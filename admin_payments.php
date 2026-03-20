<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: dashboard.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$admin = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['approve_payment'])) {
        $payment_id = $_POST['payment_id'];
        
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("SELECT p.*, u.username, u.balance as user_balance FROM payments p JOIN users u ON p.user_id = u.id WHERE p.id = ? FOR UPDATE");
            $stmt->execute([$payment_id]);
            $payment = $stmt->fetch();
            
            if ($payment && $payment['status'] == 'pending') {
                $stmt = $pdo->prepare("UPDATE payments SET status = 'completed', updated_at = NOW() WHERE id = ?");
                $stmt->execute([$payment_id]);
                
                $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
                $stmt->execute([$payment['amount'], $payment['user_id']]);
                
                $stmt = $pdo->prepare("INSERT INTO transactions (user_id, type, amount, description, created_at) VALUES (?, 'deposit', ?, ?, NOW())");
                $stmt->execute([$payment['user_id'], $payment['amount'], 'Bakiye Yükleme (Onaylandı) #' . $payment['payment_id']]);
                
                // BİLDİRİM EKLEME (ONAYLANDI)
                $notif_title = "Ödeme Onaylandı ✅";
                $notif_msg = "₺" . number_format($payment['amount'], 2) . " tutarındaki ödemeniz onaylandı ve hesabınıza eklendi.";
                $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, created_at) VALUES (?, ?, ?, 'success', NOW())");
                $stmt->execute([$payment['user_id'], $notif_title, $notif_msg]);

                $pdo->commit();
                $_SESSION['success'] = "Ödeme onaylandı ve bakiye eklendi.";
            }
        } catch (Exception $e) {
            $pdo->rollBack();
        }
    }

    if (isset($_POST['reject_payment'])) {
        $payment_id = $_POST['payment_id'];
        $reason = $_POST['reject_reason'];
        
        // Kullanıcı ID'sini al (Bildirim için)
        $stmt = $pdo->prepare("SELECT user_id, amount FROM payments WHERE id = ?");
        $stmt->execute([$payment_id]);
        $payment_info = $stmt->fetch();

        $stmt = $pdo->prepare("UPDATE payments SET status = 'failed', reject_reason = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$reason, $payment_id]);

        // BİLDİRİM EKLEME (REDDEDİLDİ)
        if($payment_info) {
            $notif_title = "Ödeme Reddedildi ❌";
            $notif_msg = "₺" . number_format($payment_info['amount'], 2) . " tutarındaki ödemeniz reddedildi. Sebep: " . $reason;
            $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, created_at) VALUES (?, ?, ?, 'danger', NOW())");
            $stmt->execute([$payment_info['user_id'], $notif_title, $notif_msg]);
        }

        $_SESSION['success'] = "Ödeme reddedildi.";
    }
    
    header("Location: admin_payments.php");
    exit;
}

$status = isset($_GET['status']) ? $_GET['status'] : 'all';
$search = isset($_GET['search']) ? $_GET['search'] : '';
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$where = "1=1";
$params = [];

if ($status !== 'all') {
    $where .= " AND p.status = ?";
    $params[] = $status;
}

if (!empty($search)) {
    $where .= " AND (u.username LIKE ? OR p.payment_id LIKE ?)";
    $search_param = "%{$search}%";
    $params[] = $search_param;
    $params[] = $search_param;
}

$count_sql = "SELECT COUNT(*) FROM payments p LEFT JOIN users u ON p.user_id = u.id WHERE $where";
$stmt = $pdo->prepare($count_sql);
$stmt->execute($params);
$total_rows = $stmt->fetchColumn();
$total_pages = ceil($total_rows / $limit);

$sql = "SELECT p.*, u.username FROM payments p 
        LEFT JOIN users u ON p.user_id = u.id 
        WHERE $where 
        ORDER BY p.created_at DESC 
        LIMIT $limit OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$payments = $stmt->fetchAll();

$stats = [
    'total_amount' => 0,
    'pending_count' => 0,
    'today_amount' => 0
];
try {
    $stats['total_amount'] = $pdo->query("SELECT SUM(amount) FROM payments WHERE status = 'completed'")->fetchColumn() ?: 0;
    $stats['pending_count'] = $pdo->query("SELECT COUNT(*) FROM payments WHERE status = 'pending'")->fetchColumn() ?: 0;
    $stats['today_amount'] = $pdo->query("SELECT SUM(amount) FROM payments WHERE status = 'completed' AND DATE(created_at) = CURDATE()")->fetchColumn() ?: 0;
} catch(Exception $e) {}

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ödeme Yönetimi - Admin Panel</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        :root {
            --primary: #8B5CF6;
            --primary-dark: #7C3AED;
            --secondary: #10B981;
            --accent: #F59E0B;
            --danger: #EF4444;
            --bg-body: #020617;
            --bg-card: rgba(30, 41, 59, 0.6);
            --text-main: #F8FAFC;
            --text-muted: #94A3B8;
            --gradient-main: linear-gradient(135deg, #8B5CF6 0%, #4F46E5 100%);
            --gradient-text: linear-gradient(135deg, #C4B5FD 0%, #6EE7B7 100%);
            --glass-border: 1px solid rgba(255, 255, 255, 0.08);
            --glow: 0 0 30px rgba(139, 92, 246, 0.3);
            --radius: 20px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg-body); color: var(--text-main); min-height: 100vh; overflow-x: hidden; }

        .background-glow { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: -1; pointer-events: none; }
        .glow-blob { position: absolute; filter: blur(90px); opacity: 0.3; border-radius: 50%; animation: float 10s infinite alternate; }
        .blob-1 { top: -10%; left: -10%; width: 600px; height: 600px; background: var(--primary); }
        .blob-2 { bottom: 10%; right: -10%; width: 500px; height: 500px; background: #059669; animation-delay: -5s; }
        @keyframes float { 0% { transform: translate(0,0); } 100% { transform: translate(30px,30px); } }

        .navbar { padding: 20px 0; position: fixed; width: 100%; top: 0; z-index: 1000; background: rgba(2, 6, 23, 0.8); backdrop-filter: blur(15px); border-bottom: var(--glass-border); }
        .container { max-width: 1400px; margin: 0 auto; padding: 0 20px; }
        .nav-inner { display: flex; justify-content: space-between; align-items: center; }
        .logo { font-family: 'Outfit'; font-size: 1.6rem; font-weight: 800; color: white; text-decoration: none; display: flex; gap: 10px; align-items: center; }
        .logo i { color: var(--primary); }
        .admin-badge { background: var(--danger); color: white; padding: 2px 8px; border-radius: 6px; font-size: 0.7rem; font-weight: bold; letter-spacing: 1px; }

        .nav-menu { display: flex; gap: 20px; align-items: center; }
        .nav-menu a { color: var(--text-muted); text-decoration: none; font-weight: 500; font-size: 0.95rem; transition: 0.3s; display: flex; align-items: center; gap: 6px; }
        .nav-menu a:hover, .nav-menu a.active { color: white; }
        .nav-menu a.active { color: var(--primary); background: rgba(139, 92, 246, 0.1); padding: 5px 12px; border-radius: 10px; }

        .main-content { padding: 100px 0 40px; }

        .page-header { margin-bottom: 30px; }
        .page-header h1 { font-family: 'Outfit'; font-size: 2rem; font-weight: 700; color: white; }
        .gradient-text { background: var(--gradient-text); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }

        .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: var(--bg-card); border: var(--glass-border); padding: 25px; border-radius: 24px; position: relative; overflow: hidden; transition: 0.3s; }
        .stat-card:hover { transform: translateY(-5px); border-color: var(--primary); box-shadow: var(--glow); }
        .stat-card::before { content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 4px; background: var(--gradient-main); }
        
        .stat-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .stat-value { font-family: 'Outfit'; font-size: 2.2rem; font-weight: 700; color: white; line-height: 1; }
        .stat-label { color: var(--text-muted); font-size: 0.9rem; margin-top: 5px; }
        .stat-icon { width: 50px; height: 50px; background: rgba(139, 92, 246, 0.1); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: var(--primary); font-size: 1.5rem; }

        .filter-section { background: var(--bg-card); padding: 25px; border-radius: 24px; border: var(--glass-border); margin-bottom: 30px; }
        .filter-form { display: grid; grid-template-columns: 2fr 1fr auto; gap: 20px; align-items: end; }
        .form-group label { display: block; margin-bottom: 8px; color: var(--text-muted); font-size: 0.9rem; font-weight: 500; }
        .modern-input { width: 100%; padding: 12px 15px; background: rgba(2, 6, 23, 0.5); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 12px; color: white; font-size: 0.95rem; transition: 0.3s; font-family: 'Plus Jakarta Sans'; }
        .modern-input:focus { outline: none; border-color: var(--primary); background: rgba(139, 92, 246, 0.05); }
        
        .btn { padding: 12px 25px; border-radius: 12px; border: none; cursor: pointer; font-weight: 600; transition: 0.3s; display: inline-flex; align-items: center; gap: 8px; font-size: 0.95rem; text-decoration: none; justify-content: center; }
        .btn-primary { background: var(--gradient-main); color: white; box-shadow: var(--glow); }
        .btn-primary:hover { transform: translateY(-2px); }

        .table-container { background: var(--bg-card); border-radius: 24px; border: var(--glass-border); overflow: hidden; backdrop-filter: blur(10px); }
        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; white-space: nowrap; }
        th { text-align: left; padding: 20px; background: rgba(0,0,0,0.3); color: var(--text-muted); font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid rgba(255,255,255,0.05); }
        td { padding: 18px 20px; border-bottom: 1px solid rgba(255,255,255,0.05); color: var(--text-main); font-size: 0.95rem; vertical-align: middle; }
        tr:hover { background: rgba(255,255,255,0.02); }

        .status-badge { padding: 5px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; display: inline-block; }
        .status-completed { background: rgba(16, 185, 129, 0.15); color: #10B981; }
        .status-pending { background: rgba(245, 158, 11, 0.15); color: #F59E0B; }
        .status-failed { background: rgba(239, 68, 68, 0.15); color: #EF4444; }

        .action-btn { width: 34px; height: 34px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; border: none; cursor: pointer; transition: 0.2s; margin-right: 5px; }
        .btn-view { background: rgba(59, 130, 246, 0.15); color: #3B82F6; }
        .btn-view:hover { background: #3B82F6; color: white; }
        .btn-approve { background: rgba(16, 185, 129, 0.15); color: #10B981; }
        .btn-approve:hover { background: #10B981; color: white; }
        .btn-reject { background: rgba(239, 68, 68, 0.15); color: #EF4444; }
        .btn-reject:hover { background: #EF4444; color: white; }

        .pagination { display: flex; justify-content: center; margin-top: 30px; gap: 8px; }
        .page-link { padding: 10px 16px; border-radius: 12px; background: var(--bg-card); border: 1px solid rgba(255,255,255,0.1); color: var(--text-muted); text-decoration: none; transition: 0.3s; font-size: 0.9rem; }
        .page-link:hover, .page-link.active { background: var(--primary); color: white; border-color: var(--primary); }

        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.85); backdrop-filter: blur(8px); z-index: 2000; align-items: center; justify-content: center; }
        .modal-content { background: #0f172a; width: auto; max-width: 90%; max-height: 90%; padding: 20px; border-radius: 24px; border: 1px solid rgba(255,255,255,0.1); position: relative; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.7); animation: zoomIn 0.3s ease; display: flex; flex-direction: column; align-items: center; }
        .close-modal { position: absolute; top: 15px; right: 15px; background: rgba(255,255,255,0.1); border: none; width: 35px; height: 35px; border-radius: 50%; color: white; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: 0.3s; z-index: 10; }
        .close-modal:hover { background: #EF4444; }
        .modal-image { max-width: 100%; max-height: 80vh; border-radius: 12px; border: 1px solid rgba(255,255,255,0.1); }
        @keyframes zoomIn { from { transform: scale(0.95); opacity: 0; } to { transform: scale(1); opacity: 1; } }

        .swal2-popup { background: #1e293b !important; border-radius: 24px !important; border: 1px solid rgba(139,92,246,0.3) !important; color: white !important; }
        .swal2-title { color: white !important; font-family: 'Outfit' !important; }
        .swal2-html-container { color: #94a3b8 !important; }
        .swal2-input { background: rgba(255,255,255,0.05) !important; border: 1px solid rgba(255,255,255,0.1) !important; color: white !important; }

        @media (max-width: 992px) {
            .nav-menu { display: none; }
            .stats-grid { grid-template-columns: 1fr; }
            .filter-form { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

    <div class="background-glow">
        <div class="glow-blob blob-1"></div>
        <div class="glow-blob blob-2"></div>
    </div>

    <nav class="navbar">
        <div class="container nav-inner">
            <a href="admin_dashboard.php" class="logo">
                <i class="fas fa-bolt"></i> Darq <span class="admin-badge">ADMIN</span>
            </a>
            
            <div class="nav-menu">
                <a href="admin_dashboard.php">Dashboard</a>
                <a href="admin_orders.php">Siparişler</a>
                <a href="admin_payments.php" class="active">Ödemeler</a>
                <a href="admin_users.php">Kullanıcılar</a>
                <a href="admin_services.php">Servisler</a>
                <a href="admin_tickets.php">Destek</a>
                <a href="dashboard.php" style="color: var(--accent);">Siteye Dön <i class="fas fa-external-link-alt"></i></a>
            </div>

            <div style="display:flex; gap:15px; align-items:center;">
                <span style="color:white; font-weight:600;"><?php echo htmlspecialchars($admin['username']); ?></span>
                <a href="logout.php" class="btn btn-primary" style="padding: 8px 15px; width: auto; font-size: 0.85rem; background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); color: #EF4444; box-shadow: none;">Çıkış</a>
            </div>
        </div>
    </nav>

    <div class="main-content container">
        
        <div class="page-header">
            <h1>Ödeme <span class="gradient-text">Yönetimi</span></h1>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-value">₺<?php echo number_format($stats['total_amount'], 2); ?></div>
                        <div class="stat-label">Toplam Ciro</div>
                    </div>
                    <div class="stat-icon"><i class="fas fa-wallet"></i></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-value" style="color: #F59E0B;"><?php echo number_format($stats['pending_count']); ?></div>
                        <div class="stat-label">Bekleyen Ödeme</div>
                    </div>
                    <div class="stat-icon"><i class="fas fa-clock"></i></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-value" style="color: #10B981;">₺<?php echo number_format($stats['today_amount'], 2); ?></div>
                        <div class="stat-label">Bugünkü Ciro</div>
                    </div>
                    <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
                </div>
            </div>
        </div>

        <div class="filter-section">
            <form method="GET" class="filter-form">
                <div class="form-group">
                    <label>Arama</label>
                    <input type="text" name="search" class="modern-input" placeholder="Kullanıcı, Ödeme ID..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="form-group">
                    <label>Durum</label>
                    <select name="status" class="modern-input">
                        <option value="all">Tümü</option>
                        <option value="pending" <?php echo $status == 'pending' ? 'selected' : ''; ?>>Beklemede</option>
                        <option value="completed" <?php echo $status == 'completed' ? 'selected' : ''; ?>>Tamamlandı</option>
                        <option value="failed" <?php echo $status == 'failed' ? 'selected' : ''; ?>>Başarısız</option>
                    </select>
                </div>
                <div class="form-group">
                    <button type="submit" class="btn btn-primary" style="height: 46px; margin-top: 24px; width: 100%;"><i class="fas fa-filter"></i> Filtrele</button>
                </div>
            </form>
        </div>

        <div class="table-container">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Ödeme ID</th>
                            <th>Kullanıcı</th>
                            <th>Tutar</th>
                            <th>Yöntem</th>
                            <th>Dekont</th>
                            <th>Durum</th>
                            <th>Tarih</th>
                            <th>İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($payments)): ?>
                        <tr><td colspan="8" style="text-align:center; padding: 40px; color: var(--text-muted);"><i class="fas fa-search" style="font-size: 2rem; margin-bottom: 10px;"></i><br>Ödeme bulunamadı.</td></tr>
                        <?php else: ?>
                            <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 600; color: var(--primary); font-family: monospace;"><?php echo $payment['payment_id']; ?></div>
                                </td>
                                <td><?php echo htmlspecialchars($payment['username']); ?></td>
                                <td style="color: #10B981; font-weight: bold;">₺<?php echo number_format($payment['amount'], 2); ?></td>
                                <td>
                                    <?php if($payment['payment_method'] == 'bank_transfer'): ?>
                                        <span style="display:flex; align-items:center; gap:5px;"><i class="fas fa-university" style="color: #F59E0B;"></i> Havale</span>
                                    <?php else: ?>
                                        <span style="display:flex; align-items:center; gap:5px;"><i class="fas fa-credit-card"></i> Kart</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if(!empty($payment['receipt_file'])): ?>
                                        <button onclick="viewReceipt('uploads/receipts/<?php echo $payment['receipt_file']; ?>')" class="action-btn btn-view" title="Görüntüle">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted);">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                    $s_map = [
                                        'pending' => ['Beklemede', 'status-pending'],
                                        'completed' => ['Tamamlandı', 'status-completed'],
                                        'failed' => ['Başarısız', 'status-failed']
                                    ];
                                    $s = $s_map[$payment['status']] ?? [$payment['status'], ''];
                                    ?>
                                    <span class="status-badge <?php echo $s[1]; ?>"><?php echo $s[0]; ?></span>
                                </td>
                                <td><?php echo date('d.m H:i', strtotime($payment['created_at'])); ?></td>
                                <td>
                                    <?php if($payment['status'] == 'pending'): ?>
                                    <button class="action-btn btn-approve" onclick="confirmApprove('<?php echo $payment['id']; ?>', '<?php echo $payment['amount']; ?>', '<?php echo $payment['username']; ?>')" title="Onayla">
                                        <i class="fas fa-check"></i>
                                    </button>
                                    <button class="action-btn btn-reject" onclick="confirmReject('<?php echo $payment['id']; ?>')" title="Reddet">
                                        <i class="fas fa-times"></i>
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <a href="?page=<?php echo $i; ?>" class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                    <?php echo $i; ?>
                </a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="modal" id="receiptModal">
        <div class="modal-content">
            <button class="close-modal" onclick="closeReceiptModal()"><i class="fas fa-times"></i></button>
            <img src="" id="receiptImage" class="modal-image" alt="Dekont">
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        <?php if(isset($_SESSION['success'])): ?>
            Swal.fire({
                icon: 'success',
                title: 'Başarılı!',
                text: '<?php echo $_SESSION['success']; unset($_SESSION['success']); ?>',
                timer: 2000,
                showConfirmButton: false,
                background: '#1e293b',
                color: '#fff'
            });
        <?php endif; ?>

        function viewReceipt(path) {
            // Check file extension
            if (path.toLowerCase().endsWith('.pdf')) {
                window.open(path, '_blank');
            } else {
                document.getElementById('receiptImage').src = path;
                document.getElementById('receiptModal').style.display = 'flex';
            }
        }

        function closeReceiptModal() {
            document.getElementById('receiptModal').style.display = 'none';
        }

        function confirmApprove(id, amount, username) {
            Swal.fire({
                title: 'Ödemeyi Onayla',
                html: `Kullanıcı: <b>${username}</b><br>Tutar: <b style="color:#10B981">₺${amount}</b><br>Bu tutar kullanıcı bakiyesine eklenecek.`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#10B981',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Evet, Onayla',
                cancelButtonText: 'İptal',
                background: '#1e293b',
                color: '#fff'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `<input type="hidden" name="approve_payment" value="1"><input type="hidden" name="payment_id" value="${id}">`;
                    document.body.appendChild(form);
                    form.submit();
                }
            })
        }

        function confirmReject(id) {
            Swal.fire({
                title: 'Ödemeyi Reddet',
                text: 'Lütfen reddetme sebebini girin:',
                input: 'text',
                inputPlaceholder: 'Örn: Dekont okunamıyor...',
                showCancelButton: true,
                confirmButtonColor: '#EF4444',
                cancelButtonColor: '#6B7280',
                confirmButtonText: 'Reddet',
                cancelButtonText: 'İptal',
                background: '#1e293b',
                color: '#fff',
                inputValidator: (value) => {
                    if (!value) {
                        return 'Bir sebep yazmalısınız!'
                    }
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `<input type="hidden" name="reject_payment" value="1"><input type="hidden" name="payment_id" value="${id}"><input type="hidden" name="reject_reason" value="${result.value}">`;
                    document.body.appendChild(form);
                    form.submit();
                }
            })
        }
    </script>
</body>
</html>
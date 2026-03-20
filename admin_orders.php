<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: dashboard.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$admin = $stmt->fetch();

$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? $_GET['search'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : 'all';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

$where = [];
$params = [];

if (!empty($search)) {
    $where[] = "(o.order_id LIKE ? OR o.api_order_id LIKE ? OR o.service_name LIKE ? OR u.username LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

if ($status != 'all') {
    $where[] = "o.status = ?";
    $params[] = $status;
}

if (!empty($date_from)) {
    $where[] = "DATE(o.created_at) >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $where[] = "DATE(o.created_at) <= ?";
    $params[] = $date_to;
}

$where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$total_orders = 0;
$orders = [];
$stats = [
    'total_orders' => 0,
    'active_orders' => 0,
    'total_revenue' => 0,
    'total_profit' => 0
];

try {
    $count_sql = "SELECT COUNT(*) FROM orders o LEFT JOIN users u ON o.user_id = u.id $where_sql";
    $stmt = $pdo->prepare($count_sql);
    $stmt->execute($params);
    $total_orders = $stmt->fetchColumn();
    $total_pages = ceil($total_orders / $limit);

    $sql = "SELECT o.*, u.username FROM orders o 
            LEFT JOIN users u ON o.user_id = u.id 
            $where_sql 
            ORDER BY o.created_at DESC 
            LIMIT $limit OFFSET $offset";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll();

    $stats_sql = "SELECT 
                    COUNT(*) as total_orders,
                    SUM(CASE WHEN status IN ('pending', 'processing', 'inprogress') THEN 1 ELSE 0 END) as active_orders,
                    SUM(price) as total_revenue,
                    SUM(profit_try) as total_profit
                  FROM orders";
    $stmt = $pdo->query($stats_sql);
    $stats = $stmt->fetch();

} catch (PDOException $e) {}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['update_status'])) {
        $order_id = $_POST['order_id'];
        $new_status = $_POST['status'];
        $admin_note = $_POST['admin_note'] ?? '';
        
        $stmt = $pdo->prepare("UPDATE orders SET status = ?, admin_note = ?, updated_at = NOW() WHERE order_id = ?");
        $stmt->execute([$new_status, $admin_note, $order_id]);
        $_SESSION['success'] = "Sipariş durumu güncellendi!";
    }
    
    if (isset($_POST['refund_order'])) {
        $order_id = $_POST['order_id'];
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE order_id = ?");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch();
        
        if ($order && $order['status'] != 'refunded') {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE orders SET status = 'refunded', updated_at = NOW() WHERE id = ?");
            $stmt->execute([$order['id']]);
            
            $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
            $stmt->execute([$order['price'], $order['user_id']]);
            
            $stmt = $pdo->prepare("INSERT INTO transactions (user_id, type, amount, description, created_at) VALUES (?, 'refund', ?, ?, NOW())");
            $stmt->execute([$order['user_id'], $order['price'], "Sipariş İadesi (#{$order['order_id']})"]);
            
            $pdo->commit();
            $_SESSION['success'] = "Sipariş iade edildi ve bakiye geri yüklendi!";
        }
    }
    
    if (isset($_POST['delete_order'])) {
        $order_id = $_POST['order_id'];
        $stmt = $pdo->prepare("DELETE FROM orders WHERE order_id = ?");
        $stmt->execute([$order_id]);
        $_SESSION['success'] = "Sipariş silindi!";
    }
    
    header("Location: admin_orders.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sipariş Yönetimi - Admin Panel</title>
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

        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 20px; }
        .page-header h1 { font-family: 'Outfit'; font-size: 2rem; font-weight: 700; color: white; }
        .gradient-text { background: var(--gradient-text); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: var(--bg-card); border: var(--glass-border); padding: 25px; border-radius: 24px; position: relative; overflow: hidden; transition: 0.3s; }
        .stat-card:hover { transform: translateY(-5px); border-color: var(--primary); box-shadow: var(--glow); }
        .stat-card::before { content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 4px; background: var(--gradient-main); }
        
        .stat-value { font-family: 'Outfit'; font-size: 2.2rem; font-weight: 700; color: white; line-height: 1; }
        .stat-label { color: var(--text-muted); font-size: 0.9rem; margin-top: 5px; }
        .stat-icon { position: absolute; right: 20px; top: 25px; width: 50px; height: 50px; background: rgba(139, 92, 246, 0.1); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: var(--primary); font-size: 1.5rem; }

        .filter-section { background: var(--bg-card); padding: 25px; border-radius: 24px; border: var(--glass-border); margin-bottom: 30px; }
        .filter-form { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; align-items: end; }
        .form-group label { display: block; margin-bottom: 8px; color: var(--text-muted); font-size: 0.9rem; font-weight: 500; }
        .modern-input { width: 100%; padding: 12px 15px; background: rgba(2, 6, 23, 0.5); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 12px; color: white; font-size: 0.95rem; transition: 0.3s; font-family: 'Plus Jakarta Sans'; }
        .modern-input:focus { outline: none; border-color: var(--primary); background: rgba(139, 92, 246, 0.05); }
        textarea.modern-input { resize: none; min-height: 100px; }

        .btn { padding: 12px 25px; border-radius: 12px; border: none; cursor: pointer; font-weight: 600; transition: 0.3s; display: inline-flex; align-items: center; gap: 8px; font-size: 0.95rem; text-decoration: none; justify-content: center; }
        .btn-primary { background: var(--gradient-main); color: white; box-shadow: var(--glow); }
        .btn-primary:hover { transform: translateY(-2px); }
        .btn-success { background: rgba(16, 185, 129, 0.15); color: var(--secondary); border: 1px solid rgba(16, 185, 129, 0.2); }
        .btn-success:hover { background: rgba(16, 185, 129, 0.25); color: white; border-color: var(--secondary); }

        .table-container { background: var(--bg-card); border-radius: 24px; border: var(--glass-border); overflow: hidden; backdrop-filter: blur(10px); }
        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; white-space: nowrap; }
        th { text-align: left; padding: 20px; background: rgba(0,0,0,0.3); color: var(--text-muted); font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid rgba(255,255,255,0.05); }
        td { padding: 18px 20px; border-bottom: 1px solid rgba(255,255,255,0.05); color: var(--text-main); font-size: 0.95rem; vertical-align: middle; }
        tr:hover { background: rgba(255,255,255,0.02); }

        .status-badge { padding: 5px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; display: inline-block; }
        .status-completed { background: rgba(16, 185, 129, 0.15); color: #10B981; }
        .status-processing { background: rgba(59, 130, 246, 0.15); color: #3B82F6; }
        .status-pending { background: rgba(245, 158, 11, 0.15); color: #F59E0B; }
        .status-cancelled { background: rgba(239, 68, 68, 0.15); color: #EF4444; }
        .status-refunded { background: rgba(168, 85, 247, 0.15); color: #A855F7; }

        .action-btn { width: 34px; height: 34px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; border: none; cursor: pointer; transition: 0.2s; margin-right: 5px; }
        .btn-edit { background: rgba(59, 130, 246, 0.15); color: #3B82F6; }
        .btn-edit:hover { background: #3B82F6; color: white; }
        .btn-refund { background: rgba(168, 85, 247, 0.15); color: #A855F7; }
        .btn-refund:hover { background: #A855F7; color: white; }
        .btn-delete { background: rgba(239, 68, 68, 0.15); color: #EF4444; }
        .btn-delete:hover { background: #EF4444; color: white; }

        .pagination { display: flex; justify-content: center; margin-top: 30px; gap: 8px; }
        .page-link { padding: 10px 16px; border-radius: 12px; background: var(--bg-card); border: 1px solid rgba(255,255,255,0.1); color: var(--text-muted); text-decoration: none; transition: 0.3s; font-size: 0.9rem; }
        .page-link:hover, .page-link.active { background: var(--primary); color: white; border-color: var(--primary); }

        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.85); backdrop-filter: blur(8px); z-index: 2000; align-items: center; justify-content: center; }
        .modal-content { background: #0f172a; width: 95%; max-width: 500px; padding: 30px; border-radius: 24px; border: 1px solid rgba(255,255,255,0.1); position: relative; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.7); animation: zoomIn 0.3s ease; }
        .close-modal { position: absolute; top: 20px; right: 20px; background: rgba(255,255,255,0.05); border: none; width: 32px; height: 32px; border-radius: 50%; color: var(--text-muted); cursor: pointer; display: flex; align-items: center; justify-content: center; transition: 0.3s; }
        .close-modal:hover { background: #EF4444; color: white; }
        @keyframes zoomIn { from { transform: scale(0.95); opacity: 0; } to { transform: scale(1); opacity: 1; } }

        .swal2-popup { background: #1e293b !important; border: 1px solid rgba(255,255,255,0.1) !important; border-radius: 20px !important; color: white !important; }
        .swal2-title { color: white !important; font-family: 'Outfit' !important; }
        .swal2-html-container { color: #94a3b8 !important; }

        @media (max-width: 992px) {
            .nav-menu { display: none; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
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
                <a href="admin_orders.php" class="active">Siparişler</a>
                <a href="admin_payments.php">Ödemeler</a>
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
            <h1>Sipariş <span class="gradient-text">Yönetimi</span></h1>
            <button onclick="exportOrders()" class="btn btn-success"><i class="fas fa-file-excel"></i> Excel'e Aktar</button>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($stats['total_orders']); ?></div>
                <div class="stat-label">Toplam Sipariş</div>
                <div class="stat-icon"><i class="fas fa-shopping-bag"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($stats['active_orders']); ?></div>
                <div class="stat-label">Aktif İşlemler</div>
                <div class="stat-icon"><i class="fas fa-sync-alt"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-value">₺<?php echo number_format($stats['total_revenue'], 2); ?></div>
                <div class="stat-label">Toplam Ciro</div>
                <div class="stat-icon"><i class="fas fa-wallet"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: var(--secondary);">₺<?php echo number_format($stats['total_profit'], 2); ?></div>
                <div class="stat-label">Toplam Kar</div>
                <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
            </div>
        </div>

        <div class="filter-section">
            <form method="GET" class="filter-form">
                <div class="form-group">
                    <label>Arama</label>
                    <input type="text" name="search" class="modern-input" placeholder="ID, Hizmet, Kullanıcı..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="form-group">
                    <label>Durum</label>
                    <select name="status" class="modern-input">
                        <option value="all">Tümü</option>
                        <option value="pending" <?php echo $status == 'pending' ? 'selected' : ''; ?>>Beklemede</option>
                        <option value="processing" <?php echo $status == 'processing' ? 'selected' : ''; ?>>İşleniyor</option>
                        <option value="completed" <?php echo $status == 'completed' ? 'selected' : ''; ?>>Tamamlandı</option>
                        <option value="cancelled" <?php echo $status == 'cancelled' ? 'selected' : ''; ?>>İptal</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Başlangıç</label>
                    <input type="date" name="date_from" class="modern-input" value="<?php echo htmlspecialchars($date_from); ?>">
                </div>
                <div class="form-group">
                    <label>Bitiş</label>
                    <input type="date" name="date_to" class="modern-input" value="<?php echo htmlspecialchars($date_to); ?>">
                </div>
                <div class="form-group">
                    <button type="submit" class="btn btn-primary" style="height: 46px; margin-top: 24px;"><i class="fas fa-filter"></i> Filtrele</button>
                </div>
            </form>
        </div>

        <div class="table-container">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Kullanıcı</th>
                            <th>Hizmet</th>
                            <th>Tutar</th>
                            <th>Kar</th>
                            <th>Durum</th>
                            <th>Tarih</th>
                            <th>İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($orders)): ?>
                        <tr><td colspan="8" style="text-align:center; padding: 40px; color: var(--text-muted);"><i class="fas fa-box-open" style="font-size:2rem; display:block; margin-bottom:10px;"></i>Sipariş bulunamadı.</td></tr>
                        <?php else: ?>
                            <?php foreach ($orders as $order): ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 600; color: var(--primary); font-family: monospace;">#<?php echo $order['order_id']; ?></div>
                                    <small style="color: var(--text-muted); font-size: 0.75rem;">API: <?php echo $order['api_order_id'] ?? '-'; ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($order['username']); ?></td>
                                <td title="<?php echo htmlspecialchars($order['service_name']); ?>">
                                    <?php echo htmlspecialchars(mb_strimwidth($order['service_name'], 0, 30, '...')); ?>
                                    <div style="font-size: 0.75rem; color: var(--text-muted);"><?php echo $order['link']; ?></div>
                                </td>
                                <td>₺<?php echo number_format($order['price'], 2); ?></td>
                                <td style="color: var(--secondary);">₺<?php echo number_format($order['profit_try'], 2); ?></td>
                                <td>
                                    <?php 
                                    $s_map = [
                                        'pending' => ['Beklemede', 'status-pending'],
                                        'processing' => ['İşleniyor', 'status-processing'],
                                        'inprogress' => ['İşleniyor', 'status-processing'],
                                        'completed' => ['Tamamlandı', 'status-completed'],
                                        'cancelled' => ['İptal', 'status-cancelled'],
                                        'refunded' => ['İade', 'status-refunded']
                                    ];
                                    $s = $s_map[$order['status']] ?? [$order['status'], ''];
                                    ?>
                                    <span class="status-badge <?php echo $s[1]; ?>"><?php echo $s[0]; ?></span>
                                </td>
                                <td><?php echo date('d.m H:i', strtotime($order['created_at'])); ?></td>
                                <td>
                                    <button class="action-btn btn-edit" onclick="openEditModal('<?php echo $order['order_id']; ?>', '<?php echo $order['status']; ?>', '<?php echo addslashes($order['admin_note']); ?>')" title="Düzenle">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    
                                    <?php if($order['status'] !== 'refunded'): ?>
                                    <button class="action-btn btn-refund" onclick="confirmRefund('<?php echo $order['order_id']; ?>', <?php echo $order['price']; ?>)" title="İade Et">
                                        <i class="fas fa-undo"></i>
                                    </button>
                                    <?php endif; ?>
                                    
                                    <button class="action-btn btn-delete" onclick="confirmDelete('<?php echo $order['order_id']; ?>')" title="Sil">
                                        <i class="fas fa-trash"></i>
                                    </button>
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

    <div class="modal" id="editModal">
        <div class="modal-content">
            <button class="close-modal" onclick="closeEditModal()"><i class="fas fa-times"></i></button>
            <h2 style="font-family: 'Outfit'; color: white; margin-bottom: 20px;">Sipariş Düzenle</h2>
            <form method="POST">
                <input type="hidden" name="update_status" value="1">
                <input type="hidden" name="order_id" id="edit_order_id">
                
                <div class="form-group">
                    <label>Sipariş Durumu</label>
                    <select name="status" id="edit_status" class="modern-input">
                        <option value="pending">Beklemede</option>
                        <option value="processing">İşleniyor</option>
                        <option value="inprogress">Devam Ediyor</option>
                        <option value="completed">Tamamlandı</option>
                        <option value="cancelled">İptal Edildi</option>
                        <option value="refunded">İade Edildi</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Yönetici Notu</label>
                    <textarea name="admin_note" id="edit_note" class="modern-input" rows="3"></textarea>
                </div>
                
                <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 10px;">Kaydet</button>
            </form>
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

        function openEditModal(id, status, note) {
            document.getElementById('edit_order_id').value = id;
            document.getElementById('edit_status').value = status;
            document.getElementById('edit_note').value = note;
            document.getElementById('editModal').style.display = 'flex';
        }

        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        function confirmDelete(orderId) {
            Swal.fire({
                title: 'Silmek istediğine emin misin?',
                text: "Bu işlem geri alınamaz!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#EF4444',
                cancelButtonColor: '#3B82F6',
                confirmButtonText: 'Evet, Sil!',
                cancelButtonText: 'İptal',
                background: '#1e293b',
                color: '#fff'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `<input type="hidden" name="delete_order" value="1"><input type="hidden" name="order_id" value="${orderId}">`;
                    document.body.appendChild(form);
                    form.submit();
                }
            })
        }

        function confirmRefund(orderId, amount) {
            Swal.fire({
                title: 'İade Onayı',
                html: `Sipariş tutarı olan <b>₺${amount.toFixed(2)}</b> kullanıcı bakiyesine eklenecektir.`,
                icon: 'info',
                showCancelButton: true,
                confirmButtonColor: '#A855F7',
                cancelButtonColor: '#3B82F6',
                confirmButtonText: 'Onayla ve İade Et',
                cancelButtonText: 'Vazgeç',
                background: '#1e293b',
                color: '#fff'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `<input type="hidden" name="refund_order" value="1"><input type="hidden" name="order_id" value="${orderId}">`;
                    document.body.appendChild(form);
                    form.submit();
                }
            })
        }

        function exportOrders() {
            alert('Excel export özelliği yakında eklenecek.');
        }
    </script>
</body>
</html>
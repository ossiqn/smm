<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    header('Location: login.php');
    exit;
}

// Bildirimleri Okundu İşaretle (AJAX)
if (isset($_POST['action']) && $_POST['action'] == 'read_notifications') {
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $stmt->execute([$user['id']]);
    echo 'ok';
    exit;
}

// Bildirimler Tablosu Kontrolü
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, title VARCHAR(255) NOT NULL, message TEXT NOT NULL, type ENUM('info', 'success', 'warning', 'danger') DEFAULT 'info', is_read BOOLEAN DEFAULT FALSE, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_user (user_id))");
} catch (Exception $e) {}

// Bildirimleri Çek
$notifications = [];
$unread_notif_count = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$user['id']]);
    $unread_notif_count = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
    $stmt->execute([$user['id']]);
    $notifications = $stmt->fetchAll();
} catch (PDOException $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_balance'])) {
        $amount = floatval($_POST['amount']);
        $payment_method = $_POST['payment_method'];
        
        if ($amount < 75) {
            $error = "Minimum yükleme tutarı 75 TL'dir.";
        } elseif ($amount > 10000) {
            $error = "Maksimum yükleme tutarı 10.000 TL'dir.";
        } else {
            if ($payment_method === 'bank_transfer') {
                if (empty($_FILES['receipt']['name'])) {
                    $error = "Banka havalesi için dekont yüklemek zorunludur.";
                } else {
                    $allowed_extensions = ['jpg', 'jpeg', 'png', 'pdf'];
                    $file_extension = strtolower(pathinfo($_FILES['receipt']['name'], PATHINFO_EXTENSION));
                    
                    if (!in_array($file_extension, $allowed_extensions)) {
                        $error = "Sadece JPG, JPEG, PNG ve PDF dosyaları yükleyebilirsiniz.";
                    } elseif ($_FILES['receipt']['size'] > 5 * 1024 * 1024) {
                        $error = "Dosya boyutu 5MB'dan küçük olmalıdır.";
                    } else {
                        $upload_dir = 'uploads/receipts/';
                        if (!file_exists($upload_dir)) {
                            mkdir($upload_dir, 0777, true);
                        }
                        
                        $file_name = 'receipt_' . time() . '_' . uniqid() . '.' . $file_extension;
                        $file_path = $upload_dir . $file_name;
                        
                        if (move_uploaded_file($_FILES['receipt']['tmp_name'], $file_path)) {
                            $payment_id = 'PAY' . strtoupper(uniqid());
                            $insert_stmt = $pdo->prepare("INSERT INTO payments (user_id, payment_id, amount, payment_method, receipt_file, status, created_at) VALUES (?, ?, ?, ?, ?, 'pending', NOW())");
                            $insert_stmt->execute([$_SESSION['user_id'], $payment_id, $amount, $payment_method, $file_name]);
                            
                            // Bildirim Ekle: Talep Alındı
                            $notif_title = "Ödeme Talebi Alındı";
                            $notif_msg = "₺" . number_format($amount, 2) . " tutarındaki ödeme bildiriminiz alındı. Yönetici onayı bekleniyor.";
                            $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, created_at) VALUES (?, ?, ?, 'info', NOW())");
                            $stmt->execute([$user['id'], $notif_title, $notif_msg]);

                            // Bildirim listesini güncelle (Sayfa yenilenmeden görünmesi için)
                            $unread_notif_count++;
                            array_unshift($notifications, [
                                'title' => $notif_title,
                                'message' => $notif_msg,
                                'created_at' => date('Y-m-d H:i:s'),
                                'type' => 'info',
                                'is_read' => 0
                            ]);
                            
                            $success = "Ödeme talebiniz ve dekontunuz alındı. Ödeme onaylandıktan sonra bakiyeniz güncellenecek.";
                        } else {
                            $error = "Dekont yüklenirken bir hata oluştu.";
                        }
                    }
                }
            }
        }
    }
}

$payments = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM payments WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
    $stmt->execute([$_SESSION['user_id']]);
    $payments = $stmt->fetchAll();
} catch (PDOException $e) {
    $create_payments_table = "CREATE TABLE IF NOT EXISTS payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        payment_id VARCHAR(50) UNIQUE NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        payment_method VARCHAR(50) NOT NULL,
        receipt_file VARCHAR(255) DEFAULT NULL,
        status ENUM('pending', 'completed', 'failed', 'refunded') DEFAULT 'pending',
        reject_reason VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL,
        INDEX idx_user_id (user_id),
        INDEX idx_payment_id (payment_id)
    )";
    $pdo->exec($create_payments_table);
}

$monthly_spent = 0;
try {
    $stmt = $pdo->prepare("SELECT SUM(amount) as total FROM payments WHERE user_id = ? AND status = 'completed' AND MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE())");
    $stmt->execute([$_SESSION['user_id']]);
    $result = $stmt->fetch();
    $monthly_spent = $result['total'] ?? 0;
} catch (PDOException $e) {}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bakiye - Darq SMM Panel</title>
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
            --transition: all 0.3s ease;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; color: var(--text-main); background: var(--bg-body); line-height: 1.6; overflow-x: hidden; min-height: 100vh; }

        .background-glow { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: -1; overflow: hidden; pointer-events: none; }
        .glow-blob { position: absolute; filter: blur(90px); opacity: 0.3; border-radius: 50%; animation: float 15s infinite alternate ease-in-out; }
        .blob-1 { top: -10%; left: -10%; width: 600px; height: 600px; background: var(--primary); }
        .blob-2 { bottom: 10%; right: -10%; width: 500px; height: 500px; background: #059669; animation-delay: -5s; }
        @keyframes float { 0% { transform: translate(0, 0) scale(1); } 100% { transform: translate(40px, 40px) scale(1.05); } }

        .navbar { padding: 20px 0; position: fixed; width: 100%; top: 0; z-index: 1000; background: rgba(2, 6, 23, 0.7); backdrop-filter: blur(15px); border-bottom: var(--glass-border); transition: 0.3s; }
        .navbar.scrolled { padding: 15px 0; background: rgba(2, 6, 23, 0.95); }
        .container { max-width: 1400px; margin: 0 auto; padding: 0 20px; }
        .nav-inner { display: flex; justify-content: space-between; align-items: center; }
        
        .logo { display: flex; align-items: center; gap: 12px; font-family: 'Outfit', sans-serif; font-size: 1.6rem; font-weight: 800; text-decoration: none; color: white; letter-spacing: -0.5px; }
        .logo i { color: var(--primary); font-size: 1.8rem; filter: drop-shadow(0 0 10px rgba(139, 92, 246, 0.5)); }

        .nav-menu { display: flex; gap: 20px; align-items: center; }
        .nav-menu a { text-decoration: none; color: var(--text-muted); font-weight: 500; transition: 0.3s; font-size: 0.95rem; display: flex; align-items: center; gap: 8px; padding: 8px 12px; border-radius: 12px; }
        .nav-menu a:hover, .nav-menu a.active { color: white; background: rgba(255,255,255,0.05); }
        .nav-menu a.active { background: rgba(139, 92, 246, 0.1); color: var(--primary); }

        .user-menu { display: flex; align-items: center; gap: 15px; position: relative; }
        .balance-badge { background: rgba(16, 185, 129, 0.1); color: #10B981; padding: 6px 12px; border-radius: 20px; font-weight: 600; display: flex; align-items: center; gap: 5px; font-size: 0.9rem; border: 1px solid rgba(16, 185, 129, 0.2); }
        
        .menu-toggle { display: none; font-size: 1.5rem; color: white; cursor: pointer; background: none; border: none; }

        /* Notification Bell Styles */
        .notif-wrapper { position: relative; margin-right: 10px; cursor: pointer; }
        .notif-bell { font-size: 1.2rem; color: var(--text-muted); transition: 0.3s; }
        .notif-bell:hover { color: white; }
        .notif-badge { position: absolute; top: -5px; right: -5px; background: var(--danger); color: white; font-size: 0.6rem; padding: 2px 5px; border-radius: 50%; border: 1px solid var(--bg-body); }
        
        .notif-dropdown {
            position: absolute;
            top: 50px;
            right: 0;
            width: 320px;
            background: #1e293b;
            border: var(--glass-border);
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.5);
            display: none;
            flex-direction: column;
            z-index: 1001;
            overflow: hidden;
            animation: slideDown 0.3s ease;
        }
        @keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        .notif-dropdown.active { display: flex; }
        .notif-header { padding: 15px; border-bottom: 1px solid rgba(255,255,255,0.05); font-weight: 600; color: white; display: flex; justify-content: space-between; align-items: center; }
        .notif-list { max-height: 300px; overflow-y: auto; }
        .notif-item { padding: 15px; border-bottom: 1px solid rgba(255,255,255,0.05); transition: 0.2s; display: block; text-decoration: none; }
        .notif-item:hover { background: rgba(255,255,255,0.02); }
        .notif-item.unread { background: rgba(139, 92, 246, 0.05); border-left: 3px solid var(--primary); }
        .notif-title { font-size: 0.9rem; font-weight: 600; color: white; margin-bottom: 3px; }
        .notif-msg { font-size: 0.8rem; color: var(--text-muted); }
        .notif-time { font-size: 0.7rem; color: var(--text-muted); margin-top: 5px; text-align: right; opacity: 0.7; }
        .notif-empty { padding: 20px; text-align: center; color: var(--text-muted); font-size: 0.9rem; }

        .main-content { padding: 100px 0 40px; }
        .page-header { margin-bottom: 40px; }
        .page-header h1 { font-family: 'Outfit', sans-serif; font-size: 2.5rem; margin-bottom: 10px; font-weight: 700; }
        .gradient-text { background: var(--gradient-text); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        
        .btn { padding: 10px 20px; border-radius: 12px; font-weight: 600; text-decoration: none; transition: 0.3s; display: inline-flex; align-items: center; gap: 8px; border: none; cursor: pointer; font-size: 0.95rem; }
        .btn-primary { background: var(--gradient-main); color: white; box-shadow: var(--glow); }
        .btn-primary:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(139, 92, 246, 0.5); }
        .btn-outline { background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.15); color: white; }
        .btn-outline:hover { background: rgba(255,255,255,0.1); border-color: white; transform: translateY(-3px); }
        .btn-secondary { background: rgba(255,255,255,0.05); color: var(--text-main); border: 1px solid rgba(255,255,255,0.1); }
        .btn-secondary:hover { background: rgba(255,255,255,0.1); }

        .balance-overview { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 25px; margin-bottom: 40px; }
        .balance-card { background: var(--bg-card); backdrop-filter: blur(15px); border: var(--glass-border); border-radius: 24px; padding: 35px; position: relative; overflow: hidden; transition: 0.3s; }
        .balance-card:hover { transform: translateY(-5px); border-color: var(--primary); box-shadow: var(--glow); }
        .balance-card.gradient { background: linear-gradient(135deg, rgba(139, 92, 246, 0.15), rgba(16, 185, 129, 0.1)); border: 1px solid rgba(139, 92, 246, 0.2); }
        .balance-card::before { content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 4px; background: var(--gradient-main); }
        .balance-label { color: var(--text-muted); font-size: 1rem; margin-bottom: 15px; }
        .balance-amount { font-family: 'Outfit'; font-size: 3rem; font-weight: 800; color: white; line-height: 1; margin-bottom: 20px; background: var(--gradient-text); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .balance-actions { display: flex; gap: 15px; }

        .payment-container { display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-bottom: 40px; }
        .payment-box { background: var(--bg-card); border-radius: 24px; padding: 35px; border: var(--glass-border); backdrop-filter: blur(10px); }
        .payment-methods { display: flex; gap: 15px; margin-bottom: 30px; overflow-x: auto; padding-bottom: 5px; }
        .method-item { padding: 15px 25px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.05); border-radius: 16px; cursor: pointer; transition: 0.3s; display: flex; flex-direction: column; align-items: center; gap: 10px; min-width: 120px; }
        .method-item i { font-size: 1.5rem; color: var(--text-muted); }
        .method-item.active { background: rgba(139, 92, 246, 0.1); border-color: var(--primary); }
        .method-item.active i { color: var(--primary); }

        .quick-amounts { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 25px; }
        .amount-tag { padding: 12px; background: rgba(255,255,255,0.03); border-radius: 12px; text-align: center; cursor: pointer; transition: 0.3s; border: 1px solid transparent; font-weight: 500; color: var(--text-muted); }
        .amount-tag:hover, .amount-tag.active { border-color: var(--primary); color: white; background: rgba(139, 92, 246, 0.1); }

        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 10px; font-weight: 500; color: var(--text-main); }
        .form-control { width: 100%; padding: 15px; background: rgba(255, 255, 255, 0.03); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 12px; color: white; font-size: 1rem; font-family: 'Plus Jakarta Sans'; }
        .form-control:focus { outline: none; border-color: var(--primary); background: rgba(255, 255, 255, 0.05); }

        .bank-details { background: rgba(255,255,255,0.03); padding: 20px; border-radius: 16px; margin-bottom: 25px; border: 1px dashed rgba(255,255,255,0.1); }
        .bank-row { display: flex; justify-content: space-between; margin-bottom: 10px; font-size: 0.95rem; }
        .bank-row span:first-child { color: var(--text-muted); }
        .bank-row span:last-child { color: white; font-weight: 600; text-align: right; }
        .copy-iban { color: var(--primary); cursor: pointer; font-size: 0.85rem; display: inline-flex; align-items: center; gap: 5px; margin-top: 5px; }

        .upload-area { border: 2px dashed rgba(255,255,255,0.1); border-radius: 16px; padding: 30px; text-align: center; cursor: pointer; transition: 0.3s; background: rgba(255,255,255,0.01); position: relative; display: block; }
        .upload-area:hover { border-color: var(--primary); background: rgba(139, 92, 246, 0.05); }
        .upload-icon { font-size: 2rem; color: var(--text-muted); margin-bottom: 10px; }
        .file-preview { margin-top: 15px; max-height: 100px; display: none; border-radius: 8px; margin-left: auto; margin-right: auto; }

        .history-table { width: 100%; border-collapse: collapse; }
        .history-table th { text-align: left; padding: 15px; color: var(--text-muted); font-weight: 500; border-bottom: 1px solid rgba(255,255,255,0.05); font-size: 0.9rem; }
        .history-table td { padding: 15px; border-bottom: 1px solid rgba(255,255,255,0.05); color: var(--text-main); font-size: 0.95rem; vertical-align: middle; }
        .history-table tr:hover { background: rgba(255,255,255,0.02); }

        .status-badge { padding: 5px 12px; border-radius: 20px; font-size: 0.8rem; font-weight: 600; display: inline-block; }
        .st-completed { background: rgba(16, 185, 129, 0.15); color: #10B981; }
        .st-pending { background: rgba(245, 158, 11, 0.15); color: #F59E0B; }
        .st-failed { background: rgba(239, 68, 68, 0.15); color: #EF4444; }

        .alert { padding: 15px; border-radius: 12px; margin-bottom: 25px; display: flex; align-items: center; gap: 10px; font-size: 0.95rem; }
        .alert-success { background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.3); color: #10B981; }
        .alert-error { background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.3); color: #FCA5A5; }

        .footer { padding: 40px 0; border-top: var(--glass-border); margin-top: 50px; text-align: center; color: var(--text-muted); font-size: 0.9rem; }

        .swal2-popup { background: #1e293b !important; border-radius: 24px !important; border: 1px solid rgba(139,92,246,0.3) !important; color: white !important; font-family: 'Plus Jakarta Sans', sans-serif !important; }
        .swal2-title { color: white !important; font-family: 'Outfit', sans-serif !important; }
        .swal2-html-container { color: #94a3b8 !important; }
        .swal2-confirm { background: var(--gradient-main) !important; box-shadow: var(--glow) !important; border-radius: 12px !important; }

        @media (max-width: 992px) {
            .nav-menu { display: none; position: fixed; top: 70px; left: 0; width: 100%; background: rgba(2,6,23,0.98); flex-direction: column; padding: 20px; height: calc(100vh - 70px); align-items: flex-start; }
            .nav-menu a { width: 100%; padding: 15px; }
            .nav-menu.active { display: flex; }
            .menu-toggle { display: block; }
            .payment-container { grid-template-columns: 1fr; }
            .user-menu { display: none; } /* Hide user menu in mobile nav bar, assume it's in mobile menu or handled differently */
        }
    </style>
</head>
<body>

    <div class="background-glow">
        <div class="glow-blob blob-1"></div>
        <div class="glow-blob blob-2"></div>
    </div>

    <nav class="navbar" id="navbar">
        <div class="container nav-inner">
            <a href="dashboard.php" class="logo">
                <i class="fas fa-bolt"></i> Darq
            </a>
            
            <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>

            <div class="nav-menu" id="navMenu">
                <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                <a href="services.php"><i class="fas fa-box"></i> Hizmetler</a>
                <a href="orders.php"><i class="fas fa-history"></i> Siparişler</a>
                <a href="balance.php" class="active"><i class="fas fa-wallet"></i> Bakiye</a>
                <a href="api.php"><i class="fas fa-code"></i> API</a>
                <a href="support.php"><i class="fas fa-headset"></i> Destek</a>
                <?php if ($is_admin): ?>
                <a href="admin_dashboard.php"><i class="fas fa-user-shield"></i> Admin Panel</a>
                <?php endif; ?>
            </div>

            <div class="user-menu" id="userMenu">
                <div class="notif-wrapper" onclick="toggleNotifications()">
                    <i class="fas fa-bell notif-bell" style="font-size: 1.2rem; color: #94A3B8; cursor: pointer;"></i>
                    <?php if($unread_notif_count > 0): ?>
                        <span class="notif-badge" id="notifBadge"><?php echo $unread_notif_count; ?></span>
                    <?php endif; ?>
                    
                    <div class="notif-dropdown" id="notifDropdown">
                        <div class="notif-header">
                            <span>Bildirimler</span>
                            <small style="cursor:pointer; color:var(--primary);" onclick="markAllRead(event)">Tümü Okundu</small>
                        </div>
                        <div class="notif-list">
                            <?php if(empty($notifications)): ?>
                                <div class="notif-empty">Henüz bildirim yok.</div>
                            <?php else: ?>
                                <?php foreach($notifications as $notif): ?>
                                    <div class="notif-item <?php echo $notif['is_read'] ? '' : 'unread'; ?>">
                                        <div class="notif-title"><?php echo htmlspecialchars($notif['title']); ?></div>
                                        <div class="notif-msg"><?php echo htmlspecialchars($notif['message']); ?></div>
                                        <div class="notif-time"><?php echo date('d.m H:i', strtotime($notif['created_at'])); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="balance-badge">
                    <i class="fas fa-coins"></i> ₺<?php echo number_format($user['balance'], 2); ?>
                </div>
                
                <a href="?logout=1" class="btn btn-secondary" style="padding: 6px 15px; font-size: 0.8rem;">Çıkış</a>
            </div>
        </div>
    </nav>

    <div class="main-content container">
        <div class="page-header">
            <h1>Bakiye <span class="gradient-text">Yönetimi</span></h1>
            <p style="color: var(--text-muted);">Hesap bakiyenizi güvenli ve hızlı bir şekilde yönetin.</p>
        </div>

        <?php if (isset($success)): ?>
            <script>document.addEventListener('DOMContentLoaded', () => Swal.fire({icon: 'success', title: 'Başarılı!', text: '<?php echo $success; ?>'}));</script>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <script>document.addEventListener('DOMContentLoaded', () => Swal.fire({icon: 'error', title: 'Hata!', text: '<?php echo $error; ?>'}));</script>
        <?php endif; ?>

        <div class="balance-overview">
            <div class="balance-card gradient">
                <div class="balance-label">Toplam Bakiye</div>
                <div class="balance-amount">₺<?php echo number_format($user['balance'], 2); ?></div>
                <div class="balance-actions">
                    <a href="#payment-form" class="btn btn-primary" onclick="document.getElementById('payment-form').scrollIntoView({behavior: 'smooth'})"><i class="fas fa-plus-circle"></i> Bakiye Yükle</a>
                    <a href="services.php" class="btn btn-secondary"><i class="fas fa-shopping-cart"></i> Harca</a>
                </div>
            </div>
            <div class="balance-card">
                <div class="balance-label">Bu Ay Harcanan</div>
                <div class="balance-amount" style="font-size: 2.5rem;">₺<?php echo number_format($monthly_spent, 2); ?></div>
                <div style="color: var(--text-muted); font-size: 0.9rem; margin-top: 10px;">
                    <i class="fas fa-chart-pie"></i> Harcamalarınız kontrol altında.
                </div>
            </div>
        </div>

        <div class="payment-container" id="payment-form">
            <div class="payment-box">
                <h3 style="font-size: 1.5rem; color: white; margin-bottom: 25px; font-family: 'Outfit';">Ödeme Yöntemi</h3>
                
                <form method="POST" enctype="multipart/form-data">
                    <div class="payment-methods">
                        <div class="method-item active" onclick="selectMethod(this, 'bank_transfer')">
                            <i class="fas fa-university"></i>
                            <span>Havale/EFT</span>
                        </div>
                        <div class="method-item" style="opacity: 0.5; cursor: not-allowed;">
                            <i class="fas fa-credit-card"></i>
                            <span>Kredi Kartı</span>
                        </div>
                        <div class="method-item" style="opacity: 0.5; cursor: not-allowed;">
                            <i class="fab fa-bitcoin"></i>
                            <span>Kripto</span>
                        </div>
                    </div>
                    <input type="hidden" name="payment_method" value="bank_transfer">

                    <div class="form-group">
                        <label>Yüklenecek Tutar</label>
                        <div class="quick-amounts">
                            <div class="amount-tag" onclick="setAmount(100)">100 TL</div>
                            <div class="amount-tag" onclick="setAmount(250)">250 TL</div>
                            <div class="amount-tag" onclick="setAmount(500)">500 TL</div>
                            <div class="amount-tag" onclick="setAmount(1000)">1000 TL</div>
                        </div>
                        <input type="number" name="amount" id="amountInput" class="form-control" placeholder="Tutar girin (Min: 75 TL)" min="75" required>
                    </div>

                    <div class="bank-details">
                        <div class="bank-row"><span>Banka:</span> <span>VakıfBank</span></div>
                        <div class="bank-row"><span>Alıcı:</span> <span>Furkan Demirkıran</span></div>
                        <div class="bank-row">
                            <span>IBAN:</span> 
                            <span>
                                TR17 0001 5001 5800 7350 4899 83
                                <div class="copy-iban" onclick="copyIBAN('TR17 0001 5001 5800 7350 4899 83')"><i class="fas fa-copy"></i> Kopyala</div>
                            </span>
                        </div>
                        <div class="bank-row" style="margin-top: 15px; padding-top: 10px; border-top: 1px dashed rgba(255,255,255,0.1);">
                            <span>Açıklama:</span> <span style="color: var(--accent);"><?php echo $user['username']; ?></span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Ödeme Dekontu</label>
                        <input type="file" name="receipt" id="receiptFile" hidden accept="image/*,.pdf" onchange="previewFile()">
                        <label for="receiptFile" class="upload-area">
                            <i class="fas fa-cloud-upload-alt upload-icon"></i>
                            <p style="color: var(--text-main); font-weight: 500;">Dekont Yüklemek İçin Tıklayın</p>
                            <p style="font-size: 0.8rem; color: var(--text-muted);">JPG, PNG veya PDF (Max 5MB)</p>
                            <img id="previewImg" class="file-preview">
                            <div id="fileName" style="font-size: 0.85rem; color: var(--primary); margin-top: 10px;"></div>
                        </label>
                    </div>

                    <button type="submit" name="add_balance" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 15px;">
                        <i class="fas fa-paper-plane"></i> Ödeme Bildirimi Gönder
                    </button>
                </form>
            </div>

            <div class="payment-box" style="height: fit-content;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
                    <h3 style="font-size: 1.5rem; color: white; font-family: 'Outfit';">Ödeme Geçmişi</h3>
                    <div style="font-size: 0.9rem; color: var(--text-muted);">Son 10 İşlem</div>
                </div>
                
                <div style="overflow-x: auto;">
                    <table class="history-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Tarih</th>
                                <th>Tutar</th>
                                <th>Durum</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($payments)): ?>
                                <?php foreach ($payments as $payment): ?>
                                    <tr>
                                        <td><span style="font-family: monospace; color: var(--primary);">#<?php echo substr($payment['payment_id'], -8); ?></span></td>
                                        <td><?php echo date('d.m.Y H:i', strtotime($payment['created_at'])); ?></td>
                                        <td style="font-weight: 600; color: white;">₺<?php echo number_format($payment['amount'], 2); ?></td>
                                        <td>
                                            <?php 
                                            $st = $payment['status'];
                                            $cls = 'st-pending'; $txt = 'Bekliyor';
                                            if($st == 'completed') { $cls = 'st-completed'; $txt = 'Onaylandı'; }
                                            if($st == 'failed') { $cls = 'st-failed'; $txt = 'Reddedildi'; }
                                            ?>
                                            <span class="status-badge <?php echo $cls; ?>"><?php echo $txt; ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" style="text-align: center; padding: 30px; color: var(--text-muted);">Henüz ödeme geçmişi yok.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <footer class="footer">
        <p>&copy; 2026 Darq SMM Panel. Tüm hakları saklıdır.</p>
        <p style="margin-top: 5px;">Güvenli Ödeme Altyapısı</p>
    </footer>

    <script>
        const menuToggle = document.getElementById('menuToggle');
        const navMenu = document.getElementById('navMenu');
        const userMenu = document.getElementById('userMenu'); // Added for mobile
        
        menuToggle.addEventListener('click', (e) => {
            e.stopPropagation();
            navMenu.classList.toggle('active');
            // userMenu.classList.toggle('active'); // Optional for mobile
        });

        window.addEventListener('scroll', () => {
            const nav = document.getElementById('navbar');
            if(window.scrollY > 50) nav.classList.add('scrolled');
            else nav.classList.remove('scrolled');
        });

        // Notification System
        function toggleNotifications() {
            const dd = document.getElementById('notifDropdown');
            dd.classList.toggle('active');
            
            if(dd.classList.contains('active')) {
                const badge = document.getElementById('notifBadge');
                if(badge) {
                    fetch('balance.php', { // Use current page for handler
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: 'action=read_notifications'
                    }).then(() => {
                        badge.style.display = 'none';
                        document.querySelectorAll('.notif-item.unread').forEach(el => {
                            el.classList.remove('unread');
                            el.style.borderLeft = 'none';
                            el.style.background = 'transparent';
                        });
                    });
                }
            }
        }
        
        document.addEventListener('click', function(e) {
            const wrapper = document.querySelector('.notif-wrapper');
            const dd = document.getElementById('notifDropdown');
            if (wrapper && !wrapper.contains(e.target)) {
                dd.classList.remove('active');
            }
            // Close nav on outside click
             if(navMenu && !navMenu.contains(e.target) && !menuToggle.contains(e.target)) {
                navMenu.classList.remove('active');
            }
        });

        function markAllRead(e) {
            e.stopPropagation();
            toggleNotifications();
        }

        function setAmount(val) {
            document.getElementById('amountInput').value = val;
            document.querySelectorAll('.amount-tag').forEach(tag => {
                tag.classList.remove('active');
                if(tag.textContent.includes(val)) tag.classList.add('active');
            });
        }

        function copyIBAN(text) {
            navigator.clipboard.writeText(text).then(() => {
                const Toast = Swal.mixin({
                    toast: true, position: 'top-end', showConfirmButton: false, timer: 3000, timerProgressBar: true,
                    background: '#1e293b', color: '#fff', iconColor: '#10b981'
                });
                Toast.fire({ icon: 'success', title: 'IBAN Kopyalandı!' });
            });
        }

        function previewFile() {
            const file = document.getElementById('receiptFile').files[0];
            const preview = document.getElementById('previewImg');
            const name = document.getElementById('fileName');
            
            if(file) {
                name.textContent = file.name;
                if(file.type.startsWith('image/')) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        preview.src = e.target.result;
                        preview.style.display = 'block';
                    }
                    reader.readAsDataURL(file);
                } else {
                    preview.style.display = 'none';
                }
            }
        }
    </script>
</body>
</html>
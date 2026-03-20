<?php
ob_start();
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

// Bildirimleri Okundu İşaretle (AJAX için)
if (isset($_POST['action']) && $_POST['action'] == 'read_notifications') {
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $stmt->execute([$user['id']]);
    echo 'ok';
    exit;
}

$_SESSION['user_role'] = $user['user_role'];
$_SESSION['balance'] = $user['balance'];
$_SESSION['api_key'] = $user['api_key'];

// --- VERİ ÇEKME İŞLEMLERİ ---

// 1. Destek Talebi Bildirimleri
$unread_ticket_msg = 0;
try {
    // Tablo kontrolü (Hata vermemesi için)
    $stmt = $pdo->query("SHOW TABLES LIKE 'support_messages'");
    if ($stmt->rowCount() > 0) {
        // ... (Mevcut destek kodu aynen kaldı) ...
    }
} catch (PDOException $e) {}

// 2. İstatistikler
$stats = [
    'total_orders' => 0,
    'active_orders' => 0,
    'completed_orders' => 0,
    'total_spent' => 0
];

// 3. Bildirimleri Çek (Yeni Eklenen Kısım)
$notifications = [];
$unread_notif_count = 0;
try {
    // Okunmamış sayısını al
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$user['id']]);
    $unread_notif_count = $stmt->fetchColumn();

    // Son 5 bildirimi çek
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
    $stmt->execute([$user['id']]);
    $notifications = $stmt->fetchAll();
} catch (PDOException $e) {}

// 4. Duyuruları Çek (Dashboard'da göstermek için)
$announcements = [];
try {
    $stmt = $pdo->query("SELECT * FROM announcements WHERE is_active = 1 ORDER BY created_at DESC LIMIT 5");
    $announcements = $stmt->fetchAll();
} catch (PDOException $e) {}

// 5. Sipariş İstatistikleri ve Geçmişi
$recent_orders = [];
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'orders'");
    if ($stmt->rowCount() > 0) {
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM orders WHERE user_id = ?");
        $stmt->execute([$user['id']]);
        $stats['total_orders'] = $stmt->fetchColumn();
        
        $stmt = $pdo->prepare("SELECT COUNT(*) as active FROM orders WHERE user_id = ? AND status IN ('processing', 'inprogress', 'pending')");
        $stmt->execute([$user['id']]);
        $stats['active_orders'] = $stmt->fetchColumn();
        
        $stmt = $pdo->prepare("SELECT SUM(price) as total_spent FROM orders WHERE user_id = ? AND status = 'completed'");
        $stmt->execute([$user['id']]);
        $total = $stmt->fetchColumn();
        $stats['total_spent'] = $total ?: 0;
        
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
        $stmt->execute([$user['id']]);
        $recent_orders = $stmt->fetchAll();
    }
} catch (PDOException $e) {}

// Popüler Servisler (Sabit Veri)
$popular_services = [
    ['id' => 101, 'name' => 'Instagram Takipçi [Gerçek & Telafi]', 'category' => 'Instagram Takipçi', 'price_per_1000' => 18.50, 'min' => 100, 'max' => 50000],
    ['id' => 102, 'name' => 'Instagram Beğeni [Hızlı Gönderim]', 'category' => 'Instagram Beğeni', 'price_per_1000' => 6.20, 'min' => 50, 'max' => 20000],
    ['id' => 103, 'name' => 'Instagram İzlenme [Keşfet Etkili]', 'category' => 'Instagram Video', 'price_per_1000' => 0.85, 'min' => 100, 'max' => 1000000]
];

// Seviye Sistemi
$user_xp = $stats['total_spent'];
$user_rank = "Yeni Üye";
$next_rank_xp = 250;
$xp_percentage = 0;

if ($user_xp >= 10000) { $user_rank = "VIP Üye"; $next_rank_xp = 10000; $xp_percentage = 100; }
elseif ($user_xp >= 5000) { $user_rank = "Elit Üye"; $next_rank_xp = 10000; }
elseif ($user_xp >= 1000) { $user_rank = "Usta Üye"; $next_rank_xp = 5000; }
elseif ($user_xp >= 250) { $user_rank = "Aktif Üye"; $next_rank_xp = 1000; }
else { $user_rank = "Başlangıç"; $next_rank_xp = 250; }

if ($xp_percentage != 100) { $xp_percentage = ($user_xp / $next_rank_xp) * 100; }

// Grafik Verisi
$spending_data = [];
$spending_data_json = json_encode([]); // Default boş
try {
    $stmt = $pdo->prepare("
        SELECT DATE(created_at) as date, SUM(price) as daily_total
        FROM orders 
        WHERE user_id = ? AND status = 'completed' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY DATE(created_at) ORDER BY date ASC
    ");
    $stmt->execute([$user['id']]);
    $raw_data = $stmt->fetchAll();
    
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $found = false;
        foreach ($raw_data as $row) {
            if ($row['date'] == $date) {
                $spending_data[] = ['date' => date('d.m', strtotime($date)), 'amount' => floatval($row['daily_total'])];
                $found = true;
                break;
            }
        }
        if (!$found) $spending_data[] = ['date' => date('d.m', strtotime($date)), 'amount' => 0];
    }
    $spending_data_json = json_encode($spending_data);
} catch (PDOException $e) {}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Darq SMM Panel</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        body { font-family: 'Plus Jakarta Sans', sans-serif; color: var(--text-main); background: var(--bg-body); line-height: 1.6; overflow-x: hidden; }

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
        .notif-item { padding: 15px; border-bottom: 1px solid rgba(255,255,255,0.05); transition: 0.2s; }
        .notif-item:hover { background: rgba(255,255,255,0.02); }
        .notif-item.unread { background: rgba(139, 92, 246, 0.05); border-left: 3px solid var(--primary); }
        .notif-title { font-size: 0.9rem; font-weight: 600; color: white; margin-bottom: 3px; }
        .notif-msg { font-size: 0.8rem; color: var(--text-muted); }
        .notif-time { font-size: 0.7rem; color: var(--text-muted); margin-top: 5px; text-align: right; opacity: 0.7; }
        .notif-empty { padding: 20px; text-align: center; color: var(--text-muted); font-size: 0.9rem; }

        .main-content { padding: 100px 0 40px; }
        .dashboard-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 20px; }
        .dashboard-header h1 { font-family: 'Outfit', sans-serif; font-size: 2rem; font-weight: 700; }
        .gradient-text { background: var(--gradient-text); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        
        .btn { padding: 10px 20px; border-radius: 12px; font-weight: 600; text-decoration: none; transition: 0.3s; display: inline-flex; align-items: center; gap: 8px; border: none; cursor: pointer; font-size: 0.95rem; }
        .btn-primary { background: var(--gradient-main); color: white; box-shadow: var(--glow); }
        .btn-primary:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(139, 92, 246, 0.5); }
        .btn-outline { background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.15); color: white; }
        .btn-outline:hover { background: rgba(255,255,255,0.1); border-color: white; transform: translateY(-3px); }
        .btn-full { width: 100%; justify-content: flex-start; margin-bottom: 10px; }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 40px; }
        .stat-card { background: var(--bg-card); backdrop-filter: blur(15px); border: var(--glass-border); border-radius: 20px; padding: 25px; transition: 0.3s; position: relative; overflow: hidden; }
        .stat-card:hover { transform: translateY(-5px); border-color: var(--primary); box-shadow: var(--glow); }
        .stat-card::before { content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 4px; background: var(--gradient-main); }
        .stat-value { font-family: 'Outfit', sans-serif; font-size: 2rem; font-weight: 700; color: white; line-height: 1; margin-bottom: 5px; }
        .stat-label { color: var(--text-muted); font-size: 0.9rem; }
        .stat-icon { width: 50px; height: 50px; background: rgba(139, 92, 246, 0.1); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: var(--primary); font-size: 1.5rem; }

        .dashboard-sections { display: grid; grid-template-columns: 2fr 1fr; gap: 30px; }
        .content-card { background: var(--bg-card); backdrop-filter: blur(15px); border: var(--glass-border); border-radius: 24px; padding: 30px; margin-bottom: 30px; }
        .card-title { font-family: 'Outfit', sans-serif; font-size: 1.3rem; font-weight: 700; color: white; margin-bottom: 25px; }
        
        .services-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; }
        .service-card { background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.05); border-radius: 16px; padding: 20px; cursor: pointer; transition: 0.3s; }
        .service-card:hover { border-color: var(--primary); background: rgba(139, 92, 246, 0.05); transform: translateY(-5px); }
        .s-icon { width: 45px; height: 45px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; color: white; background: var(--gradient-main); }
        .s-price { font-size: 1.2rem; font-weight: 700; color: var(--primary); }

        .list-item { display: flex; justify-content: space-between; padding: 15px; background: rgba(255,255,255,0.02); border-radius: 12px; margin-bottom: 10px; border: 1px solid rgba(255,255,255,0.05); transition: 0.2s; align-items: flex-start; flex-direction: column; gap: 5px; }
        .announcement-tag { font-size: 0.7rem; padding: 2px 8px; border-radius: 6px; text-transform: uppercase; font-weight: 700; margin-bottom: 5px; display: inline-block; }
        .tag-info { background: rgba(59, 130, 246, 0.2); color: #3B82F6; }
        .tag-warning { background: rgba(245, 158, 11, 0.2); color: #F59E0B; }
        .tag-danger { background: rgba(239, 68, 68, 0.2); color: #EF4444; }
        .tag-success { background: rgba(16, 185, 129, 0.2); color: #10B981; }

        .status-badge { padding: 4px 12px; border-radius: 20px; font-size: 0.8rem; font-weight: 600; }
        .status-completed { background: rgba(16, 185, 129, 0.15); color: #10B981; }

        .progress-container { background: rgba(255,255,255,0.1); border-radius: 10px; height: 12px; width: 100%; overflow: hidden; margin-top: 10px; }
        .progress-bar { height: 100%; background: var(--gradient-main); border-radius: 10px; transition: width 0.5s ease; }

        .chart-container { position: relative; height: 250px; width: 100%; margin-top: 15px; }

        .ticket-toast {
            position: fixed; bottom: 30px; right: 30px; background: rgba(30, 41, 59, 0.95); border: 1px solid rgba(139, 92, 246, 0.4);
            border-radius: 16px; padding: 20px; display: flex; align-items: center; gap: 15px; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
            z-index: 9999; backdrop-filter: blur(10px); border-left: 4px solid var(--primary);
        }

        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); backdrop-filter: blur(5px); z-index: 2000; align-items: center; justify-content: center; }
        .modal-content { background: var(--bg-card); border: var(--glass-border); padding: 30px; border-radius: 24px; width: 90%; max-width: 500px; position: relative; animation: slideUp 0.3s ease; }
        @keyframes slideUp { from { transform: translateY(30px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

        .form-control { width: 100%; padding: 12px 15px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.1); border-radius: 12px; color: white; margin-bottom: 15px; }

        .footer { padding: 40px 0; border-top: var(--glass-border); margin-top: 50px; text-align: center; color: var(--text-muted); font-size: 0.9rem; }

        @media (max-width: 992px) {
            .dashboard-sections { grid-template-columns: 1fr; }
            .nav-menu { display: none; position: fixed; top: 70px; left: 0; width: 100%; background: rgba(2,6,23,0.98); flex-direction: column; padding: 20px; height: calc(100vh - 70px); }
            .nav-menu.active { display: flex; }
            .menu-toggle { display: block; }
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
            <a href="dashboard.php" class="logo"><i class="fas fa-bolt"></i> Darq</a>
            <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
            <div class="nav-menu" id="navMenu">
                <a href="dashboard.php" class="active"><i class="fas fa-home"></i> Dashboard</a>
                <a href="services.php"><i class="fas fa-box"></i> Hizmetler</a>
                <a href="orders.php"><i class="fas fa-history"></i> Siparişler</a>
                <a href="balance.php"><i class="fas fa-wallet"></i> Bakiye</a>
                <a href="support.php"><i class="fas fa-headset"></i> Destek</a>
                <?php if ($user['user_role'] === 'admin' || $user['user_role'] === 'super_admin'): ?>
                <a href="admin_dashboard.php"><i class="fas fa-user-shield"></i> Admin Panel</a>
                <?php endif; ?>
            </div>
            
            <div class="user-menu">
                <div class="notif-wrapper" onclick="toggleNotifications()">
                    <i class="fas fa-bell notif-bell"></i>
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

                <div class="balance-badge"><i class="fas fa-coins"></i> ₺<?php echo number_format($user['balance'], 2); ?></div>
                <a href="logout.php" class="btn btn-outline" style="padding: 6px 15px; font-size: 0.8rem;">Çıkış</a>
            </div>
        </div>
    </nav>

    <div class="main-content container">
        <div class="dashboard-header">
            <div>
                <h1>Hoş Geldin, <span class="gradient-text"><?php echo htmlspecialchars($user['username']); ?></span>! 👋</h1>
                <p style="color: var(--text-muted);">Bugün hesaplarını büyütmek için harika bir gün.</p>
            </div>
            <a href="services.php" class="btn btn-primary"><i class="fas fa-rocket"></i> Hızlı Sipariş</a>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header" style="display:flex; justify-content:space-between; align-items:center;">
                    <div><div class="stat-value">₺<?php echo number_format($user['balance'], 2); ?></div><div class="stat-label">Bakiye</div></div>
                    <div class="stat-icon"><i class="fas fa-wallet"></i></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header" style="display:flex; justify-content:space-between; align-items:center;">
                    <div><div class="stat-value"><?php echo number_format($stats['total_orders']); ?></div><div class="stat-label">Sipariş</div></div>
                    <div class="stat-icon"><i class="fas fa-shopping-bag"></i></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header" style="display:flex; justify-content:space-between; align-items:center;">
                    <div><div class="stat-value"><?php echo number_format($stats['active_orders']); ?></div><div class="stat-label">Aktif</div></div>
                    <div class="stat-icon"><i class="fas fa-sync fa-spin"></i></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header" style="display:flex; justify-content:space-between; align-items:center;">
                    <div><div class="stat-value">₺<?php echo number_format($stats['total_spent'], 2); ?></div><div class="stat-label">Harcama</div></div>
                    <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
                </div>
            </div>
        </div>

        <div class="dashboard-sections">
            <div class="left-col">
                <div class="content-card">
                    <div class="card-title">Popüler Hizmetler</div>
                    <div class="services-grid">
                        <?php foreach ($popular_services as $service): ?>
                        <div class="service-card" onclick="openOrderModal(<?php echo htmlspecialchars(json_encode($service)); ?>)">
                            <div class="service-header">
                                <div class="s-icon">
                                    <i class="fab fa-instagram"></i>
                                </div>
                                <div>
                                    <div class="s-name" style="color:white; font-weight:600;"><?php echo htmlspecialchars($service['name']); ?></div>
                                    <div class="s-cat" style="font-size:0.8rem; color:var(--text-muted);"><?php echo htmlspecialchars($service['category']); ?></div>
                                </div>
                            </div>
                            <div class="s-price">₺<?php echo number_format($service['price_per_1000'], 2); ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="content-card">
                    <div class="card-title">Harcama Analizi</div>
                    <div class="chart-container"><canvas id="spendingChart"></canvas></div>
                </div>
            </div>

            <div class="right-col">
                <div class="content-card">
                    <div class="card-title"><i class="fas fa-bullhorn" style="color:#F59E0B; margin-right:10px;"></i> Duyurular</div>
                    <?php if(!empty($announcements)): ?>
                        <?php foreach($announcements as $ann): ?>
                        <div class="list-item" style="display:block;">
                            <?php 
                                $tagClass = 'tag-info';
                                if($ann['type'] == 'warning') $tagClass = 'tag-warning';
                                if($ann['type'] == 'danger') $tagClass = 'tag-danger';
                                if($ann['type'] == 'success') $tagClass = 'tag-success';
                            ?>
                            <span class="announcement-tag <?php echo $tagClass; ?>"><?php echo strtoupper($ann['type']); ?></span>
                            <div style="font-weight:600; color:white; margin-bottom:5px;"><?php echo htmlspecialchars($ann['title']); ?></div>
                            <div style="font-size:0.85rem; color:var(--text-muted); line-height:1.4;"><?php echo htmlspecialchars($ann['content']); ?></div>
                            <div style="font-size:0.7rem; color:var(--text-muted); text-align:right; margin-top:5px;"><?php echo date('d.m.Y', strtotime($ann['created_at'])); ?></div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="text-align:center; color:var(--text-muted); padding:20px;">Henüz duyuru yok.</div>
                    <?php endif; ?>
                </div>

                <div class="content-card">
                    <div class="card-title">Hesap Seviyesi</div>
                    <div style="display:flex; justify-content:space-between; color:white;">
                        <span style="font-weight:bold; color:var(--primary);"><?php echo $user_rank; ?></span>
                        <span><?php echo number_format($user_xp, 2); ?> / <?php echo number_format($next_rank_xp, 0); ?> XP</span>
                    </div>
                    <div class="progress-container"><div class="progress-bar" style="width: <?php echo $xp_percentage; ?>%;"></div></div>
                </div>

                <div class="content-card">
                    <div class="card-title">Topluluk ve Destek</div>
                    <a href="https://t.me/DarqSmm0" target="_blank" class="btn btn-outline btn-full" style="color:#38bdf8; border-color:rgba(56,189,248,0.3);">
                        <i class="fab fa-telegram-plane"></i> Telegram Kanalı
                    </a>
                    <a href="support.php" class="btn btn-outline btn-full">
                        <i class="fas fa-headset"></i> Destek Talebi
                    </a>
                    <a href="https://wa.me/+212721490727" target="_blank" class="btn btn-outline btn-full" style="color:#25D366; border-color:rgba(37,211,102,0.3);">
                        <i class="fab fa-whatsapp"></i> WhatsApp
                    </a>
                    <?php if ($user['user_role'] === 'admin' || $user['user_role'] === 'super_admin'): ?>
                    <a href="admin_dashboard.php" class="btn btn-primary btn-full" style="margin-top:10px; background:linear-gradient(135deg, #ef4444, #b91c1c);">
                        <i class="fas fa-user-shield"></i> Admin Paneline Git
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <footer class="footer">
        <p>&copy; 2026 Darq SMM Panel. Tüm hakları saklıdır.</p>
    </footer>

    <div class="modal" id="orderModal">
        <div class="modal-content">
            <button class="close-modal" onclick="closeOrderModal()" style="position:absolute; top:20px; right:20px; background:none; border:none; color:white; cursor:pointer;"><i class="fas fa-times"></i></button>
            <h2 style="margin-bottom:20px;">Sipariş Oluştur</h2>
            <form action="create_order.php" method="POST">
                <input type="hidden" id="modalServiceId" name="service_id">
                <input type="text" id="modalServiceName" class="form-control" readonly>
                <input type="url" name="link" class="form-control" placeholder="Link (https://..)" required>
                <input type="number" id="quantity" name="quantity" class="form-control" placeholder="Miktar" required oninput="calculatePrice()">
                <div style="background:rgba(255,255,255,0.05); padding:15px; border-radius:12px; margin-bottom:20px; display:flex; justify-content:space-between;">
                    <span>Toplam:</span><span id="totalPrice" style="font-weight:700; color:var(--primary);">₺0.00</span>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center;">Onayla</button>
            </form>
        </div>
    </div>

    <script>
        const menuToggle = document.getElementById('menuToggle');
        const navMenu = document.getElementById('navMenu');
        menuToggle.addEventListener('click', () => navMenu.classList.toggle('active'));

        window.addEventListener('scroll', () => {
            const nav = document.getElementById('navbar');
            if(window.scrollY > 50) nav.classList.add('scrolled');
            else nav.classList.remove('scrolled');
        });

        // Bildirim Sistemi JS
        function toggleNotifications() {
            const dd = document.getElementById('notifDropdown');
            dd.classList.toggle('active');
            
            // Eğer açıldıysa ve badge varsa okundu say
            if(dd.classList.contains('active')) {
                const badge = document.getElementById('notifBadge');
                if(badge) {
                    fetch('dashboard.php', {
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

        // Dışarı tıklayınca kapat
        document.addEventListener('click', function(e) {
            const wrapper = document.querySelector('.notif-wrapper');
            const dd = document.getElementById('notifDropdown');
            if (wrapper && !wrapper.contains(e.target)) {
                dd.classList.remove('active');
            }
        });

        function markAllRead(e) {
            e.stopPropagation();
            toggleNotifications(); // Zaten açarken okundu yapıyor
        }

        let currentService = null;
        function openOrderModal(service) {
            currentService = service;
            document.getElementById('modalServiceName').value = service.name;
            document.getElementById('modalServiceId').value = service.id;
            document.getElementById('quantity').value = service.min;
            calculatePrice();
            document.getElementById('orderModal').style.display = 'flex';
        }
        function closeOrderModal() { document.getElementById('orderModal').style.display = 'none'; }
        function calculatePrice() {
            if(!currentService) return;
            let qty = document.getElementById('quantity').value;
            let price = (qty / 1000) * currentService.price_per_1000;
            document.getElementById('totalPrice').innerText = '₺' + price.toFixed(2);
        }

        const ctx = document.getElementById('spendingChart').getContext('2d');
        const spendingData = <?php echo $spending_data_json; ?>;
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: spendingData.map(d => d.date),
                datasets: [{
                    label: 'Harcama',
                    data: spendingData.map(d => d.amount),
                    borderColor: '#8B5CF6',
                    backgroundColor: 'rgba(139, 92, 246, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
        });
    </script>
</body>
</html>
<?php
ob_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$admin = $stmt->fetch();

if (!$admin || ($admin['user_role'] !== 'admin' && $admin['user_role'] !== 'super_admin')) {
    header('Location: dashboard.php');
    exit;
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS announcements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        content TEXT NOT NULL,
        type ENUM('info', 'success', 'warning', 'danger') DEFAULT 'info',
        is_active BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        title VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        type ENUM('info', 'success', 'warning', 'danger') DEFAULT 'info',
        is_read BOOLEAN DEFAULT FALSE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id)
    )");
} catch (PDOException $e) {}

$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_announcement'])) {
    $title = trim($_POST['title']);
    $content = trim($_POST['content']);
    $type = $_POST['type'];
    $send_notification = isset($_POST['send_notification']);

    if (!empty($title) && !empty($content)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO announcements (title, content, type) VALUES (?, ?, ?)");
            $stmt->execute([$title, $content, $type]);

            if ($send_notification) {
                $users = $pdo->query("SELECT id FROM users WHERE user_role != 'admin'")->fetchAll(PDO::FETCH_COLUMN);
                
                if ($users) {
                    $values = [];
                    $placeholders = [];
                    foreach ($users as $uid) {
                        $values[] = $uid;
                        $values[] = $title;
                        $values[] = $content;
                        $values[] = $type;
                        $placeholders[] = "(?, ?, ?, ?)";
                    }
                    
                    if (!empty($placeholders)) {
                        $sql = "INSERT INTO notifications (user_id, title, message, type) VALUES " . implode(', ', $placeholders);
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute($values);
                    }
                }
            }
            $success_msg = "Duyuru yayınlandı" . ($send_notification ? " ve tüm kullanıcılara bildirim gönderildi." : ".");
        } catch (PDOException $e) {
            $error_msg = "İşlem sırasında hata oluştu: " . $e->getMessage();
        }
    } else {
        $error_msg = "Başlık ve içerik alanları zorunludur.";
    }
}

$stats = [
    'total_users' => 0, 'total_orders' => 0, 'today_orders' => 0,
    'total_balance' => 0, 'pending_orders' => 0, 'pending_payments' => 0,
    'open_tickets' => 0, 'live_chats' => 0
];

try {
    $stats['total_users'] = $pdo->query("SELECT COUNT(*) FROM users WHERE user_role != 'admin'")->fetchColumn();
    $stats['total_orders'] = $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
    $stats['today_orders'] = $pdo->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()")->fetchColumn();
    $stats['total_balance'] = $pdo->query("SELECT SUM(balance) FROM users WHERE user_role != 'admin'")->fetchColumn() ?: 0;
    $stats['pending_orders'] = $pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ('pending', 'processing', 'inprogress')")->fetchColumn();
    $stats['pending_payments'] = $pdo->query("SELECT COUNT(*) FROM payments WHERE status = 'pending'")->fetchColumn();
    $stats['open_tickets'] = $pdo->query("SELECT COUNT(*) FROM tickets WHERE status = 'open'")->fetchColumn();
    
    $check = $pdo->query("SHOW TABLES LIKE 'live_support_messages'");
    if($check->rowCount() > 0) {
        $stats['live_chats'] = $pdo->query("SELECT COUNT(DISTINCT user_id) FROM live_support_messages WHERE created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE) AND is_admin = 0")->fetchColumn();
    }
} catch (PDOException $e) {}

$recent_orders = $pdo->query("SELECT o.*, u.username FROM orders o JOIN users u ON o.user_id = u.id ORDER BY o.created_at DESC LIMIT 5")->fetchAll();
$recent_users = $pdo->query("SELECT * FROM users WHERE user_role != 'admin' ORDER BY created_at DESC LIMIT 5")->fetchAll();
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - Darq SMM</title>
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
        .glow-blob { position: absolute; filter: blur(90px); opacity: 0.3; border-radius: 50%; animation: float 10s infinite ease-in-out alternate; }
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

        .dashboard-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 20px; }
        .dashboard-header h1 { font-family: 'Outfit'; font-size: 2rem; font-weight: 700; color: white; }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: var(--bg-card); border: var(--glass-border); padding: 25px; border-radius: 24px; position: relative; overflow: hidden; transition: 0.3s; }
        .stat-card:hover { transform: translateY(-5px); border-color: var(--primary); box-shadow: var(--glow); }
        .stat-card::before { content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 4px; background: var(--gradient-main); }
        
        .stat-value { font-family: 'Outfit'; font-size: 2.2rem; font-weight: 700; color: white; line-height: 1; }
        .stat-label { color: var(--text-muted); font-size: 0.9rem; margin-top: 5px; }
        .stat-icon { position: absolute; right: 20px; top: 25px; width: 50px; height: 50px; background: rgba(139, 92, 246, 0.1); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: var(--primary); font-size: 1.5rem; }

        .dashboard-layout { display: grid; grid-template-columns: 2fr 1fr; gap: 30px; }
        
        .section-card { background: var(--bg-card); border: var(--glass-border); border-radius: 24px; padding: 30px; margin-bottom: 30px; backdrop-filter: blur(10px); }
        .section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        .section-title { font-family: 'Outfit'; font-size: 1.3rem; font-weight: 700; color: white; display: flex; align-items: center; gap: 10px; }
        
        .list-item { display: flex; justify-content: space-between; align-items: center; padding: 15px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.05); border-radius: 16px; margin-bottom: 10px; transition: 0.2s; }
        .list-item:hover { background: rgba(255,255,255,0.05); border-color: rgba(139,92,246,0.3); }
        .item-info h4 { font-size: 0.95rem; font-weight: 600; color: white; margin-bottom: 4px; }
        .item-details { font-size: 0.8rem; color: var(--text-muted); display: flex; gap: 10px; }
        
        .status-badge { padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; }
        .status-pending { background: rgba(245, 158, 11, 0.15); color: var(--accent); }
        .status-completed { background: rgba(16, 185, 129, 0.15); color: var(--secondary); }
        .status-processing { background: rgba(59, 130, 246, 0.15); color: #3B82F6; }

        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 500; color: var(--text-muted); font-size: 0.9rem; }
        .modern-input { width: 100%; padding: 12px 15px; background: rgba(2, 6, 23, 0.5); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 12px; color: white; font-size: 0.95rem; transition: 0.3s; font-family: 'Plus Jakarta Sans'; }
        .modern-input:focus { outline: none; border-color: var(--primary); background: rgba(139, 92, 246, 0.05); }
        textarea.modern-input { resize: none; min-height: 100px; }

        .btn { padding: 12px 20px; border-radius: 12px; font-weight: 600; text-decoration: none; transition: 0.3s; display: inline-flex; align-items: center; justify-content: center; gap: 8px; border: none; cursor: pointer; font-size: 0.95rem; width: 100%; }
        .btn-primary { background: var(--gradient-main); color: white; box-shadow: var(--glow); }
        .btn-primary:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(139, 92, 246, 0.5); }
        
        .checkbox-wrapper { display: flex; align-items: center; gap: 10px; margin-top: 10px; cursor: pointer; }
        .checkbox-wrapper input { width: 18px; height: 18px; accent-color: var(--primary); cursor: pointer; }
        .checkbox-wrapper span { font-size: 0.9rem; color: var(--text-main); }

        .admin-quick-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .quick-btn { background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.05); border-radius: 16px; padding: 20px; text-align: center; color: white; text-decoration: none; transition: 0.3s; display: flex; flex-direction: column; align-items: center; gap: 10px; }
        .quick-btn:hover { background: var(--primary); border-color: var(--primary); transform: translateY(-3px); }
        .quick-btn i { font-size: 1.8rem; color: var(--text-muted); transition: 0.3s; }
        .quick-btn:hover i { color: white; }
        
        .notification-badge { background: var(--danger); color: white; font-size: 0.75rem; padding: 2px 8px; border-radius: 10px; margin-left: 5px; }

        @media (max-width: 992px) {
            .nav-menu { display: none; }
            .dashboard-layout { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
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
                <i class="fas fa-bolt"></i> Darq <span class="admin-badge">YÖNETİM</span>
            </a>
            
            <div class="nav-menu">
                <a href="admin_dashboard.php" class="active">Dashboard</a>
                <a href="admin_orders.php">Siparişler</a>
                <a href="admin_payments.php">Ödemeler</a>
                <a href="admin_users.php">Kullanıcılar</a>
                <a href="admin_tickets.php">Destek</a>
                <a href="dashboard.php" style="color: var(--accent);">Siteyi Görüntüle <i class="fas fa-external-link-alt"></i></a>
            </div>

            <div style="display:flex; gap:15px; align-items:center;">
                <a href="logout.php" class="btn btn-primary" style="padding: 8px 15px; width: auto; font-size: 0.85rem; background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); color: #EF4444; box-shadow: none;">Çıkış</a>
            </div>
        </div>
    </nav>

    <div class="main-content container">
        
        <div class="dashboard-header">
            <div>
                <h1>Yönetim Paneli</h1>
                <p style="color: var(--text-muted);">Sistem durumunu ve aktiviteleri buradan yönetebilirsiniz.</p>
            </div>
            <div style="display:flex; gap:10px;">
                <a href="admin_live_support.php" target="_blank" class="btn btn-primary" style="background: #3B82F6; box-shadow: 0 0 20px rgba(59, 130, 246, 0.3);">
                    <i class="fas fa-headset"></i> Canlı Destek (<?php echo $stats['live_chats']; ?>)
                </a>
            </div>
        </div>

        <?php if ($success_msg): ?>
            <script>document.addEventListener('DOMContentLoaded', () => Swal.fire({icon: 'success', title: 'Başarılı', text: '<?php echo $success_msg; ?>'}));</script>
        <?php endif; ?>
        <?php if ($error_msg): ?>
            <script>document.addEventListener('DOMContentLoaded', () => Swal.fire({icon: 'error', title: 'Hata', text: '<?php echo $error_msg; ?>'}));</script>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['total_users']; ?></div>
                <div class="stat-label">Toplam Kullanıcı</div>
                <div class="stat-icon"><i class="fas fa-users"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['total_orders']; ?></div>
                <div class="stat-label">Toplam Sipariş</div>
                <div class="stat-icon"><i class="fas fa-shopping-bag"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-value">₺<?php echo number_format($stats['total_balance'], 2); ?></div>
                <div class="stat-label">Kullanıcı Bakiyeleri</div>
                <div class="stat-icon"><i class="fas fa-wallet"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: <?php echo $stats['pending_orders'] > 0 ? '#F59E0B' : 'white'; ?>"><?php echo $stats['pending_orders']; ?></div>
                <div class="stat-label">Bekleyen Sipariş</div>
                <div class="stat-icon"><i class="fas fa-clock"></i></div>
            </div>
        </div>

        <div class="dashboard-layout">
            <div>
                <div class="section-card">
                    <div class="section-header">
                        <h2 class="section-title"><i class="fas fa-list-ul"></i> Son Siparişler</h2>
                        <a href="admin_orders.php" style="color: var(--primary); text-decoration: none; font-size: 0.9rem;">Tümü</a>
                    </div>
                    <?php foreach ($recent_orders as $order): ?>
                        <div class="list-item">
                            <div class="item-info">
                                <h4><?php echo htmlspecialchars(mb_strimwidth($order['service_name'], 0, 40, '...')); ?></h4>
                                <div class="item-details">
                                    <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($order['username']); ?></span>
                                    <span><i class="fas fa-tag"></i> ₺<?php echo number_format($order['price'], 2); ?></span>
                                </div>
                            </div>
                            <div class="status-badge status-<?php echo $order['status']; ?>">
                                <?php echo $order['status']; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="section-card">
                    <div class="section-header">
                        <h2 class="section-title"><i class="fas fa-user-plus"></i> Son Kayıtlar</h2>
                        <a href="admin_users.php" style="color: var(--primary); text-decoration: none; font-size: 0.9rem;">Tümü</a>
                    </div>
                    <?php foreach ($recent_users as $user): ?>
                        <div class="list-item">
                            <div class="item-info">
                                <h4><?php echo htmlspecialchars($user['username']); ?></h4>
                                <div class="item-details">
                                    <span><?php echo htmlspecialchars($user['email']); ?></span>
                                </div>
                            </div>
                            <div style="font-size: 0.8rem; color: var(--text-muted);">
                                <?php echo date('d.m.Y', strtotime($user['created_at'])); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div>
                <div class="section-card" style="border-color: rgba(139, 92, 246, 0.3);">
                    <div class="section-header">
                        <h2 class="section-title"><i class="fas fa-bullhorn"></i> Duyuru & Bildirim</h2>
                    </div>
                    <form method="POST">
                        <div class="form-group">
                            <label>Başlık</label>
                            <input type="text" name="title" class="modern-input" placeholder="Örn: Sistem Bakımı" required>
                        </div>
                        <div class="form-group">
                            <label>İçerik</label>
                            <textarea name="content" class="modern-input" placeholder="Duyuru metnini buraya girin..." required></textarea>
                        </div>
                        <div class="form-group">
                            <label>Tip</label>
                            <select name="type" class="modern-input">
                                <option value="info">Bilgi (Mavi)</option>
                                <option value="success">Başarılı (Yeşil)</option>
                                <option value="warning">Uyarı (Sarı)</option>
                                <option value="danger">Kritik (Kırmızı)</option>
                            </select>
                        </div>
                        <label class="checkbox-wrapper">
                            <input type="checkbox" name="send_notification" checked>
                            <span>Tüm kullanıcılara bildirim olarak da gönder</span>
                        </label>
                        <button type="submit" name="send_announcement" class="btn btn-primary" style="margin-top: 15px;">
                            <i class="fas fa-paper-plane"></i> Yayınla
                        </button>
                    </form>
                </div>

                <div class="section-card">
                    <div class="section-header">
                        <h2 class="section-title"><i class="fas fa-bolt"></i> Hızlı İşlemler</h2>
                    </div>
                    <div class="admin-quick-actions">
                        <a href="admin_orders.php?status=pending" class="quick-btn">
                            <i class="fas fa-clock" style="color: #F59E0B;"></i>
                            <div>
                                <div>Sipariş Onayı</div>
                                <div style="font-size: 0.8rem; opacity: 0.7;"><?php echo $stats['pending_orders']; ?> Bekleyen</div>
                            </div>
                        </a>
                        <a href="admin_payments.php?status=pending" class="quick-btn">
                            <i class="fas fa-money-check-alt" style="color: #10B981;"></i>
                            <div>
                                <div>Ödeme Onayı</div>
                                <div style="font-size: 0.8rem; opacity: 0.7;"><?php echo $stats['pending_payments']; ?> Bekleyen</div>
                            </div>
                        </a>
                        <a href="admin_tickets.php?status=open" class="quick-btn">
                            <i class="fas fa-ticket-alt" style="color: #EF4444;"></i>
                            <div>
                                <div>Destek Talebi</div>
                                <div style="font-size: 0.8rem; opacity: 0.7;"><?php echo $stats['open_tickets']; ?> Açık</div>
                            </div>
                        </a>
                        <a href="admin_services.php" class="quick-btn">
                            <i class="fas fa-list" style="color: #8B5CF6;"></i>
                            <div>
                                <div>Servis Düzenle</div>
                                <div style="font-size: 0.8rem; opacity: 0.7;">Fiyat Ayarları</div>
                            </div>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</body>
</html>
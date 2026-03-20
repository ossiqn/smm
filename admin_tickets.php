<?php
ob_start();
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: dashboard.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$admin = $stmt->fetch();

// --- AJAX: Talep Detaylarını Getir ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'get_ticket_details') {
    while (ob_get_level()) { ob_end_clean(); }
    
    $ticket_string_id = $_POST['ticket_id'];

    $stmt = $pdo->prepare("SELECT * FROM tickets WHERE ticket_id = ?");
    $stmt->execute([$ticket_string_id]);
    $ticket = $stmt->fetch();

    if (!$ticket) {
        echo '<div style="padding:20px; text-align:center; color:#EF4444;">Talep bulunamadı.</div>';
        exit;
    }

    $conversation = [];

    // Kullanıcının ilk mesajı
    $conversation[] = [
        'type' => 'user',
        'message' => $ticket['message'],
        'created_at' => $ticket['created_at'],
        'sender_name' => $ticket['username']
    ];

    // Mesajlaşma geçmişi
    $stmt = $pdo->prepare("SELECT message, created_at, is_admin FROM ticket_messages WHERE ticket_id = ? ORDER BY created_at ASC");
    $stmt->execute([$ticket_string_id]);
    $replies = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach($replies as $reply) {
        $conversation[] = [
            'type' => $reply['is_admin'] ? 'admin' : 'user',
            'message' => $reply['message'],
            'created_at' => $reply['created_at'],
            'sender_name' => $reply['is_admin'] ? 'Destek Ekibi' : $ticket['username']
        ];
    }

    usort($conversation, function($a, $b) {
        return strtotime($a['created_at']) - strtotime($b['created_at']);
    });
    ?>

    <div class="chat-header-info">
        <div>
            <h3 class="modal-ticket-subject"><?php echo htmlspecialchars($ticket['subject']); ?></h3>
            <div class="modal-ticket-meta">
                <span class="meta-id">#<?php echo $ticket['ticket_id']; ?></span>
                <span class="status-badge status-<?php echo $ticket['status']; ?>">
                    <?php echo strtoupper($ticket['status']); ?>
                </span>
            </div>
        </div>
        <button type="button" class="btn-close-modal" onclick="closeModal()">
            <i class="fas fa-times"></i>
        </button>
    </div>

    <div class="chat-area" id="chatAreaScroll">
        <?php foreach ($conversation as $msg): ?>
            <div class="message-row <?php echo $msg['type'] === 'admin' ? 'outgoing' : 'incoming'; ?>">
                <div class="message-bubble">
                    <div class="message-sender">
                        <?php if($msg['type'] === 'admin'): ?>
                            <i class="fas fa-headset"></i> Destek Ekibi
                        <?php else: ?>
                            <i class="fas fa-user"></i> <?php echo htmlspecialchars($msg['sender_name']); ?>
                        <?php endif; ?>
                    </div>
                    <div class="message-text">
                        <?php echo nl2br(htmlspecialchars($msg['message'])); ?>
                    </div>
                    <div class="message-time">
                        <?php echo date('d.m H:i', strtotime($msg['created_at'])); ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($ticket['status'] !== 'closed'): ?>
        <form id="replyForm" class="reply-box" onsubmit="submitReply(event, '<?php echo $ticket['ticket_id']; ?>')">
            <input type="hidden" name="action" value="quick_reply">
            <input type="hidden" name="ticket_id" value="<?php echo $ticket['id']; ?>">
            
            <div class="quick-actions">
                <span onclick="insertTemplate('Merhaba,\n\nSorununuz çözülmüştür. İyi günler dileriz.')">✅ Çözüldü</span>
                <span onclick="insertTemplate('Merhaba,\n\nKonuyu ilgili birime ilettik, lütfen bekleyiniz.')">⏳ İnceleniyor</span>
                <span onclick="insertTemplate('Merhaba,\n\nLütfen sipariş numaranızı iletir misiniz?')">❓ Bilgi İste</span>
            </div>
            
            <div class="input-group">
                <textarea name="message" id="replyText" class="form-control" placeholder="Yanıtınızı buraya yazın..." rows="2" required></textarea>
            </div>
            
            <div class="action-buttons">
                <button type="button" class="btn btn-danger-soft" onclick="closeTicket(<?php echo $ticket['id']; ?>)">
                    <i class="fas fa-lock"></i> Kapat
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-paper-plane"></i> Gönder
                </button>
            </div>
        </form>
    <?php else: ?>
        <div class="closed-notice">
            <i class="fas fa-lock"></i> Bu talep kapatılmıştır. Yanıt verilemez.
            <br>
            <button type="button" onclick="reopenTicket(<?php echo $ticket['id']; ?>)" class="btn-text">Tekrar Aç</button>
        </div>
    <?php endif; ?>
    <?php
    exit;
}

// --- POST İŞLEMLERİ ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. HIZLI CEVAP VE BİLDİRİM
    if (isset($_POST['action']) && $_POST['action'] == 'quick_reply') {
        $id = intval($_POST['ticket_id']);
        $message = trim($_POST['message']);
        
        $t = $pdo->prepare("SELECT ticket_id, user_id FROM tickets WHERE id = ?");
        $t->execute([$id]);
        $ticket_data = $t->fetch();

        if ($ticket_data && !empty($message)) {
            // Mesajı kaydet
            $stmt = $pdo->prepare("INSERT INTO ticket_messages (ticket_id, admin_id, message, is_admin, created_at) VALUES (?, ?, ?, 1, NOW())");
            $stmt->execute([$ticket_data['ticket_id'], $_SESSION['user_id'], $message]);
            
            // Durumu güncelle
            $stmt = $pdo->prepare("UPDATE tickets SET status = 'answered', updated_at = NOW() WHERE id = ?");
            $stmt->execute([$id]);
            
            // --> KULLANICIYA BİLDİRİM GÖNDER <--
            try {
                $notif_title = "Destek Yanıtı";
                $notif_msg = "Ticket #{$ticket_data['ticket_id']} için yeni bir yanıtınız var.";
                $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, created_at) VALUES (?, ?, ?, 'info', NOW())");
                $stmt->execute([$ticket_data['user_id'], $notif_title, $notif_msg]);
            } catch(Exception $e){}

            echo "OK"; 
            exit;
        }
    }

    // 2. TİCKET KAPATMA VE BİLDİRİM
    if (isset($_POST['action']) && $_POST['action'] == 'close_ticket') {
        $id = intval($_POST['ticket_id']);
        
        // Önce ticket bilgilerini al (user_id lazım)
        $stmt = $pdo->prepare("SELECT user_id, ticket_id FROM tickets WHERE id = ?");
        $stmt->execute([$id]);
        $ticket_data = $stmt->fetch();

        if ($ticket_data) {
            $pdo->prepare("UPDATE tickets SET status = 'closed', updated_at = NOW() WHERE id = ?")->execute([$id]);
            
            // Bildirim Gönder
            $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, created_at) VALUES (?, ?, ?, 'warning', NOW())")
                ->execute([$ticket_data['user_id'], 'Talep Kapatıldı', "Ticket #{$ticket_data['ticket_id']} kapatıldı."]);
        }

        $_SESSION['success'] = "Talep kapatıldı.";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    // 3. TİCKET TEKRAR AÇMA VE BİLDİRİM
    if (isset($_POST['action']) && $_POST['action'] == 'reopen_ticket') {
        $id = intval($_POST['ticket_id']);
        
        $stmt = $pdo->prepare("SELECT user_id, ticket_id FROM tickets WHERE id = ?");
        $stmt->execute([$id]);
        $ticket_data = $stmt->fetch();

        if ($ticket_data) {
            $pdo->prepare("UPDATE tickets SET status = 'open', updated_at = NOW() WHERE id = ?")->execute([$id]);
            
            // Bildirim Gönder
            $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, created_at) VALUES (?, ?, ?, 'info', NOW())")
                ->execute([$ticket_data['user_id'], 'Talep Tekrar Açıldı', "Ticket #{$ticket_data['ticket_id']} tekrar işleme alındı."]);
        }

        $_SESSION['success'] = "Talep tekrar açıldı.";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    // 4. TİCKET SİLME
    if (isset($_POST['action']) && $_POST['action'] == 'delete_ticket') {
        $id = intval($_POST['ticket_id']);
        $t = $pdo->prepare("SELECT ticket_id FROM tickets WHERE id = ?");
        $t->execute([$id]);
        $data = $t->fetch();
        
        if ($data) {
            $pdo->prepare("DELETE FROM ticket_messages WHERE ticket_id = ?")->execute([$data['ticket_id']]);
            $pdo->prepare("DELETE FROM tickets WHERE id = ?")->execute([$id]);
            $_SESSION['success'] = "Talep silindi.";
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
}

// Tablo Kontrolleri
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, title VARCHAR(255) NOT NULL, message TEXT NOT NULL, type ENUM('info', 'success', 'warning', 'danger') DEFAULT 'info', is_read BOOLEAN DEFAULT FALSE, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_user_id (user_id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tickets (id INT AUTO_INCREMENT PRIMARY KEY, ticket_id VARCHAR(50) UNIQUE NOT NULL, user_id INT NOT NULL, username VARCHAR(100) NOT NULL, subject VARCHAR(255) NOT NULL, message TEXT NOT NULL, category VARCHAR(100), priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium', status ENUM('open', 'in_progress', 'answered', 'closed', 'resolved') DEFAULT 'open', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX idx_user_id (user_id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ticket_messages (id INT AUTO_INCREMENT PRIMARY KEY, ticket_id VARCHAR(50) NOT NULL, user_id INT, admin_id INT, message TEXT NOT NULL, is_admin BOOLEAN DEFAULT FALSE, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_ticket_id (ticket_id))");
} catch (Exception $e) { }

$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

$sql = "SELECT * FROM tickets WHERE 1=1";
$params = [];

if ($status_filter !== 'all') {
    $sql .= " AND status = ?";
    $params[] = $status_filter;
}

if (!empty($search_query)) {
    $sql .= " AND (subject LIKE ? OR ticket_id LIKE ? OR username LIKE ?)";
    $term = "%$search_query%";
    $params = array_merge($params, [$term, $term, $term]);
}

$sql .= " ORDER BY CASE WHEN status = 'open' THEN 1 WHEN status = 'in_progress' THEN 2 ELSE 3 END, updated_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tickets = $stmt->fetchAll();

$stats = [
    'open' => $pdo->query("SELECT COUNT(*) FROM tickets WHERE status = 'open'")->fetchColumn(),
    'answered' => $pdo->query("SELECT COUNT(*) FROM tickets WHERE status = 'answered'")->fetchColumn(),
    'closed' => $pdo->query("SELECT COUNT(*) FROM tickets WHERE status = 'closed'")->fetchColumn(),
    'total' => $pdo->query("SELECT COUNT(*) FROM tickets")->fetchColumn()
];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Destek Yönetimi - Admin Panel</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        :root { --primary: #8B5CF6; --primary-dark: #7C3AED; --secondary: #10B981; --accent: #F59E0B; --danger: #EF4444; --bg-body: #020617; --bg-card: rgba(30, 41, 59, 0.6); --text-main: #F8FAFC; --text-muted: #94A3B8; --gradient-main: linear-gradient(135deg, #8B5CF6 0%, #4F46E5 100%); --glass-border: 1px solid rgba(255, 255, 255, 0.08); --radius: 20px; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg-body); color: var(--text-main); min-height: 100vh; overflow-x: hidden; }
        .background-glow { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: -1; pointer-events: none; }
        .glow-blob { position: absolute; filter: blur(90px); opacity: 0.3; border-radius: 50%; }
        .blob-1 { top: -10%; left: -10%; width: 600px; height: 600px; background: var(--primary); }
        .blob-2 { bottom: 10%; right: -10%; width: 500px; height: 500px; background: #059669; }
        .navbar { padding: 20px 0; position: fixed; width: 100%; top: 0; z-index: 1000; background: rgba(2, 6, 23, 0.8); backdrop-filter: blur(15px); border-bottom: var(--glass-border); }
        .container { max-width: 1400px; margin: 0 auto; padding: 0 20px; }
        .nav-inner { display: flex; justify-content: space-between; align-items: center; }
        .logo { font-family: 'Outfit'; font-size: 1.6rem; font-weight: 800; color: white; text-decoration: none; display: flex; gap: 10px; align-items: center; }
        .logo i { color: var(--primary); }
        .admin-badge { background: var(--accent); color: #000; padding: 2px 8px; border-radius: 6px; font-size: 0.7rem; font-weight: bold; }
        .nav-menu { display: flex; gap: 20px; align-items: center; }
        .nav-menu a { color: var(--text-muted); text-decoration: none; font-weight: 500; font-size: 0.95rem; transition: 0.3s; display: flex; align-items: center; gap: 6px; }
        .nav-menu a:hover, .nav-menu a.active { color: white; }
        .nav-menu a.active { color: var(--primary); }
        .main-content { padding: 100px 0 40px; }
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: var(--bg-card); border: var(--glass-border); padding: 20px; border-radius: 20px; text-align: center; backdrop-filter: blur(10px); }
        .stat-number { font-family: 'Outfit'; font-size: 2rem; font-weight: 700; color: white; }
        .stat-label { color: var(--text-muted); font-size: 0.9rem; }
        .text-warning { color: var(--accent); } .text-success { color: var(--secondary); } .text-danger { color: var(--danger); }
        .filter-bar { display: flex; gap: 15px; margin-bottom: 30px; background: var(--bg-card); padding: 20px; border-radius: 16px; border: var(--glass-border); align-items: center; }
        .search-input { flex: 1; background: #0f172a; border: 1px solid rgba(255,255,255,0.1); padding: 12px 15px; border-radius: 12px; color: white; font-size: 0.95rem; }
        .filter-select { background: #0f172a; border: 1px solid rgba(255,255,255,0.1); padding: 12px 15px; border-radius: 12px; color: white; font-size: 0.95rem; min-width: 150px; }
        .btn-filter { background: var(--primary); color: white; border: none; padding: 12px 25px; border-radius: 12px; cursor: pointer; font-weight: 600; transition: 0.3s; }
        .btn-filter:hover { background: var(--primary-dark); }
        .table-container { background: var(--bg-card); border-radius: 24px; border: var(--glass-border); overflow: hidden; }
        .custom-table { width: 100%; border-collapse: collapse; }
        .custom-table th { text-align: left; padding: 18px 20px; background: rgba(0,0,0,0.2); color: var(--text-muted); font-weight: 600; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px; }
        .custom-table td { padding: 18px 20px; border-bottom: 1px solid rgba(255,255,255,0.05); color: var(--text-main); font-size: 0.95rem; vertical-align: middle; }
        .custom-table tr:hover { background: rgba(255,255,255,0.02); }
        .status-badge { padding: 5px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; display: inline-block; }
        .status-open { background: rgba(245, 158, 11, 0.15); color: #F59E0B; border: 1px solid rgba(245, 158, 11, 0.2); }
        .status-answered { background: rgba(16, 185, 129, 0.15); color: #10B981; border: 1px solid rgba(16, 185, 129, 0.2); }
        .status-closed { background: rgba(148, 163, 184, 0.1); color: var(--text-muted); border: 1px solid rgba(148, 163, 184, 0.2); }
        .btn-sm { padding: 8px 14px; border-radius: 8px; border: none; cursor: pointer; font-size: 0.85rem; color: white; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; transition: 0.3s; }
        .btn-view { background: rgba(139, 92, 246, 0.15); color: var(--primary); border: 1px solid rgba(139, 92, 246, 0.2); }
        .btn-view:hover { background: var(--primary); color: white; }
        .btn-delete { background: rgba(239, 68, 68, 0.15); color: var(--danger); border: 1px solid rgba(239, 68, 68, 0.2); }
        .btn-delete:hover { background: var(--danger); color: white; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); backdrop-filter: blur(8px); z-index: 2000; align-items: center; justify-content: center; }
        .modal-content { background: #1e293b; width: 95%; max-width: 800px; height: 85vh; border-radius: 24px; border: 1px solid rgba(139,92,246,0.3); display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 50px 100px rgba(0,0,0,0.5); }
        .chat-header-info { padding: 20px; background: rgba(15, 23, 42, 0.9); border-bottom: 1px solid rgba(255,255,255,0.05); display: flex; justify-content: space-between; align-items: center; flex-shrink: 0; }
        .modal-ticket-subject { font-size: 1.3rem; font-weight: 700; color: white; font-family: 'Outfit'; margin-bottom: 5px; }
        .modal-ticket-meta { font-size: 0.85rem; color: var(--text-muted); display: flex; gap: 15px; align-items: center; }
        .meta-id { color: var(--primary); font-family: monospace; font-weight: 600; }
        .btn-close-modal { background: none; border: none; color: var(--text-muted); font-size: 1.2rem; cursor: pointer; transition: 0.3s; }
        .btn-close-modal:hover { color: white; }
        .chat-area { flex: 1; padding: 25px; overflow-y: auto; display: flex; flex-direction: column; gap: 20px; background: rgba(2,6,23,0.5); }
        .message-row { display: flex; width: 100%; }
        .incoming { justify-content: flex-start; }
        .outgoing { justify-content: flex-end; }
        .message-bubble { max-width: 80%; padding: 15px 20px; border-radius: 16px; position: relative; font-size: 0.95rem; line-height: 1.6; }
        .incoming .message-bubble { background: rgba(255, 255, 255, 0.05); color: var(--text-main); border-bottom-left-radius: 2px; border: 1px solid rgba(255,255,255,0.05); }
        .outgoing .message-bubble { background: var(--gradient-main); color: white; border-bottom-right-radius: 2px; box-shadow: 0 4px 15px rgba(139, 92, 246, 0.3); }
        .message-sender { font-size: 0.75rem; font-weight: 700; margin-bottom: 5px; opacity: 0.9; text-transform: uppercase; letter-spacing: 0.5px; display: flex; align-items: center; gap: 5px; }
        .message-time { font-size: 0.7rem; text-align: right; margin-top: 5px; opacity: 0.7; }
        .incoming .message-time { color: rgba(255,255,255,0.5); }
        .outgoing .message-time { color: rgba(255,255,255,0.8); }
        .reply-box { padding: 20px; background: #1e293b; border-top: 1px solid rgba(255,255,255,0.05); flex-shrink: 0; }
        .quick-actions { display: flex; gap: 10px; margin-bottom: 15px; overflow-x: auto; padding-bottom: 5px; }
        .quick-actions span { background: rgba(255,255,255,0.05); padding: 6px 12px; border-radius: 8px; font-size: 0.8rem; cursor: pointer; border: 1px solid rgba(255,255,255,0.1); transition: 0.2s; white-space: nowrap; color: var(--text-muted); }
        .quick-actions span:hover { border-color: var(--primary); color: white; background: rgba(139, 92, 246, 0.1); }
        .form-control { width: 100%; background: #0f172a; border: 1px solid rgba(255,255,255,0.1); padding: 15px; border-radius: 12px; color: white; resize: none; font-family: 'Plus Jakarta Sans'; font-size: 0.95rem; }
        .form-control:focus { outline: none; border-color: var(--primary); }
        .action-buttons { display: flex; justify-content: space-between; align-items: center; margin-top: 15px; }
        .btn-primary { background: var(--gradient-main); color: white; padding: 10px 25px; border-radius: 10px; border: none; font-weight: 600; cursor: pointer; transition: 0.3s; display: flex; gap: 8px; align-items: center; }
        .btn-primary:hover { background: var(--primary-dark); }
        .btn-danger-soft { background: rgba(239, 68, 68, 0.15); color: var(--danger); padding: 10px 20px; border-radius: 10px; border: 1px solid rgba(239, 68, 68, 0.2); cursor: pointer; font-weight: 600; display: flex; gap: 8px; align-items: center; transition: 0.3s; }
        .btn-danger-soft:hover { background: rgba(239, 68, 68, 0.25); }
        .btn-text { background: none; border: none; color: #94a3b8; text-decoration: underline; cursor: pointer; font-size: 0.9rem; }
        .btn-text:hover { color: white; }
        .closed-notice { text-align: center; padding: 20px; background: rgba(239,68,68,0.1); color: var(--danger); border-radius: 12px; font-weight: 600; width: 100%; }
        .swal2-popup { background: #1e293b !important; border-radius: 24px !important; border: 1px solid rgba(139,92,246,0.3) !important; }
        .swal2-title { color: white !important; font-family: 'Outfit' !important; }
        .swal2-html-container { color: #cbd5e1 !important; }
        .swal2-confirm { background: var(--gradient-main) !important; border: none !important; border-radius: 12px !important; }
        .swal2-cancel { background: transparent !important; border: 1px solid rgba(239,68,68,0.3) !important; color: #EF4444 !important; border-radius: 12px !important; }
        @media (max-width: 992px) { .nav-menu { display: none; } .stats-grid { grid-template-columns: 1fr 1fr; } }
        @media (max-width: 768px) { .filters-grid { grid-template-columns: 1fr; } .filter-bar { flex-direction: column; } }
    </style>
</head>
<body>

    <div class="background-glow"><div class="glow-blob blob-1"></div><div class="glow-blob blob-2"></div></div>

    <nav class="navbar">
        <div class="container nav-inner">
            <a href="admin_dashboard.php" class="logo"><i class="fas fa-shield-alt"></i> Darq <span class="admin-badge">ADMIN</span></a>
            <div class="nav-menu">
                <a href="admin_dashboard.php">Dashboard</a>
                <a href="admin_orders.php">Siparişler</a>
                <a href="admin_payments.php">Ödemeler</a>
                <a href="admin_users.php">Kullanıcılar</a>
                <a href="admin_services.php">Servisler</a>
                <a href="admin_tickets.php" class="active">Destek</a>
                <a href="dashboard.php" style="color: #F59E0B;">Siteye Dön <i class="fas fa-external-link-alt"></i></a>
            </div>
            <div style="display:flex; gap:15px; align-items:center;">
                <span style="color:white; font-weight:600;"><?php echo htmlspecialchars($admin['username']); ?></span>
                <a href="logout.php" class="btn-sm btn-delete" style="padding: 6px 12px; font-size:0.8rem;">Çıkış</a>
            </div>
        </div>
    </nav>

    <div class="main-content container">
        <div class="stats-grid">
            <div class="stat-card"><div class="stat-number"><?php echo $stats['total']; ?></div><div class="stat-label">Toplam Talep</div></div>
            <div class="stat-card"><div class="stat-number text-warning"><?php echo $stats['open']; ?></div><div class="stat-label">Bekleyen</div></div>
            <div class="stat-card"><div class="stat-number text-success"><?php echo $stats['answered']; ?></div><div class="stat-label">Yanıtlanan</div></div>
            <div class="stat-card"><div class="stat-number text-muted"><?php echo $stats['closed']; ?></div><div class="stat-label">Kapanan</div></div>
        </div>

        <form class="filter-bar" method="GET">
            <input type="text" name="search" class="search-input" placeholder="Ticket ID, Konu veya Kullanıcı ara..." value="<?php echo htmlspecialchars($search_query); ?>">
            <select name="status" class="filter-select">
                <option value="all">Tüm Durumlar</option>
                <option value="open" <?php echo $status_filter == 'open' ? 'selected' : ''; ?>>Bekleyen</option>
                <option value="answered" <?php echo $status_filter == 'answered' ? 'selected' : ''; ?>>Yanıtlanan</option>
                <option value="closed" <?php echo $status_filter == 'closed' ? 'selected' : ''; ?>>Kapalı</option>
            </select>
            <button type="submit" class="btn-filter"><i class="fas fa-filter"></i> Filtrele</button>
        </form>

        <div class="table-container">
            <table class="custom-table">
                <thead><tr><th>ID</th><th>Kullanıcı</th><th>Konu</th><th>Kategori</th><th>Durum</th><th>Son Güncelleme</th><th>İşlem</th></tr></thead>
                <tbody>
                    <?php if (count($tickets) > 0): ?>
                        <?php foreach ($tickets as $t): ?>
                        <tr>
                            <td style="color: var(--primary); font-family: monospace;">#<?php echo htmlspecialchars($t['ticket_id']); ?></td>
                            <td><?php echo htmlspecialchars($t['username']); ?></td>
                            <td><?php echo htmlspecialchars($t['subject']); ?></td>
                            <td style="color: var(--text-muted); font-size: 0.85rem;"><?php echo htmlspecialchars($t['category'] ?? '-'); ?></td>
                            <td><span class="status-badge status-<?php echo $t['status']; ?>"><?php echo strtoupper($t['status']); ?></span></td>
                            <td style="color: var(--text-muted); font-size: 0.85rem;"><?php echo date('d.m H:i', strtotime($t['updated_at'])); ?></td>
                            <td>
                                <div style="display:flex; gap:10px;">
                                    <button type="button" class="btn-sm btn-view" onclick="openTicketModal('<?php echo $t['ticket_id']; ?>')"><i class="fas fa-eye"></i> Detay</button>
                                    <form method="POST" onsubmit="return confirm('Silmek istediğine emin misin?');" style="display:inline;">
                                        <input type="hidden" name="action" value="delete_ticket">
                                        <input type="hidden" name="ticket_id" value="<?php echo $t['id']; ?>">
                                        <button type="submit" class="btn-sm btn-delete"><i class="fas fa-trash"></i></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="7" style="text-align:center; padding:40px; color: var(--text-muted);">Kayıt bulunamadı.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div id="ticketModal" class="modal">
        <div class="modal-content">
            <div id="modalBody" style="display:flex; flex-direction:column; height:100%;">
                <div style="flex:1; display:flex; align-items:center; justify-content:center; color:white;"><i class="fas fa-spinner fa-spin fa-2x"></i></div>
            </div>
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

        function openTicketModal(ticketId) {
            const modal = document.getElementById('ticketModal');
            const body = document.getElementById('modalBody');
            
            modal.style.display = 'flex';
            body.innerHTML = '<div style="flex:1; display:flex; align-items:center; justify-content:center; color:white;"><i class="fas fa-spinner fa-spin fa-2x"></i></div>';
            
            const formData = new FormData();
            formData.append('action', 'get_ticket_details');
            formData.append('ticket_id', ticketId);

            fetch(window.location.href, { 
                method: 'POST', 
                body: formData 
            })
            .then(r => r.text())
            .then(html => {
                if(html.includes('<html') || html.includes('<!DOCTYPE')) {
                      body.innerHTML = '<div style="color:#EF4444; padding:20px; text-align:center;">Hata: İçerik yüklenemedi. Lütfen sayfayı yenileyip tekrar deneyin.</div>';
                      console.error("HATA: Sunucu tüm sayfayı döndürdü. PHP kodunun en üstündeki if bloğu çalışmıyor.");
                } else {
                    body.innerHTML = html;
                    const chatArea = document.getElementById('chatAreaScroll');
                    if(chatArea) chatArea.scrollTop = chatArea.scrollHeight;
                }
            })
            .catch(err => {
                console.error(err);
                body.innerHTML = '<div style="color:#EF4444; padding:20px; text-align:center;">Bağlantı hatası oluştu.</div>';
            });
        }

        function closeModal() {
            document.getElementById('ticketModal').style.display = 'none';
        }
        
        window.onclick = function(event) {
            const modal = document.getElementById('ticketModal');
            if (event.target == modal) {
                closeModal();
            }
        }

        function insertTemplate(text) {
            const input = document.getElementById('replyText');
            if(input) input.value = text;
        }

        function submitReply(e, ticketId) {
            e.preventDefault();
            const form = e.target;
            const btn = form.querySelector('button[type="submit"]');
            
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

            const formData = new FormData(form);

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            }).then(() => {
                openTicketModal(ticketId);
            });
        }

        function closeTicket(id) {
            Swal.fire({
                title: 'Talebi Kapat?',
                text: "Bu destek talebi kapatılacak.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#EF4444',
                cancelButtonColor: '#3B82F6',
                confirmButtonText: 'Evet, Kapat',
                cancelButtonText: 'İptal',
                background: '#1e293b',
                color: '#fff'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `<input type="hidden" name="action" value="close_ticket"><input type="hidden" name="ticket_id" value="${id}">`;
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }
        
        function reopenTicket(id) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `<input type="hidden" name="action" value="reopen_ticket"><input type="hidden" name="ticket_id" value="${id}">`;
            document.body.appendChild(form);
            form.submit();
        }
    </script>
</body>
</html>
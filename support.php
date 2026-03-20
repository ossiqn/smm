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

if (isset($_POST['action']) && $_POST['action'] == 'read_notifications') {
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $stmt->execute([$user['id']]);
    echo 'ok';
    exit;
}

$is_admin = isset($user['user_role']) && ($user['user_role'] == 'admin' || $user['user_role'] == 'super_admin');

if (isset($_POST['action']) && $_POST['action'] == 'get_ticket_details') {
    while (ob_get_level()) { ob_end_clean(); }
    
    $ticket_id = $_POST['ticket_id'];

    $stmt = $pdo->prepare("SELECT * FROM tickets WHERE ticket_id = ? AND user_id = ?");
    $stmt->execute([$ticket_id, $_SESSION['user_id']]);
    $ticket = $stmt->fetch();

    if (!$ticket) {
        echo '<div style="text-align:center; padding:40px; color:#EF4444;"><i class="fas fa-exclamation-triangle" style="font-size:2rem; margin-bottom:10px;"></i><br>Talep bulunamadı.</div>';
        exit;
    }

    $all_messages = [];

    $stmt = $pdo->prepare("SELECT message, created_at, is_admin FROM ticket_messages WHERE ticket_id = ?");
    $stmt->execute([$ticket_id]);
    $msgs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach($msgs as $m) {
        $all_messages[] = [
            'message' => $m['message'],
            'created_at' => $m['created_at'],
            'is_admin' => $m['is_admin']
        ];
    }

    array_unshift($all_messages, [
        'message' => $ticket['message'],
        'created_at' => $ticket['created_at'],
        'is_admin' => 0
    ]);

    try {
        $stmt = $pdo->prepare("SELECT message, created_at, is_admin FROM ticket_replies WHERE ticket_id = ?");
        $stmt->execute([$ticket['id']]); 
        $admin_msgs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach($admin_msgs as $m) {
            $all_messages[] = [
                'message' => $m['message'],
                'created_at' => $m['created_at'],
                'is_admin' => 1
            ];
        }
    } catch(PDOException $e) {}

    usort($all_messages, function($a, $b) {
        return strtotime($a['created_at']) - strtotime($b['created_at']);
    });

    ?>

    <div class="chat-layout">
        <div class="ticket-chat-header">
            <div style="display:flex; align-items:center; gap:15px;">
                <div class="header-icon"><i class="fas fa-ticket-alt"></i></div>
                <div>
                    <h3 class="header-title"><?php echo htmlspecialchars($ticket['subject']); ?></h3>
                    <span class="header-subtitle">#<?php echo htmlspecialchars($ticket['ticket_id']); ?></span>
                </div>
            </div>
            <span class="status-badge status-<?php echo $ticket['status']; ?>">
                <?php 
                $st = [
                    'open' => 'Açık', 
                    'in_progress' => 'İşleniyor', 
                    'answered' => 'Cevaplandı', 
                    'closed' => 'Kapalı', 
                    'resolved' => 'Çözüldü'
                ];
                echo $st[$ticket['status']] ?? $ticket['status']; 
                ?>
            </span>
        </div>

        <div class="ticket-conversation" id="ticketConversation">
            <?php foreach ($all_messages as $msg): ?>
                <div class="message <?php echo $msg['is_admin'] ? 'admin' : 'user'; ?>">
                    <div class="msg-content-wrapper">
                        <?php if($msg['is_admin']): ?>
                            <div class="msg-sender">Destek Ekibi</div>
                        <?php endif; ?>
                        <div class="msg-bubble">
                            <?php echo nl2br(htmlspecialchars($msg['message'])); ?>
                        </div>
                        <div class="msg-time">
                            <?php echo date('d.m H:i', strtotime($msg['created_at'])); ?>
                            <?php if(!$msg['is_admin']): ?><i class="fas fa-check-double"></i><?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($ticket['status'] !== 'closed' && $ticket['status'] !== 'resolved'): ?>
            <form id="replyFormModal" class="reply-area">
                <input type="hidden" name="reply_ticket" value="1">
                <input type="hidden" name="ticket_id" value="<?php echo $ticket['ticket_id']; ?>">
                
                <div class="reply-input-wrapper">
                    <textarea name="reply_message" class="chat-input" rows="1" required placeholder="Mesajınızı yazın..."></textarea>
                    <button type="submit" class="chat-send-btn">
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </div>
            </form>
        <?php else: ?>
            <div class="ticket-closed-notice">
                <i class="fas fa-lock"></i> Bu destek talebi kapatılmıştır.
            </div>
        <?php endif; ?>
    </div>

    <script>
        var chatDiv = document.getElementById("ticketConversation");
        if(chatDiv) { chatDiv.scrollTop = chatDiv.scrollHeight; }
    </script>

    <?php
    exit;
}

try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tickets' AND COLUMN_NAME = 'username'");
    $has_username = $stmt->fetchColumn();
    if (!$has_username) {
        $pdo->exec("ALTER TABLE tickets ADD COLUMN username VARCHAR(100) NOT NULL AFTER user_id");
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, title VARCHAR(255) NOT NULL, message TEXT NOT NULL, type ENUM('info', 'success', 'warning', 'danger') DEFAULT 'info', is_read BOOLEAN DEFAULT FALSE, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_user (user_id))");
} catch (PDOException $e) {}

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

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_ticket'])) {
        $subject = htmlspecialchars($_POST['subject']);
        $category = htmlspecialchars($_POST['category']);
        $priority = htmlspecialchars($_POST['priority']);
        $message = htmlspecialchars($_POST['message']);
        
        if (empty($subject) || empty($message)) {
            $error = "Lütfen konu ve mesaj alanlarını doldurun.";
        } else {
            $ticket_id = 'TKT' . strtoupper(uniqid());
            
            $stmt = $pdo->prepare("INSERT INTO tickets (ticket_id, user_id, username, subject, category, priority, message, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'open')");
            $stmt->execute([$ticket_id, $_SESSION['user_id'], $user['username'], $subject, $category, $priority, $message]);
            
            $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, created_at) VALUES (?, ?, ?, 'info', NOW())");
            $stmt->execute([$_SESSION['user_id'], 'Destek Talebi Oluşturuldu', "Talebiniz (#{$ticket_id}) alındı. En kısa sürede yanıtlanacaktır."]);

            $success = "Destek talebiniz başarıyla oluşturuldu.";
        }
    }
    
    if (isset($_POST['reply_ticket'])) {
        $ticket_id = htmlspecialchars($_POST['ticket_id']);
        $reply_message = htmlspecialchars($_POST['reply_message']);
        
        if (!empty($reply_message)) {
            $stmt = $pdo->prepare("INSERT INTO ticket_messages (ticket_id, user_id, message, is_admin) VALUES (?, ?, ?, FALSE)");
            $stmt->execute([$ticket_id, $_SESSION['user_id'], $reply_message]);
            
            $stmt = $pdo->prepare("UPDATE tickets SET status = 'open', updated_at = NOW() WHERE ticket_id = ?");
            $stmt->execute([$ticket_id]);
            
            $success = "Cevabınız gönderildi.";
        }
    }
    
    if (isset($_POST['send_live_message'])) {
        $message = htmlspecialchars($_POST['live_message']);
        
        if (!empty($message)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO live_support_messages (user_id, message, is_admin, created_at) VALUES (?, ?, 0, NOW())");
                $stmt->execute([$_SESSION['user_id'], $message]);
                
                $ai_replies = [
                    "Mesajınız alındı. Destek ekibimiz en kısa sürede dönüş yapacaktır.",
                    "Anlaşıldı, konuyu inceliyoruz.",
                    "Lütfen bekleyin, yetkili birime aktarıyorum.",
                    "Sorununuzla ilgili kayıt oluşturuldu.",
                    "Size nasıl yardımcı olabileceğimi kontrol ediyorum."
                ];
                $random_reply = $ai_replies[array_rand($ai_replies)];
                
                $stmt = $pdo->prepare("INSERT INTO live_support_messages (user_id, message, is_admin, created_at) VALUES (?, ?, 1, NOW())");
                $stmt->execute([$_SESSION['user_id'], $random_reply]);

                $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, created_at) VALUES (?, ?, ?, 'info', NOW())");
                $stmt->execute([$_SESSION['user_id'], 'Canlı Destek', 'Destek ekibinden yeni bir mesajınız var.']);
                
            } catch (PDOException $e) {}
            header("Location: support.php#liveChat");
            exit;
        }
    }
}

$tickets = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM tickets WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$_SESSION['user_id']]);
    $tickets = $stmt->fetchAll();
} catch (PDOException $e) {}

$stats = ['total' => 0, 'open' => 0, 'answered' => 0];
if (!empty($tickets)) {
    $stats['total'] = count($tickets);
    foreach ($tickets as $ticket) {
        if($ticket['status'] == 'open') $stats['open']++;
        if($ticket['status'] == 'answered') $stats['answered']++;
    }
}

$live_chat_messages = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM live_support_messages WHERE user_id = ? ORDER BY created_at ASC LIMIT 50");
    $stmt->execute([$_SESSION['user_id']]);
    $live_chat_messages = $stmt->fetchAll();
} catch (PDOException $e) {}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Destek - Darq SMM Panel</title>
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

        .notif-bell-container { position: relative; cursor: pointer; margin-right: 10px; }
        .notif-icon { font-size: 1.2rem; color: var(--text-muted); transition: 0.3s; }
        .notif-icon:hover { color: white; }
        .notif-badge { position: absolute; top: -5px; right: -5px; background: var(--danger); color: white; font-size: 0.6rem; padding: 2px 5px; border-radius: 50%; font-weight: 700; border: 1px solid var(--bg-body); }
        .notif-dropdown { position: absolute; top: 50px; right: 0; width: 320px; background: #1e293b; border: var(--glass-border); border-radius: 16px; box-shadow: 0 15px 50px rgba(0,0,0,0.6); display: none; flex-direction: column; z-index: 1001; overflow: hidden; animation: slideDown 0.3s ease; }
        .notif-dropdown.active { display: flex; }
        .notif-header { padding: 15px; border-bottom: 1px solid rgba(255,255,255,0.05); font-weight: 600; color: white; background: rgba(0,0,0,0.2); }
        .notif-body { max-height: 350px; overflow-y: auto; }
        .notif-item { padding: 15px; border-bottom: 1px solid rgba(255,255,255,0.05); transition: 0.2s; display: block; text-decoration: none; }
        .notif-item:hover { background: rgba(255,255,255,0.03); }
        .notif-item.unread { background: rgba(139, 92, 246, 0.05); border-left: 3px solid var(--primary); }
        .notif-title { font-size: 0.9rem; font-weight: 600; color: white; margin-bottom: 4px; }
        .notif-desc { font-size: 0.8rem; color: var(--text-muted); line-height: 1.4; }
        .notif-date { font-size: 0.7rem; color: var(--text-muted); margin-top: 6px; text-align: right; opacity: 0.7; }
        .notif-empty { padding: 20px; text-align: center; color: var(--text-muted); font-size: 0.9rem; }

        .main-content { padding: 100px 0 40px; }
        .dashboard-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 20px; }
        .dashboard-header h1 { font-family: 'Outfit', sans-serif; font-size: 2rem; font-weight: 700; }
        .gradient-text { background: var(--gradient-text); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 40px; }
        .stat-card { background: var(--bg-card); backdrop-filter: blur(15px); border: var(--glass-border); border-radius: 20px; padding: 25px; transition: 0.3s; position: relative; overflow: hidden; }
        .stat-card:hover { transform: translateY(-5px); border-color: var(--primary); box-shadow: var(--glow); }
        .stat-card::before { content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 4px; background: var(--gradient-main); }
        .stat-value { font-family: 'Outfit', sans-serif; font-size: 2rem; font-weight: 700; color: white; line-height: 1; margin-bottom: 5px; }
        .stat-label { color: var(--text-muted); font-size: 0.9rem; }
        .stat-icon { position: absolute; right: 20px; top: 20px; width: 50px; height: 50px; background: rgba(139, 92, 246, 0.1); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: var(--primary); font-size: 1.5rem; }

        .support-grid { display: grid; grid-template-columns: 1fr 1.2fr; gap: 30px; margin-bottom: 40px; }
        .content-card { background: var(--bg-card); backdrop-filter: blur(15px); border: var(--glass-border); border-radius: 24px; padding: 30px; }
        .card-title { font-family: 'Outfit', sans-serif; font-size: 1.3rem; color: white; font-weight: 600; margin-bottom: 25px; display: flex; align-items: center; gap: 10px; }
        .card-title i { color: var(--primary); }

        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 500; color: var(--text-muted); font-size: 0.9rem; }
        .modern-input { width: 100%; padding: 14px 15px; background: rgba(2, 6, 23, 0.5); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 14px; color: white; font-size: 0.95rem; transition: 0.3s; font-family: 'Plus Jakarta Sans'; }
        .modern-input:focus { outline: none; border-color: var(--primary); background: rgba(139, 92, 246, 0.05); }
        textarea.modern-input { resize: none; }

        .priority-options { display: flex; gap: 10px; }
        .priority-option { flex: 1; padding: 12px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.05); border-radius: 12px; text-align: center; cursor: pointer; transition: 0.3s; font-weight: 500; font-size: 0.9rem; color: var(--text-muted); }
        .priority-option:hover { border-color: var(--primary); color: white; }
        .priority-option.active { background: var(--primary); border-color: var(--primary); color: white; box-shadow: var(--glow); }

        .btn { padding: 14px 25px; border-radius: 14px; font-weight: 600; text-decoration: none; transition: 0.3s; display: inline-flex; align-items: center; justify-content: center; gap: 8px; border: none; cursor: pointer; font-size: 1rem; width: 100%; }
        .btn-primary { background: var(--gradient-main); color: white; box-shadow: var(--glow); }
        .btn-primary:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(139, 92, 246, 0.5); }
        .btn-outline { background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.15); color: white; }
        .btn-outline:hover { background: rgba(255,255,255,0.1); border-color: white; transform: translateY(-3px); }

        .ticket-list { display: flex; flex-direction: column; gap: 15px; max-height: 600px; overflow-y: auto; padding-right: 5px; }
        .ticket-item { background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.05); border-radius: 16px; padding: 20px; cursor: pointer; transition: 0.3s; position: relative; overflow: hidden; }
        .ticket-item:hover { background: rgba(255,255,255,0.05); border-color: var(--primary); transform: translateX(5px); }
        
        .ticket-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px; }
        .ticket-subject { font-weight: 600; color: white; font-size: 1.05rem; }
        .ticket-meta { display: flex; justify-content: space-between; align-items: center; font-size: 0.85rem; color: var(--text-muted); }
        .ticket-id { font-family: monospace; background: rgba(255,255,255,0.05); padding: 2px 6px; border-radius: 6px; color: var(--primary); }
        
        .status-badge { padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
        .status-open { background: rgba(59, 130, 246, 0.15); color: #3B82F6; }
        .status-answered { background: rgba(16, 185, 129, 0.15); color: #10B981; }
        .status-closed { background: rgba(148, 163, 184, 0.15); color: #94A3B8; }

        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.85); backdrop-filter: blur(10px); z-index: 2000; align-items: center; justify-content: center; }
        .modal-content { background: #0f172a; width: 95%; max-width: 800px; height: 85vh; border-radius: 24px; border: var(--glass-border); display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.7); animation: zoomIn 0.3s ease; position: relative; }
        @keyframes zoomIn { from { transform: scale(0.95); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        
        .close-modal { position: absolute; top: 20px; right: 20px; background: rgba(255,255,255,0.05); border: none; width: 40px; height: 40px; border-radius: 12px; color: white; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: 0.3s; z-index: 10; }
        .close-modal:hover { background: #EF4444; }

        .chat-layout { display: flex; flex-direction: column; height: 100%; }
        .ticket-chat-header { padding: 25px; border-bottom: 1px solid rgba(255,255,255,0.05); background: rgba(15, 23, 42, 0.8); display: flex; align-items: center; justify-content: space-between; }
        .header-icon { width: 45px; height: 45px; background: var(--gradient-main); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; color: white; }
        .header-title { font-family: 'Outfit'; font-size: 1.2rem; font-weight: 700; color: white; margin: 0; line-height: 1.2; }
        .header-subtitle { color: var(--text-muted); font-size: 0.85rem; }

        .ticket-conversation { flex: 1; padding: 25px; overflow-y: auto; display: flex; flex-direction: column; gap: 20px; background-image: radial-gradient(rgba(139, 92, 246, 0.05) 1px, transparent 1px); background-size: 30px 30px; }
        
        .message { display: flex; max-width: 80%; }
        .message.user { align-self: flex-end; justify-content: flex-end; }
        .message.admin { align-self: flex-start; }
        
        .msg-content-wrapper { display: flex; flex-direction: column; gap: 5px; }
        .message.user .msg-content-wrapper { align-items: flex-end; }
        
        .msg-sender { font-size: 0.75rem; color: var(--primary); font-weight: 600; margin-left: 5px; }
        
        .msg-bubble { padding: 15px 20px; border-radius: 18px; font-size: 0.95rem; line-height: 1.6; position: relative; }
        .message.user .msg-bubble { background: var(--gradient-main); color: white; border-bottom-right-radius: 4px; box-shadow: 0 5px 15px rgba(139, 92, 246, 0.3); }
        .message.admin .msg-bubble { background: rgba(30, 41, 59, 0.9); border: 1px solid rgba(255,255,255,0.1); color: var(--text-main); border-bottom-left-radius: 4px; }
        
        .msg-time { font-size: 0.7rem; color: var(--text-muted); margin-top: 2px; display: flex; align-items: center; gap: 4px; opacity: 0.8; }

        .reply-area { padding: 20px; background: rgba(15, 23, 42, 0.9); border-top: 1px solid rgba(255,255,255,0.05); }
        .reply-input-wrapper { background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); border-radius: 16px; padding: 8px 8px 8px 20px; display: flex; align-items: center; gap: 10px; transition: 0.3s; }
        .reply-input-wrapper:focus-within { border-color: var(--primary); background: rgba(255,255,255,0.08); }
        
        .chat-input { flex: 1; background: transparent; border: none; color: white; font-family: 'Plus Jakarta Sans'; font-size: 0.95rem; resize: none; max-height: 100px; padding: 10px 0; outline: none; }
        .chat-send-btn { width: 45px; height: 45px; background: var(--primary); border: none; border-radius: 12px; color: white; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; transition: 0.3s; }
        .chat-send-btn:hover { background: var(--primary-dark); transform: scale(1.05); }

        .ticket-closed-notice { text-align: center; padding: 20px; color: var(--text-muted); font-size: 0.9rem; background: rgba(255,255,255,0.02); border-top: 1px solid rgba(255,255,255,0.05); }

        /* Live Chat Floating */
        .live-chat-btn { position: fixed; bottom: 30px; right: 30px; width: 60px; height: 60px; background: var(--gradient-main); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-size: 1.5rem; cursor: pointer; box-shadow: 0 10px 30px rgba(139, 92, 246, 0.4); z-index: 1000; transition: 0.3s; border: none; animation: float 3s ease-in-out infinite; }
        .live-chat-btn:hover { transform: scale(1.1); }
        
        .live-chat-modal { display: none; position: fixed; bottom: 100px; right: 30px; width: 360px; height: 500px; background: #0f172a; border-radius: 24px; border: 1px solid rgba(255,255,255,0.1); z-index: 1000; flex-direction: column; overflow: hidden; box-shadow: 0 20px 50px rgba(0,0,0,0.5); animation: slideUp 0.3s ease; }
        @keyframes slideUp { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        
        .live-chat-header { background: var(--gradient-main); padding: 20px; color: white; font-weight: 600; display: flex; justify-content: space-between; align-items: center; }
        .live-chat-body { flex: 1; padding: 20px; overflow-y: auto; background: rgba(0,0,0,0.2); display: flex; flex-direction: column; gap: 10px; }
        .live-chat-footer { padding: 15px; border-top: 1px solid rgba(255,255,255,0.1); background: rgba(30,41,59,0.5); display: flex; gap: 10px; }
        
        .live-msg { padding: 10px 15px; border-radius: 12px; font-size: 0.9rem; max-width: 85%; }
        .live-msg.ai { background: rgba(255,255,255,0.1); color: white; align-self: flex-start; border-bottom-left-radius: 2px; }
        .live-msg.user { background: var(--primary); color: white; align-self: flex-end; border-bottom-right-radius: 2px; }

        .footer { padding: 40px 0; border-top: var(--glass-border); margin-top: 50px; text-align: center; color: var(--text-muted); font-size: 0.9rem; }

        @media (max-width: 992px) {
            .nav-menu { display: none; position: fixed; top: 70px; left: 0; width: 100%; background: rgba(2,6,23,0.98); flex-direction: column; padding: 20px; height: calc(100vh - 70px); align-items: flex-start; }
            .nav-menu a { width: 100%; padding: 15px; }
            .nav-menu.active { display: flex; }
            .menu-toggle { display: block; }
            .support-grid { grid-template-columns: 1fr; }
            .modal-content { width: 100%; height: 100%; border-radius: 0; border: none; }
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
                <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                <a href="services.php"><i class="fas fa-box"></i> Hizmetler</a>
                <a href="orders.php"><i class="fas fa-history"></i> Siparişler</a>
                <a href="balance.php"><i class="fas fa-wallet"></i> Bakiye</a>
                <a href="support.php" class="active"><i class="fas fa-headset"></i> Destek</a>
                <?php if ($is_admin): ?>
                <a href="admin_dashboard.php"><i class="fas fa-user-shield"></i> Admin Panel</a>
                <?php endif; ?>
            </div>
            
            <div class="user-menu">
                <div class="notif-bell-container" onclick="toggleNotifications()">
                    <i class="fas fa-bell notif-icon"></i>
                    <?php if($unread_notif_count > 0): ?>
                        <span class="notif-badge" id="notifBadge"><?php echo $unread_notif_count; ?></span>
                    <?php endif; ?>
                    
                    <div class="notif-dropdown" id="notifDropdown">
                        <div class="notif-header">Son Bildirimler</div>
                        <div class="notif-body">
                            <?php if(empty($notifications)): ?>
                                <div class="notif-empty">Henüz bildirim yok.</div>
                            <?php else: ?>
                                <?php foreach($notifications as $notif): ?>
                                    <a href="javascript:void(0)" class="notif-item <?php echo $notif['is_read'] ? '' : 'unread'; ?>">
                                        <div class="notif-title"><?php echo htmlspecialchars($notif['title']); ?></div>
                                        <div class="notif-desc"><?php echo htmlspecialchars($notif['message']); ?></div>
                                        <div class="notif-date"><?php echo date('d.m H:i', strtotime($notif['created_at'])); ?></div>
                                    </a>
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
                <h1>Destek <span class="gradient-text">Merkezi</span></h1>
                <p style="color: var(--text-muted);">Sorularınız mı var? Ekibimiz 7/24 yanınızda.</p>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-ticket-alt"></i></div>
                <div class="stat-value"><?php echo $stats['total']; ?></div>
                <div class="stat-label">Toplam Talep</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(245, 158, 11, 0.1); color: #F59E0B;"><i class="fas fa-clock"></i></div>
                <div class="stat-value"><?php echo $stats['open']; ?></div>
                <div class="stat-label">Açık Talepler</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(16, 185, 129, 0.1); color: #10B981;"><i class="fas fa-check-circle"></i></div>
                <div class="stat-value"><?php echo $stats['answered']; ?></div>
                <div class="stat-label">Yanıtlananlar</div>
            </div>
        </div>

        <div class="support-grid">
            <div class="content-card">
                <div class="card-title"><i class="fas fa-plus-circle"></i> Yeni Talep Oluştur</div>
                
                <?php if ($success): ?>
                    <script>document.addEventListener('DOMContentLoaded', () => Swal.fire({icon: 'success', title: 'Başarılı!', text: '<?php echo $success; ?>'}));</script>
                <?php endif; ?>
                <?php if ($error): ?>
                    <script>document.addEventListener('DOMContentLoaded', () => Swal.fire({icon: 'error', title: 'Hata!', text: '<?php echo $error; ?>'}));</script>
                <?php endif; ?>

                <form method="POST">
                    <div class="form-group">
                        <label>Konu</label>
                        <input type="text" name="subject" class="modern-input" placeholder="Örn: Sipariş gecikmesi" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Kategori</label>
                        <select name="category" class="modern-input" style="appearance: none;">
                            <option value="sipariş">Sipariş Sorunu</option>
                            <option value="ödeme">Ödeme Bildirimi</option>
                            <option value="api">API Sorunu</option>
                            <option value="diğer">Diğer</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Öncelik</label>
                        <div class="priority-options">
                            <div class="priority-option active" onclick="setPriority('low', this)">Düşük</div>
                            <div class="priority-option" onclick="setPriority('medium', this)">Orta</div>
                            <div class="priority-option" onclick="setPriority('high', this)">Yüksek</div>
                        </div>
                        <input type="hidden" name="priority" id="priorityInput" value="low">
                    </div>
                    
                    <div class="form-group">
                        <label>Mesajınız</label>
                        <textarea name="message" class="modern-input" rows="5" placeholder="Detaylı açıklama..." required></textarea>
                    </div>
                    
                    <button type="submit" name="create_ticket" class="btn btn-primary">
                        <i class="fas fa-paper-plane"></i> Talebi Gönder
                    </button>
                </form>
            </div>

            <div class="content-card" style="height: fit-content;">
                <div class="card-title"><i class="fas fa-history"></i> Geçmiş Talepler</div>
                <div class="ticket-list">
                    <?php if (!empty($tickets)): ?>
                        <?php foreach ($tickets as $ticket): ?>
                            <div class="ticket-item" onclick="showTicketDetails('<?php echo $ticket['ticket_id']; ?>')">
                                <div class="ticket-top">
                                    <div class="ticket-subject"><?php echo htmlspecialchars($ticket['subject']); ?></div>
                                    <span class="status-badge status-<?php echo $ticket['status']; ?>">
                                        <?php 
                                        $st = ['open'=>'Açık', 'in_progress'=>'İşleniyor', 'answered'=>'Yanıtlandı', 'closed'=>'Kapalı', 'resolved'=>'Çözüldü'];
                                        echo $st[$ticket['status']] ?? $ticket['status'];
                                        ?>
                                    </span>
                                </div>
                                <div class="ticket-meta">
                                    <span class="ticket-id">#<?php echo $ticket['ticket_id']; ?></span>
                                    <span><?php echo date('d.m.Y', strtotime($ticket['created_at'])); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="text-align: center; padding: 40px; color: var(--text-muted);">
                            <i class="fas fa-folder-open" style="font-size: 3rem; opacity: 0.3; margin-bottom: 15px;"></i>
                            <p>Henüz destek talebiniz yok.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <button class="live-chat-btn" onclick="toggleLiveChat()">
        <i class="fas fa-comment-dots"></i>
    </button>
    
    <div class="live-chat-modal" id="liveChatModal">
        <div class="live-chat-header">
            <div><i class="fas fa-headset"></i> Canlı Destek</div>
            <div onclick="toggleLiveChat()" style="cursor: pointer;"><i class="fas fa-times"></i></div>
        </div>
        <div class="live-chat-body" id="liveChatBody">
            <div class="live-msg ai">Merhaba! Size nasıl yardımcı olabilirim?</div>
            <?php foreach($live_chat_messages as $msg): ?>
                <div class="live-msg <?php echo $msg['is_admin'] ? 'ai' : 'user'; ?>">
                    <?php echo htmlspecialchars($msg['message']); ?>
                </div>
            <?php endforeach; ?>
        </div>
        <form class="live-chat-footer" method="POST">
            <input type="text" name="live_message" class="modern-input" placeholder="Mesaj yazın..." style="padding: 10px; font-size: 0.9rem;" required>
            <button type="submit" name="send_live_message" class="btn btn-primary" style="width: 45px; height: 45px; padding: 0; border-radius: 12px;"><i class="fas fa-paper-plane"></i></button>
        </form>
    </div>

    <div class="modal" id="ticketModal">
        <div class="modal-content">
            <button class="close-modal" onclick="closeModal()"><i class="fas fa-times"></i></button>
            <div id="modalContent" style="height: 100%;">
                <div style="display:flex; align-items:center; justify-content:center; height:100%; color: var(--primary);">
                    <i class="fas fa-circle-notch fa-spin fa-3x"></i>
                </div>
            </div>
        </div>
    </div>

    <footer class="footer">
        <div class="container">
            <p>&copy; 2026 Darq SMM Panel. Tüm hakları saklıdır.</p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        const menuToggle = document.getElementById('menuToggle');
        const navMenu = document.getElementById('navMenu');
        menuToggle.addEventListener('click', () => navMenu.classList.toggle('active'));

        window.addEventListener('scroll', () => {
            const nav = document.getElementById('navbar');
            if(window.scrollY > 50) nav.classList.add('scrolled');
            else nav.classList.remove('scrolled');
        });

        function toggleNotifications() {
            const dd = document.getElementById('notifDropdown');
            dd.classList.toggle('active');
            
            if(dd.classList.contains('active')) {
                const badge = document.getElementById('notifBadge');
                if(badge) {
                    fetch('support.php', {
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
            const wrapper = document.querySelector('.notif-bell-container');
            const dd = document.getElementById('notifDropdown');
            if (wrapper && !wrapper.contains(e.target)) {
                dd.classList.remove('active');
            }
        });

        function markAllRead(e) {
            e.stopPropagation();
            toggleNotifications();
        }

        function setPriority(p, el) {
            document.querySelectorAll('.priority-option').forEach(b => b.classList.remove('active'));
            el.classList.add('active');
            document.getElementById('priorityInput').value = p;
        }

        function showTicketDetails(id) {
            const modal = document.getElementById('ticketModal');
            const content = document.getElementById('modalContent');
            modal.style.display = 'flex';
            
            let formData = new FormData();
            formData.append('action', 'get_ticket_details');
            formData.append('ticket_id', id);

            fetch(window.location.href, { method: 'POST', body: formData })
            .then(r => r.text())
            .then(html => { 
                content.innerHTML = html; 
                const form = document.getElementById('replyFormModal');
                
                const chatDiv = document.getElementById("ticketConversation");
                if(chatDiv) { chatDiv.scrollTop = chatDiv.scrollHeight; }

                if(form){
                    form.addEventListener('submit', function(e){
                        e.preventDefault();
                        let replyData = new FormData(this);
                        const btn = this.querySelector('button');
                        btn.disabled = true;
                        btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i>';

                        fetch(window.location.href, { method: 'POST', body: replyData })
                        .then(() => showTicketDetails(id));
                    });
                }
            });
        }

        function closeModal() { document.getElementById('ticketModal').style.display = 'none'; }
        
        function toggleLiveChat() {
            const chat = document.getElementById('liveChatModal');
            chat.style.display = chat.style.display === 'flex' ? 'none' : 'flex';
            if(chat.style.display === 'flex') {
                const body = document.getElementById('liveChatBody');
                body.scrollTop = body.scrollHeight;
            }
        }
    </script>
</body>
</html>
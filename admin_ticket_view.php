<?php
require_once 'config.php';

// Admin kontrolü
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: dashboard.php');
    exit;
}

$admin_id = $_SESSION['user_id'];
$ticket_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($ticket_id <= 0) {
    header('Location: admin_tickets.php');
    exit;
}

// Ticket bilgilerini al
$stmt = $pdo->prepare("SELECT t.*, u.username, u.email FROM tickets t JOIN users u ON t.user_id = u.id WHERE t.id = ?");
$stmt->execute([$ticket_id]);
$ticket = $stmt->fetch();

if (!$ticket) {
    header('Location: admin_tickets.php');
    exit;
}

// Ticket cevaplarını al
$stmt = $pdo->prepare("SELECT tr.*, u.username FROM ticket_replies tr LEFT JOIN users u ON tr.user_id = u.id WHERE tr.ticket_id = ? ORDER BY tr.created_at ASC");
$stmt->execute([$ticket_id]);
$replies = $stmt->fetchAll();

$success = '';
$error = '';

// Cevap gönderme işlemi
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['reply_ticket'])) {
        $message = trim($_POST['message']);
        
        if (!empty($message)) {
            try {
                $pdo->beginTransaction();
                
                // Cevabı kaydet
                $stmt = $pdo->prepare("INSERT INTO ticket_replies (ticket_id, user_id, message, is_admin, created_at) VALUES (?, ?, ?, 1, NOW())");
                $stmt->execute([$ticket_id, $admin_id, $message]);
                
                // Ticket durumunu güncelle
                $new_status = 'answered';
                $stmt = $pdo->prepare("UPDATE tickets SET status = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$new_status, $ticket_id]);
                
                $pdo->commit();
                
                // Sayfayı yenile
                header("Location: admin_ticket_view.php?id=$ticket_id&success=1");
                exit;
                
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = "Cevap gönderilirken hata oluştu: " . $e->getMessage();
            }
        } else {
            $error = "Lütfen mesajınızı yazın.";
        }
    }
    
    // Ticket kapatma
    if (isset($_POST['close_ticket'])) {
        try {
            $stmt = $pdo->prepare("UPDATE tickets SET status = 'closed', closed_at = NOW(), updated_at = NOW() WHERE id = ?");
            $stmt->execute([$ticket_id]);
            
            $_SESSION['success'] = "Destek talebi başarıyla kapatıldı.";
            header("Location: admin_tickets.php");
            exit;
            
        } catch (PDOException $e) {
            $error = "Destek talebi kapatılırken hata oluştu: " . $e->getMessage();
        }
    }
    
    // Ticket silme
    if (isset($_POST['delete_ticket'])) {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("DELETE FROM ticket_replies WHERE ticket_id = ?");
            $stmt->execute([$ticket_id]);
            
            $stmt = $pdo->prepare("DELETE FROM tickets WHERE id = ?");
            $stmt->execute([$ticket_id]);
            
            $pdo->commit();
            
            $_SESSION['success'] = "Destek talebi başarıyla silindi.";
            header("Location: admin_tickets.php");
            exit;
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = "Destek talebi silinirken hata oluştu: " . $e->getMessage();
        }
    }
    
    // Durum güncelleme
    if (isset($_POST['update_status'])) {
        $new_status = $_POST['status'];
        
        try {
            $stmt = $pdo->prepare("UPDATE tickets SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$new_status, $ticket_id]);
            
            header("Location: admin_ticket_view.php?id=$ticket_id&status_updated=1");
            exit;
            
        } catch (PDOException $e) {
            $error = "Durum güncellenirken hata oluştu: " . $e->getMessage();
        }
    }
    
    // Öncelik güncelleme
    if (isset($_POST['update_priority'])) {
        $new_priority = $_POST['priority'];
        
        try {
            $stmt = $pdo->prepare("UPDATE tickets SET priority = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$new_priority, $ticket_id]);
            
            header("Location: admin_ticket_view.php?id=$ticket_id&priority_updated=1");
            exit;
            
        } catch (PDOException $e) {
            $error = "Öncelik güncellenirken hata oluştu: " . $e->getMessage();
        }
    }
}

// URL'den gelen başarı mesajlarını kontrol et
if (isset($_GET['success'])) {
    $success = "Cevabınız başarıyla gönderildi!";
}
if (isset($_GET['status_updated'])) {
    $success = "Durum başarıyla güncellendi!";
}
if (isset($_GET['priority_updated'])) {
    $success = "Öncelik başarıyla güncellendi!";
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket #<?php echo $ticket_id; ?> - Darq SMM Panel</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #7c3aed;
            --secondary: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --bg-dark: #0f172a;
            --bg-card: #1e293b;
            --text-light: #f8fafc;
            --text-gray: #94a3b8;
            --radius: 12px;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg-dark);
            color: var(--text-light);
            line-height: 1.6;
        }
        
        .navbar {
            background: rgba(30, 41, 59, 0.95);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            position: fixed;
            top: 0;
            width: 100%;
            z-index: 1000;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 15px;
        }
        
        .navbar .container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
        }
        
        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            font-size: 1.2rem;
            font-weight: 700;
        }
        
        .logo-icon {
            width: 35px;
            height: 35px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
        }
        
        .logo-text {
            font-weight: 700;
        }
        
        .gradient-text {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        
        .menu-toggle {
            display: none;
            background: none;
            border: none;
            color: var(--text-light);
            font-size: 1.5rem;
            cursor: pointer;
            padding: 5px;
            border-radius: 5px;
        }
        
        .nav-menu {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .nav-menu a {
            color: var(--text-gray);
            text-decoration: none;
            padding: 8px 12px;
            border-radius: 8px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
        }
        
        .nav-menu a:hover {
            color: var(--text-light);
            background: rgba(255, 255, 255, 0.1);
        }
        
        .user-menu {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .user-avatar {
            width: 35px;
            height: 35px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
        }
        
        .main-content {
            padding: 100px 15px 30px;
        }
        
        .page-header {
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .page-header h1 {
            font-size: 1.8rem;
        }
        
        .btn {
            padding: 10px 20px;
            border-radius: var(--radius);
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn-secondary {
            background: rgba(255, 255, 255, 0.1);
            color: var(--text-light);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .btn-success {
            background: linear-gradient(135deg, var(--secondary), #059669);
            color: white;
        }
        
        .btn-danger {
            background: linear-gradient(135deg, var(--danger), #dc2626);
            color: white;
        }
        
        .ticket-container {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .ticket-content {
            background: var(--bg-card);
            border-radius: var(--radius);
            padding: 20px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .ticket-sidebar {
            background: var(--bg-card);
            border-radius: var(--radius);
            padding: 20px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .ticket-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .meta-item {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .meta-label {
            color: var(--text-gray);
            font-size: 0.85rem;
        }
        
        .meta-value {
            font-weight: 600;
        }
        
        .ticket-message {
            margin-bottom: 30px;
        }
        
        .ticket-message h3 {
            font-size: 1.2rem;
            margin-bottom: 10px;
            color: var(--text-light);
        }
        
        .message-content {
            background: rgba(255, 255, 255, 0.05);
            border-radius: var(--radius);
            padding: 15px;
            line-height: 1.7;
            white-space: pre-wrap;
        }
        
        .conversation {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .conversation h3 {
            font-size: 1.2rem;
            margin-bottom: 15px;
            color: var(--text-light);
        }
        
        .conversation-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
            max-height: 400px;
            overflow-y: auto;
            margin-bottom: 20px;
        }
        
        .reply-item {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .reply-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 5px;
        }
        
        .reply-user {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .user-avatar-small {
            width: 32px;
            height: 32px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        .user-name {
            font-weight: 600;
            font-size: 0.95rem;
        }
        
        .reply-time {
            font-size: 0.8rem;
            color: var(--text-gray);
        }
        
        .reply-content {
            background: rgba(255, 255, 255, 0.05);
            border-radius: var(--radius);
            padding: 12px;
            line-height: 1.6;
            white-space: pre-wrap;
            border-left: 4px solid var(--primary);
        }
        
        .reply-item.user .reply-content {
            border-left-color: var(--text-gray);
        }
        
        .reply-form {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .reply-form h3 {
            font-size: 1.2rem;
            margin-bottom: 15px;
            color: var(--text-light);
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        textarea.form-control {
            width: 100%;
            padding: 12px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: var(--radius);
            color: var(--text-light);
            font-family: inherit;
            resize: vertical;
            min-height: 120px;
        }
        
        textarea.form-control:focus {
            outline: none;
            border-color: var(--primary);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary), #6d28d9);
            color: white;
            padding: 12px 25px;
            font-weight: 600;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(124, 58, 237, 0.3);
        }
        
        .sidebar-section {
            margin-bottom: 25px;
            padding-bottom: 25px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .sidebar-section:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }
        
        .sidebar-section h3 {
            font-size: 1.1rem;
            margin-bottom: 15px;
            color: var(--text-light);
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 15px;
        }
        
        .user-avatar-medium {
            width: 45px;
            height: 45px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 1rem;
        }
        
        .status-controls, .priority-controls {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 8px;
        }
        
        .status-option, .priority-option {
            padding: 10px;
            border-radius: var(--radius);
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: var(--text-light);
            cursor: pointer;
            text-align: center;
            font-size: 0.85rem;
            transition: all 0.3s ease;
        }
        
        .status-option:hover, .priority-option:hover {
            background: rgba(255, 255, 255, 0.1);
        }
        
        .status-option.active, .priority-option.active {
            border-color: transparent;
        }
        
        .status-option.open.active {
            background: rgba(59, 130, 246, 0.1);
            color: #3b82f6;
        }
        
        .status-option.answered.active {
            background: rgba(16, 185, 129, 0.1);
            color: var(--secondary);
        }
        
        .status-option.customer_reply.active {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }
        
        .status-option.closed.active {
            background: rgba(148, 163, 184, 0.1);
            color: var(--text-gray);
        }
        
        .priority-option.low.active {
            background: rgba(16, 185, 129, 0.1);
            color: var(--secondary);
        }
        
        .priority-option.medium.active {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }
        
        .priority-option.high.active {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }
        
        .priority-option.urgent.active {
            background: rgba(124, 58, 237, 0.1);
            color: var(--primary);
        }
        
        .alert {
            padding: 15px;
            border-radius: var(--radius);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.2);
            color: var(--secondary);
        }
        
        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: var(--danger);
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            z-index: 10000;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        
        .modal-content {
            background: var(--bg-card);
            border-radius: var(--radius);
            padding: 25px;
            max-width: 500px;
            width: 100%;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .modal-title {
            font-size: 1.3rem;
            margin-bottom: 15px;
            color: var(--text-light);
        }
        
        .modal-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
            justify-content: flex-end;
        }
        
        /* Mobil için responsive tasarım */
        @media (max-width: 768px) {
            .navbar .container {
                padding: 10px;
            }
            
            .menu-toggle {
                display: block;
            }
            
            .nav-menu {
                position: fixed;
                top: 60px;
                left: 0;
                width: 100%;
                background: var(--bg-card);
                flex-direction: column;
                padding: 15px;
                display: none;
                border-top: 1px solid rgba(255, 255, 255, 0.1);
                z-index: 999;
            }
            
            .nav-menu.active {
                display: flex;
            }
            
            .ticket-container {
                grid-template-columns: 1fr;
            }
            
            .ticket-meta {
                grid-template-columns: 1fr;
            }
            
            .conversation-list {
                max-height: 300px;
            }
            
            .status-controls, .priority-controls {
                grid-template-columns: 1fr;
            }
            
            .page-header {
                flex-direction: column;
                align-items: stretch;
            }
            
            .page-actions {
                display: flex;
                gap: 10px;
            }
        }
        
        @media (max-width: 480px) {
            .main-content {
                padding: 80px 10px 20px;
            }
            
            .ticket-content, .ticket-sidebar {
                padding: 15px;
            }
            
            .btn {
                padding: 8px 15px;
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="container">
            <button class="menu-toggle" id="menuToggle">
                <i class="fas fa-bars"></i>
            </button>
            
            <a href="admin_dashboard.php" class="logo">
                <div class="logo-icon">
                    <i class="fas fa-shield-alt"></i>
                </div>
                <div class="logo-text">
                    <span class="gradient-text">Darq</span>
                    <span style="font-size: 0.85rem; color: var(--primary); margin-left: 5px;">Admin</span>
                </div>
            </a>

            <div class="nav-menu" id="navMenu">
                <a href="admin_dashboard.php">
                    <i class="fas fa-home"></i> Dashboard
                </a>
                <a href="admin_tickets.php">
                    <i class="fas fa-ticket-alt"></i> Destek
                </a>
                <a href="admin_users.php">
                    <i class="fas fa-users"></i> Kullanıcılar
                </a>
                <a href="admin_orders.php">
                    <i class="fas fa-shopping-cart"></i> Siparişler
                </a>
            </div>

            <div class="user-menu">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($_SESSION['username'], 0, 1)); ?>
                </div>
                <div style="display: flex; flex-direction: column;">
                    <div style="font-weight: 600; font-size: 0.9rem;"><?php echo htmlspecialchars($_SESSION['username']); ?></div>
                    <div style="font-size: 0.8rem; color: var(--text-gray);">ADMIN</div>
                </div>
            </div>
        </div>
    </nav>

    <div class="main-content container">
        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo $success; ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <div class="page-header">
            <h1>Ticket #<?php echo $ticket_id; ?> - <?php echo htmlspecialchars($ticket['subject']); ?></h1>
            <div class="page-actions">
                <a href="admin_tickets.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Geri
                </a>
                <?php if ($ticket['status'] != 'closed'): ?>
                    <button onclick="openCloseModal()" class="btn btn-success">
                        <i class="fas fa-check"></i> Kapat
                    </button>
                <?php endif; ?>
                <button onclick="openDeleteModal()" class="btn btn-danger">
                    <i class="fas fa-trash"></i> Sil
                </button>
            </div>
        </div>

        <div class="ticket-container">
            <div class="ticket-content">
                <div class="ticket-meta">
                    <div class="meta-item">
                        <div class="meta-label">Oluşturan</div>
                        <div class="meta-value"><?php echo htmlspecialchars($ticket['username']); ?></div>
                    </div>
                    <div class="meta-item">
                        <div class="meta-label">Email</div>
                        <div class="meta-value"><?php echo htmlspecialchars($ticket['email']); ?></div>
                    </div>
                    <div class="meta-item">
                        <div class="meta-label">Oluşturulma</div>
                        <div class="meta-value"><?php echo date('d.m.Y H:i', strtotime($ticket['created_at'])); ?></div>
                    </div>
                    <div class="meta-item">
                        <div class="meta-label">Güncelleme</div>
                        <div class="meta-value"><?php echo date('d.m.Y H:i', strtotime($ticket['updated_at'])); ?></div>
                    </div>
                </div>

                <div class="ticket-message">
                    <h3>Orijinal Mesaj</h3>
                    <div class="message-content">
                        <?php echo nl2br(htmlspecialchars($ticket['message'])); ?>
                    </div>
                </div>

                <?php if (!empty($replies)): ?>
                    <div class="conversation">
                        <h3>Konuşma Geçmişi</h3>
                        <div class="conversation-list">
                            <?php foreach ($replies as $reply): ?>
                                <div class="reply-item <?php echo $reply['is_admin'] ? 'admin' : 'user'; ?>">
                                    <div class="reply-header">
                                        <div class="reply-user">
                                            <div class="user-avatar-small">
                                                <?php echo strtoupper(substr($reply['username'], 0, 1)); ?>
                                            </div>
                                            <div class="user-name"><?php echo htmlspecialchars($reply['username']); ?></div>
                                        </div>
                                        <div class="reply-time">
                                            <?php echo date('d.m.Y H:i', strtotime($reply['created_at'])); ?>
                                        </div>
                                    </div>
                                    <div class="reply-content">
                                        <?php echo nl2br(htmlspecialchars($reply['message'])); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($ticket['status'] != 'closed'): ?>
                    <div class="reply-form">
                        <h3>Cevap Yaz</h3>
                        <form method="POST" action="">
                            <div class="form-group">
                                <textarea name="message" class="form-control" placeholder="Cevabınızı buraya yazın..." required></textarea>
                            </div>
                            <button type="submit" name="reply_ticket" class="btn btn-primary">
                                <i class="fas fa-paper-plane"></i> Cevap Gönder
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>

            <div class="ticket-sidebar">
                <div class="sidebar-section">
                    <h3>Ticket Kontrolleri</h3>
                    
                    <div class="form-group">
                        <label style="margin-bottom: 8px; display: block; color: var(--text-gray);">Durum</label>
                        <form method="POST" action="" id="statusForm">
                            <div class="status-controls">
                                <div class="status-option open <?php echo $ticket['status'] == 'open' ? 'active' : ''; ?>" onclick="updateStatus('open')">Bekleyen</div>
                                <div class="status-option answered <?php echo $ticket['status'] == 'answered' ? 'active' : ''; ?>" onclick="updateStatus('answered')">Cevaplanan</div>
                                <div class="status-option customer_reply <?php echo $ticket['status'] == 'customer_reply' ? 'active' : ''; ?>" onclick="updateStatus('customer_reply')">Yanıt Bekliyor</div>
                                <div class="status-option closed <?php echo $ticket['status'] == 'closed' ? 'active' : ''; ?>" onclick="updateStatus('closed')">Kapatılan</div>
                            </div>
                            <input type="hidden" name="status" id="statusInput">
                        </form>
                    </div>
                    
                    <div class="form-group">
                        <label style="margin-bottom: 8px; display: block; color: var(--text-gray);">Öncelik</label>
                        <form method="POST" action="" id="priorityForm">
                            <div class="priority-controls">
                                <div class="priority-option low <?php echo $ticket['priority'] == 'low' ? 'active' : ''; ?>" onclick="updatePriority('low')">Düşük</div>
                                <div class="priority-option medium <?php echo $ticket['priority'] == 'medium' ? 'active' : ''; ?>" onclick="updatePriority('medium')">Orta</div>
                                <div class="priority-option high <?php echo $ticket['priority'] == 'high' ? 'active' : ''; ?>" onclick="updatePriority('high')">Yüksek</div>
                                <div class="priority-option urgent <?php echo $ticket['priority'] == 'urgent' ? 'active' : ''; ?>" onclick="updatePriority('urgent')">Acil</div>
                            </div>
                            <input type="hidden" name="priority" id="priorityInput">
                        </form>
                    </div>
                </div>

                <div class="sidebar-section">
                    <h3>Ticket Bilgileri</h3>
                    <div style="display: flex; flex-direction: column; gap: 15px;">
                        <div>
                            <div style="color: var(--text-gray); font-size: 0.85rem; margin-bottom: 5px;">Departman</div>
                            <div><?php echo htmlspecialchars($ticket['department'] ?? 'Genel'); ?></div>
                        </div>
                        
                        <div>
                            <div style="color: var(--text-gray); font-size: 0.85rem; margin-bottom: 5px;">Durum</div>
                            <div>
                                <?php if ($ticket['status'] == 'open'): ?>
                                    <span style="background: rgba(59, 130, 246, 0.1); color: #3b82f6; padding: 6px 12px; border-radius: 20px; font-size: 0.85rem;">
                                        <i class="fas fa-clock"></i> Bekleyen
                                    </span>
                                <?php elseif ($ticket['status'] == 'answered'): ?>
                                    <span style="background: rgba(16, 185, 129, 0.1); color: var(--secondary); padding: 6px 12px; border-radius: 20px; font-size: 0.85rem;">
                                        <i class="fas fa-reply"></i> Cevaplanan
                                    </span>
                                <?php elseif ($ticket['status'] == 'customer_reply'): ?>
                                    <span style="background: rgba(245, 158, 11, 0.1); color: var(--warning); padding: 6px 12px; border-radius: 20px; font-size: 0.85rem;">
                                        <i class="fas fa-comment-dots"></i> Yanıt Bekliyor
                                    </span>
                                <?php elseif ($ticket['status'] == 'closed'): ?>
                                    <span style="background: rgba(148, 163, 184, 0.1); color: var(--text-gray); padding: 6px 12px; border-radius: 20px; font-size: 0.85rem;">
                                        <i class="fas fa-check-circle"></i> Kapatılan
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div>
                            <div style="color: var(--text-gray); font-size: 0.85rem; margin-bottom: 5px;">Öncelik</div>
                            <div>
                                <?php if ($ticket['priority'] == 'low'): ?>
                                    <span style="background: rgba(16, 185, 129, 0.1); color: var(--secondary); padding: 6px 12px; border-radius: 20px; font-size: 0.85rem;">Düşük</span>
                                <?php elseif ($ticket['priority'] == 'medium'): ?>
                                    <span style="background: rgba(245, 158, 11, 0.1); color: var(--warning); padding: 6px 12px; border-radius: 20px; font-size: 0.85rem;">Orta</span>
                                <?php elseif ($ticket['priority'] == 'high'): ?>
                                    <span style="background: rgba(239, 68, 68, 0.1); color: var(--danger); padding: 6px 12px; border-radius: 20px; font-size: 0.85rem;">Yüksek</span>
                                <?php elseif ($ticket['priority'] == 'urgent'): ?>
                                    <span style="background: rgba(124, 58, 237, 0.1); color: var(--primary); padding: 6px 12px; border-radius: 20px; font-size: 0.85rem;">Acil</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <?php if ($ticket['closed_at']): ?>
                            <div>
                                <div style="color: var(--text-gray); font-size: 0.85rem; margin-bottom: 5px;">Kapatılma</div>
                                <div><?php echo date('d.m.Y H:i', strtotime($ticket['closed_at'])); ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal" id="closeModal">
        <div class="modal-content">
            <h3 class="modal-title">Ticket Kapat</h3>
            <p>Bu ticketı kapatmak istediğinize emin misiniz?</p>
            <div class="modal-actions">
                <button class="btn btn-secondary" onclick="closeCloseModal()">
                    <i class="fas fa-times"></i> İptal
                </button>
                <form method="POST" action="">
                    <button type="submit" name="close_ticket" class="btn btn-success">
                        <i class="fas fa-check"></i> Evet, Kapat
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="modal" id="deleteModal">
        <div class="modal-content">
            <h3 class="modal-title">Ticket Sil</h3>
            <p>Bu ticketı ve tüm mesajlarını silmek istediğinize emin misiniz? Bu işlem geri alınamaz!</p>
            <div class="modal-actions">
                <button class="btn btn-secondary" onclick="closeDeleteModal()">
                    <i class="fas fa-times"></i> İptal
                </button>
                <form method="POST" action="">
                    <button type="submit" name="delete_ticket" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Evet, Sil
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const menuToggle = document.getElementById('menuToggle');
            const navMenu = document.getElementById('navMenu');
            
            if (menuToggle && navMenu) {
                menuToggle.addEventListener('click', function() {
                    navMenu.classList.toggle('active');
                });
            }
            
            const conversationList = document.querySelector('.conversation-list');
            if (conversationList) {
                conversationList.scrollTop = conversationList.scrollHeight;
            }
            
            window.addEventListener('click', function(e) {
                if (e.target.classList.contains('modal')) {
                    closeCloseModal();
                    closeDeleteModal();
                }
            });
            
            window.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeCloseModal();
                    closeDeleteModal();
                }
            });
        });
        
        function updateStatus(status) {
            if (confirm('Ticket durumunu güncellemek istediğinize emin misiniz?')) {
                document.getElementById('statusInput').value = status;
                document.getElementById('statusForm').submit();
            }
        }
        
        function updatePriority(priority) {
            if (confirm('Ticket önceliğini güncellemek istediğinize emin misiniz?')) {
                document.getElementById('priorityInput').value = priority;
                document.getElementById('priorityForm').submit();
            }
        }
        
        function openCloseModal() {
            document.getElementById('closeModal').style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }
        
        function closeCloseModal() {
            document.getElementById('closeModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }
        
        function openDeleteModal() {
            document.getElementById('deleteModal').style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }
        
        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }
    </script>
</body>
</html>
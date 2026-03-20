<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: dashboard.php');
    exit;
}

$admin_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['user_id'], $_POST['subject'], $_POST['message'])) {
    $user_id = intval($_POST['user_id']);
    $subject = trim($_POST['subject']);
    $message = trim($_POST['message']);
    $priority = $_POST['priority'];
    $department = $_POST['department'];
    
    if (!empty($subject) && !empty($message)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO tickets (user_id, subject, message, priority, department, status, created_at) VALUES (?, ?, ?, ?, ?, 'answered', NOW())");
            $stmt->execute([$user_id, $subject, $message, $priority, $department]);
            
            $ticket_id = $pdo->lastInsertId();
            
            $stmt = $pdo->prepare("INSERT INTO ticket_replies (ticket_id, user_id, message, is_admin, created_at) VALUES (?, ?, ?, 1, NOW())");
            $stmt->execute([$ticket_id, $admin_id, $message]);
            
            header("Location: admin_ticket_view.php?id=$ticket_id");
            exit;
        } catch (PDOException $e) {
            $error = "Ticket oluşturulurken hata oluştu";
        }
    } else {
        $error = "Lütfen tüm alanları doldurun";
    }
}

$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
$subject = isset($_GET['subject']) ? $_GET['subject'] : '';

if ($user_id > 0) {
    $stmt = $pdo->prepare("SELECT username, email FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
} else {
    $user = null;
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket Oluştur - Darq SMM Panel</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #8B5CF6;
            --primary-dark: #7C3AED;
            --bg-dark: #0F172A;
            --bg-card: #1E293B;
            --text-light: #F8FAFC;
            --text-gray: #94A3B8;
            --gradient: linear-gradient(135deg, var(--primary), #10B981);
            --radius: 12px;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg-dark);
            color: var(--text-light);
            padding: 20px;
        }
        
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: var(--bg-card);
            border-radius: var(--radius);
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        }
        
        h1 {
            font-size: 1.8rem;
            margin-bottom: 25px;
            background: var(--gradient);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            color: var(--text-gray);
            font-weight: 500;
        }
        
        input, select, textarea {
            width: 100%;
            padding: 12px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: var(--radius);
            color: var(--text-light);
            font-family: inherit;
            transition: all 0.3s ease;
        }
        
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--primary);
        }
        
        textarea {
            min-height: 200px;
            resize: vertical;
        }
        
        .btn {
            padding: 12px 30px;
            background: var(--gradient);
            color: white;
            border: none;
            border-radius: var(--radius);
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(139, 92, 246, 0.3);
        }
        
        .btn-secondary {
            background: rgba(255, 255, 255, 0.1);
            margin-right: 10px;
        }
        
        .user-info {
            background: rgba(255, 255, 255, 0.05);
            padding: 15px;
            border-radius: var(--radius);
            margin-bottom: 20px;
            border-left: 4px solid var(--primary);
        }
        
        .alert {
            padding: 15px;
            border-radius: var(--radius);
            margin-bottom: 20px;
            background: rgba(239, 68, 68, 0.1);
            border-left: 4px solid #EF4444;
            color: #EF4444;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1><i class="fas fa-ticket-alt"></i> Yeni Ticket Oluştur</h1>
        
        <?php if (isset($error)): ?>
            <div class="alert">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($user): ?>
            <div class="user-info">
                <div><strong>Kullanıcı:</strong> <?php echo htmlspecialchars($user['username']); ?></div>
                <div><strong>Email:</strong> <?php echo htmlspecialchars($user['email']); ?></div>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <input type="hidden" name="user_id" value="<?php echo $user_id; ?>">
            
            <div class="form-group">
                <label for="subject">Konu</label>
                <input type="text" name="subject" id="subject" value="<?php echo htmlspecialchars($subject); ?>" required>
            </div>
            
            <div class="form-group">
                <label for="department">Departman</label>
                <select name="department" id="department" required>
                    <option value="general">Genel Destek</option>
                    <option value="technical">Teknik Sorun</option>
                    <option value="billing">Fatura ve Ödeme</option>
                    <option value="order">Sipariş Sorunu</option>
                    <option value="other">Diğer</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="priority">Öncelik</label>
                <select name="priority" id="priority" required>
                    <option value="low">Düşük</option>
                    <option value="medium" selected>Orta</option>
                    <option value="high">Yüksek</option>
                    <option value="urgent">Acil</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="message">Mesaj</label>
                <textarea name="message" id="message" required placeholder="Mesajınızı detaylı bir şekilde yazın..."></textarea>
            </div>
            
            <div style="display: flex; gap: 10px; margin-top: 30px;">
                <button type="button" class="btn btn-secondary" onclick="window.close()">
                    <i class="fas fa-times"></i> İptal
                </button>
                <button type="submit" class="btn">
                    <i class="fas fa-paper-plane"></i> Ticket Oluştur
                </button>
            </div>
        </form>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            <?php if ($subject): ?>
                document.getElementById('message').focus();
            <?php else: ?>
                document.getElementById('subject').focus();
            <?php endif; ?>
        });
    </script>
</body>
</html>
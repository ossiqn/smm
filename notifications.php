<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// Sayfalama
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Toplam bildirim sayısı
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM user_notifications WHERE user_id = ?");
$stmt->execute([$user_id]);
$total_notifications = $stmt->fetch()['total'];
$total_pages = ceil($total_notifications / $limit);

// Bildirimleri getir
$stmt = $pdo->prepare("SELECT * FROM user_notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?");
$stmt->bindValue(1, $user_id, PDO::PARAM_INT);
$stmt->bindValue(2, $limit, PDO::PARAM_INT);
$stmt->bindValue(3, $offset, PDO::PARAM_INT);
$stmt->execute();
$notifications = $stmt->fetchAll();

// Tümünü okundu yapma butonu
if (isset($_POST['mark_all_read'])) {
    $stmt = $pdo->prepare("UPDATE user_notifications SET is_read = TRUE WHERE user_id = ?");
    $stmt->execute([$user_id]);
    
    $_SESSION['success'] = "Tüm bildirimler okundu olarak işaretlendi.";
    header("Location: notifications.php");
    exit;
}

// Tümünü silme butonu
if (isset($_POST['delete_all'])) {
    $stmt = $pdo->prepare("DELETE FROM user_notifications WHERE user_id = ?");
    $stmt->execute([$user_id]);
    
    $_SESSION['success'] = "Tüm bildirimler silindi.";
    header("Location: notifications.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bildirimler - Darq SMM Panel</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Dashboard CSS'ini kullan veya özelleştir */
    </style>
</head>
<body>
    <!-- Dashboard navbar'ını kullan -->
    
    <div class="container">
        <h1>Bildirimler</h1>
        
        <div style="margin-bottom: 20px; display: flex; gap: 10px;">
            <form method="POST" style="display: inline;">
                <button type="submit" name="mark_all_read" class="btn btn-primary">
                    <i class="fas fa-check-double"></i> Tümünü Okundu Yap
                </button>
            </form>
            <form method="POST" style="display: inline;" onsubmit="return confirm('Tüm bildirimleri silmek istediğinize emin misiniz?')">
                <button type="submit" name="delete_all" class="btn btn-danger">
                    <i class="fas fa-trash"></i> Tümünü Sil
                </button>
            </form>
        </div>
        
        <?php if (!empty($notifications)): ?>
            <div class="notifications-list">
                <?php foreach ($notifications as $notification): ?>
                    <div class="notification-item" style="background: <?php echo !$notification['is_read'] ? 'rgba(59, 130, 246, 0.05)' : 'var(--bg-card)'; ?>; padding: 20px; border-radius: var(--radius); margin-bottom: 15px; border: 1px solid rgba(255, 255, 255, 0.1);">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 15px;">
                            <div style="flex: 1;">
                                <div style="font-weight: 600; font-size: 1.1rem; color: var(--text-light); margin-bottom: 8px;">
                                    <?php echo htmlspecialchars($notification['title']); ?>
                                </div>
                                <div style="color: var(--text-gray); margin-bottom: 10px;">
                                    <?php echo nl2br(htmlspecialchars($notification['message'])); ?>
                                </div>
                                <div style="font-size: 0.9rem; color: var(--text-gray);">
                                    <i class="far fa-clock"></i> <?php echo date('d.m.Y H:i', strtotime($notification['created_at'])); ?>
                                    <?php if ($notification['related_id']): ?>
                                        <span style="margin-left: 15px;">
                                            <i class="fas fa-link"></i> ID: <?php echo $notification['related_id']; ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div>
                                <form method="POST" action="mark_notifications_read.php" style="display: inline;">
                                    <input type="hidden" name="id" value="<?php echo $notification['id']; ?>">
                                    <button type="submit" class="btn btn-secondary" style="padding: 5px 10px; font-size: 0.9rem;">
                                        <i class="fas fa-check"></i> Okundu
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <?php if ($total_pages > 1): ?>
                <div class="pagination" style="display: flex; gap: 10px; justify-content: center; margin-top: 30px;">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page-1; ?>" class="btn btn-secondary">
                            <i class="fas fa-chevron-left"></i> Önceki
                        </a>
                    <?php endif; ?>
                    
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="?page=<?php echo $i; ?>" class="btn <?php echo $i == $page ? 'btn-primary' : 'btn-secondary'; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page+1; ?>" class="btn btn-secondary">
                            Sonraki <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div style="text-align: center; padding: 60px 20px; color: var(--text-gray);">
                <i class="fas fa-bell-slash" style="font-size: 4rem; margin-bottom: 20px; opacity: 0.5;"></i>
                <h3>Henüz bildiriminiz yok</h3>
                <p>Yeni bildirimler burada görünecektir</p>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
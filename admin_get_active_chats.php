<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Yetkisiz erişim']);
    exit;
}

try {
    $stmt = $pdo->query("SELECT user_id, COUNT(*) as message_count FROM live_support_messages WHERE is_admin = 0 AND created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE) GROUP BY user_id");
    $active_chats = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    echo json_encode(['success' => true, 'active_chats' => $active_chats]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Veritabanı hatası']);
}
?>
<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Oturum açmanız gerekiyor']);
    exit;
}

$user_id = $_SESSION['user_id'];

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as unread FROM live_support_messages WHERE user_id = ? AND is_admin = 1 AND created_at > (SELECT MAX(created_at) FROM live_support_messages WHERE user_id = ? AND is_admin = 0)");
    $stmt->execute([$user_id, $user_id]);
    $result = $stmt->fetch();
    
    echo json_encode(['success' => true, 'unread' => $result['unread'] ?? 0]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Veritabanı hatası']);
}
?>
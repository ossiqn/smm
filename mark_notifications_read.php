<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false]);
    exit;
}

$user_id = $_SESSION['user_id'];

if (isset($_GET['all']) && $_GET['all'] == 1) {
    // Tüm bildirimleri okundu yap
    $stmt = $pdo->prepare("UPDATE user_notifications SET is_read = TRUE WHERE user_id = ?");
    $stmt->execute([$user_id]);
    
    echo json_encode(['success' => true]);
} elseif (isset($_GET['id'])) {
    // Tek bir bildirimi okundu yap
    $notification_id = intval($_GET['id']);
    
    $stmt = $pdo->prepare("UPDATE user_notifications SET is_read = TRUE WHERE id = ? AND user_id = ?");
    $stmt->execute([$notification_id, $user_id]);
    
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false]);
}
?>
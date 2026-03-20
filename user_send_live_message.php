<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Oturum açmanız gerekiyor']);
    exit;
}

$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['message'])) {
    $message = trim($_POST['message']);
    
    if (!empty($message)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO live_support_messages (user_id, message, is_admin, created_at) VALUES (?, ?, 0, NOW())");
            $stmt->execute([$user_id, $message]);
            
            $ai_replies = [
                "Mesajınız alındı. Bir destek temsilcisi en kısa sürede size dönüş yapacaktır.",
                "Sorununuzu anladım. Size yardımcı olmaya çalışıyorum.",
                "Lütfen birkaç dakika bekleyin, sorununuzla ilgileniyorum.",
                "Bu konuda size daha detaylı bilgi vereceğim.",
                "Anlıyorum, size en uygun çözümü bulmak için çalışıyorum."
            ];
            
            $random_reply = $ai_replies[array_rand($ai_replies)];
            
            $stmt = $pdo->prepare("INSERT INTO live_support_messages (user_id, message, is_admin, created_at) VALUES (?, ?, 1, NOW())");
            $stmt->execute([$user_id, $random_reply]);
            
            echo json_encode(['success' => true, 'reply' => $random_reply]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Mesaj kaydedilemedi']);
        }
    }
} elseif (isset($_GET['action']) && $_GET['action'] == 'connect') {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Geçersiz istek']);
}
?>
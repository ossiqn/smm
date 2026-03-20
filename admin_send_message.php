<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Yetkisiz erişim']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['user_id'], $_POST['message'])) {
    $admin_id = $_SESSION['user_id'];
    $user_id = intval($_POST['user_id']);
    $message = trim($_POST['message']);
    
    if (!empty($message)) {
        try {
            // is_admin = 1 olduğundan emin ol
            $stmt = $pdo->prepare("INSERT INTO live_support_messages 
                (user_id, admin_id, message, is_admin, created_at) 
                VALUES (?, ?, ?, 1, NOW())");
            $success = $stmt->execute([$user_id, $admin_id, $message]);
            
            // DEBUG için
            error_log("Admin mesaj gönderdi - User: $user_id, Success: " . ($success ? 'YES' : 'NO'));
            
            if ($success) {
                echo json_encode([
                    'success' => true,
                    'message_id' => $pdo->lastInsertId()
                ]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Insert başarısız']);
            }
        } catch (PDOException $e) {
            error_log("Send Error: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Veritabanı hatası: ' . $e->getMessage()]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Boş mesaj']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Geçersiz istek']);
}
?>
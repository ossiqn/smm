<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Yetkisiz erişim']);
    exit;
}

$admin_id = $_SESSION['user_id'];
$ticket_id = isset($_POST['ticket_id']) ? intval($_POST['ticket_id']) : 0;
$message = isset($_POST['message']) ? trim($_POST['message']) : '';

error_log("Ticket ID: $ticket_id");
error_log("Message: " . substr($message, 0, 50));

if ($ticket_id <= 0) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Geçersiz ticket ID']);
    exit;
}

if (empty($message)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Mesaj boş olamaz']);
    exit;
}

try {
    $pdo->beginTransaction();
    
    $stmt = $pdo->prepare("SELECT user_id, status FROM tickets WHERE id = ?");
    $stmt->execute([$ticket_id]);
    $ticket = $stmt->fetch();
    
    if (!$ticket) {
        throw new Exception('Ticket bulunamadı');
    }
    
    $stmt = $pdo->prepare("INSERT INTO ticket_replies (ticket_id, user_id, message, is_admin, created_at) VALUES (?, ?, ?, 1, NOW())");
    $stmt->execute([$ticket_id, $admin_id, $message]);
    
    $new_status = 'answered';
    $stmt = $pdo->prepare("UPDATE tickets SET status = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$new_status, $ticket_id]);
    
    $pdo->commit();
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'Cevap gönderildi']);
    
} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Quick reply error: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
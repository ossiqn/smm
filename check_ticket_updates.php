<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['has_updates' => false]);
    exit;
}

$ticket_id = isset($_GET['ticket_id']) ? intval($_GET['ticket_id']) : 0;

if ($ticket_id <= 0) {
    echo json_encode(['has_updates' => false]);
    exit;
}

// Son kontrol zamanını session'da sakla
$last_check_key = 'ticket_last_check_' . $ticket_id;
$last_check_time = $_SESSION[$last_check_key] ?? time();

// Ticket'ın son güncelleme zamanını kontrol et
$stmt = $pdo->prepare("SELECT updated_at FROM tickets WHERE id = ?");
$stmt->execute([$ticket_id]);
$ticket = $stmt->fetch();

$has_updates = false;
if ($ticket && strtotime($ticket['updated_at']) > $last_check_time) {
    $has_updates = true;
}

// Yeni mesajları kontrol et
$stmt = $pdo->prepare("SELECT COUNT(*) as new_messages FROM ticket_replies WHERE ticket_id = ? AND created_at > FROM_UNIXTIME(?)");
$stmt->execute([$ticket_id, $last_check_time]);
$result = $stmt->fetch();

if ($result['new_messages'] > 0) {
    $has_updates = true;
}

// Güncel zamanı kaydet
$_SESSION[$last_check_key] = time();

echo json_encode(['has_updates' => $has_updates]);
?>
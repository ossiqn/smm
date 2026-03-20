<?php
require_once 'config.php';
require_once 'api_functions.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user || ($user['user_role'] != 'admin' && $user['user_role'] != 'super_admin')) {
    header('Location: dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $result = sync_services_from_api($pdo);
    
    $_SESSION['sync_message'] = $result['message'];
    $_SESSION['sync_success'] = $result['success'];
    
    header('Location: dashboard.php');
    exit;
}

header('Location: dashboard.php');
exit;
?>
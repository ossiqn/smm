<?php
require_once 'config.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Oturum açılmamış']);
    exit;
}

if (!isset($_GET['order_id'])) {
    echo json_encode(['error' => 'Sipariş ID gerekli']);
    exit;
}

$order_id = $_GET['order_id'];

try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE order_id = ? AND user_id = ?");
    $stmt->execute([$order_id, $_SESSION['user_id']]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        echo json_encode(['error' => 'Sipariş bulunamadı']);
        exit;
    }
    
    echo json_encode($order);
    
} catch (PDOException $e) {
    echo json_encode(['error' => 'Veritabanı hatası: ' . $e->getMessage()]);
    exit;
}
?>
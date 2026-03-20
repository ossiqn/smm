<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Yetkisiz erişim']);
    exit;
}

if (isset($_GET['user_id'])) {
    $user_id = intval($_GET['user_id']);
    
    try {
        // ÖNCELİKLE BU SORGUYU TEST ET
        $stmt = $pdo->prepare("SELECT 
            id,
            user_id,
            admin_id,
            message,
            is_admin,
            created_at 
            FROM live_support_messages 
            WHERE user_id = ? 
            ORDER BY created_at ASC 
            LIMIT 100");
        $stmt->execute([$user_id]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // DEBUG için ekle (sonra silebilirsin)
        error_log("User ID: $user_id, Message Count: " . count($messages));
        
        echo json_encode([
            'success' => true, 
            'messages' => $messages,
            'count' => count($messages) // DEBUG için
        ]);
    } catch (PDOException $e) {
        error_log("DB Error: " . $e->getMessage()); // DEBUG için
        echo json_encode(['success' => false, 'error' => 'Veritabanı hatası: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Kullanıcı ID gerekli']);
}
?>
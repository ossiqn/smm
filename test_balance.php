<?php
require_once 'config.php';

// Test için doğrudan DB operasyonu
try {
    $pdo->beginTransaction();
    
    // Test user'ı bul
    $stmt = $pdo->prepare("SELECT id, balance FROM users WHERE id = 1");
    $stmt->execute();
    $user = $stmt->fetch();
    
    echo "Before: User ID 1, Balance: " . $user['balance'] . "\n";
    
    // 100 TL ekle
    $amount = 100;
    $new_balance = $user['balance'] + $amount;
    
    $update = $pdo->prepare("UPDATE users SET balance = ? WHERE id = 1");
    $result = $update->execute([$new_balance]);
    
    echo "Update Result: " . ($result ? "Success" : "Failed") . "\n";
    
    // Check new balance
    $stmt = $pdo->prepare("SELECT balance FROM users WHERE id = 1");
    $stmt->execute();
    $user = $stmt->fetch();
    
    echo "After: User ID 1, Balance: " . $user['balance'] . "\n";
    
    $pdo->commit();
    
    echo "Transaction committed successfully!\n";
} catch (Exception $e) {
    $pdo->rollBack();
    echo "Error: " . $e->getMessage() . "\n";
}
?>


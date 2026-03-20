<?php
require_once 'config.php';

// Doğrudan test - form gibi POST yap
$_POST['add_balance'] = '1';
$_POST['user_id'] = '1';
$_POST['amount'] = '50';
$_POST['note'] = 'Test işlemi';

echo "POST Data: " . json_encode($_POST) . "\n\n";

// BAKİYE DÜZENLE - admin_users.php'deki kodu çalıştır
if (isset($_POST['add_balance'])) {
    $user_id = intval($_POST['user_id'] ?? 0);
    $amount = floatval($_POST['amount'] ?? 0);
    $note = trim($_POST['note'] ?? '');
    
    echo "After intval/floatval: User ID: $user_id, Amount: $amount\n\n";
    
    if ($user_id <= 0) {
        echo "Error: Geçersiz kullanıcı ID!\n";
        exit;
    }
    
    if ($amount == 0) {
        echo "Error: Tutar 0 olamaz!\n";
        exit;
    }
    
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("SELECT balance FROM users WHERE id = ? FOR UPDATE");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            throw new Exception("Kullanıcı bulunamadı (ID: $user_id)");
        }
        
        $old_balance = floatval($user['balance']);
        $new_balance = $old_balance + floatval($amount);
        
        echo "Old Balance: $old_balance\n";
        echo "New Balance: $new_balance\n\n";
        
        $update_stmt = $pdo->prepare("UPDATE users SET balance = ? WHERE id = ?");
        $update_result = $update_stmt->execute([$new_balance, $user_id]);
        
        if (!$update_result) {
            throw new Exception("Bakiye güncellenemedi!");
        }
        
        echo "✅ UPDATE başarılı!\n";
        
        $pdo->commit();
        
        echo "✅ Transaction committed!\n";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo "❌ Error: " . $e->getMessage() . "\n";
    }
}
?>

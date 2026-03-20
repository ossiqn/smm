<?php
require_once 'config.php';

error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Oturum açmanız gerekiyor.']);
    exit;
}

$order_local_id = 0;
if (isset($_POST['id'])) {
    $order_local_id = intval($_POST['id']);
} elseif (isset($_GET['id'])) {
    $order_local_id = intval($_GET['id']);
}

if ($order_local_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Geçersiz ID.']);
    exit;
}

$api_url = 'https://takipcinizbizden.com/api/v2';
$api_key = '14fd5712a199e44cdd0412ec5e33d744';

try {
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ?");
    $stmt->execute([$order_local_id, $_SESSION['user_id']]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        echo json_encode(['success' => false, 'message' => 'Sipariş bulunamadı.']);
        exit;
    }

    if (empty($order['api_order_id'])) {
        echo json_encode(['success' => false, 'message' => 'Bu siparişin API ID\'si yok.']);
        exit;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'key' => $api_key,
        'action' => 'status',
        'order' => $order['api_order_id']
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($response, true);

    if (isset($result['status'])) {
        $api_status = strtolower($result['status']);
        $start_count = isset($result['start_count']) ? intval($result['start_count']) : 0;
        $remains = isset($result['remains']) ? intval($result['remains']) : 0;
        
        $new_status = 'pending';
        if ($api_status == 'completed') $new_status = 'completed';
        elseif ($api_status == 'processing' || $api_status == 'in progress') $new_status = 'processing';
        elseif ($api_status == 'partial') $new_status = 'partial';
        elseif ($api_status == 'canceled') $new_status = 'cancelled';
        
        if ($new_status == 'cancelled' && $order['status'] != 'cancelled') {
            $pdo->beginTransaction();
            
            $refund_amount = floatval($order['price'] > 0 ? $order['price'] : $order['total']);
            
            $updateUser = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
            $updateUser->execute([$refund_amount, $_SESSION['user_id']]);
            
            $updateOrder = $pdo->prepare("UPDATE orders SET status = ?, start_count = ?, remains = ?, updated_at = NOW() WHERE id = ?");
            $updateOrder->execute([$new_status, $start_count, $remains, $order_local_id]);
            
            $desc = "Otomatik İptal İadesi (#" . ($order['order_id'] ?? $order_local_id) . ")";
            $pdo->prepare("INSERT INTO transactions (user_id, amount, type, description, created_at) VALUES (?, ?, 'refund', ?, NOW())")->execute([$_SESSION['user_id'], $refund_amount, $desc]);
            
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Sipariş iptal edildi ve ücret iade edildi.']);
        } 
        elseif ($new_status == 'partial' && $order['status'] != 'partial') {
             $pdo->beginTransaction();
             
             $unit_price = ($order['price'] > 0 ? $order['price'] : $order['total']) / $order['quantity'];
             $refund_amount = $remains * $unit_price;
             
             if ($refund_amount > 0) {
                 $updateUser = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
                 $updateUser->execute([$refund_amount, $_SESSION['user_id']]);
                 
                 $desc = "Kısmi Tamamlanma İadesi (#" . ($order['order_id'] ?? $order_local_id) . ")";
                 $pdo->prepare("INSERT INTO transactions (user_id, amount, type, description, created_at) VALUES (?, ?, 'refund', ?, NOW())")->execute([$_SESSION['user_id'], $refund_amount, $desc]);
             }
             
             $updateOrder = $pdo->prepare("UPDATE orders SET status = ?, start_count = ?, remains = ?, updated_at = NOW() WHERE id = ?");
             $updateOrder->execute([$new_status, $start_count, $remains, $order_local_id]);
             
             $pdo->commit();
             echo json_encode(['success' => true, 'message' => 'Sipariş kısmen tamamlandı, kalan iade edildi.']);
        }
        else {
            $stmt = $pdo->prepare("UPDATE orders SET status = ?, start_count = ?, remains = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$new_status, $start_count, $remains, $order_local_id]);
            echo json_encode(['success' => true, 'message' => 'Durum güncellendi: ' . ucfirst($new_status)]);
        }

    } else {
        echo json_encode(['success' => false, 'message' => 'API verisi alınamadı.']);
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
}
?>
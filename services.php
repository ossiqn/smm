<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    header('Location: login.php');
    exit;
}

if (isset($_POST['action']) && $_POST['action'] == 'read_notifications') {
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $stmt->execute([$user['id']]);
    echo 'ok';
    exit;
}

$api_url = 'https://takipcinizbizden.com/api/v2';
$api_key = '14fd5712a199e44cdd0412ec5e33d744';

// --- VERİTABANI GÜNCELLEME VE KONTROL ALANI ---
try {
    // Orders tablosunu güncelle
    $stmt = $pdo->query("DESCRIBE orders");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('api_order_id', $columns)) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN api_order_id VARCHAR(50) DEFAULT NULL AFTER order_id");
    }
    
    // Notifications tablosunu oluştur (Yoksa)
    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY, 
        user_id INT NOT NULL, 
        title VARCHAR(255) NOT NULL, 
        message TEXT NOT NULL, 
        type ENUM('info', 'success', 'warning', 'danger') DEFAULT 'info', 
        is_read BOOLEAN DEFAULT FALSE, 
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, 
        INDEX idx_user (user_id)
    )");

    // EĞER TABLO VAR AMA TITLE SÜTUNU YOKSA EKLE (HATA DÜZELTİCİ)
    $stmt = $pdo->query("DESCRIBE notifications");
    $notif_columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('title', $notif_columns)) {
        $pdo->exec("ALTER TABLE notifications ADD COLUMN title VARCHAR(255) NOT NULL AFTER user_id");
    }

} catch (Exception $e) {}
// ----------------------------------------------

$notifications = [];
$unread_count = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$user['id']]);
    $unread_count = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
    $stmt->execute([$user['id']]);
    $notifications = $stmt->fetchAll();
} catch (Exception $e) {}

$categories = [
    'all' => 'Tüm Hizmetler',
    'Instagram' => 'Instagram',
    'TikTok' => 'TikTok',
    'YouTube' => 'YouTube',
    'Twitter' => 'Twitter',
    'Facebook' => 'Facebook',
    'Spotify' => 'Spotify',
    'Telegram' => 'Telegram',
    'Twitch' => 'Twitch',
    'Other' => 'Diğer'
];

$services = [];
$api_success = false;

try {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'key' => $api_key,
        'action' => 'services'
    ]));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($response && $http_code == 200) {
        $api_data = json_decode($response, true);
        if (is_array($api_data)) {
            $api_success = true;
            foreach ($api_data as $api_service) {
                if (isset($api_service['service']) && isset($api_service['name'])) {
                    $api_price = floatval($api_service['rate']);
                    $our_price = $api_price * 1.70;
                    
                    $min_amount = intval($api_service['min']);
                    if ($min_amount < 100) $min_amount = 100;

                    $category_name = 'Other';
                    $service_name_lower = strtolower($api_service['name']);
                    
                    if (strpos($service_name_lower, 'instagram') !== false) $category_name = 'Instagram';
                    elseif (strpos($service_name_lower, 'tiktok') !== false) $category_name = 'TikTok';
                    elseif (strpos($service_name_lower, 'youtube') !== false) $category_name = 'YouTube';
                    elseif (strpos($service_name_lower, 'twitter') !== false) $category_name = 'Twitter';
                    elseif (strpos($service_name_lower, 'facebook') !== false) $category_name = 'Facebook';
                    elseif (strpos($service_name_lower, 'spotify') !== false) $category_name = 'Spotify';
                    elseif (strpos($service_name_lower, 'telegram') !== false) $category_name = 'Telegram';
                    elseif (strpos($service_name_lower, 'twitch') !== false) $category_name = 'Twitch';
                    
                    $est_time = 'Normal Hız';
                    if (strpos($service_name_lower, 'anlık') !== false || strpos($service_name_lower, 'instant') !== false) {
                        $est_time = 'Anlık Başlama';
                    } elseif (strpos($service_name_lower, 'hızlı') !== false || strpos($service_name_lower, 'fast') !== false) {
                        $est_time = 'Hızlı Teslimat';
                    } elseif (strpos($service_name_lower, 'yavaş') !== false || strpos($service_name_lower, 'slow') !== false) {
                        $est_time = '0-24 Saat';
                    }

                    $services[] = [
                        'api_id' => $api_service['service'],
                        'name' => $api_service['name'],
                        'category' => $category_name,
                        'price_per_1000' => round($our_price, 2),
                        'min' => $min_amount,
                        'max' => intval($api_service['max']),
                        'description' => isset($api_service['description']) ? $api_service['description'] : ($api_service['name'] . ' - Yüksek kalite garantili'),
                        'rate' => $api_service['rate'],
                        'time' => $est_time
                    ];
                }
            }
        }
    }
} catch (Exception $e) {}

$total_services = count($services);
$categories_count = [];
foreach ($services as $service) {
    $cat = $service['category'];
    if (!isset($categories_count[$cat])) $categories_count[$cat] = 0;
    $categories_count[$cat]++;
}

$order_error = null;
$order_success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_order'])) {
    $service_api_id = $_POST['service_id'];
    $link = trim($_POST['link']);
    $quantity = intval($_POST['quantity']);
    
    $selected_service = null;
    foreach ($services as $service) {
        if ($service['api_id'] == $service_api_id) {
            $selected_service = $service;
            break;
        }
    }
    
    if ($selected_service) {
        $price_per_1000 = $selected_service['price_per_1000'];
        $total_price = ($quantity / 1000) * $price_per_1000;
        $total_price = round($total_price, 2);
        
        if ($quantity < 100) {
             $order_error = "Minimum sipariş miktarı 100 adettir.";
        } elseif ($quantity < $selected_service['min'] || $quantity > $selected_service['max']) {
            $order_error = "Miktar aralığı: {$selected_service['min']} - {$selected_service['max']}";
        } elseif ($user['balance'] >= $total_price) {
            try {
                $api_order_data = [
                    'key' => $api_key,
                    'action' => 'add',
                    'service' => $selected_service['api_id'],
                    'link' => $link,
                    'quantity' => $quantity
                ];
                
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $api_url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($api_order_data));
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                
                $api_response = curl_exec($ch);
                
                if (curl_errno($ch)) {
                    throw new Exception(curl_error($ch));
                }
                
                curl_close($ch);
                
                $api_result = json_decode($api_response, true);
                
                if (isset($api_result['order'])) {
                    $api_order_id = $api_result['order'];
                    $internal_order_id = date('Ymd') . rand(1000, 9999);
                    
                    $stmt = $pdo->prepare("INSERT INTO orders (order_id, api_order_id, user_id, service_name, category, link, quantity, price, total, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())");
                    $stmt->execute([$internal_order_id, $api_order_id, $user['id'], $selected_service['name'], $selected_service['category'], $link, $quantity, $total_price, $total_price]);
                    
                    $new_balance = $user['balance'] - $total_price;
                    $stmt = $pdo->prepare("UPDATE users SET balance = ? WHERE id = ?");
                    $stmt->execute([$new_balance, $user['id']]);
                    
                    $stmt = $pdo->prepare("INSERT INTO transactions (user_id, type, amount, description, created_at) VALUES (?, 'order', ?, ?, NOW())");
                    $stmt->execute([$user['id'], -$total_price, 'Sipariş: ' . $selected_service['name']]);
                    
                    // Bildirim Ekleme (Düzeltilmiş)
                    $notif_title = "Sipariş Alındı";
                    $notif_msg = "Siparişiniz (#{$internal_order_id}) başarıyla oluşturuldu. Tutar: ₺{$total_price}";
                    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, created_at) VALUES (?, ?, ?, 'success', NOW())");
                    $stmt->execute([$user['id'], $notif_title, $notif_msg]);
                    
                    $order_success = 'Siparişiniz başarıyla oluşturuldu! Sipariş No: #' . $internal_order_id;
                    $user['balance'] = $new_balance;
                    
                    // Bildirimi anlık listeye ekle
                    $unread_count++;
                    array_unshift($notifications, [
                        'title' => $notif_title,
                        'message' => $notif_msg,
                        'created_at' => date('Y-m-d H:i:s'),
                        'type' => 'success',
                        'is_read' => 0
                    ]);
                    
                } else {
                    $raw_error = $api_result['error'] ?? 'Bilinmeyen hata';
                    if ($raw_error == 'neworder.error.not_enough_funds') {
                        $order_error = 'SİSTEM HATASI: Ana sağlayıcıda bakiye yetersiz.';
                    } else {
                        $order_error = 'Sipariş oluşturulamadı: ' . $raw_error;
                    }
                }
            } catch (Exception $e) {
                $order_error = 'Sistemsel hata: ' . $e->getMessage();
            }
        } else {
            $order_error = 'Yetersiz bakiye!';
        }
    } else {
        $order_error = 'Servis bulunamadı!';
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hizmetler - Darq SMM Panel</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        :root {
            --primary: #8B5CF6;
            --primary-dark: #7C3AED;
            --secondary: #10B981;
            --accent: #F59E0B;
            --danger: #EF4444;
            --bg-body: #020617;
            --bg-card: rgba(30, 41, 59, 0.6);
            --text-main: #F8FAFC;
            --text-muted: #94A3B8;
            --gradient-main: linear-gradient(135deg, #8B5CF6 0%, #4F46E5 100%);
            --gradient-text: linear-gradient(135deg, #C4B5FD 0%, #6EE7B7 100%);
            --glass-border: 1px solid rgba(255, 255, 255, 0.08);
            --glow: 0 0 30px rgba(139, 92, 246, 0.3);
            --radius: 20px;
            --transition: all 0.3s ease;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; color: var(--text-main); background: var(--bg-body); line-height: 1.6; overflow-x: hidden; min-height: 100vh; }

        .background-glow { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: -1; overflow: hidden; pointer-events: none; }
        .glow-blob { position: absolute; filter: blur(90px); opacity: 0.3; border-radius: 50%; animation: float 15s infinite alternate ease-in-out; }
        .blob-1 { top: -10%; left: -10%; width: 600px; height: 600px; background: var(--primary); }
        .blob-2 { bottom: 10%; right: -10%; width: 500px; height: 500px; background: #059669; animation-delay: -5s; }
        @keyframes float { 0% { transform: translate(0, 0) scale(1); } 100% { transform: translate(40px, 40px) scale(1.05); } }

        .navbar { padding: 20px 0; position: fixed; width: 100%; top: 0; z-index: 1000; background: rgba(2, 6, 23, 0.7); backdrop-filter: blur(15px); border-bottom: var(--glass-border); transition: 0.3s; }
        .navbar.scrolled { padding: 15px 0; background: rgba(2, 6, 23, 0.95); }
        .container { max-width: 1400px; margin: 0 auto; padding: 0 20px; }
        .nav-inner { display: flex; justify-content: space-between; align-items: center; }
        
        .logo { display: flex; align-items: center; gap: 12px; font-family: 'Outfit', sans-serif; font-size: 1.6rem; font-weight: 800; text-decoration: none; color: white; letter-spacing: -0.5px; }
        .logo i { color: var(--primary); font-size: 1.8rem; filter: drop-shadow(0 0 10px rgba(139, 92, 246, 0.5)); }

        .nav-menu { display: flex; gap: 20px; align-items: center; }
        .nav-menu a { text-decoration: none; color: var(--text-muted); font-weight: 500; transition: 0.3s; font-size: 0.95rem; display: flex; align-items: center; gap: 8px; padding: 8px 12px; border-radius: 12px; }
        .nav-menu a:hover, .nav-menu a.active { color: white; background: rgba(255,255,255,0.05); }
        .nav-menu a.active { background: rgba(139, 92, 246, 0.1); color: var(--primary); }

        .user-menu { display: flex; align-items: center; gap: 15px; position: relative; }
        .balance-badge { background: rgba(16, 185, 129, 0.1); color: #10B981; padding: 6px 12px; border-radius: 20px; font-weight: 600; display: flex; align-items: center; gap: 5px; font-size: 0.9rem; border: 1px solid rgba(16, 185, 129, 0.2); }
        
        .menu-toggle { display: none; font-size: 1.5rem; color: white; cursor: pointer; background: none; border: none; }

        /* Notification Bell Styles */
        .notif-wrapper { position: relative; margin-right: 10px; cursor: pointer; }
        .notif-bell { font-size: 1.2rem; color: var(--text-muted); transition: 0.3s; }
        .notif-bell:hover { color: white; }
        .notif-badge { position: absolute; top: -5px; right: -5px; background: var(--danger); color: white; font-size: 0.6rem; padding: 2px 5px; border-radius: 50%; border: 1px solid var(--bg-body); }
        
        .notif-dropdown {
            position: absolute;
            top: 50px;
            right: 0;
            width: 320px;
            background: #1e293b;
            border: var(--glass-border);
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.5);
            display: none;
            flex-direction: column;
            z-index: 1001;
            overflow: hidden;
            animation: slideDown 0.3s ease;
        }
        @keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        .notif-dropdown.active { display: flex; }
        .notif-header { padding: 15px; border-bottom: 1px solid rgba(255,255,255,0.05); font-weight: 600; color: white; display: flex; justify-content: space-between; align-items: center; }
        .notif-list { max-height: 300px; overflow-y: auto; }
        .notif-item { padding: 15px; border-bottom: 1px solid rgba(255,255,255,0.05); transition: 0.2s; }
        .notif-item:hover { background: rgba(255,255,255,0.02); }
        .notif-item.unread { background: rgba(139, 92, 246, 0.05); border-left: 3px solid var(--primary); }
        .notif-title { font-size: 0.9rem; font-weight: 600; color: white; margin-bottom: 3px; }
        .notif-msg { font-size: 0.8rem; color: var(--text-muted); }
        .notif-time { font-size: 0.7rem; color: var(--text-muted); margin-top: 5px; text-align: right; opacity: 0.7; }
        .notif-empty { padding: 20px; text-align: center; color: var(--text-muted); font-size: 0.9rem; }

        .main-content { padding: 100px 0 40px; }
        .dashboard-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 20px; }
        .dashboard-header h1 { font-family: 'Outfit', sans-serif; font-size: 2rem; font-weight: 700; }
        .gradient-text { background: var(--gradient-text); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        
        .btn { padding: 10px 20px; border-radius: 12px; font-weight: 600; text-decoration: none; transition: 0.3s; display: inline-flex; align-items: center; gap: 8px; border: none; cursor: pointer; font-size: 0.95rem; }
        .btn-primary { background: var(--gradient-main); color: white; box-shadow: var(--glow); }
        .btn-primary:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(139, 92, 246, 0.5); }
        .btn-outline { background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.15); color: white; }
        .btn-outline:hover { background: rgba(255,255,255,0.1); border-color: white; transform: translateY(-3px); }
        .btn-full { width: 100%; justify-content: flex-start; margin-bottom: 10px; }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 40px; }
        .stat-card { background: var(--bg-card); backdrop-filter: blur(15px); border: var(--glass-border); border-radius: 20px; padding: 25px; transition: 0.3s; position: relative; overflow: hidden; }
        .stat-card:hover { transform: translateY(-5px); border-color: var(--primary); box-shadow: var(--glow); }
        .stat-card::before { content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 4px; background: var(--gradient-main); }
        .stat-value { font-family: 'Outfit', sans-serif; font-size: 2rem; font-weight: 700; color: white; line-height: 1; margin-bottom: 5px; }
        .stat-label { color: var(--text-muted); font-size: 0.9rem; }
        .stat-icon { width: 50px; height: 50px; background: rgba(139, 92, 246, 0.1); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: var(--primary); font-size: 1.5rem; }

        .content-card { background: var(--bg-card); backdrop-filter: blur(15px); border: var(--glass-border); border-radius: 24px; padding: 30px; margin-bottom: 30px; }
        
        .filters-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 20px; }
        .filter-group { display: flex; flex-direction: column; gap: 8px; }
        .filter-group label { font-weight: 500; color: var(--text-muted); font-size: 0.9rem; }
        .form-control-filter { width: 100%; padding: 12px 15px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.1); border-radius: 12px; color: white; font-size: 0.95rem; transition: var(--transition); }
        .form-control-filter:focus { outline: none; border-color: var(--primary); background: rgba(255,255,255,0.05); }
        .filter-actions { display: flex; gap: 15px; align-items: center; justify-content: space-between; margin-top: 20px; padding-top: 20px; border-top: var(--glass-border); }
        
        .category-tabs { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 30px; }
        .category-tab { padding: 10px 20px; background: rgba(255, 255, 255, 0.03); border-radius: 12px; border: var(--glass-border); color: var(--text-muted); cursor: pointer; transition: var(--transition); font-weight: 500; display: flex; align-items: center; gap: 8px; }
        .category-tab:hover { background: rgba(255, 255, 255, 0.08); color: white; }
        .category-tab.active { background: rgba(139, 92, 246, 0.15); color: var(--primary); border-color: var(--primary); }
        .category-tab .count { background: rgba(255, 255, 255, 0.1); padding: 2px 8px; border-radius: 10px; font-size: 0.75rem; }
        
        .services-grid-layout { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 25px; margin-bottom: 40px; }
        .service-card { background: var(--bg-card); border: var(--glass-border); border-radius: 20px; padding: 25px; transition: var(--transition); position: relative; overflow: hidden; backdrop-filter: blur(15px); display: flex; flex-direction: column; height: 100%; }
        .service-card:hover { transform: translateY(-5px); border-color: var(--primary); box-shadow: var(--glow); }
        .service-header { display: flex; align-items: center; gap: 15px; margin-bottom: 20px; }
        .s-icon { width: 50px; height: 50px; background: var(--gradient-main); border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: white; flex-shrink: 0; box-shadow: 0 4px 15px rgba(139, 92, 246, 0.3); }
        .service-name { font-size: 1.1rem; font-weight: 700; color: white; margin-bottom: 4px; font-family: 'Outfit', sans-serif; line-height: 1.3; }
        .service-cat { font-size: 0.85rem; color: var(--text-muted); background: rgba(255,255,255,0.05); padding: 4px 10px; border-radius: 20px; display: inline-block; }
        .service-desc { color: var(--text-muted); font-size: 0.9rem; line-height: 1.6; margin-bottom: 20px; flex-grow: 1; }
        .price-box { text-align: center; padding: 12px; background: rgba(139, 92, 246, 0.1); border-radius: 14px; margin-bottom: 20px; border: 1px solid rgba(139, 92, 246, 0.2); }
        .price-amount { font-size: 1.5rem; font-weight: 800; color: var(--primary); font-family: 'Outfit', sans-serif; }
        .price-per { color: var(--text-muted); font-size: 0.85rem; }
        .detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 20px; }
        .detail-item { background: rgba(255,255,255,0.03); padding: 10px; border-radius: 12px; text-align: center; border: 1px solid rgba(255,255,255,0.05); }
        .detail-label { font-size: 0.75rem; color: var(--text-muted); margin-bottom: 3px; }
        .detail-val { font-size: 0.95rem; font-weight: 600; color: white; }
        .service-tag { position: absolute; top: 15px; right: 15px; background: rgba(16, 185, 129, 0.15); color: #10B981; padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; border: 1px solid rgba(16, 185, 129, 0.3); }

        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.85); backdrop-filter: blur(12px); z-index: 2000; align-items: center; justify-content: center; }
        .modal-content { background: #0f172a; border: 1px solid rgba(255,255,255,0.1); padding: 0; border-radius: 24px; width: 95%; max-width: 480px; position: relative; animation: slideUp 0.3s cubic-bezier(0.4, 0, 0.2, 1); box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.7); overflow: hidden; }
        @keyframes slideUp { from { transform: translateY(30px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .modal-header-bg { background: linear-gradient(135deg, rgba(139, 92, 246, 0.2) 0%, rgba(15, 23, 42, 0) 100%); padding: 30px 25px 15px 25px; border-bottom: 1px solid rgba(255,255,255,0.05); }
        .modal-body { padding: 25px; }
        .selected-service-card { background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 16px; padding: 15px; display: flex; gap: 15px; align-items: center; margin-bottom: 10px; }
        .ss-icon { width: 40px; height: 40px; background: var(--gradient-main); border-radius: 10px; display: flex; align-items: center; justify-content: center; color: white; font-size: 1.1rem; flex-shrink: 0; }
        .ss-info h4 { font-family: 'Outfit', sans-serif; font-size: 0.95rem; color: white; line-height: 1.3; }
        .ss-info span { font-size: 0.75rem; color: var(--text-muted); background: rgba(255,255,255,0.05); padding: 2px 8px; border-radius: 10px; }
        .modern-input-group { position: relative; margin-bottom: 15px; }
        .modern-input-group label { display: block; font-size: 0.8rem; font-weight: 600; color: var(--text-muted); margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px; }
        .modern-input-wrapper { position: relative; }
        .modern-input-wrapper i { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: var(--primary); font-size: 1.1rem; pointer-events: none; }
        .modern-input { width: 100%; padding: 14px 15px 14px 48px; background: rgba(15, 23, 42, 0.6); border: 1px solid rgba(255,255,255,0.1); border-radius: 14px; color: white; font-size: 1rem; transition: all 0.3s ease; font-family: 'Plus Jakarta Sans', sans-serif; }
        .modern-input:focus { outline: none; border-color: var(--primary); background: rgba(139, 92, 246, 0.05); box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1); }
        .qty-stepper { display: flex; align-items: center; background: rgba(15, 23, 42, 0.6); border: 1px solid rgba(255,255,255,0.1); border-radius: 14px; padding: 5px; height: 52px; }
        .qty-btn-step { width: 42px; height: 40px; border-radius: 10px; border: none; background: rgba(255,255,255,0.05); color: white; cursor: pointer; transition: 0.2s; font-size: 1.2rem; display: flex; align-items: center; justify-content: center; }
        .qty-btn-step:hover { background: var(--primary); color: white; }
        .qty-btn-step:active { transform: scale(0.95); }
        .modern-input.qty { border: none; background: transparent; padding: 0; text-align: center; font-weight: 700; font-size: 1.1rem; height: 100%; box-shadow: none; }
        .qty-limits { font-size: 0.75rem; color: var(--text-muted); text-align: right; margin-top: 5px; }
        .receipt-card { background: radial-gradient(circle at top right, rgba(139, 92, 246, 0.1), transparent 70%), rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.08); border-radius: 18px; padding: 20px; margin-top: 25px; position: relative; overflow: hidden; }
        .receipt-card::before { content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 3px; background: var(--gradient-main); }
        .receipt-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; font-size: 0.9rem; color: var(--text-muted); }
        .receipt-row.total { margin-top: 15px; padding-top: 15px; border-top: 1px dashed rgba(255,255,255,0.1); margin-bottom: 0; }
        .receipt-row.total span:first-child { font-weight: 600; color: white; }
        .big-price { font-size: 1.6rem; font-weight: 800; color: var(--primary); font-family: 'Outfit', sans-serif; text-shadow: 0 0 20px rgba(139, 92, 246, 0.3); }
        .modal-footer { padding: 0 25px 25px 25px; }
        .btn-block { width: 100%; padding: 16px; font-size: 1rem; border-radius: 16px; display: flex; align-items: center; justify-content: center; gap: 10px; }
        .close-modal-modern { position: absolute; top: 20px; right: 20px; width: 32px; height: 32px; background: rgba(255,255,255,0.05); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: var(--text-muted); cursor: pointer; border: 1px solid rgba(255,255,255,0.05); transition: 0.3s; z-index: 10; }
        .close-modal-modern:hover { background: rgba(239, 68, 68, 0.1); color: #EF4444; border-color: rgba(239, 68, 68, 0.3); transform: rotate(90deg); }
        .footer { padding: 40px 0; border-top: var(--glass-border); margin-top: 50px; text-align: center; color: var(--text-muted); font-size: 0.9rem; }
        .swal2-container { z-index: 99999 !important; }
        .swal2-popup { background: #1e293b !important; border: 1px solid rgba(255,255,255,0.1) !important; border-radius: 20px !important; color: white !important; font-family: 'Plus Jakarta Sans', sans-serif !important; }
        .swal2-title { color: white !important; font-family: 'Outfit', sans-serif !important; }
        .swal2-html-container { color: #94a3b8 !important; }
        .swal2-confirm { background: var(--gradient-main) !important; box-shadow: var(--glow) !important; border-radius: 12px !important; padding: 12px 30px !important; }
        .swal2-cancel { background: transparent !important; border: 1px solid #ef4444 !important; color: #ef4444 !important; border-radius: 12px !important; padding: 12px 30px !important; }

        @media (max-width: 992px) {
            .nav-menu { display: none; position: fixed; top: 70px; left: 0; width: 100%; background: rgba(2,6,23,0.98); flex-direction: column; padding: 20px; height: calc(100vh - 70px); align-items: flex-start; }
            .nav-menu a { width: 100%; padding: 15px; }
            .nav-menu.active { display: flex; }
            .menu-toggle { display: block; }
        }
    </style>
</head>
<body>

    <div class="background-glow">
        <div class="glow-blob blob-1"></div>
        <div class="glow-blob blob-2"></div>
    </div>

    <nav class="navbar" id="navbar">
        <div class="container nav-inner">
            <a href="dashboard.php" class="logo"><i class="fas fa-bolt"></i> Darq</a>
            <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
            <div class="nav-menu" id="navMenu">
                <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                <a href="services.php" class="active"><i class="fas fa-box"></i> Hizmetler</a>
                <a href="orders.php"><i class="fas fa-history"></i> Siparişler</a>
                <a href="balance.php"><i class="fas fa-wallet"></i> Bakiye</a>
                <a href="support.php"><i class="fas fa-headset"></i> Destek</a>
                <?php if ($user['user_role'] === 'admin' || $user['user_role'] === 'super_admin'): ?>
                <a href="admin_dashboard.php"><i class="fas fa-user-shield"></i> Admin Panel</a>
                <?php endif; ?>
            </div>
            
            <div class="user-menu">
                <div class="notif-wrapper" onclick="toggleNotifications()">
                    <i class="fas fa-bell notif-bell"></i>
                    <?php if($unread_count > 0): ?>
                        <span class="notif-badge" id="notifBadge"><?php echo $unread_count; ?></span>
                    <?php endif; ?>
                    
                    <div class="notif-dropdown" id="notifDropdown">
                        <div class="notif-header">
                            <span>Bildirimler</span>
                            <small style="cursor:pointer; color:var(--primary);" onclick="markAllRead(event)">Tümü Okundu</small>
                        </div>
                        <div class="notif-list">
                            <?php if(empty($notifications)): ?>
                                <div class="notif-empty">Henüz bildirim yok.</div>
                            <?php else: ?>
                                <?php foreach($notifications as $notif): ?>
                                    <div class="notif-item <?php echo $notif['is_read'] ? '' : 'unread'; ?>">
                                        <div class="notif-title"><?php echo htmlspecialchars($notif['title']); ?></div>
                                        <div class="notif-msg"><?php echo htmlspecialchars($notif['message']); ?></div>
                                        <div class="notif-time"><?php echo date('d.m H:i', strtotime($notif['created_at'])); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="balance-badge"><i class="fas fa-coins"></i> ₺<?php echo number_format($user['balance'], 2); ?></div>
                <a href="logout.php" class="btn btn-outline" style="padding: 6px 15px; font-size: 0.8rem;">Çıkış</a>
            </div>
        </div>
    </nav>

    <div class="main-content container">
        <div class="dashboard-header">
            <div>
                <h1>Hizmet <span class="gradient-text">Listesi</span></h1>
                <p style="color: var(--text-muted);">API üzerinden anlık güncellenen yüksek kaliteli servisler.</p>
                <div style="margin-top: 10px; display: inline-flex; align-items: center; gap: 10px; font-size: 0.9rem; background: rgba(255,255,255,0.05); padding: 5px 15px; border-radius: 20px;">
                    <i class="fas fa-circle" style="color: <?php echo $api_success ? '#10B981' : '#EF4444'; ?>; font-size: 0.7rem;"></i>
                    <?php echo $api_success ? 'API Bağlantısı Aktif' : 'API Bağlantı Hatası'; ?>
                </div>
            </div>
            <button class="btn btn-primary" onclick="location.reload()">
                <i class="fas fa-sync-alt"></i> Listeyi Yenile
            </button>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header" style="display:flex; justify-content:space-between; align-items:center;">
                    <div><div class="stat-value" id="total-visible-services"><?php echo $total_services; ?></div><div class="stat-label">Toplam Hizmet</div></div>
                    <div class="stat-icon"><i class="fas fa-boxes"></i></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header" style="display:flex; justify-content:space-between; align-items:center;">
                    <div><div class="stat-value"><?php echo count($categories_count); ?></div><div class="stat-label">Kategori</div></div>
                    <div class="stat-icon"><i class="fas fa-layer-group"></i></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header" style="display:flex; justify-content:space-between; align-items:center;">
                    <div><div class="stat-value">API</div><div class="stat-label">Entegrasyon</div></div>
                    <div class="stat-icon"><i class="fas fa-code-branch"></i></div>
                </div>
            </div>
        </div>

        <div class="content-card">
            <div class="filters-grid">
                <div class="filter-group">
                    <label for="search"><i class="fas fa-search"></i> Hizmet Ara</label>
                    <input type="text" id="search" class="form-control-filter" placeholder="Hizmet adı veya ID..." onkeyup="filterServices()">
                </div>
                <div class="filter-group">
                    <label for="category"><i class="fas fa-filter"></i> Kategori</label>
                    <select id="category" class="form-control-filter" onchange="filterServices()">
                        <?php foreach ($categories as $key => $name): ?>
                            <option value="<?php echo $key; ?>"><?php echo $name; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="sort"><i class="fas fa-sort"></i> Sıralama</label>
                    <select id="sort" class="form-control-filter" onchange="sortServices()">
                        <option value="default">Varsayılan</option>
                        <option value="price_low">Fiyat (Düşük > Yüksek)</option>
                        <option value="price_high">Fiyat (Yüksek > Düşük)</option>
                        <option value="name">İsim (A-Z)</option>
                    </select>
                </div>
            </div>
            <div class="filter-actions">
                <button type="button" class="btn btn-primary" onclick="filterServices()"><i class="fas fa-filter"></i> Uygula</button>
                <button type="button" class="btn btn-outline" onclick="resetFilters()"><i class="fas fa-undo"></i> Sıfırla</button>
            </div>
        </div>

        <?php if ($total_services > 0): ?>
        <div class="category-tabs" id="categoryTabsContainer">
            <?php foreach ($categories as $key => $name): ?>
                <?php if ($key !== 'all'): ?>
                    <div class="category-tab" data-cat="<?php echo $key; ?>" onclick="selectCategoryTab('<?php echo $key; ?>')">
                        <?php 
                        $icon = 'fas fa-star';
                        if(strpos($key, 'Instagram') !== false) $icon = 'fab fa-instagram';
                        elseif(strpos($key, 'TikTok') !== false) $icon = 'fab fa-tiktok';
                        elseif(strpos($key, 'YouTube') !== false) $icon = 'fab fa-youtube';
                        elseif(strpos($key, 'Twitter') !== false) $icon = 'fab fa-twitter';
                        elseif(strpos($key, 'Telegram') !== false) $icon = 'fab fa-telegram';
                        ?>
                        <i class="<?php echo $icon; ?>"></i>
                        <?php echo $name; ?>
                        <?php if (isset($categories_count[$key])): ?>
                            <span class="count"><?php echo $categories_count[$key]; ?></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="services-grid-layout" id="servicesGrid">
            <?php if (!empty($services)): ?>
                <?php foreach ($services as $service): ?>
                    <div class="service-card" 
                         data-category="<?php echo htmlspecialchars($service['category']); ?>" 
                         data-name="<?php echo htmlspecialchars(strtolower($service['name'])); ?>"
                         data-price="<?php echo $service['price_per_1000']; ?>">
                         
                        <?php if ($service['price_per_1000'] < 30): ?>
                            <span class="service-tag"><i class="fas fa-fire"></i> POPÜLER</span>
                        <?php endif; ?>

                        <div class="service-header">
                            <div class="s-icon">
                                <?php 
                                $cat_icon = 'fas fa-bolt';
                                if($service['category'] == 'Instagram') $cat_icon = 'fab fa-instagram';
                                elseif($service['category'] == 'TikTok') $cat_icon = 'fab fa-tiktok';
                                elseif($service['category'] == 'YouTube') $cat_icon = 'fab fa-youtube';
                                elseif($service['category'] == 'Twitter') $cat_icon = 'fab fa-twitter';
                                elseif($service['category'] == 'Facebook') $cat_icon = 'fab fa-facebook';
                                elseif($service['category'] == 'Spotify') $cat_icon = 'fab fa-spotify';
                                elseif($service['category'] == 'Telegram') $cat_icon = 'fab fa-telegram';
                                ?>
                                <i class="<?php echo $cat_icon; ?>"></i>
                            </div>
                            <div>
                                <div class="service-name"><?php echo htmlspecialchars($service['name']); ?></div>
                                <span class="service-cat"><?php echo htmlspecialchars($service['category']); ?></span>
                            </div>
                        </div>

                        <div class="service-desc">
                            <?php echo htmlspecialchars(mb_substr($service['description'], 0, 100)) . (mb_strlen($service['description']) > 100 ? '...' : ''); ?>
                        </div>

                        <div class="price-box">
                            <div class="price-amount">₺<?php echo number_format($service['price_per_1000'], 2); ?></div>
                            <div class="price-per">1000 Adet Fiyatı</div>
                        </div>

                        <div class="detail-grid">
                            <div class="detail-item">
                                <div class="detail-label">Min. Sipariş</div>
                                <div class="detail-val"><?php echo number_format($service['min']); ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Maks. Sipariş</div>
                                <div class="detail-val"><?php echo number_format($service['max']); ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Tahmini Süre</div>
                                <div class="detail-val"><?php echo $service['time']; ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">ID</div>
                                <div class="detail-val">#<?php echo $service['api_id']; ?></div>
                            </div>
                        </div>

                        <button class="btn btn-primary" style="justify-content: center; margin-top: auto;" onclick="openOrderModal(<?php echo htmlspecialchars(json_encode($service)); ?>)">
                            <i class="fas fa-cart-plus"></i> Sipariş Ver
                        </button>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="grid-column: 1 / -1; text-align: center; padding: 50px; background: var(--bg-card); border-radius: 20px; border: var(--glass-border);">
                    <i class="fas fa-exclamation-triangle" style="font-size: 3rem; color: var(--text-muted); margin-bottom: 20px;"></i>
                    <h3 style="color: white; margin-bottom: 10px;">Servis Bulunamadı</h3>
                    <p style="color: var(--text-muted);">API bağlantısı kurulamadı veya servis listesi boş.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <footer class="footer">
        <div class="container">
            <p>&copy; 2026 Darq SMM Panel. Tüm hakları saklıdır.</p>
        </div>
    </footer>

    <div class="modal" id="orderModal">
        <div class="modal-content">
            <button class="close-modal-modern" onclick="closeOrderModal()"><i class="fas fa-times"></i></button>
            
            <div class="modal-header-bg">
                <h2 class="modal-title">Sipariş Oluştur</h2>
                <div class="selected-service-card">
                    <div class="ss-icon"><i class="fas fa-star" id="modalIcon"></i></div>
                    <div class="ss-info">
                        <h4 id="modalServiceNameDisplay">Servis Adı Yükleniyor...</h4>
                        <span id="modalServiceCat">Kategori</span>
                    </div>
                </div>
            </div>

            <form id="orderForm" method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="place_order" value="1">
                    <input type="hidden" id="modalServiceId" name="service_id">
                    
                    <div class="modern-input-group">
                        <label>Hedef Link</label>
                        <div class="modern-input-wrapper">
                            <i class="fas fa-link"></i>
                            <input type="url" name="link" class="modern-input" placeholder="https://instagram.com/..." required>
                        </div>
                    </div>

                    <div class="modern-input-group">
                        <label>Miktar</label>
                        <div class="modern-input-wrapper">
                            <div class="qty-stepper">
                                <button type="button" class="qty-btn-step" onclick="changeQuantity(-100)"><i class="fas fa-minus"></i></button>
                                <input type="number" id="quantity" name="quantity" class="modern-input qty" placeholder="1000" required oninput="calculatePrice()">
                                <button type="button" class="qty-btn-step" onclick="changeQuantity(100)"><i class="fas fa-plus"></i></button>
                            </div>
                        </div>
                        <div class="qty-limits">
                            Limitler: <span id="minQuantity">100</span> - <span id="maxQuantity">10.000</span>
                        </div>
                    </div>

                    <div class="receipt-card">
                        <div class="receipt-row">
                            <span>Birim Fiyat (1000 Adet)</span>
                            <span id="unitPrice">₺0.00</span>
                        </div>
                        <div class="receipt-row">
                            <span>Miktar</span>
                            <span id="summaryQuantity">0 adet</span>
                        </div>
                        <div class="receipt-row total">
                            <span>Ödenecek Tutar</span>
                            <span class="big-price" id="totalPrice">₺0.00</span>
                        </div>
                    </div>
                    
                    <div style="margin-top: 15px; font-size: 0.85rem; color: var(--text-muted); text-align: center;">
                        Mevcut Bakiye: <span style="color: #10B981; font-weight: 600;">₺<?php echo number_format($user['balance'], 2); ?></span>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary btn-block">
                        <i class="fas fa-rocket"></i> Siparişi Onayla
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        const menuToggle = document.getElementById('menuToggle');
        const navMenu = document.getElementById('navMenu');
        menuToggle.addEventListener('click', () => navMenu.classList.toggle('active'));

        window.addEventListener('scroll', () => {
            const nav = document.getElementById('navbar');
            if(window.scrollY > 50) nav.classList.add('scrolled');
            else nav.classList.remove('scrolled');
        });

        function toggleNotifications() {
            const dd = document.getElementById('notifDropdown');
            dd.classList.toggle('active');
            
            if(dd.classList.contains('active')) {
                const badge = document.getElementById('notifBadge');
                if(badge) {
                    fetch('services.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: 'action=read_notifications'
                    }).then(() => {
                        badge.style.display = 'none';
                        document.querySelectorAll('.notif-item.unread').forEach(el => {
                            el.classList.remove('unread');
                            el.style.borderLeft = 'none';
                            el.style.background = 'transparent';
                        });
                    });
                }
            }
        }

        document.addEventListener('click', function(e) {
            const wrapper = document.querySelector('.notif-wrapper');
            const dd = document.getElementById('notifDropdown');
            if (wrapper && !wrapper.contains(e.target)) {
                dd.classList.remove('active');
            }
        });

        function markAllRead(e) {
            e.stopPropagation();
            toggleNotifications();
        }

        let currentService = null;
        let userBalance = <?php echo $user['balance']; ?>;

        const swalWithTheme = Swal.mixin({
            background: '#1e293b',
            color: '#F8FAFC',
            confirmButtonColor: '#8B5CF6',
            cancelButtonColor: '#EF4444',
            customClass: {
                popup: 'swal2-popup',
                title: 'swal2-title',
                confirmButton: 'swal2-confirm',
                cancelButton: 'swal2-cancel'
            }
        });

        <?php if ($order_error): ?>
            swalWithTheme.fire({ icon: 'error', title: 'Hata!', text: "<?php echo addslashes($order_error); ?>" });
        <?php endif; ?>
        
        <?php if ($order_success): ?>
            swalWithTheme.fire({ icon: 'success', title: 'Başarılı!', text: "<?php echo addslashes($order_success); ?>" }).then(() => { window.location.href = 'orders.php'; });
        <?php endif; ?>

        function filterServices() {
            const searchInput = document.getElementById('search').value.toLowerCase();
            const categorySelect = document.getElementById('category').value;
            const cards = document.querySelectorAll('.service-card');
            let visibleCount = 0;

            cards.forEach(card => {
                const name = card.getAttribute('data-name');
                const category = card.getAttribute('data-category');
                
                const matchesSearch = name.includes(searchInput);
                const matchesCategory = categorySelect === 'all' || category === categorySelect;

                if (matchesSearch && matchesCategory) {
                    card.style.display = 'flex';
                    visibleCount++;
                } else {
                    card.style.display = 'none';
                }
            });

            document.querySelectorAll('.category-tab').forEach(tab => {
                tab.classList.remove('active');
                if(tab.getAttribute('data-cat') === categorySelect) {
                    tab.classList.add('active');
                }
            });
            
            const counter = document.getElementById('total-visible-services');
            if(counter) counter.innerText = visibleCount;
        }

        function selectCategoryTab(category) {
            document.getElementById('category').value = category;
            filterServices();
        }

        function resetFilters() {
            document.getElementById('search').value = '';
            document.getElementById('category').value = 'all';
            document.getElementById('sort').value = 'default';
            filterServices();
            sortServices();
        }

        function sortServices() {
            const grid = document.getElementById('servicesGrid');
            const cards = Array.from(grid.getElementsByClassName('service-card'));
            const sortValue = document.getElementById('sort').value;

            cards.sort((a, b) => {
                const priceA = parseFloat(a.getAttribute('data-price'));
                const priceB = parseFloat(b.getAttribute('data-price'));
                const nameA = a.getAttribute('data-name');
                const nameB = b.getAttribute('data-name');

                if (sortValue === 'price_low') return priceA - priceB;
                if (sortValue === 'price_high') return priceB - priceA;
                if (sortValue === 'name') return nameA.localeCompare(nameB);
                return 0;
            });

            cards.forEach(card => grid.appendChild(card));
        }

        function openOrderModal(service) {
            currentService = service;
            document.getElementById('modalServiceNameDisplay').textContent = service.name;
            document.getElementById('modalServiceCat').textContent = service.category;
            
            let iconClass = 'fas fa-star';
            if(service.category.includes('Instagram')) iconClass = 'fab fa-instagram';
            else if(service.category.includes('TikTok')) iconClass = 'fab fa-tiktok';
            else if(service.category.includes('YouTube')) iconClass = 'fab fa-youtube';
            else if(service.category.includes('Twitter')) iconClass = 'fab fa-twitter';
            else if(service.category.includes('Telegram')) iconClass = 'fab fa-telegram';
            document.getElementById('modalIcon').className = iconClass;

            document.getElementById('modalServiceId').value = service.api_id;
            
            const min = service.min || 100;
            const max = service.max || 10000;
            
            document.getElementById('minQuantity').textContent = min.toLocaleString();
            document.getElementById('maxQuantity').textContent = max.toLocaleString();
            document.getElementById('quantity').min = min;
            document.getElementById('quantity').max = max;
            document.getElementById('quantity').value = min;
            document.getElementById('unitPrice').innerHTML = '₺' + (service.price_per_1000 || 0).toFixed(2);
            
            calculatePrice();
            document.getElementById('orderModal').style.display = 'flex';
        }

        function closeOrderModal() {
            document.getElementById('orderModal').style.display = 'none';
            currentService = null;
        }

        function changeQuantity(amount) {
            const qtyInput = document.getElementById('quantity');
            let val = parseInt(qtyInput.value) || 0;
            val += amount;
            if(currentService) {
                 if(val < currentService.min) val = currentService.min;
                 if(val > currentService.max) val = currentService.max;
            }
            qtyInput.value = val;
            calculatePrice();
        }

        function calculatePrice() {
            if (!currentService) return;
            const quantity = parseInt(document.getElementById('quantity').value) || 0;
            const pricePer1000 = currentService.price_per_1000 || 0;
            const total = (quantity / 1000) * pricePer1000;
            
            document.getElementById('summaryQuantity').textContent = quantity.toLocaleString() + ' adet';
            document.getElementById('totalPrice').textContent = '₺' + total.toFixed(2);
        }

        document.getElementById('orderForm').addEventListener('submit', function(e) {
            e.preventDefault();
            if (!currentService) return;

            const quantity = parseInt(document.getElementById('quantity').value);
            const total = (quantity / 1000) * currentService.price_per_1000;

            if (total > userBalance) {
                swalWithTheme.fire({
                    icon: 'warning',
                    title: 'Yetersiz Bakiye!',
                    text: 'Bu işlem için bakiyeniz yetersiz. Lütfen bakiye yükleyin.',
                    confirmButtonText: 'Bakiye Yükle',
                    showCancelButton: true,
                    cancelButtonText: 'İptal'
                }).then((res) => {
                    if (res.isConfirmed) window.location.href = 'balance.php';
                });
                return;
            }

            swalWithTheme.fire({
                title: 'Onaylıyor musunuz?',
                html: `<b>${quantity} adet</b> sipariş için<br><b style="font-size:1.5rem; color:#F59E0B">₺${total.toFixed(2)}</b><br>bakiyenizden düşülecektir.`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Evet, Onayla',
                cancelButtonText: 'Vazgeç'
            }).then((result) => {
                if (result.isConfirmed) {
                    this.submit();
                }
            });
        });

        window.onclick = function(event) {
            if (event.target == document.getElementById('orderModal')) {
                closeOrderModal();
            }
        }
    </script>
</body>
</html>
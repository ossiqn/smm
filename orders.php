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

$is_admin = ($user['user_role'] == 'admin' || $user['user_role'] == 'super_admin');

$api_url = 'https://takipcinizbizden.com/api/v2';
$api_key = '14fd5712a199e44cdd0412ec5e33d744';

function getStatusIcon($status) {
    switch(strtolower($status)) {
        case 'pending': return 'fas fa-clock';
        case 'processing': return 'fas fa-sync-alt fa-spin';
        case 'in progress': return 'fas fa-sync-alt fa-spin';
        case 'progress': return 'fas fa-sync-alt fa-spin';
        case 'completed': return 'fas fa-check-circle';
        case 'complete': return 'fas fa-check-circle';
        case 'partial': return 'fas fa-chart-pie';
        case 'cancelled': return 'fas fa-times-circle';
        case 'canceled': return 'fas fa-times-circle';
        case 'refunded': return 'fas fa-undo';
        case 'refund': return 'fas fa-undo';
        case 'active': return 'fas fa-play-circle';
        case 'inactive': return 'fas fa-pause-circle';
        default: return 'fas fa-info-circle';
    }
}

function getStatusText($status) {
    switch(strtolower($status)) {
        case 'pending': return 'Bekliyor';
        case 'processing': return 'Hazırlanıyor';
        case 'in progress': return 'Hazırlanıyor';
        case 'progress': return 'Hazırlanıyor';
        case 'completed': return 'Tamamlandı';
        case 'complete': return 'Tamamlandı';
        case 'partial': return 'Kısmi';
        case 'cancelled': return 'İptal Edildi';
        case 'canceled': return 'İptal Edildi';
        case 'refunded': return 'İade Edildi';
        case 'refund': return 'İade Edildi';
        case 'active': return 'Aktif';
        case 'inactive': return 'Pasif';
        default: return ucfirst($status);
    }
}

function updateOrderStatusFromAPI($pdo, $api_url, $api_key, $user_id) {
    try {
        $stmt = $pdo->prepare("SELECT id, order_id, api_order_id, status, service_name FROM orders WHERE user_id = ? AND status IN ('pending', 'processing', 'in progress', 'partial') AND api_order_id IS NOT NULL AND api_order_id != ''");
        $stmt->execute([$user_id]);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($orders as $order) {
            try {
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $api_url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => http_build_query([
                        'key' => $api_key,
                        'action' => 'status',
                        'order' => $order['api_order_id']
                    ]),
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_CONNECTTIMEOUT => 5
                ]);
                
                $response = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                if ($response && $http_code == 200) {
                    $api_data = json_decode($response, true);
                    
                    if (isset($api_data['status'])) {
                        $api_status = strtolower(trim($api_data['status']));
                        
                        $status_map = [
                            'pending' => 'pending',
                            'processing' => 'processing',
                            'in progress' => 'processing',
                            'progress' => 'processing',
                            'completed' => 'completed',
                            'complete' => 'completed',
                            'partial' => 'partial',
                            'cancelled' => 'cancelled',
                            'canceled' => 'cancelled',
                            'refunded' => 'refunded',
                            'refund' => 'refunded'
                        ];
                        
                        $new_status = isset($status_map[$api_status]) ? $status_map[$api_status] : $order['status'];
                        
                        if ($new_status !== $order['status']) {
                            $update_stmt = $pdo->prepare("UPDATE orders SET status = ?, updated_at = NOW() WHERE id = ?");
                            $update_stmt->execute([$new_status, $order['id']]);

                            $notif_title = "Sipariş Güncellendi";
                            $notif_type = "info";
                            $status_text_tr = getStatusText($new_status);
                            
                            if($new_status == 'completed') { 
                                $notif_title = "Sipariş Tamamlandı ✅"; 
                                $notif_type = "success"; 
                            } elseif($new_status == 'processing') { 
                                $notif_title = "Sipariş Hazırlanıyor 🚀"; 
                                $notif_type = "info"; 
                            } elseif($new_status == 'cancelled' || $new_status == 'refunded') { 
                                $notif_title = "Sipariş İptal/İade ❌"; 
                                $notif_type = "warning"; 
                            }

                            $notif_msg = "#{$order['order_id']} nolu {$order['service_name']} siparişinizin durumu güncellendi: {$status_text_tr}";
                            
                            $notif_stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, created_at) VALUES (?, ?, ?, ?, NOW())");
                            $notif_stmt->execute([$user_id, $notif_title, $notif_msg, $notif_type]);
                        }
                    }
                }
            } catch (Exception $e) {
                continue;
            }
        }
    } catch (Exception $e) {
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_order') {
    header('Content-Type: application/json');
    $order_id = $_POST['order_id'];
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE order_id = ? AND user_id = ? AND status = 'pending'");
        $stmt->execute([$order_id, $user['id']]);
        $order = $stmt->fetch();

        if ($order) {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ?");
            $stmt->execute([$order['id']]);
            
            $refund_amount = $order['price'];
            $new_balance = $user['balance'] + $refund_amount;
            
            $stmt = $pdo->prepare("UPDATE users SET balance = ? WHERE id = ?");
            $stmt->execute([$new_balance, $user['id']]);
            
            $stmt = $pdo->prepare("INSERT INTO transactions (user_id, type, amount, description, created_at) VALUES (?, 'refund', ?, ?, NOW())");
            $stmt->execute([$user['id'], $refund_amount, "İade: Sipariş #{$order_id} iptali"]);

            $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, created_at) VALUES (?, ?, ?, 'danger', NOW())");
            $stmt->execute([$user['id'], 'Sipariş İptal Edildi', "#{$order_id} nolu siparişiniz tarafınızca iptal edildi ve tutar bakiyenize iade edildi.", 'danger']);
            
            $pdo->commit();
            echo json_encode(['success' => true, 'refund_amount' => $refund_amount, 'new_balance' => $new_balance]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Sipariş iptal edilemez veya bulunamadı.']);
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Veritabanı hatası.']);
    }
    exit;
}

updateOrderStatusFromAPI($pdo, $api_url, $api_key, $user['id']);

$current_page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$limit = 10;
$offset = ($current_page - 1) * $limit;

$filter_status = isset($_GET['status']) ? $_GET['status'] : 'all';
$filter_category = isset($_GET['category']) ? $_GET['category'] : 'all';
$search_query = isset($_GET['search']) ? $_GET['search'] : '';

$where_conditions = ["user_id = ?"];
$params = [$user['id']];

if ($filter_status !== 'all') {
    $where_conditions[] = "status = ?";
    $params[] = $filter_status;
}

if ($filter_category !== 'all') {
    $where_conditions[] = "category = ?";
    $params[] = $filter_category;
}

if (!empty($search_query)) {
    $where_conditions[] = "(order_id LIKE ? OR service_name LIKE ? OR link LIKE ? OR api_order_id LIKE ?)";
    $search_param = "%{$search_query}%";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
}

$where_sql = implode(' AND ', $where_conditions);

$count_sql = "SELECT COUNT(*) as total FROM orders WHERE {$where_sql}";
$stmt = $pdo->prepare($count_sql);
$stmt->execute($params);
$total_orders = $stmt->fetchColumn();
$total_pages = ceil($total_orders / $limit);

$sql = "SELECT * FROM orders WHERE {$where_sql} ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

$status_counts = [];
$statuses = ['all', 'pending', 'processing', 'completed', 'partial', 'cancelled', 'refunded'];
foreach ($statuses as $status) {
    if ($status === 'all') {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM orders WHERE user_id = ?");
        $stmt->execute([$user['id']]);
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM orders WHERE user_id = ? AND status = ?");
        $stmt->execute([$user['id'], $status]);
    }
    $result = $stmt->fetch();
    $status_counts[$status] = $result['count'];
}

$categories = ['all' => 'Tüm Kategoriler'];
$stmt = $pdo->prepare("SELECT DISTINCT category FROM orders WHERE user_id = ? AND category IS NOT NULL");
$stmt->execute([$user['id']]);
$category_results = $stmt->fetchAll();
foreach ($category_results as $row) {
    $categories[$row['category']] = $row['category'];
}

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

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Siparişlerim - Darq SMM Panel</title>
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

        .notif-bell-container { position: relative; cursor: pointer; margin-right: 10px; }
        .notif-icon { font-size: 1.2rem; color: var(--text-muted); transition: 0.3s; }
        .notif-icon:hover { color: white; }
        .notif-badge { position: absolute; top: -5px; right: -5px; background: var(--danger); color: white; font-size: 0.6rem; padding: 2px 5px; border-radius: 50%; font-weight: 700; border: 1px solid var(--bg-body); }
        .notif-dropdown { position: absolute; top: 50px; right: 0; width: 320px; background: #1e293b; border: var(--glass-border); border-radius: 16px; box-shadow: 0 15px 50px rgba(0,0,0,0.6); display: none; flex-direction: column; z-index: 1001; overflow: hidden; animation: slideDown 0.3s ease; }
        .notif-dropdown.active { display: flex; }
        .notif-header { padding: 15px; border-bottom: 1px solid rgba(255,255,255,0.05); font-weight: 600; color: white; background: rgba(0,0,0,0.2); }
        .notif-body { max-height: 350px; overflow-y: auto; }
        .notif-item { padding: 15px; border-bottom: 1px solid rgba(255,255,255,0.05); transition: 0.2s; display: block; text-decoration: none; }
        .notif-item:hover { background: rgba(255,255,255,0.03); }
        .notif-item.unread { background: rgba(139, 92, 246, 0.05); border-left: 3px solid var(--primary); }
        .notif-title { font-size: 0.9rem; font-weight: 600; color: white; margin-bottom: 4px; }
        .notif-desc { font-size: 0.8rem; color: var(--text-muted); line-height: 1.4; }
        .notif-date { font-size: 0.7rem; color: var(--text-muted); margin-top: 6px; text-align: right; opacity: 0.7; }
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

        .stats-overview { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 40px; }
        .stat-card { background: var(--bg-card); backdrop-filter: blur(15px); border: var(--glass-border); border-radius: 20px; padding: 25px; transition: 0.3s; cursor: pointer; position: relative; overflow: hidden; }
        .stat-card:hover { transform: translateY(-5px); border-color: var(--primary); box-shadow: var(--glow); }
        .stat-card.active { background: rgba(139, 92, 246, 0.15); border-color: var(--primary); }
        .stat-icon { width: 50px; height: 50px; background: rgba(139, 92, 246, 0.1); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: var(--primary); font-size: 1.5rem; margin-bottom: 10px; }
        .stat-content h3 { font-family: 'Outfit', sans-serif; font-size: 2rem; font-weight: 700; margin: 0; line-height: 1; }
        .stat-content p { color: var(--text-muted); font-size: 0.9rem; margin-top: 5px; }

        .content-card { background: var(--bg-card); backdrop-filter: blur(15px); border: var(--glass-border); border-radius: 24px; padding: 30px; margin-bottom: 30px; }
        
        .filters-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 20px; }
        .filter-group { display: flex; flex-direction: column; gap: 8px; }
        .filter-group label { font-weight: 500; color: var(--text-muted); font-size: 0.9rem; }
        .form-control-filter { width: 100%; padding: 12px 15px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.1); border-radius: 12px; color: white; font-size: 0.95rem; transition: var(--transition); }
        .form-control-filter:focus { outline: none; border-color: var(--primary); background: rgba(255,255,255,0.05); }
        .filter-actions { display: flex; gap: 15px; align-items: center; justify-content: space-between; margin-top: 20px; padding-top: 20px; border-top: var(--glass-border); }
        
        .table-glass-container { background: var(--bg-card); backdrop-filter: blur(15px); border-radius: 24px; border: var(--glass-border); overflow: hidden; margin-bottom: 40px; }
        .table-responsive { overflow-x: auto; }
        .modern-table { width: 100%; border-collapse: collapse; }
        .modern-table thead { background: rgba(0, 0, 0, 0.3); }
        .modern-table th { padding: 20px; text-align: left; font-weight: 600; color: var(--text-muted); border-bottom: 1px solid rgba(255, 255, 255, 0.05); white-space: nowrap; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px; }
        .modern-table td { padding: 20px; border-bottom: 1px solid rgba(255, 255, 255, 0.05); color: white; vertical-align: middle; }
        .modern-table tbody tr { transition: 0.2s; }
        .modern-table tbody tr:hover { background: rgba(255, 255, 255, 0.03); }

        .order-id-badge { font-family: 'Outfit', sans-serif; font-weight: 700; color: white; background: rgba(255,255,255,0.05); padding: 5px 10px; border-radius: 8px; font-size: 0.9rem; display: inline-flex; align-items: center; gap: 5px; }
        .api-id-mini { font-size: 0.7rem; color: var(--text-muted); margin-top: 4px; display: block; font-family: monospace; }
        
        .service-mini { display: flex; align-items: center; gap: 12px; }
        .s-icon-mini { width: 36px; height: 36px; background: var(--gradient-main); border-radius: 10px; display: flex; align-items: center; justify-content: center; color: white; flex-shrink: 0; font-size: 1rem; }
        .s-info-mini div { font-weight: 600; font-size: 0.95rem; }
        .s-info-mini span { font-size: 0.75rem; color: var(--text-muted); background: rgba(255,255,255,0.05); padding: 2px 8px; border-radius: 10px; margin-top: 3px; display: inline-block; }

        .link-cell a { color: var(--primary); text-decoration: none; transition: 0.2s; display: flex; align-items: center; gap: 5px; font-size: 0.9rem; }
        .link-cell a:hover { color: white; }

        .status-badge { padding: 6px 12px; border-radius: 20px; font-size: 0.8rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; }
        .status-pending { background: rgba(245, 158, 11, 0.15); color: #F59E0B; border: 1px solid rgba(245, 158, 11, 0.2); }
        .status-processing { background: rgba(59, 130, 246, 0.15); color: #3B82F6; border: 1px solid rgba(59, 130, 246, 0.2); }
        .status-completed { background: rgba(16, 185, 129, 0.15); color: #10B981; border: 1px solid rgba(16, 185, 129, 0.2); }
        .status-partial { background: rgba(139, 92, 246, 0.15); color: #8B5CF6; border: 1px solid rgba(139, 92, 246, 0.2); }
        .status-cancelled { background: rgba(239, 68, 68, 0.15); color: #EF4444; border: 1px solid rgba(239, 68, 68, 0.2); }
        .status-refunded { background: rgba(148, 163, 184, 0.15); color: #94A3B8; border: 1px solid rgba(148, 163, 184, 0.2); }

        .action-btn-group { display: flex; gap: 8px; }
        .action-btn-small { width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; border: 1px solid rgba(255,255,255,0.1); background: rgba(255,255,255,0.03); color: var(--text-muted); cursor: pointer; transition: 0.2s; }
        .action-btn-small:hover { background: var(--primary); color: white; border-color: var(--primary); }
        .action-btn-small.cancel:hover { background: #EF4444; border-color: #EF4444; }
        .action-btn-small:disabled { opacity: 0.3; cursor: not-allowed; }

        .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 30px; flex-wrap: wrap; }
        .page-link { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; background: var(--bg-card); border: 1px solid rgba(255,255,255,0.1); color: white; cursor: pointer; transition: 0.2s; }
        .page-link:hover, .page-link.active { background: var(--primary); border-color: var(--primary); box-shadow: var(--glow); }
        .page-link:disabled { opacity: 0.5; cursor: not-allowed; }

        .footer { padding: 40px 0; border-top: var(--glass-border); margin-top: 50px; text-align: center; color: var(--text-muted); font-size: 0.9rem; }
        @keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }

        /* SweetAlert Customization */
        .swal2-popup { background: #1e293b !important; border: 1px solid rgba(255,255,255,0.1) !important; border-radius: 20px !important; color: white !important; font-family: 'Plus Jakarta Sans', sans-serif !important; }
        .swal2-title { color: white !important; font-family: 'Outfit', sans-serif !important; }
        .swal2-html-container { color: #94a3b8 !important; }
        .swal2-confirm { background: var(--gradient-main) !important; box-shadow: var(--glow) !important; border-radius: 12px !important; }
        .swal2-cancel { background: transparent !important; border: 1px solid #ef4444 !important; color: #ef4444 !important; border-radius: 12px !important; }
        .swal-detail-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid rgba(255,255,255,0.05); color: #94a3b8; font-size: 0.95rem; }
        .swal-detail-row span:last-child { color: white; font-weight: 600; text-align: right; }

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
                <a href="services.php"><i class="fas fa-box"></i> Hizmetler</a>
                <a href="orders.php" class="active"><i class="fas fa-history"></i> Siparişler</a>
                <a href="balance.php"><i class="fas fa-wallet"></i> Bakiye</a>
                <a href="support.php"><i class="fas fa-headset"></i> Destek</a>
                <?php if ($is_admin): ?>
                <a href="admin_dashboard.php"><i class="fas fa-user-shield"></i> Admin Panel</a>
                <?php endif; ?>
            </div>
            <div class="user-menu">
                <div class="notif-bell-container" onclick="toggleNotifications()">
                    <i class="fas fa-bell notif-icon"></i>
                    <?php if($unread_count > 0): ?>
                        <span class="notif-badge" id="notifBadge"><?php echo $unread_count; ?></span>
                    <?php endif; ?>
                    
                    <div class="notif-dropdown" id="notifDropdown">
                        <div class="notif-header">Son Bildirimler</div>
                        <div class="notif-body">
                            <?php if(empty($notifications)): ?>
                                <div class="notif-empty">Henüz bildirim yok.</div>
                            <?php else: ?>
                                <?php foreach($notifications as $notif): ?>
                                    <a href="javascript:void(0)" class="notif-item <?php echo $notif['is_read'] ? '' : 'unread'; ?>">
                                        <div class="notif-title"><?php echo htmlspecialchars($notif['title']); ?></div>
                                        <div class="notif-desc"><?php echo htmlspecialchars($notif['message']); ?></div>
                                        <div class="notif-date"><?php echo date('d.m H:i', strtotime($notif['created_at'])); ?></div>
                                    </a>
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
                <h1>Sipariş <span class="gradient-text">Geçmişi</span></h1>
                <p style="color: var(--text-muted);">Tüm işlemlerinizi buradan takip edin ve yönetin.</p>
            </div>
            <button class="btn btn-primary" onclick="location.reload()">
                <i class="fas fa-sync-alt"></i> Yenile
            </button>
        </div>

        <div class="stats-overview">
            <div class="stat-card <?php echo $filter_status === 'all' ? 'active' : ''; ?>" onclick="filterByStatus('all')">
                <div class="stat-icon"><i class="fas fa-boxes"></i></div>
                <div class="stat-content"><h3><?php echo $status_counts['all']; ?></h3><p>Toplam</p></div>
            </div>
            <div class="stat-card <?php echo $filter_status === 'pending' ? 'active' : ''; ?>" onclick="filterByStatus('pending')">
                <div class="stat-icon"><i class="fas fa-clock"></i></div>
                <div class="stat-content"><h3><?php echo $status_counts['pending']; ?></h3><p>Bekleyen</p></div>
            </div>
            <div class="stat-card <?php echo $filter_status === 'processing' ? 'active' : ''; ?>" onclick="filterByStatus('processing')">
                <div class="stat-icon"><i class="fas fa-sync-alt fa-spin"></i></div>
                <div class="stat-content"><h3><?php echo $status_counts['processing']; ?></h3><p>İşleniyor</p></div>
            </div>
            <div class="stat-card <?php echo $filter_status === 'completed' ? 'active' : ''; ?>" onclick="filterByStatus('completed')">
                <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                <div class="stat-content"><h3><?php echo $status_counts['completed']; ?></h3><p>Tamamlanan</p></div>
            </div>
        </div>

        <div class="content-card">
            <form method="GET" action="" id="filterForm">
                <div class="filters-grid">
                    <div class="filter-group">
                        <label for="search"><i class="fas fa-search"></i> Sipariş Ara</label>
                        <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search_query); ?>" class="form-control-filter" placeholder="Sipariş ID, link...">
                    </div>
                    <div class="filter-group">
                        <label for="status"><i class="fas fa-filter"></i> Durum</label>
                        <select id="status" name="status" class="form-control-filter">
                            <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>Tümü</option>
                            <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>Bekleyen</option>
                            <option value="processing" <?php echo $filter_status === 'processing' ? 'selected' : ''; ?>>İşleniyor</option>
                            <option value="completed" <?php echo $filter_status === 'completed' ? 'selected' : ''; ?>>Tamamlanan</option>
                            <option value="partial" <?php echo $filter_status === 'partial' ? 'selected' : ''; ?>>Kısmi</option>
                            <option value="cancelled" <?php echo $filter_status === 'cancelled' ? 'selected' : ''; ?>>İptal</option>
                            <option value="refunded" <?php echo $filter_status === 'refunded' ? 'selected' : ''; ?>>İade</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="category"><i class="fas fa-tags"></i> Kategori</label>
                        <select id="category" name="category" class="form-control-filter">
                            <?php foreach ($categories as $key => $name): ?>
                                <option value="<?php echo $key; ?>" <?php echo $filter_category === $key ? 'selected' : ''; ?>><?php echo $name; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filtrele</button>
                    <a href="orders.php" class="btn btn-outline" style="text-decoration:none;"><i class="fas fa-undo"></i> Temizle</a>
                </div>
            </form>
        </div>

        <div class="table-glass-container">
            <div class="table-responsive">
                <table class="modern-table">
                    <thead>
                        <tr>
                            <th>Sipariş ID</th>
                            <th>Servis Detayı</th>
                            <th>Link</th>
                            <th>Miktar</th>
                            <th>Tutar</th>
                            <th>Durum</th>
                            <th>Tarih</th>
                            <th>İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($orders)): ?>
                            <?php foreach ($orders as $order): ?>
                                <?php
                                $status_class = 'status-' . $order['status'];
                                $status_text = getStatusText($order['status']);
                                $status_icon = getStatusIcon($order['status']);
                                $service_icon = 'fas fa-star';
                                if(strpos($order['category'], 'Instagram') !== false) $service_icon = 'fab fa-instagram';
                                elseif(strpos($order['category'], 'TikTok') !== false) $service_icon = 'fab fa-tiktok';
                                elseif(strpos($order['category'], 'YouTube') !== false) $service_icon = 'fab fa-youtube';
                                elseif(strpos($order['category'], 'Twitter') !== false) $service_icon = 'fab fa-twitter';
                                elseif(strpos($order['category'], 'Facebook') !== false) $service_icon = 'fab fa-facebook';
                                elseif(strpos($order['category'], 'Spotify') !== false) $service_icon = 'fab fa-spotify';
                                elseif(strpos($order['category'], 'Telegram') !== false) $service_icon = 'fab fa-telegram';
                                ?>
                                <tr data-order-id="<?php echo $order['order_id']; ?>">
                                    <td>
                                        <div class="order-id-badge">
                                            #<?php echo htmlspecialchars($order['order_id']); ?>
                                        </div>
                                        <?php if ($order['api_order_id']): ?>
                                            <span class="api-id-mini"><i class="fas fa-code"></i> <?php echo htmlspecialchars($order['api_order_id']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="service-mini">
                                            <div class="s-icon-mini"><i class="<?php echo $service_icon; ?>"></i></div>
                                            <div class="s-info-mini">
                                                <div><?php echo htmlspecialchars(mb_substr($order['service_name'], 0, 30)) . (mb_strlen($order['service_name']) > 30 ? '...' : ''); ?></div>
                                                <span><?php echo htmlspecialchars($order['category']); ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="link-cell">
                                        <a href="<?php echo htmlspecialchars($order['link']); ?>" target="_blank">
                                            <i class="fas fa-external-link-alt"></i> Linke Git
                                        </a>
                                    </td>
                                    <td style="font-weight:600;"><?php echo number_format($order['quantity']); ?></td>
                                    <td style="color:#F59E0B; font-weight:700;">₺<?php echo number_format($order['price'], 2); ?></td>
                                    <td>
                                        <div class="status-badge <?php echo $status_class; ?>">
                                            <i class="<?php echo $status_icon; ?>"></i> <?php echo $status_text; ?>
                                        </div>
                                    </td>
                                    <td style="color:var(--text-muted); font-size:0.85rem;">
                                        <?php echo date('d.m.Y H:i', strtotime($order['created_at'])); ?>
                                    </td>
                                    <td>
                                        <div class="action-btn-group">
                                            <button class="action-btn-small" onclick="viewOrderDetails('<?php echo $order['order_id']; ?>')" title="Detaylar">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <?php if ($order['status'] === 'pending'): ?>
                                                <button class="action-btn-small cancel" onclick="confirmCancelOrder('<?php echo $order['order_id']; ?>', '<?php echo htmlspecialchars(addslashes($order['service_name'])); ?>', <?php echo $order['price']; ?>)" title="İptal Et">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            <?php else: ?>
                                                <button class="action-btn-small" disabled title="İptal Edilemez">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" style="text-align:center; padding: 60px;">
                                    <i class="fas fa-folder-open" style="font-size: 3rem; color: var(--text-muted); margin-bottom: 15px;"></i>
                                    <h3 style="color:white; margin-bottom:10px;">Sipariş Bulunamadı</h3>
                                    <p style="color:var(--text-muted);">Arama kriterlerinize uygun kayıt yok.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <button class="page-link" onclick="goToPage(1)" <?php echo $current_page == 1 ? 'disabled' : ''; ?>><i class="fas fa-angle-double-left"></i></button>
                <button class="page-link" onclick="goToPage(<?php echo max(1, $current_page - 1); ?>)" <?php echo $current_page == 1 ? 'disabled' : ''; ?>><i class="fas fa-chevron-left"></i></button>
                
                <?php for ($i = max(1, $current_page - 2); $i <= min($total_pages, $current_page + 2); $i++): ?>
                    <button class="page-link <?php echo $i == $current_page ? 'active' : ''; ?>" onclick="goToPage(<?php echo $i; ?>)"><?php echo $i; ?></button>
                <?php endfor; ?>
                
                <button class="page-link" onclick="goToPage(<?php echo min($total_pages, $current_page + 1); ?>)" <?php echo $current_page == $total_pages ? 'disabled' : ''; ?>><i class="fas fa-chevron-right"></i></button>
                <button class="page-link" onclick="goToPage(<?php echo $total_pages; ?>)" <?php echo $current_page == $total_pages ? 'disabled' : ''; ?>><i class="fas fa-angle-double-right"></i></button>
            </div>
        <?php endif; ?>
    </div>

    <footer class="footer">
        <div class="container">
            <p>&copy; 2026 Darq SMM Panel. Tüm hakları saklıdır.</p>
        </div>
    </footer>

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
                    fetch('orders.php', {
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
            const wrapper = document.querySelector('.notif-bell-container');
            const dd = document.getElementById('notifDropdown');
            if (wrapper && !wrapper.contains(e.target)) {
                dd.classList.remove('active');
            }
        });

        let ordersData = <?php echo json_encode($orders); ?>;
        
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

        function filterByStatus(status) {
            window.location.href = `orders.php?status=${status}`;
        }

        function goToPage(page) {
            const url = new URL(window.location.href);
            url.searchParams.set('page', page);
            window.location.href = url.toString();
        }

        function viewOrderDetails(orderId) {
            const order = ordersData.find(o => o.order_id == orderId);
            if (!order) return;
            
            let detailsHtml = `
                <div style="text-align: left;">
                    <h4 style="color:#8B5CF6; margin-bottom:15px; font-family:'Outfit';">#${order.order_id}</h4>
                    <div style="background:rgba(255,255,255,0.03); padding:15px; border-radius:12px; border:1px solid rgba(255,255,255,0.05);">
                        <div class="swal-detail-row"><span>Servis:</span><span>${order.service_name}</span></div>
                        <div class="swal-detail-row"><span>Kategori:</span><span>${order.category}</span></div>
                        <div class="swal-detail-row"><span>Link:</span><span style="max-width:200px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${order.link}</span></div>
                        <div class="swal-detail-row"><span>Miktar:</span><span>${parseInt(order.quantity).toLocaleString()}</span></div>
                        <div class="swal-detail-row"><span>Tutar:</span><span style="color:#F59E0B">₺${parseFloat(order.price).toFixed(2)}</span></div>
                        <div class="swal-detail-row"><span>Tarih:</span><span>${new Date(order.created_at).toLocaleString('tr-TR')}</span></div>
                    </div>
                </div>
            `;
            
            swalWithTheme.fire({
                title: 'Sipariş Detayı',
                html: detailsHtml,
                showConfirmButton: true,
                confirmButtonText: 'Kapat'
            });
        }

        function confirmCancelOrder(orderId, serviceName, price) {
            swalWithTheme.fire({
                title: 'Siparişi İptal Et?',
                html: `Bu işlem geri alınamaz. <b style="color:#F59E0B">₺${price.toFixed(2)}</b> tutar bakiyenize iade edilecektir.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Evet, İptal Et',
                cancelButtonText: 'Vazgeç',
                confirmButtonColor: '#EF4444'
            }).then((result) => {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('action', 'cancel_order');
                    formData.append('order_id', orderId);
                    
                    fetch('orders.php', { method: 'POST', body: formData })
                    .then(r => r.json())
                    .then(data => {
                        if(data.success) {
                            swalWithTheme.fire({
                                icon: 'success',
                                title: 'İptal Edildi!',
                                html: `Sipariş iptal edildi ve <b style="color:#10B981">₺${data.refund_amount.toFixed(2)}</b> iade edildi.<br>Yeni Bakiye: <b>₺${data.new_balance.toFixed(2)}</b>`,
                                confirmButtonText: 'Tamam'
                            }).then(() => location.reload());
                        } else {
                            swalWithTheme.fire('Hata', data.message, 'error');
                        }
                    })
                    .catch(() => swalWithTheme.fire('Hata', 'İşlem sırasında bir hata oluştu.', 'error'));
                }
            });
        }
    </script>
</body>
</html>
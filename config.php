<?php
ob_start();

$sessionPath = '/tmp';
if (!file_exists($sessionPath)) {
    @mkdir($sessionPath, 0777, true);
}
ini_set('session.save_path', $sessionPath);
ini_set('session.gc_probability', 1);
ini_set('session.gc_divisor', 100);
ini_set('session.gc_maxlifetime', 86400);

session_start();

date_default_timezone_set('Europe/Istanbul');

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'hexdarq_smm');

define('SITE_NAME', 'Darq SMM Panel');
define('SITE_URL', 'http://localhost/hexdarq');

define('EXCHANGE_RATE_USD_TRY', 32.50);
define('PROFIT_MARGIN', 50);

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    die("Veritabanı bağlantı hatası");
}

if (!isset($_SESSION['user_id']) && isset($_COOKIE['remember_token'])) {
    $token = $_COOKIE['remember_token'];
    $stmt = $pdo->prepare("SELECT * FROM users WHERE remember_token = ?");
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    
    if ($user) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['user_role'] = $user['user_role'];
        $_SESSION['balance'] = $user['balance'];
        $_SESSION['api_key'] = $user['api_key'];
    }
}

function sanitize($data) {
    if (is_array($data)) {
        return array_map('sanitize', $data);
    }
    if (!is_string($data)) {
        return '';
    }
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

function generate_csrf_token() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validate_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function generate_api_key() {
    return 'hex_' . bin2hex(random_bytes(16));
}

function get_exchange_rate_from_db($pdo) {
    try {
        $stmt = $pdo->prepare("SELECT value FROM settings WHERE setting_key = 'exchange_rate'");
        $stmt->execute();
        $result = $stmt->fetch();
        
        if ($result && isset($result['value'])) {
            return floatval($result['value']);
        }
        
        return EXCHANGE_RATE_USD_TRY;
    } catch (Exception $e) {
        return EXCHANGE_RATE_USD_TRY;
    }
}

function get_api_exchange_rate() {
    global $pdo;
    return get_exchange_rate_from_db($pdo);
}

function update_exchange_rate() {
    try {
        $url = 'https://api.exchangerate-api.com/v4/latest/USD';
        $ch = curl_init();
        
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code == 200 && $response) {
            $data = json_decode($response, true);
            if (isset($data['rates']['TRY'])) {
                $rate = floatval($data['rates']['TRY']);
                
                global $pdo;
                $stmt = $pdo->prepare("INSERT INTO settings (setting_key, value, updated_at) VALUES ('exchange_rate', ?, NOW()) ON DUPLICATE KEY UPDATE value = ?, updated_at = NOW()");
                $stmt->execute([$rate, $rate]);
                
                return $rate;
            }
        }
        
        return EXCHANGE_RATE_USD_TRY;
    } catch (Exception $e) {
        return EXCHANGE_RATE_USD_TRY;
    }
}

function check_session() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
}

function get_user_role($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT user_role FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    return $user['user_role'] ?? 'user';
}

function validate_input_type($input) {
    if (is_array($input)) {
        return false;
    }
    return true;
}

function secure_password_hash($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

function validate_password($password, $hashed_password) {
    return password_verify($password, $hashed_password);
}

function get_client_ip() {
    $ip_keys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];
    foreach ($ip_keys as $key) {
        if (array_key_exists($key, $_SERVER) === true) {
            foreach (explode(',', $_SERVER[$key]) as $ip) {
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                    return $ip;
                }
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function log_activity($pdo, $user_id, $action, $details = '') {
    $ip = get_client_ip();
    $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
    $stmt->execute([$user_id, $action, $details, $ip]);
}

function rate_limit_check($pdo, $ip, $limit = 10, $time_window = 3600) {
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM rate_limits WHERE ip_address = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)");
    $stmt->execute([$ip, $time_window]);
    $result = $stmt->fetch();
    
    if ($result['count'] >= $limit) {
        return false;
    }
    
    $stmt = $pdo->prepare("INSERT INTO rate_limits (ip_address) VALUES (?)");
    $stmt->execute([$ip]);
    
    return true;
}

function get_system_stats($pdo) {
    $stats = [
        'users' => 1500,
        'orders' => 50000,
        'services' => 500
    ];

    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM users");
        $cnt = $stmt->fetchColumn();
        if ($cnt > 0) $stats['users'] = $cnt;

        $stmt = $pdo->query("SHOW TABLES LIKE 'orders'");
        if ($stmt->rowCount() > 0) {
            $stmt = $pdo->query("SELECT COUNT(*) FROM orders");
            $cnt = $stmt->fetchColumn();
            if ($cnt > 0) $stats['orders'] = $cnt;
        }

        $stmt = $pdo->query("SHOW TABLES LIKE 'services'");
        if ($stmt->rowCount() > 0) {
            $stmt = $pdo->query("SELECT COUNT(*) FROM services");
            $cnt = $stmt->fetchColumn();
            if ($cnt > 0) $stats['services'] = $cnt;
        }
        
        if ($stats['orders'] < 1000) {
            $stats['orders'] = $stats['users'] * 12 + 1500;
        }

    } catch (Exception $e) {}

    return $stats;
}
?>
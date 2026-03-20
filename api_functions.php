<?php
class RevamedyaAPI {
    private $api_key = '14fd5712a199e44cdd0412ec5e33d744';
    private $base_url = 'https://takipcinizbizden.com/api/v2';
    
    public function __construct($api_key = null) {
        if ($api_key) {
            $this->api_key = $api_key;
        }
    }
    
    public function getBalanceTRY() {
        try {
            $balance_data = $this->getBalance();
            
            if (isset($balance_data['balance'])) {
                $usd_balance = floatval($balance_data['balance']);
            } else {
                $usd_balance = 0;
            }
            
            $exchange_rate = get_api_exchange_rate();
            $try_balance = $usd_balance * $exchange_rate;
            
            return [
                'success' => true,
                'usd' => $usd_balance,
                'try' => $try_balance,
                'exchange_rate' => $exchange_rate
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'usd' => 0,
                'try' => 0,
                'exchange_rate' => EXCHANGE_RATE_USD_TRY
            ];
        }
    }
    
    public function getBalance() {
        $url = $this->base_url;
        $post_data = [
            'key' => $this->api_key,
            'action' => 'balance'
        ];
        
        $result = $this->makeRequest($url, $post_data);
        
        return $result;
    }
    
    public function getServices() {
        $url = $this->base_url;
        $post_data = [
            'key' => $this->api_key,
            'action' => 'services'
        ];
        
        return $this->makeRequest($url, $post_data);
    }
    
    public function createOrder($service_id, $link, $quantity) {
        $url = $this->base_url;
        $post_data = [
            'key' => $this->api_key,
            'action' => 'add',
            'service' => $service_id,
            'link' => $link,
            'quantity' => $quantity
        ];
        
        return $this->makeRequest($url, $post_data);
    }
    
    public function getOrderStatus($order_id) {
        $url = $this->base_url;
        $post_data = [
            'key' => $this->api_key,
            'action' => 'status',
            'order' => $order_id
        ];
        
        return $this->makeRequest($url, $post_data);
    }
    
    public function getMultiOrderStatus($order_ids) {
        $url = $this->base_url;
        $post_data = [
            'key' => $this->api_key,
            'action' => 'status',
            'orders' => implode(',', (array)$order_ids)
        ];
        
        return $this->makeRequest($url, $post_data);
    }
    
    private function makeRequest($url, $post_data = null) {
        $ch = curl_init();
        
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        
        if ($post_data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
        }
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'User-Agent: DarqSMM/1.0'
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($response) {
            $result = json_decode($response, true);
            
            if (json_last_error() === JSON_ERROR_NONE) {
                return $result;
            } else {
                return [
                    'error' => true,
                    'message' => 'Geçersiz JSON yanıtı',
                    'raw_response' => $response
                ];
            }
        }
        
        return [
            'error' => true,
            'message' => 'API bağlantı hatası: ' . $error,
            'http_code' => $http_code,
            'status' => 'error'
        ];
    }
}

function sync_services_from_api($pdo) {
    try {
        $api = new RevamedyaAPI();
        $services = $api->getServices();
        
        if (isset($services['error']) && $services['error']) {
            return [
                'success' => false,
                'message' => 'API bağlantı hatası: ' . ($services['message'] ?? 'Bilinmeyen hata')
            ];
        }
        
        $pdo->exec("DELETE FROM services");
        
        $inserted = 0;
        if (is_array($services)) {
            foreach ($services as $service) {
                if (isset($service['service'])) {
                    $service_id = $service['service'];
                    $name = $service['name'] ?? 'Unknown';
                    $category = $service['category'] ?? 'Other';
                    $rate_per_1000 = $service['rate'] ?? 0;
                    
                    $exchange_rate = get_api_exchange_rate();
                    $price_per_1000_try = $rate_per_1000 * $exchange_rate * 1.5;
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO services (service_id, name, category, rate_per_1000, price_per_1000, min, max, description, status) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')
                    ");
                    
                    $stmt->execute([
                        $service_id,
                        $name,
                        $category,
                        $rate_per_1000,
                        $price_per_1000_try,
                        $service['min'] ?? 100,
                        $service['max'] ?? 10000,
                        $service['description'] ?? $name . ' ' . $category . ' hizmeti'
                    ]);
                    
                    $inserted++;
                }
            }
        }
        
        return [
            'success' => true,
            'message' => $inserted . ' hizmet başarıyla senkronize edildi.',
            'count' => $inserted
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Senkronizasyon hatası: ' . $e->getMessage()
        ];
    }
}

function calculate_profit($api_price_usd, $selling_price_try, $quantity, $exchange_rate = 32.00) {
    $api_cost_try = ($api_price_usd * $exchange_rate * $quantity) / 1000;
    $revenue_try = $selling_price_try;
    
    $profit_try = $revenue_try - $api_cost_try;
    $profit_percentage = ($api_cost_try > 0) ? (($profit_try / $api_cost_try) * 100) : 0;
    
    return [
        'profit_try' => $profit_try,
        'profit_percentage' => $profit_percentage,
        'api_cost_try' => $api_cost_try,
        'revenue_try' => $revenue_try
    ];
}

function create_order_through_api($service_id, $link, $quantity) {
    try {
        $api = new RevamedyaAPI();
        return $api->createOrder($service_id, $link, $quantity);
    } catch (Exception $e) {
        return [
            'error' => true,
            'message' => 'Sipariş oluşturma hatası: ' . $e->getMessage()
        ];
    }
}

function get_order_status_from_api($order_id) {
    try {
        $api = new RevamedyaAPI();
        return $api->getOrderStatus($order_id);
    } catch (Exception $e) {
        return [
            'error' => true,
            'message' => 'Sipariş durumu sorgulama hatası: ' . $e->getMessage()
        ];
    }
}

function check_api_connection() {
    try {
        $api = new RevamedyaAPI();
        $balance = $api->getBalance();
        
        if (isset($balance['error']) && $balance['error']) {
            return [
                'success' => false,
                'message' => $balance['message'] ?? 'API bağlantı hatası'
            ];
        }
        
        return [
            'success' => true,
            'message' => 'API bağlantısı başarılı',
            'balance' => $balance['balance'] ?? 0
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'API bağlantı hatası: ' . $e->getMessage()
        ];
    }
}
?>
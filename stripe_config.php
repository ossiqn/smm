<?php
// Stripe API Anahtarları
define('STRIPE_PUBLISHABLE_KEY', 'pk_test_51SvAo1E8VDaknj7E6HwjtyqIyTxWic6AA3kj2nsnnn19JyGz2cBuCOaBaR7GXkOfJQzoC0l5BpuriRSIJZDPWroi00bbo09ESB');
define('STRIPE_SECRET_KEY', 'sk_test_51SvAo1E8VDaknj7EkUP7oqsVmN9PnEwW2tdxU5l9iRCDRu21hVYA1Bynp1UhkE6f4E2MBwH5uEqnchddtO7n5I1m00Cj8L0U9z');

// Stripe API Fonksiyonları - CURL ile
function createStripeCheckout($amount, $user_id, $username, $email) {
    $ch = curl_init();
    
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://api.stripe.com/v1/checkout/sessions',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . STRIPE_SECRET_KEY,
            'Content-Type: application/x-www-form-urlencoded'
        ],
        CURLOPT_POSTFIELDS => http_build_query([
            'payment_method_types[]' => 'card',
            'line_items[0][price_data][currency]' => 'try',
            'line_items[0][price_data][product_data][name]' => 'Darq SMM Panel Bakiye Yükleme',
            'line_items[0][price_data][product_data][description]' => $username . ' için bakiye yükleme',
            'line_items[0][price_data][unit_amount]' => $amount * 100,
            'line_items[0][quantity]' => 1,
            'mode' => 'payment',
            'success_url' => 'http://' . $_SERVER['HTTP_HOST'] . '/balance.php?success=true&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => 'http://' . $_SERVER['HTTP_HOST'] . '/balance.php?canceled=true',
            'customer_email' => $email,
            'metadata[user_id]' => $user_id,
            'metadata[username]' => $username
        ])
    ]);
    
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['error' => $error];
    }
    
    return json_decode($response, true);
}

function getStripeSession($session_id) {
    $ch = curl_init();
    
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://api.stripe.com/v1/checkout/sessions/' . $session_id,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . STRIPE_SECRET_KEY
        ]
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}

function getStripePaymentIntent($payment_intent_id) {
    $ch = curl_init();
    
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://api.stripe.com/v1/payment_intents/' . $payment_intent_id,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . STRIPE_SECRET_KEY
        ]
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}
?>
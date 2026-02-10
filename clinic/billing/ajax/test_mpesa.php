<?php
require_once '../../includes/inc_all.php';

function testMpesaConnection() {
    global $mpesa_config;
    
    // Try to get access token (simplest test)
    $endpoint = ($mpesa_config['env'] == 'live') ? 
                'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials' :
                'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
    
    $credentials = base64_encode($mpesa_config['consumer_key'] . ':' . $mpesa_config['consumer_secret']);
    
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['Authorization: Basic ' . $credentials],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 10
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $result = json_decode($response, true);
        if (isset($result['access_token'])) {
            return ['success' => true, 'message' => 'M-Pesa API connection successful'];
        }
    }
    
    return ['success' => false, 'error' => 'Failed to connect to M-Pesa API'];
}

$result = testMpesaConnection();
header('Content-Type: application/json');
echo json_encode($result);
?>
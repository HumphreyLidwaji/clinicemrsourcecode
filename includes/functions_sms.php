<?php
// includes/functions_sms.php

function sendSMS($phone, $message) {
    global $config_sms_provider, $config_sms_api_key, $config_sms_username, $config_sms_password, $config_sms_sender_id;
    
    // Clean phone number
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($phone) == 9 && substr($phone, 0, 1) == '7') {
        $phone = '254' . $phone;
    }
    
    switch ($config_sms_provider) {
        case 'africastalking':
            return sendViaAfricasTalking($phone, $message);
        case 'safaricom':
            return sendViaSafaricom($phone, $message);
        case 'at_sms':
            return sendViaATSMS($phone, $message);
        default:
            return "SMS provider not configured";
    }
}

function sendViaAfricasTalking($phone, $message) {
    global $config_sms_api_key, $config_sms_username, $config_sms_sender_id;
    
    $url = 'https://api.africastalking.com/version1/messaging';
    
    $data = [
        'username' => $config_sms_username,
        'to' => $phone,
        'message' => $message,
        'from' => $config_sms_sender_id
    ];
    
    $headers = [
        'ApiKey: ' . $config_sms_api_key,
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode == 201) {
        return true;
    } else {
        return "Failed: " . $response;
    }
}

// Add similar functions for other providers...
?>
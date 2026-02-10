<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Only allow AJAX requests
if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
    header('HTTP/1.0 403 Forbidden');
    exit('Direct access not allowed');
}

header('Content-Type: application/json');

// Validate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    echo json_encode(['success' => false, 'error' => 'Session expired']);
    exit;
}

$checkout_id = sanitizeInput($_GET['checkout_id'] ?? '');

if (empty($checkout_id)) {
    echo json_encode(['success' => false, 'error' => 'Invalid checkout ID']);
    exit;
}

// Get transaction details
$sql = "SELECT 
            pt.*,
            ml.request_data,
            ml.response_data,
            c.result_code,
            c.result_desc,
            c.mpesa_receipt,
            c.amount as callback_amount,
            c.phone_number as callback_phone,
            c.transaction_date,
            c.created_at as callback_time
        FROM mpesa_pending_transactions pt
        LEFT JOIN mpesa_logs ml ON pt.log_id = ml.log_id
        LEFT JOIN mpesa_callbacks c ON pt.checkout_request_id = c.checkout_request_id
        WHERE pt.checkout_request_id = ?
        ORDER BY c.created_at DESC
        LIMIT 1";

$stmt = mysqli_prepare($mysqli, $sql);
mysqli_stmt_bind_param($stmt, 's', $checkout_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$transaction = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$transaction) {
    echo json_encode(['success' => false, 'error' => 'Transaction not found']);
    exit;
}

// Check if user has permission to view this transaction (via invoice)
$permission_sql = "SELECT i.invoice_id 
                   FROM invoices i
                   JOIN mpesa_pending_transactions pt ON i.invoice_id = pt.invoice_id
                   WHERE pt.checkout_request_id = ?";
$permission_stmt = mysqli_prepare($mysqli, $permission_sql);
mysqli_stmt_bind_param($permission_stmt, 's', $checkout_id);
mysqli_stmt_execute($permission_stmt);
$permission_result = mysqli_stmt_get_result($permission_stmt);
$permission = mysqli_fetch_assoc($permission_result);
mysqli_stmt_close($permission_stmt);

if (!$permission) {
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

// Check Safaricom API for latest status
$api_status = checkMpesaApiStatusDetailed($mysqli, $checkout_id);

// Format response
$response = [
    'success' => true,
    'transaction' => [
        'checkout_request_id' => $transaction['checkout_request_id'],
        'merchant_request_id' => $transaction['merchant_request_id'],
        'amount' => $transaction['amount'],
        'phone_number' => $transaction['phone_number'],
        'formatted_phone' => formatPhoneDisplay($transaction['phone_number']),
        'status' => $transaction['status'],
        'created_at' => $transaction['created_at'],
        'updated_at' => $transaction['updated_at'],
        'payment_id' => $transaction['payment_id'],
        'invoice_id' => $transaction['invoice_id']
    ],
    'api_check' => $api_status
];

// Add callback details if available
if ($transaction['result_code'] !== null) {
    $response['callback'] = [
        'result_code' => $transaction['result_code'],
        'result_desc' => $transaction['result_desc'],
        'mpesa_receipt' => $transaction['mpesa_receipt'],
        'amount' => $transaction['callback_amount'],
        'phone_number' => $transaction['callback_phone'],
        'transaction_date' => $transaction['transaction_date'],
        'callback_time' => $transaction['callback_time']
    ];
    
    // Determine final status from callback
    if ($transaction['result_code'] == 0) {
        $response['transaction']['final_status'] = 'completed';
        $response['transaction']['receipt_number'] = $transaction['mpesa_receipt'];
    } else {
        $response['transaction']['final_status'] = 'failed';
    }
}

// Check if payment was already recorded
if ($transaction['payment_id']) {
    $payment_sql = "SELECT 
                        p.payment_number,
                        p.payment_amount,
                        p.payment_date,
                        p.accounting_status
                    FROM payments p
                    WHERE p.payment_id = ?";
    $payment_stmt = mysqli_prepare($mysqli, $payment_sql);
    mysqli_stmt_bind_param($payment_stmt, 'i', $transaction['payment_id']);
    mysqli_stmt_execute($payment_stmt);
    $payment_result = mysqli_stmt_get_result($payment_stmt);
    $payment = mysqli_fetch_assoc($payment_result);
    mysqli_stmt_close($payment_stmt);
    
    if ($payment) {
        $response['payment_record'] = $payment;
    }
}

// Update local status based on API if different
if ($api_status['success'] && isset($api_status['result']['ResultCode'])) {
    $api_result_code = $api_status['result']['ResultCode'];
    
    if ($api_result_code == 0) {
        // Payment completed according to API
        if ($transaction['status'] !== 'completed') {
            updateMpesaTransactionStatusDetailed($mysqli, $checkout_id, 'completed', $api_status['result']);
            $response['transaction']['status'] = 'completed';
            $response['transaction']['status_updated'] = true;
            
            // Extract receipt number from API response
            if (isset($api_status['result']['CallbackMetadata']['Item'])) {
                foreach ($api_status['result']['CallbackMetadata']['Item'] as $item) {
                    if ($item['Name'] == 'MpesaReceiptNumber') {
                        $response['transaction']['api_receipt'] = $item['Value'] ?? '';
                        break;
                    }
                }
            }
        }
    } elseif ($api_result_code != 1037 && $transaction['status'] === 'pending') { // 1037 = timeout
        // Payment failed
        updateMpesaTransactionStatusDetailed($mysqli, $checkout_id, 'failed', $api_status['result']);
        $response['transaction']['status'] = 'failed';
        $response['transaction']['status_updated'] = true;
        $response['transaction']['api_error'] = $api_status['result']['ResultDesc'] ?? 'Unknown error';
    }
}

echo json_encode($response);

// Helper Functions
function formatPhoneDisplay($phone) {
    if (strlen($phone) === 12 && substr($phone, 0, 3) === '254') {
        return '0' . substr($phone, 3);
    }
    return $phone;
}

function checkMpesaApiStatusDetailed($mysqli, $checkout_request_id) {
    // Include M-Pesa configuration
    $mpesa_config = [
        'env' => 'sandbox',
        'business_shortcode' => '174379',
        'consumer_key' => 'YOUR_CONSUMER_KEY_HERE',
        'consumer_secret' => 'YOUR_CONSUMER_SECRET_HERE',
        'passkey' => 'YOUR_PASSKEY_HERE'
    ];
    
    try {
        // Get access token
        $endpoint = ($mpesa_config['env'] == 'live') ? 
                    'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials' :
                    'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
        
        $credentials = base64_encode($mpesa_config['consumer_key'] . ':' . $mpesa_config['consumer_secret']);
        
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['Authorization: Basic ' . $credentials],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 15
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code !== 200) {
            return ['success' => false, 'error' => 'Auth failed: HTTP ' . $http_code];
        }
        
        $result = json_decode($response, true);
        $access_token = $result['access_token'] ?? null;
        
        if (!$access_token) {
            return ['success' => false, 'error' => 'No access token in response'];
        }
        
        // Prepare query request
        $timestamp = date('YmdHis');
        $password = base64_encode($mpesa_config['business_shortcode'] . $mpesa_config['passkey'] . $timestamp);
        
        $query_data = [
            'BusinessShortCode' => $mpesa_config['business_shortcode'],
            'Password' => $password,
            'Timestamp' => $timestamp,
            'CheckoutRequestID' => $checkout_request_id
        ];
        
        // Send query request
        $endpoint = ($mpesa_config['env'] == 'live') ? 
                    'https://api.safaricom.co.ke/mpesa/stkpushquery/v1/query' :
                    'https://sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query';
        
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $access_token,
                'Content-Type: application/json'
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($query_data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 15
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $result = json_decode($response, true);
        
        // Log the query
        $log_sql = "INSERT INTO mpesa_query_logs SET 
                    checkout_request_id = ?,
                    request_data = ?,
                    response_data = ?,
                    http_code = ?,
                    query_type = 'specific_check',
                    created_at = NOW()";
        $log_stmt = mysqli_prepare($mysqli, $log_sql);
        mysqli_stmt_bind_param($log_stmt, 'sssi', 
            $checkout_request_id, json_encode($query_data), $response, $http_code
        );
        mysqli_stmt_execute($log_stmt);
        mysqli_stmt_close($log_stmt);
        
        if ($http_code !== 200) {
            return [
                'success' => false,
                'error' => 'API call failed: HTTP ' . $http_code,
                'http_code' => $http_code,
                'raw_response' => $response
            ];
        }
        
        return [
            'success' => true,
            'http_code' => $http_code,
            'result' => $result,
            'raw_response' => $response
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => 'Exception: ' . $e->getMessage()
        ];
    }
}

function updateMpesaTransactionStatusDetailed($mysqli, $checkout_id, $status, $result_data = null) {
    // Update transaction status
    $update_sql = "UPDATE mpesa_pending_transactions SET 
                   status = ?,
                   updated_at = NOW()
                   WHERE checkout_request_id = ?";
    $update_stmt = mysqli_prepare($mysqli, $update_sql);
    mysqli_stmt_bind_param($update_stmt, 'ss', $status, $checkout_id);
    mysqli_stmt_execute($update_stmt);
    mysqli_stmt_close($update_stmt);
    
    // If result data is provided, store detailed callback
    if ($result_data) {
        // Check if callback already exists
        $check_sql = "SELECT callback_id FROM mpesa_callbacks WHERE checkout_request_id = ? LIMIT 1";
        $check_stmt = mysqli_prepare($mysqli, $check_sql);
        mysqli_stmt_bind_param($check_stmt, 's', $checkout_id);
        mysqli_stmt_execute($check_stmt);
        $check_result = mysqli_stmt_get_result($check_stmt);
        $callback_exists = mysqli_fetch_assoc($check_result);
        mysqli_stmt_close($check_stmt);
        
        if (!$callback_exists) {
            // Parse callback metadata
            $callback_data = [
                'checkout_request_id' => $checkout_id,
                'result_code' => $result_data['ResultCode'] ?? 1,
                'result_desc' => $result_data['ResultDesc'] ?? 'Unknown',
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            // Extract metadata from result parameters
            if (isset($result_data['CallbackMetadata']['Item'])) {
                foreach ($result_data['CallbackMetadata']['Item'] as $item) {
                    switch ($item['Name']) {
                        case 'MpesaReceiptNumber':
                            $callback_data['mpesa_receipt'] = $item['Value'] ?? '';
                            break;
                        case 'Amount':
                            $callback_data['amount'] = $item['Value'] ?? 0;
                            break;
                        case 'PhoneNumber':
                            $callback_data['phone_number'] = $item['Value'] ?? '';
                            break;
                        case 'TransactionDate':
                            $callback_data['transaction_date'] = $item['Value'] ?? '';
                            break;
                    }
                }
            }
            
            // Store callback
            $callback_sql = "INSERT INTO mpesa_callbacks SET 
                            checkout_request_id = ?,
                            result_code = ?,
                            result_desc = ?,
                            mpesa_receipt = ?,
                            amount = ?,
                            phone_number = ?,
                            transaction_date = ?,
                            raw_data = ?,
                            created_at = ?";
            $callback_stmt = mysqli_prepare($mysqli, $callback_sql);
            mysqli_stmt_bind_param($callback_stmt, 'sisssssss', 
                $callback_data['checkout_request_id'],
                $callback_data['result_code'],
                $callback_data['result_desc'],
                $callback_data['mpesa_receipt'] ?? '',
                $callback_data['amount'] ?? 0,
                $callback_data['phone_number'] ?? '',
                $callback_data['transaction_date'] ?? '',
                json_encode($result_data),
                $callback_data['created_at']
            );
            mysqli_stmt_execute($callback_stmt);
            mysqli_stmt_close($callback_stmt);
        }
    }
}
?>
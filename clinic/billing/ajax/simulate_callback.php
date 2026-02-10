<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Only allow AJAX requests
if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
    die('Direct access not allowed');
}

header('Content-Type: application/json');

// Validate CSRF
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$checkout_id = sanitizeInput($_POST['checkout_id'] ?? '');

if (empty($checkout_id)) {
    echo json_encode(['success' => false, 'error' => 'Invalid checkout ID']);
    exit;
}

// Get transaction details
$sql = "SELECT * FROM mpesa_pending_transactions WHERE checkout_request_id = ?";
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

// Simulate successful callback
$callback_data = [
    'Body' => [
        'stkCallback' => [
            'MerchantRequestID' => $transaction['merchant_request_id'],
            'CheckoutRequestID' => $checkout_id,
            'ResultCode' => 0,
            'ResultDesc' => 'The service request is processed successfully.',
            'CallbackMetadata' => [
                'Item' => [
                    ['Name' => 'Amount', 'Value' => $transaction['amount']],
                    ['Name' => 'MpesaReceiptNumber', 'Value' => 'SIM' . date('YmdHis') . rand(100, 999)],
                    ['Name' => 'Balance', 'Value' => ''],
                    ['Name' => 'TransactionDate', 'Value' => date('YmdHis')],
                    ['Name' => 'PhoneNumber', 'Value' => $transaction['phone_number']]
                ]
            ]
        ]
    ]
];

// Store simulated callback
$sql = "INSERT INTO mpesa_callbacks SET 
        checkout_request_id = ?,
        result_code = ?,
        result_desc = ?,
        mpesa_receipt = ?,
        amount = ?,
        phone_number = ?,
        transaction_date = ?,
        raw_data = ?,
        created_at = NOW()";
$stmt = mysqli_prepare($mysqli, $sql);
$receipt = $callback_data['Body']['stkCallback']['CallbackMetadata']['Item'][1]['Value'];
mysqli_stmt_bind_param($stmt, 'sissssss', 
    $checkout_id,
    $callback_data['Body']['stkCallback']['ResultCode'],
    $callback_data['Body']['stkCallback']['ResultDesc'],
    $receipt,
    $transaction['amount'],
    $transaction['phone_number'],
    $callback_data['Body']['stkCallback']['CallbackMetadata']['Item'][3]['Value'],
    json_encode($callback_data)
);
mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

// Update transaction status
$update_sql = "UPDATE mpesa_pending_transactions SET 
               status = 'completed',
               updated_at = NOW()
               WHERE checkout_request_id = ?";
$update_stmt = mysqli_prepare($mysqli, $update_sql);
mysqli_stmt_bind_param($update_stmt, 's', $checkout_id);
mysqli_stmt_execute($update_stmt);
mysqli_stmt_close($update_stmt);

echo json_encode([
    'success' => true,
    'message' => 'Callback simulated successfully',
    'receipt' => $receipt
]);
?>
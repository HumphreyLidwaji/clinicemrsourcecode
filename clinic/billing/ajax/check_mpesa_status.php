<?php
require_once '../../includes/inc_all.php';

$invoice_id = intval($_GET['invoice_id'] ?? 0);
$checkout_id = sanitizeInput($_GET['checkout_id'] ?? '');

$response = ['success' => false, 'pending' => false];

if ($invoice_id) {
    // Check for pending M-Pesa payments for this invoice
    $sql = "SELECT * FROM mpesa_pending_transactions 
            WHERE invoice_id = ? AND status = 'pending' 
            ORDER BY created_at DESC LIMIT 1";
    $stmt = mysqli_prepare($mysqli, $sql);
    mysqli_stmt_bind_param($stmt, 'i', $invoice_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($row = mysqli_fetch_assoc($result)) {
        $response = [
            'success' => true,
            'pending' => true,
            'checkout_id' => $row['checkout_request_id'],
            'amount' => number_format($row['amount'], 2),
            'phone' => $row['phone_number'],
            'status' => $row['status'],
            'time' => date('H:i', strtotime($row['created_at']))
        ];
    }
} elseif ($checkout_id) {
    // Check specific checkout ID
    $sql = "SELECT * FROM mpesa_pending_transactions 
            WHERE checkout_request_id = ?";
    $stmt = mysqli_prepare($mysqli, $sql);
    mysqli_stmt_bind_param($stmt, 's', $checkout_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($row = mysqli_fetch_assoc($result)) {
        $response = [
            'success' => true,
            'pending' => $row['status'] === 'pending',
            'checkout_id' => $row['checkout_request_id'],
            'amount' => number_format($row['amount'], 2),
            'phone' => $row['phone_number'],
            'status' => $row['status'],
            'time' => date('H:i', strtotime($row['created_at']))
        ];
        
        // If completed, get receipt info
        if ($row['status'] === 'completed' && $row['payment_id']) {
            $payment_sql = "SELECT payment_number FROM payments WHERE payment_id = ?";
            $payment_stmt = mysqli_prepare($mysqli, $payment_sql);
            mysqli_stmt_bind_param($payment_stmt, 'i', $row['payment_id']);
            mysqli_stmt_execute($payment_stmt);
            $payment_result = mysqli_stmt_get_result($payment_stmt);
            if ($payment_row = mysqli_fetch_assoc($payment_result)) {
                $response['receipt'] = $payment_row['payment_number'];
            }
        }
    }
}

header('Content-Type: application/json');
echo json_encode($response);
?>
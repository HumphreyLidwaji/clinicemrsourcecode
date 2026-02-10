<?php
require_once '../includes/inc_all.php';

// Log the raw callback
$input = file_get_contents('php://input');
file_put_contents('mpesa_callback.log', date('Y-m-d H:i:s') . " - " . $input . "\n", FILE_APPEND);

$data = json_decode($input, true);

if (isset($data['Body']['stkCallback'])) {
    $callback = $data['Body']['stkCallback'];
    $merchantRequestID = $callback['MerchantRequestID'];
    $checkoutRequestID = $callback['CheckoutRequestID'];
    $resultCode = $callback['ResultCode'];
    $resultDesc = $callback['ResultDesc'];
    
    if ($resultCode == 0) {
        // Payment successful
        $callbackMetadata = $callback['CallbackMetadata']['Item'] ?? [];
        
        $amount = 0;
        $mpesaReceiptNumber = '';
        $transactionDate = '';
        $phoneNumber = '';
        
        foreach ($callbackMetadata as $item) {
            if ($item['Name'] == 'Amount') $amount = $item['Value'];
            if ($item['Name'] == 'MpesaReceiptNumber') $mpesaReceiptNumber = $item['Value'];
            if ($item['Name'] == 'TransactionDate') $transactionDate = $item['Value'];
            if ($item['Name'] == 'PhoneNumber') $phoneNumber = $item['Value'];
        }
        
        // Find the pending transaction
        $sql = "SELECT * FROM mpesa_pending_transactions 
                WHERE checkout_request_id = ? AND status = 'pending'";
        $stmt = mysqli_prepare($mysqli, $sql);
        mysqli_stmt_bind_param($stmt, 's', $checkoutRequestID);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if ($row = mysqli_fetch_assoc($result)) {
            $invoice_id = $row['invoice_id'];
            
            // Get invoice details
            $invoice_sql = "SELECT * FROM invoices WHERE invoice_id = ?";
            $invoice_stmt = mysqli_prepare($mysqli, $invoice_sql);
            mysqli_stmt_bind_param($invoice_stmt, 'i', $invoice_id);
            mysqli_stmt_execute($invoice_stmt);
            $invoice_result = mysqli_stmt_get_result($invoice_stmt);
            $invoice = mysqli_fetch_assoc($invoice_result);
            
            if ($invoice) {
                // Create payment record
                $payment_number = generatePaymentNumber();
                $payment_sql = "INSERT INTO payments SET 
                               payment_number = ?,
                               invoice_id = ?,
                               payment_date = CURDATE(),
                               payment_method = 'mobile_money',
                               payment_amount = ?,
                               reference_number = ?,
                               status = 'posted',
                               notes = 'M-Pesa Payment - Receipt: $mpesaReceiptNumber',
                               created_by = ?,
                               created_at = NOW()";
                
                $user_id = $_SESSION['user_id'] ?? 0;
                $stmt = mysqli_prepare($mysqli, $payment_sql);
                mysqli_stmt_bind_param($stmt, 'sidsi', 
                    $payment_number, $invoice_id, $amount, $mpesaReceiptNumber, $user_id
                );
                mysqli_stmt_execute($stmt);
                $payment_id = mysqli_insert_id($mysqli);
                
                // Update invoice paid amount
                $update_invoice_sql = "UPDATE invoices 
                                      SET paid_amount = paid_amount + ?,
                                          invoice_status = CASE 
                                              WHEN (invoice_amount - (paid_amount + ?)) <= 0 THEN 'paid'
                                              ELSE 'partially_paid'
                                          END
                                      WHERE invoice_id = ?";
                $update_stmt = mysqli_prepare($mysqli, $update_invoice_sql);
                mysqli_stmt_bind_param($update_stmt, 'ddi', $amount, $amount, $invoice_id);
                mysqli_stmt_execute($update_stmt);
                
                // Update M-Pesa transaction status
                $update_mpesa_sql = "UPDATE mpesa_pending_transactions SET 
                                    status = 'completed',
                                    payment_id = ?,
                                    updated_at = NOW()
                                    WHERE checkout_request_id = ?";
                $update_mpesa_stmt = mysqli_prepare($mysqli, $update_mpesa_sql);
                mysqli_stmt_bind_param($update_mpesa_stmt, 'is', $payment_id, $checkoutRequestID);
                mysqli_stmt_execute($update_mpesa_stmt);
                
                // Update log
                $update_log_sql = "UPDATE mpesa_logs SET 
                                  status = 'completed',
                                  reference = ?,
                                  updated_at = NOW()
                                  WHERE checkout_request_id = ?";
                $update_log_stmt = mysqli_prepare($mysqli, $update_log_sql);
                mysqli_stmt_bind_param($update_log_stmt, 'ss', $mpesaReceiptNumber, $checkoutRequestID);
                mysqli_stmt_execute($update_log_stmt);
                
                // TODO: Send SMS receipt to patient
                // TODO: Create accounting journal entry
            }
        }
    } else {
        // Payment failed
        $update_sql = "UPDATE mpesa_pending_transactions SET 
                      status = 'failed',
                      updated_at = NOW()
                      WHERE checkout_request_id = ?";
        $stmt = mysqli_prepare($mysqli, $update_sql);
        mysqli_stmt_bind_param($stmt, 's', $checkoutRequestID);
        mysqli_stmt_execute($stmt);
        
        // Update log
        $update_log_sql = "UPDATE mpesa_logs SET 
                          status = 'failed',
                          updated_at = NOW()
                          WHERE checkout_request_id = ?";
        $update_log_stmt = mysqli_prepare($mysqli, $update_log_sql);
        mysqli_stmt_bind_param($update_log_stmt, 's', $checkoutRequestID);
        mysqli_stmt_execute($update_log_stmt);
    }
}

// Always return success to M-Pesa
header('Content-Type: application/json');
echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Success']);

function generatePaymentNumber() {
    global $mysqli;
    
    // Find the maximum payment number
    $sql = "SELECT MAX(CAST(SUBSTRING(payment_number, 4) AS UNSIGNED)) as max_num 
            FROM payments 
            WHERE payment_number LIKE 'PAY-%'";
    $result = mysqli_query($mysqli, $sql);
    $row = mysqli_fetch_assoc($result);
    $next_num = ($row['max_num'] ?? 0) + 1;
    
    return 'PAY-' . str_pad($next_num, 6, '0', STR_PAD_LEFT);
}
?>
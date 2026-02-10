<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Validate CSRF
if (!isset($_GET['csrf_token']) || $_GET['csrf_token'] !== $_SESSION['csrf_token']) {
    die('Invalid CSRF token');
}

$invoice_id = intval($_GET['invoice_id'] ?? 0);

if (!$invoice_id) {
    die('Invalid invoice ID');
}

// Get invoice details
$invoice_sql = "SELECT invoice_number FROM invoices WHERE invoice_id = ?";
$invoice_stmt = mysqli_prepare($mysqli, $invoice_sql);
mysqli_stmt_bind_param($invoice_stmt, 'i', $invoice_id);
mysqli_stmt_execute($invoice_stmt);
$invoice_result = mysqli_stmt_get_result($invoice_stmt);
$invoice = mysqli_fetch_assoc($invoice_result);
mysqli_stmt_close($invoice_stmt);

if (!$invoice) {
    die('Invoice not found');
}

// Get transactions
$sql = "SELECT 
            pt.checkout_request_id,
            pt.merchant_request_id,
            pt.amount,
            pt.phone_number,
            pt.status,
            pt.created_at,
            pt.updated_at,
            c.result_code,
            c.result_desc,
            c.mpesa_receipt,
            c.amount as callback_amount,
            c.phone_number as callback_phone,
            c.transaction_date,
            p.payment_number,
            p.payment_amount,
            p.payment_date,
            p.accounting_status,
            u.user_name as initiated_by
        FROM mpesa_pending_transactions pt
        LEFT JOIN mpesa_callbacks c ON pt.checkout_request_id = c.checkout_request_id
        LEFT JOIN payments p ON pt.payment_id = p.payment_id
        LEFT JOIN users u ON pt.created_by = u.user_id
        WHERE pt.invoice_id = ?
        ORDER BY pt.created_at DESC";

$stmt = mysqli_prepare($mysqli, $sql);
mysqli_stmt_bind_param($stmt, 'i', $invoice_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="mpesa_logs_invoice_' . $invoice['invoice_number'] . '_' . date('Y-m-d') . '.csv"');

// Create output stream
$output = fopen('php://output', 'w');

// Add BOM for UTF-8
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Write headers
fputcsv($output, [
    'Checkout ID',
    'Merchant ID',
    'Amount (KSH)',
    'Phone Number',
    'Status',
    'Created Date',
    'Created Time',
    'Updated Date',
    'Updated Time',
    'Result Code',
    'Result Description',
    'M-Pesa Receipt',
    'Callback Amount',
    'Callback Phone',
    'Transaction Date',
    'Payment Number',
    'Payment Amount',
    'Payment Date',
    'Accounting Status',
    'Initiated By'
]);

// Write data
while ($row = mysqli_fetch_assoc($result)) {
    fputcsv($output, [
        $row['checkout_request_id'],
        $row['merchant_request_id'],
        $row['amount'],
        $row['phone_number'],
        $row['status'],
        date('Y-m-d', strtotime($row['created_at'])),
        date('H:i:s', strtotime($row['created_at'])),
        $row['updated_at'] ? date('Y-m-d', strtotime($row['updated_at'])) : '',
        $row['updated_at'] ? date('H:i:s', strtotime($row['updated_at'])) : '',
        $row['result_code'] ?? '',
        $row['result_desc'] ?? '',
        $row['mpesa_receipt'] ?? '',
        $row['callback_amount'] ?? '',
        $row['callback_phone'] ?? '',
        $row['transaction_date'] ?? '',
        $row['payment_number'] ?? '',
        $row['payment_amount'] ?? '',
        $row['payment_date'] ? date('Y-m-d H:i:s', strtotime($row['payment_date'])) : '',
        $row['accounting_status'] ?? '',
        $row['initiated_by'] ?? ''
    ]);
}

mysqli_stmt_close($stmt);
fclose($output);
exit;
?>
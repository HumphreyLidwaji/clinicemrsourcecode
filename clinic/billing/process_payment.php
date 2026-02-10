<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Get current page name for redirects
$current_page = basename($_SERVER['PHP_SELF']);

// ========================
// CASH REGISTER FUNCTIONS
// ========================

function checkAndOpenCashRegister($mysqli, $user_id) {
    // Check if user has an open cash register
    $check_sql = "SELECT * FROM cash_register WHERE opened_by = ? AND status = 'open' ORDER BY opened_at DESC LIMIT 1";
    $check_stmt = mysqli_prepare($mysqli, $check_sql);
    mysqli_stmt_bind_param($check_stmt, 'i', $user_id);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);
    
    if (mysqli_num_rows($check_result) > 0) {
        // User has an open register
        return mysqli_fetch_assoc($check_result);
    } else {
        // Need to open a new register
        $register_name = "Register-" . date('Ymd-His') . "-" . substr(uniqid(), -4);
        
        $open_sql = "INSERT INTO cash_register SET 
                    register_name = ?,
                    cash_balance = 0.00,
                    is_active = 1,
                    status = 'open',
                    opened_by = ?,
                    opened_at = NOW(),
                    created_at = NOW()";
        
        $open_stmt = mysqli_prepare($mysqli, $open_sql);
        mysqli_stmt_bind_param($open_stmt, 'si', $register_name, $user_id);
        
        if (mysqli_stmt_execute($open_stmt)) {
            $register_id = mysqli_insert_id($mysqli);
            mysqli_stmt_close($open_stmt);
            
            // Get the newly created register
            $new_sql = "SELECT * FROM cash_register WHERE register_id = ?";
            $new_stmt = mysqli_prepare($mysqli, $new_sql);
            mysqli_stmt_bind_param($new_stmt, 'i', $register_id);
            mysqli_stmt_execute($new_stmt);
            $new_result = mysqli_stmt_get_result($new_stmt);
            
            return mysqli_fetch_assoc($new_result);
        } else {
            mysqli_stmt_close($open_stmt);
            return false;
        }
    }
}

function createRegisterTransaction($mysqli, $register_id, $payment_id, $transaction_type, $amount, $description, $user_id) {
    $transaction_sql = "INSERT INTO cash_register_transactions SET 
                       register_id = ?,
                       payment_id = ?,
                       transaction_type = ?,
                       amount = ?,
                       description = ?,
                       created_by = ?,
                       created_at = NOW()";
    
    $transaction_stmt = mysqli_prepare($mysqli, $transaction_sql);
    mysqli_stmt_bind_param($transaction_stmt, 'iisdsi', 
        $register_id, $payment_id, $transaction_type, $amount, $description, $user_id
    );
    
    $result = mysqli_stmt_execute($transaction_stmt);
    mysqli_stmt_close($transaction_stmt);
    
    return $result;
}

function updateCashRegisterBalance($mysqli, $register_id, $amount, $operation = 'add') {
    $operator = $operation === 'add' ? '+' : '-';
    
    $update_sql = "UPDATE cash_register SET 
                   cash_balance = cash_balance $operator ?,
                   updated_at = NOW()
                   WHERE register_id = ? AND status = 'open'";
    
    $update_stmt = mysqli_prepare($mysqli, $update_sql);
    mysqli_stmt_bind_param($update_stmt, 'di', $amount, $register_id);
    
    $result = mysqli_stmt_execute($update_stmt);
    mysqli_stmt_close($update_stmt);
    
    return $result;
}

// ========================
// PAYMENT PROCESSING FUNCTIONS
// ========================

function checkInvoiceStatusAndUpdate($mysqli, $invoice_id) {
    // Check if invoice has draft or pending status
    $invoice_sql = "SELECT invoice_status FROM invoices WHERE invoice_id = ?";
    $invoice_stmt = mysqli_prepare($mysqli, $invoice_sql);
    mysqli_stmt_bind_param($invoice_stmt, 'i', $invoice_id);
    mysqli_stmt_execute($invoice_stmt);
    $invoice_result = mysqli_stmt_get_result($invoice_stmt);
    $invoice = mysqli_fetch_assoc($invoice_result);
    mysqli_stmt_close($invoice_stmt);
    
    if ($invoice && in_array($invoice['invoice_status'], ['draft', 'pending'])) {
        // Check if there are pending bills that are not finalized
        $pending_sql = "SELECT COUNT(*) as pending_count 
                       FROM pending_bills 
                       WHERE invoice_id = ? AND is_finalized = 0";
        $pending_stmt = mysqli_prepare($mysqli, $pending_sql);
        mysqli_stmt_bind_param($pending_stmt, 'i', $invoice_id);
        mysqli_stmt_execute($pending_stmt);
        $pending_result = mysqli_stmt_get_result($pending_stmt);
        $pending_data = mysqli_fetch_assoc($pending_result);
        mysqli_stmt_close($pending_stmt);
        
        if ($pending_data['pending_count'] > 0) {
            // Update invoice status to partial paid
            $update_sql = "UPDATE invoices SET 
                          invoice_status = 'partial_paid',
                          updated_at = NOW()
                          WHERE invoice_id = ?";
            $update_stmt = mysqli_prepare($mysqli, $update_sql);
            mysqli_stmt_bind_param($update_stmt, 'i', $invoice_id);
            mysqli_stmt_execute($update_stmt);
            mysqli_stmt_close($update_stmt);
            
            return true;
        }
    }
    return false;
}

function getPriceListForInvoice($mysqli, $invoice_id) {
    // Get price list ID from invoice items
    $price_sql = "SELECT pl.price_list_id, pl.price_list_type 
                 FROM invoice_items ii
                 LEFT JOIN price_list_items pli ON ii.billable_item_id = pli.billable_item_id
                 LEFT JOIN price_lists pl ON pli.price_list_id = pl.price_list_id
                 WHERE ii.invoice_id = ?
                 LIMIT 1";
    $price_stmt = mysqli_prepare($mysqli, $price_sql);
    mysqli_stmt_bind_param($price_stmt, 'i', $invoice_id);
    mysqli_stmt_execute($price_stmt);
    $price_result = mysqli_stmt_get_result($price_stmt);
    $price_list = mysqli_fetch_assoc($price_result);
    mysqli_stmt_close($price_stmt);
    
    return $price_list;
}

function getPaymentMethodFromPriceList($price_list_type) {
    $method_map = [
        'cash' => 'cash',
        'insurance' => 'insurance',
        'corporate' => 'bank_transfer',
        'government' => 'bank_transfer',
        'staff' => 'cash',
        'other' => 'cash'
    ];
    
    return $method_map[$price_list_type] ?? 'cash';
}

function logPaymentActivity($mysqli, $invoice_id, $action, $details, $user_id) {
    $log_sql = "INSERT INTO payment_activity_log SET 
               invoice_id = ?,
               action = ?,
               details = ?,
               created_by = ?,
               created_at = NOW()";
    $log_stmt = mysqli_prepare($mysqli, $log_sql);
    mysqli_stmt_bind_param($log_stmt, 'issi', $invoice_id, $action, $details, $user_id);
    mysqli_stmt_execute($log_stmt);
    mysqli_stmt_close($log_stmt);
}

// ========================
// PAYMENT PROCESSING
// ========================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    // Handle manual payment processing
    if (isset($_POST['process_payment'])) {
        // Validate CSRF
        if (!isset($_SESSION['csrf_token']) || $csrf_token !== $_SESSION['csrf_token']) {
            flash_alert("Invalid CSRF token", 'error');
            header("Location: $current_page?invoice_id=" . ($_POST['invoice_id'] ?? 0));
            exit;
        }
        
        $invoice_id = intval($_POST['invoice_id'] ?? 0);
        $payment_method = sanitizeInput($_POST['payment_method'] ?? '');
        $payment_amount = floatval($_POST['payment_amount'] ?? 0);
        $payment_date = sanitizeInput($_POST['payment_date'] ?? '');
        $register_id = intval($_POST['register_id'] ?? 0);
        $payment_notes = sanitizeInput($_POST['payment_notes'] ?? '');
        $reference_number = sanitizeInput($_POST['reference_number'] ?? '');
        $insurance_company_id = intval($_POST['insurance_company_id'] ?? 0);
        $insurance_claim_number = sanitizeInput($_POST['insurance_claim_number'] ?? '');
        
        // Check if item-wise payment
        $is_itemwise = isset($_POST['itemwise_payment']) && $_POST['itemwise_payment'] === '1';
        $item_payments = isset($_POST['item_payments']) ? $_POST['item_payments'] : [];
        
        // Validate required fields
        if (empty($payment_method) || $payment_amount <= 0) {
            flash_alert("Please fill all required fields with valid values", 'error');
            header("Location: $current_page?invoice_id=$invoice_id");
            exit;
        }
        
        // Get invoice details
        $invoice_sql = "SELECT i.*, 
                               i.total_amount - IFNULL(i.amount_paid, 0) as remaining_balance
                        FROM invoices i
                        WHERE i.invoice_id = ?";
        $invoice_stmt = mysqli_prepare($mysqli, $invoice_sql);
        mysqli_stmt_bind_param($invoice_stmt, 'i', $invoice_id);
        mysqli_stmt_execute($invoice_stmt);
        $invoice_result = mysqli_stmt_get_result($invoice_stmt);
        $invoice = mysqli_fetch_assoc($invoice_result);
        mysqli_stmt_close($invoice_stmt);
        
        if (!$invoice) {
            flash_alert("Invoice not found", 'error');
            header("Location: $current_page?invoice_id=$invoice_id");
            exit;
        }
        
        // Validate amount doesn't exceed remaining balance
        $remaining_balance = $invoice['remaining_balance'];
        if ($payment_amount > $remaining_balance) {
            flash_alert("Amount cannot exceed remaining balance of KSH " . number_format($remaining_balance, 2), 'error');
            header("Location: $current_page?invoice_id=$invoice_id");
            exit;
        }
        
        // Generate payment number
        $payment_number = 'PAY-' . date('Ymd') . '-' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
        
        // Get transaction reference
        if (empty($reference_number)) {
            $reference_number = $payment_number;
        }
        
        // Start transaction
        mysqli_begin_transaction($mysqli);
        
        try {
            // Check and update invoice status if needed
            checkInvoiceStatusAndUpdate($mysqli, $invoice_id);
            
            // Check if we need to use user's register or selected register
            $use_register_id = $register_id;
            
            // If no register selected, use user's open register or create one
            if ($use_register_id <= 0) {
                $user_register = checkAndOpenCashRegister($mysqli, $session_user_id);
                if ($user_register) {
                    $use_register_id = $user_register['register_id'];
                } else {
                    throw new Exception("Failed to open cash register for user");
                }
            }
            
            // Insert payment record
            $payment_sql = "INSERT INTO payments SET 
                           payment_number = ?,
                           invoice_id = ?,
                           payment_method = ?,
                           payment_amount = ?,
                           payment_date = ?,
                           reference_number = ?,
                           notes = ?,
                           status = 'posted',
                           posted_at = NOW(),
                           posted_by = ?,
                           created_by = ?,
                           created_at = NOW()";
            
            $payment_stmt = mysqli_prepare($mysqli, $payment_sql);
            mysqli_stmt_bind_param($payment_stmt, 'sisdsssii', 
                $payment_number, 
                $invoice_id, 
                $payment_method, 
                $payment_amount, 
                $payment_date, 
                $reference_number, 
                $payment_notes,
                $session_user_id,
                $session_user_id
            );
            
            if (!mysqli_stmt_execute($payment_stmt)) {
                throw new Exception("Failed to create payment record: " . mysqli_error($mysqli));
            }
            
            $payment_id = mysqli_insert_id($mysqli);
            mysqli_stmt_close($payment_stmt);
            
            // Update invoice paid amount
            $update_invoice_sql = "UPDATE invoices SET 
                                  amount_paid = IFNULL(amount_paid, 0) + ?,
                                  invoice_status = CASE 
                                      WHEN (total_amount - (IFNULL(amount_paid, 0) + ?)) <= 0 THEN 'paid'
                                      WHEN (IFNULL(amount_paid, 0) + ?) > 0 THEN 'partial_paid'
                                      ELSE 'issued'
                                  END,
                                  updated_at = NOW()
                                  WHERE invoice_id = ?";
            
            $update_stmt = mysqli_prepare($mysqli, $update_invoice_sql);
            mysqli_stmt_bind_param($update_stmt, 'dddi', $payment_amount, $payment_amount, $payment_amount, $invoice_id);
            
            if (!mysqli_stmt_execute($update_stmt)) {
                throw new Exception("Failed to update invoice: " . mysqli_error($mysqli));
            }
            mysqli_stmt_close($update_stmt);
            
            // Update cash register and create transaction for cash/mobile money
            if (($payment_method === 'cash' || $payment_method === 'mobile_money') && $use_register_id > 0) {
                // Update cash register balance
                if (!updateCashRegisterBalance($mysqli, $use_register_id, $payment_amount, 'add')) {
                    throw new Exception("Failed to update cash register balance");
                }
                
                // Create register transaction record
                $description = "Payment for Invoice #" . $invoice['invoice_number'] . " via " . ucfirst(str_replace('_', ' ', $payment_method));
                if (!createRegisterTransaction($mysqli, $use_register_id, $payment_id, 'payment', $payment_amount, $description, $session_user_id)) {
                    throw new Exception("Failed to create register transaction");
                }
            }
            
            // Create journal entry for accounting
            $journal_entry_number = 'JE-' . date('Ymd') . '-' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
            
            // Get appropriate accounts based on payment method
            $debit_account_id = 1; // Default Cash account
            $credit_account_id = 19; // Default Service Revenue account
            
            if ($payment_method === 'insurance') {
                $debit_account_id = 3; // Accounts Receivable
                $credit_account_id = 19; // Service Revenue
            } elseif ($payment_method === 'bank_transfer') {
                $debit_account_id = 2; // Bank account
            }
            
            // Create journal entry
            $journal_sql = "INSERT INTO journal_entries SET 
                           journal_entry_number = ?,
                           transaction_date = ?,
                           posting_date = ?,
                           description = ?,
                           transaction_type = 'payment',
                           source_type = 'payment',
                           source_id = ?,
                           total_debit = ?,
                           total_credit = ?,
                           status = 'posted',
                           posted_at = NOW(),
                           posted_by = ?,
                           notes = ?,
                           created_by = ?,
                           created_at = NOW()";
            
            $journal_stmt = mysqli_prepare($mysqli, $journal_sql);
            $journal_desc = "Payment for Invoice #" . $invoice['invoice_number'] . " via " . ucfirst(str_replace('_', ' ', $payment_method));
            mysqli_stmt_bind_param($journal_stmt, 'ssssiddssi', 
                $journal_entry_number,
                $payment_date,
                $payment_date,
                $journal_desc,
                $payment_id,
                $payment_amount,
                $payment_amount,
                $session_user_id,
                $payment_notes,
                $session_user_id
            );
            
            if (!mysqli_stmt_execute($journal_stmt)) {
                throw new Exception("Failed to create journal entry: " . mysqli_error($mysqli));
            }
            
            $journal_id = mysqli_insert_id($mysqli);
            mysqli_stmt_close($journal_stmt);
            
            // Update payment with journal entry id
            $update_payment_sql = "UPDATE payments SET 
                                  journal_entry_id = ?,
                                  updated_at = NOW()
                                  WHERE payment_id = ?";
            
            $update_payment_stmt = mysqli_prepare($mysqli, $update_payment_sql);
            mysqli_stmt_bind_param($update_payment_stmt, 'ii', $journal_id, $payment_id);
            
            if (!mysqli_stmt_execute($update_payment_stmt)) {
                throw new Exception("Failed to update payment with journal entry: " . mysqli_error($mysqli));
            }
            mysqli_stmt_close($update_payment_stmt);
            
            // Handle item-wise payments if applicable
            if ($is_itemwise && !empty($item_payments)) {
                foreach ($item_payments as $item_id => $item_amount) {
                    $item_amount = floatval($item_amount);
                    if ($item_amount > 0) {
                        // Update invoice item payment status
                        $update_item_sql = "UPDATE invoice_items SET 
                                           amount_paid = IFNULL(amount_paid, 0) + ?,
                                           payment_status = CASE 
                                               WHEN (total_amount - (IFNULL(amount_paid, 0) + ?)) <= 0 THEN 'paid'
                                               ELSE 'partial'
                                           END,
                                           updated_at = NOW()
                                           WHERE invoice_item_id = ?";
                        $update_item_stmt = mysqli_prepare($mysqli, $update_item_sql);
                        mysqli_stmt_bind_param($update_item_stmt, 'ddi', $item_amount, $item_amount, $item_id);
                        mysqli_stmt_execute($update_item_stmt);
                        mysqli_stmt_close($update_item_stmt);
                        
                        // Create payment allocation record
                        $allocation_sql = "INSERT INTO payment_allocations SET 
                                          payment_id = ?,
                                          invoice_item_id = ?,
                                          allocated_amount = ?,
                                          created_by = ?,
                                          created_at = NOW()";
                        $allocation_stmt = mysqli_prepare($mysqli, $allocation_sql);
                        mysqli_stmt_bind_param($allocation_stmt, 'iidi', $payment_id, $item_id, $item_amount, $session_user_id);
                        mysqli_stmt_execute($allocation_stmt);
                        mysqli_stmt_close($allocation_stmt);
                    }
                }
            }
            
            // Log payment activity
            logPaymentActivity($mysqli, $invoice_id, 'payment_processed', 
                "Payment #$payment_number processed for KSH " . number_format($payment_amount, 2), 
                $session_user_id);
            
            // Commit transaction
            mysqli_commit($mysqli);
            
            flash_alert("✅ Payment processed successfully! Payment #$payment_number", 'success');
            header("Location: billing_payment_view.php?payment_id=$payment_id");
            exit;
            
        } catch (Exception $e) {
            mysqli_rollback($mysqli);
            error_log("Payment processing error: " . $e->getMessage());
            flash_alert("❌ Failed to process payment: " . $e->getMessage(), 'error');
            header("Location: $current_page?invoice_id=$invoice_id");
            exit;
        }
    }
}

// ========================
// DISPLAY LOGIC
// ========================

$invoice_id = intval($_GET['invoice_id'] ?? 0);

if (!$invoice_id) {
    flash_alert("No invoice specified", 'error');
    header("Location: billing_dashboard.php");
    exit;
}

// Get invoice details
$invoice_sql = "SELECT i.*, 
                       p.patient_id, 
                       CONCAT(p.first_name, ' ', p.last_name) as patient_name,
                       p.phone_primary, 
                       p.email, 
                       p.patient_mrn, 
                       p.date_of_birth,
                       i.total_amount - IFNULL(i.amount_paid, 0) as remaining_balance,
                       IFNULL(i.amount_paid, 0) as amount_paid,
                       (SELECT COUNT(*) FROM payments WHERE invoice_id = i.invoice_id AND status = 'posted') as payment_count,
                       v.visit_status, 
                       v.discharge_datetime,
                       (SELECT COUNT(*) FROM pending_bills WHERE invoice_id = i.invoice_id AND is_finalized = 0) as pending_bills_count
                FROM invoices i
                LEFT JOIN patients p ON i.patient_id = p.patient_id
                LEFT JOIN visits v ON i.visit_id = v.visit_id
                WHERE i.invoice_id = ?";
$invoice_stmt = mysqli_prepare($mysqli, $invoice_sql);
mysqli_stmt_bind_param($invoice_stmt, 'i', $invoice_id);
mysqli_stmt_execute($invoice_stmt);
$invoice_result = mysqli_stmt_get_result($invoice_stmt);
$invoice = mysqli_fetch_assoc($invoice_result);
mysqli_stmt_close($invoice_stmt);

if (!$invoice) {
    flash_alert("Invoice not found", 'error');
    header("Location: billing_dashboard.php");
    exit;
}

// Check and update invoice status if needed
checkInvoiceStatusAndUpdate($mysqli, $invoice_id);

// Get price list for auto-selecting payment method
$price_list = getPriceListForInvoice($mysqli, $invoice_id);
$auto_payment_method = '';
if ($price_list && !empty($price_list['price_list_type'])) {
    $auto_payment_method = getPaymentMethodFromPriceList($price_list['price_list_type']);
}

// Get invoice items
$items_sql = "SELECT ii.*, 
                     bi.item_name as service_name,
                     bi.item_category_id as service_category_id,
                     ii.total_amount - IFNULL(ii.amount_paid, 0) as item_due,
                     CASE 
                         WHEN ii.total_amount - IFNULL(ii.amount_paid, 0) <= 0 THEN 'paid'
                         WHEN IFNULL(ii.amount_paid, 0) > 0 THEN 'partial'
                         ELSE 'unpaid'
                     END as calculated_status
              FROM invoice_items ii
              LEFT JOIN billable_items bi ON ii.billable_item_id = bi.billable_item_id
              WHERE ii.invoice_id = ? 
              ORDER BY ii.invoice_item_id";

$items_stmt = mysqli_prepare($mysqli, $items_sql);
mysqli_stmt_bind_param($items_stmt, 'i', $invoice_id);
mysqli_stmt_execute($items_stmt);
$items_result = mysqli_stmt_get_result($items_stmt);
$invoice_items = [];
$total_item_due = 0;

while ($item = mysqli_fetch_assoc($items_result)) {
    $invoice_items[] = $item;
    $total_item_due += $item['item_due'];
}
mysqli_stmt_close($items_stmt);

// Get payment history
$payments_sql = "SELECT p.*, u.user_name as received_by 
                 FROM payments p
                 LEFT JOIN users u ON p.posted_by = u.user_id
                 WHERE p.invoice_id = ? 
                 ORDER BY p.payment_date DESC";
$payments_stmt = mysqli_prepare($mysqli, $payments_sql);
mysqli_stmt_bind_param($payments_stmt, 'i', $invoice_id);
mysqli_stmt_execute($payments_stmt);
$payments_result = mysqli_stmt_get_result($payments_stmt);
$payments = [];
while ($payment = mysqli_fetch_assoc($payments_result)) {
    $payments[] = $payment;
}
mysqli_stmt_close($payments_stmt);

// Get insurance companies
$insurance_sql = "SELECT * FROM insurance_companies WHERE is_active = 1 ORDER BY company_name";
$insurance_result = mysqli_query($mysqli, $insurance_sql);

// Get cash registers - with user's open register first
$registers_sql = "SELECT * FROM cash_register WHERE status = 'open' 
                  ORDER BY 
                    CASE WHEN opened_by = ? THEN 0 ELSE 1 END,
                    register_name";
$registers_stmt = mysqli_prepare($mysqli, $registers_sql);
mysqli_stmt_bind_param($registers_stmt, 'i', $session_user_id);
mysqli_stmt_execute($registers_stmt);
$registers_result = mysqli_stmt_get_result($registers_stmt);

// Get user's open register
$user_register_sql = "SELECT * FROM cash_register WHERE opened_by = ? AND status = 'open' ORDER BY opened_at DESC LIMIT 1";
$user_register_stmt = mysqli_prepare($mysqli, $user_register_sql);
mysqli_stmt_bind_param($user_register_stmt, 'i', $session_user_id);
mysqli_stmt_execute($user_register_stmt);
$user_register_result = mysqli_stmt_get_result($user_register_stmt);
$user_register = mysqli_fetch_assoc($user_register_result);

// Check if invoice is fully paid
$fully_paid = ($invoice['amount_paid'] >= $invoice['total_amount']);

// Check for pending bills
$has_pending_bills = $invoice['pending_bills_count'] > 0;

// Generate CSRF token if not exists
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Process Payment - Invoice #<?php echo htmlspecialchars($invoice['invoice_number']); ?></title>
    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="/assets/css/bootstrap.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="/assets/css/all.min.css">
    <!-- Custom CSS -->
    <style>
        .payment-method-card {
            border: 2px solid transparent;
            transition: all 0.3s;
            cursor: pointer;
        }
        .payment-method-card:hover {
            border-color: #007bff;
            transform: translateY(-2px);
        }
        .payment-method-card.active {
            border-color: #007bff;
            background-color: #f8f9fa;
        }
        .payment-amount-input {
            font-size: 1.5rem;
            font-weight: bold;
        }
        .status-badge {
            font-size: 0.8rem;
        }
        .progress-bar {
            transition: width 0.6s ease;
        }
        .amount-breakdown {
            background: #f8f9fa;
            border-radius: 5px;
            padding: 15px;
        }
        .item-row.selected {
            background-color: #e8f4fd;
        }
        .item-checkbox:checked + .item-details {
            color: #007bff;
            font-weight: bold;
        }
        .payment-summary {
            position: sticky;
            top: 20px;
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 15px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .item-amount-input {
            max-width: 120px;
        }
        .register-info {
            background: #e8f4fd;
            border-left: 4px solid #007bff;
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 4px;
        }
        .price-list-info {
            background: #e8f5e8;
            border-left: 4px solid #28a745;
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 4px;
        }
    </style>
</head>
<body class="hold-transition sidebar-mini">
<div class="wrapper">
    
    <!-- Content Header -->
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1>Process Payment</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="/clinic/dashboard.php">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="billing_invoices.php">Billing</a></li>
                        <li class="breadcrumb-item active">Process Payment</li>
                    </ol>
                </div>
            </div>
        </div>
    </section>

    <!-- Main content -->
    <section class="content">
        <div class="container-fluid">
            <?php if (isset($_SESSION['alert_message'])): ?>
                <div class="alert alert-<?php echo $_SESSION['alert_type']; ?> alert-dismissible">
                    <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
                    <i class="icon fas fa-<?php echo $_SESSION['alert_type'] == 'success' ? 'check' : 
                                          ($_SESSION['alert_type'] == 'warning' ? 'exclamation-triangle' : 'exclamation-triangle'); ?>"></i>
                    <?php echo $_SESSION['alert_message']; ?>
                </div>
                <?php 
                unset($_SESSION['alert_type']);
                unset($_SESSION['alert_message']);
                ?>
            <?php endif; ?>

            <?php if ($has_pending_bills && in_array($invoice['invoice_status'], ['draft', 'pending'])): ?>
            <div class="alert alert-info alert-dismissible">
                <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
                <h5><i class="icon fas fa-info-circle mr-2"></i>Pending Bills Detected</h5>
                <p>This invoice has <?php echo $invoice['pending_bills_count']; ?> pending bill(s) that are not finalized. 
                Invoice status has been updated to <strong>Partial Paid</strong>.</p>
            </div>
            <?php endif; ?>

            <!-- Price List Info -->
            <?php if ($price_list && !empty($price_list['price_list_type'])): ?>
            <div class="alert alert-success alert-dismissible">
                <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
                <h5><i class="icon fas fa-tag mr-2"></i>Price List Detected</h5>
                <p>Price List Type: <strong><?php echo ucfirst($price_list['price_list_type']); ?></strong></p>
                <p>Recommended Payment Method: <strong><?php echo ucfirst(str_replace('_', ' ', $auto_payment_method)); ?></strong> 
                (auto-selected based on price list)</p>
            </div>
            <?php endif; ?>

            <!-- Cash Register Status -->
            <?php if ($user_register): ?>
            <div class="alert alert-success alert-dismissible">
                <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
                <h5><i class="icon fas fa-cash-register mr-2"></i>Cash Register Ready</h5>
                <p>Your cash register <strong>"<?php echo htmlspecialchars($user_register['register_name']); ?>"</strong> is open and ready for transactions.</p>
                <p>Current Balance: <strong>KSH <?php echo number_format($user_register['cash_balance'], 2); ?></strong></p>
                <small>Opened: <?php echo date('M j, Y g:i A', strtotime($user_register['opened_at'])); ?></small>
            </div>
            <?php else: ?>
            <div class="alert alert-info">
                <h5><i class="icon fas fa-info-circle mr-2"></i>Cash Register Required</h5>
                <p>A cash register will be automatically opened for you when you process your first payment.</p>
                <p>Cash and mobile money payments will be recorded in your register.</p>
            </div>
            <?php endif; ?>

            <div class="row">
                <!-- Left Column: Invoice Details -->
                <div class="col-md-4">
                    <div class="card card-primary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-file-invoice mr-2"></i>Invoice Details</h3>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <h5 class="font-weight-bold"><?php echo htmlspecialchars($invoice['patient_name']); ?></h5>
                                <p class="text-muted mb-1">Invoice #: <span class="font-weight-bold"><?php echo htmlspecialchars($invoice['invoice_number']); ?></span></p>
                                <p class="text-muted mb-1">Date: <?php echo date('M j, Y', strtotime($invoice['invoice_date'])); ?></p>
                                <p class="text-muted">Status: 
                                    <span class="badge badge-<?php 
                                        echo $invoice['invoice_status'] === 'closed' ? 'secondary' : 
                                             ($invoice['invoice_status'] === 'partial_paid' ? 'warning' : 
                                             ($invoice['invoice_status'] === 'paid' ? 'success' : 'danger')); 
                                    ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $invoice['invoice_status'])); ?>
                                    </span>
                                </p>
                                <?php if ($has_pending_bills): ?>
                                <p class="text-muted">Pending Bills: <span class="badge badge-warning"><?php echo $invoice['pending_bills_count']; ?> pending</span></p>
                                <?php endif; ?>
                            </div>
                            
                            <div class="amount-breakdown">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Total Amount:</span>
                                    <span class="font-weight-bold">KSH <?php echo number_format($invoice['total_amount'], 2); ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Paid Amount:</span>
                                    <span class="text-success font-weight-bold">KSH <?php echo number_format($invoice['amount_paid'], 2); ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Balance Due:</span>
                                    <span class="text-danger font-weight-bold">KSH <?php echo number_format($invoice['remaining_balance'], 2); ?></span>
                                </div>
                                <hr>
                                <div class="d-flex justify-content-between">
                                    <span>Payments:</span>
                                    <span class="badge badge-info"><?php echo $invoice['payment_count']; ?> transactions</span>
                                </div>
                            </div>
                            
                            <?php if ($price_list && !empty($price_list['price_list_type'])): ?>
                            <div class="price-list-info mt-3">
                                <h6 class="font-weight-bold">Price List Info</h6>
                                <p class="mb-1">Type: <strong><?php echo ucfirst($price_list['price_list_type']); ?></strong></p>
                                <p class="mb-0">Auto-selected Payment Method: <strong><?php echo ucfirst(str_replace('_', ' ', $auto_payment_method)); ?></strong></p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="card card-success mt-3">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-bolt mr-2"></i>Quick Actions</h3>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <?php if ($user_register): ?>
                                <a href="cash_register_management.php?register_id=<?php echo $user_register['register_id']; ?>" class="btn btn-info">
                                    <i class="fas fa-cash-register mr-2"></i>Manage Register
                                </a>
                                <?php endif; ?>
                                <a href="payment_receipt.php?invoice_id=<?php echo $invoice_id; ?>" class="btn btn-warning">
                                    <i class="fas fa-receipt mr-2"></i>View Receipts
                                </a>
                                <a href="billing_invoice_edit.php?invoice_id=<?php echo $invoice_id; ?>" class="btn btn-outline-secondary">
                                    <i class="fas fa-edit mr-2"></i>Edit Invoice
                                </a>
                                <a href="billing_invoices.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-arrow-left mr-2"></i>Back to Invoices
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Cash Register Info -->
                    <div class="card card-info mt-3">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-cash-register mr-2"></i>Cash Register</h3>
                        </div>
                        <div class="card-body">
                            <?php if ($user_register): ?>
                            <div class="register-info">
                                <h6 class="font-weight-bold"><?php echo htmlspecialchars($user_register['register_name']); ?></h6>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Status:</span>
                                    <span class="badge badge-success">Open</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Balance:</span>
                                    <span class="font-weight-bold text-success">KSH <?php echo number_format($user_register['cash_balance'], 2); ?></span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Opened:</span>
                                    <span class="text-muted"><?php echo date('M j, Y g:i A', strtotime($user_register['opened_at'])); ?></span>
                                </div>
                            </div>
                            <div class="text-center">
                                <a href="cash_register_close.php?register_id=<?php echo $user_register['register_id']; ?>" 
                                   class="btn btn-sm btn-outline-danger" 
                                   onclick="return confirm('Close this cash register? This will prevent further transactions.')">
                                    <i class="fas fa-lock mr-1"></i> Close Register
                                </a>
                            </div>
                            <?php else: ?>
                            <p class="text-muted text-center">No active cash register found.</p>
                            <p class="text-muted small">A register will be automatically opened when you process a payment.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Payment Methods & Items -->
                <div class="col-md-8">
                    <!-- Payment Method Selection -->
                    <div class="card card-warning">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-credit-card mr-2"></i>Select Payment Method</h3>
                        </div>
                        <div class="card-body">
                            <!-- Payment Method Selection -->
                            <div class="row mb-4">
                                <div class="col-md-6">
                                    <div class="payment-method-card card text-center p-3 mb-3" 
                                         onclick="selectPaymentMethod('manual')" 
                                         id="manualCard">
                                        <div class="text-primary mb-2" style="font-size: 2rem;">
                                            <i class="fas fa-hand-holding-usd"></i>
                                        </div>
                                        <h6>Manual Entry</h6>
                                        <p class="text-muted small mb-1">Cash, Card, Insurance, etc</p>
                                        <div class="text-info small">
                                            <i class="fas fa-clock"></i> Manual payment entry
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="payment-method-card card text-center p-3 mb-3" 
                                         onclick="selectPaymentMethod('itemwise')" 
                                         id="itemwiseCard">
                                        <div class="text-success mb-2" style="font-size: 2rem;">
                                            <i class="fas fa-list-check"></i>
                                        </div>
                                        <h6>Item-Wise Payment</h6>
                                        <p class="text-muted small mb-1">Pay specific invoice items</p>
                                        <div class="text-warning small">
                                            <i class="fas fa-check-double"></i> Partial payments allowed
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Cash Register Warning for Manual Payments -->
                            <?php if (!$user_register): ?>
                            <div class="alert alert-warning mb-3">
                                <i class="fas fa-exclamation-triangle mr-2"></i>
                                <strong>Note:</strong> A cash register will be automatically opened when you process cash or mobile money payments.
                            </div>
                            <?php endif; ?>

                            <!-- Manual Payment Form -->
                            <div id="manualFormContainer" style="display: none;">
                                <div class="alert alert-warning">
                                    <i class="fas fa-exclamation-triangle mr-2"></i>
                                    <strong>Manual Payment Entry:</strong> Use this for cash, card, insurance, or other manual payment methods.
                                </div>
                                
                                <form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>?invoice_id=<?php echo $invoice_id; ?>" id="manualPaymentForm">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="invoice_id" value="<?php echo $invoice_id; ?>">
                                    <input type="hidden" name="itemwise_payment" value="0">
                                    
                                    <!-- Payment Method Selection -->
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label class="font-weight-bold">Payment Method <span class="text-danger">*</span></label>
                                                <select class="form-control" name="payment_method" id="payment_method" required onchange="togglePaymentFields()">
                                                    <option value="">Select Method</option>
                                                    <option value="cash" <?php echo $auto_payment_method === 'cash' ? 'selected' : ''; ?>>Cash</option>
                                                    <option value="mobile_money" <?php echo $auto_payment_method === 'mobile_money' ? 'selected' : ''; ?>>Mobile Money</option>
                                                    <option value="credit_card">Credit Card</option>
                                                    <option value="insurance" <?php echo $auto_payment_method === 'insurance' ? 'selected' : ''; ?>>Insurance</option>
                                                    <option value="check">Check</option>
                                                    <option value="bank_transfer" <?php echo $auto_payment_method === 'bank_transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                                                    <option value="nhif">NHIF</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label class="font-weight-bold">Amount (KSH) <span class="text-danger">*</span></label>
                                                <input type="number" class="form-control payment-amount-input" name="payment_amount" 
                                                       id="payment_amount" step="0.01" min="0.01" 
                                                       max="<?php echo $invoice['remaining_balance']; ?>"
                                                       value="<?php echo $invoice['remaining_balance']; ?>"
                                                       required onchange="validatePaymentAmount()">
                                                <small class="form-text text-muted">
                                                    Maximum: KSH <?php echo number_format($invoice['remaining_balance'], 2); ?>
                                                </small>
                                                <div class="mt-2">
                                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setFullAmount()">
                                                        Full Amount
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary ml-1" onclick="setPartialAmount()">
                                                        Partial Payment
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Payment Date & Register -->
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label class="font-weight-bold">Payment Date <span class="text-danger">*</span></label>
                                                <input type="datetime-local" class="form-control" name="payment_date" 
                                                       value="<?php echo date('Y-m-d\TH:i'); ?>" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label class="font-weight-bold">Cash Register</label>
                                                <select class="form-control" name="register_id" id="register_id">
                                                    <option value="">Use My Register</option>
                                                    <?php 
                                                    mysqli_data_seek($registers_result, 0);
                                                    while ($register = mysqli_fetch_assoc($registers_result)): 
                                                        $is_user_register = $register['opened_by'] == $session_user_id;
                                                    ?>
                                                        <option value="<?php echo $register['register_id']; ?>" <?php echo $is_user_register ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($register['register_name']); ?> 
                                                            (KSH <?php echo number_format($register['cash_balance'], 2); ?>)
                                                            <?php echo $is_user_register ? ' - My Register' : ''; ?>
                                                        </option>
                                                    <?php endwhile; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Method Specific Fields -->
                                    <div id="methodFieldsContainer">
                                        <!-- Method-specific fields will be shown here -->
                                    </div>
                                    
                                    <!-- Payment Notes -->
                                    <div class="form-group">
                                        <label class="font-weight-bold">Reference Number</label>
                                        <input type="text" class="form-control" name="reference_number" 
                                               placeholder="Receipt/Transaction number">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="font-weight-bold">Payment Notes</label>
                                        <textarea class="form-control" name="payment_notes" rows="2" 
                                                  placeholder="Additional payment notes..."></textarea>
                                    </div>
                                    
                                    <div class="text-center">
                                        <button type="submit" name="process_payment" class="btn btn-primary btn-lg" id="processManualPaymentButton">
                                            <i class="fas fa-check mr-2"></i>Process Manual Payment
                                        </button>
                                        <button type="button" class="btn btn-outline-secondary btn-lg ml-2" onclick="goBackToMethods()">
                                            <i class="fas fa-arrow-left mr-2"></i>Back
                                        </button>
                                    </div>
                                </form>
                            </div>

                            <!-- Item-Wise Payment Form -->
                            <div id="itemwiseFormContainer" style="display: none;">
                                <div class="alert alert-success">
                                    <i class="fas fa-info-circle mr-2"></i>
                                    <strong>Item-Wise Payment:</strong> Select specific items to pay. You can pay partial amounts for individual items.
                                </div>
                                
                                <form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>?invoice_id=<?php echo $invoice_id; ?>" id="itemwisePaymentForm">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="invoice_id" value="<?php echo $invoice_id; ?>">
                                    <input type="hidden" name="itemwise_payment" value="1">
                                    
                                    <!-- Invoice Items List -->
                                    <div class="card mb-3">
                                        <div class="card-header bg-light">
                                            <h6 class="mb-0"><i class="fas fa-list mr-2"></i>Invoice Items</h6>
                                        </div>
                                        <div class="card-body p-0">
                                            <table class="table table-hover mb-0">
                                                <thead class="bg-light">
                                                    <tr>
                                                        <th width="50">#</th>
                                                        <th>Service/Item</th>
                                                        <th width="120" class="text-right">Due</th>
                                                        <th width="150" class="text-right">Pay Amount</th>
                                                        <th width="100" class="text-center">Status</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php if (empty($invoice_items)): ?>
                                                    <tr>
                                                        <td colspan="5" class="text-center text-muted py-3">No invoice items found</td>
                                                    </tr>
                                                    <?php else: ?>
                                                    <?php foreach ($invoice_items as $index => $item): ?>
                                                    <tr class="item-row">
                                                        <td><?php echo $index + 1; ?></td>
                                                        <td>
                                                            <div class="font-weight-bold"><?php echo htmlspecialchars($item['service_name']); ?></div>
                                                            <small class="text-muted">Qty: <?php echo $item['quantity']; ?></small>
                                                        </td>
                                                        <td class="text-right font-weight-bold">
                                                            KSH <?php echo number_format($item['item_due'], 2); ?>
                                                        </td>
                                                        <td class="text-right">
                                                            <input type="number" class="form-control item-amount-input" 
                                                                   name="item_payments[<?php echo $item['invoice_item_id']; ?>]"
                                                                   value="0.00" step="0.01" min="0" 
                                                                   max="<?php echo $item['item_due']; ?>"
                                                                   onchange="updateItemwiseTotal()"
                                                                   data-item-id="<?php echo $item['invoice_item_id']; ?>"
                                                                   data-item-due="<?php echo $item['item_due']; ?>">
                                                        </td>
                                                        <td class="text-center">
                                                            <?php if ($item['calculated_status'] == 'paid'): ?>
                                                            <span class="badge badge-success">Paid</span>
                                                            <?php elseif ($item['calculated_status'] == 'partial'): ?>
                                                            <span class="badge badge-warning">Partial</span>
                                                            <?php else: ?>
                                                            <span class="badge badge-danger">Unpaid</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </tbody>
                                                <tfoot class="bg-light">
                                                    <tr>
                                                        <td colspan="2" class="text-right font-weight-bold">Total:</td>
                                                        <td class="text-right font-weight-bold">
                                                            KSH <span id="totalItemDue"><?php echo number_format($total_item_due, 2); ?></span>
                                                        </td>
                                                        <td class="text-right font-weight-bold">
                                                            KSH <span id="totalToPay">0.00</span>
                                                        </td>
                                                        <td></td>
                                                    </tr>
                                                </tfoot>
                                            </table>
                                        </div>
                                    </div>
                                    
                                    <!-- Payment Method Selection -->
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label class="font-weight-bold">Payment Method <span class="text-danger">*</span></label>
                                                <select class="form-control" name="payment_method" id="itemwise_payment_method" required onchange="toggleItemwisePaymentFields()">
                                                    <option value="">Select Method</option>
                                                    <option value="cash" <?php echo $auto_payment_method === 'cash' ? 'selected' : ''; ?>>Cash</option>
                                                    <option value="mobile_money" <?php echo $auto_payment_method === 'mobile_money' ? 'selected' : ''; ?>>Mobile Money</option>
                                                    <option value="credit_card">Credit Card</option>
                                                    <option value="insurance" <?php echo $auto_payment_method === 'insurance' ? 'selected' : ''; ?>>Insurance</option>
                                                    <option value="check">Check</option>
                                                    <option value="bank_transfer" <?php echo $auto_payment_method === 'bank_transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                                                    <option value="nhif">NHIF</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label class="font-weight-bold">Total Amount (KSH) <span class="text-danger">*</span></label>
                                                <input type="number" class="form-control payment-amount-input" name="payment_amount" 
                                                       id="itemwise_payment_amount" step="0.01" min="0.01" 
                                                       max="<?php echo $invoice['remaining_balance']; ?>"
                                                       value="0.00" required readonly>
                                                <small class="form-text text-muted">
                                                    Calculated from selected items above
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Payment Date & Register -->
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label class="font-weight-bold">Payment Date <span class="text-danger">*</span></label>
                                                <input type="datetime-local" class="form-control" name="payment_date" 
                                                       value="<?php echo date('Y-m-d\TH:i'); ?>" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label class="font-weight-bold">Cash Register</label>
                                                <select class="form-control" name="register_id" id="itemwise_register_id">
                                                    <option value="">Use My Register</option>
                                                    <?php 
                                                    mysqli_data_seek($registers_result, 0);
                                                    while ($register = mysqli_fetch_assoc($registers_result)): 
                                                        $is_user_register = $register['opened_by'] == $session_user_id;
                                                    ?>
                                                        <option value="<?php echo $register['register_id']; ?>" <?php echo $is_user_register ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($register['register_name']); ?> 
                                                            (KSH <?php echo number_format($register['cash_balance'], 2); ?>)
                                                            <?php echo $is_user_register ? ' - My Register' : ''; ?>
                                                        </option>
                                                    <?php endwhile; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Method Specific Fields -->
                                    <div id="itemwiseMethodFieldsContainer">
                                        <!-- Method-specific fields will be shown here -->
                                    </div>
                                    
                                    <!-- Payment Notes -->
                                    <div class="form-group">
                                        <label class="font-weight-bold">Reference Number</label>
                                        <input type="text" class="form-control" name="reference_number" 
                                               placeholder="Receipt/Transaction number">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="font-weight-bold">Payment Notes</label>
                                        <textarea class="form-control" name="payment_notes" rows="2" 
                                                  placeholder="Additional payment notes..."></textarea>
                                    </div>
                                    
                                    <div class="text-center">
                                        <button type="submit" name="process_payment" class="btn btn-success btn-lg" id="processItemwisePaymentButton">
                                            <i class="fas fa-check-double mr-2"></i>Process Item-Wise Payment
                                        </button>
                                        <button type="button" class="btn btn-outline-secondary btn-lg ml-2" onclick="goBackToMethods()">
                                            <i class="fas fa-arrow-left mr-2"></i>Back
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<!-- JavaScript Libraries -->
<script src="/assets/js/jquery.min.js"></script>
<script src="/assets/js/bootstrap.bundle.min.js"></script>
<script src="/assets/js/all.min.js"></script>

<script>
$(document).ready(function() {
    console.log('Payment page loaded for invoice #<?php echo $invoice_id; ?>');
    
    // Initialize form validation
    $('#manualPaymentForm').on('submit', function(e) {
        return validateManualForm();
    });
    
    $('#itemwisePaymentForm').on('submit', function(e) {
        return validateItemwiseForm();
    });
    
    // Auto-select payment method based on price list if available
    <?php if (!empty($auto_payment_method)): ?>
    console.log('Auto-selecting payment method: <?php echo $auto_payment_method; ?> based on price list');
    <?php endif; ?>
    
    // Check if cash register is ready
    <?php if (!$user_register): ?>
    console.log('No active cash register found. One will be created on first payment.');
    <?php endif; ?>
});

let selectedMethod = '';

function selectPaymentMethod(method) {
    selectedMethod = method;
    
    // Reset all cards
    $('.payment-method-card').removeClass('active');
    
    // Hide all forms
    $('#manualFormContainer').hide();
    $('#itemwiseFormContainer').hide();
    
    // Activate selected card and show corresponding form
    if (method === 'manual') {
        $('#manualCard').addClass('active');
        $('#manualFormContainer').show();
        
        // Initialize method fields
        togglePaymentFields();
        
        // Auto-focus payment method select
        setTimeout(() => {
            $('#payment_method').focus();
        }, 300);
    } else if (method === 'itemwise') {
        $('#itemwiseCard').addClass('active');
        $('#itemwiseFormContainer').show();
        
        // Initialize item-wise total
        updateItemwiseTotal();
        
        // Initialize method fields
        toggleItemwisePaymentFields();
        
        // Auto-focus first item amount input
        setTimeout(() => {
            $('.item-amount-input:first').focus();
        }, 300);
    }
}

function goBackToMethods() {
    $('#manualFormContainer').hide();
    $('#itemwiseFormContainer').hide();
    $('.payment-method-card').removeClass('active');
    selectedMethod = '';
}

function validatePaymentAmount() {
    const amount = parseFloat($('#payment_amount').val()) || 0;
    const maxAmount = <?php echo $invoice['remaining_balance']; ?>;
    
    if (amount > maxAmount) {
        alert('Amount cannot exceed remaining balance of KSH ' + maxAmount.toFixed(2));
        $('#payment_amount').val(maxAmount.toFixed(2));
    }
    
    if (amount <= 0) {
        $('#payment_amount').val('0.01');
    }
}

function setFullAmount() {
    const maxAmount = <?php echo $invoice['remaining_balance']; ?>;
    $('#payment_amount').val(maxAmount.toFixed(2));
    validatePaymentAmount();
}

function setPartialAmount() {
    const maxAmount = <?php echo $invoice['remaining_balance']; ?>;
    const partialAmount = Math.min(1000, maxAmount); // Default to 1000 or less
    $('#payment_amount').val(partialAmount.toFixed(2));
    $('#payment_amount').focus();
    validatePaymentAmount();
}

function validateManualForm() {
    const method = $('#payment_method').val();
    const amount = parseFloat($('#payment_amount').val()) || 0;
    const maxAmount = <?php echo $invoice['remaining_balance']; ?>;
    
    if (!method) {
        alert('Please select a payment method');
        $('#payment_method').focus();
        return false;
    }
    
    if (amount <= 0) {
        alert('Amount must be greater than 0');
        $('#payment_amount').focus();
        return false;
    }
    
    if (amount > maxAmount) {
        alert('Amount cannot exceed remaining balance of KSH ' + maxAmount.toFixed(2));
        $('#payment_amount').focus();
        return false;
    }
    
    // Cash register warning for cash/mobile money
    if ((method === 'cash' || method === 'mobile_money') && !confirm('This payment will be recorded in the selected cash register. Continue?')) {
        return false;
    }
    
    return confirm('Are you sure you want to process this manual payment?\n\nAmount: KSH ' + amount.toFixed(2) + '\nMethod: ' + method);
}

function togglePaymentFields() {
    const method = $('#payment_method').val();
    const container = $('#methodFieldsContainer');
    
    // Clear previous fields
    container.empty();
    
    // Show relevant fields based on method
    if (method === 'mobile_money') {
        container.html(`
            <div class="alert alert-info mt-2">
                <i class="fas fa-mobile-alt mr-2"></i>
                <strong>Mobile Money Details:</strong> Enter transaction information below.
            </div>
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label class="font-weight-bold">Transaction ID</label>
                        <input type="text" class="form-control" name="mpesa_transaction_id" 
                               placeholder="Enter M-Pesa transaction ID">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label class="font-weight-bold">Phone Number</label>
                        <input type="tel" class="form-control" name="manual_mpesa_phone" 
                               placeholder="2547XXXXXXXX">
                    </div>
                </div>
            </div>
        `);
    } else if (method === 'insurance') {
        container.html(`
            <div class="alert alert-info mt-2">
                <i class="fas fa-hospital mr-2"></i>
                <strong>Insurance Details:</strong> Enter insurance information below.
            </div>
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label class="font-weight-bold">Insurance Company</label>
                        <select class="form-control" name="insurance_company_id">
                            <option value="">Select Insurance Company</option>
                            <?php 
                            mysqli_data_seek($insurance_result, 0);
                            while ($insurance = mysqli_fetch_assoc($insurance_result)): ?>
                                <option value="<?php echo $insurance['insurance_company_id']; ?>">
                                    <?php echo htmlspecialchars($insurance['company_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label class="font-weight-bold">Claim Number</label>
                        <input type="text" class="form-control" name="insurance_claim_number" 
                               placeholder="Enter claim number">
                    </div>
                </div>
            </div>
        `);
    } else if (method === 'credit_card') {
        container.html(`
            <div class="alert alert-info mt-2">
                <i class="fas fa-credit-card mr-2"></i>
                <strong>Card Details:</strong> Enter card information below.
            </div>
            <div class="row">
                <div class="col-md-4">
                    <div class="form-group">
                        <label class="font-weight-bold">Card Type</label>
                        <select class="form-control" name="card_type">
                            <option value="">Select Card Type</option>
                            <option value="visa">Visa</option>
                            <option value="mastercard">MasterCard</option>
                            <option value="amex">American Express</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <label class="font-weight-bold">Last 4 Digits</label>
                        <input type="text" class="form-control" name="card_last_four" 
                               placeholder="1234" maxlength="4" pattern="[0-9]{4}">
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <label class="font-weight-bold">Auth Code</label>
                        <input type="text" class="form-control" name="card_authorization_code" 
                               placeholder="Enter auth code">
                    </div>
                </div>
            </div>
        `);
    }
}

function toggleItemwisePaymentFields() {
    const method = $('#itemwise_payment_method').val();
    const container = $('#itemwiseMethodFieldsContainer');
    
    // Clear previous fields
    container.empty();
    
    // Show relevant fields based on method (same as manual form)
    if (method === 'mobile_money') {
        container.html(`
            <div class="alert alert-info mt-2">
                <i class="fas fa-mobile-alt mr-2"></i>
                <strong>Mobile Money Details:</strong> Enter transaction information below.
            </div>
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label class="font-weight-bold">Transaction ID</label>
                        <input type="text" class="form-control" name="mpesa_transaction_id" 
                               placeholder="Enter M-Pesa transaction ID">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label class="font-weight-bold">Phone Number</label>
                        <input type="tel" class="form-control" name="manual_mpesa_phone" 
                               placeholder="2547XXXXXXXX">
                    </div>
                </div>
            </div>
        `);
    } else if (method === 'insurance') {
        container.html(`
            <div class="alert alert-info mt-2">
                <i class="fas fa-hospital mr-2"></i>
                <strong>Insurance Details:</strong> Enter insurance information below.
            </div>
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label class="font-weight-bold">Insurance Company</label>
                        <select class="form-control" name="insurance_company_id">
                            <option value="">Select Insurance Company</option>
                            <?php 
                            mysqli_data_seek($insurance_result, 0);
                            while ($insurance = mysqli_fetch_assoc($insurance_result)): ?>
                                <option value="<?php echo $insurance['insurance_company_id']; ?>">
                                    <?php echo htmlspecialchars($insurance['company_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label class="font-weight-bold">Claim Number</label>
                        <input type="text" class="form-control" name="insurance_claim_number" 
                               placeholder="Enter claim number">
                    </div>
                </div>
            </div>
        `);
    }
}

function updateItemwiseTotal() {
    let total = 0;
    let anyAmount = false;
    
    $('.item-amount-input').each(function() {
        const amount = parseFloat($(this).val()) || 0;
        const maxAmount = parseFloat($(this).data('item-due')) || 0;
        
        // Validate individual amount doesn't exceed item due
        if (amount > maxAmount) {
            alert('Amount cannot exceed item due of KSH ' + maxAmount.toFixed(2));
            $(this).val(maxAmount.toFixed(2));
            total += maxAmount;
        } else if (amount > 0) {
            anyAmount = true;
            total += amount;
        }
    });
    
    // Update display
    $('#totalToPay').text(total.toFixed(2));
    $('#itemwise_payment_amount').val(total.toFixed(2));
    
    // Enable/disable submit button
    $('#processItemwisePaymentButton').prop('disabled', !anyAmount);
    
    // Highlight rows with payments
    $('.item-amount-input').each(function() {
        const amount = parseFloat($(this).val()) || 0;
        const row = $(this).closest('tr');
        if (amount > 0) {
            row.addClass('selected');
        } else {
            row.removeClass('selected');
        }
    });
}

function validateItemwiseForm() {
    const method = $('#itemwise_payment_method').val();
    const amount = parseFloat($('#itemwise_payment_amount').val()) || 0;
    const maxAmount = <?php echo $invoice['remaining_balance']; ?>;
    
    if (!method) {
        alert('Please select a payment method');
        $('#itemwise_payment_method').focus();
        return false;
    }
    
    if (amount <= 0) {
        alert('Please enter payment amounts for at least one item');
        return false;
    }
    
    if (amount > maxAmount) {
        alert('Total amount cannot exceed remaining balance of KSH ' + maxAmount.toFixed(2));
        return false;
    }
    
    // Validate individual items don't exceed their due amounts
    let valid = true;
    $('.item-amount-input').each(function() {
        const amount = parseFloat($(this).val()) || 0;
        const maxAmount = parseFloat($(this).data('item-due')) || 0;
        
        if (amount > maxAmount) {
            alert('Amount for item ' + $(this).data('item-id') + ' cannot exceed due amount of KSH ' + maxAmount.toFixed(2));
            $(this).focus();
            valid = false;
            return false;
        }
    });
    
    if (!valid) return false;
    
    // Cash register warning for cash/mobile money
    if ((method === 'cash' || method === 'mobile_money') && !confirm('This payment will be recorded in the selected cash register. Continue?')) {
        return false;
    }
    
    return confirm('Are you sure you want to process this item-wise payment?\n\nTotal Amount: KSH ' + amount.toFixed(2) + '\nMethod: ' + method + '\n\nPayment will be allocated to selected items.');
}

// Auto-select manual payment method on page load if there's a price list
<?php if (!empty($auto_payment_method)): ?>
$(document).ready(function() {
    selectPaymentMethod('manual');
});
<?php endif; ?>

// Handle browser back button
window.addEventListener('popstate', function(event) {
    if (selectedMethod) {
        goBackToMethods();
        history.pushState(null, null, window.location.pathname + window.location.search);
    }
});

// Initialize history state
history.pushState(null, null, window.location.pathname + window.location.search);
</script>

</body>
</html>
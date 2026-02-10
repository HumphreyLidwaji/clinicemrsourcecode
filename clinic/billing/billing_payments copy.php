<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Handle payment processing
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (isset($_POST['process_payment'])) {
        validateCSRFToken($csrf_token);

        // Get form data
        $invoice_id = intval($_POST['invoice_id'] ?? 0);
        $payment_method = sanitizeInput($_POST['payment_method'] ?? '');
        $payment_amount = floatval($_POST['payment_amount'] ?? 0);
        $payment_date = sanitizeInput($_POST['payment_date'] ?? date('Y-m-d H:i:s'));
        $payment_notes = sanitizeInput($_POST['payment_notes'] ?? '');
        $register_id = intval($_POST['register_id'] ?? 0);
        $allocation_method = sanitizeInput($_POST['allocation_method'] ?? 'full');
        
        // Payment method specific data
        $insurance_company_id = intval($_POST['insurance_company_id'] ?? 0);
        $insurance_claim_number = sanitizeInput($_POST['insurance_claim_number'] ?? '');
        $insurance_approval_code = sanitizeInput($_POST['insurance_approval_code'] ?? '');
        
        $mpesa_phone = sanitizeInput($_POST['mpesa_phone'] ?? '');
        $mpesa_transaction_id = sanitizeInput($_POST['mpesa_transaction_id'] ?? '');
        
        $card_last_four = sanitizeInput($_POST['card_last_four'] ?? '');
        $card_type = sanitizeInput($_POST['card_type'] ?? '');
        $card_authorization_code = sanitizeInput($_POST['card_authorization_code'] ?? '');
        
        $check_number = sanitizeInput($_POST['check_number'] ?? '');
        $bank_name = sanitizeInput($_POST['bank_name'] ?? '');
        $check_date = sanitizeInput($_POST['check_date'] ?? '');
        
        $bank_transfer_ref = sanitizeInput($_POST['bank_transfer_ref'] ?? '');
        $bank_account = sanitizeInput($_POST['bank_account'] ?? '');

        // Item-level payment allocations
        $item_payments = [];
        if ($allocation_method === 'specific_items' && isset($_POST['item_payments'])) {
            foreach ($_POST['item_payments'] as $item_id => $amount) {
                $item_id = intval($item_id);
                $amount = floatval($amount);
                if ($amount > 0) {
                    $item_payments[$item_id] = $amount;
                }
            }
        }

        // Validate required fields
        if (empty($invoice_id) || empty($payment_method) || $payment_amount <= 0) {
            flash_alert("Please fill in all required payment details", 'error');
            header("Location: process_payment.php?invoice_id=$invoice_id");
            exit;
        }

        // Start transaction
        mysqli_begin_transaction($mysqli);

        try {
            // Get invoice details with item information
            $invoice_sql = "SELECT i.*, p.patient_id, CONCAT(p.patient_first_name, ' ', p.patient_last_name) as patient_name,
                                   i.invoice_amount - IFNULL(i.paid_amount, 0) as remaining_balance
                            FROM invoices i
                            LEFT JOIN patients p ON i.invoice_client_id = p.patient_id
                            WHERE i.invoice_id = ?";
            $invoice_stmt = mysqli_prepare($mysqli, $invoice_sql);
            mysqli_stmt_bind_param($invoice_stmt, 'i', $invoice_id);
            mysqli_stmt_execute($invoice_stmt);
            $invoice_result = mysqli_stmt_get_result($invoice_stmt);
            $invoice = mysqli_fetch_assoc($invoice_result);
            mysqli_stmt_close($invoice_stmt);

            if (!$invoice) {
                throw new Exception("Invoice not found");
            }

            // Get invoice items for allocation
            $items_sql = "SELECT * FROM invoice_items WHERE item_invoice_id = ?";
            $items_stmt = mysqli_prepare($mysqli, $items_sql);
            mysqli_stmt_bind_param($items_stmt, 'i', $invoice_id);
            mysqli_stmt_execute($items_stmt);
            $items_result = mysqli_stmt_get_result($items_stmt);
            $invoice_items = [];
            while ($item = mysqli_fetch_assoc($items_result)) {
                $invoice_items[$item['item_id']] = $item;
            }
            mysqli_stmt_close($items_stmt);

            // Validate payment allocation
            if ($allocation_method === 'specific_items') {
                $total_item_allocation = array_sum($item_payments);
                if (abs($total_item_allocation - $payment_amount) > 0.01) {
                    throw new Exception("Item allocations (KSH " . number_format($total_item_allocation, 2) . ") must match payment amount (KSH " . number_format($payment_amount, 2) . ")");
                }
                
                // Validate individual item allocations don't exceed remaining amounts
                foreach ($item_payments as $item_id => $amount) {
                    if (!isset($invoice_items[$item_id])) {
                        throw new Exception("Invalid item selected for payment");
                    }
                    $item = $invoice_items[$item_id];
                    $item_remaining = $item['item_total'] - $item['item_amount_paid'];
                    if ($amount > $item_remaining) {
                        throw new Exception("Payment for '{$item['item_name']}' exceeds remaining amount of KSH " . number_format($item_remaining, 2));
                    }
                }
            } else {
                // Full or partial payment validation
                if ($payment_amount > $invoice['remaining_balance']) {
                    throw new Exception("Payment amount exceeds remaining balance of KSH " . number_format($invoice['remaining_balance'], 2));
                }
            }

            // Generate payment number
            $payment_number = generatePaymentNumber($mysqli);

            // Insert payment record
            $payment_sql = "INSERT INTO payments SET 
                           payment_number = ?,
                           payment_invoice_id = ?,
                           payment_amount = ?,
                           payment_method = ?,
                           payment_date = ?,
                           payment_notes = ?,
                           payment_received_by = ?,
                           register_id = ?,
                           allocation_method = ?,
                           payment_status = 'completed',
                           created_at = NOW(),
                           payment_created_by = ?";
            
            $payment_stmt = mysqli_prepare($mysqli, $payment_sql);
            mysqli_stmt_bind_param($payment_stmt, 'siddssiisi', 
                $payment_number, $invoice_id, $payment_amount, $payment_method, 
                $payment_date, $payment_notes, $session_user_id, $register_id, 
                $allocation_method, $session_user_id
            );
            
            if (!mysqli_stmt_execute($payment_stmt)) {
                throw new Exception("Failed to create payment record: " . mysqli_stmt_error($payment_stmt));
            }
            
            $payment_id = mysqli_insert_id($mysqli);
            mysqli_stmt_close($payment_stmt);

            // Insert payment method specific details
            if ($payment_method === 'insurance' && $insurance_company_id) {
                $insurance_sql = "INSERT INTO payment_insurance_details SET 
                                 payment_id = ?,
                                 insurance_provider = ?,
                                 claim_number = ?,
                                 approval_code = ?";
                $insurance_stmt = mysqli_prepare($mysqli, $insurance_sql);
                
                // Get insurance company name
                $insurance_company_sql = "SELECT company_name FROM insurance_companies WHERE insurance_company_id = ?";
                $insurance_company_stmt = mysqli_prepare($mysqli, $insurance_company_sql);
                mysqli_stmt_bind_param($insurance_company_stmt, 'i', $insurance_company_id);
                mysqli_stmt_execute($insurance_company_stmt);
                $insurance_company_result = mysqli_stmt_get_result($insurance_company_stmt);
                $insurance_company = mysqli_fetch_assoc($insurance_company_result);
                $insurance_provider_name = $insurance_company['company_name'] ?? '';
                mysqli_stmt_close($insurance_company_stmt);
                
                mysqli_stmt_bind_param($insurance_stmt, 'isss', 
                    $payment_id, $insurance_provider_name, $insurance_claim_number, $insurance_approval_code
                );
                mysqli_stmt_execute($insurance_stmt);
                mysqli_stmt_close($insurance_stmt);
            } elseif ($payment_method === 'mpesa_stk') {
                $mpesa_sql = "INSERT INTO payment_mpesa_details SET 
                             payment_id = ?,
                             phone_number = ?,
                             reference_number = ?";
                $mpesa_stmt = mysqli_prepare($mysqli, $mpesa_sql);
                mysqli_stmt_bind_param($mpesa_stmt, 'iss', 
                    $payment_id, $mpesa_phone, $mpesa_transaction_id
                );
                mysqli_stmt_execute($mpesa_stmt);
                mysqli_stmt_close($mpesa_stmt);
            } elseif ($payment_method === 'card') {
                $card_sql = "INSERT INTO payment_card_details SET 
                            payment_id = ?,
                            card_type = ?,
                            card_last_four = ?,
                            auth_code = ?";
                $card_stmt = mysqli_prepare($mysqli, $card_sql);
                mysqli_stmt_bind_param($card_stmt, 'isss', 
                    $payment_id, $card_type, $card_last_four, $card_authorization_code
                );
                mysqli_stmt_execute($card_stmt);
                mysqli_stmt_close($card_stmt);
            } elseif ($payment_method === 'check') {
                $check_sql = "INSERT INTO payment_check_details SET 
                             payment_id = ?,
                             check_number = ?,
                             bank_name = ?,
                             check_date = ?";
                $check_stmt = mysqli_prepare($mysqli, $check_sql);
                mysqli_stmt_bind_param($check_stmt, 'isss', 
                    $payment_id, $check_number, $bank_name, $check_date
                );
                mysqli_stmt_execute($check_stmt);
                mysqli_stmt_close($check_stmt);
            } elseif ($payment_method === 'bank_transfer') {
                $bank_sql = "INSERT INTO payment_bank_details SET 
                            payment_id = ?,
                            reference_number = ?,
                            bank_name = ?";
                $bank_stmt = mysqli_prepare($mysqli, $bank_sql);
                mysqli_stmt_bind_param($bank_stmt, 'iss', 
                    $payment_id, $bank_transfer_ref, $bank_account
                );
                mysqli_stmt_execute($bank_stmt);
                mysqli_stmt_close($bank_stmt);
            }

            // Process payment allocation to invoice items
            if ($allocation_method === 'specific_items' && !empty($item_payments)) {
                foreach ($item_payments as $item_id => $amount) {
                    if ($amount > 0) {
                        // Update item paid amount and status
                        $item_update_sql = "UPDATE invoice_items SET 
                                           item_amount_paid = item_amount_paid + ?,
                                           item_payment_status = CASE 
                                               WHEN (item_amount_paid + ?) >= item_total THEN 'paid'
                                               WHEN (item_amount_paid + ?) > 0 THEN 'partial'
                                               ELSE 'unpaid'
                                           END,
                                           item_updated_at = NOW()
                                           WHERE item_id = ?";
                        $item_update_stmt = mysqli_prepare($mysqli, $item_update_sql);
                        mysqli_stmt_bind_param($item_update_stmt, 'dddi', $amount, $amount, $amount, $item_id);
                        
                        if (!mysqli_stmt_execute($item_update_stmt)) {
                            throw new Exception("Failed to update item payment: " . mysqli_stmt_error($item_update_stmt));
                        }
                        
                        mysqli_stmt_close($item_update_stmt);
                    }
                }
            } else {
                // Full or partial payment - distribute to oldest unpaid items first
                $remaining_payment = $payment_amount;
                
                // Get unpaid items ordered by oldest first
                $unpaid_items_sql = "SELECT * FROM invoice_items 
                                    WHERE item_invoice_id = ? 
                                    AND item_payment_status != 'paid'
                                    ORDER BY item_id ASC";
                $unpaid_items_stmt = mysqli_prepare($mysqli, $unpaid_items_sql);
                mysqli_stmt_bind_param($unpaid_items_stmt, 'i', $invoice_id);
                mysqli_stmt_execute($unpaid_items_stmt);
                $unpaid_items_result = mysqli_stmt_get_result($unpaid_items_stmt);
                
                while (($item = mysqli_fetch_assoc($unpaid_items_result)) && $remaining_payment > 0) {
                    $item_remaining = $item['item_total'] - $item['item_amount_paid'];
                    $amount_to_apply = min($item_remaining, $remaining_payment);
                    
                    if ($amount_to_apply > 0) {
                        $item_update_sql = "UPDATE invoice_items SET 
                                           item_amount_paid = item_amount_paid + ?,
                                           item_payment_status = CASE 
                                               WHEN (item_amount_paid + ?) >= item_total THEN 'paid'
                                               ELSE 'partial'
                                           END,
                                           item_updated_at = NOW()
                                           WHERE item_id = ?";
                        $item_update_stmt = mysqli_prepare($mysqli, $item_update_sql);
                        mysqli_stmt_bind_param($item_update_stmt, 'ddi', $amount_to_apply, $amount_to_apply, $item['item_id']);
                        
                        if (!mysqli_stmt_execute($item_update_stmt)) {
                            throw new Exception("Failed to update item: " . mysqli_stmt_error($item_update_stmt));
                        }
                        
                        mysqli_stmt_close($item_update_stmt);
                        $remaining_payment -= $amount_to_apply;
                    }
                }
                mysqli_stmt_close($unpaid_items_stmt);
            }

            // Update invoice paid amount and status
            $current_paid_amount = $invoice['paid_amount'] ?? 0;
            $new_paid_amount = $current_paid_amount + $payment_amount;
            $invoice_amount = $invoice['invoice_amount'];
            
            // Determine new invoice status
            if ($new_paid_amount >= $invoice_amount) {
                $new_status = 'paid';
            } elseif ($new_paid_amount > 0) {
                $new_status = 'partially_paid';
            } else {
                $new_status = 'unpaid';
            }

            $invoice_update_sql = "UPDATE invoices SET 
                                  paid_amount = ?,
                                  invoice_status = ?,
                                  invoice_updated_at = NOW()
                                  WHERE invoice_id = ?";
            $invoice_update_stmt = mysqli_prepare($mysqli, $invoice_update_sql);
            mysqli_stmt_bind_param($invoice_update_stmt, 'dsi', $new_paid_amount, $new_status, $invoice_id);
            
            if (!mysqli_stmt_execute($invoice_update_stmt)) {
                throw new Exception("Failed to update invoice: " . mysqli_stmt_error($invoice_update_stmt));
            }
            
            mysqli_stmt_close($invoice_update_stmt);

            // Commit transaction
            mysqli_commit($mysqli);

            // Log the payment activity
            $activity_description = "Payment processed: KSH " . number_format($payment_amount, 2) . " via " . $payment_method . " - Receipt #" . $payment_number;
            log_activity($mysqli, "Payment", "Processed", $activity_description, $session_user_id, $invoice_id, 'invoices');

            flash_alert("Payment processed successfully! Receipt #: " . $payment_number, 'success');
            header("Location: payment_receipt.php?payment_id=" . $payment_id);
            exit;

        } catch (Exception $e) {
            // Rollback transaction on error
            mysqli_rollback($mysqli);
            flash_alert("Payment Error: " . $e->getMessage(), 'error');
            header("Location: process_payment.php?invoice_id=$invoice_id");
            exit;
        }
    }
}

// Get invoice ID from URL
$invoice_id = intval($_GET['invoice_id'] ?? 0);

if (!$invoice_id) {
    flash_alert("No invoice specified", 'error');
    header("Location: billing_dashboard.php");
    exit;
}

// Get invoice details with proper paid_amount handling
$invoice_sql = "SELECT i.*, p.patient_id, CONCAT(p.patient_first_name, ' ', p.patient_last_name) as patient_name,
                       p.patient_phone, p.patient_email, p.patient_mrn, p.patient_dob,
                       i.invoice_amount - IFNULL(i.paid_amount, 0) as remaining_balance,
                       IFNULL(i.paid_amount, 0) as paid_amount,
                       (SELECT COUNT(*) FROM payments WHERE payment_invoice_id = i.invoice_id AND payment_status = 'completed') as payment_count
                FROM invoices i
                LEFT JOIN patients p ON i.invoice_client_id = p.patient_id
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

// Ensure invoice_status is set
if (empty($invoice['invoice_status'])) {
    if ($invoice['paid_amount'] >= $invoice['invoice_amount']) {
        $invoice['invoice_status'] = 'paid';
    } elseif ($invoice['paid_amount'] > 0) {
        $invoice['invoice_status'] = 'partially_paid';
    } else {
        $invoice['invoice_status'] = 'unpaid';
    }
}

// Get invoice items with payment status
$items_sql = "SELECT *, 
                     item_total - item_amount_paid as item_due,
                     CASE 
                         WHEN item_amount_paid >= item_total THEN 'paid'
                         WHEN item_amount_paid > 0 THEN 'partial' 
                         ELSE 'unpaid'
                     END as calculated_status
              FROM invoice_items 
              WHERE item_invoice_id = ? 
              ORDER BY item_id";
$items_stmt = mysqli_prepare($mysqli, $items_sql);
mysqli_stmt_bind_param($items_stmt, 'i', $invoice_id);
mysqli_stmt_execute($items_stmt);
$items_result = mysqli_stmt_get_result($items_stmt);
$invoice_items = [];
while ($item = mysqli_fetch_assoc($items_result)) {
    // Use calculated status if item_payment_status is not set
    if (empty($item['item_payment_status'])) {
        $item['item_payment_status'] = $item['calculated_status'];
    }
    $invoice_items[] = $item;
}
mysqli_stmt_close($items_stmt);

// Get payment history
$payments_sql = "SELECT p.*, u.user_name as received_by 
                 FROM payments p
                 LEFT JOIN users u ON p.payment_received_by = u.user_id
                 WHERE p.payment_invoice_id = ? 
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

// Get insurance companies from insurance_companies table
$insurance_sql = "SELECT * FROM insurance_companies WHERE is_active = 1 ORDER BY company_name";
$insurance_result = mysqli_query($mysqli, $insurance_sql);

// Get active cash registers
$registers_sql = "SELECT * FROM cash_register WHERE status = 'open' ORDER BY register_name";
$registers_result = mysqli_query($mysqli, $registers_sql);

// Helper function to generate payment number
function generatePaymentNumber($mysqli) {
    $prefix = "PMT";
    $year = date('Y');
    
    // Get last payment number for this year
    $last_sql = "SELECT payment_number FROM payments 
                 WHERE payment_number LIKE '$prefix-$year-%' 
                 ORDER BY payment_id DESC LIMIT 1";
    $last_result = mysqli_query($mysqli, $last_sql);
    
    if (mysqli_num_rows($last_result) > 0) {
        $last_number = mysqli_fetch_assoc($last_result)['payment_number'];
        $last_seq = intval(substr($last_number, -4));
        $new_seq = str_pad($last_seq + 1, 4, '0', STR_PAD_LEFT);
    } else {
        $new_seq = '0001';
    }
    
    return $prefix . '-' . $year . '-' . $new_seq;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Process Payment - <?php echo htmlspecialchars($session_company_name); ?></title>
    <!-- Bootstrap CSS -->
    <link href="../vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="../vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <style>
        .payment-fields {
            background-color: #f8f9fa;
            border-radius: 0.25rem;
            padding: 1rem;
            margin: 1rem 0;
            border-left: 4px solid #007bff;
        }
        .card {
            border: none;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
        }
        .card-header {
            border-bottom: 1px solid #e3e6f0;
        }
        .item-row.table-success {
            background-color: #d4edda !important;
        }
        .allocation-section {
            border: 2px dashed #dee2e6;
            border-radius: 0.5rem;
            padding: 1rem;
            margin: 1rem 0;
        }
        .status-badge {
            font-size: 0.75rem;
        }
        .amount-cell {
            font-family: 'Courier New', monospace;
            font-weight: bold;
        }
        .novalidate {
            border: none !important;
        }
    </style>
</head>
<body class="bg-light">
    <div class="container-fluid">
        <!-- Header Section -->
        <div class="d-sm-flex align-items-center justify-content-between mb-4">
            <div>
                <h1 class="h3 mb-0 text-gray-800">
                    <i class="fas fa-credit-card text-primary mr-2"></i>
                    Process Payment
                </h1>
                <p class="text-muted mb-0">Record payment for patient invoice</p>
            </div>
            <div>
                <a href="billing_invoice_edit.php?invoice_id=<?php echo $invoice_id; ?>" class="btn btn-secondary">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Invoice
                </a>
                <a href="billing_dashboard.php" class="btn btn-outline-secondary ml-2">
                    <i class="fas fa-tachometer-alt mr-2"></i>Dashboard
                </a>
            </div>
        </div>

        <!-- Alert Messages -->
        <?php if (isset($_SESSION['alert_message'])): ?>
            <div class="alert alert-<?php echo $_SESSION['alert_type']; ?> alert-dismissible fade show" role="alert">
                <?php echo $_SESSION['alert_message']; ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <?php 
            unset($_SESSION['alert_type']);
            unset($_SESSION['alert_message']);
            ?>
        <?php endif; ?>

        <div class="row">
            <!-- Left Column: Invoice Summary & Payment Form -->
            <div class="col-lg-8">
                <!-- Invoice Summary Card -->
                <div class="card shadow mb-4">
                    <div class="card-header bg-primary text-white py-3">
                        <h5 class="m-0 font-weight-bold">
                            <i class="fas fa-file-invoice mr-2"></i>
                            Invoice Summary
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td class="font-weight-bold text-right" width="40%">Invoice #:</td>
                                        <td class="font-weight-bold text-primary"><?php echo htmlspecialchars($invoice['invoice_number']); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="font-weight-bold text-right">Patient:</td>
                                        <td><?php echo htmlspecialchars($invoice['patient_name']); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="font-weight-bold text-right">MRN:</td>
                                        <td><?php echo htmlspecialchars($invoice['patient_mrn']); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="font-weight-bold text-right">Phone:</td>
                                        <td><?php echo htmlspecialchars($invoice['patient_phone']); ?></td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td class="font-weight-bold text-right" width="40%">Total Amount:</td>
                                        <td class="font-weight-bold text-success amount-cell">KSH <?php echo number_format($invoice['invoice_amount'], 2); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="font-weight-bold text-right">Paid Amount:</td>
                                        <td class="font-weight-bold text-info amount-cell">KSH <?php echo number_format($invoice['paid_amount'], 2); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="font-weight-bold text-right">Remaining Balance:</td>
                                        <td class="font-weight-bold text-danger amount-cell">KSH <?php echo number_format($invoice['remaining_balance'], 2); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="font-weight-bold text-right">Status:</td>
                                        <td>
                                            <span class="badge badge-<?php 
                                                echo $invoice['invoice_status'] === 'paid' ? 'success' : 
                                                     ($invoice['invoice_status'] === 'partially_paid' ? 'warning' : 'danger'); 
                                            ?> status-badge">
                                                <?php echo ucfirst(str_replace('_', ' ', $invoice['invoice_status'])); ?>
                                            </span>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Payment Form Card -->
                <div class="card shadow">
                    <div class="card-header bg-success text-white py-3">
                        <h5 class="m-0 font-weight-bold">
                            <i class="fas fa-money-bill-wave mr-2"></i>
                            Payment Details
                        </h5>
                    </div>
                    <div class="card-body">
                        <!-- Add novalidate attribute to form to prevent HTML5 validation -->
                        <form method="POST" id="paymentForm" autocomplete="off" novalidate>
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="invoice_id" value="<?php echo $invoice_id; ?>">

                            <!-- Payment Allocation Method -->
                            <div class="form-group">
                                <label class="font-weight-bold">Payment Allocation Method</label>
                                <select class="form-control" name="allocation_method" id="allocation_method" required onchange="toggleAllocationMethod()">
                                    <option value="full">Full Payment (Auto-distribute)</option>
                                    <option value="partial">Partial Payment (Auto-distribute to oldest items)</option>
                                    <option value="specific_items">Specific Items (Choose which items to pay)</option>
                                </select>
                                <small class="form-text text-muted">
                                    Choose how to allocate this payment across invoice items
                                </small>
                            </div>

                            <!-- Item Allocation Section -->
                            <div class="allocation-section" id="item_allocation_section" style="display: none;">
                                <h6 class="font-weight-bold text-primary mb-3">
                                    <i class="fas fa-list-check mr-2"></i>Allocate Payment to Specific Items
                                </h6>
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered">
                                        <thead class="bg-light">
                                            <tr>
                                                <th width="50">Pay</th>
                                                <th>Item Description</th>
                                                <th class="text-right">Total Amount</th>
                                                <th class="text-right">Paid</th>
                                                <th class="text-right">Due</th>
                                                <th class="text-right">Payment Amount</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody id="item_allocation_tbody">
                                            <?php
                                            $total_due = 0;
                                            foreach ($invoice_items as $item):
                                                $item_due = $item['item_total'] - $item['item_amount_paid'];
                                                $total_due += $item_due;
                                                $is_paid = $item_due <= 0;
                                            ?>
                                            <tr class="item-row <?php echo $is_paid ? 'table-success' : ''; ?>">
                                                <td class="text-center">
                                                    <?php if (!$is_paid): ?>
                                                    <input type="checkbox" class="item-checkbox" 
                                                           data-item-id="<?php echo $item['item_id']; ?>"
                                                           data-due-amount="<?php echo $item_due; ?>"
                                                           onchange="updateItemPayment(this)">
                                                    <?php else: ?>
                                                    <i class="fas fa-check text-success"></i>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="font-weight-bold"><?php echo htmlspecialchars($item['item_name']); ?></div>
                                                    <?php if (!empty($item['item_description'])): ?>
                                                    <small class="text-muted"><?php echo htmlspecialchars($item['item_description']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-right amount-cell">KSH <?php echo number_format($item['item_total'], 2); ?></td>
                                                <td class="text-right text-success amount-cell">KSH <?php echo number_format($item['item_amount_paid'], 2); ?></td>
                                                <td class="text-right text-danger amount-cell">KSH <?php echo number_format($item_due, 2); ?></td>
                                                <td class="text-right">
                                                    <?php if (!$is_paid): ?>
                                                    <input type="number" class="form-control form-control-sm item-payment-input" 
                                                           name="item_payments[<?php echo $item['item_id']; ?>]"
                                                           data-item-id="<?php echo $item['item_id']; ?>"
                                                           data-max-amount="<?php echo $item_due; ?>"
                                                           placeholder="0.00" step="0.01" min="0" 
                                                           max="<?php echo $item_due; ?>"
                                                           onchange="validateItemPayment(this)"
                                                           onkeyup="validateItemPayment(this)"
                                                           style="width: 100px; display: inline-block;">
                                                    <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge badge-<?php 
                                                        echo $item['item_payment_status'] === 'paid' ? 'success' : 
                                                             ($item['item_payment_status'] === 'partial' ? 'warning' : 'danger'); 
                                                    ?> status-badge">
                                                        <?php echo ucfirst($item['item_payment_status']); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot class="bg-light">
                                            <tr>
                                                <td colspan="4" class="text-right font-weight-bold">Total Due:</td>
                                                <td class="text-right font-weight-bold text-danger amount-cell">KSH <?php echo number_format($total_due, 2); ?></td>
                                                <td class="text-right">
                                                    <div id="total_item_allocation" class="font-weight-bold text-success amount-cell">KSH 0.00</div>
                                                </td>
                                                <td></td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="font-weight-bold">Payment Method <span class="text-danger">*</span></label>
                                        <select class="form-control" name="payment_method" id="payment_method" required onchange="togglePaymentFields()">
                                            <option value="">Select Payment Method</option>
                                            <option value="cash">Cash</option>
                                            <option value="mpesa_stk">M-Pesa STK</option>
                                            <option value="card">Credit/Debit Card</option>
                                            <option value="insurance">Insurance</option>
                                            <option value="check">Check</option>
                                            <option value="bank_transfer">Bank Transfer</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="font-weight-bold">Payment Amount (KSH) <span class="text-danger">*</span></label>
                                        <input type="number" class="form-control" name="payment_amount" 
                                               id="payment_amount" step="0.01" min="0.01" 
                                               max="<?php echo $invoice['remaining_balance']; ?>"
                                               value="<?php echo $invoice['remaining_balance']; ?>"
                                               required onchange="validatePaymentAmount()">
                                        <small class="form-text text-muted">
                                            Maximum: KSH <?php echo number_format($invoice['remaining_balance'], 2); ?>
                                        </small>
                                    </div>
                                </div>
                            </div>

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
                                            <option value="">Select Register (Optional)</option>
                                            <?php 
                                            mysqli_data_seek($registers_result, 0);
                                            while ($register = mysqli_fetch_assoc($registers_result)): ?>
                                                <option value="<?php echo $register['register_id']; ?>">
                                                    <?php echo htmlspecialchars($register['register_name']); ?> 
                                                    (Balance: KSH <?php echo number_format($register['cash_balance'], 2); ?>)
                                                </option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <!-- Payment Method Specific Fields - REMOVED required attributes -->
                            <!-- Insurance Payment Fields -->
                            <div class="payment-fields" id="insurance_fields" style="display: none;">
                                <h6 class="font-weight-bold text-primary mb-3">
                                    <i class="fas fa-hospital mr-2"></i>Insurance Payment Details
                                </h6>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="font-weight-bold">Insurance Company</label>
                                            <select class="form-control" name="insurance_company_id" id="insurance_company_id">
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
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="font-weight-bold">Approval Code</label>
                                            <input type="text" class="form-control" name="insurance_approval_code" 
                                                   placeholder="Enter approval code">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="font-weight-bold">Coverage Amount (KSH)</label>
                                            <input type="number" class="form-control" name="insurance_coverage_amount" 
                                                   step="0.01" min="0" placeholder="0.00">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- M-Pesa Payment Fields -->
                            <div class="payment-fields" id="mpesa_fields" style="display: none;">
                                <h6 class="font-weight-bold text-primary mb-3">
                                    <i class="fas fa-mobile-alt mr-2"></i>M-Pesa Payment Details
                                </h6>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="font-weight-bold">Phone Number</label>
                                            <input type="tel" class="form-control" name="mpesa_phone" 
                                                   placeholder="e.g., 254712345678" pattern="[0-9]{12}">
                                            <small class="form-text text-muted">Format: 254712345678</small>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="font-weight-bold">Transaction ID</label>
                                            <input type="text" class="form-control" name="mpesa_transaction_id" 
                                                   placeholder="Enter M-Pesa transaction ID">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Card Payment Fields -->
                            <div class="payment-fields" id="card_fields" style="display: none;">
                                <h6 class="font-weight-bold text-primary mb-3">
                                    <i class="fas fa-credit-card mr-2"></i>Card Payment Details
                                </h6>
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label class="font-weight-bold">Card Type</label>
                                            <select class="form-control" name="card_type">
                                                <option value="">Select Card Type</option>
                                                <option value="visa">Visa</option>
                                                <option value="mastercard">MasterCard</option>
                                                <option value="amex">American Express</option>
                                                <option value="discover">Discover</option>
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
                                            <label class="font-weight-bold">Authorization Code</label>
                                            <input type="text" class="form-control" name="card_authorization_code" 
                                                   placeholder="Enter auth code">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Check Payment Fields -->
                            <div class="payment-fields" id="check_fields" style="display: none;">
                                <h6 class="font-weight-bold text-primary mb-3">
                                    <i class="fas fa-money-check mr-2"></i>Check Payment Details
                                </h6>
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label class="font-weight-bold">Check Number</label>
                                            <input type="text" class="form-control" name="check_number" 
                                                   placeholder="Enter check number">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label class="font-weight-bold">Bank Name</label>
                                            <input type="text" class="form-control" name="bank_name" 
                                                   placeholder="Enter bank name">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label class="font-weight-bold">Check Date</label>
                                            <input type="date" class="form-control" name="check_date">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Bank Transfer Fields -->
                            <div class="payment-fields" id="bank_transfer_fields" style="display: none;">
                                <h6 class="font-weight-bold text-primary mb-3">
                                    <i class="fas fa-university mr-2"></i>Bank Transfer Details
                                </h6>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="font-weight-bold">Reference Number</label>
                                            <input type="text" class="form-control" name="bank_transfer_ref" 
                                                   placeholder="Enter transfer reference">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="font-weight-bold">Bank Account</label>
                                            <input type="text" class="form-control" name="bank_account" 
                                                   placeholder="Enter bank account details">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="font-weight-bold">Payment Notes</label>
                                <textarea class="form-control" name="payment_notes" rows="3" 
                                          placeholder="Additional payment notes or comments..."></textarea>
                            </div>

                            <div class="form-group">
                                <button type="submit" name="process_payment" class="btn btn-success btn-lg">
                                    <i class="fas fa-check-circle mr-2"></i>Process Payment
                                </button>
                                <button type="reset" class="btn btn-outline-secondary ml-2" onclick="resetForm()">
                                    <i class="fas fa-undo mr-2"></i>Reset Form
                                </button>
                                <a href="billing_invoice_edit.php?invoice_id=<?php echo $invoice_id; ?>" class="btn btn-outline-primary ml-2">
                                    <i class="fas fa-edit mr-2"></i>Edit Invoice
                                </a>
                                <!-- Debug button -->
                                <button type="button" class="btn btn-warning ml-2" onclick="debugForm()">
                                    <i class="fas fa-bug mr-2"></i>Debug
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Right Column: Payment History -->
            <div class="col-lg-4">
                <div class="card shadow">
                    <div class="card-header bg-info text-white py-3">
                        <h5 class="m-0 font-weight-bold">
                            <i class="fas fa-history mr-2"></i>
                            Payment History
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($payments)): ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($payments as $payment): ?>
                                    <div class="list-group-item px-0 py-2">
                                        <div class="d-flex w-100 justify-content-between">
                                            <h6 class="mb-1 text-primary"><?php echo htmlspecialchars($payment['payment_number']); ?></h6>
                                            <small class="text-success font-weight-bold amount-cell">
                                                KSH <?php echo number_format($payment['payment_amount'], 2); ?>
                                            </small>
                                        </div>
                                        <p class="mb-1 small">
                                            <span class="badge badge-secondary"><?php echo ucfirst($payment['payment_method']); ?></span>
                                            <span class="badge badge-<?php echo $payment['payment_status'] === 'completed' ? 'success' : 'warning'; ?>">
                                                <?php echo ucfirst($payment['payment_status']); ?>
                                            </span>
                                        </p>
                                        <small class="text-muted">
                                            <?php echo date('M j, Y g:i A', strtotime($payment['payment_date'])); ?><br>
                                            By: <?php echo htmlspecialchars($payment['received_by'] ?? 'System'); ?>
                                        </small>
                                        <?php if ($payment['payment_notes']): ?>
                                            <div class="mt-1">
                                                <small class="text-muted"><?php echo htmlspecialchars($payment['payment_notes']); ?></small>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="fas fa-receipt fa-3x text-muted mb-3"></i>
                                <h6>No Payments Yet</h6>
                                <p class="text-muted small">No payments have been recorded for this invoice.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Quick Actions Card -->
                <div class="card shadow mt-4">
                    <div class="card-header bg-warning text-dark py-3">
                        <h5 class="m-0 font-weight-bold">
                            <i class="fas fa-bolt mr-2"></i>
                            Quick Actions
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="billing_invoice_edit.php?invoice_id=<?php echo $invoice_id; ?>" class="btn btn-outline-primary">
                                <i class="fas fa-edit mr-2"></i>Edit Invoice Details
                            </a>
                            <a href="patient_profile.php?patient_id=<?php echo $invoice['patient_id']; ?>" class="btn btn-outline-info">
                                <i class="fas fa-user mr-2"></i>View Patient Profile
                            </a>
                            <a href="billing_dashboard.php" class="btn btn-outline-success">
                                <i class="fas fa-tachometer-alt mr-2"></i>Billing Dashboard
                            </a>
                        </div>
                    </div>
                </div>

                <!-- System Information Card -->
                <div class="card shadow mt-4">
                    <div class="card-header bg-secondary text-white py-3">
                        <h5 class="m-0 font-weight-bold">
                            <i class="fas fa-info-circle mr-2"></i>
                            System Information
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="small">
                            <div class="d-flex justify-content-between mb-1">
                                <span>Invoice Created:</span>
                                <span><?php echo date('M j, Y g:i A', strtotime($invoice['invoice_created_at'])); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-1">
                                <span>Last Updated:</span>
                                <span><?php echo date('M j, Y g:i A', strtotime($invoice['invoice_updated_at'] ?? $invoice['invoice_created_at'])); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-1">
                                <span>Total Payments:</span>
                                <span class="font-weight-bold"><?php echo $invoice['payment_count']; ?></span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span>Items Count:</span>
                                <span class="font-weight-bold"><?php echo count($invoice_items); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap core JavaScript-->
    <script src="../vendor/jquery/jquery.min.js"></script>
    <script src="../vendor/bootstrap/js/bootstrap.bundle.min.js"></script>

    <script>
let totalAllocated = 0;
let itemLastValues = {};

function toggleAllocationMethod() {
    const method = document.getElementById('allocation_method').value;
    const allocationSection = document.getElementById('item_allocation_section');
    
    if (method === 'specific_items') {
        allocationSection.style.display = 'block';
        document.getElementById('payment_amount').value = <?php echo $invoice['remaining_balance']; ?>;
        validatePaymentAmount();
    } else {
        allocationSection.style.display = 'none';
        document.querySelectorAll('.item-payment-input').forEach(input => input.value = '');
        document.querySelectorAll('.item-checkbox').forEach(checkbox => checkbox.checked = false);
        totalAllocated = 0;
        updateTotalAllocation();
    }
}

function updateItemPayment(checkbox) {
    const itemId = checkbox.getAttribute('data-item-id');
    const dueAmount = parseFloat(checkbox.getAttribute('data-due-amount'));
    const paymentInput = document.querySelector(`.item-payment-input[data-item-id="${itemId}"]`);
    
    if (checkbox.checked) {
        paymentInput.value = dueAmount.toFixed(2);
        totalAllocated += dueAmount;
        itemLastValues[itemId] = dueAmount;
    } else {
        paymentInput.value = '';
        totalAllocated -= (itemLastValues[itemId] || 0);
        delete itemLastValues[itemId];
    }
    updateTotalAllocation();
    updatePaymentAmount();
}

function validateItemPayment(input) {
    const itemId = input.getAttribute('data-item-id');
    const maxAmount = parseFloat(input.getAttribute('data-max-amount'));
    const amount = parseFloat(input.value) || 0;
    
    if (amount > maxAmount) {
        alert('Payment amount cannot exceed due amount of KSH ' + maxAmount.toFixed(2));
        input.value = maxAmount.toFixed(2);
        return false;
    }
    
    const oldAmount = itemLastValues[itemId] || 0;
    totalAllocated = totalAllocated - oldAmount + amount;
    itemLastValues[itemId] = amount;
    
    updateTotalAllocation();
    updatePaymentAmount();
    return true;
}

function validatePaymentAmount() {
    const paymentAmount = parseFloat(document.getElementById('payment_amount').value) || 0;
    const maxAmount = <?php echo $invoice['remaining_balance']; ?>;
    
    if (paymentAmount > maxAmount) {
        alert('Payment amount cannot exceed remaining balance of KSH ' + maxAmount.toFixed(2));
        document.getElementById('payment_amount').value = maxAmount.toFixed(2);
    }
}

function updateTotalAllocation() {
    document.getElementById('total_item_allocation').textContent = 'KSH ' + totalAllocated.toFixed(2);
}

function updatePaymentAmount() {
    const paymentAmountInput = document.getElementById('payment_amount');
    const allocationMethod = document.getElementById('allocation_method').value;
    
    if (allocationMethod === 'specific_items') {
        paymentAmountInput.value = totalAllocated.toFixed(2);
    }
}

function togglePaymentFields() {
    const paymentMethod = document.getElementById('payment_method').value;
    
    // Hide all payment fields
    document.querySelectorAll('.payment-fields').forEach(field => {
        field.style.display = 'none';
    });
    
    // Show relevant fields based on payment method
    if (paymentMethod === 'insurance') {
        document.getElementById('insurance_fields').style.display = 'block';
    } else if (paymentMethod === 'mpesa_stk') {
        document.getElementById('mpesa_fields').style.display = 'block';
    } else if (paymentMethod === 'card') {
        document.getElementById('card_fields').style.display = 'block';
    } else if (paymentMethod === 'check') {
        document.getElementById('check_fields').style.display = 'block';
    } else if (paymentMethod === 'bank_transfer') {
        document.getElementById('bank_transfer_fields').style.display = 'block';
    }
}

function resetForm() {
    totalAllocated = 0;
    itemLastValues = {};
    updateTotalAllocation();
    setTimeout(() => {
        toggleAllocationMethod();
        togglePaymentFields();
    }, 100);
}

// Enhanced form validation that handles payment method specific fields
document.getElementById('paymentForm').addEventListener('submit', function(e) {
    console.log('Form submission started...');
    
    const allocationMethod = document.getElementById('allocation_method').value;
    const paymentAmount = parseFloat(document.getElementById('payment_amount').value) || 0;
    const paymentMethod = document.getElementById('payment_method').value;
    const paymentDate = document.querySelector('input[name="payment_date"]').value;
    
    console.log('Payment Method:', paymentMethod, 'Amount:', paymentAmount, 'Date:', paymentDate);
    
    // Basic validation
    if (paymentAmount <= 0) {
        e.preventDefault();
        alert('Please enter a valid payment amount');
        return false;
    }
    
    if (!paymentMethod) {
        e.preventDefault();
        alert('Please select a payment method');
        return false;
    }
    
    if (!paymentDate) {
        e.preventDefault();
        alert('Please select a payment date');
        return false;
    }
    
    // Specific allocation validation
    if (allocationMethod === 'specific_items') {
        if (totalAllocated <= 0) {
            e.preventDefault();
            alert('Please select at least one item to pay');
            return false;
        }
        
        if (Math.abs(totalAllocated - paymentAmount) > 0.01) {
            e.preventDefault();
            alert('Total item allocation (KSH ' + totalAllocated.toFixed(2) + ') must match payment amount (KSH ' + paymentAmount.toFixed(2) + ')');
            return false;
        }
    }
    
    // Payment method specific validation
    let validationPassed = true;
    let errorMessage = '';
    
    if (paymentMethod === 'insurance') {
        const insuranceCompany = document.getElementById('insurance_company_id').value;
        if (!insuranceCompany) {
            validationPassed = false;
            errorMessage = 'Please select an insurance company';
        }
    } else if (paymentMethod === 'mpesa_stk') {
        const mpesaPhone = document.querySelector('input[name="mpesa_phone"]').value;
        const mpesaTransaction = document.querySelector('input[name="mpesa_transaction_id"]').value;
        if (!mpesaPhone) {
            validationPassed = false;
            errorMessage = 'Please enter M-Pesa phone number';
        } else if (!mpesaTransaction) {
            validationPassed = false;
            errorMessage = 'Please enter M-Pesa transaction ID';
        }
    } else if (paymentMethod === 'card') {
        const cardType = document.querySelector('select[name="card_type"]').value;
        const cardLastFour = document.querySelector('input[name="card_last_four"]').value;
        const authCode = document.querySelector('input[name="card_authorization_code"]').value;
        if (!cardType) {
            validationPassed = false;
            errorMessage = 'Please select card type';
        } else if (!cardLastFour) {
            validationPassed = false;
            errorMessage = 'Please enter last 4 digits of card';
        } else if (!authCode) {
            validationPassed = false;
            errorMessage = 'Please enter authorization code';
        }
    } else if (paymentMethod === 'check') {
        const checkNumber = document.querySelector('input[name="check_number"]').value;
        const bankName = document.querySelector('input[name="bank_name"]').value;
        const checkDate = document.querySelector('input[name="check_date"]').value;
        if (!checkNumber) {
            validationPassed = false;
            errorMessage = 'Please enter check number';
        } else if (!bankName) {
            validationPassed = false;
            errorMessage = 'Please enter bank name';
        } else if (!checkDate) {
            validationPassed = false;
            errorMessage = 'Please enter check date';
        }
    } else if (paymentMethod === 'bank_transfer') {
        const transferRef = document.querySelector('input[name="bank_transfer_ref"]').value;
        const bankAccount = document.querySelector('input[name="bank_account"]').value;
        if (!transferRef) {
            validationPassed = false;
            errorMessage = 'Please enter transfer reference number';
        } else if (!bankAccount) {
            validationPassed = false;
            errorMessage = 'Please enter bank account details';
        }
    }
    
    if (!validationPassed) {
        e.preventDefault();
        alert(errorMessage);
        return false;
    }
    
    console.log('Form validation passed, submitting...');
    return true;
});

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    console.log('Page loaded, initializing form...');
    toggleAllocationMethod();
    togglePaymentFields();
    
    // Set default payment date to now
    const now = new Date();
    const timezoneOffset = now.getTimezoneOffset() * 60000;
    const localISOTime = new Date(now - timezoneOffset).toISOString().slice(0, 16);
    document.querySelector('input[name="payment_date"]').value = localISOTime;
    
    console.log('Form initialization complete');
});

// Debug function
function debugForm() {
    console.log('=== FORM DEBUG INFO ===');
    console.log('Payment Amount:', document.getElementById('payment_amount').value);
    console.log('Payment Method:', document.getElementById('payment_method').value);
    console.log('Allocation Method:', document.getElementById('allocation_method').value);
    console.log('Total Allocated:', totalAllocated);
    console.log('Item Payments:', itemLastValues);
    console.log('========================');
}

// Make debug function available globally
window.debugForm = debugForm;
</script>
</body>
</html>
<?php
// post/billing_actions.php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/configz.php';

// Verify CSRF token
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    die("CSRF token validation failed");
}

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'open_register':
        openCashRegister();
        break;
    case 'close_register':
        closeCashRegister();
        break;
    case 'add_cash_transaction':
        addCashTransaction();
        break;
    case 'suspend_register':
        suspendCashRegister();
        break;
    case 'resume_register':
        resumeCashRegister();
        break;
    case 'process_payment':
        processPayment();
    break;
    case 'process_partial_insurance':
        processPartialInsurance();
    break;
    default:
        $_SESSION['alert_type'] = 'danger';
        $_SESSION['alert_message'] = 'Invalid action';
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
}

function openCashRegister() {
    global $mysqli, $user_id;
    
    $opening_balance = floatval($_POST['opening_balance']);
    $notes = sanitizeInput($_POST['notes'] ?? '');
    
    // Check if register is already open for today
    $check_sql = "SELECT register_id FROM cash_register WHERE register_date = CURDATE() AND status = 'open'";
    $check_result = $mysqli->query($check_sql);
    
    if ($check_result->num_rows > 0) {
        $_SESSION['alert_type'] = 'warning';
        $_SESSION['alert_message'] = 'Cash register is already open for today';
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
    }
    
    // Insert new cash register record
    $sql = "INSERT INTO cash_register 
            (register_date, opened_at, opened_by, opening_balance, notes, status) 
            VALUES (CURDATE(), NOW(), ?, ?, ?, 'open')";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("ids", $user_id, $opening_balance, $notes);
    
    if ($stmt->execute()) {
        // Log the action
        $register_id = $mysqli->insert_id;
        logCashRegisterAction($register_id, 'opened', "Register opened with balance: $" . number_format($opening_balance, 2));
        
        $_SESSION['alert_type'] = 'success';
        $_SESSION['alert_message'] = 'Cash register opened successfully';
    } else {
        $_SESSION['alert_type'] = 'danger';
        $_SESSION['alert_message'] = 'Failed to open cash register: ' . $mysqli->error;
    }
    
    $stmt->close();
    header("Location: " . $_SERVER['HTTP_REFERER']);
    exit;
}

function closeCashRegister() {
    global $mysqli, $user_id;
    
    $register_id = intval($_POST['register_id']);
    $actual_cash = floatval($_POST['actual_cash']);
    $closing_notes = sanitizeInput($_POST['closing_notes'] ?? '');
    
    // Get register details
    $register_sql = "SELECT * FROM cash_register WHERE register_id = ? AND status = 'open'";
    $stmt = $mysqli->prepare($register_sql);
    $stmt->bind_param("i", $register_id);
    $stmt->execute();
    $register = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$register) {
        $_SESSION['alert_type'] = 'danger';
        $_SESSION['alert_message'] = 'Cash register not found or already closed';
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
    }
    
    // Calculate totals
    $payment_stats = getRegisterPaymentStats($register_id);
    $cash_sales = $payment_stats['cash_amount'];
    $card_sales = $payment_stats['card_amount'];
    $insurance_sales = $payment_stats['insurance_amount'];
    $total_sales = $payment_stats['total_amount'];
    $total_refunds = $payment_stats['refund_amount'];
    $net_sales = $total_sales - $total_refunds;
    
    $expected_cash = $register['opening_balance'] + $cash_sales;
    $cash_difference = $actual_cash - $expected_cash;
    
    // Update cash register record
    $update_sql = "UPDATE cash_register SET 
                    closed_at = NOW(),
                    closed_by = ?,
                    closing_balance = ?,
                    expected_cash = ?,
                    actual_cash = ?,
                    cash_sales = ?,
                    card_sales = ?,
                    insurance_sales = ?,
                    total_sales = ?,
                    total_refunds = ?,
                    net_sales = ?,
                    status = 'closed',
                    notes = CONCAT(COALESCE(notes, ''), ' | Closing: ', ?)
                   WHERE register_id = ?";
    
    $stmt = $mysqli->prepare($update_sql);
    $stmt->bind_param("iddddddddddsi", 
        $user_id, 
        $actual_cash, 
        $expected_cash, 
        $actual_cash,
        $cash_sales,
        $card_sales,
        $insurance_sales,
        $total_sales,
        $total_refunds,
        $net_sales,
        $closing_notes,
        $register_id
    );
    
    if ($stmt->execute()) {
        // Log the action
        $log_message = "Register closed. Expected: $" . number_format($expected_cash, 2) . 
                      ", Actual: $" . number_format($actual_cash, 2) . 
                      ", Difference: $" . number_format($cash_difference, 2);
        logCashRegisterAction($register_id, 'closed', $log_message);
        
        $_SESSION['alert_type'] = 'success';
        $_SESSION['alert_message'] = 'Cash register closed successfully. ' . 
                                   ($cash_difference != 0 ? 'Difference: $' . number_format($cash_difference, 2) : 'Cash balanced');
    } else {
        $_SESSION['alert_type'] = 'danger';
        $_SESSION['alert_message'] = 'Failed to close cash register: ' . $mysqli->error;
    }
    
    $stmt->close();
    header("Location: " . $_SERVER['HTTP_REFERER']);
    exit;
}

function addCashTransaction() {
    global $mysqli, $user_id;
    
    $register_id = intval($_POST['register_id']);
    $transaction_type = sanitizeInput($_POST['transaction_type']);
    $payment_method = sanitizeInput($_POST['payment_method']);
    $amount = floatval($_POST['amount']);
    $description = sanitizeInput($_POST['description'] ?? '');
    $reference_number = sanitizeInput($_POST['reference_number'] ?? '');
    $invoice_id = intval($_POST['invoice_id'] ?? 0);
    
    // Verify register is open
    if (!isRegisterOpen($register_id)) {
        $_SESSION['alert_type'] = 'danger';
        $_SESSION['alert_message'] = 'Cash register is not open';
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
    }
    
    // Insert transaction
    $sql = "INSERT INTO cash_register_transactions 
            (register_id, transaction_type, payment_method, amount, description, reference_number, invoice_id, created_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("isssssii", $register_id, $transaction_type, $payment_method, $amount, $description, $reference_number, $invoice_id, $user_id);
    
    if ($stmt->execute()) {
        // Log the action
        logCashRegisterAction($register_id, 'transaction_added', 
            "Added $transaction_type: $" . number_format($amount, 2) . " via $payment_method");
        
        $_SESSION['alert_type'] = 'success';
        $_SESSION['alert_message'] = ucfirst($transaction_type) . ' transaction recorded successfully';
    } else {
        $_SESSION['alert_type'] = 'danger';
        $_SESSION['alert_message'] = 'Failed to record transaction: ' . $mysqli->error;
    }
    
    $stmt->close();
    header("Location: " . $_SERVER['HTTP_REFERER']);
    exit;
}

function suspendCashRegister() {
    global $mysqli, $user_id;
    
    $register_id = intval($_POST['register_id']);
    $reason = sanitizeInput($_POST['reason'] ?? 'Temporarily suspended');
    
    $sql = "UPDATE cash_register SET status = 'suspended', notes = CONCAT(COALESCE(notes, ''), ' | Suspended: ', ?) WHERE register_id = ?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("si", $reason, $register_id);
    
    if ($stmt->execute()) {
        logCashRegisterAction($register_id, 'suspended', "Register suspended: $reason");
        $_SESSION['alert_type'] = 'warning';
        $_SESSION['alert_message'] = 'Cash register suspended';
    } else {
        $_SESSION['alert_type'] = 'danger';
        $_SESSION['alert_message'] = 'Failed to suspend cash register';
    }
    
    $stmt->close();
    header("Location: " . $_SERVER['HTTP_REFERER']);
    exit;
}

function resumeCashRegister() {
    global $mysqli, $user_id;
    
    $register_id = intval($_POST['register_id']);
    
    $sql = "UPDATE cash_register SET status = 'open', notes = CONCAT(COALESCE(notes, ''), ' | Resumed: ', NOW()) WHERE register_id = ?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("i", $register_id);
    
    if ($stmt->execute()) {
        logCashRegisterAction($register_id, 'resumed', "Register resumed");
        $_SESSION['alert_type'] = 'success';
        $_SESSION['alert_message'] = 'Cash register resumed';
    } else {
        $_SESSION['alert_type'] = 'danger';
        $_SESSION['alert_message'] = 'Failed to resume cash register';
    }
    
    $stmt->close();
    header("Location: " . $_SERVER['HTTP_REFERER']);
    exit;
}

// Helper functions
function getRegisterPaymentStats($register_id) {
    global $mysqli;
    
    $sql = "SELECT 
                payment_method,
                transaction_type,
                SUM(payment_amount) as total_amount
            FROM payments 
            WHERE register_id = ? 
            GROUP BY payment_method, transaction_type";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("i", $register_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $stats = [
        'cash_amount' => 0,
        'card_amount' => 0,
        'insurance_amount' => 0,
        'total_amount' => 0,
        'refund_amount' => 0
    ];
    
    while ($row = $result->fetch_assoc()) {
        $amount = floatval($row['total_amount']);
        
        if ($row['transaction_type'] === 'refund') {
            $stats['refund_amount'] += $amount;
        } else {
            switch ($row['payment_method']) {
                case 'cash':
                    $stats['cash_amount'] += $amount;
                    break;
                case 'card':
                    $stats['card_amount'] += $amount;
                    break;
                case 'insurance':
                    $stats['insurance_amount'] += $amount;
                    break;
            }
            $stats['total_amount'] += $amount;
        }
    }
    
    $stmt->close();
    return $stats;
}

function isRegisterOpen($register_id) {
    global $mysqli;
    
    $sql = "SELECT status FROM cash_register WHERE register_id = ?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("i", $register_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $register = $result->fetch_assoc();
    $stmt->close();
    
    return $register && $register['status'] === 'open';
}

function logCashRegisterAction($register_id, $action, $description) {
    global $mysqli, $user_id;
    
    $sql = "INSERT INTO cash_register_logs 
            (register_id, user_id, action, description) 
            VALUES (?, ?, ?, ?)";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("iiss", $register_id, $user_id, $action, $description);
    $stmt->execute();
    $stmt->close();
}



function processPayment() {
    global $mysqli, $user_id;
    
    // Validate required fields
    $required = ['amount', 'payment_method', 'payment_date'];
    foreach ($required as $field) {
        if (empty($_POST[$field])) {
            $_SESSION['alert_type'] = 'danger';
            $_SESSION['alert_message'] = "Missing required field: $field";
            header("Location: " . $_SERVER['HTTP_REFERER']);
            exit;
        }
    }
    
    $amount = floatval($_POST['amount']);
    $payment_method = sanitizeInput($_POST['payment_method']);
    $payment_date = sanitizeInput($_POST['payment_date']);
    $invoice_id = intval($_POST['invoice_id'] ?? 0);
    $patient_id = intval($_POST['patient_id'] ?? 0);
    $register_id = intval($_POST['register_id'] ?? 0);
    $notes = sanitizeInput($_POST['notes'] ?? '');
    
    // Validate cash register
    if (!$register_id || !isRegisterOpen($register_id)) {
        $_SESSION['alert_type'] = 'danger';
        $_SESSION['alert_message'] = 'Cash register is not open or invalid';
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
    }
    
    // Validate invoice if provided
    if ($invoice_id) {
        $invoice = getInvoice($invoice_id);
        if (!$invoice) {
            $_SESSION['alert_type'] = 'danger';
            $_SESSION['alert_message'] = 'Invoice not found';
            header("Location: " . $_SERVER['HTTP_REFERER']);
            exit;
        }
        
        $balance_due = $invoice['invoice_amount'] - $invoice['paid_amount'];
        if ($amount > $balance_due) {
            $_SESSION['alert_type'] = 'warning';
            $_SESSION['alert_message'] = "Payment amount ($" . number_format($amount, 2) . ") exceeds invoice balance ($" . number_format($balance_due, 2) . ")";
            header("Location: " . $_SERVER['HTTP_REFERER']);
            exit;
        }
        
        $patient_id = $invoice['invoice_client_id'];
    }
    
    // Validate patient for direct payments
    if (!$invoice_id && !$patient_id) {
        $_SESSION['alert_type'] = 'danger';
        $_SESSION['alert_message'] = 'Patient is required for direct payments';
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
    }
    
    try {
        $mysqli->begin_transaction();
        
        // Generate payment number
        $payment_number = generatePaymentNumber();
        
        // Insert payment record
        $payment_sql = "INSERT INTO payments 
                       (payment_number, payment_invoice_id, register_id, payment_amount, 
                        payment_method, transaction_type, payment_date, payment_status, 
                        payment_received_by, notes, created_at) 
                       VALUES (?, ?, ?, ?, ?, 'payment', ?, 'completed', ?, ?, NOW())";
        
        $stmt = $mysqli->prepare($payment_sql);
        $stmt->bind_param("siidssis", 
            $payment_number,
            $invoice_id,
            $register_id,
            $amount,
            $payment_method,
            $payment_date,
            $user_id,
            $notes
        );
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to create payment: " . $stmt->error);
        }
        
        $payment_id = $mysqli->insert_id;
        $stmt->close();
        
        // Update invoice if applicable
        if ($invoice_id) {
            updateInvoicePayment($invoice_id, $amount);
            
            // Create co-pay invoice if this is an insurance payment and there's remaining balance
            if ($payment_method === 'insurance') {
                $remaining_balance = $invoice['invoice_amount'] - ($invoice['paid_amount'] + $amount);
                if ($remaining_balance > 0) {
                    createCoPayInvoice($invoice_id, $remaining_balance, $payment_id);
                }
            }
        }
        
        // Add method-specific details
        addPaymentDetails($payment_id, $payment_method, $_POST);
        
        // Log the transaction
        logCashRegisterTransaction($register_id, $payment_id, $amount, $payment_method, 'payment');
        
        $mysqli->commit();
        
        // Success response
        $_SESSION['alert_type'] = 'success';
        $_SESSION['alert_message'] = "Payment of $" . number_format($amount, 2) . " processed successfully";
        
        // Redirect to receipt or dashboard
        header("Location: billing_payment_receipt.php?id=" . $payment_id);
        exit;
        
    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = 'danger';
        $_SESSION['alert_message'] = "Payment processing failed: " . $e->getMessage();
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
    }
}

function processPartialInsurance() {
    global $mysqli, $user_id;
    
    $invoice_id = intval($_POST['invoice_id'] ?? 0);
    $amount = floatval($_POST['amount'] ?? 0);
    $insurance_provider = sanitizeInput($_POST['insurance_provider'] ?? '');
    $claim_number = sanitizeInput($_POST['claim_number'] ?? '');
    $approval_code = sanitizeInput($_POST['approval_code'] ?? '');
    $register_id = intval($_POST['register_id'] ?? 0);
    $notes = sanitizeInput($_POST['notes'] ?? '');
    
    // Validate inputs
    if (!$invoice_id || $amount <= 0) {
        $_SESSION['alert_type'] = 'danger';
        $_SESSION['alert_message'] = 'Invalid invoice or amount';
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
    }
    
    if (empty($insurance_provider)) {
        $_SESSION['alert_type'] = 'danger';
        $_SESSION['alert_message'] = 'Insurance provider is required';
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
    }
    
    // Validate register
    if (!$register_id || !isRegisterOpen($register_id)) {
        $_SESSION['alert_type'] = 'danger';
        $_SESSION['alert_message'] = 'Cash register is not open';
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
    }
    
    $invoice = getInvoice($invoice_id);
    if (!$invoice) {
        $_SESSION['alert_type'] = 'danger';
        $_SESSION['alert_message'] = 'Invoice not found';
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
    }
    
    $balance_due = $invoice['invoice_amount'] - $invoice['paid_amount'];
    if ($amount > $balance_due) {
        $_SESSION['alert_type'] = 'warning';
        $_SESSION['alert_message'] = "Payment amount exceeds invoice balance";
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
    }
    
    try {
        $mysqli->begin_transaction();
        
        // Generate payment number
        $payment_number = generatePaymentNumber();
        $payment_date = date('Y-m-d H:i:s');
        
        // Insert insurance payment record
        $payment_sql = "INSERT INTO payments 
                       (payment_number, payment_invoice_id, register_id, payment_amount, 
                        payment_method, transaction_type, payment_date, payment_status, 
                        payment_received_by, notes, created_at) 
                       VALUES (?, ?, ?, ?, 'insurance', 'payment', ?, 'completed', ?, ?, NOW())";
        
        $stmt = $mysqli->prepare($payment_sql);
        $stmt->bind_param("siidssis", 
            $payment_number,
            $invoice_id,
            $register_id,
            $amount,
            $payment_date,
            $user_id,
            $notes
        );
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to create insurance payment: " . $stmt->error);
        }
        
        $payment_id = $mysqli->insert_id;
        $stmt->close();
        
        // Update invoice
        updateInvoicePayment($invoice_id, $amount);
        
        // Add insurance payment details
        $details_sql = "INSERT INTO payment_insurance_details 
                       (payment_id, insurance_provider, claim_number, approval_code, created_at) 
                       VALUES (?, ?, ?, ?, NOW())";
        
        $stmt = $mysqli->prepare($details_sql);
        $stmt->bind_param("isss", $payment_id, $insurance_provider, $claim_number, $approval_code);
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to save insurance details: " . $stmt->error);
        }
        $stmt->close();
        
        // Create co-pay invoice for remaining balance
        $remaining_balance = $invoice['invoice_amount'] - ($invoice['paid_amount'] + $amount);
        if ($remaining_balance > 0) {
            createCoPayInvoice($invoice_id, $remaining_balance, $payment_id);
        }
        
        // Update invoice status if fully paid by insurance
        if ($remaining_balance <= 0) {
            $update_sql = "UPDATE invoices SET invoice_status = 'paid' WHERE invoice_id = ?";
            $stmt = $mysqli->prepare($update_sql);
            $stmt->bind_param("i", $invoice_id);
            $stmt->execute();
            $stmt->close();
        }
        
        // Log transaction
        logCashRegisterTransaction($register_id, $payment_id, $amount, 'insurance', 'payment');
        
        $mysqli->commit();
        
        $_SESSION['alert_type'] = 'success';
        $_SESSION['alert_message'] = "Partial insurance payment of $" . number_format($amount, 2) . " processed. Co-pay invoice created for remaining balance.";
        header("Location: billing_payment_receipt.php?id=" . $payment_id);
        exit;
        
    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = 'danger';
        $_SESSION['alert_message'] = "Insurance payment processing failed: " . $e->getMessage();
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
    }
}

// Helper Functions
function generatePaymentNumber() {
    global $mysqli;
    
    $prefix = 'PAY';
    $year = date('Y');
    
    $sql = "SELECT MAX(payment_number) as last_number FROM payments WHERE payment_number LIKE '$prefix$year%'";
    $result = $mysqli->query($sql);
    $row = $result->fetch_assoc();
    
    if ($row['last_number']) {
        $last_num = intval(substr($row['last_number'], -4));
        $new_num = str_pad($last_num + 1, 4, '0', STR_PAD_LEFT);
    } else {
        $new_num = '0001';
    }
    
    return $prefix . $year . $new_num;
}

function getInvoice($invoice_id) {
    global $mysqli;
    
    $sql = "SELECT * FROM invoices WHERE invoice_id = ?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("i", $invoice_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $invoice = $result->fetch_assoc();
    $stmt->close();
    
    return $invoice;
}

function updateInvoicePayment($invoice_id, $amount) {
    global $mysqli;
    
    $sql = "UPDATE invoices 
            SET paid_amount = COALESCE(paid_amount, 0) + ?,
                invoice_status = CASE 
                    WHEN (COALESCE(paid_amount, 0) + ?) >= invoice_amount THEN 'paid'
                    WHEN (COALESCE(paid_amount, 0) + ?) > 0 THEN 'partial'
                    ELSE invoice_status
                END
            WHERE invoice_id = ?";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("dddi", $amount, $amount, $amount, $invoice_id);
    
    if (!$stmt->execute()) {
        throw new Exception("Failed to update invoice: " . $stmt->error);
    }
    
    $stmt->close();
}

function createCoPayInvoice($original_invoice_id, $remaining_balance, $insurance_payment_id) {
    global $mysqli, $user_id;
    
    // Get original invoice details
    $original = getInvoice($original_invoice_id);
    if (!$original) {
        throw new Exception("Original invoice not found");
    }
    
    // Generate co-pay invoice number
    $invoice_number = generateCoPayInvoiceNumber();
    
    // Create co-pay invoice
    $sql = "INSERT INTO invoices 
           (invoice_number, invoice_type, invoice_status, invoice_date, invoice_due,
            invoice_amount, paid_amount, invoice_currency_code, invoice_category_id,
            invoice_client_id, visit_id, insurance_provider, policy_number,
            patient_responsibility, notes, created_by) 
           VALUES (?, 'patient_co_pay', 'sent', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 15 DAY),
                   ?, 0, 'USD', ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $notes = "Co-pay invoice created after insurance payment. Original invoice: " . $original['invoice_number'];
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("sdiissssi", 
        $invoice_number,
        $remaining_balance,
        $original['invoice_category_id'],
        $original['invoice_client_id'],
        $original['visit_id'],
        $original['insurance_provider'],
        $original['policy_number'],
        $remaining_balance,
        $notes,
        $user_id
    );
    
    if (!$stmt->execute()) {
        throw new Exception("Failed to create co-pay invoice: " . $stmt->error);
    }
    
    $co_pay_invoice_id = $mysqli->insert_id;
    $stmt->close();
    
    // Copy line items from original invoice
    copyInvoiceItems($original_invoice_id, $co_pay_invoice_id, $remaining_balance);
    
    return $co_pay_invoice_id;
}

function generateCoPayInvoiceNumber() {
    global $mysqli;
    
    $prefix = 'COP';
    $year = date('Y');
    
    $sql = "SELECT MAX(invoice_number) as last_number FROM invoices WHERE invoice_number LIKE '$prefix$year%'";
    $result = $mysqli->query($sql);
    $row = $result->fetch_assoc();
    
    if ($row['last_number']) {
        $last_num = intval(substr($row['last_number'], -4));
        $new_num = str_pad($last_num + 1, 4, '0', STR_PAD_LEFT);
    } else {
        $new_num = '0001';
    }
    
    return $prefix . $year . $new_num;
}

function copyInvoiceItems($original_invoice_id, $new_invoice_id, $remaining_balance) {
    global $mysqli;
    
    $sql = "INSERT INTO invoice_items 
           (item_invoice_id, item_name, item_description, item_quantity, item_price, 
            item_tax, item_total, item_order, item_tax_id, item_product_id)
           SELECT ?, item_name, item_description, item_quantity, item_price,
                  item_tax, item_total, item_order, item_tax_id, item_product_id
           FROM invoice_items 
           WHERE item_invoice_id = ?";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("ii", $new_invoice_id, $original_invoice_id);
    
    if (!$stmt->execute()) {
        throw new Exception("Failed to copy invoice items: " . $stmt->error);
    }
    
    $stmt->close();
}

function addPaymentDetails($payment_id, $payment_method, $post_data) {
    global $mysqli;
    
    switch ($payment_method) {
        case 'card':
            $sql = "INSERT INTO payment_card_details 
                   (payment_id, card_type, card_last_four, auth_code, created_at) 
                   VALUES (?, ?, ?, ?, NOW())";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param("isss", 
                $payment_id,
                $post_data['card_type'] ?? '',
                $post_data['card_last_four'] ?? '',
                $post_data['auth_code'] ?? ''
            );
            break;
            
        case 'check':
            $sql = "INSERT INTO payment_check_details 
                   (payment_id, check_number, bank_name, check_date, created_at) 
                   VALUES (?, ?, ?, ?, NOW())";
            $stmt = $mysqli->prepare($sql);
            $check_date = !empty($post_data['check_date']) ? $post_data['check_date'] : date('Y-m-d');
            $stmt->bind_param("isss", 
                $payment_id,
                $post_data['check_number'] ?? '',
                $post_data['bank_name'] ?? '',
                $check_date
            );
            break;
            
        case 'bank_transfer':
            $sql = "INSERT INTO payment_bank_details 
                   (payment_id, reference_number, bank_name, created_at) 
                   VALUES (?, ?, ?, NOW())";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param("iss", 
                $payment_id,
                $post_data['reference_number'] ?? '',
                $post_data['bank_name'] ?? ''
            );
            break;
            
        case 'mpesa_stk':
            $sql = "INSERT INTO payment_mpesa_details 
                   (payment_id, phone_number, reference_number, created_at) 
                   VALUES (?, ?, ?, NOW())";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param("iss", 
                $payment_id,
                $post_data['mpesa_phone'] ?? '',
                $post_data['mpesa_reference'] ?? ''
            );
            break;
            
        case 'insurance':
            $sql = "INSERT INTO payment_insurance_details 
                   (payment_id, insurance_provider, claim_number, approval_code, created_at) 
                   VALUES (?, ?, ?, ?, NOW())";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param("isss", 
                $payment_id,
                $post_data['insurance_provider'] ?? '',
                $post_data['claim_number'] ?? '',
                $post_data['approval_code'] ?? ''
            );
            break;
            
        default:
            return; // No details needed for cash
    }
    
    if (isset($stmt)) {
        if (!$stmt->execute()) {
            throw new Exception("Failed to save payment details: " . $stmt->error);
        }
        $stmt->close();
    }
}

function logCashRegisterTransaction($register_id, $payment_id, $amount, $method, $type) {
    global $mysqli, $user_id;
    
    $sql = "INSERT INTO cash_register_transactions 
           (register_id, transaction_type, payment_method, amount, description, 
            payment_id, created_by, created_at) 
           VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
    
    $description = "Payment #$payment_id - $method";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("issdsii", 
        $register_id,
        $type,
        $method,
        $amount,
        $description,
        $payment_id,
        $user_id
    );
    
    if (!$stmt->execute()) {
        throw new Exception("Failed to log transaction: " . $stmt->error);
    }
    
    $stmt->close();
}
?>
?>
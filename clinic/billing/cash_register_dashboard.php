<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/audit_functions.php';

// Initialize variables
$selected_register_id = isset($_GET['register_id']) ? intval($_GET['register_id']) : 0;

// Get today's date for filters
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$week_start = date('Y-m-d', strtotime('monday this week'));
$month_start = date('Y-m-d', strtotime('first day of this month'));

// Default Column Sortby/Order Filter
$sort = "cr.register_name";
$order = "ASC";

// Filter parameters
$status_filter = $_GET['status'] ?? '';
$transaction_type_filter = $_GET['transaction_type'] ?? '';
$payment_method_filter = $_GET['payment_method'] ?? '';

// Date Range Filter
$dtf = sanitizeInput($_GET['dtf'] ?? '');
$dtt = sanitizeInput($_GET['dtt'] ?? '');

// Search Query
$q = sanitizeInput($_GET['q'] ?? '');
if (!empty($q)) {
    $search_query = "AND (
        cr.register_name LIKE '%$q%' 
        OR u.user_name LIKE '%$q%'
        OR p.first_name LIKE '%$q%'
        OR p.last_name LIKE '%$q%'
        OR p.patient_mrn LIKE '%$q%'
        OR i.invoice_number LIKE '%$q%'
        OR crt.reference_number LIKE '%$q%'
        OR crt.description LIKE '%$q%'
    )";
} else {
    $search_query = '';
}

// Build WHERE clause for filters
$where_conditions = ["1=1"];
$params = [];
$types = '';

if (!empty($status_filter)) {
    $where_conditions[] = "cr.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

if (!empty($selected_register_id)) {
    $where_conditions[] = "cr.register_id = ?";
    $params[] = $selected_register_id;
    $types .= 'i';
}

// Date range filter for transactions
$transactions_date_where = "DATE(crt.created_at) >= CURDATE()"; // Default to today
if (!empty($dtf) && !empty($dtt)) {
    $transactions_date_where = "DATE(crt.created_at) BETWEEN ? AND ?";
    $transactions_params = [$dtf, $dtt];
} elseif (isset($_GET['canned_date'])) {
    switch ($_GET['canned_date']) {
        case 'today':
            $transactions_date_where = "DATE(crt.created_at) = CURDATE()";
            break;
        case 'yesterday':
            $transactions_date_where = "DATE(crt.created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
            break;
        case 'thisweek':
            $transactions_date_where = "YEARWEEK(crt.created_at) = YEARWEEK(CURDATE())";
            break;
        case 'lastweek':
            $transactions_date_where = "YEARWEEK(crt.created_at) = YEARWEEK(DATE_SUB(CURDATE(), INTERVAL 7 DAY))";
            break;
        case 'thismonth':
            $transactions_date_where = "MONTH(crt.created_at) = MONTH(CURDATE()) AND YEAR(crt.created_at) = YEAR(CURDATE())";
            break;
        case 'lastmonth':
            $transactions_date_where = "MONTH(crt.created_at) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND YEAR(crt.created_at) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))";
            break;
    }
}

$where_clause = implode(' AND ', $where_conditions);

// Get all cash registers
$registers_sql = "
    SELECT SQL_CALC_FOUND_ROWS 
        cr.*,
        u.user_name as opened_by_name,
        u2.user_name as closed_by_name,
        (SELECT COUNT(*) FROM cash_register_transactions WHERE register_id = cr.register_id) as transaction_count,
        (SELECT SUM(amount) FROM cash_register_transactions WHERE register_id = cr.register_id AND transaction_type IN ('payment', 'deposit')) as total_income,
        (SELECT SUM(amount) FROM cash_register_transactions WHERE register_id = cr.register_id AND transaction_type IN ('refund', 'expense', 'withdrawal')) as total_expenses,
        (SELECT MAX(created_at) FROM cash_register_transactions WHERE register_id = cr.register_id) as last_transaction_time
    FROM cash_register cr
    LEFT JOIN users u ON cr.opened_by = u.user_id
    LEFT JOIN users u2 ON cr.closed_by = u2.user_id
    WHERE $where_clause
    $search_query
    ORDER BY $sort $order
";

$registers_stmt = $mysqli->prepare($registers_sql);
if (!empty($params)) {
    $registers_stmt->bind_param($types, ...$params);
}
$registers_stmt->execute();
$cash_registers = $registers_stmt->get_result();

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get recent transactions for selected register or all registers
if ($selected_register_id > 0) {
    $transactions_sql = "
        SELECT crt.*,
               cr.register_name,
               u.user_name as created_by_name,
               p.first_name as patient_first_name,
               p.last_name as patient_last_name,
               p.patient_mrn,
               i.invoice_number,
               pm.payment_method_name
        FROM cash_register_transactions crt
        JOIN cash_register cr ON crt.register_id = cr.register_id
        LEFT JOIN users u ON crt.created_by = u.user_id
        LEFT JOIN invoices i ON crt.invoice_id = i.invoice_id
        LEFT JOIN patients p ON i.patient_id = p.patient_id
        LEFT JOIN payment_methods pm ON pm.payment_method = crt.payment_method
        WHERE crt.register_id = ?
        AND $transactions_date_where
    ";
    
    if (!empty($transaction_type_filter)) {
        $transactions_sql .= " AND crt.transaction_type = ?";
    }
    
    if (!empty($payment_method_filter)) {
        $transactions_sql .= " AND crt.payment_method = ?";
    }
    
    $transactions_sql .= " ORDER BY crt.created_at DESC LIMIT 50";
    
    $transactions_stmt = $mysqli->prepare($transactions_sql);
    $transactions_params = [$selected_register_id];
    
    if (!empty($transaction_type_filter)) {
        $transactions_params[] = $transaction_type_filter;
    }
    
    if (!empty($payment_method_filter)) {
        $transactions_params[] = $payment_method_filter;
    }
    
    if (!empty($transactions_params)) {
        $param_types = str_repeat('s', count($transactions_params));
        $transactions_stmt->bind_param($param_types, ...$transactions_params);
    }
    
    $transactions_stmt->execute();
    $recent_transactions = $transactions_stmt->get_result();
} else {
    $recent_transactions = [];
}

// Get statistics
$total_registers = $mysqli->query("SELECT COUNT(*) FROM cash_register WHERE is_active = 1")->fetch_row()[0];
$open_registers = $mysqli->query("SELECT COUNT(*) FROM cash_register WHERE status = 'open'")->fetch_row()[0];
$closed_registers = $mysqli->query("SELECT COUNT(*) FROM cash_register WHERE status = 'closed'")->fetch_row()[0];
$total_cash_balance = $mysqli->query("SELECT SUM(cash_balance) FROM cash_register WHERE is_active = 1")->fetch_assoc()['SUM(cash_balance)'] ?? 0;

// Today's transaction statistics
$today_stats_sql = "
    SELECT 
        COUNT(*) as total_transactions,
        SUM(CASE WHEN transaction_type IN ('payment', 'deposit') THEN amount ELSE 0 END) as total_income,
        SUM(CASE WHEN transaction_type IN ('refund', 'expense', 'withdrawal') THEN amount ELSE 0 END) as total_expenses,
        COUNT(DISTINCT register_id) as active_registers_today
    FROM cash_register_transactions 
    WHERE DATE(created_at) = CURDATE()
";

$today_stats_result = $mysqli->query($today_stats_sql);
$today_stats = $today_stats_result->fetch_assoc();

// Get transaction type distribution for today
$type_distribution_sql = "
    SELECT 
        transaction_type,
        COUNT(*) as count,
        SUM(amount) as total_amount
    FROM cash_register_transactions 
    WHERE DATE(created_at) = CURDATE()
    GROUP BY transaction_type
    ORDER BY total_amount DESC
";

$type_distribution_result = $mysqli->query($type_distribution_sql);
$type_distribution = [];
while ($row = $type_distribution_result->fetch_assoc()) {
    $type_distribution[] = $row;
}

// Get payment method distribution for today
$payment_distribution_sql = "
    SELECT 
        payment_method,
        COUNT(*) as count,
        SUM(amount) as total_amount
    FROM cash_register_transactions 
    WHERE DATE(created_at) = CURDATE()
    AND payment_method IS NOT NULL
    GROUP BY payment_method
    ORDER BY total_amount DESC
";

$payment_distribution_result = $mysqli->query($payment_distribution_sql);
$payment_distribution = [];
while ($row = $payment_distribution_result->fetch_assoc()) {
    $payment_distribution[] = $row;
}

// Get unique statuses for filter
$statuses = $mysqli->query("SELECT DISTINCT status FROM cash_register WHERE status IS NOT NULL ORDER BY status");

// Get unique transaction types for filter
$transaction_types = $mysqli->query("SELECT DISTINCT transaction_type FROM cash_register_transactions WHERE transaction_type IS NOT NULL ORDER BY transaction_type");

// Get unique payment methods for filter
$payment_methods = $mysqli->query("SELECT DISTINCT payment_method FROM cash_register_transactions WHERE payment_method IS NOT NULL ORDER BY payment_method");

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token'] ?? '');
    
    // Validate CSRF token
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        header("Location: cash_register_dashboard.php");
        exit;
    }

    // Check which form was submitted
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'open_register':
                handleOpenRegister($mysqli, $session_user_id);
                break;
                
            case 'close_register':
                handleCloseRegister($mysqli, $session_user_id);
                break;
                
            case 'add_transaction':
                handleAddTransaction($mysqli, $session_user_id);
                break;
                
            case 'delete_transaction':
                handleDeleteTransaction($mysqli, $session_user_id);
                break;
                
            case 'select_register':
                $selected_register_id = intval($_POST['register_id'] ?? 0);
                header("Location: cash_register_dashboard.php?register_id=" . $selected_register_id);
                exit;
                break;
        }
    }
}

// Functions
function handleOpenRegister($mysqli, $user_id) {
    $register_name = sanitizeInput($_POST['register_name'] ?? '');
    $opening_balance = floatval($_POST['opening_balance'] ?? 0);
    
    if (empty($register_name)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Register name is required";
        return false;
    }
    
    // Start transaction
    $mysqli->begin_transaction();
    
    try {
        // Create cash register
        $register_sql = "INSERT INTO cash_register (
            register_name,
            cash_balance,
            status,
            opened_by,
            opened_at
        ) VALUES (?, ?, 'open', ?, NOW())";
        
        $register_stmt = $mysqli->prepare($register_sql);
        if (!$register_stmt) {
            throw new Exception("Prepare failed for register: " . $mysqli->error);
        }
        
        $register_stmt->bind_param(
            "sdi",
            $register_name,
            $opening_balance,
            $user_id
        );
        
        if (!$register_stmt->execute()) {
            throw new Exception("Error opening register: " . $register_stmt->error);
        }
        
        $register_id = $register_stmt->insert_id;
        
        // Record initial deposit if opening balance > 0
        if ($opening_balance > 0) {
            $deposit_sql = "INSERT INTO cash_register_transactions (
                register_id,
                transaction_type,
                payment_method,
                amount,
                description,
                reference_number,
                created_by,
                created_at
            ) VALUES (?, 'deposit', 'cash', ?, 'Opening balance', 'OPENING', ?, NOW())";
            
            $deposit_stmt = $mysqli->prepare($deposit_sql);
            $deposit_stmt->bind_param(
                "idi",
                $register_id,
                $opening_balance,
                $user_id
            );
            
            if (!$deposit_stmt->execute()) {
                throw new Exception("Error recording opening deposit: " . $deposit_stmt->error);
            }
        }
        
        // AUDIT LOG: Log register opening
        audit_log($mysqli, [
            'user_id'     => $user_id,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'CREATE',
            'module'      => 'CashRegister',
            'table_name'  => 'cash_register',
            'entity_type' => 'cash_register',
            'record_id'   => $register_id,
            'patient_id'  => 0,
            'visit_id'    => 0,
            'description' => "Opened cash register: " . $register_name,
            'status'      => 'SUCCESS',
            'old_values'  => null,
            'new_values'  => [
                'register_name' => $register_name,
                'cash_balance' => $opening_balance,
                'status' => 'open',
                'opened_by' => $user_id
            ]
        ]);
        
        $mysqli->commit();
        
        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Cash register opened successfully!";
        
        // Redirect to the new register
        header("Location: cash_register_dashboard.php?register_id=" . $register_id);
        exit;
        
    } catch (Exception $e) {
        $mysqli->rollback();
        
        // AUDIT LOG: Log failed register opening
        audit_log($mysqli, [
            'user_id'     => $user_id,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'CREATE',
            'module'      => 'CashRegister',
            'table_name'  => 'cash_register',
            'entity_type' => 'cash_register',
            'record_id'   => 0,
            'patient_id'  => 0,
            'visit_id'    => 0,
            'description' => "Failed to open cash register: " . $register_name,
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => null,
            'error'       => $e->getMessage()
        ]);
        
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error: " . $e->getMessage();
        return false;
    }
}

function handleCloseRegister($mysqli, $user_id) {
    $register_id = intval($_POST['register_id'] ?? 0);
    $closing_balance = floatval($_POST['closing_balance'] ?? 0);
    $notes = sanitizeInput($_POST['notes'] ?? '');
    
    if (!$register_id) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Register ID required";
        return false;
    }
    
    // Start transaction
    $mysqli->begin_transaction();
    
    try {
        // Get current register details for audit log
        $register_sql = "SELECT * FROM cash_register WHERE register_id = ?";
        $register_stmt = $mysqli->prepare($register_sql);
        $register_stmt->bind_param("i", $register_id);
        $register_stmt->execute();
        $register_result = $register_stmt->get_result();
        $register = $register_result->fetch_assoc();
        
        if (!$register) {
            throw new Exception("Register not found");
        }
        
        if ($register['status'] == 'closed') {
            throw new Exception("Register is already closed");
        }
        
        // Close the register
        $close_sql = "UPDATE cash_register 
                     SET status = 'closed',
                         closed_by = ?,
                         closed_at = NOW(),
                         updated_at = NOW()
                     WHERE register_id = ?";
        
        $close_stmt = $mysqli->prepare($close_sql);
        $close_stmt->bind_param("ii", $user_id, $register_id);
        
        if (!$close_stmt->execute()) {
            throw new Exception("Failed to close register: " . $mysqli->error);
        }
        
        // Record closing balance adjustment if different from actual
        $actual_balance = $register['cash_balance'] ?? 0;
        if ($closing_balance != $actual_balance) {
            $difference = $closing_balance - $actual_balance;
            $adjustment_type = $difference > 0 ? 'deposit' : 'withdrawal';
            $adjustment_amount = abs($difference);
            
            $adjustment_sql = "INSERT INTO cash_register_transactions (
                register_id,
                transaction_type,
                payment_method,
                amount,
                description,
                reference_number,
                created_by,
                created_at
            ) VALUES (?, ?, 'cash', ?, ?, 'CLOSING_ADJUSTMENT', ?, NOW())";
            
            $adjustment_stmt = $mysqli->prepare($adjustment_sql);
            $adjustment_description = "Closing balance adjustment: " . ($difference > 0 ? 'Add' : 'Remove') . " " . number_format($adjustment_amount, 2);
            $adjustment_stmt->bind_param(
                "isdssi",
                $register_id,
                $adjustment_type,
                $adjustment_amount,
                $adjustment_description,
                $user_id
            );
            
            if (!$adjustment_stmt->execute()) {
                throw new Exception("Error recording adjustment: " . $adjustment_stmt->error);
            }
            
            // Update final balance
            $update_balance_sql = "UPDATE cash_register SET cash_balance = ?, updated_at = NOW() WHERE register_id = ?";
            $update_balance_stmt = $mysqli->prepare($update_balance_sql);
            $update_balance_stmt->bind_param("di", $closing_balance, $register_id);
            $update_balance_stmt->execute();
        }
        
        // AUDIT LOG: Log register closing
        audit_log($mysqli, [
            'user_id'     => $user_id,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'UPDATE',
            'module'      => 'CashRegister',
            'table_name'  => 'cash_register',
            'entity_type' => 'cash_register',
            'record_id'   => $register_id,
            'patient_id'  => 0,
            'visit_id'    => 0,
            'description' => "Closed cash register: " . $register['register_name'],
            'status'      => 'SUCCESS',
            'old_values'  => [
                'status' => 'open',
                'cash_balance' => $actual_balance
            ],
            'new_values'  => [
                'status' => 'closed',
                'cash_balance' => $closing_balance,
                'closed_by' => $user_id,
                'closed_at' => date('Y-m-d H:i:s')
            ]
        ]);
        
        $mysqli->commit();
        
        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Cash register closed successfully!";
        
        // Redirect back to dashboard
        header("Location: cash_register_dashboard.php");
        exit;
        
    } catch (Exception $e) {
        $mysqli->rollback();
        
        // AUDIT LOG: Log failed register closing
        audit_log($mysqli, [
            'user_id'     => $user_id,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'UPDATE',
            'module'      => 'CashRegister',
            'table_name'  => 'cash_register',
            'entity_type' => 'cash_register',
            'record_id'   => $register_id,
            'patient_id'  => 0,
            'visit_id'    => 0,
            'description' => "Failed to close cash register #" . $register_id,
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => null,
            'error'       => $e->getMessage()
        ]);
        
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error: " . $e->getMessage();
        return false;
    }
}

function handleAddTransaction($mysqli, $user_id) {
    $register_id = intval($_POST['register_id'] ?? 0);
    $transaction_type = sanitizeInput($_POST['transaction_type'] ?? '');
    $payment_method = sanitizeInput($_POST['payment_method'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0);
    $description = sanitizeInput($_POST['description'] ?? '');
    $reference_number = sanitizeInput($_POST['reference_number'] ?? '');
    $invoice_id = intval($_POST['invoice_id'] ?? 0);
    
    if (!$register_id || !$transaction_type || $amount <= 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid parameters";
        return false;
    }
    
    // Start transaction
    $mysqli->begin_transaction();
    
    try {
        // Check if register is open
        $register_sql = "SELECT * FROM cash_register WHERE register_id = ? AND status = 'open'";
        $register_stmt = $mysqli->prepare($register_sql);
        $register_stmt->bind_param("i", $register_id);
        $register_stmt->execute();
        $register_result = $register_stmt->get_result();
        $register = $register_result->fetch_assoc();
        
        if (!$register) {
            throw new Exception("Register is not open or not found");
        }
        
        // Insert transaction
        $transaction_sql = "INSERT INTO cash_register_transactions (
            register_id,
            transaction_type,
            payment_method,
            amount,
            description,
            reference_number,
            invoice_id,
            created_by,
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $transaction_stmt = $mysqli->prepare($transaction_sql);
        if (!$transaction_stmt) {
            throw new Exception("Prepare failed for transaction: " . $mysqli->error);
        }
        
        $transaction_stmt->bind_param(
            "issdssii",
            $register_id,
            $transaction_type,
            $payment_method,
            $amount,
            $description,
            $reference_number,
            $invoice_id,
            $user_id
        );
        
        if (!$transaction_stmt->execute()) {
            throw new Exception("Error adding transaction: " . $transaction_stmt->error);
        }
        
        $transaction_id = $transaction_stmt->insert_id;
        
        // Update cash balance
        $balance_change = 0;
        if (in_array($transaction_type, ['payment', 'deposit'])) {
            $balance_change = $amount;
        } elseif (in_array($transaction_type, ['refund', 'expense', 'withdrawal'])) {
            $balance_change = -$amount;
        }
        
        $update_balance_sql = "UPDATE cash_register 
                              SET cash_balance = cash_balance + ?, 
                                  updated_at = NOW() 
                              WHERE register_id = ?";
        
        $update_balance_stmt = $mysqli->prepare($update_balance_sql);
        $update_balance_stmt->bind_param("di", $balance_change, $register_id);
        
        if (!$update_balance_stmt->execute()) {
            throw new Exception("Error updating cash balance: " . $update_balance_stmt->error);
        }
        
        // AUDIT LOG: Log transaction
        audit_log($mysqli, [
            'user_id'     => $user_id,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'CREATE',
            'module'      => 'CashRegister',
            'table_name'  => 'cash_register_transactions',
            'entity_type' => 'cash_transaction',
            'record_id'   => $transaction_id,
            'patient_id'  => 0, // Will be fetched from invoice if available
            'visit_id'    => 0, // Will be fetched from invoice if available
            'description' => "Added " . $transaction_type . " transaction: " . $description,
            'status'      => 'SUCCESS',
            'old_values'  => null,
            'new_values'  => [
                'register_id' => $register_id,
                'transaction_type' => $transaction_type,
                'payment_method' => $payment_method,
                'amount' => $amount,
                'description' => $description,
                'reference_number' => $reference_number,
                'invoice_id' => $invoice_id
            ]
        ]);
        
        $mysqli->commit();
        
        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Transaction added successfully!";
        
        // Redirect back to register view
        header("Location: cash_register_dashboard.php?register_id=" . $register_id);
        exit;
        
    } catch (Exception $e) {
        $mysqli->rollback();
        
        // AUDIT LOG: Log failed transaction
        audit_log($mysqli, [
            'user_id'     => $user_id,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'CREATE',
            'module'      => 'CashRegister',
            'table_name'  => 'cash_register_transactions',
            'entity_type' => 'cash_transaction',
            'record_id'   => 0,
            'patient_id'  => 0,
            'visit_id'    => 0,
            'description' => "Failed to add transaction to register #" . $register_id,
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => null,
            'error'       => $e->getMessage()
        ]);
        
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error: " . $e->getMessage();
        return false;
    }
}

function handleDeleteTransaction($mysqli, $user_id) {
    $transaction_id = intval($_POST['transaction_id'] ?? 0);
    
    if (!$transaction_id) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Transaction ID required";
        return false;
    }
    
    // Start transaction
    $mysqli->begin_transaction();
    
    try {
        // Get transaction details for audit log and reversal
        $transaction_sql = "SELECT crt.*, cr.register_id, cr.register_name 
                           FROM cash_register_transactions crt
                           JOIN cash_register cr ON crt.register_id = cr.register_id
                           WHERE crt.transaction_id = ?";
        $transaction_stmt = $mysqli->prepare($transaction_sql);
        $transaction_stmt->bind_param("i", $transaction_id);
        $transaction_stmt->execute();
        $transaction_result = $transaction_stmt->get_result();
        $transaction = $transaction_result->fetch_assoc();
        
        if (!$transaction) {
            throw new Exception("Transaction not found");
        }
        
        // Reverse the cash balance effect
        $balance_change = 0;
        if (in_array($transaction['transaction_type'], ['payment', 'deposit'])) {
            $balance_change = -$transaction['amount'];
        } elseif (in_array($transaction['transaction_type'], ['refund', 'expense', 'withdrawal'])) {
            $balance_change = $transaction['amount'];
        }
        
        $update_balance_sql = "UPDATE cash_register 
                              SET cash_balance = cash_balance + ?, 
                                  updated_at = NOW() 
                              WHERE register_id = ?";
        
        $update_balance_stmt = $mysqli->prepare($update_balance_sql);
        $update_balance_stmt->bind_param("di", $balance_change, $transaction['register_id']);
        
        if (!$update_balance_stmt->execute()) {
            throw new Exception("Error reversing cash balance: " . $update_balance_stmt->error);
        }
        
        // Delete the transaction
        $delete_sql = "DELETE FROM cash_register_transactions WHERE transaction_id = ?";
        $delete_stmt = $mysqli->prepare($delete_sql);
        $delete_stmt->bind_param("i", $transaction_id);
        
        if (!$delete_stmt->execute()) {
            throw new Exception("Failed to delete transaction");
        }
        
        // AUDIT LOG: Log transaction deletion
        audit_log($mysqli, [
            'user_id'     => $user_id,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'DELETE',
            'module'      => 'CashRegister',
            'table_name'  => 'cash_register_transactions',
            'entity_type' => 'cash_transaction',
            'record_id'   => $transaction_id,
            'patient_id'  => 0,
            'visit_id'    => 0,
            'description' => "Deleted transaction from register: " . $transaction['register_name'],
            'status'      => 'SUCCESS',
            'old_values'  => [
                'transaction_type' => $transaction['transaction_type'] ?? '',
                'amount' => $transaction['amount'] ?? 0,
                'description' => $transaction['description'] ?? '',
                'register_id' => $transaction['register_id'] ?? 0
            ],
            'new_values'  => null
        ]);
        
        $mysqli->commit();
        
        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Transaction deleted successfully!";
        
        // Redirect back to register view
        header("Location: cash_register_dashboard.php?register_id=" . $transaction['register_id']);
        exit;
        
    } catch (Exception $e) {
        $mysqli->rollback();
        
        // AUDIT LOG: Log failed deletion
        audit_log($mysqli, [
            'user_id'     => $user_id,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'DELETE',
            'module'      => 'CashRegister',
            'table_name'  => 'cash_register_transactions',
            'entity_type' => 'cash_transaction',
            'record_id'   => $transaction_id,
            'patient_id'  => 0,
            'visit_id'    => 0,
            'description' => "Failed to delete transaction #" . $transaction_id,
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => null,
            'error'       => $e->getMessage()
        ]);
        
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error: " . $e->getMessage();
        return false;
    }
}

// Get list of open invoices for dropdown
$open_invoices_sql = "
    SELECT i.invoice_id, i.invoice_number, i.patient_name, i.total_amount, i.amount_due
    FROM invoices i
    WHERE i.invoice_status IN ('issued', 'partially_paid')
    AND i.amount_due > 0
    ORDER BY i.invoice_date DESC
    LIMIT 20
";

$open_invoices_result = $mysqli->query($open_invoices_sql);
$open_invoices = [];
while ($row = $open_invoices_result->fetch_assoc()) {
    $open_invoices[] = $row;
}

// Get selected register details if any
$selected_register = null;
if ($selected_register_id > 0) {
    $selected_register_sql = "
        SELECT cr.*,
               u.user_name as opened_by_name,
               u2.user_name as closed_by_name,
               (SELECT SUM(amount) FROM cash_register_transactions WHERE register_id = cr.register_id AND transaction_type IN ('payment', 'deposit')) as total_income,
               (SELECT SUM(amount) FROM cash_register_transactions WHERE register_id = cr.register_id AND transaction_type IN ('refund', 'expense', 'withdrawal')) as total_expenses,
               (SELECT COUNT(*) FROM cash_register_transactions WHERE register_id = cr.register_id) as transaction_count
        FROM cash_register cr
        LEFT JOIN users u ON cr.opened_by = u.user_id
        LEFT JOIN users u2 ON cr.closed_by = u2.user_id
        WHERE cr.register_id = ?
    ";
    
    $selected_register_stmt = $mysqli->prepare($selected_register_sql);
    $selected_register_stmt->bind_param("i", $selected_register_id);
    $selected_register_stmt->execute();
    $selected_register_result = $selected_register_stmt->get_result();
    $selected_register = $selected_register_result->fetch_assoc();
}

// Reset pointer for main query
if ($cash_registers) {
    $cash_registers->data_seek(0);
}
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-cash-register mr-2"></i>
            Cash Register Management
        </h3>
        <div class="card-tools">
            <div class="btn-group">
                <button class="btn btn-success" data-toggle="modal" data-target="#openRegisterModal">
                    <i class="fas fa-plus mr-2"></i>Open New Register
                </button>
                <?php if ($selected_register && $selected_register['status'] == 'open'): ?>
                    <button class="btn btn-warning ml-2" data-toggle="modal" data-target="#closeRegisterModal">
                        <i class="fas fa-lock mr-2"></i>Close Register
                    </button>
                    <button class="btn btn-info ml-2" data-toggle="modal" data-target="#addTransactionModal">
                        <i class="fas fa-plus-circle mr-2"></i>Add Transaction
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card-header pb-2 pt-3">
        <form autocomplete="off">
            <div class="row">
                <div class="col-md-5">
                    <div class="form-group">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search registers, users, reference numbers..." autofocus>
                            <div class="input-group-append">
                                <button class="btn btn-secondary" type="button" data-toggle="collapse" data-target="#advancedFilter"><i class="fas fa-filter"></i></button>
                                <button class="btn btn-primary"><i class="fa fa-search"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-7">
                    <div class="btn-toolbar form-group float-right">
                        <div class="btn-group">
                            <span class="btn btn-light border">
                                <i class="fas fa-cash-register text-info mr-1"></i>
                                Total: <strong><?php echo $total_registers; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-lock-open text-success mr-1"></i>
                                Open: <strong><?php echo $open_registers; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-lock text-warning mr-1"></i>
                                Closed: <strong><?php echo $closed_registers; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-dollar-sign text-primary mr-1"></i>
                                Balance: <strong>KSH <?php echo number_format($total_cash_balance, 2); ?></strong>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php if ($status_filter || isset($_GET['dtf'])) { echo "show"; } ?>" id="advancedFilter">
                <div class="row">
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Status</label>
                            <select class="form-control select2" name="status" onchange="this.form.submit()">
                                <option value="">- All Statuses -</option>
                                <?php while($status = $statuses->fetch_assoc()): ?>
                                    <option value="<?php echo htmlspecialchars($status['status']); ?>" <?php if ($status_filter == $status['status']) { echo "selected"; } ?>>
                                        <?php echo ucfirst(htmlspecialchars($status['status'])); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Date Range</label>
                            <select onchange="this.form.submit()" class="form-control select2" name="canned_date">
                                <option <?php if (($_GET['canned_date'] ?? '') == "custom") { echo "selected"; } ?> value="custom">Custom</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "today") { echo "selected"; } ?> value="today">Today</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "yesterday") { echo "selected"; } ?> value="yesterday">Yesterday</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "thisweek") { echo "selected"; } ?> value="thisweek">This Week</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "lastweek") { echo "selected"; } ?> value="lastweek">Last Week</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "thismonth") { echo "selected"; } ?> value="thismonth">This Month</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "lastmonth") { echo "selected"; } ?> value="lastmonth">Last Month</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Date from</label>
                            <input onchange="this.form.submit()" type="date" class="form-control" name="dtf" max="2999-12-31" value="<?php echo nullable_htmlentities($dtf); ?>">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Date to</label>
                            <input onchange="this.form.submit()" type="date" class="form-control" name="dtt" max="2999-12-31" value="<?php echo nullable_htmlentities($dtt); ?>">
                        </div>
                    </div>
                    <?php if ($selected_register_id > 0): ?>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Transaction Type</label>
                            <select class="form-control select2" name="transaction_type" onchange="this.form.submit()">
                                <option value="">- All Types -</option>
                                <?php while($type = $transaction_types->fetch_assoc()): ?>
                                    <option value="<?php echo htmlspecialchars($type['transaction_type']); ?>" <?php if ($transaction_type_filter == $type['transaction_type']) { echo "selected"; } ?>>
                                        <?php echo ucfirst(htmlspecialchars($type['transaction_type'])); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Payment Method</label>
                            <select class="form-control select2" name="payment_method" onchange="this.form.submit()">
                                <option value="">- All Methods -</option>
                                <?php while($method = $payment_methods->fetch_assoc()): ?>
                                    <option value="<?php echo htmlspecialchars($method['payment_method']); ?>" <?php if ($payment_method_filter == $method['payment_method']) { echo "selected"; } ?>>
                                        <?php echo ucfirst(htmlspecialchars($method['payment_method'])); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>
    
    <div class="card-body">
        <?php if (isset($_SESSION['alert_message'])): ?>
            <div class="alert alert-<?php echo $_SESSION['alert_type']; ?> alert-dismissible">
                <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
                <i class="icon fas fa-<?php echo $_SESSION['alert_type'] == 'success' ? 'check' : 'exclamation-triangle'; ?>"></i>
                <?php echo $_SESSION['alert_message']; ?>
            </div>
            <?php 
            unset($_SESSION['alert_type']);
            unset($_SESSION['alert_message']);
            ?>
        <?php endif; ?>

        <!-- Alert Container for AJAX Messages -->
        <div id="ajaxAlertContainer"></div>
        
        <!-- Today's Statistics Row -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card border-left-primary shadow h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Today's Transactions</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $today_stats['total_transactions'] ?? 0; ?></div>
                                <div class="mt-2 mb-0 text-muted text-xs">
                                    <span class="text-success mr-2"><i class="fas fa-arrow-up"></i> KSH <?php echo number_format($today_stats['total_income'] ?? 0, 2); ?></span>
                                    <span class="text-danger"><i class="fas fa-arrow-down"></i> KSH <?php echo number_format($today_stats['total_expenses'] ?? 0, 2); ?></span>
                                </div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-exchange-alt fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-left-success shadow h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Active Registers Today</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $today_stats['active_registers_today'] ?? 0; ?></div>
                                <div class="mt-2 mb-0 text-muted text-xs">
                                    <span>Processing transactions</span>
                                </div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-cash-register fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-left-info shadow h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Total Cash Balance</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800">KSH <?php echo number_format($total_cash_balance, 2); ?></div>
                                <div class="mt-2 mb-0 text-muted text-xs">
                                    <span>Across all registers</span>
                                </div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-piggy-bank fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-left-warning shadow h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Open Registers</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $open_registers; ?></div>
                                <div class="mt-2 mb-0 text-muted text-xs">
                                    <span>Ready for transactions</span>
                                </div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-lock-open fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <?php if ($selected_register): ?>
        <!-- Selected Register Summary Card -->
        <div class="card mb-4">
            <div class="card-header bg-info py-2">
                <h4 class="card-title mb-0 text-white">
                    <i class="fas fa-cash-register mr-2"></i>
                    Register: <?php echo htmlspecialchars($selected_register['register_name']); ?>
                    <span class="badge badge-<?php echo $selected_register['status'] == 'open' ? 'success' : 'warning'; ?> ml-2">
                        <?php echo ucfirst($selected_register['status']); ?>
                    </span>
                </h4>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3 text-center">
                        <div class="display-4 text-success">KSH <?php echo number_format($selected_register['cash_balance'] ?? 0, 2); ?></div>
                        <small class="text-muted">Current Cash Balance</small>
                    </div>
                    <div class="col-md-3">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Opened By:</span>
                            <span class="font-weight-bold"><?php echo htmlspecialchars($selected_register['opened_by_name'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Opened At:</span>
                            <span><?php echo date('M j, Y H:i', strtotime($selected_register['opened_at'])); ?></span>
                        </div>
                        <?php if ($selected_register['status'] == 'closed'): ?>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Closed By:</span>
                            <span class="font-weight-bold"><?php echo htmlspecialchars($selected_register['closed_by_name'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="text-muted">Closed At:</span>
                            <span><?php echo date('M j, Y H:i', strtotime($selected_register['closed_at'])); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-3">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Total Income:</span>
                            <span class="text-success font-weight-bold">KSH <?php echo number_format($selected_register['total_income'] ?? 0, 2); ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Total Expenses:</span>
                            <span class="text-danger font-weight-bold">KSH <?php echo number_format($selected_register['total_expenses'] ?? 0, 2); ?></span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="text-muted">Transaction Count:</span>
                            <span class="font-weight-bold"><?php echo $selected_register['transaction_count'] ?? 0; ?></span>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-center">
                            <?php if ($selected_register['status'] == 'open'): ?>
                                <button class="btn btn-warning btn-block mb-2" data-toggle="modal" data-target="#closeRegisterModal">
                                    <i class="fas fa-lock mr-2"></i>Close Register
                                </button>
                                <button class="btn btn-info btn-block" data-toggle="modal" data-target="#addTransactionModal">
                                    <i class="fas fa-plus-circle mr-2"></i>Add Transaction
                                </button>
                            <?php else: ?>
                                <div class="alert alert-secondary">
                                    <i class="fas fa-lock mr-2"></i>
                                    This register is closed
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Distribution Charts Row -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h5 class="card-title mb-0"><i class="fas fa-chart-pie mr-2"></i>Today's Transaction Types</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($type_distribution)): ?>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Type</th>
                                            <th class="text-right">Count</th>
                                            <th class="text-right">Amount</th>
                                            <th class="text-right">Percentage</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $total_today = $today_stats['total_income'] + $today_stats['total_expenses'];
                                        foreach ($type_distribution as $type): 
                                            $percentage = $total_today > 0 ? ($type['total_amount'] / $total_today) * 100 : 0;
                                            $text_class = in_array($type['transaction_type'], ['payment', 'deposit']) ? 'text-success' : 'text-danger';
                                        ?>
                                            <tr>
                                                <td>
                                                    <span class="badge badge-<?php echo $type['transaction_type'] == 'payment' ? 'success' : ($type['transaction_type'] == 'deposit' ? 'info' : ($type['transaction_type'] == 'refund' ? 'warning' : 'danger')); ?>">
                                                        <?php echo ucfirst($type['transaction_type']); ?>
                                                    </span>
                                                </td>
                                                <td class="text-right"><?php echo $type['count']; ?></td>
                                                <td class="text-right <?php echo $text_class; ?>">KSH <?php echo number_format(abs($type['total_amount']), 2); ?></td>
                                                <td class="text-right"><?php echo number_format($percentage, 1); ?>%</td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-3">
                                <i class="fas fa-chart-pie fa-2x text-muted mb-2"></i>
                                <p class="text-muted mb-0">No transaction data for today</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h5 class="card-title mb-0"><i class="fas fa-credit-card mr-2"></i>Today's Payment Methods</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($payment_distribution)): ?>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Method</th>
                                            <th class="text-right">Count</th>
                                            <th class="text-right">Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($payment_distribution as $method): 
                                            $badge_class = [
                                                'cash' => 'success',
                                                'card' => 'primary',
                                                'insurance' => 'info',
                                                'bank_transfer' => 'warning',
                                                'check' => 'secondary'
                                            ][$method['payment_method']] ?? 'secondary';
                                        ?>
                                            <tr>
                                                <td>
                                                    <span class="badge badge-<?php echo $badge_class; ?>">
                                                        <?php echo ucfirst(str_replace('_', ' ', $method['payment_method'])); ?>
                                                    </span>
                                                </td>
                                                <td class="text-right"><?php echo $method['count']; ?></td>
                                                <td class="text-right text-success">KSH <?php echo number_format($method['total_amount'], 2); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-3">
                                <i class="fas fa-credit-card fa-2x text-muted mb-2"></i>
                                <p class="text-muted mb-0">No payment data for today</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Main Content Tabs -->
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <ul class="nav nav-tabs card-header-tabs">
                            <li class="nav-item">
                                <a class="nav-link <?php echo !$selected_register_id ? 'active' : ''; ?>" href="#registers-tab" data-toggle="tab">
                                    <i class="fas fa-list mr-1"></i>All Registers
                                </a>
                            </li>
                            <?php if ($selected_register_id): ?>
                            <li class="nav-item">
                                <a class="nav-link <?php echo $selected_register_id ? 'active' : ''; ?>" href="#transactions-tab" data-toggle="tab">
                                    <i class="fas fa-exchange-alt mr-1"></i>Recent Transactions
                                </a>
                            </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                    <div class="card-body">
                        <div class="tab-content">
                            <!-- Registers Tab -->
                            <div class="tab-pane <?php echo !$selected_register_id ? 'active' : ''; ?>" id="registers-tab">
                                <div class="table-responsive-sm">
                                    <table class="table table-hover mb-0">
                                        <thead class="<?php if ($num_rows[0] == 0) { echo "d-none"; } ?> bg-light">
                                        <tr>
                                            <th>Register Name</th>
                                            <th>Status</th>
                                            <th>Cash Balance</th>
                                            <th>Transactions</th>
                                            <th>Opened By</th>
                                            <th>Last Activity</th>
                                            <th class="text-center">Actions</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php if ($cash_registers && $cash_registers->num_rows > 0): ?>
                                            <?php while($register = $cash_registers->fetch_assoc()): 
                                                $register_id = intval($register['register_id']);
                                                $register_name = nullable_htmlentities($register['register_name']);
                                                $status = nullable_htmlentities($register['status']);
                                                $cash_balance = floatval($register['cash_balance']);
                                                $transaction_count = intval($register['transaction_count']);
                                                $opened_by_name = nullable_htmlentities($register['opened_by_name'] ?? 'N/A');
                                                $last_transaction = $register['last_transaction_time'] ? date('M j, H:i', strtotime($register['last_transaction_time'])) : 'No activity';
                                                $total_income = floatval($register['total_income'] ?? 0);
                                                $total_expenses = floatval($register['total_expenses'] ?? 0);
                                            ?>
                                                <tr>
                                                    <td>
                                                        <div class="font-weight-bold"><?php echo $register_name; ?></div>
                                                        <?php if ($register['is_active'] == 0): ?>
                                                            <small class="text-danger"><i class="fas fa-ban"></i> Inactive</small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span class="badge badge-<?php echo $status == 'open' ? 'success' : 'warning'; ?>">
                                                            <?php echo ucfirst($status); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <div class="font-weight-bold text-success">KSH <?php echo number_format($cash_balance, 2); ?></div>
                                                        <small class="text-muted">
                                                            <span class="text-success">+<?php echo number_format($total_income, 2); ?></span> | 
                                                            <span class="text-danger">-<?php echo number_format($total_expenses, 2); ?></span>
                                                        </small>
                                                    </td>
                                                    <td>
                                                        <span class="badge badge-info"><?php echo $transaction_count; ?></span>
                                                    </td>
                                                    <td>
                                                        <small><?php echo $opened_by_name; ?></small>
                                                        <br><small class="text-muted"><?php echo date('M j, Y', strtotime($register['opened_at'])); ?></small>
                                                    </td>
                                                    <td>
                                                        <small><?php echo $last_transaction; ?></small>
                                                    </td>
                                                    <td>
                                                        <div class="dropdown dropleft text-center">
                                                            <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                                                <i class="fas fa-ellipsis-h"></i>
                                                            </button>
                                                            <div class="dropdown-menu">
                                                                <a class="dropdown-item" href="cash_register_dashboard.php?register_id=<?php echo $register_id; ?>">
                                                                    <i class="fas fa-fw fa-eye mr-2"></i>View Details
                                                                </a>
                                                                <?php if ($status == 'open'): ?>
                                                                    <a class="dropdown-item" href="#" onclick="closeRegister(<?php echo $register_id; ?>)">
                                                                        <i class="fas fa-fw fa-lock mr-2"></i>Close Register
                                                                    </a>
                                                                    <a class="dropdown-item" href="#" data-toggle="modal" data-target="#addTransactionModal" onclick="setSelectedRegister(<?php echo $register_id; ?>)">
                                                                        <i class="fas fa-fw fa-plus-circle mr-2"></i>Add Transaction
                                                                    </a>
                                                                <?php endif; ?>
                                                                <?php if ($status == 'closed' && $register['is_active'] == 1): ?>
                                                                    <div class="dropdown-divider"></div>
                                                                    <a class="dropdown-item text-danger" href="#" onclick="deleteRegister(<?php echo $register_id; ?>)">
                                                                        <i class="fas fa-fw fa-trash mr-2"></i>Delete Register
                                                                    </a>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="7" class="text-center py-4">
                                                    <i class="fas fa-cash-register fa-3x text-muted mb-3"></i>
                                                    <h5 class="text-muted">No Cash Registers Found</h5>
                                                    <p class="text-muted">
                                                        <?php echo ($status_filter || $search_query) ? 
                                                            'Try adjusting your filters or search criteria.' : 
                                                            'Get started by opening your first cash register.'; 
                                                        ?>
                                                    </p>
                                                    <button class="btn btn-success mt-2" data-toggle="modal" data-target="#openRegisterModal">
                                                        <i class="fas fa-plus mr-2"></i>Open First Register
                                                    </button>
                                                    <?php if ($status_filter || $search_query): ?>
                                                        <a href="cash_register_dashboard.php" class="btn btn-outline-secondary mt-2 ml-2">
                                                            <i class="fas fa-times mr-2"></i>Clear Filters
                                                        </a>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            
                            <!-- Transactions Tab -->
                            <?php if ($selected_register_id): ?>
                            <div class="tab-pane <?php echo $selected_register_id ? 'active' : ''; ?>" id="transactions-tab">
                                <?php if ($recent_transactions && $recent_transactions->num_rows > 0): ?>
                                    <div class="table-responsive-sm">
                                        <table class="table table-hover mb-0">
                                            <thead class="bg-light">
                                            <tr>
                                                <th>Date & Time</th>
                                                <th>Type</th>
                                                <th>Payment Method</th>
                                                <th>Description</th>
                                                <th>Reference</th>
                                                <th>Patient/Invoice</th>
                                                <th class="text-right">Amount</th>
                                                <th class="text-center">Actions</th>
                                            </tr>
                                            </thead>
                                            <tbody>
                                            <?php while($transaction = $recent_transactions->fetch_assoc()): 
                                                $transaction_id = intval($transaction['transaction_id']);
                                                $transaction_type = nullable_htmlentities($transaction['transaction_type']);
                                                $payment_method = nullable_htmlentities($transaction['payment_method']);
                                                $description = nullable_htmlentities($transaction['description']);
                                                $reference_number = nullable_htmlentities($transaction['reference_number']);
                                                $amount = floatval($transaction['amount']);
                                                $created_at = date('M j, H:i', strtotime($transaction['created_at']));
                                                $created_by = nullable_htmlentities($transaction['created_by_name'] ?? 'N/A');
                                                $patient_name = $transaction['patient_first_name'] ? $transaction['patient_first_name'] . ' ' . $transaction['patient_last_name'] : '';
                                                $invoice_number = nullable_htmlentities($transaction['invoice_number'] ?? '');
                                                
                                                $type_badge = [
                                                    'payment' => 'success',
                                                    'deposit' => 'info',
                                                    'refund' => 'warning',
                                                    'expense' => 'danger',
                                                    'withdrawal' => 'secondary'
                                                ][$transaction_type] ?? 'secondary';
                                                
                                                $method_badge = [
                                                    'cash' => 'success',
                                                    'card' => 'primary',
                                                    'insurance' => 'info',
                                                    'bank_transfer' => 'warning',
                                                    'check' => 'secondary'
                                                ][$payment_method] ?? 'secondary';
                                            ?>
                                                <tr>
                                                    <td>
                                                        <small><?php echo $created_at; ?></small>
                                                        <br><small class="text-muted">by <?php echo $created_by; ?></small>
                                                    </td>
                                                    <td>
                                                        <span class="badge badge-<?php echo $type_badge; ?>">
                                                            <?php echo ucfirst($transaction_type); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php if ($payment_method): ?>
                                                            <span class="badge badge-<?php echo $method_badge; ?>">
                                                                <?php echo ucfirst(str_replace('_', ' ', $payment_method)); ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <div class="font-weight-bold"><?php echo $description; ?></div>
                                                    </td>
                                                    <td>
                                                        <?php if ($reference_number): ?>
                                                            <code><?php echo $reference_number; ?></code>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($patient_name): ?>
                                                            <small><?php echo $patient_name; ?></small>
                                                            <br><small class="text-info"><?php echo $invoice_number; ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-right">
                                                        <div class="font-weight-bold <?php echo in_array($transaction_type, ['payment', 'deposit']) ? 'text-success' : 'text-danger'; ?>">
                                                            <?php echo in_array($transaction_type, ['payment', 'deposit']) ? '+' : '-'; ?>
                                                            KSH <?php echo number_format($amount, 2); ?>
                                                        </div>
                                                    </td>
                                                    <td class="text-center">
                                                        <button class="btn btn-danger btn-sm" onclick="deleteTransaction(<?php echo $transaction_id; ?>)">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-4">
                                        <i class="fas fa-exchange-alt fa-3x text-muted mb-3"></i>
                                        <h5 class="text-muted">No Transactions Found</h5>
                                        <p class="text-muted">
                                            <?php echo ($transaction_type_filter || $payment_method_filter) ? 
                                                'Try adjusting your filters.' : 
                                                'No transactions recorded for this register.'; 
                                            ?>
                                        </p>
                                        <?php if ($selected_register['status'] == 'open'): ?>
                                            <button class="btn btn-info mt-2" data-toggle="modal" data-target="#addTransactionModal">
                                                <i class="fas fa-plus-circle mr-2"></i>Add First Transaction
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Open Register Modal -->
<div class="modal fade" id="openRegisterModal" tabindex="-1" role="dialog" aria-labelledby="openRegisterModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-success">
                <h5 class="modal-title text-white" id="openRegisterModalLabel">
                    <i class="fas fa-plus mr-2"></i>Open New Cash Register
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true" class="text-white">&times;</span>
                </button>
            </div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="open_register">
                
                <div class="modal-body">
                    <div class="form-group">
                        <label class="required">Register Name</label>
                        <input type="text" class="form-control" name="register_name" placeholder="e.g., Main Cash Register, Pharmacy Register" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Opening Balance (KSH)</label>
                        <input type="number" class="form-control" name="opening_balance" value="0.00" step="0.01" min="0">
                        <small class="form-text text-muted">Enter the starting cash amount in the register</small>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle mr-2"></i>
                        <strong>Note:</strong> The register will be opened with the status "open" and you can start adding transactions immediately.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">
                        <i class="fas fa-times mr-2"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-plus mr-2"></i>Open Register
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Close Register Modal -->
<?php if ($selected_register && $selected_register['status'] == 'open'): ?>
<div class="modal fade" id="closeRegisterModal" tabindex="-1" role="dialog" aria-labelledby="closeRegisterModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title text-white" id="closeRegisterModalLabel">
                    <i class="fas fa-lock mr-2"></i>Close Cash Register
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true" class="text-white">&times;</span>
                </button>
            </div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="close_register">
                <input type="hidden" name="register_id" value="<?php echo $selected_register_id; ?>">
                
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        <strong>Warning:</strong> Once closed, you cannot add transactions to this register unless you reopen it.
                    </div>
                    
                    <div class="form-group">
                        <label class="required">Current Cash Balance</label>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text">KSH</span>
                            </div>
                            <input type="number" class="form-control" name="closing_balance" value="<?php echo number_format($selected_register['cash_balance'] ?? 0, 2); ?>" step="0.01" min="0" required>
                        </div>
                        <small class="form-text text-muted">Enter the actual cash amount in the register for closing</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Closing Notes</label>
                        <textarea class="form-control" name="notes" rows="2" placeholder="Optional notes about the closing"></textarea>
                    </div>
                    
                    <div class="alert alert-light">
                        <p class="mb-1"><strong>Register Summary:</strong></p>
                        <div class="d-flex justify-content-between">
                            <span>Register:</span>
                            <span class="font-weight-bold"><?php echo htmlspecialchars($selected_register['register_name']); ?></span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span>Opened By:</span>
                            <span><?php echo htmlspecialchars($selected_register['opened_by_name'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span>Opened At:</span>
                            <span><?php echo date('M j, Y H:i', strtotime($selected_register['opened_at'])); ?></span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span>Total Transactions:</span>
                            <span><?php echo $selected_register['transaction_count'] ?? 0; ?></span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">
                        <i class="fas fa-times mr-2"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-lock mr-2"></i>Close Register
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Add Transaction Modal -->
<div class="modal fade" id="addTransactionModal" tabindex="-1" role="dialog" aria-labelledby="addTransactionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-info">
                <h5 class="modal-title text-white" id="addTransactionModalLabel">
                    <i class="fas fa-plus-circle mr-2"></i>Add Transaction
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true" class="text-white">&times;</span>
                </button>
            </div>
            <form method="post" id="addTransactionForm">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="add_transaction">
                <input type="hidden" name="register_id" id="transaction_register_id" value="<?php echo $selected_register_id; ?>">
                
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="required">Transaction Type</label>
                                <select class="form-control" name="transaction_type" id="transaction_type" required>
                                    <option value="">- Select Type -</option>
                                    <option value="payment">Payment (Income)</option>
                                    <option value="deposit">Deposit (Add Cash)</option>
                                    <option value="refund">Refund (Expense)</option>
                                    <option value="expense">Expense (Cash Out)</option>
                                    <option value="withdrawal">Withdrawal (Remove Cash)</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="required">Payment Method</label>
                                <select class="form-control" name="payment_method" id="payment_method" required>
                                    <option value="">- Select Method -</option>
                                    <option value="cash">Cash</option>
                                    <option value="card">Card</option>
                                    <option value="insurance">Insurance</option>
                                    <option value="bank_transfer">Bank Transfer</option>
                                    <option value="check">Check</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="required">Amount (KSH)</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text">KSH</span>
                                    </div>
                                    <input type="number" class="form-control" name="amount" id="transaction_amount" value="0.00" step="0.01" min="0.01" required>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Reference Number</label>
                                <input type="text" class="form-control" name="reference_number" placeholder="e.g., Receipt #, Check #">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="required">Description</label>
                        <input type="text" class="form-control" name="description" placeholder="e.g., Invoice payment, Petty cash expense" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Link to Invoice (Optional)</label>
                        <select class="form-control select2" name="invoice_id" id="invoice_id">
                            <option value="">- Select Invoice -</option>
                            <?php foreach ($open_invoices as $invoice): ?>
                                <option value="<?php echo $invoice['invoice_id']; ?>">
                                    <?php echo htmlspecialchars($invoice['invoice_number']); ?> - 
                                    <?php echo htmlspecialchars($invoice['patient_name']); ?> - 
                                    KSH <?php echo number_format($invoice['amount_due'], 2); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="alert alert-info" id="transactionPreview" style="display: none;">
                        <i class="fas fa-info-circle mr-2"></i>
                        <strong>Preview:</strong> This will be recorded as a 
                        <span id="previewType" class="font-weight-bold"></span> transaction of 
                        <span id="previewAmount" class="font-weight-bold"></span> via 
                        <span id="previewMethod" class="font-weight-bold"></span>.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">
                        <i class="fas fa-times mr-2"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-info">
                        <i class="fas fa-plus-circle mr-2"></i>Add Transaction
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.select2').select2();

    // Auto-close alerts after 5 seconds
    setTimeout(() => {
        $('.alert').alert('close');
    }, 5000);

    // Transaction preview
    $('#transaction_type, #payment_method, #transaction_amount').on('change keyup', function() {
        const type = $('#transaction_type').val();
        const method = $('#payment_method').val();
        const amount = $('#transaction_amount').val();
        
        if (type && method && amount > 0) {
            $('#previewType').text(type);
            $('#previewAmount').text('KSH ' + parseFloat(amount).toFixed(2));
            $('#previewMethod').text(method);
            $('#transactionPreview').show();
        } else {
            $('#transactionPreview').hide();
        }
    });

    // Auto-submit when date range changes
    $('input[type="date"]').change(function() {
        if ($(this).val()) {
            $(this).closest('form').submit();
        }
    });

    // Auto-submit date range when canned date is selected
    $('select[name="canned_date"]').change(function() {
        if ($(this).val() !== 'custom') {
            $(this).closest('form').submit();
        }
    });

    // Set selected register for transaction modal
    window.setSelectedRegister = function(registerId) {
        $('#transaction_register_id').val(registerId);
    };
});

function closeRegister(registerId) {
    if (confirm('Are you sure you want to close this register? You cannot add transactions after closing.')) {
        window.location.href = 'cash_register_dashboard.php?register_id=' + registerId;
        setTimeout(() => {
            $('#closeRegisterModal').modal('show');
        }, 500);
    }
}

function deleteTransaction(transactionId) {
    if (confirm('Are you sure you want to delete this transaction? This will reverse the cash balance effect.')) {
        const form = document.createElement('form');
        form.method = 'post';
        form.action = '';
        
        const csrfToken = document.createElement('input');
        csrfToken.type = 'hidden';
        csrfToken.name = 'csrf_token';
        csrfToken.value = '<?php echo $_SESSION['csrf_token']; ?>';
        form.appendChild(csrfToken);
        
        const action = document.createElement('input');
        action.type = 'hidden';
        action.name = 'action';
        action.value = 'delete_transaction';
        form.appendChild(action);
        
        const transactionIdInput = document.createElement('input');
        transactionIdInput.type = 'hidden';
        transactionIdInput.name = 'transaction_id';
        transactionIdInput.value = transactionId;
        form.appendChild(transactionIdInput);
        
        document.body.appendChild(form);
        form.submit();
    }
}

function deleteRegister(registerId) {
    if (confirm('Are you sure you want to delete this register? This action cannot be undone.')) {
        // In a real implementation, you would need a delete_register action
        // For now, we'll just redirect to deactivate
        window.location.href = 'cash_register_dashboard.php?action=delete&register_id=' + registerId;
    }
}

// Form validation
$('#addTransactionForm').on('submit', function(e) {
    const amount = parseFloat($('#transaction_amount').val());
    
    if (amount <= 0) {
        alert('Amount must be greater than 0');
        e.preventDefault();
        return false;
    }
    
    if (!$('#transaction_type').val() || !$('#payment_method').val()) {
        alert('Please select transaction type and payment method');
        e.preventDefault();
        return false;
    }
});

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + O for open register
    if (e.ctrlKey && e.keyCode === 79) {
        e.preventDefault();
        $('#openRegisterModal').modal('show');
    }
    // Ctrl + T for add transaction (if register selected)
    if (e.ctrlKey && e.keyCode === 84 && <?php echo $selected_register && $selected_register['status'] == 'open' ? 'true' : 'false'; ?>) {
        e.preventDefault();
        $('#addTransactionModal').modal('show');
    }
    // Ctrl + F for focus search
    if (e.ctrlKey && e.keyCode === 70) {
        e.preventDefault();
        $('input[name="q"]').focus();
    }
});
</script>

<style>
.required:after {
    content: " *";
    color: #dc3545;
}
.card {
    border: none;
    box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
}
.card-header {
    border-bottom: 1px solid #e3e6f0;
}
.border-left-primary {
    border-left: 0.25rem solid #4e73df !important;
}
.border-left-success {
    border-left: 0.25rem solid #1cc88a !important;
}
.border-left-info {
    border-left: 0.25rem solid #36b9cc !important;
}
.border-left-warning {
    border-left: 0.25rem solid #f6c23e !important;
}
.table th {
    border-top: none;
    font-weight: 600;
    color: #6e707e;
    font-size: 0.85rem;
    text-transform: uppercase;
}
.badge-pill {
    padding: 0.5em 0.8em;
}
.tab-content {
    border: 1px solid #dee2e6;
    border-top: none;
    padding: 1rem;
    border-radius: 0 0 0.25rem 0.25rem;
}
.nav-tabs .nav-link.active {
    background-color: #fff;
    border-bottom-color: #fff;
}
.display-4 {
    font-size: 2.5rem;
    font-weight: 300;
    line-height: 1.2;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
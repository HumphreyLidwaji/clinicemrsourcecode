<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Check if period ID is provided
if (!isset($_GET['period_id']) || empty($_GET['period_id'])) {
    $_SESSION['alert_type'] = 'error';
    $_SESSION['alert_message'] = 'No payroll period ID provided.';
    header("Location: payroll_management.php");
    exit;
}

$period_id = intval($_GET['period_id']);

// Get period details
$period_sql = "SELECT * FROM payroll_periods WHERE period_id = ?";
$period_stmt = $mysqli->prepare($period_sql);
$period_stmt->bind_param("i", $period_id);
$period_stmt->execute();
$period_result = $period_stmt->get_result();
$period = $period_result->fetch_assoc();

// Get common accounting accounts (using the same approach as pharmacy)
$accounts = [
    'payroll_expense' => getAccountIdByNumber($mysqli, '5070'), // Payroll Expense
    'cash' => getAccountIdByNumber($mysqli, '1010'),            // Cash Account
    'bank' => getAccountIdByNumber($mysqli, '1010'),            // Using Cash for now, adjust if you have separate bank account
];

// Helper function to get account ID (same as pharmacy)
function getAccountIdByNumber($mysqli, $account_number) {
    $result = mysqli_query($mysqli, "
        SELECT account_id FROM accounts 
        WHERE account_number = '$account_number' 
        AND account_status = 'active'
        AND account_archived_at IS NULL
    ");
    $row = mysqli_fetch_assoc($result);
    return $row ? $row['account_id'] : null;
}

// Function to record payroll payment journal entry (similar to pharmacy payment)
function recordPayrollJournalEntry($mysqli, $period_id, $payment_amount, $payment_method, $payment_date, $payment_reference, $user_id, $accounts) {
    $period = mysqli_fetch_assoc(mysqli_query($mysqli, "
        SELECT * FROM payroll_periods WHERE period_id = $period_id
    "));
    
    if (!$period) {
        throw new Exception("Payroll period not found");
    }
    
    $reference = "PAYROLL-" . $period_id . "-" . date('YmdHis');
    $description = "Payroll payment for " . $period['period_name'] . " - " . $payment_method;
    if ($payment_reference) {
        $description .= " - Ref: " . $payment_reference;
    }
    
    // Determine which account to credit based on payment method
    $credit_account_id = $accounts['cash']; // Default to cash
    switch($payment_method) {
        case 'bank':
        case 'cash':
        case 'cheque':
            $credit_account_id = $accounts['cash']; // Using cash account for all methods for now
            break;
        // Add more cases if you have separate bank accounts
    }
    
    // Create journal header
    mysqli_query($mysqli, "
        INSERT INTO journal_headers SET
        header_name = 'Payroll Payment - {$period['period_name']}',
        reference_number = '$reference',
        entry_date = '$payment_date',
        description = '$description',
        status = 'posted',
        module = 'payroll',
        created_by = $user_id,
        created_at = NOW()
    ");
    $journal_header_id = mysqli_insert_id($mysqli);
    
    if (!$journal_header_id) {
        throw new Exception("Failed to create journal header: " . mysqli_error($mysqli));
    }
    
    // Create main journal entry
    mysqli_query($mysqli, "
        INSERT INTO journal_entries SET
        journal_header_id = $journal_header_id,
        entry_number = '$reference',
        entry_date = '$payment_date',
        entry_description = '$description',
        reference_number = '$reference',
        entry_type = 'payment',
        source_document = 'payroll',
        created_by = $user_id,
        entry_created_at = NOW(),
        account_id = {$accounts['payroll_expense']},
        amount = $payment_amount,
        description = 'Payroll expense for {$period['period_name']}'
    ");
    $entry_id = mysqli_insert_id($mysqli);
    
    if (!$entry_id) {
        throw new Exception("Failed to create journal entry: " . mysqli_error($mysqli));
    }
    
    // Create journal entry lines (double-entry)
    
    // Line 1: Debit Payroll Expense
    mysqli_query($mysqli, "
        INSERT INTO journal_entry_lines SET
        entry_id = $entry_id,
        account_id = {$accounts['payroll_expense']},
        entry_type = 'debit',
        amount = $payment_amount,
        description = 'Payroll expense - {$period['period_name']}',
        reference = '$reference',
        line_created_at = NOW()
    ");
    
    // Line 2: Credit Cash/Bank
    $credit_description = "Payment made via " . $payment_method . " - Payroll {$period['period_name']}";
    if ($payment_reference) {
        $credit_description .= " - Ref: $payment_reference";
    }
    
    mysqli_query($mysqli, "
        INSERT INTO journal_entry_lines SET
        entry_id = $entry_id,
        account_id = $credit_account_id,
        entry_type = 'credit',
        amount = $payment_amount,
        description = '$credit_description',
        reference = '$reference',
        line_created_at = NOW()
    ");
    
    // Update payroll period with journal reference
    mysqli_query($mysqli, "
        UPDATE payroll_periods SET 
        accounting_journal_id = $journal_header_id
        WHERE period_id = $period_id
    ");
    
    return $journal_header_id;
}

// Handle payment processing
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if ($_POST['action'] == 'mark_paid') {
        $payment_method = $_POST['payment_method'];
        $payment_date = $_POST['payment_date'];
        $payment_reference = $_POST['payment_reference'] ?? '';
        
        mysqli_begin_transaction($mysqli);
        
        try {
            // Update all transactions for this period
            $sql = "UPDATE payroll_transactions 
                    SET status = 'paid', payment_method = ?, payment_date = ?, payment_reference = ?
                    WHERE period_id = ? AND status = 'approved'";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param("sssi", $payment_method, $payment_date, $payment_reference, $period_id);
            
            if (!$stmt->execute()) {
                throw new Exception('Error marking payments as paid: ' . $stmt->error);
            }
            
            $updated_rows = $stmt->affected_rows;
            
            // Update period status
            $update_sql = "UPDATE payroll_periods SET status = 'paid' WHERE period_id = ?";
            $update_stmt = $mysqli->prepare($update_sql);
            $update_stmt->bind_param("i", $period_id);
            
            if (!$update_stmt->execute()) {
                throw new Exception('Error updating period status: ' . $update_stmt->error);
            }
            
            // Get total net pay for accounting entry
            $totals_sql = "SELECT SUM(net_pay) as total_net_pay 
                           FROM payroll_transactions 
                           WHERE period_id = ? AND status = 'paid'";
            $totals_stmt = $mysqli->prepare($totals_sql);
            $totals_stmt->bind_param("i", $period_id);
            $totals_stmt->execute();
            $totals_result = $totals_stmt->get_result();
            $totals = $totals_result->fetch_assoc();
            $total_net_pay = $totals['total_net_pay'] ?? 0;
            
            // Verify accounts exist before proceeding
            if (!$accounts['payroll_expense']) {
                throw new Exception("Payroll expense account (5070) not found or inactive");
            }
            if (!$accounts['cash']) {
                throw new Exception("Cash account (1010) not found or inactive");
            }
            
            // Post to accounting system using the direct approach (like pharmacy)
            if ($total_net_pay > 0) {
                $journal_header_id = recordPayrollJournalEntry(
                    $mysqli, 
                    $period_id, 
                    $total_net_pay, 
                    $payment_method, 
                    $payment_date, 
                    $payment_reference, 
                    $session_user_id, 
                    $accounts
                );
                
                error_log("Payroll Payment: Successfully created journal header ID: $journal_header_id");
                
            } else {
                error_log("Payroll Payment: Total net pay is 0 or negative, skipping accounting entry");
            }
            
            mysqli_commit($mysqli);
            
            $_SESSION['alert_type'] = 'success';
            $_SESSION['alert_message'] = 'Payments marked as paid and accounting entries recorded successfully!';
            
        } catch (Exception $e) {
            mysqli_rollback($mysqli);
            $_SESSION['alert_type'] = 'error';
            $_SESSION['alert_message'] = 'Payment processing failed: ' . $e->getMessage();
            
            // Log the detailed error
            error_log("Payroll Payment Error: " . $e->getMessage());
        }
        
        header("Location: payroll_payment.php?period_id=$period_id");
        exit;
    }
}

// Get approved transactions for payment
$transactions_sql = "SELECT 
                        pt.*,
                        e.first_name, e.last_name, e.employee_number, e.bank_name, e.bank_account,
                        d.department_name
                     FROM payroll_transactions pt
                     JOIN employees e ON pt.employee_id = e.employee_id
                     LEFT JOIN departments d ON e.department_id = d.department_id
                     WHERE pt.period_id = ? AND pt.status = 'approved'
                     ORDER BY e.first_name, e.last_name";
$transactions_stmt = $mysqli->prepare($transactions_sql);
$transactions_stmt->bind_param("i", $period_id);
$transactions_stmt->execute();
$transactions_result = $transactions_stmt->get_result();

// Calculate payment totals
$totals_sql = "SELECT 
                 COUNT(*) as employee_count,
                 SUM(net_pay) as total_net_pay
               FROM payroll_transactions 
               WHERE period_id = ? AND status = 'approved'";
$totals_stmt = $mysqli->prepare($totals_sql);
$totals_stmt->bind_param("i", $period_id);
$totals_stmt->execute();
$totals = $totals_stmt->get_result()->fetch_assoc();
?>

<div class="card">
    <div class="card-header bg-success py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-money-bill-wave mr-2"></i>
                Process Payments - <?php echo htmlspecialchars($period['period_name']); ?>
            </h3>
            <a href="payroll_process.php?period_id=<?php echo $period_id; ?>" class="btn btn-light">
                <i class="fas fa-arrow-left mr-2"></i>Back to Payroll
            </a>
        </div>
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

        <!-- Payment Summary -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white py-2">
                <h5 class="card-title mb-0"><i class="fas fa-chart-bar mr-2"></i>Payment Summary</h5>
            </div>
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-md-3 mb-3">
                        <div class="border rounded p-3 bg-light">
                            <h3 class="text-primary mb-0"><?php echo $totals['employee_count'] ?? 0; ?></h3>
                            <small class="text-muted">Employees to Pay</small>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="border rounded p-3 bg-light">
                            <h3 class="text-success mb-0">KES <?php echo number_format($totals['total_net_pay'] ?? 0, 2); ?></h3>
                            <small class="text-muted">Total to Pay</small>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="border rounded p-3 bg-light">
                            <h3 class="text-info mb-0"><?php echo date('M j, Y', strtotime($period['pay_date'])); ?></h3>
                            <small class="text-muted">Pay Date</small>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="border rounded p-3 bg-light">
                            <h3 class="text-warning mb-0"><?php echo $transactions_result->num_rows; ?></h3>
                            <small class="text-muted">Ready for Payment</small>
                        </div>
                    </div>
                </div>
                
                <?php if ($transactions_result->num_rows > 0): ?>
                <div class="row mt-4">
                    <div class="col-md-12">
                        <form method="POST">
                            <input type="hidden" name="action" value="mark_paid">
                            <div class="form-row align-items-end">
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label>Payment Method</label>
                                        <select class="form-control" name="payment_method" required>
                                            <option value="bank">Bank Transfer</option>
                                            <option value="cash">Cash</option>
                                            <option value="cheque">Cheque</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label>Payment Date</label>
                                        <input type="date" class="form-control" name="payment_date" 
                                               value="<?php echo date('Y-m-d'); ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Payment Reference</label>
                                        <input type="text" class="form-control" name="payment_reference" 
                                               placeholder="e.g., Bank transfer reference, cheque number...">
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="form-group">
                                        <button type="submit" class="btn btn-success btn-block" onclick="return confirm('Are you sure you want to mark these payments as paid? This will record accounting entries and cannot be undone.')">
                                            <i class="fas fa-check-circle mr-2"></i>Mark as Paid
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="col-md-12">
                                    <div class="alert alert-info">
                                        <small>
                                            <i class="fas fa-info-circle mr-2"></i>
                                            <strong>Accounting Impact:</strong> This will record a debit to Payroll Expense (5070) and credit to Cash (1010) for the total amount of KES <?php echo number_format($totals['total_net_pay'] ?? 0, 2); ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Payment List -->
        <div class="card">
            <div class="card-header bg-warning text-white py-2">
                <h5 class="card-title mb-0">
                    <i class="fas fa-list mr-2"></i>
                    Payment List
                    <span class="badge badge-light ml-2"><?php echo $transactions_result->num_rows; ?> employees</span>
                </h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th>Employee</th>
                                <th>Bank Details</th>
                                <th class="text-right">Net Pay</th>
                                <th>Status</th>
                                <th class="text-center">Payslip</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($transactions_result->num_rows > 0): ?>
                                <?php while ($transaction = $transactions_result->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <div class="font-weight-bold"><?php echo htmlspecialchars($transaction['first_name'] . ' ' . $transaction['last_name']); ?></div>
                                            <small class="text-muted">
                                                <?php echo htmlspecialchars($transaction['employee_number']); ?> | 
                                                <?php echo htmlspecialchars($transaction['department_name'] ?? 'No Department'); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <?php if ($transaction['bank_name'] && $transaction['bank_account']): ?>
                                                <div class="font-weight-bold"><?php echo htmlspecialchars($transaction['bank_name']); ?></div>
                                                <small class="text-muted"><?php echo htmlspecialchars($transaction['bank_account']); ?></small>
                                            <?php else: ?>
                                                <span class="text-muted">No bank details</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-right font-weight-bold text-primary">
                                            KES <?php echo number_format($transaction['net_pay'], 2); ?>
                                        </td>
                                        <td>
                                            <span class="badge badge-success">Approved</span>
                                        </td>
                                        <td class="text-center">
                                            <a href="payslip.php?transaction_id=<?php echo $transaction['transaction_id']; ?>" 
                                               class="btn btn-info btn-sm" target="_blank">
                                                <i class="fas fa-file-invoice"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                                
                                <!-- Total Row -->
                                <tr class="bg-light font-weight-bold">
                                    <td colspan="2">TOTAL PAYMENT</td>
                                    <td class="text-right text-primary">KES <?php echo number_format($totals['total_net_pay'], 2); ?></td>
                                    <td></td>
                                    <td></td>
                                </tr>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center py-4">
                                        <i class="fas fa-money-bill-wave fa-3x text-muted mb-3"></i>
                                        <h5 class="text-muted">No Payments Ready</h5>
                                        <p class="text-muted">No approved payroll transactions found for this period.</p>
                                        <a href="payroll_process.php?period_id=<?php echo $period_id; ?>" class="btn btn-primary">
                                            <i class="fas fa-arrow-left mr-2"></i>Back to Payroll Processing
                                        </a>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Check permissions
if (!isset($_SESSION['user_id'])) {
    header("Location: /login.php");
    exit;
}

// Get account ID
$account_id = intval($_GET['id'] ?? 0);
if ($account_id == 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Account ID is required.";
    header("Location: accounts.php");
    exit;
}

// Get account details
$account_sql = "SELECT a.*, parent.account_number as parent_account_number, 
                       parent.account_name as parent_account_name
                FROM accounts a
                LEFT JOIN accounts parent ON a.parent_account_id = parent.account_id
                WHERE a.account_id = ?";
$account_stmt = $mysqli->prepare($account_sql);
$account_stmt->bind_param("i", $account_id);
$account_stmt->execute();
$account = $account_stmt->get_result()->fetch_assoc();

if (!$account) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Account not found.";
    header("Location: accounts.php");
    exit;
}

// Get date parameters
$dtf = sanitizeInput($_GET['dtf'] ?? '');
$dtt = sanitizeInput($_GET['dtt'] ?? '');
$show_all = isset($_GET['show_all']) ? true : false;

// Set default date range if not provided
if (empty($dtf)) {
    $dtf = date('Y-m-01'); // First day of current month
}
if (empty($dtt)) {
    $dtt = date('Y-m-t'); // Last day of current month
}

// Check if journal system exists
$table_check = $mysqli->query("SHOW TABLES LIKE 'journal_entries'")->num_rows;
if ($table_check == 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Journal entry system is not set up. Please run accounting setup first.";
    header("Location: accounts.php");
    exit;
}

// Get account ledger data
function getAccountLedgerData($mysqli, $account_id, $start_date = '', $end_date = '', $show_all = false) {
    $data = [
        'transactions' => [],
        'opening_balance' => 0,
        'total_debits' => 0,
        'total_credits' => 0,
        'closing_balance' => 0,
        'running_balance' => 0
    ];
    
    // Get account opening balance (balance before start date)
    $opening_sql = "SELECT 
                        COALESCE(a.balance, 0) as opening_balance,
                        COALESCE((
                            SELECT SUM(jel.debit_amount) - SUM(jel.credit_amount) 
                            FROM journal_entry_lines jel
                            JOIN journal_entries je ON jel.journal_entry_id = je.journal_entry_id
                            WHERE jel.account_id = a.account_id 
                            AND je.status = 'posted'
                            AND je.transaction_date < ?
                        ), 0) as prior_activity
                    FROM accounts a
                    WHERE a.account_id = ?";
    
    $opening_stmt = $mysqli->prepare($opening_sql);
    $opening_stmt->bind_param("si", $start_date, $account_id);
    $opening_stmt->execute();
    $opening_result = $opening_stmt->get_result()->fetch_assoc();
    
    $data['opening_balance'] = $opening_result['opening_balance'] + $opening_result['prior_activity'];
    $data['running_balance'] = $data['opening_balance'];
    
    // Build WHERE clause
    $where_clause = "je.status = 'posted' AND jel.account_id = ?";
    if (!empty($start_date) && !empty($end_date) && !$show_all) {
        $where_clause .= " AND je.transaction_date BETWEEN ? AND ?";
    }
    
    // Get transactions
    $sql = "SELECT 
                je.journal_entry_id,
                je.journal_entry_number,
                je.transaction_date,
                je.transaction_type,
                je.description as entry_description,
                jel.description as line_description,
                jel.debit_amount,
                jel.credit_amount,
                jel.reference_type,
                jel.reference_id,
                u.user_name as created_by
            FROM journal_entries je
            JOIN journal_entry_lines jel ON je.journal_entry_id = jel.journal_entry_id
            LEFT JOIN users u ON je.created_by = u.user_id
            WHERE $where_clause
            ORDER BY je.transaction_date, je.journal_entry_id";
    
    $stmt = $mysqli->prepare($sql);
    
    if (!empty($start_date) && !empty($end_date) && !$show_all) {
        $stmt->bind_param("iss", $account_id, $start_date, $end_date);
    } else {
        $stmt->bind_param("i", $account_id);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $transaction = [
            'journal_entry_id' => $row['journal_entry_id'],
            'journal_entry_number' => $row['journal_entry_number'],
            'transaction_date' => $row['transaction_date'],
            'transaction_type' => $row['transaction_type'],
            'entry_description' => $row['entry_description'],
            'line_description' => $row['line_description'],
            'debit_amount' => floatval($row['debit_amount']),
            'credit_amount' => floatval($row['credit_amount']),
            'reference_type' => $row['reference_type'],
            'reference_id' => $row['reference_id'],
            'created_by' => $row['created_by']
        ];
        
        // Calculate running balance
        $net_change = $transaction['debit_amount'] - $transaction['credit_amount'];
        $data['running_balance'] += $net_change;
        $transaction['running_balance'] = $data['running_balance'];
        
        $data['transactions'][] = $transaction;
        $data['total_debits'] += $transaction['debit_amount'];
        $data['total_credits'] += $transaction['credit_amount'];
    }
    
    // Calculate closing balance
    $data['closing_balance'] = $data['running_balance'];
    
    return $data;
}

// Get ledger data
$ledger_data = getAccountLedgerData($mysqli, $account_id, $dtf, $dtt, $show_all);

// Get account statistics
$stats_sql = "SELECT 
    COUNT(DISTINCT jel.journal_entry_id) as total_transactions,
    COUNT(DISTINCT DATE(je.transaction_date)) as active_days,
    COALESCE(SUM(jel.debit_amount), 0) as total_debits_all,
    COALESCE(SUM(jel.credit_amount), 0) as total_credits_all,
    (SELECT COUNT(DISTINCT DATE(je.transaction_date)) 
     FROM journal_entry_lines jel2
     JOIN journal_entries je ON jel2.journal_entry_id = je.journal_entry_id
     WHERE jel2.account_id = ? 
     AND je.status = 'posted'
     AND YEAR(je.transaction_date) = YEAR(CURDATE())) as days_this_year
FROM journal_entry_lines jel
JOIN journal_entries je ON jel.journal_entry_id = je.journal_entry_id
WHERE jel.account_id = ? 
AND je.status = 'posted'";

$stats_stmt = $mysqli->prepare($stats_sql);
$stats_stmt->bind_param("ii", $account_id, $account_id);
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();
$account_stats = $stats_result->fetch_assoc();

// Get recent activity
$recent_sql = "SELECT 
    a.activity_id,
    a.activity_type,
    a.activity_description,
    a.activity_date,
    u.user_name as performed_by_name
FROM activities a
LEFT JOIN users u ON a.performed_by = u.user_id
WHERE a.related_type = 'account' AND a.related_id = ?
ORDER BY a.activity_date DESC
LIMIT 10";

$recent_stmt = $mysqli->prepare($recent_sql);
$recent_stmt->bind_param("i", $account_id);
$recent_stmt->execute();
$recent_activity = $recent_stmt->get_result();
?>

<div class="card">
    <div class="card-header <?php echo $account['account_type'] == 'asset' ? 'bg-success' : 
                                ($account['account_type'] == 'liability' ? 'bg-warning' : 
                                ($account['account_type'] == 'equity' ? 'bg-primary' : 
                                ($account['account_type'] == 'revenue' ? 'bg-info' : 'bg-danger'))); ?> py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-book mr-2"></i>
            Account Ledger: <?php echo htmlspecialchars($account['account_number'] . ' - ' . $account['account_name']); ?>
        </h3>
        <div class="card-tools">
            <div class="btn-group">
                <button type="button" class="btn btn-light dropdown-toggle" data-toggle="dropdown">
                    <i class="fas fa-download mr-2"></i>Export
                </button>
                <div class="dropdown-menu">
                    <a class="dropdown-item" href="#" onclick="printReport()">
                        <i class="fas fa-print mr-2"></i>Print Ledger
                    </a>
                    <a class="dropdown-item" href="#" onclick="exportPDF()">
                        <i class="fas fa-file-pdf mr-2"></i>PDF
                    </a>
                    <a class="dropdown-item" href="#" onclick="exportExcel()">
                        <i class="fas fa-file-excel mr-2"></i>Excel
                    </a>
                </div>
                <a href="accounts.php" class="btn btn-light ml-2">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Accounts
                </a>
            </div>
        </div>
    </div>
    
    <!-- Account Summary -->
    <div class="card-body border-bottom">
        <div class="row">
            <div class="col-md-6">
                <div class="card card-sm">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-8">
                                <h4 class="mb-1"><?php echo htmlspecialchars($account['account_number']); ?></h4>
                                <h5 class="mb-2"><?php echo htmlspecialchars($account['account_name']); ?></h5>
                                <div class="mb-2">
                                    <span class="badge badge-<?php 
                                        echo $account['account_type'] == 'asset' ? 'success' : 
                                             ($account['account_type'] == 'liability' ? 'warning' : 
                                             ($account['account_type'] == 'equity' ? 'primary' : 
                                             ($account['account_type'] == 'revenue' ? 'info' : 'danger'))); 
                                    ?> mr-2">
                                        <?php echo ucfirst($account['account_type']); ?>
                                    </span>
                                    <span class="badge badge-dark">
                                        <?php echo ucfirst($account['normal_balance']); ?> Normal Balance
                                    </span>
                                </div>
                                <?php if ($account['description']): ?>
                                    <p class="text-muted mb-2"><?php echo htmlspecialchars($account['description']); ?></p>
                                <?php endif; ?>
                                <?php if ($account['parent_account_number']): ?>
                                    <p class="mb-0">
                                        <small class="text-muted">Parent Account: </small>
                                        <strong><?php echo htmlspecialchars($account['parent_account_number'] . ' - ' . $account['parent_account_name']); ?></strong>
                                    </p>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-4 text-right">
                                <div class="h3 <?php echo $account['balance'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                    <?php echo numfmt_format_currency($currency_format, $account['balance'], $session_company_currency); ?>
                                </div>
                                <small class="text-muted">Current Balance</small>
                                <div class="mt-2">
                                    <?php if ($account['is_active']): ?>
                                        <span class="badge badge-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger">Inactive</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="row text-center">
                    <div class="col-md-4">
                        <div class="info-box bg-light">
                            <span class="info-box-icon bg-info"><i class="fas fa-exchange-alt"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Total Transactions</span>
                                <span class="info-box-number"><?php echo $account_stats['total_transactions']; ?></span>
                                <small class="text-muted">All time</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="info-box bg-light">
                            <span class="info-box-icon bg-success"><i class="fas fa-arrow-up"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Total Debits</span>
                                <span class="info-box-number"><?php echo numfmt_format_currency($currency_format, $account_stats['total_debits_all'], $session_company_currency); ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="info-box bg-light">
                            <span class="info-box-icon bg-danger"><i class="fas fa-arrow-down"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Total Credits</span>
                                <span class="info-box-number"><?php echo numfmt_format_currency($currency_format, $account_stats['total_credits_all'], $session_company_currency); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Ledger Controls -->
    <div class="card-header pb-2 pt-3">
        <form method="GET" class="row align-items-end">
            <input type="hidden" name="id" value="<?php echo $account_id; ?>">
            <div class="col-md-3">
                <div class="form-group">
                    <label class="font-weight-bold">Date from</label>
                    <input type="date" class="form-control" name="dtf" value="<?php echo htmlspecialchars($dtf); ?>">
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    <label class="font-weight-bold">Date to</label>
                    <input type="date" class="form-control" name="dtt" value="<?php echo htmlspecialchars($dtt); ?>">
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    <label class="font-weight-bold">Options</label>
                    <div class="custom-control custom-checkbox mt-2">
                        <input type="checkbox" class="custom-control-input" id="show_all" name="show_all" value="1" <?php echo $show_all ? 'checked' : ''; ?>>
                        <label class="custom-control-label" for="show_all">Show All Transactions</label>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="btn-group btn-block">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-sync mr-2"></i>Update Ledger
                    </button>
                    <a href="account_ledger.php?id=<?php echo $account_id; ?>" class="btn btn-outline-secondary">
                        <i class="fas fa-times mr-2"></i>Reset
                    </a>
                </div>
            </div>
        </form>
    </div>
    
    <!-- Ledger Summary -->
    <div class="card-body border-bottom">
        <div class="row text-center">
            <div class="col-md-3">
                <div class="card card-sm">
                    <div class="card-body">
                        <div class="h4 text-primary"><?php echo numfmt_format_currency($currency_format, $ledger_data['opening_balance'], $session_company_currency); ?></div>
                        <small class="text-muted">Opening Balance</small>
                        <div class="small mt-1">
                            <span class="badge badge-light">As of <?php echo date('M j, Y', strtotime($dtf . ' -1 day')); ?></span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card card-sm">
                    <div class="card-body">
                        <div class="h4 text-success"><?php echo numfmt_format_currency($currency_format, $ledger_data['total_debits'], $session_company_currency); ?></div>
                        <small class="text-muted">Period Debits</small>
                        <div class="small mt-1">
                            <span class="badge badge-success"><?php echo count(array_filter($ledger_data['transactions'], function($t) { return $t['debit_amount'] > 0; })); ?> entries</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card card-sm">
                    <div class="card-body">
                        <div class="h4 text-danger"><?php echo numfmt_format_currency($currency_format, $ledger_data['total_credits'], $session_company_currency); ?></div>
                        <small class="text-muted">Period Credits</small>
                        <div class="small mt-1">
                            <span class="badge badge-danger"><?php echo count(array_filter($ledger_data['transactions'], function($t) { return $t['credit_amount'] > 0; })); ?> entries</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card card-sm">
                    <div class="card-body">
                        <div class="h4 <?php echo $ledger_data['closing_balance'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                            <?php echo numfmt_format_currency($currency_format, $ledger_data['closing_balance'], $session_company_currency); ?>
                        </div>
                        <small class="text-muted">Closing Balance</small>
                        <div class="small mt-1">
                            <span class="badge badge-info">As of <?php echo date('M j, Y', strtotime($dtt)); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card-body">
        <!-- Account Ledger Table -->
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0">
                            <i class="fas fa-file-invoice-dollar mr-2"></i>
                            Account Ledger - Period: <?php echo date('M j, Y', strtotime($dtf)); ?> to <?php echo date('M j, Y', strtotime($dtt)); ?>
                        </h4>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Date</th>
                                        <th>Entry #</th>
                                        <th>Description</th>
                                        <th>Type</th>
                                        <th class="text-right">Debit</th>
                                        <th class="text-right">Credit</th>
                                        <th class="text-right">Balance</th>
                                        <th>Created By</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- Opening Balance Row -->
                                    <tr class="bg-light">
                                        <td colspan="4" class="font-weight-bold text-primary">
                                            Opening Balance as of <?php echo date('M j, Y', strtotime($dtf . ' -1 day')); ?>
                                        </td>
                                        <td colspan="2" class="text-right font-weight-bold">
                                            <?php echo numfmt_format_currency($currency_format, $ledger_data['opening_balance'], $session_company_currency); ?>
                                        </td>
                                        <td class="text-right font-weight-bold <?php echo $ledger_data['opening_balance'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                            <?php echo numfmt_format_currency($currency_format, $ledger_data['opening_balance'], $session_company_currency); ?>
                                        </td>
                                        <td colspan="2" class="text-muted text-center">-</td>
                                    </tr>
                                    
                                    <?php if (empty($ledger_data['transactions'])): ?>
                                        <tr>
                                            <td colspan="9" class="text-center py-4">
                                                <i class="fas fa-exchange-alt fa-3x text-muted mb-3"></i>
                                                <h5 class="text-muted">No transactions found</h5>
                                                <p class="text-muted">No transactions for this account in the selected period.</p>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($ledger_data['transactions'] as $transaction): 
                                            $balance_class = $transaction['running_balance'] >= 0 ? 'text-success' : 'text-danger';
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="font-weight-bold"><?php echo date('M j, Y', strtotime($transaction['transaction_date'])); ?></div>
                                                <small class="text-muted"><?php echo date('D', strtotime($transaction['transaction_date'])); ?></small>
                                            </td>
                                            <td class="font-weight-bold text-primary"><?php echo htmlspecialchars($transaction['journal_entry_number']); ?></td>
                                            <td>
                                                <div class="font-weight-bold"><?php echo htmlspecialchars($transaction['entry_description']); ?></div>
                                                <?php if ($transaction['line_description']): ?>
                                                    <small class="text-muted"><?php echo htmlspecialchars($transaction['line_description']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge badge-secondary"><?php echo str_replace('_', ' ', $transaction['transaction_type']); ?></span>
                                            </td>
                                            <td class="text-right <?php echo $transaction['debit_amount'] > 0 ? 'text-success font-weight-bold' : 'text-muted'; ?>">
                                                <?php echo $transaction['debit_amount'] > 0 ? numfmt_format_currency($currency_format, $transaction['debit_amount'], $session_company_currency) : '-'; ?>
                                            </td>
                                            <td class="text-right <?php echo $transaction['credit_amount'] > 0 ? 'text-danger font-weight-bold' : 'text-muted'; ?>">
                                                <?php echo $transaction['credit_amount'] > 0 ? numfmt_format_currency($currency_format, $transaction['credit_amount'], $session_company_currency) : '-'; ?>
                                            </td>
                                            <td class="text-right font-weight-bold <?php echo $balance_class; ?>">
                                                <?php echo numfmt_format_currency($currency_format, $transaction['running_balance'], $session_company_currency); ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($transaction['created_by']); ?></td>
                                            <td class="text-center">
                                                <a href="journal_entry_view.php?id=<?php echo $transaction['journal_entry_id']; ?>" class="btn btn-sm btn-info" title="View Entry">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                        
                                        <!-- Closing Balance Row -->
                                        <tr class="bg-primary text-white">
                                            <td colspan="4" class="font-weight-bold">
                                                Closing Balance as of <?php echo date('M j, Y', strtotime($dtt)); ?>
                                            </td>
                                            <td colspan="2" class="text-right font-weight-bold">
                                                <?php 
                                                $period_change = $ledger_data['closing_balance'] - $ledger_data['opening_balance'];
                                                echo numfmt_format_currency($currency_format, $period_change, $session_company_currency);
                                                ?>
                                            </td>
                                            <td class="text-right font-weight-bold">
                                                <?php echo numfmt_format_currency($currency_format, $ledger_data['closing_balance'], $session_company_currency); ?>
                                            </td>
                                            <td colspan="2" class="text-center">
                                                <span class="badge badge-light">Net Change: <?php echo numfmt_format_currency($currency_format, $period_change, $session_company_currency); ?></span>
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
        
        <!-- Recent Activity -->
        <div class="row mt-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-stream mr-2"></i>Recent Account Activity</h4>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Date & Time</th>
                                        <th>Activity Type</th>
                                        <th>Description</th>
                                        <th>Performed By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($recent_activity->num_rows > 0): ?>
                                        <?php while($activity = $recent_activity->fetch_assoc()): ?>
                                            <tr>
                                                <td>
                                                    <div class="font-weight-bold"><?php echo date('M j, Y', strtotime($activity['activity_date'])); ?></div>
                                                    <small class="text-muted"><?php echo date('H:i', strtotime($activity['activity_date'])); ?></small>
                                                </td>
                                                <td>
                                                    <span class="badge badge-info"><?php echo str_replace('_', ' ', $activity['activity_type']); ?></span>
                                                </td>
                                                <td><?php echo htmlspecialchars($activity['activity_description']); ?></td>
                                                <td><?php echo htmlspecialchars($activity['performed_by_name']); ?></td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4" class="text-center py-3">
                                                <i class="fas fa-stream fa-2x text-muted mb-2"></i><br>
                                                No recent activity found
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
        
        <!-- Quick Actions -->
        <div class="row mt-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-bolt mr-2"></i>Quick Actions</h4>
                    </div>
                    <div class="card-body">
                        <div class="btn-group">
                            <a href="account_edit.php?id=<?php echo $account_id; ?>" class="btn btn-success">
                                <i class="fas fa-edit mr-2"></i>Edit Account
                            </a>
                            <a href="reports_account_statement.php?account_id=<?php echo $account_id; ?>" class="btn btn-info">
                                <i class="fas fa-file-invoice mr-2"></i>Account Statement
                            </a>
                            <a href="reports_general_ledger.php?account_id=<?php echo $account_id; ?>" class="btn btn-primary">
                                <i class="fas fa-book mr-2"></i>General Ledger
                            </a>
                            <a href="journal_entry_new.php?account_id=<?php echo $account_id; ?>" class="btn btn-warning">
                                <i class="fas fa-plus-circle mr-2"></i>Add Transaction
                            </a>
                            <?php if ($account['is_active']): ?>
                                <a href="post/account.php?deactivate_account=<?php echo $account_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>" class="btn btn-danger confirm-link">
                                    <i class="fas fa-pause mr-2"></i>Deactivate Account
                                </a>
                            <?php else: ?>
                                <a href="post/account.php?activate_account=<?php echo $account_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>" class="btn btn-success confirm-link">
                                    <i class="fas fa-play mr-2"></i>Activate Account
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Confirm actions
    $('.confirm-link').click(function(e) {
        if (!confirm('Are you sure you want to perform this action?')) {
            e.preventDefault();
        }
    });
});

function printReport() {
    const printContent = document.querySelector('.card').innerHTML;
    const originalContent = document.body.innerHTML;
    
    document.body.innerHTML = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>Account Ledger - <?php echo htmlspecialchars($account['account_number'] . ' - ' . $account['account_name']); ?></title>
            <style>
                body { font-family: Arial, sans-serif; }
                .report-header { text-align: center; margin-bottom: 20px; }
                .company-name { font-size: 24px; font-weight: bold; }
                .report-title { font-size: 18px; margin: 10px 0; }
                .account-info { text-align: left; margin-bottom: 20px; }
                table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
                th, td { padding: 6px; text-align: left; border-bottom: 1px solid #ddd; }
                th { background-color: #f5f5f5; font-weight: bold; }
                .text-right { text-align: right; }
                .total-row { font-weight: bold; background-color: #f0f0f0; }
                @media print {
                    .no-print { display: none; }
                    body { font-size: 11px; }
                    .btn-group { display: none; }
                }
            </style>
        </head>
        <body>
            <div class="report-header">
                <div class="company-name"><?php echo htmlspecialchars($session_company_name); ?></div>
                <div class="report-title">Account Ledger</div>
                <div class="account-info">
                    <strong>Account:</strong> <?php echo htmlspecialchars($account['account_number'] . ' - ' . $account['account_name']); ?><br>
                    <strong>Period:</strong> <?php echo date('F j, Y', strtotime($dtf)); ?> to <?php echo date('F j, Y', strtotime($dtt)); ?>
                </div>
            </div>
            ${printContent}
        </body>
        </html>
    `;
    
    window.print();
    document.body.innerHTML = originalContent;
    location.reload();
}

function exportPDF() {
    window.location.href = 'export_pdf.php?report=account_ledger&account_id=<?php echo $account_id; ?>&start=<?php echo $dtf; ?>&end=<?php echo $dtt; ?>';
}

function exportExcel() {
    window.location.href = 'export_excel.php?report=account_ledger&account_id=<?php echo $account_id; ?>&start=<?php echo $dtf; ?>&end=<?php echo $dtt; ?>';
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + P to print
    if (e.ctrlKey && e.keyCode === 80) {
        e.preventDefault();
        printReport();
    }
    // Escape to go back
    if (e.keyCode === 27) {
        window.location.href = 'accounts.php';
    }
    // Ctrl + E to edit
    if (e.ctrlKey && e.keyCode === 69) {
        e.preventDefault();
        window.location.href = 'account_edit.php?id=<?php echo $account_id; ?>';
    }
    // Ctrl + S for statement
    if (e.ctrlKey && e.keyCode === 83) {
        e.preventDefault();
        window.location.href = 'reports_account_statement.php?account_id=<?php echo $account_id; ?>';
    }
});
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
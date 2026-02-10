<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Check permissions
if (!isset($_SESSION['user_id'])) {
    header("Location: /login.php");
    exit;
}

// Get parameters
$account_id = intval($_GET['account_id'] ?? 0);
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

// Get all accounts for dropdown
$accounts_sql = "SELECT account_id, account_number, account_name, account_type 
                FROM accounts 
                WHERE is_active = 1 
                ORDER BY account_type, account_number";
$accounts_result = $mysqli->query($accounts_sql);

// Get selected account details
$account_details = null;
if ($account_id > 0) {
    $account_sql = "SELECT a.*, parent.account_number as parent_account_number, 
                           parent.account_name as parent_account_name
                    FROM accounts a
                    LEFT JOIN accounts parent ON a.parent_account_id = parent.account_id
                    WHERE a.account_id = ?";
    $account_stmt = $mysqli->prepare($account_sql);
    $account_stmt->bind_param("i", $account_id);
    $account_stmt->execute();
    $account_details = $account_stmt->get_result()->fetch_assoc();
}

// Get general ledger data
function getGeneralLedgerData($mysqli, $account_id = 0, $start_date = '', $end_date = '', $show_all = false) {
    $data = [
        'transactions' => [],
        'opening_balance' => 0,
        'total_debits' => 0,
        'total_credits' => 0,
        'closing_balance' => 0,
        'running_balance' => 0
    ];
    
    // Get account opening balance (balance before start date)
    if ($account_id > 0) {
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
    }
    
    // Build WHERE clause
    $where_clause = "je.status = 'posted'";
    if ($account_id > 0) {
        $where_clause .= " AND jel.account_id = ?";
    }
    if (!empty($start_date) && !empty($end_date)) {
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
    
    if ($account_id > 0 && !empty($start_date) && !empty($end_date)) {
        $stmt->bind_param("iss", $account_id, $start_date, $end_date);
    } elseif ($account_id > 0) {
        $stmt->bind_param("i", $account_id);
    } elseif (!empty($start_date) && !empty($end_date)) {
        $stmt->bind_param("ss", $start_date, $end_date);
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
        if ($account_id > 0) {
            $net_change = $transaction['debit_amount'] - $transaction['credit_amount'];
            $data['running_balance'] += $net_change;
            $transaction['running_balance'] = $data['running_balance'];
        }
        
        $data['transactions'][] = $transaction;
        $data['total_debits'] += $transaction['debit_amount'];
        $data['total_credits'] += $transaction['credit_amount'];
    }
    
    // Calculate closing balance
    if ($account_id > 0) {
        $data['closing_balance'] = $data['running_balance'];
    } else {
        $data['closing_balance'] = $data['total_debits'] - $data['total_credits'];
    }
    
    return $data;
}

// Get general ledger data
$ledger_data = getGeneralLedgerData($mysqli, $account_id, $dtf, $dtt, $show_all);

// Get statistics for all accounts
$stats_sql = "SELECT 
    COUNT(*) as total_accounts,
    COUNT(CASE WHEN a.account_type = 'asset' THEN 1 END) as asset_accounts,
    COUNT(CASE WHEN a.account_type = 'liability' THEN 1 END) as liability_accounts,
    COUNT(CASE WHEN a.account_type = 'equity' THEN 1 END) as equity_accounts,
    COUNT(CASE WHEN a.account_type = 'revenue' THEN 1 END) as revenue_accounts,
    COUNT(CASE WHEN a.account_type = 'expense' THEN 1 END) as expense_accounts,
    (SELECT COUNT(DISTINCT jel.account_id) 
     FROM journal_entry_lines jel
     JOIN journal_entries je ON jel.journal_entry_id = je.journal_entry_id
     WHERE je.status = 'posted'
     AND je.transaction_date BETWEEN ? AND ?) as active_accounts_period
FROM accounts a
WHERE a.is_active = 1";

$stats_stmt = $mysqli->prepare($stats_sql);
$stats_stmt->bind_param("ss", $dtf, $dtt);
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();
$stats = $stats_result->fetch_assoc();
?>

<div class="card">
    <div class="card-header bg-info py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-book mr-2"></i>General Ledger Report
        </h3>
        <div class="card-tools">
            <div class="btn-group">
                <button type="button" class="btn btn-light dropdown-toggle" data-toggle="dropdown">
                    <i class="fas fa-download mr-2"></i>Export
                </button>
                <div class="dropdown-menu">
                    <a class="dropdown-item" href="#" onclick="printReport()">
                        <i class="fas fa-print mr-2"></i>Print
                    </a>
                    <a class="dropdown-item" href="#" onclick="exportPDF()">
                        <i class="fas fa-file-pdf mr-2"></i>PDF
                    </a>
                    <a class="dropdown-item" href="#" onclick="exportExcel()">
                        <i class="fas fa-file-excel mr-2"></i>Excel
                    </a>
                </div>
                <a href="reports.php" class="btn btn-light ml-2">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Reports
                </a>
            </div>
        </div>
    </div>
    
    <!-- Report Controls -->
    <div class="card-body border-bottom">
        <form method="GET" class="row align-items-end">
            <div class="col-md-3">
                <div class="form-group">
                    <label class="font-weight-bold">Account</label>
                    <select class="form-control select2" name="account_id" onchange="this.form.submit()">
                        <option value="0">All Accounts (General Ledger)</option>
                        <?php while($account = $accounts_result->fetch_assoc()): ?>
                            <option value="<?php echo $account['account_id']; ?>" 
                                <?php echo $account_id == $account['account_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($account['account_number'] . ' - ' . $account['account_name'] . ' (' . $account['account_type'] . ')'); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
            </div>
            <div class="col-md-2">
                <div class="form-group">
                    <label class="font-weight-bold">Date from</label>
                    <input type="date" class="form-control" name="dtf" value="<?php echo htmlspecialchars($dtf); ?>">
                </div>
            </div>
            <div class="col-md-2">
                <div class="form-group">
                    <label class="font-weight-bold">Date to</label>
                    <input type="date" class="form-control" name="dtt" value="<?php echo htmlspecialchars($dtt); ?>">
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    <label class="font-weight-bold">Options</label>
                    <div class="custom-control custom-checkbox mt-2">
                        <input type="checkbox" class="custom-control-input" id="show_all" name="show_all" value="1" <?php echo $show_all ? 'checked' : ''; ?> onchange="this.form.submit()">
                        <label class="custom-control-label" for="show_all">Show All Periods</label>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="btn-group btn-block">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-sync mr-2"></i>Update
                    </button>
                    <a href="reports_general_ledger.php" class="btn btn-outline-secondary">
                        <i class="fas fa-times mr-2"></i>Reset
                    </a>
                </div>
            </div>
        </form>
    </div>
    
    <!-- Summary Statistics -->
    <div class="card-body border-bottom">
        <div class="row text-center">
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-secondary"><i class="fas fa-chart-pie"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Accounts</span>
                        <span class="info-box-number"><?php echo $stats['total_accounts']; ?></span>
                        <small class="text-muted"><?php echo $stats['active_accounts_period']; ?> active</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-info"><i class="fas fa-exchange-alt"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Transactions</span>
                        <span class="info-box-number"><?php echo count($ledger_data['transactions']); ?></span>
                        <small class="text-muted">In period</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-success"><i class="fas fa-arrow-up"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Debits</span>
                        <span class="info-box-number"><?php echo numfmt_format_currency($currency_format, $ledger_data['total_debits'], $session_company_currency); ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-danger"><i class="fas fa-arrow-down"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Credits</span>
                        <span class="info-box-number"><?php echo numfmt_format_currency($currency_format, $ledger_data['total_credits'], $session_company_currency); ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-primary"><i class="fas fa-balance-scale"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Net Change</span>
                        <span class="info-box-number"><?php echo numfmt_format_currency($currency_format, $ledger_data['closing_balance'], $session_company_currency); ?></span>
                        <small class="text-muted">For selected account(s)</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card-body">
        <!-- Account Information (if single account selected) -->
        <?php if ($account_id > 0 && $account_details): ?>
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0">
                            <i class="fas fa-info-circle mr-2"></i>
                            Account Information: <?php echo htmlspecialchars($account_details['account_number'] . ' - ' . $account_details['account_name']); ?>
                        </h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3">
                                <div class="text-center p-3 border rounded">
                                    <div class="h4 text-primary">
                                        <?php echo numfmt_format_currency($currency_format, $ledger_data['opening_balance'], $session_company_currency); ?>
                                    </div>
                                    <small class="text-muted">Opening Balance</small>
                                    <div class="small mt-1">
                                        <span class="badge badge-light">As of <?php echo date('M j, Y', strtotime($dtf . ' -1 day')); ?></span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-center p-3 border rounded">
                                    <div class="h4 text-success">
                                        <?php echo numfmt_format_currency($currency_format, $ledger_data['total_debits'], $session_company_currency); ?>
                                    </div>
                                    <small class="text-muted">Total Debits</small>
                                    <div class="small mt-1">
                                        <span class="badge badge-success"><?php echo count(array_filter($ledger_data['transactions'], function($t) { return $t['debit_amount'] > 0; })); ?> entries</span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-center p-3 border rounded">
                                    <div class="h4 text-danger">
                                        <?php echo numfmt_format_currency($currency_format, $ledger_data['total_credits'], $session_company_currency); ?>
                                    </div>
                                    <small class="text-muted">Total Credits</small>
                                    <div class="small mt-1">
                                        <span class="badge badge-danger"><?php echo count(array_filter($ledger_data['transactions'], function($t) { return $t['credit_amount'] > 0; })); ?> entries</span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-center p-3 border rounded">
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
                        
                        <div class="row mt-3">
                            <div class="col-md-6">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <th width="40%" class="text-muted">Account Type:</th>
                                        <td>
                                            <span class="badge badge-<?php 
                                                echo $account_details['account_type'] == 'asset' ? 'success' : 
                                                     ($account_details['account_type'] == 'liability' ? 'warning' : 
                                                     ($account_details['account_type'] == 'equity' ? 'primary' : 
                                                     ($account_details['account_type'] == 'revenue' ? 'info' : 'danger'))); 
                                            ?>">
                                                <?php echo ucfirst($account_details['account_type']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">Normal Balance:</th>
                                        <td>
                                            <span class="badge badge-dark"><?php echo ucfirst($account_details['normal_balance']); ?></span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">Account Subtype:</th>
                                        <td><?php echo htmlspecialchars($account_details['account_subtype'] ?: 'N/A'); ?></td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <th width="40%" class="text-muted">Parent Account:</th>
                                        <td>
                                            <?php if ($account_details['parent_account_number']): ?>
                                                <?php echo htmlspecialchars($account_details['parent_account_number'] . ' - ' . $account_details['parent_account_name']); ?>
                                            <?php else: ?>
                                                <span class="text-muted">None</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">Description:</th>
                                        <td><?php echo htmlspecialchars($account_details['description'] ?: 'No description'); ?></td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">Report Period:</th>
                                        <td><?php echo date('M j, Y', strtotime($dtf)); ?> to <?php echo date('M j, Y', strtotime($dtt)); ?></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- General Ledger Report -->
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0">
                            <i class="fas fa-file-invoice-dollar mr-2"></i>
                            General Ledger 
                            <?php if ($account_id > 0 && $account_details): ?>
                                for Account <?php echo htmlspecialchars($account_details['account_number']); ?>
                            <?php else: ?>
                                (All Accounts)
                            <?php endif; ?>
                            - Period: <?php echo date('M j, Y', strtotime($dtf)); ?> to <?php echo date('M j, Y', strtotime($dtt)); ?>
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
                                        <?php if ($account_id == 0): ?>
                                        <th>Account</th>
                                        <?php endif; ?>
                                        <th>Type</th>
                                        <th class="text-right">Debit</th>
                                        <th class="text-right">Credit</th>
                                        <?php if ($account_id > 0): ?>
                                        <th class="text-right">Balance</th>
                                        <?php endif; ?>
                                        <th>Created By</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- Opening Balance Row (for single account) -->
                                    <?php if ($account_id > 0): ?>
                                    <tr class="bg-light">
                                        <td colspan="<?php echo $account_id > 0 ? 6 : 5; ?>" class="font-weight-bold text-primary">
                                            Opening Balance as of <?php echo date('M j, Y', strtotime($dtf . ' -1 day')); ?>
                                        </td>
                                        <td colspan="2" class="text-right font-weight-bold">
                                            <?php echo numfmt_format_currency($currency_format, $ledger_data['opening_balance'], $session_company_currency); ?>
                                        </td>
                                        <td colspan="2" class="text-muted text-center">-</td>
                                    </tr>
                                    <?php endif; ?>
                                    
                                    <?php if (empty($ledger_data['transactions'])): ?>
                                        <tr>
                                            <td colspan="<?php echo $account_id > 0 ? 10 : 9; ?>" class="text-center py-4">
                                                <i class="fas fa-book fa-3x text-muted mb-3"></i>
                                                <h5 class="text-muted">No transactions found</h5>
                                                <p class="text-muted">
                                                    <?php if ($account_id > 0): ?>
                                                        No transactions for this account in the selected period.
                                                    <?php else: ?>
                                                        No transactions found in the selected period.
                                                    <?php endif; ?>
                                                </p>
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
                                            <?php if ($account_id == 0): 
                                                // Get account info for this transaction
                                                $account_info_sql = "SELECT a.account_number, a.account_name 
                                                                    FROM accounts a 
                                                                    WHERE a.account_id = (
                                                                        SELECT jel.account_id 
                                                                        FROM journal_entry_lines jel 
                                                                        WHERE jel.journal_entry_id = ? 
                                                                        LIMIT 1
                                                                    )";
                                                $account_info_stmt = $mysqli->prepare($account_info_sql);
                                                $account_info_stmt->bind_param("i", $transaction['journal_entry_id']);
                                                $account_info_stmt->execute();
                                                $account_info = $account_info_stmt->get_result()->fetch_assoc();
                                            ?>
                                            <td>
                                                <?php if ($account_info): ?>
                                                    <div class="font-weight-bold"><?php echo htmlspecialchars($account_info['account_number']); ?></div>
                                                    <small class="text-muted"><?php echo htmlspecialchars($account_info['account_name']); ?></small>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <?php endif; ?>
                                            <td>
                                                <span class="badge badge-secondary"><?php echo str_replace('_', ' ', $transaction['transaction_type']); ?></span>
                                            </td>
                                            <td class="text-right <?php echo $transaction['debit_amount'] > 0 ? 'text-success font-weight-bold' : 'text-muted'; ?>">
                                                <?php echo $transaction['debit_amount'] > 0 ? numfmt_format_currency($currency_format, $transaction['debit_amount'], $session_company_currency) : '-'; ?>
                                            </td>
                                            <td class="text-right <?php echo $transaction['credit_amount'] > 0 ? 'text-danger font-weight-bold' : 'text-muted'; ?>">
                                                <?php echo $transaction['credit_amount'] > 0 ? numfmt_format_currency($currency_format, $transaction['credit_amount'], $session_company_currency) : '-'; ?>
                                            </td>
                                            <?php if ($account_id > 0): ?>
                                            <td class="text-right font-weight-bold <?php echo $balance_class; ?>">
                                                <?php echo numfmt_format_currency($currency_format, $transaction['running_balance'], $session_company_currency); ?>
                                            </td>
                                            <?php endif; ?>
                                            <td><?php echo htmlspecialchars($transaction['created_by']); ?></td>
                                            <td class="text-center">
                                                <a href="journal_entry_view.php?id=<?php echo $transaction['journal_entry_id']; ?>" class="btn btn-sm btn-info" title="View Entry">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                        
                                        <!-- Closing Balance Row (for single account) -->
                                        <?php if ($account_id > 0): ?>
                                        <tr class="bg-primary text-white">
                                            <td colspan="<?php echo $account_id > 0 ? 6 : 5; ?>" class="font-weight-bold">
                                                Closing Balance as of <?php echo date('M j, Y', strtotime($dtt)); ?>
                                            </td>
                                            <td colspan="2" class="text-right font-weight-bold">
                                                <?php echo numfmt_format_currency($currency_format, $ledger_data['closing_balance'], $session_company_currency); ?>
                                            </td>
                                            <td colspan="2" class="text-center">
                                                <span class="badge badge-light">Period Change: <?php echo numfmt_format_currency($currency_format, $ledger_data['closing_balance'] - $ledger_data['opening_balance'], $session_company_currency); ?></span>
                                            </td>
                                        </tr>
                                        <?php endif; ?>
                                        
                                        <!-- Totals Row (for all accounts) -->
                                        <?php if ($account_id == 0): ?>
                                        <tr class="bg-warning">
                                            <td colspan="4" class="font-weight-bold">TOTALS</td>
                                            <td></td>
                                            <td class="text-right font-weight-bold text-success">
                                                <?php echo numfmt_format_currency($currency_format, $ledger_data['total_debits'], $session_company_currency); ?>
                                            </td>
                                            <td class="text-right font-weight-bold text-danger">
                                                <?php echo numfmt_format_currency($currency_format, $ledger_data['total_credits'], $session_company_currency); ?>
                                            </td>
                                            <td class="text-right font-weight-bold text-primary">
                                                <?php echo numfmt_format_currency($currency_format, $ledger_data['closing_balance'], $session_company_currency); ?>
                                            </td>
                                            <td colspan="2"></td>
                                        </tr>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Transaction Summary -->
        <div class="row mt-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-chart-bar mr-2"></i>Transaction Summary</h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6>Transaction Statistics</h6>
                                <div class="table-responsive">
                                    <table class="table table-sm table-borderless">
                                        <tr>
                                            <th width="60%" class="text-muted">Total Transactions:</th>
                                            <td class="text-right font-weight-bold"><?php echo count($ledger_data['transactions']); ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Debit Entries:</th>
                                            <td class="text-right">
                                                <span class="font-weight-bold text-success"><?php echo count(array_filter($ledger_data['transactions'], function($t) { return $t['debit_amount'] > 0; })); ?></span>
                                                <small class="text-muted ml-2">
                                                    (<?php echo numfmt_format_currency($currency_format, $ledger_data['total_debits'], $session_company_currency); ?>)
                                                </small>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Credit Entries:</th>
                                            <td class="text-right">
                                                <span class="font-weight-bold text-danger"><?php echo count(array_filter($ledger_data['transactions'], function($t) { return $t['credit_amount'] > 0; })); ?></span>
                                                <small class="text-muted ml-2">
                                                    (<?php echo numfmt_format_currency($currency_format, $ledger_data['total_credits'], $session_company_currency); ?>)
                                                </small>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Average Transaction:</th>
                                            <td class="text-right font-weight-bold">
                                                <?php 
                                                $avg_transaction = count($ledger_data['transactions']) > 0 ? 
                                                    ($ledger_data['total_debits'] + $ledger_data['total_credits']) / count($ledger_data['transactions']) : 0;
                                                echo numfmt_format_currency($currency_format, $avg_transaction, $session_company_currency);
                                                ?>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <h6>Period Summary</h6>
                                <div class="table-responsive">
                                    <table class="table table-sm table-borderless">
                                        <tr>
                                            <th width="60%" class="text-muted">Report Period:</th>
                                            <td class="text-right"><?php echo date('M j, Y', strtotime($dtf)); ?> to <?php echo date('M j, Y', strtotime($dtt)); ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Days in Period:</th>
                                            <td class="text-right">
                                                <?php 
                                                $days = (strtotime($dtt) - strtotime($dtf)) / (60 * 60 * 24) + 1;
                                                echo floor($days);
                                                ?> days
                                            </td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Transactions per Day:</th>
                                            <td class="text-right font-weight-bold">
                                                <?php 
                                                $trans_per_day = $days > 0 ? count($ledger_data['transactions']) / $days : 0;
                                                echo number_format($trans_per_day, 1);
                                                ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Generated:</th>
                                            <td class="text-right"><?php echo date('M j, Y H:i'); ?></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
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
                            <a href="reports_trial_balance.php?dtf=<?php echo $dtf; ?>&dtt=<?php echo $dtt; ?>" class="btn btn-outline-primary">
                                <i class="fas fa-balance-scale-left mr-2"></i>Trial Balance
                            </a>
                            <a href="reports_balance_sheet.php?as_of=<?php echo $dtt; ?>" class="btn btn-outline-success">
                                <i class="fas fa-file-invoice-dollar mr-2"></i>Balance Sheet
                            </a>
                            <a href="reports_income_statement.php?start_date=<?php echo $dtf; ?>&end_date=<?php echo $dtt; ?>" class="btn btn-outline-info">
                                <i class="fas fa-chart-line mr-2"></i>Income Statement
                            </a>
                            <a href="journal_entries.php?dtf=<?php echo $dtf; ?>&dtt=<?php echo $dtt; ?>" class="btn btn-outline-warning">
                                <i class="fas fa-book mr-2"></i>Journal Entries
                            </a>
                            <?php if ($account_id > 0): ?>
                            <a href="account_ledger.php?id=<?php echo $account_id; ?>" class="btn btn-outline-secondary">
                                <i class="fas fa-history mr-2"></i>Account Ledger
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
    $('.select2').select2();
});

function printReport() {
    const printContent = document.querySelector('.card').innerHTML;
    const originalContent = document.body.innerHTML;
    
    document.body.innerHTML = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>General Ledger - <?php echo date('F j, Y', strtotime($dtf)); ?> to <?php echo date('F j, Y', strtotime($dtt)); ?></title>
            <style>
                body { font-family: Arial, sans-serif; }
                .report-header { text-align: center; margin-bottom: 20px; }
                .company-name { font-size: 24px; font-weight: bold; }
                .report-title { font-size: 18px; margin: 10px 0; }
                .report-period { font-size: 14px; color: #666; }
                table { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 11px; }
                th, td { padding: 4px; text-align: left; border-bottom: 1px solid #ddd; }
                th { background-color: #f5f5f5; font-weight: bold; }
                .text-right { text-align: right; }
                .total-row { font-weight: bold; background-color: #f0f0f0; }
                @media print {
                    .no-print { display: none; }
                    body { font-size: 10px; }
                    .btn-group { display: none; }
                }
            </style>
        </head>
        <body>
            <div class="report-header">
                <div class="company-name"><?php echo htmlspecialchars($session_company_name); ?></div>
                <div class="report-title">General Ledger</div>
                <div class="report-period">
                    <?php if ($account_id > 0 && $account_details): ?>
                        Account: <?php echo htmlspecialchars($account_details['account_number'] . ' - ' . $account_details['account_name']); ?><br>
                    <?php endif; ?>
                    Period: <?php echo date('F j, Y', strtotime($dtf)); ?> to <?php echo date('F j, Y', strtotime($dtt)); ?>
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
    window.location.href = 'export_pdf.php?report=general_ledger&account_id=<?php echo $account_id; ?>&start=<?php echo $dtf; ?>&end=<?php echo $dtt; ?>';
}

function exportExcel() {
    window.location.href = 'export_excel.php?report=general_ledger&account_id=<?php echo $account_id; ?>&start=<?php echo $dtf; ?>&end=<?php echo $dtt; ?>';
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
        window.location.href = 'reports.php';
    }
    // Ctrl + T for trial balance
    if (e.ctrlKey && e.keyCode === 84) {
        e.preventDefault();
        window.location.href = 'reports_trial_balance.php?dtf=<?php echo $dtf; ?>&dtt=<?php echo $dtt; ?>';
    }
    // Ctrl + B for balance sheet
    if (e.ctrlKey && e.keyCode === 66) {
        e.preventDefault();
        window.location.href = 'reports_balance_sheet.php?as_of=<?php echo $dtt; ?>';
    }
});
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
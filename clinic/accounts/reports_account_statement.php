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

// Check if account exists
if ($account_id == 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "No account selected.";
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
$account_details = $account_stmt->get_result()->fetch_assoc();

if (!$account_details) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Account not found.";
    header("Location: accounts.php");
    exit;
}

// Get account statement data
function getAccountStatementData($mysqli, $account_id, $start_date = '', $end_date = '', $show_all = false) {
    $data = [
        'transactions' => [],
        'opening_balance' => 0,
        'total_debits' => 0,
        'total_credits' => 0,
        'closing_balance' => 0,
        'running_balance' => 0,
        'period_activity' => 0,
        'previous_period_balance' => 0
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
    
    // Get previous period balance (balance 30 days before start date)
    $prev_start_date = date('Y-m-d', strtotime($start_date . ' -30 days'));
    $prev_end_date = date('Y-m-d', strtotime($start_date . ' -1 day'));
    
    $prev_sql = "SELECT 
                    COALESCE(SUM(jel.debit_amount) - SUM(jel.credit_amount), 0) as period_activity
                 FROM journal_entry_lines jel
                 JOIN journal_entries je ON jel.journal_entry_id = je.journal_entry_id
                 WHERE jel.account_id = ?
                 AND je.status = 'posted'
                 AND je.transaction_date BETWEEN ? AND ?";
    
    $prev_stmt = $mysqli->prepare($prev_sql);
    $prev_stmt->bind_param("iss", $account_id, $prev_start_date, $prev_end_date);
    $prev_stmt->execute();
    $prev_result = $prev_stmt->get_result()->fetch_assoc();
    
    $data['previous_period_balance'] = $data['opening_balance'] - $prev_result['period_activity'];
    
    // Build WHERE clause for current period
    $where_clause = "je.status = 'posted' AND jel.account_id = ?";
    if (!empty($start_date) && !empty($end_date)) {
        $where_clause .= " AND je.transaction_date BETWEEN ? AND ?";
    }
    
    // Get transactions for current period
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
    
    if (!empty($start_date) && !empty($end_date)) {
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
    
    // Calculate period activity and closing balance
    $data['period_activity'] = $data['total_debits'] - $data['total_credits'];
    $data['closing_balance'] = $data['opening_balance'] + $data['period_activity'];
    
    return $data;
}

// Get account statement data
$statement_data = getAccountStatementData($mysqli, $account_id, $dtf, $dtt, $show_all);

// Get all accounts for dropdown (for comparison)
$accounts_sql = "SELECT account_id, account_number, account_name, account_type 
                FROM accounts 
                WHERE is_active = 1 
                ORDER BY account_type, account_number";
$accounts_result = $mysqli->query($accounts_sql);
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-file-invoice-dollar mr-2"></i>Account Statement
        </h3>
        <div class="card-tools">
            <div class="btn-group">
                <button type="button" class="btn btn-light dropdown-toggle" data-toggle="dropdown">
                    <i class="fas fa-download mr-2"></i>Export
                </button>
                <div class="dropdown-menu">
                    <a class="dropdown-item" href="#" onclick="printStatement()">
                        <i class="fas fa-print mr-2"></i>Print
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
    
    <!-- Report Controls -->
    <div class="card-body border-bottom">
        <form method="GET" class="row align-items-end">
            <input type="hidden" name="account_id" value="<?php echo $account_id; ?>">
            <div class="col-md-4">
                <div class="form-group">
                    <label class="font-weight-bold">Account</label>
                    <select class="form-control select2" name="account_id" onchange="this.form.submit()">
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
            <div class="col-md-2">
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
                    <a href="reports_account_statement.php?account_id=<?php echo $account_id; ?>" class="btn btn-outline-secondary">
                        <i class="fas fa-times mr-2"></i>Reset
                    </a>
                </div>
            </div>
        </form>
    </div>
    
    <!-- Account Information -->
    <div class="card-body border-bottom">
        <div class="row">
            <div class="col-md-8">
                <h4 class="mb-3">
                    <i class="fas fa-file-invoice-dollar text-primary mr-2"></i>
                    Account Statement: <?php echo htmlspecialchars($account_details['account_number'] . ' - ' . $account_details['account_name']); ?>
                </h4>
                <div class="row">
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
                            <tr>
                                <th class="text-muted">Parent Account:</th>
                                <td>
                                    <?php if ($account_details['parent_account_number']): ?>
                                        <?php echo htmlspecialchars($account_details['parent_account_number'] . ' - ' . $account_details['parent_account_name']); ?>
                                    <?php else: ?>
                                        <span class="text-muted">None</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless">
                            <tr>
                                <th width="40%" class="text-muted">Report Period:</th>
                                <td><?php echo date('M j, Y', strtotime($dtf)); ?> to <?php echo date('M j, Y', strtotime($dtt)); ?></td>
                            </tr>
                            <tr>
                                <th class="text-muted">Days in Period:</th>
                                <td>
                                    <?php 
                                    $days = (strtotime($dtt) - strtotime($dtf)) / (60 * 60 * 24) + 1;
                                    echo floor($days);
                                    ?> days
                                </td>
                            </tr>
                            <tr>
                                <th class="text-muted">Current Balance:</th>
                                <td class="font-weight-bold <?php echo $account_details['balance'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                    <?php echo numfmt_format_currency($currency_format, $account_details['balance'], $session_company_currency); ?>
                                </td>
                            </tr>
                            <tr>
                                <th class="text-muted">Status:</th>
                                <td>
                                    <span class="badge badge-<?php echo $account_details['is_active'] ? 'success' : 'danger'; ?>">
                                        <?php echo $account_details['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card bg-light">
                    <div class="card-header bg-transparent py-2">
                        <h5 class="card-title mb-0"><i class="fas fa-chart-line mr-2"></i>Account Summary</h5>
                    </div>
                    <div class="card-body p-3">
                        <div class="text-center mb-3">
                            <div class="h4 <?php echo $statement_data['closing_balance'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                <?php echo numfmt_format_currency($currency_format, $statement_data['closing_balance'], $session_company_currency); ?>
                            </div>
                            <small class="text-muted">Closing Balance</small>
                        </div>
                        
                        <div class="row text-center">
                            <div class="col-6">
                                <div class="text-success">
                                    <div class="h6 mb-0"><?php echo numfmt_format_currency($currency_format, $statement_data['total_debits'], $session_company_currency); ?></div>
                                    <small>Total Debits</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="text-danger">
                                    <div class="h6 mb-0"><?php echo numfmt_format_currency($currency_format, $statement_data['total_credits'], $session_company_currency); ?></div>
                                    <small>Total Credits</small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-3 text-center">
                            <div class="small text-muted">Net Activity</div>
                            <div class="h6 <?php echo $statement_data['period_activity'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                <?php echo $statement_data['period_activity'] >= 0 ? '+' : ''; ?>
                                <?php echo numfmt_format_currency($currency_format, $statement_data['period_activity'], $session_company_currency); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card-body">
        <!-- Balance Summary -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h5 class="card-title mb-0"><i class="fas fa-balance-scale mr-2"></i>Balance Summary</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3">
                                <div class="text-center p-3 border rounded bg-light">
                                    <div class="text-muted small">Previous Balance</div>
                                    <div class="h5 font-weight-bold">
                                        <?php echo numfmt_format_currency($currency_format, $statement_data['previous_period_balance'], $session_company_currency); ?>
                                    </div>
                                    <small class="text-muted">Before <?php echo date('M j, Y', strtotime($dtf)); ?></small>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-center p-3 border rounded bg-light">
                                    <div class="text-muted small">Opening Balance</div>
                                    <div class="h5 font-weight-bold text-primary">
                                        <?php echo numfmt_format_currency($currency_format, $statement_data['opening_balance'], $session_company_currency); ?>
                                    </div>
                                    <small class="text-muted">As of <?php echo date('M j, Y', strtotime($dtf)); ?></small>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-center p-3 border rounded bg-light">
                                    <div class="text-muted small">Period Activity</div>
                                    <div class="h5 font-weight-bold <?php echo $statement_data['period_activity'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo $statement_data['period_activity'] >= 0 ? '+' : ''; ?>
                                        <?php echo numfmt_format_currency($currency_format, $statement_data['period_activity'], $session_company_currency); ?>
                                    </div>
                                    <small class="text-muted">Net change</small>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-center p-3 border rounded bg-success text-white">
                                    <div class="small">Closing Balance</div>
                                    <div class="h4 font-weight-bold">
                                        <?php echo numfmt_format_currency($currency_format, $statement_data['closing_balance'], $session_company_currency); ?>
                                    </div>
                                    <small>As of <?php echo date('M j, Y', strtotime($dtt)); ?></small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Transaction Details -->
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-list-alt mr-2"></i>
                                Transaction Details
                                <span class="badge badge-secondary ml-2"><?php echo count($statement_data['transactions']); ?> entries</span>
                            </h5>
                            <div>
                                <span class="text-muted mr-3">
                                    <i class="fas fa-square text-success mr-1"></i>Debit: <?php echo numfmt_format_currency($currency_format, $statement_data['total_debits'], $session_company_currency); ?>
                                </span>
                                <span class="text-muted">
                                    <i class="fas fa-square text-danger mr-1"></i>Credit: <?php echo numfmt_format_currency($currency_format, $statement_data['total_credits'], $session_company_currency); ?>
                                </span>
                            </div>
                        </div>
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
                                        <td colspan="3" class="font-weight-bold text-primary">
                                            <i class="fas fa-arrow-circle-left mr-2"></i>Opening Balance
                                        </td>
                                        <td class="text-muted">-</td>
                                        <td colspan="2" class="text-center font-weight-bold">
                                            <?php echo numfmt_format_currency($currency_format, $statement_data['opening_balance'], $session_company_currency); ?>
                                        </td>
                                        <td class="text-right font-weight-bold text-primary">
                                            <?php echo numfmt_format_currency($currency_format, $statement_data['opening_balance'], $session_company_currency); ?>
                                        </td>
                                        <td colspan="2" class="text-muted text-center">-</td>
                                    </tr>
                                    
                                    <?php if (empty($statement_data['transactions'])): ?>
                                        <tr>
                                            <td colspan="9" class="text-center py-4">
                                                <i class="fas fa-exchange-alt fa-3x text-muted mb-3"></i>
                                                <h5 class="text-muted">No transactions in this period</h5>
                                                <p class="text-muted">
                                                    No activity for account <?php echo htmlspecialchars($account_details['account_number']); ?> 
                                                    from <?php echo date('M j, Y', strtotime($dtf)); ?> to <?php echo date('M j, Y', strtotime($dtt)); ?>.
                                                </p>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($statement_data['transactions'] as $transaction): 
                                            $balance_class = $transaction['running_balance'] >= 0 ? 'text-success' : 'text-danger';
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="font-weight-bold"><?php echo date('M j, Y', strtotime($transaction['transaction_date'])); ?></div>
                                                <small class="text-muted"><?php echo date('D', strtotime($transaction['transaction_date'])); ?></small>
                                            </td>
                                            <td class="font-weight-bold text-primary">
                                                <a href="journal_entry_view.php?id=<?php echo $transaction['journal_entry_id']; ?>" class="text-primary">
                                                    <?php echo htmlspecialchars($transaction['journal_entry_number']); ?>
                                                </a>
                                            </td>
                                            <td>
                                                <div class="font-weight-bold"><?php echo htmlspecialchars($transaction['entry_description']); ?></div>
                                                <?php if ($transaction['line_description']): ?>
                                                    <small class="text-muted"><?php echo htmlspecialchars($transaction['line_description']); ?></small>
                                                <?php endif; ?>
                                                <?php if ($transaction['reference_type']): ?>
                                                    <div class="mt-1">
                                                        <span class="badge badge-info">Ref: <?php echo htmlspecialchars($transaction['reference_type']); ?> #<?php echo $transaction['reference_id']; ?></span>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge badge-secondary"><?php echo str_replace('_', ' ', $transaction['transaction_type']); ?></span>
                                            </td>
                                            <td class="text-right <?php echo $transaction['debit_amount'] > 0 ? 'text-success font-weight-bold' : 'text-muted'; ?>">
                                                <?php if ($transaction['debit_amount'] > 0): ?>
                                                    <i class="fas fa-arrow-up text-success mr-1"></i>
                                                    <?php echo numfmt_format_currency($currency_format, $transaction['debit_amount'], $session_company_currency); ?>
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-right <?php echo $transaction['credit_amount'] > 0 ? 'text-danger font-weight-bold' : 'text-muted'; ?>">
                                                <?php if ($transaction['credit_amount'] > 0): ?>
                                                    <i class="fas fa-arrow-down text-danger mr-1"></i>
                                                    <?php echo numfmt_format_currency($currency_format, $transaction['credit_amount'], $session_company_currency); ?>
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-right font-weight-bold <?php echo $balance_class; ?>">
                                                <?php echo numfmt_format_currency($currency_format, $transaction['running_balance'], $session_company_currency); ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($transaction['created_by']); ?></td>
                                            <td class="text-center">
                                                <div class="btn-group btn-group-sm">
                                                    <a href="journal_entry_view.php?id=<?php echo $transaction['journal_entry_id']; ?>" class="btn btn-info" title="View Entry">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <?php if ($transaction['reference_type'] && $transaction['reference_id']): ?>
                                                        <a href="<?php echo getReferenceLink($transaction['reference_type'], $transaction['reference_id']); ?>" class="btn btn-warning" title="View Source">
                                                            <i class="fas fa-external-link-alt"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                        
                                        <!-- Closing Balance Row -->
                                        <tr class="bg-primary text-white">
                                            <td colspan="4" class="font-weight-bold">
                                                <i class="fas fa-flag-checkered mr-2"></i>Closing Balance
                                            </td>
                                            <td class="text-right font-weight-bold">
                                                <?php echo numfmt_format_currency($currency_format, $statement_data['total_debits'], $session_company_currency); ?>
                                            </td>
                                            <td class="text-right font-weight-bold">
                                                <?php echo numfmt_format_currency($currency_format, $statement_data['total_credits'], $session_company_currency); ?>
                                            </td>
                                            <td colspan="3" class="text-right h4 font-weight-bold">
                                                <?php echo numfmt_format_currency($currency_format, $statement_data['closing_balance'], $session_company_currency); ?>
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
        
        <!-- Statistics -->
        <div class="row mt-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h5 class="card-title mb-0"><i class="fas fa-chart-bar mr-2"></i>Transaction Statistics</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="info-box bg-light">
                                    <span class="info-box-icon bg-success"><i class="fas fa-arrow-up"></i></span>
                                    <div class="info-box-content">
                                        <span class="info-box-text">Debit Entries</span>
                                        <span class="info-box-number">
                                            <?php 
                                            $debit_entries = count(array_filter($statement_data['transactions'], function($t) { 
                                                return $t['debit_amount'] > 0; 
                                            }));
                                            echo $debit_entries;
                                            ?>
                                            <small class="text-muted ml-2">
                                                (<?php echo numfmt_format_currency($currency_format, $statement_data['total_debits'], $session_company_currency); ?>)
                                            </small>
                                        </span>
                                        <div class="progress">
                                            <div class="progress-bar bg-success" style="width: <?php echo count($statement_data['transactions']) > 0 ? ($debit_entries / count($statement_data['transactions']) * 100) : 0; ?>%"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="info-box bg-light">
                                    <span class="info-box-icon bg-danger"><i class="fas fa-arrow-down"></i></span>
                                    <div class="info-box-content">
                                        <span class="info-box-text">Credit Entries</span>
                                        <span class="info-box-number">
                                            <?php 
                                            $credit_entries = count(array_filter($statement_data['transactions'], function($t) { 
                                                return $t['credit_amount'] > 0; 
                                            }));
                                            echo $credit_entries;
                                            ?>
                                            <small class="text-muted ml-2">
                                                (<?php echo numfmt_format_currency($currency_format, $statement_data['total_credits'], $session_company_currency); ?>)
                                            </small>
                                        </span>
                                        <div class="progress">
                                            <div class="progress-bar bg-danger" style="width: <?php echo count($statement_data['transactions']) > 0 ? ($credit_entries / count($statement_data['transactions']) * 100) : 0; ?>%"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="info-box bg-light">
                                    <span class="info-box-icon bg-info"><i class="fas fa-exchange-alt"></i></span>
                                    <div class="info-box-content">
                                        <span class="info-box-text">Average Transaction</span>
                                        <span class="info-box-number">
                                            <?php 
                                            $avg_transaction = count($statement_data['transactions']) > 0 ? 
                                                ($statement_data['total_debits'] + $statement_data['total_credits']) / count($statement_data['transactions']) : 0;
                                            echo numfmt_format_currency($currency_format, $avg_transaction, $session_company_currency);
                                            ?>
                                        </span>
                                        <div class="progress">
                                            <div class="progress-bar bg-info" style="width: 100%"></div>
                                        </div>
                                        <small class="text-muted">
                                            <?php echo count($statement_data['transactions']); ?> total transactions
                                        </small>
                                    </div>
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
                        <h5 class="card-title mb-0"><i class="fas fa-bolt mr-2"></i>Quick Actions</h5>
                    </div>
                    <div class="card-body">
                        <div class="btn-group">
                            <a href="accounts_edit.php?id=<?php echo $account_id; ?>" class="btn btn-outline-primary">
                                <i class="fas fa-edit mr-2"></i>Edit Account
                            </a>
                            <a href="reports_general_ledger.php?account_id=<?php echo $account_id; ?>&dtf=<?php echo $dtf; ?>&dtt=<?php echo $dtt; ?>" class="btn btn-outline-success">
                                <i class="fas fa-book mr-2"></i>General Ledger
                            </a>
                            <a href="journal_entries.php?account_id=<?php echo $account_id; ?>&dtf=<?php echo $dtf; ?>&dtt=<?php echo $dtt; ?>" class="btn btn-outline-info">
                                <i class="fas fa-list-alt mr-2"></i>Journal Entries
                            </a>
                            <a href="reports_trial_balance.php?dtf=<?php echo $dtf; ?>&dtt=<?php echo $dtt; ?>" class="btn btn-outline-warning">
                                <i class="fas fa-balance-scale mr-2"></i>Trial Balance
                            </a>
                            <a href="#" class="btn btn-outline-danger" onclick="return confirm('Are you sure you want to deactivate this account?')">
                                <i class="fas fa-ban mr-2"></i>Deactivate Account
                            </a>
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
    
    // Initialize tooltips
    $('[data-toggle="tooltip"]').tooltip();
});

function printStatement() {
    const printContent = document.querySelector('.card').innerHTML;
    const originalContent = document.body.innerHTML;
    
    document.body.innerHTML = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>Account Statement - <?php echo htmlspecialchars($account_details['account_number'] . ' - ' . $account_details['account_name']); ?></title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                .statement-header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #333; padding-bottom: 10px; }
                .company-name { font-size: 24px; font-weight: bold; }
                .statement-title { font-size: 18px; margin: 10px 0; }
                .account-info { margin-bottom: 20px; }
                .balance-summary { margin: 20px 0; }
                .balance-box { border: 1px solid #ddd; padding: 10px; text-align: center; margin: 5px; }
                table { width: 100%; border-collapse: collapse; margin: 20px 0; font-size: 11px; }
                th, td { padding: 6px; text-align: left; border-bottom: 1px solid #ddd; }
                th { background-color: #f5f5f5; font-weight: bold; }
                .text-right { text-align: right; }
                .text-center { text-align: center; }
                .opening-row { background-color: #f0f0f0; }
                .closing-row { background-color: #333; color: white; }
                @media print {
                    .no-print { display: none; }
                    body { font-size: 10px; margin: 0; }
                    .btn-group, .card-tools { display: none; }
                }
                @page { margin: 0.5in; }
            </style>
        </head>
        <body>
            <div class="statement-header">
                <div class="company-name"><?php echo htmlspecialchars($session_company_name); ?></div>
                <div class="statement-title">Account Statement</div>
                <div>Period: <?php echo date('F j, Y', strtotime($dtf)); ?> to <?php echo date('F j, Y', strtotime($dtt)); ?></div>
                <div>Account: <?php echo htmlspecialchars($account_details['account_number'] . ' - ' . $account_details['account_name']); ?></div>
                <div>Generated: <?php echo date('F j, Y H:i'); ?></div>
            </div>
            
            <div class="account-info">
                <strong>Account Details:</strong><br>
                Type: <?php echo ucfirst($account_details['account_type']); ?> | 
                Normal Balance: <?php echo ucfirst($account_details['normal_balance']); ?> | 
                Status: <?php echo $account_details['is_active'] ? 'Active' : 'Inactive'; ?>
            </div>
            
            <div class="balance-summary">
                <div style="display: flex; justify-content: space-between;">
                    <div class="balance-box" style="flex: 1;">
                        <div style="font-size: 12px; color: #666;">Opening Balance</div>
                        <div style="font-size: 16px; font-weight: bold;"><?php echo numfmt_format_currency($currency_format, $statement_data['opening_balance'], $session_company_currency); ?></div>
                        <div style="font-size: 10px;"><?php echo date('M j, Y', strtotime($dtf)); ?></div>
                    </div>
                    <div class="balance-box" style="flex: 1;">
                        <div style="font-size: 12px; color: #666;">Period Activity</div>
                        <div style="font-size: 16px; font-weight: bold; color: <?php echo $statement_data['period_activity'] >= 0 ? 'green' : 'red'; ?>;">
                            <?php echo $statement_data['period_activity'] >= 0 ? '+' : ''; ?>
                            <?php echo numfmt_format_currency($currency_format, $statement_data['period_activity'], $session_company_currency); ?>
                        </div>
                        <div style="font-size: 10px;">Net change</div>
                    </div>
                    <div class="balance-box" style="flex: 1; background-color: #f0f8ff;">
                        <div style="font-size: 12px; color: #666;">Closing Balance</div>
                        <div style="font-size: 18px; font-weight: bold; color: blue;">
                            <?php echo numfmt_format_currency($currency_format, $statement_data['closing_balance'], $session_company_currency); ?>
                        </div>
                        <div style="font-size: 10px;"><?php echo date('M j, Y', strtotime($dtt)); ?></div>
                    </div>
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
    window.location.href = 'export_pdf.php?report=account_statement&account_id=<?php echo $account_id; ?>&start=<?php echo $dtf; ?>&end=<?php echo $dtt; ?>';
}

function exportExcel() {
    window.location.href = 'export_excel.php?report=account_statement&account_id=<?php echo $account_id; ?>&start=<?php echo $dtf; ?>&end=<?php echo $dtt; ?>';
}

// Helper function to get reference links (you'll need to implement this based on your system)
function getReferenceLink(reference_type, reference_id) {
    switch(reference_type) {
        case 'invoice':
            return 'invoice.php?id=' + reference_id;
        case 'payment':
            return 'payment.php?id=' + reference_id;
        case 'adjustment':
            return 'adjustment.php?id=' + reference_id;
        case 'refund':
            return 'refund.php?id=' + reference_id;
        default:
            return '#';
    }
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + P to print
    if (e.ctrlKey && e.keyCode === 80) {
        e.preventDefault();
        printStatement();
    }
    // Escape to go back
    if (e.keyCode === 27) {
        window.location.href = 'accounts.php';
    }
    // Ctrl + G for general ledger
    if (e.ctrlKey && e.keyCode === 71) {
        e.preventDefault();
        window.location.href = 'reports_general_ledger.php?account_id=<?php echo $account_id; ?>&dtf=<?php echo $dtf; ?>&dtt=<?php echo $dtt; ?>';
    }
    // Ctrl + J for journal entries
    if (e.ctrlKey && e.keyCode === 74) {
        e.preventDefault();
        window.location.href = 'journal_entries.php?account_id=<?php echo $account_id; ?>&dtf=<?php echo $dtf; ?>&dtt=<?php echo $dtt; ?>';
    }
});
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
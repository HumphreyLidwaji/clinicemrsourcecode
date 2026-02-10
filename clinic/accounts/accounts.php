<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Default Column Sortby/Order Filter
$sort = "a.account_number";
$order = "ASC";

// Filter parameters
$type_filter = $_GET['type'] ?? '';
$class_filter = $_GET['class'] ?? '';
$status_filter = $_GET['status'] ?? 'active';

// Date Range Filter
$dtf = sanitizeInput($_GET['dtf'] ?? '');
$dtt = sanitizeInput($_GET['dtt'] ?? '');

// Set default date range if not provided
if (empty($dtf)) {
    $dtf = date('Y-m-01'); // First day of current month
}
if (empty($dtt)) {
    $dtt = date('Y-m-t'); // Last day of current month
}

// Check if journal_entry_lines table exists
$check_table_sql = "SHOW TABLES LIKE 'journal_entry_lines'";
$table_exists = $mysqli->query($check_table_sql)->num_rows > 0;

if ($table_exists && !empty($dtf) && !empty($dtt)) {
    $date_query = "AND DATE(je.transaction_date) BETWEEN '$dtf' AND '$dtt'";
} else {
    $date_query = '';
}

// Class Filter (using account_type directly from accounts table)
if ($class_filter) {
    $class_query = "AND a.account_type = '" . sanitizeInput($class_filter) . "'";
} else {
    $class_query = '';
}

// Type Filter (using account_subtype)
if ($type_filter) {
    $type_query = "AND a.account_subtype = '" . sanitizeInput($type_filter) . "'";
} else {
    $type_query = '';
}

// Status Filter
if ($status_filter) {
    $status_query = "AND a.is_active = " . ($status_filter == 'active' ? 1 : 0);
} else {
    $status_query = "";
}

// Search Query
$q = sanitizeInput($_GET['q'] ?? '');
if (!empty($q)) {
    $search_query = "AND (
        a.account_name LIKE '%$q%' 
        OR a.account_number LIKE '%$q%'
        OR a.description LIKE '%$q%'
    )";
} else {
    $search_query = '';
}

// Get statistics
$stats_sql = "SELECT 
    COUNT(*) as total_accounts,
    SUM(CASE WHEN a.account_type = 'asset' THEN 1 ELSE 0 END) as asset_accounts,
    SUM(CASE WHEN a.account_type = 'liability' THEN 1 ELSE 0 END) as liability_accounts,
    SUM(CASE WHEN a.account_type = 'equity' THEN 1 ELSE 0 END) as equity_accounts,
    SUM(CASE WHEN a.account_type = 'revenue' THEN 1 ELSE 0 END) as revenue_accounts,
    SUM(CASE WHEN a.account_type = 'expense' THEN 1 ELSE 0 END) as expense_accounts,
    SUM(CASE WHEN a.is_active = 1 THEN 1 ELSE 0 END) as active_accounts,
    SUM(CASE WHEN a.is_active = 0 THEN 1 ELSE 0 END) as inactive_accounts,
    SUM(CASE WHEN a.normal_balance = 'debit' THEN a.balance ELSE 0 END) as total_debit_balance,
    SUM(CASE WHEN a.normal_balance = 'credit' THEN a.balance ELSE 0 END) as total_credit_balance
FROM accounts a
WHERE 1=1
$status_query
$class_query
$type_query
$search_query";

$stats_result = $mysqli->query($stats_sql);
$stats = $stats_result->fetch_assoc();

// Get period activity statistics
if ($table_exists) {
    $activity_sql = "SELECT 
        COUNT(DISTINCT je.journal_entry_id) as total_transactions,
        COUNT(DISTINCT jel.account_id) as active_accounts_period,
        COALESCE(SUM(jel.debit_amount), 0) as period_debits_total,
        COALESCE(SUM(jel.credit_amount), 0) as period_credits_total
    FROM journal_entries je
    LEFT JOIN journal_entry_lines jel ON je.journal_entry_id = jel.journal_entry_id
    WHERE je.status = 'posted'
    $date_query";
    
    $activity_result = $mysqli->query($activity_sql);
    $activity_stats = $activity_result->fetch_assoc();
} else {
    $activity_stats = [
        'total_transactions' => 0,
        'active_accounts_period' => 0,
        'period_debits_total' => 0,
        'period_credits_total' => 0
    ];
}

// Get financial summary
$financial_summary_sql = "SELECT 
    SUM(CASE WHEN a.account_type = 'asset' THEN a.balance ELSE 0 END) as total_assets,
    SUM(CASE WHEN a.account_type = 'liability' THEN a.balance ELSE 0 END) as total_liabilities,
    SUM(CASE WHEN a.account_type = 'equity' THEN a.balance ELSE 0 END) as total_equity,
    SUM(CASE WHEN a.account_type = 'revenue' THEN a.balance ELSE 0 END) as total_revenue,
    SUM(CASE WHEN a.account_type = 'expense' THEN a.balance ELSE 0 END) as total_expenses
FROM accounts a
WHERE a.is_active = 1";

$financial_summary_result = $mysqli->query($financial_summary_sql);
$financial_summary = $financial_summary_result->fetch_assoc();

// Calculate metrics
$total_assets = $financial_summary['total_assets'] ?? 0;
$total_liabilities = $financial_summary['total_liabilities'] ?? 0;
$total_equity = $financial_summary['total_equity'] ?? 0;
$net_income = ($financial_summary['total_revenue'] ?? 0) - ($financial_summary['total_expenses'] ?? 0);
$accounting_equation = $total_assets - ($total_liabilities + $total_equity);

// Get account defaults
$defaults_sql = "SELECT ad.*, a.account_name, a.account_number 
                FROM account_defaults ad 
                LEFT JOIN accounts a ON ad.account_id = a.account_id 
                ORDER BY ad.default_type";
$defaults_result = $mysqli->query($defaults_sql);
$default_accounts = [];
while ($default = $defaults_result->fetch_assoc()) {
    $default_accounts[$default['default_type']] = $default;
}

// Main query for accounts
$sql = mysqli_query(
    $mysqli,
    "
    SELECT SQL_CALC_FOUND_ROWS a.*,
           parent.account_name as parent_account_name,
           parent.account_number as parent_account_number,
           
           -- Get period activity if tables exist
           IF($table_exists, 
               (SELECT COUNT(*) FROM journal_entry_lines jel
                JOIN journal_entries je ON jel.journal_entry_id = je.journal_entry_id
                WHERE jel.account_id = a.account_id 
                AND je.status = 'posted'
                $date_query), 0) as transaction_count,
           
           IF($table_exists,
               (SELECT COALESCE(SUM(jel.debit_amount), 0) FROM journal_entry_lines jel
                JOIN journal_entries je ON jel.journal_entry_id = je.journal_entry_id
                WHERE jel.account_id = a.account_id 
                AND je.status = 'posted'
                $date_query), 0) as period_debits,
           
           IF($table_exists,
               (SELECT COALESCE(SUM(jel.credit_amount), 0) FROM journal_entry_lines jel
                JOIN journal_entries je ON jel.journal_entry_id = je.journal_entry_id
                WHERE jel.account_id = a.account_id 
                AND je.status = 'posted'
                $date_query), 0) as period_credits
           
    FROM accounts a 
    LEFT JOIN accounts parent ON a.parent_account_id = parent.account_id
    
    WHERE 1=1
      $status_query
      $class_query
      $type_query
      $search_query
    ORDER BY $sort $order
    LIMIT $record_from, $record_to
");

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get recent journal entries
if ($table_exists) {
    $recent_entries_sql = "SELECT 
        je.journal_entry_id, je.journal_entry_number, je.transaction_date, 
        je.description as entry_description, je.transaction_type,
        je.total_debit, je.total_credit, je.status,
        u.user_name as created_by,
        COUNT(jel.journal_entry_line_id) as line_count
    FROM journal_entries je
    LEFT JOIN users u ON je.created_by = u.user_id
    LEFT JOIN journal_entry_lines jel ON je.journal_entry_id = jel.journal_entry_id
    WHERE je.status = 'posted'
    GROUP BY je.journal_entry_id
    ORDER BY je.transaction_date DESC, je.journal_entry_id DESC 
    LIMIT 10";
    $recent_entries = $mysqli->query($recent_entries_sql);
} else {
    $recent_entries = (object)['num_rows' => 0];
}

// Get account subtypes for filter
$subtypes_sql = "SELECT DISTINCT account_subtype FROM accounts WHERE account_subtype IS NOT NULL AND account_subtype != '' ORDER BY account_subtype";
$subtypes_result = $mysqli->query($subtypes_sql);

?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2 mb-0 text-white"><i class="fas fa-fw fa-balance-scale mr-2"></i>Accounting Dashboard</h3>
        <div class="card-tools">
            <div class="btn-group">
                <a href="account_new.php" class="btn btn-success">
                    <i class="fas fa-plus mr-2"></i>New Account
                </a>
                
                <?php if ($table_exists): ?>
                    <a href="journal_entry_new.php" class="btn btn-primary ml-2">
                        <i class="fas fa-book mr-2"></i>Journal Entry
                    </a>
                <?php endif; ?>
                
                <a href="reports_balance_sheet.php" class="btn btn-info ml-2">
                    <i class="fas fa-file-invoice-dollar mr-2"></i>Balance Sheet
                </a>
                
                <a href="reports_income_statement.php" class="btn btn-warning ml-2">
                    <i class="fas fa-chart-line mr-2"></i>Income Statement
                </a>
                
                <?php if (!$table_exists): ?>
                    <a href="setup_accounting.php" class="btn btn-danger ml-2">
                        <i class="fas fa-cog mr-2"></i>Setup Accounting
                    </a>
                <?php endif; ?>
                
                <div class="btn-group ml-2">
                    <button type="button" class="btn btn-secondary dropdown-toggle" data-toggle="dropdown">
                        <i class="fas fa-tasks mr-2"></i>Quick Actions
                    </button>
                    <div class="dropdown-menu">
                        <a class="dropdown-item" href="account_defaults.php">
                            <i class="fas fa-cog mr-2"></i>Manage Defaults
                        </a>
                        <?php if ($table_exists): ?>
                            <a class="dropdown-item" href="journal_entries.php">
                                <i class="fas fa-history mr-2"></i>Journal History
                            </a>
                            <a class="dropdown-item" href="reports_general_ledger.php">
                                <i class="fas fa-book mr-2"></i>General Ledger
                            </a>
                            <a class="dropdown-item" href="reports_trial_balance.php">
                                <i class="fas fa-balance-scale-left mr-2"></i>Trial Balance
                            </a>
                        <?php endif; ?>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item" href="account_import.php">
                            <i class="fas fa-file-import mr-2"></i>Import Accounts
                        </a>
                        <a class="dropdown-item" href="account_export.php">
                            <i class="fas fa-file-export mr-2"></i>Export Accounts
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <?php if (!$table_exists): ?>
    <div class="row mt-3">
        <div class="col-12">
            <div class="alert alert-danger alert-dismissible fade show m-3" role="alert">
                <i class="fas fa-exclamation-triangle mr-2"></i>
                <strong>Accounting system not fully setup.</strong> The journal entry system needs to be initialized. 
                <a href="setup_accounting.php" class="alert-link ml-2">Run Accounting Setup</a>
                <button type="button" class="close" data-dismiss="alert">
                    <span>&times;</span>
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Alert Row -->
    <?php if (abs($accounting_equation) > 0.01): ?>
    <div class="row mt-3">
        <div class="col-12">
            <div class="alert-container">
                <div class="alert alert-danger alert-dismissible fade show mb-2" role="alert">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    <strong>Accounting Equation Out of Balance!</strong> 
                    Assets <?php echo numfmt_format_currency($currency_format, $total_assets, $session_company_currency); ?> ≠ 
                    Liabilities + Equity <?php echo numfmt_format_currency($currency_format, $total_liabilities + $total_equity, $session_company_currency); ?>
                    <span class="font-weight-bold ml-2">Difference: <?php echo numfmt_format_currency($currency_format, $accounting_equation, $session_company_currency); ?></span>
                    <button type="button" class="close" data-dismiss="alert">
                        <span>&times;</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Statistics Row -->
    <div class="card-body border-bottom">
        <div class="row">
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-primary"><i class="fas fa-wallet"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Assets</span>
                        <span class="info-box-number"><?php echo numfmt_format_currency($currency_format, $total_assets, $session_company_currency); ?></span>
                        <small class="text-muted"><?php echo $stats['asset_accounts'] ?? 0; ?> accounts</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-warning"><i class="fas fa-hand-holding-usd"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Liabilities</span>
                        <span class="info-box-number"><?php echo numfmt_format_currency($currency_format, $total_liabilities, $session_company_currency); ?></span>
                        <small class="text-muted"><?php echo $stats['liability_accounts'] ?? 0; ?> accounts</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-success"><i class="fas fa-money-bill-wave"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Net Income</span>
                        <span class="info-box-number"><?php echo numfmt_format_currency($currency_format, $net_income, $session_company_currency); ?></span>
                        <small class="text-muted">Period activity</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon <?php echo abs($accounting_equation) < 0.01 ? 'bg-success' : 'bg-danger'; ?>">
                        <i class="fas fa-balance-scale"></i>
                    </span>
                    <div class="info-box-content">
                        <span class="info-box-text">Balance Check</span>
                        <span class="info-box-number"><?php echo numfmt_format_currency($currency_format, $accounting_equation, $session_company_currency); ?></span>
                        <small class="text-muted">Assets = Liabilities + Equity</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-info"><i class="fas fa-exchange-alt"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Period Activity</span>
                        <span class="info-box-number"><?php echo $activity_stats['total_transactions'] ?? 0; ?></span>
                        <small class="text-muted"><?php echo $activity_stats['active_accounts_period'] ?? 0; ?> accounts active</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-secondary"><i class="fas fa-chart-pie"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Accounts</span>
                        <span class="info-box-number"><?php echo $stats['total_accounts'] ?? 0; ?></span>
                        <small class="text-muted"><?php echo $stats['active_accounts'] ?? 0; ?> active</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Account Defaults Summary -->
    <?php if (!empty($default_accounts)): ?>
    <div class="card-body border-bottom">
        <h5 class="mb-3"><i class="fas fa-cog text-primary mr-2"></i>Account Defaults</h5>
        <div class="row">
            <?php 
            $default_types = [
                'cash' => ['icon' => 'fa-money-bill-wave', 'color' => 'success'],
                'accounts_receivable' => ['icon' => 'fa-hand-holding-usd', 'color' => 'info'],
                'accounts_payable' => ['icon' => 'fa-file-invoice-dollar', 'color' => 'warning'],
                'sales_revenue' => ['icon' => 'fa-chart-line', 'color' => 'success'],
                'cost_of_goods_sold' => ['icon' => 'fa-boxes', 'color' => 'danger'],
                'inventory' => ['icon' => 'fa-warehouse', 'color' => 'primary'],
                'retained_earnings' => ['icon' => 'fa-piggy-bank', 'color' => 'info'],
                'bank_charges' => ['icon' => 'fa-credit-card', 'color' => 'secondary'],
                'depreciation' => ['icon' => 'fa-chart-bar', 'color' => 'warning']
            ];
            
            $count = 0;
            foreach ($default_accounts as $type => $default): 
                if ($count % 4 == 0 && $count > 0) echo '</div><div class="row">'; 
                $type_info = $default_types[$type] ?? ['icon' => 'fa-cog', 'color' => 'secondary'];
            ?>
            <div class="col-md-3">
                <div class="card card-sm mb-2">
                    <div class="card-body p-2">
                        <div class="d-flex align-items-center">
                            <div class="mr-3">
                                <span class="badge badge-<?php echo $type_info['color']; ?> badge-pill p-2">
                                    <i class="fas <?php echo $type_info['icon']; ?>"></i>
                                </span>
                            </div>
                            <div class="flex-grow-1">
                                <div class="font-weight-bold text-truncate"><?php echo ucwords(str_replace('_', ' ', $type)); ?></div>
                                <small class="text-muted"><?php echo $default['account_number']; ?>: <?php echo $default['account_name']; ?></small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php $count++; endforeach; ?>
        </div>
        <div class="text-center mt-2">
            <a href="account_defaults.php" class="btn btn-outline-primary btn-sm">
                <i class="fas fa-cog mr-2"></i>Manage Defaults
            </a>
        </div>
    </div>
    <?php endif; ?>

    <div class="card-header pb-2 pt-3">
        <form autocomplete="off">
            <div class="row">
                <div class="col-md-5">
                    <div class="form-group">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search account name, number, description..." autofocus>
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
                            <span class="btn btn-light border" data-toggle="tooltip" title="Total Accounts">
                                <i class="fas fa-wallet text-dark mr-1"></i>
                                <strong><?php echo $stats['total_accounts'] ?? 0; ?></strong>
                            </span>
                            <span class="btn btn-light border" data-toggle="tooltip" title="Active Accounts">
                                <i class="fas fa-check-circle text-success mr-1"></i>
                                <strong><?php echo $stats['active_accounts'] ?? 0; ?></strong>
                            </span>
                            <span class="btn btn-light border" data-toggle="tooltip" title="Inactive Accounts">
                                <i class="fas fa-pause-circle text-warning mr-1"></i>
                                <strong><?php echo $stats['inactive_accounts'] ?? 0; ?></strong>
                            </span>
                            <span class="btn btn-light border" data-toggle="tooltip" title="Period Transactions">
                                <i class="fas fa-exchange-alt text-info mr-1"></i>
                                <strong><?php echo $activity_stats['total_transactions'] ?? 0; ?></strong>
                            </span>
                            <?php if ($table_exists): ?>
                                <a href="journal_entry_new.php" class="btn btn-warning ml-2">
                                    <i class="fas fa-fw fa-book mr-2"></i>Journal Entry
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php if ($class_filter || $type_filter || $status_filter || !empty($dtf) || !empty($dtt)) { echo "show"; } ?>" id="advancedFilter">
                <div class="row">
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Date Range</label>
                            <select class="form-control select2" name="canned_date" onchange="handleDateSelection(this)">
                                <option value="custom" <?php if (($_GET['canned_date'] ?? '') == "custom") { echo "selected"; } ?>>Custom</option>
                                <option value="today" <?php if (($_GET['canned_date'] ?? '') == "today") { echo "selected"; } ?>>Today</option>
                                <option value="yesterday" <?php if (($_GET['canned_date'] ?? '') == "yesterday") { echo "selected"; } ?>>Yesterday</option>
                                <option value="thisweek" <?php if (($_GET['canned_date'] ?? '') == "thisweek") { echo "selected"; } ?>>This Week</option>
                                <option value="lastweek" <?php if (($_GET['canned_date'] ?? '') == "lastweek") { echo "selected"; } ?>>Last Week</option>
                                <option value="thismonth" <?php if (($_GET['canned_date'] ?? '') == "thismonth") { echo "selected"; } ?>>This Month</option>
                                <option value="lastmonth" <?php if (($_GET['canned_date'] ?? '') == "lastmonth") { echo "selected"; } ?>>Last Month</option>
                                <option value="thisquarter" <?php if (($_GET['canned_date'] ?? '') == "thisquarter") { echo "selected"; } ?>>This Quarter</option>
                                <option value="thisyear" <?php if (($_GET['canned_date'] ?? '') == "thisyear") { echo "selected"; } ?>>This Year</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Date from</label>
                            <input type="date" class="form-control" name="dtf" max="2999-12-31" value="<?php echo nullable_htmlentities($dtf); ?>">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Date to</label>
                            <input type="date" class="form-control" name="dtt" max="2999-12-31" value="<?php echo nullable_htmlentities($dtt); ?>">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Account Type</label>
                            <select class="form-control select2" name="class" onchange="this.form.submit()">
                                <option value="">- All Types -</option>
                                <option value="asset" <?php if ($class_filter == "asset") { echo "selected"; } ?>>Assets</option>
                                <option value="liability" <?php if ($class_filter == "liability") { echo "selected"; } ?>>Liabilities</option>
                                <option value="equity" <?php if ($class_filter == "equity") { echo "selected"; } ?>>Equity</option>
                                <option value="revenue" <?php if ($class_filter == "revenue") { echo "selected"; } ?>>Revenue</option>
                                <option value="expense" <?php if ($class_filter == "expense") { echo "selected"; } ?>>Expenses</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Quick Actions</label>
                            <div class="btn-group btn-block">
                                <a href="accounts.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times mr-2"></i>Clear Filters
                                </a>
                                <a href="account_new.php" class="btn btn-success">
                                    <i class="fas fa-plus mr-2"></i>Add Account
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row mt-2">
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Account Subtype</label>
                            <select class="form-control select2" name="type" onchange="this.form.submit()">
                                <option value="">- All Subtypes -</option>
                                <?php while($subtype = $subtypes_result->fetch_assoc()): ?>
                                    <option value="<?php echo htmlspecialchars($subtype['account_subtype']); ?>" <?php if ($type_filter == $subtype['account_subtype']) { echo "selected"; } ?>>
                                        <?php echo htmlspecialchars($subtype['account_subtype']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Status</label>
                            <select class="form-control select2" name="status" onchange="this.form.submit()">
                                <option value="">- All Status -</option>
                                <option value="active" <?php if ($status_filter == "active") { echo "selected"; } ?>>Active</option>
                                <option value="inactive" <?php if ($status_filter == "inactive") { echo "selected"; } ?>>Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Quick Filters</label>
                            <div class="btn-group btn-group-toggle" data-toggle="buttons">
                                <a href="?status=inactive" class="btn btn-outline-warning btn-sm <?php echo $status_filter == 'inactive' ? 'active' : ''; ?>">
                                    <i class="fas fa-pause-circle mr-1"></i> Inactive Accounts
                                </a>
                                <a href="?class=expense" class="btn btn-outline-danger btn-sm <?php echo $class_filter == 'expense' ? 'active' : ''; ?>">
                                    <i class="fas fa-money-bill-wave mr-1"></i> Expense Accounts
                                </a>
                                <a href="?class=revenue" class="btn btn-outline-success btn-sm <?php echo $class_filter == 'revenue' ? 'active' : ''; ?>">
                                    <i class="fas fa-chart-line mr-1"></i> Revenue Accounts
                                </a>
                                <?php if ($table_exists): ?>
                                    <a href="?dtf=<?php echo date('Y-m-01'); ?>&dtt=<?php echo date('Y-m-d'); ?>" class="btn btn-outline-info btn-sm <?php echo !empty($dtf) ? 'active' : ''; ?>">
                                        <i class="fas fa-calendar-alt mr-1"></i> This Month Activity
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
    
    <div class="table-responsive-sm">
        <table class="table table-hover mb-0">
            <thead class="<?php if ($num_rows[0] == 0) { echo "d-none"; } ?> bg-light">
            <tr>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=a.account_number&order=<?php echo $disp; ?>">
                        Account # <?php if ($sort == 'a.account_number') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=a.account_name&order=<?php echo $disp; ?>">
                        Account Name <?php if ($sort == 'a.account_name') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=a.account_type&order=<?php echo $disp; ?>">
                        Type <?php if ($sort == 'a.account_type') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=a.account_subtype&order=<?php echo $disp; ?>">
                        Subtype <?php if ($sort == 'a.account_subtype') { echo $order_icon; } ?>
                    </a>
                </th>
                <th class="text-right">
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=a.balance&order=<?php echo $disp; ?>">
                        Current Balance <?php if ($sort == 'a.balance') { echo $order_icon; } ?>
                    </a>
                </th>
                <th class="text-center">
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=transaction_count&order=<?php echo $disp; ?>">
                        Period Activity <?php if ($sort == 'transaction_count') { echo $order_icon; } ?>
                    </a>
                </th>
                <th class="text-center">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php
            if ($num_rows[0] == 0) {
                ?>
                <tr>
                    <td colspan="7" class="text-center py-5">
                        <i class="fas fa-wallet fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No accounts found</h5>
                        <p class="text-muted">
                            <?php 
                            if ($q || $class_filter || $type_filter || $status_filter) {
                                echo "Try adjusting your search or filter criteria.";
                            } else {
                                echo "Get started by adding your first accounting account.";
                            }
                            ?>
                        </p>
                        <a href="account_new.php" class="btn btn-primary">
                            <i class="fas fa-plus mr-2"></i>Add First Account
                        </a>
                        <?php if ($q || $class_filter || $type_filter || $status_filter): ?>
                            <a href="accounts.php" class="btn btn-secondary ml-2">
                                <i class="fas fa-times mr-2"></i>Clear Filters
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php
            } else {
                while ($row = mysqli_fetch_array($sql)) {
                    $account_id = intval($row['account_id']);
                    $account_number = nullable_htmlentities($row['account_number']);
                    $account_name = nullable_htmlentities($row['account_name']);
                    $account_description = nullable_htmlentities($row['description']);
                    $account_type = nullable_htmlentities($row['account_type']);
                    $account_subtype = nullable_htmlentities($row['account_subtype']);
                    $parent_account_id = intval($row['parent_account_id'] ?? 0);
                    $parent_account_name = nullable_htmlentities($row['parent_account_name']);
                    $parent_account_number = nullable_htmlentities($row['parent_account_number']);
                    $normal_balance = nullable_htmlentities($row['normal_balance']);
                    $current_balance = floatval($row['balance']);
                    $transaction_count = intval($row['transaction_count'] ?? 0);
                    $period_debits = floatval($row['period_debits'] ?? 0);
                    $period_credits = floatval($row['period_credits'] ?? 0);
                    $is_active = $row['is_active'];

                    // Check if this account is used as a default
                    $default_check_sql = "SELECT default_type FROM account_defaults WHERE account_id = $account_id";
                    $default_result = $mysqli->query($default_check_sql);
                    $is_default_account = $default_result->num_rows > 0;
                    $default_types = [];
                    while ($default_row = $default_result->fetch_assoc()) {
                        $default_types[] = $default_row['default_type'];
                    }

                    // Type badge styling
                    $type_badge = "";
                    $type_icon = "";
                    switch($account_type) {
                        case 'asset':
                            $type_badge = "badge-success";
                            $type_icon = "fa-wallet";
                            break;
                        case 'liability':
                            $type_badge = "badge-warning";
                            $type_icon = "fa-hand-holding-usd";
                            break;
                        case 'equity':
                            $type_badge = "badge-primary";
                            $type_icon = "fa-piggy-bank";
                            break;
                        case 'revenue':
                            $type_badge = "badge-info";
                            $type_icon = "fa-chart-line";
                            break;
                        case 'expense':
                            $type_badge = "badge-danger";
                            $type_icon = "fa-money-bill-wave";
                            break;
                        default:
                            $type_badge = "badge-secondary";
                            $type_icon = "fa-cog";
                    }

                    // Balance color - depends on account type and normal balance
                    if ($normal_balance == 'debit') {
                        $balance_color = $current_balance >= 0 ? 'text-success' : 'text-danger';
                    } else {
                        $balance_color = $current_balance >= 0 ? 'text-danger' : 'text-success';
                    }

                    // Default account badge
                    $default_badge = '';
                    if ($is_default_account) {
                        $default_badge = '<span class="badge badge-dark ml-1" data-toggle="tooltip" title="System Default Account"><i class="fas fa-cog"></i></span>';
                    }

                    ?>
                    <tr class="<?php echo !$is_active ? 'table-secondary' : ''; ?>">
                        <td>
                            <div class="font-weight-bold text-primary"><?php echo $account_number; ?></div>
                            <?php if(!$is_active): ?>
                                <small class="text-danger"><i class="fas fa-pause-circle"></i> Inactive</small>
                            <?php endif; ?>
                            <?php if($is_default_account): ?>
                                <small class="text-dark d-block"><i class="fas fa-cog"></i> Default Account</small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="font-weight-bold"><?php echo $account_name; ?><?php echo $default_badge; ?></div>
                            <?php if($account_description): ?>
                                <small class="text-muted"><?php echo strlen($account_description) > 50 ? substr($account_description, 0, 50) . '...' : $account_description; ?></small>
                            <?php endif; ?>
                            <?php if($parent_account_name): ?>
                                <small class="text-info d-block">
                                    <i class="fas fa-level-up-alt"></i> Parent: <?php echo $parent_account_number . ' - ' . $parent_account_name; ?>
                                </small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?php echo $type_badge; ?> badge-pill">
                                <i class="fas <?php echo $type_icon; ?> mr-1"></i>
                                <?php echo ucfirst($account_type); ?>
                            </span>
                            <div class="small text-muted mt-1">
                                <i class="fas fa-balance-scale"></i> <?php echo ucfirst($normal_balance); ?> normal
                            </div>
                        </td>
                        <td>
                            <?php if($account_subtype): ?>
                                <div class="font-weight-bold"><?php echo $account_subtype; ?></div>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                            <?php if(!empty($default_types)): ?>
                                <small class="text-dark d-block">
                                    <i class="fas fa-cogs"></i> <?php echo count($default_types); ?> default(s)
                                </small>
                            <?php endif; ?>
                        </td>
                        <td class="text-right">
                            <div class="font-weight-bold <?php echo $balance_color; ?>">
                                <?php echo numfmt_format_currency($currency_format, $current_balance, $session_company_currency); ?>
                            </div>
                            <?php if($period_debits > 0 || $period_credits > 0): ?>
                                <small class="text-muted d-block">
                                    <i class="fas fa-arrow-up text-success"></i> 
                                    <?php echo numfmt_format_currency($currency_format, $period_debits, $session_company_currency); ?>
                                    <i class="fas fa-arrow-down text-danger ml-2"></i> 
                                    <?php echo numfmt_format_currency($currency_format, $period_credits, $session_company_currency); ?>
                                </small>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if($transaction_count > 0): ?>
                                <span class="badge badge-info badge-pill"><?php echo $transaction_count; ?></span>
                                <?php if($period_debits > 0 || $period_credits > 0): ?>
                                    <div class="small text-muted mt-1">
                                        <i class="fas fa-exchange-alt"></i> Activity
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="badge badge-secondary badge-pill">0</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="dropdown dropleft text-center">
                                <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                    <i class="fas fa-ellipsis-h"></i>
                                </button>
                                <div class="dropdown-menu">
                                    <a class="dropdown-item" href="account_ledger.php?id=<?php echo $account_id; ?>">
                                        <i class="fas fa-fw fa-book mr-2"></i>View Ledger
                                    </a>
                                    <a class="dropdown-item" href="account_edit.php?id=<?php echo $account_id; ?>">
                                        <i class="fas fa-fw fa-edit mr-2"></i>Edit Account
                                    </a>
                                    <a class="dropdown-item" href="reports_account_statement.php?account_id=<?php echo $account_id; ?>">
                                        <i class="fas fa-fw fa-file-invoice mr-2"></i>Account Statement
                                    </a>
                                    <?php if($is_default_account): ?>
                                        <a class="dropdown-item" href="account_defaults.php">
                                            <i class="fas fa-fw fa-cog mr-2"></i>Manage Default
                                        </a>
                                    <?php else: ?>
                                        <a class="dropdown-item" href="account_defaults_set.php?account_id=<?php echo $account_id; ?>">
                                            <i class="fas fa-fw fa-cog mr-2"></i>Set as Default
                                        </a>
                                    <?php endif; ?>
                                    <div class="dropdown-divider"></div>
                                    <?php if(!isset($row['is_system_account'])): ?>
                                        <?php if($is_active): ?>
                                            <a class="dropdown-item text-warning confirm-link" href="post.php?deactivate_account=<?php echo $account_id; ?>">
                                                <i class="fas fa-fw fa-pause mr-2"></i>Deactivate
                                            </a>
                                        <?php else: ?>
                                            <a class="dropdown-item text-success confirm-link" href="post.php?activate_account=<?php echo $account_id; ?>">
                                                <i class="fas fa-fw fa-play mr-2"></i>Activate
                                            </a>
                                        <?php endif; ?>
                                        <?php if($current_balance == 0): ?>
                                            <a class="dropdown-item text-danger confirm-link" href="post.php?archive_account=<?php echo $account_id; ?>">
                                                <i class="fas fa-fw fa-archive mr-2"></i>Archive
                                            </a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php
                }
            }
            ?>
            </tbody>
        </table>
    </div>
    
    <!-- Recent Journal Entries Section -->
    <?php if ($table_exists && $recent_entries->num_rows > 0): ?>
    <div class="card-body border-top">
        <h5 class="mb-3"><i class="fas fa-book text-warning mr-2"></i>Recent Journal Entries</h5>
        <div class="table-responsive">
            <table class="table table-sm table-hover">
                <thead class="bg-light">
                    <tr>
                        <th>Date</th>
                        <th>Entry #</th>
                        <th>Description</th>
                        <th class="text-right">Total Debit</th>
                        <th class="text-right">Total Credit</th>
                        <th>Type</th>
                        <th>Created By</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($entry = $recent_entries->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo date('M j, Y', strtotime($entry['transaction_date'])); ?></td>
                            <td>
                                <div class="font-weight-bold"><?php echo $entry['journal_entry_number']; ?></div>
                                <small class="badge badge-<?php echo $entry['status'] == 'posted' ? 'success' : 'warning'; ?>">
                                    <?php echo ucfirst($entry['status']); ?>
                                </small>
                            </td>
                            <td><?php echo htmlspecialchars($entry['entry_description']); ?></td>
                            <td class="text-right text-success font-weight-bold">
                                <?php echo numfmt_format_currency($currency_format, $entry['total_debit'], $session_company_currency); ?>
                            </td>
                            <td class="text-right text-danger font-weight-bold">
                                <?php echo numfmt_format_currency($currency_format, $entry['total_credit'], $session_company_currency); ?>
                            </td>
                            <td>
                                <span class="badge badge-info"><?php echo str_replace('_', ' ', $entry['transaction_type']); ?></span>
                            </td>
                            <td><?php echo $entry['created_by']; ?></td>
                            <td class="text-center">
                                <a href="journal_entry_view.php?id=<?php echo $entry['journal_entry_id']; ?>" class="btn btn-info btn-sm">
                                    <i class="fas fa-eye"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <div class="text-center mt-2">
            <a href="journal_entries.php" class="btn btn-outline-warning btn-sm">
                <i class="fas fa-history mr-2"></i>View All Journal Entries
            </a>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Ends Card Body -->
    <?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/filter_footer.php'; ?>
</div>

<script>
$(document).ready(function() {
    $('.select2').select2();
    $('[data-toggle="tooltip"]').tooltip();

    // Auto-submit when filters change
    $('select[name="class"], select[name="type"], select[name="status"]').change(function() {
        $(this).closest('form').submit();
    });

    // Date range handling
    $('input[name="dtf"], input[name="dtt"]').change(function() {
        $(this).closest('form').submit();
    });

    // Quick filter buttons
    $('.btn-group-toggle .btn').click(function(e) {
        e.preventDefault();
        window.location.href = $(this).attr('href');
    });

    // Confirm destructive actions
    $('.confirm-link').click(function(e) {
        if (!confirm('Are you sure you want to perform this action?')) {
            e.preventDefault();
        }
    });
});

function handleDateSelection(select) {
    const form = select.closest('form');
    const dtfInput = form.querySelector('input[name="dtf"]');
    const dttInput = form.querySelector('input[name="dtt"]');
    
    const today = new Date();
    let startDate, endDate;
    
    switch(select.value) {
        case 'today':
            startDate = endDate = today.toISOString().split('T')[0];
            break;
        case 'yesterday':
            const yesterday = new Date(today);
            yesterday.setDate(today.getDate() - 1);
            startDate = endDate = yesterday.toISOString().split('T')[0];
            break;
        case 'thisweek':
            const firstDay = new Date(today.setDate(today.getDate() - today.getDay()));
            const lastDay = new Date(today.setDate(today.getDate() - today.getDay() + 6));
            startDate = firstDay.toISOString().split('T')[0];
            endDate = lastDay.toISOString().split('T')[0];
            break;
        case 'lastweek':
            const lastWeekStart = new Date(today.setDate(today.getDate() - today.getDay() - 7));
            const lastWeekEnd = new Date(today.setDate(today.getDate() - today.getDay() - 1));
            startDate = lastWeekStart.toISOString().split('T')[0];
            endDate = lastWeekEnd.toISOString().split('T')[0];
            break;
        case 'thismonth':
            startDate = new Date(today.getFullYear(), today.getMonth(), 1).toISOString().split('T')[0];
            endDate = new Date(today.getFullYear(), today.getMonth() + 1, 0).toISOString().split('T')[0];
            break;
        case 'lastmonth':
            const lastMonth = new Date(today.getFullYear(), today.getMonth() - 1, 1);
            startDate = new Date(lastMonth.getFullYear(), lastMonth.getMonth(), 1).toISOString().split('T')[0];
            endDate = new Date(lastMonth.getFullYear(), lastMonth.getMonth() + 1, 0).toISOString().split('T')[0];
            break;
        case 'thisquarter':
            const quarter = Math.floor((today.getMonth() + 3) / 3);
            startDate = new Date(today.getFullYear(), (quarter - 1) * 3, 1).toISOString().split('T')[0];
            endDate = new Date(today.getFullYear(), quarter * 3, 0).toISOString().split('T')[0];
            break;
        case 'thisyear':
            startDate = new Date(today.getFullYear(), 0, 1).toISOString().split('T')[0];
            endDate = new Date(today.getFullYear(), 11, 31).toISOString().split('T')[0];
            break;
        default:
            return; // Custom date, don't auto-submit
    }
    
    dtfInput.value = startDate;
    dttInput.value = endDate;
    form.submit();
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + A for new account
    if (e.ctrlKey && e.keyCode === 65) {
        e.preventDefault();
        window.location.href = 'account_new.php';
    }
    // Ctrl + J for new journal entry
    if (e.ctrlKey && e.keyCode === 74) {
        e.preventDefault();
        window.location.href = 'journal_entry_new.php';
    }
    // Ctrl + F for focus search
    if (e.ctrlKey && e.keyCode === 70) {
        e.preventDefault();
        $('input[name="q"]').focus();
    }
    // Ctrl + R for reports
    if (e.ctrlKey && e.keyCode === 82) {
        e.preventDefault();
        window.location.href = 'reports_balance_sheet.php';
    }
    // Ctrl + D for defaults
    if (e.ctrlKey && e.keyCode === 68) {
        e.preventDefault();
        window.location.href = 'account_defaults.php';
    }
});
</script>

<style>
.card .card-body {
    padding: 1rem;
}

.info-box {
    border-radius: 0.25rem;
    box-shadow: 0 0 1px rgba(0,0,0,.125);
}

.info-box-icon {
    width: 60px;
    height: 60px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 0.25rem 0 0 0.25rem;
}

.badge-pill {
    padding: 0.5em 0.8em;
}

.btn-group-toggle .btn.active {
    background-color: #007bff;
    border-color: #007bff;
    color: white;
}

.alert-container .alert {
    margin-bottom: 0.5rem;
}

.table-secondary {
    background-color: #f8f9fa !important;
    color: #6c757d;
}

.card-sm {
    margin-bottom: 0.5rem;
    border: 1px solid #e9ecef;
}

.card-sm .card-body {
    padding: 0.5rem;
}

.card-footer {
    background-color: #f8f9fa;
    border-top: 1px solid #e9ecef;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
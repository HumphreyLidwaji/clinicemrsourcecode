<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Filter parameters
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$period_filter = $_GET['period'] ?? 'thismonth';

// Date Filter based on period
switch ($period_filter) {
    case 'today':
        $start_date = date('Y-m-d');
        $end_date = date('Y-m-d');
        break;
    case 'yesterday':
        $start_date = date('Y-m-d', strtotime('-1 day'));
        $end_date = date('Y-m-d', strtotime('-1 day'));
        break;
    case 'thisweek':
        $start_date = date('Y-m-d', strtotime('monday this week'));
        $end_date = date('Y-m-d', strtotime('sunday this week'));
        break;
    case 'thismonth':
        $start_date = date('Y-m-01');
        $end_date = date('Y-m-d');
        break;
    case 'lastmonth':
        $start_date = date('Y-m-01', strtotime('-1 month'));
        $end_date = date('Y-m-t', strtotime('-1 month'));
        break;
    case 'thisquarter':
        $current_quarter = ceil(date('n') / 3);
        $start_date = date('Y-m-d', mktime(0, 0, 0, ($current_quarter - 1) * 3 + 1, 1, date('Y')));
        $end_date = date('Y-m-d');
        break;
    case 'thisyear':
        $start_date = date('Y-01-01');
        $end_date = date('Y-m-d');
        break;
    case 'custom':
        // Use the provided dates
        break;
    default:
        $period_filter = 'thismonth';
        $start_date = date('Y-m-01');
        $end_date = date('Y-m-d');
        break;
}

// Get cash accounts (typically asset accounts with "cash" or "bank" in name)
$cash_accounts_sql = "
    SELECT a.*, at.type_name
    FROM accounts a 
    JOIN account_types at ON a.account_type = at.type_id 
    WHERE at.type_class = 'Asset' 
    AND a.is_active = 1
    AND (
        a.account_name LIKE '%cash%' 
        OR a.account_name LIKE '%bank%' 
        OR a.account_name LIKE '%checking%'
        OR a.account_name LIKE '%savings%'
        OR a.account_name LIKE '%money%'
    )
    ORDER BY a.account_number
";

$cash_accounts_result = $mysqli->query($cash_accounts_sql);
$cash_accounts = [];
$all_cash_account_ids = [];

while($account = $cash_accounts_result->fetch_assoc()) {
    $cash_accounts[] = $account;
    $all_cash_account_ids[] = $account['account_id'];
}

// Prepare cash account IDs for SQL query
$cash_account_ids = !empty($all_cash_account_ids) ? implode(',', $all_cash_account_ids) : '0';

// Calculate cash flow using journal_entry_lines
// 1. Cash from Operating Activities (typically revenue accounts and expense accounts affecting cash)
$operating_activities_sql = "
    SELECT 
        SUM(CASE WHEN jel.entry_type = 'debit' THEN jel.amount ELSE -jel.amount END) as net_cash_flow
    FROM journal_entry_lines jel
    JOIN journal_entries je ON jel.entry_id = je.entry_id
    JOIN journal_headers jh ON je.journal_header_id = jh.journal_header_id
    JOIN accounts a ON jel.account_id = a.account_id
    JOIN account_types at ON a.account_type = at.type_id
    WHERE jel.account_id IN ($cash_account_ids)
    AND jh.status = 'posted'
    AND DATE(je.entry_date) BETWEEN '$start_date' AND '$end_date'
    AND je.entry_type IN ('receipt', 'payment', 'adjustment')
    AND (
        at.type_class = 'Revenue' 
        OR at.type_class = 'Expense'
        OR a.account_name LIKE '%sales%'
        OR a.account_name LIKE '%income%'
        OR a.account_name LIKE '%revenue%'
        OR a.account_name LIKE '%expense%'
        OR a.account_name LIKE '%cost%'
        OR a.account_name LIKE '%salary%'
        OR a.account_name LIKE '%rent%'
        OR a.account_name LIKE '%utility%'
    )
";

$operating_result = $mysqli->query($operating_activities_sql);
$operating_flow = $operating_result->fetch_assoc();
$cash_from_operations = floatval($operating_flow['net_cash_flow'] ?? 0);

// 2. Cash from Investing Activities (typically asset purchases/sales)
$investing_activities_sql = "
    SELECT 
        SUM(CASE WHEN jel.entry_type = 'debit' THEN jel.amount ELSE -jel.amount END) as net_cash_flow
    FROM journal_entry_lines jel
    JOIN journal_entries je ON jel.entry_id = je.entry_id
    JOIN journal_headers jh ON je.journal_header_id = jh.journal_header_id
    JOIN accounts a ON jel.account_id = a.account_id
    JOIN account_types at ON a.account_type = at.type_id
    WHERE jel.account_id IN ($cash_account_ids)
    AND jh.status = 'posted'
    AND DATE(je.entry_date) BETWEEN '$start_date' AND '$end_date'
    AND (
        a.account_name LIKE '%equipment%'
        OR a.account_name LIKE '%property%'
        OR a.account_name LIKE '%investment%'
        OR a.account_name LIKE '%fixed%asset%'
        OR a.account_name LIKE '%vehicle%'
        OR a.account_name LIKE '%building%'
    )
";

$investing_result = $mysqli->query($investing_activities_sql);
$investing_flow = $investing_result->fetch_assoc();
$cash_from_investing = floatval($investing_flow['net_cash_flow'] ?? 0);

// 3. Cash from Financing Activities (typically equity and liability changes)
$financing_activities_sql = "
    SELECT 
        SUM(CASE WHEN jel.entry_type = 'debit' THEN jel.amount ELSE -jel.amount END) as net_cash_flow
    FROM journal_entry_lines jel
    JOIN journal_entries je ON jel.entry_id = je.entry_id
    JOIN journal_headers jh ON je.journal_header_id = jh.journal_header_id
    JOIN accounts a ON jel.account_id = a.account_id
    JOIN account_types at ON a.account_type = at.type_id
    WHERE jel.account_id IN ($cash_account_ids)
    AND jh.status = 'posted'
    AND DATE(je.entry_date) BETWEEN '$start_date' AND '$end_date'
    AND (
        at.type_class = 'Liability' 
        OR at.type_class = 'Equity'
        OR a.account_name LIKE '%loan%'
        OR a.account_name LIKE '%capital%'
        OR a.account_name LIKE '%dividend%'
        OR a.account_name LIKE '%drawing%'
        OR a.account_name LIKE '%owner%'
    )
";

$financing_result = $mysqli->query($financing_activities_sql);
$financing_flow = $financing_result->fetch_assoc();
$cash_from_financing = floatval($financing_flow['net_cash_flow'] ?? 0);

// Calculate net cash flow
$net_cash_flow = $cash_from_operations + $cash_from_investing + $cash_from_financing;

// Get opening cash balance (before start date)
$opening_cash_sql = "
    SELECT 
        COALESCE(SUM(CASE WHEN jel.entry_type = 'debit' THEN jel.amount ELSE -jel.amount END), 0) as opening_balance
    FROM journal_entry_lines jel
    JOIN journal_entries je ON jel.entry_id = je.entry_id
    JOIN journal_headers jh ON je.journal_header_id = jh.journal_header_id
    WHERE jel.account_id IN ($cash_account_ids)
    AND jh.status = 'posted'
    AND DATE(je.entry_date) < '$start_date'
";

$opening_cash_result = $mysqli->query($opening_cash_sql);
$opening_cash_row = $opening_cash_result->fetch_assoc();
$opening_cash_balance = floatval($opening_cash_row['opening_balance'] ?? 0);

// Add opening balances from cash accounts
$opening_balances_sql = "
    SELECT COALESCE(SUM(opening_balance), 0) as total_opening
    FROM accounts 
    WHERE account_id IN ($cash_account_ids)
";
$opening_balances_result = $mysqli->query($opening_balances_sql);
$opening_balances_row = $opening_balances_result->fetch_assoc();
$opening_cash_balance += floatval($opening_balances_row['total_opening'] ?? 0);

// Calculate closing cash balance
$closing_cash_balance = $opening_cash_balance + $net_cash_flow;

// Get cash account details with current balances
$cash_account_details = [];
foreach ($cash_accounts as $account) {
    $account_id = $account['account_id'];
    
    $account_balance_sql = "
        SELECT 
            COALESCE(SUM(CASE WHEN jel.entry_type = 'debit' THEN jel.amount ELSE -jel.amount END), 0) as current_balance
        FROM journal_entry_lines jel
        JOIN journal_entries je ON jel.entry_id = je.entry_id
        JOIN journal_headers jh ON je.journal_header_id = jh.journal_header_id
        WHERE jel.account_id = $account_id
        AND jh.status = 'posted'
        AND DATE(je.entry_date) <= '$end_date'
    ";
    
    $balance_result = $mysqli->query($account_balance_sql);
    $balance_row = $balance_result->fetch_assoc();
    $current_balance = floatval($balance_row['current_balance'] ?? 0) + floatval($account['opening_balance'] ?? 0);
    
    $cash_account_details[] = [
        'account' => $account,
        'current_balance' => $current_balance
    ];
}

// Get top cash transactions
$top_transactions_sql = "
    SELECT 
        jel.*,
        je.entry_date,
        je.entry_description,
        je.reference_number,
        je.entry_number,
        a.account_name,
        a.account_number,
        at.type_class,
        jh.description as journal_description
    FROM journal_entry_lines jel
    JOIN journal_entries je ON jel.entry_id = je.entry_id
    JOIN journal_headers jh ON je.journal_header_id = jh.journal_header_id
    JOIN accounts a ON jel.account_id = a.account_id
    JOIN account_types at ON a.account_type = at.type_id
    WHERE jel.account_id IN ($cash_account_ids)
    AND jh.status = 'posted'
    AND DATE(je.entry_date) BETWEEN '$start_date' AND '$end_date'
    ORDER BY jel.amount DESC
    LIMIT 10
";

$top_transactions_result = $mysqli->query($top_transactions_sql);
$top_transactions = [];

while($transaction = $top_transactions_result->fetch_assoc()) {
    $top_transactions[] = $transaction;
}

// Calculate cash flow trend (month-over-month comparison if available)
$previous_period_start = date('Y-m-d', strtotime($start_date . ' -1 month'));
$previous_period_end = date('Y-m-d', strtotime($end_date . ' -1 month'));

// Get previous period cash flow
$previous_cash_flow_sql = "
    SELECT 
        COALESCE(SUM(CASE WHEN jel.entry_type = 'debit' THEN jel.amount ELSE -jel.amount END), 0) as previous_net_cash_flow
    FROM journal_entry_lines jel
    JOIN journal_entries je ON jel.entry_id = je.entry_id
    JOIN journal_headers jh ON je.journal_header_id = jh.journal_header_id
    WHERE jel.account_id IN ($cash_account_ids)
    AND jh.status = 'posted'
    AND DATE(je.entry_date) BETWEEN '$previous_period_start' AND '$previous_period_end'
";

$previous_cash_flow_result = $mysqli->query($previous_cash_flow_sql);
$previous_cash_flow_row = $previous_cash_flow_result->fetch_assoc();
$previous_net_cash_flow = floatval($previous_cash_flow_row['previous_net_cash_flow'] ?? 0);

// Calculate trend percentage
$trend_percentage = 0;
if ($previous_net_cash_flow != 0) {
    $trend_percentage = (($net_cash_flow - $previous_net_cash_flow) / abs($previous_net_cash_flow)) * 100;
}

?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-money-bill-wave mr-2"></i>Cash Flow Statement
        </h3>
        <div class="card-tools">
            <a href="?export=pdf&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&period=<?php echo $period_filter; ?>" class="btn btn-light">
                <i class="fas fa-file-pdf mr-2"></i>Export PDF
            </a>
            <button class="btn btn-primary" onclick="window.print()">
                <i class="fas fa-print mr-2"></i>Print
            </button>
            <a href="reports_balance_sheet.php?date=<?php echo $end_date; ?>" class="btn btn-success">
                <i class="fas fa-file-invoice-dollar mr-2"></i>Balance Sheet
            </a>
        </div>
    </div>

    <div class="card-header pb-2 pt-3">
        <form autocomplete="off">
            <div class="row">
                <div class="col-md-5">
                    <div class="form-group">
                        <label>Period</label>
                        <div class="input-group">
                            <select class="form-control select2" name="period" onchange="this.form.submit()">
                                <option value="custom" <?php if ($period_filter == "custom") { echo "selected"; } ?>>Custom</option>
                                <option value="today" <?php if ($period_filter == "today") { echo "selected"; } ?>>Today</option>
                                <option value="yesterday" <?php if ($period_filter == "yesterday") { echo "selected"; } ?>>Yesterday</option>
                                <option value="thisweek" <?php if ($period_filter == "thisweek") { echo "selected"; } ?>>This Week</option>
                                <option value="thismonth" <?php if ($period_filter == "thismonth") { echo "selected"; } ?>>This Month</option>
                                <option value="lastmonth" <?php if ($period_filter == "lastmonth") { echo "selected"; } ?>>Last Month</option>
                                <option value="thisquarter" <?php if ($period_filter == "thisquarter") { echo "selected"; } ?>>This Quarter</option>
                                <option value="thisyear" <?php if ($period_filter == "thisyear") { echo "selected"; } ?>>This Year</option>
                            </select>
                            <?php if ($period_filter == 'custom'): ?>
                                <input type="date" class="form-control ml-2" name="start_date" value="<?php echo $start_date; ?>" onchange="this.form.submit()">
                                <input type="date" class="form-control ml-2" name="end_date" value="<?php echo $end_date; ?>" onchange="this.form.submit()">
                            <?php endif; ?>
                        </div>
                        <small class="form-text text-muted">
                            Cash flow for period: <?php echo date('M j, Y', strtotime($start_date)); ?> to <?php echo date('M j, Y', strtotime($end_date)); ?>
                        </small>
                    </div>
                </div>
                <div class="col-md-7">
                    <div class="btn-toolbar form-group float-right">
                        <div class="btn-group">
                            <a href="reports_income_statement.php?start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>" class="btn btn-success">
                                <i class="fas fa-fw fa-chart-line mr-2"></i>Income Statement
                            </a>
                            <a href="reports_trial_balance.php?date=<?php echo $end_date; ?>" class="btn btn-warning">
                                <i class="fas fa-fw fa-balance-scale mr-2"></i>Trial Balance
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- Cash Flow Summary -->
    <div class="card-body border-bottom">
        <div class="row text-center">
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-info"><i class="fas fa-exchange-alt"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Opening Cash</span>
                        <span class="info-box-number <?php echo $opening_cash_balance >= 0 ? 'text-success' : 'text-danger'; ?>">
                            <?php echo numfmt_format_currency($currency_format, $opening_cash_balance, $session_company_currency); ?>
                        </span>
                        <small class="text-muted">As of <?php echo date('M j, Y', strtotime($start_date . ' -1 day')); ?></small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-success"><i class="fas fa-briefcase"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Operations</span>
                        <span class="info-box-number <?php echo $cash_from_operations >= 0 ? 'text-success' : 'text-danger'; ?>">
                            <?php echo numfmt_format_currency($currency_format, $cash_from_operations, $session_company_currency); ?>
                        </span>
                        <small class="text-muted"><?php echo $cash_from_operations >= 0 ? 'Inflow' : 'Outflow'; ?></small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-warning"><i class="fas fa-chart-line"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Investing</span>
                        <span class="info-box-number <?php echo $cash_from_investing >= 0 ? 'text-success' : 'text-danger'; ?>">
                            <?php echo numfmt_format_currency($currency_format, $cash_from_investing, $session_company_currency); ?>
                        </span>
                        <small class="text-muted"><?php echo $cash_from_investing >= 0 ? 'Inflow' : 'Outflow'; ?></small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-secondary"><i class="fas fa-hand-holding-usd"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Financing</span>
                        <span class="info-box-number <?php echo $cash_from_financing >= 0 ? 'text-success' : 'text-danger'; ?>">
                            <?php echo numfmt_format_currency($currency_format, $cash_from_financing, $session_company_currency); ?>
                        </span>
                        <small class="text-muted"><?php echo $cash_from_financing >= 0 ? 'Inflow' : 'Outflow'; ?></small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-primary"><i class="fas fa-wallet"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Closing Cash</span>
                        <span class="info-box-number <?php echo $closing_cash_balance >= 0 ? 'text-success' : 'text-danger'; ?>">
                            <?php echo numfmt_format_currency($currency_format, $closing_cash_balance, $session_company_currency); ?>
                        </span>
                        <small class="text-muted">As of <?php echo date('M j, Y', strtotime($end_date)); ?></small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Cash Flow Statement -->
    <div class="card-body">
        <div class="row">
            <div class="col-md-12">
                <h4 class="mb-4">
                    <i class="fas fa-file-invoice-dollar text-primary mr-2"></i>
                    Statement of Cash Flows
                    <span class="badge badge-<?php echo $net_cash_flow >= 0 ? 'success' : 'danger'; ?> ml-2">
                        Net Cash Flow: <?php echo numfmt_format_currency($currency_format, $net_cash_flow, $session_company_currency); ?>
                    </span>
                </h4>
            </div>
        </div>

        <div class="row">
            <div class="col-md-8">
                <!-- Cash Flow Statement Details -->
                <div class="card">
                    <div class="card-body p-0">
                        <table class="table table-bordered mb-0">
                            <tbody>
                                <!-- Opening Cash Balance -->
                                <tr class="table-info">
                                    <td width="80%" class="font-weight-bold">
                                        <i class="fas fa-sign-in-alt mr-2"></i>Opening Cash Balance
                                    </td>
                                    <td width="20%" class="text-right font-weight-bold <?php echo $opening_cash_balance >= 0 ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo numfmt_format_currency($currency_format, $opening_cash_balance, $session_company_currency); ?>
                                    </td>
                                </tr>

                                <!-- Cash from Operating Activities -->
                                <tr class="table-light">
                                    <td colspan="2" class="font-weight-bold bg-success text-white">
                                        <i class="fas fa-briefcase mr-2"></i>CASH FLOW FROM OPERATING ACTIVITIES
                                    </td>
                                </tr>
                                <tr>
                                    <td class="pl-4">
                                        <i class="fas fa-arrow-right text-success mr-2"></i>
                                        Cash receipts from customers & operations
                                    </td>
                                    <td class="text-right text-success">
                                        <?php 
                                        $operating_inflow = $cash_from_operations > 0 ? $cash_from_operations : 0;
                                        echo numfmt_format_currency($currency_format, $operating_inflow, $session_company_currency);
                                        ?>
                                    </td>
                                </tr>
                                <?php if ($cash_from_operations < 0): ?>
                                <tr>
                                    <td class="pl-4">
                                        <i class="fas fa-arrow-left text-danger mr-2"></i>
                                        Cash payments for expenses & operations
                                    </td>
                                    <td class="text-right text-danger">
                                        <?php echo numfmt_format_currency($currency_format, abs($cash_from_operations), $session_company_currency); ?>
                                    </td>
                                </tr>
                                <?php endif; ?>
                                <tr class="table-success font-weight-bold">
                                    <td class="border-top">
                                        Net Cash Provided by Operating Activities
                                    </td>
                                    <td class="text-right border-top <?php echo $cash_from_operations >= 0 ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo numfmt_format_currency($currency_format, $cash_from_operations, $session_company_currency); ?>
                                    </td>
                                </tr>

                                <!-- Cash from Investing Activities -->
                                <tr class="table-light">
                                    <td colspan="2" class="font-weight-bold bg-warning text-white">
                                        <i class="fas fa-chart-line mr-2"></i>CASH FLOW FROM INVESTING ACTIVITIES
                                    </td>
                                </tr>
                                <?php if ($cash_from_investing > 0): ?>
                                <tr>
                                    <td class="pl-4">
                                        <i class="fas fa-arrow-right text-success mr-2"></i>
                                        Proceeds from sale of assets/investments
                                    </td>
                                    <td class="text-right text-success">
                                        <?php echo numfmt_format_currency($currency_format, $cash_from_investing, $session_company_currency); ?>
                                    </td>
                                </tr>
                                <?php elseif ($cash_from_investing < 0): ?>
                                <tr>
                                    <td class="pl-4">
                                        <i class="fas fa-arrow-left text-danger mr-2"></i>
                                        Purchase of equipment/fixed assets
                                    </td>
                                    <td class="text-right text-danger">
                                        <?php echo numfmt_format_currency($currency_format, abs($cash_from_investing), $session_company_currency); ?>
                                    </td>
                                </tr>
                                <?php else: ?>
                                <tr>
                                    <td class="pl-4 text-muted">
                                        <i class="fas fa-minus mr-2"></i>
                                        No investing activities
                                    </td>
                                    <td class="text-right text-muted">-</td>
                                </tr>
                                <?php endif; ?>
                                <tr class="table-warning font-weight-bold">
                                    <td class="border-top">
                                        Net Cash Used in Investing Activities
                                    </td>
                                    <td class="text-right border-top <?php echo $cash_from_investing >= 0 ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo numfmt_format_currency($currency_format, $cash_from_investing, $session_company_currency); ?>
                                    </td>
                                </tr>

                                <!-- Cash from Financing Activities -->
                                <tr class="table-light">
                                    <td colspan="2" class="font-weight-bold bg-secondary text-white">
                                        <i class="fas fa-hand-holding-usd mr-2"></i>CASH FLOW FROM FINANCING ACTIVITIES
                                    </td>
                                </tr>
                                <?php if ($cash_from_financing > 0): ?>
                                <tr>
                                    <td class="pl-4">
                                        <i class="fas fa-arrow-right text-success mr-2"></i>
                                        Proceeds from loans/investments
                                    </td>
                                    <td class="text-right text-success">
                                        <?php echo numfmt_format_currency($currency_format, $cash_from_financing, $session_company_currency); ?>
                                    </td>
                                </tr>
                                <?php elseif ($cash_from_financing < 0): ?>
                                <tr>
                                    <td class="pl-4">
                                        <i class="fas fa-arrow-left text-danger mr-2"></i>
                                        Loan repayments/dividend payments
                                    </td>
                                    <td class="text-right text-danger">
                                        <?php echo numfmt_format_currency($currency_format, abs($cash_from_financing), $session_company_currency); ?>
                                    </td>
                                </tr>
                                <?php else: ?>
                                <tr>
                                    <td class="pl-4 text-muted">
                                        <i class="fas fa-minus mr-2"></i>
                                        No financing activities
                                    </td>
                                    <td class="text-right text-muted">-</td>
                                </tr>
                                <?php endif; ?>
                                <tr class="table-secondary font-weight-bold">
                                    <td class="border-top">
                                        Net Cash Provided by Financing Activities
                                    </td>
                                    <td class="text-right border-top <?php echo $cash_from_financing >= 0 ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo numfmt_format_currency($currency_format, $cash_from_financing, $session_company_currency); ?>
                                    </td>
                                </tr>

                                <!-- Net Increase/Decrease in Cash -->
                                <tr class="table-primary font-weight-bold">
                                    <td class="border-top border-dark">
                                        Net Increase (Decrease) in Cash
                                    </td>
                                    <td class="text-right border-top border-dark <?php echo $net_cash_flow >= 0 ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo numfmt_format_currency($currency_format, $net_cash_flow, $session_company_currency); ?>
                                    </td>
                                </tr>

                                <!-- Closing Cash Balance -->
                                <tr class="table-info font-weight-bold">
                                    <td>
                                        <i class="fas fa-sign-out-alt mr-2"></i>Closing Cash Balance
                                    </td>
                                    <td class="text-right <?php echo $closing_cash_balance >= 0 ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo numfmt_format_currency($currency_format, $closing_cash_balance, $session_company_currency); ?>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Cash Flow Trend -->
                <div class="card mt-4">
                    <div class="card-header bg-info text-white">
                        <h6 class="card-title mb-0">
                            <i class="fas fa-chart-line mr-2"></i>Cash Flow Trend Analysis
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-md-4">
                                <div class="p-3">
                                    <h5 class="<?php echo $net_cash_flow >= 0 ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo numfmt_format_currency($currency_format, $net_cash_flow, $session_company_currency); ?>
                                    </h5>
                                    <small class="text-muted">Current Period</small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="p-3">
                                    <h5 class="<?php echo $previous_net_cash_flow >= 0 ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo numfmt_format_currency($currency_format, $previous_net_cash_flow, $session_company_currency); ?>
                                    </h5>
                                    <small class="text-muted">Previous Period</small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="p-3">
                                    <h5 class="<?php echo $trend_percentage >= 0 ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo number_format($trend_percentage, 1); ?>%
                                    </h5>
                                    <small class="text-muted">Change</small>
                                </div>
                            </div>
                        </div>
                        <div class="mt-3">
                            <div class="progress" style="height: 20px;">
                                <?php 
                                $positive_percentage = $cash_from_operations > 0 ? abs($cash_from_operations) / max(abs($cash_from_operations) + abs($cash_from_investing) + abs($cash_from_financing), 1) * 100 : 0;
                                $investing_percentage = $cash_from_investing != 0 ? abs($cash_from_investing) / max(abs($cash_from_operations) + abs($cash_from_investing) + abs($cash_from_financing), 1) * 100 : 0;
                                $financing_percentage = $cash_from_financing != 0 ? abs($cash_from_financing) / max(abs($cash_from_operations) + abs($cash_from_investing) + abs($cash_from_financing), 1) * 100 : 0;
                                ?>
                                <div class="progress-bar bg-success" style="width: <?php echo $positive_percentage; ?>%">
                                    Operations
                                </div>
                                <div class="progress-bar bg-warning" style="width: <?php echo $investing_percentage; ?>%">
                                    Investing
                                </div>
                                <div class="progress-bar bg-secondary" style="width: <?php echo $financing_percentage; ?>%">
                                    Financing
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <!-- Cash Accounts Summary -->
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h6 class="card-title mb-0">
                            <i class="fas fa-wallet mr-2"></i>Cash Accounts
                            <span class="badge badge-light float-right"><?php echo count($cash_accounts); ?> accounts</span>
                        </h6>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0">
                                <tbody>
                                    <?php if (empty($cash_accounts)): ?>
                                        <tr>
                                            <td colspan="2" class="text-center text-muted py-3">
                                                <i class="fas fa-search-dollar fa-2x mb-2"></i><br>
                                                No cash accounts found
                                                <div class="small mt-2">Create accounts with "cash", "bank", or "checking" in name</div>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php 
                                        $total_cash = 0;
                                        foreach ($cash_account_details as $detail): 
                                            $total_cash += $detail['current_balance'];
                                        ?>
                                            <tr>
                                                <td>
                                                    <div class="font-weight-bold"><?php echo htmlspecialchars($detail['account']['account_number']); ?></div>
                                                    <small class="text-muted"><?php echo htmlspecialchars($detail['account']['account_name']); ?></small>
                                                </td>
                                                <td class="text-right font-weight-bold <?php echo $detail['current_balance'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                    <?php echo numfmt_format_currency($currency_format, $detail['current_balance'], $session_company_currency); ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <tr class="table-success font-weight-bold">
                                            <td class="border-top">Total Cash</td>
                                            <td class="text-right border-top">
                                                <?php echo numfmt_format_currency($currency_format, $total_cash, $session_company_currency); ?>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Top Cash Transactions -->
                <div class="card mt-4">
                    <div class="card-header bg-info text-white">
                        <h6 class="card-title mb-0">
                            <i class="fas fa-list-ol mr-2"></i>Top Cash Transactions
                            <span class="badge badge-light float-right">Top 10</span>
                        </h6>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0">
                                <tbody>
                                    <?php if (empty($top_transactions)): ?>
                                        <tr>
                                            <td colspan="3" class="text-center text-muted py-3">
                                                <i class="fas fa-exchange-alt fa-2x mb-2"></i><br>
                                                No cash transactions
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($top_transactions as $transaction): ?>
                                            <tr>
                                                <td>
                                                    <div class="font-weight-bold"><?php echo date('M j', strtotime($transaction['entry_date'])); ?></div>
                                                    <small class="text-muted"><?php echo htmlspecialchars(substr($transaction['entry_description'] ?: $transaction['journal_description'], 0, 30)); ?>...</small>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge badge-<?php echo $transaction['entry_type'] == 'debit' ? 'success' : 'danger'; ?>">
                                                        <?php echo $transaction['entry_type'] == 'debit' ? 'In' : 'Out'; ?>
                                                    </span>
                                                </td>
                                                <td class="text-right font-weight-bold <?php echo $transaction['entry_type'] == 'debit' ? 'text-success' : 'text-danger'; ?>">
                                                    <?php echo numfmt_format_currency($currency_format, $transaction['amount'], $session_company_currency); ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Quick Analysis -->
                <div class="card mt-4">
                    <div class="card-header bg-warning text-white">
                        <h6 class="card-title mb-0">
                            <i class="fas fa-chart-pie mr-2"></i>Cash Flow Distribution
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="text-center mb-3">
                            <canvas id="cashFlowChart" width="200" height="200"></canvas>
                        </div>
                        <div class="small">
                            <div class="d-flex justify-content-between mb-1">
                                <span class="text-success">Operations</span>
                                <span><?php echo number_format(abs($cash_from_operations) / max(abs($cash_from_operations) + abs($cash_from_investing) + abs($cash_from_financing), 1) * 100, 1); ?>%</span>
                            </div>
                            <div class="d-flex justify-content-between mb-1">
                                <span class="text-warning">Investing</span>
                                <span><?php echo number_format(abs($cash_from_investing) / max(abs($cash_from_operations) + abs($cash_from_investing) + abs($cash_from_financing), 1) * 100, 1); ?>%</span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="text-secondary">Financing</span>
                                <span><?php echo number_format(abs($cash_from_financing) / max(abs($cash_from_operations) + abs($cash_from_investing) + abs($cash_from_financing), 1) * 100, 1); ?>%</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Action Buttons -->
    <div class="card-footer">
        <div class="row">
            <div class="col-md-12">
                <div class="btn-toolbar justify-content-between">
                    <div class="btn-group">
                        <a href="reports_income_statement.php?start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>" class="btn btn-success">
                            <i class="fas fa-chart-line mr-2"></i>Income Statement
                        </a>
                        <a href="reports_balance_sheet.php?date=<?php echo $end_date; ?>" class="btn btn-primary">
                            <i class="fas fa-file-invoice-dollar mr-2"></i>Balance Sheet
                        </a>
                        <a href="reports_general_ledger.php" class="btn btn-info">
                            <i class="fas fa-book mr-2"></i>General Ledger
                        </a>
                    </div>
                    <div class="btn-group">
                        <a href="accounts.php" class="btn btn-outline-secondary">
                            <i class="fas fa-list mr-2"></i>Manage Accounts
                        </a>
                        <a href="journal_entries.php" class="btn btn-outline-warning">
                            <i class="fas fa-book mr-2"></i>View Journal
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Chart.js for Cash Flow Distribution -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
$(document).ready(function() {
    $('.select2').select2({
        width: '100%'
    });
    
    // Auto-submit when period changes (except for custom)
    $('select[name="period"]').change(function() {
        if ($(this).val() !== 'custom') {
            $(this).closest('form').submit();
        }
    });
    
    // Toggle custom date input
    $('select[name="period"]').on('change', function() {
        if ($(this).val() === 'custom') {
            // Show custom date inputs if not already present
            if (!$('input[name="start_date"]').length) {
                $(this).closest('.input-group').append(
                    '<input type="date" class="form-control ml-2" name="start_date" value="<?php echo date('Y-m-01'); ?>">'
                ).append(
                    '<input type="date" class="form-control ml-2" name="end_date" value="<?php echo date('Y-m-d'); ?>">'
                );
            }
        } else {
            // Hide custom date inputs
            $('input[name="start_date"], input[name="end_date"]').remove();
        }
    });
    
    // Print button functionality
    $('.btn-print').click(function(e) {
        e.preventDefault();
        window.print();
    });
    
    // Initialize cash flow chart
    var ctx = document.getElementById('cashFlowChart').getContext('2d');
    var cashFlowChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Operations', 'Investing', 'Financing'],
            datasets: [{
                data: [
                    Math.abs(<?php echo $cash_from_operations; ?>),
                    Math.abs(<?php echo $cash_from_investing; ?>),
                    Math.abs(<?php echo $cash_from_financing; ?>)
                ],
                backgroundColor: [
                    '#28a745', // success green
                    '#ffc107', // warning yellow
                    '#6c757d'  // secondary gray
                ],
                borderWidth: 1
            }]
        },
        options: {
            responsive: false,
            maintainAspectRatio: false,
            cutoutPercentage: 70,
            legend: {
                display: false
            },
            tooltips: {
                callbacks: {
                    label: function(tooltipItem, data) {
                        var label = data.labels[tooltipItem.index];
                        var value = data.datasets[0].data[tooltipItem.index];
                        var total = data.datasets[0].data.reduce((a, b) => a + b, 0);
                        var percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                        return label + ': ' + percentage + '%';
                    }
                }
            }
        }
    });
    
    // Auto-refresh chart on window resize
    $(window).resize(function() {
        cashFlowChart.resize();
    });
    
    // Keyboard shortcuts
    $(document).keydown(function(e) {
        // Ctrl + P to print
        if (e.ctrlKey && e.keyCode === 80) {
            e.preventDefault();
            window.print();
        }
        // Ctrl + E to export
        if (e.ctrlKey && e.keyCode === 69) {
            e.preventDefault();
            alert('Export functionality would be implemented here');
        }
    });
});
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
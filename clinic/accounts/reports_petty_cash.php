<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Initialize variables
$period = sanitizeInput($_GET['period'] ?? 'month');
$export = sanitizeInput($_GET['export'] ?? '');
$start_date = sanitizeInput($_GET['start_date'] ?? '');
$end_date = sanitizeInput($_GET['end_date'] ?? '');
$category_filter = intval($_GET['category'] ?? 0);

// Date range calculations based on period
switch ($period) {
    case 'today':
        $start_date = date('Y-m-d');
        $end_date = date('Y-m-d');
        $period_label = 'Today';
        break;
    case 'week':
        $start_date = date('Y-m-d', strtotime('monday this week'));
        $end_date = date('Y-m-d', strtotime('sunday this week'));
        $period_label = 'This Week';
        break;
    case 'month':
        $start_date = date('Y-m-01');
        $end_date = date('Y-m-t');
        $period_label = 'This Month';
        break;
    case 'quarter':
        $quarter = ceil(date('n') / 3);
        $start_date = date('Y-m-d', strtotime(date('Y') . '-' . (($quarter * 3) - 2) . '-01'));
        $end_date = date('Y-m-t', strtotime(date('Y') . '-' . ($quarter * 3) . '-01'));
        $period_label = 'This Quarter';
        break;
    case 'year':
        $start_date = date('Y-01-01');
        $end_date = date('Y-12-31');
        $period_label = 'This Year';
        break;
    case 'custom':
        if (empty($start_date)) $start_date = date('Y-m-01');
        if (empty($end_date)) $end_date = date('Y-m-t');
        $period_label = 'Custom Range';
        break;
    default:
        $period = 'month';
        $start_date = date('Y-m-01');
        $end_date = date('Y-m-t');
        $period_label = 'This Month';
}

// Get petty cash account
$petty_cash_sql = "SELECT a.account_id, a.account_name, a.account_currency_code 
                   FROM accounts a 
                   WHERE a.account_name LIKE '%petty cash%' 
                   AND (a.account_archived_at IS NULL OR a.account_archived_at = '')
                   AND a.account_status = 'active'
                   LIMIT 1";
$petty_cash_result = $mysqli->query($petty_cash_sql);
$petty_cash_account = $petty_cash_result->fetch_assoc();

if (!$petty_cash_account) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "No petty cash account found. Please configure a petty cash account first.";
    header("Location: petty_cash.php");
    exit;
}

$petty_cash_account_id = $petty_cash_account['account_id'];
$petty_cash_currency = $petty_cash_account['account_currency_code'];

// Get opening balance (balance before start date)
$opening_balance_sql = "SELECT 
    COALESCE(SUM(CASE WHEN jel.entry_type = 'credit' THEN jel.amount ELSE 0 END), 0) -
    COALESCE(SUM(CASE WHEN jel.entry_type = 'debit' THEN jel.amount ELSE 0 END), 0) as opening_balance
    FROM journal_entry_lines jel
    JOIN journal_entries je ON jel.entry_id = je.entry_id
    WHERE jel.account_id = ?
    AND je.entry_date < ?";
$opening_stmt = $mysqli->prepare($opening_balance_sql);
$opening_stmt->bind_param("is", $petty_cash_account_id, $start_date);
$opening_stmt->execute();
$opening_result = $opening_stmt->get_result();
$opening_data = $opening_result->fetch_assoc();
$opening_balance = $opening_data['opening_balance'] ?? 0;

// Get period transactions
$transactions_sql = "SELECT 
    jel.amount, 
    jel.entry_type,
    jel.description,
    jel.created_at,
    je.entry_date,
    je.reference_number,
    a.account_name as category,
    a.account_id as category_id,
    u.user_name as created_by,
    jh.journal_header_id
    FROM journal_entry_lines jel
    JOIN journal_entries je ON jel.entry_id = je.entry_id
    JOIN journal_headers jh ON je.journal_header_id = jh.journal_header_id
    JOIN accounts a ON jel.account_id = a.account_id
    LEFT JOIN users u ON je.created_by = u.user_id
    WHERE jel.account_id = ?
    AND DATE(je.entry_date) BETWEEN ? AND ?
    ";

if ($category_filter > 0) {
    $transactions_sql .= " AND jel.account_id = ?";
    $transactions_sql .= " ORDER BY je.entry_date DESC, je.entry_id DESC";
    $transactions_stmt = $mysqli->prepare($transactions_sql);
    $transactions_stmt->bind_param("issi", $petty_cash_account_id, $start_date, $end_date, $category_filter);
} else {
    $transactions_sql .= " ORDER BY je.entry_date DESC, je.entry_id DESC";
    $transactions_stmt = $mysqli->prepare($transactions_sql);
    $transactions_stmt->bind_param("iss", $petty_cash_account_id, $start_date, $end_date);
}

$transactions_stmt->execute();
$transactions_result = $transactions_stmt->get_result();
$transactions = [];

$total_debits = 0;
$total_credits = 0;

while ($transaction = $transactions_result->fetch_assoc()) {
    $transactions[] = $transaction;
    if ($transaction['entry_type'] == 'debit') {
        $total_debits += $transaction['amount'];
    } else {
        $total_credits += $transaction['amount'];
    }
}

$closing_balance = $opening_balance + $total_credits - $total_debits;
$net_change = $total_credits - $total_debits;

// Get category breakdown
$category_sql = "SELECT 
    a.account_id,
    a.account_name,
    a.account_number,
    COUNT(*) as transaction_count,
    SUM(CASE WHEN jel.entry_type = 'debit' THEN jel.amount ELSE 0 END) as total_debits,
    SUM(CASE WHEN jel.entry_type = 'credit' THEN jel.amount ELSE 0 END) as total_credits
    FROM journal_entry_lines jel
    JOIN journal_entries je ON jel.entry_id = je.entry_id
    JOIN accounts a ON jel.account_id = a.account_id
    WHERE jel.account_id = ?
    AND DATE(je.entry_date) BETWEEN ? AND ?
    GROUP BY a.account_id, a.account_name, a.account_number
    ORDER BY total_debits DESC";
$category_stmt = $mysqli->prepare($category_sql);
$category_stmt->bind_param("iss", $petty_cash_account_id, $start_date, $end_date);
$category_stmt->execute();
$category_result = $category_stmt->get_result();
$categories = [];

while ($category = $category_result->fetch_assoc()) {
    $categories[] = $category;
}

// Get monthly trend data
$trend_sql = "SELECT 
    DATE_FORMAT(je.entry_date, '%Y-%m') as month,
    COUNT(*) as transaction_count,
    SUM(CASE WHEN jel.entry_type = 'debit' THEN jel.amount ELSE 0 END) as total_debits,
    SUM(CASE WHEN jel.entry_type = 'credit' THEN jel.amount ELSE 0 END) as total_credits
    FROM journal_entry_lines jel
    JOIN journal_entries je ON jel.entry_id = je.entry_id
    WHERE jel.account_id = ?
    AND je.entry_date >= DATE_SUB(?, INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(je.entry_date, '%Y-%m')
    ORDER BY month DESC
    LIMIT 6";
$trend_stmt = $mysqli->prepare($trend_sql);
$trend_stmt->bind_param("is", $petty_cash_account_id, $end_date);
$trend_stmt->execute();
$trend_result = $trend_stmt->get_result();
$monthly_trends = [];

while ($trend = $trend_result->fetch_assoc()) {
    $monthly_trends[] = $trend;
}

// Handle PDF export
if ($export == 'pdf') {
    // Generate PDF report (you'll need to implement PDF generation)
    header("Location: generate_petty_cash_pdf.php?start_date=$start_date&end_date=$end_date&period=$period");
    exit;
}

// Get available categories for filter
$all_categories_sql = "SELECT DISTINCT a.account_id, a.account_name, a.account_number
                      FROM journal_entry_lines jel
                      JOIN accounts a ON jel.account_id = a.account_id
                      WHERE jel.account_id = ?
                      ORDER BY a.account_name";
$all_categories_stmt = $mysqli->prepare($all_categories_sql);
$all_categories_stmt->bind_param("i", $petty_cash_account_id);
$all_categories_stmt->execute();
$all_categories_result = $all_categories_stmt->get_result();
$available_categories = [];

while ($cat = $all_categories_result->fetch_assoc()) {
    $available_categories[] = $cat;
}
?>

<div class="card">
    <div class="card-header bg-info py-2">
        <h3 class="card-title mt-2 mb-0"><i class="fas fa-fw fa-chart-bar mr-2"></i>Petty Cash Reports</h3>
        <div class="card-tools">
            <a href="petty_cash.php" class="btn btn-light">
                <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
            </a>
        </div>
    </div>

    <div class="card-body">
        <!-- Report Filters -->
        <div class="card card-warning mb-4">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-filter mr-2"></i>Report Filters</h3>
            </div>
            <div class="card-body">
                <form method="GET" id="reportFilterForm" autocomplete="off">
                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Report Period</label>
                                <select class="form-control select2" name="period" id="periodSelect" onchange="this.form.submit()">
                                    <option value="today" <?php echo $period == 'today' ? 'selected' : ''; ?>>Today</option>
                                    <option value="week" <?php echo $period == 'week' ? 'selected' : ''; ?>>This Week</option>
                                    <option value="month" <?php echo $period == 'month' ? 'selected' : ''; ?>>This Month</option>
                                    <option value="quarter" <?php echo $period == 'quarter' ? 'selected' : ''; ?>>This Quarter</option>
                                    <option value="year" <?php echo $period == 'year' ? 'selected' : ''; ?>>This Year</option>
                                    <option value="custom" <?php echo $period == 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Start Date</label>
                                <input type="date" class="form-control" name="start_date" 
                                       value="<?php echo $start_date; ?>" 
                                       <?php echo $period != 'custom' ? 'readonly' : ''; ?>>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>End Date</label>
                                <input type="date" class="form-control" name="end_date" 
                                       value="<?php echo $end_date; ?>"
                                       <?php echo $period != 'custom' ? 'readonly' : ''; ?>>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Category Filter</label>
                                <select class="form-control select2" name="category" onchange="this.form.submit()">
                                    <option value="">All Categories</option>
                                    <?php foreach ($available_categories as $cat): ?>
                                        <option value="<?php echo $cat['account_id']; ?>" 
                                                <?php echo $category_filter == $cat['account_id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cat['account_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-12">
                            <div class="btn-group">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-sync-alt mr-2"></i>Apply Filters
                                </button>
                                <a href="reports_petty_cash.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-undo mr-2"></i>Reset
                                </a>
                                <div class="btn-group">
                                    <button type="button" class="btn btn-success dropdown-toggle" data-toggle="dropdown">
                                        <i class="fas fa-download mr-2"></i>Export
                                    </button>
                                    <div class="dropdown-menu">
                                        <a class="dropdown-item" href="?<?php echo http_build_query($_GET); ?>&export=pdf">
                                            <i class="fas fa-file-pdf mr-2"></i>Export as PDF
                                        </a>
                                        <a class="dropdown-item" href="?<?php echo http_build_query($_GET); ?>&export=csv">
                                            <i class="fas fa-file-csv mr-2"></i>Export as CSV
                                        </a>
                                        <a class="dropdown-item" href="?<?php echo http_build_query($_GET); ?>&export=excel">
                                            <i class="fas fa-file-excel mr-2"></i>Export as Excel
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-primary"><i class="fas fa-calendar"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Report Period</span>
                        <span class="info-box-number"><?php echo $period_label; ?></span>
                        <small class="text-muted"><?php echo date('M j, Y', strtotime($start_date)); ?> to <?php echo date('M j, Y', strtotime($end_date)); ?></small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-success"><i class="fas fa-arrow-down"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Income</span>
                        <span class="info-box-number text-success">
                            <?php echo numfmt_format_currency($currency_format, $total_credits, $petty_cash_currency); ?>
                        </span>
                        <small class="text-muted">Replenishments</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-danger"><i class="fas fa-arrow-up"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Expenses</span>
                        <span class="info-box-number text-danger">
                            <?php echo numfmt_format_currency($currency_format, $total_debits, $petty_cash_currency); ?>
                        </span>
                        <small class="text-muted">Payments</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-info"><i class="fas fa-balance-scale"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Net Change</span>
                        <span class="info-box-number <?php echo $net_change >= 0 ? 'text-success' : 'text-danger'; ?>">
                            <?php echo numfmt_format_currency($currency_format, $net_change, $petty_cash_currency); ?>
                        </span>
                        <small class="text-muted">Period change</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Balance Summary -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card card-success">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-wallet mr-2"></i>Balance Summary</h3>
                    </div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between mb-2">
                            <span>Opening Balance:</span>
                            <strong><?php echo numfmt_format_currency($currency_format, $opening_balance, $petty_cash_currency); ?></strong>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span>+ Total Income:</span>
                            <strong class="text-success"><?php echo numfmt_format_currency($currency_format, $total_credits, $petty_cash_currency); ?></strong>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span>- Total Expenses:</span>
                            <strong class="text-danger"><?php echo numfmt_format_currency($currency_format, $total_debits, $petty_cash_currency); ?></strong>
                        </div>
                        <hr>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="font-weight-bold">Closing Balance:</span>
                            <strong class="font-weight-bold <?php echo $closing_balance >= 0 ? 'text-success' : 'text-danger'; ?>">
                                <?php echo numfmt_format_currency($currency_format, $closing_balance, $petty_cash_currency); ?>
                            </strong>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Category Breakdown -->
            <div class="col-md-8">
                <div class="card card-primary">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-chart-pie mr-2"></i>Expense Categories</h3>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Category</th>
                                        <th class="text-center">Transactions</th>
                                        <th class="text-right">Total Amount</th>
                                        <th class="text-right">Percentage</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($categories)): ?>
                                        <?php foreach ($categories as $category): ?>
                                            <?php if ($category['total_debits'] > 0): ?>
                                                <tr>
                                                    <td>
                                                        <div class="font-weight-bold"><?php echo htmlspecialchars($category['account_name']); ?></div>
                                                        <small class="text-muted"><?php echo htmlspecialchars($category['account_number']); ?></small>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="badge badge-info"><?php echo $category['transaction_count']; ?></span>
                                                    </td>
                                                    <td class="text-right font-weight-bold text-danger">
                                                        <?php echo numfmt_format_currency($currency_format, $category['total_debits'], $petty_cash_currency); ?>
                                                    </td>
                                                    <td class="text-right">
                                                        <?php echo $total_debits > 0 ? number_format(($category['total_debits'] / $total_debits) * 100, 1) : 0; ?>%
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4" class="text-center text-muted py-3">
                                                <i class="fas fa-chart-pie fa-2x mb-2"></i>
                                                <p>No expense data available for this period</p>
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

        <!-- Monthly Trend -->
        <?php if (!empty($monthly_trends)): ?>
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card card-info">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-chart-line mr-2"></i>6-Month Trend</h3>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Month</th>
                                        <th class="text-center">Transactions</th>
                                        <th class="text-right">Total Income</th>
                                        <th class="text-right">Total Expenses</th>
                                        <th class="text-right">Net Change</th>
                                        <th style="width: 200px;">Trend</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($monthly_trends as $trend): ?>
                                        <?php 
                                        $net_change_trend = $trend['total_credits'] - $trend['total_debits'];
                                        $max_amount = max($trend['total_debits'], $trend['total_credits']);
                                        $debit_percent = $max_amount > 0 ? ($trend['total_debits'] / $max_amount) * 100 : 0;
                                        $credit_percent = $max_amount > 0 ? ($trend['total_credits'] / $max_amount) * 100 : 0;
                                        ?>
                                        <tr>
                                            <td class="font-weight-bold"><?php echo date('F Y', strtotime($trend['month'] . '-01')); ?></td>
                                            <td class="text-center">
                                                <span class="badge badge-secondary"><?php echo $trend['transaction_count']; ?></span>
                                            </td>
                                            <td class="text-right text-success font-weight-bold">
                                                <?php echo numfmt_format_currency($currency_format, $trend['total_credits'], $petty_cash_currency); ?>
                                            </td>
                                            <td class="text-right text-danger font-weight-bold">
                                                <?php echo numfmt_format_currency($currency_format, $trend['total_debits'], $petty_cash_currency); ?>
                                            </td>
                                            <td class="text-right font-weight-bold <?php echo $net_change_trend >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                <?php echo numfmt_format_currency($currency_format, $net_change_trend, $petty_cash_currency); ?>
                                            </td>
                                            <td>
                                                <div class="progress" style="height: 20px;">
                                                    <div class="progress-bar bg-danger" style="width: <?php echo $debit_percent; ?>%" 
                                                         title="Expenses: <?php echo numfmt_format_currency($currency_format, $trend['total_debits'], $petty_cash_currency); ?>">
                                                    </div>
                                                    <div class="progress-bar bg-success" style="width: <?php echo $credit_percent; ?>%" 
                                                         title="Income: <?php echo numfmt_format_currency($currency_format, $trend['total_credits'], $petty_cash_currency); ?>">
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Detailed Transactions -->
        <div class="card card-warning">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title mb-0"><i class="fas fa-list-alt mr-2"></i>Detailed Transactions</h3>
                <span class="badge badge-primary"><?php echo count($transactions); ?> transactions</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-striped mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th>Date</th>
                                <th>Description</th>
                                <th>Reference</th>
                                <th>Category</th>
                                <th>Type</th>
                                <th class="text-right">Amount</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($transactions)): ?>
                                <?php foreach ($transactions as $transaction): ?>
                                    <tr>
                                        <td>
                                            <div class="font-weight-bold"><?php echo date('M j, Y', strtotime($transaction['entry_date'])); ?></div>
                                            <small class="text-muted"><?php echo date('H:i', strtotime($transaction['entry_date'])); ?></small>
                                        </td>
                                        <td>
                                            <div class="font-weight-bold"><?php echo htmlspecialchars($transaction['description']); ?></div>
                                            <?php if ($transaction['created_by']): ?>
                                                <small class="text-muted">By: <?php echo htmlspecialchars($transaction['created_by']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge badge-light"><?php echo htmlspecialchars($transaction['reference_number']); ?></span>
                                        </td>
                                        <td>
                                            <span class="badge badge-secondary"><?php echo htmlspecialchars($transaction['category']); ?></span>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?php echo $transaction['entry_type'] == 'debit' ? 'danger' : 'success'; ?>">
                                                <?php echo $transaction['entry_type'] == 'debit' ? 'Expense' : 'Income'; ?>
                                            </span>
                                        </td>
                                        <td class="text-right font-weight-bold <?php echo $transaction['entry_type'] == 'debit' ? 'text-danger' : 'text-success'; ?>">
                                            <?php echo numfmt_format_currency($currency_format, $transaction['amount'], $petty_cash_currency); ?>
                                        </td>
                                        <td class="text-center">
                                            <a href="journal_entry_view.php?id=<?php echo $transaction['journal_header_id']; ?>" 
                                               class="btn btn-info btn-sm" title="View Journal Entry">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4">
                                        <i class="fas fa-receipt fa-3x text-muted mb-3"></i>
                                        <h5>No Transactions Found</h5>
                                        <p class="text-muted mb-0">No petty cash transactions found for the selected period.</p>
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

<script>
$(document).ready(function() {
    // Initialize Select2
    $('.select2').select2({
        width: '100%'
    });

    // Handle period selection changes
    $('#periodSelect').change(function() {
        if ($(this).val() === 'custom') {
            $('input[name="start_date"], input[name="end_date"]').prop('readonly', false);
        } else {
            $('input[name="start_date"], input[name="end_date"]').prop('readonly', true);
        }
    });

    // Auto-submit form when period changes (for quick navigation)
    $('select[name="period"]').change(function() {
        if ($(this).val() !== 'custom') {
            $('#reportFilterForm').submit();
        }
    });

    // Print report
    $('#printReport').click(function() {
        window.print();
    });
});

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + P to print
    if (e.ctrlKey && e.keyCode === 80) {
        e.preventDefault();
        window.print();
    }
    // Escape to go back
    if (e.keyCode === 27) {
        window.location.href = 'petty_cash.php';
    }
});
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
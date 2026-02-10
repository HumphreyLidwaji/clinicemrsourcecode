<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Check permissions
if (!isset($_SESSION['user_id'])) {
    header("Location: /login.php");
    exit;
}

// Get date parameters
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');
$period = $_GET['period'] ?? 'monthly';
$compare_with = $_GET['compare'] ?? 'previous_period';

// Calculate date ranges based on period
switch ($period) {
    case 'quarterly':
        $quarter = ceil(date('n', strtotime($start_date)) / 3);
        $start_date = date('Y-m-01', strtotime(date('Y') . '-' . (($quarter - 1) * 3 + 1) . '-01'));
        $end_date = date('Y-m-t', strtotime(date('Y') . '-' . ($quarter * 3) . '-01'));
        break;
    case 'yearly':
        $start_date = date('Y-01-01', strtotime($start_date));
        $end_date = date('Y-12-31', strtotime($end_date));
        break;
}

// Calculate comparison period dates
$previous_start_date = date('Y-m-d', strtotime($start_date . ' -1 ' . $period));
$previous_end_date = date('Y-m-d', strtotime($end_date . ' -1 ' . $period));

// Check if journal system exists
$table_check = $mysqli->query("SHOW TABLES LIKE 'journal_entries'")->num_rows;
$use_journal = ($table_check > 0);

// Function to get income statement data
function getIncomeStatementData($mysqli, $start_date, $end_date, $use_journal = false) {
    $data = [
        'revenue' => [],
        'expenses' => [],
        'totals' => [
            'revenue' => 0,
            'expenses' => 0,
            'gross_profit' => 0,
            'net_income' => 0
        ]
    ];
    
    if ($use_journal) {
        // Get revenue from journal entries
        $revenue_sql = "SELECT 
                            a.account_id,
                            a.account_number,
                            a.account_name,
                            a.account_subtype,
                            COALESCE(SUM(jel.credit_amount) - SUM(jel.debit_amount), 0) as amount
                        FROM accounts a
                        LEFT JOIN journal_entry_lines jel ON a.account_id = jel.account_id
                        LEFT JOIN journal_entries je ON jel.journal_entry_id = je.journal_entry_id
                        WHERE a.account_type = 'revenue'
                        AND a.is_active = 1
                        AND je.status = 'posted'
                        AND je.transaction_date BETWEEN ? AND ?
                        GROUP BY a.account_id, a.account_number, a.account_name, a.account_subtype
                        HAVING amount != 0
                        ORDER BY a.account_number";
        
        $revenue_stmt = $mysqli->prepare($revenue_sql);
        $revenue_stmt->bind_param("ss", $start_date, $end_date);
        $revenue_stmt->execute();
        $revenue_result = $revenue_stmt->get_result();
        
        while ($row = $revenue_result->fetch_assoc()) {
            $data['revenue'][] = [
                'id' => $row['account_id'],
                'number' => $row['account_number'],
                'name' => $row['account_name'],
                'subtype' => $row['account_subtype'],
                'amount' => floatval($row['amount'])
            ];
            $data['totals']['revenue'] += floatval($row['amount']);
        }
        
        // Get expenses from journal entries
        $expense_sql = "SELECT 
                            a.account_id,
                            a.account_number,
                            a.account_name,
                            a.account_subtype,
                            COALESCE(SUM(jel.debit_amount) - SUM(jel.credit_amount), 0) as amount
                        FROM accounts a
                        LEFT JOIN journal_entry_lines jel ON a.account_id = jel.account_id
                        LEFT JOIN journal_entries je ON jel.journal_entry_id = je.journal_entry_id
                        WHERE a.account_type = 'expense'
                        AND a.is_active = 1
                        AND je.status = 'posted'
                        AND je.transaction_date BETWEEN ? AND ?
                        GROUP BY a.account_id, a.account_number, a.account_name, a.account_subtype
                        HAVING amount != 0
                        ORDER BY a.account_number";
        
        $expense_stmt = $mysqli->prepare($expense_sql);
        $expense_stmt->bind_param("ss", $start_date, $end_date);
        $expense_stmt->execute();
        $expense_result = $expense_stmt->get_result();
        
        while ($row = $expense_result->fetch_assoc()) {
            $data['expenses'][] = [
                'id' => $row['account_id'],
                'number' => $row['account_number'],
                'name' => $row['account_name'],
                'subtype' => $row['account_subtype'],
                'amount' => floatval($row['amount'])
            ];
            $data['totals']['expenses'] += floatval($row['amount']);
        }
    } else {
        // Get revenue from account balances
        $revenue_sql = "SELECT 
                            account_id,
                            account_number,
                            account_name,
                            account_subtype,
                            COALESCE(balance, 0) as amount
                        FROM accounts
                        WHERE account_type = 'revenue'
                        AND is_active = 1
                        AND balance != 0
                        ORDER BY account_number";
        
        $revenue_result = $mysqli->query($revenue_sql);
        
        while ($row = $revenue_result->fetch_assoc()) {
            $data['revenue'][] = [
                'id' => $row['account_id'],
                'number' => $row['account_number'],
                'name' => $row['account_name'],
                'subtype' => $row['account_subtype'],
                'amount' => floatval($row['amount'])
            ];
            $data['totals']['revenue'] += floatval($row['amount']);
        }
        
        // Get expenses from account balances
        $expense_sql = "SELECT 
                            account_id,
                            account_number,
                            account_name,
                            account_subtype,
                            COALESCE(balance, 0) as amount
                        FROM accounts
                        WHERE account_type = 'expense'
                        AND is_active = 1
                        AND balance != 0
                        ORDER BY account_number";
        
        $expense_result = $mysqli->query($expense_sql);
        
        while ($row = $expense_result->fetch_assoc()) {
            $data['expenses'][] = [
                'id' => $row['account_id'],
                'number' => $row['account_number'],
                'name' => $row['account_name'],
                'subtype' => $row['account_subtype'],
                'amount' => floatval($row['amount'])
            ];
            $data['totals']['expenses'] += floatval($row['amount']);
        }
    }
    
    // Calculate gross profit and net income
    $data['totals']['gross_profit'] = $data['totals']['revenue'];
    $data['totals']['net_income'] = $data['totals']['revenue'] - $data['totals']['expenses'];
    
    return $data;
}

// Get current period data
$current_data = getIncomeStatementData($mysqli, $start_date, $end_date, $use_journal);

// Get comparison data
$comparison_data = [];
if ($compare_with === 'previous_period') {
    $comparison_data = getIncomeStatementData($mysqli, $previous_start_date, $previous_end_date, $use_journal);
} elseif ($compare_with === 'budget') {
    // You would typically get budget data from a budgets table
    // This is a placeholder implementation
    $comparison_data = [
        'revenue' => [],
        'expenses' => [],
        'totals' => [
            'revenue' => $current_data['totals']['revenue'] * 1.1, // 10% higher than actual
            'expenses' => $current_data['totals']['expenses'] * 0.9, // 10% lower than actual
            'gross_profit' => 0,
            'net_income' => 0
        ]
    ];
    $comparison_data['totals']['gross_profit'] = $comparison_data['totals']['revenue'];
    $comparison_data['totals']['net_income'] = $comparison_data['totals']['revenue'] - $comparison_data['totals']['expenses'];
}

// Get month-over-month data for chart
$monthly_data = [];
for ($i = 5; $i >= 0; $i--) {
    $month_start = date('Y-m-01', strtotime("-$i months"));
    $month_end = date('Y-m-t', strtotime($month_start));
    $month_label = date('M Y', strtotime($month_start));
    $monthly_data[$month_label] = getIncomeStatementData($mysqli, $month_start, $month_end, $use_journal);
}
?>

<div class="card">
    <div class="card-header bg-success py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-chart-line mr-2"></i>Income Statement Report
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
                    <label class="font-weight-bold">Start Date</label>
                    <input type="date" class="form-control" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" onchange="this.form.submit()">
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    <label class="font-weight-bold">End Date</label>
                    <input type="date" class="form-control" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" onchange="this.form.submit()">
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    <label class="font-weight-bold">Period</label>
                    <select class="form-control select2" name="period" onchange="this.form.submit()">
                        <option value="monthly" <?php echo $period == 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                        <option value="quarterly" <?php echo $period == 'quarterly' ? 'selected' : ''; ?>>Quarterly</option>
                        <option value="yearly" <?php echo $period == 'yearly' ? 'selected' : ''; ?>>Yearly</option>
                    </select>
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    <label class="font-weight-bold">Compare With</label>
                    <select class="form-control select2" name="compare" onchange="this.form.submit()">
                        <option value="none" <?php echo $compare_with == 'none' ? 'selected' : ''; ?>>No Comparison</option>
                        <option value="previous_period" <?php echo $compare_with == 'previous_period' ? 'selected' : ''; ?>>Previous Period</option>
                        <option value="budget" <?php echo $compare_with == 'budget' ? 'selected' : ''; ?>>Budget</option>
                    </select>
                </div>
            </div>
        </form>
    </div>
    
    <!-- Summary Statistics -->
    <div class="card-body border-bottom">
        <div class="row text-center">
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-info"><i class="fas fa-money-bill-wave"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Revenue</span>
                        <span class="info-box-number"><?php echo numfmt_format_currency($currency_format, $current_data['totals']['revenue'], $session_company_currency); ?></span>
                        <?php if ($compare_with !== 'none'): ?>
                            <?php 
                            $change = $current_data['totals']['revenue'] - $comparison_data['totals']['revenue'];
                            $percent = $comparison_data['totals']['revenue'] > 0 ? ($change / $comparison_data['totals']['revenue'] * 100) : 0;
                            $trend_class = $change >= 0 ? 'text-success' : 'text-danger';
                            $trend_icon = $change >= 0 ? 'fa-arrow-up' : 'fa-arrow-down';
                            ?>
                            <small class="<?php echo $trend_class; ?>">
                                <i class="fas <?php echo $trend_icon; ?> mr-1"></i>
                                <?php echo numfmt_format_currency($currency_format, abs($change), $session_company_currency); ?>
                                (<?php echo number_format(abs($percent), 1); ?>%)
                            </small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-danger"><i class="fas fa-wallet"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Expenses</span>
                        <span class="info-box-number"><?php echo numfmt_format_currency($currency_format, $current_data['totals']['expenses'], $session_company_currency); ?></span>
                        <?php if ($compare_with !== 'none'): ?>
                            <?php 
                            $change = $current_data['totals']['expenses'] - $comparison_data['totals']['expenses'];
                            $percent = $comparison_data['totals']['expenses'] > 0 ? ($change / $comparison_data['totals']['expenses'] * 100) : 0;
                            $trend_class = $change <= 0 ? 'text-success' : 'text-danger';
                            $trend_icon = $change <= 0 ? 'fa-arrow-down' : 'fa-arrow-up';
                            ?>
                            <small class="<?php echo $trend_class; ?>">
                                <i class="fas <?php echo $trend_icon; ?> mr-1"></i>
                                <?php echo numfmt_format_currency($currency_format, abs($change), $session_company_currency); ?>
                                (<?php echo number_format(abs($percent), 1); ?>%)
                            </small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-success"><i class="fas fa-chart-line"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Gross Profit</span>
                        <span class="info-box-number"><?php echo numfmt_format_currency($currency_format, $current_data['totals']['gross_profit'], $session_company_currency); ?></span>
                        <?php if ($compare_with !== 'none'): ?>
                            <?php 
                            $change = $current_data['totals']['gross_profit'] - $comparison_data['totals']['gross_profit'];
                            $percent = $comparison_data['totals']['gross_profit'] > 0 ? ($change / $comparison_data['totals']['gross_profit'] * 100) : 0;
                            $trend_class = $change >= 0 ? 'text-success' : 'text-danger';
                            $trend_icon = $change >= 0 ? 'fa-arrow-up' : 'fa-arrow-down';
                            ?>
                            <small class="<?php echo $trend_class; ?>">
                                <i class="fas <?php echo $trend_icon; ?> mr-1"></i>
                                <?php echo numfmt_format_currency($currency_format, abs($change), $session_company_currency); ?>
                                (<?php echo number_format(abs($percent), 1); ?>%)
                            </small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-primary"><i class="fas fa-balance-scale"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Net Income</span>
                        <span class="info-box-number"><?php echo numfmt_format_currency($currency_format, $current_data['totals']['net_income'], $session_company_currency); ?></span>
                        <?php if ($compare_with !== 'none'): ?>
                            <?php 
                            $change = $current_data['totals']['net_income'] - $comparison_data['totals']['net_income'];
                            $percent = $comparison_data['totals']['net_income'] > 0 ? ($change / $comparison_data['totals']['net_income'] * 100) : 0;
                            $trend_class = $change >= 0 ? 'text-success' : 'text-danger';
                            $trend_icon = $change >= 0 ? 'fa-arrow-up' : 'fa-arrow-down';
                            ?>
                            <small class="<?php echo $trend_class; ?>">
                                <i class="fas <?php echo $trend_icon; ?> mr-1"></i>
                                <?php echo numfmt_format_currency($currency_format, abs($change), $session_company_currency); ?>
                                (<?php echo number_format(abs($percent), 1); ?>%)
                            </small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Profitability Metrics -->
        <div class="row mt-3">
            <div class="col-md-12">
                <div class="alert <?php echo $current_data['totals']['net_income'] >= 0 ? 'alert-success' : 'alert-danger'; ?>">
                    <div class="row align-items-center">
                        <div class="col-md-9">
                            <i class="fas fa-<?php echo $current_data['totals']['net_income'] >= 0 ? 'check-circle' : 'exclamation-triangle'; ?> mr-2"></i>
                            <strong>Profitability Status:</strong>
                            <span class="ml-2">
                                <?php if ($current_data['totals']['net_income'] >= 0): ?>
                                    Operating at a profit of <?php echo numfmt_format_currency($currency_format, $current_data['totals']['net_income'], $session_company_currency); ?>
                                <?php else: ?>
                                    Operating at a loss of <?php echo numfmt_format_currency($currency_format, abs($current_data['totals']['net_income']), $session_company_currency); ?>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="col-md-3 text-right">
                            <strong>
                                Profit Margin: 
                                <?php 
                                $profit_margin = $current_data['totals']['revenue'] > 0 ? 
                                    ($current_data['totals']['net_income'] / $current_data['totals']['revenue'] * 100) : 0;
                                echo number_format($profit_margin, 1) . '%';
                                ?>
                            </strong>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card-body">
        <!-- Income Statement Report -->
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0">
                            <i class="fas fa-file-invoice-dollar mr-2"></i>
                            Income Statement for period <?php echo date('F j, Y', strtotime($start_date)); ?> to <?php echo date('F j, Y', strtotime($end_date)); ?>
                        </h4>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th colspan="2" class="bg-info text-white">REVENUE</th>
                                        <?php if ($compare_with !== 'none'): ?>
                                        <th class="text-right bg-info text-white"><?php echo $compare_with == 'budget' ? 'Budget' : 'Previous'; ?></th>
                                        <th class="text-right bg-info text-white">Variance</th>
                                        <?php endif; ?>
                                        <th class="text-right bg-info text-white">Current</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $revenue_subtotals = [];
                                    foreach ($current_data['revenue'] as $account):
                                        $subtype = $account['subtype'] ?: 'Other Revenue';
                                        if (!isset($revenue_subtotals[$subtype])) {
                                            $revenue_subtotals[$subtype] = 0;
                                        }
                                        $revenue_subtotals[$subtype] += $account['amount'];
                                        
                                        // Find matching account in comparison data
                                        $comp_account = null;
                                        if ($compare_with !== 'none') {
                                            foreach ($comparison_data['revenue'] as $comp) {
                                                if ($comp['id'] == $account['id']) {
                                                    $comp_account = $comp;
                                                    break;
                                                }
                                            }
                                        }
                                    ?>
                                    <tr>
                                        <td width="10%" class="text-muted"><?php echo htmlspecialchars($account['number']); ?></td>
                                        <td width="40%"><?php echo htmlspecialchars($account['name']); ?></td>
                                        <?php if ($compare_with !== 'none'): ?>
                                        <td width="15%" class="text-right text-muted">
                                            <?php echo $comp_account ? numfmt_format_currency($currency_format, $comp_account['amount'], $session_company_currency) : '-'; ?>
                                        </td>
                                        <td width="15%" class="text-right">
                                            <?php if ($comp_account): 
                                                $variance = $account['amount'] - $comp_account['amount'];
                                                $variance_percent = $comp_account['amount'] > 0 ? ($variance / $comp_account['amount'] * 100) : 0;
                                                $trend_class = $variance >= 0 ? 'text-success' : 'text-danger';
                                                $trend_icon = $variance >= 0 ? 'fa-arrow-up' : 'fa-arrow-down';
                                            ?>
                                            <span class="<?php echo $trend_class; ?>">
                                                <i class="fas <?php echo $trend_icon; ?> mr-1"></i>
                                                <?php echo numfmt_format_currency($currency_format, abs($variance), $session_company_currency); ?>
                                                (<?php echo number_format(abs($variance_percent), 1); ?>%)
                                            </span>
                                            <?php else: ?>
                                            -
                                            <?php endif; ?>
                                        </td>
                                        <?php endif; ?>
                                        <td width="<?php echo $compare_with !== 'none' ? '20%' : '50%'; ?>" class="text-right font-weight-bold">
                                            <?php echo numfmt_format_currency($currency_format, $account['amount'], $session_company_currency); ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    
                                    <!-- Revenue subtotals -->
                                    <?php foreach ($revenue_subtotals as $subtype => $subtotal): ?>
                                    <tr class="bg-light">
                                        <td colspan="<?php echo $compare_with !== 'none' ? 4 : 2; ?>" class="font-weight-bold"><?php echo htmlspecialchars($subtype); ?></td>
                                        <td class="text-right font-weight-bold"><?php echo numfmt_format_currency($currency_format, $subtotal, $session_company_currency); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    
                                    <!-- Total Revenue -->
                                    <tr class="bg-info text-white">
                                        <td colspan="<?php echo $compare_with !== 'none' ? 4 : 2; ?>" class="font-weight-bold">TOTAL REVENUE</td>
                                        <td class="text-right font-weight-bold"><?php echo numfmt_format_currency($currency_format, $current_data['totals']['revenue'], $session_company_currency); ?></td>
                                    </tr>
                                    
                                    <!-- Expenses -->
                                    <thead class="bg-light">
                                        <tr>
                                            <th colspan="2" class="bg-danger text-white">EXPENSES</th>
                                            <?php if ($compare_with !== 'none'): ?>
                                            <th class="text-right bg-danger text-white"><?php echo $compare_with == 'budget' ? 'Budget' : 'Previous'; ?></th>
                                            <th class="text-right bg-danger text-white">Variance</th>
                                            <?php endif; ?>
                                            <th class="text-right bg-danger text-white">Current</th>
                                        </tr>
                                    </thead>
                                    
                                    <?php 
                                    $expense_subtotals = [];
                                    foreach ($current_data['expenses'] as $account):
                                        $subtype = $account['subtype'] ?: 'Other Expenses';
                                        if (!isset($expense_subtotals[$subtype])) {
                                            $expense_subtotals[$subtype] = 0;
                                        }
                                        $expense_subtotals[$subtype] += $account['amount'];
                                        
                                        // Find matching account in comparison data
                                        $comp_account = null;
                                        if ($compare_with !== 'none') {
                                            foreach ($comparison_data['expenses'] as $comp) {
                                                if ($comp['id'] == $account['id']) {
                                                    $comp_account = $comp;
                                                    break;
                                                }
                                            }
                                        }
                                    ?>
                                    <tr>
                                        <td class="text-muted"><?php echo htmlspecialchars($account['number']); ?></td>
                                        <td><?php echo htmlspecialchars($account['name']); ?></td>
                                        <?php if ($compare_with !== 'none'): ?>
                                        <td class="text-right text-muted">
                                            <?php echo $comp_account ? numfmt_format_currency($currency_format, $comp_account['amount'], $session_company_currency) : '-'; ?>
                                        </td>
                                        <td class="text-right">
                                            <?php if ($comp_account): 
                                                $variance = $account['amount'] - $comp_account['amount'];
                                                $variance_percent = $comp_account['amount'] > 0 ? ($variance / $comp_account['amount'] * 100) : 0;
                                                $trend_class = $variance <= 0 ? 'text-success' : 'text-danger';
                                                $trend_icon = $variance <= 0 ? 'fa-arrow-down' : 'fa-arrow-up';
                                            ?>
                                            <span class="<?php echo $trend_class; ?>">
                                                <i class="fas <?php echo $trend_icon; ?> mr-1"></i>
                                                <?php echo numfmt_format_currency($currency_format, abs($variance), $session_company_currency); ?>
                                                (<?php echo number_format(abs($variance_percent), 1); ?>%)
                                            </span>
                                            <?php else: ?>
                                            -
                                            <?php endif; ?>
                                        </td>
                                        <?php endif; ?>
                                        <td class="text-right font-weight-bold"><?php echo numfmt_format_currency($currency_format, $account['amount'], $session_company_currency); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    
                                    <!-- Expense subtotals -->
                                    <?php foreach ($expense_subtotals as $subtype => $subtotal): ?>
                                    <tr class="bg-light">
                                        <td colspan="<?php echo $compare_with !== 'none' ? 4 : 2; ?>" class="font-weight-bold"><?php echo htmlspecialchars($subtype); ?></td>
                                        <td class="text-right font-weight-bold"><?php echo numfmt_format_currency($currency_format, $subtotal, $session_company_currency); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    
                                    <!-- Total Expenses -->
                                    <tr class="bg-danger text-white">
                                        <td colspan="<?php echo $compare_with !== 'none' ? 4 : 2; ?>" class="font-weight-bold">TOTAL EXPENSES</td>
                                        <td class="text-right font-weight-bold"><?php echo numfmt_format_currency($currency_format, $current_data['totals']['expenses'], $session_company_currency); ?></td>
                                    </tr>
                                    
                                    <!-- Net Income -->
                                    <tr class="bg-success text-white">
                                        <td colspan="<?php echo $compare_with !== 'none' ? 4 : 2; ?>" class="font-weight-bold">NET INCOME</td>
                                        <td class="text-right font-weight-bold"><?php echo numfmt_format_currency($currency_format, $current_data['totals']['net_income'], $session_company_currency); ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Trend Analysis -->
        <?php if (count($monthly_data) > 1): ?>
        <div class="row mt-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-chart-line mr-2"></i>Trend Analysis (Last 6 Months)</h4>
                    </div>
                    <div class="card-body">
                        <canvas id="trendChart" height="100"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Key Metrics -->
        <div class="row mt-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-chart-pie mr-2"></i>Key Performance Indicators</h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3">
                                <div class="text-center p-3 border rounded">
                                    <div class="h4 text-primary">
                                        <?php 
                                        $profit_margin = $current_data['totals']['revenue'] > 0 ? 
                                            ($current_data['totals']['net_income'] / $current_data['totals']['revenue'] * 100) : 0;
                                        echo number_format($profit_margin, 1) . '%';
                                        ?>
                                    </div>
                                    <small class="text-muted">Net Profit Margin</small>
                                    <div class="small mt-1">
                                        <?php 
                                        if ($profit_margin > 20) {
                                            echo '<span class="badge badge-success">Excellent</span>';
                                        } elseif ($profit_margin > 10) {
                                            echo '<span class="badge badge-warning">Good</span>';
                                        } elseif ($profit_margin > 0) {
                                            echo '<span class="badge badge-info">Adequate</span>';
                                        } else {
                                            echo '<span class="badge badge-danger">Loss</span>';
                                        }
                                        ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-center p-3 border rounded">
                                    <div class="h4 text-success">
                                        <?php 
                                        $expense_ratio = $current_data['totals']['revenue'] > 0 ? 
                                            ($current_data['totals']['expenses'] / $current_data['totals']['revenue'] * 100) : 0;
                                        echo number_format($expense_ratio, 1) . '%';
                                        ?>
                                    </div>
                                    <small class="text-muted">Expense Ratio</small>
                                    <div class="small mt-1">
                                        <?php 
                                        if ($expense_ratio < 70) {
                                            echo '<span class="badge badge-success">Efficient</span>';
                                        } elseif ($expense_ratio < 85) {
                                            echo '<span class="badge badge-warning">Moderate</span>';
                                        } else {
                                            echo '<span class="badge badge-danger">High</span>';
                                        }
                                        ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-center p-3 border rounded">
                                    <div class="h4 text-info">
                                        <?php 
                                        $revenue_growth = 0;
                                        if ($compare_with !== 'none' && $comparison_data['totals']['revenue'] > 0) {
                                            $revenue_growth = (($current_data['totals']['revenue'] - $comparison_data['totals']['revenue']) / $comparison_data['totals']['revenue'] * 100);
                                        }
                                        echo number_format($revenue_growth, 1) . '%';
                                        ?>
                                    </div>
                                    <small class="text-muted">Revenue Growth</small>
                                    <div class="small mt-1">
                                        <?php 
                                        if ($revenue_growth > 10) {
                                            echo '<span class="badge badge-success">Strong</span>';
                                        } elseif ($revenue_growth > 0) {
                                            echo '<span class="badge badge-warning">Growing</span>';
                                        } else {
                                            echo '<span class="badge badge-danger">Declining</span>';
                                        }
                                        ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-center p-3 border rounded">
                                    <div class="h4 text-warning">
                                        <?php 
                                        $expense_growth = 0;
                                        if ($compare_with !== 'none' && $comparison_data['totals']['expenses'] > 0) {
                                            $expense_growth = (($current_data['totals']['expenses'] - $comparison_data['totals']['expenses']) / $comparison_data['totals']['expenses'] * 100);
                                        }
                                        echo number_format($expense_growth, 1) . '%';
                                        ?>
                                    </div>
                                    <small class="text-muted">Expense Growth</small>
                                    <div class="small mt-1">
                                        <?php 
                                        if ($expense_growth < $revenue_growth) {
                                            echo '<span class="badge badge-success">Controlled</span>';
                                        } elseif ($expense_growth > $revenue_growth) {
                                            echo '<span class="badge badge-danger">High</span>';
                                        } else {
                                            echo '<span class="badge badge-warning">Matched</span>';
                                        }
                                        ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
$(document).ready(function() {
    $('.select2').select2();
    
    <?php if (count($monthly_data) > 1): ?>
    // Initialize trend chart
    const trendCtx = document.getElementById('trendChart').getContext('2d');
    const trendChart = new Chart(trendCtx, {
        type: 'line',
        data: {
            labels: [<?php echo "'" . implode("','", array_keys($monthly_data)) . "'"; ?>],
            datasets: [
                {
                    label: 'Revenue',
                    data: [<?php echo implode(',', array_map(function($item) { return $item['totals']['revenue']; }, $monthly_data)); ?>],
                    borderColor: '#17a2b8',
                    backgroundColor: 'rgba(23, 162, 184, 0.1)',
                    fill: true,
                    tension: 0.4
                },
                {
                    label: 'Expenses',
                    data: [<?php echo implode(',', array_map(function($item) { return $item['totals']['expenses']; }, $monthly_data)); ?>],
                    borderColor: '#dc3545',
                    backgroundColor: 'rgba(220, 53, 69, 0.1)',
                    fill: true,
                    tension: 0.4
                },
                {
                    label: 'Net Income',
                    data: [<?php echo implode(',', array_map(function($item) { return $item['totals']['net_income']; }, $monthly_data)); ?>],
                    borderColor: '#28a745',
                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                    fill: true,
                    tension: 0.4
                }
            ]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    position: 'top',
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            if (label) {
                                label += ': ';
                            }
                            label += new Intl.NumberFormat('en-US', {
                                style: 'currency',
                                currency: '<?php echo $session_company_currency; ?>'
                            }).format(context.parsed.y);
                            return label;
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return new Intl.NumberFormat('en-US', {
                                style: 'currency',
                                currency: '<?php echo $session_company_currency; ?>',
                                minimumFractionDigits: 0
                            }).format(value);
                        }
                    }
                }
            }
        }
    });
    <?php endif; ?>
});

function printReport() {
    const printContent = document.querySelector('.card').innerHTML;
    const originalContent = document.body.innerHTML;
    
    document.body.innerHTML = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>Income Statement - <?php echo date('F j, Y', strtotime($start_date)); ?> to <?php echo date('F j, Y', strtotime($end_date)); ?></title>
            <style>
                body { font-family: Arial, sans-serif; }
                .report-header { text-align: center; margin-bottom: 20px; }
                .company-name { font-size: 24px; font-weight: bold; }
                .report-title { font-size: 18px; margin: 10px 0; }
                .report-period { font-size: 14px; color: #666; }
                table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
                th, td { padding: 8px; text-align: left; border-bottom: 1px solid #ddd; }
                th { background-color: #f5f5f5; font-weight: bold; }
                .text-right { text-align: right; }
                .total-row { font-weight: bold; background-color: #f0f0f0; }
                .section-header { background-color: #e9ecef; font-weight: bold; }
                @media print {
                    .no-print { display: none; }
                    body { font-size: 12px; }
                }
            </style>
        </head>
        <body>
            <div class="report-header">
                <div class="company-name"><?php echo htmlspecialchars($session_company_name); ?></div>
                <div class="report-title">Income Statement</div>
                <div class="report-period">Period: <?php echo date('F j, Y', strtotime($start_date)); ?> to <?php echo date('F j, Y', strtotime($end_date)); ?></div>
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
    window.location.href = 'export_pdf.php?report=income_statement&start=<?php echo $start_date; ?>&end=<?php echo $end_date; ?>';
}

function exportExcel() {
    window.location.href = 'export_excel.php?report=income_statement&start=<?php echo $start_date; ?>&end=<?php echo $end_date; ?>';
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
});
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
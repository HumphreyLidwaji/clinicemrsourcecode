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
$as_of_date = $_GET['as_of'] ?? date('Y-m-d');
$compare_date = $_GET['compare'] ?? '';
$period = $_GET['period'] ?? 'monthly';

// Calculate date ranges based on period
$date_ranges = [];
switch ($period) {
    case 'quarterly':
        $quarters = [
            'Q1' => ['start' => date('Y-01-01'), 'end' => date('Y-03-31')],
            'Q2' => ['start' => date('Y-04-01'), 'end' => date('Y-06-30')],
            'Q3' => ['start' => date('Y-07-01'), 'end' => date('Y-09-30')],
            'Q4' => ['start' => date('Y-10-01'), 'end' => date('Y-12-31')]
        ];
        break;
    case 'yearly':
        $current_year = date('Y');
        for ($i = 0; $i < 3; $i++) {
            $year = $current_year - $i;
            $date_ranges[$year] = [
                'start' => "$year-01-01",
                'end' => "$year-12-31"
            ];
        }
        break;
    default: // monthly
        for ($i = 0; $i < 6; $i++) {
            $date = date('Y-m-d', strtotime("-$i months"));
            $month = date('F Y', strtotime($date));
            $date_ranges[$month] = [
                'start' => date('Y-m-01', strtotime($date)),
                'end' => date('Y-m-t', strtotime($date))
            ];
        }
        break;
}

// Check if journal system exists
$table_check = $mysqli->query("SHOW TABLES LIKE 'journal_entries'")->num_rows;
$use_journal = ($table_check > 0);

// Function to get account balances for a date range
function getAccountBalances($mysqli, $start_date = null, $end_date = null, $use_journal = false) {
    if ($use_journal && $start_date && $end_date) {
        // Calculate balances from journal entries
        $sql = "SELECT 
                    a.account_id, 
                    a.account_number, 
                    a.account_name, 
                    a.account_type,
                    a.account_subtype,
                    a.normal_balance,
                    COALESCE(a.balance, 0) as opening_balance,
                    COALESCE((
                        SELECT SUM(jel.debit_amount) - SUM(jel.credit_amount) 
                        FROM journal_entry_lines jel
                        JOIN journal_entries je ON jel.journal_entry_id = je.journal_entry_id
                        WHERE jel.account_id = a.account_id 
                        AND je.status = 'posted'
                        AND je.transaction_date BETWEEN ? AND ?
                    ), 0) as period_change,
                    (COALESCE(a.balance, 0) + COALESCE((
                        SELECT SUM(jel.debit_amount) - SUM(jel.credit_amount) 
                        FROM journal_entry_lines jel
                        JOIN journal_entries je ON jel.journal_entry_id = je.journal_entry_id
                        WHERE jel.account_id = a.account_id 
                        AND je.status = 'posted'
                        AND je.transaction_date BETWEEN ? AND ?
                    ), 0)) as ending_balance
                FROM accounts a
                WHERE a.is_active = 1
                ORDER BY a.account_type, a.account_number";
        
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("ssss", $start_date, $end_date, $start_date, $end_date);
    } else {
        // Use current balances from accounts table
        $sql = "SELECT 
                    account_id, 
                    account_number, 
                    account_name, 
                    account_type,
                    account_subtype,
                    normal_balance,
                    COALESCE(balance, 0) as ending_balance,
                    0 as opening_balance,
                    0 as period_change
                FROM accounts
                WHERE is_active = 1
                ORDER BY account_type, account_number";
        
        $stmt = $mysqli->prepare($sql);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $balances = [
        'assets' => [],
        'liabilities' => [],
        'equity' => []
    ];
    
    $totals = [
        'assets_opening' => 0,
        'assets_ending' => 0,
        'liabilities_opening' => 0,
        'liabilities_ending' => 0,
        'equity_opening' => 0,
        'equity_ending' => 0
    ];
    
    while ($row = $result->fetch_assoc()) {
        $account = [
            'id' => $row['account_id'],
            'number' => $row['account_number'],
            'name' => $row['account_name'],
            'type' => $row['account_type'],
            'subtype' => $row['account_subtype'],
            'normal_balance' => $row['normal_balance'],
            'opening_balance' => floatval($row['opening_balance']),
            'period_change' => floatval($row['period_change']),
            'ending_balance' => floatval($row['ending_balance'])
        ];
        
        // Adjust for normal balance
        if ($row['normal_balance'] === 'credit' && $row['account_type'] !== 'liability' && $row['account_type'] !== 'equity') {
            $account['opening_balance'] = -$account['opening_balance'];
            $account['ending_balance'] = -$account['ending_balance'];
        }
        
        // Categorize
        switch ($row['account_type']) {
            case 'asset':
                $balances['assets'][] = $account;
                $totals['assets_opening'] += $account['opening_balance'];
                $totals['assets_ending'] += $account['ending_balance'];
                break;
            case 'liability':
                $balances['liabilities'][] = $account;
                $totals['liabilities_opening'] += $account['opening_balance'];
                $totals['liabilities_ending'] += $account['ending_balance'];
                break;
            case 'equity':
                $balances['equity'][] = $account;
                $totals['equity_opening'] += $account['opening_balance'];
                $totals['equity_ending'] += $account['ending_balance'];
                break;
        }
    }
    
    // Calculate net income from revenue and expense accounts
    $income_sql = "SELECT 
                    SUM(CASE WHEN account_type = 'revenue' THEN COALESCE(balance, 0) ELSE 0 END) as total_revenue,
                    SUM(CASE WHEN account_type = 'expense' THEN COALESCE(balance, 0) ELSE 0 END) as total_expense
                  FROM accounts 
                  WHERE is_active = 1 
                  AND account_type IN ('revenue', 'expense')";
    
    $income_result = $mysqli->query($income_sql);
    $income = $income_result->fetch_assoc();
    $net_income = ($income['total_revenue'] ?? 0) - ($income['total_expense'] ?? 0);
    
    // Add net income to equity
    $totals['equity_ending'] += $net_income;
    
    return [
        'balances' => $balances,
        'totals' => $totals,
        'net_income' => $net_income
    ];
}

// Get current period balances
$current_balances = getAccountBalances($mysqli, $as_of_date, $as_of_date, $use_journal);

// Get previous period balances for comparison
$previous_balances = [];
if ($compare_date) {
    $prev_date = date('Y-m-d', strtotime($compare_date . ' -1 month'));
    $previous_balances = getAccountBalances($mysqli, $prev_date, $prev_date, $use_journal);
}

// Get trend data
$trend_data = [];
foreach ($date_ranges as $label => $range) {
    $trend_data[$label] = getAccountBalances($mysqli, $range['start'], $range['end'], $use_journal);
}
?>

<div class="card">
    <div class="card-header bg-info py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-balance-scale-left mr-2"></i>Balance Sheet Report
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
                    <label class="font-weight-bold">As of Date</label>
                    <input type="date" class="form-control" name="as_of" value="<?php echo htmlspecialchars($as_of_date); ?>" onchange="this.form.submit()">
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    <label class="font-weight-bold">Compare With</label>
                    <input type="date" class="form-control" name="compare" value="<?php echo htmlspecialchars($compare_date); ?>" onchange="this.form.submit()">
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
                <div class="btn-group btn-block">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-sync mr-2"></i>Update Report
                    </button>
                    <a href="reports_balance_sheet.php" class="btn btn-outline-secondary">
                        <i class="fas fa-times mr-2"></i>Reset
                    </a>
                </div>
            </div>
        </form>
    </div>
    
    <!-- Summary Statistics -->
    <div class="card-body border-bottom">
        <div class="row text-center">
            <div class="col-md-4">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-success"><i class="fas fa-wallet"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Assets</span>
                        <span class="info-box-number"><?php echo numfmt_format_currency($currency_format, $current_balances['totals']['assets_ending'], $session_company_currency); ?></span>
                        <?php if ($compare_date && isset($previous_balances['totals']['assets_ending'])): ?>
                            <?php 
                            $change = $current_balances['totals']['assets_ending'] - $previous_balances['totals']['assets_ending'];
                            $percent = $previous_balances['totals']['assets_ending'] > 0 ? ($change / $previous_balances['totals']['assets_ending'] * 100) : 0;
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
            <div class="col-md-4">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-warning"><i class="fas fa-hand-holding-usd"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Liabilities</span>
                        <span class="info-box-number"><?php echo numfmt_format_currency($currency_format, $current_balances['totals']['liabilities_ending'], $session_company_currency); ?></span>
                        <?php if ($compare_date && isset($previous_balances['totals']['liabilities_ending'])): ?>
                            <?php 
                            $change = $current_balances['totals']['liabilities_ending'] - $previous_balances['totals']['liabilities_ending'];
                            $percent = $previous_balances['totals']['liabilities_ending'] > 0 ? ($change / $previous_balances['totals']['liabilities_ending'] * 100) : 0;
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
            <div class="col-md-4">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-primary"><i class="fas fa-balance-scale"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Owner's Equity</span>
                        <span class="info-box-number"><?php echo numfmt_format_currency($currency_format, $current_balances['totals']['equity_ending'], $session_company_currency); ?></span>
                        <?php if ($compare_date && isset($previous_balances['totals']['equity_ending'])): ?>
                            <?php 
                            $change = $current_balances['totals']['equity_ending'] - $previous_balances['totals']['equity_ending'];
                            $percent = $previous_balances['totals']['equity_ending'] > 0 ? ($change / $previous_balances['totals']['equity_ending'] * 100) : 0;
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
        
        <!-- Balance Check -->
        <div class="row mt-3">
            <div class="col-md-12">
                <div class="alert <?php echo abs($current_balances['totals']['assets_ending'] - ($current_balances['totals']['liabilities_ending'] + $current_balances['totals']['equity_ending'])) < 0.01 ? 'alert-success' : 'alert-danger'; ?>">
                    <div class="row align-items-center">
                        <div class="col-md-9">
                            <i class="fas fa-<?php echo abs($current_balances['totals']['assets_ending'] - ($current_balances['totals']['liabilities_ending'] + $current_balances['totals']['equity_ending'])) < 0.01 ? 'check-circle' : 'exclamation-triangle'; ?> mr-2"></i>
                            <strong>Accounting Equation Check:</strong>
                            <span class="ml-2">
                                Assets (<?php echo numfmt_format_currency($currency_format, $current_balances['totals']['assets_ending'], $session_company_currency); ?>)
                                = 
                                Liabilities (<?php echo numfmt_format_currency($currency_format, $current_balances['totals']['liabilities_ending'], $session_company_currency); ?>)
                                + 
                                Equity (<?php echo numfmt_format_currency($currency_format, $current_balances['totals']['equity_ending'], $session_company_currency); ?>)
                            </span>
                        </div>
                        <div class="col-md-3 text-right">
                            <strong>
                                <?php 
                                $difference = $current_balances['totals']['assets_ending'] - ($current_balances['totals']['liabilities_ending'] + $current_balances['totals']['equity_ending']);
                                echo numfmt_format_currency($currency_format, $difference, $session_company_currency);
                                ?>
                            </strong>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card-body">
        <!-- Balance Sheet Report -->
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0">
                            <i class="fas fa-file-invoice-dollar mr-2"></i>
                            Balance Sheet as of <?php echo date('F j, Y', strtotime($as_of_date)); ?>
                        </h4>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th colspan="2" class="bg-success text-white">ASSETS</th>
                                        <?php if ($compare_date): ?>
                                        <th class="text-right bg-success text-white">Previous</th>
                                        <th class="text-right bg-success text-white">Change</th>
                                        <?php endif; ?>
                                        <th class="text-right bg-success text-white">Current</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $asset_subtotals = [];
                                    foreach ($current_balances['balances']['assets'] as $index => $account):
                                        $subtype = $account['subtype'] ?: 'Other Assets';
                                        if (!isset($asset_subtotals[$subtype])) {
                                            $asset_subtotals[$subtype] = 0;
                                        }
                                        $asset_subtotals[$subtype] += $account['ending_balance'];
                                        
                                        $prev_account = $previous_balances['balances']['assets'][$index] ?? null;
                                    ?>
                                    <tr>
                                        <td width="10%" class="text-muted"><?php echo htmlspecialchars($account['number']); ?></td>
                                        <td width="40%"><?php echo htmlspecialchars($account['name']); ?></td>
                                        <?php if ($compare_date): ?>
                                        <td width="15%" class="text-right text-muted">
                                            <?php echo $prev_account ? numfmt_format_currency($currency_format, $prev_account['ending_balance'], $session_company_currency) : '-'; ?>
                                        </td>
                                        <td width="15%" class="text-right">
                                            <?php if ($prev_account): 
                                                $change = $account['ending_balance'] - $prev_account['ending_balance'];
                                                $trend_class = $change >= 0 ? 'text-success' : 'text-danger';
                                                $trend_icon = $change >= 0 ? 'fa-arrow-up' : 'fa-arrow-down';
                                            ?>
                                            <span class="<?php echo $trend_class; ?>">
                                                <i class="fas <?php echo $trend_icon; ?> mr-1"></i>
                                                <?php echo numfmt_format_currency($currency_format, abs($change), $session_company_currency); ?>
                                            </span>
                                            <?php else: ?>
                                            -
                                            <?php endif; ?>
                                        </td>
                                        <?php endif; ?>
                                        <td width="<?php echo $compare_date ? '20%' : '50%'; ?>" class="text-right font-weight-bold">
                                            <?php echo numfmt_format_currency($currency_format, $account['ending_balance'], $session_company_currency); ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    
                                    <!-- Asset subtotals -->
                                    <?php foreach ($asset_subtotals as $subtype => $subtotal): ?>
                                    <tr class="bg-light">
                                        <td colspan="<?php echo $compare_date ? 4 : 2; ?>" class="font-weight-bold"><?php echo htmlspecialchars($subtype); ?></td>
                                        <td class="text-right font-weight-bold"><?php echo numfmt_format_currency($currency_format, $subtotal, $session_company_currency); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    
                                    <!-- Total Assets -->
                                    <tr class="bg-success text-white">
                                        <td colspan="<?php echo $compare_date ? 4 : 2; ?>" class="font-weight-bold">TOTAL ASSETS</td>
                                        <td class="text-right font-weight-bold"><?php echo numfmt_format_currency($currency_format, $current_balances['totals']['assets_ending'], $session_company_currency); ?></td>
                                    </tr>
                                </tbody>
                                
                                <thead class="bg-light">
                                    <tr>
                                        <th colspan="2" class="bg-warning">LIABILITIES & EQUITY</th>
                                        <?php if ($compare_date): ?>
                                        <th class="text-right bg-warning">Previous</th>
                                        <th class="text-right bg-warning">Change</th>
                                        <?php endif; ?>
                                        <th class="text-right bg-warning">Current</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- Liabilities -->
                                    <tr>
                                        <td colspan="<?php echo $compare_date ? 5 : 3; ?>" class="bg-light font-weight-bold">LIABILITIES</td>
                                    </tr>
                                    <?php 
                                    $liability_subtotals = [];
                                    foreach ($current_balances['balances']['liabilities'] as $index => $account):
                                        $subtype = $account['subtype'] ?: 'Other Liabilities';
                                        if (!isset($liability_subtotals[$subtype])) {
                                            $liability_subtotals[$subtype] = 0;
                                        }
                                        $liability_subtotals[$subtype] += $account['ending_balance'];
                                        
                                        $prev_account = $previous_balances['balances']['liabilities'][$index] ?? null;
                                    ?>
                                    <tr>
                                        <td class="text-muted"><?php echo htmlspecialchars($account['number']); ?></td>
                                        <td><?php echo htmlspecialchars($account['name']); ?></td>
                                        <?php if ($compare_date): ?>
                                        <td class="text-right text-muted">
                                            <?php echo $prev_account ? numfmt_format_currency($currency_format, $prev_account['ending_balance'], $session_company_currency) : '-'; ?>
                                        </td>
                                        <td class="text-right">
                                            <?php if ($prev_account): 
                                                $change = $account['ending_balance'] - $prev_account['ending_balance'];
                                                $trend_class = $change <= 0 ? 'text-success' : 'text-danger';
                                                $trend_icon = $change <= 0 ? 'fa-arrow-down' : 'fa-arrow-up';
                                            ?>
                                            <span class="<?php echo $trend_class; ?>">
                                                <i class="fas <?php echo $trend_icon; ?> mr-1"></i>
                                                <?php echo numfmt_format_currency($currency_format, abs($change), $session_company_currency); ?>
                                            </span>
                                            <?php else: ?>
                                            -
                                            <?php endif; ?>
                                        </td>
                                        <?php endif; ?>
                                        <td class="text-right font-weight-bold"><?php echo numfmt_format_currency($currency_format, $account['ending_balance'], $session_company_currency); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    
                                    <!-- Liability subtotals -->
                                    <?php foreach ($liability_subtotals as $subtype => $subtotal): ?>
                                    <tr class="bg-light">
                                        <td colspan="<?php echo $compare_date ? 4 : 2; ?>" class="font-weight-bold"><?php echo htmlspecialchars($subtype); ?></td>
                                        <td class="text-right font-weight-bold"><?php echo numfmt_format_currency($currency_format, $subtotal, $session_company_currency); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    
                                    <!-- Total Liabilities -->
                                    <tr class="bg-light">
                                        <td colspan="<?php echo $compare_date ? 4 : 2; ?>" class="font-weight-bold">TOTAL LIABILITIES</td>
                                        <td class="text-right font-weight-bold"><?php echo numfmt_format_currency($currency_format, $current_balances['totals']['liabilities_ending'], $session_company_currency); ?></td>
                                    </tr>
                                    
                                    <!-- Equity -->
                                    <tr>
                                        <td colspan="<?php echo $compare_date ? 5 : 3; ?>" class="bg-light font-weight-bold mt-3">EQUITY</td>
                                    </tr>
                                    <?php 
                                    $equity_subtotals = [];
                                    foreach ($current_balances['balances']['equity'] as $index => $account):
                                        $subtype = $account['subtype'] ?: 'Equity';
                                        if (!isset($equity_subtotals[$subtype])) {
                                            $equity_subtotals[$subtype] = 0;
                                        }
                                        $equity_subtotals[$subtype] += $account['ending_balance'];
                                        
                                        $prev_account = $previous_balances['balances']['equity'][$index] ?? null;
                                    ?>
                                    <tr>
                                        <td class="text-muted"><?php echo htmlspecialchars($account['number']); ?></td>
                                        <td><?php echo htmlspecialchars($account['name']); ?></td>
                                        <?php if ($compare_date): ?>
                                        <td class="text-right text-muted">
                                            <?php echo $prev_account ? numfmt_format_currency($currency_format, $prev_account['ending_balance'], $session_company_currency) : '-'; ?>
                                        </td>
                                        <td class="text-right">
                                            <?php if ($prev_account): 
                                                $change = $account['ending_balance'] - $prev_account['ending_balance'];
                                                $trend_class = $change >= 0 ? 'text-success' : 'text-danger';
                                                $trend_icon = $change >= 0 ? 'fa-arrow-up' : 'fa-arrow-down';
                                            ?>
                                            <span class="<?php echo $trend_class; ?>">
                                                <i class="fas <?php echo $trend_icon; ?> mr-1"></i>
                                                <?php echo numfmt_format_currency($currency_format, abs($change), $session_company_currency); ?>
                                            </span>
                                            <?php else: ?>
                                            -
                                            <?php endif; ?>
                                        </td>
                                        <?php endif; ?>
                                        <td class="text-right font-weight-bold"><?php echo numfmt_format_currency($currency_format, $account['ending_balance'], $session_company_currency); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    
                                    <!-- Net Income -->
                                    <tr>
                                        <td class="text-muted">3999</td>
                                        <td>Net Income</td>
                                        <?php if ($compare_date): ?>
                                        <td class="text-right text-muted">
                                            <?php echo isset($previous_balances['net_income']) ? numfmt_format_currency($currency_format, $previous_balances['net_income'], $session_company_currency) : '-'; ?>
                                        </td>
                                        <td class="text-right">
                                            <?php if (isset($previous_balances['net_income'])): 
                                                $change = $current_balances['net_income'] - $previous_balances['net_income'];
                                                $trend_class = $change >= 0 ? 'text-success' : 'text-danger';
                                                $trend_icon = $change >= 0 ? 'fa-arrow-up' : 'fa-arrow-down';
                                            ?>
                                            <span class="<?php echo $trend_class; ?>">
                                                <i class="fas <?php echo $trend_icon; ?> mr-1"></i>
                                                <?php echo numfmt_format_currency($currency_format, abs($change), $session_company_currency); ?>
                                            </span>
                                            <?php else: ?>
                                            -
                                            <?php endif; ?>
                                        </td>
                                        <?php endif; ?>
                                        <td class="text-right font-weight-bold"><?php echo numfmt_format_currency($currency_format, $current_balances['net_income'], $session_company_currency); ?></td>
                                    </tr>
                                    
                                    <!-- Total Equity -->
                                    <tr class="bg-primary text-white">
                                        <td colspan="<?php echo $compare_date ? 4 : 2; ?>" class="font-weight-bold">TOTAL EQUITY</td>
                                        <td class="text-right font-weight-bold"><?php echo numfmt_format_currency($currency_format, $current_balances['totals']['equity_ending'], $session_company_currency); ?></td>
                                    </tr>
                                    
                                    <!-- Total Liabilities & Equity -->
                                    <tr class="bg-warning">
                                        <td colspan="<?php echo $compare_date ? 4 : 2; ?>" class="font-weight-bold">TOTAL LIABILITIES & EQUITY</td>
                                        <td class="text-right font-weight-bold"><?php echo numfmt_format_currency($currency_format, $current_balances['totals']['liabilities_ending'] + $current_balances['totals']['equity_ending'], $session_company_currency); ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Trend Analysis -->
        <?php if (count($trend_data) > 1): ?>
        <div class="row mt-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-chart-line mr-2"></i>Trend Analysis</h4>
                    </div>
                    <div class="card-body">
                        <canvas id="trendChart" height="100"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Key Ratios -->
        <div class="row mt-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-percentage mr-2"></i>Financial Ratios</h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="text-center p-3 border rounded">
                                    <div class="h4 text-primary">
                                        <?php 
                                        $current_ratio = $current_balances['totals']['assets_ending'] > 0 ? 
                                            ($current_balances['totals']['assets_ending'] / max($current_balances['totals']['liabilities_ending'], 1)) : 0;
                                        echo number_format($current_ratio, 2);
                                        ?>
                                    </div>
                                    <small class="text-muted">Current Ratio</small>
                                    <div class="small mt-1">
                                        <?php 
                                        if ($current_ratio > 2) {
                                            echo '<span class="badge badge-success">Excellent</span>';
                                        } elseif ($current_ratio > 1) {
                                            echo '<span class="badge badge-warning">Adequate</span>';
                                        } else {
                                            echo '<span class="badge badge-danger">Poor</span>';
                                        }
                                        ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-center p-3 border rounded">
                                    <div class="h4 text-success">
                                        <?php 
                                        $debt_to_equity = $current_balances['totals']['equity_ending'] > 0 ? 
                                            ($current_balances['totals']['liabilities_ending'] / $current_balances['totals']['equity_ending']) : 0;
                                        echo number_format($debt_to_equity, 2);
                                        ?>
                                    </div>
                                    <small class="text-muted">Debt to Equity Ratio</small>
                                    <div class="small mt-1">
                                        <?php 
                                        if ($debt_to_equity < 0.5) {
                                            echo '<span class="badge badge-success">Low Risk</span>';
                                        } elseif ($debt_to_equity < 1) {
                                            echo '<span class="badge badge-warning">Moderate</span>';
                                        } else {
                                            echo '<span class="badge badge-danger">High Risk</span>';
                                        }
                                        ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-center p-3 border rounded">
                                    <div class="h4 text-info">
                                        <?php 
                                        $equity_ratio = $current_balances['totals']['assets_ending'] > 0 ? 
                                            ($current_balances['totals']['equity_ending'] / $current_balances['totals']['assets_ending'] * 100) : 0;
                                        echo number_format($equity_ratio, 1) . '%';
                                        ?>
                                    </div>
                                    <small class="text-muted">Equity Ratio</small>
                                    <div class="small mt-1">
                                        <?php 
                                        if ($equity_ratio > 50) {
                                            echo '<span class="badge badge-success">Strong</span>';
                                        } elseif ($equity_ratio > 30) {
                                            echo '<span class="badge badge-warning">Adequate</span>';
                                        } else {
                                            echo '<span class="badge badge-danger">Weak</span>';
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
    
    <?php if (count($trend_data) > 1): ?>
    // Initialize trend chart
    const trendCtx = document.getElementById('trendChart').getContext('2d');
    const trendChart = new Chart(trendCtx, {
        type: 'line',
        data: {
            labels: [<?php echo "'" . implode("','", array_keys($trend_data)) . "'"; ?>],
            datasets: [
                {
                    label: 'Assets',
                    data: [<?php echo implode(',', array_map(function($item) { return $item['totals']['assets_ending']; }, $trend_data)); ?>],
                    borderColor: '#28a745',
                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                    fill: true,
                    tension: 0.4
                },
                {
                    label: 'Liabilities',
                    data: [<?php echo implode(',', array_map(function($item) { return $item['totals']['liabilities_ending']; }, $trend_data)); ?>],
                    borderColor: '#ffc107',
                    backgroundColor: 'rgba(255, 193, 7, 0.1)',
                    fill: true,
                    tension: 0.4
                },
                {
                    label: 'Equity',
                    data: [<?php echo implode(',', array_map(function($item) { return $item['totals']['equity_ending']; }, $trend_data)); ?>],
                    borderColor: '#007bff',
                    backgroundColor: 'rgba(0, 123, 255, 0.1)',
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
            <title>Balance Sheet - <?php echo date('F j, Y', strtotime($as_of_date)); ?></title>
            <style>
                body { font-family: Arial, sans-serif; }
                .report-header { text-align: center; margin-bottom: 20px; }
                .company-name { font-size: 24px; font-weight: bold; }
                .report-title { font-size: 18px; margin: 10px 0; }
                .report-date { font-size: 14px; color: #666; }
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
                <div class="report-title">Balance Sheet</div>
                <div class="report-date">As of <?php echo date('F j, Y', strtotime($as_of_date)); ?></div>
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
    // You would typically implement this with a server-side PDF generation library
    window.location.href = 'export_pdf.php?report=balance_sheet&date=<?php echo $as_of_date; ?>';
}

function exportExcel() {
    // You would typically implement this with a server-side Excel generation library
    window.location.href = 'export_excel.php?report=balance_sheet&date=<?php echo $as_of_date; ?>';
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
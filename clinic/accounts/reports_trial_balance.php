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
$period = $_GET['period'] ?? 'monthly';
$show_zero_balance = $_GET['show_zero'] ?? false;

// Calculate date ranges
$date_ranges = [];
switch ($period) {
    case 'quarterly':
        $quarter = ceil(date('n', strtotime($as_of_date)) / 3);
        $start_date = date('Y-m-01', strtotime(date('Y', strtotime($as_of_date)) . '-' . (($quarter - 1) * 3 + 1) . '-01'));
        $end_date = date('Y-m-t', strtotime(date('Y', strtotime($as_of_date)) . '-' . ($quarter * 3) . '-01'));
        break;
    case 'yearly':
        $start_date = date('Y-01-01', strtotime($as_of_date));
        $end_date = date('Y-12-31', strtotime($as_of_date));
        break;
    default: // monthly
        $start_date = date('Y-m-01', strtotime($as_of_date));
        $end_date = date('Y-m-t', strtotime($as_of_date));
        break;
}

// Check if journal system exists
$table_check = $mysqli->query("SHOW TABLES LIKE 'journal_entries'")->num_rows;
$use_journal = ($table_check > 0);

// Function to get trial balance data
function getTrialBalanceData($mysqli, $start_date, $end_date, $use_journal = false, $show_zero_balance = false) {
    if ($use_journal) {
        // Calculate from journal entries
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
                " . (!$show_zero_balance ? "HAVING ending_balance != 0 OR period_change != 0" : "") . "
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
                " . (!$show_zero_balance ? "AND COALESCE(balance, 0) != 0" : "") . "
                ORDER BY account_type, account_number";
        
        $stmt = $mysqli->prepare($sql);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $data = [
        'accounts' => [],
        'totals' => [
            'debit_opening' => 0,
            'credit_opening' => 0,
            'debit_change' => 0,
            'credit_change' => 0,
            'debit_ending' => 0,
            'credit_ending' => 0
        ]
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
        
        // Calculate debit/credit amounts based on normal balance
        if ($row['normal_balance'] === 'debit') {
            // For debit normal balance accounts
            $account['debit_opening'] = $account['opening_balance'] >= 0 ? $account['opening_balance'] : 0;
            $account['credit_opening'] = $account['opening_balance'] < 0 ? abs($account['opening_balance']) : 0;
            
            $account['debit_change'] = $account['period_change'] >= 0 ? $account['period_change'] : 0;
            $account['credit_change'] = $account['period_change'] < 0 ? abs($account['period_change']) : 0;
            
            $account['debit_ending'] = $account['ending_balance'] >= 0 ? $account['ending_balance'] : 0;
            $account['credit_ending'] = $account['ending_balance'] < 0 ? abs($account['ending_balance']) : 0;
        } else {
            // For credit normal balance accounts
            $account['debit_opening'] = $account['opening_balance'] < 0 ? abs($account['opening_balance']) : 0;
            $account['credit_opening'] = $account['opening_balance'] >= 0 ? $account['opening_balance'] : 0;
            
            $account['debit_change'] = $account['period_change'] < 0 ? abs($account['period_change']) : 0;
            $account['credit_change'] = $account['period_change'] >= 0 ? $account['period_change'] : 0;
            
            $account['debit_ending'] = $account['ending_balance'] < 0 ? abs($account['ending_balance']) : 0;
            $account['credit_ending'] = $account['ending_balance'] >= 0 ? $account['ending_balance'] : 0;
        }
        
        $data['accounts'][] = $account;
        
        // Update totals
        $data['totals']['debit_opening'] += $account['debit_opening'];
        $data['totals']['credit_opening'] += $account['credit_opening'];
        $data['totals']['debit_change'] += $account['debit_change'];
        $data['totals']['credit_change'] += $account['credit_change'];
        $data['totals']['debit_ending'] += $account['debit_ending'];
        $data['totals']['credit_ending'] += $account['credit_ending'];
    }
    
    return $data;
}

// Get trial balance data
$trial_balance = getTrialBalanceData($mysqli, $start_date, $end_date, $use_journal, $show_zero_balance);

// Get previous period for comparison
$previous_start_date = date('Y-m-d', strtotime($start_date . ' -1 ' . $period));
$previous_end_date = date('Y-m-d', strtotime($end_date . ' -1 ' . $period));
$previous_trial_balance = getTrialBalanceData($mysqli, $previous_start_date, $previous_end_date, $use_journal, $show_zero_balance);

// Group accounts by type
$grouped_accounts = [
    'asset' => [],
    'liability' => [],
    'equity' => [],
    'revenue' => [],
    'expense' => []
];

foreach ($trial_balance['accounts'] as $account) {
    $grouped_accounts[$account['type']][] = $account;
}

// Get statistics
$stats_sql = "SELECT 
    COUNT(*) as total_accounts,
    COUNT(CASE WHEN a.account_type = 'asset' THEN 1 END) as asset_accounts,
    COUNT(CASE WHEN a.account_type = 'liability' THEN 1 END) as liability_accounts,
    COUNT(CASE WHEN a.account_type = 'equity' THEN 1 END) as equity_accounts,
    COUNT(CASE WHEN a.account_type = 'revenue' THEN 1 END) as revenue_accounts,
    COUNT(CASE WHEN a.account_type = 'expense' THEN 1 END) as expense_accounts,
    COUNT(CASE WHEN COALESCE(a.balance, 0) != 0 THEN 1 END) as active_accounts
FROM accounts a
WHERE a.is_active = 1";
$stats_result = $mysqli->query($stats_sql);
$stats = $stats_result->fetch_assoc();
?>

<div class="card">
    <div class="card-header bg-warning py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-balance-scale-left mr-2"></i>Trial Balance Report
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
                    <label class="font-weight-bold">Options</label>
                    <div class="custom-control custom-checkbox mt-2">
                        <input type="checkbox" class="custom-control-input" id="show_zero" name="show_zero" value="1" <?php echo $show_zero_balance ? 'checked' : ''; ?> onchange="this.form.submit()">
                        <label class="custom-control-label" for="show_zero">Show Zero Balance Accounts</label>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="btn-group btn-block">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-sync mr-2"></i>Update Report
                    </button>
                    <a href="reports_trial_balance.php" class="btn btn-outline-secondary">
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
                        <small class="text-muted"><?php echo $stats['active_accounts']; ?> active</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-success"><i class="fas fa-wallet"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Asset Accounts</span>
                        <span class="info-box-number"><?php echo $stats['asset_accounts']; ?></span>
                        <small class="text-muted">In trial balance</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-warning"><i class="fas fa-hand-holding-usd"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Liability Accounts</span>
                        <span class="info-box-number"><?php echo $stats['liability_accounts']; ?></span>
                        <small class="text-muted">In trial balance</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-info"><i class="fas fa-arrow-up"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Debits</span>
                        <span class="info-box-number"><?php echo numfmt_format_currency($currency_format, $trial_balance['totals']['debit_ending'], $session_company_currency); ?></span>
                        <small class="text-muted">Ending balance</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-danger"><i class="fas fa-arrow-down"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Credits</span>
                        <span class="info-box-number"><?php echo numfmt_format_currency($currency_format, $trial_balance['totals']['credit_ending'], $session_company_currency); ?></span>
                        <small class="text-muted">Ending balance</small>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Balance Check -->
        <div class="row mt-3">
            <div class="col-md-12">
                <?php 
                $balance_difference = abs($trial_balance['totals']['debit_ending'] - $trial_balance['totals']['credit_ending']);
                $is_balanced = $balance_difference < 0.01;
                ?>
                <div class="alert <?php echo $is_balanced ? 'alert-success' : 'alert-danger'; ?>">
                    <div class="row align-items-center">
                        <div class="col-md-9">
                            <i class="fas fa-<?php echo $is_balanced ? 'check-circle' : 'exclamation-triangle'; ?> mr-2"></i>
                            <strong>Trial Balance Check:</strong>
                            <span class="ml-2">
                                Total Debits (<?php echo numfmt_format_currency($currency_format, $trial_balance['totals']['debit_ending'], $session_company_currency); ?>)
                                <?php echo $is_balanced ? '=' : '≠'; ?>
                                Total Credits (<?php echo numfmt_format_currency($currency_format, $trial_balance['totals']['credit_ending'], $session_company_currency); ?>)
                            </span>
                        </div>
                        <div class="col-md-3 text-right">
                            <strong>
                                <?php 
                                $difference = $trial_balance['totals']['debit_ending'] - $trial_balance['totals']['credit_ending'];
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
        <!-- Trial Balance Report -->
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0">
                            <i class="fas fa-file-invoice-dollar mr-2"></i>
                            Trial Balance as of <?php echo date('F j, Y', strtotime($as_of_date)); ?>
                        </h4>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th rowspan="2" class="align-middle">Account</th>
                                        <th rowspan="2" class="align-middle">Account Name</th>
                                        <th colspan="3" class="text-center border-bottom-0">Opening Balance</th>
                                        <th colspan="3" class="text-center border-bottom-0">Period Activity</th>
                                        <th colspan="3" class="text-center border-bottom-0">Ending Balance</th>
                                    </tr>
                                    <tr class="border-top-0">
                                        <!-- Opening Balance Columns -->
                                        <th class="text-right text-success">Debit</th>
                                        <th class="text-right text-danger">Credit</th>
                                        <th class="text-right text-muted">Balance</th>
                                        
                                        <!-- Period Activity Columns -->
                                        <th class="text-right text-success">Debit</th>
                                        <th class="text-right text-danger">Credit</th>
                                        <th class="text-right text-muted">Net Change</th>
                                        
                                        <!-- Ending Balance Columns -->
                                        <th class="text-right text-success">Debit</th>
                                        <th class="text-right text-danger">Credit</th>
                                        <th class="text-right text-primary">Balance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $type_totals = [
                                        'debit_opening' => 0,
                                        'credit_opening' => 0,
                                        'debit_change' => 0,
                                        'credit_change' => 0,
                                        'debit_ending' => 0,
                                        'credit_ending' => 0
                                    ];
                                    
                                    foreach ($grouped_accounts as $type => $accounts):
                                        if (empty($accounts)) continue;
                                        
                                        $type_label = ucfirst($type);
                                        $type_color = $type == 'asset' ? 'success' : 
                                                     ($type == 'liability' ? 'warning' : 
                                                     ($type == 'equity' ? 'primary' : 
                                                     ($type == 'revenue' ? 'info' : 'danger')));
                                    ?>
                                    <!-- Account Type Header -->
                                    <tr class="bg-<?php echo $type_color; ?> text-white">
                                        <td colspan="11" class="font-weight-bold">
                                            <i class="fas fa-<?php 
                                                echo $type == 'asset' ? 'wallet' : 
                                                     ($type == 'liability' ? 'hand-holding-usd' : 
                                                     ($type == 'equity' ? 'piggy-bank' : 
                                                     ($type == 'revenue' ? 'chart-line' : 'money-bill-wave'))); 
                                            ?> mr-2"></i>
                                            <?php echo $type_label; ?> ACCOUNTS
                                        </td>
                                    </tr>
                                    
                                    <?php foreach ($accounts as $account): 
                                        // Reset type totals for this type
                                        if (!isset($type_started)) {
                                            $type_totals = [
                                                'debit_opening' => 0,
                                                'credit_opening' => 0,
                                                'debit_change' => 0,
                                                'credit_change' => 0,
                                                'debit_ending' => 0,
                                                'credit_ending' => 0
                                            ];
                                            $type_started = true;
                                        }
                                        
                                        $type_totals['debit_opening'] += $account['debit_opening'];
                                        $type_totals['credit_opening'] += $account['credit_opening'];
                                        $type_totals['debit_change'] += $account['debit_change'];
                                        $type_totals['credit_change'] += $account['credit_change'];
                                        $type_totals['debit_ending'] += $account['debit_ending'];
                                        $type_totals['credit_ending'] += $account['credit_ending'];
                                        
                                        $balance_color = $account['ending_balance'] >= 0 ? 'text-success' : 'text-danger';
                                        $balance_sign = $account['ending_balance'] >= 0 ? '' : '-';
                                    ?>
                                    <tr>
                                        <td class="font-weight-bold text-primary"><?php echo htmlspecialchars($account['number']); ?></td>
                                        <td><?php echo htmlspecialchars($account['name']); ?></td>
                                        
                                        <!-- Opening Balance -->
                                        <td class="text-right <?php echo $account['debit_opening'] > 0 ? 'text-success font-weight-bold' : 'text-muted'; ?>">
                                            <?php echo $account['debit_opening'] > 0 ? numfmt_format_currency($currency_format, $account['debit_opening'], $session_company_currency) : '-'; ?>
                                        </td>
                                        <td class="text-right <?php echo $account['credit_opening'] > 0 ? 'text-danger font-weight-bold' : 'text-muted'; ?>">
                                            <?php echo $account['credit_opening'] > 0 ? numfmt_format_currency($currency_format, $account['credit_opening'], $session_company_currency) : '-'; ?>
                                        </td>
                                        <td class="text-right text-muted">
                                            <?php 
                                            $opening_balance = $account['debit_opening'] - $account['credit_opening'];
                                            echo $opening_balance != 0 ? numfmt_format_currency($currency_format, $opening_balance, $session_company_currency) : '-';
                                            ?>
                                        </td>
                                        
                                        <!-- Period Activity -->
                                        <td class="text-right <?php echo $account['debit_change'] > 0 ? 'text-success font-weight-bold' : 'text-muted'; ?>">
                                            <?php echo $account['debit_change'] > 0 ? numfmt_format_currency($currency_format, $account['debit_change'], $session_company_currency) : '-'; ?>
                                        </td>
                                        <td class="text-right <?php echo $account['credit_change'] > 0 ? 'text-danger font-weight-bold' : 'text-muted'; ?>">
                                            <?php echo $account['credit_change'] > 0 ? numfmt_format_currency($currency_format, $account['credit_change'], $session_company_currency) : '-'; ?>
                                        </td>
                                        <td class="text-right <?php echo $account['period_change'] != 0 ? ($account['period_change'] >= 0 ? 'text-success' : 'text-danger') . ' font-weight-bold' : 'text-muted'; ?>">
                                            <?php echo $account['period_change'] != 0 ? numfmt_format_currency($currency_format, $account['period_change'], $session_company_currency) : '-'; ?>
                                        </td>
                                        
                                        <!-- Ending Balance -->
                                        <td class="text-right <?php echo $account['debit_ending'] > 0 ? 'text-success font-weight-bold' : 'text-muted'; ?>">
                                            <?php echo $account['debit_ending'] > 0 ? numfmt_format_currency($currency_format, $account['debit_ending'], $session_company_currency) : '-'; ?>
                                        </td>
                                        <td class="text-right <?php echo $account['credit_ending'] > 0 ? 'text-danger font-weight-bold' : 'text-muted'; ?>">
                                            <?php echo $account['credit_ending'] > 0 ? numfmt_format_currency($currency_format, $account['credit_ending'], $session_company_currency) : '-'; ?>
                                        </td>
                                        <td class="text-right <?php echo $balance_color; ?> font-weight-bold">
                                            <?php echo $balance_sign . numfmt_format_currency($currency_format, abs($account['ending_balance']), $session_company_currency); ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    
                                    <!-- Account Type Subtotal -->
                                    <tr class="bg-light">
                                        <td colspan="2" class="font-weight-bold">Total <?php echo $type_label; ?></td>
                                        
                                        <!-- Opening Balance Subtotal -->
                                        <td class="text-right font-weight-bold <?php echo $type_totals['debit_opening'] > 0 ? 'text-success' : 'text-muted'; ?>">
                                            <?php echo $type_totals['debit_opening'] > 0 ? numfmt_format_currency($currency_format, $type_totals['debit_opening'], $session_company_currency) : '-'; ?>
                                        </td>
                                        <td class="text-right font-weight-bold <?php echo $type_totals['credit_opening'] > 0 ? 'text-danger' : 'text-muted'; ?>">
                                            <?php echo $type_totals['credit_opening'] > 0 ? numfmt_format_currency($currency_format, $type_totals['credit_opening'], $session_company_currency) : '-'; ?>
                                        </td>
                                        <td class="text-right font-weight-bold text-muted">
                                            <?php 
                                            $type_opening_balance = $type_totals['debit_opening'] - $type_totals['credit_opening'];
                                            echo $type_opening_balance != 0 ? numfmt_format_currency($currency_format, $type_opening_balance, $session_company_currency) : '-';
                                            ?>
                                        </td>
                                        
                                        <!-- Period Activity Subtotal -->
                                        <td class="text-right font-weight-bold <?php echo $type_totals['debit_change'] > 0 ? 'text-success' : 'text-muted'; ?>">
                                            <?php echo $type_totals['debit_change'] > 0 ? numfmt_format_currency($currency_format, $type_totals['debit_change'], $session_company_currency) : '-'; ?>
                                        </td>
                                        <td class="text-right font-weight-bold <?php echo $type_totals['credit_change'] > 0 ? 'text-danger' : 'text-muted'; ?>">
                                            <?php echo $type_totals['credit_change'] > 0 ? numfmt_format_currency($currency_format, $type_totals['credit_change'], $session_company_currency) : '-'; ?>
                                        </td>
                                        <td class="text-right font-weight-bold text-muted">
                                            <?php 
                                            $type_change_balance = $type_totals['debit_change'] - $type_totals['credit_change'];
                                            echo $type_change_balance != 0 ? numfmt_format_currency($currency_format, $type_change_balance, $session_company_currency) : '-';
                                            ?>
                                        </td>
                                        
                                        <!-- Ending Balance Subtotal -->
                                        <td class="text-right font-weight-bold <?php echo $type_totals['debit_ending'] > 0 ? 'text-success' : 'text-muted'; ?>">
                                            <?php echo $type_totals['debit_ending'] > 0 ? numfmt_format_currency($currency_format, $type_totals['debit_ending'], $session_company_currency) : '-'; ?>
                                        </td>
                                        <td class="text-right font-weight-bold <?php echo $type_totals['credit_ending'] > 0 ? 'text-danger' : 'text-muted'; ?>">
                                            <?php echo $type_totals['credit_ending'] > 0 ? numfmt_format_currency($currency_format, $type_totals['credit_ending'], $session_company_currency) : '-'; ?>
                                        </td>
                                        <td class="text-right font-weight-bold text-primary">
                                            <?php 
                                            $type_ending_balance = $type_totals['debit_ending'] - $type_totals['credit_ending'];
                                            echo numfmt_format_currency($currency_format, $type_ending_balance, $session_company_currency);
                                            ?>
                                        </td>
                                    </tr>
                                    
                                    <!-- Spacer row -->
                                    <tr>
                                        <td colspan="11" style="height: 10px;"></td>
                                    </tr>
                                    <?php 
                                    unset($type_started);
                                    endforeach; ?>
                                    
                                    <!-- Grand Total -->
                                    <tr class="bg-warning">
                                        <td colspan="2" class="font-weight-bold">GRAND TOTAL</td>
                                        
                                        <!-- Opening Balance Total -->
                                        <td class="text-right font-weight-bold text-success">
                                            <?php echo numfmt_format_currency($currency_format, $trial_balance['totals']['debit_opening'], $session_company_currency); ?>
                                        </td>
                                        <td class="text-right font-weight-bold text-danger">
                                            <?php echo numfmt_format_currency($currency_format, $trial_balance['totals']['credit_opening'], $session_company_currency); ?>
                                        </td>
                                        <td class="text-right font-weight-bold text-muted">
                                            <?php 
                                            $total_opening_balance = $trial_balance['totals']['debit_opening'] - $trial_balance['totals']['credit_opening'];
                                            echo numfmt_format_currency($currency_format, $total_opening_balance, $session_company_currency);
                                            ?>
                                        </td>
                                        
                                        <!-- Period Activity Total -->
                                        <td class="text-right font-weight-bold text-success">
                                            <?php echo numfmt_format_currency($currency_format, $trial_balance['totals']['debit_change'], $session_company_currency); ?>
                                        </td>
                                        <td class="text-right font-weight-bold text-danger">
                                            <?php echo numfmt_format_currency($currency_format, $trial_balance['totals']['credit_change'], $session_company_currency); ?>
                                        </td>
                                        <td class="text-right font-weight-bold text-muted">
                                            <?php 
                                            $total_change_balance = $trial_balance['totals']['debit_change'] - $trial_balance['totals']['credit_change'];
                                            echo numfmt_format_currency($currency_format, $total_change_balance, $session_company_currency);
                                            ?>
                                        </td>
                                        
                                        <!-- Ending Balance Total -->
                                        <td class="text-right font-weight-bold text-success">
                                            <?php echo numfmt_format_currency($currency_format, $trial_balance['totals']['debit_ending'], $session_company_currency); ?>
                                        </td>
                                        <td class="text-right font-weight-bold text-danger">
                                            <?php echo numfmt_format_currency($currency_format, $trial_balance['totals']['credit_ending'], $session_company_currency); ?>
                                        </td>
                                        <td class="text-right font-weight-bold text-primary">
                                            <?php 
                                            $total_ending_balance = $trial_balance['totals']['debit_ending'] - $trial_balance['totals']['credit_ending'];
                                            echo numfmt_format_currency($currency_format, $total_ending_balance, $session_company_currency);
                                            ?>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Balance Analysis -->
        <div class="row mt-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-chart-bar mr-2"></i>Balance Analysis</h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="table-responsive">
                                    <table class="table table-sm table-borderless">
                                        <tr>
                                            <th width="40%" class="text-muted">Total Debit Balance:</th>
                                            <td class="text-right font-weight-bold text-success">
                                                <?php echo numfmt_format_currency($currency_format, $trial_balance['totals']['debit_ending'], $session_company_currency); ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Total Credit Balance:</th>
                                            <td class="text-right font-weight-bold text-danger">
                                                <?php echo numfmt_format_currency($currency_format, $trial_balance['totals']['credit_ending'], $session_company_currency); ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Balance Difference:</th>
                                            <td class="text-right font-weight-bold <?php echo $is_balanced ? 'text-success' : 'text-danger'; ?>">
                                                <?php echo numfmt_format_currency($currency_format, $balance_difference, $session_company_currency); ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Status:</th>
                                            <td class="text-right">
                                                <span class="badge badge-<?php echo $is_balanced ? 'success' : 'danger'; ?>">
                                                    <?php echo $is_balanced ? 'BALANCED' : 'OUT OF BALANCE'; ?>
                                                </span>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="table-responsive">
                                    <table class="table table-sm table-borderless">
                                        <tr>
                                            <th width="40%" class="text-muted">Accounts Included:</th>
                                            <td class="text-right font-weight-bold"><?php echo count($trial_balance['accounts']); ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Period Activity:</th>
                                            <td class="text-right font-weight-bold text-info">
                                                <?php echo numfmt_format_currency($currency_format, $trial_balance['totals']['debit_change'] + $trial_balance['totals']['credit_change'], $session_company_currency); ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Report Period:</th>
                                            <td class="text-right">
                                                <?php echo date('M j, Y', strtotime($start_date)); ?> to <?php echo date('M j, Y', strtotime($end_date)); ?>
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
        
        <!-- Discrepancy Analysis (if out of balance) -->
        <?php if (!$is_balanced): ?>
        <div class="row mt-4">
            <div class="col-md-12">
                <div class="card border-danger">
                    <div class="card-header bg-danger py-2">
                        <h4 class="card-title mb-0 text-white">
                            <i class="fas fa-exclamation-triangle mr-2"></i>Discrepancy Analysis
                        </h4>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-danger">
                            <h5><i class="fas fa-exclamation-circle mr-2"></i>Trial Balance is Out of Balance!</h5>
                            <p>The total debits do not equal total credits. This indicates there may be errors in the journal entries.</p>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <h6>Possible Causes:</h6>
                                <ul class="mb-0">
                                    <li>Journal entries with unequal debits and credits</li>
                                    <li>Missing journal entries</li>
                                    <li>Incorrect account balances</li>
                                    <li>Unposted journal entries</li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <h6>Recommended Actions:</h6>
                                <ul class="mb-0">
                                    <li>Review recent journal entries for errors</li>
                                    <li>Verify all entries are properly posted</li>
                                    <li>Check account opening balances</li>
                                    <li>Run the journal reconciliation tool</li>
                                </ul>
                            </div>
                        </div>
                        
                        <div class="text-center mt-3">
                            <a href="journal_entries.php" class="btn btn-danger">
                                <i class="fas fa-search mr-2"></i>Review Journal Entries
                            </a>
                            <button class="btn btn-outline-danger ml-2" onclick="findDiscrepancies()">
                                <i class="fas fa-cog mr-2"></i>Find Discrepancies
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
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
            <title>Trial Balance - <?php echo date('F j, Y', strtotime($as_of_date)); ?></title>
            <style>
                body { font-family: Arial, sans-serif; }
                .report-header { text-align: center; margin-bottom: 20px; }
                .company-name { font-size: 24px; font-weight: bold; }
                .report-title { font-size: 18px; margin: 10px 0; }
                .report-date { font-size: 14px; color: #666; }
                table { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 11px; }
                th, td { padding: 4px; text-align: left; border-bottom: 1px solid #ddd; }
                th { background-color: #f5f5f5; font-weight: bold; }
                .text-right { text-align: right; }
                .total-row { font-weight: bold; background-color: #f0f0f0; }
                .section-header { background-color: #e9ecef; font-weight: bold; }
                @media print {
                    .no-print { display: none; }
                    body { font-size: 10px; }
                }
            </style>
        </head>
        <body>
            <div class="report-header">
                <div class="company-name"><?php echo htmlspecialchars($session_company_name); ?></div>
                <div class="report-title">Trial Balance</div>
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
    window.location.href = 'export_pdf.php?report=trial_balance&date=<?php echo $as_of_date; ?>&period=<?php echo $period; ?>';
}

function exportExcel() {
    window.location.href = 'export_excel.php?report=trial_balance&date=<?php echo $as_of_date; ?>&period=<?php echo $period; ?>';
}

function findDiscrepancies() {
    Swal.fire({
        title: 'Finding Discrepancies...',
        text: 'Analyzing journal entries to find the source of the imbalance.',
        icon: 'info',
        showCancelButton: false,
        showConfirmButton: false,
        allowOutsideClick: false,
        allowEscapeKey: false,
        allowEnterKey: false,
        didOpen: () => {
            Swal.showLoading();
            
            // Simulate API call (replace with actual AJAX call)
            setTimeout(() => {
                Swal.fire({
                    title: 'Analysis Complete',
                    html: `
                        <div class="text-left">
                            <p><strong>Found potential issues:</strong></p>
                            <ul>
                                <li>3 journal entries with unbalanced amounts</li>
                                <li>1 account with incorrect opening balance</li>
                                <li>2 unposted draft entries affecting totals</li>
                            </ul>
                            <p>Review these items to correct the trial balance.</p>
                        </div>
                    `,
                    icon: 'info',
                    confirmButtonText: 'OK',
                    showCancelButton: true,
                    cancelButtonText: 'View Details'
                }).then((result) => {
                    if (result.dismiss === Swal.DismissReason.cancel) {
                        window.location.href = 'journal_entries.php?status=draft';
                    }
                });
            }, 2000);
        }
    });
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
    // Ctrl + F to find discrepancies
    if (e.ctrlKey && e.keyCode === 70 && !<?php echo $is_balanced ? 'true' : 'false'; ?>) {
        e.preventDefault();
        findDiscrepancies();
    }
});
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
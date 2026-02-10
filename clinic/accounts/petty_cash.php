<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Initialize variables
$petty_cash_balance = 0;
$recent_transactions = [];
$pending_requests = [];
$month_stats = [];

// Get petty cash account
$petty_cash_sql = "SELECT a.account_id, a.account_name, a.current_balance, a.account_currency_code
                   FROM accounts a 
                   WHERE a.account_name LIKE '%petty cash%' 
                   AND a.account_archived_at IS NULL 
                   AND a.account_status = 'active'
                   LIMIT 1";
$petty_cash_result = $mysqli->query($petty_cash_sql);
$petty_cash_account = $petty_cash_result->fetch_assoc();

if ($petty_cash_account) {
    $petty_cash_balance = $petty_cash_account['current_balance'];
    $petty_cash_account_id = $petty_cash_account['account_id'];
    $petty_cash_currency = $petty_cash_account['account_currency_code'];
} else {
    // Create petty cash account if it doesn't exist
    $create_account_sql = "INSERT INTO accounts (
        account_number, account_name, account_type, account_description,
        current_balance, account_currency_code, account_status, created_by, updated_by
    ) VALUES ('1000', 'Petty Cash', 
        (SELECT type_id FROM account_types WHERE type_name = 'Cash' LIMIT 1),
        'Petty cash fund for small expenses', 0.00, ?, 'active', ?, ?)";
    
    $create_stmt = $mysqli->prepare($create_account_sql);
    $create_stmt->bind_param("sii", $session_company_currency, $session_user_id, $session_user_id);
    $create_stmt->execute();
    $petty_cash_account_id = $mysqli->insert_id;
    $petty_cash_currency = $session_company_currency;
}

// Get recent petty cash transactions
$transactions_sql = "SELECT jel.amount, jel.entry_type, jel.description, 
                            je.entry_date, je.reference_number,
                            a.account_name, u.user_name as created_by
                     FROM journal_entry_lines jel
                     JOIN journal_entries je ON jel.entry_id = je.entry_id
                     JOIN accounts a ON jel.account_id = a.account_id
                     LEFT JOIN users u ON je.created_by = u.user_id
                     WHERE jel.account_id = ?
                     ORDER BY je.entry_date DESC, je.entry_id DESC
                     LIMIT 10";
$transactions_stmt = $mysqli->prepare($transactions_sql);
$transactions_stmt->bind_param("i", $petty_cash_account_id);
$transactions_stmt->execute();
$transactions_result = $transactions_stmt->get_result();

while ($transaction = $transactions_result->fetch_assoc()) {
    $recent_transactions[] = $transaction;
}

// Get monthly statistics
$month_stats_sql = "SELECT 
    DATE_FORMAT(je.entry_date, '%Y-%m') as month,
    SUM(CASE WHEN jel.entry_type = 'debit' THEN jel.amount ELSE 0 END) as total_debits,
    SUM(CASE WHEN jel.entry_type = 'credit' THEN jel.amount ELSE 0 END) as total_credits,
    COUNT(*) as transaction_count
    FROM journal_entry_lines jel
    JOIN journal_entries je ON jel.entry_id = je.entry_id
    WHERE jel.account_id = ?
    AND je.entry_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(je.entry_date, '%Y-%m')
    ORDER BY month DESC
    LIMIT 6";
$month_stats_stmt = $mysqli->prepare($month_stats_sql);
$month_stats_stmt->bind_param("i", $petty_cash_account_id);
$month_stats_stmt->execute();
$month_stats_result = $month_stats_stmt->get_result();

while ($stat = $month_stats_result->fetch_assoc()) {
    $month_stats[] = $stat;
}

// Get current month totals
$current_month_sql = "SELECT 
    SUM(CASE WHEN jel.entry_type = 'debit' THEN jel.amount ELSE 0 END) as month_debits,
    SUM(CASE WHEN jel.entry_type = 'credit' THEN jel.amount ELSE 0 END) as month_credits,
    COUNT(*) as month_count
    FROM journal_entry_lines jel
    JOIN journal_entries je ON jel.entry_id = je.entry_id
    WHERE jel.account_id = ?
    AND MONTH(je.entry_date) = MONTH(CURDATE())
    AND YEAR(je.entry_date) = YEAR(CURDATE())";
$current_month_stmt = $mysqli->prepare($current_month_sql);
$current_month_stmt->bind_param("i", $petty_cash_account_id);
$current_month_stmt->execute();
$current_month_result = $current_month_stmt->get_result();
$current_month = $current_month_result->fetch_assoc();

// Calculate statistics
$total_transactions = count($recent_transactions);
$month_debits = $current_month['month_debits'] ?? 0;
$month_credits = $current_month['month_credits'] ?? 0;
$month_count = $current_month['month_count'] ?? 0;

// Get petty cash settings
$settings_sql = "SELECT setting_key, setting_value FROM system_settings 
                WHERE setting_key LIKE 'petty_cash_%'";
$settings_result = $mysqli->query($settings_sql);
$petty_cash_settings = [];
while ($setting = $settings_result->fetch_assoc()) {
    $petty_cash_settings[$setting['setting_key']] = $setting['setting_value'];
}

$max_transaction_amount = $petty_cash_settings['petty_cash_max_amount'] ?? 500.00;
$replenish_threshold = $petty_cash_settings['petty_cash_replenish_threshold'] ?? 100.00;
?>

<div class="card">
    <div class="card-header bg-info py-2">
        <h3 class="card-title mt-2 mb-0"><i class="fas fa-fw fa-wallet mr-2"></i>Petty Cash Management</h3>
        <div class="card-tools">
            <div class="btn-group">
                <a href="journal_entry_new.php?petty_cash=1" class="btn btn-success">
                    <i class="fas fa-plus mr-2"></i>New Transaction
                </a>
                <button type="button" class="btn btn-success dropdown-toggle dropdown-toggle-split" data-toggle="dropdown">
                    <span class="sr-only">Toggle Dropdown</span>
                </button>
                <div class="dropdown-menu">
                    <a class="dropdown-item" href="journal_entry_new.php?petty_cash=1&type=expense">
                        <i class="fas fa-arrow-up text-danger mr-2"></i>Record Expense
                    </a>
                    <a class="dropdown-item" href="journal_entry_new.php?petty_cash=1&type=replenish">
                        <i class="fas fa-arrow-down text-success mr-2"></i>Replenish Fund
                    </a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="reports_petty_cash.php">
                        <i class="fas fa-chart-bar mr-2"></i>Detailed Reports
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Statistics Row -->
    <div class="card-body border-bottom">
        <div class="row text-center">
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-primary"><i class="fas fa-wallet"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Current Balance</span>
                        <span class="info-box-number <?php echo $petty_cash_balance < $replenish_threshold ? 'text-danger' : 'text-success'; ?>">
                            <?php echo numfmt_format_currency($currency_format, $petty_cash_balance, $petty_cash_currency); ?>
                        </span>
                        <?php if ($petty_cash_balance < $replenish_threshold): ?>
                            <small class="text-danger">
                                <i class="fas fa-exclamation-triangle"></i> Below threshold
                            </small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-success"><i class="fas fa-arrow-down"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Month Income</span>
                        <span class="info-box-number text-success">
                            <?php echo numfmt_format_currency($currency_format, $month_credits, $petty_cash_currency); ?>
                        </span>
                        <small class="text-muted">Replenishments</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-danger"><i class="fas fa-arrow-up"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Month Expenses</span>
                        <span class="info-box-number text-danger">
                            <?php echo numfmt_format_currency($currency_format, $month_debits, $petty_cash_currency); ?>
                        </span>
                        <small class="text-muted">Payments</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-info"><i class="fas fa-exchange-alt"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Month Transactions</span>
                        <span class="info-box-number"><?php echo $month_count; ?></span>
                        <small class="text-muted">This month</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-warning"><i class="fas fa-money-bill-wave"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Max per Transaction</span>
                        <span class="info-box-number">
                            <?php echo numfmt_format_currency($currency_format, $max_transaction_amount, $petty_cash_currency); ?>
                        </span>
                        <small class="text-muted">Limit</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-secondary"><i class="fas fa-bell"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Replenish At</span>
                        <span class="info-box-number">
                            <?php echo numfmt_format_currency($currency_format, $replenish_threshold, $petty_cash_currency); ?>
                        </span>
                        <small class="text-muted">Threshold</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card-body">
        <div class="row">
            <!-- Left Column: Recent Transactions -->
            <div class="col-md-8">
                <!-- Quick Actions Card -->
                <div class="card card-success mb-4">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-bolt mr-2"></i>Quick Actions</h3>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <a href="journal_entry_new.php?petty_cash=1&type=expense" class="btn btn-danger btn-lg btn-block mb-2">
                                    <i class="fas fa-arrow-up mr-2"></i>Record Expense
                                </a>
                                <small class="text-muted d-block text-center">Record petty cash payment</small>
                            </div>
                            <div class="col-md-4">
                                <a href="journal_entry_new.php?petty_cash=1&type=replenish" class="btn btn-success btn-lg btn-block mb-2">
                                    <i class="fas fa-arrow-down mr-2"></i>Replenish Fund
                                </a>
                                <small class="text-muted d-block text-center">Add money to petty cash</small>
                            </div>
                            <div class="col-md-4">
                                <a href="reports_petty_cash.php" class="btn btn-info btn-lg btn-block mb-2">
                                    <i class="fas fa-chart-bar mr-2"></i>View Reports
                                </a>
                                <small class="text-muted d-block text-center">Detailed analysis</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Transactions Card -->
                <div class="card card-primary">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h3 class="card-title mb-0"><i class="fas fa-list-alt mr-2"></i>Recent Transactions</h3>
                        <a href="journal_entries.php?account=<?php echo $petty_cash_account_id; ?>" class="btn btn-sm btn-outline-primary">
                            View All <i class="fas fa-arrow-right ml-1"></i>
                        </a>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover table-striped mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Date</th>
                                        <th>Description</th>
                                        <th>Reference</th>
                                        <th>Type</th>
                                        <th class="text-right">Amount</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($recent_transactions)): ?>
                                        <?php foreach ($recent_transactions as $transaction): ?>
                                            <tr>
                                                <td>
                                                    <div class="font-weight-bold"><?php echo date('M j, Y', strtotime($transaction['entry_date'])); ?></div>
                                                    <small class="text-muted"><?php echo date('H:i', strtotime($transaction['entry_date'])); ?></small>
                                                </td>
                                                <td>
                                                    <div class="font-weight-bold"><?php echo htmlspecialchars($transaction['description']); ?></div>
                                                    <small class="text-muted"><?php echo htmlspecialchars($transaction['account_name']); ?></small>
                                                    <?php if ($transaction['created_by']): ?>
                                                        <br><small class="text-muted">By: <?php echo htmlspecialchars($transaction['created_by']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge badge-light"><?php echo htmlspecialchars($transaction['reference_number']); ?></span>
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
                                                    <div class="btn-group btn-group-sm">
                                                        <button type="button" class="btn btn-info" title="View Details">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                        <button type="button" class="btn btn-warning" title="Edit">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="text-center py-4">
                                                <i class="fas fa-receipt fa-3x text-muted mb-3"></i>
                                                <h5>No Transactions Found</h5>
                                                <p class="text-muted mb-0">No petty cash transactions recorded yet.</p>
                                                <a href="journal_entry_new.php?petty_cash=1" class="btn btn-success mt-2">
                                                    <i class="fas fa-plus mr-2"></i>Record First Transaction
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

            <!-- Right Column: Analytics & Tools -->
            <div class="col-md-4">
                <!-- Balance Alert Card -->
                <div class="card <?php echo $petty_cash_balance < $replenish_threshold ? 'card-danger' : 'card-success'; ?>">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-bell mr-2"></i>Balance Status</h3>
                    </div>
                    <div class="card-body text-center">
                        <div class="display-4 <?php echo $petty_cash_balance < $replenish_threshold ? 'text-danger' : 'text-success'; ?>">
                            <?php echo numfmt_format_currency($currency_format, $petty_cash_balance, $petty_cash_currency); ?>
                        </div>
                        <p class="mb-2">Current Petty Cash Balance</p>
                        
                        <?php if ($petty_cash_balance < $replenish_threshold): ?>
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-triangle mr-2"></i>
                                <strong>Low Balance Alert!</strong><br>
                                Balance is below replenishment threshold of 
                                <?php echo numfmt_format_currency($currency_format, $replenish_threshold, $petty_cash_currency); ?>
                            </div>
                            <a href="journal_entry_new.php?petty_cash=1&type=replenish" class="btn btn-success btn-block">
                                <i class="fas fa-plus-circle mr-2"></i>Replenish Now
                            </a>
                        <?php else: ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle mr-2"></i>
                                <strong>Balance is Healthy</strong><br>
                                <?php echo numfmt_format_currency($currency_format, ($petty_cash_balance - $replenish_threshold), $petty_cash_currency); ?> above threshold
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Monthly Statistics Card -->
                <div class="card card-info">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-chart-bar mr-2"></i>Monthly Overview</h3>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($month_stats)): ?>
                            <?php foreach (array_slice($month_stats, 0, 3) as $stat): ?>
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between mb-1">
                                        <span class="font-weight-bold"><?php echo date('F Y', strtotime($stat['month'] . '-01')); ?></span>
                                        <span class="badge badge-light"><?php echo $stat['transaction_count']; ?> trans</span>
                                    </div>
                                    <div class="progress" style="height: 20px;">
                                        <?php 
                                        $total = $stat['total_debits'] + $stat['total_credits'];
                                        $debit_percent = $total > 0 ? ($stat['total_debits'] / $total) * 100 : 0;
                                        $credit_percent = $total > 0 ? ($stat['total_credits'] / $total) * 100 : 0;
                                        ?>
                                        <div class="progress-bar bg-danger" style="width: <?php echo $debit_percent; ?>%" 
                                             title="Expenses: <?php echo numfmt_format_currency($currency_format, $stat['total_debits'], $petty_cash_currency); ?>">
                                        </div>
                                        <div class="progress-bar bg-success" style="width: <?php echo $credit_percent; ?>%" 
                                             title="Income: <?php echo numfmt_format_currency($currency_format, $stat['total_credits'], $petty_cash_currency); ?>">
                                        </div>
                                    </div>
                                    <div class="d-flex justify-content-between mt-1 small text-muted">
                                        <span>Exp: <?php echo numfmt_format_currency($currency_format, $stat['total_debits'], $petty_cash_currency); ?></span>
                                        <span>Inc: <?php echo numfmt_format_currency($currency_format, $stat['total_credits'], $petty_cash_currency); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center text-muted py-3">
                                <i class="fas fa-chart-bar fa-2x mb-2"></i>
                                <p>No monthly data available</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Quick Tools Card -->
                <div class="card card-warning">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-tools mr-2"></i>Quick Tools</h3>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="reports_petty_cash.php?export=pdf" class="btn btn-outline-primary btn-sm">
                                <i class="fas fa-file-pdf mr-2"></i>Export PDF Report
                            </a>
                            <a href="reports_petty_cash.php?period=month" class="btn btn-outline-info btn-sm">
                                <i class="fas fa-calendar mr-2"></i>Monthly Statement
                            </a>
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-toggle="modal" data-target="#settingsModal">
                                <i class="fas fa-cog mr-2"></i>Settings
                            </button>
                            <a href="account_ledger.php?id=<?php echo $petty_cash_account_id; ?>" class="btn btn-outline-dark btn-sm">
                                <i class="fas fa-book mr-2"></i>View Full Ledger
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Petty Cash Guidelines -->
                <div class="card card-light">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-info-circle mr-2"></i>Petty Cash Guidelines</h3>
                    </div>
                    <div class="card-body">
                        <small class="text-muted">
                            <strong>Usage Rules:</strong><br>
                            • Max per transaction: <strong><?php echo numfmt_format_currency($currency_format, $max_transaction_amount, $petty_cash_currency); ?></strong><br>
                            • Replenish when below: <strong><?php echo numfmt_format_currency($currency_format, $replenish_threshold, $petty_cash_currency); ?></strong><br>
                            • Always get receipts<br>
                            • Record immediately after payment<br><br>
                            <strong>Common Uses:</strong><br>
                            • Office supplies<br>
                            • Small repairs<br>
                            • Emergency expenses<br>
                            • Staff refreshments
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Settings Modal -->
<div class="modal fade" id="settingsModal" tabindex="-1" role="dialog" aria-labelledby="settingsModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="settingsModalLabel">Petty Cash Settings</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST" action="post/update_petty_cash_settings.php">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    
                    <div class="form-group">
                        <label for="max_transaction_amount">Maximum Transaction Amount</label>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text"><?php echo $petty_cash_currency; ?></span>
                            </div>
                            <input type="number" class="form-control" id="max_transaction_amount" 
                                   name="max_transaction_amount" step="0.01" min="0" 
                                   value="<?php echo $max_transaction_amount; ?>" required>
                        </div>
                        <small class="form-text text-muted">Maximum amount allowed for a single petty cash transaction</small>
                    </div>

                    <div class="form-group">
                        <label for="replenish_threshold">Replenishment Threshold</label>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text"><?php echo $petty_cash_currency; ?></span>
                            </div>
                            <input type="number" class="form-control" id="replenish_threshold" 
                                   name="replenish_threshold" step="0.01" min="0" 
                                   value="<?php echo $replenish_threshold; ?>" required>
                        </div>
                        <small class="form-text text-muted">Alert when balance falls below this amount</small>
                    </div>

                    <div class="form-group">
                        <label for="default_category">Default Expense Category</label>
                        <select class="form-control select2" id="default_category" name="default_category">
                            <option value="">- Select Default Category -</option>
                            <?php
                            $categories_sql = "SELECT account_id, account_name FROM accounts 
                                             WHERE account_type IN (SELECT type_id FROM account_types WHERE type_class = 'Expense')
                                             AND account_archived_at IS NULL 
                                             ORDER BY account_name";
                            $categories_result = $mysqli->query($categories_sql);
                            while ($category = $categories_result->fetch_assoc()): ?>
                                <option value="<?php echo $category['account_id']; ?>"
                                    <?php echo ($petty_cash_settings['petty_cash_default_category'] ?? '') == $category['account_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($category['account_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                        <small class="form-text text-muted">Default expense account for petty cash transactions</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save mr-2"></i>Save Settings
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize Select2 in modal
    $('.select2').select2({
        width: '100%',
        dropdownParent: $('#settingsModal')
    });

    // Auto-refresh balance every 30 seconds
    setInterval(function() {
        $.get('ajax/get_petty_cash_balance.php', function(data) {
            if (data.balance !== undefined) {
                $('.info-box-number:first').text(data.balance_formatted);
                $('.display-4').text(data.balance_formatted);
                
                // Update alert status
                if (data.balance < data.threshold) {
                    $('.card-danger .alert').show();
                    $('.card-success .alert').hide();
                } else {
                    $('.card-danger .alert').hide();
                    $('.card-success .alert').show();
                }
            }
        });
    }, 30000);

    // Quick expense form
    $('#quickExpenseBtn').click(function() {
        $('#quickExpenseModal').modal('show');
    });

    // Keyboard shortcuts
    $(document).keydown(function(e) {
        // Ctrl + E for quick expense
        if (e.ctrlKey && e.keyCode === 69) {
            e.preventDefault();
            window.location.href = 'journal_entry_new.php?petty_cash=1&type=expense';
        }
        // Ctrl + R for replenish
        if (e.ctrlKey && e.keyCode === 82) {
            e.preventDefault();
            window.location.href = 'journal_entry_new.php?petty_cash=1&type=replenish';
        }
        // Ctrl + S for settings
        if (e.ctrlKey && e.keyCode === 83) {
            e.preventDefault();
            $('#settingsModal').modal('show');
        }
    });

    // Low balance notification
    <?php if ($petty_cash_balance < $replenish_threshold): ?>
        setTimeout(function() {
            toastr.warning(
                'Petty cash balance is low! Current balance: <?php echo numfmt_format_currency($currency_format, $petty_cash_balance, $petty_cash_currency); ?>',
                'Low Balance Alert',
                {
                    timeOut: 10000,
                    extendedTimeOut: 5000,
                    closeButton: true,
                    progressBar: true
                }
            );
        }, 2000);
    <?php endif; ?>
});

// Quick balance check
function checkBalance() {
    $.get('ajax/get_petty_cash_balance.php', function(data) {
        if (data.balance !== undefined) {
            alert('Current Petty Cash Balance: ' + data.balance_formatted);
        }
    });
}
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
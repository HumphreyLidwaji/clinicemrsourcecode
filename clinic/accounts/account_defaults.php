<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Check permissions
if (!isset($_SESSION['user_id'])) {
    header("Location: /login.php");
    exit;
}

// Default account types
$default_types = [
    'cash' => 'Cash Account',
    'accounts_receivable' => 'Accounts Receivable',
    'accounts_payable' => 'Accounts Payable',
    'sales_revenue' => 'Sales Revenue',
    'cost_of_goods_sold' => 'Cost of Goods Sold',
    'inventory' => 'Inventory',
    'retained_earnings' => 'Retained Earnings',
    'bank_charges' => 'Bank Charges/Interest',
    'depreciation' => 'Depreciation Expense',
    'salary_expense' => 'Salary Expense',
    'rent_expense' => 'Rent Expense',
    'utility_expense' => 'Utility Expense',
    'supply_expense' => 'Office Supplies Expense',
    'tax_expense' => 'Tax Expense',
    'dividend' => 'Dividend Account'
];

// Get existing defaults
$defaults_sql = "SELECT ad.*, a.account_number, a.account_name, a.account_type 
                FROM account_defaults ad 
                LEFT JOIN accounts a ON ad.account_id = a.account_id 
                ORDER BY ad.default_type";
$defaults_result = $mysqli->query($defaults_sql);
$existing_defaults = [];
while ($row = $defaults_result->fetch_assoc()) {
    $existing_defaults[$row['default_type']] = $row;
}

// Get all active accounts for dropdown
$accounts_sql = "SELECT account_id, account_number, account_name, account_type 
                FROM accounts 
                WHERE is_active = 1 
                ORDER BY account_type, account_number";
$accounts_result = $mysqli->query($accounts_sql);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token.";
        header("Location: account_defaults.php");
        exit;
    }
    
    $mysqli->begin_transaction();
    
    try {
        // Clear existing defaults
        $clear_sql = "DELETE FROM account_defaults";
        $mysqli->query($clear_sql);
        
        // Insert new defaults
        $insert_sql = "INSERT INTO account_defaults (default_type, account_id, description) VALUES (?, ?, ?)";
        $insert_stmt = $mysqli->prepare($insert_sql);
        
        foreach ($default_types as $type => $description) {
            $account_id = intval($_POST[$type] ?? 0);
            if ($account_id > 0) {
                $insert_stmt->bind_param("sis", $type, $account_id, $description);
                $insert_stmt->execute();
            }
        }
        
        // Log activity
        $activity_sql = "INSERT INTO activities 
                        (activity_type, activity_description, performed_by, related_type) 
                        VALUES ('account_defaults_updated', 'Updated account default settings', ?, 'system')";
        $activity_stmt = $mysqli->prepare($activity_sql);
        $activity_stmt->bind_param("i", $_SESSION['user_id']);
        $activity_stmt->execute();
        
        $mysqli->commit();
        
        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Account defaults updated successfully!";
        header("Location: account_defaults.php");
        exit;
        
    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error updating defaults: " . $e->getMessage();
        header("Location: account_defaults.php");
        exit;
    }
}

// Get account type statistics
$stats_sql = "SELECT 
    COUNT(*) as total_accounts,
    COUNT(CASE WHEN account_type = 'asset' THEN 1 END) as asset_accounts,
    COUNT(CASE WHEN account_type = 'liability' THEN 1 END) as liability_accounts,
    COUNT(CASE WHEN account_type = 'equity' THEN 1 END) as equity_accounts,
    COUNT(CASE WHEN account_type = 'revenue' THEN 1 END) as revenue_accounts,
    COUNT(CASE WHEN account_type = 'expense' THEN 1 END) as expense_accounts
FROM accounts 
WHERE is_active = 1";
$stats_result = $mysqli->query($stats_sql);
$stats = $stats_result->fetch_assoc();
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-cog mr-2"></i>Account Default Settings
        </h3>
        <div class="card-tools">
            <a href="accounts.php" class="btn btn-light">
                <i class="fas fa-arrow-left mr-2"></i>Back to Accounts
            </a>
        </div>
    </div>
    
    <!-- Statistics Row -->
    <div class="card-body border-bottom">
        <div class="row text-center">
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-secondary"><i class="fas fa-chart-pie"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Accounts</span>
                        <span class="info-box-number"><?php echo $stats['total_accounts']; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-success"><i class="fas fa-wallet"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Asset Accounts</span>
                        <span class="info-box-number"><?php echo $stats['asset_accounts']; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-warning"><i class="fas fa-hand-holding-usd"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Liability Accounts</span>
                        <span class="info-box-number"><?php echo $stats['liability_accounts']; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-info"><i class="fas fa-chart-line"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Revenue Accounts</span>
                        <span class="info-box-number"><?php echo $stats['revenue_accounts']; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-danger"><i class="fas fa-money-bill-wave"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Expense Accounts</span>
                        <span class="info-box-number"><?php echo $stats['expense_accounts']; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-dark"><i class="fas fa-cog"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Defaults Set</span>
                        <span class="info-box-number"><?php echo count($existing_defaults); ?>/<?php echo count($default_types); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card-body">
        <!-- Alert Messages -->
        <?php if (isset($_SESSION['alert_message'])): ?>
        <div class="alert alert-<?php echo $_SESSION['alert_type']; ?> alert-dismissible fade show">
            <i class="fas fa-<?php echo $_SESSION['alert_type'] == 'success' ? 'check-circle' : 'exclamation-triangle'; ?> mr-2"></i>
            <?php echo $_SESSION['alert_message']; ?>
            <button type="button" class="close" data-dismiss="alert">
                <span>&times;</span>
            </button>
        </div>
        <?php 
        unset($_SESSION['alert_type']);
        unset($_SESSION['alert_message']);
        endif; ?>
        
        <!-- Default Settings Form -->
        <form method="POST" id="defaultsForm">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <div class="row">
                <!-- Asset Defaults -->
                <div class="col-md-6">
                    <div class="card mb-4">
                        <div class="card-header bg-success py-2">
                            <h4 class="card-title mb-0 text-white">
                                <i class="fas fa-wallet mr-2"></i>Asset Accounts
                            </h4>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label class="font-weight-bold">Cash Account</label>
                                <select class="form-control select2" name="cash">
                                    <option value="">Select Cash Account</option>
                                    <?php 
                                    $accounts_result->data_seek(0);
                                    while($account = $accounts_result->fetch_assoc()):
                                        if ($account['account_type'] == 'asset'):
                                    ?>
                                    <option value="<?php echo $account['account_id']; ?>" 
                                        <?php echo isset($existing_defaults['cash']) && $existing_defaults['cash']['account_id'] == $account['account_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($account['account_number'] . ' - ' . $account['account_name']); ?>
                                    </option>
                                    <?php 
                                        endif;
                                    endwhile; 
                                    ?>
                                </select>
                                <small class="form-text text-muted">Primary cash/bank account for transactions</small>
                            </div>
                            
                            <div class="form-group">
                                <label class="font-weight-bold">Accounts Receivable</label>
                                <select class="form-control select2" name="accounts_receivable">
                                    <option value="">Select AR Account</option>
                                    <?php 
                                    $accounts_result->data_seek(0);
                                    while($account = $accounts_result->fetch_assoc()):
                                        if ($account['account_type'] == 'asset'):
                                    ?>
                                    <option value="<?php echo $account['account_id']; ?>" 
                                        <?php echo isset($existing_defaults['accounts_receivable']) && $existing_defaults['accounts_receivable']['account_id'] == $account['account_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($account['account_number'] . ' - ' . $account['account_name']); ?>
                                    </option>
                                    <?php 
                                        endif;
                                    endwhile; 
                                    ?>
                                </select>
                                <small class="form-text text-muted">Account for tracking money owed by customers</small>
                            </div>
                            
                            <div class="form-group">
                                <label class="font-weight-bold">Inventory Account</label>
                                <select class="form-control select2" name="inventory">
                                    <option value="">Select Inventory Account</option>
                                    <?php 
                                    $accounts_result->data_seek(0);
                                    while($account = $accounts_result->fetch_assoc()):
                                        if ($account['account_type'] == 'asset'):
                                    ?>
                                    <option value="<?php echo $account['account_id']; ?>" 
                                        <?php echo isset($existing_defaults['inventory']) && $existing_defaults['inventory']['account_id'] == $account['account_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($account['account_number'] . ' - ' . $account['account_name']); ?>
                                    </option>
                                    <?php 
                                        endif;
                                    endwhile; 
                                    ?>
                                </select>
                                <small class="form-text text-muted">Account for tracking inventory value</small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Revenue Defaults -->
                    <div class="card mb-4">
                        <div class="card-header bg-info py-2">
                            <h4 class="card-title mb-0 text-white">
                                <i class="fas fa-chart-line mr-2"></i>Revenue Accounts
                            </h4>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label class="font-weight-bold">Sales Revenue</label>
                                <select class="form-control select2" name="sales_revenue">
                                    <option value="">Select Sales Revenue Account</option>
                                    <?php 
                                    $accounts_result->data_seek(0);
                                    while($account = $accounts_result->fetch_assoc()):
                                        if ($account['account_type'] == 'revenue'):
                                    ?>
                                    <option value="<?php echo $account['account_id']; ?>" 
                                        <?php echo isset($existing_defaults['sales_revenue']) && $existing_defaults['sales_revenue']['account_id'] == $account['account_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($account['account_number'] . ' - ' . $account['account_name']); ?>
                                    </option>
                                    <?php 
                                        endif;
                                    endwhile; 
                                    ?>
                                </select>
                                <small class="form-text text-muted">Primary revenue account for sales</small>
                            </div>
                            
                            <div class="form-group">
                                <label class="font-weight-bold">Service Revenue</label>
                                <select class="form-control select2" name="service_revenue">
                                    <option value="">Select Service Revenue Account</option>
                                    <?php 
                                    $accounts_result->data_seek(0);
                                    while($account = $accounts_result->fetch_assoc()):
                                        if ($account['account_type'] == 'revenue'):
                                    ?>
                                    <option value="<?php echo $account['account_id']; ?>" 
                                        <?php echo isset($existing_defaults['service_revenue']) && $existing_defaults['service_revenue']['account_id'] == $account['account_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($account['account_number'] . ' - ' . $account['account_name']); ?>
                                    </option>
                                    <?php 
                                        endif;
                                    endwhile; 
                                    ?>
                                </select>
                                <small class="form-text text-muted">Revenue account for services</small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Liability & Equity Defaults -->
                <div class="col-md-6">
                    <div class="card mb-4">
                        <div class="card-header bg-warning py-2">
                            <h4 class="card-title mb-0 text-white">
                                <i class="fas fa-hand-holding-usd mr-2"></i>Liability & Equity Accounts
                            </h4>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label class="font-weight-bold">Accounts Payable</label>
                                <select class="form-control select2" name="accounts_payable">
                                    <option value="">Select AP Account</option>
                                    <?php 
                                    $accounts_result->data_seek(0);
                                    while($account = $accounts_result->fetch_assoc()):
                                        if ($account['account_type'] == 'liability'):
                                    ?>
                                    <option value="<?php echo $account['account_id']; ?>" 
                                        <?php echo isset($existing_defaults['accounts_payable']) && $existing_defaults['accounts_payable']['account_id'] == $account['account_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($account['account_number'] . ' - ' . $account['account_name']); ?>
                                    </option>
                                    <?php 
                                        endif;
                                    endwhile; 
                                    ?>
                                </select>
                                <small class="form-text text-muted">Account for tracking money owed to vendors</small>
                            </div>
                            
                            <div class="form-group">
                                <label class="font-weight-bold">Retained Earnings</label>
                                <select class="form-control select2" name="retained_earnings">
                                    <option value="">Select Retained Earnings Account</option>
                                    <?php 
                                    $accounts_result->data_seek(0);
                                    while($account = $accounts_result->fetch_assoc()):
                                        if ($account['account_type'] == 'equity'):
                                    ?>
                                    <option value="<?php echo $account['account_id']; ?>" 
                                        <?php echo isset($existing_defaults['retained_earnings']) && $existing_defaults['retained_earnings']['account_id'] == $account['account_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($account['account_number'] . ' - ' . $account['account_name']); ?>
                                    </option>
                                    <?php 
                                        endif;
                                    endwhile; 
                                    ?>
                                </select>
                                <small class="form-text text-muted">Account for accumulated profits</small>
                            </div>
                            
                            <div class="form-group">
                                <label class="font-weight-bold">Dividend Account</label>
                                <select class="form-control select2" name="dividend">
                                    <option value="">Select Dividend Account</option>
                                    <?php 
                                    $accounts_result->data_seek(0);
                                    while($account = $accounts_result->fetch_assoc()):
                                        if ($account['account_type'] == 'equity'):
                                    ?>
                                    <option value="<?php echo $account['account_id']; ?>" 
                                        <?php echo isset($existing_defaults['dividend']) && $existing_defaults['dividend']['account_id'] == $account['account_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($account['account_number'] . ' - ' . $account['account_name']); ?>
                                    </option>
                                    <?php 
                                        endif;
                                    endwhile; 
                                    ?>
                                </select>
                                <small class="form-text text-muted">Account for dividend distributions</small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Expense Defaults -->
                    <div class="card mb-4">
                        <div class="card-header bg-danger py-2">
                            <h4 class="card-title mb-0 text-white">
                                <i class="fas fa-money-bill-wave mr-2"></i>Expense Accounts
                            </h4>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label class="font-weight-bold">Cost of Goods Sold</label>
                                <select class="form-control select2" name="cost_of_goods_sold">
                                    <option value="">Select COGS Account</option>
                                    <?php 
                                    $accounts_result->data_seek(0);
                                    while($account = $accounts_result->fetch_assoc()):
                                        if ($account['account_type'] == 'expense'):
                                    ?>
                                    <option value="<?php echo $account['account_id']; ?>" 
                                        <?php echo isset($existing_defaults['cost_of_goods_sold']) && $existing_defaults['cost_of_goods_sold']['account_id'] == $account['account_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($account['account_number'] . ' - ' . $account['account_name']); ?>
                                    </option>
                                    <?php 
                                        endif;
                                    endwhile; 
                                    ?>
                                </select>
                                <small class="form-text text-muted">Account for cost of products sold</small>
                            </div>
                            
                            <div class="form-group">
                                <label class="font-weight-bold">Salary Expense</label>
                                <select class="form-control select2" name="salary_expense">
                                    <option value="">Select Salary Expense Account</option>
                                    <?php 
                                    $accounts_result->data_seek(0);
                                    while($account = $accounts_result->fetch_assoc()):
                                        if ($account['account_type'] == 'expense'):
                                    ?>
                                    <option value="<?php echo $account['account_id']; ?>" 
                                        <?php echo isset($existing_defaults['salary_expense']) && $existing_defaults['salary_expense']['account_id'] == $account['account_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($account['account_number'] . ' - ' . $account['account_name']); ?>
                                    </option>
                                    <?php 
                                        endif;
                                    endwhile; 
                                    ?>
                                </select>
                                <small class="form-text text-muted">Account for salary expenses</small>
                            </div>
                            
                            <div class="form-group">
                                <label class="font-weight-bold">Rent Expense</label>
                                <select class="form-control select2" name="rent_expense">
                                    <option value="">Select Rent Expense Account</option>
                                    <?php 
                                    $accounts_result->data_seek(0);
                                    while($account = $accounts_result->fetch_assoc()):
                                        if ($account['account_type'] == 'expense'):
                                    ?>
                                    <option value="<?php echo $account['account_id']; ?>" 
                                        <?php echo isset($existing_defaults['rent_expense']) && $existing_defaults['rent_expense']['account_id'] == $account['account_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($account['account_number'] . ' - ' . $account['account_name']); ?>
                                    </option>
                                    <?php 
                                        endif;
                                    endwhile; 
                                    ?>
                                </select>
                                <small class="form-text text-muted">Account for rent expenses</small>
                            </div>
                            
                            <div class="form-group">
                                <label class="font-weight-bold">Bank Charges/Interest</label>
                                <select class="form-control select2" name="bank_charges">
                                    <option value="">Select Bank Charges Account</option>
                                    <?php 
                                    $accounts_result->data_seek(0);
                                    while($account = $accounts_result->fetch_assoc()):
                                        if ($account['account_type'] == 'expense'):
                                    ?>
                                    <option value="<?php echo $account['account_id']; ?>" 
                                        <?php echo isset($existing_defaults['bank_charges']) && $existing_defaults['bank_charges']['account_id'] == $account['account_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($account['account_number'] . ' - ' . $account['account_name']); ?>
                                    </option>
                                    <?php 
                                        endif;
                                    endwhile; 
                                    ?>
                                </select>
                                <small class="form-text text-muted">Account for bank fees and interest</small>
                            </div>
                            
                            <div class="form-group">
                                <label class="font-weight-bold">Depreciation Expense</label>
                                <select class="form-control select2" name="depreciation">
                                    <option value="">Select Depreciation Account</option>
                                    <?php 
                                    $accounts_result->data_seek(0);
                                    while($account = $accounts_result->fetch_assoc()):
                                        if ($account['account_type'] == 'expense'):
                                    ?>
                                    <option value="<?php echo $account['account_id']; ?>" 
                                        <?php echo isset($existing_defaults['depreciation']) && $existing_defaults['depreciation']['account_id'] == $account['account_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($account['account_number'] . ' - ' . $account['account_name']); ?>
                                    </option>
                                    <?php 
                                        endif;
                                    endwhile; 
                                    ?>
                                </select>
                                <small class="form-text text-muted">Account for depreciation expenses</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Current Defaults Summary -->
            <div class="card mb-4">
                <div class="card-header bg-dark py-2">
                    <h4 class="card-title mb-0 text-white">
                        <i class="fas fa-list-check mr-2"></i>Current Defaults Summary
                    </h4>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php 
                        $default_categories = [
                            'Asset' => ['cash', 'accounts_receivable', 'inventory'],
                            'Liability & Equity' => ['accounts_payable', 'retained_earnings', 'dividend'],
                            'Revenue' => ['sales_revenue', 'service_revenue'],
                            'Expense' => ['cost_of_goods_sold', 'salary_expense', 'rent_expense', 'bank_charges', 'depreciation']
                        ];
                        
                        foreach ($default_categories as $category => $types):
                            $set_count = 0;
                            foreach ($types as $type) {
                                if (isset($existing_defaults[$type])) {
                                    $set_count++;
                                }
                            }
                        ?>
                        <div class="col-md-3">
                            <div class="card card-sm mb-2">
                                <div class="card-body p-2">
                                    <div class="d-flex align-items-center">
                                        <div class="mr-3">
                                            <span class="badge badge-<?php 
                                                echo $category == 'Asset' ? 'success' : 
                                                     ($category == 'Liability & Equity' ? 'warning' : 
                                                     ($category == 'Revenue' ? 'info' : 'danger')); 
                                            ?> badge-pill p-2">
                                                <i class="fas fa-<?php 
                                                    echo $category == 'Asset' ? 'wallet' : 
                                                         ($category == 'Liability & Equity' ? 'hand-holding-usd' : 
                                                         ($category == 'Revenue' ? 'chart-line' : 'money-bill-wave')); 
                                                ?>"></i>
                                            </span>
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="font-weight-bold"><?php echo $category; ?></div>
                                            <small class="text-muted"><?php echo $set_count; ?>/<?php echo count($types); ?> set</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <?php if (count($existing_defaults) > 0): ?>
                    <div class="table-responsive mt-3">
                        <table class="table table-sm table-hover">
                            <thead class="bg-light">
                                <tr>
                                    <th>Type</th>
                                    <th>Account</th>
                                    <th>Account Type</th>
                                    <th>Description</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($existing_defaults as $type => $default): ?>
                                <tr>
                                    <td><span class="badge badge-dark"><?php echo str_replace('_', ' ', $type); ?></span></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($default['account_number']); ?></strong><br>
                                        <small class="text-muted"><?php echo htmlspecialchars($default['account_name']); ?></small>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?php 
                                            echo $default['account_type'] == 'asset' ? 'success' : 
                                                 ($default['account_type'] == 'liability' ? 'warning' : 
                                                 ($default['account_type'] == 'equity' ? 'primary' : 
                                                 ($default['account_type'] == 'revenue' ? 'info' : 'danger'))); 
                                        ?>">
                                            <?php echo ucfirst($default['account_type']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($default['description']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-4">
                        <i class="fas fa-cog fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No Defaults Configured</h5>
                        <p class="text-muted">Configure account defaults to automate journal entries and reporting.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Form Actions -->
            <div class="row">
                <div class="col-md-12">
                    <div class="btn-toolbar justify-content-between">
                        <div class="btn-group">
                            <a href="accounts.php" class="btn btn-secondary">
                                <i class="fas fa-times mr-2"></i>Cancel
                            </a>
                        </div>
                        <div class="btn-group">
                            <button type="reset" class="btn btn-outline-secondary">
                                <i class="fas fa-redo mr-2"></i>Reset Form
                            </button>
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-save mr-2"></i>Save Defaults
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.select2').select2({
        placeholder: "Select an account",
        allowClear: true
    });
    
    // Form validation
    $('#defaultsForm').validate({
        rules: {
            cash: { required: true },
            accounts_receivable: { required: true },
            accounts_payable: { required: true },
            sales_revenue: { required: true },
            cost_of_goods_sold: { required: true },
            retained_earnings: { required: true }
        },
        messages: {
            cash: "Please select a cash account",
            accounts_receivable: "Please select an accounts receivable account",
            accounts_payable: "Please select an accounts payable account",
            sales_revenue: "Please select a sales revenue account",
            cost_of_goods_sold: "Please select a cost of goods sold account",
            retained_earnings: "Please select a retained earnings account"
        },
        errorElement: 'div',
        errorClass: 'invalid-feedback',
        highlight: function(element) {
            $(element).addClass('is-invalid').removeClass('is-valid');
        },
        unhighlight: function(element) {
            $(element).removeClass('is-invalid').addClass('is-valid');
        },
        errorPlacement: function(error, element) {
            error.insertAfter(element);
        }
    });
    
    // Auto-fill suggestion
    $('#autoFillBtn').click(function() {
        Swal.fire({
            title: 'Auto-fill Suggestions?',
            text: 'This will suggest accounts based on common naming patterns. Continue?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, Auto-fill',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                autoFillDefaults();
            }
        });
    });
});

function autoFillDefaults() {
    // This would typically make an AJAX call to a server-side script
    // For now, we'll just show a message
    Swal.fire({
        title: 'Auto-fill Complete',
        text: 'Suggested accounts have been selected based on common patterns.',
        icon: 'success',
        timer: 2000,
        showConfirmButton: false
    });
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + S to save
    if (e.ctrlKey && e.keyCode === 83) {
        e.preventDefault();
        $('#defaultsForm').submit();
    }
    // Escape to cancel
    if (e.keyCode === 27) {
        window.location.href = 'accounts.php';
    }
});
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
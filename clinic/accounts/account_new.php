<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Check permissions
if (!isset($_SESSION['user_id'])) {
    header("Location: /login.php");
    exit;
}

// Initialize variables
$account_number = $account_name = $account_type = $account_subtype = $parent_account_id = $normal_balance = $description = '';
$errors = [];

// Get parent accounts for dropdown
$parent_accounts_sql = "SELECT account_id, account_number, account_name, account_type 
                       FROM accounts 
                       WHERE parent_account_id IS NULL 
                       AND is_active = 1 
                       ORDER BY account_number";
$parent_accounts = $mysqli->query($parent_accounts_sql);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errors[] = "Invalid CSRF token.";
    }
    
    // Get and sanitize inputs
    $account_number = sanitizeInput($_POST['account_number'] ?? '');
    $account_name = sanitizeInput($_POST['account_name'] ?? '');
    $account_type = sanitizeInput($_POST['account_type'] ?? '');
    $account_subtype = sanitizeInput($_POST['account_subtype'] ?? '');
    $parent_account_id = intval($_POST['parent_account_id'] ?? NULL);
    $normal_balance = sanitizeInput($_POST['normal_balance'] ?? 'debit');
    $description = sanitizeInput($_POST['description'] ?? '');
    
    // Validate required fields
    if (empty($account_number)) {
        $errors[] = "Account number is required.";
    }
    
    if (empty($account_name)) {
        $errors[] = "Account name is required.";
    }
    
    if (empty($account_type)) {
        $errors[] = "Account type is required.";
    }
    
    // Check if account number already exists
    $check_sql = "SELECT account_id FROM accounts WHERE account_number = ?";
    $check_stmt = $mysqli->prepare($check_sql);
    $check_stmt->bind_param("s", $account_number);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        $errors[] = "Account number already exists.";
    }
    
    // If no errors, insert the account
    if (empty($errors)) {
        $insert_sql = "INSERT INTO accounts 
                      (account_number, account_name, account_type, account_subtype, 
                       parent_account_id, normal_balance, description, is_active, created_by, updated_by) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)";
        
        $insert_stmt = $mysqli->prepare($insert_sql);
        $insert_stmt->bind_param("ssssissii", 
            $account_number,
            $account_name,
            $account_type,
            $account_subtype,
            $parent_account_id,
            $normal_balance,
            $description,
            $_SESSION['user_id'],
            $_SESSION['user_id']
        );
        
        if ($insert_stmt->execute()) {
            $account_id = $insert_stmt->insert_id;
            
            // Log activity
            $activity_sql = "INSERT INTO activities 
                            (activity_type, activity_description, performed_by, related_type, related_id) 
                            VALUES ('account_created', 'Created new account: $account_number - $account_name', 
                                    ?, 'account', ?)";
            $activity_stmt = $mysqli->prepare($activity_sql);
            $activity_stmt->bind_param("ii", $_SESSION['user_id'], $account_id);
            $activity_stmt->execute();
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Account created successfully!";
            header("Location: accounts.php");
            exit;
        } else {
            $errors[] = "Error creating account: " . $mysqli->error;
        }
    }
}

// Generate next account number suggestion
$next_number_sql = "SELECT MAX(CAST(SUBSTRING(account_number, 3) AS UNSIGNED)) as max_num 
                   FROM accounts 
                   WHERE account_number LIKE '1%' 
                   AND account_number REGEXP '^1[0-9]+$'";
$next_number_result = $mysqli->query($next_number_sql);
$next_number = $next_number_result->fetch_assoc()['max_num'] ?? 999;
$suggested_number = '1' . str_pad($next_number + 1, 4, '0', STR_PAD_LEFT);
?>

<div class="card">
    <div class="card-header bg-success py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-plus-circle mr-2"></i>Create New Account
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
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-primary"><i class="fas fa-wallet"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Assets</span>
                        <span class="info-box-number">
                            <?php 
                            $asset_count = $mysqli->query("SELECT COUNT(*) as count FROM accounts WHERE account_type = 'asset' AND is_active = 1")->fetch_assoc()['count'];
                            echo $asset_count;
                            ?>
                        </span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-warning"><i class="fas fa-hand-holding-usd"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Liabilities</span>
                        <span class="info-box-number">
                            <?php 
                            $liability_count = $mysqli->query("SELECT COUNT(*) as count FROM accounts WHERE account_type = 'liability' AND is_active = 1")->fetch_assoc()['count'];
                            echo $liability_count;
                            ?>
                        </span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-info"><i class="fas fa-chart-line"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Revenue Accounts</span>
                        <span class="info-box-number">
                            <?php 
                            $revenue_count = $mysqli->query("SELECT COUNT(*) as count FROM accounts WHERE account_type = 'revenue' AND is_active = 1")->fetch_assoc()['count'];
                            echo $revenue_count;
                            ?>
                        </span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-danger"><i class="fas fa-money-bill-wave"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Expense Accounts</span>
                        <span class="info-box-number">
                            <?php 
                            $expense_count = $mysqli->query("SELECT COUNT(*) as count FROM accounts WHERE account_type = 'expense' AND is_active = 1")->fetch_assoc()['count'];
                            echo $expense_count;
                            ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card-body">
        <!-- Error Messages -->
        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <h5><i class="fas fa-exclamation-triangle mr-2"></i>Please fix the following errors:</h5>
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- Account Creation Form -->
        <form method="POST" id="accountForm">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <div class="row">
                <!-- Basic Information -->
                <div class="col-md-6">
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-info-circle mr-2"></i>Account Information</h4>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label for="account_number" class="font-weight-bold">Account Number *</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" id="account_number" name="account_number" 
                                           value="<?php echo htmlspecialchars($account_number ?: $suggested_number); ?>" 
                                           required placeholder="e.g., 10001">
                                    <div class="input-group-append">
                                        <button type="button" class="btn btn-outline-secondary" onclick="suggestAccountNumber()">
                                            <i class="fas fa-magic"></i> Suggest
                                        </button>
                                    </div>
                                </div>
                                <small class="form-text text-muted">Unique identifier for the account</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="account_name" class="font-weight-bold">Account Name *</label>
                                <input type="text" class="form-control" id="account_name" name="account_name" 
                                       value="<?php echo htmlspecialchars($account_name); ?>" 
                                       required placeholder="e.g., Cash on Hand">
                                <small class="form-text text-muted">Descriptive name of the account</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="account_type" class="font-weight-bold">Account Type *</label>
                                <select class="form-control select2" id="account_type" name="account_type" required>
                                    <option value="">Select Account Type</option>
                                    <option value="asset" <?php echo $account_type == 'asset' ? 'selected' : ''; ?>>Asset</option>
                                    <option value="liability" <?php echo $account_type == 'liability' ? 'selected' : ''; ?>>Liability</option>
                                    <option value="equity" <?php echo $account_type == 'equity' ? 'selected' : ''; ?>>Equity</option>
                                    <option value="revenue" <?php echo $account_type == 'revenue' ? 'selected' : ''; ?>>Revenue</option>
                                    <option value="expense" <?php echo $account_type == 'expense' ? 'selected' : ''; ?>>Expense</option>
                                </select>
                                <small class="form-text text-muted">Primary classification of the account</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="account_subtype" class="font-weight-bold">Account Subtype</label>
                                <input type="text" class="form-control" id="account_subtype" name="account_subtype" 
                                       value="<?php echo htmlspecialchars($account_subtype); ?>" 
                                       placeholder="e.g., Current Assets, Fixed Assets">
                                <small class="form-text text-muted">Secondary classification (optional)</small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Account Settings -->
                <div class="col-md-6">
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-cog mr-2"></i>Account Settings</h4>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label for="parent_account_id" class="font-weight-bold">Parent Account</label>
                                <select class="form-control select2" id="parent_account_id" name="parent_account_id">
                                    <option value="">No Parent Account</option>
                                    <?php while($parent = $parent_accounts->fetch_assoc()): ?>
                                        <option value="<?php echo $parent['account_id']; ?>" 
                                            <?php echo $parent_account_id == $parent['account_id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($parent['account_number'] . ' - ' . $parent['account_name'] . ' (' . $parent['account_type'] . ')'); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                                <small class="form-text text-muted">Optional parent account for hierarchical structure</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="normal_balance" class="font-weight-bold">Normal Balance *</label>
                                <select class="form-control select2" id="normal_balance" name="normal_balance" required>
                                    <option value="debit" <?php echo $normal_balance == 'debit' ? 'selected' : ''; ?>>Debit</option>
                                    <option value="credit" <?php echo $normal_balance == 'credit' ? 'selected' : ''; ?>>Credit</option>
                                </select>
                                <small class="form-text text-muted">Type of balance that normally increases the account</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="description" class="font-weight-bold">Description</label>
                                <textarea class="form-control" id="description" name="description" 
                                          rows="4" placeholder="Optional description of the account"><?php echo htmlspecialchars($description); ?></textarea>
                                <small class="form-text text-muted">Detailed description of account usage</small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Account Type Guide -->
                    <div class="card">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-question-circle mr-2"></i>Account Type Guide</h4>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <th><span class="badge badge-success">Asset</span></th>
                                        <td>Resources owned (e.g., Cash, Inventory)</td>
                                        <td><span class="badge badge-dark">Debit</span></td>
                                    </tr>
                                    <tr>
                                        <th><span class="badge badge-warning">Liability</span></th>
                                        <td>Obligations owed (e.g., Loans, Payables)</td>
                                        <td><span class="badge badge-dark">Credit</span></td>
                                    </tr>
                                    <tr>
                                        <th><span class="badge badge-primary">Equity</span></th>
                                        <td>Owner's interest (e.g., Capital, Retained Earnings)</td>
                                        <td><span class="badge badge-dark">Credit</span></td>
                                    </tr>
                                    <tr>
                                        <th><span class="badge badge-info">Revenue</span></th>
                                        <td>Income earned (e.g., Sales, Service Income)</td>
                                        <td><span class="badge badge-dark">Credit</span></td>
                                    </tr>
                                    <tr>
                                        <th><span class="badge badge-danger">Expense</span></th>
                                        <td>Costs incurred (e.g., Rent, Salaries)</td>
                                        <td><span class="badge badge-dark">Debit</span></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Form Actions -->
            <div class="row mt-4">
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
                                <i class="fas fa-save mr-2"></i>Create Account
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
    $('.select2').select2();
    
    // Auto-update normal balance based on account type
    $('#account_type').change(function() {
        const type = $(this).val();
        const normalBalance = $('#normal_balance');
        
        switch(type) {
            case 'asset':
            case 'expense':
                normalBalance.val('debit').trigger('change');
                break;
            case 'liability':
            case 'equity':
            case 'revenue':
                normalBalance.val('credit').trigger('change');
                break;
        }
    });
    
    // Form validation
    $('#accountForm').validate({
        rules: {
            account_number: {
                required: true,
                minlength: 2
            },
            account_name: {
                required: true,
                minlength: 3
            },
            account_type: {
                required: true
            },
            normal_balance: {
                required: true
            }
        },
        messages: {
            account_number: {
                required: "Please enter an account number",
                minlength: "Account number must be at least 2 characters"
            },
            account_name: {
                required: "Please enter an account name",
                minlength: "Account name must be at least 3 characters"
            },
            account_type: {
                required: "Please select an account type"
            },
            normal_balance: {
                required: "Please select a normal balance"
            }
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
            if (element.parent('.input-group').length) {
                error.insertAfter(element.parent());
            } else {
                error.insertAfter(element);
            }
        }
    });
});

function suggestAccountNumber() {
    const type = $('#account_type').val();
    let prefix = '';
    
    switch(type) {
        case 'asset':
            prefix = '1';
            break;
        case 'liability':
            prefix = '2';
            break;
        case 'equity':
            prefix = '3';
            break;
        case 'revenue':
            prefix = '4';
            break;
        case 'expense':
            prefix = '5';
            break;
        default:
            prefix = '1';
    }
    
    $.ajax({
        url: 'ajax/get_next_account_number.php',
        method: 'POST',
        data: { prefix: prefix },
        success: function(response) {
            $('#account_number').val(response);
        }
    });
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + S to save
    if (e.ctrlKey && e.keyCode === 83) {
        e.preventDefault();
        $('#accountForm').submit();
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
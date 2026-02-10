<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Check permissions
if (!isset($_SESSION['user_id'])) {
    header("Location: /login.php");
    exit;
}

// Get account ID
$account_id = intval($_GET['id'] ?? 0);
if ($account_id == 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Account ID is required.";
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
$account = $account_stmt->get_result()->fetch_assoc();

if (!$account) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Account not found.";
    header("Location: accounts.php");
    exit;
}

// Get parent accounts for dropdown (excluding current account and its children)
$parent_accounts_sql = "SELECT account_id, account_number, account_name, account_type 
                       FROM accounts 
                       WHERE is_active = 1 
                       AND account_id != ?
                       AND (parent_account_id IS NULL OR parent_account_id != ?)
                       ORDER BY account_type, account_number";
$parent_stmt = $mysqli->prepare($parent_accounts_sql);
$parent_stmt->bind_param("ii", $account_id, $account_id);
$parent_stmt->execute();
$parent_accounts = $parent_stmt->get_result();

// Initialize variables
$account_number = $account['account_number'];
$account_name = $account['account_name'];
$account_type = $account['account_type'];
$account_subtype = $account['account_subtype'];
$parent_account_id = $account['parent_account_id'];
$normal_balance = $account['normal_balance'];
$description = $account['description'];
$is_active = $account['is_active'];
$errors = [];

// Get account statistics
$stats_sql = "SELECT 
    COUNT(DISTINCT jel.journal_entry_id) as total_transactions,
    COALESCE(SUM(jel.debit_amount), 0) as total_debits,
    COALESCE(SUM(jel.credit_amount), 0) as total_credits,
    (SELECT COUNT(*) FROM journal_entry_lines WHERE account_id = ? AND DATE(created_at) = CURDATE()) as today_transactions
FROM journal_entry_lines jel
WHERE jel.account_id = ?";

$stats_stmt = $mysqli->prepare($stats_sql);
$stats_stmt->bind_param("ii", $account_id, $account_id);
$stats_stmt->execute();
$account_stats = $stats_stmt->get_result()->fetch_assoc();

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
    $parent_account_id = intval($_POST['parent_account_id'] ?? 0);
    $normal_balance = sanitizeInput($_POST['normal_balance'] ?? 'debit');
    $description = sanitizeInput($_POST['description'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
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
    
    // Check if account number already exists (excluding current account)
    if ($account_number != $account['account_number']) {
        $check_sql = "SELECT account_id FROM accounts WHERE account_number = ? AND account_id != ?";
        $check_stmt = $mysqli->prepare($check_sql);
        $check_stmt->bind_param("si", $account_number, $account_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $errors[] = "Account number already exists.";
        }
    }
    
    // Check if trying to set itself as parent
    if ($parent_account_id == $account_id) {
        $errors[] = "Account cannot be its own parent.";
    }
    
    // Check if account has transactions (can't change type if it does)
    if ($account_type != $account['account_type'] && $account_stats['total_transactions'] > 0) {
        $errors[] = "Cannot change account type because it has existing transactions.";
    }
    
    // If no errors, update the account
    if (empty($errors)) {
        $update_sql = "UPDATE accounts 
                      SET account_number = ?, 
                          account_name = ?, 
                          account_type = ?, 
                          account_subtype = ?, 
                          parent_account_id = ?, 
                          normal_balance = ?, 
                          description = ?, 
                          is_active = ?, 
                          updated_by = ?, 
                          updated_at = CURRENT_TIMESTAMP
                      WHERE account_id = ?";
        
        $update_stmt = $mysqli->prepare($update_sql);
        $update_stmt->bind_param("ssssissiii", 
            $account_number,
            $account_name,
            $account_type,
            $account_subtype,
            $parent_account_id ?: NULL,
            $normal_balance,
            $description,
            $is_active,
            $_SESSION['user_id'],
            $account_id
        );
        
        if ($update_stmt->execute()) {
            // Log activity
            $activity_sql = "INSERT INTO activities 
                            (activity_type, activity_description, performed_by, related_type, related_id) 
                            VALUES ('account_updated', 'Updated account: $account_number - $account_name', 
                                    ?, 'account', ?)";
            $activity_stmt = $mysqli->prepare($activity_sql);
            $activity_stmt->bind_param("ii", $_SESSION['user_id'], $account_id);
            $activity_stmt->execute();
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Account updated successfully!";
            header("Location: account_ledger.php?id=$account_id");
            exit;
        } else {
            $errors[] = "Error updating account: " . $mysqli->error;
        }
    }
}
?>

<div class="card">
    <div class="card-header bg-warning py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-edit mr-2"></i>
            Edit Account: <?php echo htmlspecialchars($account['account_number'] . ' - ' . $account['account_name']); ?>
        </h3>
        <div class="card-tools">
            <a href="account_ledger.php?id=<?php echo $account_id; ?>" class="btn btn-light">
                <i class="fas fa-arrow-left mr-2"></i>Back to Ledger
            </a>
        </div>
    </div>
    
    <!-- Statistics Row -->
    <div class="card-body border-bottom">
        <div class="row text-center">
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-info"><i class="fas fa-exchange-alt"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Transactions</span>
                        <span class="info-box-number"><?php echo $account_stats['total_transactions']; ?></span>
                        <small class="text-muted">All time</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-success"><i class="fas fa-arrow-up"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Debits</span>
                        <span class="info-box-number"><?php echo numfmt_format_currency($currency_format, $account_stats['total_debits'], $session_company_currency); ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-danger"><i class="fas fa-arrow-down"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Credits</span>
                        <span class="info-box-number"><?php echo numfmt_format_currency($currency_format, $account_stats['total_credits'], $session_company_currency); ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-primary"><i class="fas fa-balance-scale"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Current Balance</span>
                        <span class="info-box-number"><?php echo numfmt_format_currency($currency_format, $account['balance'], $session_company_currency); ?></span>
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
        
        <!-- Account Information Card -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-info-circle mr-2"></i>Current Account Information</h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <th width="40%" class="text-muted">Account Number:</th>
                                        <td class="font-weight-bold text-primary"><?php echo htmlspecialchars($account['account_number']); ?></td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">Account Name:</th>
                                        <td class="font-weight-bold"><?php echo htmlspecialchars($account['account_name']); ?></td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">Account Type:</th>
                                        <td>
                                            <span class="badge badge-<?php 
                                                echo $account['account_type'] == 'asset' ? 'success' : 
                                                     ($account['account_type'] == 'liability' ? 'warning' : 
                                                     ($account['account_type'] == 'equity' ? 'primary' : 
                                                     ($account['account_type'] == 'revenue' ? 'info' : 'danger'))); 
                                            ?>">
                                                <?php echo ucfirst($account['account_type']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">Status:</th>
                                        <td>
                                            <?php if ($account['is_active']): ?>
                                                <span class="badge badge-success">Active</span>
                                            <?php else: ?>
                                                <span class="badge badge-danger">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <th width="40%" class="text-muted">Normal Balance:</th>
                                        <td><span class="badge badge-dark"><?php echo ucfirst($account['normal_balance']); ?></span></td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">Account Subtype:</th>
                                        <td><?php echo htmlspecialchars($account['account_subtype'] ?: 'N/A'); ?></td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">Parent Account:</th>
                                        <td>
                                            <?php if ($account['parent_account_number']): ?>
                                                <?php echo htmlspecialchars($account['parent_account_number'] . ' - ' . $account['parent_account_name']); ?>
                                            <?php else: ?>
                                                <span class="text-muted">None</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">Current Balance:</th>
                                        <td class="font-weight-bold <?php echo $account['balance'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                            <?php echo numfmt_format_currency($currency_format, $account['balance'], $session_company_currency); ?>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Account Edit Form -->
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
                                <input type="text" class="form-control" id="account_number" name="account_number" 
                                       value="<?php echo htmlspecialchars($account_number); ?>" required>
                                <small class="form-text text-muted">Unique identifier for the account</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="account_name" class="font-weight-bold">Account Name *</label>
                                <input type="text" class="form-control" id="account_name" name="account_name" 
                                       value="<?php echo htmlspecialchars($account_name); ?>" required>
                                <small class="form-text text-muted">Descriptive name of the account</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="account_type" class="font-weight-bold">Account Type *</label>
                                <select class="form-control select2" id="account_type" name="account_type" required 
                                        <?php echo $account_stats['total_transactions'] > 0 ? 'disabled' : ''; ?>>
                                    <option value="">Select Account Type</option>
                                    <option value="asset" <?php echo $account_type == 'asset' ? 'selected' : ''; ?>>Asset</option>
                                    <option value="liability" <?php echo $account_type == 'liability' ? 'selected' : ''; ?>>Liability</option>
                                    <option value="equity" <?php echo $account_type == 'equity' ? 'selected' : ''; ?>>Equity</option>
                                    <option value="revenue" <?php echo $account_type == 'revenue' ? 'selected' : ''; ?>>Revenue</option>
                                    <option value="expense" <?php echo $account_type == 'expense' ? 'selected' : ''; ?>>Expense</option>
                                </select>
                                <?php if ($account_stats['total_transactions'] > 0): ?>
                                    <input type="hidden" name="account_type" value="<?php echo htmlspecialchars($account_type); ?>">
                                    <small class="form-text text-warning">
                                        <i class="fas fa-exclamation-triangle"></i> Cannot change account type because it has existing transactions.
                                    </small>
                                <?php else: ?>
                                    <small class="form-text text-muted">Primary classification of the account</small>
                                <?php endif; ?>
                            </div>
                            
                            <div class="form-group">
                                <label for="account_subtype" class="font-weight-bold">Account Subtype</label>
                                <input type="text" class="form-control" id="account_subtype" name="account_subtype" 
                                       value="<?php echo htmlspecialchars($account_subtype); ?>">
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
                                <textarea class="form-control" id="description" name="description" rows="4"><?php echo htmlspecialchars($description); ?></textarea>
                                <small class="form-text text-muted">Detailed description of account usage</small>
                            </div>
                            
                            <div class="form-group">
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input" id="is_active" name="is_active" value="1" <?php echo $is_active ? 'checked' : ''; ?>>
                                    <label class="custom-control-label font-weight-bold" for="is_active">Account is Active</label>
                                    <small class="form-text text-muted d-block">Inactive accounts cannot be used in new transactions</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Warnings & Restrictions -->
            <?php if ($account_stats['total_transactions'] > 0): ?>
            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="card border-warning">
                        <div class="card-header bg-warning py-2">
                            <h4 class="card-title mb-0">
                                <i class="fas fa-exclamation-triangle mr-2"></i>Editing Restrictions
                            </h4>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-warning">
                                <h5><i class="fas fa-info-circle mr-2"></i>Important Notes</h5>
                                <ul class="mb-0">
                                    <li>This account has <?php echo $account_stats['total_transactions']; ?> transaction(s) recorded</li>
                                    <li>Some fields cannot be changed to maintain data integrity</li>
                                    <li>Account type cannot be changed after transactions are recorded</li>
                                    <li>Consider creating a new account instead of modifying this one if major changes are needed</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Form Actions -->
            <div class="row">
                <div class="col-md-12">
                    <div class="btn-toolbar justify-content-between">
                        <div class="btn-group">
                            <a href="account_ledger.php?id=<?php echo $account_id; ?>" class="btn btn-secondary">
                                <i class="fas fa-times mr-2"></i>Cancel
                            </a>
                        </div>
                        <div class="btn-group">
                            <button type="reset" class="btn btn-outline-secondary">
                                <i class="fas fa-redo mr-2"></i>Reset Form
                            </button>
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-save mr-2"></i>Save Changes
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
            error.insertAfter(element);
        }
    });
    
    // Confirm before deactivating account with balance
    $('#is_active').change(function() {
        if (!$(this).is(':checked') && <?php echo $account['balance'] != 0 ? 'true' : 'false'; ?>) {
            const balance = '<?php echo numfmt_format_currency($currency_format, $account['balance'], $session_company_currency); ?>';
            Swal.fire({
                title: 'Deactivate Account?',
                html: `This account has a balance of <strong>${balance}</strong>.<br><br>
                       Are you sure you want to deactivate it?<br>
                       Deactivated accounts cannot be used in new transactions.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, Deactivate',
                cancelButtonText: 'Keep Active'
            }).then((result) => {
                if (!result.isConfirmed) {
                    $(this).prop('checked', true);
                }
            });
        }
    });
});

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + S to save
    if (e.ctrlKey && e.keyCode === 83) {
        e.preventDefault();
        $('#accountForm').submit();
    }
    // Escape to cancel
    if (e.keyCode === 27) {
        window.location.href = 'account_ledger.php?id=<?php echo $account_id; ?>';
    }
});
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
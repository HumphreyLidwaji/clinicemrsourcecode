<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Check permissions
if (!isset($_SESSION['user_id'])) {
    header("Location: /login.php");
    exit;
}

// Check if journal system is set up
$table_check = $mysqli->query("SHOW TABLES LIKE 'journal_entries'")->num_rows;
if ($table_check == 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Journal entry system is not set up. Please run accounting setup first.";
    header("Location: accounts.php");
    exit;
}

// Initialize variables
$transaction_date = date('Y-m-d');
$posting_date = date('Y-m-d');
$description = $transaction_type = $reference_number = $notes = '';
$lines = [['account_id' => '', 'debit_amount' => '', 'credit_amount' => '', 'description' => '']];
$errors = [];

// Get accounts for dropdown
$accounts_sql = "SELECT account_id, account_number, account_name, account_type, normal_balance, balance 
                FROM accounts 
                WHERE is_active = 1 
                ORDER BY account_type, account_number";
$accounts_result = $mysqli->query($accounts_sql);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errors[] = "Invalid CSRF token.";
    }
    
    // Get and sanitize inputs
    $transaction_date = sanitizeInput($_POST['transaction_date'] ?? '');
    $posting_date = sanitizeInput($_POST['posting_date'] ?? '');
    $description = sanitizeInput($_POST['description'] ?? '');
    $transaction_type = sanitizeInput($_POST['transaction_type'] ?? 'other');
    $reference_number = sanitizeInput($_POST['reference_number'] ?? '');
    $notes = sanitizeInput($_POST['notes'] ?? '');
    
    // Validate dates
    if (empty($transaction_date)) {
        $errors[] = "Transaction date is required.";
    }
    
    if (empty($posting_date)) {
        $errors[] = "Posting date is required.";
    }
    
    if (empty($description)) {
        $errors[] = "Description is required.";
    }
    
    // Get and validate journal lines
    $lines = [];
    $total_debit = 0;
    $total_credit = 0;
    $line_index = 0;
    
    if (isset($_POST['account_id']) && is_array($_POST['account_id'])) {
        foreach ($_POST['account_id'] as $index => $account_id) {
            $account_id = intval($account_id);
            $debit_amount = floatval($_POST['debit_amount'][$index] ?? 0);
            $credit_amount = floatval($_POST['credit_amount'][$index] ?? 0);
            $line_description = sanitizeInput($_POST['line_description'][$index] ?? '');
            
            // Skip empty lines
            if ($account_id == 0 && $debit_amount == 0 && $credit_amount == 0) {
                continue;
            }
            
            // Validate line
            if ($account_id == 0) {
                $errors[] = "Please select an account for line " . ($line_index + 1);
            }
            
            if ($debit_amount < 0 || $credit_amount < 0) {
                $errors[] = "Amounts cannot be negative on line " . ($line_index + 1);
            }
            
            if ($debit_amount > 0 && $credit_amount > 0) {
                $errors[] = "Cannot have both debit and credit on line " . ($line_index + 1);
            }
            
            if ($debit_amount == 0 && $credit_amount == 0) {
                $errors[] = "Amount required on line " . ($line_index + 1);
            }
            
            $lines[] = [
                'account_id' => $account_id,
                'debit_amount' => $debit_amount,
                'credit_amount' => $credit_amount,
                'description' => $line_description,
                'line_number' => $line_index + 1
            ];
            
            $total_debit += $debit_amount;
            $total_credit += $credit_amount;
            $line_index++;
        }
    }
    
    // Validate at least 2 lines (double-entry)
    if (count($lines) < 2) {
        $errors[] = "At least two journal lines are required (double-entry accounting).";
    }
    
    // Validate debits equal credits
    if (abs($total_debit - $total_credit) > 0.01) {
        $errors[] = "Debits ($" . number_format($total_debit, 2) . ") must equal credits ($" . number_format($total_credit, 2) . "). Difference: $" . number_format(abs($total_debit - $total_credit), 2);
    }
    
    // If no errors, create journal entry
    if (empty($errors)) {
        $mysqli->begin_transaction();
        
        try {
            // Generate journal entry number
            $year = date('Y');
            $month = date('m');
            $count_sql = "SELECT COUNT(*) as count FROM journal_entries 
                         WHERE YEAR(created_at) = ? AND MONTH(created_at) = ?";
            $count_stmt = $mysqli->prepare($count_sql);
            $count_stmt->bind_param("ii", $year, $month);
            $count_stmt->execute();
            $count_result = $count_stmt->get_result()->fetch_assoc();
            $entry_number = 'JE' . $year . $month . str_pad($count_result['count'] + 1, 4, '0', STR_PAD_LEFT);
            
            // Insert journal entry
            $entry_sql = "INSERT INTO journal_entries 
                         (journal_entry_number, transaction_date, posting_date, description, 
                          transaction_type, reference_number, total_debit, total_credit, 
                          status, notes, created_by) 
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?)";
            
            $entry_stmt = $mysqli->prepare($entry_sql);
            $entry_stmt->bind_param("ssssssddsi",
                $entry_number,
                $transaction_date,
                $posting_date,
                $description,
                $transaction_type,
                $reference_number,
                $total_debit,
                $total_credit,
                $notes,
                $_SESSION['user_id']
            );
            
            if (!$entry_stmt->execute()) {
                throw new Exception("Error creating journal entry: " . $mysqli->error);
            }
            
            $journal_entry_id = $mysqli->insert_id;
            
            // Insert journal lines
            $line_sql = "INSERT INTO journal_entry_lines 
                        (journal_entry_id, line_number, account_id, 
                         debit_amount, credit_amount, description, created_by) 
                        VALUES (?, ?, ?, ?, ?, ?, ?)";
            
            $line_stmt = $mysqli->prepare($line_sql);
            
            foreach ($lines as $line) {
                $line_stmt->bind_param("iiiddsi",
                    $journal_entry_id,
                    $line['line_number'],
                    $line['account_id'],
                    $line['debit_amount'],
                    $line['credit_amount'],
                    $line['description'],
                    $_SESSION['user_id']
                );
                
                if (!$line_stmt->execute()) {
                    throw new Exception("Error creating journal line: " . $mysqli->error);
                }
            }
            
            // Log activity
            $activity_sql = "INSERT INTO activities 
                            (activity_type, activity_description, performed_by, 
                             related_type, related_id, amount) 
                            VALUES ('journal_entry_created', 
                                    'Created journal entry: $entry_number', 
                                    ?, 'journal_entry', ?, ?)";
            $activity_stmt = $mysqli->prepare($activity_sql);
            $activity_stmt->bind_param("iid", $_SESSION['user_id'], $journal_entry_id, $total_debit);
            $activity_stmt->execute();
            
            $mysqli->commit();
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Journal entry created successfully! Entry #: $entry_number";
            
            // Ask if user wants to post immediately
            $_SESSION['pending_journal_entry_id'] = $journal_entry_id;
            
            header("Location: journal_entry_view.php?id=$journal_entry_id&action=created");
            exit;
            
        } catch (Exception $e) {
            $mysqli->rollback();
            $errors[] = $e->getMessage();
        }
    }
}
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-book mr-2"></i>Create New Journal Entry
        </h3>
        <div class="card-tools">
            <a href="journal_entries.php" class="btn btn-light">
                <i class="fas fa-arrow-left mr-2"></i>Back to Journal
            </a>
        </div>
    </div>
    
    <!-- Statistics Row -->
    <div class="card-body border-bottom">
        <div class="row text-center">
            <div class="col-md-4">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-info"><i class="fas fa-exchange-alt"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Today's Entries</span>
                        <span class="info-box-number">
                            <?php 
                            $today_count = $mysqli->query("SELECT COUNT(*) as count FROM journal_entries WHERE DATE(created_at) = CURDATE()")->fetch_assoc()['count'];
                            echo $today_count;
                            ?>
                        </span>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-success"><i class="fas fa-check-circle"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Posted This Month</span>
                        <span class="info-box-number">
                            <?php 
                            $month_count = $mysqli->query("SELECT COUNT(*) as count FROM journal_entries WHERE status = 'posted' AND MONTH(created_at) = MONTH(CURDATE())")->fetch_assoc()['count'];
                            echo $month_count;
                            ?>
                        </span>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-warning"><i class="fas fa-clock"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Draft Entries</span>
                        <span class="info-box-number">
                            <?php 
                            $draft_count = $mysqli->query("SELECT COUNT(*) as count FROM journal_entries WHERE status = 'draft'")->fetch_assoc()['count'];
                            echo $draft_count;
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

        <!-- Journal Entry Form -->
        <form method="POST" id="journalForm">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <div class="row">
                <!-- Entry Information -->
                <div class="col-md-6">
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-info-circle mr-2"></i>Entry Information</h4>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="transaction_date" class="font-weight-bold">Transaction Date *</label>
                                        <input type="date" class="form-control" id="transaction_date" name="transaction_date" 
                                               value="<?php echo htmlspecialchars($transaction_date); ?>" required>
                                        <small class="form-text text-muted">Date when transaction occurred</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="posting_date" class="font-weight-bold">Posting Date *</label>
                                        <input type="date" class="form-control" id="posting_date" name="posting_date" 
                                               value="<?php echo htmlspecialchars($posting_date); ?>" required>
                                        <small class="form-text text-muted">Date to record in ledger</small>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="description" class="font-weight-bold">Description *</label>
                                <input type="text" class="form-control" id="description" name="description" 
                                       value="<?php echo htmlspecialchars($description); ?>" required 
                                       placeholder="e.g., Monthly rent payment">
                                <small class="form-text text-muted">Brief description of the transaction</small>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="transaction_type" class="font-weight-bold">Transaction Type</label>
                                        <select class="form-control select2" id="transaction_type" name="transaction_type">
                                            <option value="other" <?php echo $transaction_type == 'other' ? 'selected' : ''; ?>>Other</option>
                                            <option value="invoice" <?php echo $transaction_type == 'invoice' ? 'selected' : ''; ?>>Invoice</option>
                                            <option value="payment" <?php echo $transaction_type == 'payment' ? 'selected' : ''; ?>>Payment</option>
                                            <option value="adjustment" <?php echo $transaction_type == 'adjustment' ? 'selected' : ''; ?>>Adjustment</option>
                                            <option value="refund" <?php echo $transaction_type == 'refund' ? 'selected' : ''; ?>>Refund</option>
                                            <option value="inventory_issue" <?php echo $transaction_type == 'inventory_issue' ? 'selected' : ''; ?>>Inventory Issue</option>
                                            <option value="inventory_receipt" <?php echo $transaction_type == 'inventory_receipt' ? 'selected' : ''; ?>>Inventory Receipt</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="reference_number" class="font-weight-bold">Reference Number</label>
                                        <input type="text" class="form-control" id="reference_number" name="reference_number" 
                                               value="<?php echo htmlspecialchars($reference_number); ?>" 
                                               placeholder="e.g., INV-001, PO-123">
                                        <small class="form-text text-muted">Optional reference to source document</small>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="notes" class="font-weight-bold">Notes</label>
                                <textarea class="form-control" id="notes" name="notes" rows="3"><?php echo htmlspecialchars($notes); ?></textarea>
                                <small class="form-text text-muted">Additional notes or memos</small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Balance Check -->
                <div class="col-md-6">
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-balance-scale mr-2"></i>Balance Check</h4>
                        </div>
                        <div class="card-body text-center">
                            <div class="mb-4">
                                <div class="h2" id="totalDebit">$0.00</div>
                                <small class="text-muted">Total Debits</small>
                            </div>
                            <div class="mb-4">
                                <div class="h2" id="totalCredit">$0.00</div>
                                <small class="text-muted">Total Credits</small>
                            </div>
                            <div class="alert" id="balanceAlert" style="display: none;">
                                <i class="fas fa-info-circle mr-2"></i>
                                <span id="balanceMessage"></span>
                            </div>
                            <div class="progress mt-3" style="height: 20px;">
                                <div class="progress-bar bg-success" id="balanceProgress" style="width: 0%"></div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Quick Actions -->
                    <div class="card">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-bolt mr-2"></i>Quick Actions</h4>
                        </div>
                        <div class="card-body">
                            <div class="btn-group-vertical w-100">
                                <button type="button" class="btn btn-outline-success mb-2" onclick="addJournalLine()">
                                    <i class="fas fa-plus mr-2"></i>Add Journal Line
                                </button>
                                <button type="button" class="btn btn-outline-info mb-2" onclick="validateJournal()">
                                    <i class="fas fa-check mr-2"></i>Validate Entry
                                </button>
                                <button type="button" class="btn btn-outline-warning mb-2" onclick="resetForm()">
                                    <i class="fas fa-redo mr-2"></i>Reset Form
                                </button>
                                <a href="journal_entries.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times mr-2"></i>Cancel
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Journal Lines -->
            <div class="card mb-4">
                <div class="card-header bg-light py-2">
                    <h4 class="card-title mb-0"><i class="fas fa-list-alt mr-2"></i>Journal Lines</h4>
                </div>
                <div class="card-body">
                    <div id="journalLines">
                        <?php foreach ($lines as $index => $line): ?>
                        <div class="journal-line row mb-3" data-index="<?php echo $index; ?>">
                            <div class="col-md-5">
                                <div class="form-group">
                                    <label>Account *</label>
                                    <select class="form-control select2 account-select" name="account_id[]" required>
                                        <option value="">Select Account</option>
                                        <?php 
                                        $accounts_result->data_seek(0);
                                        while($account = $accounts_result->fetch_assoc()): 
                                        ?>
                                            <option value="<?php echo $account['account_id']; ?>" 
                                                <?php echo $line['account_id'] == $account['account_id'] ? 'selected' : ''; ?>
                                                data-type="<?php echo $account['account_type']; ?>"
                                                data-normal-balance="<?php echo $account['normal_balance']; ?>">
                                                <?php echo htmlspecialchars($account['account_number'] . ' - ' . $account['account_name'] . ' (' . $account['account_type'] . ')'); ?>
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Debit Amount</label>
                                    <div class="input-group">
                                        <div class="input-group-prepend">
                                            <span class="input-group-text">$</span>
                                        </div>
                                        <input type="number" class="form-control debit-amount" name="debit_amount[]" 
                                               step="0.01" min="0" value="<?php echo htmlspecialchars($line['debit_amount']); ?>">
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Credit Amount</label>
                                    <div class="input-group">
                                        <div class="input-group-prepend">
                                            <span class="input-group-text">$</span>
                                        </div>
                                        <input type="number" class="form-control credit-amount" name="credit_amount[]" 
                                               step="0.01" min="0" value="<?php echo htmlspecialchars($line['credit_amount']); ?>">
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-1">
                                <div class="form-group">
                                    <label>&nbsp;</label>
                                    <button type="button" class="btn btn-danger btn-block" onclick="removeJournalLine(this)" <?php echo $index == 0 ? 'disabled' : ''; ?>>
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="col-md-12">
                                <div class="form-group">
                                    <label>Line Description (Optional)</label>
                                    <input type="text" class="form-control" name="line_description[]" 
                                           value="<?php echo htmlspecialchars($line['description']); ?>" 
                                           placeholder="e.g., Rent for January 2024">
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="text-center">
                        <button type="button" class="btn btn-success" onclick="addJournalLine()">
                            <i class="fas fa-plus mr-2"></i>Add Another Line
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Form Actions -->
            <div class="row">
                <div class="col-md-12">
                    <div class="btn-toolbar justify-content-between">
                        <div class="btn-group">
                            <a href="journal_entries.php" class="btn btn-secondary">
                                <i class="fas fa-times mr-2"></i>Cancel
                            </a>
                        </div>
                        <div class="btn-group">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save mr-2"></i>Save as Draft
                            </button>
                            <button type="submit" name="post_immediately" value="1" class="btn btn-success">
                                <i class="fas fa-check-circle mr-2"></i>Save & Post
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
    updateBalanceCheck();
    
    // Initialize Select2 for new lines
    $(document).on('select2:open', () => {
        document.querySelector('.select2-container--open .select2-search__field').focus();
    });
    
    // Auto-focus amount fields when account is selected
    $(document).on('change', '.account-select', function() {
        const line = $(this).closest('.journal-line');
        const accountType = $(this).find(':selected').data('type');
        const normalBalance = $(this).find(':selected').data('normal-balance');
        
        if (accountType) {
            // Highlight appropriate amount field based on normal balance
            if (normalBalance === 'debit') {
                line.find('.debit-amount').addClass('border-success').removeClass('border-danger');
                line.find('.credit-amount').removeClass('border-success border-danger');
            } else {
                line.find('.credit-amount').addClass('border-success').removeClass('border-danger');
                line.find('.debit-amount').removeClass('border-success border-danger');
            }
        }
    });
    
    // Update balance check on amount changes
    $(document).on('input', '.debit-amount, .credit-amount', function() {
        updateBalanceCheck();
    });
    
    // Auto-calculate opposite amount
    $(document).on('blur', '.debit-amount', function() {
        const line = $(this).closest('.journal-line');
        const debit = parseFloat($(this).val()) || 0;
        if (debit > 0) {
            line.find('.credit-amount').val('').trigger('input');
        }
    });
    
    $(document).on('blur', '.credit-amount', function() {
        const line = $(this).closest('.journal-line');
        const credit = parseFloat($(this).val()) || 0;
        if (credit > 0) {
            line.find('.debit-amount').val('').trigger('input');
        }
    });
});

let lineCounter = <?php echo count($lines); ?>;

function addJournalLine() {
    lineCounter++;
    const newLine = `
        <div class="journal-line row mb-3" data-index="${lineCounter}">
            <div class="col-md-5">
                <div class="form-group">
                    <label>Account *</label>
                    <select class="form-control select2 account-select" name="account_id[]" required>
                        <option value="">Select Account</option>
                        <?php 
                        $accounts_result->data_seek(0);
                        while($account = $accounts_result->fetch_assoc()): 
                        ?>
                            <option value="<?php echo $account['account_id']; ?>" 
                                data-type="<?php echo $account['account_type']; ?>"
                                data-normal-balance="<?php echo $account['normal_balance']; ?>">
                                <?php echo htmlspecialchars($account['account_number'] . ' - ' . $account['account_name'] . ' (' . $account['account_type'] . ')'); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    <label>Debit Amount</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text">$</span>
                        </div>
                        <input type="number" class="form-control debit-amount" name="debit_amount[]" step="0.01" min="0" value="">
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    <label>Credit Amount</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text">$</span>
                        </div>
                        <input type="number" class="form-control credit-amount" name="credit_amount[]" step="0.01" min="0" value="">
                    </div>
                </div>
            </div>
            <div class="col-md-1">
                <div class="form-group">
                    <label>&nbsp;</label>
                    <button type="button" class="btn btn-danger btn-block" onclick="removeJournalLine(this)">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>
            <div class="col-md-12">
                <div class="form-group">
                    <label>Line Description (Optional)</label>
                    <input type="text" class="form-control" name="line_description[]" placeholder="e.g., Rent for January 2024">
                </div>
            </div>
        </div>
    `;
    
    $('#journalLines').append(newLine);
    $('.select2').select2();
    updateRemoveButtons();
}

function removeJournalLine(button) {
    $(button).closest('.journal-line').remove();
    updateBalanceCheck();
    updateRemoveButtons();
}

function updateRemoveButtons() {
    $('.journal-line').each(function(index) {
        const removeButton = $(this).find('.btn-danger');
        if (index === 0) {
            removeButton.prop('disabled', true).addClass('disabled');
        } else {
            removeButton.prop('disabled', false).removeClass('disabled');
        }
    });
}

function updateBalanceCheck() {
    let totalDebit = 0;
    let totalCredit = 0;
    
    $('.journal-line').each(function() {
        const debit = parseFloat($(this).find('.debit-amount').val()) || 0;
        const credit = parseFloat($(this).find('.credit-amount').val()) || 0;
        totalDebit += debit;
        totalCredit += credit;
    });
    
    // Update display
    $('#totalDebit').text('$' + totalDebit.toFixed(2));
    $('#totalCredit').text('$' + totalCredit.toFixed(2));
    
    // Update progress bar
    const maxAmount = Math.max(totalDebit, totalCredit);
    const progress = maxAmount > 0 ? Math.min(totalDebit, totalCredit) / maxAmount * 100 : 0;
    $('#balanceProgress').css('width', progress + '%');
    
    // Update balance message
    const balanceAlert = $('#balanceAlert');
    const balanceMessage = $('#balanceMessage');
    
    if (Math.abs(totalDebit - totalCredit) < 0.01) {
        balanceAlert.removeClass('alert-danger').addClass('alert-success').show();
        balanceMessage.html('<strong>Balanced!</strong> Debits equal credits.');
    } else {
        balanceAlert.removeClass('alert-success').addClass('alert-danger').show();
        const difference = Math.abs(totalDebit - totalCredit).toFixed(2);
        balanceMessage.html(`<strong>Out of Balance!</strong> Difference: $${difference}`);
    }
}

function validateJournal() {
    const errors = [];
    let hasDebit = false;
    let hasCredit = false;
    
    $('.journal-line').each(function(index) {
        const accountId = $(this).find('.account-select').val();
        const debit = parseFloat($(this).find('.debit-amount').val()) || 0;
        const credit = parseFloat($(this).find('.credit-amount').val()) || 0;
        const lineNumber = index + 1;
        
        if (!accountId) {
            errors.push(`Line ${lineNumber}: Please select an account`);
        }
        
        if (debit < 0 || credit < 0) {
            errors.push(`Line ${lineNumber}: Amounts cannot be negative`);
        }
        
        if (debit > 0 && credit > 0) {
            errors.push(`Line ${lineNumber}: Cannot have both debit and credit`);
        }
        
        if (debit === 0 && credit === 0) {
            errors.push(`Line ${lineNumber}: Amount required`);
        }
        
        if (debit > 0) hasDebit = true;
        if (credit > 0) hasCredit = true;
    });
    
    const totalDebit = parseFloat($('#totalDebit').text().replace('$', '')) || 0;
    const totalCredit = parseFloat($('#totalCredit').text().replace('$', '')) || 0;
    
    if (Math.abs(totalDebit - totalCredit) > 0.01) {
        errors.push(`Debits ($${totalDebit.toFixed(2)}) do not equal credits ($${totalCredit.toFixed(2)})`);
    }
    
    if ($('.journal-line').length < 2) {
        errors.push('At least two journal lines are required');
    }
    
    if (!hasDebit || !hasCredit) {
        errors.push('Entry must have at least one debit and one credit');
    }
    
    if (errors.length > 0) {
        Swal.fire({
            icon: 'error',
            title: 'Validation Errors',
            html: '<ul class="text-left"><li>' + errors.join('</li><li>') + '</li></ul>',
            confirmButtonText: 'Fix Errors'
        });
        return false;
    } else {
        Swal.fire({
            icon: 'success',
            title: 'Validation Successful',
            text: 'Journal entry is properly balanced and ready to save.',
            confirmButtonText: 'Great!'
        });
        return true;
    }
}

function resetForm() {
    Swal.fire({
        title: 'Reset Form?',
        text: 'This will clear all entered data. Are you sure?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, Reset',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            document.getElementById('journalForm').reset();
            $('.select2').val(null).trigger('change');
            
            // Remove all but first line
            $('.journal-line:not(:first)').remove();
            
            // Clear first line
            $('.journal-line:first').find('select').val('').trigger('change');
            $('.journal-line:first').find('input[type="number"]').val('');
            $('.journal-line:first').find('input[type="text"]').val('');
            
            updateBalanceCheck();
            updateRemoveButtons();
        }
    });
}

// Form validation
$('#journalForm').submit(function(e) {
    if (!validateJournal()) {
        e.preventDefault();
        return false;
    }
    
    const postingImmediately = $('button[name="post_immediately"]').is(':focus');
    if (postingImmediately) {
        $(this).append('<input type="hidden" name="post_immediately" value="1">');
    }
    
    return true;
});

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + S to save
    if (e.ctrlKey && e.keyCode === 83) {
        e.preventDefault();
        $('#journalForm').submit();
    }
    // Ctrl + D to add line
    if (e.ctrlKey && e.keyCode === 68) {
        e.preventDefault();
        addJournalLine();
    }
    // Ctrl + V to validate
    if (e.ctrlKey && e.keyCode === 86) {
        e.preventDefault();
        validateJournal();
    }
    // Escape to cancel
    if (e.keyCode === 27) {
        window.location.href = 'journal_entries.php';
    }
});
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
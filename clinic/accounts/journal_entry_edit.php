<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

$journal_header_id = intval($_GET['id'] ?? 0);

if (!$journal_header_id) {
    $_SESSION['alert_type'] = "danger";
    $_SESSION['alert_message'] = "Journal entry ID is required";
    header("Location: journal_entries.php");
    exit;
}

// Get journal header details
$header_sql = "SELECT jh.*, u.user_name as created_by 
               FROM journal_headers jh
               LEFT JOIN users u ON jh.created_by = u.user_id
               WHERE jh.journal_header_id = ?";
$header_stmt = $mysqli->prepare($header_sql);
$header_stmt->bind_param("i", $journal_header_id);
$header_stmt->execute();
$header_result = $header_stmt->get_result();
$journal_header = $header_result->fetch_assoc();

if (!$journal_header) {
    $_SESSION['alert_type'] = "danger";
    $_SESSION['alert_message'] = "Journal entry not found";
    header("Location: journal_entries.php");
    exit;
}

// Check if journal can be edited (only draft status and today's entries)
$entry_date = $journal_header['entry_date'];
$today = date('Y-m-d');
$can_edit = ($journal_header['status'] == 'draft' && $entry_date == $today);

if (!$can_edit) {
    $_SESSION['alert_type'] = "warning";
    $_SESSION['alert_message'] = "Only today's draft journal entries can be edited";
    header("Location: journal_entry_view.php?id=$journal_header_id");
    exit;
}

// Get journal entry and lines
$entry_sql = "SELECT 
    je.entry_id, je.entry_number, je.entry_type, je.currency_code,
    u.user_name as created_by, je.created_at
FROM journal_entries je
LEFT JOIN users u ON je.created_by = u.user_id
WHERE je.journal_header_id = ?";
$entry_stmt = $mysqli->prepare($entry_sql);
$entry_stmt->bind_param("i", $journal_header_id);
$entry_stmt->execute();
$entry_result = $entry_stmt->get_result();
$journal_entry = $entry_result->fetch_assoc();

// Get journal entry lines
$lines_sql = "SELECT 
    jel.line_id, jel.account_id, jel.entry_type, jel.amount, jel.description,
    a.account_number, a.account_name, a.account_type,
    at.type_name, at.type_class
FROM journal_entry_lines jel
JOIN accounts a ON jel.account_id = a.account_id
LEFT JOIN account_types at ON a.account_type = at.type_id
WHERE jel.entry_id = ?
ORDER BY jel.entry_type DESC, jel.line_id";
$lines_stmt = $mysqli->prepare($lines_sql);
$lines_stmt->bind_param("i", $journal_entry['entry_id']);
$lines_stmt->execute();
$lines_result = $lines_stmt->get_result();

$lines = [];
$total_debits = 0;
$total_credits = 0;

while ($line = $lines_result->fetch_assoc()) {
    $lines[] = $line;
    if ($line['entry_type'] == 'debit') {
        $total_debits += floatval($line['amount']);
    } else {
        $total_credits += floatval($line['amount']);
    }
}

// Get all active accounts for dropdown
$accounts_sql = "SELECT account_id, account_number, account_name, account_type 
                 FROM accounts 
                 WHERE is_active = 1
                 ORDER BY account_number";
$accounts_result = $mysqli->query($accounts_sql);

// Get entry types
$entry_types = [
    'payment' => 'Payment',
    'receipt' => 'Receipt',
    'adjustment' => 'Adjustment',
    'opening' => 'Opening Balance',
    'closing' => 'Closing Entry'
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action == 'update_journal') {
        mysqli_begin_transaction($mysqli);
        
        try {
            $header_name = sanitizeInput($_POST['header_name']);
            $reference_number = sanitizeInput($_POST['reference_number']);
            $description = sanitizeInput($_POST['description']);
            $entry_date = sanitizeInput($_POST['entry_date']);
            $entry_type = sanitizeInput($_POST['entry_type']);
            $currency_code = sanitizeInput($_POST['currency_code']);
            
            // Validate new line entries
            $total_debits_new = 0;
            $total_credits_new = 0;
            $valid_lines = [];
            
            if (isset($_POST['lines'])) {
                foreach ($_POST['lines'] as $line_id => $line_data) {
                    $account_id = intval($line_data['account_id']);
                    $amount = floatval($line_data['amount']);
                    $line_entry_type = sanitizeInput($line_data['entry_type']);
                    $line_description = sanitizeInput($line_data['description']);
                    
                    if ($account_id > 0 && $amount > 0 && in_array($line_entry_type, ['debit', 'credit'])) {
                        if ($line_entry_type === 'debit') {
                            $total_debits_new += $amount;
                        } else {
                            $total_credits_new += $amount;
                        }
                        
                        $valid_lines[] = [
                            'line_id' => $line_id,
                            'account_id' => $account_id,
                            'entry_type' => $line_entry_type,
                            'amount' => $amount,
                            'description' => $line_description
                        ];
                    }
                }
            }
            
            // Check balance
            if (abs($total_debits_new - $total_credits_new) > 0.01) {
                throw new Exception("Debits and credits must balance. Debits: " . $total_debits_new . ", Credits: " . $total_credits_new);
            }
            
            // Check minimum lines
            if (count($valid_lines) < 2) {
                throw new Exception("Please add at least two valid journal entry lines");
            }
            
            // Update journal header
            $update_header_sql = "UPDATE journal_headers SET 
                                 header_name = ?, 
                                 reference_number = ?, 
                                 description = ?, 
                                 entry_date = ?,
                                 updated_at = NOW()
                                 WHERE journal_header_id = ?";
            $update_header_stmt = $mysqli->prepare($update_header_sql);
            $update_header_stmt->bind_param("ssssi", $header_name, $reference_number, $description, $entry_date, $journal_header_id);
            $update_header_stmt->execute();
            
            // Update journal entry
            $update_entry_sql = "UPDATE journal_entries SET 
                                entry_type = ?,
                                currency_code = ?,
                                updated_at = NOW()
                                WHERE journal_header_id = ?";
            $update_entry_stmt = $mysqli->prepare($update_entry_sql);
            $update_entry_stmt->bind_param("ssi", $entry_type, $currency_code, $journal_header_id);
            $update_entry_stmt->execute();
            
            // Update journal entry lines
            foreach ($valid_lines as $line) {
                // First, get old line data to reverse account balance
                $old_line_sql = "SELECT account_id, entry_type, amount FROM journal_entry_lines WHERE line_id = ?";
                $old_line_stmt = $mysqli->prepare($old_line_sql);
                $old_line_stmt->bind_param("i", $line['line_id']);
                $old_line_stmt->execute();
                $old_line_result = $old_line_stmt->get_result();
                $old_line = $old_line_result->fetch_assoc();
                
                // Reverse old account balance
                if ($old_line) {
                    $old_balance_operator = $old_line['entry_type'] === 'debit' ? '-' : '+';
                    $reverse_sql = "UPDATE accounts SET 
                                   current_balance = current_balance $old_balance_operator ?,
                                   updated_at = NOW()
                                   WHERE account_id = ?";
                    $reverse_stmt = $mysqli->prepare($reverse_sql);
                    $reverse_stmt->bind_param("di", $old_line['amount'], $old_line['account_id']);
                    $reverse_stmt->execute();
                }
                
                // Update line
                $update_line_sql = "UPDATE journal_entry_lines SET 
                                   account_id = ?, 
                                   entry_type = ?, 
                                   amount = ?, 
                                   description = ?,
                                   updated_at = NOW()
                                   WHERE line_id = ?";
                $update_line_stmt = $mysqli->prepare($update_line_sql);
                $update_line_stmt->bind_param("isdsi", $line['account_id'], $line['entry_type'], 
                                             $line['amount'], $line['description'], $line['line_id']);
                $update_line_stmt->execute();
                
                // Apply new account balance
                $new_balance_operator = $line['entry_type'] === 'debit' ? '+' : '-';
                $apply_sql = "UPDATE accounts SET 
                             current_balance = current_balance $new_balance_operator ?,
                             updated_at = NOW()
                             WHERE account_id = ?";
                $apply_stmt = $mysqli->prepare($apply_sql);
                $apply_stmt->bind_param("di", $line['amount'], $line['account_id']);
                $apply_stmt->execute();
            }
            
            mysqli_commit($mysqli);
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Journal entry updated successfully";
            header("Location: journal_entry_view.php?id=$journal_header_id");
            exit;
            
        } catch (Exception $e) {
            mysqli_rollback($mysqli);
            $_SESSION['alert_type'] = "danger";
            $_SESSION['alert_message'] = "Error updating journal entry: " . $e->getMessage();
        }
    }
}

?>

<div class="card">
    <div class="card-header bg-warning py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-edit mr-2"></i>Edit Journal Entry
            </h3>
            <div class="card-tools">
                <a href="journal_entry_view.php?id=<?php echo $journal_header_id; ?>" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to View
                </a>
            </div>
        </div>
    </div>
    
    <div class="card-body">
        <?php if (isset($_SESSION['alert_message'])): ?>
            <div class="alert alert-<?php echo $_SESSION['alert_type']; ?> alert-dismissible">
                <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
                <i class="icon fas fa-<?php echo $_SESSION['alert_type'] == 'success' ? 'check' : 'exclamation-triangle'; ?>"></i>
                <?php echo $_SESSION['alert_message']; ?>
            </div>
            <?php 
            unset($_SESSION['alert_type']);
            unset($_SESSION['alert_message']);
            ?>
        <?php endif; ?>

        <form method="POST" id="journalForm">
            <input type="hidden" name="action" value="update_journal">
            
            <!-- Header Information -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="form-group">
                        <label>Header Name <strong class="text-danger">*</strong></label>
                        <input type="text" class="form-control" name="header_name" 
                               value="<?php echo htmlspecialchars($journal_header['header_name']); ?>" required>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <label>Reference Number <strong class="text-danger">*</strong></label>
                        <input type="text" class="form-control" name="reference_number" 
                               value="<?php echo htmlspecialchars($journal_header['reference_number']); ?>" required>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <label>Entry Number</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($journal_entry['entry_number']); ?>" readonly>
                    </div>
                </div>
            </div>
            
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="form-group">
                        <label>Entry Date <strong class="text-danger">*</strong></label>
                        <input type="date" class="form-control" name="entry_date" 
                               value="<?php echo htmlspecialchars($journal_header['entry_date']); ?>" required>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <label>Entry Type <strong class="text-danger">*</strong></label>
                        <select class="form-control select2" name="entry_type" required>
                            <?php foreach ($entry_types as $value => $label): ?>
                                <option value="<?php echo $value; ?>" 
                                        <?php echo $journal_entry['entry_type'] == $value ? 'selected' : ''; ?>>
                                    <?php echo $label; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <label>Currency <strong class="text-danger">*</strong></label>
                        <select class="form-control select2" name="currency_code" required>
                            <?php
                            $currencies_sql = "SELECT currency_code, currency_name FROM currencies WHERE is_active = 1 ORDER BY currency_code";
                            $currencies_result = $mysqli->query($currencies_sql);
                            while ($currency = $currencies_result->fetch_assoc()):
                            ?>
                                <option value="<?php echo $currency['currency_code']; ?>" 
                                        <?php echo $journal_entry['currency_code'] == $currency['currency_code'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($currency['currency_code'] . ' - ' . $currency['currency_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>
            </div>
            
            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="form-group">
                        <label>Description <strong class="text-danger">*</strong></label>
                        <textarea class="form-control" name="description" rows="2" required><?php echo htmlspecialchars($journal_header['description']); ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Journal Entry Lines -->
            <div class="row mb-3">
                <div class="col-md-12">
                    <h5 class="mb-3">
                        <i class="fas fa-list mr-2"></i>Journal Entry Lines
                        <span class="badge badge-primary ml-2"><?php echo count($lines); ?> lines</span>
                    </h5>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered" id="linesTable">
                    <thead class="bg-light">
                        <tr>
                            <th width="30%">Account</th>
                            <th width="10%">Type</th>
                            <th width="20%">Amount</th>
                            <th width="35%">Description</th>
                            <th width="5%"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lines as $line): ?>
                            <tr>
                                <td>
                                    <select class="form-control select2" name="lines[<?php echo $line['line_id']; ?>][account_id]" required>
                                        <option value="">Select Account</option>
                                        <?php 
                                        $accounts_result->data_seek(0);
                                        while ($account = $accounts_result->fetch_assoc()): 
                                            $selected = $account['account_id'] == $line['account_id'] ? 'selected' : '';
                                        ?>
                                            <option value="<?php echo $account['account_id']; ?>" <?php echo $selected; ?>>
                                                <?php echo htmlspecialchars($account['account_number'] . ' - ' . $account['account_name']); ?>
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                </td>
                                <td>
                                    <select class="form-control" name="lines[<?php echo $line['line_id']; ?>][entry_type]" required>
                                        <option value="debit" <?php echo $line['entry_type'] == 'debit' ? 'selected' : ''; ?>>Debit</option>
                                        <option value="credit" <?php echo $line['entry_type'] == 'credit' ? 'selected' : ''; ?>>Credit</option>
                                    </select>
                                </td>
                                <td>
                                    <div class="input-group">
                                        <div class="input-group-prepend">
                                            <span class="input-group-text"><?php echo $journal_entry['currency_code']; ?></span>
                                        </div>
                                        <input type="number" class="form-control" name="lines[<?php echo $line['line_id']; ?>][amount]" 
                                               step="0.01" min="0.01" value="<?php echo number_format($line['amount'], 2, '.', ''); ?>" required>
                                    </div>
                                </td>
                                <td>
                                    <input type="text" class="form-control" name="lines[<?php echo $line['line_id']; ?>][description]" 
                                           value="<?php echo htmlspecialchars($line['description']); ?>" placeholder="Line description...">
                                </td>
                                <td class="text-center">
                                    <?php if (count($lines) > 2): ?>
                                        <button type="button" class="btn btn-danger btn-sm remove-line" title="Remove Line">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="bg-light">
                            <td colspan="2" class="text-right font-weight-bold">Total:</td>
                            <td class="text-right font-weight-bold">
                                <span class="text-success">Dr: <?php echo number_format($total_debits, 2); ?></span><br>
                                <span class="text-danger">Cr: <?php echo number_format($total_credits, 2); ?></span>
                            </td>
                            <td colspan="2" class="text-center">
                                <?php $is_balanced = abs($total_debits - $total_credits) < 0.01; ?>
                                <span class="badge badge-<?php echo $is_balanced ? 'success' : 'danger'; ?>">
                                    <?php echo $is_balanced ? 'BALANCED' : 'UNBALANCED'; ?>
                                </span>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Action Buttons -->
            <div class="row mt-4">
                <div class="col-md-12">
                    <div class="form-group">
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save mr-2"></i>Update Journal Entry
                        </button>
                        <a href="journal_entry_view.php?id=<?php echo $journal_header_id; ?>" class="btn btn-secondary">
                            <i class="fas fa-times mr-2"></i>Cancel
                        </a>
                        <button type="button" class="btn btn-danger float-right" id="voidJournal">
                            <i class="fas fa-ban mr-2"></i>Void Journal
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.select2').select2();
    
    // Recalculate totals when amounts change
    $('input[name*="amount"]').on('input', function() {
        calculateTotals();
    });
    
    // Recalculate when entry types change
    $('select[name*="entry_type"]').on('change', function() {
        calculateTotals();
    });
    
    // Remove line
    $(document).on('click', '.remove-line', function() {
        if ($('#linesTable tbody tr').length > 2) {
            $(this).closest('tr').remove();
            calculateTotals();
        } else {
            alert('Journal entry must have at least 2 lines (one debit and one credit)');
        }
    });
    
    // Void journal confirmation
    $('#voidJournal').click(function() {
        if (confirm('Are you sure you want to void this journal entry? This action cannot be undone.')) {
            window.location.href = 'post.php?void_journal=<?php echo $journal_header_id; ?>';
        }
    });
    
    function calculateTotals() {
        let totalDebits = 0;
        let totalCredits = 0;
        
        $('#linesTable tbody tr').each(function() {
            const amount = parseFloat($(this).find('input[name*="amount"]').val()) || 0;
            const type = $(this).find('select[name*="entry_type"]').val();
            
            if (type === 'debit') {
                totalDebits += amount;
            } else if (type === 'credit') {
                totalCredits += amount;
            }
        });
        
        // Update totals display
        $('tfoot .text-success').text('Dr: ' + totalDebits.toFixed(2));
        $('tfoot .text-danger').text('Cr: ' + totalCredits.toFixed(2));
        
        // Update balance status
        const diff = Math.abs(totalDebits - totalCredits);
        const balanceBadge = $('tfoot .badge');
        if (diff < 0.01) {
            balanceBadge.removeClass('badge-danger').addClass('badge-success').text('BALANCED');
        } else {
            balanceBadge.removeClass('badge-success').addClass('badge-danger').text('UNBALANCED: ' + diff.toFixed(2));
        }
    }
    
    // Form validation
    $('#journalForm').on('submit', function(e) {
        let totalDebits = 0;
        let totalCredits = 0;
        
        $('#linesTable tbody tr').each(function() {
            const amount = parseFloat($(this).find('input[name*="amount"]').val()) || 0;
            const type = $(this).find('select[name*="entry_type"]').val();
            
            if (type === 'debit') {
                totalDebits += amount;
            } else if (type === 'credit') {
                totalCredits += amount;
            }
        });
        
        if (Math.abs(totalDebits - totalCredits) > 0.01) {
            e.preventDefault();
            alert('Journal entry must be balanced before submitting. Debits and credits do not match.');
            return false;
        }
        
        // Check minimum lines
        if ($('#linesTable tbody tr').length < 2) {
            e.preventDefault();
            alert('Journal entry must have at least 2 lines (double-entry accounting requirement).');
            return false;
        }
    });
    
    // Initial calculation
    calculateTotals();
});
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
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

// Get journal entry
$entry_sql = "SELECT 
    je.entry_id, je.entry_number, je.entry_date, je.entry_description, 
    je.reference_number, je.entry_type, je.currency_code, je.source_document,
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
$debit_entries = [];
$credit_entries = [];

while ($line = $lines_result->fetch_assoc()) {
    $lines[] = $line;
    $amount = floatval($line['amount']);
    
    if ($line['entry_type'] == 'debit') {
        $total_debits += $amount;
        $debit_entries[] = $line;
    } else {
        $total_credits += $amount;
        $credit_entries[] = $line;
    }
}

$is_balanced = abs($total_debits - $total_credits) < 0.01;
$is_today = $journal_header['entry_date'] == date('Y-m-d');
$is_draft = $journal_header['status'] == 'draft';

// Entry type labels
$entry_types = [
    'payment' => 'Payment',
    'receipt' => 'Receipt',
    'adjustment' => 'Adjustment',
    'opening' => 'Opening Balance',
    'closing' => 'Closing Entry'
];

?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0 text-white">
                <i class="fas fa-fw fa-book mr-2"></i>Journal Entry Details
            </h3>
            <div class="card-tools">
                <div class="btn-group">
                    <a href="journal_entries.php" class="btn btn-light">
                        <i class="fas fa-arrow-left mr-2"></i>Back to List
                    </a>
                    <?php if ($is_today && $is_draft): ?>
                        <a href="journal_entry_edit.php?id=<?php echo $journal_header_id; ?>" class="btn btn-warning">
                            <i class="fas fa-edit mr-2"></i>Edit
                        </a>
                    <?php endif; ?>
                    <a href="journal_entry_print.php?id=<?php echo $journal_header_id; ?>" class="btn btn-info" target="_blank">
                        <i class="fas fa-print mr-2"></i>Print
                    </a>
                </div>
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

        <!-- Header Information -->
        <div class="row mb-4">
            <div class="col-md-8">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h4 class="mb-1"><?php echo htmlspecialchars($journal_header['header_name']); ?></h4>
                        <p class="text-muted mb-2"><?php echo htmlspecialchars($journal_header['description']); ?></p>
                        <div class="d-flex flex-wrap gap-3">
                            <span class="badge badge-<?php echo $journal_header['status'] == 'posted' ? 'success' : 'warning'; ?>">
                                <?php echo strtoupper($journal_header['status']); ?>
                            </span>
                            <span class="badge badge-info">
                                <?php echo count($lines); ?> Lines
                            </span>
                            <span class="badge badge-<?php echo $is_balanced ? 'success' : 'danger'; ?>">
                                <?php echo $is_balanced ? 'BALANCED' : 'UNBALANCED'; ?>
                            </span>
                            <?php if ($journal_entry['entry_type']): ?>
                                <span class="badge badge-secondary">
                                    <?php echo $entry_types[$journal_entry['entry_type']] ?? ucfirst($journal_entry['entry_type']); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="bg-light p-3 rounded">
                    <div class="row text-center">
                        <div class="col-6">
                            <div class="border-right">
                                <div class="text-success font-weight-bold h5"><?php echo numfmt_format_currency($currency_format, $total_debits, $journal_entry['currency_code'] ?? $session_company_currency); ?></div>
                                <small class="text-muted">Total Debits</small>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="text-danger font-weight-bold h5"><?php echo numfmt_format_currency($currency_format, $total_credits, $journal_entry['currency_code'] ?? $session_company_currency); ?></div>
                            <small class="text-muted">Total Credits</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Journal Details -->
        <div class="row mb-4">
            <div class="col-md-3">
                <strong>Entry Number:</strong><br>
                <span class="text-primary"><?php echo htmlspecialchars($journal_entry['entry_number']); ?></span>
            </div>
            <div class="col-md-3">
                <strong>Reference Number:</strong><br>
                <span><?php echo htmlspecialchars($journal_header['reference_number']); ?></span>
            </div>
            <div class="col-md-3">
                <strong>Entry Date:</strong><br>
                <span><?php echo date('F j, Y', strtotime($journal_header['entry_date'])); ?></span>
            </div>
            <div class="col-md-3">
                <strong>Currency:</strong><br>
                <span><?php echo htmlspecialchars($journal_entry['currency_code'] ?? $session_company_currency); ?></span>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-3">
                <strong>Created By:</strong><br>
                <span><?php echo htmlspecialchars($journal_header['created_by'] ?? 'System'); ?></span>
            </div>
            <div class="col-md-3">
                <strong>Created On:</strong><br>
                <span><?php echo date('F j, Y g:i A', strtotime($journal_header['created_at'])); ?></span>
            </div>
            <div class="col-md-3">
                <strong>Entry Type:</strong><br>
                <span><?php echo $entry_types[$journal_entry['entry_type']] ?? ucfirst($journal_entry['entry_type']); ?></span>
            </div>
            <div class="col-md-3">
                <?php if ($journal_entry['source_document']): ?>
                    <strong>Source Document:</strong><br>
                    <span class="text-info"><?php echo htmlspecialchars($journal_entry['source_document']); ?></span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Journal Entries -->
        <div class="row">
            <div class="col-md-12">
                <h5 class="mb-3 border-bottom pb-2">
                    <i class="fas fa-list mr-2"></i>Journal Entry Lines
                </h5>
            </div>
        </div>

        <div class="row">
            <!-- Debit Entries -->
            <div class="col-md-6">
                <div class="card border-success">
                    <div class="card-header bg-success text-white py-2">
                        <h6 class="card-title mb-0">
                            <i class="fas fa-arrow-left mr-2"></i>Debit Entries
                            <span class="badge badge-light float-right"><?php echo count($debit_entries); ?></span>
                        </h6>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Account</th>
                                        <th class="text-right">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($debit_entries as $entry): ?>
                                        <tr>
                                            <td>
                                                <div class="font-weight-bold"><?php echo htmlspecialchars($entry['account_name']); ?></div>
                                                <small class="text-muted">
                                                    <?php echo htmlspecialchars($entry['account_number']); ?> • 
                                                    <?php echo htmlspecialchars($entry['type_name']); ?>
                                                </small>
                                                <?php if ($entry['description']): ?>
                                                    <br><small class="text-info"><?php echo htmlspecialchars($entry['description']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-right font-weight-bold text-success">
                                                <?php echo numfmt_format_currency($currency_format, $entry['amount'], $journal_entry['currency_code'] ?? $session_company_currency); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <tr class="bg-light font-weight-bold">
                                        <td>Total Debits</td>
                                        <td class="text-right text-success">
                                            <?php echo numfmt_format_currency($currency_format, $total_debits, $journal_entry['currency_code'] ?? $session_company_currency); ?>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Credit Entries -->
            <div class="col-md-6">
                <div class="card border-danger">
                    <div class="card-header bg-danger text-white py-2">
                        <h6 class="card-title mb-0">
                            <i class="fas fa-arrow-right mr-2"></i>Credit Entries
                            <span class="badge badge-light float-right"><?php echo count($credit_entries); ?></span>
                        </h6>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Account</th>
                                        <th class="text-right">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($credit_entries as $entry): ?>
                                        <tr>
                                            <td>
                                                <div class="font-weight-bold"><?php echo htmlspecialchars($entry['account_name']); ?></div>
                                                <small class="text-muted">
                                                    <?php echo htmlspecialchars($entry['account_number']); ?> • 
                                                    <?php echo htmlspecialchars($entry['type_name']); ?>
                                                </small>
                                                <?php if ($entry['description']): ?>
                                                    <br><small class="text-info"><?php echo htmlspecialchars($entry['description']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-right font-weight-bold text-danger">
                                                <?php echo numfmt_format_currency($currency_format, $entry['amount'], $journal_entry['currency_code'] ?? $session_company_currency); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <tr class="bg-light font-weight-bold">
                                        <td>Total Credits</td>
                                        <td class="text-right text-danger">
                                            <?php echo numfmt_format_currency($currency_format, $total_credits, $journal_entry['currency_code'] ?? $session_company_currency); ?>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Balance Summary -->
        <div class="row mt-4">
            <div class="col-md-12">
                <div class="card border-<?php echo $is_balanced ? 'success' : 'danger'; ?>">
                    <div class="card-header bg-<?php echo $is_balanced ? 'success' : 'danger'; ?> text-white py-2">
                        <h6 class="card-title mb-0">
                            <i class="fas fa-balance-scale mr-2"></i>Balance Summary
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-md-4">
                                <div class="border-right">
                                    <div class="h4 text-success"><?php echo numfmt_format_currency($currency_format, $total_debits, $journal_entry['currency_code'] ?? $session_company_currency); ?></div>
                                    <small class="text-muted">Total Debits</small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="border-right">
                                    <div class="h4 text-danger"><?php echo numfmt_format_currency($currency_format, $total_credits, $journal_entry['currency_code'] ?? $session_company_currency); ?></div>
                                    <small class="text-muted">Total Credits</small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="h4 text-<?php echo $is_balanced ? 'success' : 'danger'; ?>">
                                    <?php echo numfmt_format_currency($currency_format, $total_debits - $total_credits, $journal_entry['currency_code'] ?? $session_company_currency); ?>
                                </div>
                                <small class="text-muted">Difference</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="row mt-4">
            <div class="col-md-12">
                <div class="btn-toolbar justify-content-between">
                    <div class="btn-group">
                        <a href="journal_entries.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left mr-2"></i>Back to List
                        </a>
                        <a href="reports_general_ledger.php" class="btn btn-info">
                            <i class="fas fa-book mr-2"></i>General Ledger
                        </a>
                    </div>
                    <div class="btn-group">
                        <?php if ($is_today && $is_draft): ?>
                            <a href="journal_entry_edit.php?id=<?php echo $journal_header_id; ?>" class="btn btn-warning">
                                <i class="fas fa-edit mr-2"></i>Edit Journal
                            </a>
                            <button type="button" class="btn btn-danger" id="voidJournal">
                                <i class="fas fa-ban mr-2"></i>Void Journal
                            </button>
                        <?php endif; ?>
                        <a href="journal_entry_print.php?id=<?php echo $journal_header_id; ?>" class="btn btn-primary" target="_blank">
                            <i class="fas fa-print mr-2"></i>Print
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Void journal confirmation
    $('#voidJournal').click(function() {
        if (confirm('Are you sure you want to void this journal entry? This action cannot be undone.')) {
            window.location.href = 'post.php?void_journal=<?php echo $journal_header_id; ?>';
        }
    });
});
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
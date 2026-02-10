<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Get payment ID from URL
$payment_id = intval($_GET['payment_id'] ?? 0);

if ($payment_id <= 0) {
    $_SESSION['alert_message'] = "Invalid payment ID";
    $_SESSION['alert_type'] = "error";
    header("Location: billing_payments.php");
    exit();
}

// Fetch payment details - CORRECTED to match your schema
$sql = mysqli_query(
    $mysqli,
    "SELECT 
        p.*,
        pat.patient_id,
        pat.first_name,
        pat.last_name,
        pat.email,
        pat.phone_primary,
        CONCAT(pat.first_name, ' ', pat.last_name) as patient_name,
        inv.invoice_id,
        inv.invoice_number,
        inv.invoice_date,
        inv.total_amount as total_amount,
        inv.invoice_status,
        u.user_name as received_by_name,
        u2.user_name as created_by_name
    FROM payments p
    LEFT JOIN invoices inv ON p.invoice_id = inv.invoice_id
    LEFT JOIN patients pat ON inv.patient_id = pat.patient_id
    LEFT JOIN users u ON p.posted_by = u.user_id
    LEFT JOIN users u2 ON p.created_by = u.user_id
    WHERE p.payment_id = $payment_id"
);

if (mysqli_num_rows($sql) == 0) {
    $_SESSION['alert_message'] = "Payment not found";
    $_SESSION['alert_type'] = "error";
    header("Location: billing_payments.php");
    exit();
}

$payment = mysqli_fetch_assoc($sql);

// Format dates
$payment_date = date('M j, Y', strtotime($payment['payment_date']));
$created_date = date('M j, Y g:i A', strtotime($payment['created_at']));
$updated_date = $payment['updated_at'] ? date('M j, Y g:i A', strtotime($payment['updated_at'])) : 'Never';

// Status badge styling - CORRECTED for your schema's status values
$status_badge = "badge-secondary";
switch($payment['status']) {
    case 'posted': $status_badge = "badge-success"; break;
    case 'pending': $status_badge = "badge-warning"; break;
    case 'void': $status_badge = "badge-danger"; break;
    case 'reversed': $status_badge = "badge-secondary"; break;
}

// Method badge styling - CORRECTED for your schema's payment_method values
$method_badge = "badge-info";
switch($payment['payment_method']) {
    case 'mobile_money': $method_badge = "badge-primary"; break;
    case 'cash': $method_badge = "badge-success"; break;
    case 'bank_transfer': $method_badge = "badge-info"; break;
    case 'credit_card': $method_badge = "badge-warning"; break;
    case 'check': $method_badge = "badge-secondary"; break;
    case 'insurance': $method_badge = "badge-dark"; break;
    case 'other': $method_badge = "badge-light"; break;
}

// Get payment method display name
$method_display_names = [
    'mobile_money' => 'Mobile Money',
    'bank_transfer' => 'Bank Transfer',
    'credit_card' => 'Credit Card',
    'cash' => 'Cash',
    'check' => 'Check',
    'insurance' => 'Insurance',
    'other' => 'Other'
];

$method_display = $method_display_names[$payment['payment_method']] ?? ucfirst($payment['payment_method']);

// Check if there are pending M-Pesa transactions for this payment
$mpesa_sql = mysqli_query(
    $mysqli,
    "SELECT * FROM mpesa_pending_transactions 
     WHERE payment_id = $payment_id 
     ORDER BY created_at DESC LIMIT 1"
);

$mpesa_details = mysqli_num_rows($mpesa_sql) > 0 ? mysqli_fetch_assoc($mpesa_sql) : null;


// Get journal entry details if payment was posted to accounting
$journal_sql = mysqli_query(
    $mysqli,
    "SELECT je.*, u.user_name as posted_by_name
     FROM journal_entries je
     LEFT JOIN users u ON je.posted_by = u.user_id
     WHERE je.journal_entry_id = " . intval($payment['journal_entry_id'])
);

$journal_details = mysqli_num_rows($journal_sql) > 0 ? mysqli_fetch_assoc($journal_sql) : null;

?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2">
                <i class="fa fa-fw fa-money-bill-wave mr-2"></i>Payment Details
            </h3>
            <div class="card-tools">
                <a href="billing_payments.php" class="btn btn-outline-secondary">
                    <i class="fas fa-fw fa-arrow-left mr-2"></i>Back to Payments
                </a>
                <a href="billing_payment_edit.php?payment_id=<?php echo $payment_id; ?>" class="btn btn-primary ml-2">
                    <i class="fas fa-fw fa-edit mr-2"></i>Edit Payment
                </a>
            </div>
        </div>
    </div>
    
    <div class="card-body">
        <div class="row">
            <!-- Payment Information -->
            <div class="col-md-8">
                <div class="card mb-4">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-info-circle mr-2"></i>Payment Information
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td class="text-muted" width="40%">Payment Number:</td>
                                        <td class="font-weight-bold"><?php echo nullable_htmlentities($payment['payment_number']); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Amount:</td>
                                        <td class="font-weight-bold text-success h5">KSH <?php echo number_format($payment['payment_amount'], 2); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Status:</td>
                                        <td>
                                            <span class="badge <?php echo $status_badge; ?> badge-lg">
                                                <?php echo ucfirst($payment['status']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Payment Method:</td>
                                        <td>
                                            <span class="badge <?php echo $method_badge; ?>">
                                                <?php echo $method_display; ?>
                                            </span>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td class="text-muted" width="40%">Payment Date:</td>
                                        <td class="font-weight-bold"><?php echo $payment_date; ?></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Reference Number:</td>
                                        <td class="font-weight-bold"><?php echo nullable_htmlentities($payment['reference_number']) ?: 'N/A'; ?></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Posted Date:</td>
                                        <td class="font-weight-bold"><?php echo $payment['posted_at'] ? date('M j, Y g:i A', strtotime($payment['posted_at'])) : 'Not Posted'; ?></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Posted By:</td>
                                        <td class="font-weight-bold"><?php echo nullable_htmlentities($payment['received_by_name']) ?: 'N/A'; ?></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                        
                        <!-- Payment Method Specific Details -->
                        <?php if (!empty($mpesa_details) || !empty($payment['bank_name']) || !empty($payment['check_number'])): ?>
                        <div class="row mt-4">
                            <div class="col-12">
                                <h6 class="text-muted border-bottom pb-2">
                                    <i class="fas fa-credit-card mr-2"></i>
                                    Payment Details
                                </h6>
                                <div class="row">
                                    <?php if ($mpesa_details && $payment['payment_method'] == 'mobile_money'): ?>
                                        <div class="col-md-6">
                                            <table class="table table-sm table-borderless">
                                                <tr>
                                                    <td class="text-muted" width="50%">Phone Number:</td>
                                                    <td class="font-weight-bold"><?php echo nullable_htmlentities($mpesa_details['phone_number']); ?></td>
                                                </tr>
                                                <tr>
                                                    <td class="text-muted">Transaction ID:</td>
                                                    <td class="font-weight-bold"><?php echo nullable_htmlentities($mpesa_details['checkout_request_id']); ?></td>
                                                </tr>
                                                <tr>
                                                    <td class="text-muted">Status:</td>
                                                    <td>
                                                        <span class="badge badge-<?php echo $mpesa_details['status'] == 'completed' ? 'success' : 'warning'; ?>">
                                                            <?php echo ucfirst($mpesa_details['status']); ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            </table>
                                        </div>
                                    <?php elseif (!empty($payment['bank_name']) && $payment['payment_method'] == 'bank_transfer'): ?>
                                        <div class="col-md-6">
                                            <table class="table table-sm table-borderless">
                                                <tr>
                                                    <td class="text-muted" width="50%">Bank Name:</td>
                                                    <td class="font-weight-bold"><?php echo nullable_htmlentities($payment['bank_name']); ?></td>
                                                </tr>
                                                <tr>
                                                    <td class="text-muted">Reference:</td>
                                                    <td class="font-weight-bold"><?php echo nullable_htmlentities($payment['reference_number']); ?></td>
                                                </tr>
                                            </table>
                                        </div>
                                    <?php elseif (!empty($payment['check_number']) && $payment['payment_method'] == 'check'): ?>
                                        <div class="col-md-6">
                                            <table class="table table-sm table-borderless">
                                                <tr>
                                                    <td class="text-muted" width="50%">Check Number:</td>
                                                    <td class="font-weight-bold"><?php echo nullable_htmlentities($payment['check_number']); ?></td>
                                                </tr>
                                                <tr>
                                                    <td class="text-muted">Bank Name:</td>
                                                    <td class="font-weight-bold"><?php echo nullable_htmlentities($payment['bank_name']); ?></td>
                                                </tr>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($register_details): ?>
                                        <div class="col-md-6">
                                            <table class="table table-sm table-borderless">
                                                <tr>
                                                    <td class="text-muted" width="50%">Cash Register:</td>
                                                    <td class="font-weight-bold"><?php echo nullable_htmlentities($register_details['register_name']); ?></td>
                                                </tr>
                                                <tr>
                                                    <td class="text-muted">Register Balance:</td>
                                                    <td class="font-weight-bold">KSH <?php echo number_format($register_details['cash_balance'], 2); ?></td>
                                                </tr>
                                                <tr>
                                                    <td class="text-muted">Opened By:</td>
                                                    <td class="font-weight-bold"><?php echo nullable_htmlentities($register_details['opened_by_name']); ?></td>
                                                </tr>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Journal Entry Details -->
                        <?php if ($journal_details): ?>
                        <div class="row mt-4">
                            <div class="col-12">
                                <h6 class="text-muted border-bottom pb-2">
                                    <i class="fas fa-book mr-2"></i>Accounting Information
                                </h6>
                                <div class="row">
                                    <div class="col-md-6">
                                        <table class="table table-sm table-borderless">
                                            <tr>
                                                <td class="text-muted" width="50%">Journal Entry #:</td>
                                                <td class="font-weight-bold"><?php echo nullable_htmlentities($journal_details['journal_entry_number']); ?></td>
                                            </tr>
                                            <tr>
                                                <td class="text-muted">Transaction Date:</td>
                                                <td class="font-weight-bold"><?php echo date('M j, Y', strtotime($journal_details['transaction_date'])); ?></td>
                                            </tr>
                                            <tr>
                                                <td class="text-muted">Posted By:</td>
                                                <td class="font-weight-bold"><?php echo nullable_htmlentities($journal_details['posted_by_name']); ?></td>
                                            </tr>
                                        </table>
                                    </div>
                                    <div class="col-md-6">
                                        <table class="table table-sm table-borderless">
                                            <tr>
                                                <td class="text-muted" width="50%">Total Debit:</td>
                                                <td class="font-weight-bold text-success">KSH <?php echo number_format($journal_details['total_debit'], 2); ?></td>
                                            </tr>
                                            <tr>
                                                <td class="text-muted">Total Credit:</td>
                                                <td class="font-weight-bold text-danger">KSH <?php echo number_format($journal_details['total_credit'], 2); ?></td>
                                            </tr>
                                            <tr>
                                                <td class="text-muted">Status:</td>
                                                <td>
                                                    <span class="badge badge-<?php echo $journal_details['status'] == 'posted' ? 'success' : 'warning'; ?>">
                                                        <?php echo ucfirst($journal_details['status']); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                                <?php if (!empty($journal_details['description'])): ?>
                                <div class="mt-2">
                                    <small class="text-muted">Description:</small>
                                    <p class="mb-0"><?php echo nl2br(htmlspecialchars($journal_details['description'])); ?></p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Payment Notes -->
                        <?php if (!empty($payment['notes'])): ?>
                        <div class="row mt-4">
                            <div class="col-12">
                                <h6 class="text-muted border-bottom pb-2">
                                    <i class="fas fa-sticky-note mr-2"></i>Payment Notes
                                </h6>
                                <div class="bg-light p-3 rounded">
                                    <?php echo nl2br(htmlspecialchars($payment['notes'])); ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Related Information -->
            <div class="col-md-4">
                <!-- Patient Information -->
                <div class="card mb-4">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-user mr-2"></i>Patient Information
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if ($payment['patient_id']): ?>
                            <div class="text-center mb-3">
                                <div class="bg-primary rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 60px; height: 60px;">
                                    <i class="fas fa-user text-white fa-2x"></i>
                                </div>
                            </div>
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <td class="text-muted" width="40%">Name:</td>
                                    <td class="font-weight-bold"><?php echo nullable_htmlentities($payment['patient_name']); ?></td>
                                </tr>
                                <tr>
                                    <td class="text-muted">MRN:</td>
                                    <td><?php echo nullable_htmlentities($payment['patient_mrn']) ?: 'N/A'; ?></td>
                                </tr>
                                <tr>
                                    <td class="text-muted">Phone:</td>
                                    <td><?php echo nullable_htmlentities($payment['patient_phone']) ?: 'N/A'; ?></td>
                                </tr>
                                <tr>
                                    <td class="text-muted">Email:</td>
                                    <td><?php echo nullable_htmlentities($payment['patient_email']) ?: 'N/A'; ?></td>
                                </tr>
                            </table>
                            <div class="text-center mt-3">
                                <a href="patient_details.php?patient_id=<?php echo $payment['patient_id']; ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-eye mr-1"></i>View Patient
                                </a>
                            </div>
                        <?php else: ?>
                            <p class="text-muted text-center mb-0">No patient associated</p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Invoice Information -->
                <div class="card mb-4">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-file-invoice mr-2"></i>Invoice Information
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if ($payment['invoice_id']): ?>
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <td class="text-muted" width="40%">Invoice #:</td>
                                    <td class="font-weight-bold"><?php echo nullable_htmlentities($payment['invoice_number']); ?></td>
                                </tr>
                                <tr>
                                    <td class="text-muted">Date:</td>
                                    <td><?php echo $payment['invoice_date'] ? date('M j, Y', strtotime($payment['invoice_date'])) : 'N/A'; ?></td>
                                </tr>
                                <tr>
                                    <td class="text-muted">Amount:</td>
                                    <td class="font-weight-bold">KSH <?php echo number_format($payment['total_amount'], 2); ?></td>
                                </tr>
                                <tr>
                                    <td class="text-muted">Status:</td>
                                    <td>
                                        <span class="badge badge-<?php 
                                            echo $payment['invoice_status'] == 'paid' ? 'success' : 
                                                 ($payment['invoice_status'] == 'partially_paid' ? 'warning' : 'danger');
                                        ?>">
                                            <?php echo ucwords(str_replace('_', ' ', $payment['invoice_status'])); ?>
                                        </span>
                                    </td>
                                </tr>
                            </table>
                            <div class="text-center mt-3">
                                <a href="billing_invoice_view.php?invoice_id=<?php echo $payment['invoice_id']; ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-eye mr-1"></i>View Invoice
                                </a>
                            </div>
                        <?php else: ?>
                            <p class="text-muted text-center mb-0">No invoice associated</p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- System Information -->
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-cog mr-2"></i>System Information
                        </h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm table-borderless">
                            <tr>
                                <td class="text-muted" width="50%">Created By:</td>
                                <td><?php echo nullable_htmlentities($payment['created_by_name']) ?: 'System'; ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted">Created Date:</td>
                                <td><?php echo $created_date; ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted">Last Updated:</td>
                                <td><?php echo $updated_date; ?></td>
                            </tr>
                            <?php if ($payment['posted_by']): ?>
                            <tr>
                                <td class="text-muted">Posted By:</td>
                                <td><?php echo nullable_htmlentities($payment['received_by_name']) ?: 'System'; ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted">Posted Date:</td>
                                <td><?php echo $payment['posted_at'] ? date('M j, Y g:i A', strtotime($payment['posted_at'])) : 'Not Posted'; ?></td>
                            </tr>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Action Buttons -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-body text-center">
                        <div class="btn-group">
                            <?php if ($payment['status'] == 'pending'): ?>
                                <a href="post.php?mark_payment_posted=<?php echo $payment_id; ?>" class="btn btn-success" onclick="return confirm('Mark this payment as posted?')">
                                    <i class="fas fa-check mr-2"></i>Mark as Posted
                                </a>
                            <?php endif; ?>
                            
                            <?php if ($payment['status'] == 'posted'): ?>
                                <a href="post.php?mark_payment_void=<?php echo $payment_id; ?>" class="btn btn-danger" onclick="return confirm('Void this payment? This action cannot be undone.')">
                                    <i class="fas fa-times mr-2"></i>Void Payment
                                </a>
                            <?php endif; ?>
                            
                            <a href="billing_payment_edit.php?payment_id=<?php echo $payment_id; ?>" class="btn btn-primary">
                                <i class="fas fa-edit mr-2"></i>Edit Payment
                            </a>
                            
                            <a href="billing_payment_print.php?payment_id=<?php echo $payment_id; ?>" target="_blank" class="btn btn-info">
                                <i class="fas fa-print mr-2"></i>Print Receipt
                            </a>
                            
                            <div class="btn-group">
                                <button type="button" class="btn btn-secondary dropdown-toggle" data-toggle="dropdown">
                                    <i class="fas fa-cog mr-2"></i>More Actions
                                </button>
                                <div class="dropdown-menu">
                                    <?php if ($payment['status'] == 'void'): ?>
                                        <a class="dropdown-item text-warning" href="post.php?reverse_payment=<?php echo $payment_id; ?>" onclick="return confirm('Reverse this voided payment?')">
                                            <i class="fas fa-undo mr-2"></i>Reverse Void
                                        </a>
                                        <div class="dropdown-divider"></div>
                                    <?php endif; ?>
                                    
                                    <a class="dropdown-item" href="post.php?email_payment_receipt=<?php echo $payment_id; ?>">
                                        <i class="fas fa-envelope mr-2"></i>Email Receipt
                                    </a>
                                    
                                    <a class="dropdown-item" href="billing_payment_refund.php?payment_id=<?php echo $payment_id; ?>">
                                        <i class="fas fa-arrow-left mr-2"></i>Process Refund
                                    </a>
                                    
                                    <div class="dropdown-divider"></div>
                                    
                                    <a class="dropdown-item text-danger" href="post.php?delete_payment=<?php echo $payment_id; ?>" onclick="return confirm('Are you sure you want to delete this payment? This action cannot be undone.')">
                                        <i class="fas fa-trash mr-2"></i>Delete Payment
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Print receipt function
    function printReceipt() {
        window.open('billing_payment_print.php?payment_id=<?php echo $payment_id; ?>', '_blank');
    }
    
    // Keyboard shortcuts
    $(document).keydown(function(e) {
        // Ctrl+P for print
        if (e.ctrlKey && e.keyCode === 80) {
            e.preventDefault();
            printReceipt();
        }
        
        // Ctrl+E for edit
        if (e.ctrlKey && e.keyCode === 69) {
            e.preventDefault();
            window.location.href = 'billing_payment_edit.php?payment_id=<?php echo $payment_id; ?>';
        }
        
        // Ctrl+M for mark as posted (if pending)
        <?php if ($payment['status'] == 'pending'): ?>
        if (e.ctrlKey && e.keyCode === 77) {
            e.preventDefault();
            if (confirm('Mark this payment as posted?')) {
                window.location.href = 'post.php?mark_payment_posted=<?php echo $payment_id; ?>';
            }
        }
        <?php endif; ?>
    });
    
    // Auto-refresh if payment is pending (check every 30 seconds)
    <?php if ($payment['status'] == 'pending'): ?>
    setInterval(function() {
        $.ajax({
            url: 'ajax/check_payment_status.php',
            type: 'GET',
            data: { payment_id: <?php echo $payment_id; ?> },
            success: function(response) {
                if (response.success && response.status !== 'pending') {
                    location.reload();
                }
            }
        });
    }, 30000);
    <?php endif; ?>
});
</script>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
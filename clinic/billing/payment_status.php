<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

$checkout_id = sanitizeInput($_GET['checkout'] ?? '');

if (!$checkout_id) {
    header("Location: billing_dashboard.php");
    exit;
}

// Get payment status
$sql = "SELECT mp.*, i.invoice_number, p.patient_first_name, p.patient_last_name,
               CONCAT(p.patient_first_name, ' ', p.patient_last_name) as patient_name
        FROM mpesa_pending_transactions mp
        LEFT JOIN invoices i ON mp.invoice_id = i.invoice_id
        LEFT JOIN patients p ON i.patient_id = p.patient_id
        WHERE mp.checkout_request_id = ?";
$stmt = mysqli_prepare($mysqli, $sql);
mysqli_stmt_bind_param($stmt, 's', $checkout_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$transaction = mysqli_fetch_assoc($result);

if (!$transaction) {
    flash_alert("Payment not found", 'error');
    header("Location: billing_dashboard.php");
    exit;
}

$status_class = 'warning';
$status_icon = 'clock';
$status_text = 'Waiting for payment...';

switch ($transaction['status']) {
    case 'completed':
        $status_class = 'success';
        $status_icon = 'check-circle';
        $status_text = 'Payment completed successfully!';
        break;
    case 'failed':
        $status_class = 'danger';
        $status_icon = 'times-circle';
        $status_text = 'Payment failed. Please try again.';
        break;
}
?>

<div class="card">
    <div class="card-header bg-primary py-3">
        <h3 class="card-title text-white">
            <i class="fas fa-mobile-alt mr-2"></i>M-Pesa Payment Status
        </h3>
        <div class="card-tools">
            <a href="billing_invoice_create_payment.php?invoice_id=<?php echo $transaction['invoice_id']; ?>" 
               class="btn btn-light">
                <i class="fas fa-arrow-left mr-2"></i>Back to Payment
            </a>
        </div>
    </div>
    
    <div class="card-body">
        <div class="row">
            <div class="col-md-6 offset-md-3">
                <div class="text-center mb-4">
                    <div class="mb-3">
                        <i class="fas fa-<?php echo $status_icon; ?> fa-4x text-<?php echo $status_class; ?>"></i>
                    </div>
                    <h3 class="text-<?php echo $status_class; ?>"><?php echo $status_text; ?></h3>
                </div>
                
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-borderless">
                                <tr>
                                    <th width="40%">Invoice Number:</th>
                                    <td><?php echo htmlspecialchars($transaction['invoice_number']); ?></td>
                                </tr>
                                <tr>
                                    <th>Patient:</th>
                                    <td><?php echo htmlspecialchars($transaction['patient_name']); ?></td>
                                </tr>
                                <tr>
                                    <th>Amount:</th>
                                    <td class="font-weight-bold">KSH <?php echo number_format($transaction['amount'], 2); ?></td>
                                </tr>
                                <tr>
                                    <th>Phone Number:</th>
                                    <td><?php echo htmlspecialchars($transaction['phone_number']); ?></td>
                                </tr>
                                <tr>
                                    <th>Status:</th>
                                    <td>
                                        <span class="badge badge-<?php echo $status_class; ?>">
                                            <?php echo ucfirst($transaction['status']); ?>
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Initiated:</th>
                                    <td><?php echo date('F j, Y H:i', strtotime($transaction['created_at'])); ?></td>
                                </tr>
                            </table>
                        </div>
                        
                        <?php if ($transaction['status'] === 'pending'): ?>
                        <div class="alert alert-info mt-3">
                            <i class="fas fa-info-circle mr-2"></i>
                            <strong>Instructions:</strong>
                            <ol class="mb-0 mt-2">
                                <li>Check your phone for M-Pesa prompt</li>
                                <li>Enter your M-Pesa PIN to complete payment</li>
                                <li>Wait for SMS confirmation</li>
                                <li>Status will update automatically</li>
                            </ol>
                        </div>
                        <?php endif; ?>
                        
                        <div class="text-center mt-4">
                            <?php if ($transaction['status'] === 'completed'): ?>
                                <a href="payment_receipt.php?payment_id=<?php echo $transaction['payment_id']; ?>" 
                                   class="btn btn-success btn-lg">
                                    <i class="fas fa-receipt mr-2"></i>View Receipt
                                </a>
                            <?php elseif ($transaction['status'] === 'failed'): ?>
                                <a href="billing_invoice_create_payment.php?invoice_id=<?php echo $transaction['invoice_id']; ?>" 
                                   class="btn btn-danger btn-lg">
                                    <i class="fas fa-redo mr-2"></i>Try Again
                                </a>
                            <?php else: ?>
                                <button onclick="refreshStatus()" class="btn btn-primary btn-lg">
                                    <i class="fas fa-sync-alt mr-2"></i>Check Status
                                </button>
                            <?php endif; ?>
                            
                            <a href="billing_invoices.php" class="btn btn-outline-secondary btn-lg ml-2">
                                <i class="fas fa-list mr-2"></i>View All Invoices
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function refreshStatus() {
    location.reload();
}

// Auto-refresh every 10 seconds if still pending
<?php if ($transaction['status'] === 'pending'): ?>
setTimeout(function() {
    refreshStatus();
}, 10000);
<?php endif; ?>
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
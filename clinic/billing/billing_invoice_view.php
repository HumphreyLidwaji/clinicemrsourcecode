<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Get invoice ID from URL
$invoice_id = intval($_GET['invoice_id'] ?? 0);

if ($invoice_id <= 0) {
    $_SESSION['alert_msg'] = "Invalid invoice ID";
    header("Location: billing_invoices.php");
    exit();
}

// Fetch invoice details with new table structure
$sql = mysqli_query(
    $mysqli,
    "SELECT 
        i.*,
        p.patient_id,
        p.first_name,
        p.last_name,
        p.email,
        p.phone_primary,
        p.date_of_birth,
        p.sex,
        CONCAT(p.first_name, ' ', p.last_name) as patient_name,
        v.visit_datetime,
        v.visit_id,
        u.user_name as created_by_name,
        pb.pending_bill_id,
        pb.pending_bill_number
    FROM invoices i
    LEFT JOIN patients p ON i.patient_id = p.patient_id
    LEFT JOIN visits v ON i.visit_id = v.visit_id
    LEFT JOIN users u ON i.created_by = u.user_id
    LEFT JOIN pending_bills pb ON i.pending_bill_id = pb.pending_bill_id
    WHERE i.invoice_id = $invoice_id"
);

if (mysqli_num_rows($sql) == 0) {
    $_SESSION['alert_msg'] = "Invoice not found";
    header("Location: billing_invoices.php");
    exit();
}

$invoice = mysqli_fetch_assoc($sql);

// Fetch invoice items from new table structure
$items_sql = mysqli_query(
    $mysqli,
    "SELECT 
        ii.*,
        bi.item_name,
        bi.item_description,
        bi.item_code,
        bi.item_type,
        bi.source_table,
        pli.price_list_id,
        pli.is_taxable,
        pli.discount_allowed
    FROM invoice_items ii
    LEFT JOIN billable_items bi ON ii.billable_item_id = bi.billable_item_id
    LEFT JOIN price_list_items pli ON ii.price_list_item_id = pli.price_list_item_id
    WHERE ii.invoice_id = $invoice_id
    ORDER BY ii.invoice_item_id ASC"
);

$invoice_items = [];
$subtotal = 0;
$total_discount = 0;
$total_tax = 0;
$grand_total = 0;

while ($item = mysqli_fetch_assoc($items_sql)) {
    $invoice_items[] = $item;
    $subtotal += $item['subtotal'];
    $total_discount += $item['discount_amount'];
    $total_tax += $item['tax_amount'];
    $grand_total += $item['total_amount'];
}

// Fetch pending bill items for reference (if available)
$pending_items_sql = mysqli_query(
    $mysqli,
    "SELECT 
        pbi.*,
        bi.item_name,
        bi.item_code,
        bi.item_description
    FROM pending_bill_items pbi
    LEFT JOIN billable_items bi ON pbi.billable_item_id = bi.billable_item_id
    WHERE pbi.pending_bill_id = " . intval($invoice['pending_bill_id']) . "
    AND pbi.is_cancelled = 0
    ORDER BY pbi.created_at ASC"
);

$pending_items = [];
while ($pending_item = mysqli_fetch_assoc($pending_items_sql)) {
    $pending_items[] = $pending_item;
}

// Fetch payments for this invoice
$payments_sql = mysqli_query(
    $mysqli,
    "SELECT 
        p.*,
        u.user_name as created_by_name,
        ba.account_name as bank_account_name,
        ca.account_name as cash_account_name
    FROM payments p
    LEFT JOIN users u ON p.created_by = u.user_id
    LEFT JOIN accounts ba ON p.bank_account_id = ba.account_id
    LEFT JOIN accounts ca ON p.cash_account_id = ca.account_id
    WHERE p.invoice_id = $invoice_id
    ORDER BY p.payment_date DESC, p.created_at DESC"
);

$payments = [];
$total_paid = 0;
while ($payment = mysqli_fetch_assoc($payments_sql)) {
    $payments[] = $payment;
    if ($payment['status'] == 'posted') {
        $total_paid += $payment['payment_amount'];
    }
}

// Calculate balances using new column names
$balance_due = $invoice['total_amount'] - $total_paid;
$paid_percentage = $invoice['total_amount'] > 0 ? ($total_paid / $invoice['total_amount']) * 100 : 0;

// Format dates
$invoice_date = date('M j, Y', strtotime($invoice['invoice_date']));
$due_date = $invoice['due_date'] ? date('M j, Y', strtotime($invoice['due_date'])) : 'N/A';
$created_date = date('M j, Y g:i A', strtotime($invoice['created_at']));
$updated_date = $invoice['updated_at'] ? date('M j, Y g:i A', strtotime($invoice['updated_at'])) : 'Never';
$finalized_date = $invoice['finalized_at'] ? date('M j, Y g:i A', strtotime($invoice['finalized_at'])) : 'Not finalized';

// Status badge styling - updated for new statuses
$status_badge = "badge-secondary";
switch($invoice['invoice_status']) {
    case 'issued': $status_badge = "badge-primary"; break;
    case 'partially_paid': $status_badge = "badge-warning"; break;
    case 'paid': $status_badge = "badge-success"; break;
    case 'cancelled': $status_badge = "badge-dark"; break;
    case 'refunded': $status_badge = "badge-danger"; break;
}

// Price list type badge styling
$type_badge = "badge-info";
switch($invoice['price_list_type']) {
    case 'insurance': $type_badge = "badge-primary"; break;
    case 'cash': $type_badge = "badge-success"; break;
    case 'corporate': $type_badge = "badge-warning"; break;
    case 'staff': $type_badge = "badge-info"; break;
    default: $type_badge = "badge-secondary";
}

// Check if overdue
$is_overdue = $invoice['invoice_status'] == 'issued' && 
              $invoice['due_date'] && 
              strtotime($invoice['due_date']) < time();
$days_overdue = $is_overdue ? floor((time() - strtotime($invoice['due_date'])) / (60 * 60 * 24)) : 0;

?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2">
                <i class="fa fa-fw fa-file-invoice mr-2"></i>Invoice Details
            </h3>
            <div class="card-tools">
                <a href="billing_invoices.php" class="btn btn-outline-secondary">
                    <i class="fas fa-fw fa-arrow-left mr-2"></i>Back to Invoices
                </a>
                <a href="billing_invoice_print.php?invoice_id=<?php echo $invoice_id; ?>" target="_blank" class="btn btn-info ml-2">
                    <i class="fas fa-fw fa-print mr-2"></i>Print
                </a>
                <?php if ($invoice['invoice_status'] == 'issued'): ?>
                    <a href="billing_invoice_edit.php?invoice_id=<?php echo $invoice_id; ?>" class="btn btn-primary ml-2">
                        <i class="fas fa-fw fa-edit mr-2"></i>Edit Invoice
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="card-body">
        <!-- Invoice Header -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="d-flex align-items-center mb-3">
                    <div class="bg-primary rounded-circle d-inline-flex align-items-center justify-content-center mr-3" style="width: 50px; height: 50px;">
                        <i class="fas fa-file-invoice text-white fa-lg"></i>
                    </div>
                    <div>
                        <h4 class="mb-0">Invoice #<?php echo nullable_htmlentities($invoice['invoice_number']); ?></h4>
                        <p class="text-muted mb-0">Created: <?php echo $invoice_date; ?></p>
                        <?php if ($invoice['pending_bill_number']): ?>
                            <p class="text-muted mb-0">Bill #<?php echo nullable_htmlentities($invoice['pending_bill_number']); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-6 text-right">
                <div class="mb-2">
                    <span class="badge <?php echo $status_badge; ?> badge-lg p-2">
                        <?php echo ucfirst(str_replace('_', ' ', $invoice['invoice_status'])); ?>
                        <?php if ($is_overdue): ?>
                            <span class="badge badge-danger ml-1">Overdue: <?php echo $days_overdue; ?> days</span>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="mb-1">
                    <span class="badge <?php echo $type_badge; ?> p-2">
                        <?php echo ucfirst($invoice['price_list_type'] ?? 'N/A'); ?> Rate
                    </span>
                </div>
                <div>
                    <span class="badge badge-light p-2">
                        Price List: <?php echo nullable_htmlentities($invoice['price_list_name']); ?>
                    </span>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Left Column - Invoice Details -->
            <div class="col-md-8">
                <!-- Patient and Invoice Information -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card h-100">
                            <div class="card-header bg-light">
                                <h6 class="card-title mb-0">
                                    <i class="fas fa-user mr-2"></i>Patient Information
                                </h6>
                            </div>
                            <div class="card-body">
                                <?php if ($invoice['patient_id']): ?>
                                    <h6 class="font-weight-bold"><?php echo nullable_htmlentities($invoice['patient_name']); ?></h6>
                                    <p class="mb-1">
                                        <i class="fas fa-id-card text-muted mr-2"></i>
                                        <?php echo nullable_htmlentities($invoice['patient_identifier']) ?: 'N/A'; ?>
                                    </p>
                                    <p class="mb-1">
                                        <i class="fas fa-phone text-muted mr-2"></i>
                                        <?php echo nullable_htmlentities($invoice['phone_primary']) ?: 'N/A'; ?>
                                    </p>
                                    <p class="mb-1">
                                        <i class="fas fa-envelope text-muted mr-2"></i>
                                        <?php echo nullable_htmlentities($invoice['email']) ?: 'N/A'; ?>
                                    </p>
                                    <div class="mt-3">
                                        <a href="patient_details.php?patient_id=<?php echo $invoice['patient_id']; ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-eye mr-1"></i>View Patient
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <p class="text-muted mb-0">No patient associated</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card h-100">
                            <div class="card-header bg-light">
                                <h6 class="card-title mb-0">
                                    <i class="fas fa-info-circle mr-2"></i>Invoice Information
                                </h6>
                            </div>
                            <div class="card-body">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td class="text-muted" width="40%">Invoice Date:</td>
                                        <td class="font-weight-bold"><?php echo $invoice_date; ?></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Due Date:</td>
                                        <td class="font-weight-bold <?php echo $is_overdue ? 'text-danger' : ''; ?>">
                                            <?php echo $due_date; ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Visit Date:</td>
                                        <td><?php echo $invoice['visit_datetime'] ? date('M j, Y', strtotime($invoice['visit_datetime'])) : 'N/A'; ?></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Payment Terms:</td>
                                        <td><?php echo nullable_htmlentities($invoice['payment_terms']) ?: 'Immediate'; ?></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Payment Method:</td>
                                        <td><?php echo nullable_htmlentities($invoice['payment_method']) ?: 'Not specified'; ?></td>
                                    </tr>
                                    <?php if ($invoice['transaction_reference']): ?>
                                        <tr>
                                            <td class="text-muted">Reference:</td>
                                            <td><?php echo nullable_htmlentities($invoice['transaction_reference']); ?></td>
                                        </tr>
                                    <?php endif; ?>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Invoice Items -->
                <div class="card mb-4">
                    <div class="card-header bg-light">
                        <h6 class="card-title mb-0">
                            <i class="fas fa-list mr-2"></i>Invoice Items
                        </h6>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th width="5%">#</th>
                                        <th width="35%">Item Description</th>
                                        <th width="10%" class="text-center">Code</th>
                                        <th width="10%" class="text-center">Qty</th>
                                        <th width="15%" class="text-right">Unit Price</th>
                                        <th width="15%" class="text-right">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($invoice_items)): ?>
                                        <?php foreach ($invoice_items as $index => $item): ?>
                                            <tr>
                                                <td class="text-muted"><?php echo $index + 1; ?></td>
                                                <td>
                                                    <div class="font-weight-bold"><?php echo nullable_htmlentities($item['item_name']); ?></div>
                                                    <?php if (!empty($item['item_description'])): ?>
                                                        <small class="text-muted"><?php echo nullable_htmlentities($item['item_description']); ?></small>
                                                    <?php endif; ?>
                                                    <?php if (!empty($item['item_type'])): ?>
                                                        <small class="badge badge-light"><?php echo ucfirst($item['item_type']); ?></small>
                                                    <?php endif; ?>
                                                    <?php if ($item['discount_amount'] > 0): ?>
                                                        <small class="badge badge-success">Discounted</small>
                                                    <?php endif; ?>
                                                    <?php if ($item['tax_amount'] > 0): ?>
                                                        <small class="badge badge-warning">Tax: <?php echo number_format($item['tax_percentage'], 2); ?>%</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <small class="text-muted"><?php echo nullable_htmlentities($item['item_code']); ?></small>
                                                </td>
                                                <td class="text-center"><?php echo number_format($item['item_quantity'], 2); ?></td>
                                                <td class="text-right">KSH <?php echo number_format($item['unit_price'], 2); ?></td>
                                                <td class="text-right font-weight-bold">KSH <?php echo number_format($item['total_amount'], 2); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="text-center py-4 text-muted">
                                                <i class="fas fa-list fa-2x mb-2"></i>
                                                <p>No items found for this invoice</p>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Source Bill Items (if available) -->
                <?php if (!empty($pending_items) && $invoice['pending_bill_id']): ?>
                <div class="card mb-4">
                    <div class="card-header bg-light">
                        <h6 class="card-title mb-0">
                            <i class="fas fa-history mr-2"></i>Source Bill Items (Bill #<?php echo nullable_htmlentities($invoice['pending_bill_number']); ?>)
                        </h6>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Item</th>
                                        <th class="text-center">Qty</th>
                                        <th class="text-right">Unit Price</th>
                                        <th class="text-right">Total</th>
                                        <th>Source</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pending_items as $pending_item): ?>
                                        <tr>
                                            <td>
                                                <div class="font-weight-bold"><?php echo nullable_htmlentities($pending_item['item_name']); ?></div>
                                                <?php if (!empty($pending_item['item_description'])): ?>
                                                    <small class="text-muted"><?php echo nullable_htmlentities($pending_item['item_description']); ?></small>
                                                <?php endif; ?>
                                                <small class="badge badge-light"><?php echo nullable_htmlentities($pending_item['item_code']); ?></small>
                                            </td>
                                            <td class="text-center"><?php echo number_format($pending_item['item_quantity'], 2); ?></td>
                                            <td class="text-right">KSH <?php echo number_format($pending_item['unit_price'], 2); ?></td>
                                            <td class="text-right">KSH <?php echo number_format($pending_item['total_amount'], 2); ?></td>
                                            <td>
                                                <small class="badge badge-info">
                                                    <?php echo str_replace('_', ' ', $pending_item['source_type']); ?>
                                                </small>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Invoice Notes -->
                <?php if (!empty($invoice['notes'])): ?>
                <div class="card">
                    <div class="card-header bg-light">
                        <h6 class="card-title mb-0">
                            <i class="fas fa-sticky-note mr-2"></i>Notes
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="bg-light p-3 rounded">
                            <?php echo nl2br(htmlspecialchars($invoice['notes'])); ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Right Column - Summary and Payments -->
            <div class="col-md-4">
                <!-- Invoice Summary -->
                <div class="card mb-4">
                    <div class="card-header bg-light">
                        <h6 class="card-title mb-0">
                            <i class="fas fa-calculator mr-2"></i>Invoice Summary
                        </h6>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm table-borderless">
                            <tr>
                                <td class="text-muted">Subtotal:</td>
                                <td class="text-right font-weight-bold">KSH <?php echo number_format($invoice['subtotal_amount'], 2); ?></td>
                            </tr>
                            <?php if ($invoice['discount_amount'] > 0): ?>
                                <tr>
                                    <td class="text-muted">Discount:</td>
                                    <td class="text-right text-danger font-weight-bold">- KSH <?php echo number_format($invoice['discount_amount'], 2); ?></td>
                                </tr>
                            <?php endif; ?>
                            <?php if ($invoice['tax_amount'] > 0): ?>
                                <tr>
                                    <td class="text-muted">Tax:</td>
                                    <td class="text-right font-weight-bold">KSH <?php echo number_format($invoice['tax_amount'], 2); ?></td>
                                </tr>
                            <?php endif; ?>
                            <tr class="border-top">
                                <td class="font-weight-bold">Total Amount:</td>
                                <td class="text-right font-weight-bold h5 text-primary">KSH <?php echo number_format($invoice['total_amount'], 2); ?></td>
                            </tr>
                            <tr>
                                <td class="font-weight-bold text-success">Paid Amount:</td>
                                <td class="text-right font-weight-bold h5 text-success">KSH <?php echo number_format($total_paid, 2); ?></td>
                            </tr>
                            <tr class="border-top">
                                <td class="font-weight-bold <?php echo $balance_due > 0 ? 'text-warning' : 'text-success'; ?>">Balance Due:</td>
                                <td class="text-right font-weight-bold h4 <?php echo $balance_due > 0 ? 'text-warning' : 'text-success'; ?>">
                                    KSH <?php echo number_format($balance_due, 2); ?>
                                </td>
                            </tr>
                        </table>

                        <!-- Payment Progress -->
                        <?php if ($invoice['total_amount'] > 0): ?>
                        <div class="mt-3">
                            <div class="d-flex justify-content-between mb-1">
                                <small class="text-muted">Payment Progress</small>
                                <small class="text-muted"><?php echo number_format($paid_percentage, 1); ?>%</small>
                            </div>
                            <div class="progress" style="height: 8px;">
                                <div class="progress-bar <?php echo $paid_percentage == 100 ? 'bg-success' : ($paid_percentage > 0 ? 'bg-info' : 'bg-warning'); ?>" 
                                     role="progressbar" 
                                     style="width: <?php echo $paid_percentage; ?>%" 
                                     aria-valuenow="<?php echo $paid_percentage; ?>" 
                                     aria-valuemin="0" 
                                     aria-valuemax="100">
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Payment History -->
                <div class="card mb-4">
                    <div class="card-header bg-light">
                        <h6 class="card-title mb-0">
                            <i class="fas fa-money-bill-wave mr-2"></i>Payment History
                        </h6>
                    </div>
                    <div class="card-body p-0">
                        <?php if (!empty($payments)): ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($payments as $payment): ?>
                                    <div class="list-group-item px-3 py-2">
                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                            <div>
                                                <h6 class="mb-0">KSH <?php echo number_format($payment['payment_amount'], 2); ?></h6>
                                                <small class="text-muted">
                                                    <?php echo date('M j, Y', strtotime($payment['payment_date'])); ?>
                                                    • <?php echo ucfirst(str_replace('_', ' ', $payment['payment_method'])); ?>
                                                </small>
                                            </div>
                                            <span class="badge badge-<?php 
                                                switch($payment['status']) {
                                                    case 'posted': echo 'success'; break;
                                                    case 'pending': echo 'warning'; break;
                                                    case 'void': echo 'danger'; break;
                                                    case 'reversed': echo 'secondary'; break;
                                                    default: echo 'light';
                                                }
                                            ?>">
                                                <?php echo ucfirst($payment['status']); ?>
                                            </span>
                                        </div>
                                        <?php if (!empty($payment['reference_number'])): ?>
                                            <small class="text-muted">Ref: <?php echo nullable_htmlentities($payment['reference_number']); ?></small>
                                        <?php endif; ?>
                                        <?php if (!empty($payment['bank_name'])): ?>
                                            <small class="text-muted d-block">Bank: <?php echo nullable_htmlentities($payment['bank_name']); ?></small>
                                        <?php endif; ?>
                                        <?php if (!empty($payment['check_number'])): ?>
                                            <small class="text-muted d-block">Check: <?php echo nullable_htmlentities($payment['check_number']); ?></small>
                                        <?php endif; ?>
                                        <?php if (!empty($payment['notes'])): ?>
                                            <small class="text-muted d-block"><?php echo nullable_htmlentities($payment['notes']); ?></small>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-muted text-center mb-0 py-3">No payments recorded</p>
                        <?php endif; ?>
                        
                        <?php if ($balance_due > 0 && $invoice['invoice_status'] != 'cancelled' && $invoice['invoice_status'] != 'refunded'): ?>
                            <div class="p-3">
                                <a href="process_payment.php?invoice_id=<?php echo $invoice_id; ?>" class="btn btn-success btn-sm btn-block">
                                    <i class="fas fa-plus mr-1"></i>Record Payment
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- System Information -->
                <div class="card">
                    <div class="card-header bg-light">
                        <h6 class="card-title mb-0">
                            <i class="fas fa-info-circle mr-2"></i>System Information
                        </h6>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm table-borderless">
                            <tr>
                                <td class="text-muted" width="50%">Created:</td>
                                <td><?php echo $created_date; ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted">Created By:</td>
                                <td><?php echo nullable_htmlentities($invoice['created_by_name']) ?: 'System'; ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted">Finalized:</td>
                                <td><?php echo $finalized_date; ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted">Last Updated:</td>
                                <td><?php echo $updated_date; ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Print invoice function
    function printInvoice() {
        window.open('billing_invoice_print.php?invoice_id=<?php echo $invoice_id; ?>', '_blank');
    }
    
    // Keyboard shortcuts
    $(document).keydown(function(e) {
        // Ctrl+P for print
        if (e.ctrlKey && e.keyCode === 80) {
            e.preventDefault();
            printInvoice();
        }
        
        // Ctrl+E for edit (if issued)
        <?php if ($invoice['invoice_status'] == 'issued'): ?>
        if (e.ctrlKey && e.keyCode === 69) {
            e.preventDefault();
            window.location.href = 'billing_invoice_edit.php?invoice_id=<?php echo $invoice_id; ?>';
        }
        <?php endif; ?>
    });
});
</script>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
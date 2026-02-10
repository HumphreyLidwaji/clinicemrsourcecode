<?php
// billing_view.php - View Bill Details
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/audit_functions.php';

// Get pending_bill_id from URL
$pending_bill_id = intval($_GET['pending_bill_id'] ?? 0);

if ($pending_bill_id <= 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid bill ID";
    header("Location: billing.php");
    exit;
}

// Get bill details with all related information
$bill_sql = "SELECT pb.*, 
                    p.first_name, p.last_name, p.patient_mrn, p.patient_id,
                    p.date_of_birth, p.sex, p.phone_primary, p.email,
                    v.visit_id, v.visit_number, v.visit_type, v.visit_datetime, 
                    v.visit_status, v.department_id,
                    d.department_name,
                    doc.user_name as doctor_name,
                    pl.price_list_name, pl.price_list_type, pl.currency,
                    u.user_name as created_by_name,
                    i.invoice_number, i.invoice_status, i.amount_paid, i.amount_due,
                    i.invoice_date, i.due_date, i.payment_terms,
                    vi.insurance_company_id, ic.company_name as insurance_company,
                    vi.member_number, vi.coverage_percentage,
                    isc.scheme_name
             FROM pending_bills pb
             JOIN patients p ON pb.patient_id = p.patient_id
             JOIN visits v ON pb.visit_id = v.visit_id
             LEFT JOIN departments d ON v.department_id = d.department_id
             LEFT JOIN users doc ON v.attending_provider_id = doc.user_id
             LEFT JOIN price_lists pl ON pb.price_list_id = pl.price_list_id
             LEFT JOIN users u ON pb.created_by = u.user_id
             LEFT JOIN invoices i ON pb.invoice_id = i.invoice_id
             LEFT JOIN visit_insurance vi ON v.visit_id = vi.visit_id
             LEFT JOIN insurance_companies ic ON vi.insurance_company_id = ic.insurance_company_id
             LEFT JOIN insurance_schemes isc ON vi.insurance_scheme_id = isc.scheme_id
             WHERE pb.pending_bill_id = ?";
$bill_stmt = $mysqli->prepare($bill_sql);
$bill_stmt->bind_param("i", $pending_bill_id);
$bill_stmt->execute();
$bill_result = $bill_stmt->get_result();
$bill = $bill_result->fetch_assoc();

if (!$bill) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Bill not found";
    header("Location: billing.php");
    exit;
}

// Get bill items
$items_sql = "SELECT pbi.*, 
                     bi.item_code, bi.item_name, bi.item_description, bi.item_type,
                     bi.is_taxable, bi.tax_rate as item_tax_rate
              FROM pending_bill_items pbi
              JOIN billable_items bi ON pbi.billable_item_id = bi.billable_item_id
              WHERE pbi.pending_bill_id = ? AND pbi.is_cancelled = 0
              ORDER BY pbi.created_at ASC";
$items_stmt = $mysqli->prepare($items_sql);
$items_stmt->bind_param("i", $pending_bill_id);
$items_stmt->execute();
$items_result = $items_stmt->get_result();
$bill_items = $items_result->fetch_all(MYSQLI_ASSOC);

// Calculate item counts and totals
$item_count = count($bill_items);
$subtotal = 0;
$total_tax = 0;
$total_amount = $bill['total_amount'] ?? 0;

foreach ($bill_items as $item) {
    $subtotal += $item['subtotal'];
    $total_tax += $item['tax_amount'];
}

// Group items by type for statistics
$items_by_type = [];
foreach ($bill_items as $item) {
    $type = $item['item_type'] ?? 'other';
    if (!isset($items_by_type[$type])) {
        $items_by_type[$type] = [
            'count' => 0,
            'amount' => 0
        ];
    }
    $items_by_type[$type]['count']++;
    $items_by_type[$type]['amount'] += $item['total_amount'];
}

// Get payment history if invoice exists
$payments = [];
if ($bill['invoice_id']) {
    $payments_sql = "SELECT * FROM payments 
                     WHERE invoice_id = ? 
                     ORDER BY payment_date DESC";
    $payments_stmt = $mysqli->prepare($payments_sql);
    $payments_stmt->bind_param("i", $bill['invoice_id']);
    $payments_stmt->execute();
    $payments_result = $payments_stmt->get_result();
    $payments = $payments_result->fetch_all(MYSQLI_ASSOC);
}

// Calculate patient age
$age = '';
if (!empty($bill['date_of_birth'])) {
    $birthDate = new DateTime($bill['date_of_birth']);
    $today = new DateTime();
    $age = $today->diff($birthDate)->y . ' years';
}

// Format amounts
$currency = $bill['currency'] ?? 'KSH';
$patient_name = $bill['first_name'] . ' ' . $bill['last_name'];
?>

<div class="card">
    <div class="card-header bg-info py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-file-invoice-dollar mr-2"></i>Bill Details: <?php echo htmlspecialchars($bill['bill_number']); ?>
        </h3>
        <div class="card-tools">
            <div class="btn-group">
                <a href="billing.php" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Billing
                </a>
                <a href="billing_edit.php?pending_bill_id=<?php echo $pending_bill_id; ?>" class="btn btn-warning ml-2">
                    <i class="fas fa-edit mr-2"></i>Edit Bill
                </a>
                <button type="button" class="btn btn-primary ml-2" onclick="window.print()">
                    <i class="fas fa-print mr-2"></i>Print
                </button>
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

        <!-- Bill Header -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="text-primary mb-0"><?php echo htmlspecialchars($bill['bill_number']); ?></h2>
                        <small class="text-muted">Pending Bill ID: <?php echo $pending_bill_id; ?></small>
                    </div>
                    <div class="text-right">
                        <div class="h3 mb-0 text-success"><?php echo $currency; ?> <?php echo number_format($total_amount, 2); ?></div>
                        <span class="badge badge-<?php 
                            switch($bill['bill_status']) {
                                case 'draft': echo 'secondary'; break;
                                case 'pending': echo 'warning'; break;
                                case 'approved': echo 'success'; break;
                                case 'cancelled': echo 'danger'; break;
                                default: echo 'info';
                            }
                        ?>">
                            <?php echo htmlspecialchars(ucfirst($bill['bill_status'])); ?>
                        </span>
                        <?php if ($bill['is_finalized']): ?>
                            <span class="badge badge-success ml-1">Finalized</span>
                        <?php endif; ?>
                    </div>
                </div>
                <hr>
            </div>
        </div>

        <div class="row">
            <!-- Left Column - Bill Details -->
            <div class="col-md-8">
                
                <!-- Patient Information Card -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-user-injured mr-2"></i>Patient Information</h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="table-responsive">
                                    <table class="table table-sm table-borderless">
                                        <tr>
                                            <th width="40%" class="text-muted">Patient Name:</th>
                                            <td><strong><?php echo htmlspecialchars($patient_name); ?></strong></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Medical Record No:</th>
                                            <td><strong class="text-primary"><?php echo htmlspecialchars($bill['patient_mrn']); ?></strong></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Date of Birth:</th>
                                            <td><?php echo !empty($bill['date_of_birth']) ? date('M j, Y', strtotime($bill['date_of_birth'])) : 'Not specified'; ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Age:</th>
                                            <td><?php echo $age ?: 'N/A'; ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Gender:</th>
                                            <td><?php echo htmlspecialchars($bill['sex'] ?: 'Not specified'); ?></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="table-responsive">
                                    <table class="table table-sm table-borderless">
                                        <tr>
                                            <th class="text-muted">Primary Phone:</th>
                                            <td><?php echo htmlspecialchars($bill['phone_primary'] ?: 'Not specified'); ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Email:</th>
                                            <td><?php echo htmlspecialchars($bill['email'] ?: 'Not specified'); ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Payment Mode:</th>
                                            <td>
                                                <?php if ($bill['insurance_company']): ?>
                                                    <span class="badge badge-info">INSURANCE</span>
                                                <?php else: ?>
                                                    <span class="badge badge-success">CASH</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Patient ID:</th>
                                            <td>#<?php echo $bill['patient_id']; ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Visit ID:</th>
                                            <td>#<?php echo $bill['visit_id']; ?></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Visit Information Card -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-clipboard-list mr-2"></i>Visit Information</h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="table-responsive">
                                    <table class="table table-sm table-borderless">
                                        <tr>
                                            <th width="40%" class="text-muted">Visit Number:</th>
                                            <td><strong class="text-primary"><?php echo htmlspecialchars($bill['visit_number']); ?></strong></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Visit Type:</th>
                                            <td><?php echo htmlspecialchars($bill['visit_type']); ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Visit Status:</th>
                                            <td>
                                                <span class="badge badge-<?php 
                                                    switch($bill['visit_status']) {
                                                        case 'ACTIVE': echo 'success'; break;
                                                        case 'CLOSED': echo 'warning'; break;
                                                        case 'CANCELLED': echo 'danger'; break;
                                                        default: echo 'secondary';
                                                    }
                                                ?>">
                                                    <?php echo htmlspecialchars($bill['visit_status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Department:</th>
                                            <td><?php echo htmlspecialchars($bill['department_name'] ?: 'Not specified'); ?></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="table-responsive">
                                    <table class="table table-sm table-borderless">
                                        <tr>
                                            <th class="text-muted">Visit Date:</th>
                                            <td><?php echo date('M j, Y g:i A', strtotime($bill['visit_datetime'])); ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Attending Provider:</th>
                                            <td><?php echo htmlspecialchars($bill['doctor_name'] ?: 'Not assigned'); ?></td>
                                        </tr>
                                        <?php if ($bill['insurance_company']): ?>
                                        <tr>
                                            <th class="text-muted">Insurance Company:</th>
                                            <td><strong class="text-info"><?php echo htmlspecialchars($bill['insurance_company']); ?></strong></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Coverage:</th>
                                            <td><?php echo htmlspecialchars($bill['coverage_percentage'] ? $bill['coverage_percentage'] . '%' : 'Not specified'); ?></td>
                                        </tr>
                                        <?php endif; ?>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Bill Items Card -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2 d-flex justify-content-between align-items-center">
                        <h4 class="card-title mb-0"><i class="fas fa-list mr-2"></i>Bill Items (<?php echo $item_count; ?>)</h4>
                        <div>
                            <span class="badge badge-primary">Total: <?php echo $currency; ?> <?php echo number_format($total_amount, 2); ?></span>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <?php if ($item_count > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th width="5%">#</th>
                                            <th width="25%">Item</th>
                                            <th width="10%">Code</th>
                                            <th width="10%">Type</th>
                                            <th width="10%" class="text-right">Qty</th>
                                            <th width="15%" class="text-right">Unit Price</th>
                                            <th width="10%" class="text-right">Tax</th>
                                            <th width="15%" class="text-right">Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $counter = 1; ?>
                                        <?php foreach ($bill_items as $item): ?>
                                            <tr>
                                                <td><?php echo $counter++; ?></td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($item['item_name']); ?></strong><br>
                                                    <small class="text-muted"><?php echo htmlspecialchars($item['item_description'] ?: ''); ?></small>
                                                </td>
                                                <td>
                                                    <code><?php echo htmlspecialchars($item['item_code']); ?></code>
                                                </td>
                                                <td>
                                                    <span class="badge badge-info">
                                                        <?php echo htmlspecialchars(ucfirst($item['item_type'])); ?>
                                                    </span>
                                                </td>
                                                <td class="text-right"><?php echo number_format($item['item_quantity'], 3); ?></td>
                                                <td class="text-right text-success"><?php echo $currency; ?> <?php echo number_format($item['unit_price'], 2); ?></td>
                                                <td class="text-right">
                                                    <?php if ($item['tax_amount'] > 0): ?>
                                                        <span class="text-info">
                                                            <?php echo $currency; ?> <?php echo number_format($item['tax_amount'], 2); ?><br>
                                                            <small>(<?php echo number_format($item['item_tax_rate'], 1); ?>%)</small>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-muted">N/A</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-right font-weight-bold text-primary">
                                                    <?php echo $currency; ?> <?php echo number_format($item['total_amount'], 2); ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="bg-light">
                                        <tr>
                                            <td colspan="5" class="text-right"><strong>Subtotal:</strong></td>
                                            <td colspan="2" class="text-right">
                                                <strong><?php echo $currency; ?> <?php echo number_format($subtotal, 2); ?></strong>
                                            </td>
                                            <td></td>
                                        </tr>
                                        <tr>
                                            <td colspan="5" class="text-right"><strong>Tax:</strong></td>
                                            <td colspan="2" class="text-right">
                                                <strong><?php echo $currency; ?> <?php echo number_format($total_tax, 2); ?></strong>
                                            </td>
                                            <td></td>
                                        </tr>
                                        <?php if ($bill['discount_amount'] > 0): ?>
                                        <tr>
                                            <td colspan="5" class="text-right text-danger"><strong>Discount:</strong></td>
                                            <td colspan="2" class="text-right text-danger">
                                                <strong>-<?php echo $currency; ?> <?php echo number_format($bill['discount_amount'], 2); ?></strong>
                                            </td>
                                            <td></td>
                                        </tr>
                                        <?php endif; ?>
                                        <tr class="table-primary">
                                            <td colspan="5" class="text-right"><strong>Total Amount:</strong></td>
                                            <td colspan="2" class="text-right">
                                                <h5 class="mb-0 text-success"><?php echo $currency; ?> <?php echo number_format($total_amount, 2); ?></h5>
                                            </td>
                                            <td></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="fas fa-shopping-cart fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">No Items in Bill</h5>
                                <p class="text-muted">This bill doesn't have any items yet</p>
                                <a href="billing_edit.php?pending_bill_id=<?php echo $pending_bill_id; ?>" class="btn btn-primary">
                                    <i class="fas fa-plus mr-2"></i>Add Items
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Bill Notes Card -->
                <?php if (!empty($bill['notes'])): ?>
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-sticky-note mr-2"></i>Bill Notes</h4>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-light">
                            <?php echo nl2br(htmlspecialchars($bill['notes'])); ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

            </div>

            <!-- Right Column - Summary & Actions -->
            <div class="col-md-4">
                
                <!-- Bill Summary Card -->
                <div class="card mb-4">
                    <div class="card-header bg-primary py-2">
                        <h4 class="card-title mb-0 text-white"><i class="fas fa-receipt mr-2"></i>Bill Summary</h4>
                    </div>
                    <div class="card-body">
                        <div class="text-center mb-3">
                            <div class="h4 text-primary"><?php echo htmlspecialchars($bill['bill_number']); ?></div>
                            <small class="text-muted">Pending Bill #</small>
                        </div>
                        <hr>
                        <div class="small">
                            <div class="d-flex justify-content-between mb-2">
                                <span>Bill Status:</span>
                                <span class="badge badge-<?php 
                                    switch($bill['bill_status']) {
                                        case 'draft': echo 'secondary'; break;
                                        case 'pending': echo 'warning'; break;
                                        case 'approved': echo 'success'; break;
                                        case 'cancelled': echo 'danger'; break;
                                        default: echo 'info';
                                    }
                                ?>">
                                    <?php echo htmlspecialchars(ucfirst($bill['bill_status'])); ?>
                                </span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Finalized:</span>
                                <span>
                                    <?php if ($bill['is_finalized']): ?>
                                        <span class="badge badge-success">Yes</span>
                                    <?php else: ?>
                                        <span class="badge badge-warning">No</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Price List:</span>
                                <span class="font-weight-bold"><?php echo htmlspecialchars($bill['price_list_name']); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Price List Type:</span>
                                <span class="badge badge-info"><?php echo htmlspecialchars($bill['price_list_type']); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Currency:</span>
                                <span class="font-weight-bold"><?php echo htmlspecialchars($currency); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-3">
                                <span>Created By:</span>
                                <span><?php echo htmlspecialchars($bill['created_by_name'] ?: 'Unknown'); ?></span>
                            </div>
                            
                            <div class="bg-light p-2 rounded mb-3">
                                <div class="d-flex justify-content-between">
                                    <span>Subtotal:</span>
                                    <span class="font-weight-bold"><?php echo $currency; ?> <?php echo number_format($subtotal, 2); ?></span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Tax:</span>
                                    <span class="font-weight-bold"><?php echo $currency; ?> <?php echo number_format($total_tax, 2); ?></span>
                                </div>
                                <?php if ($bill['discount_amount'] > 0): ?>
                                <div class="d-flex justify-content-between text-danger">
                                    <span>Discount:</span>
                                    <span class="font-weight-bold">-<?php echo $currency; ?> <?php echo number_format($bill['discount_amount'], 2); ?></span>
                                </div>
                                <?php endif; ?>
                                <div class="d-flex justify-content-between mt-1 pt-2 border-top">
                                    <span class="h5">Total:</span>
                                    <span class="h4 text-success"><?php echo $currency; ?> <?php echo number_format($total_amount, 2); ?></span>
                                </div>
                            </div>
                            
                            <div class="d-flex justify-content-between mb-2">
                                <span>Created Date:</span>
                                <span><?php echo date('M j, Y', strtotime($bill['created_at'])); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Bill Date:</span>
                                <span><?php echo date('M j, Y', strtotime($bill['bill_date'])); ?></span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span>Last Updated:</span>
                                <span><?php echo date('M j, Y', strtotime($bill['updated_at'])); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Invoice Information Card -->
                <?php if ($bill['invoice_number']): ?>
                <div class="card mb-4">
                    <div class="card-header bg-success py-2">
                        <h4 class="card-title mb-0 text-white"><i class="fas fa-file-invoice mr-2"></i>Invoice Information</h4>
                    </div>
                    <div class="card-body">
                        <div class="text-center mb-3">
                            <div class="h4 text-success"><?php echo htmlspecialchars($bill['invoice_number']); ?></div>
                            <small class="text-muted">Invoice #</small>
                        </div>
                        <hr>
                        <div class="small">
                            <div class="d-flex justify-content-between mb-2">
                                <span>Invoice Status:</span>
                                <span class="badge badge-<?php 
                                    switch($bill['invoice_status']) {
                                        case 'issued': echo 'info'; break;
                                        case 'partially_paid': echo 'warning'; break;
                                        case 'paid': echo 'success'; break;
                                        case 'cancelled': echo 'danger'; break;
                                        default: echo 'secondary';
                                    }
                                ?>">
                                    <?php echo htmlspecialchars(str_replace('_', ' ', ucfirst($bill['invoice_status']))); ?>
                                </span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Amount Paid:</span>
                                <span class="font-weight-bold text-success"><?php echo $currency; ?> <?php echo number_format($bill['amount_paid'], 2); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Amount Due:</span>
                                <span class="font-weight-bold text-danger"><?php echo $currency; ?> <?php echo number_format($bill['amount_due'], 2); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Invoice Date:</span>
                                <span><?php echo date('M j, Y', strtotime($bill['invoice_date'])); ?></span>
                            </div>
                            <?php if ($bill['due_date']): ?>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Due Date:</span>
                                <span><?php echo date('M j, Y', strtotime($bill['due_date'])); ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if ($bill['payment_terms']): ?>
                            <div class="d-flex justify-content-between">
                                <span>Payment Terms:</span>
                                <span><?php echo htmlspecialchars($bill['payment_terms']); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Item Statistics Card -->
                <div class="card mb-4">
                    <div class="card-header bg-info py-2">
                        <h4 class="card-title mb-0 text-white"><i class="fas fa-chart-pie mr-2"></i>Item Statistics</h4>
                    </div>
                    <div class="card-body">
                        <div class="small">
                            <div class="d-flex justify-content-between mb-2">
                                <span>Total Items:</span>
                                <span class="font-weight-bold"><?php echo $item_count; ?></span>
                            </div>
                            <?php foreach ($items_by_type as $type => $stats): ?>
                            <div class="d-flex justify-content-between mb-1">
                                <span><?php echo ucfirst($type); ?>:</span>
                                <span>
                                    <span class="badge badge-light"><?php echo $stats['count']; ?> items</span>
                                    <span class="text-success"><?php echo $currency; ?> <?php echo number_format($stats['amount'], 2); ?></span>
                                </span>
                            </div>
                            <?php endforeach; ?>
                            <hr>
                            <div class="d-flex justify-content-between mb-1">
                                <span>Subtotal:</span>
                                <span class="font-weight-bold"><?php echo $currency; ?> <?php echo number_format($subtotal, 2); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-1">
                                <span>Tax:</span>
                                <span class="font-weight-bold"><?php echo $currency; ?> <?php echo number_format($total_tax, 2); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mt-2 pt-2 border-top">
                                <span class="h6">Total:</span>
                                <span class="h5 text-success"><?php echo $currency; ?> <?php echo number_format($total_amount, 2); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Actions Card -->
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-cogs mr-2"></i>Actions</h4>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="billing_edit.php?pending_bill_id=<?php echo $pending_bill_id; ?>" class="btn btn-warning btn-block">
                                <i class="fas fa-edit mr-2"></i>Edit Bill
                            </a>
                            <a href="billing.php" class="btn btn-outline-primary btn-block">
                                <i class="fas fa-arrow-left mr-2"></i>Back to Billing
                            </a>
                            <?php if ($bill['invoice_number']): ?>
                                <a href="billing_invoice_view.php?invoice_id=<?php echo $bill['invoice_id']; ?>" class="btn btn-success btn-block">
                                    <i class="fas fa-file-invoice mr-2"></i>View Invoice
                                </a>
                            <?php endif; ?>
                            <button type="button" class="btn btn-outline-info btn-block" onclick="window.print()">
                                <i class="fas fa-print mr-2"></i>Print Bill
                            </button>
                            <a href="visit_details.php?visit_id=<?php echo $bill['visit_id']; ?>" class="btn btn-outline-secondary btn-block">
                                <i class="fas fa-eye mr-2"></i>View Visit Details
                            </a>
                            <?php if ($bill['bill_status'] == 'draft' && !$bill['is_finalized']): ?>
                                <button type="button" class="btn btn-danger btn-block" onclick="deleteBill(<?php echo $pending_bill_id; ?>)">
                                    <i class="fas fa-trash mr-2"></i>Delete Bill
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Auto-close alerts after 5 seconds
    setTimeout(function() {
        $('.alert').fadeOut('slow');
    }, 5000);
});

function deleteBill(billId) {
    if (confirm('Are you sure you want to delete this bill? This action cannot be undone.')) {
        const form = document.createElement('form');
        form.method = 'post';
        form.action = 'billing.php';
        
        const csrfToken = document.createElement('input');
        csrfToken.type = 'hidden';
        csrfToken.name = 'csrf_token';
        csrfToken.value = '<?php echo $_SESSION['csrf_token']; ?>';
        form.appendChild(csrfToken);
        
        const action = document.createElement('input');
        action.type = 'hidden';
        action.name = 'action';
        action.value = 'delete_pending_bill';
        form.appendChild(action);
        
        const billIdInput = document.createElement('input');
        billIdInput.type = 'hidden';
        billIdInput.name = 'pending_bill_id';
        billIdInput.value = billId;
        form.appendChild(billIdInput);
        
        document.body.appendChild(form);
        form.submit();
    }
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + E to edit
    if (e.ctrlKey && e.keyCode === 69) {
        e.preventDefault();
        window.location.href = 'billing_edit.php?pending_bill_id=<?php echo $pending_bill_id; ?>';
    }
    // Ctrl + P to print
    if (e.ctrlKey && e.keyCode === 80) {
        e.preventDefault();
        window.print();
    }
    // Escape to go back
    if (e.keyCode === 27) {
        window.location.href = 'billing.php';
    }
});
</script>

<style>
.card {
    border: none;
    box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
}
.card-header {
    border-bottom: 1px solid #e3e6f0;
}
.table th {
    font-weight: 600;
    color: #6e707e;
    font-size: 0.85rem;
    text-transform: uppercase;
}
.table-sm td, .table-sm th {
    padding: 0.5rem;
}
.code {
    font-family: 'Courier New', monospace;
    background-color: #f8f9fa;
    padding: 0.2rem 0.4rem;
    border-radius: 0.2rem;
    font-size: 0.85rem;
}
.bg-light {
    background-color: #f8f9fa !important;
}
.table-borderless th,
.table-borderless td {
    border: none !important;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
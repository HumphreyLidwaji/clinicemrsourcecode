<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

$purchase_order_id = intval($_GET['id'] ?? 0);

if ($purchase_order_id <= 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid purchase order ID";
    header("Location: inventory_purchase_orders.php");
    exit;
}

// Get purchase order details
$po_sql = "SELECT 
            po.*,
            s.supplier_id,
            s.supplier_name,
            s.supplier_contact,
            s.supplier_phone,
            s.supplier_email,
            s.supplier_address,
            l.location_id,
            l.location_name,
            l.location_type,
            req.user_id as requested_by_id,
            req.user_name as requested_by_name,
            req.user_name as requested_by_username,
            app.user_id as approved_by_id,
            app.user_name as approved_by_name,
            app.user_name as approved_by_username,
            cr.user_name as created_by_name,
            up.user_name as updated_by_name
        FROM inventory_purchase_orders po
        LEFT JOIN suppliers s ON po.supplier_id = s.supplier_id
        LEFT JOIN inventory_locations l ON po.delivery_location_id = l.location_id
        LEFT JOIN users req ON po.requested_by = req.user_id
        LEFT JOIN users app ON po.approved_by = app.user_id
        LEFT JOIN users cr ON po.created_by = cr.user_id
        LEFT JOIN users up ON po.updated_by = up.user_id
        WHERE po.purchase_order_id = ? AND po.is_active = 1";
        
$po_stmt = $mysqli->prepare($po_sql);
$po_stmt->bind_param("i", $purchase_order_id);
$po_stmt->execute();
$po_result = $po_stmt->get_result();
$po = $po_result->fetch_assoc();
$po_stmt->close();

if (!$po) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Purchase order not found or has been deleted";
    header("Location: inventory_purchase_orders.php");
    exit;
}

// Get purchase order items
$items_sql = "SELECT 
                poi.*,
                ii.item_id,
                ii.item_name,
                ii.item_code,
                ii.unit_of_measure,
                ii.is_drug,
                ii.requires_batch,
                ic.category_name,
                (SELECT SUM(quantity_received) FROM inventory_grn_items gi 
                 WHERE gi.purchase_order_item_id = poi.purchase_order_item_id 
                 AND gi.is_active = 1) as total_received_so_far
            FROM inventory_purchase_order_items poi
            INNER JOIN inventory_items ii ON poi.item_id = ii.item_id
            LEFT JOIN inventory_categories ic ON ii.category_id = ic.category_id
            WHERE poi.purchase_order_id = ? AND poi.is_active = 1
            ORDER BY ii.item_name";
            
$items_stmt = $mysqli->prepare($items_sql);
$items_stmt->bind_param("i", $purchase_order_id);
$items_stmt->execute();
$items_result = $items_stmt->get_result();
$items = [];
$total_ordered = 0;
$total_received = 0;
$total_estimated_amount = 0;
$total_received_amount = 0;
while ($item = $items_result->fetch_assoc()) {
    $item['estimated_total'] = $item['quantity_ordered'] * $item['unit_cost'];
    $item['received_total'] = $item['total_received_so_far'] * $item['unit_cost'];
    $total_ordered += $item['quantity_ordered'];
    $total_received += $item['total_received_so_far'];
    $total_estimated_amount += $item['estimated_total'];
    $total_received_amount += $item['received_total'];
    $items[] = $item;
}
$items_stmt->close();

// Get related GRNs
$grns_sql = "SELECT 
                g.*,
                u.user_name as received_by_name,
                v.user_name as verified_by_name
            FROM inventory_grns g
            LEFT JOIN users u ON g.received_by = u.user_id
            LEFT JOIN users v ON g.verified_by = v.user_id
            WHERE g.purchase_order_id = ? AND g.is_active = 1
            ORDER BY g.grn_date DESC";
            
$grns_stmt = $mysqli->prepare($grns_sql);
$grns_stmt->bind_param("i", $purchase_order_id);
$grns_stmt->execute();
$grns_result = $grns_stmt->get_result();
$grns = [];
while ($grn = $grns_result->fetch_assoc()) {
    $grns[] = $grn;
}
$grns_stmt->close();

// Calculate received percentage
$received_percentage = $total_ordered > 0 ? ($total_received / $total_ordered) * 100 : 0;

// Determine status badge
$status_badge = '';
$status_icon = '';
switch($po['status']) {
    case 'draft':
        $status_badge = 'badge-secondary';
        $status_icon = 'file-alt';
        break;
    case 'submitted':
        $status_badge = 'badge-info';
        $status_icon = 'paper-plane';
        break;
    case 'approved':
        $status_badge = 'badge-success';
        $status_icon = 'check';
        break;
    case 'partially_received':
        $status_badge = 'badge-warning';
        $status_icon = 'truck-loading';
        break;
    case 'received':
        $status_badge = 'badge-primary';
        $status_icon = 'check-double';
        break;
    case 'cancelled':
        $status_badge = 'badge-danger';
        $status_icon = 'times';
        break;
    default:
        $status_badge = 'badge-light';
        $status_icon = 'question';
}

// Check if overdue
$is_overdue = false;
if ($po['expected_delivery_date'] && $po['status'] != 'received' && $po['status'] != 'cancelled') {
    $today = new DateTime();
    $delivery_date = new DateTime($po['expected_delivery_date']);
    $is_overdue = $delivery_date < $today;
}
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h3 class="card-title mt-2 mb-0 text-white">
                    <i class="fas fa-fw fa-clipboard-check mr-2"></i>Purchase Order: <?php echo htmlspecialchars($po['po_number']); ?>
                </h3>
                <small class="text-white-50">Supplier: <?php echo htmlspecialchars($po['supplier_name']); ?></small>
            </div>
            <div class="card-tools">
                <a href="inventory_purchase_orders.php" class="btn btn-light mr-2">
                    <i class="fas fa-arrow-left mr-2"></i>Back to POs
                </a>
                <?php if ($po['status'] == 'draft'): ?>
                    <a href="inventory_purchase_order_edit.php?id=<?php echo $purchase_order_id; ?>" class="btn btn-warning">
                        <i class="fas fa-edit mr-2"></i>Edit PO
                    </a>
                <?php endif; ?>
                <?php if (in_array($po['status'], ['approved', 'partially_received']) && hasPermission('inventory_grn_create')): ?>
                    <a href="inventory_grn_create.php?po_id=<?php echo $purchase_order_id; ?>" class="btn btn-success">
                        <i class="fas fa-receipt mr-2"></i>Create GRN
                    </a>
                <?php endif; ?>
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

        <!-- PO Summary -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="info-box bg-gradient-<?php echo str_replace(['badge-', 'secondary', 'info', 'success', 'warning', 'primary', 'danger'], 
                    ['', 'secondary', 'info', 'success', 'warning', 'primary', 'danger'], $status_badge); ?>">
                    <span class="info-box-icon"><i class="fas fa-shopping-cart"></i></span>
                    <div class="info-box-content">
                        <div class="row">
                            <div class="col-md-3">
                                <span class="info-box-text">PO Number</span>
                                <span class="info-box-number"><?php echo htmlspecialchars($po['po_number']); ?></span>
                            </div>
                            <div class="col-md-3">
                                <span class="info-box-text">Status</span>
                                <span class="info-box-number">
                                    <span class="badge <?php echo $status_badge; ?>">
                                        <i class="fas fa-<?php echo $status_icon; ?> mr-1"></i>
                                        <?php echo ucfirst(str_replace('_', ' ', $po['status'])); ?>
                                    </span>
                                </span>
                            </div>
                            <div class="col-md-3">
                                <span class="info-box-text">Total Ordered</span>
                                <span class="info-box-number">
                                    <?php echo number_format($total_ordered, 3); ?> units
                                </span>
                            </div>
                            <div class="col-md-3">
                                <span class="info-box-text">Estimated Amount</span>
                                <span class="info-box-number">$<?php echo number_format($total_estimated_amount, 2); ?></span>
                            </div>
                        </div>
                        <div class="row mt-2">
                            <div class="col-md-3">
                                <span class="info-box-text">Received</span>
                                <span class="info-box-number">
                                    <?php echo number_format($total_received, 3); ?> units
                                    <small>(<?php echo number_format($received_percentage, 1); ?>%)</small>
                                </span>
                            </div>
                            <div class="col-md-3">
                                <span class="info-box-text">Received Value</span>
                                <span class="info-box-number">$<?php echo number_format($total_received_amount, 2); ?></span>
                            </div>
                            <div class="col-md-3">
                                <span class="info-box-text">GRNs</span>
                                <span class="info-box-number"><?php echo count($grns); ?> GRN(s)</span>
                            </div>
                            <div class="col-md-3">
                                <span class="info-box-text">Items</span>
                                <span class="info-box-number"><?php echo count($items); ?> items</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Left Column: PO Details -->
            <div class="col-md-6">
                <!-- PO Information -->
                <div class="card card-primary">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-info-circle mr-2"></i>Purchase Order Information</h3>
                    </div>
                    <div class="card-body">
                        <dl class="row">
                            <dt class="col-sm-4">PO Number:</dt>
                            <dd class="col-sm-8"><?php echo htmlspecialchars($po['po_number']); ?></dd>
                            
                            <dt class="col-sm-4">PO Date:</dt>
                            <dd class="col-sm-8"><?php echo date('F j, Y', strtotime($po['po_date'])); ?></dd>
                            
                            <dt class="col-sm-4">Expected Delivery:</dt>
                            <dd class="col-sm-8 <?php echo $is_overdue ? 'text-danger font-weight-bold' : ''; ?>">
                                <?php echo $po['expected_delivery_date'] ? date('F j, Y', strtotime($po['expected_delivery_date'])) : 'Not specified'; ?>
                                <?php if ($is_overdue): ?>
                                    <span class="badge badge-danger ml-2">Overdue</span>
                                <?php endif; ?>
                            </dd>
                            
                            <dt class="col-sm-4">Supplier:</dt>
                            <dd class="col-sm-8">
                                <strong><?php echo htmlspecialchars($po['supplier_name']); ?></strong><br>
                                <small class="text-muted">
                                    <?php if ($po['supplier_contact']): ?>
                                        Contact: <?php echo htmlspecialchars($po['supplier_contact']); ?><br>
                                    <?php endif; ?>
                                    <?php if ($po['supplier_phone']): ?>
                                        Phone: <?php echo htmlspecialchars($po['supplier_phone']); ?><br>
                                    <?php endif; ?>
                                    <?php if ($po['supplier_email']): ?>
                                        Email: <?php echo htmlspecialchars($po['supplier_email']); ?>
                                    <?php endif; ?>
                                </small>
                            </dd>
                            
                            <dt class="col-sm-4">Delivery Location:</dt>
                            <dd class="col-sm-8">
                                <strong><?php echo htmlspecialchars($po['location_name']); ?></strong><br>
                                <small class="text-muted"><?php echo htmlspecialchars($po['location_type']); ?></small>
                            </dd>
                            
                            <dt class="col-sm-4">Requested By:</dt>
                            <dd class="col-sm-8"><?php echo htmlspecialchars($po['requested_by_name']); ?></dd>
                            
                            <?php if ($po['approved_by_name']): ?>
                                <dt class="col-sm-4">Approved By:</dt>
                                <dd class="col-sm-8">
                                    <?php echo htmlspecialchars($po['approved_by_name']); ?><br>
                                    <small class="text-muted">
                                        <?php if ($po['approved_at']): ?>
                                            <?php echo date('F j, Y, g:i a', strtotime($po['approved_at'])); ?>
                                        <?php endif; ?>
                                    </small>
                                </dd>
                            <?php endif; ?>
                            
                            <dt class="col-sm-4">Notes:</dt>
                            <dd class="col-sm-8"><?php echo $po['notes'] ? nl2br(htmlspecialchars($po['notes'])) : '<span class="text-muted">No notes</span>'; ?></dd>
                            
                            <dt class="col-sm-4">Created:</dt>
                            <dd class="col-sm-8">
                                <?php echo date('F j, Y, g:i a', strtotime($po['created_at'])); ?><br>
                                <small class="text-muted">By: <?php echo htmlspecialchars($po['created_by_name'] ?? 'System'); ?></small>
                            </dd>
                            
                            <dt class="col-sm-4">Last Updated:</dt>
                            <dd class="col-sm-8">
                                <?php echo date('F j, Y, g:i a', strtotime($po['updated_at'])); ?><br>
                                <small class="text-muted">By: <?php echo htmlspecialchars($po['updated_by_name'] ?? 'System'); ?></small>
                            </dd>
                        </dl>
                    </div>
                </div>

                <!-- Receipt Progress -->
                <div class="card card-warning mt-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-truck-loading mr-2"></i>Receipt Progress</h3>
                    </div>
                    <div class="card-body">
                        <div class="text-center mb-3">
                            <div class="h2 <?php echo $received_percentage == 100 ? 'text-success' : ($received_percentage > 0 ? 'text-warning' : 'text-secondary'); ?>">
                                <?php echo number_format($received_percentage, 1); ?>%
                            </div>
                            <div class="progress" style="height: 25px;">
                                <div class="progress-bar <?php echo $received_percentage == 100 ? 'bg-success' : ($received_percentage > 0 ? 'bg-warning' : 'bg-secondary'); ?>" 
                                     role="progressbar" 
                                     style="width: <?php echo min($received_percentage, 100); ?>%;" 
                                     aria-valuenow="<?php echo $received_percentage; ?>" 
                                     aria-valuemin="0" 
                                     aria-valuemax="100">
                                    <?php echo number_format($received_percentage, 1); ?>%
                                </div>
                            </div>
                        </div>
                        <div class="row text-center">
                            <div class="col-md-6">
                                <div class="small text-muted">Ordered</div>
                                <div class="h4 font-weight-bold"><?php echo number_format($total_ordered, 3); ?></div>
                                <small class="text-muted">units</small>
                            </div>
                            <div class="col-md-6">
                                <div class="small text-muted">Received</div>
                                <div class="h4 font-weight-bold text-success"><?php echo number_format($total_received, 3); ?></div>
                                <small class="text-muted">units</small>
                            </div>
                        </div>
                        <div class="row text-center mt-3">
                            <div class="col-md-6">
                                <div class="small text-muted">Estimated Value</div>
                                <div class="h5 font-weight-bold">$<?php echo number_format($total_estimated_amount, 2); ?></div>
                            </div>
                            <div class="col-md-6">
                                <div class="small text-muted">Received Value</div>
                                <div class="h5 font-weight-bold text-success">$<?php echo number_format($total_received_amount, 2); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Items and GRNs -->
            <div class="col-md-6">
                <!-- PO Items -->
                <div class="card card-success">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-cubes mr-2"></i>Order Items (<?php echo count($items); ?>)</h3>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($items)): ?>
                            <div class="text-center py-4">
                                <i class="fas fa-box-open fa-2x text-muted mb-3"></i>
                                <h5>No Items Found</h5>
                                <p class="text-muted">This purchase order has no items.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Item</th>
                                            <th class="text-center">Unit</th>
                                            <th class="text-right">Ordered</th>
                                            <th class="text-right">Received</th>
                                            <th class="text-right">Unit Cost</th>
                                            <th class="text-right">Total</th>
                                            <th class="text-center">Progress</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($items as $item): 
                                            $item_received_percentage = $item['quantity_ordered'] > 0 ? ($item['total_received_so_far'] / $item['quantity_ordered']) * 100 : 0;
                                        ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($item['item_name']); ?></strong><br>
                                                    <small class="text-muted">
                                                        <?php echo htmlspecialchars($item['item_code']); ?>
                                                        <?php if ($item['category_name']): ?>
                                                            • <?php echo htmlspecialchars($item['category_name']); ?>
                                                        <?php endif; ?>
                                                        <?php if ($item['requires_batch'] == 1): ?>
                                                            <span class="badge badge-info ml-1">Batch</span>
                                                        <?php endif; ?>
                                                    </small>
                                                </td>
                                                <td class="text-center"><?php echo htmlspecialchars($item['unit_of_measure']); ?></td>
                                                <td class="text-right"><?php echo number_format($item['quantity_ordered'], 3); ?></td>
                                                <td class="text-right">
                                                    <span class="<?php echo $item['total_received_so_far'] == $item['quantity_ordered'] ? 'text-success' : 
                                                                    ($item['total_received_so_far'] > 0 ? 'text-warning' : 'text-muted'); ?>">
                                                        <?php echo number_format($item['total_received_so_far'], 3); ?>
                                                    </span>
                                                </td>
                                                <td class="text-right">$<?php echo number_format($item['unit_cost'], 4); ?></td>
                                                <td class="text-right">
                                                    <span class="font-weight-bold">$<?php echo number_format($item['estimated_total'], 2); ?></span>
                                                </td>
                                                <td class="text-center" width="80">
                                                    <div class="progress" style="height: 15px;">
                                                        <div class="progress-bar <?php echo $item_received_percentage == 100 ? 'bg-success' : 
                                                                                    ($item_received_percentage > 0 ? 'bg-warning' : 'bg-secondary'); ?>" 
                                                             style="width: <?php echo min($item_received_percentage, 100); ?>%;">
                                                        </div>
                                                    </div>
                                                    <small class="text-muted"><?php echo number_format($item_received_percentage, 0); ?>%</small>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="bg-light">
                                        <tr>
                                            <td colspan="2" class="text-right"><strong>Totals:</strong></td>
                                            <td class="text-right"><strong><?php echo number_format($total_ordered, 3); ?></strong></td>
                                            <td class="text-right">
                                                <strong class="<?php echo $total_received == $total_ordered ? 'text-success' : 
                                                                ($total_received > 0 ? 'text-warning' : 'text-muted'); ?>">
                                                    <?php echo number_format($total_received, 3); ?>
                                                </strong>
                                            </td>
                                            <td class="text-right">-</td>
                                            <td class="text-right"><strong>$<?php echo number_format($total_estimated_amount, 2); ?></strong></td>
                                            <td class="text-center">
                                                <strong><?php echo number_format($received_percentage, 1); ?>%</strong>
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Related GRNs -->
                <div class="card card-info mt-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-receipt mr-2"></i>Goods Receipt Notes (<?php echo count($grns); ?>)</h3>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($grns)): ?>
                            <div class="text-center py-4">
                                <i class="fas fa-receipt fa-2x text-muted mb-3"></i>
                                <h5>No GRNs Found</h5>
                                <p class="text-muted">No goods receipt notes have been created for this purchase order yet.</p>
                                <?php if (in_array($po['status'], ['approved', 'partially_received']) && hasPermission('inventory_grn_create')): ?>
                                    <a href="inventory_grn_create.php?po_id=<?php echo $purchase_order_id; ?>" class="btn btn-success">
                                        <i class="fas fa-plus mr-1"></i>Create First GRN
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>GRN Number</th>
                                            <th class="text-center">Date</th>
                                            <th class="text-center">Received By</th>
                                            <th class="text-center">Verification</th>
                                            <th class="text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($grns as $grn): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($grn['grn_number']); ?></strong>
                                                    <?php if ($grn['invoice_number']): ?>
                                                        <br><small class="text-muted">Invoice: <?php echo htmlspecialchars($grn['invoice_number']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center"><?php echo date('M j, Y', strtotime($grn['grn_date'])); ?></td>
                                                <td class="text-center"><?php echo htmlspecialchars($grn['received_by_name']); ?></td>
                                                <td class="text-center">
                                                    <?php if ($grn['verified_by']): ?>
                                                        <span class="badge badge-success">
                                                            <i class="fas fa-check mr-1"></i>Verified
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge badge-warning">
                                                            <i class="fas fa-clock mr-1"></i>Pending
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <div class="btn-group">
                                                        <a href="inventory_grn_view.php?id=<?php echo $grn['grn_id']; ?>" 
                                                           class="btn btn-xs btn-info" title="View GRN">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        <a href="inventory_grn_print.php?id=<?php echo $grn['grn_id']; ?>" 
                                                           class="btn btn-xs btn-secondary" title="Print" target="_blank">
                                                            <i class="fas fa-print"></i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="card-footer text-center">
                                <a href="inventory_grn_create.php?po_id=<?php echo $purchase_order_id; ?>" class="btn btn-success btn-sm">
                                    <i class="fas fa-plus mr-1"></i>Create Another GRN
                                </a>
                                <a href="inventory_grns.php?po=<?php echo $purchase_order_id; ?>" class="btn btn-outline-info btn-sm">
                                    <i class="fas fa-list mr-1"></i>View All GRNs
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="row mt-4">
            <div class="col-md-12">
                <div class="card card-secondary">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-bolt mr-2"></i>Quick Actions</h3>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3">
                                <a href="inventory_purchase_order_print.php?id=<?php echo $purchase_order_id; ?>" 
                                   class="btn btn-secondary btn-block mb-2" target="_blank">
                                    <i class="fas fa-print mr-2"></i>Print PO
                                </a>
                            </div>
                            <div class="col-md-3">
                                <a href="inventory_grn_create.php?po_id=<?php echo $purchase_order_id; ?>" 
                                   class="btn btn-success btn-block mb-2">
                                    <i class="fas fa-receipt mr-2"></i>Create GRN
                                </a>
                            </div>
                            <div class="col-md-3">
                                <a href="inventory_purchase_orders.php" class="btn btn-info btn-block mb-2">
                                    <i class="fas fa-arrow-left mr-2"></i>Back to POs
                                </a>
                            </div>
                            <div class="col-md-3">
                                <button type="button" class="btn btn-outline-danger btn-block mb-2" onclick="printPODetails()">
                                    <i class="fas fa-download mr-2"></i>Export Details
                                </button>
                            </div>
                        </div>
                        <?php if ($po['status'] == 'draft'): ?>
                        <div class="row">
                            <div class="col-md-4">
                                <a href="inventory_purchase_order_edit.php?id=<?php echo $purchase_order_id; ?>" 
                                   class="btn btn-warning btn-block mb-2">
                                    <i class="fas fa-edit mr-2"></i>Edit PO
                                </a>
                            </div>
                            <div class="col-md-4">
                                <?php if (hasPermission('inventory_approve')): ?>
                                    <a href="inventory_purchase_order_action.php?action=submit&id=<?php echo $purchase_order_id; ?>" 
                                       class="btn btn-primary btn-block mb-2">
                                        <i class="fas fa-paper-plane mr-2"></i>Submit for Approval
                                    </a>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-4">
                                <?php if (hasPermission('inventory_edit')): ?>
                                    <button type="button" class="btn btn-danger btn-block mb-2" onclick="cancelPO()">
                                        <i class="fas fa-times mr-2"></i>Cancel PO
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php elseif ($po['status'] == 'submitted' && hasPermission('inventory_approve')): ?>
                        <div class="row">
                            <div class="col-md-6">
                                <a href="inventory_purchase_order_action.php?action=approve&id=<?php echo $purchase_order_id; ?>" 
                                   class="btn btn-success btn-block mb-2">
                                    <i class="fas fa-check mr-2"></i>Approve PO
                                </a>
                            </div>
                            <div class="col-md-6">
                                <a href="inventory_purchase_order_action.php?action=reject&id=<?php echo $purchase_order_id; ?>" 
                                   class="btn btn-danger btn-block mb-2">
                                    <i class="fas fa-times mr-2"></i>Reject PO
                                </a>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Highlight overdue dates
    <?php if ($is_overdue): ?>
        $('.text-danger').closest('tr').addClass('bg-danger-light');
        setInterval(function() {
            $('.badge-danger').fadeOut(500).fadeIn(500);
        }, 2000);
    <?php endif; ?>
});

function printPODetails() {
    const printContent = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>Purchase Order: <?php echo htmlspecialchars($po['po_number']); ?></title>
            <style>
                body { font-family: Arial, sans-serif; }
                .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #333; padding-bottom: 10px; }
                .section { margin-bottom: 15px; }
                .section-title { font-weight: bold; background-color: #f0f0f0; padding: 5px; }
                .row { display: flex; margin-bottom: 5px; }
                .col-label { width: 200px; font-weight: bold; }
                .col-value { flex: 1; }
                table { width: 100%; border-collapse: collapse; margin: 10px 0; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                th { background-color: #f2f2f2; }
                .footer { margin-top: 30px; text-align: center; font-size: 12px; color: #666; }
                .text-right { text-align: right; }
                .text-center { text-align: center; }
            </style>
        </head>
        <body>
            <div class="header">
                <h2>Purchase Order Details Report</h2>
                <h3>PO Number: <?php echo htmlspecialchars($po['po_number']); ?></h3>
                <p>Generated on: ${new Date().toLocaleString()}</p>
            </div>
            
            <div class="section">
                <div class="section-title">Purchase Order Information</div>
                <div class="row"><div class="col-label">PO Number:</div><div class="col-value"><?php echo htmlspecialchars($po['po_number']); ?></div></div>
                <div class="row"><div class="col-label">PO Date:</div><div class="col-value"><?php echo date('F j, Y', strtotime($po['po_date'])); ?></div></div>
                <div class="row"><div class="col-label">Expected Delivery:</div><div class="col-value"><?php echo $po['expected_delivery_date'] ? date('F j, Y', strtotime($po['expected_delivery_date'])) : 'Not specified'; ?></div></div>
                <div class="row"><div class="col-label">Status:</div><div class="col-value"><?php echo ucfirst(str_replace('_', ' ', $po['status'])); ?></div></div>
                <div class="row"><div class="col-label">Supplier:</div><div class="col-value"><?php echo htmlspecialchars($po['supplier_name']); ?></div></div>
                <div class="row"><div class="col-label">Delivery Location:</div><div class="col-value"><?php echo htmlspecialchars($po['location_name']); ?> (<?php echo htmlspecialchars($po['location_type']); ?>)</div></div>
                <div class="row"><div class="col-label">Requested By:</div><div class="col-value"><?php echo htmlspecialchars($po['requested_by_name']); ?></div></div>
                <div class="row"><div class="col-label">Approved By:</div><div class="col-value"><?php echo htmlspecialchars($po['approved_by_name'] ?? 'Not approved'); ?></div></div>
                <div class="row"><div class="col-label">Notes:</div><div class="col-value"><?php echo nl2br(htmlspecialchars($po['notes'] ?? 'None')); ?></div></div>
            </div>
            
            <div class="section">
                <div class="section-title">Order Summary</div>
                <div class="row"><div class="col-label">Total Items:</div><div class="col-value"><?php echo count($items); ?></div></div>
                <div class="row"><div class="col-label">Total Ordered:</div><div class="col-value"><?php echo number_format($total_ordered, 3); ?> units</div></div>
                <div class="row"><div class="col-label">Total Received:</div><div class="col-value"><?php echo number_format($total_received, 3); ?> units (<?php echo number_format($received_percentage, 1); ?>%)</div></div>
                <div class="row"><div class="col-label">Estimated Amount:</div><div class="col-value">$<?php echo number_format($total_estimated_amount, 2); ?></div></div>
                <div class="row"><div class="col-label">Received Amount:</div><div class="col-value">$<?php echo number_format($total_received_amount, 2); ?></div></div>
                <div class="row"><div class="col-label">Related GRNs:</div><div class="col-value"><?php echo count($grns); ?> GRN(s)</div></div>
            </div>
            
            <?php if (!empty($items)): ?>
            <div class="section">
                <div class="section-title">Order Items</div>
                <table>
                    <thead>
                        <tr>
                            <th>Item Name</th>
                            <th>Code</th>
                            <th class="text-right">Ordered</th>
                            <th class="text-right">Received</th>
                            <th class="text-right">Unit Cost</th>
                            <th class="text-right">Total</th>
                            <th class="text-center">Progress</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): 
                            $item_received_percentage = $item['quantity_ordered'] > 0 ? ($item['total_received_so_far'] / $item['quantity_ordered']) * 100 : 0;
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                            <td><?php echo htmlspecialchars($item['item_code']); ?></td>
                            <td class="text-right"><?php echo number_format($item['quantity_ordered'], 3); ?></td>
                            <td class="text-right"><?php echo number_format($item['total_received_so_far'], 3); ?></td>
                            <td class="text-right">$<?php echo number_format($item['unit_cost'], 4); ?></td>
                            <td class="text-right">$<?php echo number_format($item['estimated_total'], 2); ?></td>
                            <td class="text-center"><?php echo number_format($item_received_percentage, 0); ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                        <tr style="font-weight: bold; background-color: #f0f0f0;">
                            <td colspan="2" class="text-right">Total:</td>
                            <td class="text-right"><?php echo number_format($total_ordered, 3); ?></td>
                            <td class="text-right"><?php echo number_format($total_received, 3); ?></td>
                            <td class="text-right">-</td>
                            <td class="text-right">$<?php echo number_format($total_estimated_amount, 2); ?></td>
                            <td class="text-center"><?php echo number_format($received_percentage, 1); ?>%</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($grns)): ?>
            <div class="section">
                <div class="section-title">Related Goods Receipt Notes</div>
                <table>
                    <thead>
                        <tr>
                            <th>GRN Number</th>
                            <th>Date</th>
                            <th>Invoice</th>
                            <th>Received By</th>
                            <th>Verification Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($grns as $grn): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($grn['grn_number']); ?></td>
                            <td><?php echo date('M j, Y', strtotime($grn['grn_date'])); ?></td>
                            <td><?php echo htmlspecialchars($grn['invoice_number'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($grn['received_by_name']); ?></td>
                            <td><?php echo $grn['verified_by'] ? 'Verified' : 'Pending'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
            <div class="footer">
                <p>Report generated by: <?php echo $_SESSION['user_name'] ?? 'System'; ?></p>
                <p>© <?php echo date('Y'); ?> Clinic Inventory System</p>
            </div>
        </body>
        </html>
    `;
    
    const printWindow = window.open('', '_blank');
    printWindow.document.write(printContent);
    printWindow.document.close();
    printWindow.focus();
    setTimeout(() => {
        printWindow.print();
        printWindow.close();
    }, 500);
}

function cancelPO() {
    if (confirm('Are you sure you want to cancel this purchase order? This action cannot be undone.')) {
        window.location.href = 'inventory_purchase_order_action.php?action=cancel&id=<?php echo $purchase_order_id; ?>';
    }
}
</script>

<style>
.info-box {
    box-shadow: 0 0 1px rgba(0,0,0,.125), 0 1px 3px rgba(0,0,0,.2);
    border-radius: .25rem;
    background: #fff;
    display: flex;
    margin-bottom: 1rem;
    min-height: 80px;
    padding: .5rem;
    position: relative;
}

.info-box .info-box-icon {
    border-radius: .25rem;
    display: flex;
    font-size: 1.875rem;
    justify-content: center;
    text-align: center;
    width: 70px;
    align-items: center;
}

.info-box .info-box-content {
    display: flex;
    flex-direction: column;
    justify-content: center;
    line-height: 1.8;
    flex: 1;
    padding: 0 10px;
}

.info-box .info-box-text {
    display: block;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    text-transform: uppercase;
    font-weight: 700;
    font-size: .875rem;
}

.info-box .info-box-number {
    font-weight: 700;
    font-size: 1.5rem;
}

.bg-danger-light {
    background-color: rgba(220, 53, 69, 0.1) !important;
}

.table-sm td {
    padding: 0.5rem;
}

.table-sm th {
    padding: 0.5rem;
}

.progress {
    border-radius: 3px;
}

.btn-group .btn-xs {
    padding: 0.15rem 0.3rem;
    font-size: 0.75rem;
    line-height: 1.3;
}

.badge-warning {
    animation: pulse 3s infinite;
}

@keyframes pulse {
    0% { opacity: 1; }
    50% { opacity: 0.7; }
    100% { opacity: 1; }
}
</style>

<?php 
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
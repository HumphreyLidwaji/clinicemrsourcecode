<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Check if GRN ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "GRN ID is required.";
    header("Location: grn_dashboard.php");
    exit;
}

$grn_id = intval($_GET['id']);

// Fetch GRN details
$grn_sql = "SELECT grn.*, po.order_number, po.order_date, po.expected_delivery_date,
                   po.order_status, po.total_amount as order_total,
                   s.supplier_name, s.supplier_contact, s.supplier_phone, 
                   s.supplier_email, s.supplier_address,
                   u.user_name as created_by_name
            FROM goods_received_notes grn
            LEFT JOIN purchase_orders po ON grn.order_id = po.order_id
            LEFT JOIN suppliers s ON po.supplier_id = s.supplier_id
            LEFT JOIN users u ON grn.created_by = u.user_id
            WHERE grn.grn_id = ?";
$grn_stmt = $mysqli->prepare($grn_sql);
$grn_stmt->bind_param("i", $grn_id);
$grn_stmt->execute();
$grn_result = $grn_stmt->get_result();

if ($grn_result->num_rows === 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "GRN not found.";
    header("Location: grn_dashboard.php");
    exit;
}

$grn = $grn_result->fetch_assoc();
$grn_stmt->close();

// Fetch GRN items
$items_sql = "SELECT gi.*, ii.item_name, ii.item_code, ii.item_unit_measure,
                     poi.quantity_ordered, poi.unit_cost,
                     (gi.quantity_received * poi.unit_cost) as line_total
              FROM grn_items gi
              LEFT JOIN inventory_items ii ON gi.item_id = ii.item_id
              LEFT JOIN purchase_order_items poi ON gi.item_id = poi.item_id AND poi.order_id = ?
              WHERE gi.grn_id = ?
              ORDER BY ii.item_name";
$items_stmt = $mysqli->prepare($items_sql);
$items_stmt->bind_param("ii", $grn['order_id'], $grn_id);
$items_stmt->execute();
$items_result = $items_stmt->get_result();
$grn_items = [];
$total_value = 0;
$total_quantity = 0;

while ($item = $items_result->fetch_assoc()) {
    $grn_items[] = $item;
    $total_value += $item['line_total'];
    $total_quantity += $item['quantity_received'];
}
$items_stmt->close();

// Check if receipt was on time
$is_ontime = true;
if ($grn['expected_delivery_date'] && $grn['receipt_date']) {
    $delivery_date = new DateTime($grn['expected_delivery_date']);
    $receipt_date = new DateTime($grn['receipt_date']);
    if ($receipt_date > $delivery_date) {
        $is_ontime = false;
    }
}

// Get company details for display
$company_sql = "SELECT company_name, company_address, company_city, company_state, 
                       company_zip, company_phone, company_email 
                FROM companies 
                LIMIT 1";
$company_result = $mysqli->query($company_sql);
$company = $company_result->fetch_assoc();
?>

<div class="card">
    <div class="card-header bg-success py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-clipboard-check mr-2"></i>Goods Received Note: <?php echo htmlspecialchars($grn['grn_number']); ?>
            </h3>
            <div class="card-tools">
                <a href="grn_dashboard.php" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to GRNs
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

        <div class="row">
            <div class="col-md-8">
                <!-- GRN Information -->
                <div class="card card-primary">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h3 class="card-title"><i class="fas fa-info-circle mr-2"></i>GRN Information</h3>
                        <span class="badge badge-success badge-lg">
                            <i class="fas fa-clipboard-check mr-1"></i>GRN
                        </span>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td class="font-weight-bold" width="40%">GRN Number:</td>
                                        <td><?php echo htmlspecialchars($grn['grn_number']); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="font-weight-bold">Purchase Order:</td>
                                        <td>
                                            <a href="purchase_order_view.php?id=<?php echo $grn['order_id']; ?>" class="font-weight-bold">
                                                <?php echo htmlspecialchars($grn['order_number']); ?>
                                            </a>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="font-weight-bold">Supplier:</td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($grn['supplier_name']); ?></strong>
                                            <?php if ($grn['supplier_contact']): ?>
                                                <br><small class="text-muted">Contact: <?php echo htmlspecialchars($grn['supplier_contact']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="font-weight-bold">Receipt Date:</td>
                                        <td>
                                            <span class="font-weight-bold <?php echo $is_ontime ? 'text-success' : 'text-danger'; ?>">
                                                <?php echo date('F j, Y', strtotime($grn['receipt_date'])); ?>
                                            </span>
                                            <?php if (!$is_ontime): ?>
                                                <br><small class="text-danger"><i class="fas fa-exclamation-triangle mr-1"></i>Delayed delivery</small>
                                            <?php else: ?>
                                                <br><small class="text-success"><i class="fas fa-check mr-1"></i>On time</small>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td class="font-weight-bold" width="40%">Received By:</td>
                                        <td><?php echo htmlspecialchars($grn['received_by']); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="font-weight-bold">Order Date:</td>
                                        <td><?php echo date('F j, Y', strtotime($grn['order_date'])); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="font-weight-bold">Expected Delivery:</td>
                                        <td>
                                            <?php if ($grn['expected_delivery_date']): ?>
                                                <?php echo date('F j, Y', strtotime($grn['expected_delivery_date'])); ?>
                                            <?php else: ?>
                                                <span class="text-muted">Not set</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="font-weight-bold">Created By:</td>
                                        <td><?php echo htmlspecialchars($grn['created_by_name']); ?></td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <?php if (!empty($grn['notes'])): ?>
                        <div class="row mt-3">
                            <div class="col-12">
                                <label class="font-weight-bold">GRN Notes:</label>
                                <div class="border rounded p-3 bg-light">
                                    <?php echo nl2br(htmlspecialchars($grn['notes'])); ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Received Items -->
                <div class="card card-warning mt-4">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-list-ol mr-2"></i>Received Items</h3>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Item</th>
                                        <th class="text-center">Ordered Qty</th>
                                        <th class="text-center">Received Qty</th>
                                        <th class="text-center">Balance</th>
                                        <th class="text-right">Unit Cost</th>
                                        <th class="text-right">Line Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($grn_items) > 0): ?>
                                        <?php foreach ($grn_items as $item): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($item['item_name']); ?></strong>
                                                    <?php if (!empty($item['item_code'])): ?>
                                                        <br><small class="text-muted">Code: <?php echo htmlspecialchars($item['item_code']); ?></small>
                                                    <?php endif; ?>
                                                    <?php if (!empty($item['item_unit_measure'])): ?>
                                                        <br><small class="text-muted">Unit: <?php echo htmlspecialchars($item['item_unit_measure']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center"><?php echo number_format($item['quantity_ordered']); ?></td>
                                                <td class="text-center">
                                                    <span class="badge badge-success"><?php echo number_format($item['quantity_received']); ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <?php 
                                                    $balance = $item['quantity_ordered'] - $item['quantity_received'];
                                                    $balance_class = $balance > 0 ? 'warning' : 'success';
                                                    ?>
                                                    <span class="badge badge-<?php echo $balance_class; ?>">
                                                        <?php echo number_format($balance); ?>
                                                    </span>
                                                </td>
                                                <td class="text-right">$<?php echo number_format($item['unit_cost'], 2); ?></td>
                                                <td class="text-right">
                                                    <strong>$<?php echo number_format($item['line_total'], 2); ?></strong>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted py-4">
                                                <i class="fas fa-cubes fa-2x mb-2"></i>
                                                <p>No items found in this GRN.</p>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                                <tfoot class="bg-light">
                                    <tr>
                                        <td colspan="4" class="text-right font-weight-bold">Total Received:</td>
                                        <td class="text-center font-weight-bold"><?php echo number_format($total_quantity); ?> units</td>
                                        <td class="text-right font-weight-bold text-success">$<?php echo number_format($total_value, 2); ?></td>
                                    </tr>
                                    <?php if ($grn['order_total']): ?>
                                        <tr>
                                            <td colspan="4" class="text-right font-weight-bold">Order Total:</td>
                                            <td class="text-center font-weight-bold"></td>
                                            <td class="text-right font-weight-bold">$<?php echo number_format($grn['order_total'], 2); ?></td>
                                        </tr>
                                        <tr>
                                            <td colspan="4" class="text-right font-weight-bold">Remaining Balance:</td>
                                            <td class="text-center font-weight-bold"></td>
                                            <td class="text-right font-weight-bold text-info">
                                                $<?php echo number_format($grn['order_total'] - $total_value, 2); ?>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Inventory Impact -->
                <div class="card card-info mt-4">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-warehouse mr-2"></i>Inventory Impact</h3>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Item</th>
                                        <th class="text-center">Received Qty</th>
                                        <th class="text-center">Current Stock</th>
                                        <th class="text-center">Stock After Receipt</th>
                                        <th class="text-center">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($grn_items as $item): 
                                        // Get current stock for this item
                                        $stock_sql = "SELECT item_quantity, item_status FROM inventory_items WHERE item_id = ?";
                                        $stock_stmt = $mysqli->prepare($stock_sql);
                                        $stock_stmt->bind_param("i", $item['item_id']);
                                        $stock_stmt->execute();
                                        $stock_result = $stock_stmt->get_result();
                                        $current_stock = $stock_result->fetch_assoc();
                                        $stock_stmt->close();

                                        $stock_after = $current_stock['item_quantity'];
                                        $received_qty = $item['quantity_received'];
                                    ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                                            <td class="text-center">+<?php echo number_format($received_qty); ?></td>
                                            <td class="text-center"><?php echo number_format($current_stock['item_quantity'] - $received_qty); ?></td>
                                            <td class="text-center">
                                                <span class="badge badge-success"><?php echo number_format($current_stock['item_quantity']); ?></span>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge badge-<?php 
                                                    echo $current_stock['item_status'] == 'In Stock' ? 'success' : 
                                                         ($current_stock['item_status'] == 'Low Stock' ? 'warning' : 'danger'); 
                                                ?>">
                                                    <?php echo $current_stock['item_status']; ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="alert alert-info mt-3">
                            <i class="fas fa-info-circle mr-2"></i>
                            This GRN increased inventory stock levels as shown above. The "Current Stock" column shows the current quantity after this receipt.
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <!-- Quick Actions -->
                <div class="card card-success">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-bolt mr-2"></i>Quick Actions</h3>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="grn_print.php?id=<?php echo $grn_id; ?>" target="_blank" class="btn btn-info">
                                <i class="fas fa-print mr-2"></i>Print GRN
                            </a>
                            <a href="purchase_order_view.php?id=<?php echo $grn['order_id']; ?>" class="btn btn-primary">
                                <i class="fas fa-shopping-cart mr-2"></i>View Purchase Order
                            </a>
                            <a href="goods_received_note.php?order_id=<?php echo $grn['order_id']; ?>" class="btn btn-warning">
                                <i class="fas fa-plus mr-2"></i>Create Another GRN
                            </a>
                            <?php if ($session_user_role == 1 || $session_user_role == 3): ?>
                                <button type="button" class="btn btn-outline-danger" data-toggle="modal" data-target="#deleteModal">
                                    <i class="fas fa-trash mr-2"></i>Delete GRN
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Supplier Information -->
                <div class="card card-info mt-4">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-truck mr-2"></i>Supplier Information</h3>
                    </div>
                    <div class="card-body">
                        <div class="text-center mb-3">
                            <i class="fas fa-building fa-3x text-info mb-2"></i>
                            <h5><?php echo htmlspecialchars($grn['supplier_name']); ?></h5>
                        </div>
                        <hr>
                        <div class="small">
                            <?php if ($grn['supplier_contact']): ?>
                                <div class="mb-2">
                                    <i class="fas fa-user mr-2 text-muted"></i>
                                    <strong>Contact:</strong> <?php echo htmlspecialchars($grn['supplier_contact']); ?>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($grn['supplier_phone']): ?>
                                <div class="mb-2">
                                    <i class="fas fa-phone mr-2 text-muted"></i>
                                    <strong>Phone:</strong> 
                                    <a href="tel:<?php echo htmlspecialchars($grn['supplier_phone']); ?>">
                                        <?php echo htmlspecialchars($grn['supplier_phone']); ?>
                                    </a>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($grn['supplier_email']): ?>
                                <div class="mb-2">
                                    <i class="fas fa-envelope mr-2 text-muted"></i>
                                    <strong>Email:</strong> 
                                    <a href="mailto:<?php echo htmlspecialchars($grn['supplier_email']); ?>">
                                        <?php echo htmlspecialchars($grn['supplier_email']); ?>
                                    </a>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($grn['supplier_address']): ?>
                                <div class="mb-2">
                                    <i class="fas fa-map-marker-alt mr-2 text-muted"></i>
                                    <strong>Address:</strong><br>
                                    <?php echo nl2br(htmlspecialchars($grn['supplier_address'])); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- GRN Summary -->
                <div class="card card-warning mt-4">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-chart-bar mr-2"></i>GRN Summary</h3>
                    </div>
                    <div class="card-body">
                        <div class="text-center mb-3">
                            <i class="fas fa-clipboard-check fa-3x text-warning mb-2"></i>
                            <h5><?php echo htmlspecialchars($grn['grn_number']); ?></h5>
                            <div class="text-muted"><?php echo count($grn_items); ?> items</div>
                        </div>
                        <hr>
                        <div class="small">
                            <div class="d-flex justify-content-between mb-2">
                                <span>Total Items Received:</span>
                                <span class="font-weight-bold"><?php echo number_format($total_quantity); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Total Value:</span>
                                <span class="font-weight-bold text-success">$<?php echo number_format($total_value, 2); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Receipt Date:</span>
                                <span class="font-weight-bold"><?php echo date('M j, Y', strtotime($grn['receipt_date'])); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Delivery Status:</span>
                                <span class="font-weight-bold text-<?php echo $is_ontime ? 'success' : 'danger'; ?>">
                                    <?php echo $is_ontime ? 'On Time' : 'Delayed'; ?>
                                </span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span>Received By:</span>
                                <span class="font-weight-bold"><?php echo htmlspecialchars($grn['received_by']); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- GRN History -->
                <div class="card card-dark mt-4">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-history mr-2"></i>GRN History</h3>
                    </div>
                    <div class="card-body">
                        <div class="small">
                            <div class="mb-2">
                                <strong>Created:</strong><br>
                                <?php echo date('M j, Y g:i A', strtotime($grn['created_at'])); ?><br>
                                <em>by <?php echo htmlspecialchars($grn['created_by_name']); ?></em>
                            </div>
                            <?php if ($grn['updated_at']): ?>
                            <div class="mb-2">
                                <strong>Last Updated:</strong><br>
                                <?php echo date('M j, Y g:i A', strtotime($grn['updated_at'])); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-exclamation-triangle mr-2"></i>Delete GRN</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete this Goods Received Note?</p>
                <div class="alert alert-warning">
                    <i class="fas fa-info-circle mr-2"></i>
                    <strong>Warning:</strong> This action will:
                    <ul class="mt-2 mb-0">
                        <li>Reverse all inventory adjustments made by this GRN</li>
                        <li>Update the purchase order received quantities</li>
                        <li>Permanently delete this GRN record</li>
                    </ul>
                </div>
                <p class="mb-0"><strong>This action cannot be undone.</strong></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <a href="grn_delete.php?id=<?php echo $grn_id; ?>" class="btn btn-danger">Delete GRN</a>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize tooltips
    $('[data-toggle="tooltip"]').tooltip();

    // Keyboard shortcuts
    $(document).keydown(function(e) {
        // Escape to go back
        if (e.keyCode === 27) {
            window.location.href = 'grn_dashboard.php';
        }
        // Ctrl+P to print
        if (e.ctrlKey && e.keyCode === 80) {
            e.preventDefault();
            window.open('grn_print.php?id=<?php echo $grn_id; ?>', '_blank');
        }
    });
});

function confirmDelete() {
    return confirm('Are you sure you want to delete this GRN? This will reverse inventory adjustments and cannot be undone.');
}
</script>

<style>
.badge-lg {
    font-size: 1rem;
    padding: 0.5em 0.8em;
}

.table th {
    border-top: none;
    font-weight: 600;
}

.card-header.bg-success {
    background: linear-gradient(45deg, #28a745, #20c997) !important;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
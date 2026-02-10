<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Check if return ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Return ID is required.";
    header("Location: purchase_order_returns_dashboard.php");
    exit;
}

$return_id = intval($_GET['id']);

// Fetch return details
$return_sql = "SELECT ret.*, po.order_number, po.order_date, po.expected_delivery_date,
                      po.order_status, po.total_amount as order_total,
                      s.supplier_name, s.supplier_contact, s.supplier_phone, 
                      s.supplier_email, s.supplier_address,
                      u.user_name as created_by_name
               FROM purchase_order_returns ret
               LEFT JOIN purchase_orders po ON ret.order_id = po.order_id
               LEFT JOIN suppliers s ON po.supplier_id = s.supplier_id
               LEFT JOIN users u ON ret.created_by = u.user_id
               WHERE ret.return_id = ?";
$return_stmt = $mysqli->prepare($return_sql);
$return_stmt->bind_param("i", $return_id);
$return_stmt->execute();
$return_result = $return_stmt->get_result();

if ($return_result->num_rows === 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Return not found.";
    header("Location: purchase_order_returns_dashboard.php");
    exit;
}

$return = $return_result->fetch_assoc();
$return_stmt->close();

// Fetch return items
$items_sql = "SELECT ri.*, ii.item_name, ii.item_code, ii.item_unit_measure,
                     poi.quantity_ordered, poi.quantity_received,
                     (poi.quantity_received - IFNULL(poi.quantity_returned, 0)) as remaining_quantity
              FROM return_items ri
              LEFT JOIN inventory_items ii ON ri.item_id = ii.item_id
              LEFT JOIN purchase_order_items poi ON ri.item_id = poi.item_id AND poi.order_id = ?
              WHERE ri.return_id = ?
              ORDER BY ii.item_name";
$items_stmt = $mysqli->prepare($items_sql);
$items_stmt->bind_param("ii", $return['order_id'], $return_id);
$items_stmt->execute();
$items_result = $items_stmt->get_result();
$return_items = [];
$total_value = 0;
$total_quantity = 0;

while ($item = $items_result->fetch_assoc()) {
    $return_items[] = $item;
    $total_value += $item['total_cost'];
    $total_quantity += $item['quantity_returned'];
}
$items_stmt->close();

// Get current stock levels for returned items
$stock_levels = [];
foreach ($return_items as $item) {
    $stock_sql = "SELECT item_quantity, item_status FROM inventory_items WHERE item_id = ?";
    $stock_stmt = $mysqli->prepare($stock_sql);
    $stock_stmt->bind_param("i", $item['item_id']);
    $stock_stmt->execute();
    $stock_result = $stock_stmt->get_result();
    $stock_levels[$item['item_id']] = $stock_result->fetch_assoc();
    $stock_stmt->close();
}

// Return type colors and icons
$return_type_colors = [
    'refund' => 'danger',
    'replacement' => 'primary',
    'credit_note' => 'success',
    'exchange' => 'warning'
];

$return_type_icons = [
    'refund' => 'money-bill',
    'replacement' => 'sync',
    'credit_note' => 'file-invoice-dollar',
    'exchange' => 'retweet'
];

$return_type_color = $return_type_colors[$return['return_type']] ?? 'secondary';
$return_type_icon = $return_type_icons[$return['return_type']] ?? 'undo';

// Reason colors
$reason_colors = [
    'damaged' => 'danger',
    'defective' => 'danger',
    'wrong_item' => 'warning',
    'over_supplied' => 'info',
    'quality_issue' => 'warning',
    'expired' => 'dark',
    'other' => 'secondary'
];

$reason_color = $reason_colors[$return['return_reason']] ?? 'secondary';
?>

<div class="card">
    <div class="card-header bg-warning py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-undo mr-2"></i>Purchase Order Return: <?php echo htmlspecialchars($return['return_number']); ?>
            </h3>
            <div class="card-tools">
                <a href="purchase_order_returns_dashboard.php" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Returns
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
                <!-- Return Information -->
                <div class="card card-primary">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h3 class="card-title"><i class="fas fa-info-circle mr-2"></i>Return Information</h3>
                        <span class="badge badge-<?php echo $return_type_color; ?> badge-lg">
                            <i class="fas fa-<?php echo $return_type_icon; ?> mr-1"></i>
                            <?php echo ucfirst(str_replace('_', ' ', $return['return_type'])); ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td class="font-weight-bold" width="40%">Return Number:</td>
                                        <td><?php echo htmlspecialchars($return['return_number']); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="font-weight-bold">Purchase Order:</td>
                                        <td>
                                            <a href="purchase_order_view.php?id=<?php echo $return['order_id']; ?>" class="font-weight-bold">
                                                <?php echo htmlspecialchars($return['order_number']); ?>
                                            </a>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="font-weight-bold">Supplier:</td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($return['supplier_name']); ?></strong>
                                            <?php if ($return['supplier_contact']): ?>
                                                <br><small class="text-muted">Contact: <?php echo htmlspecialchars($return['supplier_contact']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="font-weight-bold">Return Date:</td>
                                        <td>
                                            <span class="font-weight-bold">
                                                <?php echo date('F j, Y', strtotime($return['return_date'])); ?>
                                            </span>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td class="font-weight-bold" width="40%">Return Type:</td>
                                        <td>
                                            <span class="badge badge-<?php echo $return_type_color; ?>">
                                                <i class="fas fa-<?php echo $return_type_icon; ?> mr-1"></i>
                                                <?php echo ucfirst(str_replace('_', ' ', $return['return_type'])); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="font-weight-bold">Return Reason:</td>
                                        <td>
                                            <span class="badge badge-<?php echo $reason_color; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $return['return_reason'])); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="font-weight-bold">Order Date:</td>
                                        <td><?php echo date('F j, Y', strtotime($return['order_date'])); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="font-weight-bold">Created By:</td>
                                        <td><?php echo htmlspecialchars($return['created_by_name']); ?></td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <?php if (!empty($return['notes'])): ?>
                        <div class="row mt-3">
                            <div class="col-12">
                                <label class="font-weight-bold">Return Notes:</label>
                                <div class="border rounded p-3 bg-light">
                                    <?php echo nl2br(htmlspecialchars($return['notes'])); ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Returned Items -->
                <div class="card card-warning mt-4">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-list-ol mr-2"></i>Returned Items</h3>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Item</th>
                                        <th class="text-center">Ordered Qty</th>
                                        <th class="text-center">Received Qty</th>
                                        <th class="text-center">Previously Returned</th>
                                        <th class="text-center">Returned Now</th>
                                        <th class="text-center">Remaining</th>
                                        <th class="text-right">Unit Cost</th>
                                        <th class="text-right">Line Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($return_items) > 0): ?>
                                        <?php foreach ($return_items as $item): 
                                            $remaining = $item['quantity_received'] - ($item['quantity_returned'] + ($item['remaining_quantity'] ?? 0));
                                        ?>
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
                                                <td class="text-center"><?php echo number_format($item['quantity_received']); ?></td>
                                                <td class="text-center">
                                                    <?php 
                                                    $previously_returned = $item['quantity_returned'] - $item['quantity_returned']; // This needs adjustment based on your data structure
                                                    ?>
                                                    <span class="badge badge-info"><?php echo number_format($previously_returned); ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge badge-danger"><?php echo number_format($item['quantity_returned']); ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge badge-<?php echo $remaining > 0 ? 'warning' : 'success'; ?>">
                                                        <?php echo number_format($remaining); ?>
                                                    </span>
                                                </td>
                                                <td class="text-right">$<?php echo number_format($item['unit_cost'], 2); ?></td>
                                                <td class="text-right">
                                                    <strong class="text-danger">$<?php echo number_format($item['total_cost'], 2); ?></strong>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="8" class="text-center text-muted py-4">
                                                <i class="fas fa-cubes fa-2x mb-2"></i>
                                                <p>No items found in this return.</p>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                                <tfoot class="bg-light">
                                    <tr>
                                        <td colspan="6" class="text-right font-weight-bold">Total Returned:</td>
                                        <td class="text-center font-weight-bold"><?php echo number_format($total_quantity); ?> units</td>
                                        <td class="text-right font-weight-bold text-danger">$<?php echo number_format($total_value, 2); ?></td>
                                    </tr>
                                    <?php if ($return['order_total']): ?>
                                        <tr>
                                            <td colspan="6" class="text-right font-weight-bold">Order Total:</td>
                                            <td class="text-center font-weight-bold"></td>
                                            <td class="text-right font-weight-bold">$<?php echo number_format($return['order_total'], 2); ?></td>
                                        </tr>
                                        <tr>
                                            <td colspan="6" class="text-right font-weight-bold">Remaining Balance:</td>
                                            <td class="text-center font-weight-bold"></td>
                                            <td class="text-right font-weight-bold text-info">
                                                $<?php echo number_format($return['order_total'] - $total_value, 2); ?>
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
                                        <th class="text-center">Returned Qty</th>
                                        <th class="text-center">Stock Before Return</th>
                                        <th class="text-center">Current Stock</th>
                                        <th class="text-center">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($return_items as $item): 
                                        $current_stock = $stock_levels[$item['item_id']] ?? ['item_quantity' => 0, 'item_status' => 'Unknown'];
                                        $stock_before = $current_stock['item_quantity'] + $item['quantity_returned'];
                                    ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                                            <td class="text-center text-danger">-<?php echo number_format($item['quantity_returned']); ?></td>
                                            <td class="text-center"><?php echo number_format($stock_before); ?></td>
                                            <td class="text-center">
                                                <span class="badge badge-<?php 
                                                    echo $current_stock['item_status'] == 'In Stock' ? 'success' : 
                                                         ($current_stock['item_status'] == 'Low Stock' ? 'warning' : 'danger'); 
                                                ?>">
                                                    <?php echo number_format($current_stock['item_quantity']); ?>
                                                </span>
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
                            This return decreased inventory stock levels as shown above. The "Stock Before Return" column shows the quantity before this return was processed.
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
                            <a href="purchase_order_return_print.php?id=<?php echo $return_id; ?>" target="_blank" class="btn btn-info">
                                <i class="fas fa-print mr-2"></i>Print Return
                            </a>
                            <a href="purchase_order_view.php?id=<?php echo $return['order_id']; ?>" class="btn btn-primary">
                                <i class="fas fa-shopping-cart mr-2"></i>View Purchase Order
                            </a>
                            <a href="purchase_order_return.php?order_id=<?php echo $return['order_id']; ?>" class="btn btn-warning">
                                <i class="fas fa-plus mr-2"></i>Create Another Return
                            </a>
                            <?php if ($session_user_role == 1 || $session_user_role == 3): ?>
                                <button type="button" class="btn btn-outline-danger" data-toggle="modal" data-target="#deleteModal">
                                    <i class="fas fa-trash mr-2"></i>Delete Return
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
                            <h5><?php echo htmlspecialchars($return['supplier_name']); ?></h5>
                        </div>
                        <hr>
                        <div class="small">
                            <?php if ($return['supplier_contact']): ?>
                                <div class="mb-2">
                                    <i class="fas fa-user mr-2 text-muted"></i>
                                    <strong>Contact:</strong> <?php echo htmlspecialchars($return['supplier_contact']); ?>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($return['supplier_phone']): ?>
                                <div class="mb-2">
                                    <i class="fas fa-phone mr-2 text-muted"></i>
                                    <strong>Phone:</strong> 
                                    <a href="tel:<?php echo htmlspecialchars($return['supplier_phone']); ?>">
                                        <?php echo htmlspecialchars($return['supplier_phone']); ?>
                                    </a>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($return['supplier_email']): ?>
                                <div class="mb-2">
                                    <i class="fas fa-envelope mr-2 text-muted"></i>
                                    <strong>Email:</strong> 
                                    <a href="mailto:<?php echo htmlspecialchars($return['supplier_email']); ?>">
                                        <?php echo htmlspecialchars($return['supplier_email']); ?>
                                    </a>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($return['supplier_address']): ?>
                                <div class="mb-2">
                                    <i class="fas fa-map-marker-alt mr-2 text-muted"></i>
                                    <strong>Address:</strong><br>
                                    <?php echo nl2br(htmlspecialchars($return['supplier_address'])); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Return Summary -->
                <div class="card card-warning mt-4">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-chart-bar mr-2"></i>Return Summary</h3>
                    </div>
                    <div class="card-body">
                        <div class="text-center mb-3">
                            <i class="fas fa-undo fa-3x text-warning mb-2"></i>
                            <h5><?php echo htmlspecialchars($return['return_number']); ?></h5>
                            <div class="text-muted"><?php echo count($return_items); ?> items</div>
                        </div>
                        <hr>
                        <div class="small">
                            <div class="d-flex justify-content-between mb-2">
                                <span>Total Items Returned:</span>
                                <span class="font-weight-bold text-danger"><?php echo number_format($total_quantity); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Total Value:</span>
                                <span class="font-weight-bold text-danger">$<?php echo number_format($total_value, 2); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Return Date:</span>
                                <span class="font-weight-bold"><?php echo date('M j, Y', strtotime($return['return_date'])); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Return Type:</span>
                                <span class="font-weight-bold text-<?php echo $return_type_color; ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $return['return_type'])); ?>
                                </span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Return Reason:</span>
                                <span class="font-weight-bold"><?php echo ucfirst(str_replace('_', ' ', $return['return_reason'])); ?></span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span>Created By:</span>
                                <span class="font-weight-bold"><?php echo htmlspecialchars($return['created_by_name']); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Return History -->
                <div class="card card-dark mt-4">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-history mr-2"></i>Return History</h3>
                    </div>
                    <div class="card-body">
                        <div class="small">
                            <div class="mb-2">
                                <strong>Created:</strong><br>
                                <?php echo date('M j, Y g:i A', strtotime($return['created_at'])); ?><br>
                                <em>by <?php echo htmlspecialchars($return['created_by_name']); ?></em>
                            </div>
                            <?php if ($return['updated_at']): ?>
                            <div class="mb-2">
                                <strong>Last Updated:</strong><br>
                                <?php echo date('M j, Y g:i A', strtotime($return['updated_at'])); ?>
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
                <h5 class="modal-title"><i class="fas fa-exclamation-triangle mr-2"></i>Delete Return</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete this Purchase Order Return?</p>
                <div class="alert alert-warning">
                    <i class="fas fa-info-circle mr-2"></i>
                    <strong>Warning:</strong> This action will:
                    <ul class="mt-2 mb-0">
                        <li>Reverse all inventory adjustments made by this return</li>
                        <li>Update the purchase order returned quantities</li>
                        <li>Permanently delete this return record and all associated items</li>
                    </ul>
                </div>
                <p class="mb-0"><strong>This action cannot be undone.</strong></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <a href="purchase_order_return_delete.php?id=<?php echo $return_id; ?>" class="btn btn-danger">Delete Return</a>
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
            window.location.href = 'purchase_order_returns_dashboard.php';
        }
        // Ctrl+P to print
        if (e.ctrlKey && e.keyCode === 80) {
            e.preventDefault();
            window.open('purchase_order_return_print.php?id=<?php echo $return_id; ?>', '_blank');
        }
    });
});

function confirmDelete() {
    return confirm('Are you sure you want to delete this return? This will reverse inventory adjustments and cannot be undone.');
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

.card-header.bg-warning {
    background: linear-gradient(45deg, #ffc107, #fd7e14) !important;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
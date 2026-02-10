<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Check if order ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Purchase order ID is required.";
    header("Location: purchase_orders.php");
    exit;
}

$order_id = intval($_GET['id']);

// Fetch purchase order details
$order_sql = "SELECT po.*, s.supplier_name, s.supplier_contact, s.supplier_phone, 
                     s.supplier_email, s.supplier_address, u.user_name as created_by_name
              FROM purchase_orders po
              LEFT JOIN suppliers s ON po.supplier_id = s.supplier_id
              LEFT JOIN users u ON po.created_by = u.user_id
              WHERE po.order_id = ?";
$order_stmt = $mysqli->prepare($order_sql);
$order_stmt->bind_param("i", $order_id);
$order_stmt->execute();
$order_result = $order_stmt->get_result();

if ($order_result->num_rows === 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Purchase order not found.";
    header("Location: purchase_orders.php");
    exit;
}

$order = $order_result->fetch_assoc();
$order_stmt->close();

// Fetch order items
$items_sql = "SELECT poi.*, ii.item_code, ii.item_quantity as current_stock, 
                     ii.item_unit_measure, ii.item_status
              FROM purchase_order_items poi
              LEFT JOIN inventory_items ii ON poi.item_id = ii.item_id
              WHERE poi.order_id = ?
              ORDER BY poi.item_name";
$items_stmt = $mysqli->prepare($items_sql);
$items_stmt->bind_param("i", $order_id);
$items_stmt->execute();
$items_result = $items_stmt->get_result();
$order_items = [];
$order_total = 0;

while ($item = $items_result->fetch_assoc()) {
    $order_items[] = $item;
    $order_total += $item['total_cost'];
}
$items_stmt->close();

// Handle status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token.";
        header("Location: purchase_order_view.php?id=" . $order_id);
        exit;
    }

    if (isset($_POST['update_status'])) {
        $new_status = sanitizeInput($_POST['order_status']);
        $notes = sanitizeInput($_POST['status_notes'] ?? '');
        
        // Start transaction for status update
        $mysqli->begin_transaction();
        
        try {
            // Update order status
            $update_sql = "UPDATE purchase_orders SET order_status = ?, status_notes = ?, updated_at = NOW() WHERE order_id = ?";
            $update_stmt = $mysqli->prepare($update_sql);
            $update_stmt->bind_param("ssi", $new_status, $notes, $order_id);
            $update_stmt->execute();
            $update_stmt->close();
            
            // If status is "received", update inventory stock
            if ($new_status === 'received') {
                foreach ($order_items as $item) {
                    $update_stock_sql = "UPDATE inventory_items 
                                        SET item_quantity = item_quantity + ?, 
                                            item_last_restocked = NOW(),
                                            item_status = CASE 
                                                WHEN (item_quantity + ?) <= 0 THEN 'Out of Stock'
                                                WHEN (item_quantity + ?) <= item_low_stock_alert THEN 'Low Stock'
                                                ELSE 'In Stock'
                                            END
                                        WHERE item_id = ?";
                    $update_stock_stmt = $mysqli->prepare($update_stock_sql);
                    $update_stock_stmt->bind_param("iiii", $item['quantity_ordered'], $item['quantity_ordered'], $item['quantity_ordered'], $item['item_id']);
                    $update_stock_stmt->execute();
                    $update_stock_stmt->close();
                    
                    // Log inventory adjustment
                    $log_sql = "INSERT INTO inventory_logs (item_id, adjustment_type, quantity_change, new_quantity, reason, created_by) 
                               VALUES (?, 'purchase_order', ?, (SELECT item_quantity FROM inventory_items WHERE item_id = ?), ?, ?)";
                    $log_stmt = $mysqli->prepare($log_sql);
                    $reason = "Purchase order #{$order['order_number']} received";
                    $log_stmt->bind_param("iiisi", $item['item_id'], $item['quantity_ordered'], $item['item_id'], $reason, $session_user_id);
                    $log_stmt->execute();
                    $log_stmt->close();
                }
            }
            
            $mysqli->commit();
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Order status updated to <strong>" . ucfirst($new_status) . "</strong> successfully!";
            
            // Refresh order data
            $order_stmt = $mysqli->prepare($order_sql);
            $order_stmt->bind_param("i", $order_id);
            $order_stmt->execute();
            $order_result = $order_stmt->get_result();
            $order = $order_result->fetch_assoc();
            $order_stmt->close();
            
        } catch (Exception $e) {
            $mysqli->rollback();
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error updating order status: " . $e->getMessage();
        }
    }
    
    header("Location: purchase_order_view.php?id=" . $order_id);
    exit;
}

// Status options for dropdown
$status_options = [
    'draft' => 'Draft',
    'ordered' => 'Ordered',
    'shipped' => 'Shipped',
    'received' => 'Received',
    'cancelled' => 'Cancelled',
    'partially_received' => 'Partially Received'
];

// Status badge classes
$status_badges = [
    'draft' => 'secondary',
    'ordered' => 'info',
    'shipped' => 'primary',
    'received' => 'success',
    'cancelled' => 'danger',
    'partially_received' => 'warning'
];
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-shopping-cart mr-2"></i>Purchase Order: <?php echo htmlspecialchars($order['order_number']); ?>
            </h3>
            <div class="card-tools">
                <a href="purchase_orders.php" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Orders
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
                <!-- Order Details -->
                <div class="card card-primary">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h3 class="card-title"><i class="fas fa-info-circle mr-2"></i>Order Details</h3>
                        <span class="badge badge-<?php echo $status_badges[$order['order_status']]; ?> badge-lg">
                            <?php echo ucfirst(str_replace('_', ' ', $order['order_status'])); ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td class="font-weight-bold" width="40%">Order Number:</td>
                                        <td><?php echo htmlspecialchars($order['order_number']); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="font-weight-bold">Supplier:</td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($order['supplier_name']); ?></strong>
                                            <?php if ($order['supplier_contact']): ?>
                                                <br><small class="text-muted">Contact: <?php echo htmlspecialchars($order['supplier_contact']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="font-weight-bold">Order Date:</td>
                                        <td><?php echo date('M j, Y', strtotime($order['order_date'])); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="font-weight-bold">Created By:</td>
                                        <td><?php echo htmlspecialchars($order['created_by_name']); ?></td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td class="font-weight-bold" width="40%">Status:</td>
                                        <td>
                                            <span class="badge badge-<?php echo $status_badges[$order['order_status']]; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $order['order_status'])); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="font-weight-bold">Expected Delivery:</td>
                                        <td>
                                            <?php if ($order['expected_delivery_date']): ?>
                                                <?php echo date('M j, Y', strtotime($order['expected_delivery_date'])); ?>
                                                <?php 
                                                $delivery_date = new DateTime($order['expected_delivery_date']);
                                                $today = new DateTime();
                                                $diff = $today->diff($delivery_date);
                                                if ($delivery_date < $today && $order['order_status'] !== 'received' && $order['order_status'] !== 'cancelled') {
                                                    echo '<span class="badge badge-danger ml-2">Overdue</span>';
                                                } elseif ($diff->days <= 3 && $order['order_status'] !== 'received' && $order['order_status'] !== 'cancelled') {
                                                    echo '<span class="badge badge-warning ml-2">Due Soon</span>';
                                                }
                                                ?>
                                            <?php else: ?>
                                                <span class="text-muted">Not set</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="font-weight-bold">Created:</td>
                                        <td><?php echo date('M j, Y g:i A', strtotime($order['created_at'])); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="font-weight-bold">Last Updated:</td>
                                        <td>
                                            <?php if ($order['updated_at']): ?>
                                                <?php echo date('M j, Y g:i A', strtotime($order['updated_at'])); ?>
                                            <?php else: ?>
                                                <span class="text-muted">Never</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <?php if (!empty($order['notes'])): ?>
                        <div class="row mt-3">
                            <div class="col-12">
                                <label class="font-weight-bold">Order Notes:</label>
                                <div class="border rounded p-3 bg-light">
                                    <?php echo nl2br(htmlspecialchars($order['notes'])); ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Order Items -->
                <div class="card card-warning">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-list-ol mr-2"></i>Order Items</h3>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Item</th>
                                        <th class="text-center">Quantity</th>
                                        <th class="text-right">Unit Cost</th>
                                        <th class="text-right">Total Cost</th>
                                        <th class="text-center">Current Stock</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($order_items) > 0): ?>
                                        <?php foreach ($order_items as $item): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($item['item_name']); ?></strong>
                                                    <?php if (!empty($item['item_description'])): ?>
                                                        <br><small class="text-muted"><?php echo htmlspecialchars($item['item_description']); ?></small>
                                                    <?php endif; ?>
                                                    <?php if (!empty($item['item_code'])): ?>
                                                        <br><small class="text-muted">Code: <?php echo htmlspecialchars($item['item_code']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <?php echo number_format($item['quantity_ordered']); ?>
                                                    <?php if (!empty($item['item_unit_measure'])): ?>
                                                        <br><small class="text-muted"><?php echo htmlspecialchars($item['item_unit_measure']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-right">$<?php echo number_format($item['unit_cost'], 2); ?></td>
                                                <td class="text-right">$<?php echo number_format($item['total_cost'], 2); ?></td>
                                                <td class="text-center">
                                                    <?php if ($item['current_stock'] !== null): ?>
                                                        <span class="badge badge-<?php 
                                                            echo $item['item_status'] == 'In Stock' ? 'success' : 
                                                                 ($item['item_status'] == 'Low Stock' ? 'warning' : 'danger'); 
                                                        ?>">
                                                            <?php echo number_format($item['current_stock']); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge badge-secondary">N/A</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" class="text-center text-muted py-4">
                                                <i class="fas fa-cubes fa-2x mb-2"></i>
                                                <p>No items found in this order.</p>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                                <tfoot class="bg-light">
                                    <tr>
                                        <td colspan="3" class="text-right font-weight-bold">Subtotal:</td>
                                        <td class="text-right font-weight-bold">$<?php echo number_format($order_total, 2); ?></td>
                                        <td></td>
                                    </tr>
                                    <tr>
                                        <td colspan="3" class="text-right font-weight-bold">Tax (0%):</td>
                                        <td class="text-right font-weight-bold">$0.00</td>
                                        <td></td>
                                    </tr>
                                    <tr>
                                        <td colspan="3" class="text-right font-weight-bold">Total Amount:</td>
                                        <td class="text-right font-weight-bold text-success">$<?php echo number_format($order_total, 2); ?></td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Status History (if available) -->
                <?php
                $history_sql = "SELECT * FROM purchase_order_history WHERE order_id = ? ORDER BY created_at DESC";
                $history_stmt = $mysqli->prepare($history_sql);
                $history_stmt->bind_param("i", $order_id);
                $history_stmt->execute();
                $history_result = $history_stmt->get_result();
                $history_items = $history_result->fetch_all(MYSQLI_ASSOC);
                $history_stmt->close();

                if (count($history_items) > 0): ?>
                <div class="card card-info">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-history mr-2"></i>Status History</h3>
                    </div>
                    <div class="card-body">
                        <div class="timeline">
                            <?php foreach ($history_items as $history): ?>
                            <div class="timeline-item">
                                <div class="timeline-header">
                                    <strong><?php echo ucfirst(str_replace('_', ' ', $history['status'])); ?></strong>
                                    <span class="float-right text-muted small">
                                        <?php echo date('M j, Y g:i A', strtotime($history['created_at'])); ?>
                                    </span>
                                </div>
                                <?php if (!empty($history['notes'])): ?>
                                <div class="timeline-body">
                                    <?php echo nl2br(htmlspecialchars($history['notes'])); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="col-md-4">
                <!-- Quick Actions -->
                <div class="card card-success">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-bolt mr-2"></i>Quick Actions</h3>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="purchase_order_edit.php?id=<?php echo $order_id; ?>" class="btn btn-warning">
                                <i class="fas fa-edit mr-2"></i>Edit Order
                            </a>
                          
                            <a href="purchase_order_print.php?id=<?php echo $order_id; ?>" target="_blank" class="btn btn-info">
                                <i class="fas fa-print mr-2"></i>Print PDF
                            </a>
                            <button type="button" class="btn btn-outline-primary" data-toggle="modal" data-target="#emailModal">
                                <i class="fas fa-envelope mr-2"></i>Email Order
                            </button>
                            <?php if ($order['order_status'] !== 'cancelled' && $order['order_status'] !== 'received'): ?>
                                <button type="button" class="btn btn-outline-danger" data-toggle="modal" data-target="#cancelModal">
                                    <i class="fas fa-times mr-2"></i>Cancel Order
                                </button>
                            <?php endif; ?>
                            <a href="purchase_order_create.php?duplicate=<?php echo $order_id; ?>" class="btn btn-outline-secondary">
                                <i class="fas fa-copy mr-2"></i>Duplicate Order
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Update Status -->
                <div class="card card-primary">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-sync-alt mr-2"></i>Update Status</h3>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="statusForm">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="update_status" value="1">
                            
                            <div class="form-group">
                                <label for="order_status">New Status</label>
                                <select class="form-control" id="order_status" name="order_status" required>
                                    <option value="">- Select Status -</option>
                                    <?php foreach ($status_options as $value => $label): ?>
                                        <option value="<?php echo $value; ?>" 
                                            <?php echo $order['order_status'] === $value ? 'selected' : ''; ?>
                                            <?php echo $value === 'draft' && $order['order_status'] !== 'draft' ? 'disabled' : ''; ?>>
                                            <?php echo $label; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="status_notes">Status Notes (Optional)</label>
                                <textarea class="form-control" id="status_notes" name="status_notes" rows="3" 
                                          placeholder="Add any notes about this status change..."></textarea>
                            </div>
                            
                            <button type="submit" class="btn btn-primary btn-block">
                                <i class="fas fa-save mr-2"></i>Update Status
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Supplier Information -->
                <div class="card card-info">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-truck mr-2"></i>Supplier Information</h3>
                    </div>
                    <div class="card-body">
                        <div class="text-center mb-3">
                            <i class="fas fa-building fa-3x text-info mb-2"></i>
                            <h5><?php echo htmlspecialchars($order['supplier_name']); ?></h5>
                        </div>
                        <hr>
                        <div class="small">
                            <?php if ($order['supplier_contact']): ?>
                                <div class="mb-2">
                                    <i class="fas fa-user mr-2 text-muted"></i>
                                    <strong>Contact:</strong> <?php echo htmlspecialchars($order['supplier_contact']); ?>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($order['supplier_phone']): ?>
                                <div class="mb-2">
                                    <i class="fas fa-phone mr-2 text-muted"></i>
                                    <strong>Phone:</strong> 
                                    <a href="tel:<?php echo htmlspecialchars($order['supplier_phone']); ?>">
                                        <?php echo htmlspecialchars($order['supplier_phone']); ?>
                                    </a>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($order['supplier_email']): ?>
                                <div class="mb-2">
                                    <i class="fas fa-envelope mr-2 text-muted"></i>
                                    <strong>Email:</strong> 
                                    <a href="mailto:<?php echo htmlspecialchars($order['supplier_email']); ?>">
                                        <?php echo htmlspecialchars($order['supplier_email']); ?>
                                    </a>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($order['supplier_address']): ?>
                                <div class="mb-2">
                                    <i class="fas fa-map-marker-alt mr-2 text-muted"></i>
                                    <strong>Address:</strong><br>
                                    <?php echo nl2br(htmlspecialchars($order['supplier_address'])); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Order Summary -->
                <div class="card card-warning">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-chart-bar mr-2"></i>Order Summary</h3>
                    </div>
                    <div class="card-body">
                        <div class="text-center mb-3">
                            <i class="fas fa-shopping-cart fa-3x text-warning mb-2"></i>
                            <h5><?php echo htmlspecialchars($order['order_number']); ?></h5>
                            <div class="text-muted"><?php echo count($order_items); ?> items</div>
                        </div>
                        <hr>
                        <div class="small">
                            <div class="d-flex justify-content-between mb-2">
                                <span>Subtotal:</span>
                                <span class="font-weight-bold">$<?php echo number_format($order_total, 2); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Tax:</span>
                                <span class="font-weight-bold">$0.00</span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Shipping:</span>
                                <span class="font-weight-bold">$0.00</span>
                            </div>
                            <hr>
                            <div class="d-flex justify-content-between">
                                <span class="font-weight-bold">Total:</span>
                                <span class="font-weight-bold text-success">$<?php echo number_format($order_total, 2); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Cancel Order Modal -->
<div class="modal fade" id="cancelModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-exclamation-triangle mr-2"></i>Cancel Purchase Order</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to cancel this purchase order?</p>
                <div class="alert alert-warning">
                    <i class="fas fa-info-circle mr-2"></i>
                    <strong>Note:</strong> This action cannot be undone. The order status will be changed to "Cancelled".
                </div>
                <form method="POST" id="cancelForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="update_status" value="1">
                    <input type="hidden" name="order_status" value="cancelled">
                    
                    <div class="form-group">
                        <label for="cancel_reason">Reason for Cancellation</label>
                        <textarea class="form-control" id="cancel_reason" name="status_notes" rows="3" 
                                  placeholder="Please provide a reason for cancelling this order..." required></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                <button type="submit" form="cancelForm" class="btn btn-danger">Cancel Order</button>
            </div>
        </div>
    </div>
</div>

<!-- Email Order Modal -->
<div class="modal fade" id="emailModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="fas fa-envelope mr-2"></i>Email Purchase Order</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <p>Send this purchase order to the supplier via email.</p>
                <form id="emailForm">
                    <div class="form-group">
                        <label for="email_to">To</label>
                        <input type="email" class="form-control" id="email_to" 
                               value="<?php echo htmlspecialchars($order['supplier_email']); ?>" 
                               placeholder="Supplier email address" required>
                    </div>
                    <div class="form-group">
                        <label for="email_subject">Subject</label>
                        <input type="text" class="form-control" id="email_subject" 
                               value="Purchase Order: <?php echo htmlspecialchars($order['order_number']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="email_message">Message</label>
                        <textarea class="form-control" id="email_message" rows="5" required>
Dear <?php echo htmlspecialchars($order['supplier_contact'] ?: 'Supplier'); ?>,

Please find attached our purchase order #<?php echo htmlspecialchars($order['order_number']); ?>.

Order Details:
- Order Date: <?php echo date('M j, Y', strtotime($order['order_date'])); ?>
- Expected Delivery: <?php echo $order['expected_delivery_date'] ? date('M j, Y', strtotime($order['expected_delivery_date'])) : 'Not specified'; ?>

Please confirm receipt of this order and provide an estimated shipping date.

Thank you,
<?php echo htmlspecialchars($order['created_by_name']); ?>
                        </textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                <button type="button" class="btn btn-info" onclick="sendEmail()">
                    <i class="fas fa-paper-plane mr-2"></i>Send Email
                </button>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Status form validation
    $('#statusForm').on('submit', function(e) {
        const newStatus = $('#order_status').val();
        const currentStatus = '<?php echo $order['order_status']; ?>';
        
        if (newStatus === currentStatus) {
            e.preventDefault();
            alert('Please select a different status from the current one.');
            return false;
        }
        
        if (newStatus === 'cancelled') {
            e.preventDefault();
            $('#cancelModal').modal('show');
            return false;
        }
    });

    // Auto-focus on cancel reason when modal opens
    $('#cancelModal').on('shown.bs.modal', function() {
        $('#cancel_reason').focus();
    });
});

function sendEmail() {
    const emailTo = $('#email_to').val();
    const emailSubject = $('#email_subject').val();
    const emailMessage = $('#email_message').val();
    
    if (!emailTo || !emailSubject || !emailMessage) {
        alert('Please fill in all email fields.');
        return;
    }
    
    // Show loading state
    const sendBtn = $('#emailModal .btn-info');
    sendBtn.html('<i class="fas fa-spinner fa-spin mr-2"></i>Sending...').prop('disabled', true);
    
    // Simulate email sending (in real application, you'd make an AJAX call)
    setTimeout(() => {
        alert('Email functionality would be implemented here. In a real application, this would send the purchase order to the supplier.');
        $('#emailModal').modal('hide');
        sendBtn.html('<i class="fas fa-paper-plane mr-2"></i>Send Email').prop('disabled', false);
    }, 1500);
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Escape to close modals
    if (e.keyCode === 27) {
        $('.modal').modal('hide');
    }
    // Ctrl+P to print
    if (e.ctrlKey && e.keyCode === 80) {
        e.preventDefault();
        window.open('purchase_order_print.php?id=<?php echo $order_id; ?>', '_blank');
    }
});
</script>

<style>
.badge-lg {
    font-size: 1rem;
    padding: 0.5em 0.8em;
}

.timeline {
    position: relative;
    padding-left: 2rem;
}

.timeline::before {
    content: '';
    position: absolute;
    left: 7px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: #dee2e6;
}

.timeline-item {
    position: relative;
    margin-bottom: 1.5rem;
}

.timeline-item::before {
    content: '';
    position: absolute;
    left: -2rem;
    top: 0.25rem;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: #007bff;
    border: 2px solid white;
}

.timeline-header {
    margin-bottom: 0.5rem;
}

.timeline-body {
    background: #f8f9fa;
    padding: 0.75rem;
    border-radius: 0.25rem;
    font-size: 0.875rem;
}

.table th {
    border-top: none;
    font-weight: 600;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
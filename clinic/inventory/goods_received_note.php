<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Check if order ID is provided
if (!isset($_GET['order_id']) || empty($_GET['order_id'])) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Purchase order ID is required.";
    header("Location: purchase_orders.php");
    exit;
}

$order_id = intval($_GET['order_id']);

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

// Check if order can receive goods
$receivable_statuses = ['ordered', 'partially_received'];
if (!in_array($order['order_status'], $receivable_statuses)) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Goods can only be received for orders with status 'Ordered' or 'Partially Received'.";
    header("Location: purchase_order_view.php?id=" . $order_id);
    exit;
}

// Fetch order items
$items_sql = "SELECT poi.*, ii.item_code, ii.item_quantity as current_stock, 
                     ii.item_unit_measure, ii.item_status,
                     (poi.quantity_ordered - IFNULL(poi.quantity_received, 0)) as remaining_quantity
              FROM purchase_order_items poi
              LEFT JOIN inventory_items ii ON poi.item_id = ii.item_id
              WHERE poi.order_id = ?
              ORDER BY poi.item_name";
$items_stmt = $mysqli->prepare($items_sql);
$items_stmt->bind_param("i", $order_id);
$items_stmt->execute();
$items_result = $items_stmt->get_result();
$order_items = [];

while ($item = $items_result->fetch_assoc()) {
    $order_items[] = $item;
}
$items_stmt->close();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    $receipt_date = sanitizeInput($_POST['receipt_date']);
    $received_by = sanitizeInput($_POST['received_by']);
    $notes = sanitizeInput($_POST['notes']);
    
    // Validate CSRF token
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        header("Location: goods_received_note.php?order_id=" . $order_id);
        exit;
    }

    // Get received quantities from POST data
    $received_items = [];
    $all_received = true;
    $some_received = false;
    
    if (isset($_POST['item_id']) && is_array($_POST['item_id'])) {
        foreach ($_POST['item_id'] as $index => $item_id) {
            $quantity_received = intval($_POST['quantity_received'][$index] ?? 0);
            $received_items[] = [
                'item_id' => intval($item_id),
                'quantity_received' => $quantity_received
            ];
            
            if ($quantity_received > 0) {
                $some_received = true;
            }
            
            $ordered_quantity = intval($_POST['ordered_quantity'][$index]);
            if ($quantity_received < $ordered_quantity) {
                $all_received = false;
            }
        }
    }

    if (!$some_received) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please enter received quantities for at least one item.";
        header("Location: goods_received_note.php?order_id=" . $order_id);
        exit;
    }

    // Start transaction
    $mysqli->begin_transaction();

    try {
        // Generate GRN number
        $grn_number = "GRN-" . date('Ymd-His');

        // Insert goods received note
        $grn_sql = "INSERT INTO goods_received_notes (
            grn_number, order_id, receipt_date, received_by, notes, 
            created_by, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, NOW())";
        
        $grn_stmt = $mysqli->prepare($grn_sql);
        $grn_stmt->bind_param(
            "sissii",
            $grn_number, $order_id, $receipt_date, $received_by, $notes, $session_user_id
        );
        $grn_stmt->execute();
        $grn_id = $grn_stmt->insert_id;
        $grn_stmt->close();

        // Update purchase order items and inventory
        foreach ($received_items as $received_item) {
            if ($received_item['quantity_received'] > 0) {
                // Update purchase order item received quantity
                $update_item_sql = "UPDATE purchase_order_items 
                                   SET quantity_received = quantity_received + ? 
                                   WHERE order_id = ? AND item_id = ?";
                $update_item_stmt = $mysqli->prepare($update_item_sql);
                $update_item_stmt->bind_param("iii", $received_item['quantity_received'], $order_id, $received_item['item_id']);
                $update_item_stmt->execute();
                $update_item_stmt->close();

                // Update inventory stock
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
                $update_stock_stmt->bind_param("iiii", 
                    $received_item['quantity_received'],
                    $received_item['quantity_received'],
                    $received_item['quantity_received'],
                    $received_item['item_id']
                );
                $update_stock_stmt->execute();
                $update_stock_stmt->close();

                // Log inventory adjustment
                $log_sql = "INSERT INTO inventory_logs (item_id, adjustment_type, quantity_change, new_quantity, reason, created_by) 
                           VALUES (?, 'goods_received', ?, (SELECT item_quantity FROM inventory_items WHERE item_id = ?), ?, ?)";
                $log_stmt = $mysqli->prepare($log_sql);
                $reason = "Goods Received Note #$grn_number for PO {$order['order_number']}";
                $log_stmt->bind_param("iiisi", 
                    $received_item['item_id'], 
                    $received_item['quantity_received'],
                    $received_item['item_id'],
                    $reason,
                    $session_user_id
                );
                $log_stmt->execute();
                $log_stmt->close();

                // Insert GRN item
                $grn_item_sql = "INSERT INTO grn_items (grn_id, item_id, quantity_received) VALUES (?, ?, ?)";
                $grn_item_stmt = $mysqli->prepare($grn_item_sql);
                $grn_item_stmt->bind_param("iii", $grn_id, $received_item['item_id'], $received_item['quantity_received']);
                $grn_item_stmt->execute();
                $grn_item_stmt->close();
            }
        }

        // Update purchase order status
        $new_status = $all_received ? 'received' : 'partially_received';
        $update_order_sql = "UPDATE purchase_orders SET order_status = ?, updated_at = NOW() WHERE order_id = ?";
        $update_order_stmt = $mysqli->prepare($update_order_sql);
        $update_order_stmt->bind_param("si", $new_status, $order_id);
        $update_order_stmt->execute();
        $update_order_stmt->close();

        $mysqli->commit();

        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Goods Received Note <strong>$grn_number</strong> created successfully! Order status updated to " . ucfirst($new_status) . ".";
        header("Location: purchase_order_view.php?id=" . $order_id);
        exit;

    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error creating goods received note: " . $e->getMessage();
        header("Location: goods_received_note.php?order_id=" . $order_id);
        exit;
    }
}
?>

<div class="card">
    <div class="card-header bg-success py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-clipboard-check mr-2"></i>Goods Received Note
            </h3>
            <div class="card-tools">
                <a href="purchase_order_view.php?id=<?php echo $order_id; ?>" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Order
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

        <!-- Order Information -->
        <div class="card card-info mb-4">
            <div class="card-header">
                <h4 class="card-title"><i class="fas fa-info-circle mr-2"></i>Purchase Order Information</h4>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3">
                        <strong>Order Number:</strong><br>
                        <?php echo htmlspecialchars($order['order_number']); ?>
                    </div>
                    <div class="col-md-3">
                        <strong>Supplier:</strong><br>
                        <?php echo htmlspecialchars($order['supplier_name']); ?>
                    </div>
                    <div class="col-md-3">
                        <strong>Order Date:</strong><br>
                        <?php echo date('M j, Y', strtotime($order['order_date'])); ?>
                    </div>
                    <div class="col-md-3">
                        <strong>Expected Delivery:</strong><br>
                        <?php echo $order['expected_delivery_date'] ? date('M j, Y', strtotime($order['expected_delivery_date'])) : 'Not set'; ?>
                    </div>
                </div>
            </div>
        </div>

        <form method="POST" id="grnForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <!-- Receipt Information -->
            <div class="card card-primary mb-4">
                <div class="card-header">
                    <h4 class="card-title"><i class="fas fa-clipboard-list mr-2"></i>Receipt Information</h4>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="receipt_date">Receipt Date *</label>
                                <input type="date" class="form-control" id="receipt_date" name="receipt_date" 
                                       value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="received_by">Received By *</label>
                                <input type="text" class="form-control" id="received_by" name="received_by" 
                                       value="<?php echo htmlspecialchars($session_user_id); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="grn_number">GRN Number</label>
                                <input type="text" class="form-control" value="Auto-generated" readonly>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="notes">Receipt Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="3" 
                                  placeholder="Any notes about this receipt, condition of goods, etc..."></textarea>
                    </div>
                </div>
            </div>

            <!-- Items Received -->
            <div class="card card-warning">
                <div class="card-header">
                    <h4 class="card-title"><i class="fas fa-list-ol mr-2"></i>Items Received</h4>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead class="bg-light">
                                <tr>
                                    <th>Item</th>
                                    <th class="text-center">Ordered Qty</th>
                                    <th class="text-center">Previously Received</th>
                                    <th class="text-center">Remaining</th>
                                    <th class="text-center">Received Now *</th>
                                    <th class="text-center">Current Stock</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($order_items as $index => $item): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($item['item_name']); ?></strong>
                                            <?php if ($item['item_code']): ?>
                                                <br><small class="text-muted">Code: <?php echo htmlspecialchars($item['item_code']); ?></small>
                                            <?php endif; ?>
                                            <br><small class="text-muted"><?php echo htmlspecialchars($item['item_unit_measure']); ?></small>
                                        </td>
                                        <td class="text-center"><?php echo $item['quantity_ordered']; ?></td>
                                        <td class="text-center"><?php echo $item['quantity_received'] ?? 0; ?></td>
                                        <td class="text-center">
                                            <span class="badge badge-<?php echo $item['remaining_quantity'] > 0 ? 'warning' : 'success'; ?>">
                                                <?php echo $item['remaining_quantity']; ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <input type="hidden" name="item_id[]" value="<?php echo $item['item_id']; ?>">
                                            <input type="hidden" name="ordered_quantity[]" value="<?php echo $item['quantity_ordered']; ?>">
                                            <input type="number" class="form-control text-center" name="quantity_received[]" 
                                                   min="0" max="<?php echo $item['remaining_quantity']; ?>" 
                                                   value="0" style="width: 100px; margin: 0 auto;">
                                        </td>
                                        <td class="text-center">
                                            <span class="badge badge-<?php 
                                                echo $item['item_status'] == 'In Stock' ? 'success' : 
                                                     ($item['item_status'] == 'Low Stock' ? 'warning' : 'danger'); 
                                            ?>">
                                                <?php echo $item['current_stock']; ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="alert alert-info mt-3">
                        <i class="fas fa-info-circle mr-2"></i>
                        Enter the quantities received for each item. The order status will be automatically updated based on the received quantities.
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="row mt-4">
                <div class="col-md-6">
                    <a href="purchase_order_view.php?id=<?php echo $order_id; ?>" class="btn btn-secondary">
                        <i class="fas fa-times mr-2"></i>Cancel
                    </a>
                </div>
                <div class="col-md-6 text-right">
                    <button type="submit" class="btn btn-success btn-lg">
                        <i class="fas fa-clipboard-check mr-2"></i>Create Goods Received Note
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
$(document).ready(function() {
    // Form validation
    $('#grnForm').on('submit', function(e) {
        let hasReceivedItems = false;
        
        $('input[name="quantity_received[]"]').each(function() {
            if (parseInt($(this).val()) > 0) {
                hasReceivedItems = true;
            }
        });
        
        if (!hasReceivedItems) {
            e.preventDefault();
            alert('Please enter received quantities for at least one item.');
            return false;
        }
        
        // Show loading state
        $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Processing...').prop('disabled', true);
    });

    // Auto-focus on first quantity field
    $('input[name="quantity_received[]"]').first().focus();
});

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + S to save
    if (e.ctrlKey && e.keyCode === 83) {
        e.preventDefault();
        $('#grnForm').submit();
    }
    // Escape to cancel
    if (e.keyCode === 27) {
        window.location.href = 'purchase_order_view.php?id=<?php echo $order_id; ?>';
    }
});
</script>

<style>
.table th {
    border-top: none;
    font-weight: 600;
}

input[type="number"] {
    text-align: center;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
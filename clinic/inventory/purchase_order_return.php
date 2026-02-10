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

// Check if order can have returns (only received or partially received orders)
$returnable_statuses = ['received', 'partially_received'];
if (!in_array($order['order_status'], $returnable_statuses)) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Returns can only be created for orders with status 'Received' or 'Partially Received'.";
    header("Location: purchase_order_view.php?id=" . $order_id);
    exit;
}

// Fetch order items with received quantities
$items_sql = "SELECT poi.*, ii.item_code, ii.item_quantity as current_stock, 
                     ii.item_unit_measure, ii.item_status,
                     (poi.quantity_received - IFNULL(poi.quantity_returned, 0)) as returnable_quantity
              FROM purchase_order_items poi
              LEFT JOIN inventory_items ii ON poi.item_id = ii.item_id
              WHERE poi.order_id = ? AND poi.quantity_received > 0
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
    $return_date = sanitizeInput($_POST['return_date']);
    $return_reason = sanitizeInput($_POST['return_reason']);
    $return_type = sanitizeInput($_POST['return_type']);
    $notes = sanitizeInput($_POST['notes']);
    
    // Validate CSRF token
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        header("Location: purchase_order_return.php?order_id=" . $order_id);
        exit;
    }

    // Validate return type - only allow specific values
    $allowed_types = ['refund', 'replacement', 'credit_note', 'exchange'];
    if (!in_array($return_type, $allowed_types)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid return type selected.";
        header("Location: purchase_order_return.php?order_id=" . $order_id);
        exit;
    }

    // Get returned quantities from POST data
    $returned_items = [];
    $some_returned = false;
    $total_return_value = 0;
    
    if (isset($_POST['item_id']) && is_array($_POST['item_id'])) {
        foreach ($_POST['item_id'] as $index => $item_id) {
            $quantity_returned = intval($_POST['quantity_returned'][$index] ?? 0);
            $unit_cost = floatval($_POST['unit_cost'][$index]);
            
            if ($quantity_returned > 0) {
                $some_returned = true;
                $item_total = $quantity_returned * $unit_cost;
                $total_return_value += $item_total;
                
                $returned_items[] = [
                    'item_id' => intval($item_id),
                    'quantity_returned' => $quantity_returned,
                    'unit_cost' => $unit_cost,
                    'total_cost' => $item_total
                ];
            }
        }
    }

    if (!$some_returned) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please enter returned quantities for at least one item.";
        header("Location: purchase_order_return.php?order_id=" . $order_id);
        exit;
    }

    // Start transaction
    $mysqli->begin_transaction();

    try {
        // Generate return number
        $return_number = "RET-" . date('Ymd-His');

        // Insert purchase order return - FIXED: Using correct parameter binding
        $return_sql = "INSERT INTO purchase_order_returns (
            return_number, order_id, return_date, return_reason, return_type, 
            total_amount, notes, created_by, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $return_stmt = $mysqli->prepare($return_sql);
        if (!$return_stmt) {
            throw new Exception("Prepare failed: " . $mysqli->error);
        }
        
        // FIX: Create variables for binding (not using references)
        $bind_order_id = $order_id;
        $bind_total_amount = $total_return_value;
        $bind_created_by = $session_user_id;
        
        // Bind parameters - FIXED: No references, just variables
        $bind_result = $return_stmt->bind_param(
            "sissdsii",
            $return_number, 
            $bind_order_id, 
            $return_date, 
            $return_reason, 
            $return_type,
            $bind_total_amount, 
            $notes, 
            $bind_created_by
        );
        
        if (!$bind_result) {
            throw new Exception("Bind failed: " . $return_stmt->error);
        }
        
        $execute_result = $return_stmt->execute();
        if (!$execute_result) {
            throw new Exception("Execute failed: " . $return_stmt->error);
        }
        
        $return_id = $return_stmt->insert_id;
        $return_stmt->close();

        // Update purchase order items and inventory
        foreach ($returned_items as $returned_item) {
            // Update purchase order item returned quantity
            $update_item_sql = "UPDATE purchase_order_items 
                               SET quantity_returned = quantity_returned + ? 
                               WHERE order_id = ? AND item_id = ?";
            $update_item_stmt = $mysqli->prepare($update_item_sql);
            
            // FIX: Create variables for binding
            $bind_quantity = $returned_item['quantity_returned'];
            $bind_order_id = $order_id;
            $bind_item_id = $returned_item['item_id'];
            
            $update_item_stmt->bind_param("iii", $bind_quantity, $bind_order_id, $bind_item_id);
            $update_item_stmt->execute();
            $update_item_stmt->close();

            // Update inventory stock (reduce stock for returns)
            $update_stock_sql = "UPDATE inventory_items 
                                SET item_quantity = item_quantity - ?, 
                                    item_status = CASE 
                                        WHEN (item_quantity - ?) <= 0 THEN 'Out of Stock'
                                        WHEN (item_quantity - ?) <= item_low_stock_alert THEN 'Low Stock'
                                        ELSE 'In Stock'
                                    END
                                WHERE item_id = ?";
            $update_stock_stmt = $mysqli->prepare($update_stock_sql);
            
            // FIX: Create variables for binding
            $bind_quantity1 = $returned_item['quantity_returned'];
            $bind_quantity2 = $returned_item['quantity_returned'];
            $bind_quantity3 = $returned_item['quantity_returned'];
            $bind_item_id = $returned_item['item_id'];
            
            $update_stock_stmt->bind_param("iiii", $bind_quantity1, $bind_quantity2, $bind_quantity3, $bind_item_id);
            $update_stock_stmt->execute();
            $update_stock_stmt->close();

            // Log inventory adjustment
            $log_sql = "INSERT INTO inventory_logs (item_id, adjustment_type, quantity_change, new_quantity, reason, created_by) 
                       VALUES (?, 'purchase_return', ?, (SELECT item_quantity FROM inventory_items WHERE item_id = ?), ?, ?)";
            $log_stmt = $mysqli->prepare($log_sql);
            
            $reason = "Purchase Return #$return_number for PO {$order['order_number']}";
            
            // FIX: Create variables for binding
            $bind_item_id = $returned_item['item_id'];
            $bind_quantity_change = -$returned_item['quantity_returned'];
            $bind_reason = $reason;
            $bind_created_by = $session_user_id;
            
            $log_stmt->bind_param("iiisi", 
                $bind_item_id, 
                $bind_quantity_change,
                $bind_item_id,
                $bind_reason,
                $bind_created_by
            );
            $log_stmt->execute();
            $log_stmt->close();

            // Insert return item
            $return_item_sql = "INSERT INTO return_items (return_id, item_id, quantity_returned, unit_cost, total_cost) 
                               VALUES (?, ?, ?, ?, ?)";
            $return_item_stmt = $mysqli->prepare($return_item_sql);
            
            // FIX: Create variables for binding
            $bind_return_id = $return_id;
            $bind_item_id = $returned_item['item_id'];
            $bind_quantity = $returned_item['quantity_returned'];
            $bind_unit_cost = $returned_item['unit_cost'];
            $bind_total_cost = $returned_item['total_cost'];
            
            $return_item_stmt->bind_param("iiidd", 
                $bind_return_id, 
                $bind_item_id, 
                $bind_quantity,
                $bind_unit_cost,
                $bind_total_cost
            );
            $return_item_stmt->execute();
            $return_item_stmt->close();
        }

        $mysqli->commit();

        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Purchase Order Return <strong>$return_number</strong> created successfully! Total return value: $" . number_format($total_return_value, 2);
        header("Location: purchase_order_view.php?id=" . $order_id);
        exit;

    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error creating purchase order return: " . $e->getMessage();
        header("Location: purchase_order_return.php?order_id=" . $order_id);
        exit;
    }
}
?>

<!-- Rest of your HTML form remains the same -->
<div class="card">
    <div class="card-header bg-warning py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-undo mr-2"></i>Purchase Order Return
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
                        <strong>Order Total:</strong><br>
                        $<?php echo number_format($order['total_amount'], 2); ?>
                    </div>
                </div>
            </div>
        </div>

        <form method="POST" id="returnForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <!-- Return Information -->
            <div class="card card-primary mb-4">
                <div class="card-header">
                    <h4 class="card-title"><i class="fas fa-undo mr-2"></i>Return Information</h4>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="return_date">Return Date *</label>
                                <input type="date" class="form-control" id="return_date" name="return_date" 
                                       value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="return_type">Return Type *</label>
                                <select class="form-control" id="return_type" name="return_type" required>
                                    <option value="">- Select Type -</option>
                                    <option value="refund">Refund</option>
                                    <option value="replacement">Replacement</option>
                                    <option value="credit_note">Credit Note</option>
                                    <option value="exchange">Exchange</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="return_number">Return Number</label>
                                <input type="text" class="form-control" value="Auto-generated" readonly>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="total_return_amount">Total Return Value</label>
                                <input type="text" class="form-control" id="total_return_amount" value="$0.00" readonly>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="return_reason">Return Reason *</label>
                        <select class="form-control" id="return_reason" name="return_reason" required>
                            <option value="">- Select Reason -</option>
                            <option value="damaged">Damaged Goods</option>
                            <option value="defective">Defective Items</option>
                            <option value="wrong_item">Wrong Item Received</option>
                            <option value="over_supplied">Over Supplied</option>
                            <option value="quality_issue">Quality Issues</option>
                            <option value="expired">Expired Items</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="notes">Return Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="3" 
                                  placeholder="Additional details about the return..."></textarea>
                    </div>
                </div>
            </div>

            <!-- Items to Return -->
            <div class="card card-danger">
                <div class="card-header">
                    <h4 class="card-title"><i class="fas fa-list-ol mr-2"></i>Items to Return</h4>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead class="bg-light">
                                <tr>
                                    <th>Item</th>
                                    <th class="text-center">Received Qty</th>
                                    <th class="text-center">Previously Returned</th>
                                    <th class="text-center">Available to Return</th>
                                    <th class="text-center">Return Qty *</th>
                                    <th class="text-center">Unit Cost</th>
                                    <th class="text-center">Line Total</th>
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
                                        <td class="text-center"><?php echo $item['quantity_received']; ?></td>
                                        <td class="text-center"><?php echo $item['quantity_returned'] ?? 0; ?></td>
                                        <td class="text-center">
                                            <span class="badge badge-<?php echo $item['returnable_quantity'] > 0 ? 'warning' : 'success'; ?>">
                                                <?php echo $item['returnable_quantity']; ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <input type="hidden" name="item_id[]" value="<?php echo $item['item_id']; ?>">
                                            <input type="hidden" name="unit_cost[]" value="<?php echo $item['unit_cost']; ?>">
                                            <input type="number" class="form-control text-center return-qty" 
                                                   name="quantity_returned[]" 
                                                   min="0" max="<?php echo $item['returnable_quantity']; ?>" 
                                                   value="0" style="width: 100px; margin: 0 auto;"
                                                   data-unit-cost="<?php echo $item['unit_cost']; ?>">
                                        </td>
                                        <td class="text-center">$<?php echo number_format($item['unit_cost'], 2); ?></td>
                                        <td class="text-center line-total">$0.00</td>
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
                            <tfoot class="bg-light">
                                <tr>
                                    <td colspan="6" class="text-right"><strong>Total Return Value:</strong></td>
                                    <td class="text-center"><strong id="grand-total">$0.00</strong></td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    
                    <div class="alert alert-warning mt-3">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        <strong>Note:</strong> Returning items will reduce your inventory stock and create a return record. 
                        This action cannot be undone.
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
                    <button type="submit" class="btn btn-warning btn-lg">
                        <i class="fas fa-undo mr-2"></i>Create Return
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
$(document).ready(function() {
    // Calculate return totals
    function calculateReturnTotals() {
        let grandTotal = 0;
        
        $('.return-qty').each(function() {
            const quantity = parseInt($(this).val()) || 0;
            const unitCost = parseFloat($(this).data('unit-cost'));
            const lineTotal = quantity * unitCost;
            
            $(this).closest('tr').find('.line-total').text('$' + lineTotal.toFixed(2));
            grandTotal += lineTotal;
        });
        
        $('#grand-total').text('$' + grandTotal.toFixed(2));
        $('#total_return_amount').val('$' + grandTotal.toFixed(2));
    }
    
    // Calculate totals when quantities change
    $('.return-qty').on('input', calculateReturnTotals);
    
    // Form validation
    $('#returnForm').on('submit', function(e) {
        let hasReturnedItems = false;
        
        $('input[name="quantity_returned[]"]').each(function() {
            if (parseInt($(this).val()) > 0) {
                hasReturnedItems = true;
            }
        });
        
        if (!hasReturnedItems) {
            e.preventDefault();
            alert('Please enter returned quantities for at least one item.');
            return false;
        }
        
        const returnType = $('#return_type').val();
        const returnReason = $('#return_reason').val();
        
        if (!returnType || !returnReason) {
            e.preventDefault();
            alert('Please select both return type and reason.');
            return false;
        }
        
        // Show loading state
        $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Processing...').prop('disabled', true);
    });

    // Auto-focus on first quantity field
    $('input[name="quantity_returned[]"]').first().focus();
    
    // Initial calculation
    calculateReturnTotals();
});

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + S to save
    if (e.ctrlKey && e.keyCode === 83) {
        e.preventDefault();
        $('#returnForm').submit();
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

.line-total, #grand-total {
    font-weight: bold;
    color: #dc3545;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
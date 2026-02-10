<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Get purchase order ID from URL
$purchase_order_id = intval($_GET['id'] ?? 0);

if ($purchase_order_id <= 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid purchase order ID.";
    header("Location: inventory_purchase_orders.php");
    exit;
}

// Initialize variables
$purchase_order = null;
$suppliers = [];
$items = [];
$locations = [];
$order_items = [];

// Fetch purchase order details
$order_sql = "SELECT 
                po.*,
                s.supplier_name,
                s.supplier_contact,
                s.supplier_phone,
                s.supplier_email,
                l.location_name,
                l.location_type,
                u.user_name as requested_by_name,
                ua.user_name as approved_by_name
              FROM inventory_purchase_orders po
              LEFT JOIN suppliers s ON po.supplier_id = s.supplier_id
              LEFT JOIN inventory_locations l ON po.delivery_location_id = l.location_id
              LEFT JOIN users u ON po.requested_by = u.user_id
              LEFT JOIN users ua ON po.approved_by = ua.user_id
              WHERE po.purchase_order_id = ? AND po.is_active = 1";
              
$order_stmt = $mysqli->prepare($order_sql);
$order_stmt->bind_param("i", $purchase_order_id);
$order_stmt->execute();
$order_result = $order_stmt->get_result();

if ($order_result->num_rows === 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Purchase order not found.";
    header("Location: inventory_purchase_orders.php");
    exit;
}

$purchase_order = $order_result->fetch_assoc();
$order_stmt->close();

// Check if order can be edited (only draft and submitted orders can be edited)
if (!in_array($purchase_order['status'], ['draft', 'submitted'])) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "This purchase order cannot be edited as it's in " . $purchase_order['status'] . " status.";
    header("Location: inventory_purchase_order_view.php?id=" . $purchase_order_id);
    exit;
}

// Get active suppliers
$suppliers_sql = "SELECT supplier_id, supplier_name, supplier_contact, supplier_phone, supplier_email 
                  FROM suppliers 
                  WHERE supplier_is_active = 1 
                  ORDER BY supplier_name";
$suppliers_result = $mysqli->query($suppliers_sql);
while ($supplier = $suppliers_result->fetch_assoc()) {
    $suppliers[] = $supplier;
}

// Get active inventory locations
$locations_sql = "SELECT location_id, location_name, location_type
                  FROM inventory_locations 
                  WHERE is_active = 1 
                  ORDER BY location_name";
$locations_result = $mysqli->query($locations_sql);
while ($location = $locations_result->fetch_assoc()) {
    $locations[] = $location;
}

// Get active inventory items with category info
$items_sql = "SELECT 
                i.item_id, 
                i.item_name, 
                i.item_code,
                i.unit_of_measure,
                i.reorder_level,
                i.status,
                i.requires_batch,
                c.category_name
              FROM inventory_items i
              LEFT JOIN inventory_categories c ON i.category_id = c.category_id
              WHERE i.is_active = 1 AND i.status = 'active'
              ORDER BY i.item_name";
$items_result = $mysqli->query($items_sql);
while ($item = $items_result->fetch_assoc()) {
    $items[] = $item;
}

// Get existing order items
$order_items_sql = "SELECT 
                      poi.purchase_order_item_id,
                      poi.item_id,
                      poi.quantity_ordered,
                      poi.quantity_received,
                      poi.unit_cost,
                      poi.estimated_total,
                      poi.notes,
                      i.item_name,
                      i.item_code,
                      i.unit_of_measure,
                      i.requires_batch,
                      c.category_name
                    FROM inventory_purchase_order_items poi
                    LEFT JOIN inventory_items i ON poi.item_id = i.item_id
                    LEFT JOIN inventory_categories c ON i.category_id = c.category_id
                    WHERE poi.purchase_order_id = ? AND poi.is_active = 1
                    ORDER BY poi.purchase_order_item_id";
                    
$order_items_stmt = $mysqli->prepare($order_items_sql);
$order_items_stmt->bind_param("i", $purchase_order_id);
$order_items_stmt->execute();
$order_items_result = $order_items_stmt->get_result();

while ($order_item = $order_items_result->fetch_assoc()) {
    $order_items[] = $order_item;
}
$order_items_stmt->close();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    $supplier_id = intval($_POST['supplier_id']);
    $delivery_location_id = intval($_POST['delivery_location_id']);
    $po_date = sanitizeInput($_POST['po_date']);
    $expected_delivery_date = sanitizeInput($_POST['expected_delivery_date']);
    $notes = sanitizeInput($_POST['notes']);
    $status = sanitizeInput($_POST['status'] ?? $purchase_order['status']);
    
    // Get order items from POST data
    $new_order_items = [];
    if (isset($_POST['item_id']) && is_array($_POST['item_id'])) {
        foreach ($_POST['item_id'] as $index => $item_id) {
            if (!empty($item_id) && !empty($_POST['quantity_ordered'][$index])) {
                $new_order_items[] = [
                    'purchase_order_item_id' => intval($_POST['purchase_order_item_id'][$index] ?? 0),
                    'item_id' => intval($item_id),
                    'item_name' => sanitizeInput($_POST['item_name'][$index]),
                    'quantity_ordered' => floatval($_POST['quantity_ordered'][$index]),
                    'unit_cost' => floatval($_POST['unit_cost'][$index]),
                    'estimated_total' => floatval($_POST['estimated_total'][$index]),
                    'notes' => sanitizeInput($_POST['item_notes'][$index] ?? '')
                ];
            }
        }
    }

    // Validate CSRF token
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        header("Location: inventory_purchase_order_edit.php?id=" . $purchase_order_id);
        exit;
    }

    // Validate required fields
    if (empty($supplier_id) || empty($delivery_location_id) || empty($po_date) || count($new_order_items) === 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please fill in all required fields and add at least one item.";
        header("Location: inventory_purchase_order_edit.php?id=" . $purchase_order_id);
        exit;
    }

    // Validate order items
    $total_estimated_amount = 0;
    foreach ($new_order_items as $item) {
        if ($item['quantity_ordered'] <= 0 || $item['unit_cost'] < 0) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Please check all item quantities and costs are valid.";
            header("Location: inventory_purchase_order_edit.php?id=" . $purchase_order_id);
            exit;
        }
        $total_estimated_amount += $item['estimated_total'];
    }

    // Start transaction
    $mysqli->begin_transaction();

    try {
        // Update purchase order
        $update_sql = "UPDATE inventory_purchase_orders SET
                        supplier_id = ?,
                        delivery_location_id = ?,
                        po_date = ?,
                        expected_delivery_date = ?,
                        total_estimated_amount = ?,
                        notes = ?,
                        status = ?,
                        updated_by = ?,
                        updated_at = NOW()
                      WHERE purchase_order_id = ?";
        
        $update_stmt = $mysqli->prepare($update_sql);
        $update_stmt->bind_param(
            "iissdssii",
            $supplier_id,
            $delivery_location_id,
            $po_date,
            $expected_delivery_date,
            $total_estimated_amount,
            $notes,
            $status,
            $session_user_id,
            $purchase_order_id
        );
        $update_stmt->execute();
        
        if ($update_stmt->affected_rows === 0) {
            throw new Exception("Failed to update purchase order");
        }
        $update_stmt->close();

        // Get existing purchase order item IDs to delete ones not in new list
        $existing_item_ids = array_column($new_order_items, 'purchase_order_item_id');
        $existing_item_ids = array_filter($existing_item_ids); // Remove zeros (new items)
        
        if (!empty($existing_item_ids)) {
            $placeholders = implode(',', array_fill(0, count($existing_item_ids), '?'));
            $delete_sql = "UPDATE inventory_purchase_order_items 
                          SET is_active = 0, updated_by = ?, updated_at = NOW()
                          WHERE purchase_order_id = ? 
                          AND purchase_order_item_id NOT IN ($placeholders)";
            
            $delete_stmt = $mysqli->prepare($delete_sql);
            $params = array_merge([$session_user_id, $purchase_order_id], $existing_item_ids);
            $delete_stmt->bind_param(str_repeat('i', count($params)), ...$params);
            $delete_stmt->execute();
            $delete_stmt->close();
        } else {
            // Soft delete all existing items if no existing items in new list
            $delete_all_sql = "UPDATE inventory_purchase_order_items 
                              SET is_active = 0, updated_by = ?, updated_at = NOW()
                              WHERE purchase_order_id = ?";
            $delete_all_stmt = $mysqli->prepare($delete_all_sql);
            $delete_all_stmt->bind_param("ii", $session_user_id, $purchase_order_id);
            $delete_all_stmt->execute();
            $delete_all_stmt->close();
        }

        // Insert/Update order items
        foreach ($new_order_items as $item) {
            if ($item['purchase_order_item_id'] > 0) {
                // Update existing item
                $item_sql = "UPDATE inventory_purchase_order_items SET
                              item_id = ?,
                              quantity_ordered = ?,
                              unit_cost = ?,
                              estimated_total = ?,
                              notes = ?,
                              updated_by = ?,
                              updated_at = NOW()
                            WHERE purchase_order_item_id = ? AND purchase_order_id = ?";
                
                $item_stmt = $mysqli->prepare($item_sql);
                $item_stmt->bind_param(
                    "idddsiii",
                    $item['item_id'],
                    $item['quantity_ordered'],
                    $item['unit_cost'],
                    $item['estimated_total'],
                    $item['notes'],
                    $session_user_id,
                    $item['purchase_order_item_id'],
                    $purchase_order_id
                );
                $item_stmt->execute();
                $item_stmt->close();
            } else {
                // Insert new item
                $item_sql = "INSERT INTO inventory_purchase_order_items (
                                purchase_order_id, item_id, quantity_ordered, 
                                unit_cost, estimated_total, notes, created_by
                            ) VALUES (?, ?, ?, ?, ?, ?, ?)";
                
                $item_stmt = $mysqli->prepare($item_sql);
                $item_stmt->bind_param(
                    "iidddsi",
                    $purchase_order_id, 
                    $item['item_id'], 
                    $item['quantity_ordered'],
                    $item['unit_cost'], 
                    $item['estimated_total'], 
                    $item['notes'],
                    $session_user_id
                );
                $item_stmt->execute();
                $item_stmt->close();
            }
        }

        $mysqli->commit();

        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Purchase order <strong>{$purchase_order['po_number']}</strong> updated successfully!";
        header("Location: inventory_purchase_order_view.php?id=" . $purchase_order_id);
        exit;

    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error updating purchase order: " . $e->getMessage();
        header("Location: inventory_purchase_order_edit.php?id=" . $purchase_order_id);
        exit;
    }
}
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-edit mr-2"></i>Edit Purchase Order: <?php echo htmlspecialchars($purchase_order['po_number']); ?>
            </h3>
            <div class="card-tools">
                <a href="inventory_purchase_order_view.php?id=<?php echo $purchase_order_id; ?>" class="btn btn-light mr-2">
                    <i class="fas fa-eye mr-2"></i>View Order
                </a>
                <a href="inventory_purchase_orders.php" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Purchase Orders
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

        <!-- Order Status Badge -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <span class="badge badge-<?php 
                            echo match($purchase_order['status']) {
                                'draft' => 'secondary',
                                'submitted' => 'warning',
                                'approved' => 'info',
                                'partially_received' => 'info',
                                'received' => 'success',
                                'cancelled' => 'danger',
                                default => 'secondary'
                            };
                        ?> badge-lg p-2">
                            <i class="fas fa-<?php 
                                echo match($purchase_order['status']) {
                                    'draft' => 'file-draft',
                                    'submitted' => 'paper-plane',
                                    'approved' => 'check-circle',
                                    'partially_received' => 'truck-loading',
                                    'received' => 'truck',
                                    'cancelled' => 'ban',
                                    default => 'file'
                                };
                            ?> mr-1"></i>
                            <?php echo ucfirst(str_replace('_', ' ', $purchase_order['status'])); ?>
                        </span>
                        <small class="text-muted ml-2">
                            Created: <?php echo date('M j, Y', strtotime($purchase_order['created_at'])); ?> 
                            by <?php echo htmlspecialchars($purchase_order['requested_by_name']); ?>
                        </small>
                        <?php if ($purchase_order['approved_by_name']): ?>
                            <small class="text-muted ml-2">
                                Approved: <?php echo date('M j, Y', strtotime($purchase_order['approved_at'])); ?> 
                                by <?php echo htmlspecialchars($purchase_order['approved_by_name']); ?>
                            </small>
                        <?php endif; ?>
                    </div>
                    <?php if ($purchase_order['status'] === 'draft'): ?>
                    <div>
                        <select class="form-control form-control-sm w-auto d-inline-block" id="status_select" name="status">
                            <option value="draft" <?php echo $purchase_order['status'] == 'draft' ? 'selected' : ''; ?>>Draft</option>
                            <option value="submitted" <?php echo $purchase_order['status'] == 'submitted' ? 'selected' : ''; ?>>Submitted</option>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <form method="POST" id="purchaseOrderForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="status" id="order_status" value="<?php echo $purchase_order['status']; ?>">
            
            <div class="row">
                <div class="col-md-8">
                    <!-- Order Information -->
                    <div class="card card-primary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-info-circle mr-2"></i>Order Information</h3>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="supplier_id">Supplier *</label>
                                        <select class="form-control select2" id="supplier_id" name="supplier_id" required>
                                            <option value="">- Select Supplier -</option>
                                            <?php foreach ($suppliers as $supplier): ?>
                                                <option value="<?php echo $supplier['supplier_id']; ?>" 
                                                    <?php echo $supplier['supplier_id'] == $purchase_order['supplier_id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($supplier['supplier_name']); ?>
                                                    <?php if ($supplier['supplier_contact']): ?>
                                                        (Contact: <?php echo htmlspecialchars($supplier['supplier_contact']); ?>)
                                                    <?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="delivery_location_id">Delivery Location *</label>
                                        <select class="form-control select2" id="delivery_location_id" name="delivery_location_id" required>
                                            <option value="">- Select Location -</option>
                                            <?php foreach ($locations as $location): ?>
                                                <option value="<?php echo $location['location_id']; ?>" 
                                                    <?php echo $location['location_id'] == $purchase_order['delivery_location_id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($location['location_name']); ?>
                                                    (<?php echo $location['location_type']; ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="po_date">Order Date *</label>
                                        <input type="date" class="form-control" id="po_date" name="po_date" 
                                               value="<?php echo htmlspecialchars($purchase_order['po_date']); ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="expected_delivery_date">Expected Delivery Date</label>
                                        <input type="date" class="form-control" id="expected_delivery_date" name="expected_delivery_date"
                                               value="<?php echo htmlspecialchars($purchase_order['expected_delivery_date']); ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="notes">Order Notes</label>
                                <textarea class="form-control" id="notes" name="notes" rows="3" 
                                          placeholder="Special instructions, delivery requirements, etc..." 
                                          maxlength="500"><?php echo htmlspecialchars($purchase_order['notes'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Order Items -->
                    <div class="card card-warning">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h3 class="card-title"><i class="fas fa-list-ol mr-2"></i>Order Items</h3>
                            <button type="button" class="btn btn-sm btn-success" onclick="addOrderItem()">
                                <i class="fas fa-plus mr-1"></i>Add Item
                            </button>
                        </div>
                        <div class="card-body">
                            <div id="order_items_container">
                                <?php if (count($order_items) > 0): ?>
                                    <?php foreach ($order_items as $index => $item): ?>
                                        <?php $itemCounter = $index + 1; ?>
                                        <div class="order-item-row border rounded p-3 mb-3" id="item_row_<?php echo $itemCounter; ?>">
                                            <input type="hidden" name="purchase_order_item_id[]" value="<?php echo $item['purchase_order_item_id']; ?>">
                                            <div class="row">
                                                <div class="col-md-4">
                                                    <div class="form-group">
                                                        <label>Item *</label>
                                                        <input type="hidden" name="item_id[]" value="<?php echo $item['item_id']; ?>">
                                                        <input type="text" class="form-control" name="item_name[]" 
                                                               value="<?php echo htmlspecialchars($item['item_name']); ?>" readonly>
                                                        <small class="form-text text-muted">
                                                            <?php echo htmlspecialchars($item['category_name']); ?> - 
                                                            <?php echo htmlspecialchars($item['unit_of_measure']); ?>
                                                            <?php if ($item['requires_batch']): ?>
                                                                <span class="badge badge-info">Requires Batch</span>
                                                            <?php endif; ?>
                                                        </small>
                                                    </div>
                                                </div>
                                                <div class="col-md-2">
                                                    <div class="form-group">
                                                        <label>Quantity *</label>
                                                        <input type="number" class="form-control quantity" name="quantity_ordered[]" 
                                                               min="0.001" step="0.001" 
                                                               value="<?php echo $item['quantity_ordered']; ?>" 
                                                               required onchange="calculateItemTotal(<?php echo $itemCounter; ?>)">
                                                        <?php if ($item['quantity_received'] > 0): ?>
                                                            <small class="form-text text-info">
                                                                <i class="fas fa-check-circle"></i> 
                                                                <?php echo $item['quantity_received']; ?> received
                                                            </small>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="col-md-2">
                                                    <div class="form-group">
                                                        <label>Unit Cost ($) *</label>
                                                        <input type="number" class="form-control unit-cost" name="unit_cost[]" 
                                                               min="0" step="0.01" 
                                                               value="<?php echo $item['unit_cost']; ?>" 
                                                               required onchange="calculateItemTotal(<?php echo $itemCounter; ?>)">
                                                    </div>
                                                </div>
                                                <div class="col-md-2">
                                                    <div class="form-group">
                                                        <label>Estimated Total ($)</label>
                                                        <input type="number" class="form-control estimated-total" name="estimated_total[]" 
                                                               value="<?php echo $item['estimated_total']; ?>" readonly>
                                                    </div>
                                                </div>
                                                <div class="col-md-2">
                                                    <div class="form-group">
                                                        <label>Notes</label>
                                                        <input type="text" class="form-control" name="item_notes[]" 
                                                               value="<?php echo htmlspecialchars($item['notes']); ?>"
                                                               placeholder="Item notes..." maxlength="255">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-10">
                                                    <div class="form-group mb-0">
                                                        <input type="text" class="form-control" 
                                                               placeholder="Optional: Batch requirements or specifications" 
                                                               name="item_specifications[]" maxlength="255">
                                                    </div>
                                                </div>
                                                <div class="col-md-2">
                                                    <?php if ($item['quantity_received'] == 0): ?>
                                                        <button type="button" class="btn btn-danger btn-block" 
                                                                onclick="removeOrderItem(<?php echo $itemCounter; ?>)">
                                                            <i class="fas fa-trash"></i> Remove
                                                        </button>
                                                    <?php else: ?>
                                                        <button type="button" class="btn btn-secondary btn-block" disabled>
                                                            <i class="fas fa-lock"></i> Received
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="text-center text-muted py-4" id="no_items_message">
                                        <i class="fas fa-cubes fa-2x mb-2"></i>
                                        <p>No items added yet. Click "Add Item" to start.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Order Summary -->
                            <div class="row mt-4">
                                <div class="col-md-6 offset-md-6">
                                    <table class="table table-sm table-borderless">
                                        <tr class="border-top">
                                            <td class="text-right"><strong>Total Estimated Amount:</strong></td>
                                            <td class="text-right" width="150"><strong>$<span id="order_total"><?php echo number_format($purchase_order['total_estimated_amount'], 2); ?></span></strong></td>
                                        </tr>
                                    </table>
                                </div>
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
                                <button type="submit" class="btn btn-success btn-lg">
                                    <i class="fas fa-save mr-2"></i>Update Purchase Order
                                </button>
                                <?php if ($purchase_order['status'] === 'draft'): ?>
                                <button type="button" class="btn btn-info" onclick="submitForApproval()">
                                    <i class="fas fa-paper-plane mr-2"></i>Submit for Approval
                                </button>
                                <?php endif; ?>
                                <button type="reset" class="btn btn-outline-secondary" onclick="resetForm()">
                                    <i class="fas fa-undo mr-2"></i>Reset Changes
                                </button>
                                <a href="inventory_purchase_order_view.php?id=<?php echo $purchase_order_id; ?>" class="btn btn-outline-danger">
                                    <i class="fas fa-times mr-2"></i>Cancel
                                </a>
                            </div>
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
                                <h5 id="supplier_name"><?php echo htmlspecialchars($purchase_order['supplier_name']); ?></h5>
                            </div>
                            <hr>
                            <div class="small" id="supplier_details">
                                <?php if ($purchase_order['supplier_contact']): ?>
                                    <div class="mb-2">
                                        <i class="fas fa-user mr-2 text-muted"></i>
                                        <strong><?php echo htmlspecialchars($purchase_order['supplier_contact']); ?></strong>
                                    </div>
                                <?php endif; ?>
                                <?php if ($purchase_order['supplier_phone']): ?>
                                    <div class="mb-2">
                                        <i class="fas fa-phone mr-2 text-muted"></i>
                                        <?php echo htmlspecialchars($purchase_order['supplier_phone']); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($purchase_order['supplier_email']): ?>
                                    <div class="mb-2">
                                        <i class="fas fa-envelope mr-2 text-muted"></i>
                                        <?php echo htmlspecialchars($purchase_order['supplier_email']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Location Information -->
                    <div class="card card-primary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-map-marker-alt mr-2"></i>Delivery Location</h3>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-3">
                                <i class="fas fa-warehouse fa-3x text-primary mb-2"></i>
                                <h5 id="location_name"><?php echo htmlspecialchars($purchase_order['location_name']); ?></h5>
                                <div class="text-muted" id="location_type"><?php echo htmlspecialchars($purchase_order['location_type']); ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Order Preview -->
                    <div class="card card-warning">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-eye mr-2"></i>Order Preview</h3>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-3">
                                <i class="fas fa-shopping-cart fa-3x text-warning mb-2"></i>
                                <h5 id="preview_order_number"><?php echo htmlspecialchars($purchase_order['po_number']); ?></h5>
                                <div class="text-muted" id="preview_item_count"><?php echo count($order_items); ?> items</div>
                            </div>
                            <hr>
                            <div class="small">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Supplier:</span>
                                    <span class="font-weight-bold" id="preview_supplier"><?php echo htmlspecialchars($purchase_order['supplier_name']); ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Delivery To:</span>
                                    <span class="font-weight-bold" id="preview_location"><?php echo htmlspecialchars($purchase_order['location_name']); ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Order Date:</span>
                                    <span class="font-weight-bold" id="preview_order_date"><?php echo $purchase_order['po_date']; ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Expected Delivery:</span>
                                    <span class="font-weight-bold" id="preview_delivery_date"><?php echo $purchase_order['expected_delivery_date'] ?: 'Not set'; ?></span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Total Amount:</span>
                                    <span class="font-weight-bold text-success">$<span id="preview_total"><?php echo number_format($purchase_order['total_estimated_amount'], 2); ?></span></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Order History -->
                    <div class="card card-secondary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-history mr-2"></i>Order History</h3>
                        </div>
                        <div class="card-body">
                            <div class="small">
                                <div class="mb-2">
                                    <i class="fas fa-calendar-plus mr-2 text-muted"></i>
                                    Created: <?php echo date('M j, Y g:i A', strtotime($purchase_order['created_at'])); ?>
                                </div>
                                <?php if ($purchase_order['updated_at'] && $purchase_order['updated_at'] != $purchase_order['created_at']): ?>
                                <div class="mb-2">
                                    <i class="fas fa-edit mr-2 text-muted"></i>
                                    Last Updated: <?php echo date('M j, Y g:i A', strtotime($purchase_order['updated_at'])); ?>
                                </div>
                                <?php endif; ?>
                                <?php if ($purchase_order['approved_by']): ?>
                                <div class="mb-2">
                                    <i class="fas fa-check-circle mr-2 text-muted"></i>
                                    Approved: <?php echo date('M j, Y g:i A', strtotime($purchase_order['approved_at'])); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Item Selection Modal -->
<div class="modal fade" id="itemSelectionModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-cubes mr-2"></i>Select Inventory Item</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table class="table table-sm table-hover" id="itemsTable">
                        <thead class="bg-light">
                            <tr>
                                <th>Item Name</th>
                                <th>Code</th>
                                <th>Category</th>
                                <th class="text-center">Unit</th>
                                <th class="text-center">Batch Required</th>
                                <th class="text-center">Reorder Level</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($item['item_name']); ?></strong>
                                    </td>
                                    <td><?php echo htmlspecialchars($item['item_code']); ?></td>
                                    <td><?php echo htmlspecialchars($item['category_name']); ?></td>
                                    <td class="text-center"><?php echo htmlspecialchars($item['unit_of_measure']); ?></td>
                                    <td class="text-center">
                                        <?php if ($item['requires_batch']): ?>
                                            <span class="badge badge-info">Yes</span>
                                        <?php else: ?>
                                            <span class="badge badge-secondary">No</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center"><?php echo $item['reorder_level']; ?></td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-sm btn-success select-item-btn" 
                                                data-item-id="<?php echo $item['item_id']; ?>"
                                                data-item-name="<?php echo htmlspecialchars($item['item_name']); ?>"
                                                data-unit-measure="<?php echo htmlspecialchars($item['unit_of_measure']); ?>"
                                                data-category="<?php echo htmlspecialchars($item['category_name']); ?>"
                                                data-requires-batch="<?php echo $item['requires_batch']; ?>">
                                            <i class="fas fa-plus mr-1"></i>Add
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Global variables
let itemCounter = <?php echo count($order_items); ?>;
const items = <?php echo json_encode($items); ?>;
const locations = <?php echo json_encode($locations); ?>;

// Make functions globally accessible
window.selectItem = function(itemId, itemName, unitMeasure, category, requiresBatch) {
    try {
        itemCounter++;
        
        const requiresBatchBadge = requiresBatch == 1 ? '<span class="badge badge-info ml-1">Requires Batch</span>' : '';
        
        const itemRow = `
            <div class="order-item-row border rounded p-3 mb-3" id="item_row_${itemCounter}">
                <input type="hidden" name="purchase_order_item_id[]" value="0">
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Item *</label>
                            <input type="hidden" name="item_id[]" value="${escapeHtml(itemId)}">
                            <input type="text" class="form-control" name="item_name[]" value="${escapeHtml(itemName)}" readonly>
                            <small class="form-text text-muted">
                                ${escapeHtml(category)} - ${escapeHtml(unitMeasure)}
                                ${requiresBatchBadge}
                            </small>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Quantity *</label>
                            <input type="number" class="form-control quantity" name="quantity_ordered[]" 
                                   min="0.001" step="0.001" value="1" required onchange="calculateItemTotal(${itemCounter})">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Unit Cost ($) *</label>
                            <input type="number" class="form-control unit-cost" name="unit_cost[]" 
                                   min="0" step="0.01" value="0.00" required onchange="calculateItemTotal(${itemCounter})">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Estimated Total ($)</label>
                            <input type="number" class="form-control estimated-total" name="estimated_total[]" 
                                   value="0.00" readonly>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Notes</label>
                            <input type="text" class="form-control" name="item_notes[]" 
                                   placeholder="Item notes..." maxlength="255">
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-10">
                        <div class="form-group mb-0">
                            <input type="text" class="form-control" placeholder="Optional: Batch requirements or specifications" 
                                   name="item_specifications[]" maxlength="255">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <button type="button" class="btn btn-danger btn-block" onclick="removeOrderItem(${itemCounter})">
                            <i class="fas fa-trash"></i> Remove
                        </button>
                    </div>
                </div>
            </div>
        `;
        
        $('#no_items_message').hide();
        $('#order_items_container').append(itemRow);
        $('#itemSelectionModal').modal('hide');
        
        calculateItemTotal(itemCounter);
        updatePreview();
    } catch (error) {
        console.error('Error adding item:', error);
        alert('Error adding item. Please try again.');
    }
}

window.removeOrderItem = function(itemId) {
    if (confirm('Are you sure you want to remove this item?')) {
        $('#item_row_' + itemId).remove();
        
        // Show no items message if no items left
        if ($('.order-item-row').length === 0) {
            $('#no_items_message').show();
        }
        
        updateOrderSummary();
        updatePreview();
    }
}

window.calculateItemTotal = function(itemId) {
    const quantity = parseFloat($('#item_row_' + itemId + ' .quantity').val()) || 0;
    const unitCost = parseFloat($('#item_row_' + itemId + ' .unit-cost').val()) || 0;
    const estimatedTotal = quantity * unitCost;
    
    $('#item_row_' + itemId + ' .estimated-total').val(estimatedTotal.toFixed(2));
    updateOrderSummary();
}

// Helper function to escape HTML
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

$(document).ready(function() {
    // Initialize Select2
    $('.select2').select2();

    // Initialize DataTable for items modal
    $('#itemsTable').DataTable({
        pageLength: 10,
        order: [[0, 'asc']],
        language: {
            search: "Search items:"
        },
        drawCallback: function() {
            // Re-attach event listeners after DataTable redraws
            attachModalEventListeners();
        }
    });

    // Attach event listeners to modal buttons
    function attachModalEventListeners() {
        $('.select-item-btn').off('click').on('click', function() {
            const itemId = $(this).data('item-id');
            const itemName = $(this).data('item-name');
            const unitMeasure = $(this).data('unit-measure');
            const category = $(this).data('category');
            const requiresBatch = $(this).data('requires-batch');
            
            selectItem(itemId, itemName, unitMeasure, category, requiresBatch);
        });
    }

    // Initial attachment of event listeners
    attachModalEventListeners();

    // Update supplier details when selected
    $('#supplier_id').on('change', function() {
        const supplierId = $(this).val();
        const selectedOption = $(this).find('option:selected');
        
        if (supplierId) {
            $('#supplier_name').text(selectedOption.text().split(' (')[0]);
            $('#preview_supplier').text(selectedOption.text().split(' (')[0]);
        } else {
            $('#supplier_name').text('Select Supplier');
            $('#preview_supplier').text('-');
        }
    });

    // Update location details when selected
    $('#delivery_location_id').on('change', function() {
        const locationId = $(this).val();
        const selectedOption = $(this).find('option:selected');
        
        if (locationId) {
            const locationText = selectedOption.text().split(' (');
            $('#location_name').text(locationText[0]);
            $('#location_type').text(locationText[1] ? locationText[1].replace(')', '') : '-');
            $('#preview_location').text(locationText[0]);
        } else {
            $('#location_name').text('Select Location');
            $('#location_type').text('-');
            $('#preview_location').text('-');
        }
    });

    // Update order date preview
    $('#po_date').on('change', function() {
        $('#preview_order_date').text($(this).val());
    });

    $('#expected_delivery_date').on('change', function() {
        $('#preview_delivery_date').text($(this).val() || 'Not set');
    });

    // Update status select
    $('#status_select').on('change', function() {
        $('#order_status').val($(this).val());
    });

    // Initialize preview
    updateOrderSummary();
    updatePreview();

    // Initialize calculations for existing items
    <?php foreach ($order_items as $index => $item): ?>
        calculateItemTotal(<?php echo $index + 1; ?>);
    <?php endforeach; ?>
});

// Add new order item row
function addOrderItem() {
    $('#itemSelectionModal').modal('show');
}

// Update order summary
function updateOrderSummary() {
    let total = 0;
    
    $('.estimated-total').each(function() {
        total += parseFloat($(this).val()) || 0;
    });
    
    $('#order_total').text(total.toFixed(2));
    $('#preview_total').text(total.toFixed(2));
}

// Update preview
function updatePreview() {
    const itemCount = $('.order-item-row').length;
    $('#preview_item_count').text(itemCount + ' item' + (itemCount !== 1 ? 's' : ''));
}

// Form actions
function submitForApproval() {
    $('#order_status').val('submitted');
    $('#purchaseOrderForm').submit();
}

function resetForm() {
    if (confirm('Are you sure you want to reset all changes? This cannot be undone.')) {
        window.location.reload();
    }
}

// Form validation
$('#purchaseOrderForm').on('submit', function(e) {
    const supplierId = $('#supplier_id').val();
    const deliveryLocationId = $('#delivery_location_id').val();
    const poDate = $('#po_date').val();
    const itemCount = $('.order-item-row').length;
    
    let isValid = true;
    
    // Validate required fields
    if (!supplierId || !deliveryLocationId || !poDate || itemCount === 0) {
        isValid = false;
    }
    
    // Validate all items have valid quantities and costs
    $('.quantity').each(function() {
        if ($(this).val() <= 0) {
            isValid = false;
            $(this).addClass('is-invalid');
        } else {
            $(this).removeClass('is-invalid');
        }
    });
    
    $('.unit-cost').each(function() {
        if ($(this).val() < 0) {
            isValid = false;
            $(this).addClass('is-invalid');
        } else {
            $(this).removeClass('is-invalid');
        }
    });
    
    if (!isValid) {
        e.preventDefault();
        alert('Please fill in all required fields and ensure all items have valid quantities and costs.');
        return false;
    }
    
    // Show loading state
    $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Updating...').prop('disabled', true);
});

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + S to save
    if (e.ctrlKey && e.keyCode === 83) {
        e.preventDefault();
        $('#purchaseOrderForm').submit();
    }
    // Escape to cancel
    if (e.keyCode === 27) {
        window.location.href = 'inventory_purchase_order_view.php?id=<?php echo $purchase_order_id; ?>';
    }
    // Ctrl + I to add item
    if (e.ctrlKey && e.keyCode === 73) {
        e.preventDefault();
        addOrderItem();
    }
});
</script>

<style>
.order-item-row {
    background-color: #f8f9fa;
    border-left: 4px solid #007bff !important;
}

.order-item-row:hover {
    background-color: #e9ecef;
}

#itemsTable_wrapper {
    margin: 0;
}

.select-item-btn {
    cursor: pointer;
}

.badge-lg {
    font-size: 1rem;
    padding: 0.5rem 1rem;
}
</style>

<?php 
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
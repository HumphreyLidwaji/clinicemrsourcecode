<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Get transfer ID from URL
$transfer_id = intval($_GET['transfer_id'] ?? 0);

if (!$transfer_id) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid transfer ID.";
    header("Location: inventory_transfers.php");
    exit;
}

// Get transfer details
$transfer_sql = "SELECT t.*, 
                        u.user_name as requested_by_name,
                        fl.location_name as from_location_name,
                        tl.location_name as to_location_name
                 FROM inventory_transfers t
                 LEFT JOIN users u ON t.requested_by = u.user_id
                 LEFT JOIN inventory_locations fl ON t.from_location_id = fl.location_id
                 LEFT JOIN inventory_locations tl ON t.to_location_id = tl.location_id
                 WHERE t.transfer_id = ?";
$transfer_stmt = $mysqli->prepare($transfer_sql);
$transfer_stmt->bind_param("i", $transfer_id);
$transfer_stmt->execute();
$transfer_result = $transfer_stmt->get_result();

if ($transfer_result->num_rows === 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Transfer not found.";
    header("Location: inventory_transfers.php");
    exit;
}

$transfer = $transfer_result->fetch_assoc();
$transfer_stmt->close();

// Check if transfer can be modified
$can_modify = in_array($transfer['transfer_status'], ['pending', 'in_transit']);

// Get transfer items
$items_sql = "SELECT ti.*, i.item_name, i.item_code, i.item_unit_measure,
                     i.item_quantity as current_stock, i.item_low_stock_alert
              FROM inventory_transfer_items ti
              JOIN inventory_items i ON ti.item_id = i.item_id
              WHERE ti.transfer_id = ?
              ORDER BY i.item_name";
$items_stmt = $mysqli->prepare($items_sql);
$items_stmt->bind_param("i", $transfer_id);
$items_stmt->execute();
$items_result = $items_stmt->get_result();
$transfer_items = [];

while ($item = $items_result->fetch_assoc()) {
    $transfer_items[] = $item;
}
$items_stmt->close();

// Get available items for adding
$available_items_sql = "SELECT item_id, item_name, item_code, item_quantity, item_unit_measure
                        FROM inventory_items 
                        WHERE item_status != 'Discontinued' 
                        AND item_quantity > 0
                        ORDER BY item_name";
$available_items_result = $mysqli->query($available_items_sql);
$available_items = [];

while ($item = $available_items_result->fetch_assoc()) {
    $available_items[] = $item;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token'] ?? '');
    
    // Validate CSRF token
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        header("Location: inventory_transfer_items.php?transfer_id=" . $transfer_id);
        exit;
    }
    
    // Check if transfer can be modified
    if (!$can_modify) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Cannot modify a completed or cancelled transfer.";
        header("Location: inventory_transfer_items.php?transfer_id=" . $transfer_id);
        exit;
    }
    
    $action = sanitizeInput($_POST['action'] ?? '');
    
    if ($action === 'update_item') {
        // Update existing item
        $item_id = intval($_POST['item_id'] ?? 0);
        $quantity = intval($_POST['quantity'] ?? 0);
        $quantity_sent = intval($_POST['quantity_sent'] ?? 0);
        $quantity_received = intval($_POST['quantity_received'] ?? 0);
        $notes = sanitizeInput($_POST['notes'] ?? '');
        
        // Validate quantities
        if ($quantity_sent > $quantity) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Quantity sent cannot exceed total quantity.";
            header("Location: inventory_transfer_items.php?transfer_id=" . $transfer_id);
            exit;
        }
        
        if ($quantity_received > $quantity_sent) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Quantity received cannot exceed quantity sent.";
            header("Location: inventory_transfer_items.php?transfer_id=" . $transfer_id);
            exit;
        }
        
        $update_sql = "UPDATE inventory_transfer_items 
                      SET quantity = ?, quantity_sent = ?, quantity_received = ?, notes = ?
                      WHERE transfer_id = ? AND item_id = ?";
        $update_stmt = $mysqli->prepare($update_sql);
        $update_stmt->bind_param("iiisii", $quantity, $quantity_sent, $quantity_received, $notes, $transfer_id, $item_id);
        
        if ($update_stmt->execute()) {
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Item updated successfully.";
        } else {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Failed to update item: " . $update_stmt->error;
        }
        $update_stmt->close();
        
    } elseif ($action === 'add_item') {
        // Add new item
        $item_id = intval($_POST['new_item_id'] ?? 0);
        $quantity = intval($_POST['new_quantity'] ?? 0);
        
        if ($item_id <= 0 || $quantity <= 0) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Please select an item and enter a valid quantity.";
            header("Location: inventory_transfer_items.php?transfer_id=" . $transfer_id);
            exit;
        }
        
        // Check if item already exists in transfer
        $check_sql = "SELECT 1 FROM inventory_transfer_items WHERE transfer_id = ? AND item_id = ?";
        $check_stmt = $mysqli->prepare($check_sql);
        $check_stmt->bind_param("ii", $transfer_id, $item_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Item already exists in this transfer.";
            $check_stmt->close();
            header("Location: inventory_transfer_items.php?transfer_id=" . $transfer_id);
            exit;
        }
        $check_stmt->close();
        
        // Add item to transfer
        $insert_sql = "INSERT INTO inventory_transfer_items 
                      (transfer_id, item_id, quantity, quantity_sent, quantity_received, notes)
                      VALUES (?, ?, ?, 0, 0, '')";
        $insert_stmt = $mysqli->prepare($insert_sql);
        $insert_stmt->bind_param("iii", $transfer_id, $item_id, $quantity);
        
        if ($insert_stmt->execute()) {
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Item added to transfer successfully.";
        } else {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Failed to add item: " . $insert_stmt->error;
        }
        $insert_stmt->close();
        
    } elseif ($action === 'remove_item') {
        // Remove item from transfer
        $item_id = intval($_POST['remove_item_id'] ?? 0);
        
        $delete_sql = "DELETE FROM inventory_transfer_items WHERE transfer_id = ? AND item_id = ?";
        $delete_stmt = $mysqli->prepare($delete_sql);
        $delete_stmt->bind_param("ii", $transfer_id, $item_id);
        
        if ($delete_stmt->execute()) {
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Item removed from transfer successfully.";
        } else {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Failed to remove item: " . $delete_stmt->error;
        }
        $delete_stmt->close();
        
    } elseif ($action === 'update_all_sent') {
        // Mark all items as sent
        $update_sql = "UPDATE inventory_transfer_items 
                      SET quantity_sent = quantity 
                      WHERE transfer_id = ?";
        $update_stmt = $mysqli->prepare($update_sql);
        $update_stmt->bind_param("i", $transfer_id);
        
        if ($update_stmt->execute()) {
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "All items marked as sent.";
        } else {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Failed to update items: " . $update_stmt->error;
        }
        $update_stmt->close();
        
    } elseif ($action === 'update_all_received') {
        // Mark all items as received
        $update_sql = "UPDATE inventory_transfer_items 
                      SET quantity_received = quantity_sent 
                      WHERE transfer_id = ?";
        $update_stmt = $mysqli->prepare($update_sql);
        $update_stmt->bind_param("i", $transfer_id);
        
        if ($update_stmt->execute()) {
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "All items marked as received.";
        } else {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Failed to update items: " . $update_stmt->error;
        }
        $update_stmt->close();
    }
    
    header("Location: inventory_transfer_items.php?transfer_id=" . $transfer_id);
    exit;
}
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0 text-white">
                <i class="fas fa-fw fa-boxes mr-2"></i>
                Manage Transfer Items: <?php echo htmlspecialchars($transfer['transfer_number']); ?>
            </h3>
            <div class="card-tools">
                <a href="inventory_transfer_view.php?id=<?php echo $transfer_id; ?>" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Transfer
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

        <!-- Transfer Info Banner -->
        <div class="alert alert-info mb-4">
            <div class="row">
                <div class="col-md-4">
                    <strong>Transfer:</strong> <?php echo htmlspecialchars($transfer['transfer_number']); ?><br>
                    <strong>Status:</strong> <?php echo ucwords(str_replace('_', ' ', $transfer['transfer_status'])); ?>
                </div>
                <div class="col-md-4">
                    <strong>From:</strong> <?php echo htmlspecialchars($transfer['from_location_name']); ?><br>
                    <strong>To:</strong> <?php echo htmlspecialchars($transfer['to_location_name']); ?>
                </div>
                <div class="col-md-4">
                    <strong>Requested By:</strong> <?php echo htmlspecialchars($transfer['requested_by_name']); ?><br>
                    <strong>Date:</strong> <?php echo date('M j, Y', strtotime($transfer['transfer_date'])); ?>
                </div>
            </div>
        </div>

        <?php if (!$can_modify): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle mr-2"></i>
                This transfer is <?php echo $transfer['transfer_status']; ?> and cannot be modified.
            </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-md-8">
                <!-- Current Items -->
                <div class="card card-primary">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-list mr-2"></i>Current Items</h3>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Item</th>
                                        <th class="text-center">Quantity</th>
                                        <th class="text-center">Sent</th>
                                        <th class="text-center">Received</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($transfer_items as $item): ?>
                                    <tr>
                                        <td>
                                            <div class="font-weight-bold"><?php echo htmlspecialchars($item['item_name']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($item['item_code']); ?></small>
                                            <div class="small">
                                                <i class="fas fa-box text-muted mr-1"></i>
                                                Stock: <?php echo $item['current_stock']; ?> <?php echo $item['item_unit_measure']; ?>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <div class="font-weight-bold"><?php echo $item['quantity']; ?></div>
                                            <small class="text-muted"><?php echo $item['item_unit_measure']; ?></small>
                                        </td>
                                        <td class="text-center">
                                            <form method="POST" class="d-inline" onsubmit="return validateQuantities(this)">
                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                <input type="hidden" name="action" value="update_item">
                                                <input type="hidden" name="item_id" value="<?php echo $item['item_id']; ?>">
                                                <div class="input-group input-group-sm">
                                                    <input type="number" class="form-control" name="quantity_sent" 
                                                           value="<?php echo $item['quantity_sent']; ?>" 
                                                           min="0" max="<?php echo $item['quantity']; ?>"
                                                           <?php echo !$can_modify ? 'disabled' : ''; ?>>
                                                </div>
                                        </td>
                                        <td class="text-center">
                                                <div class="input-group input-group-sm">
                                                    <input type="number" class="form-control" name="quantity_received" 
                                                           value="<?php echo $item['quantity_received']; ?>" 
                                                           min="0" max="<?php echo $item['quantity_sent']; ?>"
                                                           <?php echo !$can_modify ? 'disabled' : ''; ?>>
                                                </div>
                                        </td>
                                        <td>
                                            <?php if ($item['quantity_received'] == $item['quantity']): ?>
                                                <span class="badge badge-success">Complete</span>
                                            <?php elseif ($item['quantity_sent'] == $item['quantity']): ?>
                                                <span class="badge badge-info">Sent</span>
                                            <?php elseif ($item['quantity_sent'] > 0): ?>
                                                <span class="badge badge-warning">Partial</span>
                                            <?php else: ?>
                                                <span class="badge badge-secondary">Pending</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <input type="hidden" name="quantity" value="<?php echo $item['quantity']; ?>">
                                                <input type="hidden" name="notes" value="<?php echo htmlspecialchars($item['notes']); ?>">
                                                <button type="submit" class="btn btn-primary" <?php echo !$can_modify ? 'disabled' : ''; ?>>
                                                    <i class="fas fa-save"></i>
                                                </button>
                                            </form>
                                            <form method="POST" class="d-inline" onsubmit="return confirmRemove('<?php echo addslashes($item['item_name']); ?>')">
                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                <input type="hidden" name="action" value="remove_item">
                                                <input type="hidden" name="remove_item_id" value="<?php echo $item['item_id']; ?>">
                                                <button type="submit" class="btn btn-danger" <?php echo !$can_modify ? 'disabled' : ''; ?>>
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    
                                    <?php if (empty($transfer_items)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-3">
                                            <i class="fas fa-box fa-2x text-muted mb-2"></i>
                                            <p class="text-muted mb-0">No items in this transfer.</p>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <?php if ($can_modify && !empty($transfer_items)): ?>
                    <div class="card-footer">
                        <div class="row">
                            <div class="col-md-6">
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="action" value="update_all_sent">
                                    <button type="submit" class="btn btn-info btn-block" onclick="return confirm('Mark ALL items as sent?')">
                                        <i class="fas fa-paper-plane mr-2"></i>Mark All as Sent
                                    </button>
                                </form>
                            </div>
                            <div class="col-md-6">
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="action" value="update_all_received">
                                    <button type="submit" class="btn btn-success btn-block" onclick="return confirm('Mark ALL items as received?')">
                                        <i class="fas fa-check-circle mr-2"></i>Mark All as Received
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Add New Item -->
                <?php if ($can_modify): ?>
                <div class="card card-success mt-4">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-plus-circle mr-2"></i>Add New Item</h3>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="addItemForm">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="action" value="add_item">
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="new_item_id">Item *</label>
                                        <select class="form-control select2" id="new_item_id" name="new_item_id" required>
                                            <option value="">- Select Item -</option>
                                            <?php foreach ($available_items as $av_item): 
                                                // Check if item already in transfer
                                                $in_transfer = false;
                                                foreach ($transfer_items as $t_item) {
                                                    if ($t_item['item_id'] == $av_item['item_id']) {
                                                        $in_transfer = true;
                                                        break;
                                                    }
                                                }
                                                
                                                if (!$in_transfer): ?>
                                                <option value="<?php echo $av_item['item_id']; ?>"
                                                        data-stock="<?php echo $av_item['item_quantity']; ?>"
                                                        data-unit="<?php echo $av_item['item_unit_measure']; ?>">
                                                    <?php echo htmlspecialchars($av_item['item_name'] . ' (' . $av_item['item_code'] . ') - Stock: ' . $av_item['item_quantity']); ?>
                                                </option>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="new_quantity">Quantity *</label>
                                        <input type="number" class="form-control" id="new_quantity" 
                                               name="new_quantity" min="1" value="1" required>
                                        <small class="form-text text-muted" id="stock_info">Select an item first</small>
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="form-group">
                                        <label>&nbsp;</label>
                                        <button type="submit" class="btn btn-success btn-block">
                                            <i class="fas fa-plus mr-2"></i>Add
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="col-md-4">
                <!-- Quick Actions -->
                <div class="card card-info">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-bolt mr-2"></i>Quick Actions</h3>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="inventory_transfer_view.php?id=<?php echo $transfer_id; ?>" class="btn btn-info">
                                <i class="fas fa-eye mr-2"></i>View Transfer
                            </a>
                            
                            <?php if ($transfer['transfer_status'] == 'pending'): ?>
                                <a href="inventory_transfer_process.php?id=<?php echo $transfer_id; ?>&action=start" class="btn btn-warning">
                                    <i class="fas fa-play mr-2"></i>Mark In Transit
                                </a>
                            <?php elseif ($transfer['transfer_status'] == 'in_transit'): ?>
                                <a href="inventory_transfer_complete.php?id=<?php echo $transfer_id; ?>" class="btn btn-success">
                                    <i class="fas fa-exchange-alt mr-2"></i>Create Transactions
                                </a>
                            <?php endif; ?>
                            
                            <a href="inventory_transfers.php" class="btn btn-outline-dark">
                                <i class="fas fa-list mr-2"></i>All Transfers
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Transfer Summary -->
                <div class="card card-warning mt-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-calculator mr-2"></i>Transfer Summary</h3>
                    </div>
                    <div class="card-body">
                        <?php
                        $total_items = count($transfer_items);
                        $total_quantity = 0;
                        $total_sent = 0;
                        $total_received = 0;
                        
                        foreach ($transfer_items as $item) {
                            $total_quantity += $item['quantity'];
                            $total_sent += $item['quantity_sent'];
                            $total_received += $item['quantity_received'];
                        }
                        ?>
                        <div class="small">
                            <div class="d-flex justify-content-between mb-2">
                                <span>Total Items:</span>
                                <span class="font-weight-bold"><?php echo $total_items; ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Total Quantity:</span>
                                <span class="font-weight-bold"><?php echo $total_quantity; ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Sent:</span>
                                <span class="font-weight-bold <?php echo $total_sent == $total_quantity ? 'text-success' : 'text-warning'; ?>">
                                    <?php echo $total_sent; ?> (<?php echo $total_quantity > 0 ? round(($total_sent / $total_quantity) * 100) : 0; ?>%)
                                </span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span>Received:</span>
                                <span class="font-weight-bold <?php echo $total_received == $total_quantity ? 'text-success' : 'text-warning'; ?>">
                                    <?php echo $total_received; ?> (<?php echo $total_quantity > 0 ? round(($total_received / $total_quantity) * 100) : 0; ?>%)
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Progress Chart -->
                <div class="card card-secondary mt-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-chart-pie mr-2"></i>Progress</h3>
                    </div>
                    <div class="card-body text-center">
                        <?php if ($total_quantity > 0): ?>
                            <div class="progress" style="height: 30px;">
                                <div class="progress-bar bg-success" style="width: <?php echo ($total_received / $total_quantity) * 100; ?>%">
                                    Received: <?php echo $total_received; ?>
                                </div>
                                <div class="progress-bar bg-info" style="width: <?php echo (($total_sent - $total_received) / $total_quantity) * 100; ?>%">
                                    In Transit: <?php echo $total_sent - $total_received; ?>
                                </div>
                                <div class="progress-bar bg-warning" style="width: <?php echo (($total_quantity - $total_sent) / $total_quantity) * 100; ?>%">
                                    Pending: <?php echo $total_quantity - $total_sent; ?>
                                </div>
                            </div>
                            <div class="mt-2 small text-muted">
                                <?php echo round(($total_received / $total_quantity) * 100); ?>% Complete
                            </div>
                        <?php else: ?>
                            <p class="text-muted mb-0">No items in transfer</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize Select2
    $('.select2').select2();
    
    // Update stock info when item is selected
    $('#new_item_id').change(function() {
        const selectedOption = $(this).find('option:selected');
        const stock = selectedOption.data('stock') || 0;
        const unit = selectedOption.data('unit') || 'units';
        
        $('#stock_info').text('Available stock: ' + stock + ' ' + unit);
        
        // Set max quantity
        $('#new_quantity').attr('max', stock);
    });
});

function validateQuantities(form) {
    const quantity = parseInt(form.querySelector('[name="quantity"]').value);
    const quantitySent = parseInt(form.querySelector('[name="quantity_sent"]').value);
    const quantityReceived = parseInt(form.querySelector('[name="quantity_received"]').value);
    
    if (quantitySent > quantity) {
        alert('Quantity sent cannot exceed total quantity.');
        return false;
    }
    
    if (quantityReceived > quantitySent) {
        alert('Quantity received cannot exceed quantity sent.');
        return false;
    }
    
    return true;
}

function confirmRemove(itemName) {
    return confirm('Are you sure you want to remove "' + itemName + '" from this transfer?\n\nThis action cannot be undone.');
}
</script>

<style>
.select2-container .select2-selection--single {
    height: 38px;
}

.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 36px;
}

.card-header.bg-primary {
    background-color: #007bff !important;
}

.progress-bar {
    font-size: 12px;
    line-height: 30px;
}

.input-group-sm .form-control {
    height: calc(1.5em + .5rem + 2px);
    padding: .25rem .5rem;
    font-size: .875rem;
    line-height: 1.5;
}

.btn-group-sm > .btn {
    padding: .25rem .5rem;
    font-size: .875rem;
    line-height: 1.5;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
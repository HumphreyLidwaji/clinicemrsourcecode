<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Get transfer ID from URL (for editing/viewing)
$transfer_id = intval($_GET['id'] ?? 0);
$edit_mode = !empty($transfer_id);

// Initialize variables
$items = [];
$locations = [];
$transfer = null;
$transfer_items = [];

// Get all active locations for dropdown
$locations_sql = "SELECT location_id, location_name, location_type 
                  FROM inventory_locations 
                  WHERE is_active = 1 
                  ORDER BY location_type, location_name";
$locations_result = $mysqli->query($locations_sql);
while ($location_row = $locations_result->fetch_assoc()) {
    $locations[] = $location_row;
}

// Get all active items with stock > 0 for dropdown
$items_sql = "SELECT item_id, item_name, item_code, item_quantity, 
                     item_low_stock_alert, location_id, item_unit_measure
              FROM inventory_items 
              WHERE item_status != 'Discontinued' 
              AND item_quantity > 0 
              ORDER BY item_name";
$items_result = $mysqli->query($items_sql);
while ($item_row = $items_result->fetch_assoc()) {
    $items[] = $item_row;
}

// If editing existing transfer, get details
if ($edit_mode) {
    $transfer_sql = "SELECT t.*, 
                            l1.location_name as from_location_name,
                            l2.location_name as to_location_name,
                            u1.user_name as requester_name,
                            u2.user_name as approver_name
                     FROM inventory_transfers t
                     LEFT JOIN inventory_locations l1 ON t.from_location_id = l1.location_id
                     LEFT JOIN inventory_locations l2 ON t.to_location_id = l2.location_id
                     LEFT JOIN users u1 ON t.requested_by = u1.user_id
                     LEFT JOIN users u2 ON t.approved_by = u2.user_id
                     WHERE t.transfer_id = ?";
    $transfer_stmt = $mysqli->prepare($transfer_sql);
    $transfer_stmt->bind_param("i", $transfer_id);
    $transfer_stmt->execute();
    $transfer_result = $transfer_stmt->get_result();
    
    if ($transfer_result->num_rows > 0) {
        $transfer = $transfer_result->fetch_assoc();
        
        // Get transfer items
        $items_sql = "SELECT ti.*, i.item_name, i.item_code, i.item_quantity as current_stock,
                             i.item_unit_measure, i.item_low_stock_alert
                      FROM inventory_transfer_items ti
                      JOIN inventory_items i ON ti.item_id = i.item_id
                      WHERE ti.transfer_id = ?";
        $items_stmt = $mysqli->prepare($items_sql);
        $items_stmt->bind_param("i", $transfer_id);
        $items_stmt->execute();
        $items_result = $items_stmt->get_result();
        
        while ($item = $items_result->fetch_assoc()) {
            $transfer_items[] = $item;
        }
        $items_stmt->close();
    }
    $transfer_stmt->close();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    $from_location_id = intval($_POST['from_location_id'] ?? 0);
    $to_location_id = intval($_POST['to_location_id'] ?? 0);
    $transfer_notes = sanitizeInput($_POST['transfer_notes'] ?? '');
    $items_data = $_POST['items'] ?? [];
    
    // Validate CSRF token
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        header("Location: inventory_transfer.php");
        exit;
    }
    
    // Validate locations
    if (!$from_location_id || !$to_location_id) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please select both source and destination locations.";
        header("Location: inventory_transfer.php");
        exit;
    }
    
    if ($from_location_id === $to_location_id) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Source and destination locations cannot be the same.";
        header("Location: inventory_transfer.php");
        exit;
    }
    
    // Validate items
    if (empty($items_data) || !is_array($items_data)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please add at least one item to transfer.";
        header("Location: inventory_transfer.php");
        exit;
    }
    
    // Filter out empty items
    $items_data = array_filter($items_data, function($item) {
        return !empty($item['item_id']) && !empty($item['quantity']) && $item['quantity'] > 0;
    });
    
    if (empty($items_data)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please add at least one item with valid quantity.";
        header("Location: inventory_transfer.php");
        exit;
    }
    
    // Start transaction
    $mysqli->begin_transaction();
    
    try {
        // Generate transfer number
        $transfer_number = 'XFER-' . date('Ymd-His');
        
        // Create transfer record
        $transfer_sql = "INSERT INTO inventory_transfers (
            transfer_number, from_location_id, to_location_id, 
            requested_by, transfer_status, notes
        ) VALUES (?, ?, ?, ?, 'pending', ?)";
        
        $transfer_stmt = $mysqli->prepare($transfer_sql);
        $transfer_stmt->bind_param(
            "siiis",
            $transfer_number, $from_location_id, $to_location_id,
            $session_user_id, $transfer_notes
        );
        
        if (!$transfer_stmt->execute()) {
            throw new Exception("Failed to create transfer record: " . $transfer_stmt->error);
        }
        
        $new_transfer_id = $transfer_stmt->insert_id;
        $transfer_stmt->close();
        
        // Process each item
        foreach ($items_data as $item) {
            $item_id = intval($item['item_id'] ?? 0);
            $quantity = intval($item['quantity'] ?? 0);
            
            if ($item_id <= 0 || $quantity <= 0) {
                continue;
            }
            
            // Get current item details from source location
            $check_sql = "SELECT i.item_quantity, i.item_low_stock_alert,
                                 COALESCE(ili.quantity, i.item_quantity) as location_quantity
                          FROM inventory_items i
                          LEFT JOIN inventory_location_items ili ON (
                              i.item_id = ili.item_id AND ili.location_id = ?
                          )
                          WHERE i.item_id = ?";
            
            $check_stmt = $mysqli->prepare($check_sql);
            $check_stmt->bind_param("ii", $from_location_id, $item_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows === 0) {
                throw new Exception("Item not found: ID " . $item_id);
            }
            
            $item_details = $check_result->fetch_assoc();
            $check_stmt->close();
            
            $available_quantity = $item_details['location_quantity'] ?? $item_details['item_quantity'];
            
            // Check if sufficient stock at source location
            if ($available_quantity < $quantity) {
                throw new Exception("Insufficient stock for item ID " . $item_id . 
                                  ". Available: " . $available_quantity . ", Requested: " . $quantity);
            }
            
            // Calculate new quantities
            $source_new_quantity = $available_quantity - $quantity;
            
            // Get destination location current quantity
            $dest_sql = "SELECT quantity FROM inventory_location_items 
                        WHERE item_id = ? AND location_id = ?";
            $dest_stmt = $mysqli->prepare($dest_sql);
            $dest_stmt->bind_param("ii", $item_id, $to_location_id);
            $dest_stmt->execute();
            $dest_result = $dest_stmt->get_result();
            $dest_quantity = 0;
            
            if ($dest_result->num_rows > 0) {
                $dest_data = $dest_result->fetch_assoc();
                $dest_quantity = $dest_data['quantity'];
            }
            $dest_stmt->close();
            
            $dest_new_quantity = $dest_quantity + $quantity;
            
            // Update source location
            if ($item_details['location_quantity'] !== null) {
                // Update existing location item
                $source_sql = "UPDATE inventory_location_items 
                              SET quantity = ?, updated_at = NOW()
                              WHERE item_id = ? AND location_id = ?";
                $source_stmt = $mysqli->prepare($source_sql);
                $source_stmt->bind_param("iii", $source_new_quantity, $item_id, $from_location_id);
                $source_stmt->execute();
                $source_stmt->close();
                
                // Remove if zero
                if ($source_new_quantity <= 0) {
                    $cleanup_sql = "DELETE FROM inventory_location_items 
                                   WHERE item_id = ? AND location_id = ? AND quantity <= 0";
                    $cleanup_stmt = $mysqli->prepare($cleanup_sql);
                    $cleanup_stmt->bind_param("ii", $item_id, $from_location_id);
                    $cleanup_stmt->execute();
                    $cleanup_stmt->close();
                }
            } else {
                // Item not tracked at this location, but we're transferring from it
                // This shouldn't happen if we checked properly, but handle it
                throw new Exception("Item not tracked at source location.");
            }
            
            // Update destination location
            $dest_update_sql = "INSERT INTO inventory_location_items 
                               (item_id, location_id, quantity, low_stock_alert, updated_at) 
                               VALUES (?, ?, ?, ?, NOW())
                               ON DUPLICATE KEY UPDATE 
                               quantity = quantity + VALUES(quantity),
                               updated_at = NOW()";
            $dest_update_stmt = $mysqli->prepare($dest_update_sql);
            $dest_update_stmt->bind_param("iiii", $item_id, $to_location_id, $quantity, $item_details['item_low_stock_alert']);
            $dest_update_stmt->execute();
            $dest_update_stmt->close();
            
            // Update main inventory item (total doesn't change for transfers)
            $main_sql = "UPDATE inventory_items SET item_updated_date = NOW() WHERE item_id = ?";
            $main_stmt = $mysqli->prepare($main_sql);
            $main_stmt->bind_param("i", $item_id);
            $main_stmt->execute();
            $main_stmt->close();
            
            // Record transaction
            $trans_sql = "INSERT INTO inventory_transactions (
                item_id, transaction_type, quantity_change, 
                previous_quantity, new_quantity, transaction_reference, 
                transaction_notes, performed_by, from_location_id, 
                to_location_id, transfer_id, transaction_date
            ) VALUES (?, 'transfer', 0, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            
            $trans_stmt = $mysqli->prepare($trans_sql);
            $total_quantity = $item_details['item_quantity']; // Total unchanged
            $trans_reference = $transfer_number . '-' . $item_id;
            $trans_notes = "Transfer: " . $quantity . " units from location " . $from_location_id . 
                          " to " . $to_location_id;
            
          $trans_stmt->bind_param(
    "iiissiiii",
    $item_id,
    $total_quantity,
    $total_quantity,
    $trans_reference,
    $trans_notes,
    $session_user_id,
    $from_location_id,
    $to_location_id,
    $new_transfer_id
);

            $trans_stmt->execute();
            $trans_stmt->close();
            
            // Add to transfer items
            $item_sql = "INSERT INTO inventory_transfer_items 
                        (transfer_id, item_id, quantity, quantity_sent, quantity_received, notes)
                        VALUES (?, ?, ?, ?, ?, ?)";
            $item_stmt = $mysqli->prepare($item_sql);
            $item_notes = "Transferred " . $quantity . " units";
            $item_stmt->bind_param("iiiiis", $new_transfer_id, $item_id, $quantity, $quantity, $quantity, $item_notes);
            $item_stmt->execute();
            $item_stmt->close();
            
            // Log individual item transfer
            $log_sql = "INSERT INTO logs SET
                      log_type = 'Inventory',
                      log_action = 'Transfer Item',
                      log_description = ?,
                      log_ip = ?,
                      log_user_agent = ?,
                      log_user_id = ?,
                      log_entity_id = ?,
                      log_created_at = NOW()";
            $log_stmt = $mysqli->prepare($log_sql);
            $log_desc = "Transferred " . $quantity . " of item #" . $item_id . 
                       " from location #" . $from_location_id . " to #" . $to_location_id;
            $log_stmt->bind_param("sssii", $log_desc, $session_ip, $session_user_agent, $session_user_id, $new_transfer_id);
            $log_stmt->execute();
            $log_stmt->close();
        }
        
        // Log the complete transfer
        $log_sql = "INSERT INTO logs SET
                  log_type = 'Inventory',
                  log_action = 'Transfer',
                  log_description = ?,
                  log_ip = ?,
                  log_user_agent = ?,
                  log_user_id = ?,
                  log_entity_id = ?,
                  log_created_at = NOW()";
        $log_stmt = $mysqli->prepare($log_sql);
        $log_desc = "Created transfer #" . $transfer_number . " with " . count($items_data) . " items";
        $log_stmt->bind_param("sssii", $log_desc, $session_ip, $session_user_agent, $session_user_id, $new_transfer_id);
        $log_stmt->execute();
        $log_stmt->close();
        
        $mysqli->commit();
        
        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Transfer completed successfully! Transfer #: " . $transfer_number;
        
        header("Location: inventory_transfer_view.php?id=" . $new_transfer_id);
        exit;
        
    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error processing transfer: " . $e->getMessage();
        header("Location: inventory_transfer.php");
        exit;
    }
}
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0 text-white">
                <i class="fas fa-fw fa-truck-moving mr-2"></i>
                <?php echo $edit_mode ? 'Edit Transfer' : 'Create Inventory Transfer'; ?>
            </h3>
            <div class="card-tools">
                <a href="inventory_transfers.php" class="btn btn-light">
                    <i class="fas fa-list mr-2"></i>View Transfers
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

        <form method="POST" id="transferForm" autocomplete="off" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <div class="row">
                <div class="col-md-8">
                    <!-- Transfer Information -->
                    <div class="card card-primary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-info-circle mr-2"></i>Transfer Details</h3>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="from_location_id">From Location *</label>
                                        <select class="form-control select2" id="from_location_id" name="from_location_id" required>
                                            <option value="">- Select Source Location -</option>
                                            <?php foreach ($locations as $location): ?>
                                                <option value="<?php echo $location['location_id']; ?>"
                                                    <?php echo ($edit_mode && $transfer['from_location_id'] == $location['location_id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($location['location_type'] . ' - ' . $location['location_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="form-text text-muted">Where items are moving from</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="to_location_id">To Location *</label>
                                        <select class="form-control select2" id="to_location_id" name="to_location_id" required>
                                            <option value="">- Select Destination Location -</option>
                                            <?php foreach ($locations as $location): ?>
                                                <option value="<?php echo $location['location_id']; ?>"
                                                    <?php echo ($edit_mode && $transfer['to_location_id'] == $location['location_id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($location['location_type'] . ' - ' . $location['location_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="form-text text-muted">Where items are moving to</small>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="transfer_notes">Transfer Notes</label>
                                <textarea class="form-control" id="transfer_notes" name="transfer_notes" rows="3" 
                                          placeholder="Reason for transfer, delivery instructions, etc..." 
                                          maxlength="500"><?php echo $edit_mode ? htmlspecialchars($transfer['notes']) : ''; ?></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Items to Transfer -->
                    <div class="card card-success">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-boxes mr-2"></i>Items to Transfer</h3>
                        </div>
                        <div class="card-body">
                            <div id="itemsContainer">
                                <!-- Template for new rows - NO NAME ATTRIBUTES HERE -->
                                <div class="item-row-template" id="itemRowTemplate" style="display: none;">
                                    <div class="row mb-3 border-bottom pb-3">
                                        <div class="col-md-5">
                                            <div class="form-group">
                                                <label>Item</label>
                                                <select class="form-control item-select" data-name="item_id">
                                                    <option value="">- Select Item -</option>
                                                    <?php foreach ($items as $item_row): ?>
                                                        <option value="<?php echo $item_row['item_id']; ?>"
                                                                data-quantity="<?php echo $item_row['item_quantity']; ?>"
                                                                data-low-stock="<?php echo $item_row['item_low_stock_alert']; ?>"
                                                                data-unit="<?php echo $item_row['item_unit_measure']; ?>">
                                                            <?php echo htmlspecialchars($item_row['item_name'] . ' (' . $item_row['item_code'] . ') - Stock: ' . $item_row['item_quantity']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-group">
                                                <label>Quantity</label>
                                                <input type="number" class="form-control item-quantity" 
                                                       data-name="quantity" min="1" value="1">
                                                <small class="form-text text-muted item-available">Available: 0</small>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-group">
                                                <label>&nbsp;</label>
                                                <button type="button" class="btn btn-danger btn-block remove-item">
                                                    <i class="fas fa-trash"></i> Remove
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Existing items for edit mode -->
                                <?php if ($edit_mode && !empty($transfer_items)): ?>
                                    <?php foreach ($transfer_items as $index => $t_item): ?>
                                    <div class="item-row mb-3 border-bottom pb-3" data-index="<?php echo $index; ?>">
                                        <div class="row">
                                            <div class="col-md-5">
                                                <div class="form-group">
                                                    <label>Item</label>
                                                    <select class="form-control item-select" name="items[<?php echo $index; ?>][item_id]" required>
                                                        <option value="">- Select Item -</option>
                                                        <?php foreach ($items as $item_row): ?>
                                                            <option value="<?php echo $item_row['item_id']; ?>"
                                                                    data-quantity="<?php echo $item_row['item_quantity']; ?>"
                                                                    data-low-stock="<?php echo $item_row['item_low_stock_alert']; ?>"
                                                                    data-unit="<?php echo $item_row['item_unit_measure']; ?>"
                                                                    <?php echo $t_item['item_id'] == $item_row['item_id'] ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($item_row['item_name'] . ' (' . $item_row['item_code'] . ') - Stock: ' . $item_row['item_quantity']); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-group">
                                                    <label>Quantity</label>
                                                    <input type="number" class="form-control item-quantity" 
                                                           name="items[<?php echo $index; ?>][quantity]" 
                                                           min="1" value="<?php echo $t_item['quantity']; ?>" required>
                                                    <small class="form-text text-muted item-available">
                                                        Available: <?php echo $t_item['current_stock']; ?>
                                                    </small>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="form-group">
                                                    <label>&nbsp;</label>
                                                    <button type="button" class="btn btn-danger btn-block remove-item">
                                                        <i class="fas fa-trash"></i> Remove
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <!-- Initial empty row -->
                                    <div class="item-row mb-3 border-bottom pb-3" data-index="0">
                                        <div class="row">
                                            <div class="col-md-5">
                                                <div class="form-group">
                                                    <label>Item</label>
                                                    <select class="form-control item-select" name="items[0][item_id]" required>
                                                        <option value="">- Select Item -</option>
                                                        <?php foreach ($items as $item_row): ?>
                                                            <option value="<?php echo $item_row['item_id']; ?>"
                                                                    data-quantity="<?php echo $item_row['item_quantity']; ?>"
                                                                    data-low-stock="<?php echo $item_row['item_low_stock_alert']; ?>"
                                                                    data-unit="<?php echo $item_row['item_unit_measure']; ?>">
                                                                <?php echo htmlspecialchars($item_row['item_name'] . ' (' . $item_row['item_code'] . ') - Stock: ' . $item_row['item_quantity']); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-group">
                                                    <label>Quantity</label>
                                                    <input type="number" class="form-control item-quantity" 
                                                           name="items[0][quantity]" min="1" value="1" required>
                                                    <small class="form-text text-muted item-available">Available: 0</small>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="form-group">
                                                    <label>&nbsp;</label>
                                                    <button type="button" class="btn btn-danger btn-block remove-item">
                                                        <i class="fas fa-trash"></i> Remove
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <button type="button" class="btn btn-success" id="addItem">
                                <i class="fas fa-plus mr-2"></i>Add Another Item
                            </button>
                        </div>
                    </div>

                    <!-- Transfer Summary -->
                    <div class="card card-info">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-calculator mr-2"></i>Transfer Summary</h3>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="alert alert-secondary">
                                        <i class="fas fa-map-marker-alt mr-2"></i>
                                        <strong>Transfer Path:</strong><br>
                                        <span id="transfer_path">Select locations</span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="alert alert-primary">
                                        <i class="fas fa-boxes mr-2"></i>
                                        <strong>Items to Transfer:</strong><br>
                                        <span id="total_items">0 items</span> | 
                                        <span id="total_quantity">0 units</span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="alert alert-warning d-none" id="stock_warning">
                                <i class="fas fa-exclamation-triangle mr-2"></i>
                                <strong>Stock Check:</strong> 
                                <span id="stock_warning_text"></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <!-- Quick Actions -->
                    <div class="card card-success">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-bolt mr-2"></i>Actions</h3>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-success btn-lg" id="submitBtn">
                                    <i class="fas fa-check mr-2"></i>
                                    <?php echo $edit_mode ? 'Update Transfer' : 'Complete Transfer'; ?>
                                </button>
                                <button type="button" class="btn btn-outline-secondary" onclick="resetForm()">
                                    <i class="fas fa-undo mr-2"></i>Reset Form
                                </button>
                                <a href="inventory_transfers.php" class="btn btn-outline-dark">
                                    <i class="fas fa-times mr-2"></i>Cancel
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Location Details -->
                    <div class="card card-info">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-map-marker-alt mr-2"></i>Location Details</h3>
                        </div>
                        <div class="card-body">
                            <div id="location_details">
                                <p class="text-muted text-center">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    Select locations to see details
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Help -->
                    <div class="card card-secondary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-question-circle mr-2"></i>Quick Help</h3>
                        </div>
                        <div class="card-body">
                            <ul class="mb-0 small">
                                <li>Select source and destination locations</li>
                                <li>Add items to transfer</li>
                                <li>System checks stock availability</li>
                                <li>Total inventory quantity remains unchanged</li>
                                <li>Location-specific quantities are updated</li>
                                <li>Complete audit trail is maintained</li>
                            </ul>
                        </div>
                    </div>

                    <!-- Recent Transfers -->
                    <div class="card card-warning">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-history mr-2"></i>Recent Transfers</h3>
                        </div>
                        <div class="card-body">
                            <?php
                            $recent_sql = "SELECT t.transfer_id, t.transfer_number, t.transfer_date, 
                                                  l1.location_name as from_location,
                                                  l2.location_name as to_location,
                                                  COUNT(ti.item_id) as item_count
                                           FROM inventory_transfers t
                                           LEFT JOIN inventory_locations l1 ON t.from_location_id = l1.location_id
                                           LEFT JOIN inventory_locations l2 ON t.to_location_id = l2.location_id
                                           LEFT JOIN inventory_transfer_items ti ON t.transfer_id = ti.transfer_id
                                           GROUP BY t.transfer_id
                                           ORDER BY t.transfer_date DESC 
                                           LIMIT 5";
                            $recent_result = $mysqli->query($recent_sql);

                            if ($recent_result->num_rows > 0): ?>
                                <div class="list-group list-group-flush">
                                    <?php while ($recent = $recent_result->fetch_assoc()): ?>
                                        <a href="inventory_transfer_view.php?id=<?php echo $recent['transfer_id']; ?>" 
                                           class="list-group-item list-group-item-action px-0 py-2">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1"><?php echo htmlspecialchars($recent['transfer_number']); ?></h6>
                                                <small><?php echo timeAgo($recent['transfer_date']); ?></small>
                                            </div>
                                            <p class="mb-1 small">
                                                <i class="fas fa-arrow-right text-primary mr-1"></i>
                                                <?php echo htmlspecialchars($recent['from_location']); ?> → 
                                                <?php echo htmlspecialchars($recent['to_location']); ?>
                                            </p>
                                            <small class="text-muted">
                                                <i class="fas fa-box mr-1"></i>
                                                <?php echo $recent['item_count']; ?> items
                                            </small>
                                        </a>
                                    <?php endwhile; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted mb-0 text-center">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    No recent transfers
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize Select2
    $('.select2').select2();
    
    let itemCounter = <?php echo $edit_mode && !empty($transfer_items) ? count($transfer_items) : 1; ?>;
    
    // Add item row
    $('#addItem').click(function() {
        const template = $('#itemRowTemplate').html();
        const newRow = $(template);
        
        // Add name attributes with correct index
        newRow.find('[data-name="item_id"]').attr('name', 'items[' + itemCounter + '][item_id]').attr('required', true);
        newRow.find('[data-name="quantity"]').attr('name', 'items[' + itemCounter + '][quantity]').attr('required', true);
        
        // Set data-index
        newRow.attr('data-index', itemCounter);
        newRow.addClass('item-row');
        newRow.removeClass('item-row-template');
        
        $('#itemsContainer').append(newRow);
        
        // Initialize Select2 for the new dropdown
        newRow.find('.item-select').select2();
        
        itemCounter++;
        updateSummary();
    });
    
    // Remove item row
    $(document).on('click', '.remove-item', function() {
        const row = $(this).closest('.item-row');
        if ($('.item-row').length > 1) {
            row.remove();
            updateSummary();
            reindexItems();
        } else {
            alert('At least one item is required.');
        }
    });
    
    // Update available quantity when item is selected
    $(document).on('change', '.item-select', function() {
        const selectedOption = $(this).find('option:selected');
        const available = selectedOption.data('quantity') || 0;
        const unit = selectedOption.data('unit') || 'units';
        
        $(this).closest('.item-row').find('.item-available').text('Available: ' + available + ' ' + unit);
        
        // Set max quantity
        const quantityInput = $(this).closest('.item-row').find('.item-quantity');
        quantityInput.attr('max', available);
        
        updateSummary();
    });
    
    // Update summary when quantity changes
    $(document).on('input', '.item-quantity', function() {
        updateSummary();
    });
    
    // Update location path
    $('#from_location_id, #to_location_id').change(function() {
        updateTransferPath();
        updateLocationDetails();
        checkStockAvailability();
    });
    
    function updateTransferPath() {
        const from = $('#from_location_id option:selected').text();
        const to = $('#to_location_id option:selected').text();
        
        if (from && to) {
            $('#transfer_path').html('<strong>From:</strong> ' + from + '<br><strong>To:</strong> ' + to);
        } else {
            $('#transfer_path').text('Select locations');
        }
    }
    
    function updateLocationDetails() {
        const fromId = $('#from_location_id').val();
        const toId = $('#to_location_id').val();
        
        if (!fromId || !toId) {
            $('#location_details').html('<p class="text-muted text-center"><i class="fas fa-info-circle mr-1"></i>Select locations to see details</p>');
            return;
        }
        
        $('#location_details').html(`
            <div class="small">
                <div class="d-flex justify-content-between mb-2">
                    <span>Source Location:</span>
                    <span class="font-weight-bold">${$('#from_location_id option:selected').text()}</span>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span>Destination:</span>
                    <span class="font-weight-bold">${$('#to_location_id option:selected').text()}</span>
                </div>
                <div class="mt-2 alert alert-info">
                    <i class="fas fa-info-circle mr-1"></i>
                    Stock will be moved between these locations
                </div>
            </div>
        `);
    }
    
    function updateSummary() {
        let totalItems = 0;
        let totalQuantity = 0;
        let stockIssues = [];
        
        $('.item-row').each(function() {
            const itemSelect = $(this).find('.item-select');
            const quantityInput = $(this).find('.item-quantity');
            const selectedOption = itemSelect.find('option:selected');
            
            if (selectedOption.val()) {
                totalItems++;
                const quantity = parseInt(quantityInput.val()) || 0;
                totalQuantity += quantity;
                
                // Check stock availability
                const available = selectedOption.data('quantity') || 0;
                if (quantity > available) {
                    stockIssues.push(selectedOption.text().split(' (')[0] + ': Requested ' + quantity + ', Available ' + available);
                }
            }
        });
        
        $('#total_items').text(totalItems + ' item' + (totalItems !== 1 ? 's' : ''));
        $('#total_quantity').text(totalQuantity + ' units');
        
        // Show stock warnings
        if (stockIssues.length > 0) {
            $('#stock_warning').removeClass('d-none');
            $('#stock_warning_text').text('Insufficient stock: ' + stockIssues.join('; '));
        } else {
            $('#stock_warning').addClass('d-none');
        }
    }
    
    function checkStockAvailability() {
        const fromLocationId = $('#from_location_id').val();
        if (!fromLocationId) return;
        updateSummary();
    }
    
    function reindexItems() {
        let newIndex = 0;
        $('.item-row').each(function() {
            const currentIndex = $(this).data('index');
            $(this).attr('data-index', newIndex);
            
            // Update name attributes
            $(this).find('[name*="item_id"]').attr('name', 'items[' + newIndex + '][item_id]');
            $(this).find('[name*="quantity"]').attr('name', 'items[' + newIndex + '][quantity]');
            
            newIndex++;
        });
        itemCounter = newIndex;
    }
    
    function resetForm() {
        if (confirm('Are you sure you want to reset all changes?')) {
            // Keep only the first item row
            const firstRow = $('#itemsContainer .item-row').first();
            $('#itemsContainer .item-row').not(firstRow).remove();
            
            // Reset first row
            firstRow.find('.item-select').val('').trigger('change');
            firstRow.find('.item-quantity').val(1);
            firstRow.find('.item-available').text('Available: 0');
            
            // Reset location selects
            $('#from_location_id, #to_location_id').val('').trigger('change');
            $('#transfer_notes').val('');
            
            // Reset indexes
            itemCounter = 1;
            firstRow.attr('data-index', 0);
            
            updateSummary();
            updateTransferPath();
            updateLocationDetails();
            
            // Reset Select2
            $('.item-select').select2();
        }
    }
    
    // Form validation
    $('#transferForm').on('submit', function(e) {
        e.preventDefault();
        
        const fromLocationId = $('#from_location_id').val();
        const toLocationId = $('#to_location_id').val();
        
        // Basic validation
        if (!fromLocationId || !toLocationId) {
            alert('Please select both source and destination locations.');
            return false;
        }
        
        if (fromLocationId === toLocationId) {
            alert('Source and destination locations cannot be the same.');
            return false;
        }
        
        let hasItems = false;
        let stockProblems = [];
        let validationErrors = [];
        
        $('.item-row').each(function(index) {
            const itemSelect = $(this).find('.item-select');
            const quantityInput = $(this).find('.item-quantity');
            const selectedOption = itemSelect.find('option:selected');
            
            if (!selectedOption.val()) {
                validationErrors.push(`Item ${index + 1}: Please select an item`);
                return;
            }
            
            hasItems = true;
            const quantity = parseInt(quantityInput.val()) || 0;
            const available = selectedOption.data('quantity') || 0;
            
            if (quantity < 1) {
                validationErrors.push(`Item ${index + 1}: Quantity must be at least 1`);
            } else if (quantity > available) {
                stockProblems.push(`Item ${index + 1}: Available ${available}, Requested ${quantity}`);
            }
        });
        
        if (!hasItems) {
            alert('Please add at least one item to transfer.');
            return false;
        }
        
        if (validationErrors.length > 0) {
            alert('Please fix the following issues:\n\n' + validationErrors.join('\n'));
            return false;
        }
        
        if (stockProblems.length > 0) {
            alert('Insufficient stock:\n\n' + stockProblems.join('\n') + '\n\nPlease adjust quantities.');
            return false;
        }
        
        // Show loading state
        const submitBtn = $('#submitBtn');
        const originalText = submitBtn.html();
        submitBtn.html('<i class="fas fa-spinner fa-spin mr-2"></i>Processing...').prop('disabled', true);
        
        // Final confirmation
        const itemCount = $('.item-row').length;
        const totalQty = $('#total_quantity').text();
        
        if (confirm(`Confirm transfer of ${itemCount} items (${totalQty})?\n\nThis will move items between locations.`)) {
            // Submit the form
            this.submit();
        } else {
            submitBtn.html(originalText).prop('disabled', false);
        }
    });
    
    // Initialize
    updateTransferPath();
    updateSummary();
    
    // Initialize existing item selects
    $('.item-select').each(function() {
        $(this).select2();
        $(this).trigger('change');
    });
});
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

.btn-primary {
    background-color: #007bff;
    border-color: #007bff;
}

.btn-primary:hover {
    background-color: #0056b3;
    border-color: #0056b3;
}

.item-row {
    border-bottom: 1px solid #dee2e6;
    padding-bottom: 15px;
    margin-bottom: 15px;
}

.item-row:last-child {
    border-bottom: none;
}

#stock_warning {
    border-left: 4px solid #ffc107;
}

#submitBtn:disabled {
    opacity: 0.7;
    cursor: not-allowed;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
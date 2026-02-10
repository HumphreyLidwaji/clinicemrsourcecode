<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Get parameters
$item_id = intval($_GET['item_id'] ?? 0);
$from_location = intval($_GET['from_location'] ?? 0);

// Initialize variables
$item = null;
$locations = [];
$from_location_data = null;

// Get all active locations
$locations_sql = "SELECT * FROM inventory_locations WHERE is_active = 1 ORDER BY location_type, location_name";
$locations_result = $mysqli->query($locations_sql);
while ($location = $locations_result->fetch_assoc()) {
    $locations[] = $location;
}

// Get item details if item_id is provided
if ($item_id > 0) {
    $item_sql = "SELECT i.*, c.category_name, s.supplier_name 
                 FROM inventory_items i
                 LEFT JOIN inventory_categories c ON i.item_category_id = c.category_id
                 LEFT JOIN suppliers s ON i.item_supplier_id = s.supplier_id
                 WHERE i.item_id = ?";
    $item_stmt = $mysqli->prepare($item_sql);
    $item_stmt->bind_param("i", $item_id);
    $item_stmt->execute();
    $item_result = $item_stmt->get_result();
    $item = $item_result->fetch_assoc();
    $item_stmt->close();
}

// Get source location details if provided
if ($from_location > 0) {
    $from_sql = "SELECT * FROM inventory_locations WHERE location_id = ?";
    $from_stmt = $mysqli->prepare($from_sql);
    $from_stmt->bind_param("i", $from_location);
    $from_stmt->execute();
    $from_result = $from_stmt->get_result();
    $from_location_data = $from_result->fetch_assoc();
    $from_stmt->close();
    
    // Get current stock in source location
    if ($item_id > 0) {
        $stock_sql = "SELECT quantity FROM inventory_location_items WHERE item_id = ? AND location_id = ?";
        $stock_stmt = $mysqli->prepare($stock_sql);
        $stock_stmt->bind_param("ii", $item_id, $from_location);
        $stock_stmt->execute();
        $stock_result = $stock_stmt->get_result();
        $current_stock = $stock_result->fetch_assoc();
        $from_location_data['current_stock'] = $current_stock['quantity'] ?? 0;
        $stock_stmt->close();
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    $transfer_item_id = intval($_POST['item_id']);
    $transfer_from_location = intval($_POST['from_location_id']);
    $transfer_to_location = intval($_POST['to_location_id']);
    $transfer_quantity = intval($_POST['quantity']);
    $transfer_notes = sanitizeInput($_POST['notes'] ?? '');
    
    // Validate CSRF token
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        header("Location: inventory_transfer.php?item_id=" . $transfer_item_id);
        exit;
    }
    
    // Validate required fields
    if ($transfer_item_id <= 0 || $transfer_from_location <= 0 || $transfer_to_location <= 0 || $transfer_quantity <= 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please fill in all required fields with valid values.";
        header("Location: inventory_transfer.php?item_id=" . $transfer_item_id);
        exit;
    }
    
    // Check if source and destination are different
    if ($transfer_from_location === $transfer_to_location) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Source and destination locations cannot be the same.";
        header("Location: inventory_transfer.php?item_id=" . $transfer_item_id);
        exit;
    }
    
    // Start transaction
    $mysqli->begin_transaction();
    
    try {
        // Get current stock in source location
        $source_stock_sql = "SELECT quantity FROM inventory_location_items WHERE item_id = ? AND location_id = ?";
        $source_stmt = $mysqli->prepare($source_stock_sql);
        $source_stmt->bind_param("ii", $transfer_item_id, $transfer_from_location);
        $source_stmt->execute();
        $source_result = $source_stmt->get_result();
        $source_stock = $source_result->fetch_assoc();
        $source_stmt->close();
        
        $current_source_quantity = $source_stock['quantity'] ?? 0;
        
        // Check if sufficient stock exists
        if ($current_source_quantity < $transfer_quantity) {
            throw new Exception("Insufficient stock in source location. Available: " . $current_source_quantity);
        }
        
        // Get item details for logging
        $item_detail_sql = "SELECT item_name, item_code FROM inventory_items WHERE item_id = ?";
        $item_detail_stmt = $mysqli->prepare($item_detail_sql);
        $item_detail_stmt->bind_param("i", $transfer_item_id);
        $item_detail_stmt->execute();
        $item_detail_result = $item_detail_stmt->get_result();
        $item_details = $item_detail_result->fetch_assoc();
        $item_detail_stmt->close();
        
        // Get location names for logging
        $location_names_sql = "SELECT 
            (SELECT location_name FROM inventory_locations WHERE location_id = ?) as from_location,
            (SELECT location_name FROM inventory_locations WHERE location_id = ?) as to_location";
        $loc_stmt = $mysqli->prepare($location_names_sql);
        $loc_stmt->bind_param("ii", $transfer_from_location, $transfer_to_location);
        $loc_stmt->execute();
        $loc_result = $loc_stmt->get_result();
        $location_names = $loc_result->fetch_assoc();
        $loc_stmt->close();
        
        $from_location_name = $location_names['from_location'] ?? "ID: $transfer_from_location";
        $to_location_name = $location_names['to_location'] ?? "ID: $transfer_to_location";
        
        // Update source location stock
        $new_source_quantity = $current_source_quantity - $transfer_quantity;
        $update_source_sql = "UPDATE inventory_location_items SET quantity = ?, updated_at = NOW() WHERE item_id = ? AND location_id = ?";
        $update_source_stmt = $mysqli->prepare($update_source_sql);
        $update_source_stmt->bind_param("iii", $new_source_quantity, $transfer_item_id, $transfer_from_location);
        
        if (!$update_source_stmt->execute()) {
            throw new Exception("Failed to update source location stock: " . $update_source_stmt->error);
        }
        $update_source_stmt->close();
        
        // Update or insert destination location stock
        $dest_stock_sql = "SELECT quantity FROM inventory_location_items WHERE item_id = ? AND location_id = ?";
        $dest_stmt = $mysqli->prepare($dest_stock_sql);
        $dest_stmt->bind_param("ii", $transfer_item_id, $transfer_to_location);
        $dest_stmt->execute();
        $dest_result = $dest_stmt->get_result();
        $dest_stock = $dest_result->fetch_assoc();
        $dest_stmt->close();
        
        $current_dest_quantity = $dest_stock['quantity'] ?? 0;
        $new_dest_quantity = $current_dest_quantity + $transfer_quantity;
        
        if ($dest_stock) {
            // Update existing record
            $update_dest_sql = "UPDATE inventory_location_items SET quantity = ?, updated_at = NOW() WHERE item_id = ? AND location_id = ?";
            $update_dest_stmt = $mysqli->prepare($update_dest_sql);
            $update_dest_stmt->bind_param("iii", $new_dest_quantity, $transfer_item_id, $transfer_to_location);
        } else {
            // Insert new record
            $update_dest_sql = "INSERT INTO inventory_location_items (item_id, location_id, quantity, low_stock_alert) VALUES (?, ?, ?, 0)";
            $update_dest_stmt = $mysqli->prepare($update_dest_sql);
            $update_dest_stmt->bind_param("iii", $transfer_item_id, $transfer_to_location, $new_dest_quantity);
        }
        
        if (!$update_dest_stmt->execute()) {
            throw new Exception("Failed to update destination location stock: " . $update_dest_stmt->error);
        }
        $update_dest_stmt->close();
        
        // Update main item quantity
        $total_quantity_sql = "SELECT COALESCE(SUM(quantity), 0) as total_quantity FROM inventory_location_items WHERE item_id = ?";
        $total_stmt = $mysqli->prepare($total_quantity_sql);
        $total_stmt->bind_param("i", $transfer_item_id);
        $total_stmt->execute();
        $total_result = $total_stmt->get_result();
        $total_data = $total_result->fetch_assoc();
        $total_stmt->close();
        
        $new_total_quantity = $total_data['total_quantity'];
        $new_status = calculateStockStatus($new_total_quantity, $transfer_item_id);
        
        $update_item_sql = "UPDATE inventory_items SET item_quantity = ?, item_status = ?, item_updated_by = ?, item_updated_date = NOW() WHERE item_id = ?";
        $update_item_stmt = $mysqli->prepare($update_item_sql);
        $update_item_stmt->bind_param("isii", $new_total_quantity, $new_status, $session_user_id, $transfer_item_id);
        
        if (!$update_item_stmt->execute()) {
            throw new Exception("Failed to update item total quantity: " . $update_item_stmt->error);
        }
        $update_item_stmt->close();
        
        // Record transfer transaction (OUT)
        $transaction_reference = "XFER-" . date('Ymd-His');
        $transaction_notes = "Stock transfer: " . $transfer_quantity . " units from " . $from_location_name . " to " . $to_location_name;
        if ($transfer_notes) {
            $transaction_notes .= " - " . $transfer_notes;
        }
        
        // FIX 1: Include transaction_date in the INSERT statement
        $transaction_sql = "INSERT INTO inventory_transactions (
            item_id, transaction_type, quantity_change, previous_quantity, new_quantity,
            transaction_reference, transaction_notes, performed_by, location_id,
            transaction_date, created_at
        ) VALUES (?, 'transfer_out', ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
        
        $trans_stmt = $mysqli->prepare($transaction_sql);
        $trans_stmt->bind_param(
            "iiiissii",
            $transfer_item_id,
            $transfer_quantity,
            $current_source_quantity,
            $new_source_quantity,
            $transaction_reference,
            $transaction_notes,
            $session_user_id,
            $transfer_from_location
        );
        
        if (!$trans_stmt->execute()) {
            throw new Exception("Failed to record transfer transaction: " . $trans_stmt->error);
        }
        $trans_stmt->close();
        
        // Record receiving transaction (IN)
        $transaction_sql2 = "INSERT INTO inventory_transactions (
            item_id, transaction_type, quantity_change, previous_quantity, new_quantity,
            transaction_reference, transaction_notes, performed_by, location_id,
            transaction_date, created_at
        ) VALUES (?, 'transfer_in', ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
        
        $trans_stmt2 = $mysqli->prepare($transaction_sql2);
        $trans_stmt2->bind_param(
            "iiiissii",
            $transfer_item_id,
            $transfer_quantity,
            $current_dest_quantity,
            $new_dest_quantity,
            $transaction_reference,
            $transaction_notes,
            $session_user_id,
            $transfer_to_location
        );
        
        if (!$trans_stmt2->execute()) {
            throw new Exception("Failed to record receiving transaction: " . $trans_stmt2->error);
        }
        $trans_stmt2->close();
        
        // FIX 2: Log to inventory_logs table instead of general logs table
        $log_sql = "INSERT INTO inventory_logs (
            item_id, adjustment_type, quantity_change, new_quantity, reason, created_by
        ) VALUES (?, 'adjustment', ?, ?, ?, ?)";
        $log_stmt = $mysqli->prepare($log_sql);
        
        // Calculate the net change (0 for transfer since total quantity doesn't change)
        $net_change = 0;
        
        $log_reason = "Stock transfer: " . $transfer_quantity . " units of " . $item_details['item_name'] . 
                     " from " . $from_location_name . " to " . $to_location_name;
        if ($transfer_notes) {
            $log_reason .= " - " . $transfer_notes;
        }
        
        $log_stmt->bind_param("iiisi", 
            $transfer_item_id,
            $net_change,
            $new_total_quantity,
            $log_reason,
            $session_user_id
        );
        
        if (!$log_stmt->execute()) {
            throw new Exception("Failed to log inventory action: " . $log_stmt->error);
        }
        $log_stmt->close();
        
        $mysqli->commit();
        
        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Stock transfer completed successfully!";
        
        header("Location: inventory_item_details.php?item_id=" . $transfer_item_id);
        exit;
        
    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error transferring stock: " . $e->getMessage();
        header("Location: inventory_transfer.php?item_id=" . $transfer_item_id);
        exit;
    }
}

function calculateStockStatus($quantity, $item_id) {
    global $mysqli;
    
    $sql = "SELECT item_low_stock_alert FROM inventory_items WHERE item_id = ?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("i", $item_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $item = $result->fetch_assoc();
    $stmt->close();
    
    $low_stock_alert = $item['item_low_stock_alert'] ?? 10;
    
    if ($quantity <= 0) {
        return 'Out of Stock';
    } elseif ($quantity <= $low_stock_alert) {
        return 'Low Stock';
    } else {
        return 'In Stock';
    }
}
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0 text-white">
                <i class="fas fa-fw fa-truck mr-2"></i>
                <?php echo $from_location ? 'Transfer Stock From Location' : 'Transfer Stock'; ?>
            </h3>
            <div class="card-tools">
                <?php if ($item_id): ?>
                    <a href="inventory_item_details.php?item_id=<?php echo $item_id; ?>" class="btn btn-light">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Item
                    </a>
                <?php else: ?>
                    <a href="inventory_dashboard.php" class="btn btn-light">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
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

        <form method="POST" id="transferForm">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="item_id" value="<?php echo $item_id; ?>">
            
            <div class="row">
                <div class="col-md-8">
                    <!-- Transfer Details -->
                    <div class="card card-primary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-exchange-alt mr-2"></i>Transfer Details</h3>
                        </div>
                        <div class="card-body">
                            <?php if ($item): ?>
                                <!-- Item Information -->
                                <div class="alert alert-info">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <strong><?php echo htmlspecialchars($item['item_name']); ?></strong>
                                            <br>
                                            <small class="text-muted">
                                                Code: <?php echo htmlspecialchars($item['item_code']); ?> | 
                                                Category: <?php echo htmlspecialchars($item['category_name'] ?? 'N/A'); ?>
                                            </small>
                                        </div>
                                        <div class="text-right">
                                            <span class="badge badge-primary">Current Total Stock: <?php echo $item['item_quantity']; ?></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="from_location_id">Source Location *</label>
                                        <select class="form-control select2" id="from_location_id" name="from_location_id" required>
                                            <option value="">- Select Source Location -</option>
                                            <?php foreach ($locations as $location): ?>
                                                <?php 
                                                $selected = ($from_location == $location['location_id']) ? 'selected' : '';
                                                $stock_info = '';
                                                
                                                if ($item_id) {
                                                    // Get current stock for this location
                                                    $stock_sql = "SELECT quantity FROM inventory_location_items WHERE item_id = ? AND location_id = ?";
                                                    $stock_stmt = $mysqli->prepare($stock_sql);
                                                    $stock_stmt->bind_param("ii", $item_id, $location['location_id']);
                                                    $stock_stmt->execute();
                                                    $stock_result = $stock_stmt->get_result();
                                                    $stock_data = $stock_result->fetch_assoc();
                                                    $stock_quantity = $stock_data['quantity'] ?? 0;
                                                    $stock_stmt->close();
                                                    
                                                    $stock_info = " (Stock: " . $stock_quantity . ")";
                                                }
                                                ?>
                                                <option value="<?php echo $location['location_id']; ?>" <?php echo $selected; ?> 
                                                        data-stock="<?php echo $stock_quantity ?? 0; ?>">
                                                    <?php echo htmlspecialchars($location['location_type'] . ' - ' . $location['location_name'] . $stock_info); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="form-text text-muted">Location where stock is currently stored</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="to_location_id">Destination Location *</label>
                                        <select class="form-control select2" id="to_location_id" name="to_location_id" required>
                                            <option value="">- Select Destination Location -</option>
                                            <?php foreach ($locations as $location): ?>
                                                <?php if ($location['location_id'] != $from_location): ?>
                                                    <option value="<?php echo $location['location_id']; ?>">
                                                        <?php echo htmlspecialchars($location['location_type'] . ' - ' . $location['location_name']); ?>
                                                    </option>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="form-text text-muted">Location where stock will be moved to</small>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="quantity">Quantity to Transfer *</label>
                                        <input type="number" class="form-control" id="quantity" name="quantity" 
                                               min="1" value="1" required>
                                        <small class="form-text text-muted">Number of units to transfer</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="available_stock">Available Stock</label>
                                        <input type="text" class="form-control" id="available_stock" readonly 
                                               value="Select source location to see available stock">
                                        <small class="form-text text-muted">Current stock in selected source location</small>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="notes">Transfer Notes</label>
                                <textarea class="form-control" id="notes" name="notes" rows="3" 
                                          placeholder="Reason for transfer, special instructions, etc..." maxlength="500"></textarea>
                                <small class="form-text text-muted">Optional notes about this transfer</small>
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
                                    <i class="fas fa-truck-loading mr-2"></i>Transfer Stock
                                </button>
                                <?php if ($item_id): ?>
                                    <a href="inventory_item_details.php?item_id=<?php echo $item_id; ?>" class="btn btn-outline-secondary">
                                        <i class="fas fa-times mr-2"></i>Cancel
                                    </a>
                                <?php else: ?>
                                    <a href="inventory_dashboard.php" class="btn btn-outline-secondary">
                                        <i class="fas fa-times mr-2"></i>Cancel
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Transfer Preview -->
                    <div class="card card-info">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-eye mr-2"></i>Transfer Preview</h3>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-3">
                                <i class="fas fa-truck fa-2x text-info mb-2"></i>
                                <h6>Stock Transfer Summary</h6>
                            </div>
                            <hr>
                            <div class="small">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Item:</span>
                                    <span id="preview_item" class="font-weight-bold">
                                        <?php echo $item ? htmlspecialchars($item['item_name']) : 'Not selected'; ?>
                                    </span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>From:</span>
                                    <span id="preview_from" class="font-weight-bold">-</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>To:</span>
                                    <span id="preview_to" class="font-weight-bold">-</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Quantity:</span>
                                    <span id="preview_quantity" class="font-weight-bold">0</span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Status:</span>
                                    <span id="preview_status" class="font-weight-bold text-warning">Pending</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Transfer Guidelines -->
                    <div class="card card-warning">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-info-circle mr-2"></i>Transfer Guidelines</h3>
                        </div>
                        <div class="card-body">
                            <div class="callout callout-info small mb-3">
                                <i class="fas fa-info-circle mr-2"></i>
                                <strong>Stock Availability:</strong> Ensure sufficient stock exists in the source location before transferring.
                            </div>
                            <div class="callout callout-warning small mb-3">
                                <i class="fas fa-exclamation-triangle mr-2"></i>
                                <strong>Location Restrictions:</strong> Some locations may have specific storage requirements.
                            </div>
                            <div class="callout callout-success small">
                                <i class="fas fa-check-circle mr-2"></i>
                                <strong>Audit Trail:</strong> All transfers are logged for inventory tracking and audit purposes.
                            </div>
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

    // Update available stock when source location changes
    $('#from_location_id').on('change', function() {
        var selectedOption = $(this).find('option:selected');
        var availableStock = selectedOption.data('stock') || 0;
        var locationName = selectedOption.text().split(' (Stock:')[0];
        
        $('#available_stock').val(availableStock);
        $('#preview_from').text(locationName);
        
        // Update quantity max value
        $('#quantity').attr('max', availableStock);
        
        // Update preview status
        updatePreviewStatus();
    });

    // Update destination in preview
    $('#to_location_id').on('change', function() {
        var selectedOption = $(this).find('option:selected');
        var locationName = selectedOption.text();
        $('#preview_to').text(locationName);
        updatePreviewStatus();
    });

    // Update quantity in preview
    $('#quantity').on('input', function() {
        var quantity = parseInt($(this).val()) || 0;
        $('#preview_quantity').text(quantity);
        updatePreviewStatus();
    });

    function updatePreviewStatus() {
        var fromLocation = $('#from_location_id').val();
        var toLocation = $('#to_location_id').val();
        var quantity = parseInt($('#quantity').val()) || 0;
        var availableStock = parseInt($('#available_stock').val()) || 0;
        
        var statusElement = $('#preview_status');
        
        if (!fromLocation || !toLocation || quantity <= 0) {
            statusElement.text('Pending').removeClass('text-success text-danger').addClass('text-warning');
        } else if (quantity > availableStock) {
            statusElement.text('Insufficient Stock').removeClass('text-success text-warning').addClass('text-danger');
        } else {
            statusElement.text('Ready to Transfer').removeClass('text-warning text-danger').addClass('text-success');
        }
    }

    // Form validation
    $('#transferForm').on('submit', function(e) {
        var fromLocation = $('#from_location_id').val();
        var toLocation = $('#to_location_id').val();
        var quantity = parseInt($('#quantity').val()) || 0;
        var availableStock = parseInt($('#available_stock').val()) || 0;
        
        if (!fromLocation || !toLocation || quantity <= 0) {
            e.preventDefault();
            alert('Please fill in all required fields with valid values.');
            return false;
        }
        
        if (fromLocation === toLocation) {
            e.preventDefault();
            alert('Source and destination locations cannot be the same.');
            return false;
        }
        
        if (quantity > availableStock) {
            e.preventDefault();
            alert('Insufficient stock in source location. Available: ' + availableStock);
            return false;
        }
        
        // Show loading state
        $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Transferring...').prop('disabled', true);
    });

    // Set initial preview values
    <?php if ($from_location_data): ?>
        $('#preview_from').text('<?php echo htmlspecialchars($from_location_data["location_type"] . " - " . $from_location_data["location_name"]); ?>');
        $('#available_stock').val('<?php echo $from_location_data["current_stock"] ?? 0; ?>');
        $('#quantity').attr('max', '<?php echo $from_location_data["current_stock"] ?? 0; ?>');
    <?php endif; ?>

    <?php if ($item): ?>
        $('#preview_item').text('<?php echo htmlspecialchars($item["item_name"]); ?>');
    <?php endif; ?>
});

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + S to save
    if (e.ctrlKey && e.keyCode === 83) {
        e.preventDefault();
        $('#transferForm').submit();
    }
    // Escape to cancel
    if (e.keyCode === 27) {
        <?php if ($item_id): ?>
            window.location.href = 'inventory_item_details.php?item_id=<?php echo $item_id; ?>';
        <?php else: ?>
            window.location.href = 'inventory_dashboard.php';
        <?php endif; ?>
    }
});
</script>

<style>
.callout {
    border-left: 3px solid #eee;
    margin-bottom: 10px;
    padding: 10px 15px;
    border-radius: 0.25rem;
}

.callout-info {
    border-left-color: #17a2b8;
    background-color: #f8f9fa;
}

.callout-warning {
    border-left-color: #ffc107;
    background-color: #fffbf0;
}

.callout-success {
    border-left-color: #28a745;
    background-color: #f0fff4;
}

.select2-container .select2-selection--single {
    height: 38px;
}

.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 36px;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
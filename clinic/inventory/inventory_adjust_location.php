<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Get location_item_id from URL
$location_item_id = intval($_GET['location_item_id'] ?? 0);

if ($location_item_id <= 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid location item ID.";
    header("Location: inventory_dashboard.php");
    exit;
}

// Get location item details
$location_item_sql = "SELECT ili.*, i.item_name, i.item_code, i.item_id, i.item_low_stock_alert,
                             il.location_name, il.location_type, il.location_id
                      FROM inventory_location_items ili
                      LEFT JOIN inventory_items i ON ili.item_id = i.item_id
                      LEFT JOIN inventory_locations il ON ili.location_id = il.location_id
                      WHERE ili.location_item_id = ?";
$location_item_stmt = $mysqli->prepare($location_item_sql);
$location_item_stmt->bind_param("i", $location_item_id);
$location_item_stmt->execute();
$location_item_result = $location_item_stmt->get_result();

if ($location_item_result->num_rows === 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Location item not found.";
    header("Location: inventory_dashboard.php");
    exit;
}

$location_item = $location_item_result->fetch_assoc();
$location_item_stmt->close();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    $adjustment_type = sanitizeInput($_POST['adjustment_type']);
    $quantity = intval($_POST['quantity']);
    $notes = sanitizeInput($_POST['notes'] ?? '');
    
    // Validate CSRF token
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        header("Location: inventory_adjust_location.php?location_item_id=" . $location_item_id);
        exit;
    }
    
    // Validate required fields
    if ($quantity <= 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Quantity must be greater than zero.";
        header("Location: inventory_adjust_location.php?location_item_id=" . $location_item_id);
        exit;
    }
    
    // Start transaction
    $mysqli->begin_transaction();
    
    try {
        $current_quantity = $location_item['quantity'];
        $item_id = $location_item['item_id'];
        $location_id = $location_item['location_id'];
        
        // Calculate new quantity
        if ($adjustment_type === 'add') {
            $new_quantity = $current_quantity + $quantity;
            $transaction_type = 'adjustment_in';
            $quantity_change = $quantity;
        } else {
            $new_quantity = $current_quantity - $quantity;
            $transaction_type = 'adjustment_out';
            $quantity_change = -$quantity;
            
            if ($new_quantity < 0) {
                throw new Exception("Cannot remove more items than available in stock. Available: " . $current_quantity);
            }
        }
        
        // Update location item quantity
        $update_sql = "UPDATE inventory_location_items SET quantity = ?, updated_at = NOW() WHERE location_item_id = ?";
        $update_stmt = $mysqli->prepare($update_sql);
        $update_stmt->bind_param("ii", $new_quantity, $location_item_id);
        
        if (!$update_stmt->execute()) {
            throw new Exception("Failed to update location stock: " . $update_stmt->error);
        }
        $update_stmt->close();
        
        // Update main item total quantity and status
        $total_quantity_sql = "SELECT COALESCE(SUM(quantity), 0) as total_quantity FROM inventory_location_items WHERE item_id = ?";
        $total_stmt = $mysqli->prepare($total_quantity_sql);
        $total_stmt->bind_param("i", $item_id);
        $total_stmt->execute();
        $total_result = $total_stmt->get_result();
        $total_data = $total_result->fetch_assoc();
        $total_stmt->close();
        
        $new_total_quantity = $total_data['total_quantity'];
        $new_status = calculateStockStatus($new_total_quantity, $item_id);
        
        $update_item_sql = "UPDATE inventory_items SET item_quantity = ?, item_status = ?, item_updated_by = ?, item_updated_date = NOW() WHERE item_id = ?";
        $update_item_stmt = $mysqli->prepare($update_item_sql);
        $update_item_stmt->bind_param("isii", $new_total_quantity, $new_status, $session_user_id, $item_id);
        
        if (!$update_item_stmt->execute()) {
            throw new Exception("Failed to update item total quantity: " . $update_item_stmt->error);
        }
        $update_item_stmt->close();
        
        // Record transaction
        $transaction_reference = "ADJ-" . strtoupper($adjustment_type) . "-" . date('Ymd-His');
        $transaction_notes = "Stock adjustment: " . $quantity . " units " . ($adjustment_type === 'add' ? 'added to' : 'removed from') . " location";
        if ($notes) {
            $transaction_notes .= " - " . $notes;
        }
        
        $transaction_sql = "INSERT INTO inventory_transactions (
            item_id, transaction_type, quantity_change, previous_quantity, new_quantity,
            transaction_reference, transaction_notes, performed_by, location_id
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $trans_stmt = $mysqli->prepare($transaction_sql);
        $trans_stmt->bind_param(
            "isiiissii",
            $item_id,
            $transaction_type,
            $quantity_change,
            $current_quantity,
            $new_quantity,
            $transaction_reference,
            $transaction_notes,
            $session_user_id,
            $location_id
        );
        
        if (!$trans_stmt->execute()) {
            throw new Exception("Failed to record adjustment transaction: " . $trans_stmt->error);
        }
        $trans_stmt->close();
        
        // Log the action
        $log_sql = "INSERT INTO logs SET
                  log_type = 'Inventory',
                  log_action = 'Adjust',
                  log_description = ?,
                  log_ip = ?,
                  log_user_agent = ?,
                  log_user_id = ?,
                  log_entity_id = ?,
                  log_created_at = NOW()";
        $log_stmt = $mysqli->prepare($log_sql);
        $log_description = "Adjusted stock for " . $location_item['item_name'] . " at " . $location_item['location_name'] . ": " . $quantity . " units " . ($adjustment_type === 'add' ? 'added' : 'removed');
        $log_stmt->bind_param("sssii", $log_description, $session_ip, $session_user_agent, $session_user_id, $item_id);
        $log_stmt->execute();
        $log_stmt->close();
        
        $mysqli->commit();
        
        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Stock adjustment completed successfully!";
        
        header("Location: inventory_item_details.php?item_id=" . $item_id);
        exit;
        
    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error adjusting stock: " . $e->getMessage();
        header("Location: inventory_adjust_location.php?location_item_id=" . $location_item_id);
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
    <div class="card-header bg-warning py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0 text-white">
                <i class="fas fa-fw fa-edit mr-2"></i>Adjust Stock in Location
            </h3>
            <div class="card-tools">
                <a href="inventory_item_details.php?item_id=<?php echo $location_item['item_id']; ?>" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Item
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

        <form method="POST" id="adjustForm">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <div class="row">
                <div class="col-md-8">
                    <!-- Adjustment Details -->
                    <div class="card card-primary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-sliders-h mr-2"></i>Adjustment Details</h3>
                        </div>
                        <div class="card-body">
                            <!-- Item and Location Information -->
                            <div class="alert alert-info">
                                <div class="row">
                                    <div class="col-md-6">
                                        <strong>Item Information</strong><br>
                                        <i class="fas fa-cube mr-2"></i><?php echo htmlspecialchars($location_item['item_name']); ?><br>
                                        <small class="text-muted">Code: <?php echo htmlspecialchars($location_item['item_code']); ?></small>
                                    </div>
                                    <div class="col-md-6">
                                        <strong>Location Information</strong><br>
                                        <i class="fas fa-map-marker-alt mr-2"></i><?php echo htmlspecialchars($location_item['location_type'] . ' - ' . $location_item['location_name']); ?><br>
                                        <small class="text-muted">Current Stock: <strong><?php echo $location_item['quantity']; ?></strong></small>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="adjustment_type">Adjustment Type *</label>
                                        <select class="form-control" id="adjustment_type" name="adjustment_type" required>
                                            <option value="add">Add Stock</option>
                                            <option value="remove">Remove Stock</option>
                                        </select>
                                        <small class="form-text text-muted">Choose whether to add or remove stock</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="quantity">Quantity *</label>
                                        <input type="number" class="form-control" id="quantity" name="quantity" 
                                               min="1" value="1" required>
                                        <small class="form-text text-muted">Number of units to adjust</small>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="notes">Adjustment Reason</label>
                                <textarea class="form-control" id="notes" name="notes" rows="3" 
                                          placeholder="Reason for adjustment (e.g., found discrepancy, damage, etc.)" 
                                          maxlength="500"></textarea>
                                <small class="form-text text-muted">Explain why this adjustment is necessary</small>
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
                                    <i class="fas fa-check mr-2"></i>Apply Adjustment
                                </button>
                                <a href="inventory_item_details.php?item_id=<?php echo $location_item['item_id']; ?>" class="btn btn-outline-secondary">
                                    <i class="fas fa-times mr-2"></i>Cancel
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Adjustment Preview -->
                    <div class="card card-info">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-eye mr-2"></i>Adjustment Preview</h3>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-3">
                                <i class="fas fa-balance-scale fa-2x text-info mb-2"></i>
                                <h6>Stock Adjustment Summary</h6>
                            </div>
                            <hr>
                            <div class="small">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Current Stock:</span>
                                    <span class="font-weight-bold"><?php echo $location_item['quantity']; ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Adjustment:</span>
                                    <span id="preview_adjustment" class="font-weight-bold">+0</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>New Stock:</span>
                                    <span id="preview_new_stock" class="font-weight-bold"><?php echo $location_item['quantity']; ?></span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Status:</span>
                                    <span id="preview_status" class="font-weight-bold text-warning">Pending</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Adjustment Guidelines -->
                    <div class="card card-warning">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-info-circle mr-2"></i>Adjustment Guidelines</h3>
                        </div>
                        <div class="card-body">
                            <div class="callout callout-info small mb-3">
                                <i class="fas fa-info-circle mr-2"></i>
                                <strong>Add Stock:</strong> Use for found items, returns, or corrections to inventory counts.
                            </div>
                            <div class="callout callout-warning small mb-3">
                                <i class="fas fa-exclamation-triangle mr-2"></i>
                                <strong>Remove Stock:</strong> Use for damaged goods, theft, or counting errors.
                            </div>
                            <div class="callout callout-success small">
                                <i class="fas fa-check-circle mr-2"></i>
                                <strong>Documentation:</strong> Always provide a reason for adjustments for audit purposes.
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
    // Update preview when adjustment type or quantity changes
    function updatePreview() {
        var adjustmentType = $('#adjustment_type').val();
        var quantity = parseInt($('#quantity').val()) || 0;
        var currentStock = <?php echo $location_item['quantity']; ?>;
        
        var adjustmentText = (adjustmentType === 'add' ? '+' : '-') + quantity;
        var newStock = adjustmentType === 'add' ? currentStock + quantity : currentStock - quantity;
        
        $('#preview_adjustment').text(adjustmentText).removeClass('text-success text-danger')
            .addClass(adjustmentType === 'add' ? 'text-success' : 'text-danger');
        
        $('#preview_new_stock').text(newStock);
        
        // Update status
        var statusElement = $('#preview_status');
        if (quantity <= 0) {
            statusElement.text('Invalid Quantity').removeClass('text-success text-warning').addClass('text-danger');
        } else if (adjustmentType === 'remove' && newStock < 0) {
            statusElement.text('Insufficient Stock').removeClass('text-success text-warning').addClass('text-danger');
        } else {
            statusElement.text('Ready to Adjust').removeClass('text-warning text-danger').addClass('text-success');
        }
    }
    
    $('#adjustment_type, #quantity').on('change input', updatePreview);
    
    // Initial preview update
    updatePreview();
    
    // Form validation
    $('#adjustForm').on('submit', function(e) {
        var adjustmentType = $('#adjustment_type').val();
        var quantity = parseInt($('#quantity'].val()) || 0;
        var currentStock = <?php echo $location_item['quantity']; ?>;
        
        if (quantity <= 0) {
            e.preventDefault();
            alert('Quantity must be greater than zero.');
            return false;
        }
        
        if (adjustmentType === 'remove' && quantity > currentStock) {
            e.preventDefault();
            alert('Cannot remove more items than available in stock. Available: ' + currentStock);
            return false;
        }
        
        // Show loading state
        $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Processing...').prop('disabled', true);
    });
});

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + S to save
    if (e.ctrlKey && e.keyCode === 83) {
        e.preventDefault();
        $('#adjustForm').submit();
    }
    // Escape to cancel
    if (e.keyCode === 27) {
        window.location.href = 'inventory_item_details.php?item_id=<?php echo $location_item['item_id']; ?>';
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
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
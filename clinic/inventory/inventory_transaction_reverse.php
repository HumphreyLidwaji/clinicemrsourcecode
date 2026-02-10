<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Get transaction ID to reverse
$transaction_id = intval($_GET['id'] ?? 0);

if (!$transaction_id) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "No transaction specified.";
    header("Location: inventory_transactions.php");
    exit;
}

// Get the transaction details
$transaction_sql = "SELECT t.*, i.item_name, i.item_code, i.item_quantity as current_stock,
                           i.location_id as current_location_id,
                           l1.location_name as from_location_name,
                           l2.location_name as to_location_name,
                           u.user_name as original_performed_by
                    FROM inventory_transactions t
                    JOIN inventory_items i ON t.item_id = i.item_id
                    LEFT JOIN inventory_locations l1 ON t.from_location_id = l1.location_id
                    LEFT JOIN inventory_locations l2 ON t.to_location_id = l2.location_id
                    LEFT JOIN users u ON t.performed_by = u.user_id
                    WHERE t.transaction_id = ?";
$transaction_stmt = $mysqli->prepare($transaction_sql);
$transaction_stmt->bind_param("i", $transaction_id);
$transaction_stmt->execute();
$transaction_result = $transaction_stmt->get_result();

if ($transaction_result->num_rows === 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Transaction not found.";
    header("Location: inventory_transactions.php");
    exit;
}

$original_transaction = $transaction_result->fetch_assoc();
$transaction_stmt->close();

// Check if already reversed
$reversal_check_sql = "SELECT transaction_id FROM inventory_transactions 
                       WHERE original_transaction_id = ?";
$reversal_check_stmt = $mysqli->prepare($reversal_check_sql);
$reversal_check_stmt->bind_param("i", $transaction_id);
$reversal_check_stmt->execute();
$reversal_check_result = $reversal_check_stmt->get_result();

if ($reversal_check_result->num_rows > 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "This transaction has already been reversed.";
    header("Location: inventory_transactions.php");
    exit;
}
$reversal_check_stmt->close();

// Check if adjustment (adjustments cannot be reversed)
if ($original_transaction['transaction_type'] === 'adjustment') {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Adjustment transactions cannot be reversed.";
    header("Location: inventory_transactions.php");
    exit;
}

// Check if transfer_in (should reverse the transfer_out instead)
if ($original_transaction['transaction_type'] === 'transfer_in') {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Please reverse the corresponding transfer_out transaction instead.";
    header("Location: inventory_transactions.php");
    exit;
}

// Start transaction for reversal
$mysqli->begin_transaction();

try {
    $item_id = $original_transaction['item_id'];
    $original_quantity_change = $original_transaction['quantity_change'];
    $reversal_quantity_change = -$original_quantity_change; // Opposite sign
    
    // Get current item details
    $current_sql = "SELECT item_quantity, location_id, item_low_stock_alert 
                    FROM inventory_items 
                    WHERE item_id = ?";
    $current_stmt = $mysqli->prepare($current_sql);
    $current_stmt->bind_param("i", $item_id);
    $current_stmt->execute();
    $current_result = $current_stmt->get_result();
    $current_item = $current_result->fetch_assoc();
    $current_stmt->close();
    
    $current_quantity = $current_item['item_quantity'];
    $current_location_id = $current_item['location_id'];
    $low_stock_alert = $current_item['item_low_stock_alert'];
    
    // Determine new quantity after reversal
    $new_quantity = $current_quantity + $reversal_quantity_change;
    
    // Validate that reversal won't cause negative stock
    if ($new_quantity < 0) {
        throw new Exception("Reversal would result in negative stock. Current: " . $current_quantity . ", Reversal: " . $reversal_quantity_change);
    }
    
    // Determine reversal type based on original transaction type
    $reversal_type = '';
    $reversal_description = '';
    
    switch ($original_transaction['transaction_type']) {
        case 'in':
        case 'purchase_in':
        case 'manual_in':
        case 'requisition_in':
        case 'transfer_in':
            // Reverse receiving by issuing
            $reversal_type = 'reversal_out';
            $reversal_description = "Reversal of receiving";
            break;
            
        case 'out':
        case 'manual_out':
        case 'requisition_out':
        case 'expired_out':
        case 'transfer_out':
            // Reverse issuing by receiving
            $reversal_type = 'reversal_in';
            $reversal_description = "Reversal of issuing";
            break;
            
        case 'transfer':
            // Reverse transfer by opposite transfer
            $reversal_type = 'transfer_reversal';
            $reversal_description = "Reversal of transfer";
            break;
            
        default:
            throw new Exception("Cannot reverse transaction type: " . $original_transaction['transaction_type']);
    }
    
    // Determine new status
    $new_status = 'In Stock';
    if ($new_quantity <= 0) {
        $new_status = 'Out of Stock';
    } elseif ($new_quantity <= $low_stock_alert) {
        $new_status = 'Low Stock';
    }
    
    // Update main inventory item
    $update_sql = "UPDATE inventory_items SET 
                  item_quantity = ?, 
                  item_status = ?,
                  item_updated_date = NOW()
                  WHERE item_id = ?";
    $update_stmt = $mysqli->prepare($update_sql);
    $update_stmt->bind_param("isi", $new_quantity, $new_status, $item_id);
    
    if (!$update_stmt->execute()) {
        throw new Exception("Failed to update inventory item: " . $update_stmt->error);
    }
    $update_stmt->close();
    
    // Update inventory_location_items if needed
    // (This would depend on your specific location tracking logic)
    
    // For transfers, handle location updates
    if ($original_transaction['transaction_type'] === 'transfer') {
        // Swap from and to locations for reversal
        $reversal_from_location_id = $original_transaction['to_location_id'];
        $reversal_to_location_id = $original_transaction['from_location_id'];
        
        // Update source location (add back)
        if ($reversal_from_location_id) {
            $location_sql = "INSERT INTO inventory_location_items (item_id, location_id, quantity, low_stock_alert) 
                            VALUES (?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE 
                            quantity = quantity + VALUES(quantity),
                            updated_at = NOW()";
            $location_stmt = $mysqli->prepare($location_sql);
            $abs_quantity = abs($reversal_quantity_change);
            $location_stmt->bind_param("iiii", $item_id, $reversal_from_location_id, $abs_quantity, $low_stock_alert);
            $location_stmt->execute();
            $location_stmt->close();
        }
        
        // Update destination location (remove)
        if ($reversal_to_location_id) {
            $location_sql = "UPDATE inventory_location_items 
                            SET quantity = quantity - ?,
                                updated_at = NOW()
                            WHERE item_id = ? AND location_id = ?";
            $location_stmt = $mysqli->prepare($location_sql);
            $abs_quantity = abs($reversal_quantity_change);
            $location_stmt->bind_param("iii", $abs_quantity, $item_id, $reversal_to_location_id);
            $location_stmt->execute();
            $location_stmt->close();
        }
    }
    
    // Generate reversal reference
    $reversal_reference = 'REV-' . $original_transaction['transaction_reference'];
    
    // Record reversal transaction
    $reversal_sql = "INSERT INTO inventory_transactions (
        item_id, transaction_type, quantity_change, previous_quantity, new_quantity,
        transaction_reference, transaction_notes, performed_by, 
        from_location_id, to_location_id, requisition_id, purchase_order_id,
        original_transaction_id, transaction_date
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
    
    $reversal_stmt = $mysqli->prepare($reversal_sql);
    
    // For reversal, swap locations if it was a transfer
    if ($original_transaction['transaction_type'] === 'transfer') {
        $reversal_from = $original_transaction['to_location_id'];
        $reversal_to = $original_transaction['from_location_id'];
    } else {
        $reversal_from = $original_transaction['from_location_id'];
        $reversal_to = $original_transaction['to_location_id'];
    }
    
    $reversal_notes = "Reversal of transaction #" . $transaction_id . ". " . 
                     $original_transaction['transaction_notes'];
    
    $reversal_stmt->bind_param(
        "isiiissiiiiii",
        $item_id, $reversal_type, $reversal_quantity_change, $current_quantity, $new_quantity,
        $reversal_reference, $reversal_notes, $session_user_id,
        $reversal_from, $reversal_to, 
        $original_transaction['requisition_id'], $original_transaction['purchase_order_id'],
        $transaction_id
    );
    
    if (!$reversal_stmt->execute()) {
        throw new Exception("Failed to record reversal transaction: " . $reversal_stmt->error);
    }
    $reversal_transaction_id = $reversal_stmt->insert_id;
    $reversal_stmt->close();
    
    // Log the reversal
    $log_sql = "INSERT INTO logs SET
              log_type = 'Inventory',
              log_action = 'Reversal',
              log_description = ?,
              log_ip = ?,
              log_user_agent = ?,
              log_user_id = ?,
              log_entity_id = ?,
              log_created_at = NOW()";
    $log_stmt = $mysqli->prepare($log_sql);
    $log_description = "Reversed transaction #" . $transaction_id . 
                      " for item #" . $item_id . " (" . $original_transaction['item_name'] . ")";
    $log_stmt->bind_param("sssii", $log_description, $session_ip, $session_user_agent, $session_user_id, $reversal_transaction_id);
    $log_stmt->execute();
    $log_stmt->close();
    
    $mysqli->commit();
    
    $_SESSION['alert_type'] = "success";
    $_SESSION['alert_message'] = "Transaction #" . $transaction_id . " reversed successfully! " . 
                                abs($reversal_quantity_change) . " units " . 
                                ($reversal_quantity_change > 0 ? "added back to" : "removed from") . 
                                " inventory.";
    
    // Send notification if needed
    if ($original_transaction['performed_by'] != $session_user_id) {
        $notification_sql = "INSERT INTO notifications SET
                           user_id = ?,
                           notification_type = 'inventory_reversal',
                           notification_title = 'Transaction Reversed',
                           notification = ?,
                           notification_timestamp = NOW(),
                           notification_read = 0";
        $notification_stmt = $mysqli->prepare($notification_sql);
        $notification_message = "Your transaction #" . $transaction_id . " for " . 
                               $original_transaction['item_name'] . " was reversed by " . 
                               $session_user_name;
        $notification_stmt->bind_param("is", $original_transaction['performed_by'], $notification_message);
        $notification_stmt->execute();
        $notification_stmt->close();
    }
    
    header("Location: inventory_transactions.php");
    exit;

} catch (Exception $e) {
    $mysqli->rollback();
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Error reversing transaction: " . $e->getMessage();
    header("Location: inventory_transactions.php");
    exit;
}
?>



<div class="card">
    <div class="card-header bg-warning py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0 text-white">
                <i class="fas fa-fw fa-undo mr-2"></i>Reverse Transaction
            </h3>
            <div class="card-tools">
                <a href="inventory_transaction_view.php?id=<?php echo $transaction_id; ?>" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Transaction
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
                <!-- Reversal Confirmation -->
                <div class="card card-danger">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-exclamation-triangle mr-2"></i>Confirm Reversal</h3>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-warning">
                            <h5><i class="fas fa-exclamation-circle mr-2"></i>Warning: Critical Action</h5>
                            <p class="mb-2">You are about to reverse a transaction. This action will:</p>
                            <ul class="mb-3">
                                <li>Create an opposite transaction to cancel out the original</li>
                                <li>Adjust the current stock level accordingly</li>
                                <li>Update location-specific inventory if applicable</li>
                                <li>Create a permanent audit trail of the reversal</li>
                                <li><strong>This action cannot be undone</strong></li>
                            </ul>
                        </div>

                        <form method="POST" id="reversalForm" autocomplete="off">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            
                            <div class="form-group">
                                <label for="reversal_reason">Reason for Reversal *</label>
                                <textarea class="form-control" id="reversal_reason" name="reversal_reason" 
                                          rows="3" placeholder="Explain why this transaction needs to be reversed..." 
                                          required maxlength="500" minlength="10"></textarea>
                                <small class="form-text text-muted">Provide a clear explanation for audit purposes (minimum 10 characters).</small>
                            </div>

                            <div class="alert alert-info">
                                <h6><i class="fas fa-info-circle mr-2"></i>Reversal Impact Summary</h6>
                                <div class="row text-center mt-3">
                                    <div class="col-md-4">
                                        <div class="border rounded p-2 bg-light">
                                            <div class="h6 text-muted">Current Total Stock</div>
                                            <div class="h4 font-weight-bold text-primary"><?php echo $current_quantity; ?></div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="border rounded p-2 bg-light">
                                            <div class="h6 text-muted">Reversal Impact</div>
                                            <div class="h4 font-weight-bold text-<?php echo $reversal_quantity_change > 0 ? 'success' : 'danger'; ?>">
                                                <?php echo $reversal_quantity_change > 0 ? '+' : ''; ?><?php echo $reversal_quantity_change; ?>
                                            </div>
                                            <div class="small text-muted"><?php echo strtoupper($reversal_type); ?> Transaction</div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="border rounded p-2 bg-light">
                                            <div class="h6 text-muted">Projected Total Stock</div>
                                            <div class="h4 font-weight-bold text-success"><?php echo $projected_total_quantity; ?></div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Location-specific impact -->
                                <div class="mt-3">
                                    <h6><i class="fas fa-map-marker-alt mr-2"></i>Location Impact</h6>
                                    <div class="small">
                                        <?php if ($reversal_type === 'transfer_reverse'): ?>
                                            <p class="mb-2">Items will be transferred back from <strong><?php echo htmlspecialchars($to_location_name); ?></strong> to <strong><?php echo htmlspecialchars($from_location_name); ?></strong></p>
                                        <?php elseif ($reversal_type === 'out'): ?>
                                            <p class="mb-2">Items will be removed from <strong><?php echo htmlspecialchars($to_location_name ?: 'Main Location'); ?></strong></p>
                                        <?php elseif ($reversal_type === 'in'): ?>
                                            <p class="mb-2">Items will be returned to <strong><?php echo htmlspecialchars($from_location_name ?: 'Main Location'); ?></strong></p>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($location_quantities_before)): ?>
                                            <div class="mt-2">
                                                <strong>Current Location Quantities:</strong>
                                                <ul class="mb-2">
                                                    <?php foreach ($location_quantities_before as $loc_id => $qty): ?>
                                                        <li><?php echo htmlspecialchars($location_names[$loc_id] ?? "Location #$loc_id"); ?>: <?php echo $qty; ?> units</li>
                                                    <?php endforeach; ?>
                                                </ul>
                                                <strong>After Reversal:</strong>
                                                <ul>
                                                    <?php foreach ($location_quantities_after as $loc_id => $qty): ?>
                                                        <li><?php echo htmlspecialchars($location_names[$loc_id] ?? "Location #$loc_id"); ?>: <?php echo $qty; ?> units</li>
                                                    <?php endforeach; ?>
                                                    <?php if (empty($location_quantities_after)): ?>
                                                        <li>All locations will have 0 stock</li>
                                                    <?php endif; ?>
                                                </ul>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-danger btn-lg" id="submitReversal">
                                    <i class="fas fa-undo mr-2"></i>Confirm & Reverse Transaction
                                </button>
                                <a href="inventory_transaction_view.php?id=<?php echo $transaction_id; ?>" class="btn btn-outline-secondary">
                                    <i class="fas fa-times mr-2"></i>Cancel
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <!-- Original Transaction Details -->
                <div class="card card-info">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-receipt mr-2"></i>Original Transaction</h3>
                    </div>
                    <div class="card-body">
                        <div class="text-center mb-3">
                            <i class="fas fa-exchange-alt fa-3x text-info mb-2"></i>
                            <h5>Transaction #<?php echo $transaction_id; ?></h5>
                            <span class="badge badge-<?php 
                                echo in_array($original_transaction['transaction_type'], ['in', 'purchase_in', 'manual_in', 'requisition_in', 'transfer_in']) ? 'success' : 
                                    (in_array($original_transaction['transaction_type'], ['out', 'manual_out', 'requisition_out', 'transfer_out', 'expired_out']) ? 'danger' : 
                                    ($original_transaction['transaction_type'] === 'transfer' ? 'primary' : 'warning'));
                            ?>">
                                <?php echo strtoupper($original_transaction['transaction_type']); ?>
                            </span>
                        </div>
                        <div class="small">
                            <div class="d-flex justify-content-between mb-2">
                                <span>Item:</span>
                                <span class="font-weight-bold"><?php echo htmlspecialchars($original_transaction['item_name']); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Quantity:</span>
                                <span class="font-weight-bold"><?php echo $original_transaction['quantity_change']; ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Date:</span>
                                <span class="font-weight-bold"><?php echo date('M j, Y g:i A', strtotime($original_transaction['transaction_date'])); ?></span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span>Reference:</span>
                                <span class="font-weight-bold"><?php echo htmlspecialchars($original_transaction['transaction_reference']); ?></span>
                            </div>
                            
                            <?php if ($original_transaction['from_location_id']): ?>
                            <div class="d-flex justify-content-between mb-2">
                                <span>From Location:</span>
                                <span class="font-weight-bold"><?php echo htmlspecialchars($original_transaction['from_location_name']); ?></span>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($original_transaction['to_location_id']): ?>
                            <div class="d-flex justify-content-between mb-2">
                                <span>To Location:</span>
                                <span class="font-weight-bold"><?php echo htmlspecialchars($original_transaction['to_location_name']); ?></span>
                            </div>
                            <?php endif; ?>
                            
                            <div class="d-flex justify-content-between">
                                <span>Performed By:</span>
                                <span class="font-weight-bold"><?php echo htmlspecialchars($original_transaction['performed_by_name']); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Impact Analysis -->
                <div class="card card-warning">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-chart-bar mr-2"></i>Impact Analysis</h3>
                    </div>
                    <div class="card-body">
                        <div class="small">
                            <div class="d-flex justify-content-between mb-2">
                                <span>Current Total Stock:</span>
                                <span class="font-weight-bold"><?php echo $current_quantity; ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Reversal Change:</span>
                                <span class="font-weight-bold text-<?php echo $reversal_quantity_change > 0 ? 'success' : 'danger'; ?>">
                                    <?php echo $reversal_quantity_change > 0 ? '+' : ''; ?><?php echo $reversal_quantity_change; ?>
                                </span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>New Total Stock:</span>
                                <span class="font-weight-bold text-success"><?php echo $projected_total_quantity; ?></span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span>Reversal Type:</span>
                                <span class="font-weight-bold text-uppercase"><?php echo $reversal_type; ?></span>
                            </div>
                            <hr>
                            <?php if ($projected_total_quantity < 0): ?>
                                <div class="alert alert-danger small mb-0">
                                    <i class="fas fa-exclamation-triangle mr-1"></i>
                                    <strong>Warning:</strong> Reversal will result in negative total stock!
                                </div>
                            <?php elseif ($projected_total_quantity <= $original_transaction['item_low_stock_alert']): ?>
                                <div class="alert alert-warning small mb-0">
                                    <i class="fas fa-exclamation-triangle mr-1"></i>
                                    <strong>Note:</strong> Total stock will be at or below low stock level
                                </div>
                            <?php else: ?>
                                <div class="alert alert-success small mb-0">
                                    <i class="fas fa-check-circle mr-1"></i>
                                    Total stock level will be within acceptable range
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Safety Checks -->
                <div class="card card-secondary">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-shield-alt mr-2"></i>Safety Checks</h3>
                    </div>
                    <div class="card-body">
                        <div class="small">
                            <div class="d-flex align-items-center mb-2">
                                <i class="fas fa-<?php echo $reversal_result->num_rows > 0 ? 'times text-danger' : 'check text-success'; ?> mr-2"></i>
                                <span>Transaction not previously reversed</span>
                            </div>
                            <div class="d-flex align-items-center mb-2">
                                <i class="fas fa-<?php echo $projected_total_quantity >= 0 ? 'check text-success' : 'times text-danger'; ?> mr-2"></i>
                                <span>No negative total stock result</span>
                            </div>
                            <?php if ($reversal_type === 'out' || $reversal_type === 'transfer_reverse'): ?>
                            <div class="d-flex align-items-center mb-2">
                                <?php 
                                $has_sufficient_location_stock = true;
                                if ($reversal_type === 'out' && $original_transaction['to_location_id']) {
                                    $available = $location_quantities_before[$original_transaction['to_location_id']] ?? 0;
                                    $has_sufficient_location_stock = ($available >= abs($reversal_quantity_change));
                                } elseif ($reversal_type === 'transfer_reverse' && $original_transaction['to_location_id']) {
                                    $available = $location_quantities_before[$original_transaction['to_location_id']] ?? 0;
                                    $has_sufficient_location_stock = ($available >= abs($reversal_quantity_change));
                                }
                                ?>
                                <i class="fas fa-<?php echo $has_sufficient_location_stock ? 'check text-success' : 'times text-danger'; ?> mr-2"></i>
                                <span>Sufficient location stock available</span>
                            </div>
                            <?php endif; ?>
                            <div class="d-flex align-items-center">
                                <i class="fas fa-check text-success mr-2"></i>
                                <span>Audit trail will be created</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- What Will Happen -->
                <div class="card card-dark">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-list mr-2"></i>What Will Happen</h3>
                    </div>
                    <div class="card-body">
                        <div class="small">
                            <div class="d-flex align-items-center mb-2">
                                <i class="fas fa-exchange-alt text-info mr-2"></i>
                                <span>Create reversal transaction</span>
                            </div>
                            <div class="d-flex align-items-center mb-2">
                                <i class="fas fa-cubes text-warning mr-2"></i>
                                <span>Update total stock levels</span>
                            </div>
                            <?php if ($reversal_type === 'transfer_reverse' || $reversal_type === 'in' || $reversal_type === 'out'): ?>
                            <div class="d-flex align-items-center mb-2">
                                <i class="fas fa-map-marker-alt text-primary mr-2"></i>
                                <span>Update location inventory</span>
                            </div>
                            <?php endif; ?>
                            <div class="d-flex align-items-center">
                                <i class="fas fa-history text-success mr-2"></i>
                                <span>Create audit trail</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Form validation
    $('#reversalForm').on('submit', function(e) {
        const reversalReason = $('#reversal_reason').val().trim();
        
        if (!reversalReason) {
            e.preventDefault();
            alert('Please provide a reason for the reversal.');
            $('#reversal_reason').focus();
            return false;
        }

        if (reversalReason.length < 10) {
            e.preventDefault();
            alert('Please provide a more detailed reason for the reversal (minimum 10 characters).');
            $('#reversal_reason').focus();
            return false;
        }

        if (!confirm('Are you absolutely sure you want to reverse this transaction? This action cannot be undone and will affect inventory levels.')) {
            e.preventDefault();
            return false;
        }

        // Show loading state
        $('#submitReversal').html('<i class="fas fa-spinner fa-spin mr-2"></i>Reversing...').prop('disabled', true);
    });

    // Check for negative stock warning
    const projectedQuantity = <?php echo $projected_total_quantity; ?>;
    if (projectedQuantity < 0) {
        $('#submitReversal').prop('disabled', true)
                          .html('<i class="fas fa-ban mr-2"></i>Cannot Reverse - Negative Stock')
                          .removeClass('btn-danger').addClass('btn-secondary');
        
        // Add warning message
        $('#reversalForm').prepend(
            '<div class="alert alert-danger">' +
            '<i class="fas fa-exclamation-triangle mr-2"></i>' +
            '<strong>Cannot Proceed:</strong> Reversing this transaction would result in negative stock. ' +
            'Please adjust stock levels before attempting to reverse this transaction.' +
            '</div>'
        );
    }

    // Real-time reason validation
    $('#reversal_reason').on('input', function() {
        const reason = $(this).val().trim();
        const minLength = 10;
        
        if (reason.length > 0 && reason.length < minLength) {
            $(this).addClass('is-invalid');
            $(this).removeClass('is-valid');
        } else if (reason.length >= minLength) {
            $(this).removeClass('is-invalid');
            $(this).addClass('is-valid');
        } else {
            $(this).removeClass('is-invalid is-valid');
        }
    });
});

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Escape to cancel
    if (e.keyCode === 27) {
        window.location.href = 'inventory_transaction_view.php?id=<?php echo $transaction_id; ?>';
    }
});
</script>

<style>
.is-invalid {
    border-color: #dc3545;
    box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25);
}

.is-valid {
    border-color: #28a745;
    box-shadow: 0 0 0 0.2rem rgba(40, 167, 69, 0.25);
}

.btn:disabled {
    cursor: not-allowed;
    opacity: 0.6;
}
</style>

<?php 
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
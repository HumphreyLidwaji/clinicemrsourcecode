<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Get transfer ID from URL
$transfer_id = intval($_GET['id'] ?? 0);

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

// Check if transfer is in transit or completed
if ($transfer['transfer_status'] == 'pending') {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Transfer must be marked as 'In Transit' before creating transactions.";
    header("Location: inventory_transfer_view.php?id=" . $transfer_id);
    exit;
}

if ($transfer['transfer_status'] == 'cancelled') {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Cannot create transactions for a cancelled transfer.";
    header("Location: inventory_transfer_view.php?id=" . $transfer_id);
    exit;
}

// Get transfer items with sent quantities
$items_sql = "SELECT ti.*, i.item_name, i.item_code, i.item_unit_measure,
                     i.item_quantity as current_stock,
                     COALESCE(ili_from.quantity, 0) as from_location_stock,
                     COALESCE(ili_to.quantity, 0) as to_location_stock
              FROM inventory_transfer_items ti
              JOIN inventory_items i ON ti.item_id = i.item_id
              LEFT JOIN inventory_location_items ili_from ON (i.item_id = ili_from.item_id AND ili_from.location_id = ?)
              LEFT JOIN inventory_location_items ili_to ON (i.item_id = ili_to.item_id AND ili_to.location_id = ?)
              WHERE ti.transfer_id = ? 
              AND ti.quantity_sent > 0
              ORDER BY i.item_name";
$items_stmt = $mysqli->prepare($items_sql);
$items_stmt->bind_param("iii", $transfer['from_location_id'], $transfer['to_location_id'], $transfer_id);
$items_stmt->execute();
$items_result = $items_stmt->get_result();
$transfer_items = [];

while ($item = $items_result->fetch_assoc()) {
    $transfer_items[] = $item;
}
$items_stmt->close();

// Check if transactions already exist
$transactions_sql = "SELECT COUNT(*) as count FROM inventory_transactions WHERE transfer_id = ?";
$transactions_stmt = $mysqli->prepare($transactions_sql);
$transactions_stmt->bind_param("i", $transfer_id);
$transactions_stmt->execute();
$transactions_result = $transactions_stmt->get_result();
$transactions_count = $transactions_result->fetch_assoc()['count'];
$transactions_stmt->close();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token'] ?? '');
    
    // Validate CSRF token
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        header("Location: inventory_transfer_complete.php?id=" . $transfer_id);
        exit;
    }
    
    // Check if there are items to process
    if (empty($transfer_items)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "No items with sent quantities to process.";
        header("Location: inventory_transfer_complete.php?id=" . $transfer_id);
        exit;
    }
    
    // Start transaction
    $mysqli->begin_transaction();
    
    try {
        $success_count = 0;
        $error_messages = [];
        
        foreach ($transfer_items as $item) {
            $item_id = $item['item_id'];
            $quantity = $item['quantity_sent']; // Use sent quantity, not requested quantity
            
            // Skip items with zero sent quantity
            if ($quantity <= 0) {
                continue;
            }
            
            // Check if source location has enough stock
            if ($item['from_location_stock'] < $quantity) {
                $error_messages[] = "Insufficient stock for " . $item['item_name'] . 
                                  " at source location. Available: " . $item['from_location_stock'] . 
                                  ", Required: " . $quantity;
                continue;
            }
            
            // Calculate new quantities
            $from_new_quantity = $item['from_location_stock'] - $quantity;
            $to_new_quantity = $item['to_location_stock'] + $quantity;
            
            // Update source location
            if ($item['from_location_stock'] > 0) {
                $source_sql = "UPDATE inventory_location_items 
                              SET quantity = ?, updated_at = NOW()
                              WHERE item_id = ? AND location_id = ?";
                $source_stmt = $mysqli->prepare($source_sql);
                $source_stmt->bind_param("iii", $from_new_quantity, $item_id, $transfer['from_location_id']);
                $source_stmt->execute();
                
                // Remove if zero
                if ($from_new_quantity <= 0) {
                    $cleanup_sql = "DELETE FROM inventory_location_items 
                                   WHERE item_id = ? AND location_id = ?";
                    $cleanup_stmt = $mysqli->prepare($cleanup_sql);
                    $cleanup_stmt->bind_param("ii", $item_id, $transfer['from_location_id']);
                    $cleanup_stmt->execute();
                    $cleanup_stmt->close();
                }
                $source_stmt->close();
            } else {
                // Item not tracked at source location (shouldn't happen if we checked properly)
                $error_messages[] = "Item " . $item['item_name'] . " not tracked at source location.";
                continue;
            }
            
            // Update destination location
            $dest_sql = "INSERT INTO inventory_location_items 
                        (item_id, location_id, quantity, updated_at) 
                        VALUES (?, ?, ?, NOW())
                        ON DUPLICATE KEY UPDATE 
                        quantity = quantity + VALUES(quantity),
                        updated_at = NOW()";
            $dest_stmt = $mysqli->prepare($dest_sql);
            $dest_stmt->bind_param("iii", $item_id, $transfer['to_location_id'], $quantity);
            $dest_stmt->execute();
            $dest_stmt->close();
            
            // Update main inventory item (total doesn't change for transfers)
            $main_sql = "UPDATE inventory_items SET item_updated_date = NOW() WHERE item_id = ?";
            $main_stmt = $mysqli->prepare($main_sql);
            $main_stmt->bind_param("i", $item_id);
            $main_stmt->execute();
            $main_stmt->close();
            
            // Create OUT transaction (from source location)
            $out_sql = "INSERT INTO inventory_transactions (
                item_id, transaction_type, quantity_change, 
                previous_quantity, new_quantity, transaction_reference, 
                transaction_notes, performed_by, from_location_id, 
                to_location_id, transfer_id, transaction_date
            ) VALUES (?, 'transfer_out', ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            
            $out_stmt = $mysqli->prepare($out_sql);
            $trans_reference = $transfer['transfer_number'] . '-OUT-' . $item_id;
            $trans_notes = "Transfer out: " . $quantity . " " . $item['item_unit_measure'] . 
                          " of " . $item['item_name'] . " to " . $transfer['to_location_name'];
            
            // Store values in variables
            $transaction_type_out = 'transfer_out';
            $quantity_change_out = -$quantity;
            $previous_quantity_out = $item['from_location_stock'];
            $new_quantity_out = $from_new_quantity;
            
         
            
          $out_stmt->bind_param(
    "iiiissiiii",
    $item_id,
    $quantity_change_out,
    $previous_quantity_out,
    $new_quantity_out,
    $trans_reference,
    $trans_notes,
    $session_user_id,
    $transfer['from_location_id'],
    $transfer['to_location_id'],
    $transfer_id
);

            $out_stmt->execute();
            $out_stmt->close();
            
            // Create IN transaction (to destination location)
            $in_sql = "INSERT INTO inventory_transactions (
                item_id, transaction_type, quantity_change, 
                previous_quantity, new_quantity, transaction_reference, 
                transaction_notes, performed_by, from_location_id, 
                to_location_id, transfer_id, transaction_date
            ) VALUES (?, 'transfer_in', ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            
            $in_stmt = $mysqli->prepare($in_sql);
            $trans_reference_in = $transfer['transfer_number'] . '-IN-' . $item_id;
            $trans_notes_in = "Transfer in: " . $quantity . " " . $item['item_unit_measure'] . 
                            " of " . $item['item_name'] . " from " . $transfer['from_location_name'];
            
            // Store values in variables
            $transaction_type_in = 'transfer_in';
            $quantity_change_in = $quantity;
            $previous_quantity_in = $item['to_location_stock'];
            $new_quantity_in = $to_new_quantity;
            
         $in_stmt->bind_param(
    "iiisssiiii",
    $item_id,
    $quantity_change_in,
    $previous_quantity_in,
    $new_quantity_in,
    $trans_reference_in,
    $trans_notes_in,
    $session_user_id,
    $transfer['from_location_id'],
    $transfer['to_location_id'],
    $transfer_id
);

            $in_stmt->execute();
            $in_stmt->close();
            
            // Update transfer item as received (if quantity sent equals quantity)
            if ($item['quantity_sent'] == $item['quantity']) {
                $update_item_sql = "UPDATE inventory_transfer_items 
                                   SET quantity_received = ?
                                   WHERE transfer_id = ? AND item_id = ?";
                $update_item_stmt = $mysqli->prepare($update_item_sql);
                $update_item_stmt->bind_param("iii", $quantity, $transfer_id, $item_id);
                $update_item_stmt->execute();
                $update_item_stmt->close();
            }
            
            $success_count++;
        }
        
        // Update transfer status to completed if all items processed
        $update_transfer_sql = "UPDATE inventory_transfers 
                               SET transfer_status = 'completed',
                                   transfer_completed_date = NOW(),
                                   completed_by = ?
                               WHERE transfer_id = ?";
        $update_transfer_stmt = $mysqli->prepare($update_transfer_sql);
        $update_transfer_stmt->bind_param("ii", $session_user_id, $transfer_id);
        $update_transfer_stmt->execute();
        $update_transfer_stmt->close();
        
        // Log the action
        $log_sql = "INSERT INTO logs SET
                  log_type = 'Inventory',
                  log_action = 'Complete Transfer',
                  log_description = ?,
                  log_ip = ?,
                  log_user_agent = ?,
                  log_user_id = ?,
                  log_entity_id = ?,
                  log_created_at = NOW()";
        $log_stmt = $mysqli->prepare($log_sql);
        $log_desc = "Completed transfer #" . $transfer['transfer_number'] . " with " . $success_count . " items";
        $log_stmt->bind_param("sssii", $log_desc, $session_ip, $session_user_agent, $session_user_id, $transfer_id);
        $log_stmt->execute();
        $log_stmt->close();
        
        $mysqli->commit();
        
        if (!empty($error_messages)) {
            $_SESSION['alert_type'] = "warning";
            $_SESSION['alert_message'] = "Transfer partially completed. " . $success_count . " items processed.<br><br>" . 
                                       "Issues:<br>" . implode("<br>", $error_messages);
        } else {
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Transfer completed successfully! " . $success_count . " items processed.";
        }
        
        header("Location: inventory_transfer_view.php?id=" . $transfer_id);
        exit;
        
    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error processing transfer: " . $e->getMessage();
        header("Location: inventory_transfer_complete.php?id=" . $transfer_id);
        exit;
    }
}
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0 text-white">
                <i class="fas fa-fw fa-exchange-alt mr-2"></i>
                Complete Transfer: <?php echo htmlspecialchars($transfer['transfer_number']); ?>
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

        <!-- Warning Alert -->
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle mr-2"></i>
            <strong>Important:</strong> This action will create inventory transactions and update stock levels at both locations.
            Please review the items below before proceeding.
        </div>

        <!-- Transfer Info -->
        <div class="card card-info mb-4">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-info-circle mr-2"></i>Transfer Information</h3>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <strong>Transfer #:</strong> <?php echo htmlspecialchars($transfer['transfer_number']); ?><br>
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
        </div>

        <?php if ($transactions_count > 0): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle mr-2"></i>
                <strong>Note:</strong> This transfer already has <?php echo $transactions_count; ?> transaction(s) recorded.
                Creating new transactions will add to the existing ones.
            </div>
        <?php endif; ?>

        <?php if (empty($transfer_items)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-times-circle mr-2"></i>
                <strong>No items to process!</strong> There are no items with sent quantities in this transfer.
                Please mark items as sent before completing the transfer.
                <div class="mt-3">
                    <a href="inventory_transfer_items.php?transfer_id=<?php echo $transfer_id; ?>" class="btn btn-warning">
                        <i class="fas fa-edit mr-2"></i>Manage Items
                    </a>
                </div>
            </div>
        <?php else: ?>
            <form method="POST" id="completeTransferForm">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                
                <!-- Items to Process -->
                <div class="card card-primary">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-boxes mr-2"></i>Items to Process</h3>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Item</th>
                                        <th class="text-center">Requested</th>
                                        <th class="text-center">Sent</th>
                                        <th class="text-center">Source Stock</th>
                                        <th class="text-center">Destination Stock</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $total_requested = 0;
                                    $total_sent = 0;
                                    $all_items_valid = true;
                                    
                                    foreach ($transfer_items as $item): 
                                        $total_requested += $item['quantity'];
                                        $total_sent += $item['quantity_sent'];
                                        $has_enough_stock = $item['from_location_stock'] >= $item['quantity_sent'];
                                        
                                        if (!$has_enough_stock) {
                                            $all_items_valid = false;
                                        }
                                    ?>
                                    <tr class="<?php echo $has_enough_stock ? '' : 'table-danger'; ?>">
                                        <td>
                                            <div class="font-weight-bold"><?php echo htmlspecialchars($item['item_name']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($item['item_code']); ?></small>
                                            <div class="small">
                                                <i class="fas fa-box text-muted mr-1"></i>
                                                Unit: <?php echo $item['item_unit_measure']; ?>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <div class="font-weight-bold"><?php echo $item['quantity']; ?></div>
                                        </td>
                                        <td class="text-center">
                                            <div class="font-weight-bold <?php echo $item['quantity_sent'] == $item['quantity'] ? 'text-success' : 'text-warning'; ?>">
                                                <?php echo $item['quantity_sent']; ?>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <div class="font-weight-bold <?php echo $has_enough_stock ? 'text-success' : 'text-danger'; ?>">
                                                <?php echo $item['from_location_stock']; ?>
                                            </div>
                                            <?php if (!$has_enough_stock): ?>
                                                <div class="small text-danger">
                                                    <i class="fas fa-exclamation-circle mr-1"></i>
                                                    Insufficient
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <div class="font-weight-bold"><?php echo $item['to_location_stock']; ?></div>
                                        </td>
                                        <td>
                                            <?php if ($has_enough_stock): ?>
                                                <span class="badge badge-success">Ready</span>
                                            <?php else: ?>
                                                <span class="badge badge-danger">Low Stock</span>
                                                <div class="small text-danger">
                                                    Needs <?php echo $item['quantity_sent'] - $item['from_location_stock']; ?> more
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="bg-light">
                                    <tr>
                                        <th>Totals</th>
                                        <th class="text-center"><?php echo $total_requested; ?></th>
                                        <th class="text-center"><?php echo $total_sent; ?></th>
                                        <th colspan="3">
                                            <?php if ($all_items_valid): ?>
                                                <span class="badge badge-success p-2">
                                                    <i class="fas fa-check mr-1"></i>
                                                    All items ready for processing
                                                </span>
                                            <?php else: ?>
                                                <span class="badge badge-danger p-2">
                                                    <i class="fas fa-exclamation-triangle mr-1"></i>
                                                    Some items have insufficient stock
                                                </span>
                                            <?php endif; ?>
                                        </th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Processing Summary -->
                <div class="card card-success mt-4">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-calculator mr-2"></i>Processing Summary</h3>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="alert alert-secondary">
                                    <h6><i class="fas fa-arrow-up mr-2"></i>From Source Location</h6>
                                    <div class="small">
                                        <strong>Location:</strong> <?php echo htmlspecialchars($transfer['from_location_name']); ?><br>
                                        <strong>Action:</strong> Reduce stock by <?php echo $total_sent; ?> units<br>
                                        <strong>Items affected:</strong> <?php echo count($transfer_items); ?> items
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="alert alert-success">
                                    <h6><i class="fas fa-arrow-down mr-2"></i>To Destination Location</h6>
                                    <div class="small">
                                        <strong>Location:</strong> <?php echo htmlspecialchars($transfer['to_location_name']); ?><br>
                                        <strong>Action:</strong> Increase stock by <?php echo $total_sent; ?> units<br>
                                        <strong>Items affected:</strong> <?php echo count($transfer_items); ?> items
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="alert alert-info">
                            <h6><i class="fas fa-exchange-alt mr-2"></i>Transactions to Create</h6>
                            <div class="small">
                                <strong>Total Transactions:</strong> <?php echo count($transfer_items) * 2; ?> (2 per item)<br>
                                <strong>Transaction Types:</strong> Transfer Out (from source) and Transfer In (to destination)<br>
                                <strong>Audit Trail:</strong> All transactions will be recorded with timestamps and user information
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="card card-warning mt-4">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-bolt mr-2"></i>Complete Transfer</h3>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-8">
                                <div class="alert alert-warning">
                                    <i class="fas fa-exclamation-triangle mr-2"></i>
                                    <strong>Final Confirmation Required</strong><br>
                                    <small>
                                        By clicking "Complete Transfer", you confirm that:
                                        <ul class="mb-0">
                                            <li>All items have been physically transferred</li>
                                            <li>Quantities sent are accurate</li>
                                            <li>You want to update inventory records</li>
                                        </ul>
                                    </small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn btn-success btn-lg" 
                                            onclick="return confirmComplete()"
                                            <?php echo !$all_items_valid ? 'disabled' : ''; ?>>
                                        <i class="fas fa-check-circle mr-2"></i>
                                        Complete Transfer
                                    </button>
                                    <a href="inventory_transfer_view.php?id=<?php echo $transfer_id; ?>" class="btn btn-outline-dark">
                                        <i class="fas fa-times mr-2"></i>Cancel
                                    </a>
                                </div>
                                <?php if (!$all_items_valid): ?>
                                    <div class="mt-2 text-danger small">
                                        <i class="fas fa-exclamation-circle mr-1"></i>
                                        Cannot complete due to insufficient stock
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<script>
function confirmComplete() {
    const message = `Are you sure you want to complete this transfer?\n\n` +
                   `Transfer: ${$('strong:contains("Transfer #:")').next().text().trim()}\n` +
                   `Items: ${<?php echo count($transfer_items); ?>} items, ${<?php echo $total_sent; ?>} units\n\n` +
                   `This will:\n` +
                   `1. Update stock at both locations\n` +
                   `2. Create inventory transactions\n` +
                   `3. Mark transfer as completed\n\n` +
                   `This action cannot be undone.`;
    
    return confirm(message);
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + Enter to complete
    if (e.ctrlKey && e.keyCode === 13) {
        e.preventDefault();
        if ($('#completeTransferForm button[type="submit"]').prop('disabled') === false) {
            $('#completeTransferForm').submit();
        }
    }
    // Escape to go back
    if (e.keyCode === 27) {
        window.location.href = 'inventory_transfer_view.php?id=<?php echo $transfer_id; ?>';
    }
});
</script>

<style>
.card-header.bg-primary {
    background-color: #007bff !important;
}

.table-danger {
    background-color: #f8d7da;
}

.badge {
    font-size: 0.85em;
    padding: 5px 10px;
}

.alert h6 {
    font-size: 1.1em;
    margin-bottom: 0.5rem;
}

.btn-lg {
    padding: 0.75rem 1.5rem;
    font-size: 1.1rem;
}

.btn:disabled {
    opacity: 0.65;
    cursor: not-allowed;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
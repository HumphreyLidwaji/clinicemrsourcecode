<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Get transfer ID and action from URL
$transfer_id = intval($_GET['id'] ?? 0);
$action = sanitizeInput($_GET['action'] ?? '');

// Validate inputs
if (!$transfer_id) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid transfer ID.";
    header("Location: inventory_transfers.php");
    exit;
}

if (!in_array($action, ['start', 'complete', 'cancel'])) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid action.";
    header("Location: inventory_transfer_view.php?id=" . $transfer_id);
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

// Handle the action
switch ($action) {
    case 'start':
        processStartTransfer($mysqli, $transfer_id, $transfer, $session_user_id, $session_ip, $session_user_agent);
        break;
        
    case 'complete':
        processCompleteTransfer($mysqli, $transfer_id, $transfer, $session_user_id, $session_ip, $session_user_agent);
        break;
        
    case 'cancel':
        processCancelTransfer($mysqli, $transfer_id, $transfer, $session_user_id, $session_ip, $session_user_agent);
        break;
}

// Redirect back to transfer view
header("Location: inventory_transfer_view.php?id=" . $transfer_id);
exit;

/**
 * Process Start Transfer (mark as in transit)
 */
function processStartTransfer($mysqli, $transfer_id, $transfer, $user_id, $ip, $user_agent) {
    // Check if transfer can be started
    if ($transfer['transfer_status'] != 'pending') {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Transfer must be in 'pending' status to start. Current status: " . $transfer['transfer_status'];
        return;
    }
    
    // Check if transfer has items
    $items_sql = "SELECT COUNT(*) as item_count FROM inventory_transfer_items WHERE transfer_id = ?";
    $items_stmt = $mysqli->prepare($items_sql);
    $items_stmt->bind_param("i", $transfer_id);
    $items_stmt->execute();
    $items_result = $items_stmt->get_result();
    $item_count = $items_result->fetch_assoc()['item_count'];
    $items_stmt->close();
    
    if ($item_count == 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Cannot start transfer: No items found in transfer.";
        return;
    }
    
    // Start transaction
    $mysqli->begin_transaction();
    
    try {
        // Update transfer status
        $update_sql = "UPDATE inventory_transfers 
                      SET transfer_status = 'in_transit',
                          transfer_started_date = NOW(),
                          started_by = ?
                      WHERE transfer_id = ?";
        $update_stmt = $mysqli->prepare($update_sql);
        $update_stmt->bind_param("ii", $user_id, $transfer_id);
        
        if (!$update_stmt->execute()) {
            throw new Exception("Failed to update transfer status: " . $update_stmt->error);
        }
        $update_stmt->close();
        
        // Log the action
        $log_sql = "INSERT INTO logs SET
                  log_type = 'Inventory',
                  log_action = 'Start Transfer',
                  log_description = ?,
                  log_ip = ?,
                  log_user_agent = ?,
                  log_user_id = ?,
                  log_entity_id = ?,
                  log_created_at = NOW()";
        $log_stmt = $mysqli->prepare($log_sql);
        $log_desc = "Started transfer #" . $transfer['transfer_number'] . " (marked as in transit)";
        $log_stmt->bind_param("sssii", $log_desc, $ip, $user_agent, $user_id, $transfer_id);
        $log_stmt->execute();
        $log_stmt->close();
        
        $mysqli->commit();
        
        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Transfer #" . $transfer['transfer_number'] . " marked as 'In Transit' successfully.";
        
    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error starting transfer: " . $e->getMessage();
    }
}

/**
 * Process Complete Transfer (mark as completed)
 */
function processCompleteTransfer($mysqli, $transfer_id, $transfer, $user_id, $ip, $user_agent) {
    // Check if transfer can be completed
    if ($transfer['transfer_status'] != 'in_transit') {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Transfer must be 'in transit' to complete. Current status: " . $transfer['transfer_status'];
        return;
    }
    
    // Check if all items have been sent
    $items_sql = "SELECT COUNT(*) as total_items,
                         SUM(CASE WHEN quantity_sent = quantity THEN 1 ELSE 0 END) as fully_sent_items
                  FROM inventory_transfer_items 
                  WHERE transfer_id = ?";
    $items_stmt = $mysqli->prepare($items_sql);
    $items_stmt->bind_param("i", $transfer_id);
    $items_stmt->execute();
    $items_result = $items_stmt->get_result();
    $items_data = $items_result->fetch_assoc();
    $items_stmt->close();
    
    if ($items_data['total_items'] == 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Cannot complete transfer: No items found.";
        return;
    }
    
    if ($items_data['fully_sent_items'] < $items_data['total_items']) {
        $_SESSION['alert_type'] = "warning";
        $_SESSION['alert_message'] = "Note: Not all items have been fully sent. " . 
                                    $items_data['fully_sent_items'] . " of " . $items_data['total_items'] . 
                                    " items are marked as fully sent.";
        // We'll continue anyway, as partial completions are allowed
    }
    
    // Start transaction
    $mysqli->begin_transaction();
    
    try {
        // Update transfer status
        $update_sql = "UPDATE inventory_transfers 
                      SET transfer_status = 'completed',
                          transfer_completed_date = NOW(),
                          completed_by = ?
                      WHERE transfer_id = ?";
        $update_stmt = $mysqli->prepare($update_sql);
        $update_stmt->bind_param("ii", $user_id, $transfer_id);
        
        if (!$update_stmt->execute()) {
            throw new Exception("Failed to update transfer status: " . $update_stmt->error);
        }
        $update_stmt->close();
        
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
        $log_desc = "Completed transfer #" . $transfer['transfer_number'] . " (marked as completed)";
        $log_stmt->bind_param("sssii", $log_desc, $ip, $user_agent, $user_id, $transfer_id);
        $log_stmt->execute();
        $log_stmt->close();
        
        $mysqli->commit();
        
        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Transfer #" . $transfer['transfer_number'] . " marked as 'Completed' successfully.";
        
    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error completing transfer: " . $e->getMessage();
    }
}

/**
 * Process Cancel Transfer
 */
function processCancelTransfer($mysqli, $transfer_id, $transfer, $user_id, $ip, $user_agent) {
    // Check if transfer can be cancelled
    if (!in_array($transfer['transfer_status'], ['pending', 'in_transit'])) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Transfer cannot be cancelled in current status: " . $transfer['transfer_status'];
        return;
    }
    
    // Check if transactions already exist
    $transactions_sql = "SELECT COUNT(*) as count FROM inventory_transactions WHERE transfer_id = ?";
    $transactions_stmt = $mysqli->prepare($transactions_sql);
    $transactions_stmt->bind_param("i", $transfer_id);
    $transactions_stmt->execute();
    $transactions_result = $transactions_stmt->get_result();
    $transactions_count = $transactions_result->fetch_assoc()['count'];
    $transactions_stmt->close();
    
    if ($transactions_count > 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Cannot cancel transfer: " . $transactions_count . " transactions already exist. Please reverse transactions first.";
        return;
    }
    
    // Start transaction
    $mysqli->begin_transaction();
    
    try {
        // Update transfer status
        $update_sql = "UPDATE inventory_transfers 
                      SET transfer_status = 'cancelled',
                          transfer_cancelled_date = NOW(),
                          cancelled_by = ?,
                          cancellation_reason = 'Cancelled by user'
                      WHERE transfer_id = ?";
        $update_stmt = $mysqli->prepare($update_sql);
        $update_stmt->bind_param("ii", $user_id, $transfer_id);
        
        if (!$update_stmt->execute()) {
            throw new Exception("Failed to update transfer status: " . $update_stmt->error);
        }
        $update_stmt->close();
        
        // Reset sent/received quantities to 0
        $reset_sql = "UPDATE inventory_transfer_items 
                     SET quantity_sent = 0, quantity_received = 0
                     WHERE transfer_id = ?";
        $reset_stmt = $mysqli->prepare($reset_sql);
        $reset_stmt->bind_param("i", $transfer_id);
        $reset_stmt->execute();
        $reset_stmt->close();
        
        // Log the action
        $log_sql = "INSERT INTO logs SET
                  log_type = 'Inventory',
                  log_action = 'Cancel Transfer',
                  log_description = ?,
                  log_ip = ?,
                  log_user_agent = ?,
                  log_user_id = ?,
                  log_entity_id = ?,
                  log_created_at = NOW()";
        $log_stmt = $mysqli->prepare($log_sql);
        $log_desc = "Cancelled transfer #" . $transfer['transfer_number'];
        $log_stmt->bind_param("sssii", $log_desc, $ip, $user_agent, $user_id, $transfer_id);
        $log_stmt->execute();
        $log_stmt->close();
        
        $mysqli->commit();
        
        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Transfer #" . $transfer['transfer_number'] . " cancelled successfully.";
        
    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error cancelling transfer: " . $e->getMessage();
    }
}
?>
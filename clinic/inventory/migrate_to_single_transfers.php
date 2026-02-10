<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Only allow admins
if ($session_user_role != 1 && $session_user_role != 3) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Access denied. Admin privileges required.";
    header("Location: inventory.php");
    exit;
}

$mysqli->begin_transaction();

try {
    // Find paired issue+receive transactions
    $pair_sql = "SELECT t1.transaction_id as issue_id, t1.item_id, 
                        ABS(t1.quantity_change) as quantity,
                        t1.from_location_id, t1.to_location_id,
                        t1.transaction_date, t1.transaction_reference,
                        t1.performed_by, t1.transaction_notes,
                        t2.transaction_id as receive_id
                 FROM inventory_transactions t1
                 JOIN inventory_transactions t2 ON (
                     t1.transaction_type = 'out'
                     AND t2.transaction_type = 'in'
                     AND t1.item_id = t2.item_id
                     AND ABS(t1.quantity_change) = ABS(t2.quantity_change)
                     AND DATE(t1.transaction_date) = DATE(t2.transaction_date)
                     AND t1.to_location_id = t2.from_location_id
                     AND TIMESTAMPDIFF(MINUTE, t1.transaction_date, t2.transaction_date) BETWEEN 0 AND 5
                 )
                 WHERE t1.transaction_type = 'out'
                 AND NOT EXISTS (
                     SELECT 1 FROM inventory_transactions t3 
                     WHERE t3.original_transaction_id = t1.transaction_id
                 )
                 ORDER BY t1.transaction_date
                 LIMIT 100"; // Process in batches
    
    $pair_result = $mysqli->query($pair_sql);
    $migrated = 0;
    $errors = [];
    
    while ($pair = $pair_result->fetch_assoc()) {
        try {
            // Create transfer record
            $transfer_number = 'MIG-' . date('Ymd-His') . '-' . $pair['issue_id'];
            
            $transfer_sql = "INSERT INTO inventory_transfers (
                transfer_number, from_location_id, to_location_id,
                requested_by, transfer_status, notes, transfer_date
            ) VALUES (?, ?, ?, ?, 'completed', ?, ?)";
            
            $transfer_stmt = $mysqli->prepare($transfer_sql);
            $notes = "Migrated from paired transactions #" . $pair['issue_id'] . " and #" . $pair['receive_id'];
            $transfer_stmt->bind_param(
                "siiiss", 
                $transfer_number, $pair['from_location_id'], $pair['to_location_id'],
                $pair['performed_by'], $notes, $pair['transaction_date']
            );
            $transfer_stmt->execute();
            $transfer_id = $transfer_stmt->insert_id;
            $transfer_stmt->close();
            
            // Create single transfer transaction
            $trans_sql = "INSERT INTO inventory_transactions (
                item_id, transaction_type, transfer_type, quantity_change,
                previous_quantity, new_quantity, transaction_reference,
                transaction_notes, performed_by, from_location_id,
                to_location_id, transfer_id, transaction_date,
                original_issue_id, original_receive_id
            ) VALUES (?, 'transfer', 'transfer', 0, 
                     (SELECT item_quantity FROM inventory_items WHERE item_id = ?),
                     (SELECT item_quantity FROM inventory_items WHERE item_id = ?),
                     ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $trans_stmt = $mysqli->prepare($trans_sql);
            $trans_ref = 'MIG-' . $pair['transaction_reference'];
            $trans_notes = "Migrated transfer: " . $notes;
            
            $trans_stmt->bind_param(
                "iiissiiiiisii",
                $pair['item_id'], $pair['item_id'], $pair['item_id'],
                $trans_ref, $trans_notes, $pair['performed_by'],
                $pair['from_location_id'], $pair['to_location_id'], $transfer_id,
                $pair['transaction_date'], $pair['issue_id'], $pair['receive_id']
            );
            $trans_stmt->execute();
            $trans_stmt->close();
            
            // Mark original transactions as migrated
            $update_sql = "UPDATE inventory_transactions 
                          SET transfer_type = 'legacy',
                              transaction_notes = CONCAT(transaction_notes, ' [MIGRATED TO TRANSFER #', ?, ']')
                          WHERE transaction_id IN (?, ?)";
            $update_stmt = $mysqli->prepare($update_sql);
            $update_stmt->bind_param("iii", $transfer_id, $pair['issue_id'], $pair['receive_id']);
            $update_stmt->execute();
            $update_stmt->close();
            
            $migrated++;
            
        } catch (Exception $e) {
            $errors[] = "Failed to migrate pair " . $pair['issue_id'] . "-" . $pair['receive_id'] . ": " . $e->getMessage();
        }
    }
    
    $mysqli->commit();
    
    $_SESSION['alert_type'] = "success";
    $_SESSION['alert_message'] = "Migration completed! " . $migrated . " paired transactions migrated to single transfers.";
    
    if (!empty($errors)) {
        $_SESSION['alert_message'] .= " " . count($errors) . " errors occurred.";
        // Log errors
        foreach ($errors as $error) {
            error_log("Migration error: " . $error);
        }
    }
    
} catch (Exception $e) {
    $mysqli->rollback();
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Migration failed: " . $e->getMessage();
}

header("Location: inventory.php");
exit;
?>
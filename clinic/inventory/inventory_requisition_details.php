<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

$requisition_id = intval($_GET['requisition_id'] ?? 0);

if ($requisition_id == 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Requisition ID is required.";
    header("Location: inventory_requisitions.php");
    exit;
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token.";
        header("Location: inventory_requisition_details.php?requisition_id=" . $requisition_id);
        exit;
    }

    $action = sanitizeInput($_POST['action'] ?? '');
    
    switch ($action) {
        case 'approve':
            // Start transaction for approval
            $mysqli->begin_transaction();
            
            try {
                // Check if approved_quantity exists and is an array
                if (!isset($_POST['approved_quantity']) || !is_array($_POST['approved_quantity'])) {
                    throw new Exception("No approved quantities submitted");
                }

                // Update requisition status
                $update_sql = "UPDATE inventory_requisitions SET 
                              status = 'approved',
                              approved_by = ?,
                              approved_date = NOW()
                              WHERE requisition_id = ?";
                $update_stmt = $mysqli->prepare($update_sql);
                $update_stmt->bind_param("ii", $session_user_id, $requisition_id);
                $update_stmt->execute();
                $update_stmt->close();

                // Update approved quantities with enhanced validation
                if (isset($_POST['approved_quantity']) && is_array($_POST['approved_quantity'])) {
                    foreach ($_POST['approved_quantity'] as $requisition_item_id => $quantity) {
                        $requisition_item_id = intval($requisition_item_id);
                        $quantity = intval($quantity);
                        
                        // Validate the quantity
                        if ($quantity >= 0) {
                            // First, verify the item exists and get requested quantity
                            $verify_sql = "SELECT quantity_requested FROM inventory_requisition_items 
                                          WHERE requisition_item_id = ? AND requisition_id = ?";
                            $verify_stmt = $mysqli->prepare($verify_sql);
                            $verify_stmt->bind_param("ii", $requisition_item_id, $requisition_id);
                            $verify_stmt->execute();
                            $verify_result = $verify_stmt->get_result();
                            
                            if ($verify_result->num_rows > 0) {
                                $item_data = $verify_result->fetch_assoc();
                                $requested_qty = $item_data['quantity_requested'];
                                
                                // Ensure approved quantity doesn't exceed requested
                                if ($quantity > $requested_qty) {
                                    $quantity = $requested_qty; // Cap at requested quantity
                                }
                                
                                $update_item_sql = "UPDATE inventory_requisition_items 
                                                  SET quantity_approved = ? 
                                                  WHERE requisition_item_id = ?";
                                $update_item_stmt = $mysqli->prepare($update_item_sql);
                                $update_item_stmt->bind_param("ii", $quantity, $requisition_item_id);
                                
                                if (!$update_item_stmt->execute()) {
                                    throw new Exception("Failed to update item $requisition_item_id: " . $update_item_stmt->error);
                                }
                                $update_item_stmt->close();
                            }
                            $verify_stmt->close();
                        }
                    }
                }

                // Log the action
                $log_sql = "INSERT INTO logs SET
                          log_type = 'Requisition',
                          log_action = 'Approve',
                          log_description = ?,
                          log_ip = ?,
                          log_user_agent = ?,
                          log_user_id = ?,
                          log_entity_id = ?,
                          log_created_at = NOW()";
                $log_stmt = $mysqli->prepare($log_sql);
                $log_description = "Approved requisition #" . $requisition_id;
                $log_stmt->bind_param("sssii", $log_description, $session_ip, $session_user_agent, $session_user_id, $requisition_id);
                $log_stmt->execute();
                $log_stmt->close();

                $mysqli->commit();

                $_SESSION['alert_type'] = "success";
                $_SESSION['alert_message'] = "Requisition approved successfully!";
                
            } catch (Exception $e) {
                $mysqli->rollback();
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = "Error approving requisition: " . $e->getMessage();
            }
            
            header("Location: inventory_requisition_details.php?requisition_id=" . $requisition_id);
            exit;
            break;
    }
}

// Handle GET actions
if (isset($_GET['action'])) {
    $action = sanitizeInput($_GET['action']);
    
    switch ($action) {
        case 'reject':
            $mysqli->begin_transaction();
            
            try {
                $reject_sql = "UPDATE inventory_requisitions SET 
                              status = 'rejected',
                              approved_by = ?,
                              approved_date = NOW()
                              WHERE requisition_id = ?";
                $reject_stmt = $mysqli->prepare($reject_sql);
                $reject_stmt->bind_param("ii", $session_user_id, $requisition_id);
                $reject_stmt->execute();
                $reject_stmt->close();

                // Log the action
                $log_sql = "INSERT INTO logs SET
                          log_type = 'Requisition',
                          log_action = 'Reject',
                          log_description = ?,
                          log_ip = ?,
                          log_user_agent = ?,
                          log_user_id = ?,
                          log_entity_id = ?,
                          log_created_at = NOW()";
                $log_stmt = $mysqli->prepare($log_sql);
                $log_description = "Rejected requisition #" . $requisition_id;
                $log_stmt->bind_param("sssii", $log_description, $session_ip, $session_user_agent, $session_user_id, $requisition_id);
                $log_stmt->execute();
                $log_stmt->close();

                $mysqli->commit();
                
                $_SESSION['alert_type'] = "warning";
                $_SESSION['alert_message'] = "Requisition rejected!";
                
            } catch (Exception $e) {
                $mysqli->rollback();
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = "Error rejecting requisition: " . $e->getMessage();
            }
            
            header("Location: inventory_requisition_details.php?requisition_id=" . $requisition_id);
            exit;
            break;

        case 'fulfill':
            // Process fulfillment with inventory_location_items
            $mysqli->begin_transaction();
            
            try {
                // Get requisition details including delivery location
                $req_sql = "SELECT delivery_location_id FROM inventory_requisitions WHERE requisition_id = ?";
                $req_stmt = $mysqli->prepare($req_sql);
                $req_stmt->bind_param("i", $requisition_id);
                $req_stmt->execute();
                $req_result = $req_stmt->get_result();
                $requisition_data = $req_result->fetch_assoc();
                $delivery_location_id = $requisition_data['delivery_location_id'];
                $req_stmt->close();

                // Get requisition items
                $items_sql = "SELECT ri.requisition_item_id, ri.item_id, ri.quantity_approved, ri.quantity_issued,
                                     i.item_name, i.item_quantity as current_stock, 
                                     i.location_id as source_location_id, i.item_low_stock_alert
                              FROM inventory_requisition_items ri
                              JOIN inventory_items i ON ri.item_id = i.item_id
                              WHERE ri.requisition_id = ? AND ri.quantity_approved > 0";
                $items_stmt = $mysqli->prepare($items_sql);
                $items_stmt->bind_param("i", $requisition_id);
                $items_stmt->execute();
                $items_result = $items_stmt->get_result();
                
                $total_approved = 0;
                $total_issued = 0;
                $items_processed = 0;
                $has_insufficient_stock = false;
                
                while ($item = $items_result->fetch_assoc()) {
                    $quantity_approved = $item['quantity_approved'];
                    $current_stock = $item['current_stock'];
                    $source_location_id = $item['source_location_id'];
                    $total_approved += $quantity_approved;
                    
                    // Check if we have sufficient stock at source location
                    if ($current_stock >= $quantity_approved) {
                        $quantity_issued = $quantity_approved;
                        
                        // 1. UPDATE SOURCE LOCATION in inventory_location_items
                        $source_current_qty = $current_stock;
                        $source_new_qty = $current_stock - $quantity_issued;
                        
                        // Update or create source location entry
                        $update_source_sql = "INSERT INTO inventory_location_items (item_id, location_id, quantity, low_stock_alert) 
                                             VALUES (?, ?, ?, ?)
                                             ON DUPLICATE KEY UPDATE 
                                             quantity = quantity - VALUES(quantity),
                                             updated_at = NOW()";
                        $update_source_stmt = $mysqli->prepare($update_source_sql);
                        $update_source_stmt->bind_param("iiii", $item['item_id'], $source_location_id, $quantity_issued, $item['item_low_stock_alert']);
                        
                        if (!$update_source_stmt->execute()) {
                            throw new Exception("Failed to update source location inventory for item {$item['item_id']}: " . $update_source_stmt->error);
                        }
                        $update_source_stmt->close();
                        
                        // Also update main inventory_items table for backward compatibility
                        $update_main_sql = "UPDATE inventory_items 
                                           SET item_quantity = ?,
                                               item_status = CASE 
                                                   WHEN ? <= 0 THEN 'Out of Stock'
                                                   WHEN ? <= item_low_stock_alert THEN 'Low Stock'
                                                   ELSE 'In Stock'
                                               END
                                           WHERE item_id = ?";
                        $update_main_stmt = $mysqli->prepare($update_main_sql);
                        $update_main_stmt->bind_param("iiii", $source_new_qty, $source_new_qty, $source_new_qty, $item['item_id']);
                        
                        if (!$update_main_stmt->execute()) {
                            throw new Exception("Failed to update main inventory for item {$item['item_id']}: " . $update_main_stmt->error);
                        }
                        $update_main_stmt->close();
                        
                        // 2. RECORD OUTGOING TRANSACTION
                        $trans_sql = "INSERT INTO inventory_transactions SET
                                    item_id = ?,
                                    location_id = ?,
                                    transaction_type = 'requisition_out',
                                    quantity_change = ?,
                                    previous_quantity = ?,
                                    new_quantity = ?,
                                    transaction_reference = ?,
                                    performed_by = ?,
                                    transaction_notes = ?,
                                    transaction_date = NOW()";
                        $trans_stmt = $mysqli->prepare($trans_sql);
                        $trans_ref = 'REQ-' . $requisition_id;
                        $trans_notes = 'Requisition fulfillment to delivery location for item ' . $item['item_name'];
                        $trans_stmt->bind_param("iiiiisis", $item['item_id'], $source_location_id, $quantity_issued, 
                                              $source_current_qty, $source_new_qty, $trans_ref, $session_user_id, $trans_notes);
                        
                        if (!$trans_stmt->execute()) {
                            throw new Exception("Failed to create outgoing transaction for item {$item['item_id']}: " . $trans_stmt->error);
                        }
                        $trans_stmt->close();

                        // 3. UPDATE DELIVERY LOCATION in inventory_location_items
                        if ($delivery_location_id) {
                            // Get current quantity at delivery location
                            $check_delivery_sql = "SELECT quantity FROM inventory_location_items 
                                                  WHERE item_id = ? AND location_id = ?";
                            $check_delivery_stmt = $mysqli->prepare($check_delivery_sql);
                            $check_delivery_stmt->bind_param("ii", $item['item_id'], $delivery_location_id);
                            $check_delivery_stmt->execute();
                            $delivery_result = $check_delivery_stmt->get_result();
                            
                            $delivery_current_qty = 0;
                            $delivery_new_qty = $quantity_issued;
                            
                            if ($delivery_result->num_rows > 0) {
                                $delivery_item = $delivery_result->fetch_assoc();
                                $delivery_current_qty = $delivery_item['quantity'];
                                $delivery_new_qty = $delivery_current_qty + $quantity_issued;
                            }
                            
                            // Update or create delivery location entry
                            $update_delivery_sql = "INSERT INTO inventory_location_items (item_id, location_id, quantity, low_stock_alert) 
                                                   VALUES (?, ?, ?, ?)
                                                   ON DUPLICATE KEY UPDATE 
                                                   quantity = quantity + VALUES(quantity),
                                                   updated_at = NOW()";
                            $update_delivery_stmt = $mysqli->prepare($update_delivery_sql);
                            $update_delivery_stmt->bind_param("iiii", $item['item_id'], $delivery_location_id, $quantity_issued, $item['item_low_stock_alert']);
                            
                            if (!$update_delivery_stmt->execute()) {
                                throw new Exception("Failed to update delivery location inventory for item {$item['item_id']}: " . $update_delivery_stmt->error);
                            }
                            $update_delivery_stmt->close();
                            
                            // Record incoming transaction at delivery location
                            $delivery_trans_sql = "INSERT INTO inventory_transactions SET
                                                item_id = ?,
                                                location_id = ?,
                                                transaction_type = 'requisition_in',
                                                quantity_change = ?,
                                                previous_quantity = ?,
                                                new_quantity = ?,
                                                transaction_reference = ?,
                                                performed_by = ?,
                                                transaction_notes = ?,
                                                transaction_date = NOW()";
                            $delivery_trans_stmt = $mysqli->prepare($delivery_trans_sql);
                            $delivery_trans_notes = 'Requisition receipt from source location for item ' . $item['item_name'];
                            $delivery_trans_stmt->bind_param("iiiiisis", $item['item_id'], $delivery_location_id, $quantity_issued, 
                                                           $delivery_current_qty, $delivery_new_qty, $trans_ref, $session_user_id, $delivery_trans_notes);
                            
                            if (!$delivery_trans_stmt->execute()) {
                                throw new Exception("Failed to create delivery transaction for item {$item['item_id']}: " . $delivery_trans_stmt->error);
                            }
                            $delivery_trans_stmt->close();
                        }
                        
                        // 4. UPDATE ISSUED QUANTITY in requisition
                        $update_issued_sql = "UPDATE inventory_requisition_items 
                                             SET quantity_issued = ? 
                                             WHERE requisition_item_id = ?";
                        $update_issued_stmt = $mysqli->prepare($update_issued_sql);
                        $update_issued_stmt->bind_param("ii", $quantity_issued, $item['requisition_item_id']);
                        
                        if (!$update_issued_stmt->execute()) {
                            throw new Exception("Failed to update issued quantity for item {$item['requisition_item_id']}: " . $update_issued_stmt->error);
                        }
                        $update_issued_stmt->close();
                        
                        $total_issued += $quantity_issued;
                        $items_processed++;
                        
                    } else {
                        // Insufficient stock - issue what we can
                        $quantity_issued = $current_stock;
                        $has_insufficient_stock = true;
                        
                        if ($quantity_issued > 0) {
                            // Update source location (set to zero)
                            $update_source_sql = "UPDATE inventory_location_items 
                                                 SET quantity = 0,
                                                     updated_at = NOW()
                                                 WHERE item_id = ? AND location_id = ?";
                            $update_source_stmt = $mysqli->prepare($update_source_sql);
                            $update_source_stmt->bind_param("ii", $item['item_id'], $source_location_id);
                            
                            if (!$update_source_stmt->execute()) {
                                throw new Exception("Failed to update source location inventory for item {$item['item_id']}: " . $update_source_stmt->error);
                            }
                            $update_source_stmt->close();
                            
                            // Update main inventory table
                            $update_main_sql = "UPDATE inventory_items 
                                               SET item_quantity = 0,
                                                   item_status = 'Out of Stock'
                                               WHERE item_id = ?";
                            $update_main_stmt = $mysqli->prepare($update_main_sql);
                            $update_main_stmt->bind_param("i", $item['item_id']);
                            
                            if (!$update_main_stmt->execute()) {
                                throw new Exception("Failed to update main inventory for item {$item['item_id']}: " . $update_main_stmt->error);
                            }
                            $update_main_stmt->close();
                            
                            // Record outgoing transaction
                            $trans_sql = "INSERT INTO inventory_transactions SET
                                        item_id = ?,
                                        location_id = ?,
                                        transaction_type = 'requisition_out',
                                        quantity_change = ?,
                                        previous_quantity = ?,
                                        new_quantity = 0,
                                        transaction_reference = ?,
                                        performed_by = ?,
                                        transaction_notes = ?,
                                        transaction_date = NOW()";
                            $trans_stmt = $mysqli->prepare($trans_sql);
                            $trans_ref = 'REQ-' . $requisition_id;
                            $trans_notes = 'Partial requisition fulfillment for item ' . $item['item_name'] . ' (Insufficient stock)';
                            $trans_stmt->bind_param("iiiisis", $item['item_id'], $source_location_id, $quantity_issued, 
                                                  $current_stock, $trans_ref, $session_user_id, $trans_notes);
                            
                            if (!$trans_stmt->execute()) {
                                throw new Exception("Failed to create transaction for item {$item['item_id']}: " . $trans_stmt->error);
                            }
                            $trans_stmt->close();
                            
                            // Update delivery location for partial fulfillment
                            if ($delivery_location_id && $quantity_issued > 0) {
                                $update_delivery_sql = "INSERT INTO inventory_location_items (item_id, location_id, quantity, low_stock_alert) 
                                                       VALUES (?, ?, ?, ?)
                                                       ON DUPLICATE KEY UPDATE 
                                                       quantity = quantity + VALUES(quantity),
                                                       updated_at = NOW()";
                                $update_delivery_stmt = $mysqli->prepare($update_delivery_sql);
                                $update_delivery_stmt->bind_param("iiii", $item['item_id'], $delivery_location_id, $quantity_issued, $item['item_low_stock_alert']);
                                
                                if (!$update_delivery_stmt->execute()) {
                                    throw new Exception("Failed to update delivery location inventory for item {$item['item_id']}: " . $update_delivery_stmt->error);
                                }
                                $update_delivery_stmt->close();
                                
                                // Record delivery transaction
                                $delivery_trans_sql = "INSERT INTO inventory_transactions SET
                                                    item_id = ?,
                                                    location_id = ?,
                                                    transaction_type = 'requisition_in',
                                                    quantity_change = ?,
                                                    previous_quantity = 0,
                                                    new_quantity = ?,
                                                    transaction_reference = ?,
                                                    performed_by = ?,
                                                    transaction_notes = ?,
                                                    transaction_date = NOW()";
                                $delivery_trans_stmt = $mysqli->prepare($delivery_trans_sql);
                                $delivery_trans_notes = 'Partial requisition receipt from source location for item ' . $item['item_name'];
                                $delivery_trans_stmt->bind_param("iiiiisis", $item['item_id'], $delivery_location_id, $quantity_issued, 
                                                               $quantity_issued, $trans_ref, $session_user_id, $delivery_trans_notes);
                                
                                if (!$delivery_trans_stmt->execute()) {
                                    throw new Exception("Failed to create delivery transaction for item {$item['item_id']}: " . $delivery_trans_stmt->error);
                                }
                                $delivery_trans_stmt->close();
                            }
                            
                            // Update issued quantity (partial fulfillment)
                            $update_issued_sql = "UPDATE inventory_requisition_items 
                                                 SET quantity_issued = ? 
                                                 WHERE requisition_item_id = ?";
                            $update_issued_stmt = $mysqli->prepare($update_issued_sql);
                            $update_issued_stmt->bind_param("ii", $quantity_issued, $item['requisition_item_id']);
                            
                            if (!$update_issued_stmt->execute()) {
                                throw new Exception("Failed to update issued quantity for item {$item['requisition_item_id']}: " . $update_issued_stmt->error);
                            }
                            $update_issued_stmt->close();
                            
                            $total_issued += $quantity_issued;
                            $items_processed++;
                        }
                    }
                }
                $items_stmt->close();
                
                // Update requisition status based on fulfillment
                if ($items_processed == 0) {
                    $new_status = 'approved';
                    $message = "No items could be issued due to insufficient stock.";
                } elseif ($total_issued == $total_approved && !$has_insufficient_stock) {
                    $new_status = 'fulfilled';
                    $message = "Requisition fully fulfilled! $total_issued items transferred from source to delivery location.";
                } else {
                    $new_status = 'partial';
                    $message = "Requisition partially fulfilled. $total_issued out of $total_approved items transferred.";
                }
                
                $update_status_sql = "UPDATE inventory_requisitions SET status = ? WHERE requisition_id = ?";
                $update_status_stmt = $mysqli->prepare($update_status_sql);
                $update_status_stmt->bind_param("si", $new_status, $requisition_id);
                
                if (!$update_status_stmt->execute()) {
                    throw new Exception("Failed to update requisition status: " . $update_status_stmt->error);
                }
                $update_status_stmt->close();
                
                $mysqli->commit();
                
                // Log the action
                $log_sql = "INSERT INTO logs SET
                          log_type = 'Requisition',
                          log_action = 'Fulfill',
                          log_description = ?,
                          log_ip = ?,
                          log_user_agent = ?,
                          log_user_id = ?,
                          log_entity_id = ?,
                          log_created_at = NOW()";
                $log_stmt = $mysqli->prepare($log_sql);
                $log_description = "Fulfilled requisition #" . $requisition_id . " (Status: " . $new_status . ", Transferred: " . $total_issued . " items)";
                $log_stmt->bind_param("sssii", $log_description, $session_ip, $session_user_agent, $session_user_id, $requisition_id);
                $log_stmt->execute();
                $log_stmt->close();
                
                $_SESSION['alert_type'] = "success";
                $_SESSION['alert_message'] = $message;
                
            } catch (Exception $e) {
                $mysqli->rollback();
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = "Error fulfilling requisition: " . $e->getMessage();
            }
            
            header("Location: inventory_requisition_details.php?requisition_id=" . $requisition_id);
            exit;
            break;
    }
}

// Get requisition details - UPDATED to include delivery location
$requisition_sql = mysqli_query($mysqli, "
    SELECT 
        r.*,
        u.user_name as requester_name,
        a.user_name as approver_name,
        l.location_name as source_location_name,
        l.location_type as source_location_type,
        dl.location_name as delivery_location_name,
        dl.location_type as delivery_location_type,
        (SELECT COUNT(*) FROM inventory_requisition_items ri WHERE ri.requisition_id = r.requisition_id) as item_count,
        (SELECT SUM(ri.quantity_requested) FROM inventory_requisition_items ri WHERE ri.requisition_id = r.requisition_id) as total_requested,
        (SELECT SUM(ri.quantity_approved) FROM inventory_requisition_items ri WHERE ri.requisition_id = r.requisition_id) as total_approved,
        (SELECT SUM(ri.quantity_issued) FROM inventory_requisition_items ri WHERE ri.requisition_id = r.requisition_id) as total_issued
    FROM inventory_requisitions r
    LEFT JOIN users u ON r.requested_by = u.user_id
    LEFT JOIN users a ON r.approved_by = a.user_id
    LEFT JOIN inventory_locations l ON r.location_id = l.location_id  -- Source location
    LEFT JOIN inventory_locations dl ON r.delivery_location_id = dl.location_id  -- Delivery location
    WHERE r.requisition_id = $requisition_id
");

if (mysqli_num_rows($requisition_sql) == 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Requisition not found.";
    header("Location: inventory_requisitions.php");
    exit;
}

$requisition = mysqli_fetch_assoc($requisition_sql);

// Get delivery location stock information
$delivery_stock_info = [
    'item_count' => 0,
    'total_stock' => 0
];

if ($requisition['delivery_location_id']) {
    $delivery_stock_sql = "SELECT COUNT(*) as item_count, COALESCE(SUM(quantity), 0) as total_stock 
                          FROM inventory_location_items 
                          WHERE location_id = ?";
    $delivery_stmt = $mysqli->prepare($delivery_stock_sql);
    $delivery_stmt->bind_param("i", $requisition['delivery_location_id']);
    $delivery_stmt->execute();
    $delivery_result = $delivery_stmt->get_result();
    $delivery_stock_info = $delivery_result->fetch_assoc();
    $delivery_stmt->close();
}

// Get requisition items
$items_result = mysqli_query($mysqli, "
    SELECT 
        ri.requisition_item_id,
        ri.*,
        i.item_name,
        i.item_code,
        i.item_brand,
        i.item_quantity as current_stock,
        i.item_low_stock_alert,
        i.item_unit_cost,
        i.item_unit_price,
        i.item_unit_measure,
        ic.category_name,
        ic.category_color,
        il.location_name as item_location
    FROM inventory_requisition_items ri
    JOIN inventory_items i ON ri.item_id = i.item_id
    LEFT JOIN invoice_categories ic ON i.item_category_id = ic.category_id
    LEFT JOIN inventory_locations il ON i.location_id = il.location_id
    WHERE ri.requisition_id = $requisition_id
    ORDER BY i.item_name
");

// Store items in array for multiple uses
$items = [];
while ($item = mysqli_fetch_assoc($items_result)) {
    $items[] = $item;
}

// Status options for dropdown
$status_options = [
    'pending' => 'Pending',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
    'fulfilled' => 'Fulfilled',
    'partial' => 'Partially Fulfilled'
];

// Status badge classes
$status_badges = [
    'pending' => 'warning',
    'approved' => 'success',
    'rejected' => 'danger',
    'fulfilled' => 'info',
    'partial' => 'primary'
];
?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h3 class="card-title mt-2 mb-0 text-white">
                    <i class="fas fa-fw fa-clipboard-list mr-2"></i>Requisition: <?php echo htmlspecialchars($requisition['requisition_number']); ?>
                </h3>
                <small class="text-light">
                    Created by <?php echo htmlspecialchars($requisition['requester_name']); ?> 
                    on <?php echo date('M j, Y', strtotime($requisition['requisition_date'])); ?>
                </small>
            </div>
            <div class="card-tools">
                <a href="inventory_requisitions.php" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Requisitions
                </a>
            </div>
        </div>
    </div>

    <div class="card-body">
        <?php if (isset($_SESSION['alert_message'])): ?>
            <div class="alert alert-<?php echo $_SESSION['alert_type']; ?> alert-dismissible">
                <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
                <i class="icon fas fa-<?php echo $_SESSION['alert_type'] == 'success' ? 'check' : ($_SESSION['alert_type'] == 'warning' ? 'exclamation-triangle' : 'exclamation-triangle'); ?>"></i>
                <?php echo $_SESSION['alert_message']; ?>
            </div>
            <?php 
            unset($_SESSION['alert_type']);
            unset($_SESSION['alert_message']);
            ?>
        <?php endif; ?>

        <div class="row">
            <div class="col-md-8">
                <!-- Requisition Details -->
                <div class="card card-primary">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h3 class="card-title"><i class="fas fa-info-circle mr-2"></i>Requisition Details</h3>
                        <span class="badge badge-<?php echo $status_badges[$requisition['status']]; ?> badge-lg">
                            <?php echo ucfirst($requisition['status']); ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td class="font-weight-bold" width="40%">Requisition Number:</td>
                                        <td><?php echo htmlspecialchars($requisition['requisition_number']); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="font-weight-bold">Requester:</td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($requisition['requester_name']); ?></strong>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="font-weight-bold">Requisition Date:</td>
                                        <td><?php echo date('M j, Y', strtotime($requisition['requisition_date'])); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="font-weight-bold">Source Location:</td>
                                        <td>
                                            <?php if ($requisition['source_location_name']): ?>
                                                <strong><?php echo htmlspecialchars($requisition['source_location_name']); ?></strong>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($requisition['source_location_type']); ?></small>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td class="font-weight-bold" width="40%">Status:</td>
                                        <td>
                                            <span class="badge badge-<?php echo $status_badges[$requisition['status']]; ?>">
                                                <?php echo ucfirst($requisition['status']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="font-weight-bold">Priority:</td>
                                        <td>
                                            <span class="badge badge-<?php 
                                                switch($requisition['priority']) {
                                                    case 'urgent': echo 'danger'; break;
                                                    case 'high': echo 'warning'; break;
                                                    case 'normal': echo 'primary'; break;
                                                    case 'low': echo 'secondary'; break;
                                                    default: echo 'light';
                                                }
                                            ?>">
                                                <?php echo ucfirst($requisition['priority']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="font-weight-bold">Delivery Location:</td>
                                        <td>
                                            <?php if ($requisition['delivery_location_name']): ?>
                                                <strong><?php echo htmlspecialchars($requisition['delivery_location_name']); ?></strong>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($requisition['delivery_location_type']); ?></small>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php if ($requisition['approved_date']): ?>
                                    <tr>
                                        <td class="font-weight-bold">Approved:</td>
                                        <td>
                                            <?php echo date('M j, Y g:i A', strtotime($requisition['approved_date'])); ?>
                                            <?php if ($requisition['approver_name']): ?>
                                                <br><small class="text-muted">by <?php echo htmlspecialchars($requisition['approver_name']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </table>
                            </div>
                        </div>

                        <?php if (!empty($requisition['notes'])): ?>
                        <div class="row mt-3">
                            <div class="col-12">
                                <label class="font-weight-bold">Requisition Notes:</label>
                                <div class="border rounded p-3 bg-light">
                                    <?php echo nl2br(htmlspecialchars($requisition['notes'])); ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Requisition Items -->
                <div class="card card-warning">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h3 class="card-title"><i class="fas fa-list-ol mr-2"></i>Requisition Items</h3>
                        <?php if ($requisition['status'] == 'pending'): ?>
                            <form method="POST" action="inventory_requisition_details.php?requisition_id=<?php echo $requisition_id; ?>" id="approveForm" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <input type="hidden" name="action" value="approve">
                                <button type="button" class="btn btn-success btn-sm" data-toggle="modal" data-target="#approveModal">
                                    <i class="fas fa-check mr-1"></i>Approve Items
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Item Details</th>
                                        <th class="text-center">Current Stock</th>
                                        <th class="text-center">Requested</th>
                                        <th class="text-center">Approved</th>
                                        <th class="text-center">Issued</th>
                                        <th class="text-center">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $total_requested = 0;
                                    $total_approved = 0;
                                    $total_issued = 0;
                                    
                                    foreach ($items as $item): 
                                        $total_requested += $item['quantity_requested'];
                                        $total_approved += $item['quantity_approved'];
                                        $total_issued += $item['quantity_issued'];
                                        
                                        // Stock status
                                        $stock_status = '';
                                        $stock_class = '';
                                        if ($item['current_stock'] == 0) {
                                            $stock_status = 'Out of Stock';
                                            $stock_class = 'danger';
                                        } elseif ($item['current_stock'] <= $item['item_low_stock_alert']) {
                                            $stock_status = 'Low Stock';
                                            $stock_class = 'warning';
                                        } else {
                                            $stock_status = 'In Stock';
                                            $stock_class = 'success';
                                        }
                                    ?>
                                        <tr>
                                            <td>
                                                <div class="font-weight-bold"><?php echo htmlspecialchars($item['item_name']); ?></div>
                                                <div class="small text-muted"><?php echo htmlspecialchars($item['item_code']); ?></div>
                                                <?php if (!empty($item['item_brand'])): ?>
                                                    <div class="small text-info"><?php echo htmlspecialchars($item['item_brand']); ?></div>
                                                <?php endif; ?>
                                                <?php if ($item['category_name']): ?>
                                                    <span class="badge badge-sm" style="background-color: <?php echo $item['category_color']; ?>; color: white;">
                                                        <?php echo htmlspecialchars($item['category_name']); ?>
                                                    </span>
                                                <?php endif; ?>
                                                <?php if (!empty($item['item_location'])): ?>
                                                    <div class="small text-muted">
                                                        <i class="fas fa-map-marker-alt mr-1"></i><?php echo htmlspecialchars($item['item_location']); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge badge-<?php echo $stock_class; ?> badge-pill">
                                                    <?php echo $item['current_stock']; ?>
                                                </span>
                                                <div class="small text-muted"><?php echo $item['item_unit_measure']; ?></div>
                                            </td>
                                            <td class="text-center">
                                                <span class="font-weight-bold"><?php echo $item['quantity_requested']; ?></span>
                                            </td>
                                            <td class="text-center">
                                                <?php if ($requisition['status'] == 'pending'): ?>
                                                    <!-- Input field for approval -->
                                                    <input type="number" 
                                                           class="form-control form-control-sm text-center approved-quantity-input" 
                                                           name="approved_quantity[<?php echo $item['requisition_item_id']; ?>]" 
                                                           value="<?php echo $item['quantity_approved'] > 0 ? $item['quantity_approved'] : $item['quantity_requested']; ?>" 
                                                           min="0" 
                                                           max="<?php echo $item['quantity_requested']; ?>"
                                                           form="approveForm"
                                                           style="width: 80px; margin: 0 auto;">
                                                <?php else: ?>
                                                    <span class="font-weight-bold text-<?php echo $item['quantity_approved'] == $item['quantity_requested'] ? 'success' : 'warning'; ?>">
                                                        <?php echo $item['quantity_approved']; ?>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <span class="font-weight-bold text-<?php echo $item['quantity_issued'] == $item['quantity_approved'] ? 'success' : 'info'; ?>">
                                                    <?php echo $item['quantity_issued']; ?>
                                                </span>
                                            </td>
                                            <td class="text-center">
                                                <?php if ($item['quantity_issued'] > 0): ?>
                                                    <?php if ($item['quantity_issued'] == $item['quantity_approved']): ?>
                                                        <span class="badge badge-success">Fully Issued</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-warning">Partially Issued</span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="badge badge-secondary">Pending</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="bg-light">
                                    <tr>
                                        <td class="text-right font-weight-bold">Totals:</td>
                                        <td></td>
                                        <td class="text-center font-weight-bold"><?php echo $total_requested; ?></td>
                                        <td class="text-center font-weight-bold"><?php echo $total_approved; ?></td>
                                        <td class="text-center font-weight-bold"><?php echo $total_issued; ?></td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Activity Log -->
                <?php
                $log_sql = "SELECT * FROM logs WHERE log_entity_id = ? AND log_type = 'Requisition' ORDER BY log_created_at DESC";
                $log_stmt = $mysqli->prepare($log_sql);
                $log_stmt->bind_param("i", $requisition_id);
                $log_stmt->execute();
                $log_result = $log_stmt->get_result();
                $activity_logs = $log_result->fetch_all(MYSQLI_ASSOC);
                $log_stmt->close();

                if (count($activity_logs) > 0): ?>
                <div class="card card-info">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-history mr-2"></i>Activity Log</h3>
                    </div>
                    <div class="card-body">
                        <div class="timeline">
                            <?php foreach ($activity_logs as $log): ?>
                            <div class="timeline-item">
                                <div class="timeline-header">
                                    <strong><?php echo ucfirst($log['log_action']); ?></strong>
                                    <span class="float-right text-muted small">
                                        <?php echo date('M j, Y g:i A', strtotime($log['log_created_at'])); ?>
                                    </span>
                                </div>
                                <?php if (!empty($log['log_description'])): ?>
                                <div class="timeline-body">
                                    <?php echo htmlspecialchars($log['log_description']); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="col-md-4">
                <!-- Quick Actions -->
                <div class="card card-success">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-bolt mr-2"></i>Quick Actions</h3>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <?php if ($requisition['status'] == 'pending'): ?>
                                <a href="?requisition_id=<?php echo $requisition_id; ?>&action=reject" class="btn btn-danger mb-2" onclick="return confirm('Are you sure you want to reject this requisition?')">
                                    <i class="fas fa-times mr-2"></i>Reject Requisition
                                </a>
                            <?php elseif (in_array($requisition['status'], ['approved', 'partial'])): ?>
                                <a href="?requisition_id=<?php echo $requisition_id; ?>&action=fulfill" class="btn btn-info mb-2" onclick="return confirm('Process fulfillment for this requisition? This will transfer items from source to delivery location.')">
                                    <i class="fas fa-truck-loading mr-2"></i>Process Fulfillment
                                </a>
                            <?php elseif ($requisition['status'] == 'fulfilled'): ?>
                                <div class="text-center text-success mb-2">
                                    <i class="fas fa-check-circle fa-2x mb-2"></i>
                                    <div class="font-weight-bold">Fulfillment Complete</div>
                                </div>
                            <?php endif; ?>
                            
                            <a href="inventory_requisition_print.php?requisition_id=<?php echo $requisition_id; ?>" target="_blank" class="btn btn-info">
                                <i class="fas fa-print mr-2"></i>Print Requisition
                            </a>
                            
                            <?php if ($requisition['status'] == 'pending'): ?>
                                <a href="inventory_requisition_edit.php?requisition_id=<?php echo $requisition_id; ?>" class="btn btn-warning">
                                    <i class="fas fa-edit mr-2"></i>Edit Requisition
                                </a>
                            <?php endif; ?>
                            
                            <a href="inventory_requisition_create.php?duplicate=<?php echo $requisition_id; ?>" class="btn btn-outline-secondary">
                                <i class="fas fa-copy mr-2"></i>Duplicate Requisition
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Requisition Summary -->
                <div class="card card-warning">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-chart-bar mr-2"></i>Requisition Summary</h3>
                    </div>
                    <div class="card-body">
                        <div class="text-center mb-3">
                            <i class="fas fa-clipboard-list fa-3x text-warning mb-2"></i>
                            <h5><?php echo htmlspecialchars($requisition['requisition_number']); ?></h5>
                            <div class="text-muted"><?php echo $requisition['item_count']; ?> items</div>
                        </div>
                        <hr>
                        <div class="small">
                            <div class="d-flex justify-content-between mb-2">
                                <span>Total Requested:</span>
                                <span class="font-weight-bold text-warning"><?php echo $requisition['total_requested']; ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Total Approved:</span>
                                <span class="font-weight-bold text-success"><?php echo $requisition['total_approved']; ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Total Issued:</span>
                                <span class="font-weight-bold text-info"><?php echo $requisition['total_issued']; ?></span>
                            </div>
                            <hr>
                            <div class="d-flex justify-content-between">
                                <span class="font-weight-bold">Completion:</span>
                                <span class="font-weight-bold">
                                    <?php if ($requisition['total_approved'] > 0): ?>
                                        <?php echo round(($requisition['total_issued'] / $requisition['total_approved']) * 100, 1); ?>%
                                    <?php else: ?>
                                        0%
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Location Information -->
                <div class="card card-info">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-map-marker-alt mr-2"></i>Location Information</h3>
                    </div>
                    <div class="card-body">
                        <!-- Source Location -->
                        <div class="mb-3">
                            <h6 class="font-weight-bold text-primary">
                                <i class="fas fa-warehouse mr-1"></i>Source Location
                            </h6>
                            <?php if ($requisition['source_location_name']): ?>
                                <div class="pl-3">
                                    <strong><?php echo htmlspecialchars($requisition['source_location_name']); ?></strong>
                                    <br><small class="text-muted"><?php echo htmlspecialchars($requisition['source_location_type']); ?></small>
                                </div>
                            <?php else: ?>
                                <div class="pl-3 text-muted">—</div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Delivery Location -->
                        <div class="mb-3">
                            <h6 class="font-weight-bold text-success">
                                <i class="fas fa-truck mr-1"></i>Delivery Location
                            </h6>
                            <?php if ($requisition['delivery_location_name']): ?>
                                <div class="pl-3">
                                    <strong><?php echo htmlspecialchars($requisition['delivery_location_name']); ?></strong>
                                    <br><small class="text-muted"><?php echo htmlspecialchars($requisition['delivery_location_type']); ?></small>
                                    <?php if ($requisition['status'] == 'fulfilled' || $requisition['status'] == 'partial'): ?>
                                        <div class="small mt-1">
                                            <i class="fas fa-boxes mr-1"></i>
                                            <?php echo $delivery_stock_info['item_count']; ?> items, 
                                            <?php echo $delivery_stock_info['total_stock']; ?> total stock
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="pl-3 text-muted">No delivery location specified</div>
                            <?php endif; ?>
                        </div>
                        
                        <hr>
                        
                        <div class="small">
                            <div class="mb-2">
                                <i class="fas fa-user mr-2 text-muted"></i>
                                <strong>Requester:</strong> <?php echo htmlspecialchars($requisition['requester_name']); ?>
                            </div>
                            
                            <?php if ($requisition['approved_by']): ?>
                                <div class="mb-2">
                                    <i class="fas fa-user-check mr-2 text-muted"></i>
                                    <strong>Approved By:</strong> <?php echo htmlspecialchars($requisition['approver_name']); ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="mb-2">
                                <i class="fas fa-calendar mr-2 text-muted"></i>
                                <strong>Date:</strong> <?php echo date('M j, Y', strtotime($requisition['requisition_date'])); ?>
                            </div>
                            
                            <div>
                                <i class="fas fa-flag mr-2 text-muted"></i>
                                <strong>Priority:</strong> 
                                <span class="badge badge-<?php 
                                    switch($requisition['priority']) {
                                        case 'urgent': echo 'danger'; break;
                                        case 'high': echo 'warning'; break;
                                        case 'normal': echo 'primary'; break;
                                        case 'low': echo 'secondary'; break;
                                        default: echo 'light';
                                    }
                                ?>">
                                    <?php echo ucfirst($requisition['priority']); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Approve Modal -->
<?php if ($requisition['status'] == 'pending'): ?>
<div class="modal fade" id="approveModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-check mr-2"></i>Approve Requisition</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <p>Review the requested quantities and adjust approved quantities as needed:</p>
                <div class="alert alert-warning">
                    <i class="fas fa-info-circle mr-2"></i>
                    <strong>Note:</strong> You can reduce approved quantities based on current stock availability.
                </div>
                <div class="alert alert-info">
                    <i class="fas fa-database mr-2"></i>
                    <strong>Current Status:</strong> 
                    <?php echo $requisition['item_count']; ?> items, 
                    <?php echo $requisition['total_requested']; ?> total requested
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="submit" form="approveForm" class="btn btn-success" id="submitApprove">
                    <i class="fas fa-check mr-2"></i>Approve Requisition
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
$(document).ready(function() {
    // Auto-focus on first quantity input when modal opens
    $('#approveModal').on('shown.bs.modal', function() {
        $('.approved-quantity-input').first().focus();
    });

    // Enhanced form validation
    $('#approveForm').on('submit', function(e) {
        let valid = true;
        let hasErrors = false;
        let totalApproved = 0;
        
        $('.approved-quantity-input').each(function() {
            const requested = parseInt($(this).attr('max'));
            const approved = parseInt($(this).val()) || 0;
            
            if (approved < 0) {
                alert('Approved quantity cannot be negative.');
                $(this).focus();
                valid = false;
                hasErrors = true;
                return false;
            }
            
            if (approved > requested) {
                alert('Approved quantity cannot exceed requested quantity.');
                $(this).focus();
                valid = false;
                hasErrors = true;
                return false;
            }
            
            if (approved === 0) {
                if (!confirm('Are you sure you want to approve 0 quantity for this item?')) {
                    $(this).focus();
                    valid = false;
                    hasErrors = true;
                    return false;
                }
            }
            
            totalApproved += approved;
        });
        
        if (totalApproved === 0 && !hasErrors) {
            if (!confirm('You are about to approve 0 quantities for all items. This will effectively reject the requisition. Continue?')) {
                valid = false;
            }
        }
        
        if (!valid) {
            e.preventDefault();
            return false;
        }
        
        // Show loading state
        $('#submitApprove').prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-2"></i>Processing...');
        
        return true;
    });

    // Real-time quantity validation
    $(document).on('input', '.approved-quantity-input', function() {
        const requested = parseInt($(this).attr('max'));
        const approved = parseInt($(this).val()) || 0;
        
        if (approved > requested) {
            $(this).addClass('is-invalid');
            $(this).next('.invalid-feedback').remove();
            $(this).after('<div class="invalid-feedback">Cannot exceed ' + requested + '</div>');
        } else if (approved < 0) {
            $(this).addClass('is-invalid');
            $(this).next('.invalid-feedback').remove();
            $(this).after('<div class="invalid-feedback">Cannot be negative</div>');
        } else {
            $(this).removeClass('is-invalid');
            $(this).next('.invalid-feedback').remove();
        }
    });
});

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Escape to close modals
    if (e.keyCode === 27) {
        $('.modal').modal('hide');
    }
    // Ctrl+P to print
    if (e.ctrlKey && e.keyCode === 80) {
        e.preventDefault();
        window.open('inventory_requisition_print.php?requisition_id=<?php echo $requisition_id; ?>', '_blank');
    }
});
</script>

<style>
.badge-lg {
    font-size: 1rem;
    padding: 0.5em 0.8em;
}

.timeline {
    position: relative;
    padding-left: 2rem;
}

.timeline::before {
    content: '';
    position: absolute;
    left: 7px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: #dee2e6;
}

.timeline-item {
    position: relative;
    margin-bottom: 1.5rem;
}

.timeline-item::before {
    content: '';
    position: absolute;
    left: -2rem;
    top: 0.25rem;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: #007bff;
    border: 2px solid white;
}

.timeline-header {
    margin-bottom: 0.5rem;
}

.timeline-body {
    background: #f8f9fa;
    padding: 0.75rem;
    border-radius: 0.25rem;
    font-size: 0.875rem;
}

.table th {
    border-top: none;
    font-weight: 600;
}

.badge-sm {
    font-size: 0.7rem;
    padding: 0.25em 0.5em;
}

.is-invalid {
    border-color: #dc3545;
    box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25);
}

.invalid-feedback {
    display: block;
    width: 100%;
    margin-top: 0.25rem;
    font-size: 0.875em;
    color: #dc3545;
}

/* Loading state */
.btn:disabled {
    cursor: not-allowed;
    opacity: 0.6;
}

.approved-quantity-input {
    transition: all 0.3s ease;
}

.approved-quantity-input:focus {
    border-color: #28a745;
    box-shadow: 0 0 0 0.2rem rgba(40, 167, 69, 0.25);
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Get item ID from URL (optional)
$item_id = intval($_GET['item_id'] ?? 0);
$transaction_type = 'out'; // Fixed to only allow out transactions
$requisition_id = intval($_GET['requisition_id'] ?? 0);
$location_id = intval($_GET['location_id'] ?? 0);

// Initialize variables
$items = [];
$item = null;
$requisition = null;
$locations = [];

// Get all active locations for dropdown
$locations_sql = "SELECT location_id, location_name, location_type 
                  FROM inventory_locations 
                  WHERE is_active = 1 
                  ORDER BY location_type, location_name";
$locations_result = $mysqli->query($locations_sql);
while ($location_row = $locations_result->fetch_assoc()) {
    $locations[] = $location_row;
}

// Get items with their current stock from inventory_location_stock
$items_sql = "SELECT 
                i.item_id, 
                i.item_name, 
                i.item_code,
                i.category_id,
                i.unit_of_measure,
                i.status,
                COALESCE(SUM(ils.quantity), 0) as total_stock,
                i.reorder_level
              FROM inventory_items i
              LEFT JOIN inventory_location_stock ils ON i.item_id = ils.item_id
              WHERE i.status = 'active' 
              AND i.is_active = 1
              GROUP BY i.item_id, i.item_name, i.item_code, i.category_id, i.unit_of_measure, i.status, i.reorder_level
              HAVING total_stock > 0
              ORDER BY i.item_name";
$items_result = $mysqli->query($items_sql);
while ($item_row = $items_result->fetch_assoc()) {
    $items[] = $item_row;
}

// If specific item is requested, get its details with current stock
if ($item_id > 0) {
    $item_sql = "SELECT 
                    i.*,
                    c.category_name,
                    c.category_type,
                    COALESCE(SUM(ils.quantity), 0) as current_stock,
                    GROUP_CONCAT(DISTINCT CONCAT(l.location_name, ' (', ils.quantity, ')') SEPARATOR ', ') as stock_locations
                 FROM inventory_items i
                 LEFT JOIN inventory_categories c ON i.category_id = c.category_id
                 LEFT JOIN inventory_location_stock ils ON i.item_id = ils.item_id
                 LEFT JOIN inventory_locations l ON ils.location_id = l.location_id
                 WHERE i.item_id = ? AND i.status = 'active'
                 GROUP BY i.item_id";
    $item_stmt = $mysqli->prepare($item_sql);
    $item_stmt->bind_param("i", $item_id);
    $item_stmt->execute();
    $item_result = $item_stmt->get_result();
    
    if ($item_result->num_rows > 0) {
        $item = $item_result->fetch_assoc();
    }
    $item_stmt->close();
}

// If requisition ID is provided, get requisition details
if ($requisition_id > 0) {
    $req_sql = "SELECT r.*, 
                       lf.location_name as from_location_name,
                       lt.location_name as to_location_name,
                       u.user_name as requester_name
                FROM inventory_requisitions r
                LEFT JOIN inventory_locations lf ON r.from_location_id = lf.location_id
                LEFT JOIN inventory_locations lt ON r.delivery_location_id = lt.location_id
                LEFT JOIN users u ON r.requested_by = u.user_id
                WHERE r.requisition_id = ? AND r.is_active = 1";
    $req_stmt = $mysqli->prepare($req_sql);
    $req_stmt->bind_param("i", $requisition_id);
    $req_stmt->execute();
    $req_result = $req_stmt->get_result();
    
    if ($req_result->num_rows > 0) {
        $requisition = $req_result->fetch_assoc();
        
        // Get requisition items
        $req_items_sql = "SELECT 
                            ri.*, 
                            i.item_name, 
                            i.item_code,
                            COALESCE(SUM(ils.quantity), 0) as current_stock
                         FROM inventory_requisition_items ri
                         JOIN inventory_items i ON ri.item_id = i.item_id
                         LEFT JOIN inventory_location_stock ils ON i.item_id = ils.item_id
                         WHERE ri.requisition_id = ? AND ri.is_active = 1
                         GROUP BY ri.requisition_item_id, i.item_id, i.item_name, i.item_code
                         HAVING current_stock > 0";
        $req_items_stmt = $mysqli->prepare($req_items_sql);
        $req_items_stmt->bind_param("i", $requisition_id);
        $req_items_stmt->execute();
        $req_items_result = $req_items_stmt->get_result();
        $requisition_items = [];
        
        while ($req_item = $req_items_result->fetch_assoc()) {
            $requisition_items[] = $req_item;
        }
        $req_items_stmt->close();
    }
    $req_stmt->close();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    $item_id = intval($_POST['item_id']);
    $quantity = floatval($_POST['quantity']);
    $reference = sanitizeInput($_POST['reference']);
    $notes = sanitizeInput($_POST['notes']);
    $related_visit_id = intval($_POST['related_visit_id'] ?? 0);
    $from_location_id = intval($_POST['from_location_id']);
    $batch_id = intval($_POST['batch_id'] ?? 0);
    $requisition_id = intval($_POST['requisition_id'] ?? 0);

    // Validate CSRF token
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        header("Location: inventory_out_transaction.php" . ($item_id ? "?item_id=" . $item_id : ""));
        exit;
    }

    // Validate required fields
    if (empty($item_id) || $quantity <= 0 || empty($from_location_id)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please fill in all required fields with valid values.";
        header("Location: inventory_out_transaction.php" . ($item_id ? "?item_id=" . $item_id : ""));
        exit;
    }

    $mysqli->begin_transaction();

    try {
        // Get available batches for this item at the specified location
        $available_batches_sql = "SELECT 
                                    ils.batch_id,
                                    b.batch_number,
                                    b.expiry_date,
                                    ils.quantity as available_quantity,
                                    ils.location_id
                                  FROM inventory_location_stock ils
                                  JOIN inventory_batches b ON ils.batch_id = b.batch_id
                                  WHERE ils.item_id = ? 
                                  AND ils.location_id = ?
                                  AND ils.quantity > 0
                                  AND b.is_active = 1
                                  ORDER BY b.expiry_date ASC";
        $batch_stmt = $mysqli->prepare($available_batches_sql);
        $batch_stmt->bind_param("ii", $item_id, $from_location_id);
        $batch_stmt->execute();
        $batch_result = $batch_stmt->get_result();
        $available_batches = [];
        
        while ($batch = $batch_result->fetch_assoc()) {
            $available_batches[] = $batch;
        }
        $batch_stmt->close();

        if (empty($available_batches)) {
            throw new Exception("No available stock for this item at the selected location.");
        }

        // If batch_id is specified, check if it's available
        $remaining_quantity = $quantity;
        $batch_transactions = [];
        
        if ($batch_id > 0) {
            $found = false;
            foreach ($available_batches as $batch) {
                if ($batch['batch_id'] == $batch_id) {
                    if ($batch['available_quantity'] < $remaining_quantity) {
                        throw new Exception("Insufficient quantity in batch " . $batch['batch_number'] . ". Available: " . $batch['available_quantity']);
                    }
                    $batch_transactions[] = [
                        'batch_id' => $batch_id,
                        'quantity' => $remaining_quantity
                    ];
                    $remaining_quantity = 0;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                throw new Exception("Selected batch is not available at this location.");
            }
        }

        // If no specific batch or remaining quantity, use FIFO (first expiry, first out)
        if ($remaining_quantity > 0) {
            foreach ($available_batches as $batch) {
                if ($remaining_quantity <= 0) break;
                
                $batch_qty = min($batch['available_quantity'], $remaining_quantity);
                $batch_transactions[] = [
                    'batch_id' => $batch['batch_id'],
                    'quantity' => $batch_qty
                ];
                $remaining_quantity -= $batch_qty;
            }
            
            if ($remaining_quantity > 0) {
                throw new Exception("Insufficient total stock at this location.");
            }
        }

        // Process each batch transaction
        $total_quantity = $quantity;
        $total_cost = 0;
        
        foreach ($batch_transactions as $batch_trans) {
            $current_batch_id = $batch_trans['batch_id'];
            $batch_qty = $batch_trans['quantity'];
            
            // Get batch details for cost calculation
            $batch_cost_sql = "SELECT ils.unit_cost 
                               FROM inventory_location_stock ils 
                               WHERE ils.batch_id = ? AND ils.location_id = ?";
            $cost_stmt = $mysqli->prepare($batch_cost_sql);
            $cost_stmt->bind_param("ii", $current_batch_id, $from_location_id);
            $cost_stmt->execute();
            $cost_result = $cost_stmt->get_result();
            $batch_cost = $cost_result->fetch_assoc()['unit_cost'] ?? 0;
            $cost_stmt->close();
            
            $total_cost += ($batch_qty * $batch_cost);
            
            // Update inventory_location_stock
            $update_stock_sql = "UPDATE inventory_location_stock 
                                SET quantity = quantity - ?,
                                    updated_at = NOW(),
                                    updated_by = ?
                                WHERE batch_id = ? AND location_id = ?";
            $update_stmt = $mysqli->prepare($update_stock_sql);
            $update_stmt->bind_param("diii", $batch_qty, $session_user_id, $current_batch_id, $from_location_id);
            
            if (!$update_stmt->execute()) {
                throw new Exception("Failed to update stock for batch: " . $update_stmt->error);
            }
            $update_stmt->close();
            
            // Remove zero quantity entries
            $cleanup_sql = "DELETE FROM inventory_location_stock 
                           WHERE batch_id = ? AND location_id = ? AND quantity <= 0";
            $cleanup_stmt = $mysqli->prepare($cleanup_sql);
            $cleanup_stmt->bind_param("ii", $current_batch_id, $from_location_id);
            $cleanup_stmt->execute();
            $cleanup_stmt->close();
            
            // Record transaction for this batch
            $transaction_sql = "INSERT INTO inventory_transactions (
                transaction_type, item_id, batch_id, from_location_id, to_location_id,
                quantity, unit_cost, reference_type, reference_id, reason,
                created_by, created_at
            ) VALUES ('ISSUE', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            
            $ref_type = $requisition_id > 0 ? 'requisition' : 'manual';
            $ref_id = $requisition_id > 0 ? $requisition_id : 0;
            $trans_reason = $notes ?: "Issue from " . $from_location_id;
            
            $trans_stmt = $mysqli->prepare($transaction_sql);
            $trans_stmt->bind_param(
                "iiiiidssisi",
                $item_id,
                $current_batch_id,
                $from_location_id,
                $to_location_id, // 0 for out transactions
                $batch_qty,
                $batch_cost,
                $ref_type,
                $ref_id,
                $trans_reason,
                $session_user_id
            );
            
            if (!$trans_stmt->execute()) {
                throw new Exception("Failed to record transaction: " . $trans_stmt->error);
            }
            $trans_stmt->close();
        }

        // Update requisition if this is a fulfillment
        if ($requisition_id > 0) {
            // Update the issued quantity in requisition items
            $update_req_sql = "UPDATE inventory_requisition_items 
                              SET quantity_issued = quantity_issued + ?,
                                  updated_at = NOW(),
                                  updated_by = ?
                              WHERE requisition_id = ? AND item_id = ?";
            $update_req_stmt = $mysqli->prepare($update_req_sql);
            $update_req_stmt->bind_param("diii", $total_quantity, $session_user_id, $requisition_id, $item_id);
            
            if (!$update_req_stmt->execute()) {
                throw new Exception("Failed to update requisition items: " . $update_req_stmt->error);
            }
            $update_req_stmt->close();
            
            // Check if requisition is fully fulfilled
            $check_req_sql = "SELECT 
                SUM(quantity_approved) as total_approved,
                SUM(quantity_issued) as total_issued
                FROM inventory_requisition_items 
                WHERE requisition_id = ? AND is_active = 1";
            $check_req_stmt = $mysqli->prepare($check_req_sql);
            $check_req_stmt->bind_param("i", $requisition_id);
            $check_req_stmt->execute();
            $req_status = $check_req_stmt->get_result()->fetch_assoc();
            $check_req_stmt->close();
            
            // Update requisition status
            $new_req_status = 'partial';
            if ($req_status['total_issued'] >= $req_status['total_approved']) {
                $new_req_status = 'fulfilled';
            }
            
            $update_status_sql = "UPDATE inventory_requisitions 
                                 SET status = ?, updated_at = NOW(), updated_by = ?
                                 WHERE requisition_id = ?";
            $update_status_stmt = $mysqli->prepare($update_status_sql);
            $update_status_stmt->bind_param("sii", $new_req_status, $session_user_id, $requisition_id);
            
            if (!$update_status_stmt->execute()) {
                throw new Exception("Failed to update requisition status: " . $update_status_stmt->error);
            }
            $update_status_stmt->close();
        }

        // Log the action
        $log_sql = "INSERT INTO logs SET
                  log_type = 'Inventory',
                  log_action = 'Issue',
                  log_description = ?,
                  log_ip = ?,
                  log_user_agent = ?,
                  log_user_id = ?,
                  log_entity_id = ?,
                  log_created_at = NOW()";
        $log_stmt = $mysqli->prepare($log_sql);
        $log_description = "Issued item #" . $item_id . 
                          " (Qty: " . $total_quantity . 
                          ", Ref: " . ($reference ?: 'N/A') . 
                          ", From Location: " . $from_location_id . ")";
        $log_stmt->bind_param("sssii", $log_description, $session_ip, $session_user_agent, $session_user_id, $item_id);
        $log_stmt->execute();
        $log_stmt->close();

        $mysqli->commit();

        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Item issued successfully! Total quantity: " . $total_quantity . ".";
        
        if ($requisition_id > 0) {
            header("Location: inventory_requisition_details.php?requisition_id=" . $requisition_id);
        } else {
            header("Location: inventory_item_details.php?item_id=" . $item_id);
        }
        exit;

    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error recording transaction: " . $e->getMessage();
        header("Location: inventory_out_transaction.php" . ($item_id ? "?item_id=" . $item_id : ""));
        exit;
    }
}

// Get available batches for selected item/location (for batch selection)
function getAvailableBatches($item_id, $location_id) {
    global $mysqli;
    $batches = [];
    
    if ($item_id > 0 && $location_id > 0) {
        $sql = "SELECT 
                    b.batch_id,
                    b.batch_number,
                    b.expiry_date,
                    b.manufacturer,
                    ils.quantity as available_quantity
                FROM inventory_location_stock ils
                JOIN inventory_batches b ON ils.batch_id = b.batch_id
                WHERE ils.item_id = ? 
                AND ils.location_id = ?
                AND ils.quantity > 0
                AND b.is_active = 1
                ORDER BY b.expiry_date ASC";
        
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("ii", $item_id, $location_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($batch = $result->fetch_assoc()) {
            $batches[] = $batch;
        }
        $stmt->close();
    }
    
    return $batches;
}
?>

<div class="card">
    <div class="card-header bg-danger py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0 text-white">
                <i class="fas fa-fw fa-arrow-up mr-2"></i>Issue Inventory Items
            </h3>
            <div class="card-tools">
                <a href="inventory.php" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Inventory
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

        <!-- Requisition Information (if applicable) -->
        <?php if ($requisition): ?>
        <div class="card card-info mb-4">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-clipboard-list mr-2"></i>Requisition Information</h3>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <strong>Requisition #:</strong><br>
                        <?php echo htmlspecialchars($requisition['requisition_number']); ?>
                    </div>
                    <div class="col-md-4">
                        <strong>Requester:</strong><br>
                        <?php echo htmlspecialchars($requisition['requester_name']); ?>
                    </div>
                    <div class="col-md-4">
                        <strong>Status:</strong><br>
                        <span class="badge badge-<?php 
                            switch($requisition['status']) {
                                case 'pending': echo 'warning'; break;
                                case 'approved': echo 'success'; break;
                                case 'fulfilled': echo 'info'; break;
                                case 'partial': echo 'primary'; break;
                                default: echo 'secondary';
                            }
                        ?>">
                            <?php echo ucfirst($requisition['status']); ?>
                        </span>
                    </div>
                </div>
                <div class="row mt-2">
                    <div class="col-md-6">
                        <strong>From Location:</strong><br>
                        <?php echo htmlspecialchars($requisition['from_location_name']); ?>
                    </div>
                    <div class="col-md-6">
                        <strong>Delivery To:</strong><br>
                        <?php echo htmlspecialchars($requisition['to_location_name']); ?>
                    </div>
                </div>
                <?php if (!empty($requisition['notes'])): ?>
                <div class="row mt-2">
                    <div class="col-12">
                        <strong>Notes:</strong><br>
                        <?php echo nl2br(htmlspecialchars($requisition['notes'])); ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <form method="POST" id="transactionForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="requisition_id" value="<?php echo $requisition_id; ?>">
            <input type="hidden" name="transaction_type" value="out">
            
            <div class="row">
                <div class="col-md-8">
                    <!-- Transaction Information -->
                    <div class="card card-danger">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-arrow-up mr-2"></i>Issue Item Details</h3>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="item_id">Item to Issue *</label>
                                        <select class="form-control select2" id="item_id" name="item_id" required>
                                            <option value="">- Select Item to Issue -</option>
                                            <?php foreach ($items as $item_row): ?>
                                                <?php 
                                                $selected = ($item && $item['item_id'] == $item_row['item_id']) ? 'selected' : '';
                                                $display_text = htmlspecialchars($item_row['item_name'] . ' (' . $item_row['item_code'] . ') - Stock: ' . $item_row['total_stock']);
                                                ?>
                                                <option value="<?php echo $item_row['item_id']; ?>" 
                                                        data-stock="<?php echo $item_row['total_stock']; ?>"
                                                        data-code="<?php echo $item_row['item_code']; ?>"
                                                        data-name="<?php echo $item_row['item_name']; ?>"
                                                        data-unit="<?php echo $item_row['unit_of_measure']; ?>"
                                                        data-reorder="<?php echo $item_row['reorder_level']; ?>"
                                                        <?php echo $selected; ?>>
                                                    <?php echo $display_text; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="from_location_id">Source Location *</label>
                                        <select class="form-control select2" id="from_location_id" name="from_location_id" required>
                                            <option value="">- Select Source Location -</option>
                                            <?php foreach ($locations as $location): ?>
                                                <option value="<?php echo $location['location_id']; ?>">
                                                    <?php echo htmlspecialchars($location['location_type'] . ' - ' . $location['location_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="form-text text-muted">Where the items are being issued from</small>
                                    </div>
                                </div>
                            </div>

                            <!-- Batch Selection (dynamic) -->
                            <div class="form-group" id="batch_selection_container" style="display: none;">
                                <label for="batch_id">Select Batch (Optional)</label>
                                <select class="form-control select2" id="batch_id" name="batch_id">
                                    <option value="0">- Auto-select (FIFO) -</option>
                                    <!-- Batches will be populated by JavaScript -->
                                </select>
                                <small class="form-text text-muted">Select specific batch or let system auto-select based on expiry date</small>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="quantity">Quantity to Issue *</label>
                                        <input type="number" class="form-control" id="quantity" name="quantity" 
                                               min="0.001" step="0.001" value="1" required>
                                        <small class="form-text text-muted" id="quantity_help">
                                            Enter the quantity to issue
                                        </small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="reference">Issue Reference Number</label>
                                        <input type="text" class="form-control" id="reference" name="reference" 
                                               placeholder="Auto-generated if left blank" maxlength="100">
                                        <small class="form-text text-muted">Issue number, patient ID, etc.</small>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="notes">Issue Notes</label>
                                <textarea class="form-control" id="notes" name="notes" rows="3" 
                                          placeholder="Purpose of issue, recipient details, etc..." 
                                          maxlength="500"></textarea>
                            </div>

                            <div class="form-group">
                                <label for="related_visit_id">Related Patient Visit (Optional)</label>
                                <input type="number" class="form-control" id="related_visit_id" name="related_visit_id" 
                                       placeholder="Enter visit ID if related to patient care" min="1">
                            </div>
                        </div>
                    </div>

                    <!-- Requisition Items (if applicable) -->
                    <?php if ($requisition && !empty($requisition_items)): ?>
                    <div class="card card-warning">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-list mr-2"></i>Requisition Items</h3>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-sm table-striped">
                                    <thead>
                                        <tr>
                                            <th>Item</th>
                                            <th class="text-center">Requested</th>
                                            <th class="text-center">Approved</th>
                                            <th class="text-center">Issued</th>
                                            <th class="text-center">Current Stock</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($requisition_items as $req_item): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($req_item['item_name']); ?></strong><br>
                                                    <small class="text-muted"><?php echo htmlspecialchars($req_item['item_code']); ?></small>
                                                </td>
                                                <td class="text-center"><?php echo $req_item['quantity_requested']; ?></td>
                                                <td class="text-center">
                                                    <span class="font-weight-bold text-success"><?php echo $req_item['quantity_approved']; ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="font-weight-bold text-info"><?php echo $req_item['quantity_issued']; ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge badge-<?php 
                                                        echo $req_item['current_stock'] == 0 ? 'danger' : 
                                                            ($req_item['current_stock'] < $req_item['quantity_approved'] ? 'warning' : 'success');
                                                    ?>">
                                                        <?php echo $req_item['current_stock']; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($req_item['quantity_approved'] > $req_item['quantity_issued'] && $req_item['current_stock'] > 0): ?>
                                                        <button type="button" class="btn btn-sm btn-primary" 
                                                                onclick="selectRequisitionItem(<?php echo $req_item['item_id']; ?>, <?php echo min($req_item['quantity_approved'] - $req_item['quantity_issued'], $req_item['current_stock']); ?>)">
                                                            <i class="fas fa-check mr-1"></i>Select
                                                        </button>
                                                    <?php elseif ($req_item['quantity_approved'] <= $req_item['quantity_issued']): ?>
                                                        <span class="badge badge-success">Fulfilled</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-danger">No Stock</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Issue Preview -->
                    <div class="card card-info">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-calculator mr-2"></i>Issue Preview</h3>
                        </div>
                        <div class="card-body">
                            <div class="row text-center">
                                <div class="col-md-4">
                                    <div class="border rounded p-3 bg-light">
                                        <div class="h5 text-muted">Current Stock</div>
                                        <div class="h2 font-weight-bold text-primary" id="preview_current">
                                            <?php echo $item ? ($item['current_stock'] ?? 0) : '0'; ?>
                                        </div>
                                        <small class="text-muted" id="preview_status_text">Available</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="border rounded p-3 bg-light">
                                        <div class="h5 text-muted">Issuing</div>
                                        <div class="h2 font-weight-bold text-danger" id="preview_change">
                                            -0
                                        </div>
                                        <div class="small text-muted">Quantity to Issue</div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="border rounded p-3 bg-light">
                                        <div class="h5 text-muted">Remaining Stock</div>
                                        <div class="h2 font-weight-bold text-success" id="preview_new">
                                            <?php echo $item ? ($item['current_stock'] ?? 0) : '0'; ?>
                                        </div>
                                        <small class="text-muted" id="preview_new_status_text">After Issue</small>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mt-3 text-center">
                                <div class="badge badge-lg p-2 badge-info" id="location_info">
                                    <i class="fas fa-map-marker-alt mr-1"></i>
                                    <span id="preview_location">No location selected</span>
                                </div>
                                
                                <div class="badge badge-lg p-2 badge-secondary" id="batch_info" style="display: none;">
                                    <i class="fas fa-layer-group mr-1"></i>
                                    <span id="preview_batch">Auto-select (FIFO)</span>
                                </div>
                            </div>

                            <!-- Warnings -->
                            <div class="mt-3 alert alert-warning d-none" id="low_stock_warning">
                                <i class="fas fa-exclamation-triangle mr-2"></i>
                                <strong>Low Stock Warning:</strong> This issue will bring stock below the reorder level.
                            </div>

                            <div class="mt-2 alert alert-danger d-none" id="insufficient_stock_warning">
                                <i class="fas fa-times-circle mr-2"></i>
                                <strong>Insufficient Stock:</strong> Not enough stock available at selected location.
                            </div>

                            <div class="mt-2 alert alert-info d-none" id="batch_warning">
                                <i class="fas fa-exclamation-circle mr-2"></i>
                                <strong>Batch Information:</strong> <span id="batch_warning_text"></span>
                            </div>
                            
                            <!-- Transaction Details -->
                            <div class="mt-3 alert alert-success d-none" id="transaction_details">
                                <i class="fas fa-database mr-2"></i>
                                <strong>Transaction Details:</strong> 
                                <span id="transaction_details_text">Issue will be recorded in transaction history.</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <!-- Quick Actions -->
                    <div class="card card-danger">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-bolt mr-2"></i>Quick Actions</h3>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-danger btn-lg" id="submitBtn">
                                    <i class="fas fa-arrow-up mr-2"></i>Issue Items
                                </button>
                                <button type="reset" class="btn btn-outline-secondary" onclick="resetForm()">
                                    <i class="fas fa-undo mr-2"></i>Reset Form
                                </button>
                                <a href="inventory.php" class="btn btn-outline-dark">
                                    <i class="fas fa-times mr-2"></i>Cancel
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Item Details -->
                    <div class="card card-info" id="item_details_card" style="display: none;">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-cube mr-2"></i>Item Details</h3>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-3">
                                <i class="fas fa-cube fa-3x text-info mb-2"></i>
                                <h5 id="detail_item_name">Item Name</h5>
                                <div class="text-muted" id="detail_item_code">Item Code</div>
                            </div>
                            <hr>
                            <div class="small">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Current Stock:</span>
                                    <span class="font-weight-bold" id="detail_quantity">0</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Reorder Level:</span>
                                    <span class="font-weight-bold" id="detail_reorder">0</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Unit Measure:</span>
                                    <span class="font-weight-bold" id="detail_unit">-</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Status:</span>
                                    <span class="font-weight-bold badge badge-success" id="detail_status">Active</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Available Batches -->
                    <div class="card card-warning" id="batches_card" style="display: none;">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-layer-group mr-2"></i>Available Batches</h3>
                        </div>
                        <div class="card-body">
                            <div id="batches_list">
                                <p class="text-muted mb-0 text-center">No batches available</p>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Quantities -->
                    <div class="card card-secondary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-bolt mr-2"></i>Common Quantities</h3>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <button type="button" class="btn btn-outline-danger btn-sm" onclick="setQuantity(1)">
                                    <i class="fas fa-cube mr-2"></i>1 Unit
                                </button>
                                <button type="button" class="btn btn-outline-danger btn-sm" onclick="setQuantity(5)">
                                    <i class="fas fa-cubes mr-2"></i>5 Units
                                </button>
                                <button type="button" class="btn btn-outline-danger btn-sm" onclick="setQuantity(10)">
                                    <i class="fas fa-box mr-2"></i>10 Units
                                </button>
                                <button type="button" class="btn btn-outline-danger btn-sm" onclick="setQuantity(25)">
                                    <i class="fas fa-boxes mr-2"></i>25 Units
                                </button>
                                <button type="button" class="btn btn-outline-danger btn-sm" onclick="setQuantity(100)">
                                    <i class="fas fa-pallet mr-2"></i>100 Units
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Issues -->
                    <div class="card card-warning">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-history mr-2"></i>Recent Issues</h3>
                        </div>
                        <div class="card-body">
                            <?php
                            $recent_sql = "SELECT 
                                            t.transaction_type, 
                                            t.quantity, 
                                            t.created_at,
                                            t.reason,
                                            i.item_name, 
                                            i.item_code,
                                            b.batch_number,
                                            u.user_name as performed_by,
                                            fl.location_name as from_location
                                        FROM inventory_transactions t
                                        JOIN inventory_items i ON t.item_id = i.item_id
                                        LEFT JOIN inventory_batches b ON t.batch_id = b.batch_id
                                        LEFT JOIN users u ON t.created_by = u.user_id
                                        LEFT JOIN inventory_locations fl ON t.from_location_id = fl.location_id
                                        WHERE t.transaction_type = 'ISSUE'
                                        ORDER BY t.created_at DESC 
                                        LIMIT 5";
                            $recent_result = $mysqli->query($recent_sql);

                            if ($recent_result->num_rows > 0): ?>
                                <div class="list-group list-group-flush">
                                    <?php while ($transaction = $recent_result->fetch_assoc()): ?>
                                        <div class="list-group-item px-0 py-2">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1">
                                                    <span class="badge badge-danger">
                                                        <i class="fas fa-arrow-up mr-1"></i>
                                                        <?php echo $transaction['quantity']; ?>
                                                    </span>
                                                </h6>
                                                <small><?php echo timeAgo($transaction['created_at']); ?></small>
                                            </div>
                                            <p class="mb-1 small">
                                                <?php echo htmlspecialchars($transaction['item_name']); ?>
                                                <?php if ($transaction['batch_number']): ?>
                                                    <br><small class="text-muted">Batch: <?php echo htmlspecialchars($transaction['batch_number']); ?></small>
                                                <?php endif; ?>
                                            </p>
                                            <small class="text-muted">
                                                From: <?php echo htmlspecialchars($transaction['from_location']); ?><br>
                                                By: <?php echo htmlspecialchars($transaction['performed_by']); ?>
                                            </small>
                                        </div>
                                    <?php endwhile; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted mb-0 text-center">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    No recent issues
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

    let currentItem = null;
    let currentLocation = null;
    let availableBatches = [];
    let locations = <?php echo json_encode($locations); ?>;

    // Load item details when item is selected
    $('#item_id').on('change', function() {
        const itemId = $(this).val();
        const selectedOption = $(this).find('option:selected');
        
        if (itemId) {
            // Get basic details from data attributes
            const currentStock = parseFloat(selectedOption.data('stock')) || 0;
            const itemCode = selectedOption.data('code') || '';
            const itemName = selectedOption.data('name') || '';
            const unitMeasure = selectedOption.data('unit') || 'units';
            const reorderLevel = parseFloat(selectedOption.data('reorder')) || 0;
            
            // Update item details card
            $('#detail_quantity').text(currentStock);
            $('#detail_reorder').text(reorderLevel);
            $('#detail_item_name').text(itemName);
            $('#detail_item_code').text(itemCode);
            $('#detail_unit').text(unitMeasure);
            $('#detail_status').text('Active').removeClass('badge-danger badge-warning').addClass('badge-success');
            
            $('#item_details_card').show();
            
            currentItem = {
                id: itemId,
                stock: currentStock,
                name: itemName,
                code: itemCode,
                unit: unitMeasure,
                reorderLevel: reorderLevel
            };
            
            // Load batches for selected location
            loadBatches();
            
        } else {
            $('#item_details_card').hide();
            $('#batches_card').hide();
            $('#batch_selection_container').hide();
            currentItem = null;
            currentLocation = null;
            availableBatches = [];
            updatePreview();
        }
    });

    // Load location details and batches
    $('#from_location_id').on('change', function() {
        const locationId = $(this).val();
        const selectedOption = $(this).find('option:selected');
        
        if (locationId) {
            currentLocation = {
                id: locationId,
                name: selectedOption.text()
            };
            
            // Load batches if item is selected
            if (currentItem) {
                loadBatches();
            }
        } else {
            currentLocation = null;
            $('#batches_card').hide();
            $('#batch_selection_container').hide();
        }
        
        updatePreview();
    });

    // Load available batches for item/location
    function loadBatches() {
        if (!currentItem || !currentLocation) {
            $('#batches_card').hide();
            $('#batch_selection_container').hide();
            return;
        }
        
        $('#batches_list').html('<p class="text-center text-muted"><i class="fas fa-spinner fa-spin"></i> Loading batches...</p>');
        $('#batches_card').show();
        $('#batch_selection_container').show();
        
        $.ajax({
            url: 'ajax/get_item_location_batches.php',
            type: 'GET',
            data: {
                item_id: currentItem.id,
                location_id: currentLocation.id
            },
            success: function(response) {
                if (response.success) {
                    availableBatches = response.batches;
                    
                    // Update batch selection dropdown
                    const $batchSelect = $('#batch_id');
                    $batchSelect.empty().append('<option value="0">- Auto-select (FIFO) -</option>');
                    
                    // Update batches list
                    let batchesHtml = '';
                    
                    response.batches.forEach(batch => {
                        const expiryDate = new Date(batch.expiry_date);
                        const today = new Date();
                        const daysDiff = Math.floor((expiryDate - today) / (1000 * 60 * 60 * 24));
                        
                        let expiryBadge = '';
                        if (daysDiff < 0) {
                            expiryBadge = '<span class="badge badge-danger ml-1">Expired</span>';
                        } else if (daysDiff <= 30) {
                            expiryBadge = `<span class="badge badge-warning ml-1">${daysDiff} days</span>`;
                        } else {
                            expiryBadge = `<span class="badge badge-success ml-1">${daysDiff} days</span>`;
                        }
                        
                        // Add to dropdown
                        $batchSelect.append(`<option value="${batch.batch_id}" data-quantity="${batch.available_quantity}">
                            ${batch.batch_number} (${batch.available_quantity} available, Exp: ${batch.expiry_date})
                        </option>`);
                        
                        // Add to batches list
                        batchesHtml += `
                            <div class="mb-2 p-2 border rounded">
                                <div class="font-weight-bold">${batch.batch_number}</div>
                                <div class="small text-muted">
                                    Expiry: ${batch.expiry_date} ${expiryBadge}<br>
                                    Available: ${batch.available_quantity} ${currentItem.unit}<br>
                                    ${batch.manufacturer ? 'Manufacturer: ' + batch.manufacturer : ''}
                                </div>
                            </div>
                        `;
                    });
                    
                    if (batchesHtml === '') {
                        batchesHtml = '<p class="text-muted mb-0 text-center">No batches available at this location</p>';
                    }
                    
                    $('#batches_list').html(batchesHtml);
                    $batchSelect.select2();
                    
                } else {
                    $('#batches_list').html(`<p class="text-danger text-center">${response.message}</p>`);
                    availableBatches = [];
                }
                
                updatePreview();
            },
            error: function() {
                $('#batches_list').html('<p class="text-danger text-center">Error loading batches</p>');
                availableBatches = [];
                updatePreview();
            }
        });
    }

    // Update preview based on form changes
    function updatePreview() {
        const quantity = parseFloat($('#quantity').val()) || 0;
        const batchId = $('#batch_id').val();
        const selectedBatch = batchId > 0 ? availableBatches.find(b => b.batch_id == batchId) : null;
        
        if (!currentItem) {
            // If no item selected, reset preview
            $('#preview_current').text('0');
            $('#preview_change').text('-0');
            $('#preview_new').text('0');
            $('#preview_location').text('No location selected');
            $('#batch_info').hide();
            $('#low_stock_warning').hide();
            $('#insufficient_stock_warning').hide();
            $('#batch_warning').hide();
            $('#transaction_details').hide();
            return;
        }
        
        const currentStock = currentItem.stock;
        const reorderLevel = currentItem.reorderLevel;
        const unitMeasure = currentItem.unit;
        
        // Calculate available stock at location
        let availableAtLocation = 0;
        if (availableBatches.length > 0) {
            availableAtLocation = availableBatches.reduce((sum, batch) => sum + parseFloat(batch.available_quantity), 0);
        }
        
        const displayChange = '-' + quantity;
        
        // Update preview numbers
        $('#preview_current').text(currentStock);
        $('#preview_change').text(displayChange);
        $('#preview_new').text(currentStock - quantity);
        
        // Update location info
        if (currentLocation) {
            $('#preview_location').html(`<strong>${currentLocation.name}</strong> (${availableAtLocation} ${unitMeasure} available)`);
            $('#location_info').show();
        } else {
            $('#location_info').hide();
        }
        
        // Update batch info
        if (selectedBatch) {
            $('#preview_batch').html(`<strong>${selectedBatch.batch_number}</strong> (${selectedBatch.available_quantity} available)`);
            $('#batch_info').show();
        } else if (availableBatches.length > 0) {
            $('#preview_batch').html(`<strong>Auto-select (FIFO)</strong> from ${availableBatches.length} batch(es)`);
            $('#batch_info').show();
        } else {
            $('#batch_info').hide();
        }
        
        // Show/hide warnings
        $('#low_stock_warning').toggle(currentStock - quantity > 0 && currentStock - quantity <= reorderLevel);
        
        if (currentLocation && availableAtLocation < quantity) {
            $('#insufficient_stock_warning').show().find('strong').text(`Insufficient Stock: Only ${availableAtLocation} ${unitMeasure} available at selected location.`);
        } else {
            $('#insufficient_stock_warning').hide();
        }
        
        // Batch-specific warnings
        if (selectedBatch && selectedBatch.available_quantity < quantity) {
            $('#batch_warning').show().find('#batch_warning_text').text(`Only ${selectedBatch.available_quantity} available in selected batch.`);
        } else {
            $('#batch_warning').hide();
        }
        
        // Show transaction details
        $('#transaction_details').show().find('#transaction_details_text').text(
            `Issue ${quantity} ${unitMeasure} will be recorded in transaction history.`
        );
        
        // Update quantity help text
        let helpText = `Enter quantity to issue (max: ${availableAtLocation} ${unitMeasure})`;
        $('#quantity_help').text(helpText);
        
        // Set max value
        $('#quantity').attr('max', availableAtLocation);
    }
    
    // Event listeners
    $('#item_id, #quantity, #from_location_id, #batch_id').on('change input', updatePreview);
    
    // Auto-generate reference
    if (!$('#reference').val()) {
        const timestamp = new Date().toISOString().replace(/[-:]/g, '').split('.')[0];
        $('#reference').val('ISSUE-' + timestamp);
    }
    
    // Select requisition item
    window.selectRequisitionItem = function(itemId, maxQuantity) {
        $('#item_id').val(itemId).trigger('change');
        $('#quantity').val(Math.min(1, maxQuantity));
        $('html, body').animate({
            scrollTop: $('#transactionForm').offset().top - 100
        }, 500);
    };
    
    // Initial setup
    <?php if ($item): ?>
        $('#item_id').trigger('change');
        <?php if ($location_id): ?>
            $('#from_location_id').val(<?php echo $location_id; ?>).trigger('change');
        <?php endif; ?>
    <?php endif; ?>
    
    // Form validation
    $('#transactionForm').on('submit', function(e) {
        const itemId = $('#item_id').val();
        const quantity = parseFloat($('#quantity').val()) || 0;
        const fromLocationId = $('#from_location_id').val();
        const batchId = $('#batch_id').val();
        
        let isValid = true;
        let errorMessage = '';
        
        // Validate required fields
        if (!itemId || quantity <= 0 || !fromLocationId) {
            isValid = false;
            errorMessage = 'Please fill in all required fields with valid values.';
        }
        
        // Validate quantity
        if (quantity <= 0) {
            isValid = false;
            errorMessage = 'Quantity must be greater than zero.';
        }
        
        // Validate location has stock
        if (availableBatches.length === 0) {
            isValid = false;
            errorMessage = 'No stock available at selected location.';
        }
        
        // Validate specific batch if selected
        if (batchId > 0) {
            const selectedBatch = availableBatches.find(b => b.batch_id == batchId);
            if (!selectedBatch) {
                isValid = false;
                errorMessage = 'Selected batch is not available.';
            } else if (selectedBatch.available_quantity < quantity) {
                isValid = false;
                errorMessage = `Insufficient quantity in selected batch. Available: ${selectedBatch.available_quantity}`;
            }
        }
        
        // Validate total available stock
        const totalAvailable = availableBatches.reduce((sum, batch) => sum + parseFloat(batch.available_quantity), 0);
        if (totalAvailable < quantity) {
            isValid = false;
            errorMessage = `Insufficient total stock. Available: ${totalAvailable}`;
        }
        
        if (!isValid) {
            e.preventDefault();
            alert(errorMessage);
            return false;
        }
        
        // Show loading state
        $('#submitBtn').html('<i class="fas fa-spinner fa-spin mr-2"></i>Issuing...').prop('disabled', true);
        
        // Show confirmation message
        const batchText = batchId > 0 ? ' from selected batch' : ' using FIFO method';
        if (!confirm(`Are you sure you want to issue ${quantity} ${currentItem?.unit || 'units'}${batchText}?\n\nThis will reduce inventory at ${currentLocation?.name || 'selected location'}.`)) {
            e.preventDefault();
            $('#submitBtn').html('<i class="fas fa-arrow-up mr-2"></i>Issue Items').prop('disabled', false);
            return false;
        }
    });
});

// Quick quantity functions
function setQuantity(quantity) {
    $('#quantity').val(quantity).trigger('input');
    $('#quantity').focus();
}

function resetForm() {
    if (confirm('Are you sure you want to reset all changes?')) {
        $('#item_id').val('').trigger('change');
        $('#quantity').val(1).trigger('input');
        $('#reference').val('');
        $('#notes').val('');
        $('#related_visit_id').val('');
        $('#from_location_id').val('');
        $('#batch_id').val('0').trigger('change');
        
        // Reset auto-generated reference
        const timestamp = new Date().toISOString().replace(/[-:]/g, '').split('.')[0];
        $('#reference').val('ISSUE-' + timestamp);
    }
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + S to save
    if (e.ctrlKey && e.keyCode === 83) {
        e.preventDefault();
        $('#transactionForm').submit();
    }
    // Escape to cancel
    if (e.keyCode === 27) {
        window.location.href = 'inventory.php';
    }
    // Ctrl + R to reset
    if (e.ctrlKey && e.keyCode === 82) {
        e.preventDefault();
        resetForm();
    }
});

// Input validation
$('#quantity').on('input', function() {
    const max = parseFloat($(this).attr('max')) || 0;
    const value = parseFloat($(this).val()) || 0;
    
    if (max && value > max) {
        $(this).val(max);
        updatePreview();
    }
});
</script>

<style>
.loading {
    opacity: 0.7;
    pointer-events: none;
}

.badge-lg {
    font-size: 0.9rem;
    padding: 0.5em 0.8em;
}

.select2-container .select2-selection--single {
    height: 38px;
}

.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 36px;
}

.card-header.bg-danger {
    background-color: #dc3545 !important;
}

.btn-danger {
    background-color: #dc3545;
    border-color: #dc3545;
}

.btn-danger:hover {
    background-color: #c82333;
    border-color: #bd2130;
}

#transaction_details {
    border-left: 4px solid #28a745;
}

#insufficient_stock_warning {
    border-left: 4px solid #dc3545;
}

#low_stock_warning {
    border-left: 4px solid #ffc107;
}

#batch_warning {
    border-left: 4px solid #17a2b8;
}
</style>

<?php 
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
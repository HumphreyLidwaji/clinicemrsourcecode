<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Get item ID from URL (optional)
$item_id = intval($_GET['item_id'] ?? 0);
$transaction_type = 'in'; // Fixed to only allow in transactions
$purchase_order_id = intval($_GET['purchase_order_id'] ?? 0);
$supplier_id = intval($_GET['supplier_id'] ?? 0);

// Initialize variables
$items = [];
$item = null;
$supplier = null;
$purchase_order = null;
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

// Get all active items for dropdown with full details
$items_sql = "SELECT item_id, item_name, item_code, item_quantity, item_low_stock_alert, location_id,
                     item_category_id, item_supplier_id, item_unit_measure, item_status
              FROM inventory_items 
              WHERE item_status != 'Discontinued' 
              ORDER BY item_name";
$items_result = $mysqli->query($items_sql);
while ($item_row = $items_result->fetch_assoc()) {
    $items[] = $item_row;
}

// Get all active suppliers for dropdown
$suppliers_sql = "SELECT supplier_id, supplier_name
                  FROM suppliers 
                 ";
$suppliers_result = $mysqli->query($suppliers_sql);
$suppliers = [];
while ($supplier_row = $suppliers_result->fetch_assoc()) {
    $suppliers[] = $supplier_row;
}

// If specific item is requested, get its details
if ($item_id > 0) {
    $item_sql = "SELECT i.*, 
                        c.category_name, c.category_type,
                        s.supplier_name,
                        l.location_name,
                        l.location_type
                 FROM inventory_items i
                 LEFT JOIN inventory_categories c ON i.item_category_id = c.category_id
                 LEFT JOIN suppliers s ON i.item_supplier_id = s.supplier_id
                 LEFT JOIN inventory_locations l ON i.location_id = l.location_id
                 WHERE i.item_id = ? AND i.item_status != 'Discontinued'";
    $item_stmt = $mysqli->prepare($item_sql);
    $item_stmt->bind_param("i", $item_id);
    $item_stmt->execute();
    $item_result = $item_stmt->get_result();
    
    if ($item_result->num_rows > 0) {
        $item = $item_result->fetch_assoc();
    }
    $item_stmt->close();
}

// If supplier ID is provided, get supplier details
if ($supplier_id > 0) {
    $supplier_sql = "SELECT * FROM suppliers WHERE supplier_id = ?";
    $supplier_stmt = $mysqli->prepare($supplier_sql);
    $supplier_stmt->bind_param("i", $supplier_id);
    $supplier_stmt->execute();
    $supplier_result = $supplier_stmt->get_result();
    
    if ($supplier_result->num_rows > 0) {
        $supplier = $supplier_result->fetch_assoc();
    }
    $supplier_stmt->close();
}

// If purchase order ID is provided, get purchase order details
if ($purchase_order_id > 0) {
    $po_sql = "SELECT po.*, s.supplier_name, s.supplier_contact_name, s.supplier_email,
                      u.user_name as created_by_name, l.location_name
               FROM purchase_orders po
               LEFT JOIN suppliers s ON po.supplier_id = s.supplier_id
               LEFT JOIN users u ON po.created_by = u.user_id
               LEFT JOIN inventory_locations l ON po.destination_location_id = l.location_id
               WHERE po.purchase_order_id = ?";
    $po_stmt = $mysqli->prepare($po_sql);
    $po_stmt->bind_param("i", $purchase_order_id);
    $po_stmt->execute();
    $po_result = $po_stmt->get_result();
    
    if ($po_result->num_rows > 0) {
        $purchase_order = $po_result->fetch_assoc();
        
        // Get purchase order items
        $po_items_sql = "SELECT poi.*, i.item_name, i.item_code, i.item_quantity as current_stock,
                                i.item_low_stock_alert, i.item_unit_measure
                         FROM purchase_order_items poi
                         JOIN inventory_items i ON poi.item_id = i.item_id
                         WHERE poi.purchase_order_id = ?";
        $po_items_stmt = $mysqli->prepare($po_items_sql);
        $po_items_stmt->bind_param("i", $purchase_order_id);
        $po_items_stmt->execute();
        $po_items_result = $po_items_stmt->get_result();
        $purchase_order_items = [];
        
        while ($po_item = $po_items_result->fetch_assoc()) {
            $purchase_order_items[] = $po_item;
        }
        $po_items_stmt->close();
    }
    $po_stmt->close();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    $item_id = intval($_POST['item_id']);
    $transaction_type = 'in'; // Force in transaction type
    $quantity = intval($_POST['quantity']);
    $unit_cost = floatval($_POST['unit_cost'] ?? 0);
    $reference = sanitizeInput($_POST['reference']);
    $notes = sanitizeInput($_POST['notes']);
    $supplier_id = intval($_POST['supplier_id'] ?? 0);
    $to_location_id = intval($_POST['to_location_id'] ?? 0);
    $purchase_order_id = intval($_POST['purchase_order_id'] ?? 0);
    $from_location_id = 0; // Always 0 for in transactions (receiving from external)
    $expiry_date = !empty($_POST['expiry_date']) ? sanitizeInput($_POST['expiry_date']) : null;
    $batch_number = sanitizeInput($_POST['batch_number'] ?? '');
    $related_visit_id = intval($_POST['related_visit_id'] ?? 0);

    // Validate CSRF token
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        header("Location: inventory_receive.php" . ($item_id ? "?item_id=" . $item_id : ""));
        exit;
    }

    // Validate required fields
    if (empty($item_id) || $quantity <= 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please fill in all required fields with valid values.";
        header("Location: inventory_receive.php" . ($item_id ? "?item_id=" . $item_id : ""));
        exit;
    }

    // Validate location is selected
    if (!$to_location_id) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please select a destination location.";
        header("Location: inventory_receive.php" . ($item_id ? "?item_id=" . $item_id : ""));
        exit;
    }

    // Validate unit cost if provided
    if ($unit_cost < 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Unit cost cannot be negative.";
        header("Location: inventory_receive.php" . ($item_id ? "?item_id=" . $item_id : ""));
        exit;
    }

    // Start transaction
    $mysqli->begin_transaction();

    try {
        // Get current item details with location information
        $current_sql = "SELECT i.*, 
                               l.location_name as current_location_name,
                               ili.quantity as location_quantity
                        FROM inventory_items i
                        LEFT JOIN inventory_locations l ON i.location_id = l.location_id
                        LEFT JOIN inventory_location_items ili ON (i.item_id = ili.item_id AND i.location_id = ili.location_id)
                        WHERE i.item_id = ?";
        $current_stmt = $mysqli->prepare($current_sql);
        $current_stmt->bind_param("i", $item_id);
        $current_stmt->execute();
        $current_result = $current_stmt->get_result();
        
        if ($current_result->num_rows === 0) {
            throw new Exception("Item not found.");
        }
        
        $current_item = $current_result->fetch_assoc();
        $current_quantity = $current_item['item_quantity'];
        $current_location_id = $current_item['location_id'];
        $low_stock_alert = $current_item['item_low_stock_alert'];
        $current_status = $current_item['item_status'];
        $current_stmt->close();

        // Calculate new quantities
        $new_quantity = $current_quantity + $quantity;
        $quantity_change = $quantity;
        $effective_location_id = $to_location_id ?: $current_location_id;

        // Determine new status based on new quantity and low stock alert
        $new_status = 'In Stock';
        if ($new_quantity <= 0) {
            $new_status = 'Out of Stock';
        } elseif ($new_quantity <= $low_stock_alert) {
            $new_status = 'Low Stock';
        }

        // CRITICAL: Update main inventory item quantity and status
        $update_sql = "UPDATE inventory_items SET 
                      item_quantity = ?, 
                      item_status = ?,
                      location_id = ?,
                      item_updated_date = NOW(),
                      last_restocked_date = NOW()";
        
        // Add supplier if provided
        if ($supplier_id > 0) {
            $update_sql .= ", item_supplier_id = ?";
        }
        
        $update_sql .= " WHERE item_id = ?";
        
        $update_stmt = $mysqli->prepare($update_sql);
        
        if ($supplier_id > 0) {
            $update_stmt->bind_param("issii", $new_quantity, $new_status, $effective_location_id, $supplier_id, $item_id);
        } else {
            $update_stmt->bind_param("issi", $new_quantity, $new_status, $effective_location_id, $item_id);
        }
        
        if (!$update_stmt->execute()) {
            throw new Exception("Failed to update inventory item: " . $update_stmt->error);
        }
        $update_stmt->close();

        // Update inventory_location_items for in transactions
        if ($to_location_id) {
            $location_sql = "INSERT INTO inventory_location_items (item_id, location_id, quantity, low_stock_alert) 
                            VALUES (?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE 
                            quantity = quantity + VALUES(quantity),
                            updated_at = NOW()";
            $location_stmt = $mysqli->prepare($location_sql);
            $location_stmt->bind_param("iiii", $item_id, $to_location_id, $quantity, $low_stock_alert);
            
            if (!$location_stmt->execute()) {
                throw new Exception("Failed to update location inventory: " . $location_stmt->error);
            }
            $location_stmt->close();
        }

        // Generate reference if not provided
        if (empty($reference)) {
            $reference = 'RECV-' . date('Ymd-His');
        }

        // Calculate total cost
        $total_cost = $unit_cost * $quantity;
        $requisition_id = 0; // Not used for receiving

        // Record transaction with proper quantity tracking
        $transaction_sql = "INSERT INTO inventory_transactions (
            item_id, transaction_type, quantity_change, previous_quantity, new_quantity,
            transaction_reference, transaction_notes, performed_by, related_visit_id,
            from_location_id, to_location_id, requisition_id, purchase_order_id, supplier_id,
            unit_cost, total_cost, batch_number, expiry_date, transaction_date
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $trans_stmt = $mysqli->prepare($transaction_sql);
        $trans_stmt->bind_param(
            "isiiissiiiiiiiidss",
            $item_id, $transaction_type, $quantity_change, $current_quantity, $new_quantity,
            $reference, $notes, $session_user_id, $related_visit_id,
            $from_location_id, $to_location_id, $requisition_id, $purchase_order_id, $supplier_id,
            $unit_cost, $total_cost, $batch_number, $expiry_date
        );
        
        if (!$trans_stmt->execute()) {
            throw new Exception("Failed to record transaction: " . $trans_stmt->error);
        }
        $transaction_id = $trans_stmt->insert_id;
        $trans_stmt->close();

        // Update purchase order if this is a receipt
        if ($purchase_order_id > 0) {
            // Update the received quantity in purchase order items
            $update_po_sql = "UPDATE purchase_order_items 
                              SET quantity_received = quantity_received + ?,
                                  unit_cost = COALESCE(?, unit_cost),
                                  total_cost = COALESCE(?, total_cost)
                              WHERE purchase_order_id = ? AND item_id = ?";
            $update_po_stmt = $mysqli->prepare($update_po_sql);
            $po_total_cost = $unit_cost * $quantity;
            $update_po_stmt->bind_param("iddii", $quantity, $unit_cost, $po_total_cost, $purchase_order_id, $item_id);
            
            if (!$update_po_stmt->execute()) {
                throw new Exception("Failed to update purchase order items: " . $update_po_stmt->error);
            }
            $update_po_stmt->close();
            
            // Check if purchase order is fully received
            $check_po_sql = "SELECT 
                SUM(quantity_ordered) as total_ordered,
                SUM(quantity_received) as total_received
                FROM purchase_order_items 
                WHERE purchase_order_id = ?";
            $check_po_stmt = $mysqli->prepare($check_po_sql);
            $check_po_stmt->bind_param("i", $purchase_order_id);
            $check_po_stmt->execute();
            $po_status = $check_po_stmt->get_result()->fetch_assoc();
            $check_po_stmt->close();
            
            // Update purchase order status
            $new_po_status = 'partially_received';
            if ($po_status['total_received'] >= $po_status['total_ordered']) {
                $new_po_status = 'received';
            } elseif ($po_status['total_received'] > 0) {
                $new_po_status = 'partially_received';
            }
            
            $update_po_status_sql = "UPDATE purchase_orders SET status = ? WHERE purchase_order_id = ?";
            $update_po_status_stmt = $mysqli->prepare($update_po_status_sql);
            $update_po_status_stmt->bind_param("si", $new_po_status, $purchase_order_id);
            
            if (!$update_po_status_stmt->execute()) {
                throw new Exception("Failed to update purchase order status: " . $update_po_status_stmt->error);
            }
            $update_po_status_stmt->close();
        }

        // Log the action
        $log_sql = "INSERT INTO logs SET
                  log_type = 'Inventory',
                  log_action = 'Receive',
                  log_description = ?,
                  log_ip = ?,
                  log_user_agent = ?,
                  log_user_id = ?,
                  log_entity_id = ?,
                  log_created_at = NOW()";
        $log_stmt = $mysqli->prepare($log_sql);
        $log_description = "Received item #" . $item_id . 
                          " (Qty: " . $quantity . ", Ref: " . $reference . 
                          ", Stock: " . $current_quantity . " → " . $new_quantity . 
                          ", Status: " . $current_status . " → " . $new_status . ")";
        $log_stmt->bind_param("sssii", $log_description, $session_ip, $session_user_agent, $session_user_id, $transaction_id);
        $log_stmt->execute();
        $log_stmt->close();

        $mysqli->commit();

        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Item received successfully! Stock updated from " . 
            $current_quantity . " to " . $new_quantity . ". Status: " . $current_status . " → " . $new_status;
        
        if ($purchase_order_id > 0) {
            header("Location: purchase_order_details.php?purchase_order_id=" . $purchase_order_id);
        } else {
            header("Location: inventory_item_details.php?item_id=" . $item_id);
        }
        exit;

    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error receiving item: " . $e->getMessage();
        header("Location: inventory_receive.php" . ($item_id ? "?item_id=" . $item_id : ""));
        exit;
    }
}
?>

<div class="card">
    <div class="card-header bg-success py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0 text-white">
                <i class="fas fa-fw fa-arrow-down mr-2"></i>Receive Inventory Items
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

        <!-- Purchase Order Information (if applicable) -->
        <?php if ($purchase_order): ?>
        <div class="card card-info mb-4">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-file-invoice-dollar mr-2"></i>Purchase Order Information</h3>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3">
                        <strong>PO #:</strong><br>
                        <?php echo htmlspecialchars($purchase_order['purchase_order_number']); ?>
                    </div>
                    <div class="col-md-3">
                        <strong>Supplier:</strong><br>
                        <?php echo htmlspecialchars($purchase_order['supplier_name']); ?>
                    </div>
                    <div class="col-md-3">
                        <strong>Status:</strong><br>
                        <span class="badge badge-<?php 
                            switch($purchase_order['status']) {
                                case 'pending': echo 'warning'; break;
                                case 'approved': echo 'success'; break;
                                case 'received': echo 'info'; break;
                                case 'partially_received': echo 'primary'; break;
                                default: echo 'secondary';
                            }
                        ?>">
                            <?php echo ucfirst(str_replace('_', ' ', $purchase_order['status'])); ?>
                        </span>
                    </div>
                    <div class="col-md-3">
                        <strong>Destination:</strong><br>
                        <?php echo htmlspecialchars($purchase_order['location_name']); ?>
                    </div>
                </div>
                <?php if (!empty($purchase_order['notes'])): ?>
                <div class="row mt-2">
                    <div class="col-12">
                        <strong>Notes:</strong><br>
                        <?php echo nl2br(htmlspecialchars($purchase_order['notes'])); ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Supplier Information (if applicable) -->
        <?php if ($supplier): ?>
        <div class="card card-warning mb-4">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-truck mr-2"></i>Supplier Information</h3>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <strong>Supplier:</strong><br>
                        <?php echo htmlspecialchars($supplier['supplier_name']); ?>
                    </div>
                    <div class="col-md-4">
                        <strong>Contact:</strong><br>
                        <?php echo htmlspecialchars($supplier['supplier_contact_name']); ?>
                    </div>
                    <div class="col-md-4">
                        <strong>Email:</strong><br>
                        <?php echo htmlspecialchars($supplier['supplier_email']); ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <form method="POST" id="receiveForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="purchase_order_id" value="<?php echo $purchase_order_id; ?>">
            <input type="hidden" name="transaction_type" value="in">
            
            <div class="row">
                <div class="col-md-8">
                    <!-- Receive Information -->
                    <div class="card card-success">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-arrow-down mr-2"></i>Receive Item Details</h3>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="item_id">Item to Receive *</label>
                                        <select class="form-control select2" id="item_id" name="item_id" required>
                                            <option value="">- Select Item to Receive -</option>
                                            <?php foreach ($items as $item_row): ?>
                                                <?php 
                                                $selected = ($item && $item['item_id'] == $item_row['item_id']) ? 'selected' : '';
                                                $display_text = htmlspecialchars($item_row['item_name'] . ' (' . $item_row['item_code'] . ') - Stock: ' . $item_row['item_quantity']);
                                                ?>
                                                <option value="<?php echo $item_row['item_id']; ?>" 
                                                        data-quantity="<?php echo $item_row['item_quantity']; ?>"
                                                        data-low-stock="<?php echo $item_row['item_low_stock_alert']; ?>"
                                                        data-location="<?php echo $item_row['location_id']; ?>"
                                                        data-unit="<?php echo $item_row['item_unit_measure']; ?>"
                                                        data-status="<?php echo $item_row['item_status']; ?>"
                                                        data-category="<?php echo $item_row['item_category_id']; ?>"
                                                        data-supplier="<?php echo $item_row['item_supplier_id']; ?>"
                                                        <?php echo $selected; ?>>
                                                    <?php echo $display_text; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="supplier_id">Supplier (Optional)</label>
                                        <select class="form-control select2" id="supplier_id" name="supplier_id">
                                            <option value="">- Select Supplier -</option>
                                            <?php foreach ($suppliers as $supplier_row): ?>
                                                <option value="<?php echo $supplier_row['supplier_id']; ?>" 
                                                        <?php echo ($supplier && $supplier['supplier_id'] == $supplier_row['supplier_id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($supplier_row['supplier_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="form-text text-muted">Who supplied this item</small>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="quantity">Quantity to Receive *</label>
                                        <input type="number" class="form-control" id="quantity" name="quantity" 
                                               min="1" value="1" required>
                                        <small class="form-text text-muted" id="quantity_help">
                                            Enter the quantity received
                                        </small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="unit_cost">Unit Cost ($)</label>
                                        <div class="input-group">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text">$</span>
                                            </div>
                                            <input type="number" class="form-control" id="unit_cost" name="unit_cost" 
                                                   min="0" step="0.01" placeholder="0.00">
                                        </div>
                                        <small class="form-text text-muted">Cost per unit (optional)</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="to_location_id">Destination Location *</label>
                                        <select class="form-control select2" id="to_location_id" name="to_location_id" required>
                                            <option value="">- Select Destination Location -</option>
                                            <?php foreach ($locations as $location): ?>
                                                <option value="<?php echo $location['location_id']; ?>" 
                                                        <?php echo ($purchase_order && $purchase_order['destination_location_id'] == $location['location_id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($location['location_type'] . ' - ' . $location['location_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="form-text text-muted">Where to store the received items</small>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="batch_number">Batch/Lot Number (Optional)</label>
                                        <input type="text" class="form-control" id="batch_number" name="batch_number" 
                                               placeholder="Enter batch or lot number" maxlength="100">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="expiry_date">Expiry Date (Optional)</label>
                                        <input type="date" class="form-control" id="expiry_date" name="expiry_date">
                                        <small class="form-text text-muted">For perishable items</small>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="reference">Receive Reference Number</label>
                                        <input type="text" class="form-control" id="reference" name="reference" 
                                               placeholder="Auto-generated if left blank" maxlength="100">
                                        <small class="form-text text-muted">GRN number, delivery note, etc.</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="related_visit_id">Related Visit ID (Optional)</label>
                                        <input type="number" class="form-control" id="related_visit_id" name="related_visit_id" 
                                               placeholder="Enter related visit ID" min="1">
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="notes">Receive Notes</label>
                                <textarea class="form-control" id="notes" name="notes" rows="3" 
                                          placeholder="Delivery details, quality notes, etc..." 
                                          maxlength="500"></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Purchase Order Items (if applicable) -->
                    <?php if ($purchase_order && !empty($purchase_order_items)): ?>
                    <div class="card card-warning">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-list mr-2"></i>Purchase Order Items</h3>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-sm table-striped">
                                    <thead>
                                        <tr>
                                            <th>Item</th>
                                            <th class="text-center">Ordered</th>
                                            <th class="text-center">Received</th>
                                            <th class="text-center">Current Stock</th>
                                            <th class="text-center">Unit Cost</th>
                                            <th>Status</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($purchase_order_items as $po_item): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($po_item['item_name']); ?></strong><br>
                                                    <small class="text-muted"><?php echo htmlspecialchars($po_item['item_code']); ?></small>
                                                </td>
                                                <td class="text-center"><?php echo $po_item['quantity_ordered']; ?></td>
                                                <td class="text-center">
                                                    <span class="font-weight-bold text-info"><?php echo $po_item['quantity_received']; ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge badge-<?php 
                                                        echo $po_item['current_stock'] == 0 ? 'danger' : 
                                                            ($po_item['current_stock'] <= $po_item['item_low_stock_alert'] ? 'warning' : 'success');
                                                    ?>">
                                                        <?php echo $po_item['current_stock']; ?> <?php echo $po_item['item_unit_measure']; ?>
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    $<?php echo number_format($po_item['unit_cost'], 2); ?>
                                                </td>
                                                <td class="text-center">
                                                    <?php 
                                                    $status_color = $po_item['quantity_received'] >= $po_item['quantity_ordered'] ? 'success' : 
                                                                   ($po_item['quantity_received'] > 0 ? 'warning' : 'secondary');
                                                    $status_text = $po_item['quantity_received'] >= $po_item['quantity_ordered'] ? 'Fully Received' : 
                                                                  ($po_item['quantity_received'] > 0 ? 'Partial' : 'Pending');
                                                    ?>
                                                    <span class="badge badge-<?php echo $status_color; ?>">
                                                        <?php echo $status_text; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($po_item['quantity_ordered'] > $po_item['quantity_received']): ?>
                                                        <button type="button" class="btn btn-sm btn-success" 
                                                                onclick="selectPOItem(<?php echo $po_item['item_id']; ?>, <?php echo $po_item['quantity_ordered'] - $po_item['quantity_received']; ?>, <?php echo $po_item['unit_cost']; ?>)">
                                                            <i class="fas fa-arrow-down mr-1"></i>Receive
                                                        </button>
                                                    <?php else: ?>
                                                        <span class="badge badge-success">Fully Received</span>
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

                    <!-- Receive Preview -->
                    <div class="card card-info">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-calculator mr-2"></i>Receive Preview</h3>
                        </div>
                        <div class="card-body">
                            <div class="row text-center">
                                <div class="col-md-4">
                                    <div class="border rounded p-3 bg-light">
                                        <div class="h5 text-muted">Current Stock</div>
                                        <div class="h2 font-weight-bold text-primary" id="preview_current">
                                            <?php echo $item ? $item['item_quantity'] : '0'; ?>
                                        </div>
                                        <small class="text-muted" id="preview_status_text">Status</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="border rounded p-3 bg-light">
                                        <div class="h5 text-muted">Receiving</div>
                                        <div class="h2 font-weight-bold text-success" id="preview_change">
                                            +0
                                        </div>
                                        <div class="small text-muted">Quantity to Receive</div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="border rounded p-3 bg-light">
                                        <div class="h5 text-muted">New Stock</div>
                                        <div class="h2 font-weight-bold text-warning" id="preview_new">
                                            <?php echo $item ? $item['item_quantity'] : '0'; ?>
                                        </div>
                                        <small class="text-muted" id="preview_new_status_text">New Status</small>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row mt-3 text-center">
                                <div class="col-md-6">
                                    <div id="preview_status" class="badge badge-lg p-2">
                                        Current Status: <span class="badge" id="preview_current_status">-</span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div id="preview_new_status" class="badge badge-lg p-2">
                                        After Receiving: <span class="badge" id="preview_status_badge">-</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Cost Preview -->
                            <div class="row mt-3">
                                <div class="col-md-6">
                                    <div class="alert alert-secondary">
                                        <i class="fas fa-money-bill-wave mr-2"></i>
                                        <strong>Cost Estimate:</strong> 
                                        <span id="cost_preview">$0.00</span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="alert alert-info d-none" id="location_preview">
                                        <i class="fas fa-map-marker-alt mr-2"></i>
                                        <strong>Receiving To:</strong> 
                                        <span id="preview_location_change">-</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Warnings and Confirmations -->
                            <div class="mt-2 alert alert-success d-none" id="good_stock_warning">
                                <i class="fas fa-check-circle mr-2"></i>
                                <strong>Good Stock Level:</strong> This receipt will bring stock to a healthy level.
                            </div>

                            <div class="mt-2 alert alert-warning d-none" id="restock_warning">
                                <i class="fas fa-exclamation-triangle mr-2"></i>
                                <strong>Restocking:</strong> This receipt will replenish low stock items.
                            </div>

                            <div class="mt-2 alert alert-success d-none" id="inventory_update_confirmation">
                                <i class="fas fa-database mr-2"></i>
                                <strong>Inventory Update:</strong> 
                                <span id="inventory_update_text">Main inventory quantity will be updated.</span>
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
                                    <i class="fas fa-check mr-2"></i>Receive Items
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
                    <div class="card card-info" id="item_details_card" style="<?php echo !$item ? 'display: none;' : ''; ?>">
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
                                    <span>Low Stock Alert:</span>
                                    <span class="font-weight-bold" id="detail_low_stock">0</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Current Status:</span>
                                    <span class="font-weight-bold" id="detail_status">-</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Current Location:</span>
                                    <span class="font-weight-bold" id="detail_location">-</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Unit Measure:</span>
                                    <span class="font-weight-bold" id="detail_unit_measure">-</span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Category:</span>
                                    <span class="font-weight-bold" id="detail_category">-</span>
                                </div>
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
                                <button type="button" class="btn btn-outline-success btn-sm" onclick="setQuantity(1)">
                                    <i class="fas fa-cube mr-2"></i>1 Unit
                                </button>
                                <button type="button" class="btn btn-outline-success btn-sm" onclick="setQuantity(5)">
                                    <i class="fas fa-cubes mr-2"></i>5 Units
                                </button>
                                <button type="button" class="btn btn-outline-success btn-sm" onclick="setQuantity(10)">
                                    <i class="fas fa-box mr-2"></i>10 Units
                                </button>
                                <button type="button" class="btn btn-outline-success btn-sm" onclick="setQuantity(25)">
                                    <i class="fas fa-boxes mr-2"></i>25 Units
                                </button>
                                <button type="button" class="btn btn-outline-success btn-sm" onclick="setQuantity(100)">
                                    <i class="fas fa-pallet mr-2"></i>100 Units
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Receipts -->
                    <div class="card card-warning">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-history mr-2"></i>Recent Receipts</h3>
                        </div>
                        <div class="card-body">
                            <?php
                            $recent_sql = "SELECT t.transaction_type, t.quantity_change, t.transaction_date, 
                                                  t.transaction_reference, i.item_name, i.item_code,
                                                  u.user_name as performed_by, l.location_name,
                                                  s.supplier_name, t.unit_cost, t.previous_quantity, t.new_quantity
                                           FROM inventory_transactions t
                                           JOIN inventory_items i ON t.item_id = i.item_id
                                           LEFT JOIN users u ON t.performed_by = u.user_id
                                           LEFT JOIN inventory_locations l ON t.to_location_id = l.location_id
                                           LEFT JOIN suppliers s ON t.supplier_id = s.supplier_id
                                           WHERE t.transaction_type = 'in'
                                           ORDER BY t.transaction_date DESC 
                                           LIMIT 5";
                            $recent_result = $mysqli->query($recent_sql);

                            if ($recent_result->num_rows > 0): ?>
                                <div class="list-group list-group-flush">
                                    <?php while ($transaction = $recent_result->fetch_assoc()): ?>
                                        <div class="list-group-item px-0 py-2">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1">
                                                    <span class="badge badge-success">
                                                        <i class="fas fa-arrow-down mr-1"></i>
                                                        +<?php echo abs($transaction['quantity_change']); ?>
                                                    </span>
                                                </h6>
                                                <small><?php echo timeAgo($transaction['transaction_date']); ?></small>
                                            </div>
                                            <p class="mb-1 small">
                                                <?php echo htmlspecialchars($transaction['item_name']); ?>
                                            </p>
                                            <small class="text-muted">
                                                Ref: <?php echo htmlspecialchars($transaction['transaction_reference']); ?><br>
                                                Stock: <?php echo $transaction['previous_quantity']; ?> → <?php echo $transaction['new_quantity']; ?><br>
                                                To: <?php echo htmlspecialchars($transaction['location_name']); ?><br>
                                                <?php if ($transaction['supplier_name']): ?>
                                                    Supplier: <?php echo htmlspecialchars($transaction['supplier_name']); ?><br>
                                                <?php endif; ?>
                                                <?php if ($transaction['unit_cost'] > 0): ?>
                                                    Cost: $<?php echo number_format($transaction['unit_cost'], 2); ?><br>
                                                <?php endif; ?>
                                                By: <?php echo htmlspecialchars($transaction['performed_by']); ?>
                                            </small>
                                        </div>
                                    <?php endwhile; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted mb-0 text-center">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    No recent receipts
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
    let locations = <?php echo json_encode($locations); ?>;

    // Load item details when item is selected
    $('#item_id').on('change', function() {
        const itemId = $(this).val();
        const selectedOption = $(this).find('option:selected');
        
        if (itemId) {
            // Show loading
            $('#item_details_card').show().addClass('loading');
            
            // Get basic details from data attributes
            const currentQuantity = parseInt(selectedOption.data('quantity')) || 0;
            const lowStockAlert = parseInt(selectedOption.data('low-stock')) || 0;
            const locationId = parseInt(selectedOption.data('location')) || 0;
            const unitMeasure = selectedOption.data('unit') || 'units';
            const currentStatus = selectedOption.data('status') || 'Unknown';
            const categoryId = selectedOption.data('category') || '';
            const supplierId = selectedOption.data('supplier') || '';
            
            // Update basic details
            $('#detail_quantity').text(currentQuantity);
            $('#detail_low_stock').text(lowStockAlert);
            $('#detail_item_name').text(selectedOption.text().split(' (')[0]);
            $('#detail_item_code').text(selectedOption.text().match(/\(([^)]+)\)/)?.[1] || '');
            $('#detail_unit_measure').text(unitMeasure);
            $('#detail_status').text(currentStatus);
            
            // Update status badge
            updateStatusBadge('detail_status', currentStatus);
            
            // Update location
            const currentLocation = locations.find(loc => loc.location_id == locationId);
            $('#detail_location').text(currentLocation ? currentLocation.location_type + ' - ' + currentLocation.location_name : 'Not assigned');
            
            // Set destination location if not already set
            if (!$('#to_location_id').val() && locationId) {
                $('#to_location_id').val(locationId).trigger('change');
            }
            
            // Get category and supplier details via AJAX if needed
            if (categoryId) {
                $.get('ajax/get_category_details.php?category_id=' + categoryId, function(data) {
                    if (data.success) {
                        $('#detail_category').text(data.category_name);
                    }
                }).fail(function() {
                    $('#detail_category').text('Not assigned');
                });
            } else {
                $('#detail_category').text('Not assigned');
            }
            
            // Update current stock for preview
            currentItem = {
                quantity: currentQuantity,
                lowStockAlert: lowStockAlert,
                locationId: locationId,
                locationName: currentLocation ? currentLocation.location_name : 'Not assigned',
                unitMeasure: unitMeasure,
                status: currentStatus
            };
            
            updatePreview();
            
        } else {
            $('#item_details_card').hide();
            currentItem = null;
            updatePreview();
        }
    });

    // Update preview based on form changes
    function updatePreview() {
        const quantity = parseInt($('#quantity').val()) || 0;
        const unitCost = parseFloat($('#unit_cost').val()) || 0;
        const toLocationId = $('#to_location_id').val();
        
        if (!currentItem) {
            // If no item selected, reset preview
            $('#preview_current').text('0');
            $('#preview_change').text('+0');
            $('#preview_new').text('0');
            $('#preview_current_status, #preview_status_badge').text('-');
            $('#preview_status_text').text('Status');
            $('#preview_new_status_text').text('New Status');
            $('#location_preview').hide();
            $('#cost_preview').text('$0.00');
            $('#inventory_update_confirmation').hide();
            return;
        }
        
        const currentQuantity = currentItem.quantity;
        const lowStockAlert = currentItem.lowStockAlert;
        const unitMeasure = currentItem.unitMeasure;
        const currentStatus = currentItem.status;
        
        const newQuantity = currentQuantity + quantity;
        const displayChange = '+' + quantity;
        
        // Update preview numbers
        $('#preview_current').text(currentQuantity);
        $('#preview_change').text(displayChange);
        $('#preview_new').text(newQuantity);
        
        // Update status indicators
        const currentStatusObj = updateStatusIndicator('preview_current_status', 'preview_status_text', currentQuantity, lowStockAlert, currentStatus);
        const newStatusObj = updateStatusIndicator('preview_status_badge', 'preview_new_status_text', newQuantity, lowStockAlert);
        
        // Update cost preview
        const totalCost = unitCost * quantity;
        $('#cost_preview').text('$' + totalCost.toFixed(2));
        
        // Show/hide warnings
        if (currentQuantity <= lowStockAlert && newQuantity > lowStockAlert) {
            $('#restock_warning').show();
            $('#good_stock_warning').hide();
        } else if (newQuantity > lowStockAlert) {
            $('#good_stock_warning').show();
            $('#restock_warning').hide();
        } else {
            $('#good_stock_warning').hide();
            $('#restock_warning').hide();
        }
        
        // Update location preview
        const toLocation = locations.find(loc => loc.location_id == toLocationId);
        if (toLocation) {
            $('#preview_location_change').text(toLocation.location_name);
            $('#location_preview').show();
        } else {
            $('#location_preview').hide();
        }
        
        // Show inventory update confirmation
        $('#inventory_update_confirmation').show();
        $('#inventory_update_text').text(
            `Main inventory will be updated from ${currentQuantity} to ${newQuantity} ${unitMeasure}. Status: ${currentStatusObj.text} → ${newStatusObj.text}`
        );
        
        // Update quantity help text
        let helpText = 'Enter the quantity received';
        if (currentQuantity <= lowStockAlert) {
            helpText = 'Restocking from low inventory (Low stock alert: ' + lowStockAlert + ')';
        }
        $('#quantity_help').text(helpText);
    }
    
    function updateStatusIndicator(badgeElementId, textElementId, quantity, lowStockAlert, currentStatus = null) {
        let status = 'In Stock';
        let statusClass = 'success';
        
        if (quantity <= 0) {
            status = 'Out of Stock';
            statusClass = 'danger';
        } else if (quantity <= lowStockAlert) {
            status = 'Low Stock';
            statusClass = 'warning';
        }
        
        // If currentStatus is provided (for initial display), use it
        if (currentStatus && badgeElementId === 'preview_current_status') {
            status = currentStatus;
            statusClass = getStatusClass(currentStatus);
        }
        
        $(`#${badgeElementId}`).text(status).removeClass('badge-success badge-warning badge-danger badge-secondary').addClass('badge-' + statusClass);
        $(`#${textElementId}`).text(status);
        
        return {
            text: status,
            class: statusClass
        };
    }
    
    function updateStatusBadge(elementId, status) {
        const statusClass = getStatusClass(status);
        $(`#${elementId}`).removeClass('badge-success badge-warning badge-danger badge-secondary').addClass('badge-' + statusClass);
    }
    
    function getStatusClass(status) {
        switch(status.toLowerCase()) {
            case 'out of stock':
                return 'danger';
            case 'low stock':
                return 'warning';
            case 'in stock':
                return 'success';
            default:
                return 'secondary';
        }
    }
    
    // Event listeners
    $('#item_id, #quantity, #unit_cost, #to_location_id').on('change input', updatePreview);
    
    // Auto-generate reference
    if (!$('#reference').val()) {
        const timestamp = new Date().toISOString().replace(/[-:]/g, '').split('.')[0];
        $('#reference').val('RECV-' + timestamp);
    }
    
    // Select purchase order item
    window.selectPOItem = function(itemId, maxQuantity, unitCost) {
        $('#item_id').val(itemId).trigger('change');
        $('#quantity').val(Math.min(1, maxQuantity));
        if (unitCost > 0) {
            $('#unit_cost').val(unitCost);
        }
        $('html, body').animate({
            scrollTop: $('#receiveForm').offset().top - 100
        }, 500);
    };
    
    // Auto-calculate total cost when unit cost or quantity changes
    $('#unit_cost, #quantity').on('input', function() {
        const unitCost = parseFloat($('#unit_cost').val()) || 0;
        const quantity = parseInt($('#quantity').val()) || 0;
        const totalCost = unitCost * quantity;
        $('#cost_preview').text('$' + totalCost.toFixed(2));
    });
    
    // Initial setup
    <?php if ($item): ?>
        $('#item_id').trigger('change');
    <?php endif; ?>
    
    // Set expiry date to today + 1 year as default
    const today = new Date();
    const oneYearLater = new Date(today.getFullYear() + 1, today.getMonth(), today.getDate());
    const formattedDate = oneYearLater.toISOString().split('T')[0];
    $('#expiry_date').val(formattedDate);
    
    // Form validation
    $('#receiveForm').on('submit', function(e) {
        const itemId = $('#item_id').val();
        const quantity = parseInt($('#quantity').val()) || 0;
        const toLocationId = $('#to_location_id').val();
        const unitCost = parseFloat($('#unit_cost').val()) || 0;
        
        let isValid = true;
        let errorMessage = '';
        
        // Validate required fields
        if (!itemId || quantity <= 0 || !toLocationId) {
            isValid = false;
            errorMessage = 'Please fill in all required fields with valid values.';
        }
        
        // Validate unit cost if provided
        if (unitCost < 0) {
            isValid = false;
            errorMessage = 'Unit cost cannot be negative.';
        }
        
        // Validate quantity
        if (quantity <= 0) {
            isValid = false;
            errorMessage = 'Quantity must be greater than zero.';
        }
        
        // Validate expiry date if provided
        const expiryDate = $('#expiry_date').val();
        if (expiryDate) {
            const expiry = new Date(expiryDate);
            if (expiry < new Date()) {
                isValid = false;
                errorMessage = 'Expiry date cannot be in the past.';
            }
        }
        
        if (!isValid) {
            e.preventDefault();
            alert(errorMessage);
            return false;
        }
        
        // Show loading state
        $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Receiving...').prop('disabled', true);
        
        // Show confirmation message
        if (!confirm(`Are you sure you want to receive ${quantity} ${currentItem?.unitMeasure || 'units'}?\n\nThis will increase inventory from ${currentItem?.quantity || 0} to ${(currentItem?.quantity || 0) + quantity}.`)) {
            e.preventDefault();
            $('button[type="submit"]').html('<i class="fas fa-check mr-2"></i>Receive Items').prop('disabled', false);
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
        $('#unit_cost').val('');
        $('#reference').val('');
        $('#notes').val('');
        $('#supplier_id').val('');
        $('#to_location_id').val('');
        $('#batch_number').val('');
        $('#related_visit_id').val('');
        
        // Reset auto-generated reference
        const timestamp = new Date().toISOString().replace(/[-:]/g, '').split('.')[0];
        $('#reference').val('RECV-' + timestamp);
        
        // Reset expiry date to default
        const today = new Date();
        const oneYearLater = new Date(today.getFullYear() + 1, today.getMonth(), today.getDate());
        const formattedDate = oneYearLater.toISOString().split('T')[0];
        $('#expiry_date').val(formattedDate);
    }
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + S to save
    if (e.ctrlKey && e.keyCode === 83) {
        e.preventDefault();
        $('#receiveForm').submit();
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
    const value = parseInt($(this).val()) || 0;
    
    if (value <= 0) {
        $(this).val(1);
        updatePreview();
    }
});

$('#unit_cost').on('input', function() {
    const value = parseFloat($(this).val()) || 0;
    
    if (value < 0) {
        $(this).val(0);
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

.card-header.bg-success {
    background-color: #28a745 !important;
}

.btn-success {
    background-color: #28a745;
    border-color: #28a745;
}

.btn-success:hover {
    background-color: #218838;
    border-color: #1e7e34;
}

#inventory_update_confirmation {
    border-left: 4px solid #28a745;
}

#restock_warning {
    border-left: 4px solid #ffc107;
}

#good_stock_warning {
    border-left: 4px solid #28a745;
}

#location_preview {
    border-left: 4px solid #17a2b8;
}
</style>

<?php 
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
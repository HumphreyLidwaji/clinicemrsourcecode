<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

$purchase_order_id = intval($_GET['po_id'] ?? 0);

if ($purchase_order_id <= 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid purchase order ID";
    header("Location: inventory_purchase_orders.php");
    exit;
}

// Get purchase order details
$po_sql = "SELECT 
            po.*,
            s.supplier_id,
            s.supplier_name,
            s.supplier_contact,
            s.supplier_phone,
            s.supplier_email,
            l.location_id,
            l.location_name,
            l.location_type
        FROM inventory_purchase_orders po
        LEFT JOIN suppliers s ON po.supplier_id = s.supplier_id
        LEFT JOIN inventory_locations l ON po.delivery_location_id = l.location_id
        WHERE po.purchase_order_id = ?
          AND po.is_active = 1";

$po_stmt = $mysqli->prepare($po_sql);
$po_stmt->bind_param("i", $purchase_order_id);
$po_stmt->execute();
$po_result = $po_stmt->get_result();
$po = $po_result->fetch_assoc();
$po_stmt->close();

if (!$po) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Purchase order not found.";
    header("Location: inventory_purchase_orders.php");
    exit;
}

if ($po['status'] === 'draft') {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = 
        "GRN cannot be created while the purchase order is in DRAFT status. 
         Please approve the purchase order first.";
    header("Location: inventory_purchase_order_view.php?id=" . $purchase_order_id);
    exit;
}

if (!in_array($po['status'], ['approved', 'partially_received'])) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = 
        "Purchase order is not in a valid state for GRN creation.";
    header("Location: inventory_purchase_orders.php");
    exit;
}


// Get purchase order items that still need to be received
$items_sql = "SELECT 
                poi.purchase_order_item_id,
                poi.item_id,
                poi.quantity_ordered,
                poi.quantity_received,
                poi.unit_cost,
                poi.notes as po_item_notes,
                ii.item_name,
                ii.item_code,
                ii.unit_of_measure,
                ii.is_drug,
                ii.requires_batch,
                ic.category_name,
                (poi.quantity_ordered - poi.quantity_received) as quantity_pending
            FROM inventory_purchase_order_items poi
            INNER JOIN inventory_items ii ON poi.item_id = ii.item_id
            LEFT JOIN inventory_categories ic ON ii.category_id = ic.category_id
            WHERE poi.purchase_order_id = ? 
              AND poi.is_active = 1
              AND (poi.quantity_ordered - poi.quantity_received) > 0
            ORDER BY ii.item_name";
            
$items_stmt = $mysqli->prepare($items_sql);
$items_stmt->bind_param("i", $purchase_order_id);
$items_stmt->execute();
$items_result = $items_stmt->get_result();
$items = [];
while ($item = $items_result->fetch_assoc()) {
    $items[] = $item;
}
$items_stmt->close();

if (empty($items)) {
    $_SESSION['alert_type'] = "warning";
    $_SESSION['alert_message'] = "All items on this purchase order have already been fully received.";
    header("Location: inventory_purchase_order_view.php?id=" . $purchase_order_id);
    exit;
}

// Generate GRN number
$grn_number = "GRN-" . date('Ymd') . "-" . strtoupper(substr(uniqid(), -6));

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token.";
        header("Location: inventory_grn_create.php?po_id=" . $purchase_order_id);
        exit;
    }

    // Get form data
    $grn_date = sanitizeInput($_POST['grn_date']);
    $invoice_number = sanitizeInput($_POST['invoice_number'] ?? '');
    $delivery_note_number = sanitizeInput($_POST['delivery_note_number'] ?? '');
    $notes = sanitizeInput($_POST['notes'] ?? '');
    
    // Get received items data
    $received_items = [];
    $total_received_value = 0;
    
    foreach ($_POST['items'] as $po_item_id => $item_data) {
        $quantity_received = floatval($item_data['quantity_received'] ?? 0);
        $batch_number = sanitizeInput($item_data['batch_number'] ?? '');
        $expiry_date = sanitizeInput($item_data['expiry_date'] ?? '');
        $item_notes = sanitizeInput($item_data['notes'] ?? '');
        
        if ($quantity_received > 0) {
            // Get item details to calculate cost
            $item_details_sql = "SELECT poi.unit_cost, poi.item_id 
                                FROM inventory_purchase_order_items poi 
                                WHERE poi.purchase_order_item_id = ?";
            $item_stmt = $mysqli->prepare($item_details_sql);
            $item_stmt->bind_param("i", $po_item_id);
            $item_stmt->execute();
            $item_result = $item_stmt->get_result();
            $item_details = $item_result->fetch_assoc();
            $item_stmt->close();
            
            $total_cost = $quantity_received * $item_details['unit_cost'];
            $total_received_value += $total_cost;
            
            $received_items[] = [
                'purchase_order_item_id' => intval($po_item_id),
                'item_id' => $item_details['item_id'],
                'quantity_received' => $quantity_received,
                'unit_cost' => $item_details['unit_cost'],
                'total_cost' => $total_cost,
                'batch_number' => $batch_number,
                'expiry_date' => $expiry_date,
                'notes' => $item_notes
            ];
        }
    }

    // Validate required fields
    if (empty($grn_date) || count($received_items) === 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please fill in all required fields and add at least one received item.";
        header("Location: inventory_grn_create.php?po_id=" . $purchase_order_id);
        exit;
    }

    // Validate quantities don't exceed pending amounts
    $valid_items = [];
    foreach ($received_items as $item) {
        // Get pending quantity for this item
        $pending_sql = "SELECT (quantity_ordered - quantity_received) as pending 
                       FROM inventory_purchase_order_items 
                       WHERE purchase_order_item_id = ?";
        $pending_stmt = $mysqli->prepare($pending_sql);
        $pending_stmt->bind_param("i", $item['purchase_order_item_id']);
        $pending_stmt->execute();
        $pending_result = $pending_stmt->get_result();
        $pending_data = $pending_result->fetch_assoc();
        $pending_stmt->close();
        
        $pending_quantity = $pending_data['pending'] ?? 0;
        
        if ($item['quantity_received'] > $pending_quantity) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Received quantity exceeds pending quantity for one or more items.";
            header("Location: inventory_grn_create.php?po_id=" . $purchase_order_id);
            exit;
        }
        
        $valid_items[] = $item;
    }

    // Start transaction
    $mysqli->begin_transaction();

    try {
        // Create GRN
        $grn_sql = "INSERT INTO inventory_grns (
            grn_number, grn_date, purchase_order_id, supplier_id, 
            received_location_id, invoice_number, delivery_note_number, 
            notes, received_by, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $grn_stmt = $mysqli->prepare($grn_sql);
        $grn_stmt->bind_param(
            "ssiiisssii",
            $grn_number,
            $grn_date,
            $purchase_order_id,
            $po['supplier_id'],
            $po['location_id'],
            $invoice_number,
            $delivery_note_number,
            $notes,
            $session_user_id,
            $session_user_id
        );
        $grn_stmt->execute();
        $grn_id = $mysqli->insert_id;
        $grn_stmt->close();

        // Create GRN items and update inventory
        foreach ($valid_items as $item) {
            // Create GRN item
            $grn_item_sql = "INSERT INTO inventory_grn_items (
                grn_id, purchase_order_item_id, item_id, batch_number,
                expiry_date, quantity_received, unit_cost, notes, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $grn_item_stmt = $mysqli->prepare($grn_item_sql);
            $grn_item_stmt->bind_param(
                "iiissddsi",
                $grn_id,
                $item['purchase_order_item_id'],
                $item['item_id'],
                $item['batch_number'],
                $item['expiry_date'],
                $item['quantity_received'],
                $item['unit_cost'],
                $item['notes'],
                $session_user_id
            );
            $grn_item_stmt->execute();
            $grn_item_id = $mysqli->insert_id;
            $grn_item_stmt->close();

            // Update purchase order item received quantity
            $update_po_item_sql = "UPDATE inventory_purchase_order_items 
                                   SET quantity_received = quantity_received + ?, 
                                       updated_by = ?, updated_at = NOW()
                                   WHERE purchase_order_item_id = ?";
            
            $update_stmt = $mysqli->prepare($update_po_item_sql);
            $update_stmt->bind_param("dii", $item['quantity_received'], $session_user_id, $item['purchase_order_item_id']);
            $update_stmt->execute();
            $update_stmt->close();

            // Check if batch needs to be created or updated
            if (!empty($item['batch_number']) && !empty($item['expiry_date'])) {
                // Check if batch already exists
                $check_batch_sql = "SELECT batch_id FROM inventory_batches 
                                   WHERE item_id = ? AND batch_number = ? AND is_active = 1";
                $check_stmt = $mysqli->prepare($check_batch_sql);
                $check_stmt->bind_param("is", $item['item_id'], $item['batch_number']);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows > 0) {
                    $batch_data = $check_result->fetch_assoc();
                    $batch_id = $batch_data['batch_id'];
                } else {
                    // Create new batch
                    $batch_sql = "INSERT INTO inventory_batches (
                        item_id, batch_number, expiry_date, supplier_id,
                        received_date, notes, created_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?)";
                    
                    $batch_stmt = $mysqli->prepare($batch_sql);
                    $batch_stmt->bind_param(
                        "ississs",
                        $item['item_id'],
                        $item['batch_number'],
                        $item['expiry_date'],
                        $po['supplier_id'],
                        $grn_date,
                        $item['notes'],
                        $session_user_id
                    );
                    $batch_stmt->execute();
                    $batch_id = $mysqli->insert_id;
                    $batch_stmt->close();
                }
                $check_stmt->close();

                // Check if stock already exists for this batch at this location
                $check_stock_sql = "SELECT stock_id, quantity FROM inventory_location_stock 
                                   WHERE batch_id = ? AND location_id = ? AND is_active = 1";
                $check_stock_stmt = $mysqli->prepare($check_stock_sql);
                $check_stock_stmt->bind_param("ii", $batch_id, $po['location_id']);
                $check_stock_stmt->execute();
                $check_stock_result = $check_stock_stmt->get_result();
                
                if ($check_stock_result->num_rows > 0) {
                    // Update existing stock
                    $stock_data = $check_stock_result->fetch_assoc();
                    $update_stock_sql = "UPDATE inventory_location_stock 
                                        SET quantity = quantity + ?, 
                                            unit_cost = ?, 
                                            last_movement_at = NOW(),
                                            updated_by = ?, updated_at = NOW()
                                        WHERE stock_id = ?";
                    
                    $update_stock_stmt = $mysqli->prepare($update_stock_sql);
                    $update_stock_stmt->bind_param(
                        "ddii",
                        $item['quantity_received'],
                        $item['unit_cost'],
                        $session_user_id,
                        $stock_data['stock_id']
                    );
                    $update_stock_stmt->execute();
                    $update_stock_stmt->close();
                } else {
                    // Create new stock entry
                    $stock_sql = "INSERT INTO inventory_location_stock (
                        batch_id, location_id, quantity, unit_cost,
                        last_movement_at, created_by
                    ) VALUES (?, ?, ?, ?, NOW(), ?)";
                    
                    $stock_stmt = $mysqli->prepare($stock_sql);
                    $stock_stmt->bind_param(
                        "iiddi",
                        $batch_id,
                        $po['location_id'],
                        $item['quantity_received'],
                        $item['unit_cost'],
                        $session_user_id
                    );
                    $stock_stmt->execute();
                    $stock_stmt->close();
                }
                $check_stock_stmt->close();

                // Create inventory transaction for GRN
                $transaction_sql = "INSERT INTO inventory_transactions (
                    transaction_type, item_id, batch_id, from_location_id,
                    to_location_id, quantity, unit_cost, reference_type,
                    reference_id, reason, created_by
                ) VALUES ('GRN', ?, ?, NULL, ?, ?, ?, 'grn', ?, 'Goods received via GRN', ?)";
                
                $transaction_stmt = $mysqli->prepare($transaction_sql);
                $transaction_stmt->bind_param(
                    "iiiddii",
                    $item['item_id'],
                    $batch_id,
                    $po['location_id'],
                    $item['quantity_received'],
                    $item['unit_cost'],
                    $grn_id,
                    $session_user_id
                );
                $transaction_stmt->execute();
                $transaction_stmt->close();
            }
        }

        // Update purchase order status if fully received
        $check_po_status_sql = "SELECT 
            SUM(quantity_ordered) as total_ordered,
            SUM(quantity_received) as total_received
            FROM inventory_purchase_order_items 
            WHERE purchase_order_id = ? AND is_active = 1";
        
        $check_po_stmt = $mysqli->prepare($check_po_status_sql);
        $check_po_stmt->bind_param("i", $purchase_order_id);
        $check_po_stmt->execute();
        $check_po_result = $check_po_stmt->get_result();
        $po_status_data = $check_po_result->fetch_assoc();
        $check_po_stmt->close();

        $new_status = 'partially_received';
        if ($po_status_data['total_received'] >= $po_status_data['total_ordered']) {
            $new_status = 'received';
        }

        $update_po_status_sql = "UPDATE inventory_purchase_orders 
                                SET status = ?, updated_by = ?, updated_at = NOW()
                                WHERE purchase_order_id = ?";
        
        $update_po_status_stmt = $mysqli->prepare($update_po_status_sql);
        $update_po_status_stmt->bind_param("sii", $new_status, $session_user_id, $purchase_order_id);
        $update_po_status_stmt->execute();
        $update_po_status_stmt->close();

        // Log the action
        $log_sql = "INSERT INTO logs SET
                  log_type = 'Inventory',
                  log_action = 'GRN Create',
                  log_description = ?,
                  log_ip = ?,
                  log_user_agent = ?,
                  log_user_id = ?,
                  log_entity_id = ?,
                  log_created_at = NOW()";
        $log_stmt = $mysqli->prepare($log_sql);
        $log_description = "Created GRN #" . $grn_number . " for PO #" . $po['po_number'] . " with " . count($valid_items) . " items";
        $log_stmt->bind_param("sssii", $log_description, $session_ip, $session_user_agent, $session_user_id, $grn_id);
        $log_stmt->execute();
        $log_stmt->close();

        $mysqli->commit();

        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "GRN #$grn_number created successfully!";
        header("Location: inventory_grn_view.php?id=" . $grn_id);
        exit;

    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error creating GRN: " . $e->getMessage();
        header("Location: inventory_grn_create.php?po_id=" . $purchase_order_id);
        exit;
    }
}
?>

<div class="card">
    <div class="card-header bg-success py-2">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h3 class="card-title mt-2 mb-0 text-white">
                    <i class="fas fa-fw fa-receipt mr-2"></i>Create Goods Receipt Note
                </h3>
                <small class="text-white-50">For PO: <?php echo htmlspecialchars($po['po_number']); ?> - <?php echo htmlspecialchars($po['supplier_name']); ?></small>
            </div>
            <div class="card-tools">
                <a href="inventory_purchase_order_view.php?id=<?php echo $purchase_order_id; ?>" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to PO
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

        <!-- PO Summary -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="info-box bg-gradient-info">
                    <span class="info-box-icon"><i class="fas fa-shopping-cart"></i></span>
                    <div class="info-box-content">
                        <div class="row">
                            <div class="col-md-3">
                                <span class="info-box-text">PO Number</span>
                                <span class="info-box-number"><?php echo htmlspecialchars($po['po_number']); ?></span>
                            </div>
                            <div class="col-md-3">
                                <span class="info-box-text">Supplier</span>
                                <span class="info-box-number"><?php echo htmlspecialchars($po['supplier_name']); ?></span>
                            </div>
                            <div class="col-md-3">
                                <span class="info-box-text">Delivery Location</span>
                                <span class="info-box-number"><?php echo htmlspecialchars($po['location_name']); ?></span>
                            </div>
                            <div class="col-md-3">
                                <span class="info-box-text">PO Date</span>
                                <span class="info-box-number"><?php echo date('M j, Y', strtotime($po['po_date'])); ?></span>
                            </div>
                        </div>
                        <div class="row mt-2">
                            <div class="col-md-12">
                                <small class="text-muted">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    This GRN will be created for items pending receipt from Purchase Order <?php echo htmlspecialchars($po['po_number']); ?>.
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <form method="POST" id="grnForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <div class="row">
                <div class="col-md-8">
                    <!-- GRN Details -->
                    <div class="card card-primary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-info-circle mr-2"></i>GRN Details</h3>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="grn_number">GRN Number *</label>
                                        <input type="text" class="form-control" id="grn_number" 
                                               value="<?php echo $grn_number; ?>" readonly>
                                        <small class="form-text text-muted">Auto-generated GRN number</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="grn_date">GRN Date *</label>
                                        <input type="date" class="form-control" id="grn_date" 
                                               name="grn_date" value="<?php echo date('Y-m-d'); ?>" required>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="invoice_number">Invoice Number</label>
                                        <input type="text" class="form-control" id="invoice_number" 
                                               name="invoice_number" placeholder="Enter invoice number...">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="delivery_note_number">Delivery Note Number</label>
                                        <input type="text" class="form-control" id="delivery_note_number" 
                                               name="delivery_note_number" placeholder="Enter delivery note number...">
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-12">
                                    <div class="form-group">
                                        <label for="notes">GRN Notes</label>
                                        <textarea class="form-control" id="notes" name="notes" rows="3" 
                                                  placeholder="Additional information about this GRN..."></textarea>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Supplier</label>
                                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($po['supplier_name']); ?>" readonly>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Receiving Location</label>
                                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($po['location_name'] . ' (' . $po['location_type'] . ')'); ?>" readonly>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Received Items Section -->
                    <div class="card card-success">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-cubes mr-2"></i>Received Items</h3>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-info mb-3">
                                <i class="fas fa-info-circle mr-2"></i>
                                <strong>Note:</strong> Only items pending receipt are shown below. 
                                Enter the actual quantities received and batch information if required.
                            </div>
                            
                            <div class="table-responsive">
                                <table class="table table-sm" id="itemsTable">
                                    <thead class="bg-light">
                                        <tr>
                                            <th width="30%">Item</th>
                                            <th width="10%" class="text-center">Pending</th>
                                            <th width="10%" class="text-center">Received *</th>
                                            <th width="15%">Batch Number</th>
                                            <th width="15%">Expiry Date</th>
                                            <th width="15%">Notes</th>
                                            <th width="5%"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="itemsTableBody">
                                        <?php foreach ($items as $index => $item): ?>
                                            <tr id="item_row_<?php echo $index; ?>">
                                                <td>
                                                    <input type="hidden" name="items[<?php echo $item['purchase_order_item_id']; ?>][item_id]" value="<?php echo $item['item_id']; ?>">
                                                    <strong><?php echo htmlspecialchars($item['item_name']); ?></strong><br>
                                                    <small class="text-muted">
                                                        <?php echo htmlspecialchars($item['item_code']); ?>
                                                        • <?php echo htmlspecialchars($item['unit_of_measure']); ?>
                                                        <?php if ($item['requires_batch'] == 1): ?>
                                                            <span class="badge badge-info ml-1">Batch Required</span>
                                                        <?php endif; ?>
                                                    </small>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge badge-warning">
                                                        <?php echo number_format($item['quantity_pending'], 3); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <input type="number" class="form-control form-control-sm quantity-received" 
                                                           name="items[<?php echo $item['purchase_order_item_id']; ?>][quantity_received]" 
                                                           min="0.001" max="<?php echo $item['quantity_pending']; ?>" 
                                                           step="0.001" value="<?php echo $item['quantity_pending']; ?>"
                                                           data-max="<?php echo $item['quantity_pending']; ?>"
                                                           onchange="validateQuantity(this)">
                                                </td>
                                                <td>
                                                    <input type="text" class="form-control form-control-sm batch-number" 
                                                           name="items[<?php echo $item['purchase_order_item_id']; ?>][batch_number]" 
                                                           placeholder="Enter batch..." 
                                                           <?php echo $item['requires_batch'] == 1 ? 'required' : ''; ?>>
                                                </td>
                                                <td>
                                                    <input type="date" class="form-control form-control-sm expiry-date" 
                                                           name="items[<?php echo $item['purchase_order_item_id']; ?>][expiry_date]" 
                                                           min="<?php echo date('Y-m-d'); ?>"
                                                           <?php echo $item['requires_batch'] == 1 ? 'required' : ''; ?>>
                                                </td>
                                                <td>
                                                    <input type="text" class="form-control form-control-sm" 
                                                           name="items[<?php echo $item['purchase_order_item_id']; ?>][notes]" 
                                                           placeholder="Item notes...">
                                                </td>
                                                <td class="text-center">
                                                    <div class="form-check">
                                                        <input type="checkbox" class="form-check-input select-item" 
                                                               checked onchange="toggleItemSelection(<?php echo $index; ?>)">
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <div class="mt-3">
                                <div class="alert alert-warning small mb-0">
                                    <i class="fas fa-exclamation-triangle mr-2"></i>
                                    <strong>Important:</strong> 
                                    <?php echo date('Y-m-d'); ?>
                                    - Batch numbers and expiry dates are required for items that require batch tracking.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <!-- Quick Actions -->
                    <div class="card card-info">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-bolt mr-2"></i>Quick Actions</h3>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-success btn-lg" id="submitBtn">
                                    <i class="fas fa-check mr-2"></i>Create GRN
                                </button>
                                <button type="reset" class="btn btn-outline-secondary" onclick="resetForm()">
                                    <i class="fas fa-undo mr-2"></i>Reset Form
                                </button>
                                <a href="inventory_purchase_order_view.php?id=<?php echo $purchase_order_id; ?>" class="btn btn-outline-danger">
                                    <i class="fas fa-times mr-2"></i>Cancel
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Batch Information Guide -->
                    <div class="card card-warning">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-box mr-2"></i>Batch Information Guide</h3>
                        </div>
                        <div class="card-body">
                            <div class="small">
                                <div class="mb-3">
                                    <h6 class="font-weight-bold text-primary">
                                        <i class="fas fa-exclamation-circle mr-1"></i>Batch Required Items
                                    </h6>
                                    <p class="mb-1">These items require batch tracking. Please provide:</p>
                                    <ul class="pl-3 mb-0">
                                        <li>Unique batch number</li>
                                        <li>Expiry date (future date)</li>
                                    </ul>
                                </div>
                                <div class="mb-2">
                                    <h6 class="font-weight-bold text-success">
                                        <i class="fas fa-check-circle mr-1"></i>Non-Batch Items
                                    </h6>
                                    <p class="mb-0">Batch information is optional for these items.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Summary -->
                    <div class="card card-primary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-calculator mr-2"></i>GRN Summary</h3>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-3">
                                <i class="fas fa-receipt fa-3x text-primary mb-2"></i>
                                <h5 id="summary_grn_number"><?php echo $grn_number; ?></h5>
                                <div class="text-muted" id="summary_supplier"><?php echo htmlspecialchars($po['supplier_name']); ?></div>
                            </div>
                            <hr>
                            <div class="small">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Total Items:</span>
                                    <span class="font-weight-bold" id="summary_item_count"><?php echo count($items); ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Total Quantity:</span>
                                    <span class="font-weight-bold" id="summary_total_quantity"><?php echo array_sum(array_column($items, 'quantity_pending')); ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Location:</span>
                                    <span class="font-weight-bold" id="summary_location"><?php echo htmlspecialchars($po['location_name']); ?></span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Date:</span>
                                    <span class="font-weight-bold" id="summary_date"><?php echo date('M j, Y'); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Validation Warnings -->
                    <div class="card card-danger">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-exclamation-triangle mr-2"></i>Validation Warnings</h3>
                        </div>
                        <div class="card-body">
                            <div id="validationMessages">
                                <div class="text-center text-muted">
                                    <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                                    <p>Form is ready for submission</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Tips -->
                    <div class="card card-light">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-lightbulb mr-2"></i>Quick Tips</h3>
                        </div>
                        <div class="card-body">
                            <div class="small">
                                <div class="mb-2">
                                    <strong>Partial Receipt:</strong> You can receive less than the pending quantity.
                                </div>
                                <div class="mb-2">
                                    <strong>Batch Numbers:</strong> Use consistent format (e.g., BATCH-2024-001).
                                </div>
                                <div class="mb-2">
                                    <strong>Expiry Dates:</strong> Must be in the future for batch items.
                                </div>
                                <div>
                                    <strong>Verification:</strong> GRN will require verification after creation.
                                </div>
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
    // Update summary on date change
    $('#grn_date').on('change', function() {
        if ($(this).val()) {
            const date = new Date($(this).val());
            $('#summary_date').text(date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }));
        }
    });

    // Update summary on quantity changes
    $('.quantity-received').on('input', function() {
        updateSummary();
        validateQuantity($(this));
    });

    // Validate batch required fields
    $('.batch-number, .expiry-date').on('change', function() {
        validateBatchFields($(this).closest('tr'));
    });

    // Initial summary update
    updateSummary();
});

function validateQuantity(input) {
    const row = $(input).closest('tr');
    const maxQuantity = parseFloat($(input).data('max')) || 0;
    const enteredQuantity = parseFloat($(input).val()) || 0;
    
    if (enteredQuantity > maxQuantity) {
        $(input).addClass('is-invalid');
        $(input).next('.invalid-feedback').remove();
        $(input).after('<div class="invalid-feedback">Cannot exceed ' + maxQuantity + '</div>');
        return false;
    } else if (enteredQuantity <= 0) {
        $(input).addClass('is-invalid');
        $(input).next('.invalid-feedback').remove();
        $(input).after('<div class="invalid-feedback">Quantity must be greater than 0</div>');
        return false;
    } else {
        $(input).removeClass('is-invalid');
        $(input).next('.invalid-feedback').remove();
        return true;
    }
}

function validateBatchFields(row) {
    const requiresBatch = row.find('.badge-info').length > 0;
    const batchNumber = row.find('.batch-number').val();
    const expiryDate = row.find('.expiry-date').val();
    
    if (requiresBatch) {
        if (!batchNumber || !expiryDate) {
            row.find('.batch-number, .expiry-date').addClass('is-invalid');
            return false;
        } else {
            // Check if expiry date is in the future
            const today = new Date().toISOString().split('T')[0];
            if (expiryDate < today) {
                row.find('.expiry-date').addClass('is-invalid');
                row.find('.expiry-date').next('.invalid-feedback').remove();
                row.find('.expiry-date').after('<div class="invalid-feedback">Expiry date must be in the future</div>');
                return false;
            } else {
                row.find('.batch-number, .expiry-date').removeClass('is-invalid');
                row.find('.invalid-feedback').remove();
                return true;
            }
        }
    }
    return true;
}

function toggleItemSelection(rowIndex) {
    const row = $('#item_row_' + rowIndex);
    const checkbox = row.find('.select-item');
    const inputs = row.find('input:not(.select-item), select, textarea');
    
    if (checkbox.is(':checked')) {
        inputs.prop('disabled', false);
        row.removeClass('table-secondary');
    } else {
        inputs.prop('disabled', true);
        row.addClass('table-secondary');
    }
    
    updateSummary();
}

function updateSummary() {
    let totalItems = 0;
    let totalQuantity = 0;
    
    $('.quantity-received').each(function() {
        const row = $(this).closest('tr');
        if (!row.hasClass('table-secondary') && row.find('.select-item').is(':checked')) {
            totalItems++;
            const quantity = parseFloat($(this).val()) || 0;
            totalQuantity += quantity;
        }
    });
    
    $('#summary_item_count').text(totalItems);
    $('#summary_total_quantity').text(totalQuantity.toFixed(3));
}

function resetForm() {
    if (confirm('Are you sure you want to reset the form? All entered data will be lost.')) {
        $('#grnForm')[0].reset();
        $('.is-invalid').removeClass('is-invalid');
        $('.invalid-feedback').remove();
        $('.table-secondary').removeClass('table-secondary');
        $('input:disabled').prop('disabled', false);
        $('.select-item').prop('checked', true);
        
        // Reset date to today
        $('#grn_date').val('<?php echo date('Y-m-d'); ?>');
        $('#summary_date').text('<?php echo date('M j, Y'); ?>');
        
        updateSummary();
        
        showToast('info', 'Form has been reset');
    }
}

// Form validation
$('#grnForm').on('submit', function(e) {
    e.preventDefault();
    
    // Clear previous error states
    $('.is-invalid').removeClass('is-invalid');
    $('.invalid-feedback').remove();
    
    // Validate GRN date
    const grnDate = $('#grn_date').val();
    if (!grnDate) {
        $('#grn_date').addClass('is-invalid');
        $('#grn_date').after('<div class="invalid-feedback">GRN date is required</div>');
        showToast('error', 'Please select a GRN date');
        return false;
    }
    
    // Validate at least one item is selected and has quantity
    let hasValidItems = false;
    let validationErrors = [];
    
    $('.select-item:checked').each(function() {
        const row = $(this).closest('tr');
        const quantityInput = row.find('.quantity-received');
        const quantity = parseFloat(quantityInput.val()) || 0;
        
        if (quantity > 0) {
            // Validate quantity
            if (!validateQuantity(quantityInput[0])) {
                validationErrors.push('Invalid quantity for ' + row.find('strong').text());
            }
            
            // Validate batch fields if required
            if (!validateBatchFields(row)) {
                validationErrors.push('Missing batch information for ' + row.find('strong').text());
            }
            
            hasValidItems = true;
        }
    });
    
    if (!hasValidItems) {
        showToast('error', 'Please enter received quantities for at least one item');
        return false;
    }
    
    if (validationErrors.length > 0) {
        showToast('error', validationErrors.join('<br>'));
        return false;
    }
    
    // Show confirmation
    const itemCount = $('.select-item:checked').length;
    const totalQuantity = parseFloat($('#summary_total_quantity').text());
    
    if (confirm(`Are you sure you want to create this GRN with ${itemCount} item(s) totaling ${totalQuantity} units?`)) {
        // Show loading state
        const submitBtn = $('#submitBtn');
        submitBtn.html('<i class="fas fa-spinner fa-spin mr-2"></i>Creating GRN...').prop('disabled', true);
        
        // Submit the form
        this.submit();
    }
});

function showToast(type, message) {
    // Create toast container if not exists
    if ($('.toast-container').length === 0) {
        $('body').append('<div class="toast-container" style="position: fixed; top: 20px; right: 20px; z-index: 1050;"></div>');
    }
    
    const toast = $(`
        <div class="toast" role="alert" aria-live="assertive" aria-atomic="true" data-delay="5000">
            <div class="toast-header bg-${type} text-white">
                <i class="fas fa-${getToastIcon(type)} mr-2"></i>
                <strong class="mr-auto">${type.charAt(0).toUpperCase() + type.slice(1)}</strong>
                <button type="button" class="ml-2 mb-1 close text-white" data-dismiss="toast">
                    <span>&times;</span>
                </button>
            </div>
            <div class="toast-body">
                ${message}
            </div>
        </div>
    `);
    
    $('.toast-container').append(toast);
    toast.toast('show');
    
    // Remove toast after it's hidden
    toast.on('hidden.bs.toast', function () {
        $(this).remove();
    });
}

function getToastIcon(type) {
    switch(type) {
        case 'success': return 'check-circle';
        case 'error': 
        case 'danger': return 'exclamation-triangle';
        case 'warning': return 'exclamation-circle';
        case 'info': return 'info-circle';
        default: return 'info-circle';
    }
}

// Initialize toast container on page load
$(document).ready(function() {
    if ($('.toast-container').length === 0) {
        $('body').append('<div class="toast-container" style="position: fixed; top: 20px; right: 20px; z-index: 1050;"></div>');
    }
});

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + S to save
    if (e.ctrlKey && e.keyCode === 83) {
        e.preventDefault();
        $('#grnForm').submit();
    }
    // Escape to cancel
    if (e.keyCode === 27) {
        window.location.href = 'inventory_purchase_order_view.php?id=<?php echo $purchase_order_id; ?>';
    }
    // Ctrl + R to reset
    if (e.ctrlKey && e.keyCode === 82) {
        e.preventDefault();
        resetForm();
    }
});
</script>

<style>
.info-box {
    box-shadow: 0 0 1px rgba(0,0,0,.125), 0 1px 3px rgba(0,0,0,.2);
    border-radius: .25rem;
    background: #fff;
    display: flex;
    margin-bottom: 1rem;
    min-height: 80px;
    padding: .5rem;
    position: relative;
}

.info-box .info-box-icon {
    border-radius: .25rem;
    display: flex;
    font-size: 1.875rem;
    justify-content: center;
    text-align: center;
    width: 70px;
    align-items: center;
}

.info-box .info-box-content {
    display: flex;
    flex-direction: column;
    justify-content: center;
    line-height: 1.8;
    flex: 1;
    padding: 0 10px;
}

.info-box .info-box-text {
    display: block;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    text-transform: uppercase;
    font-weight: 700;
    font-size: .875rem;
}

.info-box .info-box-number {
    font-weight: 700;
    font-size: 1.5rem;
}

.table-secondary {
    background-color: rgba(108, 117, 125, 0.1) !important;
}

.table-secondary input,
.table-secondary select {
    background-color: transparent !important;
}

.is-invalid {
    border-color: #dc3545;
    box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25);
}

.toast {
    min-width: 300px;
    max-width: 350px;
}

.btn:disabled {
    cursor: not-allowed;
    opacity: 0.6;
}

.form-control-sm {
    height: calc(1.5em + 0.5rem + 2px);
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
    line-height: 1.5;
}

.badge-info {
    font-size: 0.7em;
    padding: 0.2em 0.4em;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
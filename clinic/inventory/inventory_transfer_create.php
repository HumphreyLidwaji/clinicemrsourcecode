<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Initialize variables
$items = [];
$locations = [];
$batches = [];
$pre_selected_item_id = intval($_GET['item_id'] ?? 0);

// Generate transfer number
$transfer_number = "TRF-" . date('Ymd') . "-" . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);

// Get active inventory locations
$locations_sql = "SELECT location_id, location_name, location_type
                  FROM inventory_locations 
                  WHERE is_active = 1 
                  ORDER BY location_type, location_name";
$locations_result = $mysqli->query($locations_sql);
while ($location = $locations_result->fetch_assoc()) {
    $locations[] = $location;
}

// Get active inventory items with category info and stock
$items_sql = "SELECT 
                i.item_id, 
                i.item_name, 
                i.item_code,
                i.unit_of_measure,
                i.requires_batch,
                c.category_name
              FROM inventory_items i
              LEFT JOIN inventory_categories c ON i.category_id = c.category_id
              WHERE i.is_active = 1 AND i.status = 'active'
              ORDER BY i.item_name";
$items_result = $mysqli->query($items_sql);
while ($item = $items_result->fetch_assoc()) {
    $items[] = $item;
}

// Get batches with stock information
$batches_sql = "SELECT 
                  b.batch_id,
                  b.batch_number,
                  b.expiry_date,
                  b.item_id,
                  b.manufacturer,
                  i.item_name,
                  i.item_code,
                  i.unit_of_measure,
                  COALESCE(SUM(ils.quantity), 0) as total_stock,
                  GROUP_CONCAT(CONCAT(l.location_name, ' (', l.location_type, '): ', ils.quantity) SEPARATOR '; ') as locations_info
                FROM inventory_batches b
                INNER JOIN inventory_items i ON b.item_id = i.item_id
                LEFT JOIN inventory_location_stock ils ON b.batch_id = ils.batch_id AND ils.is_active = 1
                LEFT JOIN inventory_locations l ON ils.location_id = l.location_id
                WHERE b.is_active = 1 
                AND b.expiry_date >= CURDATE()
                GROUP BY b.batch_id, b.batch_number, b.expiry_date, b.item_id, b.manufacturer, i.item_name, i.item_code, i.unit_of_measure
                HAVING total_stock > 0
                ORDER BY b.expiry_date ASC, b.batch_number";
$batches_result = $mysqli->query($batches_sql);
$all_batches = [];
while ($batch = $batches_result->fetch_assoc()) {
    $all_batches[] = $batch;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    $from_location_id = intval($_POST['from_location_id']);
    $to_location_id = intval($_POST['to_location_id']);
    $notes = sanitizeInput($_POST['notes']);
    $transfer_date = sanitizeInput($_POST['transfer_date']);
    
    // Get transfer items from POST data - FIXED FORMAT
    $transfer_items = [];
    if (isset($_POST['batch_id']) && is_array($_POST['batch_id'])) {
        foreach ($_POST['batch_id'] as $index => $batch_id) {
            if (!empty($batch_id) && !empty($_POST['quantity'][$index])) {
                $transfer_items[] = [
                    'batch_id' => intval($batch_id),
                    'item_name' => sanitizeInput($_POST['item_name'][$index]),
                    'batch_number' => sanitizeInput($_POST['batch_number'][$index]),
                    'quantity' => floatval($_POST['quantity'][$index]),
                    'available_stock' => floatval($_POST['available_stock'][$index]),
                    'notes' => sanitizeInput($_POST['item_notes'][$index] ?? '')
                ];
            }
        }
    }

    // Validate CSRF token
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        header("Location: inventory_transfer_create.php");
        exit;
    }

    // Validate required fields
    if (empty($from_location_id) || empty($to_location_id) || empty($transfer_date) || count($transfer_items) === 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please fill in all required fields and add at least one item.";
        header("Location: inventory_transfer_create.php");
        exit;
    }

    // Validate locations are different
    if ($from_location_id == $to_location_id) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Source and destination locations must be different.";
        header("Location: inventory_transfer_create.php");
        exit;
    }

    // Validate transfer items
    foreach ($transfer_items as $item) {
        if ($item['quantity'] <= 0) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Please check all item quantities are valid.";
            header("Location: inventory_transfer_create.php");
            exit;
        }
        
        // Check if quantity exceeds available stock
        if ($item['quantity'] > $item['available_stock']) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Quantity exceeds available stock for batch " . $item['batch_number'];
            header("Location: inventory_transfer_create.php");
            exit;
        }
    }

    // Start transaction
    $mysqli->begin_transaction();

    try {
        // Insert transfer
        $transfer_sql = "INSERT INTO inventory_transfers (
            transfer_number, from_location_id, to_location_id, 
            status, requested_by, notes
        ) VALUES (?, ?, ?, 'pending', ?, ?)";
        
        $transfer_stmt = $mysqli->prepare($transfer_sql);
        $transfer_stmt->bind_param(
            "siiss",
            $transfer_number,
            $from_location_id,
            $to_location_id,
            $session_user_id,
            $notes
        );

        $transfer_stmt->execute();
        $transfer_id = $transfer_stmt->insert_id;
        $transfer_stmt->close();

        // Insert transfer items
        $item_sql = "INSERT INTO inventory_transfer_items (
            transfer_id, batch_id, quantity, notes
        ) VALUES (?, ?, ?, ?)";
        
        $item_stmt = $mysqli->prepare($item_sql);
        
        foreach ($transfer_items as $item) {
            $item_stmt->bind_param(
                "iids",
                $transfer_id,
                $item['batch_id'],
                $item['quantity'],
                $item['notes']
            );
            $item_stmt->execute();
        }
        $item_stmt->close();

        $mysqli->commit();

        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Transfer <strong>$transfer_number</strong> created successfully!";
        header("Location: inventory_transfer_view.php?id=" . $transfer_id);
        exit;

    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error creating transfer: " . $e->getMessage();
        header("Location: inventory_transfer_create.php");
        exit;
    }
}
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-truck-moving mr-2"></i>Create Inventory Transfer
            </h3>
            <div class="card-tools">
                <a href="inventory_transfers.php" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Transfers
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

        <form method="POST" id="transferForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <div class="row">
                <div class="col-md-8">
                    <!-- Transfer Information -->
                    <div class="card card-primary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-info-circle mr-2"></i>Transfer Information</h3>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="transfer_number">Transfer Number</label>
                                        <input type="text" class="form-control" id="transfer_number" 
                                               value="<?php echo htmlspecialchars($transfer_number); ?>" readonly>
                                        <small class="form-text text-muted">Auto-generated transfer number</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="transfer_date">Transfer Date *</label>
                                        <input type="date" class="form-control" id="transfer_date" name="transfer_date" 
                                               value="<?php echo date('Y-m-d'); ?>" required>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="from_location_id">From Location *</label>
                                        <select class="form-control select2" id="from_location_id" name="from_location_id" required>
                                            <option value="">- Select Source Location -</option>
                                            <?php foreach ($locations as $location): ?>
                                                <option value="<?php echo $location['location_id']; ?>">
                                                    <?php echo htmlspecialchars($location['location_name']); ?>
                                                    (<?php echo $location['location_type']; ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="to_location_id">To Location *</label>
                                        <select class="form-control select2" id="to_location_id" name="to_location_id" required>
                                            <option value="">- Select Destination Location -</option>
                                            <?php foreach ($locations as $location): ?>
                                                <option value="<?php echo $location['location_id']; ?>">
                                                    <?php echo htmlspecialchars($location['location_name']); ?>
                                                    (<?php echo $location['location_type']; ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="notes">Transfer Notes</label>
                                <textarea class="form-control" id="notes" name="notes" rows="3" 
                                          placeholder="Purpose of transfer, special instructions, etc..." 
                                          maxlength="500"></textarea>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Requested By</label>
                                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($session_name); ?>" readonly>
                                        <small class="form-text text-muted">Your name will be recorded as the requester</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Transfer Items -->
                    <div class="card card-warning">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h3 class="card-title"><i class="fas fa-list-ol mr-2"></i>Transfer Items</h3>
                            <button type="button" class="btn btn-sm btn-success" onclick="showBatchModal()">
                                <i class="fas fa-plus mr-1"></i>Add Item
                            </button>
                        </div>
                        <div class="card-body">
                            <div id="transfer_items_container">
                                <!-- Transfer items will be added here dynamically -->
                                <div class="text-center text-muted py-4" id="no_items_message">
                                    <i class="fas fa-boxes fa-2x mb-2"></i>
                                    <p>No items added yet. Click "Add Item" to start.</p>
                                </div>
                            </div>
                            
                            <!-- Transfer Summary -->
                            <div class="row mt-4">
                                <div class="col-md-6 offset-md-6">
                                    <table class="table table-sm table-borderless">
                                        <tr class="border-top">
                                            <td class="text-right"><strong>Total Items:</strong></td>
                                            <td class="text-right" width="150"><strong><span id="item_count">0</span> items</strong></td>
                                        </tr>
                                        <tr>
                                            <td class="text-right"><strong>Total Quantity:</strong></td>
                                            <td class="text-right"><strong><span id="total_quantity">0.000</span></strong></td>
                                        </tr>
                                    </table>
                                </div>
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
                                <button type="submit" class="btn btn-success btn-lg" id="submitBtn">
                                    <i class="fas fa-save mr-2"></i>Create Transfer
                                </button>
                                <button type="reset" class="btn btn-outline-secondary" onclick="resetForm()">
                                    <i class="fas fa-undo mr-2"></i>Reset Form
                                </button>
                                <a href="inventory_transfers.php" class="btn btn-outline-danger">
                                    <i class="fas fa-times mr-2"></i>Cancel
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Source Location Information -->
                    <div class="card card-info">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-sign-out-alt mr-2"></i>Source Location</h3>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-3">
                                <i class="fas fa-warehouse fa-3x text-info mb-2"></i>
                                <h5 id="from_location_name">Select Location</h5>
                            </div>
                            <hr>
                            <div class="small" id="from_location_details">
                                <div class="text-muted text-center">Select a source location</div>
                            </div>
                        </div>
                    </div>

                    <!-- Destination Location Information -->
                    <div class="card card-primary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-sign-in-alt mr-2"></i>Destination Location</h3>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-3">
                                <i class="fas fa-map-marker-alt fa-3x text-primary mb-2"></i>
                                <h5 id="to_location_name">Select Location</h5>
                                <div class="text-muted" id="to_location_type">-</div>
                            </div>
                        </div>
                    </div>

                    <!-- Available Batches -->
                    <div class="card card-warning">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-boxes mr-2"></i>Available Batches</h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-tool" onclick="refreshBatches()" title="Refresh">
                                    <i class="fas fa-sync-alt"></i>
                                </button>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div class="list-group list-group-flush" id="batchesList" style="max-height: 300px; overflow-y: auto;">
                                <?php foreach ($all_batches as $batch): ?>
                                    <div class="list-group-item list-group-item-action batch-item" 
                                         data-batch-id="<?php echo $batch['batch_id']; ?>"
                                         data-item-name="<?php echo htmlspecialchars($batch['item_name']); ?>"
                                         data-batch-number="<?php echo htmlspecialchars($batch['batch_number']); ?>"
                                         data-expiry-date="<?php echo $batch['expiry_date']; ?>"
                                         data-manufacturer="<?php echo htmlspecialchars($batch['manufacturer'] ?? ''); ?>"
                                         data-available-stock="<?php echo $batch['total_stock']; ?>"
                                         data-unit-measure="<?php echo htmlspecialchars($batch['unit_of_measure']); ?>"
                                         style="cursor: pointer;">
                                        <div class="d-flex w-100 justify-content-between">
                                            <h6 class="mb-1"><?php echo htmlspecialchars($batch['item_name']); ?></h6>
                                            <small class="text-muted"><?php echo number_format($batch['total_stock'], 3); ?></small>
                                        </div>
                                        <p class="mb-1 small">
                                            <strong>Batch:</strong> <?php echo htmlspecialchars($batch['batch_number']); ?>
                                            <?php if ($batch['manufacturer']): ?>
                                                • <strong>Mfr:</strong> <?php echo htmlspecialchars($batch['manufacturer']); ?>
                                            <?php endif; ?>
                                        </p>
                                        <small class="text-muted">
                                            <i class="fas fa-calendar-alt mr-1"></i>
                                            Expires: <?php echo date('M j, Y', strtotime($batch['expiry_date'])); ?>
                                            • <?php echo htmlspecialchars($batch['unit_of_measure']); ?>
                                        </small>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (empty($all_batches)): ?>
                                    <div class="list-group-item text-center text-muted">
                                        <i class="fas fa-box-open fa-2x mb-2"></i>
                                        <p>No batches with available stock found</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Transfer Preview -->
                    <div class="card card-danger">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-eye mr-2"></i>Transfer Preview</h3>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-3">
                                <i class="fas fa-truck-moving fa-3x text-danger mb-2"></i>
                                <h5 id="preview_transfer_number"><?php echo htmlspecialchars($transfer_number); ?></h5>
                                <div class="text-muted" id="preview_item_count">0 items</div>
                            </div>
                            <hr>
                            <div class="small">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>From:</span>
                                    <span class="font-weight-bold" id="preview_from_location">-</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>To:</span>
                                    <span class="font-weight-bold" id="preview_to_location">-</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Transfer Date:</span>
                                    <span class="font-weight-bold" id="preview_transfer_date">-</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Total Items:</span>
                                    <span class="font-weight-bold" id="preview_total_items">0</span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Requested By:</span>
                                    <span class="font-weight-bold text-success"><?php echo htmlspecialchars($session_name); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Batch Selection Modal -->
<div class="modal fade" id="batchSelectionModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-boxes mr-2"></i>Select Batch</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="row mb-3">
                    <div class="col-md-12">
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text"><i class="fas fa-search"></i></span>
                            </div>
                            <input type="text" class="form-control" id="batchSearch" placeholder="Search batches by item name, batch number, or manufacturer...">
                        </div>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover" id="batchesTable">
                        <thead class="bg-light">
                            <tr>
                                <th>Item</th>
                                <th>Batch Number</th>
                                <th>Manufacturer</th>
                                <th class="text-center">Expiry Date</th>
                                <th class="text-center">Available Stock</th>
                                <th class="text-center">Unit</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($all_batches as $batch): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($batch['item_name']); ?></strong><br>
                                        <small class="text-muted"><?php echo htmlspecialchars($batch['item_code']); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($batch['batch_number']); ?></td>
                                    <td><?php echo $batch['manufacturer'] ? htmlspecialchars($batch['manufacturer']) : '-'; ?></td>
                                    <td class="text-center <?php echo strtotime($batch['expiry_date']) < strtotime('+30 days') ? 'text-warning' : ''; ?>">
                                        <?php echo date('M j, Y', strtotime($batch['expiry_date'])); ?>
                                        <?php if (strtotime($batch['expiry_date']) < strtotime('+30 days')): ?>
                                            <br><small class="text-danger">Expiring soon</small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge <?php echo $batch['total_stock'] > 0 ? 'badge-success' : 'badge-secondary'; ?>">
                                            <?php echo number_format($batch['total_stock'], 3); ?>
                                        </span>
                                    </td>
                                    <td class="text-center"><?php echo htmlspecialchars($batch['unit_of_measure']); ?></td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-sm btn-success select-batch-btn" 
                                                data-batch-id="<?php echo $batch['batch_id']; ?>"
                                                data-item-name="<?php echo htmlspecialchars($batch['item_name']); ?>"
                                                data-batch-number="<?php echo htmlspecialchars($batch['batch_number']); ?>"
                                                data-expiry-date="<?php echo $batch['expiry_date']; ?>"
                                                data-manufacturer="<?php echo htmlspecialchars($batch['manufacturer'] ?? ''); ?>"
                                                data-available-stock="<?php echo $batch['total_stock']; ?>"
                                                data-unit-measure="<?php echo htmlspecialchars($batch['unit_of_measure']); ?>"
                                                <?php echo $batch['total_stock'] <= 0 ? 'disabled' : ''; ?>>
                                            <i class="fas fa-plus mr-1"></i>Add
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
// Global variables
let itemCounter = 0;
const batches = <?php echo json_encode($all_batches); ?>;
const locations = <?php echo json_encode($locations); ?>;

// Helper function to escape HTML
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Function to add batch to transfer
function addBatchToTransfer(batchId, itemName, batchNumber, expiryDate, manufacturer, availableStock, unitMeasure) {
    try {
        itemCounter++;
        
        const manufacturerText = manufacturer ? `<small class="form-text text-muted">Manufacturer: ${escapeHtml(manufacturer)}</small>` : '';
        const expiryText = `<small class="form-text text-muted">Expires: ${escapeHtml(expiryDate)}</small>`;
        
        const itemRow = `
            <div class="transfer-item-row border rounded p-3 mb-3" id="item_row_${itemCounter}">
                <div class="row">
                    <div class="col-md-5">
                        <div class="form-group">
                            <label>Item & Batch *</label>
                            <input type="hidden" name="batch_id[]" value="${escapeHtml(batchId)}">
                            <input type="hidden" name="item_name[]" value="${escapeHtml(itemName)}">
                            <input type="hidden" name="batch_number[]" value="${escapeHtml(batchNumber)}">
                            <input type="hidden" name="available_stock[]" value="${escapeHtml(availableStock)}">
                            <input type="text" class="form-control" value="${escapeHtml(itemName)} - ${escapeHtml(batchNumber)}" readonly>
                            ${manufacturerText}
                            ${expiryText}
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Available Stock</label>
                            <input type="text" class="form-control" value="${parseFloat(availableStock).toFixed(3)} ${escapeHtml(unitMeasure)}" readonly>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Quantity *</label>
                            <input type="number" class="form-control quantity" name="quantity[]" 
                                   min="0.001" step="0.001" max="${availableStock}" 
                                   value="${Math.min(1, availableStock).toFixed(3)}" required
                                   onchange="calculateTotalQuantity()">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Notes</label>
                            <input type="text" class="form-control" name="item_notes[]" 
                                   placeholder="Item notes..." maxlength="255">
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-10">
                        <div class="form-group mb-0">
                            <input type="text" class="form-control" placeholder="Optional: Special handling instructions" 
                                   name="item_instructions[]" maxlength="255">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <button type="button" class="btn btn-danger btn-block" onclick="removeTransferItem(${itemCounter})">
                            <i class="fas fa-trash"></i> Remove
                        </button>
                    </div>
                </div>
            </div>
        `;
        
        $('#no_items_message').hide();
        $('#transfer_items_container').append(itemRow);
        $('#batchSelectionModal').modal('hide');
        
        calculateTotalQuantity();
        updatePreview();
        
        // Show success message
        showToast('success', `Added ${itemName} (${batchNumber}) to transfer`);
    } catch (error) {
        console.error('Error adding batch:', error);
        showToast('error', 'Error adding batch. Please try again.');
    }
}

// Function to remove transfer item
function removeTransferItem(itemId) {
    if (confirm('Are you sure you want to remove this item?')) {
        $('#item_row_' + itemId).remove();
        
        // Show no items message if no items left
        if ($('.transfer-item-row').length === 0) {
            $('#no_items_message').show();
        }
        
        calculateTotalQuantity();
        updatePreview();
        
        showToast('info', 'Item removed from transfer');
    }
}

// Show batch selection modal
function showBatchModal() {
    $('#batchSelectionModal').modal('show');
}

// Calculate total quantity
function calculateTotalQuantity() {
    let totalQuantity = 0;
    let itemCount = 0;
    
    $('.quantity').each(function() {
        const quantity = parseFloat($(this).val()) || 0;
        totalQuantity += quantity;
        itemCount++;
    });
    
    $('#item_count').text(itemCount);
    $('#total_quantity').text(totalQuantity.toFixed(3));
    $('#preview_total_items').text(itemCount);
    $('#preview_item_count').text(itemCount + ' item' + (itemCount !== 1 ? 's' : ''));
}

// Update preview
function updatePreview() {
    calculateTotalQuantity();
}

// Refresh batches list
function refreshBatches() {
    location.reload();
}

// Reset form
function resetForm() {
    if (confirm('Are you sure you want to reset the entire form? All items will be removed.')) {
        $('#transferForm')[0].reset();
        $('#transfer_items_container').empty();
        $('#no_items_message').show();
        calculateTotalQuantity();
        updatePreview();
        $('.select2').val('').trigger('change');
        $('#transfer_date').val('<?php echo date('Y-m-d'); ?>').trigger('change');
        
        showToast('info', 'Form has been reset');
    }
}

// Show toast notification
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

$(document).ready(function() {
    // Initialize Select2
    $('.select2').select2();

    // Initialize DataTable for batches modal
    $('#batchesTable').DataTable({
        pageLength: 10,
        order: [[3, 'asc']], // Sort by expiry date
        language: {
            search: "Search batches:"
        },
        drawCallback: function() {
            // Re-attach event listeners after DataTable redraws
            attachModalEventListeners();
        }
    });

    // Search functionality for batches
    $('#batchSearch').on('keyup', function() {
        const searchTerm = $(this).val().toLowerCase();
        $('#batchesTable tbody tr').each(function() {
            const rowText = $(this).text().toLowerCase();
            $(this).toggle(rowText.indexOf(searchTerm) > -1);
        });
    });

    // Attach event listeners to modal buttons
    function attachModalEventListeners() {
        $('.select-batch-btn').off('click').on('click', function() {
            const batchId = $(this).data('batch-id');
            const itemName = $(this).data('item-name');
            const batchNumber = $(this).data('batch-number');
            const expiryDate = $(this).data('expiry-date');
            const manufacturer = $(this).data('manufacturer');
            const availableStock = $(this).data('available-stock');
            const unitMeasure = $(this).data('unit-measure');
            
            addBatchToTransfer(batchId, itemName, batchNumber, expiryDate, manufacturer, availableStock, unitMeasure);
        });
    }

    // Attach click events to batch list items
    $('.batch-item').off('click').on('click', function() {
        const batchId = $(this).data('batch-id');
        const itemName = $(this).data('item-name');
        const batchNumber = $(this).data('batch-number');
        const expiryDate = $(this).data('expiry-date');
        const manufacturer = $(this).data('manufacturer');
        const availableStock = $(this).data('available-stock');
        const unitMeasure = $(this).data('unit-measure');
        
        addBatchToTransfer(batchId, itemName, batchNumber, expiryDate, manufacturer, availableStock, unitMeasure);
    });

    // Initial attachment of event listeners
    attachModalEventListeners();

    // Update from location details when selected
    $('#from_location_id').on('change', function() {
        const locationId = $(this).val();
        const selectedOption = $(this).find('option:selected');
        
        if (locationId) {
            const locationText = selectedOption.text().split(' (');
            $('#from_location_name').text(locationText[0]);
            $('#preview_from_location').text(locationText[0]);
        } else {
            $('#from_location_name').text('Select Location');
            $('#preview_from_location').text('-');
        }
    });

    // Update to location details when selected
    $('#to_location_id').on('change', function() {
        const locationId = $(this).val();
        const selectedOption = $(this).find('option:selected');
        
        if (locationId) {
            const locationText = selectedOption.text().split(' (');
            $('#to_location_name').text(locationText[0]);
            $('#to_location_type').text(locationText[1] ? locationText[1].replace(')', '') : '-');
            $('#preview_to_location').text(locationText[0]);
        } else {
            $('#to_location_name').text('Select Location');
            $('#to_location_type').text('-');
            $('#preview_to_location').text('-');
        }
    });

    // Update transfer date preview
    $('#transfer_date').on('change', function() {
        $('#preview_transfer_date').text($(this).val());
    });

    // Validate that locations are different
    function validateLocations() {
        const fromLocation = $('#from_location_id').val();
        const toLocation = $('#to_location_id').val();
        
        if (fromLocation && toLocation && fromLocation === toLocation) {
            $('#to_location_id').addClass('is-invalid');
            $('<div class="invalid-feedback">Source and destination locations must be different.</div>').insertAfter('#to_location_id');
            return false;
        } else {
            $('#to_location_id').removeClass('is-invalid');
            $('#to_location_id').next('.invalid-feedback').remove();
            return true;
        }
    }

    $('#from_location_id, #to_location_id').on('change', validateLocations);

    // Auto-set transfer date to today
    $('#transfer_date').trigger('change');

    // Form validation
    $('#transferForm').on('submit', function(e) {
        const transferDate = $('#transfer_date').val();
        const fromLocationId = $('#from_location_id').val();
        const toLocationId = $('#to_location_id').val();
        const itemCount = $('.transfer-item-row').length;
        
        let isValid = true;
        
        // Validate required fields
        if (!transferDate || !fromLocationId || !toLocationId || itemCount === 0) {
            isValid = false;
        }
        
        // Validate locations are different
        if (fromLocationId === toLocationId) {
            isValid = false;
            $('#to_location_id').addClass('is-invalid');
        }
        
        // Validate all items have valid quantities
        $('.quantity').each(function() {
            const quantity = parseFloat($(this).val()) || 0;
            const maxQuantity = parseFloat($(this).attr('max')) || 0;
            
            if (quantity <= 0) {
                isValid = false;
                $(this).addClass('is-invalid');
            } else if (quantity > maxQuantity) {
                isValid = false;
                $(this).addClass('is-invalid');
                $(this).next('.invalid-feedback').remove();
                $('<div class="invalid-feedback">Quantity exceeds available stock.</div>').insertAfter($(this));
            } else {
                $(this).removeClass('is-invalid');
                $(this).next('.invalid-feedback').remove();
            }
        });
        
        if (!isValid) {
            e.preventDefault();
            showToast('error', 'Please fill in all required fields, ensure locations are different, and all items have valid quantities that do not exceed available stock.');
            return false;
        }
        
        // Show loading state
        const submitBtn = $('#submitBtn');
        submitBtn.html('<i class="fas fa-spinner fa-spin mr-2"></i>Creating...').prop('disabled', true);
    });

    // Keyboard shortcuts
    $(document).keydown(function(e) {
        // Ctrl + S to save
        if (e.ctrlKey && e.keyCode === 83) {
            e.preventDefault();
            if ($('#transferForm')[0].checkValidity()) {
                $('#transferForm').submit();
            } else {
                showToast('error', 'Please fill in all required fields before submitting.');
            }
        }
        // Escape to cancel
        if (e.keyCode === 27) {
            window.location.href = 'inventory_transfers.php';
        }
        // Ctrl + R to reset
        if (e.ctrlKey && e.keyCode === 82) {
            e.preventDefault();
            resetForm();
        }
        // Ctrl + A to add item
        if (e.ctrlKey && e.keyCode === 65) {
            e.preventDefault();
            showBatchModal();
        }
    });
});
</script>

<style>
.transfer-item-row {
    background-color: #f8f9fa;
    border-left: 4px solid #17a2b8 !important;
}

.transfer-item-row:hover {
    background-color: #e9ecef;
}

#batchesTable_wrapper {
    margin: 0;
}

.select-batch-btn {
    cursor: pointer;
}

.badge-lg {
    font-size: 1rem;
    padding: 0.5rem 1rem;
}

.card-header.bg-primary {
    background: linear-gradient(45deg, #0066cc, #007bff) !important;
}

.form-control:read-only {
    background-color: #f8f9fa;
}

.batch-item:hover {
    background-color: #f8f9fa;
}

.list-group-item-action {
    transition: background-color 0.2s;
}

.modal-lg {
    max-width: 90%;
}

.dataTables_wrapper {
    margin-top: 0 !important;
}

.toast {
    min-width: 300px;
    max-width: 350px;
}

.btn:disabled {
    cursor: not-allowed;
    opacity: 0.6;
}

.table-sm td {
    padding: 0.3rem;
}

.table-sm th {
    padding: 0.5rem 0.3rem;
}

.card-header {
    padding: 0.5rem 1rem;
}

.card-title {
    font-size: 1.1rem;
    margin-bottom: 0;
}

.btn-sm {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
}
</style>

<?php 
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
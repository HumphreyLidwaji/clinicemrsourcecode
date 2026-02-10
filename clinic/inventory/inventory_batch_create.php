<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Get item ID from URL
$item_id = intval($_GET['item_id'] ?? 0);

if ($item_id <= 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid item ID.";
    header("Location: inventory_items.php");
    exit;
}

// Get item details
$item_sql = "SELECT i.*, ic.category_name, ic.category_type 
             FROM inventory_items i
             LEFT JOIN inventory_categories ic ON i.category_id = ic.category_id
             WHERE i.item_id = ?";
$item_stmt = $mysqli->prepare($item_sql);
$item_stmt->bind_param("i", $item_id);
$item_stmt->execute();
$item_result = $item_stmt->get_result();

if ($item_result->num_rows === 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Item not found.";
    header("Location: inventory_items.php");
    exit;
}

$item = $item_result->fetch_assoc();
$item_stmt->close();

// Check if item requires batch tracking
if (!$item['requires_batch']) {
    $_SESSION['alert_type'] = "warning";
    $_SESSION['alert_message'] = "This item does not require batch tracking. Use regular stock addition instead.";
    header("Location: inventory_item_details.php?item_id=" . $item_id);
    exit;
}

// Get suppliers for dropdown
$suppliers_sql = "SELECT supplier_id, supplier_name FROM suppliers WHERE supplier_is_active = 1 ORDER BY supplier_name";
$suppliers_result = $mysqli->query($suppliers_sql);

// Get active locations for stock placement
$locations_sql = "SELECT location_id, location_name, location_type 
                  FROM inventory_locations 
                  WHERE is_active = 1 
                  ORDER BY location_type, location_name";
$locations_result = $mysqli->query($locations_sql);

// Get existing batches for this item (for reference)
$existing_batches_sql = "SELECT batch_number, expiry_date, manufacturer 
                         FROM inventory_batches 
                         WHERE item_id = ? AND is_active = 1 
                         ORDER BY expiry_date ASC, batch_id DESC 
                         LIMIT 5";
$existing_batches_stmt = $mysqli->prepare($existing_batches_sql);
$existing_batches_stmt->bind_param("i", $item_id);
$existing_batches_stmt->execute();
$existing_batches_result = $existing_batches_stmt->get_result();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token'] ?? '');
    
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        header("Location: inventory_batch_create.php?item_id=" . $item_id);
        exit;
    }
    
    addBatch();
}

function addBatch() {
    global $mysqli, $session_user_id, $session_ip, $session_user_agent, $item_id, $item;
    
    // Get form data
    $batch_number = sanitizeInput($_POST['batch_number']);
    $expiry_date = sanitizeInput($_POST['expiry_date']);
    $manufacturer = sanitizeInput($_POST['manufacturer'] ?? '');
    $supplier_id = intval($_POST['supplier_id'] ?? 0);
    $received_date = sanitizeInput($_POST['received_date']);
    $notes = sanitizeInput($_POST['notes'] ?? '');
    $location_id = intval($_POST['location_id'] ?? 0);
    $quantity = floatval($_POST['quantity'] ?? 0);
    $unit_cost = floatval($_POST['unit_cost'] ?? 0);
    $selling_price = floatval($_POST['selling_price'] ?? 0);
    
    // Validate required fields
    $errors = [];
    
    if (empty($batch_number)) {
        $errors[] = "Batch number is required.";
    }
    
    if (empty($expiry_date)) {
        $errors[] = "Expiry date is required.";
    } elseif (strtotime($expiry_date) < strtotime('today')) {
        $errors[] = "Expiry date cannot be in the past.";
    }
    
    if (empty($received_date)) {
        $errors[] = "Received date is required.";
    } elseif (strtotime($received_date) > strtotime('today')) {
        $errors[] = "Received date cannot be in the future.";
    }
    
    if ($location_id <= 0) {
        $errors[] = "Please select a location for stock placement.";
    }
    
    if ($quantity <= 0) {
        $errors[] = "Quantity must be greater than 0.";
    }
    
    if ($unit_cost < 0) {
        $errors[] = "Unit cost cannot be negative.";
    }
    
    if ($selling_price < 0) {
        $errors[] = "Selling price cannot be negative.";
    }
    
    if (!empty($errors)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = implode("<br>", $errors);
        $_SESSION['form_data'] = $_POST;
        header("Location: inventory_batch_create.php?item_id=" . $item_id);
        exit;
    }
    
    $mysqli->begin_transaction();
    
    try {
        // Check if batch number already exists for this item
        $check_sql = "SELECT batch_id FROM inventory_batches 
                      WHERE item_id = ? AND batch_number = ? 
                      AND is_active = 1";
        $check_stmt = $mysqli->prepare($check_sql);
        $check_stmt->bind_param("is", $item_id, $batch_number);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            throw new Exception("Batch number '$batch_number' already exists for this item.");
        }
        $check_stmt->close();
        
        // Insert new batch
        $batch_sql = "INSERT INTO inventory_batches (item_id, batch_number, expiry_date, manufacturer, supplier_id, received_date, notes, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $batch_stmt = $mysqli->prepare($batch_sql);
        $batch_stmt->bind_param("isssissi", 
            $item_id,
            $batch_number,
            $expiry_date,
            $manufacturer,
            $supplier_id,
            $received_date,
            $notes,
            $session_user_id
        );
        
        if (!$batch_stmt->execute()) {
            throw new Exception("Failed to create batch: " . $batch_stmt->error);
        }
        
        $batch_id = $batch_stmt->insert_id;
        $batch_stmt->close();
        
        // Add stock to location
        $stock_sql = "INSERT INTO inventory_location_stock (batch_id,item_id, location_id, quantity, unit_cost, selling_price, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $stock_stmt = $mysqli->prepare($stock_sql);
        $stock_stmt->bind_param("iiidddi",
            $batch_id,
            $item_id,
            $location_id,
            $quantity,
            $unit_cost,
            $selling_price,
            $session_user_id
        );
        
        if (!$stock_stmt->execute()) {
            throw new Exception("Failed to add stock to location: " . $stock_stmt->error);
        }
        
        $stock_id = $stock_stmt->insert_id;
        $stock_stmt->close();
        
        // Record GRN transaction
        $transaction_sql = "INSERT INTO inventory_transactions (transaction_type, item_id, batch_id, to_location_id, quantity, unit_cost, reason, created_by, created_at) VALUES ('GRN', ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $transaction_stmt = $mysqli->prepare($transaction_sql);
        $reason = "GRN for batch $batch_number - " . ($notes ?: "Initial stock receipt");
        $transaction_stmt->bind_param("iiiddsi",
            $item_id,
            $batch_id,
            $location_id,
            $quantity,
            $unit_cost,
            $reason,
            $session_user_id
        );
        
        if (!$transaction_stmt->execute()) {
            throw new Exception("Failed to record transaction: " . $transaction_stmt->error);
        }
        
        $transaction_stmt->close();
        
        // Log the action
        $log_sql = "INSERT INTO logs SET
                   log_type = 'Inventory',
                   log_action = 'Batch Create',
                   log_description = ?,
                   log_ip = ?,
                   log_user_agent = ?,
                   log_user_id = ?,
                   log_entity_id = ?,
                   log_created_at = NOW()";
        $log_stmt = $mysqli->prepare($log_sql);
        $log_description = "Created batch $batch_number for item: " . $item['item_name'] . " with " . $quantity . " units at location " . $location_id;
        $log_stmt->bind_param("sssii", $log_description, $session_ip, $session_user_agent, $session_user_id, $batch_id);
        $log_stmt->execute();
        $log_stmt->close();
        
        $mysqli->commit();
        
        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Batch <strong>$batch_number</strong> created successfully with <strong>$quantity</strong> units!";
        header("Location: inventory_item_details.php?item_id=" . $item_id);
        exit;
        
    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error creating batch: " . $e->getMessage();
        $_SESSION['form_data'] = $_POST;
        header("Location: inventory_batch_create.php?item_id=" . $item_id);
        exit;
    }
}

// Get form data from session if exists
$form_data = $_SESSION['form_data'] ?? null;
if (isset($_SESSION['form_data'])) {
    unset($_SESSION['form_data']);
}
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h3 class="card-title mt-2 mb-0 text-white">
                    <i class="fas fa-fw fa-plus-circle mr-2"></i>Add New Batch
                </h3>
                <small class="text-white">
                    For: <?php echo htmlspecialchars($item['item_name']); ?> 
                    (<?php echo htmlspecialchars($item['item_code']); ?>)
                </small>
            </div>
            <div class="card-tools">
                <a href="inventory_item_details.php?item_id=<?php echo $item_id; ?>" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Item
                </a>
            </div>
        </div>
    </div>

    <?php if (isset($_SESSION['alert_message'])): ?>
    <div class="card-body border-bottom py-2">
        <div class="alert alert-<?php echo $_SESSION['alert_type']; ?> alert-dismissible mb-0">
            <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
            <i class="icon fas fa-<?php echo $_SESSION['alert_type'] == 'success' ? 'check' : 'exclamation-triangle'; ?>"></i>
            <?php echo $_SESSION['alert_message']; ?>
        </div>
        <?php 
        unset($_SESSION['alert_type']);
        unset($_SESSION['alert_message']);
        ?>
    </div>
    <?php endif; ?>

    <div class="card-body">
        <!-- Item Information Card -->
        <div class="card card-info mb-4">
            <div class="card-body">
                <div class="row">
                    <div class="col-md-2 text-center">
                        <div class="mb-3">
                            <?php if ($item['is_drug']): ?>
                                <i class="fas fa-pills fa-3x text-danger"></i>
                            <?php else: ?>
                                <i class="fas fa-cube fa-3x text-primary"></i>
                            <?php endif; ?>
                        </div>
                        <?php if ($item['is_drug']): ?>
                            <span class="badge badge-danger">Drug Item</span>
                        <?php endif; ?>
                        <span class="badge badge-info">Batch Tracked</span>
                    </div>
                    <div class="col-md-10">
                        <div class="row">
                            <div class="col-md-4">
                                <h5><?php echo htmlspecialchars($item['item_name']); ?></h5>
                                <p class="mb-1"><strong>Code:</strong> <?php echo htmlspecialchars($item['item_code']); ?></p>
                                <p class="mb-1"><strong>Category:</strong> <?php echo htmlspecialchars($item['category_name'] ?? 'N/A'); ?></p>
                            </div>
                            <div class="col-md-4">
                                <p class="mb-1"><strong>Unit:</strong> <?php echo htmlspecialchars($item['unit_of_measure']); ?></p>
                                <p class="mb-1"><strong>Reorder Level:</strong> <?php echo number_format($item['reorder_level'], 3); ?></p>
                                <p class="mb-1"><strong>Status:</strong> 
                                    <span class="badge badge-<?php echo $item['status'] == 'active' ? 'success' : 'secondary'; ?>">
                                        <?php echo ucfirst($item['status']); ?>
                                    </span>
                                </p>
                            </div>
                            <div class="col-md-4">
                                <?php if ($item['notes']): ?>
                                    <p class="mb-1"><strong>Notes:</strong></p>
                                    <p class="mb-0 small text-muted"><?php echo htmlspecialchars($item['notes']); ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Left Column: Form -->
            <div class="col-md-8">
                <form method="POST" autocomplete="off" id="batchForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h5 class="card-title mb-0"><i class="fas fa-layer-group mr-2"></i>Batch Information</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Batch Number <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="batch_number" 
                                               value="<?php echo isset($form_data['batch_number']) ? htmlspecialchars($form_data['batch_number']) : ''; ?>" 
                                               required placeholder="Enter batch/lot number" maxlength="100">
                                        <small class="form-text text-muted">Unique identifier for this batch</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Manufacturer</label>
                                        <input type="text" class="form-control" name="manufacturer" 
                                               value="<?php echo isset($form_data['manufacturer']) ? htmlspecialchars($form_data['manufacturer']) : ''; ?>" 
                                               placeholder="Manufacturer name" maxlength="100">
                                        <small class="form-text text-muted">Optional manufacturer information</small>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Supplier</label>
                                        <select class="form-control select2" name="supplier_id">
                                            <option value="0">- Select Supplier -</option>
                                            <?php 
                                            // Reset pointer for suppliers result
                                            $suppliers_result->data_seek(0);
                                            while($supplier = $suppliers_result->fetch_assoc()): 
                                            ?>
                                                <?php
                                                $selected = isset($form_data['supplier_id']) && $form_data['supplier_id'] == $supplier['supplier_id'] ? 'selected' : '';
                                                ?>
                                                <option value="<?php echo $supplier['supplier_id']; ?>" <?php echo $selected; ?>>
                                                    <?php echo htmlspecialchars($supplier['supplier_name']); ?>
                                                </option>
                                            <?php endwhile; ?>
                                        </select>
                                        <small class="form-text text-muted">Optional supplier information</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Received Date <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" name="received_date" 
                                               value="<?php echo isset($form_data['received_date']) ? htmlspecialchars($form_data['received_date']) : date('Y-m-d'); ?>" 
                                               required max="<?php echo date('Y-m-d'); ?>">
                                        <small class="form-text text-muted">Date when batch was received</small>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Expiry Date <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" name="expiry_date" 
                                               value="<?php echo isset($form_data['expiry_date']) ? htmlspecialchars($form_data['expiry_date']) : ''; ?>" 
                                               required min="<?php echo date('Y-m-d'); ?>">
                                        <small class="form-text text-muted">Expiry date for this batch</small>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label>Notes</label>
                                <textarea class="form-control" name="notes" rows="2" 
                                          placeholder="Any additional notes about this batch..."><?php echo isset($form_data['notes']) ? htmlspecialchars($form_data['notes']) : ''; ?></textarea>
                                <small class="form-text text-muted">Optional notes about this batch</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h5 class="card-title mb-0"><i class="fas fa-boxes mr-2"></i>Stock Information</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Location <span class="text-danger">*</span></label>
                                        <select class="form-control select2" name="location_id" required>
                                            <option value="">- Select Location -</option>
                                            <?php 
                                            // Reset pointer for locations result
                                            $locations_result->data_seek(0);
                                            while($location = $locations_result->fetch_assoc()): 
                                            ?>
                                                <?php
                                                $selected = isset($form_data['location_id']) && $form_data['location_id'] == $location['location_id'] ? 'selected' : '';
                                                ?>
                                                <option value="<?php echo $location['location_id']; ?>" <?php echo $selected; ?>>
                                                    <?php echo htmlspecialchars($location['location_type'] . ' - ' . $location['location_name']); ?>
                                                </option>
                                            <?php endwhile; ?>
                                        </select>
                                        <small class="form-text text-muted">Where this batch will be stored</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Quantity <span class="text-danger">*</span></label>
                                        <input type="number" class="form-control" name="quantity" 
                                               value="<?php echo isset($form_data['quantity']) ? htmlspecialchars($form_data['quantity']) : '0'; ?>" 
                                               required min="0.001" step="0.001">
                                        <small class="form-text text-muted">Quantity to add (in <?php echo htmlspecialchars($item['unit_of_measure']); ?>)</small>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Unit Cost ($) <span class="text-danger">*</span></label>
                                        <input type="number" class="form-control" name="unit_cost" 
                                               value="<?php echo isset($form_data['unit_cost']) ? htmlspecialchars($form_data['unit_cost']) : '0.00'; ?>" 
                                               required min="0" step="0.0001">
                                        <small class="form-text text-muted">Cost per unit for this batch</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Selling Price ($)</label>
                                        <input type="number" class="form-control" name="selling_price" 
                                               value="<?php echo isset($form_data['selling_price']) ? htmlspecialchars($form_data['selling_price']) : '0.00'; ?>" 
                                               min="0" step="0.01">
                                        <small class="form-text text-muted">Optional selling price per unit</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <button type="submit" class="btn btn-success" id="submitBtn">
                            <i class="fas fa-save mr-2"></i>Create Batch & Add Stock
                        </button>
                        <button type="reset" class="btn btn-outline-secondary" onclick="resetForm()">
                            <i class="fas fa-undo mr-2"></i>Reset Form
                        </button>
                        <a href="inventory_item_details.php?item_id=<?php echo $item_id; ?>" class="btn btn-secondary">
                            <i class="fas fa-times mr-2"></i>Cancel
                        </a>
                    </div>
                </form>
            </div>
            
            <!-- Right Column: Information -->
            <div class="col-md-4">
                <!-- Quick Tips Card -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h5 class="card-title mb-0"><i class="fas fa-lightbulb mr-2"></i>Quick Tips</h5>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info small mb-3">
                            <i class="fas fa-info-circle mr-2"></i>
                            <strong>Batch Numbers:</strong> Must be unique for each item. Use clear, consistent naming.
                        </div>
                        <div class="alert alert-warning small mb-3">
                            <i class="fas fa-exclamation-triangle mr-2"></i>
                            <strong>Expiry Dates:</strong> Required for batch-tracked items. Set realistic dates.
                        </div>
                        <div class="alert alert-success small mb-3">
                            <i class="fas fa-check-circle mr-2"></i>
                            <strong>Stock Placement:</strong> Choose appropriate location for storage.
                        </div>
                        <div class="alert alert-primary small">
                            <i class="fas fa-dollar-sign mr-2"></i>
                            <strong>Cost & Pricing:</strong> Accurate cost tracking is essential for inventory valuation.
                        </div>
                    </div>
                </div>
                
                <!-- Existing Batches Card -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h5 class="card-title mb-0"><i class="fas fa-history mr-2"></i>Existing Batches</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($existing_batches_result->num_rows > 0): ?>
                            <div class="list-group list-group-flush small">
                                <?php 
                                $existing_batches_result->data_seek(0);
                                while($batch = $existing_batches_result->fetch_assoc()): 
                                ?>
                                    <div class="list-group-item px-0 py-2">
                                        <div class="font-weight-bold"><?php echo htmlspecialchars($batch['batch_number']); ?></div>
                                        <div class="text-muted">
                                            Expires: <?php echo date('M j, Y', strtotime($batch['expiry_date'])); ?>
                                            <?php 
                                            $days_diff = floor((strtotime($batch['expiry_date']) - time()) / (60 * 60 * 24));
                                            if ($days_diff < 0) {
                                                echo '<span class="badge badge-danger ml-2">Expired</span>';
                                            } elseif ($days_diff <= 30) {
                                                echo '<span class="badge badge-warning ml-2">' . $days_diff . ' days left</span>';
                                            }
                                            ?>
                                        </div>
                                        <?php if ($batch['manufacturer']): ?>
                                            <small>Manufacturer: <?php echo htmlspecialchars($batch['manufacturer']); ?></small>
                                        <?php endif; ?>
                                    </div>
                                <?php endwhile; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-muted mb-0">No existing batches found for this item.</p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Batch Number Generator -->
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h5 class="card-title mb-0"><i class="fas fa-magic mr-2"></i>Batch Number Generator</h5>
                    </div>
                    <div class="card-body">
                        <div class="form-group">
                            <label>Prefix</label>
                            <input type="text" class="form-control" id="batch_prefix" value="BATCH-" placeholder="Prefix">
                        </div>
                        <div class="form-group">
                            <label>Year</label>
                            <input type="checkbox" id="include_year" checked> Include Year
                        </div>
                        <div class="form-group">
                            <label>Sequence</label>
                            <input type="number" class="form-control" id="batch_sequence" value="1" min="1">
                        </div>
                        <button type="button" class="btn btn-outline-primary btn-block" onclick="generateBatchNumber()">
                            <i class="fas fa-bolt mr-2"></i>Generate Batch Number
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.select2').select2();
    
    // Set default expiry date to 1 year from now
    if (!$('input[name="expiry_date"]').val()) {
        var oneYearFromNow = new Date();
        oneYearFromNow.setFullYear(oneYearFromNow.getFullYear() + 1);
        var formattedDate = oneYearFromNow.toISOString().split('T')[0];
        $('input[name="expiry_date"]').val(formattedDate);
    }
    
    // Auto-calculate selling price if empty
    $('input[name="unit_cost"]').on('blur', function() {
        var cost = parseFloat($(this).val());
        var priceField = $('input[name="selling_price"]');
        
        if (cost > 0 && (!priceField.val() || priceField.val() == '0.00')) {
            // Suggest a price with 100% markup
            var suggestedPrice = (cost * 2).toFixed(2);
            priceField.val(suggestedPrice);
        }
    });
    
    // Handle form submission
    $('#batchForm').on('submit', function(e) {
        if (!validateForm()) {
            e.preventDefault();
            return false;
        }
        
        // Show loading state
        $('#submitBtn').html('<i class="fas fa-spinner fa-spin mr-2"></i>Creating...').prop('disabled', true);
        
        // Allow form to submit normally
        return true;
    });
});

function validateForm() {
    var isValid = true;
    var errors = [];
    
    // Clear previous errors
    $('.is-invalid').removeClass('is-invalid');
    
    // Check required fields
    var requiredFields = ['batch_number', 'expiry_date', 'received_date', 'location_id', 'quantity', 'unit_cost'];
    
    requiredFields.forEach(function(fieldName) {
        var field = $('[name="' + fieldName + '"]');
        var value = field.val();
        
        if (!value || value.trim() === '' || value === '0' || value === '0.00') {
            isValid = false;
            field.addClass('is-invalid');
            
            var fieldLabel = field.closest('.form-group').find('label').text().replace('*', '').trim();
            errors.push(fieldLabel + ' is required');
        }
    });
    
    // Check expiry date is in future
    var expiryDate = new Date($('[name="expiry_date"]').val());
    var today = new Date();
    today.setHours(0, 0, 0, 0);
    
    if (expiryDate && expiryDate < today) {
        isValid = false;
        $('[name="expiry_date"]').addClass('is-invalid');
        errors.push('Expiry date must be in the future');
    }
    
    // Check received date is not in future
    var receivedDate = new Date($('[name="received_date"]').val());
    if (receivedDate && receivedDate > today) {
        isValid = false;
        $('[name="received_date"]').addClass('is-invalid');
        errors.push('Received date cannot be in the future');
    }
    
    if (!isValid) {
        var errorMessage = 'Please fix the following errors:<br>' + errors.join('<br>');
        showErrorToast(errorMessage);
        return false;
    }
    
    return true;
}

function resetForm() {
    if (confirm('Are you sure you want to reset all changes? All entered data will be lost.')) {
        $('#batchForm')[0].reset();
        $('.select2').val('').trigger('change');
        $('.is-invalid').removeClass('is-invalid');
        $('#submitBtn').html('<i class="fas fa-save mr-2"></i>Create Batch & Add Stock').prop('disabled', false);
        
        // Reset dates to defaults
        var today = new Date().toISOString().split('T')[0];
        var oneYearFromNow = new Date();
        oneYearFromNow.setFullYear(oneYearFromNow.getFullYear() + 1);
        var formattedDate = oneYearFromNow.toISOString().split('T')[0];
        
        $('input[name="received_date"]').val(today);
        $('input[name="expiry_date"]').val(formattedDate);
        
        showToast('info', 'Form has been reset');
    }
}

function generateBatchNumber() {
    var prefix = $('#batch_prefix').val() || 'BATCH';
    var includeYear = $('#include_year').is(':checked');
    var sequence = $('#batch_sequence').val() || '1';
    
    var batchNumber = prefix;
    if (includeYear) {
        batchNumber += '-' + new Date().getFullYear();
    }
    batchNumber += '-' + sequence.toString().padStart(3, '0');
    
    $('input[name="batch_number"]').val(batchNumber);
    $('#batch_sequence').val(parseInt(sequence) + 1);
    
    showToast('success', 'Batch number generated: ' + batchNumber);
}

function showToast(type, message) {
    var toast = $('<div class="toast" role="alert" aria-live="assertive" aria-atomic="true" data-delay="3000">' +
        '<div class="toast-header bg-' + type + ' text-white">' +
        '<i class="fas fa-' + getToastIcon(type) + ' mr-2"></i>' +
        '<strong class="mr-auto">' + type.charAt(0).toUpperCase() + type.slice(1) + '</strong>' +
        '<button type="button" class="ml-2 mb-1 close text-white" data-dismiss="toast">' +
        '<span>&times;</span>' +
        '</button>' +
        '</div>' +
        '<div class="toast-body">' +
        message +
        '</div>' +
        '</div>');
    
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

function showErrorToast(message) {
    showToast('danger', message);
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + S to save
    if (e.ctrlKey && e.keyCode === 83) {
        e.preventDefault();
        if (validateForm()) {
            $('#batchForm').submit();
        }
    }
    // Escape to cancel
    if (e.keyCode === 27) {
        window.location.href = 'inventory_item_details.php?item_id=' + <?php echo $item_id; ?>;
    }
    // Ctrl + R to reset
    if (e.ctrlKey && e.keyCode === 82) {
        e.preventDefault();
        resetForm();
    }
    // Ctrl + G to generate batch number
    if (e.ctrlKey && e.keyCode === 71) {
        e.preventDefault();
        generateBatchNumber();
    }
});

// Initialize toast container if not exists
if ($('.toast-container').length === 0) {
    $('body').append('<div class="toast-container" style="position: fixed; top: 20px; right: 20px; z-index: 1050;"></div>');
}

// Prevent double form submission
var formSubmitted = false;
$('#batchForm').on('submit', function() {
    if (formSubmitted) {
        return false;
    }
    formSubmitted = true;
    return true;
});
</script>

<style>
.toast {
    min-width: 250px;
}

.form-control:focus {
    border-color: #80bdff;
    box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
}

.select2-container .select2-selection--single {
    height: 38px;
}

.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 36px;
}

.list-group-item {
    border: none;
    padding: 0.5rem 0;
}

.badge-pill {
    padding-right: 0.6em;
    padding-left: 0.6em;
}

.card-header.bg-light {
    background-color: #f8f9fa !important;
}

.is-invalid {
    border-color: #dc3545;
    box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25);
}

.btn:disabled {
    cursor: not-allowed;
    opacity: 0.6;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
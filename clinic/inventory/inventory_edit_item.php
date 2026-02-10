<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Check if item_id is provided
if (!isset($_GET['item_id']) || empty($_GET['item_id'])) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "No item specified for editing.";
    header("Location: inventory_items.php");
    exit;
}

$item_id = intval($_GET['item_id']);

// Initialize variables
$categories = [];
$suppliers = [];
$locations = [];
$item = null;

// Fetch the item to edit
$item_sql = "SELECT * FROM inventory_items WHERE item_id = ?";
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

// Get categories
$categories_sql = "SELECT * FROM inventory_categories WHERE is_active = 1 ORDER BY category_type, category_name";
$categories_result = $mysqli->query($categories_sql);
while ($category = $categories_result->fetch_assoc()) {
    $categories[] = $category;
}

// Get suppliers
$suppliers_sql = "SELECT * FROM suppliers WHERE supplier_is_active = 1 ORDER BY supplier_name";
$suppliers_result = $mysqli->query($suppliers_sql);
while ($supplier = $suppliers_result->fetch_assoc()) {
    $suppliers[] = $supplier;
}

// Get locations with enhanced data
$locations_sql = "SELECT * FROM inventory_locations WHERE is_active = 1 ORDER BY location_type, location_name";
$locations_result = $mysqli->query($locations_sql);
while ($location = $locations_result->fetch_assoc()) {
    $locations[] = $location;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    $item_name = sanitizeInput($_POST['item_name']);
    $item_code = sanitizeInput($_POST['item_code']);
    $category_id = intval($_POST['category_id']);
    $unit_of_measure = sanitizeInput($_POST['unit_of_measure']);
    $reorder_level = floatval($_POST['reorder_level']);
    
    // FIX: Use isset() to properly check checkboxes
    $is_drug = isset($_POST['is_drug']) && $_POST['is_drug'] == '1' ? 1 : 0;
    $requires_batch = isset($_POST['requires_batch']) && $_POST['requires_batch'] == '1' ? 1 : 0;
    
    // FIX: Ensure status has a default value
    $status = !empty($_POST['status']) ? sanitizeInput($_POST['status']) : 'active';
    
    $notes = sanitizeInput($_POST['notes']);

    // Debug logging (remove in production)
    error_log("DEBUG EDIT - is_drug: $is_drug, requires_batch: $requires_batch, status: $status");

    // Validate CSRF token
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        header("Location: inventory_item_edit.php?item_id=" . $item_id);
        exit;
    }

    // Validate required fields
    if (empty($item_name) || empty($item_code)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please fill in all required fields.";
        header("Location: inventory_item_edit.php?item_id=" . $item_id);
        exit;
    }

    // Check if item code already exists (excluding current item)
    $check_sql = "SELECT item_id FROM inventory_items WHERE item_code = ? AND item_id != ?";
    $check_stmt = $mysqli->prepare($check_sql);
    $check_stmt->bind_param("si", $item_code, $item_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($check_result->num_rows > 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Item code already exists. Please use a unique item code.";
        header("Location: inventory_item_edit.php?item_id=" . $item_id);
        exit;
    }

    // Start transaction for item update
    $mysqli->begin_transaction();

    try {
        // Update item
        $update_sql = "UPDATE inventory_items SET
            item_name = ?,
            item_code = ?,
            category_id = ?,
            unit_of_measure = ?,
            is_drug = ?,
            requires_batch = ?,
            reorder_level = ?,
            status = ?,
            notes = ?,
            updated_by = ?,
            updated_at = NOW()
            WHERE item_id = ?";

        $update_stmt = $mysqli->prepare($update_sql);
        
        // FIX: Corrected bind_param data types
        // s = string, i = integer, d = double (float), s = string
        $update_stmt->bind_param(
            "ssisiidssii",  // FIXED: Changed pattern to match data types
            $item_name,         // string
            $item_code,         // string
            $category_id,       // integer
            $unit_of_measure,   // string
            $is_drug,           // integer
            $requires_batch,    // integer
            $reorder_level,     // double/float
            $status,            // string
            $notes,             // string
            $session_user_id,   // integer
            $item_id            // integer
        );

        if (!$update_stmt->execute()) {
            throw new Exception("Failed to update item: " . $update_stmt->error);
        }
        
        $update_stmt->close();

        // Log the action
        $log_sql = "INSERT INTO logs SET
                  log_type = 'Inventory',
                  log_action = 'Update',
                  log_description = ?,
                  log_ip = ?,
                  log_user_agent = ?,
                  log_user_id = ?,
                  log_entity_id = ?,
                  log_created_at = NOW()";
        $log_stmt = $mysqli->prepare($log_sql);
        $log_description = "Updated inventory item: " . $item_name . " (Code: " . $item_code . ")";
        $log_stmt->bind_param("sssii", $log_description, $session_ip, $session_user_agent, $session_user_id, $item_id);
        $log_stmt->execute();
        $log_stmt->close();

        $mysqli->commit();

        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Item <strong>$item_name</strong> updated successfully!";
        
        // Check if we need to redirect to batch management
        if ($requires_batch && !$item['requires_batch']) {
            // Item changed from non-batch to batch tracking
            header("Location: inventory_batch_create.php?item_id=" . $item_id);
            exit;
        } else {
            header("Location: inventory_item_details.php?item_id=" . $item_id);
            exit;
        }

    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error updating item: " . $e->getMessage();
        header("Location: inventory_item_edit.php?item_id=" . $item_id);
        exit;
    }
}

// Get location statistics for the sidebar
$location_stats_sql = "SELECT 
    l.location_id,
    l.location_name, 
    l.location_type,
    COUNT(DISTINCT ils.batch_id) as item_count,
    COALESCE(SUM(ils.quantity), 0) as total_quantity
    FROM inventory_locations l
    LEFT JOIN inventory_location_stock ils ON l.location_id = ils.location_id
    WHERE l.is_active = 1
    GROUP BY l.location_id, l.location_name, l.location_type
    ORDER BY l.location_type, l.location_name
    LIMIT 6";
$location_stats_result = $mysqli->query($location_stats_sql);
$location_stats = [];
while ($stat = $location_stats_result->fetch_assoc()) {
    $location_stats[] = $stat;
}

// Get item statistics
$item_stats_sql = "SELECT 
    COALESCE(SUM(ils.quantity), 0) as total_stock,
    COUNT(DISTINCT ils.batch_id) as batch_count,
    COUNT(DISTINCT ils.location_id) as location_count
    FROM inventory_items i
    LEFT JOIN inventory_location_stock ils ON i.item_id = ils.item_id
    WHERE i.item_id = ?";
$item_stats_stmt = $mysqli->prepare($item_stats_sql);
$item_stats_stmt->bind_param("i", $item_id);
$item_stats_stmt->execute();
$item_stats_result = $item_stats_stmt->get_result();
$item_stats = $item_stats_result->fetch_assoc();
$item_stats_stmt->close();
?>

<div class="card">
    <div class="card-header bg-warning py-2">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h3 class="card-title mt-2 mb-0 text-white">
                    <i class="fas fa-fw fa-edit mr-2"></i>Edit Inventory Item
                    <small class="text-light ml-2"><?php echo htmlspecialchars($item['item_name']); ?> (<?php echo htmlspecialchars($item['item_code']); ?>)</small>
                </h3>
            </div>
            <div class="card-tools">
                <div class="btn-group">
                    <a href="inventory_item_details.php?item_id=<?php echo $item_id; ?>" class="btn btn-light">
                        <i class="fas fa-eye mr-2"></i>View Details
                    </a>
                    <a href="inventory_items.php" class="btn btn-light">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Inventory
                    </a>
                </div>
            </div>
        </div>
    </div>

    <?php if (isset($_SESSION['alert_message'])): ?>
    <div class="card-body border-bottom py-2">
        <div class="alert alert-<?php echo $_SESSION['alert_type']; ?> alert-dismissible mb-0">
            <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
            <i class="icon fas fa-<?php echo $_SESSION['alert_type'] == 'success' ? 'check' : ($_SESSION['alert_type'] == 'info' ? 'info-circle' : 'exclamation-triangle'); ?>"></i>
            <?php echo $_SESSION['alert_message']; ?>
        </div>
        <?php 
        unset($_SESSION['alert_type']);
        unset($_SESSION['alert_message']);
        ?>
    </div>
    <?php endif; ?>

    <div class="card-body">
        <!-- Quick Stats Row -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="info-box-container d-flex flex-wrap">
                    <div class="info-box bg-light mr-3 mb-2">
                        <span class="info-box-icon bg-primary"><i class="fas fa-boxes"></i></span>
                        <div class="info-box-content">
                            <span class="info-box-text">Total Stock</span>
                            <span class="info-box-number"><?php echo $item_stats['total_stock']; ?></span>
                        </div>
                    </div>
                    <div class="info-box bg-light mr-3 mb-2">
                        <span class="info-box-icon bg-info"><i class="fas fa-layer-group"></i></span>
                        <div class="info-box-content">
                            <span class="info-box-text">Batches</span>
                            <span class="info-box-number"><?php echo $item_stats['batch_count']; ?></span>
                        </div>
                    </div>
                    <div class="info-box bg-light mr-3 mb-2">
                        <span class="info-box-icon bg-success"><i class="fas fa-map-marker-alt"></i></span>
                        <div class="info-box-content">
                            <span class="info-box-text">Locations</span>
                            <span class="info-box-number"><?php echo $item_stats['location_count']; ?></span>
                        </div>
                    </div>
                    <div class="info-box bg-light mb-2">
                        <span class="info-box-icon bg-<?php echo $item['status'] == 'active' ? 'success' : 'secondary'; ?>">
                            <i class="fas fa-<?php echo $item['status'] == 'active' ? 'check-circle' : 'times-circle'; ?>"></i>
                        </span>
                        <div class="info-box-content">
                            <span class="info-box-text">Status</span>
                            <span class="info-box-number text-capitalize"><?php echo $item['status']; ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions Row -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="btn-toolbar justify-content-between">
                    <div class="btn-group">
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Form Status:</strong> 
                            <span class="badge badge-warning ml-2">Editing</span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Last Updated:</strong> 
                            <span class="badge badge-info ml-2">
                                <?php echo $item['updated_at'] ? date('M j, Y H:i', strtotime($item['updated_at'])) : 'Never'; ?>
                            </span>
                        </span>
                    </div>
                    <div class="btn-group">
                        <button type="submit" form="inventoryForm" class="btn btn-success">
                            <i class="fas fa-save mr-2"></i>Save Changes
                        </button>
                        <button type="reset" form="inventoryForm" class="btn btn-outline-secondary" onclick="resetForm()">
                            <i class="fas fa-undo mr-2"></i>Reset Changes
                        </button>
                        <div class="dropdown">
                            <button class="btn btn-secondary dropdown-toggle" type="button" data-toggle="dropdown">
                                <i class="fas fa-cog mr-2"></i>Actions
                            </button>
                            <div class="dropdown-menu">
                                <a class="dropdown-item" href="#" onclick="revertToOriginal()">
                                    <i class="fas fa-history mr-2"></i>Revert to Original
                                </a>
                                <a class="dropdown-item" href="#" onclick="validateForm()">
                                    <i class="fas fa-check-circle mr-2"></i>Validate Form
                                </a>
                                <div class="dropdown-divider"></div>
                                <?php if ($item['requires_batch']): ?>
                                <a class="dropdown-item" href="inventory_batch_create.php?item_id=<?php echo $item_id; ?>">
                                    <i class="fas fa-plus-circle mr-2"></i>Add New Batch
                                </a>
                                <?php endif; ?>
                                <a class="dropdown-item" href="inventory_adjustment_create.php?item_id=<?php echo $item_id; ?>">
                                    <i class="fas fa-balance-scale mr-2"></i>Adjust Stock
                                </a>
                                <a class="dropdown-item text-danger" href="#" onclick="confirmDelete()">
                                    <i class="fas fa-trash mr-2"></i>Delete Item
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Left Column: Form Fields -->
            <div class="col-md-8">
                <!-- Basic Information -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-info-circle mr-2"></i>Basic Information</h4>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="inventoryForm" autocomplete="off" onsubmit="return validateFormOnSubmit()">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" id="original_data" value="<?php echo htmlspecialchars(json_encode($item)); ?>">
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="item_name" class="form-required">Item Name</label>
                                        <input type="text" class="form-control" id="item_name" name="item_name" 
                                               value="<?php echo htmlspecialchars($item['item_name']); ?>" 
                                               placeholder="Enter item name" required maxlength="200">
                                        <small class="form-text text-muted">Descriptive name for the item</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="item_code" class="form-required">Item Code</label>
                                        <input type="text" class="form-control" id="item_code" name="item_code" 
                                               value="<?php echo htmlspecialchars($item['item_code']); ?>" 
                                               placeholder="Enter unique item code" required maxlength="50">
                                        <small class="form-text text-muted">Must be unique - used for scanning and tracking</small>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="category_id">Category</label>
                                        <select class="form-control select2" id="category_id" name="category_id">
                                            <option value="">- Select Category -</option>
                                            <?php foreach ($categories as $category): ?>
                                                <option value="<?php echo $category['category_id']; ?>" 
                                                    <?php echo $category['category_id'] == $item['category_id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($category['category_type'] . ' - ' . $category['category_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="form-text text-muted">Helps organize and filter items</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="unit_of_measure" class="form-required">Unit of Measure</label>
                                        <select class="form-control" id="unit_of_measure" name="unit_of_measure" required>
                                            <option value="">- Select Unit -</option>
                                            <option value="pcs" <?php echo $item['unit_of_measure'] == 'pcs' ? 'selected' : ''; ?>>Pieces</option>
                                            <option value="box" <?php echo $item['unit_of_measure'] == 'box' ? 'selected' : ''; ?>>Box</option>
                                            <option value="pack" <?php echo $item['unit_of_measure'] == 'pack' ? 'selected' : ''; ?>>Pack</option>
                                            <option value="bottle" <?php echo $item['unit_of_measure'] == 'bottle' ? 'selected' : ''; ?>>Bottle</option>
                                            <option value="roll" <?php echo $item['unit_of_measure'] == 'roll' ? 'selected' : ''; ?>>Roll</option>
                                            <option value="set" <?php echo $item['unit_of_measure'] == 'set' ? 'selected' : ''; ?>>Set</option>
                                            <option value="unit" <?php echo $item['unit_of_measure'] == 'unit' ? 'selected' : ''; ?>>Unit</option>
                                            <option value="ml" <?php echo $item['unit_of_measure'] == 'ml' ? 'selected' : ''; ?>>Milliliter</option>
                                            <option value="mg" <?php echo $item['unit_of_measure'] == 'mg' ? 'selected' : ''; ?>>Milligram</option>
                                            <option value="tablet" <?php echo $item['unit_of_measure'] == 'tablet' ? 'selected' : ''; ?>>Tablet</option>
                                            <option value="vial" <?php echo $item['unit_of_measure'] == 'vial' ? 'selected' : ''; ?>>Vial</option>
                                            <option value="tube" <?php echo $item['unit_of_measure'] == 'tube' ? 'selected' : ''; ?>>Tube</option>
                                            <option value="kit" <?php echo $item['unit_of_measure'] == 'kit' ? 'selected' : ''; ?>>Kit</option>
                                            <option value="pair" <?php echo $item['unit_of_measure'] == 'pair' ? 'selected' : ''; ?>>Pair</option>
                                            <option value="meter" <?php echo $item['unit_of_measure'] == 'meter' ? 'selected' : ''; ?>>Meter</option>
                                            <option value="liter" <?php echo $item['unit_of_measure'] == 'liter' ? 'selected' : ''; ?>>Liter</option>
                                            <option value="carton" <?php echo $item['unit_of_measure'] == 'carton' ? 'selected' : ''; ?>>Carton</option>
                                            <option value="case" <?php echo $item['unit_of_measure'] == 'case' ? 'selected' : ''; ?>>Case</option>
                                        </select>
                                        <small class="form-text text-muted">Measurement unit for this item</small>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="reorder_level">Reorder Level</label>
                                        <input type="number" class="form-control" id="reorder_level" 
                                               name="reorder_level" min="0" step="0.001" 
                                               value="<?php echo htmlspecialchars($item['reorder_level']); ?>">
                                        <small class="form-text text-muted">System will alert when stock reaches this level</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="status">Status</label>
                                        <select class="form-control" id="status" name="status" required>
                                            <option value="active" <?php echo $item['status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                                            <option value="inactive" <?php echo $item['status'] == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                        </select>
                                        <small class="form-text text-muted">Item availability status</small>
                                    </div>
                                </div>
                            </div>
                    </div>
                </div>

                <!-- Item Properties -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-tags mr-2"></i>Item Properties</h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-check mb-3">
                                    <input type="checkbox" class="form-check-input" id="is_drug" name="is_drug" value="1"
                                        <?php echo $item['is_drug'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="is_drug">
                                        <i class="fas fa-pills text-danger mr-2"></i>This is a drug/pharmaceutical item
                                    </label>
                                    <small class="form-text text-muted">Check for medications and pharmaceuticals</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check mb-3">
                                    <input type="checkbox" class="form-check-input" id="requires_batch" name="requires_batch" value="1"
                                        <?php echo $item['requires_batch'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="requires_batch">
                                        <i class="fas fa-layer-group text-info mr-2"></i>Requires batch/lot tracking
                                    </label>
                                    <small class="form-text text-muted">Check for items with batch numbers and expiry dates</small>
                                </div>
                            </div>
                        </div>
                        
                        <?php if ($item['requires_batch'] && $item_stats['batch_count'] > 0): ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle mr-2"></i>
                            <strong>Warning:</strong> Changing batch tracking settings may affect existing batches and stock records.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Additional Information -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-sticky-note mr-2"></i>Additional Information</h4>
                    </div>
                    <div class="card-body">
                        <div class="form-group">
                            <label for="notes">Notes</label>
                            <textarea class="form-control" id="notes" name="notes" rows="3" 
                                      placeholder="Additional notes, special handling instructions, storage requirements, etc..." 
                                      maxlength="1000"><?php echo htmlspecialchars($item['notes']); ?></textarea>
                            <small class="form-text text-muted">Any additional information about this item</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Preview & Information -->
            <div class="col-md-4">
                <!-- Quick Actions Card -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-bolt mr-2"></i>Quick Actions</h4>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2 mb-3">
                            <button type="submit" form="inventoryForm" class="btn btn-success btn-lg">
                                <i class="fas fa-save mr-2"></i>Save Changes
                            </button>
                            <button type="reset" form="inventoryForm" class="btn btn-outline-secondary" onclick="resetForm()">
                                <i class="fas fa-undo mr-2"></i>Reset Changes
                            </button>
                            <a href="inventory_item_details.php?item_id=<?php echo $item_id; ?>" class="btn btn-outline-secondary">
                                <i class="fas fa-times mr-2"></i>Cancel
                            </a>
                        </div>
                        
                        <div class="mb-3">
                            <div class="alert alert-info small mb-0">
                                <i class="fas fa-keyboard mr-2"></i>
                                <strong>Keyboard Shortcuts:</strong><br>
                                <kbd>Ctrl</kbd> + <kbd>S</kbd> Save<br>
                                <kbd>Ctrl</kbd> + <kbd>R</kbd> Reset<br>
                                <kbd>Esc</kbd> Cancel<br>
                                <kbd>Ctrl</kbd> + <kbd>H</kbd> Revert
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Item Preview -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-eye mr-2"></i>Item Preview</h4>
                    </div>
                    <div class="card-body">
                        <div class="text-center mb-3">
                            <div class="mb-3">
                                <div class="info-box-icon bg-primary mx-auto" style="width: 60px; height: 60px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                    <i class="fas fa-cube fa-2x text-white"></i>
                                </div>
                            </div>
                            <h5 id="preview_item_name" class="mb-1"><?php echo htmlspecialchars($item['item_name']); ?></h5>
                            <div id="preview_item_code" class="text-muted small">Code: <?php echo htmlspecialchars($item['item_code']); ?></div>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <th width="40%" class="text-muted">Category:</th>
                                    <td id="preview_category" class="text-right">
                                        <?php 
                                        $category_name = '-';
                                        foreach ($categories as $cat) {
                                            if ($cat['category_id'] == $item['category_id']) {
                                                $category_name = htmlspecialchars($cat['category_name']);
                                                break;
                                            }
                                        }
                                        echo $category_name;
                                        ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Unit:</th>
                                    <td id="preview_unit" class="text-right"><?php echo htmlspecialchars($item['unit_of_measure']); ?></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Reorder Level:</th>
                                    <td id="preview_reorder" class="text-right"><?php echo htmlspecialchars($item['reorder_level']); ?></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Status:</th>
                                    <td id="preview_status" class="text-right">
                                        <span class="badge badge-<?php echo $item['status'] == 'active' ? 'success' : 'secondary'; ?>">
                                            <?php echo ucfirst($item['status']); ?>
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Properties:</th>
                                    <td id="preview_properties" class="text-right">
                                        <?php if ($item['is_drug']): ?>
                                            <span class="badge badge-danger mr-1">Drug</span>
                                        <?php endif; ?>
                                        <?php if ($item['requires_batch']): ?>
                                            <span class="badge badge-info">Batch Tracked</span>
                                        <?php endif; ?>
                                        <?php if (!$item['is_drug'] && !$item['requires_batch']): ?>
                                            <span class="badge badge-secondary">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Location Overview -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-map-marker-alt mr-2"></i>Location Overview</h4>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($location_stats)): ?>
                            <div class="info-box bg-light mb-3">
                                <span class="info-box-icon bg-primary"><i class="fas fa-warehouse"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Active Locations</span>
                                    <span class="info-box-number"><?php echo count($location_stats); ?></span>
                                </div>
                            </div>
                            
                            <div class="list-group list-group-flush">
                                <?php foreach ($location_stats as $stat): ?>
                                    <div class="list-group-item px-0 py-2 border-bottom">
                                        <div class="d-flex w-100 justify-content-between align-items-center">
                                            <div>
                                                <h6 class="mb-1"><?php echo htmlspecialchars($stat['location_name']); ?></h6>
                                                <small class="text-muted"><?php echo htmlspecialchars($stat['location_type']); ?></small>
                                            </div>
                                            <div class="text-right">
                                                <span class="badge badge-primary badge-pill"><?php echo $stat['item_count']; ?></span>
                                                <div class="small text-muted mt-1"><?php echo $stat['total_quantity']; ?> items</div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="text-center mt-3">
                                <a href="inventory_locations.php" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-plus mr-1"></i>Manage Locations
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-3">
                                <i class="fas fa-info-circle fa-2x text-muted mb-2"></i>
                                <p class="text-muted mb-0">No locations configured</p>
                                <a href="inventory_locations.php" class="btn btn-sm btn-primary mt-2">
                                    <i class="fas fa-plus mr-1"></i>Add Location
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Quick Tips -->
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-lightbulb mr-2"></i>Quick Tips</h4>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info small mb-3">
                            <i class="fas fa-info-circle mr-2"></i>
                            <strong>Batch Tracking:</strong> Disabling batch tracking for items with existing batches may cause data inconsistencies.
                        </div>
                        <div class="alert alert-warning small mb-3">
                            <i class="fas fa-exclamation-triangle mr-2"></i>
                            <strong>Status Change:</strong> Setting item to inactive will hide it from most inventory operations.
                        </div>
                        <div class="alert alert-success small mb-3">
                            <i class="fas fa-check-circle mr-2"></i>
                            <strong>Code Uniqueness:</strong> Ensure item codes remain unique across the entire inventory.
                        </div>
                        <div class="alert alert-primary small">
                            <i class="fas fa-clipboard-list mr-2"></i>
                            <strong>Audit Trail:</strong> All changes are logged for compliance and tracking purposes.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize Select2
    $('.select2').select2();

    // Store original form values
    var originalData = JSON.parse($('#original_data').val());
    
    // Update preview in real-time
    $('#item_name').on('input', function() {
        $('#preview_item_name').text($(this).val() || originalData.item_name);
    });

    $('#item_code').on('input', function() {
        $('#preview_item_code').text('Code: ' + ($(this).val() || originalData.item_code));
    });

    $('#category_id').on('change', function() {
        var selectedText = $(this).find('option:selected').text();
        $('#preview_category').text(selectedText.split(' - ')[1] || '-');
    });

    $('#unit_of_measure').on('change', function() {
        $('#preview_unit').text($(this).val() || originalData.unit_of_measure);
    });

    $('#reorder_level').on('input', function() {
        $('#preview_reorder').text($(this).val() || originalData.reorder_level);
    });

    $('#status').on('change', function() {
        var status = $(this).val();
        var badgeClass = status === 'active' ? 'badge-success' : 'badge-secondary';
        $('#preview_status').html('<span class="badge ' + badgeClass + '">' + status.charAt(0).toUpperCase() + status.slice(1) + '</span>');
    });

    // Update properties preview
    $('#is_drug, #requires_batch').on('change', function() {
        updatePropertiesPreview();
    });

    function updatePropertiesPreview() {
        var properties = [];
        if ($('#is_drug').is(':checked')) {
            properties.push('<span class="badge badge-danger mr-1">Drug</span>');
        }
        if ($('#requires_batch').is(':checked')) {
            properties.push('<span class="badge badge-info">Batch Tracked</span>');
        }
        
        if (properties.length > 0) {
            $('#preview_properties').html(properties.join(' '));
        } else {
            $('#preview_properties').html('<span class="badge badge-secondary">-</span>');
        }
    }

    // Enhanced form validation on submit
    function validateFormOnSubmit() {
        var requiredFields = ['item_name', 'item_code', 'unit_of_measure', 'status'];
        var isValid = true;
        var errorMessages = [];
        
        // Clear previous errors
        $('.is-invalid').removeClass('is-invalid');
        $('.invalid-feedback').remove();
        
        requiredFields.forEach(function(field) {
            var $field = $('#' + field);
            var value = $field.val();
            var fieldName = $('label[for="' + field + '"]').text().replace('*', '').trim();
            
            if (!value) {
                isValid = false;
                errorMessages.push(fieldName + ' is required');
                $field.addClass('is-invalid');
                $field.after('<div class="invalid-feedback">' + fieldName + ' is required</div>');
            }
        });

        // Check for changes
        var hasChanges = checkFormChanges();
        if (!hasChanges) {
            if (confirm('No changes were made to the form. Continue anyway?')) {
                window.location.href = 'inventory_item_details.php?item_id=' + <?php echo $item_id; ?>;
            }
            return false;
        }

        if (!isValid) {
            showErrorToast('Please fix the form errors');
            // Scroll to first error
            $('.is-invalid').first().focus();
            return false;
        }

        // Show loading state
        $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Saving...').prop('disabled', true);
        
        // Ensure checkboxes have proper values
        $('#is_drug').each(function() {
            if (!$(this).prop('checked')) {
                $(this).prop('checked', false);
                $(this).val('0');
            }
        });
        
        $('#requires_batch').each(function() {
            if (!$(this).prop('checked')) {
                $(this).prop('checked', false);
                $(this).val('0');
            }
        });

        return true;
    }

    // Initialize preview
    updatePropertiesPreview();
});

function checkFormChanges() {
    var currentData = {
        item_name: $('#item_name').val(),
        item_code: $('#item_code').val(),
        category_id: $('#category_id').val(),
        unit_of_measure: $('#unit_of_measure').val(),
        reorder_level: $('#reorder_level').val(),
        is_drug: $('#is_drug').is(':checked'),
        requires_batch: $('#requires_batch').is(':checked'),
        status: $('#status').val(),
        notes: $('#notes').val()
    };

    var originalData = JSON.parse($('#original_data').val());
    
    return JSON.stringify(currentData) !== JSON.stringify({
        item_name: originalData.item_name,
        item_code: originalData.item_code,
        category_id: originalData.category_id.toString(),
        unit_of_measure: originalData.unit_of_measure,
        reorder_level: originalData.reorder_level.toString(),
        is_drug: Boolean(originalData.is_drug),
        requires_batch: Boolean(originalData.requires_batch),
        status: originalData.status,
        notes: originalData.notes
    });
}

function resetForm() {
    if (confirm('Are you sure you want to reset all changes? All unsaved data will be lost.')) {
        var originalData = JSON.parse($('#original_data').val());
        
        $('#item_name').val(originalData.item_name);
        $('#item_code').val(originalData.item_code);
        $('#category_id').val(originalData.category_id).trigger('change');
        $('#unit_of_measure').val(originalData.unit_of_measure);
        $('#reorder_level').val(originalData.reorder_level);
        $('#status').val(originalData.status);
        $('#is_drug').prop('checked', Boolean(originalData.is_drug));
        $('#requires_batch').prop('checked', Boolean(originalData.requires_batch));
        $('#notes').val(originalData.notes);
        
        // Trigger preview updates
        $('#item_name').trigger('input');
        $('#item_code').trigger('input');
        $('#reorder_level').trigger('input');
        $('#status').trigger('change');
        updatePropertiesPreview();
        
        showToast('info', 'Changes have been reset');
    }
}

function revertToOriginal() {
    if (confirm('Revert to original values? This will discard all changes made in this session.')) {
        resetForm();
    }
}

function confirmDelete() {
    if (confirm('Are you sure you want to delete this item?\n\nThis action cannot be undone and will remove:\n• All associated batches\n• All stock records\n• All transaction history\n\nType "DELETE" to confirm:')) {
        var confirmation = prompt('Please type "DELETE" to confirm deletion:');
        if (confirmation === 'DELETE') {
            window.location.href = 'inventory_item_delete.php?item_id=' + <?php echo $item_id; ?>;
        } else {
            showToast('error', 'Deletion cancelled. Incorrect confirmation text.');
        }
    }
}

function validateForm() {
    var isValid = true;
    var warnings = [];
    
    // Check item code uniqueness (simulated - would need AJAX in real implementation)
    var itemCode = $('#item_code').val();
    if (itemCode && itemCode.length < 3) {
        warnings.push('Item code should be at least 3 characters');
    }
    
    // Check if drug item requires batch tracking
    if ($('#is_drug').is(':checked') && !$('#requires_batch').is(':checked')) {
        warnings.push('Drug items should have batch tracking enabled');
    }
    
    // Check for batch tracking changes
    var originalData = JSON.parse($('#original_data').val());
    if (originalData.requires_batch && !$('#requires_batch').is(':checked')) {
        warnings.push('Disabling batch tracking for items with existing batches may cause data issues');
    }
    
    if (warnings.length > 0) {
        showWarningToast('Form warnings:<br>• ' + warnings.join('<br>• '));
    } else {
        showToast('success', 'Form validation passed!');
    }
}

// Toast functions
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

function showWarningToast(message) {
    showToast('warning', message);
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + S to save
    if (e.ctrlKey && e.keyCode === 83) {
        e.preventDefault();
        if (validateFormOnSubmit()) {
            $('#inventoryForm').submit();
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
    // Ctrl + H to revert
    if (e.ctrlKey && e.keyCode === 72) {
        e.preventDefault();
        revertToOriginal();
    }
});

// Initialize toast container if not exists
if ($('.toast-container').length === 0) {
    $('body').append('<div class="toast-container" style="position: fixed; top: 20px; right: 20px; z-index: 1050;"></div>');
}

// Tooltip initialization
$(function () {
    $('[title]').tooltip();
});

// Warn user before leaving if there are unsaved changes
$(window).on('beforeunload', function() {
    if (checkFormChanges()) {
        return 'You have unsaved changes. Are you sure you want to leave?';
    }
});

// Remove warning when form is submitted
$('#inventoryForm').on('submit', function() {
    $(window).off('beforeunload');
});
</script>

<style>
.form-required:after {
    content: " *";
    color: #dc3545;
}

.is-invalid {
    border-color: #dc3545;
    box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25);
}

.invalid-feedback {
    display: block;
    width: 100%;
    margin-top: 0.25rem;
    font-size: 80%;
    color: #dc3545;
}

.select2-container .select2-selection--single {
    height: 38px;
}

.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 36px;
}

.btn:disabled {
    cursor: not-allowed;
    opacity: 0.6;
}

.info-box {
    box-shadow: 0 0 1px rgba(0,0,0,.125), 0 1px 3px rgba(0,0,0,.2);
    border-radius: .25rem;
    background: #fff;
    display: -ms-flexbox;
    display: flex;
    margin-bottom: 1rem;
    min-height: 80px;
    padding: .5rem;
    position: relative;
}

.info-box .info-box-icon {
    border-radius: .25rem;
    -ms-flex-align: center;
    align-items: center;
    display: -ms-flexbox;
    display: flex;
    font-size: 1.875rem;
    -ms-flex-pack: center;
    justify-content: center;
    text-align: center;
    width: 70px;
}

.info-box .info-box-content {
    -ms-flex: 1;
    flex: 1;
    padding: 5px 10px;
}

.info-box .info-box-number {
    display: block;
    font-weight: 700;
    font-size: 1.5rem;
}

.info-box .info-box-text {
    display: block;
    font-size: .875rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.info-box-container {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
}

.info-box-container .info-box {
    flex: 1;
    min-width: 200px;
    margin: 0;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
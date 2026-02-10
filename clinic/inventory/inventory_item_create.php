<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Initialize variables
$categories = [];
$suppliers = [];
$locations = [];

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
    
    // FIX: Use isset() to check if checkbox is checked
    $is_drug = isset($_POST['is_drug']) && $_POST['is_drug'] == '1' ? 1 : 0;
    $requires_batch = isset($_POST['requires_batch']) && $_POST['requires_batch'] == '1' ? 1 : 0;
    
    // FIX: Get status from form and ensure it's not empty
    $status = !empty($_POST['status']) ? sanitizeInput($_POST['status']) : 'active';
    
    $notes = sanitizeInput($_POST['notes']);

    // Debug - log the values (remove in production)
    error_log("DEBUG - is_drug: $is_drug, requires_batch: $requires_batch, status: $status");

    // Validate CSRF token
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        header("Location: inventory_item_create.php");
        exit;
    }

    // Validate required fields
    if (empty($item_name) || empty($item_code)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please fill in all required fields.";
        header("Location: inventory_item_create.php");
        exit;
    }

    // Check if item code already exists
    $check_sql = "SELECT item_id FROM inventory_items WHERE item_code = ?";
    $check_stmt = $mysqli->prepare($check_sql);
    $check_stmt->bind_param("s", $item_code);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($check_result->num_rows > 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Item code already exists. Please use a unique item code.";
        header("Location: inventory_item_create.php");
        exit;
    }

    // Start transaction for item creation
    $mysqli->begin_transaction();

    try {
        // Insert new item
        $insert_sql = "INSERT INTO inventory_items (
            item_name, item_code, category_id, unit_of_measure, 
            is_drug, requires_batch, reorder_level, status, notes, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $insert_stmt = $mysqli->prepare($insert_sql);
        
        // FIX: Corrected bind_param data types
        // s = string, i = integer, d = double (float), s = string
        $insert_stmt->bind_param(
            "ssisiidssi",  // FIXED: Changed last 's' to 'i' for integer
            $item_name,         // string
            $item_code,         // string
            $category_id,       // integer
            $unit_of_measure,   // string
            $is_drug,           // integer
            $requires_batch,    // integer
            $reorder_level,     // double/float
            $status,            // string
            $notes,             // string
            $session_user_id    // integer
        );

        if (!$insert_stmt->execute()) {
            throw new Exception("Failed to create item: " . $insert_stmt->error);
        }
        
        $new_item_id = $insert_stmt->insert_id;
        $insert_stmt->close();

        // Log the action
        $log_sql = "INSERT INTO logs SET
                  log_type = 'Inventory',
                  log_action = 'Create',
                  log_description = ?,
                  log_ip = ?,
                  log_user_agent = ?,
                  log_user_id = ?,
                  log_entity_id = ?,
                  log_created_at = NOW()";
        $log_stmt = $mysqli->prepare($log_sql);
        $log_description = "Created new inventory item: " . $item_name . " (Code: " . $item_code . ")";
        $log_stmt->bind_param("sssii", $log_description, $session_ip, $session_user_agent, $session_user_id, $new_item_id);
        $log_stmt->execute();
        $log_stmt->close();

        $mysqli->commit();

        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Item <strong>$item_name</strong> added successfully!";
        $_SESSION['new_item_id'] = $new_item_id;
        
        // Redirect based on item type
        if ($requires_batch) {
            header("Location: inventory_batch_create.php?item_id=" . $new_item_id);
        } else {
            header("Location: inventory_item_details.php?item_id=" . $new_item_id);
        }
        exit;

    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error adding item: " . $e->getMessage();
        header("Location: inventory_item_create.php");
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
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h3 class="card-title mt-2 mb-0 text-white">
                    <i class="fas fa-fw fa-plus-circle mr-2"></i>Add New Inventory Item
                </h3>
            </div>
            <div class="card-tools">
                <a href="inventory_items.php" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Inventory
                </a>
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
        <!-- Quick Actions Row -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="btn-toolbar justify-content-between">
                    <div class="btn-group">
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Form Status:</strong> 
                            <span class="badge badge-success ml-2">Ready</span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Fields:</strong> 
                            <span class="badge badge-primary ml-2">2 required</span>
                        </span>
                    </div>
                    <div class="btn-group">
                        <button type="submit" form="inventoryForm" class="btn btn-success">
                            <i class="fas fa-save mr-2"></i>Add Item
                        </button>
                        <button type="reset" form="inventoryForm" class="btn btn-outline-secondary" onclick="resetForm()">
                            <i class="fas fa-undo mr-2"></i>Reset
                        </button>
                        <div class="dropdown">
                            <button class="btn btn-secondary dropdown-toggle" type="button" data-toggle="dropdown">
                                <i class="fas fa-cog mr-2"></i>Actions
                            </button>
                            <div class="dropdown-menu">
                                <a class="dropdown-item" href="#" onclick="fillSampleData()">
                                    <i class="fas fa-magic mr-2"></i>Fill Sample Data
                                </a>
                                <a class="dropdown-item" href="#" onclick="validateForm()">
                                    <i class="fas fa-check-circle mr-2"></i>Validate Form
                                </a>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item" href="inventory_import.php">
                                    <i class="fas fa-file-import mr-2"></i>Import Items
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
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="item_name" class="form-required">Item Name</label>
                                        <input type="text" class="form-control" id="item_name" name="item_name" 
                                               placeholder="Enter item name" required maxlength="200">
                                        <small class="form-text text-muted">Descriptive name for the item</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="item_code" class="form-required">Item Code</label>
                                        <input type="text" class="form-control" id="item_code" name="item_code" 
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
                                                <option value="<?php echo $category['category_id']; ?>">
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
                                            <option value="pcs">Pieces</option>
                                            <option value="box">Box</option>
                                            <option value="pack">Pack</option>
                                            <option value="bottle">Bottle</option>
                                            <option value="roll">Roll</option>
                                            <option value="set">Set</option>
                                            <option value="unit">Unit</option>
                                            <option value="ml">Milliliter</option>
                                            <option value="mg">Milligram</option>
                                            <option value="tablet">Tablet</option>
                                            <option value="vial">Vial</option>
                                            <option value="tube">Tube</option>
                                            <option value="kit">Kit</option>
                                            <option value="pair">Pair</option>
                                            <option value="meter">Meter</option>
                                            <option value="liter">Liter</option>
                                            <option value="carton">Carton</option>
                                            <option value="case">Case</option>
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
                                               name="reorder_level" min="0" step="0.001" value="0">
                                        <small class="form-text text-muted">System will alert when stock reaches this level</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="status">Status</label>
                                        <select class="form-control" id="status" name="status" required>
                                            <option value="active" selected>Active</option>
                                            <option value="inactive">Inactive</option>
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
                                    <input type="checkbox" class="form-check-input" id="is_drug" name="is_drug" value="1">
                                    <label class="form-check-label" for="is_drug">
                                        <i class="fas fa-pills text-danger mr-2"></i>This is a drug/pharmaceutical item
                                    </label>
                                    <small class="form-text text-muted">Check for medications and pharmaceuticals</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check mb-3">
                                    <input type="checkbox" class="form-check-input" id="requires_batch" name="requires_batch" value="1" checked>
                                    <label class="form-check-label" for="requires_batch">
                                        <i class="fas fa-layer-group text-info mr-2"></i>Requires batch/lot tracking
                                    </label>
                                    <small class="form-text text-muted">Check for items with batch numbers and expiry dates</small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle mr-2"></i>
                            <strong>Note:</strong> If batch tracking is enabled, you'll need to add batches and stock after creating this item.
                        </div>
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
                                      placeholder="Additional notes, special handling instructions, storage requirements, etc..." maxlength="1000"></textarea>
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
                                <i class="fas fa-save mr-2"></i>Add Item
                            </button>
                            <button type="reset" form="inventoryForm" class="btn btn-outline-secondary" onclick="resetForm()">
                                <i class="fas fa-undo mr-2"></i>Reset Form
                            </button>
                            <a href="inventory_items.php" class="btn btn-outline-secondary">
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
                                <kbd>Ctrl</kbd> + <kbd>D</kbd> Sample Data
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
                            <h5 id="preview_item_name" class="mb-1">New Item</h5>
                            <div id="preview_item_code" class="text-muted small">Code: ---</div>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <th width="40%" class="text-muted">Category:</th>
                                    <td id="preview_category" class="text-right">-</td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Unit:</th>
                                    <td id="preview_unit" class="text-right">-</td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Reorder Level:</th>
                                    <td id="preview_reorder" class="text-right">0</td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Status:</th>
                                    <td id="preview_status" class="text-right">
                                        <span class="badge badge-success">Active</span>
                                    </td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Properties:</th>
                                    <td id="preview_properties" class="text-right">
                                        <span class="badge badge-info">Batch Tracked</span>
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
                            <strong>Batch Tracking:</strong> Enable for items with expiry dates or batch numbers (drugs, perishables).
                        </div>
                        <div class="alert alert-warning small mb-3">
                            <i class="fas fa-exclamation-triangle mr-2"></i>
                            <strong>Reorder Levels:</strong> Set based on usage patterns and supplier lead times.
                        </div>
                        <div class="alert alert-success small mb-3">
                            <i class="fas fa-check-circle mr-2"></i>
                            <strong>Unit Consistency:</strong> Use consistent units across similar items for accurate reporting.
                        </div>
                        <div class="alert alert-primary small">
                            <i class="fas fa-clipboard-list mr-2"></i>
                            <strong>Next Steps:</strong> After creating item, add batches and stock in locations.
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

    // Update preview in real-time
    $('#item_name').on('input', function() {
        $('#preview_item_name').text($(this).val() || 'New Item');
    });

    $('#item_code').on('input', function() {
        $('#preview_item_code').text('Code: ' + ($(this).val() || '---'));
    });

    $('#category_id').on('change', function() {
        var selectedText = $(this).find('option:selected').text();
        $('#preview_category').text(selectedText.split(' - ')[1] || '-');
    });

    $('#unit_of_measure').on('change', function() {
        $('#preview_unit').text($(this).val() || '-');
    });

    $('#reorder_level').on('input', function() {
        $('#preview_reorder').text($(this).val() || '0');
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

        if (!isValid) {
            showErrorToast('Please fix the form errors');
            // Scroll to first error
            $('.is-invalid').first().focus();
            return false;
        }

        // Show loading state
        $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Adding...').prop('disabled', true);
        
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

function resetForm() {
    if (confirm('Are you sure you want to reset all changes? All entered data will be lost.')) {
        $('#inventoryForm')[0].reset();
        $('.select2').val('').trigger('change');
        updatePropertiesPreview();
        showToast('info', 'Form has been reset');
    }
}

function fillSampleData() {
    if (confirm('Fill form with sample data? This will overwrite current values.')) {
        $('#item_name').val('Paracetamol 500mg Tablets');
        $('#item_code').val('PARA-' + Math.floor(Math.random() * 1000));
        $('#category_id').val('2').trigger('change'); // Assuming 2 is Pharmacy category
        $('#unit_of_measure').val('tablet');
        $('#reorder_level').val(1000);
        $('#status').val('active');
        $('#is_drug').prop('checked', true);
        $('#requires_batch').prop('checked', true);
        $('#notes').val('For fever and pain relief. Store in cool, dry place.');
        
        // Trigger preview updates
        $('#item_name').trigger('input');
        $('#item_code').trigger('input');
        $('#reorder_level').trigger('input');
        $('#status').trigger('change');
        updatePropertiesPreview();
        
        showToast('info', 'Sample data loaded');
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
        window.location.href = 'inventory_items.php';
    }
    // Ctrl + R to reset
    if (e.ctrlKey && e.keyCode === 82) {
        e.preventDefault();
        resetForm();
    }
    // Ctrl + D for sample data
    if (e.ctrlKey && e.keyCode === 68) {
        e.preventDefault();
        fillSampleData();
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
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
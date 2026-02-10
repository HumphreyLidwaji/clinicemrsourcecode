<?php
// asset_new.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Get categories, locations, and users for dropdowns
$categories_sql = "SELECT * FROM asset_categories WHERE is_active = 1 ORDER BY category_name";
$categories_result = $mysqli->query($categories_sql);

$locations_sql = "SELECT * FROM asset_locations WHERE is_active = 1 ORDER BY location_name";
$locations_result = $mysqli->query($locations_sql);

$users_sql = "SELECT user_id, user_name FROM users WHERE user_status = 1 ORDER BY user_name";
$users_result = $mysqli->query($users_sql);

$suppliers_sql = "SELECT supplier_id, supplier_name FROM suppliers WHERE supplier_is_active = 1 ORDER BY supplier_name";
$suppliers_result = $mysqli->query($suppliers_sql);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token.";
        header("Location: asset_new.php");
        exit;
    }
    
    // Collect form data
    $asset_tag = sanitizeInput($_POST['asset_tag']);
    $asset_name = sanitizeInput($_POST['asset_name']);
    $asset_description = sanitizeInput($_POST['asset_description']);
    $category_id = intval($_POST['category_id']);
    $serial_number = sanitizeInput($_POST['serial_number']);
    $model = sanitizeInput($_POST['model']);
    $manufacturer = sanitizeInput($_POST['manufacturer']);
    $purchase_date = sanitizeInput($_POST['purchase_date']);
    $purchase_price = floatval($_POST['purchase_price']);
    $warranty_expiry = sanitizeInput($_POST['warranty_expiry']);
    $supplier_id = intval($_POST['supplier_id']);
    $location_id = intval($_POST['location_id']);
    $status = sanitizeInput($_POST['status']);
    $condition = sanitizeInput($_POST['condition']);
    $assigned_to = intval($_POST['assigned_to']);
    $assigned_department = sanitizeInput($_POST['assigned_department']);
    $is_critical = isset($_POST['is_critical']) ? 1 : 0;
    $notes = sanitizeInput($_POST['notes']);
    
    // Generate asset tag if not provided
    if (empty($asset_tag)) {
        $asset_tag = "AST-" . date('Ymd') . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
    }
    
    // Check if asset tag already exists
    $check_sql = "SELECT asset_id FROM assets WHERE asset_tag = ?";
    $check_stmt = $mysqli->prepare($check_sql);
    $check_stmt->bind_param("s", $asset_tag);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Asset tag already exists. Please use a different tag.";
        header("Location: asset_new.php");
        exit;
    }
    
    // Calculate current value (same as purchase price for new assets)
    $current_value = $purchase_price;
    
    // Insert new asset
    $insert_sql = "INSERT INTO assets (
        asset_tag, asset_name, asset_description, category_id,
        serial_number, model, manufacturer, purchase_date,
        purchase_price, current_value, warranty_expiry, supplier_id,
        location_id, status, condition, assigned_to, assigned_department,
        is_critical, notes, created_by
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $insert_stmt = $mysqli->prepare($insert_sql);
    $insert_stmt->bind_param(
        "sssisssssdisiississi",
        $asset_tag,
        $asset_name,
        $asset_description,
        $category_id,
        $serial_number,
        $model,
        $manufacturer,
        $purchase_date,
        $purchase_price,
        $current_value,
        $warranty_expiry,
        $supplier_id,
        $location_id,
        $status,
        $condition,
        $assigned_to,
        $assigned_department,
        $is_critical,
        $notes,
        $session_user_id
    );
    
    if ($insert_stmt->execute()) {
        $new_asset_id = $insert_stmt->insert_id;
        
        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Asset added successfully!";
        header("Location: asset_view.php?id=" . $new_asset_id);
        exit;
    } else {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error adding asset: " . $mysqli->error;
        header("Location: asset_new.php");
        exit;
    }
}
?>

<div class="content-wrapper">
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="m-0 text-dark">
                        <i class="fas fa-plus-circle mr-2"></i>Add New Asset
                    </h1>
                </div>
                <div class="col-sm-6">
                    <div class="float-right">
                        <a href="asset_management.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left mr-2"></i>Back to Assets
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <section class="content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-md-8">
                    <div class="card card-primary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-info-circle mr-2"></i>Asset Information</h3>
                        </div>
                        <div class="card-body">
                            <form method="POST" id="assetForm" autocomplete="off">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="asset_tag">Asset Tag *</label>
                                            <input type="text" class="form-control" id="asset_tag" name="asset_tag" 
                                                   placeholder="Auto-generated if left blank">
                                            <small class="form-text text-muted">Unique identifier for this asset</small>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="asset_name">Asset Name *</label>
                                            <input type="text" class="form-control" id="asset_name" name="asset_name" required>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label for="asset_description">Description</label>
                                    <textarea class="form-control" id="asset_description" name="asset_description" rows="3"></textarea>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="category_id">Category *</label>
                                            <select class="form-control select2" id="category_id" name="category_id" required>
                                                <option value="">- Select Category -</option>
                                                <?php while($category = $categories_result->fetch_assoc()): ?>
                                                    <option value="<?php echo $category['category_id']; ?>">
                                                        <?php echo htmlspecialchars($category['category_name']); ?>
                                                    </option>
                                                <?php endwhile; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="location_id">Location</label>
                                            <select class="form-control select2" id="location_id" name="location_id">
                                                <option value="">- Select Location -</option>
                                                <?php while($location = $locations_result->fetch_assoc()): ?>
                                                    <option value="<?php echo $location['location_id']; ?>">
                                                        <?php echo htmlspecialchars($location['location_name']); ?>
                                                    </option>
                                                <?php endwhile; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                
                                <h5 class="mt-4 mb-3">Technical Details</h5>
                                
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label for="serial_number">Serial Number</label>
                                            <input type="text" class="form-control" id="serial_number" name="serial_number">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label for="model">Model</label>
                                            <input type="text" class="form-control" id="model" name="model">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label for="manufacturer">Manufacturer</label>
                                            <input type="text" class="form-control" id="manufacturer" name="manufacturer">
                                        </div>
                                    </div>
                                </div>
                                
                                <h5 class="mt-4 mb-3">Purchase Information</h5>
                                
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label for="purchase_date">Purchase Date</label>
                                            <input type="date" class="form-control" id="purchase_date" name="purchase_date">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label for="purchase_price">Purchase Price ($)</label>
                                            <input type="number" step="0.01" class="form-control" id="purchase_price" name="purchase_price" value="0">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label for="supplier_id">Supplier</label>
                                            <select class="form-control select2" id="supplier_id" name="supplier_id">
                                                <option value="">- Select Supplier -</option>
                                                <?php while($supplier = $suppliers_result->fetch_assoc()): ?>
                                                    <option value="<?php echo $supplier['supplier_id']; ?>">
                                                        <?php echo htmlspecialchars($supplier['supplier_name']); ?>
                                                    </option>
                                                <?php endwhile; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="warranty_expiry">Warranty Expiry</label>
                                            <input type="date" class="form-control" id="warranty_expiry" name="warranty_expiry">
                                        </div>
                                    </div>
                                </div>
                                
                                <h5 class="mt-4 mb-3">Assignment & Status</h5>
                                
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label for="status">Status</label>
                                            <select class="form-control" id="status" name="status">
                                                <option value="active" selected>Active</option>
                                                <option value="inactive">Inactive</option>
                                                <option value="under_maintenance">Under Maintenance</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label for="condition">Condition</label>
                                            <select class="form-control" id="condition" name="condition">
                                                <option value="good" selected>Good</option>
                                                <option value="excellent">Excellent</option>
                                                <option value="fair">Fair</option>
                                                <option value="poor">Poor</option>
                                                <option value="critical">Critical</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label for="assigned_to">Assigned To</label>
                                            <select class="form-control select2" id="assigned_to" name="assigned_to">
                                                <option value="">- Unassigned -</option>
                                                <?php while($user = $users_result->fetch_assoc()): ?>
                                                    <option value="<?php echo $user['user_id']; ?>">
                                                        <?php echo htmlspecialchars($user['user_name']); ?>
                                                    </option>
                                                <?php endwhile; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="assigned_department">Assigned Department</label>
                                            <input type="text" class="form-control" id="assigned_department" name="assigned_department">
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <div class="form-check">
                                        <input type="checkbox" class="form-check-input" id="is_critical" name="is_critical">
                                        <label class="form-check-label" for="is_critical">
                                            <i class="fas fa-exclamation-triangle text-danger mr-1"></i>
                                            Mark as Critical Asset
                                        </label>
                                        <small class="form-text text-muted">Critical assets require special attention and monitoring</small>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label for="notes">Additional Notes</label>
                                    <textarea class="form-control" id="notes" name="notes" rows="3"></textarea>
                                </div>
                                
                                <div class="form-group mt-4">
                                    <button type="submit" class="btn btn-success btn-lg">
                                        <i class="fas fa-save mr-2"></i>Save Asset
                                    </button>
                                    <button type="reset" class="btn btn-secondary">
                                        <i class="fas fa-undo mr-2"></i>Reset Form
                                    </button>
                                    <a href="asset_management.php" class="btn btn-danger">
                                        <i class="fas fa-times mr-2"></i>Cancel
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <!-- Quick Help -->
                    <div class="card card-info">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-question-circle mr-2"></i>Quick Help</h3>
                        </div>
                        <div class="card-body">
                            <h6>Asset Tag</h6>
                            <p class="small">Unique identifier for tracking. Leave blank to auto-generate.</p>
                            
                            <h6>Categories</h6>
                            <p class="small">Group similar assets for better organization and reporting.</p>
                            
                            <h6>Critical Assets</h6>
                            <p class="small">Mark assets that are essential for operations or require special monitoring.</p>
                            
                            <h6>Purchase Information</h6>
                            <p class="small">Helps with depreciation calculation and warranty tracking.</p>
                            
                            <h6>Suppliers</h6>
                            <p class="small">Track where assets were purchased for warranty and support purposes.</p>
                        </div>
                    </div>
                    
                    <!-- Asset Preview -->
                    <div class="card card-success mt-4">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-eye mr-2"></i>Asset Preview</h3>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-3">
                                <i class="fas fa-cube fa-3x text-success mb-2"></i>
                                <h5 id="preview_name">Asset Name</h5>
                                <div id="preview_tag" class="text-muted">Asset Tag</div>
                            </div>
                            <hr>
                            <div class="small">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Category:</span>
                                    <span id="preview_category" class="font-weight-bold">-</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Location:</span>
                                    <span id="preview_location" class="font-weight-bold">-</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Supplier:</span>
                                    <span id="preview_supplier" class="font-weight-bold">-</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Status:</span>
                                    <span id="preview_status" class="font-weight-bold">-</span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Value:</span>
                                    <span id="preview_value" class="font-weight-bold">$0.00</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<script>
$(document).ready(function() {
    $('.select2').select2();
    
    // Auto-generate asset tag on focus out if empty
    $('#asset_name').on('blur', function() {
        if (!$('#asset_tag').val()) {
            var name = $(this).val().replace(/[^a-z0-9]/gi, '').substring(0, 3).toUpperCase();
            var date = new Date().toISOString().slice(2, 10).replace(/-/g, '');
            var random = Math.floor(Math.random() * 900) + 100;
            $('#asset_tag').val('AST-' + date + '-' + name + random);
        }
    });
    
    // Update preview in real-time
    $('#asset_name').on('input', function() {
        $('#preview_name').text($(this).val() || 'Asset Name');
    });
    
    $('#asset_tag').on('input', function() {
        $('#preview_tag').text($(this).val() || 'Asset Tag');
    });
    
    $('#category_id').on('change', function() {
        var selectedText = $(this).find('option:selected').text();
        $('#preview_category').text(selectedText);
    });
    
    $('#location_id').on('change', function() {
        var selectedText = $(this).find('option:selected').text();
        $('#preview_location').text(selectedText);
    });
    
    $('#supplier_id').on('change', function() {
        var selectedText = $(this).find('option:selected').text();
        $('#preview_supplier').text(selectedText);
    });
    
    $('#status').on('change', function() {
        $('#preview_status').text($(this).val());
    });
    
    $('#purchase_price').on('input', function() {
        var value = parseFloat($(this).val()) || 0;
        $('#preview_value').text('$' + value.toFixed(2));
    });
    
    // Form validation
    $('#assetForm').on('submit', function(e) {
        var requiredFields = ['asset_name', 'category_id'];
        var isValid = true;
        
        requiredFields.forEach(function(field) {
            if (!$('#' + field).val()) {
                isValid = false;
                $('#' + field).addClass('is-invalid');
            } else {
                $('#' + field).removeClass('is-invalid');
            }
        });
        
        if (!isValid) {
            e.preventDefault();
            alert('Please fill in all required fields.');
            return false;
        }
        
        // Show loading state
        $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Saving...').prop('disabled', true);
    });
});

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + S to save
    if (e.ctrlKey && e.keyCode === 83) {
        e.preventDefault();
        $('#assetForm').submit();
    }
    // Escape to cancel
    if (e.keyCode === 27) {
        window.location.href = 'asset_management.php';
    }
});
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
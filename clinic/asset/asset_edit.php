<?php
// asset_edit.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';


// Get asset ID
$asset_id = intval($_GET['id'] ?? 0);
if ($asset_id <= 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid asset ID.";
    header("Location: asset_management.php");
    exit;
}

// Get current asset data with enhanced information
$asset_sql = "
    SELECT a.*, 
           ac.category_name, ac.depreciation_rate, ac.useful_life_years,
           al.location_name, al.location_type,
           creator.user_name as created_by_name,
           updater.user_name as updated_by_name,
           s.supplier_name,
           u.user_name as assigned_user_name
    FROM assets a
    LEFT JOIN asset_categories ac ON a.category_id = ac.category_id
    LEFT JOIN asset_locations al ON a.location_id = al.location_id
    LEFT JOIN users creator ON a.created_by = creator.user_id
    LEFT JOIN users updater ON a.updated_by = updater.user_id
    LEFT JOIN suppliers s ON a.supplier_id = s.supplier_id
    LEFT JOIN users u ON a.assigned_to = u.user_id
    WHERE a.asset_id = ? ";
$asset_stmt = $mysqli->prepare($asset_sql);
$asset_stmt->bind_param("i", $asset_id);
$asset_stmt->execute();
$asset_result = $asset_stmt->get_result();
$asset = $asset_result->fetch_assoc();

if (!$asset) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Asset not found or has been archived.";
    header("Location: asset_management.php");
    exit;
}

// Get categories, locations, and users for dropdowns
$categories_sql = "SELECT * FROM asset_categories WHERE is_active = 1 ORDER BY category_name";
$categories_result = $mysqli->query($categories_sql);
$categories = [];
while($category = $categories_result->fetch_assoc()) {
    $categories[] = $category;
}

$locations_sql = "SELECT * FROM asset_locations WHERE is_active = 1 ORDER BY location_type, location_name";
$locations_result = $mysqli->query($locations_sql);
$locations = [];
while($location = $locations_result->fetch_assoc()) {
    $locations[] = $location;
}

$users_sql = "SELECT user_id, user_name FROM users";
$users_result = $mysqli->query($users_sql);
$users = [];
while($user = $users_result->fetch_assoc()) {
    $users[] = $user;
}

$suppliers_sql = "SELECT supplier_id, supplier_name FROM suppliers WHERE supplier_is_active = 1 ORDER BY supplier_name";
$suppliers_result = $mysqli->query($suppliers_sql);
$suppliers = [];
while($supplier = $suppliers_result->fetch_assoc()) {
    $suppliers[] = $supplier;
}

// Get current user's information
$current_user_sql = "SELECT user_name, user_email FROM users WHERE user_id = ?";
$current_user_stmt = $mysqli->prepare($current_user_sql);
$current_user_stmt->bind_param("i", $session_user_id);
$current_user_stmt->execute();
$current_user_result = $current_user_stmt->get_result();
$current_user = $current_user_result->fetch_assoc();

// Get asset statistics
$asset_stats_sql = "
    SELECT 
        COUNT(*) as total_assets,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_assets,
        SUM(CASE WHEN status = 'under_maintenance' THEN 1 ELSE 0 END) as maintenance_assets,
        COALESCE(SUM(purchase_price), 0) as total_purchase_value,
        COALESCE(SUM(current_value), 0) as total_current_value,
        (SELECT COUNT(*) FROM asset_maintenance WHERE asset_id = ?) as maintenance_count,
        (SELECT COUNT(*) FROM asset_checkout_logs WHERE asset_id = ? AND checkin_date IS NULL) as checked_out_count
    FROM assets 
   
";
$stats_stmt = $mysqli->prepare($asset_stats_sql);
$stats_stmt->bind_param("ii", $asset_id, $asset_id);
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();
$asset_stats = $stats_result->fetch_assoc();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token.";
        header("Location: asset_edit.php?id=" . $asset_id);
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
    $current_value = floatval($_POST['current_value']);
    $warranty_expiry = sanitizeInput($_POST['warranty_expiry']);
    $supplier_id = intval($_POST['supplier_id']);
    $location_id = intval($_POST['location_id']);
    $status = sanitizeInput($_POST['status']);
    $asset_condition = sanitizeInput($_POST['asset_condition']);
    $assigned_to = intval($_POST['assigned_to']);
    $assigned_department = sanitizeInput($_POST['assigned_department']);
    $is_critical = isset($_POST['is_critical']) ? 1 : 0;
    $next_maintenance_date = sanitizeInput($_POST['next_maintenance_date']);
    $notes = sanitizeInput($_POST['notes']);
    $updated_by = $session_user_id;
    
    // Check if asset tag already exists (excluding current asset)
    $check_sql = "SELECT asset_id FROM assets WHERE asset_tag = ? AND asset_id != ?";
    $check_stmt = $mysqli->prepare($check_sql);
    $check_stmt->bind_param("si", $asset_tag, $asset_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Asset tag already exists. Please use a different tag.";
        header("Location: asset_edit.php?id=" . $asset_id);
        exit;
    }
    
    // Validate dates
    $purchaseDate = $purchase_date ? new DateTime($purchase_date) : null;
    $warrantyExpiry = $warranty_expiry ? new DateTime($warranty_expiry) : null;
    $nextMaintenance = $next_maintenance_date ? new DateTime($next_maintenance_date) : null;
    
    if ($purchaseDate && $purchaseDate > new DateTime()) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Purchase date cannot be in the future.";
        header("Location: asset_edit.php?id=" . $asset_id);
        exit;
    }
    
    if ($warrantyExpiry && $purchaseDate && $warrantyExpiry < $purchaseDate) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Warranty expiry cannot be before purchase date.";
        header("Location: asset_edit.php?id=" . $asset_id);
        exit;
    }
    
    // Update asset
    $update_sql = "UPDATE assets SET
        asset_tag = ?,
        asset_name = ?,
        asset_description = ?,
        category_id = ?,
        serial_number = ?,
        model = ?,
        manufacturer = ?,
        purchase_date = ?,
        purchase_price = ?,
        current_value = ?,
        warranty_expiry = ?,
        supplier_id = ?,
        location_id = ?,
        status = ?,
        asset_condition = ?,
        assigned_to = ?,
        assigned_department = ?,
        is_critical = ?,
        next_maintenance_date = ?,
        notes = ?,
        updated_by = ?,
        updated_at = NOW()
    WHERE asset_id = ?";
    
    $update_stmt = $mysqli->prepare($update_sql);
    $update_stmt->bind_param(
        "sssisssssdssiississsii",
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
        $asset_condition,
        $assigned_to,
        $assigned_department,
        $is_critical,
        $next_maintenance_date,
        $notes,
        $updated_by,
        $asset_id
    );
    
    if ($update_stmt->execute()) {
        // Log activity
        $log_description = "Asset updated: {$asset_tag} - {$asset_name}";
        mysqli_query($mysqli, "INSERT INTO logs SET log_type = 'Asset', log_action = 'Update', log_description = '$log_description', log_ip = '$session_ip', log_user_agent = '$session_user_agent', log_user_id = $session_user_id");
        
        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Asset <strong>$asset_name</strong> updated successfully!";
        header("Location: asset_view.php?id=" . $asset_id);
        exit;
    } else {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error updating asset: " . $mysqli->error;
        header("Location: asset_edit.php?id=" . $asset_id);
        exit;
    }
}
?>

<div class="card">
    <div class="card-header bg-warning py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0 text-dark">
                <i class="fas fa-fw fa-edit mr-2"></i>Edit Asset: <?php echo htmlspecialchars($asset['asset_tag']); ?>
            </h3>
            <div class="btn-group">
                <a href="asset_view.php?id=<?php echo $asset_id; ?>" class="btn btn-info">
                    <i class="fas fa-eye mr-2"></i>View Asset
                </a>
                <a href="asset_management.php" class="btn btn-light ml-2">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Assets
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
                <?php 
                unset($_SESSION['alert_type']);
                unset($_SESSION['alert_message']);
                ?>
            </div>
        <?php endif; ?>

        <form method="POST" id="assetForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <div class="row">
                <div class="col-md-8">
                    <!-- Basic Information -->
                    <div class="card card-primary">
                        <div class="card-header">
                            <h4 class="card-title"><i class="fas fa-info-circle mr-2"></i>Basic Information</h4>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="asset_tag">Asset Tag *</label>
                                        <input type="text" class="form-control" id="asset_tag" name="asset_tag" 
                                               value="<?php echo htmlspecialchars($asset['asset_tag']); ?>" required>
                                        <small class="form-text text-muted">Unique identifier for this asset</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="asset_name">Asset Name *</label>
                                        <input type="text" class="form-control" id="asset_name" name="asset_name" 
                                               value="<?php echo htmlspecialchars($asset['asset_name']); ?>" required>
                                        <small class="form-text text-muted">Descriptive name for the asset</small>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="asset_description">Description</label>
                                <textarea class="form-control" id="asset_description" name="asset_description" rows="3"><?php echo htmlspecialchars($asset['asset_description']); ?></textarea>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="category_id">Category *</label>
                                        <select class="form-control select2" id="category_id" name="category_id" required>
                                            <option value="">- Select Category -</option>
                                            <?php foreach ($categories as $category): ?>
                                                <option value="<?php echo $category['category_id']; ?>" 
                                                    <?php echo $asset['category_id'] == $category['category_id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($category['category_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="location_id">Location</label>
                                        <select class="form-control select2" id="location_id" name="location_id">
                                            <option value="">- Select Location -</option>
                                            <?php foreach ($locations as $location): ?>
                                                <option value="<?php echo $location['location_id']; ?>" 
                                                    <?php echo $asset['location_id'] == $location['location_id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($location['location_type'] . ' - ' . $location['location_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Technical Details -->
                    <div class="card card-info">
                        <div class="card-header">
                            <h4 class="card-title"><i class="fas fa-microchip mr-2"></i>Technical Details</h4>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="serial_number">Serial Number</label>
                                        <input type="text" class="form-control" id="serial_number" name="serial_number" 
                                               value="<?php echo htmlspecialchars($asset['serial_number']); ?>">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="model">Model</label>
                                        <input type="text" class="form-control" id="model" name="model" 
                                               value="<?php echo htmlspecialchars($asset['model']); ?>">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="manufacturer">Manufacturer</label>
                                        <input type="text" class="form-control" id="manufacturer" name="manufacturer" 
                                               value="<?php echo htmlspecialchars($asset['manufacturer']); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Purchase & Financial Information -->
                    <div class="card card-success">
                        <div class="card-header">
                            <h4 class="card-title"><i class="fas fa-shopping-cart mr-2"></i>Purchase & Financial Information</h4>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="purchase_date">Purchase Date</label>
                                        <input type="date" class="form-control" id="purchase_date" name="purchase_date" 
                                               value="<?php echo $asset['purchase_date']; ?>">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="purchase_price">Purchase Price ($)</label>
                                        <input type="number" step="0.01" class="form-control" id="purchase_price" name="purchase_price" 
                                               value="<?php echo number_format($asset['purchase_price'], 2); ?>">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="current_value">Current Value ($)</label>
                                        <input type="number" step="0.01" class="form-control" id="current_value" name="current_value" 
                                               value="<?php echo number_format($asset['current_value'], 2); ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="warranty_expiry">Warranty Expiry</label>
                                        <input type="date" class="form-control" id="warranty_expiry" name="warranty_expiry" 
                                               value="<?php echo $asset['warranty_expiry']; ?>">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="supplier_id">Supplier</label>
                                        <select class="form-control select2" id="supplier_id" name="supplier_id">
                                            <option value="">- Select Supplier -</option>
                                            <?php foreach ($suppliers as $supplier): ?>
                                                <option value="<?php echo $supplier['supplier_id']; ?>" 
                                                    <?php echo $asset['supplier_id'] == $supplier['supplier_id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($supplier['supplier_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Assignment & Status -->
                    <div class="card card-warning">
                        <div class="card-header">
                            <h4 class="card-title"><i class="fas fa-user-check mr-2"></i>Assignment & Status</h4>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="status">Status</label>
                                        <select class="form-control" id="status" name="status" required>
                                            <option value="active" <?php echo $asset['status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                                            <option value="inactive" <?php echo $asset['status'] == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                            <option value="under_maintenance" <?php echo $asset['status'] == 'under_maintenance' ? 'selected' : ''; ?>>Under Maintenance</option>
                                            <option value="checked_out" <?php echo $asset['status'] == 'checked_out' ? 'selected' : ''; ?>>Checked Out</option>
                                            <option value="disposed" <?php echo $asset['status'] == 'disposed' ? 'selected' : ''; ?>>Disposed</option>
                                            <option value="lost" <?php echo $asset['status'] == 'lost' ? 'selected' : ''; ?>>Lost</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="asset_condition">Condition</label>
                                        <select class="form-control" id="asset_condition" name="asset_condition" required>
                                            <option value="excellent" <?php echo $asset['asset_condition'] == 'excellent' ? 'selected' : ''; ?>>Excellent</option>
                                            <option value="good" <?php echo $asset['asset_condition'] == 'good' ? 'selected' : ''; ?>>Good</option>
                                            <option value="fair" <?php echo $asset['asset_condition'] == 'fair' ? 'selected' : ''; ?>>Fair</option>
                                            <option value="poor" <?php echo $asset['asset_condition'] == 'poor' ? 'selected' : ''; ?>>Poor</option>
                                            <option value="critical" <?php echo $asset['asset_condition'] == 'critical' ? 'selected' : ''; ?>>Critical</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="next_maintenance_date">Next Maintenance</label>
                                        <input type="date" class="form-control" id="next_maintenance_date" name="next_maintenance_date" 
                                               value="<?php echo $asset['next_maintenance_date']; ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="assigned_to">Assigned To</label>
                                        <select class="form-control select2" id="assigned_to" name="assigned_to">
                                            <option value="">- Unassigned -</option>
                                            <?php foreach ($users as $user): ?>
                                                <option value="<?php echo $user['user_id']; ?>" 
                                                    <?php echo $asset['assigned_to'] == $user['user_id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($user['user_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="assigned_department">Assigned Department</label>
                                        <input type="text" class="form-control" id="assigned_department" name="assigned_department" 
                                               value="<?php echo htmlspecialchars($asset['assigned_department']); ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="form-check mb-3">
                                <input type="checkbox" class="form-check-input" id="is_critical" name="is_critical" 
                                       <?php echo $asset['is_critical'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="is_critical">
                                    <i class="fas fa-exclamation-triangle mr-2"></i>
                                    Mark as Critical Asset (requires special attention)
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Additional Information -->
                    <div class="card card-secondary">
                        <div class="card-header">
                            <h4 class="card-title"><i class="fas fa-sticky-note mr-2"></i>Additional Information</h4>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label for="notes">Notes</label>
                                <textarea class="form-control" id="notes" name="notes" rows="3"><?php echo htmlspecialchars($asset['notes']); ?></textarea>
                                <small class="form-text text-muted">Additional information, maintenance history, or special instructions</small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <!-- Quick Actions -->
                    <div class="card card-success">
                        <div class="card-header">
                            <h4 class="card-title"><i class="fas fa-bolt mr-2"></i>Quick Actions</h4>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-success btn-lg">
                                    <i class="fas fa-save mr-2"></i>Update Asset
                                </button>
                                <button type="reset" class="btn btn-outline-secondary" onclick="resetForm()">
                                    <i class="fas fa-undo mr-2"></i>Reset Changes
                                </button>
                                <a href="asset_view.php?id=<?php echo $asset_id; ?>" class="btn btn-outline-info">
                                    <i class="fas fa-eye mr-2"></i>View Asset
                                </a>
                                <a href="asset_management.php" class="btn btn-outline-danger">
                                    <i class="fas fa-times mr-2"></i>Cancel
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Asset Preview -->
                    <div class="card card-info">
                        <div class="card-header">
                            <h4 class="card-title"><i class="fas fa-eye mr-2"></i>Asset Preview</h4>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-3">
                                <i class="fas fa-cube fa-2x text-info mb-2"></i>
                                <h5 id="preview_name"><?php echo htmlspecialchars($asset['asset_name']); ?></h5>
                                <div id="preview_tag" class="text-muted"><?php echo htmlspecialchars($asset['asset_tag']); ?></div>
                            </div>
                            <hr>
                            <div class="small">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Category:</span>
                                    <span id="preview_category" class="font-weight-bold"><?php echo htmlspecialchars($asset['category_name']); ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Status:</span>
                                    <span id="preview_status" class="badge badge-<?php 
                                        switch($asset['status']) {
                                            case 'active': echo 'success'; break;
                                            case 'inactive': echo 'secondary'; break;
                                            case 'under_maintenance': echo 'warning'; break;
                                            case 'checked_out': echo 'info'; break;
                                            case 'disposed': echo 'danger'; break;
                                            default: echo 'secondary';
                                        }
                                    ?>"><?php echo ucfirst(str_replace('_', ' ', $asset['status'])); ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Condition:</span>
                                    <span id="preview_condition" class="font-weight-bold"><?php echo ucfirst($asset['asset_condition']); ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Location:</span>
                                    <span id="preview_location" class="font-weight-bold"><?php echo $asset['location_name'] ? htmlspecialchars($asset['location_name']) : 'Not set'; ?></span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Current Value:</span>
                                    <span id="preview_value" class="font-weight-bold text-success">$<?php echo number_format($asset['current_value'], 2); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Asset Statistics -->
                    <div class="card card-warning">
                        <div class="card-header">
                            <h4 class="card-title"><i class="fas fa-chart-bar mr-2"></i>Asset Statistics</h4>
                        </div>
                        <div class="card-body">
                            <div class="list-group list-group-flush">
                                <div class="list-group-item px-0 py-2">
                                    <div class="d-flex w-100 justify-content-between">
                                        <span>Maintenance Records:</span>
                                        <span class="badge badge-primary badge-pill"><?php echo $asset_stats['maintenance_count']; ?></span>
                                    </div>
                                </div>
                                <div class="list-group-item px-0 py-2">
                                    <div class="d-flex w-100 justify-content-between">
                                        <span>Current Status:</span>
                                        <span class="badge badge-<?php 
                                            switch($asset['status']) {
                                                case 'active': echo 'success'; break;
                                                case 'under_maintenance': echo 'warning'; break;
                                                case 'checked_out': echo 'info'; break;
                                                default: echo 'secondary';
                                            }
                                        ?> badge-pill">
                                            <?php echo ucfirst(str_replace('_', ' ', $asset['status'])); ?>
                                        </span>
                                    </div>
                                </div>
                                <?php if ($asset['purchase_price'] > 0): ?>
                                <div class="list-group-item px-0 py-2">
                                    <div class="d-flex w-100 justify-content-between">
                                        <span>Purchase Price:</span>
                                        <span class="badge badge-dark badge-pill">$<?php echo number_format($asset['purchase_price'], 2); ?></span>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <div class="list-group-item px-0 py-2">
                                    <div class="d-flex w-100 justify-content-between">
                                        <span>Days Since Purchase:</span>
                                        <span class="badge badge-info badge-pill">
                                            <?php 
                                            if ($asset['purchase_date']) {
                                                $purchaseDate = new DateTime($asset['purchase_date']);
                                                $today = new DateTime();
                                                echo $today->diff($purchaseDate)->days;
                                            } else {
                                                echo 'N/A';
                                            }
                                            ?>
                                        </span>
                                    </div>
                                </div>
                                <?php if ($asset_stats['checked_out_count'] > 0): ?>
                                <div class="list-group-item px-0 py-2">
                                    <div class="d-flex w-100 justify-content-between">
                                        <span>Currently Checked Out:</span>
                                        <span class="badge badge-warning badge-pill">Yes</span>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Record Information -->
                    <div class="card card-secondary">
                        <div class="card-header">
                            <h4 class="card-title"><i class="fas fa-info-circle mr-2"></i>Record Information</h4>
                        </div>
                        <div class="card-body">
                            <div class="small">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Created:</span>
                                    <span><?php echo date('M j, Y', strtotime($asset['created_at'])); ?></span>
                                </div>
                                <?php if ($asset['created_by_name']): ?>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>By:</span>
                                    <span><?php echo htmlspecialchars($asset['created_by_name']); ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if ($asset['updated_at'] && $asset['updated_at'] != $asset['created_at']): ?>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Last Updated:</span>
                                    <span><?php echo date('M j, Y', strtotime($asset['updated_at'])); ?></span>
                                </div>
                                <?php if ($asset['updated_by_name']): ?>
                                <div class="d-flex justify-content-between">
                                    <span>By:</span>
                                    <span><?php echo htmlspecialchars($asset['updated_by_name']); ?></span>
                                </div>
                                <?php endif; ?>
                                <?php endif; ?>
                                <div class="d-flex justify-content-between mt-3">
                                    <span>Record ID:</span>
                                    <span class="font-weight-bold">#<?php echo str_pad($asset_id, 6, '0', STR_PAD_LEFT); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Tips -->
                    <div class="card card-primary">
                        <div class="card-header">
                            <h4 class="card-title"><i class="fas fa-lightbulb mr-2"></i>Quick Tips</h4>
                        </div>
                        <div class="card-body">
                            <div class="callout callout-info small mb-3">
                                <i class="fas fa-info-circle mr-2"></i>
                                <strong>Asset Tag:</strong> Must be unique. Use a consistent format for easy identification.
                            </div>
                            <div class="callout callout-warning small mb-3">
                                <i class="fas fa-exclamation-triangle mr-2"></i>
                                <strong>Depreciation:</strong> Current value should be updated regularly to reflect depreciation.
                            </div>
                            <div class="callout callout-success small">
                                <i class="fas fa-check-circle mr-2"></i>
                                <strong>Maintenance Schedule:</strong> Setting next maintenance date helps with preventive maintenance planning.
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
    // Initialize Select2
    $('.select2').select2();

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
    
    $('#status').on('change', function() {
        var status = $(this).val();
        var badgeClass = '';
        switch(status) {
            case 'active': badgeClass = 'success'; break;
            case 'inactive': badgeClass = 'secondary'; break;
            case 'under_maintenance': badgeClass = 'warning'; break;
            case 'checked_out': badgeClass = 'info'; break;
            case 'disposed': badgeClass = 'danger'; break;
            default: badgeClass = 'secondary';
        }
        var statusText = status.replace('_', ' ');
        statusText = statusText.charAt(0).toUpperCase() + statusText.slice(1);
        $('#preview_status').removeClass().addClass('badge badge-' + badgeClass).text(statusText);
    });
    
    $('#asset_condition').on('change', function() {
        $('#preview_condition').text($(this).val());
    });
    
    $('#location_id').on('change', function() {
        var selectedText = $(this).find('option:selected').text();
        $('#preview_location').text(selectedText || 'Not set');
    });
    
    $('#current_value').on('input', function() {
        var value = parseFloat($(this).val()) || 0;
        $('#preview_value').text('$' + value.toFixed(2));
    });

    // Auto-calculate current value if purchase price changes
    $('#purchase_price').on('change', function() {
        var purchasePrice = parseFloat($(this).val()) || 0;
        var currentValue = parseFloat($('#current_value').val()) || 0;
        
        // Only auto-update if current value is zero or close to purchase price
        if (currentValue === 0 || Math.abs(currentValue - purchasePrice) < 0.01) {
            $('#current_value').val(purchasePrice.toFixed(2));
            $('#preview_value').text('$' + purchasePrice.toFixed(2));
        }
    });

    // Set default warranty expiry (1 year from purchase) if not set
    $('#purchase_date').on('change', function() {
        if ($(this).val() && !$('#warranty_expiry').val()) {
            var purchaseDate = new Date($(this).val());
            var warrantyExpiry = new Date(purchaseDate);
            warrantyExpiry.setFullYear(warrantyExpiry.getFullYear() + 1);
            $('#warranty_expiry').val(warrantyExpiry.toISOString().split('T')[0]);
        }
    });

    // Set default next maintenance date (30 days from now) if not set
    if (!$('#next_maintenance_date').val()) {
        var nextDate = new Date();
        nextDate.setDate(nextDate.getDate() + 30);
        $('#next_maintenance_date').val(nextDate.toISOString().split('T')[0]);
    }

    // Enhanced form validation
    $('#assetForm').on('submit', function(e) {
        var requiredFields = ['asset_tag', 'asset_name', 'category_id', 'status', 'asset_condition'];
        var isValid = true;
        var errorMessages = [];
        
        requiredFields.forEach(function(field) {
            var value = $('#' + field).val();
            var fieldName = $('label[for="' + field + '"]').text().replace('*', '').trim();
            
            if (!value) {
                isValid = false;
                errorMessages.push(fieldName + ' is required');
                $('#' + field).addClass('is-invalid');
            } else {
                $('#' + field).removeClass('is-invalid');
            }
        });

        // Validate dates
        var purchaseDate = $('#purchase_date').val() ? new Date($('#purchase_date').val()) : null;
        var warrantyExpiry = $('#warranty_expiry').val() ? new Date($('#warranty_expiry').val()) : null;
        var today = new Date();
        
        if (purchaseDate && purchaseDate > today) {
            isValid = false;
            errorMessages.push('Purchase date cannot be in the future');
            $('#purchase_date').addClass('is-invalid');
        } else {
            $('#purchase_date').removeClass('is-invalid');
        }
        
        if (warrantyExpiry && purchaseDate && warrantyExpiry < purchaseDate) {
            isValid = false;
            errorMessages.push('Warranty expiry cannot be before purchase date');
            $('#warranty_expiry').addClass('is-invalid');
        } else {
            $('#warranty_expiry').removeClass('is-invalid');
        }

        // Validate financial values
        var purchasePrice = parseFloat($('#purchase_price').val()) || 0;
        var currentValue = parseFloat($('#current_value').val()) || 0;
        
        if (purchasePrice < 0) {
            isValid = false;
            errorMessages.push('Purchase price cannot be negative');
            $('#purchase_price').addClass('is-invalid');
        } else {
            $('#purchase_price').removeClass('is-invalid');
        }
        
        if (currentValue < 0) {
            isValid = false;
            errorMessages.push('Current value cannot be negative');
            $('#current_value').addClass('is-invalid');
        } else {
            $('#current_value').removeClass('is-invalid');
        }

        if (!isValid) {
            e.preventDefault();
            var errorMessage = 'Please fix the following errors:\n• ' + errorMessages.join('\n• ');
            alert(errorMessage);
            return false;
        }

        // Show loading state
        $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Updating...').prop('disabled', true);
    });

    // Initialize preview badges
    $('#status').trigger('change');
});

function resetForm() {
    if (confirm('Are you sure you want to reset all changes? All unsaved changes will be lost.')) {
        location.reload();
    }
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + S to save
    if (e.ctrlKey && e.keyCode === 83) {
        e.preventDefault();
        $('#assetForm').submit();
    }
    // Escape to cancel
    if (e.keyCode === 27) {
        window.location.href = 'asset_view.php?id=<?php echo $asset_id; ?>';
    }
    // Ctrl + R to reset
    if (e.ctrlKey && e.keyCode === 82) {
        e.preventDefault();
        resetForm();
    }
    // Ctrl + V to view asset
    if (e.ctrlKey && e.keyCode === 86) {
        e.preventDefault();
        window.location.href = 'asset_view.php?id=<?php echo $asset_id; ?>';
    }
});
</script>

<style>
.callout {
    border-left: 3px solid #eee;
    margin-bottom: 10px;
    padding: 10px 15px;
    border-radius: 0.25rem;
}

.callout-info {
    border-left-color: #17a2b8;
    background-color: #f8f9fa;
}

.callout-warning {
    border-left-color: #ffc107;
    background-color: #fffbf0;
}

.callout-success {
    border-left-color: #28a745;
    background-color: #f0fff4;
}

.callout-primary {
    border-left-color: #007bff;
    background-color: #f0f8ff;
}

.select2-container .select2-selection--single {
    height: 38px;
}

.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 36px;
}

.is-invalid {
    border-color: #dc3545;
    box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25);
}

.btn:disabled {
    cursor: not-allowed;
    opacity: 0.6;
}

.badge-pill {
    padding: 0.5em 0.8em;
}

.list-group-item {
    border: none;
    padding: 0.75rem 0;
}

.list-group-item:hover {
    background-color: #f8f9fa;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
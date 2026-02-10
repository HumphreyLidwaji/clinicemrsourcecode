<?php
// asset_maintenance_edit.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Check if ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid maintenance ID";
    header("Location: asset_maintenance.php");
    exit;
}

$maintenance_id = intval($_GET['id']);

// Get maintenance record for editing
$sql = "
    SELECT am.*,
           a.asset_id, a.asset_tag, a.asset_name, a.serial_number, 
           a.asset_condition, a.status as asset_status,
           ac.category_name,
           al.location_name, al.building, al.floor, al.room_number,
           s.supplier_name,
           creator.user_name as created_by_name,
           performer.user_name as performed_by_name
    FROM asset_maintenance am
    JOIN assets a ON am.asset_id = a.asset_id
    LEFT JOIN asset_categories ac ON a.category_id = ac.category_id
    LEFT JOIN asset_locations al ON a.location_id = al.location_id
    LEFT JOIN suppliers s ON am.supplier_id = s.supplier_id
    LEFT JOIN users creator ON am.created_by = creator.user_id
    LEFT JOIN users performer ON am.performed_by = performer.user_id
    WHERE am.maintenance_id = ?
";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $maintenance_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Maintenance record not found";
    header("Location: asset_maintenance.php");
    exit;
}

$maintenance = $result->fetch_assoc();

// Initialize variables
$assets = [];
$suppliers = [];
$users = [];

// Get assets for dropdown
$assets_sql = "
    SELECT a.asset_id, a.asset_tag, a.asset_name, a.serial_number, 
           a.asset_condition, a.status, ac.category_name
    FROM assets a
    LEFT JOIN asset_categories ac ON a.category_id = ac.category_id
    WHERE a.status IN ('active', 'under_maintenance')
    ORDER BY a.asset_tag
";
$assets_result = $mysqli->query($assets_sql);
while ($asset = $assets_result->fetch_assoc()) {
    $assets[] = $asset;
}

// Get suppliers
$suppliers_sql = "SELECT supplier_id, supplier_name FROM suppliers 
                 WHERE supplier_is_active = 1 ORDER BY supplier_name";
$suppliers_result = $mysqli->query($suppliers_sql);
while ($supplier = $suppliers_result->fetch_assoc()) {
    $suppliers[] = $supplier;
}

// Get users
$users_sql = "SELECT user_id, user_name, user_email FROM users 
             ";
$users_result = $mysqli->query($users_sql);
while ($user = $users_result->fetch_assoc()) {
    $users[] = $user;
}

// Get current user's information
$current_user_sql = "SELECT user_name, user_email FROM users WHERE user_id = ?";
$current_user_stmt = $mysqli->prepare($current_user_sql);
$current_user_stmt->bind_param("i", $session_user_id);
$current_user_stmt->execute();
$current_user_result = $current_user_stmt->get_result();
$current_user = $current_user_result->fetch_assoc();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    
    if ($csrf_token != $_SESSION['csrf_token']) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "CSRF token validation failed";
        header("Location: asset_maintenance_edit.php?id=$maintenance_id");
        exit;
    }
    
    $asset_id = intval($_POST['asset_id']);
    $maintenance_type = sanitizeInput($_POST['maintenance_type']);
    $maintenance_date = sanitizeInput($_POST['maintenance_date']);
    $scheduled_date = sanitizeInput($_POST['scheduled_date']);
    $description = sanitizeInput($_POST['description']);
    $findings = sanitizeInput($_POST['findings']);
    $recommendations = sanitizeInput($_POST['recommendations']);
    $performed_by = intval($_POST['performed_by']);
    $supplier_id = intval($_POST['supplier_id']);
    $cost = floatval($_POST['cost']);
    $next_maintenance_date = sanitizeInput($_POST['next_maintenance_date']);
    $next_maintenance_notes = sanitizeInput($_POST['next_maintenance_notes']);
    $status = sanitizeInput($_POST['status']);
    $updated_by = $session_user_id;
    
    // Validate required fields
    if (empty($asset_id) || empty($maintenance_type) || empty($maintenance_date) || empty($description)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please fill in all required fields";
        header("Location: asset_maintenance_edit.php?id=$maintenance_id");
        exit;
    }
    
    // Validate dates
    $maintenanceDate = new DateTime($maintenance_date);
    $scheduledDate = $scheduled_date ? new DateTime($scheduled_date) : null;
    $nextMaintenanceDate = $next_maintenance_date ? new DateTime($next_maintenance_date) : null;
    
    if ($scheduledDate && $scheduledDate < $maintenanceDate) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Scheduled date cannot be before maintenance date";
        header("Location: asset_maintenance_edit.php?id=$maintenance_id");
        exit;
    }
    
    if ($nextMaintenanceDate && $nextMaintenanceDate < $maintenanceDate) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Next maintenance date cannot be before current maintenance date";
        header("Location: asset_maintenance_edit.php?id=$maintenance_id");
        exit;
    }
    
    // Update maintenance record
    $update_sql = "
        UPDATE asset_maintenance SET
            asset_id = ?,
            maintenance_type = ?,
            maintenance_date = ?,
            scheduled_date = ?,
            description = ?,
            findings = ?,
            recommendations = ?,
            performed_by = ?,
            supplier_id = ?,
            cost = ?,
            next_maintenance_date = ?,
            next_maintenance_notes = ?,
            status = ?,
            updated_by = ?,
            updated_at = NOW()
        WHERE maintenance_id = ?
    ";
    
    $update_stmt = $mysqli->prepare($update_sql);
    $update_stmt->bind_param(
        "issssssiidsssii",
        $asset_id,
        $maintenance_type,
        $maintenance_date,
        $scheduled_date,
        $description,
        $findings,
        $recommendations,
        $performed_by,
        $supplier_id,
        $cost,
        $next_maintenance_date,
        $next_maintenance_notes,
        $status,
        $updated_by,
        $maintenance_id
    );
    
    // Update asset status if maintenance type is corrective
    if ($maintenance_type == 'corrective') {
        $update_asset_sql = "UPDATE assets SET asset_condition = 'needs_attention', updated_by = ?, updated_at = NOW() WHERE asset_id = ?";
    } else {
        $update_asset_sql = "UPDATE assets SET updated_by = ?, updated_at = NOW() WHERE asset_id = ?";
    }
    
    $update_asset_stmt = $mysqli->prepare($update_asset_sql);
    $update_asset_stmt->bind_param("ii", $session_user_id, $asset_id);
    
    // Start transaction
    $mysqli->begin_transaction();
    
    try {
        if ($update_stmt->execute() && $update_asset_stmt->execute()) {
            $mysqli->commit();
            
            // Get asset details for logging
            $asset_info = $mysqli->query("SELECT asset_tag, asset_name FROM assets WHERE asset_id = $asset_id")->fetch_assoc();
            
            // Log activity
            $log_description = "Maintenance updated: {$asset_info['asset_tag']} - {$asset_info['asset_name']} (Type: $maintenance_type)";
            mysqli_query($mysqli, "INSERT INTO logs SET log_type = 'Asset', log_action = 'Update', log_description = '$log_description', log_ip = '$session_ip', log_user_agent = '$session_user_agent', log_user_id = $session_user_id");
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Maintenance record updated successfully!";
            header("Location: asset_maintenance_view.php?id=$maintenance_id");
            exit;
        } else {
            throw new Exception("Database error");
        }
    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error updating maintenance record: " . $mysqli->error;
        header("Location: asset_maintenance_edit.php?id=$maintenance_id");
        exit;
    }
}

// Get maintenance statistics
$maintenance_stats_sql = "
    SELECT 
        COUNT(*) as total_maintenance,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) as scheduled,
        COALESCE(SUM(cost), 0) as total_cost
    FROM asset_maintenance
    WHERE asset_id = ?
";
$stats_stmt = $mysqli->prepare($maintenance_stats_sql);
$stats_stmt->bind_param("i", $maintenance['asset_id']);
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();
$maintenance_stats = $stats_result->fetch_assoc();
?>

<div class="card">
    <div class="card-header bg-warning py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0 text-dark">
                <i class="fas fa-fw fa-edit mr-2"></i>Edit Maintenance Record
            </h3>
            <div class="btn-group">
                <a href="asset_maintenance_view.php?id=<?php echo $maintenance_id; ?>" class="btn btn-info">
                    <i class="fas fa-eye mr-2"></i>View
                </a>
                <a href="asset_maintenance.php" class="btn btn-light ml-2">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Maintenance
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

        <form method="POST" id="maintenanceForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <div class="row">
                <div class="col-md-8">
                    <!-- Asset Selection -->
                    <div class="card card-primary">
                        <div class="card-header">
                            <h4 class="card-title"><i class="fas fa-cube mr-2"></i>Asset Information</h4>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label for="asset_id">Asset *</label>
                                <select class="form-control select2" id="asset_id" name="asset_id" required>
                                    <option value="">- Select Asset -</option>
                                    <?php foreach ($assets as $asset): ?>
                                        <option value="<?php echo $asset['asset_id']; ?>" 
                                                <?php echo $asset['asset_id'] == $maintenance['asset_id'] ? 'selected' : ''; ?>
                                                data-category="<?php echo htmlspecialchars($asset['category_name']); ?>"
                                                data-condition="<?php echo htmlspecialchars($asset['asset_condition']); ?>">
                                            <?php echo htmlspecialchars($asset['asset_tag'] . ' - ' . $asset['asset_name']); ?>
                                            <?php if ($asset['serial_number']): ?>
                                                (SN: <?php echo htmlspecialchars($asset['serial_number']); ?>)
                                            <?php endif; ?>
                                            - <?php echo htmlspecialchars(ucfirst($asset['status'])); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="form-text text-muted">Select the asset that received maintenance</small>
                            </div>
                            
                            <div class="row mt-3" id="assetDetails">
                                <div class="col-md-4">
                                    <div class="info-box bg-light">
                                        <span class="info-box-icon"><i class="fas fa-tag"></i></span>
                                        <div class="info-box-content">
                                            <span class="info-box-text">Current Asset</span>
                                            <span class="info-box-number"><?php echo htmlspecialchars($maintenance['asset_tag']); ?></span>
                                            <small class="text-muted"><?php echo htmlspecialchars($maintenance['asset_name']); ?></small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="info-box bg-light">
                                        <span class="info-box-icon"><i class="fas fa-folder"></i></span>
                                        <div class="info-box-content">
                                            <span class="info-box-text">Category</span>
                                            <span class="info-box-number"><?php echo htmlspecialchars($maintenance['category_name']); ?></span>
                                            <small class="text-muted">Asset category</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="info-box bg-light">
                                        <span class="info-box-icon"><i class="fas fa-heartbeat"></i></span>
                                        <div class="info-box-content">
                                            <span class="info-box-text">Condition</span>
                                            <span class="info-box-number"><?php echo ucfirst(str_replace('_', ' ', $maintenance['asset_condition'])); ?></span>
                                            <small class="text-muted">Current asset condition</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Maintenance Details -->
                    <div class="card card-warning">
                        <div class="card-header">
                            <h4 class="card-title"><i class="fas fa-tools mr-2"></i>Maintenance Details</h4>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="maintenance_type">Maintenance Type *</label>
                                        <select class="form-control" id="maintenance_type" name="maintenance_type" required>
                                            <option value="">- Select Type -</option>
                                            <option value="preventive" <?php echo $maintenance['maintenance_type'] == 'preventive' ? 'selected' : ''; ?>>Preventive Maintenance</option>
                                            <option value="corrective" <?php echo $maintenance['maintenance_type'] == 'corrective' ? 'selected' : ''; ?>>Corrective Maintenance</option>
                                            <option value="calibration" <?php echo $maintenance['maintenance_type'] == 'calibration' ? 'selected' : ''; ?>>Calibration</option>
                                            <option value="inspection" <?php echo $maintenance['maintenance_type'] == 'inspection' ? 'selected' : ''; ?>>Inspection</option>
                                            <option value="upgrade" <?php echo $maintenance['maintenance_type'] == 'upgrade' ? 'selected' : ''; ?>>Upgrade/Enhancement</option>
                                            <option value="repair" <?php echo $maintenance['maintenance_type'] == 'repair' ? 'selected' : ''; ?>>Repair</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="status">Status *</label>
                                        <select class="form-control" id="status" name="status" required>
                                            <option value="scheduled" <?php echo $maintenance['status'] == 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                                            <option value="in_progress" <?php echo $maintenance['status'] == 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                            <option value="completed" <?php echo $maintenance['status'] == 'completed' ? 'selected' : ''; ?>>Completed</option>
                                            <option value="cancelled" <?php echo $maintenance['status'] == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="performed_by">Performed By</label>
                                        <select class="form-control select2" id="performed_by" name="performed_by">
                                            <option value="">- Not Assigned -</option>
                                            <?php foreach ($users as $user): ?>
                                                <option value="<?php echo $user['user_id']; ?>" 
                                                    <?php echo $user['user_id'] == $maintenance['performed_by'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($user['user_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="supplier_id">Supplier/Vendor</label>
                                        <select class="form-control select2" id="supplier_id" name="supplier_id">
                                            <option value="">- No Supplier -</option>
                                            <?php foreach ($suppliers as $supplier): ?>
                                                <option value="<?php echo $supplier['supplier_id']; ?>" 
                                                    <?php echo $supplier['supplier_id'] == $maintenance['supplier_id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($supplier['supplier_name']); ?>
                                                    <?php if ($supplier['supplier_type']): ?>
                                                        (<?php echo htmlspecialchars($supplier['supplier_type']); ?>)
                                                    <?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="cost">Cost ($)</label>
                                        <input type="number" class="form-control" id="cost" name="cost" 
                                               min="0" step="0.01" value="<?php echo number_format($maintenance['cost'], 2); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Maintenance Description -->
                    <div class="card card-info">
                        <div class="card-header">
                            <h4 class="card-title"><i class="fas fa-clipboard mr-2"></i>Maintenance Description</h4>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label for="description">Description *</label>
                                <textarea class="form-control" id="description" name="description" rows="3" required><?php echo htmlspecialchars($maintenance['description']); ?></textarea>
                            </div>

                            <div class="form-group">
                                <label for="findings">Findings/Issues Identified</label>
                                <textarea class="form-control" id="findings" name="findings" rows="2"><?php echo htmlspecialchars($maintenance['findings']); ?></textarea>
                            </div>

                            <div class="form-group">
                                <label for="recommendations">Recommendations</label>
                                <textarea class="form-control" id="recommendations" name="recommendations" rows="2"><?php echo htmlspecialchars($maintenance['recommendations']); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Schedule Information -->
                    <div class="card card-success">
                        <div class="card-header">
                            <h4 class="card-title"><i class="fas fa-calendar-alt mr-2"></i>Schedule Information</h4>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="maintenance_date">Maintenance Date *</label>
                                        <input type="date" class="form-control" id="maintenance_date" 
                                               name="maintenance_date" value="<?php echo $maintenance['maintenance_date']; ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="scheduled_date">Scheduled Date</label>
                                        <input type="date" class="form-control" id="scheduled_date" 
                                               name="scheduled_date" value="<?php echo $maintenance['scheduled_date']; ?>">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="next_maintenance_date">Next Maintenance Date</label>
                                        <input type="date" class="form-control" id="next_maintenance_date" 
                                               name="next_maintenance_date" value="<?php echo $maintenance['next_maintenance_date']; ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="next_maintenance_notes">Next Maintenance Notes</label>
                                <textarea class="form-control" id="next_maintenance_notes" name="next_maintenance_notes" rows="2"><?php echo htmlspecialchars($maintenance['next_maintenance_notes']); ?></textarea>
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
                                    <i class="fas fa-save mr-2"></i>Update Maintenance
                                </button>
                                <button type="reset" class="btn btn-outline-secondary" onclick="resetForm()">
                                    <i class="fas fa-undo mr-2"></i>Reset Changes
                                </button>
                                <a href="asset_maintenance_view.php?id=<?php echo $maintenance_id; ?>" class="btn btn-outline-info">
                                    <i class="fas fa-eye mr-2"></i>View Details
                                </a>
                                <a href="asset_maintenance.php" class="btn btn-outline-danger">
                                    <i class="fas fa-times mr-2"></i>Cancel
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Maintenance Preview -->
                    <div class="card card-info">
                        <div class="card-header">
                            <h4 class="card-title"><i class="fas fa-eye mr-2"></i>Preview</h4>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-3">
                                <i class="fas fa-tools fa-2x text-info mb-2"></i>
                                <h5 id="preview_asset"><?php echo htmlspecialchars($maintenance['asset_tag']); ?></h5>
                                <div id="preview_type" class="text-muted"><?php echo ucfirst($maintenance['maintenance_type']); ?> Maintenance</div>
                            </div>
                            <hr>
                            <div class="small">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Status:</span>
                                    <span id="preview_status" class="badge badge-warning"><?php echo ucfirst($maintenance['status']); ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Maintenance Date:</span>
                                    <span id="preview_date" class="font-weight-bold"><?php echo date('M d, Y', strtotime($maintenance['maintenance_date'])); ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Performed By:</span>
                                    <span id="preview_performer" class="font-weight-bold"><?php echo $maintenance['performed_by_name'] ?: 'Not assigned'; ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Cost:</span>
                                    <span id="preview_cost" class="font-weight-bold text-success">$<?php echo number_format($maintenance['cost'], 2); ?></span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Updated by:</span>
                                    <span class="font-weight-bold"><?php echo htmlspecialchars($current_user['user_name']); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Maintenance Statistics -->
                    <div class="card card-warning">
                        <div class="card-header">
                            <h4 class="card-title"><i class="fas fa-chart-bar mr-2"></i>Asset Maintenance Statistics</h4>
                        </div>
                        <div class="card-body">
                            <div class="list-group list-group-flush">
                                <div class="list-group-item px-0 py-2">
                                    <div class="d-flex w-100 justify-content-between">
                                        <span>Total Maintenance:</span>
                                        <span class="badge badge-primary badge-pill"><?php echo $maintenance_stats['total_maintenance']; ?></span>
                                    </div>
                                </div>
                                <div class="list-group-item px-0 py-2">
                                    <div class="d-flex w-100 justify-content-between">
                                        <span>Completed:</span>
                                        <span class="badge badge-success badge-pill"><?php echo $maintenance_stats['completed']; ?></span>
                                    </div>
                                </div>
                                <div class="list-group-item px-0 py-2">
                                    <div class="d-flex w-100 justify-content-between">
                                        <span>In Progress:</span>
                                        <span class="badge badge-info badge-pill"><?php echo $maintenance_stats['in_progress']; ?></span>
                                    </div>
                                </div>
                                <div class="list-group-item px-0 py-2">
                                    <div class="d-flex w-100 justify-content-between">
                                        <span>Scheduled:</span>
                                        <span class="badge badge-warning badge-pill"><?php echo $maintenance_stats['scheduled']; ?></span>
                                    </div>
                                </div>
                                <div class="list-group-item px-0 py-2">
                                    <div class="d-flex w-100 justify-content-between">
                                        <span>Total Cost:</span>
                                        <span class="badge badge-dark badge-pill">$<?php echo number_format($maintenance_stats['total_cost'], 0); ?></span>
                                    </div>
                                </div>
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
                                    <span><?php echo date('M d, Y', strtotime($maintenance['created_at'])); ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>By:</span>
                                    <span><?php echo htmlspecialchars($maintenance['created_by_name']); ?></span>
                                </div>
                                <?php if ($maintenance['updated_at']): ?>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Last Updated:</span>
                                    <span><?php echo date('M d, Y', strtotime($maintenance['updated_at'])); ?></span>
                                </div>
                                <?php endif; ?>
                                <div class="d-flex justify-content-between">
                                    <span>Record ID:</span>
                                    <span class="font-weight-bold">#<?php echo str_pad($maintenance['maintenance_id'], 6, '0', STR_PAD_LEFT); ?></span>
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
                                <strong>Status Updates:</strong> Update the status as maintenance progresses through different stages.
                            </div>
                            <div class="callout callout-warning small mb-3">
                                <i class="fas fa-exclamation-triangle mr-2"></i>
                                <strong>Cost Tracking:</strong> Record all costs associated with this maintenance for accurate budgeting.
                            </div>
                            <div class="callout callout-success small">
                                <i class="fas fa-check-circle mr-2"></i>
                                <strong>Future Planning:</strong> Set next maintenance dates to ensure timely follow-up.
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

    // Update preview when form fields change
    $('#asset_id').on('change', function() {
        var selectedOption = $(this).find('option:selected');
        if (selectedOption.val()) {
            $('#preview_asset').text(selectedOption.text().split(' - ')[0] || selectedOption.text());
        }
    });

    $('#maintenance_type').on('change', function() {
        var selectedText = $(this).find('option:selected').text();
        $('#preview_type').text(selectedText + ' Maintenance');
    });

    $('#status').on('change', function() {
        var selectedText = $(this).find('option:selected').text();
        var badgeClass = '';
        switch($(this).val()) {
            case 'completed': badgeClass = 'badge-success'; break;
            case 'in_progress': badgeClass = 'badge-primary'; break;
            case 'scheduled': badgeClass = 'badge-warning'; break;
            case 'cancelled': badgeClass = 'badge-secondary'; break;
            default: badgeClass = 'badge-light';
        }
        $('#preview_status').removeClass().addClass('badge ' + badgeClass).text(selectedText);
    });

    $('#performed_by').on('change', function() {
        var selectedText = $(this).find('option:selected').text();
        $('#preview_performer').text(selectedText || 'Not assigned');
    });

    $('#maintenance_date').on('change', function() {
        var date = new Date($(this).val());
        if (!isNaN(date.getTime())) {
            $('#preview_date').text(date.toLocaleDateString('en-US', { 
                month: 'short', 
                day: 'numeric', 
                year: 'numeric' 
            }));
        }
    });

    $('#cost').on('input', function() {
        var cost = parseFloat($(this).val()) || 0;
        $('#preview_cost').text('$' + cost.toFixed(2));
    });

    // Auto-set scheduled date for preventive maintenance
    $('#maintenance_type').on('change', function() {
        if ($(this).val() === 'preventive' && !$('#scheduled_date').val()) {
            var tomorrow = new Date();
            tomorrow.setDate(tomorrow.getDate() + 1);
            $('#scheduled_date').val(tomorrow.toISOString().split('T')[0]);
            
            // Set next maintenance date (30 days from now)
            var nextDate = new Date();
            nextDate.setDate(nextDate.getDate() + 30);
            $('#next_maintenance_date').val(nextDate.toISOString().split('T')[0]);
        }
    });

    // Enhanced form validation
    $('#maintenanceForm').on('submit', function(e) {
        var requiredFields = ['asset_id', 'maintenance_type', 'maintenance_date', 'description', 'status'];
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
        var maintenanceDate = new Date($('#maintenance_date').val());
        var scheduledDate = $('#scheduled_date').val() ? new Date($('#scheduled_date').val()) : null;
        var nextMaintenanceDate = $('#next_maintenance_date').val() ? new Date($('#next_maintenance_date').val()) : null;
        
        if (scheduledDate && scheduledDate < maintenanceDate) {
            isValid = false;
            errorMessages.push('Scheduled date cannot be before maintenance date');
            $('#scheduled_date').addClass('is-invalid');
        } else {
            $('#scheduled_date').removeClass('is-invalid');
        }
        
        if (nextMaintenanceDate && nextMaintenanceDate < maintenanceDate) {
            isValid = false;
            errorMessages.push('Next maintenance date cannot be before current maintenance date');
            $('#next_maintenance_date').addClass('is-invalid');
        } else {
            $('#next_maintenance_date').removeClass('is-invalid');
        }

        // Validate cost
        var cost = parseFloat($('#cost').val()) || 0;
        if (cost < 0) {
            isValid = false;
            errorMessages.push('Cost cannot be negative');
            $('#cost').addClass('is-invalid');
        } else {
            $('#cost').removeClass('is-invalid');
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

    // Initialize preview
    $('#maintenance_type').trigger('change');
    $('#status').trigger('change');
    $('#cost').trigger('input');
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
        $('#maintenanceForm').submit();
    }
    // Escape to cancel
    if (e.keyCode === 27) {
        window.location.href = 'asset_maintenance_view.php?id=<?php echo $maintenance_id; ?>';
    }
    // Ctrl + R to reset
    if (e.ctrlKey && e.keyCode === 82) {
        e.preventDefault();
        resetForm();
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

.info-box {
    margin-bottom: 10px;
}

.badge-pill {
    padding: 0.5em 0.8em;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
<?php
// asset_maintenance_new.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';


// Initialize variables
$assets = [];
$suppliers = [];
$users = [];

// Get assets that can have maintenance
$assets_sql = "
    SELECT a.asset_id, a.asset_tag, a.asset_name, a.serial_number, 
           a.asset_condition, a.status, ac.category_name,
           al.location_name, a.purchase_price, a.current_value
    FROM assets a
    LEFT JOIN asset_categories ac ON a.category_id = ac.category_id
    LEFT JOIN asset_locations al ON a.location_id = al.location_id
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

// Get users who can perform maintenance
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

// Get maintenance statistics
$maintenance_stats_sql = "
    SELECT 
        COUNT(*) as total_maintenance,
        SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) as scheduled,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
        SUM(CASE WHEN scheduled_date < CURDATE() AND status IN ('scheduled', 'in_progress') THEN 1 ELSE 0 END) as overdue,
        COALESCE(SUM(cost), 0) as total_cost
    FROM asset_maintenance
";
$maintenance_stats_result = $mysqli->query($maintenance_stats_sql);
$maintenance_stats = $maintenance_stats_result->fetch_assoc();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    
    if ($csrf_token != $_SESSION['csrf_token']) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "CSRF token validation failed";
        header("Location: asset_maintenance_new.php");
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
    $created_by = $session_user_id;
    
    // Validate required fields
    if (empty($asset_id) || empty($maintenance_type) || empty($maintenance_date) || empty($description)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please fill in all required fields";
        header("Location: asset_maintenance_new.php");
        exit;
    }
    
    // Validate dates
    $maintenanceDate = new DateTime($maintenance_date);
    $scheduledDate = $scheduled_date ? new DateTime($scheduled_date) : null;
    $nextMaintenanceDate = $next_maintenance_date ? new DateTime($next_maintenance_date) : null;
    
    if ($scheduledDate && $scheduledDate < $maintenanceDate) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Scheduled date cannot be before maintenance date";
        header("Location: asset_maintenance_new.php");
        exit;
    }
    
    if ($nextMaintenanceDate && $nextMaintenanceDate < $maintenanceDate) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Next maintenance date cannot be before current maintenance date";
        header("Location: asset_maintenance_new.php");
        exit;
    }
    
    // Insert maintenance record
    $insert_sql = "
        INSERT INTO asset_maintenance 
        (asset_id, maintenance_type, maintenance_date, scheduled_date, 
         description, findings, recommendations, performed_by, supplier_id, 
         cost, next_maintenance_date, next_maintenance_notes, status, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'scheduled', ?, NOW())
    ";
    
    $insert_stmt = $mysqli->prepare($insert_sql);
    $insert_stmt->bind_param(
        "issssssiidssi",
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
        $created_by
    );
    
    // Update asset status if maintenance is in progress
    if ($maintenance_type == 'corrective') {
        $update_asset_sql = "UPDATE assets SET asset_condition = 'needs_attention', updated_by = ?, updated_at = NOW() WHERE asset_id = ?";
    } else {
        $update_asset_sql = "UPDATE assets SET updated_by = ?, updated_at = NOW() WHERE asset_id = ?";
    }
    
    $update_stmt = $mysqli->prepare($update_asset_sql);
    $update_stmt->bind_param("ii", $session_user_id, $asset_id);
    
    // Start transaction
    $mysqli->begin_transaction();
    
    try {
        if ($insert_stmt->execute() && $update_stmt->execute()) {
            $mysqli->commit();
            $new_maintenance_id = $insert_stmt->insert_id;
            
            // Get asset details for logging
            $asset_info = $mysqli->query("SELECT asset_tag, asset_name FROM assets WHERE asset_id = $asset_id")->fetch_assoc();
            
            // Log activity
            $log_description = "Maintenance scheduled: {$asset_info['asset_tag']} - {$asset_info['asset_name']} (Type: $maintenance_type)";
            mysqli_query($mysqli, "INSERT INTO logs SET log_type = 'Asset', log_action = 'Maintenance', log_description = '$log_description', log_ip = '$session_ip', log_user_agent = '$session_user_agent', log_user_id = $session_user_id");
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Maintenance record created successfully!";
            header("Location: asset_maintenance.php");
            exit;
        } else {
            throw new Exception("Database error");
        }
    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error creating maintenance record: " . $mysqli->error;
        header("Location: asset_maintenance_new.php");
        exit;
    }
}

// Get recent maintenance for selected assets
$recent_maintenance_sql = "
    SELECT am.*, a.asset_tag, a.asset_name, u.user_name as performed_by_name
    FROM asset_maintenance am
    JOIN assets a ON am.asset_id = a.asset_id
    LEFT JOIN users u ON am.performed_by = u.user_id
    ORDER BY am.maintenance_date DESC
    LIMIT 5
";
$recent_maintenance_result = $mysqli->query($recent_maintenance_sql);
$recent_maintenance = [];
while ($maintenance = $recent_maintenance_result->fetch_assoc()) {
    $recent_maintenance[] = $maintenance;
}
?>

<div class="card">
    <div class="card-header bg-warning py-2">
        <h3 class="card-title mt-2 mb-0 text-dark"><i class="fas fa-fw fa-tools mr-2"></i>New Maintenance Record</h3>
        <div class="card-tools">
            <a href="asset_maintenance.php" class="btn btn-light">
                <i class="fas fa-arrow-left mr-2"></i>Back to Maintenance
            </a>
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
                            <h3 class="card-title"><i class="fas fa-cube mr-2"></i>Select Asset</h3>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label for="asset_id">Asset *</label>
                                <select class="form-control select2" id="asset_id" name="asset_id" required>
                                    <option value="">- Select Asset -</option>
                                    <?php foreach ($assets as $asset): ?>
                                        <option value="<?php echo $asset['asset_id']; ?>" 
                                                data-category="<?php echo htmlspecialchars($asset['category_name']); ?>"
                                                data-location="<?php echo htmlspecialchars($asset['location_name']); ?>"
                                                data-condition="<?php echo htmlspecialchars($asset['asset_condition']); ?>"
                                                data-value="<?php echo number_format($asset['current_value'], 2); ?>">
                                            <?php echo htmlspecialchars($asset['asset_tag'] . ' - ' . $asset['asset_name']); ?>
                                            <?php if ($asset['serial_number']): ?>
                                                (SN: <?php echo htmlspecialchars($asset['serial_number']); ?>)
                                            <?php endif; ?>
                                            - <?php echo htmlspecialchars(ucfirst($asset['status'])); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="form-text text-muted">Select the asset requiring maintenance</small>
                            </div>
                            
                            <div class="row mt-3" id="assetDetails" style="display: none;">
                                <div class="col-md-3">
                                    <div class="info-box bg-light">
                                        <span class="info-box-icon"><i class="fas fa-tag"></i></span>
                                        <div class="info-box-content">
                                            <span class="info-box-text">Category</span>
                                            <span id="assetCategory" class="info-box-number">-</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="info-box bg-light">
                                        <span class="info-box-icon"><i class="fas fa-map-marker-alt"></i></span>
                                        <div class="info-box-content">
                                            <span class="info-box-text">Location</span>
                                            <span id="assetLocation" class="info-box-number">-</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="info-box bg-light">
                                        <span class="info-box-icon"><i class="fas fa-heartbeat"></i></span>
                                        <div class="info-box-content">
                                            <span class="info-box-text">Condition</span>
                                            <span id="assetCondition" class="info-box-number">-</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="info-box bg-light">
                                        <span class="info-box-icon"><i class="fas fa-dollar-sign"></i></span>
                                        <div class="info-box-content">
                                            <span class="info-box-text">Current Value</span>
                                            <span id="assetValue" class="info-box-number">$0.00</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Maintenance Details -->
                    <div class="card card-warning">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-tools mr-2"></i>Maintenance Details</h3>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="maintenance_type">Maintenance Type *</label>
                                        <select class="form-control" id="maintenance_type" name="maintenance_type" required>
                                            <option value="">- Select Type -</option>
                                            <option value="preventive">Preventive Maintenance</option>
                                            <option value="corrective">Corrective Maintenance</option>
                                            <option value="calibration">Calibration</option>
                                            <option value="inspection">Inspection</option>
                                            <option value="upgrade">Upgrade/Enhancement</option>
                                            <option value="repair">Repair</option>
                                        </select>
                                        <small class="form-text text-muted">Type of maintenance being performed</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="performed_by">Performed By</label>
                                        <select class="form-control select2" id="performed_by" name="performed_by">
                                            <option value="">- Internal Staff -</option>
                                            <?php foreach ($users as $user): ?>
                                                <option value="<?php echo $user['user_id']; ?>" <?php echo $user['user_id'] == $session_user_id ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($user['user_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="form-text text-muted">Person responsible for the maintenance</small>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="supplier_id">Supplier/Vendor</label>
                                        <select class="form-control select2" id="supplier_id" name="supplier_id">
                                            <option value="">- No Supplier -</option>
                                            <?php foreach ($suppliers as $supplier): ?>
                                                <option value="<?php echo $supplier['supplier_id']; ?>">
                                                    <?php echo htmlspecialchars($supplier['supplier_name']); ?>
                                                    <?php if ($supplier['supplier_type']): ?>
                                                        (<?php echo htmlspecialchars($supplier['supplier_type']); ?>)
                                                    <?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="form-text text-muted">External vendor if maintenance is outsourced</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="cost">Cost ($)</label>
                                        <input type="number" class="form-control" id="cost" name="cost" 
                                               min="0" step="0.01" value="0.00">
                                        <small class="form-text text-muted">Cost of maintenance, parts, or service</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Maintenance Description -->
                    <div class="card card-info">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-clipboard mr-2"></i>Maintenance Description</h3>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label for="description">Description *</label>
                                <textarea class="form-control" id="description" name="description" rows="3" 
                                          placeholder="Describe the maintenance work to be performed..." required maxlength="500"></textarea>
                                <small class="form-text text-muted">Detailed description of the maintenance work</small>
                            </div>

                            <div class="form-group">
                                <label for="findings">Findings/Issues Identified</label>
                                <textarea class="form-control" id="findings" name="findings" rows="2" 
                                          placeholder="Any issues or problems identified during inspection..." maxlength="500"></textarea>
                                <small class="form-text text-muted">Problems or issues that need to be addressed</small>
                            </div>

                            <div class="form-group">
                                <label for="recommendations">Recommendations</label>
                                <textarea class="form-control" id="recommendations" name="recommendations" rows="2" 
                                          placeholder="Recommended actions or solutions..." maxlength="500"></textarea>
                                <small class="form-text text-muted">Suggested solutions or actions</small>
                            </div>
                        </div>
                    </div>

                    <!-- Schedule Information -->
                    <div class="card card-success">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-calendar-alt mr-2"></i>Schedule Information</h3>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="maintenance_date">Maintenance Date *</label>
                                        <input type="date" class="form-control" id="maintenance_date" 
                                               name="maintenance_date" value="<?php echo date('Y-m-d'); ?>" required>
                                        <small class="form-text text-muted">When maintenance was/will be performed</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="scheduled_date">Scheduled Date</label>
                                        <input type="date" class="form-control" id="scheduled_date" 
                                               name="scheduled_date">
                                        <small class="form-text text-muted">When maintenance is scheduled (if different)</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="next_maintenance_date">Next Maintenance Date</label>
                                        <input type="date" class="form-control" id="next_maintenance_date" 
                                               name="next_maintenance_date">
                                        <small class="form-text text-muted">When next maintenance is due</small>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="next_maintenance_notes">Next Maintenance Notes</label>
                                <textarea class="form-control" id="next_maintenance_notes" name="next_maintenance_notes" rows="2" 
                                          placeholder="Notes for next maintenance (parts needed, special requirements, etc.)..." maxlength="500"></textarea>
                                <small class="form-text text-muted">Information to assist with next maintenance</small>
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
                                    <i class="fas fa-save mr-2"></i>Create Maintenance
                                </button>
                                <button type="reset" class="btn btn-outline-secondary" onclick="resetForm()">
                                    <i class="fas fa-undo mr-2"></i>Reset Form
                                </button>
                                <a href="asset_maintenance.php" class="btn btn-outline-danger">
                                    <i class="fas fa-times mr-2"></i>Cancel
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Maintenance Preview -->
                    <div class="card card-info">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-eye mr-2"></i>Maintenance Preview</h3>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-3">
                                <i class="fas fa-tools fa-3x text-info mb-2"></i>
                                <h5 id="preview_asset">Select an Asset</h5>
                                <div id="preview_type" class="text-muted">-</div>
                            </div>
                            <hr>
                            <div class="small">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Maintenance Date:</span>
                                    <span id="preview_date" class="font-weight-bold"><?php echo date('M d, Y'); ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Scheduled Date:</span>
                                    <span id="preview_scheduled" class="font-weight-bold">Not set</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Performed By:</span>
                                    <span id="preview_performer" class="font-weight-bold">-</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Cost:</span>
                                    <span id="preview_cost" class="font-weight-bold">$0.00</span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Status:</span>
                                    <span class="badge badge-warning">Scheduled</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Maintenance Statistics -->
                    <div class="card card-warning">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-chart-bar mr-2"></i>Maintenance Statistics</h3>
                        </div>
                        <div class="card-body">
                            <div class="list-group list-group-flush">
                                <div class="list-group-item px-0 py-2">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1">Total Records</h6>
                                        <span class="badge badge-primary badge-pill"><?php echo $maintenance_stats['total_maintenance']; ?></span>
                                    </div>
                                    <small class="text-muted">All maintenance records</small>
                                </div>
                                <div class="list-group-item px-0 py-2">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1">Scheduled</h6>
                                        <span class="badge badge-warning badge-pill"><?php echo $maintenance_stats['scheduled']; ?></span>
                                    </div>
                                    <small class="text-muted">Planned maintenance</small>
                                </div>
                                <div class="list-group-item px-0 py-2">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1">In Progress</h6>
                                        <span class="badge badge-info badge-pill"><?php echo $maintenance_stats['in_progress']; ?></span>
                                    </div>
                                    <small class="text-muted">Ongoing maintenance</small>
                                </div>
                                <div class="list-group-item px-0 py-2">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1">Completed</h6>
                                        <span class="badge badge-success badge-pill"><?php echo $maintenance_stats['completed']; ?></span>
                                    </div>
                                    <small class="text-muted">Finished maintenance</small>
                                </div>
                                <div class="list-group-item px-0 py-2">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1">Overdue</h6>
                                        <span class="badge badge-danger badge-pill"><?php echo $maintenance_stats['overdue']; ?></span>
                                    </div>
                                    <small class="text-muted">Past scheduled date</small>
                                </div>
                                <div class="list-group-item px-0 py-2">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1">Total Cost</h6>
                                        <span class="badge badge-dark badge-pill">$<?php echo number_format($maintenance_stats['total_cost'], 0); ?></span>
                                    </div>
                                    <small class="text-muted">Cumulative maintenance cost</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Maintenance -->
                    <div class="card card-secondary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-history mr-2"></i>Recent Maintenance</h3>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($recent_maintenance)): ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($recent_maintenance as $maintenance): ?>
                                        <div class="list-group-item px-0 py-2">
                                            <div class="d-flex w-100 justify-content-between align-items-center">
                                                <div>
                                                    <h6 class="mb-1"><?php echo htmlspecialchars($maintenance['asset_tag']); ?></h6>
                                                    <small class="text-muted">
                                                        <?php echo ucfirst($maintenance['maintenance_type']); ?> - 
                                                        <?php echo htmlspecialchars(substr($maintenance['description'], 0, 30)); ?>...
                                                    </small>
                                                </div>
                                                <span class="badge badge-<?php 
                                                    switch($maintenance['status']) {
                                                        case 'completed': echo 'success'; break;
                                                        case 'in_progress': echo 'info'; break;
                                                        case 'scheduled': echo 'warning'; break;
                                                        default: echo 'secondary';
                                                    }
                                                ?>">
                                                    <?php echo ucfirst($maintenance['status']); ?>
                                                </span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted text-center mb-0">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    No recent maintenance
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Quick Tips -->
                    <div class="card card-primary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-lightbulb mr-2"></i>Quick Tips</h3>
                        </div>
                        <div class="card-body">
                            <div class="callout callout-info small mb-3">
                                <i class="fas fa-info-circle mr-2"></i>
                                <strong>Preventive vs Corrective:</strong> Preventive maintenance prevents issues, corrective fixes existing problems.
                            </div>
                            <div class="callout callout-warning small mb-3">
                                <i class="fas fa-exclamation-triangle mr-2"></i>
                                <strong>Detailed Notes:</strong> Include detailed findings and recommendations for future reference.
                            </div>
                            <div class="callout callout-success small">
                                <i class="fas fa-check-circle mr-2"></i>
                                <strong>Schedule Next:</strong> Always schedule next maintenance date to ensure timely follow-up.
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

    // Show asset details when asset is selected
    $('#asset_id').on('change', function() {
        var selectedOption = $(this).find('option:selected');
        var assetDetails = $('#assetDetails');
        
        if ($(this).val()) {
            $('#preview_asset').text(selectedOption.text().split(' - ')[0] || selectedOption.text());
            
            $('#assetCategory').text(selectedOption.data('category') || '-');
            $('#assetLocation').text(selectedOption.data('location') || '-');
            $('#assetCondition').text(selectedOption.data('condition') || '-');
            $('#assetValue').text('$' + (selectedOption.data('value') || '0.00'));
            
            assetDetails.show();
        } else {
            assetDetails.hide();
            $('#preview_asset').text('Select an Asset');
        }
    });

    // Update preview when maintenance type is selected
    $('#maintenance_type').on('change', function() {
        var selectedText = $(this).find('option:selected').text();
        $('#preview_type').text(selectedText || '-');
    });

    // Update preview when performed by is selected
    $('#performed_by').on('change', function() {
        var selectedText = $(this).find('option:selected').text();
        $('#preview_performer').text(selectedText || '-');
    });

    // Update preview for maintenance date
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

    // Update preview for scheduled date
    $('#scheduled_date').on('change', function() {
        var date = new Date($(this).val());
        if (!isNaN(date.getTime())) {
            $('#preview_scheduled').text(date.toLocaleDateString('en-US', { 
                month: 'short', 
                day: 'numeric', 
                year: 'numeric' 
            }));
        } else {
            $('#preview_scheduled').text('Not set');
        }
    });

    // Update preview for cost
    $('#cost').on('input', function() {
        var cost = parseFloat($(this).val()) || 0;
        $('#preview_cost').text('$' + cost.toFixed(2));
    });

    // Set default scheduled date (tomorrow) if maintenance type is preventive
    $('#maintenance_type').on('change', function() {
        if ($(this).val() === 'preventive') {
            var tomorrow = new Date();
            tomorrow.setDate(tomorrow.getDate() + 1);
            $('#scheduled_date').val(tomorrow.toISOString().split('T')[0]);
            
            // Set next maintenance date (30 days from now)
            var nextDate = new Date();
            nextDate.setDate(nextDate.getDate() + 30);
            $('#next_maintenance_date').val(nextDate.toISOString().split('T')[0]);
            
            // Trigger change events
            $('#scheduled_date').trigger('change');
        }
    });

    // Enhanced form validation
    $('#maintenanceForm').on('submit', function(e) {
        var requiredFields = ['asset_id', 'maintenance_type', 'maintenance_date', 'description'];
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
        var today = new Date();
        
        if (maintenanceDate > today) {
            // Allow future dates for scheduling
            // No validation needed
        }
        
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
        $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Creating...').prop('disabled', true);
    });

    // Initialize preview
    $('#maintenance_type').trigger('change');
    $('#performed_by').trigger('change');
    $('#cost').trigger('input');
});

function resetForm() {
    if (confirm('Are you sure you want to reset all changes? All entered data will be lost.')) {
        $('#maintenanceForm')[0].reset();
        $('.select2').val('').trigger('change');
        $('#maintenance_date').val('<?php echo date("Y-m-d"); ?>');
        $('#maintenance_type').trigger('change');
        $('#asset_id').trigger('change');
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
        window.location.href = 'asset_maintenance.php';
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
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
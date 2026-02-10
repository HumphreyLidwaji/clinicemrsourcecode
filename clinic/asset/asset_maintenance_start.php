<?php
// asset_maintenance_start.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Check if asset ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid asset ID";
    header("Location: asset_mainteinace.php");
    exit;
}

$asset_id = intval($_GET['id']);

// Get asset details
$asset_sql = "
    SELECT a.*, 
           ac.category_name,
           al.location_name,
           CONCAT(u.user_name, ' (', u.user_email, ')') as last_updated_by_name
    FROM assets a
    LEFT JOIN asset_categories ac ON a.category_id = ac.category_id
    LEFT JOIN asset_locations al ON a.location_id = al.location_id
    LEFT JOIN users u ON a.updated_by = u.user_id
    WHERE a.asset_id = ?
";

$asset_stmt = $mysqli->prepare($asset_sql);
$asset_stmt->bind_param("i", $asset_id);
$asset_stmt->execute();
$asset_result = $asset_stmt->get_result();

if ($asset_result->num_rows === 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Asset not found";
    header("Location: assets.php");
    exit;
}

$asset = $asset_result->fetch_assoc();

// Check if asset is already in maintenance
if ($asset['status'] == 'maintenance') {
    $_SESSION['alert_type'] = "warning";
    $_SESSION['alert_message'] = "Asset is already in maintenance";
    header("Location: asset_details.php?id=$asset_id");
    exit;
}

// Check if asset is checked out (should be checked in first)
if ($asset['status'] == 'checked_out') {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Asset must be checked in before starting maintenance";
    header("Location: asset_details.php?id=$asset_id");
    exit;
}

// Get maintenance types/categories
$maintenance_types_sql = "
    SELECT type_id, type_name, description 
    FROM asset_maintenance_types 
    WHERE is_active = 1 
    ORDER BY type_name
";
$maintenance_types_result = $mysqli->query($maintenance_types_sql);
$maintenance_types = [];
while ($type = $maintenance_types_result->fetch_assoc()) {
    $maintenance_types[] = $type;
}

// Get technicians/assignees
$technicians_sql = "
    SELECT employee_id, CONCAT(first_name, ' ', last_name) as employee_name, employee_title
    FROM employees 
    WHERE employee_status = 'active' 
    ORDER BY first_name, last_name
";
$technicians_result = $mysqli->query($technicians_sql);
$technicians = [];
while ($tech = $technicians_result->fetch_assoc()) {
    $technicians[] = $tech;
}

// Get vendors/suppliers
$vendors_sql = "
    SELECT vendor_id, vendor_name, vendor_type, contact_email, contact_phone
    FROM vendors 
    WHERE vendor_status = 'active' 
    ORDER BY vendor_name
";
$vendors_result = $mysqli->query($vendors_sql);
$vendors = [];
while ($vendor = $vendors_result->fetch_assoc()) {
    $vendors[] = $vendor;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    
    if ($csrf_token != $_SESSION['csrf_token']) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "CSRF token validation failed";
        header("Location: asset_maintenance_start.php?id=$asset_id");
        exit;
    }
    
    $maintenance_type_id = !empty($_POST['maintenance_type_id']) ? intval($_POST['maintenance_type_id']) : null;
    $maintenance_title = sanitizeInput($_POST['maintenance_title']);
    $maintenance_description = sanitizeInput($_POST['maintenance_description']);
    $scheduled_start_date = sanitizeInput($_POST['scheduled_start_date']);
    $estimated_completion_date = !empty($_POST['estimated_completion_date']) ? sanitizeInput($_POST['estimated_completion_date']) : null;
    $priority = sanitizeInput($_POST['priority']);
    $assigned_to_id = !empty($_POST['assigned_to_id']) ? intval($_POST['assigned_to_id']) : null;
    $vendor_id = !empty($_POST['vendor_id']) ? intval($_POST['vendor_id']) : null;
    $estimated_cost = !empty($_POST['estimated_cost']) ? floatval($_POST['estimated_cost']) : null;
    $maintenance_notes = sanitizeInput($_POST['maintenance_notes']);
    $created_by = $session_user_id;
    
    // Validate required fields
    if (empty($maintenance_title) || empty($scheduled_start_date) || empty($priority)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please fill in all required fields";
        header("Location: asset_maintenance_start.php?id=$asset_id");
        exit;
    }
    
    // Validate date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $scheduled_start_date)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid start date format. Use YYYY-MM-DD";
        header("Location: asset_maintenance_start.php?id=$asset_id");
        exit;
    }
    
    if ($estimated_completion_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $estimated_completion_date)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid completion date format. Use YYYY-MM-DD";
        header("Location: asset_maintenance_start.php?id=$asset_id");
        exit;
    }
    
    // Validate dates
    $start_timestamp = strtotime($scheduled_start_date);
    if ($start_timestamp === false) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid start date";
        header("Location: asset_maintenance_start.php?id=$asset_id");
        exit;
    }
    
    if ($estimated_completion_date) {
        $completion_timestamp = strtotime($estimated_completion_date);
        if ($completion_timestamp === false) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Invalid completion date";
            header("Location: asset_maintenance_start.php?id=$asset_id");
            exit;
        }
        
        if ($completion_timestamp < $start_timestamp) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Completion date cannot be before start date";
            header("Location: asset_maintenance_start.php?id=$asset_id");
            exit;
        }
    }
    
    // Start transaction
    $mysqli->begin_transaction();
    
    try {
        // Insert maintenance record
        $insert_sql = "
            INSERT INTO asset_maintenance 
            (asset_id, maintenance_type_id, maintenance_title, maintenance_description, 
             scheduled_start_date, estimated_completion_date, priority, 
             assigned_to_id, vendor_id, estimated_cost, maintenance_notes, 
             status, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'scheduled', ?, NOW())
        ";
        
        $insert_stmt = $mysqli->prepare($insert_sql);
        $insert_stmt->bind_param(
            "issssssiidsi",
            $asset_id,
            $maintenance_type_id,
            $maintenance_title,
            $maintenance_description,
            $scheduled_start_date,
            $estimated_completion_date,
            $priority,
            $assigned_to_id,
            $vendor_id,
            $estimated_cost,
            $maintenance_notes,
            $created_by
        );
        
        // Update asset status to maintenance
        $update_asset_sql = "
            UPDATE assets 
            SET status = 'maintenance', 
                updated_by = ?, 
                updated_at = NOW() 
            WHERE asset_id = ?
        ";
        
        $update_stmt = $mysqli->prepare($update_asset_sql);
        $update_stmt->bind_param("ii", $session_user_id, $asset_id);
        
        if ($insert_stmt->execute() && $update_stmt->execute()) {
            $maintenance_id = $insert_stmt->insert_id;
            $mysqli->commit();
            
            // Log activity
            $log_description = "Maintenance scheduled for asset: {$asset['asset_tag']} - {$asset['asset_name']} (Maintenance ID: $maintenance_id)";
            mysqli_query($mysqli, "INSERT INTO logs SET log_type = 'Asset Maintenance', log_action = 'Schedule', log_description = '$log_description', log_ip = '$session_ip', log_user_agent = '$session_user_agent', log_user_id = $session_user_id");
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Maintenance scheduled successfully!";
            header("Location: asset_maintenance_details.php?id=$maintenance_id");
            exit;
        } else {
            throw new Exception("Database error: " . $mysqli->error);
        }
    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error scheduling maintenance: " . $e->getMessage();
        header("Location: asset_maintenance_start.php?id=$asset_id");
        exit;
    }
}
?>

<div class="card">
    <div class="card-header bg-warning py-2">
        <h3 class="card-title mt-2 mb-0 text-white"><i class="fas fa-fw fa-tools mr-2"></i>Schedule Maintenance</h3>
        <div class="card-tools">
            <a href="asset_details.php?id=<?php echo $asset_id; ?>" class="btn btn-light">
                <i class="fas fa-arrow-left mr-2"></i>Back to Asset
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

        <!-- Asset Information Card -->
        <div class="card card-info mb-4">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-cube mr-2"></i>Asset Information</h3>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <dl class="row">
                            <dt class="col-sm-4">Asset Tag:</dt>
                            <dd class="col-sm-8"><?php echo htmlspecialchars($asset['asset_tag']); ?></dd>
                            
                            <dt class="col-sm-4">Asset Name:</dt>
                            <dd class="col-sm-8"><?php echo htmlspecialchars($asset['asset_name']); ?></dd>
                            
                            <dt class="col-sm-4">Serial Number:</dt>
                            <dd class="col-sm-8"><?php echo htmlspecialchars($asset['serial_number'] ?: 'N/A'); ?></dd>
                        </dl>
                    </div>
                    <div class="col-md-4">
                        <dl class="row">
                            <dt class="col-sm-4">Category:</dt>
                            <dd class="col-sm-8"><?php echo htmlspecialchars($asset['category_name']); ?></dd>
                            
                            <dt class="col-sm-4">Current Location:</dt>
                            <dd class="col-sm-8"><?php echo htmlspecialchars($asset['location_name'] ?: 'N/A'); ?></dd>
                            
                            <dt class="col-sm-4">Current Value:</dt>
                            <dd class="col-sm-8">$<?php echo number_format($asset['current_value'], 2); ?></dd>
                        </dl>
                    </div>
                    <div class="col-md-4">
                        <dl class="row">
                            <dt class="col-sm-4">Current Status:</dt>
                            <dd class="col-sm-8">
                                <span class="badge badge-<?php 
                                    echo $asset['status'] == 'active' ? 'success' : 
                                         ($asset['status'] == 'checked_out' ? 'primary' : 
                                         ($asset['status'] == 'maintenance' ? 'warning' : 'secondary'));
                                ?>">
                                    <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $asset['status']))); ?>
                                </span>
                            </dd>
                            
                            <dt class="col-sm-4">Purchase Date:</dt>
                            <dd class="col-sm-8"><?php echo $asset['purchase_date'] ? date('M d, Y', strtotime($asset['purchase_date'])) : 'N/A'; ?></dd>
                            
                            <dt class="col-sm-4">Warranty Expires:</dt>
                            <dd class="col-sm-8">
                                <?php if ($asset['warranty_expiry_date']): ?>
                                    <?php 
                                    $warranty_date = strtotime($asset['warranty_expiry_date']);
                                    $now = time();
                                    $days_left = floor(($warranty_date - $now) / (60 * 60 * 24));
                                    
                                    if ($days_left > 30) {
                                        $badge_class = 'success';
                                    } elseif ($days_left > 0) {
                                        $badge_class = 'warning';
                                    } else {
                                        $badge_class = 'danger';
                                    }
                                    ?>
                                    <span class="badge badge-<?php echo $badge_class; ?>">
                                        <?php echo date('M d, Y', $warranty_date); ?>
                                        (<?php echo $days_left > 0 ? $days_left . ' days left' : 'Expired'; ?>)
                                    </span>
                                <?php else: ?>
                                    N/A
                                <?php endif; ?>
                            </dd>
                        </dl>
                    </div>
                </div>
            </div>
        </div>

        <!-- Maintenance Form -->
        <form method="POST" id="maintenanceForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <div class="row">
                <div class="col-md-8">
                    <!-- Maintenance Details -->
                    <div class="card card-primary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-clipboard-list mr-2"></i>Maintenance Details</h3>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="maintenance_title">Maintenance Title *</label>
                                        <input type="text" class="form-control" id="maintenance_title" 
                                               name="maintenance_title" required 
                                               placeholder="e.g., Routine Servicing, Hardware Repair, Software Update">
                                        <small class="form-text text-muted">Brief description of the maintenance work</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="maintenance_type_id">Maintenance Type</label>
                                        <select class="form-control select2" id="maintenance_type_id" name="maintenance_type_id">
                                            <option value="">- Select Type -</option>
                                            <?php foreach ($maintenance_types as $type): ?>
                                                <option value="<?php echo $type['type_id']; ?>">
                                                    <?php echo htmlspecialchars($type['type_name']); ?>
                                                    <?php if ($type['description']): ?>
                                                        - <?php echo htmlspecialchars($type['description']); ?>
                                                    <?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="form-text text-muted">Category of maintenance work</small>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="maintenance_description">Detailed Description *</label>
                                <textarea class="form-control" id="maintenance_description" name="maintenance_description" 
                                          rows="3" required placeholder="Describe the maintenance required, issues to fix, etc..."></textarea>
                                <small class="form-text text-muted">Detailed explanation of the maintenance work needed</small>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="priority">Priority *</label>
                                        <select class="form-control" id="priority" name="priority" required>
                                            <option value="low">Low - Routine maintenance</option>
                                            <option value="medium" selected>Medium - Standard priority</option>
                                            <option value="high">High - Important, needs attention</option>
                                            <option value="critical">Critical - Urgent, affects operations</option>
                                        </select>
                                        <small class="form-text text-muted">How urgent is this maintenance?</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="estimated_cost">Estimated Cost ($)</label>
                                        <div class="input-group">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text">$</span>
                                            </div>
                                            <input type="number" class="form-control" id="estimated_cost" 
                                                   name="estimated_cost" step="0.01" min="0" 
                                                   placeholder="0.00">
                                        </div>
                                        <small class="form-text text-muted">Estimated cost for parts and labor</small>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="maintenance_notes">Additional Notes</label>
                                <textarea class="form-control" id="maintenance_notes" name="maintenance_notes" 
                                          rows="2" placeholder="Special instructions, safety considerations, etc..."></textarea>
                                <small class="form-text text-muted">Any additional information about this maintenance</small>
                            </div>
                        </div>
                    </div>

                    <!-- Schedule & Assignment -->
                    <div class="card card-success">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-calendar-alt mr-2"></i>Schedule & Assignment</h3>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="scheduled_start_date">Scheduled Start Date *</label>
                                        <input type="date" class="form-control" id="scheduled_start_date" 
                                               name="scheduled_start_date" required 
                                               value="<?php echo date('Y-m-d'); ?>">
                                        <small class="form-text text-muted">When maintenance is scheduled to begin</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="estimated_completion_date">Estimated Completion Date</label>
                                        <input type="date" class="form-control" id="estimated_completion_date" 
                                               name="estimated_completion_date">
                                        <small class="form-text text-muted">When maintenance is expected to be completed (optional)</small>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="assigned_to_id">Assign to Technician</label>
                                        <select class="form-control select2" id="assigned_to_id" name="assigned_to_id">
                                            <option value="">- Select Technician -</option>
                                            <?php foreach ($technicians as $tech): ?>
                                                <option value="<?php echo $tech['employee_id']; ?>">
                                                    <?php echo htmlspecialchars($tech['employee_name']); ?>
                                                    <?php if ($tech['employee_title']): ?>
                                                        (<?php echo htmlspecialchars($tech['employee_title']); ?>)
                                                    <?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="form-text text-muted">Internal technician responsible for this maintenance</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="vendor_id">External Vendor/Supplier</label>
                                        <select class="form-control select2" id="vendor_id" name="vendor_id">
                                            <option value="">- Select Vendor -</option>
                                            <?php foreach ($vendors as $vendor): ?>
                                                <option value="<?php echo $vendor['vendor_id']; ?>">
                                                    <?php echo htmlspecialchars($vendor['vendor_name']); ?>
                                                    <?php if ($vendor['vendor_type']): ?>
                                                        (<?php echo htmlspecialchars($vendor['vendor_type']); ?>)
                                                    <?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="form-text text-muted">External vendor if maintenance is outsourced</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <!-- Quick Actions -->
                    <div class="card card-primary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-bolt mr-2"></i>Quick Actions</h3>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-warning btn-lg">
                                    <i class="fas fa-calendar-check mr-2"></i>Schedule Maintenance
                                </button>
                                <a href="asset_details.php?id=<?php echo $asset_id; ?>" class="btn btn-outline-danger">
                                    <i class="fas fa-times mr-2"></i>Cancel
                                </a>
                            </div>
                            
                            <hr>
                            
                            <div class="alert alert-info">
                                <h6><i class="fas fa-info-circle mr-2"></i>Maintenance Information</h6>
                                <small>
                                    <div class="mb-2">
                                        <strong>Asset:</strong><br>
                                        <?php echo htmlspecialchars($asset['asset_tag'] . ' - ' . $asset['asset_name']); ?>
                                    </div>
                                    <div class="mb-2">
                                        <strong>Current Status:</strong><br>
                                        <?php echo htmlspecialchars(ucfirst($asset['status'])); ?>
                                    </div>
                                    <div>
                                        <strong>Initiated by:</strong><br>
                                        <?php echo htmlspecialchars($_SESSION['user_name']); ?>
                                    </div>
                                </small>
                            </div>
                        </div>
                    </div>

                    <!-- Maintenance Statistics -->
                    <div class="card card-warning mb-4">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-chart-bar mr-2"></i>Maintenance History</h3>
                        </div>
                        <div class="card-body">
                            <?php
                            // Get maintenance history for this asset
                            $history_sql = "
                                SELECT COUNT(*) as total_maintenance,
                                       SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                                       SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                                       SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
                                FROM asset_maintenance
                                WHERE asset_id = ?
                            ";
                            $history_stmt = $mysqli->prepare($history_sql);
                            $history_stmt->bind_param("i", $asset_id);
                            $history_stmt->execute();
                            $history_result = $history_stmt->get_result();
                            $history = $history_result->fetch_assoc();
                            ?>
                            <div class="list-group list-group-flush">
                                <div class="list-group-item px-0 py-2">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1">Total Maintenance</h6>
                                        <span class="badge badge-primary badge-pill"><?php echo $history['total_maintenance']; ?></span>
                                    </div>
                                    <small class="text-muted">All time maintenance records</small>
                                </div>
                                <div class="list-group-item px-0 py-2">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1">Completed</h6>
                                        <span class="badge badge-success badge-pill"><?php echo $history['completed']; ?></span>
                                    </div>
                                    <small class="text-muted">Successfully completed</small>
                                </div>
                                <div class="list-group-item px-0 py-2">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1">In Progress</h6>
                                        <span class="badge badge-warning badge-pill"><?php echo $history['in_progress']; ?></span>
                                    </div>
                                    <small class="text-muted">Currently ongoing</small>
                                </div>
                                <div class="list-group-item px-0 py-2">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1">Cancelled</h6>
                                        <span class="badge badge-danger badge-pill"><?php echo $history['cancelled']; ?></span>
                                    </div>
                                    <small class="text-muted">Cancelled maintenance</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Tips -->
                    <div class="card card-info">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-lightbulb mr-2"></i>Maintenance Tips</h3>
                        </div>
                        <div class="card-body">
                            <div class="callout callout-info small mb-3">
                                <i class="fas fa-info-circle mr-2"></i>
                                <strong>Detailed Description:</strong> Provide clear details to help technicians understand the work needed.
                            </div>
                            <div class="callout callout-warning small mb-3">
                                <i class="fas fa-exclamation-triangle mr-2"></i>
                                <strong>Realistic Scheduling:</strong> Set achievable dates considering part availability and technician workload.
                            </div>
                            <div class="callout callout-success small">
                                <i class="fas fa-check-circle mr-2"></i>
                                <strong>Cost Estimation:</strong> Include estimated costs for budgeting and approval processes.
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

    // Set default estimated completion date (7 days from now)
    var defaultCompletionDate = new Date();
    defaultCompletionDate.setDate(defaultCompletionDate.getDate() + 7);
    $('#estimated_completion_date').val(defaultCompletionDate.toISOString().split('T')[0]);

    // Form validation
    $('#maintenanceForm').on('submit', function(e) {
        var requiredFields = ['maintenance_title', 'maintenance_description', 'scheduled_start_date', 'priority'];
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
        var startDate = new Date($('#scheduled_start_date').val());
        var completionDate = $('#estimated_completion_date').val() ? new Date($('#estimated_completion_date').val()) : null;
        var today = new Date();
        
        // Check if start date is in the past
        if (startDate < new Date(today.getFullYear(), today.getMonth(), today.getDate())) {
            isValid = false;
            errorMessages.push('Start date cannot be in the past');
            $('#scheduled_start_date').addClass('is-invalid');
        }
        
        // Check if completion date is before start date
        if (completionDate && completionDate < startDate) {
            isValid = false;
            errorMessages.push('Completion date cannot be before start date');
            $('#estimated_completion_date').addClass('is-invalid');
        }

        // Validate cost if provided
        var estimatedCost = $('#estimated_cost').val();
        if (estimatedCost && parseFloat(estimatedCost) < 0) {
            isValid = false;
            errorMessages.push('Estimated cost cannot be negative');
            $('#estimated_cost').addClass('is-invalid');
        }

        if (!isValid) {
            e.preventDefault();
            var errorMessage = 'Please fix the following errors:\n• ' + errorMessages.join('\n• ');
            alert(errorMessage);
            return false;
        }

        // Show loading state
        $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Scheduling...').prop('disabled', true);
    });

    // Keyboard shortcuts
    $(document).keydown(function(e) {
        // Ctrl + S to save
        if (e.ctrlKey && e.keyCode === 83) {
            e.preventDefault();
            $('#maintenanceForm').submit();
        }
        // Escape to cancel
        if (e.keyCode === 27) {
            window.location.href = 'asset_details.php?id=<?php echo $asset_id; ?>';
        }
    });
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

.is-invalid {
    border-color: #dc3545;
    box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25);
}

.btn:disabled {
    cursor: not-allowed;
    opacity: 0.6;
}

dl.row dt {
    font-weight: 600;
    color: #6c757d;
}

dl.row dd {
    color: #212529;
}

.input-group-text {
    background-color: #e9ecef;
    border-color: #ced4da;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
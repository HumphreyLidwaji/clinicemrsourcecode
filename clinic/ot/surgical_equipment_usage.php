<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

$case_id = intval($_GET['case_id']);

// Get case details
$case_sql = "SELECT sc.*, p.first_name, p.last_name, p.patient_mrn 
             FROM surgical_cases sc 
             LEFT JOIN patients p ON sc.patient_id = p.patient_id 
             WHERE sc.case_id = $case_id";
$case_result = $mysqli->query($case_sql);

if ($case_result->num_rows == 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Surgical case not found";
    header("Location: theatre_dashboard.php");
    exit();
}

$case = $case_result->fetch_assoc();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_equipment_usage'])) {
        $asset_id = intval($_POST['asset_id']);
        $quantity_used = intval($_POST['quantity_used']);
        $usage_start_time = sanitizeInput($_POST['usage_start_time']);
        $usage_end_time = sanitizeInput($_POST['usage_end_time']);
        $notes = sanitizeInput($_POST['notes']);
        
        // Check if equipment is available
        $asset_sql = "SELECT status, location_id FROM assets WHERE asset_id = $asset_id";
        $asset_result = $mysqli->query($asset_sql);
        $asset = $asset_result->fetch_assoc();
        
        if ($asset['status'] == 'maintenance' || $asset['status'] == 'inactive') {
            $_SESSION['alert_type'] = "warning";
            $_SESSION['alert_message'] = "Selected equipment is not available (status: " . $asset['status'] . ")";
        } else {
            // Insert equipment usage
            $insert_sql = "INSERT INTO surgical_equipment_usage SET
                          case_id = $case_id,
                          asset_id = $asset_id,
                          quantity_used = $quantity_used,
                          usage_start_time = " . ($usage_start_time ? "'$usage_start_time'" : "NULL") . ",
                          usage_end_time = " . ($usage_end_time ? "'$usage_end_time'" : "NULL") . ",
                          notes = '$notes',
                          created_by = " . intval($_SESSION['user_id']);
            
            if ($mysqli->query($insert_sql)) {
                // Update asset status to 'in-use' if not already
                $update_asset_sql = "UPDATE assets SET status = 'in-use', updated_at = NOW() WHERE asset_id = $asset_id";
                $mysqli->query($update_asset_sql);
                
                // Log activity
                $asset_name_sql = "SELECT asset_name FROM assets WHERE asset_id = $asset_id";
                $asset_name_result = $mysqli->query($asset_name_sql);
                $asset_name = $asset_name_result->fetch_assoc()['asset_name'];
                
                $log_description = "Added equipment usage: $asset_name for case: " . $case['case_number'];
                mysqli_query($mysqli, "INSERT INTO logs SET log_type = 'Equipment Usage', log_action = 'Add', log_description = '$log_description', log_ip = '$session_ip', log_user_agent = '$session_user_agent', log_user_id = $session_user_id");
                
                $_SESSION['alert_message'] = "Equipment usage recorded successfully";
            } else {
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = "Error recording equipment usage: " . $mysqli->error;
            }
        }
        
        header("Location: surgical_equipment_usage.php?case_id=$case_id");
        exit();
    }
    
    if (isset($_POST['update_equipment_usage'])) {
        $usage_id = intval($_POST['usage_id']);
        $quantity_used = intval($_POST['quantity_used']);
        $usage_start_time = sanitizeInput($_POST['usage_start_time']);
        $usage_end_time = sanitizeInput($_POST['usage_end_time']);
        $notes = sanitizeInput($_POST['notes']);
        
        $update_sql = "UPDATE surgical_equipment_usage SET
                      quantity_used = $quantity_used,
                      usage_start_time = " . ($usage_start_time ? "'$usage_start_time'" : "NULL") . ",
                      usage_end_time = " . ($usage_end_time ? "'$usage_end_time'" : "NULL") . ",
                      notes = '$notes',
                      updated_at = NOW()
                      WHERE usage_id = $usage_id";
        
        if ($mysqli->query($update_sql)) {
            $_SESSION['alert_message'] = "Equipment usage updated successfully";
        } else {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error updating equipment usage: " . $mysqli->error;
        }
        
        header("Location: surgical_equipment_usage.php?case_id=$case_id");
        exit();
    }
    
    if (isset($_POST['remove_equipment_usage'])) {
        $usage_id = intval($_POST['usage_id']);
        
        // Get asset details for logging and status update
        $usage_sql = "SELECT a.asset_id, a.asset_name FROM surgical_equipment_usage seu 
                      JOIN assets a ON seu.asset_id = a.asset_id 
                      WHERE seu.usage_id = $usage_id";
        $usage_result = $mysqli->query($usage_sql);
        $usage = $usage_result->fetch_assoc();
        
        $delete_sql = "DELETE FROM surgical_equipment_usage WHERE usage_id = $usage_id";
        
        if ($mysqli->query($delete_sql)) {
            // Update asset status back to 'active'
            $update_asset_sql = "UPDATE assets SET status = 'active', updated_at = NOW() WHERE asset_id = " . $usage['asset_id'];
            $mysqli->query($update_asset_sql);
        }
      
        header("Location: surgical_equipment_usage.php?case_id=$case_id");
        exit();
    }
}

// Get equipment usage for this case
$usage_sql = "SELECT seu.*, 
                     a.asset_tag, a.asset_name, a.manufacturer, a.model, a.serial_number, a.is_critical,
                     u.user_name as recorded_by_name,
                     TIMESTAMPDIFF(MINUTE, seu.usage_start_time, seu.usage_end_time) as usage_duration
              FROM surgical_equipment_usage seu
              LEFT JOIN assets a ON seu.asset_id = a.asset_id
              LEFT JOIN users u ON seu.created_by = u.user_id
              WHERE seu.case_id = $case_id 
              ORDER BY seu.usage_start_time, a.asset_name";
$usage_result = $mysqli->query($usage_sql);

// Get available equipment (assets that are active and not used in this case)
$used_asset_ids = [];
while ($usage = $usage_result->fetch_assoc()) {
    $used_asset_ids[] = $usage['asset_id'];
}
mysqli_data_seek($usage_result, 0);

$exclude_ids = !empty($used_asset_ids) ? implode(',', $used_asset_ids) : '0';
$available_assets_sql = "SELECT a.*, l.location_name 
                         FROM assets a
                         LEFT JOIN locations l ON a.location_id = l.location_id
                         WHERE a.status = 'active' 
                         AND a.asset_id NOT IN ($exclude_ids)
                         ORDER BY a.asset_name";
$available_assets_result = $mysqli->query($available_assets_sql);

// Calculate usage statistics
$total_items = 0;
$total_duration = 0;
$critical_count = 0;

$temp_result = $mysqli->query($usage_sql);
while ($item = $temp_result->fetch_assoc()) {
    $total_items += $item['quantity_used'];
    $total_duration += $item['usage_duration'] ?: 0;
    
    if ($item['is_critical']) {
        $critical_count++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Equipment Usage - Case: <?php echo htmlspecialchars($case['case_number']); ?></title>
    <?php include_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php'; ?>
    <style>
        .case-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .equipment-card {
            border: 1px solid #e3e6f0;
            border-radius: 5px;
            margin-bottom: 15px;
            transition: all 0.3s;
        }
        .equipment-card.critical {
            border-left: 4px solid #dc3545;
            background-color: #fff5f5;
        }
        .equipment-card:hover {
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .equipment-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background-color: #007bff;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        .stat-card {
            border-radius: 5px;
            padding: 15px;
            color: white;
            margin-bottom: 15px;
            text-align: center;
        }
        .stat-card.primary { background-color: #007bff; }
        .stat-card.success { background-color: #28a745; }
        .stat-card.warning { background-color: #ffc107; color: #212529; }
        .stat-card.danger { background-color: #dc3545; }
        .usage-timeline {
            border-left: 3px solid #007bff;
            padding-left: 20px;
            margin-left: 10px;
        }
        .time-slot {
            background-color: #f8f9fa;
            border-radius: 5px;
            padding: 5px 10px;
            margin-bottom: 5px;
            border-left: 3px solid #28a745;
        }
        .asset-tag {
            font-family: monospace;
            background-color: #e9ecef;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 0.9em;
        }
        .clavien-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 3px;
            font-weight: bold;
            font-size: 0.9em;
            margin-right: 5px;
        }
        .clavien-I { background-color: #28a745; color: white; }
        .clavien-II { background-color: #ffc107; color: #212529; }
        .clavien-IIIa { background-color: #fd7e14; color: white; }
        .clavien-IIIb { background-color: #dc3545; color: white; }
        .clavien-IVa { background-color: #6f42c1; color: white; }
        .clavien-IVb { background-color: #e83e8c; color: white; }
        .clavien-V { background-color: #343a40; color: white; }
        .section-card {
            border: 1px solid #dee2e6;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .section-header {
            background-color: #e9ecef;
            padding: 10px 15px;
            border-bottom: 1px solid #dee2e6;
            font-weight: bold;
        }
        .section-body {
            padding: 15px;
        }
        .severity-indicator {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 8px;
        }
        .severity-minor { background-color: #28a745; }
        .severity-moderate { background-color: #ffc107; }
        .severity-severe { background-color: #fd7e14; }
        .severity-critical { background-color: #dc3545; }
    </style>
</head>
<body>
    
    <div class="container-fluid">
        <div class="row">
            <div class="col-lg-12">
                <!-- Case Header -->
                <div class="case-header">
                    <div class="row">
                        <div class="col-md-8">
                            <h3 class="mb-1"><i class="fas fa-tools mr-2"></i>Equipment Usage Management</h3>
                            <p class="mb-0">
                                Case: <?php echo htmlspecialchars($case['case_number']); ?> | 
                                Patient: <?php echo htmlspecialchars($case['first_name'] . ' ' . $case['last_name']); ?> | 
                                MRN: <?php echo htmlspecialchars($case['patient_mrn']); ?>
                            </p>
                        </div>
                        <div class="col-md-4 text-right">
                            <a href="surgical_case_view.php?id=<?php echo $case_id; ?>" class="btn btn-light btn-sm">
                                <i class="fas fa-arrow-left mr-1"></i>Back to Case
                            </a>
                            <a href="theatre_dashboard.php" class="btn btn-light btn-sm">
                                <i class="fas fa-tachometer-alt mr-1"></i>Dashboard
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- Statistics -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="stat-card primary">
                            <h3 class="mb-0"><?php echo $usage_result->num_rows; ?></h3>
                            <p class="mb-0">Equipment Items</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card success">
                            <h3 class="mb-0"><?php echo $total_items; ?></h3>
                            <p class="mb-0">Total Quantity</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card warning">
                            <h3 class="mb-0"><?php echo $total_duration > 0 ? round($total_duration / 60, 1) : 0; ?></h3>
                            <p class="mb-0">Total Hours</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card danger">
                            <h3 class="mb-0"><?php echo $critical_count; ?></h3>
                            <p class="mb-0">Critical Equipment</p>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-8">
                        <!-- Current Equipment Usage -->
                        <div class="section-card">
                            <div class="section-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">
                                    <i class="fas fa-list mr-2"></i>Equipment Used in This Case
                                    <span class="badge badge-primary"><?php echo $usage_result->num_rows; ?> items</span>
                                </h5>
                                <button class="btn btn-sm btn-primary" data-toggle="collapse" data-target="#addEquipmentForm">
                                    <i class="fas fa-plus mr-1"></i>Add Equipment
                                </button>
                            </div>
                            <div class="section-body">
                                <?php if ($usage_result->num_rows > 0): ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm">
                                            <thead>
                                                <tr>
                                                    <th>Asset</th>
                                                    <th>Quantity</th>
                                                    <th>Usage Time</th>
                                                    <th>Duration</th>
                                                    <th>Recorded By</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php while ($item = $usage_result->fetch_assoc()): ?>
                                                <tr class="<?php echo $item['is_critical'] ? 'table-danger' : ''; ?>">
                                                    <td>
                                                        <div class="font-weight-bold"><?php echo htmlspecialchars($item['asset_name']); ?></div>
                                                        <div class="small">
                                                            <span class="asset-tag"><?php echo htmlspecialchars($item['asset_tag']); ?></span>
                                                            <?php if($item['manufacturer']): ?>
                                                                <span class="text-muted"> | <?php echo htmlspecialchars($item['manufacturer']); ?></span>
                                                            <?php endif; ?>
                                                            <?php if($item['model']): ?>
                                                                <span class="text-muted"> | <?php echo htmlspecialchars($item['model']); ?></span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <?php if($item['is_critical']): ?>
                                                            <span class="badge badge-danger badge-sm mt-1"><i class="fas fa-exclamation-triangle"></i> Critical</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    
                                                    <td><?php echo $item['quantity_used']; ?></td>
                                                    <td>
                                                        <?php if($item['usage_start_time']): ?>
                                                            <div class="small">
                                                                <strong>Start:</strong> <?php echo date('H:i', strtotime($item['usage_start_time'])); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if($item['usage_end_time']): ?>
                                                            <div class="small">
                                                                <strong>End:</strong> <?php echo date('H:i', strtotime($item['usage_end_time'])); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if($item['usage_duration']): ?>
                                                            <?php
                                                            $hours = floor($item['usage_duration'] / 60);
                                                            $minutes = $item['usage_duration'] % 60;
                                                            echo $hours > 0 ? $hours . 'h ' : '';
                                                            echo $minutes . 'm';
                                                            ?>
                                                        <?php else: ?>
                                                            <span class="text-muted">N/A</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <small><?php echo htmlspecialchars($item['recorded_by_name']); ?></small>
                                                        <br>
                                                        <small class="text-muted"><?php echo date('M j', strtotime($item['created_at'])); ?></small>
                                                    </td>
                                                    <td>
                                                        <div class="btn-group btn-group-sm">
                                                            <!-- Edit Modal Trigger -->
                                                            <button type="button" class="btn btn-outline-primary" data-toggle="modal" data-target="#editUsageModal<?php echo $item['usage_id']; ?>">
                                                                <i class="fas fa-edit"></i>
                                                            </button>
                                                            <!-- Remove Form -->
                                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to remove this equipment usage?');">
                                                                <input type="hidden" name="usage_id" value="<?php echo $item['usage_id']; ?>">
                                                                <button type="submit" name="remove_equipment_usage" class="btn btn-outline-danger">
                                                                    <i class="fas fa-trash"></i>
                                                                </button>
                                                            </form>
                                                        </div>
                                                        
                                                        <!-- Edit Usage Modal -->
                                                        <div class="modal fade" id="editUsageModal<?php echo $item['usage_id']; ?>" tabindex="-1" role="dialog">
                                                            <div class="modal-dialog" role="document">
                                                                <div class="modal-content">
                                                                    <div class="modal-header">
                                                                        <h5 class="modal-title">Edit Equipment Usage</h5>
                                                                        <button type="button" class="close" data-dismiss="modal">
                                                                            <span>&times;</span>
                                                                        </button>
                                                                    </div>
                                                                    <form method="POST">
                                                                        <div class="modal-body">
                                                                            <input type="hidden" name="usage_id" value="<?php echo $item['usage_id']; ?>">
                                                                            
                                                                            <div class="form-group">
                                                                                <label>Equipment</label>
                                                                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($item['asset_name']); ?>" readonly>
                                                                            </div>
                                                                            
                                                                            <div class="form-group">
                                                                                <label>Quantity Used</label>
                                                                                <input type="number" class="form-control" name="quantity_used" value="<?php echo $item['quantity_used']; ?>" min="1" required>
                                                                            </div>
                                                                            
                                                                            <div class="row">
                                                                                <div class="col-md-6">
                                                                                    <div class="form-group">
                                                                                        <label>Usage Start Time</label>
                                                                                        <input type="time" class="form-control" name="usage_start_time" value="<?php echo $item['usage_start_time']; ?>">
                                                                                    </div>
                                                                                </div>
                                                                                <div class="col-md-6">
                                                                                    <div class="form-group">
                                                                                        <label>Usage End Time</label>
                                                                                        <input type="time" class="form-control" name="usage_end_time" value="<?php echo $item['usage_end_time']; ?>">
                                                                                    </div>
                                                                                </div>
                                                                            </div>
                                                                            
                                                                            <div class="form-group">
                                                                                <label>Notes</label>
                                                                                <textarea class="form-control" name="notes" rows="2"><?php echo htmlspecialchars($item['notes']); ?></textarea>
                                                                            </div>
                                                                        </div>
                                                                        <div class="modal-footer">
                                                                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                                                                            <button type="submit" name="update_equipment_usage" class="btn btn-primary">Save Changes</button>
                                                                        </div>
                                                                    </form>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                </tr>
                                                <?php endwhile; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-4">
                                        <i class="fas fa-tools fa-3x text-muted mb-3"></i>
                                        <h5>No Equipment Usage Recorded</h5>
                                        <p class="text-muted">Add equipment used during this surgical case using the form below.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Add Equipment Form -->
                        <div class="collapse mb-4" id="addEquipmentForm">
                            <div class="section-card">
                                <div class="section-header">
                                    <h5 class="mb-0"><i class="fas fa-plus mr-2"></i>Add Equipment Usage</h5>
                                </div>
                                <div class="section-body">
                                    <form method="POST" action="">
                                        <div class="row">
                                            <div class="col-md-8">
                                                <div class="form-group">
                                                    <label>Select Equipment *</label>
                                                    <select class="form-control select2" name="asset_id" id="assetSelect" required>
                                                        <option value="">Select equipment...</option>
                                                        <?php if ($available_assets_result->num_rows > 0): ?>
                                                            <?php while ($asset = $available_assets_result->fetch_assoc()): ?>
                                                                <option value="<?php echo $asset['asset_id']; ?>" data-critical="<?php echo $asset['is_critical']; ?>" <?php echo $asset['is_critical'] ? 'class="text-danger"' : ''; ?>>
                                                                    <?php echo htmlspecialchars($asset['asset_tag'] . ' - ' . $asset['asset_name']); ?>
                                                                    <?php if($asset['location_name']): ?> (<?php echo htmlspecialchars($asset['location_name']); ?>)<?php endif; ?>
                                                                    <?php if($asset['is_critical']): ?> ⚠ CRITICAL<?php endif; ?>
                                                                </option>
                                                            <?php endwhile; ?>
                                                        <?php else: ?>
                                                            <option value="" disabled>No available equipment</option>
                                                        <?php endif; ?>
                                                    </select>
                                                    <small class="form-text text-muted">Select equipment used during this surgery</small>
                                                </div>
                                            </div>
                                            <div class="col-md-2">
                                                <div class="form-group">
                                                    <label>Quantity *</label>
                                                    <input type="number" class="form-control" name="quantity_used" value="1" min="1" required>
                                                </div>
                                            </div>
                                            <div class="col-md-2">
                                                <div class="form-group">
                                                    <label>Critical</label>
                                                    <div class="mt-2">
                                                        <span class="badge badge-danger" id="criticalBadge" style="display: none;">CRITICAL</span>
                                                        <span class="badge badge-success" id="nonCriticalBadge" style="display: none;">Standard</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="row">
                                            <div class="col-md-4">
                                                <div class="form-group">
                                                    <label>Usage Start Time</label>
                                                    <input type="time" class="form-control" name="usage_start_time">
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-group">
                                                    <label>Usage End Time</label>
                                                    <input type="time" class="form-control" name="usage_end_time">
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-group">
                                                    <label>Estimated Duration</label>
                                                    <input type="text" class="form-control" id="estimatedDuration" readonly placeholder="Will calculate automatically">
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label>Notes</label>
                                            <textarea class="form-control" name="notes" rows="2" placeholder="Any additional notes about equipment usage"></textarea>
                                        </div>
                                        
                                        <div class="form-group text-right">
                                            <button type="button" class="btn btn-secondary" data-toggle="collapse" data-target="#addEquipmentForm">
                                                Cancel
                                            </button>
                                            <button type="submit" name="add_equipment_usage" class="btn btn-primary">
                                                <i class="fas fa-save mr-2"></i>Record Equipment Usage
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <!-- Equipment Checklist -->
                        <div class="section-card">
                            <div class="section-header">
                                <h5 class="mb-0"><i class="fas fa-clipboard-check mr-2"></i>Equipment Checklist</h5>
                            </div>
                            <div class="section-body">
                                <div class="form-check mb-2">
                                    <input type="checkbox" class="form-check-input" id="check1">
                                    <label class="form-check-label" for="check1">All equipment sterilized</label>
                                </div>
                                <div class="form-check mb-2">
                                    <input type="checkbox" class="form-check-input" id="check2">
                                    <label class="form-check-label" for="check2">Equipment functionality verified</label>
                                </div>
                                <div class="form-check mb-2">
                                    <input type="checkbox" class="form-check-input" id="check3">
                                    <label class="form-check-label" for="check3">Backup equipment available</label>
                                </div>
                                <div class="form-check mb-2">
                                    <input type="checkbox" class="form-check-input" id="check4">
                                    <label class="form-check-label" for="check4">Safety checks completed</label>
                                </div>
                                <div class="form-check mb-2">
                                    <input type="checkbox" class="form-check-input" id="check5">
                                    <label class="form-check-label" for="check5">Documentation updated</label>
                                </div>
                                
                                <button class="btn btn-sm btn-outline-primary btn-block mt-3" id="checkAll">
                                    <i class="fas fa-check mr-1"></i>Mark All Complete
                                </button>
                                
                                <div class="alert alert-warning mt-3 small">
                                    <i class="fas fa-exclamation-triangle mr-2"></i>
                                    <strong>Important:</strong> Ensure all critical equipment has been checked and tested before surgery.
                                </div>
                            </div>
                        </div>
                        
                        <!-- Quick Templates -->
                        <div class="section-card">
                            <div class="section-header">
                                <h5 class="mb-0"><i class="fas fa-clipboard-list mr-2"></i>Quick Add Common Equipment</h5>
                            </div>
                            <div class="section-body">
                                <?php
                                // Common surgical equipment categories
                                $common_categories = [
                                    'Surgical Instruments' => ['Scalpel', 'Forceps', 'Scissors', 'Retractor', 'Clamps'],
                                    'Monitoring Equipment' => ['ECG Monitor', 'Pulse Oximeter', 'Blood Pressure Monitor', 'Temperature Monitor'],
                                    'Anesthesia Equipment' => ['Anesthesia Machine', 'Ventilator', 'Gas Cylinder', 'Laryngoscope'],
                                    'Surgical Power Tools' => ['Drill', 'Saw', 'Cautery Machine', 'Suction Machine'],
                                    'Implants & Prosthetics' => ['Stent', 'Plate', 'Screw', 'Prosthetic Joint']
                                ];
                                ?>
                                
                                <div id="quickAddEquipment">
                                    <?php foreach ($common_categories as $category => $items): ?>
                                        <h6 class="mt-3"><?php echo $category; ?></h6>
                                        <div class="d-flex flex-wrap gap-2">
                                            <?php foreach ($items as $item): 
                                                // Check if this equipment exists in assets
                                                $check_sql = "SELECT asset_id, asset_tag FROM assets 
                                                             WHERE asset_name LIKE '%$item%' 
                                                             AND status = 'active'
                                                             LIMIT 1";
                                                $check_result = $mysqli->query($check_sql);
                                                $has_asset = $check_result->num_rows > 0;
                                                $asset = $has_asset ? $check_result->fetch_assoc() : null;
                                            ?>
                                                <button type="button" 
                                                        class="btn btn-sm btn-outline-secondary quick-equipment-btn" 
                                                        data-item="<?php echo $item; ?>"
                                                        data-asset-id="<?php echo $has_asset ? $asset['asset_id'] : ''; ?>"
                                                        <?php echo !$has_asset ? 'disabled title="Equipment not in inventory"' : ''; ?>>
                                                    <?php echo $item; ?>
                                                    <?php if ($has_asset): ?>
                                                        <small class="text-muted">(<?php echo $asset['asset_tag']; ?>)</small>
                                                    <?php endif; ?>
                                                </button>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                
                                <div class="alert alert-info mt-3 small">
                                    <i class="fas fa-info-circle mr-2"></i>
                                    <strong>Note:</strong> Grayed out items are not available in the inventory.
                                </div>
                            </div>
                        </div>
                        
                        <!-- Safety Guidelines -->
                        <div class="card">
                            <div class="card-header bg-light">
                                <h5 class="mb-0"><i class="fas fa-shield-alt mr-2"></i>Safety Guidelines</h5>
                            </div>
                            <div class="card-body">
                                <div class="alert alert-danger small mb-3">
                                    <i class="fas fa-exclamation-triangle mr-2"></i>
                                    <strong>Critical Equipment:</strong> Must have backup available and pre-use check documented.
                                </div>
                                
                                <ul class="small pl-3">
                                    <li class="mb-2">Verify sterility indicators before use</li>
                                    <li class="mb-2">Check equipment expiry dates</li>
                                    <li class="mb-2">Document any equipment malfunctions</li>
                                    <li class="mb-2">Report near-miss events immediately</li>
                                    <li>Follow manufacturer's maintenance schedule</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <?php include_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
    <script>
        $(document).ready(function() {
            $('.select2').select2({
                templateResult: function(data) {
                    if (!data.id) {
                        return data.text;
                    }
                    if ($(data.element).hasClass('text-danger')) {
                        return $('<span class="text-danger">' + data.text + '</span>');
                    }
                    return data.text;
                }
            });
            
            // Update critical badge when asset is selected
            $('#assetSelect').change(function() {
                var selectedOption = $(this).find('option:selected');
                var isCritical = selectedOption.data('critical');
                
                if (selectedOption.val()) {
                    if (isCritical == 1) {
                        $('#criticalBadge').show();
                        $('#nonCriticalBadge').hide();
                    } else {
                        $('#criticalBadge').hide();
                        $('#nonCriticalBadge').show();
                    }
                } else {
                    $('#criticalBadge').hide();
                    $('#nonCriticalBadge').hide();
                }
            });
            
            // Calculate duration when times change
            $('input[name="usage_start_time"], input[name="usage_end_time"]').change(function() {
                var startTime = $('input[name="usage_start_time"]').val();
                var endTime = $('input[name="usage_end_time"]').val();
                
                if (startTime && endTime) {
                    var start = new Date('2000-01-01T' + startTime + ':00');
                    var end = new Date('2000-01-01T' + endTime + ':00');
                    
                    if (end < start) {
                        // If end time is before start time, assume it's the next day
                        end.setDate(end.getDate() + 1);
                    }
                    
                    var diffMs = end - start;
                    var diffMins = Math.floor(diffMs / 60000);
                    var hours = Math.floor(diffMins / 60);
                    var minutes = diffMins % 60;
                    
                    var durationText = '';
                    if (hours > 0) {
                        durationText += hours + ' hour' + (hours > 1 ? 's' : '') + ' ';
                    }
                    durationText += minutes + ' minute' + (minutes > 1 ? 's' : '');
                    
                    $('#estimatedDuration').val(durationText);
                } else {
                    $('#estimatedDuration').val('');
                }
            });
            
            // Quick add equipment buttons
            $('.quick-equipment-btn').click(function() {
                var itemName = $(this).data('item');
                var assetId = $(this).data('asset-id');
                
                if (assetId) {
                    // Find and select the asset
                    $('#assetSelect').val(assetId).trigger('change');
                    
                    // Show success message
                    showToast('success', itemName + ' has been selected.');
                }
            });
            
            // Mark all checklist items
            $('#checkAll').click(function() {
                $('.form-check-input').prop('checked', true);
                $(this).html('<i class="fas fa-check-double mr-1"></i>All Complete');
                $(this).removeClass('btn-outline-primary').addClass('btn-success');
                showToast('success', 'All checklist items marked as complete.');
            });
            
            // Toast function
            function showToast(type, message) {
                var toast = $('<div class="toast" role="alert" aria-live="assertive" aria-atomic="true" data-delay="3000">' +
                    '<div class="toast-header bg-' + type + ' text-white">' +
                    '<i class="fas fa-check mr-2"></i>' +
                    '<strong class="mr-auto">Success</strong>' +
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
            
            // Initialize toast container if not exists
            if ($('.toast-container').length === 0) {
                $('body').append('<div class="toast-container" style="position: fixed; top: 20px; right: 20px; z-index: 1050;"></div>');
            }
        });
    </script>
</body>
</html>
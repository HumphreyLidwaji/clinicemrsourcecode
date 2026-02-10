<?php
// asset_maintenance_view.php
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

// Get maintenance record with related data
$sql = "
    SELECT am.*,
           a.asset_id, a.asset_tag, a.asset_name, a.serial_number, 
           a.asset_condition, a.status as asset_status, a.purchase_price, a.current_value,
           ac.category_name,
           al.location_name, al.building, al.floor, al.room_number,
           s.supplier_name, s.supplier_email, s.supplier_phone,
           creator.user_name as created_by_name, creator.user_email as created_by_email,
           performer.user_name as performed_by_name, performer.user_email as performed_by_email,
           updater.user_name as updated_by_name,
           (SELECT COUNT(*) FROM asset_maintenance am2 WHERE am2.asset_id = a.asset_id) as asset_maintenance_count,
           (SELECT COUNT(*) FROM asset_maintenance am3 WHERE am3.asset_id = a.asset_id AND am3.status = 'completed') as completed_maintenance_count
    FROM asset_maintenance am
    JOIN assets a ON am.asset_id = a.asset_id
    LEFT JOIN asset_categories ac ON a.category_id = ac.category_id
    LEFT JOIN asset_locations al ON a.location_id = al.location_id
    LEFT JOIN suppliers s ON am.supplier_id = s.supplier_id
    LEFT JOIN users creator ON am.created_by = creator.user_id
    LEFT JOIN users performer ON am.performed_by = performer.user_id
    LEFT JOIN users updater ON am.updated_by = updater.user_id
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

// Handle Start Maintenance Action
if (isset($_GET['action']) && $_GET['action'] == 'start' && $maintenance['status'] == 'scheduled') {
    // Start transaction
    $mysqli->begin_transaction();
    
    try {
        // Update maintenance status to in_progress
        $update_sql = "
            UPDATE asset_maintenance 
            SET status = 'in_progress', 
                actual_start_date = CURDATE(),
                updated_by = ?,
                updated_at = NOW()
            WHERE maintenance_id = ?
        ";
        
        $update_stmt = $mysqli->prepare($update_sql);
        $update_stmt->bind_param("ii", $session_user_id, $maintenance_id);
        
        // Update asset status to maintenance
        $update_asset_sql = "
            UPDATE assets 
            SET status = 'maintenance', 
                updated_by = ?, 
                updated_at = NOW() 
            WHERE asset_id = ?
        ";
        
        $update_asset_stmt = $mysqli->prepare($update_asset_sql);
        $update_asset_stmt->bind_param("ii", $session_user_id, $maintenance['asset_id']);
        
        if ($update_stmt->execute() && $update_asset_stmt->execute()) {
            $mysqli->commit();
            
            // Log activity
            $log_description = "Maintenance started for asset: {$maintenance['asset_tag']} - {$maintenance['asset_name']} (Maintenance ID: $maintenance_id)";
            mysqli_query($mysqli, "INSERT INTO logs SET log_type = 'Asset Maintenance', log_action = 'Start', log_description = '$log_description', log_ip = '$session_ip', log_user_agent = '$session_user_agent', log_user_id = $session_user_id");
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Maintenance started successfully!";
            header("Location: asset_maintenance_view.php?id=$maintenance_id");
            exit;
        } else {
            throw new Exception("Database error: " . $mysqli->error);
        }
    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error starting maintenance: " . $e->getMessage();
        header("Location: asset_maintenance_view.php?id=$maintenance_id");
        exit;
    }
}

// Handle Complete Maintenance Action
if (isset($_GET['action']) && $_GET['action'] == 'complete' && $maintenance['status'] == 'in_progress') {
    // Get completion data if provided
    $actual_cost = isset($_POST['actual_cost']) ? floatval($_POST['actual_cost']) : $maintenance['cost'];
    $completed_notes = isset($_POST['completed_notes']) ? sanitizeInput($_POST['completed_notes']) : '';
    $new_asset_condition = isset($_POST['new_asset_condition']) ? sanitizeInput($_POST['new_asset_condition']) : $maintenance['asset_condition'];
    $findings = isset($_POST['findings']) ? sanitizeInput($_POST['findings']) : $maintenance['findings'];
    $recommendations = isset($_POST['recommendations']) ? sanitizeInput($_POST['recommendations']) : $maintenance['recommendations'];
    
    // Start transaction
    $mysqli->begin_transaction();
    
    try {
        // Update maintenance status to completed
        $update_sql = "
            UPDATE asset_maintenance 
            SET status = 'completed', 
                completed_date = CURDATE(),
                actual_cost = ?,
                completed_notes = ?,
                findings = ?,
                recommendations = ?,
                performed_by = ?,
                updated_by = ?,
                updated_at = NOW()
            WHERE maintenance_id = ?
        ";
        
        $update_stmt = $mysqli->prepare($update_sql);
        $update_stmt->bind_param(
            "dsssiii", 
            $actual_cost, 
            $completed_notes,
            $findings,
            $recommendations,
            $session_user_id,
            $session_user_id,
            $maintenance_id
        );
        
        // Update asset status to active and condition
        $update_asset_sql = "
            UPDATE assets 
            SET status = 'active', 
                asset_condition = ?,
                updated_by = ?, 
                updated_at = NOW() 
            WHERE asset_id = ?
        ";
        
        $update_asset_stmt = $mysqli->prepare($update_asset_sql);
        $update_asset_stmt->bind_param("sii", $new_asset_condition, $session_user_id, $maintenance['asset_id']);
        
        if ($update_stmt->execute() && $update_asset_stmt->execute()) {
            $mysqli->commit();
            
            // Log activity
            $log_description = "Maintenance completed for asset: {$maintenance['asset_tag']} - {$maintenance['asset_name']} (Maintenance ID: $maintenance_id)";
            mysqli_query($mysqli, "INSERT INTO logs SET log_type = 'Asset Maintenance', log_action = 'Complete', log_description = '$log_description', log_ip = '$session_ip', log_user_agent = '$session_user_agent', log_user_id = $session_user_id");
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Maintenance completed successfully!";
            header("Location: asset_maintenance_view.php?id=$maintenance_id");
            exit;
        } else {
            throw new Exception("Database error: " . $mysqli->error);
        }
    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error completing maintenance: " . $e->getMessage();
        header("Location: asset_maintenance_view.php?id=$maintenance_id");
        exit;
    }
}

// Handle Cancel Maintenance Action
if (isset($_GET['action']) && $_GET['action'] == 'cancel' && $maintenance['status'] != 'cancelled') {
    // Get cancellation reason if provided
    $cancellation_reason = isset($_POST['cancellation_reason']) ? sanitizeInput($_POST['cancellation_reason']) : 'No reason provided';
    
    // Start transaction
    $mysqli->begin_transaction();
    
    try {
        // Update maintenance status to cancelled
        $update_sql = "
            UPDATE asset_maintenance 
            SET status = 'cancelled', 
                cancellation_reason = ?,
                updated_by = ?,
                updated_at = NOW()
            WHERE maintenance_id = ?
        ";
        
        $update_stmt = $mysqli->prepare($update_sql);
        $update_stmt->bind_param("sii", $cancellation_reason, $session_user_id, $maintenance_id);
        
        // Only update asset status if it was in maintenance
        $update_asset_sql = "
            UPDATE assets 
            SET status = 'active', 
                updated_by = ?, 
                updated_at = NOW() 
            WHERE asset_id = ? AND status = 'maintenance'
        ";
        
        $update_asset_stmt = $mysqli->prepare($update_asset_sql);
        $update_asset_stmt->bind_param("ii", $session_user_id, $maintenance['asset_id']);
        
        if ($update_stmt->execute() && $update_asset_stmt->execute()) {
            $mysqli->commit();
            
            // Log activity
            $log_description = "Maintenance cancelled for asset: {$maintenance['asset_tag']} - {$maintenance['asset_name']} (Maintenance ID: $maintenance_id). Reason: $cancellation_reason";
            mysqli_query($mysqli, "INSERT INTO logs SET log_type = 'Asset Maintenance', log_action = 'Cancel', log_description = '$log_description', log_ip = '$session_ip', log_user_agent = '$session_user_agent', log_user_id = $session_user_id");
            
            $_SESSION['alert_type'] = "warning";
            $_SESSION['alert_message'] = "Maintenance cancelled successfully!";
            header("Location: asset_maintenance_view.php?id=$maintenance_id");
            exit;
        } else {
            throw new Exception("Database error: " . $mysqli->error);
        }
    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error cancelling maintenance: " . $e->getMessage();
        header("Location: asset_maintenance_view.php?id=$maintenance_id");
        exit;
    }
}

// Handle Restore Maintenance Action
if (isset($_GET['action']) && $_GET['action'] == 'restore' && $maintenance['status'] == 'cancelled') {
    // Start transaction
    $mysqli->begin_transaction();
    
    try {
        // Update maintenance status to scheduled
        $update_sql = "
            UPDATE asset_maintenance 
            SET status = 'scheduled', 
                cancellation_reason = NULL,
                updated_by = ?,
                updated_at = NOW()
            WHERE maintenance_id = ?
        ";
        
        $update_stmt = $mysqli->prepare($update_sql);
        $update_stmt->bind_param("ii", $session_user_id, $maintenance_id);
        
        if ($update_stmt->execute()) {
            $mysqli->commit();
            
            // Log activity
            $log_description = "Maintenance restored for asset: {$maintenance['asset_tag']} - {$maintenance['asset_name']} (Maintenance ID: $maintenance_id)";
            mysqli_query($mysqli, "INSERT INTO logs SET log_type = 'Asset Maintenance', log_action = 'Restore', log_description = '$log_description', log_ip = '$session_ip', log_user_agent = '$session_user_agent', log_user_id = $session_user_id");
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Maintenance restored successfully!";
            header("Location: asset_maintenance_view.php?id=$maintenance_id");
            exit;
        } else {
            throw new Exception("Database error: " . $mysqli->error);
        }
    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error restoring maintenance: " . $e->getMessage();
        header("Location: asset_maintenance_view.php?id=$maintenance_id");
        exit;
    }
}

// Get maintenance history for this asset
$history_sql = "
    SELECT am.maintenance_id, am.maintenance_type, am.maintenance_date, 
           am.description, am.cost, am.status, am.performed_by,
           u.user_name as performed_by_name
    FROM asset_maintenance am
    LEFT JOIN users u ON am.performed_by = u.user_id
    WHERE am.asset_id = ? AND am.maintenance_id != ?
    ORDER BY am.maintenance_date DESC
    LIMIT 10
";
$history_stmt = $mysqli->prepare($history_sql);
$history_stmt->bind_param("ii", $maintenance['asset_id'], $maintenance_id);
$history_stmt->execute();
$history_result = $history_stmt->get_result();

// Get similar maintenance records
$similar_sql = "
    SELECT am.maintenance_id, am.maintenance_type, am.maintenance_date, 
           am.description, am.cost, am.status,
           a.asset_tag, a.asset_name
    FROM asset_maintenance am
    JOIN assets a ON am.asset_id = a.asset_id
    WHERE am.maintenance_type = ? AND am.maintenance_id != ?
    ORDER BY am.maintenance_date DESC
    LIMIT 5
";
$similar_stmt = $mysqli->prepare($similar_sql);
$similar_stmt->bind_param("si", $maintenance['maintenance_type'], $maintenance_id);
$similar_stmt->execute();
$similar_result = $similar_stmt->get_result();

// Calculate days since maintenance
$days_since_maintenance = 0;
if ($maintenance['maintenance_date']) {
    $maintenance_date = new DateTime($maintenance['maintenance_date']);
    $today = new DateTime();
    $days_since_maintenance = $today->diff($maintenance_date)->days;
}

// Calculate days until next maintenance
$days_until_next = null;
if ($maintenance['next_maintenance_date']) {
    $next_date = new DateTime($maintenance['next_maintenance_date']);
    $today = new DateTime();
    $days_until_next = $today->diff($next_date)->days;
    if ($today > $next_date) {
        $days_until_next = -$days_until_next; // Negative for overdue
    }
}

// Determine status badge color
$status_badge = "";
$status_icon = "";
switch($maintenance['status']) {
    case 'scheduled':
        $status_badge = $days_until_next < 0 ? "badge-danger" : "badge-warning";
        $status_icon = $days_until_next < 0 ? "fa-calendar-times" : "fa-calendar";
        break;
    case 'in_progress':
        $status_badge = "badge-primary";
        $status_icon = "fa-wrench";
        break;
    case 'completed':
        $status_badge = "badge-success";
        $status_icon = "fa-check";
        break;
    case 'cancelled':
        $status_badge = "badge-secondary";
        $status_icon = "fa-ban";
        break;
    default:
        $status_badge = "badge-light";
        $status_icon = "fa-question-circle";
}

// Determine type badge color
$type_badge = "";
switch($maintenance['maintenance_type']) {
    case 'preventive': $type_badge = "badge-primary"; break;
    case 'corrective': $type_badge = "badge-danger"; break;
    case 'calibration': $type_badge = "badge-info"; break;
    case 'inspection': $type_badge = "badge-warning"; break;
    default: $type_badge = "badge-secondary";
}
?>

<div class="card">
    <div class="card-header bg-info py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0 text-white">
                <i class="fas fa-fw fa-eye mr-2"></i>Maintenance Record Details
            </h3>
            <div class="btn-group">
                <a href="asset_maintenance_edit.php?id=<?php echo $maintenance_id; ?>" class="btn btn-warning">
                    <i class="fas fa-edit mr-2"></i>Edit
                </a>
                <a href="asset_maintenance.php" class="btn btn-light ml-2">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Maintenance
                </a>
                <div class="btn-group ml-2">
                    <button type="button" class="btn btn-secondary dropdown-toggle" data-toggle="dropdown">
                        <i class="fas fa-ellipsis-h"></i>
                    </button>
                    <div class="dropdown-menu dropdown-menu-right">
                        <?php if ($maintenance['status'] == 'scheduled'): ?>
                            <a class="dropdown-item text-primary" href="asset_maintenance_view.php?id=<?php echo $maintenance_id; ?>&action=start" onclick="return confirm('Are you sure you want to start this maintenance?')">
                                <i class="fas fa-play mr-2"></i>Start Maintenance
                            </a>
                        <?php endif; ?>
                        <?php if ($maintenance['status'] == 'in_progress'): ?>
                            <a class="dropdown-item text-success" href="#" data-toggle="modal" data-target="#completeModal">
                                <i class="fas fa-check mr-2"></i>Complete Maintenance
                            </a>
                        <?php endif; ?>
                        <?php if ($maintenance['status'] != 'cancelled'): ?>
                            <a class="dropdown-item text-danger" href="#" data-toggle="modal" data-target="#cancelModal">
                                <i class="fas fa-ban mr-2"></i>Cancel Maintenance
                            </a>
                        <?php else: ?>
                            <a class="dropdown-item text-success" href="asset_maintenance_view.php?id=<?php echo $maintenance_id; ?>&action=restore" onclick="return confirm('Are you sure you want to restore this maintenance?')">
                                <i class="fas fa-redo mr-2"></i>Restore Maintenance
                            </a>
                        <?php endif; ?>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item" href="asset_maintenance_print.php?id=<?php echo $maintenance_id; ?>">
                            <i class="fas fa-print mr-2"></i>Print Record
                        </a>
                        <a class="dropdown-item" href="asset_maintenance_export.php?id=<?php echo $maintenance_id; ?>">
                            <i class="fas fa-file-export mr-2"></i>Export Details
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card-body">
        <!-- Complete Maintenance Modal -->
        <div class="modal fade" id="completeModal" tabindex="-1" role="dialog" aria-labelledby="completeModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg" role="document">
                <div class="modal-content">
                    <form method="POST" action="asset_maintenance_view.php?id=<?php echo $maintenance_id; ?>&action=complete">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <div class="modal-header bg-success">
                            <h5 class="modal-title text-white" id="completeModalLabel">
                                <i class="fas fa-check mr-2"></i>Complete Maintenance
                            </h5>
                            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            <div class="alert alert-success">
                                <i class="fas fa-info-circle mr-2"></i>
                                <strong>Complete Maintenance:</strong> Mark this maintenance as completed and update asset information.
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="actual_cost">Actual Cost ($)</label>
                                        <div class="input-group">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text">$</span>
                                            </div>
                                            <input type="number" class="form-control" id="actual_cost" 
                                                   name="actual_cost" step="0.01" min="0" 
                                                   value="<?php echo $maintenance['cost']; ?>">
                                        </div>
                                        <small class="form-text text-muted">Actual cost incurred</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="new_asset_condition">New Asset Condition *</label>
                                        <select class="form-control" id="new_asset_condition" name="new_asset_condition" required>
                                            <option value="excellent" <?php echo $maintenance['asset_condition'] == 'excellent' ? 'selected' : ''; ?>>Excellent - Like new</option>
                                            <option value="good" <?php echo $maintenance['asset_condition'] == 'good' ? 'selected' : ''; ?>>Good - Normal wear</option>
                                            <option value="fair" <?php echo $maintenance['asset_condition'] == 'fair' ? 'selected' : ''; ?>>Fair - Minor issues</option>
                                            <option value="poor" <?php echo $maintenance['asset_condition'] == 'poor' ? 'selected' : ''; ?>>Poor - Needs repair</option>
                                            <option value="needs_attention" <?php echo $maintenance['asset_condition'] == 'needs_attention' ? 'selected' : ''; ?>>Needs Attention</option>
                                        </select>
                                        <small class="form-text text-muted">Asset condition after maintenance</small>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="findings">Findings/Issues</label>
                                <textarea class="form-control" id="findings" name="findings" rows="3" 
                                          placeholder="What issues were found during maintenance..."><?php echo htmlspecialchars($maintenance['findings'] ?: ''); ?></textarea>
                                <small class="form-text text-muted">Issues discovered during maintenance</small>
                            </div>

                            <div class="form-group">
                                <label for="recommendations">Recommendations</label>
                                <textarea class="form-control" id="recommendations" name="recommendations" rows="3" 
                                          placeholder="Recommendations for future maintenance..."><?php echo htmlspecialchars($maintenance['recommendations'] ?: ''); ?></textarea>
                                <small class="form-text text-muted">Recommendations based on findings</small>
                            </div>

                            <div class="form-group">
                                <label for="completed_notes">Completion Notes</label>
                                <textarea class="form-control" id="completed_notes" name="completed_notes" rows="3" 
                                          placeholder="Additional notes about the completion..."></textarea>
                                <small class="form-text text-muted">Any additional information about completion</small>
                            </div>
                            
                            <div class="alert alert-info">
                                <h6><i class="fas fa-info-circle mr-2"></i>Maintenance Details</h6>
                                <small>
                                    <div class="mb-2">
                                        <strong>Asset:</strong><br>
                                        <?php echo htmlspecialchars($maintenance['asset_tag'] . ' - ' . $maintenance['asset_name']); ?>
                                    </div>
                                    <div class="mb-2">
                                        <strong>Maintenance ID:</strong><br>
                                        #<?php echo str_pad($maintenance['maintenance_id'], 6, '0', STR_PAD_LEFT); ?>
                                    </div>
                                    <div class="mb-2">
                                        <strong>Estimated Cost:</strong><br>
                                        $<?php echo number_format($maintenance['cost'], 2); ?>
                                    </div>
                                    <div>
                                        <strong>Current Condition:</strong><br>
                                        <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $maintenance['asset_condition']))); ?>
                                    </div>
                                </small>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">
                                <i class="fas fa-times mr-2"></i>Cancel
                            </button>
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-check mr-2"></i>Complete Maintenance
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Cancel Maintenance Modal -->
        <div class="modal fade" id="cancelModal" tabindex="-1" role="dialog" aria-labelledby="cancelModalLabel" aria-hidden="true">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <form method="POST" action="asset_maintenance_view.php?id=<?php echo $maintenance_id; ?>&action=cancel">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <div class="modal-header bg-warning">
                            <h5 class="modal-title text-white" id="cancelModalLabel">
                                <i class="fas fa-ban mr-2"></i>Cancel Maintenance
                            </h5>
                            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle mr-2"></i>
                                <strong>Warning:</strong> This action cannot be undone. The maintenance will be marked as cancelled.
                            </div>
                            
                            <div class="form-group">
                                <label for="cancellation_reason">Cancellation Reason *</label>
                                <textarea class="form-control" id="cancellation_reason" name="cancellation_reason" rows="3" required placeholder="Please provide a reason for cancelling this maintenance..."></textarea>
                                <small class="form-text text-muted">This will be recorded in the maintenance history.</small>
                            </div>
                            
                            <div class="alert alert-info">
                                <h6><i class="fas fa-info-circle mr-2"></i>Maintenance Details</h6>
                                <small>
                                    <div class="mb-2">
                                        <strong>Asset:</strong><br>
                                        <?php echo htmlspecialchars($maintenance['asset_tag'] . ' - ' . $maintenance['asset_name']); ?>
                                    </div>
                                    <div class="mb-2">
                                        <strong>Maintenance ID:</strong><br>
                                        #<?php echo str_pad($maintenance['maintenance_id'], 6, '0', STR_PAD_LEFT); ?>
                                    </div>
                                    <div>
                                        <strong>Description:</strong><br>
                                        <?php echo htmlspecialchars(substr($maintenance['description'], 0, 100)); ?>...
                                    </div>
                                </small>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">
                                <i class="fas fa-times mr-2"></i>Cancel
                            </button>
                            <button type="submit" class="btn btn-danger">
                                <i class="fas fa-ban mr-2"></i>Confirm Cancellation
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Header Information -->
        <div class="row mb-4">
            <div class="col-md-8">
                <h4 class="mb-1"><?php echo htmlspecialchars($maintenance['description']); ?></h4>
                <div class="d-flex align-items-center">
                    <span class="badge <?php echo $status_badge; ?> badge-pill mr-2">
                        <i class="fas <?php echo $status_icon; ?> mr-1"></i>
                        <?php echo ucfirst(str_replace('_', ' ', $maintenance['status'])); ?>
                    </span>
                    <span class="badge <?php echo $type_badge; ?> mr-2">
                        <?php echo ucfirst($maintenance['maintenance_type']); ?> Maintenance
                    </span>
                    <span class="text-muted">
                        <i class="fas fa-calendar-alt mr-1"></i>
                        <?php echo date('F j, Y', strtotime($maintenance['maintenance_date'])); ?>
                    </span>
                </div>
            </div>
            <div class="col-md-4 text-right">
                <h4 class="text-success">$<?php echo number_format($maintenance['cost'], 2); ?></h4>
                <small class="text-muted">Maintenance Cost</small>
            </div>
        </div>

        <div class="row">
            <div class="col-md-8">
                <!-- Maintenance Details -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h4 class="card-title"><i class="fas fa-info-circle mr-2"></i>Maintenance Details</h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <table class="table table-sm">
                                    <tr>
                                        <th width="40%">Maintenance ID:</th>
                                        <td>#<?php echo str_pad($maintenance['maintenance_id'], 6, '0', STR_PAD_LEFT); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Type:</th>
                                        <td>
                                            <span class="badge <?php echo $type_badge; ?>">
                                                <?php echo ucfirst($maintenance['maintenance_type']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Status:</th>
                                        <td>
                                            <span class="badge <?php echo $status_badge; ?>">
                                                <i class="fas <?php echo $status_icon; ?> mr-1"></i>
                                                <?php echo ucfirst(str_replace('_', ' ', $maintenance['status'])); ?>
                                            </span>
                                            <?php if ($maintenance['status'] == 'cancelled' && $maintenance['cancellation_reason']): ?>
                                                <div class="mt-1">
                                                    <small class="text-danger">
                                                        <i class="fas fa-info-circle mr-1"></i>
                                                        Reason: <?php echo htmlspecialchars($maintenance['cancellation_reason']); ?>
                                                    </small>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Maintenance Date:</th>
                                        <td>
                                            <?php echo date('F j, Y', strtotime($maintenance['maintenance_date'])); ?>
                                            <?php if ($days_since_maintenance > 0): ?>
                                                <small class="text-muted d-block">
                                                    (<?php echo $days_since_maintenance; ?> days ago)
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php if ($maintenance['actual_start_date']): ?>
                                    <tr>
                                        <th>Started Date:</th>
                                        <td class="text-primary">
                                            <i class="fas fa-play mr-1"></i>
                                            <?php echo date('F j, Y', strtotime($maintenance['actual_start_date'])); ?>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                    <?php if ($maintenance['completed_date']): ?>
                                    <tr>
                                        <th>Completed Date:</th>
                                        <td class="text-success">
                                            <i class="fas fa-check mr-1"></i>
                                            <?php echo date('F j, Y', strtotime($maintenance['completed_date'])); ?>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <table class="table table-sm">
                                    <tr>
                                        <th width="40%">Performed By:</th>
                                        <td>
                                            <?php if ($maintenance['performed_by_name']): ?>
                                                <?php echo htmlspecialchars($maintenance['performed_by_name']); ?>
                                                <?php if ($maintenance['performed_by_email']): ?>
                                                    <small class="d-block text-muted">
                                                        <i class="fas fa-envelope mr-1"></i>
                                                        <?php echo htmlspecialchars($maintenance['performed_by_email']); ?>
                                                    </small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">Not assigned</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php if ($maintenance['supplier_name']): ?>
                                    <tr>
                                        <th>Supplier:</th>
                                        <td>
                                            <?php echo htmlspecialchars($maintenance['supplier_name']); ?>
                                            <?php if ($maintenance['supplier_contact'] ?? 'N/A'): ?>
                                                <small class="d-block text-muted">
                                                    <i class="fas fa-user mr-1"></i>
                                                    <?php echo htmlspecialchars($maintenance['supplier_contact'] ?? 'N/A'); ?>
                                                </small>
                                            <?php endif; ?>
                                            <?php if ($maintenance['supplier_phone']): ?>
                                                <small class="d-block text-muted">
                                                    <i class="fas fa-phone mr-1"></i>
                                                    <?php echo htmlspecialchars($maintenance['supplier_phone']); ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                    <tr>
                                        <th>Cost:</th>
                                        <td class="text-success font-weight-bold">
                                            <?php if ($maintenance['actual_cost'] && $maintenance['actual_cost'] != $maintenance['cost']): ?>
                                                <span class="text-muted text-decoration-line-through">
                                                    $<?php echo number_format($maintenance['cost'], 2); ?>
                                                </span>
                                                <br>
                                                <span class="text-primary">
                                                    $<?php echo number_format($maintenance['actual_cost'], 2); ?> (Actual)
                                                </span>
                                            <?php else: ?>
                                                $<?php echo number_format($maintenance['cost'], 2); ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php if ($maintenance['next_maintenance_date']): ?>
                                    <tr>
                                        <th>Next Maintenance:</th>
                                        <td>
                                            <?php echo date('F j, Y', strtotime($maintenance['next_maintenance_date'])); ?>
                                            <?php if ($days_until_next !== null): ?>
                                                <?php if ($days_until_next < 0): ?>
                                                    <small class="text-danger d-block">
                                                        <i class="fas fa-exclamation-triangle mr-1"></i>
                                                        Overdue by <?php echo abs($days_until_next); ?> days
                                                    </small>
                                                <?php elseif ($days_until_next <= 7): ?>
                                                    <small class="text-warning d-block">
                                                        <i class="fas fa-clock mr-1"></i>
                                                        Due in <?php echo $days_until_next; ?> days
                                                    </small>
                                                <?php else: ?>
                                                    <small class="text-muted d-block">
                                                        <i class="fas fa-clock mr-1"></i>
                                                        Due in <?php echo $days_until_next; ?> days
                                                    </small>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </table>
                            </div>
                        </div>

                        <div class="row mt-3">
                            <div class="col-12">
                                <h5><i class="fas fa-clipboard mr-2"></i>Description</h5>
                                <div class="border rounded p-3 bg-light">
                                    <?php echo nl2br(htmlspecialchars($maintenance['description'])); ?>
                                </div>
                            </div>
                        </div>

                        <?php if ($maintenance['findings']): ?>
                        <div class="row mt-3">
                            <div class="col-12">
                                <h5><i class="fas fa-search mr-2"></i>Findings/Issues</h5>
                                <div class="border rounded p-3 bg-light">
                                    <?php echo nl2br(htmlspecialchars($maintenance['findings'])); ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ($maintenance['recommendations']): ?>
                        <div class="row mt-3">
                            <div class="col-12">
                                <h5><i class="fas fa-lightbulb mr-2"></i>Recommendations</h5>
                                <div class="border rounded p-3 bg-light">
                                    <?php echo nl2br(htmlspecialchars($maintenance['recommendations'])); ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ($maintenance['completed_notes']): ?>
                        <div class="row mt-3">
                            <div class="col-12">
                                <h5><i class="fas fa-check-circle mr-2"></i>Completion Notes</h5>
                                <div class="border rounded p-3 bg-light">
                                    <?php echo nl2br(htmlspecialchars($maintenance['completed_notes'])); ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ($maintenance['next_maintenance_notes']): ?>
                        <div class="row mt-3">
                            <div class="col-12">
                                <h5><i class="fas fa-calendar-check mr-2"></i>Next Maintenance Notes</h5>
                                <div class="border rounded p-3 bg-light">
                                    <?php echo nl2br(htmlspecialchars($maintenance['next_maintenance_notes'])); ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Asset Information -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h4 class="card-title"><i class="fas fa-cube mr-2"></i>Asset Information</h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <table class="table table-sm">
                                    <tr>
                                        <th width="40%">Asset Tag:</th>
                                        <td>
                                            <a href="asset_view.php?id=<?php echo $maintenance['asset_id']; ?>" class="font-weight-bold text-primary">
                                                <?php echo htmlspecialchars($maintenance['asset_tag']); ?>
                                            </a>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Asset Name:</th>
                                        <td><?php echo htmlspecialchars($maintenance['asset_name']); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Category:</th>
                                        <td><?php echo htmlspecialchars($maintenance['category_name']); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Serial Number:</th>
                                        <td><?php echo htmlspecialchars($maintenance['serial_number']) ?: 'N/A'; ?></td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <table class="table table-sm">
                                    <tr>
                                        <th width="40%">Current Location:</th>
                                        <td>
                                            <?php echo htmlspecialchars($maintenance['location_name']); ?>
                                            <?php if ($maintenance['building']): ?>
                                                <small class="d-block text-muted">
                                                    <?php echo htmlspecialchars($maintenance['building']); ?>
                                                    <?php if ($maintenance['floor']): ?>, Floor: <?php echo htmlspecialchars($maintenance['floor']); ?><?php endif; ?>
                                                    <?php if ($maintenance['room_number']): ?>, Room: <?php echo htmlspecialchars($maintenance['room_number']); ?><?php endif; ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Asset Status:</th>
                                        <td>
                                            <span class="badge badge-<?php 
                                                switch($maintenance['asset_status']) {
                                                    case 'active': echo 'success'; break;
                                                    case 'under_maintenance': echo 'warning'; break;
                                                    case 'checked_out': echo 'info'; break;
                                                    case 'disposed': echo 'secondary'; break;
                                                    default: echo 'light';
                                                }
                                            ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $maintenance['asset_status'])); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Asset Condition:</th>
                                        <td>
                                            <span class="badge badge-<?php 
                                                switch($maintenance['asset_condition']) {
                                                    case 'excellent': echo 'success'; break;
                                                    case 'good': echo 'info'; break;
                                                    case 'fair': echo 'warning'; break;
                                                    case 'poor': echo 'danger'; break;
                                                    case 'needs_attention': echo 'warning'; break;
                                                    default: echo 'secondary';
                                                }
                                            ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $maintenance['asset_condition'])); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Current Value:</th>
                                        <td class="text-success font-weight-bold">
                                            $<?php echo number_format($maintenance['current_value'], 2); ?>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                        <div class="text-center mt-3">
                            <a href="asset_view.php?id=<?php echo $maintenance['asset_id']; ?>" class="btn btn-outline-primary">
                                <i class="fas fa-external-link-alt mr-2"></i>View Full Asset Details
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Maintenance History -->
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title"><i class="fas fa-history mr-2"></i>Maintenance History for this Asset</h4>
                    </div>
                    <div class="card-body">
                        <?php if ($history_result->num_rows > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Type</th>
                                            <th>Description</th>
                                            <th>Cost</th>
                                            <th>Status</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($history = $history_result->fetch_assoc()): ?>
                                            <tr>
                                                <td><?php echo date('M j, Y', strtotime($history['maintenance_date'])); ?></td>
                                                <td>
                                                    <span class="badge badge-<?php 
                                                        switch($history['maintenance_type']) {
                                                            case 'preventive': echo 'primary'; break;
                                                            case 'corrective': echo 'danger'; break;
                                                            case 'calibration': echo 'info'; break;
                                                            case 'inspection': echo 'warning'; break;
                                                            default: echo 'secondary';
                                                        }
                                                    ?>">
                                                        <?php echo ucfirst($history['maintenance_type']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars(substr($history['description'], 0, 50)); ?>
                                                    <?php if (strlen($history['description']) > 50): ?>...<?php endif; ?>
                                                </td>
                                                <td>$<?php echo number_format($history['cost'], 2); ?></td>
                                                <td>
                                                    <span class="badge badge-<?php 
                                                        switch($history['status']) {
                                                            case 'completed': echo 'success'; break;
                                                            case 'in_progress': echo 'primary'; break;
                                                            case 'scheduled': echo 'warning'; break;
                                                            case 'cancelled': echo 'secondary'; break;
                                                            default: echo 'light';
                                                        }
                                                    ?>">
                                                        <?php echo ucfirst($history['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <a href="asset_maintenance_view.php?id=<?php echo $history['maintenance_id']; ?>" class="btn btn-sm btn-outline-info">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="text-center mt-2">
                                <a href="asset_maintenance.php?asset=<?php echo $maintenance['asset_id']; ?>" class="btn btn-sm btn-outline-primary">
                                    View All Maintenance for this Asset
                                </a>
                            </div>
                        <?php else: ?>
                            <p class="text-muted text-center mb-0">
                                <i class="fas fa-info-circle mr-1"></i>
                                No previous maintenance records found for this asset.
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <!-- Quick Actions -->
                <div class="card mb-4">
                    <div class="card-header bg-success text-white">
                        <h4 class="card-title mb-0"><i class="fas fa-bolt mr-2"></i>Quick Actions</h4>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="asset_maintenance_edit.php?id=<?php echo $maintenance_id; ?>" class="btn btn-warning">
                                <i class="fas fa-edit mr-2"></i>Edit Maintenance Record
                            </a>
                            
                            <?php if ($maintenance['status'] == 'scheduled'): ?>
                                <a href="asset_maintenance_view.php?id=<?php echo $maintenance_id; ?>&action=start" 
                                   class="btn btn-primary" 
                                   onclick="return confirm('Are you sure you want to start this maintenance?')">
                                    <i class="fas fa-play mr-2"></i>Start Maintenance
                                </a>
                            <?php endif; ?>
                            
                            <?php if ($maintenance['status'] == 'in_progress'): ?>
                                <button type="button" class="btn btn-success" data-toggle="modal" data-target="#completeModal">
                                    <i class="fas fa-check mr-2"></i>Complete Maintenance
                                </button>
                            <?php endif; ?>
                            
                            <?php if ($maintenance['status'] != 'cancelled'): ?>
                                <button type="button" class="btn btn-outline-danger" data-toggle="modal" data-target="#cancelModal">
                                    <i class="fas fa-ban mr-2"></i>Cancel Maintenance
                                </button>
                            <?php else: ?>
                                <a href="asset_maintenance_view.php?id=<?php echo $maintenance_id; ?>&action=restore" 
                                   class="btn btn-outline-success" 
                                   onclick="return confirm('Are you sure you want to restore this maintenance?')">
                                    <i class="fas fa-redo mr-2"></i>Restore Maintenance
                                </a>
                            <?php endif; ?>
                            
                            <a href="asset_maintenance_new.php?asset=<?php echo $maintenance['asset_id']; ?>" class="btn btn-info">
                                <i class="fas fa-plus mr-2"></i>New Maintenance for this Asset
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Maintenance Statistics -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h4 class="card-title"><i class="fas fa-chart-bar mr-2"></i>Statistics</h4>
                    </div>
                    <div class="card-body">
                        <div class="list-group list-group-flush">
                            <div class="list-group-item px-0 py-2">
                                <div class="d-flex w-100 justify-content-between">
                                    <span>Total Maintenance for Asset:</span>
                                    <span class="badge badge-primary badge-pill"><?php echo $maintenance['asset_maintenance_count']; ?></span>
                                </div>
                            </div>
                            <div class="list-group-item px-0 py-2">
                                <div class="d-flex w-100 justify-content-between">
                                    <span>Completed Maintenance:</span>
                                    <span class="badge badge-success badge-pill"><?php echo $maintenance['completed_maintenance_count']; ?></span>
                                </div>
                            </div>
                            <div class="list-group-item px-0 py-2">
                                <div class="d-flex w-100 justify-content-between">
                                    <span>Days Since Maintenance:</span>
                                    <span class="badge badge-info badge-pill"><?php echo $days_since_maintenance; ?></span>
                                </div>
                            </div>
                            <?php if ($days_until_next !== null): ?>
                            <div class="list-group-item px-0 py-2">
                                <div class="d-flex w-100 justify-content-between">
                                    <span>Days Until Next:</span>
                                    <span class="badge badge-<?php echo $days_until_next < 0 ? 'danger' : ($days_until_next <= 7 ? 'warning' : 'info'); ?> badge-pill">
                                        <?php echo abs($days_until_next); ?>
                                    </span>
                                </div>
                            </div>
                            <?php endif; ?>
                            <div class="list-group-item px-0 py-2">
                                <div class="d-flex w-100 justify-content-between">
                                    <span>Cost Impact:</span>
                                    <span class="text-success font-weight-bold">
                                        <?php if ($maintenance['actual_cost']): ?>
                                            $<?php echo number_format($maintenance['actual_cost'], 2); ?>
                                        <?php else: ?>
                                            $<?php echo number_format($maintenance['cost'], 2); ?>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Activity Timeline -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h4 class="card-title"><i class="fas fa-stream mr-2"></i>Activity Timeline</h4>
                    </div>
                    <div class="card-body">
                        <div class="timeline">
                            <!-- Created -->
                            <div class="timeline-item">
                                <div class="timeline-marker bg-primary"></div>
                                <div class="timeline-content">
                                    <h6 class="mb-1">Record Created</h6>
                                    <small class="text-muted">
                                        <?php echo date('M j, Y g:i A', strtotime($maintenance['created_at'])); ?>
                                    </small>
                                    <?php if ($maintenance['created_by_name']): ?>
                                        <div class="small">By: <?php echo htmlspecialchars($maintenance['created_by_name']); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Maintenance Date -->
                            <div class="timeline-item">
                                <div class="timeline-marker bg-info"></div>
                                <div class="timeline-content">
                                    <h6 class="mb-1">Scheduled Date</h6>
                                    <small class="text-muted">
                                        <?php echo date('M j, Y', strtotime($maintenance['maintenance_date'])); ?>
                                    </small>
                                </div>
                            </div>

                            <!-- Started Date -->
                            <?php if ($maintenance['actual_start_date']): ?>
                            <div class="timeline-item">
                                <div class="timeline-marker bg-primary"></div>
                                <div class="timeline-content">
                                    <h6 class="mb-1">Maintenance Started</h6>
                                    <small class="text-muted">
                                        <?php echo date('M j, Y', strtotime($maintenance['actual_start_date'])); ?>
                                    </small>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- Completed Date -->
                            <?php if ($maintenance['completed_date']): ?>
                            <div class="timeline-item">
                                <div class="timeline-marker bg-success"></div>
                                <div class="timeline-content">
                                    <h6 class="mb-1">Completed</h6>
                                    <small class="text-muted">
                                        <?php echo date('M j, Y', strtotime($maintenance['completed_date'])); ?>
                                    </small>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- Cancelled -->
                            <?php if ($maintenance['status'] == 'cancelled'): ?>
                            <div class="timeline-item">
                                <div class="timeline-marker bg-danger"></div>
                                <div class="timeline-content">
                                    <h6 class="mb-1">Cancelled</h6>
                                    <small class="text-muted">
                                        <?php echo date('M j, Y', strtotime($maintenance['updated_at'])); ?>
                                    </small>
                                    <?php if ($maintenance['cancellation_reason']): ?>
                                        <div class="small text-danger">
                                            <i class="fas fa-info-circle mr-1"></i>
                                            Reason: <?php echo htmlspecialchars(substr($maintenance['cancellation_reason'], 0, 50)); ?>...
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- Next Maintenance -->
                            <?php if ($maintenance['next_maintenance_date']): ?>
                            <div class="timeline-item">
                                <div class="timeline-marker bg-dark"></div>
                                <div class="timeline-content">
                                    <h6 class="mb-1">Next Maintenance Due</h6>
                                    <small class="text-muted">
                                        <?php echo date('M j, Y', strtotime($maintenance['next_maintenance_date'])); ?>
                                    </small>
                                    <?php if ($days_until_next !== null): ?>
                                        <?php if ($days_until_next < 0): ?>
                                            <div class="small text-danger">
                                                <i class="fas fa-exclamation-triangle mr-1"></i>
                                                Overdue by <?php echo abs($days_until_next); ?> days
                                            </div>
                                        <?php elseif ($days_until_next <= 7): ?>
                                            <div class="small text-warning">
                                                <i class="fas fa-clock mr-1"></i>
                                                Due in <?php echo $days_until_next; ?> days
                                            </div>
                                        <?php else: ?>
                                            <div class="small text-muted">
                                                <i class="fas fa-clock mr-1"></i>
                                                Due in <?php echo $days_until_next; ?> days
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- Updated -->
                            <?php if ($maintenance['updated_at'] && $maintenance['updated_at'] != $maintenance['created_at']): ?>
                            <div class="timeline-item">
                                <div class="timeline-marker bg-secondary"></div>
                                <div class="timeline-content">
                                    <h6 class="mb-1">Last Updated</h6>
                                    <small class="text-muted">
                                        <?php echo date('M j, Y g:i A', strtotime($maintenance['updated_at'])); ?>
                                    </small>
                                    <?php if ($maintenance['updated_by_name']): ?>
                                        <div class="small">By: <?php echo htmlspecialchars($maintenance['updated_by_name']); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Similar Maintenance -->
                <?php if ($similar_result->num_rows > 0): ?>
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title"><i class="fas fa-exchange-alt mr-2"></i>Similar Maintenance</h4>
                    </div>
                    <div class="card-body">
                        <div class="list-group list-group-flush">
                            <?php while ($similar = $similar_result->fetch_assoc()): ?>
                                <a href="asset_maintenance_view.php?id=<?php echo $similar['maintenance_id']; ?>" class="list-group-item list-group-item-action px-0 py-2">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1"><?php echo htmlspecialchars($similar['asset_tag']); ?></h6>
                                        <small class="text-muted">
                                            <?php echo date('M j', strtotime($similar['maintenance_date'])); ?>
                                        </small>
                                    </div>
                                    <small class="text-muted">
                                        <?php echo htmlspecialchars(substr($similar['description'], 0, 40)); ?>...
                                    </small>
                                    <div class="mt-1">
                                        <span class="badge badge-<?php 
                                            switch($similar['status']) {
                                                case 'completed': echo 'success'; break;
                                                case 'in_progress': echo 'primary'; break;
                                                case 'scheduled': echo 'warning'; break;
                                                case 'cancelled': echo 'secondary'; break;
                                                default: echo 'light';
                                            }
                                        ?>">
                                            <?php echo ucfirst($similar['status']); ?>
                                        </span>
                                        <span class="badge badge-light">
                                            $<?php echo number_format($similar['cost'], 2); ?>
                                        </span>
                                    </div>
                                </a>
                            <?php endwhile; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card-footer">
        <div class="row">
            <div class="col-md-6">
                <small class="text-muted">
                    Created: <?php echo date('F j, Y, g:i a', strtotime($maintenance['created_at'])); ?>
                    <?php if ($maintenance['created_by_name']): ?>
                        by <?php echo htmlspecialchars($maintenance['created_by_name']); ?>
                    <?php endif; ?>
                </small>
            </div>
            <div class="col-md-6 text-right">
                <?php if ($maintenance['updated_at'] && $maintenance['updated_at'] != $maintenance['created_at']): ?>
                    <small class="text-muted">
                        Last Updated: <?php echo date('F j, Y, g:i a', strtotime($maintenance['updated_at'])); ?>
                        <?php if ($maintenance['updated_by_name']): ?>
                            by <?php echo htmlspecialchars($maintenance['updated_by_name']); ?>
                        <?php endif; ?>
                    </small>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + E to edit
    if (e.ctrlKey && e.keyCode === 69) {
        e.preventDefault();
        window.location.href = 'asset_maintenance_edit.php?id=<?php echo $maintenance_id; ?>';
    }
    // Escape to go back
    if (e.keyCode === 27) {
        window.location.href = 'asset_maintenance.php';
    }
    // Ctrl + P to print
    if (e.ctrlKey && e.keyCode === 80) {
        e.preventDefault();
        window.location.href = 'asset_maintenance_print.php?id=<?php echo $maintenance_id; ?>';
    }
    // Ctrl + S to start maintenance (if scheduled)
    <?php if ($maintenance['status'] == 'scheduled'): ?>
    if (e.ctrlKey && e.keyCode === 83) {
        e.preventDefault();
        if (confirm('Are you sure you want to start this maintenance?')) {
            window.location.href = 'asset_maintenance_view.php?id=<?php echo $maintenance_id; ?>&action=start';
        }
    }
    <?php endif; ?>
    // Ctrl + C to complete maintenance (if in progress)
    <?php if ($maintenance['status'] == 'in_progress'): ?>
    if (e.ctrlKey && e.keyCode === 67) {
        e.preventDefault();
        $('#completeModal').modal('show');
    }
    <?php endif; ?>
    // Ctrl + X to cancel maintenance (if not cancelled)
    <?php if ($maintenance['status'] != 'cancelled'): ?>
    if (e.ctrlKey && e.keyCode === 88) {
        e.preventDefault();
        $('#cancelModal').modal('show');
    }
    <?php endif; ?>
});

// Validate actual cost in complete modal
$('#completeModal').on('show.bs.modal', function () {
    $('#actual_cost').focus();
});

$('#completeModal form').on('submit', function(e) {
    var actualCost = $('#actual_cost').val();
    if (actualCost && parseFloat(actualCost) < 0) {
        e.preventDefault();
        alert('Actual cost cannot be negative');
        $('#actual_cost').focus();
        return false;
    }
});
</script>

<style>
.timeline {
    position: relative;
    padding-left: 30px;
}

.timeline-item {
    position: relative;
    margin-bottom: 20px;
}

.timeline-marker {
    position: absolute;
    left: -30px;
    top: 0;
    width: 12px;
    height: 12px;
    border-radius: 50%;
}

.timeline-content {
    padding-bottom: 10px;
    border-bottom: 1px solid #e9ecef;
}

.timeline-item:last-child .timeline-content {
    border-bottom: none;
    padding-bottom: 0;
}

.badge-pill {
    padding: 0.5em 0.8em;
}

.table th {
    font-weight: 600;
    color: #495057;
}

.card {
    margin-bottom: 20px;
}

.btn-group .dropdown-menu {
    min-width: 200px;
}

.modal-header.bg-success .close,
.modal-header.bg-warning .close {
    color: #fff;
    text-shadow: 0 1px 0 #000;
}

.text-decoration-line-through {
    text-decoration: line-through;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
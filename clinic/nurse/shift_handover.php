<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/audit_functions.php'; // Added for logging

// Get visit_id from URL
$visit_id = intval($_GET['visit_id'] ?? 0);

if ($visit_id <= 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid visit ID";
    
    // AUDIT LOG: Invalid visit ID
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'ACCESS',
        'module'      => 'Shift Handover',
        'table_name'  => 'visits',
        'entity_type' => 'visit',
        'record_id'   => null,
        'patient_id'  => null,
        'visit_id'    => null,
        'description' => "Attempted to access shift_handover.php with invalid visit ID: " . $visit_id,
        'status'      => 'FAILED',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    header("Location: /clinic/dashboard.php");
    exit;
}

// Initialize variables
$patient_info = null;
$visit_info = null;
$handovers = [];
$current_shift = '';
$today = date('Y-m-d');

// Determine current shift
$current_hour = date('H');
if ($current_hour >= 6 && $current_hour < 14) {
    $current_shift = 'morning';
} elseif ($current_hour >= 14 && $current_hour < 22) {
    $current_shift = 'evening';
} else {
    $current_shift = 'night';
}

// Determine next shift
$next_shift = '';
switch ($current_shift) {
    case 'morning':
        $next_shift = 'evening';
        break;
    case 'evening':
        $next_shift = 'night';
        break;
    case 'night':
        $next_shift = 'morning';
        break;
}

// Check visit type and get patient info
$visit_found = false;

// Check OPD visits
$opd_sql = "SELECT ov.*, p.* 
           FROM opd_visits ov 
           JOIN patients p ON ov.patient_id = p.patient_id
           WHERE ov.visit_id = ?";
$opd_stmt = $mysqli->prepare($opd_sql);
$opd_stmt->bind_param("i", $visit_id);
$opd_stmt->execute();
$opd_result = $opd_stmt->get_result();

if ($opd_result->num_rows > 0) {
    $visit_info = $opd_result->fetch_assoc();
    $patient_info = $visit_info;
    $visit_type = 'OPD';
    $visit_found = true;
}

// Check IPD admissions
if (!$visit_found) {
    $ipd_sql = "SELECT ia.*, p.* 
               FROM ipd_admissions ia 
               JOIN patients p ON ia.patient_id = p.patient_id
               WHERE ia.visit_id = ?";
    $ipd_stmt = $mysqli->prepare($ipd_sql);
    $ipd_stmt->bind_param("i", $visit_id);
    $ipd_stmt->execute();
    $ipd_result = $ipd_stmt->get_result();
    
    if ($ipd_result->num_rows > 0) {
        $visit_info = $ipd_result->fetch_assoc();
        $patient_info = $visit_info;
        $visit_type = 'IPD';
        $visit_found = true;
    }
}

// Check Emergency visits
if (!$visit_found) {
    $emergency_sql = "SELECT ev.*, p.* 
                     FROM emergency_visits ev 
                     JOIN patients p ON ev.patient_id = p.patient_id
                     WHERE ev.visit_id = ?";
    $emergency_stmt = $mysqli->prepare($emergency_sql);
    $emergency_stmt->bind_param("i", $visit_id);
    $emergency_stmt->execute();
    $emergency_result = $emergency_stmt->get_result();
    
    if ($emergency_result->num_rows > 0) {
        $visit_info = $emergency_result->fetch_assoc();
        $patient_info = $visit_info;
        $visit_type = 'EMERGENCY';
        $visit_found = true;
    }
}

if (!$visit_found) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Visit not found";
    
    // AUDIT LOG: Visit not found
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'ACCESS',
        'module'      => 'Shift Handover',
        'table_name'  => 'visits',
        'entity_type' => 'visit',
        'record_id'   => $visit_id,
        'patient_id'  => null,
        'visit_id'    => $visit_id,
        'description' => "Attempted to access shift handover for visit ID " . $visit_id . " but visit not found",
        'status'      => 'FAILED',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    header("Location: /clinic/dashboard.php");
    exit;
}

// Get shift handovers for this patient
$handover_sql = "SELECT sh.*, 
                 u1.user_name as from_nurse_name,
                 u2.user_name as to_nurse_name,
                 u3.user_name as acknowledged_by_name
                 FROM nursing_handovers sh
                 JOIN users u1 ON sh.from_nurse_id = u1.user_id
                 JOIN users u2 ON sh.to_nurse_id = u2.user_id
                 LEFT JOIN users u3 ON sh.acknowledged_by = u3.user_id
                 WHERE sh.handover_id IN (
                     SELECT handover_id FROM nursing_handover_patients 
                     WHERE patient_id = ?
                 )
                 ORDER BY sh.shift_date DESC, 
                 FIELD(sh.from_shift, 'morning', 'evening', 'night') DESC";
$handover_stmt = $mysqli->prepare($handover_sql);
$handover_stmt->bind_param("i", $patient_info['patient_id']);
$handover_stmt->execute();
$handover_result = $handover_stmt->get_result();
$handovers = $handover_result->fetch_all(MYSQLI_ASSOC);

// Get today's handover for current shift
$todays_handover = null;
foreach ($handovers as $handover) {
    if ($handover['shift_date'] == $today && $handover['from_shift'] == $current_shift) {
        $todays_handover = $handover;
        break;
    }
}

// Get pending tasks for handover
$pending_tasks_sql = "SELECT nt.* 
                     FROM nursing_tasks nt
                     WHERE nt.visit_id = ? 
                     AND nt.status IN ('Pending', 'In Progress')
                     ORDER BY 
                       CASE nt.priority 
                           WHEN 'Critical' THEN 1
                           WHEN 'High' THEN 2
                           WHEN 'Medium' THEN 3
                           WHEN 'Low' THEN 4
                       END,
                       nt.scheduled_time ASC";
$pending_tasks_stmt = $mysqli->prepare($pending_tasks_sql);
$pending_tasks_stmt->bind_param("i", $visit_id);
$pending_tasks_stmt->execute();
$pending_tasks_result = $pending_tasks_stmt->get_result();
$pending_tasks = $pending_tasks_result->fetch_all(MYSQLI_ASSOC);

// Get available nurses for next shift
$nurses_sql = "SELECT user_id, user_name FROM users 
         ";
$nurses_stmt = $mysqli->prepare($nurses_sql);

$nurses_stmt->execute();
$nurses_result = $nurses_stmt->get_result();
$available_nurses = $nurses_result->fetch_all(MYSQLI_ASSOC);

// AUDIT LOG: Successful access to shift handover page
audit_log($mysqli, [
    'user_id'     => $_SESSION['user_id'] ?? null,
    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
    'action'      => 'VIEW',
    'module'      => 'Shift Handover',
    'table_name'  => 'visits',
    'entity_type' => 'visit',
    'record_id'   => $visit_id,
    'patient_id'  => $patient_info['patient_id'],
    'visit_id'    => $visit_id,
    'description' => "Accessed shift handover page for visit ID " . $visit_id . " (Patient: " . $patient_info['patient_first_name'] . " " . $patient_info['patient_last_name'] . "). Current shift: " . $current_shift . ", Visit type: " . $visit_type,
    'status'      => 'SUCCESS',
    'old_values'  => null,
    'new_values'  => null
]);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Create handover
    if (isset($_POST['create_handover'])) {
        $shift_date = $_POST['shift_date'];
        $from_shift = $_POST['from_shift'];
        $to_shift = $_POST['to_shift'];
        $to_nurse_id = $_POST['to_nurse_id'];
        $handover_notes = trim($_POST['handover_notes']);
        $critical_issues = trim($_POST['critical_issues'] ?? '');
        $pending_medications = trim($_POST['pending_medications'] ?? '');
        $special_instructions = trim($_POST['special_instructions'] ?? '');
        $priority = $_POST['priority'];
        $from_nurse_id = $_SESSION['user_id'];
        
        // Get to nurse name for audit log
        $to_nurse_sql = "SELECT user_name FROM users WHERE user_id = ?";
        $to_nurse_stmt = $mysqli->prepare($to_nurse_sql);
        $to_nurse_stmt->bind_param("i", $to_nurse_id);
        $to_nurse_stmt->execute();
        $to_nurse_result = $to_nurse_stmt->get_result();
        $to_nurse = $to_nurse_result->fetch_assoc();
        $to_nurse_name = $to_nurse['user_name'] ?? 'Unknown';
        
        // AUDIT LOG: Create handover attempt
        audit_log($mysqli, [
            'user_id'     => $from_nurse_id,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'SHIFT_HANDOVER_CREATE',
            'module'      => 'Shift Handover',
            'table_name'  => 'nursing_handovers',
            'entity_type' => 'nursing_handover',
            'record_id'   => null,
            'patient_id'  => $patient_info['patient_id'],
            'visit_id'    => $visit_id,
            'description' => "Attempting to create shift handover. From shift: " . $from_shift . " to " . $to_shift . " (" . $shift_date . "). To nurse: " . $to_nurse_name . ". Priority: " . $priority,
            'status'      => 'ATTEMPT',
            'old_values'  => null,
            'new_values'  => [
                'shift_date' => $shift_date,
                'from_shift' => $from_shift,
                'to_shift' => $to_shift,
                'to_nurse_id' => $to_nurse_id,
                'priority' => $priority,
                'from_nurse_id' => $from_nurse_id
            ]
        ]);
        
        // Start transaction
        $mysqli->begin_transaction();
        
        try {
            // Create handover record
            $insert_sql = "INSERT INTO nursing_handovers 
                          (from_nurse_id, to_nurse_id, shift_date, 
                           from_shift, to_shift, handover_notes,
                           critical_issues, pending_medications, special_instructions,
                           priority, handover_status)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')";
            
            $insert_stmt = $mysqli->prepare($insert_sql);
            $insert_stmt->bind_param("iissssssss", 
                $from_nurse_id, $to_nurse_id, $shift_date,
                $from_shift, $to_shift, $handover_notes,
                $critical_issues, $pending_medications, $special_instructions,
                $priority
            );
            
            if (!$insert_stmt->execute()) {
                throw new Exception("Error creating handover: " . $mysqli->error);
            }
            
            $handover_id = $mysqli->insert_id;
            
            // Link patient to handover
            $link_sql = "INSERT INTO nursing_handover_patients 
                        (handover_id, patient_id)
                        VALUES (?, ?)";
            
            $link_stmt = $mysqli->prepare($link_sql);
            $link_stmt->bind_param("ii", $handover_id, $patient_info['patient_id']);
            
            if (!$link_stmt->execute()) {
                throw new Exception("Error linking patient: " . $mysqli->error);
            }
            
            // Add pending tasks to handover
            $task_count = 0;
            if (isset($_POST['handover_tasks']) && is_array($_POST['handover_tasks'])) {
                $task_count = count($_POST['handover_tasks']);
                foreach ($_POST['handover_tasks'] as $task_id) {
                    $task_id = intval($task_id);
                    if ($task_id > 0) {
                        $task_sql = "INSERT INTO nursing_handover_tasks 
                                    (handover_id, task_description, status, priority)
                                    SELECT ?, CONCAT(nt.task_description, ' (Due: ', 
                                            DATE_FORMAT(nt.scheduled_time, '%H:%i'), ')'), 
                                            'pending', nt.priority
                                    FROM nursing_tasks nt
                                    WHERE nt.id = ?";
                        
                        $task_stmt = $mysqli->prepare($task_sql);
                        $task_stmt->bind_param("ii", $handover_id, $task_id);
                        $task_stmt->execute();
                    }
                }
            }
            
            $mysqli->commit();
            
            // AUDIT LOG: Successful handover creation
            audit_log($mysqli, [
                'user_id'     => $from_nurse_id,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'SHIFT_HANDOVER_CREATE',
                'module'      => 'Shift Handover',
                'table_name'  => 'nursing_handovers',
                'entity_type' => 'nursing_handover',
                'record_id'   => $handover_id,
                'patient_id'  => $patient_info['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Shift handover created successfully. Handover ID: " . $handover_id . ". Tasks included: " . $task_count,
                'status'      => 'SUCCESS',
                'old_values'  => null,
                'new_values'  => [
                    'handover_id' => $handover_id,
                    'shift_date' => $shift_date,
                    'from_shift' => $from_shift,
                    'to_shift' => $to_shift,
                    'to_nurse_id' => $to_nurse_id,
                    'priority' => $priority,
                    'handover_status' => 'pending',
                    'from_nurse_id' => $from_nurse_id,
                    'created_at' => date('Y-m-d H:i:s')
                ]
            ]);
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Shift handover created successfully";
            header("Location: shift_handover.php?visit_id=" . $visit_id);
            exit;
            
        } catch (Exception $e) {
            $mysqli->rollback();
            
            // AUDIT LOG: Failed handover creation
            audit_log($mysqli, [
                'user_id'     => $from_nurse_id,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'SHIFT_HANDOVER_CREATE',
                'module'      => 'Shift Handover',
                'table_name'  => 'nursing_handovers',
                'entity_type' => 'nursing_handover',
                'record_id'   => null,
                'patient_id'  => $patient_info['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Failed to create shift handover. Error: " . $e->getMessage(),
                'status'      => 'FAILED',
                'old_values'  => null,
                'new_values'  => null
            ]);
            
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = $e->getMessage();
        }
    }
    
    // Acknowledge handover
    if (isset($_POST['acknowledge_handover'])) {
        $handover_id = intval($_POST['handover_id']);
        $acknowledgement_notes = trim($_POST['acknowledgement_notes'] ?? '');
        $acknowledged_by = $_SESSION['user_id'];
        
        // Get handover details for audit log
        $handover_sql = "SELECT from_shift, to_shift, shift_date FROM nursing_handovers WHERE handover_id = ?";
        $handover_stmt = $mysqli->prepare($handover_sql);
        $handover_stmt->bind_param("i", $handover_id);
        $handover_stmt->execute();
        $handover_result = $handover_stmt->get_result();
        $handover_details = $handover_result->fetch_assoc();
        
        // AUDIT LOG: Acknowledge handover attempt
        audit_log($mysqli, [
            'user_id'     => $acknowledged_by,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'SHIFT_HANDOVER_ACKNOWLEDGE',
            'module'      => 'Shift Handover',
            'table_name'  => 'nursing_handovers',
            'entity_type' => 'nursing_handover',
            'record_id'   => $handover_id,
            'patient_id'  => $patient_info['patient_id'],
            'visit_id'    => $visit_id,
            'description' => "Attempting to acknowledge handover ID " . $handover_id . ". Shift: " . ($handover_details['from_shift'] ?? 'N/A') . " to " . ($handover_details['to_shift'] ?? 'N/A'),
            'status'      => 'ATTEMPT',
            'old_values'  => ['handover_status' => 'pending'],
            'new_values'  => [
                'handover_status' => 'acknowledged',
                'acknowledgement_notes' => $acknowledgement_notes,
                'acknowledged_by' => $acknowledged_by
            ]
        ]);
        
        $update_sql = "UPDATE nursing_handovers 
                      SET acknowledgement_notes = ?,
                          acknowledged_at = NOW(),
                          acknowledged_by = ?,
                          handover_status = 'acknowledged'
                      WHERE handover_id = ? AND to_nurse_id = ?";
        
        $update_stmt = $mysqli->prepare($update_sql);
        $update_stmt->bind_param("siii", $acknowledgement_notes, $acknowledged_by, $handover_id, $acknowledged_by);
        
        if ($update_stmt->execute()) {
            // AUDIT LOG: Successful handover acknowledgement
            audit_log($mysqli, [
                'user_id'     => $acknowledged_by,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'SHIFT_HANDOVER_ACKNOWLEDGE',
                'module'      => 'Shift Handover',
                'table_name'  => 'nursing_handovers',
                'entity_type' => 'nursing_handover',
                'record_id'   => $handover_id,
                'patient_id'  => $patient_info['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Handover acknowledged successfully. Handover ID: " . $handover_id,
                'status'      => 'SUCCESS',
                'old_values'  => ['handover_status' => 'pending'],
                'new_values'  => [
                    'handover_status' => 'acknowledged',
                    'acknowledgement_notes' => $acknowledgement_notes,
                    'acknowledged_by' => $acknowledged_by,
                    'acknowledged_at' => date('Y-m-d H:i:s')
                ]
            ]);
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Handover acknowledged successfully";
            header("Location: shift_handover.php?visit_id=" . $visit_id);
            exit;
        } else {
            // AUDIT LOG: Failed handover acknowledgement
            audit_log($mysqli, [
                'user_id'     => $acknowledged_by,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'SHIFT_HANDOVER_ACKNOWLEDGE',
                'module'      => 'Shift Handover',
                'table_name'  => 'nursing_handovers',
                'entity_type' => 'nursing_handover',
                'record_id'   => $handover_id,
                'patient_id'  => $patient_info['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Failed to acknowledge handover. Error: " . $mysqli->error,
                'status'      => 'FAILED',
                'old_values'  => null,
                'new_values'  => null
            ]);
            
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error acknowledging handover: " . $mysqli->error;
        }
    }
    
    // Complete handover tasks
    if (isset($_POST['complete_handover'])) {
        $handover_id = intval($_POST['handover_id']);
        $completed_by = $_SESSION['user_id'];
        
        // Get handover details for audit log
        $handover_sql = "SELECT from_shift, to_shift, shift_date, handover_status FROM nursing_handovers WHERE handover_id = ?";
        $handover_stmt = $mysqli->prepare($handover_sql);
        $handover_stmt->bind_param("i", $handover_id);
        $handover_stmt->execute();
        $handover_result = $handover_stmt->get_result();
        $handover_details = $handover_result->fetch_assoc();
        $old_status = $handover_details['handover_status'] ?? null;
        
        // AUDIT LOG: Complete handover attempt
        audit_log($mysqli, [
            'user_id'     => $completed_by,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'SHIFT_HANDOVER_COMPLETE',
            'module'      => 'Shift Handover',
            'table_name'  => 'nursing_handovers',
            'entity_type' => 'nursing_handover',
            'record_id'   => $handover_id,
            'patient_id'  => $patient_info['patient_id'],
            'visit_id'    => $visit_id,
            'description' => "Attempting to mark handover as completed. Handover ID: " . $handover_id,
            'status'      => 'ATTEMPT',
            'old_values'  => ['handover_status' => $old_status],
            'new_values'  => [
                'handover_status' => 'completed',
                'tasks_completed_by' => $completed_by
            ]
        ]);
        
        $update_sql = "UPDATE nursing_handovers 
                      SET tasks_completed_at = NOW(),
                          handover_status = 'completed'
                      WHERE handover_id = ?";
        
        $update_stmt = $mysqli->prepare($update_sql);
        $update_stmt->bind_param("i", $handover_id);
        
        if ($update_stmt->execute()) {
            // AUDIT LOG: Successful handover completion
            audit_log($mysqli, [
                'user_id'     => $completed_by,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'SHIFT_HANDOVER_COMPLETE',
                'module'      => 'Shift Handover',
                'table_name'  => 'nursing_handovers',
                'entity_type' => 'nursing_handover',
                'record_id'   => $handover_id,
                'patient_id'  => $patient_info['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Handover marked as completed. Handover ID: " . $handover_id,
                'status'      => 'SUCCESS',
                'old_values'  => ['handover_status' => $old_status],
                'new_values'  => [
                    'handover_status' => 'completed',
                    'tasks_completed_at' => date('Y-m-d H:i:s')
                ]
            ]);
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Handover marked as completed";
            header("Location: shift_handover.php?visit_id=" . $visit_id);
            exit;
        } else {
            // AUDIT LOG: Failed handover completion
            audit_log($mysqli, [
                'user_id'     => $completed_by,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'SHIFT_HANDOVER_COMPLETE',
                'module'      => 'Shift Handover',
                'table_name'  => 'nursing_handovers',
                'entity_type' => 'nursing_handover',
                'record_id'   => $handover_id,
                'patient_id'  => $patient_info['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Failed to complete handover. Error: " . $mysqli->error,
                'status'      => 'FAILED',
                'old_values'  => null,
                'new_values'  => null
            ]);
            
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error completing handover: " . $mysqli->error;
        }
    }
}

// Get patient full name
$full_name = $patient_info['patient_first_name'] . 
            ($patient_info['patient_middle_name'] ? ' ' . $patient_info['patient_middle_name'] : '') . 
            ' ' . $patient_info['patient_last_name'];

// Get latest vitals
$vitals_sql = "SELECT * FROM patient_vitals 
              WHERE visit_id = ? 
              ORDER BY recorded_at DESC LIMIT 1";
$vitals_stmt = $mysqli->prepare($vitals_sql);
$vitals_stmt->bind_param("i", $visit_id);
$vitals_stmt->execute();
$vitals_result = $vitals_stmt->get_result();
$latest_vitals = $vitals_result->fetch_assoc();

// Function to get shift badge
function getShiftBadge($shift) {
    switch($shift) {
        case 'morning':
            return '<span class="badge badge-info"><i class="fas fa-sun mr-1"></i>Morning</span>';
        case 'evening':
            return '<span class="badge badge-warning"><i class="fas fa-moon mr-1"></i>Evening</span>';
        case 'night':
            return '<span class="badge badge-dark"><i class="fas fa-star mr-1"></i>Night</span>';
        default:
            return '<span class="badge badge-secondary">' . $shift . '</span>';
    }
}

// Function to get handover status badge
function getHandoverStatusBadge($status) {
    switch($status) {
        case 'completed':
            return '<span class="badge badge-success"><i class="fas fa-check-circle mr-1"></i>Completed</span>';
        case 'acknowledged':
            return '<span class="badge badge-primary"><i class="fas fa-thumbs-up mr-1"></i>Acknowledged</span>';
        case 'pending':
            return '<span class="badge badge-warning"><i class="fas fa-clock mr-1"></i>Pending</span>';
        default:
            return '<span class="badge badge-secondary">' . $status . '</span>';
    }
}

// Function to get priority badge
function getHandoverPriorityBadge($priority) {
    switch($priority) {
        case 'urgent':
            return '<span class="badge badge-danger"><i class="fas fa-exclamation-triangle mr-1"></i>Urgent</span>';
        case 'routine':
            return '<span class="badge badge-info"><i class="fas fa-clock mr-1"></i>Routine</span>';
        default:
            return '<span class="badge badge-secondary">' . $priority . '</span>';
    }
}
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0">
            <i class="fas fa-fw fa-exchange-alt mr-2"></i>Shift Handover: <?php echo htmlspecialchars($patient_info['patient_mrn']); ?>
        </h3>
        <div class="card-tools">
            <div class="btn-group">
                <button type="button" class="btn btn-light" onclick="window.history.back()">
                    <i class="fas fa-arrow-left mr-2"></i>Back
                </button>
                <?php if (!$todays_handover): ?>
                <button type="button" class="btn btn-success" data-toggle="modal" data-target="#createHandoverModal">
                    <i class="fas fa-plus mr-2"></i>Create Handover
                </button>
                <?php endif; ?>
                <button type="button" class="btn btn-info" onclick="printHandover()">
                    <i class="fas fa-print mr-2"></i>Print Handover
                </button>
                <a href="/clinic/nurse/tasks.php?visit_id=<?php echo $visit_id; ?>" class="btn btn-warning">
                    <i class="fas fa-tasks mr-2"></i>Tasks
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

        <!-- Patient and Shift Info -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card bg-light">
                    <div class="card-body py-2">
                        <div class="row">
                            <div class="col-md-6">
                                <table class="table table-sm table-borderless mb-0">
                                    <tr>
                                        <th width="40%" class="text-muted">Patient:</th>
                                        <td><strong><?php echo htmlspecialchars($full_name); ?></strong></td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">MRN:</th>
                                        <td><span class="badge badge-info"><?php echo htmlspecialchars($patient_info['patient_mrn']); ?></span></td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">Visit Type:</th>
                                        <td>
                                            <span class="badge badge-<?php 
                                                echo $visit_type == 'OPD' ? 'primary' : 
                                                     ($visit_type == 'IPD' ? 'success' : 'danger'); 
                                            ?>">
                                                <?php echo htmlspecialchars($visit_type); ?>
                                            </span>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <div class="row text-center">
                                    <div class="col-4">
                                        <div class="text-muted">Current Shift</div>
                                        <div class="h4">
                                            <?php echo getShiftBadge($current_shift); ?>
                                        </div>
                                        <small class="text-muted">
                                            <?php echo date('H:i'); ?>
                                        </small>
                                    </div>
                                    <div class="col-4">
                                        <div class="text-muted">Next Shift</div>
                                        <div class="h4">
                                            <?php echo getShiftBadge($next_shift); ?>
                                        </div>
                                        <small class="text-muted">
                                            Starts at 
                                            <?php 
                                            switch($next_shift) {
                                                case 'morning': echo '06:00'; break;
                                                case 'evening': echo '14:00'; break;
                                                case 'night': echo '22:00'; break;
                                            }
                                            ?>
                                        </small>
                                    </div>
                                    <div class="col-4">
                                        <div class="text-muted">Total Handovers</div>
                                        <div class="h4"><?php echo count($handovers); ?></div>
                                        <small class="text-muted">
                                            <?php 
                                            $today_count = array_filter($handovers, function($h) use ($today) { 
                                                return $h['shift_date'] == $today; 
                                            });
                                            echo count($today_count) . ' today';
                                            ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Patient Summary -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-info py-2">
                        <h6 class="card-title mb-0 text-white">
                            <i class="fas fa-user-md mr-2"></i>Patient Quick Summary
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <!-- Latest Vitals -->
                            <div class="col-md-4">
                                <div class="card">
                                    <div class="card-header bg-light py-1">
                                        <h6 class="card-title mb-0">Latest Vitals</h6>
                                    </div>
                                    <div class="card-body">
                                        <?php if ($latest_vitals): ?>
                                        <div class="row text-center">
                                            <div class="col-6">
                                                <small class="text-muted">BP</small>
                                                <div class="h6">
                                                    <?php echo $latest_vitals['blood_pressure_systolic'] . '/' . $latest_vitals['blood_pressure_diastolic']; ?>
                                                </div>
                                            </div>
                                            <div class="col-6">
                                                <small class="text-muted">Pulse</small>
                                                <div class="h6">
                                                    <?php echo $latest_vitals['pulse_rate']; ?> bpm
                                                </div>
                                            </div>
                                            <div class="col-6">
                                                <small class="text-muted">Temp</small>
                                                <div class="h6">
                                                    <?php echo $latest_vitals['temperature']; ?>°C
                                                </div>
                                            </div>
                                            <div class="col-6">
                                                <small class="text-muted">SpO2</small>
                                                <div class="h6">
                                                    <?php echo $latest_vitals['oxygen_saturation']; ?>%
                                                </div>
                                            </div>
                                        </div>
                                        <small class="text-muted d-block text-center mt-2">
                                            <?php echo date('H:i', strtotime($latest_vitals['recorded_at'])); ?>
                                        </small>
                                        <?php else: ?>
                                        <div class="text-center text-muted">
                                            <i class="fas fa-heartbeat fa-2x mb-2"></i>
                                            <div>No vitals recorded</div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Pending Tasks -->
                            <div class="col-md-4">
                                <div class="card">
                                    <div class="card-header bg-light py-1">
                                        <h6 class="card-title mb-0">Pending Tasks</h6>
                                    </div>
                                    <div class="card-body">
                                        <?php if (!empty($pending_tasks)): ?>
                                        <div class="list-group list-group-flush">
                                            <?php foreach ($pending_tasks as $task): ?>
                                                <div class="list-group-item p-2">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <div class="small">
                                                            <?php echo htmlspecialchars(substr($task['task_description'], 0, 50)); ?>...
                                                        </div>
                                                        <div>
                                                            <?php 
                                                            switch($task['priority']) {
                                                                case 'Critical': echo '<span class="badge badge-danger">C</span>'; break;
                                                                case 'High': echo '<span class="badge badge-warning">H</span>'; break;
                                                                case 'Medium': echo '<span class="badge badge-info">M</span>'; break;
                                                                case 'Low': echo '<span class="badge badge-secondary">L</span>'; break;
                                                            }
                                                            ?>
                                                        </div>
                                                    </div>
                                                    <small class="text-muted">
                                                        <?php echo date('H:i', strtotime($task['scheduled_time'])); ?>
                                                    </small>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="text-center mt-2">
                                            <small class="text-muted">
                                                <?php echo count($pending_tasks); ?> pending tasks
                                            </small>
                                        </div>
                                        <?php else: ?>
                                        <div class="text-center text-muted">
                                            <i class="fas fa-tasks fa-2x mb-2"></i>
                                            <div>No pending tasks</div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Handover Status -->
                            <div class="col-md-4">
                                <div class="card">
                                    <div class="card-header bg-light py-1">
                                        <h6 class="card-title mb-0">Today's Handover</h6>
                                    </div>
                                    <div class="card-body text-center">
                                        <?php if ($todays_handover): ?>
                                        <div class="mb-3">
                                            <?php echo getHandoverStatusBadge($todays_handover['handover_status']); ?>
                                        </div>
                                        <div class="mb-2">
                                            <strong>From:</strong> <?php echo htmlspecialchars($todays_handover['from_nurse_name']); ?>
                                        </div>
                                        <div class="mb-2">
                                            <strong>To:</strong> <?php echo htmlspecialchars($todays_handover['to_nurse_name']); ?>
                                        </div>
                                        <div class="mb-2">
                                            <?php echo getShiftBadge($todays_handover['from_shift']); ?>
                                            <i class="fas fa-arrow-right mx-2"></i>
                                            <?php echo getShiftBadge($todays_handover['to_shift']); ?>
                                        </div>
                                        <?php if ($todays_handover['acknowledged_at']): ?>
                                        <div class="text-success small">
                                            <i class="fas fa-check-circle mr-1"></i>
                                            Acknowledged at <?php echo date('H:i', strtotime($todays_handover['acknowledged_at'])); ?>
                                        </div>
                                        <?php endif; ?>
                                        <?php else: ?>
                                        <div class="text-muted mb-3">
                                            <i class="fas fa-exchange-alt fa-3x"></i>
                                        </div>
                                        <div class="h6 text-muted">No handover created</div>
                                        <button type="button" class="btn btn-sm btn-primary mt-2" 
                                                data-toggle="modal" data-target="#createHandoverModal">
                                            Create Handover
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Handover History -->
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-info py-2">
                        <h4 class="card-title mb-0 text-white">
                            <i class="fas fa-history mr-2"></i>Handover History
                            <span class="badge badge-light float-right"><?php echo count($handovers); ?> handovers</span>
                        </h4>
                    </div>
                    <div class="card-body p-0">
                        <?php if (!empty($handovers)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Date & Shift</th>
                                            <th>Nurse Handover</th>
                                            <th>Status & Priority</th>
                                            <th>Critical Issues</th>
                                            <th class="text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $current_date = null;
                                        foreach ($handovers as $handover): 
                                            $is_today = ($handover['shift_date'] == $today);
                                            $row_class = $is_today ? 'table-info' : '';
                                            
                                            if ($current_date != $handover['shift_date']) {
                                                $current_date = $handover['shift_date'];
                                                $date_display = date('M j, Y', strtotime($handover['shift_date']));
                                                if ($is_today) {
                                                    $date_display = '<strong>Today</strong>';
                                                }
                                        ?>
                                            <tr class="bg-light">
                                                <td colspan="5" class="font-weight-bold">
                                                    <i class="fas fa-calendar-day mr-2"></i><?php echo $date_display; ?>
                                                </td>
                                            </tr>
                                        <?php } ?>
                                            <tr class="<?php echo $row_class; ?>">
                                                <td>
                                                    <div class="mb-1">
                                                        <?php echo getShiftBadge($handover['from_shift']); ?>
                                                        <i class="fas fa-arrow-right mx-1"></i>
                                                        <?php echo getShiftBadge($handover['to_shift']); ?>
                                                    </div>
                                                    <small class="text-muted">
                                                        <?php echo date('H:i', strtotime($handover['created_at'])); ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <div>
                                                        <strong>From:</strong> <?php echo htmlspecialchars($handover['from_nurse_name']); ?>
                                                    </div>
                                                    <div>
                                                        <strong>To:</strong> <?php echo htmlspecialchars($handover['to_nurse_name']); ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="mb-1">
                                                        <?php echo getHandoverStatusBadge($handover['handover_status']); ?>
                                                    </div>
                                                    <div>
                                                        <?php echo getHandoverPriorityBadge($handover['priority']); ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if ($handover['critical_issues']): ?>
                                                        <div class="text-danger">
                                                            <i class="fas fa-exclamation-triangle mr-1"></i>
                                                            <?php echo htmlspecialchars(substr($handover['critical_issues'], 0, 50)); ?>...
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="text-muted">No critical issues</span>
                                                    <?php endif; ?>
                                                    <?php if ($handover['pending_medications']): ?>
                                                        <div class="text-warning small mt-1">
                                                            <i class="fas fa-pills mr-1"></i>
                                                            Pending meds
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <button type="button" class="btn btn-sm btn-info" 
                                                            onclick="viewHandoverDetails(<?php echo htmlspecialchars(json_encode($handover)); ?>)"
                                                            title="View Details">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <?php if ($handover['handover_status'] == 'pending' && $handover['to_nurse_id'] == $_SESSION['user_id']): ?>
                                                        <button type="button" class="btn btn-sm btn-warning" 
                                                                data-toggle="modal" data-target="#acknowledgeHandoverModal"
                                                                onclick="setAcknowledgeHandover(<?php echo $handover['handover_id']; ?>)"
                                                                title="Acknowledge">
                                                            <i class="fas fa-thumbs-up"></i>
                                                        </button>
                                                    <?php elseif ($handover['handover_status'] == 'acknowledged' && $handover['to_nurse_id'] == $_SESSION['user_id']): ?>
                                                        <button type="button" class="btn btn-sm btn-success" 
                                                                data-toggle="modal" data-target="#completeHandoverModal"
                                                                onclick="setCompleteHandover(<?php echo $handover['handover_id']; ?>)"
                                                                title="Mark Complete">
                                                            <i class="fas fa-check"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-exchange-alt fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">No Handover History</h5>
                                <p class="text-muted">No shift handovers have been recorded for this patient.</p>
                                <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#createHandoverModal">
                                    <i class="fas fa-plus mr-2"></i>Create First Handover
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Handover Templates -->
        <div class="row mt-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-secondary py-2">
                        <h6 class="card-title mb-0 text-white">
                            <i class="fas fa-clipboard-list mr-2"></i>Handover Templates
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <div class="card template-card" data-template="stable">
                                    <div class="card-body text-center p-3">
                                        <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                                        <h6>Stable Patient</h6>
                                        <small class="text-muted">For routine handover</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <div class="card template-card" data-template="critical">
                                    <div class="card-body text-center p-3">
                                        <i class="fas fa-exclamation-triangle fa-2x text-danger mb-2"></i>
                                        <h6>Critical Patient</h6>
                                        <small class="text-muted">For urgent handover</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <div class="card template-card" data-template="postop">
                                    <div class="card-body text-center p-3">
                                        <i class="fas fa-procedures fa-2x text-warning mb-2"></i>
                                        <h6>Post-Operative</h6>
                                        <small class="text-muted">Surgical patient handover</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Create Handover Modal -->
<div class="modal fade" id="createHandoverModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" id="createHandoverForm">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Create Shift Handover</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="shift_date">Shift Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="shift_date" name="shift_date" 
                                       value="<?php echo $today; ?>" required>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="from_shift">From Shift <span class="text-danger">*</span></label>
                                <select class="form-control" id="from_shift" name="from_shift" required>
                                    <option value="morning" <?php echo $current_shift == 'morning' ? 'selected' : ''; ?>>Morning</option>
                                    <option value="evening" <?php echo $current_shift == 'evening' ? 'selected' : ''; ?>>Evening</option>
                                    <option value="night" <?php echo $current_shift == 'night' ? 'selected' : ''; ?>>Night</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="to_shift">To Shift <span class="text-danger">*</span></label>
                                <select class="form-control" id="to_shift" name="to_shift" required>
                                    <option value="morning">Morning</option>
                                    <option value="evening" <?php echo $next_shift == 'evening' ? 'selected' : ''; ?>>Evening</option>
                                    <option value="night" <?php echo $next_shift == 'night' ? 'selected' : ''; ?>>Night</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="to_nurse_id">Handover To <span class="text-danger">*</span></label>
                                <select class="form-control" id="to_nurse_id" name="to_nurse_id" required>
                                    <option value="">Select Nurse</option>
                                    <?php foreach ($available_nurses as $nurse): ?>
                                        <option value="<?php echo $nurse['user_id']; ?>">
                                            <?php echo htmlspecialchars($nurse['user_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="priority">Priority <span class="text-danger">*</span></label>
                                <select class="form-control" id="priority" name="priority" required>
                                    <option value="routine">Routine</option>
                                    <option value="urgent">Urgent</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Pending Tasks for Handover -->
                    <?php if (!empty($pending_tasks)): ?>
                    <div class="card mb-3">
                        <div class="card-header bg-warning py-2">
                            <h6 class="card-title mb-0">
                                <i class="fas fa-tasks mr-2"></i>Include Pending Tasks in Handover
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input" id="select_all_tasks" 
                                           onclick="toggleAllTasks(this.checked)">
                                    <label class="custom-control-label" for="select_all_tasks">
                                        <strong>Select All Tasks (<?php echo count($pending_tasks); ?>)</strong>
                                    </label>
                                </div>
                            </div>
                            <div class="row">
                                <?php foreach ($pending_tasks as $task): ?>
                                    <div class="col-md-6 mb-2">
                                        <div class="custom-control custom-checkbox">
                                            <input type="checkbox" class="custom-control-input task-checkbox" 
                                                   id="task_<?php echo $task['id']; ?>" 
                                                   name="handover_tasks[]" value="<?php echo $task['id']; ?>">
                                            <label class="custom-control-label" for="task_<?php echo $task['id']; ?>">
                                                <div class="d-flex justify-content-between">
                                                    <div>
                                                        <?php echo htmlspecialchars(substr($task['task_description'], 0, 40)); ?>...
                                                    </div>
                                                    <div>
                                                        <?php 
                                                        switch($task['priority']) {
                                                            case 'Critical': echo '<span class="badge badge-danger">C</span>'; break;
                                                            case 'High': echo '<span class="badge badge-warning">H</span>'; break;
                                                            case 'Medium': echo '<span class="badge badge-info">M</span>'; break;
                                                            case 'Low': echo '<span class="badge badge-secondary">L</span>'; break;
                                                        }
                                                        ?>
                                                    </div>
                                                </div>
                                                <small class="text-muted d-block">
                                                    <?php echo date('H:i', strtotime($task['scheduled_time'])); ?>
                                                </small>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Handover Notes -->
                    <div class="form-group">
                        <label for="handover_notes">Handover Notes <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="handover_notes" name="handover_notes" 
                                  rows="4" placeholder="Patient status, care provided, observations..." required></textarea>
                    </div>
                    
                    <!-- Critical Issues -->
                    <div class="form-group">
                        <label for="critical_issues">Critical Issues / Concerns</label>
                        <textarea class="form-control" id="critical_issues" name="critical_issues" 
                                  rows="2" placeholder="Any critical issues requiring immediate attention..."></textarea>
                    </div>
                    
                    <!-- Pending Medications -->
                    <div class="form-group">
                        <label for="pending_medications">Pending Medications</label>
                        <textarea class="form-control" id="pending_medications" name="pending_medications" 
                                  rows="2" placeholder="Medications due, IV infusions, PRN medications..."></textarea>
                    </div>
                    
                    <!-- Special Instructions -->
                    <div class="form-group">
                        <label for="special_instructions">Special Instructions</label>
                        <textarea class="form-control" id="special_instructions" name="special_instructions" 
                                  rows="2" placeholder="Any special care instructions, family concerns..."></textarea>
                    </div>
                    
                    <!-- Quick Templates -->
                    <div class="form-group">
                        <label>Quick Templates:</label>
                        <div class="btn-group btn-group-sm d-flex" role="group">
                            <button type="button" class="btn btn-outline-primary flex-fill" onclick="applyTemplate('stable')">
                                <i class="fas fa-check-circle mr-1"></i>Stable
                            </button>
                            <button type="button" class="btn btn-outline-warning flex-fill" onclick="applyTemplate('monitoring')">
                                <i class="fas fa-heartbeat mr-1"></i>Monitoring
                            </button>
                            <button type="button" class="btn btn-outline-danger flex-fill" onclick="applyTemplate('critical')">
                                <i class="fas fa-exclamation-triangle mr-1"></i>Critical
                            </button>
                            <button type="button" class="btn btn-outline-success flex-fill" onclick="applyTemplate('discharge')">
                                <i class="fas fa-home mr-1"></i>Discharge Prep
                            </button>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" name="create_handover" class="btn btn-primary">
                        <i class="fas fa-paper-plane mr-2"></i>Create Handover
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Acknowledge Handover Modal -->
<div class="modal fade" id="acknowledgeHandoverModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST" id="acknowledgeHandoverForm">
                <input type="hidden" name="handover_id" id="acknowledge_handover_id">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title">Acknowledge Handover</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label for="acknowledgement_notes">Acknowledgement Notes</label>
                        <textarea class="form-control" id="acknowledgement_notes" name="acknowledgement_notes" 
                                  rows="3" placeholder="Any questions or clarifications..."></textarea>
                    </div>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle mr-2"></i>
                        By acknowledging, you confirm receipt and understanding of the handover information.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" name="acknowledge_handover" class="btn btn-warning">
                        <i class="fas fa-thumbs-up mr-2"></i>Acknowledge Handover
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Complete Handover Modal -->
<div class="modal fade" id="completeHandoverModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST" id="completeHandoverForm">
                <input type="hidden" name="handover_id" id="complete_handover_id">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">Mark Handover as Complete</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle mr-2"></i>
                        Marking this handover as complete indicates that all handover tasks have been addressed.
                    </div>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        This action cannot be undone.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" name="complete_handover" class="btn btn-success">
                        <i class="fas fa-check mr-2"></i>Mark as Complete
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Handover Details Modal -->
<div class="modal fade" id="handoverDetailsModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">Handover Details</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body" id="handoverDetailsContent">
                <!-- Content will be loaded dynamically -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="printHandover()">
                    <i class="fas fa-print mr-2"></i>Print
                </button>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize date picker
    $('#shift_date').flatpickr({
        dateFormat: 'Y-m-d',
        maxDate: 'today'
    });
    
    // Template card click handlers
    $('.template-card').click(function() {
        const template = $(this).data('template');
        applyTemplate(template);
    });
    
    // Auto-expand textareas
    $('textarea').on('input', function() {
        this.style.height = 'auto';
        this.style.height = (this.scrollHeight) + 'px';
    }).trigger('input');
    
    // Set default to_shift based on current shift
    updateToShift();
    $('#from_shift').change(updateToShift);
});

function updateToShift() {
    const fromShift = $('#from_shift').val();
    let toShift = '';
    
    switch(fromShift) {
        case 'morning':
            toShift = 'evening';
            break;
        case 'evening':
            toShift = 'night';
            break;
        case 'night':
            toShift = 'morning';
            break;
    }
    
    $('#to_shift').val(toShift);
}

function toggleAllTasks(checked) {
    $('.task-checkbox').prop('checked', checked);
}

function applyTemplate(template) {
    let notes = '';
    let critical = '';
    let medications = '';
    let instructions = '';
    let priority = 'routine';
    
    const now = new Date();
    const timeString = now.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
    const dateString = now.toLocaleDateString();
    
    switch(template) {
        case 'stable':
            notes = `Patient stable throughout shift. Vital signs within normal limits. Patient resting comfortably. No new complaints. All care provided as per plan.`;
            critical = `None. Patient stable.`;
            medications = `All medications administered as scheduled. Next doses as per medication chart.`;
            instructions = `Continue current plan of care. Monitor vital signs 4-hourly.`;
            priority = 'routine';
            break;
            
        case 'monitoring':
            notes = `Patient requires close monitoring. Vital signs stable but borderline. Resting with occasional discomfort. Responding well to interventions.`;
            critical = `Requires frequent observation. Monitor for any deterioration.`;
            medications = `Medications administered. Pain medication due in 2 hours.`;
            instructions = `Monitor vital signs hourly. Notify doctor if condition changes.`;
            priority = 'routine';
            break;
            
        case 'critical':
            notes = `CRITICAL PATIENT. Requires constant monitoring. Unstable vital signs. Receiving multiple interventions. Family notified.`;
            critical = `CRITICAL: Requires 1:1 nursing. Unstable condition. Emergency equipment at bedside.`;
            medications = `Multiple IV infusions running. Critical medications in progress.`;
            instructions = `DO NOT LEAVE UNATTENDED. Notify doctor immediately for any changes. Document all observations.`;
            priority = 'urgent';
            break;
            
        case 'discharge':
            notes = `Patient preparing for discharge. Stable condition. All discharge criteria met. Patient education provided.`;
            critical = `None. Ready for discharge.`;
            medications = `Discharge medications prepared. Instructions given to patient.`;
            instructions = `Complete discharge paperwork. Ensure follow-up appointment scheduled.`;
            priority = 'routine';
            break;
    }
    
    // Add timestamp
    notes = `[${timeString}] ${notes}`;
    
    // Apply to form fields
    $('#handover_notes').val(notes);
    $('#critical_issues').val(critical);
    $('#pending_medications').val(medications);
    $('#special_instructions').val(instructions);
    $('#priority').val(priority);
    
    // Trigger auto-expand
    $('textarea').trigger('input');
    
    showToast(`Applied ${template} template`, 'success');
}

function setAcknowledgeHandover(handoverId) {
    $('#acknowledge_handover_id').val(handoverId);
}

function setCompleteHandover(handoverId) {
    $('#complete_handover_id').val(handoverId);
}

function viewHandoverDetails(handover) {
    const modalContent = document.getElementById('handoverDetailsContent');
    const shiftDate = new Date(handover.shift_date);
    const createdDate = new Date(handover.created_at);
    const acknowledgedDate = handover.acknowledged_at ? new Date(handover.acknowledged_at) : null;
    const completedDate = handover.tasks_completed_at ? new Date(handover.tasks_completed_at) : null;
    
    let html = `
        <div class="card mb-3">
            <div class="card-header bg-light py-2">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="card-title mb-0">
                            Shift Handover - ${shiftDate.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })}
                        </h5>
                        <div class="mt-1">
                            ${getShiftBadge(handover.from_shift)} 
                            <i class="fas fa-arrow-right mx-2"></i>
                            ${getShiftBadge(handover.to_shift)}
                        </div>
                    </div>
                    <div>
                        ${getHandoverStatusBadge(handover.handover_status)}
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless">
                            <tr>
                                <th width="40%">From Nurse:</th>
                                <td>${handover.from_nurse_name}</td>
                            </tr>
                            <tr>
                                <th>To Nurse:</th>
                                <td>${handover.to_nurse_name}</td>
                            </tr>
                            <tr>
                                <th>Priority:</th>
                                <td>${getHandoverPriorityBadge(handover.priority)}</td>
                            </tr>
                            <tr>
                                <th>Created:</th>
                                <td>${createdDate.toLocaleDateString()} ${createdDate.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless">
    `;
    
    if (acknowledgedDate) {
        html += `<tr>
                    <th width="40%">Acknowledged:</th>
                    <td>${acknowledgedDate.toLocaleDateString()} ${acknowledgedDate.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</td>
                </tr>
                <tr>
                    <th>Acknowledged By:</th>
                    <td>${handover.acknowledged_by_name || 'N/A'}</td>
                </tr>`;
    }
    
    if (completedDate) {
        html += `<tr>
                    <th>Completed:</th>
                    <td>${completedDate.toLocaleDateString()} ${completedDate.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</td>
                </tr>`;
    }
    
    html += `       </table>
                    </div>
                </div>
                
                <!-- Handover Content -->
                <div class="handover-content">
    `;
    
    if (handover.handover_notes) {
        html += `<div class="mb-4">
                    <h6><i class="fas fa-clipboard-list mr-2"></i>Handover Notes</h6>
                    <div class="p-3 bg-light rounded">${handover.handover_notes.replace(/\n/g, '<br>')}</div>
                </div>`;
    }
    
    if (handover.critical_issues) {
        html += `<div class="mb-4">
                    <h6 class="text-danger"><i class="fas fa-exclamation-triangle mr-2"></i>Critical Issues</h6>
                    <div class="p-3 bg-light rounded">${handover.critical_issues.replace(/\n/g, '<br>')}</div>
                </div>`;
    }
    
    if (handover.pending_medications) {
        html += `<div class="mb-4">
                    <h6 class="text-warning"><i class="fas fa-pills mr-2"></i>Pending Medications</h6>
                    <div class="p-3 bg-light rounded">${handover.pending_medications.replace(/\n/g, '<br>')}</div>
                </div>`;
    }
    
    if (handover.special_instructions) {
        html += `<div class="mb-4">
                    <h6 class="text-info"><i class="fas fa-info-circle mr-2"></i>Special Instructions</h6>
                    <div class="p-3 bg-light rounded">${handover.special_instructions.replace(/\n/g, '<br>')}</div>
                </div>`;
    }
    
    if (handover.acknowledgement_notes) {
        html += `<div class="mb-4">
                    <h6 class="text-success"><i class="fas fa-thumbs-up mr-2"></i>Acknowledgement Notes</h6>
                    <div class="p-3 bg-light rounded">${handover.acknowledgement_notes.replace(/\n/g, '<br>')}</div>
                </div>`;
    }
    
    // Get handover tasks
    $.ajax({
        url: '/clinic/ajax/get_handover_tasks.php',
        method: 'POST',
        data: { handover_id: handover.handover_id },
        dataType: 'json',
        success: function(response) {
            if (response.success && response.tasks.length > 0) {
                let tasksHtml = `<div class="mb-4">
                    <h6><i class="fas fa-tasks mr-2"></i>Handover Tasks</h6>
                    <div class="list-group">`;
                
                response.tasks.forEach(function(task) {
                    tasksHtml += `<div class="list-group-item">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>${task.task_description}</div>
                            <div>
                                <span class="badge badge-${task.status == 'completed' ? 'success' : 'warning'}">
                                    ${task.status}
                                </span>
                            </div>
                        </div>
                        ${task.completion_notes ? `<small class="text-muted">${task.completion_notes}</small>` : ''}
                    </div>`;
                });
                
                tasksHtml += `</div></div>`;
                $(modalContent).find('.handover-content').append(tasksHtml);
            }
        }
    });
    
    html += `   </div>
                
                <div class="text-muted small mt-3">
                    <i class="fas fa-info-circle mr-1"></i>
                    Handover ID: ${handover.handover_id}
                </div>
            </div>
        </div>
    `;
    
    modalContent.innerHTML = html;
    $('#handoverDetailsModal').modal('show');
}

function printHandover() {
    const printWindow = window.open('', '_blank');
    const patientInfo = `Patient: ${full_name} | MRN: ${patient_info.patient_mrn} | Visit ID: ${visit_id}`;
    
    let html = `
        <html>
        <head>
            <title>Shift Handover - ${patient_info.patient_mrn}</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                h1, h2 { color: #333; }
                .handover-section { margin: 20px 0; padding: 15px; border: 1px solid #ddd; border-radius: 5px; }
                .critical { border-color: #dc3545; background-color: #f8d7da; }
                .info { border-color: #17a2b8; background-color: #d1ecf1; }
                .warning { border-color: #ffc107; background-color: #fff3cd; }
                .signature { margin-top: 50px; border-top: 1px solid #333; padding-top: 20px; }
                @media print {
                    .no-print { display: none; }
                    body { margin: 0; padding: 10px; }
                }
            </style>
        </head>
        <body>
            <h1>Shift Handover Report</h1>
            <h2>${patientInfo}</h2>
            <p>Printed: ${new Date().toLocaleString()}</p>
            
            <div class="handover-section">
                <h3>Current Status</h3>
                <p><strong>Shift:</strong> ${current_shift} (${new Date().toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})})</p>
                <p><strong>Next Shift:</strong> ${next_shift}</p>
            </div>
            
            <div class="handover-section info">
                <h3>Patient Summary</h3>
                <p><strong>Name:</strong> ${full_name}</p>
                <p><strong>MRN:</strong> ${patient_info.patient_mrn}</p>
                <p><strong>Visit Type:</strong> ${visit_type}</p>
    `;
    
    <?php if ($latest_vitals): ?>
    html += `<p><strong>Latest Vitals:</strong> BP: ${latest_vitals.blood_pressure_systolic}/${latest_vitals.blood_pressure_diastolic}, 
            Pulse: ${latest_vitals.pulse_rate} bpm, Temp: ${latest_vitals.temperature}°C, SpO2: ${latest_vitals.oxygen_saturation}%</p>`;
    <?php endif; ?>
    
    html += `</div>`;
    
    <?php if (!empty($pending_tasks)): ?>
    html += `<div class="handover-section warning">
                <h3>Pending Tasks (${pending_tasks.length})</h3>
                <ul>`;
    <?php foreach ($pending_tasks as $task): ?>
    html += `<li>${task.task_description} (${task.priority} - ${new Date(task.scheduled_time).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})})</li>`;
    <?php endforeach; ?>
    html += `</ul></div>`;
    <?php endif; ?>
    
    <?php if ($todays_handover): ?>
    html += `<div class="handover-section">
                <h3>Latest Handover (Today)</h3>
                <p><strong>From:</strong> ${todays_handover.from_nurse_name} (${todays_handover.from_shift})</p>
                <p><strong>To:</strong> ${todays_handover.to_nurse_name} (${todays_handover.to_shift})</p>
                <p><strong>Status:</strong> ${todays_handover.handover_status}</p>
                ${todays_handover.critical_issues ? `<p class="critical"><strong>Critical Issues:</strong> ${todays_handover.critical_issues}</p>` : ''}
            </div>`;
    <?php endif; ?>
    
    html += `
            <div class="signature">
                <p>Prepared by: _______________________</p>
                <p>Date: ${new Date().toLocaleDateString()}</p>
                <p>Time: ${new Date().toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</p>
            </div>
            
            <div class="no-print">
                <br><br>
                <button onclick="window.print()">Print</button>
                <button onclick="window.close()">Close</button>
            </div>
        </body>
        </html>
    `;
    
    printWindow.document.write(html);
    printWindow.document.close();
}

function showToast(message, type = 'info') {
    const toast = $(`
        <div class="toast" role="alert" aria-live="assertive" aria-atomic="true" data-delay="3000">
            <div class="toast-header bg-${type} text-white">
                <strong class="mr-auto"><i class="fas fa-info-circle mr-2"></i>Notification</strong>
                <button type="button" class="ml-2 mb-1 close text-white" data-dismiss="toast" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="toast-body">
                ${message}
            </div>
        </div>
    `);
    
    $('.toast-container').remove();
    $('<div class="toast-container position-fixed" style="top: 20px; right: 20px; z-index: 9999;"></div>')
        .append(toast)
        .appendTo('body');
    
    toast.toast('show');
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + H for handover
    if (e.ctrlKey && e.keyCode === 72) {
        e.preventDefault();
        $('#createHandoverModal').modal('show');
    }
    // Ctrl + A for acknowledge
    if (e.ctrlKey && e.keyCode === 65) {
        e.preventDefault();
        const pendingHandover = $('.btn-warning[title="Acknowledge"]').first();
        if (pendingHandover.length) {
            pendingHandover.click();
        }
    }
    // Ctrl + P for print
    if (e.ctrlKey && e.keyCode === 80) {
        e.preventDefault();
        printHandover();
    }
});
</script>

<style>
.template-card {
    cursor: pointer;
    transition: transform 0.2s, box-shadow 0.2s;
}
.template-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    border-color: #007bff;
}

.table-info {
    background-color: #d1ecf1 !important;
}

.list-group-item {
    border-left: none;
    border-right: none;
}
.list-group-item:first-child {
    border-top: none;
}
.list-group-item:last-child {
    border-bottom: none;
}

/* Print styles */
@media print {
    .card-header, .card-tools, .btn, form, .modal,
    .card-footer, .toast-container, .template-card,
    .no-print {
        display: none !important;
    }
    .card {
        border: none !important;
        box-shadow: none !important;
    }
    .card-body {
        padding: 0 !important;
    }
    .handover-section {
        page-break-inside: avoid;
    }
}
</style>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
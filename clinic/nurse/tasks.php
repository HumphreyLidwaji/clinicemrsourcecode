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
        'module'      => 'Nursing Tasks',
        'table_name'  => 'visits',
        'entity_type' => 'visit',
        'record_id'   => null,
        'patient_id'  => null,
        'visit_id'    => null,
        'description' => "Attempted to access nursing_tasks.php with invalid visit ID: " . $visit_id,
        'status'      => 'FAILED',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    header("Location: /clinic/dashboard.php");
    exit;
}

// Get visit and patient information in a single query
$sql = "SELECT 
            v.*,
            p.*,
            v.visit_type,
            v.visit_number,
            v.visit_datetime,
            v.admission_datetime,
            v.discharge_datetime,
            ia.admission_number,
            ia.admission_status,
            ia.ward_id,
            ia.bed_id
        FROM visits v
        JOIN patients p ON v.patient_id = p.patient_id
        LEFT JOIN ipd_admissions ia ON v.visit_id = ia.visit_id
        WHERE v.visit_id = ?";
        
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $visit_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Visit not found";
    
    // AUDIT LOG: Visit not found
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'ACCESS',
        'module'      => 'Nursing Tasks',
        'table_name'  => 'visits',
        'entity_type' => 'visit',
        'record_id'   => $visit_id,
        'patient_id'  => null,
        'visit_id'    => $visit_id,
        'description' => "Attempted to access nursing tasks for visit ID " . $visit_id . " but visit not found",
        'status'      => 'FAILED',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    header("Location: /clinic/dashboard.php");
    exit;
}

$visit_info = $result->fetch_assoc();
$patient_info = $visit_info;
$visit_type = $visit_info['visit_type'];

// AUDIT LOG: Successful access to nursing tasks page
audit_log($mysqli, [
    'user_id'     => $_SESSION['user_id'] ?? null,
    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
    'action'      => 'VIEW',
    'module'      => 'Nursing Tasks',
    'table_name'  => 'visits',
    'entity_type' => 'visit',
    'record_id'   => $visit_id,
    'patient_id'  => $patient_info['patient_id'],
    'visit_id'    => $visit_id,
    'description' => "Accessed nursing tasks page for visit ID " . $visit_id . " (Patient: " . $patient_info['first_name'] . " " . $patient_info['last_name'] . "). Visit type: " . $visit_type,
    'status'      => 'SUCCESS',
    'old_values'  => null,
    'new_values'  => null
]);

// Get nursing tasks for this visit
$tasks_sql = "SELECT 
              nt.*, 
              u1.user_name as assigned_to_name,
              u2.user_name as assigned_by_name
              FROM nursing_tasks nt
              JOIN users u1 ON nt.assigned_to = u1.user_id
              LEFT JOIN users u2 ON nt.assigned_by = u2.user_id
              WHERE nt.visit_id = ?
              ORDER BY 
                CASE nt.priority 
                    WHEN 'Critical' THEN 1
                    WHEN 'High' THEN 2
                    WHEN 'Medium' THEN 3
                    WHEN 'Low' THEN 4
                END,
                nt.due_datetime ASC";

$tasks_stmt = $mysqli->prepare($tasks_sql);
$tasks_stmt->bind_param("i", $visit_id);
$tasks_stmt->execute();
$tasks_result = $tasks_stmt->get_result();
$nursing_tasks = $tasks_result->fetch_all(MYSQLI_ASSOC);

// Get available nurses for assignment
$nurses_sql = "SELECT user_id, user_name FROM users 
             
               ORDER BY user_name";
$nurses_result = $mysqli->query($nurses_sql);
$available_nurses = $nurses_result->fetch_all(MYSQLI_ASSOC);

// Handle form submission for new task
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add new task
    if (isset($_POST['add_task'])) {
        $task_type = $_POST['task_type'];
        $task_description = trim($_POST['task_description']);
        $scheduled_time = $_POST['scheduled_time'];
        $priority = $_POST['priority'];
        $assigned_to = $_POST['assigned_to'];
        $notes = trim($_POST['notes'] ?? '');
        $assigned_by = $_SESSION['user_id'];
        
        // Get assigned nurse name for audit log
        $assigned_nurse_sql = "SELECT user_name FROM users WHERE user_id = ?";
        $assigned_nurse_stmt = $mysqli->prepare($assigned_nurse_sql);
        $assigned_nurse_stmt->bind_param("i", $assigned_to);
        $assigned_nurse_stmt->execute();
        $assigned_nurse_result = $assigned_nurse_stmt->get_result();
        $assigned_nurse = $assigned_nurse_result->fetch_assoc();
        $assigned_nurse_name = $assigned_nurse['user_name'] ?? 'Unknown';
        
        // AUDIT LOG: Add task attempt
        audit_log($mysqli, [
            'user_id'     => $assigned_by,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'NURSING_TASK_CREATE',
            'module'      => 'Nursing Tasks',
            'table_name'  => 'nursing_tasks',
            'entity_type' => 'nursing_task',
            'record_id'   => null,
            'patient_id'  => $patient_info['patient_id'],
            'visit_id'    => $visit_id,
            'description' => "Attempting to add nursing task. Type: " . $task_type . ", Priority: " . $priority . ", Scheduled: " . $scheduled_time . ", Assigned to: " . $assigned_nurse_name,
            'status'      => 'ATTEMPT',
            'old_values'  => null,
            'new_values'  => [
                'task_type' => $task_type,
                'priority' => $priority,
                'scheduled_time' => $scheduled_time,
                'assigned_to' => $assigned_to,
                'assigned_by' => $assigned_by
            ]
        ]);
        
        // Insert query for new table structure
        $insert_sql = "INSERT INTO nursing_tasks 
                      (visit_id, task_type, task_description, scheduled_time, 
                       due_datetime, priority, assigned_to, assigned_by, notes, status)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')";
        
        $insert_stmt = $mysqli->prepare($insert_sql);
        $insert_stmt->bind_param("isssssiis", 
            $visit_id,
            $task_type,
            $task_description,
            $scheduled_time,
            $scheduled_time, // Using scheduled_time as due_datetime initially
            $priority,
            $assigned_to,
            $assigned_by,
            $notes
        );
        
        if ($insert_stmt->execute()) {
            $task_id = $insert_stmt->insert_id;
            
            // AUDIT LOG: Successful task creation
            audit_log($mysqli, [
                'user_id'     => $assigned_by,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'NURSING_TASK_CREATE',
                'module'      => 'Nursing Tasks',
                'table_name'  => 'nursing_tasks',
                'entity_type' => 'nursing_task',
                'record_id'   => $task_id,
                'patient_id'  => $patient_info['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Nursing task added successfully. Task ID: " . $task_id . ", Type: " . $task_type,
                'status'      => 'SUCCESS',
                'old_values'  => null,
                'new_values'  => [
                    'id' => $task_id,
                    'task_type' => $task_type,
                    'priority' => $priority,
                    'scheduled_time' => $scheduled_time,
                    'assigned_to' => $assigned_to,
                    'assigned_by' => $assigned_by,
                    'status' => 'Pending',
                    'created_at' => date('Y-m-d H:i:s')
                ]
            ]);
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Task added successfully";
            header("Location: tasks.php?visit_id=" . $visit_id);
            exit;
        } else {
            // AUDIT LOG: Failed task creation
            audit_log($mysqli, [
                'user_id'     => $assigned_by,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'NURSING_TASK_CREATE',
                'module'      => 'Nursing Tasks',
                'table_name'  => 'nursing_tasks',
                'entity_type' => 'nursing_task',
                'record_id'   => null,
                'patient_id'  => $patient_info['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Failed to add nursing task. Error: " . $mysqli->error,
                'status'      => 'FAILED',
                'old_values'  => null,
                'new_values'  => null
            ]);
            
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error adding task: " . $mysqli->error;
        }
    }
    
    // Update task status
    if (isset($_POST['update_task'])) {
        $task_id = intval($_POST['task_id']);
        $status = $_POST['status'];
        $outcome = trim($_POST['outcome'] ?? '');
        $updated_by = $_SESSION['user_id'];
        
        // Get current task details for audit log
        $current_task_sql = "SELECT task_type, priority, status FROM nursing_tasks WHERE id = ?";
        $current_task_stmt = $mysqli->prepare($current_task_sql);
        $current_task_stmt->bind_param("i", $task_id);
        $current_task_stmt->execute();
        $current_task_result = $current_task_stmt->get_result();
        $current_task = $current_task_result->fetch_assoc();
        $old_status = $current_task['status'] ?? null;
        $task_type = $current_task['task_type'] ?? null;
        
        // AUDIT LOG: Update task attempt
        audit_log($mysqli, [
            'user_id'     => $updated_by,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'NURSING_TASK_UPDATE',
            'module'      => 'Nursing Tasks',
            'table_name'  => 'nursing_tasks',
            'entity_type' => 'nursing_task',
            'record_id'   => $task_id,
            'patient_id'  => $patient_info['patient_id'],
            'visit_id'    => $visit_id,
            'description' => "Attempting to update nursing task. Task ID: " . $task_id . " (" . $task_type . "). Changing status from " . $old_status . " to " . $status,
            'status'      => 'ATTEMPT',
            'old_values'  => ['status' => $old_status],
            'new_values'  => [
                'status' => $status,
                'outcome' => $outcome
            ]
        ]);
        
        // Build update query
        $update_sql = "UPDATE nursing_tasks SET status = ?, outcome = ?";
        
        if ($status === 'Completed') {
            $update_sql .= ", completed_time = NOW()";
        }
        
        $update_sql .= " WHERE id = ?";
        
        $update_stmt = $mysqli->prepare($update_sql);
        
        if ($status === 'Completed') {
            $update_stmt->bind_param("ssi", $status, $outcome, $task_id);
        } else {
            $update_stmt->bind_param("ssi", $status, $outcome, $task_id);
        }
        
        if ($update_stmt->execute()) {
            // AUDIT LOG: Successful task update
            audit_log($mysqli, [
                'user_id'     => $updated_by,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'NURSING_TASK_UPDATE',
                'module'      => 'Nursing Tasks',
                'table_name'  => 'nursing_tasks',
                'entity_type' => 'nursing_task',
                'record_id'   => $task_id,
                'patient_id'  => $patient_info['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Nursing task updated successfully. Task ID: " . $task_id . " (" . $task_type . "). New status: " . $status,
                'status'      => 'SUCCESS',
                'old_values'  => ['status' => $old_status],
                'new_values'  => [
                    'status' => $status,
                    'outcome' => $outcome,
                    'completed_time' => $status === 'Completed' ? date('Y-m-d H:i:s') : null,
                    'updated_at' => date('Y-m-d H:i:s')
                ]
            ]);
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Task updated successfully";
            header("Location: nursing_tasks.php?visit_id=" . $visit_id);
            exit;
        } else {
            // AUDIT LOG: Failed task update
            audit_log($mysqli, [
                'user_id'     => $updated_by,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'NURSING_TASK_UPDATE',
                'module'      => 'Nursing Tasks',
                'table_name'  => 'nursing_tasks',
                'entity_type' => 'nursing_task',
                'record_id'   => $task_id,
                'patient_id'  => $patient_info['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Failed to update nursing task. Error: " . $mysqli->error,
                'status'      => 'FAILED',
                'old_values'  => null,
                'new_values'  => null
            ]);
            
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error updating task: " . $mysqli->error;
        }
    }
    
    // Delete task
    if (isset($_POST['delete_task'])) {
        $task_id = intval($_POST['task_id']);
        $deleted_by = $_SESSION['user_id'];
        
        // Get task details for audit log
        $task_sql = "SELECT task_type, priority, status FROM nursing_tasks WHERE id = ?";
        $task_stmt = $mysqli->prepare($task_sql);
        $task_stmt->bind_param("i", $task_id);
        $task_stmt->execute();
        $task_result = $task_stmt->get_result();
        $task_details = $task_result->fetch_assoc();
        $task_type = $task_details['task_type'] ?? null;
        
        // AUDIT LOG: Delete task attempt
        audit_log($mysqli, [
            'user_id'     => $deleted_by,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'NURSING_TASK_DELETE',
            'module'      => 'Nursing Tasks',
            'table_name'  => 'nursing_tasks',
            'entity_type' => 'nursing_task',
            'record_id'   => $task_id,
            'patient_id'  => $patient_info['patient_id'],
            'visit_id'    => $visit_id,
            'description' => "Attempting to delete nursing task. Task ID: " . $task_id . " (" . $task_type . ")",
            'status'      => 'ATTEMPT',
            'old_values'  => [
                'task_type' => $task_type,
                'priority' => $task_details['priority'] ?? null,
                'status' => $task_details['status'] ?? null
            ],
            'new_values'  => null
        ]);
        
        $delete_sql = "DELETE FROM nursing_tasks WHERE id = ?";
        $delete_stmt = $mysqli->prepare($delete_sql);
        $delete_stmt->bind_param("i", $task_id);
        
        if ($delete_stmt->execute()) {
            // AUDIT LOG: Successful task deletion
            audit_log($mysqli, [
                'user_id'     => $deleted_by,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'NURSING_TASK_DELETE',
                'module'      => 'Nursing Tasks',
                'table_name'  => 'nursing_tasks',
                'entity_type' => 'nursing_task',
                'record_id'   => $task_id,
                'patient_id'  => $patient_info['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Nursing task deleted successfully. Task ID: " . $task_id . " (" . $task_type . ")",
                'status'      => 'SUCCESS',
                'old_values'  => [
                    'task_type' => $task_type,
                    'priority' => $task_details['priority'] ?? null,
                    'status' => $task_details['status'] ?? null
                ],
                'new_values'  => null
            ]);
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Task deleted successfully";
            header("Location: nursing_tasks.php?visit_id=" . $visit_id);
            exit;
        } else {
            // AUDIT LOG: Failed task deletion
            audit_log($mysqli, [
                'user_id'     => $deleted_by,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'NURSING_TASK_DELETE',
                'module'      => 'Nursing Tasks',
                'table_name'  => 'nursing_tasks',
                'entity_type' => 'nursing_task',
                'record_id'   => $task_id,
                'patient_id'  => $patient_info['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Failed to delete nursing task. Error: " . $mysqli->error,
                'status'      => 'FAILED',
                'old_values'  => null,
                'new_values'  => null
            ]);
            
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error deleting task: " . $mysqli->error;
        }
    }
}

// Get patient full name
$full_name = $patient_info['first_name'] . 
            ($patient_info['middle_name'] ? ' ' . $patient_info['middle_name'] : '') . 
            ' ' . $patient_info['last_name'];

// Calculate age
$age = '';
if (!empty($patient_info['date_of_birth'])) {
    $birthDate = new DateTime($patient_info['date_of_birth']);
    $today = new DateTime();
    $age = $today->diff($birthDate)->y . ' years';
}

// Get visit number based on type
$visit_number = $visit_info['visit_number'];
if ($visit_type === 'IPD' && !empty($visit_info['admission_number'])) {
    $visit_number = $visit_info['admission_number'];
}

// Get visit date based on type
$visit_date = $visit_info['visit_datetime'];
if ($visit_type === 'IPD' && !empty($visit_info['admission_datetime'])) {
    $visit_date = $visit_info['admission_datetime'];
}

// Task type options (from enum)
$task_types = [
    'vitals' => 'Vitals Monitoring',
    'medication' => 'Medication Administration',
    'wound_care' => 'Wound Care',
    'assessment' => 'Patient Assessment',
    'io_chart' => 'I/O Charting',
    'procedure' => 'Procedure',
    'education' => 'Patient Education',
    'other' => 'Other'
];

// Priority options (from enum)
$priorities = [
    'Low' => 'Low',
    'Medium' => 'Medium',
    'High' => 'High',
    'Critical' => 'Critical'
];

// Status options (from enum)
$status_options = [
    'Pending' => 'Pending',
    'In Progress' => 'In Progress',
    'Completed' => 'Completed',
    'Overdue' => 'Overdue',
    'Cancelled' => 'Cancelled'
];

// Function to get priority badge
function getPriorityBadge($priority) {
    switch($priority) {
        case 'Critical':
            return '<span class="badge badge-danger"><i class="fas fa-exclamation-circle mr-1"></i>Critical</span>';
        case 'High':
            return '<span class="badge badge-warning"><i class="fas fa-exclamation-triangle mr-1"></i>High</span>';
        case 'Medium':
            return '<span class="badge badge-info"><i class="fas fa-info-circle mr-1"></i>Medium</span>';
        case 'Low':
            return '<span class="badge badge-secondary"><i class="fas fa-arrow-down mr-1"></i>Low</span>';
        default:
            return '<span class="badge badge-light">' . $priority . '</span>';
    }
}

// Function to get status badge
function getTaskStatusBadge($status) {
    switch($status) {
        case 'Completed':
            return '<span class="badge badge-success"><i class="fas fa-check-circle mr-1"></i>Completed</span>';
        case 'In Progress':
            return '<span class="badge badge-primary"><i class="fas fa-spinner mr-1"></i>In Progress</span>';
        case 'Overdue':
            return '<span class="badge badge-danger"><i class="fas fa-clock mr-1"></i>Overdue</span>';
        case 'Cancelled':
            return '<span class="badge badge-secondary"><i class="fas fa-ban mr-1"></i>Cancelled</span>';
        case 'Pending':
        default:
            return '<span class="badge badge-warning"><i class="fas fa-clock mr-1"></i>Pending</span>';
    }
}

// Function to get task type icon
function getTaskTypeIcon($type) {
    switch($type) {
        case 'vitals':
            return '<i class="fas fa-heartbeat text-danger"></i>';
        case 'medication':
            return '<i class="fas fa-pills text-primary"></i>';
        case 'wound_care':
            return '<i class="fas fa-band-aid text-warning"></i>';
        case 'assessment':
            return '<i class="fas fa-stethoscope text-success"></i>';
        case 'io_chart':
            return '<i class="fas fa-tint text-info"></i>';
        case 'procedure':
            return '<i class="fas fa-procedures text-purple"></i>';
        case 'education':
            return '<i class="fas fa-book text-teal"></i>';
        case 'other':
        default:
            return '<i class="fas fa-tasks text-secondary"></i>';
    }
}
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0">
            <i class="fas fa-fw fa-tasks mr-2"></i>Nursing Tasks: <?php echo htmlspecialchars($patient_info['patient_mrn']); ?>
        </h3>
        <div class="card-tools">
            <div class="btn-group">
                <button type="button" class="btn btn-light" onclick="window.history.back()">
                    <i class="fas fa-arrow-left mr-2"></i>Back
                </button>
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

        <!-- Patient and Visit Info -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card bg-light">
                    <div class="card-body py-2">
                        <div class="row">
                            <div class="col-md-8">
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
                                                <th class="text-muted">Age:</th>
                                                <td><span class="badge badge-secondary"><?php echo $age ?: 'N/A'; ?></span></td>
                                            </tr>
                                            <tr>
                                                <th class="text-muted">Sex:</th>
                                                <td><span class="badge badge-secondary"><?php echo htmlspecialchars($patient_info['sex'] ?? 'N/A'); ?></span></td>
                                            </tr>
                                        </table>
                                    </div>
                                    <div class="col-md-6">
                                        <table class="table table-sm table-borderless mb-0">
                                            <tr>
                                                <th width="40%" class="text-muted">Visit Type:</th>
                                                <td>
                                                    <span class="badge badge-<?php 
                                                        echo $visit_type == 'OPD' ? 'primary' : 
                                                             ($visit_type == 'IPD' ? 'success' : 'danger'); 
                                                    ?>">
                                                        <?php echo htmlspecialchars($visit_type); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th class="text-muted">Visit #:</th>
                                                <td><?php echo htmlspecialchars($visit_number); ?></td>
                                            </tr>
                                            <tr>
                                                <th class="text-muted">Date:</th>
                                                <td>
                                                    <?php echo !empty($visit_date) ? date('M j, Y H:i', strtotime($visit_date)) : 'N/A'; ?>
                                                </td>
                                            </tr>
                                            <?php if ($visit_type === 'IPD' && !empty($visit_info['ward_id'])): ?>
                                            <tr>
                                                <th class="text-muted">Ward/Bed:</th>
                                                <td>
                                                    <span class="badge badge-info">
                                                        Ward <?php echo htmlspecialchars($visit_info['ward_id']); ?>
                                                        <?php if (!empty($visit_info['bed_id'])): ?>
                                                            / Bed <?php echo htmlspecialchars($visit_info['bed_id']); ?>
                                                        <?php endif; ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <?php endif; ?>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 text-right">
                                <div class="mt-2">
                                    <span class="h5">
                                        <i class="fas fa-tasks text-primary mr-1"></i>
                                        <span class="badge badge-light"><?php echo count($nursing_tasks); ?> Tasks</span>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Add New Task Form -->
            <div class="col-md-5">
                <div class="card">
                    <div class="card-header bg-success py-2">
                        <h4 class="card-title mb-0 text-white">
                            <i class="fas fa-plus-circle mr-2"></i>Add New Task
                        </h4>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="taskForm">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="task_type">Task Type <span class="text-danger">*</span></label>
                                        <select class="form-control" id="task_type" name="task_type" required>
                                            <option value="">Select Type</option>
                                            <?php foreach ($task_types as $key => $label): ?>
                                                <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="priority">Priority <span class="text-danger">*</span></label>
                                        <select class="form-control" id="priority" name="priority" required>
                                            <option value="">Select Priority</option>
                                            <?php foreach ($priorities as $key => $label): ?>
                                                <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="task_description">Task Description <span class="text-danger">*</span></label>
                                <textarea class="form-control" id="task_description" name="task_description" 
                                          rows="3" placeholder="Describe the task..." required></textarea>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="scheduled_time">Scheduled Date & Time <span class="text-danger">*</span></label>
                                        <input type="datetime-local" class="form-control" id="scheduled_time" 
                                               name="scheduled_time" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="assigned_to">Assign To <span class="text-danger">*</span></label>
                                        <select class="form-control" id="assigned_to" name="assigned_to" required>
                                            <option value="">Select Nurse</option>
                                            <?php foreach ($available_nurses as $nurse): ?>
                                                <option value="<?php echo $nurse['user_id']; ?>">
                                                    <?php echo htmlspecialchars($nurse['user_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="notes">Additional Notes</label>
                                <textarea class="form-control" id="notes" name="notes" 
                                          rows="2" placeholder="Any special instructions..."></textarea>
                            </div>
                            
                            <!-- Quick Schedule Options -->
                            <div class="form-group">
                                <label>Quick Schedule:</label>
                                <div class="btn-group btn-group-sm d-flex" role="group">
                                    <button type="button" class="btn btn-outline-primary flex-fill" onclick="setSchedule('30min')">
                                        <i class="fas fa-clock mr-1"></i>30 min
                                    </button>
                                    <button type="button" class="btn btn-outline-primary flex-fill" onclick="setSchedule('2hr')">
                                        <i class="fas fa-clock mr-1"></i>2 hours
                                    </button>
                                    <button type="button" class="btn btn-outline-primary flex-fill" onclick="setSchedule('nextshift')">
                                        <i class="fas fa-clock mr-1"></i>Next Shift
                                    </button>
                                    <button type="button" class="btn btn-outline-primary flex-fill" onclick="setSchedule('tomorrow')">
                                        <i class="fas fa-sun mr-1"></i>Tomorrow
                                    </button>
                                </div>
                            </div>
                            
                            <div class="form-group mb-0">
                                <button type="submit" name="add_task" class="btn btn-success btn-lg btn-block">
                                    <i class="fas fa-save mr-2"></i>Save Task
                                </button>
                            </div>
                        </form>
                        
                        <!-- Quick Task Templates -->
                        <div class="mt-4 p-3 bg-light rounded">
                            <h6><i class="fas fa-clone mr-2"></i>Quick Task Templates</h6>
                            <div class="row">
                                <div class="col-6 mb-2">
                                    <button type="button" class="btn btn-outline-primary btn-sm btn-block" onclick="applyTemplate('vitals')">
                                        <i class="fas fa-heartbeat mr-1"></i>Vitals Check
                                    </button>
                                </div>
                                <div class="col-6 mb-2">
                                    <button type="button" class="btn btn-outline-primary btn-sm btn-block" onclick="applyTemplate('medication')">
                                        <i class="fas fa-pills mr-1"></i>Medication
                                    </button>
                                </div>
                                <div class="col-6 mb-2">
                                    <button type="button" class="btn btn-outline-primary btn-sm btn-block" onclick="applyTemplate('assessment')">
                                        <i class="fas fa-stethoscope mr-1"></i>Assessment
                                    </button>
                                </div>
                                <div class="col-6 mb-2">
                                    <button type="button" class="btn btn-outline-primary btn-sm btn-block" onclick="applyTemplate('education')">
                                        <i class="fas fa-book mr-1"></i>Education
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tasks List -->
            <div class="col-md-7">
                <div class="card">
                    <div class="card-header bg-info py-2">
                        <h4 class="card-title mb-0 text-white">
                            <i class="fas fa-list-check mr-2"></i>Tasks List
                        </h4>
                        <div class="card-tools">
                            <span class="badge badge-light"><?php echo count($nursing_tasks); ?> tasks</span>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <?php if (!empty($nursing_tasks)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th width="5%"></th>
                                            <th width="40%">Task Description</th>
                                            <th width="15%">Schedule</th>
                                            <th width="15%">Status & Priority</th>
                                            <th width="15%">Assigned To</th>
                                            <th width="10%" class="text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($nursing_tasks as $task): 
                                            // Check if task is overdue
                                            $is_overdue = ($task['status'] == 'Pending' && 
                                                         strtotime($task['due_datetime']) < time());
                                            $row_class = $is_overdue ? 'table-danger' : '';
                                            
                                            if ($task['status'] == 'Completed') {
                                                $row_class = 'table-success';
                                            } elseif ($task['status'] == 'In Progress') {
                                                $row_class = 'table-primary';
                                            }
                                        ?>
                                            <tr class="<?php echo $row_class; ?>">
                                                <td class="text-center">
                                                    <?php echo getTaskTypeIcon($task['task_type']); ?>
                                                </td>
                                                <td>
                                                    <div class="font-weight-bold">
                                                        <?php echo htmlspecialchars($task['task_description']); ?>
                                                    </div>
                                                    <?php if ($task['task_type'] != 'other'): ?>
                                                        <small class="text-muted">
                                                            <?php echo $task_types[$task['task_type']] ?? $task['task_type']; ?>
                                                        </small>
                                                    <?php endif; ?>
                                                    <?php if (!empty($task['outcome'])): ?>
                                                        <div class="text-muted mt-1">
                                                            <small><i class="fas fa-note mr-1"></i><?php echo htmlspecialchars(substr($task['outcome'], 0, 100)); ?>...</small>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div>
                                                        <i class="far fa-calendar-alt text-primary mr-1"></i>
                                                        <?php echo date('M j', strtotime($task['due_datetime'])); ?>
                                                    </div>
                                                    <div>
                                                        <i class="far fa-clock text-secondary mr-1"></i>
                                                        <?php echo date('H:i', strtotime($task['due_datetime'])); ?>
                                                    </div>
                                                    <?php if ($task['completed_time']): ?>
                                                        <div class="text-success small">
                                                            <i class="fas fa-check-circle mr-1"></i>
                                                            <?php echo date('H:i', strtotime($task['completed_time'])); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="mb-1">
                                                        <?php echo getTaskStatusBadge($task['status']); ?>
                                                    </div>
                                                    <div>
                                                        <?php echo getPriorityBadge($task['priority']); ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div>
                                                        <?php echo htmlspecialchars($task['assigned_to_name']); ?>
                                                    </div>
                                                    <?php if ($task['assigned_by_name']): ?>
                                                        <small class="text-muted">
                                                            By: <?php echo htmlspecialchars($task['assigned_by_name']); ?>
                                                        </small>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <button type="button" class="btn btn-sm btn-info" 
                                                            onclick="viewTaskDetails(<?php echo htmlspecialchars(json_encode($task)); ?>)"
                                                            title="View Details">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-warning" 
                                                            onclick="editTask(<?php echo htmlspecialchars(json_encode($task)); ?>)"
                                                            title="Update Status">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                                        <button type="submit" name="delete_task" class="btn btn-sm btn-danger" 
                                                                title="Delete Task" onclick="return confirm('Delete this task?')">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-tasks fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">No Nursing Tasks</h5>
                                <p class="text-muted">No tasks have been assigned for this visit yet.</p>
                                <a href="#taskForm" class="btn btn-success">
                                    <i class="fas fa-plus-circle mr-2"></i>Add First Task
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Task Statistics -->
                <?php if (!empty($nursing_tasks)): ?>
                <div class="row mt-4">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header bg-warning py-2">
                                <h4 class="card-title mb-0">
                                    <i class="fas fa-chart-bar mr-2"></i>Task Statistics
                                </h4>
                            </div>
                            <div class="card-body">
                                <div class="row text-center">
                                    <div class="col-3">
                                        <div class="text-muted">Critical</div>
                                        <div class="h4 text-danger">
                                            <?php 
                                            $critical = array_filter($nursing_tasks, function($t) { 
                                                return $t['priority'] == 'Critical'; 
                                            });
                                            echo count($critical);
                                            ?>
                                        </div>
                                    </div>
                                    <div class="col-3">
                                        <div class="text-muted">High</div>
                                        <div class="h4 text-warning">
                                            <?php 
                                            $high = array_filter($nursing_tasks, function($t) { 
                                                return $t['priority'] == 'High'; 
                                            });
                                            echo count($high);
                                            ?>
                                        </div>
                                    </div>
                                    <div class="col-3">
                                        <div class="text-muted">In Progress</div>
                                        <div class="h4 text-primary">
                                            <?php 
                                            $inProgress = array_filter($nursing_tasks, function($t) { 
                                                return $t['status'] == 'In Progress'; 
                                            });
                                            echo count($inProgress);
                                            ?>
                                        </div>
                                    </div>
                                    <div class="col-3">
                                        <div class="text-muted">Overdue</div>
                                        <div class="h4 text-danger">
                                            <?php 
                                            $overdue = array_filter($nursing_tasks, function($t) { 
                                                return ($t['status'] == 'Pending' && 
                                                       strtotime($t['due_datetime']) < time()); 
                                            });
                                            echo count($overdue);
                                            ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Task Details Modal -->
<div class="modal fade" id="taskDetailsModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Task Details</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body" id="taskDetailsContent">
                <!-- Content will be loaded dynamically -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="printTask()">
                    <i class="fas fa-print mr-2"></i>Print
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Update Task Modal -->
<div class="modal fade" id="updateTaskModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST" id="updateTaskForm">
                <input type="hidden" name="task_id" id="update_task_id">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title">Update Task Status</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label for="update_status">Status <span class="text-danger">*</span></label>
                        <select class="form-control" id="update_status" name="status" required>
                            <?php foreach ($status_options as $key => $label): ?>
                                <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="outcome">Outcome / Notes</label>
                        <textarea class="form-control" id="outcome" name="outcome" 
                                  rows="3" placeholder="Describe task outcome..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_task" class="btn btn-warning">
                        <i class="fas fa-save mr-2"></i>Update Task
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Auto-close alerts after 5 seconds
    setTimeout(function() {
        $('.alert').fadeOut('slow');
    }, 5000);

    // Tooltip initialization
    $('[title]').tooltip();

    // Set default scheduled time to now + 30 minutes
    const now = new Date();
    now.setMinutes(now.getMinutes() + 30);
    document.getElementById('scheduled_time').value = now.toISOString().slice(0, 16);

    // Auto-expand textareas
    $('textarea').on('input', function() {
        this.style.height = 'auto';
        this.style.height = (this.scrollHeight) + 'px';
    });

    // Auto-check for overdue tasks
    checkOverdueTasks();
    // Check every minute
    setInterval(checkOverdueTasks, 60000);
});

function applyTemplate(template) {
    let description = '';
    let priority = 'Medium';
    let taskType = template;
    
    switch(template) {
        case 'vitals':
            description = 'Monitor and record vital signs: Temperature, Blood Pressure, Pulse, Respiratory Rate, SpO2';
            priority = 'Medium';
            break;
        case 'medication':
            description = 'Administer scheduled medications as per doctor\'s orders';
            priority = 'High';
            break;
        case 'assessment':
            description = 'Complete comprehensive patient assessment including pain, neuro, respiratory, cardiovascular systems';
            priority = 'Medium';
            break;
        case 'education':
            description = 'Provide patient education regarding diagnosis, medications, and self-care';
            priority = 'Low';
            break;
    }
    
    // Populate form
    $('#task_type').val(taskType);
    $('#task_description').val(description);
    $('#priority').val(priority);
    
    showToast(`Applied ${template} template`, 'info');
}

function setSchedule(timeframe) {
    const now = new Date();
    let scheduled = new Date();
    
    switch(timeframe) {
        case '30min':
            scheduled.setMinutes(now.getMinutes() + 30);
            break;
        case '2hr':
            scheduled.setHours(now.getHours() + 2);
            break;
        case 'nextshift':
            // Set to next shift (morning: 6am, evening: 2pm, night: 10pm)
            const hour = now.getHours();
            if (hour < 6) scheduled.setHours(6, 0, 0, 0);
            else if (hour < 14) scheduled.setHours(14, 0, 0, 0);
            else if (hour < 22) scheduled.setHours(22, 0, 0, 0);
            else {
                scheduled.setDate(scheduled.getDate() + 1);
                scheduled.setHours(6, 0, 0, 0);
            }
            break;
        case 'tomorrow':
            scheduled.setDate(now.getDate() + 1);
            scheduled.setHours(9, 0, 0, 0); // 9 AM tomorrow
            break;
    }
    
    document.getElementById('scheduled_time').value = scheduled.toISOString().slice(0, 16);
    showToast(`Scheduled for ${timeframe}`, 'success');
}

function viewTaskDetails(task) {
    const modalContent = document.getElementById('taskDetailsContent');
    const scheduled = new Date(task.due_datetime);
    const completed = task.completed_time ? new Date(task.completed_time) : null;
    const created = task.created_at ? new Date(task.created_at) : null;
    
    let html = `
        <div class="card mb-3">
            <div class="card-header bg-light py-2">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="mb-0">
                            ${getTaskTypeIcon(task.task_type)} 
                            <span class="ml-2">${task.task_description}</span>
                        </h5>
                        <small class="text-muted">
                            ${task.task_type ? (task_types[task.task_type] || task.task_type) : 'Nursing Task'}
                        </small>
                    </div>
                    <div>
                        ${getTaskStatusBadge(task.status)}
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless">
                            <tr>
                                <th width="40%">Assigned To:</th>
                                <td>${task.assigned_to_name}</td>
                            </tr>
                            <tr>
                                <th>Assigned By:</th>
                                <td>${task.assigned_by_name || 'N/A'}</td>
                            </tr>
                            <tr>
                                <th>Scheduled:</th>
                                <td>${scheduled.toLocaleDateString()} ${scheduled.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless">
                            <tr>
                                <th width="40%">Priority:</th>
                                <td>${getPriorityBadge(task.priority)}</td>
                            </tr>
                            <tr>
                                <th>Status:</th>
                                <td>${getTaskStatusBadge(task.status)}</td>
                            </tr>
    `;
    
    if (completed) {
        html += `<tr>
                    <th>Completed:</th>
                    <td>${completed.toLocaleDateString()} ${completed.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</td>
                </tr>`;
    }
    
    html += `       </table>
                    </div>
                </div>
                
                ${task.notes ? `<div class="mb-3">
                    <h6><i class="fas fa-note mr-2"></i>Instructions</h6>
                    <div class="p-3 bg-light rounded">${task.notes.replace(/\n/g, '<br>')}</div>
                </div>` : ''}
                
                ${task.outcome ? `<div class="mb-3">
                    <h6><i class="fas fa-clipboard-check mr-2"></i>Outcome</h6>
                    <div class="p-3 bg-light rounded">${task.outcome.replace(/\n/g, '<br>')}</div>
                </div>` : ''}
                
                <div class="text-muted small mt-3">
                    <i class="fas fa-info-circle mr-1"></i>
                    Task ID: ${task.id} | Created: ${created ? created.toLocaleString() : 'N/A'}
                </div>
            </div>
        </div>
        
        <div class="text-center">
            <button type="button" class="btn btn-warning" onclick="editTask(${JSON.stringify(task)})">
                <i class="fas fa-edit mr-2"></i>Update Status
            </button>
        </div>
    `;
    
    modalContent.innerHTML = html;
    $('#taskDetailsModal').modal('show');
}

function editTask(task) {
    if (task.status === 'Completed') {
        alert('Completed tasks cannot be edited.');
        return;
    }
    
    // Populate update form
    $('#update_task_id').val(task.id);
    $('#update_status').val(task.status);
    $('#outcome').val(task.outcome || '');
    
    // Show update modal
    $('#updateTaskModal').modal('show');
}

function checkOverdueTasks() {
    const now = new Date();
    $('table tbody tr').each(function() {
        const row = $(this);
        const status = row.find('td:nth-child(4) .badge').text();
        const scheduledText = row.find('td:nth-child(3) div:nth-child(2)').text();
        
        if (status.includes('Pending') && scheduledText) {
            // Parse the scheduled time
            const scheduledDate = new Date(scheduledText.trim());
            if (scheduledDate < now) {
                row.addClass('table-danger');
                row.find('td:nth-child(4)').html('<span class="badge badge-danger"><i class="fas fa-clock mr-1"></i>Overdue</span>');
            }
        }
    });
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

function printTask() {
    window.print();
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + S for save
    if (e.ctrlKey && e.keyCode === 83) {
        e.preventDefault();
        $('#taskForm button[type="submit"]').click();
    }
    // Ctrl + N for new (focus on first field)
    if (e.ctrlKey && e.keyCode === 78) {
        e.preventDefault();
        $('#task_type').focus();
    }
    // Ctrl + T for templates
    if (e.ctrlKey && e.keyCode === 84) {
        e.preventDefault();
        applyTemplate('vitals');
    }
    // Escape to go back
    if (e.keyCode === 27) {
        window.history.back();
    }
});

// Helper functions for UI display
function getTaskTypeIcon(type) {
    switch(type) {
        case 'vitals': return '<i class="fas fa-heartbeat text-danger"></i>';
        case 'medication': return '<i class="fas fa-pills text-primary"></i>';
        case 'wound_care': return '<i class="fas fa-band-aid text-warning"></i>';
        case 'assessment': return '<i class="fas fa-stethoscope text-success"></i>';
        case 'io_chart': return '<i class="fas fa-tint text-info"></i>';
        case 'procedure': return '<i class="fas fa-procedures text-purple"></i>';
        case 'education': return '<i class="fas fa-book text-teal"></i>';
        default: return '<i class="fas fa-tasks text-secondary"></i>';
    }
}

function getTaskStatusBadge(status) {
    switch(status) {
        case 'Completed': return '<span class="badge badge-success"><i class="fas fa-check-circle mr-1"></i>Completed</span>';
        case 'In Progress': return '<span class="badge badge-primary"><i class="fas fa-spinner mr-1"></i>In Progress</span>';
        case 'Overdue': return '<span class="badge badge-danger"><i class="fas fa-clock mr-1"></i>Overdue</span>';
        case 'Cancelled': return '<span class="badge badge-secondary"><i class="fas fa-ban mr-1"></i>Cancelled</span>';
        default: return '<span class="badge badge-warning"><i class="fas fa-clock mr-1"></i>Pending</span>';
    }
}

function getPriorityBadge(priority) {
    switch(priority) {
        case 'Critical': return '<span class="badge badge-danger"><i class="fas fa-exclamation-circle mr-1"></i>Critical</span>';
        case 'High': return '<span class="badge badge-warning"><i class="fas fa-exclamation-triangle mr-1"></i>High</span>';
        case 'Medium': return '<span class="badge badge-info"><i class="fas fa-info-circle mr-1"></i>Medium</span>';
        case 'Low': return '<span class="badge badge-secondary"><i class="fas fa-arrow-down mr-1"></i>Low</span>';
        default: return '<span class="badge badge-light">' + priority + '</span>';
    }
}

const task_types = <?php echo json_encode($task_types); ?>;
</script>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
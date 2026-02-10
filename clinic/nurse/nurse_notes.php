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
        'module'      => 'Nurse Notes',
        'table_name'  => 'visits',
        'entity_type' => 'visit',
        'record_id'   => null,
        'patient_id'  => null,
        'visit_id'    => null,
        'description' => "Attempted to access opd_notes.php with invalid visit ID: " . $visit_id,
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
$nurse_notes = [];
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
        'module'      => 'Nurse Notes',
        'table_name'  => 'visits',
        'entity_type' => 'visit',
        'record_id'   => $visit_id,
        'patient_id'  => null,
        'visit_id'    => $visit_id,
        'description' => "Attempted to access nurse notes for visit ID " . $visit_id . " but visit not found",
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

// Get existing nurse notes for this visit
$notes_sql = "SELECT nn.*, 
              u.user_name as recorded_by_name,
              fu.user_name as finalized_by_name
              FROM nurse_daily_notes nn
              JOIN users u ON nn.recorded_by = u.user_id
              LEFT JOIN users fu ON nn.finalized_by = fu.user_id
              WHERE nn.visit_id = ?
              ORDER BY nn.note_date DESC, 
              FIELD(nn.shift, 'morning', 'evening', 'night')";
$notes_stmt = $mysqli->prepare($notes_sql);
$notes_stmt->bind_param("i", $visit_id);
$notes_stmt->execute();
$notes_result = $notes_stmt->get_result();
$nurse_notes = $notes_result->fetch_all(MYSQLI_ASSOC);

// Get today's notes for current shift
$todays_note = null;
foreach ($nurse_notes as $note) {
    if ($note['note_date'] == $today && $note['shift'] == $current_shift) {
        $todays_note = $note;
        break;
    }
}

// AUDIT LOG: Successful access to nurse notes page
audit_log($mysqli, [
    'user_id'     => $_SESSION['user_id'] ?? null,
    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
    'action'      => 'VIEW',
    'module'      => 'Nurse Notes',
    'table_name'  => 'visits',
    'entity_type' => 'visit',
    'record_id'   => $visit_id,
    'patient_id'  => $patient_info['patient_id'],
    'visit_id'    => $visit_id,
    'description' => "Accessed nurse notes page for visit ID " . $visit_id . " (Patient: " . $patient_info['first_name'] . " " . $patient_info['last_name'] . "). Current shift: " . $current_shift,
    'status'      => 'SUCCESS',
    'old_values'  => null,
    'new_values'  => null
]);

// Handle form submission for new/modified notes
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_note'])) {
        $shift = $_POST['shift'];
        $note_date = $_POST['note_date'];
        $subjective = !empty($_POST['subjective']) ? trim($_POST['subjective']) : null;
        $objective = !empty($_POST['objective']) ? trim($_POST['objective']) : null;
        $assessment = !empty($_POST['assessment']) ? trim($_POST['assessment']) : null;
        $plan = !empty($_POST['plan']) ? trim($_POST['plan']) : null;
        $notes = !empty($_POST['notes']) ? trim($_POST['notes']) : null;
        $recorded_by = $_SESSION['user_id'];
        $note_type = $_POST['note_type'] ?? 'daily';
        $status = isset($_POST['finalize']) ? 'finalized' : 'draft';
        
        // Check if note already exists for this shift/date
        $check_sql = "SELECT note_id, status FROM nurse_daily_notes 
                     WHERE visit_id = ? AND patient_id = ? 
                     AND shift = ? AND note_date = ?";
        $check_stmt = $mysqli->prepare($check_sql);
        $check_stmt->bind_param("iiss", $visit_id, $patient_info['patient_id'], $shift, $note_date);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $existing_note = $check_result->fetch_assoc();
        $old_status = $existing_note['status'] ?? null;
        
        // AUDIT LOG: Save note attempt
        $action = $existing_note ? 'NURSE_NOTE_UPDATE' : 'NURSE_NOTE_CREATE';
        $description = $existing_note ? 
            "Attempting to update nurse note for shift: " . $shift . ", date: " . $note_date :
            "Attempting to create nurse note for shift: " . $shift . ", date: " . $note_date;
        
        audit_log($mysqli, [
            'user_id'     => $recorded_by,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => $action,
            'module'      => 'Nurse Notes',
            'table_name'  => 'nurse_daily_notes',
            'entity_type' => 'nurse_note',
            'record_id'   => $existing_note['note_id'] ?? null,
            'patient_id'  => $patient_info['patient_id'],
            'visit_id'    => $visit_id,
            'description' => $description . ". Status: " . $status,
            'status'      => 'ATTEMPT',
            'old_values'  => $existing_note ? [
                'status' => $old_status,
                'shift' => $shift,
                'note_date' => $note_date
            ] : null,
            'new_values'  => [
                'status' => $status,
                'shift' => $shift,
                'note_date' => $note_date,
                'note_type' => $note_type,
                'recorded_by' => $recorded_by
            ]
        ]);
        
        if ($existing_note) {
            // Update existing note
            $update_sql = "UPDATE nurse_daily_notes 
                          SET subjective = ?, objective = ?, assessment = ?, 
                              plan = ?, notes = ?, note_type = ?, status = ?,
                              recorded_by = ?, recorded_at = NOW()";
            
            if ($status == 'finalized') {
                $update_sql .= ", finalized_at = NOW(), finalized_by = ?";
            }
            
            $update_sql .= " WHERE note_id = ?";
            
            $update_stmt = $mysqli->prepare($update_sql);
            
            if ($status == 'finalized') {
                $update_stmt->bind_param("sssssssii", 
                    $subjective, $objective, $assessment, $plan, $notes, $note_type, $status,
                    $recorded_by, $recorded_by, $existing_note['note_id']
                );
            } else {
                $update_stmt->bind_param("sssssssi", 
                    $subjective, $objective, $assessment, $plan, $notes, $note_type, $status,
                    $recorded_by, $existing_note['note_id']
                );
            }
            
            if ($update_stmt->execute()) {
                $message = $status == 'finalized' ? "Note finalized successfully" : "Note updated successfully";
                
                // AUDIT LOG: Successful note update
                audit_log($mysqli, [
                    'user_id'     => $recorded_by,
                    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                    'action'      => 'NURSE_NOTE_UPDATE',
                    'module'      => 'Nurse Notes',
                    'table_name'  => 'nurse_daily_notes',
                    'entity_type' => 'nurse_note',
                    'record_id'   => $existing_note['note_id'],
                    'patient_id'  => $patient_info['patient_id'],
                    'visit_id'    => $visit_id,
                    'description' => "Nurse note updated successfully. Note ID: " . $existing_note['note_id'] . ", Shift: " . $shift . ", Status: " . $status,
                    'status'      => 'SUCCESS',
                    'old_values'  => [
                        'status' => $old_status,
                        'shift' => $shift,
                        'note_date' => $note_date
                    ],
                    'new_values'  => [
                        'status' => $status,
                        'shift' => $shift,
                        'note_date' => $note_date,
                        'note_type' => $note_type,
                        'recorded_by' => $recorded_by,
                        'updated_at' => date('Y-m-d H:i:s')
                    ]
                ]);
                
                $_SESSION['alert_type'] = "success";
                $_SESSION['alert_message'] = $message;
                header("Location: opd_notes.php?visit_id=" . $visit_id);
                exit;
            } else {
                // AUDIT LOG: Failed note update
                audit_log($mysqli, [
                    'user_id'     => $recorded_by,
                    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                    'action'      => 'NURSE_NOTE_UPDATE',
                    'module'      => 'Nurse Notes',
                    'table_name'  => 'nurse_daily_notes',
                    'entity_type' => 'nurse_note',
                    'record_id'   => $existing_note['note_id'],
                    'patient_id'  => $patient_info['patient_id'],
                    'visit_id'    => $visit_id,
                    'description' => "Failed to update nurse note. Error: " . $mysqli->error,
                    'status'      => 'FAILED',
                    'old_values'  => null,
                    'new_values'  => null
                ]);
                
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = "Error updating note: " . $mysqli->error;
            }
        } else {
            // Insert new note
            $insert_sql = "INSERT INTO nurse_daily_notes 
                          (visit_id, patient_id, shift, note_date, subjective, 
                           objective, assessment, plan, notes, recorded_by, 
                           note_type, status";
            
            if ($status == 'finalized') {
                $insert_sql .= ", finalized_at, finalized_by";
            }
            
            $insert_sql .= ") VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?";
            
            if ($status == 'finalized') {
                $insert_sql .= ", NOW(), ?";
            }
            
            $insert_sql .= ")";
            
            $insert_stmt = $mysqli->prepare($insert_sql);
            
            if ($status == 'finalized') {
                $insert_stmt->bind_param("iisssssssissi",
                    $visit_id, $patient_info['patient_id'], $shift, $note_date,
                    $subjective, $objective, $assessment, $plan, $notes,
                    $recorded_by, $note_type, $status, $recorded_by
                );
            } else {
                $insert_stmt->bind_param("iisssssssiss",
                    $visit_id, $patient_info['patient_id'], $shift, $note_date,
                    $subjective, $objective, $assessment, $plan, $notes,
                    $recorded_by, $note_type, $status
                );
            }
            
            if ($insert_stmt->execute()) {
                $note_id = $insert_stmt->insert_id;
                $message = $status == 'finalized' ? "Note created and finalized successfully" : "Note saved as draft";
                
                // AUDIT LOG: Successful note creation
                audit_log($mysqli, [
                    'user_id'     => $recorded_by,
                    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                    'action'      => 'NURSE_NOTE_CREATE',
                    'module'      => 'Nurse Notes',
                    'table_name'  => 'nurse_daily_notes',
                    'entity_type' => 'nurse_note',
                    'record_id'   => $note_id,
                    'patient_id'  => $patient_info['patient_id'],
                    'visit_id'    => $visit_id,
                    'description' => "Nurse note created successfully. Note ID: " . $note_id . ", Shift: " . $shift . ", Status: " . $status,
                    'status'      => 'SUCCESS',
                    'old_values'  => null,
                    'new_values'  => [
                        'note_id' => $note_id,
                        'status' => $status,
                        'shift' => $shift,
                        'note_date' => $note_date,
                        'note_type' => $note_type,
                        'recorded_by' => $recorded_by,
                        'created_at' => date('Y-m-d H:i:s')
                    ]
                ]);
                
                $_SESSION['alert_type'] = "success";
                $_SESSION['alert_message'] = $message;
                header("Location: opd_notes.php?visit_id=" . $visit_id);
                exit;
            } else {
                // AUDIT LOG: Failed note creation
                audit_log($mysqli, [
                    'user_id'     => $recorded_by,
                    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                    'action'      => 'NURSE_NOTE_CREATE',
                    'module'      => 'Nurse Notes',
                    'table_name'  => 'nurse_daily_notes',
                    'entity_type' => 'nurse_note',
                    'record_id'   => null,
                    'patient_id'  => $patient_info['patient_id'],
                    'visit_id'    => $visit_id,
                    'description' => "Failed to create nurse note. Error: " . $mysqli->error,
                    'status'      => 'FAILED',
                    'old_values'  => null,
                    'new_values'  => null
                ]);
                
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = "Error saving note: " . $mysqli->error;
            }
        }
    }
    
    // Handle note deletion
    if (isset($_POST['delete_note'])) {
        $note_id = intval($_POST['note_id']);
        $deleted_by = $_SESSION['user_id'];
        
        // Get note details for audit log
        $note_sql = "SELECT shift, note_date, status FROM nurse_daily_notes WHERE note_id = ?";
        $note_stmt = $mysqli->prepare($note_sql);
        $note_stmt->bind_param("i", $note_id);
        $note_stmt->execute();
        $note_result = $note_stmt->get_result();
        $note_details = $note_result->fetch_assoc();
        
        // AUDIT LOG: Delete note attempt
        audit_log($mysqli, [
            'user_id'     => $deleted_by,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'NURSE_NOTE_DELETE',
            'module'      => 'Nurse Notes',
            'table_name'  => 'nurse_daily_notes',
            'entity_type' => 'nurse_note',
            'record_id'   => $note_id,
            'patient_id'  => $patient_info['patient_id'],
            'visit_id'    => $visit_id,
            'description' => "Attempting to delete nurse note ID " . $note_id . ". Shift: " . ($note_details['shift'] ?? 'N/A') . ", Date: " . ($note_details['note_date'] ?? 'N/A'),
            'status'      => 'ATTEMPT',
            'old_values'  => [
                'shift' => $note_details['shift'] ?? null,
                'note_date' => $note_details['note_date'] ?? null,
                'status' => $note_details['status'] ?? null
            ],
            'new_values'  => null
        ]);
        
        $delete_sql = "DELETE FROM nurse_daily_notes WHERE note_id = ?";
        $delete_stmt = $mysqli->prepare($delete_sql);
        $delete_stmt->bind_param("i", $note_id);
        
        if ($delete_stmt->execute()) {
            // AUDIT LOG: Successful note deletion
            audit_log($mysqli, [
                'user_id'     => $deleted_by,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'NURSE_NOTE_DELETE',
                'module'      => 'Nurse Notes',
                'table_name'  => 'nurse_daily_notes',
                'entity_type' => 'nurse_note',
                'record_id'   => $note_id,
                'patient_id'  => $patient_info['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Nurse note deleted successfully. Note ID: " . $note_id,
                'status'      => 'SUCCESS',
                'old_values'  => [
                    'shift' => $note_details['shift'] ?? null,
                    'note_date' => $note_details['note_date'] ?? null,
                    'status' => $note_details['status'] ?? null
                ],
                'new_values'  => null
            ]);
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Note deleted successfully";
            header("Location: opd_notes.php?visit_id=" . $visit_id);
            exit;
        } else {
            // AUDIT LOG: Failed note deletion
            audit_log($mysqli, [
                'user_id'     => $deleted_by,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'NURSE_NOTE_DELETE',
                'module'      => 'Nurse Notes',
                'table_name'  => 'nurse_daily_notes',
                'entity_type' => 'nurse_note',
                'record_id'   => $note_id,
                'patient_id'  => $patient_info['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Failed to delete nurse note. Error: " . $mysqli->error,
                'status'      => 'FAILED',
                'old_values'  => null,
                'new_values'  => null
            ]);
            
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error deleting note: " . $mysqli->error;
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
    $today_date = new DateTime();
    $age = $today_date->diff($birthDate)->y . ' years';
}

// Get visit number based on type
$visit_number = $visit_info['visit_number'];
if ($visit_type === 'IPD' && !empty($visit_info['admission_number'])) {
    $visit_number = $visit_info['admission_number'];
}

// Get visit date
$visit_date = $visit_info['visit_datetime'];
if ($visit_type === 'IPD' && !empty($visit_info['admission_datetime'])) {
    $visit_date = $visit_info['admission_datetime'];
}

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

// Function to get status badge
function getStatusBadge($status) {
    switch($status) {
        case 'finalized':
            return '<span class="badge badge-success"><i class="fas fa-lock mr-1"></i>Finalized</span>';
        case 'draft':
            return '<span class="badge badge-warning"><i class="fas fa-edit mr-1"></i>Draft</span>';
        default:
            return '<span class="badge badge-secondary">' . $status . '</span>';
    }
}
?>
<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0">
            <i class="fas fa-fw fa-clipboard mr-2"></i>Nursing Notes: <?php echo htmlspecialchars($patient_info['patient_mrn']); ?>
        </h3>
        <div class="card-tools">
            <div class="btn-group">
                <button type="button" class="btn btn-light" onclick="window.history.back()">
                    <i class="fas fa-arrow-left mr-2"></i>Back
                </button>
                <button type="button" class="btn btn-success" onclick="window.print()">
                    <i class="fas fa-print mr-2"></i>Print Notes
                </button>
                <a href="/clinic/nurse/vitals.php?visit_id=<?php echo $visit_id; ?>" class="btn btn-info">
                    <i class="fas fa-heartbeat mr-2"></i>Vitals
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
                                                <th class="text-muted">Current Shift:</th>
                                                <td><?php echo getShiftBadge($current_shift); ?></td>
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
                                        <i class="fas fa-clipboard-list text-primary mr-1"></i>
                                        <span class="badge badge-light"><?php echo count($nurse_notes); ?> Notes</span>
                                    </span>
                                    <br>
                                    <span class="h6">
                                        <i class="fas fa-calendar-day text-success mr-1"></i>
                                        <span class="badge badge-light"><?php echo date('F j, Y'); ?></span>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- SOAP Notes Form -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-success py-2">
                        <h4 class="card-title mb-0 text-white">
                            <i class="fas fa-edit mr-2"></i><?php echo $todays_note ? 'Update' : 'Record'; ?> Nursing Notes
                            <span class="badge badge-light float-right"><?php echo getShiftBadge($current_shift); ?></span>
                        </h4>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="nursingNotesForm">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="shift">Shift</label>
                                        <select class="form-control" id="shift" name="shift" required>
                                            <option value="morning" <?php echo $current_shift == 'morning' ? 'selected' : ''; ?>>Morning (6am - 2pm)</option>
                                            <option value="evening" <?php echo $current_shift == 'evening' ? 'selected' : ''; ?>>Evening (2pm - 10pm)</option>
                                            <option value="night" <?php echo $current_shift == 'night' ? 'selected' : ''; ?>>Night (10pm - 6am)</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="note_date">Date</label>
                                        <input type="date" class="form-control" id="note_date" name="note_date" 
                                               value="<?php echo $today; ?>" required>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="note_type">Note Type</label>
                                <select class="form-control" id="note_type" name="note_type">
                                    <option value="daily" selected>Daily Progress Note</option>
                                    <option value="admission">Admission Note</option>
                                    <option value="discharge">Discharge Note</option>
                                    <option value="transfer">Transfer Note</option>
                                    <option value="incident">Incident Report</option>
                                    <option value="education">Patient Education</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            
                            <!-- SOAP Format -->
                            <div class="accordion" id="soapAccordion">
                                <!-- Subjective -->
                                <div class="card mb-2">
                                    <div class="card-header p-0" id="headingSubjective">
                                        <h5 class="mb-0">
                                            <button class="btn btn-link btn-block text-left p-3" type="button" 
                                                    data-toggle="collapse" data-target="#collapseSubjective" 
                                                    aria-expanded="true" aria-controls="collapseSubjective">
                                                <i class="fas fa-user mr-2"></i><strong>S</strong>ubjective
                                                <small class="text-muted ml-3">(Patient's complaints, symptoms)</small>
                                            </button>
                                        </h5>
                                    </div>
                                    <div id="collapseSubjective" class="collapse show" aria-labelledby="headingSubjective" data-parent="#soapAccordion">
                                        <div class="card-body p-3">
                                            <textarea class="form-control" id="subjective" name="subjective" 
                                                      rows="4" placeholder="Patient reports..."><?php echo $todays_note['subjective'] ?? ''; ?></textarea>
                                            <small class="form-text text-muted">
                                                Include chief complaint, history of present illness, pain scale, etc.
                                            </small>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Objective -->
                                <div class="card mb-2">
                                    <div class="card-header p-0" id="headingObjective">
                                        <h5 class="mb-0">
                                            <button class="btn btn-link btn-block text-left p-3 collapsed" type="button" 
                                                    data-toggle="collapse" data-target="#collapseObjective" 
                                                    aria-expanded="false" aria-controls="collapseObjective">
                                                <i class="fas fa-stethoscope mr-2"></i><strong>O</strong>bjective
                                                <small class="text-muted ml-3">(Observations, measurements, findings)</small>
                                            </button>
                                        </h5>
                                    </div>
                                    <div id="collapseObjective" class="collapse" aria-labelledby="headingObjective" data-parent="#soapAccordion">
                                        <div class="card-body p-3">
                                            <textarea class="form-control" id="objective" name="objective" 
                                                      rows="4" placeholder="Observed..."><?php echo $todays_note['objective'] ?? ''; ?></textarea>
                                            <small class="form-text text-muted">
                                                Include vital signs, physical assessment findings, lab results, etc.
                                            </small>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Assessment -->
                                <div class="card mb-2">
                                    <div class="card-header p-0" id="headingAssessment">
                                        <h5 class="mb-0">
                                            <button class="btn btn-link btn-block text-left p-3 collapsed" type="button" 
                                                    data-toggle="collapse" data-target="#collapseAssessment" 
                                                    aria-expanded="false" aria-controls="collapseAssessment">
                                                <i class="fas fa-diagnoses mr-2"></i><strong>A</strong>ssessment
                                                <small class="text-muted ml-3">(Analysis, interpretation)</small>
                                            </button>
                                        </h5>
                                    </div>
                                    <div id="collapseAssessment" class="collapse" aria-labelledby="headingAssessment" data-parent="#soapAccordion">
                                        <div class="card-body p-3">
                                            <textarea class="form-control" id="assessment" name="assessment" 
                                                      rows="4" placeholder="Assessment indicates..."><?php echo $todays_note['assessment'] ?? ''; ?></textarea>
                                            <small class="form-text text-muted">
                                                Include nursing diagnosis, patient's progress, response to treatment.
                                            </small>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Plan -->
                                <div class="card mb-2">
                                    <div class="card-header p-0" id="headingPlan">
                                        <h5 class="mb-0">
                                            <button class="btn btn-link btn-block text-left p-3 collapsed" type="button" 
                                                    data-toggle="collapse" data-target="#collapsePlan" 
                                                    aria-expanded="false" aria-controls="collapsePlan">
                                                <i class="fas fa-tasks mr-2"></i><strong>P</strong>lan
                                                <small class="text-muted ml-3">(Interventions, follow-up)</small>
                                            </button>
                                        </h5>
                                    </div>
                                    <div id="collapsePlan" class="collapse" aria-labelledby="headingPlan" data-parent="#soapAccordion">
                                        <div class="card-body p-3">
                                            <textarea class="form-control" id="plan" name="plan" 
                                                      rows="4" placeholder="Plan includes..."><?php echo $todays_note['plan'] ?? ''; ?></textarea>
                                            <small class="form-text text-muted">
                                                Include nursing interventions, patient education, referrals, follow-up.
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Additional Notes -->
                            <div class="form-group mt-3">
                                <label for="notes">
                                    <i class="fas fa-sticky-note mr-1"></i>Additional Notes
                                </label>
                                <textarea class="form-control" id="notes" name="notes" 
                                          rows="3" placeholder="Other observations, patient concerns, family communication..."><?php echo $todays_note['notes'] ?? ''; ?></textarea>
                            </div>
                            
                            <!-- Quick Templates -->
                            <div class="form-group">
                                <label>Quick Templates:</label>
                                <div class="btn-group btn-group-sm d-flex" role="group">
                                    <button type="button" class="btn btn-outline-primary flex-fill" onclick="applyTemplate('stable')">
                                        <i class="fas fa-check-circle mr-1"></i>Stable
                                    </button>
                                    <button type="button" class="btn btn-outline-warning flex-fill" onclick="applyTemplate('worsening')">
                                        <i class="fas fa-exclamation-triangle mr-1"></i>Worsening
                                    </button>
                                    <button type="button" class="btn btn-outline-success flex-fill" onclick="applyTemplate('improving')">
                                        <i class="fas fa-arrow-up mr-1"></i>Improving
                                    </button>
                                    <button type="button" class="btn btn-outline-danger flex-fill" onclick="applyTemplate('critical')">
                                        <i class="fas fa-skull-crossbones mr-1"></i>Critical
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Action Buttons -->
                            <div class="form-group mb-0">
                                <div class="btn-group btn-block" role="group">
                                    <button type="submit" name="save_note" class="btn btn-warning btn-lg flex-fill">
                                        <i class="fas fa-save mr-2"></i>Save as Draft
                                    </button>
                                    <button type="submit" name="save_note" value="1" class="btn btn-success btn-lg flex-fill" onclick="document.getElementById('nursingNotesForm').querySelector('input[name=finalize]').value = '1';">
                                        <i class="fas fa-lock mr-2"></i>Save & Finalize
                                    </button>
                                </div>
                                <input type="hidden" name="finalize" id="finalize_note" value="0">
                            </div>
                        </form>
                        
                        <!-- Clinical Calculators -->
                        <div class="mt-4 p-3 bg-light rounded">
                            <h6><i class="fas fa-calculator mr-2"></i>Clinical Calculators</h6>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="input-group input-group-sm mb-2">
                                        <div class="input-group-prepend">
                                            <span class="input-group-text">IV Rate</span>
                                        </div>
                                        <input type="number" class="form-control" id="iv_volume" placeholder="Volume (ml)">
                                        <input type="number" class="form-control" id="iv_time" placeholder="Time (hrs)">
                                    </div>
                                    <button type="button" class="btn btn-sm btn-outline-primary btn-block" onclick="calculateIVRate()">
                                        Calc IV Rate
                                    </button>
                                </div>
                                <div class="col-md-6">
                                    <div class="input-group input-group-sm mb-2">
                                        <div class="input-group-prepend">
                                            <span class="input-group-text">Urine Output</span>
                                        </div>
                                        <input type="number" class="form-control" id="urine_volume" placeholder="Volume (ml)">
                                        <input type="number" class="form-control" id="urine_time" placeholder="Time (hrs)">
                                    </div>
                                    <button type="button" class="btn btn-sm btn-outline-success btn-block" onclick="calculateUrineOutput()">
                                        Calc Output
                                    </button>
                                </div>
                            </div>
                            <div id="calc_result" class="mt-2 text-center" style="display: none;">
                                <span class="badge badge-info" id="calc_value"></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Nursing Notes History -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-info py-2">
                        <h4 class="card-title mb-0 text-white">
                            <i class="fas fa-history mr-2"></i>Nursing Notes History
                            <span class="badge badge-light float-right"><?php echo count($nurse_notes); ?> notes</span>
                        </h4>
                    </div>
                    <div class="card-body p-0">
                        <?php if (!empty($nurse_notes)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Date/Shift</th>
                                            <th>Type</th>
                                            <th>Status</th>
                                            <th>Recorded By</th>
                                            <th class="text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $current_date = null;
                                        foreach ($nurse_notes as $note): 
                                            $note_date = new DateTime($note['note_date']);
                                            $is_today = ($note['note_date'] == $today);
                                            $row_class = $is_today ? 'table-info' : '';
                                            
                                            if ($current_date != $note['note_date']) {
                                                $current_date = $note['note_date'];
                                                $date_display = $note_date->format('M j, Y');
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
                                                    <div>
                                                        <?php echo getShiftBadge($note['shift']); ?>
                                                    </div>
                                                    <small class="text-muted">
                                                        <?php echo date('H:i', strtotime($note['recorded_at'])); ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <div class="text-capitalize">
                                                        <?php echo htmlspecialchars($note['note_type']); ?>
                                                    </div>
                                                    <?php if ($note['note_type'] != 'daily'): ?>
                                                        <small class="badge badge-secondary"><?php echo $note['note_type']; ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php echo getStatusBadge($note['status']); ?>
                                                    <?php if ($note['status'] == 'finalized' && $note['finalized_at']): ?>
                                                        <br>
                                                        <small class="text-muted">
                                                            <?php echo date('M j, H:i', strtotime($note['finalized_at'])); ?>
                                                        </small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($note['recorded_by_name']); ?>
                                                    <?php if ($note['finalized_by_name']): ?>
                                                        <br>
                                                        <small class="text-muted">
                                                            Final: <?php echo htmlspecialchars($note['finalized_by_name']); ?>
                                                        </small>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <button type="button" class="btn btn-sm btn-info" 
                                                            onclick="viewNoteDetails(<?php echo htmlspecialchars(json_encode($note)); ?>)"
                                                            title="View Details">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <?php if ($note['status'] == 'draft'): ?>
                                                        <button type="button" class="btn btn-sm btn-warning" 
                                                                onclick="editNote(<?php echo htmlspecialchars(json_encode($note)); ?>)"
                                                                title="Edit Note">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <form method="POST" style="display: inline;">
                                                            <input type="hidden" name="note_id" value="<?php echo $note['note_id']; ?>">
                                                            <button type="submit" name="delete_note" class="btn btn-sm btn-danger" 
                                                                    title="Delete Note" onclick="return confirm('Delete this note?')">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-clipboard fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">No Nursing Notes</h5>
                                <p class="text-muted">No nursing notes have been recorded for this visit yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Statistics -->
                    <?php if (!empty($nurse_notes)): ?>
                    <div class="card-footer">
                        <div class="row text-center">
                            <div class="col-4">
                                <div class="text-muted">Total Notes</div>
                                <div class="h4"><?php echo count($nurse_notes); ?></div>
                            </div>
                            <div class="col-4">
                                <div class="text-muted">Finalized</div>
                                <div class="h4 text-success">
                                    <?php 
                                    $finalized = array_filter($nurse_notes, function($n) { 
                                        return $n['status'] == 'finalized'; 
                                    });
                                    echo count($finalized);
                                    ?>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="text-muted">Drafts</div>
                                <div class="h4 text-warning">
                                    <?php 
                                    $drafts = array_filter($nurse_notes, function($n) { 
                                        return $n['status'] == 'draft'; 
                                    });
                                    echo count($drafts);
                                    ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Quick Reference Cards -->
                <div class="row mt-4">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-warning py-2">
                                <h6 class="card-title mb-0">
                                    <i class="fas fa-lightbulb mr-2"></i>SOAP Guidelines
                                </h6>
                            </div>
                            <div class="card-body p-2">
                                <small>
                                    <strong>S:</strong> Patient's subjective experience<br>
                                    <strong>O:</strong> Objective, measurable data<br>
                                    <strong>A:</strong> Nursing assessment/diagnosis<br>
                                    <strong>P:</strong> Plan of care/interventions
                                </small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-secondary py-2">
                                <h6 class="card-title mb-0">
                                    <i class="fas fa-clock mr-2"></i>Shift Times
                                </h6>
                            </div>
                            <div class="card-body p-2">
                                <small>
                                    <strong>Morning:</strong> 06:00 - 14:00<br>
                                    <strong>Evening:</strong> 14:00 - 22:00<br>
                                    <strong>Night:</strong> 22:00 - 06:00
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Note Details Modal -->
<div class="modal fade" id="noteDetailsModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Nursing Note Details</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body" id="noteDetailsContent">
                <!-- Content will be loaded dynamically -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="window.print()">
                    <i class="fas fa-print mr-2"></i>Print
                </button>
            </div>
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

    // Initialize date picker
    $('#note_date').flatpickr({
        dateFormat: 'Y-m-d',
        maxDate: 'today'
    });

    // Auto-expand textareas based on content
    $('textarea').on('input', function() {
        this.style.height = 'auto';
        this.style.height = (this.scrollHeight) + 'px';
    }).trigger('input');

    // Initialize accordion
    $('#soapAccordion .collapse').on('shown.bs.collapse', function() {
        $(this).prev().find('button').addClass('active');
    }).on('hidden.bs.collapse', function() {
        $(this).prev().find('button').removeClass('active');
    });

    // Form validation
    $('#nursingNotesForm').validate({
        rules: {
            shift: {
                required: true
            },
            note_date: {
                required: true,
                date: true
            }
        },
        messages: {
            shift: {
                required: "Please select a shift"
            },
            note_date: {
                required: "Please select a date",
                date: "Please enter a valid date"
            }
        }
    });
});

function applyTemplate(template) {
    const now = new Date();
    const timeString = now.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
    const dateString = now.toLocaleDateString();
    
    let subjective = '';
    let objective = '';
    let assessment = '';
    let plan = '';
    let notes = '';
    
    switch(template) {
        case 'stable':
            subjective = `Patient reports feeling well. No new complaints.`;
            objective = `Vital signs stable. Patient alert and oriented. No distress noted.`;
            assessment = `Patient condition stable. Tolerating current treatment well.`;
            plan = `Continue current plan of care. Monitor vital signs regularly.`;
            notes = `Patient resting comfortably. Family informed of status.`;
            break;
            
        case 'improving':
            subjective = `Patient reports improvement in symptoms. Pain decreased.`;
            objective = `Vital signs improving. Patient appears more comfortable.`;
            assessment = `Patient responding well to treatment. Condition improving.`;
            plan = `Continue current interventions. Assess response to treatment.`;
            notes = `Encouraged patient. Positive progress noted.`;
            break;
            
        case 'worsening':
            subjective = `Patient reports increased pain/discomfort. New symptoms reported.`;
            objective = `Vital signs concerning. Patient appears distressed.`;
            assessment = `Condition appears to be worsening. Requires close monitoring.`;
            plan = `Notify physician. Increase monitoring frequency. Consider additional interventions.`;
            notes = `Family notified. Emergency equipment checked.`;
            break;
            
        case 'critical':
            subjective = `Patient reports severe distress. Critical symptoms present.`;
            objective = `Critical vital signs. Patient in acute distress.`;
            assessment = `Patient in critical condition. Immediate intervention required.`;
            plan = `Activate emergency response. Prepare for immediate intervention.`;
            notes = `Rapid response team notified. Emergency protocols initiated.`;
            break;
    }
    
    // Add timestamp
    notes = `[${timeString}] ${notes}`;
    
    // Apply to form fields
    document.getElementById('subjective').value = subjective;
    document.getElementById('objective').value = objective;
    document.getElementById('assessment').value = assessment;
    document.getElementById('plan').value = plan;
    document.getElementById('notes').value = notes;
    
    // Trigger auto-expand
    $('textarea').trigger('input');
    
    // Show success message
    showToast(`Applied ${template} template`, 'success');
}

function calculateIVRate() {
    const volume = parseFloat(document.getElementById('iv_volume').value);
    const time = parseFloat(document.getElementById('iv_time').value);
    
    if (!volume || !time || time <= 0) {
        alert('Please enter valid volume and time');
        return;
    }
    
    const rate = (volume / time).toFixed(1);
    const result = `IV Rate: ${rate} ml/hr`;
    
    document.getElementById('calc_value').textContent = result;
    document.getElementById('calc_result').style.display = 'block';
    
    // Auto-add to notes
    const currentNotes = document.getElementById('notes').value;
    const timestamp = new Date().toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
    const newNote = `[${timestamp}] IV infusion: ${volume}ml over ${time}hrs (${rate}ml/hr)`;
    
    document.getElementById('notes').value = currentNotes ? 
        currentNotes + '\n' + newNote : newNote;
    $('textarea').trigger('input');
}

function calculateUrineOutput() {
    const volume = parseFloat(document.getElementById('urine_volume').value);
    const time = parseFloat(document.getElementById('urine_time').value);
    
    if (!volume || !time || time <= 0) {
        alert('Please enter valid volume and time');
        return;
    }
    
    const hourlyOutput = (volume / time).toFixed(1);
    const result = `Urine Output: ${hourlyOutput} ml/hr`;
    
    document.getElementById('calc_value').textContent = result;
    document.getElementById('calc_result').style.display = 'block';
    
    // Auto-add to notes
    const currentNotes = document.getElementById('notes').value;
    const timestamp = new Date().toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
    const newNote = `[${timestamp}] Urine output: ${volume}ml in ${time}hrs (${hourlyOutput}ml/hr)`;
    
    document.getElementById('notes').value = currentNotes ? 
        currentNotes + '\n' + newNote : newNote;
    $('textarea').trigger('input');
}

function viewNoteDetails(note) {
    const modalContent = document.getElementById('noteDetailsContent');
    const noteDate = new Date(note.note_date);
    const recordedAt = new Date(note.recorded_at);
    const finalizedAt = note.finalized_at ? new Date(note.finalized_at) : null;
    
    let html = `
        <div class="card mb-3">
            <div class="card-header bg-light py-2">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0">
                            ${getShiftBadge(note.shift)} 
                            <span class="ml-2">${noteDate.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })}</span>
                        </h6>
                        <small class="text-muted">
                            ${note.note_type ? note.note_type.charAt(0).toUpperCase() + note.note_type.slice(1) + ' Note' : 'Nursing Note'}
                        </small>
                    </div>
                    <div>
                        ${getStatusBadge(note.status)}
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless">
                            <tr>
                                <th width="40%">Recorded By:</th>
                                <td>${note.recorded_by_name}</td>
                            </tr>
                            <tr>
                                <th>Recorded At:</th>
                                <td>${recordedAt.toLocaleDateString()} ${recordedAt.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless">
    `;
    
    if (finalizedAt) {
        html += `<tr>
                    <th width="40%">Finalized By:</th>
                    <td>${note.finalized_by_name || 'N/A'}</td>
                </tr>
                <tr>
                    <th>Finalized At:</th>
                    <td>${finalizedAt.toLocaleDateString()} ${finalizedAt.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</td>
                </tr>`;
    }
    
    html += `       </table>
                    </div>
                </div>
                
                <div class="soap-notes">
    `;
    
    if (note.subjective) {
        html += `<div class="mb-4">
                    <h6 class="text-primary"><i class="fas fa-user mr-2"></i>Subjective (S)</h6>
                    <div class="p-3 bg-light rounded">${note.subjective.replace(/\n/g, '<br>')}</div>
                </div>`;
    }
    
    if (note.objective) {
        html += `<div class="mb-4">
                    <h6 class="text-success"><i class="fas fa-stethoscope mr-2"></i>Objective (O)</h6>
                    <div class="p-3 bg-light rounded">${note.objective.replace(/\n/g, '<br>')}</div>
                </div>`;
    }
    
    if (note.assessment) {
        html += `<div class="mb-4">
                    <h6 class="text-warning"><i class="fas fa-diagnoses mr-2"></i>Assessment (A)</h6>
                    <div class="p-3 bg-light rounded">${note.assessment.replace(/\n/g, '<br>')}</div>
                </div>`;
    }
    
    if (note.plan) {
        html += `<div class="mb-4">
                    <h6 class="text-info"><i class="fas fa-tasks mr-2"></i>Plan (P)</h6>
                    <div class="p-3 bg-light rounded">${note.plan.replace(/\n/g, '<br>')}</div>
                </div>`;
    }
    
    if (note.notes) {
        html += `<div class="mb-4">
                    <h6 class="text-secondary"><i class="fas fa-sticky-note mr-2"></i>Additional Notes</h6>
                    <div class="p-3 bg-light rounded">${note.notes.replace(/\n/g, '<br>')}</div>
                </div>`;
    }
    
    html += `   </div>
            </div>
        </div>`;
    
    modalContent.innerHTML = html;
    $('#noteDetailsModal').modal('show');
}

function editNote(note) {
    // Populate form with note data
    $('#shift').val(note.shift);
    $('#note_date').val(note.note_date);
    $('#note_type').val(note.note_type);
    $('#subjective').val(note.subjective);
    $('#objective').val(note.objective);
    $('#assessment').val(note.assessment);
    $('#plan').val(note.plan);
    $('#notes').val(note.notes);
    
    // Trigger auto-expand
    $('textarea').trigger('input');
    
    // Add hidden field for note_id if needed
    if (!$('#note_id').length) {
        $('<input>').attr({
            type: 'hidden',
            id: 'note_id',
            name: 'note_id',
            value: note.note_id
        }).appendTo('#nursingNotesForm');
    } else {
        $('#note_id').val(note.note_id);
    }
    
    // Scroll to form
    $('html, body').animate({
        scrollTop: $('#nursingNotesForm').offset().top - 20
    }, 500);
    
    // Show message
    showToast('Note loaded for editing', 'info');
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
    // Ctrl + S for save draft
    if (e.ctrlKey && e.keyCode === 83 && !e.shiftKey) {
        e.preventDefault();
        $('#finalize_note').val('0');
        $('#nursingNotesForm').submit();
    }
    // Ctrl + Shift + S for save and finalize
    if (e.ctrlKey && e.shiftKey && e.keyCode === 83) {
        e.preventDefault();
        $('#finalize_note').val('1');
        $('#nursingNotesForm').submit();
    }
    // Ctrl + N for new note (clear form)
    if (e.ctrlKey && e.keyCode === 78) {
        e.preventDefault();
        if (confirm('Clear current form and start new note?')) {
            $('#nursingNotesForm')[0].reset();
            $('#note_date').val('<?php echo $today; ?>');
            $('#shift').val('<?php echo $current_shift; ?>');
            $('textarea').trigger('input');
        }
    }
    // Ctrl + P for print
    if (e.ctrlKey && e.keyCode === 80) {
        e.preventDefault();
        window.print();
    }
    // Escape to go back
    if (e.keyCode === 27) {
        window.history.back();
    }
});

// Auto-save draft every 2 minutes
let autoSaveTimer;
function startAutoSave() {
    autoSaveTimer = setInterval(function() {
        if ($('#nursingNotesForm').valid()) {
            // Only auto-save if there's content
            const hasContent = $('#subjective').val() || $('#objective').val() || 
                              $('#assessment').val() || $('#plan').val() || $('#notes').val();
            
            if (hasContent) {
                console.log('Auto-saving draft...');
                // You could implement AJAX auto-save here
            }
        }
    }, 120000); // 2 minutes
}

$(document).ready(function() {
    startAutoSave();
});
</script>

<style>
/* Custom styles for nursing notes */
.table-info {
    background-color: #d1ecf1 !important;
}
.soap-notes h6 {
    border-bottom: 2px solid;
    padding-bottom: 5px;
    margin-bottom: 10px;
}
.soap-notes .text-primary h6 { border-color: #007bff; }
.soap-notes .text-success h6 { border-color: #28a745; }
.soap-notes .text-warning h6 { border-color: #ffc107; }
.soap-notes .text-info h6 { border-color: #17a2b8; }

.accordion .card-header button {
    text-decoration: none !important;
    color: #495057 !important;
    font-weight: 600 !important;
}
.accordion .card-header button.active {
    background-color: #f8f9fa;
    color: #007bff !important;
}
.accordion .card-header button:hover {
    background-color: #f8f9fa;
}

/* Print styles */
@media print {
    .card-header, .card-tools, .btn, form, .modal,
    .card-footer, .toast-container {
        display: none !important;
    }
    .card {
        border: none !important;
        box-shadow: none !important;
    }
    .card-body {
        padding: 0 !important;
    }
    .soap-notes .p-3 {
        padding: 0.5rem !important;
    }
    .table {
        font-size: 11px !important;
    }
}
</style>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
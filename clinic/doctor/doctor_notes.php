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
        'module'      => 'Doctor Notes',
        'table_name'  => 'visits',
        'entity_type' => 'visit',
        'record_id'   => null,
        'patient_id'  => null,
        'visit_id'    => null,
        'description' => "Attempted to access doctor_notes.php with invalid visit ID: " . $visit_id,
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
$doctor_notes = [];
$visit_type = '';
$today = date('Y-m-d');

// Get visit and patient information - UPDATED WITH CORRECT FIELDS
$visit_sql = "SELECT v.*, 
                     p.patient_id, p.first_name, p.last_name, 
                     p.patient_mrn, p.sex as patient_gender, p.date_of_birth as patient_dob,
                     p.phone_primary as patient_phone,
                     p.county, p.sub_county, p.ward, p.village,
                     d.department_name,
                     doctor.user_name as doctor_name
              FROM visits v 
              JOIN patients p ON v.patient_id = p.patient_id
              JOIN departments d ON v.department_id = d.department_id
              LEFT JOIN users doctor ON v.attending_provider_id = doctor.user_id
              WHERE v.visit_id = ?";
$visit_stmt = $mysqli->prepare($visit_sql);
$visit_stmt->bind_param("i", $visit_id);
$visit_stmt->execute();
$visit_result = $visit_stmt->get_result();

if ($visit_result->num_rows === 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Visit not found";
    
    // AUDIT LOG: Visit not found
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'ACCESS',
        'module'      => 'Doctor Notes',
        'table_name'  => 'visits',
        'entity_type' => 'visit',
        'record_id'   => $visit_id,
        'patient_id'  => null,
        'visit_id'    => $visit_id,
        'description' => "Attempted to access doctor notes for visit ID " . $visit_id . " but visit not found",
        'status'      => 'FAILED',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    header("Location: /clinic/dashboard.php");
    exit;
}

$visit_info = $visit_result->fetch_assoc();
$patient_info = [
    'patient_id' => $visit_info['patient_id'],
    'first_name' => $visit_info['first_name'],
    'last_name' => $visit_info['last_name'],
    'patient_mrn' => $visit_info['patient_mrn'],
    'patient_gender' => $visit_info['patient_gender'],
    'patient_dob' => $visit_info['patient_dob'],
    'patient_phone' => $visit_info['patient_phone'],
    'county' => $visit_info['county'],
    'sub_county' => $visit_info['sub_county'],
    'ward' => $visit_info['ward'],
    'village' => $visit_info['village']
];

$visit_type = $visit_info['visit_type'];
$visit_status = $visit_info['visit_status'];

// Check for IPD admission
$is_ipd = false;
$ipd_info = null;
if ($visit_type == 'IPD') {
    $ipd_sql = "SELECT * FROM ipd_admissions WHERE visit_id = ?";
    $ipd_stmt = $mysqli->prepare($ipd_sql);
    $ipd_stmt->bind_param("i", $visit_id);
    $ipd_stmt->execute();
    $ipd_result = $ipd_stmt->get_result();
    if ($ipd_result->num_rows > 0) {
        $is_ipd = true;
        $ipd_info = $ipd_result->fetch_assoc();
    }
}

// Get existing doctor notes for this visit
// Assuming there's a doctor_notes table (you should check if this table exists)
$notes_sql = "SELECT dn.*, 
                     u.user_name as recorded_by_name
              FROM doctor_notes dn
              JOIN users u ON dn.recorded_by = u.user_id
              WHERE dn.visit_id = ?
              ORDER BY dn.note_date DESC, dn.recorded_at DESC";
$notes_stmt = $mysqli->prepare($notes_sql);
$notes_stmt->bind_param("i", $visit_id);
$notes_stmt->execute();
$notes_result = $notes_stmt->get_result();
$doctor_notes = $notes_result->fetch_all(MYSQLI_ASSOC);

// If doctor_notes table doesn't exist, let's create a placeholder and show a message
if ($mysqli->error) {
    $doctor_notes = [];
    
    // AUDIT LOG: Table not found
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'SYSTEM',
        'module'      => 'Doctor Notes',
        'table_name'  => 'doctor_notes',
        'entity_type' => 'system',
        'record_id'   => null,
        'patient_id'  => $patient_info['patient_id'],
        'visit_id'    => $visit_id,
        'description' => "Doctor notes table not found in database when accessing visit " . $visit_id,
        'status'      => 'WARNING',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    $_SESSION['alert_type'] = "warning";
    $_SESSION['alert_message'] = "Doctor notes table not found. Please create the doctor_notes table.";
}

// Get today's note
$todays_note = null;
foreach ($doctor_notes as $note) {
    if ($note['note_date'] == $today) {
        $todays_note = $note;
        break;
    }
}

// Handle form submission for new/modified notes
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_note'])) {
        $note_date = $_POST['note_date'];
        $note_type = $_POST['note_type'] ?? 'progress';
        $subjective = !empty($_POST['subjective']) ? trim($_POST['subjective']) : null;
        $objective = !empty($_POST['objective']) ? trim($_POST['objective']) : null;
        $assessment = !empty($_POST['assessment']) ? trim($_POST['assessment']) : null;
        $plan = !empty($_POST['plan']) ? trim($_POST['plan']) : null;
        $clinical_notes = !empty($_POST['clinical_notes']) ? trim($_POST['clinical_notes']) : null;
        $recommendations = !empty($_POST['recommendations']) ? trim($_POST['recommendations']) : null;
        
        $recorded_by = $_SESSION['user_id'];
        $status = isset($_POST['finalize']) ? 'finalized' : 'draft';
        
        // AUDIT LOG: Attempt to save doctor note
        audit_log($mysqli, [
            'user_id'     => $recorded_by,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'DOCTOR_NOTE_SAVE',
            'module'      => 'Doctor Notes',
            'table_name'  => 'doctor_notes',
            'entity_type' => 'doctor_note',
            'record_id'   => null,
            'patient_id'  => $patient_info['patient_id'],
            'visit_id'    => $visit_id,
            'description' => "Attempting to save doctor note for visit " . $visit_id . ". Type: " . $note_type . ", Status: " . $status,
            'status'      => 'ATTEMPT',
            'old_values'  => null,
            'new_values'  => [
                'note_date' => $note_date,
                'note_type' => $note_type,
                'status' => $status
            ]
        ]);
        
        // Check if note already exists for today
        $check_sql = "SELECT note_id FROM doctor_notes 
                     WHERE visit_id = ? AND patient_id = ? 
                     AND note_date = ? AND note_type = ?";
        $check_stmt = $mysqli->prepare($check_sql);
        $check_stmt->bind_param("iiss", $visit_id, $patient_info['patient_id'], $note_date, $note_type);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $existing_note = $check_result->fetch_assoc();
        
        if ($existing_note) {
            // Get current note for audit log
            $old_note_sql = "SELECT * FROM doctor_notes WHERE note_id = ?";
            $old_note_stmt = $mysqli->prepare($old_note_sql);
            $old_note_stmt->bind_param("i", $existing_note['note_id']);
            $old_note_stmt->execute();
            $old_note_result = $old_note_stmt->get_result();
            $old_note = $old_note_result->fetch_assoc();
            
            // Update existing note
            $update_sql = "UPDATE doctor_notes 
                          SET subjective = ?, objective = ?, assessment = ?, 
                              plan = ?, clinical_notes = ?, recommendations = ?,
                              status = ?, updated_at = NOW()
                          WHERE note_id = ?";
            
            $update_stmt = $mysqli->prepare($update_sql);
            
            $update_stmt->bind_param("sssssssi", 
                $subjective, $objective, $assessment, $plan,
                $clinical_notes, $recommendations, $status,
                $existing_note['note_id']
            );
            
            if ($update_stmt->execute()) {
                $message = $status == 'finalized' ? "Note finalized successfully" : "Note updated successfully";
                
                // AUDIT LOG: Successful note update
                audit_log($mysqli, [
                    'user_id'     => $recorded_by,
                    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                    'action'      => 'DOCTOR_NOTE_UPDATE',
                    'module'      => 'Doctor Notes',
                    'table_name'  => 'doctor_notes',
                    'entity_type' => 'doctor_note',
                    'record_id'   => $existing_note['note_id'],
                    'patient_id'  => $patient_info['patient_id'],
                    'visit_id'    => $visit_id,
                    'description' => "Updated doctor note ID " . $existing_note['note_id'] . " for visit " . $visit_id,
                    'status'      => 'SUCCESS',
                    'old_values'  => [
                        'note_type' => $old_note['note_type'] ?? null,
                        'status' => $old_note['status'] ?? null,
                        'subjective' => $old_note['subjective'] ?? null,
                        'assessment' => $old_note['assessment'] ?? null
                    ],
                    'new_values'  => [
                        'note_type' => $note_type,
                        'status' => $status,
                        'subjective' => substr($subjective ?? '', 0, 100) . '...',
                        'assessment' => substr($assessment ?? '', 0, 100) . '...'
                    ]
                ]);
                
                $_SESSION['alert_type'] = "success";
                $_SESSION['alert_message'] = $message;
                header("Location: doctor_notes.php?visit_id=" . $visit_id);
                exit;
            } else {
                // AUDIT LOG: Failed note update
                audit_log($mysqli, [
                    'user_id'     => $recorded_by,
                    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                    'action'      => 'DOCTOR_NOTE_UPDATE',
                    'module'      => 'Doctor Notes',
                    'table_name'  => 'doctor_notes',
                    'entity_type' => 'doctor_note',
                    'record_id'   => $existing_note['note_id'],
                    'patient_id'  => $patient_info['patient_id'],
                    'visit_id'    => $visit_id,
                    'description' => "Failed to update doctor note ID " . $existing_note['note_id'] . ". Error: " . $mysqli->error,
                    'status'      => 'FAILED',
                    'old_values'  => null,
                    'new_values'  => null
                ]);
                
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = "Error updating note: " . $mysqli->error;
            }
        } else {
            // Insert new note
            $insert_sql = "INSERT INTO doctor_notes 
                          (visit_id, patient_id, note_date, note_type,
                           subjective, objective, assessment, plan,
                           clinical_notes, recommendations, recorded_by, status)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $insert_stmt = $mysqli->prepare($insert_sql);
            
            $insert_stmt->bind_param("iissssssssss",
                $visit_id, $patient_info['patient_id'], $note_date, $note_type,
                $subjective, $objective, $assessment, $plan,
                $clinical_notes, $recommendations, $recorded_by, $status
            );
            
            if ($insert_stmt->execute()) {
                $new_note_id = $insert_stmt->insert_id;
                $message = $status == 'finalized' ? "Note created and finalized successfully" : "Note saved as draft";
                
                // AUDIT LOG: Successful note creation
                audit_log($mysqli, [
                    'user_id'     => $recorded_by,
                    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                    'action'      => 'DOCTOR_NOTE_CREATE',
                    'module'      => 'Doctor Notes',
                    'table_name'  => 'doctor_notes',
                    'entity_type' => 'doctor_note',
                    'record_id'   => $new_note_id,
                    'patient_id'  => $patient_info['patient_id'],
                    'visit_id'    => $visit_id,
                    'description' => "Created new doctor note for visit " . $visit_id . ". Note ID: " . $new_note_id,
                    'status'      => 'SUCCESS',
                    'old_values'  => null,
                    'new_values'  => [
                        'note_date' => $note_date,
                        'note_type' => $note_type,
                        'status' => $status,
                        'subjective' => substr($subjective ?? '', 0, 100) . '...',
                        'assessment' => substr($assessment ?? '', 0, 100) . '...',
                        'recorded_by' => $recorded_by
                    ]
                ]);
                
                $_SESSION['alert_type'] = "success";
                $_SESSION['alert_message'] = $message;
                header("Location: doctor_notes.php?visit_id=" . $visit_id);
                exit;
            } else {
                // AUDIT LOG: Failed note creation
                audit_log($mysqli, [
                    'user_id'     => $recorded_by,
                    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                    'action'      => 'DOCTOR_NOTE_CREATE',
                    'module'      => 'Doctor Notes',
                    'table_name'  => 'doctor_notes',
                    'entity_type' => 'doctor_note',
                    'record_id'   => null,
                    'patient_id'  => $patient_info['patient_id'],
                    'visit_id'    => $visit_id,
                    'description' => "Failed to create doctor note for visit " . $visit_id . ". Error: " . $mysqli->error,
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
        
        // Get note details before deletion for audit log
        $note_details_sql = "SELECT * FROM doctor_notes WHERE note_id = ?";
        $note_details_stmt = $mysqli->prepare($note_details_sql);
        $note_details_stmt->bind_param("i", $note_id);
        $note_details_stmt->execute();
        $note_details_result = $note_details_stmt->get_result();
        $note_to_delete = $note_details_result->fetch_assoc();
        
        $delete_sql = "DELETE FROM doctor_notes WHERE note_id = ?";
        $delete_stmt = $mysqli->prepare($delete_sql);
        $delete_stmt->bind_param("i", $note_id);
        
        if ($delete_stmt->execute()) {
            // AUDIT LOG: Note deleted
            audit_log($mysqli, [
                'user_id'     => $_SESSION['user_id'] ?? null,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'DOCTOR_NOTE_DELETE',
                'module'      => 'Doctor Notes',
                'table_name'  => 'doctor_notes',
                'entity_type' => 'doctor_note',
                'record_id'   => $note_id,
                'patient_id'  => $patient_info['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Deleted doctor note ID " . $note_id . " for visit " . $visit_id,
                'status'      => 'SUCCESS',
                'old_values'  => [
                    'note_date' => $note_to_delete['note_date'] ?? null,
                    'note_type' => $note_to_delete['note_type'] ?? null,
                    'status' => $note_to_delete['status'] ?? null
                ],
                'new_values'  => null
            ]);
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Note deleted successfully";
            header("Location: doctor_notes.php?visit_id=" . $visit_id);
            exit;
        } else {
            // AUDIT LOG: Failed note deletion
            audit_log($mysqli, [
                'user_id'     => $_SESSION['user_id'] ?? null,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'DOCTOR_NOTE_DELETE',
                'module'      => 'Doctor Notes',
                'table_name'  => 'doctor_notes',
                'entity_type' => 'doctor_note',
                'record_id'   => $note_id,
                'patient_id'  => $patient_info['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Failed to delete doctor note ID " . $note_id . ". Error: " . $mysqli->error,
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
$full_name = $patient_info['first_name'] . ' ' . $patient_info['last_name'];

// Calculate age
$age = '';
if (!empty($patient_info['patient_dob'])) {
    $birthDate = new DateTime($patient_info['patient_dob']);
    $today_date = new DateTime();
    $age = $today_date->diff($birthDate)->y . ' years';
}

// Get visit number
$visit_number = $visit_info['visit_number'];

// AUDIT LOG: Successful access to doctor notes page
audit_log($mysqli, [
    'user_id'     => $_SESSION['user_id'] ?? null,
    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
    'action'      => 'VIEW',
    'module'      => 'Doctor Notes',
    'table_name'  => 'visits',
    'entity_type' => 'visit',
    'record_id'   => $visit_id,
    'patient_id'  => $patient_info['patient_id'],
    'visit_id'    => $visit_id,
    'description' => "Accessed doctor notes page for visit ID " . $visit_id . " (Patient: " . $full_name . ", Visit #: " . $visit_number . ", Type: " . $visit_type . ")",
    'status'      => 'SUCCESS',
    'old_values'  => null,
    'new_values'  => null
]);

// Function to get note type badge
function getNoteTypeBadge($type) {
    switch($type) {
        case 'progress':
            return '<span class="badge badge-info"><i class="fas fa-clipboard-list mr-1"></i>Progress</span>';
        case 'consultation':
            return '<span class="badge badge-warning"><i class="fas fa-stethoscope mr-1"></i>Consultation</span>';
        case 'procedure':
            return '<span class="badge badge-danger"><i class="fas fa-procedures mr-1"></i>Procedure</span>';
        case 'follow_up':
            return '<span class="badge badge-secondary"><i class="fas fa-calendar-check mr-1"></i>Follow-up</span>';
        case 'discharge':
            return '<span class="badge badge-success"><i class="fas fa-sign-out-alt mr-1"></i>Discharge</span>';
        default:
            return '<span class="badge badge-secondary">' . $type . '</span>';
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
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h3 class="card-title mt-2 mb-0 text-white">
                    <i class="fas fa-fw fa-file-medical-alt mr-2"></i>
                    Doctor's Clinical Notes
                </h3>
                <small class="text-white">
                    Visit #<?php echo htmlspecialchars($visit_number); ?> | 
                    Patient: <?php echo htmlspecialchars($full_name); ?>
                    <?php if ($is_ipd && $ipd_info): ?>
                        | IPD Admission: <?php echo htmlspecialchars($ipd_info['admission_number']); ?>
                    <?php endif; ?>
                </small>
            </div>
            <div class="card-tools">
                <div class="btn-group">
                    <a href="/clinic/doctor/doctor_dashboard.php" class="btn btn-light">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
                    </a>
                    <a href="/clinic/doctor/patient_chart.php?patient_id=<?php echo $patient_info['patient_id']; ?>&visit_id=<?php echo $visit_id; ?>" 
                       class="btn btn-info ml-2">
                        <i class="fas fa-file-medical mr-2"></i>Patient Chart
                    </a>
                    <?php if (SimplePermission::any("doctor_orders")): ?>
                        <a href="/clinic/doctor/doctor_orders.php?visit_id=<?php echo $visit_id; ?>" 
                           class="btn btn-warning ml-2">
                            <i class="fas fa-prescription mr-2"></i>Orders
                        </a>
                    <?php endif; ?>
                </div>
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
                                    <div class="col-md-4">
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
                                                <th class="text-muted">Age/Sex:</th>
                                                <td>
                                                    <span class="badge badge-secondary">
                                                        <?php echo $age ?: 'N/A'; ?> / <?php echo htmlspecialchars($patient_info['patient_gender']); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        </table>
                                    </div>
                                    <div class="col-md-4">
                                        <table class="table table-sm table-borderless mb-0">
                                            <tr>
                                                <th width="40%" class="text-muted">Visit Date:</th>
                                                <td><?php echo date('M j, Y', strtotime($visit_info['visit_datetime'])); ?></td>
                                            </tr>
                                            <tr>
                                                <th class="text-muted">Visit Type:</th>
                                                <td>
                                                    <span class="badge badge-<?php 
                                                        echo $visit_type == 'OPD' ? 'primary' : 
                                                             ($visit_type == 'IPD' ? 'success' : 
                                                             ($visit_type == 'EMERGENCY' ? 'danger' : 'secondary')); 
                                                    ?>">
                                                        <?php echo htmlspecialchars($visit_type); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th class="text-muted">Status:</th>
                                                <td>
                                                    <span class="badge badge-<?php 
                                                        echo $visit_status == 'ACTIVE' ? 'warning' : 
                                                             ($visit_status == 'CLOSED' ? 'success' : 
                                                             ($visit_status == 'CANCELLED' ? 'danger' : 'secondary')); 
                                                    ?>">
                                                        <?php echo htmlspecialchars($visit_status); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        </table>
                                    </div>
                                    <div class="col-md-4">
                                        <table class="table table-sm table-borderless mb-0">
                                            <tr>
                                                <th width="40%" class="text-muted">Department:</th>
                                                <td><?php echo htmlspecialchars($visit_info['department_name']); ?></td>
                                            </tr>
                                            <tr>
                                                <th class="text-muted">Attending:</th>
                                                <td><?php echo htmlspecialchars($visit_info['doctor_name'] ?: 'Not assigned'); ?></td>
                                            </tr>
                                            <tr>
                                                <th class="text-muted">Contact:</th>
                                                <td><?php echo htmlspecialchars($patient_info['patient_phone']); ?></td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 text-right">
                                <div class="mt-2">
                                    <span class="h5">
                                        <i class="fas fa-file-medical text-primary mr-1"></i>
                                        <span class="badge badge-light"><?php echo count($doctor_notes); ?> Notes</span>
                                    </span>
                                    <br>
                                    <span class="h6">
                                        <i class="fas fa-user-md text-success mr-1"></i>
                                        <span class="badge badge-light">Dr. <?php echo $session_name; ?></span>
                                    </span>
                                    <br>
                                    <span class="h6">
                                        <i class="fas fa-calendar-day text-info mr-1"></i>
                                        <span class="badge badge-light"><?php echo date('F j, Y'); ?></span>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <?php if ($is_ipd && $ipd_info): ?>
                        <div class="row mt-2">
                            <div class="col-md-12">
                                <div class="alert alert-success p-2 mb-0">
                                    <i class="fas fa-procedures mr-2"></i>
                                    <strong>IPD Admission:</strong> 
                                    <?php echo htmlspecialchars($ipd_info['admission_number']); ?> | 
                                    <strong>Admitted:</strong> 
                                    <?php echo date('M j, Y H:i', strtotime($ipd_info['admission_datetime'])); ?> | 
                                    <strong>Status:</strong> 
                                    <span class="badge badge-<?php echo $ipd_info['admission_status'] == 'ACTIVE' ? 'warning' : 'success'; ?>">
                                        <?php echo htmlspecialchars($ipd_info['admission_status']); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Doctor's Notes Form (SOAP Format) -->
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-success py-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <h4 class="card-title mb-0 text-white">
                                <i class="fas fa-edit mr-2"></i>
                                <?php echo $todays_note ? 'Update' : 'Create'; ?> SOAP Note
                                <span class="badge badge-light ml-2">
                                    <?php echo $todays_note ? getStatusBadge($todays_note['status']) : 'New Note'; ?>
                                </span>
                            </h4>
                            <button type="button" class="btn btn-light btn-sm" data-toggle="modal" data-target="#templatesModal">
                                <i class="fas fa-clipboard-list mr-1"></i>Templates
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="doctorNotesForm">
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="note_date">Date</label>
                                        <input type="date" class="form-control" id="note_date" name="note_date" 
                                               value="<?php echo $todays_note['note_date'] ?? $today; ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="note_type">Note Type</label>
                                        <select class="form-control" id="note_type" name="note_type" required>
                                            <option value="progress" <?php echo ($todays_note['note_type'] ?? 'progress') == 'progress' ? 'selected' : ''; ?>>Progress Note</option>
                                            <option value="consultation" <?php echo ($todays_note['note_type'] ?? '') == 'consultation' ? 'selected' : ''; ?>>Consultation Note</option>
                                            <option value="procedure" <?php echo ($todays_note['note_type'] ?? '') == 'procedure' ? 'selected' : ''; ?>>Procedure Note</option>
                                            <option value="follow_up" <?php echo ($todays_note['note_type'] ?? '') == 'follow_up' ? 'selected' : ''; ?>>Follow-up Note</option>
                                            <option value="discharge" <?php echo ($todays_note['note_type'] ?? '') == 'discharge' ? 'selected' : ''; ?>>Discharge Note</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <!-- SOAP Format Sections -->
                            <div class="soap-container">
                                <!-- Subjective Section -->
                                <div class="card mb-3 border-left-primary">
                                    <div class="card-header bg-light py-2">
                                        <h5 class="card-title mb-0 text-primary">
                                            <i class="fas fa-user mr-2"></i>Subjective (S)
                                            <small class="text-muted ml-3">Patient's complaints, symptoms, history</small>
                                        </h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="form-group">
                                            <textarea class="form-control" id="subjective" name="subjective" 
                                                      rows="4" placeholder="Patient reports..."><?php echo $todays_note['subjective'] ?? ''; ?></textarea>
                                            <small class="form-text text-muted">
                                                Include chief complaint, history of present illness, review of systems, etc.
                                            </small>
                                        </div>
                                    </div>
                                </div>

                                <!-- Objective Section -->
                                <div class="card mb-3 border-left-success">
                                    <div class="card-header bg-light py-2">
                                        <h5 class="card-title mb-0 text-success">
                                            <i class="fas fa-stethoscope mr-2"></i>Objective (O)
                                            <small class="text-muted ml-3">Observations, measurements, findings</small>
                                        </h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="form-group">
                                            <textarea class="form-control" id="objective" name="objective" 
                                                      rows="4" placeholder="Observed..."><?php echo $todays_note['objective'] ?? ''; ?></textarea>
                                            <small class="form-text text-muted">
                                                Include vital signs, physical examination findings, lab results, etc.
                                            </small>
                                        </div>
                                    </div>
                                </div>

                                <!-- Assessment Section -->
                                <div class="card mb-3 border-left-warning">
                                    <div class="card-header bg-light py-2">
                                        <h5 class="card-title mb-0 text-warning">
                                            <i class="fas fa-diagnoses mr-2"></i>Assessment (A)
                                            <small class="text-muted ml-3">Analysis, diagnosis, interpretation</small>
                                        </h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="form-group">
                                            <textarea class="form-control" id="assessment" name="assessment" 
                                                      rows="4" placeholder="Assessment indicates..."><?php echo $todays_note['assessment'] ?? ''; ?></textarea>
                                            <small class="form-text text-muted">
                                                Include diagnosis, differential diagnosis, assessment of progress.
                                            </small>
                                        </div>
                                    </div>
                                </div>

                                <!-- Plan Section -->
                                <div class="card mb-3 border-left-info">
                                    <div class="card-header bg-light py-2">
                                        <h5 class="card-title mb-0 text-info">
                                            <i class="fas fa-tasks mr-2"></i>Plan (P)
                                            <small class="text-muted ml-3">Interventions, treatment, follow-up</small>
                                        </h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="form-group">
                                            <textarea class="form-control" id="plan" name="plan" 
                                                      rows="4" placeholder="Plan includes..."><?php echo $todays_note['plan'] ?? ''; ?></textarea>
                                            <small class="form-text text-muted">
                                                Include treatment plan, medications, procedures, follow-up instructions.
                                            </small>
                                        </div>
                                    </div>
                                </div>

                                <!-- Additional Clinical Notes -->
                                <div class="card mb-3 border-left-secondary">
                                    <div class="card-header bg-light py-2">
                                        <h5 class="card-title mb-0 text-secondary">
                                            <i class="fas fa-notes-medical mr-2"></i>Additional Clinical Notes
                                        </h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="form-group">
                                            <textarea class="form-control" id="clinical_notes" name="clinical_notes" 
                                                      rows="3" placeholder="Additional notes..."><?php echo $todays_note['clinical_notes'] ?? ''; ?></textarea>
                                            <small class="form-text text-muted">
                                                Any additional clinical observations or notes.
                                            </small>
                                        </div>
                                    </div>
                                </div>

                                <!-- Recommendations -->
                                <div class="card mb-3 border-left-dark">
                                    <div class="card-header bg-light py-2">
                                        <h5 class="card-title mb-0 text-dark">
                                            <i class="fas fa-lightbulb mr-2"></i>Recommendations
                                        </h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="form-group">
                                            <textarea class="form-control" id="recommendations" name="recommendations" 
                                                      rows="3" placeholder="Recommendations..."><?php echo $todays_note['recommendations'] ?? ''; ?></textarea>
                                            <small class="form-text text-muted">
                                                Specific recommendations for patient care.
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Quick Templates -->
                            <div class="card mb-3">
                                <div class="card-header bg-light py-2">
                                    <h6 class="card-title mb-0">
                                        <i class="fas fa-bolt mr-2"></i>Quick Templates
                                    </h6>
                                </div>
                                <div class="card-body p-2">
                                    <div class="btn-group btn-group-sm flex-wrap" role="group">
                                        <button type="button" class="btn btn-outline-primary m-1" onclick="applyTemplate('stable')">
                                            <i class="fas fa-check-circle mr-1"></i>Stable
                                        </button>
                                        <button type="button" class="btn btn-outline-success m-1" onclick="applyTemplate('improving')">
                                            <i class="fas fa-arrow-up mr-1"></i>Improving
                                        </button>
                                        <button type="button" class="btn btn-outline-warning m-1" onclick="applyTemplate('worsening')">
                                            <i class="fas fa-exclamation-triangle mr-1"></i>Worsening
                                        </button>
                                        <button type="button" class="btn btn-outline-danger m-1" onclick="applyTemplate('critical')">
                                            <i class="fas fa-skull-crossbones mr-1"></i>Critical
                                        </button>
                                        <button type="button" class="btn btn-outline-info m-1" onclick="applyTemplate('discharge')">
                                            <i class="fas fa-sign-out-alt mr-1"></i>Discharge
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Action Buttons -->
                            <div class="form-group mb-0">
                                <div class="btn-group btn-block" role="group">
                                    <button type="submit" name="save_note" class="btn btn-warning btn-lg flex-fill">
                                        <i class="fas fa-save mr-2"></i>Save as Draft
                                    </button>
                                    <button type="submit" name="save_note" class="btn btn-success btn-lg flex-fill" onclick="setFinalizeFlag()">
                                        <i class="fas fa-lock mr-2"></i>Save & Finalize
                                    </button>
                                </div>
                                <input type="hidden" name="finalize" id="finalize_note" value="0">
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Notes History -->
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header bg-info py-2">
                        <h4 class="card-title mb-0 text-white">
                            <i class="fas fa-history mr-2"></i>Notes History
                            <span class="badge badge-light float-right"><?php echo count($doctor_notes); ?> notes</span>
                        </h4>
                    </div>
                    <div class="card-body p-0" style="max-height: 600px; overflow-y: auto;">
                        <?php if (!empty($doctor_notes)): ?>
                            <div class="list-group list-group-flush">
                                <?php 
                                $current_date = null;
                                foreach ($doctor_notes as $note): 
                                    $note_date = new DateTime($note['note_date']);
                                    $recorded_at = new DateTime($note['recorded_at']);
                                    $is_today = ($note['note_date'] == $today);
                                    
                                    if ($current_date != $note['note_date']) {
                                        $current_date = $note['note_date'];
                                        $date_display = $note_date->format('M j, Y');
                                        if ($is_today) {
                                            $date_display = '<strong>Today</strong>';
                                        }
                                ?>
                                    <div class="list-group-item bg-light">
                                        <small class="font-weight-bold text-primary">
                                            <i class="fas fa-calendar-day mr-2"></i><?php echo $date_display; ?>
                                        </small>
                                    </div>
                                <?php } ?>
                                    <div class="list-group-item <?php echo $is_today ? 'list-group-item-info' : ''; ?>">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <?php echo getNoteTypeBadge($note['note_type']); ?>
                                                <?php echo getStatusBadge($note['status']); ?>
                                                <div class="small text-muted mt-1">
                                                    <i class="fas fa-user-md mr-1"></i><?php echo htmlspecialchars($note['recorded_by_name']); ?>
                                                    <br>
                                                    <i class="fas fa-clock mr-1"></i><?php echo $recorded_at->format('H:i'); ?>
                                                </div>
                                            </div>
                                            <div class="btn-group btn-group-sm">
                                                <button type="button" class="btn btn-info btn-sm" 
                                                        onclick="viewNoteDetails(<?php echo htmlspecialchars(json_encode($note)); ?>)"
                                                        title="View Note">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <?php if ($note['status'] == 'draft'): ?>
                                                    <button type="button" class="btn btn-warning btn-sm" 
                                                            onclick="editNote(<?php echo htmlspecialchars(json_encode($note)); ?>)"
                                                            title="Edit Note">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="note_id" value="<?php echo $note['note_id']; ?>">
                                                        <button type="submit" name="delete_note" class="btn btn-danger btn-sm" 
                                                                title="Delete Note" onclick="return confirm('Delete this note?')">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-file-medical fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">No Clinical Notes</h5>
                                <p class="text-muted">No doctor notes have been recorded for this visit yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Statistics -->
                    <?php if (!empty($doctor_notes)): ?>
                    <div class="card-footer">
                        <div class="row text-center">
                            <div class="col-4">
                                <div class="text-muted">Total</div>
                                <div class="h4"><?php echo count($doctor_notes); ?></div>
                            </div>
                            <div class="col-4">
                                <div class="text-muted">Finalized</div>
                                <div class="h4 text-success">
                                    <?php 
                                    $finalized = array_filter($doctor_notes, function($n) { 
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
                                    $drafts = array_filter($doctor_notes, function($n) { 
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

                <!-- SOAP Guidelines -->
                <div class="card mt-3">
                    <div class="card-header bg-light py-2">
                        <h6 class="card-title mb-0">
                            <i class="fas fa-lightbulb mr-2"></i>SOAP Guidelines
                        </h6>
                    </div>
                    <div class="card-body p-2">
                        <div class="alert alert-info mb-2 p-2">
                            <strong>S:</strong> Subjective<br>
                            <small>Patient's complaints, symptoms, history</small>
                        </div>
                        <div class="alert alert-success mb-2 p-2">
                            <strong>O:</strong> Objective<br>
                            <small>Measurable data, observations, findings</small>
                        </div>
                        <div class="alert alert-warning mb-2 p-2">
                            <strong>A:</strong> Assessment<br>
                            <small>Diagnosis, analysis, interpretation</small>
                        </div>
                        <div class="alert alert-primary mb-0 p-2">
                            <strong>P:</strong> Plan<br>
                            <small>Treatment, interventions, follow-up</small>
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
                <h5 class="modal-title">Clinical Note Details</h5>
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

<!-- Templates Modal -->
<div class="modal fade" id="templatesModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title">Clinical Note Templates</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="card mb-3">
                            <div class="card-header bg-success text-white py-2">
                                <h6 class="card-title mb-0">Progress Note Templates</h6>
                            </div>
                            <div class="card-body">
                                <button type="button" class="btn btn-outline-success btn-block mb-2" onclick="applyProgressTemplate('stable')">
                                    Stable Progress
                                </button>
                                <button type="button" class="btn btn-outline-warning btn-block mb-2" onclick="applyProgressTemplate('improving')">
                                    Improving
                                </button>
                                <button type="button" class="btn btn-outline-danger btn-block mb-2" onclick="applyProgressTemplate('worsening')">
                                    Worsening
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card mb-3">
                            <div class="card-header bg-primary text-white py-2">
                                <h6 class="card-title mb-0">Specialty Templates</h6>
                            </div>
                            <div class="card-body">
                                <button type="button" class="btn btn-outline-primary btn-block mb-2" onclick="applyTemplate('discharge')">
                                    Discharge Summary
                                </button>
                                <button type="button" class="btn btn-outline-info btn-block mb-2" onclick="applyTemplate('post_op')">
                                    Post-Op Note
                                </button>
                                <button type="button" class="btn btn-outline-secondary btn-block mb-2" onclick="applyTemplate('consultation')">
                                    Consultation Note
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
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

    // Form validation
    $('#doctorNotesForm').validate({
        rules: {
            note_date: {
                required: true,
                date: true
            },
            note_type: {
                required: true
            }
        },
        messages: {
            note_date: {
                required: "Please select a date",
                date: "Please enter a valid date"
            },
            note_type: {
                required: "Please select a note type"
            }
        }
    });
});

function setFinalizeFlag() {
    document.getElementById('finalize_note').value = '1';
}

function applyTemplate(template) {
    const now = new Date();
    const timeString = now.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
    const dateString = now.toLocaleDateString();
    
    let subjective = '';
    let objective = '';
    let assessment = '';
    let plan = '';
    let clinical_notes = '';
    let recommendations = '';
    
    switch(template) {
        case 'stable':
            subjective = `Patient reports feeling well. No new complaints.`;
            objective = `Vital signs stable. Physical exam within normal limits.`;
            assessment = `Patient condition stable. Treatment plan effective.`;
            plan = `Continue current treatment. Follow-up as scheduled.`;
            clinical_notes = `Patient comfortable and cooperative.`;
            recommendations = `Maintain current medication regimen.`;
            break;
            
        case 'improving':
            subjective = `Patient reports improvement in symptoms.`;
            objective = `Clinical findings show improvement.`;
            assessment = `Patient responding well to treatment.`;
            plan = `Continue current therapy. Monitor progress.`;
            clinical_notes = `Positive response to treatment noted.`;
            recommendations = `Continue current course.`;
            break;
            
        case 'worsening':
            subjective = `Patient reports worsening symptoms.`;
            objective = `Clinical findings indicate deterioration.`;
            assessment = `Condition appears to be worsening.`;
            plan = `Adjust treatment plan. Increase monitoring.`;
            clinical_notes = `Requires close observation.`;
            recommendations = `Consider additional interventions.`;
            break;
            
        case 'critical':
            subjective = `Critical symptoms reported.`;
            objective = `Critical clinical findings.`;
            assessment = `Patient in critical condition.`;
            plan = `Immediate intervention required.`;
            clinical_notes = `Emergency protocols initiated.`;
            recommendations = `Activate emergency response.`;
            break;
            
        case 'discharge':
            subjective = `Patient ready for discharge.`;
            objective = `Stable for discharge.`;
            assessment = `Meets discharge criteria.`;
            plan = `Discharge with follow-up.`;
            clinical_notes = `Discharge instructions provided.`;
            recommendations = `Follow-up appointment scheduled.`;
            break;
    }
    
    // Apply to form fields
    document.getElementById('subjective').value = subjective;
    document.getElementById('objective').value = objective;
    document.getElementById('assessment').value = assessment;
    document.getElementById('plan').value = plan;
    document.getElementById('clinical_notes').value = clinical_notes;
    document.getElementById('recommendations').value = recommendations;
    
    // Set note type if applicable
    if (template === 'discharge') {
        document.getElementById('note_type').value = 'discharge';
    }
    
    // Trigger auto-expand
    $('textarea').trigger('input');
    
    // Show success message
    showToast(`Applied ${template} template`, 'success');
}

function applyProgressTemplate(type) {
    let subjective, objective, assessment, plan;
    
    switch(type) {
        case 'stable':
            subjective = "Patient reports feeling well, no new complaints. Sleeping well, appetite good.";
            objective = "Vital signs stable: BP 120/80, HR 72, RR 16, Temp 36.8°C. Physical exam unchanged.";
            assessment = "Condition remains stable, responding well to current treatment.";
            plan = "Continue current medications. Follow-up in clinic in 1 week.";
            break;
        case 'improving':
            subjective = "Symptoms improving, pain decreased, mobility better.";
            objective = "Clinical improvement noted. Lab results improving.";
            assessment = "Patient making good progress with current treatment.";
            plan = "Continue current therapy. Consider reducing dosage.";
            break;
        case 'worsening':
            subjective = "Symptoms worsening, increased pain, difficulty breathing.";
            objective = "Clinical deterioration noted. Vital signs concerning.";
            assessment = "Condition deteriorating, requires intervention.";
            plan = "Increase monitoring frequency. Consider treatment adjustment.";
            break;
    }
    
    document.getElementById('subjective').value = subjective;
    document.getElementById('objective').value = objective;
    document.getElementById('assessment').value = assessment;
    document.getElementById('plan').value = plan;
    $('textarea').trigger('input');
    $('#templatesModal').modal('hide');
    showToast(`${type} progress template applied`, 'info');
}

function clearForm() {
    if (confirm('Clear all form fields?')) {
        $('#doctorNotesForm')[0].reset();
        $('#note_date').val('<?php echo $today; ?>');
        $('#note_type').val('progress');
        $('textarea').trigger('input');
        showToast('Form cleared', 'info');
    }
}

function importPreviousNote() {
    if (confirm('Import the most recent note as a template?')) {
        <?php if (!empty($doctor_notes)): ?>
            const previousNote = <?php echo json_encode($doctor_notes[0]); ?>;
            document.getElementById('subjective').value = previousNote.subjective || '';
            document.getElementById('objective').value = previousNote.objective || '';
            document.getElementById('assessment').value = previousNote.assessment || '';
            document.getElementById('plan').value = previousNote.plan || '';
            document.getElementById('clinical_notes').value = previousNote.clinical_notes || '';
            document.getElementById('recommendations').value = previousNote.recommendations || '';
            $('textarea').trigger('input');
            showToast('Previous note imported as template', 'success');
        <?php else: ?>
            showToast('No previous notes found', 'warning');
        <?php endif; ?>
    }
}

function viewNoteDetails(note) {
    const modalContent = document.getElementById('noteDetailsContent');
    const noteDate = new Date(note.note_date);
    const recordedAt = new Date(note.recorded_at);
    
    let html = `
        <div class="card mb-3">
            <div class="card-header bg-light py-2">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0">
                            ${getNoteTypeBadgeHtml(note.note_type)} 
                            <span class="ml-2">${noteDate.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })}</span>
                        </h6>
                        <small class="text-muted">
                            Recorded: ${recordedAt.toLocaleDateString()} ${recordedAt.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}
                        </small>
                    </div>
                    <div>
                        ${getStatusBadgeHtml(note.status)}
                    </div>
                </div>
            </div>
            <div class="card-body">
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
    
    if (note.clinical_notes) {
        html += `<div class="mb-4">
                    <h6 class="text-secondary"><i class="fas fa-notes-medical mr-2"></i>Clinical Notes</h6>
                    <div class="p-3 bg-light rounded">${note.clinical_notes.replace(/\n/g, '<br>')}</div>
                </div>`;
    }
    
    if (note.recommendations) {
        html += `<div class="mb-4">
                    <h6 class="text-dark"><i class="fas fa-lightbulb mr-2"></i>Recommendations</h6>
                    <div class="p-3 bg-light rounded">${note.recommendations.replace(/\n/g, '<br>')}</div>
                </div>`;
    }
    
    html += `   </div>
            </div>`;
    
    modalContent.innerHTML = html;
    $('#noteDetailsModal').modal('show');
}

function getNoteTypeBadgeHtml(type) {
    switch(type) {
        case 'progress':
            return '<span class="badge badge-info"><i class="fas fa-clipboard-list mr-1"></i>Progress</span>';
        case 'consultation':
            return '<span class="badge badge-warning"><i class="fas fa-stethoscope mr-1"></i>Consultation</span>';
        case 'procedure':
            return '<span class="badge badge-danger"><i class="fas fa-procedures mr-1"></i>Procedure</span>';
        case 'follow_up':
            return '<span class="badge badge-secondary"><i class="fas fa-calendar-check mr-1"></i>Follow-up</span>';
        case 'discharge':
            return '<span class="badge badge-success"><i class="fas fa-sign-out-alt mr-1"></i>Discharge</span>';
        default:
            return '<span class="badge badge-secondary">' + type + '</span>';
    }
}

function getStatusBadgeHtml(status) {
    switch(status) {
        case 'finalized':
            return '<span class="badge badge-success"><i class="fas fa-lock mr-1"></i>Finalized</span>';
        case 'draft':
            return '<span class="badge badge-warning"><i class="fas fa-edit mr-1"></i>Draft</span>';
        default:
            return '<span class="badge badge-secondary">' + status + '</span>';
    }
}

function editNote(note) {
    // Populate form with note data
    $('#note_date').val(note.note_date);
    $('#note_type').val(note.note_type);
    $('#subjective').val(note.subjective);
    $('#objective').val(note.objective);
    $('#assessment').val(note.assessment);
    $('#plan').val(note.plan);
    $('#clinical_notes').val(note.clinical_notes);
    $('#recommendations').val(note.recommendations);
    
    // Trigger auto-expand
    $('textarea').trigger('input');
    
    // Add hidden field for note_id if needed
    if (!$('#note_id').length) {
        $('<input>').attr({
            type: 'hidden',
            id: 'note_id',
            name: 'note_id',
            value: note.note_id
        }).appendTo('#doctorNotesForm');
    } else {
        $('#note_id').val(note.note_id);
    }
    
    // Scroll to form
    $('html, body').animate({
        scrollTop: $('#doctorNotesForm').offset().top - 20
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
        $('#doctorNotesForm').submit();
    }
    // Ctrl + Shift + S for save and finalize
    if (e.ctrlKey && e.shiftKey && e.keyCode === 83) {
        e.preventDefault();
        setFinalizeFlag();
        $('#doctorNotesForm').submit();
    }
    // Ctrl + N for new note (clear form)
    if (e.ctrlKey && e.keyCode === 78) {
        e.preventDefault();
        clearForm();
    }
    // Ctrl + P for print
    if (e.ctrlKey && e.keyCode === 80) {
        e.preventDefault();
        window.print();
    }
    // Escape to close modals
    if (e.keyCode === 27 && $('.modal.show').length) {
        $('.modal').modal('hide');
    }
});
</script>

<style>
.soap-container .card {
    border-left-width: 4px !important;
}
.border-left-primary { border-left-color: #007bff !important; }
.border-left-success { border-left-color: #28a745 !important; }
.border-left-warning { border-left-color: #ffc107 !important; }
.border-left-info { border-left-color: #17a2b8 !important; }
.border-left-secondary { border-left-color: #6c757d !important; }
.border-left-dark { border-left-color: #343a40 !important; }

.list-group-item-info {
    background-color: #d1ecf1 !important;
}

/* Print styles */
@media print {
    .card-header, .card-tools, .btn, form, .modal,
    .card-footer, .toast-container, #templatesModal {
        display: none !important;
    }
    .card {
        border: none !important;
        box-shadow: none !important;
    }
    .card-body {
        padding: 0 !important;
    }
    .soap-container .card {
        border-left: none !important;
        margin-bottom: 1rem !important;
    }
    .soap-container .card-body {
        padding: 0.5rem !important;
    }
}
</style>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
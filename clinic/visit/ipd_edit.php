<?php
// ipd_edit.php - Edit IPD Admission
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/audit_functions.php';

// Check if ipd_admission_id is provided
$ipd_admission_id = isset($_GET['ipd_admission_id']) ? intval($_GET['ipd_admission_id']) : 0;

if ($ipd_admission_id <= 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid IPD Admission ID.";
    header("Location: ipd.php");
    exit;
}

// Fetch IPD admission details
$ipd_sql = "SELECT 
    ia.*,
    v.visit_id,
    v.visit_number,
    v.patient_id,
    v.visit_datetime,
    v.admission_datetime as visit_admission_datetime,
    v.department_id as visit_department_id,
    v.attending_provider_id as visit_attending_provider_id,
    p.first_name,
    p.last_name,
    p.patient_mrn,
    p.date_of_birth,
    p.sex,
    p.phone_primary,
    d.department_name,
    w.ward_name,
    b.bed_number,
    b.bed_type,
    b.bed_occupied,
    u1.user_name as admitting_provider_name,
    u2.user_name as attending_provider_name,
    u3.user_name as nurse_incharge_name
FROM ipd_admissions ia
LEFT JOIN visits v ON ia.visit_id = v.visit_id
LEFT JOIN patients p ON v.patient_id = p.patient_id
LEFT JOIN departments d ON ia.department_id = d.department_id
LEFT JOIN wards w ON ia.ward_id = w.ward_id
LEFT JOIN beds b ON ia.bed_id = b.bed_id
LEFT JOIN users u1 ON ia.admitting_provider_id = u1.user_id
LEFT JOIN users u2 ON ia.attending_provider_id = u2.user_id
LEFT JOIN users u3 ON ia.nurse_incharge_id = u3.user_id
WHERE ia.ipd_admission_id = ?";

$ipd_stmt = $mysqli->prepare($ipd_sql);
$ipd_stmt->bind_param("i", $ipd_admission_id);
$ipd_stmt->execute();
$ipd_result = $ipd_stmt->get_result();

if ($ipd_result->num_rows === 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "IPD Admission not found.";
    header("Location: ipd.php");
    exit;
}

$ipd_admission = $ipd_result->fetch_assoc();

// Check if admission can be edited (only active admissions)
$can_edit = ($ipd_admission['admission_status'] === 'ACTIVE');

// Get departments, doctors, nurses, wards, beds for dropdowns
$departments = [];
$doctors = [];
$nurses = [];
$wards = [];

// Fetch departments
$dept_sql = "SELECT department_id, department_name FROM departments 
            WHERE department_archived_at IS NULL 
            ORDER BY department_name";
$dept_result = $mysqli->query($dept_sql);
while ($row = $dept_result->fetch_assoc()) {
    $departments[] = $row;
}

// Fetch doctors (users)
$doctor_sql = "SELECT user_id, user_name FROM users ORDER BY user_name";
$doctor_result = $mysqli->query($doctor_sql);
while ($row = $doctor_result->fetch_assoc()) {
    $doctors[] = $row;
}

// Fetch nurses (users)
$nurse_sql = "SELECT user_id, user_name FROM users ORDER BY user_name";
$nurse_result = $mysqli->query($nurse_sql);
while ($row = $nurse_result->fetch_assoc()) {
    $nurses[] = $row;
}

// Fetch wards
$ward_sql = "SELECT ward_id, ward_name FROM wards 
            WHERE ward_archived_at IS NULL 
            ORDER BY ward_name";
$ward_result = $mysqli->query($ward_sql);
while ($row = $ward_result->fetch_assoc()) {
    $wards[] = $row;
}

// Get beds by ward
$beds_by_ward = [];
$beds_sql = "SELECT b.bed_id, b.bed_ward_id, b.bed_number, b.bed_type, b.bed_occupied
             FROM beds b
             WHERE b.bed_archived_at IS NULL
             ORDER BY b.bed_ward_id, b.bed_number";
$beds_result = $mysqli->query($beds_sql);
while ($row = $beds_result->fetch_assoc()) {
    $ward_id = $row['bed_ward_id'];
    if (!isset($beds_by_ward[$ward_id])) {
        $beds_by_ward[$ward_id] = [];
    }
    $beds_by_ward[$ward_id][] = $row;
}

// Get the current bed (even if occupied)
if ($ipd_admission['bed_id']) {
    $current_bed_id = $ipd_admission['bed_id'];
    $current_bed_ward_id = $ipd_admission['ward_id'];
    
    // Ensure current bed is in the beds_by_ward array
    if (!isset($beds_by_ward[$current_bed_ward_id])) {
        $beds_by_ward[$current_bed_ward_id] = [];
    }
    
    // Check if current bed is already in the array
    $found = false;
    foreach ($beds_by_ward[$current_bed_ward_id] as $bed) {
        if ($bed['bed_id'] == $current_bed_id) {
            $found = true;
            break;
        }
    }
    
    // If not found, fetch it
    if (!$found) {
        $bed_sql = "SELECT bed_id, bed_ward_id, bed_number, bed_type, bed_occupied 
                    FROM beds WHERE bed_id = ?";
        $bed_stmt = $mysqli->prepare($bed_sql);
        $bed_stmt->bind_param("i", $current_bed_id);
        $bed_stmt->execute();
        $bed_result = $bed_stmt->get_result();
        if ($bed_row = $bed_result->fetch_assoc()) {
            $beds_by_ward[$current_bed_ward_id][] = $bed_row;
        }
    }
}

// Admission types
$admission_types = [
    'EMERGENCY' => 'Emergency',
    'ELECTIVE' => 'Elective',
    'REFERRAL' => 'Referral'
];

// Get today's IPD stats
$today_ipd_sql = "SELECT COUNT(*) as count FROM ipd_admissions WHERE DATE(created_at) = CURDATE()";
$today_ipd_result = $mysqli->query($today_ipd_sql);
$today_ipd_admissions = $today_ipd_result->fetch_assoc()['count'];

// Get active IPD admissions
$active_ipd_sql = "SELECT COUNT(*) as count FROM ipd_admissions WHERE admission_status = 'ACTIVE'";
$active_ipd_result = $mysqli->query($active_ipd_sql);
$active_ipd_admissions = $active_ipd_result->fetch_assoc()['count'];

// Get available beds count
$available_beds_sql = "SELECT COUNT(*) as count FROM beds WHERE bed_occupied = 0 AND bed_archived_at IS NULL";
$available_beds_result = $mysqli->query($available_beds_sql);
$available_beds = $available_beds_result->fetch_assoc()['count'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    
    // Validate CSRF token
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        header("Location: ipd_edit.php?ipd_admission_id=" . $ipd_admission_id);
        exit;
    }
    
    // IPD Admission information
    $admission_date = sanitizeInput($_POST['admission_date']);
    $admission_type = sanitizeInput($_POST['admission_type'] ?? 'ELECTIVE');
    $department_id = intval($_POST['department_id'] ?? 0);
    $ward_id = intval($_POST['ward_id'] ?? 0);
    $bed_id = intval($_POST['bed_id'] ?? 0);
    $admitting_provider_id = intval($_POST['admitting_provider_id'] ?? 0);
    $attending_provider_id = intval($_POST['attending_provider_id'] ?? 0);
    $nurse_incharge_id = intval($_POST['nurse_incharge_id'] ?? 0);
    
    // Referral information
    $referred_from = sanitizeInput($_POST['referred_from'] ?? '');
    
    // Validate required fields
    if (empty($admission_date) || empty($admission_type)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please fill in admission date and type.";
        header("Location: ipd_edit.php?ipd_admission_id=" . $ipd_admission_id);
        exit;
    }

    if ($admitting_provider_id <= 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please select an admitting provider.";
        header("Location: ipd_edit.php?ipd_admission_id=" . $ipd_admission_id);
        exit;
    }

    if ($department_id <= 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please select a department.";
        header("Location: ipd_edit.php?ipd_admission_id=" . $ipd_admission_id);
        exit;
    }

    if ($ward_id <= 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please select a ward.";
        header("Location: ipd_edit.php?ipd_admission_id=" . $ipd_admission_id);
        exit;
    }

    if ($bed_id <= 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please select a bed.";
        header("Location: ipd_edit.php?ipd_admission_id=" . $ipd_admission_id);
        exit;
    }

    // Validate that bed is not already occupied by another patient
    if ($bed_id != $ipd_admission['bed_id']) {
        $check_bed_sql = "SELECT bed_occupied FROM beds WHERE bed_id = ? AND bed_id != ?";
        $check_bed_stmt = $mysqli->prepare($check_bed_sql);
        $check_bed_stmt->bind_param("ii", $bed_id, $ipd_admission['bed_id']);
        $check_bed_stmt->execute();
        $check_bed_result = $check_bed_stmt->get_result();
        
        if ($check_bed_row = $check_bed_result->fetch_assoc()) {
            if ($check_bed_row['bed_occupied'] == 1) {
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = "Selected bed is already occupied by another patient. Please choose another bed.";
                header("Location: ipd_edit.php?ipd_admission_id=" . $ipd_admission_id);
                exit;
            }
        }
        $check_bed_stmt->close();
    }

    // Start transaction
    $mysqli->begin_transaction();

    try {
        // Store old values for audit log
        $old_data = [
            'admission_datetime'     => $ipd_admission['admission_datetime'],
            'admission_type'         => $ipd_admission['admission_type'],
            'department_id'          => $ipd_admission['department_id'],
            'ward_id'                => $ipd_admission['ward_id'],
            'bed_id'                 => $ipd_admission['bed_id'],
            'referred_from'          => $ipd_admission['referred_from'],
            'admitting_provider_id'  => $ipd_admission['admitting_provider_id'],
            'attending_provider_id'  => $ipd_admission['attending_provider_id'],
            'nurse_incharge_id'      => $ipd_admission['nurse_incharge_id']
        ];
        
        // Update IPD admission record
        $update_sql = "UPDATE ipd_admissions SET
            admission_datetime = ?,
            admission_type = ?,
            department_id = ?,
            ward_id = ?,
            bed_id = ?,
            referred_from = ?,
            admitting_provider_id = ?,
            attending_provider_id = ?,
            nurse_incharge_id = ?,
            updated_at = NOW()
        WHERE ipd_admission_id = ?";
        
        $update_stmt = $mysqli->prepare($update_sql);
        if (!$update_stmt) {
            throw new Exception("Database error: " . $mysqli->error);
        }
        
        $referred_from = empty($referred_from) ? NULL : $referred_from;
        $attending_provider_id = $attending_provider_id <= 0 ? NULL : $attending_provider_id;
        $nurse_incharge_id = $nurse_incharge_id <= 0 ? NULL : $nurse_incharge_id;
        
        $update_stmt->bind_param(
            "ssiiisiiii",
            $admission_date,
            $admission_type,
            $department_id,
            $ward_id,
            $bed_id,
            $referred_from,
            $admitting_provider_id,
            $attending_provider_id,
            $nurse_incharge_id,
            $ipd_admission_id
        );

        if (!$update_stmt->execute()) {
            throw new Exception("Error updating IPD admission: " . $mysqli->error);
        }
        
        // Update bed status if bed changed
        if ($bed_id != $ipd_admission['bed_id']) {
            // Free old bed
            if ($ipd_admission['bed_id']) {
                $free_bed_sql = "UPDATE beds SET bed_occupied = 0 WHERE bed_id = ?";
                $free_bed_stmt = $mysqli->prepare($free_bed_sql);
                $free_bed_stmt->bind_param("i", $ipd_admission['bed_id']);
                
                if (!$free_bed_stmt->execute()) {
                    throw new Exception("Error freeing old bed: " . $mysqli->error);
                }
                $free_bed_stmt->close();
                
                // AUDIT LOG: Log bed status update (free)
                audit_log($mysqli, [
                    'user_id'     => $session_user_id,
                    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                    'action'      => 'UPDATE',
                    'module'      => 'Beds',
                    'table_name'  => 'beds',
                    'entity_type' => 'bed',
                    'record_id'   => $ipd_admission['bed_id'],
                    'patient_id'  => $ipd_admission['patient_id'],
                    'visit_id'    => $ipd_admission['visit_id'],
                    'description' => "Freed bed for IPD admission: " . $ipd_admission['admission_number'],
                    'status'      => 'SUCCESS',
                    'old_values'  => ['bed_occupied' => 1],
                    'new_values'  => ['bed_occupied' => 0]
                ]);
            }
            
            // Occupy new bed
            $occupy_bed_sql = "UPDATE beds SET bed_occupied = 1 WHERE bed_id = ?";
            $occupy_bed_stmt = $mysqli->prepare($occupy_bed_sql);
            $occupy_bed_stmt->bind_param("i", $bed_id);
            
            if (!$occupy_bed_stmt->execute()) {
                throw new Exception("Error occupying new bed: " . $mysqli->error);
            }
            $occupy_bed_stmt->close();
            
            // AUDIT LOG: Log bed status update (occupy)
            audit_log($mysqli, [
                'user_id'     => $session_user_id,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'UPDATE',
                'module'      => 'Beds',
                'table_name'  => 'beds',
                'entity_type' => 'bed',
                'record_id'   => $bed_id,
                'patient_id'  => $ipd_admission['patient_id'],
                'visit_id'    => $ipd_admission['visit_id'],
                'description' => "Occupied bed for IPD admission: " . $ipd_admission['admission_number'],
                'status'      => 'SUCCESS',
                'old_values'  => ['bed_occupied' => 0],
                'new_values'  => ['bed_occupied' => 1]
            ]);
        }
        
        // Update visit admission datetime
        $update_visit_sql = "UPDATE visits SET admission_datetime = ? WHERE visit_id = ?";
        $update_visit_stmt = $mysqli->prepare($update_visit_sql);
        $update_visit_stmt->bind_param("si", $admission_date, $ipd_admission['visit_id']);
        
        if (!$update_visit_stmt->execute()) {
            throw new Exception("Error updating visit admission datetime: " . $mysqli->error);
        }
        $update_visit_stmt->close();
        
        // Commit the transaction
        $mysqli->commit();
        
        // Build new data for audit log
        $new_data = [
            'admission_datetime'     => $admission_date,
            'admission_type'         => $admission_type,
            'department_id'          => $department_id,
            'ward_id'                => $ward_id,
            'bed_id'                 => $bed_id,
            'referred_from'          => $referred_from,
            'admitting_provider_id'  => $admitting_provider_id,
            'attending_provider_id'  => $attending_provider_id,
            'nurse_incharge_id'      => $nurse_incharge_id
        ];
        
        // AUDIT LOG: Log IPD admission update
        audit_log($mysqli, [
            'user_id'     => $session_user_id,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'UPDATE',
            'module'      => 'IPD Admissions',
            'table_name'  => 'ipd_admissions',
            'entity_type' => 'ipd_admission',
            'record_id'   => $ipd_admission_id,
            'patient_id'  => $ipd_admission['patient_id'],
            'visit_id'    => $ipd_admission['visit_id'],
            'description' => "Updated IPD admission: " . $ipd_admission['admission_number'],
            'status'      => 'SUCCESS',
            'old_values'  => $old_data,
            'new_values'  => $new_data
        ]);
        
        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "IPD Admission updated successfully!";
        
        if ($bed_id != $ipd_admission['bed_id']) {
            $_SESSION['alert_message'] .= "<br>Bed changed from " . $ipd_admission['bed_number'] . " to new bed.";
        }
        
        header("Location: ipd_details.php?ipd_admission_id=" . $ipd_admission_id);
        exit;

    } catch (Exception $e) {
        $mysqli->rollback();
        
        // AUDIT LOG: Log failed update attempt
        audit_log($mysqli, [
            'user_id'     => $session_user_id,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'UPDATE',
            'module'      => 'IPD Admissions',
            'table_name'  => 'ipd_admissions',
            'entity_type' => 'ipd_admission',
            'record_id'   => $ipd_admission_id,
            'patient_id'  => $ipd_admission['patient_id'],
            'visit_id'    => $ipd_admission['visit_id'],
            'description' => "Failed to update IPD admission: " . $ipd_admission['admission_number'],
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => null,
            'error'       => $e->getMessage()
        ]);
        
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error updating IPD admission: " . $e->getMessage();
        header("Location: ipd_edit.php?ipd_admission_id=" . $ipd_admission_id);
        exit;
    }
}

// Calculate patient age
$patient_age = '';
if (!empty($ipd_admission['date_of_birth'])) {
    $birthDate = new DateTime($ipd_admission['date_of_birth']);
    $today = new DateTime();
    $patient_age = $today->diff($birthDate)->y . ' yrs';
}

$patient_name = htmlspecialchars($ipd_admission['last_name'] . ', ' . $ipd_admission['first_name']);
$admission_datetime = $ipd_admission['admission_datetime'];
$formatted_admission_datetime = date('Y-m-d\TH:i', strtotime($admission_datetime));
?>

<div class="card">
    <div class="card-header bg-warning py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-edit mr-2"></i>Edit IPD Admission: <?php echo htmlspecialchars($ipd_admission['admission_number']); ?>
        </h3>
        <div class="card-tools">
            <div class="btn-group">
                <a href="ipd_details.php?ipd_admission_id=<?php echo $ipd_admission_id; ?>" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to IPD Details
                </a>
                <?php if ($can_edit): ?>
                <a href="ipd_add.php" class="btn btn-light ml-2">
                    <i class="fas fa-plus mr-2"></i>New IPD Admission
                </a>
                <?php endif; ?>
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

        <!-- Edit Status Header -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="btn-toolbar justify-content-between">
                    <div class="btn-group">
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Status:</strong> 
                            <?php
                            $status_color = '';
                            switch($ipd_admission['admission_status']) {
                                case 'ACTIVE': $status_color = 'success'; break;
                                case 'DISCHARGED': $status_color = 'info'; break;
                                case 'TRANSFERRED': $status_color = 'warning'; break;
                                case 'CANCELLED': $status_color = 'danger'; break;
                                default: $status_color = 'secondary';
                            }
                            ?>
                            <span class="badge badge-<?php echo $status_color; ?> ml-2">
                                <?php echo htmlspecialchars($ipd_admission['admission_status']); ?>
                            </span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Admission #:</strong> 
                            <span class="badge badge-dark ml-2"><?php echo htmlspecialchars($ipd_admission['admission_number']); ?></span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Visit #:</strong> 
                            <span class="badge badge-info ml-2"><?php echo htmlspecialchars($ipd_admission['visit_number']); ?></span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Today:</strong> 
                            <span class="badge badge-success ml-2"><?php echo date('M j, Y'); ?></span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>IPD Today:</strong> 
                            <span class="badge badge-primary ml-2"><?php echo $today_ipd_admissions; ?></span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Active IPD:</strong> 
                            <span class="badge badge-warning ml-2"><?php echo $active_ipd_admissions; ?></span>
                        </span>
                    </div>
                    <div class="btn-group">
                        <a href="ipd_details.php?ipd_admission_id=<?php echo $ipd_admission_id; ?>" class="btn btn-secondary">
                            <i class="fas fa-times mr-2"></i>Cancel
                        </a>
                        <?php if ($can_edit): ?>
                        <button type="submit" form="ipdEditForm" class="btn btn-primary">
                            <i class="fas fa-save mr-2"></i>Update Admission
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!$can_edit): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle mr-2"></i>
                This IPD admission is <?php echo $ipd_admission['admission_status']; ?> and cannot be edited. 
                Only ACTIVE admissions can be modified.
            </div>
        <?php endif; ?>

        <form method="post" id="ipdEditForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

            <div class="row">
                <!-- Left Column: Patient Info & Admission Details -->
                <div class="col-md-8">
                    <!-- Patient Information Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-user mr-2"></i>Patient Information</h4>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Patient Name</label>
                                        <div class="form-control" style="height: auto; min-height: 38px; background-color: #f8f9fa;">
                                            <strong><?php echo $patient_name; ?></strong>
                                            <br>
                                            <small class="text-muted">MRN: <?php echo htmlspecialchars($ipd_admission['patient_mrn']); ?></small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label>Gender/Age</label>
                                        <div class="form-control" style="height: auto; min-height: 38px; background-color: #f8f9fa;">
                                            <strong><?php echo htmlspecialchars($ipd_admission['sex'] . ' / ' . $patient_age); ?></strong>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label>Visit Number</label>
                                        <div class="form-control" style="height: auto; min-height: 38px; background-color: #f8f9fa;">
                                            <strong class="text-primary"><?php echo htmlspecialchars($ipd_admission['visit_number']); ?></strong>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Phone</label>
                                        <div class="form-control" style="height: auto; min-height: 38px; background-color: #f8f9fa;">
                                            <strong><?php echo htmlspecialchars($ipd_admission['phone_primary']); ?></strong>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Visit Date</label>
                                        <div class="form-control" style="height: auto; min-height: 38px; background-color: #f8f9fa;">
                                            <strong><?php echo date('M j, Y H:i', strtotime($ipd_admission['visit_datetime'])); ?></strong>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="text-center mt-3">
                                <a href="patient_details.php?patient_id=<?php echo $ipd_admission['patient_id']; ?>" class="btn btn-outline-primary btn-sm">
                                    <i class="fas fa-user mr-2"></i>View Patient Details
                                </a>
                                <a href="visit_details.php?visit_id=<?php echo $ipd_admission['visit_id']; ?>" class="btn btn-outline-info btn-sm ml-2">
                                    <i class="fas fa-calendar-alt mr-2"></i>View Visit Details
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- IPD Admission Information Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-calendar-alt mr-2"></i>IPD Admission Information</h4>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label class="required">Admission Date & Time</label>
                                        <input type="datetime-local" class="form-control" name="admission_date" id="admission_date" 
                                               value="<?php echo $formatted_admission_datetime; ?>" required <?php echo !$can_edit ? 'disabled' : ''; ?>>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label class="required">Admission Type</label>
                                        <select class="form-control select2" name="admission_type" id="admission_type" 
                                                required data-placeholder="Select admission type" <?php echo !$can_edit ? 'disabled' : ''; ?>>
                                            <option value="">- Select Admission Type -</option>
                                            <?php foreach ($admission_types as $key => $label): ?>
                                                <option value="<?php echo $key; ?>" <?php echo $ipd_admission['admission_type'] == $key ? 'selected' : ''; ?>>
                                                    <?php echo $label; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label class="required">Department</label>
                                        <select class="form-control select2" name="department_id" id="department_id" 
                                                required data-placeholder="Select department" <?php echo !$can_edit ? 'disabled' : ''; ?>>
                                            <option value="">- Select Department -</option>
                                            <?php foreach ($departments as $dept): ?>
                                                <option value="<?php echo $dept['department_id']; ?>" 
                                                    <?php echo $ipd_admission['department_id'] == $dept['department_id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($dept['department_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="required">Admitting Provider</label>
                                        <select class="form-control select2" name="admitting_provider_id" id="admitting_provider_id" 
                                                required data-placeholder="Select admitting doctor" <?php echo !$can_edit ? 'disabled' : ''; ?>>
                                            <option value="">- Select Admitting Doctor -</option>
                                            <?php foreach ($doctors as $doctor): ?>
                                                <option value="<?php echo $doctor['user_id']; ?>" 
                                                    <?php echo $ipd_admission['admitting_provider_id'] == $doctor['user_id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($doctor['user_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Attending Provider (Incharge Doctor)</label>
                                        <select class="form-control select2" name="attending_provider_id" id="attending_provider_id" 
                                                data-placeholder="Select attending doctor" <?php echo !$can_edit ? 'disabled' : ''; ?>>
                                            <option value=""></option>
                                            <?php foreach ($doctors as $doctor): ?>
                                                <option value="<?php echo $doctor['user_id']; ?>" 
                                                    <?php echo $ipd_admission['attending_provider_id'] == $doctor['user_id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($doctor['user_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Nurse Incharge</label>
                                        <select class="form-control select2" name="nurse_incharge_id" id="nurse_incharge_id" 
                                                data-placeholder="Select nurse incharge" <?php echo !$can_edit ? 'disabled' : ''; ?>>
                                            <option value=""></option>
                                            <?php foreach ($nurses as $nurse): ?>
                                                <option value="<?php echo $nurse['user_id']; ?>" 
                                                    <?php echo $ipd_admission['nurse_incharge_id'] == $nurse['user_id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($nurse['user_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Referred From (If Referral)</label>
                                        <input type="text" class="form-control" name="referred_from" id="referred_from" 
                                               value="<?php echo htmlspecialchars($ipd_admission['referred_from'] ?? ''); ?>"
                                               placeholder="Name of referring facility/person" maxlength="150" <?php echo !$can_edit ? 'disabled' : ''; ?>>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Ward and Bed Assignment Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-bed mr-2"></i>Ward & Bed Assignment</h4>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="required">Ward</label>
                                        <select class="form-control select2" name="ward_id" id="ward_id" 
                                                required data-placeholder="Select ward" <?php echo !$can_edit ? 'disabled' : ''; ?>>
                                            <option value="">- Select Ward -</option>
                                            <?php foreach ($wards as $ward): ?>
                                                <option value="<?php echo $ward['ward_id']; ?>" 
                                                    <?php echo $ipd_admission['ward_id'] == $ward['ward_id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($ward['ward_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="required">Bed</label>
                                        <select class="form-control" name="bed_id" id="bed_id" required <?php echo !$can_edit ? 'disabled' : ''; ?>>
                                            <option value="">- Select Ward First -</option>
                                            <?php if ($ipd_admission['ward_id']): ?>
                                                <?php 
                                                $current_bed_id = $ipd_admission['bed_id'];
                                                $current_ward_id = $ipd_admission['ward_id'];
                                                ?>
                                                <?php if (isset($beds_by_ward[$current_ward_id])): ?>
                                                    <?php foreach ($beds_by_ward[$current_ward_id] as $bed): 
                                                        $isOccupied = $bed['bed_occupied'] == 1;
                                                        $bedText = $bed['bed_number'];
                                                        
                                                        if ($isOccupied && $bed['bed_id'] != $current_bed_id) {
                                                            $bedText .= ' (Occupied)';
                                                        } elseif ($bed['bed_id'] == $current_bed_id) {
                                                            $bedText .= ' (Current Bed)';
                                                        } else {
                                                            $bedText .= ' (Available)';
                                                        }
                                                        
                                                        if ($bed['bed_type']) {
                                                            $bedText .= ' - ' . $bed['bed_type'];
                                                        }
                                                    ?>
                                                        <option value="<?php echo $bed['bed_id']; ?>" 
                                                            <?php echo $bed['bed_id'] == $current_bed_id ? 'selected' : ''; ?>
                                                            <?php echo ($isOccupied && $bed['bed_id'] != $current_bed_id) ? 'disabled' : ''; ?>>
                                                            <?php echo $bedText; ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <?php if ($ipd_admission['bed_id']): ?>
                                <div class="alert alert-warning mt-3">
                                    <i class="fas fa-exclamation-triangle mr-2"></i>
                                    <strong>Note:</strong> Changing beds will automatically free the current bed (<?php echo htmlspecialchars($ipd_admission['bed_number']); ?>) 
                                    and occupy the new bed. The current bed is marked as "Occupied" for other patients.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Actions & Preview -->
                <div class="col-md-4">
                    <!-- Edit Actions Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-bolt mr-2"></i>Edit Actions</h4>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <?php if ($can_edit && SimplePermission::any("ipd_admission_update")): ?>
                                <button type="submit" class="btn btn-primary btn-lg" id="submitBtn">
                                    <i class="fas fa-save mr-2"></i>Update Admission
                                </button>
                                <?php endif; ?>
                                
                                <button type="reset" class="btn btn-outline-secondary" <?php echo !$can_edit ? 'disabled' : ''; ?>>
                                    <i class="fas fa-redo mr-2"></i>Reset Changes
                                </button>
                                
                                <a href="ipd_details.php?ipd_admission_id=<?php echo $ipd_admission_id; ?>" class="btn btn-outline-danger">
                                    <i class="fas fa-times mr-2"></i>Cancel
                                </a>
                            </div>
                            <hr>
                            <div class="small">
                                <p class="mb-2"><strong>Keyboard Shortcuts:</strong></p>
                                <div class="row">
                                    <div class="col-6">
                                        <span class="badge badge-light">Ctrl + S</span> Save
                                    </div>
                                    <div class="col-6">
                                        <span class="badge badge-light">Ctrl + R</span> Reset
                                    </div>
                                </div>
                                <div class="row mt-1">
                                    <div class="col-6">
                                        <span class="badge badge-light">Ctrl + W</span> Ward
                                    </div>
                                    <div class="col-6">
                                        <span class="badge badge-light">Esc</span> Cancel
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Admission Preview Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-eye mr-2"></i>Admission Preview</h4>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-3">
                                <div class="preview-icon mb-2">
                                    <i class="fas fa-procedures fa-2x text-info"></i>
                                </div>
                                <h5><?php echo htmlspecialchars($admission_types[$ipd_admission['admission_type']] ?? 'IPD Admission'); ?></h5>
                                <div class="text-muted small"><?php echo htmlspecialchars($ipd_admission['admission_number']); ?></div>
                            </div>
                            <hr>
                            <div class="small">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Patient:</span>
                                    <span id="preview_patient" class="font-weight-bold text-primary">
                                        <?php echo $patient_name; ?>
                                    </span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Visit #:</span>
                                    <span class="font-weight-bold text-primary"><?php echo htmlspecialchars($ipd_admission['visit_number']); ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Admission Time:</span>
                                    <span id="preview_datetime" class="font-weight-bold text-primary">
                                        <?php echo date('M j, Y H:i', strtotime($admission_datetime)); ?>
                                    </span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Admission Type:</span>
                                    <span id="preview_admission_type" class="font-weight-bold text-primary">
                                        <?php echo htmlspecialchars($admission_types[$ipd_admission['admission_type']] ?? $ipd_admission['admission_type']); ?>
                                    </span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Ward/Bed:</span>
                                    <span id="preview_ward_bed" class="font-weight-bold text-primary">
                                        <?php 
                                        echo htmlspecialchars($ipd_admission['ward_name'] ?? 'N/A') . ' / ' . 
                                             htmlspecialchars($ipd_admission['bed_number'] ?? 'N/A');
                                        ?>
                                    </span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Admitting Dr:</span>
                                    <span id="preview_admitting_provider" class="font-weight-bold text-primary">
                                        <?php echo htmlspecialchars($ipd_admission['admitting_provider_name'] ?? 'N/A'); ?>
                                    </span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Attending Dr:</span>
                                    <span id="preview_attending_provider" class="font-weight-bold text-primary">
                                        <?php echo htmlspecialchars($ipd_admission['attending_provider_name'] ?? 'N/A'); ?>
                                    </span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Status:</span>
                                    <span id="preview_status" class="font-weight-bold text-success">
                                        <?php echo htmlspecialchars($ipd_admission['admission_status']); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Today's IPD Statistics Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-chart-bar mr-2"></i>Today's IPD Statistics</h4>
                        </div>
                        <div class="card-body">
                            <div class="row text-center">
                                <div class="col-6">
                                    <div class="stat-box bg-primary-light p-2 rounded mb-2">
                                        <i class="fas fa-procedures fa-lg text-primary mb-1"></i>
                                        <h5 class="mb-0"><?php echo $today_ipd_admissions; ?></h5>
                                        <small class="text-muted">IPD Today</small>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="stat-box bg-warning-light p-2 rounded mb-2">
                                        <i class="fas fa-user-injured fa-lg text-warning mb-1"></i>
                                        <h5 class="mb-0"><?php echo $active_ipd_admissions; ?></h5>
                                        <small class="text-muted">Active IPD</small>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="stat-box bg-danger-light p-2 rounded">
                                        <i class="fas fa-bed fa-lg text-danger mb-1"></i>
                                        <h5 class="mb-0"><?php echo $available_beds; ?></h5>
                                        <small class="text-muted">Available Beds</small>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="stat-box bg-success-light p-2 rounded">
                                        <i class="fas fa-hospital fa-lg text-success mb-1"></i>
                                        <h5 class="mb-0"><?php echo count($wards); ?></h5>
                                        <small class="text-muted">Total Wards</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Form Tips Card -->
                    <div class="card">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-lightbulb mr-2"></i>Important Notes</h4>
                        </div>
                        <div class="card-body">
                            <ul class="list-unstyled small mb-0">
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-success mr-2"></i>
                                    <span class="text-danger">*</span> denotes required fields
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-exclamation-circle text-danger mr-2"></i>
                                    Only ACTIVE admissions can be edited
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-success mr-2"></i>
                                    Changing bed updates bed occupancy automatically
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-success mr-2"></i>
                                    All changes are logged for audit
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-success mr-2"></i>
                                    Admission number cannot be changed
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-success mr-2"></i>
                                    Ward and bed selection are required
                                </li>
                                <li>
                                    <i class="fas fa-check-circle text-success mr-2"></i>
                                    Billing can be done separately after admission
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Form Actions Footer -->
            <div class="row mt-4">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-body py-2">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <small class="text-muted">
                                        <i class="fas fa-info-circle mr-1"></i>
                                        Admission #<?php echo htmlspecialchars($ipd_admission['admission_number']); ?> • 
                                        Last updated: <?php echo !empty($ipd_admission['updated_at']) ? date('M j, Y H:i', strtotime($ipd_admission['updated_at'])) : 'Never'; ?>
                                    </small>
                                </div>
                                <div>
                                    <a href="ipd_details.php?ipd_admission_id=<?php echo $ipd_admission_id; ?>" class="btn btn-secondary btn-sm">
                                        <i class="fas fa-times mr-1"></i>Cancel
                                    </a>
                                    <button type="reset" class="btn btn-outline-secondary btn-sm" <?php echo !$can_edit ? 'disabled' : ''; ?>>
                                        <i class="fas fa-redo mr-1"></i>Reset
                                    </button>
                                    <?php if ($can_edit && SimplePermission::any("ipd_admission_update")) { ?>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save mr-1"></i>Update Admission
                                    </button>
                                    <?php } ?>
                                </div>
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
    $('.select2').select2({
        width: '100%',
        placeholder: "Select...",
        theme: 'bootstrap',
        minimumResultsForSearch: 10
    });

    // Convert PHP data to JavaScript
    var bedsData = <?php echo json_encode($beds_by_ward); ?>;
    var currentBedId = <?php echo $ipd_admission['bed_id'] ?: 'null'; ?>;
    var currentWardId = <?php echo $ipd_admission['ward_id'] ?: 'null'; ?>;

    // When ward selection changes, load beds
    $('#ward_id').change(function() {
        var wardId = $(this).val();
        var bedsSelect = $('#bed_id');
        
        bedsSelect.empty();
        
        if (wardId && bedsData[wardId]) {
            var beds = bedsData[wardId];
            
            bedsSelect.append('<option value="">- Select Bed -</option>');
            
            $.each(beds, function(index, bed) {
                var isOccupied = bed.bed_occupied == 1;
                var isCurrentBed = bed.bed_id == currentBedId;
                var bedText = bed.bed_number;
                
                if (isCurrentBed) {
                    bedText += ' (Current Bed)';
                } else if (isOccupied) {
                    bedText += ' (Occupied)';
                } else {
                    bedText += ' (Available)';
                }
                
                if (bed.bed_type) {
                    bedText += ' - ' + bed.bed_type;
                }
                
                bedsSelect.append($('<option>', {
                    value: bed.bed_id,
                    text: bedText,
                    disabled: (isOccupied && !isCurrentBed)
                }));
            });
            
            bedsSelect.prop('disabled', false);
            
            // Update preview
            updateWardBedPreview();
        } else {
            bedsSelect.append('<option value="">- No beds available -</option>').prop('disabled', true);
            $('#preview_ward_bed').text('-');
        }
        
        // If this is the current ward, select the current bed
        if (wardId == currentWardId && currentBedId) {
            bedsSelect.val(currentBedId);
        }
    });

    // Initialize bed dropdown if ward is already selected
    if (currentWardId) {
        $('#ward_id').trigger('change');
    }

    // Function to update ward/bed preview
    function updateWardBedPreview() {
        var wardText = $('#ward_id option:selected').text();
        var bedText = $('#bed_id option:selected').text();
        
        if (wardText && wardText !== '' && bedText && bedText !== '- Select Bed -' && bedText !== '- No beds available -' && bedText !== '- Select Ward First -') {
            // Extract just the bed number (remove status info)
            var bedNumber = bedText.split(' (')[0];
            $('#preview_ward_bed').text(wardText + ' - ' + bedNumber);
        } else {
            $('#preview_ward_bed').text('-');
        }
    }

    // Update preview when admission type changes
    $('#admission_type').change(function() {
        var selectedText = $(this).find('option:selected').text();
        $('#preview_admission_type').text(selectedText || '-');
    });

    // Update preview when admitting provider changes
    $('#admitting_provider_id').change(function() {
        var selectedText = $(this).find('option:selected').text();
        $('#preview_admitting_provider').text(selectedText || '-');
    });

    // Update preview when attending provider changes
    $('#attending_provider_id').change(function() {
        var selectedText = $(this).find('option:selected').text();
        $('#preview_attending_provider').text(selectedText || '-');
    });

    // Update preview when date/time changes
    $('#admission_date').change(function() {
        if ($(this).val()) {
            var date = new Date($(this).val());
            var formattedDate = date.toLocaleString('en-US', {
                month: 'short',
                day: 'numeric',
                year: 'numeric',
                hour: 'numeric',
                minute: '2-digit',
                hour12: true
            });
            $('#preview_datetime').text(formattedDate);
        }
    });

    // Form validation
    $('#ipdEditForm').on('submit', function(e) {
        var isValid = true;
        
        // Clear previous validation errors
        $('.is-invalid').removeClass('is-invalid');
        $('.select2-selection').removeClass('is-invalid');
        
        // Validate required fields
        var requiredFields = ['admission_date', 'admission_type', 'department_id', 'admitting_provider_id', 'ward_id', 'bed_id'];
        requiredFields.forEach(function(field) {
            if (!$('[name="' + field + '"]').val()) {
                isValid = false;
                $('[name="' + field + '"]').addClass('is-invalid');
                if ($('[name="' + field + '"]').is('select')) {
                    $('[name="' + field + '"]').next('.select2-container').find('.select2-selection').addClass('is-invalid');
                }
            } else {
                $('[name="' + field + '"]').removeClass('is-invalid');
                if ($('[name="' + field + '"]').is('select')) {
                    $('[name="' + field + '"]').next('.select2-container').find('.select2-selection').removeClass('is-invalid');
                }
            }
        });
        
        // Check if new bed is occupied (except by current patient)
        if ($('#bed_id').val() && $('#bed_id').val() != currentBedId) {
            var selectedOption = $('#bed_id option:selected');
            var isOccupied = selectedOption.text().includes('(Occupied)');
            
            if (isOccupied) {
                isValid = false;
                $('#bed_id').addClass('is-invalid');
                alert('Selected bed is already occupied by another patient. Please choose another bed.');
            }
        }

        if (!isValid) {
            e.preventDefault();
            
            // Show error message
            if (!$('#formErrorAlert').length) {
                $('#ipdEditForm').prepend(
                    '<div class="alert alert-danger alert-dismissible" id="formErrorAlert">' +
                    '<button type="button" class="close" data-dismiss="alert">&times;</button>' +
                    '<i class="fas fa-exclamation-triangle mr-2"></i>' +
                    'Please fill in all required fields marked with *' +
                    '</div>'
                );
            }
            
            // Scroll to first error
            $('html, body').animate({
                scrollTop: $('.is-invalid').first().offset().top - 100
            }, 500);
            
            return false;
        }

        // Show loading state
        $('#submitBtn').html('<i class="fas fa-spinner fa-spin mr-2"></i>Updating...').prop('disabled', true);
    });

    // Disable form if admission cannot be edited
    <?php if (!$can_edit): ?>
        $('#ipdEditForm input, #ipdEditForm select, #ipdEditForm textarea, #ipdEditForm button[type="submit"], #ipdEditForm button[type="reset"]').prop('disabled', true);
        $('.select2').prop('disabled', true);
        $('#submitBtn').removeClass('btn-primary').addClass('btn-secondary').html('<i class="fas fa-lock mr-2"></i>Editing Disabled');
    <?php endif; ?>
});

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + S to save (only if can edit)
    <?php if ($can_edit): ?>
    if (e.ctrlKey && e.keyCode === 83) {
        e.preventDefault();
        $('#ipdEditForm').submit();
    }
    // Ctrl + R to reset form
    if (e.ctrlKey && e.keyCode === 82) {
        e.preventDefault();
        $('#ipdEditForm')[0].reset();
        // Reset Select2 to original values
        $('#admission_type').val('<?php echo $ipd_admission['admission_type']; ?>').trigger('change');
        $('#department_id').val('<?php echo $ipd_admission['department_id']; ?>').trigger('change');
        $('#admitting_provider_id').val('<?php echo $ipd_admission['admitting_provider_id']; ?>').trigger('change');
        $('#attending_provider_id').val('<?php echo $ipd_admission['attending_provider_id'] ?? ''; ?>').trigger('change');
        $('#nurse_incharge_id').val('<?php echo $ipd_admission['nurse_incharge_id'] ?? ''; ?>').trigger('change');
        $('#ward_id').val('<?php echo $ipd_admission['ward_id']; ?>').trigger('change');
        // Bed dropdown will be updated by ward change trigger
        // Reset preview
        $('#preview_admission_type').text('<?php echo $admission_types[$ipd_admission['admission_type']] ?? $ipd_admission['admission_type']; ?>');
        $('#preview_admitting_provider').text('<?php echo htmlspecialchars($ipd_admission['admitting_provider_name'] ?? 'N/A'); ?>');
        $('#preview_attending_provider').text('<?php echo htmlspecialchars($ipd_admission['attending_provider_name'] ?? 'N/A'); ?>');
        // Clear validation errors
        $('.is-invalid').removeClass('is-invalid');
        $('.select2-selection').removeClass('is-invalid');
    }
    // Ctrl + W to focus on ward selection
    if (e.ctrlKey && e.keyCode === 87) {
        e.preventDefault();
        $('#ward_id').select2('open');
    }
    <?php endif; ?>
    // Escape to cancel
    if (e.keyCode === 27) {
        window.location.href = 'ipd_details.php?ipd_admission_id=<?php echo $ipd_admission_id; ?>';
    }
});
</script>

<style>
.required:after {
    content: " *";
    color: #dc3545;
}
.select2-container .select2-selection.is-invalid {
    border-color: #dc3545;
}
.preview-icon {
    width: 60px;
    height: 60px;
    background-color: #e3f2fd;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto;
}
.stat-box {
    transition: all 0.3s ease;
}
.stat-box:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}
.bg-primary-light {
    background-color: rgba(13, 110, 253, 0.1);
}
.bg-warning-light {
    background-color: rgba(255, 193, 7, 0.1);
}
.bg-danger-light {
    background-color: rgba(220, 53, 69, 0.1);
}
.bg-success-light {
    background-color: rgba(25, 135, 84, 0.1);
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
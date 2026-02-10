<?php
// ipd_add.php - Register New IPD Admission (Simplified - Admissions Only)
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/audit_functions.php';

// Function to generate IPD admission number
function generateIPDAdmissionNumber($mysqli) {
    $year = date('Y');
    $prefix = 'IPD-KSC-' . $year . '-';
    
    // Get the last IPD admission number for current year
    $sql = "SELECT MAX(admission_number) AS last_number
            FROM ipd_admissions
            WHERE admission_number LIKE ?
            AND YEAR(created_at) = ?";
    
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $mysqli->error);
    }
    
    $like_prefix = $prefix . '%';
    $stmt->bind_param('si', $like_prefix, $year);
    $stmt->execute();
    
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    
    $last_number = 0;
    if (!empty($row['last_number'])) {
        // Extract the number after the last dash
        $parts = explode('-', $row['last_number']);
        $last_number = intval(end($parts));
    }
    
    $next_number = $last_number + 1;
    
    return $prefix . str_pad($next_number, 3, '0', STR_PAD_LEFT);
}

// Fetch active IPD visits for dropdown
$active_ipd_visits = [];

// Get active IPD visits from visits table (not already in ipd_admissions)
$visits_sql = "SELECT 
    v.visit_id,
    v.visit_number,
    v.patient_id,
    v.visit_datetime,
    v.department_id,
    v.attending_provider_id,
    p.first_name,
    p.last_name,
    p.patient_mrn,
    p.date_of_birth,
    p.sex,
    p.phone_primary,
    d.department_name,
    u.user_name as doctor_name
FROM visits v
JOIN patients p ON v.patient_id = p.patient_id
LEFT JOIN departments d ON v.department_id = d.department_id
LEFT JOIN users u ON v.attending_provider_id = u.user_id
WHERE v.visit_type = 'IPD'
AND v.visit_status = 'ACTIVE'
AND v.visit_id NOT IN (SELECT visit_id FROM ipd_admissions WHERE admission_status = 'ACTIVE')
AND p.patient_status = 'ACTIVE'
ORDER BY v.visit_datetime DESC";

$visits_result = $mysqli->query($visits_sql);
while ($row = $visits_result->fetch_assoc()) {
    $active_ipd_visits[] = $row;
}

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

// Get admission type breakdown for today
$emergency_admissions_sql = "SELECT COUNT(*) as count FROM ipd_admissions 
                            WHERE DATE(created_at) = CURDATE() 
                            AND admission_type = 'EMERGENCY'";
$emergency_admissions_result = $mysqli->query($emergency_admissions_sql);
$emergency_admissions = $emergency_admissions_result->fetch_assoc()['count'];

$elective_admissions_sql = "SELECT COUNT(*) as count FROM ipd_admissions 
                           WHERE DATE(created_at) = CURDATE() 
                           AND admission_type = 'ELECTIVE'";
$elective_admissions_result = $mysqli->query($elective_admissions_sql);
$elective_admissions = $elective_admissions_result->fetch_assoc()['count'];

$referral_admissions_sql = "SELECT COUNT(*) as count FROM ipd_admissions 
                           WHERE DATE(created_at) = CURDATE() 
                           AND admission_type = 'REFERRAL'";
$referral_admissions_result = $mysqli->query($referral_admissions_sql);
$referral_admissions = $referral_admissions_result->fetch_assoc()['count'];

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

// Admission types
$admission_types = [
    'EMERGENCY' => 'Emergency',
    'ELECTIVE' => 'Elective',
    'REFERRAL' => 'Referral'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    
    // Visit selection
    $visit_id = intval($_POST['visit_id'] ?? 0);
    
    if ($visit_id <= 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please select an IPD visit.";
        header("Location: ipd_add.php");
        exit;
    }
    
    // Get visit details
    $visit_sql = "SELECT v.*, p.patient_id, p.first_name, p.last_name, p.patient_mrn 
                  FROM visits v 
                  JOIN patients p ON v.patient_id = p.patient_id 
                  WHERE v.visit_id = ?";
    $visit_stmt = $mysqli->prepare($visit_sql);
    $visit_stmt->bind_param("i", $visit_id);
    $visit_stmt->execute();
    $visit_result = $visit_stmt->get_result();
    
    if ($visit_result->num_rows === 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Selected visit not found.";
        header("Location: ipd_add.php");
        exit;
    }
    
    $visit_data = $visit_result->fetch_assoc();
    $patient_id = $visit_data['patient_id'];
    $visit_number = $visit_data['visit_number'];
    
    // Check if visit already has active IPD admission
    $check_ipd_sql = "SELECT ipd_admission_id FROM ipd_admissions 
                     WHERE visit_id = ? AND admission_status = 'ACTIVE'";
    $check_stmt = $mysqli->prepare($check_ipd_sql);
    $check_stmt->bind_param("i", $visit_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "This visit already has an active IPD admission.";
        header("Location: ipd_add.php");
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
    
    // Validate CSRF token
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        header("Location: ipd_add.php");
        exit;
    }

    // Validate required fields
    if (empty($admission_date) || empty($admission_type)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please fill in admission date and type.";
        header("Location: ipd_add.php");
        exit;
    }

    if ($admitting_provider_id <= 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please select an admitting provider.";
        header("Location: ipd_add.php");
        exit;
    }

    if ($department_id <= 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please select a department.";
        header("Location: ipd_add.php");
        exit;
    }

    if ($ward_id <= 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please select a ward.";
        header("Location: ipd_add.php");
        exit;
    }

    if ($bed_id <= 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please select a bed.";
        header("Location: ipd_add.php");
        exit;
    }

    // Validate that bed is not already occupied
    $check_bed_sql = "SELECT bed_occupied FROM beds WHERE bed_id = ?";
    $check_bed_stmt = $mysqli->prepare($check_bed_sql);
    $check_bed_stmt->bind_param("i", $bed_id);
    $check_bed_stmt->execute();
    $check_bed_result = $check_bed_stmt->get_result();
    
    if ($check_bed_row = $check_bed_result->fetch_assoc()) {
        if ($check_bed_row['bed_occupied'] == 1) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Selected bed is already occupied. Please choose another bed.";
            header("Location: ipd_add.php");
            exit;
        }
    }
    
    $check_bed_stmt->close();

    // Start transaction
    $mysqli->begin_transaction();

    try {
        // Generate IPD admission number
        $admission_number = generateIPDAdmissionNumber($mysqli);
        
        // Get facility code from visit or use default
        $facility_code = !empty($visit_data['facility_code']) ? $visit_data['facility_code'] : 'KSC';
        
        // Create IPD admission record
        $ipd_sql = "INSERT INTO ipd_admissions (
            visit_id, admission_number,
            ward_id, bed_id,
            admission_datetime, admission_type, referred_from,
            admitting_provider_id, attending_provider_id, nurse_incharge_id,department_id,
            admission_status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ACTIVE')";
        
        $ipd_stmt = $mysqli->prepare($ipd_sql);
        if (!$ipd_stmt) {
            throw new Exception("Database error: " . $mysqli->error);
        }
        
        $referred_from = empty($referred_from) ? NULL : $referred_from;
        $attending_provider_id = $attending_provider_id <= 0 ? NULL : $attending_provider_id;
        $nurse_incharge_id = $nurse_incharge_id <= 0 ? NULL : $nurse_incharge_id;
        
        $ipd_stmt->bind_param(
            "isiisssiiii",
            $visit_id,
            $admission_number,
            $ward_id,
            $bed_id,
            $admission_date,
            $admission_type,
            $referred_from,
            $admitting_provider_id,
            $attending_provider_id,
            $nurse_incharge_id,
            $department_id
        );

        if (!$ipd_stmt->execute()) {
            throw new Exception("Error creating IPD admission: " . $mysqli->error);
        }
        
        $ipd_admission_id = $ipd_stmt->insert_id;
        
        // Update visit with admission datetime
        $update_visit_sql = "UPDATE visits SET admission_datetime = ? WHERE visit_id = ?";
        $update_visit_stmt = $mysqli->prepare($update_visit_sql);
        $update_visit_stmt->bind_param("si", $admission_date, $visit_id);
        
        if (!$update_visit_stmt->execute()) {
            throw new Exception("Error updating visit admission datetime: " . $mysqli->error);
        }
        $update_visit_stmt->close();
        
        // Mark bed as occupied
        $update_bed_sql = "UPDATE beds SET bed_occupied = 1 WHERE bed_id = ?";
        $update_bed_stmt = $mysqli->prepare($update_bed_sql);
        $update_bed_stmt->bind_param("i", $bed_id);
        
        if (!$update_bed_stmt->execute()) {
            throw new Exception("Error updating bed status: " . $mysqli->error);
        }
        $update_bed_stmt->close();
        
        // Commit the transaction
        $mysqli->commit();
        
        // BUILD NEW DATA for audit log - IPD Admission
        $new_data_ipd = [
            'admission_number'       => $admission_number,
            'visit_id'               => $visit_id,
            'visit_number'           => $visit_number,
            'ward_id'                => $ward_id,
            'bed_id'                 => $bed_id,
            'admission_datetime'     => $admission_date,
            'admission_type'         => $admission_type,
            'referred_from'          => $referred_from,
            'admitting_provider_id'  => $admitting_provider_id,
            'attending_provider_id'  => $attending_provider_id,
            'nurse_incharge_id'      => $nurse_incharge_id,
            'admission_status'       => 'ACTIVE'
        ];
        
        // AUDIT LOG: Log IPD admission creation
        audit_log($mysqli, [
            'user_id'     => $session_user_id,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'CREATE',
            'module'      => 'IPD Admissions',
            'table_name'  => 'ipd_admissions',
            'entity_type' => 'ipd_admission',
            'record_id'   => $ipd_admission_id,
            'patient_id'  => $patient_id,
            'visit_id'    => $visit_id,
            'description' => "Created IPD admission: " . $admission_number . " for visit: " . $visit_number,
            'status'      => 'SUCCESS',
            'old_values'  => null, // No old values for creation
            'new_values'  => $new_data_ipd
        ]);
        
        // AUDIT LOG: Log visit update (admission datetime)
        audit_log($mysqli, [
            'user_id'     => $session_user_id,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'UPDATE',
            'module'      => 'Visits',
            'table_name'  => 'visits',
            'entity_type' => 'visit',
            'record_id'   => $visit_id,
            'patient_id'  => $patient_id,
            'visit_id'    => $visit_id,
            'description' => "Updated visit with admission datetime for IPD admission: " . $admission_number,
            'status'      => 'SUCCESS',
            'old_values'  => ['admission_datetime' => null],
            'new_values'  => ['admission_datetime' => $admission_date]
        ]);
        
        // AUDIT LOG: Log bed status update
        audit_log($mysqli, [
            'user_id'     => $session_user_id,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'UPDATE',
            'module'      => 'Beds',
            'table_name'  => 'beds',
            'entity_type' => 'bed',
            'record_id'   => $bed_id,
            'patient_id'  => $patient_id,
            'visit_id'    => $visit_id,
            'description' => "Marked bed as occupied for IPD admission: " . $admission_number,
            'status'      => 'SUCCESS',
            'old_values'  => ['bed_occupied' => 0],
            'new_values'  => ['bed_occupied' => 1]
        ]);
        
        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "IPD Admission registered successfully!<br>Admission Number: " . $admission_number;
        
        // Add ward/bed information to success message
        $_SESSION['alert_message'] .= "<br>Ward/Bed assigned and marked as Occupied.";
        
        header("Location: ipd_details.php?ipd_admission_id=" . $ipd_admission_id);
        exit;

    } catch (Exception $e) {
        $mysqli->rollback();
        
        // AUDIT LOG: Log failed IPD admission attempt
        audit_log($mysqli, [
            'user_id'     => $session_user_id,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'CREATE',
            'module'      => 'IPD Admissions',
            'table_name'  => 'ipd_admissions',
            'entity_type' => 'ipd_admission',
            'record_id'   => 0,
            'patient_id'  => $patient_id,
            'visit_id'    => $visit_id,
            'description' => "Failed to create IPD admission for visit: " . $visit_number,
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => null,
            'error'       => $e->getMessage()
        ]);
        
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = $e->getMessage();
        header("Location: ipd_add.php");
        exit;
    }
}
?>


    <div class="card-body">
        <?php if (isset($_SESSION['alert_message'])): ?>
            <div class="alert alert-<?php echo $_SESSION['alert_type']; ?> alert-dismissible">
                <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
                <i class="icon fas fa-<?php echo $_SESSION['alert_type'] == 'success' ? 'check' : 'exclamation-triangle'; ?>"></i>
                <?php echo nl2br(htmlspecialchars($_SESSION['alert_message'])); ?>
            </div>
            <?php 
            unset($_SESSION['alert_type']);
            unset($_SESSION['alert_message']);
            ?>
        <?php endif; ?>

        <!-- Registration Stats Header -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="btn-toolbar justify-content-between">
                    <div class="btn-group">
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Status:</strong> 
                            <span class="badge badge-primary ml-2">IPD Admission</span>
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
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Available Beds:</strong> 
                            <span class="badge badge-info ml-2"><?php echo $available_beds; ?></span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Emergency:</strong> 
                            <span class="badge badge-danger ml-2"><?php echo $emergency_admissions; ?></span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Elective:</strong> 
                            <span class="badge badge-success ml-2"><?php echo $elective_admissions; ?></span>
                        </span>
                    </div>
                    <div class="btn-group">
                        <a href="ipd.php" class="btn btn-secondary">
                            <i class="fas fa-times mr-2"></i>Cancel
                        </a>
                        <button type="submit" form="ipdForm" class="btn btn-primary">
                            <i class="fas fa-save mr-2"></i>Register IPD
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <form method="post" id="ipdForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="admission_number" id="admissionNumberField" value="<?php echo generateIPDAdmissionNumber($mysqli); ?>">

            <div class="row">
                <!-- Left Column: Visit Selection & Admission Information -->
                <div class="col-md-8">
                    <!-- Visit Selection Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-hospital mr-2"></i>Select IPD Visit</h4>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label class="required">Select Active IPD Visit</label>
                                <select class="form-control select2" name="visit_id" id="visit_id" 
                                        data-placeholder="Select IPD visit" required>
                                    <option value=""></option>
                                    <?php foreach ($active_ipd_visits as $visit): 
                                        $patient_name = htmlspecialchars($visit['last_name'] . ', ' . $visit['first_name']);
                                        $visit_date = date('M j, Y', strtotime($visit['visit_datetime']));
                                        $age = '';
                                        if (!empty($visit['date_of_birth'])) {
                                            $birthDate = new DateTime($visit['date_of_birth']);
                                            $today = new DateTime();
                                            $age = ' (' . $today->diff($birthDate)->y . ' yrs)';
                                        }
                                    ?>
                                        <option value="<?php echo $visit['visit_id']; ?>"
                                                data-patient-id="<?php echo $visit['patient_id']; ?>"
                                                data-patient-name="<?php echo $patient_name; ?>"
                                                data-patient-mrn="<?php echo htmlspecialchars($visit['patient_mrn']); ?>"
                                                data-visit-date="<?php echo $visit_date; ?>"
                                                data-patient-age="<?php echo $age; ?>"
                                                data-patient-gender="<?php echo htmlspecialchars($visit['sex']); ?>"
                                                data-patient-phone="<?php echo htmlspecialchars($visit['phone_primary']); ?>"
                                                data-department-id="<?php echo $visit['department_id']; ?>"
                                                data-department-name="<?php echo htmlspecialchars($visit['department_name']); ?>"
                                                data-attending-provider-id="<?php echo $visit['attending_provider_id']; ?>"
                                                data-doctor-name="<?php echo htmlspecialchars($visit['doctor_name']); ?>">
                                            <?php echo $visit['visit_number'] . ' - ' . $patient_name . $age . ' (MRN: ' . $visit['patient_mrn'] . ') - ' . $visit_date; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="form-text text-muted">Select an active IPD visit to admit. Only visits with status ACTIVE are shown.</small>
                                <?php if (empty($active_ipd_visits)): ?>
                                    <div class="alert alert-warning mt-2">
                                        <i class="fas fa-exclamation-triangle mr-2"></i>
                                        No active IPD visits found. Please create an IPD visit first.
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Patient Info Display -->
                            <div id="patientInfoCard" class="card mt-3" style="display: none;">
                                <div class="card-body p-3">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <h6 id="patientNameDisplay" class="font-weight-bold mb-1"></h6>
                                            <small class="text-muted" id="patientMRNDisplay"></small><br>
                                            <small class="text-muted" id="patientGenderAgeDisplay"></small>
                                        </div>
                                        <div class="col-md-6">
                                            <small class="text-muted">Visit Date:</small>
                                            <div id="visitDateDisplay" class="font-weight-bold"></div>
                                            <small class="text-muted">Phone:</small>
                                            <div id="patientPhoneDisplay" class="font-weight-bold"></div>
                                        </div>
                                    </div>
                                </div>
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
                                               value="<?php echo date('Y-m-d\TH:i'); ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label class="required">Admission Type</label>
                                        <select class="form-control select2" name="admission_type" id="admission_type" required>
                                            <?php foreach ($admission_types as $key => $label): ?>
                                                <option value="<?php echo $key; ?>" <?php echo $key == 'ELECTIVE' ? 'selected' : ''; ?>>
                                                    <?php echo $label; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label class="required">Department</label>
                                        <select class="form-control select2" name="department_id" id="department_id" required>
                                            <option value=""></option>
                                            <?php foreach ($departments as $dept): ?>
                                                <option value="<?php echo $dept['department_id']; ?>">
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
                                                data-placeholder="Select admitting doctor" required>
                                            <option value=""></option>
                                            <?php foreach ($doctors as $doctor): ?>
                                                <option value="<?php echo $doctor['user_id']; ?>">
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
                                                data-placeholder="Select attending doctor">
                                            <option value=""></option>
                                            <?php foreach ($doctors as $doctor): ?>
                                                <option value="<?php echo $doctor['user_id']; ?>">
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
                                                data-placeholder="Select nurse incharge">
                                            <option value=""></option>
                                            <?php foreach ($nurses as $nurse): ?>
                                                <option value="<?php echo $nurse['user_id']; ?>">
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
                                               placeholder="Name of referring facility/person" maxlength="150">
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
                                        <select class="form-control select2" name="ward_id" id="ward_id" required>
                                            <option value=""></option>
                                            <?php foreach ($wards as $ward): ?>
                                                <option value="<?php echo $ward['ward_id']; ?>">
                                                    <?php echo htmlspecialchars($ward['ward_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="required">Bed</label>
                                        <select class="form-control" name="bed_id" id="bed_id" disabled required>
                                            <option value="">- Select Ward First -</option>
                                        </select>
                                        <small class="form-text text-muted" id="bedFeeInfo" style="display: none;">
                                            <span id="bedRateDisplay" class="font-weight-bold">KES 0.00</span> per day
                                        </small>
                                    </div>
                                </div>
                            </div>
                            <div class="alert alert-info mt-3">
                                <i class="fas fa-info-circle mr-2"></i>
                                <strong>Important:</strong> Bed will be marked as <strong>Occupied</strong> immediately upon admission.
                            </div>
                        </div>
                    </div>

            
                </div>

                <!-- Right Column: Actions & Preview -->
                <div class="col-md-4">
                    <!-- Registration Actions Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-bolt mr-2"></i>Registration Actions</h4>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <?php if (SimplePermission::any("visit_create")) { ?>
                                <button type="submit" class="btn btn-primary btn-lg" id="submitBtn" disabled>
                                    <i class="fas fa-procedures mr-2"></i>Register IPD
                                </button>
                                <?php } ?>
                                <button type="reset" class="btn btn-outline-secondary" id="resetBtn">
                                    <i class="fas fa-redo mr-2"></i>Reset Form
                                </button>
                                <a href="ipd.php" class="btn btn-outline-danger">
                                    <i class="fas fa-times mr-2"></i>Cancel
                                </a>
                            </div>
                            <hr>
                            <div class="small">
                                <p class="mb-2"><strong>Keyboard Shortcuts:</strong></p>
                                <div class="row">
                                    <div class="col-6">
                                        <span class="badge badge-light">Ctrl + V</span> Visit Search
                                    </div>
                                    <div class="col-6">
                                        <span class="badge badge-light">Ctrl + W</span> Ward Select
                                    </div>
                                </div>
                                <div class="row mt-1">
                                    <div class="col-6">
                                        <span class="badge badge-light">Ctrl + S</span> Save
                                    </div>
                                    <div class="col-6">
                                        <span class="badge badge-light">Ctrl + R</span> Reset
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- IPD Admission Preview Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-eye mr-2"></i>IPD Admission Preview</h4>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-3">
                                <div class="preview-icon mb-2">
                                    <i class="fas fa-procedures fa-2x text-primary"></i>
                                </div>
                                <h5 id="preview_admission_type">IPD Admission</h5>
                                <div id="preview_admission_number" class="text-muted small">
                                    <?php echo generateIPDAdmissionNumber($mysqli); ?>
                                </div>
                            </div>
                            <hr>
                            <div class="small">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Patient:</span>
                                    <span id="preview_patient" class="font-weight-bold text-primary">-</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Visit Number:</span>
                                    <span id="preview_visit_number" class="font-weight-bold text-primary">-</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Admission Time:</span>
                                    <span id="preview_datetime" class="font-weight-bold text-primary"><?php echo date('M j, Y H:i'); ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Admission Type:</span>
                                    <span id="preview_admission_type_text" class="font-weight-bold text-primary">Elective</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Ward/Bed:</span>
                                    <span id="preview_ward_bed" class="font-weight-bold text-primary">-</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Admitting Dr:</span>
                                    <span id="preview_admitting_provider" class="font-weight-bold text-primary">-</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Attending Dr:</span>
                                    <span id="preview_attending_provider" class="font-weight-bold text-primary">-</span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Status:</span>
                                    <span id="preview_status" class="font-weight-bold text-success">Active</span>
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
                                        <i class="fas fa-exclamation-triangle fa-lg text-danger mb-1"></i>
                                        <h5 class="mb-0"><?php echo $emergency_admissions; ?></h5>
                                        <small class="text-muted">Emergency</small>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="stat-box bg-success-light p-2 rounded">
                                        <i class="fas fa-bed fa-lg text-success mb-1"></i>
                                        <h5 class="mb-0"><?php echo $available_beds; ?></h5>
                                        <small class="text-muted">Available Beds</small>
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
                                    Select active IPD visit first
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-success mr-2"></i>
                                    Admission number format: IPD-YYYY-NNN
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-success mr-2"></i>
                                    Ward and bed are required for IPD
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-success mr-2"></i>
                                    Bed will be marked as Occupied immediately
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-success mr-2"></i>
                                    Admission status defaults to "ACTIVE"
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
                                        Admission Number: <span class="font-weight-bold"><?php echo generateIPDAdmissionNumber($mysqli); ?></span>
                                    </small>
                                </div>
                                <div>
                                    <a href="ipd.php" class="btn btn-secondary btn-sm">
                                        <i class="fas fa-times mr-1"></i>Cancel
                                    </a>
                                    <button type="reset" class="btn btn-outline-secondary btn-sm">
                                        <i class="fas fa-redo mr-1"></i>Reset
                                    </button>
                                    <?php if (SimplePermission::any("visit_create")) { ?>
                                    <button type="submit" class="btn btn-primary" id="submitBtnFooter" disabled>
                                        <i class="fas fa-save mr-1"></i>Register IPD
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
    var visitsData = <?php echo json_encode($active_ipd_visits); ?>;
    
    // Function to update submit button state
    function updateSubmitButtonState() {
        var visitSelected = $('#visit_id').val();
        var bedSelected = $('#bed_id').val() && $('#bed_id').val() !== '- Select Bed -';
        var submitBtn = $('#submitBtn');
        var submitBtnFooter = $('#submitBtnFooter');
        
        if (!visitSelected) {
            submitBtn.prop('disabled', true).html('<i class="fas fa-procedures mr-2"></i>Select Visit First');
            submitBtnFooter.prop('disabled', true);
        } else {
            submitBtn.prop('disabled', false).html('<i class="fas fa-procedures mr-2"></i>Register IPD');
            submitBtnFooter.prop('disabled', false);
        }
    }

    // When visit selection changes, update patient info and form fields
    $('#visit_id').change(function() {
        var visitId = $(this).val();
        var selectedOption = $(this).find('option:selected');
        var patientInfoCard = $('#patientInfoCard');
        
        if (visitId) {
            // Show patient info
            var patientName = selectedOption.data('patient-name');
            var patientMRN = selectedOption.data('patient-mrn');
            var visitDate = selectedOption.data('visit-date');
            var patientAge = selectedOption.data('patient-age');
            var patientGender = selectedOption.data('patient-gender');
            var patientPhone = selectedOption.data('patient-phone');
            var departmentId = selectedOption.data('department-id');
            var departmentName = selectedOption.data('department-name');
            var attendingProviderId = selectedOption.data('attending-provider-id');
            var doctorName = selectedOption.data('doctor-name');
            
            // Update patient info display
            $('#patientNameDisplay').text(patientName);
            $('#patientMRNDisplay').text('MRN: ' + patientMRN);
            $('#patientGenderAgeDisplay').text(patientGender + patientAge);
            $('#visitDateDisplay').text(visitDate);
            $('#patientPhoneDisplay').text(patientPhone);
            patientInfoCard.show();
            
            // Update preview
            $('#preview_patient').text(patientName);
            $('#preview_visit_number').text(selectedOption.text().split(' - ')[0]);
            
            // Update form fields
            if (departmentId) {
                $('#department_id').val(departmentId).trigger('change');
            }
            
            if (attendingProviderId) {
                $('#attending_provider_id').val(attendingProviderId).trigger('change');
            }
            
            // Update admission date to visit date
            var visitDateTime = new Date('<?php echo date("Y-m-d"); ?>T<?php echo date("H:i"); ?>');
            $('#admission_date').val(visitDateTime.toISOString().slice(0, 16));
            $('#preview_datetime').text(visitDateTime.toLocaleString());
            
        } else {
            patientInfoCard.hide();
            $('#preview_patient').text('-');
            $('#preview_visit_number').text('-');
        }
        
        updateSubmitButtonState();
    });

    // When ward selection changes, load beds
    $('#ward_id').change(function() {
        var wardId = $(this).val();
        var bedsSelect = $('#bed_id');
        
        bedsSelect.empty();
        
        if (wardId && bedsData[wardId]) {
            var beds = bedsData[wardId];
            
            bedsSelect.append('<option value="">- Select Bed -</option>');
            
            $.each(beds, function(index, bed) {
                // Check bed_occupied field
                var isOccupied = bed.bed_occupied == 1;
                var bedText = bed.bed_number;
                var bedClass = '';
                
                if (isOccupied) {
                    bedText += ' (Occupied)';
                    bedClass = 'text-danger';
                } else {
                    bedText += ' (Available)';
                    bedClass = 'text-success';
                }
                
                if (bed.bed_type) {
                    bedText += ' - ' + bed.bed_type;
                }
                
                bedsSelect.append($('<option>', {
                    value: bed.bed_id,
                    text: bedText,
                    class: bedClass,
                    disabled: isOccupied
                }));
            });
            
            bedsSelect.prop('disabled', false);
            
            // Update preview
            updateWardBedPreview();
        } else {
            bedsSelect.append('<option value="">- No beds available -</option>').prop('disabled', true);
            $('#preview_ward_bed').text('-');
        }
    });

    // When bed selection changes, update preview
    $('#bed_id').change(function() {
        updateWardBedPreview();
    });

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
        $('#preview_admission_type_text').text(selectedText || 'Elective');
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
            $('#preview_datetime').text(date.toLocaleString());
        }
    });

 

    // Form validation
    $('#ipdForm').on('submit', function(e) {
        var isValid = true;
        
        // Clear previous validation errors
        $('.is-invalid').removeClass('is-invalid');
        $('.select2-selection').removeClass('is-invalid');
        
        // Validate visit selection
        if (!$('#visit_id').val()) {
            isValid = false;
            $('#visit_id').addClass('is-invalid');
            $('#visit_id').next('.select2-container').find('.select2-selection').addClass('is-invalid');
        }
        
        // Validate required fields
        if (!$('#admission_date').val()) {
            isValid = false;
            $('#admission_date').addClass('is-invalid');
        }
        
        if (!$('#admission_type').val()) {
            isValid = false;
            $('#admission_type').addClass('is-invalid');
        }
        
        if (!$('#department_id').val()) {
            isValid = false;
            $('#department_id').addClass('is-invalid');
            $('#department_id').next('.select2-container').find('.select2-selection').addClass('is-invalid');
        }
        
        if (!$('#admitting_provider_id').val()) {
            isValid = false;
            $('#admitting_provider_id').addClass('is-invalid');
            $('#admitting_provider_id').next('.select2-container').find('.select2-selection').addClass('is-invalid');
        }

        // Validate ward and bed
        if (!$('#ward_id').val()) {
            isValid = false;
            $('#ward_id').addClass('is-invalid');
            $('#ward_id').next('.select2-container').find('.select2-selection').addClass('is-invalid');
        }
        
        if (!$('#bed_id').val() || $('#bed_id').val() === '- Select Bed -') {
            isValid = false;
            $('#bed_id').addClass('is-invalid');
        } else {
            // Check if bed is occupied
            var selectedOption = $('#bed_id option:selected');
            var isOccupied = selectedOption.text().includes('(Occupied)');
            
            if (isOccupied) {
                isValid = false;
                $('#bed_id').addClass('is-invalid');
                alert('Selected bed is already occupied. Please choose another bed.');
            }
        }

        if (!isValid) {
            e.preventDefault();
            
            // Show error message
            if (!$('#formErrorAlert').length) {
                $('#ipdForm').prepend(
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
        $('#submitBtn').html('<i class="fas fa-spinner fa-spin mr-2"></i>Registering...').prop('disabled', true);
        $('#submitBtnFooter').html('<i class="fas fa-spinner fa-spin mr-2"></i>Registering...').prop('disabled', true);
        $('#resetBtn').prop('disabled', true);
    });

    // Keyboard shortcuts
    $(document).keydown(function(e) {
        // Ctrl + V to focus on visit search
        if (e.ctrlKey && e.keyCode === 86) {
            e.preventDefault();
            $('#visit_id').select2('open');
        }
        // Ctrl + W to focus on ward selection
        if (e.ctrlKey && e.keyCode === 87) {
            e.preventDefault();
            $('#ward_id').select2('open');
        }
        // Ctrl + S to submit form
        if (e.ctrlKey && e.keyCode === 83) {
            e.preventDefault();
            $('#ipdForm').submit();
        }
        // Ctrl + R to reset form
        if (e.ctrlKey && e.keyCode === 82) {
            e.preventDefault();
            $('#ipdForm')[0].reset();
            // Reset Select2
            $('.select2').val('').trigger('change');
            // Reset preview
            $('#patientInfoCard').hide();
            $('#preview_patient').text('-');
            $('#preview_visit_number').text('-');
            $('#preview_admitting_provider').text('-');
            $('#preview_attending_provider').text('-');
            $('#preview_admission_type_text').text('Elective');
            $('#preview_datetime').text('<?php echo date("M j, Y H:i"); ?>');
            $('#preview_ward_bed').text('-');
            // Reset bed selection
            $('#bed_id').empty().append('<option value="">- Select Ward First -</option>').prop('disabled', true);
            // Clear validation errors
            $('.is-invalid').removeClass('is-invalid');
            $('.select2-selection').removeClass('is-invalid');
            // Reset submit button
            updateSubmitButtonState();
        }
        // Escape to cancel
        if (e.keyCode === 27) {
            window.location.href = 'ipd.php';
        }
    });

    // Auto-close alerts after 5 seconds
    setTimeout(function() {
        $('.alert').fadeOut('slow');
    }, 5000);

    // Auto-focus on visit search
    <?php if (!empty($active_ipd_visits)): ?>
    $('#visit_id').select2('open');
    <?php endif; ?>
    
    // Initialize submit button state
    updateSubmitButtonState();
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
    background-color: rgba(13, 110, 253, 0.1) !important;
}
.bg-warning-light {
    background-color: rgba(255, 193, 7, 0.1) !important;
}
.bg-danger-light {
    background-color: rgba(220, 53, 69, 0.1) !important;
}
.bg-success-light {
    background-color: rgba(25, 135, 84, 0.1) !important;
}
.card-title {
    font-size: 1.1rem;
    font-weight: 600;
}
.list-group-item {
    border-left: 0;
    border-right: 0;
}
.list-group-item:first-child {
    border-top: 0;
}
.list-group-item:last-child {
    border-bottom: 0;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
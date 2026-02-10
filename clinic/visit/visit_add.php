<?php
// visit_add.php - Register New Visit (All Types: OPD, IPD, Emergency)
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/audit_functions.php';

// Function to generate visit number based on visit type
// Function to generate visit number with fixed prefix VISIT (ignoring visit type)
function generateVisitNumber($mysqli, $visit_type, $facility_code) {
    $year = date('Y');
    $type_prefix = 'VISIT';  

    $prefix = $type_prefix . '-' . $facility_code . '-' . $year . '-';

    // Use a transaction with locking to prevent race conditions
    $mysqli->begin_transaction();

    try {
        // Lock the visits table to prevent concurrent inserts from getting the same number
        $lock_sql = "SELECT MAX(visit_number) AS last_number
                     FROM visits
                     WHERE visit_number LIKE ?
                       AND YEAR(visit_datetime) = ?
                     FOR UPDATE";

        $stmt = $mysqli->prepare($lock_sql);
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

        // Generate the visit number
        $visit_number = $prefix . str_pad($next_number, 3, '0', STR_PAD_LEFT);

        $mysqli->commit();

        return $visit_number;

    } catch (Exception $e) {
        $mysqli->rollback();
        throw new Exception("Error generating visit number: " . $e->getMessage());
    }
}


// Get departments, doctors, patients, and facilities for dropdowns
$departments = [];
$doctors = [];
$patients = [];
$facilities = [];

// Fetch departments
$dept_sql = "SELECT department_id, department_name FROM departments 
            WHERE department_archived_at IS NULL 
            ORDER BY department_name";
$dept_result = $mysqli->query($dept_sql);
while ($row = $dept_result->fetch_assoc()) {
    $departments[] = $row;
}

// Fetch doctors (all users)
$doctor_sql = "SELECT user_id, user_name FROM users ORDER BY user_name";
$doctor_result = $mysqli->query($doctor_sql);
while ($row = $doctor_result->fetch_assoc()) {
    $doctors[] = $row;
}

// Fetch patients for search/selection
$patient_sql = "SELECT patient_id, first_name, last_name, patient_mrn 
               FROM patients 
               WHERE patient_status != 'ARCHIVED' 
               ORDER BY last_name, first_name 
               LIMIT 100";
$patient_result = $mysqli->query($patient_sql);
while ($row = $patient_result->fetch_assoc()) {
    $patients[] = $row;
}

// Fetch active facilities
$facility_sql = "SELECT facility_id, facility_internal_code, facility_name, mfl_code 
                 FROM facilities 
                 WHERE is_active = 1 
                 ORDER BY facility_name";
$facility_result = $mysqli->query($facility_sql);
while ($row = $facility_result->fetch_assoc()) {
    $facilities[] = $row;
}

// Get today's visit stats
$today_sql = "SELECT 
    COUNT(*) as total_visits,
    SUM(CASE WHEN visit_type = 'OPD' THEN 1 ELSE 0 END) as opd_visits,
    SUM(CASE WHEN visit_type = 'EMERGENCY' THEN 1 ELSE 0 END) as emergency_visits,
    SUM(CASE WHEN visit_type = 'IPD' THEN 1 ELSE 0 END) as ipd_visits
    FROM visits 
    WHERE DATE(visit_datetime) = CURDATE()";
$today_result = $mysqli->query($today_sql);
$today_stats = $today_result->fetch_assoc();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    
    // Validate CSRF token
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        header("Location: visit_add.php");
        exit;
    }

    // Get form data
    $patient_id = intval($_POST['patient_id'] ?? 0);
    $facility_code = sanitizeInput($_POST['facility_code'] ?? '');
    $visit_type = sanitizeInput($_POST['visit_type'] ?? '');
    $department_id = intval($_POST['department_id'] ?? 0);
    $visit_datetime = sanitizeInput($_POST['visit_datetime'] ?? '');
    $attending_provider_id = intval($_POST['attending_provider_id'] ?? 0);
    
    // Validate required fields
    if ($patient_id <= 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please select a patient.";
        header("Location: visit_add.php");
        exit;
    }

    if (empty($facility_code)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please select a facility.";
        header("Location: visit_add.php");
        exit;
    }

    if (empty($visit_type)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please select a visit type.";
        header("Location: visit_add.php");
        exit;
    }

    if (empty($visit_datetime)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please enter visit date and time.";
        header("Location: visit_add.php");
        exit;
    }

    if ($attending_provider_id <= 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please select an attending provider.";
        header("Location: visit_add.php");
        exit;
    }

    // Check if patient has active visit of same type today
    $check_sql = "SELECT visit_id, visit_number 
                  FROM visits 
                  WHERE patient_id = ? 
                  AND DATE(visit_datetime) = DATE(?)
                  AND visit_type = ?
                  AND visit_status = 'ACTIVE'";
    $check_stmt = $mysqli->prepare($check_sql);
    $check_stmt->bind_param("iss", $patient_id, $visit_datetime, $visit_type);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        $active_visit = $check_result->fetch_assoc();
        $_SESSION['alert_type'] = "warning";
        $_SESSION['alert_message'] = "Patient already has an active " . $visit_type . " visit today!<br>Visit #" . $active_visit['visit_number'];
        header("Location: visit_add.php");
        exit;
    }
    $check_stmt->close();

    // Generate visit number with transaction
    try {
        $visit_number = generateVisitNumber($mysqli, $visit_type, $facility_code);
    } catch (Exception $e) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error generating visit number: " . $e->getMessage();
        header("Location: visit_add.php");
        exit;
    }

    // Start transaction for the insert
    $mysqli->begin_transaction();
    
    try {
        // Double-check the visit number doesn't already exist (extra safety)
        $double_check_sql = "SELECT COUNT(*) as count FROM visits WHERE visit_number = ?";
        $double_check_stmt = $mysqli->prepare($double_check_sql);
        $double_check_stmt->bind_param("s", $visit_number);
        $double_check_stmt->execute();
        $double_check_result = $double_check_stmt->get_result();
        $double_check_row = $double_check_result->fetch_assoc();
        
        if ($double_check_row['count'] > 0) {
            // Regenerate if somehow duplicate exists
            $mysqli->rollback();
            $visit_number = generateVisitNumber($mysqli, $visit_type, $facility_code);
            $mysqli->begin_transaction();
        }
        
        // Insert into visits table
        $sql = "INSERT INTO visits (
                visit_number,
                patient_id,
                facility_code,
                visit_type,
                department_id,
                visit_datetime,
                attending_provider_id,
                created_by,
                visit_status,
                closed_at,
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'ACTIVE', NULL, NOW())";
        
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $mysqli->error);
        }
        
        $department_id = $department_id <= 0 ? NULL : $department_id;
        
        $stmt->bind_param(
            "sissisii",
            $visit_number,
            $patient_id,
            $facility_code,
            $visit_type,
            $department_id,
            $visit_datetime,
            $attending_provider_id,
            $session_user_id
        );

        if (!$stmt->execute()) {
            throw new Exception("Error creating visit: " . $mysqli->error);
        }
        
        $visit_id = $stmt->insert_id;
        
        // Commit the transaction
        $mysqli->commit();
        
        // BUILD NEW DATA for audit log
        $new_data = [
            'visit_number'          => $visit_number,
            'patient_id'            => $patient_id,
            'facility_code'         => $facility_code,
            'visit_type'            => $visit_type,
            'department_id'         => $department_id,
            'visit_datetime'        => $visit_datetime,
            'attending_provider_id' => $attending_provider_id,
            'visit_status'          => 'ACTIVE'
        ];
        
        // AUDIT LOG: Log visit creation
        audit_log($mysqli, [
            'user_id'     => $session_user_id,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'CREATE',
            'module'      => 'Visits',
            'table_name'  => 'visits',
            'entity_type' => 'visit',
            'record_id'   => $visit_id,
            'patient_id'  => $patient_id,
            'visit_id'    => $visit_id,
            'description' => "Created new " . $visit_type . " visit: " . $visit_number,
            'status'      => 'SUCCESS',
            'old_values'  => null, // No old values for creation
            'new_values'  => $new_data
        ]);
        
        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Visit registered successfully!<br>Visit Number: " . $visit_number;
        
        header("Location: visit_details.php?visit_id=" . $visit_id);
        exit;
        
    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = $e->getMessage();
        header("Location: visit_add.php");
        exit;
    }
}
?>
<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-hospital mr-2"></i>Register New Visit
        </h3>
        <div class="card-tools">
            <div class="btn-group">
                <a href="visits.php" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to All Visits
                </a>
                <a href="patient_add.php" class="btn btn-light ml-2">
                    <i class="fas fa-user-plus mr-2"></i>Register New Patient
                </a>
            </div>
        </div>
    </div>

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
                            <span class="badge badge-info ml-2">New Visit Registration</span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Today:</strong> 
                            <span class="badge badge-success ml-2"><?php echo date('M j, Y'); ?></span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Total Today:</strong> 
                            <span class="badge badge-primary ml-2"><?php echo $today_stats['total_visits'] ?? 0; ?></span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>OPD Today:</strong> 
                            <span class="badge badge-warning ml-2"><?php echo $today_stats['opd_visits'] ?? 0; ?></span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Emergency Today:</strong> 
                            <span class="badge badge-danger ml-2"><?php echo $today_stats['emergency_visits'] ?? 0; ?></span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>IPD Today:</strong> 
                            <span class="badge badge-success ml-2"><?php echo $today_stats['ipd_visits'] ?? 0; ?></span>
                        </span>
                    </div>
                    <div class="btn-group">
                        <a href="visits.php" class="btn btn-secondary">
                            <i class="fas fa-times mr-2"></i>Cancel
                        </a>
                        <button type="submit" form="visitForm" class="btn btn-primary">
                            <i class="fas fa-save mr-2"></i>Register Visit
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <form method="post" id="visitForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

            <div class="row">
                <!-- Left Column: Patient & Visit Information -->
                <div class="col-md-8">
                    <!-- Patient Selection Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-user mr-2"></i>Patient Information</h4>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label class="required">Select Patient</label>
                                <select class="form-control select2" name="patient_id" id="patient_id" 
                                        data-placeholder="Search patient by name or MRN" required>
                                    <option value=""></option>
                                    <?php foreach ($patients as $patient): 
                                        $full_name = htmlspecialchars($patient['last_name'] . ', ' . $patient['first_name']);
                                        $mrn = htmlspecialchars($patient['patient_mrn']);
                                    ?>
                                        <option value="<?php echo $patient['patient_id']; ?>">
                                            <?php echo $full_name; ?> (MRN: <?php echo $mrn; ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="form-text text-muted">Search patients by name or MRN. <a href="patient_add.php" class="text-primary">Register new patient</a></small>
                                <div class="alert alert-warning mt-2" id="activeVisitWarning" style="display: none;">
                                    <i class="fas fa-exclamation-triangle mr-2"></i>
                                    <span id="activeVisitMessage"></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Visit Information Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-calendar-alt mr-2"></i>Visit Information</h4>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="required">Visit Type</label>
                                        <select class="form-control select2" name="visit_type" id="visit_type" required>
                                            <option value="">- Select Visit Type -</option>
                                            <option value="OPD">OPD (Outpatient)</option>
                                            <option value="EMERGENCY">Emergency</option>
                                            <option value="IPD">IPD (Inpatient Admission)</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="required">Facility</label>
                                        <select class="form-control select2" name="facility_code" id="facility_code" required>
                                            <option value="">- Select Facility -</option>
                                            <?php foreach ($facilities as $facility): ?>
                                                <option value="<?php echo htmlspecialchars($facility['facility_internal_code']); ?>">
                                                    <?php echo htmlspecialchars($facility['facility_name']); ?> 
                                                    (<?php echo htmlspecialchars($facility['facility_internal_code']); ?>)
                                                    <?php if (!empty($facility['mfl_code'])): ?>
                                                        - MFL: <?php echo htmlspecialchars($facility['mfl_code']); ?>
                                                    <?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="required">Visit Date & Time</label>
                                        <input type="datetime-local" class="form-control" name="visit_datetime" id="visit_datetime" 
                                               value="<?php echo date('Y-m-d\TH:i'); ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Department</label>
                                        <select class="form-control select2" name="department_id" id="department_id" 
                                                data-placeholder="Select department">
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
                                <div class="col-md-12">
                                    <div class="form-group">
                                        <label class="required">Attending Provider</label>
                                        <select class="form-control select2" name="attending_provider_id" id="attending_provider_id" 
                                                data-placeholder="Select doctor/clinical officer" required>
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
                                <button type="submit" class="btn btn-primary btn-lg" id="submitBtn">
                                    <i class="fas fa-hospital mr-2"></i>Register Visit
                                </button>
                                <?php } ?>
                                <button type="reset" class="btn btn-outline-secondary" id="resetBtn">
                                    <i class="fas fa-redo mr-2"></i>Reset Form
                                </button>
                                <a href="visits.php" class="btn btn-outline-danger">
                                    <i class="fas fa-times mr-2"></i>Cancel
                                </a>
                            </div>
                            <hr>
                            <div class="small">
                                <p class="mb-2"><strong>Keyboard Shortcuts:</strong></p>
                                <div class="row">
                                    <div class="col-6">
                                        <span class="badge badge-light">Ctrl + P</span> Patient Search
                                    </div>
                                    <div class="col-6">
                                        <span class="badge badge-light">Ctrl + T</span> Visit Type
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

                    <!-- Visit Preview Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-eye mr-2"></i>Visit Preview</h4>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-3">
                                <div class="preview-icon mb-2">
                                    <i class="fas fa-hospital fa-2x text-info"></i>
                                </div>
                                <h5 id="preview_visit_type">New Visit</h5>
                                <div id="preview_visit_number" class="text-muted small">
                                    Select visit type to generate number
                                </div>
                            </div>
                            <hr>
                            <div class="small">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Patient:</span>
                                    <span id="preview_patient" class="font-weight-bold text-primary">-</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Date & Time:</span>
                                    <span id="preview_datetime" class="font-weight-bold text-primary"><?php echo date('M j, Y H:i'); ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Visit Type:</span>
                                    <span id="preview_visit_type_text" class="font-weight-bold text-primary">-</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Facility:</span>
                                    <span id="preview_facility" class="font-weight-bold text-primary">-</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Department:</span>
                                    <span id="preview_department" class="font-weight-bold text-primary">-</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Provider:</span>
                                    <span id="preview_provider" class="font-weight-bold text-primary">-</span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Status:</span>
                                    <span id="preview_status" class="font-weight-bold text-success">Active</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Today's Statistics Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-chart-bar mr-2"></i>Today's Statistics</h4>
                        </div>
                        <div class="card-body">
                            <div class="row text-center">
                                <div class="col-6">
                                    <div class="stat-box bg-primary-light p-2 rounded mb-2">
                                        <i class="fas fa-hospital fa-lg text-primary mb-1"></i>
                                        <h5 class="mb-0"><?php echo $today_stats['total_visits'] ?? 0; ?></h5>
                                        <small class="text-muted">Total Visits</small>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="stat-box bg-warning-light p-2 rounded mb-2">
                                        <i class="fas fa-stethoscope fa-lg text-warning mb-1"></i>
                                        <h5 class="mb-0"><?php echo $today_stats['opd_visits'] ?? 0; ?></h5>
                                        <small class="text-muted">OPD Visits</small>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="stat-box bg-danger-light p-2 rounded">
                                        <i class="fas fa-ambulance fa-lg text-danger mb-1"></i>
                                        <h5 class="mb-0"><?php echo $today_stats['emergency_visits'] ?? 0; ?></h5>
                                        <small class="text-muted">Emergency</small>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="stat-box bg-success-light p-2 rounded">
                                        <i class="fas fa-procedures fa-lg text-success mb-1"></i>
                                        <h5 class="mb-0"><?php echo $today_stats['ipd_visits'] ?? 0; ?></h5>
                                        <small class="text-muted">IPD Admissions</small>
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
                                    Patient cannot have multiple visits of same type on same day
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-success mr-2"></i>
                                    Visit number format: TYPE-FACILITY-YYYY-NNN
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-success mr-2"></i>
                                    Attending provider is required
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-success mr-2"></i>
                                    Facility selection is required
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-success mr-2"></i>
                                    Department is optional
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-success mr-2"></i>
                                    Visit status defaults to "ACTIVE"
                                </li>
                                <li>
                                    <i class="fas fa-check-circle text-success mr-2"></i>
                                    No billing information is collected in this form
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
                                        <span id="footerVisitNumber">Select visit type to generate visit number</span>
                                    </small>
                                </div>
                                <div>
                                    <a href="visits.php" class="btn btn-secondary btn-sm">
                                        <i class="fas fa-times mr-1"></i>Cancel
                                    </a>
                                    <button type="reset" class="btn btn-outline-secondary btn-sm">
                                        <i class="fas fa-redo mr-1"></i>Reset
                                    </button>
                                    <?php if (SimplePermission::any("visit_create")) { ?>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save mr-1"></i>Register Visit
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

    // Function to check for active visits today
    function checkActiveVisit(patientId, visitType, visitDate) {
        if (!patientId || !visitType || !visitDate) return;
        
        // Format date for API
        var dateObj = new Date(visitDate);
        var formattedDate = dateObj.toISOString().split('T')[0];
        
        $.ajax({
            url: 'ajax_check_visit.php',
            type: 'POST',
            data: { 
                patient_id: patientId,
                visit_type: visitType,
                visit_date: formattedDate
            },
            dataType: 'json',
            success: function(response) {
                if (response.has_active_visit) {
                    var warningHtml = '<i class="fas fa-exclamation-triangle mr-2"></i>' +
                                     '<strong>Patient has ' + visitType + ' visit today!</strong><br>' +
                                     'Visit #' + response.visit_number;
                    
                    $('#activeVisitWarning').html(warningHtml).show();
                    $('#submitBtn').prop('disabled', true).html('<i class="fas fa-ban mr-2"></i>Patient Has Active Visit');
                } else {
                    $('#activeVisitWarning').hide();
                    $('#submitBtn').prop('disabled', false).html('<i class="fas fa-hospital mr-2"></i>Register Visit');
                }
            },
            error: function() {
                console.log('Error checking visits');
            }
        });
    }

    // Update preview when patient is selected
    $('#patient_id').change(function() {
        var patientId = $(this).val();
        var selectedText = $(this).find('option:selected').text();
        $('#preview_patient').text(selectedText || '-');
        
        // Check active visit if visit type and date are selected
        var visitType = $('#visit_type').val();
        var visitDate = $('#visit_datetime').val();
        if (patientId && visitType && visitDate) {
            checkActiveVisit(patientId, visitType, visitDate);
        }
    });

    // Update preview when visit type changes
    $('#visit_type').change(function() {
        var selectedText = $(this).find('option:selected').text();
        var visitType = $(this).val();
        $('#preview_visit_type_text').text(selectedText || '-');
        
        // Update the main preview header
        if (visitType) {
            $('#preview_visit_type').text(selectedText + ' Registration');
        } else {
            $('#preview_visit_type').text('New Visit');
        }
        
        // Update visit number preview
        var facilityCode = $('#facility_code').val();
        if (visitType && facilityCode) {
            generateVisitNumberPreview(visitType, facilityCode);
        }
        
        // Check active visit if patient and date are selected
        var patientId = $('#patient_id').val();
        var visitDate = $('#visit_datetime').val();
        if (patientId && visitType && visitDate) {
            checkActiveVisit(patientId, visitType, visitDate);
        }
    });

    // Update preview when facility changes
    $('#facility_code').change(function() {
        var selectedText = $(this).find('option:selected').text();
        var facilityCode = $(this).val();
        $('#preview_facility').text(selectedText || '-');
        
        // Update visit number preview
        var visitType = $('#visit_type').val();
        if (visitType && facilityCode) {
            generateVisitNumberPreview(visitType, facilityCode);
        }
    });

    // Function to generate visit number preview
    function generateVisitNumberPreview(visitType, facilityCode) {
        // Get current year
        var year = new Date().getFullYear();
        
        // Map visit type to prefix
        var typePrefix = '';
        switch(visitType) {
            case 'OPD':
                typePrefix = 'OPD';
                break;
            case 'IPD':
                typePrefix = 'IPD';
                break;
            case 'EMERGENCY':
                typePrefix = 'EMER';
                break;
            default:
                typePrefix = 'VISIT';
        }
        
        // Generate preview number (showing format only)
        var previewNumber = typePrefix + '-' + facilityCode + '-' + year + '-XXX';
        $('#preview_visit_number').text(previewNumber);
        $('#footerVisitNumber').text('Visit Number Format: ' + previewNumber);
    }

    // Update preview when date/time changes
    $('#visit_datetime').change(function() {
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
            
            // Check active visit if patient and type are selected
            var patientId = $('#patient_id').val();
            var visitType = $('#visit_type').val();
            if (patientId && visitType && $(this).val()) {
                checkActiveVisit(patientId, visitType, $(this).val());
            }
        }
    });

    // Update preview when department changes
    $('#department_id').change(function() {
        var selectedText = $(this).find('option:selected').text();
        $('#preview_department').text(selectedText || 'Not specified');
    });

    // Update preview when provider changes
    $('#attending_provider_id').change(function() {
        var selectedText = $(this).find('option:selected').text();
        $('#preview_provider').text(selectedText || '-');
    });

    // Form validation
    $('#visitForm').on('submit', function(e) {
        var isValid = true;
        
        // Clear previous validation errors
        $('.is-invalid').removeClass('is-invalid');
        $('.select2-selection').removeClass('is-invalid');
        
        // Validate required fields
        var requiredFields = [
            { id: 'patient_id', name: 'Patient' },
            { id: 'facility_code', name: 'Facility' },
            { id: 'visit_type', name: 'Visit Type' },
            { id: 'visit_datetime', name: 'Visit Date & Time' },
            { id: 'attending_provider_id', name: 'Attending Provider' }
        ];
        
        requiredFields.forEach(function(field) {
            var $field = $('#' + field.id);
            if (!$field.val()) {
                isValid = false;
                $field.addClass('is-invalid');
                if ($field.hasClass('select2')) {
                    $field.next('.select2-container').find('.select2-selection').addClass('is-invalid');
                }
            }
        });

        if (!isValid) {
            e.preventDefault();
            
            // Show error message
            if (!$('#formErrorAlert').length) {
                $('#visitForm').prepend(
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
        $('#resetBtn').prop('disabled', true);
    });

    // Keyboard shortcuts
    $(document).keydown(function(e) {
        // Ctrl + P to focus on patient search
        if (e.ctrlKey && e.keyCode === 80) {
            e.preventDefault();
            $('#patient_id').select2('open');
        }
        // Ctrl + S to submit form
        if (e.ctrlKey && e.keyCode === 83) {
            e.preventDefault();
            $('#visitForm').submit();
        }
        // Ctrl + R to reset form
        if (e.ctrlKey && e.keyCode === 82) {
            e.preventDefault();
            $('#visitForm')[0].reset();
            // Reset Select2
            $('.select2').val('').trigger('change');
            // Reset preview
            $('#preview_patient').text('-');
            $('#preview_provider').text('-');
            $('#preview_datetime').text('<?php echo date("M j, Y H:i"); ?>');
            $('#preview_visit_type_text').text('-');
            $('#preview_facility').text('-');
            $('#preview_department').text('-');
            $('#preview_visit_type').text('New Visit');
            $('#preview_visit_number').text('Select visit type to generate number');
            $('#footerVisitNumber').text('Select visit type to generate visit number');
            // Clear validation errors
            $('.is-invalid').removeClass('is-invalid');
            $('.select2-selection').removeClass('is-invalid');
            // Reset active visit warning
            $('#activeVisitWarning').hide();
            // Reset submit button
            $('#submitBtn').prop('disabled', false).html('<i class="fas fa-hospital mr-2"></i>Register Visit');
        }
        // Escape to cancel
        if (e.keyCode === 27) {
            window.location.href = 'visits.php';
        }
    });

    // Auto-close alerts after 5 seconds
    setTimeout(function() {
        $('.alert').fadeOut('slow');
    }, 5000);

    // Auto-focus on patient search
    $('#patient_id').select2('open');
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
.card-title {
    font-size: 1.1rem;
    font-weight: 600;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
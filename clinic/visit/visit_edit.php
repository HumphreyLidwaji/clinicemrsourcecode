<?php
// visit_edit.php - Edit Visit Information
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/audit_functions.php';
// Get visit_id from URL
$visit_id = intval($_GET['visit_id'] ?? 0);

if ($visit_id <= 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid visit ID";
    header("Location: visits.php");
    exit;
}

// Get departments, doctors, and facilities for dropdowns
$departments = [];
$doctors = [];
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

// Fetch active facilities
$facility_sql = "SELECT facility_id, facility_internal_code, facility_name, mfl_code 
                 FROM facilities 
                 WHERE is_active = 1 
                 ORDER BY facility_name";
$facility_result = $mysqli->query($facility_sql);
while ($row = $facility_result->fetch_assoc()) {
    $facilities[] = $row;
}

// Get visit data
$visit_sql = "SELECT v.*, 
                     p.first_name, p.last_name, p.patient_mrn
              FROM visits v 
              JOIN patients p ON v.patient_id = p.patient_id
              WHERE v.visit_id = ? AND p.archived_at IS NULL";
$visit_stmt = $mysqli->prepare($visit_sql);
$visit_stmt->bind_param("i", $visit_id);
$visit_stmt->execute();
$visit_result = $visit_stmt->get_result();
$visit = $visit_result->fetch_assoc();

if (!$visit) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Visit not found";
    header("Location: visits.php");
    exit;
}

// Check if visit can be edited (not closed)
$can_edit = ($visit['visit_status'] != 'CLOSED');

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
        header("Location: visit_edit.php?visit_id=" . $visit_id);
        exit;
    }

    // Get form data
    $facility_code = sanitizeInput($_POST['facility_code'] ?? '');
    $visit_type = sanitizeInput($_POST['visit_type'] ?? '');
    $department_id = intval($_POST['department_id'] ?? 0);
    $visit_datetime = sanitizeInput($_POST['visit_datetime'] ?? '');
    $attending_provider_id = intval($_POST['attending_provider_id'] ?? 0);
    
    // Validate required fields
    if (empty($facility_code)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please select a facility.";
        header("Location: visit_edit.php?visit_id=" . $visit_id);
        exit;
    }

    if (empty($visit_type)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please select a visit type.";
        header("Location: visit_edit.php?visit_id=" . $visit_id);
        exit;
    }

    if (empty($visit_datetime)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please enter visit date and time.";
        header("Location: visit_edit.php?visit_id=" . $visit_id);
        exit;
    }

    if ($attending_provider_id <= 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please select an attending provider.";
        header("Location: visit_edit.php?visit_id=" . $visit_id);
        exit;
    }

    // Check if patient has active visit of same type on same day (excluding current visit)
    $check_sql = "SELECT visit_id, visit_number 
                  FROM visits 
                  WHERE patient_id = ? 
                  AND DATE(visit_datetime) = DATE(?)
                  AND visit_type = ?
                  AND visit_status = 'ACTIVE'
                  AND visit_id != ?";
    $check_stmt = $mysqli->prepare($check_sql);
    $check_stmt->bind_param("issi", $visit['patient_id'], $visit_datetime, $visit_type, $visit_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        $active_visit = $check_result->fetch_assoc();
        $_SESSION['alert_type'] = "warning";
        $_SESSION['alert_message'] = "Patient already has an active " . $visit_type . " visit on this date!<br>Visit #" . $active_visit['visit_number'];
        header("Location: visit_edit.php?visit_id=" . $visit_id);
        exit;
    }
    $check_stmt->close();

    // Update visit in database
    $sql = "UPDATE visits SET
            facility_code = ?,
            visit_type = ?,
            department_id = ?,
            visit_datetime = ?,
            attending_provider_id = ?,
            updated_at = NOW()
            WHERE visit_id = ?";
    
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Database error: " . $mysqli->error;
        header("Location: visit_edit.php?visit_id=" . $visit_id);
        exit;
    }
    
    $department_id = $department_id <= 0 ? NULL : $department_id;
    
    $stmt->bind_param(
        "ssisii",
        $facility_code,
        $visit_type,
        $department_id,
        $visit_datetime,
        $attending_provider_id,
        $visit_id
    );

    if ($stmt->execute()) {
        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Visit updated successfully!";
        header("Location: visit_details.php?visit_id=" . $visit_id);
        exit;
    } else {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error updating visit: " . $mysqli->error;
        header("Location: visit_edit.php?visit_id=" . $visit_id);
        exit;
    }
}
?>
<div class="card">
    <div class="card-header bg-warning py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-edit mr-2"></i>Edit Visit: <?php echo htmlspecialchars($visit['visit_number']); ?>
        </h3>
        <div class="card-tools">
            <div class="btn-group">
                <a href="visit_details.php?visit_id=<?php echo $visit_id; ?>" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Visit Details
                </a>
                <?php if ($can_edit): ?>
                <a href="visit_add.php" class="btn btn-light ml-2">
                    <i class="fas fa-plus mr-2"></i>New Visit
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
                            switch($visit['visit_status']) {
                                case 'ACTIVE': $status_color = 'success'; break;
                                case 'CLOSED': $status_color = 'secondary'; break;
                                default: $status_color = 'secondary';
                            }
                            ?>
                            <span class="badge badge-<?php echo $status_color; ?> ml-2">
                                <?php echo htmlspecialchars($visit['visit_status']); ?>
                            </span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Visit #:</strong> 
                            <span class="badge badge-dark ml-2"><?php echo htmlspecialchars($visit['visit_number']); ?></span>
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
                    </div>
                    <div class="btn-group">
                        <a href="visit_details.php?visit_id=<?php echo $visit_id; ?>" class="btn btn-secondary">
                            <i class="fas fa-times mr-2"></i>Cancel
                        </a>
                        <?php if ($can_edit): ?>
                        <button type="submit" form="visitForm" class="btn btn-primary">
                            <i class="fas fa-save mr-2"></i>Update Visit
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!$can_edit): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle mr-2"></i>
                This visit is <?php echo $visit['visit_status']; ?> and cannot be edited. 
                Only ACTIVE visits can be modified.
            </div>
        <?php endif; ?>

        <form method="post" id="visitForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

            <div class="row">
                <!-- Left Column: Visit Information -->
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
                                            <strong><?php echo htmlspecialchars($visit['first_name'] . ' ' . $visit['last_name']); ?></strong>
                                            <br>
                                            <small class="text-muted">MRN: <?php echo htmlspecialchars($visit['patient_mrn']); ?></small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Visit Number</label>
                                        <div class="form-control" style="height: auto; min-height: 38px; background-color: #f8f9fa;">
                                            <strong class="text-primary"><?php echo htmlspecialchars($visit['visit_number']); ?></strong>
                                            <br>
                                            <small class="text-muted">Auto-generated visit identifier</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="text-center mt-3">
                                <a href="patient_details.php?patient_id=<?php echo $visit['patient_id']; ?>" class="btn btn-outline-primary btn-sm">
                                    <i class="fas fa-user mr-2"></i>View Patient Details
                                </a>
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
                                        <select class="form-control select2" name="visit_type" id="visit_type" 
                                                required data-placeholder="Select visit type" <?php echo !$can_edit ? 'disabled' : ''; ?>>
                                            <option value="">- Select Visit Type -</option>
                                            <option value="OPD" <?php echo ($visit['visit_type'] ?? '') == 'OPD' ? 'selected' : ''; ?>>OPD (Outpatient)</option>
                                            <option value="EMERGENCY" <?php echo ($visit['visit_type'] ?? '') == 'EMERGENCY' ? 'selected' : ''; ?>>Emergency</option>
                                            <option value="IPD" <?php echo ($visit['visit_type'] ?? '') == 'IPD' ? 'selected' : ''; ?>>IPD (Inpatient Admission)</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="required">Facility</label>
                                        <select class="form-control select2" name="facility_code" id="facility_code" required <?php echo !$can_edit ? 'disabled' : ''; ?>>
                                            <option value="">- Select Facility -</option>
                                            <?php foreach ($facilities as $facility): ?>
                                                <option value="<?php echo htmlspecialchars($facility['facility_internal_code']); ?>" 
                                                    <?php echo ($visit['facility_code'] ?? '') == $facility['facility_internal_code'] ? 'selected' : ''; ?>>
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
                                               value="<?php echo date('Y-m-d\TH:i', strtotime($visit['visit_datetime'])); ?>" required <?php echo !$can_edit ? 'disabled' : ''; ?>>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Department</label>
                                        <select class="form-control select2" name="department_id" id="department_id" 
                                                data-placeholder="Select department" <?php echo !$can_edit ? 'disabled' : ''; ?>>
                                            <option value=""></option>
                                            <?php foreach ($departments as $dept): ?>
                                                <option value="<?php echo $dept['department_id']; ?>" 
                                                    <?php echo ($visit['department_id'] ?? 0) == $dept['department_id'] ? 'selected' : ''; ?>>
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
                                                data-placeholder="Select doctor/clinical officer" required <?php echo !$can_edit ? 'disabled' : ''; ?>>
                                            <option value=""></option>
                                            <?php foreach ($doctors as $doctor): ?>
                                                <option value="<?php echo $doctor['user_id']; ?>" 
                                                    <?php echo ($visit['attending_provider_id'] ?? 0) == $doctor['user_id'] ? 'selected' : ''; ?>>
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
                    <!-- Edit Actions Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-bolt mr-2"></i>Edit Actions</h4>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <?php if ($can_edit && SimplePermission::any("visit_edit")): ?>
                                <button type="submit" class="btn btn-primary btn-lg" id="submitBtn">
                                    <i class="fas fa-save mr-2"></i>Update Visit
                                </button>
                                <?php endif; ?>
                                
                                <button type="reset" class="btn btn-outline-secondary" <?php echo !$can_edit ? 'disabled' : ''; ?>>
                                    <i class="fas fa-redo mr-2"></i>Reset Changes
                                </button>
                                
                                <a href="visit_details.php?visit_id=<?php echo $visit_id; ?>" class="btn btn-outline-danger">
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
                                        <span class="badge badge-light">Esc</span> Cancel
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
                                    <i class="fas fa-calendar-check fa-2x text-info"></i>
                                </div>
                                <h5 id="preview_visit_type"><?php echo htmlspecialchars($visit['visit_type']); ?></h5>
                                <div id="preview_visit_number" class="text-muted small"><?php echo htmlspecialchars($visit['visit_number']); ?></div>
                            </div>
                            <hr>
                            <div class="small">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Patient:</span>
                                    <span id="preview_patient" class="font-weight-bold text-primary">
                                        <?php echo htmlspecialchars($visit['first_name'] . ' ' . $visit['last_name']); ?>
                                    </span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>MRN:</span>
                                    <span class="font-weight-bold text-primary"><?php echo htmlspecialchars($visit['patient_mrn']); ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Date & Time:</span>
                                    <span id="preview_datetime" class="font-weight-bold text-primary">
                                        <?php echo date('M j, Y H:i', strtotime($visit['visit_datetime'])); ?>
                                    </span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Facility:</span>
                                    <span id="preview_facility" class="font-weight-bold text-primary">
                                        <?php echo htmlspecialchars($visit['facility_code']); ?>
                                    </span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Department:</span>
                                    <span id="preview_department" class="font-weight-bold text-primary">
                                        <?php
                                        $dept_name = '';
                                        foreach ($departments as $dept) {
                                            if ($dept['department_id'] == $visit['department_id']) {
                                                $dept_name = $dept['department_name'];
                                                break;
                                            }
                                        }
                                        echo htmlspecialchars($dept_name ?: 'Not specified');
                                        ?>
                                    </span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Provider:</span>
                                    <span id="preview_provider" class="font-weight-bold text-primary">
                                        <?php
                                        $doctor_name = '';
                                        foreach ($doctors as $doctor) {
                                            if ($doctor['user_id'] == $visit['attending_provider_id']) {
                                                $doctor_name = $doctor['user_name'];
                                                break;
                                            }
                                        }
                                        echo htmlspecialchars($doctor_name ?: 'Not assigned');
                                        ?>
                                    </span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Status:</span>
                                    <span id="preview_status" class="font-weight-bold text-success">
                                        <?php echo htmlspecialchars($visit['visit_status']); ?>
                                    </span>
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
                                    Visit number cannot be changed (auto-generated)
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
                                    <i class="fas fa-exclamation-circle text-danger mr-2"></i>
                                    CLOSED visits cannot be edited
                                </li>
                                <li>
                                    <i class="fas fa-check-circle text-success mr-2"></i>
                                    All changes are logged in the system
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
                                        Visit #<?php echo htmlspecialchars($visit['visit_number']); ?> • 
                                        Last updated: <?php echo !empty($visit['updated_at']) ? date('M j, Y H:i', strtotime($visit['updated_at'])) : 'Never'; ?>
                                    </small>
                                </div>
                                <div>
                                    <a href="visit_details.php?visit_id=<?php echo $visit_id; ?>" class="btn btn-secondary btn-sm">
                                        <i class="fas fa-times mr-1"></i>Cancel
                                    </a>
                                    <button type="reset" class="btn btn-outline-secondary btn-sm" <?php echo !$can_edit ? 'disabled' : ''; ?>>
                                        <i class="fas fa-redo mr-1"></i>Reset
                                    </button>
                                    <?php if ($can_edit && SimplePermission::any("visit_edit")) { ?>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save mr-1"></i>Update Visit
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

    // Update preview when form fields change
    $('#visit_type').change(function() {
        var selectedText = $(this).find('option:selected').text();
        $('#preview_visit_type').text(selectedText || '-');
    });

    $('#facility_code').change(function() {
        var selectedText = $(this).find('option:selected').text();
        // Extract facility code from the option text
        var parts = selectedText.split('(');
        if (parts.length > 1) {
            var facilityCode = parts[1].split(')')[0];
            $('#preview_facility').text(facilityCode.trim());
        } else {
            $('#preview_facility').text('-');
        }
    });

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
        }
    });

    $('#department_id').change(function() {
        var selectedText = $(this).find('option:selected').text();
        $('#preview_department').text(selectedText || 'Not specified');
    });

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
        var requiredFields = ['facility_code', 'visit_type', 'visit_datetime', 'attending_provider_id'];
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
        $('#submitBtn').html('<i class="fas fa-spinner fa-spin mr-2"></i>Updating...').prop('disabled', true);
    });

    // Disable form if visit cannot be edited
    <?php if (!$can_edit): ?>
        $('#visitForm input, #visitForm select, #visitForm textarea, #visitForm button[type="submit"], #visitForm button[type="reset"]').prop('disabled', true);
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
        $('#visitForm').submit();
    }
    // Ctrl + R to reset form
    if (e.ctrlKey && e.keyCode === 82) {
        e.preventDefault();
        $('#visitForm')[0].reset();
        // Reset Select2
        $('.select2').val('').trigger('change');
        // Reset to original values
        $('#visit_type').val('<?php echo $visit['visit_type']; ?>').trigger('change');
        $('#facility_code').val('<?php echo $visit['facility_code']; ?>').trigger('change');
        $('#department_id').val('<?php echo $visit['department_id'] ?? ''; ?>').trigger('change');
        $('#attending_provider_id').val('<?php echo $visit['attending_provider_id']; ?>').trigger('change');
        // Reset preview
        $('#preview_visit_type').text('<?php echo $visit['visit_type']; ?>');
        $('#preview_facility').text('<?php echo $visit['facility_code']; ?>');
        $('#preview_department').text('<?php echo htmlspecialchars($dept_name ?: 'Not specified'); ?>');
        $('#preview_provider').text('<?php echo htmlspecialchars($doctor_name ?: 'Not assigned'); ?>');
        // Clear validation errors
        $('.is-invalid').removeClass('is-invalid');
        $('.select2-selection').removeClass('is-invalid');
    }
    <?php endif; ?>
    // Escape to cancel
    if (e.keyCode === 27) {
        window.location.href = 'visit_details.php?visit_id=<?php echo $visit_id; ?>';
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
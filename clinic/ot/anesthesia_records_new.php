<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Check if we're coming from a surgical case, visit, or patient
$case_id = isset($_GET['case_id']) ? intval($_GET['case_id']) : 0;
$visit_id = isset($_GET['visit_id']) ? intval($_GET['visit_id']) : 0;
$patient_id = isset($_GET['patient_id']) ? intval($_GET['patient_id']) : 0;

// Initialize variables
$anaesthesia_types = [];
$anaesthetists = [];
$patients = [];
$surgical_cases = [];

// Get anaesthesia types
$anaesthesia_types = [
    'general' => 'General Anaesthesia',
    'regional' => 'Regional Anaesthesia',
    'spinal' => 'Spinal Anaesthesia',
    'epidural' => 'Epidural Anaesthesia',
    'local' => 'Local Anaesthesia',
    'sedation' => 'Conscious Sedation',
    'combined' => 'Combined Anaesthesia'
];

// Get anaesthetists (users with anaesthetist role)
$anaesthetists_sql = "SELECT user_id, user_name
                     FROM users 
                     ORDER BY user_name";
$anaesthetists_result = $mysqli->query($anaesthetists_sql);
while ($anaesthetist = $anaesthetists_result->fetch_assoc()) {
    $anaesthetists[] = $anaesthetist;
}

// Get patients with active surgical cases (from surgical_cases table)
$patients_sql = "SELECT DISTINCT p.patient_id, p.first_name, p.last_name, 
                        p.patient_mrn, p.sex, p.date_of_birth,
                        p.blood_group
                      FROM patients p
                 JOIN surgical_cases sc ON p.patient_id = sc.patient_id
                 WHERE sc.case_status IN ('scheduled', 'referred', 'in_or')
                 AND (sc.surgery_date >= CURDATE() OR sc.surgery_date IS NULL)
                 AND p.patient_status = 'ACTIVE'
                 ORDER BY p.last_name, p.first_name";
$patients_result = $mysqli->query($patients_sql);
while ($patient = $patients_result->fetch_assoc()) {
    $patients[] = $patient;
}

// If we have a case_id, get the case and patient details
$selected_case = null;
$selected_patient = null;
$preselected_patient_id = null;

if ($case_id > 0) {
    // Get surgical case with patient details from patients table
    $case_sql = "SELECT sc.*, p.first_name, p.last_name, 
                        p.patient_mrn, p.sex, p.date_of_birth,
                        p.blood_group, 
                        u.user_name as surgeon_name,
                        t.theatre_number, t.theatre_name
                 FROM surgical_cases sc
                 JOIN patients p ON sc.patient_id = p.patient_id
                 LEFT JOIN users u ON sc.primary_surgeon_id = u.user_id
                 LEFT JOIN theatres t ON sc.theater_id = t.theatre_id
                 WHERE sc.case_id = ?";
    
    $case_stmt = $mysqli->prepare($case_sql);
    $case_stmt->bind_param("i", $case_id);
    $case_stmt->execute();
    $case_result = $case_stmt->get_result();
    
    if ($case_result->num_rows > 0) {
        $selected_case = $case_result->fetch_assoc();
        $preselected_patient_id = $selected_case['patient_id'];
        $selected_patient = [
            'patient_id' => $selected_case['patient_id'],
            'first_name' => $selected_case['first_name'],
            'last_name' => $selected_case['last_name'],
            'patient_mrn' => $selected_case['patient_mrn'],
            'sex' => $selected_case['sex'],
            'date_of_birth' => $selected_case['date_of_birth'],
            'blood_group' => $selected_case['blood_group'],
        ];
    }
} elseif ($visit_id > 0) {
    // Get visit details and find surgical case for this visit
    $visit_sql = "SELECT v.*, p.patient_id, p.first_name, p.last_name, 
                         p.patient_mrn, p.sex, p.date_of_birth,
                         p.blood_group
                  FROM visits v
                  JOIN patients p ON v.patient_id = p.patient_id
                  WHERE v.visit_id = ?";
    
    $visit_stmt = $mysqli->prepare($visit_sql);
    $visit_stmt->bind_param("i", $visit_id);
    $visit_stmt->execute();
    $visit_result = $visit_stmt->get_result();
    
    if ($visit_result->num_rows > 0) {
        $visit = $visit_result->fetch_assoc();
        $preselected_patient_id = $visit['patient_id'];
        $selected_patient = [
            'patient_id' => $visit['patient_id'],
            'first_name' => $visit['first_name'],
            'last_name' => $visit['last_name'],
            'patient_mrn' => $visit['patient_mrn'],
            'sex' => $visit['sex'],
            'date_of_birth' => $visit['date_of_birth'],
            'blood_group' => $visit['blood_group']
        ];
        
        // Find surgical case for this visit
        $case_sql = "SELECT sc.*, u.user_name as surgeon_name,
                            t.theatre_number, t.theatre_name
                     FROM surgical_cases sc
                     LEFT JOIN users u ON sc.primary_surgeon_id = u.user_id
                     LEFT JOIN theatres t ON sc.theater_id = t.theatre_id
                     WHERE sc.visit_id = ?
                     AND sc.case_status IN ('scheduled', 'referred', 'in_or')
                     LIMIT 1";
        
        $case_stmt = $mysqli->prepare($case_sql);
        $case_stmt->bind_param("i", $visit_id);
        $case_stmt->execute();
        $case_result = $case_stmt->get_result();
        
        if ($case_result->num_rows > 0) {
            $selected_case = $case_result->fetch_assoc();
        }
    }
} elseif ($patient_id > 0) {
    // Get patient details directly
    $patient_sql = "SELECT p.* 
                   FROM patients p
                   WHERE p.patient_id = ? 
                   AND p.patient_status = 'ACTIVE'";
    
    $patient_stmt = $mysqli->prepare($patient_sql);
    $patient_stmt->bind_param("i", $patient_id);
    $patient_stmt->execute();
    $patient_result = $patient_stmt->get_result();
    
    if ($patient_result->num_rows > 0) {
        $preselected_patient_id = $patient_id;
        $selected_patient = $patient_result->fetch_assoc();
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    $case_id = intval($_POST['case_id']);
    $anaesthetist_id = intval($_POST['anaesthetist_id']);
    $anaesthesia_type = sanitizeInput($_POST['anaesthesia_type']);
    $agents_used = sanitizeInput($_POST['agents_used']);
    $dosage = sanitizeInput($_POST['dosage']);
    $start_time = !empty($_POST['start_time']) ? $_POST['start_time'] : null;
    $end_time = !empty($_POST['end_time']) ? $_POST['end_time'] : null;
    $monitoring_parameters = sanitizeInput($_POST['monitoring_parameters']);
    $pre_op_assessment = sanitizeInput($_POST['pre_op_assessment']);
    $intra_op_events = sanitizeInput($_POST['intra_op_events']);
    $post_op_plan = sanitizeInput($_POST['post_op_plan']);
    $complications = sanitizeInput($_POST['complications']);
    $recovery_notes = sanitizeInput($_POST['recovery_notes']);
    
    // Vital signs
    $bp_pre_op = sanitizeInput($_POST['bp_pre_op']);
    $hr_pre_op = sanitizeInput($_POST['hr_pre_op']);
    $rr_pre_op = sanitizeInput($_POST['rr_pre_op']);
    $spo2_pre_op = sanitizeInput($_POST['spo2_pre_op']);
    $temp_pre_op = sanitizeInput($_POST['temp_pre_op']);
    $bp_intra_op = sanitizeInput($_POST['bp_intra_op']);
    $hr_intra_op = sanitizeInput($_POST['hr_intra_op']);
    $rr_intra_op = sanitizeInput($_POST['rr_intra_op']);
    $spo2_intra_op = sanitizeInput($_POST['spo2_intra_op']);
    $temp_intra_op = sanitizeInput($_POST['temp_intra_op']);
    $bp_post_op = sanitizeInput($_POST['bp_post_op']);
    $hr_post_op = sanitizeInput($_POST['hr_post_op']);
    $rr_post_op = sanitizeInput($_POST['rr_post_op']);
    $spo2_post_op = sanitizeInput($_POST['spo2_post_op']);
    $temp_post_op = sanitizeInput($_POST['temp_post_op']);

    // Validate CSRF token
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        header("Location: anaesthesia_new.php");
        exit;
    }

    // Validate required fields
    if (empty($case_id) || empty($anaesthetist_id) || empty($anaesthesia_type)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please fill in all required fields.";
        header("Location: anaesthesia_new.php");
        exit;
    }

    // Check if anaesthesia record already exists for this surgical case
    $check_sql = "SELECT record_id FROM anaesthesia_records WHERE surgery_id = ? AND archived_at IS NULL";
    $check_stmt = $mysqli->prepare($check_sql);
    $check_stmt->bind_param("i", $case_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($check_result->num_rows > 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "An anaesthesia record already exists for this surgical case.";
        header("Location: anaesthesia_new.php");
        exit;
    }

    // Get surgical case details for logging
    $case_sql = "SELECT sc.case_number, sc.patient_id, p.first_name, p.last_name
                 FROM surgical_cases sc
                 JOIN patients p ON sc.patient_id = p.patient_id
                 WHERE sc.case_id = ?";
    $case_stmt = $mysqli->prepare($case_sql);
    $case_stmt->bind_param("i", $case_id);
    $case_stmt->execute();
    $case_result = $case_stmt->get_result();
    $case = $case_result->fetch_assoc();

    // Insert new anaesthesia record (using case_id as surgery_id for compatibility)
    $insert_sql = "INSERT INTO anaesthesia_records (
        surgery_id, anaesthetist_id, anaesthesia_type, agents_used, dosage,
        start_time, end_time, monitoring_parameters, complications, recovery_notes,
        pre_op_assessment, intra_op_events, post_op_plan,
        bp_pre_op, hr_pre_op, rr_pre_op, spo2_pre_op, temp_pre_op,
        bp_intra_op, hr_intra_op, rr_intra_op, spo2_intra_op, temp_intra_op,
        bp_post_op, hr_post_op, rr_post_op, spo2_post_op, temp_post_op,
        created_by
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $insert_stmt = $mysqli->prepare($insert_sql);
    $insert_stmt->bind_param(
        "iisssssssssssssssssssssssss",
        $case_id,
        $anaesthetist_id,
        $anaesthesia_type,
        $agents_used,
        $dosage,
        $start_time,
        $end_time,
        $monitoring_parameters,
        $complications,
        $recovery_notes,
        $pre_op_assessment,
        $intra_op_events,
        $post_op_plan,
        $bp_pre_op,
        $hr_pre_op,
        $rr_pre_op,
        $spo2_pre_op,
        $temp_pre_op,
        $bp_intra_op,
        $hr_intra_op,
        $rr_intra_op,
        $spo2_intra_op,
        $temp_intra_op,
        $bp_post_op,
        $hr_post_op,
        $rr_post_op,
        $spo2_post_op,
        $temp_post_op,
        $session_user_id
    );

    if ($insert_stmt->execute()) {
        $new_record_id = $insert_stmt->insert_id;
        
        // Log the activity
        $activity_description = "Created anaesthesia record for surgical case #{$case['case_number']} - {$case['first_name']} {$case['last_name']}";
        mysqli_query($mysqli, "INSERT INTO activity_logs (activity_description, activity_type, user_id, surgery_id, patient_id) VALUES ('$activity_description', 'anaesthesia_created', $session_user_id, $case_id, {$case['patient_id']})");
        
        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Anaesthesia record created successfully!";
        header("Location: anaesthesia_view.php?id=" . $new_record_id);
        exit;
    } else {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error creating anaesthesia record: " . $mysqli->error;
        header("Location: anaesthesia_new.php");
        exit;
    }
}
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-syringe mr-2"></i>New Anaesthesia Record
        </h3>
        <div class="card-tools">
            <a href="anaesthesia_records.php" class="btn btn-light">
                <i class="fas fa-arrow-left mr-2"></i>Back to Records
            </a>
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

        <form method="POST" id="anaesthesiaForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" id="selected_case_id" name="case_id" value="<?php echo $selected_case ? $selected_case['case_id'] : ''; ?>">
            
            <div class="row">
                <!-- Left Column - Patient & Surgical Case Selection -->
                <div class="col-lg-4">
                    <!-- Patient Selection Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h5 class="card-title mb-0"><i class="fas fa-user-injured mr-2"></i>Select Patient</h5>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label for="patient_id">Patient *</label>
                                <select class="form-control select2" id="patient_id" name="patient_id" required onchange="loadSurgicalCases()">
                                    <option value="">- Select Patient -</option>
                                    <?php foreach ($patients as $patient): 
                                        $age = $patient['date_of_birth'] ? date('Y') - date('Y', strtotime($patient['date_of_birth'])) : 'N/A';
                                        $selected = ($preselected_patient_id == $patient['patient_id']) ? 'selected' : '';
                                    ?>
                                        <option value="<?php echo $patient['patient_id']; ?>" 
                                                data-blood-group="<?php echo $patient['blood_group']; ?>"
                                                <?php echo $selected; ?>>
                                            <?php echo htmlspecialchars($patient['last_name'] . ', ' . $patient['first_name'] . ' (' . $age . 'y/' . $patient['sex'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Patient Information Preview -->
                            <div id="patientInfo" class="mt-3" style="<?php echo $selected_patient ? '' : 'display: none;'; ?>">
                                <h6 class="text-muted">Patient Details</h6>
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td class="text-muted">Name:</td>
                                        <td id="preview_name"><?php echo $selected_patient ? htmlspecialchars($selected_patient['first_name'] . ' ' . $selected_patient['last_name']) : '-'; ?></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">MRN:</td>
                                        <td id="preview_mrn"><?php echo $selected_patient ? htmlspecialchars($selected_patient['patient_mrn']) : '-'; ?></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Gender:</td>
                                        <td id="preview_gender"><?php echo $selected_patient ? htmlspecialchars($selected_patient['sex']) : '-'; ?></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">DOB:</td>
                                        <td id="preview_dob"><?php echo $selected_patient ? ($selected_patient['date_of_birth'] ? date('M j, Y', strtotime($selected_patient['date_of_birth'])) : '-') : '-'; ?></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Age:</td>
                                        <td id="preview_age"><?php echo $selected_patient && $selected_patient['date_of_birth'] ? (date('Y') - date('Y', strtotime($selected_patient['date_of_birth']))) . ' yrs' : '-'; ?></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Blood Type:</td>
                                        <td id="preview_blood_type"><?php echo $selected_patient ? ($selected_patient['blood_group'] ?: '-') : '-'; ?></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Surgical Case Selection Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h5 class="card-title mb-0"><i class="fas fa-procedures mr-2"></i>Select Surgical Case</h5>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label for="surgical_case_id">Surgical Case *</label>
                                <select class="form-control select2" id="surgical_case_id" name="surgical_case_id" required 
                                        onchange="updateSelectedCase()" <?php echo $preselected_patient_id ? '' : 'disabled'; ?>>
                                    <option value="">- Select Surgical Case -</option>
                                    <?php if ($selected_case): ?>
                                        <option value="<?php echo $selected_case['case_id']; ?>" 
                                                data-procedure="<?php echo htmlspecialchars($selected_case['planned_procedure']); ?>"
                                                data-surgeon="<?php echo htmlspecialchars($selected_case['surgeon_name']); ?>"
                                                data-theatre="<?php echo htmlspecialchars($selected_case['theatre_number'] . ' - ' . $selected_case['theatre_name']); ?>"
                                                data-date="<?php echo $selected_case['surgery_date']; ?>"
                                                data-time="<?php echo $selected_case['surgery_start_time']; ?>"
                                                data-diagnosis="<?php echo htmlspecialchars($selected_case['pre_op_diagnosis']); ?>"
                                                selected>
                                            <?php echo htmlspecialchars($selected_case['case_number'] . ' - ' . 
                                                ($selected_case['surgery_date'] ? date('M j', strtotime($selected_case['surgery_date'])) : 'No date') . 
                                                ($selected_case['surgery_start_time'] ? ' @ ' . $selected_case['surgery_start_time'] : '')); ?>
                                        </option>
                                    <?php endif; ?>
                                </select>
                                <small class="form-text text-muted">Select a patient first to view their surgical cases</small>
                            </div>
                            
                            <!-- Surgical Case Information Preview -->
                            <div id="caseInfo" class="mt-3" style="<?php echo $selected_case ? '' : 'display: none;'; ?>">
                                <h6 class="text-muted">Surgical Case Details</h6>
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td class="text-muted" width="40%">Procedure:</td>
                                        <td id="preview_procedure"><?php echo $selected_case ? htmlspecialchars($selected_case['planned_procedure']) : '-'; ?></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Surgeon:</td>
                                        <td id="preview_surgeon"><?php echo $selected_case ? htmlspecialchars($selected_case['surgeon_name']) : '-'; ?></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Theatre:</td>
                                        <td id="preview_theatre"><?php echo $selected_case ? htmlspecialchars($selected_case['theatre_number'] . ' - ' . $selected_case['theatre_name']) : '-'; ?></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Date:</td>
                                        <td id="preview_date"><?php echo $selected_case ? ($selected_case['surgery_date'] ? date('M j, Y', strtotime($selected_case['surgery_date'])) : '-') : '-'; ?></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Time:</td>
                                        <td id="preview_time"><?php echo $selected_case ? ($selected_case['surgery_start_time'] ?: '-') : '-'; ?></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted align-top">Diagnosis:</td>
                                        <td id="preview_diagnosis"><?php echo $selected_case ? htmlspecialchars($selected_case['pre_op_diagnosis']) : '-'; ?></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Stats Card -->
                    <div class="card">
                        <div class="card-header bg-light py-2">
                            <h5 class="card-title mb-0"><i class="fas fa-chart-line mr-2"></i>Vital Signs Summary</h5>
                        </div>
                        <div class="card-body">
                            <div class="text-center">
                                <div class="row mb-3">
                                    <div class="col-6">
                                        <div class="border rounded p-2">
                                            <div class="h5 text-primary mb-1" id="stats_bp">-</div>
                                            <small class="text-muted">BP (Avg)</small>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="border rounded p-2">
                                            <div class="h5 text-success mb-1" id="stats_hr">-</div>
                                            <small class="text-muted">HR (Avg)</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-6">
                                        <div class="border rounded p-2">
                                            <div class="h5 text-info mb-1" id="stats_spo2">-</div>
                                            <small class="text-muted">SpO₂ (Avg)</small>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="border rounded p-2">
                                            <div class="h5 text-warning mb-1" id="stats_temp">-</div>
                                            <small class="text-muted">Temp (Avg)</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column - Anaesthesia Details -->
                <div class="col-lg-8">
                    <!-- Anaesthesia Details Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h5 class="card-title mb-0"><i class="fas fa-file-medical-alt mr-2"></i>Anaesthesia Details</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="anaesthetist_id">Anaesthetist *</label>
                                        <select class="form-control select2" id="anaesthetist_id" name="anaesthetist_id" required>
                                            <option value="">- Select Anaesthetist -</option>
                                            <?php foreach ($anaesthetists as $anaesthetist): ?>
                                                <option value="<?php echo $anaesthetist['user_id']; ?>">
                                                    <?php echo htmlspecialchars($anaesthetist['user_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="anaesthesia_type">Anaesthesia Type *</label>
                                        <select class="form-control select2" id="anaesthesia_type" name="anaesthesia_type" required>
                                            <option value="">- Select Type -</option>
                                            <?php foreach ($anaesthesia_types as $value => $label): ?>
                                                <option value="<?php echo $value; ?>">
                                                    <?php echo htmlspecialchars($label); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="agents_used">Anaesthetic Agents Used</label>
                                        <textarea class="form-control" id="agents_used" name="agents_used" rows="2" 
                                                  placeholder="e.g., Propofol, Sevoflurane, Fentanyl, Rocuronium..."></textarea>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="dosage">Dosage & Administration</label>
                                        <textarea class="form-control" id="dosage" name="dosage" rows="2" 
                                                  placeholder="e.g., Propofol 2mg/kg, Sevoflurane 2%, Fentanyl 2mcg/kg..."></textarea>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="start_time">Anaesthesia Start Time</label>
                                        <input type="datetime-local" class="form-control" id="start_time" name="start_time">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="end_time">Anaesthesia End Time</label>
                                        <input type="datetime-local" class="form-control" id="end_time" name="end_time">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Vital Signs Monitoring Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h5 class="card-title mb-0"><i class="fas fa-heartbeat mr-2"></i>Vital Signs Monitoring</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered table-sm">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Parameter</th>
                                            <th>Pre-operative</th>
                                            <th>Intra-operative</th>
                                            <th>Post-operative</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td><strong>Blood Pressure (BP)</strong></td>
                                            <td>
                                                <input type="text" class="form-control form-control-sm" name="bp_pre_op" 
                                                       placeholder="120/80" oninput="updateVitalStats()">
                                            </td>
                                            <td>
                                                <input type="text" class="form-control form-control-sm" name="bp_intra_op" 
                                                       placeholder="110/70" oninput="updateVitalStats()">
                                            </td>
                                            <td>
                                                <input type="text" class="form-control form-control-sm" name="bp_post_op" 
                                                       placeholder="115/75" oninput="updateVitalStats()">
                                            </td>
                                        </tr>
                                        <tr>
                                            <td><strong>Heart Rate (HR)</strong></td>
                                            <td>
                                                <input type="text" class="form-control form-control-sm" name="hr_pre_op" 
                                                       placeholder="72 bpm" oninput="updateVitalStats()">
                                            </td>
                                            <td>
                                                <input type="text" class="form-control form-control-sm" name="hr_intra_op" 
                                                       placeholder="68 bpm" oninput="updateVitalStats()">
                                            </td>
                                            <td>
                                                <input type="text" class="form-control form-control-sm" name="hr_post_op" 
                                                       placeholder="70 bpm" oninput="updateVitalStats()">
                                            </td>
                                        </tr>
                                        <tr>
                                            <td><strong>SpO<sub>2</sub></strong></td>
                                            <td>
                                                <input type="text" class="form-control form-control-sm" name="spo2_pre_op" 
                                                       placeholder="98%" oninput="updateVitalStats()">
                                            </td>
                                            <td>
                                                <input type="text" class="form-control form-control-sm" name="spo2_intra_op" 
                                                       placeholder="99%" oninput="updateVitalStats()">
                                            </td>
                                            <td>
                                                <input type="text" class="form-control form-control-sm" name="spo2_post_op" 
                                                       placeholder="97%" oninput="updateVitalStats()">
                                            </td>
                                        </tr>
                                        <tr>
                                            <td><strong>Temperature</strong></td>
                                            <td>
                                                <input type="text" class="form-control form-control-sm" name="temp_pre_op" 
                                                       placeholder="36.5°C" oninput="updateVitalStats()">
                                            </td>
                                            <td>
                                                <input type="text" class="form-control form-control-sm" name="temp_intra_op" 
                                                       placeholder="36.2°C" oninput="updateVitalStats()">
                                            </td>
                                            <td>
                                                <input type="text" class="form-control form-control-sm" name="temp_post_op" 
                                                       placeholder="36.4°C" oninput="updateVitalStats()">
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            
                            <div class="form-group mt-3">
                                <label for="monitoring_parameters">Monitoring Parameters</label>
                                <textarea class="form-control" id="monitoring_parameters" name="monitoring_parameters" rows="2" 
                                          placeholder="ECG, ETCO2, NIBP, Temperature, Neuromuscular monitoring, etc."></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Assessment & Events Cards -->
                    <div class="row">
                        <div class="col-md-4">
                            <div class="card h-100">
                                <div class="card-header bg-light py-2">
                                    <h5 class="card-title mb-0"><i class="fas fa-clipboard-check mr-2"></i>Pre-op Assessment</h5>
                                </div>
                                <div class="card-body">
                                    <textarea class="form-control" id="pre_op_assessment" name="pre_op_assessment" rows="5" 
                                              placeholder="ASA grade, Mallampati score, Airway assessment, Fasting status, Pre-medication..."></textarea>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card h-100">
                                <div class="card-header bg-light py-2">
                                    <h5 class="card-title mb-0"><i class="fas fa-procedures mr-2"></i>Intra-op Events</h5>
                                </div>
                                <div class="card-body">
                                    <textarea class="form-control" id="intra_op_events" name="intra_op_events" rows="5" 
                                              placeholder="Induction, Intubation, Positioning, IV lines, Fluid management..."></textarea>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card h-100">
                                <div class="card-header bg-light py-2">
                                    <h5 class="card-title mb-0"><i class="fas fa-notes-medical mr-2"></i>Post-op Plan</h5>
                                </div>
                                <div class="card-body">
                                    <textarea class="form-control" id="post_op_plan" name="post_op_plan" rows="5" 
                                              placeholder="Analgesia plan, Monitoring requirements, Expected discharge..."></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Complications & Recovery Cards -->
                    <div class="row mt-4">
                        <div class="col-md-6">
                            <div class="card h-100">
                                <div class="card-header bg-light py-2">
                                    <h5 class="card-title mb-0"><i class="fas fa-exclamation-triangle mr-2"></i>Complications</h5>
                                </div>
                                <div class="card-body">
                                    <textarea class="form-control" id="complications" name="complications" rows="4" 
                                              placeholder="Any intra-operative or immediate post-operative complications..."></textarea>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card h-100">
                                <div class="card-header bg-light py-2">
                                    <h5 class="card-title mb-0"><i class="fas fa-bed mr-2"></i>Recovery Notes</h5>
                                </div>
                                <div class="card-body">
                                    <textarea class="form-control" id="recovery_notes" name="recovery_notes" rows="4" 
                                              placeholder="Recovery room observations, Aldrete score, Pain assessment..."></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Form Actions -->
                    <div class="row mt-4">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body text-center">
                                    <button type="submit" class="btn btn-success btn-lg mr-2">
                                        <i class="fas fa-save mr-2"></i>Create Anaesthesia Record
                                    </button>
                                    <button type="reset" class="btn btn-outline-secondary btn-lg mr-2">
                                        <i class="fas fa-undo mr-2"></i>Reset Form
                                    </button>
                                    <a href="anaesthesia_records.php" class="btn btn-outline-danger btn-lg">
                                        <i class="fas fa-times mr-2"></i>Cancel
                                    </a>
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
    $('.select2').select2({
        theme: 'bootstrap4'
    });
    
    <?php if ($preselected_patient_id): ?>
    // Load surgical cases for preselected patient
    loadSurgicalCases();
    <?php endif; ?>
    
    // Load surgical cases when patient is selected
    window.loadSurgicalCases = function() {
        var patientId = $('#patient_id').val();
        var caseSelect = $('#surgical_case_id');
        var patientSelect = $('#patient_id');
        var selectedPatient = patientSelect.find('option:selected');
        
        if (!patientId) {
            caseSelect.prop('disabled', true).empty().append('<option value="">- Select Surgical Case -</option>');
            $('#patientInfo').hide();
            $('#caseInfo').hide();
            return;
        }
        
        // Show patient info
        if (selectedPatient.length > 0) {
            $('#preview_name').text(selectedPatient.text().split(' (')[0]);
            $('#preview_mrn').text(selectedPatient.text().match(/MRN: (\w+)/)?.[1] || '-');
            $('#preview_gender').text(selectedPatient.text().match(/\/([MFI])\//)?.[1] || '-');
            $('#preview_dob').text('-');
            $('#preview_age').text(selectedPatient.text().match(/(\d+)y/)?.[1] + ' yrs' || '-');
            $('#preview_blood_type').text(selectedPatient.data('blood-group') || '-');
            $('#patientInfo').show();
        }
        
        // Load surgical cases via AJAX
        $.ajax({
            url: 'ajax/get_patient_surgical_cases.php',
            type: 'GET',
            data: { patient_id: patientId },
            dataType: 'json',
            success: function(data) {
                caseSelect.empty().append('<option value="">- Select Surgical Case -</option>');
                
                if (data.length > 0) {
                    $.each(data, function(index, surgical_case) {
                        var date = surgical_case.surgery_date ? new Date(surgical_case.surgery_date) : null;
                        var formattedDate = date ? date.toLocaleDateString('en-US', {month: 'short', day: 'numeric'}) : 'No date';
                        var time = surgical_case.surgery_start_time ? surgical_case.surgery_start_time : '';
                        
                        caseSelect.append(
                            '<option value="' + surgical_case.case_id + '" ' +
                            'data-procedure="' + (surgical_case.planned_procedure || '') + '" ' +
                            'data-surgeon="' + (surgical_case.surgeon_name || '') + '" ' +
                            'data-theatre="' + (surgical_case.theatre_number || '') + '" ' +
                            'data-date="' + (surgical_case.surgery_date || '') + '" ' +
                            'data-time="' + (time) + '" ' +
                            'data-diagnosis="' + (surgical_case.pre_op_diagnosis || '') + '">' +
                            surgical_case.case_number + ' - ' + formattedDate + 
                            (time ? ' @ ' + time : '') +
                            '</option>'
                        );
                    });
                    caseSelect.prop('disabled', false);
                } else {
                    caseSelect.append('<option value="">No surgical cases found</option>');
                    caseSelect.prop('disabled', true);
                    $('#caseInfo').hide();
                }
            },
            error: function() {
                caseSelect.empty().append('<option value="">Error loading surgical cases</option>');
                caseSelect.prop('disabled', true);
            }
        });
    };

    // Update selected case info and set hidden input
    window.updateSelectedCase = function() {
        var selectedOption = $('#surgical_case_id').find('option:selected');
        var procedure = selectedOption.data('procedure');
        var surgeon = selectedOption.data('surgeon');
        var theatre = selectedOption.data('theatre');
        var date = selectedOption.data('date');
        var time = selectedOption.data('time');
        var diagnosis = selectedOption.data('diagnosis');
        var caseId = selectedOption.val();
        
        if (caseId) {
            var formattedDate = date ? new Date(date).toLocaleDateString('en-US', {month: 'short', day: 'numeric', year: 'numeric'}) : '-';
            var formattedTime = time ? time : '-';
            
            $('#preview_procedure').text(procedure || '-');
            $('#preview_surgeon').text(surgeon || '-');
            $('#preview_theatre').text(theatre || '-');
            $('#preview_date').text(formattedDate);
            $('#preview_time').text(formattedTime);
            $('#preview_diagnosis').text(diagnosis || '-');
            $('#caseInfo').show();
            
            // Update hidden input with case_id
            $('#selected_case_id').val(caseId);
        } else {
            $('#caseInfo').hide();
            $('#selected_case_id').val('');
        }
    };
    
    // Update vital signs statistics
    window.updateVitalStats = function() {
        // Calculate average BP (systolic only)
        let bpSum = 0;
        let bpCount = 0;
        ['pre_op', 'intra_op', 'post_op'].forEach(phase => {
            const bp = $(`input[name="bp_${phase}"]`).val();
            if (bp && bp.includes('/')) {
                const systolic = parseInt(bp.split('/')[0]);
                if (!isNaN(systolic)) {
                    bpSum += systolic;
                    bpCount++;
                }
            }
        });
        $('#stats_bp').text(bpCount > 0 ? Math.round(bpSum / bpCount) + '' : '-');
        
        // Calculate average HR
        let hrSum = 0;
        let hrCount = 0;
        ['pre_op', 'intra_op', 'post_op'].forEach(phase => {
            const hr = $(`input[name="hr_${phase}"]`).val();
            const hrNum = parseInt(hr);
            if (!isNaN(hrNum) && hrNum > 0) {
                hrSum += hrNum;
                hrCount++;
            }
        });
        $('#stats_hr').text(hrCount > 0 ? Math.round(hrSum / hrCount) + '' : '-');
        
        // Calculate average SpO2
        let spo2Sum = 0;
        let spo2Count = 0;
        ['pre_op', 'intra_op', 'post_op'].forEach(phase => {
            const spo2 = $(`input[name="spo2_${phase}"]`).val();
            const spo2Num = parseInt(spo2);
            if (!isNaN(spo2Num) && spo2Num > 0) {
                spo2Sum += spo2Num;
                spo2Count++;
            }
        });
        $('#stats_spo2').text(spo2Count > 0 ? Math.round(spo2Sum / spo2Count) + '%' : '-');
        
        // Calculate average Temperature
        let tempSum = 0;
        let tempCount = 0;
        ['pre_op', 'intra_op', 'post_op'].forEach(phase => {
            const temp = $(`input[name="temp_${phase}"]`).val();
            const tempNum = parseFloat(temp);
            if (!isNaN(tempNum) && tempNum > 0) {
                tempSum += tempNum;
                tempCount++;
            }
        });
        $('#stats_temp').text(tempCount > 0 ? (tempSum / tempCount).toFixed(1) + '°C' : '-');
    };
    
    // Auto-calculate duration when both times are entered
    $('#start_time, #end_time').on('change', function() {
        const start = $('#start_time').val();
        const end = $('#end_time').val();
        
        if (start && end) {
            const startDate = new Date(start);
            const endDate = new Date(end);
            const durationMs = endDate - startDate;
            
            if (durationMs > 0) {
                const durationMinutes = Math.round(durationMs / (1000 * 60));
                const hours = Math.floor(durationMinutes / 60);
                const minutes = durationMinutes % 60;
                
                let durationText = '';
                if (hours > 0) durationText += hours + 'h ';
                if (minutes > 0) durationText += minutes + 'm';
                
                if (!$('#durationAlert').length) {
                    $('<div class="alert alert-info alert-dismissible fade show mt-3" id="durationAlert">' +
                      '<i class="fas fa-clock mr-2"></i>' +
                      'Anaesthesia duration: <strong>' + durationText.trim() + '</strong>' +
                      '<button type="button" class="close" data-dismiss="alert">' +
                      '<span>&times;</span>' +
                      '</button>' +
                      '</div>').insertAfter('#anaesthesiaForm .card:first-child .card-body');
                } else {
                    $('#durationAlert').html(
                      '<i class="fas fa-clock mr-2"></i>' +
                      'Anaesthesia duration: <strong>' + durationText.trim() + '</strong>' +
                      '<button type="button" class="close" data-dismiss="alert">' +
                      '<span>&times;</span>' +
                      '</button>'
                    );
                }
            }
        }
    });
    
    // Form validation
    $('#anaesthesiaForm').on('submit', function(e) {
        var isValid = true;
        var errorMessages = [];
        
        // Clear previous errors
        $('.is-invalid').removeClass('is-invalid');
        $('.invalid-feedback').remove();
        
        // Check required fields
        if (!$('#patient_id').val()) {
            $('#patient_id').addClass('is-invalid').after('<div class="invalid-feedback">Please select a patient</div>');
            errorMessages.push('Patient selection is required');
            isValid = false;
        }
        
        if (!$('#surgical_case_id').val()) {
            $('#surgical_case_id').addClass('is-invalid').after('<div class="invalid-feedback">Please select a surgical case</div>');
            errorMessages.push('Surgical case selection is required');
            isValid = false;
        }
        
        if (!$('#anaesthetist_id').val()) {
            $('#anaesthetist_id').addClass('is-invalid').after('<div class="invalid-feedback">Please select an anaesthetist</div>');
            errorMessages.push('Anaesthetist selection is required');
            isValid = false;
        }
        
        if (!$('#anaesthesia_type').val()) {
            $('#anaesthesia_type').addClass('is-invalid').after('<div class="invalid-feedback">Please select anaesthesia type</div>');
            errorMessages.push('Anaesthesia type is required');
            isValid = false;
        }
        
        // Validate time logic
        const start = $('#start_time').val();
        const end = $('#end_time').val();
        if (start && end) {
            const startDate = new Date(start);
            const endDate = new Date(end);
            if (endDate <= startDate) {
                $('#end_time').addClass('is-invalid').after('<div class="invalid-feedback">End time must be after start time</div>');
                errorMessages.push('End time must be after start time');
                isValid = false;
            }
        }
        
        if (!isValid) {
            e.preventDefault();
            
            // Show error alert
            let errorHtml = '<div class="alert alert-danger alert-dismissible">';
            errorHtml += '<button type="button" class="close" data-dismiss="alert">&times;</button>';
            errorHtml += '<i class="fas fa-exclamation-triangle mr-2"></i>';
            errorHtml += '<strong>Please fix the following errors:</strong><ul class="mb-0 mt-1">';
            errorMessages.forEach(msg => errorHtml += '<li>' + msg + '</li>');
            errorHtml += '</ul></div>';
            
            $('.card-body').prepend(errorHtml);
            $('html, body').animate({ scrollTop: 0 }, 500);
        } else {
            // Show loading state
            $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Creating...').prop('disabled', true);
        }
    });
});

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + S to save
    if (e.ctrlKey && e.keyCode === 83) {
        e.preventDefault();
        $('#anaesthesiaForm').submit();
    }
    // Escape to cancel
    if (e.keyCode === 27) {
        window.location.href = 'anaesthesia_records.php';
    }
    // Ctrl + R to reset
    if (e.ctrlKey && e.keyCode === 82) {
        e.preventDefault();
        $('#anaesthesiaForm').trigger('reset');
        $('.select2').trigger('change');
        <?php if (!$preselected_patient_id): ?>
        $('#patientInfo, #caseInfo').hide();
        <?php endif; ?>
        $('#stats_bp, #stats_hr, #stats_spo2, #stats_temp').text('-');
    }
});
</script>

<style>
.select2-container .select2-selection--single {
    height: 38px !important;
}
.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 36px !important;
}
.select2-container--default .select2-selection--single .select2-selection__arrow {
    height: 36px !important;
}
.card {
    border: 1px solid #e3e6f0;
}
.card-header.bg-light {
    background-color: #f8f9fa !important;
    border-bottom: 1px solid #e3e6f0;
}
.table th {
    background-color: #f8f9fa;
    font-weight: 600;
}
.table-borderless td {
    border: none !important;
}
.invalid-feedback {
    display: block;
}
.form-control:focus {
    border-color: #80bdff;
    box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/audit_functions.php'; // Added for logging

// AUDIT LOG: Initial page access
audit_log($mysqli, [
    'user_id'     => $_SESSION['user_id'] ?? null,
    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
    'action'      => 'PAGE_ACCESS',
    'module'      => 'Vitals',
    'table_name'  => 'N/A',
    'entity_type' => 'page',
    'record_id'   => null,
    'patient_id'  => null,
    'visit_id'    => null,
    'description' => "Accessed vitals.php",
    'status'      => 'ATTEMPT',
    'old_values'  => null,
    'new_values'  => null
]);

// Get visit_id from URL
$visit_id = intval($_GET['visit_id'] ?? 0);

if ($visit_id <= 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid visit ID";
    
    // AUDIT LOG: Invalid visit ID
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'ACCESS_DENIED',
        'module'      => 'Vitals',
        'table_name'  => 'visits',
        'entity_type' => 'visit',
        'record_id'   => null,
        'patient_id'  => null,
        'visit_id'    => null,
        'description' => "Attempted to access vitals.php with invalid visit ID: " . ($_GET['visit_id'] ?? 'empty'),
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
        'action'      => 'ACCESS_DENIED',
        'module'      => 'Vitals',
        'table_name'  => 'visits',
        'entity_type' => 'visit',
        'record_id'   => $visit_id,
        'patient_id'  => null,
        'visit_id'    => $visit_id,
        'description' => "Visit not found for vitals. Visit ID: " . $visit_id,
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

// AUDIT LOG: Retrieved visit and patient information
audit_log($mysqli, [
    'user_id'     => $_SESSION['user_id'] ?? null,
    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
    'action'      => 'RETRIEVE',
    'module'      => 'Vitals',
    'table_name'  => 'visits',
    'entity_type' => 'visit',
    'record_id'   => $visit_id,
    'patient_id'  => $patient_info['patient_id'],
    'visit_id'    => $visit_id,
    'description' => "Retrieved visit information for vitals. Visit Type: " . $visit_type . ", MRN: " . $patient_info['patient_mrn'] ?? 'N/A',
    'status'      => 'SUCCESS',
    'old_values'  => null,
    'new_values'  => null
]);

// Get existing vitals for this visit
$vitals_sql = "SELECT * FROM vitals 
              WHERE visit_id = ? 
              ORDER BY recorded_at DESC";
$vitals_stmt = $mysqli->prepare($vitals_sql);
$vitals_stmt->bind_param("i", $visit_id);
$vitals_stmt->execute();
$vitals_result = $vitals_stmt->get_result();
$vitals_list = $vitals_result->fetch_all(MYSQLI_ASSOC);

// AUDIT LOG: Retrieved existing vitals
audit_log($mysqli, [
    'user_id'     => $_SESSION['user_id'] ?? null,
    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
    'action'      => 'RETRIEVE_VITALS',
    'module'      => 'Vitals',
    'table_name'  => 'vitals',
    'entity_type' => 'vitals_list',
    'record_id'   => null,
    'patient_id'  => $patient_info['patient_id'],
    'visit_id'    => $visit_id,
    'description' => "Retrieved " . count($vitals_list) . " existing vitals records for patient",
    'status'      => 'SUCCESS',
    'old_values'  => null,
    'new_values'  => null
]);

// Handle form submission for new vitals
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // AUDIT LOG: Form submission received
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'FORM_SUBMIT',
        'module'      => 'Vitals',
        'table_name'  => 'N/A',
        'entity_type' => 'form',
        'record_id'   => null,
        'patient_id'  => $patient_info['patient_id'],
        'visit_id'    => $visit_id,
        'description' => "Form submission received on vitals page. Action: " . (isset($_POST['add_vitals']) ? 'add_vitals' : 'unknown'),
        'status'      => 'ATTEMPT',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    if (isset($_POST['add_vitals'])) {
        // Get form data
        $temperature = !empty($_POST['temperature']) ? floatval($_POST['temperature']) : null;
        $pulse = !empty($_POST['pulse']) ? intval($_POST['pulse']) : null;
        $respiration_rate = !empty($_POST['respiration_rate']) ? intval($_POST['respiration_rate']) : null;
        $blood_pressure_systolic = !empty($_POST['blood_pressure_systolic']) ? intval($_POST['blood_pressure_systolic']) : null;
        $blood_pressure_diastolic = !empty($_POST['blood_pressure_diastolic']) ? intval($_POST['blood_pressure_diastolic']) : null;
        $oxygen_saturation = !empty($_POST['oxygen_saturation']) ? intval($_POST['oxygen_saturation']) : null;
        $weight = !empty($_POST['weight']) ? floatval($_POST['weight']) : null;
        $height = !empty($_POST['height']) ? floatval($_POST['height']) : null;
        $remarks = !empty($_POST['remarks']) ? trim($_POST['remarks']) : null;
        
        // AUDIT LOG: Starting vitals recording
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'],
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'ADD_VITALS_START',
            'module'      => 'Vitals',
            'table_name'  => 'vitals',
            'entity_type' => 'vitals',
            'record_id'   => null,
            'patient_id'  => $patient_info['patient_id'],
            'visit_id'    => $visit_id,
            'description' => "Starting to record new vitals. Temp: " . $temperature . "°C, BP: " . $blood_pressure_systolic . "/" . $blood_pressure_diastolic,
            'status'      => 'ATTEMPT',
            'old_values'  => null,
            'new_values'  => null
        ]);
        
        // Calculate BMI if weight and height provided
        $bmi = null;
        if ($weight && $height) {
            $height_m = $height / 100; // Convert cm to meters
            if ($height_m > 0) {
                $bmi = round($weight / ($height_m * $height_m), 1);
            }
        }
        
        // Get recorded_at date/time
        $recorded_date = !empty($_POST['recorded_date']) ? $_POST['recorded_date'] : date('Y-m-d');
        $recorded_time = !empty($_POST['recorded_time']) ? $_POST['recorded_time'] : date('H:i');
        $recorded_at = $recorded_date . ' ' . $recorded_time;
        
        // Insert vitals
        $insert_sql = "INSERT INTO vitals 
                      (patient_id, visit_id, visit_type, recorded_at, 
                       temperature, pulse, respiration_rate, blood_pressure_systolic, 
                       blood_pressure_diastolic, oxygen_saturation, weight, height, 
                       bmi, remarks) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $insert_stmt = $mysqli->prepare($insert_sql);
        $insert_stmt->bind_param("iisssiiiiiddds",
            $patient_info['patient_id'],
            $visit_id,
            $visit_type,
            $recorded_at,
            $temperature,
            $pulse,
            $respiration_rate,
            $blood_pressure_systolic,
            $blood_pressure_diastolic,
            $oxygen_saturation,
            $weight,
            $height,
            $bmi,
            $remarks
        );
        
        if ($insert_stmt->execute()) {
            $vital_id = $mysqli->insert_id;
            
            // AUDIT LOG: Vitals recorded successfully
            audit_log($mysqli, [
                'user_id'     => $_SESSION['user_id'],
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'ADD_VITALS',
                'module'      => 'Vitals',
                'table_name'  => 'vitals',
                'entity_type' => 'vitals',
                'record_id'   => $vital_id,
                'patient_id'  => $patient_info['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Vitals recorded successfully at " . $recorded_at,
                'status'      => 'SUCCESS',
                'old_values'  => null,
                'new_values'  => [
                    'patient_id' => $patient_info['patient_id'],
                    'visit_id' => $visit_id,
                    'visit_type' => $visit_type,
                    'recorded_at' => $recorded_at,
                    'temperature' => $temperature,
                    'pulse' => $pulse,
                    'respiration_rate' => $respiration_rate,
                    'blood_pressure_systolic' => $blood_pressure_systolic,
                    'blood_pressure_diastolic' => $blood_pressure_diastolic,
                    'oxygen_saturation' => $oxygen_saturation,
                    'weight' => $weight,
                    'height' => $height,
                    'bmi' => $bmi,
                    'remarks' => $remarks
                ]
            ]);
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Vitals recorded successfully";
            header("Location: vitals.php?visit_id=" . $visit_id);
            exit;
        } else {
            $error = "Error recording vitals: " . $mysqli->error;
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = $error;
            
            // AUDIT LOG: Failed to record vitals
            audit_log($mysqli, [
                'user_id'     => $_SESSION['user_id'],
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'ADD_VITALS_FAIL',
                'module'      => 'Vitals',
                'table_name'  => 'vitals',
                'entity_type' => 'vitals',
                'record_id'   => null,
                'patient_id'  => $patient_info['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Failed to record vitals. Error: " . $error,
                'status'      => 'FAILED',
                'old_values'  => null,
                'new_values'  => null
            ]);
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

// Get BMI classification
function getBMIClassification($bmi) {
    if ($bmi === null) return ['class' => 'secondary', 'label' => 'N/A'];
    if ($bmi < 18.5) return ['class' => 'warning', 'label' => 'Underweight'];
    if ($bmi < 25) return ['class' => 'success', 'label' => 'Normal'];
    if ($bmi < 30) return ['class' => 'warning', 'label' => 'Overweight'];
    return ['class' => 'danger', 'label' => 'Obese'];
}

// Get BP classification
function getBPClassification($systolic, $diastolic) {
    if (!$systolic || !$diastolic) return ['class' => 'secondary', 'label' => 'N/A'];
    
    if ($systolic < 120 && $diastolic < 80) {
        return ['class' => 'success', 'label' => 'Normal'];
    } elseif ($systolic < 130 && $diastolic < 80) {
        return ['class' => 'info', 'label' => 'Elevated'];
    } elseif ($systolic < 140 || $diastolic < 90) {
        return ['class' => 'warning', 'label' => 'Stage 1 Hypertension'];
    } else {
        return ['class' => 'danger', 'label' => 'Stage 2 Hypertension'];
    }
}

// Function to get temperature classification
function getTemperatureClassification($temp) {
    if ($temp === null) return ['class' => 'secondary', 'label' => 'N/A'];
    if ($temp < 36.1) return ['class' => 'info', 'label' => 'Hypothermia'];
    if ($temp < 37.2) return ['class' => 'success', 'label' => 'Normal'];
    if ($temp < 38.3) return ['class' => 'warning', 'label' => 'Low-grade Fever'];
    if ($temp < 39.4) return ['class' => 'warning', 'label' => 'Fever'];
    return ['class' => 'danger', 'label' => 'High Fever'];
}

// Function to get pulse classification
function getPulseClassification($pulse) {
    if ($pulse === null) return ['class' => 'secondary', 'label' => 'N/A'];
    if ($pulse < 60) return ['class' => 'warning', 'label' => 'Bradycardia'];
    if ($pulse < 100) return ['class' => 'success', 'label' => 'Normal'];
    return ['class' => 'warning', 'label' => 'Tachycardia'];
}

// Function to get respiration classification
function getRespirationClassification($resp) {
    if ($resp === null) return ['class' => 'secondary', 'label' => 'N/A'];
    if ($resp < 12) return ['class' => 'warning', 'label' => 'Bradypnea'];
    if ($resp < 20) return ['class' => 'success', 'label' => 'Normal'];
    return ['class' => 'warning', 'label' => 'Tachypnea'];
}

// Function to get oxygen saturation classification
function getOxygenClassification($oxygen) {
    if ($oxygen === null) return ['class' => 'secondary', 'label' => 'N/A'];
    if ($oxygen < 90) return ['class' => 'danger', 'label' => 'Low'];
    if ($oxygen < 95) return ['class' => 'warning', 'label' => 'Below Normal'];
    return ['class' => 'success', 'label' => 'Normal'];
}

// Function to format vital values with badges
function formatVitalValue($value, $unit, $classification_func = null) {
    if ($value === null) {
        return '<span class="text-muted">N/A</span>';
    }
    
    $formatted = number_format($value, 1) . ' ' . $unit;
    
    if ($classification_func && is_callable($classification_func)) {
        $classification = $classification_func($value);
        return $formatted . ' <span class="badge badge-' . $classification['class'] . '">' . $classification['label'] . '</span>';
    }
    
    return $formatted;
}

// Get latest vitals for summary display
$latest_vitals = !empty($vitals_list) ? $vitals_list[0] : null;

// Get vital trends (last 5 readings)
$vital_trends = array_slice($vitals_list, 0, 5);
?>
<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0">
            <i class="fas fa-fw fa-heartbeat mr-2"></i>Patient Vitals: <?php echo htmlspecialchars($patient_info['patient_mrn']); ?>
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
                                        <i class="fas fa-history text-primary mr-1"></i>
                                        <span class="badge badge-light"><?php echo count($vitals_list); ?> Records</span>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Add New Vitals Form -->
            <div class="col-md-5">
                <div class="card">
                    <div class="card-header bg-success py-2">
                        <h4 class="card-title mb-0 text-white">
                            <i class="fas fa-plus-circle mr-2"></i>Record New Vitals
                        </h4>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="vitalsForm">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="recorded_date">Date</label>
                                        <input type="date" class="form-control" id="recorded_date" name="recorded_date" 
                                               value="<?php echo date('Y-m-d'); ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="recorded_time">Time</label>
                                        <input type="time" class="form-control" id="recorded_time" name="recorded_time" 
                                               value="<?php echo date('H:i'); ?>" required>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="temperature">Temperature (°C)</label>
                                        <input type="number" class="form-control" id="temperature" name="temperature" 
                                               step="0.1" min="30" max="45" placeholder="e.g., 36.5">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="pulse">Pulse (bpm)</label>
                                        <input type="number" class="form-control" id="pulse" name="pulse" 
                                               min="30" max="250" placeholder="e.g., 72">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="respiration_rate">Respiration (bpm)</label>
                                        <input type="number" class="form-control" id="respiration_rate" name="respiration_rate" 
                                               min="8" max="60" placeholder="e.g., 16">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="oxygen_saturation">SpO₂ (%)</label>
                                        <input type="number" class="form-control" id="oxygen_saturation" name="oxygen_saturation" 
                                               min="50" max="100" placeholder="e.g., 98">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="blood_pressure_systolic">BP Systolic</label>
                                        <input type="number" class="form-control" id="blood_pressure_systolic" 
                                               name="blood_pressure_systolic" min="50" max="250" placeholder="e.g., 120">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="blood_pressure_diastolic">BP Diastolic</label>
                                        <input type="number" class="form-control" id="blood_pressure_diastolic" 
                                               name="blood_pressure_diastolic" min="30" max="150" placeholder="e.g., 80">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="weight">Weight (kg)</label>
                                        <input type="number" class="form-control" id="weight" name="weight" 
                                               step="0.1" min="0.5" max="300" placeholder="e.g., 70.5">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="height">Height (cm)</label>
                                        <input type="number" class="form-control" id="height" name="height" 
                                               step="0.1" min="30" max="250" placeholder="e.g., 175">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="remarks">Remarks</label>
                                <textarea class="form-control" id="remarks" name="remarks" 
                                          rows="2" placeholder="Additional notes..."></textarea>
                            </div>
                            
                            <div class="form-group mb-0">
                                <button type="submit" name="add_vitals" class="btn btn-success btn-lg btn-block">
                                    <i class="fas fa-save mr-2"></i>Save Vitals
                                </button>
                            </div>
                        </form>
                        
                        <!-- BMI Calculator -->
                        <div class="mt-4 p-3 bg-light rounded">
                            <h6><i class="fas fa-calculator mr-2"></i>BMI Calculator</h6>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="input-group input-group-sm mb-2">
                                        <div class="input-group-prepend">
                                            <span class="input-group-text">Weight (kg)</span>
                                        </div>
                                        <input type="number" class="form-control" id="bmi_weight" placeholder="Weight">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="input-group input-group-sm mb-2">
                                        <div class="input-group-prepend">
                                            <span class="input-group-text">Height (cm)</span>
                                        </div>
                                        <input type="number" class="form-control" id="bmi_height" placeholder="Height">
                                    </div>
                                </div>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary btn-block" onclick="calculateBMI()">
                                Calculate BMI
                            </button>
                            <div id="bmi_result" class="mt-2 text-center" style="display: none;">
                                <span class="badge badge-secondary">BMI: <span id="bmi_value"></span></span>
                                <span class="badge badge-success ml-2" id="bmi_category"></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Vitals History -->
            <div class="col-md-7">
                <div class="card">
                    <div class="card-header bg-info py-2">
                        <h4 class="card-title mb-0 text-white">
                            <i class="fas fa-history mr-2"></i>Vitals History
                        </h4>
                        <div class="card-tools">
                            <span class="badge badge-light"><?php echo count($vitals_list); ?> records</span>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <?php if (!empty($vitals_list)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Date/Time</th>
                                            <th>Temp</th>
                                            <th>Pulse</th>
                                            <th>BP</th>
                                            <th>SpO₂</th>
                                            <th>BMI</th>
                                            <th class="text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($vitals_list as $vital): ?>
                                            <?php
                                            $bmi_class = getBMIClassification($vital['bmi']);
                                            $bp_class = getBPClassification($vital['blood_pressure_systolic'], $vital['blood_pressure_diastolic']);
                                            ?>
                                            <tr>
                                                <td>
                                                    <div class="font-weight-bold"><?php echo date('M j', strtotime($vital['recorded_at'])); ?></div>
                                                    <small class="text-muted"><?php echo date('H:i', strtotime($vital['recorded_at'])); ?></small>
                                                </td>
                                                <td>
                                                    <?php if ($vital['temperature']): ?>
                                                        <span class="badge badge-light">
                                                            <?php echo $vital['temperature']; ?>°C
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($vital['pulse']): ?>
                                                        <span class="badge badge-light">
                                                            <?php echo $vital['pulse']; ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($vital['blood_pressure_systolic'] && $vital['blood_pressure_diastolic']): ?>
                                                        <span class="badge badge-<?php echo $bp_class['class']; ?>">
                                                            <?php echo $vital['blood_pressure_systolic']; ?>/<?php echo $vital['blood_pressure_diastolic']; ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($vital['oxygen_saturation']): ?>
                                                        <span class="badge badge-light">
                                                            <?php echo $vital['oxygen_saturation']; ?>%
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($vital['bmi']): ?>
                                                        <span class="badge badge-<?php echo $bmi_class['class']; ?>">
                                                            <?php echo $vital['bmi']; ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <button type="button" class="btn btn-sm btn-info" 
                                                            onclick="viewVitalDetails(<?php echo htmlspecialchars(json_encode($vital)); ?>)"
                                                            title="View Details">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <a href="vitals_edit.php?vital_id=<?php echo $vital['vital_id']; ?>" 
                                                       class="btn btn-sm btn-warning" title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">No Vitals Recorded</h5>
                                <p class="text-muted">No vitals have been recorded for this visit yet.</p>
                                <a href="#vitalsForm" class="btn btn-success">
                                    <i class="fas fa-plus-circle mr-2"></i>Record First Vitals
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Vital Signs Chart -->
                <?php if (count($vitals_list) >= 2): ?>
                <div class="card mt-4">
                    <div class="card-header bg-warning py-2">
                        <h4 class="card-title mb-0">
                            <i class="fas fa-chart-line mr-2"></i>Trend Analysis
                        </h4>
                    </div>
                    <div class="card-body">
                        <canvas id="vitalsChart" height="150"></canvas>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Vital Details Modal -->
<div class="modal fade" id="vitalDetailsModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Vital Signs Details</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body" id="vitalDetailsContent">
                <!-- Content will be loaded dynamically -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
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

    // Initialize date and time pickers
    $('#recorded_date').flatpickr({
        dateFormat: 'Y-m-d',
        maxDate: 'today'
    });
    
    $('#recorded_time').flatpickr({
        enableTime: true,
        noCalendar: true,
        dateFormat: 'H:i',
        time_24hr: true
    });

    // Form validation
    $('#vitalsForm').validate({
        rules: {
            temperature: {
                range: [30, 45]
            },
            pulse: {
                range: [30, 250]
            },
            respiration_rate: {
                range: [8, 60]
            },
            blood_pressure_systolic: {
                range: [50, 250]
            },
            blood_pressure_diastolic: {
                range: [30, 150]
            },
            oxygen_saturation: {
                range: [50, 100]
            },
            weight: {
                range: [0.5, 300]
            },
            height: {
                range: [30, 250]
            }
        },
        messages: {
            temperature: {
                range: "Temperature must be between 30-45°C"
            },
            pulse: {
                range: "Pulse must be between 30-250 bpm"
            }
        }
    });

    // Create chart if there are multiple vitals records
    <?php if (count($vitals_list) >= 2): ?>
    createVitalsChart();
    <?php endif; ?>
});

function calculateBMI() {
    const weight = parseFloat(document.getElementById('bmi_weight').value);
    const height = parseFloat(document.getElementById('bmi_height').value);
    
    if (!weight || !height) {
        alert('Please enter both weight and height');
        return;
    }
    
    const heightM = height / 100;
    const bmi = (weight / (heightM * heightM)).toFixed(1);
    
    let category = '';
    let categoryClass = '';
    
    if (bmi < 18.5) {
        category = 'Underweight';
        categoryClass = 'warning';
    } else if (bmi < 25) {
        category = 'Normal';
        categoryClass = 'success';
    } else if (bmi < 30) {
        category = 'Overweight';
        categoryClass = 'warning';
    } else {
        category = 'Obese';
        categoryClass = 'danger';
    }
    
    document.getElementById('bmi_value').textContent = bmi;
    document.getElementById('bmi_category').textContent = category;
    document.getElementById('bmi_category').className = 'badge badge-' + categoryClass;
    document.getElementById('bmi_result').style.display = 'block';
    
    // Auto-fill the form
    document.getElementById('weight').value = weight;
    document.getElementById('height').value = height;
}

function viewVitalDetails(vital) {
    const modalContent = document.getElementById('vitalDetailsContent');
    const recordedAt = new Date(vital.recorded_at);
    
    let html = `
        <div class="row">
            <div class="col-md-6">
                <div class="card mb-3">
                    <div class="card-header bg-light py-2">
                        <h6 class="mb-0"><i class="fas fa-calendar-alt mr-2"></i>Recorded</h6>
                    </div>
                    <div class="card-body">
                        <div class="text-center">
                            <h3>${recordedAt.toLocaleDateString('en-US', { weekday: 'long' })}</h3>
                            <h1 class="display-4">${recordedAt.getDate()}</h1>
                            <h4>${recordedAt.toLocaleDateString('en-US', { month: 'long', year: 'numeric' })}</h4>
                            <h5 class="text-muted">${recordedAt.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })}</h5>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card mb-3">
                    <div class="card-header bg-light py-2">
                        <h6 class="mb-0"><i class="fas fa-notes-medical mr-2"></i>Vital Signs</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-borderless">
    `;
    
    if (vital.temperature) {
        html += `<tr><th>Temperature:</th><td><span class="badge badge-info">${vital.temperature} °C</span></td></tr>`;
    }
    if (vital.pulse) {
        html += `<tr><th>Pulse:</th><td><span class="badge badge-info">${vital.pulse} bpm</span></td></tr>`;
    }
    if (vital.respiration_rate) {
        html += `<tr><th>Respiration:</th><td><span class="badge badge-info">${vital.respiration_rate} bpm</span></td></tr>`;
    }
    if (vital.blood_pressure_systolic && vital.blood_pressure_diastolic) {
        html += `<tr><th>Blood Pressure:</th><td><span class="badge badge-info">${vital.blood_pressure_systolic}/${vital.blood_pressure_diastolic} mmHg</span></td></tr>`;
    }
    if (vital.oxygen_saturation) {
        html += `<tr><th>SpO₂:</th><td><span class="badge badge-info">${vital.oxygen_saturation}%</span></td></tr>`;
    }
    if (vital.weight) {
        html += `<tr><th>Weight:</th><td><span class="badge badge-info">${vital.weight} kg</span></td></tr>`;
    }
    if (vital.height) {
        html += `<tr><th>Height:</th><td><span class="badge badge-info">${vital.height} cm</span></td></tr>`;
    }
    if (vital.bmi) {
        const bmiClass = vital.bmi < 18.5 ? 'warning' : vital.bmi < 25 ? 'success' : vital.bmi < 30 ? 'warning' : 'danger';
        html += `<tr><th>BMI:</th><td><span class="badge badge-${bmiClass}">${vital.bmi}</span></td></tr>`;
    }
    
    html += `
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    if (vital.remarks) {
        html += `
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header bg-light py-2">
                            <h6 class="mb-0"><i class="fas fa-comment-medical mr-2"></i>Remarks</h6>
                        </div>
                        <div class="card-body">
                            <p class="mb-0">${vital.remarks.replace(/\n/g, '<br>')}</p>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }
    
    modalContent.innerHTML = html;
    $('#vitalDetailsModal').modal('show');
}

<?php if (count($vitals_list) >= 2): ?>
function createVitalsChart() {
    const ctx = document.getElementById('vitalsChart').getContext('2d');
    const vitalsData = <?php echo json_encode($vitals_list); ?>;
    
    // Sort by date (newest first for display, but we want chronological for chart)
    const sortedVitals = [...vitalsData].sort((a, b) => new Date(a.recorded_at) - new Date(b.recorded_at));
    
    const labels = sortedVitals.map(v => {
        const date = new Date(v.recorded_at);
        return `${date.getDate()}/${date.getMonth() + 1} ${date.getHours().toString().padStart(2, '0')}:${date.getMinutes().toString().padStart(2, '0')}`;
    });
    
    const datasets = [];
    
    // Temperature dataset
    if (sortedVitals.some(v => v.temperature)) {
        datasets.push({
            label: 'Temperature (°C)',
            data: sortedVitals.map(v => v.temperature),
            borderColor: '#ff6b6b',
            backgroundColor: 'rgba(255, 107, 107, 0.1)',
            yAxisID: 'y',
            fill: true
        });
    }
    
    // Pulse dataset
    if (sortedVitals.some(v => v.pulse)) {
        datasets.push({
            label: 'Pulse (bpm)',
            data: sortedVitals.map(v => v.pulse),
            borderColor: '#4ecdc4',
            backgroundColor: 'rgba(78, 205, 196, 0.1)',
            yAxisID: 'y',
            fill: true
        });
    }
    
    // Blood Pressure (Systolic) dataset
    if (sortedVitals.some(v => v.blood_pressure_systolic)) {
        datasets.push({
            label: 'BP Systolic',
            data: sortedVitals.map(v => v.blood_pressure_systolic),
            borderColor: '#45b7d1',
            backgroundColor: 'rgba(69, 183, 209, 0.1)',
            yAxisID: 'y',
            fill: true
        });
    }
    
    // SpO2 dataset
    if (sortedVitals.some(v => v.oxygen_saturation)) {
        datasets.push({
            label: 'SpO₂ (%)',
            data: sortedVitals.map(v => v.oxygen_saturation),
            borderColor: '#96ceb4',
            backgroundColor: 'rgba(150, 206, 180, 0.1)',
            yAxisID: 'y1',
            fill: true
        });
    }
    
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: datasets
        },
        options: {
            responsive: true,
            interaction: {
                mode: 'index',
                intersect: false,
            },
            scales: {
                x: {
                    display: true,
                    title: {
                        display: true,
                        text: 'Date/Time'
                    }
                },
                y: {
                    type: 'linear',
                    display: true,
                    position: 'left',
                    title: {
                        display: true,
                        text: 'Temp/Pulse/BP'
                    }
                },
                y1: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    title: {
                        display: true,
                        text: 'SpO₂ (%)'
                    },
                    min: 90,
                    max: 100,
                    grid: {
                        drawOnChartArea: false,
                    },
                }
            }
        }
    });
}
<?php endif; ?>

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + S for save
    if (e.ctrlKey && e.keyCode === 83) {
        e.preventDefault();
        $('#vitalsForm button[type="submit"]').click();
    }
    // Ctrl + N for new (focus on first field)
    if (e.ctrlKey && e.keyCode === 78) {
        e.preventDefault();
        $('#temperature').focus();
    }
    // Ctrl + C for calculator
    if (e.ctrlKey && e.keyCode === 67) {
        e.preventDefault();
        calculateBMI();
    }
    // Escape to go back
    if (e.keyCode === 27) {
        window.history.back();
    }
});
</script>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
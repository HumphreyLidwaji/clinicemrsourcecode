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
        'module'      => 'Ward Rounds',
        'table_name'  => 'visits',
        'entity_type' => 'visit',
        'record_id'   => null,
        'patient_id'  => null,
        'visit_id'    => null,
        'description' => "Attempted to access ward_round.php with invalid visit ID: " . $visit_id,
        'status'      => 'FAILED',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    header("Location: /clinic/dashboard.php");
    exit;
}

// Check if user is a nurse
if (!SimplePermission::any(['nurse_ipd_access', 'nurse_ward_access'])) {
    // AUDIT LOG: Permission denied
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'ACCESS',
        'module'      => 'Ward Rounds',
        'table_name'  => 'visits',
        'entity_type' => 'visit',
        'record_id'   => $visit_id,
        'patient_id'  => null,
        'visit_id'    => $visit_id,
        'description' => "Permission denied for ward rounds access. User lacks nurse_ipd_access or nurse_ward_access permission",
        'status'      => 'DENIED',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    showPermissionDenied();
    exit();
}

// Initialize variables
$patient_info = null;
$visit_info = null;
$ipd_info = null;
$ward_info = null;
$bed_info = null;
$nurse_rounds = [];
$today = date('Y-m-d');
$current_shift = '';

// Determine current shift
$current_hour = date('H');
if ($current_hour >= 6 && $current_hour < 14) {
    $current_shift = 'MORNING';
} elseif ($current_hour >= 14 && $current_hour < 22) {
    $current_shift = 'EVENING';
} else {
    $current_shift = 'NIGHT';
}

// Get visit and patient information
$sql = "SELECT 
            v.*,
            p.*,
            ia.*,
            w.ward_name,
            w.ward_type,
            b.bed_number,
            b.bed_type,
            CONCAT(doc.user_name) as attending_doctor,
            CONCAT(nurse.user_name) as nurse_incharge
        FROM visits v
        JOIN patients p ON v.patient_id = p.patient_id
        LEFT JOIN ipd_admissions ia ON v.visit_id = ia.visit_id AND v.visit_type = 'IPD'
        LEFT JOIN wards w ON ia.ward_id = w.ward_id
        LEFT JOIN beds b ON ia.bed_id = b.bed_id
        LEFT JOIN users doc ON ia.attending_provider_id = doc.user_id
        LEFT JOIN users nurse ON ia.nurse_incharge_id = nurse.user_id
        WHERE v.visit_id = ? AND v.visit_type = 'IPD'";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $visit_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "IPD admission not found";
    
    // AUDIT LOG: IPD admission not found
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'ACCESS',
        'module'      => 'Ward Rounds',
        'table_name'  => 'visits',
        'entity_type' => 'visit',
        'record_id'   => $visit_id,
        'patient_id'  => null,
        'visit_id'    => $visit_id,
        'description' => "IPD admission not found for visit ID " . $visit_id,
        'status'      => 'FAILED',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    header("Location: /ipd/dashboard.php");
    exit;
}

$visit_info = $result->fetch_assoc();
$patient_info = $visit_info;
$ipd_info = $visit_info;
$ward_info = [
    'ward_name' => $visit_info['ward_name'],
    'ward_type' => $visit_info['ward_type']
];
$bed_info = [
    'bed_number' => $visit_info['bed_number'],
    'bed_type' => $visit_info['bed_type']
];

// AUDIT LOG: Successful access to ward rounds page
audit_log($mysqli, [
    'user_id'     => $_SESSION['user_id'] ?? null,
    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
    'action'      => 'VIEW',
    'module'      => 'Ward Rounds',
    'table_name'  => 'visits',
    'entity_type' => 'visit',
    'record_id'   => $visit_id,
    'patient_id'  => $patient_info['patient_id'],
    'visit_id'    => $visit_id,
    'description' => "Accessed ward rounds page for IPD admission. Visit ID: " . $visit_id . ", Patient: " . $patient_info['first_name'] . " " . $patient_info['last_name'] . ", Ward: " . ($ward_info['ward_name'] ?? 'N/A'),
    'status'      => 'SUCCESS',
    'old_values'  => null,
    'new_values'  => null
]);

// Get existing nurse rounds
$rounds_sql = "SELECT 
                    nr.*,
                    CONCAT(nu.user_name) as nurse_name,
                    CONCAT(vu.user_name) as verified_by_name
                FROM nurse_rounds nr
                LEFT JOIN users nu ON nr.nurse_id = nu.user_id
                LEFT JOIN users vu ON nr.verified_by = vu.user_id
                WHERE nr.visit_id = ?
                ORDER BY nr.round_datetime DESC";
$rounds_stmt = $mysqli->prepare($rounds_sql);
$rounds_stmt->bind_param("i", $visit_id);
$rounds_stmt->execute();
$rounds_result = $rounds_stmt->get_result();
$nurse_rounds = $rounds_result->fetch_all(MYSQLI_ASSOC);

// Get today's nurse round for current shift
$today_round = null;
foreach ($nurse_rounds as $round) {
    $round_date = date('Y-m-d', strtotime($round['round_datetime']));
    if ($round_date == $today && $round['round_type'] == $current_shift) {
        $today_round = $round;
        break;
    }
}

// Get latest vitals
$vitals_sql = "SELECT 
                    temperature, pulse, respiration_rate, 
                    blood_pressure_systolic, blood_pressure_diastolic,
                    oxygen_saturation, pain_score, recorded_at
                FROM vitals
                WHERE visit_id = ?
                ORDER BY recorded_at DESC
                LIMIT 1";
$vitals_stmt = $mysqli->prepare($vitals_sql);
$vitals_stmt->bind_param("i", $visit_id);
$vitals_stmt->execute();
$vitals_result = $vitals_stmt->get_result();
$latest_vitals = $vitals_result->fetch_assoc();

// Get pending medications
$meds_sql = "SELECT COUNT(*) as pending_meds 
             FROM drug_administration 
             WHERE visit_id = ? AND status = 'scheduled' 
             AND DATE(time_scheduled) <= CURDATE()";
$meds_stmt = $mysqli->prepare($meds_sql);
$meds_stmt->bind_param("i", $visit_id);
$meds_stmt->execute();
$meds_result = $meds_stmt->get_result();
$pending_meds = $meds_result->fetch_assoc()['pending_meds'] ?? 0;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_round'])) {
        $round_datetime = $_POST['round_date'] . ' ' . $_POST['round_time'];
        $round_type = $_POST['round_type'];
        $temperature = !empty($_POST['temperature']) ? $_POST['temperature'] : null;
        $pulse_rate = !empty($_POST['pulse_rate']) ? $_POST['pulse_rate'] : null;
        $respiratory_rate = !empty($_POST['respiratory_rate']) ? $_POST['respiratory_rate'] : null;
        $blood_pressure_systolic = !empty($_POST['blood_pressure_systolic']) ? $_POST['blood_pressure_systolic'] : null;
        $blood_pressure_diastolic = !empty($_POST['blood_pressure_diastolic']) ? $_POST['blood_pressure_diastolic'] : null;
        $oxygen_saturation = !empty($_POST['oxygen_saturation']) ? $_POST['oxygen_saturation'] : null;
        $pain_score = !empty($_POST['pain_score']) ? $_POST['pain_score'] : null;
        $general_condition = $_POST['general_condition'];
        $level_of_consciousness = $_POST['level_of_consciousness'];
        $oral_intake = !empty($_POST['oral_intake']) ? $_POST['oral_intake'] : null;
        $iv_intake = !empty($_POST['iv_intake']) ? $_POST['iv_intake'] : null;
        $urine_output = !empty($_POST['urine_output']) ? $_POST['urine_output'] : null;
        $stool_output = $_POST['stool_output'];
        $vomiting = $_POST['vomiting'];
        $personal_hygiene = trim($_POST['personal_hygiene'] ?? '');
        $position_change = trim($_POST['position_change'] ?? '');
        $skin_condition = trim($_POST['skin_condition'] ?? '');
        $wound_care = trim($_POST['wound_care'] ?? '');
        $dressing_changes = trim($_POST['dressing_changes'] ?? '');
        $medications_given = trim($_POST['medications_given'] ?? '');
        $iv_fluids = trim($_POST['iv_fluids'] ?? '');
        $observations = trim($_POST['observations'] ?? '');
        $complaints = trim($_POST['complaints'] ?? '');
        $interventions = trim($_POST['interventions'] ?? '');
        $fall_risk_assessment = $_POST['fall_risk_assessment'];
        $pressure_ulcer_risk = $_POST['pressure_ulcer_risk'];
        $safety_precautions = trim($_POST['safety_precautions'] ?? '');
        $patient_education = trim($_POST['patient_education'] ?? '');
        $family_education = trim($_POST['family_education'] ?? '');
        $next_assessment_time = !empty($_POST['next_assessment_time']) ? $_POST['next_assessment_time'] : null;
        $special_instructions = trim($_POST['special_instructions'] ?? '');
        $nurse_id = $_SESSION['user_id'];
        $ipd_admission_id = $ipd_info['ipd_admission_id'];
        
        // Prepare round data for audit log
        $round_data = [
            'round_datetime' => $round_datetime,
            'round_type' => $round_type,
            'temperature' => $temperature,
            'pulse_rate' => $pulse_rate,
            'respiratory_rate' => $respiratory_rate,
            'blood_pressure_systolic' => $blood_pressure_systolic,
            'blood_pressure_diastolic' => $blood_pressure_diastolic,
            'oxygen_saturation' => $oxygen_saturation,
            'pain_score' => $pain_score,
            'general_condition' => $general_condition,
            'level_of_consciousness' => $level_of_consciousness,
            'oral_intake' => $oral_intake,
            'iv_intake' => $iv_intake,
            'urine_output' => $urine_output,
            'stool_output' => $stool_output,
            'vomiting' => $vomiting,
            'personal_hygiene' => $personal_hygiene,
            'position_change' => $position_change,
            'skin_condition' => $skin_condition,
            'wound_care' => $wound_care,
            'dressing_changes' => $dressing_changes,
            'medications_given' => $medications_given,
            'iv_fluids' => $iv_fluids,
            'observations' => $observations,
            'complaints' => $complaints,
            'interventions' => $interventions,
            'fall_risk_assessment' => $fall_risk_assessment,
            'pressure_ulcer_risk' => $pressure_ulcer_risk,
            'safety_precautions' => $safety_precautions,
            'patient_education' => $patient_education,
            'family_education' => $family_education,
            'next_assessment_time' => $next_assessment_time,
            'special_instructions' => $special_instructions
        ];
        
        // Check if round already exists for today's shift
        $check_sql = "SELECT nurse_round_id FROM nurse_rounds 
                     WHERE visit_id = ? AND DATE(round_datetime) = ? 
                     AND round_type = ?";
        $check_stmt = $mysqli->prepare($check_sql);
        $check_date = date('Y-m-d', strtotime($round_datetime));
        $check_stmt->bind_param("iss", $visit_id, $check_date, $round_type);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $existing_round = $check_result->fetch_assoc();
        
        if ($existing_round) {
            // Get current round details for audit log
            $current_round_sql = "SELECT * FROM nurse_rounds WHERE nurse_round_id = ?";
            $current_round_stmt = $mysqli->prepare($current_round_sql);
            $current_round_stmt->bind_param("i", $existing_round['nurse_round_id']);
            $current_round_stmt->execute();
            $current_round_result = $current_round_stmt->get_result();
            $old_round_data = $current_round_result->fetch_assoc();
            
            // AUDIT LOG: Update round attempt
            audit_log($mysqli, [
                'user_id'     => $nurse_id,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'WARD_ROUND_UPDATE',
                'module'      => 'Ward Rounds',
                'table_name'  => 'nurse_rounds',
                'entity_type' => 'nurse_round',
                'record_id'   => $existing_round['nurse_round_id'],
                'patient_id'  => $patient_info['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Attempting to update ward round. Round ID: " . $existing_round['nurse_round_id'] . ", Type: " . $round_type . ", Date: " . $check_date,
                'status'      => 'ATTEMPT',
                'old_values'  => $old_round_data,
                'new_values'  => $round_data
            ]);
            
            // Update existing round
            $update_sql = "UPDATE nurse_rounds SET 
                          round_datetime = ?, 
                          temperature = ?, 
                          pulse_rate = ?, 
                          respiratory_rate = ?, 
                          blood_pressure_systolic = ?,
                          blood_pressure_diastolic = ?,
                          oxygen_saturation = ?,
                          pain_score = ?,
                          general_condition = ?,
                          level_of_consciousness = ?,
                          oral_intake = ?,
                          iv_intake = ?,
                          urine_output = ?,
                          stool_output = ?,
                          vomiting = ?,
                          personal_hygiene = ?,
                          position_change = ?,
                          skin_condition = ?,
                          wound_care = ?,
                          dressing_changes = ?,
                          medications_given = ?,
                          iv_fluids = ?,
                          observations = ?,
                          complaints = ?,
                          interventions = ?,
                          fall_risk_assessment = ?,
                          pressure_ulcer_risk = ?,
                          safety_precautions = ?,
                          patient_education = ?,
                          family_education = ?,
                          next_assessment_time = ?,
                          special_instructions = ?,
                          updated_at = NOW()
                          WHERE nurse_round_id = ?";
            
            $update_stmt = $mysqli->prepare($update_sql);
            $update_stmt->bind_param("sddddddsissiisssssssssssssssssssi",
                $round_datetime,
                $temperature,
                $pulse_rate,
                $respiratory_rate,
                $blood_pressure_systolic,
                $blood_pressure_diastolic,
                $oxygen_saturation,
                $pain_score,
                $general_condition,
                $level_of_consciousness,
                $oral_intake,
                $iv_intake,
                $urine_output,
                $stool_output,
                $vomiting,
                $personal_hygiene,
                $position_change,
                $skin_condition,
                $wound_care,
                $dressing_changes,
                $medications_given,
                $iv_fluids,
                $observations,
                $complaints,
                $interventions,
                $fall_risk_assessment,
                $pressure_ulcer_risk,
                $safety_precautions,
                $patient_education,
                $family_education,
                $next_assessment_time,
                $special_instructions,
                $existing_round['nurse_round_id']
            );
            
            if ($update_stmt->execute()) {
                // AUDIT LOG: Successful round update
                audit_log($mysqli, [
                    'user_id'     => $nurse_id,
                    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                    'action'      => 'WARD_ROUND_UPDATE',
                    'module'      => 'Ward Rounds',
                    'table_name'  => 'nurse_rounds',
                    'entity_type' => 'nurse_round',
                    'record_id'   => $existing_round['nurse_round_id'],
                    'patient_id'  => $patient_info['patient_id'],
                    'visit_id'    => $visit_id,
                    'description' => "Ward round updated successfully. Round ID: " . $existing_round['nurse_round_id'] . ", Type: " . $round_type . ", Date: " . $check_date,
                    'status'      => 'SUCCESS',
                    'old_values'  => $old_round_data,
                    'new_values'  => array_merge($round_data, [
                        'nurse_round_id' => $existing_round['nurse_round_id'],
                        'updated_at' => date('Y-m-d H:i:s')
                    ])
                ]);
                
                $_SESSION['alert_type'] = "success";
                $_SESSION['alert_message'] = "Nurse round updated successfully";
            } else {
                // AUDIT LOG: Failed round update
                audit_log($mysqli, [
                    'user_id'     => $nurse_id,
                    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                    'action'      => 'WARD_ROUND_UPDATE',
                    'module'      => 'Ward Rounds',
                    'table_name'  => 'nurse_rounds',
                    'entity_type' => 'nurse_round',
                    'record_id'   => $existing_round['nurse_round_id'],
                    'patient_id'  => $patient_info['patient_id'],
                    'visit_id'    => $visit_id,
                    'description' => "Failed to update ward round. Error: " . $mysqli->error,
                    'status'      => 'FAILED',
                    'old_values'  => null,
                    'new_values'  => null
                ]);
                
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = "Error updating round: " . $mysqli->error;
            }
        } else {
            // AUDIT LOG: Create round attempt
            audit_log($mysqli, [
                'user_id'     => $nurse_id,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'WARD_ROUND_CREATE',
                'module'      => 'Ward Rounds',
                'table_name'  => 'nurse_rounds',
                'entity_type' => 'nurse_round',
                'record_id'   => null,
                'patient_id'  => $patient_info['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Attempting to create new ward round. Type: " . $round_type . ", Date: " . $check_date,
                'status'      => 'ATTEMPT',
                'old_values'  => null,
                'new_values'  => $round_data
            ]);
            
            // Insert new round
            $insert_sql = "INSERT INTO nurse_rounds 
                          (ipd_admission_id, visit_id, nurse_id, round_datetime, 
                           round_type, temperature, pulse_rate, respiratory_rate,
                           blood_pressure_systolic, blood_pressure_diastolic,
                           oxygen_saturation, pain_score, general_condition,
                           level_of_consciousness, oral_intake, iv_intake,
                           urine_output, stool_output, vomiting, personal_hygiene,
                           position_change, skin_condition, wound_care, dressing_changes,
                           medications_given, iv_fluids, observations, complaints,
                           interventions, fall_risk_assessment, pressure_ulcer_risk,
                           safety_precautions, patient_education, family_education,
                           next_assessment_time, special_instructions, created_by, created_at) 
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 
                                  ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            
            $insert_stmt = $mysqli->prepare($insert_sql);
            $insert_stmt->bind_param("iiisssddddddsissiissssssssssssssssssi",
                $ipd_admission_id,
                $visit_id,
                $nurse_id,
                $round_datetime,
                $round_type,
                $temperature,
                $pulse_rate,
                $respiratory_rate,
                $blood_pressure_systolic,
                $blood_pressure_diastolic,
                $oxygen_saturation,
                $pain_score,
                $general_condition,
                $level_of_consciousness,
                $oral_intake,
                $iv_intake,
                $urine_output,
                $stool_output,
                $vomiting,
                $personal_hygiene,
                $position_change,
                $skin_condition,
                $wound_care,
                $dressing_changes,
                $medications_given,
                $iv_fluids,
                $observations,
                $complaints,
                $interventions,
                $fall_risk_assessment,
                $pressure_ulcer_risk,
                $safety_precautions,
                $patient_education,
                $family_education,
                $next_assessment_time,
                $special_instructions,
                $nurse_id
            );
            
            if ($insert_stmt->execute()) {
                $round_id = $insert_stmt->insert_id;
                
                // AUDIT LOG: Successful round creation
                audit_log($mysqli, [
                    'user_id'     => $nurse_id,
                    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                    'action'      => 'WARD_ROUND_CREATE',
                    'module'      => 'Ward Rounds',
                    'table_name'  => 'nurse_rounds',
                    'entity_type' => 'nurse_round',
                    'record_id'   => $round_id,
                    'patient_id'  => $patient_info['patient_id'],
                    'visit_id'    => $visit_id,
                    'description' => "Ward round created successfully. Round ID: " . $round_id . ", Type: " . $round_type . ", Date: " . $check_date,
                    'status'      => 'SUCCESS',
                    'old_values'  => null,
                    'new_values'  => array_merge($round_data, [
                        'nurse_round_id' => $round_id,
                        'ipd_admission_id' => $ipd_admission_id,
                        'visit_id' => $visit_id,
                        'nurse_id' => $nurse_id,
                        'created_by' => $nurse_id,
                        'created_at' => date('Y-m-d H:i:s')
                    ])
                ]);
                
                $_SESSION['alert_type'] = "success";
                $_SESSION['alert_message'] = "Nurse round recorded successfully";
            } else {
                // AUDIT LOG: Failed round creation
                audit_log($mysqli, [
                    'user_id'     => $nurse_id,
                    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                    'action'      => 'WARD_ROUND_CREATE',
                    'module'      => 'Ward Rounds',
                    'table_name'  => 'nurse_rounds',
                    'entity_type' => 'nurse_round',
                    'record_id'   => null,
                    'patient_id'  => $patient_info['patient_id'],
                    'visit_id'    => $visit_id,
                    'description' => "Failed to create ward round. Error: " . $mysqli->error,
                    'status'      => 'FAILED',
                    'old_values'  => null,
                    'new_values'  => null
                ]);
                
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = "Error saving round: " . $mysqli->error;
            }
        }
        
        header("Location: ward_round.php?visit_id=" . $visit_id);
        exit;
    }
    
    // Handle round deletion (if needed)
    if (isset($_POST['delete_round'])) {
        $round_id = intval($_POST['round_id']);
        
        // Get current round details for audit log
        $current_round_sql = "SELECT * FROM nurse_rounds WHERE nurse_round_id = ?";
        $current_round_stmt = $mysqli->prepare($current_round_sql);
        $current_round_stmt->bind_param("i", $round_id);
        $current_round_stmt->execute();
        $current_round_result = $current_round_stmt->get_result();
        $old_round_data = $current_round_result->fetch_assoc();
        
        if ($old_round_data) {
            // AUDIT LOG: Delete round attempt
            audit_log($mysqli, [
                'user_id'     => $_SESSION['user_id'] ?? null,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'WARD_ROUND_DELETE',
                'module'      => 'Ward Rounds',
                'table_name'  => 'nurse_rounds',
                'entity_type' => 'nurse_round',
                'record_id'   => $round_id,
                'patient_id'  => $patient_info['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Attempting to delete ward round. Round ID: " . $round_id . ", Type: " . $old_round_data['round_type'] . ", Date: " . $old_round_data['round_datetime'],
                'status'      => 'ATTEMPT',
                'old_values'  => $old_round_data,
                'new_values'  => null
            ]);
            
            $delete_sql = "DELETE FROM nurse_rounds WHERE nurse_round_id = ?";
            $delete_stmt = $mysqli->prepare($delete_sql);
            $delete_stmt->bind_param("i", $round_id);
            
            if ($delete_stmt->execute()) {
                // AUDIT LOG: Successful round deletion
                audit_log($mysqli, [
                    'user_id'     => $_SESSION['user_id'] ?? null,
                    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                    'action'      => 'WARD_ROUND_DELETE',
                    'module'      => 'Ward Rounds',
                    'table_name'  => 'nurse_rounds',
                    'entity_type' => 'nurse_round',
                    'record_id'   => $round_id,
                    'patient_id'  => $patient_info['patient_id'],
                    'visit_id'    => $visit_id,
                    'description' => "Ward round deleted successfully. Round ID: " . $round_id . ", Type: " . $old_round_data['round_type'] . ", Date: " . $old_round_data['round_datetime'],
                    'status'      => 'SUCCESS',
                    'old_values'  => $old_round_data,
                    'new_values'  => null
                ]);
                
                $_SESSION['alert_type'] = "success";
                $_SESSION['alert_message'] = "Nurse round deleted successfully";
            } else {
                // AUDIT LOG: Failed round deletion
                audit_log($mysqli, [
                    'user_id'     => $_SESSION['user_id'] ?? null,
                    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                    'action'      => 'WARD_ROUND_DELETE',
                    'module'      => 'Ward Rounds',
                    'table_name'  => 'nurse_rounds',
                    'entity_type' => 'nurse_round',
                    'record_id'   => $round_id,
                    'patient_id'  => $patient_info['patient_id'],
                    'visit_id'    => $visit_id,
                    'description' => "Failed to delete ward round. Error: " . $mysqli->error,
                    'status'      => 'FAILED',
                    'old_values'  => null,
                    'new_values'  => null
                ]);
                
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = "Error deleting round: " . $mysqli->error;
            }
            
            header("Location: ward_round.php?visit_id=" . $visit_id);
            exit;
        }
    }
}

// Calculate age
$age = '';
if (!empty($patient_info['date_of_birth'])) {
    $birthDate = new DateTime($patient_info['date_of_birth']);
    $today_date = new DateTime();
    $age = $today_date->diff($birthDate)->y . ' years';
}

// Calculate days admitted
$days_admitted = 0;
if (!empty($ipd_info['admission_datetime'])) {
    $admission_date = new DateTime($ipd_info['admission_datetime']);
    $today_date = new DateTime();
    $days_admitted = $today_date->diff($admission_date)->days;
}

// Get patient full name
$full_name = $patient_info['first_name'] . ' ' . $patient_info['last_name'];

// Function to get round type badge
function getRoundTypeBadge($type) {
    switch($type) {
        case 'MORNING':
            return '<span class="badge badge-info"><i class="fas fa-sun mr-1"></i>Morning</span>';
        case 'EVENING':
            return '<span class="badge badge-warning"><i class="fas fa-moon mr-1"></i>Evening</span>';
        case 'NIGHT':
            return '<span class="badge badge-dark"><i class="fas fa-star mr-1"></i>Night</span>';
        case 'SPECIAL':
            return '<span class="badge badge-danger"><i class="fas fa-exclamation-circle mr-1"></i>Special</span>';
        default:
            return '<span class="badge badge-secondary">' . $type . '</span>';
    }
}

// Function to get condition badge
function getConditionBadge($condition) {
    switch($condition) {
        case 'STABLE':
            return '<span class="badge badge-success">Stable</span>';
        case 'FAIR':
            return '<span class="badge badge-info">Fair</span>';
        case 'SERIOUS':
            return '<span class="badge badge-warning">Serious</span>';
        case 'CRITICAL':
            return '<span class="badge badge-danger">Critical</span>';
        case 'IMPROVING':
            return '<span class="badge badge-primary">Improving</span>';
        case 'DETERIORATING':
            return '<span class="badge badge-danger">Deteriorating</span>';
        default:
            return '<span class="badge badge-secondary">' . $condition . '</span>';
    }
}
?>
<div class="card">
    <div class="card-header bg-info py-2">
        <h3 class="card-title mt-2 mb-0">
            <i class="fas fa-fw fa-user-nurse mr-2"></i>Nurse Ward Round: <?php echo htmlspecialchars($patient_info['patient_mrn']); ?>
        </h3>
        <div class="card-tools">
            <div class="btn-group">
                <button type="button" class="btn btn-light" onclick="window.history.back()">
                    <i class="fas fa-arrow-left mr-2"></i>Back
                </button>
                <button type="button" class="btn btn-success" onclick="window.print()">
                    <i class="fas fa-print mr-2"></i>Print Round
                </button>
                <a href="/clinic/nurse/vitals.php?visit_id=<?php echo $visit_id; ?>" class="btn btn-primary">
                    <i class="fas fa-heartbeat mr-2"></i>Vitals
                </a>
                <a href="/clinic/nurse/nurse_notes.php?visit_id=<?php echo $visit_id; ?>" class="btn btn-warning">
                    <i class="fas fa-notes-medical mr-2"></i>Notes
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

        <!-- Patient and Admission Info -->
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
                                                <th class="text-muted">Age/Sex:</th>
                                                <td><span class="badge badge-secondary"><?php echo $age ?: 'N/A'; ?> / <?php echo $patient_info['sex']; ?></span></td>
                                            </tr>
                                        </table>
                                    </div>
                                    <div class="col-md-6">
                                        <table class="table table-sm table-borderless mb-0">
                                            <tr>
                                                <th width="40%" class="text-muted">Admission #:</th>
                                                <td><span class="badge badge-warning"><?php echo htmlspecialchars($ipd_info['admission_number']); ?></span></td>
                                            </tr>
                                            <tr>
                                                <th class="text-muted">Location:</th>
                                                <td><?php echo htmlspecialchars($ward_info['ward_name']); ?> (Bed: <?php echo htmlspecialchars($bed_info['bed_number']); ?>)</td>
                                            </tr>
                                            <tr>
                                                <th class="text-muted">Day #:</th>
                                                <td><span class="badge badge-success">Day <?php echo $days_admitted + 1; ?></span></td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 text-right">
                                <div class="mt-2">
                                    <span class="h5">
                                        <i class="fas fa-user-md text-primary mr-1"></i>
                                        <span class="badge badge-light">Dr. <?php echo htmlspecialchars($visit_info['attending_doctor'] ?? 'N/A'); ?></span>
                                    </span>
                                    <br>
                                    <span class="h6">
                                        <i class="fas fa-user-nurse text-success mr-1"></i>
                                        <span class="badge badge-light">Nurse: <?php echo htmlspecialchars($visit_info['nurse_incharge'] ?? 'N/A'); ?></span>
                                    </span>
                                    <br>
                                    <span class="h6">
                                        <i class="fas fa-clipboard-list text-warning mr-1"></i>
                                        <span class="badge badge-light"><?php echo count($nurse_rounds); ?> Rounds</span>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Nurse Round Form -->
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-success py-2">
                        <h4 class="card-title mb-0 text-white">
                            <i class="fas fa-user-nurse mr-2"></i>Ward Round Form
                            <span class="badge badge-light float-right"><?php echo getRoundTypeBadge($current_shift); ?></span>
                        </h4>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="nurseRoundForm">
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="round_type">Shift</label>
                                        <select class="form-control" id="round_type" name="round_type" required>
                                            <option value="MORNING" <?php echo $current_shift == 'MORNING' ? 'selected' : ''; ?>>Morning (6am - 2pm)</option>
                                            <option value="EVENING" <?php echo $current_shift == 'EVENING' ? 'selected' : ''; ?>>Evening (2pm - 10pm)</option>
                                            <option value="NIGHT" <?php echo $current_shift == 'NIGHT' ? 'selected' : ''; ?>>Night (10pm - 6am)</option>
                                            <option value="SPECIAL" <?php echo ($today_round['round_type'] ?? '') == 'SPECIAL' ? 'selected' : ''; ?>>Special Round</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="round_date">Date</label>
                                        <input type="date" class="form-control" id="round_date" name="round_date" 
                                               value="<?php echo $today; ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="round_time">Time</label>
                                        <input type="time" class="form-control" id="round_time" name="round_time" 
                                               value="<?php echo date('H:i'); ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="next_assessment_time">Next Assessment</label>
                                        <input type="time" class="form-control" id="next_assessment_time" name="next_assessment_time" 
                                               value="<?php echo $today_round['next_assessment_time'] ?? ''; ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Vital Signs -->
                            <div class="card mb-3">
                                <div class="card-header bg-primary py-2">
                                    <h6 class="card-title mb-0 text-white">
                                        <i class="fas fa-heartbeat mr-2"></i>Vital Signs
                                    </h6>
                                </div>
                                <div class="card-body p-2">
                                    <div class="row">
                                        <div class="col-md-2">
                                            <div class="form-group mb-1">
                                                <label class="small">Temp (°C)</label>
                                                <input type="number" step="0.1" class="form-control form-control-sm" 
                                                       name="temperature" placeholder="36.5"
                                                       value="<?php echo $today_round['temperature'] ?? ($latest_vitals['temperature'] ?? ''); ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="form-group mb-1">
                                                <label class="small">Pulse</label>
                                                <input type="number" class="form-control form-control-sm" 
                                                       name="pulse_rate" placeholder="72"
                                                       value="<?php echo $today_round['pulse_rate'] ?? ($latest_vitals['pulse_rate'] ?? ''); ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="form-group mb-1">
                                                <label class="small">Resp</label>
                                                <input type="number" class="form-control form-control-sm" 
                                                       name="respiratory_rate" placeholder="16"
                                                       value="<?php echo $today_round['respiratory_rate'] ?? ($latest_vitals['respiratory_rate'] ?? ''); ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-group mb-1">
                                                <label class="small">BP (Sys/Dia)</label>
                                                <div class="input-group input-group-sm">
                                                    <input type="number" class="form-control" 
                                                           name="blood_pressure_systolic" placeholder="120"
                                                           value="<?php echo $today_round['blood_pressure_systolic'] ?? ($latest_vitals['blood_pressure_systolic'] ?? ''); ?>">
                                                    <div class="input-group-prepend input-group-append">
                                                        <span class="input-group-text">/</span>
                                                    </div>
                                                    <input type="number" class="form-control" 
                                                           name="blood_pressure_diastolic" placeholder="80"
                                                           value="<?php echo $today_round['blood_pressure_diastolic'] ?? ($latest_vitals['blood_pressure_diastolic'] ?? ''); ?>">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-group mb-1">
                                                <label class="small">SpO₂ (%)</label>
                                                <input type="number" step="0.1" class="form-control form-control-sm" 
                                                       name="oxygen_saturation" placeholder="98"
                                                       value="<?php echo $today_round['oxygen_saturation'] ?? ($latest_vitals['oxygen_saturation'] ?? ''); ?>">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row mt-1">
                                        <div class="col-md-3">
                                            <div class="form-group mb-1">
                                                <label class="small">Pain Score (0-10)</label>
                                                <input type="number" min="0" max="10" class="form-control form-control-sm" 
                                                       name="pain_score" placeholder="0"
                                                       value="<?php echo $today_round['pain_score'] ?? ($latest_vitals['pain_score'] ?? ''); ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-group mb-1">
                                                <label class="small">General Condition</label>
                                                <select class="form-control form-control-sm" name="general_condition">
                                                    <option value="STABLE" <?php echo ($today_round['general_condition'] ?? '') == 'STABLE' ? 'selected' : ''; ?>>Stable</option>
                                                    <option value="FAIR" <?php echo ($today_round['general_condition'] ?? '') == 'FAIR' ? 'selected' : ''; ?>>Fair</option>
                                                    <option value="SERIOUS" <?php echo ($today_round['general_condition'] ?? '') == 'SERIOUS' ? 'selected' : ''; ?>>Serious</option>
                                                    <option value="CRITICAL" <?php echo ($today_round['general_condition'] ?? '') == 'CRITICAL' ? 'selected' : ''; ?>>Critical</option>
                                                    <option value="IMPROVING" <?php echo ($today_round['general_condition'] ?? '') == 'IMPROVING' ? 'selected' : ''; ?>>Improving</option>
                                                    <option value="DETERIORATING" <?php echo ($today_round['general_condition'] ?? '') == 'DETERIORATING' ? 'selected' : ''; ?>>Deteriorating</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-group mb-1">
                                                <label class="small">Level of Consciousness</label>
                                                <select class="form-control form-control-sm" name="level_of_consciousness">
                                                    <option value="ALERT" <?php echo ($today_round['level_of_consciousness'] ?? '') == 'ALERT' ? 'selected' : ''; ?>>Alert</option>
                                                    <option value="VERBAL" <?php echo ($today_round['level_of_consciousness'] ?? '') == 'VERBAL' ? 'selected' : ''; ?>>Verbal</option>
                                                    <option value="PAIN" <?php echo ($today_round['level_of_consciousness'] ?? '') == 'PAIN' ? 'selected' : ''; ?>>Pain</option>
                                                    <option value="UNRESPONSIVE" <?php echo ($today_round['level_of_consciousness'] ?? '') == 'UNRESPONSIVE' ? 'selected' : ''; ?>>Unresponsive</option>
                                                    <option value="CONFUSED" <?php echo ($today_round['level_of_consciousness'] ?? '') == 'CONFUSED' ? 'selected' : ''; ?>>Confused</option>
                                                    <option value="DROWSY" <?php echo ($today_round['level_of_consciousness'] ?? '') == 'DROWSY' ? 'selected' : ''; ?>>Drowsy</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-group mb-1">
                                                <label class="small">Fall Risk</label>
                                                <select class="form-control form-control-sm" name="fall_risk_assessment">
                                                    <option value="LOW" <?php echo ($today_round['fall_risk_assessment'] ?? '') == 'LOW' ? 'selected' : ''; ?>>Low</option>
                                                    <option value="MODERATE" <?php echo ($today_round['fall_risk_assessment'] ?? '') == 'MODERATE' ? 'selected' : ''; ?>>Moderate</option>
                                                    <option value="HIGH" <?php echo ($today_round['fall_risk_assessment'] ?? '') == 'HIGH' ? 'selected' : ''; ?>>High</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Intake/Output -->
                            <div class="card mb-3">
                                <div class="card-header bg-info py-2">
                                    <h6 class="card-title mb-0 text-white">
                                        <i class="fas fa-tint mr-2"></i>Intake & Output (ml)
                                    </h6>
                                </div>
                                <div class="card-body p-2">
                                    <div class="row">
                                        <div class="col-md-3">
                                            <div class="form-group mb-1">
                                                <label class="small">Oral Intake</label>
                                                <input type="number" class="form-control form-control-sm" 
                                                       name="oral_intake" placeholder="500"
                                                       value="<?php echo $today_round['oral_intake'] ?? ''; ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-group mb-1">
                                                <label class="small">IV Intake</label>
                                                <input type="number" class="form-control form-control-sm" 
                                                       name="iv_intake" placeholder="1000"
                                                       value="<?php echo $today_round['iv_intake'] ?? ''; ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-group mb-1">
                                                <label class="small">Urine Output</label>
                                                <input type="number" class="form-control form-control-sm" 
                                                       name="urine_output" placeholder="800"
                                                       value="<?php echo $today_round['urine_output'] ?? ''; ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-group mb-1">
                                                <label class="small">Stool</label>
                                                <select class="form-control form-control-sm" name="stool_output">
                                                    <option value="NORMAL" <?php echo ($today_round['stool_output'] ?? '') == 'NORMAL' ? 'selected' : ''; ?>>Normal</option>
                                                    <option value="DIARRHEA" <?php echo ($today_round['stool_output'] ?? '') == 'DIARRHEA' ? 'selected' : ''; ?>>Diarrhea</option>
                                                    <option value="CONSTIPATED" <?php echo ($today_round['stool_output'] ?? '') == 'CONSTIPATED' ? 'selected' : ''; ?>>Constipated</option>
                                                    <option value="NONE" <?php echo ($today_round['stool_output'] ?? '') == 'NONE' ? 'selected' : ''; ?>>None</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row mt-1">
                                        <div class="col-md-3">
                                            <div class="form-group mb-1">
                                                <label class="small">Vomiting</label>
                                                <select class="form-control form-control-sm" name="vomiting">
                                                    <option value="NONE" <?php echo ($today_round['vomiting'] ?? '') == 'NONE' ? 'selected' : ''; ?>>None</option>
                                                    <option value="OCCASIONAL" <?php echo ($today_round['vomiting'] ?? '') == 'OCCASIONAL' ? 'selected' : ''; ?>>Occasional</option>
                                                    <option value="FREQUENT" <?php echo ($today_round['vomiting'] ?? '') == 'FREQUENT' ? 'selected' : ''; ?>>Frequent</option>
                                                    <option value="PROJECTILE" <?php echo ($today_round['vomiting'] ?? '') == 'PROJECTILE' ? 'selected' : ''; ?>>Projectile</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-group mb-1">
                                                <label class="small">Pressure Ulcer Risk</label>
                                                <select class="form-control form-control-sm" name="pressure_ulcer_risk">
                                                    <option value="LOW" <?php echo ($today_round['pressure_ulcer_risk'] ?? '') == 'LOW' ? 'selected' : ''; ?>>Low</option>
                                                    <option value="MODERATE" <?php echo ($today_round['pressure_ulcer_risk'] ?? '') == 'MODERATE' ? 'selected' : ''; ?>>Moderate</option>
                                                    <option value="HIGH" <?php echo ($today_round['pressure_ulcer_risk'] ?? '') == 'HIGH' ? 'selected' : ''; ?>>High</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Nursing Care -->
                            <div class="card mb-3">
                                <div class="card-header bg-warning py-2">
                                    <h6 class="card-title mb-0 text-white">
                                        <i class="fas fa-hands-helping mr-2"></i>Nursing Care
                                    </h6>
                                </div>
                                <div class="card-body p-2">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group mb-1">
                                                <label class="small">Personal Hygiene</label>
                                                <textarea class="form-control form-control-sm" name="personal_hygiene" 
                                                          rows="2" placeholder="Bathing, oral care, grooming..."><?php echo $today_round['personal_hygiene'] ?? ''; ?></textarea>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group mb-1">
                                                <label class="small">Position Changes</label>
                                                <textarea class="form-control form-control-sm" name="position_change" 
                                                          rows="2" placeholder="Turning schedule, mobility..."><?php echo $today_round['position_change'] ?? ''; ?></textarea>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group mb-1">
                                                <label class="small">Skin Condition</label>
                                                <textarea class="form-control form-control-sm" name="skin_condition" 
                                                          rows="2" placeholder="Pressure areas, rashes, wounds..."><?php echo $today_round['skin_condition'] ?? ''; ?></textarea>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group mb-1">
                                                <label class="small">Wound Care</label>
                                                <textarea class="form-control form-control-sm" name="wound_care" 
                                                          rows="2" placeholder="Wound assessment, dressing..."><?php echo $today_round['wound_care'] ?? ''; ?></textarea>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group mb-1">
                                                <label class="small">Dressing Changes</label>
                                                <textarea class="form-control form-control-sm" name="dressing_changes" 
                                                          rows="2" placeholder="Dressing changes performed..."><?php echo $today_round['dressing_changes'] ?? ''; ?></textarea>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group mb-1">
                                                <label class="small">Safety Precautions</label>
                                                <textarea class="form-control form-control-sm" name="safety_precautions" 
                                                          rows="2" placeholder="Side rails, restraints, alarms..."><?php echo $today_round['safety_precautions'] ?? ''; ?></textarea>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Medications & Treatments -->
                            <div class="card mb-3">
                                <div class="card-header bg-danger py-2">
                                    <h6 class="card-title mb-0 text-white">
                                        <i class="fas fa-pills mr-2"></i>Medications & Treatments
                                    </h6>
                                </div>
                                <div class="card-body p-2">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group mb-1">
                                                <label class="small">Medications Given</label>
                                                <textarea class="form-control form-control-sm" name="medications_given" 
                                                          rows="3" placeholder="Medications administered this shift..."><?php echo $today_round['medications_given'] ?? ''; ?></textarea>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group mb-1">
                                                <label class="small">IV Fluids</label>
                                                <textarea class="form-control form-control-sm" name="iv_fluids" 
                                                          rows="3" placeholder="IV fluids, rate, site condition..."><?php echo $today_round['iv_fluids'] ?? ''; ?></textarea>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Observations & Interventions -->
                            <div class="card mb-3">
                                <div class="card-header bg-secondary py-2">
                                    <h6 class="card-title mb-0 text-white">
                                        <i class="fas fa-clipboard-check mr-2"></i>Observations & Interventions
                                    </h6>
                                </div>
                                <div class="card-body p-2">
                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="form-group mb-1">
                                                <label class="small">Observations</label>
                                                <textarea class="form-control form-control-sm" name="observations" 
                                                          rows="3" placeholder="General observations..."><?php echo $today_round['observations'] ?? ''; ?></textarea>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-group mb-1">
                                                <label class="small">Patient Complaints</label>
                                                <textarea class="form-control form-control-sm" name="complaints" 
                                                          rows="3" placeholder="Patient's complaints..."><?php echo $today_round['complaints'] ?? ''; ?></textarea>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-group mb-1">
                                                <label class="small">Nursing Interventions</label>
                                                <textarea class="form-control form-control-sm" name="interventions" 
                                                          rows="3" placeholder="Interventions performed..."><?php echo $today_round['interventions'] ?? ''; ?></textarea>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Education & Instructions -->
                            <div class="card mb-3">
                                <div class="card-header bg-success py-2">
                                    <h6 class="card-title mb-0 text-white">
                                        <i class="fas fa-graduation-cap mr-2"></i>Education & Instructions
                                    </h6>
                                </div>
                                <div class="card-body p-2">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group mb-1">
                                                <label class="small">Patient Education</label>
                                                <textarea class="form-control form-control-sm" name="patient_education" 
                                                          rows="2" placeholder="Education provided to patient..."><?php echo $today_round['patient_education'] ?? ''; ?></textarea>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group mb-1">
                                                <label class="small">Family Education</label>
                                                <textarea class="form-control form-control-sm" name="family_education" 
                                                          rows="2" placeholder="Education provided to family..."><?php echo $today_round['family_education'] ?? ''; ?></textarea>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-12">
                                            <div class="form-group mb-1">
                                                <label class="small">Special Instructions</label>
                                                <textarea class="form-control form-control-sm" name="special_instructions" 
                                                          rows="2" placeholder="Special instructions for next shift..."><?php echo $today_round['special_instructions'] ?? ''; ?></textarea>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Action Buttons -->
                            <div class="form-group mt-3">
                                <div class="btn-group btn-block" role="group">
                                    <button type="submit" name="save_round" class="btn btn-success btn-lg flex-fill">
                                        <i class="fas fa-save mr-2"></i>Save Ward Round
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Rounds History & Quick Info -->
            <div class="col-md-4">
                <!-- Rounds History -->
                <div class="card">
                    <div class="card-header bg-info py-2">
                        <h4 class="card-title mb-0 text-white">
                            <i class="fas fa-history mr-2"></i>Ward Rounds History
                            <span class="badge badge-light float-right"><?php echo count($nurse_rounds); ?> rounds</span>
                        </h4>
                    </div>
                    <div class="card-body p-0" style="max-height: 400px; overflow-y: auto;">
                        <?php if (!empty($nurse_rounds)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Date/Time</th>
                                            <th>Shift</th>
                                            <th>Condition</th>
                                            <th class="text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $current_date = null;
                                        foreach ($nurse_rounds as $round): 
                                            $round_datetime = new DateTime($round['round_datetime']);
                                            $is_today = ($round_datetime->format('Y-m-d') == $today);
                                            $row_class = $is_today ? 'table-info' : '';
                                            
                                            if ($current_date != $round_datetime->format('Y-m-d')) {
                                                $current_date = $round_datetime->format('Y-m-d');
                                                $date_display = $round_datetime->format('M j, Y');
                                                if ($is_today) {
                                                    $date_display = '<strong>Today</strong>';
                                                }
                                        ?>
                                            <tr class="bg-light">
                                                <td colspan="4" class="font-weight-bold">
                                                    <i class="fas fa-calendar-day mr-2"></i><?php echo $date_display; ?>
                                                </td>
                                            </tr>
                                        <?php } ?>
                                            <tr class="<?php echo $row_class; ?>">
                                                <td>
                                                    <div>
                                                        <?php echo getRoundTypeBadge($round['round_type']); ?>
                                                    </div>
                                                    <small class="text-muted">
                                                        <?php echo $round_datetime->format('H:i'); ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($round['round_type']); ?>
                                                </td>
                                                <td>
                                                    <?php echo getConditionBadge($round['general_condition']); ?>
                                                </td>
                                                <td class="text-center">
                                                    <button type="button" class="btn btn-sm btn-info" 
                                                            onclick="viewRoundDetails(<?php echo htmlspecialchars(json_encode($round)); ?>)"
                                                            title="View Details">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-user-nurse fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">No Ward Rounds</h5>
                                <p class="text-muted">No ward rounds have been recorded for this admission.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Quick Stats -->
                <div class="card mt-4">
                    <div class="card-header bg-warning py-2">
                        <h6 class="card-title mb-0 text-white">
                            <i class="fas fa-chart-bar mr-2"></i>Quick Stats
                        </h6>
                    </div>
                    <div class="card-body p-2">
                        <div class="row text-center">
                            <div class="col-4">
                                <div class="text-muted">Days Admitted</div>
                                <div class="h4"><?php echo $days_admitted + 1; ?></div>
                            </div>
                            <div class="col-4">
                                <div class="text-muted">Pending Meds</div>
                                <div class="h4 text-danger"><?php echo $pending_meds; ?></div>
                            </div>
                            <div class="col-4">
                                <div class="text-muted">Rounds Today</div>
                                <?php 
                                $rounds_today = array_filter($nurse_rounds, function($r) use ($today) {
                                    return date('Y-m-d', strtotime($r['round_datetime'])) == $today;
                                });
                                ?>
                                <div class="h4 text-success"><?php echo count($rounds_today); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Latest Vitals -->
                <div class="card mt-4">
                    <div class="card-header bg-primary py-2">
                        <h6 class="card-title mb-0 text-white">
                            <i class="fas fa-heartbeat mr-2"></i>Latest Vital Signs
                        </h6>
                    </div>
                    <div class="card-body p-2">
                        <?php if ($latest_vitals): ?>
                            <div class="text-center">
                                <div class="row">
                                    <div class="col-3">
                                        <div class="mb-2">
                                            <div class="text-muted small">Temp</div>
                                            <div class="h5"><?php echo $latest_vitals['temperature'] ?? '--'; ?>°C</div>
                                        </div>
                                    </div>
                                    <div class="col-3">
                                        <div class="mb-2">
                                            <div class="text-muted small">Pulse</div>
                                            <div class="h5"><?php echo $latest_vitals['pulse_rate'] ?? '--'; ?></div>
                                        </div>
                                    </div>
                                    <div class="col-3">
                                        <div class="mb-2">
                                            <div class="text-muted small">Resp</div>
                                            <div class="h5"><?php echo $latest_vitals['respiratory_rate'] ?? '--'; ?></div>
                                        </div>
                                    </div>
                                    <div class="col-3">
                                        <div class="mb-2">
                                            <div class="text-muted small">BP</div>
                                            <div class="h5">
                                                <?php 
                                                if ($latest_vitals['blood_pressure_systolic'] && $latest_vitals['blood_pressure_diastolic']) {
                                                    echo $latest_vitals['blood_pressure_systolic'] . '/' . $latest_vitals['blood_pressure_diastolic'];
                                                } else {
                                                    echo '--';
                                                }
                                                ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <small class="text-muted">
                                    Last recorded: <?php echo date('H:i', strtotime($latest_vitals['recorded_at'])); ?>
                                </small>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-3">
                                <i class="fas fa-heartbeat fa-2x text-muted mb-2"></i>
                                <p class="text-muted">No vital signs recorded</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Round Details Modal -->
<div class="modal fade" id="roundDetailsModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Ward Round Details</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body" id="roundDetailsContent">
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

    // Initialize date pickers
    $('#round_date').flatpickr({
        dateFormat: 'Y-m-d',
        maxDate: 'today'
    });

    // Auto-expand textareas
    $('textarea').on('input', function() {
        this.style.height = 'auto';
        this.style.height = (this.scrollHeight) + 'px';
    }).trigger('input');

    // Calculate totals
    function calculateTotals() {
        const oral = parseFloat($('input[name="oral_intake"]').val()) || 0;
        const iv = parseFloat($('input[name="iv_intake"]').val()) || 0;
        const urine = parseFloat($('input[name="urine_output"]').val()) || 0;
        
        const totalIntake = oral + iv;
        const balance = totalIntake - urine;
        
        // You could display these somewhere if needed
        console.log(`Intake: ${totalIntake}ml, Output: ${urine}ml, Balance: ${balance}ml`);
    }

    // Recalculate when intake/output changes
    $('input[name="oral_intake"], input[name="iv_intake"], input[name="urine_output"]').on('input', calculateTotals);
});

function viewRoundDetails(round) {
    const modalContent = document.getElementById('roundDetailsContent');
    const roundDate = new Date(round.round_datetime);
    
    let html = `
        <div class="card">
            <div class="card-header bg-light py-2">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0">
                            ${getRoundTypeBadge(round.round_type)} 
                            <span class="ml-2">${roundDate.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })}</span>
                        </h6>
                        <small class="text-muted">
                            ${roundDate.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})} • 
                            Nurse: ${round.nurse_name || 'N/A'}
                            ${round.verified_by_name ? ` • Verified by: ${round.verified_by_name}` : ''}
                        </small>
                    </div>
                    <div>
                        ${getConditionBadge(round.general_condition)}
                    </div>
                </div>
            </div>
            <div class="card-body">
    `;
    
    // Vital Signs
    if (round.temperature || round.pulse_rate || round.respiratory_rate || 
        round.blood_pressure_systolic || round.oxygen_saturation) {
        html += `<div class="row mb-3">
                    <div class="col-md-12">
                        <h6 class="text-primary"><i class="fas fa-heartbeat mr-2"></i>Vital Signs</h6>
                        <div class="p-2 bg-light rounded">
                            <div class="row">
                                <div class="col-3">
                                    <small class="text-muted">Temp:</small>
                                    <div>${round.temperature || '--'}°C</div>
                                </div>
                                <div class="col-3">
                                    <small class="text-muted">Pulse:</small>
                                    <div>${round.pulse_rate || '--'}</div>
                                </div>
                                <div class="col-3">
                                    <small class="text-muted">Resp:</small>
                                    <div>${round.respiratory_rate || '--'}</div>
                                </div>
                                <div class="col-3">
                                    <small class="text-muted">BP:</small>
                                    <div>${round.blood_pressure_systolic || '--'}/${round.blood_pressure_diastolic || '--'}</div>
                                </div>
                            </div>
                            <div class="row mt-2">
                                <div class="col-3">
                                    <small class="text-muted">SpO₂:</small>
                                    <div>${round.oxygen_saturation || '--'}%</div>
                                </div>
                                <div class="col-3">
                                    <small class="text-muted">Pain:</small>
                                    <div>${round.pain_score || '--'}/10</div>
                                </div>
                                <div class="col-3">
                                    <small class="text-muted">LOC:</small>
                                    <div>${round.level_of_consciousness || '--'}</div>
                                </div>
                                <div class="col-3">
                                    <small class="text-muted">Fall Risk:</small>
                                    <div>${round.fall_risk_assessment || '--'}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>`;
    }
    
    // Intake/Output
    if (round.oral_intake || round.iv_intake || round.urine_output) {
        html += `<div class="row mb-3">
                    <div class="col-md-12">
                        <h6 class="text-info"><i class="fas fa-tint mr-2"></i>Intake & Output</h6>
                        <div class="p-2 bg-light rounded">
                            <div class="row">
                                <div class="col-4">
                                    <small class="text-muted">Oral Intake:</small>
                                    <div>${round.oral_intake || '0'} ml</div>
                                </div>
                                <div class="col-4">
                                    <small class="text-muted">IV Intake:</small>
                                    <div>${round.iv_intake || '0'} ml</div>
                                </div>
                                <div class="col-4">
                                    <small class="text-muted">Urine Output:</small>
                                    <div>${round.urine_output || '0'} ml</div>
                                </div>
                            </div>
                            <div class="row mt-2">
                                <div class="col-4">
                                    <small class="text-muted">Stool:</small>
                                    <div>${round.stool_output || '--'}</div>
                                </div>
                                <div class="col-4">
                                    <small class="text-muted">Vomiting:</small>
                                    <div>${round.vomiting || '--'}</div>
                                </div>
                                <div class="col-4">
                                    <small class="text-muted">Pressure Risk:</small>
                                    <div>${round.pressure_ulcer_risk || '--'}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>`;
    }
    
    // Nursing Care
    if (round.personal_hygiene || round.position_change || round.skin_condition || 
        round.wound_care || round.dressing_changes || round.safety_precautions) {
        html += `<div class="row mb-3">
                    <div class="col-md-12">
                        <h6 class="text-warning"><i class="fas fa-hands-helping mr-2"></i>Nursing Care</h6>
                        <div class="p-2 bg-light rounded">`;
        
        if (round.personal_hygiene) {
            html += `<div class="mb-2"><strong>Personal Hygiene:</strong><br>${round.personal_hygiene.replace(/\n/g, '<br>')}</div>`;
        }
        if (round.position_change) {
            html += `<div class="mb-2"><strong>Position Changes:</strong><br>${round.position_change.replace(/\n/g, '<br>')}</div>`;
        }
        if (round.skin_condition) {
            html += `<div class="mb-2"><strong>Skin Condition:</strong><br>${round.skin_condition.replace(/\n/g, '<br>')}</div>`;
        }
        if (round.wound_care) {
            html += `<div class="mb-2"><strong>Wound Care:</strong><br>${round.wound_care.replace(/\n/g, '<br>')}</div>`;
        }
        if (round.dressing_changes) {
            html += `<div class="mb-2"><strong>Dressing Changes:</strong><br>${round.dressing_changes.replace(/\n/g, '<br>')}</div>`;
        }
        if (round.safety_precautions) {
            html += `<div class="mb-2"><strong>Safety Precautions:</strong><br>${round.safety_precautions.replace(/\n/g, '<br>')}</div>`;
        }
        
        html += `</div></div></div>`;
    }
    
    // Medications
    if (round.medications_given || round.iv_fluids) {
        html += `<div class="row mb-3">
                    <div class="col-md-12">
                        <h6 class="text-danger"><i class="fas fa-pills mr-2"></i>Medications & Treatments</h6>
                        <div class="p-2 bg-light rounded">`;
        
        if (round.medications_given) {
            html += `<div class="mb-2"><strong>Medications Given:</strong><br>${round.medications_given.replace(/\n/g, '<br>')}</div>`;
        }
        if (round.iv_fluids) {
            html += `<div class="mb-2"><strong>IV Fluids:</strong><br>${round.iv_fluids.replace(/\n/g, '<br>')}</div>`;
        }
        
        html += `</div></div></div>`;
    }
    
    // Observations
    if (round.observations || round.complaints || round.interventions) {
        html += `<div class="row mb-3">
                    <div class="col-md-12">
                        <h6 class="text-secondary"><i class="fas fa-clipboard-check mr-2"></i>Observations & Interventions</h6>
                        <div class="p-2 bg-light rounded">`;
        
        if (round.observations) {
            html += `<div class="mb-2"><strong>Observations:</strong><br>${round.observations.replace(/\n/g, '<br>')}</div>`;
        }
        if (round.complaints) {
            html += `<div class="mb-2"><strong>Patient Complaints:</strong><br>${round.complaints.replace(/\n/g, '<br>')}</div>`;
        }
        if (round.interventions) {
            html += `<div class="mb-2"><strong>Nursing Interventions:</strong><br>${round.interventions.replace(/\n/g, '<br>')}</div>`;
        }
        
        html += `</div></div></div>`;
    }
    
    // Education
    if (round.patient_education || round.family_education || round.special_instructions) {
        html += `<div class="row mb-3">
                    <div class="col-md-12">
                        <h6 class="text-success"><i class="fas fa-graduation-cap mr-2"></i>Education & Instructions</h6>
                        <div class="p-2 bg-light rounded">`;
        
        if (round.patient_education) {
            html += `<div class="mb-2"><strong>Patient Education:</strong><br>${round.patient_education.replace(/\n/g, '<br>')}</div>`;
        }
        if (round.family_education) {
            html += `<div class="mb-2"><strong>Family Education:</strong><br>${round.family_education.replace(/\n/g, '<br>')}</div>`;
        }
        if (round.special_instructions) {
            html += `<div class="mb-2"><strong>Special Instructions:</strong><br>${round.special_instructions.replace(/\n/g, '<br>')}</div>`;
        }
        if (round.next_assessment_time) {
            html += `<div class="mb-2"><strong>Next Assessment:</strong><br>${round.next_assessment_time}</div>`;
        }
        
        html += `</div></div></div>`;
    }
    
    html += `   </div>
            <div class="card-footer">
                <small class="text-muted">
                    Recorded: ${new Date(round.created_at).toLocaleString()}
                </small>
            </div>
        </div>`;
    
    modalContent.innerHTML = html;
    $('#roundDetailsModal').modal('show');
}

// Print styles
<style>
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
    .p-2, .p-3 {
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
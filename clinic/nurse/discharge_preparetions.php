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
        'module'      => 'Discharge Preparations',
        'table_name'  => 'visits',
        'entity_type' => 'visit',
        'record_id'   => null,
        'patient_id'  => null,
        'visit_id'    => null,
        'description' => "Attempted to access discharge_preparations.php with invalid visit ID: " . $visit_id,
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
$ipd_info = null;
$preparation = null;
$checklist_items = [];
$discharge_medications = [];
$discharge_documents = [];
$today = date('Y-m-d');

// Get visit and patient information
$visit_sql = "SELECT v.*, 
             p.patient_id, p.patient_mrn, p.first_name, p.middle_name, p.last_name,
             p.date_of_birth, p.sex, p.phone_primary, p.email,
             p.blood_group,
             u.user_name as provider_name,
             d.department_name
             FROM visits v
             JOIN patients p ON v.patient_id = p.patient_id
             JOIN users u ON v.attending_provider_id = u.user_id
             LEFT JOIN departments d ON v.department_id = d.department_id
             WHERE v.visit_id = ? 
             AND v.visit_status = 'ACTIVE'";
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
        'module'      => 'Discharge Preparations',
        'table_name'  => 'visits',
        'entity_type' => 'visit',
        'record_id'   => $visit_id,
        'patient_id'  => null,
        'visit_id'    => $visit_id,
        'description' => "Attempted to access discharge preparations for visit ID " . $visit_id . " but visit not found",
        'status'      => 'FAILED',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    header("Location: /clinic/dashboard.php");
    exit;
}

$visit_info = $visit_result->fetch_assoc();
$patient_info = $visit_info;

// Check if visit is IPD (required for discharge)
if ($visit_info['visit_type'] !== 'IPD') {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Patient must be currently admitted (IPD) for discharge preparations";
    
    // AUDIT LOG: Not IPD visit
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'ACCESS',
        'module'      => 'Discharge Preparations',
        'table_name'  => 'visits',
        'entity_type' => 'visit',
        'record_id'   => $visit_id,
        'patient_id'  => $visit_info['patient_id'],
        'visit_id'    => $visit_id,
        'description' => "Attempted discharge preparations for non-IPD visit. Visit type: " . $visit_info['visit_type'],
        'status'      => 'FAILED',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    header("Location: /clinic/dashboard.php");
    exit;
}

// Get IPD admission details
$ipd_sql = "SELECT ia.*, 
            w.ward_name, w.ward_type,
            b.bed_number, b.bed_type, b.bed_status as bed_status,
            adm.user_name as admitting_provider_name,
            att.user_name as attending_provider_name,
            nurse.user_name as nurse_incharge_name,
            TIMESTAMPDIFF(DAY, ia.admission_datetime, CURDATE()) as length_of_stay
            FROM ipd_admissions ia
            LEFT JOIN wards w ON ia.ward_id = w.ward_id
            LEFT JOIN beds b ON ia.bed_id = b.bed_id
            LEFT JOIN users adm ON ia.admitting_provider_id = adm.user_id
            LEFT JOIN users att ON ia.attending_provider_id = att.user_id
            LEFT JOIN users nurse ON ia.nurse_incharge_id = nurse.user_id
            WHERE ia.visit_id = ? AND ia.discharge_datetime IS NULL";
$ipd_stmt = $mysqli->prepare($ipd_sql);
$ipd_stmt->bind_param("i", $visit_id);
$ipd_stmt->execute();
$ipd_result = $ipd_stmt->get_result();

if ($ipd_result->num_rows === 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Patient must be currently admitted (IPD) for discharge preparations";
    
    // AUDIT LOG: IPD admission not found
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'ACCESS',
        'module'      => 'Discharge Preparations',
        'table_name'  => 'ipd_admissions',
        'entity_type' => 'ipd_admission',
        'record_id'   => null,
        'patient_id'  => $visit_info['patient_id'],
        'visit_id'    => $visit_id,
        'description' => "IPD admission not found or already discharged for visit ID " . $visit_id,
        'status'      => 'FAILED',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    header("Location: /clinic/dashboard.php");
    exit;
}

$ipd_info = $ipd_result->fetch_assoc();

// Get existing discharge preparation
$prep_sql = "SELECT dp.*, 
            u1.user_name as created_by_name,
            u2.user_name as discharged_by_name,
            u3.user_name as clearance_doctor_name,
            u4.user_name as followup_doctor_name
            FROM discharge_preparations dp
            LEFT JOIN users u1 ON dp.created_by = u1.user_id
            LEFT JOIN users u2 ON dp.discharged_by = u2.user_id
            LEFT JOIN users u3 ON dp.clearance_physician_id = u3.user_id
            LEFT JOIN users u4 ON dp.followup_physician_id = u4.user_id
            WHERE dp.visit_id = ?";
$prep_stmt = $mysqli->prepare($prep_sql);
$prep_stmt->bind_param("i", $visit_id);
$prep_stmt->execute();
$prep_result = $prep_stmt->get_result();

if ($prep_result->num_rows > 0) {
    $preparation = $prep_result->fetch_assoc();
    
    // Get checklist items
    $checklist_sql = "SELECT dci.*, u.user_name as assigned_to_name,
                     uc.user_name as completed_by_name
                     FROM discharge_checklist_items dci
                     LEFT JOIN users u ON dci.assigned_to = u.user_id
                     LEFT JOIN users uc ON dci.completed_by = uc.user_id
                     WHERE dci.preparation_id = ?
                     ORDER BY 
                       CASE dci.checklist_category 
                           WHEN 'medical' THEN 1
                           WHEN 'medications' THEN 2
                           WHEN 'documentation' THEN 3
                           WHEN 'financial' THEN 4
                           WHEN 'transportation' THEN 5
                           WHEN 'education' THEN 6
                           WHEN 'belongings' THEN 7
                           ELSE 8
                       END,
                       dci.item_id";
    $checklist_stmt = $mysqli->prepare($checklist_sql);
    $checklist_stmt->bind_param("i", $preparation['preparation_id']);
    $checklist_stmt->execute();
    $checklist_result = $checklist_stmt->get_result();
    $checklist_items = $checklist_result->fetch_all(MYSQLI_ASSOC);
    
    // Get discharge medications from prescriptions
    $meds_sql = "
        SELECT 
            pr.prescription_id,
            pr.prescription_date,
            pr.prescription_status,
            pr.prescription_notes,
            pr.prescription_instructions,
            pr.prescription_dispensed_at,

            pi.pi_id,
            pi.pi_quantity,
            pi.pi_dispensed_quantity,
            pi.pi_dosage,
            pi.pi_frequency,
            pi.pi_duration,
            pi.pi_duration_unit,
            pi.pi_instructions,
            pi.pi_unit_price,
            pi.pi_total_price,

            d.user_name AS prescribed_by_name,
            ph.user_name AS dispensed_by_name

        FROM prescriptions pr
        INNER JOIN prescription_items pi 
            ON pi.pi_prescription_id = pr.prescription_id

        LEFT JOIN users d 
            ON pr.prescription_doctor_id = d.user_id

        LEFT JOIN users ph 
            ON pr.prescription_dispensed_by = ph.user_id

        WHERE pr.prescription_visit_id = ?
          AND pr.prescription_status IN ('dispensed','partial','completed')

        ORDER BY pr.prescription_date DESC, pi.pi_id ASC
    ";

    $meds_stmt = $mysqli->prepare($meds_sql);
    $meds_stmt->bind_param("i", $visit_id);
    $meds_stmt->execute();
    $meds_result = $meds_stmt->get_result();
    $discharge_medications = $meds_result->fetch_all(MYSQLI_ASSOC);
    
    // Get discharge documents from patient_files
    $docs_sql = "
        SELECT 
            pf.file_id,
            pf.file_category,
            pf.file_original_name,
            pf.file_saved_name,
            pf.file_path,
            pf.file_size,
            pf.file_type,
            pf.file_description,
            pf.file_uploaded_at,
            pf.file_visibility,

            u.user_name AS uploaded_by_name

        FROM patient_files pf
        LEFT JOIN users u 
            ON pf.file_uploaded_by = u.user_id

        WHERE pf.file_visit_id = ?
          AND pf.file_category IN (
              'Discharge Summary',
              'Prescription',
              'Follow-up',
              'Referral',
              'Patient Instructions'
          )
          AND pf.file_archived_at IS NULL

        ORDER BY pf.file_uploaded_at DESC
    ";

    $docs_stmt = $mysqli->prepare($docs_sql);
    $docs_stmt->bind_param("i", $visit_id);
    $docs_stmt->execute();
    $docs_result = $docs_stmt->get_result();
    $discharge_documents = $docs_result->fetch_all(MYSQLI_ASSOC);
}

// Get available physicians for clearance and followup
$doctors_sql = "SELECT user_id, user_name 
               FROM users
             ";
$doctors_result = $mysqli->query($doctors_sql);
$available_doctors = $doctors_result->fetch_all(MYSQLI_ASSOC);

// Get available nurses/staff for assignments
$staff_sql = "SELECT user_id, user_name
             FROM users 
            ";
$staff_result = $mysqli->query($staff_sql);
$available_staff = $staff_result->fetch_all(MYSQLI_ASSOC);

// AUDIT LOG: Successful access to discharge preparations page
audit_log($mysqli, [
    'user_id'     => $_SESSION['user_id'] ?? null,
    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
    'action'      => 'VIEW',
    'module'      => 'Discharge Preparations',
    'table_name'  => 'visits',
    'entity_type' => 'visit',
    'record_id'   => $visit_id,
    'patient_id'  => $patient_info['patient_id'],
    'visit_id'    => $visit_id,
    'description' => "Accessed discharge preparations page for visit ID " . $visit_id . " (Patient: " . $patient_info['first_name'] . " " . $patient_info['last_name'] . ")",
    'status'      => 'SUCCESS',
    'old_values'  => null,
    'new_values'  => null
]);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Create/Update discharge preparation
    if (isset($_POST['save_preparation'])) {
        $discharge_date = $_POST['discharge_date'];
        $discharge_time = $_POST['discharge_time'];
        $discharge_type = $_POST['discharge_type'];
        $discharge_status = $_POST['discharge_status'];
        $notes = trim($_POST['notes'] ?? '');
        $created_by = $_SESSION['user_id'];
        
        // Medical clearance
        $medical_clearance_obtained = isset($_POST['medical_clearance_obtained']) ? 1 : 0;
        $clearance_physician_id = !empty($_POST['clearance_physician_id']) ? intval($_POST['clearance_physician_id']) : null;
        $clearance_date = !empty($_POST['clearance_date']) ? $_POST['clearance_date'] : null;
        $clearance_notes = trim($_POST['clearance_notes'] ?? '');
        
        // Medications
        $medications_ready = isset($_POST['medications_ready']) ? 1 : 0;
        $medications_dispensed = isset($_POST['medications_dispensed']) ? 1 : 0;
        $medication_instructions = trim($_POST['medication_instructions'] ?? '');
        $prescriptions_printed = isset($_POST['prescriptions_printed']) ? 1 : 0;
        
        // Documentation
        $discharge_summary_ready = isset($_POST['discharge_summary_ready']) ? 1 : 0;
        $patient_instructions_ready = isset($_POST['patient_instructions_ready']) ? 1 : 0;
        $followup_appointment_scheduled = isset($_POST['followup_appointment_scheduled']) ? 1 : 0;
        $followup_date = !empty($_POST['followup_date']) ? $_POST['followup_date'] : null;
        $followup_physician_id = !empty($_POST['followup_physician_id']) ? intval($_POST['followup_physician_id']) : null;
        $followup_notes = trim($_POST['followup_notes'] ?? '');
        
        // Financial
        $billing_cleared = isset($_POST['billing_cleared']) ? 1 : 0;
        $billing_amount = !empty($_POST['billing_amount']) ? floatval($_POST['billing_amount']) : null;
        $billing_paid = !empty($_POST['billing_paid']) ? floatval($_POST['billing_paid']) : null;
        $billing_balance = !empty($_POST['billing_balance']) ? floatval($_POST['billing_balance']) : null;
        $billing_notes = trim($_POST['billing_notes'] ?? '');
        
        // Transportation
        $transportation_arranged = isset($_POST['transportation_arranged']) ? 1 : 0;
        $transportation_type = trim($_POST['transportation_type'] ?? '');
        $transportation_details = trim($_POST['transportation_details'] ?? '');
        
        // Education
        $education_completed = isset($_POST['education_completed']) ? 1 : 0;
        $education_topics = trim($_POST['education_topics'] ?? '');
        $education_notes = trim($_POST['education_notes'] ?? '');
        
        // Belongings
        $belongings_returned = isset($_POST['belongings_returned']) ? 1 : 0;
        $belongings_verified_by = !empty($_POST['belongings_verified_by']) ? intval($_POST['belongings_verified_by']) : null;
        
        // Special requirements
        $special_equipment_required = isset($_POST['special_equipment_required']) ? 1 : 0;
        $equipment_details = trim($_POST['equipment_details'] ?? '');
        $home_care_arranged = isset($_POST['home_care_arranged']) ? 1 : 0;
        $home_care_details = trim($_POST['home_care_details'] ?? '');
        
        // Final checklist
        $final_vitals_recorded = isset($_POST['final_vitals_recorded']) ? 1 : 0;
        $final_assessment_done = isset($_POST['final_assessment_done']) ? 1 : 0;
        $patient_ready_for_discharge = isset($_POST['patient_ready_for_discharge']) ? 1 : 0;
        
        // AUDIT LOG: Save preparation attempt
        $audit_action = $preparation ? 'DISCHARGE_PREPARATION_UPDATE' : 'DISCHARGE_PREPARATION_CREATE';
        $description = $preparation ? 
            "Attempting to update discharge preparation for visit ID " . $visit_id . ". Discharge type: " . $discharge_type :
            "Attempting to create discharge preparation for visit ID " . $visit_id . ". Discharge type: " . $discharge_type;
            
        audit_log($mysqli, [
            'user_id'     => $created_by,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => $audit_action,
            'module'      => 'Discharge Preparations',
            'table_name'  => 'discharge_preparations',
            'entity_type' => 'discharge_preparation',
            'record_id'   => $preparation['preparation_id'] ?? null,
            'patient_id'  => $patient_info['patient_id'],
            'visit_id'    => $visit_id,
            'description' => $description,
            'status'      => 'ATTEMPT',
            'old_values'  => $preparation ? [
                'discharge_type' => $preparation['discharge_type'],
                'discharge_status' => $preparation['discharge_status'],
                'medical_clearance_obtained' => $preparation['medical_clearance_obtained']
            ] : null,
            'new_values'  => [
                'discharge_type' => $discharge_type,
                'discharge_status' => $discharge_status,
                'medical_clearance_obtained' => $medical_clearance_obtained,
                'discharge_date' => $discharge_date,
                'discharge_time' => $discharge_time,
                'created_by' => $created_by
            ]
        ]);
        
        if ($preparation) {
            // Update existing preparation
            $update_sql = "UPDATE discharge_preparations SET
                          discharge_date = ?, discharge_time = ?, discharge_type = ?, discharge_status = ?,
                          medical_clearance_obtained = ?, clearance_physician_id = ?, clearance_date = ?, clearance_notes = ?,
                          medications_ready = ?, medications_dispensed = ?, medication_instructions = ?, prescriptions_printed = ?,
                          discharge_summary_ready = ?, patient_instructions_ready = ?, followup_appointment_scheduled = ?,
                          followup_date = ?, followup_physician_id = ?, followup_notes = ?,
                          billing_cleared = ?, billing_amount = ?, billing_paid = ?, billing_balance = ?, billing_notes = ?,
                          transportation_arranged = ?, transportation_type = ?, transportation_details = ?,
                          education_completed = ?, education_topics = ?, education_notes = ?,
                          belongings_returned = ?, belongings_verified_by = ?,
                          special_equipment_required = ?, equipment_details = ?, home_care_arranged = ?, home_care_details = ?,
                          final_vitals_recorded = ?, final_assessment_done = ?, patient_ready_for_discharge = ?,
                          notes = ?, updated_at = NOW()
                          WHERE preparation_id = ?";
            
            $update_stmt = $mysqli->prepare($update_sql);
            $update_stmt->bind_param("ssssiisssisssiisssddddsssssssssssssssssi", 
                $discharge_date, $discharge_time, $discharge_type, $discharge_status,
                $medical_clearance_obtained, $clearance_physician_id, $clearance_date, $clearance_notes,
                $medications_ready, $medications_dispensed, $medication_instructions, $prescriptions_printed,
                $discharge_summary_ready, $patient_instructions_ready, $followup_appointment_scheduled,
                $followup_date, $followup_physician_id, $followup_notes,
                $billing_cleared, $billing_amount, $billing_paid, $billing_balance, $billing_notes,
                $transportation_arranged, $transportation_type, $transportation_details,
                $education_completed, $education_topics, $education_notes,
                $belongings_returned, $belongings_verified_by,
                $special_equipment_required, $equipment_details, $home_care_arranged, $home_care_details,
                $final_vitals_recorded, $final_assessment_done, $patient_ready_for_discharge,
                $notes, $preparation['preparation_id']
            );
            
            if ($update_stmt->execute()) {
                // AUDIT LOG: Successful preparation update
                audit_log($mysqli, [
                    'user_id'     => $created_by,
                    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                    'action'      => 'DISCHARGE_PREPARATION_UPDATE',
                    'module'      => 'Discharge Preparations',
                    'table_name'  => 'discharge_preparations',
                    'entity_type' => 'discharge_preparation',
                    'record_id'   => $preparation['preparation_id'],
                    'patient_id'  => $patient_info['patient_id'],
                    'visit_id'    => $visit_id,
                    'description' => "Discharge preparation updated successfully for visit ID " . $visit_id . ". Preparation ID: " . $preparation['preparation_id'],
                    'status'      => 'SUCCESS',
                    'old_values'  => [
                        'discharge_type' => $preparation['discharge_type'],
                        'discharge_status' => $preparation['discharge_status']
                    ],
                    'new_values'  => [
                        'discharge_type' => $discharge_type,
                        'discharge_status' => $discharge_status,
                        'medical_clearance_obtained' => $medical_clearance_obtained,
                        'updated_at' => date('Y-m-d H:i:s')
                    ]
                ]);
                
                $_SESSION['alert_type'] = "success";
                $_SESSION['alert_message'] = "Discharge preparation updated successfully";
                header("Location: discharge_preparations.php?visit_id=" . $visit_id);
                exit;
            } else {
                // AUDIT LOG: Failed preparation update
                audit_log($mysqli, [
                    'user_id'     => $created_by,
                    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                    'action'      => 'DISCHARGE_PREPARATION_UPDATE',
                    'module'      => 'Discharge Preparations',
                    'table_name'  => 'discharge_preparations',
                    'entity_type' => 'discharge_preparation',
                    'record_id'   => $preparation['preparation_id'],
                    'patient_id'  => $patient_info['patient_id'],
                    'visit_id'    => $visit_id,
                    'description' => "Failed to update discharge preparation. Error: " . $mysqli->error,
                    'status'      => 'FAILED',
                    'old_values'  => null,
                    'new_values'  => null
                ]);
                
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = "Error updating preparation: " . $mysqli->error;
            }
        } else {
            // Create new preparation
            $insert_sql = "INSERT INTO discharge_preparations 
                          (visit_id, patient_id, discharge_date, discharge_time, discharge_type, discharge_status,
                          medical_clearance_obtained, clearance_physician_id, clearance_date, clearance_notes,
                          medications_ready, medications_dispensed, medication_instructions, prescriptions_printed,
                          discharge_summary_ready, patient_instructions_ready, followup_appointment_scheduled,
                          followup_date, followup_physician_id, followup_notes,
                          billing_cleared, billing_amount, billing_paid, billing_balance, billing_notes,
                          transportation_arranged, transportation_type, transportation_details,
                          education_completed, education_topics, education_notes,
                          belongings_returned, belongings_verified_by,
                          special_equipment_required, equipment_details, home_care_arranged, home_care_details,
                          final_vitals_recorded, final_assessment_done, patient_ready_for_discharge,
                          notes, created_by)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $insert_stmt = $mysqli->prepare($insert_sql);
            $insert_stmt->bind_param("iissssiisssisssiisssddddsssssssssssssssssi", 
                $visit_id, $patient_info['patient_id'], $discharge_date, $discharge_time, $discharge_type, $discharge_status,
                $medical_clearance_obtained, $clearance_physician_id, $clearance_date, $clearance_notes,
                $medications_ready, $medications_dispensed, $medication_instructions, $prescriptions_printed,
                $discharge_summary_ready, $patient_instructions_ready, $followup_appointment_scheduled,
                $followup_date, $followup_physician_id, $followup_notes,
                $billing_cleared, $billing_amount, $billing_paid, $billing_balance, $billing_notes,
                $transportation_arranged, $transportation_type, $transportation_details,
                $education_completed, $education_topics, $education_notes,
                $belongings_returned, $belongings_verified_by,
                $special_equipment_required, $equipment_details, $home_care_arranged, $home_care_details,
                $final_vitals_recorded, $final_assessment_done, $patient_ready_for_discharge,
                $notes, $created_by
            );
            
            if ($insert_stmt->execute()) {
                // Add default checklist items
                $preparation_id = $mysqli->insert_id;
                addDefaultChecklistItems($preparation_id, $mysqli);

                // AUDIT LOG: Successful preparation creation
                audit_log($mysqli, [
                    'user_id'     => $created_by,
                    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                    'action'      => 'DISCHARGE_PREPARATION_CREATE',
                    'module'      => 'Discharge Preparations',
                    'table_name'  => 'discharge_preparations',
                    'entity_type' => 'discharge_preparation',
                    'record_id'   => $preparation_id,
                    'patient_id'  => $patient_info['patient_id'],
                    'visit_id'    => $visit_id,
                    'description' => "Discharge preparation created successfully. Preparation ID: " . $preparation_id,
                    'status'      => 'SUCCESS',
                    'old_values'  => null,
                    'new_values'  => [
                        'preparation_id' => $preparation_id,
                        'discharge_type' => $discharge_type,
                        'discharge_status' => $discharge_status,
                        'created_by' => $created_by,
                        'created_at' => date('Y-m-d H:i:s')
                    ]
                ]);
                
                $_SESSION['alert_type'] = "success";
                $_SESSION['alert_message'] = "Discharge preparation created successfully";
                header("Location: discharge_preparations.php?visit_id=" . $visit_id);
                exit;
            } else {
                // AUDIT LOG: Failed preparation creation
                audit_log($mysqli, [
                    'user_id'     => $created_by,
                    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                    'action'      => 'DISCHARGE_PREPARATION_CREATE',
                    'module'      => 'Discharge Preparations',
                    'table_name'  => 'discharge_preparations',
                    'entity_type' => 'discharge_preparation',
                    'record_id'   => null,
                    'patient_id'  => $patient_info['patient_id'],
                    'visit_id'    => $visit_id,
                    'description' => "Failed to create discharge preparation. Error: " . $mysqli->error,
                    'status'      => 'FAILED',
                    'old_values'  => null,
                    'new_values'  => null
                ]);
                
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = "Error creating preparation: " . $mysqli->error;
            }
        }
    }
    
    // Complete discharge
    if (isset($_POST['complete_discharge'])) {
        if ($preparation) {
            $completed_by = $_SESSION['user_id'];
            
            // AUDIT LOG: Complete discharge attempt
            audit_log($mysqli, [
                'user_id'     => $completed_by,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'DISCHARGE_COMPLETION',
                'module'      => 'Discharge Preparations',
                'table_name'  => 'discharge_preparations',
                'entity_type' => 'discharge_preparation',
                'record_id'   => $preparation['preparation_id'],
                'patient_id'  => $patient_info['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Attempting to complete discharge for preparation ID " . $preparation['preparation_id'],
                'status'      => 'ATTEMPT',
                'old_values'  => [
                    'discharge_status' => $preparation['discharge_status'],
                    'ipd_discharge_datetime' => $ipd_info['discharge_datetime'],
                    'visit_status' => $visit_info['visit_status']
                ],
                'new_values'  => [
                    'discharge_status' => 'completed',
                    'discharge_datetime' => $preparation['discharge_date'] . ' ' . $preparation['discharge_time'],
                    'discharged_by' => $completed_by
                ]
            ]);
            
            // Start transaction
            $mysqli->begin_transaction();
            
            try {
                // Update discharge preparation status
                $update_sql = "UPDATE discharge_preparations 
                              SET discharge_status = 'completed',
                                  discharge_completed_at = NOW(),
                                  discharged_by = ?
                              WHERE preparation_id = ?";
                
                $update_stmt = $mysqli->prepare($update_sql);
                $update_stmt->bind_param("ii", $completed_by, $preparation['preparation_id']);
                
                if (!$update_stmt->execute()) {
                    throw new Exception("Failed to update discharge preparation: " . $mysqli->error);
                }
                
                // Update IPD admission with discharge date
                $update_ipd = "UPDATE ipd_admissions 
                              SET discharge_datetime = ?,
                                  discharge_status = 'DISCHARGED',
                                  updated_at = NOW()
                              WHERE visit_id = ?";
                
                $discharge_datetime = $preparation['discharge_date'] . ' ' . $preparation['discharge_time'];
                $update_ipd_stmt = $mysqli->prepare($update_ipd);
                $update_ipd_stmt->bind_param("si", $discharge_datetime, $visit_id);
                
                if (!$update_ipd_stmt->execute()) {
                    throw new Exception("Failed to update IPD admission: " . $mysqli->error);
                }
                
                // Update visit status
                $update_visit = "UPDATE visits 
                               SET visit_status = 'CLOSED',
                                   closed_at = NOW(),
                                   discharge_datetime = ?
                               WHERE visit_id = ?";
                
                $update_visit_stmt = $mysqli->prepare($update_visit);
                $update_visit_stmt->bind_param("si", $discharge_datetime, $visit_id);
                
                if (!$update_visit_stmt->execute()) {
                    throw new Exception("Failed to update visit: " . $mysqli->error);
                }
                
                // Free up the bed
                $update_bed = "UPDATE beds SET status = 'available' 
                              WHERE bed_id = (SELECT bed_id FROM ipd_admissions WHERE visit_id = ?)";
                $update_bed_stmt = $mysqli->prepare($update_bed);
                $update_bed_stmt->bind_param("i", $visit_id);
                
                if (!$update_bed_stmt->execute()) {
                    throw new Exception("Failed to update bed status: " . $mysqli->error);
                }
                
                $mysqli->commit();
                
                // AUDIT LOG: Successful discharge completion
                audit_log($mysqli, [
                    'user_id'     => $completed_by,
                    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                    'action'      => 'DISCHARGE_COMPLETION',
                    'module'      => 'Discharge Preparations',
                    'table_name'  => 'discharge_preparations',
                    'entity_type' => 'discharge_preparation',
                    'record_id'   => $preparation['preparation_id'],
                    'patient_id'  => $patient_info['patient_id'],
                    'visit_id'    => $visit_id,
                    'description' => "Discharge completed successfully. Patient officially discharged. Preparation ID: " . $preparation['preparation_id'],
                    'status'      => 'SUCCESS',
                    'old_values'  => [
                        'discharge_status' => $preparation['discharge_status'],
                        'ipd_discharge_datetime' => null,
                        'visit_status' => 'ACTIVE',
                        'bed_status' => 'occupied'
                    ],
                    'new_values'  => [
                        'discharge_status' => 'completed',
                        'discharge_datetime' => $discharge_datetime,
                        'discharged_by' => $completed_by,
                        'visit_status' => 'CLOSED',
                        'bed_status' => 'available'
                    ]
                ]);
                
                $_SESSION['alert_type'] = "success";
                $_SESSION['alert_message'] = "Discharge completed successfully! Patient is officially discharged.";
                header("Location: discharge_preparations.php?visit_id=" . $visit_id);
                exit;
                
            } catch (Exception $e) {
                $mysqli->rollback();
                
                // AUDIT LOG: Failed discharge completion
                audit_log($mysqli, [
                    'user_id'     => $completed_by,
                    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                    'action'      => 'DISCHARGE_COMPLETION',
                    'module'      => 'Discharge Preparations',
                    'table_name'  => 'discharge_preparations',
                    'entity_type' => 'discharge_preparation',
                    'record_id'   => $preparation['preparation_id'],
                    'patient_id'  => $patient_info['patient_id'],
                    'visit_id'    => $visit_id,
                    'description' => "Failed to complete discharge. Error: " . $e->getMessage(),
                    'status'      => 'FAILED',
                    'old_values'  => null,
                    'new_values'  => null
                ]);
                
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = "Error completing discharge: " . $e->getMessage();
            }
        }
    }
    
    // Cancel discharge
    if (isset($_POST['cancel_discharge'])) {
        if ($preparation) {
            $cancellation_reason = trim($_POST['cancellation_reason']);
            $cancelled_by = $_SESSION['user_id'];
            
            // AUDIT LOG: Cancel discharge attempt
            audit_log($mysqli, [
                'user_id'     => $cancelled_by,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'DISCHARGE_CANCELLATION',
                'module'      => 'Discharge Preparations',
                'table_name'  => 'discharge_preparations',
                'entity_type' => 'discharge_preparation',
                'record_id'   => $preparation['preparation_id'],
                'patient_id'  => $patient_info['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Attempting to cancel discharge preparation. Reason: " . $cancellation_reason,
                'status'      => 'ATTEMPT',
                'old_values'  => ['discharge_status' => $preparation['discharge_status']],
                'new_values'  => [
                    'discharge_status' => 'cancelled',
                    'cancellation_reason' => $cancellation_reason,
                    'cancelled_by' => $cancelled_by
                ]
            ]);
            
            $update_sql = "UPDATE discharge_preparations 
                          SET discharge_status = 'cancelled',
                              cancellation_reason = ?,
                              cancelled_by = ?,
                              cancelled_at = NOW()
                          WHERE preparation_id = ?";
            
            $update_stmt = $mysqli->prepare($update_sql);
            $update_stmt->bind_param("sii", $cancellation_reason, $cancelled_by, $preparation['preparation_id']);
            
            if ($update_stmt->execute()) {
                // AUDIT LOG: Successful discharge cancellation
                audit_log($mysqli, [
                    'user_id'     => $cancelled_by,
                    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                    'action'      => 'DISCHARGE_CANCELLATION',
                    'module'      => 'Discharge Preparations',
                    'table_name'  => 'discharge_preparations',
                    'entity_type' => 'discharge_preparation',
                    'record_id'   => $preparation['preparation_id'],
                    'patient_id'  => $patient_info['patient_id'],
                    'visit_id'    => $visit_id,
                    'description' => "Discharge preparation cancelled successfully. Preparation ID: " . $preparation['preparation_id'],
                    'status'      => 'SUCCESS',
                    'old_values'  => ['discharge_status' => $preparation['discharge_status']],
                    'new_values'  => [
                        'discharge_status' => 'cancelled',
                        'cancellation_reason' => $cancellation_reason,
                        'cancelled_by' => $cancelled_by,
                        'cancelled_at' => date('Y-m-d H:i:s')
                    ]
                ]);
                
                $_SESSION['alert_type'] = "success";
                $_SESSION['alert_message'] = "Discharge preparation cancelled successfully";
                header("Location: discharge_preparations.php?visit_id=" . $visit_id);
                exit;
            } else {
                // AUDIT LOG: Failed discharge cancellation
                audit_log($mysqli, [
                    'user_id'     => $cancelled_by,
                    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                    'action'      => 'DISCHARGE_CANCELLATION',
                    'module'      => 'Discharge Preparations',
                    'table_name'  => 'discharge_preparations',
                    'entity_type' => 'discharge_preparation',
                    'record_id'   => $preparation['preparation_id'],
                    'patient_id'  => $patient_info['patient_id'],
                    'visit_id'    => $visit_id,
                    'description' => "Failed to cancel discharge preparation. Error: " . $mysqli->error,
                    'status'      => 'FAILED',
                    'old_values'  => null,
                    'new_values'  => null
                ]);
                
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = "Error cancelling discharge: " . $mysqli->error;
            }
        }
    }
    
    // Update checklist item
    if (isset($_POST['update_checklist_item'])) {
        $item_id = intval($_POST['item_id']);
        $item_status = $_POST['item_status'];
        $item_notes = trim($_POST['item_notes'] ?? '');
        $updated_by = $_SESSION['user_id'];
        
        // Get current item info for audit log
        $current_item_sql = "SELECT item_description, item_status FROM discharge_checklist_items WHERE item_id = ?";
        $current_item_stmt = $mysqli->prepare($current_item_sql);
        $current_item_stmt->bind_param("i", $item_id);
        $current_item_stmt->execute();
        $current_item_result = $current_item_stmt->get_result();
        $current_item = $current_item_result->fetch_assoc();
        $old_item_status = $current_item['item_status'] ?? null;
        $item_description = $current_item['item_description'] ?? null;
        
        // AUDIT LOG: Update checklist item attempt
        audit_log($mysqli, [
            'user_id'     => $updated_by,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'CHECKLIST_ITEM_UPDATE',
            'module'      => 'Discharge Preparations',
            'table_name'  => 'discharge_checklist_items',
            'entity_type' => 'checklist_item',
            'record_id'   => $item_id,
            'patient_id'  => $patient_info['patient_id'],
            'visit_id'    => $visit_id,
            'description' => "Attempting to update checklist item: " . $item_description . ". New status: " . $item_status,
            'status'      => 'ATTEMPT',
            'old_values'  => ['item_status' => $old_item_status],
            'new_values'  => [
                'item_status' => $item_status,
                'notes' => $item_notes,
                'completed_by' => $item_status == 'completed' ? $updated_by : null
            ]
        ]);
        
        $update_sql = "UPDATE discharge_checklist_items 
                      SET item_status = ?, notes = ?";
        
        if ($item_status == 'completed') {
            $update_sql .= ", completed_at = NOW(), completed_by = ?";
        } else {
            $update_sql .= ", completed_at = NULL, completed_by = NULL";
        }
        
        $update_sql .= " WHERE item_id = ?";
        
        if ($item_status == 'completed') {
            $update_stmt = $mysqli->prepare($update_sql);
            $update_stmt->bind_param("ssii", $item_status, $item_notes, $updated_by, $item_id);
        } else {
            $update_stmt = $mysqli->prepare($update_sql);
            $update_stmt->bind_param("ssi", $item_status, $item_notes, $item_id);
        }
        
        if ($update_stmt->execute()) {
            // AUDIT LOG: Successful checklist item update
            audit_log($mysqli, [
                'user_id'     => $updated_by,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'CHECKLIST_ITEM_UPDATE',
                'module'      => 'Discharge Preparations',
                'table_name'  => 'discharge_checklist_items',
                'entity_type' => 'checklist_item',
                'record_id'   => $item_id,
                'patient_id'  => $patient_info['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Checklist item updated successfully. Item: " . $item_description . " (ID: " . $item_id . ")",
                'status'      => 'SUCCESS',
                'old_values'  => ['item_status' => $old_item_status],
                'new_values'  => [
                    'item_status' => $item_status,
                    'notes' => $item_notes,
                    'completed_by' => $item_status == 'completed' ? $updated_by : null,
                    'completed_at' => $item_status == 'completed' ? date('Y-m-d H:i:s') : null
                ]
            ]);
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Checklist item updated successfully";
            header("Location: discharge_preparations.php?visit_id=" . $visit_id);
            exit;
        } else {
            // AUDIT LOG: Failed checklist item update
            audit_log($mysqli, [
                'user_id'     => $updated_by,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'CHECKLIST_ITEM_UPDATE',
                'module'      => 'Discharge Preparations',
                'table_name'  => 'discharge_checklist_items',
                'entity_type' => 'checklist_item',
                'record_id'   => $item_id,
                'patient_id'  => $patient_info['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Failed to update checklist item. Error: " . $mysqli->error,
                'status'      => 'FAILED',
                'old_values'  => null,
                'new_values'  => null
            ]);
            
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error updating checklist item: " . $mysqli->error;
        }
    }
    
    // Add discharge medication
    if (isset($_POST['add_medication'])) {
        if ($preparation) {
            $medication_name = trim($_POST['medication_name']);
            $dosage = trim($_POST['dosage'] ?? '');
            $frequency = trim($_POST['frequency'] ?? '');
            $duration = trim($_POST['duration'] ?? '');
            $route = trim($_POST['route'] ?? '');
            $instructions = trim($_POST['instructions'] ?? '');
            $quantity_dispensed = intval($_POST['quantity_dispensed'] ?? 0);
            $refills = intval($_POST['refills'] ?? 0);
            $prescribed_by = !empty($_POST['prescribed_by']) ? intval($_POST['prescribed_by']) : null;
            $med_notes = trim($_POST['med_notes'] ?? '');
            $added_by = $_SESSION['user_id'];
            
            // AUDIT LOG: Add discharge medication attempt
            audit_log($mysqli, [
                'user_id'     => $added_by,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'DISCHARGE_MEDICATION_ADD',
                'module'      => 'Discharge Preparations',
                'table_name'  => 'discharge_medications',
                'entity_type' => 'discharge_medication',
                'record_id'   => null,
                'patient_id'  => $patient_info['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Attempting to add discharge medication: " . $medication_name,
                'status'      => 'ATTEMPT',
                'old_values'  => null,
                'new_values'  => [
                    'medication_name' => $medication_name,
                    'dosage' => $dosage,
                    'frequency' => $frequency,
                    'preparation_id' => $preparation['preparation_id'],
                    'added_by' => $added_by
                ]
            ]);
            
            $insert_sql = "INSERT INTO discharge_medications 
                          (preparation_id, medication_name, dosage, frequency, duration, route, 
                          instructions, quantity_dispensed, refills, prescribed_by, notes)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $insert_stmt = $mysqli->prepare($insert_sql);
            $insert_stmt->bind_param("issssssiiss", 
                $preparation['preparation_id'], $medication_name, $dosage, $frequency, $duration, $route,
                $instructions, $quantity_dispensed, $refills, $prescribed_by, $med_notes
            );
            
            if ($insert_stmt->execute()) {
                $medication_id = $mysqli->insert_id;
                
                // AUDIT LOG: Successful medication addition
                audit_log($mysqli, [
                    'user_id'     => $added_by,
                    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                    'action'      => 'DISCHARGE_MEDICATION_ADD',
                    'module'      => 'Discharge Preparations',
                    'table_name'  => 'discharge_medications',
                    'entity_type' => 'discharge_medication',
                    'record_id'   => $medication_id,
                    'patient_id'  => $patient_info['patient_id'],
                    'visit_id'    => $visit_id,
                    'description' => "Discharge medication added successfully. Medication: " . $medication_name . " (ID: " . $medication_id . ")",
                    'status'      => 'SUCCESS',
                    'old_values'  => null,
                    'new_values'  => [
                        'medication_id' => $medication_id,
                        'medication_name' => $medication_name,
                        'dosage' => $dosage,
                        'frequency' => $frequency,
                        'preparation_id' => $preparation['preparation_id'],
                        'added_by' => $added_by
                    ]
                ]);
                
                $_SESSION['alert_type'] = "success";
                $_SESSION['alert_message'] = "Medication added successfully";
                header("Location: discharge_preparations.php?visit_id=" . $visit_id);
                exit;
            } else {
                // AUDIT LOG: Failed medication addition
                audit_log($mysqli, [
                    'user_id'     => $added_by,
                    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                    'action'      => 'DISCHARGE_MEDICATION_ADD',
                    'module'      => 'Discharge Preparations',
                    'table_name'  => 'discharge_medications',
                    'entity_type' => 'discharge_medication',
                    'record_id'   => null,
                    'patient_id'  => $patient_info['patient_id'],
                    'visit_id'    => $visit_id,
                    'description' => "Failed to add discharge medication. Error: " . $mysqli->error,
                    'status'      => 'FAILED',
                    'old_values'  => null,
                    'new_values'  => null
                ]);
                
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = "Error adding medication: " . $mysqli->error;
            }
        }
    }
    
    // Dispense medication
    if (isset($_POST['dispense_medication'])) {
        $medication_id = intval($_POST['medication_id']);
        $dispensed_by = $_SESSION['user_id'];
        
        // Get current medication info for audit log
        $current_med_sql = "SELECT medication_name, preparation_id FROM discharge_medications WHERE medication_id = ?";
        $current_med_stmt = $mysqli->prepare($current_med_sql);
        $current_med_stmt->bind_param("i", $medication_id);
        $current_med_stmt->execute();
        $current_med_result = $current_med_stmt->get_result();
        $current_med = $current_med_result->fetch_assoc();
        $medication_name = $current_med['medication_name'] ?? null;
        
        // AUDIT LOG: Dispense medication attempt
        audit_log($mysqli, [
            'user_id'     => $dispensed_by,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'DISCHARGE_MEDICATION_DISPENSE',
            'module'      => 'Discharge Preparations',
            'table_name'  => 'discharge_medications',
            'entity_type' => 'discharge_medication',
            'record_id'   => $medication_id,
            'patient_id'  => $patient_info['patient_id'],
            'visit_id'    => $visit_id,
            'description' => "Attempting to dispense medication: " . $medication_name . " (ID: " . $medication_id . ")",
            'status'      => 'ATTEMPT',
            'old_values'  => null,
            'new_values'  => [
                'dispensed_by' => $dispensed_by,
                'dispensed_at' => date('Y-m-d H:i:s')
            ]
        ]);
        
        $update_sql = "UPDATE discharge_medications 
                      SET dispensed_by = ?, dispensed_at = NOW()
                      WHERE medication_id = ?";
        
        $update_stmt = $mysqli->prepare($update_sql);
        $update_stmt->bind_param("ii", $dispensed_by, $medication_id);
        
        if ($update_stmt->execute()) {
            // AUDIT LOG: Successful medication dispensation
            audit_log($mysqli, [
                'user_id'     => $dispensed_by,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'DISCHARGE_MEDICATION_DISPENSE',
                'module'      => 'Discharge Preparations',
                'table_name'  => 'discharge_medications',
                'entity_type' => 'discharge_medication',
                'record_id'   => $medication_id,
                'patient_id'  => $patient_info['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Medication dispensed successfully. Medication: " . $medication_name . " (ID: " . $medication_id . ")",
                'status'      => 'SUCCESS',
                'old_values'  => null,
                'new_values'  => [
                    'dispensed_by' => $dispensed_by,
                    'dispensed_at' => date('Y-m-d H:i:s')
                ]
            ]);
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Medication marked as dispensed";
            header("Location: discharge_preparations.php?visit_id=" . $visit_id);
            exit;
        } else {
            // AUDIT LOG: Failed medication dispensation
            audit_log($mysqli, [
                'user_id'     => $dispensed_by,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'DISCHARGE_MEDICATION_DISPENSE',
                'module'      => 'Discharge Preparations',
                'table_name'  => 'discharge_medications',
                'entity_type' => 'discharge_medication',
                'record_id'   => $medication_id,
                'patient_id'  => $patient_info['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Failed to dispense medication. Error: " . $mysqli->error,
                'status'      => 'FAILED',
                'old_values'  => null,
                'new_values'  => null
            ]);
            
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error dispensing medication: " . $mysqli->error;
        }
    }
}

// Helper function to add default checklist items
function addDefaultChecklistItems($preparation_id, $mysqli) {
    $default_items = [
        ['medical', 'Obtain medical clearance from physician'],
        ['medical', 'Review final lab results'],
        ['medical', 'Complete discharge summary'],
        ['medications', 'Prepare discharge medications'],
        ['medications', 'Print prescriptions'],
        ['medications', 'Provide medication education'],
        ['documentation', 'Complete discharge instructions'],
        ['documentation', 'Schedule follow-up appointment'],
        ['documentation', 'Prepare referral letters if needed'],
        ['financial', 'Clear hospital bills'],
        ['financial', 'Provide billing summary'],
        ['transportation', 'Arrange patient transportation'],
        ['education', 'Provide wound care instructions'],
        ['education', 'Discuss activity restrictions'],
        ['education', 'Review warning signs'],
        ['belongings', 'Return patient belongings'],
        ['belongings', 'Collect hospital property'],
        ['final', 'Record final vital signs'],
        ['final', 'Complete final assessment'],
        ['final', 'Verify patient readiness']
    ];
    
    // AUDIT LOG: Adding default checklist items
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'DEFAULT_CHECKLIST_CREATION',
        'module'      => 'Discharge Preparations',
        'table_name'  => 'discharge_checklist_items',
        'entity_type' => 'checklist_item',
        'record_id'   => null,
        'patient_id'  => $patient_info['patient_id'],
        'visit_id'    => $visit_id,
        'description' => "Adding default checklist items for preparation ID " . $preparation_id,
        'status'      => 'SUCCESS',
        'old_values'  => null,
        'new_values'  => [
            'preparation_id' => $preparation_id,
            'item_count' => count($default_items)
        ]
    ]);
    
    foreach ($default_items as $item) {
        $insert_sql = "INSERT INTO discharge_checklist_items 
                      (preparation_id, checklist_category, item_description)
                      VALUES (?, ?, ?)";
        $insert_stmt = $mysqli->prepare($insert_sql);
        $insert_stmt->bind_param("iss", $preparation_id, $item[0], $item[1]);
        $insert_stmt->execute();
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

// Function to get status badge
function getDischargeStatusBadge($status) {
    switch($status) {
        case 'completed':
            return '<span class="badge badge-success"><i class="fas fa-check-circle mr-1"></i>Completed</span>';
        case 'in_progress':
            return '<span class="badge badge-primary"><i class="fas fa-spinner mr-1"></i>In Progress</span>';
        case 'cancelled':
            return '<span class="badge badge-secondary"><i class="fas fa-ban mr-1"></i>Cancelled</span>';
        case 'pending':
        default:
            return '<span class="badge badge-warning"><i class="fas fa-clock mr-1"></i>Pending</span>';
    }
}

// Function to get checklist status badge
function getChecklistStatusBadge($status) {
    switch($status) {
        case 'completed':
            return '<span class="badge badge-success"><i class="fas fa-check-circle mr-1"></i>Completed</span>';
        case 'in_progress':
            return '<span class="badge badge-primary"><i class="fas fa-spinner mr-1"></i>In Progress</span>';
        case 'not_applicable':
            return '<span class="badge badge-secondary"><i class="fas fa-minus-circle mr-1"></i>N/A</span>';
        case 'pending':
        default:
            return '<span class="badge badge-warning"><i class="fas fa-clock mr-1"></i>Pending</span>';
    }
}

// Function to get discharge type badge
function getDischargeTypeBadge($type) {
    switch($type) {
        case 'routine':
            return '<span class="badge badge-success"><i class="fas fa-home mr-1"></i>Routine</span>';
        case 'planned':
            return '<span class="badge badge-info"><i class="fas fa-calendar-check mr-1"></i>Planned</span>';
        case 'emergency':
            return '<span class="badge badge-danger"><i class="fas fa-exclamation-triangle mr-1"></i>Emergency</span>';
        case 'transfer':
            return '<span class="badge badge-warning"><i class="fas fa-ambulance mr-1"></i>Transfer</span>';
        case 'death':
            return '<span class="badge badge-dark"><i class="fas fa-cross mr-1"></i>Death</span>';
        case 'left_against_advice':
            return '<span class="badge badge-danger"><i class="fas fa-walking mr-1"></i>Left Against Advice</span>';
        case 'absconded':
            return '<span class="badge badge-danger"><i class="fas fa-running mr-1"></i>Absconded</span>';
        default:
            return '<span class="badge badge-secondary">' . $type . '</span>';
    }
}

// Calculate completion percentage
$completion_percentage = 0;
if ($preparation) {
    $total_fields = 0;
    $completed_fields = 0;
    
    // Count boolean fields (excluding IDs and dates)
    $boolean_fields = [
        'medical_clearance_obtained', 'medications_ready', 'medications_dispensed',
        'prescriptions_printed', 'discharge_summary_ready', 'patient_instructions_ready',
        'followup_appointment_scheduled', 'billing_cleared', 'transportation_arranged',
        'education_completed', 'belongings_returned', 'special_equipment_required',
        'home_care_arranged', 'final_vitals_recorded', 'final_assessment_done',
        'patient_ready_for_discharge'
    ];
    
    foreach ($boolean_fields as $field) {
        $total_fields++;
        if (isset($preparation[$field]) && $preparation[$field] == 1) {
            $completed_fields++;
        }
    }
    
    // Count checklist items
    if (!empty($checklist_items)) {
        foreach ($checklist_items as $item) {
            $total_fields++;
            if (isset($item['item_status']) && $item['item_status'] == 'completed') {
                $completed_fields++;
            }
        }
    }
    
    if ($total_fields > 0) {
        $completion_percentage = round(($completed_fields / $total_fields) * 100);
    }
}

// Group checklist items by category
$category_groups = [];
if (!empty($checklist_items)) {
    foreach ($checklist_items as $item) {
        $category = isset($item['checklist_category']) ? $item['checklist_category'] : 'other';
        if (!isset($category_groups[$category])) {
            $category_groups[$category] = [];
        }
        $category_groups[$category][] = $item;
    }
}
?>
<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0">
            <i class="fas fa-fw fa-home mr-2"></i>Discharge Preparations: <?php echo htmlspecialchars($patient_info['patient_mrn']); ?>
        </h3>
        <div class="card-tools">
            <div class="btn-group">
                <button type="button" class="btn btn-light" onclick="window.history.back()">
                    <i class="fas fa-arrow-left mr-2"></i>Back
                </button>
                <?php if (!$preparation): ?>
                    <button type="button" class="btn btn-success" data-toggle="modal" data-target="#createPreparationModal">
                        <i class="fas fa-plus mr-2"></i>Start Discharge
                    </button>
                <?php endif; ?>
                <button type="button" class="btn btn-info" onclick="printDischargeSummary()">
                    <i class="fas fa-print mr-2"></i>Print Summary
                </button>
                <a href="/clinic/nurse/opd_notes.php?visit_id=<?php echo $visit_id; ?>" class="btn btn-warning">
                    <i class="fas fa-clipboard mr-2"></i>Notes
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
                                                <th class="text-muted">Age/Gender:</th>
                                                <td>
                                                    <span class="badge badge-secondary"><?php echo $age; ?></span>
                                                    <span class="badge badge-secondary ml-1"><?php echo htmlspecialchars($patient_info['sex']); ?></span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th class="text-muted">Blood Group:</th>
                                                <td>
                                                    <?php if ($patient_info['blood_group']): ?>
                                                        <span class="badge badge-danger"><?php echo htmlspecialchars($patient_info['blood_group']); ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">Not recorded</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        </table>
                                    </div>
                                    <div class="col-md-6">
                                        <table class="table table-sm table-borderless mb-0">
                                            <tr>
                                                <th width="40%" class="text-muted">Admission Date:</th>
                                                <td><?php echo date('M j, Y H:i', strtotime($ipd_info['admission_datetime'])); ?></td>
                                            </tr>
                                            <tr>
                                                <th class="text-muted">Length of Stay:</th>
                                                <td><span class="badge badge-info"><?php echo $ipd_info['length_of_stay']; ?> days</span></td>
                                            </tr>
                                            <tr>
                                                <th class="text-muted">Ward/Bed:</th>
                                                <td>
                                                    <?php if ($ipd_info['ward_name']): ?>
                                                        <span class="badge badge-primary"><?php echo htmlspecialchars($ipd_info['ward_name']); ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">Not assigned</span>
                                                    <?php endif; ?>
                                                    <?php if ($ipd_info['bed_number']): ?>
                                                        <span class="badge badge-secondary ml-1"><?php echo htmlspecialchars($ipd_info['bed_number']); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th class="text-muted">Admitting Provider:</th>
                                                <td><?php echo htmlspecialchars($ipd_info['admitting_provider_name'] ?? 'N/A'); ?></td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card bg-white border">
                                    <div class="card-body text-center py-2">
                                        <h5 class="mb-1">Discharge Status</h5>
                                        <?php if ($preparation): ?>
                                            <div class="mb-2">
                                                <?php echo getDischargeStatusBadge($preparation['discharge_status']); ?>
                                            </div>
                                            <div class="mb-1">
                                                <strong>Planned:</strong> <?php echo date('M j, Y', strtotime($preparation['discharge_date'])); ?>
                                            </div>
                                            <div class="mb-1">
                                                <strong>Time:</strong> <?php echo date('H:i', strtotime($preparation['discharge_time'])); ?>
                                            </div>
                                            <div>
                                                <?php echo getDischargeTypeBadge($preparation['discharge_type']); ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="text-muted">
                                                <i class="fas fa-home fa-2x mb-2"></i>
                                                <div>No discharge planned</div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($preparation): ?>
        <!-- Progress and Quick Stats -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="info-box">
                    <span class="info-box-icon bg-info"><i class="fas fa-tasks"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Completion</span>
                        <span class="info-box-number"><?php echo $completion_percentage; ?>%</span>
                        <div class="progress">
                            <div class="progress-bar bg-info" style="width: <?php echo $completion_percentage; ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box">
                    <span class="info-box-icon bg-success"><i class="fas fa-check-circle"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Checklist Items</span>
                        <span class="info-box-number">
                            <?php 
                            $completed_items = 0;
                            if (!empty($checklist_items)) {
                                foreach ($checklist_items as $item) {
                                    if (isset($item['item_status']) && $item['item_status'] == 'completed') {
                                        $completed_items++;
                                    }
                                }
                            }
                            echo $completed_items . '/' . count($checklist_items);
                            ?>
                        </span>
                        <span class="progress-description">
                            <?php echo $completed_items; ?> completed
                        </span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box">
                    <span class="info-box-icon bg-warning"><i class="fas fa-pills"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Medications</span>
                        <span class="info-box-number"><?php echo count($discharge_medications); ?></span>
                        <span class="progress-description">
                            <?php 
                            $dispensed_meds = 0;
                            foreach ($discharge_medications as $med) {
                                if (!empty($med['prescription_dispensed_at'])) {
                                    $dispensed_meds++;
                                }
                            }
                            echo $dispensed_meds; ?> dispensed
                        </span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box">
                    <span class="info-box-icon bg-primary"><i class="fas fa-file-medical"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Documents</span>
                        <span class="info-box-number"><?php echo count($discharge_documents); ?></span>
                        <span class="progress-description">
                            Ready for discharge
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Discharge Preparation Overview -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-info py-2">
                        <h4 class="card-title mb-0 text-white">
                            <i class="fas fa-clipboard-list mr-2"></i>Discharge Preparation Overview
                        </h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <!-- Medical Clearance -->
                            <div class="col-md-3 mb-3">
                                <div class="card <?php echo isset($preparation['medical_clearance_obtained']) && $preparation['medical_clearance_obtained'] ? 'border-success' : 'border-warning'; ?>">
                                    <div class="card-body text-center p-3">
                                        <div class="mb-2">
                                            <i class="fas fa-user-md fa-2x <?php echo isset($preparation['medical_clearance_obtained']) && $preparation['medical_clearance_obtained'] ? 'text-success' : 'text-warning'; ?>"></i>
                                        </div>
                                        <h6 class="card-title">Medical Clearance</h6>
                                        <?php if (isset($preparation['medical_clearance_obtained']) && $preparation['medical_clearance_obtained']): ?>
                                            <div class="text-success small">
                                                <i class="fas fa-check-circle mr-1"></i>Obtained
                                            </div>
                                            <?php if (isset($preparation['clearance_doctor_name'])): ?>
                                                <div class="text-muted small">
                                                    By: <?php echo htmlspecialchars($preparation['clearance_doctor_name']); ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <div class="text-warning small">
                                                <i class="fas fa-clock mr-1"></i>Pending
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Medications -->
                            <div class="col-md-3 mb-3">
                                <div class="card <?php echo isset($preparation['medications_dispensed']) && $preparation['medications_dispensed'] ? 'border-success' : 'border-warning'; ?>">
                                    <div class="card-body text-center p-3">
                                        <div class="mb-2">
                                            <i class="fas fa-pills fa-2x <?php echo isset($preparation['medications_dispensed']) && $preparation['medications_dispensed'] ? 'text-success' : 'text-warning'; ?>"></i>
                                        </div>
                                        <h6 class="card-title">Medications</h6>
                                        <?php if (isset($preparation['medications_dispensed']) && $preparation['medications_dispensed']): ?>
                                            <div class="text-success small">
                                                <i class="fas fa-check-circle mr-1"></i>Dispensed
                                            </div>
                                            <div class="text-muted small">
                                                <?php echo count($discharge_medications); ?> meds
                                            </div>
                                        <?php else: ?>
                                            <div class="text-warning small">
                                                <i class="fas fa-clock mr-1"></i>Pending
                                            </div>
                                            <div class="text-muted small">
                                                <?php echo count($discharge_medications); ?> meds ready
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Documentation -->
                            <div class="col-md-3 mb-3">
                                <div class="card <?php echo (isset($preparation['discharge_summary_ready']) && $preparation['discharge_summary_ready'] && isset($preparation['patient_instructions_ready']) && $preparation['patient_instructions_ready']) ? 'border-success' : 'border-warning'; ?>">
                                    <div class="card-body text-center p-3">
                                        <div class="mb-2">
                                            <i class="fas fa-file-medical fa-2x <?php echo (isset($preparation['discharge_summary_ready']) && $preparation['discharge_summary_ready'] && isset($preparation['patient_instructions_ready']) && $preparation['patient_instructions_ready']) ? 'text-success' : 'text-warning'; ?>"></i>
                                        </div>
                                        <h6 class="card-title">Documentation</h6>
                                        <?php if (isset($preparation['discharge_summary_ready']) && $preparation['discharge_summary_ready'] && isset($preparation['patient_instructions_ready']) && $preparation['patient_instructions_ready']): ?>
                                            <div class="text-success small">
                                                <i class="fas fa-check-circle mr-1"></i>Complete
                                            </div>
                                        <?php else: ?>
                                            <div class="text-warning small">
                                                <i class="fas fa-clock mr-1"></i>In Progress
                                            </div>
                                            <div class="text-muted small">
                                                <?php 
                                                $doc_ready = 0;
                                                if (isset($preparation['discharge_summary_ready']) && $preparation['discharge_summary_ready']) $doc_ready++;
                                                if (isset($preparation['patient_instructions_ready']) && $preparation['patient_instructions_ready']) $doc_ready++;
                                                echo $doc_ready . '/2 ready';
                                                ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Financial -->
                            <div class="col-md-3 mb-3">
                                <div class="card <?php echo isset($preparation['billing_cleared']) && $preparation['billing_cleared'] ? 'border-success' : 'border-warning'; ?>">
                                    <div class="card-body text-center p-3">
                                        <div class="mb-2">
                                            <i class="fas fa-money-bill-wave fa-2x <?php echo isset($preparation['billing_cleared']) && $preparation['billing_cleared'] ? 'text-success' : 'text-warning'; ?>"></i>
                                        </div>
                                        <h6 class="card-title">Financial</h6>
                                        <?php if (isset($preparation['billing_cleared']) && $preparation['billing_cleared']): ?>
                                            <div class="text-success small">
                                                <i class="fas fa-check-circle mr-1"></i>Cleared
                                            </div>
                                            <?php if (isset($preparation['billing_balance']) && $preparation['billing_balance'] == 0): ?>
                                                <div class="text-muted small">
                                                    Fully paid
                                                </div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <div class="text-warning small">
                                                <i class="fas fa-clock mr-1"></i>Pending
                                            </div>
                                            <?php if (isset($preparation['billing_balance']) && $preparation['billing_balance'] > 0): ?>
                                                <div class="text-danger small">
                                                    Balance: $<?php echo isset($preparation['billing_balance']) ? number_format($preparation['billing_balance'], 2) : '0.00'; ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Action Buttons -->
                        <div class="row mt-3">
                            <div class="col-md-12 text-center">
                                <div class="btn-group">
                                    <?php if ($preparation['discharge_status'] == 'pending' || $preparation['discharge_status'] == 'in_progress'): ?>
                                        <button type="button" class="btn btn-warning" data-toggle="modal" data-target="#editPreparationModal">
                                            <i class="fas fa-edit mr-2"></i>Edit Preparation
                                        </button>
                                        <button type="button" class="btn btn-success" data-toggle="modal" data-target="#completeDischargeModal" 
                                                <?php echo $completion_percentage < 90 ? 'disabled title="Complete 90% of preparations first"' : ''; ?>>
                                            <i class="fas fa-check-circle mr-2"></i>Complete Discharge
                                        </button>
                                        <button type="button" class="btn btn-danger" data-toggle="modal" data-target="#cancelDischargeModal">
                                            <i class="fas fa-times-circle mr-2"></i>Cancel Discharge
                                        </button>
                                    <?php elseif ($preparation['discharge_status'] == 'completed'): ?>
                                        <span class="badge badge-success p-2">
                                            <i class="fas fa-check-circle mr-2"></i>Discharge completed on 
                                            <?php echo date('M j, Y H:i', strtotime($preparation['discharge_completed_at'])); ?>
                                        </span>
                                    <?php elseif ($preparation['discharge_status'] == 'cancelled'): ?>
                                        <span class="badge badge-secondary p-2">
                                            <i class="fas fa-ban mr-2"></i>Discharge cancelled on 
                                            <?php echo date('M j, Y H:i', strtotime($preparation['cancelled_at'])); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Checklist Items -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-success py-2">
                        <h4 class="card-title mb-0 text-white">
                            <i class="fas fa-tasks mr-2"></i>Discharge Checklist
                            <span class="badge badge-light float-right"><?php echo $completed_items; ?>/<?php echo count($checklist_items); ?> completed</span>
                        </h4>
                    </div>
                    <div class="card-body p-0">
                        <?php if (!empty($checklist_items)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th width="5%">#</th>
                                            <th width="50%">Task Description</th>
                                            <th width="15%">Category</th>
                                            <th width="15%">Status</th>
                                            <th width="15%" class="text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $counter = 1;
                                        foreach ($category_groups as $category => $items): 
                                            $category_name = ucfirst($category);
                                            $category_completed = 0;
                                            foreach ($items as $item) {
                                                if (isset($item['item_status']) && $item['item_status'] == 'completed') {
                                                    $category_completed++;
                                                }
                                            }
                                        ?>
                                            <tr class="bg-light">
                                                <td colspan="5" class="font-weight-bold">
                                                    <i class="fas fa-folder mr-2"></i><?php echo $category_name; ?>
                                                    <span class="badge badge-primary ml-2">
                                                        <?php echo $category_completed; ?>/<?php echo count($items); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <?php foreach ($items as $item): ?>
                                                <tr>
                                                    <td><?php echo $counter++; ?></td>
                                                    <td>
                                                        <div class="font-weight-bold">
                                                            <?php echo isset($item['item_description']) ? htmlspecialchars($item['item_description']) : 'No description'; ?>
                                                        </div>
                                                        <?php if (isset($item['assigned_to_name'])): ?>
                                                            <small class="text-muted">
                                                                <i class="fas fa-user mr-1"></i>
                                                                <?php echo htmlspecialchars($item['assigned_to_name']); ?>
                                                            </small>
                                                        <?php endif; ?>
                                                        <?php if (isset($item['notes'])): ?>
                                                            <div class="text-muted small">
                                                                <i class="fas fa-note mr-1"></i>
                                                                <?php echo htmlspecialchars(substr($item['notes'], 0, 50)); ?>...
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span class="badge badge-secondary">
                                                            <?php echo isset($item['checklist_category']) ? ucfirst($item['checklist_category']) : 'Other'; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php echo getChecklistStatusBadge(isset($item['item_status']) ? $item['item_status'] : 'pending'); ?>
                                                        <?php if (isset($item['completed_at'])): ?>
                                                            <br>
                                                            <small class="text-muted">
                                                                <?php echo date('H:i', strtotime($item['completed_at'])); ?>
                                                            </small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <button type="button" class="btn btn-sm btn-info" 
                                                                data-toggle="modal" data-target="#updateChecklistModal"
                                                                onclick="setUpdateChecklist(<?php echo htmlspecialchars(json_encode($item)); ?>)"
                                                                title="Update Status">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-tasks fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">No Checklist Items</h5>
                                <p class="text-muted">Checklist items will be created automatically.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Discharge Medications -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-warning py-2">
                        <h4 class="card-title mb-0 text-white">
                            <i class="fas fa-pills mr-2"></i>Discharge Medications
                            <span class="badge badge-light float-right"><?php echo count($discharge_medications); ?> medications</span>
                        </h4>
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-12 text-right">
                                <button type="button" class="btn btn-sm btn-success" data-toggle="modal" data-target="#addMedicationModal">
                                    <i class="fas fa-plus mr-2"></i>Add Medication
                                </button>
                            </div>
                        </div>
                        
                        <?php if (!empty($discharge_medications)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Medication</th>
                                            <th>Dosage & Frequency</th>
                                            <th>Duration</th>
                                            <th>Instructions</th>
                                            <th>Status</th>
                                            <th class="text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($discharge_medications as $med): ?>
                                            <tr class="<?php echo !empty($med['prescription_dispensed_at']) ? 'table-success' : ''; ?>">
                                                <td>
                                                    <div class="font-weight-bold">
                                                        <?php echo htmlspecialchars($med['pi_dosage'] ?? 'Unknown'); ?>
                                                    </div>
                                                    <small class="text-muted">
                                                        <?php echo htmlspecialchars($med['prescription_instructions'] ?? 'N/A'); ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <div>
                                                        <?php echo htmlspecialchars($med['pi_dosage'] ?? ''); ?>
                                                    </div>
                                                    <small class="text-muted">
                                                        <?php echo htmlspecialchars($med['pi_frequency'] ?? ''); ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars(($med['pi_duration'] ?? '') . ' ' . ($med['pi_duration_unit'] ?? '')); ?>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars(substr($med['pi_instructions'] ?? '', 0, 50)); ?>...
                                                    <?php if (isset($med['pi_dispensed_quantity']) && $med['pi_dispensed_quantity'] > 0): ?>
                                                        <div class="text-info small">
                                                            Qty: <?php echo $med['pi_dispensed_quantity']; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($med['prescription_dispensed_at'])): ?>
                                                        <span class="badge badge-success">
                                                            <i class="fas fa-check-circle mr-1"></i>Dispensed
                                                        </span>
                                                        <br>
                                                        <small class="text-muted">
                                                            <?php echo date('M j, H:i', strtotime($med['prescription_dispensed_at'])); ?>
                                                        </small>
                                                    <?php else: ?>
                                                        <span class="badge badge-warning">
                                                            <i class="fas fa-clock mr-1"></i>Pending
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <?php if (empty($med['prescription_dispensed_at'])): ?>
                                                        <form method="POST" style="display: inline;">
                                                            <input type="hidden" name="prescription_id" value="<?php echo $med['prescription_id']; ?>">
                                                            <button type="submit" name="dispense_prescription" class="btn btn-sm btn-success"
                                                                    onclick="return confirm('Mark this prescription as dispensed?')"
                                                                    title="Mark as Dispensed">
                                                                <i class="fas fa-check"></i>
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
                                <i class="fas fa-pills fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">No Discharge Medications</h5>
                                <p class="text-muted">Add medications that need to be prescribed at discharge.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Discharge Documents -->
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-info py-2">
                        <h4 class="card-title mb-0 text-white">
                            <i class="fas fa-file-medical mr-2"></i>Discharge Documents
                            <span class="badge badge-light float-right"><?php echo count($discharge_documents); ?> documents</span>
                        </h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php if (!empty($discharge_documents)): ?>
                                <?php foreach ($discharge_documents as $doc): ?>
                                    <div class="col-md-4 mb-3">
                                        <div class="card document-card">
                                            <div class="card-body">
                                                <div class="d-flex justify-content-between align-items-center mb-2">
                                                    <div>
                                                        <?php 
                                                        $icon_class = 'fa-file';
                                                        $icon_color = 'text-primary';
                                                        if (isset($doc['file_type']) && strpos($doc['file_type'], 'pdf') !== false) {
                                                            $icon_class = 'fa-file-pdf';
                                                            $icon_color = 'text-danger';
                                                        } elseif (isset($doc['file_type']) && strpos($doc['file_type'], 'image') !== false) {
                                                            $icon_class = 'fa-file-image';
                                                            $icon_color = 'text-success';
                                                        } elseif (isset($doc['file_type']) && strpos($doc['file_type'], 'word') !== false) {
                                                            $icon_class = 'fa-file-word';
                                                            $icon_color = 'text-primary';
                                                        } elseif (isset($doc['file_type']) && strpos($doc['file_type'], 'excel') !== false) {
                                                            $icon_class = 'fa-file-excel';
                                                            $icon_color = 'text-success';
                                                        }
                                                        ?>
                                                        <i class="fas <?php echo $icon_class; ?> fa-2x <?php echo $icon_color; ?> mr-2"></i>
                                                    </div>
                                                    <div>
                                                        <span class="badge badge-secondary">
                                                            <?php echo htmlspecialchars($doc['file_category'] ?? 'Unknown'); ?>
                                                        </span>
                                                    </div>
                                                </div>
                                                <h6 class="card-title">
                                                    <?php echo htmlspecialchars($doc['file_original_name'] ?? 'Unknown file'); ?>
                                                </h6>
                                                <p class="card-text text-muted small">
                                                    Uploaded: <?php echo date('M j, Y', strtotime($doc['file_uploaded_at'])); ?>
                                                    <?php if (isset($doc['uploaded_by_name'])): ?>
                                                        <br>By: <?php echo htmlspecialchars($doc['uploaded_by_name']); ?>
                                                    <?php endif; ?>
                                                </p>
                                                <div class="text-center">
                                                    <?php if (isset($doc['file_path']) && $doc['file_path']): ?>
                                                        <a href="<?php echo htmlspecialchars($doc['file_path']); ?>" 
                                                           target="_blank" class="btn btn-sm btn-outline-primary">
                                                            <i class="fas fa-download mr-1"></i>Download
                                                        </a>
                                                    <?php else: ?>
                                                        <button type="button" class="btn btn-sm btn-outline-secondary" disabled>
                                                            <i class="fas fa-exclamation-triangle mr-1"></i>No File
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="col-md-12">
                                    <div class="text-center py-5">
                                        <i class="fas fa-file-medical fa-3x text-muted mb-3"></i>
                                        <h5 class="text-muted">No Discharge Documents</h5>
                                        <p class="text-muted">Discharge documents will be added here.</p>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <?php else: ?>
        <!-- No Preparation Message -->
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="fas fa-home fa-4x text-muted mb-4"></i>
                        <h3 class="text-muted">No Discharge Preparation Started</h3>
                        <p class="text-muted mb-4">
                            Start the discharge preparation process to begin organizing everything needed for patient discharge.
                        </p>
                        <button type="button" class="btn btn-lg btn-success" data-toggle="modal" data-target="#createPreparationModal">
                            <i class="fas fa-plus mr-2"></i>Start Discharge Preparation
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Create Preparation Modal -->
<div class="modal fade" id="createPreparationModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" id="createPreparationForm">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Start Discharge Preparation</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="discharge_date">Planned Discharge Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="discharge_date" name="discharge_date" 
                                       value="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="discharge_time">Planned Discharge Time <span class="text-danger">*</span></label>
                                <input type="time" class="form-control" id="discharge_time" name="discharge_time" 
                                       value="10:00" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="discharge_type">Discharge Type <span class="text-danger">*</span></label>
                                <select class="form-control" id="discharge_type" name="discharge_type" required>
                                    <option value="routine">Routine Discharge</option>
                                    <option value="planned">Planned Discharge</option>
                                    <option value="emergency">Emergency Discharge</option>
                                    <option value="transfer">Transfer to Another Facility</option>
                                    <option value="left_against_advice">Left Against Medical Advice</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="discharge_status">Initial Status <span class="text-danger">*</span></label>
                                <select class="form-control" id="discharge_status" name="discharge_status" required>
                                    <option value="pending">Pending</option>
                                    <option value="in_progress">In Progress</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="notes">Initial Notes</label>
                        <textarea class="form-control" id="notes" name="notes" 
                                  rows="3" placeholder="Any initial notes about the discharge..."></textarea>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle mr-2"></i>
                        Starting discharge preparation will create a checklist of tasks that need to be completed before the patient can be discharged.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" name="save_preparation" class="btn btn-primary">
                        <i class="fas fa-play mr-2"></i>Start Preparation
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Preparation Modal -->
<?php if ($preparation): ?>
<div class="modal fade" id="editPreparationModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" id="editPreparationForm">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title">Edit Discharge Preparation</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <ul class="nav nav-tabs" id="preparationTabs" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link active" id="basic-tab" data-toggle="tab" href="#basic" role="tab">
                                <i class="fas fa-info-circle mr-1"></i>Basic Info
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" id="medical-tab" data-toggle="tab" href="#medical" role="tab">
                                <i class="fas fa-user-md mr-1"></i>Medical
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" id="medications-tab" data-toggle="tab" href="#medications" role="tab">
                                <i class="fas fa-pills mr-1"></i>Medications
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" id="documentation-tab" data-toggle="tab" href="#documentation" role="tab">
                                <i class="fas fa-file-medical mr-1"></i>Documentation
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" id="financial-tab" data-toggle="tab" href="#financial" role="tab">
                                <i class="fas fa-money-bill-wave mr-1"></i>Financial
                            </a>
                        </li>
                    </ul>
                    
                    <div class="tab-content p-3" id="preparationTabsContent">
                        <!-- Basic Info Tab -->
                        <div class="tab-pane fade show active" id="basic" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="edit_discharge_date">Discharge Date</label>
                                        <input type="date" class="form-control" id="edit_discharge_date" name="discharge_date" 
                                               value="<?php echo htmlspecialchars($preparation['discharge_date'] ?? ''); ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="edit_discharge_time">Discharge Time</label>
                                        <input type="time" class="form-control" id="edit_discharge_time" name="discharge_time" 
                                               value="<?php echo htmlspecialchars($preparation['discharge_time'] ?? '10:00'); ?>" required>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="edit_discharge_type">Discharge Type</label>
                                        <select class="form-control" id="edit_discharge_type" name="discharge_type" required>
                                            <option value="routine" <?php echo ($preparation['discharge_type'] ?? '') == 'routine' ? 'selected' : ''; ?>>Routine Discharge</option>
                                            <option value="planned" <?php echo ($preparation['discharge_type'] ?? '') == 'planned' ? 'selected' : ''; ?>>Planned Discharge</option>
                                            <option value="emergency" <?php echo ($preparation['discharge_type'] ?? '') == 'emergency' ? 'selected' : ''; ?>>Emergency Discharge</option>
                                            <option value="transfer" <?php echo ($preparation['discharge_type'] ?? '') == 'transfer' ? 'selected' : ''; ?>>Transfer to Another Facility</option>
                                            <option value="left_against_advice" <?php echo ($preparation['discharge_type'] ?? '') == 'left_against_advice' ? 'selected' : ''; ?>>Left Against Medical Advice</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="edit_discharge_status">Status</label>
                                        <select class="form-control" id="edit_discharge_status" name="discharge_status" required>
                                            <option value="pending" <?php echo ($preparation['discharge_status'] ?? '') == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                            <option value="in_progress" <?php echo ($preparation['discharge_status'] ?? '') == 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="edit_notes">Notes</label>
                                <textarea class="form-control" id="edit_notes" name="notes" rows="3"><?php echo htmlspecialchars($preparation['notes'] ?? ''); ?></textarea>
                            </div>
                        </div>
                        
                        <!-- Medical Tab -->
                        <div class="tab-pane fade" id="medical" role="tabpanel">
                            <div class="form-group">
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input" id="edit_medical_clearance" 
                                           name="medical_clearance_obtained" value="1" 
                                           <?php echo isset($preparation['medical_clearance_obtained']) && $preparation['medical_clearance_obtained'] ? 'checked' : ''; ?>>
                                    <label class="custom-control-label" for="edit_medical_clearance">
                                        <strong>Medical Clearance Obtained</strong>
                                    </label>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="edit_clearance_physician">Clearing Physician</label>
                                        <select class="form-control" id="edit_clearance_physician" name="clearance_physician_id">
                                            <option value="">Select Physician</option>
                                            <?php foreach ($available_doctors as $doctor): ?>
                                                <option value="<?php echo $doctor['user_id']; ?>" 
                                                    <?php echo ($preparation['clearance_physician_id'] ?? 0) == $doctor['user_id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($doctor['user_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="edit_clearance_date">Clearance Date</label>
                                        <input type="date" class="form-control" id="edit_clearance_date" name="clearance_date" 
                                               value="<?php echo htmlspecialchars($preparation['clearance_date'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="edit_clearance_notes">Clearance Notes</label>
                                <textarea class="form-control" id="edit_clearance_notes" name="clearance_notes" rows="3"><?php echo htmlspecialchars($preparation['clearance_notes'] ?? ''); ?></textarea>
                            </div>
                        </div>
                        
                        <!-- Medications Tab -->
                        <div class="tab-pane fade" id="medications" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <div class="custom-control custom-checkbox">
                                            <input type="checkbox" class="custom-control-input" id="edit_medications_ready" 
                                                   name="medications_ready" value="1" 
                                                   <?php echo isset($preparation['medications_ready']) && $preparation['medications_ready'] ? 'checked' : ''; ?>>
                                            <label class="custom-control-label" for="edit_medications_ready">
                                                <strong>Medications Ready</strong>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <div class="custom-control custom-checkbox">
                                            <input type="checkbox" class="custom-control-input" id="edit_medications_dispensed" 
                                                   name="medications_dispensed" value="1" 
                                                   <?php echo isset($preparation['medications_dispensed']) && $preparation['medications_dispensed'] ? 'checked' : ''; ?>>
                                            <label class="custom-control-label" for="edit_medications_dispensed">
                                                <strong>Medications Dispensed</strong>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input" id="edit_prescriptions_printed" 
                                           name="prescriptions_printed" value="1" 
                                           <?php echo isset($preparation['prescriptions_printed']) && $preparation['prescriptions_printed'] ? 'checked' : ''; ?>>
                                    <label class="custom-control-label" for="edit_prescriptions_printed">
                                        <strong>Prescriptions Printed</strong>
                                    </label>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="edit_medication_instructions">Medication Instructions</label>
                                <textarea class="form-control" id="edit_medication_instructions" name="medication_instructions" rows="3"><?php echo htmlspecialchars($preparation['medication_instructions'] ?? ''); ?></textarea>
                            </div>
                        </div>
                        
                        <!-- Documentation Tab -->
                        <div class="tab-pane fade" id="documentation" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <div class="custom-control custom-checkbox">
                                            <input type="checkbox" class="custom-control-input" id="edit_discharge_summary" 
                                                   name="discharge_summary_ready" value="1" 
                                                   <?php echo isset($preparation['discharge_summary_ready']) && $preparation['discharge_summary_ready'] ? 'checked' : ''; ?>>
                                            <label class="custom-control-label" for="edit_discharge_summary">
                                                <strong>Discharge Summary Ready</strong>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <div class="custom-control custom-checkbox">
                                            <input type="checkbox" class="custom-control-input" id="edit_patient_instructions" 
                                                   name="patient_instructions_ready" value="1" 
                                                   <?php echo isset($preparation['patient_instructions_ready']) && $preparation['patient_instructions_ready'] ? 'checked' : ''; ?>>
                                            <label class="custom-control-label" for="edit_patient_instructions">
                                                <strong>Patient Instructions Ready</strong>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input" id="edit_followup_scheduled" 
                                           name="followup_appointment_scheduled" value="1" 
                                           <?php echo isset($preparation['followup_appointment_scheduled']) && $preparation['followup_appointment_scheduled'] ? 'checked' : ''; ?>>
                                    <label class="custom-control-label" for="edit_followup_scheduled">
                                        <strong>Follow-up Appointment Scheduled</strong>
                                    </label>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="edit_followup_date">Follow-up Date</label>
                                        <input type="date" class="form-control" id="edit_followup_date" name="followup_date" 
                                               value="<?php echo htmlspecialchars($preparation['followup_date'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="edit_followup_physician">Follow-up Physician</label>
                                        <select class="form-control" id="edit_followup_physician" name="followup_physician_id">
                                            <option value="">Select Physician</option>
                                            <?php foreach ($available_doctors as $doctor): ?>
                                                <option value="<?php echo $doctor['user_id']; ?>" 
                                                    <?php echo ($preparation['followup_physician_id'] ?? 0) == $doctor['user_id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($doctor['user_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="edit_followup_notes">Follow-up Notes</label>
                                <textarea class="form-control" id="edit_followup_notes" name="followup_notes" rows="3"><?php echo htmlspecialchars($preparation['followup_notes'] ?? ''); ?></textarea>
                            </div>
                        </div>
                        
                        <!-- Financial Tab -->
                        <div class="tab-pane fade" id="financial" role="tabpanel">
                            <div class="form-group">
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input" id="edit_billing_cleared" 
                                           name="billing_cleared" value="1" 
                                           <?php echo isset($preparation['billing_cleared']) && $preparation['billing_cleared'] ? 'checked' : ''; ?>>
                                    <label class="custom-control-label" for="edit_billing_cleared">
                                        <strong>Billing Cleared</strong>
                                    </label>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="edit_billing_amount">Total Amount</label>
                                        <input type="number" class="form-control" id="edit_billing_amount" name="billing_amount" 
                                               step="0.01" value="<?php echo htmlspecialchars($preparation['billing_amount'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="edit_billing_paid">Amount Paid</label>
                                        <input type="number" class="form-control" id="edit_billing_paid" name="billing_paid" 
                                               step="0.01" value="<?php echo htmlspecialchars($preparation['billing_paid'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="edit_billing_balance">Balance</label>
                                        <input type="number" class="form-control" id="edit_billing_balance" name="billing_balance" 
                                               step="0.01" value="<?php echo htmlspecialchars($preparation['billing_balance'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="edit_billing_notes">Billing Notes</label>
                                <textarea class="form-control" id="edit_billing_notes" name="billing_notes" rows="3"><?php echo htmlspecialchars($preparation['billing_notes'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" name="save_preparation" class="btn btn-warning">
                        <i class="fas fa-save mr-2"></i>Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Update Checklist Modal -->
<div class="modal fade" id="updateChecklistModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST" id="updateChecklistForm">
                <input type="hidden" name="item_id" id="update_item_id">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title">Update Checklist Item</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label for="update_item_status">Status</label>
                        <select class="form-control" id="update_item_status" name="item_status" required>
                            <option value="pending">Pending</option>
                            <option value="in_progress">In Progress</option>
                            <option value="completed">Completed</option>
                            <option value="not_applicable">Not Applicable</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="update_item_notes">Notes</label>
                        <textarea class="form-control" id="update_item_notes" name="item_notes" rows="3" 
                                  placeholder="Add notes about this task..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_checklist_item" class="btn btn-info">
                        <i class="fas fa-save mr-2"></i>Update Item
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Medication Modal -->
<div class="modal fade" id="addMedicationModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST" id="addMedicationForm">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">Add Discharge Medication</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label for="medication_name">Medication Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="medication_name" name="medication_name" 
                               placeholder="e.g., Amoxicillin 500mg" required>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="dosage">Dosage</label>
                                <input type="text" class="form-control" id="dosage" name="dosage" 
                                       placeholder="e.g., 500mg">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="frequency">Frequency</label>
                                <input type="text" class="form-control" id="frequency" name="frequency" 
                                       placeholder="e.g., TID (3 times daily)">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="duration">Duration</label>
                                <input type="text" class="form-control" id="duration" name="duration" 
                                       placeholder="e.g., 7 days">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="route">Route</label>
                                <select class="form-control" id="route" name="route">
                                    <option value="">Select Route</option>
                                    <option value="Oral">Oral</option>
                                    <option value="IV">IV</option>
                                    <option value="IM">IM</option>
                                    <option value="SC">SC</option>
                                    <option value="Topical">Topical</option>
                                    <option value="Inhalation">Inhalation</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="quantity_dispensed">Quantity</label>
                                <input type="number" class="form-control" id="quantity_dispensed" name="quantity_dispensed" 
                                       min="0" value="0">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="refills">Refills</label>
                                <input type="number" class="form-control" id="refills" name="refills" 
                                       min="0" value="0">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="instructions">Special Instructions</label>
                        <textarea class="form-control" id="instructions" name="instructions" rows="2" 
                                  placeholder="e.g., Take with food, Avoid alcohol..."></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="prescribed_by">Prescribed By</label>
                        <select class="form-control" id="prescribed_by" name="prescribed_by">
                            <option value="">Select Prescriber</option>
                            <?php foreach ($available_doctors as $doctor): ?>
                                <option value="<?php echo $doctor['user_id']; ?>">
                                    <?php echo htmlspecialchars($doctor['user_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="med_notes">Notes</label>
                        <textarea class="form-control" id="med_notes" name="med_notes" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_medication" class="btn btn-success">
                        <i class="fas fa-plus mr-2"></i>Add Medication
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Complete Discharge Modal -->
<div class="modal fade" id="completeDischargeModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST" id="completeDischargeForm">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">Complete Discharge</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        <strong>Warning:</strong> Completing discharge will:
                        <ul class="mt-2 mb-0">
                            <li>Mark the patient as officially discharged</li>
                            <li>Update the IPD admission record</li>
                            <li>Update the visit status</li>
                            <li>Free up the patient's bed</li>
                            <li>Cannot be undone automatically</li>
                        </ul>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle mr-2"></i>
                        <strong>Completion Checklist:</strong>
                        <div class="mt-2">
                            <?php 
                            $critical_items = [
                                'Medical clearance obtained' => isset($preparation['medical_clearance_obtained']) && $preparation['medical_clearance_obtained'],
                                'Medications dispensed' => isset($preparation['medications_dispensed']) && $preparation['medications_dispensed'],
                                'Discharge summary ready' => isset($preparation['discharge_summary_ready']) && $preparation['discharge_summary_ready'],
                                'Patient instructions ready' => isset($preparation['patient_instructions_ready']) && $preparation['patient_instructions_ready'],
                                'Billing cleared' => isset($preparation['billing_cleared']) && $preparation['billing_cleared'],
                                'Patient ready for discharge' => isset($preparation['patient_ready_for_discharge']) && $preparation['patient_ready_for_discharge']
                            ];
                            
                            foreach ($critical_items as $item => $status):
                            ?>
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input" 
                                           id="check_<?php echo str_replace(' ', '_', strtolower($item)); ?>" 
                                           <?php echo $status ? 'checked' : 'disabled'; ?>>
                                    <label class="custom-control-label <?php echo $status ? 'text-success' : 'text-danger'; ?>" 
                                           for="check_<?php echo str_replace(' ', '_', strtolower($item)); ?>">
                                        <?php if ($status): ?>
                                            <i class="fas fa-check-circle mr-1"></i>
                                        <?php else: ?>
                                            <i class="fas fa-times-circle mr-1"></i>
                                        <?php endif; ?>
                                        <?php echo $item; ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Overall Completion: <strong><?php echo $completion_percentage; ?>%</strong></label>
                        <div class="progress">
                            <div class="progress-bar <?php echo $completion_percentage >= 90 ? 'bg-success' : 'bg-danger'; ?>" 
                                 style="width: <?php echo $completion_percentage; ?>%">
                                <?php echo $completion_percentage; ?>%
                            </div>
                        </div>
                        <?php if ($completion_percentage < 90): ?>
                            <small class="text-danger">
                                <i class="fas fa-exclamation-circle mr-1"></i>
                                Complete 90% of preparations before discharging
                            </small>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" name="complete_discharge" class="btn btn-success" 
                            <?php echo $completion_percentage < 90 ? 'disabled' : ''; ?>>
                        <i class="fas fa-check-circle mr-2"></i>Complete Discharge
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Cancel Discharge Modal -->
<div class="modal fade" id="cancelDischargeModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST" id="cancelDischargeForm">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">Cancel Discharge Preparation</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        <strong>Warning:</strong> This will cancel all discharge preparations. This action cannot be undone.
                    </div>
                    
                    <div class="form-group">
                        <label for="cancellation_reason">Cancellation Reason <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="cancellation_reason" name="cancellation_reason" 
                                  rows="4" placeholder="Why are you cancelling the discharge preparation?" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                    <button type="submit" name="cancel_discharge" class="btn btn-danger">
                        <i class="fas fa-times-circle mr-2"></i>Cancel Discharge
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize date pickers
    $('#discharge_date').flatpickr({
        dateFormat: 'Y-m-d',
        minDate: 'today'
    });
    
    $('#edit_discharge_date').flatpickr({
        dateFormat: 'Y-m-d',
        minDate: 'today'
    });
    
    $('#edit_clearance_date').flatpickr({
        dateFormat: 'Y-m-d',
        maxDate: 'today'
    });
    
    $('#edit_followup_date').flatpickr({
        dateFormat: 'Y-m-d',
        minDate: 'today'
    });
    
    // Auto-expand textareas
    $('textarea').on('input', function() {
        this.style.height = 'auto';
        this.style.height = (this.scrollHeight) + 'px';
    });
    
    // Calculate billing balance
    $('#edit_billing_amount, #edit_billing_paid').on('input', function() {
        const amount = parseFloat($('#edit_billing_amount').val()) || 0;
        const paid = parseFloat($('#edit_billing_paid').val()) || 0;
        const balance = amount - paid;
        $('#edit_billing_balance').val(balance.toFixed(2));
    });
    
    // Tab navigation
    $('#preparationTabs a').on('click', function (e) {
        e.preventDefault();
        $(this).tab('show');
    });
});

function setUpdateChecklist(item) {
    $('#update_item_id').val(item.item_id);
    $('#update_item_status').val(item.item_status || 'pending');
    $('#update_item_notes').val(item.notes || '');
}

function printDischargeSummary() {
    const printWindow = window.open('', '_blank');
    const patientInfo = `Patient: ${'<?php echo addslashes($full_name); ?>'} | MRN: ${'<?php echo $patient_info['patient_mrn']; ?>'} | Visit ID: ${'<?php echo $visit_id; ?>'}`;
    
    let html = `
        <html>
        <head>
            <title>Discharge Preparation Summary - ${'<?php echo $patient_info['patient_mrn']; ?>'}</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                h1, h2, h3 { color: #333; }
                .summary-section { margin: 20px 0; padding: 15px; border: 1px solid #ddd; border-radius: 5px; }
                .completed { color: #28a745; }
                .pending { color: #ffc107; }
                table { width: 100%; border-collapse: collapse; margin: 10px 0; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                th { background-color: #f2f2f2; }
                @media print {
                    .no-print { display: none; }
                    body { margin: 0; padding: 10px; }
                }
            </style>
        </head>
        <body>
            <h1>Discharge Preparation Summary</h1>
            <h2>${patientInfo}</h2>
            <p>Printed: ${new Date().toLocaleString()}</p>
            
            <div class="summary-section">
                <h3>Patient Information</h3>
                <table>
                    <tr><th>Name</th><td>${'<?php echo addslashes($full_name); ?>'}</td></tr>
                    <tr><th>MRN</th><td>${'<?php echo $patient_info['patient_mrn']; ?>'}</td></tr>
                    <tr><th>Admission Date</th><td>${'<?php echo date('M j, Y', strtotime($ipd_info['admission_datetime'])); ?>'}</td></tr>
                    <tr><th>Length of Stay</th><td>${'<?php echo $ipd_info['length_of_stay']; ?>'} days</td></tr>
                    <tr><th>Ward/Bed</th><td>${'<?php echo htmlspecialchars($ipd_info['ward_name'] ?? 'Not assigned'); ?>'} / ${'<?php echo htmlspecialchars($ipd_info['bed_number'] ?? 'Not assigned'); ?>'}</td></tr>
                </table>
            </div>
    `;
    
    <?php if ($preparation): ?>
    html += `
            <div class="summary-section">
                <h3>Discharge Information</h3>
                <table>
                    <tr><th>Planned Discharge</th><td>${'<?php echo date('M j, Y', strtotime($preparation['discharge_date'])); ?>'} ${'<?php echo $preparation['discharge_time']; ?>'}</td></tr>
                    <tr><th>Discharge Type</th><td>${'<?php echo $preparation['discharge_type']; ?>'}</td></tr>
                    <tr><th>Status</th><td>${'<?php echo $preparation['discharge_status']; ?>'}</td></tr>
                    <tr><th>Completion</th><td>${'<?php echo $completion_percentage; ?>'}%</td></tr>
                </table>
            </div>
            
            <div class="summary-section">
                <h3>Checklist Summary</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th>Completed</th>
                            <th>Total</th>
                            <th>Percentage</th>
                        </tr>
                    </thead>
                    <tbody>
    `;
    
    <?php 
    if (!empty($category_groups)) {
        foreach ($category_groups as $category => $items) {
            $completed = 0;
            foreach ($items as $item) {
                if (isset($item['item_status']) && $item['item_status'] == 'completed') {
                    $completed++;
                }
            }
            $percentage = count($items) > 0 ? round(($completed / count($items)) * 100) : 0;
    ?>
    html += `<tr>
                <td>${'<?php echo ucfirst($category); ?>'}</td>
                <td>${'<?php echo $completed; ?>'}</td>
                <td>${'<?php echo count($items); ?>'}</td>
                <td>${'<?php echo $percentage; ?>'}%</td>
            </tr>`;
    <?php 
        }
    }
    ?>
    
    html += `       </tbody>
                </table>
            </div>
            
            <div class="summary-section">
                <h3>Medications (${'<?php echo count($discharge_medications); ?>'})</h3>
    `;
    
    <?php if (!empty($discharge_medications)): ?>
    html += `<table>
                <thead>
                    <tr>
                        <th>Medication</th>
                        <th>Dosage</th>
                        <th>Frequency</th>
                        <th>Duration</th>
                    </tr>
                </thead>
                <tbody>`;
    <?php foreach ($discharge_medications as $med): ?>
    html += `<tr>
                <td>${'<?php echo addslashes($med['pi_dosage'] ?? 'Unknown'); ?>'}</td>
                <td>${'<?php echo addslashes($med['pi_dosage'] ?? ''); ?>'}</td>
                <td>${'<?php echo addslashes($med['pi_frequency'] ?? ''); ?>'}</td>
                <td>${'<?php echo addslashes(($med['pi_duration'] ?? '') . ' ' . ($med['pi_duration_unit'] ?? '')); ?>'}</td>
            </tr>`;
    <?php endforeach; ?>
    html += `   </tbody>
            </table>`;
    <?php else: ?>
    html += `<p>No discharge medications prescribed.</p>`;
    <?php endif; ?>
    
    html += `</div>`;
    <?php else: ?>
    html += `<div class="summary-section">
                <h3>Discharge Status</h3>
                <p>No discharge preparation started yet.</p>
            </div>`;
    <?php endif; ?>
    
    html += `
            <div class="no-print">
                <br><br>
                <button onclick="window.print()">Print</button>
                <button onclick="window.close()">Close</button>
            </div>
        </body>
        </html>
    `;
    
    printWindow.document.write(html);
    printWindow.document.close();
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
    // Ctrl + D for discharge
    if (e.ctrlKey && e.keyCode === 68) {
        e.preventDefault();
        <?php if (!$preparation): ?>
            $('#createPreparationModal').modal('show');
        <?php else: ?>
            $('#editPreparationModal').modal('show');
        <?php endif; ?>
    }
    // Ctrl + M for medication
    if (e.ctrlKey && e.keyCode === 77) {
        e.preventDefault();
        <?php if ($preparation): ?>
            $('#addMedicationModal').modal('show');
        <?php endif; ?>
    }
    // Ctrl + P for print
    if (e.ctrlKey && e.keyCode === 80) {
        e.preventDefault();
        printDischargeSummary();
    }
    // Escape to close modals
    if (e.keyCode === 27) {
        $('.modal').modal('hide');
    }
});
</script>

<style>
.info-box {
    box-shadow: 0 0 1px rgba(0,0,0,.125), 0 1px 3px rgba(0,0,0,.2);
    border-radius: .25rem;
    background: #fff;
    display: flex;
    margin-bottom: 1rem;
    min-height: 80px;
    padding: .5rem;
    position: relative;
}
.info-box .info-box-icon {
    border-radius: .25rem;
    align-items: center;
    display: flex;
    font-size: 1.875rem;
    justify-content: center;
    text-align: center;
    width: 70px;
}
.info-box .info-box-content {
    display: flex;
    flex-direction: column;
    justify-content: center;
    line-height: 1.8;
    flex: 1;
    padding: 0 10px;
}
.info-box .info-box-text, .info-box .info-box-number {
    display: block;
    overflow: hidden;
    text-overflow: ellipsis;
}
.info-box .info-box-text {
    font-size: 14px;
}
.info-box .info-box-number {
    font-weight: 700;
    font-size: 1.8rem;
}

.document-card {
    cursor: pointer;
    transition: transform 0.2s, box-shadow 0.2s;
}
.document-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    border-color: #007bff;
}

/* Print styles */
@media print {
    .card-header, .card-tools, .btn, form, .modal,
    .card-footer, .toast-container, .info-box-icon {
        display: none !important;
    }
    .card {
        border: none !important;
        box-shadow: none !important;
    }
    .card-body {
        padding: 0 !important;
    }
    .info-box {
        border: 1px solid #ddd;
        margin: 5px;
    }
    table {
        font-size: 10px !important;
    }
}
</style>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
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
        'module'      => 'Doctor Consultation',
        'table_name'  => 'visits',
        'entity_type' => 'visit',
        'record_id'   => null,
        'patient_id'  => null,
        'visit_id'    => null,
        'description' => "Attempted to access doctor_consultation.php with invalid visit ID: " . $visit_id,
        'status'      => 'FAILED',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    header("Location: /clinic/doctor/dashboard.php");
    exit;
}

// Initialize variables
$patient_info = null;
$visit_info = null;
$doctor_notes = [];
$visit_type = '';
$consultation_status = 'pending';
$today = date('Y-m-d');

// Get visit and patient information using the new visits table
$sql = "SELECT 
            v.*, 
            p.*,
            p.first_name as patient_first_name,
            p.middle_name as patient_middle_name,
            p.last_name as patient_last_name,
            p.date_of_birth as patient_dob,
            p.sex as patient_gender,
            p.blood_group as patient_blood_group,
            d.department_name,
            doc.user_name as attending_provider,
            w.ward_name,
            b.bed_number,
            ia.ipd_admission_id,
            ia.admission_number
        FROM visits v 
        JOIN patients p ON v.patient_id = p.patient_id
        LEFT JOIN departments d ON v.department_id = d.department_id
        LEFT JOIN users doc ON v.attending_provider_id = doc.user_id
        LEFT JOIN ipd_admissions ia ON v.visit_id = ia.visit_id
        LEFT JOIN wards w ON ia.ward_id = w.ward_id
        LEFT JOIN beds b ON ia.bed_id = b.bed_id
        WHERE v.visit_id = ?";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $visit_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $visit_info = $result->fetch_assoc();
    $patient_info = $visit_info;
    $visit_type = $visit_info['visit_type'];
    
    // For IPD visits, set specific IPD info
    if ($visit_type == 'IPD' && $visit_info['ipd_admission_id']) {
        $visit_number = $visit_info['admission_number'];
    } else {
        $visit_number = $visit_info['visit_number'];
    }
} else {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Visit not found";
    
    // AUDIT LOG: Visit not found
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'ACCESS',
        'module'      => 'Doctor Consultation',
        'table_name'  => 'visits',
        'entity_type' => 'visit',
        'record_id'   => $visit_id,
        'patient_id'  => null,
        'visit_id'    => $visit_id,
        'description' => "Attempted to access doctor consultation for visit ID " . $visit_id . " but visit not found",
        'status'      => 'FAILED',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    header("Location: /clinic/doctor/dashboard.php");
    exit;
}

// Get existing doctor consultation notes
$notes_sql = "SELECT dc.*, 
              u.user_name as recorded_by_name
              FROM doctor_consultations dc
              JOIN users u ON dc.recorded_by = u.user_id
              WHERE dc.visit_id = ?
              ORDER BY dc.consultation_date DESC, dc.consultation_time DESC";
$notes_stmt = $mysqli->prepare($notes_sql);
$notes_stmt->bind_param("i", $visit_id);
$notes_stmt->execute();
$notes_result = $notes_stmt->get_result();
$doctor_notes = $notes_result->fetch_all(MYSQLI_ASSOC);

// Get today's consultation
$todays_note = null;
foreach ($doctor_notes as $note) {
    if ($note['consultation_date'] == $today) {
        $todays_note = $note;
        break;
    }
}

// Get latest vitals
$vitals_sql = "SELECT * FROM vitals 
               WHERE visit_id = ? 
               ORDER BY recorded_at DESC 
               LIMIT 1";
$vitals_stmt = $mysqli->prepare($vitals_sql);
$vitals_stmt->bind_param("i", $visit_id);
$vitals_stmt->execute();
$vitals_result = $vitals_stmt->get_result();
$latest_vitals = $vitals_result->fetch_assoc();

// Get count of pending bills for this visit
$pending_bills_sql = "SELECT COUNT(*) AS count FROM pending_bills
                      WHERE visit_id = ? 
                      AND bill_status IN ('pending', 'approved')";
$pending_bills_stmt = $mysqli->prepare($pending_bills_sql);
$pending_bills_stmt->bind_param("i", $visit_id);
$pending_bills_stmt->execute();
$pending_bills_result = $pending_bills_stmt->get_result();
$pending_bills = $pending_bills_result->fetch_assoc();

// Get pending labs
$pending_labs_sql = "SELECT COUNT(*) as count FROM lab_orders 
                     WHERE visit_id = ? 
                     AND lab_order_status IN ('Pending', 'Collected', 'Sent_to_Lab')";
$pending_labs_stmt = $mysqli->prepare($pending_labs_sql);
$pending_labs_stmt->bind_param("i", $visit_id);
$pending_labs_stmt->execute();
$pending_labs_result = $pending_labs_stmt->get_result();
$pending_labs = $pending_labs_result->fetch_assoc();

// Get pending imaging
$pending_imaging_sql = "SELECT COUNT(*) as count FROM radiology_orders 
                        WHERE visit_id = ? 
                        AND order_status IN ('Pending', 'Scheduled')";
$pending_imaging_stmt = $mysqli->prepare($pending_imaging_sql);
$pending_imaging_stmt->bind_param("i", $visit_id);
$pending_imaging_stmt->execute();
$pending_imaging_result = $pending_imaging_stmt->get_result();
$pending_imaging = $pending_imaging_result->fetch_assoc();

// Handle form submission for new consultation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_consultation'])) {
        $chief_complaint = !empty($_POST['chief_complaint']) ? trim($_POST['chief_complaint']) : null;
        $history_present_illness = !empty($_POST['history_present_illness']) ? trim($_POST['history_present_illness']) : null;
        $past_medical_history = !empty($_POST['past_medical_history']) ? trim($_POST['past_medical_history']) : null;
        $allergies = !empty($_POST['allergies']) ? trim($_POST['allergies']) : null;
        $medications = !empty($_POST['medications']) ? trim($_POST['medications']) : null;
        $family_history = !empty($_POST['family_history']) ? trim($_POST['family_history']) : null;
        $social_history = !empty($_POST['social_history']) ? trim($_POST['social_history']) : null;
        
        // Review of systems
        $review_of_systems = isset($_POST['review_of_systems']) ? json_encode($_POST['review_of_systems']) : null;
        
        // Physical exam
        $general_appearance = !empty($_POST['general_appearance']) ? trim($_POST['general_appearance']) : null;
        $vital_signs = !empty($_POST['vital_signs']) ? trim($_POST['vital_signs']) : null;
        $head_neck = !empty($_POST['head_neck']) ? trim($_POST['head_neck']) : null;
        $chest_lungs = !empty($_POST['chest_lungs']) ? trim($_POST['chest_lungs']) : null;
        $cardiovascular = !empty($_POST['cardiovascular']) ? trim($_POST['cardiovascular']) : null;
        $abdomen = !empty($_POST['abdomen']) ? trim($_POST['abdomen']) : null;
        $neurological = !empty($_POST['neurological']) ? trim($_POST['neurological']) : null;
        $musculoskeletal = !empty($_POST['musculoskeletal']) ? trim($_POST['musculoskeletal']) : null;
        $skin = !empty($_POST['skin']) ? trim($_POST['skin']) : null;
        $other_exam = !empty($_POST['other_exam']) ? trim($_POST['other_exam']) : null;
        
        // Assessment
        $primary_diagnosis = !empty($_POST['primary_diagnosis']) ? trim($_POST['primary_diagnosis']) : null;
        $secondary_diagnosis = !empty($_POST['secondary_diagnosis']) ? trim($_POST['secondary_diagnosis']) : null;
        $differential_diagnosis = !empty($_POST['differential_diagnosis']) ? trim($_POST['differential_diagnosis']) : null;
        $icd10_codes = !empty($_POST['icd10_codes']) ? trim($_POST['icd10_codes']) : null;
        $icd11_codes = !empty($_POST['icd11_codes']) ? trim($_POST['icd11_codes']) : null;
        
        // Plan
        $investigations = !empty($_POST['investigations']) ? trim($_POST['investigations']) : null;
        $medication_plan = !empty($_POST['medication_plan']) ? trim($_POST['medication_plan']) : null;
        $procedures = !empty($_POST['procedures']) ? trim($_POST['procedures']) : null;
        $follow_up = !empty($_POST['follow_up']) ? trim($_POST['follow_up']) : null;
        $patient_education = !empty($_POST['patient_education']) ? trim($_POST['patient_education']) : null;
        $referrals = !empty($_POST['referrals']) ? trim($_POST['referrals']) : null;
        
        // Notes
        $clinical_notes = !empty($_POST['clinical_notes']) ? trim($_POST['clinical_notes']) : null;
        $consultation_date = date('Y-m-d');
        $consultation_time = date('H:i:s');
        $recorded_by = $_SESSION['user_id'];
        $consultation_status = isset($_POST['finalize']) ? 'completed' : 'draft';
        
        // AUDIT LOG: Attempt to save consultation
        audit_log($mysqli, [
            'user_id'     => $recorded_by,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'CONSULTATION_SAVE',
            'module'      => 'Doctor Consultation',
            'table_name'  => 'doctor_consultations',
            'entity_type' => 'consultation',
            'record_id'   => null,
            'patient_id'  => $patient_info['patient_id'],
            'visit_id'    => $visit_id,
            'description' => "Attempting to save consultation for visit " . $visit_id . ". Status: " . $consultation_status,
            'status'      => 'ATTEMPT',
            'old_values'  => null,
            'new_values'  => [
                'consultation_date' => $consultation_date,
                'consultation_status' => $consultation_status,
                'chief_complaint' => substr($chief_complaint, 0, 100) . '...' // Truncate for log
            ]
        ]);
        
        // Check if consultation already exists for today
        $check_sql = "SELECT consultation_id FROM doctor_consultations 
                     WHERE visit_id = ? AND consultation_date = ?";
        $check_stmt = $mysqli->prepare($check_sql);
        $check_stmt->bind_param("is", $visit_id, $consultation_date);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $existing_consultation = $check_result->fetch_assoc();
        
        if ($existing_consultation) {
            // Get current consultation for audit log
            $old_consult_sql = "SELECT * FROM doctor_consultations WHERE consultation_id = ?";
            $old_consult_stmt = $mysqli->prepare($old_consult_sql);
            $old_consult_stmt->bind_param("i", $existing_consultation['consultation_id']);
            $old_consult_stmt->execute();
            $old_consult_result = $old_consult_stmt->get_result();
            $old_consultation = $old_consult_result->fetch_assoc();
            
            // Update existing consultation
            $update_sql = "UPDATE doctor_consultations 
                          SET chief_complaint = ?, history_present_illness = ?, 
                              past_medical_history = ?, allergies = ?, medications = ?,
                              family_history = ?, social_history = ?, review_of_systems = ?,
                              general_appearance = ?, vital_signs = ?, head_neck = ?,
                              chest_lungs = ?, cardiovascular = ?, abdomen = ?,
                              neurological = ?, musculoskeletal = ?, skin = ?,
                              other_exam = ?, primary_diagnosis = ?, secondary_diagnosis = ?,
                              differential_diagnosis = ?, icd10_codes = ?, icd11_codes = ?,
                              investigations = ?, medication_plan = ?, procedures = ?,
                              follow_up = ?, patient_education = ?, referrals = ?, clinical_notes = ?,
                              consultation_status = ?, consultation_time = ?,
                              recorded_by = ?, updated_at = NOW()
                          WHERE consultation_id = ?";
            
            $update_stmt = $mysqli->prepare($update_sql);
            $update_stmt->bind_param(
                "ssssssssssssssssssssssssssssssssi",
                $chief_complaint, $history_present_illness, $past_medical_history,
                $allergies, $medications, $family_history, $social_history,
                $review_of_systems, $general_appearance, $vital_signs, $head_neck,
                $chest_lungs, $cardiovascular, $abdomen, $neurological,
                $musculoskeletal, $skin, $other_exam, $primary_diagnosis,
                $secondary_diagnosis, $differential_diagnosis, $icd10_codes,
                $icd11_codes, $investigations, $medication_plan, $procedures,
                $follow_up, $patient_education, $referrals, $clinical_notes,
                $consultation_status, $consultation_time, $recorded_by,
                $existing_consultation['consultation_id']
            );
            
            if ($update_stmt->execute()) {
                $_SESSION['alert_type'] = "success";
                $_SESSION['alert_message'] = "Consultation updated successfully";
                
                // AUDIT LOG: Successful consultation update
                audit_log($mysqli, [
                    'user_id'     => $recorded_by,
                    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                    'action'      => 'CONSULTATION_UPDATE',
                    'module'      => 'Doctor Consultation',
                    'table_name'  => 'doctor_consultations',
                    'entity_type' => 'consultation',
                    'record_id'   => $existing_consultation['consultation_id'],
                    'patient_id'  => $patient_info['patient_id'],
                    'visit_id'    => $visit_id,
                    'description' => "Updated consultation ID " . $existing_consultation['consultation_id'] . " for visit " . $visit_id,
                    'status'      => 'SUCCESS',
                    'old_values'  => [
                        'chief_complaint' => $old_consultation['chief_complaint'] ?? null,
                        'primary_diagnosis' => $old_consultation['primary_diagnosis'] ?? null,
                        'consultation_status' => $old_consultation['consultation_status'] ?? null
                    ],
                    'new_values'  => [
                        'chief_complaint' => $chief_complaint,
                        'primary_diagnosis' => $primary_diagnosis,
                        'consultation_status' => $consultation_status,
                        'updated_by' => $recorded_by
                    ]
                ]);
                
                header("Location: doctor_consultation.php?visit_id=" . $visit_id);
                exit;
            } else {
                // AUDIT LOG: Failed consultation update
                audit_log($mysqli, [
                    'user_id'     => $recorded_by,
                    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                    'action'      => 'CONSULTATION_UPDATE',
                    'module'      => 'Doctor Consultation',
                    'table_name'  => 'doctor_consultations',
                    'entity_type' => 'consultation',
                    'record_id'   => $existing_consultation['consultation_id'],
                    'patient_id'  => $patient_info['patient_id'],
                    'visit_id'    => $visit_id,
                    'description' => "Failed to update consultation ID " . $existing_consultation['consultation_id'] . ". Error: " . $mysqli->error,
                    'status'      => 'FAILED',
                    'old_values'  => null,
                    'new_values'  => null
                ]);
                
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = "Error updating consultation: " . $mysqli->error;
            }
        } else {
            // Insert new consultation
            $insert_sql = "INSERT INTO doctor_consultations 
                          (visit_id, patient_id, chief_complaint, history_present_illness,
                           past_medical_history, allergies, medications, family_history,
                           social_history, review_of_systems, general_appearance,
                           vital_signs, head_neck, chest_lungs, cardiovascular,
                           abdomen, neurological, musculoskeletal, skin, other_exam,
                           primary_diagnosis, secondary_diagnosis, differential_diagnosis,
                           icd10_codes, icd11_codes, investigations, medication_plan, procedures,
                           follow_up, patient_education, referrals, clinical_notes,
                           consultation_date, consultation_time, consultation_status,
                           recorded_by, created_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            
            $insert_stmt = $mysqli->prepare($insert_sql);
            $insert_stmt->bind_param(
                "iisssssssssssssssssssssssssssssssss",
                $visit_id, $patient_info['patient_id'], $chief_complaint,
                $history_present_illness, $past_medical_history, $allergies,
                $medications, $family_history, $social_history, $review_of_systems,
                $general_appearance, $vital_signs, $head_neck, $chest_lungs,
                $cardiovascular, $abdomen, $neurological, $musculoskeletal,
                $skin, $other_exam, $primary_diagnosis, $secondary_diagnosis,
                $differential_diagnosis, $icd10_codes, $icd11_codes, $investigations,
                $medication_plan, $procedures, $follow_up, $patient_education,
                $referrals, $clinical_notes, $consultation_date, $consultation_time,
                $consultation_status, $recorded_by
            );
            
            if ($insert_stmt->execute()) {
                $new_consultation_id = $insert_stmt->insert_id;
                
                $_SESSION['alert_type'] = "success";
                $_SESSION['alert_message'] = "Consultation saved successfully";
                
                // AUDIT LOG: Successful consultation creation
                audit_log($mysqli, [
                    'user_id'     => $recorded_by,
                    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                    'action'      => 'CONSULTATION_CREATE',
                    'module'      => 'Doctor Consultation',
                    'table_name'  => 'doctor_consultations',
                    'entity_type' => 'consultation',
                    'record_id'   => $new_consultation_id,
                    'patient_id'  => $patient_info['patient_id'],
                    'visit_id'    => $visit_id,
                    'description' => "Created new consultation for visit " . $visit_id . ". Consultation ID: " . $new_consultation_id,
                    'status'      => 'SUCCESS',
                    'old_values'  => null,
                    'new_values'  => [
                        'consultation_date' => $consultation_date,
                        'consultation_time' => $consultation_time,
                        'consultation_status' => $consultation_status,
                        'chief_complaint' => substr($chief_complaint, 0, 100) . '...',
                        'primary_diagnosis' => substr($primary_diagnosis, 0, 100) . '...',
                        'recorded_by' => $recorded_by
                    ]
                ]);
                
                header("Location: doctor_consultation.php?visit_id=" . $visit_id);
                exit;
            } else {
                // AUDIT LOG: Failed consultation creation
                audit_log($mysqli, [
                    'user_id'     => $recorded_by,
                    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                    'action'      => 'CONSULTATION_CREATE',
                    'module'      => 'Doctor Consultation',
                    'table_name'  => 'doctor_consultations',
                    'entity_type' => 'consultation',
                    'record_id'   => null,
                    'patient_id'  => $patient_info['patient_id'],
                    'visit_id'    => $visit_id,
                    'description' => "Failed to create consultation for visit " . $visit_id . ". Error: " . $mysqli->error,
                    'status'      => 'FAILED',
                    'old_values'  => null,
                    'new_values'  => null
                ]);
                
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = "Error saving consultation: " . $mysqli->error;
            }
        }
    }
    
    // Handle consultation deletion
    if (isset($_POST['delete_consultation'])) {
        $consultation_id = intval($_POST['consultation_id']);
        
        // Get consultation details before deletion for audit log
        $consult_details_sql = "SELECT * FROM doctor_consultations WHERE consultation_id = ?";
        $consult_details_stmt = $mysqli->prepare($consult_details_sql);
        $consult_details_stmt->bind_param("i", $consultation_id);
        $consult_details_stmt->execute();
        $consult_details_result = $consult_details_stmt->get_result();
        $consultation_to_delete = $consult_details_result->fetch_assoc();
        
        $delete_sql = "DELETE FROM doctor_consultations WHERE consultation_id = ?";
        $delete_stmt = $mysqli->prepare($delete_sql);
        $delete_stmt->bind_param("i", $consultation_id);
        
        if ($delete_stmt->execute()) {
            // AUDIT LOG: Consultation deleted
            audit_log($mysqli, [
                'user_id'     => $_SESSION['user_id'] ?? null,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'CONSULTATION_DELETE',
                'module'      => 'Doctor Consultation',
                'table_name'  => 'doctor_consultations',
                'entity_type' => 'consultation',
                'record_id'   => $consultation_id,
                'patient_id'  => $patient_info['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Deleted consultation ID " . $consultation_id . " for visit " . $visit_id,
                'status'      => 'SUCCESS',
                'old_values'  => [
                    'consultation_date' => $consultation_to_delete['consultation_date'] ?? null,
                    'chief_complaint' => $consultation_to_delete['chief_complaint'] ?? null,
                    'primary_diagnosis' => $consultation_to_delete['primary_diagnosis'] ?? null
                ],
                'new_values'  => null
            ]);
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Consultation deleted successfully";
            header("Location: doctor_consultation.php?visit_id=" . $visit_id);
            exit;
        } else {
            // AUDIT LOG: Failed consultation deletion
            audit_log($mysqli, [
                'user_id'     => $_SESSION['user_id'] ?? null,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'CONSULTATION_DELETE',
                'module'      => 'Doctor Consultation',
                'table_name'  => 'doctor_consultations',
                'entity_type' => 'consultation',
                'record_id'   => $consultation_id,
                'patient_id'  => $patient_info['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Failed to delete consultation ID " . $consultation_id . ". Error: " . $mysqli->error,
                'status'      => 'FAILED',
                'old_values'  => null,
                'new_values'  => null
            ]);
            
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error deleting consultation: " . $mysqli->error;
        }
    }
}

// Get patient full name
$full_name = $patient_info['patient_first_name'] . 
            ($patient_info['patient_middle_name'] ? ' ' . $patient_info['patient_middle_name'] : '') . 
            ' ' . $patient_info['patient_last_name'];

// Calculate age
$age = '';
if (!empty($patient_info['patient_dob'])) {
    $birthDate = new DateTime($patient_info['patient_dob']);
    $today_date = new DateTime();
    $age = $today_date->diff($birthDate)->y . ' years';
}

// AUDIT LOG: Successful access to doctor consultation page
audit_log($mysqli, [
    'user_id'     => $_SESSION['user_id'] ?? null,
    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
    'action'      => 'VIEW',
    'module'      => 'Doctor Consultation',
    'table_name'  => 'visits',
    'entity_type' => 'visit',
    'record_id'   => $visit_id,
    'patient_id'  => $patient_info['patient_id'],
    'visit_id'    => $visit_id,
    'description' => "Accessed doctor consultation page for visit ID " . $visit_id . " (Patient: " . $full_name . ", Visit #: " . $visit_number . ", Type: " . $visit_type . ")",
    'status'      => 'SUCCESS',
    'old_values'  => null,
    'new_values'  => null
]);

// Get status badge
function getConsultationStatusBadge($status) {
    switch($status) {
        case 'completed':
            return '<span class="badge badge-success"><i class="fas fa-check-circle mr-1"></i>Completed</span>';
        case 'draft':
            return '<span class="badge badge-warning"><i class="fas fa-edit mr-1"></i>Draft</span>';
        case 'pending':
            return '<span class="badge badge-info"><i class="fas fa-clock mr-1"></i>Pending</span>';
        default:
            return '<span class="badge badge-secondary">' . $status . '</span>';
    }
}

// Get visit type badge
function getVisitTypeBadge($type) {
    switch($type) {
        case 'OPD':
            return '<span class="badge badge-primary">OPD</span>';
        case 'IPD':
            return '<span class="badge badge-success">IPD</span>';
        case 'EMERGENCY':
            return '<span class="badge badge-danger">EMERGENCY</span>';
        default:
            return '<span class="badge badge-secondary">' . $type . '</span>';
    }
}
?>

<div class="card">
    <div class="card-header bg-primary py-2 text-white">
        <h3 class="card-title mt-2 mb-0">
            <i class="fas fa-fw fa-stethoscope mr-2"></i>Doctor Consultation: <?php echo htmlspecialchars($patient_info['patient_mrn']); ?>
        </h3>
        <div class="card-tools">
            <div class="btn-group">
                <button type="button" class="btn btn-light" onclick="window.history.back()">
                    <i class="fas fa-arrow-left mr-2"></i>Back
                </button>
                <button type="button" class="btn btn-success" onclick="window.print()">
                    <i class="fas fa-print mr-2"></i>Print Consultation
                </button>
                <a href="/clinic/doctor/doctor_orders.php?visit_id=<?php echo $visit_id; ?>" class="btn btn-warning">
                    <i class="fas fa-prescription mr-2"></i>Doctor Orders
                </a>
                <?php if ($visit_type == 'EMERGENCY'): ?>
                    <a href="/clinic/nurse/vitals.php?visit_id=<?php echo $visit_id; ?>&visit_type=<?php echo $visit_type; ?>" class="btn btn-info">
                        <i class="fas fa-heartbeat mr-2"></i>Vitals
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

        <!-- Patient and Visit Info -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card bg-light">
                    <div class="card-body py-2">
                        <div class="row">
                            <div class="col-md-6">
                                <table class="table table-sm table-borderless mb-0">
                                    <tr>
                                        <th width="35%" class="text-muted">Patient:</th>
                                        <td><strong><?php echo htmlspecialchars($full_name); ?></strong></td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">MRN:</th>
                                        <td><span class="badge badge-info"><?php echo htmlspecialchars($patient_info['patient_mrn']); ?></span></td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">Age/Gender:</th>
                                        <td><?php echo $age . ' / ' . htmlspecialchars($patient_info['patient_gender']); ?></td>
                                    </tr>
                                    <?php if(!empty($patient_info['patient_blood_group'])): ?>
                                    <tr>
                                        <th class="text-muted">Blood Group:</th>
                                        <td><span class="badge badge-danger"><?php echo htmlspecialchars($patient_info['patient_blood_group']); ?></span></td>
                                    </tr>
                                    <?php endif; ?>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <table class="table table-sm table-borderless mb-0">
                                    <tr>
                                        <th width="35%" class="text-muted">Visit Type:</th>
                                        <td><?php echo getVisitTypeBadge($visit_type); ?></td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">Visit #:</th>
                                        <td><?php echo htmlspecialchars($visit_number); ?></td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">Date:</th>
                                        <td><?php echo date('F j, Y'); ?></td>
                                    </tr>
                                    <?php if($visit_info['department_name'] ?? false): ?>
                                    <tr>
                                        <th class="text-muted">Department:</th>
                                        <td><?php echo htmlspecialchars($visit_info['department_name']); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                    <?php if(($visit_info['ward_name'] ?? false) && ($visit_info['bed_number'] ?? false)): ?>
                                    <tr>
                                        <th class="text-muted">Ward/Bed:</th>
                                        <td><?php echo htmlspecialchars($visit_info['ward_name'] . ' - ' . $visit_info['bed_number']); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Vital Signs Quick View -->
        <?php if ($latest_vitals): ?>
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card border-primary">
                    <div class="card-header bg-primary py-1 text-white">
                        <h6 class="card-title mb-0">
                            <i class="fas fa-heartbeat mr-2"></i>Latest Vital Signs
                            <small class="float-right"><?php echo date('H:i', strtotime($latest_vitals['recorded_at'])); ?></small>
                        </h6>
                    </div>
                    <div class="card-body p-2">
                        <div class="row text-center">
                            <div class="col-2">
                                <div class="text-muted small">Temperature</div>
                                <div class="h5 <?php echo $latest_vitals['temperature'] > 38 ? 'text-danger font-weight-bold' : 'text-success'; ?>">
                                    <?php echo $latest_vitals['temperature'] ?? '--'; ?>°C
                                </div>
                            </div>
                            <div class="col-2">
                                <div class="text-muted small">Pulse</div>
                                <div class="h5 <?php echo ($latest_vitals['pulse'] < 60 || $latest_vitals['pulse'] > 100) ? 'text-danger font-weight-bold' : 'text-success'; ?>">
                                    <?php echo $latest_vitals['pulse'] ?? '--'; ?> bpm
                                </div>
                            </div>
                            <div class="col-2">
                                <div class="text-muted small">Blood Pressure</div>
                                <div class="h5 <?php echo ($latest_vitals['blood_pressure_systolic'] > 140 || $latest_vitals['blood_pressure_diastolic'] > 90) ? 'text-danger font-weight-bold' : 'text-success'; ?>">
                                    <?php echo $latest_vitals['blood_pressure_systolic'] ?? '--'; ?>/<?php echo $latest_vitals['blood_pressure_diastolic'] ?? '--'; ?>
                                </div>
                            </div>
                            <div class="col-2">
                                <div class="text-muted small">Respiratory Rate</div>
                                <div class="h5 <?php echo ($latest_vitals['respiration_rate'] < 12 || $latest_vitals['respiration_rate'] > 20) ? 'text-danger font-weight-bold' : 'text-success'; ?>">
                                    <?php echo $latest_vitals['respiration_rate'] ?? '--'; ?> /min
                                </div>
                            </div>
                            <div class="col-2">
                                <div class="text-muted small">SpO₂</div>
                                <div class="h5 <?php echo $latest_vitals['oxygen_saturation'] < 95 ? 'text-danger font-weight-bold' : 'text-success'; ?>">
                                    <?php echo $latest_vitals['oxygen_saturation'] ?? '--'; ?>%
                                </div>
                            </div>
                            <div class="col-2">
                                <div class="text-muted small">Pain Score</div>
                                <div class="h5 <?php echo $latest_vitals['pain_score'] > 4 ? 'text-danger font-weight-bold' : 'text-warning'; ?>">
                                    <?php echo $latest_vitals['pain_score'] ?? '0'; ?>/10
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Pending Items Alert -->
        <?php if ($pending_bills['count'] > 0 || $pending_labs['count'] > 0 || $pending_imaging['count'] > 0): ?>
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="alert alert-warning">
                    <h6><i class="fas fa-exclamation-triangle mr-2"></i>Pending Items</h6>
                    <div class="d-flex">
                        <?php if ($pending_bills['count'] > 0): ?>
                            <span class="badge badge-warning mr-3"><?php echo $pending_bills['count']; ?> Bills</span>
                        <?php endif; ?>
                        <?php if ($pending_labs['count'] > 0): ?>
                            <span class="badge badge-info mr-3"><?php echo $pending_labs['count']; ?> Labs</span>
                        <?php endif; ?>
                        <?php if ($pending_imaging['count'] > 0): ?>
                            <span class="badge badge-primary"><?php echo $pending_imaging['count']; ?> Imaging</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="row">
            <!-- Consultation Form -->
            <div class="col-md-8">
                <form method="POST" id="consultationForm">
                    <div class="card mb-4">
                        <div class="card-header bg-success py-2 text-white">
                            <h4 class="card-title mb-0">
                                <i class="fas fa-edit mr-2"></i><?php echo $todays_note ? 'Update' : 'Record'; ?> Doctor Consultation
                                <span class="badge badge-light float-right"><?php echo date('H:i'); ?></span>
                            </h4>
                        </div>
                        <div class="card-body">
                            <!-- Chief Complaint -->
                            <div class="form-group">
                                <label for="chief_complaint" class="font-weight-bold">
                                    <i class="fas fa-exclamation-circle text-danger mr-1"></i>Chief Complaint
                                </label>
                                <input type="text" class="form-control" id="chief_complaint" name="chief_complaint" 
                                       value="<?php echo htmlspecialchars($todays_note['chief_complaint'] ?? ''); ?>"
                                       placeholder="Patient's main complaint..." required>
                            </div>

                            <!-- History of Present Illness -->
                            <div class="form-group">
                                <label for="history_present_illness" class="font-weight-bold">
                                    <i class="fas fa-history text-primary mr-1"></i>History of Present Illness (HPI)
                                </label>
                                <textarea class="form-control" id="history_present_illness" name="history_present_illness" 
                                          rows="3" placeholder="Describe onset, duration, progression, associated symptoms..."><?php echo htmlspecialchars($todays_note['history_present_illness'] ?? ''); ?></textarea>
                            </div>

                            <!-- Past Medical History -->
                            <div class="form-group">
                                <label for="past_medical_history" class="font-weight-bold">
                                    <i class="fas fa-medkit text-info mr-1"></i>Past Medical History (PMH)
                                </label>
                                <textarea class="form-control" id="past_medical_history" name="past_medical_history" 
                                          rows="2" placeholder="Previous medical conditions, surgeries, hospitalizations..."><?php echo htmlspecialchars($todays_note['past_medical_history'] ?? ''); ?></textarea>
                            </div>

                            <!-- Allergies -->
                            <div class="form-group">
                                <label for="allergies" class="font-weight-bold">
                                    <i class="fas fa-allergies text-danger mr-1"></i>Allergies
                                </label>
                                <textarea class="form-control" id="allergies" name="allergies" 
                                          rows="2" placeholder="Drug allergies, food allergies, environmental allergies..."><?php echo htmlspecialchars($todays_note['allergies'] ?? ''); ?></textarea>
                            </div>

                            <!-- Medications -->
                            <div class="form-group">
                                <label for="medications" class="font-weight-bold">
                                    <i class="fas fa-pills text-primary mr-1"></i>Current Medications
                                </label>
                                <textarea class="form-control" id="medications" name="medications" 
                                          rows="2" placeholder="Current medications with doses and frequencies..."><?php echo htmlspecialchars($todays_note['medications'] ?? ''); ?></textarea>
                            </div>

                            <!-- Family History -->
                            <div class="form-group">
                                <label for="family_history" class="font-weight-bold">
                                    <i class="fas fa-users text-info mr-1"></i>Family History (FH)
                                </label>
                                <textarea class="form-control" id="family_history" name="family_history" 
                                          rows="2" placeholder="Family medical history..."><?php echo htmlspecialchars($todays_note['family_history'] ?? ''); ?></textarea>
                            </div>

                            <!-- Social History -->
                            <div class="form-group">
                                <label for="social_history" class="font-weight-bold">
                                    <i class="fas fa-user-friends text-warning mr-1"></i>Social History (SH)
                                </label>
                                <textarea class="form-control" id="social_history" name="social_history" 
                                          rows="2" placeholder="Smoking, alcohol, occupation, living situation..."><?php echo htmlspecialchars($todays_note['social_history'] ?? ''); ?></textarea>
                            </div>

                            <!-- Review of Systems -->
                            <div class="card mb-3">
                                <div class="card-header bg-info py-1 text-white">
                                    <h6 class="card-title mb-0">
                                        <i class="fas fa-search mr-2"></i>Review of Systems (ROS)
                                    </h6>
                                </div>
                                <div class="card-body p-2">
                                    <div class="row">
                                        <?php
                                        $systems = [
                                            'General' => ['Fever', 'Weight Loss', 'Fatigue', 'Night Sweats'],
                                            'HEENT' => ['Headache', 'Vision Changes', 'Hearing Loss', 'Sore Throat'],
                                            'Cardiovascular' => ['Chest Pain', 'Palpitations', 'Edema', 'Shortness of Breath'],
                                            'Respiratory' => ['Cough', 'Wheezing', 'Hemoptysis', 'Chest Tightness'],
                                            'Gastrointestinal' => ['Nausea', 'Vomiting', 'Diarrhea', 'Abdominal Pain', 'Constipation'],
                                            'Genitourinary' => ['Dysuria', 'Frequency', 'Hematuria', 'Discharge'],
                                            'Musculoskeletal' => ['Joint Pain', 'Swelling', 'Limited Motion', 'Weakness'],
                                            'Neurological' => ['Dizziness', 'Seizures', 'Numbness', 'Tingling'],
                                            'Psychiatric' => ['Anxiety', 'Depression', 'Sleep Disturbance', 'Memory Loss'],
                                            'Endocrine' => ['Polyuria', 'Polydipsia', 'Heat/Cold Intolerance'],
                                            'Hematologic' => ['Easy Bruising', 'Bleeding', 'Anemia'],
                                            'Skin' => ['Rash', 'Itching', 'Lesions', 'Ulcers']
                                        ];
                                        
                                        $selected_systems = $todays_note['review_of_systems'] ? json_decode($todays_note['review_of_systems'], true) : [];
                                        ?>
                                        <?php foreach ($systems as $system => $symptoms): ?>
                                            <div class="col-md-4 mb-2">
                                                <label class="font-weight-bold small"><?php echo $system; ?></label>
                                                <?php foreach ($symptoms as $symptom): ?>
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" 
                                                               name="review_of_systems[]" 
                                                               value="<?php echo $system . ': ' . $symptom; ?>"
                                                               <?php echo in_array($system . ': ' . $symptom, $selected_systems) ? 'checked' : ''; ?>>
                                                        <label class="form-check-label small"><?php echo $symptom; ?></label>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Physical Examination -->
                            <div class="card mb-3">
                                <div class="card-header bg-warning py-1">
                                    <h6 class="card-title mb-0">
                                        <i class="fas fa-user-md mr-2"></i>Physical Examination
                                    </h6>
                                </div>
                                <div class="card-body p-2">
                                    <!-- General Appearance -->
                                    <div class="form-group mb-3">
                                        <label for="general_appearance" class="font-weight-bold">General Appearance</label>
                                        <textarea class="form-control" id="general_appearance" name="general_appearance" 
                                                  rows="2" placeholder="Appearance, distress level, nutritional status..."><?php echo htmlspecialchars($todays_note['general_appearance'] ?? ''); ?></textarea>
                                    </div>

                                    <!-- Vital Signs -->
                                    <div class="form-group mb-3">
                                        <label for="vital_signs" class="font-weight-bold">Vital Signs</label>
                                        <textarea class="form-control" id="vital_signs" name="vital_signs" 
                                                  rows="2" placeholder="Temperature, pulse, BP, respiratory rate, SpO2..."><?php echo htmlspecialchars($todays_note['vital_signs'] ?? ''); ?></textarea>
                                    </div>

                                    <!-- System Examinations -->
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="head_neck" class="small">Head & Neck</label>
                                                <textarea class="form-control" id="head_neck" name="head_neck" 
                                                          rows="2" placeholder="HEENT exam findings..."><?php echo htmlspecialchars($todays_note['head_neck'] ?? ''); ?></textarea>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="chest_lungs" class="small">Chest & Lungs</label>
                                                <textarea class="form-control" id="chest_lungs" name="chest_lungs" 
                                                          rows="2" placeholder="Respiratory exam findings..."><?php echo htmlspecialchars($todays_note['chest_lungs'] ?? ''); ?></textarea>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="cardiovascular" class="small">Cardiovascular</label>
                                                <textarea class="form-control" id="cardiovascular" name="cardiovascular" 
                                                          rows="2" placeholder="CVS exam findings..."><?php echo htmlspecialchars($todays_note['cardiovascular'] ?? ''); ?></textarea>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="abdomen" class="small">Abdomen</label>
                                                <textarea class="form-control" id="abdomen" name="abdomen" 
                                                          rows="2" placeholder="Abdominal exam findings..."><?php echo htmlspecialchars($todays_note['abdomen'] ?? ''); ?></textarea>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="neurological" class="small">Neurological</label>
                                                <textarea class="form-control" id="neurological" name="neurological" 
                                                          rows="2" placeholder="Neurological exam findings..."><?php echo htmlspecialchars($todays_note['neurological'] ?? ''); ?></textarea>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="musculoskeletal" class="small">Musculoskeletal</label>
                                                <textarea class="form-control" id="musculoskeletal" name="musculoskeletal" 
                                                          rows="2" placeholder="MSK exam findings..."><?php echo htmlspecialchars($todays_note['musculoskeletal'] ?? ''); ?></textarea>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="skin" class="small">Skin</label>
                                                <textarea class="form-control" id="skin" name="skin" 
                                                          rows="2" placeholder="Skin exam findings..."><?php echo htmlspecialchars($todays_note['skin'] ?? ''); ?></textarea>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="other_exam" class="small">Other Examinations</label>
                                                <textarea class="form-control" id="other_exam" name="other_exam" 
                                                          rows="2" placeholder="Other relevant exam findings..."><?php echo htmlspecialchars($todays_note['other_exam'] ?? ''); ?></textarea>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Assessment & Diagnosis -->
                            <div class="card mb-3">
                                <div class="card-header bg-danger py-1 text-white">
                                    <h6 class="card-title mb-0">
                                        <i class="fas fa-diagnoses mr-2"></i>Assessment & Diagnosis
                                    </h6>
                                </div>
                                <div class="card-body p-2">
                                    <!-- Primary Diagnosis -->
                                    <div class="form-group">
                                        <label for="primary_diagnosis" class="font-weight-bold">Primary Diagnosis</label>
                                        <textarea class="form-control" id="primary_diagnosis" name="primary_diagnosis" 
                                                  rows="2" placeholder="Main diagnosis..."><?php echo htmlspecialchars($todays_note['primary_diagnosis'] ?? ''); ?></textarea>
                                    </div>

                                    <!-- Secondary Diagnosis -->
                                    <div class="form-group">
                                        <label for="secondary_diagnosis" class="font-weight-bold">Secondary Diagnosis</label>
                                        <textarea class="form-control" id="secondary_diagnosis" name="secondary_diagnosis" 
                                                  rows="2" placeholder="Additional diagnoses..."><?php echo htmlspecialchars($todays_note['secondary_diagnosis'] ?? ''); ?></textarea>
                                    </div>

                                    <!-- Differential Diagnosis -->
                                    <div class="form-group">
                                        <label for="differential_diagnosis" class="font-weight-bold">Differential Diagnosis</label>
                                        <textarea class="form-control" id="differential_diagnosis" name="differential_diagnosis" 
                                                  rows="2" placeholder="Rule out..."><?php echo htmlspecialchars($todays_note['differential_diagnosis'] ?? ''); ?></textarea>
                                    </div>

                                    <!-- ICD Codes -->
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="icd10_codes" class="font-weight-bold">ICD-10 Codes</label>
                                                <div class="input-group">
                                                    <input type="text" class="form-control" id="icd10_codes" name="icd10_codes" 
                                                           value="<?php echo htmlspecialchars($todays_note['icd10_codes'] ?? ''); ?>"
                                                           placeholder="e.g., J18.9, I10, E11.9">
                                                    <div class="input-group-append">
                                                        <button type="button" class="btn btn-outline-secondary" onclick="searchICD10()">
                                                            <i class="fas fa-search"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="icd11_codes" class="font-weight-bold">ICD-11 Codes</label>
                                                <div class="input-group">
                                                    <input type="text" class="form-control" id="icd11_codes" name="icd11_codes" 
                                                           value="<?php echo htmlspecialchars($todays_note['icd11_codes'] ?? ''); ?>"
                                                           placeholder="e.g., CA23.Z, 8A43.0Z">
                                                    <div class="input-group-append">
                                                        <button type="button" class="btn btn-outline-primary" onclick="searchICD11()">
                                                            <i class="fas fa-search"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Plan -->
                            <div class="card mb-3">
                                <div class="card-header bg-primary py-1 text-white">
                                    <h6 class="card-title mb-0">
                                        <i class="fas fa-tasks mr-2"></i>Plan
                                    </h6>
                                </div>
                                <div class="card-body p-2">
                                    <!-- Investigations -->
                                    <div class="form-group">
                                        <label for="investigations" class="font-weight-bold">Investigations</label>
                                        <textarea class="form-control" id="investigations" name="investigations" 
                                                  rows="2" placeholder="Lab tests, imaging, procedures needed..."><?php echo htmlspecialchars($todays_note['investigations'] ?? ''); ?></textarea>
                                    </div>

                                    <!-- Medication Plan -->
                                    <div class="form-group">
                                        <label for="medication_plan" class="font-weight-bold">Medication Plan</label>
                                        <textarea class="form-control" id="medication_plan" name="medication_plan" 
                                                  rows="2" placeholder="Prescriptions, treatments..."><?php echo htmlspecialchars($todays_note['medication_plan'] ?? ''); ?></textarea>
                                    </div>

                                    <!-- Procedures -->
                                    <div class="form-group">
                                        <label for="procedures" class="font-weight-bold">Procedures</label>
                                        <textarea class="form-control" id="procedures" name="procedures" 
                                                  rows="2" placeholder="Procedures, surgeries planned..."><?php echo htmlspecialchars($todays_note['procedures'] ?? ''); ?></textarea>
                                    </div>

                                    <!-- Follow-up -->
                                    <div class="form-group">
                                        <label for="follow_up" class="font-weight-bold">Follow-up</label>
                                        <textarea class="form-control" id="follow_up" name="follow_up" 
                                                  rows="2" placeholder="Follow-up appointments, monitoring..."><?php echo htmlspecialchars($todays_note['follow_up'] ?? ''); ?></textarea>
                                    </div>

                                    <!-- Patient Education -->
                                    <div class="form-group">
                                        <label for="patient_education" class="font-weight-bold">Patient Education</label>
                                        <textarea class="form-control" id="patient_education" name="patient_education" 
                                                  rows="2" placeholder="Education provided..."><?php echo htmlspecialchars($todays_note['patient_education'] ?? ''); ?></textarea>
                                    </div>

                                    <!-- Referrals -->
                                    <div class="form-group">
                                        <label for="referrals" class="font-weight-bold">Referrals</label>
                                        <textarea class="form-control" id="referrals" name="referrals" 
                                                  rows="2" placeholder="Specialist referrals..."><?php echo htmlspecialchars($todays_note['referrals'] ?? ''); ?></textarea>
                                    </div>
                                </div>
                            </div>

                            <!-- Clinical Notes -->
                            <div class="form-group">
                                <label for="clinical_notes" class="font-weight-bold">
                                    <i class="fas fa-sticky-note mr-1"></i>Clinical Notes
                                </label>
                                <textarea class="form-control" id="clinical_notes" name="clinical_notes" 
                                          rows="3" placeholder="Additional clinical notes, reasoning, prognosis..."><?php echo htmlspecialchars($todays_note['clinical_notes'] ?? ''); ?></textarea>
                            </div>

                            <!-- Action Buttons -->
                            <div class="form-group mb-0">
                                <div class="btn-group btn-block" role="group">
                                    <button type="submit" name="save_consultation" class="btn btn-warning btn-lg flex-fill">
                                        <i class="fas fa-save mr-2"></i>Save as Draft
                                    </button>
                                    <button type="submit" name="save_consultation" value="1" 
                                            class="btn btn-success btn-lg flex-fill" onclick="document.getElementById('finalize_consultation').value = '1';">
                                        <i class="fas fa-lock mr-2"></i>Save & Complete
                                    </button>
                                </div>
                                <input type="hidden" name="finalize" id="finalize_consultation" value="0">
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Right Column - History & Tools -->
            <div class="col-md-4">
                <!-- Consultation History -->
                <div class="card mb-4">
                    <div class="card-header bg-info py-2 text-white">
                        <h4 class="card-title mb-0">
                            <i class="fas fa-history mr-2"></i>Consultation History
                            <span class="badge badge-light float-right"><?php echo count($doctor_notes); ?> consultations</span>
                        </h4>
                    </div>
                    <div class="card-body p-0">
                        <?php if (!empty($doctor_notes)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Date</th>
                                            <th>Complaint</th>
                                            <th>Status</th>
                                            <th class="text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        foreach ($doctor_notes as $note): 
                                            $note_date = new DateTime($note['consultation_date']);
                                            $is_today = ($note['consultation_date'] == $today);
                                            $row_class = $is_today ? 'table-info' : '';
                                        ?>
                                            <tr class="<?php echo $row_class; ?>">
                                                <td>
                                                    <div><?php echo $note_date->format('M j, Y'); ?></div>
                                                    <small class="text-muted">
                                                        <?php echo date('H:i', strtotime($note['consultation_time'])); ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <div class="font-weight-bold small">
                                                        <?php echo htmlspecialchars(substr($note['chief_complaint'] ?? 'No complaint', 0, 30)); ?>
                                                        <?php if(strlen($note['chief_complaint'] ?? '') > 30): ?>...<?php endif; ?>
                                                    </div>
                                                    <?php if($note['primary_diagnosis']): ?>
                                                        <small class="text-muted">
                                                            <?php echo htmlspecialchars(substr($note['primary_diagnosis'], 0, 20)); ?>
                                                            <?php if(strlen($note['primary_diagnosis']) > 20): ?>...<?php endif; ?>
                                                        </small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php echo getConsultationStatusBadge($note['consultation_status']); ?>
                                                </td>
                                                <td class="text-center">
                                                    <button type="button" class="btn btn-sm btn-info" 
                                                            onclick="viewConsultationDetails(<?php echo htmlspecialchars(json_encode($note)); ?>)"
                                                            title="View Details">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <?php if ($note['consultation_status'] == 'draft'): ?>
                                                        <button type="button" class="btn btn-sm btn-warning" 
                                                                onclick="editConsultation(<?php echo htmlspecialchars(json_encode($note)); ?>)"
                                                                title="Edit Consultation">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <form method="POST" style="display: inline;">
                                                            <input type="hidden" name="consultation_id" value="<?php echo $note['consultation_id']; ?>">
                                                            <button type="submit" name="delete_consultation" class="btn btn-sm btn-danger" 
                                                                    title="Delete Consultation" onclick="return confirm('Delete this consultation?')">
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
                                <i class="fas fa-stethoscope fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">No Consultations</h5>
                                <p class="text-muted">No doctor consultations have been recorded for this visit yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="card mb-4">
                    <div class="card-header bg-warning py-2">
                        <h6 class="card-title mb-0">
                            <i class="fas fa-bolt mr-2"></i>Quick Actions
                        </h6>
                    </div>
                    <div class="card-body p-2">
                        <div class="btn-group-vertical btn-block" role="group">
                            <a href="/clinic/doctor/doctor_orders.php?visit_id=<?php echo $visit_id; ?>" 
                               class="btn btn-outline-primary text-left mb-2">
                                <i class="fas fa-prescription mr-2"></i>Create Orders
                                <?php if($pending_bills['count'] > 0): ?>
                                    <span class="badge badge-warning float-right"><?php echo $pending_bills['count']; ?></span>
                                <?php endif; ?>
                            </a>
                            
                            <a href="/clinic/doctor/prescription.php?visit_id=<?php echo $visit_id; ?>" 
                               class="btn btn-outline-info text-left mb-2">
                                <i class="fas fa-prescription mr-2"></i>Prescribe
                            </a>
                                 <a href="/clinic/doctor/lab_orders.php?visit_id=<?php echo $visit_id; ?>" 
                               class="btn btn-outline-primary text-left mb-2">
                                <i class="fas fa-prescription mr-2"></i>Order Lab
                                <?php if($pending_labs['count'] > 0): ?>
                                    <span class="badge badge-warning float-right"><?php echo $pending_labs['count']; ?></span>
                                <?php endif; ?>
                            </a>
                                 <a href="/clinic/doctor/imaging_orders.php?visit_id=<?php echo $visit_id; ?>" 
                               class="btn btn-outline-primary text-left mb-2">
                                <i class="fas fa-prescription mr-2"></i>Order Image
                                <?php if($pending_imaging['count'] > 0): ?>
                                    <span class="badge badge-warning float-right"><?php echo $pending_imaging['count']; ?></span>
                                <?php endif; ?>
                            </a>
                       
                            
                            <?php if ($visit_type == 'OPD'): ?>
                                <a href="/opd/discharge.php?visit_id=<?php echo $visit_id; ?>" 
                                   class="btn btn-outline-success text-left mb-2">
                                    <i class="fas fa-sign-out-alt mr-2"></i>Discharge Patient
                                </a>
                            <?php elseif ($visit_type == 'EMERGENCY'): ?>
                                <a href="/emergency/discharge.php?visit_id=<?php echo $visit_id; ?>" 
                                   class="btn btn-outline-danger text-left mb-2">
                                    <i class="fas fa-ambulance mr-2"></i>Emergency Discharge
                                </a>
                            <?php endif; ?>
                            
                            <a href="/certificates/medical_certificate.php?patient_id=<?php echo $patient_info['patient_id']; ?>&visit_id=<?php echo $visit_id; ?>" 
                               class="btn btn-outline-secondary text-left">
                                <i class="fas fa-file-medical mr-2"></i>Medical Certificate
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Clinical Calculators -->
                <div class="card mb-4">
                    <div class="card-header bg-secondary py-2 text-white">
                        <h6 class="card-title mb-0">
                            <i class="fas fa-calculator mr-2"></i>Clinical Calculators
                        </h6>
                    </div>
                    <div class="card-body p-2">
                        <div class="row">
                            <div class="col-md-6">
                                <button type="button" class="btn btn-sm btn-outline-primary btn-block mb-2" onclick="calculateBMI()">
                                    <i class="fas fa-weight mr-1"></i>BMI
                                </button>
                            </div>
                            <div class="col-md-6">
                                <button type="button" class="btn btn-sm btn-outline-info btn-block mb-2" onclick="calculateGFR()">
                                    <i class="fas fa-kidneys mr-1"></i>GFR
                                </button>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <button type="button" class="btn btn-sm btn-outline-success btn-block mb-2" onclick="calculateMAP()">
                                    <i class="fas fa-heartbeat mr-1"></i>MAP
                                </button>
                            </div>
                            <div class="col-md-6">
                                <button type="button" class="btn btn-sm btn-outline-warning btn-block mb-2" onclick="calculateCHADS2()">
                                    <i class="fas fa-brain mr-1"></i>CHADS2
                                </button>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-12">
                                <button type="button" class="btn btn-sm btn-outline-danger btn-block" onclick="calculateSOFA()">
                                    <i class="fas fa-procedures mr-1"></i>SOFA Score
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ICD Quick Reference -->
                <div class="card">
                    <div class="card-header bg-dark py-2 text-white">
                        <h6 class="card-title mb-0">
                            <i class="fas fa-code mr-2"></i>Common ICD-11 Codes
                        </h6>
                    </div>
                    <div class="card-body p-2">
                        <small>
                            <strong>Hypertension:</strong> BA00<br>
                            <strong>Diabetes Type 2:</strong> 5A11<br>
                            <strong>Pneumonia:</strong> CA40<br>
                            <strong>UTI:</strong> GC00<br>
                            <strong>COPD:</strong> CA22<br>
                            <strong>Asthma:</strong> CA23<br>
                            <strong>Back Pain:</strong> MG30<br>
                            <strong>Headache:</strong> 8A80<br>
                            <strong>Abdominal Pain:</strong> MD90<br>
                            <strong>Chest Pain:</strong> MC81<br>
                            <button type="button" class="btn btn-sm btn-outline-primary btn-block mt-2" onclick="searchICD11()">
                                <i class="fas fa-search mr-1"></i>Search ICD-11 Codes
                            </button>
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ICD-11 Search Modal -->
<div class="modal fade" id="icd11SearchModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Search ICD-11 Codes</h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <div class="input-group">
                        <input type="text" class="form-control" id="icd11SearchTerm" placeholder="Search by code or description...">
                        <div class="input-group-append">
                            <button class="btn btn-primary" type="button" onclick="performICD11Search()">
                                <i class="fas fa-search"></i> Search
                            </button>
                        </div>
                    </div>
                </div>
                <div id="icd11SearchResults" class="mt-3">
                    <!-- Results will be displayed here -->
                </div>
                <div class="text-center mt-3" id="icd11Loading" style="display: none;">
                    <i class="fas fa-spinner fa-spin fa-2x"></i>
                    <p>Searching...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Consultation Details Modal -->
<div class="modal fade" id="consultationDetailsModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-xl" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Consultation Details</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body" id="consultationDetailsContent">
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

    // Auto-expand textareas based on content
    $('textarea').on('input', function() {
        this.style.height = 'auto';
        this.style.height = (this.scrollHeight) + 'px';
    }).trigger('input');

    // Form validation
    $('#consultationForm').validate({
        rules: {
            chief_complaint: {
                required: true
            }
        },
        messages: {
            chief_complaint: "Please enter chief complaint"
        }
    });

    // Auto-fill current date and time
    $('#consultation_date').val('<?php echo date('Y-m-d'); ?>');
    $('#consultation_time').val('<?php echo date('H:i'); ?>');
});

function searchICD11() {
    $('#icd11SearchModal').modal('show');
    // Clear previous results
    $('#icd11SearchResults').html('');
    // Focus on search input
    setTimeout(function() {
        $('#icd11SearchTerm').focus();
    }, 500);
}

function performICD11Search() {
    const searchTerm = $('#icd11SearchTerm').val().trim();
    
    if (searchTerm.length < 2) {
        $('#icd11SearchResults').html('<div class="alert alert-warning">Please enter at least 2 characters to search</div>');
        return;
    }
    
    $('#icd11Loading').show();
    $('#icd11SearchResults').html('');
    
    $.ajax({
        url: '/ajax/search_icd11.php',
        method: 'GET',
        data: { q: searchTerm },
        dataType: 'json',
        success: function(response) {
            $('#icd11Loading').hide();
            
            if (response.error) {
                $('#icd11SearchResults').html('<div class="alert alert-danger">' + response.error + '</div>');
                return;
            }
            
            if (response.length === 0) {
                $('#icd11SearchResults').html('<div class="alert alert-info">No ICD-11 codes found for "' + searchTerm + '"</div>');
                return;
            }
            
            let html = '<div class="table-responsive"><table class="table table-sm table-hover"><thead><tr><th>Code</th><th>Title</th><th>Action</th></tr></thead><tbody>';
            
            response.forEach(function(code) {
                html += '<tr>';
                html += '<td><strong>' + code.icd_code + '</strong></td>';
                html += '<td>' + code.title + '</td>';
                html += '<td><button type="button" class="btn btn-sm btn-primary" onclick="selectICD11Code(\'' + code.icd_code + '\', \'' + code.title.replace(/'/g, "\\'") + '\')">Select</button></td>';
                html += '</tr>';
            });
            
            html += '</tbody></table></div>';
            $('#icd11SearchResults').html(html);
        },
        error: function() {
            $('#icd11Loading').hide();
            $('#icd11SearchResults').html('<div class="alert alert-danger">Error searching ICD-11 codes. Please try again.</div>');
        }
    });
}

function selectICD11Code(code, title) {
    // Get current ICD-11 codes
    let currentCodes = $('#icd11_codes').val().trim();
    let codesArray = [];
    
    if (currentCodes) {
        // Split by comma and clean up
        codesArray = currentCodes.split(',').map(c => c.trim()).filter(c => c);
    }
    
    // Check if code already exists
    const codeExists = codesArray.some(c => c.startsWith(code));
    
    if (!codeExists) {
        // Add new code
        const newEntry = code + ' - ' + title;
        if (codesArray.length > 0) {
            codesArray.push(newEntry);
            $('#icd11_codes').val(codesArray.join(', '));
        } else {
            $('#icd11_codes').val(newEntry);
        }
        
        // Also add to primary diagnosis if empty
        if (!$('#primary_diagnosis').val().trim()) {
            $('#primary_diagnosis').val(title);
        }
        
        showToast('ICD-11 code ' + code + ' added', 'success');
    } else {
        showToast('ICD-11 code ' + code + ' already exists', 'info');
    }
    
    $('#icd11SearchModal').modal('hide');
}

function searchICD10() {
    // For now, use a simple prompt
    const code = prompt('Enter ICD-10 code (e.g., J18.9, I10):');
    if (code) {
        // Get current ICD-10 codes
        let currentCodes = $('#icd10_codes').val().trim();
        let codesArray = [];
        
        if (currentCodes) {
            // Split by comma and clean up
            codesArray = currentCodes.split(',').map(c => c.trim()).filter(c => c);
        }
        
        // Check if code already exists
        if (!codesArray.includes(code)) {
            codesArray.push(code);
            $('#icd10_codes').val(codesArray.join(', '));
            showToast('ICD-10 code ' + code + ' added', 'success');
        } else {
            showToast('ICD-10 code ' + code + ' already exists', 'info');
        }
    }
}

function viewConsultationDetails(consultation) {
    const modalContent = document.getElementById('consultationDetailsContent');
    const consultationDate = new Date(consultation.consultation_date);
    const consultationTime = consultation.consultation_time ? consultation.consultation_time.substring(0, 5) : '--';
    
    // Parse review of systems
    let reviewSystems = '';
    if (consultation.review_of_systems) {
        const systems = JSON.parse(consultation.review_of_systems);
        reviewSystems = systems.join(', ');
    }
    
    let html = `
        <div class="card">
            <div class="card-header bg-light py-2">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0">Doctor Consultation</h6>
                        <small class="text-muted">
                            ${consultationDate.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })} 
                            at ${consultationTime}
                        </small>
                    </div>
                    <div>
                        ${getConsultationStatusBadge(consultation.consultation_status)}
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <div class="col-md-12">
                        <table class="table table-sm table-borderless">
                            <tr>
                                <th width="20%">Chief Complaint:</th>
                                <td><strong>${consultation.chief_complaint || 'Not specified'}</strong></td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <!-- History -->
                ${consultation.history_present_illness ? `
                <div class="mb-3">
                    <h6 class="text-primary"><i class="fas fa-history mr-2"></i>History of Present Illness</h6>
                    <div class="p-2 bg-light rounded">${consultation.history_present_illness.replace(/\n/g, '<br>')}</div>
                </div>` : ''}
                
                ${consultation.past_medical_history ? `
                <div class="mb-3">
                    <h6 class="text-info"><i class="fas fa-medkit mr-2"></i>Past Medical History</h6>
                    <div class="p-2 bg-light rounded">${consultation.past_medical_history.replace(/\n/g, '<br>')}</div>
                </div>` : ''}
                
                ${consultation.allergies ? `
                <div class="mb-3">
                    <h6 class="text-danger"><i class="fas fa-allergies mr-2"></i>Allergies</h6>
                    <div class="p-2 bg-light rounded">${consultation.allergies.replace(/\n/g, '<br>')}</div>
                </div>` : ''}
                
                ${reviewSystems ? `
                <div class="mb-3">
                    <h6 class="text-warning"><i class="fas fa-search mr-2"></i>Review of Systems</h6>
                    <div class="p-2 bg-light rounded">${reviewSystems}</div>
                </div>` : ''}
                
                <!-- Physical Exam -->
                <div class="mb-3">
                    <h6 class="text-success"><i class="fas fa-user-md mr-2"></i>Physical Examination</h6>
                    <div class="p-2 bg-light rounded">
                        ${consultation.general_appearance ? `<strong>General:</strong> ${consultation.general_appearance.replace(/\n/g, '<br>')}<br><br>` : ''}
                        ${consultation.vital_signs ? `<strong>Vital Signs:</strong> ${consultation.vital_signs.replace(/\n/g, '<br>')}<br><br>` : ''}
                        ${consultation.head_neck ? `<strong>Head & Neck:</strong> ${consultation.head_neck.replace(/\n/g, '<br>')}<br><br>` : ''}
                        ${consultation.chest_lungs ? `<strong>Chest & Lungs:</strong> ${consultation.chest_lungs.replace(/\n/g, '<br>')}<br><br>` : ''}
                        ${consultation.cardiovascular ? `<strong>Cardiovascular:</strong> ${consultation.cardiovascular.replace(/\n/g, '<br>')}<br><br>` : ''}
                        ${consultation.abdomen ? `<strong>Abdomen:</strong> ${consultation.abdomen.replace(/\n/g, '<br>')}<br><br>` : ''}
                        ${consultation.neurological ? `<strong>Neurological:</strong> ${consultation.neurological.replace(/\n/g, '<br>')}<br><br>` : ''}
                        ${consultation.musculoskeletal ? `<strong>Musculoskeletal:</strong> ${consultation.musculoskeletal.replace(/\n/g, '<br>')}<br><br>` : ''}
                        ${consultation.skin ? `<strong>Skin:</strong> ${consultation.skin.replace(/\n/g, '<br>')}` : ''}
                    </div>
                </div>
                
                <!-- Assessment -->
                <div class="mb-3">
                    <h6 class="text-danger"><i class="fas fa-diagnoses mr-2"></i>Assessment & Diagnosis</h6>
                    <div class="p-2 bg-light rounded">
                        ${consultation.primary_diagnosis ? `<strong>Primary Diagnosis:</strong> ${consultation.primary_diagnosis.replace(/\n/g, '<br>')}<br><br>` : ''}
                        ${consultation.secondary_diagnosis ? `<strong>Secondary Diagnosis:</strong> ${consultation.secondary_diagnosis.replace(/\n/g, '<br>')}<br><br>` : ''}
                        ${consultation.differential_diagnosis ? `<strong>Differential Diagnosis:</strong> ${consultation.differential_diagnosis.replace(/\n/g, '<br>')}<br><br>` : ''}
                        ${consultation.icd10_codes ? `<strong>ICD-10 Codes:</strong> ${consultation.icd10_codes}<br><br>` : ''}
                        ${consultation.icd11_codes ? `<strong>ICD-11 Codes:</strong> ${consultation.icd11_codes}` : ''}
                    </div>
                </div>
                
                <!-- Plan -->
                <div class="mb-3">
                    <h6 class="text-primary"><i class="fas fa-tasks mr-2"></i>Plan</h6>
                    <div class="p-2 bg-light rounded">
                        ${consultation.investigations ? `<strong>Investigations:</strong> ${consultation.investigations.replace(/\n/g, '<br>')}<br><br>` : ''}
                        ${consultation.medication_plan ? `<strong>Medication Plan:</strong> ${consultation.medication_plan.replace(/\n/g, '<br>')}<br><br>` : ''}
                        ${consultation.procedures ? `<strong>Procedures:</strong> ${consultation.procedures.replace(/\n/g, '<br>')}<br><br>` : ''}
                        ${consultation.follow_up ? `<strong>Follow-up:</strong> ${consultation.follow_up.replace(/\n/g, '<br>')}<br><br>` : ''}
                        ${consultation.patient_education ? `<strong>Patient Education:</strong> ${consultation.patient_education.replace(/\n/g, '<br>')}<br><br>` : ''}
                        ${consultation.referrals ? `<strong>Referrals:</strong> ${consultation.referrals.replace(/\n/g, '<br>')}` : ''}
                    </div>
                </div>
                
                <!-- Clinical Notes -->
                ${consultation.clinical_notes ? `
                <div class="mb-3">
                    <h6 class="text-secondary"><i class="fas fa-sticky-note mr-2"></i>Clinical Notes</h6>
                    <div class="p-2 bg-light rounded">${consultation.clinical_notes.replace(/\n/g, '<br>')}</div>
                </div>` : ''}
                
                <!-- Recorded By -->
                <div class="text-center mt-4">
                    <p class="text-muted">
                        <i class="fas fa-user-md"></i> Recorded by: ${consultation.recorded_by_name}
                    </p>
                </div>
            </div>
        </div>
    `;
    
    modalContent.innerHTML = html;
    $('#consultationDetailsModal').modal('show');
}

function editConsultation(consultation) {
    // Populate form with consultation data
    $('#chief_complaint').val(consultation.chief_complaint);
    $('#history_present_illness').val(consultation.history_present_illness);
    $('#past_medical_history').val(consultation.past_medical_history);
    $('#allergies').val(consultation.allergies);
    $('#medications').val(consultation.medications);
    $('#family_history').val(consultation.family_history);
    $('#social_history').val(consultation.social_history);
    $('#general_appearance').val(consultation.general_appearance);
    $('#vital_signs').val(consultation.vital_signs);
    $('#head_neck').val(consultation.head_neck);
    $('#chest_lungs').val(consultation.chest_lungs);
    $('#cardiovascular').val(consultation.cardiovascular);
    $('#abdomen').val(consultation.abdomen);
    $('#neurological').val(consultation.neurological);
    $('#musculoskeletal').val(consultation.musculoskeletal);
    $('#skin').val(consultation.skin);
    $('#other_exam').val(consultation.other_exam);
    $('#primary_diagnosis').val(consultation.primary_diagnosis);
    $('#secondary_diagnosis').val(consultation.secondary_diagnosis);
    $('#differential_diagnosis').val(consultation.differential_diagnosis);
    $('#icd10_codes').val(consultation.icd10_codes);
    $('#icd11_codes').val(consultation.icd11_codes);
    $('#investigations').val(consultation.investigations);
    $('#medication_plan').val(consultation.medication_plan);
    $('#procedures').val(consultation.procedures);
    $('#follow_up').val(consultation.follow_up);
    $('#patient_education').val(consultation.patient_education);
    $('#referrals').val(consultation.referrals);
    $('#clinical_notes').val(consultation.clinical_notes);
    
    // Parse review of systems
    if (consultation.review_of_systems) {
        const systems = JSON.parse(consultation.review_of_systems);
        systems.forEach(system => {
            $(`input[name="review_of_systems[]"][value="${system}"]`).prop('checked', true);
        });
    }
    
    // Trigger auto-expand
    $('textarea').trigger('input');
    
    // Scroll to form
    $('html, body').animate({
        scrollTop: $('#consultationForm').offset().top - 20
    }, 500);
    
    showToast('Consultation loaded for editing', 'info');
}

function calculateBMI() {
    const weight = prompt('Enter weight (kg):');
    const height = prompt('Enter height (cm):');
    
    if (weight && height && height > 0) {
        const heightM = height / 100;
        const bmi = (weight / (heightM * heightM)).toFixed(1);
        
        let category = '';
        if (bmi < 18.5) category = 'Underweight';
        else if (bmi < 25) category = 'Normal';
        else if (bmi < 30) category = 'Overweight';
        else category = 'Obese';
        
        const result = `BMI: ${bmi} (${category})`;
        addToClinicalNotes(result);
        showToast(result, 'info');
    }
}

function calculateGFR() {
    const age = prompt('Enter age:');
    const gender = prompt('Enter gender (M/F):');
    const creatinine = prompt('Enter serum creatinine (mg/dL):');
    const isBlack = confirm('Is patient African American?');
    
    if (age && gender && creatinine) {
        let gfr = (140 - age) * (gender.toUpperCase() === 'M' ? 1 : 0.85) * (1 / creatinine);
        if (isBlack) gfr *= 1.212;
        
        const result = `Estimated GFR: ${gfr.toFixed(1)} mL/min/1.73m²`;
        addToClinicalNotes(result);
        showToast(result, 'info');
    }
}

function calculateMAP() {
    const systolic = prompt('Enter systolic BP:');
    const diastolic = prompt('Enter diastolic BP:');
    
    if (systolic && diastolic) {
        const map = (parseInt(systolic) + 2 * parseInt(diastolic)) / 3;
        const result = `Mean Arterial Pressure: ${map.toFixed(1)} mmHg`;
        addToClinicalNotes(result);
        showToast(result, 'info');
    }
}

function addToClinicalNotes(text) {
    const currentNotes = $('#clinical_notes').val();
    const timestamp = new Date().toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
    const newNote = `[${timestamp}] ${text}`;
    
    $('#clinical_notes').val(currentNotes ? currentNotes + '\n' + newNote : newNote);
    $('textarea').trigger('input');
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

// Keyboard shortcuts for ICD search
$(document).keydown(function(e) {
    // Ctrl + I for ICD-11 search
    if (e.ctrlKey && e.keyCode === 73) {
        e.preventDefault();
        searchICD11();
    }
    // Ctrl + Shift + I for ICD-10
    if (e.ctrlKey && e.shiftKey && e.keyCode === 73) {
        e.preventDefault();
        searchICD10();
    }
    // Ctrl + S for save draft
    if (e.ctrlKey && e.keyCode === 83 && !e.shiftKey) {
        e.preventDefault();
        $('#finalize_consultation').val('0');
        $('#consultationForm').submit();
    }
    // Ctrl + Shift + S for save and complete
    if (e.ctrlKey && e.shiftKey && e.keyCode === 83) {
        e.preventDefault();
        $('#finalize_consultation').val('1');
        $('#consultationForm').submit();
    }
    // Ctrl + O for doctor orders
    if (e.ctrlKey && e.keyCode === 79) {
        e.preventDefault();
        window.location.href = '/clinic/doctor/doctor_orders.php?visit_id=<?php echo $visit_id; ?>';
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

// Auto-save draft
let autoSaveTimer;
function startAutoSave() {
    autoSaveTimer = setInterval(function() {
        if ($('#consultationForm').valid()) {
            const hasContent = $('#chief_complaint').val() || $('#history_present_illness').val();
            if (hasContent) {
                console.log('Auto-saving consultation draft...');
                // AJAX auto-save could be implemented here
            }
        }
    }, 300000); // 5 minutes
}

$(document).ready(function() {
    startAutoSave();
    
    // Enable enter key in ICD-11 search
    $('#icd11SearchTerm').keypress(function(e) {
        if (e.which === 13) {
            performICD11Search();
            return false;
        }
    });
});
</script>

<style>
/* Custom styles for doctor consultation */
.table-info {
    background-color: #d1ecf1 !important;
}

.icd-code-item {
    cursor: pointer;
    transition: background-color 0.2s;
}

.icd-code-item:hover {
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
    .table {
        font-size: 11px !important;
    }
}
</style>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
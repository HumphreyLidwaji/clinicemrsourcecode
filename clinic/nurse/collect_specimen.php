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
        'module'      => 'Specimen Collection',
        'table_name'  => 'visits',
        'entity_type' => 'visit',
        'record_id'   => null,
        'patient_id'  => null,
        'visit_id'    => null,
        'description' => "Attempted to access collect_specimen.php with invalid visit ID: " . $visit_id,
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
        'action'      => 'ACCESS',
        'module'      => 'Specimen Collection',
        'table_name'  => 'visits',
        'entity_type' => 'visit',
        'record_id'   => $visit_id,
        'patient_id'  => null,
        'visit_id'    => $visit_id,
        'description' => "Attempted to access specimen collection for visit ID " . $visit_id . " but visit not found",
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

// Get lab orders for this visit
$lab_orders_sql = "SELECT lo.*, 
                  lot.lab_order_test_id, lot.test_id, lot.status as test_status,
                  lot.required_volume, lot.result_value, lot.result_unit,
                  t.test_name, t.test_code,
                  doc.user_name as doctor_name,
                  u.user_name as created_by_name
                  FROM lab_orders lo
                  JOIN lab_order_tests lot ON lo.lab_order_id = lot.lab_order_id
                  JOIN lab_tests t ON lot.test_id = t.test_id
                  LEFT JOIN users doc ON lo.ordering_doctor_id = doc.user_id
                  LEFT JOIN users u ON lo.created_by = u.user_id
                  WHERE lo.visit_id = ? 
                  AND lo.lab_order_patient_id = ?
                  AND lo.lab_order_status IN ('Pending', 'Partially Collected', 'Collected')
                  AND lot.status IN ('pending', 'specimen_required')
                  AND lot.is_active = 1
                  ORDER BY lo.order_priority DESC, lo.order_date ASC";
$lab_orders_stmt = $mysqli->prepare($lab_orders_sql);
$lab_orders_stmt->bind_param("ii", $visit_id, $patient_info['patient_id']);
$lab_orders_stmt->execute();
$lab_orders_result = $lab_orders_stmt->get_result();
$lab_orders = $lab_orders_result->fetch_all(MYSQLI_ASSOC);

// Get already collected samples
$samples_sql = "SELECT ls.*, 
                t.test_name, t.test_code,
                u.user_name as collected_by_name,
                ru.user_name as received_by_name,
                lo.order_number,
                lot.required_volume
                FROM lab_samples ls
                JOIN lab_orders lo ON ls.lab_order_id = lo.lab_order_id
                JOIN lab_order_tests lot ON ls.lab_order_id = lot.lab_order_id AND ls.test_id = lot.test_id
                JOIN lab_tests t ON ls.test_id = t.test_id
                LEFT JOIN users u ON ls.collected_by = u.user_id
                LEFT JOIN users ru ON ls.received_by = ru.user_id
                WHERE lo.visit_id = ? 
                AND lo.lab_order_patient_id = ?
                ORDER BY ls.collection_date DESC";
$samples_stmt = $mysqli->prepare($samples_sql);
$samples_stmt->bind_param("ii", $visit_id, $patient_info['patient_id']);
$samples_stmt->execute();
$samples_result = $samples_stmt->get_result();
$collected_samples = $samples_result->fetch_all(MYSQLI_ASSOC);

// Get specimen types for dropdown
$specimen_types_sql = "SELECT DISTINCT specimen_type 
                      FROM lab_tests 
                      WHERE specimen_type IS NOT NULL 
                      AND specimen_type != '' 
                      ORDER BY specimen_type";
$specimen_types_result = $mysqli->query($specimen_types_sql);
$specimen_types = $specimen_types_result->fetch_all(MYSQLI_ASSOC);

// AUDIT LOG: Successful access to specimen collection page
audit_log($mysqli, [
    'user_id'     => $_SESSION['user_id'] ?? null,
    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
    'action'      => 'VIEW',
    'module'      => 'Specimen Collection',
    'table_name'  => 'visits',
    'entity_type' => 'visit',
    'record_id'   => $visit_id,
    'patient_id'  => $patient_info['patient_id'],
    'visit_id'    => $visit_id,
    'description' => "Accessed specimen collection page for visit ID " . $visit_id . " (Patient: " . $patient_info['first_name'] . " " . $patient_info['last_name'] . ")",
    'status'      => 'SUCCESS',
    'old_values'  => null,
    'new_values'  => null
]);

// Handle form submission for sample collection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['collect_sample'])) {
        $lab_order_id = intval($_POST['lab_order_id']);
        $lab_order_test_id = intval($_POST['lab_order_test_id']);
        $test_id = intval($_POST['test_id']);
        $specimen_type = !empty($_POST['specimen_type']) ? trim($_POST['specimen_type']) : null;
        $collection_date = !empty($_POST['collection_date']) ? $_POST['collection_date'] : date('Y-m-d');
        $collection_time = !empty($_POST['collection_time']) ? $_POST['collection_time'] : date('H:i');
        $collection_site = !empty($_POST['collection_site']) ? trim($_POST['collection_site']) : null;
        $collected_volume = !empty($_POST['collected_volume']) ? trim($_POST['collected_volume']) : null;
        $volume_unit = !empty($_POST['volume_unit']) ? trim($_POST['volume_unit']) : 'mL';
        $collection_tube = !empty($_POST['collection_tube']) ? trim($_POST['collection_tube']) : null;
        $storage_temperature = !empty($_POST['storage_temperature']) ? trim($_POST['storage_temperature']) : null;
        $sample_condition = !empty($_POST['sample_condition']) ? trim($_POST['sample_condition']) : null;
        $collection_notes = !empty($_POST['collection_notes']) ? trim($_POST['collection_notes']) : null;
        $collected_by = $_SESSION['user_id'];
        $quality_check = isset($_POST['quality_check']) ? 1 : 0;
        
        // Generate unique sample code
        $sample_prefix = 'SMP';
        $date_code = date('ymd');
        $random_code = strtoupper(substr(md5(uniqid(rand(), true)), 0, 6));
        $sample_code = $sample_prefix . $date_code . $random_code;
        
        // Generate sample number (sequential per day)
        $today = date('Y-m-d');
        $sample_count_sql = "SELECT COUNT(*) as count FROM lab_samples WHERE DATE(created_at) = ?";
        $sample_count_stmt = $mysqli->prepare($sample_count_sql);
        $sample_count_stmt->bind_param("s", $today);
        $sample_count_stmt->execute();
        $sample_count_result = $sample_count_stmt->get_result();
        $sample_count = $sample_count_result->fetch_assoc()['count'] + 1;
        $sample_number = 'SN' . date('Ymd') . str_pad($sample_count, 4, '0', STR_PAD_LEFT);
        
        // AUDIT LOG: Sample collection attempt
        audit_log($mysqli, [
            'user_id'     => $collected_by,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'SAMPLE_COLLECTION',
            'module'      => 'Specimen Collection',
            'table_name'  => 'lab_samples',
            'entity_type' => 'lab_sample',
            'record_id'   => null,
            'patient_id'  => $patient_info['patient_id'],
            'visit_id'    => $visit_id,
            'description' => "Attempting to collect specimen for lab order ID " . $lab_order_id . ", test ID " . $test_id . ". Specimen type: " . $specimen_type,
            'status'      => 'ATTEMPT',
            'old_values'  => null,
            'new_values'  => [
                'lab_order_id' => $lab_order_id,
                'test_id' => $test_id,
                'sample_code' => $sample_code,
                'specimen_type' => $specimen_type,
                'collected_by' => $collected_by
            ]
        ]);
        
        // Start transaction
        $mysqli->begin_transaction();
        
        try {
            // Insert sample record
            $insert_sql = "INSERT INTO lab_samples 
                          (lab_order_id, test_id, sample_code, sample_number, 
                           specimen_type, collection_date, collected_by,
                           collection_site, collected_volume, volume_unit,
                           collection_tube, storage_temperature, sample_condition,
                           collection_notes, quality_check, sample_status)
                          VALUES (?, ?, ?, ?, ?, CONCAT(?, ' ', ?), ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Collected')";
            
            $insert_stmt = $mysqli->prepare($insert_sql);
            $insert_stmt->bind_param("iissssssssssssi",
                $lab_order_id, $test_id, $sample_code, $sample_number,
                $specimen_type, $collection_date, $collection_time, $collected_by,
                $collection_site, $collected_volume, $volume_unit,
                $collection_tube, $storage_temperature, $sample_condition,
                $collection_notes, $quality_check
            );
            
            if (!$insert_stmt->execute()) {
                throw new Exception("Failed to record sample collection: " . $mysqli->error);
            }
            
            $sample_id = $mysqli->insert_id;
            
            // Update lab_order_tests status
            $update_test_sql = "UPDATE lab_order_tests 
                               SET status = 'specimen_collected'
                               WHERE lab_order_test_id = ?";
            $update_test_stmt = $mysqli->prepare($update_test_sql);
            $update_test_stmt->bind_param("i", $lab_order_test_id);
            
            if (!$update_test_stmt->execute()) {
                throw new Exception("Failed to update test status: " . $mysqli->error);
            }
            
            // Check if all tests in this order have samples collected
            $check_order_sql = "SELECT COUNT(*) as pending_count 
                               FROM lab_order_tests 
                               WHERE lab_order_id = ? 
                               AND status IN ('pending', 'specimen_required')
                               AND is_active = 1";
            $check_order_stmt = $mysqli->prepare($check_order_sql);
            $check_order_stmt->bind_param("i", $lab_order_id);
            $check_order_stmt->execute();
            $check_order_result = $check_order_stmt->get_result();
            $pending_count = $check_order_result->fetch_assoc()['pending_count'];
            
            // Get current order status for audit log
            $current_order_sql = "SELECT lab_order_status FROM lab_orders WHERE lab_order_id = ?";
            $current_order_stmt = $mysqli->prepare($current_order_sql);
            $current_order_stmt->bind_param("i", $lab_order_id);
            $current_order_stmt->execute();
            $current_order_result = $current_order_stmt->get_result();
            $current_order = $current_order_result->fetch_assoc();
            $old_order_status = $current_order['lab_order_status'] ?? null;
            
            // Update lab_order status
            if ($pending_count == 0) {
                // All samples collected
                $new_order_status = 'Collected';
                $update_order_sql = "UPDATE lab_orders 
                                    SET lab_order_status = 'Collected'
                                    WHERE lab_order_id = ?";
            } else {
                // Some samples still pending
                $new_order_status = 'Partially Collected';
                $update_order_sql = "UPDATE lab_orders 
                                    SET lab_order_status = 'Partially Collected'
                                    WHERE lab_order_id = ?";
            }
            
            $update_order_stmt = $mysqli->prepare($update_order_sql);
            $update_order_stmt->bind_param("i", $lab_order_id);
            
            if (!$update_order_stmt->execute()) {
                throw new Exception("Failed to update order status: " . $mysqli->error);
            }
            
            // Log activity
            $activity_sql = "INSERT INTO lab_activities 
                            (test_id, activity_type, activity_description, performed_by)
                            VALUES (?, 'sample_collection', ?, ?)";
            $activity_stmt = $mysqli->prepare($activity_sql);
            $activity_desc = "Sample collected: " . $sample_code . " for test ID " . $test_id;
            $activity_stmt->bind_param("isi", $test_id, $activity_desc, $collected_by);
            
            if (!$activity_stmt->execute()) {
                throw new Exception("Failed to log activity: " . $mysqli->error);
            }
            
            // Commit transaction
            $mysqli->commit();
            
            // AUDIT LOG: Successful sample collection
            audit_log($mysqli, [
                'user_id'     => $collected_by,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'SAMPLE_COLLECTION',
                'module'      => 'Specimen Collection',
                'table_name'  => 'lab_samples',
                'entity_type' => 'lab_sample',
                'record_id'   => $sample_id,
                'patient_id'  => $patient_info['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Sample collected successfully. Sample Code: " . $sample_code . ", Sample ID: " . $sample_id,
                'status'      => 'SUCCESS',
                'old_values'  => [
                    'lab_order_status' => $old_order_status,
                    'test_status' => 'pending'
                ],
                'new_values'  => [
                    'sample_id' => $sample_id,
                    'sample_code' => $sample_code,
                    'sample_number' => $sample_number,
                    'lab_order_status' => $new_order_status,
                    'test_status' => 'specimen_collected',
                    'collected_by' => $collected_by,
                    'collection_date' => $collection_date . ' ' . $collection_time
                ]
            ]);
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Sample collected successfully. Sample Code: " . $sample_code;
            $_SESSION['last_sample_code'] = $sample_code;
            header("Location: collect_specimen.php?visit_id=" . $visit_id . "&print=" . $sample_code);
            exit;
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $mysqli->rollback();
            
            // AUDIT LOG: Failed sample collection
            audit_log($mysqli, [
                'user_id'     => $collected_by,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'SAMPLE_COLLECTION',
                'module'      => 'Specimen Collection',
                'table_name'  => 'lab_samples',
                'entity_type' => 'lab_sample',
                'record_id'   => null,
                'patient_id'  => $patient_info['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Failed to collect sample. Error: " . $e->getMessage(),
                'status'      => 'FAILED',
                'old_values'  => null,
                'new_values'  => null
            ]);
            
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error: " . $e->getMessage();
        }
    }
    
    // Handle sample rejection
    if (isset($_POST['reject_sample'])) {
        $sample_id = intval($_POST['sample_id']);
        $rejection_reason = !empty($_POST['rejection_reason']) ? trim($_POST['rejection_reason']) : null;
        $rejected_by = $_SESSION['user_id'];
        
        // Get current sample info for audit log
        $current_sample_sql = "SELECT sample_code, sample_status FROM lab_samples WHERE sample_id = ?";
        $current_sample_stmt = $mysqli->prepare($current_sample_sql);
        $current_sample_stmt->bind_param("i", $sample_id);
        $current_sample_stmt->execute();
        $current_sample_result = $current_sample_stmt->get_result();
        $current_sample = $current_sample_result->fetch_assoc();
        $old_sample_status = $current_sample['sample_status'] ?? null;
        $sample_code = $current_sample['sample_code'] ?? null;
        
        // AUDIT LOG: Sample rejection attempt
        audit_log($mysqli, [
            'user_id'     => $rejected_by,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'SAMPLE_REJECTION',
            'module'      => 'Specimen Collection',
            'table_name'  => 'lab_samples',
            'entity_type' => 'lab_sample',
            'record_id'   => $sample_id,
            'patient_id'  => $patient_info['patient_id'],
            'visit_id'    => $visit_id,
            'description' => "Attempting to reject sample ID " . $sample_id . " (" . $sample_code . "). Reason: " . $rejection_reason,
            'status'      => 'ATTEMPT',
            'old_values'  => ['sample_status' => $old_sample_status],
            'new_values'  => [
                'sample_status' => 'Rejected',
                'rejection_reason' => $rejection_reason,
                'rejected_by' => $rejected_by
            ]
        ]);
        
        $reject_sql = "UPDATE lab_samples 
                      SET sample_status = 'Rejected',
                          collection_notes = CONCAT(COALESCE(collection_notes, ''), '\nREJECTED: ', ?)
                      WHERE sample_id = ?";
        
        $reject_stmt = $mysqli->prepare($reject_sql);
        $reject_stmt->bind_param("si", $rejection_reason, $sample_id);
        
        if ($reject_stmt->execute()) {
            // Log activity
            $test_id_sql = "SELECT test_id FROM lab_samples WHERE sample_id = ?";
            $test_id_stmt = $mysqli->prepare($test_id_sql);
            $test_id_stmt->bind_param("i", $sample_id);
            $test_id_stmt->execute();
            $test_id_result = $test_id_stmt->get_result();
            $test_id_row = $test_id_result->fetch_assoc();
            
            if ($test_id_row) {
                $activity_sql = "INSERT INTO lab_activities 
                                (test_id, activity_type, activity_description, performed_by)
                                VALUES (?, 'sample_rejection', ?, ?)";
                $activity_stmt = $mysqli->prepare($activity_sql);
                $activity_desc = "Sample rejected: " . $rejection_reason;
                $activity_stmt->bind_param("isi", $test_id_row['test_id'], $activity_desc, $rejected_by);
                $activity_stmt->execute();
            }
            
            // AUDIT LOG: Successful sample rejection
            audit_log($mysqli, [
                'user_id'     => $rejected_by,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'SAMPLE_REJECTION',
                'module'      => 'Specimen Collection',
                'table_name'  => 'lab_samples',
                'entity_type' => 'lab_sample',
                'record_id'   => $sample_id,
                'patient_id'  => $patient_info['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Sample rejected successfully. Sample ID: " . $sample_id . " (" . $sample_code . ")",
                'status'      => 'SUCCESS',
                'old_values'  => ['sample_status' => $old_sample_status],
                'new_values'  => [
                    'sample_status' => 'Rejected',
                    'rejection_reason' => $rejection_reason,
                    'rejected_by' => $rejected_by
                ]
            ]);
            
            $_SESSION['alert_type'] = "warning";
            $_SESSION['alert_message'] = "Sample rejected successfully";
            header("Location: collect_specimen.php?visit_id=" . $visit_id);
            exit;
        } else {
            // AUDIT LOG: Failed sample rejection
            audit_log($mysqli, [
                'user_id'     => $rejected_by,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'SAMPLE_REJECTION',
                'module'      => 'Specimen Collection',
                'table_name'  => 'lab_samples',
                'entity_type' => 'lab_sample',
                'record_id'   => $sample_id,
                'patient_id'  => $patient_info['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Failed to reject sample. Error: " . $mysqli->error,
                'status'      => 'FAILED',
                'old_values'  => null,
                'new_values'  => null
            ]);
            
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error rejecting sample: " . $mysqli->error;
        }
    }
    
    // Handle sample reception at lab
    if (isset($_POST['receive_sample'])) {
        $sample_id = intval($_POST['sample_id']);
        $received_by = $_SESSION['user_id'];
        
        // Get current sample info for audit log
        $current_sample_sql = "SELECT sample_code, sample_status FROM lab_samples WHERE sample_id = ?";
        $current_sample_stmt = $mysqli->prepare($current_sample_sql);
        $current_sample_stmt->bind_param("i", $sample_id);
        $current_sample_stmt->execute();
        $current_sample_result = $current_sample_stmt->get_result();
        $current_sample = $current_sample_result->fetch_assoc();
        $old_sample_status = $current_sample['sample_status'] ?? null;
        $sample_code = $current_sample['sample_code'] ?? null;
        
        // AUDIT LOG: Sample reception attempt
        audit_log($mysqli, [
            'user_id'     => $received_by,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'SAMPLE_RECEPTION',
            'module'      => 'Specimen Collection',
            'table_name'  => 'lab_samples',
            'entity_type' => 'lab_sample',
            'record_id'   => $sample_id,
            'patient_id'  => $patient_info['patient_id'],
            'visit_id'    => $visit_id,
            'description' => "Attempting to receive sample at lab. Sample ID: " . $sample_id . " (" . $sample_code . ")",
            'status'      => 'ATTEMPT',
            'old_values'  => ['sample_status' => $old_sample_status],
            'new_values'  => [
                'sample_status' => 'Received',
                'received_by' => $received_by,
                'received_date' => date('Y-m-d H:i:s')
            ]
        ]);
        
        $receive_sql = "UPDATE lab_samples 
                       SET sample_status = 'Received',
                           received_date = NOW()
                       WHERE sample_id = ?";
        
        $receive_stmt = $mysqli->prepare($receive_sql);
        $receive_stmt->bind_param("i", $sample_id);
        
        if ($receive_stmt->execute()) {
            // Log activity
            $test_id_sql = "SELECT test_id FROM lab_samples WHERE sample_id = ?";
            $test_id_stmt = $mysqli->prepare($test_id_sql);
            $test_id_stmt->bind_param("i", $sample_id);
            $test_id_stmt->execute();
            $test_id_result = $test_id_stmt->get_result();
            $test_id_row = $test_id_result->fetch_assoc();
            
            if ($test_id_row) {
                $activity_sql = "INSERT INTO lab_activities 
                                (test_id, activity_type, activity_description, performed_by)
                                VALUES (?, 'sample_reception', 'Sample received at laboratory', ?)";
                $activity_stmt = $mysqli->prepare($activity_sql);
                $activity_stmt->bind_param("ii", $test_id_row['test_id'], $received_by);
                $activity_stmt->execute();
            }
            
            // AUDIT LOG: Successful sample reception
            audit_log($mysqli, [
                'user_id'     => $received_by,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'SAMPLE_RECEPTION',
                'module'      => 'Specimen Collection',
                'table_name'  => 'lab_samples',
                'entity_type' => 'lab_sample',
                'record_id'   => $sample_id,
                'patient_id'  => $patient_info['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Sample received at lab successfully. Sample ID: " . $sample_id . " (" . $sample_code . ")",
                'status'      => 'SUCCESS',
                'old_values'  => ['sample_status' => $old_sample_status],
                'new_values'  => [
                    'sample_status' => 'Received',
                    'received_by' => $received_by,
                    'received_date' => date('Y-m-d H:i:s')
                ]
            ]);
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Sample received at lab successfully";
            header("Location: collect_specimen.php?visit_id=" . $visit_id);
            exit;
        } else {
            // AUDIT LOG: Failed sample reception
            audit_log($mysqli, [
                'user_id'     => $received_by,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'SAMPLE_RECEPTION',
                'module'      => 'Specimen Collection',
                'table_name'  => 'lab_samples',
                'entity_type' => 'lab_sample',
                'record_id'   => $sample_id,
                'patient_id'  => $patient_info['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Failed to receive sample at lab. Error: " . $mysqli->error,
                'status'      => 'FAILED',
                'old_values'  => null,
                'new_values'  => null
            ]);
            
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error receiving sample: " . $mysqli->error;
        }
    }
    
    // Handle quality check
    if (isset($_POST['quality_check_sample'])) {
        $sample_id = intval($_POST['sample_id']);
        $quality_check = isset($_POST['quality_ok']) ? 1 : 0;
        $quality_notes = !empty($_POST['quality_notes']) ? trim($_POST['quality_notes']) : null;
        $checked_by = $_SESSION['user_id'];
        
        // Get current sample info for audit log
        $current_sample_sql = "SELECT sample_code, quality_check FROM lab_samples WHERE sample_id = ?";
        $current_sample_stmt = $mysqli->prepare($current_sample_sql);
        $current_sample_stmt->bind_param("i", $sample_id);
        $current_sample_stmt->execute();
        $current_sample_result = $current_sample_stmt->get_result();
        $current_sample = $current_sample_result->fetch_assoc();
        $old_quality_check = $current_sample['quality_check'] ?? null;
        $sample_code = $current_sample['sample_code'] ?? null;
        
        // AUDIT LOG: Quality check attempt
        audit_log($mysqli, [
            'user_id'     => $checked_by,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'SAMPLE_QUALITY_CHECK',
            'module'      => 'Specimen Collection',
            'table_name'  => 'lab_samples',
            'entity_type' => 'lab_sample',
            'record_id'   => $sample_id,
            'patient_id'  => $patient_info['patient_id'],
            'visit_id'    => $visit_id,
            'description' => "Attempting quality check for sample ID " . $sample_id . " (" . $sample_code . "). Result: " . ($quality_check ? 'Pass' : 'Fail'),
            'status'      => 'ATTEMPT',
            'old_values'  => ['quality_check' => $old_quality_check],
            'new_values'  => [
                'quality_check' => $quality_check,
                'quality_notes' => $quality_notes,
                'checked_by' => $checked_by
            ]
        ]);
        
        $quality_sql = "UPDATE lab_samples 
                       SET quality_check = ?,
                           collection_notes = CONCAT(COALESCE(collection_notes, ''), '\nQUALITY CHECK: ', ?)
                       WHERE sample_id = ?";
        
        $quality_stmt = $mysqli->prepare($quality_sql);
        $quality_stmt->bind_param("isi", $quality_check, $quality_notes, $sample_id);
        
        if ($quality_stmt->execute()) {
            // Log activity
            $test_id_sql = "SELECT test_id FROM lab_samples WHERE sample_id = ?";
            $test_id_stmt = $mysqli->prepare($test_id_sql);
            $test_id_stmt->bind_param("i", $sample_id);
            $test_id_stmt->execute();
            $test_id_result = $test_id_stmt->get_result();
            $test_id_row = $test_id_result->fetch_assoc();
            
            if ($test_id_row) {
                $activity_type = $quality_check ? 'quality_check_pass' : 'quality_check_fail';
                $activity_sql = "INSERT INTO lab_activities 
                                (test_id, activity_type, activity_description, performed_by)
                                VALUES (?, ?, ?, ?)";
                $activity_stmt = $mysqli->prepare($activity_sql);
                $activity_desc = "Quality check: " . ($quality_check ? "Passed" : "Failed") . " - " . $quality_notes;
                $activity_stmt->bind_param("issi", $test_id_row['test_id'], $activity_type, $activity_desc, $checked_by);
                $activity_stmt->execute();
            }
            
            // AUDIT LOG: Successful quality check
            audit_log($mysqli, [
                'user_id'     => $checked_by,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'SAMPLE_QUALITY_CHECK',
                'module'      => 'Specimen Collection',
                'table_name'  => 'lab_samples',
                'entity_type' => 'lab_sample',
                'record_id'   => $sample_id,
                'patient_id'  => $patient_info['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Quality check recorded for sample ID " . $sample_id . " (" . $sample_code . "). Result: " . ($quality_check ? 'Passed' : 'Failed'),
                'status'      => 'SUCCESS',
                'old_values'  => ['quality_check' => $old_quality_check],
                'new_values'  => [
                    'quality_check' => $quality_check,
                    'quality_notes' => $quality_notes,
                    'checked_by' => $checked_by
                ]
            ]);
            
            $_SESSION['alert_type'] = "info";
            $_SESSION['alert_message'] = "Quality check recorded successfully";
            header("Location: collect_specimen.php?visit_id=" . $visit_id);
            exit;
        } else {
            // AUDIT LOG: Failed quality check
            audit_log($mysqli, [
                'user_id'     => $checked_by,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'SAMPLE_QUALITY_CHECK',
                'module'      => 'Specimen Collection',
                'table_name'  => 'lab_samples',
                'entity_type' => 'lab_sample',
                'record_id'   => $sample_id,
                'patient_id'  => $patient_info['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Failed to record quality check. Error: " . $mysqli->error,
                'status'      => 'FAILED',
                'old_values'  => null,
                'new_values'  => null
            ]);
            
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error recording quality check: " . $mysqli->error;
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
    $today_date = new DateTime();
    $age = $today_date->diff($birthDate)->y . ' years';
}

// Get visit number
$visit_number = $visit_info['visit_number'];
if ($visit_type === 'IPD' && !empty($visit_info['admission_number'])) {
    $visit_number = $visit_info['admission_number'];
}

// Function to get status badge
function getSampleStatusBadge($status) {
    switch($status) {
        case 'Pending':
            return '<span class="badge badge-secondary"><i class="fas fa-clock mr-1"></i>Pending</span>';
        case 'Collected':
            return '<span class="badge badge-info"><i class="fas fa-check-circle mr-1"></i>Collected</span>';
        case 'In Transit':
            return '<span class="badge badge-warning"><i class="fas fa-truck mr-1"></i>In Transit</span>';
        case 'Received':
            return '<span class="badge badge-success"><i class="fas fa-vial mr-1"></i>Received</span>';
        case 'Rejected':
            return '<span class="badge badge-danger"><i class="fas fa-times-circle mr-1"></i>Rejected</span>';
        case 'Completed':
            return '<span class="badge badge-primary"><i class="fas fa-check-double mr-1"></i>Completed</span>';
        default:
            return '<span class="badge badge-light">' . $status . '</span>';
    }
}

// Function to get priority badge
function getPriorityBadge($priority) {
    switch($priority) {
        case 'stat':
            return '<span class="badge badge-danger"><i class="fas fa-bolt mr-1"></i>STAT</span>';
        case 'urgent':
            return '<span class="badge badge-warning"><i class="fas fa-exclamation-triangle mr-1"></i>Urgent</span>';
        case 'routine':
            return '<span class="badge badge-info"><i class="fas fa-clock mr-1"></i>Routine</span>';
        default:
            return '<span class="badge badge-secondary">' . $priority . '</span>';
    }
}

// Function to get quality check badge
function getQualityCheckBadge($quality_check) {
    if ($quality_check == 1) {
        return '<span class="badge badge-success"><i class="fas fa-check mr-1"></i>Quality OK</span>';
    } elseif ($quality_check == 0) {
        return '<span class="badge badge-danger"><i class="fas fa-times mr-1"></i>Quality Issue</span>';
    } else {
        return '<span class="badge badge-secondary"><i class="fas fa-question mr-1"></i>Not Checked</span>';
    }
}
?>
<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0">
            <i class="fas fa-fw fa-vial mr-2"></i>Sample Collection: <?php echo htmlspecialchars($patient_info['patient_mrn']); ?>
        </h3>
        <div class="card-tools">
            <div class="btn-group">
                <button type="button" class="btn btn-light" onclick="window.history.back()">
                    <i class="fas fa-arrow-left mr-2"></i>Back
                </button>
                <button type="button" class="btn btn-success" onclick="printLabel()">
                    <i class="fas fa-print mr-2"></i>Print Label
                </button>
                <a href="/clinic/doctor/order_lab.php?visit_id=<?php echo $visit_id; ?>" class="btn btn-info">
                    <i class="fas fa-flask mr-2"></i>Order Lab Test
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
                                        <i class="fas fa-flask text-primary mr-1"></i>
                                        <span class="badge badge-light"><?php echo count($lab_orders); ?> Pending Tests</span>
                                    </span>
                                    <br>
                                    <span class="h5">
                                        <i class="fas fa-vial text-success mr-1"></i>
                                        <span class="badge badge-light"><?php echo count($collected_samples); ?> Samples</span>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Sample Collection Form -->
            <div class="col-md-5">
                <div class="card">
                    <div class="card-header bg-success py-2">
                        <h4 class="card-title mb-0 text-white">
                            <i class="fas fa-plus-circle mr-2"></i>Collect Sample for Test
                        </h4>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="sampleForm">
                            <!-- Test Selection -->
                            <div class="form-group">
                                <label for="lab_order_test_id">Select Test to Collect *</label>
                                <select class="form-control select2" id="lab_order_test_id" name="lab_order_test_id" required>
                                    <option value="">-- Select Test --</option>
                                    <?php 
                                    $current_order = null;
                                    foreach ($lab_orders as $order): 
                                        if ($current_order != $order['lab_order_id']) {
                                            $current_order = $order['lab_order_id'];
                                            echo '<optgroup label="Order #' . $order['order_number'] . ' - ' . getPriorityBadge($order['order_priority']) . '">';
                                        }
                                    ?>
                                        <option value="<?php echo $order['lab_order_test_id']; ?>"
                                                data-lab-order-id="<?php echo $order['lab_order_id']; ?>"
                                                data-test-id="<?php echo $order['test_id']; ?>"
                                                data-test-name="<?php echo htmlspecialchars($order['test_name']); ?>"
                                                data-specimen-type="<?php echo htmlspecialchars($order['specimen_type'] ?? ''); ?>"
                                                data-required-volume="<?php echo htmlspecialchars($order['required_volume'] ?? ''); ?>">
                                            <?php echo htmlspecialchars($order['test_name'] . ' (' . $order['test_code'] . ')'); ?>
                                            <?php if ($order['required_volume']): ?>
                                                - Requires: <?php echo $order['required_volume']; ?>
                                            <?php endif; ?>
                                        </option>
                                    <?php 
                                        if ($current_order != $order['lab_order_id']) {
                                            echo '</optgroup>';
                                        }
                                    endforeach; 
                                    ?>
                                </select>
                                <input type="hidden" id="lab_order_id" name="lab_order_id">
                                <input type="hidden" id="test_id" name="test_id">
                                <input type="hidden" id="test_name_display" name="test_name_display">
                            </div>
                            
                            <!-- Selected Test Info -->
                            <div class="alert alert-info" id="testInfo" style="display: none;">
                                <h6><i class="fas fa-info-circle mr-2"></i>Test Information</h6>
                                <div class="row">
                                    <div class="col-md-6">
                                        <small><strong>Test:</strong> <span id="display_test_name"></span></small><br>
                                        <small><strong>Code:</strong> <span id="display_test_code"></span></small>
                                    </div>
                                    <div class="col-md-6">
                                        <small><strong>Required Specimen:</strong> <span id="display_specimen_type"></span></small><br>
                                        <small><strong>Required Volume:</strong> <span id="display_required_volume"></span></small>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Specimen Type -->
                            <div class="form-group">
                                <label for="specimen_type">Specimen Type *</label>
                                <select class="form-control select2" id="specimen_type" name="specimen_type" required>
                                    <option value="">-- Select Specimen Type --</option>
                                    <?php foreach ($specimen_types as $type): ?>
                                        <option value="<?php echo htmlspecialchars($type['specimen_type']); ?>">
                                            <?php echo htmlspecialchars($type['specimen_type']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="collection_date">Collection Date *</label>
                                        <input type="date" class="form-control" id="collection_date" name="collection_date" 
                                               value="<?php echo date('Y-m-d'); ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="collection_time">Collection Time *</label>
                                        <input type="time" class="form-control" id="collection_time" name="collection_time" 
                                               value="<?php echo date('H:i'); ?>" required>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Collection Details -->
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="collection_site">Collection Site</label>
                                        <input type="text" class="form-control" id="collection_site" name="collection_site" 
                                               placeholder="e.g., Right arm, Midstream urine">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="collection_tube">Collection Tube/Container</label>
                                        <input type="text" class="form-control" id="collection_tube" name="collection_tube" 
                                               placeholder="e.g., EDTA tube, Sterile cup">
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Volume -->
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="collected_volume">Collected Volume</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control" id="collected_volume" name="collected_volume" 
                                                   placeholder="e.g., 5.0">
                                            <div class="input-group-append">
                                                <select class="form-control" id="volume_unit" name="volume_unit" style="width: 80px;">
                                                    <option value="mL" selected>mL</option>
                                                    <option value="µL">µL</option>
                                                    <option value="g">g</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="storage_temperature">Storage Temperature</label>
                                        <input type="text" class="form-control" id="storage_temperature" name="storage_temperature" 
                                               placeholder="e.g., Room temp, 2-8°C">
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Sample Condition -->
                            <div class="form-group">
                                <label for="sample_condition">Sample Condition</label>
                                <select class="form-control" id="sample_condition" name="sample_condition">
                                    <option value="">-- Select Condition --</option>
                                    <option value="Satisfactory">Satisfactory</option>
                                    <option value="Hemolyzed">Hemolyzed</option>
                                    <option value="Lipemic">Lipemic</option>
                                    <option value="Icteric">Icteric</option>
                                    <option value="Clotted">Clotted</option>
                                    <option value="Insufficient">Insufficient Volume</option>
                                    <option value="Contaminated">Contaminated</option>
                                    <option value="Improper Collection">Improper Collection</option>
                                </select>
                            </div>
                            
                            <!-- Quality Check -->
                            <div class="form-group">
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input" id="quality_check" name="quality_check" value="1">
                                    <label class="custom-control-label" for="quality_check">
                                        <i class="fas fa-check-circle text-success mr-1"></i>Quality Check Passed
                                    </label>
                                </div>
                                <small class="form-text text-muted">Check if sample meets quality requirements</small>
                            </div>
                            
                            <!-- Collection Notes -->
                            <div class="form-group">
                                <label for="collection_notes">Collection Notes</label>
                                <textarea class="form-control" id="collection_notes" name="collection_notes" 
                                          rows="3" placeholder="Any special instructions, observations, or issues during collection..."></textarea>
                            </div>
                            
                            <button type="submit" name="collect_sample" class="btn btn-success btn-lg btn-block">
                                <i class="fas fa-save mr-2"></i>Collect Sample
                            </button>
                        </form>
                        
                        <!-- Quick Collection for Common Tests -->
                        <div class="mt-4 p-3 bg-light rounded">
                            <h6><i class="fas fa-bolt mr-2"></i>Quick Collection</h6>
                            <div class="btn-group btn-group-sm d-flex" role="group">
                                <button type="button" class="btn btn-outline-primary flex-fill" onclick="quickCollection('CBC')">
                                    <i class="fas fa-tint mr-1"></i>CBC
                                </button>
                                <button type="button" class="btn btn-outline-success flex-fill" onclick="quickCollection('URINALYSIS')">
                                    <i class="fas fa-flask mr-1"></i>Urinalysis
                                </button>
                                <button type="button" class="btn btn-outline-warning flex-fill" onclick="quickCollection('GLUCOSE')">
                                    <i class="fas fa-syringe mr-1"></i>Glucose
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sample Collection History -->
            <div class="col-md-7">
                <div class="card">
                    <div class="card-header bg-info py-2">
                        <h4 class="card-title mb-0 text-white">
                            <i class="fas fa-history mr-2"></i>Sample Collection History
                            <span class="badge badge-light float-right"><?php echo count($collected_samples); ?> samples</span>
                        </h4>
                    </div>
                    <div class="card-body p-0">
                        <?php if (!empty($collected_samples)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Sample Code</th>
                                            <th>Test</th>
                                            <th>Date/Time</th>
                                            <th>Status</th>
                                            <th>Quality</th>
                                            <th class="text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($collected_samples as $sample): 
                                            $collection_datetime = $sample['collection_date'];
                                        ?>
                                            <tr>
                                                <td>
                                                    <div class="font-weight-bold text-primary">
                                                        <?php echo htmlspecialchars($sample['sample_code']); ?>
                                                    </div>
                                                    <small class="text-muted">
                                                        <?php echo htmlspecialchars($sample['specimen_type'] ?? 'N/A'); ?>
                                                    </small>
                                                    <?php if ($sample['collected_volume']): ?>
                                                        <br>
                                                        <small class="text-muted">
                                                            <?php echo $sample['collected_volume']; ?> <?php echo $sample['volume_unit']; ?>
                                                        </small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="font-weight-bold">
                                                        <?php echo htmlspecialchars($sample['test_name']); ?>
                                                    </div>
                                                    <small class="text-muted">
                                                        <?php echo htmlspecialchars($sample['test_code']); ?>
                                                    </small>
                                                    <br>
                                                    <small class="text-muted">
                                                        Order: <?php echo htmlspecialchars($sample['order_number']); ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <div>
                                                        <?php echo date('M j', strtotime($collection_datetime)); ?>
                                                    </div>
                                                    <small class="text-muted">
                                                        <?php echo date('H:i', strtotime($collection_datetime)); ?>
                                                    </small>
                                                    <?php if ($sample['received_date']): ?>
                                                        <br>
                                                        <small class="text-success">
                                                            Received: <?php echo date('H:i', strtotime($sample['received_date'])); ?>
                                                        </small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php echo getSampleStatusBadge($sample['sample_status']); ?>
                                                    <?php if ($sample['sample_condition'] && $sample['sample_condition'] != 'Satisfactory'): ?>
                                                        <br>
                                                        <small class="text-warning">
                                                            <?php echo htmlspecialchars($sample['sample_condition']); ?>
                                                        </small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php echo getQualityCheckBadge($sample['quality_check']); ?>
                                                    <?php if ($sample['collected_by_name']): ?>
                                                        <br>
                                                        <small class="text-muted">
                                                            By: <?php echo htmlspecialchars($sample['collected_by_name']); ?>
                                                        </small>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <button type="button" class="btn btn-sm btn-info" 
                                                            onclick="viewSampleDetails(<?php echo htmlspecialchars(json_encode($sample)); ?>)"
                                                            title="View Details">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-success" 
                                                            onclick="printSampleLabel('<?php echo $sample['sample_code']; ?>')"
                                                            title="Print Label">
                                                        <i class="fas fa-print"></i>
                                                    </button>
                                                    <?php if ($sample['sample_status'] == 'Collected'): ?>
                                                        <button type="button" class="btn btn-sm btn-warning" 
                                                                data-toggle="modal" data-target="#receiveModal"
                                                                onclick="prepareReceiveModal(<?php echo $sample['sample_id']; ?>)"
                                                                title="Receive at Lab">
                                                            <i class="fas fa-vial"></i>
                                                        </button>
                                                        <button type="button" class="btn btn-sm btn-danger" 
                                                                data-toggle="modal" data-target="#rejectModal"
                                                                onclick="prepareRejectModal(<?php echo $sample['sample_id']; ?>)"
                                                                title="Reject Sample">
                                                            <i class="fas fa-times"></i>
                                                        </button>
                                                        <button type="button" class="btn btn-sm btn-secondary" 
                                                                data-toggle="modal" data-target="#qualityModal"
                                                                onclick="prepareQualityModal(<?php echo $sample['sample_id']; ?>)"
                                                                title="Quality Check">
                                                            <i class="fas fa-check-double"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-vial fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">No Samples Collected</h5>
                                <p class="text-muted">No samples have been collected for this visit yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Pending Lab Orders -->
                <div class="card mt-4">
                    <div class="card-header bg-warning py-2">
                        <h4 class="card-title mb-0">
                            <i class="fas fa-clipboard-list mr-2"></i>Pending Lab Orders
                            <span class="badge badge-light float-right"><?php echo count($lab_orders); ?> tests</span>
                        </h4>
                    </div>
                    <div class="card-body p-0">
                        <?php if (!empty($lab_orders)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Order #</th>
                                            <th>Test</th>
                                            <th>Priority</th>
                                            <th>Ordered By</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $current_order = null;
                                        foreach ($lab_orders as $order): 
                                            if ($current_order != $order['lab_order_id']) {
                                                $current_order = $order['lab_order_id'];
                                        ?>
                                            <tr class="bg-light">
                                                <td colspan="5" class="font-weight-bold">
                                                    <i class="fas fa-file-medical mr-2"></i>Order #<?php echo $order['order_number']; ?>
                                                    <small class="text-muted ml-3">
                                                        <?php echo date('M j, H:i', strtotime($order['order_date'])); ?>
                                                    </small>
                                                </td>
                                            </tr>
                                        <?php } ?>
                                            <tr>
                                                <td>
                                                    <div class="font-weight-bold">
                                                        #<?php echo $order['order_number']; ?>
                                                    </div>
                                                    <?php if ($order['clinical_notes']): ?>
                                                        <small class="text-muted" title="<?php echo htmlspecialchars($order['clinical_notes']); ?>">
                                                            <?php echo htmlspecialchars(substr($order['clinical_notes'], 0, 50)); ?>...
                                                        </small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="font-weight-bold">
                                                        <?php echo htmlspecialchars($order['test_name']); ?>
                                                    </div>
                                                    <small class="text-muted">
                                                        <?php echo htmlspecialchars($order['test_code']); ?>
                                                    </small>
                                                    <?php if ($order['required_volume']): ?>
                                                        <br>
                                                        <small class="badge badge-info">
                                                            Need: <?php echo $order['required_volume']; ?>
                                                        </small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php echo getPriorityBadge($order['order_priority']); ?>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($order['doctor_name'] ?? 'N/A'); ?>
                                                </td>
                                                <td>
                                                    <?php 
                                                    $test_status_badge = '';
                                                    switch($order['test_status']) {
                                                        case 'pending':
                                                        case 'specimen_required':
                                                            $test_status_badge = '<span class="badge badge-secondary">Pending Collection</span>';
                                                            break;
                                                        case 'specimen_collected':
                                                            $test_status_badge = '<span class="badge badge-info">Specimen Collected</span>';
                                                            break;
                                                        case 'in_progress':
                                                            $test_status_badge = '<span class="badge badge-warning">In Progress</span>';
                                                            break;
                                                        case 'completed':
                                                            $test_status_badge = '<span class="badge badge-success">Completed</span>';
                                                            break;
                                                        default:
                                                            $test_status_badge = '<span class="badge badge-light">' . $order['test_status'] . '</span>';
                                                    }
                                                    echo $test_status_badge;
                                                    ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-3">
                                <i class="fas fa-clipboard-check fa-2x text-muted mb-2"></i>
                                <h6 class="text-muted">No Pending Lab Orders</h6>
                                <p class="text-muted mb-0">All lab orders have been processed or no orders placed.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Sample Details Modal -->
<div class="modal fade" id="sampleDetailsModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Sample Details</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body" id="sampleDetailsContent">
                <!-- Content will be loaded dynamically -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="printFromModal()">
                    <i class="fas fa-print mr-2"></i>Print Label
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Reject Sample Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">Reject Sample</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" id="reject_sample_id" name="sample_id">
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        <strong>Warning:</strong> This action cannot be undone. Please provide a reason for rejection.
                    </div>
                    
                    <div class="form-group">
                        <label for="rejection_reason">Rejection Reason *</label>
                        <textarea class="form-control" id="rejection_reason" name="rejection_reason" 
                                  rows="4" placeholder="Specify why this sample is being rejected..." required></textarea>
                        <small class="form-text text-muted">
                            Common reasons: Insufficient volume, hemolyzed, wrong container, expired collection, etc.
                        </small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" name="reject_sample" class="btn btn-danger">
                        <i class="fas fa-times-circle mr-2"></i>Reject Sample
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Receive Sample Modal -->
<div class="modal fade" id="receiveModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">Receive Sample at Lab</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" id="receive_sample_id" name="sample_id">
                    
                    <div class="alert alert-info">
                        <i class="fas fa-vial mr-2"></i>
                        <strong>Confirm Receipt:</strong> This will mark the sample as received at the laboratory.
                    </div>
                    
                    <p>Are you sure you want to mark this sample as received at the lab?</p>
                    <p class="text-muted"><small>This action updates the sample status and records the receipt time.</small></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" name="receive_sample" class="btn btn-success">
                        <i class="fas fa-check-circle mr-2"></i>Confirm Receipt
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Quality Check Modal -->
<div class="modal fade" id="qualityModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-secondary text-white">
                <h5 class="modal-title">Quality Check</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" id="quality_sample_id" name="sample_id">
                    
                    <div class="alert alert-info">
                        <i class="fas fa-check-double mr-2"></i>
                        <strong>Quality Assessment:</strong> Evaluate if the sample meets quality requirements.
                    </div>
                    
                    <div class="form-group">
                        <div class="custom-control custom-radio custom-control-inline">
                            <input type="radio" id="quality_ok" name="quality_ok" class="custom-control-input" value="1" checked>
                            <label class="custom-control-label text-success" for="quality_ok">
                                <i class="fas fa-check-circle mr-1"></i>Quality OK
                            </label>
                        </div>
                        <div class="custom-control custom-radio custom-control-inline">
                            <input type="radio" id="quality_fail" name="quality_ok" class="custom-control-input" value="0">
                            <label class="custom-control-label text-danger" for="quality_fail">
                                <i class="fas fa-times-circle mr-1"></i>Quality Issue
                            </label>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="quality_notes">Quality Notes</label>
                        <textarea class="form-control" id="quality_notes" name="quality_notes" 
                                  rows="3" placeholder="Describe any quality issues or confirm sample is acceptable..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" name="quality_check_sample" class="btn btn-primary">
                        <i class="fas fa-save mr-2"></i>Save Quality Check
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Label Print Preview Modal -->
<div class="modal fade" id="printModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">Print Sample Label</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body" id="printPreview">
                <!-- Label preview will be loaded here -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="printLabelNow()">
                    <i class="fas fa-print mr-2"></i>Print Now
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

    // Initialize Select2
    $('.select2').select2({
        theme: 'bootstrap4',
        placeholder: 'Select option',
        allowClear: true
    });

    // Initialize date and time pickers
    $('#collection_date').flatpickr({
        dateFormat: 'Y-m-d',
        maxDate: 'today'
    });
    
    $('#collection_time').flatpickr({
        enableTime: true,
        noCalendar: true,
        dateFormat: 'H:i',
        time_24hr: true,
        minuteIncrement: 1
    });

    // Form validation
    $('#sampleForm').validate({
        rules: {
            lab_order_test_id: {
                required: true
            },
            specimen_type: {
                required: true
            },
            collection_date: {
                required: true,
                date: true
            },
            collection_time: {
                required: true
            }
        },
        messages: {
            lab_order_test_id: {
                required: "Please select a test"
            },
            specimen_type: {
                required: "Please select specimen type"
            }
        }
    });

    // Auto-fill test info when test is selected
    $('#lab_order_test_id').on('change', function() {
        const selected = $(this).find(':selected');
        const labOrderId = selected.data('lab-order-id');
        const testId = selected.data('test-id');
        const testName = selected.data('test-name');
        const specimenType = selected.data('specimen-type');
        const requiredVolume = selected.data('required-volume');
        const testCode = selected.text().match(/\(([^)]+)\)/)?.[1] || '';
        
        if (labOrderId && testId) {
            $('#lab_order_id').val(labOrderId);
            $('#test_id').val(testId);
            $('#test_name_display').val(testName);
            
            // Display test info
            $('#display_test_name').text(testName);
            $('#display_test_code').text(testCode);
            $('#display_specimen_type').text(specimenType || 'Not specified');
            $('#display_required_volume').text(requiredVolume || 'Not specified');
            $('#testInfo').show();
            
            // Auto-fill specimen type if available
            if (specimenType) {
                $('#specimen_type').val(specimenType).trigger('change');
            }
            
            // Auto-fill collected volume if required volume specified
            if (requiredVolume) {
                const volumeMatch = requiredVolume.match(/(\d+(\.\d+)?)/);
                if (volumeMatch) {
                    $('#collected_volume').val(volumeMatch[0]);
                }
            }
        } else {
            $('#testInfo').hide();
        }
    });

    // Check if we need to print a label
    <?php if (isset($_GET['print']) && !empty($_GET['print'])): ?>
    setTimeout(function() {
        printSampleLabel('<?php echo $_GET['print']; ?>');
    }, 1000);
    <?php endif; ?>
});

function quickCollection(testType) {
    // Find the option with this test type
    const option = $('#lab_order_test_id option').filter(function() {
        return $(this).text().includes(testType);
    }).first();
    
    if (option.length) {
        $('#lab_order_test_id').val(option.val()).trigger('change');
        
        // Auto-fill based on test type
        if (testType === 'CBC') {
            $('#specimen_type').val('Blood');
            $('#collection_tube').val('EDTA tube');
            $('#storage_temperature').val('Room temperature');
            $('#collected_volume').val('2.0');
        } else if (testType === 'URINALYSIS') {
            $('#specimen_type').val('Urine');
            $('#collection_tube').val('Sterile urine container');
            $('#storage_temperature').val('2-8°C');
            $('#collected_volume').val('10.0');
        } else if (testType === 'GLUCOSE') {
            $('#specimen_type').val('Blood');
            $('#collection_tube').val('Fluoride tube');
            $('#storage_temperature').val('2-8°C');
            $('#collected_volume').val('1.0');
        }
        
        // Scroll to form
        $('html, body').animate({
            scrollTop: $('#sampleForm').offset().top - 20
        }, 500);
        
        showToast(`Prepared for ${testType} collection`, 'info');
    } else {
        showToast(`No ${testType} test found in pending orders`, 'warning');
    }
}

function viewSampleDetails(sample) {
    const modalContent = document.getElementById('sampleDetailsContent');
    const collectionDate = sample.collection_date ? new Date(sample.collection_date) : null;
    const receivedDate = sample.received_date ? new Date(sample.received_date) : null;
    
    let html = `
        <div class="card mb-3">
            <div class="card-header bg-light py-2">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="mb-0">
                            <i class="fas fa-vial mr-2"></i>${sample.sample_code}
                            <small class="text-muted ml-2">${sample.sample_number}</small>
                        </h5>
                    </div>
                    <div>
                        ${getSampleStatusBadge(sample.sample_status)}
                        ${getQualityCheckBadge(sample.quality_check)}
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless">
                            <tr>
                                <th width="40%">Test:</th>
                                <td><strong>${sample.test_name} (${sample.test_code})</strong></td>
                            </tr>
                            <tr>
                                <th>Order #:</th>
                                <td>${sample.order_number}</td>
                            </tr>
                            <tr>
                                <th>Patient:</th>
                                <td><?php echo htmlspecialchars($full_name); ?></td>
                            </tr>
                            <tr>
                                <th>MRN:</th>
                                <td><span class="badge badge-info"><?php echo htmlspecialchars($patient_info['patient_mrn']); ?></span></td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless">
    `;
    
    if (collectionDate) {
        html += `<tr>
                    <th width="40%">Collection:</th>
                    <td>${collectionDate.toLocaleDateString()} ${collectionDate.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</td>
                </tr>`;
    }
    if (sample.collected_by_name) {
        html += `<tr>
                    <th>Collected By:</th>
                    <td>${sample.collected_by_name}</td>
                </tr>`;
    }
    if (receivedDate) {
        html += `<tr>
                    <th>Received at Lab:</th>
                    <td>${receivedDate.toLocaleDateString()} ${receivedDate.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</td>
                </tr>`;
    }
    if (sample.received_by_name) {
        html += `<tr>
                    <th>Received By:</th>
                    <td>${sample.received_by_name}</td>
                </tr>`;
    }
    
    html += `       </table>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="card mb-3">
                            <div class="card-header bg-light py-2">
                                <h6 class="mb-0"><i class="fas fa-info-circle mr-2"></i>Sample Details</h6>
                            </div>
                            <div class="card-body">
                                <table class="table table-sm table-borderless">
    `;
    
    if (sample.specimen_type) {
        html += `<tr><th>Specimen Type:</th><td>${sample.specimen_type}</td></tr>`;
    }
    if (sample.collection_site) {
        html += `<tr><th>Collection Site:</th><td>${sample.collection_site}</td></tr>`;
    }
    if (sample.collected_volume) {
        html += `<tr><th>Volume:</th><td>${sample.collected_volume} ${sample.volume_unit || 'mL'}</td></tr>`;
    }
    if (sample.collection_tube) {
        html += `<tr><th>Container:</th><td>${sample.collection_tube}</td></tr>`;
    }
    if (sample.required_volume) {
        html += `<tr><th>Required Volume:</th><td>${sample.required_volume}</td></tr>`;
    }
    
    html += `           </table>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card mb-3">
                            <div class="card-header bg-light py-2">
                                <h6 class="mb-0"><i class="fas fa-temperature-low mr-2"></i>Storage & Condition</h6>
                            </div>
                            <div class="card-body">
                                <table class="table table-sm table-borderless">
    `;
    
    if (sample.storage_temperature) {
        html += `<tr><th>Storage Temp:</th><td>${sample.storage_temperature}</td></tr>`;
    }
    if (sample.sample_condition) {
        const conditionClass = sample.sample_condition === 'Satisfactory' ? 'text-success' : 'text-warning';
        html += `<tr><th>Condition:</th><td class="${conditionClass}">${sample.sample_condition}</td></tr>`;
    }
    if (sample.quality_check !== null) {
        const qualityText = sample.quality_check == 1 ? 'Passed' : 'Failed';
        const qualityClass = sample.quality_check == 1 ? 'text-success' : 'text-danger';
        html += `<tr><th>Quality Check:</th><td class="${qualityClass}">${qualityText}</td></tr>`;
    }
    
    html += `           </table>
                            </div>
                        </div>
                    </div>
                </div>
                
                ${sample.collection_notes ? `
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h6 class="mb-0"><i class="fas fa-sticky-note mr-2"></i>Collection Notes</h6>
                    </div>
                    <div class="card-body">
                        <p class="mb-0">${sample.collection_notes.replace(/\n/g, '<br>')}</p>
                    </div>
                </div>
                ` : ''}
            </div>
        </div>
    `;
    
    modalContent.innerHTML = html;
    
    // Store sample code for printing
    modalContent.dataset.sampleCode = sample.sample_code;
    
    $('#sampleDetailsModal').modal('show');
}

function prepareRejectModal(sampleId) {
    $('#reject_sample_id').val(sampleId);
}

function prepareReceiveModal(sampleId) {
    $('#receive_sample_id').val(sampleId);
}

function prepareQualityModal(sampleId) {
    $('#quality_sample_id').val(sampleId);
}

function printSampleLabel(sampleCode) {
    // Show loading
    $('#printPreview').html(`
        <div class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="sr-only">Loading...</span>
            </div>
            <p class="mt-3">Generating label for ${sampleCode}...</p>
        </div>
    `);
    
    $('#printModal').modal('show');
    
    // Fetch sample details and generate label
    setTimeout(() => {
        generateLabelPreview(sampleCode);
    }, 500);
}

function printLabel() {
    // Show print modal with form data
    const formData = {
        patientName: '<?php echo htmlspecialchars($full_name); ?>',
        patientMRN: '<?php echo htmlspecialchars($patient_info['patient_mrn']); ?>',
        testName: $('#test_name_display').val() || 'Test Sample',
        specimenType: $('#specimen_type').val(),
        collectionDate: $('#collection_date').val(),
        collectionTime: $('#collection_time').val(),
        collectedBy: '<?php echo $_SESSION['user_name'] ?? "Nurse"; ?>'
    };
    
    generateLabelPreview(null, formData);
    $('#printModal').modal('show');
}

function generateLabelPreview(sampleCode = null, formData = null) {
    let labelHtml = '';
    
    if (sampleCode) {
        // Generate label for existing sample
        labelHtml = `
            <div class="label-preview border p-3" style="width: 3.5in; height: 2in; margin: 0 auto;">
                <div class="text-center mb-2">
                    <h5 class="mb-1"><strong>LAB SAMPLE</strong></h5>
                    <small class="text-muted">Hospital Laboratory</small>
                </div>
                <hr class="my-2">
                <div class="row">
                    <div class="col-6">
                        <small class="text-muted d-block">Sample Code:</small>
                        <h4 class="text-primary"><strong>${sampleCode}</strong></h4>
                    </div>
                    <div class="col-6 text-right">
                        <small class="text-muted d-block">Date/Time:</small>
                        <div>${new Date().toLocaleDateString()}</div>
                        <div>${new Date().toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</div>
                    </div>
                </div>
                <hr class="my-2">
                <div class="row">
                    <div class="col-12">
                        <small class="text-muted d-block">Patient:</small>
                        <div><strong><?php echo htmlspecialchars($full_name); ?></strong></div>
                        <small>MRN: <?php echo htmlspecialchars($patient_info['patient_mrn']); ?></small>
                    </div>
                </div>
                <div class="text-center mt-2">
                    <small class="text-muted">Handle with care | Store appropriately</small>
                </div>
            </div>
            
            <div class="mt-4">
                <h6><i class="fas fa-qrcode mr-2"></i>Barcode / QR Code</h6>
                <div class="text-center p-3 bg-light">
                    <div id="barcodeContainer"></div>
                    <small class="text-muted">Scan to verify sample details</small>
                </div>
            </div>
        `;
    } else if (formData) {
        // Generate label preview from form data
        labelHtml = `
            <div class="label-preview border p-3" style="width: 3.5in; height: 2in; margin: 0 auto;">
                <div class="text-center mb-2">
                    <h5 class="mb-1"><strong>LAB SAMPLE</strong></h5>
                    <small class="text-muted">Hospital Laboratory</small>
                </div>
                <hr class="my-2">
                <div class="row">
                    <div class="col-6">
                        <small class="text-muted d-block">Test:</small>
                        <h5><strong>${formData.testName}</strong></h5>
                    </div>
                    <div class="col-6 text-right">
                        <small class="text-muted d-block">Collection:</small>
                        <div>${formData.collectionDate}</div>
                        <div>${formData.collectionTime}</div>
                    </div>
                </div>
                <hr class="my-2">
                <div class="row">
                    <div class="col-12">
                        <small class="text-muted d-block">Patient:</small>
                        <div><strong>${formData.patientName}</strong></div>
                        <small>MRN: ${formData.patientMRN}</small>
                    </div>
                </div>
                <div class="row mt-2">
                    <div class="col-12">
                        <small class="text-muted d-block">Specimen:</small>
                        <div>${formData.specimenType}</div>
                    </div>
                </div>
                <div class="text-center mt-2">
                    <small class="text-muted">PRE-COLLECTION LABEL | Complete details after collection</small>
                </div>
            </div>
        `;
    }
    
    $('#printPreview').html(labelHtml);
    
    // Generate barcode if sampleCode exists
    if (sampleCode) {
        generateBarcode(sampleCode);
    }
}

function generateBarcode(sampleCode) {
    // This is a simple barcode simulation
    const barcodeContainer = document.getElementById('barcodeContainer');
    if (barcodeContainer) {
        barcodeContainer.innerHTML = `
            <div class="barcode-simulation" style="font-family: 'Libre Barcode 39', monospace; font-size: 48px; letter-spacing: 5px;">
                *${sampleCode}*
            </div>
            <div class="mt-2" style="font-family: monospace; font-size: 12px;">
                ${sampleCode}
            </div>
        `;
    }
}

function printFromModal() {
    const sampleCode = document.getElementById('sampleDetailsContent').dataset.sampleCode;
    if (sampleCode) {
        printSampleLabel(sampleCode);
        $('#sampleDetailsModal').modal('hide');
    }
}

function printLabelNow() {
    const printContent = document.getElementById('printPreview').innerHTML;
    const originalContent = document.body.innerHTML;
    
    document.body.innerHTML = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>Print Sample Label</title>
            <style>
                @media print {
                    @page { margin: 0; }
                    body { margin: 0.5in; }
                    .label-preview { border: 1px solid #000 !important; }
                }
                .label-preview {
                    width: 3.5in !important;
                    height: 2in !important;
                    padding: 10px !important;
                    font-family: Arial, sans-serif !important;
                }
                .barcode-simulation {
                    font-family: 'Courier New', monospace !important;
                    font-size: 36px !important;
                    letter-spacing: 3px !important;
                }
            </style>
        </head>
        <body onload="window.print(); window.close();">
            ${printContent}
        </body>
        </html>
    `;
    
    window.print();
    document.body.innerHTML = originalContent;
    $('#printModal').modal('hide');
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
    // Ctrl + S for save sample
    if (e.ctrlKey && e.keyCode === 83) {
        e.preventDefault();
        if ($('#sampleForm').valid()) {
            $('#sampleForm').submit();
        }
    }
    // Ctrl + P for print
    if (e.ctrlKey && e.keyCode === 80) {
        e.preventDefault();
        printLabel();
    }
    // Ctrl + N for new sample (clear form)
    if (e.ctrlKey && e.keyCode === 78) {
        e.preventDefault();
        $('#sampleForm')[0].reset();
        $('#collection_date').val('<?php echo date('Y-m-d'); ?>');
        $('#collection_time').val('<?php echo date('H:i'); ?>');
        $('#testInfo').hide();
        $('#lab_order_test_id').val('').trigger('change');
    }
    // Escape to go back
    if (e.keyCode === 27) {
        window.history.back();
    }
});
</script>

<style>
/* Custom styles for sample collection */
.label-preview {
    font-family: 'Courier New', monospace;
    background-color: white;
    border: 2px dashed #ccc;
}
.barcode-simulation {
    font-family: 'Libre Barcode 39', cursive;
    font-size: 48px;
    letter-spacing: 5px;
}

/* Print styles */
@media print {
    .card-header, .card-tools, .btn, form, .modal,
    .toast-container {
        display: none !important;
    }
    .card {
        border: none !important;
        box-shadow: none !important;
    }
    .card-body {
        padding: 0 !important;
    }
    .label-preview {
        border: 1px solid #000 !important;
        page-break-inside: avoid;
    }
}

/* Libre Barcode font for barcode simulation */
@import url('https://fonts.googleapis.com/css2?family=Libre+Barcode+39&display=swap');
</style>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
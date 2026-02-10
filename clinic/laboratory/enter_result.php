<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/audit_functions.php'; // Added for logging
    
$order_test_id = intval($_GET['order_test_id']);

// AUDIT LOG: Access attempt for entering test result
audit_log($mysqli, [
    'user_id'     => $_SESSION['user_id'] ?? null,
    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
    'action'      => 'ACCESS',
    'module'      => 'Lab Results',
    'table_name'  => 'lab_order_tests',
    'entity_type' => 'lab_order_test',
    'record_id'   => $order_test_id,
    'patient_id'  => null,
    'visit_id'    => null,
    'description' => "Attempting to access enter_result.php for order test ID: " . $order_test_id,
    'status'      => 'ATTEMPT',
    'old_values'  => null,
    'new_values'  => null
]);

// Get order test details
$order_test = $mysqli->query("
    SELECT lot.*, lt.test_name, lt.test_code, lt.specimen_type, lt.reference_range, lt.method, lt.category_id,
           lo.order_number, lo.lab_order_id, p.first_name as patient_first_name, p.last_name as patient_last_name, p.patient_mrn, p.sex as patient_gender, p.date_of_birth as patient_dob,
           u.user_name as doctor_name
    FROM lab_order_tests lot
    JOIN lab_tests lt ON lot.test_id = lt.test_id
    JOIN lab_orders lo ON lot.lab_order_id = lo.lab_order_id
    LEFT JOIN patients p ON lo.lab_order_patient_id = p.patient_id
    LEFT JOIN users u ON lo.ordering_doctor_id = u.user_id
    WHERE lot.lab_order_test_id = $order_test_id
")->fetch_assoc();

if (!$order_test) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Order test not found.";
    
    // AUDIT LOG: Order test not found
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'ACCESS',
        'module'      => 'Lab Results',
        'table_name'  => 'lab_order_tests',
        'entity_type' => 'lab_order_test',
        'record_id'   => $order_test_id,
        'patient_id'  => null,
        'visit_id'    => null,
        'description' => "Order test ID " . $order_test_id . " not found",
        'status'      => 'FAILED',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    header("Location: lab_orders.php");
    exit;
}

// AUDIT LOG: Successful access to enter result page
audit_log($mysqli, [
    'user_id'     => $_SESSION['user_id'] ?? null,
    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
    'action'      => 'VIEW',
    'module'      => 'Lab Results',
    'table_name'  => 'lab_order_tests',
    'entity_type' => 'lab_order_test',
    'record_id'   => $order_test_id,
    'patient_id'  => null,
    'visit_id'    => null,
    'description' => "Accessed enter_result.php for test: " . $order_test['test_name'] . " (" . $order_test['test_code'] . "). Patient: " . $order_test['patient_first_name'] . " " . $order_test['patient_last_name'],
    'status'      => 'SUCCESS',
    'old_values'  => null,
    'new_values'  => null
]);

// Check if result already exists
$existing_result = $mysqli->query("
    SELECT * FROM lab_results 
    WHERE lab_order_test_id = $order_test_id 
    ORDER BY created_at DESC 
    LIMIT 1
")->fetch_assoc();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    $result_value = sanitizeInput($_POST['result_value']);
    $result_unit = sanitizeInput($_POST['result_unit']);
    $abnormal_flag = sanitizeInput($_POST['abnormal_flag']);
    $result_status = sanitizeInput($_POST['result_status']);
    $performed_by = intval($_POST['performed_by']);
    $result_notes = sanitizeInput($_POST['result_notes']);
    $instrument_used = sanitizeInput($_POST['instrument_used']);
    $reagent_lot = sanitizeInput($_POST['reagent_lot']);
    $qc_passed = isset($_POST['qc_passed']) ? 1 : 0;
    $result_verified = isset($_POST['result_verified']) ? 1 : 0;

    // Handle reference ranges
    $reference_ranges = [];
    if (isset($_POST['reference_range_min']) && isset($_POST['reference_range_max']) && isset($_POST['reference_range_unit'])) {
        $min_values = $_POST['reference_range_min'];
        $max_values = $_POST['reference_range_max'];
        $units = $_POST['reference_range_unit'];
        $conditions = $_POST['reference_range_condition'] ?? [];
        
        for ($i = 0; $i < count($min_values); $i++) {
            if (!empty($min_values[$i]) && !empty($max_values[$i]) && !empty($units[$i])) {
                $range = [
                    'min' => $min_values[$i],
                    'max' => $max_values[$i],
                    'unit' => $units[$i],
                    'condition' => $conditions[$i] ?? ''
                ];
                $reference_ranges[] = $range;
            }
        }
    }

    // Validate CSRF token
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        
        // AUDIT LOG: Invalid CSRF token
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'VALIDATION_FAILED',
            'module'      => 'Lab Results',
            'table_name'  => 'lab_results',
            'entity_type' => 'lab_result',
            'record_id'   => $existing_result['result_id'] ?? null,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Invalid CSRF token when attempting to " . ($existing_result ? "update" : "enter") . " result for test: " . $order_test['test_name'],
            'status'      => 'FAILED',
            'old_values'  => $existing_result ? json_encode($existing_result) : null,
            'new_values'  => null
        ]);
        
        header("Location: enter_result.php?order_test_id=" . $order_test_id);
        exit;
    }

    // Handle date/time input
    $formatted_result_date = '';
    if (isset($_POST['result_datetime'])) {
        $result_datetime = sanitizeInput($_POST['result_datetime']);
        if (!empty($result_datetime)) {
            $formatted_result_date = str_replace('T', ' ', $result_datetime) . ':00';
        }
    }

    // Prepare result data for audit log
    $result_data = [
        'result_value' => $result_value,
        'result_unit' => $result_unit,
        'abnormal_flag' => $abnormal_flag,
        'result_status' => $result_status,
        'result_date' => $formatted_result_date,
        'performed_by' => $performed_by,
        'result_notes' => $result_notes,
        'instrument_used' => $instrument_used,
        'reagent_lot' => $reagent_lot,
        'qc_passed' => $qc_passed,
        'result_verified' => $result_verified,
        'reference_ranges' => $reference_ranges
    ];

    // AUDIT LOG: Attempt to enter/update test result
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => $existing_result ? 'RESULT_UPDATE' : 'RESULT_CREATE',
        'module'      => 'Lab Results',
        'table_name'  => 'lab_results',
        'entity_type' => 'lab_result',
        'record_id'   => $existing_result['result_id'] ?? null,
        'patient_id'  => null,
        'visit_id'    => null,
        'description' => "Attempting to " . ($existing_result ? "update" : "enter") . " result for test: " . $order_test['test_name'] . " (" . $order_test['test_code'] . ")",
        'status'      => 'ATTEMPT',
        'old_values'  => $existing_result ? json_encode($existing_result) : null,
        'new_values'  => json_encode($result_data)
    ]);

    // Validate required fields including date/time
    if (empty($result_value) || empty($formatted_result_date) || !$qc_passed) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Result value, date/time, and quality control confirmation are required.";
        
        // AUDIT LOG: Validation failed - missing required fields
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'VALIDATION_FAILED',
            'module'      => 'Lab Results',
            'table_name'  => 'lab_results',
            'entity_type' => 'lab_result',
            'record_id'   => $existing_result['result_id'] ?? null,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Validation failed: Missing required fields when " . ($existing_result ? "updating" : "entering") . " result for test: " . $order_test['test_name'],
            'status'      => 'FAILED',
            'old_values'  => $existing_result ? json_encode($existing_result) : null,
            'new_values'  => json_encode($result_data)
        ]);
        
        header("Location: enter_result.php?order_test_id=" . $order_test_id);
        exit;
    }

    // Validate datetime format for MySQL
    $result_datetime_obj = DateTime::createFromFormat('Y-m-d H:i:s', $formatted_result_date);
    if (!$result_datetime_obj) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid date/time format. Please use the correct format.";
        
        // AUDIT LOG: Validation failed - invalid datetime format
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'VALIDATION_FAILED',
            'module'      => 'Lab Results',
            'table_name'  => 'lab_results',
            'entity_type' => 'lab_result',
            'record_id'   => $existing_result['result_id'] ?? null,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Validation failed: Invalid date/time format when " . ($existing_result ? "updating" : "entering") . " result for test: " . $order_test['test_name'],
            'status'      => 'FAILED',
            'old_values'  => $existing_result ? json_encode($existing_result) : null,
            'new_values'  => json_encode($result_data)
        ]);
        
        header("Location: enter_result.php?order_test_id=" . $order_test_id);
        exit;
    }

    $formatted_result_date = $result_datetime_obj->format('Y-m-d H:i:s');

    // Validate result date is not in the future
    $now = new DateTime();
    if ($result_datetime_obj > $now) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Result date cannot be in the future.";
        
        // AUDIT LOG: Validation failed - future date
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'VALIDATION_FAILED',
            'module'      => 'Lab Results',
            'table_name'  => 'lab_results',
            'entity_type' => 'lab_result',
            'record_id'   => $existing_result['result_id'] ?? null,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Validation failed: Future date when " . ($existing_result ? "updating" : "entering") . " result for test: " . $order_test['test_name'],
            'status'      => 'FAILED',
            'old_values'  => $existing_result ? json_encode($existing_result) : null,
            'new_values'  => json_encode($result_data)
        ]);
        
        header("Location: enter_result.php?order_test_id=" . $order_test_id);
        exit;
    }

    // Validate numerical results if applicable
    if (is_numeric($result_value)) {
        $num_value = floatval($result_value);
        if ($num_value < 0) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Result value cannot be negative.";
            
            // AUDIT LOG: Validation failed - negative value
            audit_log($mysqli, [
                'user_id'     => $_SESSION['user_id'] ?? null,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'VALIDATION_FAILED',
                'module'      => 'Lab Results',
                'table_name'  => 'lab_results',
                'entity_type' => 'lab_result',
                'record_id'   => $existing_result['result_id'] ?? null,
                'patient_id'  => null,
                'visit_id'    => null,
                'description' => "Validation failed: Negative value when " . ($existing_result ? "updating" : "entering") . " result for test: " . $order_test['test_name'],
                'status'      => 'FAILED',
                'old_values'  => $existing_result ? json_encode($existing_result) : null,
                'new_values'  => json_encode($result_data)
            ]);
            
            header("Location: enter_result.php?order_test_id=" . $order_test_id);
            exit;
        }
    }

    // Start transaction
    $mysqli->begin_transaction();

    try {
        // Insert or update result in lab_results table
        if ($existing_result) {
            // Update existing result
            $result_sql = "UPDATE lab_results SET 
                          result_value = ?,
                          result_unit = ?,
                          abnormal_flag = ?,
                          result_status = ?,
                          result_date = ?,
                          performed_by = ?,
                          result_notes = ?,
                          instrument_used = ?,
                          reagent_lot = ?,
                          qc_passed = ?,
                          reference_ranges = ?,
                          updated_at = NOW()
                          WHERE result_id = ?";
            
            $result_stmt = $mysqli->prepare($result_sql);
            $reference_ranges_json = json_encode($reference_ranges);
            $result_stmt->bind_param(
                "sssssisssisi",
                $result_value,
                $result_unit,
                $abnormal_flag,
                $result_status,
                $formatted_result_date,
                $performed_by,
                $result_notes,
                $instrument_used,
                $reagent_lot,
                $qc_passed,
                $reference_ranges_json,
                $existing_result['result_id']
            );
        } else {
            // Insert new result
            $result_sql = "INSERT INTO lab_results SET 
                          lab_order_test_id = ?,
                          result_value = ?,
                          result_unit = ?,
                          abnormal_flag = ?,
                          result_status = ?,
                          result_date = ?,
                          performed_by = ?,
                          result_notes = ?,
                          instrument_used = ?,
                          reagent_lot = ?,
                          qc_passed = ?,
                          reference_ranges = ?,
                          created_at = NOW(),
                          updated_at = NOW()";
            
            $result_stmt = $mysqli->prepare($result_sql);
            $reference_ranges_json = json_encode($reference_ranges);
            $result_stmt->bind_param(
                "issssssssiis",
                $order_test_id,
                $result_value,
                $result_unit,
                $abnormal_flag,
                $result_status,
                $formatted_result_date,
                $performed_by,
                $result_notes,
                $instrument_used,
                $reagent_lot,
                $qc_passed,
                $reference_ranges_json
            );
        }

        if (!$result_stmt->execute()) {
            throw new Exception("Error saving test result: " . $mysqli->error);
        }

        $result_id = $existing_result ? $existing_result['result_id'] : $result_stmt->insert_id;

        // Update order test status to 'completed'
        $update_test_sql = "UPDATE lab_order_tests SET 
                           status = 'completed',
                           updated_at = NOW()
                           WHERE lab_order_test_id = ?";
        
        $update_test_stmt = $mysqli->prepare($update_test_sql);
        $update_test_stmt->bind_param("i", $order_test_id);

        if (!$update_test_stmt->execute()) {
            throw new Exception("Error updating test status: " . $mysqli->error);
        }

        // Update lab order status if all tests are completed
        $pending_tests_sql = "SELECT COUNT(*) as pending_count 
                             FROM lab_order_tests 
                             WHERE lab_order_id = ? AND status != 'completed'";
        $pending_stmt = $mysqli->prepare($pending_tests_sql);
        $pending_stmt->bind_param("i", $order_test['lab_order_id']);
        $pending_stmt->execute();
        $pending_result = $pending_stmt->get_result()->fetch_assoc();

        if ($pending_result['pending_count'] == 0) {
            $update_order_sql = "UPDATE lab_orders SET 
                                lab_order_status = 'completed',
                                updated_at = NOW()
                                WHERE lab_order_id = ?";
            
            $update_order_stmt = $mysqli->prepare($update_order_sql);
            $update_order_stmt->bind_param("i", $order_test['lab_order_id']);
            
            if (!$update_order_stmt->execute()) {
                throw new Exception("Error updating order status: " . $mysqli->error);
            }
        }

        // Log the activity
        $activity_sql = "INSERT INTO lab_activities SET 
                        test_id = ?,
                        activity_type = 'result_entered',
                        activity_description = ?,
                        performed_by = ?,
                        activity_date = NOW()";
        
        $activity_desc = "Result entered for test: " . $order_test['test_name'] . " (" . $order_test['test_code'] . ") - Result: " . $result_value . " " . $result_unit;
        $activity_stmt = $mysqli->prepare($activity_sql);
        $activity_stmt->bind_param("isi", $order_test_id, $activity_desc, $session_user_id);
        $activity_stmt->execute();

        // Log result history if updating existing result
        if ($existing_result) {
            $history_sql = "INSERT INTO lab_result_history SET 
                           result_id = ?,
                           old_result_value = ?,
                           new_result_value = ?,
                           change_reason = ?,
                           changed_by = ?,
                           changed_at = NOW()";
            
            $change_reason = "Result updated via result entry form";
            $history_stmt = $mysqli->prepare($history_sql);
            $history_stmt->bind_param(
                "isssi",
                $result_id,
                $existing_result['result_value'],
                $result_value,
                $change_reason,
                $session_user_id
            );
            $history_stmt->execute();
        }

        // Commit transaction
        $mysqli->commit();

        // AUDIT LOG: Successful result entry/update
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => $existing_result ? 'RESULT_UPDATE' : 'RESULT_CREATE',
            'module'      => 'Lab Results',
            'table_name'  => 'lab_results',
            'entity_type' => 'lab_result',
            'record_id'   => $result_id,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Test result " . ($existing_result ? "updated" : "entered") . " successfully for test: " . $order_test['test_name'] . " (" . $order_test['test_code'] . ") - Result: " . $result_value . " " . $result_unit,
            'status'      => 'SUCCESS',
            'old_values'  => $existing_result ? json_encode($existing_result) : null,
            'new_values'  => json_encode(array_merge($result_data, [
                'result_id' => $result_id,
                'lab_order_test_id' => $order_test_id,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]))
        ]);

        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Test result " . ($existing_result ? "updated" : "entered") . " successfully!";
        header("Location: lab_order_details.php?id=" . $order_test['lab_order_id']);
        exit;

    } catch (Exception $e) {
        $mysqli->rollback();
        
        // AUDIT LOG: Failed result entry/update
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => $existing_result ? 'RESULT_UPDATE' : 'RESULT_CREATE',
            'module'      => 'Lab Results',
            'table_name'  => 'lab_results',
            'entity_type' => 'lab_result',
            'record_id'   => $existing_result['result_id'] ?? null,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Error " . ($existing_result ? "updating" : "entering") . " test result for test: " . $order_test['test_name'] . ". Error: " . $e->getMessage(),
            'status'      => 'FAILED',
            'old_values'  => $existing_result ? json_encode($existing_result) : null,
            'new_values'  => json_encode($result_data)
        ]);

        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error " . ($existing_result ? "updating" : "entering") . " test result: " . $e->getMessage();
        header("Location: enter_result.php?order_test_id=" . $order_test_id);
        exit;
    }
}

// Get technicians for performed_by dropdown
$technicians = $mysqli->query("
    SELECT user_id, user_name 
    FROM users 
");

// Get existing reference ranges if any
$existing_ranges = [];
if ($existing_result && !empty($existing_result['reference_ranges'])) {
    $existing_ranges = json_decode($existing_result['reference_ranges'], true) ?: [];
}

// If no existing ranges, create one default empty range
if (empty($existing_ranges)) {
    $existing_ranges = [
        ['min' => '', 'max' => '', 'unit' => '', 'condition' => '']
    ];
}
?>
<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0">
            <i class="fas fa-fw fa-edit mr-2"></i>
            <?php echo $existing_result ? 'Update Test Result' : 'Enter Test Result'; ?>
        </h3>
        <div class="card-tools">
            <a href="lab_order_details.php?id=<?php echo $order_test['lab_order_id']; ?>" class="btn btn-light">
                <i class="fas fa-arrow-left mr-2"></i>Back to Order
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

        <?php if ($existing_result): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle mr-2"></i>
                A result already exists for this test. You are updating the existing result.
            </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-md-8">
                <form method="POST" id="enterResultForm" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    
                    <!-- Test Information -->
                    <div class="card card-primary mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-info-circle mr-2"></i>Test Information</h4>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="text-muted">Test Name</label>
                                        <p class="form-control-plaintext font-weight-bold"><?php echo htmlspecialchars($order_test['test_name']); ?></p>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="text-muted">Test Code</label>
                                        <p class="form-control-plaintext font-weight-bold"><?php echo htmlspecialchars($order_test['test_code']); ?></p>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="text-muted">Specimen Type</label>
                                        <p class="form-control-plaintext"><?php echo htmlspecialchars($order_test['specimen_type'] ?: 'Not specified'); ?></p>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="text-muted">Method</label>
                                        <p class="form-control-plaintext"><?php echo htmlspecialchars($order_test['method'] ?: 'Standard method'); ?></p>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="text-muted">Order Number</label>
                                        <p class="form-control-plaintext font-weight-bold"><?php echo htmlspecialchars($order_test['order_number']); ?></p>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="text-muted">Patient</label>
                                        <p class="form-control-plaintext"><?php echo htmlspecialchars($order_test['patient_first_name'] . ' ' . $order_test['patient_last_name']); ?> (MRN: <?php echo htmlspecialchars($order_test['patient_mrn']); ?>)</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Result Entry -->
                    <div class="card card-info mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-flask mr-2"></i>Test Result</h4>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="resultValue">Result Value <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="resultValue" name="result_value" 
                                               value="<?php echo $existing_result ? htmlspecialchars($existing_result['result_value']) : ''; ?>"
                                               placeholder="Enter test result" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="resultUnit">Unit</label>
                                        <input type="text" class="form-control" id="resultUnit" name="result_unit" 
                                               value="<?php echo $existing_result ? htmlspecialchars($existing_result['result_unit']) : ''; ?>"
                                               placeholder="e.g., mg/dL, U/L">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="abnormalFlag">Abnormal Flag</label>
                                        <select class="form-control" id="abnormalFlag" name="abnormal_flag">
                                            <option value="normal" <?php echo ($existing_result && $existing_result['abnormal_flag'] == 'normal') ? 'selected' : ''; ?>>Normal</option>
                                            <option value="low" <?php echo ($existing_result && $existing_result['abnormal_flag'] == 'low') ? 'selected' : ''; ?>>Low</option>
                                            <option value="high" <?php echo ($existing_result && $existing_result['abnormal_flag'] == 'high') ? 'selected' : ''; ?>>High</option>
                                            <option value="critical_low" <?php echo ($existing_result && $existing_result['abnormal_flag'] == 'critical_low') ? 'selected' : ''; ?>>Critical Low</option>
                                            <option value="critical_high" <?php echo ($existing_result && $existing_result['abnormal_flag'] == 'critical_high') ? 'selected' : ''; ?>>Critical High</option>
                                            <option value="abnormal" <?php echo ($existing_result && $existing_result['abnormal_flag'] == 'abnormal') ? 'selected' : ''; ?>>Abnormal</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="resultStatus">Result Status</label>
                                        <select class="form-control" id="resultStatus" name="result_status" required>
                                            <option value="preliminary" <?php echo ($existing_result && $existing_result['result_status'] == 'preliminary') ? 'selected' : ''; ?>>Preliminary</option>
                                            <option value="final" <?php echo (!$existing_result || $existing_result['result_status'] == 'final') ? 'selected' : ''; ?>>Final</option>
                                            <option value="corrected" <?php echo ($existing_result && $existing_result['result_status'] == 'corrected') ? 'selected' : ''; ?>>Corrected</option>
                                            <option value="amended" <?php echo ($existing_result && $existing_result['result_status'] == 'amended') ? 'selected' : ''; ?>>Amended</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="resultDatetime">Result Date & Time <span class="text-danger">*</span></label>
                                        <input type="datetime-local" class="form-control" id="resultDatetime" 
                                               name="result_datetime" 
                                               value="<?php 
                                                   if ($existing_result) {
                                                       echo htmlspecialchars(date('Y-m-d\TH:i', strtotime($existing_result['result_date'])));
                                                   } else {
                                                       echo htmlspecialchars(date('Y-m-d\TH:i'));
                                                   }
                                               ?>" 
                                               required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="performedBy">Performed By <span class="text-danger">*</span></label>
                                        <select class="form-control select2" id="performedBy" name="performed_by" required>
                                            <option value="">- Select Technician -</option>
                                            <?php while($tech = $technicians->fetch_assoc()): ?>
                                                <option value="<?php echo $tech['user_id']; ?>" 
                                                    <?php echo ($existing_result && $existing_result['performed_by'] == $tech['user_id']) || (!$existing_result && $tech['user_id'] == $session_user_id) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($tech['user_name']); ?>
                                                </option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-12">
                                    <div class="form-group">
                                        <label for="resultNotes">Result Notes</label>
                                        <textarea class="form-control" id="resultNotes" name="result_notes" 
                                                  rows="2" placeholder="Any additional comments about the result..."><?php echo $existing_result ? htmlspecialchars($existing_result['result_notes']) : ''; ?></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Reference Ranges -->
                    <div class="card card-warning mb-4">
                        <div class="card-header bg-light py-2 d-flex justify-content-between align-items-center">
                            <h4 class="card-title mb-0"><i class="fas fa-ruler mr-2"></i>Reference Ranges</h4>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="addReferenceRange()">
                                <i class="fas fa-plus mr-1"></i>Add Range
                            </button>
                        </div>
                        <div class="card-body">
                            <div id="referenceRangesContainer">
                                <?php foreach ($existing_ranges as $index => $range): ?>
                                <div class="reference-range-item border-bottom pb-3 mb-3">
                                    <div class="row">
                                        <div class="col-md-3">
                                            <div class="form-group">
                                                <label>Minimum Value</label>
                                                <input type="text" class="form-control" name="reference_range_min[]" 
                                                       value="<?php echo htmlspecialchars($range['min']); ?>" 
                                                       placeholder="0.0">
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-group">
                                                <label>Maximum Value</label>
                                                <input type="text" class="form-control" name="reference_range_max[]" 
                                                       value="<?php echo htmlspecialchars($range['max']); ?>" 
                                                       placeholder="100.0">
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-group">
                                                <label>Unit</label>
                                                <input type="text" class="form-control" name="reference_range_unit[]" 
                                                       value="<?php echo htmlspecialchars($range['unit']); ?>" 
                                                       placeholder="mg/dL">
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="form-group">
                                                <label>Condition</label>
                                                <input type="text" class="form-control" name="reference_range_condition[]" 
                                                       value="<?php echo htmlspecialchars($range['condition']); ?>" 
                                                       placeholder="e.g., Adult Male">
                                            </div>
                                        </div>
                                        <div class="col-md-1">
                                            <div class="form-group">
                                                <label>&nbsp;</label>
                                                <button type="button" class="btn btn-sm btn-outline-danger btn-block" onclick="removeReferenceRange(this)">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="text-muted small">
                                <i class="fas fa-info-circle mr-1"></i>
                                Add multiple reference ranges for different conditions (e.g., age groups, genders)
                            </div>
                        </div>
                    </div>

                    <!-- Quality Control -->
                    <div class="card card-warning mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-check-circle mr-2"></i>Quality Control</h4>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="instrumentUsed">Instrument Used</label>
                                        <input type="text" class="form-control" id="instrumentUsed" name="instrument_used" 
                                               value="<?php echo $existing_result ? htmlspecialchars($existing_result['instrument_used']) : ''; ?>"
                                               placeholder="e.g., Analyzer Model XYZ">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="reagentLot">Reagent Lot Number</label>
                                        <input type="text" class="form-control" id="reagentLot" name="reagent_lot" 
                                               value="<?php echo $existing_result ? htmlspecialchars($existing_result['reagent_lot']) : ''; ?>"
                                               placeholder="Reagent batch number">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-check mb-2">
                                <input type="checkbox" class="form-check-input" id="qcPassed" name="qc_passed" value="1" 
                                    <?php echo $existing_result && $existing_result['qc_passed'] ? 'checked' : ''; ?> required>
                                <label class="form-check-label" for="qcPassed">
                                    Quality control passed for this test run
                                </label>
                            </div>
                            
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="resultVerified" name="result_verified" value="1"
                                    <?php echo $existing_result && $existing_result['verified_by'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="resultVerified">
                                    I verify this result is accurate and complete
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Form Actions -->
                    <div class="row mt-4">
                        <div class="col-md-12">
                            <div class="btn-toolbar justify-content-between">
                                <a href="lab_order_details.php?id=<?php echo $order_test['lab_order_id']; ?>" class="btn btn-secondary">
                                    <i class="fas fa-times mr-2"></i>Cancel
                                </a>
                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-save mr-2"></i><?php echo $existing_result ? 'Update Result' : 'Save Result'; ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <div class="col-md-4">
                <!-- Quick Actions -->
                <div class="card card-success mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-bolt mr-2"></i>Quick Actions</h4>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <button type="button" class="btn btn-outline-primary" onclick="setCurrentDateTime()">
                                <i class="fas fa-clock mr-2"></i>Set Current Date/Time
                            </button>
                            <button type="button" class="btn btn-outline-info" onclick="autoDetectAbnormalFlag()">
                                <i class="fas fa-magic mr-2"></i>Auto-detect Abnormal Flag
                            </button>
                            <button type="button" class="btn btn-outline-warning" onclick="loadCommonRanges()">
                                <i class="fas fa-database mr-2"></i>Load Common Ranges
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Quick Patterns -->
                <div class="card card-warning mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-chart-line mr-2"></i>Quick Patterns</h4>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <button type="button" class="btn btn-outline-success btn-sm" onclick="loadCommonPattern('normal_range')">
                                <i class="fas fa-check-circle mr-2"></i>Normal Range
                            </button>
                            <button type="button" class="btn btn-outline-warning btn-sm" onclick="loadCommonPattern('slightly_elevated')">
                                <i class="fas fa-arrow-up mr-2"></i>Slightly Elevated
                            </button>
                            <button type="button" class="btn btn-outline-warning btn-sm" onclick="loadCommonPattern('slightly_low')">
                                <i class="fas fa-arrow-down mr-2"></i>Slightly Low
                            </button>
                            <button type="button" class="btn btn-outline-danger btn-sm" onclick="loadCommonPattern('critical_high')">
                                <i class="fas fa-exclamation-triangle mr-2"></i>Critical High
                            </button>
                            <button type="button" class="btn btn-outline-danger btn-sm" onclick="loadCommonPattern('critical_low')">
                                <i class="fas fa-exclamation-triangle mr-2"></i>Critical Low
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Result Guidelines -->
                <div class="card card-info">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-book mr-2"></i>Result Guidelines</h4>
                    </div>
                    <div class="card-body">
                        <ul class="list-unstyled small">
                            <li class="mb-2">
                                <i class="fas fa-check text-success mr-1"></i>
                                Verify instrument calibration before use
                            </li>
                            <li class="mb-2">
                                <i class="fas fa-check text-success mr-1"></i>
                                Document all quality control results
                            </li>
                            <li class="mb-2">
                                <i class="fas fa-check text-success mr-1"></i>
                                Report critical values immediately
                            </li>
                            <li class="mb-2">
                                <i class="fas fa-check text-success mr-1"></i>
                                Follow established reference ranges
                            </li>
                            <li>
                                <i class="fas fa-check text-success mr-1"></i>
                                Document any result modifications
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- Existing Result Info -->
                <?php if ($existing_result): ?>
                <div class="card card-warning">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-history mr-2"></i>Existing Result</h4>
                    </div>
                    <div class="card-body">
                        <div class="small">
                            <p><strong>Last Updated:</strong><br>
                            <?php echo date('M j, Y H:i', strtotime($existing_result['updated_at'])); ?></p>
                            <p><strong>Current Status:</strong><br>
                            <span class="badge badge-info"><?php echo ucfirst($existing_result['result_status']); ?></span></p>
                            <?php if ($existing_result['verified_by']): ?>
                                <p class="text-success">
                                    <i class="fas fa-check-circle mr-1"></i>Result is verified
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Auto-detect abnormal flag based on result value and reference ranges
    function setupAbnormalFlagDetection() {
        $('#resultValue').on('input', function() {
            var resultValue = parseFloat($(this).val());
            if (isNaN(resultValue)) return;
            
            // Check against all reference ranges
            let isNormal = false;
            $('.reference-range-item').each(function() {
                const min = parseFloat($(this).find('input[name="reference_range_min[]"]').val());
                const max = parseFloat($(this).find('input[name="reference_range_max[]"]').val());
                
                if (!isNaN(min) && !isNaN(max)) {
                    if (resultValue >= min && resultValue <= max) {
                        isNormal = true;
                    }
                }
            });
            
            if (isNormal) {
                $('#abnormalFlag').val('normal');
            } else {
                // Check if it's high or low
                $('.reference-range-item').each(function() {
                    const min = parseFloat($(this).find('input[name="reference_range_min[]"]').val());
                    const max = parseFloat($(this).find('input[name="reference_range_max[]"]').val());
                    
                    if (!isNaN(min) && !isNaN(max)) {
                        if (resultValue < min) {
                            $('#abnormalFlag').val('low');
                        } else if (resultValue > max) {
                            $('#abnormalFlag').val('high');
                        }
                    }
                });
            }
        });
    }

    // Form validation
    $('#enterResultForm').submit(function(e) {
        const resultValue = $('#resultValue').val();
        const qcPassed = $('#qcPassed').is(':checked');
        const performedBy = $('#performedBy').val();
        const resultDatetime = $('#resultDatetime').val();
        
        if (!resultValue || !resultDatetime || !performedBy || !qcPassed) {
            e.preventDefault();
            alert('Please fill in all required fields and confirm quality control');
            return false;
        }
        
        // Show loading state
        $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Saving...').prop('disabled', true);
    });

    // Critical value warning
    $('#abnormalFlag').change(function() {
        const flag = $(this).val();
        if (flag === 'critical_low' || flag === 'critical_high') {
            alert('Critical value detected! Please ensure proper notification procedures are followed.');
        }
    });

    setupAbnormalFlagDetection();
});

// Reference Range Management
let rangeCounter = <?php echo count($existing_ranges); ?>;

function addReferenceRange() {
    rangeCounter++;
    const newRange = `
        <div class="reference-range-item border-bottom pb-3 mb-3">
            <div class="row">
                <div class="col-md-3">
                    <div class="form-group">
                        <label>Minimum Value</label>
                        <input type="text" class="form-control" name="reference_range_min[]" placeholder="0.0">
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label>Maximum Value</label>
                        <input type="text" class="form-control" name="reference_range_max[]" placeholder="100.0">
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label>Unit</label>
                        <input type="text" class="form-control" name="reference_range_unit[]" placeholder="mg/dL">
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-group">
                        <label>Condition</label>
                        <input type="text" class="form-control" name="reference_range_condition[]" placeholder="e.g., Adult Male">
                    </div>
                </div>
                <div class="col-md-1">
                    <div class="form-group">
                        <label>&nbsp;</label>
                        <button type="button" class="btn btn-sm btn-outline-danger btn-block" onclick="removeReferenceRange(this)">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;
    $('#referenceRangesContainer').append(newRange);
}

function removeReferenceRange(button) {
    if ($('.reference-range-item').length > 1) {
        $(button).closest('.reference-range-item').remove();
    } else {
        alert('At least one reference range is required.');
    }
}

function setCurrentDateTime() {
    const now = new Date();
    const localDateTime = now.toISOString().slice(0, 16);
    $('#resultDatetime').val(localDateTime);
}

function autoDetectAbnormalFlag() {
    const resultValue = parseFloat($('#resultValue').val());
    if (!isNaN(resultValue)) {
        $('#resultValue').trigger('input');
    } else {
        alert('Please enter a result value first');
    }
}

function loadCommonRanges() {
    const testCode = '<?php echo $order_test['test_code']; ?>'.toLowerCase();
    const testName = '<?php echo $order_test['test_name']; ?>'.toLowerCase();
    
    const commonRanges = {
        'glucose': [
            { min: '70', max: '99', unit: 'mg/dL', condition: 'Fasting' },
            { min: '140', max: '199', unit: 'mg/dL', condition: 'Postprandial' }
        ],
        'cholesterol': [
            { min: '0', max: '200', unit: 'mg/dL', condition: 'Desirable' },
            { min: '200', max: '239', unit: 'mg/dL', condition: 'Borderline High' },
            { min: '240', max: '999', unit: 'mg/dL', condition: 'High' }
        ],
        'hemoglobin': [
            { min: '13.5', max: '17.5', unit: 'g/dL', condition: 'Adult Male' },
            { min: '12.0', max: '15.5', unit: 'g/dL', condition: 'Adult Female' }
        ],
        'wbc': [
            { min: '4.5', max: '11.0', unit: 'x10³/μL', condition: 'Adult' }
        ],
        'sodium': [
            { min: '135', max: '145', unit: 'mmol/L', condition: 'Adult' }
        ],
        'potassium': [
            { min: '3.5', max: '5.1', unit: 'mmol/L', condition: 'Adult' }
        ]
    };
    
    let foundRanges = null;
    for (const [testKey, ranges] of Object.entries(commonRanges)) {
        if (testCode.includes(testKey) || testName.includes(testKey)) {
            foundRanges = ranges;
            break;
        }
    }
    
    if (foundRanges) {
        // Clear existing ranges
        $('#referenceRangesContainer').empty();
        
        // Add new ranges
        foundRanges.forEach(range => {
            const rangeHtml = `
                <div class="reference-range-item border-bottom pb-3 mb-3">
                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Minimum Value</label>
                                <input type="text" class="form-control" name="reference_range_min[]" value="${range.min}">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Maximum Value</label>
                                <input type="text" class="form-control" name="reference_range_max[]" value="${range.max}">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Unit</label>
                                <input type="text" class="form-control" name="reference_range_unit[]" value="${range.unit}">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-group">
                                <label>Condition</label>
                                <input type="text" class="form-control" name="reference_range_condition[]" value="${range.condition}">
                            </div>
                        </div>
                        <div class="col-md-1">
                            <div class="form-group">
                                <label>&nbsp;</label>
                                <button type="button" class="btn btn-sm btn-outline-danger btn-block" onclick="removeReferenceRange(this)">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            $('#referenceRangesContainer').append(rangeHtml);
        });
        
        showAlert('Common reference ranges loaded for ' + testName, 'success');
    } else {
        showAlert('No common ranges found for this test type.', 'info');
    }
}

function loadCommonPattern(pattern) {
    const testCode = '<?php echo $order_test['test_code']; ?>'.toLowerCase();
    const testName = '<?php echo $order_test['test_name']; ?>'.toLowerCase();
    
    // Test-specific pattern values
    const testPatterns = {
        'glucose': {
            normal_range: { value: '95', unit: 'mg/dL' },
            slightly_elevated: { value: '115', unit: 'mg/dL' },
            critical_high: { value: '450', unit: 'mg/dL' }
        },
        'cholesterol': {
            normal_range: { value: '180', unit: 'mg/dL' },
            slightly_elevated: { value: '220', unit: 'mg/dL' },
            critical_high: { value: '300', unit: 'mg/dL' }
        },
        'hemoglobin': {
            normal_range: { value: '14.5', unit: 'g/dL' },
            slightly_low: { value: '11.5', unit: 'g/dL' },
            critical_low: { value: '7.0', unit: 'g/dL' }
        }
    };
    
    let testSpecificPattern = null;
    for (const [testKey, patterns] of Object.entries(testPatterns)) {
        if (testCode.includes(testKey) || testName.includes(testKey)) {
            testSpecificPattern = patterns[pattern];
            break;
        }
    }
    
    const patterns = {
        'normal_range': {
            result_value: testSpecificPattern?.value || 'Within normal limits',
            result_unit: testSpecificPattern?.unit || '',
            abnormal_flag: 'normal',
            result_notes: 'Result falls within established reference range'
        },
        'slightly_elevated': {
            result_value: testSpecificPattern?.value || '',
            result_unit: testSpecificPattern?.unit || '',
            abnormal_flag: 'high',
            result_notes: 'Slightly elevated from reference range. Clinical correlation recommended.'
        },
        'slightly_low': {
            result_value: testSpecificPattern?.value || '',
            result_unit: testSpecificPattern?.unit || '',
            abnormal_flag: 'low',
            result_notes: 'Slightly below reference range. Clinical correlation recommended.'
        },
        'critical_high': {
            result_value: testSpecificPattern?.value || '',
            result_unit: testSpecificPattern?.unit || '',
            abnormal_flag: 'critical_high',
            result_notes: 'CRITICAL VALUE - Notified physician as per protocol.'
        },
        'critical_low': {
            result_value: testSpecificPattern?.value || '',
            result_unit: testSpecificPattern?.unit || '',
            abnormal_flag: 'critical_low',
            result_notes: 'CRITICAL VALUE - Notified physician as per protocol.'
        }
    };
    
    if (patterns[pattern]) {
        if (patterns[pattern].result_value) {
            $('#resultValue').val(patterns[pattern].result_value);
        }
        if (patterns[pattern].result_unit) {
            $('#resultUnit').val(patterns[pattern].result_unit);
        }
        $('#abnormalFlag').val(patterns[pattern].abnormal_flag);
        $('#resultNotes').val(patterns[pattern].result_notes);
        showAlert('Pattern applied successfully!', 'success');
    }
}

function showAlert(message, type) {
    const alertClass = type === 'success' ? 'alert-success' : 
                      type === 'info' ? 'alert-info' : 'alert-danger';
    const icon = type === 'success' ? 'check' : 
                type === 'info' ? 'info-circle' : 'exclamation-triangle';
    
    const alertHtml = `
        <div class="alert ${alertClass} alert-dismissible fade show" role="alert">
            <i class="fas fa-${icon} mr-2"></i>
            ${message}
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
    `;
    
    // Remove existing alerts and prepend new one
    $('.alert-dismissible').remove();
    $('#enterResultForm').prepend(alertHtml);
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + S to save
    if (e.ctrlKey && e.keyCode === 83) {
        e.preventDefault();
        $('#enterResultForm').submit();
    }
    // Escape to cancel
    if (e.keyCode === 27) {
        window.location.href = 'lab_order_details.php?id=<?php echo $order_test['lab_order_id']; ?>';
    }
    // Ctrl + T to set current time
    if (e.ctrlKey && e.keyCode === 84) {
        e.preventDefault();
        setCurrentDateTime();
    }
    // Ctrl + R to add reference range
    if (e.ctrlKey && e.keyCode === 82) {
        e.preventDefault();
        addReferenceRange();
    }
});
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
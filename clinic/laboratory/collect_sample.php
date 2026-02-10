<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/audit_functions.php'; // Added for logging

$order_test_id = intval($_GET['order_test_id']);

// AUDIT LOG: Access attempt for sample collection
audit_log($mysqli, [
    'user_id'     => $_SESSION['user_id'] ?? null,
    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
    'action'      => 'ACCESS',
    'module'      => 'Lab Samples',
    'table_name'  => 'lab_order_tests',
    'entity_type' => 'lab_order_test',
    'record_id'   => $order_test_id,
    'patient_id'  => null,
    'visit_id'    => null,
    'description' => "Attempting to access collect_sample.php for order test ID: " . $order_test_id,
    'status'      => 'ATTEMPT',
    'old_values'  => null,
    'new_values'  => null
]);

// Get order test details
$order_test = $mysqli->query("
    SELECT lot.*, lt.test_name, lt.test_code, lt.specimen_type, lt.required_volume,
           lo.order_number, p.first_name as patient_first_name, p.last_name as patient_last_name, p.patient_mrn, p.sex as patient_gender, p.date_of_birth as patient_dob,
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
        'module'      => 'Lab Samples',
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

// AUDIT LOG: Successful access to collect sample page
audit_log($mysqli, [
    'user_id'     => $_SESSION['user_id'] ?? null,
    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
    'action'      => 'VIEW',
    'module'      => 'Lab Samples',
    'table_name'  => 'lab_order_tests',
    'entity_type' => 'lab_order_test',
    'record_id'   => $order_test_id,
    'patient_id'  => null,
    'visit_id'    => null,
    'description' => "Accessed collect_sample.php for test: "
        . $order_test['test_name'] . " (" . $order_test['test_code'] . "). Patient: "
        . $order_test['patient_first_name'] . " "
        . $order_test['patient_last_name'],
    'status'      => 'SUCCESS',
    'old_values'  => null,
    'new_values'  => null
]);


// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    $sample_number = sanitizeInput($_POST['sample_number']);
    $collection_date = sanitizeInput($_POST['collection_date']);
    $specimen_type = sanitizeInput($_POST['specimen_type']);
    $collection_site = sanitizeInput($_POST['collection_site']);
    $sample_condition = sanitizeInput($_POST['sample_condition']);
    $collection_tube = sanitizeInput($_POST['collection_tube']);
    $collected_volume = floatval($_POST['collected_volume']);
    $storage_temperature = sanitizeInput($_POST['storage_temperature']);
    $collection_notes = sanitizeInput($_POST['collection_notes']);
    $quality_check = isset($_POST['quality_check']) ? 1 : 0;

    // Prepare sample data for audit log
    $sample_data = [
        'sample_number' => $sample_number,
        'collection_date' => $collection_date,
        'specimen_type' => $specimen_type,
        'collection_site' => $collection_site,
        'sample_condition' => $sample_condition,
        'collection_tube' => $collection_tube,
        'collected_volume' => $collected_volume,
        'storage_temperature' => $storage_temperature,
        'collection_notes' => $collection_notes,
        'quality_check' => $quality_check,
        'collected_by' => $session_user_id ?? null
    ];

    // AUDIT LOG: Attempt to collect sample
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'SAMPLE_COLLECT',
        'module'      => 'Lab Samples',
        'table_name'  => 'lab_samples',
        'entity_type' => 'lab_sample',
        'record_id'   => null,
        'patient_id'  => null,
        'visit_id'    => null,
        'description' => "Attempting to collect sample for test: " . $order_test['test_name'] . " (" . $order_test['test_code'] . ") - Sample: " . $sample_number,
        'status'      => 'ATTEMPT',
        'old_values'  => null,
        'new_values'  => json_encode($sample_data)
    ]);

    // Validate CSRF token
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        
        // AUDIT LOG: Invalid CSRF token
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'VALIDATION_FAILED',
            'module'      => 'Lab Samples',
            'table_name'  => 'lab_samples',
            'entity_type' => 'lab_sample',
            'record_id'   => null,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Invalid CSRF token when attempting to collect sample for test: " . $order_test['test_name'],
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => null
        ]);
        
        header("Location: collect_sample.php?order_test_id=" . $order_test_id);
        exit;
    }

    // Validate required fields
    if (empty($sample_number) || empty($collection_date) || empty($specimen_type) || empty($sample_condition) || !$quality_check) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please fill in all required fields and confirm quality check.";
        
        // AUDIT LOG: Validation failed - missing required fields
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'VALIDATION_FAILED',
            'module'      => 'Lab Samples',
            'table_name'  => 'lab_samples',
            'entity_type' => 'lab_sample',
            'record_id'   => null,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Validation failed: Missing required fields when collecting sample for test: " . $order_test['test_name'],
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => json_encode($sample_data)
        ]);
        
        header("Location: collect_sample.php?order_test_id=" . $order_test_id);
        exit;
    }

    // Validate collection date is not in the future
    $collection_datetime = new DateTime($collection_date);
    $now = new DateTime();
    if ($collection_datetime > $now) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Collection date cannot be in the future.";
        
        // AUDIT LOG: Validation failed - future date
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'VALIDATION_FAILED',
            'module'      => 'Lab Samples',
            'table_name'  => 'lab_samples',
            'entity_type' => 'lab_sample',
            'record_id'   => null,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Validation failed: Future collection date when collecting sample for test: " . $order_test['test_name'],
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => json_encode($sample_data)
        ]);
        
        header("Location: collect_sample.php?order_test_id=" . $order_test_id);
        exit;
    }

    // Check if sample number already exists
    $check_sql = "SELECT sample_id FROM lab_samples WHERE sample_number = ?";
    $check_stmt = $mysqli->prepare($check_sql);
    $check_stmt->bind_param("s", $sample_number);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($check_result->num_rows > 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Sample number already exists. Please use a unique sample number.";
        
        // AUDIT LOG: Validation failed - duplicate sample number
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'VALIDATION_FAILED',
            'module'      => 'Lab Samples',
            'table_name'  => 'lab_samples',
            'entity_type' => 'lab_sample',
            'record_id'   => null,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Validation failed: Duplicate sample number '" . $sample_number . "' when collecting sample for test: " . $order_test['test_name'],
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => json_encode($sample_data)
        ]);
        
        header("Location: collect_sample.php?order_test_id=" . $order_test_id);
        exit;
    }
    $check_stmt->close();

    // Start transaction
    $mysqli->begin_transaction();

    try {
        // Insert sample record
        $insert_sample_sql = "INSERT INTO lab_samples SET 
            sample_number = ?,
            test_id = ?,
            lab_order_id = ?,
            specimen_type = ?,
            collection_date = ?,
            collection_site = ?,
            sample_condition = ?,
            collection_tube = ?,
            collected_volume = ?,
            storage_temperature = ?,
            collection_notes = ?,
            collected_by = ?,
            quality_check = ?,
            created_at = NOW()";

        $insert_sample_stmt = $mysqli->prepare($insert_sample_sql);
        $insert_sample_stmt->bind_param(
            "siisssssdssii", 
            $sample_number,
            $order_test_id,
            $order_test['lab_order_id'],
            $specimen_type,
            $collection_date,
            $collection_site,
            $sample_condition,
            $collection_tube,
            $collected_volume,
            $storage_temperature,
            $collection_notes,
            $session_user_id,
            $quality_check 
        );

        if (!$insert_sample_stmt->execute()) {
            throw new Exception("Error inserting sample: " . $mysqli->error);
        }

        $sample_id = $insert_sample_stmt->insert_id;

        // Update order test status to 'collected'
        $update_test_sql = "UPDATE lab_order_tests SET 
                           status = 'collected',
                           updated_at = NOW()
                           WHERE lab_order_test_id = ?";
        
        $update_test_stmt = $mysqli->prepare($update_test_sql);
        $update_test_stmt->bind_param("i", $order_test_id);

        if (!$update_test_stmt->execute()) {
            throw new Exception("Error updating test status: " . $mysqli->error);
        }

        // Update lab order status if all tests are collected
        $pending_tests_sql = "SELECT COUNT(*) as pending_count 
                             FROM lab_order_tests 
                             WHERE lab_order_id = ? AND status = 'pending'";
        $pending_stmt = $mysqli->prepare($pending_tests_sql);
        $pending_stmt->bind_param("i", $order_test['lab_order_id']);
        $pending_stmt->execute();
        $pending_result = $pending_stmt->get_result()->fetch_assoc();

        if ($pending_result['pending_count'] == 0) {
            $update_order_sql = "UPDATE lab_orders SET 
                                lab_order_status = 'collected',
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
                        activity_type = 'sample_collected',
                        activity_description = ?,
                        performed_by = ?,
                        activity_date = NOW()";
        
        $activity_desc = "Sample collected for test: " . $order_test['test_name'] . " (" . $order_test['test_code'] . ") - Sample: " . $sample_number;
        $activity_stmt = $mysqli->prepare($activity_sql);
        $activity_stmt->bind_param("isi", $order_test_id, $activity_desc, $session_user_id);
        $activity_stmt->execute();

        // Commit transaction
        $mysqli->commit();

        // AUDIT LOG: Successful sample collection
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'SAMPLE_COLLECT',
            'module'      => 'Lab Samples',
            'table_name'  => 'lab_samples',
            'entity_type' => 'lab_sample',
            'record_id'   => $sample_id,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Sample collected successfully for test: " . $order_test['test_name'] . " (" . $order_test['test_code'] . ") - Sample: " . $sample_number,
            'status'      => 'SUCCESS',
            'old_values'  => null,
            'new_values'  => json_encode(array_merge($sample_data, [
                'sample_id' => $sample_id,
                'test_id' => $order_test_id,
                'lab_order_id' => $order_test['lab_order_id'],
                'created_at' => date('Y-m-d H:i:s')
            ]))
        ]);

        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Sample collected successfully!";
        header("Location: lab_order_details.php?id=" . $order_test['lab_order_id']);
        exit;

    } catch (Exception $e) {
        $mysqli->rollback();
        
        // AUDIT LOG: Failed sample collection
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'SAMPLE_COLLECT',
            'module'      => 'Lab Samples',
            'table_name'  => 'lab_samples',
            'entity_type' => 'lab_sample',
            'record_id'   => null,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Error collecting sample for test: " . $order_test['test_name'] . ". Error: " . $e->getMessage(),
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => json_encode($sample_data)
        ]);

        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error collecting sample: " . $e->getMessage();
        header("Location: collect_sample.php?order_test_id=" . $order_test_id);
        exit;
    }
}

// Generate sample number
function generateSampleNumber() {
    $timestamp = time();
    $random = rand(100, 999);
    return 'SAMP-' . $timestamp . '-' . $random;
}
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0">
            <i class="fas fa-fw fa-syringe mr-2"></i>Collect Sample
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

        <div class="row">
            <div class="col-md-8">
                <form method="POST" id="collectSampleForm" autocomplete="off">
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
                                        <label class="text-muted">Order Number</label>
                                        <p class="form-control-plaintext font-weight-bold"><?php echo htmlspecialchars($order_test['order_number']); ?></p>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="text-muted">Patient</label>
                                        <p class="form-control-plaintext font-weight-bold"><?php echo htmlspecialchars($order_test['patient_first_name'] . ' ' . $order_test['patient_last_name']); ?> (MRN: <?php echo htmlspecialchars($order_test['patient_mrn']); ?>)</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Collection Details -->
                    <div class="card card-info mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-syringe mr-2"></i>Collection Details</h4>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="sampleNumber">Sample Number <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="sampleNumber" name="sample_number" 
                                               value="<?php echo generateSampleNumber(); ?>" required>
                                        <small class="form-text text-muted">Unique identifier for the sample</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="collectionDate">Collection Date & Time <span class="text-danger">*</span></label>
                                        <input type="datetime-local" class="form-control" id="collectionDate" 
                                               name="collection_date" value="<?php echo date('Y-m-d\TH:i'); ?>" required>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="specimenType">Specimen Type <span class="text-danger">*</span></label>
                                        <select class="form-control select2" id="specimenType" name="specimen_type" required>
                                            <option value="">- Select Specimen Type -</option>
                                            <option value="Blood" <?php echo $order_test['specimen_type'] == 'Blood' ? 'selected' : ''; ?>>Blood</option>
                                            <option value="Urine">Urine</option>
                                            <option value="Stool">Stool</option>
                                            <option value="Sputum">Sputum</option>
                                            <option value="CSF">CSF</option>
                                            <option value="Tissue">Tissue</option>
                                            <option value="Swab">Swab</option>
                                            <option value="Other">Other</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="collectionSite">Collection Site</label>
                                        <input type="text" class="form-control" id="collectionSite" name="collection_site" 
                                               placeholder="e.g., Left Arm, Mid-stream">
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="sampleCondition">Sample Condition <span class="text-danger">*</span></label>
                                        <select class="form-control" id="sampleCondition" name="sample_condition" required>
                                            <option value="good">Good</option>
                                            <option value="hemolyzed">Hemolyzed</option>
                                            <option value="clotted">Clotted</option>
                                            <option value="insufficient">Insufficient Volume</option>
                                            <option value="contaminated">Contaminated</option>
                                            <option value="improper">Improper Storage</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="collectionTube">Collection Tube/Container</label>
                                        <input type="text" class="form-control" id="collectionTube" name="collection_tube" 
                                               placeholder="e.g., EDTA Tube, Sterile Container">
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="collectedVolume">Collected Volume</label>
                                        <div class="input-group">
                                            <input type="number" class="form-control" id="collectedVolume" 
                                                   name="collected_volume" step="0.1" min="0" placeholder="0.0">
                                            <div class="input-group-append">
                                                <span class="input-group-text">ml</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="storageTemperature">Storage Temperature</label>
                                        <select class="form-control" id="storageTemperature" name="storage_temperature">
                                            <option value="room_temp">Room Temperature</option>
                                            <option value="refrigerated">Refrigerated (2-8°C)</option>
                                            <option value="frozen">Frozen (-20°C)</option>
                                            <option value="deep_frozen">Deep Frozen (-80°C)</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Collection Notes -->
                    <div class="card card-warning">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-sticky-note mr-2"></i>Additional Information</h4>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label for="collectionNotes">Collection Notes</label>
                                <textarea class="form-control" id="collectionNotes" name="collection_notes" 
                                          rows="3" placeholder="Any special instructions or observations..."></textarea>
                            </div>
                            
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="qualityCheck" name="quality_check" value="1" required>
                                <label class="form-check-label" for="qualityCheck">
                                    I confirm that the sample has been collected following proper procedures and meets quality standards
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
                                    <i class="fas fa-save mr-2"></i>Save Collection
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
                            <button type="button" class="btn btn-outline-primary" onclick="generateNewSampleNumber()">
                                <i class="fas fa-sync mr-2"></i>Generate New Sample Number
                            </button>
                            <button type="button" class="btn btn-outline-info" onclick="setCurrentDateTime()">
                                <i class="fas fa-clock mr-2"></i>Set Current Date/Time
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Collection Guidelines -->
                <div class="card card-info">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-book mr-2"></i>Collection Guidelines</h4>
                    </div>
                    <div class="card-body">
                        <ul class="list-unstyled small">
                            <li class="mb-2">
                                <i class="fas fa-check text-success mr-1"></i>
                                Verify patient identity before collection
                            </li>
                            <li class="mb-2">
                                <i class="fas fa-check text-success mr-1"></i>
                                Use proper personal protective equipment
                            </li>
                            <li class="mb-2">
                                <i class="fas fa-check text-success mr-1"></i>
                                Follow aseptic technique
                            </li>
                            <li class="mb-2">
                                <i class="fas fa-check text-success mr-1"></i>
                                Label samples immediately after collection
                            </li>
                            <li class="mb-2">
                                <i class="fas fa-check text-success mr-1"></i>
                                Store samples at appropriate temperature
                            </li>
                            <li>
                                <i class="fas fa-check text-success mr-1"></i>
                                Document any deviations or issues
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.select2').select2();

    // Form validation
    $('#collectSampleForm').submit(function(e) {
        const sampleNumber = $('#sampleNumber').val();
        const collectionDate = $('#collectionDate').val();
        const specimenType = $('#specimenType').val();
        const sampleCondition = $('#sampleCondition').val();
        const qualityCheck = $('#qualityCheck').is(':checked');
        
        if (!sampleNumber || !collectionDate || !specimenType || !sampleCondition || !qualityCheck) {
            e.preventDefault();
            alert('Please fill in all required fields and confirm quality check');
            return false;
        }
        
        // Validate collection date is not in the future
        const collectionDateTime = new Date(collectionDate);
        const now = new Date();
        if (collectionDateTime > now) {
            e.preventDefault();
            alert('Collection date cannot be in the future');
            return false;
        }
        
        // Show loading state
        $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Saving...').prop('disabled', true);
    });
});

function generateNewSampleNumber() {
    const timestamp = new Date().getTime();
    const random = Math.floor(Math.random() * 1000);
    const sampleNumber = 'SAMP-' + timestamp + '-' + random;
    $('#sampleNumber').val(sampleNumber);
}

function setCurrentDateTime() {
    const now = new Date();
    const localDateTime = now.toISOString().slice(0, 16);
    $('#collectionDate').val(localDateTime);
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + S to save
    if (e.ctrlKey && e.keyCode === 83) {
        e.preventDefault();
        $('#collectSampleForm').submit();
    }
    // Escape to cancel
    if (e.keyCode === 27) {
        window.location.href = 'lab_order_details.php?id=<?php echo $order_test['lab_order_id']; ?>';
    }
    // Ctrl + G to generate new sample number
    if (e.ctrlKey && e.keyCode === 71) {
        e.preventDefault();
        generateNewSampleNumber();
    }
    // Ctrl + T to set current time
    if (e.ctrlKey && e.keyCode === 84) {
        e.preventDefault();
        setCurrentDateTime();
    }
});
</script>

<?php 
 require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';

 ?>
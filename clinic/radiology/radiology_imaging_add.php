<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/audit_functions.php'; // Added for logging

// Get imaging ID from URL
$imaging_id = intval($_GET['imaging_id'] ?? 0);

// AUDIT LOG: Access attempt for entering imaging result
audit_log($mysqli, [
    'user_id'     => $_SESSION['user_id'] ?? null,
    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
    'action'      => 'ACCESS',
    'module'      => 'Radiology',
    'table_name'  => 'radiology_imagings',
    'entity_type' => 'radiology_imaging',
    'record_id'   => $imaging_id,
    'patient_id'  => null,
    'visit_id'    => null,
    'description' => "Attempting to access imaging result entry page for imaging ID: " . $imaging_id,
    'status'      => 'ATTEMPT',
    'old_values'  => null,
    'new_values'  => null
]);

if ($imaging_id <= 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid imaging ID.";
    
    // AUDIT LOG: Invalid imaging ID
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'ACCESS',
        'module'      => 'Radiology',
        'table_name'  => 'radiology_imagings',
        'entity_type' => 'radiology_imaging',
        'record_id'   => $imaging_id,
        'patient_id'  => null,
        'visit_id'    => null,
        'description' => "Invalid imaging ID: " . $imaging_id,
        'status'      => 'FAILED',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    header("Location: radiology_imaging.php");
    exit;
}

// Get imaging details
$imaging_sql = "SELECT * FROM radiology_imagings WHERE imaging_id = ? AND is_active = 1";
$imaging_stmt = $mysqli->prepare($imaging_sql);
$imaging_stmt->bind_param("i", $imaging_id);
$imaging_stmt->execute();
$imaging_result = $imaging_stmt->get_result();

if ($imaging_result->num_rows === 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Imaging not found or has been deleted.";
    
    // AUDIT LOG: Imaging not found
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'ACCESS',
        'module'      => 'Radiology',
        'table_name'  => 'radiology_imagings',
        'entity_type' => 'radiology_imaging',
        'record_id'   => $imaging_id,
        'patient_id'  => null,
        'visit_id'    => null,
        'description' => "Imaging ID " . $imaging_id . " not found or inactive",
        'status'      => 'FAILED',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    header("Location: radiology_imaging.php");
    exit;
}

$imaging = $imaging_result->fetch_assoc();
$imaging_stmt->close();

// Get radiologists for assignment
$radiologists_sql = "SELECT user_id, CONCAT(first_name, ' ', last_name) as radiologist_name 
                     FROM users 
                     WHERE user_role = 'radiologist' AND is_active = 1 
                     ORDER BY first_name, last_name";
$radiologists_result = $mysqli->query($radiologists_sql);

// Get recent orders for this imaging
$recent_orders_sql = "SELECT o.order_id, o.patient_id, CONCAT(p.first_name, ' ', p.last_name) as patient_name,
                      o.order_date, o.status, o.priority, o.radiologist_id
                      FROM radiology_orders o
                      JOIN patients p ON o.patient_id = p.patient_id
                      WHERE o.imaging_id = ? 
                      AND o.status IN ('ordered', 'in_progress')
                      ORDER BY o.order_date DESC 
                      LIMIT 10";
$recent_orders_stmt = $mysqli->prepare($recent_orders_sql);
$recent_orders_stmt->bind_param("i", $imaging_id);
$recent_orders_stmt->execute();
$recent_orders_result = $recent_orders_stmt->get_result();

// AUDIT LOG: Successful access to imaging result entry page
audit_log($mysqli, [
    'user_id'     => $_SESSION['user_id'] ?? null,
    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
    'action'      => 'VIEW',
    'module'      => 'Radiology',
    'table_name'  => 'radiology_imagings',
    'entity_type' => 'radiology_imaging',
    'record_id'   => $imaging_id,
    'patient_id'  => null,
    'visit_id'    => null,
    'description' => "Accessed imaging result entry page for: " . $imaging['imaging_name'] . " (" . $imaging['imaging_code'] . ")",
    'status'      => 'SUCCESS',
    'old_values'  => null,
    'new_values'  => null
]);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    $order_id = intval($_POST['order_id']);
    $result_text = sanitizeInput($_POST['result_text']);
    $radiologist_id = intval($_POST['radiologist_id']);
    $findings = sanitizeInput($_POST['findings']);
    $impression = sanitizeInput($_POST['impression']);
    $recommendations = sanitizeInput($_POST['recommendations']);
    $is_abnormal = isset($_POST['is_abnormal']) ? 1 : 0;
    $severity = sanitizeInput($_POST['severity']);

    // Prepare result data for audit log
    $result_data = [
        'order_id' => $order_id,
        'radiologist_id' => $radiologist_id,
        'result_text' => $result_text,
        'findings' => $findings,
        'impression' => $impression,
        'recommendations' => $recommendations,
        'is_abnormal' => $is_abnormal,
        'severity' => $severity,
        'result_date' => date('Y-m-d H:i:s'),
        'result_by' => $session_user_id ?? null
    ];
    
    // Get order details for audit log
    $order_sql = "SELECT * FROM radiology_orders WHERE order_id = ?";
    $order_stmt = $mysqli->prepare($order_sql);
    $order_stmt->bind_param("i", $order_id);
    $order_stmt->execute();
    $order_result = $order_stmt->get_result();
    
    if ($order_result->num_rows === 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Order not found.";
        
        // AUDIT LOG: Order not found
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'RESULT_ENTRY',
            'module'      => 'Radiology',
            'table_name'  => 'radiology_results',
            'entity_type' => 'radiology_result',
            'record_id'   => $order_id,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Attempting to enter result for non-existent order ID: " . $order_id,
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => json_encode($result_data)
        ]);
        
        header("Location: radiology_imaging_enter_result.php?imaging_id=" . $imaging_id);
        exit;
    }
    
    $order = $order_result->fetch_assoc();
    $order_stmt->close();
    
    // Store old order values for audit log
    $old_order_data = [
        'status' => $order['status'],
        'result_date' => $order['result_date'],
        'radiologist_id' => $order['radiologist_id']
    ];

    // AUDIT LOG: Attempt to enter imaging result
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'RESULT_ENTRY',
        'module'      => 'Radiology',
        'table_name'  => 'radiology_results',
        'entity_type' => 'radiology_result',
        'record_id'   => $order_id,
        'patient_id'  => $order['patient_id'],
        'visit_id'    => $order['visit_id'],
        'description' => "Attempting to enter result for radiology order ID: " . $order_id . " (Patient ID: " . $order['patient_id'] . ")",
        'status'      => 'ATTEMPT',
        'old_values'  => json_encode($old_order_data),
        'new_values'  => json_encode($result_data)
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
            'module'      => 'Radiology',
            'table_name'  => 'radiology_results',
            'entity_type' => 'radiology_result',
            'record_id'   => $order_id,
            'patient_id'  => $order['patient_id'],
            'visit_id'    => $order['visit_id'],
            'description' => "Invalid CSRF token when attempting to enter result for order ID: " . $order_id,
            'status'      => 'FAILED',
            'old_values'  => json_encode($old_order_data),
            'new_values'  => json_encode($result_data)
        ]);
        
        header("Location: radiology_imaging_enter_result.php?imaging_id=" . $imaging_id);
        exit;
    }

    // Validate required fields
    if (empty($result_text) || empty($radiologist_id) || empty($findings) || empty($impression)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please fill in all required fields.";
        
        // AUDIT LOG: Validation failed - missing required fields
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'VALIDATION_FAILED',
            'module'      => 'Radiology',
            'table_name'  => 'radiology_results',
            'entity_type' => 'radiology_result',
            'record_id'   => $order_id,
            'patient_id'  => $order['patient_id'],
            'visit_id'    => $order['visit_id'],
            'description' => "Validation failed: Missing required fields when entering result for order ID: " . $order_id,
            'status'      => 'FAILED',
            'old_values'  => json_encode($old_order_data),
            'new_values'  => json_encode($result_data)
        ]);
        
        header("Location: radiology_imaging_enter_result.php?imaging_id=" . $imaging_id);
        exit;
    }

    // Start transaction
    $mysqli->begin_transaction();

    try {
        // Update radiology order status
        $update_order_sql = "UPDATE radiology_orders SET 
                           status = 'completed',
                           radiologist_id = ?,
                           result_date = NOW(),
                           completed_by = ?,
                           updated_at = NOW()
                           WHERE order_id = ?";
        
        $update_order_stmt = $mysqli->prepare($update_order_sql);
        $update_order_stmt->bind_param("iii", $radiologist_id, $session_user_id, $order_id);
        
        if (!$update_order_stmt->execute()) {
            throw new Exception("Error updating radiology order: " . $mysqli->error);
        }
        $update_order_stmt->close();

        // Insert radiology result
        $insert_result_sql = "INSERT INTO radiology_results SET 
                            order_id = ?,
                            result_text = ?,
                            radiologist_id = ?,
                            findings = ?,
                            impression = ?,
                            recommendations = ?,
                            is_abnormal = ?,
                            severity = ?,
                            result_date = NOW(),
                            result_by = ?,
                            created_at = NOW()";
        
        $insert_result_stmt = $mysqli->prepare($insert_result_sql);
        $insert_result_stmt->bind_param(
            "isissssii",
            $order_id,
            $result_text,
            $radiologist_id,
            $findings,
            $impression,
            $recommendations,
            $is_abnormal,
            $severity,
            $session_user_id
        );
        
        if (!$insert_result_stmt->execute()) {
            throw new Exception("Error inserting radiology result: " . $mysqli->error);
        }
        $result_id = $insert_result_stmt->insert_id;
        $insert_result_stmt->close();

        // Log activity in radiology_activity_logs
        $activity_sql = "INSERT INTO radiology_activity_logs SET 
                        imaging_id = ?, 
                        imaging_code = ?, 
                        order_id = ?,
                        user_id = ?, 
                        action = 'result_entered', 
                        description = ?, 
                        ip_address = ?, 
                        user_agent = ?";
        
        $radiologist_name_sql = "SELECT CONCAT(first_name, ' ', last_name) as name FROM users WHERE user_id = ?";
        $radiologist_stmt = $mysqli->prepare($radiologist_name_sql);
        $radiologist_stmt->bind_param("i", $radiologist_id);
        $radiologist_stmt->execute();
        $radiologist_result = $radiologist_stmt->get_result();
        $radiologist_name = "Unknown";
        if ($radiologist_result->num_rows > 0) {
            $radiologist_row = $radiologist_result->fetch_assoc();
            $radiologist_name = $radiologist_row['name'];
        }
        $radiologist_stmt->close();
        
        $activity_desc = "Result entered for imaging order ID: " . $order_id . 
                        ". Imaging: " . $imaging['imaging_name'] . 
                        ". Radiologist: " . $radiologist_name . 
                        ". Abnormal: " . ($is_abnormal ? 'Yes' : 'No') . 
                        ". Severity: " . $severity;
        
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN';
        
        $activity_stmt = $mysqli->prepare($activity_sql);
        $activity_stmt->bind_param(
            "isiisss",
            $imaging_id,
            $imaging['imaging_code'],
            $order_id,
            $session_user_id,
            $activity_desc,
            $ip_address,
            $user_agent
        );
        
        if (!$activity_stmt->execute()) {
            throw new Exception("Error logging activity: " . $mysqli->error);
        }
        $activity_stmt->close();

        // Update result_data with the result ID for audit log
        $result_data['result_id'] = $result_id;
        $result_data['status'] = 'completed';
        $old_order_data['status'] = $order['status'];

        // Commit transaction
        $mysqli->commit();

        // AUDIT LOG: Successful result entry
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'RESULT_ENTRY',
            'module'      => 'Radiology',
            'table_name'  => 'radiology_results',
            'entity_type' => 'radiology_result',
            'record_id'   => $result_id,
            'patient_id'  => $order['patient_id'],
            'visit_id'    => $order['visit_id'],
            'description' => "Result entered successfully for imaging order ID: " . $order_id . 
                            ". Imaging: " . $imaging['imaging_name'] . 
                            ". Status changed from '" . $old_order_data['status'] . "' to 'completed'.",
            'status'      => 'SUCCESS',
            'old_values'  => json_encode($old_order_data),
            'new_values'  => json_encode($result_data)
        ]);

        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Radiology result entered successfully!";
        header("Location: radiology_order_details.php?order_id=" . $order_id);
        exit;

    } catch (Exception $e) {
        // Rollback transaction on error
        $mysqli->rollback();
        
        // AUDIT LOG: Failed result entry
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'RESULT_ENTRY',
            'module'      => 'Radiology',
            'table_name'  => 'radiology_results',
            'entity_type' => 'radiology_result',
            'record_id'   => $order_id,
            'patient_id'  => $order['patient_id'],
            'visit_id'    => $order['visit_id'],
            'description' => "Failed to enter result for imaging order ID: " . $order_id . ". Error: " . $e->getMessage(),
            'status'      => 'FAILED',
            'old_values'  => json_encode($old_order_data),
            'new_values'  => json_encode($result_data)
        ]);

        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = $e->getMessage();
        header("Location: radiology_imaging_enter_result.php?imaging_id=" . $imaging_id);
        exit;
    }
}
?>

<!-- HTML form remains the same - just added audit logging to the PHP logic -->

<div class="card">
    <div class="card-header bg-info py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-plus-circle mr-2"></i>Add New Radiology Imaging
            </h3>
            <div class="card-tools">
                <a href="radiology_imaging.php" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Imaging
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

        <form method="POST" id="imagingForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <div class="row">
                <div class="col-md-8">
                    <!-- Imaging Basic Information -->
                    <div class="card card-primary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-info-circle mr-2"></i>Imaging Information</h3>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="imaging_code">Imaging Code *</label>
                                        <input type="text" class="form-control" id="imaging_code" name="imaging_code" 
                                               placeholder="e.g., XRAY_CHEST, CT_HEAD, MRI_BRAIN" required maxlength="20">
                                        <small class="form-text text-muted">Unique identifier for the imaging procedure (will also be used as billable item code)</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="imaging_name">Imaging Name *</label>
                                        <input type="text" class="form-control" id="imaging_name" name="imaging_name" 
                                               placeholder="e.g., Chest X-Ray, CT Head without Contrast" required>
                                        <small class="form-text text-muted">Full descriptive name of the imaging procedure (will be used as billable item name)</small>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="imaging_description">Description</label>
                                <textarea class="form-control" id="imaging_description" name="imaging_description" rows="3" 
                                          placeholder="Brief description of what this imaging procedure involves and its purpose..."
                                          maxlength="500"></textarea>
                                <small class="form-text text-muted">This description will also be used for the billable item</small>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="modality">Modality *</label>
                                        <select class="form-control" id="modality" name="modality" required>
                                            <option value="">- Select Modality -</option>
                                            <option value="X-Ray">X-Ray</option>
                                            <option value="CT">CT Scan</option>
                                            <option value="MRI">MRI</option>
                                            <option value="Ultrasound">Ultrasound</option>
                                            <option value="Mammography">Mammography</option>
                                            <option value="Fluoroscopy">Fluoroscopy</option>
                                            <option value="Nuclear">Nuclear Medicine</option>
                                            <option value="Other">Other</option>
                                        </select>
                                        <small class="form-text text-muted">Modality will determine the billable category</small>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="fee_amount">Fee ($) *</label>
                                        <input type="number" class="form-control" id="fee_amount" name="fee_amount" 
                                               step="0.01" min="0" value="0.00" required>
                                        <small class="form-text text-muted">This will be the unit price in billable items</small>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="duration_minutes">Duration (minutes) *</label>
                                        <input type="number" class="form-control" id="duration_minutes" name="duration_minutes" 
                                               min="1" value="30" required>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Billable Item Preview -->
                    <div class="card card-secondary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-dollar-sign mr-2"></i>Billable Item Preview</h3>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle mr-2"></i>
                                This imaging procedure will automatically be added to the billable items catalog for billing purposes.
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Billable Item Type</label>
                                        <input type="text" class="form-control bg-light" value="Imaging Procedure" readonly>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Billable Item Code</label>
                                        <input type="text" class="form-control bg-light" id="billable_item_code" value="" readonly>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Cost Price (Estimated)</label>
                                        <div class="input-group">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text">$</span>
                                            </div>
                                            <input type="text" class="form-control bg-light" id="cost_price_preview" value="0.00" readonly>
                                        </div>
                                        <small class="form-text text-muted">Auto-calculated as 60% of selling price</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Tax Settings</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control bg-light" value="Non-taxable (0%)" readonly>
                                            <div class="input-group-append">
                                                <span class="input-group-text"><i class="fas fa-percentage"></i></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label>Mapping Information</label>
                                <div class="alert alert-light">
                                    <small>
                                        <strong>Source:</strong> radiology_imagings table<br>
                                        <strong>Category:</strong> <span id="billable_category_preview">Radiology - </span><br>
                                        <strong>Status:</strong> <span id="billable_status_preview">Active</span>
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Imaging Specifications -->
                    <div class="card card-info">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-cogs mr-2"></i>Imaging Specifications</h3>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="body_part">Body Part</label>
                                        <input type="text" class="form-control" id="body_part" name="body_part" 
                                               placeholder="e.g., Chest, Head, Abdomen, Pelvis">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="contrast_required">Contrast Required</label>
                                        <select class="form-control" id="contrast_required" name="contrast_required">
                                            <option value="None">None</option>
                                            <option value="Oral">Oral</option>
                                            <option value="IV">IV</option>
                                            <option value="Both">Both (Oral & IV)</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="radiation_dose">Radiation Dose</label>
                                        <input type="text" class="form-control" id="radiation_dose" name="radiation_dose" 
                                               placeholder="e.g., 2.5 mSv, Low Dose, Non-ionizing">
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="preparation_instructions">Preparation Instructions</label>
                                <textarea class="form-control" id="preparation_instructions" name="preparation_instructions" rows="3" 
                                          placeholder="Patient preparation instructions, fasting requirements, clothing guidelines..."
                                          maxlength="1000"></textarea>
                            </div>

                            <div class="form-group">
                                <label for="report_template">Report Template</label>
                                <textarea class="form-control" id="report_template" name="report_template" rows="4" 
                                          placeholder="Standard reporting template for this imaging study..."
                                          maxlength="2000"></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Imaging Preview -->
                    <div class="card card-warning">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-eye mr-2"></i>Imaging Preview</h3>
                        </div>
                        <div class="card-body">
                            <div class="row text-center">
                                <div class="col-md-4">
                                    <div class="border rounded p-3 bg-light">
                                        <div class="h5 text-muted">Imaging Code</div>
                                        <div class="h3 font-weight-bold text-primary" id="preview_code">
                                            -
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="border rounded p-3 bg-light">
                                        <div class="h5 text-muted">Fee</div>
                                        <div class="h3 font-weight-bold text-success" id="preview_fee">
                                            $0.00
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="border rounded p-3 bg-light">
                                        <div class="h5 text-muted">Duration</div>
                                        <div class="h3 font-weight-bold text-info" id="preview_duration">
                                            30 min
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mt-3">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong>Imaging Name:</strong> 
                                        <span id="preview_name" class="ml-2">-</span>
                                    </div>
                                    <div>
                                        <strong>Modality:</strong> 
                                        <span id="preview_modality" class="ml-2 badge badge-primary">-</span>
                                    </div>
                                </div>
                                <div class="mt-2">
                                    <strong>Body Part:</strong> 
                                    <span id="preview_body_part" class="ml-2 text-muted">-</span>
                                </div>
                                <div class="mt-2">
                                    <strong>Contrast:</strong> 
                                    <span id="preview_contrast" class="ml-2 badge badge-warning">None</span>
                                </div>
                                <div class="mt-2">
                                    <strong>Description:</strong> 
                                    <span id="preview_description" class="ml-2 text-muted">-</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <!-- Quick Actions -->
                    <div class="card card-success">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-bolt mr-2"></i>Quick Actions</h3>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <?php if (SimplePermission::any([ 'radiology_create_image'])): ?>
                                <button type="submit" class="btn btn-success btn-lg">
                                    <i class="fas fa-save mr-2"></i>Create Imaging
                                </button>
                                <?php endif;?>
                                <button type="reset" class="btn btn-outline-secondary" onclick="resetForm()">
                                    <i class="fas fa-undo mr-2"></i>Reset Form
                                </button>
                                <a href="radiology_imaging.php" class="btn btn-outline-danger">
                                    <i class="fas fa-times mr-2"></i>Cancel
                                </a>
                            </div>
                            
                            <div class="form-group mt-3">
                                <div class="custom-control custom-switch">
                                    <input type="checkbox" class="custom-control-input" id="is_active" name="is_active" value="1" checked>
                                    <label class="custom-control-label" for="is_active">Active Imaging</label>
                                </div>
                                <small class="form-text text-muted">Inactive imaging won't be available for ordering or billing</small>
                            </div>
                        </div>
                    </div>

                    <!-- Billable Categories Reference -->
                    <div class="card card-primary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-tags mr-2"></i>Available Billable Categories</h3>
                        </div>
                        <div class="card-body">
                            <?php if ($billable_categories_result->num_rows > 0): ?>
                                <div class="list-group list-group-flush">
                                    <?php while ($category = $billable_categories_result->fetch_assoc()): ?>
                                        <div class="list-group-item px-0 py-2">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1 text-primary"><?php echo htmlspecialchars($category['category_name']); ?></h6>
                                                <small class="text-muted">ID: <?php echo $category['category_id']; ?></small>
                                            </div>
                                            <small class="text-muted">New categories will be created based on modality</small>
                                        </div>
                                    <?php endwhile; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted mb-0 text-center">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    No billable categories found. New ones will be created automatically.
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Common Imaging Templates -->
                    <div class="card card-info">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-magic mr-2"></i>Quick Templates</h3>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="loadTemplate('chest_xray')">
                                    <i class="fas fa-lungs mr-2"></i>Chest X-Ray
                                </button>
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="loadTemplate('ct_head')">
                                    <i class="fas fa-brain mr-2"></i>CT Head
                                </button>
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="loadTemplate('mri_brain')">
                                    <i class="fas fa-magnet mr-2"></i>MRI Brain
                                </button>
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="loadTemplate('us_abdomen')">
                                    <i class="fas fa-procedures mr-2"></i>Ultrasound Abdomen
                                </button>
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="loadTemplate('mammogram')">
                                    <i class="fas fa-female mr-2"></i>Mammogram
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Imaging -->
                    <div class="card card-secondary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-history mr-2"></i>Recently Added Imaging</h3>
                        </div>
                        <div class="card-body">
                            <?php if ($recent_imaging_result->num_rows > 0): ?>
                                <div class="list-group list-group-flush">
                                    <?php while ($imaging = $recent_imaging_result->fetch_assoc()): ?>
                                        <div class="list-group-item px-0 py-2">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1 text-primary"><?php echo htmlspecialchars($imaging['imaging_code']); ?></h6>
                                                <small class="text-success">$<?php echo number_format($imaging['fee_amount'], 2); ?></small>
                                            </div>
                                            <p class="mb-1 small"><?php echo htmlspecialchars($imaging['imaging_name']); ?></p>
                                            <div class="d-flex justify-content-between">
                                                <small class="text-muted"><?php echo htmlspecialchars($imaging['modality']); ?></small>
                                                <small class="text-muted"><?php echo $imaging['duration_minutes']; ?>m</small>
                                            </div>
                                        </div>
                                    <?php endwhile; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted mb-0 text-center">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    No recent imaging
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Validation Rules -->
                    <div class="card card-warning">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-check-circle mr-2"></i>Validation Rules</h3>
                        </div>
                        <div class="card-body">
                            <ul class="list-unstyled small">
                                <li class="mb-1">
                                    <i class="fas fa-check text-success mr-1"></i>
                                    Imaging code must be unique
                                </li>
                                <li class="mb-1">
                                    <i class="fas fa-check text-success mr-1"></i>
                                    All required fields must be filled
                                </li>
                                <li class="mb-1">
                                    <i class="fas fa-check text-success mr-1"></i>
                                    Fee cannot be negative
                                </li>
                                <li class="mb-1">
                                    <i class="fas fa-check text-success mr-1"></i>
                                    Duration must be positive
                                </li>
                                <li>
                                    <i class="fas fa-check text-success mr-1"></i>
                                    Modality must be selected
                                </li>
                                <li class="mt-2">
                                    <i class="fas fa-dollar-sign text-info mr-1"></i>
                                    Imaging will be added to billable items
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
$(document).ready(function() {
    // Update preview based on form changes
    function updatePreview() {
        const imagingCode = $('#imaging_code').val() || '-';
        const imagingName = $('#imaging_name').val() || '-';
        const imagingDescription = $('#imaging_description').val() || '-';
        const modality = $('#modality').val() || '-';
        const bodyPart = $('#body_part').val() || '-';
        const fee = $('#fee_amount').val() || '0.00';
        const duration = $('#duration_minutes').val() || '30';
        const contrast = $('#contrast_required').val() || 'None';
        const isActive = $('#is_active').is(':checked');
        
        // Calculate cost price (60% of selling price for imaging)
        const costPrice = parseFloat(fee) * 0.6;
        
        // Update preview elements
        $('#preview_code').text(imagingCode);
        $('#preview_name').text(imagingName);
        $('#preview_description').text(imagingDescription);
        $('#preview_modality').text(modality);
        $('#preview_body_part').text(bodyPart);
        $('#preview_fee').text('$' + parseFloat(fee).toFixed(2));
        $('#preview_duration').text(duration + ' min');
        $('#preview_contrast').text(contrast);
        
        // Update billable item preview
        $('#billable_item_code').val(imagingCode);
        $('#cost_price_preview').val(costPrice.toFixed(2));
        $('#billable_category_preview').text('Radiology - ' + (modality || '-'));
        $('#billable_status_preview').text(isActive ? 'Active' : 'Inactive');
        $('#billable_status_preview').removeClass('text-success text-danger').addClass(isActive ? 'text-success' : 'text-danger');
        
        // Update contrast badge color
        const contrastBadge = $('#preview_contrast');
        contrastBadge.removeClass('badge-warning badge-info badge-danger');
        if (contrast === 'None') {
            contrastBadge.addClass('badge-warning');
        } else if (contrast === 'Oral' || contrast === 'IV') {
            contrastBadge.addClass('badge-info');
        } else {
            contrastBadge.addClass('badge-danger');
        }
    }
    
    // Event listeners for real-time preview
    $('#imaging_code, #imaging_name, #imaging_description, #modality, #body_part, #fee_amount, #duration_minutes, #contrast_required, #is_active').on('input change', updatePreview);
    
    // Auto-generate imaging code suggestion
    $('#imaging_name, #modality').on('blur', function() {
        if (!$('#imaging_code').val()) {
            generateImagingCode();
        }
    });
    
    function generateImagingCode() {
        const modality = $('#modality').val();
        const name = $('#imaging_name').val();
        
        if (modality && name) {
            let code = '';
            
            // Create modality prefix
            switch(modality) {
                case 'X-Ray': code = 'XRAY'; break;
                case 'CT': code = 'CT'; break;
                case 'MRI': code = 'MRI'; break;
                case 'Ultrasound': code = 'US'; break;
                case 'Mammography': code = 'MAMMO'; break;
                case 'Fluoroscopy': code = 'FLUORO'; break;
                case 'Nuclear': code = 'NUC'; break;
                default: code = 'IMG'; break;
            }
            
            // Add body part or key words from name
            const words = name.toUpperCase().split(' ');
            const keyWords = words.filter(word => 
                word.length > 2 && 
                !['WITH', 'WITHOUT', 'AND', 'OR', 'THE', 'FOR', 'OF'].includes(word)
            );
            
            if (keyWords.length > 0) {
                code += '_' + keyWords.slice(0, 2).join('_');
            }
            
            $('#imaging_code').val(code.replace(/[^A-Z0-9_]/g, ''));
            updatePreview();
        }
    }
    
    // Set default duration based on modality
    $('#modality').on('change', function() {
        const modality = $(this).val();
        let defaultDuration = 30;
        let defaultFee = 0.00;
        
        switch(modality) {
            case 'X-Ray': 
                defaultDuration = 15; 
                defaultFee = 75.00;
                break;
            case 'CT': 
                defaultDuration = 30; 
                defaultFee = 350.00;
                break;
            case 'MRI': 
                defaultDuration = 45; 
                defaultFee = 600.00;
                break;
            case 'Ultrasound': 
                defaultDuration = 30; 
                defaultFee = 250.00;
                break;
            case 'Mammography': 
                defaultDuration = 20; 
                defaultFee = 150.00;
                break;
            case 'Fluoroscopy': 
                defaultDuration = 30; 
                defaultFee = 200.00;
                break;
            case 'Nuclear': 
                defaultDuration = 60; 
                defaultFee = 500.00;
                break;
        }
        
        $('#duration_minutes').val(defaultDuration);
        $('#fee_amount').val(defaultFee.toFixed(2));
        updatePreview();
    });
    
    // Initial preview update
    updatePreview();
    
    // Form validation
    $('#imagingForm').on('submit', function(e) {
        const imagingCode = $('#imaging_code').val().trim();
        const imagingName = $('#imaging_name').val().trim();
        const modality = $('#modality').val();
        const fee = parseFloat($('#fee_amount').val());
        const duration = parseInt($('#duration_minutes').val());
        
        let isValid = true;
        let errorMessage = '';
        
        // Validate required fields
        if (!imagingCode) {
            isValid = false;
            errorMessage = 'Imaging code is required';
        } else if (!imagingName) {
            isValid = false;
            errorMessage = 'Imaging name is required';
        } else if (!modality) {
            isValid = false;
            errorMessage = 'Modality is required';
        } else if (fee < 0) {
            isValid = false;
            errorMessage = 'Fee cannot be negative';
        } else if (duration <= 0) {
            isValid = false;
            errorMessage = 'Duration must be positive';
        }
        
        if (!isValid) {
            e.preventDefault();
            alert('Please fix the following error: ' + errorMessage);
            return false;
        }
        
        // Show loading state
        $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Creating...').prop('disabled', true);
    });
});

// Template functions
function loadTemplate(templateType) {
    const templates = {
        'chest_xray': {
            imaging_code: 'XRAY_CHEST',
            imaging_name: 'Chest X-Ray',
            imaging_description: 'Standard two-view chest radiography to evaluate lungs, heart, and chest wall',
            modality: 'X-Ray',
            body_part: 'Chest',
            preparation_instructions: 'Remove metal objects from chest area. No special preparation required.',
            contrast_required: 'None',
            fee_amount: '75.00',
            duration_minutes: '15',
            radiation_dose: '0.1 mSv',
            report_template: 'CHEST X-RAY REPORT:\n\nTECHNIQUE: PA and lateral views of the chest.\n\nFINDINGS:\n- Lungs: Clear and expanded bilaterally.\n- Heart: Normal size and configuration.\n- Mediastinum: Unremarkable.\n- Bones: No acute fracture or destructive lesion.\n- Soft tissues: Normal.\n\nIMPRESSION: Normal chest x-ray.'
        },
        'ct_head': {
            imaging_code: 'CT_HEAD',
            imaging_name: 'CT Head without Contrast',
            imaging_description: 'Computed tomography of the head to evaluate brain, skull, and intracranial structures',
            modality: 'CT',
            body_part: 'Head',
            preparation_instructions: 'Remove all metal objects from head and neck. No contrast preparation required.',
            contrast_required: 'None',
            fee_amount: '350.00',
            duration_minutes: '30',
            radiation_dose: '2.0 mSv',
            report_template: 'CT HEAD REPORT:\n\nTECHNIQUE: Axial images from skull base to vertex without IV contrast.\n\nFINDINGS:\n- Brain parenchyma: Normal gray-white matter differentiation.\n- Ventricles: Normal size and configuration.\n- Basal cisterns: Patent.\n- Calvarium: Intact.\n- Paranasal sinuses: Clear.\n\nIMPRESSION: Normal non-contrast CT head.'
        },
        'mri_brain': {
            imaging_code: 'MRI_BRAIN',
            imaging_name: 'MRI Brain without Contrast',
            imaging_description: 'Magnetic resonance imaging of the brain for detailed evaluation of brain structures',
            modality: 'MRI',
            body_part: 'Brain',
            preparation_instructions: 'No metal objects. Screening for MRI compatibility required. No contrast preparation.',
            contrast_required: 'None',
            fee_amount: '600.00',
            duration_minutes: '45',
            radiation_dose: 'Non-ionizing',
            report_template: 'MRI BRAIN REPORT:\n\nTECHNIQUE: Multiplanar, multisequence MRI of the brain without contrast.\n\nFINDINGS:\n- Brain parenchyma: Normal signal intensity.\n- Ventricular system: Normal size and configuration.\n- Cerebellum and brainstem: Unremarkable.\n- Cranial nerves: Normal.\n- Vessels: Flow voids present.\n\nIMPRESSION: Normal MRI brain without contrast.'
        },
        'us_abdomen': {
            imaging_code: 'US_ABDOMEN',
            imaging_name: 'Ultrasound Abdomen Complete',
            imaging_description: 'Complete abdominal ultrasound evaluating liver, gallbladder, pancreas, kidneys, and spleen',
            modality: 'Ultrasound',
            body_part: 'Abdomen',
            preparation_instructions: 'NPO for 6 hours prior to exam. Full bladder required.',
            contrast_required: 'None',
            fee_amount: '250.00',
            duration_minutes: '30',
            radiation_dose: 'Non-ionizing',
            report_template: 'ABDOMINAL ULTRASOUND REPORT:\n\nTECHNIQUE: Grayscale and Doppler ultrasound of the abdomen.\n\nFINDINGS:\n- Liver: Normal size and echotexture.\n- Gallbladder: Normal, no stones or wall thickening.\n- Pancreas: Partially visualized, unremarkable.\n- Kidneys: Normal size and cortical thickness.\n- Spleen: Normal.\n- Aorta: Normal caliber.\n\nIMPRESSION: Normal abdominal ultrasound.'
        },
        'mammogram': {
            imaging_code: 'MAMMO_SCREEN',
            imaging_name: 'Screening Mammogram',
            imaging_description: 'Bilateral screening mammography for breast cancer detection',
            modality: 'Mammography',
            body_part: 'Breast',
            preparation_instructions: 'No deodorant, powder, or lotion on day of exam. Wear two-piece clothing.',
            contrast_required: 'None',
            fee_amount: '150.00',
            duration_minutes: '20',
            radiation_dose: '0.4 mSv',
            report_template: 'SCREENING MAMMOGRAM REPORT:\n\nTECHNIQUE: Bilateral CC and MLO views.\n\nFINDINGS:\n- Breast composition: Heterogeneously dense.\n- Masses: None identified.\n- Calcifications: Benign scattered calcifications.\n- Asymmetries: None.\n- Associated features: None.\n\nIMPRESSION: BI-RADS 2: Benign findings.'
        }
    };
    
    const template = templates[templateType];
    if (template) {
        $('#imaging_code').val(template.imaging_code);
        $('#imaging_name').val(template.imaging_name);
        $('#imaging_description').val(template.imaging_description);
        $('#modality').val(template.modality);
        $('#body_part').val(template.body_part);
        $('#preparation_instructions').val(template.preparation_instructions);
        $('#contrast_required').val(template.contrast_required);
        $('#fee_amount').val(template.fee_amount);
        $('#duration_minutes').val(template.duration_minutes);
        $('#radiation_dose').val(template.radiation_dose);
        $('#report_template').val(template.report_template);
        
        // Trigger preview update
        $('input, select, textarea').trigger('change');
        
        // Show success message
        alert('Template loaded successfully! Please review and adjust as needed.');
    }
}

function resetForm() {
    if (confirm('Are you sure you want to reset all fields?')) {
        $('#imagingForm')[0].reset();
        $('input, select, textarea').trigger('change');
    }
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + S to save
    if (e.ctrlKey && e.keyCode === 83) {
        e.preventDefault();
        $('#imagingForm').submit();
    }
    // Escape to cancel
    if (e.keyCode === 27) {
        window.location.href = 'radiology_imaging.php';
    }
});
</script>

<style>
.card {
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    margin-bottom: 1rem;
}
.form-control:focus {
    border-color: #007bff;
    box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
}
.list-group-item {
    border: none;
    padding: 0.75rem 0;
}
.bg-light {
    background-color: #f8f9fa !important;
}
</style>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
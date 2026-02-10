<?php
// radiology_edit_order.php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/audit_functions.php'; // Added for logging

// Get order ID from URL
$order_id = intval($_GET['order_id']);

// AUDIT LOG: Access attempt for editing radiology order
audit_log($mysqli, [
    'user_id'     => $_SESSION['user_id'] ?? null,
    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
    'action'      => 'ACCESS',
    'module'      => 'Radiology',
    'table_name'  => 'radiology_orders',
    'entity_type' => 'radiology_order',
    'record_id'   => $order_id,
    'patient_id'  => null,
    'visit_id'    => null,
    'description' => "Attempting to access radiology order edit page for order ID: " . $order_id,
    'status'      => 'ATTEMPT',
    'old_values'  => null,
    'new_values'  => null
]);

if (empty($order_id) || $order_id <= 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid order ID.";
    
    // AUDIT LOG: Invalid order ID
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'ACCESS',
        'module'      => 'Radiology',
        'table_name'  => 'radiology_orders',
        'entity_type' => 'radiology_order',
        'record_id'   => $order_id,
        'patient_id'  => null,
        'visit_id'    => null,
        'description' => "Invalid order ID: " . $order_id,
        'status'      => 'FAILED',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    header("Location: radiology_orders.php");
    exit;
}

// Fetch radiology order details
$order_sql = "SELECT ro.*, 
                     p.patient_first_name, p.patient_last_name, p.patient_mrn, p.patient_gender, p.patient_dob,
                     u.user_name as referring_doctor_name,
                     d.department_name
              FROM radiology_orders ro
              LEFT JOIN patients p ON ro.patient_id = p.patient_id
              LEFT JOIN users u ON ro.referring_doctor_id = u.user_id
              LEFT JOIN departments d ON ro.department_id = d.department_id
              WHERE ro.radiology_order_id = ?";
$order_stmt = $mysqli->prepare($order_sql);
$order_stmt->bind_param("i", $order_id);
$order_stmt->execute();
$order_result = $order_stmt->get_result();

if ($order_result->num_rows == 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Radiology order not found.";
    
    // AUDIT LOG: Order not found
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'ACCESS',
        'module'      => 'Radiology',
        'table_name'  => 'radiology_orders',
        'entity_type' => 'radiology_order',
        'record_id'   => $order_id,
        'patient_id'  => null,
        'visit_id'    => null,
        'description' => "Radiology order ID " . $order_id . " not found",
        'status'      => 'FAILED',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    header("Location: radiology_orders.php");
    exit;
}

$order = $order_result->fetch_assoc();

// AUDIT LOG: Successful access to edit order page
audit_log($mysqli, [
    'user_id'     => $_SESSION['user_id'] ?? null,
    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
    'action'      => 'VIEW',
    'module'      => 'Radiology',
    'table_name'  => 'radiology_orders',
    'entity_type' => 'radiology_order',
    'record_id'   => $order_id,
    'patient_id'  => $order['patient_id'],
    'visit_id'    => $order['visit_id'] ?? null,
    'description' => "Accessed radiology order edit page for order #" . $order['order_number'] . 
                    " (Patient: " . $order['patient_first_name'] . " " . $order['patient_last_name'] . ")",
    'status'      => 'SUCCESS',
    'old_values'  => null,
    'new_values'  => null
]);

// Fetch existing studies for this order
$existing_studies_sql = "SELECT ros.*, ri.imaging_name, ri.imaging_code, ri.fee_amount, ri.imaging_description
                         FROM radiology_order_studies ros
                         LEFT JOIN radiology_imagings ri ON ros.imaging_id = ri.imaging_id
                         WHERE ros.radiology_order_id = ?
                         ORDER BY ros.created_at ASC";
$existing_studies_stmt = $mysqli->prepare($existing_studies_sql);
$existing_studies_stmt->bind_param("i", $order_id);
$existing_studies_stmt->execute();
$existing_studies_result = $existing_studies_stmt->get_result();

// Store existing studies for audit log
$existing_studies = [];
while ($study = $existing_studies_result->fetch_assoc()) {
    $existing_studies[$study['radiology_order_study_id']] = $study;
}
$existing_studies_result->data_seek(0); // Reset pointer

// Fetch all available imaging studies
$available_studies_sql = "SELECT imaging_id, imaging_name, imaging_code, fee_amount, imaging_description
                          FROM radiology_imagings 
                          WHERE is_active = 1
                          ORDER BY imaging_name ASC";
$available_studies_result = $mysqli->query($available_studies_sql);

// Fetch available radiologists/technologists
$radiologists_sql = "SELECT user_id, user_name FROM users";
$radiologists_result = $mysqli->query($radiologists_sql);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    
    // Store old order data for audit log
    $old_order_data = [
        'order_priority' => $order['order_priority'],
        'body_part' => $order['body_part'],
        'clinical_notes' => $order['clinical_notes'],
        'instructions' => $order['instructions'],
        'contrast_required' => $order['contrast_required'],
        'contrast_type' => $order['contrast_type'],
        'pre_procedure_instructions' => $order['pre_procedure_instructions'],
        'radiologist_id' => $order['radiologist_id']
    ];
    
    // Store old studies data for audit log
    $old_studies_data = $existing_studies;
    
    // Prepare changes tracking
    $changes = [];
    $studies_changes = [];
    $new_studies_added = [];
    $studies_deleted = [];
    $studies_updated = [];

    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        
        // AUDIT LOG: Invalid CSRF token
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'VALIDATION_FAILED',
            'module'      => 'Radiology',
            'table_name'  => 'radiology_orders',
            'entity_type' => 'radiology_order',
            'record_id'   => $order_id,
            'patient_id'  => $order['patient_id'],
            'visit_id'    => $order['visit_id'] ?? null,
            'description' => "Invalid CSRF token when attempting to edit radiology order #" . $order['order_number'],
            'status'      => 'FAILED',
            'old_values'  => json_encode(['order_data' => $old_order_data, 'studies' => $old_studies_data]),
            'new_values'  => null
        ]);
        
        header("Location: radiology_edit_order.php?order_id=" . $order_id);
        exit;
    }
    
    // Update order details
    $order_priority = sanitizeInput($_POST['order_priority'] ?? 'routine');
    $body_part = sanitizeInput($_POST['body_part'] ?? '');
    $clinical_notes = sanitizeInput($_POST['clinical_notes'] ?? '');
    $instructions = sanitizeInput($_POST['instructions'] ?? '');
    $contrast_required = isset($_POST['contrast_required']) ? 1 : 0;
    $contrast_type = sanitizeInput($_POST['contrast_type'] ?? '');
    $pre_procedure_instructions = sanitizeInput($_POST['pre_procedure_instructions'] ?? '');
    
    // Update radiologist if assigned
    $radiologist_id = !empty($_POST['radiologist_id']) ? intval($_POST['radiologist_id']) : null;
    
    // Track order changes
    if ($old_order_data['order_priority'] != $order_priority) {
        $changes[] = "Priority: " . $old_order_data['order_priority'] . " → " . $order_priority;
    }
    if ($old_order_data['body_part'] != $body_part) {
        $changes[] = "Body part: " . ($old_order_data['body_part'] ?: 'Not specified') . " → " . ($body_part ?: 'Not specified');
    }
    if ($old_order_data['contrast_required'] != $contrast_required) {
        $changes[] = "Contrast required: " . ($old_order_data['contrast_required'] ? 'Yes' : 'No') . " → " . ($contrast_required ? 'Yes' : 'No');
    }
    if ($old_order_data['contrast_type'] != $contrast_type) {
        $changes[] = "Contrast type: " . ($old_order_data['contrast_type'] ?: 'Not specified') . " → " . ($contrast_type ?: 'Not specified');
    }
    if ($old_order_data['radiologist_id'] != $radiologist_id) {
        $old_radiologist = $old_order_data['radiologist_id'] ? "ID " . $old_order_data['radiologist_id'] : 'None';
        $new_radiologist = $radiologist_id ? "ID " . $radiologist_id : 'None';
        $changes[] = "Radiologist: " . $old_radiologist . " → " . $new_radiologist;
    }
    
    // Prepare new order data for audit log
    $new_order_data = [
        'order_priority' => $order_priority,
        'body_part' => $body_part,
        'clinical_notes' => $clinical_notes,
        'instructions' => $instructions,
        'contrast_required' => $contrast_required,
        'contrast_type' => $contrast_type,
        'pre_procedure_instructions' => $pre_procedure_instructions,
        'radiologist_id' => $radiologist_id,
        'updated_by' => $session_user_id ?? null
    ];

    // AUDIT LOG: Attempt to update order and studies
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'UPDATE_ORDER',
        'module'      => 'Radiology',
        'table_name'  => 'radiology_orders',
        'entity_type' => 'radiology_order',
        'record_id'   => $order_id,
        'patient_id'  => $order['patient_id'],
        'visit_id'    => $order['visit_id'] ?? null,
        'description' => "Attempting to update radiology order #" . $order['order_number'] . 
                        " and associated studies (Patient: " . $order['patient_first_name'] . " " . $order['patient_last_name'] . ")",
        'status'      => 'ATTEMPT',
        'old_values'  => json_encode(['order_data' => $old_order_data, 'studies' => $old_studies_data]),
        'new_values'  => json_encode(['order_data' => $new_order_data])
    ]);

    try {
        $mysqli->begin_transaction();
        
        // Update order details
        $update_sql = "UPDATE radiology_orders 
                       SET order_priority = ?, 
                           body_part = ?, 
                           clinical_notes = ?, 
                           instructions = ?,
                           contrast_required = ?, 
                           contrast_type = ?, 
                           pre_procedure_instructions = ?,
                           radiologist_id = ?,
                           updated_by = ?,
                           updated_at = NOW()
                       WHERE radiology_order_id = ?";
        $update_stmt = $mysqli->prepare($update_sql);
        $update_stmt->bind_param("ssssissiii", 
            $order_priority, $body_part, $clinical_notes, $instructions,
            $contrast_required, $contrast_type, $pre_procedure_instructions,
            $radiologist_id, $session_user_id, $order_id
        );
        
        if (!$update_stmt->execute()) {
            throw new Exception("Error updating order details: " . $mysqli->error);
        }
        $update_stmt->close();
        
        // Handle existing studies updates
        if (isset($_POST['existing_studies'])) {
            foreach ($_POST['existing_studies'] as $study_id => $study_data) {
                $study_id = intval($study_id);
                $status = sanitizeInput($study_data['status'] ?? 'pending');
                $scheduled_date = !empty($study_data['scheduled_date']) ? sanitizeInput($study_data['scheduled_date']) : null;
                $performed_by = !empty($study_data['performed_by']) ? intval($study_data['performed_by']) : null;
                $study_notes = sanitizeInput($study_data['study_notes'] ?? '');
                
                // Get old study data for comparison
                $old_study_data = isset($old_studies_data[$study_id]) ? $old_studies_data[$study_id] : null;
                
                if ($old_study_data) {
                    $study_changes = [];
                    if ($old_study_data['status'] != $status) {
                        $study_changes[] = "Status: " . $old_study_data['status'] . " → " . $status;
                    }
                    if ($old_study_data['scheduled_date'] != $scheduled_date) {
                        $old_date = $old_study_data['scheduled_date'] ? date('M j, Y', strtotime($old_study_data['scheduled_date'])) : 'Not scheduled';
                        $new_date = $scheduled_date ? date('M j, Y', strtotime($scheduled_date)) : 'Not scheduled';
                        $study_changes[] = "Scheduled date: " . $old_date . " → " . $new_date;
                    }
                    if ($old_study_data['performed_by'] != $performed_by) {
                        $old_performer = $old_study_data['performed_by'] ? "ID " . $old_study_data['performed_by'] : 'None';
                        $new_performer = $performed_by ? "ID " . $performed_by : 'None';
                        $study_changes[] = "Performed by: " . $old_performer . " → " . $new_performer;
                    }
                    
                    if (!empty($study_changes)) {
                        $studies_updated[$study_id] = [
                            'imaging_name' => $old_study_data['imaging_name'],
                            'changes' => $study_changes
                        ];
                    }
                }
                
                $update_study_sql = "UPDATE radiology_order_studies 
                                     SET status = ?, 
                                         scheduled_date = ?, 
                                         performed_by = ?, 
                                         study_notes = ?,
                                         updated_at = NOW()
                                     WHERE radiology_order_study_id = ?";
                $update_study_stmt = $mysqli->prepare($update_study_sql);
                $update_study_stmt->bind_param("ssisi", $status, $scheduled_date, $performed_by, $study_notes, $study_id);
                
                if (!$update_study_stmt->execute()) {
                    throw new Exception("Error updating study ID " . $study_id . ": " . $mysqli->error);
                }
                $update_study_stmt->close();
            }
        }
        
        // Handle new studies addition
        if (isset($_POST['new_studies']) && is_array($_POST['new_studies'])) {
            foreach ($_POST['new_studies'] as $new_study) {
                if (!empty($new_study['imaging_id'])) {
                    $imaging_id = intval($new_study['imaging_id']);
                    $status = sanitizeInput($new_study['status'] ?? 'pending');
                    $scheduled_date = !empty($new_study['scheduled_date']) ? sanitizeInput($new_study['scheduled_date']) : null;
                    $performed_by = !empty($new_study['performed_by']) ? intval($new_study['performed_by']) : null;
                    $study_notes = sanitizeInput($new_study['study_notes'] ?? '');
                    
                    // Get imaging details for logging
                    $imaging_sql = "SELECT imaging_name, imaging_code FROM radiology_imagings WHERE imaging_id = ?";
                    $imaging_stmt = $mysqli->prepare($imaging_sql);
                    $imaging_stmt->bind_param("i", $imaging_id);
                    $imaging_stmt->execute();
                    $imaging_result = $imaging_stmt->get_result();
                    $imaging_data = $imaging_result->fetch_assoc();
                    $imaging_stmt->close();
                    
                    // Check if this study already exists for this order
                    $check_sql = "SELECT radiology_order_study_id FROM radiology_order_studies 
                                  WHERE radiology_order_id = ? AND imaging_id = ?";
                    $check_stmt = $mysqli->prepare($check_sql);
                    $check_stmt->bind_param("ii", $order_id, $imaging_id);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result();
                    
                    if ($check_result->num_rows == 0) {
                        $insert_sql = "INSERT INTO radiology_order_studies 
                                       (radiology_order_id, imaging_id, status, scheduled_date, performed_by, study_notes, created_by, created_at)
                                       VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
                        $insert_stmt = $mysqli->prepare($insert_sql);
                        $insert_stmt->bind_param("iissisi", $order_id, $imaging_id, $status, $scheduled_date, $performed_by, $study_notes, $session_user_id);
                        
                        if (!$insert_stmt->execute()) {
                            throw new Exception("Error adding new study: " . $mysqli->error);
                        }
                        
                        $new_study_id = $insert_stmt->insert_id;
                        $insert_stmt->close();
                        
                        // Track new study
                        $new_studies_added[] = [
                            'study_id' => $new_study_id,
                            'imaging_name' => $imaging_data['imaging_name'],
                            'imaging_code' => $imaging_data['imaging_code'],
                            'status' => $status
                        ];
                        
                        // AUDIT LOG: New study added
                        audit_log($mysqli, [
                            'user_id'     => $_SESSION['user_id'] ?? null,
                            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                            'action'      => 'ADD_STUDY',
                            'module'      => 'Radiology',
                            'table_name'  => 'radiology_order_studies',
                            'entity_type' => 'radiology_study',
                            'record_id'   => $new_study_id,
                            'patient_id'  => $order['patient_id'],
                            'visit_id'    => $order['visit_id'] ?? null,
                            'description' => "Added new study to order #" . $order['order_number'] . 
                                            ": " . $imaging_data['imaging_name'] . " (" . $imaging_data['imaging_code'] . ")" .
                                            " with status: " . $status,
                            'status'      => 'SUCCESS',
                            'old_values'  => null,
                            'new_values'  => json_encode([
                                'order_id' => $order_id,
                                'imaging_id' => $imaging_id,
                                'imaging_name' => $imaging_data['imaging_name'],
                                'status' => $status,
                                'scheduled_date' => $scheduled_date,
                                'performed_by' => $performed_by,
                                'created_by' => $session_user_id
                            ])
                        ]);
                    }
                    $check_stmt->close();
                }
            }
        }
        
        // Handle study deletions
        if (isset($_POST['delete_studies']) && is_array($_POST['delete_studies'])) {
            foreach ($_POST['delete_studies'] as $study_id) {
                $study_id = intval($study_id);
                
                // Get study details before deletion for audit log
                $study_sql = "SELECT ros.*, ri.imaging_name, ri.imaging_code 
                             FROM radiology_order_studies ros
                             LEFT JOIN radiology_imagings ri ON ros.imaging_id = ri.imaging_id
                             WHERE ros.radiology_order_study_id = ?";
                $study_stmt = $mysqli->prepare($study_sql);
                $study_stmt->bind_param("i", $study_id);
                $study_stmt->execute();
                $study_result = $study_stmt->get_result();
                $study_data = $study_result->fetch_assoc();
                $study_stmt->close();
                
                $delete_sql = "DELETE FROM radiology_order_studies WHERE radiology_order_study_id = ?";
                $delete_stmt = $mysqli->prepare($delete_sql);
                $delete_stmt->bind_param("i", $study_id);
                
                if (!$delete_stmt->execute()) {
                    throw new Exception("Error deleting study ID " . $study_id . ": " . $mysqli->error);
                }
                $delete_stmt->close();
                
                // Track deleted study
                $studies_deleted[] = [
                    'study_id' => $study_id,
                    'imaging_name' => $study_data['imaging_name'],
                    'imaging_code' => $study_data['imaging_code'],
                    'status' => $study_data['status']
                ];
                
                // AUDIT LOG: Study deleted
                audit_log($mysqli, [
                    'user_id'     => $_SESSION['user_id'] ?? null,
                    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                    'action'      => 'DELETE_STUDY',
                    'module'      => 'Radiology',
                    'table_name'  => 'radiology_order_studies',
                    'entity_type' => 'radiology_study',
                    'record_id'   => $study_id,
                    'patient_id'  => $order['patient_id'],
                    'visit_id'    => $order['visit_id'] ?? null,
                    'description' => "Deleted study from order #" . $order['order_number'] . 
                                    ": " . $study_data['imaging_name'] . " (" . $study_data['imaging_code'] . ")" .
                                    " with status: " . $study_data['status'],
                    'status'      => 'SUCCESS',
                    'old_values'  => json_encode($study_data),
                    'new_values'  => null
                ]);
            }
        }
        
        $mysqli->commit();
        
        // Build comprehensive change description
        $change_description = [];
        if (!empty($changes)) {
            $change_description[] = "Order changes: " . implode(", ", $changes);
        }
        if (!empty($studies_updated)) {
            $updated_studies_desc = [];
            foreach ($studies_updated as $study_id => $study) {
                $updated_studies_desc[] = $study['imaging_name'] . " (" . implode(", ", $study['changes']) . ")";
            }
            $change_description[] = "Updated studies: " . implode("; ", $updated_studies_desc);
        }
        if (!empty($new_studies_added)) {
            $new_studies_desc = [];
            foreach ($new_studies_added as $study) {
                $new_studies_desc[] = $study['imaging_name'] . " (" . $study['status'] . ")";
            }
            $change_description[] = "Added studies: " . implode(", ", $new_studies_desc);
        }
        if (!empty($studies_deleted)) {
            $deleted_studies_desc = [];
            foreach ($studies_deleted as $study) {
                $deleted_studies_desc[] = $study['imaging_name'];
            }
            $change_description[] = "Deleted studies: " . implode(", ", $deleted_studies_desc);
        }
        
        $full_change_description = !empty($change_description) ? implode(". ", $change_description) : "No changes detected";

        // AUDIT LOG: Successful order update
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'UPDATE_ORDER',
            'module'      => 'Radiology',
            'table_name'  => 'radiology_orders',
            'entity_type' => 'radiology_order',
            'record_id'   => $order_id,
            'patient_id'  => $order['patient_id'],
            'visit_id'    => $order['visit_id'] ?? null,
            'description' => "Radiology order #" . $order['order_number'] . " updated successfully. " . $full_change_description,
            'status'      => 'SUCCESS',
            'old_values'  => json_encode(['order_data' => $old_order_data, 'studies' => $old_studies_data]),
            'new_values'  => json_encode(['order_data' => $new_order_data, 'changes_summary' => $change_description])
        ]);

        // Log activity in activity_logs (existing log)
        $activity_desc = "Updated radiology order #" . $order['order_number'] . " and its studies";
        
        $activity_sql = "INSERT INTO activity_logs SET 
                        activity_description = ?, 
                        activity_created_by = ?, 
                        activity_date = NOW()";
        $activity_stmt = $mysqli->prepare($activity_sql);
        $activity_stmt->bind_param("si", $activity_desc, $session_user_id);
        $activity_stmt->execute();
        $activity_stmt->close();
        
        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Order and studies updated successfully.";
        header("Location: radiology_order_details.php?id=" . $order_id);
        exit;
        
    } catch (Exception $e) {
        $mysqli->rollback();
        
        // AUDIT LOG: Failed order update
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'UPDATE_ORDER',
            'module'      => 'Radiology',
            'table_name'  => 'radiology_orders',
            'entity_type' => 'radiology_order',
            'record_id'   => $order_id,
            'patient_id'  => $order['patient_id'],
            'visit_id'    => $order['visit_id'] ?? null,
            'description' => "Failed to update radiology order #" . $order['order_number'] . 
                            ". Error: " . $e->getMessage(),
            'status'      => 'FAILED',
            'old_values'  => json_encode(['order_data' => $old_order_data, 'studies' => $old_studies_data]),
            'new_values'  => json_encode(['order_data' => $new_order_data])
        ]);

        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error updating order: " . $e->getMessage();
        header("Location: radiology_edit_order.php?order_id=" . $order_id);
        exit;
    }
}

// Calculate patient age
$patient_age = "";
if (!empty($order['patient_dob'])) {
    $birthDate = new DateTime($order['patient_dob']);
    $today = new DateTime();
    $age = $today->diff($birthDate)->y;
    $patient_age = " ($age yrs)";
}
?>

<div class="card">
    <div class="card-header bg-warning py-2">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h3 class="card-title mt-2 mb-0">
                    <i class="fas fa-fw fa-edit mr-2"></i>Edit Radiology Order & Studies
                </h3>
                <small class="text-white-50">Order #: <?php echo htmlspecialchars($order['order_number']); ?></small>
            </div>
            <div class="btn-group">
                <a href="radiology_order_details.php?id=<?php echo $order_id; ?>" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Order
                </a>
            </div>
        </div>
    </div>
    
    <div class="card-body">
        <!-- Alert Messages -->
        <?php if (isset($_SESSION['alert_message'])): ?>
            <div class="alert alert-<?php echo $_SESSION['alert_type']; ?> alert-dismissible">
                <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
                <i class="icon fas fa-<?php echo $_SESSION['alert_type'] == 'success' ? 'check' : 'exclamation-triangle'; ?>"></i>
                <?php echo $_SESSION['alert_message']; ?>
            </div>
            <?php unset($_SESSION['alert_message'], $_SESSION['alert_type']); ?>
        <?php endif; ?>
        
        <form method="POST" action="" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <div class="row">
                <!-- Order Information Column -->
                <div class="col-md-6">
                    <!-- Order Information -->
                    <div class="card card-primary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-info-circle mr-2"></i>Order Information</h3>
                        </div>
                        <div class="card-body">
                            <table class="table table-sm">
                                <tr>
                                    <th width="40%">Patient:</th>
                                    <td>
                                        <strong><?php echo htmlspecialchars($order['patient_first_name'] . ' ' . $order['patient_last_name']); ?></strong>
                                        <?php echo $patient_age; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th>MRN:</th>
                                    <td><?php echo htmlspecialchars($order['patient_mrn']); ?></td>
                                </tr>
                                <tr>
                                    <th>Order #:</th>
                                    <td><?php echo htmlspecialchars($order['order_number']); ?></td>
                                </tr>
                                <tr>
                                    <th>Priority:</th>
                                    <td>
                                        <select class="form-control form-control-sm" id="order_priority" name="order_priority">
                                            <option value="routine" <?php echo $order['order_priority'] == 'routine' ? 'selected' : ''; ?>>Routine</option>
                                            <option value="urgent" <?php echo $order['order_priority'] == 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                                            <option value="stat" <?php echo $order['order_priority'] == 'stat' ? 'selected' : ''; ?>>Stat</option>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Body Part:</th>
                                    <td>
                                        <input type="text" class="form-control form-control-sm" id="body_part" name="body_part" 
                                               value="<?php echo htmlspecialchars($order['body_part'] ?? 'NA'); ?>">
                                    </td>
                                </tr>
                                <tr>
                                    <th>Referring Doctor:</th>
                                    <td><?php echo !empty($order['referring_doctor_name']) ? htmlspecialchars($order['referring_doctor_name']) : 'N/A'; ?></td>
                                </tr>
                                <tr>
                                    <th>Department:</th>
                                    <td><?php echo !empty($order['department_name']) ? htmlspecialchars($order['department_name']) : 'N/A'; ?></td>
                                </tr>
                                <tr>
                                    <th>Assign Radiologist:</th>
                                    <td>
                                        <select class="form-control form-control-sm select2" id="radiologist_id" name="radiologist_id">
                                            <option value="">- Select Radiologist -</option>
                                            <?php 
                                            $radiologists_result->data_seek(0);
                                            while ($radiologist = $radiologists_result->fetch_assoc()): ?>
                                                <option value="<?php echo $radiologist['user_id']; ?>"
                                                    <?php echo $order['radiologist_id'] == $radiologist['user_id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($radiologist['user_name']); ?>
                                                </option>
                                            <?php endwhile; ?>
                                        </select>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <!-- Clinical Information -->
                    <div class="card card-warning mt-3">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-stethoscope mr-2"></i>Clinical Information</h3>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label for="clinical_notes">Clinical Notes</label>
                                <textarea class="form-control" id="clinical_notes" name="clinical_notes" 
                                          rows="4"><?php echo htmlspecialchars($order['clinical_notes']); ?></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label for="instructions">Instructions</label>
                                <textarea class="form-control" id="instructions" name="instructions" 
                                          rows="4"><?php echo htmlspecialchars($order['instructions']); ?></textarea>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <div class="custom-control custom-checkbox">
                                            <input type="checkbox" class="custom-control-input" id="contrast_required" 
                                                   name="contrast_required" value="1" 
                                                   <?php echo $order['contrast_required'] ? 'checked' : ''; ?>>
                                            <label class="custom-control-label" for="contrast_required">
                                                Contrast Required
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="contrast_type">Contrast Type</label>
                                        <input type="text" class="form-control form-control-sm" id="contrast_type" name="contrast_type" 
                                               value="<?php echo htmlspecialchars($order['contrast_type']); ?>"
                                               <?php echo !$order['contrast_required'] ? 'disabled' : ''; ?>>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="pre_procedure_instructions">Pre-procedure Instructions</label>
                                <textarea class="form-control" id="pre_procedure_instructions" 
                                          name="pre_procedure_instructions" rows="3"><?php echo htmlspecialchars($order['pre_procedure_instructions'] ?? 'NA'); ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Studies Management Column -->
                <div class="col-md-6">
                    <!-- Existing Studies -->
                    <div class="card card-success">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h3 class="card-title"><i class="fas fa-procedures mr-2"></i>Manage Studies</h3>
                            <span class="badge badge-light"><?php echo $existing_studies_result->num_rows; ?> studies</span>
                        </div>
                        <div class="card-body">
                            <?php if ($existing_studies_result->num_rows > 0): ?>
                                <div class="existing-studies">
                                    <?php 
                                    $existing_studies_result->data_seek(0);
                                    while ($study = $existing_studies_result->fetch_assoc()): 
                                        $study_id = $study['radiology_order_study_id'];
                                    ?>
                                        <div class="study-card card mb-3">
                                            <div class="card-header py-2">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <h6 class="mb-0">
                                                        <?php echo htmlspecialchars($study['imaging_name']); ?>
                                                        <small class="text-muted">(<?php echo htmlspecialchars($study['imaging_code']); ?>)</small>
                                                    </h6>
                                                    <div class="custom-control custom-checkbox">
                                                        <input type="checkbox" class="custom-control-input" 
                                                               id="delete_<?php echo $study_id; ?>" 
                                                               name="delete_studies[]" value="<?php echo $study_id; ?>">
                                                        <label class="custom-control-label text-danger" 
                                                               for="delete_<?php echo $study_id; ?>">
                                                            Delete
                                                        </label>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="card-body">
                                                <div class="row">
                                                    <div class="col-md-6">
                                                        <div class="form-group">
                                                            <label>Status</label>
                                                            <select class="form-control form-control-sm" name="existing_studies[<?php echo $study_id; ?>][status]">
                                                                <option value="pending" <?php echo $study['status'] == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                                                <option value="scheduled" <?php echo $study['status'] == 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                                                                <option value="in_progress" <?php echo $study['status'] == 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                                                <option value="completed" <?php echo $study['status'] == 'completed' ? 'selected' : ''; ?>>Completed</option>
                                                                <option value="cancelled" <?php echo $study['status'] == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="form-group">
                                                            <label>Scheduled Date</label>
                                                            <input type="datetime-local" class="form-control form-control-sm" 
                                                                   name="existing_studies[<?php echo $study_id; ?>][scheduled_date]"
                                                                   value="<?php echo $study['scheduled_date'] ? date('Y-m-d\TH:i', strtotime($study['scheduled_date'])) : ''; ?>">
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <div class="row">
                                                    <div class="col-md-6">
                                                        <div class="form-group">
                                                            <label>Performed By</label>
                                                            <select class="form-control form-control-sm select2" name="existing_studies[<?php echo $study_id; ?>][performed_by]">
                                                                <option value="">Select Technician</option>
                                                                <?php 
                                                                $radiologists_result->data_seek(0);
                                                                while ($tech = $radiologists_result->fetch_assoc()): ?>
                                                                    <option value="<?php echo $tech['user_id']; ?>"
                                                                        <?php echo $study['performed_by'] == $tech['user_id'] ? 'selected' : ''; ?>>
                                                                        <?php echo htmlspecialchars($tech['user_name']); ?>
                                                                    </option>
                                                                <?php endwhile; ?>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="form-group">
                                                            <label>Fee</label>
                                                            <input type="text" class="form-control form-control-sm" 
                                                                   value="Kshs<?php echo number_format($study['fee_amount'], 2); ?>" readonly>
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <div class="form-group">
                                                    <label>Study Notes</label>
                                                    <textarea class="form-control form-control-sm" 
                                                              name="existing_studies[<?php echo $study_id; ?>][study_notes]" 
                                                              rows="2"
                                                              placeholder="Additional notes for this study..."><?php echo htmlspecialchars($study['study_notes'] ?? ''); ?></textarea>
                                                </div>
                                                
                                                <?php if (!empty($study['imaging_description'])): ?>
                                                    <div class="alert alert-info p-2 mt-2">
                                                        <small><strong>Description:</strong> <?php echo htmlspecialchars($study['imaging_description']); ?></small>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endwhile; ?>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-info text-center">
                                    <i class="fas fa-procedures fa-2x mb-3"></i>
                                    <h5>No studies found for this order</h5>
                                    <p class="mb-0">Add new studies using the section below.</p>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Add New Studies -->
                            <div class="new-studies-section mt-4">
                                <h5 class="mb-3"><i class="fas fa-plus-circle mr-2"></i>Add New Studies</h5>
                                <div id="newStudiesContainer">
                                    <!-- New studies will be added here -->
                                </div>
                                <button type="button" class="btn btn-sm btn-success mt-2" id="addNewStudyBtn">
                                    <i class="fas fa-plus mr-2"></i>Add Another Study
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Form Actions -->
                    <div class="card mt-3">
                        <div class="card-body text-center">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="fas fa-save mr-2"></i>Save All Changes
                            </button>
                            <a href="radiology_order_details.php?id=<?php echo $order_id; ?>" class="btn btn-secondary btn-lg">
                                <i class="fas fa-times mr-2"></i>Cancel
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
$(document).ready(function() {
    let studyCounter = 0;
    
    // Initialize Select2
    $('.select2').select2({
        width: '100%'
    });
    
    // Toggle contrast type field
    $('#contrast_required').change(function() {
        $('#contrast_type').prop('disabled', !$(this).is(':checked'));
    });
    
    // Set minimum datetime for scheduling
    const now = new Date();
    now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
    const minDateTime = now.toISOString().slice(0, 16);
    
    // Apply to existing inputs
    $('input[type="datetime-local"]').attr('min', minDateTime);
    
    // Add new study row
    $('#addNewStudyBtn').click(function() {
        studyCounter++;
        const studyRow = `
            <div class="study-card card mb-3" id="studyRow${studyCounter}">
                <div class="card-header py-2">
                    <h6 class="mb-0">New Study</h6>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label>Select Imaging Study</label>
                        <select class="form-control select2 study-select" name="new_studies[${studyCounter}][imaging_id]" required>
                            <option value="">- Select Imaging Study -</option>
                            <?php 
                            $available_studies_result->data_seek(0);
                            while ($avail_study = $available_studies_result->fetch_assoc()): ?>
                                <option value="<?php echo $avail_study['imaging_id']; ?>"
                                        data-fee="<?php echo $avail_study['fee_amount']; ?>"
                                        data-description="<?php echo htmlspecialchars($avail_study['imaging_description'] ?? ''); ?>">
                                    <?php echo htmlspecialchars($avail_study['imaging_name']); ?> 
                                    (<?php echo htmlspecialchars($avail_study['imaging_code']); ?>)
                                    - Kshs<?php echo number_format($avail_study['fee_amount'], 2); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Status</label>
                                <select class="form-control form-control-sm" name="new_studies[${studyCounter}][status]">
                                    <option value="pending">Pending</option>
                                    <option value="scheduled">Scheduled</option>
                                    <option value="in_progress">In Progress</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Scheduled Date</label>
                                <input type="datetime-local" class="form-control form-control-sm" 
                                       name="new_studies[${studyCounter}][scheduled_date]">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Performed By</label>
                                <select class="form-control form-control-sm select2" name="new_studies[${studyCounter}][performed_by]">
                                    <option value="">Select Technician</option>
                                    <?php 
                                    $radiologists_result->data_seek(0);
                                    while ($tech = $radiologists_result->fetch_assoc()): ?>
                                        <option value="<?php echo $tech['user_id']; ?>">
                                            <?php echo htmlspecialchars($tech['user_name']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Fee</label>
                                <input type="text" class="form-control form-control-sm fee-display" readonly>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Study Notes</label>
                        <textarea class="form-control form-control-sm" 
                                  name="new_studies[${studyCounter}][study_notes]" 
                                  rows="2"
                                  placeholder="Additional notes for this study..."></textarea>
                    </div>
                    
                    <div id="imagingDetails${studyCounter}" class="alert alert-info p-2 mt-2" style="display: none;">
                        <small><strong>Description:</strong> <span class="imaging-description"></span></small>
                    </div>
                    
                    <button type="button" class="btn btn-sm btn-danger remove-study-btn" 
                            data-row="studyRow${studyCounter}">
                        <i class="fas fa-trash mr-2"></i> Remove Study
                    </button>
                </div>
            </div>
        `;
        $('#newStudiesContainer').append(studyRow);
        
        // Initialize Select2 for new row
        $('#studyRow' + studyCounter + ' .select2').select2({
            width: '100%'
        });
        
        // Apply min datetime to new input
        $('#studyRow' + studyCounter + ' input[type="datetime-local"]').attr('min', minDateTime);
    });
    
    // Remove study row
    $(document).on('click', '.remove-study-btn', function() {
        const rowId = $(this).data('row');
        $('#' + rowId).remove();
    });
    
    // Update fee and description when study is selected
    $(document).on('change', '.study-select', function() {
        const selectedOption = $(this).find('option:selected');
        const fee = selectedOption.data('fee') || '0.00';
        const description = selectedOption.data('description') || '';
        const card = $(this).closest('.study-card');
        
        card.find('.fee-display').val('Kshs' + parseFloat(fee).toFixed(2));
        
        if (description) {
            const detailsId = card.attr('id').replace('studyRow', 'imagingDetails');
            $('#' + detailsId + ' .imaging-description').text(description);
            $('#' + detailsId).show();
        } else {
            const detailsId = card.attr('id').replace('studyRow', 'imagingDetails');
            $('#' + detailsId).hide();
        }
    });
    
    // Apply to new inputs when added
    $(document).on('DOMNodeInserted', 'input[type="datetime-local"]', function() {
        $(this).attr('min', minDateTime);
    });
});
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
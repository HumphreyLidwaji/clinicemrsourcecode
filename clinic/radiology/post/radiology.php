<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/audit_functions.php'; // Added for logging

// Handle schedule order redirect (GET request)
if (isset($_GET['schedule_order'])) {
    $radiology_order_id = intval($_GET['schedule_order']);
    
    // AUDIT LOG: Attempt to schedule order
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'SCHEDULE_ORDER',
        'module'      => 'Radiology',
        'table_name'  => 'radiology_orders',
        'entity_type' => 'radiology_order',
        'record_id'   => $radiology_order_id,
        'patient_id'  => null,
        'visit_id'    => null,
        'description' => "Attempting to schedule studies for order ID: " . $radiology_order_id,
        'status'      => 'ATTEMPT',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    // Verify order exists and is not completed/cancelled
    $check_sql = "SELECT order_status, order_number, patient_id FROM radiology_orders WHERE radiology_order_id = ?";
    $check_stmt = $mysqli->prepare($check_sql);
    $check_stmt->bind_param("i", $radiology_order_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows == 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Radiology order not found.";
        
        // AUDIT LOG: Order not found
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'SCHEDULE_ORDER',
            'module'      => 'Radiology',
            'table_name'  => 'radiology_orders',
            'entity_type' => 'radiology_order',
            'record_id'   => $radiology_order_id,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Radiology order ID " . $radiology_order_id . " not found",
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => null
        ]);
        
        header("Location: /clinic/radiology/radiology_orders.php");
        exit;
    }
    
    $order = $check_result->fetch_assoc();
    if ($order['order_status'] == 'Completed' || $order['order_status'] == 'Cancelled') {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Cannot schedule studies for completed or cancelled orders.";
        
        // AUDIT LOG: Invalid order status for scheduling
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'SCHEDULE_ORDER',
            'module'      => 'Radiology',
            'table_name'  => 'radiology_orders',
            'entity_type' => 'radiology_order',
            'record_id'   => $radiology_order_id,
            'patient_id'  => $order['patient_id'],
            'visit_id'    => null,
            'description' => "Cannot schedule studies for order #" . $order['order_number'] . 
                            ". Current status: " . $order['order_status'],
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => null
        ]);
        
        header("Location: /clinic/radiology/radiology_order_details.php?id=" . $radiology_order_id);
        exit;
    }
    
    // AUDIT LOG: Successful schedule order attempt
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'SCHEDULE_ORDER',
        'module'      => 'Radiology',
        'table_name'  => 'radiology_orders',
        'entity_type' => 'radiology_order',
        'record_id'   => $radiology_order_id,
        'patient_id'  => $order['patient_id'],
        'visit_id'    => null,
        'description' => "Redirecting to schedule studies for order #" . $order['order_number'],
        'status'      => 'SUCCESS',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    // Redirect to schedule study page
    header("Location: /clinic/radiology/radiology_schedule_study.php?order_id=" . $radiology_order_id);
    exit;
}

// Handle other GET actions
if (isset($_GET['delete_order'])) {
    $radiology_order_id = intval($_GET['delete_order']);
    
    // AUDIT LOG: Attempt to delete order
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'DELETE_ORDER',
        'module'      => 'Radiology',
        'table_name'  => 'radiology_orders',
        'entity_type' => 'radiology_order',
        'record_id'   => $radiology_order_id,
        'patient_id'  => null,
        'visit_id'    => null,
        'description' => "Attempting to delete radiology order ID: " . $radiology_order_id,
        'status'      => 'ATTEMPT',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    // Verify order exists
    $check_sql = "SELECT ro.*, p.patient_first_name, p.patient_last_name 
                  FROM radiology_orders ro
                  LEFT JOIN patients p ON ro.patient_id = p.patient_id
                  WHERE ro.radiology_order_id = ?";
    $check_stmt = $mysqli->prepare($check_sql);
    $check_stmt->bind_param("i", $radiology_order_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows == 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Radiology order not found.";
        
        // AUDIT LOG: Order not found
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'DELETE_ORDER',
            'module'      => 'Radiology',
            'table_name'  => 'radiology_orders',
            'entity_type' => 'radiology_order',
            'record_id'   => $radiology_order_id,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Radiology order ID " . $radiology_order_id . " not found",
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => null
        ]);
        
        header("Location: /clinic/radiology/radiology_orders.php");
        exit;
    }

    $order = $check_result->fetch_assoc();
    
    // Get studies to be deleted for audit log
    $studies_sql = "SELECT ros.*, ri.imaging_name, ri.imaging_code 
                    FROM radiology_order_studies ros
                    LEFT JOIN radiology_imagings ri ON ros.imaging_id = ri.imaging_id
                    WHERE ros.radiology_order_id = ?";
    $studies_stmt = $mysqli->prepare($studies_sql);
    $studies_stmt->bind_param("i", $radiology_order_id);
    $studies_stmt->execute();
    $studies_result = $studies_stmt->get_result();
    $studies_to_delete = [];
    while ($study = $studies_result->fetch_assoc()) {
        $studies_to_delete[] = [
            'study_id' => $study['radiology_order_study_id'],
            'imaging_name' => $study['imaging_name'],
            'imaging_code' => $study['imaging_code'],
            'status' => $study['status']
        ];
    }

    try {
        $mysqli->begin_transaction();

        // Delete order studies first (due to foreign key constraints)
        $delete_studies_sql = "DELETE FROM radiology_order_studies WHERE radiology_order_id = ?";
        $delete_studies_stmt = $mysqli->prepare($delete_studies_sql);
        $delete_studies_stmt->bind_param("i", $radiology_order_id);
        $delete_studies_stmt->execute();

        // Delete the order
        $delete_order_sql = "DELETE FROM radiology_orders WHERE radiology_order_id = ?";
        $delete_order_stmt = $mysqli->prepare($delete_order_sql);
        $delete_order_stmt->bind_param("i", $radiology_order_id);
        $delete_order_stmt->execute();

        $mysqli->commit();

        // AUDIT LOG: Order deletion successful
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'DELETE_ORDER',
            'module'      => 'Radiology',
            'table_name'  => 'radiology_orders',
            'entity_type' => 'radiology_order',
            'record_id'   => $radiology_order_id,
            'patient_id'  => $order['patient_id'],
            'visit_id'    => null,
            'description' => "Deleted radiology order #" . $order['order_number'] . 
                            " for patient: " . $order['patient_first_name'] . " " . $order['patient_last_name'] . 
                            ". Deleted " . count($studies_to_delete) . " associated studies.",
            'status'      => 'SUCCESS',
            'old_values'  => json_encode([
                'order' => [
                    'order_number' => $order['order_number'],
                    'order_status' => $order['order_status'],
                    'order_priority' => $order['order_priority'],
                    'patient_id' => $order['patient_id']
                ],
                'studies' => $studies_to_delete
            ]),
            'new_values'  => null
        ]);

        // Log activity in activity_logs (existing log)
        $activity_desc = "Deleted radiology order: " . $order['order_number'];
        $activity_sql = "INSERT INTO activity_logs SET 
                        activity_description = ?, 
                        activity_created_by = ?, 
                        activity_date = NOW()";
        $activity_stmt = $mysqli->prepare($activity_sql);
        $activity_stmt->bind_param("si", $activity_desc, $session_user_id);
        $activity_stmt->execute();
        $activity_stmt->close();

        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Radiology order deleted successfully!";
        header("Location: /clinic/radiology/radiology_orders.php");
        exit;

    } catch (Exception $e) {
        $mysqli->rollback();
        
        // AUDIT LOG: Failed order deletion
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'DELETE_ORDER',
            'module'      => 'Radiology',
            'table_name'  => 'radiology_orders',
            'entity_type' => 'radiology_order',
            'record_id'   => $radiology_order_id,
            'patient_id'  => $order['patient_id'],
            'visit_id'    => null,
            'description' => "Failed to delete radiology order #" . $order['order_number'] . 
                            ". Error: " . $e->getMessage(),
            'status'      => 'FAILED',
            'old_values'  => json_encode([
                'order' => [
                    'order_number' => $order['order_number'],
                    'order_status' => $order['order_status']
                ],
                'studies_count' => count($studies_to_delete)
            ]),
            'new_values'  => null
        ]);

        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error deleting order: " . $e->getMessage();
        header("Location: /clinic/radiology/radiology_orders.php");
        exit;
    }
}

// Handle study status updates
if (isset($_GET['update_study_status'])) {
    $study_id = intval($_GET['update_study_status']);
    $new_status = sanitizeInput($_GET['status']);
    
    // AUDIT LOG: Attempt to update study status
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'UPDATE_STUDY_STATUS',
        'module'      => 'Radiology',
        'table_name'  => 'radiology_order_studies',
        'entity_type' => 'radiology_study',
        'record_id'   => $study_id,
        'patient_id'  => null,
        'visit_id'    => null,
        'description' => "Attempting to update study ID " . $study_id . " status to: " . $new_status,
        'status'      => 'ATTEMPT',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    // Verify study exists
    $check_sql = "SELECT ros.*, ri.imaging_name, ri.imaging_code, ro.order_number, ro.patient_id,
                         p.patient_first_name, p.patient_last_name
                  FROM radiology_order_studies ros
                  JOIN radiology_imagings ri ON ros.imaging_id = ri.imaging_id
                  JOIN radiology_orders ro ON ros.radiology_order_id = ro.radiology_order_id
                  LEFT JOIN patients p ON ro.patient_id = p.patient_id
                  WHERE ros.radiology_order_study_id = ?";
    $check_stmt = $mysqli->prepare($check_sql);
    $check_stmt->bind_param("i", $study_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows == 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Study not found.";
        
        // AUDIT LOG: Study not found
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'UPDATE_STUDY_STATUS',
            'module'      => 'Radiology',
            'table_name'  => 'radiology_order_studies',
            'entity_type' => 'radiology_study',
            'record_id'   => $study_id,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Study ID " . $study_id . " not found",
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => null
        ]);
        
        header("Location: /clinic/radiology/radiology_orders.php");
        exit;
    }

    $study = $check_result->fetch_assoc();
    $old_status = $study['status'];

    // Update study status
    $update_sql = "UPDATE radiology_order_studies SET 
                  status = ?,
                  updated_at = NOW()";
    
    // Add performed_date if status is completed
    if ($new_status == 'completed') {
        $update_sql .= ", performed_date = NOW(), performed_by = ?";
    } else {
        $update_sql .= ", performed_date = NULL, performed_by = NULL";
    }
    
    $update_sql .= " WHERE radiology_order_study_id = ?";
    
    $update_stmt = $mysqli->prepare($update_sql);
    
    if ($new_status == 'completed') {
        $update_stmt->bind_param("sii", $new_status, $session_user_id, $study_id);
    } else {
        $update_stmt->bind_param("si", $new_status, $study_id);
    }
    
    if ($update_stmt->execute()) {
        // Update order status if all studies are completed
        $order_completed = false;
        if ($new_status == 'completed') {
            $check_all_completed_sql = "SELECT COUNT(*) as total, 
                                               SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
                                        FROM radiology_order_studies 
                                        WHERE radiology_order_id = ?";
            $check_all_stmt = $mysqli->prepare($check_all_completed_sql);
            $check_all_stmt->bind_param("i", $study['radiology_order_id']);
            $check_all_stmt->execute();
            $check_all_result = $check_all_stmt->get_result();
            $completion_data = $check_all_result->fetch_assoc();
            
            if ($completion_data['total'] > 0 && $completion_data['completed'] == $completion_data['total']) {
                $update_order_sql = "UPDATE radiology_orders SET 
                                   order_status = 'Completed',
                                   updated_at = NOW()
                                   WHERE radiology_order_id = ?";
                $update_order_stmt = $mysqli->prepare($update_order_sql);
                $update_order_stmt->bind_param("i", $study['radiology_order_id']);
                $update_order_stmt->execute();
                $update_order_stmt->close();
                $order_completed = true;
            }
        }

        // AUDIT LOG: Study status updated successfully
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'UPDATE_STUDY_STATUS',
            'module'      => 'Radiology',
            'table_name'  => 'radiology_order_studies',
            'entity_type' => 'radiology_study',
            'record_id'   => $study_id,
            'patient_id'  => $study['patient_id'],
            'visit_id'    => null,
            'description' => "Updated study '" . $study['imaging_name'] . "' (" . $study['imaging_code'] . 
                            ") status from '" . $old_status . "' to '" . $new_status . "'" . 
                            ($order_completed ? ". Order #" . $study['order_number'] . " marked as completed." : ""),
            'status'      => 'SUCCESS',
            'old_values'  => json_encode([
                'status' => $old_status,
                'performed_date' => $study['performed_date'],
                'performed_by' => $study['performed_by']
            ]),
            'new_values'  => json_encode([
                'status' => $new_status,
                'performed_by' => ($new_status == 'completed' ? $session_user_id : null),
                'order_completed' => $order_completed
            ])
        ]);

        // Log activity in activity_logs (existing log)
        $activity_desc = "Updated study status: " . $study['imaging_name'] . 
                        " to " . $new_status . 
                        " for order #" . $study['order_number'];
        $activity_sql = "INSERT INTO activity_logs SET 
                        activity_description = ?, 
                        activity_created_by = ?, 
                        activity_date = NOW()";
        $activity_stmt = $mysqli->prepare($activity_sql);
        $activity_stmt->bind_param("si", $activity_desc, $session_user_id);
        $activity_stmt->execute();
        $activity_stmt->close();

        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Study status updated successfully!";
    } else {
        // AUDIT LOG: Failed study status update
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'UPDATE_STUDY_STATUS',
            'module'      => 'Radiology',
            'table_name'  => 'radiology_order_studies',
            'entity_type' => 'radiology_study',
            'record_id'   => $study_id,
            'patient_id'  => $study['patient_id'],
            'visit_id'    => null,
            'description' => "Failed to update study '" . $study['imaging_name'] . "' status. Error: " . $mysqli->error,
            'status'      => 'FAILED',
            'old_values'  => json_encode(['status' => $old_status]),
            'new_values'  => json_encode(['status' => $new_status])
        ]);

        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error updating study status: " . $mysqli->error;
    }
    
    header("Location: /clinic/radiology/radiology_order_details.php?id=" . $study['radiology_order_id']);
    exit;
}

// Handle order status updates
if (isset($_GET['update_order_status'])) {
    $order_id = intval($_GET['update_order_status']);
    $new_status = sanitizeInput($_GET['status']);
    
    // AUDIT LOG: Attempt to update order status
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'UPDATE_ORDER_STATUS',
        'module'      => 'Radiology',
        'table_name'  => 'radiology_orders',
        'entity_type' => 'radiology_order',
        'record_id'   => $order_id,
        'patient_id'  => null,
        'visit_id'    => null,
        'description' => "Attempting to update order ID " . $order_id . " status to: " . $new_status,
        'status'      => 'ATTEMPT',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    // Verify order exists
    $check_sql = "SELECT ro.*, p.patient_first_name, p.patient_last_name 
                  FROM radiology_orders ro
                  LEFT JOIN patients p ON ro.patient_id = p.patient_id
                  WHERE ro.radiology_order_id = ?";
    $check_stmt = $mysqli->prepare($check_sql);
    $check_stmt->bind_param("i", $order_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows == 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Radiology order not found.";
        
        // AUDIT LOG: Order not found
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'UPDATE_ORDER_STATUS',
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
        
        header("Location: /clinic/radiology/radiology_orders.php");
        exit;
    }

    $order = $check_result->fetch_assoc();
    $old_status = $order['order_status'];

    // Update order status
    $update_sql = "UPDATE radiology_orders SET 
                  order_status = ?,
                  updated_at = NOW()
                  WHERE radiology_order_id = ?";
    
    $update_stmt = $mysqli->prepare($update_sql);
    $update_stmt->bind_param("si", $new_status, $order_id);
    
    if ($update_stmt->execute()) {
        // AUDIT LOG: Order status updated successfully
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'UPDATE_ORDER_STATUS',
            'module'      => 'Radiology',
            'table_name'  => 'radiology_orders',
            'entity_type' => 'radiology_order',
            'record_id'   => $order_id,
            'patient_id'  => $order['patient_id'],
            'visit_id'    => null,
            'description' => "Updated order #" . $order['order_number'] . 
                            " status from '" . $old_status . "' to '" . $new_status . "'" .
                            " (Patient: " . $order['patient_first_name'] . " " . $order['patient_last_name'] . ")",
            'status'      => 'SUCCESS',
            'old_values'  => json_encode(['order_status' => $old_status]),
            'new_values'  => json_encode(['order_status' => $new_status])
        ]);

        // Log activity in activity_logs (existing log)
        $activity_desc = "Updated order status: " . $order['order_number'] . 
                        " to " . $new_status;
        $activity_sql = "INSERT INTO activity_logs SET 
                        activity_description = ?, 
                        activity_created_by = ?, 
                        activity_date = NOW()";
        $activity_stmt = $mysqli->prepare($activity_sql);
        $activity_stmt->bind_param("si", $activity_desc, $session_user_id);
        $activity_stmt->execute();
        $activity_stmt->close();

        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Order status updated successfully!";
    } else {
        // AUDIT LOG: Failed order status update
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'UPDATE_ORDER_STATUS',
            'module'      => 'Radiology',
            'table_name'  => 'radiology_orders',
            'entity_type' => 'radiology_order',
            'record_id'   => $order_id,
            'patient_id'  => $order['patient_id'],
            'visit_id'    => null,
            'description' => "Failed to update order #" . $order['order_number'] . " status. Error: " . $mysqli->error,
            'status'      => 'FAILED',
            'old_values'  => json_encode(['order_status' => $old_status]),
            'new_values'  => json_encode(['order_status' => $new_status])
        ]);

        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error updating order status: " . $mysqli->error;
    }
    
    header("Location: /clinic/radiology/radiology_order_details.php?id=" . $order_id);
    exit;
}

// Handle complete order
if (isset($_GET['complete_order'])) {
    $order_id = intval($_GET['complete_order']);
    
    // AUDIT LOG: Attempt to complete order
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'COMPLETE_ORDER',
        'module'      => 'Radiology',
        'table_name'  => 'radiology_orders',
        'entity_type' => 'radiology_order',
        'record_id'   => $order_id,
        'patient_id'  => null,
        'visit_id'    => null,
        'description' => "Attempting to complete order ID: " . $order_id,
        'status'      => 'ATTEMPT',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    // Verify order exists
    $check_sql = "SELECT ro.*, p.patient_first_name, p.patient_last_name 
                  FROM radiology_orders ro
                  LEFT JOIN patients p ON ro.patient_id = p.patient_id
                  WHERE ro.radiology_order_id = ?";
    $check_stmt = $mysqli->prepare($check_sql);
    $check_stmt->bind_param("i", $order_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows == 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Radiology order not found.";
        
        // AUDIT LOG: Order not found
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'COMPLETE_ORDER',
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
        
        header("Location: /clinic/radiology/radiology_orders.php");
        exit;
    }

    $order = $check_result->fetch_assoc();
    $old_status = $order['order_status'];

    // Check if all studies are completed
    $studies_sql = "SELECT COUNT(*) as total, 
                           SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
                    FROM radiology_order_studies 
                    WHERE radiology_order_id = ?";
    $studies_stmt = $mysqli->prepare($studies_sql);
    $studies_stmt->bind_param("i", $order_id);
    $studies_stmt->execute();
    $studies_result = $studies_stmt->get_result();
    $studies_data = $studies_result->fetch_assoc();

    if ($studies_data['total'] == 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Cannot complete order with no studies.";
        
        // AUDIT LOG: No studies in order
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'COMPLETE_ORDER',
            'module'      => 'Radiology',
            'table_name'  => 'radiology_orders',
            'entity_type' => 'radiology_order',
            'record_id'   => $order_id,
            'patient_id'  => $order['patient_id'],
            'visit_id'    => null,
            'description' => "Cannot complete order #" . $order['order_number'] . " - no studies found",
            'status'      => 'FAILED',
            'old_values'  => json_encode(['order_status' => $old_status]),
            'new_values'  => null
        ]);
        
        header("Location: /clinic/radiology/radiology_order_details.php?id=" . $order_id);
        exit;
    }

    if ($studies_data['completed'] != $studies_data['total']) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Cannot complete order. Not all studies are completed.";
        
        // AUDIT LOG: Not all studies completed
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'COMPLETE_ORDER',
            'module'      => 'Radiology',
            'table_name'  => 'radiology_orders',
            'entity_type' => 'radiology_order',
            'record_id'   => $order_id,
            'patient_id'  => $order['patient_id'],
            'visit_id'    => null,
            'description' => "Cannot complete order #" . $order['order_number'] . 
                            " - " . ($studies_data['total'] - $studies_data['completed']) . 
                            " of " . $studies_data['total'] . " studies pending",
            'status'      => 'FAILED',
            'old_values'  => json_encode(['order_status' => $old_status]),
            'new_values'  => null
        ]);
        
        header("Location: /clinic/radiology/radiology_order_details.php?id=" . $order_id);
        exit;
    }

    try {
        $mysqli->begin_transaction();

        // Update order status to Completed
        $update_sql = "UPDATE radiology_orders SET 
                      order_status = 'Completed',
                      updated_at = NOW()
                      WHERE radiology_order_id = ?";
        
        $update_stmt = $mysqli->prepare($update_sql);
        $update_stmt->bind_param("i", $order_id);
        
        if (!$update_stmt->execute()) {
            throw new Exception("Error completing order: " . $mysqli->error);
        }

        $mysqli->commit();

        // AUDIT LOG: Order completion successful
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'COMPLETE_ORDER',
            'module'      => 'Radiology',
            'table_name'  => 'radiology_orders',
            'entity_type' => 'radiology_order',
            'record_id'   => $order_id,
            'patient_id'  => $order['patient_id'],
            'visit_id'    => null,
            'description' => "Completed order #" . $order['order_number'] . 
                            " with " . $studies_data['total'] . " completed studies." .
                            " (Patient: " . $order['patient_first_name'] . " " . $order['patient_last_name'] . ")",
            'status'      => 'SUCCESS',
            'old_values'  => json_encode(['order_status' => $old_status]),
            'new_values'  => json_encode([
                'order_status' => 'Completed',
                'completed_studies' => $studies_data['total'],
                'patient_name' => $order['patient_first_name'] . ' ' . $order['patient_last_name']
            ])
        ]);

        // Log activity in activity_logs (existing log)
        $activity_desc = "Completed radiology order: " . $order['order_number'];
        $activity_sql = "INSERT INTO activity_logs SET 
                        activity_description = ?, 
                        activity_created_by = ?, 
                        activity_date = NOW()";
        $activity_stmt = $mysqli->prepare($activity_sql);
        $activity_stmt->bind_param("si", $activity_desc, $session_user_id);
        $activity_stmt->execute();
        $activity_stmt->close();

        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Order completed successfully!";
        header("Location: /clinic/radiology/radiology_order_details.php?id=" . $order_id);
        exit;

    } catch (Exception $e) {
        $mysqli->rollback();
        
        // AUDIT LOG: Failed order completion
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'COMPLETE_ORDER',
            'module'      => 'Radiology',
            'table_name'  => 'radiology_orders',
            'entity_type' => 'radiology_order',
            'record_id'   => $order_id,
            'patient_id'  => $order['patient_id'],
            'visit_id'    => null,
            'description' => "Failed to complete order #" . $order['order_number'] . 
                            ". Error: " . $e->getMessage(),
            'status'      => 'FAILED',
            'old_values'  => json_encode(['order_status' => $old_status]),
            'new_values'  => json_encode(['order_status' => 'Completed'])
        ]);

        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error completing order: " . $e->getMessage();
        header("Location: /clinic/radiology/radiology_order_details.php?id=" . $order_id);
        exit;
    }
}

// Handle complete study with findings (POST request)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_study'])) {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    $study_id = intval($_POST['study_id']);
    $findings = sanitizeInput($_POST['findings'] ?? '');
    $impression = sanitizeInput($_POST['impression'] ?? '');
    $recommendations = sanitizeInput($_POST['recommendations'] ?? '');

    // AUDIT LOG: Attempt to complete study with findings
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'COMPLETE_STUDY_DETAILED',
        'module'      => 'Radiology',
        'table_name'  => 'radiology_order_studies',
        'entity_type' => 'radiology_study',
        'record_id'   => $study_id,
        'patient_id'  => null,
        'visit_id'    => null,
        'description' => "Attempting to complete study ID " . $study_id . " with detailed findings",
        'status'      => 'ATTEMPT',
        'old_values'  => null,
        'new_values'  => null
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
            'table_name'  => 'radiology_order_studies',
            'entity_type' => 'radiology_study',
            'record_id'   => $study_id,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Invalid CSRF token when attempting to complete study ID " . $study_id,
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => null
        ]);
        
        header("Location: /clinic/radiology/radiology_orders.php");
        exit;
    }

    // Verify study exists
    $check_sql = "SELECT ros.*, ri.imaging_name, ri.imaging_code, ro.radiology_order_id, ro.order_number,
                         ro.patient_id, p.patient_first_name, p.patient_last_name
                  FROM radiology_order_studies ros
                  JOIN radiology_imagings ri ON ros.imaging_id = ri.imaging_id
                  JOIN radiology_orders ro ON ros.radiology_order_id = ro.radiology_order_id
                  LEFT JOIN patients p ON ro.patient_id = p.patient_id
                  WHERE ros.radiology_order_study_id = ?";
    $check_stmt = $mysqli->prepare($check_sql);
    $check_stmt->bind_param("i", $study_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows == 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Study not found.";
        
        // AUDIT LOG: Study not found
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'COMPLETE_STUDY_DETAILED',
            'module'      => 'Radiology',
            'table_name'  => 'radiology_order_studies',
            'entity_type' => 'radiology_study',
            'record_id'   => $study_id,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Study ID " . $study_id . " not found",
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => null
        ]);
        
        header("Location: /clinic/radiology/radiology_orders.php");
        exit;
    }

    $study = $check_result->fetch_assoc();
    $order_id = $study['radiology_order_id'];
    $old_findings = $study['findings'];
    $old_impression = $study['impression'];
    $old_recommendations = $study['recommendations'];
    $old_status = $study['status'];

    try {
        $mysqli->begin_transaction();

        // Update study with findings and mark as completed
        $update_sql = "UPDATE radiology_order_studies SET 
                      status = 'completed',
                      findings = ?,
                      impression = ?,
                      recommendations = ?,
                      performed_date = NOW(),
                      performed_by = ?,
                      updated_at = NOW()
                      WHERE radiology_order_study_id = ?";
        
        $update_stmt = $mysqli->prepare($update_sql);
        $update_stmt->bind_param("sssii", 
            $findings,
            $impression,
            $recommendations,
            $session_user_id,
            $study_id
        );
        
        if (!$update_stmt->execute()) {
            throw new Exception("Error completing study: " . $mysqli->error);
        }

        // Check if all studies are now completed to update order status
        $order_completed = false;
        $check_completion_sql = "SELECT COUNT(*) as total, 
                                        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
                                 FROM radiology_order_studies 
                                 WHERE radiology_order_id = ?";
        $check_completion_stmt = $mysqli->prepare($check_completion_sql);
        $check_completion_stmt->bind_param("i", $order_id);
        $check_completion_stmt->execute();
        $completion_result = $check_completion_stmt->get_result();
        $completion_data = $completion_result->fetch_assoc();
        
        if ($completion_data['total'] > 0 && $completion_data['completed'] == $completion_data['total']) {
            $update_order_sql = "UPDATE radiology_orders SET 
                               order_status = 'Completed',
                               updated_at = NOW()
                               WHERE radiology_order_id = ?";
            $update_order_stmt = $mysqli->prepare($update_order_sql);
            $update_order_stmt->bind_param("i", $order_id);
            $update_order_stmt->execute();
            $update_order_stmt->close();
            $order_completed = true;
        }

        $mysqli->commit();

        // AUDIT LOG: Study completed with findings
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'COMPLETE_STUDY_DETAILED',
            'module'      => 'Radiology',
            'table_name'  => 'radiology_order_studies',
            'entity_type' => 'radiology_study',
            'record_id'   => $study_id,
            'patient_id'  => $study['patient_id'],
            'visit_id'    => null,
            'description' => "Completed study '" . $study['imaging_name'] . "' (" . $study['imaging_code'] . 
                            ") with detailed findings for order #" . $study['order_number'] . 
                            ($order_completed ? ". Order marked as completed." : ""),
            'status'      => 'SUCCESS',
            'old_values'  => json_encode([
                'status' => $old_status,
                'findings' => $old_findings,
                'impression' => $old_impression,
                'recommendations' => $old_recommendations
            ]),
            'new_values'  => json_encode([
                'status' => 'completed',
                'findings_length' => strlen($findings),
                'impression_length' => strlen($impression),
                'recommendations_length' => strlen($recommendations),
                'performed_by' => $session_user_id,
                'order_completed' => $order_completed
            ])
        ]);

        // Log activity in activity_logs (existing log)
        $activity_desc = "Completed study with findings: " . $study['imaging_name'];
        $activity_sql = "INSERT INTO activity_logs SET 
                        activity_description = ?, 
                        activity_created_by = ?, 
                        activity_date = NOW()";
        $activity_stmt = $mysqli->prepare($activity_sql);
        $activity_stmt->bind_param("si", $activity_desc, $session_user_id);
        $activity_stmt->execute();
        $activity_stmt->close();

        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Study completed successfully with findings recorded!";
        header("Location: /clinic/radiology/radiology_order_details.php?id=" . $order_id);
        exit;

    } catch (Exception $e) {
        $mysqli->rollback();
        
        // AUDIT LOG: Failed to complete study with findings
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'COMPLETE_STUDY_DETAILED',
            'module'      => 'Radiology',
            'table_name'  => 'radiology_order_studies',
            'entity_type' => 'radiology_study',
            'record_id'   => $study_id,
            'patient_id'  => $study['patient_id'],
            'visit_id'    => null,
            'description' => "Failed to complete study '" . $study['imaging_name'] . "' with findings. Error: " . $e->getMessage(),
            'status'      => 'FAILED',
            'old_values'  => json_encode([
                'status' => $old_status,
                'findings' => $old_findings,
                'impression' => $old_impression,
                'recommendations' => $old_recommendations
            ]),
            'new_values'  => json_encode([
                'status' => 'completed',
                'findings' => $findings,
                'impression' => $impression,
                'recommendations' => $recommendations
            ])
        ]);

        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error completing study: " . $e->getMessage();
        header("Location: /clinic/radiology/radiology_order_details.php?id=" . $order_id);
        exit;
    }
}

// Default redirect if no action matched
// AUDIT LOG: No action matched
audit_log($mysqli, [
    'user_id'     => $_SESSION['user_id'] ?? null,
    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
    'action'      => 'UNKNOWN_ACTION',
    'module'      => 'Radiology',
    'table_name'  => 'radiology_orders',
    'entity_type' => 'radiology_order',
    'record_id'   => null,
    'patient_id'  => null,
    'visit_id'    => null,
    'description' => "No valid action specified in radiology_orders_action.php",
    'status'      => 'INFO',
    'old_values'  => null,
    'new_values'  => null
]);

header("Location: /clinic/radiology/radiology_orders.php");
exit;
?>
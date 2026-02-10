<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/audit_functions.php'; // Added for logging

$imaging_id = intval($_GET['imaging_id'] ?? 0);

// AUDIT LOG: Access attempt for deactivating imaging
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
    'description' => "Attempting to access imaging deactivation for imaging ID: " . $imaging_id,
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

// Get imaging details before deactivation for audit log
$imaging_sql = "SELECT * FROM radiology_imagings WHERE imaging_id = ?";
$imaging_stmt = $mysqli->prepare($imaging_sql);
$imaging_stmt->bind_param("i", $imaging_id);
$imaging_stmt->execute();
$imaging_result = $imaging_stmt->get_result();

if ($imaging_result->num_rows === 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Imaging not found.";
    
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
        'description' => "Imaging ID " . $imaging_id . " not found",
        'status'      => 'FAILED',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    header("Location: radiology_imaging.php");
    exit;
}

$imaging = $imaging_result->fetch_assoc();
$imaging_stmt->close();

// Prepare old values for audit log
$old_imaging_data = [
    'imaging_id' => $imaging['imaging_id'],
    'imaging_code' => $imaging['imaging_code'],
    'imaging_name' => $imaging['imaging_name'],
    'modality' => $imaging['modality'],
    'body_part' => $imaging['body_part'],
    'fee_amount' => $imaging['fee_amount'],
    'is_active' => $imaging['is_active'],
    'created_by' => $imaging['created_by'],
    'created_at' => $imaging['created_at']
];

// Prepare new values for audit log
$new_imaging_data = [
    'imaging_id' => $imaging['imaging_id'],
    'imaging_code' => $imaging['imaging_code'],
    'imaging_name' => $imaging['imaging_name'],
    'modality' => $imaging['modality'],
    'body_part' => $imaging['body_part'],
    'fee_amount' => $imaging['fee_amount'],
    'is_active' => 0, // Will be set to 0
    'created_by' => $imaging['created_by'],
    'created_at' => $imaging['created_at'],
    'updated_by' => $session_user_id ?? null,
    'updated_at' => date('Y-m-d H:i:s')
];

// AUDIT LOG: Attempt to deactivate imaging
audit_log($mysqli, [
    'user_id'     => $_SESSION['user_id'] ?? null,
    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
    'action'      => 'DEACTIVATE',
    'module'      => 'Radiology',
    'table_name'  => 'radiology_imagings',
    'entity_type' => 'radiology_imaging',
    'record_id'   => $imaging_id,
    'patient_id'  => null,
    'visit_id'    => null,
    'description' => "Attempting to deactivate radiology imaging: " . $imaging['imaging_name'] . " (" . $imaging['imaging_code'] . ")",
    'status'      => 'ATTEMPT',
    'old_values'  => json_encode($old_imaging_data),
    'new_values'  => json_encode($new_imaging_data)
]);

// Also need to check for associated billable item
$billable_item_sql = "SELECT item_id, item_code, item_name, is_active FROM billable_items 
                     WHERE source_table = 'radiology_imagings' AND source_id = ?";
$billable_stmt = $mysqli->prepare($billable_item_sql);
$billable_stmt->bind_param("i", $imaging_id);
$billable_stmt->execute();
$billable_result = $billable_stmt->get_result();
$has_billable_item = $billable_result->num_rows > 0;
$billable_item = $has_billable_item ? $billable_result->fetch_assoc() : null;
$billable_stmt->close();

// Start transaction
$mysqli->begin_transaction();

try {
    // Deactivate the imaging
    $update_sql = "UPDATE radiology_imagings SET is_active = 0, updated_by = ?, updated_at = NOW() WHERE imaging_id = ?";
    $update_stmt = $mysqli->prepare($update_sql);
    $update_stmt->bind_param("ii", $session_user_id, $imaging_id);
    
    if (!$update_stmt->execute()) {
        throw new Exception("Error deactivating radiology imaging: " . $mysqli->error);
    }
    $update_stmt->close();

    // If there's an associated billable item, deactivate it too
    if ($has_billable_item && $billable_item) {
        $update_billable_sql = "UPDATE billable_items SET is_active = 0, updated_by = ?, updated_at = NOW() WHERE item_id = ?";
        $update_billable_stmt = $mysqli->prepare($update_billable_sql);
        $update_billable_stmt->bind_param("ii", $session_user_id, $billable_item['item_id']);
        
        if (!$update_billable_stmt->execute()) {
            throw new Exception("Error deactivating associated billable item: " . $mysqli->error);
        }
        $update_billable_stmt->close();
        
        // AUDIT LOG: Billable item deactivation
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'DEACTIVATE',
            'module'      => 'Billing',
            'table_name'  => 'billable_items',
            'entity_type' => 'billable_item',
            'record_id'   => $billable_item['item_id'],
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Deactivated billable item for radiology imaging: " . $imaging['imaging_name'] . " (" . $imaging['imaging_code'] . ")",
            'status'      => 'SUCCESS',
            'old_values'  => json_encode(['is_active' => $billable_item['is_active']]),
            'new_values'  => json_encode(['is_active' => 0, 'updated_by' => $session_user_id, 'updated_at' => date('Y-m-d H:i:s')])
        ]);
    }

    // Log the activity in radiology_activity_logs
    $activity_sql = "INSERT INTO radiology_activity_logs SET 
                    imaging_id = ?, 
                    imaging_code = ?, 
                    user_id = ?, 
                    action = 'deactivate', 
                    description = ?, 
                    ip_address = ?, 
                    user_agent = ?";
    
    $activity_desc = "Deactivated radiology imaging: " . $imaging['imaging_name'] . " (" . $imaging['imaging_code'] . ")";
    if ($has_billable_item) {
        $activity_desc .= ". Associated billable item (ID: " . $billable_item['item_id'] . ") also deactivated.";
    }
    
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN';
    
    $activity_stmt = $mysqli->prepare($activity_sql);
    $activity_stmt->bind_param("isisss", $imaging_id, $imaging['imaging_code'], $session_user_id, $activity_desc, $ip_address, $user_agent);
    
    if (!$activity_stmt->execute()) {
        throw new Exception("Error logging activity: " . $mysqli->error);
    }
    $activity_stmt->close();

    // Commit transaction
    $mysqli->commit();

    // AUDIT LOG: Successful imaging deactivation
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'DEACTIVATE',
        'module'      => 'Radiology',
        'table_name'  => 'radiology_imagings',
        'entity_type' => 'radiology_imaging',
        'record_id'   => $imaging_id,
        'patient_id'  => null,
        'visit_id'    => null,
        'description' => "Successfully deactivated radiology imaging: " . $imaging['imaging_name'] . " (" . $imaging['imaging_code'] . ")" . 
                        ($has_billable_item ? " and associated billable item" : ""),
        'status'      => 'SUCCESS',
        'old_values'  => json_encode($old_imaging_data),
        'new_values'  => json_encode($new_imaging_data)
    ]);

    $_SESSION['alert_type'] = "success";
    $_SESSION['alert_message'] = "Radiology imaging deactivated successfully!" . 
                                ($has_billable_item ? " Associated billable item also deactivated." : "");

} catch (Exception $e) {
    // Rollback transaction on error
    $mysqli->rollback();
    
    // AUDIT LOG: Failed deactivation
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'DEACTIVATE',
        'module'      => 'Radiology',
        'table_name'  => 'radiology_imagings',
        'entity_type' => 'radiology_imaging',
        'record_id'   => $imaging_id,
        'patient_id'  => null,
        'visit_id'    => null,
        'description' => "Failed to deactivate radiology imaging: " . $imaging['imaging_name'] . ". Error: " . $e->getMessage(),
        'status'      => 'FAILED',
        'old_values'  => json_encode($old_imaging_data),
        'new_values'  => json_encode($new_imaging_data)
    ]);

    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = $e->getMessage();
}

header("Location: radiology_imaging_details.php?imaging_id=" . $imaging_id);
exit;
?>
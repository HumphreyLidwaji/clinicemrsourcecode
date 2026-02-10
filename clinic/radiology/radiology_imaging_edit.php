<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/audit_functions.php'; // Added for logging

// Get imaging ID from URL
$imaging_id = intval($_GET['imaging_id'] ?? 0);

// AUDIT LOG: Access attempt for editing imaging
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
    'description' => "Attempting to access radiology_imaging_edit.php for imaging ID: " . $imaging_id,
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

// Fetch imaging details for editing
$imaging_sql = "SELECT * FROM radiology_imagings WHERE imaging_id = ?";
$imaging_stmt = $mysqli->prepare($imaging_sql);
$imaging_stmt->bind_param("i", $imaging_id);
$imaging_stmt->execute();
$imaging_result = $imaging_stmt->get_result();

if ($imaging_result->num_rows === 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Radiology imaging not found.";
    
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

// AUDIT LOG: Successful access to edit imaging page
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
    'description' => "Accessed radiology_imaging_edit.php for imaging: " . $imaging['imaging_name'] . " (" . $imaging['imaging_code'] . ")",
    'status'      => 'SUCCESS',
    'old_values'  => null,
    'new_values'  => null
]);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    $imaging_code = sanitizeInput($_POST['imaging_code']);
    $imaging_name = sanitizeInput($_POST['imaging_name']);
    $imaging_description = sanitizeInput($_POST['imaging_description']);
    $modality = sanitizeInput($_POST['modality']);
    $body_part = sanitizeInput($_POST['body_part']);
    $preparation_instructions = sanitizeInput($_POST['preparation_instructions']);
    $contrast_required = sanitizeInput($_POST['contrast_required']);
    $fee_amount = floatval($_POST['fee_amount']);
    $duration_minutes = intval($_POST['duration_minutes']);
    $radiation_dose = sanitizeInput($_POST['radiation_dose']);
    $report_template = sanitizeInput($_POST['report_template']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    // Prepare imaging data for audit log
    $imaging_data = [
        'imaging_code' => $imaging_code,
        'imaging_name' => $imaging_name,
        'imaging_description' => $imaging_description,
        'modality' => $modality,
        'body_part' => $body_part,
        'preparation_instructions' => $preparation_instructions,
        'contrast_required' => $contrast_required,
        'fee_amount' => $fee_amount,
        'duration_minutes' => $duration_minutes,
        'radiation_dose' => $radiation_dose,
        'report_template' => $report_template,
        'is_active' => $is_active,
        'updated_by' => $session_user_id ?? null
    ];
    
    // Store old values for audit log
    $old_imaging_data = [
        'imaging_code' => $imaging['imaging_code'],
        'imaging_name' => $imaging['imaging_name'],
        'imaging_description' => $imaging['imaging_description'],
        'modality' => $imaging['modality'],
        'body_part' => $imaging['body_part'],
        'preparation_instructions' => $imaging['preparation_instructions'],
        'contrast_required' => $imaging['contrast_required'],
        'fee_amount' => $imaging['fee_amount'],
        'duration_minutes' => $imaging['duration_minutes'],
        'radiation_dose' => $imaging['radiation_dose'],
        'report_template' => $imaging['report_template'],
        'is_active' => $imaging['is_active']
    ];

    // AUDIT LOG: Attempt to update radiology imaging
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'UPDATE',
        'module'      => 'Radiology',
        'table_name'  => 'radiology_imagings',
        'entity_type' => 'radiology_imaging',
        'record_id'   => $imaging_id,
        'patient_id'  => null,
        'visit_id'    => null,
        'description' => "Attempting to update radiology imaging: " . $old_imaging_data['imaging_name'] . " (" . $old_imaging_data['imaging_code'] . ")",
        'status'      => 'ATTEMPT',
        'old_values'  => json_encode($old_imaging_data),
        'new_values'  => json_encode($imaging_data)
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
            'table_name'  => 'radiology_imagings',
            'entity_type' => 'radiology_imaging',
            'record_id'   => $imaging_id,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Invalid CSRF token when attempting to update radiology imaging: " . $old_imaging_data['imaging_name'],
            'status'      => 'FAILED',
            'old_values'  => json_encode($old_imaging_data),
            'new_values'  => json_encode($imaging_data)
        ]);
        
        header("Location: radiology_imaging_edit.php?imaging_id=" . $imaging_id);
        exit;
    }

    // Validate required fields
    if (empty($imaging_code) || empty($imaging_name) || empty($modality) || $fee_amount < 0 || $duration_minutes <= 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please fill in all required fields with valid values.";
        
        // AUDIT LOG: Validation failed - missing required fields
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'VALIDATION_FAILED',
            'module'      => 'Radiology',
            'table_name'  => 'radiology_imagings',
            'entity_type' => 'radiology_imaging',
            'record_id'   => $imaging_id,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Validation failed: Missing required fields or invalid values when updating radiology imaging: " . $old_imaging_data['imaging_name'],
            'status'      => 'FAILED',
            'old_values'  => json_encode($old_imaging_data),
            'new_values'  => json_encode($imaging_data)
        ]);
        
        header("Location: radiology_imaging_edit.php?imaging_id=" . $imaging_id);
        exit;
    }

    // Check if imaging code already exists (excluding current record)
    $check_sql = "SELECT imaging_id FROM radiology_imagings WHERE imaging_code = ? AND imaging_id != ? AND is_active = 1";
    $check_stmt = $mysqli->prepare($check_sql);
    $check_stmt->bind_param("si", $imaging_code, $imaging_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($check_result->num_rows > 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Imaging code already exists. Please use a unique imaging code.";
        
        // AUDIT LOG: Validation failed - duplicate imaging code
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'VALIDATION_FAILED',
            'module'      => 'Radiology',
            'table_name'  => 'radiology_imagings',
            'entity_type' => 'radiology_imaging',
            'record_id'   => $imaging_id,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Validation failed: Duplicate imaging code '" . $imaging_code . "' when updating radiology imaging: " . $old_imaging_data['imaging_name'],
            'status'      => 'FAILED',
            'old_values'  => json_encode($old_imaging_data),
            'new_values'  => json_encode($imaging_data)
        ]);
        
        header("Location: radiology_imaging_edit.php?imaging_id=" . $imaging_id);
        exit;
    }
    $check_stmt->close();

    // Start transaction
    $mysqli->begin_transaction();

    try {
        // Update imaging
        $update_sql = "UPDATE radiology_imagings SET 
                      imaging_code = ?, 
                      imaging_name = ?, 
                      imaging_description = ?, 
                      modality = ?, 
                      body_part = ?, 
                      preparation_instructions = ?, 
                      contrast_required = ?, 
                      fee_amount = ?, 
                      duration_minutes = ?, 
                      radiation_dose = ?, 
                      report_template = ?, 
                      is_active = ?,
                      updated_by = ?,
                      updated_at = NOW()
                      WHERE imaging_id = ?";
        
        $update_stmt = $mysqli->prepare($update_sql);
        $update_stmt->bind_param(
            "sssssssdisssii", 
            $imaging_code, $imaging_name, $imaging_description, $modality, $body_part, 
            $preparation_instructions, $contrast_required, $fee_amount, $duration_minutes, 
            $radiation_dose, $report_template, $is_active, $session_user_id, $imaging_id
        );

        if (!$update_stmt->execute()) {
            throw new Exception("Error updating radiology imaging: " . $mysqli->error);
        }
        $update_stmt->close();

        // Also update associated billable item if fee changed
        $billable_item_sql = "SELECT item_id, unit_price FROM billable_items 
                             WHERE source_table = 'radiology_imagings' AND source_id = ?";
        $billable_stmt = $mysqli->prepare($billable_item_sql);
        $billable_stmt->bind_param("i", $imaging_id);
        $billable_stmt->execute();
        $billable_result = $billable_stmt->get_result();
        
        if ($billable_result->num_rows > 0) {
            $billable_item = $billable_result->fetch_assoc();
            
            // Update billable item if fee changed
            if ($billable_item['unit_price'] != $fee_amount) {
                $update_billable_sql = "UPDATE billable_items SET 
                                       item_code = ?,
                                       item_name = ?,
                                       item_description = ?,
                                       unit_price = ?,
                                       updated_by = ?,
                                       updated_at = NOW()
                                       WHERE item_id = ?";
                
                $update_billable_stmt = $mysqli->prepare($update_billable_sql);
                $update_billable_stmt->bind_param(
                    "sssdii",
                    $imaging_code,
                    $imaging_name,
                    $imaging_description,
                    $fee_amount,
                    $session_user_id,
                    $billable_item['item_id']
                );
                
                if (!$update_billable_stmt->execute()) {
                    throw new Exception("Error updating associated billable item: " . $mysqli->error);
                }
                $update_billable_stmt->close();
                
                // AUDIT LOG: Billable item update
                audit_log($mysqli, [
                    'user_id'     => $_SESSION['user_id'] ?? null,
                    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                    'action'      => 'UPDATE',
                    'module'      => 'Billing',
                    'table_name'  => 'billable_items',
                    'entity_type' => 'billable_item',
                    'record_id'   => $billable_item['item_id'],
                    'patient_id'  => null,
                    'visit_id'    => null,
                    'description' => "Updated billable item for radiology imaging: " . $old_imaging_data['imaging_name'] . ". Price updated from $" . number_format($billable_item['unit_price'], 2) . " to $" . number_format($fee_amount, 2),
                    'status'      => 'SUCCESS',
                    'old_values'  => json_encode([
                        'item_code' => $old_imaging_data['imaging_code'],
                        'item_name' => $old_imaging_data['imaging_name'],
                        'item_description' => $old_imaging_data['imaging_description'],
                        'unit_price' => $billable_item['unit_price']
                    ]),
                    'new_values'  => json_encode([
                        'item_code' => $imaging_code,
                        'item_name' => $imaging_name,
                        'item_description' => $imaging_description,
                        'unit_price' => $fee_amount,
                        'updated_by' => $session_user_id,
                        'updated_at' => date('Y-m-d H:i:s')
                    ])
                ]);
            }
        }
        $billable_stmt->close();

        // Track changes for logging
        $changes = [];
        if ($old_imaging_data['imaging_code'] != $imaging_code) $changes[] = "Imaging code: {$old_imaging_data['imaging_code']} → {$imaging_code}";
        if ($old_imaging_data['imaging_name'] != $imaging_name) $changes[] = "Imaging name: {$old_imaging_data['imaging_name']} → {$imaging_name}";
        if ($old_imaging_data['modality'] != $modality) $changes[] = "Modality: {$old_imaging_data['modality']} → {$modality}";
        if ($old_imaging_data['fee_amount'] != $fee_amount) $changes[] = "Fee: $" . number_format($old_imaging_data['fee_amount'], 2) . " → $" . number_format($fee_amount, 2);
        if ($old_imaging_data['duration_minutes'] != $duration_minutes) $changes[] = "Duration: {$old_imaging_data['duration_minutes']}min → {$duration_minutes}min";
        if ($old_imaging_data['is_active'] != $is_active) $changes[] = "Status: " . ($old_imaging_data['is_active'] ? 'Active' : 'Inactive') . " → " . ($is_active ? 'Active' : 'Inactive');

        // Log the activity in radiology_activity_logs
        $activity_sql = "INSERT INTO radiology_activity_logs SET 
                        imaging_id = ?, 
                        imaging_code = ?, 
                        user_id = ?, 
                        action = 'edit', 
                        description = ?, 
                        ip_address = ?, 
                        user_agent = ?";
        
        $activity_desc = "Updated radiology imaging: " . $imaging_name . " (" . $imaging_code . ")";
        if (!empty($changes)) {
            $activity_desc .= ". Changes: " . implode(", ", $changes);
        }
        
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN';
        
        $activity_stmt = $mysqli->prepare($activity_sql);
        $activity_stmt->bind_param("isisss", $imaging_id, $imaging_code, $session_user_id, $activity_desc, $ip_address, $user_agent);
        
        if (!$activity_stmt->execute()) {
            throw new Exception("Error logging activity: " . $mysqli->error);
        }
        $activity_stmt->close();

        // Commit transaction
        $mysqli->commit();

        // AUDIT LOG: Successful imaging update
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'UPDATE',
            'module'      => 'Radiology',
            'table_name'  => 'radiology_imagings',
            'entity_type' => 'radiology_imaging',
            'record_id'   => $imaging_id,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Radiology imaging updated: " . $old_imaging_data['imaging_name'] . " (" . $old_imaging_data['imaging_code'] . "). " . (!empty($changes) ? "Changes: " . implode(", ", $changes) : "No significant changes detected"),
            'status'      => 'SUCCESS',
            'old_values'  => json_encode($old_imaging_data),
            'new_values'  => json_encode(array_merge($imaging_data, [
                'imaging_id' => $imaging_id,
                'updated_at' => date('Y-m-d H:i:s')
            ]))
        ]);

        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Radiology imaging updated successfully!";
        header("Location: radiology_imaging_details.php?imaging_id=" . $imaging_id);
        exit;

    } catch (Exception $e) {
        // Rollback transaction on error
        $mysqli->rollback();
        
        // AUDIT LOG: Failed imaging update
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'UPDATE',
            'module'      => 'Radiology',
            'table_name'  => 'radiology_imagings',
            'entity_type' => 'radiology_imaging',
            'record_id'   => $imaging_id,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Failed to update radiology imaging: " . $old_imaging_data['imaging_name'] . ". Error: " . $e->getMessage(),
            'status'      => 'FAILED',
            'old_values'  => json_encode($old_imaging_data),
            'new_values'  => json_encode($imaging_data)
        ]);

        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error updating radiology imaging: " . $e->getMessage();
        header("Location: radiology_imaging_edit.php?imaging_id=" . $imaging_id);
        exit;
    }
}

// Get activity logs from radiology_activity_logs
$activity_sql = "SELECT ral.*, u.user_name 
                 FROM radiology_activity_logs ral
                 LEFT JOIN users u ON ral.user_id = u.user_id
                 WHERE ral.imaging_id = ? 
                 ORDER BY ral.created_at DESC 
                 LIMIT 5";
$activity_stmt = $mysqli->prepare($activity_sql);
$activity_stmt->bind_param("i", $imaging_id);
$activity_stmt->execute();
$activity_result = $activity_stmt->get_result();
?>

<div class="card">
    <div class="card-header bg-info py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-x-ray mr-2"></i>Radiology Imaging Details
            </h3>
            <div class="card-tools">
                <div class="btn-group">
                    <a href="radiology_imaging_edit.php?imaging_id=<?php echo $imaging_id; ?>" class="btn btn-warning">
                        <i class="fas fa-edit mr-2"></i>Edit
                    </a>
                    <a href="radiology_imaging.php" class="btn btn-light">
                        <i class="fas fa-arrow-left mr-2"></i>Back to List
                    </a>
                </div>
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

        <div class="row">
            <div class="col-md-8">
                <!-- Imaging Overview -->
                <div class="card card-primary">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-info-circle mr-2"></i>Imaging Overview</h3>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <table class="table table-bordered">
                                    <tr>
                                        <th class="bg-light" style="width: 40%">Imaging Code</th>
                                        <td>
                                            <span class="font-weight-bold text-primary"><?php echo htmlspecialchars($imaging['imaging_code']); ?></span>
                                            <?php if (!$imaging['is_active']): ?>
                                                <span class="badge badge-danger ml-2">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light">Imaging Name</th>
                                        <td class="font-weight-bold"><?php echo htmlspecialchars($imaging['imaging_name']); ?></td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light">Modality</th>
                                        <td>
                                            <span class="badge badge-primary"><?php echo htmlspecialchars($imaging['modality']); ?></span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light">Body Part</th>
                                        <td><?php echo htmlspecialchars($imaging['body_part'] ?: 'Not specified'); ?></td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <table class="table table-bordered">
                                    <tr>
                                        <th class="bg-light" style="width: 40%">Fee Amount</th>
                                        <td class="font-weight-bold text-success">$<?php echo number_format($imaging['fee_amount'], 2); ?></td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light">Duration</th>
                                        <td>
                                            <span class="badge badge-info"><?php echo intval($imaging['duration_minutes']); ?> minutes</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light">Contrast Required</th>
                                        <td>
                                            <?php if ($imaging['contrast_required'] && $imaging['contrast_required'] !== 'None'): ?>
                                                <span class="badge badge-warning"><?php echo htmlspecialchars($imaging['contrast_required']); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">None</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light">Radiation Dose</th>
                                        <td><?php echo htmlspecialchars($imaging['radiation_dose'] ?: 'Not specified'); ?></td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <?php if ($imaging['imaging_description']): ?>
                        <div class="form-group">
                            <label class="font-weight-bold">Description</label>
                            <div class="border rounded p-3 bg-light">
                                <?php echo nl2br(htmlspecialchars($imaging['imaging_description'])); ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Preparation Instructions -->
                <?php if ($imaging['preparation_instructions']): ?>
                <div class="card card-warning">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-clipboard-list mr-2"></i>Preparation Instructions</h3>
                    </div>
                    <div class="card-body">
                        <div class="border rounded p-3 bg-light">
                            <?php echo nl2br(htmlspecialchars($imaging['preparation_instructions'])); ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Report Template -->
                <?php if ($imaging['report_template']): ?>
                <div class="card card-success">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-file-medical mr-2"></i>Report Template</h3>
                    </div>
                    <div class="card-body">
                        <div class="border rounded p-3 bg-light font-monospace small">
                            <?php echo nl2br(htmlspecialchars($imaging['report_template'])); ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="col-md-4">
                <!-- Quick Actions -->
                <div class="card card-success">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-bolt mr-2"></i>Quick Actions</h3>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="radiology_imaging_edit.php?imaging_id=<?php echo $imaging_id; ?>" class="btn btn-warning">
                                <i class="fas fa-edit mr-2"></i>Edit Imaging
                            </a>
                            <a href="radiology_imaging.php" class="btn btn-outline-primary">
                                <i class="fas fa-list mr-2"></i>View All Imaging
                            </a>
                            <a href="radiology_imaging_add.php" class="btn btn-outline-success">
                                <i class="fas fa-plus mr-2"></i>Add New Imaging
                            </a>
                            <?php if ($imaging['is_active']): ?>
                                <button type="button" class="btn btn-outline-danger" onclick="confirmDeactivate()">
                                    <i class="fas fa-times mr-2"></i>Deactivate
                                </button>
                            <?php else: ?>
                                <button type="button" class="btn btn-outline-success" onclick="confirmActivate()">
                                    <i class="fas fa-check mr-2"></i>Activate
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Status Information -->
                <div class="card card-info">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-chart-bar mr-2"></i>Status Information</h3>
                    </div>
                    <div class="card-body">
                        <div class="list-group list-group-flush">
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                                Status
                                <span class="badge badge-<?php echo $imaging['is_active'] ? 'success' : 'danger'; ?>">
                                    <?php echo $imaging['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </div>
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                                Created By
                                <span class="text-muted"><?php echo htmlspecialchars($imaging['created_by_name'] ?: 'System'); ?></span>
                            </div>
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                                Created Date
                                <span class="text-muted"><?php echo date('M j, Y g:i A', strtotime($imaging['created_at'])); ?></span>
                            </div>
                            <?php if ($imaging['updated_at']): ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                                Last Updated
                                <span class="text-muted"><?php echo date('M j, Y g:i A', strtotime($imaging['updated_at'])); ?></span>
                            </div>
                            <?php if ($imaging['updated_by_name']): ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                                Updated By
                                <span class="text-muted"><?php echo htmlspecialchars($imaging['updated_by_name']); ?></span>
                            </div>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div class="card card-secondary">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-history mr-2"></i>Recent Activity</h3>
                    </div>
                    <div class="card-body">
                        <?php if ($activity_result->num_rows > 0): ?>
                            <div class="list-group list-group-flush">
                                <?php while ($activity = $activity_result->fetch_assoc()): ?>
                                    <div class="list-group-item px-0 py-2">
                                        <div class="d-flex w-100 justify-content-between">
                                            <small class="text-primary"><?php echo htmlspecialchars($activity['user_name'] ?: 'System'); ?></small>
                                            <small class="text-muted"><?php echo date('M j, g:i A', strtotime($activity['activity_date'])); ?></small>
                                        </div>
                                        <p class="mb-1 small"><?php echo htmlspecialchars($activity['activity_description']); ?></p>
                                    </div>
                                <?php endwhile; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-muted mb-0 text-center">
                                <i class="fas fa-info-circle mr-1"></i>
                                No recent activity
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function confirmDeactivate() {
    if (confirm('Are you sure you want to deactivate this imaging? It will no longer be available for ordering.')) {
        window.location.href = 'radiology_imaging_deactivate.php?imaging_id=<?php echo $imaging_id; ?>';
    }
}

function confirmActivate() {
    if (confirm('Are you sure you want to activate this imaging? It will be available for ordering.')) {
        window.location.href = 'radiology_imaging_activate.php?imaging_id=<?php echo $imaging_id; ?>';
    }
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + E to edit
    if (e.ctrlKey && e.keyCode === 69) {
        e.preventDefault();
        window.location.href = 'radiology_imaging_edit.php?imaging_id=<?php echo $imaging_id; ?>';
    }
    // Ctrl + L to go back to list
    if (e.ctrlKey && e.keyCode === 76) {
        e.preventDefault();
        window.location.href = 'radiology_imaging.php';
    }
});
</script>

<style>
.card {
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    margin-bottom: 1rem;
}
.table th {
    font-weight: 600;
}
.font-monospace {
    font-family: 'Courier New', monospace;
}
</style>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
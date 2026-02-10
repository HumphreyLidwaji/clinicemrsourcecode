<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/audit_functions.php'; // Added for logging

// Get order ID from URL
$order_id = intval($_GET['order_id']);

// AUDIT LOG: Access attempt for scheduling study
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
    'description' => "Attempting to access study scheduling page for radiology order ID: " . $order_id,
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

// AUDIT LOG: Successful access to schedule study page
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
    'description' => "Accessed study scheduling page for radiology order #" . $order['order_number'] . 
                    " (Patient: " . $order['patient_first_name'] . " " . $order['patient_last_name'] . ")",
    'status'      => 'SUCCESS',
    'old_values'  => null,
    'new_values'  => null
]);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    $imaging_id = intval($_POST['imaging_id']);
    $scheduled_date = sanitizeInput($_POST['scheduled_date']);
    $radiologist_id = !empty($_POST['radiologist_id']) ? intval($_POST['radiologist_id']) : null;
    $study_notes = sanitizeInput($_POST['study_notes'] ?? '');

    // Prepare study data for audit log
    $study_data = [
        'order_id' => $order_id,
        'imaging_id' => $imaging_id,
        'scheduled_date' => $scheduled_date,
        'radiologist_id' => $radiologist_id,
        'study_notes' => $study_notes,
        'status' => 'scheduled',
        'created_by' => $session_user_id ?? null
    ];
    
    // Store old order values for audit log
    $old_order_data = [
        'order_status' => $order['order_status'],
        'radiologist_id' => $order['radiologist_id']
    ];

    // AUDIT LOG: Attempt to schedule study
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'SCHEDULE_STUDY',
        'module'      => 'Radiology',
        'table_name'  => 'radiology_order_studies',
        'entity_type' => 'radiology_study',
        'record_id'   => $order_id,
        'patient_id'  => $order['patient_id'],
        'visit_id'    => $order['visit_id'] ?? null,
        'description' => "Attempting to schedule study for radiology order #" . $order['order_number'] . 
                        " (Patient: " . $order['patient_first_name'] . " " . $order['patient_last_name'] . ")",
        'status'      => 'ATTEMPT',
        'old_values'  => json_encode($old_order_data),
        'new_values'  => json_encode($study_data)
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
            'record_id'   => $order_id,
            'patient_id'  => $order['patient_id'],
            'visit_id'    => $order['visit_id'] ?? null,
            'description' => "Invalid CSRF token when attempting to schedule study for order #" . $order['order_number'],
            'status'      => 'FAILED',
            'old_values'  => json_encode($old_order_data),
            'new_values'  => json_encode($study_data)
        ]);
        
        header("Location: radiology_schedule_study.php?order_id=" . $order_id);
        exit;
    }

    // Validate required fields
    if (empty($imaging_id) || empty($scheduled_date)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please fill in all required fields.";
        
        // AUDIT LOG: Validation failed - missing required fields
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'VALIDATION_FAILED',
            'module'      => 'Radiology',
            'table_name'  => 'radiology_order_studies',
            'entity_type' => 'radiology_study',
            'record_id'   => $order_id,
            'patient_id'  => $order['patient_id'],
            'visit_id'    => $order['visit_id'] ?? null,
            'description' => "Validation failed: Missing required fields when scheduling study for order #" . $order['order_number'],
            'status'      => 'FAILED',
            'old_values'  => json_encode($old_order_data),
            'new_values'  => json_encode($study_data)
        ]);
        
        header("Location: radiology_schedule_study.php?order_id=" . $order_id);
        exit;
    }

    // Verify imaging study exists
    $imaging_sql = "SELECT imaging_id, imaging_name, imaging_code, fee_amount 
                   FROM radiology_imagings 
                   WHERE imaging_id = ? AND is_active = 1";
    $imaging_stmt = $mysqli->prepare($imaging_sql);
    $imaging_stmt->bind_param("i", $imaging_id);
    $imaging_stmt->execute();
    $imaging_result = $imaging_stmt->get_result();
    
    if ($imaging_result->num_rows == 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Selected imaging study not found or inactive.";
        
        // AUDIT LOG: Imaging study not found
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'VALIDATION_FAILED',
            'module'      => 'Radiology',
            'table_name'  => 'radiology_order_studies',
            'entity_type' => 'radiology_study',
            'record_id'   => $order_id,
            'patient_id'  => $order['patient_id'],
            'visit_id'    => $order['visit_id'] ?? null,
            'description' => "Validation failed: Imaging study ID " . $imaging_id . " not found or inactive when scheduling for order #" . $order['order_number'],
            'status'      => 'FAILED',
            'old_values'  => json_encode($old_order_data),
            'new_values'  => json_encode($study_data)
        ]);
        
        header("Location: radiology_schedule_study.php?order_id=" . $order_id);
        exit;
    }

    $imaging_data = $imaging_result->fetch_assoc();
    
    // Add imaging details to study data for audit log
    $study_data['imaging_name'] = $imaging_data['imaging_name'];
    $study_data['imaging_code'] = $imaging_data['imaging_code'];
    $study_data['fee_amount'] = $imaging_data['fee_amount'];

    try {
        $mysqli->begin_transaction();

        // Insert the study
        $insert_sql = "INSERT INTO radiology_order_studies SET 
                      radiology_order_id = ?,
                      imaging_id = ?,
                      scheduled_date = ?,
                      study_notes = ?,
                      status = 'scheduled',
                      created_by = ?,
                      created_at = NOW()";
        
        $insert_stmt = $mysqli->prepare($insert_sql);
        $insert_stmt->bind_param("iissi", 
            $order_id,
            $imaging_id,
            $scheduled_date,
            $study_notes,
            $session_user_id
        );
        
        if (!$insert_stmt->execute()) {
            throw new Exception("Error scheduling study: " . $mysqli->error);
        }
        
        $study_id = $insert_stmt->insert_id;
        $insert_stmt->close();
        
        // Update study_data with study_id for audit log
        $study_data['study_id'] = $study_id;

        // Update radiologist if assigned
        if ($radiologist_id) {
            $update_radiologist_sql = "UPDATE radiology_orders SET 
                                      radiologist_id = ?,
                                      updated_by = ?,
                                      updated_at = NOW()
                                      WHERE radiology_order_id = ?";
            $update_radiologist_stmt = $mysqli->prepare($update_radiologist_sql);
            $update_radiologist_stmt->bind_param("iii", $radiologist_id, $session_user_id, $order_id);
            $update_radiologist_stmt->execute();
            $update_radiologist_stmt->close();
            
            // Add radiologist assignment to study data
            $study_data['radiologist_assigned'] = true;
        }

        // Update order status to Scheduled if it was Pending
        $new_order_status = 'Scheduled';
        $old_order_status = $order['order_status'];
        
        $update_status_sql = "UPDATE radiology_orders SET 
                             order_status = ?,
                             updated_by = ?,
                             updated_at = NOW()
                             WHERE radiology_order_id = ? AND order_status = 'Pending'";
        $update_status_stmt = $mysqli->prepare($update_status_sql);
        $update_status_stmt->bind_param("sii", $new_order_status, $session_user_id, $order_id);
        $update_status_stmt->execute();
        $update_status_stmt->close();
        
        // Update old order data with new status
        $old_order_data['order_status'] = $old_order_status;
        $study_data['old_order_status'] = $old_order_status;
        $study_data['new_order_status'] = $new_order_status;

        $mysqli->commit();

        // AUDIT LOG: Successful study scheduling
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'SCHEDULE_STUDY',
            'module'      => 'Radiology',
            'table_name'  => 'radiology_order_studies',
            'entity_type' => 'radiology_study',
            'record_id'   => $study_id,
            'patient_id'  => $order['patient_id'],
            'visit_id'    => $order['visit_id'] ?? null,
            'description' => "Study scheduled successfully for radiology order #" . $order['order_number'] . 
                            ". Imaging: " . $imaging_data['imaging_name'] . 
                            " (" . $imaging_data['imaging_code'] . ")" .
                            ". Scheduled for: " . date('M j, Y g:i A', strtotime($scheduled_date)) .
                            ". Order status changed from '" . $old_order_status . "' to '" . $new_order_status . "'",
            'status'      => 'SUCCESS',
            'old_values'  => json_encode($old_order_data),
            'new_values'  => json_encode(array_merge($study_data, [
                'study_id' => $study_id,
                'created_at' => date('Y-m-d H:i:s')
            ]))
        ]);

        // Log activity in activity_logs (existing log)
        $activity_desc = "Scheduled radiology study: " . $imaging_data['imaging_name'] . 
                        " (" . $imaging_data['imaging_code'] . ")" .
                        " for order #" . $order['order_number'] . 
                        " on " . date('M j, Y g:i A', strtotime($scheduled_date));
        
        $activity_sql = "INSERT INTO activity_logs SET 
                        activity_description = ?, 
                        activity_created_by = ?, 
                        activity_date = NOW()";
        $activity_stmt = $mysqli->prepare($activity_sql);
        $activity_stmt->bind_param("si", $activity_desc, $session_user_id);
        $activity_stmt->execute();
        $activity_stmt->close();

        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Study '" . $imaging_data['imaging_name'] . "' scheduled successfully!";
        header("Location: radiology_order_details.php?id=" . $order_id);
        exit;

    } catch (Exception $e) {
        $mysqli->rollback();
        
        // AUDIT LOG: Failed study scheduling
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'SCHEDULE_STUDY',
            'module'      => 'Radiology',
            'table_name'  => 'radiology_order_studies',
            'entity_type' => 'radiology_study',
            'record_id'   => $order_id,
            'patient_id'  => $order['patient_id'],
            'visit_id'    => $order['visit_id'] ?? null,
            'description' => "Failed to schedule study for radiology order #" . $order['order_number'] . 
                            ". Error: " . $e->getMessage(),
            'status'      => 'FAILED',
            'old_values'  => json_encode($old_order_data),
            'new_values'  => json_encode($study_data)
        ]);

        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error scheduling study: " . $e->getMessage();
        header("Location: radiology_schedule_study.php?order_id=" . $order_id);
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
    <div class="card-header bg-info py-2">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h3 class="card-title mt-2 mb-0">
                    <i class="fas fa-fw fa-calendar-plus mr-2"></i>Schedule Radiology Study
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

        <div class="row">
            <!-- Order Information -->
            <div class="col-md-6">
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
                                    <?php
                                    $priority_badge = "";
                                    switch($order['order_priority']) {
                                        case 'stat':
                                            $priority_badge = "badge-danger";
                                            break;
                                        case 'urgent':
                                            $priority_badge = "badge-warning";
                                            break;
                                        case 'routine':
                                            $priority_badge = "badge-success";
                                            break;
                                        default:
                                            $priority_badge = "badge-light";
                                    }
                                    ?>
                                    <span class="badge <?php echo $priority_badge; ?>"><?php echo ucfirst($order['order_priority']); ?></span>
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
                        </table>
                    </div>
                </div>

                <!-- Clinical Information -->
                <div class="card card-warning mt-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-stethoscope mr-2"></i>Clinical Information</h3>
                    </div>
                    <div class="card-body">
                        <h6>Clinical Notes:</h6>
                        <p><?php echo !empty($order['clinical_notes']) ? nl2br(htmlspecialchars($order['clinical_notes'])) : 'No clinical notes provided.'; ?></p>
                        
                        <h6>Instructions:</h6>
                        <p><?php echo !empty($order['instructions']) ? nl2br(htmlspecialchars($order['instructions'])) : 'No special instructions.'; ?></p>
                        
                        <?php if ($order['contrast_required']): ?>
                            <div class="alert alert-info mt-2">
                                <i class="fas fa-info-circle mr-2"></i>
                                <strong>Contrast Required:</strong> <?php echo htmlspecialchars($order['contrast_type']); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Schedule Study Form -->
            <div class="col-md-6">
                <div class="card card-success">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-calendar-plus mr-2"></i>Schedule New Study</h3>
                    </div>
                    <div class="card-body">
                        <form method="post" autocomplete="off">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
<div class="form-group">
    <label for="imaging_id">Select Imaging Study *</label>
    <select class="form-control select2" id="imaging_id" name="imaging_id" required>
        <option value="">- Select Imaging Study -</option>
        <?php
        // Fetch available imaging studies
        $imaging_sql = "SELECT 
                            imaging_id, 
                            imaging_code, 
                            imaging_name, 
                            fee_amount, 
                            imaging_description 
                        FROM radiology_imagings 
                        WHERE is_active = 1 
                        ORDER BY imaging_name";

        $imaging_result = $mysqli->query($imaging_sql);

        if ($imaging_result && $imaging_result->num_rows > 0) {
            while ($imaging = $imaging_result->fetch_assoc()):
                
                $fee_amount = floatval($imaging['fee_amount']);
                $description = htmlspecialchars($imaging['imaging_description'] ?? '');

        ?>
            <option value="<?php echo $imaging['imaging_id']; ?>" 
                    data-fee="<?php echo $fee_amount; ?>"
                    data-description="<?php echo $description; ?>">
                <?php echo htmlspecialchars($imaging['imaging_name']); ?> 
                (<?php echo htmlspecialchars($imaging['imaging_code']); ?>)
                - $<?php echo number_format($fee_amount, 2); ?>
            </option>
        <?php
            endwhile;
        } else {
            echo '<option value="">No imaging studies available</option>';
        }
        ?>
    </select>
</div>


                            <!-- Imaging Study Details -->
                            <div id="imagingDetails" class="card mb-3" style="display: none;">
                                <div class="card-body">
                                    <h6 class="card-title">Study Information</h6>
                                    <div class="row">
                                        <div class="col-md-12">
                                            <p><strong>Description:</strong> <span id="imagingDescription"></span></p>
                                        </div>
                                        <div class="col-md-6">
                                            <p><strong>Fee:</strong> $<span id="imagingFee"></span></p>
                                        </div>
                                        <div class="col-md-6">
                                            <p><strong>Turnaround Time:</strong> <span id="imagingTurnaround"></span></p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="scheduled_date">Scheduled Date & Time *</label>
                                        <input type="datetime-local" class="form-control" id="scheduled_date" name="scheduled_date" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="radiologist_id">Assign Radiologist</label>
                                        <select class="form-control select2" id="radiologist_id" name="radiologist_id">
                                            <option value="">- Select Radiologist -</option>
                                            <?php
                                            // Fetch available radiologists
                                            $radiologists_sql = "SELECT user_id, user_name, user_email 
                                                                FROM users 
                                                               ";
                                            $radiologists_result = $mysqli->query($radiologists_sql);
                                            
                                            if ($radiologists_result && $radiologists_result->num_rows > 0) {
                                                while ($radiologist = $radiologists_result->fetch_assoc()): ?>
                                                    <option value="<?php echo $radiologist['user_id']; ?>">
                                                        <?php echo htmlspecialchars($radiologist['user_name']); ?>
                                                    </option>
                                                <?php 
                                                endwhile;
                                            } else {
                                                echo '<option value="">No radiologists available</option>';
                                            }
                                            ?>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="study_notes">Study Notes</label>
                                <textarea class="form-control" id="study_notes" name="study_notes" rows="3" 
                                          placeholder="Additional notes for this specific study..."></textarea>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn btn-success btn-lg btn-block">
                                    <i class="fas fa-calendar-plus mr-2"></i>Schedule Study
                                </button>
                                <a href="radiology_order_details.php?id=<?php echo $order_id; ?>" class="btn btn-secondary btn-block">
                                    <i class="fas fa-times mr-2"></i>Cancel
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize Select2
    $('.select2').select2();

    // Show imaging study details when selected
    $('#imaging_id').change(function() {
        const selectedOption = $(this).find('option:selected');
        const description = selectedOption.data('description');
        const fee = selectedOption.data('fee');
        const turnaround = selectedOption.data('turnaround');
        
        if (selectedOption.val()) {
            $('#imagingDescription').text(description || 'No description available');
            $('#imagingFee').text(parseFloat(fee).toFixed(2));
            $('#imagingTurnaround').text(turnaround || 'Not specified');
            $('#imagingDetails').show();
        } else {
            $('#imagingDetails').hide();
        }
    });

    // Set minimum datetime for scheduling
    const now = new Date();
    now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
    $('#scheduled_date').attr('min', now.toISOString().slice(0, 16));

    // Auto-populate scheduled date with a reasonable default (tomorrow at 9 AM)
    const tomorrow = new Date(now);
    tomorrow.setDate(tomorrow.getDate() + 1);
    tomorrow.setHours(9, 0, 0, 0);
    $('#scheduled_date').val(tomorrow.toISOString().slice(0, 16));

    // Trigger change event on page load to show details if imaging is pre-selected
    $('#imaging_id').trigger('change');
});
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
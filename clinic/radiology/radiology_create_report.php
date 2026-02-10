<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/audit_functions.php'; // Added for logging

// Get order ID from URL
$order_id = intval($_GET['order_id']);

// AUDIT LOG: Access attempt for creating radiology report
audit_log($mysqli, [
    'user_id'     => $_SESSION['user_id'] ?? null,
    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
    'action'      => 'ACCESS',
    'module'      => 'Radiology',
    'table_name'  => 'radiology_reports',
    'entity_type' => 'radiology_report',
    'record_id'   => null,
    'patient_id'  => null,
    'visit_id'    => null,
    'description' => "Attempting to access radiology report creation page for order ID: " . $order_id,
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
        'table_name'  => 'radiology_reports',
        'entity_type' => 'radiology_report',
        'record_id'   => null,
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
                     ru.user_name as radiologist_name,
                     d.department_name
              FROM radiology_orders ro
              LEFT JOIN patients p ON ro.patient_id = p.patient_id
              LEFT JOIN users u ON ro.referring_doctor_id = u.user_id
              LEFT JOIN users ru ON ro.radiologist_id = ru.user_id
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

// Fetch completed studies for this order
$studies_sql = "SELECT ros.*, ri.imaging_name, ri.imaging_code
                FROM radiology_order_studies ros
                LEFT JOIN radiology_imagings ri ON ros.imaging_id = ri.imaging_id
                WHERE ros.radiology_order_id = ? AND ros.status = 'completed'
                ORDER BY ros.performed_date ASC";
$studies_stmt = $mysqli->prepare($studies_sql);
$studies_stmt->bind_param("i", $order_id);
$studies_stmt->execute();
$studies_result = $studies_stmt->get_result();

if ($studies_result->num_rows == 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "No completed studies found for this order. Cannot create report.";
    
    // AUDIT LOG: No completed studies
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'CREATE_REPORT',
        'module'      => 'Radiology',
        'table_name'  => 'radiology_reports',
        'entity_type' => 'radiology_report',
        'record_id'   => null,
        'patient_id'  => $order['patient_id'],
        'visit_id'    => $order['visit_id'] ?? null,
        'description' => "No completed studies found for order #" . $order['order_number'] . 
                        ". Cannot create report for patient: " . $order['patient_first_name'] . " " . $order['patient_last_name'],
        'status'      => 'FAILED',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    header("Location: radiology_order_details.php?id=" . $order_id);
    exit;
}

// AUDIT LOG: Successful access to create report page
audit_log($mysqli, [
    'user_id'     => $_SESSION['user_id'] ?? null,
    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
    'action'      => 'VIEW',
    'module'      => 'Radiology',
    'table_name'  => 'radiology_reports',
    'entity_type' => 'radiology_report',
    'record_id'   => null,
    'patient_id'  => $order['patient_id'],
    'visit_id'    => $order['visit_id'] ?? null,
    'description' => "Accessed radiology report creation page for order #" . $order['order_number'] . 
                    " (Patient: " . $order['patient_first_name'] . " " . $order['patient_last_name'] . ")",
    'status'      => 'SUCCESS',
    'old_values'  => null,
    'new_values'  => null
]);

// Check if report already exists
$existing_report_sql = "SELECT report_id FROM radiology_reports WHERE radiology_order_id = ? AND report_status != 'cancelled'";
$existing_report_stmt = $mysqli->prepare($existing_report_sql);
$existing_report_stmt->bind_param("i", $order_id);
$existing_report_stmt->execute();
$existing_report_result = $existing_report_stmt->get_result();

if ($existing_report_result->num_rows > 0) {
    $existing_report = $existing_report_result->fetch_assoc();
    $_SESSION['alert_type'] = "info";
    $_SESSION['alert_message'] = "A report already exists for this order.";
    
    // AUDIT LOG: Report already exists
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'CREATE_REPORT',
        'module'      => 'Radiology',
        'table_name'  => 'radiology_reports',
        'entity_type' => 'radiology_report',
        'record_id'   => $existing_report['report_id'],
        'patient_id'  => $order['patient_id'],
        'visit_id'    => $order['visit_id'] ?? null,
        'description' => "Report already exists for order #" . $order['order_number'] . 
                        ". Redirecting to existing report ID: " . $existing_report['report_id'],
        'status'      => 'INFO',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    header("Location: radiology_view_report.php?report_id=" . $existing_report['report_id']);
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    
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
            'table_name'  => 'radiology_reports',
            'entity_type' => 'radiology_report',
            'record_id'   => null,
            'patient_id'  => $order['patient_id'],
            'visit_id'    => $order['visit_id'] ?? null,
            'description' => "Invalid CSRF token when attempting to create radiology report for order #" . $order['order_number'],
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => null
        ]);
        
        header("Location: radiology_create_report.php?order_id=" . $order_id);
        exit;
    }

    $clinical_history = sanitizeInput($_POST['clinical_history'] ?? '');
    $technique = sanitizeInput($_POST['technique'] ?? '');
    $comparison = sanitizeInput($_POST['comparison'] ?? '');
    $findings = sanitizeInput($_POST['findings'] ?? '');
    $impression = sanitizeInput($_POST['impression'] ?? '');
    $recommendations = sanitizeInput($_POST['recommendations'] ?? '');
    $conclusion = sanitizeInput($_POST['conclusion'] ?? '');
    $report_status = sanitizeInput($_POST['report_status'] ?? 'draft');

    // Prepare report data for audit log
    $report_data = [
        'order_id' => $order_id,
        'order_number' => $order['order_number'],
        'patient_id' => $order['patient_id'],
        'patient_name' => $order['patient_first_name'] . ' ' . $order['patient_last_name'],
        'referring_doctor_id' => $order['referring_doctor_id'],
        'radiologist_id' => $order['radiologist_id'],
        'clinical_history' => substr($clinical_history, 0, 100) . (strlen($clinical_history) > 100 ? '...' : ''),
        'technique' => substr($technique, 0, 100) . (strlen($technique) > 100 ? '...' : ''),
        'comparison' => $comparison,
        'findings_length' => strlen($findings),
        'impression_length' => strlen($impression),
        'recommendations' => $recommendations,
        'conclusion' => $conclusion,
        'report_status' => $report_status,
        'created_by' => $session_user_id ?? null
    ];

    // AUDIT LOG: Attempt to create report
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'CREATE_REPORT',
        'module'      => 'Radiology',
        'table_name'  => 'radiology_reports',
        'entity_type' => 'radiology_report',
        'record_id'   => null,
        'patient_id'  => $order['patient_id'],
        'visit_id'    => $order['visit_id'] ?? null,
        'description' => "Attempting to create radiology report for order #" . $order['order_number'] . 
                        " (Patient: " . $order['patient_first_name'] . " " . $order['patient_last_name'] . 
                        ", Status: " . $report_status . ")",
        'status'      => 'ATTEMPT',
        'old_values'  => null,
        'new_values'  => json_encode($report_data)
    ]);

    try {
        $mysqli->begin_transaction();

        // Generate report number
        $report_number = 'RAD-' . date('Ymd') . '-' . str_pad($order_id, 4, '0', STR_PAD_LEFT);
        $report_data['report_number'] = $report_number;

        // Create main report
        $report_sql = "INSERT INTO radiology_reports SET 
                      report_number = ?,
                      radiology_order_id = ?,
                      patient_id = ?,
                      referring_doctor_id = ?,
                      radiologist_id = ?,
                      clinical_history = ?,
                      technique = ?,
                      comparison = ?,
                      findings = ?,
                      impression = ?,
                      recommendations = ?,
                      conclusion = ?,
                      report_status = ?,
                      created_by = ?";
        
        $report_stmt = $mysqli->prepare($report_sql);
        $report_stmt->bind_param("siiisssssssssi", 
            $report_number,
            $order_id,
            $order['patient_id'],
            $order['referring_doctor_id'],
            $order['radiologist_id'],
            $clinical_history,
            $technique,
            $comparison,
            $findings,
            $impression,
            $recommendations,
            $conclusion,
            $report_status,
            $session_user_id
        );
        
        if (!$report_stmt->execute()) {
            throw new Exception("Error creating report: " . $mysqli->error);
        }
        
        $report_id = $report_stmt->insert_id;
        $report_stmt->close();

        $report_data['report_id'] = $report_id;
        
        // AUDIT LOG: Main report created
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'CREATE_REPORT',
            'module'      => 'Radiology',
            'table_name'  => 'radiology_reports',
            'entity_type' => 'radiology_report',
            'record_id'   => $report_id,
            'patient_id'  => $order['patient_id'],
            'visit_id'    => $order['visit_id'] ?? null,
            'description' => "Main radiology report created with number: " . $report_number . 
                            " for order #" . $order['order_number'],
            'status'      => 'SUCCESS',
            'old_values'  => null,
            'new_values'  => json_encode($report_data)
        ]);

        // Link studies to report
        mysqli_data_seek($studies_result, 0);
        $studies_linked = [];
        while ($study = $studies_result->fetch_assoc()) {
            $study_findings = sanitizeInput($_POST['study_findings_' . $study['radiology_order_study_id']] ?? '');
            $study_impression = sanitizeInput($_POST['study_impression_' . $study['radiology_order_study_id']] ?? '');
            
            $study_sql = "INSERT INTO radiology_report_studies SET 
                         report_id = ?,
                         radiology_order_study_id = ?,
                         study_findings = ?,
                         study_impression = ?";
            
            $study_stmt = $mysqli->prepare($study_sql);
            $study_stmt->bind_param("iiss", $report_id, $study['radiology_order_study_id'], $study_findings, $study_impression);
            
            if (!$study_stmt->execute()) {
                throw new Exception("Error linking study to report: " . $mysqli->error);
            }
            
            $linked_study_id = $study_stmt->insert_id;
            $study_stmt->close();
            
            $studies_linked[] = [
                'study_id' => $study['radiology_order_study_id'],
                'linked_id' => $linked_study_id,
                'imaging_name' => $study['imaging_name'],
                'imaging_code' => $study['imaging_code'],
                'findings_length' => strlen($study_findings),
                'impression_length' => strlen($study_impression)
            ];
            
            // AUDIT LOG: Study linked to report
            audit_log($mysqli, [
                'user_id'     => $_SESSION['user_id'] ?? null,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'LINK_STUDY',
                'module'      => 'Radiology',
                'table_name'  => 'radiology_report_studies',
                'entity_type' => 'radiology_report_study',
                'record_id'   => $linked_study_id,
                'patient_id'  => $order['patient_id'],
                'visit_id'    => $order['visit_id'] ?? null,
                'description' => "Linked study '" . $study['imaging_name'] . "' (" . $study['imaging_code'] . 
                                ") to report #" . $report_number,
                'status'      => 'SUCCESS',
                'old_values'  => null,
                'new_values'  => json_encode([
                    'report_id' => $report_id,
                    'order_study_id' => $study['radiology_order_study_id'],
                    'imaging_name' => $study['imaging_name'],
                    'imaging_code' => $study['imaging_code'],
                    'report_number' => $report_number
                ])
            ]);
        }

        // If finalizing report, set finalized date
        if ($report_status == 'final') {
            $finalize_sql = "UPDATE radiology_reports SET 
                           finalized_at = NOW(),
                           finalized_by = ?
                           WHERE report_id = ?";
            $finalize_stmt = $mysqli->prepare($finalize_sql);
            $finalize_stmt->bind_param("ii", $session_user_id, $report_id);
            
            if (!$finalize_stmt->execute()) {
                throw new Exception("Error finalizing report: " . $mysqli->error);
            }
            
            $finalize_stmt->close();
            
            // AUDIT LOG: Report finalized
            audit_log($mysqli, [
                'user_id'     => $_SESSION['user_id'] ?? null,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'FINALIZE_REPORT',
                'module'      => 'Radiology',
                'table_name'  => 'radiology_reports',
                'entity_type' => 'radiology_report',
                'record_id'   => $report_id,
                'patient_id'  => $order['patient_id'],
                'visit_id'    => $order['visit_id'] ?? null,
                'description' => "Finalized radiology report #" . $report_number . 
                                " for order #" . $order['order_number'],
                'status'      => 'SUCCESS',
                'old_values'  => json_encode(['status' => 'draft']),
                'new_values'  => json_encode(['status' => 'final', 'finalized_by' => $session_user_id])
            ]);
        }

        $mysqli->commit();

        // Log activity in activity_logs (existing log)
        $activity_desc = "Created radiology report: " . $report_number . " for order #" . $order['order_number'];
        $activity_sql = "INSERT INTO activity_logs SET 
                        activity_description = ?, 
                        activity_created_by = ?, 
                        activity_date = NOW()";
        $activity_stmt = $mysqli->prepare($activity_sql);
        $activity_stmt->bind_param("si", $activity_desc, $session_user_id);
        $activity_stmt->execute();
        $activity_stmt->close();

        // AUDIT LOG: Report creation completed
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'CREATE_REPORT_COMPLETE',
            'module'      => 'Radiology',
            'table_name'  => 'radiology_reports',
            'entity_type' => 'radiology_report',
            'record_id'   => $report_id,
            'patient_id'  => $order['patient_id'],
            'visit_id'    => $order['visit_id'] ?? null,
            'description' => "Radiology report creation completed successfully. Report #" . $report_number . 
                            " with " . count($studies_linked) . " linked studies. " .
                            "Status: " . $report_status . ". " .
                            "Patient: " . $order['patient_first_name'] . " " . $order['patient_last_name'],
            'status'      => 'COMPLETED',
            'old_values'  => null,
            'new_values'  => json_encode([
                'report_id' => $report_id,
                'report_number' => $report_number,
                'order_number' => $order['order_number'],
                'patient_name' => $order['patient_first_name'] . ' ' . $order['patient_last_name'],
                'studies_linked' => count($studies_linked),
                'report_status' => $report_status
            ])
        ]);

        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Radiology report created successfully!";
        header("Location: radiology_view_report.php?report_id=" . $report_id);
        exit;

    } catch (Exception $e) {
        $mysqli->rollback();
        
        // AUDIT LOG: Failed report creation
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'CREATE_REPORT',
            'module'      => 'Radiology',
            'table_name'  => 'radiology_reports',
            'entity_type' => 'radiology_report',
            'record_id'   => null,
            'patient_id'  => $order['patient_id'],
            'visit_id'    => $order['visit_id'] ?? null,
            'description' => "Failed to create radiology report for order #" . $order['order_number'] . 
                            ". Error: " . $e->getMessage(),
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => json_encode($report_data)
        ]);

        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error creating report: " . $e->getMessage();
        header("Location: radiology_create_report.php?order_id=" . $order_id);
        exit;
    }
}

// Calculate patient age
$patient_age = "";
if (!empty($order['patient_dob'])) {
    $birthDate = new DateTime($order['patient_dob']);
    $today = new DateTime();
    $age = $today->diff($birthDate)->y;
    $patient_age = "$age years";
}
?>
<div class="card">
    <div class="card-header bg-primary py-2">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h3 class="card-title mt-2 mb-0 text-white">
                    <i class="fas fa-fw fa-file-medical mr-2"></i>Create Radiology Report
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

        <!-- Patient and Order Information -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card card-info">
                    <div class="card-header">
                        <h4 class="card-title mb-0"><i class="fas fa-user-injured mr-2"></i>Patient Information</h4>
                    </div>
                    <div class="card-body">
                        <p><strong>Name:</strong> <?php echo htmlspecialchars($order['patient_first_name'] . ' ' . $order['patient_last_name']); ?></p>
                        <p><strong>MRN:</strong> <?php echo htmlspecialchars($order['patient_mrn']); ?></p>
                        <p><strong>Age/Gender:</strong> <?php echo $patient_age . ' / ' . htmlspecialchars($order['patient_gender']); ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card card-warning">
                    <div class="card-header">
                        <h4 class="card-title mb-0"><i class="fas fa-info-circle mr-2"></i>Order Information</h4>
                    </div>
                    <div class="card-body">
                        <p><strong>Order #:</strong> <?php echo htmlspecialchars($order['order_number']); ?></p>
                        <p><strong>Referring Doctor:</strong> <?php echo htmlspecialchars($order['referring_doctor_name'] ?? 'N/A'); ?></p>
                        <p><strong>Radiologist:</strong> <?php echo htmlspecialchars($order['radiologist_name'] ?? 'Not assigned'); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Report Form -->
        <form method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

            <div class="card card-success mb-4">
                <div class="card-header">
                    <h4 class="card-title mb-0"><i class="fas fa-stethoscope mr-2"></i>Clinical Information</h4>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label for="clinical_history">Clinical History</label>
                        <textarea class="form-control" id="clinical_history" name="clinical_history" rows="4" 
                                  placeholder="Patient's clinical history and reason for study..."><?php echo htmlspecialchars($order['clinical_notes'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="technique">Technique</label>
                        <textarea class="form-control" id="technique" name="technique" rows="3" 
                                  placeholder="Description of the technique used..."></textarea>
                    </div>

                    <div class="form-group">
                        <label for="comparison">Comparison</label>
                        <textarea class="form-control" id="comparison" name="comparison" rows="2" 
                                  placeholder="Comparison with previous studies if available..."></textarea>
                    </div>
                </div>
            </div>

            <!-- Studies Findings -->
            <div class="card card-primary mb-4">
                <div class="card-header">
                    <h4 class="card-title mb-0"><i class="fas fa-procedures mr-2"></i>Studies & Findings</h4>
                </div>
                <div class="card-body">
                    <?php 
                    $study_count = 0;
                    while ($study = $studies_result->fetch_assoc()): 
                        $study_count++;
                    ?>
                        <div class="study-section mb-4 p-3 border rounded">
                            <h5 class="text-primary">Study #<?php echo $study_count; ?>: <?php echo htmlspecialchars($study['imaging_name']); ?></h5>
                            <small class="text-muted">Code: <?php echo htmlspecialchars($study['imaging_code']); ?></small>
                            
                            <div class="form-group mt-3">
                                <label for="study_findings_<?php echo $study['radiology_order_study_id']; ?>">Findings for this Study</label>
                                <textarea class="form-control" id="study_findings_<?php echo $study['radiology_order_study_id']; ?>" 
                                          name="study_findings_<?php echo $study['radiology_order_study_id']; ?>" rows="4"
                                          placeholder="Detailed findings for this specific study..."><?php echo htmlspecialchars($study['findings'] ?? ''); ?></textarea>
                            </div>

                            <div class="form-group">
                                <label for="study_impression_<?php echo $study['radiology_order_study_id']; ?>">Impression for this Study</label>
                                <textarea class="form-control" id="study_impression_<?php echo $study['radiology_order_study_id']; ?>" 
                                          name="study_impression_<?php echo $study['radiology_order_study_id']; ?>" rows="3"
                                          placeholder="Impression for this specific study..."><?php echo htmlspecialchars($study['impression'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            </div>

            <!-- Overall Report -->
            <div class="card card-info mb-4">
                <div class="card-header">
                    <h4 class="card-title mb-0"><i class="fas fa-file-medical-alt mr-2"></i>Overall Report</h4>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label for="findings">Overall Findings</label>
                        <textarea class="form-control" id="findings" name="findings" rows="5" 
                                  placeholder="Consolidated findings from all studies..."></textarea>
                    </div>

                    <div class="form-group">
                        <label for="impression">Overall Impression</label>
                        <textarea class="form-control" id="impression" name="impression" rows="4" 
                                  placeholder="Overall clinical impression..."></textarea>
                    </div>

                    <div class="form-group">
                        <label for="recommendations">Recommendations</label>
                        <textarea class="form-control" id="recommendations" name="recommendations" rows="3" 
                                  placeholder="Recommendations for follow-up or additional studies..."></textarea>
                    </div>

                    <div class="form-group">
                        <label for="conclusion">Conclusion</label>
                        <textarea class="form-control" id="conclusion" name="conclusion" rows="2" 
                                  placeholder="Final conclusion..."></textarea>
                    </div>
                </div>
            </div>

            <!-- Report Status -->
            <div class="card card-warning">
                <div class="card-header">
                    <h4 class="card-title mb-0"><i class="fas fa-cog mr-2"></i>Report Settings</h4>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label for="report_status">Report Status</label>
                        <select class="form-control" id="report_status" name="report_status">
                            <option value="draft">Draft</option>
                            <option value="final">Final</option>
                        </select>
                        <small class="form-text text-muted">
                            <strong>Draft:</strong> Report can be edited later<br>
                            <strong>Final:</strong> Report is finalized and cannot be edited (creates permanent record)
                        </small>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-success btn-lg">
                            <i class="fas fa-save mr-2"></i>Save Radiology Report
                        </button>
                        <a href="radiology_order_details.php?id=<?php echo $order_id; ?>" class="btn btn-secondary">
                            <i class="fas fa-times mr-2"></i>Cancel
                        </a>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
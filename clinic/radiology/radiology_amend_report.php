<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/audit_functions.php'; // Added for logging

// Get report ID from URL
$report_id = intval($_GET['report_id']);

// AUDIT LOG: Access attempt for amending radiology report
audit_log($mysqli, [
    'user_id'     => $_SESSION['user_id'] ?? null,
    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
    'action'      => 'ACCESS',
    'module'      => 'Radiology',
    'table_name'  => 'radiology_reports',
    'entity_type' => 'radiology_report',
    'record_id'   => $report_id,
    'patient_id'  => null,
    'visit_id'    => null,
    'description' => "Attempting to access radiology report amendment page for report ID: " . $report_id,
    'status'      => 'ATTEMPT',
    'old_values'  => null,
    'new_values'  => null
]);

if (empty($report_id) || $report_id <= 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid report ID.";
    
    // AUDIT LOG: Invalid report ID
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'ACCESS',
        'module'      => 'Radiology',
        'table_name'  => 'radiology_reports',
        'entity_type' => 'radiology_report',
        'record_id'   => $report_id,
        'patient_id'  => null,
        'visit_id'    => null,
        'description' => "Invalid report ID: " . $report_id,
        'status'      => 'FAILED',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    header("Location: radiology_reports.php");
    exit;
}

// Fetch radiology report details for amendment
$report_sql = "SELECT rr.*, 
                      p.patient_first_name, p.patient_last_name, p.patient_mrn, p.patient_gender, p.patient_dob,
                      u.user_name as referring_doctor_name,
                      ru.user_name as radiologist_name,
                      ro.order_number, ro.order_priority, ro.clinical_notes as order_clinical_notes,
                      d.department_name
               FROM radiology_reports rr
               LEFT JOIN patients p ON rr.patient_id = p.patient_id
               LEFT JOIN users u ON rr.referring_doctor_id = u.user_id
               LEFT JOIN users ru ON rr.radiologist_id = ru.user_id
               LEFT JOIN radiology_orders ro ON rr.radiology_order_id = ro.radiology_order_id
               LEFT JOIN departments d ON ro.department_id = d.department_id
               WHERE rr.report_id = ?";
$report_stmt = $mysqli->prepare($report_sql);
$report_stmt->bind_param("i", $report_id);
$report_stmt->execute();
$report_result = $report_stmt->get_result();

if ($report_result->num_rows == 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Radiology report not found.";
    
    // AUDIT LOG: Report not found
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'ACCESS',
        'module'      => 'Radiology',
        'table_name'  => 'radiology_reports',
        'entity_type' => 'radiology_report',
        'record_id'   => $report_id,
        'patient_id'  => null,
        'visit_id'    => null,
        'description' => "Radiology report ID " . $report_id . " not found",
        'status'      => 'FAILED',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    header("Location: radiology_reports.php");
    exit;
}

$report = $report_result->fetch_assoc();

// Check if report can be amended (only final reports can be amended)
if ($report['report_status'] != 'final') {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Only finalized reports can be amended. This report is currently " . $report['report_status'] . ".";
    
    // AUDIT LOG: Report not in amendable state
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'AMEND_REPORT',
        'module'      => 'Radiology',
        'table_name'  => 'radiology_reports',
        'entity_type' => 'radiology_report',
        'record_id'   => $report_id,
        'patient_id'  => $report['patient_id'],
        'visit_id'    => null,
        'description' => "Report #" . $report['report_number'] . " cannot be amended. Current status: " . $report['report_status'],
        'status'      => 'FAILED',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    header("Location: radiology_view_report.php?report_id=" . $report_id);
    exit;
}

// Fetch report studies with details for amendment
$studies_sql = "SELECT rrs.*, 
                       ros.scheduled_date, ros.performed_date,
                       ri.imaging_name, ri.imaging_code,
                       u.user_name as performed_by_name
                FROM radiology_report_studies rrs
                LEFT JOIN radiology_order_studies ros ON rrs.radiology_order_study_id = ros.radiology_order_study_id
                LEFT JOIN radiology_imagings ri ON ros.imaging_id = ri.imaging_id
                LEFT JOIN users u ON ros.performed_by = u.user_id
                WHERE rrs.report_id = ?
                ORDER BY ros.performed_date ASC";
$studies_stmt = $mysqli->prepare($studies_sql);
$studies_stmt->bind_param("i", $report_id);
$studies_stmt->execute();
$studies_result = $studies_stmt->get_result();

// AUDIT LOG: Successful access to amend report page
audit_log($mysqli, [
    'user_id'     => $_SESSION['user_id'] ?? null,
    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
    'action'      => 'VIEW',
    'module'      => 'Radiology',
    'table_name'  => 'radiology_reports',
    'entity_type' => 'radiology_report',
    'record_id'   => $report_id,
    'patient_id'  => $report['patient_id'],
    'visit_id'    => null,
    'description' => "Accessed radiology report amendment page for report #" . $report['report_number'] . 
                    " (Patient: " . $report['patient_first_name'] . " " . $report['patient_last_name'] . ")",
    'status'      => 'SUCCESS',
    'old_values'  => null,
    'new_values'  => null
]);

// Handle form submission for amendment
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    $amendment_reason = sanitizeInput($_POST['amendment_reason'] ?? '');
    
    // Store old report data for audit log
    $old_report_data = [
        'clinical_history' => $report['clinical_history'],
        'technique' => $report['technique'],
        'comparison' => $report['comparison'],
        'findings' => $report['findings'],
        'impression' => $report['impression'],
        'recommendations' => $report['recommendations'],
        'conclusion' => $report['conclusion'],
        'report_status' => $report['report_status']
    ];
    
    // Store old studies data for audit log
    $old_studies_data = [];
    mysqli_data_seek($studies_result, 0);
    while ($study = $studies_result->fetch_assoc()) {
        $old_studies_data[$study['report_study_id']] = [
            'study_findings' => $study['study_findings'],
            'study_impression' => $study['study_impression'],
            'imaging_name' => $study['imaging_name'],
            'imaging_code' => $study['imaging_code']
        ];
    }
    $studies_result->data_seek(0); // Reset pointer
    
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
            'record_id'   => $report_id,
            'patient_id'  => $report['patient_id'],
            'visit_id'    => null,
            'description' => "Invalid CSRF token when attempting to amend radiology report #" . $report['report_number'],
            'status'      => 'FAILED',
            'old_values'  => json_encode(['report_data' => $old_report_data, 'studies' => $old_studies_data]),
            'new_values'  => null
        ]);
        
        header("Location: radiology_amend_report.php?report_id=" . $report_id);
        exit;
    }

    // Validate amendment reason
    if (empty($amendment_reason)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please provide a reason for amending this report.";
        
        // AUDIT LOG: Missing amendment reason
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'VALIDATION_FAILED',
            'module'      => 'Radiology',
            'table_name'  => 'radiology_reports',
            'entity_type' => 'radiology_report',
            'record_id'   => $report_id,
            'patient_id'  => $report['patient_id'],
            'visit_id'    => null,
            'description' => "Missing amendment reason for report #" . $report['report_number'],
            'status'      => 'FAILED',
            'old_values'  => json_encode(['report_data' => $old_report_data, 'studies' => $old_studies_data]),
            'new_values'  => null
        ]);
        
        header("Location: radiology_amend_report.php?report_id=" . $report_id);
        exit;
    }

    $clinical_history = sanitizeInput($_POST['clinical_history'] ?? '');
    $technique = sanitizeInput($_POST['technique'] ?? '');
    $comparison = sanitizeInput($_POST['comparison'] ?? '');
    $findings = sanitizeInput($_POST['findings'] ?? '');
    $impression = sanitizeInput($_POST['impression'] ?? '');
    $recommendations = sanitizeInput($_POST['recommendations'] ?? '');
    $conclusion = sanitizeInput($_POST['conclusion'] ?? '');

    // Prepare new report data for audit log
    $new_report_data = [
        'clinical_history' => $clinical_history,
        'technique' => $technique,
        'comparison' => $comparison,
        'findings' => $findings,
        'impression' => $impression,
        'recommendations' => $recommendations,
        'conclusion' => $conclusion,
        'report_status' => 'amended',
        'amendment_reason' => $amendment_reason,
        'amended_by' => $session_user_id ?? null
    ];

    // Track changes
    $changes = [];
    if ($old_report_data['clinical_history'] != $clinical_history) {
        $changes[] = "Clinical history updated";
    }
    if ($old_report_data['findings'] != $findings) {
        $old_len = strlen($old_report_data['findings']);
        $new_len = strlen($findings);
        $changes[] = "Findings updated (length: {$old_len} → {$new_len} chars)";
    }
    if ($old_report_data['impression'] != $impression) {
        $old_len = strlen($old_report_data['impression']);
        $new_len = strlen($impression);
        $changes[] = "Impression updated (length: {$old_len} → {$new_len} chars)";
    }
    if ($old_report_data['recommendations'] != $recommendations) {
        $changes[] = "Recommendations updated";
    }

    // AUDIT LOG: Attempt to amend report
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'AMEND_REPORT',
        'module'      => 'Radiology',
        'table_name'  => 'radiology_reports',
        'entity_type' => 'radiology_report',
        'record_id'   => $report_id,
        'patient_id'  => $report['patient_id'],
        'visit_id'    => null,
        'description' => "Attempting to amend radiology report #" . $report['report_number'] . 
                        " for patient: " . $report['patient_first_name'] . " " . $report['patient_last_name'] . 
                        ". Reason: " . $amendment_reason,
        'status'      => 'ATTEMPT',
        'old_values'  => json_encode(['report_data' => $old_report_data, 'studies' => $old_studies_data]),
        'new_values'  => json_encode(['report_data' => $new_report_data])
    ]);

    try {
        $mysqli->begin_transaction();

        // First, create a version record of the current report
        $version_sql = "INSERT INTO radiology_report_versions SET 
                       report_id = ?,
                       version_number = (SELECT COALESCE(MAX(version_number), 0) + 1 FROM radiology_report_versions WHERE report_id = ?),
                       findings = ?,
                       impression = ?,
                       recommendations = ?,
                       amended_by = ?,
                       amendment_reason = ?";
        
        $version_stmt = $mysqli->prepare($version_sql);
        $version_stmt->bind_param("iissiis", 
            $report_id,
            $report_id,
            $report['findings'],
            $report['impression'],
            $report['recommendations'],
            $session_user_id,
            $amendment_reason
        );
        
        if (!$version_stmt->execute()) {
            throw new Exception("Error creating report version: " . $mysqli->error);
        }
        
        $version_id = $mysqli->insert_id;
        $version_stmt->close();
        
        // AUDIT LOG: Report version created
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'CREATE_VERSION',
            'module'      => 'Radiology',
            'table_name'  => 'radiology_report_versions',
            'entity_type' => 'radiology_report_version',
            'record_id'   => $version_id,
            'patient_id'  => $report['patient_id'],
            'visit_id'    => null,
            'description' => "Created version #" . $version_id . " for report #" . $report['report_number'] . 
                            " before amendment. Reason: " . $amendment_reason,
            'status'      => 'SUCCESS',
            'old_values'  => null,
            'new_values'  => json_encode([
                'report_id' => $report_id,
                'version_id' => $version_id,
                'amendment_reason' => $amendment_reason,
                'amended_by' => $session_user_id
            ])
        ]);

        // Update the main report with amended content
        $update_sql = "UPDATE radiology_reports SET 
                      clinical_history = ?,
                      technique = ?,
                      comparison = ?,
                      findings = ?,
                      impression = ?,
                      recommendations = ?,
                      conclusion = ?,
                      report_status = 'amended',
                      updated_at = NOW()
                      WHERE report_id = ?";
        
        $update_stmt = $mysqli->prepare($update_sql);
        $update_stmt->bind_param("sssssssi", 
            $clinical_history,
            $technique,
            $comparison,
            $findings,
            $impression,
            $recommendations,
            $conclusion,
            $report_id
        );
        
        if (!$update_stmt->execute()) {
            throw new Exception("Error updating report: " . $mysqli->error);
        }
        $update_stmt->close();

        // Update study-specific findings if provided
        $studies_updated = [];
        mysqli_data_seek($studies_result, 0);
        while ($study = $studies_result->fetch_assoc()) {
            $study_findings = sanitizeInput($_POST['study_findings_' . $study['radiology_order_study_id']] ?? '');
            $study_impression = sanitizeInput($_POST['study_impression_' . $study['radiology_order_study_id']] ?? '');
            
            // Check if study data changed
            $old_study = $old_studies_data[$study['report_study_id']] ?? [];
            $study_changed = false;
            $study_changes = [];
            
            if ($old_study['study_findings'] != $study_findings) {
                $study_changed = true;
                $study_changes[] = "Study findings updated";
            }
            if ($old_study['study_impression'] != $study_impression) {
                $study_changed = true;
                $study_changes[] = "Study impression updated";
            }
            
            if ($study_changed) {
                $update_study_sql = "UPDATE radiology_report_studies SET 
                                   study_findings = ?,
                                   study_impression = ?,
                                   updated_at = NOW()
                                   WHERE report_study_id = ?";
                
                $update_study_stmt = $mysqli->prepare($update_study_sql);
                $update_study_stmt->bind_param("ssi", $study_findings, $study_impression, $study['report_study_id']);
                
                if (!$update_study_stmt->execute()) {
                    throw new Exception("Error updating study: " . $mysqli->error);
                }
                $update_study_stmt->close();
                
                $studies_updated[] = [
                    'study_id' => $study['report_study_id'],
                    'imaging_name' => $study['imaging_name'],
                    'changes' => $study_changes
                ];
                
                // AUDIT LOG: Study updated in amendment
                audit_log($mysqli, [
                    'user_id'     => $_SESSION['user_id'] ?? null,
                    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                    'action'      => 'UPDATE_STUDY',
                    'module'      => 'Radiology',
                    'table_name'  => 'radiology_report_studies',
                    'entity_type' => 'radiology_report_study',
                    'record_id'   => $study['report_study_id'],
                    'patient_id'  => $report['patient_id'],
                    'visit_id'    => null,
                    'description' => "Updated study '" . $study['imaging_name'] . "' in amended report #" . $report['report_number'],
                    'status'      => 'SUCCESS',
                    'old_values'  => json_encode($old_study),
                    'new_values'  => json_encode([
                        'study_findings' => $study_findings,
                        'study_impression' => $study_impression,
                        'imaging_name' => $study['imaging_name']
                    ])
                ]);
            }
        }

        $mysqli->commit();

        // Build comprehensive change description
        $change_description = "Report #" . $report['report_number'] . " amended. ";
        if (!empty($changes)) {
            $change_description .= "Changes: " . implode(", ", $changes) . ". ";
        }
        if (!empty($studies_updated)) {
            $studies_desc = array_map(function($study) {
                return $study['imaging_name'] . " (" . implode(", ", $study['changes']) . ")";
            }, $studies_updated);
            $change_description .= "Updated studies: " . implode("; ", $studies_desc) . ". ";
        }
        $change_description .= "Amendment reason: " . $amendment_reason;

        // AUDIT LOG: Report amendment completed
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'AMEND_REPORT',
            'module'      => 'Radiology',
            'table_name'  => 'radiology_reports',
            'entity_type' => 'radiology_report',
            'record_id'   => $report_id,
            'patient_id'  => $report['patient_id'],
            'visit_id'    => null,
            'description' => $change_description,
            'status'      => 'SUCCESS',
            'old_values'  => json_encode(['report_data' => $old_report_data, 'studies' => $old_studies_data]),
            'new_values'  => json_encode([
                'report_data' => $new_report_data,
                'version_id' => $version_id,
                'studies_updated' => count($studies_updated)
            ])
        ]);

        // Log activity in activity_logs (existing log)
        $activity_desc = "Amended radiology report: " . $report['report_number'] . " - Reason: " . $amendment_reason;
        $activity_sql = "INSERT INTO activity_logs SET 
                        activity_description = ?, 
                        activity_created_by = ?, 
                        activity_date = NOW()";
        $activity_stmt = $mysqli->prepare($activity_sql);
        $activity_stmt->bind_param("si", $activity_desc, $session_user_id);
        $activity_stmt->execute();
        $activity_stmt->close();

        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Report amended successfully! A new version has been saved for audit purposes.";
        header("Location: radiology_view_report.php?report_id=" . $report_id);
        exit;

    } catch (Exception $e) {
        $mysqli->rollback();
        
        // AUDIT LOG: Failed report amendment
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'AMEND_REPORT',
            'module'      => 'Radiology',
            'table_name'  => 'radiology_reports',
            'entity_type' => 'radiology_report',
            'record_id'   => $report_id,
            'patient_id'  => $report['patient_id'],
            'visit_id'    => null,
            'description' => "Failed to amend radiology report #" . $report['report_number'] . 
                            ". Error: " . $e->getMessage() . 
                            ". Amendment reason: " . $amendment_reason,
            'status'      => 'FAILED',
            'old_values'  => json_encode(['report_data' => $old_report_data, 'studies' => $old_studies_data]),
            'new_values'  => json_encode(['report_data' => $new_report_data])
        ]);

        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error amending report: " . $e->getMessage();
        header("Location: radiology_amend_report.php?report_id=" . $report_id);
        exit;
    }
}

// Calculate patient age
$patient_age = "";
if (!empty($report['patient_dob'])) {
    $birthDate = new DateTime($report['patient_dob']);
    $today = new DateTime();
    $age = $today->diff($birthDate)->y;
    $patient_age = "$age years";
}
?>
<div class="card">
    <div class="card-header bg-warning py-2">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h3 class="card-title mt-2 mb-0 text-dark">
                    <i class="fas fa-fw fa-file-medical-alt mr-2"></i>Amend Radiology Report
                </h3>
                <small class="text-dark-50">Report #: <?php echo htmlspecialchars($report['report_number']); ?></small>
            </div>
            <div class="btn-group">
                <a href="radiology_view_report.php?report_id=<?php echo $report_id; ?>" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Report
                </a>
                <a href="radiology_reports.php" class="btn btn-info">
                    <i class="fas fa-list mr-2"></i>All Reports
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

        <!-- Warning Alert -->
        <div class="alert alert-warning">
            <h5><i class="fas fa-exclamation-triangle mr-2"></i>Important Notice</h5>
            <p class="mb-0">
                You are about to amend a <strong>finalized report</strong>. This action will:
            </p>
            <ul class="mb-0 mt-2">
                <li>Create a permanent version of the current report for audit purposes</li>
                <li>Update the report with your amendments</li>
                <li>Change the report status to "Amended"</li>
                <li>Record the reason for amendment</li>
            </ul>
        </div>

        <!-- Patient and Order Information -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card card-info">
                    <div class="card-header">
                        <h4 class="card-title mb-0"><i class="fas fa-user-injured mr-2"></i>Patient Information</h4>
                    </div>
                    <div class="card-body">
                        <p><strong>Name:</strong> <?php echo htmlspecialchars($report['patient_first_name'] . ' ' . $report['patient_last_name']); ?></p>
                        <p><strong>MRN:</strong> <?php echo htmlspecialchars($report['patient_mrn']); ?></p>
                        <p><strong>Age/Gender:</strong> <?php echo $patient_age . ' / ' . htmlspecialchars($report['patient_gender']); ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card card-primary">
                    <div class="card-header">
                        <h4 class="card-title mb-0"><i class="fas fa-info-circle mr-2"></i>Report Information</h4>
                    </div>
                    <div class="card-body">
                        <p><strong>Report #:</strong> <?php echo htmlspecialchars($report['report_number']); ?></p>
                        <p><strong>Order #:</strong> <?php echo htmlspecialchars($report['order_number']); ?></p>
                        <p><strong>Referring Doctor:</strong> <?php echo htmlspecialchars($report['referring_doctor_name'] ?? 'N/A'); ?></p>
                        <p><strong>Original Report Date:</strong> <?php echo date('M j, Y g:i A', strtotime($report['created_at'])); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Amendment Form -->
        <form method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

            <!-- Amendment Reason -->
            <div class="card card-danger mb-4">
                <div class="card-header">
                    <h4 class="card-title mb-0"><i class="fas fa-clipboard-check mr-2"></i>Amendment Details</h4>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label for="amendment_reason" class="font-weight-bold">Reason for Amendment *</label>
                        <textarea class="form-control" id="amendment_reason" name="amendment_reason" rows="3" 
                                  placeholder="Please provide a detailed reason for amending this report..." required></textarea>
                        <small class="form-text text-muted">
                            This reason will be recorded in the audit trail and cannot be changed later.
                        </small>
                    </div>
                </div>
            </div>

            <!-- Clinical Information -->
            <div class="card card-success mb-4">
                <div class="card-header">
                    <h4 class="card-title mb-0"><i class="fas fa-stethoscope mr-2"></i>Clinical Information</h4>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label for="clinical_history">Clinical History</label>
                        <textarea class="form-control" id="clinical_history" name="clinical_history" rows="4" 
                                  placeholder="Patient's clinical history and reason for study..."><?php echo htmlspecialchars($report['clinical_history'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="technique">Technique</label>
                        <textarea class="form-control" id="technique" name="technique" rows="3" 
                                  placeholder="Description of the technique used..."><?php echo htmlspecialchars($report['technique'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="comparison">Comparison</label>
                        <textarea class="form-control" id="comparison" name="comparison" rows="2" 
                                  placeholder="Comparison with previous studies if available..."><?php echo htmlspecialchars($report['comparison'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Studies Findings -->
            <?php if ($studies_result->num_rows > 0): ?>
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
                                          placeholder="Detailed findings for this specific study..."><?php echo htmlspecialchars($study['study_findings'] ?? ''); ?></textarea>
                            </div>

                            <div class="form-group">
                                <label for="study_impression_<?php echo $study['radiology_order_study_id']; ?>">Impression for this Study</label>
                                <textarea class="form-control" id="study_impression_<?php echo $study['radiology_order_study_id']; ?>" 
                                          name="study_impression_<?php echo $study['radiology_order_study_id']; ?>" rows="3"
                                          placeholder="Impression for this specific study..."><?php echo htmlspecialchars($study['study_impression'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Overall Report -->
            <div class="card card-info mb-4">
                <div class="card-header">
                    <h4 class="card-title mb-0"><i class="fas fa-file-medical-alt mr-2"></i>Overall Report</h4>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label for="findings">Overall Findings</label>
                        <textarea class="form-control" id="findings" name="findings" rows="5" 
                                  placeholder="Consolidated findings from all studies..."><?php echo htmlspecialchars($report['findings'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="impression">Overall Impression</label>
                        <textarea class="form-control" id="impression" name="impression" rows="4" 
                                  placeholder="Overall clinical impression..."><?php echo htmlspecialchars($report['impression'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="recommendations">Recommendations</label>
                        <textarea class="form-control" id="recommendations" name="recommendations" rows="3" 
                                  placeholder="Recommendations for follow-up or additional studies..."><?php echo htmlspecialchars($report['recommendations'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="conclusion">Conclusion</label>
                        <textarea class="form-control" id="conclusion" name="conclusion" rows="2" 
                                  placeholder="Final conclusion..."><?php echo htmlspecialchars($report['conclusion'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="card card-warning">
                <div class="card-header">
                    <h4 class="card-title mb-0"><i class="fas fa-shield-alt mr-2"></i>Finalize Amendment</h4>
                </div>
                <div class="card-body">
                    <div class="alert alert-info">
                        <h6><i class="fas fa-info-circle mr-2"></i>Before Proceeding</h6>
                        <p class="mb-0">
                            Please review all changes carefully. Once submitted:
                        </p>
                        <ul class="mb-0 mt-2">
                            <li>The current report content will be permanently saved as a version</li>
                            <li>Your amendments will become the active report content</li>
                            <li>The report status will change to "Amended"</li>
                            <li>This action will be recorded in the audit trail</li>
                        </ul>
                    </div>

                    <div class="form-actions text-center">
                        <button type="submit" class="btn btn-warning btn-lg" onclick="return confirmAmendment()">
                            <i class="fas fa-file-medical-alt mr-2"></i>Submit Amendment
                        </button>
                        <a href="radiology_view_report.php?report_id=<?php echo $report_id; ?>" class="btn btn-secondary btn-lg">
                            <i class="fas fa-times mr-2"></i>Cancel Amendment
                        </a>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
function confirmAmendment() {
    const reason = document.getElementById('amendment_reason').value.trim();
    if (!reason) {
        alert('Please provide a reason for amending this report.');
        return false;
    }
    
    return confirm('Are you sure you want to amend this report?\n\nThis action will:\n- Create a permanent version of the current report\n- Update the report with your changes\n- Change the report status to "Amended"\n\nReason: ' + reason);
}

$(document).ready(function() {
    // Add change tracking
    $('textarea').on('input', function() {
        const originalValue = $(this).data('original') || $(this).val();
        const currentValue = $(this).val();
        
        if (originalValue !== currentValue) {
            $(this).addClass('border border-warning');
        } else {
            $(this).removeClass('border border-warning');
        }
    });

    // Store original values
    $('textarea').each(function() {
        $(this).data('original', $(this).val());
    });

    // Character counters for important fields
    $('#amendment_reason').on('input', function() {
        const length = $(this).val().length;
        $('#reason-counter').text(length + ' characters');
    });

    // Create counter for amendment reason
    $('#amendment_reason').after('<div class="text-muted small mt-1"><span id="reason-counter">0 characters</span></div>');
    $('#amendment_reason').trigger('input');
});
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
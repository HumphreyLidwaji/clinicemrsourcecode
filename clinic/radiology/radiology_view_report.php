<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Get report ID from URL
$report_id = intval($_GET['report_id']);

// Fetch radiology report details
$report_sql = "SELECT rr.*, 
                      p.patient_first_name, p.patient_last_name, p.patient_mrn, p.patient_gender, p.patient_dob,
                      p.patient_phone, p.patient_email,
                      u.user_name as referring_doctor_name,
                      ru.user_name as radiologist_name, 
                      cb.user_name as created_by_name,
                      fb.user_name as finalized_by_name,
                      ro.order_number, ro.order_priority, ro.clinical_notes as order_clinical_notes,
                      d.department_name
                     
               FROM radiology_reports rr
               LEFT JOIN patients p ON rr.patient_id = p.patient_id
               LEFT JOIN users u ON rr.referring_doctor_id = u.user_id
               LEFT JOIN users ru ON rr.radiologist_id = ru.user_id
               LEFT JOIN users cb ON rr.created_by = cb.user_id
               LEFT JOIN users fb ON rr.finalized_by = fb.user_id
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
    header("Location: radiology_imaging_orders.php");
    exit;
}

$report = $report_result->fetch_assoc();

// Fetch report studies with details
$studies_sql = "SELECT rrs.*, 
                       ros.scheduled_date, ros.performed_date,
                       ri.imaging_name, ri.imaging_code, ri.fee_amount,
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

// Fetch report versions for history
$versions_sql = "SELECT rrv.*, u.user_name as amended_by_name
                 FROM radiology_report_versions rrv
                 LEFT JOIN users u ON rrv.amended_by = u.user_id
                 WHERE rrv.report_id = ?
                 ORDER BY rrv.amended_at DESC";
$versions_stmt = $mysqli->prepare($versions_sql);
$versions_stmt->bind_param("i", $report_id);
$versions_stmt->execute();
$versions_result = $versions_stmt->get_result();

// Calculate patient age
$patient_age = "";
if (!empty($report['patient_dob'])) {
    $birthDate = new DateTime($report['patient_dob']);
    $today = new DateTime();
    $age = $today->diff($birthDate)->y;
    $patient_age = "$age years";
}

// Build facility address
$facility_address = "";
if (!empty($report['ward'])) {
    $facility_address .= $report['ward'];
}
if (!empty($report['sub_county'])) {
    $facility_address .= $facility_address ? ", " . $report['sub_county'] : $report['sub_county'];
}
if (!empty($report['county'])) {
    $facility_address .= $facility_address ? ", " . $report['county'] : $report['county'];
}

// Handle report actions
if (isset($_GET['action'])) {
    $action = sanitizeInput($_GET['action']);
    $csrf_token = sanitizeInput($_GET['csrf_token']);
    
    // Validate CSRF token
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        header("Location: radiology_view_report.php?report_id=" . $report_id);
        exit;
    }

    switch ($action) {
        case 'finalize':
            if ($report['report_status'] == 'draft') {
                $finalize_sql = "UPDATE radiology_reports SET 
                               report_status = 'final',
                               finalized_at = NOW(),
                               finalized_by = ?
                               WHERE report_id = ?";
                $finalize_stmt = $mysqli->prepare($finalize_sql);
                $finalize_stmt->bind_param("ii", $session_user_id, $report_id);
                
                if ($finalize_stmt->execute()) {
                    // Log activity
                    $activity_desc = "Finalized radiology report: " . $report['report_number'];
                    $activity_sql = "INSERT INTO activity_logs SET 
                                    activity_description = ?, 
                                    activity_created_by = ?, 
                                    activity_date = NOW()";
                    $activity_stmt = $mysqli->prepare($activity_sql);
                    $activity_stmt->bind_param("si", $activity_desc, $session_user_id);
                    $activity_stmt->execute();
                    $activity_stmt->close();
                    
                    $_SESSION['alert_type'] = "success";
                    $_SESSION['alert_message'] = "Report finalized successfully!";
                } else {
                    $_SESSION['alert_type'] = "error";
                    $_SESSION['alert_message'] = "Error finalizing report: " . $mysqli->error;
                }
                $finalize_stmt->close();
            }
            break;
            
        case 'amend':
            // Create a new version before amendment
            $version_sql = "INSERT INTO radiology_report_versions SET 
                          report_id = ?,
                          version_number = (SELECT COALESCE(MAX(version_number), 0) + 1 FROM radiology_report_versions WHERE report_id = ?),
                          findings = ?,
                          impression = ?,
                          recommendations = ?,
                          amended_by = ?,
                          amendment_reason = 'Manual amendment'";
            $version_stmt = $mysqli->prepare($version_sql);
            $version_stmt->bind_param("iissii", $report_id, $report_id, $report['findings'], $report['impression'], $report['recommendations'], $session_user_id);
            $version_stmt->execute();
            $version_stmt->close();
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Report version saved. You can now amend the report.";
            header("Location: radiology_amend_report.php?report_id=" . $report_id);
            exit;
            break;
    }
    
    header("Location: radiology_view_report.php?report_id=" . $report_id);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Radiology Report - <?php echo htmlspecialchars($report['report_number']); ?></title>
    <style>
        @media print {
            .no-print {
                display: none !important;
            }
            .card {
                border: none !important;
                box-shadow: none !important;
            }
            .container-fluid {
                padding: 0 !important;
            }
            body {
                font-size: 12px;
                line-height: 1.2;
            }
        }
        .report-header {
            border-bottom: 2px solid #333;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        .report-title {
            color: #2c3e50;
            font-weight: bold;
        }
        .patient-info-table th {
            background-color: #f8f9fa;
            width: 30%;
        }
        .findings-section {
            background-color: #f8f9fa;
            border-left: 4px solid #007bff;
            padding: 15px;
            margin: 10px 0;
        }
        .signature-line {
            border-top: 1px solid #000;
            margin-top: 50px;
            padding-top: 5px;
            width: 300px;
        }
        .watermark {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            font-size: 80px;
            color: rgba(0,0,0,0.1);
            z-index: -1;
            font-weight: bold;
        }
        .status-badge {
            font-size: 0.9em;
            padding: 0.5em 1em;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <!-- Action Buttons -->
        <div class="row no-print mb-3">
            <div class="col-12">
                <div class="btn-group">
                    <a href="radiology_imaging_orders.php" class="btn btn-light">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Orders
                    </a>
                      <?php if (SimplePermission::any([ 'radiology_view_order'])): ?>
                    <a href="radiology_order_details.php?id=<?php echo $report['radiology_order_id']; ?>" class="btn btn-info">
                        <i class="fas fa-clipboard-list mr-2"></i>View Order
                    </a>
                     <?php endif; ?>
                      <?php if (SimplePermission::any([ 'radiology_print_report'])): ?>
                    <button onclick="window.print()" class="btn btn-primary">
                        <i class="fas fa-print mr-2"></i>Print Report
                    </button>
                        <?php endif; ?>
                    <?php if ($report['report_status'] == 'draft'): ?>
                          <?php if (SimplePermission::any([ 'radiology_edit_report'])): ?>
                        <a href="radiology_amend_report.php?report_id=<?php echo $report_id; ?>" class="btn btn-warning">
                            <i class="fas fa-edit mr-2"></i>Edit Report
                        </a>
                        <?php endif; ?>
                          <?php if (SimplePermission::any([ 'radiology_finalize_report'])): ?>
                        <a href="radiology_view_report.php?report_id=<?php echo $report_id; ?>&action=finalize&csrf_token=<?php echo $_SESSION['csrf_token']; ?>" 
                           class="btn btn-success confirm-link" 
                           data-confirm-message="Are you sure you want to finalize this report? This action cannot be undone.">
                            <i class="fas fa-check-circle mr-2"></i>Finalize Report
                        </a>
                        <?php endif; ?>
                    <?php else: ?>
                          <?php if (SimplePermission::any([ 'radiology_amend_report'])): ?>
                        <a href="radiology_view_report.php?report_id=<?php echo $report_id; ?>&action=amend&csrf_token=<?php echo $_SESSION['csrf_token']; ?>" 
                           class="btn btn-warning confirm-link"
                           data-confirm-message="This will create a new version of the report for amendment. Continue?">
                            <i class="fas fa-file-medical-alt mr-2"></i>Amend Report
                        </a>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($versions_result->num_rows > 0): ?>
                        <button type="button" class="btn btn-secondary" data-toggle="modal" data-target="#versionsModal">
                            <i class="fas fa-history mr-2"></i>View History
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Alert Messages -->
        <?php if (isset($_SESSION['alert_message'])): ?>
            <div class="alert alert-<?php echo $_SESSION['alert_type']; ?> alert-dismissible no-print">
                <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
                <i class="icon fas fa-<?php echo $_SESSION['alert_type'] == 'success' ? 'check' : 'exclamation-triangle'; ?>"></i>
                <?php echo $_SESSION['alert_message']; ?>
            </div>
            <?php unset($_SESSION['alert_message'], $_SESSION['alert_type']); ?>
        <?php endif; ?>

        <!-- Radiology Report -->
        <div class="card">
            <div class="card-body">
                <!-- Report Header -->
                <div class="report-header">
                    <div class="row">
                        <div class="col-md-2">
                            <div style="height: 80px; background: #f8f9fa; display: flex; align-items: center; justify-content: center; border: 1px solid #dee2e6;">
                                <span class="text-muted">Facility Logo</span>
                            </div>
                        </div>
                        <div class="col-md-8 text-center">
                            <h1 class="report-title">RADIOLOGY REPORT</h1>
                            <h4 class="text-muted"><?php echo htmlspecialchars($report['facility_name'] ?? 'Medical Facility'); ?></h4>
                            <p class="mb-0"><?php echo htmlspecialchars($report['department_name'] ?? 'Radiology Department'); ?></p>
                            <small class="text-muted">
                                <?php if (!empty($facility_address)): ?>
                                    <?php echo htmlspecialchars($facility_address); ?>
                                <?php endif; ?>
                            </small>
                        </div>
                        <div class="col-md-2 text-right">
                            <strong>Report Date:</strong><br>
                            <?php echo date('M j, Y', strtotime($report['report_date'])); ?><br>
                            <strong>Report Status:</strong><br>
                            <?php
                            $status_badge = "";
                            switch($report['report_status']) {
                                case 'draft':
                                    $status_badge = "badge-warning";
                                    break;
                                case 'final':
                                    $status_badge = "badge-success";
                                    break;
                                case 'amended':
                                    $status_badge = "badge-info";
                                    break;
                                case 'cancelled':
                                    $status_badge = "badge-danger";
                                    break;
                            }
                            ?>
                            <span class="badge status-badge <?php echo $status_badge; ?>">
                                <?php echo ucfirst($report['report_status']); ?>
                            </span><br>
                            <small class="text-muted">Report #: <?php echo htmlspecialchars($report['report_number']); ?></small>
                        </div>
                    </div>
                </div>

                <!-- Patient Information -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <h4 class="text-primary">PATIENT INFORMATION</h4>
                        <table class="table table-sm patient-info-table">
                            <tr>
                                <th>Patient Name:</th>
                                <td><?php echo htmlspecialchars($report['patient_first_name'] . ' ' . $report['patient_last_name']); ?></td>
                            </tr>
                            <tr>
                                <th>MRN:</th>
                                <td><?php echo htmlspecialchars($report['patient_mrn']); ?></td>
                            </tr>
                            <tr>
                                <th>Date of Birth / Age:</th>
                                <td>
                                    <?php echo !empty($report['patient_dob']) ? date('M j, Y', strtotime($report['patient_dob'])) : 'N/A'; ?>
                                    <?php if ($patient_age): ?>
                                        (<?php echo $patient_age; ?>)
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th>Gender:</th>
                                <td><?php echo htmlspecialchars($report['patient_gender']); ?></td>
                            </tr>
                            <tr>
                                <th>Contact:</th>
                                <td>
                                    <?php if (!empty($report['patient_phone'])): ?>
                                        <?php echo htmlspecialchars($report['patient_phone']); ?>
                                    <?php endif; ?>
                                    <?php if (!empty($report['patient_email'])): ?>
                                        <br><?php echo htmlspecialchars($report['patient_email']); ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h4 class="text-primary">ORDER & REFERRAL INFORMATION</h4>
                        <table class="table table-sm patient-info-table">
                            <tr>
                                <th>Order Number:</th>
                                <td><?php echo htmlspecialchars($report['order_number']); ?></td>
                            </tr>
                            <tr>
                                <th>Referring Physician:</th>
                                <td>
                                    <?php echo htmlspecialchars($report['referring_doctor_name'] ?? 'N/A'); ?>
                                    <?php if (!empty($report['referring_doctor_credentials'])): ?>
                                        , <?php echo htmlspecialchars($report['referring_doctor_credentials']); ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th>Radiologist:</th>
                                <td>
                                    <?php echo htmlspecialchars($report['radiologist_name'] ?? 'Not assigned'); ?>
                                    <?php if (!empty($report['radiologist_credentials'])): ?>
                                        , <?php echo htmlspecialchars($report['radiologist_credentials']); ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th>Report Created By:</th>
                                <td>
                                    <?php echo htmlspecialchars($report['created_by_name']); ?>
                                    on <?php echo date('M j, Y g:i A', strtotime($report['created_at'])); ?>
                                </td>
                            </tr>
                            <?php if ($report['report_status'] == 'final'): ?>
                                <tr>
                                    <th>Report Finalized By:</th>
                                    <td>
                                        <?php echo htmlspecialchars($report['finalized_by_name']); ?>
                                        on <?php echo date('M j, Y g:i A', strtotime($report['finalized_at'])); ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>

                <!-- Clinical Information -->
                <?php if (!empty($report['clinical_history']) || !empty($report['technique']) || !empty($report['comparison'])): ?>
                <div class="row mb-4">
                    <div class="col-12">
                        <h4 class="text-primary">CLINICAL INFORMATION</h4>
                        <div class="card">
                            <div class="card-body">
                                <?php if (!empty($report['clinical_history'])): ?>
                                    <h6>Clinical History:</h6>
                                    <p><?php echo nl2br(htmlspecialchars($report['clinical_history'])); ?></p>
                                <?php endif; ?>
                                
                                <?php if (!empty($report['technique'])): ?>
                                    <h6>Technique:</h6>
                                    <p><?php echo nl2br(htmlspecialchars($report['technique'])); ?></p>
                                <?php endif; ?>
                                
                                <?php if (!empty($report['comparison'])): ?>
                                    <h6>Comparison:</h6>
                                    <p><?php echo nl2br(htmlspecialchars($report['comparison'])); ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Studies Performed -->
                <div class="row mb-4">
                    <div class="col-12">
                        <h4 class="text-primary">STUDIES PERFORMED</h4>
                        
                        <?php 
                        $study_count = 0;
                        while ($study = $studies_result->fetch_assoc()): 
                            $study_count++;
                        ?>
                            <div class="card mb-3">
                                <div class="card-header bg-light">
                                    <h5 class="mb-0">
                                        Study #<?php echo $study_count; ?>: 
                                        <?php echo htmlspecialchars($study['imaging_name']); ?>
                                        (<?php echo htmlspecialchars($study['imaging_code']); ?>)
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="row mb-3">
                                        <div class="col-md-4">
                                            <strong>Performed Date:</strong><br>
                                            <?php echo $study['performed_date'] ? date('M j, Y g:i A', strtotime($study['performed_date'])) : 'Not recorded'; ?>
                                        </div>
                                        <div class="col-md-4">
                                            <strong>Performed By:</strong><br>
                                            <?php echo htmlspecialchars($study['performed_by_name'] ?? 'Not recorded'); ?>
                                            <?php if (!empty($study['performed_by_credentials'])): ?>
                                                , <?php echo htmlspecialchars($study['performed_by_credentials']); ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="col-md-4">
                                            <strong>Study Fee:</strong><br>
                                            $<?php echo number_format($study['fee_amount'], 2); ?>
                                        </div>
                                    </div>

                                    <?php if (!empty($study['study_findings'])): ?>
                                        <div class="findings-section">
                                            <h6 class="text-primary">FINDINGS:</h6>
                                            <p><?php echo nl2br(htmlspecialchars($study['study_findings'])); ?></p>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($study['study_impression'])): ?>
                                        <div class="findings-section">
                                            <h6 class="text-primary">IMPRESSION:</h6>
                                            <p><?php echo nl2br(htmlspecialchars($study['study_impression'])); ?></p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                </div>

                <!-- Report Content -->
                <div class="row mb-4">
                    <div class="col-12">
                        <h4 class="text-primary">RADIOLOGY REPORT</h4>
                        <div class="card">
                            <div class="card-body">
                                <?php if (!empty($report['findings'])): ?>
                                    <div class="findings-section">
                                        <h6 class="text-primary">FINDINGS:</h6>
                                        <p><?php echo nl2br(htmlspecialchars($report['findings'])); ?></p>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($report['impression'])): ?>
                                    <div class="findings-section">
                                        <h6 class="text-primary">IMPRESSION:</h6>
                                        <p><?php echo nl2br(htmlspecialchars($report['impression'])); ?></p>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($report['recommendations'])): ?>
                                    <div class="findings-section">
                                        <h6 class="text-primary">RECOMMENDATIONS:</h6>
                                        <p><?php echo nl2br(htmlspecialchars($report['recommendations'])); ?></p>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($report['conclusion'])): ?>
                                    <div class="findings-section">
                                        <h6 class="text-primary">CONCLUSION:</h6>
                                        <p><?php echo nl2br(htmlspecialchars($report['conclusion'])); ?></p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Signatures -->
                <div class="row mt-5">
                    <div class="col-md-6 text-center">
                        <div class="signature-line"></div>
                        <p class="mb-0">
                            <strong>
                                <?php echo htmlspecialchars($report['radiologist_name'] ?? 'Radiologist'); ?>
                                <?php if (!empty($report['radiologist_credentials'])): ?>
                                    , <?php echo htmlspecialchars($report['radiologist_credentials']); ?>
                                <?php endif; ?>
                            </strong><br>
                            Radiologist
                        </p>
                    </div>
                    <div class="col-md-6 text-center">
                        <div class="signature-line"></div>
                        <p class="mb-0">
                            <strong>Date: <?php echo date('M j, Y'); ?></strong><br>
                            Report Generation Date
                        </p>
                    </div>
                </div>

                <!-- Footer -->
                <div class="row mt-4">
                    <div class="col-12 text-center">
                        <hr>
                        <small class="text-muted">
                            <?php if ($report['report_status'] == 'final'): ?>
                                <strong>FINAL REPORT</strong> - This report has been finalized and should not be amended without proper version control.<br>
                            <?php else: ?>
                                <strong>DRAFT REPORT</strong> - This is a draft report and has not been finalized.<br>
                            <?php endif; ?>
                            Confidentiality Notice: This document contains privileged and confidential information intended only for the healthcare provider ordering this study.<br>
                            Report ID: <?php echo htmlspecialchars($report['report_number']); ?> | Generated on: <?php echo date('M j, Y g:i A'); ?>
                        </small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Watermark for printed version -->
        <div class="watermark no-print">
            <?php echo htmlspecialchars($report['facility_name'] ?? 'MEDICAL REPORT'); ?>
            <?php if ($report['report_status'] == 'draft'): ?>
                <br>DRAFT
            <?php endif; ?>
        </div>
    </div>

    <!-- Report Versions Modal -->
    <?php if ($versions_result->num_rows > 0): ?>
    <div class="modal fade" id="versionsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Report Version History</h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Version</th>
                                    <th>Amended By</th>
                                    <th>Date & Time</th>
                                    <th>Amendment Reason</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($version = $versions_result->fetch_assoc()): ?>
                                    <tr>
                                        <td>v<?php echo $version['version_number']; ?></td>
                                        <td><?php echo htmlspecialchars($version['amended_by_name']); ?></td>
                                        <td><?php echo date('M j, Y g:i A', strtotime($version['amended_at'])); ?></td>
                                        <td><?php echo htmlspecialchars($version['amendment_reason'] ?? 'Not specified'); ?></td>
                                        <td>
                                            <button type="button" class="btn btn-sm btn-info view-version-btn" 
                                                    data-version-id="<?php echo $version['version_id']; ?>"
                                                    data-findings="<?php echo htmlspecialchars($version['findings'] ?? ''); ?>"
                                                    data-impression="<?php echo htmlspecialchars($version['impression'] ?? ''); ?>"
                                                    data-recommendations="<?php echo htmlspecialchars($version['recommendations'] ?? ''); ?>">
                                                <i class="fas fa-eye"></i> View
                                            </button>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Version Details Modal -->
    <div class="modal fade" id="versionDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Report Version Details</h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div id="versionDetailsContent"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script>
    $(document).ready(function() {
        // View version details
        $('.view-version-btn').click(function() {
            const findings = $(this).data('findings');
            const impression = $(this).data('impression');
            const recommendations = $(this).data('recommendations');
            
            let content = '';
            
            if (findings) {
                content += `<h6>Findings:</h6><p>${findings}</p><hr>`;
            }
            if (impression) {
                content += `<h6>Impression:</h6><p>${impression}</p><hr>`;
            }
            if (recommendations) {
                content += `<h6>Recommendations:</h6><p>${recommendations}</p>`;
            }
            
            if (!content) {
                content = '<p class="text-muted">No content available for this version.</p>';
            }
            
            $('#versionDetailsContent').html(content);
            $('#versionDetailsModal').modal('show');
        });

        // Print styles
        window.addEventListener('DOMContentLoaded', (event) => {
            const style = document.createElement('style');
            style.textContent = `
                @media print {
                    body { 
                        margin: 0; 
                        padding: 20px;
                        font-family: "Times New Roman", Times, serif;
                    }
                    .card { 
                        border: none !important; 
                        box-shadow: none !important; 
                    }
                    .btn-group { 
                        display: none !important; 
                    }
                    .watermark { 
                        display: block !important; 
                    }
                }
            `;
            document.head.appendChild(style);
        });
    });

    function emailReport() {
        const email = prompt('Enter email address to send report:');
        if (email) {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (emailRegex.test(email)) {
                alert('Report will be sent to: ' + email + '\n\nThis feature will be implemented in the next update.');
            } else {
                alert('Please enter a valid email address.');
            }
        }
    }
    </script>

    <!-- Include Bootstrap and Font Awesome for icons -->
    <link rel="stylesheet" href="/vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="/vendor/fontawesome/css/all.min.css">
    <script src="/vendor/jquery/jquery.min.js"></script>
    <script src="/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>
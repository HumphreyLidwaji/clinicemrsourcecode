<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Get order ID from URL
$order_id = intval($_GET['order_id']);

// Fetch radiology order details for report
$order_sql = "SELECT ro.*, 
                     p.patient_first_name, p.patient_last_name, p.patient_mrn, p.patient_gender, p.patient_dob, 
                     p.patient_phone, p.patient_email,
                     u.user_name as referring_doctor_name,
                     ru.user_name as radiologist_name,
                     d.department_name, 
                     fm.facility_name, fm.facility_code, fm.facility_type, fm.county, fm.sub_county, fm.ward
              FROM radiology_orders ro
              LEFT JOIN patients p ON ro.patient_id = p.patient_id
              LEFT JOIN users u ON ro.referring_doctor_id = u.user_id
              LEFT JOIN users ru ON ro.radiologist_id = ru.user_id
              LEFT JOIN departments d ON ro.department_id = d.department_id
              LEFT JOIN facility_master fm ON d.facility_id = fm.facility_id
              WHERE ro.radiology_order_id = ? AND fm.is_active = 1";
$order_stmt = $mysqli->prepare($order_sql);
$order_stmt->bind_param("i", $order_id);
$order_stmt->execute();
$order_result = $order_stmt->get_result();

if ($order_result->num_rows == 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Radiology order not found.";
    header("Location: radiology_orders.php");
    exit;
}

$order = $order_result->fetch_assoc();

// Fetch completed studies with findings
$studies_sql = "SELECT ros.*, ri.imaging_name, ri.imaging_code, ri.fee_amount,
                       u.user_name as performed_by_name, u.user_credentials as performed_by_credentials
                FROM radiology_order_studies ros
                LEFT JOIN radiology_imagings ri ON ros.imaging_id = ri.imaging_id
                LEFT JOIN users u ON ros.performed_by = u.user_id
                WHERE ros.radiology_order_id = ? AND ros.status = 'completed'
                ORDER BY ros.performed_date ASC";
$studies_stmt = $mysqli->prepare($studies_sql);
$studies_stmt->bind_param("i", $order_id);
$studies_stmt->execute();
$studies_result = $studies_stmt->get_result();

if ($studies_result->num_rows == 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "No completed studies found for this order. Cannot generate report.";
    header("Location: radiology_order_details.php?id=" . $order_id);
    exit;
}

// Calculate patient age
$patient_age = "";
if (!empty($order['patient_dob'])) {
    $birthDate = new DateTime($order['patient_dob']);
    $today = new DateTime();
    $age = $today->diff($birthDate)->y;
    $patient_age = "$age years";
}

// Build facility address from available fields
$facility_address = "";
if (!empty($order['ward'])) {
    $facility_address .= $order['ward'];
}
if (!empty($order['sub_county'])) {
    $facility_address .= $facility_address ? ", " . $order['sub_county'] : $order['sub_county'];
}
if (!empty($order['county'])) {
    $facility_address .= $facility_address ? ", " . $order['county'] : $order['county'];
}

// Handle PDF generation
if (isset($_GET['generate_pdf'])) {
    // We'll implement PDF generation here
    // For now, redirect back with message
    $_SESSION['alert_type'] = "info";
    $_SESSION['alert_message'] = "PDF generation feature will be implemented soon.";
    header("Location: radiology_generate_report.php?order_id=" . $order_id);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Radiology Report - Order #<?php echo htmlspecialchars($order['order_number']); ?></title>
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
        .facility-logo {
            max-height: 80px;
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
    </style>
</head>
<body>
    <div class="container-fluid">
        <!-- Action Buttons -->
        <div class="row no-print mb-3">
            <div class="col-12">
                <div class="btn-group">
                    <a href="radiology_order_details.php?id=<?php echo $order_id; ?>" class="btn btn-light">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Order
                    </a>
                     <?php if (SimplePermission::any([ 'radiology_print_report'])): ?>
                    <button onclick="window.print()" class="btn btn-primary">
                        <i class="fas fa-print mr-2"></i>Print Report
                    </button>
                    <?php endif; ?>
                    <a href="radiology_generate_report.php?order_id=<?php echo $order_id; ?>&generate_pdf=1" class="btn btn-success">
                        <i class="fas fa-file-pdf mr-2"></i>Download PDF
                    </a>
                    <button onclick="emailReport()" class="btn btn-info">
                        <i class="fas fa-envelope mr-2"></i>Email Report
                    </button>
                </div>
            </div>
        </div>

        <!-- Radiology Report -->
        <div class="card">
            <div class="card-body">
                <!-- Report Header -->
                <div class="report-header">
                    <div class="row">
                        <div class="col-md-2">
                            <?php if (!empty($order['facility_logo'])): ?>
                                <img src="<?php echo htmlspecialchars($order['facility_logo']); ?>" alt="Facility Logo" class="facility-logo">
                            <?php else: ?>
                                <div style="height: 80px; background: #f8f9fa; display: flex; align-items: center; justify-content: center; border: 1px solid #dee2e6;">
                                    <span class="text-muted">No Logo</span>
                                </div>
                            <?php endif; ?>
                        </div>
                     <!-- In the report header section, update the facility address display: -->
<div class="col-md-8 text-center">
    <h1 class="report-title">RADIOLOGY REPORT</h1>
    <h4 class="text-muted"><?php echo htmlspecialchars($order['facility_name'] ?? 'Medical Facility'); ?></h4>
    <p class="mb-0"><?php echo htmlspecialchars($order['department_name'] ?? 'Radiology Department'); ?></p>
    <small class="text-muted">
        <?php if (!empty($facility_address)): ?>
            <?php echo htmlspecialchars($facility_address); ?>
        <?php endif; ?>
        <?php if (!empty($order['department_phone'])): ?>
            <?php echo !empty($facility_address) ? ' | ' : ''; ?>
            Tel: <?php echo htmlspecialchars($order['department_phone']); ?>
        <?php endif; ?>
    </small>
    <?php if (!empty($order['facility_type'])): ?>
        <br><small class="text-muted">Type: <?php echo htmlspecialchars($order['facility_type']); ?></small>
    <?php endif; ?>
    <?php if (!empty($order['facility_code'])): ?>
        <br><small class="text-muted">Code: <?php echo htmlspecialchars($order['facility_code']); ?></small>
    <?php endif; ?>
</div>
                        <div class="col-md-2 text-right">
                            <strong>Report Date:</strong><br>
                            <?php echo date('M j, Y'); ?><br>
                            <strong>Report Time:</strong><br>
                            <?php echo date('g:i A'); ?><br>
                            <small class="text-muted">Report ID: <?php echo 'RAD-' . date('Ymd') . '-' . $order_id; ?></small>
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
                                <td><?php echo htmlspecialchars($order['patient_first_name'] . ' ' . $order['patient_last_name']); ?></td>
                            </tr>
                            <tr>
                                <th>MRN:</th>
                                <td><?php echo htmlspecialchars($order['patient_mrn']); ?></td>
                            </tr>
                            <tr>
                                <th>Date of Birth / Age:</th>
                                <td>
                                    <?php echo !empty($order['patient_dob']) ? date('M j, Y', strtotime($order['patient_dob'])) : 'N/A'; ?>
                                    <?php if ($patient_age): ?>
                                        (<?php echo $patient_age; ?>)
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th>Gender:</th>
                                <td><?php echo htmlspecialchars($order['patient_gender']); ?></td>
                            </tr>
                            <tr>
                                <th>Contact:</th>
                                <td>
                                    <?php if (!empty($order['patient_phone'])): ?>
                                        <?php echo htmlspecialchars($order['patient_phone']); ?>
                                    <?php endif; ?>
                                    <?php if (!empty($order['patient_email'])): ?>
                                        <br><?php echo htmlspecialchars($order['patient_email']); ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h4 class="text-primary">ORDER INFORMATION</h4>
                        <table class="table table-sm patient-info-table">
                            <tr>
                                <th>Order Number:</th>
                                <td><?php echo htmlspecialchars($order['order_number']); ?></td>
                            </tr>
                            <tr>
                                <th>Order Date:</th>
                                <td><?php echo date('M j, Y g:i A', strtotime($order['created_at'])); ?></td>
                            </tr>
                            <tr>
                                <th>Priority:</th>
                                <td>
                                    <span class="badge badge-<?php 
                                        echo $order['order_priority'] == 'stat' ? 'danger' : 
                                             ($order['order_priority'] == 'urgent' ? 'warning' : 'success'); 
                                    ?>">
                                        <?php echo ucfirst($order['order_priority']); ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <th>Referring Physician:</th>
                                <td>
                                    <?php echo htmlspecialchars($order['referring_doctor_name'] ?? 'N/A'); ?>
                                    <?php if (!empty($order['referring_doctor_phone'])): ?>
                                        <br>Tel: <?php echo htmlspecialchars($order['referring_doctor_phone']); ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th>Clinical History:</th>
                                <td><?php echo !empty($order['clinical_notes']) ? nl2br(htmlspecialchars($order['clinical_notes'])) : 'Not provided'; ?></td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!-- Studies and Findings -->
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

                                    <?php if (!empty($study['findings'])): ?>
                                        <div class="findings-section">
                                            <h6 class="text-primary">FINDINGS:</h6>
                                            <p><?php echo nl2br(htmlspecialchars($study['findings'])); ?></p>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($study['impression'])): ?>
                                        <div class="findings-section">
                                            <h6 class="text-primary">IMPRESSION:</h6>
                                            <p><?php echo nl2br(htmlspecialchars($study['impression'])); ?></p>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($study['recommendations'])): ?>
                                        <div class="findings-section">
                                            <h6 class="text-primary">RECOMMENDATIONS:</h6>
                                            <p><?php echo nl2br(htmlspecialchars($study['recommendations'])); ?></p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                </div>

                <!-- Overall Impression and Recommendations -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header bg-light">
                                <h5 class="mb-0">OVERALL ASSESSMENT</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6 class="text-primary">SUMMARY:</h6>
                                        <p>
                                            <?php 
                                            $total_studies = $study_count;
                                            $completed_date = $study['performed_date'] ?? $order['updated_at'];
                                            echo "This report summarizes the findings from $total_studies radiological " . ($total_studies > 1 ? 'studies' : 'study') . " performed";
                                            if ($completed_date) {
                                                echo " on " . date('M j, Y', strtotime($completed_date));
                                            }
                                            echo ".";
                                            ?>
                                        </p>
                                    </div>
                                    <div class="col-md-6">
                                        <h6 class="text-primary">REPORT STATUS:</h6>
                                        <p>
                                            <span class="badge badge-success">FINAL REPORT</span><br>
                                            Report generated electronically and requires no signature for validation.
                                        </p>
                                    </div>
                                </div>
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
                                <?php echo htmlspecialchars($order['radiologist_name'] ?? 'Radiologist'); ?>
                                <?php if (!empty($order['radiologist_credentials'])): ?>
                                    , <?php echo htmlspecialchars($order['radiologist_credentials']); ?>
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
                            This is an electronically generated report. No signature required.<br>
                            Confidentiality Notice: This document contains privileged and confidential information intended only for the use of the healthcare provider ordering this study.<br>
                            Report ID: <?php echo 'RAD-' . date('Ymd') . '-' . $order_id . '-' . time(); ?>
                        </small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Watermark for printed version -->
        <div class="watermark no-print">
            <?php echo htmlspecialchars($order['facility_name'] ?? 'MEDICAL REPORT'); ?>
        </div>
    </div>

    <script>
    function emailReport() {
        const email = prompt('Enter email address to send report:');
        if (email) {
            // Simple email validation
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (emailRegex.test(email)) {
                alert('Report will be sent to: ' + email + '\n\nThis feature will be implemented in the next update.');
                // Here you would typically make an AJAX call to send the email
            } else {
                alert('Please enter a valid email address.');
            }
        }
    }

    // Print styles
    window.addEventListener('DOMContentLoaded', (event) => {
        // Add print-specific styles
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
    </script>

    <!-- Include Bootstrap and Font Awesome for icons -->
    <link rel="stylesheet" href="/vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="/vendor/fontawesome/css/all.min.css">
    <script src="/vendor/jquery/jquery.min.js"></script>
    <script src="/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>
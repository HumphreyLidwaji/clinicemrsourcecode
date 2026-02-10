<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

$case_id = intval($_GET['id']);

// Get surgical case details
$sql = "SELECT sc.*, 
               p.patient_first_name, p.patient_last_name, p.patient_mrn, p.patient_gender, p.patient_dob, 
               p.patient_phone, p.patient_email, 
               ps.user_name as surgeon_name,
               a.user_name as anesthetist_name,
               rd.user_name as referring_doctor_name,
               t.theatre_name, t.theatre_number,
               creator.user_name as created_by_name,
               TIMESTAMPDIFF(MINUTE, sc.surgery_start_time, sc.surgery_end_time) as actual_duration
        FROM surgical_cases sc
        LEFT JOIN patients p ON sc.patient_id = p.patient_id
        LEFT JOIN users ps ON sc.primary_surgeon_id = ps.user_id
        LEFT JOIN users a ON sc.anesthetist_id = a.user_id
        LEFT JOIN users rd ON sc.referring_doctor_id = rd.user_id
        LEFT JOIN theatres t ON sc.theater_id = t.theatre_id
        LEFT JOIN users creator ON sc.created_by = creator.user_id
        WHERE sc.case_id = $case_id";

$result = $mysqli->query($sql);

if ($result->num_rows == 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Surgical case not found";
    header("Location: theatre_dashboard.php");
    exit();
}

$case = $result->fetch_assoc();

// Get surgical team
$team_sql = "SELECT st.*, u.user_name 
             FROM surgical_team st 
             JOIN users u ON st.user_id = u.user_id 
             WHERE st.case_id = $case_id 
             ORDER BY st.is_primary DESC, u.user_name";
$team_result = $mysqli->query($team_sql);

// Get complications
$complications_sql = "SELECT * FROM surgical_complications WHERE case_id = $case_id ";
$complications_result = $mysqli->query($complications_sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Surgical Case Print: <?php echo htmlspecialchars($case['case_number']); ?></title>
    <style>
        @media print {
            .no-print {
                display: none !important;
            }
            body {
                font-family: Arial, sans-serif;
                font-size: 12px;
            }
            .container {
                width: 100% !important;
                max-width: 100% !important;
                padding: 0 !important;
            }
        }
        body {
            font-family: Arial, sans-serif;
            background-color: #fff;
            color: #000;
        }
        .print-header {
            text-align: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #000;
        }
        .hospital-name {
            font-size: 24px;
            font-weight: bold;
        }
        .hospital-address {
            font-size: 12px;
            margin-bottom: 10px;
        }
        .case-title {
            font-size: 18px;
            font-weight: bold;
            margin: 20px 0;
            text-align: center;
        }
        .section {
            margin-bottom: 20px;
            page-break-inside: avoid;
        }
        .section-title {
            font-size: 14px;
            font-weight: bold;
            border-bottom: 1px solid #000;
            padding-bottom: 5px;
            margin-bottom: 10px;
        }
        .info-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }
        .info-table td {
            padding: 5px;
            border: 1px solid #ddd;
        }
        .info-table .label {
            font-weight: bold;
            width: 30%;
            background-color: #f5f5f5;
        }
        .signature-area {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #000;
        }
        .signature-line {
            width: 200px;
            border-top: 1px solid #000;
            margin: 40px 0 5px 0;
        }
        .page-break {
            page-break-before: always;
        }
        .watermark {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            font-size: 72px;
            color: rgba(0,0,0,0.1);
            z-index: -1;
            white-space: nowrap;
        }
    </style>
</head>
<body>
    <!-- Print controls -->
    <div class="no-print" style="padding: 20px; background-color: #f5f5f5; text-align: center;">
        <button onclick="window.print()" class="btn btn-primary">
            <i class="fas fa-print mr-2"></i>Print
        </button>
        <button onclick="window.close()" class="btn btn-secondary">
            <i class="fas fa-times mr-2"></i>Close
        </button>
        <button onclick="window.history.back()" class="btn btn-secondary">
            <i class="fas fa-arrow-left mr-2"></i>Back
        </button>
    </div>
    
    <div class="container">
        <!-- Watermark -->
        <div class="watermark">
            SURGICAL CASE RECORD
        </div>
        
        <!-- Header -->
        <div class="print-header">
            <div class="hospital-name">YOUR HOSPITAL NAME</div>
            <div class="hospital-address">Hospital Address Line 1 | Hospital Address Line 2 | Phone: (123) 456-7890</div>
            <div>SURGICAL CASE RECORD</div>
        </div>
        
        <!-- Case Information -->
        <div class="case-title">
            Surgical Case: <?php echo htmlspecialchars($case['case_number']); ?>
        </div>
        
        <div class="section">
            <div class="section-title">1. Basic Information</div>
            <table class="info-table">
                <tr>
                    <td class="label">Case Number</td>
                    <td><?php echo htmlspecialchars($case['case_number']); ?></td>
                    <td class="label">Case Status</td>
                    <td><?php echo strtoupper(str_replace('_', ' ', $case['case_status'])); ?></td>
                </tr>
                <tr>
                    <td class="label">Created Date</td>
                    <td><?php echo date('M j, Y H:i', strtotime($case['created_at'])); ?></td>
                    <td class="label">Created By</td>
                    <td><?php echo htmlspecialchars($case['created_by_name']); ?></td>
                </tr>
                <tr>
                    <td class="label">Surgical Urgency</td>
                    <td><?php echo ucfirst($case['surgical_urgency']); ?></td>
                    <td class="label">Surgical Specialty</td>
                    <td><?php echo htmlspecialchars($case['surgical_specialty']); ?></td>
                </tr>
            </table>
        </div>
        
        <div class="section">
            <div class="section-title">2. Patient Information</div>
            <table class="info-table">
                <tr>
                    <td class="label">Patient Name</td>
                    <td><?php echo htmlspecialchars($case['patient_first_name'] . ' ' . $case['patient_last_name']); ?></td>
                    <td class="label">MRN</td>
                    <td><?php echo htmlspecialchars($case['patient_mrn']); ?></td>
                </tr>
                <tr>
                    <td class="label">Date of Birth</td>
                    <td><?php echo $case['patient_dob'] ? date('M j, Y', strtotime($case['patient_dob'])) : 'N/A'; ?></td>
                    <td class="label">Gender</td>
                    <td><?php echo htmlspecialchars($case['patient_gender']); ?></td>
                </tr>
                <tr>
                    <td class="label">Contact</td>
                    <td><?php echo htmlspecialchars($case['patient_phone'] ?? 'N/A'); ?></td>
                    <td class="label">Email</td>
                    <td><?php echo htmlspecialchars($case['patient_email'] ?? 'N/A'); ?></td>
                </tr>
                <tr>
                    <td class="label">Address</td>
                    <td colspan="3"><?php echo htmlspecialchars($case['patient_address'] ?? 'N/A'); ?></td>
                </tr>
            </table>
        </div>
        
        <div class="section">
            <div class="section-title">3. Surgical Details</div>
            <table class="info-table">
                <tr>
                    <td class="label">Pre-operative Diagnosis</td>
                    <td colspan="3"><?php echo nl2br(htmlspecialchars($case['pre_op_diagnosis'])); ?></td>
                </tr>
                <tr>
                    <td class="label">Planned Procedure</td>
                    <td colspan="3"><?php echo nl2br(htmlspecialchars($case['planned_procedure'])); ?></td>
                </tr>
                <tr>
                    <td class="label">ASA Score</td>
                    <td><?php echo $case['asa_score'] ?: 'N/A'; ?></td>
                    <td class="label">Estimated Duration</td>
                    <td><?php echo $case['estimated_duration_minutes'] ? $case['estimated_duration_minutes'] . ' minutes' : 'N/A'; ?></td>
                </tr>
                <?php if($case['referral_source']): ?>
                <tr>
                    <td class="label">Referral Source</td>
                    <td colspan="3"><?php echo htmlspecialchars($case['referral_source']); ?></td>
                </tr>
                <?php endif; ?>
            </table>
        </div>
        
        <div class="section">
            <div class="section-title">4. Medical Team</div>
            <table class="info-table">
                <tr>
                    <td class="label">Primary Surgeon</td>
                    <td><?php echo htmlspecialchars($case['surgeon_name'] ?? 'N/A'); ?></td>
                    <td class="label">Anesthetist</td>
                    <td><?php echo htmlspecialchars($case['anesthetist_name'] ?? 'N/A'); ?></td>
                </tr>
                <tr>
                    <td class="label">Referring Doctor</td>
                    <td colspan="3"><?php echo htmlspecialchars($case['referring_doctor_name'] ?? 'N/A'); ?></td>
                </tr>
            </table>
            
            <?php if($team_result->num_rows > 0): ?>
            <table class="info-table">
                <tr>
                    <th>Team Member</th>
                    <th>Role</th>
                    <th>Primary</th>
                </tr>
                <?php while($member = $team_result->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($member['user_name']); ?></td>
                    <td><?php echo htmlspecialchars($member['role']); ?></td>
                    <td><?php echo $member['is_primary'] ? 'Yes' : 'No'; ?></td>
                </tr>
                <?php endwhile; ?>
            </table>
            <?php endif; ?>
        </div>
        
        <div class="section">
            <div class="section-title">5. Timeline</div>
            <table class="info-table">
                <tr>
                    <td class="label">Presentation Date</td>
                    <td><?php echo $case['presentation_date'] ? date('M j, Y', strtotime($case['presentation_date'])) : 'N/A'; ?></td>
                    <td class="label">Decision Date</td>
                    <td><?php echo $case['decision_date'] ? date('M j, Y', strtotime($case['decision_date'])) : 'N/A'; ?></td>
                </tr>
                <tr>
                    <td class="label">Target OR Date</td>
                    <td><?php echo $case['target_or_date'] ? date('M j, Y', strtotime($case['target_or_date'])) : 'N/A'; ?></td>
                    <td class="label">Surgery Date</td>
                    <td><?php echo $case['surgery_date'] ? date('M j, Y', strtotime($case['surgery_date'])) : 'N/A'; ?></td>
                </tr>
                <?php if($case['surgery_date']): ?>
                <tr>
                    <td class="label">Surgery Time</td>
                    <td colspan="3">
                        <?php echo $case['surgery_start_time'] ? date('H:i', strtotime($case['surgery_start_time'])) : ''; ?>
                        <?php if($case['surgery_end_time']): ?> - <?php echo date('H:i', strtotime($case['surgery_end_time'])); ?><?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td class="label">Theatre</td>
                    <td colspan="3"><?php echo htmlspecialchars($case['theatre_name'] ?? 'N/A'); ?></td>
                </tr>
                <?php if($case['actual_duration']): ?>
                <tr>
                    <td class="label">Actual Duration</td>
                    <td colspan="3"><?php echo $case['actual_duration']; ?> minutes</td>
                </tr>
                <?php endif; ?>
                <?php endif; ?>
            </table>
        </div>
        
        <?php if($complications_result->num_rows > 0): ?>
        <div class="section">
            <div class="section-title">6. Complications</div>
            <table class="info-table">
                <tr>
                    <th>Type</th>
                    <th>Severity</th>
                    <th>Description</th>
                    <th>Date</th>
                    <th>Status</th>
                </tr>
                <?php while($complication = $complications_result->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($complication['complication_type']); ?></td>
                    <td><?php echo ucfirst($complication['severity']); ?></td>
                    <td><?php echo htmlspecialchars($complication['description']); ?></td>
                    <td><?php echo $complication['occurred_at'] ? date('M j, Y H:i', strtotime($complication['occurred_at'])) : ''; ?></td>
                    <td><?php echo ucfirst($complication['complication_status']); ?></td>
                </tr>
                <?php endwhile; ?>
            </table>
        </div>
        <?php endif; ?>
        
        <?php if($case['notes']): ?>
        <div class="section">
            <div class="section-title">7. Additional Notes</div>
            <div style="border: 1px solid #ddd; padding: 10px; min-height: 100px;">
                <?php echo nl2br(htmlspecialchars($case['notes'])); ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Signatures -->
        <div class="section signature-area">
            <div style="float: left; width: 30%; text-align: center;">
                <div class="signature-line"></div>
                <div>Primary Surgeon</div>
                <div><?php echo htmlspecialchars($case['surgeon_name'] ?? ''); ?></div>
            </div>
            
            <div style="float: left; width: 30%; text-align: center; margin-left: 5%;">
                <div class="signature-line"></div>
                <div>Anesthetist</div>
                <div><?php echo htmlspecialchars($case['anesthetist_name'] ?? ''); ?></div>
            </div>
            
            <div style="float: left; width: 30%; text-align: center; margin-left: 5%;">
                <div class="signature-line"></div>
                <div>Date & Time</div>
                <div><?php echo date('M j, Y H:i'); ?></div>
            </div>
            <div style="clear: both;"></div>
        </div>
        
        <!-- Footer -->
        <div style="margin-top: 50px; font-size: 10px; text-align: center; color: #666;">
            <hr>
            <p>Document printed on: <?php echo date('M j, Y H:i:s'); ?> | User: <?php echo $_SESSION['user_name'] ?? 'System'; ?></p>
            <p>This is a system generated document. No physical signature is required.</p>
        </div>
    </div>
    
    <script>
        // Auto-print on page load
        window.onload = function() {
            // Optional: auto-print after 1 second
            // setTimeout(function() { window.print(); }, 1000);
        };
    </script>
</body>
</html>
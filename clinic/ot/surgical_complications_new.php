<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

$case_id = intval($_GET['case_id']);

// Get case details
$case_sql = "SELECT sc.*, p.first_name, p.last_name, p.patient_mrn 
             FROM surgical_cases sc 
             LEFT JOIN patients p ON sc.patient_id = p.patient_id 
             WHERE sc.case_id = $case_id";
$case_result = $mysqli->query($case_sql);

if ($case_result->num_rows == 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Surgical case not found";
    header("Location: theatre_dashboard.php");
    exit();
}

$case = $case_result->fetch_assoc();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $complication_type = sanitizeInput($_POST['complication_type']);
    $complication_category = sanitizeInput($_POST['complication_category']);
    $complication_description = sanitizeInput($_POST['complication_description']);
    $clavien_dindo_grade = sanitizeInput($_POST['clavien_dindo_grade']);
    $time_from_surgery = sanitizeInput($_POST['time_from_surgery']);
    $management_provided = sanitizeInput($_POST['management_provided']);
    $intervention_required = isset($_POST['intervention_required']) ? 1 : 0;
    $intervention_type = sanitizeInput($_POST['intervention_type']);
    $outcome = sanitizeInput($_POST['outcome']);
    $resolution_date = sanitizeInput($_POST['resolution_date']);
    $sequelae = sanitizeInput($_POST['sequelae']);
    $reported_by = intval($_POST['reported_by']);
    $reported_to_surgeon = isset($_POST['reported_to_surgeon']) ? 1 : 0;
    $reported_to_patient = isset($_POST['reported_to_patient']) ? 1 : 0;
    $contributing_factors = sanitizeInput($_POST['contributing_factors']);
    $immediate_action = sanitizeInput($_POST['immediate_action']);
    $follow_up_plan = sanitizeInput($_POST['follow_up_plan']);
    $estimated_resolution_date = sanitizeInput($_POST['estimated_resolution_date']);
    $preventive_measures = sanitizeInput($_POST['preventive_measures']);
    $follow_up_required = isset($_POST['follow_up_required']) ? 1 : 0;
    $follow_up_notes = sanitizeInput($_POST['follow_up_notes']);
    $severity = sanitizeInput($_POST['severity']);
    $occurred_at = sanitizeInput($_POST['occurred_at']);
    $detected_by = intval($_POST['detected_by']);
    $anatomical_location = sanitizeInput($_POST['anatomical_location']);
    $intraoperative = isset($_POST['intraoperative']) ? 1 : 0;
    $postoperative = isset($_POST['postoperative']) ? 1 : 0;
    
    // Insert complication
    $sql = "INSERT INTO surgical_complications SET
            case_id = $case_id,
            complication_type = '$complication_type',
            complication_category = '$complication_category',
            complication_description = '$complication_description',
            clavien_dindo_grade = '$clavien_dindo_grade',
            time_from_surgery = '$time_from_surgery',
            management_provided = '$management_provided',
            intervention_required = $intervention_required,
            intervention_type = '$intervention_type',
            outcome = '$outcome',
            resolution_date = " . ($resolution_date ? "'$resolution_date'" : "NULL") . ",
            sequelae = '$sequelae',
            reported_by = $reported_by,
            reported_to_surgeon = $reported_to_surgeon,
            reported_to_patient = $reported_to_patient,
            contributing_factors = '$contributing_factors',
            immediate_action = '$immediate_action',
            follow_up_plan = '$follow_up_plan',
            estimated_resolution_date = " . ($estimated_resolution_date ? "'$estimated_resolution_date'" : "NULL") . ",
            preventive_measures = '$preventive_measures',
            follow_up_required = $follow_up_required,
            follow_up_notes = '$follow_up_notes',
            complication_status = 'active',
            severity = '$severity',
            occurred_at = " . ($occurred_at ? "'$occurred_at'" : "NULL") . ",
            detected_by = $detected_by,
            anatomical_location = '$anatomical_location',
            intraoperative = $intraoperative,
            postoperative = $postoperative,
            created_by = " . intval($_SESSION['user_id']);
    
    if ($mysqli->query($sql)) {
        // Log activity
        $log_description = "Recorded complication: $complication_type for case: " . $case['case_number'];
        mysqli_query($mysqli, "INSERT INTO logs SET log_type = 'Surgical Complication', log_action = 'Create', log_description = '$log_description', log_ip = '$session_ip', log_user_agent = '$session_user_agent', log_user_id = $session_user_id");
        
        $_SESSION['alert_message'] = "Complication recorded successfully";
        header("Location: surgical_complications.php?case_id=$case_id");
        exit();
    } else {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error recording complication: " . $mysqli->error;
    }
}

// Get users for dropdowns
$users_sql = "SELECT user_id, user_name FROM users ORDER BY user_name";
$users_result = $mysqli->query($users_sql);

// Complication types (based on table enum)
$complication_types = [
    'Intraoperative' => 'Intraoperative',
    'Postoperative' => 'Postoperative',
    'Anesthesia' => 'Anesthesia',
    'Other' => 'Other'
];

// Complication categories
$complication_categories = [
    'Bleeding/Hematoma',
    'Infection',
    'Wound Dehiscence',
    'Anastomotic Leak',
    'Organ Injury',
    'Nerve Injury',
    'Deep Vein Thrombosis',
    'Pulmonary Embolism',
    'Pneumonia',
    'Urinary Tract Infection',
    'Cardiac Event',
    'Stroke',
    'Medication Error',
    'Equipment Failure',
    'Systemic Complication'
];

// Clavien-Dindo grades
$clavien_dindo_grades = [
    'I' => 'Grade I - Any deviation from normal postop course without need for intervention',
    'II' => 'Grade II - Requiring pharmacological treatment',
    'IIIa' => 'Grade IIIa - Intervention not under general anesthesia',
    'IIIb' => 'Grade IIIb - Intervention under general anesthesia',
    'IVa' => 'Grade IVa - Single organ dysfunction',
    'IVb' => 'Grade IVb - Multi-organ dysfunction',
    'V' => 'Grade V - Death'
];

// Outcomes
$outcomes = [
    'Resolved' => 'Resolved',
    'Improved' => 'Improved',
    'Unchanged' => 'Unchanged',
    'Worsened' => 'Worsened',
    'Death' => 'Death'
];

// Anatomical locations
$anatomical_locations = [
    'Head/Neck',
    'Thorax',
    'Abdomen',
    'Pelvis',
    'Upper Limb',
    'Lower Limb',
    'Spine',
    'Cardiovascular',
    'Neurological',
    'Multiple Sites',
    'Systemic'
];

// Time from surgery options
$time_from_surgery_options = [
    'Intraoperative',
    'Immediate (0-24 hours)',
    'Early (1-7 days)',
    'Intermediate (1-4 weeks)',
    'Late (>4 weeks)'
];

// Severity levels
$severity_levels = [
    'Minor' => 'Minor - No long-term effects',
    'Moderate' => 'Moderate - Requires intervention',
    'Severe' => 'Severe - Permanent damage',
    'Critical' => 'Critical - Life-threatening'
];

// Get existing complications for this case
$existing_complications_sql = "SELECT * FROM surgical_complications WHERE case_id = $case_id";
$existing_complications_result = $mysqli->query($existing_complications_sql);
$has_existing_complications = $existing_complications_result->num_rows > 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Surgical Complication - Case: <?php echo htmlspecialchars($case['case_number']); ?></title>
    <?php include_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php'; ?>
    <style>
        .case-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .clavien-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 3px;
            font-weight: bold;
            font-size: 0.9em;
            margin-right: 5px;
        }
        .clavien-I { background-color: #28a745; color: white; }
        .clavien-II { background-color: #ffc107; color: #212529; }
        .clavien-IIIa { background-color: #fd7e14; color: white; }
        .clavien-IIIb { background-color: #dc3545; color: white; }
        .clavien-IVa { background-color: #6f42c1; color: white; }
        .clavien-IVb { background-color: #e83e8c; color: white; }
        .clavien-V { background-color: #343a40; color: white; }
        .section-card {
            border: 1px solid #dee2e6;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .section-header {
            background-color: #e9ecef;
            padding: 10px 15px;
            border-bottom: 1px solid #dee2e6;
            font-weight: bold;
        }
        .section-body {
            padding: 15px;
        }
        .timeline-badge {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            margin-right: 15px;
        }
        .timeline-badge.intra { background-color: #007bff; }
        .timeline-badge.post { background-color: #6c757d; }
        .risk-factor {
            display: inline-block;
            background-color: #e9ecef;
            border: 1px solid #dee2e6;
            border-radius: 20px;
            padding: 3px 10px;
            margin: 2px;
            font-size: 0.9em;
            cursor: pointer;
        }
        .risk-factor:hover {
            background-color: #dc3545;
            color: white;
            border-color: #dc3545;
        }
        .risk-factor.selected {
            background-color: #dc3545;
            color: white;
            border-color: #dc3545;
        }
        .warning-card {
            border-left: 4px solid #dc3545;
            background-color: #fff5f5;
        }
        .severity-indicator {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 8px;
        }
        .severity-minor { background-color: #28a745; }
        .severity-moderate { background-color: #ffc107; }
        .severity-severe { background-color: #fd7e14; }
        .severity-critical { background-color: #dc3545; }
    </style>
</head>
<body>
    
    <div class="container-fluid">
        <div class="row">
            <div class="col-lg-12">
                <!-- Case Header -->
                <div class="case-header">
                    <div class="row">
                        <div class="col-md-8">
                            <h3 class="mb-1"><i class="fas fa-exclamation-triangle mr-2"></i>New Surgical Complication</h3>
                            <p class="mb-0">
                                Case: <?php echo htmlspecialchars($case['case_number']); ?> | 
                                Patient: <?php echo htmlspecialchars($case['first_name'] . ' ' . $case['last_name']); ?> | 
                                MRN: <?php echo htmlspecialchars($case['patient_mrn']); ?>
                            </p>
                        </div>
                        <div class="col-md-4 text-right">
                            <a href="surgical_case_view.php?id=<?php echo $case_id; ?>" class="btn btn-light btn-sm">
                                <i class="fas fa-arrow-left mr-1"></i>Back to Case
                            </a>
                            <a href="surgical_complications.php?case_id=<?php echo $case_id; ?>" class="btn btn-light btn-sm">
                                <i class="fas fa-list mr-1"></i>View All
                            </a>
                        </div>
                    </div>
                </div>
                
                <?php if ($has_existing_complications): ?>
                <div class="alert alert-warning mb-4">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    <strong>Note:</strong> This case already has <?php echo $existing_complications_result->num_rows; ?> recorded complication(s). 
                    <a href="surgical_complications.php?case_id=<?php echo $case_id; ?>" class="alert-link">View existing complications</a>
                </div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div class="row">
                        <div class="col-md-8">
                            <!-- Complication Classification -->
                            <div class="section-card">
                                <div class="section-header">
                                    <i class="fas fa-tags mr-2"></i>Complication Classification
                                </div>
                                <div class="section-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label>Complication Type *</label>
                                                <select class="form-control" name="complication_type" required>
                                                    <option value="">Select Type</option>
                                                    <?php foreach($complication_types as $key => $value): ?>
                                                        <option value="<?php echo $key; ?>"><?php echo $value; ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label>Complication Category</label>
                                                <select class="form-control select2" name="complication_category">
                                                    <option value="">Select Category</option>
                                                    <?php foreach($complication_categories as $category): ?>
                                                        <option value="<?php echo $category; ?>"><?php echo $category; ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label>Clavien-Dindo Grade *</label>
                                                <select class="form-control" name="clavien_dindo_grade" id="clavienSelect" required>
                                                    <option value="">Select Grade</option>
                                                    <?php foreach($clavien_dindo_grades as $key => $description): ?>
                                                        <option value="<?php echo $key; ?>"><?php echo $key . ' - ' . $description; ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <div id="clavienIndicator" class="mt-2" style="display: none;">
                                                    <div class="d-flex align-items-center">
                                                        <span class="clavien-badge" id="clavienBadge"></span>
                                                        <span id="clavienDescription"></span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label>Severity</label>
                                                <select class="form-control" name="severity" id="severitySelect">
                                                    <option value="">Select Severity</option>
                                                    <?php foreach($severity_levels as $key => $description): ?>
                                                        <option value="<?php echo $key; ?>"><?php echo $key; ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <div id="severityIndicator" class="mt-2" style="display: none;">
                                                    <div class="d-flex align-items-center">
                                                        <span class="severity-indicator" id="severityColor"></span>
                                                        <span id="severityDescription"></span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label>Time from Surgery</label>
                                                <select class="form-control" name="time_from_surgery">
                                                    <option value="">Select Time Frame</option>
                                                    <?php foreach($time_from_surgery_options as $time): ?>
                                                        <option value="<?php echo $time; ?>"><?php echo $time; ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label>Anatomical Location</label>
                                                <select class="form-control select2" name="anatomical_location">
                                                    <option value="">Select Location</option>
                                                    <?php foreach($anatomical_locations as $location): ?>
                                                        <option value="<?php echo $location; ?>"><?php echo $location; ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Complication Description -->
                            <div class="section-card">
                                <div class="section-header">
                                    <i class="fas fa-edit mr-2"></i>Complication Description
                                </div>
                                <div class="section-body">
                                    <div class="form-group">
                                        <label>Description *</label>
                                        <textarea class="form-control" name="complication_description" rows="4" required placeholder="Describe the complication in detail including signs, symptoms, and findings"></textarea>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label>Date & Time Occurred</label>
                                                <input type="datetime-local" class="form-control" name="occurred_at" value="<?php echo date('Y-m-d\TH:i'); ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label>Timing</label>
                                                <div class="mt-2">
                                                    <div class="form-check form-check-inline">
                                                        <input class="form-check-input" type="checkbox" name="intraoperative" id="intraoperative" value="1">
                                                        <label class="form-check-label" for="intraoperative">
                                                            <span class="timeline-badge intra">I</span> Intraoperative
                                                        </label>
                                                    </div>
                                                    <div class="form-check form-check-inline">
                                                        <input class="form-check-input" type="checkbox" name="postoperative" id="postoperative" value="1">
                                                        <label class="form-check-label" for="postoperative">
                                                            <span class="timeline-badge post">P</span> Postoperative
                                                        </label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label>Sequelae / Long-term Effects</label>
                                        <textarea class="form-control" name="sequelae" rows="2" placeholder="Describe any long-term effects or sequelae"></textarea>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Management & Intervention -->
                            <div class="section-card">
                                <div class="section-header">
                                    <i class="fas fa-stethoscope mr-2"></i>Management & Intervention
                                </div>
                                <div class="section-body">
                                    <div class="form-group">
                                        <label>Management Provided</label>
                                        <textarea class="form-control" name="management_provided" rows="3" placeholder="Describe the management provided for this complication"></textarea>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label>Immediate Action Taken</label>
                                        <textarea class="form-control" name="immediate_action" rows="2" placeholder="Describe immediate actions taken"></textarea>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="intervention_required" id="intervention_required" value="1">
                                                    <label class="form-check-label" for="intervention_required">
                                                        Intervention Required
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label>Intervention Type</label>
                                                <input type="text" class="form-control" name="intervention_type" placeholder="e.g., Surgical re-exploration, Antibiotics, etc.">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Outcome & Follow-up -->
                            <div class="section-card">
                                <div class="section-header">
                                    <i class="fas fa-chart-line mr-2"></i>Outcome & Follow-up
                                </div>
                                <div class="section-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label>Outcome</label>
                                                <select class="form-control" name="outcome">
                                                    <option value="">Select Outcome</option>
                                                    <?php foreach($outcomes as $key => $value): ?>
                                                        <option value="<?php echo $key; ?>"><?php echo $value; ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label>Resolution Date</label>
                                                <input type="date" class="form-control" name="resolution_date">
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label>Follow-up Plan</label>
                                        <textarea class="form-control" name="follow_up_plan" rows="2" placeholder="Describe the follow-up plan"></textarea>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label>Estimated Resolution Date</label>
                                                <input type="date" class="form-control" name="estimated_resolution_date">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <div class="form-check mt-4">
                                                    <input class="form-check-input" type="checkbox" name="follow_up_required" id="follow_up_required" value="1">
                                                    <label class="form-check-label" for="follow_up_required">
                                                        Follow-up Required
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label>Follow-up Notes</label>
                                        <textarea class="form-control" name="follow_up_notes" rows="2" placeholder="Additional follow-up notes"></textarea>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label>Preventive Measures</label>
                                        <textarea class="form-control" name="preventive_measures" rows="2" placeholder="Measures to prevent similar complications in the future"></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-4">
                            <!-- Contributing Factors -->
                            <div class="section-card">
                                <div class="section-header">
                                    <i class="fas fa-search mr-2"></i>Contributing Factors
                                </div>
                                <div class="section-body">
                                    <div class="form-group">
                                        <label>Contributing Factors</label>
                                        <textarea class="form-control" name="contributing_factors" rows="4" placeholder="List any factors that may have contributed to this complication"></textarea>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <small class="text-muted">Common Risk Factors:</small>
                                        <div id="riskFactorTags" class="mt-2">
                                            <?php
                                            $risk_factors = [
                                                'Patient comorbidities',
                                                'Emergency surgery',
                                                'Complex procedure',
                                                'Prolonged surgery time',
                                                'Technical difficulty',
                                                'Equipment issues',
                                                'Inadequate planning',
                                                'Communication issues',
                                                'Staffing issues',
                                                'Patient non-compliance',
                                                'Medication error',
                                                'Anesthesia factors',
                                                'Surgical technique',
                                                'Infection control breach'
                                            ];
                                            foreach ($risk_factors as $factor):
                                            ?>
                                                <span class="risk-factor" data-factor="<?php echo $factor; ?>"><?php echo $factor; ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Reporting & Documentation -->
                            <div class="section-card">
                                <div class="section-header">
                                    <i class="fas fa-flag mr-2"></i>Reporting & Documentation
                                </div>
                                <div class="section-body">
                                    <div class="form-group">
                                        <label>Reported By *</label>
                                        <select class="form-control select2" name="reported_by" required>
                                            <option value="">Select Person</option>
                                            <?php 
                                            mysqli_data_seek($users_result, 0);
                                            while($user = $users_result->fetch_assoc()): ?>
                                                <option value="<?php echo $user['user_id']; ?>">
                                                    <?php echo htmlspecialchars($user['user_name']); ?>
                                                </option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label>Detected By</label>
                                        <select class="form-control select2" name="detected_by">
                                            <option value="">Select Person</option>
                                            <?php 
                                            mysqli_data_seek($users_result, 0);
                                            while($user = $users_result->fetch_assoc()): ?>
                                                <option value="<?php echo $user['user_id']; ?>">
                                                    <?php echo htmlspecialchars($user['user_name']); ?>
                                                </option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" name="reported_to_surgeon" id="reported_to_surgeon" value="1" checked>
                                        <label class="form-check-label" for="reported_to_surgeon">
                                            Reported to Surgeon
                                        </label>
                                    </div>
                                    
                                    <div class="form-check mb-3">
                                        <input class="form-check-input" type="checkbox" name="reported_to_patient" id="reported_to_patient" value="1" checked>
                                        <label class="form-check-label" for="reported_to_patient">
                                            Reported to Patient/Family
                                        </label>
                                    </div>
                                    
                                    <div class="alert alert-warning small">
                                        <i class="fas fa-exclamation-triangle mr-2"></i>
                                        <strong>Grade III+ complications</strong> must be reported to hospital administration within 24 hours.
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Clavien-Dindo Classification -->
                            <div class="card mb-4">
                                <div class="card-header bg-light">
                                    <h5 class="mb-0"><i class="fas fa-graduation-cap mr-2"></i>Clavien-Dindo Classification</h5>
                                </div>
                                <div class="card-body">
                                    <div class="mb-2">
                                        <span class="clavien-badge clavien-I">I</span>
                                        <small>Any deviation from normal postop course</small>
                                    </div>
                                    <div class="mb-2">
                                        <span class="clavien-badge clavien-II">II</span>
                                        <small>Requiring pharmacological treatment</small>
                                    </div>
                                    <div class="mb-2">
                                        <span class="clavien-badge clavien-IIIa">IIIa</span>
                                        <small>Intervention not under GA</small>
                                    </div>
                                    <div class="mb-2">
                                        <span class="clavien-badge clavien-IIIb">IIIb</span>
                                        <small>Intervention under GA</small>
                                    </div>
                                    <div class="mb-2">
                                        <span class="clavien-badge clavien-IVa">IVa</span>
                                        <small>Single organ dysfunction</small>
                                    </div>
                                    <div class="mb-2">
                                        <span class="clavien-badge clavien-IVb">IVb</span>
                                        <small>Multi-organ dysfunction</small>
                                    </div>
                                    <div class="mb-2">
                                        <span class="clavien-badge clavien-V">V</span>
                                        <small>Death</small>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Quick Templates -->
                            <div class="card">
                                <div class="card-header bg-light">
                                    <h5 class="mb-0"><i class="fas fa-clipboard-list mr-2"></i>Quick Templates</h5>
                                </div>
                                <div class="card-body">
                                    <div class="btn-group-vertical btn-block">
                                        <button type="button" class="btn btn-sm btn-outline-secondary mb-2" onclick="loadTemplate('infection')">
                                            <i class="fas fa-bacteria mr-2"></i>Surgical Site Infection
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary mb-2" onclick="loadTemplate('bleeding')">
                                            <i class="fas fa-tint mr-2"></i>Post-op Bleeding
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary mb-2" onclick="loadTemplate('wound')">
                                            <i class="fas fa-cut mr-2"></i>Wound Dehiscence
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="loadTemplate('pneumonia')">
                                            <i class="fas fa-lungs mr-2"></i>Post-op Pneumonia
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Form Actions -->
                    <div class="row">
                        <div class="col-md-12">
                            <div class="card">
                                <div class="card-body text-right">
                                    <a href="surgical_case_view.php?id=<?php echo $case_id; ?>" class="btn btn-secondary">
                                        <i class="fas fa-times mr-2"></i>Cancel
                                    </a>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save mr-2"></i>Save Complication
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <?php include_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
    <script>
        $(document).ready(function() {
            $('.select2').select2();
            
            // Clavien-Dindo grade indicator
            $('#clavienSelect').change(function() {
                var grade = $(this).val();
                var indicator = $('#clavienIndicator');
                var badge = $('#clavienBadge');
                var description = $('#clavienDescription');
                
                if (grade) {
                    var gradeInfo = {
                        'I': { class: 'clavien-I', text: 'Any deviation from normal postop course without need for intervention' },
                        'II': { class: 'clavien-II', text: 'Requiring pharmacological treatment' },
                        'IIIa': { class: 'clavien-IIIa', text: 'Intervention not under general anesthesia' },
                        'IIIb': { class: 'clavien-IIIb', text: 'Intervention under general anesthesia' },
                        'IVa': { class: 'clavien-IVa', text: 'Single organ dysfunction' },
                        'IVb': { class: 'clavien-IVb', text: 'Multi-organ dysfunction' },
                        'V': { class: 'clavien-V', text: 'Death' }
                    };
                    
                    badge.removeClass().addClass('clavien-badge ' + gradeInfo[grade].class).text(grade);
                    description.text(gradeInfo[grade].text);
                    indicator.show();
                } else {
                    indicator.hide();
                }
            });
            
            // Severity indicator
            $('#severitySelect').change(function() {
                var severity = $(this).val();
                var indicator = $('#severityIndicator');
                var color = $('#severityColor');
                var description = $('#severityDescription');
                
                if (severity) {
                    var severityInfo = {
                        'Minor': { color: 'severity-minor', text: 'Minor - No long-term effects' },
                        'Moderate': { color: 'severity-moderate', text: 'Moderate - Requires intervention' },
                        'Severe': { color: 'severity-severe', text: 'Severe - Permanent damage' },
                        'Critical': { color: 'severity-critical', text: 'Critical - Life-threatening' }
                    };
                    
                    color.removeClass().addClass('severity-indicator ' + severityInfo[severity].color);
                    description.text(severityInfo[severity].text);
                    indicator.show();
                } else {
                    indicator.hide();
                }
            });
            
            // Risk factor tags
            $('.risk-factor').click(function() {
                $(this).toggleClass('selected');
                updateContributingFactors();
            });
            
            function updateContributingFactors() {
                var selectedFactors = [];
                $('.risk-factor.selected').each(function() {
                    selectedFactors.push($(this).data('factor'));
                });
                
                var currentText = $('textarea[name="contributing_factors"]').val();
                if (currentText) {
                    $('textarea[name="contributing_factors"]').val(currentText + '\n• ' + selectedFactors.join('\n• '));
                } else {
                    $('textarea[name="contributing_factors"]').val('• ' + selectedFactors.join('\n• '));
                }
                
                // Clear selection after adding
                $('.risk-factor.selected').removeClass('selected');
            }
            
            // Quick templates
            window.loadTemplate = function(templateType) {
                var templates = {
                    'infection': {
                        type: 'Postoperative',
                        category: 'Infection',
                        clavien: 'II',
                        severity: 'Moderate',
                        description: 'Surgical site infection with erythema, warmth, and purulent drainage from incision site. Patient febrile (38.5°C). Wound culture sent for analysis.',
                        time: 'Early (1-7 days)',
                        location: 'Abdomen',
                        management: 'Incision and drainage performed at bedside. Wound packed with saline gauze. Antibiotics started (Cefazolin 1g IV q8h).',
                        intervention: 1,
                        intervention_type: 'Incision & drainage, Antibiotics',
                        factors: 'Patient comorbidities, Prolonged surgery time',
                        action: 'Bedside I&D performed, antibiotics initiated',
                        followup: 'Daily dressing changes, antibiotic therapy for 7 days, follow-up in clinic in 1 week.'
                    },
                    'bleeding': {
                        type: 'Postoperative',
                        category: 'Bleeding/Hematoma',
                        clavien: 'IIIb',
                        severity: 'Severe',
                        description: 'Significant postoperative bleeding with drop in hemoglobin from 12.5 to 8.2 g/dL. Patient tachycardic and hypotensive. Large hematoma at surgical site.',
                        time: 'Immediate (0-24 hours)',
                        location: 'Abdomen',
                        management: 'Emergency return to OR for exploration and hemostasis. 2 units PRBC transfused. Surgical hemostasis achieved.',
                        intervention: 1,
                        intervention_type: 'Surgical re-exploration, Blood transfusion',
                        factors: 'Complex procedure, Technical difficulty',
                        action: 'Emergency return to OR, transfusion',
                        followup: 'Monitor hemoglobin, watch for signs of re-bleeding, serial abdominal exams.'
                    },
                    'wound': {
                        type: 'Postoperative',
                        category: 'Wound Dehiscence',
                        clavien: 'IIIa',
                        severity: 'Moderate',
                        description: 'Partial wound dehiscence with superficial separation of incision edges. No fascial involvement. Small amount of serosanguinous drainage.',
                        time: 'Early (1-7 days)',
                        location: 'Abdomen',
                        management: 'Wound care with daily dressing changes. Secondary intention healing.',
                        intervention: 0,
                        factors: 'Patient comorbidities, Poor nutritional status',
                        action: 'Daily wound care initiated',
                        followup: 'Daily dressing changes, nutritional optimization, follow-up in 1 week.'
                    },
                    'pneumonia': {
                        type: 'Postoperative',
                        category: 'Pneumonia',
                        clavien: 'II',
                        severity: 'Moderate',
                        description: 'Hospital-acquired pneumonia with fever, productive cough, and new infiltrate on chest X-ray. Sputum culture pending.',
                        time: 'Early (1-7 days)',
                        location: 'Thorax',
                        management: 'Broad-spectrum antibiotics started (Piperacillin-tazobactam 4.5g IV q6h). Chest physiotherapy initiated.',
                        intervention: 1,
                        intervention_type: 'Antibiotics, Chest physiotherapy',
                        factors: 'Prolonged surgery time, Patient comorbidities',
                        action: 'Antibiotics started, respiratory therapy',
                        followup: 'Complete antibiotic course, repeat CXR in 1 week, pulmonary follow-up.'
                    }
                };
                
                var template = templates[templateType];
                if (template) {
                    $('select[name="complication_type"]').val(template.type);
                    $('select[name="complication_category"]').val(template.category).trigger('change');
                    $('select[name="clavien_dindo_grade"]').val(template.clavien).trigger('change');
                    $('select[name="severity"]').val(template.severity).trigger('change');
                    $('textarea[name="complication_description"]').val(template.description);
                    $('select[name="time_from_surgery"]').val(template.time);
                    $('select[name="anatomical_location"]').val(template.location).trigger('change');
                    $('textarea[name="management_provided"]').val(template.management);
                    $('#intervention_required').prop('checked', template.intervention);
                    $('input[name="intervention_type"]').val(template.intervention_type || '');
                    $('textarea[name="contributing_factors"]').val(template.factors);
                    $('textarea[name="immediate_action"]').val(template.action);
                    $('textarea[name="follow_up_plan"]').val(template.followup);
                    
                    // Set timing checkboxes
                    if (template.type === 'Intraoperative') {
                        $('#intraoperative').prop('checked', true);
                    } else {
                        $('#postoperative').prop('checked', true);
                    }
                    
                    // Show success message
                    alert(templateType.charAt(0).toUpperCase() + templateType.slice(1) + ' template loaded. Please review and customize the details.');
                }
            };
            
            // Auto-fill detected by with reported by if empty
            $('select[name="reported_by"]').change(function() {
                if (!$('select[name="detected_by"]').val()) {
                    $('select[name="detected_by"]').val($(this).val()).trigger('change');
                }
            });
        });
    </script>
</body>
</html>
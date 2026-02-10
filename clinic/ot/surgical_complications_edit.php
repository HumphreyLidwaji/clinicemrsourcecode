<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

$complication_id = intval($_GET['id']);

// Get complication details
$sql = "SELECT sc.*, 
               sc.case_id,
               sc.patient_first_name, sc.patient_last_name, sc.patient_mrn,
               sc.case_number, sc.planned_procedure,
               u.user_name as reported_by_name,
               d.user_name as detected_by_name,
               DATEDIFF(CURDATE(), sc.occurred_at) as days_since_occurrence
        FROM (
            SELECT sc.*, 
                   c.case_number, c.planned_procedure,
                   p.patient_first_name, p.patient_last_name, p.patient_mrn
            FROM surgical_complications sc
            LEFT JOIN surgical_cases c ON sc.case_id = c.case_id
            LEFT JOIN patients p ON c.patient_id = p.patient_id
            WHERE sc.complication_id = $complication_id
        ) sc
        LEFT JOIN users u ON sc.reported_by = u.user_id
        LEFT JOIN users d ON sc.detected_by = d.user_id";

$result = $mysqli->query($sql);

if ($result->num_rows == 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Surgical complication not found";
    header("Location: theatre_dashboard.php");
    exit();
}

$complication = $result->fetch_assoc();
$case_id = $complication['case_id'];

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
    $complication_status = sanitizeInput($_POST['complication_status']);
    $severity = sanitizeInput($_POST['severity']);
    $occurred_at = sanitizeInput($_POST['occurred_at']);
    $detected_by = intval($_POST['detected_by']);
    $anatomical_location = sanitizeInput($_POST['anatomical_location']);
    $intraoperative = isset($_POST['intraoperative']) ? 1 : 0;
    $postoperative = isset($_POST['postoperative']) ? 1 : 0;
    
    // Update complication
    $update_sql = "UPDATE surgical_complications SET
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
                   complication_status = '$complication_status',
                   severity = '$severity',
                   occurred_at = " . ($occurred_at ? "'$occurred_at'" : "NULL") . ",
                   detected_by = $detected_by,
                   anatomical_location = '$anatomical_location',
                   intraoperative = $intraoperative,
                   postoperative = $postoperative,
                   updated_at = NOW()
                   WHERE complication_id = $complication_id";
    
    if ($mysqli->query($update_sql)) {
        // Log activity
        $log_description = "Updated complication: " . $complication['complication_category'] . " for case: " . $complication['case_number'];
        mysqli_query($mysqli, "INSERT INTO logs SET log_type = 'Surgical Complication', log_action = 'Update', log_description = '$log_description', log_ip = '$session_ip', log_user_agent = '$session_user_agent', log_user_id = $session_user_id");
        
        $_SESSION['alert_message'] = "Complication updated successfully";
        header("Location: surgical_complications_view.php?id=$complication_id");
        exit();
    } else {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error updating complication: " . $mysqli->error;
    }
}

// Get users for dropdowns
$users_sql = "SELECT user_id, user_name FROM users ORDER BY user_name";
$users_result = $mysqli->query($users_sql);

// Complication types
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

// Status options
$status_options = [
    'active' => 'Active',
    'resolved' => 'Resolved',
    'monitoring' => 'Under Monitoring'
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Complication - <?php echo htmlspecialchars($complication['complication_category']); ?></title>
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
        .status-badge {
            padding: 4px 10px;
            border-radius: 3px;
            font-size: 0.9em;
            font-weight: bold;
        }
        .status-active { background-color: #dc3545; color: white; }
        .status-resolved { background-color: #28a745; color: white; }
        .status-monitoring { background-color: #ffc107; color: #212529; }
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
                            <h3 class="mb-1"><i class="fas fa-edit mr-2"></i>Edit Complication</h3>
                            <p class="mb-0">
                                Case: <?php echo htmlspecialchars($complication['case_number']); ?> | 
                                Patient: <?php echo htmlspecialchars($complication['patient_first_name'] . ' ' . $complication['patient_last_name']); ?> | 
                                MRN: <?php echo htmlspecialchars($complication['patient_mrn']); ?>
                            </p>
                        </div>
                        <div class="col-md-4 text-right">
                            <a href="surgical_complications_view.php?id=<?php echo $complication_id; ?>" class="btn btn-light btn-sm">
                                <i class="fas fa-eye mr-1"></i>View
                            </a>
                            <a href="surgical_complications.php?case_id=<?php echo $case_id; ?>" class="btn btn-light btn-sm">
                                <i class="fas fa-list mr-1"></i>All Complications
                            </a>
                            <a href="surgical_case_view.php?id=<?php echo $case_id; ?>" class="btn btn-light btn-sm">
                                <i class="fas fa-arrow-left mr-1"></i>Back to Case
                            </a>
                        </div>
                    </div>
                </div>
                
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
                                                        <option value="<?php echo $key; ?>" <?php if($complication['complication_type'] == $key) echo 'selected'; ?>>
                                                            <?php echo $value; ?>
                                                        </option>
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
                                                        <option value="<?php echo $category; ?>" <?php if($complication['complication_category'] == $category) echo 'selected'; ?>>
                                                            <?php echo $category; ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="form-group">
                                                <label>Clavien-Dindo Grade *</label>
                                                <select class="form-control" name="clavien_dindo_grade" id="clavienSelect" required>
                                                    <option value="">Select Grade</option>
                                                    <?php foreach($clavien_dindo_grades as $key => $description): ?>
                                                        <option value="<?php echo $key; ?>" <?php if($complication['clavien_dindo_grade'] == $key) echo 'selected'; ?>>
                                                            <?php echo $key; ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-group">
                                                <label>Severity</label>
                                                <select class="form-control" name="severity" id="severitySelect">
                                                    <option value="">Select Severity</option>
                                                    <?php foreach($severity_levels as $key => $description): ?>
                                                        <option value="<?php echo $key; ?>" <?php if($complication['severity'] == $key) echo 'selected'; ?>>
                                                            <?php echo $key; ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-group">
                                                <label>Status</label>
                                                <select class="form-control" name="complication_status" required>
                                                    <?php foreach($status_options as $key => $value): ?>
                                                        <option value="<?php echo $key; ?>" <?php if($complication['complication_status'] == $key) echo 'selected'; ?>>
                                                            <?php echo $value; ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
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
                                                        <option value="<?php echo $time; ?>" <?php if($complication['time_from_surgery'] == $time) echo 'selected'; ?>>
                                                            <?php echo $time; ?>
                                                        </option>
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
                                                        <option value="<?php echo $location; ?>" <?php if($complication['anatomical_location'] == $location) echo 'selected'; ?>>
                                                            <?php echo $location; ?>
                                                        </option>
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
                                        <textarea class="form-control" name="complication_description" rows="4" required><?php echo htmlspecialchars($complication['complication_description']); ?></textarea>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label>Date & Time Occurred</label>
                                                <input type="datetime-local" class="form-control" name="occurred_at" 
                                                       value="<?php echo $complication['occurred_at'] ? date('Y-m-d\TH:i', strtotime($complication['occurred_at'])) : ''; ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label>Timing</label>
                                                <div class="mt-2">
                                                    <div class="form-check form-check-inline">
                                                        <input class="form-check-input" type="checkbox" name="intraoperative" id="intraoperative" value="1" <?php if($complication['intraoperative']) echo 'checked'; ?>>
                                                        <label class="form-check-label" for="intraoperative">
                                                            Intraoperative
                                                        </label>
                                                    </div>
                                                    <div class="form-check form-check-inline">
                                                        <input class="form-check-input" type="checkbox" name="postoperative" id="postoperative" value="1" <?php if($complication['postoperative']) echo 'checked'; ?>>
                                                        <label class="form-check-label" for="postoperative">
                                                            Postoperative
                                                        </label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label>Sequelae / Long-term Effects</label>
                                        <textarea class="form-control" name="sequelae" rows="2"><?php echo htmlspecialchars($complication['sequelae']); ?></textarea>
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
                                        <textarea class="form-control" name="management_provided" rows="3"><?php echo htmlspecialchars($complication['management_provided']); ?></textarea>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label>Immediate Action Taken</label>
                                        <textarea class="form-control" name="immediate_action" rows="2"><?php echo htmlspecialchars($complication['immediate_action']); ?></textarea>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="intervention_required" id="intervention_required" value="1" <?php if($complication['intervention_required']) echo 'checked'; ?>>
                                                    <label class="form-check-label" for="intervention_required">
                                                        Intervention Required
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label>Intervention Type</label>
                                                <input type="text" class="form-control" name="intervention_type" value="<?php echo htmlspecialchars($complication['intervention_type']); ?>">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-4">
                            <!-- Outcome & Follow-up -->
                            <div class="section-card mb-4">
                                <div class="section-header">
                                    <i class="fas fa-chart-line mr-2"></i>Outcome & Follow-up
                                </div>
                                <div class="section-body">
                                    <div class="row">
                                        <div class="col-md-12">
                                            <div class="form-group">
                                                <label>Outcome</label>
                                                <select class="form-control" name="outcome">
                                                    <option value="">Select Outcome</option>
                                                    <?php foreach($outcomes as $key => $value): ?>
                                                        <option value="<?php echo $key; ?>" <?php if($complication['outcome'] == $key) echo 'selected'; ?>>
                                                            <?php echo $value; ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label>Resolution Date</label>
                                                <input type="date" class="form-control" name="resolution_date" 
                                                       value="<?php echo $complication['resolution_date'] ? date('Y-m-d', strtotime($complication['resolution_date'])) : ''; ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label>Estimated Resolution</label>
                                                <input type="date" class="form-control" name="estimated_resolution_date" 
                                                       value="<?php echo $complication['estimated_resolution_date'] ? date('Y-m-d', strtotime($complication['estimated_resolution_date'])) : ''; ?>">
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label>Follow-up Plan</label>
                                        <textarea class="form-control" name="follow_up_plan" rows="2"><?php echo htmlspecialchars($complication['follow_up_plan']); ?></textarea>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-12">
                                            <div class="form-group">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="follow_up_required" id="follow_up_required" value="1" <?php if($complication['follow_up_required']) echo 'checked'; ?>>
                                                    <label class="form-check-label" for="follow_up_required">
                                                        Follow-up Required
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label>Follow-up Notes</label>
                                        <textarea class="form-control" name="follow_up_notes" rows="2"><?php echo htmlspecialchars($complication['follow_up_notes']); ?></textarea>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label>Preventive Measures</label>
                                        <textarea class="form-control" name="preventive_measures" rows="2"><?php echo htmlspecialchars($complication['preventive_measures']); ?></textarea>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Contributing Factors -->
                            <div class="section-card mb-4">
                                <div class="section-header">
                                    <i class="fas fa-search mr-2"></i>Contributing Factors
                                </div>
                                <div class="section-body">
                                    <div class="form-group">
                                        <label>Contributing Factors</label>
                                        <textarea class="form-control" name="contributing_factors" rows="4"><?php echo htmlspecialchars($complication['contributing_factors']); ?></textarea>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Reporting & Documentation -->
                            <div class="section-card mb-4">
                                <div class="section-header">
                                    <i class="fas fa-flag mr-2"></i>Reporting & Documentation
                                </div>
                                <div class="section-body">
                                    <div class="row">
                                        <div class="col-md-12">
                                            <div class="form-group">
                                                <label>Reported By *</label>
                                                <select class="form-control select2" name="reported_by" required>
                                                    <option value="">Select Person</option>
                                                    <?php 
                                                    mysqli_data_seek($users_result, 0);
                                                    while($user = $users_result->fetch_assoc()): ?>
                                                        <option value="<?php echo $user['user_id']; ?>" <?php if($complication['reported_by'] == $user['user_id']) echo 'selected'; ?>>
                                                            <?php echo htmlspecialchars($user['user_name']); ?>
                                                        </option>
                                                    <?php endwhile; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-12">
                                            <div class="form-group">
                                                <label>Detected By</label>
                                                <select class="form-control select2" name="detected_by">
                                                    <option value="">Select Person</option>
                                                    <?php 
                                                    mysqli_data_seek($users_result, 0);
                                                    while($user = $users_result->fetch_assoc()): ?>
                                                        <option value="<?php echo $user['user_id']; ?>" <?php if($complication['detected_by'] == $user['user_id']) echo 'selected'; ?>>
                                                            <?php echo htmlspecialchars($user['user_name']); ?>
                                                        </option>
                                                    <?php endwhile; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="reported_to_surgeon" id="reported_to_surgeon" value="1" <?php if($complication['reported_to_surgeon']) echo 'checked'; ?>>
                                                <label class="form-check-label" for="reported_to_surgeon">
                                                    Reported to Surgeon
                                                </label>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="reported_to_patient" id="reported_to_patient" value="1" <?php if($complication['reported_to_patient']) echo 'checked'; ?>>
                                                <label class="form-check-label" for="reported_to_patient">
                                                    Reported to Patient/Family
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Last Updated -->
                            <div class="card">
                                <div class="card-header bg-light">
                                    <h5 class="mb-0"><i class="fas fa-history mr-2"></i>Document Information</h5>
                                </div>
                                <div class="card-body">
                                    <?php
                                    // Get update history
                                    $history_sql = "SELECT log_description, log_created_at 
                                                   FROM logs 
                                                   WHERE log_type = 'Surgical Complication' 
                                                   AND log_description LIKE '%" . $complication['complication_category'] . "%'
                                                   AND log_description LIKE '%" . $complication['case_number'] . "%'
                                                   ORDER BY log_created_at DESC 
                                                   LIMIT 3";
                                    $history_result = $mysqli->query($history_sql);
                                    ?>
                                    
                                    <div class="small">
                                        <strong>Days Since Occurrence:</strong> 
                                        <?php echo $complication['days_since_occurrence'] ?: 'N/A'; ?>
                                        <br>
                                        <strong>Reported By:</strong> 
                                        <?php echo htmlspecialchars($complication['reported_by_name']); ?>
                                        <br>
                                        <strong>Detected By:</strong> 
                                        <?php echo htmlspecialchars($complication['detected_by_name'] ?: 'N/A'); ?>
                                    </div>
                                    
                                    <?php if ($history_result->num_rows > 0): ?>
                                        <hr class="my-2">
                                        <small class="text-muted">Recent Activity:</small>
                                        <?php while ($history = $history_result->fetch_assoc()): ?>
                                            <div class="mt-1">
                                                <small class="text-muted"><?php echo date('M j, H:i', strtotime($history['log_created_at'])); ?></small>
                                                <div class="small"><?php echo htmlspecialchars($history['log_description']); ?></div>
                                            </div>
                                        <?php endwhile; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Form Actions -->
                    <div class="row">
                        <div class="col-md-12">
                            <div class="card">
                                <div class="card-body text-right">
                                    <a href="surgical_complications_view.php?id=<?php echo $complication_id; ?>" class="btn btn-secondary">
                                        <i class="fas fa-times mr-2"></i>Cancel
                                    </a>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save mr-2"></i>Save Changes
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
            
            // Update severity indicator
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
            
            // Trigger change on load if severity is set
            <?php if ($complication['severity']): ?>
            $('#severitySelect').trigger('change');
            <?php endif; ?>
            
            // Auto-fill detected by with reported by if empty
            $('select[name="reported_by"]').change(function() {
                if (!$('select[name="detected_by"]').val()) {
                    $('select[name="detected_by"]').val($(this).val()).trigger('change');
                }
            });
            
            // Set resolution date to today if status is resolved
            $('select[name="complication_status"]').change(function() {
                if ($(this).val() === 'resolved' && !$('input[name="resolution_date"]').val()) {
                    var today = new Date().toISOString().split('T')[0];
                    $('input[name="resolution_date"]').val(today);
                }
            });
            
            // Keyboard shortcuts
            $(document).keydown(function(e) {
                // Ctrl + S to save
                if (e.ctrlKey && e.keyCode === 83) {
                    e.preventDefault();
                    $('button[type="submit"]').click();
                }
                // Esc to cancel
                if (e.keyCode === 27) {
                    window.location.href = 'surgical_complications_view.php?id=<?php echo $complication_id; ?>';
                }
            });
        });
    </script>
</body>
</html>
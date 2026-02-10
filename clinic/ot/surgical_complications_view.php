<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Get complication ID from URL
$complication_id = intval($_GET['id'] ?? 0);

if ($complication_id <= 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid complication ID.";
    header("Location: surgical_complications.php");
    exit;
}

// Fetch complication details with related information
$complication_sql = "SELECT sc.*,
                            u.user_name as reported_by_name,
                            d.user_name as detected_by_name,
                            uc.user_name as created_by_name
                           
                       
                     FROM surgical_complications sc
                     LEFT JOIN users u ON sc.reported_by = u.user_id
                     LEFT JOIN users d ON sc.detected_by = d.user_id
                     LEFT JOIN users uc ON sc.created_by = uc.user_id
                     
                     WHERE sc.complication_id = ?";

$complication_stmt = $mysqli->prepare($complication_sql);
$complication_stmt->bind_param("i", $complication_id);
$complication_stmt->execute();
$complication_result = $complication_stmt->get_result();

if ($complication_result->num_rows === 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Surgical complication not found.";
    header("Location: surgical_complications.php");
    exit;
}

$complication = $complication_result->fetch_assoc();

// Check if patient information is available
$has_patient_info = !empty($complication['patient_id']);

// Check for intraoperative/postoperative flags
$is_intraoperative = $complication['intraoperative'] ?? 0;
$is_postoperative = $complication['postoperative'] ?? 0;

// Determine primary complication type from flags if type field is empty
if (empty($complication['complication_type']) && ($is_intraoperative || $is_postoperative)) {
    if ($is_intraoperative) {
        $complication['complication_type'] = 'Intraoperative';
    } elseif ($is_postoperative) {
        $complication['complication_type'] = 'Postoperative';
    }
}

// Fetch follow-up notes from the same table (follow_up_notes field)
$has_followup_notes = !empty($complication['follow_up_notes']);

// Clavien-Dindo grade styling
$grade_colors = [
    'I' => 'badge-info',
    'II' => 'badge-primary',
    'IIIa' => 'badge-warning',
    'IIIb' => 'badge-warning',
    'IVa' => 'badge-danger',
    'IVb' => 'badge-danger',
    'V' => 'badge-dark'
];

$grade_labels = [
    'I' => 'Grade I: Any deviation from normal postoperative course without need for pharmacological, surgical, endoscopic, or radiological interventions',
    'II' => 'Grade II: Requiring pharmacological treatment with drugs other than those allowed for grade I complications',
    'IIIa' => 'Grade IIIa: Intervention not under general anesthesia',
    'IIIb' => 'Grade IIIb: Intervention under general anesthesia',
    'IVa' => 'Grade IVa: Single organ dysfunction (including dialysis)',
    'IVb' => 'Grade IVb: Multi organ dysfunction',
    'V' => 'Grade V: Death of a patient'
];

// Outcome styling - note: outcome field is now varchar(50), not enum
$outcome_colors = [
    'Resolved' => 'badge-success',
    'Improved' => 'badge-primary',
    'Unchanged' => 'badge-warning',
    'Worsened' => 'badge-danger',
    'Death' => 'badge-dark',
    'Recovered' => 'badge-success',
    'Ongoing' => 'badge-info',
    'Resolving' => 'badge-primary',
    'Chronic' => 'badge-secondary'
];

// Complication type styling
$type_colors = [
    'Intraoperative' => 'badge-danger',
    'Postoperative' => 'badge-warning',
    'Anesthesia' => 'badge-info',
    'Other' => 'badge-secondary'
];

// Status styling
$status_colors = [
    'Active' => 'badge-warning',
    'Resolved' => 'badge-success',
    'Monitoring' => 'badge-info',
    'Under Treatment' => 'badge-primary',
    'Chronic' => 'badge-secondary',
    'Pending' => 'badge-warning',
    'Closed' => 'badge-secondary'
];

// Severity styling
$severity_colors = [
    'Minor' => 'badge-info',
    'Moderate' => 'badge-warning',
    'Major' => 'badge-danger',
    'Severe' => 'badge-dark',
    'Critical' => 'badge-danger'
];
?>

<div class="card">
    <div class="card-header bg-danger py-2">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h3 class="card-title mt-2 mb-0 text-white">
                    <i class="fas fa-fw fa-exclamation-triangle mr-2"></i>
                    Surgical Complication
                </h3>
                <small class="text-white-50">Complication ID: <?php echo $complication_id; ?></small>
            </div>
            <div class="card-tools">
                <span class="badge <?php echo $grade_colors[$complication['clavien_dindo_grade']] ?? 'badge-secondary'; ?> badge-lg mr-2">
                    <i class="fas fa-fw fa-biohazard mr-1"></i>
                    Clavien-Dindo <?php echo htmlspecialchars($complication['clavien_dindo_grade']); ?>
                </span>
                <a href="surgical_complications_edit.php?id=<?php echo $complication_id; ?>" class="btn btn-warning btn-sm">
                    <i class="fas fa-edit mr-1"></i>Edit
                </a>
            </div>
        </div>
    </div>

    <!-- Alert Messages -->
    <?php if (isset($_SESSION['alert_message'])): ?>
        <div class="alert alert-<?php echo $_SESSION['alert_type']; ?> alert-dismissible m-3">
            <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
            <i class="icon fas fa-<?php echo $_SESSION['alert_type'] == 'success' ? 'check' : 'exclamation-triangle'; ?>"></i>
            <?php echo $_SESSION['alert_message']; ?>
        </div>
        <?php 
        unset($_SESSION['alert_type']);
        unset($_SESSION['alert_message']);
        ?>
    <?php endif; ?>

    <div class="card-body">
        <!-- Quick Actions -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="btn-toolbar justify-content-between">
                    <div class="btn-group">
                        <a href="surgical_complications_new.php" class="btn btn-success">
                            <i class="fas fa-plus mr-2"></i>Add New Complication
                        </a>
                        <button type="button" class="btn btn-info dropdown-toggle" data-toggle="dropdown">
                            <i class="fas fa-cog mr-2"></i>Quick Actions
                        </button>
                        <div class="dropdown-menu">
                            <a class="dropdown-item" href="#" data-toggle="modal" data-target="#addFollowupModal">
                                <i class="fas fa-sticky-note mr-2"></i>Update Follow-up Notes
                            </a>
                            <?php if($complication['follow_up_required']): ?>
                                <a class="dropdown-item" href="#" data-toggle="modal" data-target="#scheduleFollowupModal">
                                    <i class="fas fa-calendar-plus mr-2"></i>Schedule Follow-up
                                </a>
                            <?php endif; ?>
                            <div class="dropdown-divider"></div>
                            <?php if($has_patient_info): ?>
                                <a class="dropdown-item" href="patient_view.php?id=<?php echo $complication['patient_id']; ?>">
                                    <i class="fas fa-user-injured mr-2"></i>View Patient
                                </a>
                            <?php endif; ?>
                            <?php if($complication['case_id']): ?>
                                <a class="dropdown-item" href="case_view.php?id=<?php echo $complication['case_id']; ?>">
                                    <i class="fas fa-folder mr-2"></i>View Case
                                </a>
                            <?php endif; ?>
                        </div>
                        <a href="surgical_complications_print.php?id=<?php echo $complication_id; ?>" class="btn btn-secondary" target="_blank">
                            <i class="fas fa-print mr-2"></i>Print
                        </a>
                    </div>
                    <div class="btn-group">
                        <a href="surgical_complications.php" class="btn btn-default">
                            <i class="fas fa-arrow-left mr-2"></i>Back to Complications
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Left Column - Complication Details -->
            <div class="col-lg-4">
                <?php if($has_patient_info): ?>
                <!-- Patient Information Card -->
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0"><i class="fas fa-user-injured mr-2"></i>Patient Information</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm table-borderless">
                            <tr>
                                <td class="text-muted" width="40%">Patient:</td>
                                <td class="font-weight-bold">
                                    <?php echo htmlspecialchars($complication['patient_first_name'] . ' ' . $complication['patient_last_name']); ?>
                                </td>
                            </tr>
                            <tr>
                                <td class="text-muted">MRN:</td>
                                <td><?php echo htmlspecialchars($complication['patient_mrn']); ?></td>
                            </tr>
                            <?php if($complication['case_number']): ?>
                            <tr>
                                <td class="text-muted">Case:</td>
                                <td><?php echo htmlspecialchars($complication['case_number']); ?></td>
                            </tr>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Classification Card -->
                <div class="card <?php echo $has_patient_info ? 'mt-4' : ''; ?>">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0"><i class="fas fa-tags mr-2"></i>Classification</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm table-borderless">
                            <?php if($complication['complication_type']): ?>
                            <tr>
                                <td class="text-muted" width="40%">Type:</td>
                                <td>
                                    <span class="badge <?php echo $type_colors[$complication['complication_type']] ?? 'badge-secondary'; ?>">
                                        <?php echo htmlspecialchars($complication['complication_type']); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <?php if($complication['complication_category']): ?>
                            <tr>
                                <td class="text-muted">Category:</td>
                                <td><?php echo htmlspecialchars($complication['complication_category']); ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <td class="text-muted">Clavien-Dindo:</td>
                                <td>
                                    <span class="badge <?php echo $grade_colors[$complication['clavien_dindo_grade']] ?? 'badge-secondary'; ?>" 
                                          title="<?php echo $grade_labels[$complication['clavien_dindo_grade']] ?? ''; ?>"
                                          data-toggle="tooltip">
                                        Grade <?php echo htmlspecialchars($complication['clavien_dindo_grade']); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php if($complication['severity']): ?>
                            <tr>
                                <td class="text-muted">Severity:</td>
                                <td>
                                    <span class="badge <?php echo $severity_colors[$complication['severity']] ?? 'badge-secondary'; ?>">
                                        <?php echo htmlspecialchars($complication['severity']); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <?php if($complication['anatomical_location']): ?>
                            <tr>
                                <td class="text-muted">Anatomical Location:</td>
                                <td><?php echo htmlspecialchars($complication['anatomical_location']); ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if($complication['time_from_surgery']): ?>
                            <tr>
                                <td class="text-muted">Time from Surgery:</td>
                                <td><?php echo htmlspecialchars($complication['time_from_surgery']); ?></td>
                            </tr>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>

                <!-- Reporting Card -->
                <div class="card mt-4">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0"><i class="fas fa-clipboard-check mr-2"></i>Reporting & Timeline</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm table-borderless">
                            <?php if($complication['reported_by_name']): ?>
                            <tr>
                                <td class="text-muted" width="40%">Reported By:</td>
                                <td><?php echo htmlspecialchars($complication['reported_by_name']); ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if($complication['detected_by_name']): ?>
                            <tr>
                                <td class="text-muted">Detected By:</td>
                                <td><?php echo htmlspecialchars($complication['detected_by_name']); ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if($complication['created_by_name']): ?>
                            <tr>
                                <td class="text-muted">Created By:</td>
                                <td><?php echo htmlspecialchars($complication['created_by_name']); ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <td class="text-muted">Reported to Surgeon:</td>
                                <td>
                                    <?php if($complication['reported_to_surgeon']): ?>
                                        <span class="badge badge-success"><i class="fas fa-check"></i> Yes</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger"><i class="fas fa-times"></i> No</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td class="text-muted">Reported to Patient:</td>
                                <td>
                                    <?php if($complication['reported_to_patient']): ?>
                                        <span class="badge badge-success"><i class="fas fa-check"></i> Yes</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger"><i class="fas fa-times"></i> No</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td class="text-muted">Complication Date:</td>
                                <td>
                                    <?php echo $complication['complication_date'] ? date('M j, Y H:i', strtotime($complication['complication_date'])) : 'Not specified'; ?>
                                </td>
                            </tr>
                            <?php if($complication['occurred_at']): ?>
                            <tr>
                                <td class="text-muted">Occurred At:</td>
                                <td><?php echo date('M j, Y H:i', strtotime($complication['occurred_at'])); ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if($complication['updated_at']): ?>
                            <tr>
                                <td class="text-muted">Last Updated:</td>
                                <td><?php echo date('M j, Y H:i', strtotime($complication['updated_at'])); ?></td>
                            </tr>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Right Column - Management and Follow-up -->
            <div class="col-lg-8">
                <!-- Description Card -->
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0"><i class="fas fa-align-left mr-2"></i>Complication Details</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <h6>Description:</h6>
                            <div class="border rounded p-3 bg-light">
                                <?php echo nl2br(htmlspecialchars($complication['complication_description'])); ?>
                            </div>
                        </div>
                        
                        <?php if($complication['description']): ?>
                        <div class="mb-3">
                            <h6>Additional Details:</h6>
                            <div class="border rounded p-3 bg-light">
                                <?php echo nl2br(htmlspecialchars($complication['description'])); ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if($complication['contributing_factors']): ?>
                        <div class="mb-3">
                            <h6>Contributing Factors:</h6>
                            <div class="border rounded p-3 bg-light">
                                <?php echo nl2br(htmlspecialchars($complication['contributing_factors'])); ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Management Card -->
                <div class="card mt-4">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0"><i class="fas fa-stethoscope mr-2"></i>Management</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <?php if($complication['immediate_action']): ?>
                                <h6>Immediate Action:</h6>
                                <div class="border rounded p-3 bg-light mb-3">
                                    <?php echo nl2br(htmlspecialchars($complication['immediate_action'])); ?>
                                </div>
                                <?php endif; ?>
                                
                                <?php if($complication['management_provided']): ?>
                                <h6>Management Provided:</h6>
                                <div class="border rounded p-3 bg-light mb-3">
                                    <?php echo nl2br(htmlspecialchars($complication['management_provided'])); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <h6>Intervention Required:</h6>
                                <div class="mb-3">
                                    <?php if($complication['intervention_required']): ?>
                                        <span class="badge badge-danger"><i class="fas fa-check"></i> Yes</span>
                                        <?php if($complication['intervention_type']): ?>
                                            <div class="border rounded p-3 bg-light mt-2">
                                                <?php echo nl2br(htmlspecialchars($complication['intervention_type'])); ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="badge badge-success"><i class="fas fa-times"></i> No</span>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if($complication['preventive_measures']): ?>
                                <h6>Preventive Measures:</h6>
                                <div class="border rounded p-3 bg-light">
                                    <?php echo nl2br(htmlspecialchars($complication['preventive_measures'])); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Outcome Card -->
                <div class="card mt-4">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0"><i class="fas fa-chart-line mr-2"></i>Outcome & Follow-up</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <table class="table table-sm table-borderless">
                                    <?php if($complication['outcome']): ?>
                                    <tr>
                                        <td class="text-muted" width="40%">Outcome:</td>
                                        <td>
                                            <span class="badge <?php echo $outcome_colors[$complication['outcome']] ?? 'badge-secondary'; ?>">
                                                <?php echo htmlspecialchars($complication['outcome']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                    <?php if($complication['complication_status']): ?>
                                    <tr>
                                        <td class="text-muted">Status:</td>
                                        <td>
                                            <span class="badge <?php echo $status_colors[$complication['complication_status']] ?? 'badge-secondary'; ?>">
                                                <?php echo htmlspecialchars($complication['complication_status']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                    <?php if($complication['resolution_date']): ?>
                                    <tr>
                                        <td class="text-muted">Resolution Date:</td>
                                        <td><?php echo date('M j, Y', strtotime($complication['resolution_date'])); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                    <?php if($complication['estimated_resolution_date']): ?>
                                    <tr>
                                        <td class="text-muted">Est. Resolution:</td>
                                        <td><?php echo date('M j, Y', strtotime($complication['estimated_resolution_date'])); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td class="text-muted" width="40%">Follow-up Required:</td>
                                        <td>
                                            <?php if($complication['follow_up_required']): ?>
                                                <span class="badge badge-warning"><i class="fas fa-check"></i> Yes</span>
                                            <?php else: ?>
                                                <span class="badge badge-success"><i class="fas fa-times"></i> No</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php if($complication['sequelae']): ?>
                                    <tr>
                                        <td class="text-muted">Sequelae:</td>
                                        <td>
                                            <div class="border rounded p-2 bg-light">
                                                <?php echo nl2br(htmlspecialchars($complication['sequelae'])); ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </table>
                            </div>
                        </div>
                        
                        <?php if($complication['follow_up_plan']): ?>
                        <div class="mt-3">
                            <h6>Follow-up Plan:</h6>
                            <div class="border rounded p-3 bg-light">
                                <?php echo nl2br(htmlspecialchars($complication['follow_up_plan'])); ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Follow-up Notes Card -->
                <?php if($has_followup_notes): ?>
                <div class="card mt-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-sticky-note mr-2"></i>Follow-up Notes
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="border rounded p-3 bg-light">
                            <?php echo nl2br(htmlspecialchars($complication['follow_up_notes'])); ?>
                        </div>
                        <div class="text-right mt-2">
                            <small class="text-muted">
                                Last updated: <?php echo $complication['updated_at'] ? date('M j, Y H:i', strtotime($complication['updated_at'])) : 'Not specified'; ?>
                            </small>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Update Follow-up Notes Modal -->
<div class="modal fade" id="addFollowupModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form action="post.php" method="POST">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-sticky-note mr-2"></i>Update Follow-up Notes</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="complication_id" value="<?php echo $complication_id; ?>">
                    <input type="hidden" name="redirect" value="surgical_complications_view.php?id=<?php echo $complication_id; ?>">
                    
                    <div class="form-group">
                        <label for="followup_notes">Follow-up Notes</label>
                        <textarea class="form-control" id="followup_notes" name="follow_up_notes" rows="6"><?php echo htmlspecialchars($complication['follow_up_notes'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="complication_status">Status</label>
                                <select class="form-control" id="complication_status" name="complication_status">
                                    <option value="">Select Status</option>
                                    <option value="Active" <?php echo ($complication['complication_status'] ?? '') == 'Active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="Resolved" <?php echo ($complication['complication_status'] ?? '') == 'Resolved' ? 'selected' : ''; ?>>Resolved</option>
                                    <option value="Monitoring" <?php echo ($complication['complication_status'] ?? '') == 'Monitoring' ? 'selected' : ''; ?>>Monitoring</option>
                                    <option value="Under Treatment" <?php echo ($complication['complication_status'] ?? '') == 'Under Treatment' ? 'selected' : ''; ?>>Under Treatment</option>
                                    <option value="Chronic" <?php echo ($complication['complication_status'] ?? '') == 'Chronic' ? 'selected' : ''; ?>>Chronic</option>
                                    <option value="Pending" <?php echo ($complication['complication_status'] ?? '') == 'Pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="Closed" <?php echo ($complication['complication_status'] ?? '') == 'Closed' ? 'selected' : ''; ?>>Closed</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="outcome">Outcome</label>
                                <select class="form-control" id="outcome" name="outcome">
                                    <option value="">Select Outcome</option>
                                    <option value="Resolved" <?php echo ($complication['outcome'] ?? '') == 'Resolved' ? 'selected' : ''; ?>>Resolved</option>
                                    <option value="Improved" <?php echo ($complication['outcome'] ?? '') == 'Improved' ? 'selected' : ''; ?>>Improved</option>
                                    <option value="Unchanged" <?php echo ($complication['outcome'] ?? '') == 'Unchanged' ? 'selected' : ''; ?>>Unchanged</option>
                                    <option value="Worsened" <?php echo ($complication['outcome'] ?? '') == 'Worsened' ? 'selected' : ''; ?>>Worsened</option>
                                    <option value="Death" <?php echo ($complication['outcome'] ?? '') == 'Death' ? 'selected' : ''; ?>>Death</option>
                                    <option value="Recovered" <?php echo ($complication['outcome'] ?? '') == 'Recovered' ? 'selected' : ''; ?>>Recovered</option>
                                    <option value="Ongoing" <?php echo ($complication['outcome'] ?? '') == 'Ongoing' ? 'selected' : ''; ?>>Ongoing</option>
                                    <option value="Resolving" <?php echo ($complication['outcome'] ?? '') == 'Resolving' ? 'selected' : ''; ?>>Resolving</option>
                                    <option value="Chronic" <?php echo ($complication['outcome'] ?? '') == 'Chronic' ? 'selected' : ''; ?>>Chronic</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="resolution_date">Resolution Date</label>
                                <input type="date" class="form-control" id="resolution_date" name="resolution_date" value="<?php echo $complication['resolution_date'] ?? ''; ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="follow_up_required">Follow-up Required</label>
                                <select class="form-control" id="follow_up_required" name="follow_up_required">
                                    <option value="0" <?php echo ($complication['follow_up_required'] ?? 0) == 0 ? 'selected' : ''; ?>>No</option>
                                    <option value="1" <?php echo ($complication['follow_up_required'] ?? 0) == 1 ? 'selected' : ''; ?>>Yes</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" name="update_complication_followup">
                        <i class="fas fa-save mr-2"></i>Update Notes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Schedule Follow-up Modal -->
<div class="modal fade" id="scheduleFollowupModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form action="post.php" method="POST">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title"><i class="fas fa-calendar-plus mr-2"></i>Schedule Follow-up</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="complication_id" value="<?php echo $complication_id; ?>">
                    <input type="hidden" name="redirect" value="surgical_complications_view.php?id=<?php echo $complication_id; ?>">
                    
                    <div class="form-group">
                        <label for="followup_date_schedule">Follow-up Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="followup_date_schedule" name="followup_date" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="followup_time">Time</label>
                        <input type="time" class="form-control" id="followup_time" name="followup_time">
                    </div>
                    
                    <div class="form-group">
                        <label for="followup_instructions">Instructions / Notes</label>
                        <textarea class="form-control" id="followup_instructions" name="instructions" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning" name="schedule_complication_followup">
                        <i class="fas fa-calendar-check mr-2"></i>Schedule
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Set default follow-up date to tomorrow
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    const tomorrowStr = tomorrow.toISOString().split('T')[0];
    $('#followup_date_schedule').val(tomorrowStr);
    
    // Set default follow-up time to 09:00
    $('#followup_time').val('09:00');
    
    // Tooltips for Clavien-Dindo grades
    $('[data-toggle="tooltip"]').tooltip();
    
    // Confirm delete actions
    $('.confirm-action').click(function(e) {
        if (!confirm($(this).data('message'))) {
            e.preventDefault();
        }
    });
});

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + E for edit
    if (e.ctrlKey && e.keyCode === 69) {
        e.preventDefault();
        window.location.href = 'surgical_complications_edit.php?id=<?php echo $complication_id; ?>';
    }
    // Ctrl + N for new note
    if (e.ctrlKey && e.keyCode === 78) {
        e.preventDefault();
        $('#addFollowupModal').modal('show');
    }
    // Ctrl + B for back
    if (e.ctrlKey && e.keyCode === 66) {
        e.preventDefault();
        window.location.href = 'surgical_complications.php';
    }
});
</script>

<style>
.badge-lg {
    font-size: 0.9rem;
    padding: 0.5rem 0.75rem;
}
.table-borderless td {
    border: none !important;
}
.card .table td {
    vertical-align: middle;
}
.list-group-item:hover {
    background-color: #f8f9fa;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
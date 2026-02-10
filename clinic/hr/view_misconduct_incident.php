<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Check if incident ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['alert_type'] = 'error';
    $_SESSION['alert_message'] = 'No incident ID provided.';
    header("Location: misconduct_dashboard.php");
    exit;
}

$incident_id = intval($_GET['id']);

// Get incident details with correct column names
$sql = "SELECT 
            mi.*,
            mc.category_name,
            mc.description as category_description,
            e.employee_id, e.first_name, e.last_name, e.employee_number, 
            d.department_name,
            j.title as position,
            er.first_name as reported_first, er.last_name as reported_last, 
            er.employee_number as reported_number,
            dr.department_name as reported_department
        FROM misconduct_incidents mi
        JOIN misconduct_categories mc ON mi.category_id = mc.category_id
        JOIN employees e ON mi.employee_id = e.employee_id
        LEFT JOIN departments d ON e.department_id = d.department_id
        LEFT JOIN job_titles j ON e.job_title_id = j.job_title_id
        JOIN employees er ON mi.reported_by = er.employee_id
        LEFT JOIN departments dr ON er.department_id = dr.department_id
        WHERE mi.incident_id = ?";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $incident_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION['alert_type'] = 'error';
    $_SESSION['alert_message'] = 'Incident not found.';
    header("Location: misconduct_dashboard.php");
    exit;
}

$incident = $result->fetch_assoc();

// Get investigation details if exists
$investigation_sql = "SELECT 
                        mi.*,
                        e.first_name as inv_first, e.last_name as inv_last,
                        j.title as inv_position
                      FROM misconduct_investigations mi
                      LEFT JOIN employees e ON mi.investigator_id = e.employee_id
                      LEFT JOIN job_titles j ON e.job_title_id = j.job_title_id
                      WHERE mi.incident_id = ?";
$investigation_stmt = $mysqli->prepare($investigation_sql);
$investigation_stmt->bind_param("i", $incident_id);
$investigation_stmt->execute();
$investigation_result = $investigation_stmt->get_result();
$investigation = $investigation_result->fetch_assoc();

// Get show cause letters
$show_cause_sql = "SELECT 
                    scl.*,
                    er.response_id, er.response_date, er.response_text
                   FROM show_cause_letters scl
                   LEFT JOIN employee_responses er ON scl.show_cause_id = er.show_cause_id
                   WHERE scl.incident_id = ?
                   ORDER BY scl.issued_date DESC";
$show_cause_stmt = $mysqli->prepare($show_cause_sql);
$show_cause_stmt->bind_param("i", $incident_id);
$show_cause_stmt->execute();
$show_cause_result = $show_cause_stmt->get_result();

// Get disciplinary hearings
$hearings_sql = "SELECT 
                    dh.*,
                    e.first_name as chair_first, e.last_name as chair_last,
                    (SELECT COUNT(*) FROM hearing_participants hp WHERE hp.hearing_id = dh.hearing_id) as participant_count
                 FROM disciplinary_hearings dh
                 LEFT JOIN employees e ON dh.chairperson_id = e.employee_id
                 WHERE dh.incident_id = ?
                 ORDER BY dh.hearing_date DESC";
$hearings_stmt = $mysqli->prepare($hearings_sql);
$hearings_stmt->bind_param("i", $incident_id);
$hearings_stmt->execute();
$hearings_result = $hearings_stmt->get_result();

// Get disciplinary actions
$actions_sql = "SELECT 
                    da.*,
                    a.appeal_id, a.appeal_date, a.outcome, a.outcome_notes
                 FROM disciplinary_actions da
                 LEFT JOIN disciplinary_appeals a ON da.action_id = a.action_id
                 WHERE da.incident_id = ?
                 ORDER BY da.effective_date DESC";
$actions_stmt = $mysqli->prepare($actions_sql);
$actions_stmt->bind_param("i", $incident_id);
$actions_stmt->execute();
$actions_result = $actions_stmt->get_result();
?>

<div class="card">
    <div class="card-header py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-exclamation-triangle mr-2"></i>
                Misconduct Incident #<?php echo $incident_id; ?>
            </h3>
            <div>
                <a href="edit_misconduct_incident.php?id=<?php echo $incident_id; ?>" class="btn btn-warning btn-sm">
                    <i class="fas fa-edit mr-2"></i>Edit Incident
                </a>
                <a href="misconduct_dashboard.php" class="btn btn-light btn-sm">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
                </a>
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
            <!-- Incident Details -->
            <div class="col-md-8">
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-info-circle mr-2"></i>Incident Details</h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <th width="40%">Employee:</th>
                                        <td>
                                            <strong><?php echo htmlspecialchars($incident['first_name'] . ' ' . $incident['last_name']); ?></strong>
                                            <br>
                                            <small class="text-muted">
                                                ID: <?php echo htmlspecialchars($incident['employee_number']); ?> | 
                                                <?php echo htmlspecialchars($incident['department_name'] ?? 'No Department'); ?> |
                                                <?php echo htmlspecialchars($incident['position'] ?? 'No Position'); ?>
                                            </small>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Category:</th>
                                        <td>
                                            <strong><?php echo htmlspecialchars($incident['category_name']); ?></strong>
                                            <?php if ($incident['category_description']): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($incident['category_description']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Incident Date:</th>
                                        <td><?php echo date('F j, Y', strtotime($incident['incident_date'])); ?></td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <th width="40%">Severity:</th>
                                        <td>
                                            <?php
                                            $severity_badge = [
                                                'low' => 'badge-info',
                                                'medium' => 'badge-warning',
                                                'high' => 'badge-danger',
                                                'gross' => 'badge-dark'
                                            ];
                                            ?>
                                            <span class="badge <?php echo $severity_badge[$incident['severity']]; ?>">
                                                <?php echo ucfirst($incident['severity']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Status:</th>
                                        <td>
                                            <?php
                                            $status_badge = [
                                                'open' => 'badge-primary',
                                                'under_investigation' => 'badge-warning',
                                                'closed' => 'badge-success'
                                            ];
                                            ?>
                                            <span class="badge <?php echo $status_badge[$incident['status']]; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $incident['status'])); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Reported By:</th>
                                        <td>
                                            <?php echo htmlspecialchars($incident['reported_first'] . ' ' . $incident['reported_last']); ?>
                                            <br>
                                            <small class="text-muted">
                                                ID: <?php echo htmlspecialchars($incident['reported_number']); ?> | 
                                                <?php echo htmlspecialchars($incident['reported_department'] ?? 'No Department'); ?>
                                            </small>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Reported On:</th>
                                        <td><?php echo date('F j, Y g:i A', strtotime($incident['created_at'])); ?></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                        
                        <div class="form-group mt-3">
                            <label class="font-weight-bold">Incident Description:</label>
                            <div class="border rounded p-3 bg-light">
                                <?php echo nl2br(htmlspecialchars($incident['description'])); ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Investigation Section -->
                <div class="card mb-4">
                    <div class="card-header bg-info text-white py-2 d-flex justify-content-between align-items-center">
                        <h4 class="card-title mb-0"><i class="fas fa-search mr-2"></i>Investigation</h4>
                        <?php if (!$investigation): ?>
                            <a href="add_investigation.php?incident_id=<?php echo $incident_id; ?>" class="btn btn-light btn-sm">
                                <i class="fas fa-plus mr-1"></i>Start Investigation
                            </a>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if ($investigation): ?>
                            <div class="row">
                                <div class="col-md-6">
                                    <table class="table table-sm table-borderless">
                                        <tr>
                                            <th width="40%">Investigator:</th>
                                            <td><?php echo htmlspecialchars($investigation['inv_first'] . ' ' . $investigation['inv_last']); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Start Date:</th>
                                            <td><?php echo date('F j, Y', strtotime($investigation['start_date'])); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Status:</th>
                                            <td>
                                                <?php if ($investigation['end_date']): ?>
                                                    <span class="badge badge-success">Completed</span>
                                                <?php else: ?>
                                                    <span class="badge badge-warning">In Progress</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                                <div class="col-md-6">
                                    <table class="table table-sm table-borderless">
                                        <tr>
                                            <th width="40%">End Date:</th>
                                            <td>
                                                <?php echo $investigation['end_date'] ? date('F j, Y', strtotime($investigation['end_date'])) : 'Not yet completed'; ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>Recommendation:</th>
                                            <td class="text-capitalize">
                                                <?php echo str_replace('_', ' ', $investigation['recommendation']); ?>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                            
                            <?php if ($investigation['findings']): ?>
                                <div class="form-group mt-3">
                                    <label class="font-weight-bold">Investigation Findings:</label>
                                    <div class="border rounded p-3 bg-light">
                                        <?php echo nl2br(htmlspecialchars($investigation['findings'])); ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <div class="text-right mt-3">
                                <a href="view_investigation.php?incident_id=<?php echo $incident_id; ?>" class="btn btn-primary btn-sm">
                                    <i class="fas fa-eye mr-1"></i>View Full Investigation
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="fas fa-search fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">No Investigation Started</h5>
                                <p class="text-muted">Start an investigation to proceed with this case.</p>
                                <a href="add_investigation.php?incident_id=<?php echo $incident_id; ?>" class="btn btn-primary">
                                    <i class="fas fa-plus mr-2"></i>Start Investigation
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Sidebar with Actions and Timeline -->
            <div class="col-md-4">
                <!-- Quick Actions -->
                <div class="card mb-4">
                    <div class="card-header bg-success text-white py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-bolt mr-2"></i>Quick Actions</h4>
                    </div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush">
                            <?php if (!$investigation): ?>
                                <a href="add_investigation.php?incident_id=<?php echo $incident_id; ?>" class="list-group-item list-group-item-action">
                                    <i class="fas fa-search text-primary mr-2"></i>
                                    Start Investigation
                                </a>
                            <?php endif; ?>
                            
                            <?php if ($investigation && !$investigation['end_date']): ?>
                                <a href="edit_investigation.php?incident_id=<?php echo $incident_id; ?>" class="list-group-item list-group-item-action">
                                    <i class="fas fa-edit text-warning mr-2"></i>
                                    Update Investigation
                                </a>
                            <?php endif; ?>
                            
                            <?php if ($investigation && $investigation['end_date'] && $show_cause_result->num_rows == 0): ?>
                                <a href="issue_show_cause.php?incident_id=<?php echo $incident_id; ?>" class="list-group-item list-group-item-action">
                                    <i class="fas fa-envelope text-info mr-2"></i>
                                    Issue Show Cause Letter
                                </a>
                            <?php endif; ?>
                            
                            <?php if ($show_cause_result->num_rows > 0 && $hearings_result->num_rows == 0): ?>
                                <a href="schedule_hearing.php?incident_id=<?php echo $incident_id; ?>" class="list-group-item list-group-item-action">
                                    <i class="fas fa-gavel text-danger mr-2"></i>
                                    Schedule Hearing
                                </a>
                            <?php endif; ?>
                            
                            <?php if ($hearings_result->num_rows > 0 && $actions_result->num_rows == 0): ?>
                                <a href="disciplinary_actions.php?incident_id=<?php echo $incident_id; ?>" class="list-group-item list-group-item-action">
                                    <i class="fas fa-balance-scale text-success mr-2"></i>
                                    Record Disciplinary Action
                                </a>
                            <?php endif; ?>
                            
                            <?php if ($incident['status'] != 'closed'): ?>
                                <a href="misconduct_dashboard.php?action=close_incident&id=<?php echo $incident_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>" 
                                   class="list-group-item list-group-item-action text-danger confirm-link"
                                   data-confirm-message="Are you sure you want to close this incident?">
                                    <i class="fas fa-times mr-2"></i>
                                    Close Incident
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Case Timeline -->
                <div class="card">
                    <div class="card-header bg-warning text-white py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-history mr-2"></i>Case Timeline</h4>
                    </div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush">
                            <!-- Incident Reported -->
                            <div class="list-group-item">
                                <div class="d-flex w-100 justify-content-between">
                                    <h6 class="mb-1 text-success">Incident Reported</h6>
                                    <small><?php echo date('M j', strtotime($incident['created_at'])); ?></small>
                                </div>
                                <p class="mb-1 small">Case opened and logged in system</p>
                            </div>
                            
                            <!-- Investigation -->
                            <?php if ($investigation): ?>
                                <div class="list-group-item">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1 text-info">Investigation Started</h6>
                                        <small><?php echo date('M j', strtotime($investigation['start_date'])); ?></small>
                                    </div>
                                    <p class="mb-1 small">Assigned to <?php echo htmlspecialchars($investigation['inv_first'] . ' ' . $investigation['inv_last']); ?></p>
                                    <?php if ($investigation['end_date']): ?>
                                        <div class="d-flex w-100 justify-content-between mt-2">
                                            <h6 class="mb-1 text-success">Investigation Completed</h6>
                                            <small><?php echo date('M j', strtotime($investigation['end_date'])); ?></small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Show Cause -->
                            <?php if ($show_cause_result->num_rows > 0): ?>
                                <?php while ($sc = $show_cause_result->fetch_assoc()): ?>
                                    <div class="list-group-item">
                                        <div class="d-flex w-100 justify-content-between">
                                            <h6 class="mb-1 text-warning">Show Cause Issued</h6>
                                            <small><?php echo date('M j', strtotime($sc['issued_date'])); ?></small>
                                        </div>
                                        <p class="mb-1 small">Due: <?php echo date('M j, Y', strtotime($sc['due_date'])); ?></p>
                                        <?php if ($sc['response_id']): ?>
                                            <div class="d-flex w-100 justify-content-between mt-2">
                                                <h6 class="mb-1 text-success">Response Received</h6>
                                                <small><?php echo date('M j', strtotime($sc['response_date'])); ?></small>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endwhile; ?>
                            <?php endif; ?>
                            
                            <!-- Hearings -->
                            <?php if ($hearings_result->num_rows > 0): ?>
                                <?php while ($hearing = $hearings_result->fetch_assoc()): ?>
                                    <div class="list-group-item">
                                        <div class="d-flex w-100 justify-content-between">
                                            <h6 class="mb-1 text-primary">Disciplinary Hearing</h6>
                                            <small><?php echo date('M j', strtotime($hearing['hearing_date'])); ?></small>
                                        </div>
                                        <p class="mb-1 small">Chaired by <?php echo htmlspecialchars($hearing['chair_first'] . ' ' . $hearing['chair_last']); ?></p>
                                    </div>
                                <?php endwhile; ?>
                            <?php endif; ?>
                            
                            <!-- Actions -->
                            <?php if ($actions_result->num_rows > 0): ?>
                                <?php while ($action = $actions_result->fetch_assoc()): ?>
                                    <div class="list-group-item">
                                        <div class="d-flex w-100 justify-content-between">
                                            <h6 class="mb-1 text-dark">Action Taken</h6>
                                            <small><?php echo date('M j', strtotime($action['effective_date'])); ?></small>
                                        </div>
                                        <p class="mb-1 small text-capitalize"><?php echo str_replace('_', ' ', $action['action_type']); ?></p>
                                    </div>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Show Cause Letters Section -->
        <?php if ($show_cause_result->num_rows > 0): ?>
            <div class="card mb-4">
                <div class="card-header bg-warning text-white py-2">
                    <h4 class="card-title mb-0"><i class="fas fa-envelope mr-2"></i>Show Cause Letters</h4>
                </div>
                <div class="card-body">
                    <?php mysqli_data_seek($show_cause_result, 0); ?>
                    <?php while ($sc = $show_cause_result->fetch_assoc()): ?>
                        <div class="card mb-3">
                            <div class="card-header py-2">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">Show Cause Letter</h5>
                                    <div>
                                        <span class="badge badge-<?php echo $sc['response_id'] ? 'success' : 'warning'; ?>">
                                            <?php echo $sc['response_id'] ? 'Response Received' : 'Awaiting Response'; ?>
                                        </span>
                                        <small class="text-muted ml-2">
                                            Issued: <?php echo date('F j, Y', strtotime($sc['issued_date'])); ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-8">
                                        <h6>Letter Content:</h6>
                                        <div class="border rounded p-3 bg-light mb-3">
                                            <?php echo nl2br(htmlspecialchars($sc['letter_text'])); ?>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <table class="table table-sm table-borderless">
                                            <tr>
                                                <th>Due Date:</th>
                                                <td><?php echo date('F j, Y', strtotime($sc['due_date'])); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Status:</th>
                                                <td>
                                                    <?php if (strtotime($sc['due_date']) < time() && !$sc['response_id']): ?>
                                                        <span class="badge badge-danger">Overdue</span>
                                                    <?php elseif ($sc['response_id']): ?>
                                                        <span class="badge badge-success">Responded</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-warning">Pending</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        </table>
                                        
                                        <?php if ($sc['response_id']): ?>
                                            <h6>Employee Response:</h6>
                                            <div class="border rounded p-3 bg-light">
                                                <small class="text-muted">Received: <?php echo date('F j, Y', strtotime($sc['response_date'])); ?></small>
                                                <div class="mt-2">
                                                    <?php echo nl2br(htmlspecialchars($sc['response_text'])); ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Disciplinary Actions Section -->
        <?php if ($actions_result->num_rows > 0): ?>
            <div class="card">
                <div class="card-header bg-dark text-white py-2">
                    <h4 class="card-title mb-0"><i class="fas fa-balance-scale mr-2"></i>Disciplinary Actions</h4>
                </div>
                <div class="card-body">
                    <?php mysqli_data_seek($actions_result, 0); ?>
                    <?php while ($action = $actions_result->fetch_assoc()): ?>
                        <div class="card mb-3">
                            <div class="card-header py-2">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0 text-capitalize"><?php echo str_replace('_', ' ', $action['action_type']); ?></h5>
                                    <div>
                                        <?php if ($action['appeal_id']): ?>
                                            <span class="badge badge-<?php 
                                                switch($action['outcome']) {
                                                    case 'upheld': echo 'success'; break;
                                                    case 'overturned': echo 'danger'; break;
                                                    case 'modified': echo 'warning'; break;
                                                    default: echo 'info';
                                                }
                                            ?>">
                                                Appeal: <?php echo ucfirst($action['outcome']); ?>
                                            </span>
                                        <?php endif; ?>
                                        <small class="text-muted ml-2">
                                            Effective: <?php echo date('F j, Y', strtotime($action['effective_date'])); ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-8">
                                        <?php if ($action['notes']): ?>
                                            <h6>Action Details:</h6>
                                            <div class="border rounded p-3 bg-light">
                                                <?php echo nl2br(htmlspecialchars($action['notes'])); ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($action['appeal_id'] && $action['outcome_notes']): ?>
                                            <h6 class="mt-3">Appeal Outcome Notes:</h6>
                                            <div class="border rounded p-3 bg-light">
                                                <?php echo nl2br(htmlspecialchars($action['outcome_notes'])); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-4">
                                        <table class="table table-sm table-borderless">
                                            <tr>
                                                <th>Effective Date:</th>
                                                <td><?php echo date('F j, Y', strtotime($action['effective_date'])); ?></td>
                                            </tr>
                                            <?php if ($action['expiry_date']): ?>
                                                <tr>
                                                    <th>Expiry Date:</th>
                                                    <td><?php echo date('F j, Y', strtotime($action['expiry_date'])); ?></td>
                                                </tr>
                                            <?php endif; ?>
                                            <?php if ($action['appeal_id']): ?>
                                                <tr>
                                                    <th>Appeal Date:</th>
                                                    <td><?php echo date('F j, Y', strtotime($action['appeal_date'])); ?></td>
                                                </tr>
                                                <tr>
                                                    <th>Appeal Outcome:</th>
                                                    <td class="text-capitalize"><?php echo str_replace('_', ' ', $action['outcome']); ?></td>
                                                </tr>
                                            <?php endif; ?>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
$(document).ready(function() {
    // Confirm links
    $('.confirm-link').click(function(e) {
        e.preventDefault();
        var message = $(this).data('confirm-message') || 'Are you sure?';
        var href = $(this).attr('href');
        
        if (confirm(message)) {
            window.location.href = href;
        }
    });
});
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
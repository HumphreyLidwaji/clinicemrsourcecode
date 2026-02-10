<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';



// Check if incident ID is provided
if (!isset($_GET['incident_id']) || empty($_GET['incident_id'])) {
    $_SESSION['alert_type'] = 'error';
    $_SESSION['alert_message'] = 'No incident ID provided.';
    header("Location: misconduct_dashboard.php");
    exit;
}

$incident_id = intval($_GET['incident_id']);

// Get incident details with correct column names
$incident_sql = "SELECT 
                    mi.*,
                    e.first_name, e.last_name, e.employee_number, 
                    d.department_name,
                    j.title as position,
                    mc.category_name,
                    inv.findings, inv.recommendation
                 FROM misconduct_incidents mi
                 JOIN employees e ON mi.employee_id = e.employee_id
                 LEFT JOIN departments d ON e.department_id = d.department_id
                 LEFT JOIN job_titles j ON e.job_title_id = j.job_title_id
                 JOIN misconduct_categories mc ON mi.category_id = mc.category_id
                 LEFT JOIN misconduct_investigations inv ON mi.incident_id = inv.incident_id
                 WHERE mi.incident_id = ?";

$incident_stmt = $mysqli->prepare($incident_sql);
$incident_stmt->bind_param("i", $incident_id);
$incident_stmt->execute();
$incident_result = $incident_stmt->get_result();

if ($incident_result->num_rows === 0) {
    $_SESSION['alert_type'] = 'error';
    $_SESSION['alert_message'] = 'Incident not found.';
    header("Location: misconduct_dashboard.php");
    exit;
}

$incident = $incident_result->fetch_assoc();

// Get existing disciplinary actions
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

// Handle form submission for new action
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_action'])) {
    $action_type = sanitizeInput($_POST['action_type']);
    $effective_date = sanitizeInput($_POST['effective_date']);
    $expiry_date = sanitizeInput($_POST['expiry_date'] ?? '');
    $notes = sanitizeInput($_POST['notes']);
    
    // Validate required fields
    if (empty($action_type) || empty($effective_date)) {
        $_SESSION['alert_type'] = 'error';
        $_SESSION['alert_message'] = 'Please fill in all required fields.';
    } else {
        // Insert the disciplinary action
        $sql = "INSERT INTO disciplinary_actions 
                (incident_id, action_type, effective_date, expiry_date, notes) 
                VALUES (?, ?, ?, ?, ?)";
        
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("issss", $incident_id, $action_type, $effective_date, $expiry_date, $notes);
        
        if ($stmt->execute()) {
            $action_id = $mysqli->insert_id;
            
            // Update incident status to closed if action is taken
            $update_sql = "UPDATE misconduct_incidents SET status = 'closed' WHERE incident_id = ?";
            $update_stmt = $mysqli->prepare($update_sql);
            $update_stmt->bind_param("i", $incident_id);
            $update_stmt->execute();
            
            // Log the action
            $audit_sql = "INSERT INTO hr_audit_log (user_id, action, description, table_name, record_id) 
                          VALUES (?, 'disciplinary_action_added', ?, 'disciplinary_actions', ?)";
            $audit_stmt = $mysqli->prepare($audit_sql);
            $audit_description = "Added disciplinary action for incident ID: $incident_id - Action: $action_type";
            $audit_stmt->bind_param("isi", $_SESSION['user_id'], $audit_description, $action_id);
            $audit_stmt->execute();
            
            $_SESSION['alert_type'] = 'success';
            $_SESSION['alert_message'] = 'Disciplinary action recorded successfully!';
            
            // Redirect to refresh the page
            header("Location: disciplinary_actions.php?incident_id=$incident_id");
            exit;
        } else {
            $_SESSION['alert_type'] = 'error';
            $_SESSION['alert_message'] = 'Error recording disciplinary action: ' . $stmt->error;
        }
    }
}

// Handle form submission for appeal
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_appeal'])) {
    $action_id = intval($_POST['action_id']);
    $appeal_date = sanitizeInput($_POST['appeal_date']);
    $appeal_reason = sanitizeInput($_POST['appeal_reason']);
    
    // Validate required fields
    if (empty($appeal_date) || empty($appeal_reason)) {
        $_SESSION['alert_type'] = 'error';
        $_SESSION['alert_message'] = 'Please fill in all required fields for the appeal.';
    } else {
        // Insert the appeal
        $sql = "INSERT INTO disciplinary_appeals 
                (action_id, appeal_date, appeal_reason, outcome) 
                VALUES (?, ?, ?, 'pending')";
        
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("iss", $action_id, $appeal_date, $appeal_reason);
        
        if ($stmt->execute()) {
            $appeal_id = $mysqli->insert_id;
            
            // Log the action
            $audit_sql = "INSERT INTO hr_audit_log (user_id, action, description, table_name, record_id) 
                          VALUES (?, 'disciplinary_appeal_added', ?, 'disciplinary_appeals', ?)";
            $audit_stmt = $mysqli->prepare($audit_sql);
            $audit_description = "Added appeal for disciplinary action ID: $action_id";
            $audit_stmt->bind_param("isi", $_SESSION['user_id'], $audit_description, $appeal_id);
            $audit_stmt->execute();
            
            $_SESSION['alert_type'] = 'success';
            $_SESSION['alert_message'] = 'Appeal recorded successfully!';
            
            // Redirect to refresh the page
            header("Location: disciplinary_actions.php?incident_id=$incident_id");
            exit;
        } else {
            $_SESSION['alert_type'] = 'error';
            $_SESSION['alert_message'] = 'Error recording appeal: ' . $stmt->error;
        }
    }
}

// Handle form submission for appeal outcome
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_appeal'])) {
    $appeal_id = intval($_POST['appeal_id']);
    $outcome = sanitizeInput($_POST['outcome']);
    $outcome_notes = sanitizeInput($_POST['outcome_notes']);
    
    // Validate required fields
    if (empty($outcome)) {
        $_SESSION['alert_type'] = 'error';
        $_SESSION['alert_message'] = 'Please select an appeal outcome.';
    } else {
        // Update the appeal
        $sql = "UPDATE disciplinary_appeals 
                SET outcome = ?, outcome_notes = ? 
                WHERE appeal_id = ?";
        
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("ssi", $outcome, $outcome_notes, $appeal_id);
        
        if ($stmt->execute()) {
            // Log the action
            $audit_sql = "INSERT INTO hr_audit_log (user_id, action, description, table_name, record_id) 
                          VALUES (?, 'disciplinary_appeal_updated', ?, 'disciplinary_appeals', ?)";
            $audit_stmt = $mysqli->prepare($audit_sql);
            $audit_description = "Updated appeal outcome for appeal ID: $appeal_id - Outcome: $outcome";
            $audit_stmt->bind_param("isi", $_SESSION['user_id'], $audit_description, $appeal_id);
            $audit_stmt->execute();
            
            $_SESSION['alert_type'] = 'success';
            $_SESSION['alert_message'] = 'Appeal outcome updated successfully!';
            
            // Redirect to refresh the page
            header("Location: disciplinary_actions.php?incident_id=$incident_id");
            exit;
        } else {
            $_SESSION['alert_type'] = 'error';
            $_SESSION['alert_message'] = 'Error updating appeal outcome: ' . $stmt->error;
        }
    }
}
?>

<div class="card">
    <div class="card-header py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-balance-scale mr-2"></i>
                Disciplinary Actions - Incident #<?php echo $incident_id; ?>
            </h3>
            <a href="view_misconduct_incident.php?id=<?php echo $incident_id; ?>" class="btn btn-light btn-sm">
                <i class="fas fa-arrow-left mr-2"></i>Back to Incident
            </a>
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

        <!-- Incident Summary -->
        <div class="card mb-4">
            <div class="card-header bg-light py-2">
                <h5 class="card-title mb-0"><i class="fas fa-info-circle mr-2"></i>Case Summary</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <table class="table table-sm table-borderless">
                            <tr>
                                <th width="50%">Employee:</th>
                                <td>
                                    <strong><?php echo htmlspecialchars($incident['first_name'] . ' ' . $incident['last_name']); ?></strong>
                                    <br>
                                    <small class="text-muted">
                                        ID: <?php echo htmlspecialchars($incident['employee_number']); ?>
                                    </small>
                                </td>
                            </tr>
                            <tr>
                                <th>Department:</th>
                                <td><?php echo htmlspecialchars($incident['department_name'] ?? 'No Department'); ?></td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-4">
                        <table class="table table-sm table-borderless">
                            <tr>
                                <th width="50%">Category:</th>
                                <td><?php echo htmlspecialchars($incident['category_name']); ?></td>
                            </tr>
                            <tr>
                                <th>Severity:</th>
                                <td>
                                    <span class="badge badge-<?php 
                                        switch($incident['severity']) {
                                            case 'low': echo 'info'; break;
                                            case 'medium': echo 'warning'; break;
                                            case 'high': echo 'danger'; break;
                                            case 'gross': echo 'dark'; break;
                                        }
                                    ?>">
                                        <?php echo ucfirst($incident['severity']); ?>
                                    </span>
                                </td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-4">
                        <table class="table table-sm table-borderless">
                            <tr>
                                <th width="50%">Investigation:</th>
                                <td>
                                    <?php if ($incident['findings']): ?>
                                        <span class="badge badge-success">Completed</span>
                                    <?php else: ?>
                                        <span class="badge badge-warning">Not Started</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th>Recommendation:</th>
                                <td class="text-capitalize">
                                    <?php echo $incident['recommendation'] ? str_replace('_', ' ', $incident['recommendation']) : 'Pending'; ?>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <?php if ($incident['findings']): ?>
                    <div class="form-group mt-3">
                        <label class="font-weight-bold">Investigation Findings:</label>
                        <div class="border rounded p-3 bg-light">
                            <?php echo nl2br(htmlspecialchars($incident['findings'])); ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Add New Disciplinary Action -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white py-2">
                <h5 class="card-title mb-0"><i class="fas fa-plus-circle mr-2"></i>Record Disciplinary Action</h5>
            </div>
            <div class="card-body">
                <form method="POST" autocomplete="off">
                    <input type="hidden" name="add_action" value="1">
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="action_type">Action Type <span class="text-danger">*</span></label>
                                <select class="form-control" id="action_type" name="action_type" required>
                                    <option value="">Select Action</option>
                                    <option value="verbal_warning">Verbal Warning</option>
                                    <option value="written_warning">Written Warning</option>
                                    <option value="final_warning">Final Warning</option>
                                    <option value="suspension">Suspension</option>
                                    <option value="demotion">Demotion</option>
                                    <option value="salary_deduction">Salary Deduction</option>
                                    <option value="termination">Termination</option>
                                    <option value="no_action">No Action</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="effective_date">Effective Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="effective_date" name="effective_date" 
                                       value="<?php echo date('Y-m-d'); ?>" 
                                       max="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                        </div>
                        
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="expiry_date">Expiry Date</label>
                                <input type="date" class="form-control" id="expiry_date" name="expiry_date">
                                <small class="form-text text-muted">
                                    For warnings - when the warning expires from record
                                </small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="notes">Action Details & Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="4" 
                                  placeholder="Provide details about the disciplinary action, including any specific conditions, requirements, or additional information..."></textarea>
                        <small class="form-text text-muted">
                            Include specific details such as suspension duration, warning terms, or other relevant information
                        </small>
                    </div>
                    
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save mr-2"></i>Record Action
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Existing Disciplinary Actions -->
        <div class="card">
            <div class="card-header bg-success text-white py-2">
                <h5 class="card-title mb-0">
                    <i class="fas fa-list mr-2"></i>
                    Existing Disciplinary Actions
                    <span class="badge badge-light ml-2"><?php echo $actions_result->num_rows; ?></span>
                </h5>
            </div>
            <div class="card-body">
                <?php if ($actions_result->num_rows > 0): ?>
                    <?php while ($action = $actions_result->fetch_assoc()): ?>
                        <div class="card mb-4">
                            <div class="card-header py-2">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0 text-capitalize">
                                        <?php echo str_replace('_', ' ', $action['action_type']); ?>
                                        <?php if ($action['appeal_id']): ?>
                                            <span class="badge badge-<?php 
                                                switch($action['outcome']) {
                                                    case 'upheld': echo 'success'; break;
                                                    case 'overturned': echo 'danger'; break;
                                                    case 'modified': echo 'warning'; break;
                                                    case 'pending': echo 'info'; break;
                                                    default: echo 'secondary';
                                                }
                                            ?> ml-2">
                                                Appeal: <?php echo ucfirst($action['outcome']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </h5>
                                    <div>
                                        <small class="text-muted">
                                            Effective: <?php echo date('F j, Y', strtotime($action['effective_date'])); ?>
                                        </small>
                                        <?php if ($action['expiry_date'] && strtotime($action['expiry_date']) > time()): ?>
                                            <span class="badge badge-warning ml-2">Active</span>
                                        <?php elseif ($action['expiry_date']): ?>
                                            <span class="badge badge-secondary ml-2">Expired</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-8">
                                        <?php if ($action['notes']): ?>
                                            <h6>Action Details:</h6>
                                            <div class="border rounded p-3 bg-light mb-3">
                                                <?php echo nl2br(htmlspecialchars($action['notes'])); ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <!-- Appeal Section -->
                                        <?php if ($action['appeal_id']): ?>
                                            <h6>Appeal Information:</h6>
                                            <div class="border rounded p-3 bg-light">
                                                <div class="row">
                                                    <div class="col-md-6">
                                                        <strong>Appeal Date:</strong><br>
                                                        <?php echo date('F j, Y', strtotime($action['appeal_date'])); ?>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <strong>Outcome:</strong><br>
                                                        <span class="text-capitalize"><?php echo str_replace('_', ' ', $action['outcome']); ?></span>
                                                    </div>
                                                </div>
                                                <?php if ($action['appeal_reason']?? ''): ?>
                                                    <div class="mt-2">
                                                        <strong>Appeal Reason:</strong><br>
                                                        <?php echo nl2br(htmlspecialchars($action['appeal_reason'])); ?>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($action['outcome_notes']): ?>
                                                    <div class="mt-2">
                                                        <strong>Outcome Notes:</strong><br>
                                                        <?php echo nl2br(htmlspecialchars($action['outcome_notes'])); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <!-- Update Appeal Outcome Form -->
                                            <?php if ($action['outcome'] == 'pending'): ?>
                                                <div class="mt-3">
                                                    <h6>Update Appeal Outcome:</h6>
                                                    <form method="POST" class="border rounded p-3 bg-light">
                                                        <input type="hidden" name="update_appeal" value="1">
                                                        <input type="hidden" name="appeal_id" value="<?php echo $action['appeal_id']; ?>">
                                                        
                                                        <div class="row">
                                                            <div class="col-md-4">
                                                                <div class="form-group">
                                                                    <label for="outcome">Outcome <span class="text-danger">*</span></label>
                                                                    <select class="form-control" id="outcome" name="outcome" required>
                                                                        <option value="upheld">Upheld - Action stands</option>
                                                                        <option value="overturned">Overturned - Action reversed</option>
                                                                        <option value="modified">Modified - Action amended</option>
                                                                    </select>
                                                                </div>
                                                            </div>
                                                            <div class="col-md-8">
                                                                <div class="form-group">
                                                                    <label for="outcome_notes">Outcome Notes</label>
                                                                    <textarea class="form-control" id="outcome_notes" name="outcome_notes" rows="2" 
                                                                              placeholder="Explain the appeal decision and any modifications..."></textarea>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <button type="submit" class="btn btn-primary btn-sm">
                                                            <i class="fas fa-check mr-1"></i>Update Outcome
                                                        </button>
                                                    </form>
                                                </div>
                                            <?php endif; ?>
                                            
                                        <?php else: ?>
                                            <!-- Add Appeal Form -->
                                            <div class="mt-3">
                                                <h6>Record Appeal:</h6>
                                                <form method="POST" class="border rounded p-3 bg-light">
                                                    <input type="hidden" name="add_appeal" value="1">
                                                    <input type="hidden" name="action_id" value="<?php echo $action['action_id']; ?>">
                                                    
                                                    <div class="row">
                                                        <div class="col-md-4">
                                                            <div class="form-group">
                                                                <label for="appeal_date">Appeal Date <span class="text-danger">*</span></label>
                                                                <input type="date" class="form-control" id="appeal_date" name="appeal_date" 
                                                                       value="<?php echo date('Y-m-d'); ?>" 
                                                                       max="<?php echo date('Y-m-d'); ?>" required>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-8">
                                                            <div class="form-group">
                                                                <label for="appeal_reason">Appeal Reason <span class="text-danger">*</span></label>
                                                                <textarea class="form-control" id="appeal_reason" name="appeal_reason" rows="2" 
                                                                          placeholder="Explain the grounds for appeal..." required></textarea>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <button type="submit" class="btn btn-warning btn-sm">
                                                        <i class="fas fa-gavel mr-1"></i>Record Appeal
                                                    </button>
                                                </form>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="col-md-4">
                                        <table class="table table-sm table-borderless">
                                            <tr>
                                                <th width="50%">Action ID:</th>
                                                <td>#<?php echo $action['action_id']; ?></td>
                                            </tr>
                                            <tr>
                                                <th>Effective Date:</th>
                                                <td><?php echo date('F j, Y', strtotime($action['effective_date'])); ?></td>
                                            </tr>
                                            <?php if ($action['expiry_date']): ?>
                                                <tr>
                                                    <th>Expiry Date:</th>
                                                    <td><?php echo date('F j, Y', strtotime($action['expiry_date'])); ?></td>
                                                </tr>
                                                <tr>
                                                    <th>Status:</th>
                                                    <td>
                                                        <?php if (strtotime($action['expiry_date']) > time()): ?>
                                                            <span class="badge badge-success">Active</span>
                                                        <?php else: ?>
                                                            <span class="badge badge-secondary">Expired</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                            <tr>
                                                <th>Recorded On:</th>
                                                <td><?php echo date('F j, Y', strtotime($action['created_at'])); ?></td>
                                            </tr>
                                        </table>
                                        
                                        <div class="text-right mt-3">
                                            <a href="edit_disciplinary_action.php?id=<?php echo $action['action_id']; ?>" class="btn btn-primary btn-sm">
                                                <i class="fas fa-edit mr-1"></i>Edit
                                            </a>
                                            <a href="delete_disciplinary_action.php?id=<?php echo $action['action_id']; ?>" 
                                               class="btn btn-danger btn-sm confirm-link"
                                               data-confirm-message="Are you sure you want to delete this disciplinary action?">
                                                <i class="fas fa-trash mr-1"></i>Delete
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="text-center py-4">
                        <i class="fas fa-balance-scale fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No Disciplinary Actions Recorded</h5>
                        <p class="text-muted">Use the form above to record disciplinary actions for this incident.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Set maximum date to today
    var today = new Date().toISOString().split('T')[0];
    $('#effective_date').attr('max', today);
    $('#appeal_date').attr('max', today);
    
    // Auto-set expiry date based on action type
    $('#action_type').change(function() {
        var actionType = $(this).val();
        var effectiveDate = $('#effective_date').val();
        
        if (effectiveDate && actionType) {
            var expiryDate = new Date(effectiveDate);
            
            switch(actionType) {
                case 'verbal_warning':
                    expiryDate.setMonth(expiryDate.getMonth() + 6); // 6 months
                    break;
                case 'written_warning':
                    expiryDate.setMonth(expiryDate.getMonth() + 12); // 12 months
                    break;
                case 'final_warning':
                    expiryDate.setMonth(expiryDate.getMonth() + 24); // 24 months
                    break;
                default:
                    // No expiry for other actions
                    $('#expiry_date').val('');
                    return;
            }
            
            $('#expiry_date').val(expiryDate.toISOString().split('T')[0]);
        }
    });
    
    // Confirm delete links
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
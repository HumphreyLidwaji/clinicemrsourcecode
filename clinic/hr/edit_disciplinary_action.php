<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';



// Check if action ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['alert_type'] = 'error';
    $_SESSION['alert_message'] = 'No disciplinary action ID provided.';
    header("Location: misconduct_dashboard.php");
    exit;
}

$action_id = intval($_GET['id']);

// Get disciplinary action details with incident and employee information
$sql = "SELECT 
            da.*,
            mi.incident_id,
            mi.incident_date,
            mi.description as incident_description,
            mi.severity,
            e.employee_id,
            e.first_name,
            e.last_name,
            e.employee_number,
            d.department_name,
            j.title as position,
            mc.category_name
        FROM disciplinary_actions da
        JOIN misconduct_incidents mi ON da.incident_id = mi.incident_id
        JOIN employees e ON mi.employee_id = e.employee_id
        LEFT JOIN departments d ON e.department_id = d.department_id
        LEFT JOIN job_titles j ON e.job_title_id = j.job_title_id
        JOIN misconduct_categories mc ON mi.category_id = mc.category_id
        WHERE da.action_id = ?";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $action_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION['alert_type'] = 'error';
    $_SESSION['alert_message'] = 'Disciplinary action not found.';
    header("Location: misconduct_dashboard.php");
    exit;
}

$action = $result->fetch_assoc();

// Check if there's an appeal for this action
$appeal_sql = "SELECT * FROM disciplinary_appeals WHERE action_id = ?";
$appeal_stmt = $mysqli->prepare($appeal_sql);
$appeal_stmt->bind_param("i", $action_id);
$appeal_stmt->execute();
$appeal_result = $appeal_stmt->get_result();
$appeal = $appeal_result->fetch_assoc();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action_type = sanitizeInput($_POST['action_type']);
    $effective_date = sanitizeInput($_POST['effective_date']);
    $expiry_date = sanitizeInput($_POST['expiry_date'] ?? '');
    $notes = sanitizeInput($_POST['notes']);
    
    // Validate required fields
    if (empty($action_type) || empty($effective_date)) {
        $_SESSION['alert_type'] = 'error';
        $_SESSION['alert_message'] = 'Please fill in all required fields.';
    } else {
        // Update the disciplinary action
        $sql = "UPDATE disciplinary_actions 
                SET action_type = ?, effective_date = ?, expiry_date = ?, notes = ?
                WHERE action_id = ?";
        
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("ssssi", $action_type, $effective_date, $expiry_date, $notes, $action_id);
        
        if ($stmt->execute()) {
            // Log the action
            $audit_sql = "INSERT INTO hr_audit_log (user_id, action, description, table_name, record_id) 
                          VALUES (?, 'disciplinary_action_updated', ?, 'disciplinary_actions', ?)";
            $audit_stmt = $mysqli->prepare($audit_sql);
            $audit_description = "Updated disciplinary action ID: $action_id - Action: $action_type";
            $audit_stmt->bind_param("isi", $_SESSION['user_id'], $audit_description, $action_id);
            $audit_stmt->execute();
            
            $_SESSION['alert_type'] = 'success';
            $_SESSION['alert_message'] = 'Disciplinary action updated successfully!';
            
            // Redirect to disciplinary actions page
            header("Location: disciplinary_actions.php?incident_id=" . $action['incident_id']);
            exit;
        } else {
            $_SESSION['alert_type'] = 'error';
            $_SESSION['alert_message'] = 'Error updating disciplinary action: ' . $stmt->error;
        }
    }
    
    // Update action data with form values for re-display
    $action = array_merge($action, $_POST);
}
?>

<div class="card">
    <div class="card-header py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-edit mr-2"></i>
                Edit Disciplinary Action #<?php echo $action_id; ?>
            </h3>
            <a href="disciplinary_actions.php?incident_id=<?php echo $action['incident_id']; ?>" class="btn btn-light btn-sm">
                <i class="fas fa-arrow-left mr-2"></i>Back to Actions
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

        <!-- Incident and Employee Summary -->
        <div class="card mb-4">
            <div class="card-header bg-light py-2">
                <h5 class="card-title mb-0"><i class="fas fa-info-circle mr-2"></i>Case Summary</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless">
                            <tr>
                                <th width="40%">Employee:</th>
                                <td>
                                    <strong><?php echo htmlspecialchars($action['first_name'] . ' ' . $action['last_name']); ?></strong>
                                    <br>
                                    <small class="text-muted">
                                        ID: <?php echo htmlspecialchars($action['employee_number']); ?> | 
                                        <?php echo htmlspecialchars($action['department_name'] ?? 'No Department'); ?> |
                                        <?php echo htmlspecialchars($action['position'] ?? 'No Position'); ?>
                                    </small>
                                </td>
                            </tr>
                            <tr>
                                <th>Incident Date:</th>
                                <td><?php echo date('F j, Y', strtotime($action['incident_date'])); ?></td>
                            </tr>
                            <tr>
                                <th>Category:</th>
                                <td><?php echo htmlspecialchars($action['category_name']); ?></td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless">
                            <tr>
                                <th width="40%">Severity:</th>
                                <td>
                                    <span class="badge badge-<?php 
                                        switch($action['severity']) {
                                            case 'low': echo 'info'; break;
                                            case 'medium': echo 'warning'; break;
                                            case 'high': echo 'danger'; break;
                                            case 'gross': echo 'dark'; break;
                                        }
                                    ?>">
                                        <?php echo ucfirst($action['severity']); ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <th>Incident ID:</th>
                                <td>#<?php echo $action['incident_id']; ?></td>
                            </tr>
                            <tr>
                                <th>Action ID:</th>
                                <td>#<?php echo $action_id; ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <div class="form-group mt-3">
                    <label class="font-weight-bold">Incident Description:</label>
                    <div class="border rounded p-3 bg-light">
                        <?php echo nl2br(htmlspecialchars($action['incident_description'])); ?>
                    </div>
                </div>
            </div>
        </div>

        <form method="POST" autocomplete="off">
            <div class="row">
                <div class="col-md-6">
                    <div class="card mb-4">
                        <div class="card-header bg-primary text-white py-2">
                            <h5 class="card-title mb-0"><i class="fas fa-cog mr-2"></i>Action Details</h5>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label for="action_type">Action Type <span class="text-danger">*</span></label>
                                <select class="form-control" id="action_type" name="action_type" required>
                                    <option value="">Select Action</option>
                                    <option value="verbal_warning" <?php echo $action['action_type'] == 'verbal_warning' ? 'selected' : ''; ?>>Verbal Warning</option>
                                    <option value="written_warning" <?php echo $action['action_type'] == 'written_warning' ? 'selected' : ''; ?>>Written Warning</option>
                                    <option value="final_warning" <?php echo $action['action_type'] == 'final_warning' ? 'selected' : ''; ?>>Final Warning</option>
                                    <option value="suspension" <?php echo $action['action_type'] == 'suspension' ? 'selected' : ''; ?>>Suspension</option>
                                    <option value="demotion" <?php echo $action['action_type'] == 'demotion' ? 'selected' : ''; ?>>Demotion</option>
                                    <option value="salary_deduction" <?php echo $action['action_type'] == 'salary_deduction' ? 'selected' : ''; ?>>Salary Deduction</option>
                                    <option value="termination" <?php echo $action['action_type'] == 'termination' ? 'selected' : ''; ?>>Termination</option>
                                    <option value="no_action" <?php echo $action['action_type'] == 'no_action' ? 'selected' : ''; ?>>No Action</option>
                                </select>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="effective_date">Effective Date <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" id="effective_date" name="effective_date" 
                                               value="<?php echo htmlspecialchars($action['effective_date']); ?>" 
                                               max="<?php echo date('Y-m-d'); ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="expiry_date">Expiry Date</label>
                                        <input type="date" class="form-control" id="expiry_date" name="expiry_date" 
                                               value="<?php echo htmlspecialchars($action['expiry_date'] ?? ''); ?>">
                                        <small class="form-text text-muted">
                                            For warnings - when the warning expires from record
                                        </small>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label>Recorded On</label>
                                <input type="text" class="form-control" value="<?php echo date('F j, Y g:i A', strtotime($action['created_at'])); ?>" disabled>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card mb-4">
                        <div class="card-header bg-info text-white py-2">
                            <h5 class="card-title mb-0"><i class="fas fa-sticky-note mr-2"></i>Additional Information</h5>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label for="notes">Action Details & Notes</label>
                                <textarea class="form-control" id="notes" name="notes" rows="8" 
                                          placeholder="Provide details about the disciplinary action, including any specific conditions, requirements, or additional information..."><?php echo htmlspecialchars($action['notes'] ?? ''); ?></textarea>
                                <small class="form-text text-muted">
                                    Include specific details such as suspension duration, warning terms, training requirements, or other relevant information
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Appeal Information (if exists) -->
            <?php if ($appeal): ?>
                <div class="card mb-4">
                    <div class="card-header bg-warning text-white py-2">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-gavel mr-2"></i>
                            Appeal Information
                            <span class="badge badge-light ml-2"><?php echo ucfirst($appeal['outcome']); ?></span>
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <th width="40%">Appeal Date:</th>
                                        <td><?php echo date('F j, Y', strtotime($appeal['appeal_date'])); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Appeal Outcome:</th>
                                        <td class="text-capitalize"><?php echo str_replace('_', ' ', $appeal['outcome']); ?></td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <th width="40%">Appeal ID:</th>
                                        <td>#<?php echo $appeal['appeal_id']; ?></td>
                                    </tr>
                                    <tr>
                                        <th>Status:</th>
                                        <td>
                                            <span class="badge badge-<?php 
                                                switch($appeal['outcome']) {
                                                    case 'upheld': echo 'success'; break;
                                                    case 'overturned': echo 'danger'; break;
                                                    case 'modified': echo 'warning'; break;
                                                    case 'pending': echo 'info'; break;
                                                    default: echo 'secondary';
                                                }
                                            ?>">
                                                <?php echo ucfirst($appeal['outcome']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                        
                        <?php if ($appeal['appeal_reason']): ?>
                            <div class="form-group mt-3">
                                <label class="font-weight-bold">Appeal Reason:</label>
                                <div class="border rounded p-3 bg-light">
                                    <?php echo nl2br(htmlspecialchars($appeal['appeal_reason'])); ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($appeal['outcome_notes']): ?>
                            <div class="form-group mt-3">
                                <label class="font-weight-bold">Outcome Notes:</label>
                                <div class="border rounded p-3 bg-light">
                                    <?php echo nl2br(htmlspecialchars($appeal['outcome_notes'])); ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <div class="text-right mt-3">
                            <a href="edit_appeal.php?id=<?php echo $appeal['appeal_id']; ?>" class="btn btn-warning btn-sm">
                                <i class="fas fa-edit mr-1"></i>Edit Appeal
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <div class="row mt-4">
                <div class="col-md-12">
                    <div class="alert alert-info">
                        <h6><i class="fas fa-lightbulb mr-2"></i>Editing Guidelines</h6>
                        <ul class="mb-0">
                            <li>Ensure all changes comply with company policies and labor laws</li>
                            <li>Consider notifying the employee of significant changes to the disciplinary action</li>
                            <li>Document the reason for any changes in the notes section</li>
                            <li>Review the impact on any existing appeals or related processes</li>
                            <li>Changes will be logged for audit purposes</li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <div class="form-group mt-4">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save mr-2"></i>Update Disciplinary Action
                </button>
                <a href="disciplinary_actions.php?incident_id=<?php echo $action['incident_id']; ?>" class="btn btn-secondary">
                    <i class="fas fa-times mr-2"></i>Cancel
                </a>
                
                <a href="delete_disciplinary_action.php?id=<?php echo $action_id; ?>" 
                   class="btn btn-danger float-right confirm-link"
                   data-confirm-message="Are you sure you want to delete this disciplinary action? This action cannot be undone and may affect related records.">
                    <i class="fas fa-trash mr-2"></i>Delete Action
                </a>
            </div>
        </form>
    </div>
</div>

<script>
$(document().ready(function() {
    // Set maximum date to today
    var today = new Date().toISOString().split('T')[0];
    $('#effective_date').attr('max', today);
    
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
    
    // Trigger change on page load if action type is set
    $('#action_type').trigger('change');
    
    // Confirm delete link
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

<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

/* Check permissions
if (SimplePermission::any('hr')) {
    $_SESSION['alert_type'] = 'error';
    $_SESSION['alert_message'] = 'You do not have permission to edit appeals.';
    header("Location: misconduct_dashboard.php");
    exit;
}
*/
// Check if appeal ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['alert_type'] = 'error';
    $_SESSION['alert_message'] = 'No appeal ID provided.';
    header("Location: misconduct_dashboard.php");
    exit;
}

$appeal_id = intval($_GET['id']);

// Get appeal details with related information
$sql = "SELECT 
            a.*,
            da.action_id,
            da.action_type,
            da.effective_date,
            da.expiry_date,
            da.notes as action_notes,
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
        FROM disciplinary_appeals a
        JOIN disciplinary_actions da ON a.action_id = da.action_id
        JOIN misconduct_incidents mi ON da.incident_id = mi.incident_id
        JOIN employees e ON mi.employee_id = e.employee_id
        LEFT JOIN departments d ON e.department_id = d.department_id
        LEFT JOIN job_titles j ON e.job_title_id = j.job_title_id
        JOIN misconduct_categories mc ON mi.category_id = mc.category_id
        WHERE a.appeal_id = ?";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $appeal_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION['alert_type'] = 'error';
    $_SESSION['alert_message'] = 'Appeal not found.';
    header("Location: misconduct_dashboard.php");
    exit;
}

$appeal = $result->fetch_assoc();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $appeal_date = sanitizeInput($_POST['appeal_date']);
    $appeal_reason = sanitizeInput($_POST['appeal_reason']);
    $outcome = sanitizeInput($_POST['outcome']);
    $outcome_notes = sanitizeInput($_POST['outcome_notes']);
    
    // Validate required fields
    if (empty($appeal_date) || empty($appeal_reason) || empty($outcome)) {
        $_SESSION['alert_type'] = 'error';
        $_SESSION['alert_message'] = 'Please fill in all required fields.';
    } else {
        // Update the appeal
        $sql = "UPDATE disciplinary_appeals 
                SET appeal_date = ?, appeal_reason = ?, outcome = ?, outcome_notes = ?
                WHERE appeal_id = ?";
        
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("ssssi", $appeal_date, $appeal_reason, $outcome, $outcome_notes, $appeal_id);
        
        if ($stmt->execute()) {
            // Log the action
            $audit_sql = "INSERT INTO hr_audit_log (user_id, action, description, table_name, record_id) 
                          VALUES (?, 'disciplinary_appeal_updated', ?, 'disciplinary_appeals', ?)";
            $audit_stmt = $mysqli->prepare($audit_sql);
            $audit_description = "Updated appeal ID: $appeal_id - Outcome: $outcome";
            $audit_stmt->bind_param("isi", $_SESSION['user_id'], $audit_description, $appeal_id);
            $audit_stmt->execute();
            
            $_SESSION['alert_type'] = 'success';
            $_SESSION['alert_message'] = 'Appeal updated successfully!';
            
            // Redirect to disciplinary actions page
            header("Location: disciplinary_actions.php?incident_id=" . $appeal['incident_id']);
            exit;
        } else {
            $_SESSION['alert_type'] = 'error';
            $_SESSION['alert_message'] = 'Error updating appeal: ' . $stmt->error;
        }
    }
    
    // Update appeal data with form values for re-display
    $appeal = array_merge($appeal, $_POST);
}
?>

<div class="card">
    <div class="card-header py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-gavel mr-2"></i>
                Edit Appeal #<?php echo $appeal_id; ?>
            </h3>
            <a href="disciplinary_actions.php?incident_id=<?php echo $appeal['incident_id']; ?>" class="btn btn-light btn-sm">
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

        <!-- Case Summary -->
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
                                    <strong><?php echo htmlspecialchars($appeal['first_name'] . ' ' . $appeal['last_name']); ?></strong>
                                    <br>
                                    <small class="text-muted">
                                        ID: <?php echo htmlspecialchars($appeal['employee_number']); ?> | 
                                        <?php echo htmlspecialchars($appeal['department_name'] ?? 'No Department'); ?> |
                                        <?php echo htmlspecialchars($appeal['position'] ?? 'No Position'); ?>
                                    </small>
                                </td>
                            </tr>
                            <tr>
                                <th>Incident Date:</th>
                                <td><?php echo date('F j, Y', strtotime($appeal['incident_date'])); ?></td>
                            </tr>
                            <tr>
                                <th>Category:</th>
                                <td><?php echo htmlspecialchars($appeal['category_name']); ?></td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless">
                            <tr>
                                <th width="40%">Severity:</th>
                                <td>
                                    <span class="badge badge-<?php 
                                        switch($appeal['severity']) {
                                            case 'low': echo 'info'; break;
                                            case 'medium': echo 'warning'; break;
                                            case 'high': echo 'danger'; break;
                                            case 'gross': echo 'dark'; break;
                                        }
                                    ?>">
                                        <?php echo ucfirst($appeal['severity']); ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <th>Incident ID:</th>
                                <td>#<?php echo $appeal['incident_id']; ?></td>
                            </tr>
                            <tr>
                                <th>Action ID:</th>
                                <td>#<?php echo $appeal['action_id']; ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <div class="form-group mt-3">
                    <label class="font-weight-bold">Incident Description:</label>
                    <div class="border rounded p-3 bg-light">
                        <?php echo nl2br(htmlspecialchars($appeal['incident_description'])); ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Disciplinary Action Information -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white py-2">
                <h5 class="card-title mb-0"><i class="fas fa-balance-scale mr-2"></i>Disciplinary Action Details</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless">
                            <tr>
                                <th width="50%">Action Type:</th>
                                <td class="text-capitalize"><?php echo str_replace('_', ' ', $appeal['action_type']); ?></td>
                            </tr>
                            <tr>
                                <th>Effective Date:</th>
                                <td><?php echo date('F j, Y', strtotime($appeal['effective_date'])); ?></td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless">
                            <tr>
                                <th width="50%">Status:</th>
                                <td>
                                    <?php if ($appeal['expiry_date'] && strtotime($appeal['expiry_date']) > time()): ?>
                                        <span class="badge badge-success">Active</span>
                                    <?php elseif ($appeal['expiry_date']): ?>
                                        <span class="badge badge-secondary">Expired</span>
                                    <?php else: ?>
                                        <span class="badge badge-info">Ongoing</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php if ($appeal['expiry_date']): ?>
                                <tr>
                                    <th>Expiry Date:</th>
                                    <td><?php echo date('F j, Y', strtotime($appeal['expiry_date'])); ?></td>
                                </tr>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>
                
                <?php if ($appeal['action_notes']): ?>
                    <div class="form-group mt-3">
                        <label class="font-weight-bold">Action Details:</label>
                        <div class="border rounded p-3 bg-light">
                            <?php echo nl2br(htmlspecialchars($appeal['action_notes'])); ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <div class="text-right mt-3">
                    <a href="edit_disciplinary_action.php?id=<?php echo $appeal['action_id']; ?>" class="btn btn-primary btn-sm">
                        <i class="fas fa-edit mr-1"></i>Edit Action
                    </a>
                </div>
            </div>
        </div>

        <form method="POST" autocomplete="off">
            <div class="row">
                <div class="col-md-6">
                    <div class="card mb-4">
                        <div class="card-header bg-warning text-white py-2">
                            <h5 class="card-title mb-0"><i class="fas fa-clipboard-list mr-2"></i>Appeal Information</h5>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label for="appeal_date">Appeal Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="appeal_date" name="appeal_date" 
                                       value="<?php echo htmlspecialchars($appeal['appeal_date']); ?>" 
                                       max="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="appeal_reason">Appeal Reason <span class="text-danger">*</span></label>
                                <textarea class="form-control" id="appeal_reason" name="appeal_reason" rows="6" 
                                          placeholder="Explain the grounds for appeal, including any new evidence, procedural errors, or mitigating circumstances..."
                                          required><?php echo htmlspecialchars($appeal['appeal_reason']); ?></textarea>
                                <small class="form-text text-muted">
                                    Clearly state the reasons for the appeal and any supporting evidence
                                </small>
                            </div>
                            
                            <div class="form-group">
                                <label>Appeal Filed On</label>
                                <input type="text" class="form-control" value="<?php echo date('F j, Y g:i A', strtotime($appeal['created_at'])); ?>" disabled>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card mb-4">
                        <div class="card-header bg-success text-white py-2">
                            <h5 class="card-title mb-0"><i class="fas fa-check-circle mr-2"></i>Appeal Outcome</h5>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label for="outcome">Appeal Outcome <span class="text-danger">*</span></label>
                                <select class="form-control" id="outcome" name="outcome" required>
                                    <option value="pending" <?php echo $appeal['outcome'] == 'pending' ? 'selected' : ''; ?>>Pending - Under review</option>
                                    <option value="upheld" <?php echo $appeal['outcome'] == 'upheld' ? 'selected' : ''; ?>>Upheld - Original action stands</option>
                                    <option value="overturned" <?php echo $appeal['outcome'] == 'overturned' ? 'selected' : ''; ?>>Overturned - Action reversed</option>
                                    <option value="modified" <?php echo $appeal['outcome'] == 'modified' ? 'selected' : ''; ?>>Modified - Action amended</option>
                                </select>
                                <small class="form-text text-muted">
                                    Select the final outcome of the appeal process
                                </small>
                            </div>
                            
                            <div class="form-group">
                                <label for="outcome_notes">Outcome Notes & Justification</label>
                                <textarea class="form-control" id="outcome_notes" name="outcome_notes" rows="8" 
                                          placeholder="Provide detailed notes about the appeal decision, including the reasoning, any conditions, and implementation details..."><?php echo htmlspecialchars($appeal['outcome_notes'] ?? ''); ?></textarea>
                                <small class="form-text text-muted">
                                    Explain the decision-making process, considerations, and any modifications to the original action
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Appeal Process Guidelines -->
            <div class="card mb-4">
                <div class="card-header bg-info text-white py-2">
                    <h5 class="card-title mb-0"><i class="fas fa-lightbulb mr-2"></i>Appeal Process Guidelines</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Common Appeal Grounds:</h6>
                            <ul>
                                <li>Procedural errors in the disciplinary process</li>
                                <li>New evidence that wasn't available originally</li>
                                <li>Inconsistency in disciplinary actions</li>
                                <li>Mitigating circumstances not considered</li>
                                <li>Disproportionate severity of the action</li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h6>Outcome Considerations:</h6>
                            <ul>
                                <li><strong>Upheld:</strong> Original action was fair and appropriate</li>
                                <li><strong>Overturned:</strong> Significant errors or new evidence warrant complete reversal</li>
                                <li><strong>Modified:</strong> Action was partially justified but needs adjustment</li>
                                <li>Ensure decisions align with company policies and legal requirements</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="row mt-4">
                <div class="col-md-12">
                    <div class="alert alert-warning">
                        <h6><i class="fas fa-exclamation-triangle mr-2"></i>Important Notes</h6>
                        <ul class="mb-0">
                            <li>All changes to appeal information will be logged for audit purposes</li>
                            <li>Consider notifying the employee of appeal outcome changes</li>
                            <li>Ensure appeal decisions comply with company policies and labor laws</li>
                            <li>Document the reasoning thoroughly for transparency</li>
                            <li>Review the impact on related records and processes</li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <div class="form-group mt-4">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save mr-2"></i>Update Appeal
                </button>
                <a href="disciplinary_actions.php?incident_id=<?php echo $appeal['incident_id']; ?>" class="btn btn-secondary">
                    <i class="fas fa-times mr-2"></i>Cancel
                </a>
                
                <a href="delete_appeal.php?id=<?php echo $appeal_id; ?>" 
                   class="btn btn-danger float-right confirm-link"
                   data-confirm-message="Are you sure you want to delete this appeal? This action cannot be undone.">
                    <i class="fas fa-trash mr-2"></i>Delete Appeal
                </a>
            </div>
        </form>
    </div>
</div>

<script>
$(document).ready(function() {
    // Set maximum date to today
    var today = new Date().toISOString().split('T')[0];
    $('#appeal_date').attr('max', today);
    
    // Character counters
    $('#appeal_reason').on('input', function() {
        var length = $(this).val().length;
        $('#appealReasonCount').text(length + ' characters');
    });
    
    $('#outcome_notes').on('input', function() {
        var length = $(this).val().length;
        $('#outcomeNotesCount').text(length + ' characters');
    });
    
    // Show/hide outcome notes based on outcome selection
    $('#outcome').change(function() {
        var outcome = $(this).val();
        if (outcome === 'pending') {
            $('#outcome_notes').attr('placeholder', 'Notes about the appeal review process...');
        } else {
            $('#outcome_notes').attr('placeholder', 'Provide detailed notes about the appeal decision, including the reasoning, any conditions, and implementation details...');
        }
    });
    
    // Trigger change on page load
    $('#outcome').trigger('change');
    
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
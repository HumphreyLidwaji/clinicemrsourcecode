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
            e.first_name, e.last_name, e.employee_number, 
            d.department_name,
            er.first_name as reported_first, er.last_name as reported_last
        FROM misconduct_incidents mi
        JOIN employees e ON mi.employee_id = e.employee_id
        LEFT JOIN departments d ON e.department_id = d.department_id
        JOIN employees er ON mi.reported_by = er.employee_id
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

// Get active categories for dropdown
$categories_sql = "SELECT category_id, category_name FROM misconduct_categories WHERE is_active = 1 ORDER BY category_name";
$categories_result = $mysqli->query($categories_sql);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $category_id = intval($_POST['category_id']);
    $incident_date = sanitizeInput($_POST['incident_date']);
    $description = sanitizeInput($_POST['description']);
    $severity = sanitizeInput($_POST['severity']);
    $status = sanitizeInput($_POST['status']);
    
    // Validate required fields
    if (empty($category_id) || empty($incident_date) || empty($description) || empty($severity) || empty($status)) {
        $_SESSION['alert_type'] = 'error';
        $_SESSION['alert_message'] = 'Please fill in all required fields.';
    } else {
        // Update the incident
        $sql = "UPDATE misconduct_incidents 
                SET category_id = ?, incident_date = ?, description = ?, severity = ?, status = ?
                WHERE incident_id = ?";
        
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("issssi", $category_id, $incident_date, $description, $severity, $status, $incident_id);
        
        if ($stmt->execute()) {
            // Log the action
            $audit_sql = "INSERT INTO hr_audit_log (user_id, action, description, table_name, record_id) 
                          VALUES (?, 'misconduct_incident_updated', ?, 'misconduct_incidents', ?)";
            $audit_stmt = $mysqli->prepare($audit_sql);
            $audit_description = "Updated misconduct incident ID: $incident_id";
            $audit_stmt->bind_param("isi", $_SESSION['user_id'], $audit_description, $incident_id);
            $audit_stmt->execute();
            
            $_SESSION['alert_type'] = 'success';
            $_SESSION['alert_message'] = 'Incident updated successfully!';
            
            // Redirect to view the incident
            header("Location: view_misconduct_incident.php?id=$incident_id");
            exit;
        } else {
            $_SESSION['alert_type'] = 'error';
            $_SESSION['alert_message'] = 'Error updating incident: ' . $stmt->error;
        }
    }
    
    // Update incident data with form values for re-display
    $incident = array_merge($incident, $_POST);
}
?>

<div class="card">
    <div class="card-header py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-edit mr-2"></i>
                Edit Misconduct Incident #<?php echo $incident_id; ?>
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
        
        <form method="POST" autocomplete="off">
            <div class="row">
                <div class="col-md-6">
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h5 class="card-title mb-0"><i class="fas fa-user mr-2"></i>Employee Information</h5>
                        </div>
                        <div class="card-body">
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <th width="40%">Employee:</th>
                                    <td>
                                        <strong><?php echo htmlspecialchars($incident['first_name'] . ' ' . $incident['last_name']); ?></strong>
                                        <br>
                                        <small class="text-muted">
                                            ID: <?php echo htmlspecialchars($incident['employee_number']); ?> | 
                                            <?php echo htmlspecialchars($incident['department_name'] ?? 'No Department'); ?>
                                        </small>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Reported By:</th>
                                    <td>
                                        <?php echo htmlspecialchars($incident['reported_first'] . ' ' . $incident['reported_last']); ?>
                                        <br>
                                        <small class="text-muted">
                                            On: <?php echo date('F j, Y g:i A', strtotime($incident['created_at'])); ?>
                                        </small>
                                    </td>
                                </tr>
                            </table>
                            <div class="alert alert-info mt-3">
                                <small>
                                    <i class="fas fa-info-circle mr-2"></i>
                                    <strong>Note:</strong> Employee and reporter information cannot be changed. 
                                    To change the involved employee, create a new incident.
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h5 class="card-title mb-0"><i class="fas fa-cog mr-2"></i>Incident Settings</h5>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label for="category_id">Misconduct Category <span class="text-danger">*</span></label>
                                <select class="form-control select2" id="category_id" name="category_id" required>
                                    <option value="">Select Category</option>
                                    <?php while ($category = $categories_result->fetch_assoc()): ?>
                                        <option value="<?php echo $category['category_id']; ?>" 
                                            <?php echo ($incident['category_id'] == $category['category_id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($category['category_name']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="incident_date">Incident Date <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" id="incident_date" name="incident_date" 
                                               value="<?php echo htmlspecialchars($incident['incident_date']); ?>" 
                                               max="<?php echo date('Y-m-d'); ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="severity">Severity Level <span class="text-danger">*</span></label>
                                        <select class="form-control" id="severity" name="severity" required>
                                            <option value="low" <?php echo $incident['severity'] == 'low' ? 'selected' : ''; ?>>Low - Minor policy violation</option>
                                            <option value="medium" <?php echo $incident['severity'] == 'medium' ? 'selected' : ''; ?>>Medium - Moderate policy violation</option>
                                            <option value="high" <?php echo $incident['severity'] == 'high' ? 'selected' : ''; ?>>High - Serious policy violation</option>
                                            <option value="gross" <?php echo $incident['severity'] == 'gross' ? 'selected' : ''; ?>>Gross - Gross misconduct</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="status">Case Status <span class="text-danger">*</span></label>
                                <select class="form-control" id="status" name="status" required>
                                    <option value="open" <?php echo $incident['status'] == 'open' ? 'selected' : ''; ?>>Open - Initial reporting stage</option>
                                    <option value="under_investigation" <?php echo $incident['status'] == 'under_investigation' ? 'selected' : ''; ?>>Under Investigation - Being investigated</option>
                                    <option value="closed" <?php echo $incident['status'] == 'closed' ? 'selected' : ''; ?>>Closed - Case resolved</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="card mb-4">
                <div class="card-header bg-light py-2">
                    <h5 class="card-title mb-0"><i class="fas fa-align-left mr-2"></i>Incident Description</h5>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label for="description">Incident Description <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="description" name="description" rows="8" 
                                  placeholder="Provide a detailed description of the incident, including date, time, location, witnesses, and specific behaviors observed..." 
                                  required><?php echo htmlspecialchars($incident['description']); ?></textarea>
                        <small class="form-text text-muted">
                            Be specific and factual. Include relevant details such as:
                            <ul class="mb-0 pl-3">
                                <li>Exact time and location of incident</li>
                                <li>Specific behaviors or actions observed</li>
                                <li>Names of any witnesses</li>
                                <li>Impact on workplace or other employees</li>
                                <li>Any previous similar incidents</li>
                            </ul>
                        </small>
                    </div>
                </div>
            </div>
            
            <div class="row mt-4">
                <div class="col-md-12">
                    <div class="alert alert-warning">
                        <h6><i class="fas fa-exclamation-triangle mr-2"></i>Important Notes</h6>
                        <ul class="mb-0">
                            <li>Changes to incident details will be logged for audit purposes</li>
                            <li>Updating the status may affect workflow (e.g., closing incident prevents further actions)</li>
                            <li>Ensure all changes comply with company policies and procedures</li>
                            <li>Consider notifying relevant parties of significant changes</li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <div class="form-group mt-4">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save mr-2"></i>Update Incident
                </button>
                <a href="view_misconduct_incident.php?id=<?php echo $incident_id; ?>" class="btn btn-secondary">
                    <i class="fas fa-times mr-2"></i>Cancel
                </a>
                
                    <a href="delete_misconduct_incident.php?id=<?php echo $incident_id; ?>" 
                       class="btn btn-danger float-right confirm-link"
                       data-confirm-message="Are you sure you want to delete this incident? This action cannot be undone.">
                        <i class="fas fa-trash mr-2"></i>Delete Incident
                    </a>
               
            </div>
        </form>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.select2').select2();
    
    // Set maximum date to today
    var today = new Date().toISOString().split('T')[0];
    $('#incident_date').attr('max', today);
    
    // Character counter for description
    $('#description').on('input', function() {
        var length = $(this).val().length;
        $('#charCount').text(length + ' characters');
    });
    
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
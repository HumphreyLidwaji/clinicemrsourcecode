<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';


// Get active categories for dropdown
$categories_sql = "SELECT category_id, category_name FROM misconduct_categories WHERE is_active = 1 ORDER BY category_name";
$categories_result = $mysqli->query($categories_sql);

// Get active employees for dropdown
$employees_sql = "SELECT employee_id, first_name, last_name, employee_number
                  FROM employees 
                  WHERE employment_status = 'active' 
                  ORDER BY first_name, last_name";
$employees_result = $mysqli->query($employees_sql);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $employee_id = intval($_POST['employee_id']);
    $category_id = intval($_POST['category_id']);
    $incident_date = sanitizeInput($_POST['incident_date']);
    $description = sanitizeInput($_POST['description']);
    $severity = sanitizeInput($_POST['severity']);
    $reported_by = intval($_SESSION['user_id']); // Current user as reporter
    
    // Validate required fields
    if (empty($employee_id) || empty($category_id) || empty($incident_date) || empty($description)) {
        $_SESSION['alert_type'] = 'error';
        $_SESSION['alert_message'] = 'Please fill in all required fields.';
    } else {
        // Insert the incident
        $sql = "INSERT INTO misconduct_incidents 
                (employee_id, category_id, incident_date, reported_by, description, severity, status) 
                VALUES (?, ?, ?, ?, ?, ?, 'open')";
        
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("iisiss", $employee_id, $category_id, $incident_date, $reported_by, $description, $severity);
        
        if ($stmt->execute()) {
            $incident_id = $mysqli->insert_id;
            
            // Log the action
            $audit_sql = "INSERT INTO hr_audit_log (user_id, action, description, table_name, record_id) 
                          VALUES (?, 'misconduct_incident_created', ?, 'misconduct_incidents', ?)";
            $audit_stmt = $mysqli->prepare($audit_sql);
            $audit_description = "Created misconduct incident ID: $incident_id for employee ID: $employee_id";
            $audit_stmt->bind_param("isi", $_SESSION['user_id'], $audit_description, $incident_id);
            $audit_stmt->execute();
            
            $_SESSION['alert_type'] = 'success';
            $_SESSION['alert_message'] = 'Misconduct incident reported successfully!';
            
            // Redirect to view the incident
            header("Location: view_misconduct_incident.php?id=$incident_id");
            exit;
        } else {
            $_SESSION['alert_type'] = 'error';
            $_SESSION['alert_message'] = 'Error reporting incident: ' . $stmt->error;
        }
    }
}
?>

<div class="card">
    <div class="card-header py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0"><i class="fas fa-fw fa-exclamation-triangle mr-2"></i>Report Misconduct Incident</h3>
            <a href="misconduct_dashboard.php" class="btn btn-light btn-sm">
                <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
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
                    <div class="form-group">
                        <label for="employee_id">Employee Involved <span class="text-danger">*</span></label>
                        <select class="form-control select2" id="employee_id" name="employee_id" required>
                            <option value="">Select Employee</option>
                            <?php while ($employee = $employees_result->fetch_assoc()): ?>
                                <option value="<?php echo $employee['employee_id']; ?>" 
                                    <?php echo isset($_POST['employee_id']) && $_POST['employee_id'] == $employee['employee_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name'] . ' (' . $employee['employee_number']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="category_id">Misconduct Category <span class="text-danger">*</span></label>
                        <select class="form-control select2" id="category_id" name="category_id" required>
                            <option value="">Select Category</option>
                            <?php while ($category = $categories_result->fetch_assoc()): ?>
                                <option value="<?php echo $category['category_id']; ?>"
                                    <?php echo isset($_POST['category_id']) && $_POST['category_id'] == $category['category_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($category['category_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-4">
                    <div class="form-group">
                        <label for="incident_date">Incident Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="incident_date" name="incident_date" 
                               value="<?php echo isset($_POST['incident_date']) ? $_POST['incident_date'] : date('Y-m-d'); ?>" 
                               max="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="form-group">
                        <label for="severity">Severity Level <span class="text-danger">*</span></label>
                        <select class="form-control" id="severity" name="severity" required>
                            <option value="low" <?php echo isset($_POST['severity']) && $_POST['severity'] == 'low' ? 'selected' : ''; ?>>Low - Minor policy violation</option>
                            <option value="medium" <?php echo isset($_POST['severity']) && $_POST['severity'] == 'medium' ? 'selected' : ''; ?>>Medium - Moderate policy violation</option>
                            <option value="high" <?php echo isset($_POST['severity']) && $_POST['severity'] == 'high' ? 'selected' : ''; ?>>High - Serious policy violation</option>
                            <option value="gross" <?php echo isset($_POST['severity']) && $_POST['severity'] == 'gross' ? 'selected' : ''; ?>>Gross - Gross misconduct</option>
                        </select>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="form-group">
                        <label>Reported By</label>
                        <input type="text" class="form-control" value="<?php echo $_SESSION['user_name']; ?>" disabled>
                        <small class="form-text text-muted">Automatically set to current user</small>
                    </div>
                </div>
            </div>
            
            <div class="form-group">
                <label for="description">Incident Description <span class="text-danger">*</span></label>
                <textarea class="form-control" id="description" name="description" rows="6" 
                          placeholder="Provide a detailed description of the incident, including date, time, location, witnesses, and specific behaviors observed..." 
                          required><?php echo isset($_POST['description']) ? $_POST['description'] : ''; ?></textarea>
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
            
            <div class="row mt-4">
                <div class="col-md-12">
                    <div class="alert alert-info">
                        <h6><i class="fas fa-info-circle mr-2"></i>Important Notes</h6>
                        <ul class="mb-0">
                            <li>All misconduct reports are confidential and will be handled according to company policy</li>
                            <li>Ensure all information provided is accurate and factual</li>
                            <li>This incident will be reviewed by HR for appropriate action</li>
                            <li>You may be contacted for additional information during the investigation</li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <div class="form-group mt-4">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-paper-plane mr-2"></i>Report Incident
                </button>
                <a href="misconduct_dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-times mr-2"></i>Cancel
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
});
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
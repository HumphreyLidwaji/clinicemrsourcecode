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
                    mc.category_name
                 FROM misconduct_incidents mi
                 JOIN employees e ON mi.employee_id = e.employee_id
                 LEFT JOIN departments d ON e.department_id = d.department_id
                 JOIN misconduct_categories mc ON mi.category_id = mc.category_id
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

// Check if investigation already exists
$existing_inv_sql = "SELECT investigation_id FROM misconduct_investigations WHERE incident_id = ?";
$existing_inv_stmt = $mysqli->prepare($existing_inv_sql);
$existing_inv_stmt->bind_param("i", $incident_id);
$existing_inv_stmt->execute();
$existing_inv_result = $existing_inv_stmt->get_result();

if ($existing_inv_result->num_rows > 0) {
    $_SESSION['alert_type'] = 'error';
    $_SESSION['alert_message'] = 'An investigation already exists for this incident.';
    header("Location: view_misconduct_incident.php?id=$incident_id");
    exit;
}

// Get investigators (HR staff and managers) with correct column names
$investigators_sql = "SELECT 
                        e.employee_id, e.first_name, e.last_name, 
                        j.title as position, 
                        d.department_name
                      FROM employees e
                      LEFT JOIN job_titles j ON e.job_title_id = j.job_title_id
                      LEFT JOIN departments d ON e.department_id = d.department_id
                      WHERE (j.title LIKE '%HR%' OR j.title LIKE '%Manager%' OR j.title LIKE '%Director%')
                      AND e.employment_status = 'Active'
                      ORDER BY e.first_name, e.last_name";
$investigators_result = $mysqli->query($investigators_sql);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $investigator_id = intval($_POST['investigator_id']);
    $start_date = sanitizeInput($_POST['start_date']);
    $estimated_end_date = sanitizeInput($_POST['estimated_end_date']);
    $investigation_scope = sanitizeInput($_POST['investigation_scope']);
    $methodology = sanitizeInput($_POST['methodology']);
    
    // Validate required fields
    if (empty($investigator_id) || empty($start_date) || empty($investigation_scope)) {
        $_SESSION['alert_type'] = 'error';
        $_SESSION['alert_message'] = 'Please fill in all required fields.';
    } else {
        // Insert the investigation
        $sql = "INSERT INTO misconduct_investigations 
                (incident_id, investigator_id, start_date, estimated_end_date, investigation_scope, methodology) 
                VALUES (?, ?, ?, ?, ?, ?)";
        
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("iissss", $incident_id, $investigator_id, $start_date, $estimated_end_date, $investigation_scope, $methodology);
        
        if ($stmt->execute()) {
            $investigation_id = $mysqli->insert_id;
            
            // Update incident status
            $update_sql = "UPDATE misconduct_incidents SET status = 'under_investigation' WHERE incident_id = ?";
            $update_stmt = $mysqli->prepare($update_sql);
            $update_stmt->bind_param("i", $incident_id);
            $update_stmt->execute();
            
            // Log the action
            $audit_sql = "INSERT INTO hr_audit_log (user_id, action, description, table_name, record_id) 
                          VALUES (?, 'investigation_started', ?, 'misconduct_investigations', ?)";
            $audit_stmt = $mysqli->prepare($audit_sql);
            $audit_description = "Started investigation for incident ID: $incident_id";
            $audit_stmt->bind_param("isi", $_SESSION['user_id'], $audit_description, $investigation_id);
            $audit_stmt->execute();
            
            $_SESSION['alert_type'] = 'success';
            $_SESSION['alert_message'] = 'Investigation started successfully!';
            
            // Redirect to view the investigation
            header("Location: view_investigation.php?incident_id=$incident_id");
            exit;
        } else {
            $_SESSION['alert_type'] = 'error';
            $_SESSION['alert_message'] = 'Error starting investigation: ' . $stmt->error;
        }
    }
}
?>

<div class="card">
    <div class="card-header py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-search mr-2"></i>
                Start Investigation - Incident #<?php echo $incident_id; ?>
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
                <h5 class="card-title mb-0"><i class="fas fa-info-circle mr-2"></i>Incident Summary</h5>
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
                                        <?php echo htmlspecialchars($incident['department_name'] ?? 'No Department'); ?>
                                    </small>
                                </td>
                            </tr>
                            <tr>
                                <th>Category:</th>
                                <td><?php echo htmlspecialchars($incident['category_name']); ?></td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless">
                            <tr>
                                <th width="40%">Severity:</th>
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
                            <tr>
                                <th>Incident Date:</th>
                                <td><?php echo date('F j, Y', strtotime($incident['incident_date'])); ?></td>
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

        <form method="POST" autocomplete="off">
            <div class="row">
                <div class="col-md-6">
                    <div class="card mb-4">
                        <div class="card-header bg-primary text-white py-2">
                            <h5 class="card-title mb-0"><i class="fas fa-user-tie mr-2"></i>Investigation Team</h5>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label for="investigator_id">Lead Investigator <span class="text-danger">*</span></label>
                                <select class="form-control select2" id="investigator_id" name="investigator_id" required>
                                    <option value="">Select Investigator</option>
                                    <?php while ($investigator = $investigators_result->fetch_assoc()): ?>
                                        <option value="<?php echo $investigator['employee_id']; ?>" 
                                            <?php echo isset($_POST['investigator_id']) && $_POST['investigator_id'] == $investigator['employee_id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($investigator['first_name'] . ' ' . $investigator['last_name'] . ' - ' . $investigator['position'] . ' (' . $investigator['department_name'] . ')'); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                                <small class="form-text text-muted">
                                    Select an HR staff member or manager to lead the investigation
                                </small>
                            </div>
                            
                            <div class="form-group">
                                <label>Current User</label>
                                <input type="text" class="form-control" value="<?php echo $_SESSION['user_name']; ?>" disabled>
                                <small class="form-text text-muted">
                                    You are creating this investigation. The lead investigator will be responsible for conducting it.
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card mb-4">
                        <div class="card-header bg-info text-white py-2">
                            <h5 class="card-title mb-0"><i class="fas fa-calendar-alt mr-2"></i>Investigation Timeline</h5>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label for="start_date">Start Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="start_date" name="start_date" 
                                       value="<?php echo isset($_POST['start_date']) ? $_POST['start_date'] : date('Y-m-d'); ?>" 
                                       min="<?php echo $incident['incident_date']; ?>" 
                                       max="<?php echo date('Y-m-d'); ?>" required>
                                <small class="form-text text-muted">
                                    Investigation cannot start before the incident date
                                </small>
                            </div>
                            
                            <div class="form-group">
                                <label for="estimated_end_date">Estimated Completion Date</label>
                                <input type="date" class="form-control" id="estimated_end_date" name="estimated_end_date" 
                                       value="<?php echo isset($_POST['estimated_end_date']) ? $_POST['estimated_end_date'] : ''; ?>"
                                       min="<?php echo date('Y-m-d'); ?>">
                                <small class="form-text text-muted">
                                    Target date for completing the investigation (optional)
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="card mb-4">
                <div class="card-header bg-success text-white py-2">
                    <h5 class="card-title mb-0"><i class="fas fa-clipboard-list mr-2"></i>Investigation Plan</h5>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label for="investigation_scope">Investigation Scope & Objectives <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="investigation_scope" name="investigation_scope" rows="4" 
                                  placeholder="Define the scope of the investigation, key questions to answer, and specific objectives..."
                                  required><?php echo isset($_POST['investigation_scope']) ? $_POST['investigation_scope'] : ''; ?></textarea>
                        <small class="form-text text-muted">
                            Clearly define what the investigation will cover and what it aims to achieve
                        </small>
                    </div>
                    
                    <div class="form-group">
                        <label for="methodology">Investigation Methodology</label>
                        <textarea class="form-control" id="methodology" name="methodology" rows="4" 
                                  placeholder="Describe the approach and methods to be used in the investigation (interviews, document review, evidence collection, etc.)..."><?php echo isset($_POST['methodology']) ? $_POST['methodology'] : ''; ?></textarea>
                        <small class="form-text text-muted">
                            Outline the methods and procedures for conducting the investigation
                        </small>
                    </div>
                </div>
            </div>
            
            <div class="row mt-4">
                <div class="col-md-12">
                    <div class="alert alert-info">
                        <h6><i class="fas fa-lightbulb mr-2"></i>Investigation Best Practices</h6>
                        <ul class="mb-0">
                            <li>Maintain confidentiality throughout the investigation process</li>
                            <li>Document all interviews, evidence, and findings thoroughly</li>
                            <li>Follow company policies and legal requirements</li>
                            <li>Ensure fairness and impartiality in the investigation</li>
                            <li>Communicate appropriately with all parties involved</li>
                            <li>Set realistic timelines and manage expectations</li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <div class="form-group mt-4">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-play-circle mr-2"></i>Start Investigation
                </button>
                <a href="view_misconduct_incident.php?id=<?php echo $incident_id; ?>" class="btn btn-secondary">
                    <i class="fas fa-times mr-2"></i>Cancel
                </a>
            </div>
        </form>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.select2').select2();
    
    // Set date constraints
    var incidentDate = '<?php echo $incident['incident_date']; ?>';
    var today = new Date().toISOString().split('T')[0];
    
    $('#start_date').attr('min', incidentDate);
    $('#start_date').attr('max', today);
    
    $('#estimated_end_date').attr('min', today);
    
    // Auto-set estimated end date to 14 days from start if not set
    $('#start_date').change(function() {
        if ($(this).val() && !$('#estimated_end_date').val()) {
            var startDate = new Date($(this).val());
            var estimatedDate = new Date(startDate);
            estimatedDate.setDate(estimatedDate.getDate() + 14); // 2 weeks default
            $('#estimated_end_date').val(estimatedDate.toISOString().split('T')[0]);
        }
    });
});
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
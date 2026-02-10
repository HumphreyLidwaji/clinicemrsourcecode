<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Get visit_id from URL
$visit_id = intval($_GET['visit_id'] ?? 0);

if ($visit_id <= 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid visit ID";
    header("Location: visits.php");
    exit;
}

// Get visit data with patient and user information
$visit_sql = "SELECT v.*, 
                     p.first_name, p.last_name,
                     p.patient_mrn, p.date_of_birth, p.sex, p.phone_primary,
                     d.department_name,
                     u_doctor.user_name as doctor_name,
                     u_created.user_name as created_by_name
              FROM visits v 
              JOIN patients p ON v.patient_id = p.patient_id
              LEFT JOIN departments d ON v.department_id = d.department_id
              LEFT JOIN users u_doctor ON v.attending_provider_id = u_doctor.user_id
              LEFT JOIN users u_created ON v.created_by = u_created.user_id
              WHERE v.visit_id = ? AND p.archived_at IS NULL";
$visit_stmt = $mysqli->prepare($visit_sql);
$visit_stmt->bind_param("i", $visit_id);
$visit_stmt->execute();
$visit_result = $visit_stmt->get_result();
$visit = $visit_result->fetch_assoc();

if (!$visit) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Visit not found";
    header("Location: visits.php");
    exit;
}

// Get today's visit stats
$today_sql = "SELECT 
    COUNT(*) as total_visits,
    SUM(CASE WHEN visit_type = 'OPD' THEN 1 ELSE 0 END) as opd_visits,
    SUM(CASE WHEN visit_type = 'EMERGENCY' THEN 1 ELSE 0 END) as emergency_visits,
    SUM(CASE WHEN visit_type = 'IPD' THEN 1 ELSE 0 END) as ipd_visits
    FROM visits 
    WHERE DATE(visit_datetime) = CURDATE()";
$today_result = $mysqli->query($today_sql);
$today_stats = $today_result->fetch_assoc();
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-calendar-check mr-2"></i>Visit Details: <?php echo htmlspecialchars($visit['visit_number']); ?>
        </h3>
        <div class="card-tools">
            <div class="btn-group">
                <a href="visits.php" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Visits
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

        <!-- Visit Header Actions -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="btn-toolbar justify-content-between">
                    <div class="btn-group">
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Status:</strong> 
                            <?php
                            $status_color = '';
                            switch($visit['visit_status']) {
                                case 'ACTIVE': $status_color = 'success'; break;
                                case 'CLOSED': $status_color = 'secondary'; break;
                                default: $status_color = 'secondary';
                            }
                            ?>
                            <span class="badge badge-<?php echo $status_color; ?> ml-2">
                                <?php echo htmlspecialchars($visit['visit_status']); ?>
                            </span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Type:</strong> 
                            <span class="badge badge-info ml-2"><?php echo htmlspecialchars($visit['visit_type']); ?></span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Today:</strong> 
                            <span class="badge badge-success ml-2"><?php echo date('M j, Y'); ?></span>
                        </span>
                    </div>
                    <div class="btn-group">
                        <?php if (SimplePermission::any("visit_edit") && $visit['visit_status'] != 'CLOSED'): ?>
                            <a href="visit_edit.php?visit_id=<?php echo $visit_id; ?>" class="btn btn-success">
                                <i class="fas fa-edit mr-2"></i>Edit Visit
                            </a>
                        <?php endif; ?>
                        <a href="visit_print.php?visit_id=<?php echo $visit_id; ?>" class="btn btn-primary" target="_blank">
                            <i class="fas fa-print mr-2"></i>Print Summary
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Visit Information -->
            <div class="col-md-8">
                <!-- Visit Details Card -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-info-circle mr-2"></i>Visit Information</h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="table-responsive">
                                    <table class="table table-sm table-borderless">
                                        <tr>
                                            <th width="40%" class="text-muted">Visit Number:</th>
                                            <td><strong class="text-primary"><?php echo htmlspecialchars($visit['visit_number']); ?></strong></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Visit Type:</th>
                                            <td><?php echo htmlspecialchars($visit['visit_type']); ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Visit Date:</th>
                                            <td><?php echo date('M j, Y g:i A', strtotime($visit['visit_datetime'])); ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Facility Code:</th>
                                            <td><strong><?php echo htmlspecialchars($visit['facility_code']); ?></strong></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Department:</th>
                                            <td><?php echo htmlspecialchars($visit['department_name'] ?: 'Not specified'); ?></td>
                                        </tr>
                                        <?php if ($visit['closed_at']): ?>
                                        <tr>
                                            <th class="text-muted">Closed Date:</th>
                                            <td><?php echo date('M j, Y g:i A', strtotime($visit['closed_at'])); ?></td>
                                        </tr>
                                        <?php endif; ?>
                                    </table>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="table-responsive">
                                    <table class="table table-sm table-borderless">
                                        <tr>
                                            <th class="text-muted">Attending Provider:</th>
                                            <td><?php echo htmlspecialchars($visit['doctor_name'] ?: 'Not assigned'); ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Created By:</th>
                                            <td><?php echo htmlspecialchars($visit['created_by_name']); ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Created Date:</th>
                                            <td><?php echo date('M j, Y g:i A', strtotime($visit['created_at'])); ?></td>
                                        </tr>
                                        <?php if ($visit['updated_at'] != $visit['created_at']): ?>
                                        <tr>
                                            <th class="text-muted">Last Updated:</th>
                                            <td><?php echo date('M j, Y g:i A', strtotime($visit['updated_at'])); ?></td>
                                        </tr>
                                        <?php endif; ?>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Patient Information Card -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-user-injured mr-2"></i>Patient Information</h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="table-responsive">
                                    <table class="table table-sm table-borderless">
                                        <?php
                                        $full_name = $visit['first_name'] . ' ' . $visit['last_name'];
                                        
                                        // Calculate age
                                        $age = '';
                                        if (!empty($visit['date_of_birth'])) {
                                            $birthDate = new DateTime($visit['date_of_birth']);
                                            $today = new DateTime();
                                            $age = $today->diff($birthDate)->y . ' years';
                                        }
                                        ?>
                                        <tr>
                                            <th width="40%" class="text-muted">Patient Name:</th>
                                            <td><strong><?php echo htmlspecialchars($full_name); ?></strong></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Medical Record No:</th>
                                            <td><strong class="text-primary"><?php echo htmlspecialchars($visit['patient_mrn']); ?></strong></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Date of Birth:</th>
                                            <td><?php echo !empty($visit['date_of_birth']) ? date('M j, Y', strtotime($visit['date_of_birth'])) : 'Not specified'; ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Age:</th>
                                            <td><?php echo $age ?: 'N/A'; ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Gender:</th>
                                            <td><?php echo htmlspecialchars($visit['sex'] ?: 'Not specified'); ?></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="table-responsive">
                                    <table class="table table-sm table-borderless">
                                        <tr>
                                            <th class="text-muted">Primary Phone:</th>
                                            <td><?php echo htmlspecialchars($visit['phone_primary'] ?: 'Not specified'); ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Patient ID:</th>
                                            <td><small class="text-muted"><?php echo $visit['patient_id']; ?></small></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                
                    </div>
                </div>
            </div>

            <!-- Sidebar Information -->
            <div class="col-md-4">
                <!-- Visit Actions Card -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-bolt mr-2"></i>Visit Actions</h4>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                         
                            
                          
                            
                            <?php if (SimplePermission::any("visit_invoice")): ?>
                                <a href="invoice_visit.php?visit_id=<?php echo $visit_id; ?>" class="btn btn-success btn-block">
                                    <i class="fas fa-edit mr-2"></i>Invoice Visit
                                </a>
                            <?php endif; ?>
                          
                            <?php if (SimplePermission::any("visit_delete")): ?>
                                <button type="button" class="btn btn-danger btn-block" onclick="confirmDeleteVisit(<?php echo $visit_id; ?>)">
                                    <i class="fas fa-trash mr-2"></i>Delete Visit
                                </button>
                            <?php endif; ?>
                        </div>
                        <hr>
                        <div class="small">
                            <p class="mb-2"><strong>Keyboard Shortcuts:</strong></p>
                            <div class="row">
                                <div class="col-6">
                                    <span class="badge badge-light">Ctrl + E</span> Edit
                                </div>
                                <div class="col-6">
                                    <span class="badge badge-light">Ctrl + P</span> Print
                                </div>
                            </div>
                            <div class="row mt-1">
                                <div class="col-6">
                                    <span class="badge badge-light">Ctrl + N</span> New Visit
                                </div>
                                <div class="col-6">
                                    <span class="badge badge-light">Esc</span> Back
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Today's Statistics Card -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-chart-bar mr-2"></i>Today's Statistics</h4>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-6">
                                <div class="stat-box bg-primary-light p-2 rounded mb-2">
                                    <i class="fas fa-hospital fa-lg text-primary mb-1"></i>
                                    <h5 class="mb-0"><?php echo $today_stats['total_visits'] ?? 0; ?></h5>
                                    <small class="text-muted">Total Visits</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="stat-box bg-warning-light p-2 rounded mb-2">
                                    <i class="fas fa-stethoscope fa-lg text-warning mb-1"></i>
                                    <h5 class="mb-0"><?php echo $today_stats['opd_visits'] ?? 0; ?></h5>
                                    <small class="text-muted">OPD Visits</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="stat-box bg-danger-light p-2 rounded">
                                    <i class="fas fa-ambulance fa-lg text-danger mb-1"></i>
                                    <h5 class="mb-0"><?php echo $today_stats['emergency_visits'] ?? 0; ?></h5>
                                    <small class="text-muted">Emergency</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="stat-box bg-success-light p-2 rounded">
                                    <i class="fas fa-procedures fa-lg text-success mb-1"></i>
                                    <h5 class="mb-0"><?php echo $today_stats['ipd_visits'] ?? 0; ?></h5>
                                    <small class="text-muted">IPD Admissions</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Visit Status Card -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-chart-bar mr-2"></i>Visit Status</h4>
                    </div>
                    <div class="card-body">
                        <div class="text-center">
                            <div class="mb-3">
                                <div class="h4 text-<?php echo $status_color; ?>">
                                    <i class="fas fa-<?php echo $visit['visit_status'] == 'ACTIVE' ? 'check-circle' : 'lock'; ?> mr-2"></i>
                                    <?php echo htmlspecialchars($visit['visit_status']); ?>
                                </div>
                                <small class="text-muted">
                                    <?php
                                    $status_desc = '';
                                    switch($visit['visit_status']) {
                                        case 'ACTIVE': 
                                            $status_desc = 'This visit is currently active and ongoing';
                                            break;
                                        case 'CLOSED': 
                                            $status_desc = 'This visit has been completed and closed';
                                            break;
                                    }
                                    echo $status_desc;
                                    ?>
                                </small>
                            </div>
                            
                            <?php if ($visit['closed_at']): ?>
                                <div class="alert alert-secondary">
                                    <i class="fas fa-calendar-times mr-2"></i>
                                    Closed on: <?php echo date('M j, Y H:i', strtotime($visit['closed_at'])); ?>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-success">
                                    <i class="fas fa-calendar-check mr-2"></i>
                                    Visit is currently active
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Form Tips Card -->
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-lightbulb mr-2"></i>Important Notes</h4>
                    </div>
                    <div class="card-body">
                        <ul class="list-unstyled small mb-0">
                            <li class="mb-2">
                                <i class="fas fa-check-circle text-success mr-2"></i>
                                Visit number format: TYPE-FACILITY-YYYY-NNN
                            </li>
                            <li class="mb-2">
                                <i class="fas fa-check-circle text-success mr-2"></i>
                                Active visits can be closed when completed
                            </li>
                            <li class="mb-2">
                                <i class="fas fa-check-circle text-success mr-2"></i>
                                Closed visits can be reopened if needed
                            </li>
                            <li class="mb-2">
                                <i class="fas fa-exclamation-circle text-danger mr-2"></i>
                                Deleting a visit is permanent and cannot be undone
                            </li>
                            <li class="mb-2">
                                <i class="fas fa-check-circle text-success mr-2"></i>
                                Use edit to update visit information
                            </li>
                            <li>
                                <i class="fas fa-check-circle text-success mr-2"></i>
                                Print summary for documentation
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Auto-close alerts after 5 seconds
    setTimeout(function() {
        $('.alert').fadeOut('slow');
    }, 5000);

    // Tooltip initialization
    $('[title]').tooltip();
});

function confirmCloseVisit(visitId) {
    if (confirm('Are you sure you want to close this visit? This will mark it as completed.')) {
        window.location.href = 'post/visit_actions.php?action=close&visit_id=' + visitId;
    }
}

function confirmReopenVisit(visitId) {
    if (confirm('Are you sure you want to reopen this visit?')) {
        window.location.href = 'post/visit_actions.php?action=reopen&visit_id=' + visitId;
    }
}

function confirmDeleteVisit(visitId) {
    if (confirm('Are you sure you want to delete this visit? This action cannot be undone and will permanently remove all visit data.')) {
        window.location.href = 'post/visit_actions.php?action=delete&visit_id=' + visitId;
    }
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + E for edit
    if (e.ctrlKey && e.keyCode === 69) {
        e.preventDefault();
        <?php if (SimplePermission::any("visit_edit") && $visit['visit_status'] != 'CLOSED'): ?>
            window.location.href = 'visit_edit.php?visit_id=<?php echo $visit_id; ?>';
        <?php endif; ?>
    }
    // Ctrl + P for print
    if (e.ctrlKey && e.keyCode === 80) {
        e.preventDefault();
        window.open('visit_print.php?visit_id=<?php echo $visit_id; ?>', '_blank');
    }
    // Ctrl + N for new visit for same patient
    if (e.ctrlKey && e.keyCode === 78) {
        e.preventDefault();
        window.location.href = 'visit_add.php?patient_id=<?php echo $visit['patient_id']; ?>';
    }
    // Escape to go back
    if (e.keyCode === 27) {
        window.location.href = 'visits.php';
    }
});
</script>

<style>
.required:after {
    content: " *";
    color: #dc3545;
}
.preview-icon {
    width: 60px;
    height: 60px;
    background-color: #e3f2fd;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto;
}
.stat-box {
    transition: all 0.3s ease;
}
.stat-box:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}
.bg-primary-light {
    background-color: rgba(13, 110, 253, 0.1);
}
.bg-warning-light {
    background-color: rgba(255, 193, 7, 0.1);
}
.bg-danger-light {
    background-color: rgba(220, 53, 69, 0.1);
}
.bg-success-light {
    background-color: rgba(25, 135, 84, 0.1);
}
.card-title {
    font-size: 1.1rem;
    font-weight: 600;
}
</style>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
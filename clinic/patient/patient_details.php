<?php
// patient_details.php - Patient Details Page
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Get patient_id from URL
$patient_id = intval($_GET['patient_id'] ?? 0);

if ($patient_id <= 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid patient ID";
    header("Location: patients.php");
    exit;
}

// Get patient data
$patient_sql = "SELECT p.*, 
                       u_created.user_name as created_by_name,
                       u_updated.user_name as updated_by_name
                FROM patients p 
                LEFT JOIN users u_created ON p.created_by = u_created.user_id
                LEFT JOIN users u_updated ON p.updated_by = u_updated.user_id
                WHERE p.patient_id = ? AND p.patient_status != 'ARCHIVED'";
$patient_stmt = $mysqli->prepare($patient_sql);
$patient_stmt->bind_param("i", $patient_id);
$patient_stmt->execute();
$patient_result = $patient_stmt->get_result();
$patient = $patient_result->fetch_assoc();

if (!$patient) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Patient not found";
    header("Location: patients.php");
    exit;
}

// Get next of kin
$kin_sql = "SELECT * FROM patient_next_of_kin WHERE patient_id = ?";
$kin_stmt = $mysqli->prepare($kin_sql);
$kin_stmt->bind_param("i", $patient_id);
$kin_stmt->execute();
$kin_result = $kin_stmt->get_result();
$next_of_kin = $kin_result->fetch_assoc();

// Get patient statistics from visits
$stats_sql = "SELECT 
                COUNT(*) as total_visits,
                COUNT(CASE WHEN visit_status = 'CLOSED' THEN 1 END) as closed_visits,
                COUNT(CASE WHEN visit_status = 'ACTIVE' THEN 1 END) as active_visits,
                MIN(visit_datetime) as first_visit,
                MAX(visit_datetime) as last_visit
              FROM visits 
              WHERE patient_id = ?";
$stats_stmt = $mysqli->prepare($stats_sql);
$stats_stmt->bind_param("i", $patient_id);
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();
$stats = $stats_result->fetch_assoc();

// Get patient visit history
$visits_sql = "SELECT v.*, 
                       d.department_name, 
                       u.user_name as provider_name,
                       f.facility_name,
                       ov.visit_category as opd_category,
                       ev.triage_category as emergency_triage,
                       ia.admission_number,
                       ia.admission_type
               FROM visits v 
               LEFT JOIN departments d ON v.department_id = d.department_id
               LEFT JOIN users u ON v.attending_provider_id = u.user_id
               LEFT JOIN facilities f ON v.facility_code = f.facility_internal_code
               LEFT JOIN opd_visits ov ON v.visit_id = ov.visit_id
               LEFT JOIN emergency_visits ev ON v.visit_id = ev.visit_id
               LEFT JOIN ipd_admissions ia ON v.visit_id = ia.visit_id
               WHERE v.patient_id = ?
               ORDER BY v.visit_datetime DESC
               LIMIT 10";
$visits_stmt = $mysqli->prepare($visits_sql);
$visits_stmt->bind_param("i", $patient_id);
$visits_stmt->execute();
$visits_result = $visits_stmt->get_result();

// Get visit types distribution
$visit_types_sql = "SELECT visit_type, COUNT(*) as count
                    FROM visits 
                    WHERE patient_id = ?
                    GROUP BY visit_type
                    ORDER BY count DESC";
$visit_types_stmt = $mysqli->prepare($visit_types_sql);
$visit_types_stmt->bind_param("i", $patient_id);
$visit_types_stmt->execute();
$visit_types_result = $visit_types_stmt->get_result();

// Calculate age
$age = '';
if (!empty($patient['date_of_birth'])) {
    $birthDate = new DateTime($patient['date_of_birth']);
    $today = new DateTime();
    $age = $today->diff($birthDate)->y . ' years';
}

$full_name = $patient['first_name'] . 
            ($patient['middle_name'] ? ' ' . $patient['middle_name'] : '') . 
            ' ' . $patient['last_name'];
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-user-injured mr-2"></i>Patient Details: <?php echo htmlspecialchars($patient['patient_mrn']); ?>
        </h3>
        <div class="card-tools">
            <div class="btn-group">
                <a href="patients.php" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Patients
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

        <!-- Page Header Actions -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="btn-toolbar justify-content-between">
                    <div class="btn-group">
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Patient:</strong> 
                            <span class="badge badge-info ml-2"><?php echo htmlspecialchars($patient['patient_mrn']); ?></span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Status:</strong> 
                            <span class="badge badge-<?php echo $patient['patient_status'] == 'ACTIVE' ? 'success' : 'secondary'; ?> ml-2">
                                <?php echo htmlspecialchars($patient['patient_status']); ?>
                            </span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Age:</strong> 
                            <span class="badge badge-primary ml-2"><?php echo $age ?: 'N/A'; ?></span>
                        </span>
                    </div>
                    <div class="btn-group">
                        <a href="patient_edit.php?patient_id=<?php echo $patient_id; ?>" class="btn btn-success">
                            <i class="fas fa-edit mr-2"></i>Edit Patient
                        </a>
                        <a href="patient_details_print.php?patient_id=<?php echo $patient_id; ?>" class="btn btn-primary" target="_blank">
                            <i class="fas fa-print mr-2"></i>Print
                        </a>
                        <?php if (SimplePermission::any("visit_create")) { ?>
                        <div class="btn-group">
                            <button type="button" class="btn btn-warning dropdown-toggle" data-toggle="dropdown">
                                <i class="fas fa-plus mr-2"></i>New Visit
                            </button>
                            <div class="dropdown-menu">
                                <a class="dropdown-item" href="visit_add.php?patient_id=<?php echo $patient_id; ?>&type=OPD">
                                    <i class="fas fa-stethoscope mr-2"></i>OPD Visit
                                </a>
                                <a class="dropdown-item" href="visit_add.php?patient_id=<?php echo $patient_id; ?>&type=EMERGENCY">
                                    <i class="fas fa-ambulance mr-2"></i>Emergency Visit
                                </a>
                                <a class="dropdown-item" href="visit_add.php?patient_id=<?php echo $patient_id; ?>&type=IPD">
                                    <i class="fas fa-procedures mr-2"></i>IPD Admission
                                </a>
                            </div>
                        </div>
                        <?php } ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Patient Information -->
            <div class="col-md-8">
                <!-- Personal Information Card -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-user mr-2"></i>Personal Information</h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="table-responsive">
                                    <table class="table table-sm table-borderless">
                                        <tr>
                                            <th width="40%" class="text-muted">Full Name:</th>
                                            <td><strong class="text-primary"><?php echo htmlspecialchars($full_name); ?></strong></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Medical Record No:</th>
                                            <td><strong><?php echo htmlspecialchars($patient['patient_mrn']); ?></strong></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Date of Birth:</th>
                                            <td><?php echo !empty($patient['date_of_birth']) ? date('M j, Y', strtotime($patient['date_of_birth'])) : 'Not specified'; ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Age:</th>
                                            <td><span class="badge badge-secondary"><?php echo $age ?: 'N/A'; ?></span></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Sex:</th>
                                            <td><?php 
                                                $sex_display = '';
                                                switch($patient['sex']) {
                                                    case 'M': $sex_display = 'Male'; break;
                                                    case 'F': $sex_display = 'Female'; break;
                                                    case 'I': $sex_display = 'Intersex'; break;
                                                    default: $sex_display = 'Not specified';
                                                }
                                                echo htmlspecialchars($sex_display); 
                                            ?></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="table-responsive">
                                    <table class="table table-sm table-borderless">
                                        <tr>
                                            <th class="text-muted">ID Type:</th>
                                            <td><?php echo htmlspecialchars($patient['id_type'] ?: 'Not specified'); ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">ID Number:</th>
                                            <td><?php echo htmlspecialchars($patient['id_number'] ?: 'Not specified'); ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Primary Phone:</th>
                                            <td><?php echo htmlspecialchars($patient['phone_primary'] ?: 'Not specified'); ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Secondary Phone:</th>
                                            <td><?php echo htmlspecialchars($patient['phone_secondary'] ?: 'Not specified'); ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Email:</th>
                                            <td><?php echo htmlspecialchars($patient['email'] ?: 'Not specified'); ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Blood Group:</th>
                                            <td><?php echo htmlspecialchars($patient['blood_group'] ?: 'Not specified'); ?></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Address Information Card -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-map-marker-alt mr-2"></i>Address Information</h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="table-responsive">
                                    <table class="table table-sm table-borderless">
                                        <tr>
                                            <th width="40%" class="text-muted">County:</th>
                                            <td><?php echo htmlspecialchars($patient['county'] ?: 'Not specified'); ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Sub-County:</th>
                                            <td><?php echo htmlspecialchars($patient['sub_county'] ?: 'Not specified'); ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Ward:</th>
                                            <td><?php echo htmlspecialchars($patient['ward'] ?: 'Not specified'); ?></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="table-responsive">
                                    <table class="table table-sm table-borderless">
                                        <tr>
                                            <th class="text-muted">Village:</th>
                                            <td><?php echo htmlspecialchars($patient['village'] ?: 'Not specified'); ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Postal Code:</th>
                                            <td><?php echo htmlspecialchars($patient['postal_code'] ?: 'Not specified'); ?></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <?php if (!empty($patient['postal_address'])): ?>
                        <div class="mt-3 p-3 bg-light rounded">
                            <strong>Postal Address:</strong><br>
                            <?php echo htmlspecialchars($patient['postal_address']); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Next of Kin Card -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-user-check mr-2"></i>Next of Kin</h4>
                    </div>
                    <div class="card-body">
                        <?php if ($next_of_kin): ?>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="table-responsive">
                                        <table class="table table-sm table-borderless">
                                            <tr>
                                                <th width="40%" class="text-muted">Full Name:</th>
                                                <td><strong><?php echo htmlspecialchars($next_of_kin['full_name']); ?></strong></td>
                                            </tr>
                                            <tr>
                                                <th class="text-muted">Relationship:</th>
                                                <td><?php echo htmlspecialchars($next_of_kin['relationship']); ?></td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="table-responsive">
                                        <table class="table table-sm table-borderless">
                                            <tr>
                                                <th class="text-muted">Phone:</th>
                                                <td><?php echo htmlspecialchars($next_of_kin['phone']); ?></td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info mb-0">
                                <i class="fas fa-info-circle mr-2"></i>No next of kin information available.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Visit Types Distribution -->
                <?php if ($visit_types_result->num_rows > 0): ?>
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-chart-pie mr-2"></i>Visit Types Distribution</h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-8">
                                <canvas id="visitTypesChart" height="200"></canvas>
                            </div>
                            <div class="col-md-4">
                                <div class="list-group">
                                    <?php 
                                    $visit_types_result->data_seek(0);
                                    while ($visit_type = $visit_types_result->fetch_assoc()): 
                                    ?>
                                        <div class="list-group-item d-flex justify-content-between align-items-center">
                                            <span>
                                                <?php 
                                                switch($visit_type['visit_type']) {
                                                    case 'OPD': echo 'OPD Visit'; break;
                                                    case 'IPD': echo 'IPD Admission'; break;
                                                    case 'EMERGENCY': echo 'Emergency Visit'; break;
                                                    default: echo htmlspecialchars($visit_type['visit_type']);
                                                }
                                                ?>
                                            </span>
                                            <span class="badge badge-primary badge-pill"><?php echo $visit_type['count']; ?></span>
                                        </div>
                                    <?php endwhile; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Sidebar Information -->
            <div class="col-md-4">
                <!-- Patient Metadata -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-database mr-2"></i>Patient Metadata</h4>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <th width="40%" class="text-muted">Created By:</th>
                                    <td><?php echo htmlspecialchars($patient['created_by_name']); ?></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Created Date:</th>
                                    <td><?php echo date('M j, Y H:i', strtotime($patient['created_at'])); ?></td>
                                </tr>
                                <?php if (!empty($patient['updated_at']) && $patient['updated_at'] != $patient['created_at']): ?>
                                <tr>
                                    <th class="text-muted">Last Updated By:</th>
                                    <td><?php echo htmlspecialchars($patient['updated_by_name']); ?></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Last Updated:</th>
                                    <td><?php echo date('M j, Y H:i', strtotime($patient['updated_at'])); ?></td>
                                </tr>
                                <?php endif; ?>
                                <?php if ($patient['is_deceased']): ?>
                                <tr>
                                    <th class="text-muted text-danger">Status:</th>
                                    <td class="text-danger">
                                        <i class="fas fa-cross mr-1"></i>Deceased
                                        <?php if (!empty($patient['date_of_death'])): ?>
                                            (<?php echo date('M j, Y', strtotime($patient['date_of_death'])); ?>)
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endif; ?>
                                <?php if ($stats['first_visit']): ?>
                                <tr>
                                    <th class="text-muted">First Visit:</th>
                                    <td><?php echo date('M j, Y', strtotime($stats['first_visit'])); ?></td>
                                </tr>
                                <?php endif; ?>
                                <?php if ($stats['last_visit']): ?>
                                <tr>
                                    <th class="text-muted">Last Visit:</th>
                                    <td><?php echo date('M j, Y', strtotime($stats['last_visit'])); ?></td>
                                </tr>
                                <?php endif; ?>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Recent Visits -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-history mr-2"></i>Recent Visits</h4>
                        <div class="card-tools">
                            <span class="badge badge-secondary"><?php echo $visits_result->num_rows; ?> records</span>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Date</th>
                                        <th>Type</th>
                                        <th>Status</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($visits_result->num_rows > 0): ?>
                                        <?php while ($visit = $visits_result->fetch_assoc()): ?>
                                            <?php
                                            $status_color = $visit['visit_status'] == 'ACTIVE' ? 'success' : 'primary';
                                            $type_badge = '';
                                            switch($visit['visit_type']) {
                                                case 'OPD': $type_badge = 'badge-info'; break;
                                                case 'IPD': $type_badge = 'badge-warning'; break;
                                                case 'EMERGENCY': $type_badge = 'badge-danger'; break;
                                                default: $type_badge = 'badge-secondary';
                                            }
                                            ?>
                                            <tr>
                                                <td>
                                                    <div class="font-weight-bold"><?php echo date('M j', strtotime($visit['visit_datetime'])); ?></div>
                                                    <small class="text-muted"><?php echo date('h:i A', strtotime($visit['visit_datetime'])); ?></small>
                                                </td>
                                                <td>
                                                    <span class="badge <?php echo $type_badge; ?>">
                                                        <?php 
                                                        switch($visit['visit_type']) {
                                                            case 'OPD': echo 'OPD'; break;
                                                            case 'IPD': echo 'IPD'; break;
                                                            case 'EMERGENCY': echo 'ER'; break;
                                                            default: echo htmlspecialchars($visit['visit_type']);
                                                        }
                                                        ?>
                                                    </span>
                                                    <?php if ($visit['visit_type'] == 'EMERGENCY' && $visit['emergency_triage']): ?>
                                                        <br><small class="text-danger"><?php echo $visit['emergency_triage']; ?> Priority</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge badge-<?php echo $status_color; ?>">
                                                        <?php echo $visit['visit_status']; ?>
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <a href="visit_details.php?visit_id=<?php echo $visit['visit_id']; ?>" class="btn btn-sm btn-info" title="View Details">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4" class="text-center py-4">
                                                <i class="fas fa-calendar-times fa-2x text-muted mb-3"></i>
                                                <h5 class="text-muted">No Visits Found</h5>
                                                <p class="text-muted">No visits recorded for this patient yet.</p>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php if ($visits_result->num_rows > 0): ?>
                    <div class="card-footer text-center">
                        <a href="patient_visits.php?patient_id=<?php echo $patient_id; ?>" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-list mr-1"></i>View All Visits
                        </a>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Patient Status -->
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-chart-bar mr-2"></i>Patient Statistics</h4>
                    </div>
                    <div class="card-body">
                        <div class="text-center">
                            <div class="mb-3">
                                <div class="h4 text-<?php echo $patient['patient_status'] == 'ACTIVE' ? 'success' : 'secondary'; ?>">
                                    <i class="fas fa-<?php echo $patient['patient_status'] == 'ACTIVE' ? 'check-circle' : 'user-slash'; ?> mr-2"></i>
                                    <?php echo htmlspecialchars($patient['patient_status']); ?>
                                </div>
                                <small class="text-muted">
                                    <?php echo $patient['patient_status'] == 'ACTIVE' ? 'This patient is active' : 'This patient is archived'; ?>
                                </small>
                            </div>
                            
                            <?php if ($stats['total_visits'] > 0): ?>
                            <div class="progress mb-2" style="height: 20px;">
                                <div class="progress-bar bg-success" style="width: <?php echo ($stats['closed_visits'] / $stats['total_visits']) * 100; ?>%">
                                    <?php echo $stats['closed_visits']; ?> Closed
                                </div>
                                <div class="progress-bar bg-warning" style="width: <?php echo ($stats['active_visits'] / $stats['total_visits']) * 100; ?>%">
                                    <?php echo $stats['active_visits']; ?> Active
                                </div>
                            </div>
                            <small class="text-muted">
                                Completion Rate: <?php echo $stats['total_visits'] > 0 ? round(($stats['closed_visits'] / $stats['total_visits']) * 100, 1) : 0; ?>%
                            </small>
                            <hr>
                            <div class="row text-center">
                                <div class="col-6">
                                    <div class="h4 mb-0"><?php echo $stats['total_visits']; ?></div>
                                    <small class="text-muted">Total Visits</small>
                                </div>
                                <div class="col-6">
                                    <div class="h4 mb-0"><?php echo $stats['active_visits']; ?></div>
                                    <small class="text-muted">Active Visits</small>
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="alert alert-info mb-0">
                                <i class="fas fa-info-circle mr-2"></i>
                                No visits recorded for this patient yet.
                            </div>
                            <?php endif; ?>
                        </div>
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

    // Visit Types Chart
    <?php if ($visit_types_result->num_rows > 0): ?>
    var visitTypesData = {
        labels: [
            <?php 
            $visit_types_result->data_seek(0);
            while ($visit_type = $visit_types_result->fetch_assoc()): 
                switch($visit_type['visit_type']) {
                    case 'OPD': echo "'OPD Visit',"; break;
                    case 'IPD': echo "'IPD Admission',"; break;
                    case 'EMERGENCY': echo "'Emergency Visit',"; break;
                    default: echo "'" . addslashes($visit_type['visit_type']) . "',";
                }
            endwhile; 
            ?>
        ],
        datasets: [{
            data: [
                <?php 
                $visit_types_result->data_seek(0);
                while ($visit_type = $visit_types_result->fetch_assoc()): 
                    echo $visit_type['count'] . ",";
                endwhile; 
                ?>
            ],
            backgroundColor: [
                '#007bff', // OPD - Blue
                '#ffc107', // IPD - Yellow
                '#dc3545', // Emergency - Red
                '#28a745', // Green for others
                '#6c757d', '#17a2b8', '#6610f2', '#e83e8c', '#fd7e14', '#20c997'
            ]
        }]
    };

    var ctx = document.getElementById('visitTypesChart').getContext('2d');
    var visitTypesChart = new Chart(ctx, {
        type: 'doughnut',
        data: visitTypesData,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            legend: {
                display: false
            },
            tooltips: {
                callbacks: {
                    label: function(tooltipItem, data) {
                        var dataset = data.datasets[tooltipItem.datasetIndex];
                        var total = dataset.data.reduce(function(previousValue, currentValue) {
                            return previousValue + currentValue;
                        });
                        var currentValue = dataset.data[tooltipItem.index];
                        var percentage = Math.floor(((currentValue/total) * 100) + 0.5);
                        return data.labels[tooltipItem.index] + ': ' + currentValue + ' (' + percentage + '%)';
                    }
                }
            }
        }
    });
    <?php endif; ?>
});

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + E for edit
    if (e.ctrlKey && e.keyCode === 69) {
        e.preventDefault();
        window.location.href = 'patient_edit.php?patient_id=<?php echo $patient_id; ?>';
    }
    // Ctrl + P for print/PDF
    if (e.ctrlKey && e.keyCode === 80) {
        e.preventDefault();
        window.open('patient_details_print.php?patient_id=<?php echo $patient_id; ?>', '_blank');
    }
    // Ctrl + O for new OPD visit
    if (e.ctrlKey && e.keyCode === 79) {
        e.preventDefault();
        window.location.href = 'visit_add.php?patient_id=<?php echo $patient_id; ?>&type=OPD';
    }
    // Ctrl + E for new Emergency visit
    if (e.ctrlKey && e.keyCode === 69) {
        e.preventDefault();
        window.location.href = 'visit_add.php?patient_id=<?php echo $patient_id; ?>&type=EMERGENCY';
    }
    // Ctrl + I for new IPD admission
    if (e.ctrlKey && e.keyCode === 73) {
        e.preventDefault();
        window.location.href = 'visit_add.php?patient_id=<?php echo $patient_id; ?>&type=IPD';
    }
    // Ctrl + H for visit history
    if (e.ctrlKey && e.keyCode === 72) {
        e.preventDefault();
        window.location.href = 'patient_visits.php?patient_id=<?php echo $patient_id; ?>';
    }
    // Escape to go back
    if (e.keyCode === 27) {
        window.location.href = 'patients.php';
    }
});
</script>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
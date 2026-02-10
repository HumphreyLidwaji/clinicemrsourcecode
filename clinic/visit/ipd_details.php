<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/audit_functions.php';

// Get ipd_admission_id from URL
$ipd_admission_id = intval($_GET['ipd_admission_id'] ?? 0);

if ($ipd_admission_id <= 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid IPD Admission ID";
    header("Location: ipd.php");
    exit;
}

// Get IPD admission data with patient, visit, ward, bed, and user information
$ipd_sql = "SELECT 
    ia.*,
    v.visit_id,
    v.visit_number,
    v.patient_id,
    v.visit_datetime,
    v.admission_datetime as visit_admission_datetime,
    v.department_id as visit_department_id,
    v.attending_provider_id as visit_attending_provider_id,
    v.facility_code,
    p.first_name,
    p.last_name,
    p.patient_mrn,
    p.date_of_birth,
    p.sex,
    p.phone_primary,
    d.department_name,
    w.ward_name,
    w.ward_type,
    b.bed_number,
    b.bed_type,
    b.bed_occupied,
    u_admit.user_name as admitting_provider_name,
    u_attend.user_name as attending_provider_name,
    u_nurse.user_name as nurse_incharge_name,
    u_created.user_name as created_by_name
FROM ipd_admissions ia
LEFT JOIN visits v ON ia.visit_id = v.visit_id
LEFT JOIN patients p ON v.patient_id = p.patient_id
LEFT JOIN departments d ON ia.department_id = d.department_id
LEFT JOIN wards w ON ia.ward_id = w.ward_id
LEFT JOIN beds b ON ia.bed_id = b.bed_id
LEFT JOIN users u_admit ON ia.admitting_provider_id = u_admit.user_id
LEFT JOIN users u_attend ON ia.attending_provider_id = u_attend.user_id
LEFT JOIN users u_nurse ON ia.nurse_incharge_id = u_nurse.user_id
LEFT JOIN users u_created ON ia.created_by = u_created.user_id
WHERE ia.ipd_admission_id = ? AND p.archived_at IS NULL";
$ipd_stmt = $mysqli->prepare($ipd_sql);
$ipd_stmt->bind_param("i", $ipd_admission_id);
$ipd_stmt->execute();
$ipd_result = $ipd_stmt->get_result();
$ipd_admission = $ipd_result->fetch_assoc();

if (!$ipd_admission) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "IPD Admission not found";
    header("Location: ipd.php");
    exit;
}

// Get today's IPD stats
$today_ipd_sql = "SELECT COUNT(*) as count FROM ipd_admissions WHERE DATE(created_at) = CURDATE()";
$today_ipd_result = $mysqli->query($today_ipd_sql);
$today_ipd_admissions = $today_ipd_result->fetch_assoc()['count'];

// Get active IPD admissions
$active_ipd_sql = "SELECT COUNT(*) as count FROM ipd_admissions WHERE admission_status = 'ACTIVE'";
$active_ipd_result = $mysqli->query($active_ipd_sql);
$active_ipd_admissions = $active_ipd_result->fetch_assoc()['count'];

// Get available beds count
$available_beds_sql = "SELECT COUNT(*) as count FROM beds WHERE bed_occupied = 0 AND bed_archived_at IS NULL";
$available_beds_result = $mysqli->query($available_beds_sql);
$available_beds = $available_beds_result->fetch_assoc()['count'];

// Get admission type breakdown for today
$emergency_admissions_sql = "SELECT COUNT(*) as count FROM ipd_admissions 
                            WHERE DATE(created_at) = CURDATE() 
                            AND admission_type = 'EMERGENCY'";
$emergency_admissions_result = $mysqli->query($emergency_admissions_sql);
$emergency_admissions = $emergency_admissions_result->fetch_assoc()['count'];

$elective_admissions_sql = "SELECT COUNT(*) as count FROM ipd_admissions 
                           WHERE DATE(created_at) = CURDATE() 
                           AND admission_type = 'ELECTIVE'";
$elective_admissions_result = $mysqli->query($elective_admissions_sql);
$elective_admissions = $elective_admissions_result->fetch_assoc()['count'];

$referral_admissions_sql = "SELECT COUNT(*) as count FROM ipd_admissions 
                           WHERE DATE(created_at) = CURDATE() 
                           AND admission_type = 'REFERRAL'";
$referral_admissions_result = $mysqli->query($referral_admissions_sql);
$referral_admissions = $referral_admissions_result->fetch_assoc()['count'];

// Admission type mapping
$admission_types = [
    'EMERGENCY' => 'Emergency',
    'ELECTIVE' => 'Elective',
    'REFERRAL' => 'Referral'
];

// Status mapping with colors
$status_colors = [
    'ACTIVE' => 'success',
    'DISCHARGED' => 'info',
    'TRANSFERRED' => 'warning',
    'CANCELLED' => 'danger',
    'ABSCONDED' => 'dark'
];

// Calculate patient age
$age = '';
if (!empty($ipd_admission['date_of_birth'])) {
    $birthDate = new DateTime($ipd_admission['date_of_birth']);
    $today = new DateTime();
    $age = $today->diff($birthDate)->y . ' years';
}

$patient_name = $ipd_admission['first_name'] . ' ' . $ipd_admission['last_name'];
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-procedures mr-2"></i>IPD Admission Details: <?php echo htmlspecialchars($ipd_admission['admission_number']); ?>
        </h3>
        <div class="card-tools">
            <div class="btn-group">
                <a href="ipd.php" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to IPD Admissions
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

        <!-- IPD Header Actions -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="btn-toolbar justify-content-between">
                    <div class="btn-group">
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Status:</strong> 
                            <?php
                            $status_color = $status_colors[$ipd_admission['admission_status']] ?? 'secondary';
                            ?>
                            <span class="badge badge-<?php echo $status_color; ?> ml-2">
                                <?php echo htmlspecialchars($ipd_admission['admission_status']); ?>
                            </span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Type:</strong> 
                            <span class="badge badge-info ml-2">
                                <?php echo htmlspecialchars($admission_types[$ipd_admission['admission_type']] ?? $ipd_admission['admission_type']); ?>
                            </span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Today:</strong> 
                            <span class="badge badge-success ml-2"><?php echo date('M j, Y'); ?></span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>IPD Today:</strong> 
                            <span class="badge badge-primary ml-2"><?php echo $today_ipd_admissions; ?></span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Active IPD:</strong> 
                            <span class="badge badge-warning ml-2"><?php echo $active_ipd_admissions; ?></span>
                        </span>
                    </div>
                    <div class="btn-group">
                        <?php if (SimplePermission::any("ipd_admission_edit") && $ipd_admission['admission_status'] == 'ACTIVE'): ?>
                            <a href="ipd_edit.php?ipd_admission_id=<?php echo $ipd_admission_id; ?>" class="btn btn-success">
                                <i class="fas fa-edit mr-2"></i>Edit Admission
                            </a>
                        <?php endif; ?>
                     
                        <a href="ipd_print.php?ipd_admission_id=<?php echo $ipd_admission_id; ?>" class="btn btn-primary" target="_blank">
                            <i class="fas fa-print mr-2"></i>Print Summary
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- IPD Admission Information -->
            <div class="col-md-8">
                <!-- Admission Details Card -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-info-circle mr-2"></i>Admission Information</h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="table-responsive">
                                    <table class="table table-sm table-borderless">
                                        <tr>
                                            <th width="40%" class="text-muted">Admission Number:</th>
                                            <td><strong class="text-primary"><?php echo htmlspecialchars($ipd_admission['admission_number']); ?></strong></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Visit Number:</th>
                                            <td>
                                                <a href="visit_details.php?visit_id=<?php echo $ipd_admission['visit_id']; ?>" class="text-info">
                                                    <?php echo htmlspecialchars($ipd_admission['visit_number']); ?>
                                                </a>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Admission Date:</th>
                                            <td><?php echo date('M j, Y g:i A', strtotime($ipd_admission['admission_datetime'])); ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Admission Type:</th>
                                            <td><?php echo htmlspecialchars($admission_types[$ipd_admission['admission_type']] ?? $ipd_admission['admission_type']); ?></td>
                                        </tr>
                                        <?php if ($ipd_admission['referred_from']): ?>
                                        <tr>
                                            <th class="text-muted">Referred From:</th>
                                            <td><?php echo htmlspecialchars($ipd_admission['referred_from']); ?></td>
                                        </tr>
                                        <?php endif; ?>
                                        <?php if ($ipd_admission['discharge_datetime']): ?>
                                        <tr>
                                            <th class="text-muted">Discharge Date:</th>
                                            <td><?php echo date('M j, Y g:i A', strtotime($ipd_admission['discharge_datetime'])); ?></td>
                                        </tr>
                                        <?php endif; ?>
                                    </table>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="table-responsive">
                                    <table class="table table-sm table-borderless">
                                        <tr>
                                            <th class="text-muted">Department:</th>
                                            <td><?php echo htmlspecialchars($ipd_admission['department_name'] ?: 'Not specified'); ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Ward:</th>
                                            <td><?php echo htmlspecialchars($ipd_admission['ward_name'] ?: 'Not assigned'); ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Bed:</th>
                                            <td>
                                                <?php echo htmlspecialchars($ipd_admission['bed_number'] ?: 'Not assigned'); ?>
                                                <?php if ($ipd_admission['bed_type']): ?>
                                                    <small class="text-muted">(<?php echo htmlspecialchars($ipd_admission['bed_type']); ?>)</small>
                                                <?php endif; ?>
                                                <?php if ($ipd_admission['bed_occupied'] == 1): ?>
                                                    <span class="badge badge-danger ml-2">Occupied</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Facility Code:</th>
                                            <td><strong><?php echo htmlspecialchars($ipd_admission['facility_code']); ?></strong></td>
                                        </tr>
                                        <?php if ($ipd_admission['created_at']): ?>
                                        <tr>
                                            <th class="text-muted">Created Date:</th>
                                            <td><?php echo date('M j, Y g:i A', strtotime($ipd_admission['created_at'])); ?></td>
                                        </tr>
                                        <?php endif; ?>
                                        <?php if ($ipd_admission['updated_at'] != $ipd_admission['created_at']): ?>
                                        <tr>
                                            <th class="text-muted">Last Updated:</th>
                                            <td><?php echo date('M j, Y g:i A', strtotime($ipd_admission['updated_at'])); ?></td>
                                        </tr>
                                        <?php endif; ?>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Medical Team Card -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-user-md mr-2"></i>Medical Team</h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="text-center">
                                    <div class="mb-2">
                                        <i class="fas fa-user-md fa-2x text-primary"></i>
                                    </div>
                                    <h6>Admitting Doctor</h6>
                                    <p class="mb-1">
                                        <strong><?php echo htmlspecialchars($ipd_admission['admitting_provider_name'] ?: 'Not assigned'); ?></strong>
                                    </p>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-center">
                                    <div class="mb-2">
                                        <i class="fas fa-stethoscope fa-2x text-success"></i>
                                    </div>
                                    <h6>Attending Doctor</h6>
                                    <p class="mb-1">
                                        <strong><?php echo htmlspecialchars($ipd_admission['attending_provider_name'] ?: 'Not assigned'); ?></strong>
                                    </p>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-center">
                                    <div class="mb-2">
                                        <i class="fas fa-user-nurse fa-2x text-info"></i>
                                    </div>
                                    <h6>Nurse Incharge</h6>
                                    <p class="mb-1">
                                        <strong><?php echo htmlspecialchars($ipd_admission['nurse_incharge_name'] ?: 'Not assigned'); ?></strong>
                                    </p>
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
                                        <tr>
                                            <th width="40%" class="text-muted">Patient Name:</th>
                                            <td><strong><?php echo htmlspecialchars($patient_name); ?></strong></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Medical Record No:</th>
                                            <td><strong class="text-primary"><?php echo htmlspecialchars($ipd_admission['patient_mrn']); ?></strong></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Date of Birth:</th>
                                            <td><?php echo !empty($ipd_admission['date_of_birth']) ? date('M j, Y', strtotime($ipd_admission['date_of_birth'])) : 'Not specified'; ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Age:</th>
                                            <td><?php echo $age ?: 'N/A'; ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Gender:</th>
                                            <td><?php echo htmlspecialchars($ipd_admission['sex'] ?: 'Not specified'); ?></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="table-responsive">
                                    <table class="table table-sm table-borderless">
                                        <tr>
                                            <th class="text-muted">Primary Phone:</th>
                                            <td><?php echo htmlspecialchars($ipd_admission['phone_primary'] ?: 'Not specified'); ?></td>
                                        </tr>
                                      
                                        <tr>
                                            <th class="text-muted">Patient ID:</th>
                                            <td><small class="text-muted"><?php echo $ipd_admission['patient_id']; ?></small></td>
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
              

                <!-- Today's IPD Statistics Card -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-chart-bar mr-2"></i>Today's IPD Statistics</h4>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-6">
                                <div class="stat-box bg-primary-light p-2 rounded mb-2">
                                    <i class="fas fa-procedures fa-lg text-primary mb-1"></i>
                                    <h5 class="mb-0"><?php echo $today_ipd_admissions; ?></h5>
                                    <small class="text-muted">IPD Today</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="stat-box bg-warning-light p-2 rounded mb-2">
                                    <i class="fas fa-user-injured fa-lg text-warning mb-1"></i>
                                    <h5 class="mb-0"><?php echo $active_ipd_admissions; ?></h5>
                                    <small class="text-muted">Active IPD</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="stat-box bg-danger-light p-2 rounded">
                                    <i class="fas fa-bed fa-lg text-danger mb-1"></i>
                                    <h5 class="mb-0"><?php echo $available_beds; ?></h5>
                                    <small class="text-muted">Available Beds</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="stat-box bg-success-light p-2 rounded">
                                    <i class="fas fa-hospital fa-lg text-success mb-1"></i>
                                    <h5 class="mb-0"><?php echo $emergency_admissions; ?></h5>
                                    <small class="text-muted">Emergency</small>
                                </div>
                            </div>
                        </div>
                        <div class="mt-3 small">
                            <div class="d-flex justify-content-between">
                                <span>Emergency:</span>
                                <span class="font-weight-bold"><?php echo $emergency_admissions; ?></span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span>Elective:</span>
                                <span class="font-weight-bold"><?php echo $elective_admissions; ?></span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span>Referral:</span>
                                <span class="font-weight-bold"><?php echo $referral_admissions; ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Admission Status Card -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-chart-bar mr-2"></i>Admission Status</h4>
                    </div>
                    <div class="card-body">
                        <div class="text-center">
                            <div class="mb-3">
                                <div class="h4 text-<?php echo $status_color; ?>">
                                    <i class="fas fa-<?php 
                                        switch($ipd_admission['admission_status']) {
                                            case 'ACTIVE': echo 'check-circle'; break;
                                            case 'DISCHARGED': echo 'sign-out-alt'; break;
                                            case 'TRANSFERRED': echo 'exchange-alt'; break;
                                            case 'CANCELLED': echo 'times-circle'; break;
                                            case 'ABSCONDED': echo 'running'; break;
                                            default: echo 'circle';
                                        }
                                    ?> mr-2"></i>
                                    <?php echo htmlspecialchars($ipd_admission['admission_status']); ?>
                                </div>
                                <small class="text-muted">
                                    <?php
                                    $status_desc = '';
                                    switch($ipd_admission['admission_status']) {
                                        case 'ACTIVE': 
                                            $status_desc = 'Patient is currently admitted';
                                            break;
                                        case 'DISCHARGED': 
                                            $status_desc = 'Patient has been discharged';
                                            break;
                                        case 'TRANSFERRED': 
                                            $status_desc = 'Patient has been transferred to another facility';
                                            break;
                                        case 'CANCELLED': 
                                            $status_desc = 'Admission was cancelled';
                                            break;
                                        case 'ABSCONDED': 
                                            $status_desc = 'Patient absconded from hospital';
                                            break;
                                    }
                                    echo $status_desc;
                                    ?>
                                </small>
                            </div>
                            
                            <?php if ($ipd_admission['admission_status'] == 'DISCHARGED' && $ipd_admission['discharge_datetime']): ?>
                                <div class="alert alert-info">
                                    <i class="fas fa-calendar-times mr-2"></i>
                                    Discharged on: <?php echo date('M j, Y H:i', strtotime($ipd_admission['discharge_datetime'])); ?>
                                </div>
                            <?php elseif ($ipd_admission['admission_status'] == 'ACTIVE'): ?>
                                <div class="alert alert-success">
                                    <i class="fas fa-bed mr-2"></i>
                                    Patient is currently admitted in <?php echo htmlspecialchars($ipd_admission['ward_name']); ?>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($ipd_admission['bed_occupied'] == 1 && $ipd_admission['admission_status'] == 'ACTIVE'): ?>
                                <div class="alert alert-warning">
                                    <i class="fas fa-bed mr-2"></i>
                                    Bed <?php echo htmlspecialchars($ipd_admission['bed_number']); ?> is occupied
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Important Notes Card -->
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-lightbulb mr-2"></i>Important Notes</h4>
                    </div>
                    <div class="card-body">
                        <ul class="list-unstyled small mb-0">
                            <li class="mb-2">
                                <i class="fas fa-check-circle text-success mr-2"></i>
                                Admission number format: IPD-YYYY-NNN
                            </li>
                            <li class="mb-2">
                                <i class="fas fa-check-circle text-success mr-2"></i>
                                Only active admissions can be edited
                            </li>
                            <li class="mb-2">
                                <i class="fas fa-check-circle text-success mr-2"></i>
                                Discharge patient when treatment is complete
                            </li>
                            <li class="mb-2">
                                <i class="fas fa-exclamation-circle text-danger mr-2"></i>
                                Cancelling admission will free the bed
                            </li>
                            <li class="mb-2">
                                <i class="fas fa-check-circle text-success mr-2"></i>
                                Create bill for services rendered
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

function confirmCancelAdmission(admissionId) {
    if (confirm('Are you sure you want to cancel this admission? This will free the bed and mark the admission as cancelled. This action cannot be undone.')) {
        window.location.href = 'post/ipd_actions.php?action=cancel&ipd_admission_id=' + admissionId;
    }
}

function transferPatient() {
    alert('Transfer functionality coming soon.');
    // window.location.href = 'ipd_transfer.php?ipd_admission_id=<?php echo $ipd_admission_id; ?>';
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + E for edit
    if (e.ctrlKey && e.keyCode === 69) {
        e.preventDefault();
        <?php if (SimplePermission::any("ipd_admission_edit") && $ipd_admission['admission_status'] == 'ACTIVE'): ?>
            window.location.href = 'ipd_edit.php?ipd_admission_id=<?php echo $ipd_admission_id; ?>';
        <?php endif; ?>
    }
    // Ctrl + D for discharge
    if (e.ctrlKey && e.keyCode === 68) {
        e.preventDefault();
        <?php if ($ipd_admission['admission_status'] == 'ACTIVE' && SimplePermission::any("ipd_discharge")): ?>
            window.location.href = 'ipd_discharge.php?ipd_admission_id=<?php echo $ipd_admission_id; ?>';
        <?php endif; ?>
    }
    // Ctrl + P for print
    if (e.ctrlKey && e.keyCode === 80) {
        e.preventDefault();
        window.open('ipd_print.php?ipd_admission_id=<?php echo $ipd_admission_id; ?>', '_blank');
    }
    // Ctrl + B for billing
    if (e.ctrlKey && e.keyCode === 66) {
        e.preventDefault();
        <?php if (SimplePermission::any("billing_create")): ?>
            window.location.href = '/clinic/billing/billing_add.php?patient_id=<?php echo $ipd_admission['patient_id']; ?>&visit_id=<?php echo $ipd_admission['visit_id']; ?>';
        <?php endif; ?>
    }
    // Escape to go back
    if (e.keyCode === 27) {
        window.location.href = 'ipd.php';
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
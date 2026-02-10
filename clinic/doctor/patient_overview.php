<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Get visit_id from URL
$visit_id = intval($_GET['visit_id'] ?? 0);

if ($visit_id <= 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid visit ID";
    header("Location: /clinic/dashboard.php");
    exit;
}

// Check if user has view permissions
// Assuming you have a permission check system
$can_edit = SimplePermission::any("doctor_notes_edit"); // Or your permission check
$is_readonly = !$can_edit; // Set to read-only if user cannot edit

// Initialize variables
$patient_info = null;
$visit_info = null;
$doctor_notes = [];
$doctor_rounds = [];
$nurse_rounds = [];
$nurse_daily_notes = [];
$vitals = [];
$prescriptions = [];
$lab_orders = [];
$radiology_orders = [];
$consultations = [];
$visit_type = '';
$today = date('Y-m-d');
$now = date('Y-m-d H:i:s');

// Get visit and patient information
$visit_sql = "SELECT v.*, 
                     p.patient_id, p.first_name, p.last_name, 
                     p.patient_mrn, p.sex as patient_gender, p.date_of_birth as patient_dob,
                     p.phone_primary as patient_phone, p.blood_group,
                     p.county, p.sub_county, p.ward, p.village, p.postal_address,
                     d.department_name,
                     doctor.user_name as doctor_name,
                     doc.user_id as doctor_id,
                     v.attending_provider_id
              FROM visits v 
              JOIN patients p ON v.patient_id = p.patient_id
              JOIN departments d ON v.department_id = d.department_id
              LEFT JOIN users doctor ON v.attending_provider_id = doctor.user_id
              LEFT JOIN users doc ON v.attending_provider_id = doc.user_id
              WHERE v.visit_id = ?";
$visit_stmt = $mysqli->prepare($visit_sql);
$visit_stmt->bind_param("i", $visit_id);
$visit_stmt->execute();
$visit_result = $visit_stmt->get_result();

if ($visit_result->num_rows === 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Visit not found";
    header("Location: /clinic/dashboard.php");
    exit;
}

$visit_info = $visit_result->fetch_assoc();
$patient_info = [
    'patient_id' => $visit_info['patient_id'],
    'first_name' => $visit_info['first_name'],
    'last_name' => $visit_info['last_name'],
    'patient_mrn' => $visit_info['patient_mrn'],
    'patient_gender' => $visit_info['patient_gender'],
    'patient_dob' => $visit_info['patient_dob'],
    'patient_phone' => $visit_info['patient_phone'],
    'blood_group' => $visit_info['blood_group'],
    'county' => $visit_info['county'],
    'sub_county' => $visit_info['sub_county'],
    'ward' => $visit_info['ward'],
    'village' => $visit_info['village'],
    'postal_address' => $visit_info['postal_address']
];

$visit_type = $visit_info['visit_type'];
$visit_status = $visit_info['visit_status'];

// Check for IPD admission
$is_ipd = false;
$ipd_info = null;
if ($visit_type == 'IPD') {
    $ipd_sql = "SELECT * FROM ipd_admissions WHERE visit_id = ?";
    $ipd_stmt = $mysqli->prepare($ipd_sql);
    $ipd_stmt->bind_param("i", $visit_id);
    $ipd_stmt->execute();
    $ipd_result = $ipd_stmt->get_result();
    if ($ipd_result->num_rows > 0) {
        $is_ipd = true;
        $ipd_info = $ipd_result->fetch_assoc();
    }
}

// Get doctor notes
$notes_sql = "SELECT dn.*, 
                     u.user_name as recorded_by_name,
                     u.user_id as recorded_by_id
              FROM doctor_notes dn
              JOIN users u ON dn.recorded_by = u.user_id
              WHERE dn.visit_id = ?
              ORDER BY dn.note_date DESC, dn.recorded_at DESC";
$notes_stmt = $mysqli->prepare($notes_sql);
$notes_stmt->bind_param("i", $visit_id);
$notes_stmt->execute();
$notes_result = $notes_stmt->get_result();
$doctor_notes = $notes_result->fetch_all(MYSQLI_ASSOC);

// Get doctor rounds (for IPD)
if ($is_ipd && $ipd_info) {
    $rounds_sql = "SELECT dr.*, 
                          u.user_name as doctor_name,
                          u2.user_name as verified_by_name
                   FROM doctor_rounds dr
                   LEFT JOIN users u ON dr.doctor_id = u.user_id
                   LEFT JOIN users u2 ON dr.verified_by = u2.user_id
                   WHERE dr.visit_id = ? 
                   ORDER BY dr.round_datetime DESC, dr.created_at DESC";
    $rounds_stmt = $mysqli->prepare($rounds_sql);
    $rounds_stmt->bind_param("i", $visit_id);
    $rounds_stmt->execute();
    $rounds_result = $rounds_stmt->get_result();
    $doctor_rounds = $rounds_result->fetch_all(MYSQLI_ASSOC);
}

// Get nurse rounds (for IPD)
if ($is_ipd && $ipd_info) {
    $nurse_rounds_sql = "SELECT nr.*, 
                                u.user_name as nurse_name,
                                u2.user_name as verified_by_name
                         FROM nurse_rounds nr
                         LEFT JOIN users u ON nr.nurse_id = u.user_id
                         LEFT JOIN users u2 ON nr.verified_by = u2.user_id
                         WHERE nr.visit_id = ? 
                         ORDER BY nr.round_datetime DESC, nr.created_at DESC";
    $nurse_rounds_stmt = $mysqli->prepare($nurse_rounds_sql);
    $nurse_rounds_stmt->bind_param("i", $visit_id);
    $nurse_rounds_stmt->execute();
    $nurse_rounds_result = $nurse_rounds_stmt->get_result();
    $nurse_rounds = $nurse_rounds_result->fetch_all(MYSQLI_ASSOC);
}

// Get nurse daily notes
$nurse_notes_sql = "SELECT ndn.*, 
                           u.user_name as nurse_name,
                           u2.user_name as finalized_by_name
                    FROM nurse_daily_notes ndn
                    LEFT JOIN users u ON ndn.recorded_by = u.user_id
                    LEFT JOIN users u2 ON ndn.finalized_by = u2.user_id
                    WHERE ndn.visit_id = ? 
                    ORDER BY ndn.note_date DESC, ndn.recorded_at DESC";
$nurse_notes_stmt = $mysqli->prepare($nurse_notes_sql);
$nurse_notes_stmt->bind_param("i", $visit_id);
$nurse_notes_stmt->execute();
$nurse_notes_result = $nurse_notes_stmt->get_result();
$nurse_daily_notes = $nurse_notes_result->fetch_all(MYSQLI_ASSOC);

// Get vitals
$vitals_sql = "SELECT * FROM vitals 
               WHERE visit_id = ? 
               ORDER BY recorded_at DESC, created_at DESC";
$vitals_stmt = $mysqli->prepare($vitals_sql);
$vitals_stmt->bind_param("i", $visit_id);
$vitals_stmt->execute();
$vitals_result = $vitals_stmt->get_result();
$vitals = $vitals_result->fetch_all(MYSQLI_ASSOC);

// Get prescriptions
$prescriptions_sql = "SELECT p.*, 
                             u.user_name as doctor_name,
                             u2.user_name as dispensed_by_name
                      FROM prescriptions p
                      LEFT JOIN users u ON p.prescription_doctor_id = u.user_id
                      LEFT JOIN users u2 ON p.prescription_dispensed_by = u2.user_id
                      WHERE p.prescription_visit_id = ? 
                      ORDER BY p.prescription_date DESC";
$prescriptions_stmt = $mysqli->prepare($prescriptions_sql);
$prescriptions_stmt->bind_param("i", $visit_id);
$prescriptions_stmt->execute();
$prescriptions_result = $prescriptions_stmt->get_result();
$prescriptions = $prescriptions_result->fetch_all(MYSQLI_ASSOC);

// Get lab orders
$lab_orders_sql = "SELECT lo.*, 
                          u.user_name as doctor_name,
                          u2.user_name as created_by_name
                   FROM lab_orders lo
                   LEFT JOIN users u ON lo.ordering_doctor_id = u.user_id
                   LEFT JOIN users u2 ON lo.created_by = u2.user_id
                   WHERE lo.visit_id = ? 
                   ORDER BY lo.order_date DESC";
$lab_orders_stmt = $mysqli->prepare($lab_orders_sql);
$lab_orders_stmt->bind_param("i", $visit_id);
$lab_orders_stmt->execute();
$lab_orders_result = $lab_orders_stmt->get_result();
$lab_orders = $lab_orders_result->fetch_all(MYSQLI_ASSOC);

// Get radiology orders
$radiology_sql = "SELECT ro.*, 
                         u.user_name as doctor_name,
                         u2.user_name as radiologist_name
                  FROM radiology_orders ro
                  LEFT JOIN users u ON ro.ordered_by = u.user_id
                  LEFT JOIN users u2 ON ro.radiologist_id = u2.user_id
                  WHERE ro.visit_id = ? 
                  ORDER BY ro.order_date DESC";
$radiology_stmt = $mysqli->prepare($radiology_sql);
$radiology_stmt->bind_param("i", $visit_id);
$radiology_stmt->execute();
$radiology_result = $radiology_stmt->get_result();
$radiology_orders = $radiology_result->fetch_all(MYSQLI_ASSOC);

// Get consultations
$consultations_sql = "SELECT dc.*, 
                             u.user_name as doctor_name
                      FROM doctor_consultations dc
                      LEFT JOIN users u ON dc.recorded_by = u.user_id
                      WHERE dc.visit_id = ? 
                      ORDER BY dc.consultation_date DESC, dc.consultation_time DESC";
$consultations_stmt = $mysqli->prepare($consultations_sql);
$consultations_stmt->bind_param("i", $visit_id);
$consultations_stmt->execute();
$consultations_result = $consultations_stmt->get_result();
$consultations = $consultations_result->fetch_all(MYSQLI_ASSOC);

// Get today's doctor note
$todays_note = null;
foreach ($doctor_notes as $note) {
    if ($note['note_date'] == $today) {
        $todays_note = $note;
        break;
    }
}

// Get patient full name
$full_name = $patient_info['first_name'] . ' ' . $patient_info['last_name'];

// Calculate age
$age = '';
if (!empty($patient_info['patient_dob'])) {
    $birthDate = new DateTime($patient_info['patient_dob']);
    $today_date = new DateTime();
    $age = $today_date->diff($birthDate)->y . ' years';
}

// Get visit number
$visit_number = $visit_info['visit_number'];

// Function to get note type badge
function getNoteTypeBadge($type) {
    switch($type) {
        case 'progress':
            return '<span class="badge badge-info"><i class="fas fa-clipboard-list mr-1"></i>Progress</span>';
        case 'consultation':
            return '<span class="badge badge-warning"><i class="fas fa-stethoscope mr-1"></i>Consultation</span>';
        case 'procedure':
            return '<span class="badge badge-danger"><i class="fas fa-procedures mr-1"></i>Procedure</span>';
        case 'follow_up':
            return '<span class="badge badge-secondary"><i class="fas fa-calendar-check mr-1"></i>Follow-up</span>';
        case 'discharge':
            return '<span class="badge badge-success"><i class="fas fa-sign-out-alt mr-1"></i>Discharge</span>';
        default:
            return '<span class="badge badge-secondary">' . $type . '</span>';
    }
}

// Function to get status badge
function getStatusBadge($status) {
    switch($status) {
        case 'finalized':
        case 'COMPLETED':
        case 'dispensed':
        case 'Completed':
            return '<span class="badge badge-success"><i class="fas fa-check-circle mr-1"></i>' . ucfirst($status) . '</span>';
        case 'draft':
        case 'PENDING':
        case 'Pending':
        case 'pending':
            return '<span class="badge badge-warning"><i class="fas fa-edit mr-1"></i>' . ucfirst($status) . '</span>';
        case 'cancelled':
        case 'Cancelled':
        case 'CANCELLED':
            return '<span class="badge badge-danger"><i class="fas fa-times-circle mr-1"></i>' . ucfirst($status) . '</span>';
        case 'active':
            return '<span class="badge badge-primary"><i class="fas fa-play-circle mr-1"></i>Active</span>';
        case 'partial':
            return '<span class="badge badge-info"><i class="fas fa-adjust mr-1"></i>Partial</span>';
        case 'In Progress':
            return '<span class="badge badge-info"><i class="fas fa-spinner mr-1"></i>In Progress</span>';
        case 'Scheduled':
            return '<span class="badge badge-primary"><i class="fas fa-calendar mr-1"></i>Scheduled</span>';
        default:
            return '<span class="badge badge-secondary">' . ucfirst($status) . '</span>';
    }
}

// Function to get priority badge
function getPriorityBadge($priority) {
    switch(strtolower($priority)) {
        case 'urgent':
        case 'emergency':
            return '<span class="badge badge-danger"><i class="fas fa-exclamation-triangle mr-1"></i>' . ucfirst($priority) . '</span>';
        case 'stat':
            return '<span class="badge badge-danger"><i class="fas fa-bolt mr-1"></i>STAT</span>';
        case 'routine':
            return '<span class="badge badge-info"><i class="fas fa-clock mr-1"></i>Routine</span>';
        default:
            return '<span class="badge badge-secondary">' . ucfirst($priority) . '</span>';
    }
}

// Function to get round type badge
function getRoundTypeBadge($type) {
    switch($type) {
        case 'MORNING':
            return '<span class="badge badge-info"><i class="fas fa-sun mr-1"></i>Morning</span>';
        case 'EVENING':
            return '<span class="badge badge-warning"><i class="fas fa-moon mr-1"></i>Evening</span>';
        case 'NIGHT':
            return '<span class="badge badge-dark"><i class="fas fa-star mr-1"></i>Night</span>';
        case 'SPECIAL':
            return '<span class="badge badge-danger"><i class="fas fa-user-md mr-1"></i>Special</span>';
        case 'OTHER':
            return '<span class="badge badge-secondary"><i class="fas fa-ellipsis-h mr-1"></i>Other</span>';
        default:
            return '<span class="badge badge-secondary">' . $type . '</span>';
    }
}

// Function to get shift badge
function getShiftBadge($shift) {
    switch(strtolower($shift)) {
        case 'morning':
            return '<span class="badge badge-info"><i class="fas fa-sun mr-1"></i>Morning</span>';
        case 'evening':
            return '<span class="badge badge-warning"><i class="fas fa-moon mr-1"></i>Evening</span>';
        case 'night':
            return '<span class="badge badge-dark"><i class="fas fa-star mr-1"></i>Night</span>';
        default:
            return '<span class="badge badge-secondary">' . ucfirst($shift) . '</span>';
    }
}
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h3 class="card-title mt-2 mb-0 text-white">
                    <i class="fas fa-fw fa-file-medical-alt mr-2"></i>
                    Clinical Documentation - Read Only View
                    <?php if ($is_readonly): ?>
                        <span class="badge badge-warning ml-2"><i class="fas fa-eye mr-1"></i>Read Only</span>
                    <?php endif; ?>
                </h3>
                <small class="text-white">
                    Visit #<?php echo htmlspecialchars($visit_number); ?> | 
                    Patient: <?php echo htmlspecialchars($full_name); ?>
                    <?php if ($is_ipd && $ipd_info): ?>
                        | IPD Admission: <?php echo htmlspecialchars($ipd_info['admission_number']); ?>
                    <?php endif; ?>
                </small>
            </div>
            <div class="card-tools">
                <div class="btn-group">
                    <a href="/clinic/dashboard.php" class="btn btn-light">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
                    </a>
                    <?php if (!$is_readonly): ?>
                        <a href="/clinic/doctor/doctor_notes.php?visit_id=<?php echo $visit_id; ?>" 
                           class="btn btn-warning ml-2">
                            <i class="fas fa-edit mr-2"></i>Edit Mode
                        </a>
                    <?php endif; ?>
                    <button type="button" class="btn btn-info ml-2" onclick="window.print()">
                        <i class="fas fa-print mr-2"></i>Print Summary
                    </button>
                </div>
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

        <!-- Patient and Visit Info -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card bg-light">
                    <div class="card-body py-2">
                        <div class="row">
                            <div class="col-md-8">
                                <div class="row">
                                    <div class="col-md-3">
                                        <table class="table table-sm table-borderless mb-0">
                                            <tr>
                                                <th width="40%" class="text-muted">Patient:</th>
                                                <td><strong><?php echo htmlspecialchars($full_name); ?></strong></td>
                                            </tr>
                                            <tr>
                                                <th class="text-muted">MRN:</th>
                                                <td><span class="badge badge-info"><?php echo htmlspecialchars($patient_info['patient_mrn']); ?></span></td>
                                            </tr>
                                            <tr>
                                                <th class="text-muted">Age/Sex:</th>
                                                <td>
                                                    <span class="badge badge-secondary">
                                                        <?php echo $age ?: 'N/A'; ?> / <?php echo htmlspecialchars($patient_info['patient_gender']); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        </table>
                                    </div>
                                    <div class="col-md-3">
                                        <table class="table table-sm table-borderless mb-0">
                                            <tr>
                                                <th class="text-muted">Blood Group:</th>
                                                <td>
                                                    <?php if ($patient_info['blood_group']): ?>
                                                        <span class="badge badge-danger"><?php echo htmlspecialchars($patient_info['blood_group']); ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">Not recorded</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th class="text-muted">Visit Date:</th>
                                                <td><?php echo date('M j, Y H:i', strtotime($visit_info['visit_datetime'])); ?></td>
                                            </tr>
                                            <tr>
                                                <th class="text-muted">Visit Type:</th>
                                                <td>
                                                    <span class="badge badge-<?php 
                                                        echo $visit_type == 'OPD' ? 'primary' : 
                                                             ($visit_type == 'IPD' ? 'success' : 
                                                             ($visit_type == 'EMERGENCY' ? 'danger' : 'secondary')); 
                                                    ?>">
                                                        <?php echo htmlspecialchars($visit_type); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        </table>
                                    </div>
                                    <div class="col-md-3">
                                        <table class="table table-sm table-borderless mb-0">
                                            <tr>
                                                <th class="text-muted">Status:</th>
                                                <td>
                                                    <span class="badge badge-<?php 
                                                        echo $visit_status == 'ACTIVE' ? 'warning' : 
                                                             ($visit_status == 'CLOSED' ? 'success' : 
                                                             ($visit_status == 'CANCELLED' ? 'danger' : 'secondary')); 
                                                    ?>">
                                                        <?php echo htmlspecialchars($visit_status); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th class="text-muted">Department:</th>
                                                <td><?php echo htmlspecialchars($visit_info['department_name']); ?></td>
                                            </tr>
                                            <tr>
                                                <th class="text-muted">Attending:</th>
                                                <td><?php echo htmlspecialchars($visit_info['doctor_name'] ?: 'Not assigned'); ?></td>
                                            </tr>
                                        </table>
                                    </div>
                                    <div class="col-md-3">
                                        <table class="table table-sm table-borderless mb-0">
                                            <tr>
                                                <th class="text-muted">Contact:</th>
                                                <td><?php echo htmlspecialchars($patient_info['patient_phone']); ?></td>
                                            </tr>
                                            <tr>
                                                <th class="text-muted">Address:</th>
                                                <td>
                                                    <?php 
                                                    $address_parts = [];
                                                    if ($patient_info['village']) $address_parts[] = $patient_info['village'];
                                                    if ($patient_info['ward']) $address_parts[] = $patient_info['ward'];
                                                    if ($patient_info['sub_county']) $address_parts[] = $patient_info['sub_county'];
                                                    if ($patient_info['county']) $address_parts[] = $patient_info['county'];
                                                    echo htmlspecialchars(implode(', ', $address_parts));
                                                    ?>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th class="text-muted">Postal:</th>
                                                <td><?php echo htmlspecialchars($patient_info['postal_address'] ?: 'N/A'); ?></td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 text-right">
                                <div class="mt-2">
                                    <span class="h5">
                                        <i class="fas fa-file-medical text-primary mr-1"></i>
                                        <span class="badge badge-light"><?php echo count($doctor_notes); ?> Notes</span>
                                    </span>
                                    <br>
                                    <span class="h6">
                                        <i class="fas fa-user-md text-success mr-1"></i>
                                        <span class="badge badge-light">Dr. <?php echo $session_name; ?></span>
                                    </span>
                                    <br>
                                    <span class="h6">
                                        <i class="fas fa-calendar-day text-info mr-1"></i>
                                        <span class="badge badge-light"><?php echo date('F j, Y'); ?></span>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <?php if ($is_ipd && $ipd_info): ?>
                        <div class="row mt-2">
                            <div class="col-md-12">
                                <div class="alert alert-success p-2 mb-0">
                                    <i class="fas fa-procedures mr-2"></i>
                                    <strong>IPD Admission:</strong> 
                                    <?php echo htmlspecialchars($ipd_info['admission_number']); ?> | 
                                    <strong>Admitted:</strong> 
                                    <?php echo date('M j, Y H:i', strtotime($ipd_info['admission_datetime'])); ?> | 
                                    <strong>Status:</strong> 
                                    <span class="badge badge-<?php echo $ipd_info['admission_status'] == 'ACTIVE' ? 'warning' : 'success'; ?>">
                                        <?php echo htmlspecialchars($ipd_info['admission_status']); ?>
                                    </span>
                                    <?php if ($ipd_info['ward_id']): ?>
                                        | <strong>Ward:</strong> <?php echo htmlspecialchars($ipd_info['ward_id']); ?>
                                    <?php endif; ?>
                                    <?php if ($ipd_info['bed_id']): ?>
                                        | <strong>Bed:</strong> <?php echo htmlspecialchars($ipd_info['bed_id']); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Summary Statistics -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-secondary py-2">
                        <h5 class="card-title mb-0 text-white">
                            <i class="fas fa-chart-bar mr-2"></i>Visit Summary Statistics
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-md-2 col-6 mb-3">
                                <div class="bg-primary text-white rounded p-3">
                                    <i class="fas fa-file-medical fa-2x mb-2"></i>
                                    <div class="h4 mb-0"><?php echo count($doctor_notes); ?></div>
                                    <small>Doctor Notes</small>
                                </div>
                            </div>
                            <div class="col-md-2 col-6 mb-3">
                                <div class="bg-info text-white rounded p-3">
                                    <i class="fas fa-prescription fa-2x mb-2"></i>
                                    <div class="h4 mb-0"><?php echo count($prescriptions); ?></div>
                                    <small>Prescriptions</small>
                                </div>
                            </div>
                            <div class="col-md-2 col-6 mb-3">
                                <div class="bg-success text-white rounded p-3">
                                    <i class="fas fa-heartbeat fa-2x mb-2"></i>
                                    <div class="h4 mb-0"><?php echo count($vitals); ?></div>
                                    <small>Vital Records</small>
                                </div>
                            </div>
                            <div class="col-md-2 col-6 mb-3">
                                <div class="bg-warning text-white rounded p-3">
                                    <i class="fas fa-flask fa-2x mb-2"></i>
                                    <div class="h4 mb-0"><?php echo count($lab_orders); ?></div>
                                    <small>Lab Orders</small>
                                </div>
                            </div>
                            <div class="col-md-2 col-6 mb-3">
                                <div class="bg-danger text-white rounded p-3">
                                    <i class="fas fa-x-ray fa-2x mb-2"></i>
                                    <div class="h4 mb-0"><?php echo count($radiology_orders); ?></div>
                                    <small>Radiology Orders</small>
                                </div>
                            </div>
                            <?php if ($is_ipd): ?>
                            <div class="col-md-2 col-6 mb-3">
                                <div class="bg-dark text-white rounded p-3">
                                    <i class="fas fa-procedures fa-2x mb-2"></i>
                                    <div class="h4 mb-0"><?php echo count($doctor_rounds) + count($nurse_rounds); ?></div>
                                    <small>Rounds</small>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Navigation Tabs -->
        <div class="row mb-4">
            <div class="col-md-12">
                <ul class="nav nav-tabs" id="clinicalTabs" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link active" id="doctor-notes-tab" data-toggle="tab" href="#doctor-notes" role="tab">
                            <i class="fas fa-file-medical-alt mr-1"></i>Doctor Notes (<?php echo count($doctor_notes); ?>)
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="vitals-tab" data-toggle="tab" href="#vitals" role="tab">
                            <i class="fas fa-heartbeat mr-1"></i>Vitals (<?php echo count($vitals); ?>)
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="prescriptions-tab" data-toggle="tab" href="#prescriptions" role="tab">
                            <i class="fas fa-prescription mr-1"></i>Prescriptions (<?php echo count($prescriptions); ?>)
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="lab-orders-tab" data-toggle="tab" href="#lab-orders" role="tab">
                            <i class="fas fa-flask mr-1"></i>Lab Orders (<?php echo count($lab_orders); ?>)
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="radiology-tab" data-toggle="tab" href="#radiology" role="tab">
                            <i class="fas fa-x-ray mr-1"></i>Radiology (<?php echo count($radiology_orders); ?>)
                        </a>
                    </li>
                    <?php if ($is_ipd): ?>
                    <li class="nav-item">
                        <a class="nav-link" id="doctor-rounds-tab" data-toggle="tab" href="#doctor-rounds" role="tab">
                            <i class="fas fa-user-md mr-1"></i>Doctor Rounds (<?php echo count($doctor_rounds); ?>)
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="nurse-notes-tab" data-toggle="tab" href="#nurse-notes" role="tab">
                            <i class="fas fa-user-nurse mr-1"></i>Nurse Notes (<?php echo count($nurse_daily_notes) + count($nurse_rounds); ?>)
                        </a>
                    </li>
                    <?php endif; ?>
                    <li class="nav-item">
                        <a class="nav-link" id="consultations-tab" data-toggle="tab" href="#consultations" role="tab">
                            <i class="fas fa-stethoscope mr-1"></i>Consultations (<?php echo count($consultations); ?>)
                        </a>
                    </li>
                </ul>
            </div>
        </div>

        <div class="tab-content" id="clinicalTabsContent">
            <!-- Doctor Notes Tab -->
            <div class="tab-pane fade show active" id="doctor-notes" role="tabpanel">
                <div class="card">
                    <div class="card-header bg-info py-2">
                        <h4 class="card-title mb-0 text-white">
                            <i class="fas fa-file-medical-alt mr-2"></i>Doctor Notes History
                            <span class="badge badge-light float-right"><?php echo count($doctor_notes); ?> notes</span>
                        </h4>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($doctor_notes)): ?>
                            <div class="accordion" id="notesAccordion">
                                <?php 
                                $current_date = null;
                                foreach ($doctor_notes as $index => $note): 
                                    $note_date = new DateTime($note['note_date']);
                                    $recorded_at = new DateTime($note['recorded_at']);
                                    $is_today = ($note['note_date'] == $today);
                                    
                                    if ($current_date != $note['note_date']) {
                                        $current_date = $note['note_date'];
                                        $date_display = $note_date->format('F j, Y');
                                        if ($is_today) {
                                            $date_display = '<strong>Today</strong> - ' . $note_date->format('F j, Y');
                                        }
                                ?>
                                    <div class="mb-2">
                                        <h5 class="text-primary">
                                            <i class="fas fa-calendar-day mr-2"></i><?php echo $date_display; ?>
                                        </h5>
                                    </div>
                                <?php } ?>
                                
                                <div class="card mb-2 <?php echo $is_today ? 'border-left-info' : ''; ?>">
                                    <div class="card-header bg-light py-2" id="heading<?php echo $index; ?>">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h6 class="mb-0">
                                                    <?php echo getNoteTypeBadge($note['note_type']); ?>
                                                    <?php echo getStatusBadge($note['status']); ?>
                                                    <button class="btn btn-link text-dark" type="button" data-toggle="collapse" 
                                                            data-target="#collapse<?php echo $index; ?>" aria-expanded="false" 
                                                            aria-controls="collapse<?php echo $index; ?>">
                                                        <i class="fas fa-chevron-down mr-1"></i>View Details
                                                    </button>
                                                </h6>
                                                <small class="text-muted">
                                                    <i class="fas fa-user-md mr-1"></i><?php echo htmlspecialchars($note['recorded_by_name']); ?>
                                                    | <i class="fas fa-clock mr-1"></i><?php echo $recorded_at->format('H:i'); ?>
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div id="collapse<?php echo $index; ?>" class="collapse" aria-labelledby="heading<?php echo $index; ?>" data-parent="#notesAccordion">
                                        <div class="card-body">
                                            <?php if ($note['subjective']): ?>
                                                <div class="mb-3">
                                                    <h6 class="text-primary"><i class="fas fa-user mr-2"></i>Subjective (S)</h6>
                                                    <div class="p-3 bg-light rounded"><?php echo nl2br(htmlspecialchars($note['subjective'])); ?></div>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if ($note['objective']): ?>
                                                <div class="mb-3">
                                                    <h6 class="text-success"><i class="fas fa-stethoscope mr-2"></i>Objective (O)</h6>
                                                    <div class="p-3 bg-light rounded"><?php echo nl2br(htmlspecialchars($note['objective'])); ?></div>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if ($note['assessment']): ?>
                                                <div class="mb-3">
                                                    <h6 class="text-warning"><i class="fas fa-diagnoses mr-2"></i>Assessment (A)</h6>
                                                    <div class="p-3 bg-light rounded"><?php echo nl2br(htmlspecialchars($note['assessment'])); ?></div>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if ($note['plan']): ?>
                                                <div class="mb-3">
                                                    <h6 class="text-info"><i class="fas fa-tasks mr-2"></i>Plan (P)</h6>
                                                    <div class="p-3 bg-light rounded"><?php echo nl2br(htmlspecialchars($note['plan'])); ?></div>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if ($note['clinical_notes']): ?>
                                                <div class="mb-3">
                                                    <h6 class="text-secondary"><i class="fas fa-notes-medical mr-2"></i>Clinical Notes</h6>
                                                    <div class="p-3 bg-light rounded"><?php echo nl2br(htmlspecialchars($note['clinical_notes'])); ?></div>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if ($note['recommendations']): ?>
                                                <div class="mb-3">
                                                    <h6 class="text-dark"><i class="fas fa-lightbulb mr-2"></i>Recommendations</h6>
                                                    <div class="p-3 bg-light rounded"><?php echo nl2br(htmlspecialchars($note['recommendations'])); ?></div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-file-medical fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">No Doctor Notes</h5>
                                <p class="text-muted">No doctor notes have been recorded for this visit.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Vitals Tab -->
            <div class="tab-pane fade" id="vitals" role="tabpanel">
                <div class="card">
                    <div class="card-header bg-success py-2">
                        <h4 class="card-title mb-0 text-white">
                            <i class="fas fa-heartbeat mr-2"></i>Vital Signs History
                            <span class="badge badge-light float-right"><?php echo count($vitals); ?> records</span>
                        </h4>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($vitals)): ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-bordered">
                                    <thead class="thead-dark">
                                        <tr>
                                            <th>Date & Time</th>
                                            <th>Temp (°C)</th>
                                            <th>Pulse (BPM)</th>
                                            <th>BP (mmHg)</th>
                                            <th>RR</th>
                                            <th>SpO2 (%)</th>
                                            <th>Weight (kg)</th>
                                            <th>Height (cm)</th>
                                            <th>BMI</th>
                                            <th>Pain (0-10)</th>
                                            <th>Remarks</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($vitals as $vital): 
                                            $recorded = new DateTime($vital['recorded_at']);
                                            $bmi = $vital['bmi'] ? number_format($vital['bmi'], 1) : null;
                                        ?>
                                            <tr>
                                                <td><?php echo $recorded->format('M j, Y H:i'); ?></td>
                                                <td class="text-center"><?php echo $vital['temperature'] ? htmlspecialchars($vital['temperature']) : '-'; ?></td>
                                                <td class="text-center"><?php echo $vital['pulse'] ?: '-'; ?></td>
                                                <td class="text-center">
                                                    <?php if ($vital['blood_pressure_systolic'] && $vital['blood_pressure_diastolic']): ?>
                                                        <span class="badge badge-<?php 
                                                            $systolic = $vital['blood_pressure_systolic'];
                                                            echo ($systolic < 90) ? 'info' : 
                                                                 (($systolic >= 90 && $systolic < 120) ? 'success' : 
                                                                 (($systolic >= 120 && $systolic < 140) ? 'warning' : 'danger'));
                                                        ?>">
                                                            <?php echo htmlspecialchars($vital['blood_pressure_systolic'] . '/' . $vital['blood_pressure_diastolic']); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center"><?php echo $vital['respiration_rate'] ?: '-'; ?></td>
                                                <td class="text-center">
                                                    <?php if ($vital['oxygen_saturation']): ?>
                                                        <span class="badge badge-<?php 
                                                            $spo2 = $vital['oxygen_saturation'];
                                                            echo ($spo2 >= 95) ? 'success' : 
                                                                 (($spo2 >= 90 && $spo2 < 95) ? 'warning' : 'danger');
                                                        ?>">
                                                            <?php echo htmlspecialchars($vital['oxygen_saturation']); ?>%
                                                        </span>
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center"><?php echo $vital['weight'] ? htmlspecialchars($vital['weight']) : '-'; ?></td>
                                                <td class="text-center"><?php echo $vital['height'] ? htmlspecialchars($vital['height']) : '-'; ?></td>
                                                <td class="text-center">
                                                    <?php if ($bmi): ?>
                                                        <span class="badge badge-<?php 
                                                            echo ($bmi < 18.5) ? 'info' : 
                                                                 (($bmi >= 18.5 && $bmi < 25) ? 'success' : 
                                                                 (($bmi >= 25 && $bmi < 30) ? 'warning' : 'danger'));
                                                        ?>">
                                                            <?php echo $bmi; ?>
                                                        </span>
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($vital['pain_score'] !== null): ?>
                                                        <span class="badge badge-<?php 
                                                            $pain = $vital['pain_score'];
                                                            echo ($pain == 0) ? 'success' : 
                                                                 (($pain <= 3) ? 'info' : 
                                                                 (($pain <= 7) ? 'warning' : 'danger'));
                                                        ?>">
                                                            <?php echo htmlspecialchars($vital['pain_score']); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo $vital['remarks'] ? htmlspecialchars($vital['remarks']) : '-'; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Latest Vitals Summary -->
                            <?php 
                            $latest_vital = $vitals[0];
                            if ($latest_vital):
                            ?>
                            <div class="card mt-4">
                                <div class="card-header bg-warning py-2">
                                    <h5 class="card-title mb-0 text-white">
                                        <i class="fas fa-chart-line mr-2"></i>Latest Vital Signs Summary
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="row text-center">
                                        <?php if ($latest_vital['temperature']): ?>
                                        <div class="col-md-2 col-6 mb-3">
                                            <div class="bg-danger text-white rounded p-3">
                                                <div class="h4 mb-0"><?php echo htmlspecialchars($latest_vital['temperature']); ?>°C</div>
                                                <small>Temperature</small>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($latest_vital['pulse']): ?>
                                        <div class="col-md-2 col-6 mb-3">
                                            <div class="bg-primary text-white rounded p-3">
                                                <div class="h4 mb-0"><?php echo htmlspecialchars($latest_vital['pulse']); ?></div>
                                                <small>Pulse (BPM)</small>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($latest_vital['blood_pressure_systolic'] && $latest_vital['blood_pressure_diastolic']): ?>
                                        <div class="col-md-2 col-6 mb-3">
                                            <div class="bg-info text-white rounded p-3">
                                                <div class="h4 mb-0"><?php echo htmlspecialchars($latest_vital['blood_pressure_systolic'] . '/' . $latest_vital['blood_pressure_diastolic']); ?></div>
                                                <small>Blood Pressure</small>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($latest_vital['oxygen_saturation']): ?>
                                        <div class="col-md-2 col-6 mb-3">
                                            <div class="bg-success text-white rounded p-3">
                                                <div class="h4 mb-0"><?php echo htmlspecialchars($latest_vital['oxygen_saturation']); ?>%</div>
                                                <small>SpO2</small>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($latest_vital['respiration_rate']): ?>
                                        <div class="col-md-2 col-6 mb-3">
                                            <div class="bg-warning text-white rounded p-3">
                                                <div class="h4 mb-0"><?php echo htmlspecialchars($latest_vital['respiration_rate']); ?></div>
                                                <small>Respiration Rate</small>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($latest_vital['pain_score'] !== null): ?>
                                        <div class="col-md-2 col-6 mb-3">
                                            <div class="bg-secondary text-white rounded p-3">
                                                <div class="h4 mb-0"><?php echo htmlspecialchars($latest_vital['pain_score']); ?>/10</div>
                                                <small>Pain Score</small>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($latest_vital['weight'] && $latest_vital['height'] && $latest_vital['bmi']): ?>
                                    <div class="row text-center mt-2">
                                        <div class="col-md-4">
                                            <div class="bg-light rounded p-2">
                                                <div class="h5 mb-0"><?php echo htmlspecialchars($latest_vital['weight']); ?> kg</div>
                                                <small class="text-muted">Weight</small>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="bg-light rounded p-2">
                                                <div class="h5 mb-0"><?php echo htmlspecialchars($latest_vital['height']); ?> cm</div>
                                                <small class="text-muted">Height</small>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="bg-light rounded p-2">
                                                <div class="h5 mb-0"><?php echo number_format($latest_vital['bmi'], 1); ?></div>
                                                <small class="text-muted">BMI</small>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-heartbeat fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">No Vital Signs</h5>
                                <p class="text-muted">No vital signs have been recorded for this visit.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Prescriptions Tab -->
            <div class="tab-pane fade" id="prescriptions" role="tabpanel">
                <div class="card">
                    <div class="card-header bg-warning py-2">
                        <h4 class="card-title mb-0 text-white">
                            <i class="fas fa-prescription mr-2"></i>Prescriptions
                            <span class="badge badge-light float-right"><?php echo count($prescriptions); ?> prescriptions</span>
                        </h4>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($prescriptions)): ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-bordered">
                                    <thead class="thead-dark">
                                        <tr>
                                            <th>Medication</th>
                                            <th>Dosage</th>
                                            <th>Frequency</th>
                                            <th>Duration</th>
                                            <th>Priority</th>
                                            <th>Type</th>
                                            <th>Status</th>
                                            <th>Refills</th>
                                            <th>Doctor</th>
                                            <th>Date</th>
                                            <th>Instructions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($prescriptions as $prescription): 
                                            $prescription_date = new DateTime($prescription['prescription_date']);
                                            $is_active = in_array($prescription['prescription_status'], ['pending', 'active', 'partial']);
                                        ?>
                                            <tr class="<?php echo $is_active ? 'table-success' : ''; ?>">
                                                <td><strong><?php echo htmlspecialchars($prescription['prescription_medication']); ?></strong></td>
                                                <td><?php echo htmlspecialchars($prescription['prescription_dosage']); ?></td>
                                                <td><?php echo htmlspecialchars($prescription['prescription_frequency']); ?></td>
                                                <td><?php echo htmlspecialchars($prescription['prescription_duration']); ?></td>
                                                <td><?php echo getPriorityBadge($prescription['prescription_priority']); ?></td>
                                                <td><?php echo htmlspecialchars($prescription['prescription_type']); ?></td>
                                                <td><?php echo getStatusBadge($prescription['prescription_status']); ?></td>
                                                <td class="text-center"><?php echo $prescription['prescription_refills'] > 0 ? $prescription['prescription_refills'] : '-'; ?></td>
                                                <td><?php echo htmlspecialchars($prescription['doctor_name']); ?></td>
                                                <td><?php echo $prescription_date->format('M j, Y H:i'); ?></td>
                                                <td>
                                                    <?php if ($prescription['prescription_instructions']): ?>
                                                        <small><?php echo htmlspecialchars($prescription['prescription_instructions']); ?></small>
                                                    <?php else: ?>
                                                        <span class="text-muted">None</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php if ($prescription['prescription_notes']): ?>
                                                <tr class="<?php echo $is_active ? 'table-success' : ''; ?>">
                                                    <td colspan="11" class="bg-light">
                                                        <small class="text-muted">
                                                            <i class="fas fa-sticky-note mr-1"></i>
                                                            <strong>Notes:</strong> <?php echo htmlspecialchars($prescription['prescription_notes']); ?>
                                                        </small>
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Prescription Summary -->
                            <div class="row mt-4">
                                <div class="col-md-4">
                                    <div class="card bg-light">
                                        <div class="card-body text-center">
                                            <h5 class="text-success">
                                                <?php 
                                                $active = array_filter($prescriptions, function($p) { 
                                                    return in_array($p['prescription_status'], ['pending', 'active']); 
                                                });
                                                echo count($active);
                                                ?>
                                            </h5>
                                            <small class="text-muted">Active Prescriptions</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="card bg-light">
                                        <div class="card-body text-center">
                                            <h5 class="text-primary">
                                                <?php 
                                                $dispensed = array_filter($prescriptions, function($p) { 
                                                    return $p['prescription_status'] == 'dispensed'; 
                                                });
                                                echo count($dispensed);
                                                ?>
                                            </h5>
                                            <small class="text-muted">Dispensed</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="card bg-light">
                                        <div class="card-body text-center">
                                            <h5 class="text-warning">
                                                <?php 
                                                $urgent = array_filter($prescriptions, function($p) { 
                                                    return in_array(strtolower($p['prescription_priority']), ['urgent', 'emergency', 'stat']); 
                                                });
                                                echo count($urgent);
                                                ?>
                                            </h5>
                                            <small class="text-muted">Urgent Priority</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-prescription-bottle-alt fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">No Prescriptions</h5>
                                <p class="text-muted">No prescriptions have been created for this visit.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Lab Orders Tab -->
            <div class="tab-pane fade" id="lab-orders" role="tabpanel">
                <div class="card">
                    <div class="card-header bg-info py-2">
                        <h4 class="card-title mb-0 text-white">
                            <i class="fas fa-flask mr-2"></i>Laboratory Orders
                            <span class="badge badge-light float-right"><?php echo count($lab_orders); ?> orders</span>
                        </h4>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($lab_orders)): ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-bordered">
                                    <thead class="thead-dark">
                                        <tr>
                                            <th>Order #</th>
                                            <th>Order Date</th>
                                            <th>Priority</th>
                                            <th>Specimen Type</th>
                                            <th>Test Type</th>
                                            <th>Status</th>
                                            <th>Ordered By</th>
                                            <th>Clinical Notes</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($lab_orders as $order): 
                                            $order_date = new DateTime($order['order_date']);
                                        ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($order['order_number']); ?></strong></td>
                                                <td><?php echo $order_date->format('M j, Y H:i'); ?></td>
                                                <td><?php echo getPriorityBadge($order['order_priority']); ?></td>
                                                <td><?php echo htmlspecialchars($order['specimen_type'] ?: 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($order['lab_order_type'] ?: 'Routine'); ?></td>
                                                <td><?php echo getStatusBadge($order['lab_order_status']); ?></td>
                                                <td><?php echo htmlspecialchars($order['doctor_name']); ?></td>
                                                <td>
                                                    <?php if ($order['clinical_notes']): ?>
                                                        <small><?php echo htmlspecialchars($order['clinical_notes']); ?></small>
                                                    <?php else: ?>
                                                        <span class="text-muted">None</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php if ($order['instructions']): ?>
                                                <tr>
                                                    <td colspan="8" class="bg-light">
                                                        <small class="text-muted">
                                                            <i class="fas fa-info-circle mr-1"></i>
                                                            <strong>Instructions:</strong> <?php echo htmlspecialchars($order['instructions']); ?>
                                                        </small>
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Lab Orders Summary -->
                            <div class="row mt-4">
                                <?php 
                                $lab_status_counts = [];
                                foreach ($lab_orders as $order) {
                                    $status = $order['lab_order_status'];
                                    if (!isset($lab_status_counts[$status])) {
                                        $lab_status_counts[$status] = 0;
                                    }
                                    $lab_status_counts[$status]++;
                                }
                                ?>
                                <?php foreach ($lab_status_counts as $status => $count): ?>
                                    <div class="col-md-3 col-6 mb-3">
                                        <div class="card bg-light">
                                            <div class="card-body text-center">
                                                <h5 class="text-<?php 
                                                    echo $status == 'Completed' ? 'success' : 
                                                         ($status == 'Pending' ? 'warning' : 
                                                         ($status == 'In Progress' ? 'info' : 'secondary'));
                                                ?>"><?php echo $count; ?></h5>
                                                <small class="text-muted"><?php echo htmlspecialchars($status); ?></small>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-flask fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">No Laboratory Orders</h5>
                                <p class="text-muted">No laboratory orders have been placed for this visit.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Radiology Tab -->
            <div class="tab-pane fade" id="radiology" role="tabpanel">
                <div class="card">
                    <div class="card-header bg-danger py-2">
                        <h4 class="card-title mb-0 text-white">
                            <i class="fas fa-x-ray mr-2"></i>Radiology Orders
                            <span class="badge badge-light float-right"><?php echo count($radiology_orders); ?> orders</span>
                        </h4>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($radiology_orders)): ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-bordered">
                                    <thead class="thead-dark">
                                        <tr>
                                            <th>Order #</th>
                                            <th>Order Date</th>
                                            <th>Study Type</th>
                                            <th>Body Part</th>
                                            <th>Priority</th>
                                            <th>Status</th>
                                            <th>Ordered By</th>
                                            <th>Radiologist</th>
                                            <th>Clinical Notes</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($radiology_orders as $order): 
                                            $order_date = new DateTime($order['order_date']);
                                        ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($order['order_number']); ?></strong></td>
                                                <td><?php echo $order_date->format('M j, Y H:i'); ?></td>
                                                <td><?php echo htmlspecialchars($order['order_type']); ?></td>
                                                <td><?php echo htmlspecialchars($order['body_part'] ?: 'N/A'); ?></td>
                                                <td><?php echo getPriorityBadge($order['order_priority']); ?></td>
                                                <td><?php echo getStatusBadge($order['order_status']); ?></td>
                                                <td><?php echo htmlspecialchars($order['doctor_name']); ?></td>
                                                <td><?php echo htmlspecialchars($order['radiologist_name'] ?: 'Not assigned'); ?></td>
                                                <td>
                                                    <?php if ($order['clinical_notes']): ?>
                                                        <small><?php echo htmlspecialchars($order['clinical_notes']); ?></small>
                                                    <?php else: ?>
                                                        <span class="text-muted">None</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php if ($order['instructions'] || $order['pre_procedure_instructions']): ?>
                                                <tr>
                                                    <td colspan="9" class="bg-light">
                                                        <?php if ($order['instructions']): ?>
                                                            <small class="text-muted">
                                                                <i class="fas fa-info-circle mr-1"></i>
                                                                <strong>Instructions:</strong> <?php echo htmlspecialchars($order['instructions']); ?>
                                                            </small><br>
                                                        <?php endif; ?>
                                                        <?php if ($order['pre_procedure_instructions']): ?>
                                                            <small class="text-muted">
                                                                <i class="fas fa-exclamation-triangle mr-1"></i>
                                                                <strong>Pre-procedure Instructions:</strong> <?php echo htmlspecialchars($order['pre_procedure_instructions']); ?>
                                                            </small>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Radiology Orders Summary -->
                            <div class="row mt-4">
                                <?php 
                                $radiology_status_counts = [];
                                foreach ($radiology_orders as $order) {
                                    $status = $order['order_status'];
                                    if (!isset($radiology_status_counts[$status])) {
                                        $radiology_status_counts[$status] = 0;
                                    }
                                    $radiology_status_counts[$status]++;
                                }
                                ?>
                                <?php foreach ($radiology_status_counts as $status => $count): ?>
                                    <div class="col-md-3 col-6 mb-3">
                                        <div class="card bg-light">
                                            <div class="card-body text-center">
                                                <h5 class="text-<?php 
                                                    echo $status == 'Completed' ? 'success' : 
                                                         ($status == 'Pending' ? 'warning' : 
                                                         ($status == 'In Progress' ? 'info' : 'secondary'));
                                                ?>"><?php echo $count; ?></h5>
                                                <small class="text-muted"><?php echo htmlspecialchars($status); ?></small>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-x-ray fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">No Radiology Orders</h5>
                                <p class="text-muted">No radiology orders have been placed for this visit.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Doctor Rounds Tab (IPD Only) -->
            <?php if ($is_ipd): ?>
            <div class="tab-pane fade" id="doctor-rounds" role="tabpanel">
                <div class="card">
                    <div class="card-header bg-primary py-2">
                        <h4 class="card-title mb-0 text-white">
                            <i class="fas fa-user-md mr-2"></i>Doctor Rounds
                            <span class="badge badge-light float-right"><?php echo count($doctor_rounds); ?> rounds</span>
                        </h4>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($doctor_rounds)): ?>
                            <div class="accordion" id="roundsAccordion">
                                <?php 
                                $current_date = null;
                                foreach ($doctor_rounds as $index => $round): 
                                    $round_time = new DateTime($round['round_datetime']);
                                    $created_at = new DateTime($round['created_at']);
                                    
                                    if ($current_date != $round_time->format('Y-m-d')) {
                                        $current_date = $round_time->format('Y-m-d');
                                        $date_display = $round_time->format('F j, Y');
                                ?>
                                    <div class="mb-2">
                                        <h5 class="text-primary">
                                            <i class="fas fa-calendar-day mr-2"></i><?php echo $date_display; ?>
                                        </h5>
                                    </div>
                                <?php } ?>
                                
                                <div class="card mb-2">
                                    <div class="card-header bg-light py-2" id="roundHeading<?php echo $index; ?>">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h6 class="mb-0">
                                                    <?php echo getRoundTypeBadge($round['round_type']); ?>
                                                    <?php echo getStatusBadge($round['round_status']); ?>
                                                    <button class="btn btn-link text-dark" type="button" data-toggle="collapse" 
                                                            data-target="#roundCollapse<?php echo $index; ?>" aria-expanded="false" 
                                                            aria-controls="roundCollapse<?php echo $index; ?>">
                                                        <i class="fas fa-chevron-down mr-1"></i>Round at <?php echo $round_time->format('H:i'); ?>
                                                    </button>
                                                </h6>
                                                <small class="text-muted">
                                                    <i class="fas fa-user-md mr-1"></i><?php echo htmlspecialchars($round['doctor_name']); ?>
                                                    | <i class="fas fa-clock mr-1"></i><?php echo $created_at->format('H:i'); ?>
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div id="roundCollapse<?php echo $index; ?>" class="collapse" aria-labelledby="roundHeading<?php echo $index; ?>" data-parent="#roundsAccordion">
                                        <div class="card-body">
                                            <?php if ($round['subjective_note']): ?>
                                                <div class="mb-3">
                                                    <h6 class="text-primary"><i class="fas fa-user mr-2"></i>Subjective</h6>
                                                    <div class="p-3 bg-light rounded"><?php echo nl2br(htmlspecialchars($round['subjective_note'])); ?></div>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if ($round['objective_note']): ?>
                                                <div class="mb-3">
                                                    <h6 class="text-success"><i class="fas fa-stethoscope mr-2"></i>Objective</h6>
                                                    <div class="p-3 bg-light rounded"><?php echo nl2br(htmlspecialchars($round['objective_note'])); ?></div>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if ($round['vital_signs']): ?>
                                                <div class="mb-3">
                                                    <h6 class="text-info"><i class="fas fa-heartbeat mr-2"></i>Vital Signs</h6>
                                                    <div class="p-3 bg-light rounded"><?php echo nl2br(htmlspecialchars($round['vital_signs'])); ?></div>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if ($round['examination_findings']): ?>
                                                <div class="mb-3">
                                                    <h6 class="text-secondary"><i class="fas fa-search mr-2"></i>Examination Findings</h6>
                                                    <div class="p-3 bg-light rounded"><?php echo nl2br(htmlspecialchars($round['examination_findings'])); ?></div>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if ($round['assessment_note']): ?>
                                                <div class="mb-3">
                                                    <h6 class="text-warning"><i class="fas fa-diagnoses mr-2"></i>Assessment</h6>
                                                    <div class="p-3 bg-light rounded"><?php echo nl2br(htmlspecialchars($round['assessment_note'])); ?></div>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if ($round['plan_note']): ?>
                                                <div class="mb-3">
                                                    <h6 class="text-info"><i class="fas fa-tasks mr-2"></i>Plan</h6>
                                                    <div class="p-3 bg-light rounded"><?php echo nl2br(htmlspecialchars($round['plan_note'])); ?></div>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if ($round['investigations_ordered']): ?>
                                                <div class="mb-3">
                                                    <h6 class="text-dark"><i class="fas fa-flask mr-2"></i>Investigations Ordered</h6>
                                                    <div class="p-3 bg-light rounded"><?php echo nl2br(htmlspecialchars($round['investigations_ordered'])); ?></div>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if ($round['medications_prescribed']): ?>
                                                <div class="mb-3">
                                                    <h6 class="text-success"><i class="fas fa-pills mr-2"></i>Medications Prescribed</h6>
                                                    <div class="p-3 bg-light rounded"><?php echo nl2br(htmlspecialchars($round['medications_prescribed'])); ?></div>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if ($round['recommendations']): ?>
                                                <div class="mb-3">
                                                    <h6 class="text-primary"><i class="fas fa-lightbulb mr-2"></i>Recommendations</h6>
                                                    <div class="p-3 bg-light rounded"><?php echo nl2br(htmlspecialchars($round['recommendations'])); ?></div>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if ($round['next_round_date']): ?>
                                                <div class="mb-3">
                                                    <h6 class="text-secondary"><i class="fas fa-calendar-alt mr-2"></i>Next Round Date</h6>
                                                    <div class="p-3 bg-light rounded"><?php echo date('M j, Y', strtotime($round['next_round_date'])); ?></div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-user-md fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">No Doctor Rounds</h5>
                                <p class="text-muted">No doctor rounds have been recorded for this admission.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Nurse Notes Tab (IPD Only) -->
            <div class="tab-pane fade" id="nurse-notes" role="tabpanel">
                <div class="card">
                    <div class="card-header bg-secondary py-2">
                        <h4 class="card-title mb-0 text-white">
                            <i class="fas fa-user-nurse mr-2"></i>Nurse Documentation
                            <span class="badge badge-light float-right">
                                <?php echo count($nurse_daily_notes) + count($nurse_rounds); ?> entries
                            </span>
                        </h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <!-- Nurse Rounds -->
                            <div class="col-md-6">
                                <div class="card mb-4">
                                    <div class="card-header bg-info py-2">
                                        <h5 class="card-title mb-0 text-white">
                                            <i class="fas fa-procedures mr-2"></i>Nurse Rounds
                                            <span class="badge badge-light float-right"><?php echo count($nurse_rounds); ?></span>
                                        </h5>
                                    </div>
                                    <div class="card-body">
                                        <?php if (!empty($nurse_rounds)): ?>
                                            <div class="list-group list-group-flush">
                                                <?php foreach ($nurse_rounds as $round): 
                                                    $round_time = new DateTime($round['round_datetime']);
                                                    $created_at = new DateTime($round['created_at']);
                                                ?>
                                                    <div class="list-group-item">
                                                        <div class="d-flex justify-content-between align-items-start">
                                                            <div>
                                                                <h6 class="mb-1">
                                                                    <?php echo getRoundTypeBadge($round['round_type']); ?>
                                                                    <?php echo getStatusBadge($round['round_status']); ?>
                                                                </h6>
                                                                <small class="text-muted">
                                                                    <i class="fas fa-calendar mr-1"></i><?php echo $round_time->format('M j, Y'); ?>
                                                                    <i class="fas fa-clock ml-2 mr-1"></i><?php echo $round_time->format('H:i'); ?>
                                                                    <br>
                                                                    <i class="fas fa-user-nurse mr-1"></i><?php echo htmlspecialchars($round['nurse_name']); ?>
                                                                </small>
                                                                <?php if ($round['general_condition']): ?>
                                                                    <div class="mt-1">
                                                                        <span class="badge badge-<?php 
                                                                            echo $round['general_condition'] == 'STABLE' ? 'success' : 
                                                                                 ($round['general_condition'] == 'CRITICAL' ? 'danger' : 
                                                                                 ($round['general_condition'] == 'SERIOUS' ? 'warning' : 'info')); 
                                                                        ?>">
                                                                            <?php echo htmlspecialchars($round['general_condition']); ?>
                                                                        </span>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </div>
                                                            <button type="button" class="btn btn-info btn-sm" 
                                                                    onclick="viewNurseRoundDetails(<?php echo htmlspecialchars(json_encode($round)); ?>)">
                                                                <i class="fas fa-eye"></i>
                                                            </button>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="text-center py-3">
                                                <i class="fas fa-procedures fa-2x text-muted mb-2"></i>
                                                <p class="text-muted mb-0">No nurse rounds recorded</p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Nurse Daily Notes -->
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header bg-success py-2">
                                        <h5 class="card-title mb-0 text-white">
                                            <i class="fas fa-clipboard-list mr-2"></i>Nurse Daily Notes
                                            <span class="badge badge-light float-right"><?php echo count($nurse_daily_notes); ?></span>
                                        </h5>
                                    </div>
                                    <div class="card-body">
                                        <?php if (!empty($nurse_daily_notes)): ?>
                                            <div class="list-group list-group-flush">
                                                <?php foreach ($nurse_daily_notes as $note): 
                                                    $note_date = new DateTime($note['note_date']);
                                                    $recorded_at = new DateTime($note['recorded_at']);
                                                ?>
                                                    <div class="list-group-item">
                                                        <div class="d-flex justify-content-between align-items-start">
                                                            <div>
                                                                <h6 class="mb-1">
                                                                    <?php echo getShiftBadge($note['shift']); ?>
                                                                    <?php echo getStatusBadge($note['status']); ?>
                                                                </h6>
                                                                <small class="text-muted">
                                                                    <i class="fas fa-calendar mr-1"></i><?php echo $note_date->format('M j, Y'); ?>
                                                                    <br>
                                                                    <i class="fas fa-user-nurse mr-1"></i><?php echo htmlspecialchars($note['nurse_name']); ?>
                                                                </small>
                                                            </div>
                                                            <button type="button" class="btn btn-info btn-sm" 
                                                                    onclick="viewNurseNoteDetails(<?php echo htmlspecialchars(json_encode($note)); ?>)">
                                                                <i class="fas fa-eye"></i>
                                                            </button>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="text-center py-3">
                                                <i class="fas fa-clipboard-list fa-2x text-muted mb-2"></i>
                                                <p class="text-muted mb-0">No nurse daily notes recorded</p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Consultations Tab -->
            <div class="tab-pane fade" id="consultations" role="tabpanel">
                <div class="card">
                    <div class="card-header bg-dark py-2">
                        <h4 class="card-title mb-0 text-white">
                            <i class="fas fa-stethoscope mr-2"></i>Consultations
                            <span class="badge badge-light float-right"><?php echo count($consultations); ?> consultations</span>
                        </h4>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($consultations)): ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-bordered">
                                    <thead class="thead-dark">
                                        <tr>
                                            <th>Date</th>
                                            <th>Time</th>
                                            <th>Consultation Type</th>
                                            <th>Reason</th>
                                            <th>Consulting Doctor</th>
                                            <th>Findings</th>
                                            <th>Recommendations</th>
                                            <th>Follow-up Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($consultations as $consultation): 
                                            $consultation_date = new DateTime($consultation['consultation_date']);
                                            $followup_date = $consultation['followup_date'] ? new DateTime($consultation['followup_date']) : null;
                                        ?>
                                            <tr>
                                                <td><?php echo $consultation_date->format('M j, Y'); ?></td>
                                                <td><?php echo htmlspecialchars($consultation['consultation_time']); ?></td>
                                                <td><?php echo htmlspecialchars($consultation['consultation_type']); ?></td>
                                                <td><?php echo htmlspecialchars($consultation['reason']); ?></td>
                                                <td><?php echo htmlspecialchars($consultation['doctor_name']); ?></td>
                                                <td>
                                                    <?php if ($consultation['findings']): ?>
                                                        <small><?php echo nl2br(htmlspecialchars($consultation['findings'])); ?></small>
                                                    <?php else: ?>
                                                        <span class="text-muted">None</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($consultation['recommendations']): ?>
                                                        <small><?php echo nl2br(htmlspecialchars($consultation['recommendations'])); ?></small>
                                                    <?php else: ?>
                                                        <span class="text-muted">None</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($followup_date): ?>
                                                        <?php echo $followup_date->format('M j, Y'); ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">Not scheduled</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php if ($consultation['notes']): ?>
                                                <tr>
                                                    <td colspan="8" class="bg-light">
                                                        <small class="text-muted">
                                                            <i class="fas fa-sticky-note mr-1"></i>
                                                            <strong>Notes:</strong> <?php echo htmlspecialchars($consultation['notes']); ?>
                                                        </small>
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-stethoscope fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">No Consultations</h5>
                                <p class="text-muted">No consultations have been recorded for this visit.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Details Modal -->
<div class="modal fade" id="detailsModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Details</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body" id="detailsContent">
                <!-- Content will be loaded dynamically -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="window.print()">
                    <i class="fas fa-print mr-2"></i>Print
                </button>
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

    // Initialize accordions to show first item
    if ($('#notesAccordion .collapse').length > 0) {
        $('#notesAccordion .collapse').first().collapse('show');
    }
    if ($('#roundsAccordion .collapse').length > 0) {
        $('#roundsAccordion .collapse').first().collapse('show');
    }
});

function viewNurseRoundDetails(round) {
    const modalContent = document.getElementById('detailsContent');
    const roundTime = new DateTime(round.round_datetime);
    
    let html = `
        <div class="card mb-3">
            <div class="card-header bg-light py-2">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0">
                            ${getRoundTypeBadgeHtml(round.round_type)} 
                            <span class="ml-2">Nurse Round</span>
                        </h6>
                        <small class="text-muted">
                            ${roundTime.toLocaleDateString()} ${roundTime.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}
                            by ${round.nurse_name}
                        </small>
                    </div>
                    <div>
                        ${getStatusBadgeHtml(round.round_status)}
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div class="row">
    `;
    
    // Vital signs section
    if (round.temperature || round.pulse_rate || round.respiratory_rate || round.blood_pressure_systolic || round.oxygen_saturation) {
        html += `<div class="col-md-6">
                    <h6 class="text-primary"><i class="fas fa-heartbeat mr-2"></i>Vital Signs</h6>
                    <div class="p-2 bg-light rounded">`;
        
        if (round.temperature) html += `<div><strong>Temp:</strong> ${round.temperature}°C</div>`;
        if (round.pulse_rate) html += `<div><strong>Pulse:</strong> ${round.pulse_rate} BPM</div>`;
        if (round.respiratory_rate) html += `<div><strong>RR:</strong> ${round.respiratory_rate}</div>`;
        if (round.blood_pressure_systolic && round.blood_pressure_diastolic) {
            html += `<div><strong>BP:</strong> ${round.blood_pressure_systolic}/${round.blood_pressure_diastolic} mmHg</div>`;
        }
        if (round.oxygen_saturation) html += `<div><strong>SpO2:</strong> ${round.oxygen_saturation}%</div>`;
        if (round.pain_score !== null) html += `<div><strong>Pain:</strong> ${round.pain_score}/10</div>`;
        
        html += `</div></div>`;
    }
    
    // General condition section
    if (round.general_condition || round.level_of_consciousness) {
        html += `<div class="col-md-6">
                    <h6 class="text-success"><i class="fas fa-user mr-2"></i>General Condition</h6>
                    <div class="p-2 bg-light rounded">`;
        
        if (round.general_condition) html += `<div><strong>Condition:</strong> ${round.general_condition}</div>`;
        if (round.level_of_consciousness) html += `<div><strong>LOC:</strong> ${round.level_of_consciousness}</div>`;
        if (round.fall_risk_assessment) html += `<div><strong>Fall Risk:</strong> ${round.fall_risk_assessment}</div>`;
        if (round.pressure_ulcer_risk) html += `<div><strong>Pressure Ulcer Risk:</strong> ${round.pressure_ulcer_risk}</div>`;
        
        html += `</div></div>`;
    }
    
    html += `</div>`;
    
    // Intake/Output section
    if (round.oral_intake || round.iv_intake || round.urine_output) {
        html += `<div class="row mt-3">
                    <div class="col-md-12">
                        <h6 class="text-info"><i class="fas fa-tint mr-2"></i>Intake & Output</h6>
                        <div class="p-2 bg-light rounded">`;
        
        if (round.oral_intake) html += `<div><strong>Oral Intake:</strong> ${round.oral_intake} ml</div>`;
        if (round.iv_intake) html += `<div><strong>IV Intake:</strong> ${round.iv_intake} ml</div>`;
        if (round.urine_output) html += `<div><strong>Urine Output:</strong> ${round.urine_output} ml</div>`;
        if (round.stool_output) html += `<div><strong>Stool:</strong> ${round.stool_output}</div>`;
        if (round.vomiting && round.vomiting !== 'NONE') html += `<div><strong>Vomiting:</strong> ${round.vomiting}</div>`;
        
        html += `</div></div></div>`;
    }
    
    // Observations section
    if (round.observations) {
        html += `<div class="mt-3">
                    <h6 class="text-warning"><i class="fas fa-search mr-2"></i>Observations</h6>
                    <div class="p-2 bg-light rounded">${round.observations.replace(/\n/g, '<br>')}</div>
                </div>`;
    }
    
    // Complaints section
    if (round.complaints) {
        html += `<div class="mt-3">
                    <h6 class="text-danger"><i class="fas fa-exclamation-circle mr-2"></i>Complaints</h6>
                    <div class="p-2 bg-light rounded">${round.complaints.replace(/\n/g, '<br>')}</div>
                </div>`;
    }
    
    // Interventions section
    if (round.interventions) {
        html += `<div class="mt-3">
                    <h6 class="text-primary"><i class="fas fa-hand-holding-medical mr-2"></i>Interventions</h6>
                    <div class="p-2 bg-light rounded">${round.interventions.replace(/\n/g, '<br>')}</div>
                </div>`;
    }
    
    // Medications given section
    if (round.medications_given) {
        html += `<div class="mt-3">
                    <h6 class="text-success"><i class="fas fa-pills mr-2"></i>Medications Given</h6>
                    <div class="p-2 bg-light rounded">${round.medications_given.replace(/\n/g, '<br>')}</div>
                </div>`;
    }
    
    // Patient education section
    if (round.patient_education) {
        html += `<div class="mt-3">
                    <h6 class="text-info"><i class="fas fa-graduation-cap mr-2"></i>Patient Education</h6>
                    <div class="p-2 bg-light rounded">${round.patient_education.replace(/\n/g, '<br>')}</div>
                </div>`;
    }
    
    // Special instructions section
    if (round.special_instructions) {
        html += `<div class="mt-3">
                    <h6 class="text-secondary"><i class="fas fa-clipboard-check mr-2"></i>Special Instructions</h6>
                    <div class="p-2 bg-light rounded">${round.special_instructions.replace(/\n/g, '<br>')}</div>
                </div>`;
    }
    
    html += `   </div>
            </div>`;
    
    modalContent.innerHTML = html;
    $('#detailsModal').modal('show');
}

function viewNurseNoteDetails(note) {
    const modalContent = document.getElementById('detailsContent');
    const noteDate = new Date(note.note_date);
    const recordedAt = new Date(note.recorded_at);
    
    let html = `
        <div class="card mb-3">
            <div class="card-header bg-light py-2">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0">
                            ${getShiftBadgeHtml(note.shift)} 
                            <span class="ml-2">Nurse Daily Note</span>
                        </h6>
                        <small class="text-muted">
                            ${noteDate.toLocaleDateString()}
                            by ${note.nurse_name}
                        </small>
                    </div>
                    <div>
                        ${getStatusBadgeHtml(note.status)}
                    </div>
                </div>
            </div>
            <div class="card-body">
    `;
    
    if (note.subjective) {
        html += `<div class="mb-3">
                    <h6 class="text-primary"><i class="fas fa-user mr-2"></i>Subjective</h6>
                    <div class="p-2 bg-light rounded">${note.subjective.replace(/\n/g, '<br>')}</div>
                </div>`;
    }
    
    if (note.objective) {
        html += `<div class="mb-3">
                    <h6 class="text-success"><i class="fas fa-stethoscope mr-2"></i>Objective</h6>
                    <div class="p-2 bg-light rounded">${note.objective.replace(/\n/g, '<br>')}</div>
                </div>`;
    }
    
    if (note.assessment) {
        html += `<div class="mb-3">
                    <h6 class="text-warning"><i class="fas fa-diagnoses mr-2"></i>Assessment</h6>
                    <div class="p-2 bg-light rounded">${note.assessment.replace(/\n/g, '<br>')}</div>
                </div>`;
    }
    
    if (note.plan) {
        html += `<div class="mb-3">
                    <h6 class="text-info"><i class="fas fa-tasks mr-2"></i>Plan</h6>
                    <div class="p-2 bg-light rounded">${note.plan.replace(/\n/g, '<br>')}</div>
                </div>`;
    }
    
    if (note.note_content) {
        html += `<div class="mb-3">
                    <h6 class="text-secondary"><i class="fas fa-notes-medical mr-2"></i>Notes</h6>
                    <div class="p-2 bg-light rounded">${note.note_content.replace(/\n/g, '<br>')}</div>
                </div>`;
    }
    
    html += `<div class="mt-3 text-muted small">
                <i class="fas fa-clock mr-1"></i>Recorded: ${recordedAt.toLocaleDateString()} ${recordedAt.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}
                ${note.finalized_at ? `<br><i class="fas fa-check-circle mr-1"></i>Finalized: ${new Date(note.finalized_at).toLocaleDateString()} by ${note.finalized_by_name || 'N/A'}` : ''}
            </div>`;
    
    html += `   </div>
            </div>`;
    
    modalContent.innerHTML = html;
    $('#detailsModal').modal('show');
}

function getRoundTypeBadgeHtml(type) {
    switch(type) {
        case 'MORNING':
            return '<span class="badge badge-info"><i class="fas fa-sun mr-1"></i>Morning</span>';
        case 'EVENING':
            return '<span class="badge badge-warning"><i class="fas fa-moon mr-1"></i>Evening</span>';
        case 'NIGHT':
            return '<span class="badge badge-dark"><i class="fas fa-star mr-1"></i>Night</span>';
        case 'SPECIAL':
            return '<span class="badge badge-danger"><i class="fas fa-user-md mr-1"></i>Special</span>';
        case 'OTHER':
            return '<span class="badge badge-secondary"><i class="fas fa-ellipsis-h mr-1"></i>Other</span>';
        default:
            return '<span class="badge badge-secondary">' + type + '</span>';
    }
}

function getShiftBadgeHtml(shift) {
    switch(shift.toLowerCase()) {
        case 'morning':
            return '<span class="badge badge-info"><i class="fas fa-sun mr-1"></i>Morning</span>';
        case 'evening':
            return '<span class="badge badge-warning"><i class="fas fa-moon mr-1"></i>Evening</span>';
        case 'night':
            return '<span class="badge badge-dark"><i class="fas fa-star mr-1"></i>Night</span>';
        default:
            return '<span class="badge badge-secondary">' + shift.charAt(0).toUpperCase() + shift.slice(1) + '</span>';
    }
}

function getStatusBadgeHtml(status) {
    switch(status) {
        case 'finalized':
        case 'COMPLETED':
        case 'DISPENSED':
            return '<span class="badge badge-success"><i class="fas fa-check-circle mr-1"></i>' + status.charAt(0).toUpperCase() + status.slice(1) + '</span>';
        case 'draft':
        case 'PENDING':
            return '<span class="badge badge-warning"><i class="fas fa-edit mr-1"></i>' + status.charAt(0).toUpperCase() + status.slice(1) + '</span>';
        case 'CANCELLED':
            return '<span class="badge badge-danger"><i class="fas fa-times-circle mr-1"></i>' + status.charAt(0).toUpperCase() + status.slice(1) + '</span>';
        default:
            return '<span class="badge badge-secondary">' + status + '</span>';
    }
}

function getPriorityBadgeHtml(priority) {
    switch(priority.toLowerCase()) {
        case 'urgent':
        case 'emergency':
            return '<span class="badge badge-danger"><i class="fas fa-exclamation-triangle mr-1"></i>' + priority.charAt(0).toUpperCase() + priority.slice(1) + '</span>';
        case 'stat':
            return '<span class="badge badge-danger"><i class="fas fa-bolt mr-1"></i>STAT</span>';
        case 'routine':
            return '<span class="badge badge-info"><i class="fas fa-clock mr-1"></i>Routine</span>';
        default:
            return '<span class="badge badge-secondary">' + priority.charAt(0).toUpperCase() + priority.slice(1) + '</span>';
    }
}

// Print functionality
function printPage() {
    window.print();
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + P for print
    if (e.ctrlKey && e.keyCode === 80) {
        e.preventDefault();
        window.print();
    }
    // Escape to close modal
    if (e.keyCode === 27 && $('.modal.show').length) {
        $('.modal').modal('hide');
    }
});
</script>

<style>
/* Read-only specific styles */
.form-control[readonly], .form-control:disabled {
    background-color: #f8f9fa;
    cursor: not-allowed;
    opacity: 1;
}

.card-header .badge {
    font-size: 0.75rem;
}

.nav-tabs .nav-link.active {
    font-weight: bold;
    border-bottom: 3px solid;
}

.nav-tabs .nav-link {
    color: #495057;
}

.accordion .card-header {
    cursor: pointer;
}

.accordion .card-header:hover {
    background-color: #f8f9fa;
}

/* Print styles */
@media print {
    .card-header, .card-tools, .btn, .modal,
    .card-footer, .nav-tabs, .toast-container {
        display: none !important;
    }
    .card {
        border: none !important;
        box-shadow: none !important;
        page-break-inside: avoid;
    }
    .card-body {
        padding: 0 !important;
    }
    .tab-pane {
        display: block !important;
        page-break-before: always;
    }
    #doctor-notes {
        page-break-before: auto;
    }
    h1, h2, h3, h4, h5, h6 {
        page-break-after: avoid;
    }
    table {
        page-break-inside: avoid;
    }
    .accordion .collapse {
        display: block !important;
        height: auto !important;
    }
}
</style>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
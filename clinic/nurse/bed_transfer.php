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

// Initialize variables
$patient_info = null;
$visit_info = null;
$ipd_info = null;
$bed_transfers = [];
$current_bed = null;
$today = date('Y-m-d');

// Get visit and patient information
$visit_sql = "SELECT v.*, 
             p.patient_id, p.patient_mrn, p.first_name, p.middle_name, p.last_name,
             p.date_of_birth, p.sex, p.phone_primary, p.email,
             p.blood_group,
             u.user_name as provider_name,
             d.department_name
             FROM visits v
             JOIN patients p ON v.patient_id = p.patient_id
             JOIN users u ON v.attending_provider_id = u.user_id
             LEFT JOIN departments d ON v.department_id = d.department_id
             WHERE v.visit_id = ? 
             AND v.visit_status != 'CANCELLED'";
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
$patient_info = $visit_info;

// Check if visit is IPD
if ($visit_info['visit_type'] !== 'IPD') {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Patient must be admitted (IPD) for bed transfer";
    header("Location: /clinic/dashboard.php");
    exit;
}

// Get IPD admission details
$ipd_sql = "SELECT ia.*, 
            w.ward_name, w.ward_type,
            b.bed_number, b.bed_type, b.bed_status as bed_status,
            adm.user_name as admitting_provider_name,
            att.user_name as attending_provider_name,
            nurse.user_name as nurse_incharge_name
            FROM ipd_admissions ia
            LEFT JOIN wards w ON ia.ward_id = w.ward_id
            LEFT JOIN beds b ON ia.bed_id = b.bed_id
            LEFT JOIN users adm ON ia.admitting_provider_id = adm.user_id
            LEFT JOIN users att ON ia.attending_provider_id = att.user_id
            LEFT JOIN users nurse ON ia.nurse_incharge_id = nurse.user_id
            WHERE ia.visit_id = ?";
$ipd_stmt = $mysqli->prepare($ipd_sql);
$ipd_stmt->bind_param("i", $visit_id);
$ipd_stmt->execute();
$ipd_result = $ipd_stmt->get_result();

if ($ipd_result->num_rows === 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "IPD admission not found";
    header("Location: /clinic/dashboard.php");
    exit;
}

$ipd_info = $ipd_result->fetch_assoc();

// Get current bed info
if ($ipd_info['bed_id']) {
    $current_bed = [
        'ward_name' => $ipd_info['ward_name'],
        'bed_number' => $ipd_info['bed_number'],
        'bed_type' => $ipd_info['bed_type'],
        'bed_status' => $ipd_info['bed_status']
    ];
}

// Get bed transfer history
$transfers_sql = "SELECT bt.*, 
                  w1.ward_name as from_ward_name,
                  w2.ward_name as to_ward_name,
                  b1.bed_number as from_bed_number,
                  b2.bed_number as to_bed_number,
                  u1.user_name as transferred_by_name,
                  u2.user_name as approved_by_name
                  FROM bed_transfers bt
                  LEFT JOIN wards w1 ON bt.from_ward_id = w1.ward_id
                  LEFT JOIN wards w2 ON bt.to_ward_id = w2.ward_id
                  LEFT JOIN beds b1 ON bt.from_bed_id = b1.bed_id
                  LEFT JOIN beds b2 ON bt.to_bed_id = b2.bed_id
                  LEFT JOIN users u1 ON bt.transferred_by = u1.user_id
                  LEFT JOIN users u2 ON bt.approved_by = u2.user_id
                  WHERE bt.visit_id = ?
                  ORDER BY bt.transfer_date DESC, bt.transfer_time DESC";
$transfers_stmt = $mysqli->prepare($transfers_sql);
$transfers_stmt->bind_param("i", $visit_id);
$transfers_stmt->execute();
$transfers_result = $transfers_stmt->get_result();
$bed_transfers = $transfers_result->fetch_all(MYSQLI_ASSOC);

// Get available wards
$wards_sql = "SELECT w.*, 
              COUNT(b.bed_id) as total_beds,
              SUM(CASE WHEN b.bed_status = 'available' THEN 1 ELSE 0 END) as available_beds
              FROM wards w
              LEFT JOIN beds b ON w.ward_id = b.bed_ward_id
              WHERE w.ward_is_active = 1
              GROUP BY w.ward_id
              ORDER BY w.ward_name";
$wards_result = $mysqli->query($wards_sql);
$wards = $wards_result->fetch_all(MYSQLI_ASSOC);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Request bed transfer
    if (isset($_POST['request_transfer'])) {
        $transfer_date = $_POST['transfer_date'];
        $transfer_time = $_POST['transfer_time'];
        $to_ward_id = intval($_POST['to_ward_id']);
        $to_bed_id = intval($_POST['to_bed_id']);
        $transfer_reason = trim($_POST['transfer_reason']);
        $priority = $_POST['priority'];
        $special_instructions = trim($_POST['special_instructions'] ?? '');
        $requested_by = $_SESSION['user_id'];
        
        // Get current bed/ward info
        $from_ward_id = $ipd_info['ward_id'] ?? null;
        $from_bed_id = $ipd_info['bed_id'] ?? null;
        
        $insert_sql = "INSERT INTO bed_transfers 
                      (visit_id, patient_id, transfer_date, transfer_time,
                       from_ward_id, from_bed_id, to_ward_id, to_bed_id,
                       transfer_reason, priority, special_instructions,
                       requested_by, status)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')";
        
        $insert_stmt = $mysqli->prepare($insert_sql);
        $insert_stmt->bind_param("isssiiiiisss", 
            $visit_id, $patient_info['patient_id'], $transfer_date, $transfer_time,
            $from_ward_id, $from_bed_id, $to_ward_id, $to_bed_id,
            $transfer_reason, $priority, $special_instructions,
            $requested_by
        );
        
        if ($insert_stmt->execute()) {
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Bed transfer request submitted successfully";
            header("Location: bed_transfer.php?visit_id=" . $visit_id);
            exit;
        } else {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error submitting transfer request: " . $mysqli->error;
        }
    }
    
    // Cancel transfer request
    if (isset($_POST['cancel_transfer'])) {
        $transfer_id = intval($_POST['transfer_id']);
        $cancellation_reason = trim($_POST['cancellation_reason']);
        
        $update_sql = "UPDATE bed_transfers 
                      SET status = 'cancelled', 
                          cancellation_reason = ?,
                          cancelled_at = NOW(),
                          cancelled_by = ?
                      WHERE transfer_id = ? AND status = 'pending'";
        
        $update_stmt = $mysqli->prepare($update_sql);
        $update_stmt->bind_param("sii", $cancellation_reason, $_SESSION['user_id'], $transfer_id);
        
        if ($update_stmt->execute()) {
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Transfer request cancelled successfully";
            header("Location: bed_transfer.php?visit_id=" . $visit_id);
            exit;
        } else {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error cancelling transfer request: " . $mysqli->error;
        }
    }
    
    // Execute transfer (for approved requests)
    if (isset($_POST['execute_transfer'])) {
        $transfer_id = intval($_POST['transfer_id']);
        
        // Start transaction
        $mysqli->begin_transaction();
        
        try {
            // Get transfer details
            $transfer_sql = "SELECT * FROM bed_transfers 
                            WHERE transfer_id = ? AND status = 'approved'";
            $transfer_stmt = $mysqli->prepare($transfer_sql);
            $transfer_stmt->bind_param("i", $transfer_id);
            $transfer_stmt->execute();
            $transfer_result = $transfer_stmt->get_result();
            $transfer = $transfer_result->fetch_assoc();
            
            if (!$transfer) {
                throw new Exception("Transfer not found or not approved");
            }
            
            // Update current bed to available
            if ($transfer['from_bed_id']) {
                $update_from_bed = "UPDATE beds SET status = 'available' WHERE bed_id = ?";
                $stmt1 = $mysqli->prepare($update_from_bed);
                $stmt1->bind_param("i", $transfer['from_bed_id']);
                $stmt1->execute();
            }
            
            // Update new bed to occupied
            $update_to_bed = "UPDATE beds SET status = 'occupied' WHERE bed_id = ?";
            $stmt2 = $mysqli->prepare($update_to_bed);
            $stmt2->bind_param("i", $transfer['to_bed_id']);
            $stmt2->execute();
            
            // Update patient's current bed in IPD admission
            $update_ipd = "UPDATE ipd_admissions SET 
                          ward_id = ?,
                          bed_id = ?,
                          updated_at = NOW()
                          WHERE visit_id = ?";
            $stmt3 = $mysqli->prepare($update_ipd);
            $stmt3->bind_param("iii", $transfer['to_ward_id'], $transfer['to_bed_id'], $visit_id);
            $stmt3->execute();
            
            // Update transfer status
            $update_transfer = "UPDATE bed_transfers 
                               SET status = 'completed',
                                   transferred_at = NOW(),
                                   transferred_by = ?
                               WHERE transfer_id = ?";
            $stmt4 = $mysqli->prepare($update_transfer);
            $stmt4->bind_param("ii", $_SESSION['user_id'], $transfer_id);
            $stmt4->execute();
            
            $mysqli->commit();
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Bed transfer completed successfully";
            header("Location: bed_transfer.php?visit_id=" . $visit_id);
            exit;
            
        } catch (Exception $e) {
            $mysqli->rollback();
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error executing transfer: " . $e->getMessage();
        }
    }
}

// Get patient full name
$full_name = $patient_info['first_name'] . 
            ($patient_info['middle_name'] ? ' ' . $patient_info['middle_name'] : '') . 
            ' ' . $patient_info['last_name'];

// Calculate age
$age = '';
if (!empty($patient_info['date_of_birth'])) {
    $birthDate = new DateTime($patient_info['date_of_birth']);
    $today = new DateTime();
    $age = $today->diff($birthDate)->y . ' years';
}

// Function to get status badge
function getTransferStatusBadge($status) {
    switch($status) {
        case 'completed':
            return '<span class="badge badge-success"><i class="fas fa-check-circle mr-1"></i>Completed</span>';
        case 'approved':
            return '<span class="badge badge-primary"><i class="fas fa-thumbs-up mr-1"></i>Approved</span>';
        case 'pending':
            return '<span class="badge badge-warning"><i class="fas fa-clock mr-1"></i>Pending</span>';
        case 'cancelled':
            return '<span class="badge badge-secondary"><i class="fas fa-ban mr-1"></i>Cancelled</span>';
        case 'rejected':
            return '<span class="badge badge-danger"><i class="fas fa-times-circle mr-1"></i>Rejected</span>';
        default:
            return '<span class="badge badge-light">' . $status . '</span>';
    }
}

// Function to get priority badge
function getTransferPriorityBadge($priority) {
    switch($priority) {
        case 'emergency':
            return '<span class="badge badge-danger"><i class="fas fa-exclamation-triangle mr-1"></i>Emergency</span>';
        case 'urgent':
            return '<span class="badge badge-warning"><i class="fas fa-exclamation-circle mr-1"></i>Urgent</span>';
        case 'routine':
            return '<span class="badge badge-info"><i class="fas fa-clock mr-1"></i>Routine</span>';
        default:
            return '<span class="badge badge-secondary">' . $priority . '</span>';
    }
}
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0">
            <i class="fas fa-fw fa-bed mr-2"></i>Bed Transfer: <?php echo htmlspecialchars($patient_info['patient_mrn']); ?>
        </h3>
        <div class="card-tools">
            <div class="btn-group">
                <button type="button" class="btn btn-light" onclick="window.history.back()">
                    <i class="fas fa-arrow-left mr-2"></i>Back
                </button>
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

        <!-- Patient and Current Bed Info -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card bg-light">
                    <div class="card-body py-2">
                        <div class="row">
                            <div class="col-md-6">
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
                                        <th class="text-muted">Age/Gender:</th>
                                        <td>
                                            <span class="badge badge-secondary"><?php echo $age ?: 'N/A'; ?></span>
                                            <span class="badge badge-secondary ml-1"><?php echo htmlspecialchars($patient_info['sex']); ?></span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">Admission Date:</th>
                                        <td><?php echo date('M j, Y H:i', strtotime($ipd_info['admission_datetime'])); ?></td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">Admitting Provider:</th>
                                        <td><?php echo htmlspecialchars($ipd_info['admitting_provider_name'] ?? 'N/A'); ?></td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <div class="card bg-white border">
                                    <div class="card-body py-2">
                                        <div class="row">
                                            <div class="col-md-8">
                                                <h5 class="mb-1">
                                                    <i class="fas fa-bed text-primary mr-2"></i>
                                                    Current Location
                                                </h5>
                                                <div class="mt-2">
                                                    <div>
                                                        <strong>Ward:</strong> 
                                                        <span class="badge badge-primary">
                                                            <?php echo htmlspecialchars($current_bed['ward_name'] ?? 'Not Assigned'); ?>
                                                        </span>
                                                        <?php if ($current_bed['ward_name'] && isset($ipd_info['ward_type'])): ?>
                                                            <small class="text-muted ml-2">
                                                                (<?php echo htmlspecialchars($ipd_info['ward_type']); ?>)
                                                            </small>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="mt-1">
                                                        <strong>Bed:</strong> 
                                                        <span class="badge badge-info">
                                                            <?php echo htmlspecialchars($current_bed['bed_number'] ?? 'Not Assigned'); ?>
                                                        </span>
                                                        <?php if ($current_bed['bed_type']): ?>
                                                            <small class="text-muted ml-2">
                                                                (<?php echo htmlspecialchars($current_bed['bed_type']); ?>)
                                                            </small>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="mt-1">
                                                        <strong>Status:</strong> 
                                                        <span class="badge badge-<?php echo $current_bed['bed_status'] === 'occupied' ? 'danger' : 'success'; ?>">
                                                            <?php echo htmlspecialchars($current_bed['bed_status'] ?? 'N/A'); ?>
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-4 text-center">
                                                <div class="display-4 text-primary">
                                                    <i class="fas fa-procedures"></i>
                                                </div>
                                                <small class="text-muted">
                                                    <?php echo htmlspecialchars($ipd_info['admission_type'] ?? 'N/A'); ?>
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Request New Transfer Form -->
            <div class="col-md-5">
                <div class="card">
                    <div class="card-header bg-success py-2">
                        <h4 class="card-title mb-0 text-white">
                            <i class="fas fa-exchange-alt mr-2"></i>Request Bed Transfer
                        </h4>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="requestTransferForm">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="transfer_date">Transfer Date <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" id="transfer_date" name="transfer_date" 
                                               value="<?php echo date('Y-m-d'); ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="transfer_time">Transfer Time <span class="text-danger">*</span></label>
                                        <input type="time" class="form-control" id="transfer_time" name="transfer_time" 
                                               value="<?php echo date('H:i'); ?>" required>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Current Location -->
                            <div class="card mb-3">
                                <div class="card-header bg-light py-2">
                                    <h6 class="card-title mb-0">Current Location</h6>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label>Current Ward</label>
                                                <input type="text" class="form-control" 
                                                       value="<?php echo htmlspecialchars($current_bed['ward_name'] ?? 'Not Assigned'); ?>" 
                                                       readonly>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label>Current Bed</label>
                                                <input type="text" class="form-control" 
                                                       value="<?php echo htmlspecialchars($current_bed['bed_number'] ?? 'Not Assigned'); ?>" 
                                                       readonly>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Destination -->
                            <div class="card mb-3">
                                <div class="card-header bg-primary text-white py-2">
                                    <h6 class="card-title mb-0">Destination</h6>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="to_ward_id">Select Ward <span class="text-danger">*</span></label>
                                                <select class="form-control" id="to_ward_id" name="to_ward_id" required>
                                                    <option value="">Select Ward</option>
                                                    <?php foreach ($wards as $ward): ?>
                                                        <option value="<?php echo $ward['ward_id']; ?>">
                                                            <?php echo htmlspecialchars($ward['ward_name']); ?> 
                                                            (<?php echo $ward['available_beds']; ?> beds available)
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="to_bed_id">Select Bed <span class="text-danger">*</span></label>
                                                <select class="form-control" id="to_bed_id" name="to_bed_id" required>
                                                    <option value="">Select a ward first</option>
                                                </select>
                                                <small class="form-text text-muted" id="bedInfo">Select a ward to see available beds</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Transfer Details -->
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="priority">Priority <span class="text-danger">*</span></label>
                                        <select class="form-control" id="priority" name="priority" required>
                                            <option value="routine">Routine</option>
                                            <option value="urgent">Urgent</option>
                                            <option value="emergency">Emergency</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="transfer_reason">Reason <span class="text-danger">*</span></label>
                                        <select class="form-control" id="transfer_reason" name="transfer_reason" required>
                                            <option value="">Select Reason</option>
                                            <option value="Medical Condition">Medical Condition</option>
                                            <option value="Isolation Required">Isolation Required</option>
                                            <option value="Ward Specialization">Ward Specialization</option>
                                            <option value="Patient Request">Patient Request</option>
                                            <option value="Bed Maintenance">Bed Maintenance</option>
                                            <option value="Overcrowding">Overcrowding</option>
                                            <option value="Other">Other</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="special_instructions">Special Instructions</label>
                                <textarea class="form-control" id="special_instructions" name="special_instructions" 
                                          rows="3" placeholder="Any special requirements or instructions..."></textarea>
                            </div>
                            
                            <!-- Quick Reasons -->
                            <div class="form-group">
                                <label>Quick Reasons:</label>
                                <div class="btn-group btn-group-sm d-flex" role="group">
                                    <button type="button" class="btn btn-outline-primary flex-fill" onclick="setReason('Medical Condition')">
                                        Medical Condition
                                    </button>
                                    <button type="button" class="btn btn-outline-success flex-fill" onclick="setReason('Isolation Required')">
                                        Isolation
                                    </button>
                                    <button type="button" class="btn btn-outline-warning flex-fill" onclick="setReason('Ward Specialization')">
                                        Specialization
                                    </button>
                                    <button type="button" class="btn btn-outline-info flex-fill" onclick="setReason('Patient Request')">
                                        Patient Request
                                    </button>
                                </div>
                            </div>
                            
                            <div class="form-group mb-0">
                                <button type="submit" name="request_transfer" class="btn btn-success btn-lg btn-block">
                                    <i class="fas fa-paper-plane mr-2"></i>Submit Request
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Transfer History -->
            <div class="col-md-7">
                <div class="card">
                    <div class="card-header bg-info py-2">
                        <h4 class="card-title mb-0 text-white">
                            <i class="fas fa-history mr-2"></i>Transfer History
                        </h4>
                        <div class="card-tools">
                            <span class="badge badge-light"><?php echo count($bed_transfers); ?> transfers</span>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <?php if (!empty($bed_transfers)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Date & Time</th>
                                            <th>From</th>
                                            <th>To</th>
                                            <th>Reason</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($bed_transfers as $transfer): 
                                            $transfer_datetime = $transfer['transfer_date'] . ' ' . $transfer['transfer_time'];
                                            $is_recent = (strtotime($transfer_datetime) > strtotime('-7 days'));
                                            $row_class = $is_recent ? 'table-info' : '';
                                        ?>
                                            <tr class="<?php echo $row_class; ?>">
                                                <td>
                                                    <div>
                                                        <?php echo date('M j, Y', strtotime($transfer['transfer_date'])); ?>
                                                    </div>
                                                    <div class="text-muted">
                                                        <?php echo date('H:i', strtotime($transfer['transfer_time'])); ?>
                                                    </div>
                                                    <?php if ($transfer['transferred_at']): ?>
                                                        <div class="text-success small">
                                                            <i class="fas fa-check-circle mr-1"></i>
                                                            Executed: <?php echo date('H:i', strtotime($transfer['transferred_at'])); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($transfer['from_ward_name']): ?>
                                                        <div>
                                                            <span class="badge badge-secondary">
                                                                <?php echo htmlspecialchars($transfer['from_ward_name']); ?>
                                                            </span>
                                                        </div>
                                                        <div class="mt-1">
                                                            Bed: <?php echo htmlspecialchars($transfer['from_bed_number']); ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="text-muted">Initial Admission</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div>
                                                        <span class="badge badge-primary">
                                                            <?php echo htmlspecialchars($transfer['to_ward_name']); ?>
                                                        </span>
                                                    </div>
                                                    <div class="mt-1">
                                                        Bed: <?php echo htmlspecialchars($transfer['to_bed_number']); ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="font-weight-bold">
                                                        <?php echo htmlspecialchars($transfer['transfer_reason']); ?>
                                                    </div>
                                                    <?php if ($transfer['special_instructions']): ?>
                                                        <small class="text-muted" title="<?php echo htmlspecialchars($transfer['special_instructions']); ?>">
                                                            <i class="fas fa-info-circle mr-1"></i>
                                                            <?php echo htmlspecialchars(substr($transfer['special_instructions'], 0, 50)); ?>...
                                                        </small>
                                                    <?php endif; ?>
                                                    <?php if ($transfer['cancellation_reason']): ?>
                                                        <div class="text-danger small">
                                                            <i class="fas fa-ban mr-1"></i>
                                                            Cancelled: <?php echo htmlspecialchars(substr($transfer['cancellation_reason'], 0, 50)); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="mb-1">
                                                        <?php echo getTransferStatusBadge($transfer['status']); ?>
                                                    </div>
                                                    <div>
                                                        <?php echo getTransferPriorityBadge($transfer['priority']); ?>
                                                    </div>
                                                </td>
                                                <td class="text-center">
                                                    <button type="button" class="btn btn-sm btn-info" 
                                                            onclick="viewTransferDetails(<?php echo htmlspecialchars(json_encode($transfer)); ?>)"
                                                            title="View Details">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <?php if ($transfer['status'] == 'pending' && $transfer['requested_by'] == $_SESSION['user_id']): ?>
                                                        <button type="button" class="btn btn-sm btn-warning" 
                                                                data-toggle="modal" data-target="#cancelTransferModal"
                                                                onclick="setCancelTransfer(<?php echo $transfer['transfer_id']; ?>)"
                                                                title="Cancel Request">
                                                            <i class="fas fa-times"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-exchange-alt fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">No Transfer History</h5>
                                <p class="text-muted">No bed transfers have been recorded for this patient.</p>
                                <a href="#requestTransferForm" class="btn btn-success">
                                    <i class="fas fa-exchange-alt mr-2"></i>Request First Transfer
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Pending Transfers -->
                <?php 
                $pending_transfers = array_filter($bed_transfers, function($t) { 
                    return in_array($t['status'], ['pending', 'approved']); 
                });
                ?>
                <?php if (!empty($pending_transfers)): ?>
                <div class="card mt-4">
                    <div class="card-header bg-warning py-2">
                        <h4 class="card-title mb-0">
                            <i class="fas fa-clock mr-2"></i>Pending Transfer Requests
                        </h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php foreach ($pending_transfers as $transfer): ?>
                                <div class="col-md-6 mb-3">
                                    <div class="card card-warning">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <h5 class="card-title mb-0">Transfer Request</h5>
                                                <?php echo getTransferStatusBadge($transfer['status']); ?>
                                            </div>
                                            <div class="mb-2">
                                                <small class="text-muted">
                                                    Requested for: <?php echo date('M j, Y H:i', strtotime($transfer['transfer_date'] . ' ' . $transfer['transfer_time'])); ?>
                                                </small>
                                            </div>
                                            <div class="mb-2">
                                                <div class="row">
                                                    <div class="col-6">
                                                        <div class="text-center">
                                                            <small class="text-muted">From</small>
                                                            <div class="font-weight-bold">
                                                                <?php if ($transfer['from_ward_name']): ?>
                                                                    <?php echo htmlspecialchars($transfer['from_ward_name']); ?>
                                                                <?php else: ?>
                                                                    Initial
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="col-6">
                                                        <div class="text-center">
                                                            <small class="text-muted">To</small>
                                                            <div class="font-weight-bold text-primary">
                                                                <?php echo htmlspecialchars($transfer['to_ward_name']); ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="mb-2">
                                                <div class="d-flex justify-content-between">
                                                    <span>Priority:</span>
                                                    <?php echo getTransferPriorityBadge($transfer['priority']); ?>
                                                </div>
                                                <div class="d-flex justify-content-between mt-1">
                                                    <span>Reason:</span>
                                                    <small class="text-muted"><?php echo htmlspecialchars(substr($transfer['transfer_reason'], 0, 30)); ?>...</small>
                                                </div>
                                            </div>
                                            <div class="text-center">
                                                <?php if ($transfer['status'] == 'pending'): ?>
                                                    <button type="button" class="btn btn-sm btn-warning" 
                                                            data-toggle="modal" data-target="#cancelTransferModal"
                                                            onclick="setCancelTransfer(<?php echo $transfer['transfer_id']; ?>)">
                                                        <i class="fas fa-times mr-1"></i>Cancel
                                                    </button>
                                                <?php elseif ($transfer['status'] == 'approved'): ?>
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="transfer_id" value="<?php echo $transfer['transfer_id']; ?>">
                                                        <button type="submit" name="execute_transfer" class="btn btn-sm btn-success"
                                                                onclick="return confirm('Execute this bed transfer? This will move the patient to the new bed.')">
                                                            <i class="fas fa-check mr-1"></i>Execute
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Ward Availability -->
        <div class="row mt-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-secondary py-2">
                        <h6 class="card-title mb-0 text-white">
                            <i class="fas fa-hospital mr-2"></i>Ward Availability
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php foreach ($wards as $ward): ?>
                                <div class="col-md-4 mb-3">
                                    <div class="card">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <h5 class="card-title mb-0">
                                                    <i class="fas fa-hospital-alt mr-2"></i>
                                                    <?php echo htmlspecialchars($ward['ward_name']); ?>
                                                </h5>
                                                <span class="badge badge-<?php echo $ward['available_beds'] > 0 ? 'success' : 'danger'; ?>">
                                                    <?php echo $ward['available_beds']; ?>/<?php echo $ward['total_beds']; ?> beds
                                                </span>
                                            </div>
                                            <?php if (!empty($ward['ward_description'])): ?>
                                                <p class="card-text text-muted small">
                                                    <?php echo htmlspecialchars($ward['ward_description']); ?>
                                                </p>
                                            <?php endif; ?>
                                            <?php if (!empty($ward['ward_type'])): ?>
                                                <p class="card-text small">
                                                    <span class="badge badge-info"><?php echo htmlspecialchars($ward['ward_type']); ?></span>
                                                </p>
                                            <?php endif; ?>
                                            <div class="text-center mt-2">
                                                <button type="button" class="btn btn-sm btn-outline-primary"
                                                        onclick="selectWard(<?php echo $ward['ward_id']; ?>)">
                                                    <i class="fas fa-bed mr-1"></i>Select This Ward
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Cancel Transfer Modal -->
<div class="modal fade" id="cancelTransferModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST" id="cancelTransferForm">
                <input type="hidden" name="transfer_id" id="cancel_transfer_id">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title">Cancel Transfer Request</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label for="cancellation_reason">Cancellation Reason <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="cancellation_reason" name="cancellation_reason" 
                                  rows="3" placeholder="Why are you cancelling this transfer request?" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" name="cancel_transfer" class="btn btn-warning">
                        <i class="fas fa-ban mr-2"></i>Confirm Cancellation
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Transfer Details Modal -->
<div class="modal fade" id="transferDetailsModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">Transfer Details</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body" id="transferDetailsContent">
                <!-- Content will be loaded dynamically -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="printTransfer()">
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

    // Initialize date picker
    const today = new Date();
    const tomorrow = new Date(today);
    tomorrow.setDate(today.getDate() + 1);
    
    $('#transfer_date').val(today.toISOString().split('T')[0]);
    
    // Load beds when ward is selected
    $('#to_ward_id').change(function() {
        const wardId = $(this).val();
        if (wardId) {
            loadAvailableBeds(wardId);
        } else {
            $('#to_bed_id').html('<option value="">Select a ward first</option>');
            $('#bedInfo').text('Select a ward to see available beds');
        }
    });
    
    // Auto-expand textareas
    $('textarea').on('input', function() {
        this.style.height = 'auto';
        this.style.height = (this.scrollHeight) + 'px';
    });
});

function loadAvailableBeds(wardId) {
    if (!wardId) return;
    
    $('#to_bed_id').html('<option value="">Loading beds...</option>');
    $('#bedInfo').html('<span class="text-info">Loading beds...</span>');
    
    // Simple AJAX call to get beds
    $.ajax({
        url: 'ajax/get_available_beds.php',
        method: 'POST',
        data: { ward_id: wardId },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                let options = '<option value="">Select Bed</option>';
                response.beds.forEach(function(bed) {
                    options += `<option value="${bed.bed_id}">${bed.bed_number} - ${bed.bed_type}</option>`;
                });
                $('#to_bed_id').html(options);
                $('#bedInfo').html(`<span class="text-success">${response.beds.length} beds available</span>`);
            } else {
                $('#to_bed_id').html('<option value="">No beds available</option>');
                $('#bedInfo').html(`<span class="text-danger">${response.message}</span>`);
            }
        },
        error: function() {
            $('#to_bed_id').html('<option value="">Error loading beds</option>');
            $('#bedInfo').html('<span class="text-danger">Error loading beds</span>');
        }
    });
}

function selectWard(wardId) {
    $('#to_ward_id').val(wardId).trigger('change');
    $('html, body').animate({
        scrollTop: $('#requestTransferForm').offset().top - 20
    }, 500);
}

function setReason(reason) {
    $('#transfer_reason').val(reason);
    showToast(`Reason set to: ${reason}`, 'info');
}

function setCancelTransfer(transferId) {
    $('#cancel_transfer_id').val(transferId);
}

function viewTransferDetails(transfer) {
    const modalContent = document.getElementById('transferDetailsContent');
    const transferDate = new Date(transfer.transfer_date);
    const transferTime = transfer.transfer_time;
    
    let html = `
        <div class="card mb-3">
            <div class="card-header bg-light py-2">
                <h5 class="card-title mb-0">
                    Transfer Request Details
                </h5>
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless">
                            <tr>
                                <th width="40%">Requested For:</th>
                                <td>${transferDate.toLocaleDateString()} ${transferTime}</td>
                            </tr>
                            <tr>
                                <th>Priority:</th>
                                <td>${getTransferPriorityBadge(transfer.priority)}</td>
                            </tr>
                            <tr>
                                <th>Status:</th>
                                <td>${getTransferStatusBadge(transfer.status)}</td>
                            </tr>
                            <tr>
                                <th>Requested By:</th>
                                <td>${transfer.transferred_by_name}</td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-sm table-borderless">
    `;
    
    if (transfer.approved_by_name) {
        html += `<tr>
                    <th width="40%">Approved By:</th>
                    <td>${transfer.approved_by_name}</td>
                </tr>`;
    }
    
    if (transfer.transferred_at) {
        const executedDate = new Date(transfer.transferred_at);
        html += `<tr>
                    <th>Executed At:</th>
                    <td>${executedDate.toLocaleString()}</td>
                </tr>`;
    }
    
    if (transfer.cancelled_at) {
        const cancelledDate = new Date(transfer.cancelled_at);
        html += `<tr>
                    <th>Cancelled At:</th>
                    <td>${cancelledDate.toLocaleString()}</td>
                </tr>`;
    }
    
    html += `       </table>
                    </div>
                </div>
                
                <!-- Transfer Locations -->
                <div class="row mb-3">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-secondary text-white py-1">
                                <h6 class="card-title mb-0">From</h6>
                            </div>
                            <div class="card-body text-center">
    `;
    
    if (transfer.from_ward_name) {
        html += `<div class="mb-2">
                    <h6>Ward</h6>
                    <div class="h4 text-secondary">${transfer.from_ward_name}</div>
                </div>
                <div>
                    <h6>Bed</h6>
                    <div class="h3 text-secondary">${transfer.from_bed_number}</div>
                </div>`;
    } else {
        html += `<div class="text-muted">
                    <i class="fas fa-info-circle fa-2x mb-2"></i>
                    <div>Initial Admission</div>
                </div>`;
    }
    
    html += `       </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-primary text-white py-1">
                                <h6 class="card-title mb-0">To</h6>
                            </div>
                            <div class="card-body text-center">
                                <div class="mb-2">
                                    <h6>Ward</h6>
                                    <div class="h4 text-primary">${transfer.to_ward_name}</div>
                                </div>
                                <div>
                                    <h6>Bed</h6>
                                    <div class="h3 text-primary">${transfer.to_bed_number}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Transfer Details -->
                <div class="card mb-3">
                    <div class="card-header bg-info text-white py-1">
                        <h6 class="card-title mb-0">Transfer Details</h6>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <h6>Reason</h6>
                            <div class="p-3 bg-light rounded">${transfer.transfer_reason}</div>
                        </div>
    `;
    
    if (transfer.special_instructions) {
        html += `<div class="mb-3">
                    <h6>Special Instructions</h6>
                    <div class="p-3 bg-light rounded">${transfer.special_instructions.replace(/\n/g, '<br>')}</div>
                </div>`;
    }
    
    if (transfer.cancellation_reason) {
        html += `<div class="mb-3">
                    <h6 class="text-danger">Cancellation Reason</h6>
                    <div class="p-3 bg-light rounded">${transfer.cancellation_reason.replace(/\n/g, '<br>')}</div>
                </div>`;
    }
    
    html += `   </div>
                </div>
                
                <div class="text-muted small">
                    <i class="fas fa-info-circle mr-1"></i>
                    Transfer ID: ${transfer.transfer_id} | Created: ${new Date(transfer.created_at).toLocaleString()}
                </div>
            </div>
        </div>
    `;
    
    modalContent.innerHTML = html;
    $('#transferDetailsModal').modal('show');
}

function printTransfer() {
    window.print();
}

function showToast(message, type = 'info') {
    const toast = $(`
        <div class="toast" role="alert" aria-live="assertive" aria-atomic="true" data-delay="3000">
            <div class="toast-header bg-${type} text-white">
                <strong class="mr-auto"><i class="fas fa-info-circle mr-2"></i>Notification</strong>
                <button type="button" class="ml-2 mb-1 close text-white" data-dismiss="toast" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="toast-body">
                ${message}
            </div>
        </div>
    `);
    
    $('.toast-container').remove();
    $('<div class="toast-container position-fixed" style="top: 20px; right: 20px; z-index: 9999;"></div>')
        .append(toast)
        .appendTo('body');
    
    toast.toast('show');
}

// Helper functions for badges (used in viewTransferDetails)
function getTransferPriorityBadge(priority) {
    switch(priority) {
        case 'emergency': return '<span class="badge badge-danger"><i class="fas fa-exclamation-triangle mr-1"></i>Emergency</span>';
        case 'urgent': return '<span class="badge badge-warning"><i class="fas fa-exclamation-circle mr-1"></i>Urgent</span>';
        case 'routine': return '<span class="badge badge-info"><i class="fas fa-clock mr-1"></i>Routine</span>';
        default: return '<span class="badge badge-secondary">' + priority + '</span>';
    }
}

function getTransferStatusBadge(status) {
    switch(status) {
        case 'completed': return '<span class="badge badge-success"><i class="fas fa-check-circle mr-1"></i>Completed</span>';
        case 'approved': return '<span class="badge badge-primary"><i class="fas fa-thumbs-up mr-1"></i>Approved</span>';
        case 'pending': return '<span class="badge badge-warning"><i class="fas fa-clock mr-1"></i>Pending</span>';
        case 'cancelled': return '<span class="badge badge-secondary"><i class="fas fa-ban mr-1"></i>Cancelled</span>';
        case 'rejected': return '<span class="badge badge-danger"><i class="fas fa-times-circle mr-1"></i>Rejected</span>';
        default: return '<span class="badge badge-light">' + status + '</span>';
    }
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + T for transfer request
    if (e.ctrlKey && e.keyCode === 84) {
        e.preventDefault();
        $('#transfer_date').focus();
    }
    // Ctrl + R for reason
    if (e.ctrlKey && e.keyCode === 82) {
        e.preventDefault();
        $('#transfer_reason').focus();
    }
    // Escape to go back
    if (e.keyCode === 27) {
        window.history.back();
    }
});
</script>

<style>
.card-warning {
    border-color: #ffc107;
}

.table-info {
    background-color: #d1ecf1 !important;
}

/* Print styles */
@media print {
    .card-header, .card-tools, .btn, form, .modal,
    .card-footer, .toast-container {
        display: none !important;
    }
    .card {
        border: none !important;
        box-shadow: none !important;
    }
    .card-body {
        padding: 0 !important;
    }
    table {
        font-size: 10px !important;
    }
}
</style>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
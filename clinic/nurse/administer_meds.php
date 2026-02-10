<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/audit_functions.php';

// Get visit_id or order_id from URL
$visit_id = intval($_GET['visit_id'] ?? 0);
$order_id = intval($_GET['order_id'] ?? 0);
$ipd_view = isset($_GET['view']) && $_GET['view'] == 'ipd';

if ($visit_id <= 0 && $order_id <= 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid parameters";
    
    header("Location: /clinic/dashboard.php");
    exit;
}

// Initialize variables
$patient_info = null;
$visit_info = null;
$prescriptions = [];
$ipd_orders = [];
$administered_meds = [];

// If order_id is provided, get visit_id from it
if ($order_id > 0) {
    $order_sql = "SELECT imo.visit_id, imo.patient_id 
                 FROM ipd_medication_orders imo 
                 WHERE imo.order_id = ?";
    $order_stmt = $mysqli->prepare($order_sql);
    $order_stmt->bind_param("i", $order_id);
    $order_stmt->execute();
    $order_result = $order_stmt->get_result();
    
    if ($order_result->num_rows > 0) {
        $order_data = $order_result->fetch_assoc();
        $visit_id = $order_data['visit_id'];
        $ipd_view = true;
    }
}

if ($visit_id > 0) {
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
                 WHERE v.visit_id = ?";
    $visit_stmt = $mysqli->prepare($visit_sql);
    $visit_stmt->bind_param("i", $visit_id);
    $visit_stmt->execute();
    $visit_result = $visit_stmt->get_result();
    
    if ($visit_result->num_rows > 0) {
        $visit_info = $visit_result->fetch_assoc();
        $patient_info = $visit_info;
        
        // Get IPD admission details if IPD
        $ipd_info = null;
        if ($visit_info['visit_type'] === 'IPD') {
            $ipd_sql = "SELECT ia.*, 
                        w.ward_name, w.ward_type,
                        b.bed_number, b.bed_type,
                        adm.user_name as admitting_provider_name,
                        att.user_name as attending_provider_name,
                        nurse.user_name as nurse_incharge_name
                        FROM ipd_admissions ia
                        LEFT JOIN wards w ON ia.ward_id = w.ward_id
                        LEFT JOIN beds b ON ia.bed_id = b.bed_id
                        LEFT JOIN users adm ON ia.admitting_provider_id = adm.user_id
                        LEFT JOIN users att ON ia.attending_provider_id = att.user_id
                        LEFT JOIN users nurse ON ia.nurse_incharge_id = nurse.user_id
                        WHERE ia.visit_id = ? AND ia.admission_status = 'ACTIVE'";
            $ipd_stmt = $mysqli->prepare($ipd_sql);
            $ipd_stmt->bind_param("i", $visit_id);
            $ipd_stmt->execute();
            $ipd_result = $ipd_stmt->get_result();
            
            if ($ipd_result->num_rows > 0) {
                $ipd_info = $ipd_result->fetch_assoc();
            }
        }
        
        // Get prescriptions based on view type
        if ($ipd_view || $visit_info['visit_type'] === 'IPD') {
            // Get IPD medication orders
            $ipd_orders_sql = "SELECT imo.*, 
                              ii.item_name, ii.item_code, ii.unit_of_measure,
                              u.user_name as ordered_by_name,
                              (SELECT COUNT(*) FROM ipd_medication_administration ima 
                               WHERE ima.order_id = imo.order_id) as administration_count,
                              (SELECT MAX(administered_at) FROM ipd_medication_administration ima 
                               WHERE ima.order_id = imo.order_id) as last_administered
                              FROM ipd_medication_orders imo
                              JOIN inventory_items ii ON imo.item_id = ii.item_id
                              JOIN users u ON imo.ordered_by = u.user_id
                              WHERE imo.visit_id = ? 
                              AND imo.status IN ('active', 'held')
                              ORDER BY imo.status = 'active' DESC, imo.start_datetime DESC";
            $ipd_orders_stmt = $mysqli->prepare($ipd_orders_sql);
            $ipd_orders_stmt->bind_param("i", $visit_id);
            $ipd_orders_stmt->execute();
            $ipd_orders_result = $ipd_orders_stmt->get_result();
            $ipd_orders = $ipd_orders_result->fetch_all(MYSQLI_ASSOC);
        } else {
            // Get OPD prescriptions
            $prescriptions_sql = "SELECT p.*, 
                                 d.drug_name, d.drug_generic_name, d.drug_form, d.drug_strength, d.drug_category,
                                 pi.pi_dosage, pi.pi_frequency, pi.pi_duration, pi.pi_instructions, pi.pi_duration_unit,
                                 pi.pi_dispensed_quantity,
                                 doc.user_name as doctor_name
                                 FROM prescriptions p
                                 JOIN prescription_items pi ON p.prescription_id = pi.pi_prescription_id
                                 JOIN drugs d ON pi.pi_drug_id = d.drug_id
                                 JOIN users doc ON p.prescription_doctor_id = doc.user_id
                                 WHERE p.prescription_visit_id = ? 
                                 AND p.prescription_status IN ('active', 'pending', 'partial')
                                 ORDER BY p.prescription_priority DESC, p.prescription_date DESC";
            $prescriptions_stmt = $mysqli->prepare($prescriptions_sql);
            $prescriptions_stmt->bind_param("i", $visit_id);
            $prescriptions_stmt->execute();
            $prescriptions_result = $prescriptions_stmt->get_result();
            $prescriptions = $prescriptions_result->fetch_all(MYSQLI_ASSOC);
        }
        
        // Get administration history based on view type
        if ($ipd_view || $visit_info['visit_type'] === 'IPD') {
            // Get IPD administration history
            $administered_sql = "SELECT ima.*, 
                                ii.item_name, ii.item_code,
                                imo.dose, imo.frequency, imo.route,
                                u.user_name as administered_by_name
                                FROM ipd_medication_administration ima
                                JOIN ipd_medication_orders imo ON ima.order_id = imo.order_id
                                JOIN inventory_items ii ON imo.item_id = ii.item_id
                                JOIN users u ON ima.administered_by = u.user_id
                                WHERE imo.visit_id = ?
                                ORDER BY ima.administered_at DESC
                                LIMIT 50";
            $administered_stmt = $mysqli->prepare($administered_sql);
            $administered_stmt->bind_param("i", $visit_id);
            $administered_stmt->execute();
            $administered_result = $administered_stmt->get_result();
            $administered_meds = $administered_result->fetch_all(MYSQLI_ASSOC);
        } else {
            // Get OPD administration history (if table exists)
            $check_table_sql = "SHOW TABLES LIKE 'medication_administration'";
            $check_result = $mysqli->query($check_table_sql);
            
            if ($check_result && $check_result->num_rows > 0) {
                $administered_sql = "SELECT ma.*, 
                                    d.drug_name, d.drug_strength, d.drug_form,
                                    pi.pi_dosage, pi.pi_frequency,
                                    p.prescription_id,
                                    u.user_name as administered_by_name
                                    FROM medication_administration ma
                                    JOIN prescriptions p ON ma.medication_id = p.prescription_id
                                    JOIN prescription_items pi ON p.prescription_id = pi.pi_prescription_id
                                    JOIN drugs d ON pi.pi_drug_id = d.drug_id
                                    JOIN users u ON ma.administered_by = u.user_id
                                    WHERE p.prescription_visit_id = ?
                                    ORDER BY ma.administered_time DESC
                                    LIMIT 50";
                $administered_stmt = $mysqli->prepare($administered_sql);
                $administered_stmt->bind_param("i", $visit_id);
                $administered_stmt->execute();
                $administered_result = $administered_stmt->get_result();
                $administered_meds = $administered_result->fetch_all(MYSQLI_ASSOC);
            }
        }
    }
}

// Handle form submission for administering medication
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['administer_ipd_order'])) {
        $order_id = intval($_POST['order_id']);
        $administered_at = !empty($_POST['administered_at']) ? $_POST['administered_at'] : date('Y-m-d H:i:s');
        $quantity = floatval($_POST['quantity'] ?? 1);
        $notes = !empty($_POST['notes']) ? trim($_POST['notes']) : null;
        $status = $_POST['status'] ?? 'given';
        $administered_by = $_SESSION['user_id'];
        
        // Get order details for audit log
        $order_details_sql = "SELECT imo.*, p.patient_id 
                             FROM ipd_medication_orders imo
                             JOIN patients p ON imo.patient_id = p.patient_id
                             WHERE imo.order_id = ?";
        $order_details_stmt = $mysqli->prepare($order_details_sql);
        $order_details_stmt->bind_param("i", $order_id);
        $order_details_stmt->execute();
        $order_details_result = $order_details_stmt->get_result();
        $order_details = $order_details_result->fetch_assoc();
        
        if (!$order_details) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Medication order not found";
            header("Location: administer_meds.php?order_id=" . $order_id);
            exit;
        }
        
        // Start transaction
        $mysqli->begin_transaction();
        
        try {
            // Insert administration record
            $administer_sql = "INSERT INTO ipd_medication_administration 
                              (order_id, administered_at, quantity, notes, status, administered_by)
                              VALUES (?, ?, ?, ?, ?, ?)";
            $administer_stmt = $mysqli->prepare($administer_sql);
            $administer_stmt->bind_param("isdssi", 
                $order_id, $administered_at, $quantity, $notes, $status, $administered_by);
            
            if (!$administer_stmt->execute()) {
                throw new Exception("Failed to record administration: " . $mysqli->error);
            }
            
            $administration_id = $mysqli->insert_id;
            
            // Update inventory stock if medication was given
            if ($status == 'given') {
                // Check if we need to deduct from inventory
                $item_sql = "SELECT ii.item_id, ii.requires_batch, ii.item_name 
                            FROM ipd_medication_orders imo
                            JOIN inventory_items ii ON imo.item_id = ii.item_id
                            WHERE imo.order_id = ?";
                $item_stmt = $mysqli->prepare($item_sql);
                $item_stmt->bind_param("i", $order_id);
                $item_stmt->execute();
                $item_result = $item_stmt->get_result();
                $item = $item_result->fetch_assoc();
                
                if ($item && $item['requires_batch']) {
                    // Get batch and location info
                    $batch_info_sql = "SELECT ils.batch_id, ils.location_id 
                                      FROM ipd_medication_orders imo
                                      JOIN prescription_items pi ON imo.prescription_id = pi.pi_prescription_id
                                      LEFT JOIN inventory_location_stock ils ON pi.pi_batch_id = ils.batch_id AND pi.pi_location_id = ils.location_id
                                      WHERE imo.order_id = ? AND ils.quantity > 0
                                      ORDER BY ils.last_movement_at
                                      LIMIT 1";
                    $batch_info_stmt = $mysqli->prepare($batch_info_sql);
                    $batch_info_stmt->bind_param("i", $order_id);
                    $batch_info_stmt->execute();
                    $batch_info_result = $batch_info_stmt->get_result();
                    $batch_info = $batch_info_result->fetch_assoc();
                    
                    if ($batch_info) {
                        // Deduct from stock
                        $deduct_sql = "UPDATE inventory_location_stock 
                                      SET quantity = quantity - ?,
                                          last_movement_at = NOW()
                                      WHERE batch_id = ? AND location_id = ?";
                        $deduct_stmt = $mysqli->prepare($deduct_sql);
                        $deduct_stmt->bind_param("dii", $quantity, $batch_info['batch_id'], $batch_info['location_id']);
                        
                        if (!$deduct_stmt->execute()) {
                            throw new Exception("Failed to update inventory stock");
                        }
                        
                        // Update administration record with batch/location info
                        $update_admin_sql = "UPDATE ipd_medication_administration 
                                            SET batch_id = ?, location_id = ?
                                            WHERE administration_id = ?";
                        $update_admin_stmt = $mysqli->prepare($update_admin_sql);
                        $update_admin_stmt->bind_param("iii", 
                            $batch_info['batch_id'], $batch_info['location_id'], $administration_id);
                        $update_admin_stmt->execute();
                    }
                }
            }
            
            // Commit transaction
            $mysqli->commit();
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Medication administration recorded successfully";
            
            // Redirect back to same page
            if ($order_id > 0) {
                header("Location: administer_meds.php?order_id=" . $order_id);
            } else {
                header("Location: administer_meds.php?visit_id=" . $visit_id);
            }
            exit;
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $mysqli->rollback();
            
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error: " . $e->getMessage();
        }
    }
}

// Get patient full name
$full_name = '';
$age = '';
$visit_number = '';

if ($patient_info) {
    $full_name = $patient_info['first_name'] . 
                ($patient_info['middle_name'] ? ' ' . $patient_info['middle_name'] : '') . 
                ' ' . $patient_info['last_name'];
    
    // Calculate age
    if (!empty($patient_info['date_of_birth'])) {
        $birthDate = new DateTime($patient_info['date_of_birth']);
        $today = new DateTime();
        $age = $today->diff($birthDate)->y . ' years';
    }
    
    $visit_number = $visit_info['visit_number'] ?? '';
}

// Functions for status badges
function getPrescriptionStatusBadge($status, $priority = null) {
    $badge_class = '';
    
    switch(strtolower($status)) {
        case 'active':
            $badge_class = 'success';
            break;
        case 'pending':
            $badge_class = 'warning';
            break;
        case 'partial':
            $badge_class = 'info';
            break;
        case 'completed':
            $badge_class = 'secondary';
            break;
        case 'cancelled':
            $badge_class = 'danger';
            break;
        case 'dispensed':
            $badge_class = 'primary';
            break;
        default:
            $badge_class = 'light';
    }
    
    $badge = '<span class="badge badge-' . $badge_class . '">' . strtoupper($status) . '</span>';
    
    if ($priority && $priority !== 'routine') {
        $priority_class = $priority === 'urgent' ? 'warning' : 'danger';
        $badge .= ' <span class="badge badge-' . $priority_class . '">' . strtoupper($priority) . '</span>';
    }
    
    return $badge;
}

function getIPDOrderStatusBadge($status) {
    $badge_class = '';
    $icon = '';
    
    switch($status) {
        case 'active': 
            $badge_class = 'success';
            $icon = 'fa-play-circle';
            break;
        case 'changed': 
            $badge_class = 'warning';
            $icon = 'fa-exchange-alt';
            break;
        case 'stopped': 
            $badge_class = 'danger';
            $icon = 'fa-stop-circle';
            break;
        case 'completed': 
            $badge_class = 'secondary';
            $icon = 'fa-check-circle';
            break;
        case 'held': 
            $badge_class = 'info';
            $icon = 'fa-pause-circle';
            break;
        default: 
            $badge_class = 'light';
            $icon = 'fa-question-circle';
    }
    
    return '<span class="badge badge-' . $badge_class . '"><i class="fas ' . $icon . ' mr-1"></i>' . strtoupper($status) . '</span>';
}

function getAdministrationStatusBadge($status) {
    $badge_class = '';
    $icon = '';
    
    switch($status) {
        case 'given':
            $badge_class = 'success';
            $icon = 'fa-check';
            break;
        case 'missed':
            $badge_class = 'warning';
            $icon = 'fa-times';
            break;
        case 'refused':
            $badge_class = 'danger';
            $icon = 'fa-ban';
            break;
        case 'held':
            $badge_class = 'info';
            $icon = 'fa-pause';
            break;
        default:
            $badge_class = 'secondary';
            $icon = 'fa-question';
    }
    
    return '<span class="badge badge-' . $badge_class . '"><i class="fas ' . $icon . ' mr-1"></i>' . strtoupper($status) . '</span>';
}

// Function to calculate next dose time for IPD orders
function calculateNextIPDDose($frequency, $last_administered = null, $start_datetime = null) {
    if (!$frequency) return null;
    
    $base_time = $last_administered ? new DateTime($last_administered) : ($start_datetime ? new DateTime($start_datetime) : new DateTime());
    $next_dose = clone $base_time;
    
    $frequency = strtolower($frequency);
    
    if (strpos($frequency, 'hour') !== false) {
        preg_match('/(\d+)/', $frequency, $matches);
        $hours = $matches[1] ?? 8;
        $next_dose->modify('+' . $hours . ' hours');
    } elseif (strpos($frequency, 'daily') !== false || strpos($frequency, 'q24h') !== false) {
        $next_dose->modify('+1 day');
    } elseif (strpos($frequency, 'bid') !== false || strpos($frequency, 'twice') !== false || strpos($frequency, 'q12h') !== false) {
        $next_dose->modify('+12 hours');
    } elseif (strpos($frequency, 'tid') !== false || strpos($frequency, 'thrice') !== false || strpos($frequency, 'q8h') !== false) {
        $next_dose->modify('+8 hours');
    } elseif (strpos($frequency, 'qid') !== false || strpos($frequency, 'q6h') !== false) {
        $next_dose->modify('+6 hours');
    } elseif (strpos($frequency, 'weekly') !== false) {
        $next_dose->modify('+7 days');
    } else {
        $next_dose->modify('+1 day');
    }
    
    return $next_dose;
}
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0 text-white">
                <i class="fas fa-fw fa-syringe mr-2"></i>
                <?php echo $ipd_view ? 'IPD ' : ''; ?>Medication Administration
                <?php if ($patient_info): ?>
                    : <?php echo htmlspecialchars($patient_info['patient_mrn']); ?>
                <?php endif; ?>
            </h3>
            <div class="card-tools">
                <div class="btn-group">
                    <button type="button" class="btn btn-light" onclick="window.history.back()">
                        <i class="fas fa-arrow-left mr-2"></i>Back
                    </button>
                    <button type="button" class="btn btn-success" onclick="window.print()">
                        <i class="fas fa-print mr-2"></i>Print MAR
                    </button>
                    <?php if ($visit_info && $visit_info['visit_type'] === 'IPD'): ?>
                        <div class="dropdown ml-2">
                            <button class="btn btn-info dropdown-toggle" type="button" data-toggle="dropdown">
                                <i class="fas fa-exchange-alt mr-2"></i>Switch View
                            </button>
                            <div class="dropdown-menu">
                                <a class="dropdown-item" href="administer_meds.php?visit_id=<?php echo $visit_id; ?>&view=ipd">
                                    <i class="fas fa-procedures mr-2"></i>IPD Orders View
                                </a>
                                <a class="dropdown-item" href="administer_meds.php?visit_id=<?php echo $visit_id; ?>">
                                    <i class="fas fa-file-prescription mr-2"></i>Prescriptions View
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                    <a href="ipd_medication_orders.php" class="btn btn-warning ml-2">
                        <i class="fas fa-clipboard-list mr-2"></i>IPD Orders
                    </a>
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

        <?php if ($patient_info): ?>
        <!-- Patient and Visit Info -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card bg-light">
                    <div class="card-body py-2">
                        <div class="row">
                            <div class="col-md-8">
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
                                                <th class="text-muted">Age/Sex:</th>
                                                <td>
                                                    <span class="badge badge-secondary"><?php echo $age ?: 'N/A'; ?></span>
                                                    <span class="badge badge-secondary ml-1"><?php echo htmlspecialchars($patient_info['sex']); ?></span>
                                                </td>
                                            </tr>
                                        </table>
                                    </div>
                                    <div class="col-md-6">
                                        <table class="table table-sm table-borderless mb-0">
                                            <tr>
                                                <th width="40%" class="text-muted">Visit Type:</th>
                                                <td>
                                                    <span class="badge badge-<?php 
                                                        echo $visit_info['visit_type'] == 'OPD' ? 'primary' : 
                                                             ($visit_info['visit_type'] == 'IPD' ? 'success' : 'danger'); 
                                                    ?>">
                                                        <?php echo htmlspecialchars($visit_info['visit_type']); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th class="text-muted">Visit #:</th>
                                                <td><?php echo htmlspecialchars($visit_number); ?></td>
                                            </tr>
                                            <?php if (isset($ipd_info) && $ipd_info): ?>
                                            <tr>
                                                <th class="text-muted">Ward/Bed:</th>
                                                <td>
                                                    <?php if ($ipd_info['ward_name']): ?>
                                                        <span class="badge badge-primary"><?php echo htmlspecialchars($ipd_info['ward_name']); ?></span>
                                                    <?php endif; ?>
                                                    <?php if ($ipd_info['bed_number']): ?>
                                                        <span class="badge badge-secondary ml-1"><?php echo htmlspecialchars($ipd_info['bed_number']); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endif; ?>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 text-right">
                                <div class="mt-2">
                                    <?php if ($ipd_view): ?>
                                        <span class="h5">
                                            <i class="fas fa-clipboard-list text-primary mr-1"></i>
                                            <span class="badge badge-light"><?php echo count($ipd_orders); ?> IPD Orders</span>
                                        </span>
                                        <br>
                                        <span class="h5">
                                            <i class="fas fa-history text-info mr-1"></i>
                                            <span class="badge badge-light"><?php echo count($administered_meds); ?> Administrations</span>
                                        </span>
                                    <?php else: ?>
                                        <span class="h5">
                                            <i class="fas fa-prescription text-primary mr-1"></i>
                                            <span class="badge badge-light"><?php echo count($prescriptions); ?> Active Rx</span>
                                        </span>
                                        <br>
                                        <span class="h5">
                                            <i class="fas fa-check-circle text-success mr-1"></i>
                                            <span class="badge badge-light"><?php echo count($administered_meds); ?> Administered</span>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Active Orders/Prescriptions -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-success py-2">
                        <h4 class="card-title mb-0 text-white">
                            <?php if ($ipd_view): ?>
                                <i class="fas fa-clipboard-list mr-2"></i>Active IPD Medication Orders
                                <span class="badge badge-light float-right"><?php echo count($ipd_orders); ?></span>
                            <?php else: ?>
                                <i class="fas fa-list-alt mr-2"></i>Active Prescriptions
                                <span class="badge badge-light float-right"><?php echo count($prescriptions); ?></span>
                            <?php endif; ?>
                        </h4>
                    </div>
                    <div class="card-body p-0">
                        <?php if ($ipd_view): ?>
                            <!-- IPD Orders View -->
                            <?php if (!empty($ipd_orders)): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead class="bg-light">
                                            <tr>
                                                <th>Medication</th>
                                                <th>Dose/Frequency</th>
                                                <th>Status</th>
                                                <th class="text-center">Next Dose</th>
                                                <th class="text-center">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $now = new DateTime();
                                            foreach ($ipd_orders as $order): 
                                                $last_administered = $order['last_administered'];
                                                $start_datetime = $order['start_datetime'];
                                                $next_dose = calculateNextIPDDose($order['frequency'], $last_administered, $start_datetime);
                                                
                                                $is_overdue = false;
                                                $is_due_soon = false;
                                                
                                                if ($next_dose) {
                                                    if ($next_dose < $now) {
                                                        $is_overdue = true;
                                                    } elseif ($next_dose <= (new DateTime())->modify('+1 hour')) {
                                                        $is_due_soon = true;
                                                    }
                                                }
                                                
                                                $row_class = '';
                                                if ($is_overdue) $row_class = 'table-danger';
                                                elseif ($is_due_soon) $row_class = 'table-warning';
                                            ?>
                                                <tr class="<?php echo $row_class; ?>">
                                                    <td>
                                                        <div class="font-weight-bold">
                                                            <?php echo htmlspecialchars($order['item_name']); ?>
                                                        </div>
                                                        <small class="text-muted"><?php echo htmlspecialchars($order['item_code']); ?></small>
                                                        <br>
                                                        <small class="text-muted">
                                                            <i class="fas fa-user-md mr-1"></i>
                                                            <?php echo htmlspecialchars($order['ordered_by_name']); ?>
                                                        </small>
                                                    </td>
                                                    <td>
                                                        <div><?php echo htmlspecialchars($order['dose']); ?> <?php echo htmlspecialchars($order['route']); ?></div>
                                                        <small class="text-muted"><?php echo htmlspecialchars($order['frequency']); ?></small>
                                                        <?php if ($order['administration_count'] > 0): ?>
                                                            <div class="mt-1">
                                                                <small class="text-info">
                                                                    <i class="fas fa-history mr-1"></i>
                                                                    <?php echo $order['administration_count']; ?> doses given
                                                                </small>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php echo getIPDOrderStatusBadge($order['status']); ?>
                                                        <br>
                                                        <small class="text-muted">
                                                            <?php echo date('M j', strtotime($order['start_datetime'])); ?>
                                                        </small>
                                                    </td>
                                                    <td class="text-center">
                                                        <?php if ($next_dose && $order['status'] == 'active'): ?>
                                                            <span class="badge badge-<?php echo $is_overdue ? 'danger' : ($is_due_soon ? 'warning' : 'info'); ?>">
                                                                <?php echo date('H:i', $next_dose->getTimestamp()); ?>
                                                            </span>
                                                        <?php elseif ($order['status'] == 'held'): ?>
                                                            <span class="badge badge-info">HELD</span>
                                                        <?php else: ?>
                                                            <span class="text-muted">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <?php if ($order['status'] == 'active'): ?>
                                                            <button type="button" class="btn btn-sm btn-success" 
                                                                    data-toggle="modal" data-target="#administerModal"
                                                                    onclick="prepareAdministerModal(<?php echo htmlspecialchars(json_encode($order)); ?>, <?php echo $is_overdue ? 'true' : 'false'; ?>)"
                                                                    title="Administer Medication">
                                                                <i class="fas fa-syringe"></i>
                                                            </button>
                                                        <?php elseif ($order['status'] == 'held'): ?>
                                                            <button type="button" class="btn btn-sm btn-warning" title="Order on Hold">
                                                                <i class="fas fa-pause"></i>
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
                                    <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                                    <h5 class="text-muted">No Active IPD Orders</h5>
                                    <p class="text-muted">No active IPD medication orders for this patient.</p>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <!-- OPD Prescriptions View -->
                            <?php if (!empty($prescriptions)): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead class="bg-light">
                                            <tr>
                                                <th>Medication</th>
                                                <th>Schedule</th>
                                                <th>Status</th>
                                                <th class="text-center">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($prescriptions as $rx): ?>
                                                <tr>
                                                    <td>
                                                        <div class="font-weight-bold">
                                                            <?php echo htmlspecialchars($rx['drug_name']); ?>
                                                            <?php if ($rx['drug_generic_name']): ?>
                                                                <br>
                                                                <small class="text-muted">(<?php echo htmlspecialchars($rx['drug_generic_name']); ?>)</small>
                                                            <?php endif; ?>
                                                        </div>
                                                        <small class="text-muted">
                                                            <?php 
                                                            echo htmlspecialchars($rx['drug_strength']) . ' ' . 
                                                                 htmlspecialchars($rx['drug_form']);
                                                            ?>
                                                        </small>
                                                    </td>
                                                    <td>
                                                        <div>
                                                            <?php echo htmlspecialchars($rx['pi_dosage'] ?? ''); ?> 
                                                            <?php echo htmlspecialchars($rx['pi_frequency'] ?? ''); ?>
                                                        </div>
                                                        <?php if ($rx['pi_duration']): ?>
                                                            <small class="text-muted">
                                                                <?php echo $rx['pi_duration']; ?> <?php echo $rx['pi_duration_unit'] ?? 'days'; ?>
                                                            </small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php echo getPrescriptionStatusBadge($rx['prescription_status'], $rx['prescription_priority']); ?>
                                                        <br>
                                                        <small class="text-muted">
                                                            <?php echo date('M j', strtotime($rx['prescription_date'])); ?>
                                                        </small>
                                                    </td>
                                                    <td class="text-center">
                                                        <?php if (in_array(strtolower($rx['prescription_status']), ['active', 'pending', 'partial'])): ?>
                                                            <button type="button" class="btn btn-sm btn-success" 
                                                                    data-toggle="modal" data-target="#administerModal"
                                                                    onclick="prepareAdministerModalLegacy(<?php echo htmlspecialchars(json_encode($rx)); ?>)"
                                                                    title="Administer Medication">
                                                                <i class="fas fa-syringe"></i>
                                                            </button>
                                                        <?php else: ?>
                                                            <span class="text-muted">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-pills fa-3x text-muted mb-3"></i>
                                    <h5 class="text-muted">No Active Prescriptions</h5>
                                    <p class="text-muted">No prescriptions are currently active for this visit.</p>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Administration History -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-info py-2">
                        <h4 class="card-title mb-0 text-white">
                            <i class="fas fa-history mr-2"></i>Administration History
                            <span class="badge badge-light float-right"><?php echo count($administered_meds); ?></span>
                        </h4>
                    </div>
                    <div class="card-body p-0">
                        <?php if (!empty($administered_meds)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Medication</th>
                                            <th>Time</th>
                                            <th>By</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($administered_meds as $admin): ?>
                                            <tr>
                                                <td>
                                                    <div class="font-weight-bold">
                                                        <?php echo htmlspecialchars($admin['item_name'] ?? $admin['drug_name'] ?? 'Unknown'); ?>
                                                    </div>
                                                    <small class="text-muted">
                                                        <?php if ($ipd_view): ?>
                                                            <?php echo htmlspecialchars($admin['dose'] ?? ''); ?> | 
                                                            <?php echo htmlspecialchars($admin['frequency'] ?? ''); ?>
                                                        <?php else: ?>
                                                            <?php echo htmlspecialchars($admin['drug_strength'] ?? ''); ?> | 
                                                            <?php echo htmlspecialchars($admin['pi_dosage'] ?? ''); ?>
                                                        <?php endif; ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <div>
                                                        <?php echo date('M j', strtotime($admin['administered_at'] ?? $admin['administered_time'])); ?>
                                                    </div>
                                                    <small class="text-muted">
                                                        <?php echo date('H:i', strtotime($admin['administered_at'] ?? $admin['administered_time'])); ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($admin['administered_by_name'] ?? 'Unknown'); ?>
                                                </td>
                                                <td>
                                                    <?php if ($ipd_view): ?>
                                                        <?php echo getAdministrationStatusBadge($admin['status'] ?? 'given'); ?>
                                                    <?php else: ?>
                                                        <?php if (isset($admin['refused']) && $admin['refused']): ?>
                                                            <span class="badge badge-danger">REFUSED</span>
                                                        <?php else: ?>
                                                            <span class="badge badge-success">GIVEN</span>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-history fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">No Administration History</h5>
                                <p class="text-muted">No medications have been administered yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Quick Administration Form (for IPD) -->
                <?php if ($ipd_view && !empty($ipd_orders)): ?>
                <div class="card mt-4">
                    <div class="card-header bg-warning py-2">
                        <h4 class="card-title mb-0">
                            <i class="fas fa-bolt mr-2"></i>Quick Administration
                        </h4>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="quickAdminForm">
                            <div class="form-group">
                                <label for="quick_order_id">Select Medication Order</label>
                                <select class="form-control select2" id="quick_order_id" name="order_id" required>
                                    <option value="">-- Select Order --</option>
                                    <?php foreach ($ipd_orders as $order): ?>
                                        <?php if ($order['status'] == 'active'): ?>
                                            <option value="<?php echo $order['order_id']; ?>">
                                                <?php echo htmlspecialchars($order['item_name'] . ' - ' . $order['dose'] . ' ' . $order['route'] . ' ' . $order['frequency']); ?>
                                            </option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="quick_administered_at">Time</label>
                                        <input type="datetime-local" class="form-control" id="quick_administered_at" 
                                               name="administered_at" value="<?php echo date('Y-m-d\TH:i'); ?>">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="quick_status">Status</label>
                                        <select class="form-control" id="quick_status" name="status">
                                            <option value="given">Given</option>
                                            <option value="missed">Missed</option>
                                            <option value="refused">Refused</option>
                                            <option value="held">Held</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="quick_notes">Notes</label>
                                <textarea class="form-control" id="quick_notes" name="notes" 
                                          rows="2" placeholder="Administration notes..."></textarea>
                            </div>
                            
                            <button type="submit" name="administer_ipd_order" class="btn btn-success btn-block">
                                <i class="fas fa-save mr-2"></i>Record Administration
                            </button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php else: ?>
        <div class="text-center py-5">
            <i class="fas fa-user-injured fa-3x text-muted mb-3"></i>
            <h5 class="text-muted">Patient Information Not Found</h5>
            <p class="text-muted">Unable to retrieve patient information for the specified visit or order.</p>
            <a href="/clinic/dashboard.php" class="btn btn-primary">
                <i class="fas fa-home mr-2"></i>Return to Dashboard
            </a>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Administer Medication Modal (for IPD) -->
<div class="modal fade" id="administerModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">Administer Medication</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" id="modal_order_id" name="order_id">
                    
                    <div class="text-center mb-3">
                        <h4 id="modal_item_name"></h4>
                        <h5 id="modal_item_details" class="text-muted"></h5>
                        <div id="modal_order_details" class="text-muted"></div>
                    </div>
                    
                    <div class="form-group">
                        <label for="modal_administered_at">Administration Time</label>
                        <input type="datetime-local" class="form-control" id="modal_administered_at" 
                               name="administered_at" value="<?php echo date('Y-m-d\TH:i'); ?>" required>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="modal_quantity">Quantity</label>
                                <input type="number" class="form-control" id="modal_quantity" 
                                       name="quantity" value="1" min="0.1" step="0.1" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="modal_status">Status</label>
                                <select class="form-control" id="modal_status" name="status">
                                    <option value="given">Given</option>
                                    <option value="missed">Missed</option>
                                    <option value="refused">Refused</option>
                                    <option value="held">Held</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="modal_notes">Notes</label>
                        <textarea class="form-control" id="modal_notes" name="notes" 
                                  rows="3" placeholder="Document any observations, patient response, or issues..."></textarea>
                    </div>
                    
                    <div class="alert alert-warning mt-3" id="overdue_warning" style="display: none;">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        <strong>Warning:</strong> This medication is overdue for administration!
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" name="administer_ipd_order" class="btn btn-success">
                        <i class="fas fa-save mr-2"></i>Record Administration
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Auto-close alerts after 5 seconds
    setTimeout(function() {
        $('.alert').fadeOut('slow');
    }, 5000);

    // Initialize Select2
    $('.select2').select2({
        theme: 'bootstrap4'
    });

    // Initialize datetime pickers
    $('#quick_administered_at, #modal_administered_at').flatpickr({
        enableTime: true,
        dateFormat: 'Y-m-d H:i',
        time_24hr: true,
        minuteIncrement: 5
    });

    // Form validation
    $('#quickAdminForm').validate({
        rules: {
            order_id: {
                required: true
            }
        }
    });
});

function prepareAdministerModal(order, isOverdue = false) {
    $('#modal_order_id').val(order.order_id);
    $('#modal_item_name').text(order.item_name + ' (' + order.item_code + ')');
    $('#modal_item_details').text(order.dose + ' ' + order.route + ' - ' + order.frequency);
    $('#modal_order_details').html('<i class="fas fa-user-md mr-1"></i>Ordered by: ' + order.ordered_by_name + 
                                   ' | Started: ' + new Date(order.start_datetime).toLocaleDateString());
    $('#modal_quantity').val(1);
    $('#modal_status').val('given');
    $('#modal_notes').val('');
    
    // Set default time to current time
    $('#modal_administered_at').val(new Date().toISOString().slice(0, 16));
    
    // Show overdue warning if applicable
    if (isOverdue) {
        $('#overdue_warning').show();
    } else {
        $('#overdue_warning').hide();
    }
}

function prepareAdministerModalLegacy(prescription) {
    // For OPD prescriptions (legacy system)
    alert('This feature is for OPD prescriptions. Please use the quick form or contact pharmacy for OPD medications.');
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + S for save (submit quick form)
    if (e.ctrlKey && e.keyCode === 83 && !$('#administerModal').is(':visible')) {
        e.preventDefault();
        <?php if ($ipd_view): ?>
            if ($('#quickAdminForm').valid()) {
                $('#quickAdminForm').submit();
            }
        <?php endif; ?>
    }
    // Ctrl + A for administer (open modal for first active order)
    if (e.ctrlKey && e.keyCode === 65) {
        e.preventDefault();
        <?php if ($ipd_view && !empty($ipd_orders)): ?>
            const firstOrder = <?php echo !empty($ipd_orders) ? json_encode($ipd_orders[0]) : 'null'; ?>;
            if (firstOrder && firstOrder.status == 'active') {
                prepareAdministerModal(firstOrder, false);
                $('#administerModal').modal('show');
            }
        <?php endif; ?>
    }
    // Ctrl + P for print
    if (e.ctrlKey && e.keyCode === 80) {
        e.preventDefault();
        window.print();
    }
});

// Auto-refresh page every 30 seconds for real-time updates
<?php if (!empty($ipd_orders) || !empty($prescriptions)): ?>
setTimeout(function() {
    location.reload();
}, 30000);
<?php endif; ?>
</script>

<style>
.table-danger {
    background-color: #f8d7da !important;
}
.table-warning {
    background-color: #fff3cd !important;
}
.select2-container--bootstrap4 .select2-selection {
    height: calc(2.25rem + 2px) !important;
}
/* Print styles for MAR */
@media print {
    .card-header, .card-tools, .btn, form, .modal {
        display: none !important;
    }
    .card {
        border: none !important;
    }
    .card-body {
        padding: 0 !important;
    }
    table {
        font-size: 12px !important;
    }
}
</style>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
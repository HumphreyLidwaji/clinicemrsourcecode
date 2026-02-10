<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Default Column Sortby/Order Filter
$sort = "imo.start_datetime";
$order = "DESC";

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/audit_functions.php';

// AUDIT LOG: Access to IPD medication orders page
audit_log($mysqli, [
    'user_id'     => $_SESSION['user_id'] ?? null,
    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
    'action'      => 'VIEW',
    'module'      => 'IPD Medication Orders',
    'table_name'  => 'ipd_medication_orders',
    'entity_type' => 'ipd_medication_order_list',
    'record_id'   => null,
    'patient_id'  => null,
    'visit_id'    => null,
    'description' => "Accessed IPD medication orders page",
    'status'      => 'SUCCESS',
    'old_values'  => null,
    'new_values'  => [
        'sort_by' => $sort,
        'sort_order' => $order
    ]
]);

// Filter parameters
$status_filter = $_GET['status'] ?? '';
$patient_filter = $_GET['patient'] ?? '';
$ward_filter = $_GET['ward'] ?? '';
$item_filter = $_GET['item'] ?? '';
$admission_status_filter = $_GET['admission_status'] ?? '';

// Date Range Filter
$dtf = sanitizeInput($_GET['dtf'] ?? '');
$dtt = sanitizeInput($_GET['dtt'] ?? '');
$canned_date = $_GET['canned_date'] ?? '';

if (!empty($dtf) && !empty($dtt)) {
    $date_query = "AND DATE(imo.start_datetime) BETWEEN '$dtf' AND '$dtt'";
} else if (!empty($canned_date)) {
    switch($canned_date) {
        case 'today':
            $date_query = "AND DATE(imo.start_datetime) = CURDATE()";
            break;
        case 'yesterday':
            $date_query = "AND DATE(imo.start_datetime) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
            break;
        case 'thisweek':
            $date_query = "AND YEARWEEK(imo.start_datetime, 1) = YEARWEEK(CURDATE(), 1)";
            break;
        case 'lastweek':
            $date_query = "AND YEARWEEK(imo.start_datetime, 1) = YEARWEEK(CURDATE(), 1) - 1";
            break;
        case 'thismonth':
            $date_query = "AND MONTH(imo.start_datetime) = MONTH(CURDATE()) AND YEAR(imo.start_datetime) = YEAR(CURDATE())";
            break;
        case 'lastmonth':
            $date_query = "AND MONTH(imo.start_datetime) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND YEAR(imo.start_datetime) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))";
            break;
        default:
            $date_query = '';
    }
} else {
    $date_query = '';
}

// Status Filter
if ($status_filter) {
    $status_query = "AND imo.status = '" . sanitizeInput($status_filter) . "'";
} else {
    $status_query = '';
}

// Patient Filter
if ($patient_filter) {
    $patient_query = "AND imo.patient_id = " . intval($patient_filter);
} else {
    $patient_query = '';
}

// Ward Filter
if ($ward_filter) {
    $ward_query = "AND ia.ward_id = " . intval($ward_filter);
} else {
    $ward_query = '';
}

// Item Filter
if ($item_filter) {
    $item_query = "AND imo.item_id = " . intval($item_filter);
} else {
    $item_query = '';
}

// Admission Status Filter
if ($admission_status_filter) {
    $admission_status_query = "AND ia.admission_status = '" . sanitizeInput($admission_status_filter) . "'";
} else {
    $admission_status_query = '';
}

// Search Query
$q = sanitizeInput($_GET['q'] ?? '');
if (!empty($q)) {
    $search_query = "AND (
        p.first_name LIKE '%$q%' 
        OR p.last_name LIKE '%$q%'
        OR p.patient_mrn LIKE '%$q%'
        OR u.user_name LIKE '%$q%'
        OR ii.item_name LIKE '%$q%'
        OR ii.item_code LIKE '%$q%'
        OR w.ward_name LIKE '%$q%'
        OR ia.admission_number LIKE '%$q%'
    )";
} else {
    $search_query = '';
}

// Main query for IPD medication orders
$sql = mysqli_query(
    $mysqli,
    "
    SELECT SQL_CALC_FOUND_ROWS imo.*,
           p.patient_id, p.first_name, p.last_name, p.patient_mrn, p.phone_primary,
           p.date_of_birth, p.sex,
           ii.item_id, ii.item_name, ii.item_code, ii.unit_of_measure,
           u.user_name as ordered_by_name,
           u2.user_name as stopped_by_name,
           ia.ipd_admission_id, ia.admission_number, ia.admission_datetime, ia.admission_status,
           w.ward_name, w.ward_type,
           b.bed_number, b.bed_type,
           v.visit_id, v.visit_number, v.visit_datetime,
           imo_old.item_name as changed_from_name,
           -- Count administrations
           (SELECT COUNT(*) FROM ipd_medication_administration ima WHERE ima.order_id = imo.order_id) as administration_count,
           -- Get last administration time
           (SELECT MAX(administered_at) FROM ipd_medication_administration ima WHERE ima.order_id = imo.order_id) as last_administered
    FROM ipd_medication_orders imo
    JOIN patients p ON imo.patient_id = p.patient_id
    JOIN inventory_items ii ON imo.item_id = ii.item_id
    JOIN users u ON imo.ordered_by = u.user_id
    LEFT JOIN users u2 ON imo.stopped_by = u2.user_id
    JOIN visits v ON imo.visit_id = v.visit_id
    LEFT JOIN ipd_admissions ia ON imo.visit_id = ia.visit_id AND ia.admission_status IN ('ACTIVE', 'DISCHARGED')
    LEFT JOIN wards w ON ia.ward_id = w.ward_id
    LEFT JOIN beds b ON ia.bed_id = b.bed_id
    LEFT JOIN ipd_medication_orders imo_old ON imo.changed_from_order_id = imo_old.order_id
    LEFT JOIN inventory_items ii_old ON imo_old.item_id = ii_old.item_id
    WHERE 1=1
      $status_query
      $patient_query
      $ward_query
      $item_query
      $admission_status_query
      $date_query
      $search_query
    ORDER BY $sort $order
    LIMIT $record_from, $record_to
");

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get statistics
$total_orders = $num_rows[0];
$active_orders = 0;
$changed_orders = 0;
$stopped_orders = 0;
$completed_orders = 0;
$held_orders = 0;
$today_orders = 0;
$week_orders = 0;
$total_administrations = 0;

// Reset pointer and calculate statistics
mysqli_data_seek($sql, 0);
while ($order = mysqli_fetch_assoc($sql)) {
    switch($order['status']) {
        case 'active':
            $active_orders++;
            break;
        case 'changed':
            $changed_orders++;
            break;
        case 'stopped':
            $stopped_orders++;
            break;
        case 'completed':
            $completed_orders++;
            break;
        case 'held':
            $held_orders++;
            break;
    }
    
    $total_administrations += intval($order['administration_count']);
    
    // Count today's orders
    if (date('Y-m-d', strtotime($order['start_datetime'])) == date('Y-m-d')) {
        $today_orders++;
    }
    
    // Count this week's orders
    $order_date = strtotime($order['start_datetime']);
    $week_start = strtotime('monday this week');
    $week_end = strtotime('sunday this week');
    if ($order_date >= $week_start && $order_date <= $week_end) {
        $week_orders++;
    }
}

// Reset pointer for template usage
mysqli_data_seek($sql, $record_from);

// Get unique patients for filter
$patients_sql = mysqli_query($mysqli, "
    SELECT patient_id, first_name, last_name 
    FROM patients 
    WHERE patient_id IN (SELECT DISTINCT patient_id FROM ipd_medication_orders)
    ORDER BY first_name, last_name
");

// Get unique wards for filter
$wards_sql = mysqli_query($mysqli, "
    SELECT w.ward_id, w.ward_name, w.ward_type
    FROM wards w
    WHERE w.ward_id IN (SELECT DISTINCT ia.ward_id FROM ipd_admissions ia WHERE ia.visit_id IN (SELECT DISTINCT visit_id FROM ipd_medication_orders))
    ORDER BY w.ward_name
");

// Get inventory items for filter
$items_sql = mysqli_query($mysqli, "
    SELECT ii.item_id, ii.item_name, ii.item_code, ii.unit_of_measure 
    FROM inventory_items ii
    WHERE ii.item_id IN (SELECT DISTINCT item_id FROM ipd_medication_orders)
    AND ii.status = 'active' AND ii.is_drug = 1
    ORDER BY ii.item_name
");

// Get admission status counts
$admission_stats_sql = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN admission_status = 'ACTIVE' THEN 1 ELSE 0 END) as active_admissions,
    SUM(CASE WHEN admission_status = 'DISCHARGED' THEN 1 ELSE 0 END) as discharged_admissions
    FROM ipd_admissions ia
    WHERE ia.visit_id IN (SELECT DISTINCT visit_id FROM ipd_medication_orders)";
$admission_stats_result = mysqli_query($mysqli, $admission_stats_sql);
$admission_stats = mysqli_fetch_assoc($admission_stats_result);

// Function to get IPD order status badge
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

// Function to get admission status badge
function getAdmissionStatusBadge($status) {
    $badge_class = '';
    
    switch($status) {
        case 'ACTIVE':
            $badge_class = 'success';
            break;
        case 'DISCHARGED':
            $badge_class = 'secondary';
            break;
        case 'TRANSFERRED':
            $badge_class = 'info';
            break;
        default:
            $badge_class = 'light';
    }
    
    return '<span class="badge badge-' . $badge_class . '">' . strtoupper($status) . '</span>';
}

?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0 text-white">
                <i class="fas fa-fw fa-clipboard-list mr-2"></i>IPD Medication Orders
            </h3>
            <div class="card-tools">
                <div class="btn-group">
                    <a href="pharmacy_prescriptions.php" class="btn btn-light">
                        <i class="fas fa-file-prescription mr-2"></i>All Prescriptions
                    </a>
                    <a href="pharmacy_prescription_add.php?type=ipd" class="btn btn-success">
                        <i class="fas fa-plus mr-2"></i>New IPD Order
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Enhanced Statistics Row -->
    <div class="card-body border-bottom py-3">
        <div class="row text-center">
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-primary"><i class="fas fa-clipboard-list"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Orders</span>
                        <span class="info-box-number"><?php echo $total_orders; ?></span>
                        <?php if ($week_orders > 0): ?>
                            <span class="info-box-text small text-success">
                                <i class="fas fa-chart-line mr-1"></i><?php echo $week_orders; ?> this week
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-success"><i class="fas fa-play-circle"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Active Orders</span>
                        <span class="info-box-number"><?php echo $active_orders; ?></span>
                        <span class="info-box-text small text-success">
                            Currently administering
                        </span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-info"><i class="fas fa-syringe"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Administrations</span>
                        <span class="info-box-number"><?php echo $total_administrations; ?></span>
                        <span class="info-box-text small text-info">
                            Total doses given
                        </span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-secondary"><i class="fas fa-procedures"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Active Admissions</span>
                        <span class="info-box-number"><?php echo $admission_stats['active_admissions'] ?? 0; ?></span>
                        <span class="info-box-text small text-secondary">
                            <?php echo $admission_stats['total'] ?? 0; ?> total admissions
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card-header pb-2 pt-3">
        <form autocomplete="off">
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search patients, items, wards, admission number..." autofocus>
                            <div class="input-group-append">
                                <button class="btn btn-secondary" type="button" data-toggle="collapse" data-target="#advancedFilter">
                                    <i class="fas fa-filter"></i>
                                </button>
                                <button class="btn btn-primary">
                                    <i class="fa fa-search"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="btn-toolbar form-group float-right">
                        <div class="btn-group">
                            <span class="btn btn-light border">
                                <i class="fas fa-clipboard-list text-primary mr-1"></i>
                                Total: <strong><?php echo $total_orders; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-play-circle text-success mr-1"></i>
                                Active: <strong><?php echo $active_orders; ?></strong>
                            </span>
                            <a href="?<?php echo $url_query_strings_sort ?>&export=pdf" class="btn btn-light border ml-2">
                                <i class="fa fa-fw fa-file-pdf mr-2"></i>Export
                            </a>
                            <a href="administer_meds.php?view=ipd" class="btn btn-info ml-2">
                                <i class="fas fa-syringe mr-2"></i>Administer
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php 
                if (isset($_GET['dtf']) || $status_filter || $patient_filter || $ward_filter || $item_filter || $canned_date || $admission_status_filter) { 
                    echo "show"; 
                } 
            ?>" id="advancedFilter">
                <div class="row">
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Date Range</label>
                            <select class="form-control select2" name="canned_date" onchange="this.form.submit()">
                                <option value="">- All Dates -</option>
                                <option value="today" <?php if ($canned_date == "today") { echo "selected"; } ?>>Today</option>
                                <option value="yesterday" <?php if ($canned_date == "yesterday") { echo "selected"; } ?>>Yesterday</option>
                                <option value="thisweek" <?php if ($canned_date == "thisweek") { echo "selected"; } ?>>This Week</option>
                                <option value="lastweek" <?php if ($canned_date == "lastweek") { echo "selected"; } ?>>Last Week</option>
                                <option value="thismonth" <?php if ($canned_date == "thismonth") { echo "selected"; } ?>>This Month</option>
                                <option value="lastmonth" <?php if ($canned_date == "lastmonth") { echo "selected"; } ?>>Last Month</option>
                                <option value="custom" <?php if (!empty($dtf)) { echo "selected"; } ?>>Custom Range</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Date from</label>
                            <input type="date" class="form-control" name="dtf" max="2999-12-31" value="<?php echo nullable_htmlentities($dtf); ?>" onchange="this.form.submit()">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Date to</label>
                            <input type="date" class="form-control" name="dtt" max="2999-12-31" value="<?php echo nullable_htmlentities($dtt); ?>" onchange="this.form.submit()">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Order Status</label>
                            <select class="form-control select2" name="status" onchange="this.form.submit()">
                                <option value="">- All Status -</option>
                                <option value="active" <?php if ($status_filter == "active") { echo "selected"; } ?>>Active</option>
                                <option value="changed" <?php if ($status_filter == "changed") { echo "selected"; } ?>>Changed</option>
                                <option value="stopped" <?php if ($status_filter == "stopped") { echo "selected"; } ?>>Stopped</option>
                                <option value="completed" <?php if ($status_filter == "completed") { echo "selected"; } ?>>Completed</option>
                                <option value="held" <?php if ($status_filter == "held") { echo "selected"; } ?>>Held</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Admission Status</label>
                            <select class="form-control select2" name="admission_status" onchange="this.form.submit()">
                                <option value="">- All Admissions -</option>
                                <option value="ACTIVE" <?php if ($admission_status_filter == "ACTIVE") { echo "selected"; } ?>>Active</option>
                                <option value="DISCHARGED" <?php if ($admission_status_filter == "DISCHARGED") { echo "selected"; } ?>>Discharged</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Ward</label>
                            <select class="form-control select2" name="ward" onchange="this.form.submit()">
                                <option value="">- All Wards -</option>
                                <?php
                                while($ward = mysqli_fetch_assoc($wards_sql)) {
                                    $ward_id = intval($ward['ward_id']);
                                    $ward_name = nullable_htmlentities($ward['ward_name']);
                                    $selected = $ward_filter == $ward_id ? 'selected' : '';
                                    echo "<option value='$ward_id' $selected>$ward_name</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Patient</label>
                            <select class="form-control select2" name="patient" onchange="this.form.submit()">
                                <option value="">- All Patients -</option>
                                <?php
                                while($patient = mysqli_fetch_assoc($patients_sql)) {
                                    $patient_id = intval($patient['patient_id']);
                                    $patient_name = nullable_htmlentities($patient['first_name'] . ' ' . $patient['last_name']);
                                    $selected = $patient_filter == $patient_id ? 'selected' : '';
                                    echo "<option value='$patient_id' $selected>$patient_name</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Medication</label>
                            <select class="form-control select2" name="item" onchange="this.form.submit()">
                                <option value="">- All Medications -</option>
                                <?php
                                while($item = mysqli_fetch_assoc($items_sql)) {
                                    $item_id = intval($item['item_id']);
                                    $item_name = nullable_htmlentities($item['item_name']);
                                    $item_code = nullable_htmlentities($item['item_code']);
                                    $unit = nullable_htmlentities($item['unit_of_measure']);
                                    $display_name = "$item_name ($item_code)";
                                    if ($unit) {
                                        $display_name .= " - $unit";
                                    }
                                    $selected = $item_filter == $item_id ? 'selected' : '';
                                    echo "<option value='$item_id' $selected>$display_name</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group mb-0">
                            <label>Quick Actions</label>
                            <div class="btn-group btn-group-toggle" data-toggle="buttons">
                                <a href="?status=active" class="btn btn-outline-success btn-sm <?php echo $status_filter == 'active' ? 'active' : ''; ?>">
                                    <i class="fas fa-play-circle mr-1"></i> Active
                                </a>
                                <a href="?status=changed" class="btn btn-outline-warning btn-sm <?php echo $status_filter == 'changed' ? 'active' : ''; ?>">
                                    <i class="fas fa-exchange-alt mr-1"></i> Changed
                                </a>
                                <a href="?status=stopped" class="btn btn-outline-danger btn-sm <?php echo $status_filter == 'stopped' ? 'active' : ''; ?>">
                                    <i class="fas fa-stop-circle mr-1"></i> Stopped
                                </a>
                                <a href="ipd_medication_orders.php" class="btn btn-outline-secondary btn-sm">
                                    <i class="fas fa-times mr-1"></i> Clear Filters
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
    
    <div class="table-responsive-sm">
        <table class="table table-hover mb-0">
            <thead class="<?php if ($num_rows[0] == 0) { echo "d-none"; } ?> bg-light">
            <tr>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=imo.start_datetime&order=<?php echo $disp; ?>">
                        Started <?php if ($sort == 'imo.start_datetime') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>Patient</th>
                <th>Admission Details</th>
                <th>Medication</th>
                <th>Dose/Frequency</th>
                <th>Status</th>
                <th>History</th>
                <th class="text-center">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php
            while ($row = mysqli_fetch_array($sql)) {
                $order_id = intval($row['order_id']);
                $patient_name = nullable_htmlentities($row['first_name'] . ' ' . $row['last_name']);
                $patient_mrn = nullable_htmlentities($row['patient_mrn']);
                $patient_id = intval($row['patient_id']);
                $item_name = nullable_htmlentities($row['item_name']);
                $item_code = nullable_htmlentities($row['item_code']);
                $dose = nullable_htmlentities($row['dose']);
                $frequency = nullable_htmlentities($row['frequency']);
                $route = nullable_htmlentities($row['route']);
                $ordered_by = nullable_htmlentities($row['ordered_by_name']);
                $status = nullable_htmlentities($row['status']);
                $start_datetime = nullable_htmlentities($row['start_datetime']);
                $end_datetime = nullable_htmlentities($row['end_datetime']);
                $stop_reason = nullable_htmlentities($row['stop_reason']);
                $changed_from_name = nullable_htmlentities($row['changed_from_name']);
                $admission_number = nullable_htmlentities($row['admission_number']);
                $admission_status = nullable_htmlentities($row['admission_status']);
                $ward_name = nullable_htmlentities($row['ward_name']);
                $bed_number = nullable_htmlentities($row['bed_number']);
                $administration_count = intval($row['administration_count']);
                $last_administered = nullable_htmlentities($row['last_administered']);
                $instructions = nullable_htmlentities($row['instructions']);
                $visit_id = intval($row['visit_id']);
                
                // Check if today's order
                $is_today = date('Y-m-d', strtotime($start_datetime)) == date('Y-m-d');
                
                // Calculate next dose if applicable
                $next_dose_time = null;
                if ($status == 'active' && $last_administered) {
                    $last_dose = new DateTime($last_administered);
                    $next_dose = clone $last_dose;
                    
                    // Calculate based on frequency
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
                    }
                    
                    $next_dose_time = $next_dose->format('Y-m-d H:i:s');
                }
                
                // Determine if overdue
                $is_overdue = false;
                if ($next_dose_time && strtotime($next_dose_time) < time()) {
                    $is_overdue = true;
                }
                ?>
                <tr class="<?php echo $status == 'active' && $is_overdue ? 'table-danger' : ($status == 'active' ? 'table-success' : ''); ?>">
                    <td>
                        <div class="font-weight-bold"><?php echo date('M j, Y', strtotime($start_datetime)); ?></div>
                        <small class="text-muted"><?php echo date('H:i', strtotime($start_datetime)); ?></small>
                        <?php if($is_today): ?>
                            <div><small class="badge badge-success">Today</small></div>
                        <?php endif; ?>
                        <?php if($end_datetime): ?>
                            <div><small class="text-muted">Ended: <?php echo date('M j', strtotime($end_datetime)); ?></small></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="font-weight-bold"><?php echo $patient_name; ?></div>
                        <small class="text-muted"><?php echo $patient_mrn; ?></small>
                        <br>
                        <small class="text-muted">
                            <i class="fas fa-user-md mr-1"></i><?php echo $ordered_by; ?>
                        </small>
                    </td>
                    <td>
                        <?php if ($admission_number): ?>
                            <div class="font-weight-bold">
                                <?php echo $admission_number; ?>
                                <?php echo getAdmissionStatusBadge($admission_status); ?>
                            </div>
                            <?php if ($ward_name): ?>
                                <small class="text-muted">
                                    <i class="fas fa-bed mr-1"></i>
                                    <?php echo $ward_name; ?>
                                    <?php if ($bed_number): ?>
                                        / Bed <?php echo $bed_number; ?>
                                    <?php endif; ?>
                                </small>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted">Not admitted</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="font-weight-bold"><?php echo $item_name; ?></div>
                        <small class="text-muted"><?php echo $item_code; ?></small>
                        <?php if ($changed_from_name): ?>
                            <div class="mt-1">
                                <small class="text-warning">
                                    <i class="fas fa-exchange-alt mr-1"></i>
                                    Changed from: <?php echo $changed_from_name; ?>
                                </small>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="font-weight-bold"><?php echo $dose; ?> <?php echo $route ? '(' . $route . ')' : ''; ?></div>
                        <small class="text-muted"><?php echo $frequency; ?></small>
                        <?php if ($instructions): ?>
                            <div class="mt-1">
                                <small class="text-info">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    <?php echo substr($instructions, 0, 30); ?>
                                    <?php if (strlen($instructions) > 30): ?>...<?php endif; ?>
                                </small>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php echo getIPDOrderStatusBadge($status); ?>
                        <?php if ($stop_reason && $status == 'stopped'): ?>
                            <div class="small text-muted mt-1"><?php echo substr($stop_reason, 0, 30); ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="small">
                            <?php if ($administration_count > 0): ?>
                                <span class="badge badge-info"><?php echo $administration_count; ?> doses</span>
                                <?php if ($last_administered): ?>
                                    <div class="text-muted mt-1">
                                        Last: <?php echo date('M j H:i', strtotime($last_administered)); ?>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="badge badge-light">Not started</span>
                            <?php endif; ?>
                            
                            <?php if ($next_dose_time && $status == 'active'): ?>
                                <div class="mt-1">
                                    <small class="<?php echo $is_overdue ? 'text-danger' : 'text-info'; ?>">
                                        <i class="fas fa-clock mr-1"></i>
                                        Next: <?php echo date('H:i', strtotime($next_dose_time)); ?>
                                    </small>
                                </div>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <div class="dropdown dropleft text-center">
                            <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                <i class="fas fa-ellipsis-h"></i>
                            </button>
                            <div class="dropdown-menu">
                                <a class="dropdown-item" href="pharmacy_ipd_order_view.php?order_id=<?php echo $order_id; ?>">
                                    <i class="fas fa-fw fa-eye mr-2"></i>View Details
                                </a>
                                
                                <?php if($status == 'active'): ?>
                                    <a class="dropdown-item text-success" href="administer_meds.php?visit_id=<?php echo $visit_id; ?>&order_id=<?php echo $order_id; ?>">
                                        <i class="fas fa-fw fa-syringe mr-2"></i>Administer
                                    </a>
                                    <a class="dropdown-item text-warning" href="pharmacy_ipd_order_change.php?order_id=<?php echo $order_id; ?>">
                                        <i class="fas fa-fw fa-exchange-alt mr-2"></i>Change Order
                                    </a>
                                    <a class="dropdown-item text-danger" href="post/pharmacy.php?stop_ipd_order=<?php echo $order_id; ?>" 
                                       onclick="return confirm('Are you sure you want to stop this medication order?')">
                                        <i class="fas fa-fw fa-stop mr-2"></i>Stop Order
                                    </a>
                                    <div class="dropdown-divider"></div>
                                <?php endif; ?>
                                
                                <a class="dropdown-item" href="pharmacy_ipd_order_history.php?order_id=<?php echo $order_id; ?>">
                                    <i class="fas fa-fw fa-history mr-2"></i>View History
                                </a>
                                
                                <?php if($administration_count > 0): ?>
                                    <a class="dropdown-item" href="ipd_administration_log.php?order_id=<?php echo $order_id; ?>">
                                        <i class="fas fa-fw fa-list-check mr-2"></i>Administration Log
                                    </a>
                                <?php endif; ?>
                                
                                <div class="dropdown-divider"></div>
                                
                                <?php if($status == 'active'): ?>
                                    <a class="dropdown-item text-info" href="post/pharmacy.php?hold_ipd_order=<?php echo $order_id; ?>">
                                        <i class="fas fa-fw fa-pause mr-2"></i>Hold Order
                                    </a>
                                <?php endif; ?>
                                
                                <?php if($status == 'held'): ?>
                                    <a class="dropdown-item text-info" href="post/pharmacy.php?resume_ipd_order=<?php echo $order_id; ?>">
                                        <i class="fas fa-fw fa-play mr-2"></i>Resume Order
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php
            } 
            
            if ($num_rows[0] == 0) {
                ?>
                <tr>
                    <td colspan="8" class="text-center py-5">
                        <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No IPD Medication Orders Found</h5>
                        <p class="text-muted">No IPD medication orders match your current filters.</p>
                        <div class="mt-3">
                            <a href="pharmacy_prescription_add.php?type=ipd" class="btn btn-success">
                                <i class="fas fa-plus mr-2"></i>Create First IPD Order
                            </a>
                            <a href="pharmacy_prescriptions.php" class="btn btn-info ml-2">
                                <i class="fas fa-file-prescription mr-2"></i>View All Prescriptions
                            </a>
                            <a href="ipd_medication_orders.php" class="btn btn-secondary ml-2">
                                <i class="fas fa-times mr-2"></i>Clear Filters
                            </a>
                        </div>
                    </td>
                </tr>
                <?php
            }
            ?>
            </tbody>
        </table>
    </div>
    
    <?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/filter_footer.php'; ?>
</div>

<script>
$(document).ready(function() {
    $('.select2').select2();

    // Auto-submit when date range changes
    $('input[type="date"]').change(function() {
        if ($(this).val()) {
            $(this).closest('form').submit();
        }
    });

    // Auto-submit when canned date is selected
    $('select[name="canned_date"]').change(function() {
        if ($(this).val() !== 'custom') {
            $(this).closest('form').submit();
        }
    });

    // Quick filter buttons
    $('.btn-group-toggle .btn').click(function(e) {
        e.preventDefault();
        window.location.href = $(this).attr('href');
    });
});

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + N for new IPD order
    if (e.ctrlKey && e.keyCode === 78) {
        e.preventDefault();
        window.location.href = 'pharmacy_prescription_add.php?type=ipd';
    }
    // Ctrl + F for focus search
    if (e.ctrlKey && e.keyCode === 70) {
        e.preventDefault();
        $('input[name="q"]').focus();
    }
    // Ctrl + A for administer page
    if (e.ctrlKey && e.keyCode === 65) {
        e.preventDefault();
        window.location.href = 'administer_meds.php?view=ipd';
    }
    // Escape to clear filters
    if (e.keyCode === 27) {
        window.location.href = 'ipd_medication_orders.php';
    }
    // Ctrl + P for all prescriptions
    if (e.ctrlKey && e.keyCode === 80) {
        e.preventDefault();
        window.location.href = 'pharmacy_prescriptions.php';
    }
});
</script>

<style>
.info-box {
    padding: 10px;
    border-radius: 5px;
    margin-bottom: 10px;
}
.info-box-icon {
    float: left;
    height: 70px;
    width: 70px;
    text-align: center;
    font-size: 30px;
    line-height: 70px;
    border-radius: 5px;
}
.info-box-content {
    margin-left: 80px;
}
.info-box-text {
    display: block;
    font-size: 14px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.info-box-number {
    display: block;
    font-weight: bold;
    font-size: 18px;
}
.table-success {
    background-color: rgba(40, 167, 69, 0.1) !important;
}
.table-danger {
    background-color: rgba(220, 53, 69, 0.1) !important;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
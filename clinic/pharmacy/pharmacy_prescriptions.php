<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Default Column Sortby/Order Filter
$sort = "p.prescription_date";
$order = "DESC";

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/audit_functions.php';

// AUDIT LOG: Access to OPD prescriptions manage page
audit_log($mysqli, [
    'user_id'     => $_SESSION['user_id'] ?? null,
    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
    'action'      => 'VIEW',
    'module'      => 'OPD Prescriptions',
    'table_name'  => 'prescriptions',
    'entity_type' => 'opd_prescription_list',
    'record_id'   => null,
    'patient_id'  => null,
    'visit_id'    => null,
    'description' => "Accessed OPD prescriptions manage page",
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
$doctor_filter = $_GET['doctor'] ?? '';
$item_filter = $_GET['item'] ?? '';

// Date Range Filter
$dtf = sanitizeInput($_GET['dtf'] ?? '');
$dtt = sanitizeInput($_GET['dtt'] ?? '');
$canned_date = $_GET['canned_date'] ?? '';

if (!empty($dtf) && !empty($dtt)) {
    $date_query = "AND DATE(p.prescription_date) BETWEEN '$dtf' AND '$dtt'";
} else if (!empty($canned_date)) {
    switch($canned_date) {
        case 'today':
            $date_query = "AND DATE(p.prescription_date) = CURDATE()";
            break;
        case 'yesterday':
            $date_query = "AND DATE(p.prescription_date) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
            break;
        case 'thisweek':
            $date_query = "AND YEARWEEK(p.prescription_date, 1) = YEARWEEK(CURDATE(), 1)";
            break;
        case 'lastweek':
            $date_query = "AND YEARWEEK(p.prescription_date, 1) = YEARWEEK(CURDATE(), 1) - 1";
            break;
        case 'thismonth':
            $date_query = "AND MONTH(p.prescription_date) = MONTH(CURDATE()) AND YEAR(p.prescription_date) = YEAR(CURDATE())";
            break;
        case 'lastmonth':
            $date_query = "AND MONTH(p.prescription_date) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND YEAR(p.prescription_date) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))";
            break;
        default:
            $date_query = '';
    }
} else {
    $date_query = '';
}

// Status Filter
if ($status_filter) {
    $status_query = "AND p.prescription_status = '" . sanitizeInput($status_filter) . "'";
} else {
    $status_query = '';
}

// Patient Filter
if ($patient_filter) {
    $patient_query = "AND p.prescription_patient_id = " . intval($patient_filter);
} else {
    $patient_query = '';
}

// Doctor Filter
if ($doctor_filter) {
    $doctor_query = "AND p.prescription_doctor_id = " . intval($doctor_filter);
} else {
    $doctor_query = '';
}

// Item Filter
if ($item_filter) {
    $item_query = "AND EXISTS (
        SELECT 1 FROM prescription_items pi2 
        WHERE pi2.pi_prescription_id = p.prescription_id 
        AND pi2.pi_inventory_item_id = " . intval($item_filter) . "
    )";
} else {
    $item_query = '';
}

// OPD ONLY Filter - Only show OPD prescriptions
$opd_only_query = "AND v.visit_type = 'OPD'";

// Search Query
$q = sanitizeInput($_GET['q'] ?? '');
if (!empty($q)) {
    $search_query = "AND (
        pat.first_name LIKE '%$q%' 
        OR pat.last_name LIKE '%$q%'
        OR pat.patient_mrn LIKE '%$q%'
        OR u.user_name LIKE '%$q%'
        OR p.prescription_id LIKE '%$q%'
        OR p.prescription_notes LIKE '%$q%'
        OR ii.item_name LIKE '%$q%'
        OR ii.item_code LIKE '%$q%'
    )";
} else {
    $search_query = '';
}

// Prepare filter data for audit log
$filter_data = [
    'status_filter' => $status_filter,
    'patient_filter' => $patient_filter,
    'doctor_filter' => $doctor_filter,
    'item_filter' => $item_filter,
    'date_from' => $dtf,
    'date_to' => $dtt,
    'canned_date' => $canned_date,
    'search_query' => $q,
    'sort_by' => $sort,
    'sort_order' => $order
];

// Main query for OPD prescriptions ONLY
$sql = mysqli_query(
    $mysqli,
    "
    SELECT SQL_CALC_FOUND_ROWS p.*, 
           pat.first_name, pat.last_name, pat.patient_mrn, pat.phone_primary,
           pat.patient_id,
           u.user_name as doctor_name,
           disp.user_name as dispensed_by_name,
           v.visit_id, v.visit_status, v.visit_datetime,
           inv.invoice_id, inv.invoice_status,
           COUNT(pi.pi_id) as item_count,
           SUM(pi.pi_quantity) as total_quantity,
           SUM(pi.pi_dispensed_quantity) as dispensed_quantity,
           SUM(pi.pi_quantity * COALESCE(ils.selling_price, 0)) as total_amount,
           SUM(CASE WHEN pi.pi_dispensed_quantity > 0 THEN 1 ELSE 0 END) as dispensed_items_count,
           GROUP_CONCAT(DISTINCT pi.pi_dosage ORDER BY pi.pi_dosage SEPARATOR ', ') as dosages,
           GROUP_CONCAT(DISTINCT pi.pi_frequency ORDER BY pi.pi_frequency SEPARATOR ', ') as frequencies,
           -- Get item information
           (SELECT GROUP_CONCAT(CONCAT(ii2.item_name, ' (', COALESCE(ii2.unit_of_measure, 'N/A'), ')') SEPARATOR ', ')
            FROM prescription_items pi2 
            LEFT JOIN inventory_items ii2 ON pi2.pi_inventory_item_id = ii2.item_id
            WHERE pi2.pi_prescription_id = p.prescription_id 
            LIMIT 2) as item_names
    FROM prescriptions p
    LEFT JOIN patients pat ON p.prescription_patient_id = pat.patient_id
    LEFT JOIN users u ON p.prescription_doctor_id = u.user_id
    LEFT JOIN users disp ON p.prescription_dispensed_by = disp.user_id
    LEFT JOIN prescription_items pi ON p.prescription_id = pi.pi_prescription_id
    LEFT JOIN inventory_items ii ON pi.pi_inventory_item_id = ii.item_id
    LEFT JOIN inventory_location_stock ils ON pi.pi_batch_id = ils.batch_id AND pi.pi_location_id = ils.location_id
    LEFT JOIN visits v ON p.prescription_visit_id = v.visit_id
    LEFT JOIN invoices inv ON p.prescription_invoice_id = inv.invoice_id
    WHERE 1=1
      $opd_only_query  -- OPD only filter
      $status_query
      $patient_query
      $doctor_query
      $item_query
      $date_query
      $search_query
    GROUP BY p.prescription_id
    ORDER BY $sort $order
    LIMIT $record_from, $record_to
");

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get statistics for OPD prescriptions only
$total_prescriptions = $num_rows[0];
$pending_prescriptions = 0;
$dispensed_prescriptions = 0;
$cancelled_prescriptions = 0;
$partial_prescriptions = 0;
$total_items = 0;
$total_amount = 0;
$today_prescriptions = 0;
$week_prescriptions = 0;
$billed_prescriptions = 0;
$prescriptions_with_items = 0;

// Reset pointer and calculate statistics
mysqli_data_seek($sql, 0);
while ($prescription = mysqli_fetch_assoc($sql)) {
    
    switch($prescription['prescription_status']) {
        case 'pending':
            $pending_prescriptions++;
            break;
        case 'dispensed':
            $dispensed_prescriptions++;
            break;
        case 'cancelled':
            $cancelled_prescriptions++;
            break;
        case 'partial':
            $partial_prescriptions++;
            break;
    }
    
    if ($prescription['invoice_id']) {
        $billed_prescriptions++;
    }
    
    $total_items += intval($prescription['item_count']);
    $total_amount += floatval($prescription['total_amount']);
    
    if ($prescription['item_names']) {
        $prescriptions_with_items++;
    }
    
    // Count today's prescriptions
    if (date('Y-m-d', strtotime($prescription['prescription_date'])) == date('Y-m-d')) {
        $today_prescriptions++;
    }
    
    // Count this week's prescriptions
    $prescription_date = strtotime($prescription['prescription_date']);
    $week_start = strtotime('monday this week');
    $week_end = strtotime('sunday this week');
    if ($prescription_date >= $week_start && $prescription_date <= $week_end) {
        $week_prescriptions++;
    }
}

// Reset pointer for template usage
mysqli_data_seek($sql, $record_from);

// Get unique patients for filter
$patients_sql = mysqli_query($mysqli, "
    SELECT DISTINCT p.patient_id, p.first_name, p.last_name 
    FROM patients p
    JOIN prescriptions pr ON p.patient_id = pr.prescription_patient_id
    JOIN visits v ON pr.prescription_visit_id = v.visit_id
    WHERE v.visit_type = 'OPD'
    AND p.patient_status = 'ACTIVE'
    ORDER BY p.first_name, p.last_name
");

// Get unique doctors for filter
$doctors_sql = mysqli_query($mysqli, "
    SELECT DISTINCT u.user_id, u.user_name 
    FROM users u
    JOIN prescriptions pr ON u.user_id = pr.prescription_doctor_id
    JOIN visits v ON pr.prescription_visit_id = v.visit_id
    WHERE v.visit_type = 'OPD'
    ORDER BY u.user_name
");

// Get inventory items for filter
$items_sql = mysqli_query($mysqli, "
    SELECT DISTINCT ii.item_id, ii.item_name, ii.item_code, ii.unit_of_measure 
    FROM inventory_items ii
    JOIN prescription_items pi ON ii.item_id = pi.pi_inventory_item_id
    JOIN prescriptions p ON pi.pi_prescription_id = p.prescription_id
    JOIN visits v ON p.prescription_visit_id = v.visit_id
    WHERE v.visit_type = 'OPD'
    AND ii.status = 'active' AND ii.is_drug = 1
    ORDER BY ii.item_name
");

// Get today's prescription summary for OPD only
$today_summary_sql = "SELECT 
    COUNT(*) as count,
    SUM(CASE WHEN p.prescription_status = 'pending' THEN 1 ELSE 0 END) as today_pending,
    SUM(CASE WHEN p.prescription_status = 'dispensed' THEN 1 ELSE 0 END) as today_dispensed
    FROM prescriptions p
    JOIN visits v ON p.prescription_visit_id = v.visit_id
    WHERE DATE(p.prescription_date) = CURDATE()
    AND v.visit_type = 'OPD'";
$today_summary_result = mysqli_query($mysqli, $today_summary_sql);
$today_summary = mysqli_fetch_assoc($today_summary_result);

?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0 text-white">
                <i class="fas fa-fw fa-file-prescription mr-2"></i>OPD Prescriptions
            </h3>
            <div class="card-tools">
                <div class="btn-group">
                    <a href="inventory_items_manage.php?type=drug" class="btn btn-info">
                        <i class="fas fa-capsules mr-2"></i>Manage Drugs/Items
                    </a>
                    <a href="pharmacy_prescription_add.php" class="btn btn-success">
                        <i class="fas fa-plus mr-2"></i>New OPD Prescription
                    </a>
                    <a href="ipd_medication_orders.php" class="btn btn-warning ml-2">
                        <i class="fas fa-procedures mr-2"></i>IPD Orders
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Enhanced Statistics Row -->
    <div class="card-body border-bottom py-3">
        <div class="row text-center">
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-primary"><i class="fas fa-file-prescription"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total OPD Prescriptions</span>
                        <span class="info-box-number"><?php echo $total_prescriptions; ?></span>
                        <span class="info-box-text small">
                            This week: <?php echo $week_prescriptions; ?>
                        </span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-warning"><i class="fas fa-clock"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Pending</span>
                        <span class="info-box-number"><?php echo $pending_prescriptions; ?></span>
                        <?php if ($today_summary['today_pending'] > 0): ?>
                            <span class="info-box-text small text-warning">
                                <?php echo $today_summary['today_pending']; ?> today
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-success"><i class="fas fa-check"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Dispensed</span>
                        <span class="info-box-number"><?php echo $dispensed_prescriptions; ?></span>
                        <?php if ($today_summary['today_dispensed'] > 0): ?>
                            <span class="info-box-text small text-success">
                                <?php echo $today_summary['today_dispensed']; ?> today
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-info"><i class="fas fa-pills"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Partially Dispensed</span>
                        <span class="info-box-number"><?php echo $partial_prescriptions; ?></span>
                        <span class="info-box-text small text-info">
                            <?php echo $prescriptions_with_items; ?> with items
                        </span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-secondary"><i class="fas fa-file-invoice-dollar"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Billed</span>
                        <span class="info-box-number"><?php echo $billed_prescriptions; ?></span>
                        <span class="info-box-text small text-secondary">
                            Revenue generated
                        </span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-dark"><i class="fas fa-money-bill-wave"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Value</span>
                        <span class="info-box-number"><?php echo numfmt_format_currency($currency_format, $total_amount, $session_company_currency); ?></span>
                        <span class="info-box-text small text-dark">
                            <?php echo $total_items; ?> total items
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
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search patients, doctors, items, MRN..." autofocus>
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
                                <i class="fas fa-file-prescription text-primary mr-1"></i>
                                Total: <strong><?php echo $total_prescriptions; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-check text-success mr-1"></i>
                                Dispensed: <strong><?php echo $dispensed_prescriptions; ?></strong>
                            </span>
                            <a href="?<?php echo $url_query_strings_sort ?>&export=pdf" class="btn btn-light border ml-2">
                                <i class="fa fa-fw fa-file-pdf mr-2"></i>Export
                            </a>
                            <a href="pharmacy_reports.php" class="btn btn-info ml-2">
                                <i class="fas fa-chart-bar mr-2"></i>Reports
                            </a>
                            <a href="pharmacy_bulk_dispense.php" class="btn btn-secondary ml-2">
                                <i class="fas fa-tasks mr-2"></i>Bulk Dispense
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php 
                if (isset($_GET['dtf']) || $status_filter || $patient_filter || $doctor_filter || $item_filter || $canned_date) { 
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
                            <label>Status</label>
                            <select class="form-control select2" name="status" onchange="this.form.submit()">
                                <option value="">- All Status -</option>
                                <option value="pending" <?php if ($status_filter == "pending") { echo "selected"; } ?>>Pending</option>
                                <option value="dispensed" <?php if ($status_filter == "dispensed") { echo "selected"; } ?>>Dispensed</option>
                                <option value="cancelled" <?php if ($status_filter == "cancelled") { echo "selected"; } ?>>Cancelled</option>
                                <option value="partial" <?php if ($status_filter == "partial") { echo "selected"; } ?>>Partially Dispensed</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group mb-0">
                            <label>Quick Actions</label>
                            <div class="btn-group-vertical btn-group-toggle w-100" data-toggle="buttons">
                                <div class="btn-group">
                                    <a href="?status=pending" class="btn btn-outline-warning btn-sm <?php echo $status_filter == 'pending' ? 'active' : ''; ?>">
                                        <i class="fas fa-clock mr-1"></i> Pending
                                    </a>
                                    <a href="?status=dispensed" class="btn btn-outline-success btn-sm <?php echo $status_filter == 'dispensed' ? 'active' : ''; ?>">
                                        <i class="fas fa-check mr-1"></i> Dispensed
                                    </a>
                                    <a href="?status=partial" class="btn btn-outline-info btn-sm <?php echo $status_filter == 'partial' ? 'active' : ''; ?>">
                                        <i class="fas fa-pills mr-1"></i> Partial
                                    </a>
                                </div>
                                <div class="btn-group mt-1">
                                    <a href="pharmacy_prescriptions.php" class="btn btn-outline-dark btn-sm">
                                        <i class="fas fa-times mr-1"></i> Clear Filters
                                    </a>
                                    <a href="ipd_medication_orders.php" class="btn btn-outline-primary btn-sm ml-1">
                                        <i class="fas fa-procedures mr-1"></i> View IPD Orders
                                    </a>
                                </div>
                            </div>
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
                            <label>Doctor</label>
                            <select class="form-control select2" name="doctor" onchange="this.form.submit()">
                                <option value="">- All Doctors -</option>
                                <?php
                                while($doctor = mysqli_fetch_assoc($doctors_sql)) {
                                    $doctor_id = intval($doctor['user_id']);
                                    $doctor_name = nullable_htmlentities($doctor['user_name']);
                                    $selected = $doctor_filter == $doctor_id ? 'selected' : '';
                                    echo "<option value='$doctor_id' $selected>$doctor_name</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Item/Drug</label>
                            <select class="form-control select2" name="item" onchange="this.form.submit()">
                                <option value="">- All Items -</option>
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
                </div>
            </div>
        </form>
    </div>
    
    <div class="table-responsive-sm">
        <table class="table table-hover mb-0">
            <thead class="<?php if ($num_rows[0] == 0) { echo "d-none"; } ?> bg-light">
            <tr>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=p.prescription_date&order=<?php echo $disp; ?>">
                        Date & Time <?php if ($sort == 'p.prescription_date') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=pat.first_name&order=<?php echo $disp; ?>">
                        Patient <?php if ($sort == 'pat.first_name') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=pat.patient_mrn&order=<?php echo $disp; ?>">
                        MRN <?php if ($sort == 'pat.patient_mrn') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>Prescribed Items</th>
                <th class="text-center">
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=item_count&order=<?php echo $disp; ?>">
                        Items <?php if ($sort == 'item_count') { echo $order_icon; } ?>
                    </a>
                </th>
                <th class="text-right">
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=total_amount&order=<?php echo $disp; ?>">
                        Total <?php if ($sort == 'total_amount') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=p.prescription_status&order=<?php echo $disp; ?>">
                        Status <?php if ($sort == 'p.prescription_status') { echo $order_icon; } ?>
                    </a>
                </th>
                <th class="text-center">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php
            while ($row = mysqli_fetch_array($sql)) {
                $prescription_id = intval($row['prescription_id']);
                $prescription_date = nullable_htmlentities($row['prescription_date']);
                $patient_first_name = nullable_htmlentities($row['first_name']);
                $patient_last_name = nullable_htmlentities($row['last_name']);
                $patient_mrn = nullable_htmlentities($row['patient_mrn']);
                $patient_phone = nullable_htmlentities($row['phone_primary']);
                $patient_id = intval($row['patient_id']);
                $doctor_name = nullable_htmlentities($row['doctor_name']);
                $dispensed_by_name = nullable_htmlentities($row['dispensed_by_name']);
                $item_count = intval($row['item_count']);
                $total_quantity = intval($row['total_quantity']);
                $dispensed_quantity = intval($row['dispensed_quantity']);
                $total_amount = floatval($row['total_amount']);
                $prescription_status = nullable_htmlentities($row['prescription_status']);
                $prescription_notes = nullable_htmlentities($row['prescription_notes']);
                $visit_id = intval($row['visit_id']);
                $visit_status = nullable_htmlentities($row['visit_status']);
                $invoice_id = intval($row['invoice_id']);
                $invoice_status = nullable_htmlentities($row['invoice_status']);
                $dispensed_items_count = intval($row['dispensed_items_count']);
                $item_names = nullable_htmlentities($row['item_names']);
                $dosages = nullable_htmlentities($row['dosages']);
                $frequencies = nullable_htmlentities($row['frequencies']);

                // Status badge styling
                $status_badge = "";
                $status_icon = "";
                $table_class = "";
                
                switch($prescription_status) {
                    case 'pending':
                        $status_badge = "warning";
                        $status_icon = "fa-clock";
                        $table_class = "table-warning";
                        break;
                    case 'dispensed':
                        $status_badge = "success";
                        $status_icon = "fa-check";
                        $table_class = "table-success";
                        break;
                    case 'cancelled':
                        $status_badge = "danger";
                        $status_icon = "fa-times";
                        $table_class = "table-danger";
                        break;
                    case 'partial':
                        $status_badge = "info";
                        $status_icon = "fa-pills";
                        $table_class = "table-info";
                        break;
                    default:
                        $status_badge = "secondary";
                        $status_icon = "fa-file-prescription";
                }

                // Check if today's prescription
                $is_today = date('Y-m-d', strtotime($prescription_date)) == date('Y-m-d');
                ?>
                <tr class="<?php echo $table_class; ?>">
                    <td>
                        <div class="font-weight-bold"><?php echo date('M j, Y', strtotime($prescription_date)); ?></div>
                        <small class="text-muted"><?php echo date('H:i', strtotime($prescription_date)); ?></small>
                        <?php if($is_today): ?>
                            <div><small class="badge badge-success">Today</small></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="font-weight-bold">
                            <?php echo $patient_first_name . ' ' . $patient_last_name; ?>
                            <?php if ($patient_phone): ?>
                                <br><small class="text-muted"><?php echo $patient_phone; ?></small>
                            <?php endif; ?>
                        </div>
                        <small class="text-muted">Dr. <?php echo $doctor_name; ?></small>
                    </td>
                    <td>
                        <div class="text-muted font-weight-bold"><?php echo $patient_mrn; ?></div>
                        <small class="badge badge-secondary">OPD</small>
                    </td>
                    <td>
                        <?php if ($item_names): ?>
                            <div class="small">
                                <?php 
                                $items_list = explode(',', $item_names);
                                foreach (array_slice($items_list, 0, 2) as $item):
                                ?>
                                    <span class="badge badge-light mb-1"><?php echo trim($item); ?></span><br>
                                <?php endforeach; ?>
                                <?php if (count($items_list) > 2): ?>
                                    <small class="text-muted">+<?php echo count($items_list) - 2; ?> more</small>
                                <?php endif; ?>
                                <?php if ($dosages): ?>
                                    <div class="mt-1">
                                        <small class="text-muted">
                                            <i class="fas fa-syringe mr-1"></i>
                                            <?php echo substr($dosages, 0, 30); ?>
                                            <?php if (strlen($dosages) > 30): ?>...<?php endif; ?>
                                        </small>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <span class="text-muted small">No items specified</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <span class="badge badge-primary badge-pill"><?php echo $item_count; ?></span>
                        <div class="small text-muted">
                            <?php echo $total_quantity; ?> total
                            <?php if ($dispensed_quantity > 0): ?>
                                <br>
                                <span class="<?php echo $dispensed_quantity < $total_quantity ? 'text-info' : 'text-success'; ?>">
                                    <?php echo $dispensed_quantity; ?> dispensed
                                </span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="text-right">
                        <div class="font-weight-bold text-success"><?php echo numfmt_format_currency($currency_format, $total_amount, $session_company_currency); ?></div>
                        <?php if ($invoice_id): ?>
                            <small class="text-success">
                                <i class="fas fa-file-invoice-dollar mr-1"></i>Billed
                            </small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge badge-<?php echo $status_badge; ?>">
                            <i class="fas <?php echo $status_icon; ?> mr-1"></i>
                            <?php echo ucfirst($prescription_status); ?>
                        </span>
                        <?php if ($prescription_status == 'dispensed' && $dispensed_by_name): ?>
                            <div class="small text-muted">by <?php echo $dispensed_by_name; ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="dropdown dropleft text-center">
                            <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                <i class="fas fa-ellipsis-h"></i>
                            </button>
                            <div class="dropdown-menu">
                                <a class="dropdown-item" href="pharmacy_prescription_view.php?id=<?php echo $prescription_id; ?>">
                                    <i class="fas fa-fw fa-eye mr-2"></i>View Details
                                </a>
                                
                                <?php if(in_array($prescription_status, ['pending', 'partial'])): ?>
                                    <a class="dropdown-item" href="pharmacy_prescription_edit.php?id=<?php echo $prescription_id; ?>">
                                        <i class="fas fa-fw fa-edit mr-2"></i>Edit
                                    </a>
                                    <a class="dropdown-item" href="pharmacy_dispense.php?prescription_id=<?php echo $prescription_id; ?>">
                                        <i class="fas fa-fw fa-pills mr-2"></i>Dispense
                                    </a>
                                <?php endif; ?>
                                
                                <?php if(in_array($prescription_status, ['pending', 'partial'])): ?>
                                    <a class="dropdown-item" href="pharmacy_dispense.php?prescription_id=<?php echo $prescription_id; ?>">
                                        <i class="fas fa-fw fa-syringe mr-2"></i>Continue Dispensing
                                    </a>
                                <?php endif; ?>
                                
                                <?php if($prescription_status == 'dispensed' && !$invoice_id && $visit_id): ?>
                                    <a class="dropdown-item text-info" href="post/pharmacy.php?create_invoice=<?php echo $prescription_id; ?>">
                                        <i class="fas fa-fw fa-file-invoice-dollar mr-2"></i>Create Invoice
                                    </a>
                                <?php endif; ?>
                                
                                <?php if($invoice_id): ?>
                                    <a class="dropdown-item text-success" href="invoice.php?invoice_id=<?php echo $invoice_id; ?>">
                                        <i class="fas fa-fw fa-receipt mr-2"></i>View Invoice
                                    </a>
                                <?php endif; ?>
                                
                                <div class="dropdown-divider"></div>
                                
                                <a class="dropdown-item" href="prescription_pdf.php?prescription_id=<?php echo $prescription_id; ?>" target="_blank">
                                    <i class="fas fa-fw fa-print mr-2"></i>Print
                                </a>
                                
                                <?php if($prescription_status == 'pending'): ?>
                                    <a class="dropdown-item text-danger confirm-link" href="post/pharmacy.php?delete_prescription=<?php echo $prescription_id; ?>">
                                        <i class="fas fa-fw fa-trash mr-2"></i>Delete
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
                        <i class="fas fa-file-prescription fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No OPD Prescriptions Found</h5>
                        <p class="text-muted">No OPD prescriptions match your current filters.</p>
                        <div class="mt-3">
                            <a href="pharmacy_prescription_add.php" class="btn btn-success">
                                <i class="fas fa-plus mr-2"></i>Create First OPD Prescription
                            </a>
                            <a href="ipd_medication_orders.php" class="btn btn-primary ml-2">
                                <i class="fas fa-procedures mr-2"></i>View IPD Orders
                            </a>
                            <a href="pharmacy_prescriptions.php" class="btn btn-secondary ml-2">
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

    // Confirm action links
    $('.confirm-link').click(function(e) {
        if (!confirm('Are you sure you want to perform this action?')) {
            e.preventDefault();
        }
    });

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
    // Ctrl + N for new prescription
    if (e.ctrlKey && e.keyCode === 78) {
        e.preventDefault();
        window.location.href = 'pharmacy_prescription_add.php';
    }
    // Ctrl + F for focus search
    if (e.ctrlKey && e.keyCode === 70) {
        e.preventDefault();
        $('input[name="q"]').focus();
    }
    // Ctrl + I for IPD orders
    if (e.ctrlKey && e.keyCode === 73) {
        e.preventDefault();
        window.location.href = 'ipd_medication_orders.php';
    }
    // Escape to clear filters
    if (e.keyCode === 27) {
        window.location.href = 'pharmacy_prescriptions.php';
    }
    // Ctrl + D for manage items
    if (e.ctrlKey && e.keyCode === 68) {
        e.preventDefault();
        window.location.href = 'inventory_items_manage.php?type=drug';
    }
    // Ctrl + B for bulk dispense
    if (e.ctrlKey && e.keyCode === 66) {
        e.preventDefault();
        window.location.href = 'pharmacy_bulk_dispense.php';
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
.table-warning {
    background-color: rgba(255, 193, 7, 0.1) !important;
}
.table-success {
    background-color: rgba(40, 167, 69, 0.1) !important;
}
.table-info {
    background-color: rgba(23, 162, 184, 0.1) !important;
}
.table-danger {
    background-color: rgba(220, 53, 69, 0.1) !important;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
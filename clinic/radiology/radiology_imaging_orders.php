<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
// Include necessary files first

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Default Column Sortby/Order Filter
$sort = "created_at";
$order = "DESC";

// Status Filter
if (isset($_GET['status']) && !empty($_GET['status']) && $_GET['status'] != 'all') {
    $status_query = "AND (ro.order_status = '" . sanitizeInput($_GET['status']) . "')";
    $status_filter = nullable_htmlentities($_GET['status']);
} else {
    // Default - all
    $status_query = '';
    $status_filter = 'all';
}

// Priority Filter
if (isset($_GET['priority']) && !empty($_GET['priority'])) {
    $priority_query = "AND (ro.order_priority = '" . sanitizeInput($_GET['priority']) . "')";
    $priority_filter = nullable_htmlentities($_GET['priority']);
} else {
    // Default - any
    $priority_filter = '';
    $priority_query = '';
}

// Patient Filter
if (isset($_GET['patient']) && !empty($_GET['patient'])) {
    $patient_id_filter = intval($_GET['patient']);
    $patient_query = "AND (ro.patient_id = $patient_id_filter)";
} else {
    $patient_id_filter = 0;
    $patient_query = '';
}

// Order Type Filter
if (isset($_GET['order_type']) && !empty($_GET['order_type'])) {
    $order_type_query = "AND (ro.order_type = '" . sanitizeInput($_GET['order_type']) . "')";
    $order_type_filter = nullable_htmlentities($_GET['order_type']);
} else {
    $order_type_filter = '';
    $order_type_query = '';
}

// Date Range for Radiology Orders
$dtf = sanitizeInput($_GET['dtf'] ?? date('Y-m-01'));
$dtt = sanitizeInput($_GET['dtt'] ?? date('Y-m-d'));

// Main query for radiology orders
$sql = mysqli_query(
    $mysqli,
    "
    SELECT SQL_CALC_FOUND_ROWS ro.*, 
           p.first_name, p.last_name, p.patient_mrn, p.sex, p.date_of_birth,
           COUNT(ros.radiology_order_study_id) as study_count,
           SUM(CASE WHEN ros.status IN ('pending', 'scheduled') THEN 1 ELSE 0 END) as pending_studies_count,
           u.user_name as referring_doctor_name,
           ru.user_name as radiologist_name,
           au.user_name as created_by_name,
           d.department_name
    FROM radiology_orders ro 
    LEFT JOIN patients p ON ro.patient_id = p.patient_id 
    LEFT JOIN radiology_order_studies ros ON ro.radiology_order_id = ros.radiology_order_id
    LEFT JOIN users u ON ro.referring_doctor_id = u.user_id
    LEFT JOIN users ru ON ro.radiologist_id = ru.user_id
    LEFT JOIN users au ON ro.created_by = au.user_id
    LEFT JOIN departments d ON ro.department_id = d.department_id
    WHERE DATE(ro.created_at) BETWEEN '$dtf' AND '$dtt'
      $status_query
      $priority_query
      $patient_query
      $order_type_query
    GROUP BY ro.radiology_order_id
    ORDER BY $sort $order
    LIMIT $record_from, $record_to
");

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get statistics for dashboard
$total_orders = $num_rows[0];
$pending_orders = 0;
$completed_orders = 0;
$urgent_orders = 0;
$total_pending_studies = 0;

// Reset pointer and calculate
mysqli_data_seek($sql, 0);
while ($order = mysqli_fetch_assoc($sql)) {
    switch($order['order_status']) {
        case 'Pending':
            $pending_orders++;
            break;
        case 'Completed':
            $completed_orders++;
            break;
    }
    if ($order['order_priority'] == 'urgent' || $order['order_priority'] == 'stat') {
        $urgent_orders++;
    }
    $total_pending_studies += intval($order['pending_studies_count']);
}
mysqli_data_seek($sql, $record_from);

// Get patients list for filter
$patients_sql = mysqli_query($mysqli, "
    SELECT DISTINCT p.patient_id, p.first_name, p.last_name, p.patient_mrn 
    FROM patients p 
    JOIN radiology_orders ro ON p.patient_id = ro.patient_id 
    WHERE p.archived_at IS NULL 
    ORDER BY p.first_name, p.last_name ASC
");

// Get order types for filter
$order_types_sql = mysqli_query($mysqli, "
    SELECT DISTINCT order_type 
    FROM radiology_orders 
    WHERE order_type IS NOT NULL 
    ORDER BY order_type ASC
");
?>

<div class="card">
    <div class="card-header bg-info py-2">
        <h3 class="card-title mt-2 mb-0"><i class="fas fa-fw fa-x-ray mr-2"></i>Radiology Orders</h3>
        <div class="card-tools">
            <button type="button" class="btn btn-success" data-toggle="modal" data-target="#newRadiologyOrderModal">
                <i class="fas fa-plus mr-2"></i>New Order
            </button>
        </div>
    </div>

    <div class="card-header pb-2 pt-3">
        <form autocomplete="off">
            <div class="row">
                <div class="col-md-5">
                    <div class="form-group">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search orders, patients, order numbers..." autofocus>
                            <div class="input-group-append">
                                <button class="btn btn-secondary" type="button" data-toggle="collapse" data-target="#advancedFilter"><i class="fas fa-filter"></i></button>
                                <button class="btn btn-primary"><i class="fa fa-search"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-7">
                    <div class="btn-toolbar form-group float-right">
                        <div class="btn-group mr-2">
                            <span class="btn btn-light border">
                                <i class="fas fa-x-ray text-info mr-1"></i>
                                Total: <strong><?php echo $total_orders; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-clock text-warning mr-1"></i>
                                Pending: <strong><?php echo $pending_orders; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-procedures text-danger mr-1"></i>
                                Pending Studies: <strong><?php echo $total_pending_studies; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-exclamation-triangle text-danger mr-1"></i>
                                Urgent: <strong><?php echo $urgent_orders; ?></strong>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php if (isset($_GET['dtf']) || $status_filter != 'all' || $priority_filter || $patient_id_filter || $order_type_filter) { echo "show"; } ?>" id="advancedFilter">
                <div class="row">
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Date Range</label>
                            <select onchange="this.form.submit()" class="form-control select2" name="canned_date">
                                <option <?php if (($_GET['canned_date'] ?? '') == "custom") { echo "selected"; } ?> value="custom">Custom</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "today") { echo "selected"; } ?> value="today">Today</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "yesterday") { echo "selected"; } ?> value="yesterday">Yesterday</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "thisweek") { echo "selected"; } ?> value="thisweek">This Week</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "lastweek") { echo "selected"; } ?> value="lastweek">Last Week</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "thismonth") { echo "selected"; } ?> value="thismonth">This Month</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "lastmonth") { echo "selected"; } ?> value="lastmonth">Last Month</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Date from</label>
                            <input onchange="this.form.submit()" type="date" class="form-control" name="dtf" max="2999-12-31" value="<?php echo nullable_htmlentities($dtf); ?>">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Date to</label>
                            <input onchange="this.form.submit()" type="date" class="form-control" name="dtt" max="2999-12-31" value="<?php echo nullable_htmlentities($dtt); ?>">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Status</label>
                            <select class="form-control select2" name="status" onchange="this.form.submit()">
                                <option value="all" <?php if ($status_filter == "all") { echo "selected"; } ?>>- All Statuses -</option>
                                <option value="Pending" <?php if ($status_filter == "Pending") { echo "selected"; } ?>>Pending</option>
                                <option value="Scheduled" <?php if ($status_filter == "Scheduled") { echo "selected"; } ?>>Scheduled</option>
                                <option value="In Progress" <?php if ($status_filter == "In Progress") { echo "selected"; } ?>>In Progress</option>
                                <option value="Completed" <?php if ($status_filter == "Completed") { echo "selected"; } ?>>Completed</option>
                                <option value="Cancelled" <?php if ($status_filter == "Cancelled") { echo "selected"; } ?>>Cancelled</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Priority</label>
                            <select class="form-control select2" name="priority" onchange="this.form.submit()">
                                <option value="">- All Priorities -</option>
                                <option value="routine" <?php if ($priority_filter == "routine") { echo "selected"; } ?>>Routine</option>
                                <option value="urgent" <?php if ($priority_filter == "urgent") { echo "selected"; } ?>>Urgent</option>
                                <option value="stat" <?php if ($priority_filter == "stat") { echo "selected"; } ?>>STAT</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Order Type</label>
                            <select class="form-control select2" name="order_type" onchange="this.form.submit()">
                                <option value="">- All Types -</option>
                                <?php
                                while ($row = mysqli_fetch_array($order_types_sql)) {
                                    $order_type = nullable_htmlentities($row['order_type']);
                                ?>
                                    <option value="<?php echo $order_type; ?>" <?php if ($order_type_filter == $order_type) { echo "selected"; } ?>>
                                        <?php echo $order_type; ?>
                                    </option>
                                <?php
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
                                mysqli_data_seek($patients_sql, 0);
                                while ($row = mysqli_fetch_array($patients_sql)) {
                                    $patient_id = intval($row['patient_id']);
                                    $patient_name = nullable_htmlentities($row['first_name'] . ' ' . $row['last_name']);
                                    $patient_mrn = nullable_htmlentities($row['patient_mrn']);
                                ?>
                                    <option value="<?php echo $patient_id; ?>" <?php if ($patient_id == $patient_id_filter) { echo "selected"; } ?>>
                                        <?php echo "$patient_name (MRN: $patient_mrn)"; ?>
                                    </option>
                                <?php
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
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=ro.order_number&order=<?php echo $disp; ?>">
                        Order # <?php if ($sort == 'ro.order_number') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=p.patient_first_name&order=<?php echo $disp; ?>">
                        Patient <?php if ($sort == 'p.patient_first_name') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>Referring Doctor</th>
                <th>Type</th>
                <th class="text-center">Studies</th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=ro.order_priority&order=<?php echo $disp; ?>">
                        Priority <?php if ($sort == 'ro.order_priority') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>Status</th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=ro.created_at&order=<?php echo $disp; ?>">
                        Order Date <?php if ($sort == 'ro.created_at') { echo $order_icon; } ?>
                    </a>
                </th>
                <th class="text-center">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php
            while ($row = mysqli_fetch_array($sql)) {
                $radiology_order_id = intval($row['radiology_order_id']);
                $order_number = nullable_htmlentities($row['order_number']);
                $order_priority = nullable_htmlentities($row['order_priority']);
                $status = nullable_htmlentities($row['order_status']);
                $order_type = nullable_htmlentities($row['order_type']);
                $body_part = nullable_htmlentities($row['body_part']);
                $created_at = nullable_htmlentities($row['created_at']);
                
                $patient_id = intval($row['patient_id']);
                $patient_first_name = nullable_htmlentities($row['first_name']);
                $patient_last_name = nullable_htmlentities($row['last_name']);
                $patient_mrn = nullable_htmlentities($row['patient_mrn']);
                $patient_gender = nullable_htmlentities($row['sex']);
                $patient_dob = nullable_htmlentities($row['date_of_birth']);
                
                $referring_doctor_name = nullable_htmlentities($row['referring_doctor_name']);
                $radiologist_name = nullable_htmlentities($row['radiologist_name']);
                $created_by_name = nullable_htmlentities($row['created_by_name']);
                $study_count = intval($row['study_count']);
                $pending_studies_count = intval($row['pending_studies_count']);

                // Status badge styling
                $status_badge = "";
                switch($status) {
                    case 'Pending':
                        $status_badge = "badge-warning";
                        break;
                    case 'Scheduled':
                        $status_badge = "badge-info";
                        break;
                    case 'In Progress':
                        $status_badge = "badge-primary";
                        break;
                    case 'Completed':
                        $status_badge = "badge-success";
                        break;
                    case 'Cancelled':
                        $status_badge = "badge-danger";
                        break;
                    default:
                        $status_badge = "badge-light";
                }

                // Priority badge styling
                $priority_badge = "";
                switch($order_priority) {
                    case 'stat':
                        $priority_badge = "badge-danger";
                        break;
                    case 'urgent':
                        $priority_badge = "badge-warning";
                        break;
                    case 'routine':
                        $priority_badge = "badge-success";
                        break;
                    default:
                        $priority_badge = "badge-light";
                }

                // Calculate patient age from DOB
                $patient_age = "";
                if (!empty($patient_dob)) {
                    $birthDate = new DateTime($patient_dob);
                    $today = new DateTime();
                    $age = $today->diff($birthDate)->y;
                    $patient_age = " ($age yrs)";
                }
                ?>
                <tr class="<?php echo $pending_studies_count > 0 ? 'table-warning' : ''; ?>">
                    <td class="font-weight-bold text-info"><?php echo $order_number; ?></td>
                    <td>
                        <div class="font-weight-bold"><?php echo $patient_first_name . ' ' . $patient_last_name . $patient_age; ?></div>
                        <small class="text-muted"><?php echo $patient_mrn; ?> • <?php echo $patient_gender; ?></small>
                    </td>
                    <td>
                        <?php if ($referring_doctor_name): ?>
                            <?php echo $referring_doctor_name; ?>
                        <?php else: ?>
                            <span class="text-muted">Not assigned</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="font-weight-bold"><?php echo $order_type; ?></div>
                        <?php if ($body_part): ?>
                            <small class="text-muted"><?php echo $body_part; ?></small>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <span class="badge badge-info"><?php echo $study_count; ?></span>
                        <?php if ($pending_studies_count > 0): ?>
                            <span class="badge badge-warning ml-1" title="Pending studies"><?php echo $pending_studies_count; ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge <?php echo $priority_badge; ?>"><?php echo ucfirst($order_priority); ?></span>
                    </td>
                    <td>
                        <span class="badge <?php echo $status_badge; ?>"><?php echo $status; ?></span>
                    </td>
                    <td>
                        <div class="font-weight-bold"><?php echo date('M j, Y', strtotime($created_at)); ?></div>
                        <small class="text-muted"><?php echo date('g:i A', strtotime($created_at)); ?></small>
                    </td>
                    <td>
                        <div class="dropdown dropleft text-center">
                            <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                <i class="fas fa-ellipsis-h"></i>
                            </button>
                            <div class="dropdown-menu">
                                 <?php if (SimplePermission::any("radiology_view_order")) { ?>
                                <a class="dropdown-item" href="radiology_order_details.php?id=<?php echo $radiology_order_id; ?>">
                                    <i class="fas fa-fw fa-eye mr-2"></i>View Details
                                </a>
                                 <?php } ?>
                                   <?php if (SimplePermission::any("radiology_edit_order")) { ?>
                                <a class="dropdown-item" href="#" data-toggle="modal" data-target="#editRadiologyOrderModal<?php echo $radiology_order_id; ?>">
                                    <i class="fas fa-fw fa-edit mr-2"></i>Edit Order
                                </a>
                                <?php } ?>
                                <?php if ($status == 'Pending' || $status == 'Scheduled'): ?>
                                    <a class="dropdown-item" href="post/radiology.php?schedule_order=<?php echo $radiology_order_id; ?>">
                                        <i class="fas fa-fw fa-calendar mr-2"></i>Schedule Study
                                    </a>
                                <?php endif; ?>
                               
                                <div class="dropdown-divider"></div>
                                 <?php if (SimplePermission::any("radiology_delete_order")) { ?>
                                <a class="dropdown-item text-danger confirm-link" href="post/radiology.php?delete_order=<?php echo $radiology_order_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token'] ?>">
                                    <i class="fas fa-fw fa-trash mr-2"></i>Delete
                                </a>
                                 <?php } ?>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php
            } 
            
            if ($num_rows[0] == 0) {
                ?>
                <tr>
                    <td colspan="9" class="text-center py-4">
                        <i class="fas fa-x-ray fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No radiology orders found</h5>
                        <p class="text-muted">No orders match your current filters.</p>
                        <a href="?status=all" class="btn btn-primary mt-2">
                            <i class="fas fa-redo mr-2"></i>Reset Filters
                        </a>
                    </td>
                </tr>
                <?php
            }
            ?>
            </tbody>
        </table>
    </div>
    
    <!-- Ends Card Body -->
    <?php 
    // Include necessary files first
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/filter_footer.php';
    ?>
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

    // Auto-submit date range when canned date is selected
    $('select[name="canned_date"]').change(function() {
        if ($(this).val() !== 'custom') {
            $(this).closest('form').submit();
        }
    });

    // Keyboard shortcuts
    $(document).keydown(function(e) {
        // Ctrl + N for new order
        if (e.ctrlKey && e.keyCode === 78) {
            e.preventDefault();
            $('#newRadiologyOrderModal').modal('show');
        }
        // Ctrl + F for focus search
        if (e.ctrlKey && e.keyCode === 70) {
            e.preventDefault();
            $('input[name="q"]').focus();
        }
    });
});
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
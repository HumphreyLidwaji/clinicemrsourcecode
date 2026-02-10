<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
// Include necessary files first
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Default Column Sortby/Order Filter
$sort = "lab_order_created_at";
$order = "DESC";

// Status Filter
if (isset($_GET['status']) && !empty($_GET['status']) && $_GET['status'] != 'all') {
    $status_query = "AND (lo.lab_order_status = '" . sanitizeInput($_GET['status']) . "')";
    $status_filter = nullable_htmlentities($_GET['status']);
} else {
    // Default - all
    $status_query = '';
    $status_filter = 'all';
}

// Priority Filter
if (isset($_GET['priority']) && !empty($_GET['priority'])) {
    $priority_query = "AND (lo.order_priority = '" . sanitizeInput($_GET['priority']) . "')";
    $priority_filter = nullable_htmlentities($_GET['priority']);
} else {
    // Default - any
    $priority_filter = '';
    $priority_query = '';
}

// Patient Filter
if (isset($_GET['patient']) && !empty($_GET['patient'])) {
    $patient_id_filter = intval($_GET['patient']);
    $patient_query = "AND (lo.lab_order_patient_id = $patient_id_filter)";
} else {
    $patient_id_filter = 0;
    $patient_query = '';
}

// Date Range for Lab Orders
$dtf = sanitizeInput($_GET['dtf'] ?? date('Y-m-01'));
$dtt = sanitizeInput($_GET['dtt'] ?? date('Y-m-d'));

// Main query for lab orders
// Updated to use correct column names from your database
$sql = mysqli_query(
    $mysqli,
    "
    SELECT SQL_CALC_FOUND_ROWS lo.*, 
           p.first_name, p.last_name, p.patient_mrn, p.sex as patient_gender, p.date_of_birth,
           COUNT(lot.lab_order_test_id) as test_count,
           SUM(CASE WHEN lot.status IN ('pending', 'collected') THEN 1 ELSE 0 END) as pending_tests_count,
           u.user_name as doctor_name,
           au.user_name as created_by_name
    FROM lab_orders lo 
    LEFT JOIN patients p ON lo.lab_order_patient_id = p.patient_id 
    LEFT JOIN lab_order_tests lot ON lo.lab_order_id = lot.lab_order_id
    LEFT JOIN users u ON lo.ordering_doctor_id = u.user_id
    LEFT JOIN users au ON lo.lab_order_created_by = au.user_id
    WHERE DATE(lo.lab_order_created_at) BETWEEN '$dtf' AND '$dtt'
      $status_query
      $priority_query
      $patient_query
      AND lo.lab_order_archived_at IS NULL
    GROUP BY lo.lab_order_id
    ORDER BY $sort $order
    LIMIT $record_from, $record_to
");

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get statistics for dashboard
$total_orders = $num_rows[0];
$pending_orders = 0;
$completed_orders = 0;
$urgent_orders = 0;
$total_pending_tests = 0;

// Reset pointer and calculate
mysqli_data_seek($sql, 0);
while ($order = mysqli_fetch_assoc($sql)) {
    switch($order['lab_order_status']) {
        case 'pending':
            $pending_orders++;
            break;
        case 'completed':
            $completed_orders++;
            break;
    }
    if ($order['order_priority'] == 'urgent' || $order['order_priority'] == 'stat') {
        $urgent_orders++;
    }
    $total_pending_tests += intval($order['pending_tests_count']);
}
mysqli_data_seek($sql, $record_from);

// Get patients list for filter
$patients_sql = mysqli_query($mysqli, "
    SELECT DISTINCT p.patient_id, p.first_name, p.last_name, p.patient_mrn 
    FROM patients p 
    JOIN lab_orders lo ON p.patient_id = lo.lab_order_patient_id 
    WHERE p.archived_at IS NULL 
    ORDER BY p.first_name, p.last_name ASC
");
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0"><i class="fas fa-fw fa-clipboard-list mr-2"></i>Laboratory Orders</h3>
        <div class="card-tools">
            <button type="button" class="btn btn-success" data-toggle="modal" data-target="#newOrderModal">
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
                                <i class="fas fa-clipboard-list text-primary mr-1"></i>
                                Total: <strong><?php echo $total_orders; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-clock text-warning mr-1"></i>
                                Pending: <strong><?php echo $pending_orders; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-vial text-danger mr-1"></i>
                                Pending Tests: <strong><?php echo $total_pending_tests; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-exclamation-triangle text-danger mr-1"></i>
                                Urgent: <strong><?php echo $urgent_orders; ?></strong>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php if (isset($_GET['dtf']) || $status_filter != 'all' || $priority_filter || $patient_id_filter) { echo "show"; } ?>" id="advancedFilter">
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
                                <option value="pending" <?php if ($status_filter == "pending") { echo "selected"; } ?>>Pending</option>
                                <option value="collected" <?php if ($status_filter == "collected") { echo "selected"; } ?>>Collected</option>
                                <option value="in_progress" <?php if ($status_filter == "in_progress") { echo "selected"; } ?>>In Progress</option>
                                <option value="completed" <?php if ($status_filter == "completed") { echo "selected"; } ?>>Completed</option>
                                <option value="cancelled" <?php if ($status_filter == "cancelled") { echo "selected"; } ?>>Cancelled</option>
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
                            <label>Patient</label>
                            <select class="form-control select2" name="patient" onchange="this.form.submit()">
                                <option value="">- All Patients -</option>
                                <?php
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
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=lo.order_number&order=<?php echo $disp; ?>">
                        Order # <?php if ($sort == 'lo.order_number') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=p.first_name&order=<?php echo $disp; ?>">
                        Patient <?php if ($sort == 'p.first_name') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>Doctor</th>
                <th class="text-center">Tests</th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=lo.order_priority&order=<?php echo $disp; ?>">
                        Priority <?php if ($sort == 'lo.order_priority') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>Status</th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=lo.lab_order_created_at&order=<?php echo $disp; ?>">
                        Order Date <?php if ($sort == 'lo.lab_order_created_at') { echo $order_icon; } ?>
                    </a>
                </th>
                <th class="text-center">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php
            while ($row = mysqli_fetch_array($sql)) {
                $lab_order_id = intval($row['lab_order_id']);
                $order_number = nullable_htmlentities($row['order_number']);
                $order_priority = nullable_htmlentities($row['order_priority']);
                $status = nullable_htmlentities($row['lab_order_status']);
                $order_type = nullable_htmlentities($row['lab_order_type']);
                $lab_order_created_at = nullable_htmlentities($row['lab_order_created_at']);
                
                $patient_id = intval($row['lab_order_patient_id']);
                $patient_first_name = nullable_htmlentities($row['first_name']);
                $patient_last_name = nullable_htmlentities($row['last_name']);
                $patient_mrn = nullable_htmlentities($row['patient_mrn']);
                $patient_gender = nullable_htmlentities($row['patient_gender']);
                $patient_dob = nullable_htmlentities($row['date_of_birth']);
                
                $doctor_name = nullable_htmlentities($row['doctor_name']);
                $created_by_name = nullable_htmlentities($row['created_by_name']);
                $test_count = intval($row['test_count']);
                $pending_tests_count = intval($row['pending_tests_count']);

                // Status badge styling
                $status_badge = "";
                switch($status) {
                    case 'pending':
                        $status_badge = "badge-warning";
                        break;
                    case 'collected':
                        $status_badge = "badge-info";
                        break;
                    case 'in_progress':
                        $status_badge = "badge-primary";
                        break;
                    case 'completed':
                        $status_badge = "badge-success";
                        break;
                    case 'cancelled':
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
                
                // Format gender for display
                $gender_display = "";
                switch($patient_gender) {
                    case 'M':
                        $gender_display = 'Male';
                        break;
                    case 'F':
                        $gender_display = 'Female';
                        break;
                    case 'I':
                        $gender_display = 'Intersex';
                        break;
                    default:
                        $gender_display = $patient_gender;
                }
                ?>
                <tr class="<?php echo $pending_tests_count > 0 ? 'table-warning' : ''; ?>">
                    <td class="font-weight-bold text-primary"><?php echo $order_number; ?></td>
                    <td>
                        <div class="font-weight-bold"><?php echo $patient_first_name . ' ' . $patient_last_name . $patient_age; ?></div>
                        <small class="text-muted"><?php echo $patient_mrn; ?> • <?php echo $gender_display; ?></small>
                    </td>
                    <td>
                        <?php if ($doctor_name): ?>
                            <?php echo $doctor_name; ?>
                        <?php else: ?>
                            <span class="text-muted">Not assigned</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <span class="badge badge-primary"><?php echo $test_count; ?></span>
                        <?php if ($pending_tests_count > 0): ?>
                            <span class="badge badge-warning ml-1" title="Pending tests"><?php echo $pending_tests_count; ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge <?php echo $priority_badge; ?>"><?php echo ucfirst($order_priority); ?></span>
                    </td>
                    <td>
                        <span class="badge <?php echo $status_badge; ?>"><?php echo ucfirst(str_replace('_', ' ', $status)); ?></span>
                    </td>
                    <td>
                        <div class="font-weight-bold"><?php echo date('M j, Y', strtotime($lab_order_created_at)); ?></div>
                        <small class="text-muted"><?php echo date('g:i A', strtotime($lab_order_created_at)); ?></small>
                    </td>
                    <td>
                        <div class="dropdown dropleft text-center">
                            <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                <i class="fas fa-ellipsis-h"></i>
                            </button>
                            <div class="dropdown-menu">
                                <a class="dropdown-item" href="lab_order_details.php?id=<?php echo $lab_order_id; ?>">
                                    <i class="fas fa-fw fa-eye mr-2"></i>View Details
                                </a>
                                <a class="dropdown-item" href="#" data-toggle="modal" data-target="#editOrderModal<?php echo $lab_order_id; ?>">
                                    <i class="fas fa-fw fa-edit mr-2"></i>Edit Order
                                </a>
                                <?php if ($status == 'pending'): ?>
                                    <a class="dropdown-item" href="post/lab.php?collect_order=<?php echo $lab_order_id; ?>">
                                        <i class="fas fa-fw fa-syringe mr-2"></i>Collect Samples
                                    </a>
                                <?php endif; ?>
                                <?php if ($status == 'completed'): ?>
                                    <a class="dropdown-item" href="lab_generate_report.php?order_id=<?php echo $lab_order_id; ?>">
                                        <i class="fas fa-fw fa-file-pdf mr-2"></i>Generate Report
                                    </a>
                                <?php endif; ?>
                                <div class="dropdown-divider"></div>
                                <?php if (isset($row['lab_order_archived_at']) && $row['lab_order_archived_at']) { ?>
                                <a class="dropdown-item text-info confirm-link" href="post/lab.php?restore_order=<?php echo $lab_order_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token'] ?>">
                                    <i class="fas fa-fw fa-redo mr-2"></i>Restore
                                </a>
                                <?php } else { ?>
                                <a class="dropdown-item text-danger confirm-link" href="post/lab.php?archive_order=<?php echo $lab_order_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token'] ?>">
                                    <i class="fas fa-fw fa-archive mr-2"></i>Archive
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
                    <td colspan="8" class="text-center py-4">
                        <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No lab orders found</h5>
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
            $('#newOrderModal').modal('show');
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
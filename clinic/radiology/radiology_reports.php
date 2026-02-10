<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
// Include necessary files first
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Default Column Sortby/Order Filter
$sort = "rr.created_at";
$order = "DESC";

// Report Status Filter
if (isset($_GET['status']) && !empty($_GET['status']) && $_GET['status'] != 'all') {
    $status_query = "AND (rr.report_status = '" . sanitizeInput($_GET['status']) . "')";
    $status_filter = nullable_htmlentities($_GET['status']);
} else {
    // Default - all
    $status_query = '';
    $status_filter = 'all';
}

// Patient Filter
if (isset($_GET['patient']) && !empty($_GET['patient'])) {
    $patient_id_filter = intval($_GET['patient']);
    $patient_query = "AND (rr.patient_id = $patient_id_filter)";
} else {
    $patient_id_filter = 0;
    $patient_query = '';
}

// Radiologist Filter
if (isset($_GET['radiologist']) && !empty($_GET['radiologist'])) {
    $radiologist_id_filter = intval($_GET['radiologist']);
    $radiologist_query = "AND (rr.radiologist_id = $radiologist_id_filter)";
} else {
    $radiologist_id_filter = 0;
    $radiologist_query = '';
}

// Date Range for Reports
$dtf = sanitizeInput($_GET['dtf'] ?? date('Y-m-01'));
$dtt = sanitizeInput($_GET['dtt'] ?? date('Y-m-d'));

// Main query for radiology reports
$sql = mysqli_query(
    $mysqli,
    "
    SELECT SQL_CALC_FOUND_ROWS rr.*, 
           p.patient_first_name, p.patient_last_name, p.patient_mrn, p.patient_gender, p.patient_dob,
           COUNT(rrs.report_study_id) as study_count,
           u.user_name as referring_doctor_name,
           ru.user_name as radiologist_name, 
           cb.user_name as created_by_name,
           fb.user_name as finalized_by_name,
           ro.order_number,
           d.department_name
    FROM radiology_reports rr 
    LEFT JOIN patients p ON rr.patient_id = p.patient_id 
    LEFT JOIN radiology_report_studies rrs ON rr.report_id = rrs.report_id
    LEFT JOIN users u ON rr.referring_doctor_id = u.user_id
    LEFT JOIN users ru ON rr.radiologist_id = ru.user_id
    LEFT JOIN users cb ON rr.created_by = cb.user_id
    LEFT JOIN users fb ON rr.finalized_by = fb.user_id
    LEFT JOIN radiology_orders ro ON rr.radiology_order_id = ro.radiology_order_id
    LEFT JOIN departments d ON ro.department_id = d.department_id
    WHERE DATE(rr.created_at) BETWEEN '$dtf' AND '$dtt'
      $status_query
      $patient_query
      $radiologist_query
    GROUP BY rr.report_id
    ORDER BY $sort $order
    LIMIT $record_from, $record_to
");

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get statistics for dashboard
$total_reports = $num_rows[0];
$draft_reports = 0;
$final_reports = 0;
$amended_reports = 0;
$today_reports = 0;

// Reset pointer and calculate
mysqli_data_seek($sql, 0);
while ($report = mysqli_fetch_assoc($sql)) {
    switch($report['report_status']) {
        case 'draft':
            $draft_reports++;
            break;
        case 'final':
            $final_reports++;
            break;
        case 'amended':
            $amended_reports++;
            break;
    }
    
    if (date('Y-m-d', strtotime($report['created_at'])) == date('Y-m-d')) {
        $today_reports++;
    }
}
mysqli_data_seek($sql, $record_from);

// Get patients list for filter
$patients_sql = mysqli_query($mysqli, "
    SELECT DISTINCT p.patient_id, p.patient_first_name, p.patient_last_name, p.patient_mrn 
    FROM patients p 
    JOIN radiology_reports rr ON p.patient_id = rr.patient_id 
    WHERE p.patient_archived_at IS NULL 
    ORDER BY p.patient_first_name, p.patient_last_name ASC
");

// Get radiologists list for filter
$radiologists_sql = mysqli_query($mysqli, "
    SELECT DISTINCT u.user_id, u.user_name
    FROM users u 
    JOIN radiology_reports rr ON u.user_id = rr.radiologist_id 
 
");
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0"><i class="fas fa-fw fa-file-medical mr-2"></i>Radiology Reports</h3>
        <div class="card-tools">
            <a href="radiology_imaging_orders.php" class="btn btn-light">
                <i class="fas fa-arrow-left mr-2"></i>Back to Orders
            </a>
        </div>
    </div>

    <div class="card-header pb-2 pt-3">
        <form autocomplete="off">
            <div class="row">
                <div class="col-md-5">
                    <div class="form-group">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search reports, patients, report numbers..." autofocus>
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
                                <i class="fas fa-file-medical text-primary mr-1"></i>
                                Total: <strong><?php echo $total_reports; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-edit text-warning mr-1"></i>
                                Draft: <strong><?php echo $draft_reports; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-check-circle text-success mr-1"></i>
                                Final: <strong><?php echo $final_reports; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-sync-alt text-info mr-1"></i>
                                Amended: <strong><?php echo $amended_reports; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-calendar-day text-danger mr-1"></i>
                                Today: <strong><?php echo $today_reports; ?></strong>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php if (isset($_GET['dtf']) || $status_filter != 'all' || $patient_id_filter || $radiologist_id_filter) { echo "show"; } ?>" id="advancedFilter">
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
                            <label>Report Status</label>
                            <select class="form-control select2" name="status" onchange="this.form.submit()">
                                <option value="all" <?php if ($status_filter == "all") { echo "selected"; } ?>>- All Statuses -</option>
                                <option value="draft" <?php if ($status_filter == "draft") { echo "selected"; } ?>>Draft</option>
                                <option value="final" <?php if ($status_filter == "final") { echo "selected"; } ?>>Final</option>
                                <option value="amended" <?php if ($status_filter == "amended") { echo "selected"; } ?>>Amended</option>
                                <option value="cancelled" <?php if ($status_filter == "cancelled") { echo "selected"; } ?>>Cancelled</option>
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
                                    $patient_name = nullable_htmlentities($row['patient_first_name'] . ' ' . $row['patient_last_name']);
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
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Radiologist</label>
                            <select class="form-control select2" name="radiologist" onchange="this.form.submit()">
                                <option value="">- All Radiologists -</option>
                                <?php
                                while ($row = mysqli_fetch_array($radiologists_sql)) {
                                    $radiologist_id = intval($row['user_id']);
                                    $radiologist_name = nullable_htmlentities($row['user_name']);
                                    $radiologist_credentials = nullable_htmlentities($row['user_credentials'] ?? '');
                                ?>
                                    <option value="<?php echo $radiologist_id; ?>" <?php if ($radiologist_id == $radiologist_id_filter) { echo "selected"; } ?>>
                                        <?php echo $radiologist_name . ($radiologist_credentials ? ", " . $radiologist_credentials : ''); ?>
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
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=rr.report_number&order=<?php echo $disp; ?>">
                        Report # <?php if ($sort == 'rr.report_number') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=p.patient_first_name&order=<?php echo $disp; ?>">
                        Patient <?php if ($sort == 'p.patient_first_name') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>Order #</th>
                <th>Radiologist</th>
                <th class="text-center">Studies</th>
                <th>Status</th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=rr.created_at&order=<?php echo $disp; ?>">
                        Created Date <?php if ($sort == 'rr.created_at') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=rr.finalized_at&order=<?php echo $disp; ?>">
                        Finalized Date <?php if ($sort == 'rr.finalized_at') { echo $order_icon; } ?>
                    </a>
                </th>
                <th class="text-center">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php
            while ($row = mysqli_fetch_array($sql)) {
                $report_id = intval($row['report_id']);
                $report_number = nullable_htmlentities($row['report_number']);
                $report_status = nullable_htmlentities($row['report_status']);
                $created_at = nullable_htmlentities($row['created_at']);
                $finalized_at = nullable_htmlentities($row['finalized_at']);
                
                $patient_id = intval($row['patient_id']);
                $patient_first_name = nullable_htmlentities($row['patient_first_name']);
                $patient_last_name = nullable_htmlentities($row['patient_last_name']);
                $patient_mrn = nullable_htmlentities($row['patient_mrn']);
                $patient_gender = nullable_htmlentities($row['patient_gender']);
                $patient_dob = nullable_htmlentities($row['patient_dob']);
                
                $order_number = nullable_htmlentities($row['order_number']);
                $referring_doctor_name = nullable_htmlentities($row['referring_doctor_name']);
                $radiologist_name = nullable_htmlentities($row['radiologist_name']);
                $radiologist_credentials = nullable_htmlentities($row['radiologist_credentials'] ?? '');
                $created_by_name = nullable_htmlentities($row['created_by_name']);
                $finalized_by_name = nullable_htmlentities($row['finalized_by_name']);
                $study_count = intval($row['study_count']);

                // Status badge styling
                $status_badge = "";
                switch($report_status) {
                    case 'draft':
                        $status_badge = "badge-warning";
                        break;
                    case 'final':
                        $status_badge = "badge-success";
                        break;
                    case 'amended':
                        $status_badge = "badge-info";
                        break;
                    case 'cancelled':
                        $status_badge = "badge-danger";
                        break;
                    default:
                        $status_badge = "badge-light";
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
                <tr>
                    <td class="font-weight-bold text-primary"><?php echo $report_number; ?></td>
                    <td>
                        <div class="font-weight-bold"><?php echo $patient_first_name . ' ' . $patient_last_name . $patient_age; ?></div>
                        <small class="text-muted"><?php echo $patient_mrn; ?> • <?php echo $patient_gender; ?></small>
                    </td>
                    <td>
                        <div class="font-weight-bold"><?php echo $order_number; ?></div>
                        <small class="text-muted">Ref: <?php echo $referring_doctor_name; ?></small>
                    </td>
                    <td>
                        <?php if ($radiologist_name): ?>
                            <div class="font-weight-bold"><?php echo $radiologist_name; ?></div>
                            <?php if ($radiologist_credentials): ?>
                                <small class="text-muted"><?php echo $radiologist_credentials; ?></small>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted">Not assigned</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <span class="badge badge-info"><?php echo $study_count; ?></span>
                    </td>
                    <td>
                        <span class="badge <?php echo $status_badge; ?>"><?php echo ucfirst($report_status); ?></span>
                    </td>
                    <td>
                        <div class="font-weight-bold"><?php echo date('M j, Y', strtotime($created_at)); ?></div>
                        <small class="text-muted">by <?php echo $created_by_name; ?></small>
                    </td>
                    <td>
                        <?php if ($finalized_at): ?>
                            <div class="font-weight-bold"><?php echo date('M j, Y', strtotime($finalized_at)); ?></div>
                            <small class="text-muted">by <?php echo $finalized_by_name; ?></small>
                        <?php else: ?>
                            <span class="text-muted">Not finalized</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="dropdown dropleft text-center">
                            <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                <i class="fas fa-ellipsis-h"></i>
                            </button>
                            <div class="dropdown-menu">
                                  <?php if (SimplePermission::any(['module_radiology', 'radiology_view_report'])): ?>
                                <a class="dropdown-item" href="radiology_view_report.php?report_id=<?php echo $report_id; ?>">
                                    <i class="fas fa-fw fa-eye mr-2"></i>View Report
                                </a>
                                 <?php endif; ?>
                                  <?php if (SimplePermission::any(['module_radiology', 'radiology_print_report'])): ?>
                                <a class="dropdown-item" href="radiology_view_report.php?report_id=<?php echo $report_id; ?>" target="_blank">
                                    <i class="fas fa-fw fa-print mr-2"></i>Print Report
                                </a>
                                 <?php endif; ?>
                                <?php if ($report_status == 'draft'): ?>
                                      <?php if (SimplePermission::any(['module_radiology', 'radiology_edit_report'])): ?>
                                    <a class="dropdown-item" href="radiology_amend_report.php?report_id=<?php echo $report_id; ?>">
                                        <i class="fas fa-fw fa-edit mr-2"></i>Edit Report
                                    </a>
                                     <?php endif; ?>
                                      <?php if (SimplePermission::any(['module_radiology', 'radiology_finalize_report'])): ?>
                                    <a class="dropdown-item" href="radiology_view_report.php?report_id=<?php echo $report_id; ?>&action=finalize&csrf_token=<?php echo $_SESSION['csrf_token']; ?>">
                                        <i class="fas fa-fw fa-check-circle mr-2"></i>Finalize Report
                                    </a>
                                    <?php endif; ?>
                                <?php elseif ($report_status == 'final'): ?>
                                    
                                      <?php if (SimplePermission::any(['module_radiology', 'radiology_amend_report'])): ?>
                                    <a class="dropdown-item" href="radiology_view_report.php?report_id=<?php echo $report_id; ?>&action=amend&csrf_token=<?php echo $_SESSION['csrf_token']; ?>">
                                        <i class="fas fa-fw fa-file-medical-alt mr-2"></i>Amend Report
                                    </a>
                                <?php endif; ?>
                                <?php endif; ?>
                                <div class="dropdown-divider"></div>
                                <?php if (SimplePermission::any(['module_radiology', 'radiology_view_order'])): ?>
                                <a class="dropdown-item" href="radiology_order_details.php?id=<?php echo $row['radiology_order_id']; ?>">
                                    <i class="fas fa-fw fa-clipboard-list mr-2"></i>View Order
                                </a>
                                <?php endif; ?>
                                  <?php if (SimplePermission::any(['module_radiology', 'radiology_delete_report'])): ?>
                                <?php if ($report_status == 'draft'): ?>
                                    <a class="dropdown-item text-danger confirm-link" href="post/radiology.php?delete_report=<?php echo $report_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token'] ?>">
                                        <i class="fas fa-fw fa-trash mr-2"></i>Delete Report
                                    </a>
                                <?php endif; ?>
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
                    <td colspan="9" class="text-center py-4">
                        <i class="fas fa-file-medical fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No radiology reports found</h5>
                        <p class="text-muted">No reports match your current filters.</p>
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
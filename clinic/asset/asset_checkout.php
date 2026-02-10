<?php
// asset_checkout.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Default Column Sortby/Order Filter
$sort = "cl.checkout_date";
$order = "DESC";

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Filter parameters
$status_filter = $_GET['status'] ?? ''; // checked_out, returned, overdue
$asset_filter = $_GET['asset'] ?? '';
$user_filter = $_GET['user'] ?? '';
$department_filter = $_GET['department'] ?? '';
$condition_filter = $_GET['condition'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Search Query
$q = sanitizeInput($_GET['q'] ?? '');
if (!empty($q)) {
    $search_query = "AND (
        a.asset_tag LIKE '%$q%' 
        OR a.asset_name LIKE '%$q%'
        OR a.serial_number LIKE '%$q%'
        OR u.user_name LIKE '%$q%'
        OR u2.user_name LIKE '%$q%'
        OR cl.checkout_notes LIKE '%$q%'
        OR cl.checkin_notes LIKE '%$q%'
        OR a.assigned_department LIKE '%$q%'
    )";
} else {
    $search_query = '';
}

// Status Filter
if ($status_filter == 'checked_out') {
    $status_query = "AND cl.checkin_date IS NULL";
} elseif ($status_filter == 'returned') {
    $status_query = "AND cl.checkin_date IS NOT NULL";
} elseif ($status_filter == 'overdue') {
    $status_query = "AND cl.checkin_date IS NULL AND cl.expected_return_date < CURDATE()";
} else {
    $status_query = '';
}

// Asset Filter
if ($asset_filter) {
    $asset_query = "AND cl.asset_id = " . intval($asset_filter);
} else {
    $asset_query = '';
}

// User Filter (assigned to)
if ($user_filter) {
    $user_query = "AND cl.assigned_to = " . intval($user_filter);
} else {
    $user_query = '';
}

// Department Filter
if ($department_filter) {
    $department_query = "AND a.assigned_department = '" . sanitizeInput($department_filter) . "'";
} else {
    $department_query = '';
}

// Condition Filter
if ($condition_filter) {
    $condition_query = "AND cl.checkin_condition = '" . sanitizeInput($condition_filter) . "'";
} else {
    $condition_query = '';
}

// Date Range Filter
$date_query = '';
if ($date_from) {
    $date_query .= "AND cl.checkout_date >= '" . sanitizeInput($date_from) . "' ";
}
if ($date_to) {
    $date_query .= "AND cl.checkout_date <= '" . sanitizeInput($date_to) . "' ";
}

// Get statistics
$stats_sql = "SELECT 
    COUNT(*) as total_checkouts,
    SUM(CASE WHEN cl.checkin_date IS NULL THEN 1 ELSE 0 END) as currently_checked_out,
    SUM(CASE WHEN cl.checkin_date IS NOT NULL THEN 1 ELSE 0 END) as returned,
    SUM(CASE WHEN cl.checkin_date IS NULL AND cl.expected_return_date < CURDATE() THEN 1 ELSE 0 END) as overdue,
    SUM(CASE WHEN cl.checkin_date IS NULL AND cl.expected_return_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as due_soon,
    (SELECT COUNT(DISTINCT cl2.assigned_to) FROM asset_checkout_logs cl2 WHERE cl2.checkin_date IS NULL) as active_users,
    (SELECT COUNT(DISTINCT a2.asset_id) FROM assets a2 WHERE a2.status = 'active') as total_available_assets,
    AVG(DATEDIFF(COALESCE(cl.checkin_date, CURDATE()), cl.checkout_date)) as avg_checkout_duration
    FROM asset_checkout_logs cl
    LEFT JOIN assets a ON cl.asset_id = a.asset_id
    WHERE 1=1
    $asset_query
    $user_query
    $department_query
    $condition_query
    $date_query
    $search_query";

$stats_result = $mysqli->query($stats_sql);
$stats = $stats_result->fetch_assoc();

// Get checkout condition statistics
$condition_stats_sql = "SELECT 
    cl.checkin_condition,
    COUNT(*) as count,
    AVG(DATEDIFF(cl.checkin_date, cl.checkout_date)) as avg_duration
    FROM asset_checkout_logs cl
    WHERE cl.checkin_date IS NOT NULL
    $date_query
    GROUP BY cl.checkin_condition
    ORDER BY count DESC";

$condition_stats_result = $mysqli->query($condition_stats_sql);
$condition_stats = [];
while ($row = $condition_stats_result->fetch_assoc()) {
    $condition_stats[$row['checkin_condition']] = $row;
}

// Main query for checkout logs
$sql = mysqli_query(
    $mysqli,
    "
    SELECT SQL_CALC_FOUND_ROWS cl.*,
           a.asset_tag,
           a.asset_name,
           a.assigned_department,
           a.asset_condition as asset_original_condition,
           a.is_critical,
           checked_out_by.user_name as checked_out_by_name,
           assigned_to.user_name as assigned_to_name,
           checkin_by.user_name as checkin_by_name,
           DATEDIFF(CURDATE(), cl.checkout_date) as days_out,
           DATEDIFF(cl.expected_return_date, CURDATE()) as days_until_due,
           DATEDIFF(COALESCE(cl.checkin_date, CURDATE()), cl.checkout_date) as checkout_duration,
           (SELECT COUNT(*) FROM asset_checkout_logs cl2 WHERE cl2.asset_id = a.asset_id) as asset_checkout_count,
           (SELECT COUNT(*) FROM asset_checkout_logs cl3 WHERE cl3.assigned_to = cl.assigned_to AND cl3.checkin_date IS NULL) as user_active_checkouts
    FROM asset_checkout_logs cl
    LEFT JOIN assets a ON cl.asset_id = a.asset_id
    LEFT JOIN users checked_out_by ON cl.checked_out_by = checked_out_by.user_id
    LEFT JOIN users assigned_to ON cl.assigned_to = assigned_to.user_id
    LEFT JOIN users checkin_by ON cl.checked_out_by = checkin_by.user_id
    WHERE 1=1
      $status_query
      $asset_query
      $user_query
      $department_query
      $condition_query
      $date_query
      $search_query
    ORDER BY $sort $order
    LIMIT $record_from, $record_to
");

if (!$sql) {
    die("Query failed: " . mysqli_error($mysqli));
}

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get assets for filter (only active assets)
$assets_sql = "SELECT asset_id, asset_tag, asset_name FROM assets WHERE status = 'active' ORDER BY asset_tag";
$assets_result = $mysqli->query($assets_sql);

// Get users for filter
$users_sql = "SELECT user_id, user_name FROM users WHERE user_status = 1 ORDER BY user_name";
$users_result = $mysqli->query($users_sql);

// Get unique departments
$departments_sql = "SELECT DISTINCT assigned_department FROM assets WHERE assigned_department IS NOT NULL AND assigned_department != '' ORDER BY assigned_department";
$departments_result = $mysqli->query($departments_sql);

// Get today's checkout summary
$today_checkout_sql = "
    SELECT 
        COUNT(*) as count,
        SUM(CASE WHEN checkin_date IS NULL THEN 1 ELSE 0 END) as checked_out_today,
        SUM(CASE WHEN checkin_date IS NOT NULL THEN 1 ELSE 0 END) as checked_in_today
    FROM asset_checkout_logs 
    WHERE DATE(checkout_date) = CURDATE()
";
$today_checkout_result = mysqli_query($mysqli, $today_checkout_sql);
$today_checkout = mysqli_fetch_assoc($today_checkout_result);

// Get longest checkout duration
$longest_checkout_sql = "
    SELECT MAX(DATEDIFF(COALESCE(checkin_date, CURDATE()), checkout_date)) as max_duration 
    FROM asset_checkout_logs 
    WHERE checkin_date IS NOT NULL
";
$longest_checkout_result = mysqli_query($mysqli, $longest_checkout_sql);
$longest_checkout = mysqli_fetch_assoc($longest_checkout_result)['max_duration'];

// Get users with most checkouts
$top_users_sql = "
    SELECT u.user_name, COUNT(*) as checkout_count
    FROM asset_checkout_logs cl
    JOIN users u ON cl.assigned_to = u.user_id
    GROUP BY cl.assigned_to
    ORDER BY checkout_count DESC
    LIMIT 5
";
$top_users_result = mysqli_query($mysqli, $top_users_sql);
$top_users = [];
while ($row = $top_users_result->fetch_assoc()) {
    $top_users[] = $row;
}
?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2 mb-0 text-white"><i class="fas fa-fw fa-exchange-alt mr-2"></i>Asset Checkout/Checkin Dashboard</h3>
        <div class="card-tools">
            <div class="btn-group">
                <a href="asset_checkout_new.php" class="btn btn-success">
                    <i class="fas fa-plus mr-2"></i>New Checkout
                </a>

                <a href="asset_management.php" class="btn btn-primary ml-2">
                    <i class="fas fa-cubes mr-2"></i>View Assets
                </a>

                <a href="asset_maintenance.php" class="btn btn-warning ml-2">
                    <i class="fas fa-tools mr-2"></i>Maintenance
                </a>

                <a href="asset_reports.php?report=checkout" class="btn btn-info ml-2">
                    <i class="fas fa-chart-bar mr-2"></i>Reports
                </a>

                <div class="btn-group ml-2">
                    <button type="button" class="btn btn-secondary dropdown-toggle" data-toggle="dropdown">
                        <i class="fas fa-tasks mr-2"></i>Quick Actions
                    </button>
                    <div class="dropdown-menu">
                        <a class="dropdown-item" href="asset_checkout_schedule.php">
                            <i class="fas fa-calendar-alt mr-2"></i>Checkout Schedule
                        </a>
                        <a href="asset_checkout_calendar.php" class="dropdown-item">
                            <i class="fas fa-calendar mr-2"></i>Calendar View
                        </a>
                        <a href="asset_checkout_bulk.php" class="dropdown-item">
                            <i class="fas fa-clone mr-2"></i>Bulk Checkout
                        </a>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item" href="asset_checkout_export.php">
                            <i class="fas fa-file-export mr-2"></i>Export Checkout Data
                        </a>
                        <a class="dropdown-item" href="asset_checkout_print.php">
                            <i class="fas fa-print mr-2"></i>Print Checkout Report
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Enhanced Stats Dashboard -->
    <div class="card-body bg-light border-bottom">
        <div class="row text-center">
            <!-- Total Checkouts -->
            <div class="col-md-2 mb-3">
                <div class="card bg-primary text-white">
                    <div class="card-body py-3">
                        <i class="fas fa-exchange-alt fa-2x mb-2"></i>
                        <h5 class="card-title">Total Checkouts</h5>
                        <h3 class="font-weight-bold"><?php echo $stats['total_checkouts']; ?></h3>
                        <small class="opacity-8">All-time transactions</small>
                    </div>
                </div>
            </div>
            
            <!-- Currently Checked Out -->
            <div class="col-md-2 mb-3">
                <div class="card bg-warning text-dark">
                    <div class="card-body py-3">
                        <i class="fas fa-handshake fa-2x mb-2"></i>
                        <h5 class="card-title">Checked Out</h5>
                        <h3 class="font-weight-bold"><?php echo $stats['currently_checked_out']; ?></h3>
                        <small class="opacity-8">Assets in use</small>
                    </div>
                </div>
            </div>
            
            <!-- Overdue -->
            <div class="col-md-2 mb-3">
                <div class="card bg-danger text-white">
                    <div class="card-body py-3">
                        <i class="fas fa-exclamation-triangle fa-2x mb-2"></i>
                        <h5 class="card-title">Overdue</h5>
                        <h3 class="font-weight-bold"><?php echo $stats['overdue']; ?></h3>
                        <small class="opacity-8">Past due date</small>
                    </div>
                </div>
            </div>
            
            <!-- Due Soon -->
            <div class="col-md-2 mb-3">
                <div class="card bg-info text-white">
                    <div class="card-body py-3">
                        <i class="fas fa-clock fa-2x mb-2"></i>
                        <h5 class="card-title">Due Soon</h5>
                        <h3 class="font-weight-bold"><?php echo $stats['due_soon']; ?></h3>
                        <small class="opacity-8">Next 7 days</small>
                    </div>
                </div>
            </div>
            
            <!-- Today's Activity -->
            <div class="col-md-2 mb-3">
                <div class="card bg-success text-white">
                    <div class="card-body py-3">
                        <i class="fas fa-calendar-day fa-2x mb-2"></i>
                        <h5 class="card-title">Today's Activity</h5>
                        <h3 class="font-weight-bold"><?php echo $today_checkout['count']; ?></h3>
                        <small class="opacity-8">Checkouts/Checkins</small>
                    </div>
                </div>
            </div>
            
            <!-- Active Users -->
            <div class="col-md-2 mb-3">
                <div class="card bg-dark text-white">
                    <div class="card-body py-3">
                        <i class="fas fa-users fa-2x mb-2"></i>
                        <h5 class="card-title">Active Users</h5>
                        <h3 class="font-weight-bold"><?php echo $stats['active_users']; ?></h3>
                        <small class="opacity-8">With assets checked out</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Alert Row -->
    <?php if ($stats['overdue'] > 0 || $stats['due_soon'] > 0 || $stats['currently_checked_out'] > ($stats['total_available_assets'] * 0.8)): ?>
    <div class="row mt-3">
        <div class="col-12">
            <div class="alert-container">
                <?php if ($stats['overdue'] > 0): ?>
                <div class="alert alert-danger alert-dismissible fade show mb-2" role="alert">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    <strong><?php echo $stats['overdue']; ?> asset(s)</strong> are overdue for return.
                    <a href="?status=overdue" class="alert-link ml-2">View Overdue Assets</a>
                    <button type="button" class="close" data-dismiss="alert">
                        <span>&times;</span>
                    </button>
                </div>
                <?php endif; ?>
                
                <?php if ($stats['due_soon'] > 0): ?>
                <div class="alert alert-warning alert-dismissible fade show mb-2" role="alert">
                    <i class="fas fa-clock mr-2"></i>
                    <strong><?php echo $stats['due_soon']; ?> asset(s)</strong> are due for return within 7 days.
                    <a href="asset_checkout.php?status=checked_out" class="alert-link ml-2">View Checked Out Assets</a>
                    <button type="button" class="close" data-dismiss="alert">
                        <span>&times;</span>
                    </button>
                </div>
                <?php endif; ?>
                
                <?php if ($stats['currently_checked_out'] > 0 && $stats['total_available_assets'] > 0 && ($stats['currently_checked_out'] / $stats['total_available_assets'] * 100) > 80): ?>
                <div class="alert alert-info alert-dismissible fade show mb-2" role="alert">
                    <i class="fas fa-chart-pie mr-2"></i>
                    <strong><?php echo number_format(($stats['currently_checked_out'] / $stats['total_available_assets'] * 100), 1); ?>% of assets</strong> are currently checked out.
                    <a href="asset_management.php" class="alert-link ml-2">View Available Assets</a>
                    <button type="button" class="close" data-dismiss="alert">
                        <span>&times;</span>
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="card-header pb-2 pt-3">
        <form autocomplete="off">
            <div class="row">
                <div class="col-md-5">
                    <div class="form-group">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search checkouts, assets, users, departments..." autofocus>
                            <div class="input-group-append">
                                <button class="btn btn-secondary" type="button" data-toggle="collapse" data-target="#advancedFilter"><i class="fas fa-filter"></i></button>
                                <button class="btn btn-primary"><i class="fa fa-search"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-7">
                    <div class="btn-toolbar form-group float-right">
                        <div class="btn-group">
                            <span class="btn btn-light border" data-toggle="tooltip" title="Total Checkouts">
                                <i class="fas fa-exchange-alt text-dark mr-1"></i>
                                <strong><?php echo $stats['total_checkouts']; ?></strong>
                            </span>
                            <span class="btn btn-light border" data-toggle="tooltip" title="Checked Out">
                                <i class="fas fa-handshake text-warning mr-1"></i>
                                <strong><?php echo $stats['currently_checked_out']; ?></strong>
                            </span>
                            <span class="btn btn-light border" data-toggle="tooltip" title="Overdue">
                                <i class="fas fa-exclamation-triangle text-danger mr-1"></i>
                                <strong><?php echo $stats['overdue']; ?></strong>
                            </span>
                            <span class="btn btn-light border" data-toggle="tooltip" title="Returned">
                                <i class="fas fa-check-circle text-success mr-1"></i>
                                <strong><?php echo $stats['returned']; ?></strong>
                            </span>
                            <a href="asset_checkout_new.php" class="btn btn-success ml-2">
                                <i class="fas fa-fw fa-plus mr-2"></i>New Checkout
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php if ($status_filter || $asset_filter || $user_filter || $department_filter || $condition_filter || $date_from || $date_to) { echo "show"; } ?>" id="advancedFilter">
                <div class="row">
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Status</label>
                            <select class="form-control select2" name="status" onchange="this.form.submit()">
                                <option value="">- All Status -</option>
                                <option value="checked_out" <?php if ($status_filter == "checked_out") { echo "selected"; } ?>>Checked Out</option>
                                <option value="returned" <?php if ($status_filter == "returned") { echo "selected"; } ?>>Returned</option>
                                <option value="overdue" <?php if ($status_filter == "overdue") { echo "selected"; } ?>>Overdue</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Asset</label>
                            <select class="form-control select2" name="asset" onchange="this.form.submit()">
                                <option value="">- All Assets -</option>
                                <?php while($asset = $assets_result->fetch_assoc()): ?>
                                    <option value="<?php echo $asset['asset_id']; ?>" <?php if ($asset_filter == $asset['asset_id']) { echo "selected"; } ?>>
                                        <?php echo htmlspecialchars($asset['asset_tag'] . ' - ' . $asset['asset_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Assigned To</label>
                            <select class="form-control select2" name="user" onchange="this.form.submit()">
                                <option value="">- All Users -</option>
                                <?php while($user = $users_result->fetch_assoc()): ?>
                                    <option value="<?php echo $user['user_id']; ?>" <?php if ($user_filter == $user['user_id']) { echo "selected"; } ?>>
                                        <?php echo htmlspecialchars($user['user_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Quick Actions</label>
                            <div class="btn-group btn-block">
                                <a href="asset_checkout.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times mr-2"></i>Clear Filters
                                </a>
                                <a href="asset_checkout_new.php" class="btn btn-success">
                                    <i class="fas fa-plus mr-2"></i>New Checkout
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row mt-2">
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Department</label>
                            <select class="form-control select2" name="department" onchange="this.form.submit()">
                                <option value="">- All Departments -</option>
                                <?php while($dept = $departments_result->fetch_assoc()): ?>
                                    <option value="<?php echo htmlspecialchars($dept['assigned_department']); ?>" <?php if ($department_filter == $dept['assigned_department']) { echo "selected"; } ?>>
                                        <?php echo htmlspecialchars($dept['assigned_department']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Return Condition</label>
                            <select class="form-control select2" name="condition" onchange="this.form.submit()">
                                <option value="">- All Conditions -</option>
                                <option value="excellent" <?php if ($condition_filter == "excellent") { echo "selected"; } ?>>Excellent</option>
                                <option value="good" <?php if ($condition_filter == "good") { echo "selected"; } ?>>Good</option>
                                <option value="fair" <?php if ($condition_filter == "fair") { echo "selected"; } ?>>Fair</option>
                                <option value="poor" <?php if ($condition_filter == "poor") { echo "selected"; } ?>>Poor</option>
                                <option value="damaged" <?php if ($condition_filter == "damaged") { echo "selected"; } ?>>Damaged</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Date From</label>
                            <input type="date" class="form-control" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" onchange="this.form.submit()">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Date To</label>
                            <input type="date" class="form-control" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" onchange="this.form.submit()">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Quick Filters</label>
                            <div class="btn-group btn-group-toggle" data-toggle="buttons">
                                <a href="?status=checked_out" class="btn btn-outline-warning btn-sm <?php echo $status_filter == 'checked_out' ? 'active' : ''; ?>">
                                    <i class="fas fa-handshake mr-1"></i> Checked Out
                                </a>
                                <a href="?status=overdue" class="btn btn-outline-danger btn-sm <?php echo $status_filter == 'overdue' ? 'active' : ''; ?>">
                                    <i class="fas fa-exclamation-triangle mr-1"></i> Overdue
                                </a>
                                <a href="?status=returned" class="btn btn-outline-success btn-sm <?php echo $status_filter == 'returned' ? 'active' : ''; ?>">
                                    <i class="fas fa-check mr-1"></i> Returned
                                </a>
                                <a href="?condition=damaged" class="btn btn-outline-dark btn-sm <?php echo $condition_filter == 'damaged' ? 'active' : ''; ?>">
                                    <i class="fas fa-times-circle mr-1"></i> Damaged Returns
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
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=cl.checkout_date&order=<?php echo $disp; ?>">
                        Checkout Date <?php if ($sort == 'cl.checkout_date') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=a.asset_tag&order=<?php echo $disp; ?>">
                        Asset <?php if ($sort == 'a.asset_tag') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=checked_out_by.user_name&order=<?php echo $disp; ?>">
                        Checked Out By <?php if ($sort == 'checked_out_by.user_name') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=assigned_to.user_name&order=<?php echo $disp; ?>">
                        Assigned To <?php if ($sort == 'assigned_to.user_name') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=a.assigned_department&order=<?php echo $disp; ?>">
                        Department <?php if ($sort == 'a.assigned_department') { echo $order_icon; } ?>
                    </a>
                </th>
                <th class="text-center">
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=cl.expected_return_date&order=<?php echo $disp; ?>">
                        Expected Return <?php if ($sort == 'cl.expected_return_date') { echo $order_icon; } ?>
                    </a>
                </th>
                <th class="text-center">
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=cl.checkin_date&order=<?php echo $disp; ?>">
                        Checkin Date <?php if ($sort == 'cl.checkin_date') { echo $order_icon; } ?>
                    </a>
                </th>
                <th class="text-center">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php 
            if ($num_rows[0] == 0) {
                ?>
                <tr>
                    <td colspan="8" class="text-center py-5">
                        <i class="fas fa-exchange-alt fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No checkout records found</h5>
                        <p class="text-muted">
                            <?php 
                            if ($q || $status_filter || $asset_filter || $user_filter || $department_filter || $condition_filter || $date_from || $date_to) {
                                echo "Try adjusting your search or filter criteria.";
                            } else {
                                echo "Get started by adding your first checkout record.";
                            }
                            ?>
                        </p>
                        <a href="asset_checkout_new.php" class="btn btn-primary">
                            <i class="fas fa-plus mr-2"></i>Add First Checkout
                        </a>
                        <?php if ($q || $status_filter || $asset_filter || $user_filter || $department_filter || $condition_filter || $date_from || $date_to): ?>
                            <a href="asset_checkout.php" class="btn btn-secondary ml-2">
                                <i class="fas fa-times mr-2"></i>Clear Filters
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php
            } else {
                while ($row = mysqli_fetch_array($sql)) {
                    $checkout_id = intval($row['checkout_id']);
                    $checkout_date = nullable_htmlentities($row['checkout_date']);
                    $asset_tag = nullable_htmlentities($row['asset_tag']);
                    $asset_name = nullable_htmlentities($row['asset_name']);
                    $checked_out_by = nullable_htmlentities($row['checked_out_by_name']);
                    $assigned_to = nullable_htmlentities($row['assigned_to_name']);
                    $department = nullable_htmlentities($row['assigned_department']);
                    $expected_return_date = nullable_htmlentities($row['expected_return_date']);
                    $checkin_date = nullable_htmlentities($row['checkin_date']);
                    $checkin_condition = nullable_htmlentities($row['checkin_condition']);
                    $checkout_notes = nullable_htmlentities($row['checkout_notes']);
                    $checkin_notes = nullable_htmlentities($row['checkin_notes']);
                    $days_out = intval($row['days_out']);
                    $days_until_due = intval($row['days_until_due']);
                    $checkout_duration = intval($row['checkout_duration']);
                    $asset_original_condition = nullable_htmlentities($row['asset_original_condition']);
                    $is_critical = boolval($row['is_critical']);
                    $asset_checkout_count = intval($row['asset_checkout_count']);
                    $user_active_checkouts = intval($row['user_active_checkouts']);

                    // Status determination
                    if ($checkin_date) {
                        $status = 'Returned';
                        $status_badge = 'success';
                        $status_icon = 'fa-check';
                    } elseif ($days_until_due < 0) {
                        $status = 'Overdue';
                        $status_badge = 'danger';
                        $status_icon = 'fa-exclamation-triangle';
                    } else {
                        $status = 'Checked Out';
                        $status_badge = 'warning';
                        $status_icon = 'fa-handshake';
                    }

                    // Condition badge styling
                    $condition_badge = '';
                    $condition_icon = '';
                    if ($checkin_condition) {
                        switch($checkin_condition) {
                            case 'excellent': 
                                $condition_badge = 'success';
                                $condition_icon = 'fa-check-circle';
                                break;
                            case 'good': 
                                $condition_badge = 'info';
                                $condition_icon = 'fa-thumbs-up';
                                break;
                            case 'fair': 
                                $condition_badge = 'warning';
                                $condition_icon = 'fa-meh';
                                break;
                            case 'poor': 
                                $condition_badge = 'warning';
                                $condition_icon = 'fa-frown';
                                break;
                            case 'damaged': 
                                $condition_badge = 'danger';
                                $condition_icon = 'fa-times-circle';
                                break;
                            default: 
                                $condition_badge = 'secondary';
                                $condition_icon = 'fa-question-circle';
                        }
                    }

                    // Check if long checkout
                    $is_long_checkout = $checkout_duration > 30;
                    
                    // Check if critical asset
                    $is_critical_asset = $is_critical;
                    ?>
                    <tr class="<?php echo $status == 'Overdue' ? 'table-danger' : ($is_critical_asset ? 'table-warning' : ($is_long_checkout ? 'table-info' : '')); ?>">
                        <td>
                            <div class="font-weight-bold"><?php echo date('M d, Y', strtotime($checkout_date)); ?></div>
                            <small class="text-muted"><?php echo date('h:i A', strtotime($checkout_date)); ?></small>
                            <?php if ($checkout_duration > 0): ?>
                                <small class="d-block text-muted">
                                    <i class="fas fa-clock mr-1"></i><?php echo $checkout_duration; ?> days
                                </small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="font-weight-bold text-primary"><?php echo $asset_tag; ?></div>
                            <small class="text-muted"><?php echo $asset_name; ?></small>
                            <?php if ($asset_original_condition): ?>
                                <small class="d-block">
                                    <span class="badge badge-secondary"><?php echo ucfirst($asset_original_condition); ?></span>
                                </small>
                            <?php endif; ?>
                            <?php if ($is_critical_asset): ?>
                                <small class="d-block text-danger">
                                    <i class="fas fa-exclamation-triangle mr-1"></i>Critical Asset
                                </small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="font-weight-bold"><?php echo $checked_out_by ?: 'N/A'; ?></div>
                        </td>
                        <td>
                            <div class="font-weight-bold"><?php echo $assigned_to ?: 'N/A'; ?></div>
                            <?php if ($user_active_checkouts > 1 && !$checkin_date): ?>
                                <small class="text-info">
                                    <i class="fas fa-user mr-1"></i><?php echo $user_active_checkouts - 1; ?> more
                                </small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($department): ?>
                                <span class="badge badge-info"><?php echo $department; ?></span>
                            <?php else: ?>
                                <span class="badge badge-light">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($expected_return_date): ?>
                                <div class="font-weight-bold"><?php echo date('M d, Y', strtotime($expected_return_date)); ?></div>
                                <?php if ($days_until_due < 0 && !$checkin_date): ?>
                                    <small class="text-danger">
                                        <i class="fas fa-exclamation-triangle mr-1"></i><?php echo abs($days_until_due); ?> days overdue
                                    </small>
                                <?php elseif ($days_until_due <= 7 && !$checkin_date): ?>
                                    <small class="text-warning">
                                        <i class="fas fa-clock mr-1"></i><?php echo $days_until_due; ?> days left
                                    </small>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">No date set</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($checkin_date): ?>
                                <div class="font-weight-bold text-success"><?php echo date('M d, Y', strtotime($checkin_date)); ?></div>
                                <small class="text-muted"><?php echo date('h:i A', strtotime($checkin_date)); ?></small>
                                <?php if ($checkin_condition): ?>
                                    <small class="d-block">
                                        <span class="badge <?php echo $condition_badge; ?>">
                                            <i class="fas <?php echo $condition_icon; ?> mr-1"></i><?php echo ucfirst($checkin_condition); ?>
                                        </span>
                                    </small>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="badge badge-light">Not returned</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="dropdown dropleft text-center">
                                <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                    <i class="fas fa-ellipsis-h"></i>
                                </button>
                                <div class="dropdown-menu">
                                    <a class="dropdown-item" href="asset_checkout_view.php?id=<?php echo $checkout_id; ?>">
                                        <i class="fas fa-fw fa-eye mr-2"></i>View Details
                                    </a>
                                    <?php if (!$checkin_date): ?>
                                        <a class="dropdown-item text-success" href="asset_checkin.php?id=<?php echo $checkout_id; ?>">
                                            <i class="fas fa-fw fa-undo mr-2"></i>Checkin Asset
                                        </a>
                                    <?php endif; ?>
                                    <a class="dropdown-item" href="asset_checkout_edit.php?id=<?php echo $checkout_id; ?>">
                                        <i class="fas fa-fw fa-edit mr-2"></i>Edit Record
                                    </a>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item" href="asset_view.php?id=<?php echo $row['asset_id']; ?>">
                                        <i class="fas fa-fw fa-cube mr-2"></i>View Asset
                                        <span class="badge badge-light float-right"><?php echo $asset_checkout_count; ?></span>
                                    </a>
                                    <?php if ($checkin_condition == 'damaged'): ?>
                                        <a class="dropdown-item text-danger" href="asset_maintenance_new.php?asset_id=<?php echo $row['asset_id']; ?>&checkout_id=<?php echo $checkout_id; ?>">
                                            <i class="fas fa-fw fa-tools mr-2"></i>Create Maintenance
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php
                }
            }
            ?>
            </tbody>
        </table>
    </div>
    
    <!-- Ends Card Body -->
    <?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/filter_footer.php'; ?>
</div>

<script>
$(document).ready(function() {
    $('.select2').select2();
    $('[data-toggle="tooltip"]').tooltip();

    // Auto-submit when filters change
    $('select[name="status"], select[name="asset"], select[name="user"], select[name="department"], select[name="condition"]').change(function() {
        $(this).closest('form').submit();
    });

    // Quick filter buttons
    $('.btn-group-toggle .btn').click(function(e) {
        e.preventDefault();
        window.location.href = $(this).attr('href');
    });

    // Set default date range (last 30 days) if no dates selected
    if (!$('input[name="date_from"]').val() && !$('input[name="date_to"]').val()) {
        var thirtyDaysAgo = new Date();
        thirtyDaysAgo.setDate(thirtyDaysAgo.getDate() - 30);
        var today = new Date().toISOString().split('T')[0];
        var thirtyDaysAgoFormatted = thirtyDaysAgo.toISOString().split('T')[0];
        
        $('input[name="date_from"]').val(thirtyDaysAgoFormatted);
        $('input[name="date_to"]').val(today);
    }
});

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + N for new checkout
    if (e.ctrlKey && e.keyCode === 78) {
        e.preventDefault();
        window.location.href = 'asset_checkout_new.php';
    }
    // Ctrl + A for assets
    if (e.ctrlKey && e.keyCode === 65) {
        e.preventDefault();
        window.location.href = 'asset_management.php';
    }
    // Ctrl + M for maintenance
    if (e.ctrlKey && e.keyCode === 77) {
        e.preventDefault();
        window.location.href = 'asset_maintenance.php';
    }
    // Ctrl + F for focus search
    if (e.ctrlKey && e.keyCode === 70) {
        e.preventDefault();
        $('input[name="q"]').focus();
    }
    // Ctrl + R for reports
    if (e.ctrlKey && e.keyCode === 82) {
        e.preventDefault();
        window.location.href = 'asset_reports.php?report=checkout';
    }
});
</script>

<style>
.card .card-body {
    padding: 1rem;
}

.list-group-item {
    border: none;
    padding: 0.75rem 1rem;
}

.list-group-item:hover {
    background-color: #f8f9fa;
}

.badge-pill {
    padding: 0.5em 0.8em;
}

.btn-group-toggle .btn.active {
    background-color: #007bff;
    border-color: #007bff;
    color: white;
}

.alert-container .alert {
    margin-bottom: 0.5rem;
}

.table-danger {
    background-color: #f8d7da !important;
}

.table-warning {
    background-color: #fff3cd !important;
}

.table-info {
    background-color: #d1ecf1 !important;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
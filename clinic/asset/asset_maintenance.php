<?php
// asset_maintenance.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Default Column Sortby/Order Filter
$sort = "am.maintenance_date";
$order = "DESC";

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Filter parameters
$status_filter = $_GET['status'] ?? '';
$type_filter = $_GET['type'] ?? '';
$asset_filter = $_GET['asset'] ?? '';
$supplier_filter = $_GET['supplier'] ?? '';
$cost_filter = $_GET['cost'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Search Query
$q = sanitizeInput($_GET['q'] ?? '');
if (!empty($q)) {
    $search_query = "AND (
        a.asset_tag LIKE '%$q%' 
        OR a.asset_name LIKE '%$q%'
        OR am.description LIKE '%$q%'
        OR am.performed_by LIKE '%$q%'
        OR s.supplier_name LIKE '%$q%'
        OR am.findings LIKE '%$q%'
        OR am.recommendations LIKE '%$q%'
    )";
} else {
    $search_query = '';
}

// Status Filter
if ($status_filter) {
    $status_query = "AND am.status = '" . sanitizeInput($status_filter) . "'";
} else {
    $status_query = '';
}

// Type Filter
if ($type_filter) {
    $type_query = "AND am.maintenance_type = '" . sanitizeInput($type_filter) . "'";
} else {
    $type_query = '';
}

// Asset Filter
if ($asset_filter) {
    $asset_query = "AND am.asset_id = " . intval($asset_filter);
} else {
    $asset_query = '';
}

// Supplier Filter
if ($supplier_filter) {
    $supplier_query = "AND am.supplier_id = " . intval($supplier_filter);
} else {
    $supplier_query = '';
}

// Cost Filter
if ($cost_filter == 'high_cost') {
    $cost_query = "AND cost >= 500";
} elseif ($cost_filter == 'low_cost') {
    $cost_query = "AND cost < 500 AND cost > 0";
} elseif ($cost_filter == 'no_cost') {
    $cost_query = "AND (cost = 0 OR cost IS NULL)";
} else {
    $cost_query = '';
}

// Date Range Filter
$date_query = '';
if ($date_from) {
    $date_query .= "AND am.maintenance_date >= '" . sanitizeInput($date_from) . "' ";
}
if ($date_to) {
    $date_query .= "AND am.maintenance_date <= '" . sanitizeInput($date_to) . "' ";
}

// Get statistics
$stats_sql = "SELECT 
    COUNT(*) as total_maintenance,
    SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) as scheduled,
    SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
    COALESCE(SUM(cost), 0) as total_cost,
    COALESCE(AVG(cost), 0) as avg_cost,
    SUM(CASE WHEN scheduled_date IS NOT NULL AND scheduled_date < CURDATE() AND status IN ('scheduled', 'in_progress') THEN 1 ELSE 0 END) as overdue_scheduled,
    SUM(CASE WHEN maintenance_date < CURDATE() AND status IN ('scheduled', 'in_progress') THEN 1 ELSE 0 END) as overdue_maintenance,
    SUM(CASE WHEN next_maintenance_date IS NOT NULL AND next_maintenance_date < CURDATE() THEN 1 ELSE 0 END) as overdue_next,
    (SELECT COUNT(DISTINCT asset_id) FROM asset_maintenance) as unique_assets,
    (SELECT COUNT(*) FROM assets WHERE status = 'active') as total_assets
    FROM asset_maintenance am
    WHERE 1=1
    $status_query
    $type_query
    $asset_query
    $supplier_query
    $cost_query
    $date_query
    $search_query";

$stats_result = $mysqli->query($stats_sql);
$stats = $stats_result->fetch_assoc();

// Get maintenance by type statistics
$type_stats_sql = "SELECT 
    maintenance_type,
    COUNT(*) as count,
    COALESCE(SUM(cost), 0) as total_cost,
    AVG(cost) as avg_cost
    FROM asset_maintenance
    WHERE 1=1
    $status_query
    $asset_query
    $supplier_query
    $cost_query
    $date_query
    $search_query
    GROUP BY maintenance_type
    ORDER BY count DESC";

$type_stats_result = $mysqli->query($type_stats_sql);
$type_stats = [];
while ($row = $type_stats_result->fetch_assoc()) {
    $type_stats[$row['maintenance_type']] = $row;
}

// Main query for maintenance records
$sql = mysqli_query(
    $mysqli,
    "
    SELECT SQL_CALC_FOUND_ROWS am.*,
           a.asset_tag,
           a.asset_name,
           a.serial_number,
           a.asset_condition,
           s.supplier_name,
           creator.user_name as created_by_name,
           performer.user_name as performed_by_name,
           DATEDIFF(CURDATE(), am.maintenance_date) as days_ago,
           DATEDIFF(am.scheduled_date, CURDATE()) as days_until_scheduled,
           DATEDIFF(am.next_maintenance_date, CURDATE()) as days_until_next,
           (SELECT COUNT(*) FROM asset_maintenance am2 WHERE am2.asset_id = a.asset_id) as asset_maintenance_count
    FROM asset_maintenance am
    LEFT JOIN assets a ON am.asset_id = a.asset_id
    LEFT JOIN suppliers s ON am.supplier_id = s.supplier_id
    LEFT JOIN users creator ON am.created_by = creator.user_id
    LEFT JOIN users performer ON am.performed_by = performer.user_id
    WHERE 1=1
      $status_query
      $type_query
      $asset_query
      $supplier_query
      $cost_query
      $date_query
      $search_query
    ORDER BY $sort $order
    LIMIT $record_from, $record_to
");

if (!$sql) {
    die("Query failed: " . mysqli_error($mysqli));
}

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get assets for filter
$assets_sql = "SELECT asset_id, asset_tag, asset_name FROM assets WHERE status IN ('active', 'under_maintenance') ORDER BY asset_tag";
$assets_result = $mysqli->query($assets_sql);

// Get suppliers for filter
$suppliers_sql = "SELECT supplier_id, supplier_name FROM suppliers WHERE supplier_is_active = 1 ORDER BY supplier_name";
$suppliers_result = $mysqli->query($suppliers_sql);

// Get today's maintenance summary
$today_maintenance_sql = "
    SELECT 
        COUNT(*) as count,
        SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) as scheduled_today,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_today,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_today
    FROM asset_maintenance 
    WHERE DATE(maintenance_date) = CURDATE()
";
$today_maintenance_result = mysqli_query($mysqli, $today_maintenance_sql);
$today_maintenance = mysqli_fetch_assoc($today_maintenance_result);

// Get upcoming maintenance (next 7 days)
$upcoming_maintenance_sql = "
    SELECT COUNT(*) as count 
    FROM asset_maintenance 
    WHERE status IN ('scheduled', 'in_progress') 
    AND scheduled_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
";
$upcoming_maintenance_result = mysqli_query($mysqli, $upcoming_maintenance_sql);
$upcoming_maintenance = mysqli_fetch_assoc($upcoming_maintenance_result)['count'];

// Get high cost maintenance count
$high_cost_maintenance_sql = "SELECT COUNT(*) as count FROM asset_maintenance WHERE cost >= 1000";
$high_cost_maintenance_result = mysqli_query($mysqli, $high_cost_maintenance_sql);
$high_cost_maintenance = mysqli_fetch_assoc($high_cost_maintenance_result)['count'];
?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2 mb-0 text-white"><i class="fas fa-fw fa-tools mr-2"></i>Asset Maintenance Dashboard</h3>
        <div class="card-tools">
            <div class="btn-group">
                <a href="asset_maintenance_new.php" class="btn btn-success">
                    <i class="fas fa-plus mr-2"></i>New Maintenance
                </a>

                <a href="asset_management.php" class="btn btn-primary ml-2">
                    <i class="fas fa-cubes mr-2"></i>View Assets
                </a>

                <a href="asset_checkout.php" class="btn btn-warning ml-2">
                    <i class="fas fa-exchange-alt mr-2"></i>Checkout/Checkin
                </a>

                <a href="asset_reports.php?report=maintenance" class="btn btn-info ml-2">
                    <i class="fas fa-chart-bar mr-2"></i>Reports
                </a>

                <div class="btn-group ml-2">
                    <button type="button" class="btn btn-secondary dropdown-toggle" data-toggle="dropdown">
                        <i class="fas fa-tasks mr-2"></i>Quick Actions
                    </button>
                    <div class="dropdown-menu">
                        <a class="dropdown-item" href="asset_maintenance_schedule.php">
                            <i class="fas fa-calendar-alt mr-2"></i>Maintenance Schedule
                        </a>
                        <a href="asset_maintenance_calendar.php" class="dropdown-item">
                            <i class="fas fa-calendar mr-2"></i>Calendar View
                        </a>
                        <a href="suppliers.php" class="dropdown-item">
                            <i class="fas fa-truck mr-2"></i>Manage Suppliers
                            <span class="badge badge-light float-right">
                                <?php echo mysqli_num_rows($suppliers_result); ?>
                            </span>
                        </a>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item" href="asset_maintenance_export.php">
                            <i class="fas fa-file-export mr-2"></i>Export Maintenance Data
                        </a>
                        <a class="dropdown-item" href="asset_maintenance_print.php">
                            <i class="fas fa-print mr-2"></i>Print Maintenance Report
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Enhanced Stats Dashboard -->
    <div class="card-body bg-light border-bottom">
        <div class="row text-center">
            <!-- Total Maintenance Records -->
            <div class="col-md-2 mb-3">
                <div class="card bg-primary text-white">
                    <div class="card-body py-3">
                        <i class="fas fa-tools fa-2x mb-2"></i>
                        <h5 class="card-title">Total Records</h5>
                        <h3 class="font-weight-bold"><?php echo $stats['total_maintenance']; ?></h3>
                        <small class="opacity-8"><?php echo $stats['unique_assets']; ?>/<?php echo $stats['total_assets']; ?> assets</small>
                    </div>
                </div>
            </div>
            
            <!-- Completed Maintenance -->
            <div class="col-md-2 mb-3">
                <div class="card bg-success text-white">
                    <div class="card-body py-3">
                        <i class="fas fa-check-circle fa-2x mb-2"></i>
                        <h5 class="card-title">Completed</h5>
                        <h3 class="font-weight-bold"><?php echo $stats['completed']; ?></h3>
                        <small class="opacity-8">Successfully completed</small>
                    </div>
                </div>
            </div>
            
            <!-- Overdue Maintenance -->
            <div class="col-md-2 mb-3">
                <div class="card bg-danger text-white">
                    <div class="card-body py-3">
                        <i class="fas fa-exclamation-triangle fa-2x mb-2"></i>
                        <h5 class="card-title">Overdue</h5>
                        <h3 class="font-weight-bold"><?php echo $stats['overdue_scheduled']; ?></h3>
                        <small class="opacity-8">Past scheduled date</small>
                    </div>
                </div>
            </div>
            
            <!-- Total Cost -->
            <div class="col-md-2 mb-3">
                <div class="card bg-info text-white">
                    <div class="card-body py-3">
                        <i class="fas fa-dollar-sign fa-2x mb-2"></i>
                        <h5 class="card-title">Total Cost</h5>
                        <h3 class="font-weight-bold">$<?php echo number_format($stats['total_cost'], 2); ?></h3>
                        <small class="opacity-8">Avg: $<?php echo number_format($stats['avg_cost'], 2); ?></small>
                    </div>
                </div>
            </div>
            
            <!-- Today's Maintenance -->
            <div class="col-md-2 mb-3">
                <div class="card bg-warning text-dark">
                    <div class="card-body py-3">
                        <i class="fas fa-calendar-day fa-2x mb-2"></i>
                        <h5 class="card-title">Today's Tasks</h5>
                        <h3 class="font-weight-bold"><?php echo $today_maintenance['count']; ?></h3>
                        <small class="opacity-8">Scheduled for today</small>
                    </div>
                </div>
            </div>
            
            <!-- Upcoming Maintenance -->
            <div class="col-md-2 mb-3">
                <div class="card bg-dark text-white">
                    <div class="card-body py-3">
                        <i class="fas fa-calendar-alt fa-2x mb-2"></i>
                        <h5 class="card-title">Upcoming</h5>
                        <h3 class="font-weight-bold"><?php echo $upcoming_maintenance; ?></h3>
                        <small class="opacity-8">Next 7 days</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Alert Row -->
    <?php if ($stats['overdue_scheduled'] > 0 || $stats['overdue_maintenance'] > 0 || $stats['overdue_next'] > 0 || $high_cost_maintenance > 0): ?>
    <div class="row mt-3">
        <div class="col-12">
            <div class="alert-container">
                <?php if ($stats['overdue_scheduled'] > 0): ?>
                <div class="alert alert-danger alert-dismissible fade show mb-2" role="alert">
                    <i class="fas fa-calendar-times mr-2"></i>
                    <strong><?php echo $stats['overdue_scheduled']; ?> maintenance record(s)</strong> are past their scheduled date.
                    <a href="?status=scheduled" class="alert-link ml-2">View Scheduled Maintenance</a>
                    <button type="button" class="close" data-dismiss="alert">
                        <span>&times;</span>
                    </button>
                </div>
                <?php endif; ?>
                
                <?php if ($stats['overdue_maintenance'] > 0): ?>
                <div class="alert alert-warning alert-dismissible fade show mb-2" role="alert">
                    <i class="fas fa-tools mr-2"></i>
                    <strong><?php echo $stats['overdue_maintenance']; ?> maintenance record(s)</strong> are overdue.
                    <a href="?status=in_progress" class="alert-link ml-2">View In Progress</a>
                    <button type="button" class="close" data-dismiss="alert">
                        <span>&times;</span>
                    </button>
                </div>
                <?php endif; ?>
                
                <?php if ($stats['overdue_next'] > 0): ?>
                <div class="alert alert-info alert-dismissible fade show mb-2" role="alert">
                    <i class="fas fa-clock mr-2"></i>
                    <strong><?php echo $stats['overdue_next']; ?> asset(s)</strong> have overdue next maintenance.
                    <a href="asset_management.php?status=active" class="alert-link ml-2">View Assets</a>
                    <button type="button" class="close" data-dismiss="alert">
                        <span>&times;</span>
                    </button>
                </div>
                <?php endif; ?>

                <?php if ($high_cost_maintenance > 0): ?>
                <div class="alert alert-dark alert-dismissible fade show mb-2" role="alert">
                    <i class="fas fa-dollar-sign mr-2"></i>
                    <strong><?php echo $high_cost_maintenance; ?> high-cost maintenance record(s)</strong> ($1000+).
                    <a href="?cost=high_cost" class="alert-link ml-2">View High Cost</a>
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
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search maintenance, assets, descriptions, findings..." autofocus>
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
                            <span class="btn btn-light border" data-toggle="tooltip" title="Total Records">
                                <i class="fas fa-tools text-dark mr-1"></i>
                                <strong><?php echo $stats['total_maintenance']; ?></strong>
                            </span>
                            <span class="btn btn-light border" data-toggle="tooltip" title="Completed">
                                <i class="fas fa-check-circle text-success mr-1"></i>
                                <strong><?php echo $stats['completed']; ?></strong>
                            </span>
                            <span class="btn btn-light border" data-toggle="tooltip" title="Scheduled">
                                <i class="fas fa-calendar text-info mr-1"></i>
                                <strong><?php echo $stats['scheduled']; ?></strong>
                            </span>
                            <span class="btn btn-light border" data-toggle="tooltip" title="Overdue">
                                <i class="fas fa-exclamation-triangle text-danger mr-1"></i>
                                <strong><?php echo $stats['overdue_scheduled']; ?></strong>
                            </span>
                            <a href="asset_maintenance_new.php" class="btn btn-success ml-2">
                                <i class="fas fa-fw fa-plus mr-2"></i>New Maintenance
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php if ($status_filter || $type_filter || $asset_filter || $supplier_filter || $cost_filter || $date_from || $date_to) { echo "show"; } ?>" id="advancedFilter">
                <div class="row">
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Status</label>
                            <select class="form-control select2" name="status" onchange="this.form.submit()">
                                <option value="">- All Status -</option>
                                <option value="scheduled" <?php if ($status_filter == "scheduled") { echo "selected"; } ?>>Scheduled</option>
                                <option value="in_progress" <?php if ($status_filter == "in_progress") { echo "selected"; } ?>>In Progress</option>
                                <option value="completed" <?php if ($status_filter == "completed") { echo "selected"; } ?>>Completed</option>
                                <option value="cancelled" <?php if ($status_filter == "cancelled") { echo "selected"; } ?>>Cancelled</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Type</label>
                            <select class="form-control select2" name="type" onchange="this.form.submit()">
                                <option value="">- All Types -</option>
                                <option value="preventive" <?php if ($type_filter == "preventive") { echo "selected"; } ?>>Preventive</option>
                                <option value="corrective" <?php if ($type_filter == "corrective") { echo "selected"; } ?>>Corrective</option>
                                <option value="calibration" <?php if ($type_filter == "calibration") { echo "selected"; } ?>>Calibration</option>
                                <option value="inspection" <?php if ($type_filter == "inspection") { echo "selected"; } ?>>Inspection</option>
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
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Quick Actions</label>
                            <div class="btn-group btn-block">
                                <a href="asset_maintenance.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times mr-2"></i>Clear Filters
                                </a>
                                <a href="asset_maintenance_new.php" class="btn btn-success">
                                    <i class="fas fa-plus mr-2"></i>New Maintenance
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row mt-2">
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Supplier</label>
                            <select class="form-control select2" name="supplier" onchange="this.form.submit()">
                                <option value="">- All Suppliers -</option>
                                <?php while($supplier = $suppliers_result->fetch_assoc()): ?>
                                    <option value="<?php echo $supplier['supplier_id']; ?>" <?php if ($supplier_filter == $supplier['supplier_id']) { echo "selected"; } ?>>
                                        <?php echo htmlspecialchars($supplier['supplier_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Cost Range</label>
                            <select class="form-control select2" name="cost" onchange="this.form.submit()">
                                <option value="">- All Costs -</option>
                                <option value="high_cost" <?php if ($cost_filter == "high_cost") { echo "selected"; } ?>>High Cost ($500+)</option>
                                <option value="low_cost" <?php if ($cost_filter == "low_cost") { echo "selected"; } ?>>Low Cost (<$500)</option>
                                <option value="no_cost" <?php if ($cost_filter == "no_cost") { echo "selected"; } ?>>No Cost</option>
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
                                <a href="?status=scheduled" class="btn btn-outline-info btn-sm <?php echo $status_filter == 'scheduled' ? 'active' : ''; ?>">
                                    <i class="fas fa-calendar mr-1"></i> Scheduled
                                </a>
                                <a href="?status=in_progress" class="btn btn-outline-warning btn-sm <?php echo $status_filter == 'in_progress' ? 'active' : ''; ?>">
                                    <i class="fas fa-wrench mr-1"></i> In Progress
                                </a>
                                <a href="?status=completed" class="btn btn-outline-success btn-sm <?php echo $status_filter == 'completed' ? 'active' : ''; ?>">
                                    <i class="fas fa-check mr-1"></i> Completed
                                </a>
                                <a href="?cost=high_cost" class="btn btn-outline-dark btn-sm <?php echo $cost_filter == 'high_cost' ? 'active' : ''; ?>">
                                    <i class="fas fa-dollar-sign mr-1"></i> High Cost
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
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=am.maintenance_date&order=<?php echo $disp; ?>">
                        Date <?php if ($sort == 'am.maintenance_date') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=a.asset_tag&order=<?php echo $disp; ?>">
                        Asset <?php if ($sort == 'a.asset_tag') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=am.maintenance_type&order=<?php echo $disp; ?>">
                        Type <?php if ($sort == 'am.maintenance_type') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>Description</th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=am.performed_by&order=<?php echo $disp; ?>">
                        Performed By <?php if ($sort == 'am.performed_by') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=s.supplier_name&order=<?php echo $disp; ?>">
                        Supplier <?php if ($sort == 's.supplier_name') { echo $order_icon; } ?>
                    </a>
                </th>
                <th class="text-right">
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=am.cost&order=<?php echo $disp; ?>">
                        Cost <?php if ($sort == 'am.cost') { echo $order_icon; } ?>
                    </a>
                </th>
                <th class="text-center">
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=am.status&order=<?php echo $disp; ?>">
                        Status <?php if ($sort == 'am.status') { echo $order_icon; } ?>
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
                    <td colspan="9" class="text-center py-5">
                        <i class="fas fa-tools fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No maintenance records found</h5>
                        <p class="text-muted">
                            <?php 
                            if ($q || $status_filter || $type_filter || $asset_filter || $supplier_filter || $cost_filter || $date_from || $date_to) {
                                echo "Try adjusting your search or filter criteria.";
                            } else {
                                echo "Get started by adding your first maintenance record.";
                            }
                            ?>
                        </p>
                        <a href="asset_maintenance_new.php" class="btn btn-primary">
                            <i class="fas fa-plus mr-2"></i>Add First Maintenance
                        </a>
                        <?php if ($q || $status_filter || $type_filter || $asset_filter || $supplier_filter || $cost_filter || $date_from || $date_to): ?>
                            <a href="asset_maintenance.php" class="btn btn-secondary ml-2">
                                <i class="fas fa-times mr-2"></i>Clear Filters
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php
            } else {
                while ($row = mysqli_fetch_array($sql)) {
                    $maintenance_id = intval($row['maintenance_id']);
                    $maintenance_date = nullable_htmlentities($row['maintenance_date']);
                    $scheduled_date = nullable_htmlentities($row['scheduled_date']);
                    $completed_date = nullable_htmlentities($row['completed_date']);
                    $asset_tag = nullable_htmlentities($row['asset_tag']);
                    $asset_name = nullable_htmlentities($row['asset_name']);
                    $asset_condition = nullable_htmlentities($row['asset_condition']);
                    $maintenance_type = nullable_htmlentities($row['maintenance_type']);
                    $description = nullable_htmlentities($row['description']);
                    $findings = nullable_htmlentities($row['findings']);
                    $recommendations = nullable_htmlentities($row['recommendations']);
                    $performed_by = nullable_htmlentities($row['performed_by_name']) ?: $row['performed_by'];
                    $supplier_name = nullable_htmlentities($row['supplier_name']);
                    $cost = floatval($row['cost']);
                    $status = nullable_htmlentities($row['status']);
                    $next_maintenance_date = nullable_htmlentities($row['next_maintenance_date']);
                    $days_ago = intval($row['days_ago']);
                    $days_until_scheduled = intval($row['days_until_scheduled']);
                    $days_until_next = intval($row['days_until_next']);
                    $asset_maintenance_count = intval($row['asset_maintenance_count']);
                    $serial_number = nullable_htmlentities($row['serial_number']);

                    // Status badge styling
                    $status_badge = "";
                    $status_icon = "";
                    switch($status) {
                        case 'scheduled':
                            $status_badge = $days_until_scheduled < 0 ? "badge-danger" : "badge-warning";
                            $status_icon = $days_until_scheduled < 0 ? "fa-calendar-times" : "fa-calendar";
                            break;
                        case 'in_progress':
                            $status_badge = "badge-primary";
                            $status_icon = "fa-wrench";
                            break;
                        case 'completed':
                            $status_badge = "badge-success";
                            $status_icon = "fa-check";
                            break;
                        case 'cancelled':
                            $status_badge = "badge-secondary";
                            $status_icon = "fa-ban";
                            break;
                        default:
                            $status_badge = "badge-light";
                            $status_icon = "fa-question-circle";
                    }

                    // Type badge styling
                    $type_badge = "";
                    switch($maintenance_type) {
                        case 'preventive': $type_badge = "badge-primary"; break;
                        case 'corrective': $type_badge = "badge-danger"; break;
                        case 'calibration': $type_badge = "badge-info"; break;
                        case 'inspection': $type_badge = "badge-warning"; break;
                        default: $type_badge = "badge-secondary";
                    }

                    // Check if overdue
                    $is_overdue = false;
                    if ($status == 'scheduled' && $days_until_scheduled < 0) {
                        $is_overdue = true;
                    }

                    // Check if high cost
                    $is_high_cost = $cost >= 500;
                    ?>
                    <tr class="<?php echo $is_overdue ? 'table-danger' : ($is_high_cost ? 'table-warning' : ''); ?>">
                        <td>
                            <div class="font-weight-bold"><?php echo date('M d, Y', strtotime($maintenance_date)); ?></div>
                            <?php if ($scheduled_date): ?>
                                <small class="text-muted">Scheduled: <?php echo date('M d, Y', strtotime($scheduled_date)); ?></small>
                            <?php endif; ?>
                            <?php if ($completed_date): ?>
                                <small class="d-block text-success">
                                    <i class="fas fa-check mr-1"></i>Completed: <?php echo date('M d, Y', strtotime($completed_date)); ?>
                                </small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="font-weight-bold text-primary"><?php echo $asset_tag; ?></div>
                            <small class="text-muted"><?php echo $asset_name; ?></small>
                            <?php if ($serial_number): ?>
                                <small class="d-block text-muted">SN: <?php echo $serial_number; ?></small>
                            <?php endif; ?>
                            <?php if ($asset_condition): ?>
                                <small class="d-block">
                                    <span class="badge badge-secondary"><?php echo ucfirst($asset_condition); ?></span>
                                </small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?php echo $type_badge; ?>">
                                <?php echo ucfirst($maintenance_type); ?>
                            </span>
                        </td>
                        <td>
                            <div class="font-weight-bold"><?php echo strlen($description) > 40 ? substr($description, 0, 40) . '...' : $description; ?></div>
                            <?php if ($findings): ?>
                                <small class="text-muted">Findings: <?php echo strlen($findings) > 30 ? substr($findings, 0, 30) . '...' : $findings; ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($performed_by): ?>
                                <div class="font-weight-bold"><?php echo $performed_by; ?></div>
                            <?php else: ?>
                                <span class="badge badge-light">Not assigned</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($supplier_name): ?>
                                <div class="font-weight-bold"><?php echo $supplier_name; ?></div>
                            <?php else: ?>
                                <span class="badge badge-light">Internal</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-right">
                            <?php if ($cost > 0): ?>
                                <div class="font-weight-bold text-success">$<?php echo number_format($cost, 2); ?></div>
                            <?php else: ?>
                                <span class="text-muted">$0.00</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <span class="badge <?php echo $status_badge; ?> badge-pill">
                                <i class="fas <?php echo $status_icon; ?> mr-1"></i>
                                <?php echo ucfirst(str_replace('_', ' ', $status)); ?>
                            </span>
                            <?php if ($next_maintenance_date): ?>
                                <small class="d-block <?php echo $days_until_next < 0 ? 'text-danger' : ($days_until_next <= 7 ? 'text-warning' : 'text-muted'); ?>">
                                    <i class="fas fa-clock mr-1"></i>
                                    Next: <?php echo date('M d, Y', strtotime($next_maintenance_date)); ?>
                                </small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="dropdown dropleft text-center">
                                <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                    <i class="fas fa-ellipsis-h"></i>
                                </button>
                                <div class="dropdown-menu">
                                    <a class="dropdown-item" href="asset_maintenance_view.php?id=<?php echo $maintenance_id; ?>">
                                        <i class="fas fa-fw fa-eye mr-2"></i>View Details
                                    </a>
                                    <a class="dropdown-item" href="asset_maintenance_edit.php?id=<?php echo $maintenance_id; ?>">
                                        <i class="fas fa-fw fa-edit mr-2"></i>Edit Record
                                    </a>
                                    <?php if ($status == 'scheduled'): ?>
                                        <a class="dropdown-item text-warning" href="asset_maintenance_start.php?id=<?php echo $maintenance_id; ?>">
                                            <i class="fas fa-fw fa-play mr-2"></i>Start Maintenance
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($status == 'in_progress'): ?>
                                        <a class="dropdown-item text-success" href="asset_maintenance_complete.php?id=<?php echo $maintenance_id; ?>">
                                            <i class="fas fa-fw fa-check mr-2"></i>Complete
                                        </a>
                                    <?php endif; ?>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item" href="asset_view.php?id=<?php echo $row['asset_id']; ?>">
                                        <i class="fas fa-fw fa-cube mr-2"></i>View Asset
                                        <span class="badge badge-light float-right"><?php echo $asset_maintenance_count; ?></span>
                                    </a>
                                    <div class="dropdown-divider"></div>
                                    <?php if ($status != 'cancelled'): ?>
                                        <a class="dropdown-item text-danger" href="#" onclick="confirmCancel(<?php echo $maintenance_id; ?>, '<?php echo addslashes($description); ?>')">
                                            <i class="fas fa-fw fa-ban mr-2"></i>Cancel Maintenance
                                        </a>
                                    <?php else: ?>
                                        <a class="dropdown-item text-success" href="#" onclick="confirmRestore(<?php echo $maintenance_id; ?>, '<?php echo addslashes($description); ?>')">
                                            <i class="fas fa-fw fa-redo mr-2"></i>Restore
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
    $('select[name="status"], select[name="type"], select[name="asset"], select[name="supplier"], select[name="cost"]').change(function() {
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

function confirmCancel(maintenanceId, description) {
    if (confirm('Are you sure you want to cancel maintenance: "' + description + '"? This action cannot be undone.')) {
        window.location.href = 'asset_maintenance_cancel.php?id=' + maintenanceId;
    }
}

function confirmRestore(maintenanceId, description) {
    if (confirm('Are you sure you want to restore maintenance: "' + description + '"?')) {
        window.location.href = 'asset_maintenance_restore.php?id=' + maintenanceId;
    }
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + N for new maintenance
    if (e.ctrlKey && e.keyCode === 78) {
        e.preventDefault();
        window.location.href = 'asset_maintenance_new.php';
    }
    // Ctrl + A for assets
    if (e.ctrlKey && e.keyCode === 65) {
        e.preventDefault();
        window.location.href = 'asset_management.php';
    }
    // Ctrl + C for checkout
    if (e.ctrlKey && e.keyCode === 67) {
        e.preventDefault();
        window.location.href = 'asset_checkout.php';
    }
    // Ctrl + F for focus search
    if (e.ctrlKey && e.keyCode === 70) {
        e.preventDefault();
        $('input[name="q"]').focus();
    }
    // Ctrl + R for reports
    if (e.ctrlKey && e.keyCode === 82) {
        e.preventDefault();
        window.location.href = 'asset_reports.php?report=maintenance';
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
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
<?php
// asset_management.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Default Column Sortby/Order Filter
$sort = "a.created_at";
$order = "DESC";

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Filter parameters
$status_filter = $_GET['status'] ?? '';
$category_filter = $_GET['category'] ?? '';
$location_filter = $_GET['location'] ?? '';
$condition_filter = $_GET['condition'] ?? '';
$assigned_filter = $_GET['assigned'] ?? '';
$supplier_filter = $_GET['supplier'] ?? '';
$critical_filter = $_GET['critical'] ?? '';
$value_filter = $_GET['value'] ?? '';

// Search Query
$q = sanitizeInput($_GET['q'] ?? '');
if (!empty($q)) {
    $search_query = "AND (
        a.asset_tag LIKE '%$q%' 
        OR a.asset_name LIKE '%$q%'
        OR a.serial_number LIKE '%$q%'
        OR a.model LIKE '%$q%'
        OR a.manufacturer LIKE '%$q%'
        OR ac.category_name LIKE '%$q%'
        OR al.location_name LIKE '%$q%'
        OR u.user_name LIKE '%$q%'
        OR s.supplier_name LIKE '%$q%'
    )";
} else {
    $search_query = '';
}

// Status Filter
if ($status_filter) {
    $status_query = "AND a.status = '" . sanitizeInput($status_filter) . "'";
} else {
    $status_query = '';
}

// Category Filter
if ($category_filter) {
    $category_query = "AND a.category_id = " . intval($category_filter);
} else {
    $category_query = '';
}

// Location Filter
if ($location_filter) {
    $location_query = "AND a.location_id = " . intval($location_filter);
} else {
    $location_query = '';
}

// Condition Filter
if ($condition_filter) {
    $condition_query = "AND a.asset_condition = '" . sanitizeInput($condition_filter) . "'";
} else {
    $condition_query = '';
}

// Assigned Filter
if ($assigned_filter) {
    $assigned_query = "AND a.assigned_to = " . intval($assigned_filter);
} else {
    $assigned_query = '';
}

// Supplier Filter
if ($supplier_filter) {
    $supplier_query = "AND a.supplier_id = " . intval($supplier_filter);
} else {
    $supplier_query = '';
}

// Critical Filter
if ($critical_filter == 'critical') {
    $critical_query = "AND a.is_critical = 1";
} else {
    $critical_query = '';
}

// Value Filter
if ($value_filter == 'high_value') {
    $value_query = "AND a.purchase_price >= 1000";
} elseif ($value_filter == 'low_value') {
    $value_query = "AND a.purchase_price < 1000 AND a.purchase_price > 0";
} elseif ($value_filter == 'no_value') {
    $value_query = "AND (a.purchase_price = 0 OR a.purchase_price IS NULL)";
} else {
    $value_query = '';
}

// Get statistics
$stats_sql = "SELECT 
    COUNT(*) as total_assets,
    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_assets,
    SUM(CASE WHEN status = 'under_maintenance' THEN 1 ELSE 0 END) as maintenance_assets,
    SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive_assets,
    SUM(CASE WHEN status = 'disposed' THEN 1 ELSE 0 END) as disposed_assets,
    SUM(CASE WHEN status = 'lost' THEN 1 ELSE 0 END) as lost_assets,
    SUM(CASE WHEN is_critical = 1 THEN 1 ELSE 0 END) as critical_assets,
    COALESCE(SUM(purchase_price), 0) as total_value,
    COALESCE(SUM(current_value), 0) as current_total_value,
    AVG(DATEDIFF(CURDATE(), a.purchase_date)) as avg_age_days,
    SUM(CASE WHEN a.next_maintenance_date IS NOT NULL AND a.next_maintenance_date < CURDATE() THEN 1 ELSE 0 END) as overdue_maintenance,
    SUM(CASE WHEN a.warranty_expiry IS NOT NULL AND a.warranty_expiry < CURDATE() THEN 1 ELSE 0 END) as expired_warranties,
    (SELECT COUNT(DISTINCT location_id) FROM assets WHERE location_id IS NOT NULL) as used_locations_count,
    (SELECT COUNT(*) FROM asset_locations WHERE is_active = 1) as total_locations_count
    FROM assets a
    WHERE 1=1
    $status_query
    $category_query
    $location_query
    $condition_query
    $assigned_query
    $supplier_query
    $critical_query
    $value_query
    $search_query";

$stats_result = $mysqli->query($stats_sql);
$stats = $stats_result->fetch_assoc();

// Get maintenance statistics
$maintenance_stats_sql = "SELECT 
    COUNT(*) as total_maintenance,
    SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) as scheduled,
    SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
    COALESCE(SUM(cost), 0) as total_cost
    FROM asset_maintenance am
    WHERE 1=1";

if ($status_filter == 'under_maintenance') {
    $maintenance_stats_sql .= " AND am.status IN ('scheduled', 'in_progress')";
}

$maintenance_stats_result = $mysqli->query($maintenance_stats_sql);
$maintenance_stats = $maintenance_stats_result->fetch_assoc();

// Main query for assets
$sql = mysqli_query(
    $mysqli,
    "
    SELECT SQL_CALC_FOUND_ROWS a.*, 
           ac.category_name,
           al.location_name,
           u.user_name as assigned_user_name,
           creator.user_name as created_by_name,
           s.supplier_name,
           DATEDIFF(CURDATE(), a.purchase_date) as age_days,
           DATEDIFF(a.next_maintenance_date, CURDATE()) as days_to_maintenance,
           COUNT(am.maintenance_id) as maintenance_count,
           COALESCE(MAX(am.maintenance_date), a.purchase_date) as last_maintenance
    FROM assets a
    LEFT JOIN asset_categories ac ON a.category_id = ac.category_id
    LEFT JOIN asset_locations al ON a.location_id = al.location_id
    LEFT JOIN users u ON a.assigned_to = u.user_id
    LEFT JOIN users creator ON a.created_by = creator.user_id
    LEFT JOIN suppliers s ON a.supplier_id = s.supplier_id
    LEFT JOIN asset_maintenance am ON a.asset_id = am.asset_id AND am.status = 'completed'
    WHERE 1=1
      $status_query
      $category_query
      $location_query
      $condition_query
      $assigned_query
      $supplier_query
      $critical_query
      $value_query
      $search_query
    GROUP BY a.asset_id
    ORDER BY $sort $order
    LIMIT $record_from, $record_to
");

if (!$sql) {
    die("Query failed: " . mysqli_error($mysqli));
}

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get categories for filter
$categories_sql = "SELECT * FROM asset_categories WHERE is_active = 1 ORDER BY category_name";
$categories_result = $mysqli->query($categories_sql);

// Get locations for filter
$locations_sql = "SELECT * FROM asset_locations WHERE is_active = 1 ORDER BY location_name";
$locations_result = $mysqli->query($locations_sql);

// Get users for filter
$users_sql = "SELECT user_id, user_name FROM users WHERE user_status = 1 ORDER BY user_name";
$users_result = $mysqli->query($users_sql);

// Get suppliers for filter
$suppliers_sql = "SELECT supplier_id, supplier_name FROM suppliers WHERE supplier_is_active = 1 ORDER BY supplier_name";
$suppliers_result = $mysqli->query($suppliers_sql);

// Get today's transactions summary
$today_transactions_sql = "
    SELECT 
        COUNT(*) as count,
        SUM(CASE WHEN transaction_type IN ('checkout', 'checkout_out') THEN 1 ELSE 0 END) as checkout_count,
        SUM(CASE WHEN transaction_type IN ('checkin', 'checkout_in') THEN 1 ELSE 0 END) as checkin_count,
        SUM(CASE WHEN transaction_type = 'maintenance' THEN 1 ELSE 0 END) as maintenance_count
    FROM asset_checkout_logs 
    WHERE DATE(created_at) = CURDATE()
";
$today_transactions_result = mysqli_query($mysqli, $today_transactions_sql);
$today_transactions = mysqli_fetch_assoc($today_transactions_result);

// Get pending maintenance count
$pending_maintenance_sql = "SELECT COUNT(*) as count FROM asset_maintenance WHERE status IN ('scheduled', 'in_progress')";
$pending_maintenance_result = mysqli_query($mysqli, $pending_maintenance_sql);
$pending_maintenance = mysqli_fetch_assoc($pending_maintenance_result)['count'];
?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2 mb-0 text-white"><i class="fas fa-fw fa-cubes mr-2"></i>Asset Management Dashboard</h3>
        <div class="card-tools">
            <div class="btn-group">
                <a href="asset_new.php" class="btn btn-success">
                    <i class="fas fa-plus mr-2"></i>New Asset
                </a>

                <a href="asset_maintenance.php" class="btn btn-primary ml-2">
                    <i class="fas fa-tools mr-2"></i>Maintenance
                    <?php if ($pending_maintenance > 0): ?>
                        <span class="badge badge-danger"><?php echo $pending_maintenance; ?></span>
                    <?php endif; ?>
                </a>

                <a href="asset_checkout.php" class="btn btn-warning ml-2">
                    <i class="fas fa-exchange-alt mr-2"></i>Checkout/Checkin
                </a>

                <a href="asset_reports.php" class="btn btn-info ml-2">
                    <i class="fas fa-chart-bar mr-2"></i>Reports
                </a>

                <div class="btn-group ml-2">
                    <button type="button" class="btn btn-secondary dropdown-toggle" data-toggle="dropdown">
                        <i class="fas fa-tasks mr-2"></i>Quick Actions
                    </button>
                    <div class="dropdown-menu">
                        <a class="dropdown-item" href="asset_categories.php">
                            <i class="fas fa-tags mr-2"></i>Manage Categories
                        </a>
                        <a href="asset_locations.php" class="dropdown-item">
                            <i class="fas fa-map-marker-alt mr-2"></i>Manage Locations
                            <span class="badge badge-light float-right"><?php echo $stats['used_locations_count']; ?>/<?php echo $stats['total_locations_count']; ?></span>
                        </a>
                        <a href="suppliers.php" class="dropdown-item">
                            <i class="fas fa-truck mr-2"></i>Manage Suppliers
                        </a>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item" href="asset_depreciation.php">
                            <i class="fas fa-chart-line mr-2"></i>Depreciation
                        </a>
                        <a class="dropdown-item" href="asset_disposals.php">
                            <i class="fas fa-trash mr-2"></i>Disposals
                        </a>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item" href="asset_export.php">
                            <i class="fas fa-file-export mr-2"></i>Export Data
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    

    <!-- Alert Row -->
    <?php if ($stats['critical_assets'] > 0 || $stats['overdue_maintenance'] > 0 || $stats['expired_warranties'] > 0 || $stats['maintenance_assets'] > 0): ?>
    <div class="row mt-3">
        <div class="col-12">
            <div class="alert-container">
                <?php if ($stats['critical_assets'] > 0): ?>
                <div class="alert alert-danger alert-dismissible fade show mb-2" role="alert">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    <strong><?php echo $stats['critical_assets']; ?> asset(s)</strong> are marked as critical.
                    <a href="?critical=critical" class="alert-link ml-2">View Critical Assets</a>
                    <button type="button" class="close" data-dismiss="alert">
                        <span>&times;</span>
                    </button>
                </div>
                <?php endif; ?>
                
                <?php if ($stats['overdue_maintenance'] > 0): ?>
                <div class="alert alert-warning alert-dismissible fade show mb-2" role="alert">
                    <i class="fas fa-tools mr-2"></i>
                    <strong><?php echo $stats['overdue_maintenance']; ?> asset(s)</strong> have overdue maintenance.
                    <a href="?status=under_maintenance" class="alert-link ml-2">View Maintenance</a>
                    <button type="button" class="close" data-dismiss="alert">
                        <span>&times;</span>
                    </button>
                </div>
                <?php endif; ?>
                
                <?php if ($stats['expired_warranties'] > 0): ?>
                <div class="alert alert-dark alert-dismissible fade show mb-2" role="alert">
                    <i class="fas fa-calendar-times mr-2"></i>
                    <strong><?php echo $stats['expired_warranties']; ?> asset(s)</strong> have expired warranties.
                    <a href="asset_reports.php?report=warranty" class="alert-link ml-2">View Warranty Report</a>
                    <button type="button" class="close" data-dismiss="alert">
                        <span>&times;</span>
                    </button>
                </div>
                <?php endif; ?>

                <?php if ($stats['maintenance_assets'] > 0): ?>
                <div class="alert alert-info alert-dismissible fade show mb-2" role="alert">
                    <i class="fas fa-clock mr-2"></i>
                    <strong><?php echo $stats['maintenance_assets']; ?> asset(s)</strong> are under maintenance.
                    <a href="?status=under_maintenance" class="alert-link ml-2">View Under Maintenance</a>
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
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search assets, tags, serial numbers, models..." autofocus>
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
                            <span class="btn btn-light border" data-toggle="tooltip" title="Total Assets">
                                <i class="fas fa-cubes text-dark mr-1"></i>
                                <strong><?php echo $stats['total_assets']; ?></strong>
                            </span>
                            <span class="btn btn-light border" data-toggle="tooltip" title="Active Assets">
                                <i class="fas fa-check-circle text-success mr-1"></i>
                                <strong><?php echo $stats['active_assets']; ?></strong>
                            </span>
                            <span class="btn btn-light border" data-toggle="tooltip" title="Critical Assets">
                                <i class="fas fa-exclamation-triangle text-danger mr-1"></i>
                                <strong><?php echo $stats['critical_assets']; ?></strong>
                            </span>
                            <span class="btn btn-light border" data-toggle="tooltip" title="Under Maintenance">
                                <i class="fas fa-tools text-warning mr-1"></i>
                                <strong><?php echo $stats['maintenance_assets']; ?></strong>
                            </span>
                            <a href="asset_maintenance.php" class="btn btn-info ml-2">
                                <i class="fas fa-fw fa-tools mr-2"></i>Maintenance
                                <?php if ($pending_maintenance > 0): ?>
                                    <span class="badge badge-danger"><?php echo $pending_maintenance; ?></span>
                                <?php endif; ?>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php if ($category_filter || $location_filter || $status_filter || $condition_filter || $assigned_filter || $supplier_filter || $critical_filter || $value_filter) { echo "show"; } ?>" id="advancedFilter">
                <div class="row">
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Category</label>
                            <select class="form-control select2" name="category" onchange="this.form.submit()">
                                <option value="">- All Categories -</option>
                                <?php while($category = $categories_result->fetch_assoc()): ?>
                                    <option value="<?php echo $category['category_id']; ?>" <?php if ($category_filter == $category['category_id']) { echo "selected"; } ?>>
                                        <?php echo htmlspecialchars($category['category_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Location</label>
                            <select class="form-control select2" name="location" onchange="this.form.submit()">
                                <option value="">- All Locations -</option>
                                <?php while($location = $locations_result->fetch_assoc()): ?>
                                    <option value="<?php echo $location['location_id']; ?>" <?php if ($location_filter == $location['location_id']) { echo "selected"; } ?>>
                                        <?php echo htmlspecialchars($location['location_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Status</label>
                            <select class="form-control select2" name="status" onchange="this.form.submit()">
                                <option value="">- All Status -</option>
                                <option value="active" <?php if ($status_filter == "active") { echo "selected"; } ?>>Active</option>
                                <option value="inactive" <?php if ($status_filter == "inactive") { echo "selected"; } ?>>Inactive</option>
                                <option value="under_maintenance" <?php if ($status_filter == "under_maintenance") { echo "selected"; } ?>>Under Maintenance</option>
                                <option value="disposed" <?php if ($status_filter == "disposed") { echo "selected"; } ?>>Disposed</option>
                                <option value="lost" <?php if ($status_filter == "lost") { echo "selected"; } ?>>Lost</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Quick Actions</label>
                            <div class="btn-group btn-block">
                                <a href="asset_management.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times mr-2"></i>Clear Filters
                                </a>
                                <a href="asset_new.php" class="btn btn-success">
                                    <i class="fas fa-plus mr-2"></i>New Asset
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row mt-2">
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Condition</label>
                            <select class="form-control select2" name="condition" onchange="this.form.submit()">
                                <option value="">- All Conditions -</option>
                                <option value="excellent" <?php if ($condition_filter == "excellent") { echo "selected"; } ?>>Excellent</option>
                                <option value="good" <?php if ($condition_filter == "good") { echo "selected"; } ?>>Good</option>
                                <option value="fair" <?php if ($condition_filter == "fair") { echo "selected"; } ?>>Fair</option>
                                <option value="poor" <?php if ($condition_filter == "poor") { echo "selected"; } ?>>Poor</option>
                                <option value="critical" <?php if ($condition_filter == "critical") { echo "selected"; } ?>>Critical</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Assigned To</label>
                            <select class="form-control select2" name="assigned" onchange="this.form.submit()">
                                <option value="">- All Users -</option>
                                <?php while($user = $users_result->fetch_assoc()): ?>
                                    <option value="<?php echo $user['user_id']; ?>" <?php if ($assigned_filter == $user['user_id']) { echo "selected"; } ?>>
                                        <?php echo htmlspecialchars($user['user_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
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
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Quick Filters</label>
                            <div class="btn-group btn-group-toggle" data-toggle="buttons">
                                <a href="?critical=critical" class="btn btn-outline-danger btn-sm <?php echo $critical_filter == 'critical' ? 'active' : ''; ?>">
                                    <i class="fas fa-exclamation-triangle mr-1"></i> Critical Assets
                                </a>
                                <a href="?status=under_maintenance" class="btn btn-outline-warning btn-sm <?php echo $status_filter == 'under_maintenance' ? 'active' : ''; ?>">
                                    <i class="fas fa-tools mr-1"></i> Under Maintenance
                                </a>
                                <a href="?value=high_value" class="btn btn-outline-success btn-sm <?php echo $value_filter == 'high_value' ? 'active' : ''; ?>">
                                    <i class="fas fa-dollar-sign mr-1"></i> High Value
                                </a>
                                <a href="?status=disposed" class="btn btn-outline-dark btn-sm <?php echo $status_filter == 'disposed' ? 'active' : ''; ?>">
                                    <i class="fas fa-trash mr-1"></i> Disposed
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
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=a.asset_tag&order=<?php echo $disp; ?>">
                        Asset Tag <?php if ($sort == 'a.asset_tag') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=a.asset_name&order=<?php echo $disp; ?>">
                        Asset Name <?php if ($sort == 'a.asset_name') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=ac.category_name&order=<?php echo $disp; ?>">
                        Category <?php if ($sort == 'ac.category_name') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=al.location_name&order=<?php echo $disp; ?>">
                        Location <?php if ($sort == 'al.location_name') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=u.user_name&order=<?php echo $disp; ?>">
                        Assigned To <?php if ($sort == 'u.user_name') { echo $order_icon; } ?>
                    </a>
                </th>
                <th class="text-center">
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=a.status&order=<?php echo $disp; ?>">
                        Status <?php if ($sort == 'a.status') { echo $order_icon; } ?>
                    </a>
                </th>
                <th class="text-right">
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=a.current_value&order=<?php echo $disp; ?>">
                        Value <?php if ($sort == 'a.current_value') { echo $order_icon; } ?>
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
                        <i class="fas fa-cubes fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No assets found</h5>
                        <p class="text-muted">
                            <?php 
                            if ($q || $category_filter || $location_filter || $status_filter || $condition_filter || $assigned_filter || $supplier_filter || $critical_filter || $value_filter) {
                                echo "Try adjusting your search or filter criteria.";
                            } else {
                                echo "Get started by adding your first asset.";
                            }
                            ?>
                        </p>
                        <a href="asset_new.php" class="btn btn-primary">
                            <i class="fas fa-plus mr-2"></i>Add First Asset
                        </a>
                        <?php if ($q || $category_filter || $location_filter || $status_filter || $condition_filter || $assigned_filter || $supplier_filter || $critical_filter || $value_filter): ?>
                            <a href="asset_management.php" class="btn btn-secondary ml-2">
                                <i class="fas fa-times mr-2"></i>Clear Filters
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php
            } else {
                while ($row = mysqli_fetch_array($sql)) {
                    $asset_id = intval($row['asset_id']);
                    $asset_tag = nullable_htmlentities($row['asset_tag']);
                    $asset_name = nullable_htmlentities($row['asset_name']);
                    $asset_description = nullable_htmlentities($row['asset_description']);
                    $category_name = nullable_htmlentities($row['category_name']);
                    $location_name = nullable_htmlentities($row['location_name']);
                    $assigned_user_name = nullable_htmlentities($row['assigned_user_name']);
                    $supplier_name = nullable_htmlentities($row['supplier_name']);
                    $status = nullable_htmlentities($row['status']);
                    $condition = nullable_htmlentities($row['asset_condition']);
                    $purchase_price = floatval($row['purchase_price'] ?? 0);
                    $current_value = floatval($row['current_value'] ?? 0);
                    $is_critical = boolval($row['is_critical'] ?? 0);
                    $days_to_maintenance = intval($row['days_to_maintenance'] ?? 0);
                    $maintenance_count = intval($row['maintenance_count'] ?? 0);
                    $serial_number = nullable_htmlentities($row['serial_number']);
                    $model = nullable_htmlentities($row['model']);
                    $manufacturer = nullable_htmlentities($row['manufacturer']);
                    $purchase_date = nullable_htmlentities($row['purchase_date']);
                    $warranty_expiry = nullable_htmlentities($row['warranty_expiry']);
                    $age_days = intval($row['age_days'] ?? 0);

                    // Status badge styling
                    $status_badge = "";
                    $status_icon = "";
                    switch($status) {
                        case 'active':
                            $status_badge = "badge-success";
                            $status_icon = "fa-check-circle";
                            break;
                        case 'inactive':
                            $status_badge = "badge-secondary";
                            $status_icon = "fa-minus-circle";
                            break;
                        case 'under_maintenance':
                            $status_badge = "badge-warning";
                            $status_icon = "fa-tools";
                            break;
                        case 'disposed':
                            $status_badge = "badge-dark";
                            $status_icon = "fa-trash";
                            break;
                        case 'lost':
                            $status_badge = "badge-danger";
                            $status_icon = "fa-exclamation-triangle";
                            break;
                        default:
                            $status_badge = "badge-light";
                            $status_icon = "fa-question-circle";
                    }

                    // Condition badge styling
                    $condition_badge = "";
                    switch($condition) {
                        case 'excellent': $condition_badge = "badge-success"; break;
                        case 'good': $condition_badge = "badge-info"; break;
                        case 'fair': $condition_badge = "badge-warning"; break;
                        case 'poor': $condition_badge = "badge-warning"; break;
                        case 'critical': $condition_badge = "badge-danger"; break;
                        default: $condition_badge = "badge-secondary";
                    }

                    // Maintenance indicator
                    $maintenance_indicator = '';
                    $maintenance_class = '';
                    if ($days_to_maintenance !== null) {
                        if ($days_to_maintenance < 0) {
                            $maintenance_indicator = 'Overdue';
                            $maintenance_class = 'danger';
                        } elseif ($days_to_maintenance <= 7) {
                            $maintenance_indicator = 'Due Soon';
                            $maintenance_class = 'warning';
                        } elseif ($days_to_maintenance <= 30) {
                            $maintenance_indicator = 'Upcoming';
                            $maintenance_class = 'info';
                        } else {
                            $maintenance_indicator = 'On Schedule';
                            $maintenance_class = 'success';
                        }
                    }

                    // Check warranty expiry
                    $is_warranty_expired = false;
                    $is_warranty_expiring_soon = false;
                    if ($warranty_expiry) {
                        $expiry_date = strtotime($warranty_expiry);
                        $today = strtotime('today');
                        $thirty_days_later = strtotime('+30 days');
                        
                        if ($expiry_date < $today) {
                            $is_warranty_expired = true;
                        } elseif ($expiry_date <= $thirty_days_later) {
                            $is_warranty_expiring_soon = true;
                        }
                    }
                    ?>
                    <tr class="<?php echo $is_critical ? 'table-danger' : ($is_warranty_expired ? 'table-dark' : ($is_warranty_expiring_soon ? 'table-warning' : '')); ?>">
                        <td>
                            <div class="font-weight-bold text-primary"><?php echo $asset_tag; ?></div>
                            <?php if (!empty($serial_number)): ?>
                                <small class="text-muted">SN: <?php echo $serial_number; ?></small>
                            <?php endif; ?>
                            <?php if (!empty($model)): ?>
                                <small class="text-muted d-block"><?php echo $model; ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="font-weight-bold"><?php echo $asset_name; ?></div>
                            <?php if (!empty($manufacturer)): ?>
                                <small class="text-muted"><?php echo $manufacturer; ?></small>
                            <?php endif; ?>
                            <?php if (!empty($asset_description)): ?>
                                <small class="text-muted d-block"><?php echo strlen($asset_description) > 50 ? substr($asset_description, 0, 50) . '...' : $asset_description; ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($category_name): ?>
                                <span class="badge badge-info">
                                    <?php echo $category_name; ?>
                                </span>
                            <?php else: ?>
                                <span class="badge badge-light">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="font-weight-bold"><?php echo $location_name ?: '—'; ?></div>
                        </td>
                        <td>
                            <?php if ($assigned_user_name): ?>
                                <div class="font-weight-bold"><?php echo $assigned_user_name; ?></div>
                            <?php else: ?>
                                <span class="badge badge-light">Unassigned</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <span class="badge <?php echo $status_badge; ?> badge-pill">
                                <i class="fas <?php echo $status_icon; ?> mr-1"></i>
                                <?php echo ucfirst(str_replace('_', ' ', $status)); ?>
                            </span>
                            <?php if ($condition): ?>
                                <small class="d-block">
                                    <span class="badge <?php echo $condition_badge; ?>"><?php echo ucfirst($condition); ?></span>
                                </small>
                            <?php endif; ?>
                            <?php if ($maintenance_indicator): ?>
                                <small class="d-block text-<?php echo $maintenance_class; ?>">
                                    <i class="fas fa-tools mr-1"></i><?php echo $maintenance_indicator; ?>
                                    <?php if ($maintenance_count > 0): ?>
                                        <span class="text-muted">(<?php echo $maintenance_count; ?>)</span>
                                    <?php endif; ?>
                                </small>
                            <?php endif; ?>
                        </td>
                        <td class="text-right">
                            <?php if ($current_value > 0): ?>
                                <div class="font-weight-bold text-success">$<?php echo number_format($current_value, 2); ?></div>
                                <small class="text-muted">Cost: $<?php echo number_format($purchase_price, 2); ?></small>
                                <?php if ($purchase_date): ?>
                                    <small class="d-block text-muted">
                                        <i class="fas fa-calendar mr-1"></i><?php echo number_format($age_days); ?> days
                                    </small>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">N/A</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="dropdown dropleft text-center">
                                <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                    <i class="fas fa-ellipsis-h"></i>
                                </button>
                                <div class="dropdown-menu">
                                    <a class="dropdown-item" href="asset_view.php?id=<?php echo $asset_id; ?>">
                                        <i class="fas fa-fw fa-eye mr-2"></i>View Details
                                    </a>
                                    <a class="dropdown-item" href="asset_edit.php?id=<?php echo $asset_id; ?>">
                                        <i class="fas fa-fw fa-edit mr-2"></i>Edit Asset
                                    </a>
                                    <a class="dropdown-item text-info" href="asset_maintenance_new.php?asset_id=<?php echo $asset_id; ?>">
                                        <i class="fas fa-fw fa-tools mr-2"></i>Schedule Maintenance
                                    </a>
                                    <a class="dropdown-item text-warning" href="asset_checkout_new.php?asset_id=<?php echo $asset_id; ?>">
                                        <i class="fas fa-fw fa-exchange-alt mr-2"></i>Checkout/Checkin
                                    </a>
                                    <div class="dropdown-divider"></div>
                                    <?php if ($status != 'disposed'): ?>
                                        <a class="dropdown-item text-danger" href="#" onclick="confirmDisposal(<?php echo $asset_id; ?>, '<?php echo addslashes($asset_name); ?>')">
                                            <i class="fas fa-fw fa-trash mr-2"></i>Mark as Disposed
                                        </a>
                                    <?php else: ?>
                                        <a class="dropdown-item text-success" href="#" onclick="confirmRestore(<?php echo $asset_id; ?>, '<?php echo addslashes($asset_name); ?>')">
                                            <i class="fas fa-fw fa-redo mr-2"></i>Restore Asset
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
    $('select[name="category"], select[name="location"], select[name="status"], select[name="condition"], select[name="assigned"], select[name="supplier"]').change(function() {
        $(this).closest('form').submit();
    });

    // Quick filter buttons
    $('.btn-group-toggle .btn').click(function(e) {
        e.preventDefault();
        window.location.href = $(this).attr('href');
    });
});

function confirmDisposal(assetId, assetName) {
    if (confirm('Are you sure you want to mark "' + assetName + '" as disposed? This action cannot be undone.')) {
        window.location.href = 'asset_dispose.php?id=' + assetId;
    }
}

function confirmRestore(assetId, assetName) {
    if (confirm('Are you sure you want to restore "' + assetName + '"?')) {
        window.location.href = 'asset_restore.php?id=' + assetId;
    }
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + N for new asset
    if (e.ctrlKey && e.keyCode === 78) {
        e.preventDefault();
        window.location.href = 'asset_new.php';
    }
    // Ctrl + M for maintenance
    if (e.ctrlKey && e.keyCode === 77) {
        e.preventDefault();
        window.location.href = 'asset_maintenance.php';
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
        window.location.href = 'asset_reports.php';
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

.table-dark {
    background-color: #d6d8d9 !important;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
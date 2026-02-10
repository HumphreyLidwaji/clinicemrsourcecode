<?php
// laundry_management.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Default Column Sortby/Order Filter
$sort = "li.created_at";
$order = "DESC";

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Filter parameters
$status_filter = $_GET['status'] ?? '';
$location_filter = $_GET['location'] ?? '';
$condition_filter = $_GET['condition'] ?? '';
$category_filter = $_GET['category'] ?? '';
$critical_filter = $_GET['critical'] ?? '';

// Search Query
$q = sanitizeInput($_GET['q'] ?? '');
if (!empty($q)) {
    $search_query = "AND (
        a.asset_tag LIKE '%$q%' 
        OR a.asset_name LIKE '%$q%'
        OR li.notes LIKE '%$q%'
        OR lc.category_name LIKE '%$q%'
    )";
} else {
    $search_query = '';
}

// Status Filter
if ($status_filter) {
    $status_query = "AND li.status = '" . sanitizeInput($status_filter) . "'";
} else {
    $status_query = '';
}

// Location Filter
if ($location_filter) {
    $location_query = "AND li.current_location = '" . sanitizeInput($location_filter) . "'";
} else {
    $location_query = '';
}

// Condition Filter
if ($condition_filter) {
    $condition_query = "AND li.item_condition = '" . sanitizeInput($condition_filter) . "'";
} else {
    $condition_query = '';
}

// Category Filter
if ($category_filter) {
    $category_query = "AND li.category_id = " . intval($category_filter);
} else {
    $category_query = '';
}

// Critical Filter
if ($critical_filter == 'critical') {
    $critical_query = "AND li.is_critical = 1";
} else {
    $critical_query = '';
}

// Get statistics
$stats_sql = "SELECT 
    COUNT(*) as total_items,
    SUM(CASE WHEN li.status = 'clean' THEN 1 ELSE 0 END) as clean_items,
    SUM(CASE WHEN li.status = 'dirty' THEN 1 ELSE 0 END) as dirty_items,
    SUM(CASE WHEN li.status = 'in_wash' THEN 1 ELSE 0 END) as in_wash_items,
    SUM(CASE WHEN li.status = 'damaged' THEN 1 ELSE 0 END) as damaged_items,
    SUM(CASE WHEN li.status = 'lost' THEN 1 ELSE 0 END) as lost_items,
    SUM(CASE WHEN li.is_critical = 1 THEN 1 ELSE 0 END) as critical_items,
    SUM(CASE WHEN li.current_location = 'clinic' THEN 1 ELSE 0 END) as in_clinic,
    SUM(CASE WHEN li.current_location = 'laundry' THEN 1 ELSE 0 END) as in_laundry,
    SUM(CASE WHEN li.current_location = 'storage' THEN 1 ELSE 0 END) as in_storage,
    SUM(CASE WHEN li.current_location = 'in_transit' THEN 1 ELSE 0 END) as in_transit,
    SUM(CASE WHEN li.item_condition = 'critical' THEN 1 ELSE 0 END) as critical_condition,
    SUM(CASE WHEN li.item_condition = 'poor' THEN 1 ELSE 0 END) as poor_condition,
    SUM(CASE WHEN li.wash_count > 100 THEN 1 ELSE 0 END) as high_wash_count,
    (SELECT COUNT(*) FROM laundry_wash_cycles WHERE DATE(wash_date) = CURDATE()) as today_washes,
    (SELECT COUNT(*) FROM laundry_transactions WHERE DATE(transaction_date) = CURDATE()) as today_transactions
    FROM laundry_items li
    WHERE 1=1
    $status_query
    $location_query
    $condition_query
    $category_query
    $critical_query
    $search_query";

$stats_result = $mysqli->query($stats_sql);
$stats = $stats_result->fetch_assoc();

// Get wash cycle statistics
$wash_stats_sql = "SELECT 
    COUNT(*) as total_cycles,
    SUM(items_washed) as total_items_washed,
    AVG(items_washed) as avg_items_per_wash,
    MAX(wash_date) as last_wash_date,
    MIN(wash_date) as first_wash_date,
    SUM(CASE WHEN temperature = 'hot' THEN 1 ELSE 0 END) as hot_washes,
    SUM(CASE WHEN temperature = 'warm' THEN 1 ELSE 0 END) as warm_washes,
    SUM(CASE WHEN temperature = 'cold' THEN 1 ELSE 0 END) as cold_washes
    FROM laundry_wash_cycles";

$wash_stats_result = $mysqli->query($wash_stats_sql);
$wash_stats = $wash_stats_result->fetch_assoc();

// Main query for laundry items
$sql = mysqli_query(
    $mysqli,
    "
    SELECT SQL_CALC_FOUND_ROWS li.*, 
           a.asset_tag,
           a.asset_name,
           a.asset_description,
           lc.category_name,
           lc.min_quantity,
           lc.reorder_point,
           creator.user_name as created_by_name,
           DATEDIFF(CURDATE(), li.last_washed_date) as days_since_last_wash,
           DATEDIFF(li.next_wash_date, CURDATE()) as days_to_next_wash,
           COUNT(lt.transaction_id) as transaction_count,
           COALESCE(MAX(lt.transaction_date), li.created_at) as last_transaction
    FROM laundry_items li
    LEFT JOIN assets a ON li.asset_id = a.asset_id
    LEFT JOIN laundry_categories lc ON li.category_id = lc.category_id
    LEFT JOIN users creator ON li.created_by = creator.user_id
    LEFT JOIN laundry_transactions lt ON li.laundry_id = lt.laundry_id
    WHERE 1=1
      $status_query
      $location_query
      $condition_query
      $category_query
      $critical_query
      $search_query
    GROUP BY li.laundry_id
    ORDER BY $sort $order
    LIMIT $record_from, $record_to
");

if (!$sql) {
    die("Query failed: " . mysqli_error($mysqli));
}

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get categories for filter
$categories_sql = "SELECT * FROM laundry_categories ";
$categories_result = $mysqli->query($categories_sql);

// Get recent transactions
$recent_transactions_sql = "
    SELECT lt.*, a.asset_name, u.user_name as performed_by_name, c.client_name
    FROM laundry_transactions lt
    LEFT JOIN laundry_items li ON lt.laundry_id = li.laundry_id
    LEFT JOIN assets a ON li.asset_id = a.asset_id
    LEFT JOIN users u ON lt.performed_by = u.user_id
    LEFT JOIN clients c ON lt.performed_for = c.client_id
    ORDER BY lt.transaction_date DESC
    LIMIT 10
";
$recent_transactions_result = $mysqli->query($recent_transactions_sql);

// Get today's wash cycles
$today_washes_sql = "
    SELECT wc.*, u.user_name as completed_by_name
    FROM laundry_wash_cycles wc
    LEFT JOIN users u ON wc.completed_by = u.user_id
    WHERE DATE(wc.wash_date) = CURDATE()
    ORDER BY wc.wash_time DESC
";
$today_washes_result = $mysqli->query($today_washes_sql);

// Get low stock categories
$low_stock_sql = "
    SELECT lc.category_id, lc.category_name, lc.min_quantity, lc.reorder_point,
           COUNT(li.laundry_id) as total_items,
           SUM(CASE WHEN li.status = 'clean' AND li.current_location = 'storage' THEN 1 ELSE 0 END) as available_clean,
           SUM(CASE WHEN li.status = 'clean' THEN 1 ELSE 0 END) as total_clean
    FROM laundry_categories lc
    LEFT JOIN laundry_items li ON lc.category_id = li.category_id
    
    GROUP BY lc.category_id, lc.category_name, lc.min_quantity, lc.reorder_point
    HAVING total_clean < lc.reorder_point
    ORDER BY available_clean ASC
";
$low_stock_result = $mysqli->query($low_stock_sql);
?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2 mb-0 text-white"><i class="fas fa-fw fa-tshirt mr-2"></i>Laundry Management</h3>
        <div class="card-tools">
            <div class="btn-group">
                <a href="laundry_new.php" class="btn btn-success">
                    <i class="fas fa-plus mr-2"></i>Add Laundry Item
                </a>

                <a href="laundry_wash_new.php" class="btn btn-primary ml-2">
                    <i class="fas fa-tint mr-2"></i>Start Wash Cycle
                </a>

                <a href="laundry_checkout.php" class="btn btn-warning ml-2">
                    <i class="fas fa-exchange-alt mr-2"></i>Checkout/Checkin
                </a>

                <a href="laundry_reports.php" class="btn btn-info ml-2">
                    <i class="fas fa-chart-bar mr-2"></i>Reports
                </a>

                <div class="btn-group ml-2">
                    <button type="button" class="btn btn-secondary dropdown-toggle" data-toggle="dropdown">
                        <i class="fas fa-tasks mr-2"></i>Quick Actions
                    </button>
                    <div class="dropdown-menu">
                        <a class="dropdown-item" href="laundry_categories.php">
                            <i class="fas fa-tags mr-2"></i>Manage Categories
                        </a>
                        <a class="dropdown-item" href="laundry_wash_cycles.php">
                            <i class="fas fa-history mr-2"></i>Wash History
                        </a>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item" href="laundry_transactions.php">
                            <i class="fas fa-list-alt mr-2"></i>All Transactions
                        </a>
                        <a class="dropdown-item" href="laundry_audit.php">
                            <i class="fas fa-clipboard-check mr-2"></i>Audit Trail
                        </a>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item" href="laundry_export.php">
                            <i class="fas fa-file-export mr-2"></i>Export Data
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
  

    <!-- Alert Row -->
    <?php if ($stats['critical_items'] > 0 || $stats['critical_condition'] > 0 || $low_stock_result->num_rows > 0): ?>
    <div class="row mt-3">
        <div class="col-12">
            <div class="alert-container">
                <?php if ($stats['critical_items'] > 0): ?>
                <div class="alert alert-danger alert-dismissible fade show mb-2" role="alert">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    <strong><?php echo $stats['critical_items']; ?> item(s)</strong> are marked as critical.
                    <a href="?critical=critical" class="alert-link ml-2">View Critical Items</a>
                    <button type="button" class="close" data-dismiss="alert">
                        <span>&times;</span>
                    </button>
                </div>
                <?php endif; ?>
                
                <?php if ($stats['critical_condition'] > 0): ?>
                <div class="alert alert-warning alert-dismissible fade show mb-2" role="alert">
                    <i class="fas fa-thermometer-full mr-2"></i>
                    <strong><?php echo $stats['critical_condition']; ?> item(s)</strong> are in critical condition.
                    <a href="?condition=critical" class="alert-link ml-2">View Critical Condition Items</a>
                    <button type="button" class="close" data-dismiss="alert">
                        <span>&times;</span>
                    </button>
                </div>
                <?php endif; ?>
                
             
                
                <?php if ($stats['dirty_items'] > 0): ?>
                <div class="alert alert-dark alert-dismissible fade show mb-2" role="alert">
                    <i class="fas fa-tint mr-2"></i>
                    <strong><?php echo $stats['dirty_items']; ?> item(s)</strong> need washing.
                    <a href="laundry_wash_new.php" class="alert-link ml-2">Start Wash Cycle</a>
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
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search items, asset tags, categories..." autofocus>
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
                            <span class="btn btn-light border" data-toggle="tooltip" title="Total Items">
                                <i class="fas fa-tshirt text-dark mr-1"></i>
                                <strong><?php echo $stats['total_items'] ?? 0; ?></strong>
                            </span>
                            <span class="btn btn-light border" data-toggle="tooltip" title="Clean Items">
                                <i class="fas fa-check-circle text-success mr-1"></i>
                                <strong><?php echo $stats['clean_items'] ?? 0; ?></strong>
                            </span>
                            <span class="btn btn-light border" data-toggle="tooltip" title="Dirty Items">
                                <i class="fas fa-exclamation-triangle text-warning mr-1"></i>
                                <strong><?php echo $stats['dirty_items'] ?? 0; ?></strong>
                            </span>
                            <span class="btn btn-light border" data-toggle="tooltip" title="Critical Items">
                                <i class="fas fa-exclamation-circle text-danger mr-1"></i>
                                <strong><?php echo $stats['critical_items'] ?? 0; ?></strong>
                            </span>
                            <a href="laundry_wash_new.php" class="btn btn-info ml-2">
                                <i class="fas fa-fw fa-tint mr-2"></i>Start Wash
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php if ($category_filter || $location_filter || $status_filter || $condition_filter || $critical_filter) { echo "show"; } ?>" id="advancedFilter">
                <div class="row">
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Status</label>
                            <select class="form-control select2" name="status" onchange="this.form.submit()">
                                <option value="">- All Status -</option>
                                <option value="clean" <?php if ($status_filter == "clean") { echo "selected"; } ?>>Clean</option>
                                <option value="dirty" <?php if ($status_filter == "dirty") { echo "selected"; } ?>>Dirty</option>
                                <option value="in_wash" <?php if ($status_filter == "in_wash") { echo "selected"; } ?>>In Wash</option>
                                <option value="damaged" <?php if ($status_filter == "damaged") { echo "selected"; } ?>>Damaged</option>
                                <option value="lost" <?php if ($status_filter == "lost") { echo "selected"; } ?>>Lost</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Location</label>
                            <select class="form-control select2" name="location" onchange="this.form.submit()">
                                <option value="">- All Locations -</option>
                                <option value="clinic" <?php if ($location_filter == "clinic") { echo "selected"; } ?>>Clinic</option>
                                <option value="laundry" <?php if ($location_filter == "laundry") { echo "selected"; } ?>>Laundry</option>
                                <option value="storage" <?php if ($location_filter == "storage") { echo "selected"; } ?>>Storage</option>
                                <option value="in_transit" <?php if ($location_filter == "in_transit") { echo "selected"; } ?>>In Transit</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
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
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Quick Actions</label>
                            <div class="btn-group btn-block">
                                <a href="laundry_management.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times mr-2"></i>Clear Filters
                                </a>
                                <a href="laundry_new.php" class="btn btn-success">
                                    <i class="fas fa-plus mr-2"></i>Add Item
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row mt-2">
                    <div class="col-md-3">
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
                    <div class="col-md-9">
                        <div class="form-group">
                            <label>Quick Filters</label>
                            <div class="btn-group btn-group-toggle" data-toggle="buttons">
                                <a href="?critical=critical" class="btn btn-outline-danger btn-sm <?php echo $critical_filter == 'critical' ? 'active' : ''; ?>">
                                    <i class="fas fa-exclamation-triangle mr-1"></i> Critical Items
                                </a>
                                <a href="?status=dirty" class="btn btn-outline-warning btn-sm <?php echo $status_filter == 'dirty' ? 'active' : ''; ?>">
                                    <i class="fas fa-tint mr-1"></i> Needs Washing
                                </a>
                                <a href="?status=clean&location=storage" class="btn btn-outline-success btn-sm <?php echo ($status_filter == 'clean' && $location_filter == 'storage') ? 'active' : ''; ?>">
                                    <i class="fas fa-boxes mr-1"></i> Available in Storage
                                </a>
                                <a href="?condition=critical" class="btn btn-outline-dark btn-sm <?php echo $condition_filter == 'critical' ? 'active' : ''; ?>">
                                    <i class="fas fa-thermometer-full mr-1"></i> Critical Condition
                                </a>
                                <a href="?status=damaged" class="btn btn-outline-secondary btn-sm <?php echo $status_filter == 'damaged' ? 'active' : ''; ?>">
                                    <i class="fas fa-times-circle mr-1"></i> Damaged
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
                        Item Name <?php if ($sort == 'a.asset_name') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=lc.category_name&order=<?php echo $disp; ?>">
                        Category <?php if ($sort == 'lc.category_name') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=li.current_location&order=<?php echo $disp; ?>">
                        Location <?php if ($sort == 'li.current_location') { echo $order_icon; } ?>
                    </a>
                </th>
                <th class="text-center">
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=li.status&order=<?php echo $disp; ?>">
                        Status <?php if ($sort == 'li.status') { echo $order_icon; } ?>
                    </a>
                </th>
                <th class="text-center">
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=li.condition&order=<?php echo $disp; ?>">
                        Condition <?php if ($sort == 'li.condition') { echo $order_icon; } ?>
                    </a>
                </th>
                <th class="text-center">
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=li.wash_count&order=<?php echo $disp; ?>">
                        Wash Count <?php if ($sort == 'li.wash_count') { echo $order_icon; } ?>
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
                        <i class="fas fa-tshirt fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No laundry items found</h5>
                        <p class="text-muted">
                            <?php 
                            if ($q || $category_filter || $location_filter || $status_filter || $condition_filter || $critical_filter) {
                                echo "Try adjusting your search or filter criteria.";
                            } else {
                                echo "Get started by adding your first laundry item.";
                            }
                            ?>
                        </p>
                        <a href="laundry_new.php" class="btn btn-primary">
                            <i class="fas fa-plus mr-2"></i>Add First Item
                        </a>
                        <?php if ($q || $category_filter || $location_filter || $status_filter || $condition_filter || $critical_filter): ?>
                            <a href="laundry_management.php" class="btn btn-secondary ml-2">
                                <i class="fas fa-times mr-2"></i>Clear Filters
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php
            } else {
                while ($row = mysqli_fetch_array($sql)) {
                    $laundry_id = intval($row['laundry_id']);
                    $asset_tag = nullable_htmlentities($row['asset_tag']);
                    $asset_name = nullable_htmlentities($row['asset_name']);
                    $asset_description = nullable_htmlentities($row['asset_description']);
                    $category_name = nullable_htmlentities($row['category_name']);
                    $current_location = nullable_htmlentities($row['current_location']);
                    $status = nullable_htmlentities($row['status']);
                    $condition = nullable_htmlentities($row['item_condition']);
                    $wash_count = intval($row['wash_count'] ?? 0);
                    $is_critical = boolval($row['is_critical'] ?? 0);
                    $last_washed_date = nullable_htmlentities($row['last_washed_date']);
                    $next_wash_date = nullable_htmlentities($row['next_wash_date']);
                    $days_since_last_wash = intval($row['days_since_last_wash'] ?? 0);
                    $days_to_next_wash = intval($row['days_to_next_wash'] ?? 0);
                    $min_quantity = intval($row['min_quantity'] ?? 0);
                    $reorder_point = intval($row['reorder_point'] ?? 5);
                    $transaction_count = intval($row['transaction_count'] ?? 0);
                    $notes = nullable_htmlentities($row['notes']);

                    // Status badge styling
                    $status_badge = "";
                    $status_icon = "";
                    switch($status) {
                        case 'clean':
                            $status_badge = "badge-success";
                            $status_icon = "fa-check-circle";
                            break;
                        case 'dirty':
                            $status_badge = "badge-warning";
                            $status_icon = "fa-exclamation-triangle";
                            break;
                        case 'in_wash':
                            $status_badge = "badge-info";
                            $status_icon = "fa-tint";
                            break;
                        case 'damaged':
                            $status_badge = "badge-danger";
                            $status_icon = "fa-times-circle";
                            break;
                        case 'lost':
                            $status_badge = "badge-dark";
                            $status_icon = "fa-question-circle";
                            break;
                        default:
                            $status_badge = "badge-light";
                            $status_icon = "fa-question-circle";
                    }

                    // Location badge styling
                    $location_badge = "";
                    $location_icon = "";
                    switch($current_location) {
                        case 'clinic':
                            $location_badge = "badge-primary";
                            $location_icon = "fa-hospital";
                            break;
                        case 'laundry':
                            $location_badge = "badge-info";
                            $location_icon = "fa-tint";
                            break;
                        case 'storage':
                            $location_badge = "badge-success";
                            $location_icon = "fa-box";
                            break;
                        case 'in_transit':
                            $location_badge = "badge-warning";
                            $location_icon = "fa-truck";
                            break;
                        default:
                            $location_badge = "badge-light";
                            $location_icon = "fa-map-marker";
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

                    // Wash count indicator
                    $wash_indicator = '';
                    if ($wash_count > 0) {
                        if ($wash_count > 200) {
                            $wash_indicator = '<span class="text-danger"><i class="fas fa-exclamation-triangle"></i> High wear</span>';
                        } elseif ($wash_count > 100) {
                            $wash_indicator = '<span class="text-warning"><i class="fas fa-info-circle"></i> Moderate wear</span>';
                        }
                    }

                    // Last washed indicator
                    $last_wash_indicator = '';
                    if ($last_washed_date) {
                        if ($days_since_last_wash > 30) {
                            $last_wash_indicator = '<small class="text-danger d-block"><i class="fas fa-clock"></i> Washed ' . $days_since_last_wash . ' days ago</small>';
                        } elseif ($days_since_last_wash > 14) {
                            $last_wash_indicator = '<small class="text-warning d-block"><i class="fas fa-clock"></i> Washed ' . $days_since_last_wash . ' days ago</small>';
                        }
                    }
                    ?>
                    <tr class="<?php echo $is_critical ? 'table-danger' : ''; ?>">
                        <td>
                            <div class="font-weight-bold text-primary"><?php echo $asset_tag; ?></div>
                            <?php if (!empty($asset_description)): ?>
                                <small class="text-muted"><?php echo strlen($asset_description) > 30 ? substr($asset_description, 0, 30) . '...' : $asset_description; ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="font-weight-bold"><?php echo $asset_name; ?></div>
                            <?php if ($category_name): ?>
                                <small class="badge badge-light"><?php echo $category_name; ?></small>
                            <?php endif; ?>
                            <?php if ($transaction_count > 0): ?>
                                <small class="text-muted d-block">
                                    <i class="fas fa-history"></i> <?php echo $transaction_count; ?> transactions
                                </small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="font-weight-bold"><?php echo $category_name ?: '—'; ?></div>
                            <?php if ($min_quantity > 0): ?>
                                <small class="text-muted d-block">
                                    Min: <?php echo $min_quantity; ?>, Reorder: <?php echo $reorder_point; ?>
                                </small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?php echo $location_badge; ?> badge-pill">
                                <i class="fas <?php echo $location_icon; ?> mr-1"></i>
                                <?php echo ucfirst(str_replace('_', ' ', $current_location)); ?>
                            </span>
                            <?php if ($next_wash_date && $days_to_next_wash < 3): ?>
                                <small class="d-block text-warning">
                                    <i class="fas fa-clock"></i> Wash due in <?php echo $days_to_next_wash; ?> days
                                </small>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <span class="badge <?php echo $status_badge; ?> badge-pill">
                                <i class="fas <?php echo $status_icon; ?> mr-1"></i>
                                <?php echo ucfirst(str_replace('_', ' ', $status)); ?>
                            </span>
                            <?php echo $last_wash_indicator; ?>
                        </td>
                        <td class="text-center">
                            <span class="badge <?php echo $condition_badge; ?>">
                                <?php echo ucfirst($condition); ?>
                            </span>
                            <?php if ($wash_count > 0): ?>
                                <div class="small text-muted mt-1">
                                    <i class="fas fa-sync"></i> <?php echo $wash_count; ?> washes
                                </div>
                            <?php endif; ?>
                            <?php echo $wash_indicator; ?>
                        </td>
                        <td class="text-center">
                            <div class="font-weight-bold"><?php echo $wash_count; ?></div>
                            <?php if ($last_washed_date): ?>
                                <small class="text-muted d-block">
                                    Last: <?php echo date('M j', strtotime($last_washed_date)); ?>
                                </small>
                            <?php endif; ?>
                            <?php if ($next_wash_date): ?>
                                <small class="text-info d-block">
                                    Next: <?php echo date('M j', strtotime($next_wash_date)); ?>
                                </small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="dropdown dropleft text-center">
                                <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                    <i class="fas fa-ellipsis-h"></i>
                                </button>
                                <div class="dropdown-menu">
                                    <a class="dropdown-item" href="laundry_view.php?id=<?php echo $laundry_id; ?>">
                                        <i class="fas fa-fw fa-eye mr-2"></i>View Details
                                    </a>
                                    <a class="dropdown-item" href="laundry_edit.php?id=<?php echo $laundry_id; ?>">
                                        <i class="fas fa-fw fa-edit mr-2"></i>Edit Item
                                    </a>
                                    <?php if ($status == 'dirty'): ?>
                                        <a class="dropdown-item text-info" href="laundry_wash_new.php?laundry_id=<?php echo $laundry_id; ?>">
                                            <i class="fas fa-fw fa-tint mr-2"></i>Mark for Washing
                                        </a>
                                    <?php elseif ($status == 'clean' && $current_location == 'storage'): ?>
                                        <a class="dropdown-item text-warning" href="laundry_checkout_new.php?laundry_id=<?php echo $laundry_id; ?>">
                                            <i class="fas fa-fw fa-sign-out-alt mr-2"></i>Checkout to Clinic
                                        </a>
                                    <?php elseif ($status == 'clean' && $current_location == 'clinic'): ?>
                                        <a class="dropdown-item text-warning" href="laundry_checkout_new.php?laundry_id=<?php echo $laundry_id; ?>">
                                            <i class="fas fa-fw fa-sign-in-alt mr-2"></i>Checkin to Storage
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($status == 'damaged'): ?>
                                        <a class="dropdown-item text-success" href="#" onclick="markAsRepaired(<?php echo $laundry_id; ?>, '<?php echo addslashes($asset_name); ?>')">
                                            <i class="fas fa-fw fa-check mr-2"></i>Mark as Repaired
                                        </a>
                                    <?php else: ?>
                                        <a class="dropdown-item text-danger" href="#" onclick="markAsDamaged(<?php echo $laundry_id; ?>, '<?php echo addslashes($asset_name); ?>')">
                                            <i class="fas fa-fw fa-times mr-2"></i>Mark as Damaged
                                        </a>
                                    <?php endif; ?>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item text-dark" href="laundry_transaction_new.php?laundry_id=<?php echo $laundry_id; ?>">
                                        <i class="fas fa-fw fa-exchange-alt mr-2"></i>Add Transaction
                                    </a>
                                    <?php if ($status != 'lost'): ?>
                                        <a class="dropdown-item text-danger" href="#" onclick="markAsLost(<?php echo $laundry_id; ?>, '<?php echo addslashes($asset_name); ?>')">
                                            <i class="fas fa-fw fa-question-circle mr-2"></i>Mark as Lost
                                        </a>
                                    <?php else: ?>
                                        <a class="dropdown-item text-success" href="#" onclick="markAsFound(<?php echo $laundry_id; ?>, '<?php echo addslashes($asset_name); ?>')">
                                            <i class="fas fa-fw fa-redo mr-2"></i>Mark as Found
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
    $('select[name="category"], select[name="location"], select[name="status"], select[name="condition"]').change(function() {
        $(this).closest('form').submit();
    });

    // Quick filter buttons
    $('.btn-group-toggle .btn').click(function(e) {
        e.preventDefault();
        window.location.href = $(this).attr('href');
    });
});

function markAsDamaged(laundryId, itemName) {
    if (confirm('Are you sure you want to mark "' + itemName + '" as damaged?')) {
        $.ajax({
            url: 'ajax/laundry_update_status.php',
            method: 'POST',
            data: {
                laundry_id: laundryId,
                status: 'damaged',
                action: 'update_status'
            },
            success: function(response) {
                location.reload();
            }
        });
    }
}

function markAsRepaired(laundryId, itemName) {
    if (confirm('Are you sure you want to mark "' + itemName + '" as repaired?')) {
        $.ajax({
            url: 'ajax/laundry_update_status.php',
            method: 'POST',
            data: {
                laundry_id: laundryId,
                status: 'clean',
                action: 'update_status'
            },
            success: function(response) {
                location.reload();
            }
        });
    }
}

function markAsLost(laundryId, itemName) {
    if (confirm('Are you sure you want to mark "' + itemName + '" as lost?')) {
        $.ajax({
            url: 'ajax/laundry_update_status.php',
            method: 'POST',
            data: {
                laundry_id: laundryId,
                status: 'lost',
                action: 'update_status'
            },
            success: function(response) {
                location.reload();
            }
        });
    }
}

function markAsFound(laundryId, itemName) {
    if (confirm('Are you sure you want to mark "' + itemName + '" as found?')) {
        $.ajax({
            url: 'ajax/laundry_update_status.php',
            method: 'POST',
            data: {
                laundry_id: laundryId,
                status: 'clean',
                action: 'update_status'
            },
            success: function(response) {
                location.reload();
            }
        });
    }
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + N for new item
    if (e.ctrlKey && e.keyCode === 78) {
        e.preventDefault();
        window.location.href = 'laundry_new.php';
    }
    // Ctrl + W for wash cycle
    if (e.ctrlKey && e.keyCode === 87) {
        e.preventDefault();
        window.location.href = 'laundry_wash_new.php';
    }
    // Ctrl + C for checkout
    if (e.ctrlKey && e.keyCode === 67) {
        e.preventDefault();
        window.location.href = 'laundry_checkout.php';
    }
    // Ctrl + F for focus search
    if (e.ctrlKey && e.keyCode === 70) {
        e.preventDefault();
        $('input[name="q"]').focus();
    }
    // Ctrl + R for reports
    if (e.ctrlKey && e.keyCode === 82) {
        e.preventDefault();
        window.location.href = 'laundry_reports.php';
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

.card-footer {
    background-color: #f8f9fa;
    border-top: 1px solid #e9ecef;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
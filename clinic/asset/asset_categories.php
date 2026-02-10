<?php
// asset_categories.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Default Column Sortby/Order Filter
$sort = "category_name";
$order = "ASC";

// Search Query
$q = sanitizeInput($_GET['q'] ?? '');
if (!empty($q)) {
    $search_query = "AND (
        category_name LIKE '%$q%' 
        OR category_description LIKE '%$q%'
    )";
} else {
    $search_query = '';
}

// Status Filter
$status_filter = $_GET['status'] ?? '';
if ($status_filter == 'active') {
    $status_query = "AND is_active = 1";
} elseif ($status_filter == 'inactive') {
    $status_query = "AND is_active = 0";
} else {
    $status_query = '';
}

// Date Range Filter
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$date_query = '';
if ($date_from) {
    $date_query .= "AND ac.created_at >= '" . sanitizeInput($date_from) . "' ";
}
if ($date_to) {
    $date_query .= "AND ac.created_at <= '" . sanitizeInput($date_to) . "' ";
}

// Get statistics
$stats_sql = "SELECT 
    COUNT(*) as total_categories,
    SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_categories,
    SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_categories,
    COUNT(DISTINCT depreciation_rate) as unique_depreciation_rates,
    SUM(CASE WHEN depreciation_rate > 0 THEN 1 ELSE 0 END) as depreciating_categories,
    (SELECT COUNT(*) FROM assets WHERE status != 'disposed') as total_assets,
    COALESCE(SUM(CASE WHEN a.status != 'disposed' THEN a.purchase_price ELSE 0 END), 0) as total_asset_value,
    COALESCE(SUM(CASE WHEN a.status != 'disposed' THEN a.current_value ELSE 0 END), 0) as total_current_value
    FROM asset_categories ac
    LEFT JOIN assets a ON ac.category_id = a.category_id
    WHERE 1=1
    $search_query
    $status_query
    $date_query";

$stats_result = $mysqli->query($stats_sql);
$stats = $stats_result->fetch_assoc();

// Get depreciation statistics
$depreciation_stats_sql = "SELECT 
    CASE 
        WHEN depreciation_rate = 0 THEN 'No Depreciation'
        WHEN depreciation_rate <= 10 THEN '0-10%'
        WHEN depreciation_rate <= 20 THEN '11-20%'
        WHEN depreciation_rate <= 30 THEN '21-30%'
        ELSE '30%+'
    END as rate_range,
    COUNT(*) as count,
    SUM(CASE WHEN a.status != 'disposed' THEN a.purchase_price ELSE 0 END) as total_value
    FROM asset_categories ac
    LEFT JOIN assets a ON ac.category_id = a.category_id
    WHERE 1=1
    $status_query
    $date_query
    GROUP BY rate_range
    ORDER BY rate_range";

$depreciation_stats_result = $mysqli->query($depreciation_stats_sql);
$depreciation_stats = [];
while ($row = $depreciation_stats_result->fetch_assoc()) {
    $depreciation_stats[$row['rate_range']] = $row;
}

// Get categories
$sql = mysqli_query(
    $mysqli,
    "
    SELECT SQL_CALC_FOUND_ROWS ac.*,
           COUNT(a.asset_id) as asset_count,
           SUM(a.purchase_price) as total_value,
           SUM(a.current_value) as current_total_value,
           creator.user_name as created_by_name,
           updater.user_name as updated_by_name,
           DATEDIFF(CURDATE(), ac.created_at) as days_ago
    FROM asset_categories ac
    LEFT JOIN assets a ON ac.category_id = a.category_id AND a.status != 'disposed'
    LEFT JOIN users creator ON ac.created_by = creator.user_id
    LEFT JOIN users updater ON ac.updated_by = updater.user_id
    WHERE 1=1
      $search_query
      $status_query
      $date_query
    GROUP BY ac.category_id
    ORDER BY $sort $order
    LIMIT $record_from, $record_to
");

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Handle category actions
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = sanitizeInput($_GET['action']);
    $category_id = intval($_GET['id']);
    
   
        $toggle_sql = "UPDATE asset_categories SET is_active = NOT is_active, updated_by = ?, updated_at = NOW() WHERE category_id = ?";
        $toggle_stmt = $mysqli->prepare($toggle_sql);
        $toggle_stmt->bind_param("ii", $session_user_id, $category_id);
        
        if ($toggle_stmt->execute()) {
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Category status updated successfully!";
        } else {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error updating category: " . $mysqli->error;
        }
        header("Location: asset_categories.php");
        exit;
    }

?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2 mb-0 text-white"><i class="fas fa-fw fa-tags mr-2"></i>Asset Categories Dashboard</h3>
        <div class="card-tools">
            <div class="btn-group">
                <a href="#" class="btn btn-success" data-toggle="modal" data-target="#addCategoryModal">
                    <i class="fas fa-plus mr-2"></i>New Category
                </a>

                <a href="asset_management.php" class="btn btn-primary ml-2">
                    <i class="fas fa-cubes mr-2"></i>View Assets
                </a>

                <a href="asset_locations.php" class="btn btn-info ml-2">
                    <i class="fas fa-map-marker-alt mr-2"></i>Locations
                </a>

                <div class="btn-group ml-2">
                    <button type="button" class="btn btn-secondary dropdown-toggle" data-toggle="dropdown">
                        <i class="fas fa-tasks mr-2"></i>Quick Actions
                    </button>
                    <div class="dropdown-menu">
                        <a class="dropdown-item" href="asset_reports.php?report=categories">
                            <i class="fas fa-chart-bar mr-2"></i>Category Reports
                        </a>
                        <a href="asset_management.php?view=by_category" class="dropdown-item">
                            <i class="fas fa-filter mr-2"></i>View by Category
                        </a>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item" href="asset_categories_export.php">
                            <i class="fas fa-file-export mr-2"></i>Export Categories
                        </a>
                        <a class="dropdown-item" href="asset_categories_print.php">
                            <i class="fas fa-print mr-2"></i>Print Category Report
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Enhanced Stats Dashboard -->
    <div class="card-body bg-light border-bottom">
        <div class="row text-center">
            <!-- Total Categories -->
            <div class="col-md-3 mb-3">
                <div class="card bg-primary text-white">
                    <div class="card-body py-3">
                        <i class="fas fa-tags fa-2x mb-2"></i>
                        <h5 class="card-title">Total Categories</h5>
                        <h3 class="font-weight-bold"><?php echo $stats['total_categories']; ?></h3>
                        <small class="opacity-8"><?php echo $stats['unique_depreciation_rates']; ?> depreciation rates</small>
                    </div>
                </div>
            </div>
            
            <!-- Active Categories -->
            <div class="col-md-3 mb-3">
                <div class="card bg-success text-white">
                    <div class="card-body py-3">
                        <i class="fas fa-check-circle fa-2x mb-2"></i>
                        <h5 class="card-title">Active</h5>
                        <h3 class="font-weight-bold"><?php echo $stats['active_categories']; ?></h3>
                        <small class="opacity-8">Available for asset assignment</small>
                    </div>
                </div>
            </div>
            
            <!-- Total Asset Value -->
            <div class="col-md-3 mb-3">
                <div class="card bg-info text-white">
                    <div class="card-body py-3">
                        <i class="fas fa-dollar-sign fa-2x mb-2"></i>
                        <h5 class="card-title">Total Asset Value</h5>
                        <h3 class="font-weight-bold">$<?php echo number_format($stats['total_current_value'], 0); ?></h3>
                        <small class="opacity-8">Original: $<?php echo number_format($stats['total_asset_value'], 0); ?></small>
                    </div>
                </div>
            </div>
            
            <!-- Depreciating Categories -->
            <div class="col-md-3 mb-3">
                <div class="card bg-warning text-white">
                    <div class="card-body py-3">
                        <i class="fas fa-chart-line fa-2x mb-2"></i>
                        <h5 class="card-title">Depreciating</h5>
                        <h3 class="font-weight-bold"><?php echo $stats['depreciating_categories']; ?></h3>
                        <small class="opacity-8">With depreciation applied</small>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Depreciation Distribution -->
        <div class="row mt-4">
            <div class="col-12">
                <h5><i class="fas fa-chart-pie mr-2"></i>Depreciation Rate Distribution</h5>
                <div class="progress mb-2" style="height: 25px;">
                    <?php 
                    $total = $stats['total_categories'];
                    $colors = ['success', 'info', 'warning', 'danger', 'secondary'];
                    $i = 0;
                    foreach ($depreciation_stats as $range => $data):
                        $percentage = $total > 0 ? ($data['count'] / $total) * 100 : 0;
                        $color = $range == 'No Depreciation' ? 'secondary' : ($range == '0-10%' ? 'success' : ($range == '11-20%' ? 'info' : ($range == '21-30%' ? 'warning' : 'danger')));
                    ?>
                    <div class="progress-bar bg-<?php echo $color; ?>" 
                         style="width: <?php echo $percentage; ?>%" 
                         data-toggle="tooltip" 
                         title="<?php echo $range . ': ' . $data['count'] . ' categories (' . number_format($percentage, 1) . '%)' . ' - $' . number_format($data['total_value'], 0) . ' value'; ?>">
                        <small><?php echo $range; ?> (<?php echo $data['count']; ?>)</small>
                    </div>
                    <?php $i++; endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Alert Row -->
    <?php if ($stats['inactive_categories'] > 0): ?>
    <div class="row mt-3">
        <div class="col-12">
            <div class="alert-container">
                <?php if ($stats['inactive_categories'] > 0): ?>
                <div class="alert alert-warning alert-dismissible fade show mb-2" role="alert">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    <strong><?php echo $stats['inactive_categories']; ?> category(ies)</strong> are currently inactive and not available for new assets.
                    <a href="?status=inactive" class="alert-link ml-2">View Inactive Categories</a>
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
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search categories, descriptions..." autofocus>
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
                            <span class="btn btn-light border" data-toggle="tooltip" title="Total Categories">
                                <i class="fas fa-tags text-dark mr-1"></i>
                                <strong><?php echo $stats['total_categories']; ?></strong>
                            </span>
                            <span class="btn btn-light border" data-toggle="tooltip" title="Active Categories">
                                <i class="fas fa-check-circle text-success mr-1"></i>
                                <strong><?php echo $stats['active_categories']; ?></strong>
                            </span>
                            <span class="btn btn-light border" data-toggle="tooltip" title="Total Asset Value">
                                <i class="fas fa-dollar-sign text-info mr-1"></i>
                                <strong>$<?php echo number_format($stats['total_current_value']/1000, 0); ?>K</strong>
                            </span>
                            <a href="#" class="btn btn-success ml-2" data-toggle="modal" data-target="#addCategoryModal">
                                <i class="fas fa-fw fa-plus mr-2"></i>New Category
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php if ($status_filter || $date_from || $date_to) { echo "show"; } ?>" id="advancedFilter">
                <div class="row">
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Status</label>
                            <select class="form-control select2" name="status" onchange="this.form.submit()">
                                <option value="">- All Status -</option>
                                <option value="active" <?php if ($status_filter == "active") { echo "selected"; } ?>>Active</option>
                                <option value="inactive" <?php if ($status_filter == "inactive") { echo "selected"; } ?>>Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Quick Actions</label>
                            <div class="btn-group btn-block">
                                <a href="asset_categories.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times mr-2"></i>Clear Filters
                                </a>
                                <a href="#" class="btn btn-success" data-toggle="modal" data-target="#addCategoryModal">
                                    <i class="fas fa-plus mr-2"></i>New Category
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row mt-2">
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Date From</label>
                            <input type="date" class="form-control" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" onchange="this.form.submit()">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Date To</label>
                            <input type="date" class="form-control" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" onchange="this.form.submit()">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Quick Filters</label>
                            <div class="btn-group btn-group-toggle" data-toggle="buttons">
                                <a href="?status=active" class="btn btn-outline-success btn-sm <?php echo $status_filter == 'active' ? 'active' : ''; ?>">
                                    <i class="fas fa-check mr-1"></i> Active
                                </a>
                                <a href="?status=inactive" class="btn btn-outline-secondary btn-sm <?php echo $status_filter == 'inactive' ? 'active' : ''; ?>">
                                    <i class="fas fa-ban mr-1"></i> Inactive
                                </a>
                                <a href="?depreciation=yes" class="btn btn-outline-info btn-sm">
                                    <i class="fas fa-chart-line mr-1"></i> With Depreciation
                                </a>
                                <a href="?depreciation=no" class="btn btn-outline-warning btn-sm">
                                    <i class="fas fa-times-circle mr-1"></i> No Depreciation
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
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=category_name&order=<?php echo $disp; ?>">
                            Category <?php if ($sort == 'category_name') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>Description</th>
                    <th>
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=depreciation_rate&order=<?php echo $disp; ?>">
                            Depreciation <?php if ($sort == 'depreciation_rate') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=asset_count&order=<?php echo $disp; ?>">
                            Assets <?php if ($sort == 'asset_count') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th class="text-right">
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=total_value&order=<?php echo $disp; ?>">
                            Asset Value <?php if ($sort == 'total_value') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th class="text-center">
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=is_active&order=<?php echo $disp; ?>">
                            Status <?php if ($sort == 'is_active') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th class="text-center">
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=ac.created_at&order=<?php echo $disp; ?>">
                            Created <?php if ($sort == 'ac.created_at') { echo $order_icon; } ?>
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
                            <i class="fas fa-tags fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">No categories found</h5>
                            <p class="text-muted">
                                <?php 
                                if ($q || $status_filter || $date_from || $date_to) {
                                    echo "Try adjusting your search or filter criteria.";
                                } else {
                                    echo "Get started by adding your first category.";
                                }
                                ?>
                            </p>
                            <a href="#" class="btn btn-primary" data-toggle="modal" data-target="#addCategoryModal">
                                <i class="fas fa-plus mr-2"></i>Add First Category
                            </a>
                            <?php if ($q || $status_filter || $date_from || $date_to): ?>
                                <a href="asset_categories.php" class="btn btn-secondary ml-2">
                                    <i class="fas fa-times mr-2"></i>Clear Filters
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php
                } else {
                    while ($row = mysqli_fetch_array($sql)) { 
                        $category_id = intval($row['category_id']);
                        $category_name = nullable_htmlentities($row['category_name']);
                        $category_description = nullable_htmlentities($row['category_description']);
                        $depreciation_rate = floatval($row['depreciation_rate']);
                        $useful_life_years = intval($row['useful_life_years']);
                        $asset_count = intval($row['asset_count']);
                        $total_value = floatval($row['total_value']);
                        $current_total_value = floatval($row['current_total_value']);
                        $is_active = boolval($row['is_active']);
                        $days_ago = intval($row['days_ago']);
                        
                        // Status badge styling
                        $status_badge = $is_active ? "badge-success" : "badge-secondary";
                        $status_icon = $is_active ? "fa-check" : "fa-ban";
                        
                        // Depreciation badge styling
                        $depreciation_badge = "";
                        $depreciation_color = "";
                        if ($depreciation_rate == 0) {
                            $depreciation_badge = "No Depreciation";
                            $depreciation_color = "secondary";
                        } elseif ($depreciation_rate <= 10) {
                            $depreciation_badge = "Slow";
                            $depreciation_color = "success";
                        } elseif ($depreciation_rate <= 20) {
                            $depreciation_badge = "Standard";
                            $depreciation_color = "info";
                        } elseif ($depreciation_rate <= 30) {
                            $depreciation_badge = "Fast";
                            $depreciation_color = "warning";
                        } else {
                            $depreciation_badge = "Rapid";
                            $depreciation_color = "danger";
                        }
                        
                        // Check if no assets
                        $has_no_assets = $asset_count == 0;
                        ?>
                        <tr class="<?php echo $has_no_assets ? 'table-light' : ''; ?>">
                            <td>
                                <div class="font-weight-bold text-primary"><?php echo $category_name; ?></div>
                                <?php if ($days_ago == 0): ?>
                                    <small class="d-block text-success"><i class="fas fa-star mr-1"></i>Added today</small>
                                <?php elseif ($days_ago <= 7): ?>
                                    <small class="d-block text-info"><i class="fas fa-clock mr-1"></i>Added <?php echo $days_ago; ?> days ago</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($category_description): ?>
                                    <small class="text-muted"><?php echo strlen($category_description) > 50 ? substr($category_description, 0, 50) . '...' : $category_description; ?></small>
                                <?php else: ?>
                                    <span class="text-muted">No description</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($depreciation_rate > 0): ?>
                                    <span class="badge badge-<?php echo $depreciation_color; ?> badge-pill">
                                        <?php echo $depreciation_rate; ?>%
                                    </span>
                                    <small class="d-block text-muted">
                                        <i class="fas fa-clock mr-1"></i><?php echo $useful_life_years; ?> years
                                    </small>
                                    <small class="d-block">
                                        <span class="badge badge-light"><?php echo $depreciation_badge; ?></span>
                                    </small>
                                <?php else: ?>
                                    <span class="badge badge-secondary badge-pill">
                                        <i class="fas fa-times mr-1"></i>No Depreciation
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($asset_count > 0): ?>
                                    <div class="font-weight-bold"><?php echo $asset_count; ?></div>
                                    <small class="text-muted">assets assigned</small>
                                <?php else: ?>
                                    <span class="badge badge-light">No assets</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right">
                                <?php if ($current_total_value > 0): ?>
                                    <div class="font-weight-bold text-success">$<?php echo number_format($current_total_value, 2); ?></div>
                                    <small class="text-muted">
                                        Original: $<?php echo number_format($total_value, 2); ?>
                                        <?php if ($total_value > $current_total_value): ?>
                                            <br><span class="text-danger">Depreciation: $<?php echo number_format($total_value - $current_total_value, 2); ?></span>
                                        <?php endif; ?>
                                    </small>
                                <?php else: ?>
                                    <span class="text-muted">N/A</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <span class="badge <?php echo $status_badge; ?> badge-pill">
                                    <i class="fas <?php echo $status_icon; ?> mr-1"></i>
                                    <?php echo $is_active ? 'Active' : 'Inactive'; ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <small class="text-muted">
                                    <?php echo date('M d, Y', strtotime($row['created_at'])); ?>
                                </small>
                            </td>
                            <td>
                                <div class="dropdown dropleft text-center">
                                    <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                        <i class="fas fa-ellipsis-h"></i>
                                    </button>
                                    <div class="dropdown-menu">
                                        <a class="dropdown-item" href="asset_management.php?category=<?php echo $category_id; ?>">
                                            <i class="fas fa-fw fa-eye mr-2"></i>View Assets
                                            <span class="badge badge-light float-right"><?php echo $asset_count; ?></span>
                                        </a>
                                        
                                            <a class="dropdown-item" href="#" onclick="editCategory(<?php echo $category_id; ?>, '<?php echo addslashes($category_name); ?>', '<?php echo addslashes($category_description); ?>', <?php echo $depreciation_rate; ?>, <?php echo $useful_life_years; ?>, <?php echo $is_active ? 'true' : 'false'; ?>)">
                                                <i class="fas fa-fw fa-edit mr-2"></i>Edit Category
                                            </a>
                                            <a class="dropdown-item text-warning" href="?action=toggle&id=<?php echo $category_id; ?>">
                                                <i class="fas fa-fw fa-power-off mr-2"></i>
                                                <?php echo $is_active ? 'Deactivate' : 'Activate'; ?>
                                            </a>
                                       
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item" href="asset_categories_analysis.php?id=<?php echo $category_id; ?>">
                                            <i class="fas fa-fw fa-chart-line mr-2"></i>Financial Analysis
                                        </a>
                                      
                                            <div class="dropdown-divider"></div>
                                            <a class="dropdown-item text-danger confirm-link" href="asset_category_delete.php?id=<?php echo $category_id; ?>">
                                                <i class="fas fa-fw fa-trash mr-2"></i>Delete Category
                                            </a>
                                        
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

<!-- Add Category Modal -->
<div class="modal fade" id="addCategoryModal" tabindex="-1" role="dialog" aria-labelledby="addCategoryModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title" id="addCategoryModalLabel">
                    <i class="fas fa-plus-circle mr-2"></i>Add New Category
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST" action="asset_category_process.php">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    
                    <div class="form-group">
                        <label for="category_name">Category Name *</label>
                        <input type="text" class="form-control" id="category_name" name="category_name" required>
                        <small class="form-text text-muted">e.g., "Computers", "Office Furniture", "Vehicles"</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="category_description">Description</label>
                        <textarea class="form-control" id="category_description" name="category_description" rows="3" placeholder="Describe this category of assets..."></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="depreciation_rate">Depreciation Rate (%)</label>
                                <div class="input-group">
                                    <input type="number" class="form-control" id="depreciation_rate" name="depreciation_rate" min="0" max="100" step="0.01" value="20.00">
                                    <div class="input-group-append">
                                        <span class="input-group-text">%</span>
                                    </div>
                                </div>
                                <small class="form-text text-muted">
                                    <span id="depreciation_hint"></span>
                                    <br>0% = No depreciation
                                </small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="useful_life_years">Useful Life (Years)</label>
                                <input type="number" class="form-control" id="useful_life_years" name="useful_life_years" min="1" max="100" value="5">
                                <small class="form-text text-muted">Expected lifespan of assets</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="is_active" name="is_active" checked>
                            <label class="form-check-label" for="is_active">Active Category (available for asset assignment)</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">
                        <i class="fas fa-times mr-2"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save mr-2"></i>Save Category
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Category Modal -->
<div class="modal fade" id="editCategoryModal" tabindex="-1" role="dialog" aria-labelledby="editCategoryModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-warning text-white">
                <h5 class="modal-title" id="editCategoryModalLabel">
                    <i class="fas fa-edit mr-2"></i>Edit Category
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST" action="asset_category_process.php">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="category_id" id="edit_category_id">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    
                    <div class="form-group">
                        <label for="edit_category_name">Category Name *</label>
                        <input type="text" class="form-control" id="edit_category_name" name="category_name" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_category_description">Description</label>
                        <textarea class="form-control" id="edit_category_description" name="category_description" rows="3"></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="edit_depreciation_rate">Depreciation Rate (%)</label>
                                <div class="input-group">
                                    <input type="number" class="form-control" id="edit_depreciation_rate" name="depreciation_rate" min="0" max="100" step="0.01">
                                    <div class="input-group-append">
                                        <span class="input-group-text">%</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="edit_useful_life_years">Useful Life (Years)</label>
                                <input type="number" class="form-control" id="edit_useful_life_years" name="useful_life_years" min="1" max="100">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="edit_is_active" name="is_active">
                            <label class="form-check-label" for="edit_is_active">Active Category</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">
                        <i class="fas fa-times mr-2"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-save mr-2"></i>Update Category
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.select2').select2();
    $('[data-toggle="tooltip"]').tooltip();

    // Auto-submit when filters change
    $('select[name="status"]').change(function() {
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

    // Update depreciation hint
    $('#depreciation_rate').on('input', function() {
        var rate = $(this).val();
        var hint = '';
        
        if (rate == 0) {
            hint = 'No depreciation will be applied to assets in this category.';
        } else if (rate <= 10) {
            hint = 'Slow depreciation rate - suitable for long-lasting assets.';
        } else if (rate <= 20) {
            hint = 'Standard depreciation rate - typical for most assets.';
        } else if (rate <= 30) {
            hint = 'Fast depreciation rate - for assets that lose value quickly.';
        } else {
            hint = 'Rapid depreciation rate - for technology or rapidly depreciating assets.';
        }
        
        $('#depreciation_hint').text(hint);
    });

    // Trigger on page load
    $('#depreciation_rate').trigger('input');
});

function editCategory(categoryId, categoryName, categoryDescription, depreciationRate, usefulLifeYears, isActive) {
    $('#edit_category_id').val(categoryId);
    $('#edit_category_name').val(categoryName);
    $('#edit_category_description').val(categoryDescription);
    $('#edit_depreciation_rate').val(depreciationRate);
    $('#edit_useful_life_years').val(usefulLifeYears);
    $('#edit_is_active').prop('checked', isActive);
    
    $('#editCategoryModal').modal('show');
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + N for new category
    if (e.ctrlKey && e.keyCode === 78) {
        e.preventDefault();
        $('#addCategoryModal').modal('show');
    }
    // Ctrl + A for assets
    if (e.ctrlKey && e.keyCode === 65) {
        e.preventDefault();
        window.location.href = 'asset_management.php';
    }
    // Ctrl + L for locations
    if (e.ctrlKey && e.keyCode === 76) {
        e.preventDefault();
        window.location.href = 'asset_locations.php';
    }
    // Ctrl + F for focus search
    if (e.ctrlKey && e.keyCode === 70) {
        e.preventDefault();
        $('input[name="q"]').focus();
    }
});

// Confirm action links
$(document).on('click', '.confirm-link', function(e) {
    if (!confirm('Are you sure you want to delete this category? This action cannot be undone.')) {
        e.preventDefault();
    }
});
</script>

<style>
.card .card-body {
    padding: 1rem;
}

.progress-bar {
    cursor: pointer;
}

.progress-bar:hover {
    opacity: 0.9;
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

.table-light {
    background-color: #f8f9fa;
}

.dropdown-menu {
    min-width: 200px;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
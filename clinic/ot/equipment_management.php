<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Default Column Sortby/Order Filter
$sort = "a.asset_name";
$order = "ASC";

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Filter parameters
$status_filter = $_GET['status'] ?? '';
$category_filter = $_GET['category'] ?? '';
$location_filter = $_GET['location'] ?? '';
$condition_filter = $_GET['condition'] ?? '';
$is_critical_filter = $_GET['is_critical'] ?? '';

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

// Critical Equipment Filter
if ($is_critical_filter !== '') {
    $critical_query = "AND a.is_critical = " . intval($is_critical_filter);
} else {
    $critical_query = '';
}

// Search Query
$q = sanitizeInput($_GET['q'] ?? '');
if (!empty($q)) {
    $search_query = "AND (
        a.asset_tag LIKE '%$q%' 
        OR a.asset_name LIKE '%$q%'
        OR a.asset_description LIKE '%$q%'
        OR a.manufacturer LIKE '%$q%'
        OR a.model LIKE '%$q%'
        OR a.serial_number LIKE '%$q%'
        OR c.category_name LIKE '%$q%'
        OR l.location_name LIKE '%$q%'
    )";
} else {
    $search_query = '';
}

// Main query for assets/equipment
$sql = mysqli_query(
    $mysqli,
    "
    SELECT SQL_CALC_FOUND_ROWS a.*,
         
           l.location_name,
           l.location_description,
           u.user_name as created_by_name,
           assg.user_name as assigned_to_name,
           DATEDIFF(CURDATE(), a.last_maintenance_date) as days_since_maintenance,
           DATEDIFF(a.next_maintenance_date, CURDATE()) as days_until_maintenance
    FROM assets a
  
    LEFT JOIN locations l ON a.location_id = l.location_id
    LEFT JOIN users u ON a.created_by = u.user_id
    LEFT JOIN users assg ON a.assigned_to = assg.user_id
    WHERE 1=1
      $status_query
      $category_query
      $location_query
      $condition_query
      $critical_query
      $search_query
    ORDER BY $sort $order
    LIMIT $record_from, $record_to
");

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get statistics
$total_assets = $num_rows[0];
$active_count = 0;
$maintenance_count = 0;
$inactive_count = 0;
$critical_count = 0;

// Reset pointer and calculate
mysqli_data_seek($sql, 0);
while ($asset = mysqli_fetch_assoc($sql)) {
    if ($asset['status'] == 'active') {
        $active_count++;
    } elseif ($asset['status'] == 'maintenance') {
        $maintenance_count++;
    } elseif ($asset['status'] == 'inactive') {
        $inactive_count++;
    }
    
    if ($asset['is_critical']) {
        $critical_count++;
    }
}
mysqli_data_seek($sql, $record_from);



// Get locations for filter
$locations_sql = "SELECT location_id, location_name FROM locations ORDER BY location_name";
$locations_result = $mysqli->query($locations_sql);

// Get conditions for filter
$conditions = ['excellent', 'good', 'fair', 'poor', 'critical'];
?>

<div class="card">
    <div class="card-header bg-warning py-2">
        <h3 class="card-title mt-2 mb-0"><i class="fas fa-fw fa-tools mr-2"></i>Equipment Management</h3>
        <div class="card-tools">
            <a href="asset_new.php" class="btn btn-success">
                <i class="fas fa-plus mr-2"></i>New Asset
            </a>
        </div>
    </div>
    
    <!-- Statistics Row -->
    <div class="card-body border-bottom">
        <div class="row text-center">
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-info"><i class="fas fa-tools"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Assets</span>
                        <span class="info-box-number"><?php echo $total_assets; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-success"><i class="fas fa-check-circle"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Active</span>
                        <span class="info-box-number"><?php echo $active_count; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-warning"><i class="fas fa-wrench"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Maintenance</span>
                        <span class="info-box-number"><?php echo $maintenance_count; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-danger"><i class="fas fa-times-circle"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Inactive</span>
                        <span class="info-box-number"><?php echo $inactive_count; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-danger"><i class="fas fa-exclamation-triangle"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Critical Assets</span>
                        <span class="info-box-number"><?php echo $critical_count; ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card-header pb-2 pt-3">
        <form autocomplete="off">
            <div class="row">
                <div class="col-md-4">
                    <div class="form-group">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search asset tag, name, model, serial..." autofocus>
                            <div class="input-group-append">
                                <button class="btn btn-secondary" type="button" data-toggle="collapse" data-target="#advancedFilter"><i class="fas fa-filter"></i></button>
                                <button class="btn btn-primary"><i class="fa fa-search"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-8">
                    <div class="btn-toolbar form-group float-right">
                        <div class="btn-group">
                            <a href="asset_new.php" class="btn btn-success">
                                <i class="fas fa-fw fa-plus mr-2"></i>New Asset
                            </a>
                            <a href="maintenance_schedule.php" class="btn btn-info">
                                <i class="fas fa-fw fa-calendar-alt mr-2"></i>Maintenance Schedule
                            </a>
                            <a href="reports_assets.php" class="btn btn-secondary">
                                <i class="fas fa-fw fa-chart-bar mr-2"></i>Asset Reports
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php if ($status_filter || $category_filter || $location_filter || $condition_filter || $is_critical_filter !== '') { echo "show"; } ?>" id="advancedFilter">
                <div class="row">
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Status</label>
                            <select class="form-control select2" name="status" onchange="this.form.submit()">
                                <option value="">- All Statuses -</option>
                                <option value="active" <?php if ($status_filter == "active") { echo "selected"; } ?>>Active</option>
                                <option value="maintenance" <?php if ($status_filter == "maintenance") { echo "selected"; } ?>>Maintenance</option>
                                <option value="inactive" <?php if ($status_filter == "inactive") { echo "selected"; } ?>>Inactive</option>
                                <option value="disposed" <?php if ($status_filter == "disposed") { echo "selected"; } ?>>Disposed</option>
                                <option value="lost" <?php if ($status_filter == "lost") { echo "selected"; } ?>>Lost</option>
                                <option value="sold" <?php if ($status_filter == "sold") { echo "selected"; } ?>>Sold</option>
                            </select>
                        </div>
                    </div>
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
                    <div class="col-md-3">
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
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Condition</label>
                            <select class="form-control select2" name="condition" onchange="this.form.submit()">
                                <option value="">- All Conditions -</option>
                                <?php foreach($conditions as $condition): ?>
                                    <option value="<?php echo $condition; ?>" <?php if ($condition_filter == $condition) { echo "selected"; } ?>>
                                        <?php echo ucfirst($condition); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Critical Assets</label>
                            <select class="form-control select2" name="is_critical" onchange="this.form.submit()">
                                <option value="">- All Assets -</option>
                                <option value="1" <?php if ($is_critical_filter === '1') { echo "selected"; } ?>>Critical Only</option>
                                <option value="0" <?php if ($is_critical_filter === '0') { echo "selected"; } ?>>Non-Critical Only</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-9">
                        <div class="form-group">
                            <label>Quick Actions</label>
                            <div class="btn-group-vertical btn-block">
                                <div class="btn-group">
                                    <a href="asset_new.php" class="btn btn-success btn-sm">
                                        <i class="fas fa-plus mr-1"></i> Add Asset
                                    </a>
                                    <a href="maintenance_schedule.php" class="btn btn-info btn-sm">
                                        <i class="fas fa-calendar mr-1"></i> Maintenance
                                    </a>
                                    <a href="asset_transfer.php" class="btn btn-warning btn-sm">
                                        <i class="fas fa-exchange-alt mr-1"></i> Transfer
                                    </a>
                                </div>
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
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=c.category_name&order=<?php echo $disp; ?>">
                        Category <?php if ($sort == 'c.category_name') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>Manufacturer & Model</th>
                <th>Location & Assignment</th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=a.status&order=<?php echo $disp; ?>">
                        Status <?php if ($sort == 'a.status') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>Maintenance</th>
                <th class="text-center">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php while ($row = mysqli_fetch_array($sql)) {
                $asset_id = intval($row['asset_id']);
                $asset_tag = nullable_htmlentities($row['asset_tag']);
                $asset_name = nullable_htmlentities($row['asset_name']);
                $asset_description = nullable_htmlentities($row['asset_description']);
                $category_name = nullable_htmlentities($row['category_name']);
                $location_name = nullable_htmlentities($row['location_name']);
                $manufacturer = nullable_htmlentities($row['manufacturer']);
                $model = nullable_htmlentities($row['model']);
                $serial_number = nullable_htmlentities($row['serial_number']);
                $purchase_date = nullable_htmlentities($row['purchase_date']);
                $purchase_price = nullable_htmlentities($row['purchase_price']);
                $current_value = nullable_htmlentities($row['current_value']);
                $warranty_expiry = nullable_htmlentities($row['warranty_expiry']);
                $status = nullable_htmlentities($row['status']);
                $asset_condition = nullable_htmlentities($row['asset_condition']);
                $assigned_to_name = nullable_htmlentities($row['assigned_to_name'] ?? '');
                $assigned_department = nullable_htmlentities($row['assigned_department'] ?? '');
                $is_critical = $row['is_critical'];
                $last_maintenance_date = nullable_htmlentities($row['last_maintenance_date']);
                $next_maintenance_date = nullable_htmlentities($row['next_maintenance_date']);
                $days_since_maintenance = intval($row['days_since_maintenance']);
                $days_until_maintenance = intval($row['days_until_maintenance']);
                $notes = nullable_htmlentities($row['notes']);

                // Status badge styling
                $status_badge = '';
                switch($status) {
                    case 'active':
                        $status_badge = 'badge-success';
                        break;
                    case 'maintenance':
                        $status_badge = 'badge-warning';
                        break;
                    case 'inactive':
                        $status_badge = 'badge-secondary';
                        break;
                    case 'disposed':
                        $status_badge = 'badge-danger';
                        break;
                    case 'lost':
                        $status_badge = 'badge-dark';
                        break;
                    case 'sold':
                        $status_badge = 'badge-info';
                        break;
                    default:
                        $status_badge = 'badge-light';
                }

                // Condition badge styling
                $condition_badge = '';
                switch($asset_condition) {
                    case 'excellent':
                        $condition_badge = 'badge-success';
                        break;
                    case 'good':
                        $condition_badge = 'badge-primary';
                        break;
                    case 'fair':
                        $condition_badge = 'badge-warning';
                        break;
                    case 'poor':
                        $condition_badge = 'badge-danger';
                        break;
                    case 'critical':
                        $condition_badge = 'badge-dark';
                        break;
                    default:
                        $condition_badge = 'badge-light';
                }

                // Maintenance status
                $maintenance_status = '';
                $maintenance_class = '';
                if ($next_maintenance_date) {
                    if ($days_until_maintenance < 0) {
                        $maintenance_status = 'Overdue';
                        $maintenance_class = 'text-danger font-weight-bold';
                    } elseif ($days_until_maintenance <= 30) {
                        $maintenance_status = 'Due Soon';
                        $maintenance_class = 'text-warning';
                    } else {
                        $maintenance_status = 'OK';
                        $maintenance_class = 'text-success';
                    }
                } else {
                    $maintenance_status = 'No Schedule';
                    $maintenance_class = 'text-muted';
                }

                // Row styling
                $row_class = '';
                if ($is_critical) {
                    $row_class = 'table-danger';
                } elseif ($days_until_maintenance < 0) {
                    $row_class = 'table-warning';
                } elseif ($asset_condition == 'critical') {
                    $row_class = 'table-dark text-white';
                } elseif ($asset_condition == 'poor') {
                    $row_class = 'table-danger';
                }
                ?>
                <tr class="<?php echo $row_class; ?>">
                    <td class="font-weight-bold"><?php echo $asset_tag; ?></td>
                    <td>
                        <div class="font-weight-bold"><?php echo $asset_name; ?></div>
                        <?php if($asset_description): ?>
                            <small class="text-muted"><?php echo substr($asset_description, 0, 50); ?><?php if(strlen($asset_description) > 50) echo '...'; ?></small>
                        <?php endif; ?>
                        <?php if($serial_number): ?>
                            <small class="text-muted d-block">S/N: <?php echo $serial_number; ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge badge-light"><?php echo $category_name; ?></span>
                    </td>
                    <td>
                        <?php if($manufacturer): ?>
                            <div class="font-weight-bold"><?php echo $manufacturer; ?></div>
                        <?php endif; ?>
                        <?php if($model): ?>
                            <small class="text-muted"><?php echo $model; ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div><?php echo $location_name ?: 'Not assigned'; ?></div>
                        <?php if($assigned_to_name): ?>
                            <small class="text-muted">Assigned to: <?php echo $assigned_to_name; ?></small>
                        <?php endif; ?>
                        <?php if($assigned_department): ?>
                            <small class="text-muted d-block">Dept: <?php echo $assigned_department; ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge <?php echo $status_badge; ?> mb-1"><?php echo ucfirst($status); ?></span>
                        <br>
                        <span class="badge <?php echo $condition_badge; ?>"><?php echo ucfirst($asset_condition); ?></span>
                        <?php if($is_critical): ?>
                            <br><span class="badge badge-danger mt-1"><i class="fas fa-exclamation-triangle mr-1"></i>Critical</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="<?php echo $maintenance_class; ?>">
                            <?php echo $maintenance_status; ?>
                        </div>
                        <?php if($next_maintenance_date): ?>
                            <small class="text-muted d-block">Next: <?php echo date('M j, Y', strtotime($next_maintenance_date)); ?></small>
                        <?php endif; ?>
                        <?php if($last_maintenance_date): ?>
                            <small class="text-info d-block">Last: <?php echo date('M j, Y', strtotime($last_maintenance_date)); ?></small>
                        <?php endif; ?>
                        <?php if($warranty_expiry): ?>
                            <?php 
                            $warranty_days = floor((strtotime($warranty_expiry) - time()) / (60 * 60 * 24));
                            $warranty_class = $warranty_days > 0 ? 'text-success' : 'text-danger';
                            ?>
                            <small class="<?php echo $warranty_class; ?> d-block">Warranty: <?php echo date('M j, Y', strtotime($warranty_expiry)); ?></small>
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
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item" href="maintenance_new.php?asset_id=<?php echo $asset_id; ?>">
                                    <i class="fas fa-fw fa-wrench mr-2"></i>Record Maintenance
                                </a>
                                <a class="dropdown-item" href="asset_transfer.php?asset_id=<?php echo $asset_id; ?>">
                                    <i class="fas fa-fw fa-exchange-alt mr-2"></i>Transfer Asset
                                </a>
                                <a class="dropdown-item" href="asset_assign.php?asset_id=<?php echo $asset_id; ?>">
                                    <i class="fas fa-fw fa-user-tag mr-2"></i>Assign to User
                                </a>
                                <div class="dropdown-divider"></div>
                                <?php if($status == 'active'): ?>
                                    <a class="dropdown-item text-warning" href="post.php?set_asset_maintenance=<?php echo $asset_id; ?>">
                                        <i class="fas fa-fw fa-tools mr-2"></i>Set Maintenance
                                    </a>
                                <?php elseif($status == 'maintenance'): ?>
                                    <a class="dropdown-item text-success" href="post.php?set_asset_active=<?php echo $asset_id; ?>">
                                        <i class="fas fa-fw fa-check mr-2"></i>Set Active
                                    </a>
                                <?php endif; ?>
                                <?php if($status == 'active'): ?>
                                    <a class="dropdown-item text-danger confirm-link" href="post.php?dispose_asset=<?php echo $asset_id; ?>">
                                        <i class="fas fa-fw fa-trash mr-2"></i>Dispose Asset
                                    </a>
                                <?php endif; ?>
                                <a class="dropdown-item text-danger confirm-link" href="post.php?archive_asset=<?php echo $asset_id; ?>">
                                    <i class="fas fa-fw fa-archive mr-2"></i>Archive Asset
                                </a>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php
            } ?>

            </tbody>
        </table>
    </div>
    
    <!-- Ends Card Body -->
    <?php 
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/filter_footer.php';
    ?>
    
</div> <!-- End Card -->

<script>
$(document).ready(function() {
    $('.select2').select2();

    // Confirm action links
    $('.confirm-link').click(function(e) {
        if (!confirm('Are you sure you want to perform this action? This cannot be undone.')) {
            e.preventDefault();
        }
    });
});

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + N for new asset
    if (e.ctrlKey && e.keyCode === 78) {
        e.preventDefault();
        window.location.href = 'asset_new.php';
    }
    // Ctrl + F for focus search
    if (e.ctrlKey && e.keyCode === 70) {
        e.preventDefault();
        $('input[name="q"]').focus();
    }
});
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
<?php
// asset_locations.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Default Column Sortby/Order Filter
$sort = "location_name";
$order = "ASC";

// Search Query
$q = sanitizeInput($_GET['q'] ?? '');
if (!empty($q)) {
    $search_query = "AND (
        location_name LIKE '%$q%' 
        OR building LIKE '%$q%'
        OR room_number LIKE '%$q%'
        OR description LIKE '%$q%'
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

// Type Filter
$type_filter = $_GET['type'] ?? '';
if ($type_filter) {
    $type_query = "AND location_type = '" . sanitizeInput($type_filter) . "'";
} else {
    $type_query = '';
}

// Date Range Filter
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$date_query = '';
if ($date_from) {
    $date_query .= "AND al.created_at >= '" . sanitizeInput($date_from) . "' ";
}
if ($date_to) {
    $date_query .= "AND al.created_at <= '" . sanitizeInput($date_to) . "' ";
}

// Get statistics
$stats_sql = "SELECT 
    COUNT(*) as total_locations,
    SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_locations,
    SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_locations,
    (SELECT COUNT(DISTINCT location_type) FROM asset_locations) as unique_types,
    (SELECT COUNT(*) FROM assets WHERE status != 'disposed') as total_assets
    FROM asset_locations al
    WHERE 1=1
    $search_query
    $status_query
    $type_query
    $date_query";

$stats_result = $mysqli->query($stats_sql);
$stats = $stats_result->fetch_assoc();

// Get locations by type statistics
$type_stats_sql = "SELECT 
    location_type,
    COUNT(*) as count,
    (SELECT COUNT(*) FROM assets a WHERE a.location_id = al.location_id AND a.status != 'disposed') as asset_count
    FROM asset_locations al
    WHERE 1=1
    $status_query
    $date_query
    GROUP BY location_type
    ORDER BY count DESC";

$type_stats_result = $mysqli->query($type_stats_sql);
$type_stats = [];
while ($row = $type_stats_result->fetch_assoc()) {
    $type_stats[$row['location_type']] = $row;
}

// Get locations
$sql = mysqli_query(
    $mysqli,
    "
    SELECT SQL_CALC_FOUND_ROWS al.*,
           COUNT(a.asset_id) as asset_count,
           SUM(a.purchase_price) as total_value,
           creator.user_name as created_by_name,
           updater.user_name as updated_by_name,
           DATEDIFF(CURDATE(), al.created_at) as days_ago
    FROM asset_locations al
    LEFT JOIN assets a ON al.location_id = a.location_id AND a.status != 'disposed'
    LEFT JOIN users creator ON al.created_by = creator.user_id
    LEFT JOIN users updater ON al.updated_by = updater.user_id
    WHERE 1=1
      $search_query
      $status_query
      $type_query
      $date_query
    GROUP BY al.location_id
    ORDER BY $sort $order
    LIMIT $record_from, $record_to
");

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Handle location actions
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = sanitizeInput($_GET['action']);
    $location_id = intval($_GET['id']);
    
    
        $toggle_sql = "UPDATE asset_locations SET is_active = NOT is_active, updated_by = ?, updated_at = NOW() WHERE location_id = ?";
        $toggle_stmt = $mysqli->prepare($toggle_sql);
        $toggle_stmt->bind_param("ii", $session_user_id, $location_id);
        
        if ($toggle_stmt->execute()) {
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Location status updated successfully!";
        } else {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error updating location: " . $mysqli->error;
        }
        header("Location: asset_locations.php");
        exit;
   
}
?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2 mb-0 text-white"><i class="fas fa-fw fa-map-marker-alt mr-2"></i>Asset Locations Dashboard</h3>
        <div class="card-tools">
            <div class="btn-group">
                <a href="#" class="btn btn-success" data-toggle="modal" data-target="#addLocationModal">
                    <i class="fas fa-plus mr-2"></i>New Location
                </a>

                <a href="asset_management.php" class="btn btn-primary ml-2">
                    <i class="fas fa-cubes mr-2"></i>View Assets
                </a>

                <a href="asset_categories.php" class="btn btn-info ml-2">
                    <i class="fas fa-tags mr-2"></i>Categories
                </a>

                <div class="btn-group ml-2">
                    <button type="button" class="btn btn-secondary dropdown-toggle" data-toggle="dropdown">
                        <i class="fas fa-tasks mr-2"></i>Quick Actions
                    </button>
                    <div class="dropdown-menu">
                        <a class="dropdown-item" href="asset_reports.php?report=locations">
                            <i class="fas fa-chart-bar mr-2"></i>Location Reports
                        </a>
                        <a href="asset_management.php?view=map" class="dropdown-item">
                            <i class="fas fa-map mr-2"></i>Location Map View
                        </a>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item" href="asset_locations_export.php">
                            <i class="fas fa-file-export mr-2"></i>Export Locations
                        </a>
                        <a class="dropdown-item" href="asset_locations_print.php">
                            <i class="fas fa-print mr-2"></i>Print Location Report
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Enhanced Stats Dashboard -->
    <div class="card-body bg-light border-bottom">
        <div class="row text-center">
            <!-- Total Locations -->
            <div class="col-md-3 mb-3">
                <div class="card bg-primary text-white">
                    <div class="card-body py-3">
                        <i class="fas fa-map-marker-alt fa-2x mb-2"></i>
                        <h5 class="card-title">Total Locations</h5>
                        <h3 class="font-weight-bold"><?php echo $stats['total_locations']; ?></h3>
                        <small class="opacity-8"><?php echo $stats['unique_types']; ?> unique types</small>
                    </div>
                </div>
            </div>
            
            <!-- Active Locations -->
            <div class="col-md-3 mb-3">
                <div class="card bg-success text-white">
                    <div class="card-body py-3">
                        <i class="fas fa-check-circle fa-2x mb-2"></i>
                        <h5 class="card-title">Active</h5>
                        <h3 class="font-weight-bold"><?php echo $stats['active_locations']; ?></h3>
                        <small class="opacity-8">Ready for asset placement</small>
                    </div>
                </div>
            </div>
            
            <!-- Inactive Locations -->
            <div class="col-md-3 mb-3">
                <div class="card bg-secondary text-white">
                    <div class="card-body py-3">
                        <i class="fas fa-ban fa-2x mb-2"></i>
                        <h5 class="card-title">Inactive</h5>
                        <h3 class="font-weight-bold"><?php echo $stats['inactive_locations']; ?></h3>
                        <small class="opacity-8">Not available for use</small>
                    </div>
                </div>
            </div>
            
            <!-- Total Assets -->
            <div class="col-md-3 mb-3">
                <div class="card bg-info text-white">
                    <div class="card-body py-3">
                        <i class="fas fa-cubes fa-2x mb-2"></i>
                        <h5 class="card-title">Assets in System</h5>
                        <h3 class="font-weight-bold"><?php echo $stats['total_assets']; ?></h3>
                        <small class="opacity-8">Across all locations</small>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Location Type Distribution -->
        <div class="row mt-4">
            <div class="col-12">
                <h5><i class="fas fa-chart-pie mr-2"></i>Location Type Distribution</h5>
                <div class="progress mb-2" style="height: 25px;">
                    <?php 
                    $total = $stats['total_locations'];
                    $colors = ['primary', 'success', 'info', 'warning', 'danger', 'secondary'];
                    $i = 0;
                    foreach ($type_stats as $type => $data):
                        $percentage = $total > 0 ? ($data['count'] / $total) * 100 : 0;
                    ?>
                    <div class="progress-bar bg-<?php echo $colors[$i % count($colors)]; ?>" 
                         style="width: <?php echo $percentage; ?>%" 
                         data-toggle="tooltip" 
                         title="<?php echo ucfirst($type) . ': ' . $data['count'] . ' locations (' . number_format($percentage, 1) . '%)' . ' - ' . $data['asset_count'] . ' assets'; ?>">
                        <small><?php echo ucfirst($type); ?> (<?php echo $data['count']; ?>)</small>
                    </div>
                    <?php $i++; endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Alert Row -->
    <?php if ($stats['inactive_locations'] > 0): ?>
    <div class="row mt-3">
        <div class="col-12">
            <div class="alert-container">
                <?php if ($stats['inactive_locations'] > 0): ?>
                <div class="alert alert-warning alert-dismissible fade show mb-2" role="alert">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    <strong><?php echo $stats['inactive_locations']; ?> location(s)</strong> are currently inactive.
                    <a href="?status=inactive" class="alert-link ml-2">View Inactive Locations</a>
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
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search locations, buildings, rooms, descriptions..." autofocus>
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
                            <span class="btn btn-light border" data-toggle="tooltip" title="Total Locations">
                                <i class="fas fa-map-marker-alt text-dark mr-1"></i>
                                <strong><?php echo $stats['total_locations']; ?></strong>
                            </span>
                            <span class="btn btn-light border" data-toggle="tooltip" title="Active Locations">
                                <i class="fas fa-check-circle text-success mr-1"></i>
                                <strong><?php echo $stats['active_locations']; ?></strong>
                            </span>
                            <span class="btn btn-light border" data-toggle="tooltip" title="Inactive Locations">
                                <i class="fas fa-ban text-secondary mr-1"></i>
                                <strong><?php echo $stats['inactive_locations']; ?></strong>
                            </span>
                            <a href="#" class="btn btn-success ml-2" data-toggle="modal" data-target="#addLocationModal">
                                <i class="fas fa-fw fa-plus mr-2"></i>New Location
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php if ($status_filter || $type_filter || $date_from || $date_to) { echo "show"; } ?>" id="advancedFilter">
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
                            <label>Type</label>
                            <select class="form-control select2" name="type" onchange="this.form.submit()">
                                <option value="">- All Types -</option>
                                <option value="department" <?php if ($type_filter == "department") { echo "selected"; } ?>>Department</option>
                                <option value="room" <?php if ($type_filter == "room") { echo "selected"; } ?>>Room</option>
                                <option value="building" <?php if ($type_filter == "building") { echo "selected"; } ?>>Building</option>
                                <option value="storage" <?php if ($type_filter == "storage") { echo "selected"; } ?>>Storage</option>
                                <option value="other" <?php if ($type_filter == "other") { echo "selected"; } ?>>Other</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Quick Actions</label>
                            <div class="btn-group btn-block">
                                <a href="asset_locations.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times mr-2"></i>Clear Filters
                                </a>
                                <a href="#" class="btn btn-success" data-toggle="modal" data-target="#addLocationModal">
                                    <i class="fas fa-plus mr-2"></i>New Location
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
                                <a href="?type=room" class="btn btn-outline-info btn-sm <?php echo $type_filter == 'room' ? 'active' : ''; ?>">
                                    <i class="fas fa-door-open mr-1"></i> Rooms
                                </a>
                                <a href="?type=storage" class="btn btn-outline-warning btn-sm <?php echo $type_filter == 'storage' ? 'active' : ''; ?>">
                                    <i class="fas fa-boxes mr-1"></i> Storage
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
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=location_name&order=<?php echo $disp; ?>">
                            Location <?php if ($sort == 'location_name') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=location_type&order=<?php echo $disp; ?>">
                            Type <?php if ($sort == 'location_type') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>Address Details</th>
                    <th>
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=asset_count&order=<?php echo $disp; ?>">
                            Assets <?php if ($sort == 'asset_count') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th class="text-right">
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=total_value&order=<?php echo $disp; ?>">
                            Total Value <?php if ($sort == 'total_value') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th class="text-center">
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=is_active&order=<?php echo $disp; ?>">
                            Status <?php if ($sort == 'is_active') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th class="text-center">
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=al.created_at&order=<?php echo $disp; ?>">
                            Created <?php if ($sort == 'al.created_at') { echo $order_icon; } ?>
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
                            <i class="fas fa-map-marker-alt fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">No locations found</h5>
                            <p class="text-muted">
                                <?php 
                                if ($q || $status_filter || $type_filter || $date_from || $date_to) {
                                    echo "Try adjusting your search or filter criteria.";
                                } else {
                                    echo "Get started by adding your first location.";
                                }
                                ?>
                            </p>
                            <a href="#" class="btn btn-primary" data-toggle="modal" data-target="#addLocationModal">
                                <i class="fas fa-plus mr-2"></i>Add First Location
                            </a>
                            <?php if ($q || $status_filter || $type_filter || $date_from || $date_to): ?>
                                <a href="asset_locations.php" class="btn btn-secondary ml-2">
                                    <i class="fas fa-times mr-2"></i>Clear Filters
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php
                } else {
                    while ($row = mysqli_fetch_array($sql)) { 
                        $location_id = intval($row['location_id']);
                        $location_name = nullable_htmlentities($row['location_name']);
                        $location_type = nullable_htmlentities($row['location_type']);
                        $building = nullable_htmlentities($row['building']);
                        $floor = nullable_htmlentities($row['floor']);
                        $room_number = nullable_htmlentities($row['room_number']);
                        $description = nullable_htmlentities($row['description']);
                        $asset_count = intval($row['asset_count']);
                        $total_value = floatval($row['total_value']);
                        $is_active = boolval($row['is_active']);
                        $days_ago = intval($row['days_ago']);
                        
                        // Status badge styling
                        $status_badge = $is_active ? "badge-success" : "badge-secondary";
                        $status_icon = $is_active ? "fa-check" : "fa-ban";
                        
                        // Type badge styling
                        $type_badge = "";
                        switch($location_type) {
                            case 'room': $type_badge = "badge-primary"; break;
                            case 'department': $type_badge = "badge-success"; break;
                            case 'building': $type_badge = "badge-info"; break;
                            case 'storage': $type_badge = "badge-warning"; break;
                            case 'other': $type_badge = "badge-secondary"; break;
                            default: $type_badge = "badge-light";
                        }
                        
                        // Check if no assets
                        $has_no_assets = $asset_count == 0;
                        ?>
                        <tr class="<?php echo $has_no_assets ? 'table-light' : ''; ?>">
                            <td>
                                <div class="font-weight-bold text-primary"><?php echo $location_name; ?></div>
                                <?php if ($description): ?>
                                    <small class="text-muted"><?php echo strlen($description) > 40 ? substr($description, 0, 40) . '...' : $description; ?></small>
                                <?php endif; ?>
                                <?php if ($days_ago == 0): ?>
                                    <small class="d-block text-success"><i class="fas fa-star mr-1"></i>Added today</small>
                                <?php elseif ($days_ago <= 7): ?>
                                    <small class="d-block text-info"><i class="fas fa-clock mr-1"></i>Added <?php echo $days_ago; ?> days ago</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?php echo $type_badge; ?> badge-pill">
                                    <?php echo ucfirst($location_type); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($building): ?>
                                    <div class="font-weight-bold"><?php echo $building; ?></div>
                                <?php endif; ?>
                                <?php if ($floor || $room_number): ?>
                                    <small class="text-muted">
                                        <?php if ($floor): ?>Floor: <?php echo $floor; ?><?php endif; ?>
                                        <?php if ($room_number): ?><?php if ($floor) echo ', '; ?>Room: <?php echo $room_number; ?><?php endif; ?>
                                    </small>
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
                                <?php if ($total_value > 0): ?>
                                    <div class="font-weight-bold text-success">$<?php echo number_format($total_value, 2); ?></div>
                                    <small class="text-muted">Total asset value</small>
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
                                        <a class="dropdown-item" href="asset_management.php?location=<?php echo $location_id; ?>">
                                            <i class="fas fa-fw fa-eye mr-2"></i>View Assets
                                            <span class="badge badge-light float-right"><?php echo $asset_count; ?></span>
                                        </a>
                                    
                                            <a class="dropdown-item" href="#" onclick="editLocation(<?php echo $location_id; ?>, '<?php echo addslashes($location_name); ?>', '<?php echo $location_type; ?>', '<?php echo addslashes($building); ?>', '<?php echo addslashes($floor); ?>', '<?php echo addslashes($room_number); ?>', '<?php echo addslashes($description); ?>', <?php echo $is_active ? 'true' : 'false'; ?>)">
                                                <i class="fas fa-fw fa-edit mr-2"></i>Edit Location
                                            </a>
                                            <a class="dropdown-item text-warning" href="?action=toggle&id=<?php echo $location_id; ?>">
                                                <i class="fas fa-fw fa-power-off mr-2"></i>
                                                <?php echo $is_active ? 'Deactivate' : 'Activate'; ?>
                                            </a>
                                       
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item" href="asset_locations_qr.php?id=<?php echo $location_id; ?>">
                                            <i class="fas fa-fw fa-qrcode mr-2"></i>Generate QR Code
                                        </a>
                                        
                                            <div class="dropdown-divider"></div>
                                            <a class="dropdown-item text-danger confirm-link" href="asset_location_delete.php?id=<?php echo $location_id; ?>">
                                                <i class="fas fa-fw fa-trash mr-2"></i>Delete Location
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

<!-- Add Location Modal -->
<div class="modal fade" id="addLocationModal" tabindex="-1" role="dialog" aria-labelledby="addLocationModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title" id="addLocationModalLabel">
                    <i class="fas fa-plus-circle mr-2"></i>Add New Location
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST" action="asset_location_process.php">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    
                    <div class="form-group">
                        <label for="location_name">Location Name *</label>
                        <input type="text" class="form-control" id="location_name" name="location_name" required>
                        <small class="form-text text-muted">e.g., "Server Room", "Accounting Department"</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="location_type">Location Type</label>
                        <select class="form-control" id="location_type" name="location_type">
                            <option value="room">Room</option>
                            <option value="department">Department</option>
                            <option value="building">Building</option>
                            <option value="storage">Storage</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="building">Building</label>
                                <input type="text" class="form-control" id="building" name="building" placeholder="e.g., Main Building">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="floor">Floor/Level</label>
                                <input type="text" class="form-control" id="floor" name="floor" placeholder="e.g., 3rd Floor">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="room_number">Room Number</label>
                        <input type="text" class="form-control" id="room_number" name="room_number" placeholder="e.g., 301">
                    </div>
                    
                    <div class="form-group">
                        <label for="description">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3" placeholder="Additional details about this location..."></textarea>
                    </div>
                    
                    <div class="form-group">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="is_active" name="is_active" checked>
                            <label class="form-check-label" for="is_active">Active Location (available for asset assignment)</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">
                        <i class="fas fa-times mr-2"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save mr-2"></i>Save Location
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Location Modal -->
<div class="modal fade" id="editLocationModal" tabindex="-1" role="dialog" aria-labelledby="editLocationModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-warning text-white">
                <h5 class="modal-title" id="editLocationModalLabel">
                    <i class="fas fa-edit mr-2"></i>Edit Location
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST" action="asset_location_process.php">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="location_id" id="edit_location_id">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    
                    <div class="form-group">
                        <label for="edit_location_name">Location Name *</label>
                        <input type="text" class="form-control" id="edit_location_name" name="location_name" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_location_type">Location Type</label>
                        <select class="form-control" id="edit_location_type" name="location_type">
                            <option value="room">Room</option>
                            <option value="department">Department</option>
                            <option value="building">Building</option>
                            <option value="storage">Storage</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="edit_building">Building</label>
                                <input type="text" class="form-control" id="edit_building" name="building">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="edit_floor">Floor/Level</label>
                                <input type="text" class="form-control" id="edit_floor" name="floor">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_room_number">Room Number</label>
                        <input type="text" class="form-control" id="edit_room_number" name="room_number">
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_description">Description</label>
                        <textarea class="form-control" id="edit_description" name="description" rows="3"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="edit_is_active" name="is_active">
                            <label class="form-check-label" for="edit_is_active">Active Location</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">
                        <i class="fas fa-times mr-2"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-save mr-2"></i>Update Location
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
    $('select[name="status"], select[name="type"]').change(function() {
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

function editLocation(locationId, locationName, locationType, building, floor, roomNumber, description, isActive) {
    $('#edit_location_id').val(locationId);
    $('#edit_location_name').val(locationName);
    $('#edit_location_type').val(locationType);
    $('#edit_building').val(building);
    $('#edit_floor').val(floor);
    $('#edit_room_number').val(roomNumber);
    $('#edit_description').val(description);
    $('#edit_is_active').prop('checked', isActive);
    
    $('#editLocationModal').modal('show');
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + N for new location
    if (e.ctrlKey && e.keyCode === 78) {
        e.preventDefault();
        $('#addLocationModal').modal('show');
    }
    // Ctrl + A for assets
    if (e.ctrlKey && e.keyCode === 65) {
        e.preventDefault();
        window.location.href = 'asset_management.php';
    }
    // Ctrl + C for categories
    if (e.ctrlKey && e.keyCode === 67) {
        e.preventDefault();
        window.location.href = 'asset_categories.php';
    }
    // Ctrl + F for focus search
    if (e.ctrlKey && e.keyCode === 70) {
        e.preventDefault();
        $('input[name="q"]').focus();
    }
});

// Confirm action links
$(document).on('click', '.confirm-link', function(e) {
    if (!confirm('Are you sure you want to delete this location? This action cannot be undone.')) {
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
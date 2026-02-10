<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Default Column Sortby/Order Filter
$sort = "e.equipment_name";
$order = "ASC";

   require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
    
// Get theatre ID from URL if provided
$theatre_id = intval($_GET['theatre_id'] ?? 0);
$theatre_name = "";
$theatre_number = "";

if ($theatre_id > 0) {
    // Fetch theatre details
    $theatre_sql = "SELECT theatre_name, theatre_number FROM theatres WHERE theatre_id = ? AND archived_at IS NULL";
    $theatre_stmt = $mysqli->prepare($theatre_sql);
    $theatre_stmt->bind_param("i", $theatre_id);
    $theatre_stmt->execute();
    $theatre_result = $theatre_stmt->get_result();
    
    if ($theatre_result->num_rows > 0) {
        $theatre_data = $theatre_result->fetch_assoc();
        $theatre_name = $theatre_data['theatre_name'];
        $theatre_number = $theatre_data['theatre_number'];
    }
}

// Filter parameters
$status_filter = $_GET['status'] ?? '';
$type_filter = $_GET['type'] ?? '';
$maintenance_filter = $_GET['maintenance'] ?? '';

// Status Filter
if ($status_filter) {
    $status_query = "AND e.status = '" . sanitizeInput($status_filter) . "'";
} else {
    $status_query = '';
}

// Type Filter
if ($type_filter) {
    $type_query = "AND e.equipment_type = '" . sanitizeInput($type_filter) . "'";
} else {
    $type_query = '';
}

// Maintenance Filter
if ($maintenance_filter === 'due') {
    $maintenance_query = "AND (e.last_maintenance_date IS NULL OR DATEDIFF(CURDATE(), e.last_maintenance_date) > 180)";
} elseif ($maintenance_filter === 'recent') {
    $maintenance_query = "AND e.last_maintenance_date IS NOT NULL AND DATEDIFF(CURDATE(), e.last_maintenance_date) <= 90";
} else {
    $maintenance_query = '';
}

// Theatre Filter
if ($theatre_id > 0) {
    $theatre_query = "AND e.theatre_id = " . intval($theatre_id);
} else {
    $theatre_query = '';
}

// Search Query
$q = sanitizeInput($_GET['q'] ?? '');
if (!empty($q)) {
    $search_query = "AND (
        e.equipment_name LIKE '%$q%' 
        OR e.equipment_type LIKE '%$q%'
        OR e.model_number LIKE '%$q%'
        OR e.serial_number LIKE '%$q%'
        OR t.theatre_name LIKE '%$q%'
        OR t.theatre_number LIKE '%$q%'
    )";
} else {
    $search_query = '';
}

// Main query for equipment
$sql = mysqli_query(
    $mysqli,
    "
    SELECT SQL_CALC_FOUND_ROWS e.*,
           t.theatre_name, t.theatre_number, t.location,
           u.user_name as created_by_name,
           mu.user_name as maintained_by_name,
           DATEDIFF(CURDATE(), e.last_maintenance_date) as days_since_maintenance,
           (SELECT COUNT(*) FROM maintenance em 
            WHERE em.equipment_id = e.equipment_id 
            AND em.status = 'pending') as pending_maintenance
    FROM theatre_equipment e
    LEFT JOIN theatres t ON e.theatre_id = t.theatre_id
    LEFT JOIN users u ON e.created_by = u.user_id
    LEFT JOIN users mu ON e.maintained_by = mu.user_id
    WHERE e.archived_at IS NULL
      $theatre_query
      $status_query
      $type_query
      $maintenance_query
      $search_query
    ORDER BY $sort $order
    LIMIT $record_from, $record_to
");

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get statistics
$total_equipment = $num_rows[0];
$active_count = 0;
$maintenance_count = 0;
$inactive_count = 0;
$due_maintenance_count = 0;

// Reset pointer and calculate
mysqli_data_seek($sql, 0);
while ($equipment = mysqli_fetch_assoc($sql)) {
    switch($equipment['status']) {
        case 'active':
            $active_count++;
            break;
        case 'maintenance':
            $maintenance_count++;
            break;
        case 'inactive':
            $inactive_count++;
            break;
    }
    
    // Check for due maintenance (more than 180 days)
    if ($equipment['last_maintenance_date']) {
        $days_since = (strtotime(date('Y-m-d')) - strtotime($equipment['last_maintenance_date'])) / (60 * 60 * 24);
        if ($days_since > 180) {
            $due_maintenance_count++;
        }
    } else {
        $due_maintenance_count++; // Never maintained
    }
}
mysqli_data_seek($sql, $record_from);

// Get unique equipment types for filter
$types_sql = mysqli_query($mysqli, "
    SELECT DISTINCT equipment_type 
    FROM theatre_equipment 
    WHERE equipment_type IS NOT NULL 
    AND equipment_type != '' 
    AND archived_at IS NULL
    ORDER BY equipment_type
");
?>

<div class="card">
    <div class="card-header bg-info py-2">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h3 class="card-title mt-2 mb-0 text-white">
                    <i class="fas fa-fw fa-tools mr-2"></i>
                    <?php echo $theatre_id ? "Equipment - OT $theatre_number - $theatre_name" : "Theatre Equipment Management"; ?>
                </h3>
                <?php if ($theatre_id): ?>
                    <small class="text-white-50">Managing equipment for specific theatre</small>
                <?php else: ?>
                    <small class="text-white-50">All theatre equipment across all operation theatres</small>
                <?php endif; ?>
            </div>
            <div class="card-tools">
                <a href="equipment_new.php<?php echo $theatre_id ? '?theatre_id=' . $theatre_id : ''; ?>" class="btn btn-success">
                    <i class="fas fa-plus mr-2"></i>New Equipment
                </a>
            </div>
        </div>
    </div>
    
    <!-- Statistics Row -->
    <div class="card-body border-bottom">
        <div class="row text-center">
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-primary"><i class="fas fa-tools"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Equipment</span>
                        <span class="info-box-number"><?php echo $total_equipment; ?></span>
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
                    <span class="info-box-icon bg-danger"><i class="fas fa-exclamation-triangle"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Due Maintenance</span>
                        <span class="info-box-number"><?php echo $due_maintenance_count; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-secondary"><i class="fas fa-ban"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Inactive</span>
                        <span class="info-box-number"><?php echo $inactive_count; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-dark"><i class="fas fa-hospital-alt"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Theatres</span>
                        <?php
                        $theatre_count_sql = "SELECT COUNT(DISTINCT theatre_id) as count FROM theatre_equipment WHERE archived_at IS NULL";
                        $theatre_count_result = $mysqli->query($theatre_count_sql);
                        $theatre_count = $theatre_count_result->fetch_assoc()['count'] ?? 0;
                        ?>
                        <span class="info-box-number"><?php echo $theatre_count; ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card-header pb-2 pt-3">
        <form autocomplete="off">
            <?php if ($theatre_id): ?>
                <input type="hidden" name="theatre_id" value="<?php echo $theatre_id; ?>">
            <?php endif; ?>
            
            <div class="row">
                <div class="col-md-5">
                    <div class="form-group">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search equipment name, type, model, serial..." autofocus>
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
                            <?php if ($theatre_id): ?>
                                <a href="theatre_view.php?id=<?php echo $theatre_id; ?>" class="btn btn-default">
                                    <i class="fas fa-fw fa-arrow-left mr-2"></i>Back to Theatre
                                </a>
                            <?php else: ?>
                                <a href="ot_management.php" class="btn btn-default">
                                    <i class="fas fa-fw fa-hospital-alt mr-2"></i>View Theatres
                                </a>
                            <?php endif; ?>
                            <a href="equipment_maintenance.php" class="btn btn-default">
                                <i class="fas fa-fw fa-wrench mr-2"></i>Maintenance
                            </a>
                            <div class="btn-group">
                                <button type="button" class="btn btn-primary dropdown-toggle" data-toggle="dropdown">
                                    <i class="fas fa-cog mr-2"></i>Quick Actions
                                </button>
                                <div class="dropdown-menu dropdown-menu-right">
                                    <a class="dropdown-item" href="equipment_new.php"><i class="fas fa-plus mr-2"></i>New Equipment</a>
                                    <a class="dropdown-item" href="equipment_maintenance.php"><i class="fas fa-wrench mr-2"></i>Maintenance Schedule</a>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item" href="reports_equipment_utilization.php"><i class="fas fa-chart-bar mr-2"></i>Equipment Reports</a>
                                    <a class="dropdown-item" href="reports_maintenance_costs.php"><i class="fas fa-file-invoice-dollar mr-2"></i>Maintenance Costs</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse 
                <?php 
                if (isset($_GET['status']) || isset($_GET['type']) || isset($_GET['maintenance']) || (!$theatre_id && isset($_GET['theatre_id']))) { 
                    echo "show"; 
                } 
                ?>" 
                id="advancedFilter"
            >
                <div class="row">
                    <?php if (!$theatre_id): ?>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Theatre</label>
                            <select class="form-control select2" name="theatre_id" onchange="this.form.submit()">
                                <option value="">- All Theatres -</option>
                                <?php
                                $theatres_sql = mysqli_query($mysqli, "
                                    SELECT theatre_id, theatre_number, theatre_name 
                                    FROM theatres 
                                    WHERE archived_at IS NULL 
                                    ORDER BY theatre_number
                                ");
                                while($theatre = mysqli_fetch_assoc($theatres_sql)) {
                                    $t_id = intval($theatre['theatre_id']);
                                    $t_number = nullable_htmlentities($theatre['theatre_number']);
                                    $t_name = nullable_htmlentities($theatre['theatre_name']);
                                    $selected = ($_GET['theatre_id'] ?? '') == $t_id ? 'selected' : '';
                                    echo "<option value='$t_id' $selected>OT $t_number - $t_name</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Status</label>
                            <select class="form-control select2" name="status" onchange="this.form.submit()">
                                <option value="">- All Status -</option>
                                <option value="active" <?php if ($status_filter == "active") { echo "selected"; } ?>>Active</option>
                                <option value="maintenance" <?php if ($status_filter == "maintenance") { echo "selected"; } ?>>Maintenance</option>
                                <option value="inactive" <?php if ($status_filter == "inactive") { echo "selected"; } ?>>Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Type</label>
                            <select class="form-control select2" name="type" onchange="this.form.submit()">
                                <option value="">- All Types -</option>
                                <?php
                                while($type = mysqli_fetch_assoc($types_sql)) {
                                    $type_name = nullable_htmlentities($type['equipment_type']);
                                    $selected = $type_filter == $type_name ? 'selected' : '';
                                    echo "<option value='$type_name' $selected>$type_name</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Maintenance</label>
                            <select class="form-control select2" name="maintenance" onchange="this.form.submit()">
                                <option value="">- All -</option>
                                <option value="due" <?php if ($maintenance_filter == "due") { echo "selected"; } ?>>Due for Maintenance</option>
                                <option value="recent" <?php if ($maintenance_filter == "recent") { echo "selected"; } ?>>Recently Maintained</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Sort By</label>
                            <select class="form-control select2" name="sort" onchange="this.form.submit()">
                                <option value="e.equipment_name" <?php if ($sort == "e.equipment_name") { echo "selected"; } ?>>Equipment Name</option>
                                <option value="e.equipment_type" <?php if ($sort == "e.equipment_type") { echo "selected"; } ?>>Equipment Type</option>
                                <option value="e.status" <?php if ($sort == "e.status") { echo "selected"; } ?>>Status</option>
                                <option value="t.theatre_number" <?php if ($sort == "t.theatre_number") { echo "selected"; } ?>>Theatre</option>
                                <option value="e.last_maintenance_date" <?php if ($sort == "e.last_maintenance_date") { echo "selected"; } ?>>Last Maintenance</option>
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
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=e.equipment_name&order=<?php echo $disp; ?>">
                        Equipment Name <?php if ($sort == 'e.equipment_name') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=e.equipment_type&order=<?php echo $disp; ?>">
                        Type <?php if ($sort == 'e.equipment_type') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>Model/Serial</th>
                <?php if (!$theatre_id): ?>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=t.theatre_number&order=<?php echo $disp; ?>">
                        Theatre <?php if ($sort == 't.theatre_number') { echo $order_icon; } ?>
                    </a>
                </th>
                <?php endif; ?>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=e.status&order=<?php echo $disp; ?>">
                        Status <?php if ($sort == 'e.status') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=e.last_maintenance_date&order=<?php echo $disp; ?>">
                        Last Maintenance <?php if ($sort == 'e.last_maintenance_date') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>Maintenance Due</th>
                <th class="text-center">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php while ($row = mysqli_fetch_array($sql)) {
                $equipment_id = intval($row['equipment_id']);
                $equipment_name = nullable_htmlentities($row['equipment_name']);
                $equipment_type = nullable_htmlentities($row['equipment_type']);
                $model_number = nullable_htmlentities($row['model_number']);
                $serial_number = nullable_htmlentities($row['serial_number']);
                $status = nullable_htmlentities($row['status']);
                $last_maintenance_date = nullable_htmlentities($row['last_maintenance_date']);
                $days_since_maintenance = intval($row['days_since_maintenance']);
                $theatre_name = nullable_htmlentities($row['theatre_name']);
                $theatre_number = nullable_htmlentities($row['theatre_number']);
                $location = nullable_htmlentities($row['location']);
                $pending_maintenance = intval($row['pending_maintenance']);
                $created_by_name = nullable_htmlentities($row['created_by_name']);
                $maintained_by_name = nullable_htmlentities($row['maintained_by_name']);

                // Status badge styling
                $status_badge = '';
                $status_icon = '';
                switch($status) {
                    case 'active':
                        $status_badge = 'badge-success';
                        $status_icon = 'fa-check-circle';
                        break;
                    case 'maintenance':
                        $status_badge = 'badge-warning';
                        $status_icon = 'fa-wrench';
                        break;
                    case 'inactive':
                        $status_badge = 'badge-secondary';
                        $status_icon = 'fa-ban';
                        break;
                    default:
                        $status_badge = 'badge-light';
                        $status_icon = 'fa-question';
                }

                // Maintenance due indicator
                $maintenance_due = false;
                $maintenance_warning = '';
                if (!$last_maintenance_date) {
                    $maintenance_due = true;
                    $maintenance_warning = 'Never maintained';
                } elseif ($days_since_maintenance > 180) {
                    $maintenance_due = true;
                    $maintenance_warning = $days_since_maintenance . ' days ago';
                } else {
                    $maintenance_warning = $days_since_maintenance . ' days ago';
                }
                ?>
                <tr class="<?php echo $maintenance_due ? 'table-warning' : ''; ?>">
                    <td>
                        <div class="font-weight-bold"><?php echo $equipment_name; ?></div>
                        <small class="text-muted">ID: <?php echo $equipment_id; ?></small>
                    </td>
                    <td>
                        <span class="badge badge-light"><?php echo $equipment_type; ?></span>
                    </td>
                    <td>
                        <div><small class="text-muted">Model: <?php echo $model_number ?: 'N/A'; ?></small></div>
                        <div><small class="text-muted">Serial: <?php echo $serial_number ?: 'N/A'; ?></small></div>
                    </td>
                    <?php if (!$theatre_id): ?>
                    <td>
                        <?php if($theatre_name): ?>
                            <div class="font-weight-bold">OT <?php echo $theatre_number; ?></div>
                            <small class="text-muted"><?php echo $theatre_name; ?></small>
                            <?php if($location): ?>
                                <br><small class="text-muted"><i class="fas fa-map-marker-alt"></i> <?php echo $location; ?></small>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted">Not assigned</span>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <td>
                        <span class="badge <?php echo $status_badge; ?>">
                            <i class="fas <?php echo $status_icon; ?> mr-1"></i><?php echo ucfirst($status); ?>
                        </span>
                        <?php if($pending_maintenance > 0): ?>
                            <br><small class="text-warning"><i class="fas fa-clock"></i> <?php echo $pending_maintenance; ?> pending</small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if($last_maintenance_date): ?>
                            <div><?php echo date('M j, Y', strtotime($last_maintenance_date)); ?></div>
                            <small class="text-muted">by <?php echo $maintained_by_name ?: 'N/A'; ?></small>
                        <?php else: ?>
                            <span class="text-muted">Never</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if($maintenance_due): ?>
                            <span class="badge badge-danger">
                                <i class="fas fa-exclamation-triangle mr-1"></i>Due
                            </span>
                            <br><small class="text-danger"><?php echo $maintenance_warning; ?></small>
                        <?php else: ?>
                            <span class="badge badge-success">
                                <i class="fas fa-check mr-1"></i>OK
                            </span>
                            <br><small class="text-muted"><?php echo $maintenance_warning; ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="dropdown dropleft text-center">
                            <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                <i class="fas fa-ellipsis-h"></i>
                            </button>
                            <div class="dropdown-menu">
                                <a class="dropdown-item" href="equipment_view.php?id=<?php echo $equipment_id; ?>">
                                    <i class="fas fa-fw fa-eye mr-2"></i>View Details
                                </a>
                                <a class="dropdown-item" href="equipment_edit.php?id=<?php echo $equipment_id; ?>">
                                    <i class="fas fa-fw fa-edit mr-2"></i>Edit Equipment
                                </a>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item" href="equipment_maintenance.php?equipment_id=<?php echo $equipment_id; ?>">
                                    <i class="fas fa-fw fa-wrench mr-2"></i>Maintenance History
                                </a>
                                <a class="dropdown-item" href="maintenance_new.php?equipment_id=<?php echo $equipment_id; ?>">
                                    <i class="fas fa-fw fa-plus mr-2"></i>Schedule Maintenance
                                </a>
                                <div class="dropdown-divider"></div>
                                <?php if($status == 'active'): ?>
                                    <a class="dropdown-item text-warning confirm-action" href="post.php?set_equipment_maintenance=<?php echo $equipment_id; ?>" data-message="Set this equipment to maintenance mode?">
                                        <i class="fas fa-fw fa-wrench mr-2"></i>Set Maintenance
                                    </a>
                                <?php elseif($status == 'maintenance'): ?>
                                    <a class="dropdown-item text-success confirm-action" href="post.php?set_equipment_active=<?php echo $equipment_id; ?>" data-message="Set this equipment as active?">
                                        <i class="fas fa-fw fa-check mr-2"></i>Set Active
                                    </a>
                                <?php endif; ?>
                                <?php if($status != 'inactive'): ?>
                                    <a class="dropdown-item text-secondary confirm-action" href="post.php?set_equipment_inactive=<?php echo $equipment_id; ?>" data-message="Set this equipment as inactive?">
                                        <i class="fas fa-fw fa-ban mr-2"></i>Set Inactive
                                    </a>
                                <?php else: ?>
                                    <a class="dropdown-item text-success confirm-action" href="post.php?set_equipment_active=<?php echo $equipment_id; ?>" data-message="Set this equipment as active?">
                                        <i class="fas fa-fw fa-check mr-2"></i>Set Active
                                    </a>
                                <?php endif; ?>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item text-danger confirm-action" href="post.php?archive_equipment=<?php echo $equipment_id; ?>" data-message="Are you sure you want to archive this equipment? This action cannot be undone.">
                                    <i class="fas fa-fw fa-archive mr-2"></i>Archive Equipment
                                </a>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php
            } ?>

            <?php if ($num_rows[0] == 0): ?>
                <tr>
                    <td colspan="<?php echo $theatre_id ? '7' : '8'; ?>" class="text-center py-5">
                        <i class="fas fa-tools fa-3x text-muted mb-3"></i>
                        <h4 class="text-muted">No Equipment Found</h4>
                        <p class="text-muted">No equipment matches your search criteria.</p>
                        <a href="equipment_new.php<?php echo $theatre_id ? '?theatre_id=' . $theatre_id : ''; ?>" class="btn btn-primary">
                            <i class="fas fa-plus mr-2"></i>Add New Equipment
                        </a>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Ends Card Body -->
  <?php 
     require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/filter_footer.php';

    ?>
    
</div> <!-- End Card -->

<!-- Maintenance Overview Card -->
<div class="card mt-4">
    <div class="card-header bg-warning">
        <h5 class="card-title mb-0 text-white">
            <i class="fas fa-exclamation-triangle mr-2"></i>Maintenance Overview
        </h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-4">
                <div class="d-flex align-items-center mb-3">
                    <span class="badge badge-success mr-3"><i class="fas fa-check fa-2x"></i></span>
                    <div>
                        <div class="h4 mb-0"><?php echo $total_equipment - $due_maintenance_count; ?></div>
                        <small class="text-muted">Equipment with OK Maintenance</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="d-flex align-items-center mb-3">
                    <span class="badge badge-danger mr-3"><i class="fas fa-exclamation-triangle fa-2x"></i></span>
                    <div>
                        <div class="h4 mb-0"><?php echo $due_maintenance_count; ?></div>
                        <small class="text-muted">Due for Maintenance</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="d-flex align-items-center mb-3">
                    <span class="badge badge-warning mr-3"><i class="fas fa-wrench fa-2x"></i></span>
                    <div>
                        <div class="h4 mb-0"><?php echo $maintenance_count; ?></div>
                        <small class="text-muted">Currently in Maintenance</small>
                    </div>
                </div>
            </div>
        </div>
        <?php if ($due_maintenance_count > 0): ?>
            <div class="alert alert-warning mt-3">
                <i class="fas fa-info-circle mr-2"></i>
                <strong>Action Required:</strong> <?php echo $due_maintenance_count; ?> equipment item(s) are due for maintenance. 
                <a href="?maintenance=due" class="alert-link">View all due equipment</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.select2').select2({
        theme: 'bootstrap4'
    });
    
    // Confirm action links
    $('.confirm-action').click(function(e) {
        if (!confirm($(this).data('message'))) {
            e.preventDefault();
        }
    });

    // Auto-refresh equipment status every 2 minutes
    setInterval(function() {
        $.get('ajax/equipment_status.php', function(data) {
            if(data.due_maintenance_count !== undefined) {
                $('.info-box .info-box-number').eq(3).text(data.due_maintenance_count);
            }
            // Update other stats as needed
        });
    }, 120000);
});

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + N for new equipment
    if (e.ctrlKey && e.keyCode === 78) {
        e.preventDefault();
        window.location.href = 'equipment_new.php<?php echo $theatre_id ? '?theatre_id=' . $theatre_id : ''; ?>';
    }
    // Ctrl + F for focus search
    if (e.ctrlKey && e.keyCode === 70) {
        e.preventDefault();
        $('input[name="q"]').focus().select();
    }
    // Ctrl + M for maintenance
    if (e.ctrlKey && e.keyCode === 77) {
        e.preventDefault();
        window.location.href = 'equipment_maintenance.php';
    }
});
</script>

<style>
.info-box {
    transition: transform 0.2s ease-in-out;
    border: 1px solid #e3e6f0;
}
.info-box:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}
.table-warning {
    background-color: rgba(255, 193, 7, 0.05) !important;
}
.badge-light {
    background-color: #f8f9fa;
    color: #6c757d;
    border: 1px solid #dee2e6;
}
</style>

<?php 
     require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';

    ?>
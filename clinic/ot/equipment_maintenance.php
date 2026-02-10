<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Default Column Sortby/Order Filter
$sort = "m.scheduled_date";
$order = "ASC";
   require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
    
// Get equipment ID from URL if provided
$equipment_id = intval($_GET['equipment_id'] ?? 0);
$equipment_name = "";
$equipment_type = "";

if ($equipment_id > 0) {
    // Fetch equipment details
    $equipment_sql = "SELECT equipment_name, equipment_type FROM theatre_equipment WHERE equipment_id = ? AND archived_at IS NULL";
    $equipment_stmt = $mysqli->prepare($equipment_sql);
    $equipment_stmt->bind_param("i", $equipment_id);
    $equipment_stmt->execute();
    $equipment_result = $equipment_stmt->get_result();
    
    if ($equipment_result->num_rows > 0) {
        $equipment_data = $equipment_result->fetch_assoc();
        $equipment_name = $equipment_data['equipment_name'];
        $equipment_type = $equipment_data['equipment_type'];
    } else {
        $equipment_id = 0; // Invalid equipment ID
    }
}

// Filter parameters
$status_filter = $_GET['status'] ?? '';
$priority_filter = $_GET['priority'] ?? '';
$type_filter = $_GET['type'] ?? '';
$timeframe_filter = $_GET['timeframe'] ?? '';

// Status Filter
if ($status_filter) {
    $status_query = "AND m.status = '" . sanitizeInput($status_filter) . "'";
} else {
    $status_query = '';
}

// Priority Filter
if ($priority_filter) {
    $priority_query = "AND m.priority = '" . sanitizeInput($priority_filter) . "'";
} else {
    $priority_query = '';
}

// Type Filter
if ($type_filter) {
    $type_query = "AND m.maintenance_type = '" . sanitizeInput($type_filter) . "'";
} else {
    $type_query = '';
}

// Timeframe Filter
$timeframe_query = '';
if ($timeframe_filter === 'today') {
    $timeframe_query = "AND m.scheduled_date = CURDATE()";
} elseif ($timeframe_filter === 'this_week') {
    $timeframe_query = "AND m.scheduled_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
} elseif ($timeframe_filter === 'overdue') {
    $timeframe_query = "AND m.scheduled_date < CURDATE() AND m.status IN ('scheduled', 'in_progress')";
} elseif ($timeframe_filter === 'upcoming') {
    $timeframe_query = "AND m.scheduled_date > CURDATE() AND m.status IN ('scheduled')";
}

// Equipment Filter
if ($equipment_id > 0) {
    $equipment_query = "AND m.equipment_id = " . intval($equipment_id);
} else {
    $equipment_query = '';
}

// Search Query
$q = sanitizeInput($_GET['q'] ?? '');
if (!empty($q)) {
    $search_query = "AND (
        m.description LIKE '%$q%' 
        OR m.maintenance_type LIKE '%$q%'
        OR e.equipment_name LIKE '%$q%'
        OR e.equipment_type LIKE '%$q%'
        OR t.theatre_number LIKE '%$q%'
        OR t.theatre_name LIKE '%$q%'
        OR u.user_name LIKE '%$q%'
    )";
} else {
    $search_query = '';
}

// Main query for maintenance records
$sql = mysqli_query(
    $mysqli,
    "
    SELECT SQL_CALC_FOUND_ROWS m.*,
           e.equipment_name, e.equipment_type, e.model, e.serial_number,
           t.theatre_name, t.theatre_number, t.location,
           u.user_name as created_by_name,
           pu.user_name as performed_by_name,
           DATEDIFF(m.scheduled_date, CURDATE()) as days_until,
           DATEDIFF(CURDATE(), m.scheduled_date) as days_overdue,
           CASE 
               WHEN m.scheduled_date < CURDATE() AND m.status IN ('scheduled', 'in_progress') THEN 'overdue'
               WHEN m.scheduled_date = CURDATE() THEN 'today'
               WHEN m.scheduled_date > CURDATE() THEN 'upcoming'
               ELSE 'completed'
           END as schedule_status
    FROM maintenance m
    LEFT JOIN theatre_equipment e ON m.equipment_id = e.equipment_id
    LEFT JOIN theatres t ON e.theatre_id = t.theatre_id
    LEFT JOIN users u ON m.created_by = u.user_id
    LEFT JOIN users pu ON m.performed_by = pu.user_id
    WHERE m.archived_at IS NULL
      $equipment_query
      $status_query
      $priority_query
      $type_query
      $timeframe_query
      $search_query
    ORDER BY $sort $order
    LIMIT $record_from, $record_to
");

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get statistics
$total_maintenance = $num_rows[0];
$scheduled_count = 0;
$in_progress_count = 0;
$completed_count = 0;
$cancelled_count = 0;
$overdue_count = 0;
$today_count = 0;

// Reset pointer and calculate
mysqli_data_seek($sql, 0);
while ($maintenance = mysqli_fetch_assoc($sql)) {
    switch($maintenance['status']) {
        case 'scheduled':
            $scheduled_count++;
            break;
        case 'in_progress':
            $in_progress_count++;
            break;
        case 'completed':
            $completed_count++;
            break;
        case 'cancelled':
            $cancelled_count++;
            break;
    }
    
    if ($maintenance['schedule_status'] === 'overdue') {
        $overdue_count++;
    }
    if ($maintenance['schedule_status'] === 'today') {
        $today_count++;
    }
}
mysqli_data_seek($sql, $record_from);

// Get maintenance statistics for dashboard
$stats_sql = "
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) as scheduled,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN scheduled_date < CURDATE() AND status IN ('scheduled', 'in_progress') THEN 1 ELSE 0 END) as overdue,
        SUM(CASE WHEN scheduled_date = CURDATE() THEN 1 ELSE 0 END) as today,
        AVG(CASE WHEN status = 'completed' THEN cost ELSE NULL END) as avg_cost
    FROM maintenance 
    WHERE archived_at IS NULL
";
$stats_result = $mysqli->query($stats_sql);
$maintenance_stats = $stats_result->fetch_assoc();

// Get unique maintenance types for filter
$types_sql = mysqli_query($mysqli, "
    SELECT DISTINCT maintenance_type 
    FROM maintenance 
    WHERE maintenance_type IS NOT NULL 
    AND maintenance_type != '' 
    AND archived_at IS NULL
    ORDER BY maintenance_type
");
?>

<div class="card">
    <div class="card-header bg-warning py-2">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h3 class="card-title mt-2 mb-0 text-dark">
                    <i class="fas fa-fw fa-wrench mr-2"></i>
                    <?php echo $equipment_id ? "Maintenance - $equipment_name" : "Equipment Maintenance Management"; ?>
                </h3>
                <?php if ($equipment_id): ?>
                    <small class="text-dark-50">Maintenance history and schedule for specific equipment</small>
                <?php else: ?>
                    <small class="text-dark-50">Schedule and track maintenance for all theatre equipment</small>
                <?php endif; ?>
            </div>
            <div class="card-tools">
                <a href="maintenance_new.php<?php echo $equipment_id ? '?equipment_id=' . $equipment_id : ''; ?>" class="btn btn-success">
                    <i class="fas fa-plus mr-2"></i>Schedule Maintenance
                </a>
            </div>
        </div>
    </div>
    
    <!-- Statistics Row -->
    <div class="card-body border-bottom">
        <div class="row text-center">
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-primary"><i class="fas fa-tasks"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total</span>
                        <span class="info-box-number"><?php echo $maintenance_stats['total'] ?? 0; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-info"><i class="fas fa-clock"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Scheduled</span>
                        <span class="info-box-number"><?php echo $maintenance_stats['scheduled'] ?? 0; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-warning"><i class="fas fa-tools"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">In Progress</span>
                        <span class="info-box-number"><?php echo $maintenance_stats['in_progress'] ?? 0; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-danger"><i class="fas fa-exclamation-triangle"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Overdue</span>
                        <span class="info-box-number"><?php echo $maintenance_stats['overdue'] ?? 0; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-success"><i class="fas fa-check-circle"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Completed</span>
                        <span class="info-box-number"><?php echo $maintenance_stats['completed'] ?? 0; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-secondary"><i class="fas fa-calendar-day"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Today</span>
                        <span class="info-box-number"><?php echo $maintenance_stats['today'] ?? 0; ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card-header pb-2 pt-3">
        <form autocomplete="off">
            <?php if ($equipment_id): ?>
                <input type="hidden" name="equipment_id" value="<?php echo $equipment_id; ?>">
            <?php endif; ?>
            
            <div class="row">
                <div class="col-md-5">
                    <div class="form-group">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search maintenance description, type, equipment..." autofocus>
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
                            <?php if ($equipment_id): ?>
                                <a href="equipment_view.php?id=<?php echo $equipment_id; ?>" class="btn btn-default">
                                    <i class="fas fa-fw fa-arrow-left mr-2"></i>Back to Equipment
                                </a>
                            <?php else: ?>
                                <a href="theatre_equipment.php" class="btn btn-default">
                                    <i class="fas fa-fw fa-tools mr-2"></i>View Equipment
                                </a>
                            <?php endif; ?>
                            <a href="maintenance_calendar.php" class="btn btn-default">
                                <i class="fas fa-fw fa-calendar-alt mr-2"></i>Calendar View
                            </a>
                            <div class="btn-group">
                                <button type="button" class="btn btn-primary dropdown-toggle" data-toggle="dropdown">
                                    <i class="fas fa-cog mr-2"></i>Quick Actions
                                </button>
                                <div class="dropdown-menu dropdown-menu-right">
                                    <a class="dropdown-item" href="maintenance_new.php"><i class="fas fa-plus mr-2"></i>Schedule Maintenance</a>
                                    <a class="dropdown-item" href="maintenance_calendar.php"><i class="fas fa-calendar-alt mr-2"></i>Maintenance Calendar</a>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item" href="reports_maintenance.php"><i class="fas fa-chart-bar mr-2"></i>Maintenance Reports</a>
                                    <a class="dropdown-item" href="reports_maintenance_costs.php"><i class="fas fa-file-invoice-dollar mr-2"></i>Cost Analysis</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse 
                <?php 
                if (isset($_GET['status']) || isset($_GET['priority']) || isset($_GET['type']) || isset($_GET['timeframe']) || (!$equipment_id && isset($_GET['equipment_id']))) { 
                    echo "show"; 
                } 
                ?>" 
                id="advancedFilter"
            >
                <div class="row">
                    <?php if (!$equipment_id): ?>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Equipment</label>
                            <select class="form-control select2" name="equipment_id" onchange="this.form.submit()">
                                <option value="">- All Equipment -</option>
                                <?php
                                $equipment_sql = mysqli_query($mysqli, "
                                    SELECT e.equipment_id, e.equipment_name, e.equipment_type, t.theatre_number
                                    FROM theatre_equipment e
                                    LEFT JOIN theatres t ON e.theatre_id = t.theatre_id
                                    WHERE e.archived_at IS NULL 
                                    ORDER BY e.equipment_name
                                ");
                                while($equip = mysqli_fetch_assoc($equipment_sql)) {
                                    $e_id = intval($equip['equipment_id']);
                                    $e_name = nullable_htmlentities($equip['equipment_name']);
                                    $e_type = nullable_htmlentities($equip['equipment_type']);
                                    $t_number = nullable_htmlentities($equip['theatre_number']);
                                    $selected = ($_GET['equipment_id'] ?? '') == $e_id ? 'selected' : '';
                                    echo "<option value='$e_id' $selected>$e_name ($e_type) - OT $t_number</option>";
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
                                <option value="scheduled" <?php if ($status_filter == "scheduled") { echo "selected"; } ?>>Scheduled</option>
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
                                <option value="">- All Priority -</option>
                                <option value="low" <?php if ($priority_filter == "low") { echo "selected"; } ?>>Low</option>
                                <option value="medium" <?php if ($priority_filter == "medium") { echo "selected"; } ?>>Medium</option>
                                <option value="high" <?php if ($priority_filter == "high") { echo "selected"; } ?>>High</option>
                                <option value="critical" <?php if ($priority_filter == "critical") { echo "selected"; } ?>>Critical</option>
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
                                    $type_name = nullable_htmlentities($type['maintenance_type']);
                                    $selected = $type_filter == $type_name ? 'selected' : '';
                                    echo "<option value='$type_name' $selected>$type_name</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Timeframe</label>
                            <select class="form-control select2" name="timeframe" onchange="this.form.submit()">
                                <option value="">- All Time -</option>
                                <option value="today" <?php if ($timeframe_filter == "today") { echo "selected"; } ?>>Today</option>
                                <option value="this_week" <?php if ($timeframe_filter == "this_week") { echo "selected"; } ?>>This Week</option>
                                <option value="overdue" <?php if ($timeframe_filter == "overdue") { echo "selected"; } ?>>Overdue</option>
                                <option value="upcoming" <?php if ($timeframe_filter == "upcoming") { echo "selected"; } ?>>Upcoming</option>
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
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=m.scheduled_date&order=<?php echo $disp; ?>">
                        Schedule Date <?php if ($sort == 'm.scheduled_date') { echo $order_icon; } ?>
                    </a>
                </th>
                <?php if (!$equipment_id): ?>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=e.equipment_name&order=<?php echo $disp; ?>">
                        Equipment <?php if ($sort == 'e.equipment_name') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>Theatre</th>
                <?php endif; ?>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=m.maintenance_type&order=<?php echo $disp; ?>">
                        Type <?php if ($sort == 'm.maintenance_type') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>Description</th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=m.priority&order=<?php echo $disp; ?>">
                        Priority <?php if ($sort == 'm.priority') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=m.status&order=<?php echo $disp; ?>">
                        Status <?php if ($sort == 'm.status') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>Cost</th>
                <th class="text-center">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php while ($row = mysqli_fetch_array($sql)) {
                $maintenance_id = intval($row['maintenance_id']);
                $scheduled_date = nullable_htmlentities($row['scheduled_date']);
                $maintenance_type = nullable_htmlentities($row['maintenance_type']);
                $description = nullable_htmlentities($row['description']);
                $priority = nullable_htmlentities($row['priority']);
                $status = nullable_htmlentities($row['status']);
                $cost = $row['cost'] ? floatval($row['cost']) : null;
                $completed_date = nullable_htmlentities($row['completed_date']);
                $notes = nullable_htmlentities($row['notes']);
                $equipment_name = nullable_htmlentities($row['equipment_name']);
                $equipment_type = nullable_htmlentities($row['equipment_type']);
                $model_number = nullable_htmlentities($row['model_number']);
                $theatre_name = nullable_htmlentities($row['theatre_name']);
                $theatre_number = nullable_htmlentities($row['theatre_number']);
                $days_until = intval($row['days_until']);
                $days_overdue = intval($row['days_overdue']);
                $schedule_status = $row['schedule_status'];
                $created_by_name = nullable_htmlentities($row['created_by_name']);
                $performed_by_name = nullable_htmlentities($row['performed_by_name']);

                // Status badge styling
                $status_badge = '';
                $status_icon = '';
                switch($status) {
                    case 'scheduled':
                        $status_badge = 'badge-primary';
                        $status_icon = 'fa-clock';
                        break;
                    case 'in_progress':
                        $status_badge = 'badge-warning';
                        $status_icon = 'fa-tools';
                        break;
                    case 'completed':
                        $status_badge = 'badge-success';
                        $status_icon = 'fa-check';
                        break;
                    case 'cancelled':
                        $status_badge = 'badge-secondary';
                        $status_icon = 'fa-ban';
                        break;
                    default:
                        $status_badge = 'badge-light';
                        $status_icon = 'fa-question';
                }

                // Priority badge styling
                $priority_badge = '';
                switch($priority) {
                    case 'low':
                        $priority_badge = 'badge-info';
                        break;
                    case 'medium':
                        $priority_badge = 'badge-warning';
                        break;
                    case 'high':
                        $priority_badge = 'badge-danger';
                        break;
                    case 'critical':
                        $priority_badge = 'badge-dark';
                        break;
                    default:
                        $priority_badge = 'badge-light';
                }

                // Schedule status styling
                $schedule_class = '';
                $schedule_text = '';
                if ($schedule_status === 'overdue') {
                    $schedule_class = 'text-danger font-weight-bold';
                    $schedule_text = $days_overdue . ' day(s) overdue';
                } elseif ($schedule_status === 'today') {
                    $schedule_class = 'text-warning font-weight-bold';
                    $schedule_text = 'Today';
                } elseif ($schedule_status === 'upcoming') {
                    $schedule_class = 'text-info';
                    $schedule_text = 'In ' . $days_until . ' day(s)';
                } else {
                    $schedule_class = 'text-muted';
                    $schedule_text = 'Completed';
                }
                ?>
                <tr class="<?php echo $schedule_status === 'overdue' ? 'table-danger' : ($schedule_status === 'today' ? 'table-warning' : ''); ?>">
                    <td>
                        <div class="font-weight-bold <?php echo $schedule_class; ?>">
                            <?php echo date('M j, Y', strtotime($scheduled_date)); ?>
                        </div>
                        <small class="<?php echo $schedule_class; ?>"><?php echo $schedule_text; ?></small>
                    </td>
                    <?php if (!$equipment_id): ?>
                    <td>
                        <div class="font-weight-bold"><?php echo $equipment_name; ?></div>
                        <small class="text-muted"><?php echo $equipment_type; ?></small>
                        <?php if($model_number): ?>
                            <br><small class="text-muted">Model: <?php echo $model_number; ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if($theatre_name): ?>
                            <div>OT <?php echo $theatre_number; ?></div>
                            <small class="text-muted"><?php echo $theatre_name; ?></small>
                        <?php else: ?>
                            <span class="text-muted">N/A</span>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <td>
                        <span class="badge badge-light"><?php echo $maintenance_type; ?></span>
                    </td>
                    <td>
                        <div class="font-weight-bold"><?php echo $description; ?></div>
                        <?php if($notes): ?>
                            <small class="text-muted"><?php echo substr($notes, 0, 50); ?><?php echo strlen($notes) > 50 ? '...' : ''; ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge <?php echo $priority_badge; ?>">
                            <?php echo ucfirst($priority); ?>
                        </span>
                    </td>
                    <td>
                        <span class="badge <?php echo $status_badge; ?>">
                            <i class="fas <?php echo $status_icon; ?> mr-1"></i><?php echo ucfirst(str_replace('_', ' ', $status)); ?>
                        </span>
                        <?php if($performed_by_name && $status === 'completed'): ?>
                            <br><small class="text-muted">by <?php echo $performed_by_name; ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if($cost): ?>
                            <span class="font-weight-bold text-success">$<?php echo number_format($cost, 2); ?></span>
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
                                <a class="dropdown-item" href="maintenance_view.php?id=<?php echo $maintenance_id; ?>">
                                    <i class="fas fa-fw fa-eye mr-2"></i>View Details
                                </a>
                                <a class="dropdown-item" href="maintenance_edit.php?id=<?php echo $maintenance_id; ?>">
                                    <i class="fas fa-fw fa-edit mr-2"></i>Edit Maintenance
                                </a>
                                <div class="dropdown-divider"></div>
                                <?php if($status == 'scheduled'): ?>
                                    <a class="dropdown-item text-warning confirm-action" href="post.php?start_maintenance=<?php echo $maintenance_id; ?>" data-message="Start this maintenance task?">
                                        <i class="fas fa-fw fa-play mr-2"></i>Start Maintenance
                                    </a>
                                <?php elseif($status == 'in_progress'): ?>
                                    <a class="dropdown-item text-success confirm-action" href="post.php?complete_maintenance=<?php echo $maintenance_id; ?>" data-message="Mark this maintenance as completed?">
                                        <i class="fas fa-fw fa-check mr-2"></i>Complete Maintenance
                                    </a>
                                <?php endif; ?>
                                <?php if($status != 'completed' && $status != 'cancelled'): ?>
                                    <a class="dropdown-item text-secondary confirm-action" href="post.php?cancel_maintenance=<?php echo $maintenance_id; ?>" data-message="Cancel this maintenance task?">
                                        <i class="fas fa-fw fa-times mr-2"></i>Cancel Maintenance
                                    </a>
                                <?php endif; ?>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item" href="equipment_view.php?id=<?php echo $row['equipment_id']; ?>">
                                    <i class="fas fa-fw fa-tools mr-2"></i>View Equipment
                                </a>
                                <a class="dropdown-item text-danger confirm-action" href="post.php?archive_maintenance=<?php echo $maintenance_id; ?>" data-message="Are you sure you want to archive this maintenance record? This action cannot be undone.">
                                    <i class="fas fa-fw fa-archive mr-2"></i>Archive Record
                                </a>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php
            } ?>

            <?php if ($num_rows[0] == 0): ?>
                <tr>
                    <td colspan="<?php echo $equipment_id ? '7' : '9'; ?>" class="text-center py-5">
                        <i class="fas fa-wrench fa-3x text-muted mb-3"></i>
                        <h4 class="text-muted">No Maintenance Records Found</h4>
                        <p class="text-muted">No maintenance records match your search criteria.</p>
                        <a href="maintenance_new.php<?php echo $equipment_id ? '?equipment_id=' . $equipment_id : ''; ?>" class="btn btn-primary">
                            <i class="fas fa-plus mr-2"></i>Schedule New Maintenance
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

<!-- Maintenance Summary Card -->
<div class="card mt-4">
    <div class="card-header bg-info text-white">
        <h5 class="card-title mb-0">
            <i class="fas fa-chart-pie mr-2"></i>Maintenance Summary
        </h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-3 text-center">
                <div class="mb-3">
                    <div class="h2 text-primary mb-1"><?php echo $maintenance_stats['total'] ?? 0; ?></div>
                    <small class="text-muted">Total Maintenance Records</small>
                </div>
            </div>
            <div class="col-md-3 text-center">
                <div class="mb-3">
                    <div class="h2 text-warning mb-1"><?php echo $maintenance_stats['overdue'] ?? 0; ?></div>
                    <small class="text-muted">Overdue Maintenance</small>
                </div>
            </div>
            <div class="col-md-3 text-center">
                <div class="mb-3">
                    <div class="h2 text-success mb-1">$<?php echo number_format($maintenance_stats['avg_cost'] ?? 0, 2); ?></div>
                    <small class="text-muted">Average Cost</small>
                </div>
            </div>
            <div class="col-md-3 text-center">
                <div class="mb-3">
                    <div class="h2 text-info mb-1"><?php echo $maintenance_stats['today'] ?? 0; ?></div>
                    <small class="text-muted">Scheduled Today</small>
                </div>
            </div>
        </div>
        
        <?php if ($maintenance_stats['overdue'] > 0): ?>
            <div class="alert alert-danger mt-3">
                <i class="fas fa-exclamation-triangle mr-2"></i>
                <strong>Attention Required:</strong> <?php echo $maintenance_stats['overdue']; ?> maintenance task(s) are overdue. 
                <a href="?timeframe=overdue" class="alert-link">View overdue maintenance</a>
            </div>
        <?php endif; ?>
        
        <?php if ($maintenance_stats['today'] > 0): ?>
            <div class="alert alert-warning mt-2">
                <i class="fas fa-calendar-day mr-2"></i>
                <strong>Today's Schedule:</strong> <?php echo $maintenance_stats['today']; ?> maintenance task(s) scheduled for today. 
                <a href="?timeframe=today" class="alert-link">View today's maintenance</a>
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

    // Auto-refresh maintenance status every 2 minutes
    setInterval(function() {
        $.get('ajax/maintenance_status.php', function(data) {
            if(data.overdue_count !== undefined) {
                $('.info-box .info-box-number').eq(3).text(data.overdue_count);
            }
            if(data.today_count !== undefined) {
                $('.info-box .info-box-number').eq(5).text(data.today_count);
            }
            // Update other stats as needed
        });
    }, 120000);

    // Highlight overdue and today's maintenance
    $('tr.table-danger, tr.table-warning').hover(
        function() {
            $(this).addClass('highlight-row');
        },
        function() {
            $(this).removeClass('highlight-row');
        }
    );
});

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + N for new maintenance
    if (e.ctrlKey && e.keyCode === 78) {
        e.preventDefault();
        window.location.href = 'maintenance_new.php<?php echo $equipment_id ? '?equipment_id=' . $equipment_id : ''; ?>';
    }
    // Ctrl + F for focus search
    if (e.ctrlKey && e.keyCode === 70) {
        e.preventDefault();
        $('input[name="q"]').focus().select();
    }
    // Ctrl + C for calendar view
    if (e.ctrlKey && e.keyCode === 67) {
        e.preventDefault();
        window.location.href = 'maintenance_calendar.php';
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
.table-danger {
    background-color: rgba(220, 53, 69, 0.05) !important;
}
.table-warning {
    background-color: rgba(255, 193, 7, 0.05) !important;
}
.highlight-row {
    transform: scale(1.01);
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    transition: all 0.2s ease;
}
</style>

<?php 
     require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';

    ?>
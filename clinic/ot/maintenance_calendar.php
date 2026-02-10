<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

   require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
    

// Get date parameters
$year = intval($_GET['year'] ?? date('Y'));
$month = intval($_GET['month'] ?? date('n'));
$view = $_GET['view'] ?? 'month'; // month, week, day

// Validate month and year
if ($month < 1 || $month > 12) $month = date('n');
if ($year < 2020 || $year > 2030) $year = date('Y');

// Calculate date ranges based on view
$first_day = date('Y-m-01', strtotime("$year-$month-01"));
$last_day = date('Y-m-t', strtotime($first_day));

if ($view === 'week') {
    $week_start = $_GET['week_start'] ?? $first_day;
    $week_end = date('Y-m-d', strtotime($week_start . ' +6 days'));
} elseif ($view === 'day') {
    $day_date = $_GET['day_date'] ?? date('Y-m-d');
}

// Navigation dates
$prev_month = $month - 1;
$prev_year = $year;
$next_month = $month + 1;
$next_year = $year;

if ($prev_month < 1) {
    $prev_month = 12;
    $prev_year = $year - 1;
}
if ($next_month > 12) {
    $next_month = 1;
    $next_year = $year + 1;
}

// Get filter parameters
$equipment_filter = $_GET['equipment_id'] ?? '';
$priority_filter = $_GET['priority'] ?? '';
$status_filter = $_GET['status'] ?? '';

// Build query conditions
$conditions = ["m.archived_at IS NULL"];
$params = [];
$types = '';

if ($view === 'month') {
    $conditions[] = "m.scheduled_date BETWEEN ? AND ?";
    $params[] = $first_day;
    $params[] = $last_day;
    $types .= 'ss';
} elseif ($view === 'week') {
    $conditions[] = "m.scheduled_date BETWEEN ? AND ?";
    $params[] = $week_start;
    $params[] = $week_end;
    $types .= 'ss';
} elseif ($view === 'day') {
    $conditions[] = "m.scheduled_date = ?";
    $params[] = $day_date;
    $types .= 's';
}

if ($equipment_filter) {
    $conditions[] = "m.equipment_id = ?";
    $params[] = intval($equipment_filter);
    $types .= 'i';
}

if ($priority_filter) {
    $conditions[] = "m.priority = ?";
    $params[] = sanitizeInput($priority_filter);
    $types .= 's';
}

if ($status_filter) {
    $conditions[] = "m.status = ?";
    $params[] = sanitizeInput($status_filter);
    $types .= 's';
}

$where_clause = implode(' AND ', $conditions);

// Fetch maintenance records
$maintenance_sql = "
    SELECT m.*,
           e.equipment_name, e.equipment_type, e.model,
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
    WHERE $where_clause
    ORDER BY m.scheduled_date, m.priority DESC, m.equipment_id
";

$maintenance_stmt = $mysqli->prepare($maintenance_sql);
if ($params) {
    $maintenance_stmt->bind_param($types, ...$params);
}
$maintenance_stmt->execute();
$maintenance_result = $maintenance_stmt->get_result();

// Group maintenance by date for calendar views
$maintenance_by_date = [];
$all_maintenance = [];

while ($maintenance = $maintenance_result->fetch_assoc()) {
    $date = $maintenance['scheduled_date'];
    if (!isset($maintenance_by_date[$date])) {
        $maintenance_by_date[$date] = [];
    }
    $maintenance_by_date[$date][] = $maintenance;
    $all_maintenance[] = $maintenance;
}

// Get equipment list for filter
$equipment_sql = "
    SELECT equipment_id, equipment_name, equipment_type 
    FROM theatre_equipment 
    WHERE archived_at IS NULL 
    ORDER BY equipment_name
";
$equipment_result = $mysqli->query($equipment_sql);

// Generate calendar data for month view
if ($view === 'month') {
    $calendar = [];
    $first_day_of_month = date('N', strtotime($first_day)); // 1 (Monday) through 7 (Sunday)
    $days_in_month = date('t', strtotime($first_day));
    $weeks = ceil(($first_day_of_month + $days_in_month - 1) / 7);

    for ($week = 0; $week < $weeks; $week++) {
        $calendar[$week] = [];
        for ($day = 1; $day <= 7; $day++) {
            $day_number = ($week * 7) + $day - $first_day_of_month + 1;
            if ($day_number < 1 || $day_number > $days_in_month) {
                $calendar[$week][$day] = null;
            } else {
                $date = date('Y-m-d', strtotime("$year-$month-$day_number"));
                $calendar[$week][$day] = [
                    'day' => $day_number,
                    'date' => $date,
                    'maintenance' => $maintenance_by_date[$date] ?? [],
                    'is_today' => $date == date('Y-m-d'),
                    'is_weekend' => $day == 6 || $day == 7,
                    'is_past' => $date < date('Y-m-d')
                ];
            }
        }
    }
}

// Generate week data for week view
if ($view === 'week') {
    $week_days = [];
    for ($i = 0; $i < 7; $i++) {
        $date = date('Y-m-d', strtotime($week_start . " +$i days"));
        $week_days[] = [
            'date' => $date,
            'day_name' => date('D', strtotime($date)),
            'day_number' => date('j', strtotime($date)),
            'month' => date('M', strtotime($date)),
            'maintenance' => $maintenance_by_date[$date] ?? [],
            'is_today' => $date == date('Y-m-d'),
            'is_weekend' => date('N', strtotime($date)) >= 6
        ];
    }
}

// Month names for display
$month_names = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];

// Day names
$day_names = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h3 class="card-title mt-2 mb-0 text-white">
                    <i class="fas fa-fw fa-calendar-alt mr-2"></i>
                    Maintenance Calendar
                </h3>
                <small class="text-white-50">
                    <?php 
                    if ($view === 'month') {
                        echo $month_names[$month] . ' ' . $year;
                    } elseif ($view === 'week') {
                        echo 'Week of ' . date('M j', strtotime($week_start)) . ' - ' . date('M j, Y', strtotime($week_end));
                    } elseif ($view === 'day') {
                        echo date('l, F j, Y', strtotime($day_date));
                    }
                    ?>
                </small>
            </div>
            <div class="card-tools">
                <a href="maintenance_new.php" class="btn btn-success">
                    <i class="fas fa-plus mr-2"></i>Schedule Maintenance
                </a>
            </div>
        </div>
    </div>

    <!-- Calendar Controls -->
    <div class="card-header bg-light py-3">
        <div class="row align-items-center">
            <div class="col-md-6">
                <div class="btn-toolbar">
                    <!-- View Type Buttons -->
                    <div class="btn-group btn-group-sm mr-3">
                        <a href="?view=month&year=<?php echo $year; ?>&month=<?php echo $month; echo $equipment_filter ? '&equipment_id=' . $equipment_filter : ''; echo $priority_filter ? '&priority=' . $priority_filter : ''; echo $status_filter ? '&status=' . $status_filter : ''; ?>" 
                           class="btn btn-outline-primary <?php echo $view === 'month' ? 'active' : ''; ?>">
                            <i class="fas fa-calendar-month mr-1"></i> Month
                        </a>
                        <a href="?view=week&week_start=<?php echo $first_day; echo $equipment_filter ? '&equipment_id=' . $equipment_filter : ''; echo $priority_filter ? '&priority=' . $priority_filter : ''; echo $status_filter ? '&status=' . $status_filter : ''; ?>" 
                           class="btn btn-outline-primary <?php echo $view === 'week' ? 'active' : ''; ?>">
                            <i class="fas fa-calendar-week mr-1"></i> Week
                        </a>
                        <a href="?view=day&day_date=<?php echo date('Y-m-d'); echo $equipment_filter ? '&equipment_id=' . $equipment_filter : ''; echo $priority_filter ? '&priority=' . $priority_filter : ''; echo $status_filter ? '&status=' . $status_filter : ''; ?>" 
                           class="btn btn-outline-primary <?php echo $view === 'day' ? 'active' : ''; ?>">
                            <i class="fas fa-calendar-day mr-1"></i> Day
                        </a>
                    </div>

                    <!-- Navigation Buttons -->
                    <div class="btn-group btn-group-sm">
                        <?php if ($view === 'month'): ?>
                            <a href="?view=month&year=<?php echo $prev_year; ?>&month=<?php echo $prev_month; echo $equipment_filter ? '&equipment_id=' . $equipment_filter : ''; echo $priority_filter ? '&priority=' . $priority_filter : ''; echo $status_filter ? '&status=' . $status_filter : ''; ?>" 
                               class="btn btn-outline-secondary">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                            <a href="?view=month&year=<?php echo date('Y'); ?>&month=<?php echo date('n'); echo $equipment_filter ? '&equipment_id=' . $equipment_filter : ''; echo $priority_filter ? '&priority=' . $priority_filter : ''; echo $status_filter ? '&status=' . $status_filter : ''; ?>" 
                               class="btn btn-outline-secondary">
                                Today
                            </a>
                            <a href="?view=month&year=<?php echo $next_year; ?>&month=<?php echo $next_month; echo $equipment_filter ? '&equipment_id=' . $equipment_filter : ''; echo $priority_filter ? '&priority=' . $priority_filter : ''; echo $status_filter ? '&status=' . $status_filter : ''; ?>" 
                               class="btn btn-outline-secondary">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php elseif ($view === 'week'): ?>
                            <a href="?view=week&week_start=<?php echo date('Y-m-d', strtotime($week_start . ' -7 days')); echo $equipment_filter ? '&equipment_id=' . $equipment_filter : ''; echo $priority_filter ? '&priority=' . $priority_filter : ''; echo $status_filter ? '&status=' . $status_filter : ''; ?>" 
                               class="btn btn-outline-secondary">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                            <a href="?view=week&week_start=<?php echo date('Y-m-d'); echo $equipment_filter ? '&equipment_id=' . $equipment_filter : ''; echo $priority_filter ? '&priority=' . $priority_filter : ''; echo $status_filter ? '&status=' . $status_filter : ''; ?>" 
                               class="btn btn-outline-secondary">
                                Today
                            </a>
                            <a href="?view=week&week_start=<?php echo date('Y-m-d', strtotime($week_start . ' +7 days')); echo $equipment_filter ? '&equipment_id=' . $equipment_filter : ''; echo $priority_filter ? '&priority=' . $priority_filter : ''; echo $status_filter ? '&status=' . $status_filter : ''; ?>" 
                               class="btn btn-outline-secondary">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php elseif ($view === 'day'): ?>
                            <a href="?view=day&day_date=<?php echo date('Y-m-d', strtotime($day_date . ' -1 day')); echo $equipment_filter ? '&equipment_id=' . $equipment_filter : ''; echo $priority_filter ? '&priority=' . $priority_filter : ''; echo $status_filter ? '&status=' . $status_filter : ''; ?>" 
                               class="btn btn-outline-secondary">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                            <a href="?view=day&day_date=<?php echo date('Y-m-d'); echo $equipment_filter ? '&equipment_id=' . $equipment_filter : ''; echo $priority_filter ? '&priority=' . $priority_filter : ''; echo $status_filter ? '&status=' . $status_filter : ''; ?>" 
                               class="btn btn-outline-secondary">
                                Today
                            </a>
                            <a href="?view=day&day_date=<?php echo date('Y-m-d', strtotime($day_date . ' +1 day')); echo $equipment_filter ? '&equipment_id=' . $equipment_filter : ''; echo $priority_filter ? '&priority=' . $priority_filter : ''; echo $status_filter ? '&status=' . $status_filter : ''; ?>" 
                               class="btn btn-outline-secondary">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="row">
                    <!-- Quick Filters -->
                    <div class="col">
                        <select class="form-control form-control-sm" id="equipmentFilter" onchange="updateCalendarFilter()">
                            <option value="">All Equipment</option>
                            <?php while($equipment = $equipment_result->fetch_assoc()): ?>
                                <option value="<?php echo $equipment['equipment_id']; ?>" 
                                    <?php echo $equipment_filter == $equipment['equipment_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($equipment['equipment_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col">
                        <select class="form-control form-control-sm" id="priorityFilter" onchange="updateCalendarFilter()">
                            <option value="">All Priorities</option>
                            <option value="low" <?php echo $priority_filter == 'low' ? 'selected' : ''; ?>>Low</option>
                            <option value="medium" <?php echo $priority_filter == 'medium' ? 'selected' : ''; ?>>Medium</option>
                            <option value="high" <?php echo $priority_filter == 'high' ? 'selected' : ''; ?>>High</option>
                            <option value="critical" <?php echo $priority_filter == 'critical' ? 'selected' : ''; ?>>Critical</option>
                        </select>
                    </div>
                    <div class="col">
                        <select class="form-control form-control-sm" id="statusFilter" onchange="updateCalendarFilter()">
                            <option value="">All Status</option>
                            <option value="scheduled" <?php echo $status_filter == 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                            <option value="in_progress" <?php echo $status_filter == 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card-body p-0">
        <?php if ($view === 'month'): ?>
            <!-- Month View -->
            <div class="calendar-month-view">
                <div class="calendar-header">
                    <div class="row text-center font-weight-bold bg-light">
                        <?php foreach ($day_names as $day_name): ?>
                            <div class="col p-3 border"><?php echo $day_name; ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="calendar-body">
                    <?php foreach ($calendar as $week): ?>
                        <div class="row calendar-week">
                            <?php foreach ($week as $day => $day_data): ?>
                                <div class="col calendar-day p-2 border <?php echo $day_data ? ($day_data['is_weekend'] ? 'weekend' : '') : 'empty-day'; ?> <?php echo $day_data && $day_data['is_past'] ? 'past-day' : ''; ?>">
                                    <?php if ($day_data): ?>
                                        <div class="day-header">
                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <span class="day-number <?php echo $day_data['is_today'] ? 'today' : ''; ?>">
                                                    <?php echo $day_data['day']; ?>
                                                </span>
                                                <?php if (count($day_data['maintenance']) > 0): ?>
                                                    <span class="badge badge-primary badge-pill">
                                                        <?php echo count($day_data['maintenance']); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        
                                        <div class="maintenance-events">
                                            <?php foreach (array_slice($day_data['maintenance'], 0, 3) as $maintenance): 
                                                $priority_class = getPriorityClass($maintenance['priority']);
                                                $status_class = getStatusClass($maintenance['schedule_status']);
                                            ?>
                                                <div class="maintenance-event small p-2 mb-1 rounded <?php echo $priority_class . ' ' . $status_class; ?>" 
                                                     onclick="showMaintenanceDetails(<?php echo $maintenance['maintenance_id']; ?>)"
                                                     data-toggle="tooltip" 
                                                     title="Click to view details">
                                                    <div class="font-weight-bold truncate">
                                                        <?php echo htmlspecialchars($maintenance['equipment_name']); ?>
                                                    </div>
                                                    <div class="truncate">
                                                        <small><?php echo htmlspecialchars($maintenance['maintenance_type']); ?></small>
                                                    </div>
                                                    <div class="d-flex justify-content-between align-items-center mt-1">
                                                        <small class="badge badge-sm <?php echo getPriorityBadgeClass($maintenance['priority']); ?>">
                                                            <?php echo ucfirst($maintenance['priority']); ?>
                                                        </small>
                                                        <small class="text-muted">
                                                            <?php echo $maintenance['theatre_number'] ? 'OT ' . $maintenance['theatre_number'] : ''; ?>
                                                        </small>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                            
                                            <?php if (count($day_data['maintenance']) > 3): ?>
                                                <div class="text-center">
                                                    <small class="text-muted">
                                                        +<?php echo count($day_data['maintenance']) - 3; ?> more
                                                    </small>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

        <?php elseif ($view === 'week'): ?>
            <!-- Week View -->
            <div class="calendar-week-view">
                <div class="row">
                    <?php foreach ($week_days as $day): ?>
                        <div class="col week-day <?php echo $day['is_weekend'] ? 'weekend' : ''; ?>">
                            <div class="day-header bg-light p-3 border text-center">
                                <div class="font-weight-bold <?php echo $day['is_today'] ? 'text-primary' : ''; ?>">
                                    <?php echo $day['day_name']; ?>
                                </div>
                                <div class="h5 mb-0 <?php echo $day['is_today'] ? 'text-primary' : ''; ?>">
                                    <?php echo $day['day_number']; ?>
                                </div>
                                <div class="small text-muted">
                                    <?php echo $day['month']; ?>
                                </div>
                            </div>
                            <div class="day-events p-2 border" style="min-height: 400px;">
                                <?php if (empty($day['maintenance'])): ?>
                                    <div class="text-center text-muted mt-4">
                                        <i class="fas fa-wrench fa-2x mb-2"></i>
                                        <p>No maintenance scheduled</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($day['maintenance'] as $maintenance): ?>
                                        <div class="maintenance-event p-2 mb-2 rounded <?php echo getPriorityClass($maintenance['priority']); ?>" 
                                             onclick="showMaintenanceDetails(<?php echo $maintenance['maintenance_id']; ?>)">
                                            <div class="font-weight-bold">
                                                <?php echo htmlspecialchars($maintenance['equipment_name']); ?>
                                            </div>
                                            <div class="small">
                                                <?php echo htmlspecialchars($maintenance['maintenance_type']); ?>
                                            </div>
                                            <div class="small text-muted">
                                                <?php echo htmlspecialchars($maintenance['description']); ?>
                                            </div>
                                            <div class="d-flex justify-content-between align-items-center mt-1">
                                                <span class="badge badge-sm <?php echo getPriorityBadgeClass($maintenance['priority']); ?>">
                                                    <?php echo ucfirst($maintenance['priority']); ?>
                                                </span>
                                                <span class="badge badge-sm <?php echo $maintenance['status'] === 'in_progress' ? 'badge-warning' : 'badge-primary'; ?>">
                                                    <?php echo ucfirst(str_replace('_', ' ', $maintenance['status'])); ?>
                                                </span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

        <?php elseif ($view === 'day'): ?>
            <!-- Day View -->
            <div class="calendar-day-view">
                <div class="row">
                    <div class="col-md-8">
                        <div class="card">
                            <div class="card-header bg-light">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-list mr-2"></i>
                                    Maintenance Schedule for <?php echo date('l, F j, Y', strtotime($day_date)); ?>
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php 
                                $day_maintenance = $maintenance_by_date[$day_date] ?? [];
                                if (empty($day_maintenance)): ?>
                                    <div class="text-center py-5">
                                        <i class="fas fa-calendar-check fa-3x text-muted mb-3"></i>
                                        <h4 class="text-muted">No Maintenance Scheduled</h4>
                                        <p class="text-muted">No maintenance tasks scheduled for this day.</p>
                                        <a href="maintenance_new.php?schedule_date=<?php echo $day_date; ?>" class="btn btn-primary">
                                            <i class="fas fa-plus mr-2"></i>Schedule Maintenance
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <div class="timeline">
                                        <?php foreach ($day_maintenance as $maintenance): ?>
                                            <div class="timeline-item mb-4">
                                                <div class="timeline-marker <?php echo getPriorityBadgeClass($maintenance['priority']); ?>"></div>
                                                <div class="timeline-content">
                                                    <div class="card <?php echo getPriorityClass($maintenance['priority']); ?>">
                                                        <div class="card-body">
                                                            <div class="row">
                                                                <div class="col-md-8">
                                                                    <h6 class="card-title">
                                                                        <?php echo htmlspecialchars($maintenance['equipment_name']); ?>
                                                                        <span class="badge <?php echo getPriorityBadgeClass($maintenance['priority']); ?> ml-2">
                                                                            <?php echo ucfirst($maintenance['priority']); ?>
                                                                        </span>
                                                                    </h6>
                                                                    <p class="card-text mb-1">
                                                                        <strong>Type:</strong> <?php echo htmlspecialchars($maintenance['maintenance_type']); ?>
                                                                    </p>
                                                                    <p class="card-text mb-1">
                                                                        <strong>Description:</strong> <?php echo htmlspecialchars($maintenance['description']); ?>
                                                                    </p>
                                                                    <p class="card-text mb-0">
                                                                        <strong>Theatre:</strong> OT <?php echo htmlspecialchars($maintenance['theatre_number']); ?> - <?php echo htmlspecialchars($maintenance['theatre_name']); ?>
                                                                    </p>
                                                                </div>
                                                                <div class="col-md-4 text-right">
                                                                    <div class="btn-group-vertical">
                                                                        <a href="maintenance_view.php?id=<?php echo $maintenance['maintenance_id']; ?>" class="btn btn-info btn-sm">
                                                                            <i class="fas fa-eye mr-1"></i> View
                                                                        </a>
                                                                        <a href="maintenance_edit.php?id=<?php echo $maintenance['maintenance_id']; ?>" class="btn btn-warning btn-sm">
                                                                            <i class="fas fa-edit mr-1"></i> Edit
                                                                        </a>
                                                                        <?php if ($maintenance['status'] === 'scheduled'): ?>
                                                                            <a href="post.php?start_maintenance=<?php echo $maintenance['maintenance_id']; ?>" class="btn btn-success btn-sm confirm-action" data-message="Start this maintenance task?">
                                                                                <i class="fas fa-play mr-1"></i> Start
                                                                            </a>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <!-- Day Summary -->
                        <div class="card">
                            <div class="card-header bg-info text-white">
                                <h5 class="card-title mb-0">Day Summary</h5>
                            </div>
                            <div class="card-body">
                                <?php
                                $day_stats = [
                                    'total' => count($day_maintenance),
                                    'high_priority' => 0,
                                    'in_progress' => 0,
                                    'scheduled' => 0
                                ];
                                
                                foreach ($day_maintenance as $m) {
                                    if ($m['priority'] === 'high' || $m['priority'] === 'critical') {
                                        $day_stats['high_priority']++;
                                    }
                                    if ($m['status'] === 'in_progress') {
                                        $day_stats['in_progress']++;
                                    } else {
                                        $day_stats['scheduled']++;
                                    }
                                }
                                ?>
                                <div class="text-center mb-3">
                                    <div class="h2 text-primary"><?php echo $day_stats['total']; ?></div>
                                    <small class="text-muted">Total Maintenance Tasks</small>
                                </div>
                                <div class="row text-center">
                                    <div class="col-6 mb-3">
                                        <div class="h4 text-warning"><?php echo $day_stats['high_priority']; ?></div>
                                        <small class="text-muted">High Priority</small>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <div class="h4 text-info"><?php echo $day_stats['in_progress']; ?></div>
                                        <small class="text-muted">In Progress</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Quick Stats Card -->
<div class="card mt-4">
    <div class="card-header bg-light">
        <h5 class="card-title mb-0">
            <i class="fas fa-chart-bar mr-2"></i>Calendar Summary
        </h5>
    </div>
    <div class="card-body">
        <div class="row text-center">
            <div class="col-md-3">
                <div class="border rounded p-3">
                    <div class="h3 text-primary mb-1"><?php echo count($all_maintenance); ?></div>
                    <small class="text-muted">Total Scheduled</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="border rounded p-3">
                    <div class="h3 text-warning mb-1">
                        <?php echo count(array_filter($all_maintenance, function($m) { 
                            return $m['priority'] === 'high' || $m['priority'] === 'critical'; 
                        })); ?>
                    </div>
                    <small class="text-muted">High Priority</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="border rounded p-3">
                    <div class="h3 text-danger mb-1">
                        <?php echo count(array_filter($all_maintenance, function($m) { 
                            return $m['schedule_status'] === 'overdue'; 
                        })); ?>
                    </div>
                    <small class="text-muted">Overdue</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="border rounded p-3">
                    <div class="h3 text-success mb-1">
                        <?php echo count(array_filter($all_maintenance, function($m) { 
                            return $m['schedule_status'] === 'today'; 
                        })); ?>
                    </div>
                    <small class="text-muted">Today</small>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Maintenance Details Modal -->
<div class="modal fade" id="maintenanceDetailsModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Maintenance Details</h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span class="text-white">&times;</span>
                </button>
            </div>
            <div class="modal-body" id="maintenanceDetailsContent">
                <!-- Content loaded via AJAX -->
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize tooltips
    $('[data-toggle="tooltip"]').tooltip();
    
    // Confirm action links
    $('.confirm-action').click(function(e) {
        if (!confirm($(this).data('message'))) {
            e.preventDefault();
        }
    });
});

function updateCalendarFilter() {
    const equipmentId = document.getElementById('equipmentFilter').value;
    const priority = document.getElementById('priorityFilter').value;
    const status = document.getElementById('statusFilter').value;
    
    let url = '?view=<?php echo $view; ?>';
    
    <?php if ($view === 'month'): ?>
        url += '&year=<?php echo $year; ?>&month=<?php echo $month; ?>';
    <?php elseif ($view === 'week'): ?>
        url += '&week_start=<?php echo $week_start; ?>';
    <?php elseif ($view === 'day'): ?>
        url += '&day_date=<?php echo $day_date; ?>';
    <?php endif; ?>
    
    if (equipmentId) url += '&equipment_id=' + equipmentId;
    if (priority) url += '&priority=' + priority;
    if (status) url += '&status=' + status;
    
    window.location.href = url;
}

function showMaintenanceDetails(maintenanceId) {
    // Load maintenance details via AJAX
    $.get('ajax/maintenance_details.php?id=' + maintenanceId, function(data) {
        $('#maintenanceDetailsContent').html(data);
        $('#maintenanceDetailsModal').modal('show');
    });
}

// Keyboard navigation
$(document).keydown(function(e) {
    // Escape key - close modal
    if (e.keyCode === 27) {
        $('#maintenanceDetailsModal').modal('hide');
    }
    
    <?php if ($view === 'month'): ?>
        // Left arrow - previous month
        if (e.keyCode === 37 && !e.ctrlKey) {
            e.preventDefault();
            window.location.href = '?view=month&year=<?php echo $prev_year; ?>&month=<?php echo $prev_month; ?><?php echo $equipment_filter ? "&equipment_id=$equipment_filter" : ""; ?><?php echo $priority_filter ? "&priority=$priority_filter" : ""; ?><?php echo $status_filter ? "&status=$status_filter" : ""; ?>';
        }
        // Right arrow - next month
        if (e.keyCode === 39 && !e.ctrlKey) {
            e.preventDefault();
            window.location.href = '?view=month&year=<?php echo $next_year; ?>&month=<?php echo $next_month; ?><?php echo $equipment_filter ? "&equipment_id=$equipment_filter" : ""; ?><?php echo $priority_filter ? "&priority=$priority_filter" : ""; ?><?php echo $status_filter ? "&status=$status_filter" : ""; ?>';
        }
    <?php endif; ?>
});
</script>

<style>
.calendar-day {
    min-height: 150px;
    background-color: #fff;
    transition: background-color 0.2s ease;
}
.calendar-day.weekend {
    background-color: #f8f9fa;
}
.calendar-day.empty-day {
    background-color: #f8f9fa;
}
.calendar-day.past-day {
    background-color: #f8f9fa;
    opacity: 0.7;
}
.day-number.today {
    background-color: #007bff;
    color: white;
    border-radius: 50%;
    width: 30px;
    height: 30px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
}
.maintenance-event {
    cursor: pointer;
    transition: all 0.2s ease;
    font-size: 0.75rem;
    border-left: 4px solid;
}
.maintenance-event:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
}
.truncate {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.calendar-week {
    min-height: 160px;
}
.week-day {
    border-right: 1px solid #dee2e6;
}
.week-day:last-child {
    border-right: none;
}
.timeline {
    position: relative;
    padding-left: 30px;
}
.timeline-item {
    position: relative;
}
.timeline-marker {
    position: absolute;
    left: -30px;
    top: 15px;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    border: 3px solid;
}
.timeline-content {
    margin-left: 10px;
}
.badge-sm {
    font-size: 0.65rem;
    padding: 0.2rem 0.4rem;
}
</style>

<?php
// Helper functions for styling
function getPriorityClass($priority) {
    switch($priority) {
        case 'critical': return 'border-left-danger bg-danger-light';
        case 'high': return 'border-left-warning bg-warning-light';
        case 'medium': return 'border-left-info bg-info-light';
        case 'low': return 'border-left-secondary bg-secondary-light';
        default: return 'border-left-light bg-light';
    }
}

function getPriorityBadgeClass($priority) {
    switch($priority) {
        case 'critical': return 'badge-danger';
        case 'high': return 'badge-warning';
        case 'medium': return 'badge-info';
        case 'low': return 'badge-secondary';
        default: return 'badge-light';
    }
}

function getStatusClass($status) {
    switch($status) {
        case 'overdue': return 'text-danger';
        case 'today': return 'text-warning';
        case 'upcoming': return 'text-info';
        default: return 'text-muted';
    }
}
?>

<?php 
     require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';

    ?>
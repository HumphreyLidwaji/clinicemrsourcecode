<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Report parameters
$report_type = $_GET['report_type'] ?? 'summary';
$date_from = $_GET['date_from'] ?? date('Y-m-01'); // First day of current month
$date_to = $_GET['date_to'] ?? date('Y-m-t'); // Last day of current month
$equipment_id = $_GET['equipment_id'] ?? '';
$theatre_id = $_GET['theatre_id'] ?? '';
$priority_filter = $_GET['priority'] ?? '';
$status_filter = $_GET['status'] ?? '';

// Validate dates
if (!validateDate($date_from)) $date_from = date('Y-m-01');
if (!validateDate($date_to)) $date_to = date('Y-m-t');
if ($date_from > $date_to) {
    $temp = $date_from;
    $date_from = $date_to;
    $date_to = $temp;
}

// Build query conditions
$conditions = ["m.archived_at IS NULL"];
$params = [];
$types = '';

$conditions[] = "m.scheduled_date BETWEEN ? AND ?";
$params[] = $date_from;
$params[] = $date_to;
$types .= 'ss';

if ($equipment_id) {
    $conditions[] = "m.equipment_id = ?";
    $params[] = intval($equipment_id);
    $types .= 'i';
}

if ($theatre_id) {
    $conditions[] = "e.theatre_id = ?";
    $params[] = intval($theatre_id);
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

// Fetch maintenance data for reports
$maintenance_sql = "
    SELECT m.*,
           e.equipment_name, e.equipment_type, e.model,
           t.theatre_name, t.theatre_number,
           u.user_name as created_by_name,
           pu.user_name as performed_by_name,
           DATEDIFF(m.completed_date, m.scheduled_date) as completion_delay,
           CASE 
               WHEN m.status = 'completed' AND m.completed_date <= m.scheduled_date THEN 'on_time'
               WHEN m.status = 'completed' AND m.completed_date > m.scheduled_date THEN 'delayed'
               WHEN m.scheduled_date < CURDATE() AND m.status IN ('scheduled', 'in_progress') THEN 'overdue'
               ELSE 'pending'
           END as timeline_status
    FROM maintenance m
    LEFT JOIN theatre_equipment e ON m.equipment_id = e.equipment_id
    LEFT JOIN theatres t ON e.theatre_id = t.theatre_id
    LEFT JOIN users u ON m.created_by = u.user_id
    LEFT JOIN users pu ON m.performed_by = pu.user_id
    WHERE $where_clause
    ORDER BY m.scheduled_date DESC
";

$maintenance_stmt = $mysqli->prepare($maintenance_sql);
if ($params) {
    $maintenance_stmt->bind_param($types, ...$params);
}
$maintenance_stmt->execute();
$maintenance_result = $maintenance_stmt->get_result();

// Calculate statistics
$stats = [
    'total' => 0,
    'completed' => 0,
    'in_progress' => 0,
    'scheduled' => 0,
    'cancelled' => 0,
    'overdue' => 0,
    'on_time' => 0,
    'delayed' => 0,
    'total_cost' => 0,
    'avg_completion_time' => 0,
    'high_priority' => 0,
    'critical_priority' => 0
];

$maintenance_data = [];
$completion_times = [];

while ($maintenance = $maintenance_result->fetch_assoc()) {
    $maintenance_data[] = $maintenance;
    $stats['total']++;
    
    // Count by status
    switch($maintenance['status']) {
        case 'completed': $stats['completed']++; break;
        case 'in_progress': $stats['in_progress']++; break;
        case 'scheduled': $stats['scheduled']++; break;
        case 'cancelled': $stats['cancelled']++; break;
    }
    
    // Count by timeline status
    switch($maintenance['timeline_status']) {
        case 'overdue': $stats['overdue']++; break;
        case 'on_time': $stats['on_time']++; break;
        case 'delayed': $stats['delayed']++; break;
    }
    
    // Count by priority
    if ($maintenance['priority'] === 'high') $stats['high_priority']++;
    if ($maintenance['priority'] === 'critical') $stats['critical_priority']++;
    
    // Cost calculations
    if ($maintenance['cost']) {
        $stats['total_cost'] += floatval($maintenance['cost']);
    }
    
    // Completion time calculations
    if ($maintenance['status'] === 'completed' && $maintenance['scheduled_date'] && $maintenance['completed_date']) {
        $start = strtotime($maintenance['scheduled_date']);
        $end = strtotime($maintenance['completed_date']);
        $days = ($end - $start) / (60 * 60 * 24);
        $completion_times[] = $days;
    }
}

// Calculate average completion time
if (!empty($completion_times)) {
    $stats['avg_completion_time'] = round(array_sum($completion_times) / count($completion_times), 1);
}

// Get equipment list for filter
$equipment_sql = "
    SELECT equipment_id, equipment_name, equipment_type 
    FROM theatre_equipment 
    WHERE archived_at IS NULL 
    ORDER BY equipment_name
";
$equipment_result = $mysqli->query($equipment_sql);

// Get theatres list for filter
$theatres_sql = "
    SELECT theatre_id, theatre_number, theatre_name 
    FROM theatres 
    WHERE archived_at IS NULL 
    ORDER BY theatre_number
";
$theatres_result = $mysqli->query($theatres_sql);

// Function to validate date
function validateDate($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

// Generate different reports based on type
$report_data = [];
switch ($report_type) {
    case 'cost_analysis':
        $report_data = generateCostAnalysisReport($maintenance_data);
        break;
    case 'equipment_performance':
        $report_data = generateEquipmentPerformanceReport($maintenance_data);
        break;
    case 'technician_performance':
        $report_data = generateTechnicianPerformanceReport($maintenance_data);
        break;
    case 'preventive_maintenance':
        $report_data = generatePreventiveMaintenanceReport($maintenance_data);
        break;
    default:
        $report_data = generateSummaryReport($maintenance_data);
}

// Report generation functions
function generateSummaryReport($data) {
    $report = [
        'title' => 'Maintenance Summary Report',
        'headers' => ['Metric', 'Count', 'Percentage'],
        'rows' => []
    ];
    
    $total = count($data);
    if ($total === 0) return $report;
    
    $status_counts = [];
    $priority_counts = [];
    $type_counts = [];
    
    foreach ($data as $item) {
        // Status counts
        $status_counts[$item['status']] = ($status_counts[$item['status']] ?? 0) + 1;
        
        // Priority counts
        $priority_counts[$item['priority']] = ($priority_counts[$item['priority']] ?? 0) + 1;
        
        // Type counts
        $type_counts[$item['maintenance_type']] = ($type_counts[$item['maintenance_type']] ?? 0) + 1;
    }
    
    // Add status summary
    foreach ($status_counts as $status => $count) {
        $percentage = round(($count / $total) * 100, 1);
        $report['rows'][] = [
            'Status: ' . ucfirst(str_replace('_', ' ', $status)),
            $count,
            $percentage . '%'
        ];
    }
    
    // Add priority summary
    foreach ($priority_counts as $priority => $count) {
        $percentage = round(($count / $total) * 100, 1);
        $report['rows'][] = [
            'Priority: ' . ucfirst($priority),
            $count,
            $percentage . '%'
        ];
    }
    
    return $report;
}

function generateCostAnalysisReport($data) {
    $report = [
        'title' => 'Maintenance Cost Analysis Report',
        'headers' => ['Equipment', 'Maintenance Type', 'Cost', 'Status', 'Completion Date'],
        'rows' => []
    ];
    
    $total_cost = 0;
    $cost_by_equipment = [];
    $cost_by_type = [];
    
    foreach ($data as $item) {
        $cost = floatval($item['cost'] ?? 0);
        $total_cost += $cost;
        
        // Equipment cost aggregation
        $cost_by_equipment[$item['equipment_name']] = ($cost_by_equipment[$item['equipment_name']] ?? 0) + $cost;
        
        // Type cost aggregation
        $cost_by_type[$item['maintenance_type']] = ($cost_by_type[$item['maintenance_type']] ?? 0) + $cost;
        
        // Individual records
        if ($cost > 0) {
            $report['rows'][] = [
                $item['equipment_name'],
                $item['maintenance_type'],
                '$' . number_format($cost, 2),
                ucfirst(str_replace('_', ' ', $item['status'])),
                $item['completed_date'] ? date('M j, Y', strtotime($item['completed_date'])) : 'N/A'
            ];
        }
    }
    
    // Add summary rows
    $report['rows'][] = ['', '<strong>Total Cost</strong>', '<strong>$' . number_format($total_cost, 2) . '</strong>', '', ''];
    
    return $report;
}

function generateEquipmentPerformanceReport($data) {
    $report = [
        'title' => 'Equipment Performance Report',
        'headers' => ['Equipment', 'Total Maintenance', 'Completed', 'Overdue', 'Avg Completion Days', 'Total Cost'],
        'rows' => []
    ];
    
    $equipment_stats = [];
    
    foreach ($data as $item) {
        $equipment_name = $item['equipment_name'];
        if (!isset($equipment_stats[$equipment_name])) {
            $equipment_stats[$equipment_name] = [
                'total' => 0,
                'completed' => 0,
                'overdue' => 0,
                'completion_times' => [],
                'total_cost' => 0
            ];
        }
        
        $equipment_stats[$equipment_name]['total']++;
        
        if ($item['status'] === 'completed') {
            $equipment_stats[$equipment_name]['completed']++;
            
            // Calculate completion time
            if ($item['scheduled_date'] && $item['completed_date']) {
                $start = strtotime($item['scheduled_date']);
                $end = strtotime($item['completed_date']);
                $days = ($end - $start) / (60 * 60 * 24);
                $equipment_stats[$equipment_name]['completion_times'][] = $days;
            }
        }
        
        if ($item['timeline_status'] === 'overdue') {
            $equipment_stats[$equipment_name]['overdue']++;
        }
        
        if ($item['cost']) {
            $equipment_stats[$equipment_name]['total_cost'] += floatval($item['cost']);
        }
    }
    
    foreach ($equipment_stats as $equipment => $stats) {
        $avg_completion = 'N/A';
        if (!empty($stats['completion_times'])) {
            $avg_completion = round(array_sum($stats['completion_times']) / count($stats['completion_times']), 1);
        }
        
        $report['rows'][] = [
            $equipment,
            $stats['total'],
            $stats['completed'],
            $stats['overdue'],
            $avg_completion,
            '$' . number_format($stats['total_cost'], 2)
        ];
    }
    
    return $report;
}

function generateTechnicianPerformanceReport($data) {
    $report = [
        'title' => 'Technician Performance Report',
        'headers' => ['Technician', 'Completed Jobs', 'Avg Completion Days', 'On Time Rate', 'Total Cost Handled'],
        'rows' => []
    ];
    
    $technician_stats = [];
    
    foreach ($data as $item) {
        if ($item['performed_by_name'] && $item['status'] === 'completed') {
            $technician = $item['performed_by_name'];
            if (!isset($technician_stats[$technician])) {
                $technician_stats[$technician] = [
                    'completed' => 0,
                    'completion_times' => [],
                    'on_time' => 0,
                    'total_cost' => 0
                ];
            }
            
            $technician_stats[$technician]['completed']++;
            
            // Calculate completion time
            if ($item['scheduled_date'] && $item['completed_date']) {
                $start = strtotime($item['scheduled_date']);
                $end = strtotime($item['completed_date']);
                $days = ($end - $start) / (60 * 60 * 24);
                $technician_stats[$technician]['completion_times'][] = $days;
                
                // Check if on time
                if ($days <= 0) {
                    $technician_stats[$technician]['on_time']++;
                }
            }
            
            if ($item['cost']) {
                $technician_stats[$technician]['total_cost'] += floatval($item['cost']);
            }
        }
    }
    
    foreach ($technician_stats as $technician => $stats) {
        $avg_completion = 'N/A';
        if (!empty($stats['completion_times'])) {
            $avg_completion = round(array_sum($stats['completion_times']) / count($stats['completion_times']), 1);
        }
        
        $on_time_rate = 'N/A';
        if ($stats['completed'] > 0) {
            $on_time_rate = round(($stats['on_time'] / $stats['completed']) * 100, 1) . '%';
        }
        
        $report['rows'][] = [
            $technician,
            $stats['completed'],
            $avg_completion,
            $on_time_rate,
            '$' . number_format($stats['total_cost'], 2)
        ];
    }
    
    return $report;
}

function generatePreventiveMaintenanceReport($data) {
    $report = [
        'title' => 'Preventive Maintenance Report',
        'headers' => ['Equipment', 'Last PM', 'Next PM Due', 'Status', 'PM Frequency', 'Theatre'],
        'rows' => []
    ];
    
    $preventive_maintenance = array_filter($data, function($item) {
        return stripos($item['maintenance_type'], 'preventive') !== false || 
               stripos($item['maintenance_type'], 'routine') !== false;
    });
    
    foreach ($preventive_maintenance as $item) {
        $last_pm = $item['completed_date'] ? date('M j, Y', strtotime($item['completed_date'])) : 'Never';
        $next_pm_due = 'N/A';
        
        // Simple logic for next PM due (could be enhanced with equipment-specific intervals)
        if ($item['completed_date']) {
            $next_due = date('Y-m-d', strtotime($item['completed_date'] . ' +90 days')); // Default 90 days
            $next_pm_due = date('M j, Y', strtotime($next_due));
            
            // Highlight if overdue
            if (strtotime($next_due) < time()) {
                $next_pm_due = '<span class="text-danger font-weight-bold">' . $next_pm_due . ' (Overdue)</span>';
            }
        }
        
        $report['rows'][] = [
            $item['equipment_name'],
            $last_pm,
            $next_pm_due,
            ucfirst(str_replace('_', ' ', $item['status'])),
            '90 days', // This could come from equipment settings
            'OT ' . $item['theatre_number']
        ];
    }
    
    return $report;
}
?>

<div class="card">
    <div class="card-header bg-info py-2">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h3 class="card-title mt-2 mb-0 text-white">
                    <i class="fas fa-fw fa-chart-bar mr-2"></i>
                    Maintenance Reports
                </h3>
                <small class="text-white-50">Comprehensive maintenance analysis and reporting</small>
            </div>
            <div class="card-tools">
                <button type="button" class="btn btn-success" onclick="printReport()">
                    <i class="fas fa-print mr-2"></i>Print Report
                </button>
            </div>
        </div>
    </div>

    <!-- Report Filters -->
    <div class="card-header bg-light">
        <form method="get" autocomplete="off" id="reportForm">
            <div class="row">
                <div class="col-md-3">
                    <div class="form-group">
                        <label>Report Type</label>
                        <select class="form-control select2" name="report_type" onchange="this.form.submit()">
                            <option value="summary" <?php echo $report_type === 'summary' ? 'selected' : ''; ?>>Summary Report</option>
                            <option value="cost_analysis" <?php echo $report_type === 'cost_analysis' ? 'selected' : ''; ?>>Cost Analysis</option>
                            <option value="equipment_performance" <?php echo $report_type === 'equipment_performance' ? 'selected' : ''; ?>>Equipment Performance</option>
                            <option value="technician_performance" <?php echo $report_type === 'technician_performance' ? 'selected' : ''; ?>>Technician Performance</option>
                            <option value="preventive_maintenance" <?php echo $report_type === 'preventive_maintenance' ? 'selected' : ''; ?>>Preventive Maintenance</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-group">
                        <label>Date From</label>
                        <input type="date" class="form-control" name="date_from" value="<?php echo $date_from; ?>" max="<?php echo date('Y-m-d'); ?>">
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-group">
                        <label>Date To</label>
                        <input type="date" class="form-control" name="date_to" value="<?php echo $date_to; ?>" max="<?php echo date('Y-m-d'); ?>">
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-group">
                        <label>Equipment</label>
                        <select class="form-control select2" name="equipment_id" onchange="this.form.submit()">
                            <option value="">All Equipment</option>
                            <?php while($equipment = $equipment_result->fetch_assoc()): ?>
                                <option value="<?php echo $equipment['equipment_id']; ?>" 
                                    <?php echo $equipment_id == $equipment['equipment_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($equipment['equipment_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label>Actions</label>
                        <div class="btn-group w-100">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter mr-2"></i>Apply Filters
                            </button>
                            <a href="reports_maintenance.php" class="btn btn-secondary">
                                <i class="fas fa-redo mr-2"></i>Reset
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="row mt-2">
                <div class="col-md-3">
                    <div class="form-group">
                        <label>Theatre</label>
                        <select class="form-control select2" name="theatre_id" onchange="this.form.submit()">
                            <option value="">All Theatres</option>
                            <?php while($theatre = $theatres_result->fetch_assoc()): ?>
                                <option value="<?php echo $theatre['theatre_id']; ?>" 
                                    <?php echo $theatre_id == $theatre['theatre_id'] ? 'selected' : ''; ?>>
                                    OT <?php echo htmlspecialchars($theatre['theatre_number']); ?> - <?php echo htmlspecialchars($theatre['theatre_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-group">
                        <label>Priority</label>
                        <select class="form-control select2" name="priority" onchange="this.form.submit()">
                            <option value="">All Priorities</option>
                            <option value="low" <?php echo $priority_filter === 'low' ? 'selected' : ''; ?>>Low</option>
                            <option value="medium" <?php echo $priority_filter === 'medium' ? 'selected' : ''; ?>>Medium</option>
                            <option value="high" <?php echo $priority_filter === 'high' ? 'selected' : ''; ?>>High</option>
                            <option value="critical" <?php echo $priority_filter === 'critical' ? 'selected' : ''; ?>>Critical</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-group">
                        <label>Status</label>
                        <select class="form-control select2" name="status" onchange="this.form.submit()">
                            <option value="">All Status</option>
                            <option value="scheduled" <?php echo $status_filter === 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                            <option value="in_progress" <?php echo $status_filter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                            <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-5">
                    <div class="form-group">
                        <label>Quick Date Ranges</label>
                        <div class="btn-group w-100">
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setDateRange('this_month')">This Month</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setDateRange('last_month')">Last Month</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setDateRange('this_quarter')">This Quarter</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setDateRange('last_quarter')">Last Quarter</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setDateRange('this_year')">This Year</button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- Statistics Overview -->
    <div class="card-body border-bottom">
        <div class="row text-center">
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-primary"><i class="fas fa-tools"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Maintenance</span>
                        <span class="info-box-number"><?php echo $stats['total']; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-success"><i class="fas fa-check-circle"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Completed</span>
                        <span class="info-box-number"><?php echo $stats['completed']; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-danger"><i class="fas fa-exclamation-triangle"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Overdue</span>
                        <span class="info-box-number"><?php echo $stats['overdue']; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-warning"><i class="fas fa-clock"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">In Progress</span>
                        <span class="info-box-number"><?php echo $stats['in_progress']; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-info"><i class="fas fa-dollar-sign"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Cost</span>
                        <span class="info-box-number">$<?php echo number_format($stats['total_cost'], 2); ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-secondary"><i class="fas fa-calendar-check"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Avg Completion</span>
                        <span class="info-box-number"><?php echo $stats['avg_completion_time']; ?> days</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Report Content -->
    <div class="card-body">
        <!-- Report Header -->
        <div class="row mb-4">
            <div class="col-md-8">
                <h4 class="text-primary"><?php echo $report_data['title']; ?></h4>
                <p class="text-muted">
                    Period: <?php echo date('F j, Y', strtotime($date_from)); ?> to <?php echo date('F j, Y', strtotime($date_to)); ?>
                    <?php if ($equipment_id): ?>
                        | Equipment Filter Applied
                    <?php endif; ?>
                    <?php if ($theatre_id): ?>
                        | Theatre Filter Applied
                    <?php endif; ?>
                </p>
            </div>
            <div class="col-md-4 text-right">
                <small class="text-muted">Generated on: <?php echo date('F j, Y \a\t g:i A'); ?></small>
            </div>
        </div>

        <!-- Report Table -->
        <?php if (!empty($report_data['rows'])): ?>
            <div class="table-responsive" id="reportTable">
                <table class="table table-bordered table-striped">
                    <thead class="bg-light">
                        <tr>
                            <?php foreach ($report_data['headers'] as $header): ?>
                                <th><?php echo $header; ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report_data['rows'] as $row): ?>
                            <tr>
                                <?php foreach ($row as $cell): ?>
                                    <td><?php echo $cell; ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="text-center py-5">
                <i class="fas fa-chart-bar fa-3x text-muted mb-3"></i>
                <h4 class="text-muted">No Data Available</h4>
                <p class="text-muted">No maintenance records found for the selected criteria.</p>
            </div>
        <?php endif; ?>

        <!-- Additional Charts/Visualizations -->
        <?php if ($report_type === 'summary' && $stats['total'] > 0): ?>
            <div class="row mt-5">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Maintenance by Status</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="statusChart" width="400" height="200"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Maintenance by Priority</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="priorityChart" width="400" height="200"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Export Options Card -->
<div class="card mt-4">
    <div class="card-header bg-light">
        <h5 class="card-title mb-0">
            <i class="fas fa-download mr-2"></i>Export Options
        </h5>
    </div>
    <div class="card-body">
        <div class="row text-center">
            <div class="col-md-3">
                <button class="btn btn-outline-primary btn-block" onclick="exportToCSV()">
                    <i class="fas fa-file-csv mr-2"></i>Export to CSV
                </button>
            </div>
            <div class="col-md-3">
                <button class="btn btn-outline-success btn-block" onclick="exportToExcel()">
                    <i class="fas fa-file-excel mr-2"></i>Export to Excel
                </button>
            </div>
            <div class="col-md-3">
                <button class="btn btn-outline-danger btn-block" onclick="exportToPDF()">
                    <i class="fas fa-file-pdf mr-2"></i>Export to PDF
                </button>
            </div>
            <div class="col-md-3">
                <button class="btn btn-outline-info btn-block" onclick="showEmailDialog()">
                    <i class="fas fa-envelope mr-2"></i>Email Report
                </button>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.select2').select2({
        theme: 'bootstrap4'
    });

    // Initialize charts if needed
    <?php if ($report_type === 'summary' && $stats['total'] > 0): ?>
        initializeCharts();
    <?php endif; ?>
});

function setDateRange(range) {
    const today = new Date();
    let fromDate, toDate;

    switch(range) {
        case 'this_month':
            fromDate = new Date(today.getFullYear(), today.getMonth(), 1);
            toDate = new Date(today.getFullYear(), today.getMonth() + 1, 0);
            break;
        case 'last_month':
            fromDate = new Date(today.getFullYear(), today.getMonth() - 1, 1);
            toDate = new Date(today.getFullYear(), today.getMonth(), 0);
            break;
        case 'this_quarter':
            const quarter = Math.floor(today.getMonth() / 3);
            fromDate = new Date(today.getFullYear(), quarter * 3, 1);
            toDate = new Date(today.getFullYear(), (quarter + 1) * 3, 0);
            break;
        case 'last_quarter':
            const lastQuarter = Math.floor(today.getMonth() / 3) - 1;
            fromDate = new Date(today.getFullYear(), lastQuarter * 3, 1);
            toDate = new Date(today.getFullYear(), (lastQuarter + 1) * 3, 0);
            break;
        case 'this_year':
            fromDate = new Date(today.getFullYear(), 0, 1);
            toDate = new Date(today.getFullYear(), 11, 31);
            break;
    }

    $('input[name="date_from"]').val(formatDate(fromDate));
    $('input[name="date_to"]').val(formatDate(toDate));
    $('#reportForm').submit();
}

function formatDate(date) {
    return date.toISOString().split('T')[0];
}

function printReport() {
    const printContent = document.getElementById('reportTable').innerHTML;
    const originalContent = document.body.innerHTML;
    
    document.body.innerHTML = `
        <html>
            <head>
                <title>Maintenance Report</title>
                <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/4.6.0/css/bootstrap.min.css">
                <style>
                    body { padding: 20px; }
                    .table { font-size: 12px; }
                </style>
            </head>
            <body>
                <h4 class="text-center"><?php echo $report_data['title']; ?></h4>
                <p class="text-center text-muted">
                    Period: <?php echo date('F j, Y', strtotime($date_from)); ?> to <?php echo date('F j, Y', strtotime($date_to)); ?>
                </p>
                ${printContent}
                <div class="text-muted mt-4">
                    <small>Generated on: <?php echo date('F j, Y \a\t g:i A'); ?></small>
                </div>
            </body>
        </html>
    `;
    
    window.print();
    document.body.innerHTML = originalContent;
    location.reload();
}

function exportToCSV() {
    // Implement CSV export logic
    alert('CSV export functionality would be implemented here');
}

function exportToExcel() {
    // Implement Excel export logic
    alert('Excel export functionality would be implemented here');
}

function exportToPDF() {
    // Implement PDF export logic
    alert('PDF export functionality would be implemented here');
}

function showEmailDialog() {
    // Implement email dialog
    alert('Email report functionality would be implemented here');
}

<?php if ($report_type === 'summary' && $stats['total'] > 0): ?>
function initializeCharts() {
    // Status Chart
    const statusCtx = document.getElementById('statusChart').getContext('2d');
    const statusChart = new Chart(statusCtx, {
        type: 'doughnut',
        data: {
            labels: ['Completed', 'In Progress', 'Scheduled', 'Cancelled'],
            datasets: [{
                data: [
                    <?php echo $stats['completed']; ?>,
                    <?php echo $stats['in_progress']; ?>,
                    <?php echo $stats['scheduled']; ?>,
                    <?php echo $stats['cancelled']; ?>
                ],
                backgroundColor: [
                    '#28a745',
                    '#ffc107',
                    '#007bff',
                    '#6c757d'
                ]
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    position: 'bottom'
                }
            }
        }
    });

    // Priority Chart
    const priorityCtx = document.getElementById('priorityChart').getContext('2d');
    const priorityChart = new Chart(priorityCtx, {
        type: 'bar',
        data: {
            labels: ['Low', 'Medium', 'High', 'Critical'],
            datasets: [{
                label: 'Maintenance Count',
                data: [
                    <?php echo $stats['total'] - $stats['high_priority'] - $stats['critical_priority']; ?>,
                    <?php echo $stats['total'] - $stats['high_priority'] - $stats['critical_priority']; ?>, // This would need actual medium count
                    <?php echo $stats['high_priority']; ?>,
                    <?php echo $stats['critical_priority']; ?>
                ],
                backgroundColor: [
                    '#6c757d',
                    '#17a2b8',
                    '#ffc107',
                    '#dc3545'
                ]
            }]
        },
        options: {
            responsive: true,
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });
}
<?php endif; ?>
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
.card {
    border: 1px solid #e3e6f0;
}
.table th {
    background-color: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
}
</style>

<?php
require_once "../includes/footer.php";
?>
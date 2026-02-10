<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
   require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
    

// Report parameters
$report_type = $_GET['report_type'] ?? 'utilization_summary';
$date_from = $_GET['date_from'] ?? date('Y-m-01'); // First day of current month
$date_to = $_GET['date_to'] ?? date('Y-m-t'); // Last day of current month
$theatre_id = $_GET['theatre_id'] ?? '';
$surgery_type = $_GET['surgery_type'] ?? '';
$surgeon_id = $_GET['surgeon_id'] ?? '';

// Validate dates
if (!validateDate($date_from)) $date_from = date('Y-m-01');
if (!validateDate($date_to)) $date_to = date('Y-m-t');
if ($date_from > $date_to) {
    $temp = $date_from;
    $date_from = $date_to;
    $date_to = $temp;
}

// Build query conditions for surgeries
$conditions = ["s.archived_at IS NULL"];
$params = [];
$types = '';

$conditions[] = "s.scheduled_date BETWEEN ? AND ?";
$params[] = $date_from;
$params[] = $date_to;
$types .= 'ss';

if ($theatre_id) {
    $conditions[] = "s.theatre_id = ?";
    $params[] = intval($theatre_id);
    $types .= 'i';
}

if ($surgery_type) {
    $conditions[] = "st.type_name = ?";
    $params[] = sanitizeInput($surgery_type);
    $types .= 's';
}

if ($surgeon_id) {
    $conditions[] = "s.primary_surgeon_id = ?";
    $params[] = intval($surgeon_id);
    $types .= 'i';
}

$where_clause = implode(' AND ', $conditions);

// Fetch surgery data for reports
$surgery_sql = "
    SELECT s.*,
           p.patient_first_name, p.patient_last_name, p.patient_mrn, p.patient_gender, p.patient_dob,
           t.theatre_name, t.theatre_number, t.capacity,
           st.type_name as surgery_type, st.complexity,
           CONCAT(sur.first_name, ' ', sur.last_name) as surgeon_name, sur.specialization,
           TIMESTAMPDIFF(MINUTE, s.actual_start_time, s.actual_end_time) as actual_duration_minutes,
           TIMESTAMPDIFF(MINUTE, s.scheduled_time, s.actual_start_time) as start_delay_minutes,
           CASE 
               WHEN s.status = 'completed' THEN 'completed'
               WHEN s.status = 'cancelled' THEN 'cancelled'
               WHEN s.scheduled_date < CURDATE() AND s.status IN ('scheduled', 'confirmed') THEN 'missed'
               ELSE 'scheduled'
           END as timeline_status
    FROM surgeries s
    LEFT JOIN patients p ON s.patient_id = p.patient_id
    LEFT JOIN theatres t ON s.theatre_id = t.theatre_id
    LEFT JOIN surgery_types st ON s.surgery_type_id = st.type_id
    LEFT JOIN surgeons sur ON s.primary_surgeon_id = sur.surgeon_id
    WHERE $where_clause
    ORDER BY s.scheduled_date, s.scheduled_time
";

$surgery_stmt = $mysqli->prepare($surgery_sql);
if ($params) {
    $surgery_stmt->bind_param($types, ...$params);
}
$surgery_stmt->execute();
$surgery_result = $surgery_stmt->get_result();

// Calculate utilization statistics
$stats = [
    'total_surgeries' => 0,
    'completed_surgeries' => 0,
    'cancelled_surgeries' => 0,
    'scheduled_surgeries' => 0,
    'total_duration' => 0,
    'avg_duration' => 0,
    'total_delay' => 0,
    'avg_delay' => 0,
    'utilization_rate' => 0,
    'peak_usage_day' => '',
    'peak_usage_count' => 0,
    'most_common_surgery' => '',
    'most_common_count' => 0
];

$surgery_data = [];
$daily_stats = [];
$theatre_stats = [];
$surgery_type_stats = [];
$surgeon_stats = [];

// Define operating hours (8 AM to 6 PM = 10 hours = 600 minutes)
$operating_hours_per_day = 600;
$total_days = (strtotime($date_to) - strtotime($date_from)) / (60 * 60 * 24) + 1;

while ($surgery = $surgery_result->fetch_assoc()) {
    $surgery_data[] = $surgery;
    $stats['total_surgeries']++;
    
    // Count by status
    switch($surgery['status']) {
        case 'completed': $stats['completed_surgeries']++; break;
        case 'cancelled': $stats['cancelled_surgeries']++; break;
        case 'scheduled': 
        case 'confirmed': 
        case 'in_progress': 
            $stats['scheduled_surgeries']++; break;
    }
    
    // Duration calculations
    $duration = intval($surgery['actual_duration_minutes']) ?: intval($surgery['estimated_duration_minutes']);
    if ($duration > 0) {
        $stats['total_duration'] += $duration;
    }
    
    // Delay calculations
    $delay = intval($surgery['start_delay_minutes']) ?: 0;
    if ($delay > 0) {
        $stats['total_delay'] += $delay;
    }
    
    // Daily statistics
    $date = $surgery['scheduled_date'];
    if (!isset($daily_stats[$date])) {
        $daily_stats[$date] = [
            'surgeries' => 0,
            'duration' => 0,
            'cancelled' => 0
        ];
    }
    $daily_stats[$date]['surgeries']++;
    $daily_stats[$date]['duration'] += $duration;
    if ($surgery['status'] === 'cancelled') {
        $daily_stats[$date]['cancelled']++;
    }
    
    // Theatre statistics
    $theatre_name = 'OT ' . $surgery['theatre_number'];
    if (!isset($theatre_stats[$theatre_name])) {
        $theatre_stats[$theatre_name] = [
            'surgeries' => 0,
            'duration' => 0,
            'cancelled' => 0
        ];
    }
    $theatre_stats[$theatre_name]['surgeries']++;
    $theatre_stats[$theatre_name]['duration'] += $duration;
    if ($surgery['status'] === 'cancelled') {
        $theatre_stats[$theatre_name]['cancelled']++;
    }
    
    // Surgery type statistics
    $surgery_type = $surgery['surgery_type'];
    if (!isset($surgery_type_stats[$surgery_type])) {
        $surgery_type_stats[$surgery_type] = [
            'count' => 0,
            'total_duration' => 0,
            'avg_duration' => 0
        ];
    }
    $surgery_type_stats[$surgery_type]['count']++;
    $surgery_type_stats[$surgery_type]['total_duration'] += $duration;
    
    // Surgeon statistics
    $surgeon = $surgery['surgeon_name'];
    if ($surgeon) {
        if (!isset($surgeon_stats[$surgeon])) {
            $surgeon_stats[$surgeon] = [
                'surgeries' => 0,
                'total_duration' => 0,
                'specialization' => $surgery['specialization']
            ];
        }
        $surgeon_stats[$surgeon]['surgeries']++;
        $surgeon_stats[$surgeon]['total_duration'] += $duration;
    }
}

// Calculate averages
if ($stats['completed_surgeries'] > 0) {
    $stats['avg_duration'] = round($stats['total_duration'] / $stats['completed_surgeries'], 1);
    $stats['avg_delay'] = round($stats['total_delay'] / $stats['completed_surgeries'], 1);
}

// Calculate utilization rate
$total_available_minutes = count($theatre_stats) * $total_days * $operating_hours_per_day;
if ($total_available_minutes > 0) {
    $stats['utilization_rate'] = round(($stats['total_duration'] / $total_available_minutes) * 100, 1);
}

// Find peak usage day
if (!empty($daily_stats)) {
    $peak_day = null;
    $max_surgeries = -1;

    foreach ($daily_stats as $day => $data) {
        if (!empty($data['surgeries']) && $data['surgeries'] > $max_surgeries) {
            $peak_day = $day;
            $max_surgeries = $data['surgeries'];
        }
    }

    if ($peak_day !== null) {
        $stats['peak_usage_day'] = date('M j, Y', strtotime($peak_day));
        $stats['peak_usage_count'] = $max_surgeries;
    }
}

// Find most common surgery type
if (!empty($surgery_type_stats)) {
    $most_common = null;
    $max_count = -1;

    foreach ($surgery_type_stats as $type => $data) {
        if (!empty($data['count']) && $data['count'] > $max_count) {
            $most_common = $type;
            $max_count = $data['count'];
        }
    }

    if ($most_common !== null) {
        $stats['most_common_surgery'] = $most_common;
        $stats['most_common_count'] = $max_count;
    }
}

// Calculate average duration for each surgery type
foreach ($surgery_type_stats as $type => &$data) {
    if ($data['count'] > 0) {
        $data['avg_duration'] = round($data['total_duration'] / $data['count'], 1);
    }
}

// Get theatres list for filter
$theatres_sql = "
    SELECT theatre_id, theatre_number, theatre_name 
    FROM theatres 
    WHERE archived_at IS NULL 
    ORDER BY theatre_number
";
$theatres_result = $mysqli->query($theatres_sql);

// Get surgery types for filter
$surgery_types_sql = "
    SELECT DISTINCT type_name 
    FROM surgery_types 
    WHERE archived_at IS NULL 
    ORDER BY type_name
";
$surgery_types_result = $mysqli->query($surgery_types_sql);

// Get surgeons for filter
$surgeons_sql = "
    SELECT surgeon_id, first_name, last_name 
    FROM surgeons 
    WHERE archived_at IS NULL 
    AND is_active = 1
    ORDER BY first_name, last_name
";
$surgeons_result = $mysqli->query($surgeons_sql);

// Function to validate date
function validateDate($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

// Generate different reports based on type
$report_data = [];
switch ($report_type) {
    case 'theatre_performance':
        $report_data = generateTheatrePerformanceReport($theatre_stats, $stats);
        break;
    case 'surgery_type_analysis':
        $report_data = generateSurgeryTypeAnalysisReport($surgery_type_stats);
        break;
    case 'surgeon_performance':
        $report_data = generateSurgeonPerformanceReport($surgeon_stats);
        break;
    case 'daily_utilization':
        $report_data = generateDailyUtilizationReport($daily_stats);
        break;
    default:
        $report_data = generateUtilizationSummaryReport($stats, $theatre_stats, $surgery_type_stats);
}

// Report generation functions
function generateUtilizationSummaryReport($stats, $theatre_stats, $surgery_type_stats) {
    $report = [
        'title' => 'OT Utilization Summary Report',
        'headers' => ['Metric', 'Value', 'Details'],
        'rows' => []
    ];
    
    $report['rows'][] = ['Total Surgeries', $stats['total_surgeries'], 'Across all operation theatres'];
    $report['rows'][] = ['Completed Surgeries', $stats['completed_surgeries'], round(($stats['completed_surgeries'] / $stats['total_surgeries']) * 100, 1) . '% completion rate'];
    $report['rows'][] = ['Cancelled Surgeries', $stats['cancelled_surgeries'], round(($stats['cancelled_surgeries'] / $stats['total_surgeries']) * 100, 1) . '% cancellation rate'];
    $report['rows'][] = ['Overall Utilization Rate', $stats['utilization_rate'] . '%', 'Based on available operating hours'];
    $report['rows'][] = ['Average Surgery Duration', $stats['avg_duration'] . ' minutes', 'Across all completed surgeries'];
    $report['rows'][] = ['Average Start Delay', $stats['avg_delay'] . ' minutes', 'For completed surgeries'];
    $report['rows'][] = ['Peak Usage Day', $stats['peak_usage_day'], $stats['peak_usage_count'] . ' surgeries performed'];
    $report['rows'][] = ['Most Common Surgery', $stats['most_common_surgery'], $stats['most_common_count'] . ' procedures'];
    
    return $report;
}

function generateTheatrePerformanceReport($theatre_stats, $overall_stats) {
    $report = [
        'title' => 'Theatre Performance Report',
        'headers' => ['Theatre', 'Total Surgeries', 'Completed', 'Cancelled', 'Total Hours', 'Avg Duration', 'Utilization Rate'],
        'rows' => []
    ];
    
    foreach ($theatre_stats as $theatre => $stats) {
        $completed = $stats['surgeries'] - $stats['cancelled'];
        $completion_rate = $stats['surgeries'] > 0 ? round(($completed / $stats['surgeries']) * 100, 1) : 0;
        $total_hours = round($stats['duration'] / 60, 1);
        $avg_duration = $completed > 0 ? round($stats['duration'] / $completed, 1) : 0;
        $utilization_rate = round(($stats['duration'] / (30 * 600)) * 100, 1); // Assuming 30 days month
        
        $report['rows'][] = [
            $theatre,
            $stats['surgeries'],
            $completed . ' (' . $completion_rate . '%)',
            $stats['cancelled'],
            $total_hours . ' hrs',
            $avg_duration . ' min',
            $utilization_rate . '%'
        ];
    }
    
    return $report;
}

function generateSurgeryTypeAnalysisReport($surgery_type_stats) {
    $report = [
        'title' => 'Surgery Type Analysis Report',
        'headers' => ['Surgery Type', 'Count', 'Percentage', 'Total Hours', 'Average Duration'],
        'rows' => []
    ];
    
    $total_surgeries = array_sum(array_column($surgery_type_stats, 'count'));
    
    foreach ($surgery_type_stats as $type => $stats) {
        $percentage = $total_surgeries > 0 ? round(($stats['count'] / $total_surgeries) * 100, 1) : 0;
        $total_hours = round($stats['total_duration'] / 60, 1);
        
        $report['rows'][] = [
            $type,
            $stats['count'],
            $percentage . '%',
            $total_hours . ' hrs',
            $stats['avg_duration'] . ' min'
        ];
    }
    
    return $report;
}

function generateSurgeonPerformanceReport($surgeon_stats) {
    $report = [
        'title' => 'Surgeon Performance Report',
        'headers' => ['Surgeon', 'Specialization', 'Total Surgeries', 'Total Hours', 'Average Duration'],
        'rows' => []
    ];
    
    foreach ($surgeon_stats as $surgeon => $stats) {
        $total_hours = round($stats['total_duration'] / 60, 1);
        $avg_duration = $stats['surgeries'] > 0 ? round($stats['total_duration'] / $stats['surgeries'], 1) : 0;
        
        $report['rows'][] = [
            $surgeon,
            $stats['specialization'] ?? 'N/A',
            $stats['surgeries'],
            $total_hours . ' hrs',
            $avg_duration . ' min'
        ];
    }
    
    return $report;
}

function generateDailyUtilizationReport($daily_stats) {
    $report = [
        'title' => 'Daily Utilization Report',
        'headers' => ['Date', 'Day', 'Surgeries', 'Completed', 'Cancelled', 'Total Hours', 'Utilization Rate'],
        'rows' => []
    ];
    
    ksort($daily_stats); // Sort by date
    
    foreach ($daily_stats as $date => $stats) {
        $day_name = date('D', strtotime($date));
        $total_hours = round($stats['duration'] / 60, 1);
        $completed = $stats['surgeries'] - $stats['cancelled'];
        $utilization_rate = round(($stats['duration'] / (count($stats) * 600)) * 100, 1); // Based on 10-hour day
        
        $report['rows'][] = [
            date('M j, Y', strtotime($date)),
            $day_name,
            $stats['surgeries'],
            $completed,
            $stats['cancelled'],
            $total_hours . ' hrs',
            $utilization_rate . '%'
        ];
    }
    
    return $report;
}
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h3 class="card-title mt-2 mb-0 text-white">
                    <i class="fas fa-fw fa-chart-line mr-2"></i>
                    OT Utilization Reports
                </h3>
                <small class="text-white-50">Operation Theatre performance analytics and insights</small>
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
                            <option value="utilization_summary" <?php echo $report_type === 'utilization_summary' ? 'selected' : ''; ?>>Utilization Summary</option>
                            <option value="theatre_performance" <?php echo $report_type === 'theatre_performance' ? 'selected' : ''; ?>>Theatre Performance</option>
                            <option value="surgery_type_analysis" <?php echo $report_type === 'surgery_type_analysis' ? 'selected' : ''; ?>>Surgery Type Analysis</option>
                            <option value="surgeon_performance" <?php echo $report_type === 'surgeon_performance' ? 'selected' : ''; ?>>Surgeon Performance</option>
                            <option value="daily_utilization" <?php echo $report_type === 'daily_utilization' ? 'selected' : ''; ?>>Daily Utilization</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-group">
                        <label>Date From</label>
                        <input type="date" class="form-control" name="date_from" value="<?php echo $date_from; ?>">
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-group">
                        <label>Date To</label>
                        <input type="date" class="form-control" name="date_to" value="<?php echo $date_to; ?>">
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-group">
                        <label>Theatre</label>
                        <select class="form-control select2" name="theatre_id" onchange="this.form.submit()">
                            <option value="">All Theatres</option>
                            <?php while($theatre = $theatres_result->fetch_assoc()): ?>
                                <option value="<?php echo $theatre['theatre_id']; ?>" 
                                    <?php echo $theatre_id == $theatre['theatre_id'] ? 'selected' : ''; ?>>
                                    OT <?php echo htmlspecialchars($theatre['theatre_number']); ?>
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
                            <a href="reports_ot_utilization.php" class="btn btn-secondary">
                                <i class="fas fa-redo mr-2"></i>Reset
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="row mt-2">
                <div class="col-md-3">
                    <div class="form-group">
                        <label>Surgery Type</label>
                        <select class="form-control select2" name="surgery_type" onchange="this.form.submit()">
                            <option value="">All Types</option>
                            <?php while($type = $surgery_types_result->fetch_assoc()): ?>
                                <option value="<?php echo $type['type_name']; ?>" 
                                    <?php echo $surgery_type == $type['type_name'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($type['type_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label>Surgeon</label>
                        <select class="form-control select2" name="surgeon_id" onchange="this.form.submit()">
                            <option value="">All Surgeons</option>
                            <?php while($surgeon = $surgeons_result->fetch_assoc()): ?>
                                <option value="<?php echo $surgeon['surgeon_id']; ?>" 
                                    <?php echo $surgeon_id == $surgeon['surgeon_id'] ? 'selected' : ''; ?>>
                                    Dr. <?php echo htmlspecialchars($surgeon['first_name'] . ' ' . $surgeon['last_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-6">
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
                    <span class="info-box-icon bg-primary"><i class="fas fa-procedures"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Surgeries</span>
                        <span class="info-box-number"><?php echo $stats['total_surgeries']; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-success"><i class="fas fa-check-circle"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Completed</span>
                        <span class="info-box-number"><?php echo $stats['completed_surgeries']; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-danger"><i class="fas fa-times-circle"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Cancelled</span>
                        <span class="info-box-number"><?php echo $stats['cancelled_surgeries']; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-info"><i class="fas fa-chart-pie"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Utilization Rate</span>
                        <span class="info-box-number"><?php echo $stats['utilization_rate']; ?>%</span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-warning"><i class="fas fa-clock"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Avg Duration</span>
                        <span class="info-box-number"><?php echo $stats['avg_duration']; ?>m</span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-secondary"><i class="fas fa-calendar-star"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Peak Day</span>
                        <span class="info-box-number"><?php echo $stats['peak_usage_count']; ?></span>
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
                    <?php if ($theatre_id): ?>
                        | Theatre Filter Applied
                    <?php endif; ?>
                    <?php if ($surgery_type): ?>
                        | Surgery Type: <?php echo htmlspecialchars($surgery_type); ?>
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
                <i class="fas fa-procedures fa-3x text-muted mb-3"></i>
                <h4 class="text-muted">No Data Available</h4>
                <p class="text-muted">No surgery records found for the selected criteria.</p>
            </div>
        <?php endif; ?>

        <!-- Additional Charts/Visualizations -->
        <?php if ($report_type === 'utilization_summary' && $stats['total_surgeries'] > 0): ?>
            <div class="row mt-5">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Surgery Distribution by Status</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="statusChart" width="400" height="200"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Theatre Utilization</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="theatreChart" width="400" height="200"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="row mt-4">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Daily Surgery Volume</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="dailyVolumeChart" width="400" height="100"></canvas>
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
    <?php if ($report_type === 'utilization_summary' && $stats['total_surgeries'] > 0): ?>
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
                <title>OT Utilization Report</title>
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

<?php if ($report_type === 'utilization_summary' && $stats['total_surgeries'] > 0): ?>
function initializeCharts() {
    // Status Distribution Chart
    const statusCtx = document.getElementById('statusChart').getContext('2d');
    const statusChart = new Chart(statusCtx, {
        type: 'doughnut',
        data: {
            labels: ['Completed', 'Scheduled', 'Cancelled'],
            datasets: [{
                data: [
                    <?php echo $stats['completed_surgeries']; ?>,
                    <?php echo $stats['scheduled_surgeries']; ?>,
                    <?php echo $stats['cancelled_surgeries']; ?>
                ],
                backgroundColor: [
                    '#28a745',
                    '#007bff',
                    '#dc3545'
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

    // Theatre Utilization Chart
    const theatreCtx = document.getElementById('theatreChart').getContext('2d');
    const theatreChart = new Chart(theatreCtx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode(array_keys($theatre_stats)); ?>,
            datasets: [{
                label: 'Number of Surgeries',
                data: <?php echo json_encode(array_column($theatre_stats, 'surgeries')); ?>,
                backgroundColor: '#007bff'
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

    // Daily Volume Chart
    const dailyCtx = document.getElementById('dailyVolumeChart').getContext('2d');
    const dailyChart = new Chart(dailyCtx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode(array_map(function($date) { return date('M j', strtotime($date)); }, array_keys($daily_stats))); ?>,
            datasets: [{
                label: 'Surgeries per Day',
                data: <?php echo json_encode(array_column($daily_stats, 'surgeries')); ?>,
                borderColor: '#28a745',
                backgroundColor: 'rgba(40, 167, 69, 0.1)',
                fill: true,
                tension: 0.4
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
     require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';

    ?>
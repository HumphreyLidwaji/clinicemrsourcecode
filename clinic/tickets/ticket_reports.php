<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';



// Report parameters
$report_type = $_GET['report_type'] ?? 'summary';
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$assigned_to = $_GET['assigned_to'] ?? '';
$category = $_GET['category'] ?? '';
$priority = $_GET['priority'] ?? '';
$status = $_GET['status'] ?? '';

// Validate dates
function validateDate($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

if (!validateDate($date_from)) $date_from = date('Y-m-d', strtotime('-30 days'));
if (!validateDate($date_to)) $date_to = date('Y-m-d');
if ($date_from > $date_to) {
    $temp = $date_from;
    $date_from = $date_to;
    $date_to = $temp;
}

// Build query conditions
$conditions = ["t.created_at BETWEEN ? AND ?"];
$params = [$date_from . ' 00:00:00', $date_to . ' 23:59:59'];
$types = 'ss';

if ($assigned_to && $assigned_to !== 'all') {
    $conditions[] = "t.assigned_to = ?";
    $params[] = intval($assigned_to);
    $types .= 'i';
}

if ($category && $category !== 'all') {
    $conditions[] = "t.ticket_category = ?";
    $params[] = sanitizeInput($category);
    $types .= 's';
}

if ($priority && $priority !== 'all') {
    $conditions[] = "t.ticket_priority = ?";
    $params[] = sanitizeInput($priority);
    $types .= 's';
}

if ($status && $status !== 'all') {
    $conditions[] = "t.ticket_status = ?";
    $params[] = sanitizeInput($status);
    $types .= 's';
}

$where_clause = implode(' AND ', $conditions);

// Fetch ticket data for reports - INCLUDING ticket_replies
$ticket_sql = "
    SELECT t.*,
           u.user_name as assigned_name,
           u.user_email as assigned_email,
          
           TIMESTAMPDIFF(HOUR, t.created_at, COALESCE(t.closed_at, NOW())) as age_hours,
           TIMESTAMPDIFF(HOUR, t.created_at, t.closed_at) as resolution_hours,
           COUNT(tr.reply_id) as reply_count,
           CASE 
               WHEN t.ticket_status = 'closed' THEN 'closed'
               WHEN t.ticket_status = 'open' AND TIMESTAMPDIFF(HOUR, t.created_at, NOW()) > 48 THEN 'overdue'
               WHEN t.ticket_status IN ('open', 'in_progress') THEN 'active'
               WHEN t.ticket_status = 'on_hold' THEN 'on_hold'
               ELSE t.ticket_status
           END as timeline_status
    FROM tickets t
    LEFT JOIN users u ON t.assigned_to = u.user_id
    LEFT JOIN ticket_replies tr ON t.ticket_id = tr.ticket_id
    WHERE $where_clause
    GROUP BY t.ticket_id
    ORDER BY t.created_at DESC
";

$ticket_stmt = $mysqli->prepare($ticket_sql);
if ($params) {
    $ticket_stmt->bind_param($types, ...$params);
}
$ticket_stmt->execute();
$ticket_result = $ticket_stmt->get_result();

// Calculate statistics
$stats = [
    'total_tickets' => 0,
    'open_tickets' => 0,
    'in_progress_tickets' => 0,
    'closed_tickets' => 0,
    'on_hold_tickets' => 0,
    'overdue_tickets' => 0,
    'avg_resolution_hours' => 0,
    'max_resolution_hours' => 0,
    'first_response_avg' => 0,
    'satisfaction_avg' => 0,
    'high_priority_tickets' => 0,
    'urgent_tickets' => 0,
    'peak_day' => '',
    'peak_day_count' => 0,
    'most_active_assignee' => '',
    'most_active_count' => 0,
    'total_replies' => 0,
    'avg_replies_per_ticket' => 0
];

$ticket_data = [];
$daily_stats = [];
$category_stats = [];
$priority_stats = [];
$assignee_stats = [];
$status_stats = [];
$client_stats = [];

$total_resolution_hours = 0;
$closed_ticket_count = 0;
$total_first_response = 0;
$total_satisfaction = 0;
$satisfaction_count = 0;
$total_replies = 0;

while ($ticket = $ticket_result->fetch_assoc()) {
    $ticket_data[] = $ticket;
    $stats['total_tickets']++;
    
    // Add reply count to total
    $total_replies += intval($ticket['reply_count']);
    
// Count by status
switch($ticket['ticket_status']) {

    case 'open': 
        $stats['open_tickets']++; 
        break;

    case 'in_progress': 
        $stats['in_progress_tickets']++; 
        break;

    case 'closed': 
        $stats['closed_tickets']++;

        if (!empty($ticket['resolution_hours'])) {
            $total_resolution_hours += $ticket['resolution_hours'];
            $closed_ticket_count++;
        }

        
        if (!empty($ticket['satisfaction_rating'])) {
            $total_satisfaction += $ticket['satisfaction_rating'];
            $satisfaction_count++;
        }
        break;

    case 'on_hold': 
        $stats['on_hold_tickets']++; 
        break;
}

    // Overdue tickets
    if ($ticket['timeline_status'] === 'overdue') {
        $stats['overdue_tickets']++;
    }
    
    // Priority counts
    if ($ticket['ticket_priority'] === 'high') {
        $stats['high_priority_tickets']++;
    }
    if ($ticket['ticket_priority'] === 'urgent') {
        $stats['urgent_tickets']++;
    }
    
    // Daily statistics
    $date = date('Y-m-d', strtotime($ticket['created_at']));
    if (!isset($daily_stats[$date])) {
        $daily_stats[$date] = [
            'tickets' => 0,
            'closed' => 0,
            'overdue' => 0,
            'high_priority' => 0,
            'replies' => 0
        ];
    }
    $daily_stats[$date]['tickets']++;
    $daily_stats[$date]['replies'] += intval($ticket['reply_count']);
    if ($ticket['ticket_status'] === 'closed') {
        $daily_stats[$date]['closed']++;
    }
    if ($ticket['timeline_status'] === 'overdue') {
        $daily_stats[$date]['overdue']++;
    }
    if (in_array($ticket['ticket_priority'], ['high', 'urgent'])) {
        $daily_stats[$date]['high_priority']++;
    }
    
    // Category statistics
    $category_name = $ticket['ticket_category'] ?: 'Uncategorized';
    if (!isset($category_stats[$category_name])) {
        $category_stats[$category_name] = [
            'count' => 0,
            'open' => 0,
            'closed' => 0,
            'avg_resolution' => 0,
            'total_resolution' => 0,
            'avg_replies' => 0,
            'total_replies' => 0
        ];
    }
    $category_stats[$category_name]['count']++;
    $category_stats[$category_name]['total_replies'] += intval($ticket['reply_count']);
    if ($ticket['ticket_status'] === 'open') $category_stats[$category_name]['open']++;
    if ($ticket['ticket_status'] === 'closed') {
        $category_stats[$category_name]['closed']++;
        if ($ticket['resolution_hours'] > 0) {
            $category_stats[$category_name]['total_resolution'] += $ticket['resolution_hours'];
        }
    }
    
    // Priority statistics
    $priority_name = $ticket['ticket_priority'];
    if (!isset($priority_stats[$priority_name])) {
        $priority_stats[$priority_name] = [
            'count' => 0,
            'open' => 0,
            'closed' => 0,
            'avg_resolution' => 0,
            'total_resolution' => 0,
            'avg_replies' => 0,
            'total_replies' => 0
        ];
    }
    $priority_stats[$priority_name]['count']++;
    $priority_stats[$priority_name]['total_replies'] += intval($ticket['reply_count']);
    if ($ticket['ticket_status'] === 'open') $priority_stats[$priority_name]['open']++;
    if ($ticket['ticket_status'] === 'closed') {
        $priority_stats[$priority_name]['closed']++;
        if ($ticket['resolution_hours'] > 0) {
            $priority_stats[$priority_name]['total_resolution'] += $ticket['resolution_hours'];
        }
    }
    
    // Assignee statistics
    $assignee = $ticket['assigned_name'] ?: 'Unassigned';
    if (!isset($assignee_stats[$assignee])) {
        $assignee_stats[$assignee] = [
            'tickets' => 0,
            'open' => 0,
            'closed' => 0,
            'avg_resolution' => 0,
            'total_resolution' => 0,
            'avg_replies' => 0,
            'total_replies' => 0,
            'email' => $ticket['assigned_email']
        ];
    }
    $assignee_stats[$assignee]['tickets']++;
    $assignee_stats[$assignee]['total_replies'] += intval($ticket['reply_count']);
    if ($ticket['ticket_status'] === 'open') $assignee_stats[$assignee]['open']++;
    if ($ticket['ticket_status'] === 'closed') {
        $assignee_stats[$assignee]['closed']++;
        if ($ticket['resolution_hours'] > 0) {
            $assignee_stats[$assignee]['total_resolution'] += $ticket['resolution_hours'];
        }
    }

}

// Calculate averages
$stats['total_replies'] = $total_replies;
$stats['avg_replies_per_ticket'] = $stats['total_tickets'] > 0 ? round($total_replies / $stats['total_tickets'], 1) : 0;

if ($closed_ticket_count > 0) {
    $stats['avg_resolution_hours'] = round($total_resolution_hours / $closed_ticket_count, 1);
}

if ($satisfaction_count > 0) {
    $stats['satisfaction_avg'] = round($total_satisfaction / $satisfaction_count, 1);
}

// Calculate averages for categories
foreach ($category_stats as &$cat) {
    if ($cat['closed'] > 0) {
        $cat['avg_resolution'] = round($cat['total_resolution'] / $cat['closed'], 1);
    }
    if ($cat['count'] > 0) {
        $cat['avg_replies'] = round($cat['total_replies'] / $cat['count'], 1);
    }
}

// Calculate averages for priorities
foreach ($priority_stats as &$pri) {
    if ($pri['closed'] > 0) {
        $pri['avg_resolution'] = round($pri['total_resolution'] / $pri['closed'], 1);
    }
    if ($pri['count'] > 0) {
        $pri['avg_replies'] = round($pri['total_replies'] / $pri['count'], 1);
    }
}

// Calculate averages for assignees
foreach ($assignee_stats as &$ass) {
    if ($ass['closed'] > 0) {
        $ass['avg_resolution'] = round($ass['total_resolution'] / $ass['closed'], 1);
    }
    if ($ass['tickets'] > 0) {
        $ass['avg_replies'] = round($ass['total_replies'] / $ass['tickets'], 1);
    }
}

// Calculate averages for clients
foreach ($client_stats as &$cli) {
    if ($cli['tickets'] > 0) {
        $cli['avg_replies'] = round($cli['total_replies'] / $cli['tickets'], 1);
    }
}

// Find peak day
if (!empty($daily_stats)) {
    $peak_day = null;
    $max_tickets = -1;

    foreach ($daily_stats as $day => $data) {
        if (!empty($data['tickets']) && $data['tickets'] > $max_tickets) {
            $peak_day = $day;
            $max_tickets = $data['tickets'];
        }
    }

    if ($peak_day !== null) {
        $stats['peak_day'] = date('M j, Y', strtotime($peak_day));
        $stats['peak_day_count'] = $max_tickets;
    }
}

// Find most active assignee
if (!empty($assignee_stats)) {
    $most_active = null;
    $max_tickets = -1;

    foreach ($assignee_stats as $assignee => $data) {
        if (!empty($data['tickets']) && $data['tickets'] > $max_tickets) {
            $most_active = $assignee;
            $max_tickets = $data['tickets'];
        }
    }

    if ($most_active !== null) {
        $stats['most_active_assignee'] = $most_active;
        $stats['most_active_count'] = $max_tickets;
    }
}

// Get filter options
$categories_sql = "SELECT DISTINCT ticket_category FROM tickets WHERE ticket_category IS NOT NULL AND ticket_category != '' ORDER BY ticket_category";
$categories_result = $mysqli->query($categories_sql);

$support_users_sql = "SELECT user_id, user_name FROM users ";
$support_users_result = $mysqli->query($support_users_sql);


// Generate report based on type
$report_data = [];
switch ($report_type) {
    case 'daily_activity':
        $report_data = generateDailyActivityReport($daily_stats);
        break;
    case 'category_analysis':
        $report_data = generateCategoryAnalysisReport($category_stats);
        break;
    case 'priority_analysis':
        $report_data = generatePriorityAnalysisReport($priority_stats);
        break;
    case 'assignee_performance':
        $report_data = generateAssigneePerformanceReport($assignee_stats);
        break;
    case 'client_analysis':
        $report_data = generateClientAnalysisReport($client_stats);
        break;
    case 'resolution_time':
        $report_data = generateResolutionTimeReport($ticket_data);
        break;
    default:
        $report_data = generateSummaryReport($stats, $priority_stats, $category_stats);
}



// Report generation functions
function generateSummaryReport($stats, $priority_stats, $category_stats) {
    $report = [
        'title' => 'Ticket Summary Report',
        'headers' => ['Metric', 'Value', 'Details'],
        'rows' => []
    ];
    
    $report['rows'][] = ['Total Tickets', $stats['total_tickets'], 'Across all statuses and priorities'];
    $report['rows'][] = ['Open Tickets', $stats['open_tickets'], round(($stats['open_tickets'] / $stats['total_tickets']) * 100, 1) . '% of total'];
    $report['rows'][] = ['Closed Tickets', $stats['closed_tickets'], round(($stats['closed_tickets'] / $stats['total_tickets']) * 100, 1) . '% completion rate'];
    $report['rows'][] = ['Overdue Tickets', $stats['overdue_tickets'], round(($stats['overdue_tickets'] / $stats['open_tickets']) * 100, 1) . '% of open tickets'];
    $report['rows'][] = ['High Priority Tickets', $stats['high_priority_tickets'] + $stats['urgent_tickets'], 'Requires immediate attention'];
    $report['rows'][] = ['Average Resolution Time', $stats['avg_resolution_hours'] . ' hours', 'For closed tickets only'];
    $report['rows'][] = ['Satisfaction Rating', $stats['satisfaction_avg'] . ' / 5', 'Based on closed tickets'];
    $report['rows'][] = ['Total Replies', $stats['total_replies'], 'Across all tickets'];
    $report['rows'][] = ['Avg Replies per Ticket', $stats['avg_replies_per_ticket'], 'Engagement metric'];
    $report['rows'][] = ['Peak Activity Day', $stats['peak_day'], $stats['peak_day_count'] . ' tickets created'];
    $report['rows'][] = ['Most Active Assignee', $stats['most_active_assignee'], $stats['most_active_count'] . ' tickets assigned'];
    
    return $report;
}

function generateDailyActivityReport($daily_stats) {
    $report = [
        'title' => 'Daily Activity Report',
        'headers' => ['Date', 'Day', 'Total Tickets', 'Closed', 'Overdue', 'High Priority', 'Total Replies', 'Closure Rate'],
        'rows' => []
    ];
    
    ksort($daily_stats); // Sort by date
    
    foreach ($daily_stats as $date => $stats) {
        $day_name = date('D', strtotime($date));
        $closure_rate = $stats['tickets'] > 0 ? round(($stats['closed'] / $stats['tickets']) * 100, 1) : 0;
        
        $report['rows'][] = [
            date('M j, Y', strtotime($date)),
            $day_name,
            $stats['tickets'],
            $stats['closed'],
            $stats['overdue'],
            $stats['high_priority'],
            $stats['replies'],
            $closure_rate . '%'
        ];
    }
    
    return $report;
}

function generateCategoryAnalysisReport($category_stats) {
    $report = [
        'title' => 'Category Analysis Report',
        'headers' => ['Category', 'Total Tickets', 'Open', 'Closed', 'Avg Resolution (hrs)', 'Avg Replies', 'Closure Rate', 'Percentage'],
        'rows' => []
    ];
    
    // Sort by total tickets
    uasort($category_stats, function($a, $b) {
        return $b['count'] - $a['count'];
    });
    
    $total_tickets = array_sum(array_column($category_stats, 'count'));
    
    foreach ($category_stats as $category => $stats) {
        $closure_rate = $stats['count'] > 0 ? round(($stats['closed'] / $stats['count']) * 100, 1) : 0;
        $percentage = $total_tickets > 0 ? round(($stats['count'] / $total_tickets) * 100, 1) : 0;
        
        $report['rows'][] = [
            $category,
            $stats['count'],
            $stats['open'],
            $stats['closed'],
            $stats['avg_resolution'],
            $stats['avg_replies'],
            $closure_rate . '%',
            $percentage . '%'
        ];
    }
    
    return $report;
}

function generatePriorityAnalysisReport($priority_stats) {
    $report = [
        'title' => 'Priority Analysis Report',
        'headers' => ['Priority', 'Total Tickets', 'Open', 'Closed', 'Avg Resolution (hrs)', 'Avg Replies', 'Closure Rate'],
        'rows' => []
    ];
    
    $priority_order = ['urgent', 'high', 'medium', 'low'];
    
    foreach ($priority_order as $priority) {
        if (isset($priority_stats[$priority])) {
            $stats = $priority_stats[$priority];
            $closure_rate = $stats['count'] > 0 ? round(($stats['closed'] / $stats['count']) * 100, 1) : 0;
            
            $report['rows'][] = [
                ucfirst($priority),
                $stats['count'],
                $stats['open'],
                $stats['closed'],
                $stats['avg_resolution'],
                $stats['avg_replies'],
                $closure_rate . '%'
            ];
        }
    }
    
    return $report;
}

function generateAssigneePerformanceReport($assignee_stats) {
    $report = [
        'title' => 'Assignee Performance Report',
        'headers' => ['Assignee', 'Total Tickets', 'Open', 'Closed', 'Avg Resolution (hrs)', 'Avg Replies', 'Closure Rate', 'SLA Compliance'],
        'rows' => []
    ];
    
    // Sort by total tickets
    uasort($assignee_stats, function($a, $b) {
        return $b['tickets'] - $a['tickets'];
    });
    
    foreach ($assignee_stats as $assignee => $stats) {
        $closure_rate = $stats['tickets'] > 0 ? round(($stats['closed'] / $stats['tickets']) * 100, 1) : 0;
        $sla_compliance = $stats['closed'] > 0 ? 
            ($stats['avg_resolution'] <= 24 ? 'Excellent' : 
            ($stats['avg_resolution'] <= 48 ? 'Good' : 'Needs Improvement')) : 'N/A';
        
        $report['rows'][] = [
            $assignee,
            $stats['tickets'],
            $stats['open'],
            $stats['closed'],
            $stats['avg_resolution'],
            $stats['avg_replies'],
            $closure_rate . '%',
            $sla_compliance
        ];
    }
    
    return $report;
}

function generateClientAnalysisReport($client_stats) {
    $report = [
        'title' => 'Client Analysis Report',
        'headers' => ['Client', 'Total Tickets', 'Open', 'Closed', 'Avg Replies', 'Avg Satisfaction', 'Closure Rate'],
        'rows' => []
    ];
    
    // Sort by total tickets
    uasort($client_stats, function($a, $b) {
        return $b['tickets'] - $a['tickets'];
    });
    
    foreach ($client_stats as $client => $stats) {
        $closure_rate = $stats['tickets'] > 0 ? round(($stats['closed'] / $stats['tickets']) * 100, 1) : 0;
        
        $report['rows'][] = [
            $client,
            $stats['tickets'],
            $stats['open'],
            $stats['closed'],
            $stats['avg_replies'],
            $stats['avg_satisfaction'] > 0 ? $stats['avg_satisfaction'] . ' / 5' : 'N/A',
            $closure_rate . '%'
        ];
    }
    
    return $report;
}

function generateResolutionTimeReport($ticket_data) {
    $report = [
        'title' => 'Resolution Time Analysis',
        'headers' => ['Time Frame', 'Ticket Count', 'Percentage', 'Average Hours', 'Avg Replies', 'Details'],
        'rows' => []
    ];
    
    $timeframes = [
        '0-2 hours' => ['min' => 0, 'max' => 2],
        '2-8 hours' => ['min' => 2, 'max' => 8],
        '8-24 hours' => ['min' => 8, 'max' => 24],
        '1-3 days' => ['min' => 24, 'max' => 72],
        '3-7 days' => ['min' => 72, 'max' => 168],
        'Over 7 days' => ['min' => 168, 'max' => PHP_INT_MAX]
    ];
    
    $timeframe_counts = array_fill_keys(array_keys($timeframes), 0);
    $timeframe_total_hours = array_fill_keys(array_keys($timeframes), 0);
    $timeframe_total_replies = array_fill_keys(array_keys($timeframes), 0);
    
    foreach ($ticket_data as $ticket) {
        if ($ticket['ticket_status'] === 'closed' && $ticket['resolution_hours'] > 0) {
            foreach ($timeframes as $label => $range) {
                if ($ticket['resolution_hours'] >= $range['min'] && $ticket['resolution_hours'] < $range['max']) {
                    $timeframe_counts[$label]++;
                    $timeframe_total_hours[$label] += $ticket['resolution_hours'];
                    $timeframe_total_replies[$label] += intval($ticket['reply_count']);
                    break;
                }
            }
        }
    }
    
    $total_closed = array_sum($timeframe_counts);
    
    foreach ($timeframes as $label => $range) {
        $count = $timeframe_counts[$label];
        $percentage = $total_closed > 0 ? round(($count / $total_closed) * 100, 1) : 0;
        $avg_hours = $count > 0 ? round($timeframe_total_hours[$label] / $count, 1) : 0;
        $avg_replies = $count > 0 ? round($timeframe_total_replies[$label] / $count, 1) : 0;
        
        $details = '';
        if ($count > 0) {
            if ($label === '0-2 hours') $details = 'Excellent response time';
            elseif ($label === '2-8 hours') $details = 'Good response time';
            elseif ($label === '8-24 hours') $details = 'Standard response time';
            elseif ($label === '1-3 days') $details = 'Needs improvement';
            elseif ($label === '3-7 days') $details = 'Poor response time';
            else $details = 'Critical - needs attention';
        }
        
        $report['rows'][] = [
            $label,
            $count,
            $percentage . '%',
            $avg_hours,
            $avg_replies,
            $details
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
                    <i class="fas fa-fw fa-chart-bar mr-2"></i>
                    Ticket Reports & Analytics
                </h3>
                <small class="text-white-50">Comprehensive ticket performance analysis and insights</small>
            </div>
            <div class="card-tools">
                <a href="tickets.php" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Tickets
                </a>
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
                            <option value="daily_activity" <?php echo $report_type === 'daily_activity' ? 'selected' : ''; ?>>Daily Activity</option>
                            <option value="category_analysis" <?php echo $report_type === 'category_analysis' ? 'selected' : ''; ?>>Category Analysis</option>
                            <option value="priority_analysis" <?php echo $report_type === 'priority_analysis' ? 'selected' : ''; ?>>Priority Analysis</option>
                            <option value="assignee_performance" <?php echo $report_type === 'assignee_performance' ? 'selected' : ''; ?>>Assignee Performance</option>
                            <option value="client_analysis" <?php echo $report_type === 'client_analysis' ? 'selected' : ''; ?>>Client Analysis</option>
                            <option value="resolution_time" <?php echo $report_type === 'resolution_time' ? 'selected' : ''; ?>>Resolution Time</option>
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
                        <label>Assigned To</label>
                        <select class="form-control select2" name="assigned_to" onchange="this.form.submit()">
                            <option value="all">All Assignees</option>
                            <option value="0">Unassigned</option>
                            <?php while($user = $support_users_result->fetch_assoc()): ?>
                                <option value="<?php echo $user['user_id']; ?>" 
                                    <?php echo $assigned_to == $user['user_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($user['user_name']); ?>
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
                            <a href="reports_tickets.php" class="btn btn-secondary">
                                <i class="fas fa-redo mr-2"></i>Reset
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
                            <option value="all">All Categories</option>
                            <?php while($cat = $categories_result->fetch_assoc()): ?>
                                <option value="<?php echo $cat['ticket_category']; ?>" 
                                    <?php echo $category == $cat['ticket_category'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat['ticket_category']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-group">
                        <label>Priority</label>
                        <select class="form-control select2" name="priority" onchange="this.form.submit()">
                            <option value="all">All Priorities</option>
                            <option value="low" <?php echo $priority == 'low' ? 'selected' : ''; ?>>Low</option>
                            <option value="medium" <?php echo $priority == 'medium' ? 'selected' : ''; ?>>Medium</option>
                            <option value="high" <?php echo $priority == 'high' ? 'selected' : ''; ?>>High</option>
                            <option value="urgent" <?php echo $priority == 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-group">
                        <label>Status</label>
                        <select class="form-control select2" name="status" onchange="this.form.submit()">
                            <option value="all">All Statuses</option>
                            <option value="open" <?php echo $status == 'open' ? 'selected' : ''; ?>>Open</option>
                            <option value="in_progress" <?php echo $status == 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                            <option value="on_hold" <?php echo $status == 'on_hold' ? 'selected' : ''; ?>>On Hold</option>
                            <option value="closed" <?php echo $status == 'closed' ? 'selected' : ''; ?>>Closed</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-5">
                    <div class="form-group">
                        <label>Quick Date Ranges</label>
                        <div class="btn-group w-100">
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setDateRange('today')">Today</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setDateRange('yesterday')">Yesterday</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setDateRange('this_week')">This Week</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setDateRange('last_week')">Last Week</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setDateRange('this_month')">This Month</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setDateRange('last_month')">Last Month</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setDateRange('last_30_days')">Last 30 Days</button>
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
                    <span class="info-box-icon bg-primary"><i class="fas fa-ticket-alt"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Tickets</span>
                        <span class="info-box-number"><?php echo $stats['total_tickets']; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-warning"><i class="fas fa-clock"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Open Tickets</span>
                        <span class="info-box-number"><?php echo $stats['open_tickets']; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-success"><i class="fas fa-check-circle"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Closed</span>
                        <span class="info-box-number"><?php echo $stats['closed_tickets']; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-danger"><i class="fas fa-exclamation-triangle"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">High Priority</span>
                        <span class="info-box-number"><?php echo $stats['high_priority_tickets'] + $stats['urgent_tickets']; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-info"><i class="fas fa-hourglass-half"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Avg Resolution</span>
                        <span class="info-box-number"><?php echo $stats['avg_resolution_hours']; ?>h</span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-secondary"><i class="fas fa-comments"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Avg Replies</span>
                        <span class="info-box-number"><?php echo $stats['avg_replies_per_ticket']; ?></span>
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
                    <?php if ($assigned_to && $assigned_to !== 'all'): ?>
                        | Assignee Filter Applied
                    <?php endif; ?>
                    <?php if ($category && $category !== 'all'): ?>
                        | Category: <?php echo htmlspecialchars($category); ?>
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
                <p class="text-muted">No tickets found for the selected criteria.</p>
            </div>
        <?php endif; ?>

        <!-- Charts Section -->
        <?php if ($report_type === 'summary' && $stats['total_tickets'] > 0): ?>
            <div class="row mt-5">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Ticket Status Distribution</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="statusChart" width="400" height="200"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Priority Distribution</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="priorityChart" width="400" height="200"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="row mt-4">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Daily Ticket Volume</h5>
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
                <button class="btn btn-outline-danger btn-block" onclick="printReport()">
                    <i class="fas fa-print mr-2"></i>Print Report
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

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
$(document).ready(function() {
    $('.select2').select2({
        theme: 'bootstrap4'
    });

    <?php if ($report_type === 'summary' && $stats['total_tickets'] > 0): ?>
        initializeCharts();
    <?php endif; ?>
});

function setDateRange(range) {
    const today = new Date();
    let fromDate, toDate;

    switch(range) {
        case 'today':
            fromDate = new Date(today);
            toDate = new Date(today);
            break;
        case 'yesterday':
            fromDate = new Date(today);
            fromDate.setDate(today.getDate() - 1);
            toDate = new Date(fromDate);
            break;
        case 'this_week':
            fromDate = new Date(today);
            fromDate.setDate(today.getDate() - today.getDay());
            toDate = new Date(today);
            break;
        case 'last_week':
            fromDate = new Date(today);
            fromDate.setDate(today.getDate() - today.getDay() - 7);
            toDate = new Date(fromDate);
            toDate.setDate(fromDate.getDate() + 6);
            break;
        case 'this_month':
            fromDate = new Date(today.getFullYear(), today.getMonth(), 1);
            toDate = new Date(today);
            break;
        case 'last_month':
            fromDate = new Date(today.getFullYear(), today.getMonth() - 1, 1);
            toDate = new Date(today.getFullYear(), today.getMonth(), 0);
            break;
        case 'last_30_days':
            fromDate = new Date(today);
            fromDate.setDate(today.getDate() - 30);
            toDate = new Date(today);
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
                <title>Ticket Report</title>
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
    // Implement CSV export
    alert('CSV export functionality would be implemented here');
}

function exportToExcel() {
    // Implement Excel export
    alert('Excel export functionality would be implemented here');
}

function showEmailDialog() {
    // Implement email dialog
    alert('Email report functionality would be implemented here');
}

<?php if ($report_type === 'summary' && $stats['total_tickets'] > 0): ?>
function initializeCharts() {
    // Status Distribution Chart
    const statusCtx = document.getElementById('statusChart').getContext('2d');
    const statusChart = new Chart(statusCtx, {
        type: 'doughnut',
        data: {
            labels: ['Open', 'In Progress', 'Closed', 'On Hold'],
            datasets: [{
                data: [
                    <?php echo $stats['open_tickets']; ?>,
                    <?php echo $stats['in_progress_tickets']; ?>,
                    <?php echo $stats['closed_tickets']; ?>,
                    <?php echo $stats['on_hold_tickets']; ?>
                ],
                backgroundColor: [
                    '#ffc107',
                    '#007bff',
                    '#28a745',
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

    // Priority Distribution Chart
    const priorityCtx = document.getElementById('priorityChart').getContext('2d');
    const priorityChart = new Chart(priorityCtx, {
        type: 'bar',
        data: {
            labels: ['Low', 'Medium', 'High', 'Urgent'],
            datasets: [{
                label: 'Number of Tickets',
                data: [
                    <?php echo $priority_stats['low']['count'] ?? 0; ?>,
                    <?php echo $priority_stats['medium']['count'] ?? 0; ?>,
                    <?php echo $priority_stats['high']['count'] ?? 0; ?>,
                    <?php echo $priority_stats['urgent']['count'] ?? 0; ?>
                ],
                backgroundColor: [
                    '#17a2b8',
                    '#ffc107',
                    '#fd7e14',
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

    // Daily Volume Chart
    const dailyCtx = document.getElementById('dailyVolumeChart').getContext('2d');
    const dailyChart = new Chart(dailyCtx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode(array_map(function($date) { 
                return date('M j', strtotime($date)); 
            }, array_keys($daily_stats))); ?>,
            datasets: [{
                label: 'Tickets per Day',
                data: <?php echo json_encode(array_column($daily_stats, 'tickets')); ?>,
                borderColor: '#007bff',
                backgroundColor: 'rgba(0, 123, 255, 0.1)',
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
<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';



// Get current month and year
$month = isset($_GET['month']) ? intval($_GET['month']) : date('n');
$year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');

// Calculate previous and next months
$prev_month = $month - 1;
$prev_year = $year;
if ($prev_month < 1) {
    $prev_month = 12;
    $prev_year = $year - 1;
}

$next_month = $month + 1;
$next_year = $year;
if ($next_month > 12) {
    $next_month = 1;
    $next_year = $year + 1;
}

// Get scheduled transfers for the month
$start_date = "$year-$month-01";
$end_date = date('Y-m-t', strtotime($start_date));

$schedule_sql = "SELECT t.*, 
                        fl.location_name as from_location_name,
                        tl.location_name as to_location_name,
                        u.user_name as requested_by_name,
                        COUNT(ti.item_id) as item_count,
                        SUM(ti.quantity) as total_quantity
                 FROM inventory_transfers t
                 LEFT JOIN inventory_locations fl ON t.from_location_id = fl.location_id
                 LEFT JOIN inventory_locations tl ON t.to_location_id = tl.location_id
                 LEFT JOIN users u ON t.requested_by = u.user_id
                 LEFT JOIN inventory_transfer_items ti ON t.transfer_id = ti.transfer_id
                 WHERE DATE(t.transfer_date) BETWEEN ? AND ?
                 GROUP BY t.transfer_id
                 ORDER BY t.transfer_date";
$schedule_stmt = $mysqli->prepare($schedule_sql);
$schedule_stmt->bind_param("ss", $start_date, $end_date);
$schedule_stmt->execute();
$schedule_result = $schedule_stmt->get_result();

$scheduled_transfers = [];
$daily_totals = [];
$status_counts = [
    'pending' => 0,
    'in_transit' => 0,
    'completed' => 0,
    'cancelled' => 0
];

while ($transfer = $schedule_result->fetch_assoc()) {
    $scheduled_transfers[] = $transfer;
    $transfer_date = date('Y-m-d', strtotime($transfer['transfer_date']));
    $transfer_day = date('j', strtotime($transfer['transfer_date']));
    
    if (!isset($daily_totals[$transfer_day])) {
        $daily_totals[$transfer_day] = [
            'count' => 0,
            'items' => 0,
            'quantity' => 0
        ];
    }
    
    $daily_totals[$transfer_day]['count']++;
    $daily_totals[$transfer_day]['items'] += $transfer['item_count'];
    $daily_totals[$transfer_day]['quantity'] += $transfer['total_quantity'];
    
    // Count by status
    if (isset($status_counts[$transfer['transfer_status']])) {
        $status_counts[$transfer['transfer_status']]++;
    }
}
$schedule_stmt->close();

// Get upcoming transfers (next 7 days)
$upcoming_start = date('Y-m-d');
$upcoming_end = date('Y-m-d', strtotime('+7 days'));

$upcoming_sql = "SELECT t.*, 
                        fl.location_name as from_location_name,
                        tl.location_name as to_location_name,
                        u.user_name as requested_by_name
                 FROM inventory_transfers t
                 LEFT JOIN inventory_locations fl ON t.from_location_id = fl.location_id
                 LEFT JOIN inventory_locations tl ON t.to_location_id = tl.location_id
                 LEFT JOIN users u ON t.requested_by = u.user_id
                 WHERE DATE(t.transfer_date) BETWEEN ? AND ?
                   AND t.transfer_status IN ('pending', 'in_transit')
                 ORDER BY t.transfer_date
                 LIMIT 10";
$upcoming_stmt = $mysqli->prepare($upcoming_sql);
$upcoming_stmt->bind_param("ss", $upcoming_start, $upcoming_end);
$upcoming_stmt->execute();
$upcoming_result = $upcoming_stmt->get_result();
$upcoming_transfers = [];

while ($transfer = $upcoming_result->fetch_assoc()) {
    $upcoming_transfers[] = $transfer;
}
$upcoming_stmt->close();

// Calendar data
$first_day = mktime(0, 0, 0, $month, 1, $year);
$days_in_month = date('t', $first_day);
$first_day_of_week = date('w', $first_day);
$month_name = date('F', $first_day);
$year_value = date('Y', $first_day);
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0 text-white">
                <i class="fas fa-fw fa-calendar-alt mr-2"></i>
                Transfer Schedule
            </h3>
            <div class="card-tools">
                <div class="btn-group">
                    <a href="inventory_transfer_schedule.php?month=<?php echo $prev_month; ?>&year=<?php echo $prev_year; ?>" 
                       class="btn btn-light">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                    <a href="inventory_transfer_schedule.php?month=<?php echo date('n'); ?>&year=<?php echo date('Y'); ?>" 
                       class="btn btn-light">
                        Today
                    </a>
                    <a href="inventory_transfer_schedule.php?month=<?php echo $next_month; ?>&year=<?php echo $next_year; ?>" 
                       class="btn btn-light">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="card-body">
        <?php if (isset($_SESSION['alert_message'])): ?>
            <div class="alert alert-<?php echo $_SESSION['alert_type']; ?> alert-dismissible">
                <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
                <i class="icon fas fa-<?php echo $_SESSION['alert_type'] == 'success' ? 'check' : 'exclamation-triangle'; ?>"></i>
                <?php echo $_SESSION['alert_message']; ?>
            </div>
            <?php 
            unset($_SESSION['alert_type']);
            unset($_SESSION['alert_message']);
            ?>
        <?php endif; ?>

        <div class="row mb-4">
            <div class="col-md-8">
                <!-- Calendar -->
                <div class="card card-primary">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <h3 class="card-title mb-0">
                                <i class="fas fa-calendar mr-2"></i>
                                <?php echo $month_name . ' ' . $year_value; ?>
                            </h3>
                            <div class="card-tools">
                                <span class="badge badge-light">
                                    <?php echo count($scheduled_transfers); ?> transfers scheduled
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-bordered mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th class="text-center" style="width: 14.28%">Sun</th>
                                        <th class="text-center" style="width: 14.28%">Mon</th>
                                        <th class="text-center" style="width: 14.28%">Tue</th>
                                        <th class="text-center" style="width: 14.28%">Wed</th>
                                        <th class="text-center" style="width: 14.28%">Thu</th>
                                        <th class="text-center" style="width: 14.28%">Fri</th>
                                        <th class="text-center" style="width: 14.28%">Sat</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $day = 1;
                                    $current_day = date('j');
                                    $current_month = date('n');
                                    $current_year = date('Y');
                                    
                                    for ($i = 0; $i < 6; $i++): ?>
                                    <tr>
                                        <?php for ($j = 0; $j < 7; $j++): ?>
                                        <?php
                                        $cell_class = '';
                                        $cell_content = '';
                                        
                                        if (($i == 0 && $j < $first_day_of_week) || $day > $days_in_month) {
                                            $cell_class = 'calendar-empty';
                                        } else {
                                            $is_today = ($day == $current_day && $month == $current_month && $year == $current_year);
                                            $is_weekend = ($j == 0 || $j == 6);
                                            
                                            if ($is_today) {
                                                $cell_class = 'bg-info text-white';
                                            } elseif ($is_weekend) {
                                                $cell_class = 'bg-light';
                                            }
                                            
                                            // Add transfer information if any
                                            $transfer_info = '';
                                            if (isset($daily_totals[$day])) {
                                                $total = $daily_totals[$day];
                                                $transfer_info = '<div class="small">';
                                                $transfer_info .= '<span class="badge badge-primary">' . $total['count'] . ' transfers</span>';
                                                $transfer_info .= '</div>';
                                            }
                                            
                                            $cell_content = '
                                                <div class="calendar-day">
                                                    <div class="day-number">' . $day . '</div>
                                                    ' . $transfer_info . '
                                                </div>
                                            ';
                                            
                                            $day++;
                                        }
                                        ?>
                                        <td class="calendar-cell p-1 <?php echo $cell_class; ?>" 
                                            style="height: 100px; vertical-align: top;">
                                            <?php echo $cell_content; ?>
                                        </td>
                                        <?php endfor; ?>
                                    </tr>
                                    <?php if ($day > $days_in_month) break; ?>
                                    <?php endfor; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Scheduled Transfers List -->
                <div class="card card-success mt-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-list mr-2"></i>Scheduled Transfers</h3>
                        <div class="card-tools">
                            <span class="badge badge-light"><?php echo $month_name . ' ' . $year_value; ?></span>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Date & Time</th>
                                        <th>Transfer #</th>
                                        <th>From → To</th>
                                        <th class="text-center">Items</th>
                                        <th class="text-center">Status</th>
                                        <th class="text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($scheduled_transfers as $transfer): ?>
                                    <?php 
                                    // Status badge
                                    switch($transfer['transfer_status']) {
                                        case 'pending':
                                            $status_class = 'warning';
                                            $status_icon = 'clock';
                                            break;
                                        case 'in_transit':
                                            $status_class = 'info';
                                            $status_icon = 'shipping-fast';
                                            break;
                                        case 'completed':
                                            $status_class = 'success';
                                            $status_icon = 'check-circle';
                                            break;
                                        case 'cancelled':
                                            $status_class = 'danger';
                                            $status_icon = 'times-circle';
                                            break;
                                        default:
                                            $status_class = 'secondary';
                                            $status_icon = 'question-circle';
                                    }
                                    ?>
                                    <tr>
                                        <td>
                                            <?php if ($transfer['transfer_date']): ?>
                                                <div class="font-weight-bold">
                                                    <?php echo date('M j, Y', strtotime($transfer['transfer_date'])); ?>
                                                </div>
                                                <div class="text-muted small">
                                                    <i class="fas fa-clock"></i>
                                                    <?php echo date('H:i', strtotime($transfer['transfer_date'])); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="inventory_transfer_view.php?id=<?php echo $transfer['transfer_id']; ?>">
                                                <strong><?php echo htmlspecialchars($transfer['transfer_number']); ?></strong>
                                            </a>
                                            <br>
                                            <small class="text-muted">
                                                <?php echo htmlspecialchars($transfer['requested_by_name']); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <div class="small">
                                                <i class="fas fa-arrow-up text-danger mr-1"></i>
                                                <?php echo htmlspecialchars($transfer['from_location_name']); ?>
                                            </div>
                                            <div class="small">
                                                <i class="fas fa-arrow-down text-success mr-1"></i>
                                                <?php echo htmlspecialchars($transfer['to_location_name']); ?>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge badge-light">
                                                <?php echo $transfer['item_count']; ?>
                                            </span>
                                            <br>
                                            <small class="text-muted">
                                                <?php echo number_format($transfer['total_quantity']); ?> units
                                            </small>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge badge-<?php echo $status_class; ?>">
                                                <i class="fas fa-<?php echo $status_icon; ?> mr-1"></i>
                                                <?php echo ucwords(str_replace('_', ' ', $transfer['transfer_status'])); ?>
                                            </span>
                                        </td>
                                        <td class="text-right">
                                            <a href="inventory_transfer_view.php?id=<?php echo $transfer['transfer_id']; ?>" 
                                               class="btn btn-sm btn-info" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="inventory_transfer_print.php?id=<?php echo $transfer['transfer_id']; ?>" 
                                               class="btn btn-sm btn-secondary" title="Print" target="_blank">
                                                <i class="fas fa-print"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    
                                    <?php if (empty($scheduled_transfers)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-3">
                                            <i class="fas fa-calendar-times fa-2x text-muted mb-2"></i>
                                            <p class="text-muted mb-0">No transfers scheduled for <?php echo $month_name . ' ' . $year_value; ?>.</p>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <!-- Status Overview -->
                <div class="card card-info">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-chart-pie mr-2"></i>Status Overview</h3>
                    </div>
                    <div class="card-body">
                        <canvas id="statusChart" height="150"></canvas>
                        <div class="mt-3">
                            <table class="table table-sm">
                                <tr>
                                    <td><span class="badge badge-warning">■</span> Pending</td>
                                    <td class="text-right"><?php echo $status_counts['pending']; ?></td>
                                    <td class="text-right">
                                        <?php echo $total = array_sum($status_counts) > 0 ? round(($status_counts['pending'] / array_sum($status_counts)) * 100) : 0; ?>%
                                    </td>
                                </tr>
                                <tr>
                                    <td><span class="badge badge-info">■</span> In Transit</td>
                                    <td class="text-right"><?php echo $status_counts['in_transit']; ?></td>
                                    <td class="text-right">
                                        <?php echo $total > 0 ? round(($status_counts['in_transit'] / $total) * 100) : 0; ?>%
                                    </td>
                                </tr>
                                <tr>
                                    <td><span class="badge badge-success">■</span> Completed</td>
                                    <td class="text-right"><?php echo $status_counts['completed']; ?></td>
                                    <td class="text-right">
                                        <?php echo $total > 0 ? round(($status_counts['completed'] / $total) * 100) : 0; ?>%
                                    </td>
                                </tr>
                                <tr>
                                    <td><span class="badge badge-danger">■</span> Cancelled</td>
                                    <td class="text-right"><?php echo $status_counts['cancelled']; ?></td>
                                    <td class="text-right">
                                        <?php echo $total > 0 ? round(($status_counts['cancelled'] / $total) * 100) : 0; ?>%
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Upcoming Transfers -->
                <div class="card card-warning mt-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-clock mr-2"></i>Upcoming (7 Days)</h3>
                    </div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush">
                            <?php foreach ($upcoming_transfers as $transfer): ?>
                            <a href="inventory_transfer_view.php?id=<?php echo $transfer['transfer_id']; ?>" 
                               class="list-group-item list-group-item-action">
                                <div class="d-flex w-100 justify-content-between">
                                    <h6 class="mb-1">
                                        <?php echo htmlspecialchars($transfer['transfer_number']); ?>
                                    </h6>
                                    <small>
                                        <?php if ($transfer['transfer_date']): ?>
                                            <?php echo date('M j', strtotime($transfer['transfer_date'])); ?>
                                        <?php endif; ?>
                                    </small>
                                </div>
                                <div class="small">
                                    <span class="badge badge-<?php echo $transfer['transfer_status'] == 'pending' ? 'warning' : 'info'; ?>">
                                        <?php echo ucwords(str_replace('_', ' ', $transfer['transfer_status'])); ?>
                                    </span>
                                </div>
                                <div class="small text-muted">
                                    <i class="fas fa-arrow-up text-danger mr-1"></i>
                                    <?php echo htmlspecialchars($transfer['from_location_name']); ?>
                                    <br>
                                    <i class="fas fa-arrow-down text-success mr-1"></i>
                                    <?php echo htmlspecialchars($transfer['to_location_name']); ?>
                                </div>
                            </a>
                            <?php endforeach; ?>
                            
                            <?php if (empty($upcoming_transfers)): ?>
                            <div class="list-group-item text-center py-3">
                                <i class="fas fa-check-circle fa-2x text-muted mb-2"></i>
                                <p class="text-muted mb-0">No upcoming transfers.</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="card card-success mt-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-bolt mr-2"></i>Quick Actions</h3>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="inventory_transfers.php?status=pending" class="btn btn-warning">
                                <i class="fas fa-clock mr-2"></i>View Pending Transfers
                            </a>
                            <a href="inventory_bulk_transfer.php" class="btn btn-primary">
                                <i class="fas fa-truck-loading mr-2"></i>Create Bulk Transfer
                            </a>
                            <a href="inventory_transfer_reports.php" class="btn btn-info">
                                <i class="fas fa-chart-bar mr-2"></i>View Reports
                            </a>
                            <a href="inventory_transfers.php" class="btn btn-outline-dark">
                                <i class="fas fa-list mr-2"></i>All Transfers
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Month Navigation -->
                <div class="card card-secondary mt-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-calendar mr-2"></i>Jump to Month</h3>
                    </div>
                    <div class="card-body">
                        <form method="GET" action="" class="form-inline">
                            <div class="form-group mr-2">
                                <select class="form-control" name="month">
                                    <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?php echo $m; ?>" 
                                            <?php echo $m == $month ? 'selected' : ''; ?>>
                                        <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                    </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="form-group mr-2">
                                <input type="number" class="form-control" name="year" 
                                       value="<?php echo $year; ?>" min="2020" max="2030" style="width: 100px;">
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-arrow-right"></i>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Initialize status chart
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('statusChart').getContext('2d');
    const statusChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Pending', 'In Transit', 'Completed', 'Cancelled'],
            datasets: [{
                data: [
                    <?php echo $status_counts['pending']; ?>,
                    <?php echo $status_counts['in_transit']; ?>,
                    <?php echo $status_counts['completed']; ?>,
                    <?php echo $status_counts['cancelled']; ?>
                ],
                backgroundColor: [
                    '#ffc107',
                    '#17a2b8',
                    '#28a745',
                    '#dc3545'
                ],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    display: false
                }
            }
        }
    });
    
    // Make calendar cells clickable for day view
    document.querySelectorAll('.calendar-cell .calendar-day').forEach(cell => {
        cell.addEventListener('click', function() {
            const dayNumber = this.querySelector('.day-number').textContent.trim();
            if (dayNumber) {
                const month = <?php echo $month; ?>;
                const year = <?php echo $year; ?>;
                const day = dayNumber.padStart(2, '0');
                const dateStr = `${year}-${month.toString().padStart(2, '0')}-${day}`;
                
                window.location.href = `inventory_transfers.php?date=${dateStr}`;
            }
        });
    });
    
    // Add hover effects
    document.querySelectorAll('.calendar-cell .calendar-day').forEach(cell => {
        cell.style.cursor = 'pointer';
        cell.addEventListener('mouseenter', function() {
            this.style.backgroundColor = '#f8f9fa';
        });
        cell.addEventListener('mouseleave', function() {
            this.style.backgroundColor = '';
        });
    });
});

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Left arrow - previous month
    if (e.keyCode === 37) {
        e.preventDefault();
        window.location.href = 'inventory_transfer_schedule.php?month=<?php echo $prev_month; ?>&year=<?php echo $prev_year; ?>';
    }
    // Right arrow - next month
    if (e.keyCode === 39) {
        e.preventDefault();
        window.location.href = 'inventory_transfer_schedule.php?month=<?php echo $next_month; ?>&year=<?php echo $next_year; ?>';
    }
    // T - today
    if (e.keyCode === 84 && e.ctrlKey) {
        e.preventDefault();
        window.location.href = 'inventory_transfer_schedule.php?month=<?php echo date('n'); ?>&year=<?php echo date('Y'); ?>';
    }
});
</script>

<style>
.calendar-cell {
    transition: background-color 0.2s;
}

.calendar-cell:hover {
    background-color: #f8f9fa !important;
}

.calendar-empty {
    background-color: #f5f5f5;
}

.calendar-day {
    height: 100%;
    padding: 2px;
}

.day-number {
    font-weight: bold;
    font-size: 1.1em;
    margin-bottom: 5px;
}

.list-group-item:hover {
    background-color: #f8f9fa;
}

.small-box {
    border-radius: 0.25rem;
    box-shadow: 0 0 1px rgba(0,0,0,.125), 0 1px 3px rgba(0,0,0,.2);
    display: block;
    margin-bottom: 20px;
    position: relative;
}

.small-box > .inner {
    padding: 10px;
}

.small-box .icon {
    position: absolute;
    top: -10px;
    right: 10px;
    z-index: 0;
    font-size: 70px;
    color: rgba(0,0,0,0.15);
    transition: all .3s linear;
}

.small-box:hover .icon {
    font-size: 75px;
}

.table th {
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.85em;
    letter-spacing: 0.5px;
}

.badge {
    font-size: 0.85em;
    padding: 4px 8px;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';



// Get filter parameters
$report_type = $_GET['report_type'] ?? 'overview';
$year = $_GET['year'] ?? date('Y');
$category_filter = $_GET['category'] ?? '';
$department_filter = $_GET['department'] ?? '';
$severity_filter = $_GET['severity'] ?? 'all';

// Date range for custom reports
$start_date = $_GET['start_date'] ?? date('Y-01-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// Get available years for filter
$years_sql = "SELECT DISTINCT YEAR(incident_date) as year 
              FROM misconduct_incidents 
              ORDER BY year DESC";
$years_result = $mysqli->query($years_sql);

// Get categories for filter
$categories_sql = "SELECT category_id, category_name FROM misconduct_categories WHERE is_active = 1 ORDER BY category_name";
$categories_result = $mysqli->query($categories_sql);

// Get departments for filter
$departments_sql = "SELECT department_id, department_name FROM departments WHERE department_is_active = 1 ORDER BY department_name";
$departments_result = $mysqli->query($departments_sql);

// Generate reports based on type
switch ($report_type) {
    case 'overview':
        $report_title = "Misconduct Overview Report";
        break;
    case 'trends':
        $report_title = "Misconduct Trends Analysis";
        break;
    case 'department':
        $report_title = "Department-wise Misconduct Report";
        break;
    case 'severity':
        $report_title = "Severity Analysis Report";
        break;
    case 'resolution':
        $report_title = "Case Resolution Report";
        break;
    default:
        $report_title = "Misconduct Reports";
}

// Generate overview statistics
$overview_sql = "
    SELECT 
        COUNT(*) as total_incidents,
        SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open_incidents,
        SUM(CASE WHEN status = 'under_investigation' THEN 1 ELSE 0 END) as investigating_incidents,
        SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed_incidents,
        SUM(CASE WHEN severity = 'low' THEN 1 ELSE 0 END) as low_severity,
        SUM(CASE WHEN severity = 'medium' THEN 1 ELSE 0 END) as medium_severity,
        SUM(CASE WHEN severity = 'high' THEN 1 ELSE 0 END) as high_severity,
        SUM(CASE WHEN severity = 'gross' THEN 1 ELSE 0 END) as gross_severity,
        AVG(TIMESTAMPDIFF(DAY, incident_date, COALESCE((SELECT MAX(created_at) FROM disciplinary_actions WHERE incident_id = mi.incident_id), NOW()))) as avg_resolution_days
    FROM misconduct_incidents mi
    WHERE YEAR(mi.incident_date) = ?
";

$overview_stmt = $mysqli->prepare($overview_sql);
$overview_stmt->bind_param("i", $year);
$overview_stmt->execute();
$overview_result = $overview_stmt->get_result();
$overview_stats = $overview_result->fetch_assoc();

// Monthly trends data
$monthly_trends_sql = "
    SELECT 
        MONTH(incident_date) as month,
        COUNT(*) as incident_count,
        SUM(CASE WHEN severity IN ('high', 'gross') THEN 1 ELSE 0 END) as serious_count
    FROM misconduct_incidents 
    WHERE YEAR(incident_date) = ?
    GROUP BY MONTH(incident_date)
    ORDER BY month
";

$monthly_stmt = $mysqli->prepare($monthly_trends_sql);
$monthly_stmt->bind_param("i", $year);
$monthly_stmt->execute();
$monthly_result = $monthly_stmt->get_result();

$monthly_data = [];
while ($row = $monthly_result->fetch_assoc()) {
    $monthly_data[$row['month']] = $row;
}

// Category distribution
$category_dist_sql = "
    SELECT 
        mc.category_name,
        COUNT(mi.incident_id) as incident_count,
        ROUND(COUNT(mi.incident_id) * 100.0 / (SELECT COUNT(*) FROM misconduct_incidents WHERE YEAR(incident_date) = ?), 2) as percentage
    FROM misconduct_categories mc
    LEFT JOIN misconduct_incidents mi ON mc.category_id = mi.category_id AND YEAR(mi.incident_date) = ?
    GROUP BY mc.category_id, mc.category_name
    HAVING incident_count > 0
    ORDER BY incident_count DESC
";

$category_stmt = $mysqli->prepare($category_dist_sql);
$category_stmt->bind_param("ii", $year, $year);
$category_stmt->execute();
$category_result = $category_stmt->get_result();

// Department-wise incidents
$dept_incidents_sql = "
    SELECT 
        d.department_name,
        COUNT(mi.incident_id) as incident_count,
        COUNT(DISTINCT mi.employee_id) as employees_involved,
        ROUND(COUNT(mi.incident_id) * 100.0 / (SELECT COUNT(*) FROM misconduct_incidents WHERE YEAR(incident_date) = ?), 2) as percentage
    FROM departments d
    LEFT JOIN employees e ON d.department_id = e.department_id
    LEFT JOIN misconduct_incidents mi ON e.employee_id = mi.employee_id AND YEAR(mi.incident_date) = ?
    WHERE d.department_is_active = 1
    GROUP BY d.department_id, d.department_name
    HAVING incident_count > 0
    ORDER BY incident_count DESC
";

$dept_stmt = $mysqli->prepare($dept_incidents_sql);
$dept_stmt->bind_param("ii", $year, $year);
$dept_stmt->execute();
$dept_result = $dept_stmt->get_result();

// Resolution time analysis
$resolution_sql = "
    SELECT 
        mi.incident_id,
        mi.incident_date,
        da.created_at as action_date,
        TIMESTAMPDIFF(DAY, mi.incident_date, da.created_at) as resolution_days,
        da.action_type,
        e.first_name,
        e.last_name,
        d.department_name
    FROM misconduct_incidents mi
    JOIN disciplinary_actions da ON mi.incident_id = da.incident_id
    JOIN employees e ON mi.employee_id = e.employee_id
    LEFT JOIN departments d ON e.department_id = d.department_id
    WHERE YEAR(mi.incident_date) = ?
    ORDER BY resolution_days DESC
    LIMIT 20
";

$resolution_stmt = $mysqli->prepare($resolution_sql);
$resolution_stmt->bind_param("i", $year);
$resolution_stmt->execute();
$resolution_result = $resolution_stmt->get_result();
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0"><i class="fas fa-fw fa-chart-bar mr-2"></i>Misconduct Reports & Analytics</h3>
        <div class="card-tools">
            <a href="misconduct_dashboard.php" class="btn btn-light">
                <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
            </a>
        </div>
    </div>

    <div class="card-header pb-2 pt-3">
        <form autocomplete="off">
            <div class="row">
                <div class="col-md-3">
                    <div class="form-group">
                        <label>Report Type</label>
                        <select class="form-control" name="report_type" onchange="this.form.submit()">
                            <option value="overview" <?php echo $report_type == 'overview' ? 'selected' : ''; ?>>Overview Report</option>
                            <option value="trends" <?php echo $report_type == 'trends' ? 'selected' : ''; ?>>Trends Analysis</option>
                            <option value="department" <?php echo $report_type == 'department' ? 'selected' : ''; ?>>Department Report</option>
                            <option value="severity" <?php echo $report_type == 'severity' ? 'selected' : ''; ?>>Severity Analysis</option>
                            <option value="resolution" <?php echo $report_type == 'resolution' ? 'selected' : ''; ?>>Resolution Report</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-group">
                        <label>Year</label>
                        <select class="form-control" name="year" onchange="this.form.submit()">
                            <?php while ($year_row = $years_result->fetch_assoc()): ?>
                                <option value="<?php echo $year_row['year']; ?>" <?php echo $year_row['year'] == $year ? 'selected' : ''; ?>>
                                    <?php echo $year_row['year']; ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-group">
                        <label>Category</label>
                        <select class="form-control select2" name="category" onchange="this.form.submit()">
                            <option value="">All Categories</option>
                            <?php while ($cat = $categories_result->fetch_assoc()): ?>
                                <option value="<?php echo $cat['category_id']; ?>" <?php echo $cat['category_id'] == $category_filter ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat['category_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label>Department</label>
                        <select class="form-control select2" name="department" onchange="this.form.submit()">
                            <option value="">All Departments</option>
                            <?php while ($dept = $departments_result->fetch_assoc()): ?>
                                <option value="<?php echo $dept['department_id']; ?>" <?php echo $dept['department_id'] == $department_filter ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($dept['department_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-group">
                        <label>Severity</label>
                        <select class="form-control" name="severity" onchange="this.form.submit()">
                            <option value="all" <?php echo $severity_filter == 'all' ? 'selected' : ''; ?>>All Severities</option>
                            <option value="low" <?php echo $severity_filter == 'low' ? 'selected' : ''; ?>>Low</option>
                            <option value="medium" <?php echo $severity_filter == 'medium' ? 'selected' : ''; ?>>Medium</option>
                            <option value="high" <?php echo $severity_filter == 'high' ? 'selected' : ''; ?>>High</option>
                            <option value="gross" <?php echo $severity_filter == 'gross' ? 'selected' : ''; ?>>Gross</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="btn-toolbar float-right">
                        <div class="btn-group mr-2">
                            <button type="button" class="btn btn-success" onclick="printReport()">
                                <i class="fas fa-print mr-2"></i>Print Report
                            </button>
                            <button type="button" class="btn btn-info" onclick="exportToExcel()">
                                <i class="fas fa-file-excel mr-2"></i>Export to Excel
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
    
    <div class="card-body">
        <!-- Report Header -->
        <div class="row mb-4">
            <div class="col-md-12">
                <h4 class="text-center mb-1"><?php echo $report_title; ?></h4>
                <p class="text-center text-muted">
                    For the year <?php echo $year; ?>
                    <?php if ($category_filter): ?>
                        | Category: <?php 
                            mysqli_data_seek($categories_result, 0);
                            while ($cat = $categories_result->fetch_assoc()) {
                                if ($cat['category_id'] == $category_filter) {
                                    echo htmlspecialchars($cat['category_name']);
                                    break;
                                }
                            }
                        ?>
                    <?php endif; ?>
                    <?php if ($department_filter): ?>
                        | Department: <?php 
                            mysqli_data_seek($departments_result, 0);
                            while ($dept = $departments_result->fetch_assoc()) {
                                if ($dept['department_id'] == $department_filter) {
                                    echo htmlspecialchars($dept['department_name']);
                                    break;
                                }
                            }
                        ?>
                    <?php endif; ?>
                    <?php if ($severity_filter != 'all'): ?>
                        | Severity: <?php echo ucfirst($severity_filter); ?>
                    <?php endif; ?>
                </p>
                <p class="text-center text-muted small">Generated on: <?php echo date('F j, Y g:i A'); ?></p>
            </div>
        </div>

        <?php if ($report_type == 'overview'): ?>
            <!-- Overview Report -->
            <div class="row">
                <div class="col-md-3 mb-4">
                    <div class="card bg-primary text-white">
                        <div class="card-body text-center">
                            <h3><?php echo $overview_stats['total_incidents']; ?></h3>
                            <p class="mb-0">Total Incidents</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 mb-4">
                    <div class="card bg-warning text-white">
                        <div class="card-body text-center">
                            <h3><?php echo $overview_stats['open_incidents'] + $overview_stats['investigating_incidents']; ?></h3>
                            <p class="mb-0">Pending Resolution</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 mb-4">
                    <div class="card bg-success text-white">
                        <div class="card-body text-center">
                            <h3><?php echo $overview_stats['closed_incidents']; ?></h3>
                            <p class="mb-0">Cases Closed</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 mb-4">
                    <div class="card bg-info text-white">
                        <div class="card-body text-center">
                            <h3><?php echo round($overview_stats['avg_resolution_days']); ?> days</h3>
                            <p class="mb-0">Avg Resolution Time</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Severity Distribution</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="severityChart" width="400" height="300"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Status Distribution</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="statusChart" width="400" height="300"></canvas>
                        </div>
                    </div>
                </div>
            </div>

        <?php elseif ($report_type == 'trends'): ?>
            <!-- Trends Report -->
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Monthly Incident Trends - <?php echo $year; ?></h5>
                </div>
                <div class="card-body">
                    <canvas id="trendsChart" width="400" height="200"></canvas>
                </div>
            </div>

        <?php elseif ($report_type == 'department'): ?>
            <!-- Department Report -->
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Department-wise Incident Analysis</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead class="bg-light">
                                <tr>
                                    <th>Department</th>
                                    <th>Incident Count</th>
                                    <th>Employees Involved</th>
                                    <th>Percentage</th>
                                    <th>Incident Rate*</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($dept_result->num_rows > 0): ?>
                                    <?php while ($dept = $dept_result->fetch_assoc()): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($dept['department_name']); ?></td>
                                            <td class="text-center"><?php echo $dept['incident_count']; ?></td>
                                            <td class="text-center"><?php echo $dept['employees_involved']; ?></td>
                                            <td class="text-center"><?php echo $dept['percentage']; ?>%</td>
                                            <td class="text-center">
                                                <?php
                                                // This would ideally be calculated based on department size
                                                $rate = 'N/A';
                                                echo $rate;
                                                ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="text-center">No department data available</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <small class="text-muted">*Incident Rate calculation requires department headcount data</small>
                </div>
            </div>

        <?php elseif ($report_type == 'resolution'): ?>
            <!-- Resolution Report -->
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Case Resolution Analysis</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead class="bg-light">
                                <tr>
                                    <th>Incident ID</th>
                                    <th>Employee</th>
                                    <th>Department</th>
                                    <th>Incident Date</th>
                                    <th>Action Date</th>
                                    <th>Resolution Days</th>
                                    <th>Action Taken</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($resolution_result->num_rows > 0): ?>
                                    <?php while ($case = $resolution_result->fetch_assoc()): ?>
                                        <tr>
                                            <td>#<?php echo $case['incident_id']; ?></td>
                                            <td><?php echo htmlspecialchars($case['first_name'] . ' ' . $case['last_name']); ?></td>
                                            <td><?php echo htmlspecialchars($case['department_name'] ?? 'No Department'); ?></td>
                                            <td><?php echo date('M j, Y', strtotime($case['incident_date'])); ?></td>
                                            <td><?php echo date('M j, Y', strtotime($case['action_date'])); ?></td>
                                            <td class="text-center">
                                                <span class="badge badge-<?php 
                                                    if ($case['resolution_days'] <= 7) echo 'success';
                                                    elseif ($case['resolution_days'] <= 30) echo 'warning';
                                                    else echo 'danger';
                                                ?>">
                                                    <?php echo $case['resolution_days']; ?> days
                                                </span>
                                            </td>
                                            <td class="text-capitalize">
                                                <?php echo str_replace('_', ' ', $case['action_type']); ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" class="text-center">No resolution data available for selected period</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        <?php endif; ?>

        <!-- Category Distribution (shown in all reports) -->
        <div class="card mt-4">
            <div class="card-header">
                <h5 class="card-title mb-0">Category Distribution - <?php echo $year; ?></h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead class="bg-light">
                            <tr>
                                <th>Category</th>
                                <th>Incident Count</th>
                                <th>Percentage</th>
                                <th>Trend</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($category_result->num_rows > 0): ?>
                                <?php mysqli_data_seek($category_result, 0); ?>
                                <?php while ($cat = $category_result->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($cat['category_name']); ?></td>
                                        <td class="text-center"><?php echo $cat['incident_count']; ?></td>
                                        <td class="text-center"><?php echo $cat['percentage']; ?>%</td>
                                        <td class="text-center">
                                            <?php
                                            // Simple trend indicator (would need previous year data for actual trend)
                                            echo '<span class="text-muted">N/A</span>';
                                            ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="text-center">No category data available</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
$(document).ready(function() {
    $('.select2').select2();

    <?php if ($report_type == 'overview'): ?>
    // Severity Distribution Chart
    var severityCtx = document.getElementById('severityChart').getContext('2d');
    var severityChart = new Chart(severityCtx, {
        type: 'doughnut',
        data: {
            labels: ['Low', 'Medium', 'High', 'Gross'],
            datasets: [{
                data: [
                    <?php echo $overview_stats['low_severity']; ?>,
                    <?php echo $overview_stats['medium_severity']; ?>,
                    <?php echo $overview_stats['high_severity']; ?>,
                    <?php echo $overview_stats['gross_severity']; ?>
                ],
                backgroundColor: [
                    '#28a745',
                    '#ffc107',
                    '#dc3545',
                    '#343a40'
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

    // Status Distribution Chart
    var statusCtx = document.getElementById('statusChart').getContext('2d');
    var statusChart = new Chart(statusCtx, {
        type: 'pie',
        data: {
            labels: ['Open', 'Under Investigation', 'Closed'],
            datasets: [{
                data: [
                    <?php echo $overview_stats['open_incidents']; ?>,
                    <?php echo $overview_stats['investigating_incidents']; ?>,
                    <?php echo $overview_stats['closed_incidents']; ?>
                ],
                backgroundColor: [
                    '#007bff',
                    '#ffc107',
                    '#28a745'
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

    <?php elseif ($report_type == 'trends'): ?>
    // Trends Chart
    var trendsCtx = document.getElementById('trendsChart').getContext('2d');
    var trendsChart = new Chart(trendsCtx, {
        type: 'line',
        data: {
            labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
            datasets: [{
                label: 'Total Incidents',
                data: [
                    <?php for ($i = 1; $i <= 12; $i++): ?>
                        <?php echo isset($monthly_data[$i]) ? $monthly_data[$i]['incident_count'] : 0; ?>,
                    <?php endfor; ?>
                ],
                borderColor: '#007bff',
                backgroundColor: 'rgba(0, 123, 255, 0.1)',
                fill: true
            }, {
                label: 'Serious Incidents',
                data: [
                    <?php for ($i = 1; $i <= 12; $i++): ?>
                        <?php echo isset($monthly_data[$i]) ? $monthly_data[$i]['serious_count'] : 0; ?>,
                    <?php endfor; ?>
                ],
                borderColor: '#dc3545',
                backgroundColor: 'rgba(220, 53, 69, 0.1)',
                fill: true
            }]
        },
        options: {
            responsive: true,
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Number of Incidents'
                    }
                }
            }
        }
    });
    <?php endif; ?>
});

function printReport() {
    window.print();
}

function exportToExcel() {
    // Simple table export (you might want to use a library for more complex exports)
    alert('Export to Excel functionality would be implemented here. This could use libraries like SheetJS or TableExport.');
}
</script>

<style>
@media print {
    .card-header, .btn, .form-group, .card-tools {
        display: none !important;
    }
    .card {
        border: none !important;
        box-shadow: none !important;
    }
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Get dashboard statistics
$stats_sql = "
    SELECT 
        (SELECT COUNT(*) FROM employees WHERE employment_status = 'Active') as active_employees,
        (SELECT COUNT(*) FROM employees WHERE employment_status = 'Inactive') as inactive_employees,
        (SELECT COUNT(*) FROM employees WHERE employment_status = 'Terminated') as terminated_employees,
        (SELECT COUNT(*) FROM leave_requests WHERE status = 'pending') as pending_leaves,
        (SELECT COUNT(*) FROM payroll_periods WHERE status = 'open') as open_payrolls,
        (SELECT COUNT(*) FROM departments WHERE department_is_active = 1) as total_departments,
        (SELECT COUNT(*) FROM employees WHERE DATE(hire_date) = CURDATE()) as new_hires_today,
        (SELECT COUNT(*) FROM attendance_logs WHERE DATE(log_date) = CURDATE()) as today_attendance
";

$stats_result = $mysqli->query($stats_sql);
$stats = $stats_result->fetch_assoc();

// Get recent employees
$recent_employees_sql = "
    SELECT e.*, d.department_name, j.title as job_title 
    FROM employees e 
    LEFT JOIN departments d ON e.department_id = d.department_id 
    LEFT JOIN job_titles j ON e.job_title_id = j.job_title_id 
    ORDER BY e.created_at DESC 
    LIMIT 5
";
$recent_employees = $mysqli->query($recent_employees_sql);

// Get pending leave requests
$pending_leaves_sql = "
    SELECT lr.*, e.first_name, e.last_name, lt.name as leave_type 
    FROM leave_requests lr 
    JOIN employees e ON lr.employee_id = e.employee_id 
    JOIN leave_types lt ON lr.leave_type_id = lt.leave_type_id 
    WHERE lr.status = 'pending' 
    ORDER BY lr.start_date ASC 
    LIMIT 5
";
$pending_leaves = $mysqli->query($pending_leaves_sql);

// Get today's attendance
$today_attendance_sql = "
    SELECT a.*, e.first_name, e.last_name, e.employee_number
    FROM attendance_logs a
    JOIN employees e ON a.employee_id = e.employee_id
    WHERE a.log_date = CURDATE()
    ORDER BY a.check_in DESC
    LIMIT 5
";
$today_attendance = $mysqli->query($today_attendance_sql);

// Get recent audit logs
$audit_logs_sql = "
    SELECT al.*, u.user_name 
    FROM hr_audit_log al 
    LEFT JOIN users u ON al.user_id = u.user_id 
    ORDER BY al.timestamp DESC 
    LIMIT 10
";
$audit_logs = $mysqli->query($audit_logs_sql);
?>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">
            <i class="fas fa-tachometer-alt mr-2"></i>HR Dashboard
        </h1>
        <div>
            <a href="add_employee.php" class="d-none d-sm-inline-block btn btn-sm btn-success shadow-sm">
                <i class="fas fa-user-plus fa-sm text-white-50 mr-1"></i> Add Employee
            </a>
            <a href="reports.php" class="d-none d-sm-inline-block btn btn-sm btn-info shadow-sm ml-2">
                <i class="fas fa-chart-bar fa-sm text-white-50 mr-1"></i> Reports
            </a>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row">
        <div class="col-xl-2 col-md-4 mb-4">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                Active Employees</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $stats['active_employees']; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-users fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-md-4 mb-4">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                Departments</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $stats['total_departments']; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-building fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-md-4 mb-4">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                Pending Leaves</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $stats['pending_leaves']; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-calendar-alt fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-md-4 mb-4">
            <div class="card border-left-info shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                Today's Attendance</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $stats['today_attendance']; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-clock fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-md-4 mb-4">
            <div class="card border-left-danger shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">
                                Terminated</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $stats['terminated_employees']; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-user-slash fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-md-4 mb-4">
            <div class="card border-left-secondary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-secondary text-uppercase mb-1">
                                Open Payroll</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $stats['open_payrolls']; ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-money-bill-wave fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card shadow">
                <div class="card-header bg-white py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Quick Actions</h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-2 mb-3">
                            <a href="add_employee.php" class="btn btn-success btn-block">
                                <i class="fas fa-user-plus mr-2"></i>Add Employee
                            </a>
                        </div>
                        <div class="col-md-2 mb-3">
                            <a href="manage_employees.php" class="btn btn-primary btn-block">
                                <i class="fas fa-users mr-2"></i>Manage Employees
                            </a>
                        </div>
                        <div class="col-md-2 mb-3">
                            <a href="leave_management.php" class="btn btn-warning btn-block">
                                <i class="fas fa-calendar-alt mr-2"></i>Leave Management
                            </a>
                        </div>
                        <div class="col-md-2 mb-3">
                            <a href="payroll_management.php" class="btn btn-info btn-block">
                                <i class="fas fa-money-bill-wave mr-2"></i>Payroll
                            </a>
                        </div>
                        <div class="col-md-2 mb-3">
                            <a href="attendance.php" class="btn btn-secondary btn-block">
                                <i class="fas fa-clock mr-2"></i>Attendance
                            </a>
                        </div>
                        <div class="col-md-2 mb-3">
                            <a href="training_dashboard.php" class="btn btn-dark btn-block">
                                <i class="fas fa-chart-bar mr-2"></i>Trainings
                            </a>
                        </div>
                       
                            <div class="col-md-2 mb-3">
                            <a href=" misconduct_dashboard.php" class="btn btn-dark btn-block">
                                <i class="fas fa-chart-bar mr-2"></i> Misconduct
                            </a>
                        </div>
                          <div class="col-md-2 mb-3">
                            <a href="reports.php" class="btn btn-dark btn-block">
                                <i class="fas fa-chart-bar mr-2"></i>Reports
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Recent Employees -->
        <div class="col-xl-4 col-lg-6">
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                    <h6 class="m-0 font-weight-bold text-primary">Recent Employees</h6>
                    <a href="manage_employees.php" class="btn btn-sm btn-primary">View All</a>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Department</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($employee = $recent_employees->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($employee['employee_number']); ?></td>
                                    <td><?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?></td>
                                    <td><?php echo htmlspecialchars($employee['department_name'] ?? 'N/A'); ?></td>
                                    <td>
                                        <span class="badge badge-<?php 
                                            switch($employee['employment_status']) {
                                                case 'Active': echo 'success'; break;
                                                case 'Inactive': echo 'warning'; break;
                                                case 'Terminated': echo 'danger'; break;
                                                case 'Suspended': echo 'secondary'; break;
                                                default: echo 'info';
                                            }
                                        ?>">
                                            <?php echo htmlspecialchars($employee['employment_status']); ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pending Leave Requests -->
        <div class="col-xl-4 col-lg-6">
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                    <h6 class="m-0 font-weight-bold text-warning">Pending Leave Requests</h6>
                    <a href="leave_management.php" class="btn btn-sm btn-warning">View All</a>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Leave Type</th>
                                    <th>Dates</th>
                                    <th>Days</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($leave = $pending_leaves->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($leave['first_name'] . ' ' . $leave['last_name']); ?></td>
                                    <td><?php echo htmlspecialchars($leave['leave_type']); ?></td>
                                    <td>
                                        <?php echo date('M j', strtotime($leave['start_date'])); ?> - 
                                        <?php echo date('M j', strtotime($leave['end_date'])); ?>
                                    </td>
                                    <td><span class="badge badge-info"><?php echo htmlspecialchars($leave['days_requested']); ?></span></td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Today's Attendance -->
        <div class="col-xl-4 col-lg-6">
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                    <h6 class="m-0 font-weight-bold text-info">Today's Attendance</h6>
                    <a href="attendance.php" class="btn btn-sm btn-info">View All</a>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Check In</th>
                                    <th>Check Out</th>
                                    <th>Hours</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($attendance = $today_attendance->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($attendance['first_name'] . ' ' . $attendance['last_name']); ?></td>
                                    <td><?php echo $attendance['check_in'] ? date('H:i', strtotime($attendance['check_in'])) : '-'; ?></td>
                                    <td><?php echo $attendance['check_out'] ? date('H:i', strtotime($attendance['check_out'])) : '-'; ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo $attendance['hours_worked'] >= 8 ? 'success' : 'warning'; ?>">
                                            <?php echo $attendance['hours_worked'] ? number_format($attendance['hours_worked'], 1) : '-'; ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Audit Log -->
    <div class="row">
        <div class="col-12">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Recent Activity</h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                            <thead>
                                <tr>
                                    <th>Time</th>
                                    <th>User</th>
                                    <th>Action</th>
                                    <th>Description</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($log = $audit_logs->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo date('M j, H:i', strtotime($log['timestamp'])); ?></td>
                                    <td><?php echo htmlspecialchars($log['user_name'] ?? 'System'); ?></td>
                                    <td>
                                        <span class="badge badge-info"><?php echo htmlspecialchars($log['action']); ?></span>
                                    </td>
                                    <td><?php echo htmlspecialchars($log['description']); ?></td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
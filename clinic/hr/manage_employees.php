<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Handle actions
if (isset($_GET['action']) && isset($_GET['id'])) {
    $employee_id = intval($_GET['id']);
    
    if ($_GET['action'] == 'delete') {
        // Soft delete - update employment status
        $sql = "UPDATE employees SET employment_status = 'Terminated' WHERE employee_id = ?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("i", $employee_id);
        
        if ($stmt->execute()) {
            // Log the action
            $audit_sql = "INSERT INTO hr_audit_log (user_id, action, description, table_name, record_id) VALUES (?, 'employee_terminated', ?, 'employees', ?)";
            $audit_stmt = $mysqli->prepare($audit_sql);
            $description = "Terminated employee ID: $employee_id";
            $audit_stmt->bind_param("isi", $_SESSION['user_id'], $description, $employee_id);
            $audit_stmt->execute();
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Employee terminated successfully!";
        } else {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error terminating employee: " . $stmt->error;
        }
        header("Location: manage_employees.php");
        exit;
    }
}

// Default Column Sortby/Order Filter
$sort = "e.first_name";
$order = "ASC";

// Get filter parameters
$department_filter = $_GET['department'] ?? '';
$status_filter = $_GET['status'] ?? 'all';
$search = $_GET['search'] ?? '';

// Date Range for Employees (hire date)
$dtf = sanitizeInput($_GET['dtf'] ?? date('Y-m-01'));
$dtt = sanitizeInput($_GET['dtt'] ?? date('Y-m-d'));

// Build query with filters
$sql = "SELECT SQL_CALC_FOUND_ROWS e.*, d.department_name, j.title as job_title 
        FROM employees e 
        LEFT JOIN departments d ON e.department_id = d.department_id 
        LEFT JOIN job_titles j ON e.job_title_id = j.job_title_id 
        WHERE DATE(e.hire_date) BETWEEN '$dtf' AND '$dtt'";

$params = [];
$types = '';

if (!empty($department_filter)) {
    $sql .= " AND e.department_id = ?";
    $params[] = $department_filter;
    $types .= 'i';
}

if (!empty($status_filter) && $status_filter != 'all') {
    $sql .= " AND e.employment_status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

if (!empty($search)) {
    $sql .= " AND (e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_number LIKE ? OR e.national_id LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= 'ssss';
}

$sql .= " ORDER BY $sort $order LIMIT $record_from, $record_to";

$stmt = $mysqli->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$employees_result = $stmt->get_result();

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get statistics for dashboard
$total_employees = $num_rows[0];
$active_employees = 0;
$inactive_employees = 0;
$suspended_employees = 0;
$terminated_employees = 0;
$today_hires = 0;

// Reset pointer and calculate
mysqli_data_seek($employees_result, 0);
while ($employee = mysqli_fetch_assoc($employees_result)) {
    switch($employee['employment_status']) {
        case 'Active':
            $active_employees++;
            break;
        case 'Inactive':
            $inactive_employees++;
            break;
        case 'Suspended':
            $suspended_employees++;
            break;
        case 'Terminated':
            $terminated_employees++;
            break;
    }
    
    if (date('Y-m-d', strtotime($employee['hire_date'])) == date('Y-m-d')) {
        $today_hires++;
    }
}
mysqli_data_seek($employees_result, $record_from);

// Get departments for filter
$departments_sql = "SELECT department_id, department_name FROM departments WHERE department_is_active = 1 ORDER BY department_name";
$departments_result = $mysqli->query($departments_sql);
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0"><i class="fas fa-fw fa-users mr-2"></i>Manage Employees</h3>
        <div class="card-tools">
            <a href="hr_dashboard.php" class="btn btn-light">
                <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
            </a>
        </div>
    </div>

    <div class="card-header pb-2 pt-3">
        <form autocomplete="off">
            <div class="row">
                <div class="col-md-5">
                    <div class="form-group">
                        <div class="input-group">
                            <input type="search" class="form-control" name="search" value="<?php if (isset($search)) { echo stripslashes(nullable_htmlentities($search)); } ?>" placeholder="Search employees, ID numbers, national IDs..." autofocus>
                            <div class="input-group-append">
                                <button class="btn btn-secondary" type="button" data-toggle="collapse" data-target="#advancedFilter"><i class="fas fa-filter"></i></button>
                                <button class="btn btn-primary"><i class="fa fa-search"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-7">
                    <div class="btn-toolbar form-group float-right">
                        <div class="btn-group mr-2">
                            <a href="add_employee.php" class="btn btn-success">
                                <i class="fas fa-user-plus mr-2"></i>Add Employee
                            </a>
                        </div>
                        <div class="btn-group mr-2">
                            <span class="btn btn-light border">
                                <i class="fas fa-users text-primary mr-1"></i>
                                Total: <strong><?php echo $total_employees; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-check-circle text-success mr-1"></i>
                                Active: <strong><?php echo $active_employees; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-pause-circle text-warning mr-1"></i>
                                Inactive: <strong><?php echo $inactive_employees; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-ban text-danger mr-1"></i>
                                Terminated: <strong><?php echo $terminated_employees; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-calendar-day text-info mr-1"></i>
                                Today Hires: <strong><?php echo $today_hires; ?></strong>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php if (isset($_GET['dtf']) || $status_filter != 'all' || $department_filter) { echo "show"; } ?>" id="advancedFilter">
                <div class="row">
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Date Range</label>
                            <select onchange="this.form.submit()" class="form-control select2" name="canned_date">
                                <option <?php if (($_GET['canned_date'] ?? '') == "custom") { echo "selected"; } ?> value="custom">Custom</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "today") { echo "selected"; } ?> value="today">Today</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "yesterday") { echo "selected"; } ?> value="yesterday">Yesterday</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "thisweek") { echo "selected"; } ?> value="thisweek">This Week</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "lastweek") { echo "selected"; } ?> value="lastweek">Last Week</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "thismonth") { echo "selected"; } ?> value="thismonth">This Month</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "lastmonth") { echo "selected"; } ?> value="lastmonth">Last Month</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Date from</label>
                            <input onchange="this.form.submit()" type="date" class="form-control" name="dtf" max="2999-12-31" value="<?php echo nullable_htmlentities($dtf); ?>">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Date to</label>
                            <input onchange="this.form.submit()" type="date" class="form-control" name="dtt" max="2999-12-31" value="<?php echo nullable_htmlentities($dtt); ?>">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Employment Status</label>
                            <select class="form-control select2" name="status" onchange="this.form.submit()">
                                <option value="all" <?php if ($status_filter == "all") { echo "selected"; } ?>>- All Statuses -</option>
                                <option value="Active" <?php if ($status_filter == "Active") { echo "selected"; } ?>>Active</option>
                                <option value="Inactive" <?php if ($status_filter == "Inactive") { echo "selected"; } ?>>Inactive</option>
                                <option value="Suspended" <?php if ($status_filter == "Suspended") { echo "selected"; } ?>>Suspended</option>
                                <option value="Terminated" <?php if ($status_filter == "Terminated") { echo "selected"; } ?>>Terminated</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Department</label>
                            <select class="form-control select2" name="department" onchange="this.form.submit()">
                                <option value="">- All Departments -</option>
                                <?php
                                while ($row = mysqli_fetch_array($departments_result)) {
                                    $department_id = intval($row['department_id']);
                                    $department_name = nullable_htmlentities($row['department_name']);
                                ?>
                                    <option value="<?php echo $department_id; ?>" <?php if ($department_id == $department_filter) { echo "selected"; } ?>>
                                        <?php echo $department_name; ?>
                                    </option>
                                <?php
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
    
    <?php if (isset($_SESSION['alert_message'])): ?>
        <div class="alert alert-<?php echo $_SESSION['alert_type']; ?> alert-dismissible m-3">
            <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
            <i class="icon fas fa-<?php echo $_SESSION['alert_type'] == 'success' ? 'check' : 'exclamation-triangle'; ?>"></i>
            <?php echo $_SESSION['alert_message']; ?>
        </div>
        <?php 
        unset($_SESSION['alert_type']);
        unset($_SESSION['alert_message']);
        ?>
    <?php endif; ?>
    
    <div class="table-responsive-sm">
        <table class="table table-hover mb-0">
            <thead class="<?php if ($num_rows[0] == 0) { echo "d-none"; } ?> bg-light">
            <tr>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=e.employee_number&order=<?php echo $disp; ?>">
                        Employee ID <?php if ($sort == 'e.employee_number') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=e.first_name&order=<?php echo $disp; ?>">
                        Employee Name <?php if ($sort == 'e.first_name') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>Department</th>
                <th>Job Title</th>
                <th>Phone</th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=e.hire_date&order=<?php echo $disp; ?>">
                        Hire Date <?php if ($sort == 'e.hire_date') { echo $order_icon; } ?>
                    </a>
                </th>
                <th class="text-right">Basic Salary</th>
                <th>Status</th>
                <th class="text-center">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php
            while ($row = mysqli_fetch_array($employees_result)) {
                $employee_id = intval($row['employee_id']);
                $employee_number = nullable_htmlentities($row['employee_number']);
                $first_name = nullable_htmlentities($row['first_name']);
                $last_name = nullable_htmlentities($row['last_name']);
                $national_id = nullable_htmlentities($row['national_id']);
                $department_name = nullable_htmlentities($row['department_name'] ?? 'N/A');
                $job_title = nullable_htmlentities($row['job_title'] ?? 'N/A');
                $phone = nullable_htmlentities($row['phone'] ?? 'N/A');
                $hire_date = nullable_htmlentities($row['hire_date']);
                $basic_salary = floatval($row['basic_salary']);
                $employment_status = nullable_htmlentities($row['employment_status']);

                // Status badge styling
                $status_badge = "";
                switch($employment_status) {
                    case 'Active':
                        $status_badge = "badge-success";
                        break;
                    case 'Inactive':
                        $status_badge = "badge-warning";
                        break;
                    case 'Suspended':
                        $status_badge = "badge-secondary";
                        break;
                    case 'Terminated':
                        $status_badge = "badge-danger";
                        break;
                    default:
                        $status_badge = "badge-light";
                }
                ?>
                <tr>
                    <td class="font-weight-bold text-primary"><?php echo $employee_number; ?></td>
                    <td>
                        <div class="font-weight-bold"><?php echo $first_name . ' ' . $last_name; ?></div>
                        <small class="text-muted">ID: <?php echo $national_id; ?></small>
                    </td>
                    <td><?php echo $department_name; ?></td>
                    <td><?php echo $job_title; ?></td>
                    <td><?php echo $phone; ?></td>
                    <td>
                        <div class="font-weight-bold"><?php echo date('M j, Y', strtotime($hire_date)); ?></div>
                        <small class="text-muted"><?php echo date('Y-m-d', strtotime($hire_date)); ?></small>
                    </td>
                    <td class="text-right font-weight-bold">KES <?php echo number_format($basic_salary, 2); ?></td>
                    <td>
                        <span class="badge <?php echo $status_badge; ?>"><?php echo ucfirst($employment_status); ?></span>
                    </td>
                    <td>
                        <div class="dropdown dropleft text-center">
                            <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                <i class="fas fa-ellipsis-h"></i>
                            </button>
                            <div class="dropdown-menu">
                                <a class="dropdown-item" href="view_employee.php?id=<?php echo $employee_id; ?>">
                                    <i class="fas fa-fw fa-eye mr-2"></i>View Details
                                </a>
                                <a class="dropdown-item" href="edit_employee.php?id=<?php echo $employee_id; ?>">
                                    <i class="fas fa-fw fa-edit mr-2"></i>Edit Employee
                                </a>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item" href="employee_payroll.php?id=<?php echo $employee_id; ?>">
                                    <i class="fas fa-fw fa-money-bill mr-2"></i>Payroll Details
                                </a>
                                <a class="dropdown-item" href="employee_attendance.php?id=<?php echo $employee_id; ?>">
                                    <i class="fas fa-fw fa-calendar-alt mr-2"></i>Attendance
                                </a>
                                <?php if ($employment_status == 'Active'): ?>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item text-danger confirm-link" href="manage_employees.php?action=delete&id=<?php echo $employee_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token'] ?>" data-confirm-message="Are you sure you want to terminate <?php echo htmlspecialchars($first_name . ' ' . $last_name); ?>?">
                                        <i class="fas fa-fw fa-user-slash mr-2"></i>Terminate Employee
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php
            } 
            
            if ($num_rows[0] == 0) {
                ?>
                <tr>
                    <td colspan="9" class="text-center py-4">
                        <i class="fas fa-users fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No employees found</h5>
                        <p class="text-muted">No employees match your current filters.</p>
                        <a href="?status=all" class="btn btn-primary mt-2">
                            <i class="fas fa-redo mr-2"></i>Reset Filters
                        </a>
                    </td>
                </tr>
                <?php
            }
            ?>
            </tbody>
        </table>
    </div>
    
    <!-- Ends Card Body -->
    <?php 
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/filter_footer.php';
    ?>
</div>

<script>
$(document).ready(function() {
    $('.select2').select2();

    // Auto-submit when date range changes
    $('input[type="date"]').change(function() {
        if ($(this).val()) {
            $(this).closest('form').submit();
        }
    });

    // Auto-submit date range when canned date is selected
    $('select[name="canned_date"]').change(function() {
        if ($(this).val() !== 'custom') {
            $(this).closest('form').submit();
        }
    });

    // Keyboard shortcuts
    $(document).keydown(function(e) {
        // Ctrl + F for focus search
        if (e.ctrlKey && e.keyCode === 70) {
            e.preventDefault();
            $('input[name="search"]').focus();
        }
    });

    // Confirm links
    $('.confirm-link').click(function(e) {
        e.preventDefault();
        var message = $(this).data('confirm-message') || 'Are you sure?';
        var href = $(this).attr('href');
        
        if (confirm(message)) {
            window.location.href = href;
        }
    });
});
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
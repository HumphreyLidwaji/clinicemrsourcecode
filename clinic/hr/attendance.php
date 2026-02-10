<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Handle manual attendance entry
if (isset($_POST['action']) && $_POST['action'] == 'manual_entry') {
    $employee_id = intval($_POST['employee_id']);
    $log_date = $_POST['log_date'];
    $check_in = $_POST['check_in'];
    $check_out = $_POST['check_out'];
    
    // Calculate hours worked
    $hours_worked = 0;
    if ($check_in && $check_out) {
        $start = new DateTime($check_in);
        $end = new DateTime($check_out);
        $diff = $start->diff($end);
        $hours_worked = $diff->h + ($diff->i / 60);
    }
    
    // Check if record already exists
    $check_sql = "SELECT attendance_id FROM attendance_logs WHERE employee_id = ? AND log_date = ?";
    $check_stmt = $mysqli->prepare($check_sql);
    $check_stmt->bind_param("is", $employee_id, $log_date);
    $check_stmt->execute();
    
    if ($check_stmt->get_result()->num_rows > 0) {
        // Update existing record
        $sql = "UPDATE attendance_logs SET check_in = ?, check_out = ?, hours_worked = ?, source = 'manual' WHERE employee_id = ? AND log_date = ?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("ssdis", $check_in, $check_out, $hours_worked, $employee_id, $log_date);
    } else {
        // Insert new record
        $sql = "INSERT INTO attendance_logs (employee_id, log_date, check_in, check_out, hours_worked, source) VALUES (?, ?, ?, ?, ?, 'manual')";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("isssd", $employee_id, $log_date, $check_in, $check_out, $hours_worked);
    }
    
    if ($stmt->execute()) {
        // Log the action
        $audit_sql = "INSERT INTO hr_audit_log (user_id, action, description, table_name, record_id) VALUES (?, 'attendance_manual_entry', ?, 'attendance_logs', ?)";
        $audit_stmt = $mysqli->prepare($audit_sql);
        $description = "Manual attendance entry for employee ID: $employee_id on $log_date";
        $audit_stmt->bind_param("isi", $_SESSION['user_id'], $description, $mysqli->insert_id);
        $audit_stmt->execute();
        
        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Attendance record saved successfully!";
    } else {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error saving attendance record: " . $stmt->error;
    }
    header("Location: attendance.php");
    exit;
}

// Default Column Sortby/Order Filter
$sort = "a.check_in";
$order = "DESC";

// Get filter parameters
$date_filter = $_GET['date'] ?? date('Y-m-d');
$employee_filter = $_GET['employee'] ?? '';
$department_filter = $_GET['department'] ?? '';
$status_filter = $_GET['status'] ?? 'all';

// Date Range for Attendance
$dtf = sanitizeInput($_GET['dtf'] ?? date('Y-m-d'));
$dtt = sanitizeInput($_GET['dtt'] ?? date('Y-m-d'));

// Build query for attendance records
$sql = "SELECT SQL_CALC_FOUND_ROWS a.*, e.first_name, e.last_name, e.employee_number, d.department_name 
        FROM attendance_logs a 
        JOIN employees e ON a.employee_id = e.employee_id 
        LEFT JOIN departments d ON e.department_id = d.department_id 
        WHERE DATE(a.log_date) BETWEEN '$dtf' AND '$dtt'";

$params = [];
$types = '';

if (!empty($employee_filter)) {
    $sql .= " AND a.employee_id = ?";
    $params[] = $employee_filter;
    $types .= 'i';
}

if (!empty($department_filter)) {
    $sql .= " AND e.department_id = ?";
    $params[] = $department_filter;
    $types .= 'i';
}

if (!empty($status_filter) && $status_filter != 'all') {
    if ($status_filter == 'present') {
        $sql .= " AND a.check_in IS NOT NULL";
    } elseif ($status_filter == 'absent') {
        $sql .= " AND a.check_in IS NULL";
    } elseif ($status_filter == 'active') {
        $sql .= " AND a.check_in IS NOT NULL AND a.check_out IS NULL";
    } elseif ($status_filter == 'completed') {
        $sql .= " AND a.check_in IS NOT NULL AND a.check_out IS NOT NULL";
    }
}

$sql .= " ORDER BY $sort $order LIMIT $record_from, $record_to";

$stmt = $mysqli->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$attendance_result = $stmt->get_result();

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get employees for filters
$employees_sql = "SELECT employee_id, first_name, last_name, employee_number FROM employees WHERE employment_status = 'Active' ORDER BY first_name, last_name";
$employees_result = $mysqli->query($employees_sql);

// Get departments for filter
$departments_sql = "SELECT department_id, department_name FROM departments WHERE department_is_active = 1 ORDER BY department_name";
$departments_result = $mysqli->query($departments_sql);

// Get attendance statistics
$total_records = $num_rows[0];
$present_count = 0;
$absent_count = 0;
$active_count = 0;
$completed_count = 0;
$today_count = 0;

// Reset pointer and calculate
mysqli_data_seek($attendance_result, 0);
while ($attendance = mysqli_fetch_assoc($attendance_result)) {
    if ($attendance['check_in']) {
        $present_count++;
        if ($attendance['check_out']) {
            $completed_count++;
        } else {
            $active_count++;
        }
    } else {
        $absent_count++;
    }
    
    if (date('Y-m-d', strtotime($attendance['log_date'])) == date('Y-m-d')) {
        $today_count++;
    }
}
mysqli_data_seek($attendance_result, $record_from);
?>

<div class="card">
    <div class="card-header bg-secondary py-2">
        <h3 class="card-title mt-2 mb-0"><i class="fas fa-fw fa-clock mr-2"></i>Attendance Management</h3>
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
                            <input type="search" class="form-control" name="search" value="<?php if (isset($search)) { echo stripslashes(nullable_htmlentities($search)); } ?>" placeholder="Search employees..." autofocus>
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
                            <button type="button" class="btn btn-success" data-toggle="modal" data-target="#manualEntryModal">
                                <i class="fas fa-plus mr-2"></i>Manual Entry
                            </button>
                        </div>
                        <div class="btn-group mr-2">
                            <span class="btn btn-light border">
                                <i class="fas fa-clock text-primary mr-1"></i>
                                Total: <strong><?php echo $total_records; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-check-circle text-success mr-1"></i>
                                Present: <strong><?php echo $present_count; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-times-circle text-danger mr-1"></i>
                                Absent: <strong><?php echo $absent_count; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-user-clock text-warning mr-1"></i>
                                Active: <strong><?php echo $active_count; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-calendar-day text-info mr-1"></i>
                                Today: <strong><?php echo $today_count; ?></strong>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php if (isset($_GET['dtf']) || $employee_filter || $department_filter || $status_filter != 'all') { echo "show"; } ?>" id="advancedFilter">
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
                            <label>Status</label>
                            <select class="form-control select2" name="status" onchange="this.form.submit()">
                                <option value="all" <?php if ($status_filter == "all") { echo "selected"; } ?>>- All Statuses -</option>
                                <option value="present" <?php if ($status_filter == "present") { echo "selected"; } ?>>Present</option>
                                <option value="absent" <?php if ($status_filter == "absent") { echo "selected"; } ?>>Absent</option>
                                <option value="active" <?php if ($status_filter == "active") { echo "selected"; } ?>>Active (Checked In)</option>
                                <option value="completed" <?php if ($status_filter == "completed") { echo "selected"; } ?>>Completed</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Employee</label>
                            <select class="form-control select2" name="employee" onchange="this.form.submit()">
                                <option value="">- All Employees -</option>
                                <?php
                                while ($row = mysqli_fetch_array($employees_result)) {
                                    $employee_id = intval($row['employee_id']);
                                    $employee_name = nullable_htmlentities($row['first_name'] . ' ' . $row['last_name']);
                                    $employee_number = nullable_htmlentities($row['employee_number']);
                                ?>
                                    <option value="<?php echo $employee_id; ?>" <?php if ($employee_id == $employee_filter) { echo "selected"; } ?>>
                                        <?php echo "$employee_name ($employee_number)"; ?>
                                    </option>
                                <?php
                                }
                                ?>
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
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=e.first_name&order=<?php echo $disp; ?>">
                        Employee <?php if ($sort == 'e.first_name') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>Department</th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=a.log_date&order=<?php echo $disp; ?>">
                        Date <?php if ($sort == 'a.log_date') { echo $order_icon; } ?>
                    </a>
                </th>
                <th class="text-center">Check In</th>
                <th class="text-center">Check Out</th>
                <th class="text-center">Hours</th>
                <th>Source</th>
                <th>Status</th>
                <th class="text-center">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php
            while ($row = mysqli_fetch_array($attendance_result)) {
                $attendance_id = intval($row['attendance_id']);
                $employee_name = nullable_htmlentities($row['first_name'] . ' ' . $row['last_name']);
                $employee_number = nullable_htmlentities($row['employee_number']);
                $department_name = nullable_htmlentities($row['department_name'] ?? 'N/A');
                $log_date = nullable_htmlentities($row['log_date']);
                $check_in = nullable_htmlentities($row['check_in']);
                $check_out = nullable_htmlentities($row['check_out']);
                $hours_worked = floatval($row['hours_worked']);
                $source = nullable_htmlentities($row['source']);
                ?>
                <tr>
                    <td>
                        <div class="font-weight-bold"><?php echo $employee_name; ?></div>
                        <small class="text-muted"><?php echo $employee_number; ?></small>
                    </td>
                    <td><?php echo $department_name; ?></td>
                    <td>
                        <div class="font-weight-bold"><?php echo date('M j, Y', strtotime($log_date)); ?></div>
                        <small class="text-muted"><?php echo date('D', strtotime($log_date)); ?></small>
                    </td>
                    <td class="text-center">
                        <?php if ($check_in): ?>
                            <span class="badge badge-success"><?php echo date('H:i', strtotime($check_in)); ?></span>
                        <?php else: ?>
                            <span class="badge badge-danger">Absent</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?php if ($check_out): ?>
                            <span class="badge badge-info"><?php echo date('H:i', strtotime($check_out)); ?></span>
                        <?php else: ?>
                            <span class="badge badge-warning">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?php if ($hours_worked > 0): ?>
                            <span class="badge badge-<?php echo $hours_worked >= 8 ? 'success' : ($hours_worked >= 6 ? 'warning' : 'danger'); ?>">
                                <?php echo number_format($hours_worked, 1); ?>h
                            </span>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge badge-<?php 
                            switch($source) {
                                case 'biometric': echo 'primary'; break;
                                case 'manual': echo 'warning'; break;
                                case 'mobile': echo 'info'; break;
                                default: echo 'secondary';
                            }
                        ?>">
                            <?php echo ucfirst($source); ?>
                        </span>
                    </td>
                    <td>
                        <?php if (!$check_in): ?>
                            <span class="badge badge-danger">Absent</span>
                        <?php elseif (!$check_out): ?>
                            <span class="badge badge-warning">Active</span>
                        <?php else: ?>
                            <span class="badge badge-success">Completed</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="dropdown dropleft text-center">
                            <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                <i class="fas fa-ellipsis-h"></i>
                            </button>
                            <div class="dropdown-menu">
                                <a class="dropdown-item" href="#" data-toggle="modal" data-target="#editAttendanceModal"
                                   data-attendance-id="<?php echo $attendance_id; ?>"
                                   data-employee-name="<?php echo htmlspecialchars($employee_name); ?>"
                                   data-log-date="<?php echo $log_date; ?>"
                                   data-check-in="<?php echo $check_in ? date('H:i', strtotime($check_in)) : ''; ?>"
                                   data-check-out="<?php echo $check_out ? date('H:i', strtotime($check_out)) : ''; ?>">
                                    <i class="fas fa-fw fa-edit mr-2"></i>Edit Record
                                </a>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item text-danger confirm-link" href="post/attendance.php?delete=<?php echo $attendance_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token'] ?>" data-confirm-message="Delete this attendance record?">
                                    <i class="fas fa-fw fa-trash mr-2"></i>Delete Record
                                </a>
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
                        <i class="fas fa-clock fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No attendance records found</h5>
                        <p class="text-muted">No attendance records match your current filters.</p>
                        <button type="button" class="btn btn-primary mt-2" data-toggle="modal" data-target="#manualEntryModal">
                            <i class="fas fa-plus mr-2"></i>Add Manual Entry
                        </button>
                        <a href="?status=all" class="btn btn-outline-secondary mt-2">
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

<!-- Manual Entry Modal -->
<div class="modal fade" id="manualEntryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Manual Attendance Entry</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="manual_entry">
                    <div class="form-group">
                        <label>Employee *</label>
                        <select class="form-control select2" name="employee_id" required>
                            <option value="">Select Employee</option>
                            <?php 
                            $employees_result->data_seek(0);
                            while($emp = mysqli_fetch_array($employees_result)): 
                                $employee_id = intval($emp['employee_id']);
                                $employee_name = nullable_htmlentities($emp['first_name'] . ' ' . $emp['last_name']);
                                $employee_number = nullable_htmlentities($emp['employee_number']);
                            ?>
                                <option value="<?php echo $employee_id; ?>">
                                    <?php echo "$employee_name ($employee_number)"; ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Date *</label>
                        <input type="date" class="form-control" name="log_date" required value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Check In Time</label>
                                <input type="time" class="form-control" name="check_in" value="08:00">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Check Out Time</label>
                                <input type="time" class="form-control" name="check_out" value="17:00">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Record</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Attendance Modal -->
<div class="modal fade" id="editAttendanceModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning text-white">
                <h5 class="modal-title">Edit Attendance Record</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <form method="POST" action="post/attendance.php">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit_entry">
                    <input type="hidden" name="attendance_id" id="edit_attendance_id">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <div class="form-group">
                        <label>Employee</label>
                        <input type="text" class="form-control" id="edit_employee_name" readonly>
                    </div>
                    <div class="form-group">
                        <label>Date</label>
                        <input type="date" class="form-control" id="edit_log_date" readonly>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Check In Time</label>
                                <input type="time" class="form-control" name="check_in" id="edit_check_in">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Check Out Time</label>
                                <input type="time" class="form-control" name="check_out" id="edit_check_out">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Record</button>
                </div>
            </form>
        </div>
    </div>
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
        // Ctrl + M for manual entry
        if (e.ctrlKey && e.keyCode === 77) {
            e.preventDefault();
            $('#manualEntryModal').modal('show');
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

    // Edit Attendance Modal
    $('#editAttendanceModal').on('show.bs.modal', function (event) {
        var button = $(event.relatedTarget);
        var attendanceId = button.data('attendance-id');
        var employeeName = button.data('employee-name');
        var logDate = button.data('log-date');
        var checkIn = button.data('check-in');
        var checkOut = button.data('check-out');
        
        var modal = $(this);
        modal.find('#edit_attendance_id').val(attendanceId);
        modal.find('#edit_employee_name').val(employeeName);
        modal.find('#edit_log_date').val(logDate);
        modal.find('#edit_check_in').val(checkIn);
        modal.find('#edit_check_out').val(checkOut);
    });
});
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
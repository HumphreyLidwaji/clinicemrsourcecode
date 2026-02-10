<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Handle leave actions
if (isset($_POST['action']) && isset($_POST['leave_id'])) {
    $leave_id = intval($_POST['leave_id']);
    $action = $_POST['action'];
    
    if (in_array($action, ['approve', 'reject'])) {
        $status = $action == 'approve' ? 'approved' : 'rejected';
        $sql = "UPDATE leave_requests SET status = ?, approved_by = ?, approved_at = NOW() WHERE leave_id = ?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("sii", $status, $_SESSION['user_id'], $leave_id);
        
        if ($stmt->execute()) {
            // Log the action
            $audit_sql = "INSERT INTO hr_audit_log (user_id, action, description, table_name, record_id) VALUES (?, 'leave_'.$action.'ed', ?, 'leave_requests', ?)";
            $audit_stmt = $mysqli->prepare($audit_sql);
            $description = ucfirst($action) . " leave request ID: $leave_id";
            $audit_stmt->bind_param("isi", $_SESSION['user_id'], $description, $leave_id);
            $audit_stmt->execute();
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Leave request " . $action . "d successfully!";
        } else {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error updating leave request: " . $stmt->error;
        }
        header("Location: leave_management.php");
        exit;
    }
}

// Default Column Sortby/Order Filter
$sort = "lr.created_at";
$order = "DESC";

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$employee_filter = $_GET['employee'] ?? '';
$search = $_GET['search'] ?? '';

// Date Range for Leave Requests
$dtf = sanitizeInput($_GET['dtf'] ?? date('Y-m-01'));
$dtt = sanitizeInput($_GET['dtt'] ?? date('Y-m-d'));

// Build query for leave requests
$sql = "SELECT SQL_CALC_FOUND_ROWS lr.*, e.first_name, e.last_name, e.employee_number, 
               lt.name as leave_type, lt.default_days,
               a.first_name as approver_first, a.last_name as approver_last,
               d.department_name
        FROM leave_requests lr 
        JOIN employees e ON lr.employee_id = e.employee_id 
        JOIN leave_types lt ON lr.leave_type_id = lt.leave_type_id 
        LEFT JOIN employees a ON lr.approved_by = a.employee_id 
        LEFT JOIN departments d ON e.department_id = d.department_id 
        WHERE DATE(lr.created_at) BETWEEN '$dtf' AND '$dtt'";

$params = [];
$types = '';

if (!empty($status_filter) && $status_filter != 'all') {
    $sql .= " AND lr.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

if (!empty($employee_filter)) {
    $sql .= " AND lr.employee_id = ?";
    $params[] = $employee_filter;
    $types .= 'i';
}

if (!empty($search)) {
    $sql .= " AND (e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_number LIKE ? OR lt.name LIKE ?)";
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
$leave_requests = $stmt->get_result();

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get leave statistics
$total_leave_requests = $num_rows[0];
$pending_requests = 0;
$approved_requests = 0;
$rejected_requests = 0;
$cancelled_requests = 0;
$today_requests = 0;

// Reset pointer and calculate
mysqli_data_seek($leave_requests, 0);
while ($leave = mysqli_fetch_assoc($leave_requests)) {
    switch($leave['status']) {
        case 'pending':
            $pending_requests++;
            break;
        case 'approved':
            $approved_requests++;
            break;
        case 'rejected':
            $rejected_requests++;
            break;
        case 'cancelled':
            $cancelled_requests++;
            break;
    }
    
    if (date('Y-m-d', strtotime($leave['created_at'])) == date('Y-m-d')) {
        $today_requests++;
    }
}
mysqli_data_seek($leave_requests, $record_from);

// Get employees for filter
$employees_sql = "SELECT employee_id, first_name, last_name, employee_number FROM employees WHERE employment_status = 'Active' ORDER BY first_name, last_name";
$employees_result = $mysqli->query($employees_sql);
?>

<div class="card">
    <div class="card-header bg-warning py-2">
        <h3 class="card-title mt-2 mb-0"><i class="fas fa-fw fa-calendar-alt mr-2"></i>Leave Management</h3>
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
                            <input type="search" class="form-control" name="search" value="<?php if (isset($search)) { echo stripslashes(nullable_htmlentities($search)); } ?>" placeholder="Search employees, leave types..." autofocus>
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
                            <a href="apply_leave.php" class="btn btn-success">
                                <i class="fas fa-plus mr-2"></i>Apply Leave
                            </a>
                        </div>
                        <div class="btn-group mr-2">
                            <span class="btn btn-light border">
                                <i class="fas fa-calendar-alt text-primary mr-1"></i>
                                Total: <strong><?php echo $total_leave_requests; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-clock text-warning mr-1"></i>
                                Pending: <strong><?php echo $pending_requests; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-check-circle text-success mr-1"></i>
                                Approved: <strong><?php echo $approved_requests; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-times-circle text-danger mr-1"></i>
                                Rejected: <strong><?php echo $rejected_requests; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-calendar-day text-info mr-1"></i>
                                Today: <strong><?php echo $today_requests; ?></strong>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php if (isset($_GET['dtf']) || $status_filter != 'all' || $employee_filter) { echo "show"; } ?>" id="advancedFilter">
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
                            <label>Leave Status</label>
                            <select class="form-control select2" name="status" onchange="this.form.submit()">
                                <option value="all" <?php if ($status_filter == "all") { echo "selected"; } ?>>- All Statuses -</option>
                                <option value="pending" <?php if ($status_filter == "pending") { echo "selected"; } ?>>Pending</option>
                                <option value="approved" <?php if ($status_filter == "approved") { echo "selected"; } ?>>Approved</option>
                                <option value="rejected" <?php if ($status_filter == "rejected") { echo "selected"; } ?>>Rejected</option>
                                <option value="cancelled" <?php if ($status_filter == "cancelled") { echo "selected"; } ?>>Cancelled</option>
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
                <th>Leave Type</th>
                <th>Dates</th>
                <th class="text-center">Days</th>
                <th>Status</th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=lr.created_at&order=<?php echo $disp; ?>">
                        Applied On <?php if ($sort == 'lr.created_at') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>Approved By</th>
                <th class="text-center">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php
            while ($row = mysqli_fetch_array($leave_requests)) {
                $leave_id = intval($row['leave_id']);
                $employee_first_name = nullable_htmlentities($row['first_name']);
                $employee_last_name = nullable_htmlentities($row['last_name']);
                $employee_number = nullable_htmlentities($row['employee_number']);
                $department_name = nullable_htmlentities($row['department_name'] ?? 'N/A');
                $leave_type = nullable_htmlentities($row['leave_type']);
                $start_date = nullable_htmlentities($row['start_date']);
                $end_date = nullable_htmlentities($row['end_date']);
                $days_requested = intval($row['days_requested']);
                $status = nullable_htmlentities($row['status']);
                $created_at = nullable_htmlentities($row['created_at']);
                $reason = nullable_htmlentities($row['reason']);
                $approver_first = nullable_htmlentities($row['approver_first'] ?? '');
                $approver_last = nullable_htmlentities($row['approver_last'] ?? '');
                $approved_at = nullable_htmlentities($row['approved_at'] ?? '');

                // Status badge styling
                $status_badge = "";
                switch($status) {
                    case 'pending':
                        $status_badge = "badge-warning";
                        break;
                    case 'approved':
                        $status_badge = "badge-success";
                        break;
                    case 'rejected':
                        $status_badge = "badge-danger";
                        break;
                    case 'cancelled':
                        $status_badge = "badge-secondary";
                        break;
                    default:
                        $status_badge = "badge-light";
                }
                ?>
                <tr>
                    <td>
                        <div class="font-weight-bold"><?php echo $employee_first_name . ' ' . $employee_last_name; ?></div>
                        <small class="text-muted"><?php echo $employee_number; ?></small>
                    </td>
                    <td><?php echo $department_name; ?></td>
                    <td class="font-weight-bold text-info"><?php echo $leave_type; ?></td>
                    <td>
                        <div class="font-weight-bold"><?php echo date('M j, Y', strtotime($start_date)); ?></div>
                        <small class="text-muted">to <?php echo date('M j, Y', strtotime($end_date)); ?></small>
                    </td>
                    <td class="text-center">
                        <span class="badge badge-info"><?php echo $days_requested; ?></span>
                    </td>
                    <td>
                        <span class="badge <?php echo $status_badge; ?>"><?php echo ucfirst($status); ?></span>
                    </td>
                    <td>
                        <div class="font-weight-bold"><?php echo date('M j, Y', strtotime($created_at)); ?></div>
                        <small class="text-muted"><?php echo date('g:i A', strtotime($created_at)); ?></small>
                    </td>
                    <td>
                        <?php if ($approver_first): ?>
                            <div class="font-weight-bold"><?php echo $approver_first . ' ' . $approver_last; ?></div>
                            <small class="text-muted"><?php echo date('M j, Y', strtotime($approved_at)); ?></small>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="dropdown dropleft text-center">
                            <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                <i class="fas fa-ellipsis-h"></i>
                            </button>
                            <div class="dropdown-menu">
                                <a class="dropdown-item" href="#" data-toggle="modal" data-target="#viewLeaveModal<?php echo $leave_id; ?>">
                                    <i class="fas fa-fw fa-eye mr-2"></i>View Details
                                </a>
                                <?php if ($status == 'pending'): ?>
                                    <div class="dropdown-divider"></div>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="leave_id" value="<?php echo $leave_id; ?>">
                                        <input type="hidden" name="action" value="approve">
                                        <button type="submit" class="dropdown-item text-success" onclick="return confirm('Approve this leave request?')">
                                            <i class="fas fa-fw fa-check mr-2"></i>Approve Leave
                                        </button>
                                    </form>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="leave_id" value="<?php echo $leave_id; ?>">
                                        <input type="hidden" name="action" value="reject">
                                        <button type="submit" class="dropdown-item text-danger" onclick="return confirm('Reject this leave request?')">
                                            <i class="fas fa-fw fa-times mr-2"></i>Reject Leave
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item" href="employee_details.php?id=<?php echo $row['employee_id']; ?>">
                                    <i class="fas fa-fw fa-user mr-2"></i>View Employee
                                </a>
                            </div>
                        </div>

                        <!-- View Leave Modal -->
                        <div class="modal fade" id="viewLeaveModal<?php echo $leave_id; ?>" tabindex="-1">
                            <div class="modal-dialog modal-lg">
                                <div class="modal-content">
                                    <div class="modal-header bg-primary text-white">
                                        <h5 class="modal-title">Leave Request Details</h5>
                                        <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="row mb-3">
                                            <div class="col-md-6">
                                                <strong class="d-block">Employee:</strong>
                                                <span class="font-weight-bold"><?php echo $employee_first_name . ' ' . $employee_last_name; ?></span>
                                                <small class="d-block text-muted">ID: <?php echo $employee_number; ?></small>
                                            </div>
                                            <div class="col-md-6">
                                                <strong class="d-block">Department:</strong>
                                                <span><?php echo $department_name; ?></span>
                                            </div>
                                        </div>
                                        <div class="row mb-3">
                                            <div class="col-md-6">
                                                <strong class="d-block">Leave Type:</strong>
                                                <span class="font-weight-bold text-info"><?php echo $leave_type; ?></span>
                                            </div>
                                            <div class="col-md-6">
                                                <strong class="d-block">Days Requested:</strong>
                                                <span class="badge badge-info"><?php echo $days_requested; ?> days</span>
                                            </div>
                                        </div>
                                        <div class="row mb-3">
                                            <div class="col-md-6">
                                                <strong class="d-block">Start Date:</strong>
                                                <span><?php echo date('l, M j, Y', strtotime($start_date)); ?></span>
                                            </div>
                                            <div class="col-md-6">
                                                <strong class="d-block">End Date:</strong>
                                                <span><?php echo date('l, M j, Y', strtotime($end_date)); ?></span>
                                            </div>
                                        </div>
                                        <?php if ($reason): ?>
                                        <div class="row mb-3">
                                            <div class="col-12">
                                                <strong class="d-block">Reason:</strong>
                                                <div class="border rounded p-3 bg-light">
                                                    <?php echo nl2br(htmlspecialchars($reason)); ?>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <strong class="d-block">Applied On:</strong>
                                                <span><?php echo date('M j, Y g:i A', strtotime($created_at)); ?></span>
                                            </div>
                                            <div class="col-md-6">
                                                <strong class="d-block">Status:</strong>
                                                <span class="badge <?php echo $status_badge; ?>"><?php echo ucfirst($status); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                                        <?php if ($status == 'pending'): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="leave_id" value="<?php echo $leave_id; ?>">
                                            <input type="hidden" name="action" value="approve">
                                            <button type="submit" class="btn btn-success" onclick="return confirm('Approve this leave request?')">
                                                <i class="fas fa-check mr-2"></i>Approve
                                            </button>
                                        </form>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="leave_id" value="<?php echo $leave_id; ?>">
                                            <input type="hidden" name="action" value="reject">
                                            <button type="submit" class="btn btn-danger" onclick="return confirm('Reject this leave request?')">
                                                <i class="fas fa-times mr-2"></i>Reject
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
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
                        <i class="fas fa-calendar-times fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No leave requests found</h5>
                        <p class="text-muted">No leave requests match your current filters.</p>
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
});
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
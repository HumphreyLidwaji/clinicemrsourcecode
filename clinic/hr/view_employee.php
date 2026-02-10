<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Employee ID is required!";
    header("Location: manage_employees.php");
    exit;
}

$employee_id = intval($_GET['id']);

// Get employee details
$sql = "SELECT e.*, d.department_name, j.title as job_title, 
               u.user_name as created_by_name
        FROM employees e 
        LEFT JOIN departments d ON e.department_id = d.department_id 
        LEFT JOIN job_titles j ON e.job_title_id = j.job_title_id 
        LEFT JOIN users u ON e.created_by = u.user_id 
        WHERE e.employee_id = ?";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $employee_id);
$stmt->execute();
$employee = $stmt->get_result()->fetch_assoc();

if (!$employee) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Employee not found!";
    header("Location: manage_employees.php");
    exit;
}

// Get employee contracts
$contracts_sql = "SELECT * FROM employee_contracts WHERE employee_id = ? ORDER BY start_date DESC";
$contracts_stmt = $mysqli->prepare($contracts_sql);
$contracts_stmt->bind_param("i", $employee_id);
$contracts_stmt->execute();
$contracts = $contracts_stmt->get_result();

// Get leave balance
$leave_balance_sql = "SELECT lt.name, lb.days_remaining 
                     FROM leave_balances lb 
                     JOIN leave_types lt ON lb.leave_type_id = lt.leave_type_id 
                     WHERE lb.employee_id = ? AND lb.year = YEAR(CURDATE())";
$leave_balance_stmt = $mysqli->prepare($leave_balance_sql);
$leave_balance_stmt->bind_param("i", $employee_id);
$leave_balance_stmt->execute();
$leave_balances = $leave_balance_stmt->get_result();

// Get recent attendance
$attendance_sql = "SELECT * FROM attendance_logs 
                   WHERE employee_id = ? 
                   ORDER BY log_date DESC 
                   LIMIT 10";
$attendance_stmt = $mysqli->prepare($attendance_sql);
$attendance_stmt->bind_param("i", $employee_id);
$attendance_stmt->execute();
$attendance = $attendance_stmt->get_result();

// Get recent leave requests
$leave_requests_sql = "SELECT lr.*, lt.name as leave_type 
                      FROM leave_requests lr 
                      JOIN leave_types lt ON lr.leave_type_id = lt.leave_type_id 
                      WHERE lr.employee_id = ? 
                      ORDER BY lr.created_at DESC 
                      LIMIT 5";
$leave_requests_stmt = $mysqli->prepare($leave_requests_sql);
$leave_requests_stmt->bind_param("i", $employee_id);
$leave_requests_stmt->execute();
$leave_requests = $leave_requests_stmt->get_result();
?>

<div class="card">
    <div class="card-header bg-info py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0 text-white">
                <i class="fas fa-fw fa-user mr-2"></i>Employee Details
            </h3>
            <div class="card-tools">
                <a href="edit_employee.php?id=<?php echo $employee_id; ?>" class="btn btn-warning btn-sm">
                    <i class="fas fa-edit mr-2"></i>Edit Employee
                </a>
                <a href="manage_employees.php" class="btn btn-light btn-sm ml-2">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Employees
                </a>
            </div>
        </div>
    </div>

    <div class="card-body">
        <!-- Employee Header -->
        <div class="row mb-4">
            <div class="col-md-2 text-center">
                <div class="employee-avatar">
                    <i class="fas fa-user-circle fa-5x text-info"></i>
                </div>
            </div>
            <div class="col-md-6">
                <h2><?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?></h2>
                <p class="text-muted mb-1">
                    <strong>Employee ID:</strong> <?php echo htmlspecialchars($employee['employee_number']); ?> | 
                    <strong>Department:</strong> <?php echo htmlspecialchars($employee['department_name'] ?? 'N/A'); ?>
                </p>
                <p class="text-muted mb-1">
                    <strong>Job Title:</strong> <?php echo htmlspecialchars($employee['job_title'] ?? 'N/A'); ?> | 
                    <strong>Status:</strong> 
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
                </p>
            </div>
            <div class="col-md-4 text-right">
                <div class="btn-group-vertical">
                    <a href="apply_leave.php?employee_id=<?php echo $employee_id; ?>" class="btn btn-primary btn-sm mb-2">
                        <i class="fas fa-calendar-plus mr-2"></i>Apply Leave
                    </a>
                    <a href="attendance.php?employee=<?php echo $employee_id; ?>" class="btn btn-secondary btn-sm mb-2">
                        <i class="fas fa-clock mr-2"></i>View Attendance
                    </a>
                    <button class="btn btn-success btn-sm" onclick="window.print()">
                        <i class="fas fa-print mr-2"></i>Print Profile
                    </button>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Personal Information -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0"><i class="fas fa-user mr-2"></i>Personal Information</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm table-borderless">
                            <tr>
                                <td width="40%"><strong>Full Name:</strong></td>
                                <td><?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Gender:</strong></td>
                                <td><?php echo htmlspecialchars($employee['gender']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Date of Birth:</strong></td>
                                <td><?php echo date('M j, Y', strtotime($employee['date_of_birth'])); ?></td>
                            </tr>
                            <tr>
                                <td><strong>National ID:</strong></td>
                                <td><?php echo htmlspecialchars($employee['national_id']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>KRA PIN:</strong></td>
                                <td><?php echo htmlspecialchars($employee['kra_pin']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>NSSF Number:</strong></td>
                                <td><?php echo htmlspecialchars($employee['nssf_number'] ?? 'N/A'); ?></td>
                            </tr>
                            <tr>
                                <td><strong>NHIF Number:</strong></td>
                                <td><?php echo htmlspecialchars($employee['nhif_number'] ?? 'N/A'); ?></td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!-- Contact Information -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0"><i class="fas fa-address-book mr-2"></i>Contact Information</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm table-borderless">
                            <tr>
                                <td width="40%"><strong>Phone:</strong></td>
                                <td><?php echo htmlspecialchars($employee['phone'] ?? 'N/A'); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Email:</strong></td>
                                <td><?php echo htmlspecialchars($employee['email'] ?? 'N/A'); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Address:</strong></td>
                                <td><?php echo nl2br(htmlspecialchars($employee['address'] ?? 'N/A')); ?></td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!-- Banking Information -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0"><i class="fas fa-university mr-2"></i>Banking Information</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm table-borderless">
                            <tr>
                                <td width="40%"><strong>Bank Name:</strong></td>
                                <td><?php echo htmlspecialchars($employee['bank_name'] ?? 'N/A'); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Bank Branch:</strong></td>
                                <td><?php echo htmlspecialchars($employee['bank_branch'] ?? 'N/A'); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Account Number:</strong></td>
                                <td><?php echo htmlspecialchars($employee['bank_account'] ?? 'N/A'); ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Employment Information -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0"><i class="fas fa-briefcase mr-2"></i>Employment Information</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm table-borderless">
                            <tr>
                                <td width="40%"><strong>Employee ID:</strong></td>
                                <td><?php echo htmlspecialchars($employee['employee_number']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Department:</strong></td>
                                <td><?php echo htmlspecialchars($employee['department_name'] ?? 'N/A'); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Job Title:</strong></td>
                                <td><?php echo htmlspecialchars($employee['job_title'] ?? 'N/A'); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Hire Date:</strong></td>
                                <td><?php echo date('M j, Y', strtotime($employee['hire_date'])); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Contract Type:</strong></td>
                                <td><?php echo htmlspecialchars($employee['contract_type']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Employment Status:</strong></td>
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
                            <tr>
                                <td><strong>Basic Salary:</strong></td>
                                <td><strong>KES <?php echo number_format($employee['basic_salary'], 2); ?></strong></td>
                            </tr>
                            <tr>
                                <td><strong>Created By:</strong></td>
                                <td><?php echo htmlspecialchars($employee['created_by_name'] ?? 'System'); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Created On:</strong></td>
                                <td><?php echo date('M j, Y g:i A', strtotime($employee['created_at'])); ?></td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!-- Leave Balance -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0"><i class="fas fa-calendar-check mr-2"></i>Leave Balance (<?php echo date('Y'); ?>)</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($leave_balances->num_rows > 0): ?>
                            <div class="row">
                                <?php while($balance = $leave_balances->fetch_assoc()): ?>
                                <div class="col-md-6 mb-2">
                                    <small class="text-muted"><?php echo htmlspecialchars($balance['name']); ?></small>
                                    <div class="progress" style="height: 10px;">
                                        <div class="progress-bar bg-success" style="width: <?php echo min(100, ($balance['days_remaining'] / 30) * 100); ?>%"></div>
                                    </div>
                                    <small><strong><?php echo $balance['days_remaining']; ?> days remaining</strong></small>
                                </div>
                                <?php endwhile; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-muted text-center">No leave balance recorded for this year.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0"><i class="fas fa-history mr-2"></i>Recent Activity</h5>
                    </div>
                    <div class="card-body">
                        <h6>Recent Leave Requests</h6>
                        <?php if ($leave_requests->num_rows > 0): ?>
                            <div class="list-group list-group-flush">
                                <?php while($leave = $leave_requests->fetch_assoc()): ?>
                                <div class="list-group-item px-0 py-2">
                                    <div class="d-flex justify-content-between">
                                        <small><?php echo htmlspecialchars($leave['leave_type']); ?></small>
                                        <span class="badge badge-<?php 
                                            switch($leave['status']) {
                                                case 'pending': echo 'warning'; break;
                                                case 'approved': echo 'success'; break;
                                                case 'rejected': echo 'danger'; break;
                                                case 'cancelled': echo 'secondary'; break;
                                                default: echo 'info';
                                            }
                                        ?>">
                                            <?php echo ucfirst($leave['status']); ?>
                                        </span>
                                    </div>
                                    <small class="text-muted">
                                        <?php echo date('M j', strtotime($leave['start_date'])); ?> - 
                                        <?php echo date('M j, Y', strtotime($leave['end_date'])); ?>
                                    </small>
                                </div>
                                <?php endwhile; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-muted"><small>No recent leave requests</small></p>
                        <?php endif; ?>

                        <h6 class="mt-3">Recent Attendance</h6>
                        <?php if ($attendance->num_rows > 0): ?>
                            <div class="list-group list-group-flush">
                                <?php while($record = $attendance->fetch_assoc()): ?>
                                <div class="list-group-item px-0 py-2">
                                    <div class="d-flex justify-content-between">
                                        <small><?php echo date('M j, Y', strtotime($record['log_date'])); ?></small>
                                        <span class="badge badge-<?php echo $record['hours_worked'] >= 8 ? 'success' : 'warning'; ?>">
                                            <?php echo $record['hours_worked'] ? number_format($record['hours_worked'], 1) . 'h' : 'Absent'; ?>
                                        </span>
                                    </div>
                                    <small class="text-muted">
                                        <?php echo $record['check_in'] ? 'In: ' . date('H:i', strtotime($record['check_in'])) : 'Not checked in'; ?>
                                        <?php echo $record['check_out'] ? ' | Out: ' . date('H:i', strtotime($record['check_out'])) : ''; ?>
                                    </small>
                                </div>
                                <?php endwhile; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-muted"><small>No recent attendance records</small></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Contracts Section -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0"><i class="fas fa-file-contract mr-2"></i>Employment Contracts</h5>
                        <button class="btn btn-sm btn-success" data-toggle="modal" data-target="#addContractModal">
                            <i class="fas fa-plus mr-2"></i>Add Contract
                        </button>
                    </div>
                    <div class="card-body">
                        <?php if ($contracts->num_rows > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-striped">
                                    <thead>
                                        <tr>
                                            <th>Start Date</th>
                                            <th>End Date</th>
                                            <th>Duration</th>
                                            <th>Terms</th>
                                            <th>Created</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while($contract = $contracts->fetch_assoc()): 
                                            $start = new DateTime($contract['start_date']);
                                            $end = $contract['end_date'] ? new DateTime($contract['end_date']) : null;
                                            $duration = $end ? $start->diff($end)->format('%y years, %m months') : 'Ongoing';
                                        ?>
                                        <tr>
                                            <td><?php echo date('M j, Y', strtotime($contract['start_date'])); ?></td>
                                            <td><?php echo $contract['end_date'] ? date('M j, Y', strtotime($contract['end_date'])) : 'No End Date'; ?></td>
                                            <td><?php echo $duration; ?></td>
                                            <td><?php echo $contract['terms'] ? nl2br(htmlspecialchars(substr($contract['terms'], 0, 100) . '...')) : 'No terms specified'; ?></td>
                                            <td><?php echo date('M j, Y', strtotime($contract['created_at'])); ?></td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p class="text-muted text-center">No contracts recorded for this employee.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Contract Modal -->
<div class="modal fade" id="addContractModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Employment Contract</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form method="POST" action="add_contract.php">
                <div class="modal-body">
                    <input type="hidden" name="employee_id" value="<?php echo $employee_id; ?>">
                    <div class="form-group">
                        <label>Start Date *</label>
                        <input type="date" class="form-control" name="start_date" required value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="form-group">
                        <label>End Date</label>
                        <input type="date" class="form-control" name="end_date">
                    </div>
                    <div class="form-group">
                        <label>Contract Terms</label>
                        <textarea class="form-control" name="terms" rows="4" placeholder="Enter contract terms and conditions..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Contract</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
@media print {
    .card-tools, .btn, .modal {
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
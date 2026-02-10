<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Get leave types
$leave_types_sql = "SELECT * FROM leave_types WHERE is_paid = 1 ORDER BY name";
$leave_types_result = $mysqli->query($leave_types_sql);

// Get employees for admin view
$employees_sql = "SELECT employee_id, first_name, last_name, employee_number FROM employees WHERE employment_status = 'Active' ORDER BY first_name, last_name";
$employees_result = $mysqli->query($employees_sql);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $employee_id = $_POST['employee_id'];
        $leave_type_id = $_POST['leave_type_id'];
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        $reason = $_POST['reason'];
        
        // Calculate number of days (excluding weekends)
        $start = new DateTime($start_date);
        $end = new DateTime($end_date);
        $interval = $start->diff($end);
        $days_requested = $interval->days + 1; // Inclusive of both dates
        
        // Subtract weekends
        $period = new DatePeriod($start, new DateInterval('P1D'), $end->modify('+1 day'));
        $weekendDays = 0;
        foreach ($period as $day) {
            if ($day->format('N') >= 6) { // 6 = Saturday, 7 = Sunday
                $weekendDays++;
            }
        }
        $days_requested -= $weekendDays;
        
        $sql = "INSERT INTO leave_requests (employee_id, leave_type_id, start_date, end_date, days_requested, reason) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("iissis", $employee_id, $leave_type_id, $start_date, $end_date, $days_requested, $reason);
        
        if ($stmt->execute()) {
            // Log the action
            $audit_sql = "INSERT INTO hr_audit_log (user_id, action, description, table_name, record_id) VALUES (?, 'leave_applied', ?, 'leave_requests', ?)";
            $audit_stmt = $mysqli->prepare($audit_sql);
            $description = "Applied for leave: ID " . $stmt->insert_id;
            $audit_stmt->bind_param("isi", $_SESSION['user_id'], $description, $stmt->insert_id);
            $audit_stmt->execute();
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Leave application submitted successfully! Requested $days_requested days.";
            header("Location: leave_management.php");
            exit;
        } else {
            throw new Exception("Leave application failed: " . $stmt->error);
        }
    } catch (Exception $e) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error applying for leave: " . $e->getMessage();
    }
}

// Pre-select employee if provided in URL
$selected_employee = $_GET['employee_id'] ?? '';
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0 text-white">
                <i class="fas fa-fw fa-calendar-plus mr-2"></i>Apply for Leave
            </h3>
            <div class="card-tools">
                <a href="leave_management.php" class="btn btn-light btn-sm">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Leave Management
                </a>
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

        <form method="POST" id="leaveForm">
            <div class="row">
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0"><i class="fas fa-calendar-alt mr-2"></i>Leave Application Details</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Employee <span class="text-danger">*</span></label>
                                        <select class="form-control select2" name="employee_id" required>
                                            <option value="">Select Employee</option>
                                            <?php while($emp = $employees_result->fetch_assoc()): ?>
                                                <option value="<?php echo $emp['employee_id']; ?>" <?php echo $selected_employee == $emp['employee_id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name'] . ' (' . $emp['employee_number'] . ')'); ?>
                                                </option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Leave Type <span class="text-danger">*</span></label>
                                        <select class="form-control select2" name="leave_type_id" required>
                                            <option value="">Select Leave Type</option>
                                            <?php while($type = $leave_types_result->fetch_assoc()): ?>
                                                <option value="<?php echo $type['leave_type_id']; ?>" data-days="<?php echo $type['default_days']; ?>">
                                                    <?php echo htmlspecialchars($type['name']); ?> (<?php echo $type['default_days']; ?> days)
                                                </option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Start Date <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" name="start_date" required min="<?php echo date('Y-m-d'); ?>">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>End Date <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" name="end_date" required min="<?php echo date('Y-m-d'); ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="form-group">
                                        <label>Reason for Leave <span class="text-danger">*</span></label>
                                        <textarea class="form-control" name="reason" rows="4" required placeholder="Please provide a detailed reason for your leave application..."></textarea>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="alert alert-info">
                                        <h6><i class="fas fa-info-circle mr-2"></i>Leave Information</h6>
                                        <div id="leaveInfo">
                                            <small class="text-muted">Select a leave type and dates to see calculated days</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <!-- Quick Actions -->
                    <div class="card card-success">
                        <div class="card-header">
                            <h5 class="card-title mb-0"><i class="fas fa-bolt mr-2"></i>Quick Actions</h5>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-success btn-lg">
                                    <i class="fas fa-paper-plane mr-2"></i>Submit Application
                                </button>
                                <button type="reset" class="btn btn-outline-secondary">
                                    <i class="fas fa-undo mr-2"></i>Reset Form
                                </button>
                                <a href="leave_management.php" class="btn btn-outline-danger">
                                    <i class="fas fa-times mr-2"></i>Cancel
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Leave Policy -->
                    <div class="card card-info mt-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0"><i class="fas fa-info-circle mr-2"></i>Leave Policy</h5>
                        </div>
                        <div class="card-body">
                            <small>
                                <ul class="pl-3">
                                    <li>Submit leave applications at least 3 days in advance</li>
                                    <li>Emergency leaves require immediate supervisor approval</li>
                                    <li>Weekends are automatically excluded from leave days</li>
                                    <li>Provide sufficient reason for leave approval</li>
                                    <li>Check your leave balance before applying</li>
                                </ul>
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize Select2
    $('.select2').select2();
    
    // Calculate leave days when dates change
    function calculateLeaveDays() {
        var startDate = $('input[name="start_date"]').val();
        var endDate = $('input[name="end_date"]').val();
        
        if (startDate && endDate) {
            var start = new Date(startDate);
            var end = new Date(endDate);
            
            if (start > end) {
                $('#leaveInfo').html('<small class="text-danger"><i class="fas fa-exclamation-triangle mr-1"></i>End date cannot be before start date</small>');
                return;
            }
            
            // Calculate business days (exclude weekends)
            var totalDays = 0;
            var current = new Date(start);
            
            while (current <= end) {
                var day = current.getDay();
                if (day !== 0 && day !== 6) { // 0 = Sunday, 6 = Saturday
                    totalDays++;
                }
                current.setDate(current.getDate() + 1);
            }
            
            var leaveType = $('select[name="leave_type_id"] option:selected').text();
            $('#leaveInfo').html(`
                <small>
                    <strong>Leave Type:</strong> ${leaveType}<br>
                    <strong>Duration:</strong> ${totalDays} business day(s)<br>
                    <strong>Period:</strong> ${formatDate(start)} to ${formatDate(end)}
                </small>
            `);
        }
    }
    
    function formatDate(date) {
        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    }
    
    $('input[name="start_date"], input[name="end_date"]').on('change', calculateLeaveDays);
    $('select[name="leave_type_id"]').on('change', calculateLeaveDays);
    
    // Form validation
    $('#leaveForm').on('submit', function(e) {
        var requiredFields = ['employee_id', 'leave_type_id', 'start_date', 'end_date', 'reason'];
        var isValid = true;
        
        requiredFields.forEach(function(field) {
            var element = $('[name="' + field + '"]');
            if (!element.val()) {
                isValid = false;
                element.addClass('is-invalid');
            } else {
                element.removeClass('is-invalid');
            }
        });

        if (!isValid) {
            e.preventDefault();
            alert('Please fill in all required fields (marked with *).');
            return false;
        }

        // Show loading state
        $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Submitting...').prop('disabled', true);
    });
});
</script>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
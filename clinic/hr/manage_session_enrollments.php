<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Session ID is required!";
    header("Location: training_dashboard.php");
    exit;
}

$session_id = intval($_GET['id']);

// Get session details
$session_sql = "SELECT ts.*, tc.course_name, tc.course_code, 
                       (SELECT COUNT(*) FROM training_enrollments te WHERE te.session_id = ts.session_id) as enrolled_count
                FROM training_sessions ts 
                JOIN training_courses tc ON ts.course_id = tc.course_id 
                WHERE ts.session_id = ?";
$session_stmt = $mysqli->prepare($session_sql);
$session_stmt->bind_param("i", $session_id);
$session_stmt->execute();
$session = $session_stmt->get_result()->fetch_assoc();

if (!$session) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Training session not found!";
    header("Location: training_dashboard.php");
    exit;
}

// Handle enrollment actions
if (isset($_GET['action']) && isset($_GET['enrollment_id'])) {
    $enrollment_id = intval($_GET['enrollment_id']);
    
    if ($_GET['action'] == 'remove_enrollment') {
        $sql = "DELETE FROM training_enrollments WHERE enrollment_id = ?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("i", $enrollment_id);
        
        if ($stmt->execute()) {
            // Log the action
            $audit_sql = "INSERT INTO hr_audit_log (user_id, action, description, table_name, record_id) VALUES (?, 'training_enrollment_removed', ?, 'training_enrollments', ?)";
            $audit_stmt = $mysqli->prepare($audit_sql);
            $description = "Removed enrollment from session: " . $session['course_name'];
            $audit_stmt->bind_param("isi", $_SESSION['user_id'], $description, $session_id);
            $audit_stmt->execute();
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Enrollment removed successfully!";
        } else {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error removing enrollment: " . $stmt->error;
        }
        header("Location: manage_session_enrollments.php?id=" . $session_id);
        exit;
    }
}

// Handle bulk enrollment
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'enroll_employees') {
        try {
            $mysqli->begin_transaction();

            $employee_ids = $_POST['employee_ids'] ?? [];
            $enrolled_count = 0;

            foreach ($employee_ids as $employee_id) {
                $employee_id = intval($employee_id);
                
                // Check if already enrolled
                $check_sql = "SELECT COUNT(*) as count FROM training_enrollments WHERE session_id = ? AND employee_id = ?";
                $check_stmt = $mysqli->prepare($check_sql);
                $check_stmt->bind_param("ii", $session_id, $employee_id);
                $check_stmt->execute();
                $already_enrolled = $check_stmt->get_result()->fetch_assoc()['count'];
                
                if (!$already_enrolled) {
                    $enroll_sql = "INSERT INTO training_enrollments (session_id, employee_id, enrollment_status) VALUES (?, ?, 'registered')";
                    $enroll_stmt = $mysqli->prepare($enroll_sql);
                    $enroll_stmt->bind_param("ii", $session_id, $employee_id);
                    
                    if ($enroll_stmt->execute()) {
                        $enrolled_count++;
                    }
                }
            }

            // Log the action
            $audit_sql = "INSERT INTO hr_audit_log (user_id, action, description, table_name, record_id) VALUES (?, 'training_enrollments_added', ?, 'training_sessions', ?)";
            $audit_stmt = $mysqli->prepare($audit_sql);
            $description = "Added $enrolled_count employees to session: " . $session['course_name'];
            $audit_stmt->bind_param("isi", $_SESSION['user_id'], $description, $session_id);
            $audit_stmt->execute();

            $mysqli->commit();

            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Successfully enrolled $enrolled_count employees!";
            header("Location: manage_session_enrollments.php?id=" . $session_id);
            exit;

        } catch (Exception $e) {
            $mysqli->rollback();
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error enrolling employees: " . $e->getMessage();
        }
    }
}

// Get current enrollments
$enrollments_sql = "SELECT te.*, emp.first_name, emp.last_name, emp.employee_number, emp.department_id,
                           d.department_name, j.title as job_title
                    FROM training_enrollments te 
                    JOIN employees emp ON te.employee_id = emp.employee_id 
                    LEFT JOIN departments d ON emp.department_id = d.department_id 
                    LEFT JOIN job_titles j ON emp.job_title_id = j.job_title_id 
                    WHERE te.session_id = ? 
                    ORDER BY emp.first_name, emp.last_name";
$enrollments_stmt = $mysqli->prepare($enrollments_sql);
$enrollments_stmt->bind_param("i", $session_id);
$enrollments_stmt->execute();
$enrollments_result = $enrollments_stmt->get_result();

// Get available employees for enrollment
$available_employees_sql = "SELECT e.employee_id, e.first_name, e.last_name, e.employee_number, d.department_name
                           FROM employees e 
                           LEFT JOIN departments d ON e.department_id = d.department_id 
                           WHERE e.employment_status = 'Active' 
                           AND e.employee_id NOT IN (
                               SELECT employee_id FROM training_enrollments WHERE session_id = ?
                           )
                           ORDER BY e.first_name, e.last_name";
$available_stmt = $mysqli->prepare($available_employees_sql);
$available_stmt->bind_param("i", $session_id);
$available_stmt->execute();
$available_employees = $available_stmt->get_result();
?>

<div class="card">
    <div class="card-header bg-info py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0 text-white">
                <i class="fas fa-fw fa-users mr-2"></i>Manage Session Enrollments
            </h3>
            <div class="card-tools">
                <a href="view_training_session.php?id=<?php echo $session_id; ?>" class="btn btn-light btn-sm">
                    <i class="fas fa-eye mr-2"></i>View Session
                </a>
                <a href="training_dashboard.php" class="btn btn-light btn-sm ml-2">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
                </a>
            </div>
        </div>
    </div>

    <div class="card-body">
        <?php if (isset($_SESSION['alert_message'])): ?>
            <div class="alert alert-<?php echo $_SESSION['alert_type']; ?> alert-dismissible fade show" role="alert">
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                <i class="fas fa-<?php echo $_SESSION['alert_type'] == 'success' ? 'check' : 'exclamation-triangle'; ?> mr-2"></i>
                <?php echo $_SESSION['alert_message']; ?>
            </div>
            <?php 
            unset($_SESSION['alert_type']);
            unset($_SESSION['alert_message']);
            ?>
        <?php endif; ?>

        <!-- Session Info -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="card-title mb-0">
                    <i class="fas fa-info-circle mr-2"></i>
                    <?php echo htmlspecialchars($session['course_name']); ?>
                </h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <strong>Date:</strong> <?php echo date('M j, Y', strtotime($session['start_datetime'])); ?>
                    </div>
                    <div class="col-md-4">
                        <strong>Time:</strong> <?php echo date('g:i A', strtotime($session['start_datetime'])); ?> - <?php echo date('g:i A', strtotime($session['end_datetime'])); ?>
                    </div>
                    <div class="col-md-4">
                        <strong>Capacity:</strong> 
                        <?php echo $session['enrolled_count']; ?>
                        <?php if ($session['max_participants']): ?>
                            /<?php echo $session['max_participants']; ?>
                            (<?php echo $session['max_participants'] - $session['enrolled_count']; ?> remaining)
                        <?php else: ?>
                            (Unlimited)
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-8">
                <!-- Current Enrollments -->
                <div class="card">
                    <div class="card-header bg-warning">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-user-check mr-2"></i>Current Enrollments (<?php echo $enrollments_result->num_rows; ?>)
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if ($enrollments_result->num_rows > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Employee</th>
                                            <th>Department</th>
                                            <th>Job Title</th>
                                            <th>Enrollment Status</th>
                                            <th>Completion</th>
                                            <th class="text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while($enrollment = $enrollments_result->fetch_assoc()): ?>
                                        <tr>
                                            <td>
                                                <div class="font-weight-bold"><?php echo htmlspecialchars($enrollment['first_name'] . ' ' . $enrollment['last_name']); ?></div>
                                                <small class="text-muted"><?php echo htmlspecialchars($enrollment['employee_number']); ?></small>
                                            </td>
                                            <td><?php echo htmlspecialchars($enrollment['department_name'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($enrollment['job_title'] ?? 'N/A'); ?></td>
                                            <td>
                                                <span class="badge badge-<?php 
                                                    switch($enrollment['enrollment_status']) {
                                                        case 'registered': echo 'primary'; break;
                                                        case 'attended': echo 'success'; break;
                                                        case 'no_show': echo 'danger'; break;
                                                        case 'cancelled': echo 'secondary'; break;
                                                        default: echo 'light';
                                                    }
                                                ?>">
                                                    <?php echo ucfirst($enrollment['enrollment_status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge badge-<?php 
                                                    switch($enrollment['completion_status']) {
                                                        case 'completed': echo 'success'; break;
                                                        case 'in_progress': echo 'warning'; break;
                                                        case 'failed': echo 'danger'; break;
                                                        case 'not_started': echo 'secondary'; break;
                                                        default: echo 'light';
                                                    }
                                                ?>">
                                                    <?php echo ucfirst(str_replace('_', ' ', $enrollment['completion_status'])); ?>
                                                </span>
                                            </td>
                                            <td class="text-center">
                                                <a href="manage_session_enrollments.php?action=remove_enrollment&id=<?php echo $session_id; ?>&enrollment_id=<?php echo $enrollment['enrollment_id']; ?>&csrf_token=<?php echo $_SESSION['csrf_token'] ?>" 
                                                   class="btn btn-sm btn-outline-danger confirm-link" 
                                                   data-confirm-message="Remove <?php echo htmlspecialchars($enrollment['first_name'] . ' ' . $enrollment['last_name']); ?> from this session?">
                                                    <i class="fas fa-times"></i>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="fas fa-users-slash fa-2x text-muted mb-3"></i>
                                <p class="text-muted">No employees enrolled in this session yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <!-- Add Enrollments -->
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h5 class="card-title mb-0"><i class="fas fa-user-plus mr-2"></i>Add Employees</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="enrollmentForm">
                            <input type="hidden" name="action" value="enroll_employees">
                            
                            <div class="form-group mb-3">
                                <label>Select Employees</label>
                                <select class="form-control select2" name="employee_ids[]" multiple="multiple" 
                                        data-placeholder="Choose employees to enroll..." 
                                        <?php echo ($session['max_participants'] && $session['enrolled_count'] >= $session['max_participants']) ? 'disabled' : ''; ?>>
                                    <?php while($employee = $available_employees->fetch_assoc()): ?>
                                        <option value="<?php echo $employee['employee_id']; ?>">
                                            <?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name'] . ' (' . $employee['employee_number'] . ') - ' . $employee['department_name']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                                <?php if ($session['max_participants'] && $session['enrolled_count'] >= $session['max_participants']): ?>
                                    <small class="form-text text-danger">Session is at full capacity. Cannot add more participants.</small>
                                <?php else: ?>
                                    <small class="form-text text-muted">Select multiple employees to enroll them in this session.</small>
                                <?php endif; ?>
                            </div>

                            <button type="submit" class="btn btn-success w-100" 
                                    <?php echo ($session['max_participants'] && $session['enrolled_count'] >= $session['max_participants']) ? 'disabled' : ''; ?>>
                                <i class="fas fa-user-plus mr-2"></i>Enroll Selected
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Quick Stats -->
                <div class="card mt-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="card-title mb-0"><i class="fas fa-chart-pie mr-2"></i>Enrollment Stats</h5>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-6 mb-3">
                                <div class="border rounded p-2 bg-white">
                                    <h4 class="text-primary mb-0"><?php echo $enrollments_result->num_rows; ?></h4>
                                    <small class="text-muted">Enrolled</small>
                                </div>
                            </div>
                            <div class="col-6 mb-3">
                                <div class="border rounded p-2 bg-white">
                                    <h4 class="text-success mb-0"><?php echo $available_employees->num_rows; ?></h4>
                                    <small class="text-muted">Available</small>
                                </div>
                            </div>
                        </div>
                        <?php if ($session['max_participants']): ?>
                            <div class="progress mt-2" style="height: 10px;">
                                <div class="progress-bar <?php echo ($session['enrolled_count'] >= $session['max_participants']) ? 'bg-danger' : 'bg-success'; ?>" 
                                     style="width: <?php echo min(100, ($session['enrolled_count'] / $session['max_participants']) * 100); ?>%">
                                </div>
                            </div>
                            <small class="text-muted text-center d-block mt-1">
                                Capacity: <?php echo $session['enrolled_count']; ?>/<?php echo $session['max_participants']; ?> 
                                (<?php echo number_format(($session['enrolled_count'] / $session['max_participants']) * 100, 1); ?>%)
                            </small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize Select2
    $('.select2').select2();

    // Confirm links
    $('.confirm-link').click(function(e) {
        e.preventDefault();
        var message = $(this).data('confirm-message') || 'Are you sure?';
        var href = $(this).attr('href');
        
        if (confirm(message)) {
            window.location.href = href;
        }
    });

    // Enrollment form submission
    $('#enrollmentForm').on('submit', function(e) {
        const selectedCount = $('select[name="employee_ids[]"]').val().length;
        if (selectedCount === 0) {
            e.preventDefault();
            alert('Please select at least one employee to enroll.');
            return false;
        }

        // Show loading state
        $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Enrolling...').prop('disabled', true);
    });
});
</script>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
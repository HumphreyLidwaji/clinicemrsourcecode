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
$session_sql = "SELECT ts.*, tc.course_name, tc.course_code 
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

// Check if session is eligible for attendance
if ($session['status'] != 'scheduled' && $session['status'] != 'in_progress') {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Attendance can only be taken for scheduled or in-progress sessions!";
    header("Location: view_training_session.php?id=" . $session_id);
    exit;
}

// Get enrollments for this session
$enrollments_sql = "SELECT te.*, emp.first_name, emp.last_name, emp.employee_number, emp.department_id,
                           d.department_name
                    FROM training_enrollments te 
                    JOIN employees emp ON te.employee_id = emp.employee_id 
                    LEFT JOIN departments d ON emp.department_id = d.department_id 
                    WHERE te.session_id = ? 
                    ORDER BY emp.first_name, emp.last_name";
$enrollments_stmt = $mysqli->prepare($enrollments_sql);
$enrollments_stmt->bind_param("i", $session_id);
$enrollments_stmt->execute();
$enrollments_result = $enrollments_stmt->get_result();

// Handle attendance submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'update_attendance') {
        try {
            $mysqli->begin_transaction();

            $attendance_data = $_POST['attendance'] ?? [];
            $updated_count = 0;

            foreach ($attendance_data as $enrollment_id => $attendance_status) {
                $enrollment_id = intval($enrollment_id);
                $attendance_status = $mysqli->real_escape_string($attendance_status);
                
                // Determine enrollment status based on attendance
                $enrollment_status = ($attendance_status == 'present' || $attendance_status == 'late') ? 'attended' : 'no_show';
                
                $update_sql = "UPDATE training_enrollments SET 
                              attendance_status = ?, 
                              enrollment_status = ?,
                              completion_status = CASE 
                                  WHEN ? IN ('present', 'late') THEN 'in_progress' 
                                  ELSE completion_status 
                              END
                              WHERE enrollment_id = ?";
                $update_stmt = $mysqli->prepare($update_sql);
                $update_stmt->bind_param("sssi", $attendance_status, $enrollment_status, $attendance_status, $enrollment_id);
                
                if ($update_stmt->execute()) {
                    $updated_count++;
                }
            }

            // Update session status to in_progress if it's scheduled
            if ($session['status'] == 'scheduled') {
                $session_update_sql = "UPDATE training_sessions SET status = 'in_progress' WHERE session_id = ?";
                $session_stmt = $mysqli->prepare($session_update_sql);
                $session_stmt->bind_param("i", $session_id);
                $session_stmt->execute();
            }

            // Log the action
            $audit_sql = "INSERT INTO hr_audit_log (user_id, action, description, table_name, record_id) VALUES (?, 'training_attendance_taken', ?, 'training_sessions', ?)";
            $audit_stmt = $mysqli->prepare($audit_sql);
            $description = "Took attendance for session: " . $session['course_name'] . " (" . $updated_count . " participants)";
            $audit_stmt->bind_param("isi", $_SESSION['user_id'], $description, $session_id);
            $audit_stmt->execute();

            $mysqli->commit();

            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Attendance updated successfully for " . $updated_count . " participants!";
            header("Location: training_attendance.php?id=" . $session_id);
            exit;

        } catch (Exception $e) {
            $mysqli->rollback();
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error updating attendance: " . $e->getMessage();
        }
    }
}

// Get attendance statistics
$attendance_stats_sql = "SELECT 
                            COUNT(*) as total,
                            SUM(CASE WHEN attendance_status = 'present' THEN 1 ELSE 0 END) as present,
                            SUM(CASE WHEN attendance_status = 'absent' THEN 1 ELSE 0 END) as absent,
                            SUM(CASE WHEN attendance_status = 'late' THEN 1 ELSE 0 END) as late,
                            SUM(CASE WHEN attendance_status = 'left_early' THEN 1 ELSE 0 END) as left_early,
                            SUM(CASE WHEN attendance_status IS NULL THEN 1 ELSE 0 END) as not_recorded
                         FROM training_enrollments 
                         WHERE session_id = ?";
$stats_stmt = $mysqli->prepare($attendance_stats_sql);
$stats_stmt->bind_param("i", $session_id);
$stats_stmt->execute();
$attendance_stats = $stats_stmt->get_result()->fetch_assoc();
?>

<div class="card">
    <div class="card-header bg-success py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0 text-white">
                <i class="fas fa-fw fa-clipboard-check mr-2"></i>Training Attendance
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
                        <strong>Date:</strong> <?php echo date('l, F j, Y', strtotime($session['start_datetime'])); ?>
                    </div>
                    <div class="col-md-4">
                        <strong>Time:</strong> <?php echo date('g:i A', strtotime($session['start_datetime'])); ?> - <?php echo date('g:i A', strtotime($session['end_datetime'])); ?>
                    </div>
                    <div class="col-md-4">
                        <strong>Location:</strong> <?php echo htmlspecialchars($session['location'] ?? 'TBD'); ?>
                    </div>
                </div>
                <div class="row mt-2">
                    <div class="col-md-12">
                        <strong>Session Status:</strong> 
                        <span class="badge badge-<?php echo $session['status'] == 'scheduled' ? 'primary' : 'warning'; ?>">
                            <?php echo ucfirst($session['status']); ?>
                        </span>
                        <?php if ($session['status'] == 'scheduled'): ?>
                            <small class="text-muted ml-2">Marking attendance will change status to "In Progress"</small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-8">
                <!-- Attendance Form -->
                <div class="card">
                    <div class="card-header bg-warning">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-users mr-2"></i>Mark Attendance (<?php echo $enrollments_result->num_rows; ?> participants)
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if ($enrollments_result->num_rows > 0): ?>
                            <form method="POST" id="attendanceForm">
                                <input type="hidden" name="action" value="update_attendance">
                                
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover">
                                        <thead class="bg-light">
                                            <tr>
                                                <th>Employee</th>
                                                <th>Department</th>
                                                <th class="text-center">Present</th>
                                                <th class="text-center">Absent</th>
                                                <th class="text-center">Late</th>
                                                <th class="text-center">Left Early</th>
                                                <th>Current Status</th>
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
                                                <td class="text-center">
                                                    <div class="custom-control custom-radio">
                                                        <input type="radio" class="custom-control-input" 
                                                               name="attendance[<?php echo $enrollment['enrollment_id']; ?>]" 
                                                               value="present" 
                                                               id="present_<?php echo $enrollment['enrollment_id']; ?>"
                                                               <?php echo $enrollment['attendance_status'] == 'present' ? 'checked' : ''; ?>>
                                                        <label class="custom-control-label" for="present_<?php echo $enrollment['enrollment_id']; ?>"></label>
                                                    </div>
                                                </td>
                                                <td class="text-center">
                                                    <div class="custom-control custom-radio">
                                                        <input type="radio" class="custom-control-input" 
                                                               name="attendance[<?php echo $enrollment['enrollment_id']; ?>]" 
                                                               value="absent" 
                                                               id="absent_<?php echo $enrollment['enrollment_id']; ?>"
                                                               <?php echo $enrollment['attendance_status'] == 'absent' ? 'checked' : ''; ?>>
                                                        <label class="custom-control-label" for="absent_<?php echo $enrollment['enrollment_id']; ?>"></label>
                                                    </div>
                                                </td>
                                                <td class="text-center">
                                                    <div class="custom-control custom-radio">
                                                        <input type="radio" class="custom-control-input" 
                                                               name="attendance[<?php echo $enrollment['enrollment_id']; ?>]" 
                                                               value="late" 
                                                               id="late_<?php echo $enrollment['enrollment_id']; ?>"
                                                               <?php echo $enrollment['attendance_status'] == 'late' ? 'checked' : ''; ?>>
                                                        <label class="custom-control-label" for="late_<?php echo $enrollment['enrollment_id']; ?>"></label>
                                                    </div>
                                                </td>
                                                <td class="text-center">
                                                    <div class="custom-control custom-radio">
                                                        <input type="radio" class="custom-control-input" 
                                                               name="attendance[<?php echo $enrollment['enrollment_id']; ?>]" 
                                                               value="left_early" 
                                                               id="left_early_<?php echo $enrollment['enrollment_id']; ?>"
                                                               <?php echo $enrollment['attendance_status'] == 'left_early' ? 'checked' : ''; ?>>
                                                        <label class="custom-control-label" for="left_early_<?php echo $enrollment['enrollment_id']; ?>"></label>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if ($enrollment['attendance_status']): ?>
                                                        <span class="badge badge-<?php 
                                                            switch($enrollment['attendance_status']) {
                                                                case 'present': echo 'success'; break;
                                                                case 'absent': echo 'danger'; break;
                                                                case 'late': echo 'warning'; break;
                                                                case 'left_early': echo 'info'; break;
                                                                default: echo 'secondary';
                                                            }
                                                        ?>">
                                                            <?php echo ucfirst(str_replace('_', ' ', $enrollment['attendance_status'])); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge badge-secondary">Not Recorded</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="mt-3">
                                    <button type="submit" class="btn btn-success btn-lg">
                                        <i class="fas fa-save mr-2"></i>Save Attendance
                                    </button>
                                    <small class="text-muted ml-2">Attendance will be saved for all participants</small>
                                </div>
                            </form>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="fas fa-users-slash fa-2x text-muted mb-3"></i>
                                <p class="text-muted">No participants enrolled in this session.</p>
                                <a href="manage_session_enrollments.php?id=<?php echo $session_id; ?>" class="btn btn-primary">
                                    <i class="fas fa-user-plus mr-2"></i>Manage Enrollments
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <!-- Attendance Statistics -->
                <div class="card mb-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="card-title mb-0"><i class="fas fa-chart-pie mr-2"></i>Attendance Summary</h5>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-6 mb-3">
                                <div class="border rounded p-2 bg-white">
                                    <h4 class="text-success mb-0"><?php echo $attendance_stats['present']; ?></h4>
                                    <small class="text-muted">Present</small>
                                </div>
                            </div>
                            <div class="col-6 mb-3">
                                <div class="border rounded p-2 bg-white">
                                    <h4 class="text-danger mb-0"><?php echo $attendance_stats['absent']; ?></h4>
                                    <small class="text-muted">Absent</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="border rounded p-2 bg-white">
                                    <h4 class="text-warning mb-0"><?php echo $attendance_stats['late']; ?></h4>
                                    <small class="text-muted">Late</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="border rounded p-2 bg-white">
                                    <h4 class="text-info mb-0"><?php echo $attendance_stats['left_early']; ?></h4>
                                    <small class="text-muted">Left Early</small>
                                </div>
                            </div>
                        </div>
                        
                        <?php if ($attendance_stats['total'] > 0): ?>
                            <div class="progress mt-3" style="height: 10px;">
                                <?php 
                                $present_percent = ($attendance_stats['present'] / $attendance_stats['total']) * 100;
                                $late_percent = ($attendance_stats['late'] / $attendance_stats['total']) * 100;
                                $left_early_percent = ($attendance_stats['left_early'] / $attendance_stats['total']) * 100;
                                $absent_percent = ($attendance_stats['absent'] / $attendance_stats['total']) * 100;
                                ?>
                                <div class="progress-bar bg-success" style="width: <?php echo $present_percent; ?>%"></div>
                                <div class="progress-bar bg-warning" style="width: <?php echo $late_percent; ?>%"></div>
                                <div class="progress-bar bg-info" style="width: <?php echo $left_early_percent; ?>%"></div>
                                <div class="progress-bar bg-danger" style="width: <?php echo $absent_percent; ?>%"></div>
                            </div>
                            <div class="small text-center mt-2">
                                Attendance Rate: <?php echo number_format((($attendance_stats['present'] + $attendance_stats['late'] + $attendance_stats['left_early']) / $attendance_stats['total']) * 100, 1); ?>%
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h5 class="card-title mb-0"><i class="fas fa-bolt mr-2"></i>Quick Actions</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="view_training_session.php?id=<?php echo $session_id; ?>" class="btn btn-primary">
                                <i class="fas fa-eye mr-2"></i>View Session
                            </a>
                            <a href="manage_session_enrollments.php?id=<?php echo $session_id; ?>" class="btn btn-info">
                                <i class="fas fa-users mr-2"></i>Manage Enrollments
                            </a>
                            <?php if ($session['status'] == 'in_progress'): ?>
                                <a href="training_evaluation.php?id=<?php echo $session_id; ?>" class="btn btn-warning">
                                    <i class="fas fa-chart-bar mr-2"></i>Start Evaluations
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Auto-save functionality
    let autoSaveTimer;
    $('#attendanceForm').on('change', 'input[type="radio"]', function() {
        clearTimeout(autoSaveTimer);
        autoSaveTimer = setTimeout(function() {
            // Optional: Add auto-save functionality here
            // For now, we'll just indicate changes
            $('button[type="submit"]').html('<i class="fas fa-save mr-2"></i>Save Attendance (Unsaved Changes)');
        }, 1000);
    });

    // Form submission
    $('#attendanceForm').on('submit', function(e) {
        // Count how many attendees have been marked
        const markedCount = $('input[type="radio"]:checked').length;
        const totalCount = <?php echo $enrollments_result->num_rows; ?>;
        
        if (markedCount === 0) {
            e.preventDefault();
            alert('Please mark attendance for at least one participant.');
            return false;
        }

        if (markedCount < totalCount) {
            if (!confirm('You have only marked ' + markedCount + ' out of ' + totalCount + ' participants. Continue anyway?')) {
                e.preventDefault();
                return false;
            }
        }

        // Show loading state
        $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Saving...').prop('disabled', true);
    });

    // Keyboard shortcuts for quick attendance marking
    $(document).keydown(function(e) {
        // Only work when focused on attendance form
        if (!$('#attendanceForm').is(':focus')) return;

        // Number keys for quick selection
        if (e.ctrlKey) {
            switch(e.keyCode) {
                case 49: // Ctrl+1 for Present
                    $('input[type="radio"]:focus').closest('tr').find('input[value="present"]').click();
                    break;
                case 50: // Ctrl+2 for Absent
                    $('input[type="radio"]:focus').closest('tr').find('input[value="absent"]').click();
                    break;
                case 51: // Ctrl+3 for Late
                    $('input[type="radio"]:focus').closest('tr').find('input[value="late"]').click();
                    break;
                case 52: // Ctrl+4 for Left Early
                    $('input[type="radio"]:focus').closest('tr').find('input[value="left_early"]').click();
                    break;
            }
        }
    });
});
</script>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
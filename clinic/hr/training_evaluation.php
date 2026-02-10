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

// Get attendees for evaluation
$attendees_sql = "SELECT te.*, emp.first_name, emp.last_name, emp.employee_number, emp.department_id,
                         d.department_name
                  FROM training_enrollments te 
                  JOIN employees emp ON te.employee_id = emp.employee_id 
                  LEFT JOIN departments d ON emp.department_id = d.department_id 
                  WHERE te.session_id = ? 
                  AND te.enrollment_status = 'attended'
                  ORDER BY emp.first_name, emp.last_name";
$attendees_stmt = $mysqli->prepare($attendees_sql);
$attendees_stmt->bind_param("i", $session_id);
$attendees_stmt->execute();
$attendees_result = $attendees_stmt->get_result();

// Handle evaluation submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'update_evaluations') {
        try {
            $mysqli->begin_transaction();

            $evaluation_data = $_POST['evaluations'] ?? [];
            $updated_count = 0;

            foreach ($evaluation_data as $enrollment_id => $evaluation) {
                $enrollment_id = intval($enrollment_id);
                $completion_status = $mysqli->real_escape_string($evaluation['completion_status']);
                $grade = !empty($evaluation['grade']) ? $mysqli->real_escape_string($evaluation['grade']) : null;
                $feedback = !empty($evaluation['feedback']) ? $mysqli->real_escape_string($evaluation['feedback']) : null;
                
                $update_sql = "UPDATE training_enrollments SET 
                              completion_status = ?, 
                              grade = ?,
                              feedback = ?
                              WHERE enrollment_id = ?";
                $update_stmt = $mysqli->prepare($update_sql);
                $update_stmt->bind_param("sssi", $completion_status, $grade, $feedback, $enrollment_id);
                
                if ($update_stmt->execute()) {
                    $updated_count++;
                }
            }

            // Update session status to completed if all evaluations are done
            $completion_check_sql = "SELECT COUNT(*) as total, 
                                            SUM(CASE WHEN completion_status = 'completed' THEN 1 ELSE 0 END) as completed
                                     FROM training_enrollments 
                                     WHERE session_id = ? AND enrollment_status = 'attended'";
            $completion_stmt = $mysqli->prepare($completion_check_sql);
            $completion_stmt->bind_param("i", $session_id);
            $completion_stmt->execute();
            $completion_stats = $completion_stmt->get_result()->fetch_assoc();

            if ($completion_stats['total'] > 0 && $completion_stats['completed'] == $completion_stats['total']) {
                $session_update_sql = "UPDATE training_sessions SET status = 'completed' WHERE session_id = ?";
                $session_stmt = $mysqli->prepare($session_update_sql);
                $session_stmt->bind_param("i", $session_id);
                $session_stmt->execute();
            }

            // Log the action
            $audit_sql = "INSERT INTO hr_audit_log (user_id, action, description, table_name, record_id) VALUES (?, 'training_evaluations_updated', ?, 'training_sessions', ?)";
            $audit_stmt = $mysqli->prepare($audit_sql);
            $description = "Updated evaluations for session: " . $session['course_name'] . " (" . $updated_count . " participants)";
            $audit_stmt->bind_param("isi", $_SESSION['user_id'], $description, $session_id);
            $audit_stmt->execute();

            $mysqli->commit();

            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Evaluations updated successfully for " . $updated_count . " participants!";
            header("Location: training_evaluation.php?id=" . $session_id);
            exit;

        } catch (Exception $e) {
            $mysqli->rollback();
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error updating evaluations: " . $e->getMessage();
        }
    }
}

// Get evaluation statistics
$evaluation_stats_sql = "SELECT 
                            COUNT(*) as total_attendees,
                            SUM(CASE WHEN completion_status = 'completed' THEN 1 ELSE 0 END) as completed,
                            SUM(CASE WHEN completion_status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                            SUM(CASE WHEN completion_status = 'failed' THEN 1 ELSE 0 END) as failed,
                            SUM(CASE WHEN completion_status = 'not_started' THEN 1 ELSE 0 END) as not_started
                         FROM training_enrollments 
                         WHERE session_id = ? AND enrollment_status = 'attended'";
$stats_stmt = $mysqli->prepare($evaluation_stats_sql);
$stats_stmt->bind_param("i", $session_id);
$stats_stmt->execute();
$evaluation_stats = $stats_stmt->get_result()->fetch_assoc();

// Calculate average grade if available
$grade_stats_sql = "SELECT AVG(CAST(grade AS DECIMAL(5,2))) as avg_grade,
                           COUNT(grade) as graded_count
                    FROM training_enrollments 
                    WHERE session_id = ? AND grade IS NOT NULL AND completion_status = 'completed'";
$grade_stmt = $mysqli->prepare($grade_stats_sql);
$grade_stmt->bind_param("i", $session_id);
$grade_stmt->execute();
$grade_stats = $grade_stmt->get_result()->fetch_assoc();
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0 text-white">
                <i class="fas fa-fw fa-chart-bar mr-2"></i>Training Evaluations
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
            <div class="card-header bg-info text-white">
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
                        <strong>Session Status:</strong> 
                        <span class="badge badge-<?php 
                            switch($session['status']) {
                                case 'scheduled': echo 'primary'; break;
                                case 'in_progress': echo 'warning'; break;
                                case 'completed': echo 'success'; break;
                                case 'cancelled': echo 'danger'; break;
                                default: echo 'secondary';
                            }
                        ?>">
                            <?php echo ucfirst($session['status']); ?>
                        </span>
                    </div>
                    <div class="col-md-4">
                        <strong>Attendees:</strong> <?php echo $evaluation_stats['total_attendees']; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-8">
                <!-- Evaluation Form -->
                <div class="card">
                    <div class="card-header bg-warning">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-clipboard-list mr-2"></i>Participant Evaluations (<?php echo $attendees_result->num_rows; ?> attendees)
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if ($attendees_result->num_rows > 0): ?>
                            <form method="POST" id="evaluationForm">
                                <input type="hidden" name="action" value="update_evaluations">
                                
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover">
                                        <thead class="bg-light">
                                            <tr>
                                                <th>Employee</th>
                                                <th>Department</th>
                                                <th>Completion Status</th>
                                                <th>Grade</th>
                                                <th>Feedback</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php while($attendee = $attendees_result->fetch_assoc()): ?>
                                            <tr>
                                                <td>
                                                    <div class="font-weight-bold"><?php echo htmlspecialchars($attendee['first_name'] . ' ' . $attendee['last_name']); ?></div>
                                                    <small class="text-muted"><?php echo htmlspecialchars($attendee['employee_number']); ?></small>
                                                </td>
                                                <td><?php echo htmlspecialchars($attendee['department_name'] ?? 'N/A'); ?></td>
                                                <td>
                                                    <select class="form-control form-control-sm" name="evaluations[<?php echo $attendee['enrollment_id']; ?>][completion_status]">
                                                        <option value="not_started" <?php echo $attendee['completion_status'] == 'not_started' ? 'selected' : ''; ?>>Not Started</option>
                                                        <option value="in_progress" <?php echo $attendee['completion_status'] == 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                                        <option value="completed" <?php echo $attendee['completion_status'] == 'completed' ? 'selected' : ''; ?>>Completed</option>
                                                        <option value="failed" <?php echo $attendee['completion_status'] == 'failed' ? 'selected' : ''; ?>>Failed</option>
                                                    </select>
                                                </td>
                                                <td>
                                                    <input type="text" class="form-control form-control-sm" 
                                                           name="evaluations[<?php echo $attendee['enrollment_id']; ?>][grade]" 
                                                           value="<?php echo htmlspecialchars($attendee['grade'] ?? ''); ?>"
                                                           placeholder="A, B, C, 85%, etc." maxlength="20">
                                                </td>
                                                <td>
                                                    <textarea class="form-control form-control-sm" 
                                                              name="evaluations[<?php echo $attendee['enrollment_id']; ?>][feedback]"
                                                              rows="2" placeholder="Performance feedback..."><?php echo htmlspecialchars($attendee['feedback'] ?? ''); ?></textarea>
                                                </td>
                                            </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="mt-3">
                                    <button type="submit" class="btn btn-success btn-lg">
                                        <i class="fas fa-save mr-2"></i>Save Evaluations
                                    </button>
                                    <small class="text-muted ml-2">Evaluations will be saved for all attendees</small>
                                </div>
                            </form>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="fas fa-users-slash fa-2x text-muted mb-3"></i>
                                <p class="text-muted">No attendees found for this session.</p>
                                <p class="text-muted small">Attendance must be marked before evaluations can be recorded.</p>
                                <a href="training_attendance.php?id=<?php echo $session_id; ?>" class="btn btn-primary">
                                    <i class="fas fa-clipboard-check mr-2"></i>Take Attendance
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <!-- Evaluation Statistics -->
                <div class="card mb-4">
                    <div class="card-header bg-success text-white">
                        <h5 class="card-title mb-0"><i class="fas fa-chart-pie mr-2"></i>Evaluation Summary</h5>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-6 mb-3">
                                <div class="border rounded p-2 bg-white">
                                    <h4 class="text-success mb-0"><?php echo $evaluation_stats['completed']; ?></h4>
                                    <small class="text-muted">Completed</small>
                                </div>
                            </div>
                            <div class="col-6 mb-3">
                                <div class="border rounded p-2 bg-white">
                                    <h4 class="text-warning mb-0"><?php echo $evaluation_stats['in_progress']; ?></h4>
                                    <small class="text-muted">In Progress</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="border rounded p-2 bg-white">
                                    <h4 class="text-danger mb-0"><?php echo $evaluation_stats['failed']; ?></h4>
                                    <small class="text-muted">Failed</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="border rounded p-2 bg-white">
                                    <h4 class="text-secondary mb-0"><?php echo $evaluation_stats['not_started']; ?></h4>
                                    <small class="text-muted">Not Started</small>
                                </div>
                            </div>
                        </div>
                        
                        <?php if ($evaluation_stats['total_attendees'] > 0): ?>
                            <div class="progress mt-3" style="height: 10px;">
                                <?php 
                                $completed_percent = ($evaluation_stats['completed'] / $evaluation_stats['total_attendees']) * 100;
                                $in_progress_percent = ($evaluation_stats['in_progress'] / $evaluation_stats['total_attendees']) * 100;
                                $failed_percent = ($evaluation_stats['failed'] / $evaluation_stats['total_attendees']) * 100;
                                $not_started_percent = ($evaluation_stats['not_started'] / $evaluation_stats['total_attendees']) * 100;
                                ?>
                                <div class="progress-bar bg-success" style="width: <?php echo $completed_percent; ?>%"></div>
                                <div class="progress-bar bg-warning" style="width: <?php echo $in_progress_percent; ?>%"></div>
                                <div class="progress-bar bg-danger" style="width: <?php echo $failed_percent; ?>%"></div>
                                <div class="progress-bar bg-secondary" style="width: <?php echo $not_started_percent; ?>%"></div>
                            </div>
                            <div class="small text-center mt-2">
                                Completion Rate: <?php echo number_format($completed_percent, 1); ?>%
                            </div>
                        <?php endif; ?>

                        <?php if ($grade_stats['graded_count'] > 0): ?>
                            <hr>
                            <div class="text-center">
                                <h5 class="text-primary">Average Grade</h5>
                                <h3 class="text-success"><?php echo number_format($grade_stats['avg_grade'], 1); ?>%</h3>
                                <small class="text-muted">Based on <?php echo $grade_stats['graded_count']; ?> graded participants</small>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5 class="card-title mb-0"><i class="fas fa-bolt mr-2"></i>Quick Actions</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="view_training_session.php?id=<?php echo $session_id; ?>" class="btn btn-primary">
                                <i class="fas fa-eye mr-2"></i>View Session
                            </a>
                            <a href="training_attendance.php?id=<?php echo $session_id; ?>" class="btn btn-warning">
                                <i class="fas fa-clipboard-check mr-2"></i>Attendance
                            </a>
                            <?php if ($evaluation_stats['completed'] == $evaluation_stats['total_attendees'] && $evaluation_stats['total_attendees'] > 0): ?>
                                <button type="button" class="btn btn-success" onclick="generateCertificates()">
                                    <i class="fas fa-certificate mr-2"></i>Generate Certificates
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Evaluation Tips -->
                <div class="card mt-4">
                    <div class="card-header bg-secondary text-white">
                        <h5 class="card-title mb-0"><i class="fas fa-lightbulb mr-2"></i>Evaluation Tips</h5>
                    </div>
                    <div class="card-body">
                        <div class="small">
                            <p class="mb-2"><strong>Completion Status:</strong></p>
                            <ul class="pl-3 mb-3">
                                <li><strong>Completed:</strong> Successfully finished training</li>
                                <li><strong>In Progress:</strong> Still working on requirements</li>
                                <li><strong>Failed:</strong> Did not meet requirements</li>
                                <li><strong>Not Started:</strong> Evaluation not begun</li>
                            </ul>
                            <p class="mb-0"><strong>Grades:</strong> Use consistent grading scale (A-F, 0-100%, etc.)</p>
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
    $('#evaluationForm').on('change', 'select, input, textarea', function() {
        clearTimeout(autoSaveTimer);
        autoSaveTimer = setTimeout(function() {
            $('button[type="submit"]').html('<i class="fas fa-save mr-2"></i>Save Evaluations (Unsaved Changes)');
        }, 1000);
    });

    // Form submission
    $('#evaluationForm').on('submit', function(e) {
        // Validate that all required fields are filled
        let isValid = true;
        let emptyFields = 0;

        $('select[name^="evaluations"]').each(function() {
            if (!$(this).val()) {
                emptyFields++;
                isValid = false;
            }
        });

        if (!isValid) {
            e.preventDefault();
            alert('Please select completion status for all participants.');
            return false;
        }

        // Show loading state
        $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Saving...').prop('disabled', true);
    });

    // Certificate generation function
    function generateCertificates() {
        if (confirm('Generate certificates for all completed participants?')) {
            // Redirect to certificate generation page
            window.location.href = 'generate_certificates.php?session_id=<?php echo $session_id; ?>';
        }
    }

    // Auto-calculate completion rate
    function updateCompletionRate() {
        const total = <?php echo $evaluation_stats['total_attendees']; ?>;
        const completed = $('select option[value="completed"]:selected').length;
        const completionRate = total > 0 ? (completed / total) * 100 : 0;
        
        // Update the completion rate display
        $('.completion-rate').text(completionRate.toFixed(1) + '%');
        
        // Update progress bar (if you add one)
        $('.completion-progress').css('width', completionRate + '%');
    }

    // Update completion rate when status changes
    $('#evaluationForm').on('change', 'select[name$="[completion_status]"]', updateCompletionRate);
});
</script>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
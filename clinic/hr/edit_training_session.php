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

// Get courses, providers, and trainers for dropdowns
$courses_sql = "SELECT course_id, course_name, course_code FROM training_courses WHERE is_active = 1 ORDER BY course_name";
$courses_result = $mysqli->query($courses_sql);

$trainers_sql = "SELECT employee_id, first_name, last_name FROM employees WHERE employment_status = 'Active' ORDER BY first_name, last_name";
$trainers_result = $mysqli->query($trainers_sql);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // Start transaction
        $mysqli->begin_transaction();

        // Validate and sanitize inputs
        $course_id = intval($_POST['course_id']);
        $trainer_id = !empty($_POST['trainer_id']) ? intval($_POST['trainer_id']) : null;
        $location = trim($_POST['location'] ?? '');
        $start_datetime = $_POST['start_datetime'];
        $end_datetime = $_POST['end_datetime'];
        $max_participants = !empty($_POST['max_participants']) ? intval($_POST['max_participants']) : null;
        $status = $_POST['status'];
        $notes = trim($_POST['notes'] ?? '');

        // Validate required fields
        if (empty($course_id) || empty($start_datetime) || empty($end_datetime) || empty($status)) {
            throw new Exception("Course, date/times, and status are required!");
        }

        // Validate date logic
        if (strtotime($start_datetime) >= strtotime($end_datetime)) {
            throw new Exception("End date/time must be after start date/time!");
        }

        // Update training session
        $session_sql = "UPDATE training_sessions SET 
            course_id = ?, trainer_id = ?, location = ?, start_datetime = ?, end_datetime = ?, 
            max_participants = ?, status = ?, notes = ?
            WHERE session_id = ?";
        
        $session_stmt = $mysqli->prepare($session_sql);
        
        if (!$session_stmt) {
            throw new Exception("Prepare failed: " . $mysqli->error);
        }

        $session_stmt->bind_param("iisssisss", 
            $course_id, $trainer_id, $location, $start_datetime, $end_datetime,
            $max_participants, $status, $notes, $session_id
        );
        
        if ($session_stmt->execute()) {
            // Log the action
            $audit_sql = "INSERT INTO hr_audit_log (user_id, action, description, table_name, record_id) VALUES (?, 'training_session_updated', ?, 'training_sessions', ?)";
            $audit_stmt = $mysqli->prepare($audit_sql);
            $description = "Updated training session ID: " . $session_id;
            $audit_stmt->bind_param("isi", $_SESSION['user_id'], $description, $session_id);
            $audit_stmt->execute();

            // Commit transaction
            $mysqli->commit();

            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Training session updated successfully!";
            header("Location: view_training_session.php?id=" . $session_id);
            exit;
        } else {
            throw new Exception("Session update failed: " . $session_stmt->error);
        }

    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error updating training session: " . $e->getMessage();
        error_log("Training Session Update Error: " . $e->getMessage());
    }
}
?>

<div class="card">
    <div class="card-header bg-warning py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0 text-white">
                <i class="fas fa-fw fa-edit mr-2"></i>Edit Training Session
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

        <form method="POST" id="sessionForm" autocomplete="off">
            <div class="row">
                <div class="col-md-6">
                    <!-- Session Details -->
                    <div class="card card-primary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-calendar-alt mr-2"></i>Session Details</h3>
                        </div>
                        <div class="card-body">
                            <div class="form-group mb-3">
                                <label>Course <span class="text-danger">*</span></label>
                                <select class="form-control select2" name="course_id" required>
                                    <option value="">Select Course</option>
                                    <?php while($course = $courses_result->fetch_assoc()): ?>
                                        <option value="<?php echo $course['course_id']; ?>" 
                                                <?php echo $session['course_id'] == $course['course_id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($course['course_name'] . " (" . $course['course_code'] . ")"); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                                <div class="invalid-feedback">Please select a course.</div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group mb-3">
                                        <label>Start Date & Time <span class="text-danger">*</span></label>
                                        <input type="datetime-local" class="form-control" name="start_datetime" required 
                                               value="<?php echo date('Y-m-d\TH:i', strtotime($session['start_datetime'])); ?>">
                                        <div class="invalid-feedback">Please enter a valid start date and time.</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group mb-3">
                                        <label>End Date & Time <span class="text-danger">*</span></label>
                                        <input type="datetime-local" class="form-control" name="end_datetime" required
                                               value="<?php echo date('Y-m-d\TH:i', strtotime($session['end_datetime'])); ?>">
                                        <div class="invalid-feedback">Please enter a valid end date and time.</div>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group mb-3">
                                <label>Location</label>
                                <input type="text" class="form-control" name="location" 
                                       value="<?php echo htmlspecialchars($session['location'] ?? ''); ?>"
                                       placeholder="Training room, venue, or online platform">
                            </div>

                            <div class="form-group mb-3">
                                <label>Maximum Participants</label>
                                <input type="number" class="form-control" name="max_participants" 
                                       value="<?php echo $session['max_participants'] ?? ''; ?>"
                                       min="1" max="1000" placeholder="Leave empty for no limit">
                                <small class="form-text text-muted">Leave blank if there's no participant limit.</small>
                            </div>
                        </div>
                    </div>

                    <!-- Additional Information -->
                    <div class="card card-secondary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-sticky-note mr-2"></i>Additional Information</h3>
                        </div>
                        <div class="card-body">
                            <div class="form-group mb-0">
                                <label>Session Notes</label>
                                <textarea class="form-control" name="notes" rows="4" 
                                          placeholder="Any special instructions, requirements, or notes for this session..."><?php echo htmlspecialchars($session['notes'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <!-- Quick Actions -->
                    <div class="card card-success">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-bolt mr-2"></i>Quick Actions</h3>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-info">
                                <small><i class="fas fa-info-circle mr-1"></i> All fields marked with <span class="text-danger">*</span> are required.</small>
                            </div>
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-success btn-lg">
                                    <i class="fas fa-save mr-2"></i>Update Session
                                </button>
                                <button type="reset" class="btn btn-outline-secondary">
                                    <i class="fas fa-undo mr-2"></i>Reset Changes
                                </button>
                                <a href="view_training_session.php?id=<?php echo $session_id; ?>" class="btn btn-outline-danger">
                                    <i class="fas fa-times mr-2"></i>Cancel
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Training Team -->
                    <div class="card card-info">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-users mr-2"></i>Training Team</h3>
                        </div>
                        <div class="card-body">
                            <div class="form-group mb-3">
                                <label>Trainer/Facilitator</label>
                                <select class="form-control select2" name="trainer_id">
                                    <option value="">Select Trainer</option>
                                    <?php while($trainer = $trainers_result->fetch_assoc()): ?>
                                        <option value="<?php echo $trainer['employee_id']; ?>"
                                                <?php echo $session['trainer_id'] == $trainer['employee_id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($trainer['first_name'] . ' ' . $trainer['last_name']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                                <small class="form-text text-muted">Select an employee to facilitate this session.</small>
                            </div>
                        </div>
                    </div>

                    <!-- Session Status -->
                    <div class="card card-warning">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-cog mr-2"></i>Session Status</h3>
                        </div>
                        <div class="card-body">
                            <div class="form-group mb-0">
                                <label>Status <span class="text-danger">*</span></label>
                                <select class="form-control select2" name="status" required>
                                    <option value="scheduled" <?php echo $session['status'] == 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                                    <option value="in_progress" <?php echo $session['status'] == 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                    <option value="completed" <?php echo $session['status'] == 'completed' ? 'selected' : ''; ?>>Completed</option>
                                    <option value="cancelled" <?php echo $session['status'] == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                </select>
                                <div class="invalid-feedback">Please select session status.</div>
                            </div>
                        </div>
                    </div>

                    <!-- Current Information -->
                    <div class="card card-secondary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-info-circle mr-2"></i>Current Information</h3>
                        </div>
                        <div class="card-body">
                            <div class="small">
                                <p class="mb-2"><strong>Course:</strong> <?php echo htmlspecialchars($session['course_name']); ?></p>
                                <p class="mb-2"><strong>Code:</strong> <?php echo htmlspecialchars($session['course_code']); ?></p>
                                <p class="mb-2"><strong>Created:</strong> <?php echo date('M j, Y g:i A', strtotime($session['created_at'])); ?></p>
                                <?php if ($session['updated_at'] != $session['created_at']): ?>
                                    <p class="mb-0"><strong>Last Updated:</strong> <?php echo date('M j, Y g:i A', strtotime($session['updated_at'])); ?></p>
                                <?php endif; ?>
                            </div>
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

    // Form validation
    $('#sessionForm').on('submit', function(e) {
        let isValid = true;
        
        // Validate required fields
        $(this).find('[required]').each(function() {
            if (!$(this).val()) {
                $(this).addClass('is-invalid');
                isValid = false;
            } else {
                $(this).removeClass('is-invalid');
            }
        });

        // Validate date logic
        const startDateTime = new Date($('input[name="start_datetime"]').val());
        const endDateTime = new Date($('input[name="end_datetime"]').val());
        
        if (startDateTime && endDateTime && startDateTime >= endDateTime) {
            $('input[name="end_datetime"]').addClass('is-invalid');
            alert('End date/time must be after start date/time!');
            isValid = false;
        }

        if (!isValid) {
            e.preventDefault();
            alert('Please fix the errors in the form before submitting.');
            return false;
        }

        // Show loading state
        $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Updating...').prop('disabled', true);
    });
});
</script>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Course ID is required!";
    header("Location: manage_training_courses.php");
    exit;
}

$course_id = intval($_GET['id']);

// Get course details
$course_sql = "SELECT tc.*, tp.provider_name, tp.contact_person, tp.phone, tp.email 
               FROM training_courses tc 
               LEFT JOIN training_providers tp ON tc.provider_id = tp.provider_id 
               WHERE tc.course_id = ?";
$course_stmt = $mysqli->prepare($course_sql);
$course_stmt->bind_param("i", $course_id);
$course_stmt->execute();
$course = $course_stmt->get_result()->fetch_assoc();

if (!$course) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Course not found!";
    header("Location: manage_training_courses.php");
    exit;
}

// Get sessions for this course
$sessions_sql = "SELECT ts.*, e.first_name, e.last_name,
                 (SELECT COUNT(*) FROM training_enrollments te WHERE te.session_id = ts.session_id) as enrolled_count
                 FROM training_sessions ts 
                 LEFT JOIN employees e ON ts.trainer_id = e.employee_id 
                 WHERE ts.course_id = ? 
                 ORDER BY ts.start_datetime DESC";
$sessions_stmt = $mysqli->prepare($sessions_sql);
$sessions_stmt->bind_param("i", $course_id);
$sessions_stmt->execute();
$sessions_result = $sessions_stmt->get_result();

// Get statistics
$stats_sql = "SELECT 
                COUNT(*) as total_sessions,
                SUM(CASE WHEN ts.status = 'completed' THEN 1 ELSE 0 END) as completed_sessions,
                SUM(CASE WHEN ts.status = 'scheduled' THEN 1 ELSE 0 END) as scheduled_sessions,
                SUM(CASE WHEN ts.status = 'in_progress' THEN 1 ELSE 0 END) as ongoing_sessions,
                (SELECT COUNT(DISTINCT te.employee_id) 
                 FROM training_enrollments te 
                 JOIN training_sessions ts ON te.session_id = ts.session_id 
                 WHERE ts.course_id = ?) as unique_participants
              FROM training_sessions ts 
              WHERE ts.course_id = ?";
$stats_stmt = $mysqli->prepare($stats_sql);
$stats_stmt->bind_param("ii", $course_id, $course_id);
$stats_stmt->execute();
$stats = $stats_stmt->get_result()->fetch_assoc();
?>

<div class="card">
    <div class="card-header bg-info py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0 text-white">
                <i class="fas fa-fw fa-book mr-2"></i>Course Details
            </h3>
            <div class="card-tools">
                <a href="manage_training_courses.php" class="btn btn-light btn-sm">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Courses
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

        <div class="row">
            <div class="col-md-8">
                <!-- Course Information -->
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-info-circle mr-2"></i>
                            <?php echo htmlspecialchars($course['course_name']); ?>
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <strong>Course Code:</strong> <?php echo htmlspecialchars($course['course_code']); ?>
                            </div>
                            <div class="col-md-6">
                                <strong>Category:</strong> <?php echo htmlspecialchars($course['category'] ?? 'N/A'); ?>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <strong>Training Type:</strong> 
                                <span class="badge badge-<?php 
                                    switch($course['training_type']) {
                                        case 'technical': echo 'info'; break;
                                        case 'soft_skills': echo 'success'; break;
                                        case 'compliance': echo 'warning'; break;
                                        case 'leadership': echo 'primary'; break;
                                        case 'mandatory': echo 'danger'; break;
                                        default: echo 'secondary';
                                    }
                                ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $course['training_type'])); ?>
                                </span>
                            </div>
                            <div class="col-md-6">
                                <strong>Duration:</strong> <?php echo $course['duration_hours'] ? $course['duration_hours'] . ' hours' : 'N/A'; ?>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <strong>Provider:</strong> <?php echo htmlspecialchars($course['provider_name'] ?? 'Internal'); ?>
                            </div>
                            <div class="col-md-6">
                                <strong>Status:</strong> 
                                <span class="badge badge-<?php echo $course['is_active'] ? 'success' : 'danger'; ?>">
                                    <?php echo $course['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </div>
                        </div>

                        <?php if (!empty($course['learning_objectives'])): ?>
                        <div class="mb-3">
                            <strong>Learning Objectives:</strong>
                            <div class="border rounded p-3 bg-light mt-1">
                                <?php echo nl2br(htmlspecialchars($course['learning_objectives'])); ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($course['prerequisites'])): ?>
                        <div class="mb-0">
                            <strong>Prerequisites:</strong>
                            <div class="border rounded p-3 bg-light mt-1">
                                <?php echo nl2br(htmlspecialchars($course['prerequisites'])); ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Training Sessions -->
                <div class="card">
                    <div class="card-header bg-warning">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-calendar-alt mr-2"></i>Training Sessions
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if ($sessions_result->num_rows > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Date & Time</th>
                                            <th>Location</th>
                                            <th>Trainer</th>
                                            <th>Enrolled</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while($session = $sessions_result->fetch_assoc()): ?>
                                        <tr>
                                            <td>
                                                <div class="font-weight-bold"><?php echo date('M j, Y', strtotime($session['start_datetime'])); ?></div>
                                                <small class="text-muted">
                                                    <?php echo date('g:i A', strtotime($session['start_datetime'])); ?> - 
                                                    <?php echo date('g:i A', strtotime($session['end_datetime'])); ?>
                                                </small>
                                            </td>
                                            <td><?php echo htmlspecialchars($session['location'] ?? 'TBD'); ?></td>
                                            <td><?php echo $session['first_name'] ? $session['first_name'] . ' ' . $session['last_name'] : 'Not Assigned'; ?></td>
                                            <td>
                                                <span class="font-weight-bold"><?php echo $session['enrolled_count']; ?></span>
                                                <?php if ($session['max_participants']): ?>
                                                    /<?php echo $session['max_participants']; ?>
                                                <?php endif; ?>
                                            </td>
                                            <td>
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
                                            </td>
                                            <td>
                                                <a href="view_training_session.php?id=<?php echo $session['session_id']; ?>" class="btn btn-sm btn-outline-primary">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="fas fa-calendar-times fa-2x text-muted mb-3"></i>
                                <p class="text-muted">No training sessions scheduled for this course.</p>
                                <a href="add_training_session.php?course_id=<?php echo $course_id; ?>" class="btn btn-primary">
                                    <i class="fas fa-plus mr-2"></i>Schedule Session
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <!-- Quick Actions -->
                <div class="card mb-4">
                    <div class="card-header bg-success text-white">
                        <h5 class="card-title mb-0"><i class="fas fa-bolt mr-2"></i>Quick Actions</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="edit_training_course.php?id=<?php echo $course_id; ?>" class="btn btn-warning">
                                <i class="fas fa-edit mr-2"></i>Edit Course
                            </a>
                            <a href="add_training_session.php?course_id=<?php echo $course_id; ?>" class="btn btn-success">
                                <i class="fas fa-calendar-plus mr-2"></i>Schedule Session
                            </a>
                            <a href="manage_training_courses.php" class="btn btn-secondary">
                                <i class="fas fa-list mr-2"></i>All Courses
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Statistics -->
                <div class="card mb-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="card-title mb-0"><i class="fas fa-chart-bar mr-2"></i>Course Statistics</h5>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-6 mb-3">
                                <div class="border rounded p-2 bg-white">
                                    <h4 class="text-primary mb-0"><?php echo $stats['total_sessions']; ?></h4>
                                    <small class="text-muted">Total Sessions</small>
                                </div>
                            </div>
                            <div class="col-6 mb-3">
                                <div class="border rounded p-2 bg-white">
                                    <h4 class="text-success mb-0"><?php echo $stats['completed_sessions']; ?></h4>
                                    <small class="text-muted">Completed</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="border rounded p-2 bg-white">
                                    <h4 class="text-warning mb-0"><?php echo $stats['scheduled_sessions']; ?></h4>
                                    <small class="text-muted">Scheduled</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="border rounded p-2 bg-white">
                                    <h4 class="text-info mb-0"><?php echo $stats['unique_participants']; ?></h4>
                                    <small class="text-muted">Participants</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Provider Information -->
                <?php if ($course['provider_name']): ?>
                <div class="card">
                    <div class="card-header bg-secondary text-white">
                        <h5 class="card-title mb-0"><i class="fas fa-building mr-2"></i>Provider Info</h5>
                    </div>
                    <div class="card-body">
                        <p class="mb-2"><strong><?php echo htmlspecialchars($course['provider_name']); ?></strong></p>
                        <?php if ($course['contact_person']): ?>
                            <p class="mb-1 small"><i class="fas fa-user mr-1"></i> <?php echo htmlspecialchars($course['contact_person']); ?></p>
                        <?php endif; ?>
                        <?php if ($course['phone']): ?>
                            <p class="mb-1 small"><i class="fas fa-phone mr-1"></i> <?php echo htmlspecialchars($course['phone']); ?></p>
                        <?php endif; ?>
                        <?php if ($course['email']): ?>
                            <p class="mb-0 small"><i class="fas fa-envelope mr-1"></i> <?php echo htmlspecialchars($course['email']); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
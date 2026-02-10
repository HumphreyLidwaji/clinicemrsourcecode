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
$session_sql = "SELECT ts.*, tc.course_name, tc.course_code, tc.training_type, tc.duration_hours,
                       tp.provider_name, e.first_name, e.last_name, e.employee_number
                FROM training_sessions ts 
                JOIN training_courses tc ON ts.course_id = tc.course_id 
                LEFT JOIN training_providers tp ON tc.provider_id = tp.provider_id 
                LEFT JOIN employees e ON ts.trainer_id = e.employee_id 
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

// Get enrollment statistics
$enrollment_stats_sql = "SELECT 
                            COUNT(*) as total_enrolled,
                            SUM(CASE WHEN enrollment_status = 'registered' THEN 1 ELSE 0 END) as registered,
                            SUM(CASE WHEN enrollment_status = 'attended' THEN 1 ELSE 0 END) as attended,
                            SUM(CASE WHEN enrollment_status = 'no_show' THEN 1 ELSE 0 END) as no_show,
                            SUM(CASE WHEN completion_status = 'completed' THEN 1 ELSE 0 END) as completed
                         FROM training_enrollments 
                         WHERE session_id = ?";
$stats_stmt = $mysqli->prepare($enrollment_stats_sql);
$stats_stmt->bind_param("i", $session_id);
$stats_stmt->execute();
$enrollment_stats = $stats_stmt->get_result()->fetch_assoc();
?>

<div class="card">
    <div class="card-header bg-info py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0 text-white">
                <i class="fas fa-fw fa-calendar-alt mr-2"></i>Training Session Details
            </h3>
            <div class="card-tools">
                <a href="training_dashboard.php" class="btn btn-light btn-sm">
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

        <div class="row">
            <div class="col-md-8">
                <!-- Session Information -->
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-info-circle mr-2"></i>
                            <?php echo htmlspecialchars($session['course_name']); ?>
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <strong>Course Code:</strong> <?php echo htmlspecialchars($session['course_code']); ?>
                            </div>
                            <div class="col-md-6">
                                <strong>Training Type:</strong> 
                                <span class="badge badge-<?php 
                                    switch($session['training_type']) {
                                        case 'technical': echo 'info'; break;
                                        case 'soft_skills': echo 'success'; break;
                                        case 'compliance': echo 'warning'; break;
                                        case 'leadership': echo 'primary'; break;
                                        case 'mandatory': echo 'danger'; break;
                                        default: echo 'secondary';
                                    }
                                ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $session['training_type'])); ?>
                                </span>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <strong>Date & Time:</strong>
                                <div>
                                    <?php echo date('l, F j, Y', strtotime($session['start_datetime'])); ?><br>
                                    <small class="text-muted">
                                        <?php echo date('g:i A', strtotime($session['start_datetime'])); ?> - 
                                        <?php echo date('g:i A', strtotime($session['end_datetime'])); ?>
                                    </small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <strong>Duration:</strong> <?php echo $session['duration_hours'] ? $session['duration_hours'] . ' hours' : 'N/A'; ?>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <strong>Location:</strong> <?php echo htmlspecialchars($session['location'] ?? 'TBD'); ?>
                            </div>
                            <div class="col-md-6">
                                <strong>Status:</strong> 
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
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <strong>Trainer:</strong> 
                                <?php echo $session['first_name'] ? $session['first_name'] . ' ' . $session['last_name'] . ' (' . $session['employee_number'] . ')' : 'Not Assigned'; ?>
                            </div>
                            <div class="col-md-6">
                                <strong>Provider:</strong> <?php echo htmlspecialchars($session['provider_name'] ?? 'Internal'); ?>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <strong>Capacity:</strong> 
                                <?php echo $session['max_participants'] ? $enrollment_stats['total_enrolled'] . '/' . $session['max_participants'] . ' participants' : 'Unlimited'; ?>
                                <?php if ($session['max_participants']): ?>
                                    <div class="progress mt-1" style="height: 8px;">
                                        <div class="progress-bar <?php echo ($enrollment_stats['total_enrolled'] >= $session['max_participants']) ? 'bg-danger' : 'bg-success'; ?>" 
                                             style="width: <?php echo min(100, ($enrollment_stats['total_enrolled'] / $session['max_participants']) * 100); ?>%">
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if (!empty($session['notes'])): ?>
                        <div class="mt-3">
                            <strong>Session Notes:</strong>
                            <div class="border rounded p-3 bg-light mt-1">
                                <?php echo nl2br(htmlspecialchars($session['notes'])); ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Participants -->
                <div class="card">
                    <div class="card-header bg-warning">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-users mr-2"></i>Participants (<?php echo $enrollment_stats['total_enrolled']; ?>)
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
                                            <th>Enrollment Status</th>
                                            <th>Completion</th>
                                            <th>Actions</th>
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
                                                <?php if ($enrollment['grade']): ?>
                                                    <small class="text-muted">(<?php echo $enrollment['grade']; ?>)</small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <a href="employee_payroll.php?id=<?php echo $enrollment['employee_id']; ?>" class="btn btn-sm btn-outline-primary" title="View Employee">
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
                <!-- Quick Actions -->
                <div class="card mb-4">
                    <div class="card-header bg-success text-white">
                        <h5 class="card-title mb-0"><i class="fas fa-bolt mr-2"></i>Quick Actions</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="edit_training_session.php?id=<?php echo $session_id; ?>" class="btn btn-warning">
                                <i class="fas fa-edit mr-2"></i>Edit Session
                            </a>
                            <a href="manage_session_enrollments.php?id=<?php echo $session_id; ?>" class="btn btn-info">
                                <i class="fas fa-users mr-2"></i>Manage Enrollments
                            </a>
                            <?php if ($session['status'] == 'scheduled' || $session['status'] == 'in_progress'): ?>
                                <a href="training_attendance.php?id=<?php echo $session_id; ?>" class="btn btn-success">
                                    <i class="fas fa-clipboard-check mr-2"></i>Take Attendance
                                </a>
                            <?php endif; ?>
                            <?php if ($session['status'] == 'completed'): ?>
                                <a href="training_evaluation.php?id=<?php echo $session_id; ?>" class="btn btn-primary">
                                    <i class="fas fa-chart-bar mr-2"></i>View Evaluations
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Enrollment Statistics -->
                <div class="card mb-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="card-title mb-0"><i class="fas fa-chart-pie mr-2"></i>Enrollment Stats</h5>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-6 mb-3">
                                <div class="border rounded p-2 bg-white">
                                    <h4 class="text-primary mb-0"><?php echo $enrollment_stats['total_enrolled']; ?></h4>
                                    <small class="text-muted">Total</small>
                                </div>
                            </div>
                            <div class="col-6 mb-3">
                                <div class="border rounded p-2 bg-white">
                                    <h4 class="text-success mb-0"><?php echo $enrollment_stats['attended']; ?></h4>
                                    <small class="text-muted">Attended</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="border rounded p-2 bg-white">
                                    <h4 class="text-warning mb-0"><?php echo $enrollment_stats['registered']; ?></h4>
                                    <small class="text-muted">Registered</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="border rounded p-2 bg-white">
                                    <h4 class="text-danger mb-0"><?php echo $enrollment_stats['no_show']; ?></h4>
                                    <small class="text-muted">No Show</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Session Timeline -->
                <div class="card">
                    <div class="card-header bg-secondary text-white">
                        <h5 class="card-title mb-0"><i class="fas fa-clock mr-2"></i>Session Timeline</h5>
                    </div>
                    <div class="card-body">
                        <div class="small">
                            <?php
                            $now = time();
                            $start = strtotime($session['start_datetime']);
                            $end = strtotime($session['end_datetime']);
                            
                            if ($now < $start) {
                                $days_until = ceil(($start - $now) / (60 * 60 * 24));
                                echo '<p class="text-primary"><i class="fas fa-clock mr-1"></i> Starts in ' . $days_until . ' day' . ($days_until != 1 ? 's' : '') . '</p>';
                            } elseif ($now >= $start && $now <= $end) {
                                echo '<p class="text-warning"><i class="fas fa-play-circle mr-1"></i> In Progress</p>';
                            } else {
                                $days_ago = floor(($now - $end) / (60 * 60 * 24));
                                echo '<p class="text-success"><i class="fas fa-check-circle mr-1"></i> Completed ' . $days_ago . ' day' . ($days_ago != 1 ? 's' : '') . ' ago</p>';
                            }
                            ?>
                            <p class="mb-1"><strong>Created:</strong> <?php echo date('M j, Y', strtotime($session['created_at'])); ?></p>
                            <?php if ($session['updated_at'] != $session['created_at']): ?>
                                <p class="mb-0"><strong>Updated:</strong> <?php echo date('M j, Y', strtotime($session['updated_at'])); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
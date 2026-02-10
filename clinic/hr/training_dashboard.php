<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Handle actions
if (isset($_GET['action']) && isset($_GET['id'])) {
    $session_id = intval($_GET['id']);
    
    if ($_GET['action'] == 'cancel_session') {
        $sql = "UPDATE training_sessions SET status = 'cancelled' WHERE session_id = ?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("i", $session_id);
        
        if ($stmt->execute()) {
            // Log the action
            $audit_sql = "INSERT INTO hr_audit_log (user_id, action, description, table_name, record_id) VALUES (?, 'training_session_cancelled', ?, 'training_sessions', ?)";
            $audit_stmt = $mysqli->prepare($audit_sql);
            $description = "Cancelled training session ID: $session_id";
            $audit_stmt->bind_param("isi", $_SESSION['user_id'], $description, $session_id);
            $audit_stmt->execute();
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Training session cancelled successfully!";
        } else {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error cancelling session: " . $stmt->error;
        }
        header("Location: training_dashboard.php");
        exit;
    }
}

// Default Column Sortby/Order Filter
$sort = "ts.start_datetime";
$order = "ASC";

// Get filter parameters
$course_filter = $_GET['course'] ?? '';
$status_filter = $_GET['status'] ?? 'all';
$type_filter = $_GET['type'] ?? 'all';
$provider_filter = $_GET['provider'] ?? '';
$search = $_GET['search'] ?? '';

// Date Range for Sessions
$dtf = sanitizeInput($_GET['dtf'] ?? date('Y-m-01'));
$dtt = sanitizeInput($_GET['dtt'] ?? date('Y-m-t'));

// Build query with filters
$sql = "SELECT SQL_CALC_FOUND_ROWS 
            ts.*, tc.course_name, tc.course_code, tc.training_type,
            tp.provider_name, e.first_name, e.last_name,
            (SELECT COUNT(*) FROM training_enrollments te WHERE te.session_id = ts.session_id) as enrolled_count
        FROM training_sessions ts 
        JOIN training_courses tc ON ts.course_id = tc.course_id 
        LEFT JOIN training_providers tp ON tc.provider_id = tp.provider_id 
        LEFT JOIN employees e ON ts.trainer_id = e.employee_id 
        WHERE DATE(ts.start_datetime) BETWEEN '$dtf' AND '$dtt'";

$params = [];
$types = '';

if (!empty($course_filter)) {
    $sql .= " AND ts.course_id = ?";
    $params[] = $course_filter;
    $types .= 'i';
}

if (!empty($status_filter) && $status_filter != 'all') {
    $sql .= " AND ts.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

if (!empty($type_filter) && $type_filter != 'all') {
    $sql .= " AND tc.training_type = ?";
    $params[] = $type_filter;
    $types .= 's';
}

if (!empty($provider_filter)) {
    $sql .= " AND tc.provider_id = ?";
    $params[] = $provider_filter;
    $types .= 'i';
}

if (!empty($search)) {
    $sql .= " AND (tc.course_name LIKE ? OR tc.course_code LIKE ? OR tp.provider_name LIKE ? OR ts.location LIKE ?)";
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
$sessions_result = $stmt->get_result();

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get statistics for dashboard
$stats_sql = "
    SELECT 
        (SELECT COUNT(*) FROM training_sessions WHERE status = 'scheduled') as upcoming_sessions,
        (SELECT COUNT(*) FROM training_sessions WHERE status = 'in_progress') as ongoing_sessions,
        (SELECT COUNT(*) FROM training_sessions WHERE status = 'completed') as completed_sessions,
        (SELECT COUNT(*) FROM training_enrollments WHERE enrollment_status = 'registered') as pending_enrollments,
        (SELECT COUNT(*) FROM training_enrollments WHERE completion_status = 'completed') as completed_trainings,
        (SELECT COUNT(*) FROM training_courses WHERE is_active = 1) as active_courses,
        (SELECT COUNT(*) FROM training_providers WHERE is_active = 1) as active_providers
";
$stats_result = $mysqli->query($stats_sql);
$stats = $stats_result->fetch_assoc();

// Get courses for filter
$courses_sql = "SELECT course_id, course_name, course_code FROM training_courses WHERE is_active = 1 ORDER BY course_name";
$courses_result = $mysqli->query($courses_sql);

// Get providers for filter
$providers_sql = "SELECT provider_id, provider_name FROM training_providers WHERE is_active = 1 ORDER BY provider_name";
$providers_result = $mysqli->query($providers_sql);

// Get recent enrollments for sidebar
$recent_enrollments_sql = "
    SELECT te.*, ts.start_datetime, tc.course_name, 
           emp.first_name, emp.last_name, emp.employee_number
    FROM training_enrollments te
    JOIN training_sessions ts ON te.session_id = ts.session_id
    JOIN training_courses tc ON ts.course_id = tc.course_id
    JOIN employees emp ON te.employee_id = emp.employee_id
    ORDER BY te.created_at DESC
    LIMIT 5
";
$recent_enrollments = $mysqli->query($recent_enrollments_sql);
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0"><i class="fas fa-fw fa-graduation-cap mr-2"></i>Training Management Dashboard</h3>
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
                            <input type="search" class="form-control" name="search" value="<?php if (isset($search)) { echo stripslashes(nullable_htmlentities($search)); } ?>" placeholder="Search courses, providers, locations..." autofocus>
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
                            <a href="add_training_session.php" class="btn btn-success">
                                <i class="fas fa-calendar-plus mr-2"></i>Schedule Session
                            </a>
                            <a href="manage_training_courses.php" class="btn btn-info">
                                <i class="fas fa-book mr-2"></i>Manage Courses
                            </a>
                            <a href="manage_training_providers.php" class="btn btn-warning">
                                <i class="fas fa-building mr-2"></i>Manage Providers
                            </a>
                        </div>
                        <div class="btn-group mr-2">
                            <span class="btn btn-light border">
                                <i class="fas fa-calendar-check text-primary mr-1"></i>
                                Upcoming: <strong><?php echo $stats['upcoming_sessions']; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-play-circle text-warning mr-1"></i>
                                Ongoing: <strong><?php echo $stats['ongoing_sessions']; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-check-circle text-success mr-1"></i>
                                Completed: <strong><?php echo $stats['completed_sessions']; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-users text-info mr-1"></i>
                                Enrollments: <strong><?php echo $stats['pending_enrollments']; ?></strong>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php if (isset($_GET['dtf']) || $status_filter != 'all' || $course_filter || $type_filter != 'all') { echo "show"; } ?>" id="advancedFilter">
                <div class="row">
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Date Range</label>
                            <select onchange="this.form.submit()" class="form-control select2" name="canned_date">
                                <option <?php if (($_GET['canned_date'] ?? '') == "custom") { echo "selected"; } ?> value="custom">Custom</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "today") { echo "selected"; } ?> value="today">Today</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "tomorrow") { echo "selected"; } ?> value="tomorrow">Tomorrow</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "thisweek") { echo "selected"; } ?> value="thisweek">This Week</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "nextweek") { echo "selected"; } ?> value="nextweek">Next Week</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "thismonth") { echo "selected"; } ?> value="thismonth">This Month</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "nextmonth") { echo "selected"; } ?> value="nextmonth">Next Month</option>
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
                            <label>Session Status</label>
                            <select class="form-control select2" name="status" onchange="this.form.submit()">
                                <option value="all" <?php if ($status_filter == "all") { echo "selected"; } ?>>- All Statuses -</option>
                                <option value="scheduled" <?php if ($status_filter == "scheduled") { echo "selected"; } ?>>Scheduled</option>
                                <option value="in_progress" <?php if ($status_filter == "in_progress") { echo "selected"; } ?>>In Progress</option>
                                <option value="completed" <?php if ($status_filter == "completed") { echo "selected"; } ?>>Completed</option>
                                <option value="cancelled" <?php if ($status_filter == "cancelled") { echo "selected"; } ?>>Cancelled</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Training Type</label>
                            <select class="form-control select2" name="type" onchange="this.form.submit()">
                                <option value="all" <?php if ($type_filter == "all") { echo "selected"; } ?>>- All Types -</option>
                                <option value="technical" <?php if ($type_filter == "technical") { echo "selected"; } ?>>Technical</option>
                                <option value="soft_skills" <?php if ($type_filter == "soft_skills") { echo "selected"; } ?>>Soft Skills</option>
                                <option value="compliance" <?php if ($type_filter == "compliance") { echo "selected"; } ?>>Compliance</option>
                                <option value="leadership" <?php if ($type_filter == "leadership") { echo "selected"; } ?>>Leadership</option>
                                <option value="mandatory" <?php if ($type_filter == "mandatory") { echo "selected"; } ?>>Mandatory</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Course</label>
                            <select class="form-control select2" name="course" onchange="this.form.submit()">
                                <option value="">- All Courses -</option>
                                <?php
                                while ($row = mysqli_fetch_array($courses_result)) {
                                    $course_id = intval($row['course_id']);
                                    $course_name = nullable_htmlentities($row['course_name']);
                                    $course_code = nullable_htmlentities($row['course_code']);
                                ?>
                                    <option value="<?php echo $course_id; ?>" <?php if ($course_id == $course_filter) { echo "selected"; } ?>>
                                        <?php echo $course_name . " (" . $course_code . ")"; ?>
                                    </option>
                                <?php
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Training Provider</label>
                            <select class="form-control select2" name="provider" onchange="this.form.submit()">
                                <option value="">- All Providers -</option>
                                <?php
                                mysqli_data_seek($providers_result, 0);
                                while ($row = mysqli_fetch_array($providers_result)) {
                                    $provider_id = intval($row['provider_id']);
                                    $provider_name = nullable_htmlentities($row['provider_name']);
                                ?>
                                    <option value="<?php echo $provider_id; ?>" <?php if ($provider_id == $provider_filter) { echo "selected"; } ?>>
                                        <?php echo $provider_name; ?>
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
    
    <div class="row">
        <!-- Main Content -->
        <div class="col-md-9">
            <div class="table-responsive-sm">
                <table class="table table-hover mb-0">
                    <thead class="<?php if ($num_rows[0] == 0) { echo "d-none"; } ?> bg-light">
                    <tr>
                        <th>Course</th>
                        <th>Date & Time</th>
                        <th>Location</th>
                        <th>Trainer</th>
                        <th>Provider</th>
                        <th>Enrolled</th>
                        <th>Status</th>
                        <th class="text-center">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php
                    while ($row = mysqli_fetch_array($sessions_result)) {
                        $session_id = intval($row['session_id']);
                        $course_name = nullable_htmlentities($row['course_name']);
                        $course_code = nullable_htmlentities($row['course_code']);
                        $training_type = nullable_htmlentities($row['training_type']);
                        $location = nullable_htmlentities($row['location'] ?? 'TBD');
                        $trainer_name = $row['first_name'] ? $row['first_name'] . ' ' . $row['last_name'] : 'Not Assigned';
                        $provider_name = nullable_htmlentities($row['provider_name'] ?? 'Internal');
                        $start_datetime = nullable_htmlentities($row['start_datetime']);
                        $end_datetime = nullable_htmlentities($row['end_datetime']);
                        $max_participants = intval($row['max_participants']);
                        $enrolled_count = intval($row['enrolled_count']);
                        $status = nullable_htmlentities($row['status']);

                        // Status badge styling
                        $status_badge = "";
                        switch($status) {
                            case 'scheduled':
                                $status_badge = "badge-primary";
                                break;
                            case 'in_progress':
                                $status_badge = "badge-warning";
                                break;
                            case 'completed':
                                $status_badge = "badge-success";
                                break;
                            case 'cancelled':
                                $status_badge = "badge-danger";
                                break;
                            default:
                                $status_badge = "badge-light";
                        }

                        // Training type badge
                        $type_badge = "";
                        switch($training_type) {
                            case 'technical':
                                $type_badge = "badge-info";
                                break;
                            case 'soft_skills':
                                $type_badge = "badge-success";
                                break;
                            case 'compliance':
                                $type_badge = "badge-warning";
                                break;
                            case 'leadership':
                                $type_badge = "badge-primary";
                                break;
                            case 'mandatory':
                                $type_badge = "badge-danger";
                                break;
                            default:
                                $type_badge = "badge-secondary";
                        }
                        ?>
                        <tr>
                            <td>
                                <div class="font-weight-bold"><?php echo $course_name; ?></div>
                                <div class="small">
                                    <span class="badge <?php echo $type_badge; ?> training-type-badge"><?php echo ucfirst(str_replace('_', ' ', $training_type)); ?></span>
                                    <span class="text-muted"><?php echo $course_code; ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="font-weight-bold"><?php echo date('M j, Y', strtotime($start_datetime)); ?></div>
                                <div class="small text-muted">
                                    <?php echo date('g:i A', strtotime($start_datetime)); ?> - <?php echo date('g:i A', strtotime($end_datetime)); ?>
                                </div>
                            </td>
                            <td><?php echo $location; ?></td>
                            <td><?php echo $trainer_name; ?></td>
                            <td><?php echo $provider_name; ?></td>
                            <td>
                                <div class="font-weight-bold"><?php echo $enrolled_count; ?>/<?php echo $max_participants ?: '∞'; ?></div>
                                <?php if ($max_participants > 0): ?>
                                    <div class="progress" style="height: 5px;">
                                        <div class="progress-bar <?php echo ($enrolled_count >= $max_participants) ? 'bg-danger' : 'bg-success'; ?>" 
                                             style="width: <?php echo min(100, ($enrolled_count / $max_participants) * 100); ?>%">
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?php echo $status_badge; ?>"><?php echo ucfirst(str_replace('_', ' ', $status)); ?></span>
                            </td>
                            <td>
                                <div class="dropdown dropleft text-center">
                                    <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                        <i class="fas fa-ellipsis-h"></i>
                                    </button>
                                    <div class="dropdown-menu">
                                        <a class="dropdown-item" href="view_training_session.php?id=<?php echo $session_id; ?>">
                                            <i class="fas fa-fw fa-eye mr-2"></i>View Details
                                        </a>
                                        <a class="dropdown-item" href="edit_training_session.php?id=<?php echo $session_id; ?>">
                                            <i class="fas fa-fw fa-edit mr-2"></i>Edit Session
                                        </a>
                                        <a class="dropdown-item" href="manage_session_enrollments.php?id=<?php echo $session_id; ?>">
                                            <i class="fas fa-fw fa-users mr-2"></i>Manage Enrollments
                                        </a>
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item" href="training_attendance.php?id=<?php echo $session_id; ?>">
                                            <i class="fas fa-fw fa-clipboard-check mr-2"></i>Take Attendance
                                        </a>
                                        <a class="dropdown-item" href="training_evaluation.php?id=<?php echo $session_id; ?>">
                                            <i class="fas fa-fw fa-chart-bar mr-2"></i>Evaluations
                                        </a>
                                        <?php if ($status == 'scheduled'): ?>
                                            <div class="dropdown-divider"></div>
                                            <a class="dropdown-item text-danger confirm-link" href="training_dashboard.php?action=cancel_session&id=<?php echo $session_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token'] ?>" data-confirm-message="Are you sure you want to cancel this training session?">
                                                <i class="fas fa-fw fa-times mr-2"></i>Cancel Session
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php
                    } 
                    
                    if ($num_rows[0] == 0) {
                        ?>
                        <tr>
                            <td colspan="8" class="text-center py-4">
                                <i class="fas fa-calendar-times fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">No training sessions found</h5>
                                <p class="text-muted">No sessions match your current filters.</p>
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
        </div>

        <!-- Sidebar -->
        <div class="col-md-3">
            <!-- Quick Stats -->
            <div class="card mb-4">
                <div class="card-header bg-info text-white">
                    <h6 class="card-title mb-0"><i class="fas fa-chart-pie mr-2"></i>Training Statistics</h6>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-6 mb-3">
                            <div class="border rounded p-2 bg-white">
                                <h5 class="text-primary mb-0"><?php echo $stats['active_courses']; ?></h5>
                                <small class="text-muted">Active Courses</small>
                            </div>
                        </div>
                        <div class="col-6 mb-3">
                            <div class="border rounded p-2 bg-white">
                                <h5 class="text-success mb-0"><?php echo $stats['active_providers']; ?></h5>
                                <small class="text-muted">Providers</small>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="border rounded p-2 bg-white">
                                <h5 class="text-warning mb-0"><?php echo $stats['pending_enrollments']; ?></h5>
                                <small class="text-muted">Pending Enrollments</small>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="border rounded p-2 bg-white">
                                <h5 class="text-info mb-0"><?php echo $stats['completed_trainings']; ?></h5>
                                <small class="text-muted">Completed</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Enrollments -->
            <div class="card">
                <div class="card-header bg-warning">
                    <h6 class="card-title mb-0"><i class="fas fa-clock mr-2"></i>Recent Enrollments</h6>
                </div>
                <div class="card-body p-0">
                    <?php if ($recent_enrollments->num_rows > 0): ?>
                        <div class="list-group list-group-flush">
                            <?php while ($enrollment = $recent_enrollments->fetch_assoc()): ?>
                                <div class="list-group-item">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1"><?php echo htmlspecialchars($enrollment['first_name'] . ' ' . $enrollment['last_name']); ?></h6>
                                        <small class="text-muted"><?php echo date('M j', strtotime($enrollment['created_at'])); ?></small>
                                    </div>
                                    <p class="mb-1 small"><?php echo htmlspecialchars($enrollment['course_name']); ?></p>
                                    <small class="text-muted">
                                        <span class="badge badge-<?php 
                                            switch($enrollment['enrollment_status']) {
                                                case 'registered': echo 'primary'; break;
                                                case 'attended': echo 'success'; break;
                                                case 'no_show': echo 'danger'; break;
                                                default: echo 'secondary';
                                            }
                                        ?>">
                                            <?php echo ucfirst($enrollment['enrollment_status']); ?>
                                        </span>
                                    </small>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-3">
                            <i class="fas fa-users text-muted mb-2"></i>
                            <p class="text-muted small mb-0">No recent enrollments</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
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

    // Confirm links
    $('.confirm-link').click(function(e) {
        e.preventDefault();
        var message = $(this).data('confirm-message') || 'Are you sure?';
        var href = $(this).attr('href');
        
        if (confirm(message)) {
            window.location.href = href;
        }
    });
});
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
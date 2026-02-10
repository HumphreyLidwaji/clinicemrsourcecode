<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Handle actions
if (isset($_GET['action']) && isset($_GET['id'])) {
    $course_id = intval($_GET['id']);
    
    if ($_GET['action'] == 'toggle_status') {
        // Get current status
        $current_sql = "SELECT is_active FROM training_courses WHERE course_id = ?";
        $current_stmt = $mysqli->prepare($current_sql);
        $current_stmt->bind_param("i", $course_id);
        $current_stmt->execute();
        $current_status = $current_stmt->get_result()->fetch_assoc()['is_active'];
        
        $new_status = $current_status ? 0 : 1;
        
        $sql = "UPDATE training_courses SET is_active = ? WHERE course_id = ?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("ii", $new_status, $course_id);
        
        if ($stmt->execute()) {
            // Log the action
            $audit_sql = "INSERT INTO hr_audit_log (user_id, action, description, table_name, record_id) VALUES (?, 'training_course_updated', ?, 'training_courses', ?)";
            $audit_stmt = $mysqli->prepare($audit_sql);
            $action = $new_status ? 'activated' : 'deactivated';
            $description = "$action training course ID: $course_id";
            $audit_stmt->bind_param("isi", $_SESSION['user_id'], $description, $course_id);
            $audit_stmt->execute();
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Course " . ($new_status ? "activated" : "deactivated") . " successfully!";
        } else {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error updating course: " . $stmt->error;
        }
        header("Location: manage_training_courses.php");
        exit;
    }
    
    if ($_GET['action'] == 'delete') {
        // Check if course has sessions
        $check_sql = "SELECT COUNT(*) as session_count FROM training_sessions WHERE course_id = ?";
        $check_stmt = $mysqli->prepare($check_sql);
        $check_stmt->bind_param("i", $course_id);
        $check_stmt->execute();
        $session_count = $check_stmt->get_result()->fetch_assoc()['session_count'];
        
        if ($session_count > 0) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Cannot delete course with existing training sessions! Deactivate instead.";
        } else {
            $sql = "DELETE FROM training_courses WHERE course_id = ?";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param("i", $course_id);
            
            if ($stmt->execute()) {
                // Log the action
                $audit_sql = "INSERT INTO hr_audit_log (user_id, action, description, table_name, record_id) VALUES (?, 'training_course_deleted', ?, 'training_courses', ?)";
                $audit_stmt = $mysqli->prepare($audit_sql);
                $description = "Deleted training course ID: $course_id";
                $audit_stmt->bind_param("isi", $_SESSION['user_id'], $description, $course_id);
                $audit_stmt->execute();
                
                $_SESSION['alert_type'] = "success";
                $_SESSION['alert_message'] = "Course deleted successfully!";
            } else {
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = "Error deleting course: " . $stmt->error;
            }
        }
        header("Location: manage_training_courses.php");
        exit;
    }
}

// Default Column Sortby/Order Filter
$sort = "tc.course_name";
$order = "ASC";

// Get filter parameters
$type_filter = $_GET['type'] ?? 'all';
$status_filter = $_GET['status'] ?? 'all';
$provider_filter = $_GET['provider'] ?? '';
$search = $_GET['search'] ?? '';

// Build query with filters
$sql = "SELECT SQL_CALC_FOUND_ROWS 
            tc.*, tp.provider_name,
            (SELECT COUNT(*) FROM training_sessions ts WHERE ts.course_id = tc.course_id) as session_count
        FROM training_courses tc 
        LEFT JOIN training_providers tp ON tc.provider_id = tp.provider_id 
        WHERE 1=1";

$params = [];
$types = '';

if (!empty($type_filter) && $type_filter != 'all') {
    $sql .= " AND tc.training_type = ?";
    $params[] = $type_filter;
    $types .= 's';
}

if (!empty($status_filter) && $status_filter != 'all') {
    $sql .= " AND tc.is_active = ?";
    $params[] = ($status_filter == 'active') ? 1 : 0;
    $types .= 'i';
}

if (!empty($provider_filter)) {
    $sql .= " AND tc.provider_id = ?";
    $params[] = $provider_filter;
    $types .= 'i';
}

if (!empty($search)) {
    $sql .= " AND (tc.course_name LIKE ? OR tc.course_code LIKE ? OR tc.category LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= 'sss';
}

$sql .= " ORDER BY $sort $order LIMIT $record_from, $record_to";

$stmt = $mysqli->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$courses_result = $stmt->get_result();

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get statistics
$total_courses = $num_rows[0];
$active_courses = 0;
$inactive_courses = 0;

// Reset pointer and calculate
mysqli_data_seek($courses_result, 0);
while ($course = mysqli_fetch_assoc($courses_result)) {
    if ($course['is_active']) {
        $active_courses++;
    } else {
        $inactive_courses++;
    }
}
mysqli_data_seek($courses_result, $record_from);

// Get providers for filter
$providers_sql = "SELECT provider_id, provider_name FROM training_providers WHERE is_active = 1 ORDER BY provider_name";
$providers_result = $mysqli->query($providers_sql);
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0"><i class="fas fa-fw fa-book mr-2"></i>Manage Training Courses</h3>
        <div class="card-tools">
            <a href="training_dashboard.php" class="btn btn-light">
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
                            <input type="search" class="form-control" name="search" value="<?php if (isset($search)) { echo stripslashes(nullable_htmlentities($search)); } ?>" placeholder="Search courses, codes, categories..." autofocus>
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
                            <a href="add_training_course.php" class="btn btn-success">
                                <i class="fas fa-plus mr-2"></i>Add Course
                            </a>
                        </div>
                        <div class="btn-group mr-2">
                            <span class="btn btn-light border">
                                <i class="fas fa-book text-primary mr-1"></i>
                                Total: <strong><?php echo $total_courses; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-check-circle text-success mr-1"></i>
                                Active: <strong><?php echo $active_courses; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-times-circle text-danger mr-1"></i>
                                Inactive: <strong><?php echo $inactive_courses; ?></strong>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php if ($type_filter != 'all' || $status_filter != 'all' || $provider_filter) { echo "show"; } ?>" id="advancedFilter">
                <div class="row">
                    <div class="col-md-3">
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
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Status</label>
                            <select class="form-control select2" name="status" onchange="this.form.submit()">
                                <option value="all" <?php if ($status_filter == "all") { echo "selected"; } ?>>- All Statuses -</option>
                                <option value="active" <?php if ($status_filter == "active") { echo "selected"; } ?>>Active</option>
                                <option value="inactive" <?php if ($status_filter == "inactive") { echo "selected"; } ?>>Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Provider</label>
                            <select class="form-control select2" name="provider" onchange="this.form.submit()">
                                <option value="">- All Providers -</option>
                                <?php
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
    
    <div class="table-responsive-sm">
        <table class="table table-hover mb-0">
            <thead class="<?php if ($num_rows[0] == 0) { echo "d-none"; } ?> bg-light">
            <tr>
                <th>Course Code</th>
                <th>Course Name</th>
                <th>Category</th>
                <th>Type</th>
                <th>Duration</th>
                <th>Provider</th>
                <th>Sessions</th>
                <th>Status</th>
                <th class="text-center">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php
            while ($row = mysqli_fetch_array($courses_result)) {
                $course_id = intval($row['course_id']);
                $course_name = nullable_htmlentities($row['course_name']);
                $course_code = nullable_htmlentities($row['course_code']);
                $category = nullable_htmlentities($row['category'] ?? 'N/A');
                $training_type = nullable_htmlentities($row['training_type']);
                $duration_hours = floatval($row['duration_hours']);
                $provider_name = nullable_htmlentities($row['provider_name'] ?? 'Internal');
                $session_count = intval($row['session_count']);
                $is_active = $row['is_active'];

                // Type badge styling
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
                    <td class="font-weight-bold text-primary"><?php echo $course_code; ?></td>
                    <td>
                        <div class="font-weight-bold"><?php echo $course_name; ?></div>
                        <?php if (!empty($row['learning_objectives'])): ?>
                            <small class="text-muted"><?php echo substr(strip_tags($row['learning_objectives']), 0, 50); ?>...</small>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $category; ?></td>
                    <td>
                        <span class="badge <?php echo $type_badge; ?> training-type-badge">
                            <?php echo ucfirst(str_replace('_', ' ', $training_type)); ?>
                        </span>
                    </td>
                    <td><?php echo $duration_hours; ?> hrs</td>
                    <td><?php echo $provider_name; ?></td>
                    <td>
                        <span class="font-weight-bold <?php echo $session_count > 0 ? 'text-success' : 'text-muted'; ?>">
                            <?php echo $session_count; ?>
                        </span>
                    </td>
                    <td>
                        <span class="badge <?php echo $is_active ? 'badge-success' : 'badge-danger'; ?>">
                            <?php echo $is_active ? 'Active' : 'Inactive'; ?>
                        </span>
                    </td>
                    <td>
                        <div class="dropdown dropleft text-center">
                            <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                <i class="fas fa-ellipsis-h"></i>
                            </button>
                            <div class="dropdown-menu">
                                <a class="dropdown-item" href="view_training_course.php?id=<?php echo $course_id; ?>">
                                    <i class="fas fa-fw fa-eye mr-2"></i>View Details
                                </a>
                                <a class="dropdown-item" href="edit_training_course.php?id=<?php echo $course_id; ?>">
                                    <i class="fas fa-fw fa-edit mr-2"></i>Edit Course
                                </a>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item" href="manage_training_courses.php?action=toggle_status&id=<?php echo $course_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token'] ?>">
                                    <i class="fas fa-fw fa-power-off mr-2"></i>
                                    <?php echo $is_active ? 'Deactivate' : 'Activate'; ?>
                                </a>
                                <?php if ($session_count == 0): ?>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item text-danger confirm-link" href="manage_training_courses.php?action=delete&id=<?php echo $course_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token'] ?>" data-confirm-message="Are you sure you want to delete <?php echo htmlspecialchars($course_name); ?>? This action cannot be undone.">
                                        <i class="fas fa-fw fa-trash mr-2"></i>Delete Course
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
                    <td colspan="9" class="text-center py-4">
                        <i class="fas fa-book fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No training courses found</h5>
                        <p class="text-muted">No courses match your current filters.</p>
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
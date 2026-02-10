<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Handle form submissions via POST
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_department'])) {
        $department_name = sanitizeInput($_POST['department_name']);
        $department_description = sanitizeInput($_POST['department_description']);
        
        $stmt = $mysqli->prepare("INSERT INTO departments SET department_name=?, department_description=?, department_is_active=1");
        $stmt->bind_param("ss", $department_name, $department_description);
        
        if ($stmt->execute()) {
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Department added successfully!";
        } else {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error adding department: " . $mysqli->error;
        }
        $stmt->close();
    }
    
    elseif (isset($_POST['update_department'])) {
        $department_id = intval($_POST['department_id']);
        $department_name = sanitizeInput($_POST['department_name']);
        $department_description = sanitizeInput($_POST['department_description']);
        
        $stmt = $mysqli->prepare("UPDATE departments SET department_name=?, department_description=?, department_updated_at=NOW() WHERE department_id=?");
        $stmt->bind_param("ssi", $department_name, $department_description, $department_id);
        
        if ($stmt->execute()) {
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Department updated successfully!";
        } else {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error updating department: " . $mysqli->error;
        }
        $stmt->close();
    }
    
    // Redirect to prevent form resubmission
    header("Location: departments.php");
    exit;
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ajax_request'])) {
    header('Content-Type: application/json');
    
    if (isset($_POST['get_department_details'])) {
        $department_id = intval($_POST['department_id']);
        $department = $mysqli->query("SELECT * FROM departments WHERE department_id = $department_id")->fetch_assoc();
        echo json_encode($department);
        exit;
    }
    
    if (isset($_POST['delete_department'])) {
        $department_id = intval($_POST['department_id']);
        $stmt = $mysqli->prepare("UPDATE departments SET department_is_active = 0, department_archived_at = NOW() WHERE department_id = ?");
        $stmt->bind_param("i", $department_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Department archived successfully!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error archiving department: ' . $mysqli->error]);
        }
        $stmt->close();
        exit;
    }
    
    if (isset($_POST['restore_department'])) {
        $department_id = intval($_POST['department_id']);
        $stmt = $mysqli->prepare("UPDATE departments SET department_is_active = 1, department_archived_at = NULL WHERE department_id = ?");
        $stmt->bind_param("i", $department_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Department restored successfully!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error restoring department: ' . $mysqli->error]);
        }
        $stmt->close();
        exit;
    }
}

// Default Column Sortby/Order Filter
$sort = "department_name";
$order = "ASC";

// Filter parameters
$active_filter = $_GET['active'] ?? '';

// Search Query
$q = sanitizeInput($_GET['q'] ?? '');
if (!empty($q)) {
    $search_query = "AND (
        d.department_name LIKE '%$q%' 
        OR d.department_description LIKE '%$q%'
    )";
} else {
    $search_query = '';
}

// Build WHERE clause for filters
$where_conditions = ["1=1"];

if ($active_filter === 'inactive') {
    $where_conditions[] = "d.department_is_active = 0";
} else {
    $where_conditions[] = "d.department_is_active = 1";
}

$where_clause = implode(' AND ', $where_conditions);

// Get all departments
$departments_sql = "
    SELECT SQL_CALC_FOUND_ROWS d.*
    FROM departments d 
    WHERE $where_clause
    $search_query
    ORDER BY $sort $order
    LIMIT $record_from, $record_to
";

$departments = $mysqli->query($departments_sql);
$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get statistics
$total_departments = $mysqli->query("SELECT COUNT(*) FROM departments WHERE department_is_active = 1")->fetch_row()[0];
$inactive_departments = $mysqli->query("SELECT COUNT(*) FROM departments WHERE department_is_active = 0")->fetch_row()[0];

// Get employee count per department (if you have an employees table)
$employee_counts = [];
$employee_result = $mysqli->query("
    SELECT department_id, COUNT(*) as employee_count 
    FROM employees  
    GROUP BY department_id
");
while ($row = $employee_result->fetch_assoc()) {
    $employee_counts[$row['department_id']] = $row['employee_count'];
}

// Reset pointer for main query
$departments->data_seek(0);
?>

<div class="card">
    <div class="card-header bg-secondary py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-sitemap mr-2"></i>Departments Management
            </h3>
            <div class="card-tools">
                <button type="button" class="btn btn-light" data-toggle="modal" data-target="#addDepartmentModal">
                    <i class="fas fa-plus mr-2"></i>New Department
                </button>
            </div>
        </div>
    </div>

    <div class="card-header pb-2 pt-3">
        <form autocomplete="off">
            <div class="row">
                <div class="col-md-5">
                    <div class="form-group">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search departments, descriptions..." autofocus>
                            <div class="input-group-append">
                                <button class="btn btn-secondary" type="button" data-toggle="collapse" data-target="#advancedFilter"><i class="fas fa-filter"></i></button>
                                <button class="btn btn-primary"><i class="fa fa-search"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-7">
                    <div class="btn-toolbar form-group float-right">
                        <div class="btn-group">
                            <span class="btn btn-light border">
                                <i class="fas fa-sitemap text-secondary mr-1"></i>
                                Active: <strong><?php echo $total_departments; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-archive text-warning mr-1"></i>
                                Inactive: <strong><?php echo $inactive_departments; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-users text-primary mr-1"></i>
                                Total: <strong><?php echo $total_departments + $inactive_departments; ?></strong>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php if ($active_filter) { echo "show"; } ?>" id="advancedFilter">
                <div class="row">
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Status</label>
                            <select class="form-control select2" name="active" onchange="this.form.submit()">
                                <option value="active" <?php if ($active_filter !== 'inactive') echo "selected"; ?>>Active Departments</option>
                                <option value="inactive" <?php if ($active_filter === 'inactive') echo "selected"; ?>>Inactive Departments</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </form>
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

        <!-- Alert Container for AJAX Messages -->
        <div id="ajaxAlertContainer"></div>
    
        <div class="table-responsive-sm">
            <table class="table table-hover mb-0">
                <thead class="<?php if ($num_rows[0] == 0) { echo "d-none"; } ?> bg-light">
                <tr>
                    <th>
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=department_name&order=<?php echo $disp; ?>">
                            Department Name <?php if ($sort == 'department_name') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>Description</th>
                    <th>Employees</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th class="text-center">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php while($department = $departments->fetch_assoc()): 
                    $department_id = intval($department['department_id']);
                    $department_name = nullable_htmlentities($department['department_name']);
                    $department_description = nullable_htmlentities($department['department_description']);
                    $employee_count = $employee_counts[$department_id] ?? 0;
                    $is_active = intval($department['department_is_active']);
                    $created_date = date('M j, Y', strtotime($department['department_created_at']));
                    ?>
                    <tr class="<?php echo $is_active ? '' : 'text-muted bg-light'; ?>">
                        <td class="font-weight-bold">
                            <i class="fas fa-sitemap text-secondary mr-2"></i>
                            <?php echo $department_name; ?>
                        </td>
                        <td>
                            <?php if ($department_description): ?>
                                <small class="text-muted"><?php echo strlen($department_description) > 50 ? substr($department_description, 0, 50) . '...' : $department_description; ?></small>
                            <?php else: ?>
                                <em class="text-muted">No description</em>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-primary"><?php echo $employee_count; ?> employees</span>
                        </td>
                        <td>
                            <?php if ($is_active): ?>
                                <span class="badge badge-success">Active</span>
                            <?php else: ?>
                                <span class="badge badge-secondary">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <small class="text-muted"><?php echo $created_date; ?></small>
                        </td>
                        <td>
                            <div class="dropdown dropleft text-center">
                                <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                    <i class="fas fa-ellipsis-h"></i>
                                </button>
                                <div class="dropdown-menu">
                                    <a class="dropdown-item" href="department_details.php?department_id=<?php echo $department_id; ?>">
                                        <i class="fas fa-fw fa-eye mr-2"></i>View Details
                                    </a>
                                    <a class="dropdown-item" href="#" onclick="editDepartment(<?php echo $department_id; ?>)">
                                        <i class="fas fa-fw fa-edit mr-2"></i>Edit Department
                                    </a>
                                    <?php if ($is_active): ?>
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item text-danger" href="#" onclick="deleteDepartment(<?php echo $department_id; ?>)">
                                            <i class="fas fa-fw fa-archive mr-2"></i>Archive Department
                                        </a>
                                    <?php else: ?>
                                        <a class="dropdown-item text-success" href="#" onclick="restoreDepartment(<?php echo $department_id; ?>)">
                                            <i class="fas fa-fw fa-undo mr-2"></i>Restore Department
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; ?>

                <?php if ($num_rows[0] === 0): ?>
                    <tr>
                        <td colspan="6" class="text-center py-4">
                            <i class="fas fa-sitemap fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">No Departments Found</h5>
                            <p class="text-muted">
                                <?php echo ($active_filter || $search_query) ? 
                                    'Try adjusting your filters or search criteria.' : 
                                    'Get started by creating your first department.'; 
                                ?>
                            </p>
                            <button type="button" class="btn btn-secondary mt-2" data-toggle="modal" data-target="#addDepartmentModal">
                                <i class="fas fa-plus mr-2"></i>Create First Department
                            </button>
                            <?php if ($active_filter || $search_query): ?>
                                <a href="departments.php" class="btn btn-outline-secondary mt-2 ml-2">
                                    <i class="fas fa-times mr-2"></i>Clear Filters
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Ends Card Body -->
        <?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/filter_footer.php'; ?>
    </div>
</div>

<!-- Add Department Modal -->
<div class="modal fade" id="addDepartmentModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-secondary text-white">
                <h5 class="modal-title"><i class="fas fa-sitemap mr-2"></i>Add New Department</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Department Name *</label>
                        <input type="text" class="form-control" name="department_name" required>
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <textarea class="form-control" name="department_description" rows="3" placeholder="Brief description of the department's purpose and function..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_department" class="btn btn-primary">Add Department</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Department Modal -->
<div class="modal fade" id="editDepartmentModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-warning text-white">
                <h5 class="modal-title"><i class="fas fa-edit mr-2"></i>Edit Department</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST">
                <input type="hidden" name="department_id" id="edit_department_id">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Department Name *</label>
                        <input type="text" class="form-control" name="department_name" id="edit_department_name" required>
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <textarea class="form-control" name="department_description" id="edit_department_description" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_department" class="btn btn-warning">Update Department</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.select2').select2();

    // Auto-close alerts after 5 seconds
    setTimeout(() => {
        $('.alert').alert('close');
    }, 5000);
});

function editDepartment(departmentId) {
    $.ajax({
        url: 'departments.php',
        type: 'POST',
        data: {
            ajax_request: 1,
            get_department_details: 1,
            department_id: departmentId
        },
        success: function(response) {
            const department = JSON.parse(response);
            $('#edit_department_id').val(department.department_id);
            $('#edit_department_name').val(department.department_name);
            $('#edit_department_description').val(department.department_description);
            $('#editDepartmentModal').modal('show');
        },
        error: function() {
            showAlert('Error loading department details. Please try again.', 'error');
        }
    });
}

function deleteDepartment(departmentId) {
    if (confirm('Are you sure you want to archive this department? This will make it inactive but preserve its data.')) {
        $.ajax({
            url: 'departments.php',
            type: 'POST',
            data: {
                ajax_request: 1,
                delete_department: 1,
                department_id: departmentId
            },
            success: function(response) {
                const result = JSON.parse(response);
                if (result.success) {
                    showAlert(result.message, 'success');
                    // Reload page after successful deletion
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    showAlert(result.message, 'error');
                }
            },
            error: function() {
                showAlert('Error archiving department. Please try again.', 'error');
            }
        });
    }
}

function restoreDepartment(departmentId) {
    if (confirm('Are you sure you want to restore this department?')) {
        $.ajax({
            url: 'departments.php',
            type: 'POST',
            data: {
                ajax_request: 1,
                restore_department: 1,
                department_id: departmentId
            },
            success: function(response) {
                const result = JSON.parse(response);
                if (result.success) {
                    showAlert(result.message, 'success');
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    showAlert(result.message, 'error');
                }
            },
            error: function() {
                showAlert('Error restoring department. Please try again.', 'error');
            }
        });
    }
}

function showAlert(message, type) {
    const alertClass = type === 'success' ? 'alert-success' : 'alert-danger';
    const iconClass = type === 'success' ? 'fa-check' : 'fa-exclamation-triangle';
    
    const alertHtml = `
        <div class="alert ${alertClass} alert-dismissible">
            <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
            <i class="icon fas ${iconClass}"></i>
            ${message}
        </div>
    `;
    
    $('#ajaxAlertContainer').html(alertHtml);
    
    // Auto-dismiss success alerts after 5 seconds
    if (type === 'success') {
        setTimeout(() => {
            $('.alert').alert('close');
        }, 5000);
    }
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + D for new department
    if (e.ctrlKey && e.keyCode === 68) {
        e.preventDefault();
        $('#addDepartmentModal').modal('show');
    }
    // Ctrl + F for focus search
    if (e.ctrlKey && e.keyCode === 70) {
        e.preventDefault();
        $('input[name="q"]').focus();
    }
});
</script>

<style>
.card {
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    margin-bottom: 1rem;
}
</style>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
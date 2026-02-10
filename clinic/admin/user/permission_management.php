<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Handle form actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    $action = sanitizeInput($_POST['action']);
    
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token.";
        header("Location: permission_management.php");
        exit;
    }

    switch ($action) {
        case 'create_permission':
            $permission_name = sanitizeInput($_POST['permission_name']);
            $permission_description = sanitizeInput($_POST['permission_description']);
            $permission_category = sanitizeInput($_POST['permission_category']);
            $is_module = isset($_POST['is_module']) ? 1 : 0;
            
            // FIX 1: Handle new category creation
            if ($permission_category === 'new_category') {
                $permission_category = sanitizeInput($_POST['new_category']);
            }
            
            if (!empty($permission_name) && !empty($permission_category)) {
                $sql = "INSERT INTO permissions (permission_name, permission_description, permission_category, is_module) VALUES (?, ?, ?, ?)";
                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param("sssi", $permission_name, $permission_description, $permission_category, $is_module);
                
                if ($stmt->execute()) {
                    $_SESSION['alert_type'] = "success";
                    $_SESSION['alert_message'] = "Permission created successfully!";
                } else {
                    $_SESSION['alert_type'] = "error";
                    $_SESSION['alert_message'] = "Error creating permission: " . $stmt->error;
                }
            }
            break;

        case 'update_permission':
            $permission_id = intval($_POST['permission_id']);
            $permission_name = sanitizeInput($_POST['permission_name']);
            $permission_description = sanitizeInput($_POST['permission_description']);
            $permission_category = sanitizeInput($_POST['permission_category']);
            $is_module = isset($_POST['is_module']) ? 1 : 0;
            
            $sql = "UPDATE permissions SET permission_name = ?, permission_description = ?, permission_category = ?, is_module = ? WHERE permission_id = ?";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param("sssii", $permission_name, $permission_description, $permission_category, $is_module, $permission_id);
            
            if ($stmt->execute()) {
                $_SESSION['alert_type'] = "success";
                $_SESSION['alert_message'] = "Permission updated successfully!";
            } else {
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = "Error updating permission: " . $stmt->error;
            }
            break;

        case 'delete_permission':
            $permission_id = intval($_POST['permission_id']);
            
            // Check if permission is assigned to any roles
            $check_sql = "SELECT COUNT(*) as role_count FROM role_permissions WHERE permission_id = ?";
            $check_stmt = $mysqli->prepare($check_sql);
            $check_stmt->bind_param("i", $permission_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            $role_count = $check_result->fetch_assoc()['role_count'];
            
            // Check if permission is assigned to any users
            $user_check_sql = "SELECT COUNT(*) as user_count FROM user_permissions WHERE permission_id = ?";
            $user_check_stmt = $mysqli->prepare($user_check_sql);
            $user_check_stmt->bind_param("i", $permission_id);
            $user_check_stmt->execute();
            $user_check_result = $user_check_stmt->get_result();
            $user_count = $user_check_result->fetch_assoc()['user_count'];
            
            if ($role_count > 0 || $user_count > 0) {
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = "Cannot delete permission assigned to $role_count role(s) and $user_count user(s). Remove assignments first.";
            } else {
                $sql = "DELETE FROM permissions WHERE permission_id = ?";
                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param("i", $permission_id);
                
                if ($stmt->execute()) {
                    $_SESSION['alert_type'] = "success";
                    $_SESSION['alert_message'] = "Permission deleted successfully!";
                } else {
                    $_SESSION['alert_type'] = "error";
                    $_SESSION['alert_message'] = "Error deleting permission: " . $stmt->error;
                }
            }
            break;
    }
    
    header("Location: permission_management.php");
    exit;
}


// Get all permissions with usage statistics
$permissions_sql = "
    SELECT 
        p.*,
        COUNT(DISTINCT rp.role_id) as role_count,
        COUNT(DISTINCT up.user_id) as user_count
    FROM permissions p
    LEFT JOIN role_permissions rp ON p.permission_id = rp.permission_id AND rp.permission_value = 1
    LEFT JOIN user_permissions up ON p.permission_id = up.permission_id
    GROUP BY p.permission_id
    ORDER BY p.permission_category, p.permission_name
";
$permissions_result = $mysqli->query($permissions_sql);
$permissions = [];
while ($permission = $permissions_result->fetch_assoc()) {
    $permissions[] = $permission;
}

// Get unique categories for filter
$categories_sql = "SELECT DISTINCT permission_category FROM permissions ORDER BY permission_category";
$categories_result = $mysqli->query($categories_sql);
$categories = [];
while ($category = $categories_result->fetch_assoc()) {
    $categories[] = $category['permission_category'];
}

// Get permission statistics
$stats_sql = "
    SELECT 
        COUNT(*) as total_permissions,
        COUNT(CASE WHEN is_module = 1 THEN 1 END) as module_permissions,
        COUNT(CASE WHEN is_module = 0 THEN 1 END) as action_permissions,
        COUNT(DISTINCT permission_category) as category_count,
        SUM(role_count_data.role_count) as total_role_assignments,
        SUM(user_count_data.user_count) as total_user_overrides
    FROM permissions p
    LEFT JOIN (
        SELECT permission_id, COUNT(*) as role_count 
        FROM role_permissions 
        WHERE permission_value = 1 
        GROUP BY permission_id
    ) as role_count_data ON p.permission_id = role_count_data.permission_id
    LEFT JOIN (
        SELECT permission_id, COUNT(*) as user_count 
        FROM user_permissions 
        GROUP BY permission_id
    ) as user_count_data ON p.permission_id = user_count_data.permission_id
";
$stats_result = $mysqli->query($stats_sql);
$stats = $stats_result->fetch_assoc();

// Get category statistics
$category_stats_sql = "
    SELECT 
        permission_category,
        COUNT(*) as permission_count,
        COUNT(CASE WHEN is_module = 1 THEN 1 END) as module_count,
        COUNT(CASE WHEN is_module = 0 THEN 1 END) as action_count
    FROM permissions 
    GROUP BY permission_category 
    ORDER BY permission_count DESC
";
$category_stats_result = $mysqli->query($category_stats_sql);
$category_stats = [];
while ($category = $category_stats_result->fetch_assoc()) {
    $category_stats[] = $category;
}
?>

<div class="card">
    <div class="card-header bg-warning py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-key mr-2"></i>Permission Management
            </h3>
            <div class="card-tools">
                <button class="btn btn-success" data-toggle="modal" data-target="#createPermissionModal">
                    <i class="fas fa-plus mr-2"></i>New Permission
                </button>
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

        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-2">
                <div class="info-box bg-primary">
                    <span class="info-box-icon"><i class="fas fa-key"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Permissions</span>
                        <span class="info-box-number"><?php echo $stats['total_permissions']; ?></span>
                        <small class="text-muted"><?php echo $stats['category_count']; ?> categories</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-success">
                    <span class="info-box-icon"><i class="fas fa-cubes"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Module Permissions</span>
                        <span class="info-box-number"><?php echo $stats['module_permissions']; ?></span>
                        <small class="text-muted"><?php echo round(($stats['module_permissions'] / max(1, $stats['total_permissions'])) * 100); ?>%</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-info">
                    <span class="info-box-icon"><i class="fas fa-bolt"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Action Permissions</span>
                        <span class="info-box-number"><?php echo $stats['action_permissions']; ?></span>
                        <small class="text-muted"><?php echo round(($stats['action_permissions'] / max(1, $stats['total_permissions'])) * 100); ?>%</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-secondary">
                    <span class="info-box-icon"><i class="fas fa-user-tag"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Role Assignments</span>
                        <span class="info-box-number"><?php echo $stats['total_role_assignments']; ?></span>
                        <small class="text-muted">Avg: <?php echo round($stats['total_role_assignments'] / max(1, $stats['total_permissions'])); ?> per perm</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-dark">
                    <span class="info-box-icon"><i class="fas fa-user-cog"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">User Overrides</span>
                        <span class="info-box-number"><?php echo $stats['total_user_overrides']; ?></span>
                        <small class="text-muted">Custom assignments</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-warning">
                    <span class="info-box-icon"><i class="fas fa-tags"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Categories</span>
                        <span class="info-box-number"><?php echo $stats['category_count']; ?></span>
                        <small class="text-muted">Permission groups</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-bolt mr-2"></i>Quick Actions
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3 mb-2">
                                <a href="/clinic/admin/user/role_management.php" class="btn btn-primary btn-block">
                                    <i class="fas fa-user-tag mr-2"></i>Manage Roles
                                </a>
                            </div>
                            <div class="col-md-3 mb-2">
                                <a href="/clinic/admin/user/user_management.php" class="btn btn-info btn-block">
                                    <i class="fas fa-users mr-2"></i>Manage Users
                                </a>
                            </div>
                            <div class="col-md-3 mb-2">
                                <a href="/clinic/admin/user/permission_dashboard.php" class="btn btn-success btn-block">
                                    <i class="fas fa-tachometer-alt mr-2"></i>Permission Dashboard
                                </a>
                            </div>
                            <div class="col-md-3 mb-2">
                                <button class="btn btn-warning btn-block" data-toggle="modal" data-target="#createPermissionModal">
                                    <i class="fas fa-plus mr-2"></i>Add Permission
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Category Statistics -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-chart-pie mr-2"></i>Permission Categories
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php foreach ($category_stats as $category): ?>
                                <div class="col-md-3 mb-3">
                                    <div class="card bg-light">
                                        <div class="card-body text-center">
                                            <h5 class="card-title"><?php echo ucfirst(str_replace('_', ' ', $category['permission_category'])); ?></h5>
                                            <div class="row">
                                                <div class="col-6">
                                                    <small class="text-muted">Total</small>
                                                    <h4 class="text-primary"><?php echo $category['permission_count']; ?></h4>
                                                </div>
                                                <div class="col-6">
                                                    <small class="text-muted">Modules</small>
                                                    <h4 class="text-success"><?php echo $category['module_count']; ?></h4>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Permissions Table -->
        <div class="table-responsive">
            <table class="table table-striped table-hover" id="permissionsTable">
                <thead class="bg-light">
                    <tr>
                        <th>Permission Name</th>
                        <th>Description</th>
                        <th>Category</th>
                        <th>Type</th>
                        <th>Role Usage</th>
                        <th>User Overrides</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($permissions as $permission): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($permission['permission_name']); ?></strong>
                            </td>
                            <td><?php echo htmlspecialchars($permission['permission_description']); ?></td>
                            <td>
                                <span class="badge badge-secondary"><?php echo ucfirst(str_replace('_', ' ', $permission['permission_category'])); ?></span>
                            </td>
                            <td>
                                <?php if ($permission['is_module']): ?>
                                    <span class="badge badge-success">Module</span>
                                <?php else: ?>
                                    <span class="badge badge-info">Action</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge-primary"><?php echo $permission['role_count']; ?> roles</span>
                            </td>
                            <td>
                                <span class="badge badge-dark"><?php echo $permission['user_count']; ?> users</span>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-warning edit-permission" 
                                            data-permission-id="<?php echo $permission['permission_id']; ?>"
                                            data-permission-name="<?php echo htmlspecialchars($permission['permission_name']); ?>"
                                            data-permission-description="<?php echo htmlspecialchars($permission['permission_description']); ?>"
                                            data-permission-category="<?php echo htmlspecialchars($permission['permission_category']); ?>"
                                            data-is-module="<?php echo $permission['is_module']; ?>"
                                            title="Edit Permission">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <?php if ($permission['role_count'] == 0 && $permission['user_count'] == 0): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                            <input type="hidden" name="action" value="delete_permission">
                                            <input type="hidden" name="permission_id" value="<?php echo $permission['permission_id']; ?>">
                                            <button type="submit" class="btn btn-danger" 
                                                    onclick="return confirm('Are you sure you want to delete this permission?')"
                                                    title="Delete Permission">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <button class="btn btn-danger" disabled 
                                                title="Cannot delete permission assigned to roles or users">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Create Permission Modal -->
<div class="modal fade" id="createPermissionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">
                    <i class="fas fa-plus mr-2"></i>Create New Permission
                </h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span class="text-white">&times;</span>
                </button>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="create_permission">
                
                <div class="modal-body">
                    <div class="form-group">
                        <label for="permission_name">Permission Name *</label>
                        <input type="text" class="form-control" id="permission_name" name="permission_name" required 
                               placeholder="e.g., patient_view, module_doctor">
                        <small class="form-text text-muted">Use lowercase with underscores (snake_case)</small>
                    </div>
                    <div class="form-group">
                        <label for="permission_description">Description</label>
                        <textarea class="form-control" id="permission_description" name="permission_description" rows="3"
                                  placeholder="Describe what this permission allows"></textarea>
                    </div>
                    <div class="form-group">
                        <label for="permission_category">Category *</label>
                        <select class="form-control" id="permission_category" name="permission_category" required>
                            <option value="">Select a category...</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo htmlspecialchars($category); ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $category)); ?>
                                </option>
                            <?php endforeach; ?>
                            <option value="new_category">+ Create New Category</option>
                        </select>
                        <input type="text" class="form-control mt-2 d-none" id="new_category" name="new_category" 
                               placeholder="Enter new category name">
                    </div>
                    <div class="form-group">
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="is_module" name="is_module" value="1">
                            <label class="custom-control-label" for="is_module">
                                This is a module permission (grants access to an entire module)
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Create Permission</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Permission Modal -->
<div class="modal fade" id="editPermissionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning text-white">
                <h5 class="modal-title">
                    <i class="fas fa-edit mr-2"></i>Edit Permission
                </h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span class="text-white">&times;</span>
                </button>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="update_permission">
                <input type="hidden" name="permission_id" id="edit_permission_id">
                
                <div class="modal-body">
                    <div class="form-group">
                        <label for="edit_permission_name">Permission Name *</label>
                        <input type="text" class="form-control" id="edit_permission_name" name="permission_name" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_permission_description">Description</label>
                        <textarea class="form-control" id="edit_permission_description" name="permission_description" rows="3"></textarea>
                    </div>
                    <div class="form-group">
                        <label for="edit_permission_category">Category *</label>
                        <select class="form-control" id="edit_permission_category" name="permission_category" required>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo htmlspecialchars($category); ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $category)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="edit_is_module" name="is_module" value="1">
                            <label class="custom-control-label" for="edit_is_module">
                                This is a module permission
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">Update Permission</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize DataTable
    $('#permissionsTable').DataTable({
        "pageLength": 25,
        "order": [[2, 'asc'], [0, 'asc']] // Sort by category then name
    });

    // Handle edit permission button clicks
    $(document).on('click', '.edit-permission', function() {
        const permissionId = $(this).data('permission-id');
        const permissionName = $(this).data('permission-name');
        const permissionDescription = $(this).data('permission-description');
        const permissionCategory = $(this).data('permission-category');
        const isModule = $(this).data('is-module');
        
        $('#edit_permission_id').val(permissionId);
        $('#edit_permission_name').val(permissionName);
        $('#edit_permission_description').val(permissionDescription);
        $('#edit_is_module').prop('checked', isModule == 1);
        
        // FIX 2: Handle category selection in edit form
        const categorySelect = $('#edit_permission_category');
        const currentCategory = permissionCategory;
        let categoryExists = false;
        
        // Check if current category exists in dropdown
        categorySelect.find('option').each(function() {
            if ($(this).val() === currentCategory) {
                categoryExists = true;
                return false;
            }
        });
        
        if (!categoryExists && currentCategory) {
            // Add current category to dropdown if it doesn't exist
            categorySelect.append(new Option(
                currentCategory.charAt(0).toUpperCase() + currentCategory.slice(1).replace(/_/g, ' '),
                currentCategory
            ));
        }
        
        // Set the category value
        categorySelect.val(currentCategory);
        
        $('#editPermissionModal').modal('show');
    });

    // Handle new category selection in create form
    $('#permission_category').change(function() {
        if ($(this).val() === 'new_category') {
            $('#new_category').removeClass('d-none');
            $('#new_category').prop('required', true);
            $('#new_category').focus();
        } else {
            $('#new_category').addClass('d-none');
            $('#new_category').prop('required', false);
            $('#new_category').val('');
        }
    });

    // Handle form submissions with better UX
    $('form').on('submit', function(e) {
        const form = $(this);
        const submitBtn = form.find('button[type="submit"]');
        
        // Show loading state
        submitBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-2"></i>Processing...');
        
        // Validate new category if selected
        if (form.find('#permission_category').val() === 'new_category') {
            const newCategory = form.find('#new_category').val().trim();
            if (!newCategory) {
                e.preventDefault();
                alert('Please enter a new category name');
                submitBtn.prop('disabled', false).html('Create Permission');
                return false;
            }
        }
    });

    // Auto-focus on permission name field when create modal opens
    $('#createPermissionModal').on('shown.bs.modal', function () {
        $('#permission_name').focus();
    });

    // Reset create form when modal is closed
    $('#createPermissionModal').on('hidden.bs.modal', function () {
        $(this).find('form')[0].reset();
        $('#new_category').addClass('d-none').prop('required', false);
    });
});
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
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
        header("Location: role_management.php");
        exit;
    }

    switch ($action) {
        case 'create_role':
            $role_name = sanitizeInput($_POST['role_name']);
            $role_description = sanitizeInput($_POST['role_description']);
            
            if (!empty($role_name)) {
                $sql = "INSERT INTO user_roles (role_name, role_description) VALUES (?, ?)";
                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param("ss", $role_name, $role_description);
                
                if ($stmt->execute()) {
                    $_SESSION['alert_type'] = "success";
                    $_SESSION['alert_message'] = "Role created successfully!";
                } else {
                    $_SESSION['alert_type'] = "error";
                    $_SESSION['alert_message'] = "Error creating role: " . $stmt->error;
                }
            }
            break;

        case 'update_role':
            $role_id = intval($_POST['role_id']);
            $role_name = sanitizeInput($_POST['role_name']);
            $role_description = sanitizeInput($_POST['role_description']);
            
            $sql = "UPDATE user_roles SET role_name = ?, role_description = ? WHERE role_id = ?";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param("ssi", $role_name, $role_description, $role_id);
            
            if ($stmt->execute()) {
                $_SESSION['alert_type'] = "success";
                $_SESSION['alert_message'] = "Role updated successfully!";
            } else {
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = "Error updating role: " . $stmt->error;
            }
            break;

        case 'delete_role':
            $role_id = intval($_POST['role_id']);
            
            // Check if role is assigned to any users
            $check_sql = "SELECT COUNT(*) as user_count FROM user_role_permissions WHERE role_id = ? AND is_active = 1";
            $check_stmt = $mysqli->prepare($check_sql);
            $check_stmt->bind_param("i", $role_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            $user_count = $check_result->fetch_assoc()['user_count'];
            
            if ($user_count > 0) {
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = "Cannot delete role assigned to $user_count user(s). Reassign users first.";
            } else {
                // Start transaction to handle multiple deletions
                $mysqli->begin_transaction();
                
                try {
                    // Delete role permissions first
                    $delete_perms_sql = "DELETE FROM role_permissions WHERE role_id = ?";
                    $delete_perms_stmt = $mysqli->prepare($delete_perms_sql);
                    $delete_perms_stmt->bind_param("i", $role_id);
                    $delete_perms_stmt->execute();
                    
                    // Delete any inactive user role assignments
                    $delete_assignments_sql = "DELETE FROM user_role_permissions WHERE role_id = ?";
                    $delete_assignments_stmt = $mysqli->prepare($delete_assignments_sql);
                    $delete_assignments_stmt->bind_param("i", $role_id);
                    $delete_assignments_stmt->execute();
                    
                    // Delete the role (only if not a system role)
                    $delete_role_sql = "DELETE FROM user_roles WHERE role_id = ? AND is_system_role = 0";
                    $delete_role_stmt = $mysqli->prepare($delete_role_sql);
                    $delete_role_stmt->bind_param("i", $role_id);
                    
                    if ($delete_role_stmt->execute() && $delete_role_stmt->affected_rows > 0) {
                        $mysqli->commit();
                        $_SESSION['alert_type'] = "success";
                        $_SESSION['alert_message'] = "Role deleted successfully!";
                    } else {
                        $mysqli->rollback();
                        $_SESSION['alert_type'] = "error";
                        $_SESSION['alert_message'] = "Cannot delete system role or role not found.";
                    }
                } catch (Exception $e) {
                    $mysqli->rollback();
                    $_SESSION['alert_type'] = "error";
                    $_SESSION['alert_message'] = "Error deleting role: " . $e->getMessage();
                }
            }
            break;
    }
    
    header("Location: role_management.php");
    exit;
}

// Get all roles
$roles_sql = "SELECT * FROM user_roles ORDER BY role_is_admin DESC, role_name";
$roles_result = $mysqli->query($roles_sql);
$roles = [];
while ($role = $roles_result->fetch_assoc()) {
    // Get active user count for each role
    $user_count_sql = "SELECT COUNT(*) as user_count FROM user_role_permissions WHERE role_id = ? AND is_active = 1";
    $user_count_stmt = $mysqli->prepare($user_count_sql);
    $user_count_stmt->bind_param("i", $role['role_id']);
    $user_count_stmt->execute();
    $user_count_result = $user_count_stmt->get_result();
    $role['user_count'] = $user_count_result->fetch_assoc()['user_count'];
    
    // Get permission count for each role
    $perm_count_sql = "SELECT COUNT(*) as perm_count FROM role_permissions WHERE role_id = ? AND permission_value = 1";
    $perm_count_stmt = $mysqli->prepare($perm_count_sql);
    $perm_count_stmt->bind_param("i", $role['role_id']);
    $perm_count_stmt->execute();
    $perm_count_result = $perm_count_stmt->get_result();
    $role['perm_count'] = $perm_count_result->fetch_assoc()['perm_count'];
    
    $roles[] = $role;
}
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-user-tag mr-2"></i>Role Management
            </h3>
            <div class="card-tools">
                <button class="btn btn-success" data-toggle="modal" data-target="#createRoleModal">
                    <i class="fas fa-plus mr-2"></i>New Role
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

        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead class="bg-light">
                    <tr>
                        <th>Role Name</th>
                        <th>Description</th>
                        <th>Users</th>
                        <th>Permissions</th>
                        <th>Type</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($roles as $role): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($role['role_name']); ?></strong>
                                <?php if ($role['role_is_admin']): ?>
                                    <i class="fas fa-crown text-warning ml-1" title="Administrator Role"></i>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($role['role_description']); ?></td>
                            <td>
                                <span class="badge badge-info"><?php echo $role['user_count']; ?> users</span>
                            </td>
                            <td>
                                <span class="badge badge-secondary"><?php echo $role['perm_count']; ?> perms</span>
                            </td>
                            <td>
                                <?php if ($role['role_is_admin']): ?>
                                    <span class="badge badge-success">System Role</span>
                                <?php elseif ($role['is_system_role']): ?>
                                    <span class="badge badge-warning">System Role</span>
                                <?php else: ?>
                                    <span class="badge badge-primary">Custom Role</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <a href="/clinic/admin/user/role_permissions.php?role_id=<?php echo $role['role_id']; ?>" 
                                       class="btn btn-info" title="Manage Permissions">
                                        <i class="fas fa-key"></i>
                                    </a>
                                    <?php if (!$role['is_system_role']): ?>
                                        <button class="btn btn-warning edit-role" 
                                                data-role-id="<?php echo $role['role_id']; ?>"
                                                data-role-name="<?php echo htmlspecialchars($role['role_name']); ?>"
                                                data-role-description="<?php echo htmlspecialchars($role['role_description']); ?>"
                                                title="Edit Role">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                    <?php else: ?>
                                        <button class="btn btn-warning" disabled title="Cannot edit system role">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                    <?php endif; ?>
                                    
                                    <?php if (!$role['is_system_role'] && $role['user_count'] == 0): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                            <input type="hidden" name="action" value="delete_role">
                                            <input type="hidden" name="role_id" value="<?php echo $role['role_id']; ?>">
                                            <button type="submit" class="btn btn-danger" 
                                                    onclick="return confirm('Are you sure you want to delete this role? This will also remove all permission assignments for this role.')"
                                                    title="Delete Role">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <button class="btn btn-danger" disabled 
                                                title="<?php echo $role['is_system_role'] ? 'Cannot delete system role' : 'Cannot delete role with active users'; ?>">
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

<!-- Create Role Modal -->
<div class="modal fade" id="createRoleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">
                    <i class="fas fa-plus mr-2"></i>Create New Role
                </h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span class="text-white">&times;</span>
                </button>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="create_role">
                
                <div class="modal-body">
                    <div class="form-group">
                        <label for="role_name">Role Name *</label>
                        <input type="text" class="form-control" id="role_name" name="role_name" required 
                               placeholder="e.g., Doctor, Nurse, Receptionist">
                    </div>
                    <div class="form-group">
                        <label for="role_description">Description</label>
                        <textarea class="form-control" id="role_description" name="role_description" rows="3"
                                  placeholder="Describe the role's purpose and access level"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Create Role</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Role Modal -->
<div class="modal fade" id="editRoleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning text-white">
                <h5 class="modal-title">
                    <i class="fas fa-edit mr-2"></i>Edit Role
                </h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span class="text-white">&times;</span>
                </button>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="update_role">
                <input type="hidden" name="role_id" id="edit_role_id">
                
                <div class="modal-body">
                    <div class="form-group">
                        <label for="edit_role_name">Role Name *</label>
                        <input type="text" class="form-control" id="edit_role_name" name="role_name" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_role_description">Description</label>
                        <textarea class="form-control" id="edit_role_description" name="role_description" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">Update Role</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Handle edit role button clicks
    $('.edit-role').click(function() {
        const roleId = $(this).data('role-id');
        const roleName = $(this).data('role-name');
        const roleDescription = $(this).data('role-description');
        
        $('#edit_role_id').val(roleId);
        $('#edit_role_name').val(roleName);
        $('#edit_role_description').val(roleDescription);
        
        $('#editRoleModal').modal('show');
    });
    
    // Auto-focus on role name field when create modal opens
    $('#createRoleModal').on('shown.bs.modal', function () {
        $('#role_name').focus();
    });
});
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
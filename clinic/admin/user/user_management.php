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
        header("Location: user_management.php");
        exit;
    }

    switch ($action) {
        case 'add_user':
            $user_name = sanitizeInput($_POST['user_name']);
            $user_email = sanitizeInput($_POST['user_email']);
            $user_password = $_POST['user_password'];
            $user_confirm_password = $_POST['user_confirm_password'];
            $role_id = intval($_POST['role_id']);
            
            // Validate input
            if (empty($user_name) || empty($user_email) || empty($user_password)) {
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = "All fields are required.";
                header("Location: user_management.php");
                exit;
            }
            
            if ($user_password !== $user_confirm_password) {
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = "Passwords do not match.";
                header("Location: user_management.php");
                exit;
            }
            
            if (!filter_var($user_email, FILTER_VALIDATE_EMAIL)) {
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = "Invalid email format.";
                header("Location: user_management.php");
                exit;
            }
            
            // Check if email already exists
            $check_sql = "SELECT user_id FROM users WHERE user_email = ? AND user_archived_at IS NULL";
            $check_stmt = $mysqli->prepare($check_sql);
            $check_stmt->bind_param("s", $user_email);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = "A user with this email already exists.";
                header("Location: user_management.php");
                exit;
            }
            
            // Hash password
            $hashed_password = password_hash($user_password, PASSWORD_DEFAULT);
            
            // Insert user
            $sql = "INSERT INTO users (user_name, user_email, user_password, user_created_at, user_status, user_auth_method) 
                    VALUES (?, ?, ?, NOW(), 1, 'local')";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param("sss", $user_name, $user_email, $hashed_password);
            
            if ($stmt->execute()) {
                $new_user_id = $stmt->insert_id;
                
                // Assign role if selected
                if ($role_id > 0) {
                    $role_sql = "INSERT INTO user_role_permissions (user_id, role_id, assigned_at, is_active) 
                                 VALUES (?, ?, NOW(), 1)";
                    $role_stmt = $mysqli->prepare($role_sql);
                    $role_stmt->bind_param("ii", $new_user_id, $role_id);
                    $role_stmt->execute();
                }
                
                $_SESSION['alert_type'] = "success";
                $_SESSION['alert_message'] = "User added successfully!";
            } else {
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = "Error adding user: " . $stmt->error;
            }
            break;

        case 'update_user':
            $user_id = intval($_POST['user_id']);
            $user_name = sanitizeInput($_POST['user_name']);
            $user_email = sanitizeInput($_POST['user_email']);
            $role_id = intval($_POST['role_id']);
            
            // Validate input
            if (empty($user_name) || empty($user_email)) {
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = "Name and email are required.";
                header("Location: user_management.php");
                exit;
            }
            
            if (!filter_var($user_email, FILTER_VALIDATE_EMAIL)) {
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = "Invalid email format.";
                header("Location: user_management.php");
                exit;
            }
            
            // Check if email already exists (excluding current user)
            $check_sql = "SELECT user_id FROM users WHERE user_email = ? AND user_id != ? AND user_archived_at IS NULL";
            $check_stmt = $mysqli->prepare($check_sql);
            $check_stmt->bind_param("si", $user_email, $user_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = "A user with this email already exists.";
                header("Location: user_management.php");
                exit;
            }
            
            // Update user
            $sql = "UPDATE users SET user_name = ?, user_email = ? WHERE user_id = ?";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param("ssi", $user_name, $user_email, $user_id);
            
            if ($stmt->execute()) {
                // Update role
                $delete_role_sql = "DELETE FROM user_role_permissions WHERE user_id = ?";
                $delete_stmt = $mysqli->prepare($delete_role_sql);
                $delete_stmt->bind_param("i", $user_id);
                $delete_stmt->execute();
                
                if ($role_id > 0) {
                    $role_sql = "INSERT INTO user_role_permissions (user_id, role_id, assigned_at, is_active) 
                                 VALUES (?, ?, NOW(), 1)";
                    $role_stmt = $mysqli->prepare($role_sql);
                    $role_stmt->bind_param("ii", $user_id, $role_id);
                    $role_stmt->execute();
                }
                
                $_SESSION['alert_type'] = "success";
                $_SESSION['alert_message'] = "User updated successfully!";
            } else {
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = "Error updating user: " . $stmt->error;
            }
            break;

        case 'update_user_role':
            $user_id = intval($_POST['user_id']);
            $role_id = intval($_POST['role_id']);
            
            $sql = "UPDATE users SET role_id = ? WHERE user_id = ?";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param("ii", $role_id, $user_id);
            
            if ($stmt->execute()) {
                $_SESSION['alert_type'] = "success";
                $_SESSION['alert_message'] = "User role updated successfully!";
            } else {
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = "Error updating user role: " . $stmt->error;
            }
            break;

        case 'toggle_user_status':
            $user_id = intval($_POST['user_id']);
            $current_status = intval($_POST['current_status']);
            $new_status = $current_status === 1 ? 0 : 1;
            
            $sql = "UPDATE users SET user_status = ? WHERE user_id = ?";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param("ii", $new_status, $user_id);
            
            if ($stmt->execute()) {
                $status_text = $new_status === 1 ? 'activated' : 'deactivated';
                $_SESSION['alert_type'] = "success";
                $_SESSION['alert_message'] = "User $status_text successfully!";
            } else {
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = "Error updating user status: " . $stmt->error;
            }
            break;

        case 'archive_user':
            $user_id = intval($_POST['user_id']);
            
            // Don't allow archiving yourself
            if ($user_id == $session_user_id) {
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = "You cannot archive your own account.";
                header("Location: user_management.php");
                exit;
            }
            
            $sql = "UPDATE users SET user_archived_at = NOW() WHERE user_id = ?";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param("i", $user_id);
            
            if ($stmt->execute()) {
                $_SESSION['alert_type'] = "success";
                $_SESSION['alert_message'] = "User archived successfully!";
            } else {
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = "Error archiving user: " . $stmt->error;
            }
            break;

        case 'restore_user':
            $user_id = intval($_POST['user_id']);
            
            $sql = "UPDATE users SET user_archived_at = NULL WHERE user_id = ?";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param("i", $user_id);
            
            if ($stmt->execute()) {
                $_SESSION['alert_type'] = "success";
                $_SESSION['alert_message'] = "User restored successfully!";
            } else {
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = "Error restoring user: " . $stmt->error;
            }
            break;
    }
    
    header("Location: user_management.php");
    exit;
}

// Handle filter
$show_archived = isset($_GET['show_archived']) && $_GET['show_archived'] == '1';
$filter_text = $show_archived ? "AND u.user_archived_at IS NOT NULL" : "AND u.user_archived_at IS NULL";

// Get all users with their roles
$users_sql = "SELECT u.*, ur.role_name, ur.role_id, urp.assigned_at,
                     GROUP_CONCAT(DISTINCT p.permission_name) as role_permissions,
                     MAX(CASE WHEN p.permission_name = '*' THEN 1 ELSE 0 END) as is_admin
              FROM users u 
              LEFT JOIN user_role_permissions urp ON u.user_id = urp.user_id AND urp.is_active = 1
              LEFT JOIN user_roles ur ON urp.role_id = ur.role_id
              LEFT JOIN role_permissions rp ON ur.role_id = rp.role_id AND rp.permission_value = 1
              LEFT JOIN permissions p ON rp.permission_id = p.permission_id
              WHERE 1=1 $filter_text
              GROUP BY u.user_id
              ORDER BY u.user_archived_at IS NULL DESC, u.user_name";
$users_result = $mysqli->query($users_sql);
$users = [];
while ($user = $users_result->fetch_assoc()) {
    $users[] = $user;
}

// Get all roles for dropdown
$roles_sql = "SELECT * FROM user_roles ORDER BY role_name";
$roles_result = $mysqli->query($roles_sql);
$roles = [];
while ($role = $roles_result->fetch_assoc()) {
    $roles[] = $role;
}

// Get user counts for stats
$user_stats_sql = "SELECT 
    COUNT(*) as total_users,
    COUNT(CASE WHEN user_archived_at IS NULL THEN 1 END) as active_users,
    COUNT(CASE WHEN user_archived_at IS NOT NULL THEN 1 END) as archived_users,
    COUNT(CASE WHEN user_status = 1 AND user_archived_at IS NULL THEN 1 END) as enabled_users
FROM users";
$user_stats_result = $mysqli->query($user_stats_sql);
$user_stats = $user_stats_result->fetch_assoc();
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-users mr-2"></i>User Management
            </h3>
            <div class="card-tools">
                <button type="button" class="btn btn-success btn-sm" data-toggle="modal" data-target="#addUserModal">
                    <i class="fas fa-plus mr-1"></i>Add New User
                </button>
                <?php if ($show_archived): ?>
                    <a href="user_management.php" class="btn btn-info btn-sm ml-1">
                        <i class="fas fa-eye mr-1"></i>View Active Users
                    </a>
                <?php else: ?>
                    <a href="user_management.php?show_archived=1" class="btn btn-secondary btn-sm ml-1">
                        <i class="fas fa-archive mr-1"></i>View Archived Users
                    </a>
                <?php endif; ?>
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

        <!-- User Statistics -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="info-box bg-info">
                    <span class="info-box-icon"><i class="fas fa-users"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Users</span>
                        <span class="info-box-number"><?php echo $user_stats['total_users']; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-success">
                    <span class="info-box-icon"><i class="fas fa-user-check"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Active Users</span>
                        <span class="info-box-number"><?php echo $user_stats['active_users']; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-warning">
                    <span class="info-box-icon"><i class="fas fa-user-times"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Enabled Users</span>
                        <span class="info-box-number"><?php echo $user_stats['enabled_users']; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-secondary">
                    <span class="info-box-icon"><i class="fas fa-archive"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Archived Users</span>
                        <span class="info-box-number"><?php echo $user_stats['archived_users']; ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead class="bg-light">
                    <tr>
                        <th>User</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Type</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): 
                        $is_current_user = $user['user_id'] == $session_user_id;
                        $is_archived = !is_null($user['user_archived_at']);
                    ?>
                        <tr class="<?php echo $is_archived ? 'table-secondary' : ''; ?>">
                            <td>
                                <div class="d-flex align-items-center">
                                    <?php if ($user['user_avatar']): ?>
                                        <img src="<?php echo htmlspecialchars($user['user_avatar']); ?>" 
                                             class="rounded-circle mr-2" width="32" height="32" 
                                             alt="<?php echo htmlspecialchars($user['user_name']); ?>">
                                    <?php else: ?>
                                        <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center mr-2" 
                                             style="width: 32px; height: 32px; font-size: 14px;">
                                            <?php echo strtoupper(substr($user['user_name'], 0, 2)); ?>
                                        </div>
                                    <?php endif; ?>
                                    <div>
                                        <strong><?php echo htmlspecialchars($user['user_name']); ?></strong>
                                        <?php if ($is_current_user): ?>
                                            <span class="badge badge-info ml-1">You</span>
                                        <?php endif; ?>
                                        <?php if ($is_archived): ?>
                                            <span class="badge badge-secondary ml-1">Archived</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($user['user_email']); ?></td>
                            <td>
                                <?php if ($is_current_user): ?>
                                    <span class="text-muted"><?php echo htmlspecialchars($user['role_name'] ?? 'No Role'); ?></span>
                                <?php else: ?>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="action" value="update_user_role">
                                        <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                        <select name="role_id" class="form-control form-control-sm d-inline-block w-auto" 
                                                onchange="this.form.submit()" <?php echo $is_archived ? 'disabled' : ''; ?>>
                                            <option value="">No Role</option>
                                            <?php foreach ($roles as $role): ?>
                                                <option value="<?php echo $role['role_id']; ?>" 
                                                    <?php echo $user['role_id'] == $role['role_id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($role['role_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($user['user_status'] == 1): ?>
                                    <span class="badge badge-success">Enabled</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">Disabled</span>
                                <?php endif; ?>
                                
                                <?php if (!$is_current_user && !$is_archived): ?>
                                    <form method="POST" class="d-inline ml-1">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="action" value="toggle_user_status">
                                        <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                        <input type="hidden" name="current_status" value="<?php echo $user['user_status']; ?>">
                                        <button type="submit" class="btn btn-xs <?php echo $user['user_status'] == 1 ? 'btn-warning' : 'btn-success'; ?>">
                                            <?php echo $user['user_status'] == 1 ? 'Disable' : 'Enable'; ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php 
                                $auth_methods = [
                                    'local' => 'Local',
                                    'ldap' => 'LDAP',
                                    'sso' => 'SSO'
                                ];
                                ?>
                                <span class="badge badge-light">
                                    <?php echo $auth_methods[$user['user_auth_method']] ?? 'Local'; ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($user['user_created_at']): ?>
                                    <small class="text-muted">
                                        <?php echo date('M j, Y', strtotime($user['user_created_at'])); ?>
                                    </small>
                                <?php else: ?>
                                    <span class="text-muted">Unknown</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <button type="button" class="btn btn-primary" 
                                            onclick="editUser(<?php echo $user['user_id']; ?>, '<?php echo htmlspecialchars($user['user_name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($user['user_email'], ENT_QUOTES); ?>', <?php echo $user['role_id'] ?? 0; ?>)"
                                            title="Edit User" <?php echo $is_archived ? 'disabled' : ''; ?>>
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    
                                    <a href="user_permissions.php?user_id=<?php echo $user['user_id']; ?>" 
                                       class="btn btn-info" title="Manage Permissions">
                                        <i class="fas fa-key"></i>
                                    </a>
                                    
                                    <?php if (!$is_current_user): ?>
                                        <?php if ($is_archived): ?>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                <input type="hidden" name="action" value="restore_user">
                                                <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                                <button type="submit" class="btn btn-success" title="Restore User"
                                                        onclick="return confirm('Are you sure you want to restore this user?')">
                                                    <i class="fas fa-undo"></i>
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                <input type="hidden" name="action" value="archive_user">
                                                <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                                <button type="submit" class="btn btn-danger" title="Archive User"
                                                        onclick="return confirm('Are you sure you want to archive this user? This will prevent them from logging in.')">
                                                    <i class="fas fa-archive"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if (empty($users)): ?>
            <div class="text-center py-4">
                <i class="fas fa-users fa-3x text-muted mb-3"></i>
                <h5 class="text-muted">
                    <?php if ($show_archived): ?>
                        No archived users found
                    <?php else: ?>
                        No active users found
                    <?php endif; ?>
                </h5>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1" role="dialog" aria-labelledby="addUserModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-success">
                <h5 class="modal-title" id="addUserModalLabel">Add New User</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST" id="addUserForm">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="add_user">
                    
                    <div class="form-group">
                        <label for="user_name">Full Name *</label>
                        <input type="text" class="form-control" id="user_name" name="user_name" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="user_email">Email Address *</label>
                        <input type="email" class="form-control" id="user_email" name="user_email" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="user_password">Password *</label>
                        <input type="password" class="form-control" id="user_password" name="user_password" required minlength="6">
                    </div>
                    
                    <div class="form-group">
                        <label for="user_confirm_password">Confirm Password *</label>
                        <input type="password" class="form-control" id="user_confirm_password" name="user_confirm_password" required minlength="6">
                    </div>
                    
                    <div class="form-group">
                        <label for="role_id">Role</label>
                        <select class="form-control" id="role_id" name="role_id">
                            <option value="">No Role</option>
                            <?php foreach ($roles as $role): ?>
                                <option value="<?php echo $role['role_id']; ?>">
                                    <?php echo htmlspecialchars($role['role_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Add User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit User Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1" role="dialog" aria-labelledby="editUserModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary">
                <h5 class="modal-title" id="editUserModalLabel">Edit User</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST" id="editUserForm">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="update_user">
                    <input type="hidden" name="user_id" id="edit_user_id">
                    
                    <div class="form-group">
                        <label for="edit_user_name">Full Name *</label>
                        <input type="text" class="form-control" id="edit_user_name" name="user_name" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_user_email">Email Address *</label>
                        <input type="email" class="form-control" id="edit_user_email" name="user_email" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_role_id">Role</label>
                        <select class="form-control" id="edit_role_id" name="role_id">
                            <option value="">No Role</option>
                            <?php foreach ($roles as $role): ?>
                                <option value="<?php echo $role['role_id']; ?>">
                                    <?php echo htmlspecialchars($role['role_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editUser(userId, userName, userEmail, roleId) {
    document.getElementById('edit_user_id').value = userId;
    document.getElementById('edit_user_name').value = userName;
    document.getElementById('edit_user_email').value = userEmail;
    document.getElementById('edit_role_id').value = roleId;
    
    $('#editUserModal').modal('show');
}

// Password confirmation validation
document.getElementById('addUserForm').addEventListener('submit', function(e) {
    const password = document.getElementById('user_password').value;
    const confirmPassword = document.getElementById('user_confirm_password').value;
    
    if (password !== confirmPassword) {
        e.preventDefault();
        alert('Passwords do not match!');
        return false;
    }
    
    if (password.length < 6) {
        e.preventDefault();
        alert('Password must be at least 6 characters long!');
        return false;
    }
    
    return true;
});
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
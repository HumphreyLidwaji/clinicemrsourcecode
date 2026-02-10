<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
//enforceUserPermission('module_users');

$user_id = intval($_GET['user_id'] ?? 0);

if ($user_id === 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "No user specified.";
    header("Location: user_management.php");
    exit;
}

// Get user details
$user_sql = "SELECT u.*, ur.role_name 
             FROM users u 
             LEFT JOIN user_role_permissions urp ON u.user_id = urp.user_id AND urp.is_active = 1
             LEFT JOIN user_roles ur ON urp.role_id = ur.role_id 
             WHERE u.user_id = ?";
$user_stmt = $mysqli->prepare($user_sql);
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user_result = $user_stmt->get_result();

if ($user_result->num_rows === 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "User not found.";
    header("Location: user_management.php");
    exit;
}

$user = $user_result->fetch_assoc();

// Handle permission updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token.";
        header("Location: user_permissions.php?user_id=" . $user_id);
        exit;
    }

    // Get selected permissions
    $selected_permissions = isset($_POST['permissions']) ? $_POST['permissions'] : [];
    
    // Start transaction
    $mysqli->begin_transaction();
    
    try {
        // Remove all existing user permission overrides
        $delete_sql = "DELETE FROM user_permissions WHERE user_id = ?";
        $delete_stmt = $mysqli->prepare($delete_sql);
        $delete_stmt->bind_param("i", $user_id);
        $delete_stmt->execute();
        
        // Add selected permissions with permission_value = 1 (granted)
        foreach ($selected_permissions as $permission_id) {
            $permission_id = intval($permission_id);
            $insert_sql = "INSERT INTO user_permissions (user_id, permission_id, permission_value) VALUES (?, ?, 1)";
            $insert_stmt = $mysqli->prepare($insert_sql);
            $insert_stmt->bind_param("ii", $user_id, $permission_id);
            $insert_stmt->execute();
        }
        
        $mysqli->commit();
        
        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "User permissions updated successfully!";
        
    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error updating user permissions: " . $e->getMessage();
    }
    
    header("Location: user_permissions.php?user_id=" . $user_id);
    exit;
}

// Get all permissions grouped by category
$permissions_sql = "SELECT * FROM permissions ORDER BY permission_category, permission_name";
$permissions_result = $mysqli->query($permissions_sql);
$permissions_by_category = [];
while ($permission = $permissions_result->fetch_assoc()) {
    $permissions_by_category[$permission['permission_category']][] = $permission;
}

// Get user's current permission overrides
$user_permissions_sql = "SELECT permission_id FROM user_permissions WHERE user_id = ? AND permission_value = 1";
$user_permissions_stmt = $mysqli->prepare($user_permissions_sql);
$user_permissions_stmt->bind_param("i", $user_id);
$user_permissions_stmt->execute();
$user_permissions_result = $user_permissions_stmt->get_result();
$current_permissions = [];
while ($row = $user_permissions_result->fetch_assoc()) {
    $current_permissions[] = $row['permission_id'];
}

// Get user's role ID first
$role_id_sql = "SELECT role_id FROM user_role_permissions WHERE user_id = ? AND is_active = 1";
$role_id_stmt = $mysqli->prepare($role_id_sql);
$role_id_stmt->bind_param("i", $user_id);
$role_id_stmt->execute();
$role_id_result = $role_id_stmt->get_result();
$user_role = $role_id_result->fetch_assoc();
$user_role_id = $user_role['role_id'] ?? 0;

// Get user's role permissions for display info
$role_permissions_sql = "SELECT p.permission_id 
                         FROM permissions p
                         JOIN role_permissions rp ON p.permission_id = rp.permission_id
                         WHERE rp.role_id = ? AND rp.permission_value = 1";
$role_permissions_stmt = $mysqli->prepare($role_permissions_sql);
$role_permissions_stmt->bind_param("i", $user_role_id);
$role_permissions_stmt->execute();
$role_permissions_result = $role_permissions_stmt->get_result();
$role_permissions = [];
while ($row = $role_permissions_result->fetch_assoc()) {
    $role_permissions[] = $row['permission_id'];
}

// Get permission statistics
$perm_stats_sql = "SELECT 
                    COUNT(*) as total_permissions,
                    COUNT(CASE WHEN is_module = 1 THEN 1 END) as module_permissions,
                    COUNT(CASE WHEN is_module = 0 THEN 1 END) as action_permissions
                   FROM permissions";
$perm_stats_result = $mysqli->query($perm_stats_sql);
$perm_stats = $perm_stats_result->fetch_assoc();
?>

<div class="card">
    <div class="card-header bg-info py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-user-shield mr-2"></i>Manage User Permissions: <?php echo htmlspecialchars($user['user_name']); ?>
            </h3>
            <div class="card-tools">
                <a href="/clinic/admin/user/user_management.php" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Users
                </a>
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

        <!-- User Information -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card bg-light">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <strong>User Name:</strong> <?php echo htmlspecialchars($user['user_name']); ?>
                                <?php if ($user['user_id'] == $session_user_id): ?>
                                    <span class="badge badge-info ml-2">Current User</span>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-4">
                                <strong>Assigned Role:</strong> 
                                <span class="badge badge-primary"><?php echo htmlspecialchars($user['role_name'] ?? 'No Role'); ?></span>
                                <?php if ($user_role_id): ?>
                                    <span class="badge badge-secondary ml-1"><?php echo count($role_permissions); ?> role permissions</span>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-4">
                                <strong>User Permissions:</strong> 
                                <span class="badge badge-warning"><?php echo count($current_permissions); ?> assigned</span>
                            </div>
                        </div>
                        <div class="row mt-2">
                            <div class="col-12">
                                <strong>Email:</strong> <?php echo htmlspecialchars($user['user_email']); ?>
                                <?php if ($user['user_status'] == 1): ?>
                                    <span class="badge badge-success ml-2">Enabled</span>
                                <?php else: ?>
                                    <span class="badge badge-danger ml-2">Disabled</span>
                                <?php endif; ?>
                                <?php if (!is_null($user['user_archived_at'])): ?>
                                    <span class="badge badge-secondary ml-1">Archived</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-12">
                <form method="POST" id="userPermissionsForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    
                    <!-- Quick Actions -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="btn-group btn-group-sm" role="group">
                                <button type="button" class="btn btn-outline-primary" onclick="selectAllPermissions()">
                                    <i class="fas fa-check-square mr-2"></i>Select All
                                </button>
                                <button type="button" class="btn btn-outline-secondary" onclick="deselectAllPermissions()">
                                    <i class="fas fa-square mr-2"></i>Deselect All
                                </button>
                                <button type="button" class="btn btn-outline-info" onclick="selectCategory('modules')">
                                    <i class="fas fa-cubes mr-2"></i>All Modules
                                </button>
                                <button type="button" class="btn btn-outline-info" onclick="selectCategory('patients')">
                                    <i class="fas fa-user-injured mr-2"></i>Patient Permissions
                                </button>
                                <button type="button" class="btn btn-outline-info" onclick="selectCategory('doctor')">
                                    <i class="fas fa-user-md mr-2"></i>Doctor Permissions
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Permissions by Category -->
                    <div class="row">
                        <?php foreach ($permissions_by_category as $category => $permissions): ?>
                            <div class="col-md-6 mb-4">
                                <div class="card h-100">
                                    <div class="card-header bg-light py-2 d-flex justify-content-between align-items-center">
                                        <h6 class="card-title mb-0">
                                            <i class="fas fa-folder mr-2 text-primary"></i>
                                            <?php echo ucfirst(str_replace('_', ' ', $category)); ?>
                                            <small class="text-muted">(<?php echo count($permissions); ?> permissions)</small>
                                        </h6>
                                        <div class="btn-group btn-group-xs">
                                            <button type="button" class="btn btn-outline-success btn-xs" 
                                                    onclick="selectCategory('<?php echo $category; ?>')" 
                                                    title="Select all in category">
                                                <i class="fas fa-check"></i>
                                            </button>
                                            <button type="button" class="btn btn-outline-danger btn-xs" 
                                                    onclick="deselectCategory('<?php echo $category; ?>')" 
                                                    title="Deselect all in category">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="card-body p-2">
                                        <div class="permission-list" style="max-height: 300px; overflow-y: auto;">
                                            <?php foreach ($permissions as $permission): ?>
                                                <div class="custom-control custom-checkbox mb-2 permission-item" data-category="<?php echo $category; ?>">
                                                    <input type="checkbox" class="custom-control-input permission-checkbox" 
                                                           id="perm_<?php echo $permission['permission_id']; ?>" 
                                                           name="permissions[]" 
                                                           value="<?php echo $permission['permission_id']; ?>"
                                                           <?php echo in_array($permission['permission_id'], $current_permissions) ? 'checked' : ''; ?>>
                                                    <label class="custom-control-label w-100" for="perm_<?php echo $permission['permission_id']; ?>">
                                                        <div class="d-flex justify-content-between align-items-center">
                                                            <span>
                                                                <strong><?php echo htmlspecialchars($permission['permission_name']); ?></strong>
                                                                <?php if ($permission['is_module']): ?>
                                                                    <span class="badge badge-primary badge-sm ml-1">Module</span>
                                                                <?php endif; ?>
                                                            </span>
                                                            <span class="badge badge-light badge-sm"><?php echo $category; ?></span>
                                                        </div>
                                                        <?php if ($permission['permission_description']): ?>
                                                            <small class="form-text text-muted ml-4">
                                                                <?php echo htmlspecialchars($permission['permission_description']); ?>
                                                            </small>
                                                        <?php endif; ?>
                                                    </label>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Permission Statistics -->
                    <div class="row mt-4">
                        <div class="col-md-12">
                            <div class="card">
                                <div class="card-header">
                                    <h6 class="card-title mb-0">
                                        <i class="fas fa-chart-bar mr-2"></i>Permission Summary
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="row text-center">
                                        <div class="col-md-3">
                                            <div class="border rounded p-2">
                                                <h4 class="mb-0 text-primary"><?php echo $perm_stats['total_permissions']; ?></h4>
                                                <small class="text-muted">Total Permissions</small>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="border rounded p-2">
                                                <h4 class="mb-0 text-success"><?php echo $perm_stats['module_permissions']; ?></h4>
                                                <small class="text-muted">Module Permissions</small>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="border rounded p-2">
                                                <h4 class="mb-0 text-info"><?php echo $perm_stats['action_permissions']; ?></h4>
                                                <small class="text-muted">Action Permissions</small>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="border rounded p-2">
                                                <h4 class="mb-0 text-warning"><?php echo count($current_permissions); ?></h4>
                                                <small class="text-muted">Currently Selected</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <div class="row mt-4">
                        <div class="col-12 text-center">
                            <button type="submit" class="btn btn-success btn-lg">
                                <i class="fas fa-save mr-2"></i>Update User Permissions
                            </button>
                            <a href="/clinic/admin/user/user_management.php" class="btn btn-secondary ml-2">
                                <i class="fas fa-times mr-2"></i>Cancel
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function selectAllPermissions() {
    $('.permission-checkbox').prop('checked', true);
    updateSelectedCount();
}

function deselectAllPermissions() {
    $('.permission-checkbox').prop('checked', false);
    updateSelectedCount();
}

function selectCategory(category) {
    $('.permission-item[data-category="' + category + '"] .permission-checkbox').prop('checked', true);
    updateSelectedCount();
}

function deselectCategory(category) {
    $('.permission-item[data-category="' + category + '"] .permission-checkbox').prop('checked', false);
    updateSelectedCount();
}

function updateSelectedCount() {
    const selectedCount = $('.permission-checkbox:checked').length;
    $('.selected-count').text(selectedCount);
}

// Update selected count when checkboxes change
$(document).ready(function() {
    $('.permission-checkbox').change(function() {
        updateSelectedCount();
    });
    
    // Initial count update
    updateSelectedCount();
});

// Form submission handling
$('#userPermissionsForm').on('submit', function(e) {
    const selectedCount = $('.permission-checkbox:checked').length;
    
    if (selectedCount === 0) {
        if (!confirm('No permissions selected. This will remove all permissions from this user. Continue?')) {
            e.preventDefault();
            return false;
        }
    }
    
    // Show loading state
    $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Updating...').prop('disabled', true);
});
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

$role_id = intval($_GET['role_id'] ?? 0);

if ($role_id === 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "No role specified.";
    header("Location: /clinic/admin/user/role_management.php");
    exit;
}

// Get role details
$role_sql = "SELECT * FROM user_roles WHERE role_id = ?";
$role_stmt = $mysqli->prepare($role_sql);
$role_stmt->bind_param("i", $role_id);
$role_stmt->execute();
$role_result = $role_stmt->get_result();

if ($role_result->num_rows === 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Role not found.";
    header("Location: /clinic/admin/user/role_management.php");
    exit;
}

$role = $role_result->fetch_assoc();

// Handle permission updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token.";
        header("Location: role_permissions.php?role_id=" . $role_id);
        exit;
    }

    // Get selected permissions
    $selected_permissions = isset($_POST['permissions']) ? $_POST['permissions'] : [];
    
    // Start transaction
    $mysqli->begin_transaction();
    
    try {
        // Remove all existing permissions for this role
        $delete_sql = "DELETE FROM role_permissions WHERE role_id = ?";
        $delete_stmt = $mysqli->prepare($delete_sql);
        $delete_stmt->bind_param("i", $role_id);
        $delete_stmt->execute();
        
        // Add selected permissions with permission_value = 1 (granted)
        foreach ($selected_permissions as $permission_id) {
            $permission_id = intval($permission_id);
            $insert_sql = "INSERT INTO role_permissions (role_id, permission_id, permission_value) VALUES (?, ?, 1)";
            $insert_stmt = $mysqli->prepare($insert_sql);
            $insert_stmt->bind_param("ii", $role_id, $permission_id);
            $insert_stmt->execute();
        }
        
        $mysqli->commit();
        
        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Permissions updated successfully!";
        
    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error updating permissions: " . $e->getMessage();
    }
    
    header("Location: role_permissions.php?role_id=" . $role_id);
    exit;
}

// Get all permissions grouped by category
$permissions_sql = "SELECT * FROM permissions ORDER BY permission_category, permission_name";
$permissions_result = $mysqli->query($permissions_sql);
$permissions_by_category = [];
while ($permission = $permissions_result->fetch_assoc()) {
    $permissions_by_category[$permission['permission_category']][] = $permission;
}

// Get current role permissions
$role_permissions_sql = "SELECT permission_id FROM role_permissions WHERE role_id = ? AND permission_value = 1";
$role_permissions_stmt = $mysqli->prepare($role_permissions_sql);
$role_permissions_stmt->bind_param("i", $role_id);
$role_permissions_stmt->execute();
$role_permissions_result = $role_permissions_stmt->get_result();
$current_permissions = [];
while ($row = $role_permissions_result->fetch_assoc()) {
    $current_permissions[] = $row['permission_id'];
}

// Get user count for this role
$user_count_sql = "SELECT COUNT(*) as user_count FROM user_role_permissions WHERE role_id = ? AND is_active = 1";
$user_count_stmt = $mysqli->prepare($user_count_sql);
$user_count_stmt->bind_param("i", $role_id);
$user_count_stmt->execute();
$user_count_result = $user_count_stmt->get_result();
$user_count = $user_count_result->fetch_assoc()['user_count'];

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
                <i class="fas fa-fw fa-key mr-2"></i>Manage Permissions: <?php echo htmlspecialchars($role['role_name']); ?>
            </h3>
            <div class="card-tools">
                <a href="/clinic/admin/user/role_management.php" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Roles
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

        <!-- Role Information -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card bg-light">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <strong>Role Name:</strong> <?php echo htmlspecialchars($role['role_name']); ?>
                                <?php if ($role['role_is_admin']): ?>
                                    <span class="badge badge-success ml-2">Administrator</span>
                                <?php endif; ?>
                                <?php if ($role['is_system_role']): ?>
                                    <span class="badge badge-warning ml-1">System Role</span>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-4">
                                <strong>Assigned Users:</strong> 
                                <span class="badge badge-primary"><?php echo $user_count; ?> users</span>
                            </div>
                            <div class="col-md-4">
                                <strong>Current Permissions:</strong> 
                                <span class="badge badge-secondary"><?php echo count($current_permissions); ?> assigned</span>
                            </div>
                        </div>
                        <?php if ($role['role_description']): ?>
                            <div class="row mt-2">
                                <div class="col-12">
                                    <strong>Description:</strong> <?php echo htmlspecialchars($role['role_description']); ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-12">
                <form method="POST" id="permissionsForm">
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
                                <i class="fas fa-save mr-2"></i>Update Role Permissions
                            </button>
                            <a href="/clinic/admin/user/role_management.php" class="btn btn-secondary ml-2">
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
$('#permissionsForm').on('submit', function(e) {
    const selectedCount = $('.permission-checkbox:checked').length;
    
    if (selectedCount === 0) {
        if (!confirm('No permissions selected. This will remove all permissions from this role. Continue?')) {
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
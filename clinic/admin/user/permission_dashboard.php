<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/Permission.php';

// Get statistics
$stats_sql = "
    SELECT 
        (SELECT COUNT(*) FROM users WHERE user_archived_at IS NULL) as total_users,
        (SELECT COUNT(*) FROM users WHERE user_status = 1 AND user_archived_at IS NULL) as active_users,
        (SELECT COUNT(*) FROM user_roles) as total_roles,
        (SELECT COUNT(*) FROM permissions) as total_permissions,
        (SELECT COUNT(*) FROM role_permissions) as role_permission_assignments,
        (SELECT COUNT(*) FROM user_permissions) as user_permission_overrides,
        (SELECT COUNT(*) FROM user_role_permissions WHERE is_active = 1) as active_role_assignments
";

$stats_result = $mysqli->query($stats_sql);
$stats = $stats_result->fetch_assoc();

// Get recent user activity (role assignments and updates)
$activity_sql = "
    SELECT 
        u.user_name,
        ur.role_name,
        urp.assigned_at as activity_date,
        'role_assigned' as type
    FROM user_role_permissions urp 
    JOIN users u ON urp.user_id = u.user_id 
    JOIN user_roles ur ON urp.role_id = ur.role_id 
    WHERE urp.is_active = 1 
    AND u.user_archived_at IS NULL
    ORDER BY urp.assigned_at DESC 
    LIMIT 10
";

$activity_result = $mysqli->query($activity_sql);
$recent_activity = [];
while ($activity = $activity_result->fetch_assoc()) {
    $recent_activity[] = $activity;
}

// Get role distribution
$role_distribution_sql = "
    SELECT 
        ur.role_name,
        COUNT(urp.user_id) as user_count
    FROM user_roles ur 
    LEFT JOIN user_role_permissions urp ON ur.role_id = urp.role_id AND urp.is_active = 1
    LEFT JOIN users u ON urp.user_id = u.user_id AND u.user_archived_at IS NULL
    GROUP BY ur.role_id, ur.role_name
    ORDER BY user_count DESC
";

$role_distribution_result = $mysqli->query($role_distribution_sql);
$role_distribution = [];
while ($role = $role_distribution_result->fetch_assoc()) {
    $role_distribution[] = $role;
}

// Get permission distribution by category
$category_stats_sql = "
    SELECT 
        permission_category,
        COUNT(*) as permission_count
    FROM permissions 
    GROUP BY permission_category 
    ORDER BY permission_count DESC
";

$category_stats_result = $mysqli->query($category_stats_sql);
$category_stats = [];
while ($category = $category_stats_result->fetch_assoc()) {
    $category_stats[] = $category;
}

// Get module statistics
$module_stats_sql = "
    SELECT 
        COUNT(*) as total_modules,
        SUM(CASE WHEN is_module = 1 THEN 1 ELSE 0 END) as module_permissions,
        SUM(CASE WHEN is_module = 0 THEN 1 ELSE 0 END) as action_permissions
    FROM permissions
";

$module_stats_result = $mysqli->query($module_stats_sql);
$module_stats = $module_stats_result->fetch_assoc();

// Get users with role assignments
$users_with_roles_sql = "SELECT COUNT(DISTINCT urp.user_id) as count 
                         FROM user_role_permissions urp 
                         JOIN users u ON urp.user_id = u.user_id 
                         WHERE urp.is_active = 1 AND u.user_archived_at IS NULL";
$users_with_roles_result = $mysqli->query($users_with_roles_sql);
$users_with_roles = $users_with_roles_result->fetch_assoc()['count'];
$role_percentage = round(($users_with_roles / max(1, $stats['total_users'])) * 100);
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-shield-alt mr-2"></i>Permission System Dashboard
            </h3>
        </div>
    </div>

    <div class="card-body">
        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-2">
                <div class="info-box bg-success">
                    <span class="info-box-icon"><i class="fas fa-users"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Users</span>
                        <span class="info-box-number"><?php echo $stats['total_users']; ?></span>
                        <small class="text-muted">Active: <?php echo $stats['active_users']; ?></small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-info">
                    <span class="info-box-icon"><i class="fas fa-user-tag"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Roles</span>
                        <span class="info-box-number"><?php echo $stats['total_roles']; ?></span>
                        <small class="text-muted"><?php echo $stats['active_role_assignments']; ?> active</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-warning">
                    <span class="info-box-icon"><i class="fas fa-key"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Permissions</span>
                        <span class="info-box-number"><?php echo $stats['total_permissions']; ?></span>
                        <small class="text-muted"><?php echo $module_stats['module_permissions']; ?> modules</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-primary">
                    <span class="info-box-icon"><i class="fas fa-user-check"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Active Users</span>
                        <span class="info-box-number"><?php echo $stats['active_users']; ?></span>
                        <small class="text-muted"><?php echo round(($stats['active_users'] / max(1, $stats['total_users'])) * 100); ?>% active</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-secondary">
                    <span class="info-box-icon"><i class="fas fa-link"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Role Permissions</span>
                        <span class="info-box-number"><?php echo $stats['role_permission_assignments']; ?></span>
                        <small class="text-muted">Avg: <?php echo round($stats['role_permission_assignments'] / max(1, $stats['total_roles'])); ?> per role</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-dark">
                    <span class="info-box-icon"><i class="fas fa-user-cog"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">User Overrides</span>
                        <span class="info-box-number"><?php echo $stats['user_permission_overrides']; ?></span>
                        <small class="text-muted">Custom permissions</small>
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
                                <a href="/clinic/admin/user/user_management.php" class="btn btn-primary btn-block">
                                    <i class="fas fa-users mr-2"></i>Manage Users
                                </a>
                            </div>
                            <div class="col-md-3 mb-2">
                                <a href="/clinic/admin/user/role_management.php" class="btn btn-info btn-block">
                                    <i class="fas fa-user-tag mr-2"></i>Manage Roles
                                </a>
                            </div>
                            <div class="col-md-3 mb-2">
                                <a href="/clinic/admin/user/permission_management.php" class="btn btn-warning btn-block">
                                    <i class="fas fa-key mr-2"></i>Manage Permissions
                                </a>
                            </div>
                            <div class="col-md-3 mb-2">
                                <a href="/clinic/admin/user/audit_log.php" class="btn btn-secondary btn-block">
                                    <i class="fas fa-history mr-2"></i>View Audit Log
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Role Distribution & Permission Categories -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-chart-pie mr-2"></i>Role Distribution
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($role_distribution)): ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($role_distribution as $role): ?>
                                    <div class="list-group-item px-0 py-2 d-flex justify-content-between align-items-center">
                                        <div>
                                            <strong><?php echo htmlspecialchars($role['role_name'] ?: 'No Role'); ?></strong>
                                        </div>
                                        <span class="badge badge-primary badge-pill"><?php echo $role['user_count']; ?> users</span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-muted text-center mb-0">
                                <i class="fas fa-info-circle mr-2"></i>No role data available
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-cubes mr-2"></i>Permissions by Category
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($category_stats)): ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($category_stats as $category): ?>
                                    <div class="list-group-item px-0 py-2 d-flex justify-content-between align-items-center">
                                        <div>
                                            <strong><?php echo ucfirst(str_replace('_', ' ', htmlspecialchars($category['permission_category']))); ?></strong>
                                        </div>
                                        <span class="badge badge-info badge-pill"><?php echo $category['permission_count']; ?> perms</span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-muted text-center mb-0">
                                <i class="fas fa-info-circle mr-2"></i>No category data available
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Activity & System Info -->
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-history mr-2"></i>Recent Role Assignments
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($recent_activity)): ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($recent_activity as $activity): ?>
                                    <div class="list-group-item px-0 py-2">
                                        <div class="d-flex w-100 justify-content-between">
                                            <h6 class="mb-1">
                                                <i class="fas fa-user-tag mr-2 text-info"></i>
                                                <?php echo htmlspecialchars($activity['user_name']); ?>
                                            </h6>
                                            <small><?php echo $activity['activity_date'] ? timeAgo($activity['activity_date']) : 'Unknown'; ?></small>
                                        </div>
                                        <p class="mb-1 small text-muted">
                                            Assigned to: <?php echo htmlspecialchars($activity['role_name']); ?>
                                        </p>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-muted text-center mb-0">
                                <i class="fas fa-info-circle mr-2"></i>No recent role assignments
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-info-circle mr-2"></i>System Information
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-12 mb-3">
                                <strong>Permission System Status:</strong>
                                <span class="badge badge-success">Active</span>
                            </div>
                            <div class="col-12 mb-3">
                                <strong>Permission Types:</strong>
                                <span class="badge badge-primary"><?php echo $module_stats['module_permissions']; ?> modules</span>
                                <span class="badge badge-info"><?php echo $module_stats['action_permissions']; ?> actions</span>
                            </div>
                            <div class="col-12 mb-3">
                                <strong>Users with Roles:</strong>
                                <span class="badge badge-primary"><?php echo $users_with_roles; ?> (<?php echo $role_percentage; ?>%)</span>
                            </div>
                            <div class="col-12 mb-3">
                                <strong>Permission Categories:</strong>
                                <span class="badge badge-secondary"><?php echo count($category_stats); ?> categories</span>
                            </div>
                            <div class="col-12 mb-3">
                                <strong>Last System Update:</strong>
                                <span class="text-muted"><?php echo date('M j, Y g:i A'); ?></span>
                            </div>
                            <div class="col-12">
                                <strong>Your Permissions:</strong>
                                <?php
                                $user_permissions = SimplePermission::getPermissions();
                                $user_permission_count = count($user_permissions);
                                ?>
                                <span class="badge badge-primary"><?php echo $user_permission_count; ?> permissions</span>
                                <?php if (in_array('*', $user_permissions)): ?>
                                    <span class="badge badge-success ml-1">Administrator</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Additional Stats -->
        <div class="row mt-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-chart-bar mr-2"></i>Permission System Overview
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-md-3">
                                <div class="border rounded p-3">
                                    <h3 class="text-primary mb-0"><?php echo $stats['active_role_assignments']; ?></h3>
                                    <small class="text-muted">Active Role Assignments</small>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="border rounded p-3">
                                    <h3 class="text-success mb-0"><?php echo $module_stats['total_modules']; ?></h3>
                                    <small class="text-muted">Total Permissions</small>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="border rounded p-3">
                                    <h3 class="text-info mb-0"><?php echo count($category_stats); ?></h3>
                                    <small class="text-muted">Permission Categories</small>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="border rounded p-3">
                                    <h3 class="text-warning mb-0"><?php echo $stats['user_permission_overrides']; ?></h3>
                                    <small class="text-muted">User Permission Overrides</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Refresh dashboard every 30 seconds
    setInterval(function() {
        // You can add AJAX refresh here if needed
        console.log('Permission dashboard loaded');
    }, 30000);
});
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
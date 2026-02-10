<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Handle filters
$filter_user = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
$filter_type = isset($_GET['type']) ? sanitizeInput($_GET['type']) : '';
$filter_action = isset($_GET['action']) ? sanitizeInput($_GET['action']) : '';
$filter_date_from = isset($_GET['date_from']) ? sanitizeInput($_GET['date_from']) : '';
$filter_date_to = isset($_GET['date_to']) ? sanitizeInput($_GET['date_to']) : '';
$filter_ip = isset($_GET['ip']) ? sanitizeInput($_GET['ip']) : '';

// Build filter conditions
$filter_conditions = [];
$filter_params = [];
$filter_types = '';

if ($filter_user > 0) {
    $filter_conditions[] = "l.log_user_id= ?";
    $filter_params[] = $filter_user;
    $filter_types .= 'i';
}

if (!empty($filter_type)) {
    $filter_conditions[] = "l.log_type = ?";
    $filter_params[] = $filter_type;
    $filter_types .= 's';
}

if (!empty($filter_action)) {
    $filter_conditions[] = "l.log_action = ?";
    $filter_params[] = $filter_action;
    $filter_types .= 's';
}

if (!empty($filter_date_from)) {
    $filter_conditions[] = "DATE(l.log_created_at) >= ?";
    $filter_params[] = $filter_date_from;
    $filter_types .= 's';
}

if (!empty($filter_date_to)) {
    $filter_conditions[] = "DATE(l.log_created_at) <= ?";
    $filter_params[] = $filter_date_to;
    $filter_types .= 's';
}

if (!empty($filter_ip)) {
    $filter_conditions[] = "l.log_ip LIKE ?";
    $filter_params[] = "%$filter_ip%";
    $filter_types .= 's';
}

$filter_sql = '';
if (!empty($filter_conditions)) {
    $filter_sql = "WHERE " . implode(" AND ", $filter_conditions);
}

// Get total count for pagination
$count_sql = "SELECT COUNT(*) as total FROM logs l $filter_sql";
$count_stmt = $mysqli->prepare($count_sql);
if (!empty($filter_params)) {
    $count_stmt->bind_param($filter_types, ...$filter_params);
}
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_logs = $count_result->fetch_assoc()['total'];

// Pagination
$per_page = 50;
$total_pages = ceil($total_logs / $per_page);
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($current_page - 1) * $per_page;

// Get audit logs with user information
$logs_sql = "
    SELECT 
        l.*,
        u.user_name,
        u.user_email
    FROM logs l
    LEFT JOIN users u ON l.log_user_id = u.user_id
    $filter_sql
    ORDER BY l.log_created_at DESC
    LIMIT ? OFFSET ?
";

$logs_stmt = $mysqli->prepare($logs_sql);
$params = $filter_params;
$types = $filter_types . 'ii';
$params[] = $per_page;
$params[] = $offset;

if (!empty($params)) {
    $logs_stmt->bind_param($types, ...$params);
} else {
    $logs_stmt->bind_param('ii', $per_page, $offset);
}

$logs_stmt->execute();
$logs_result = $logs_stmt->get_result();
$logs = [];
while ($log = $logs_result->fetch_assoc()) {
    $logs[] = $log;
}

// Get unique log types for filter dropdown
$types_sql = "SELECT DISTINCT log_type FROM logs ORDER BY log_type";
$types_result = $mysqli->query($types_sql);
$log_types = [];
while ($type = $types_result->fetch_assoc()) {
    $log_types[] = $type['log_type'];
}

// Get unique log actions for filter dropdown
$actions_sql = "SELECT DISTINCT log_action FROM logs ORDER BY log_action";
$actions_result = $mysqli->query($actions_sql);
$log_actions = [];
while ($action = $actions_result->fetch_assoc()) {
    $log_actions[] = $action['log_action'];
}

// Get users for filter dropdown
$users_sql = "SELECT user_id, user_name FROM users WHERE user_archived_at IS NULL ORDER BY user_name";
$users_result = $mysqli->query($users_sql);
$users = [];
while ($user = $users_result->fetch_assoc()) {
    $users[] = $user;
}

// Get audit statistics
$stats_sql = "
    SELECT 
        COUNT(*) as total_entries,
        COUNT(DISTINCT log_user_id) as unique_users,
        COUNT(DISTINCT log_ip) as unique_ips,
        COUNT(DISTINCT log_type) as unique_types,
        MIN(log_created_at) as first_entry,
        MAX(log_created_at) as last_entry,
        COUNT(CASE WHEN log_action = 'Success' THEN 1 END) as success_count,
        COUNT(CASE WHEN log_action = 'Failed' THEN 1 END) as failed_count
    FROM logs
";

$stats_result = $mysqli->query($stats_sql);
$stats = $stats_result->fetch_assoc();

// Get recent activity types
$recent_activity_sql = "
    SELECT 
        log_type,
        COUNT(*) as count
    FROM logs 
    WHERE log_created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY log_type 
    ORDER BY count DESC 
    LIMIT 10
";
$recent_activity_result = $mysqli->query($recent_activity_sql);
$recent_activity = [];
while ($activity = $recent_activity_result->fetch_assoc()) {
    $recent_activity[] = $activity;
}
?>

<div class="card">
    <div class="card-header bg-secondary py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-history mr-2"></i>Audit Log
            </h3>
            <div class="card-tools">
                <button class="btn btn-info" data-toggle="collapse" data-target="#filterCollapse">
                    <i class="fas fa-filter mr-2"></i>Filters
                </button>
            </div>
        </div>
    </div>

    <div class="card-body">
        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-2">
                <div class="info-box bg-primary">
                    <span class="info-box-icon"><i class="fas fa-history"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Entries</span>
                        <span class="info-box-number"><?php echo number_format($stats['total_entries']); ?></span>
                        <small class="text-muted">All time</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-success">
                    <span class="info-box-icon"><i class="fas fa-users"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Unique Users</span>
                        <span class="info-box-number"><?php echo $stats['unique_users']; ?></span>
                        <small class="text-muted">Active users</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-info">
                    <span class="info-box-icon"><i class="fas fa-network-wired"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Unique IPs</span>
                        <span class="info-box-number"><?php echo $stats['unique_ips']; ?></span>
                        <small class="text-muted">IP addresses</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-warning">
                    <span class="info-box-icon"><i class="fas fa-tags"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Activity Types</span>
                        <span class="info-box-number"><?php echo $stats['unique_types']; ?></span>
                        <small class="text-muted">Different actions</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-success">
                    <span class="info-box-icon"><i class="fas fa-check-circle"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Success</span>
                        <span class="info-box-number"><?php echo number_format($stats['success_count']); ?></span>
                        <small class="text-muted">Successful actions</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-danger">
                    <span class="info-box-icon"><i class="fas fa-times-circle"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Failed</span>
                        <span class="info-box-number"><?php echo number_format($stats['failed_count']); ?></span>
                        <small class="text-muted">Failed actions</small>
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
                                <a href="/clinic/admin/user/permission_dashboard.php" class="btn btn-primary btn-block">
                                    <i class="fas fa-tachometer-alt mr-2"></i>Permission Dashboard
                                </a>
                            </div>
                            <div class="col-md-3 mb-2">
                                <a href="/clinic/admin/user/role_management.php" class="btn btn-info btn-block">
                                    <i class="fas fa-user-tag mr-2"></i>Role Management
                                </a>
                            </div>
                            <div class="col-md-3 mb-2">
                                <a href="/clinic/admin/user/user_management.php" class="btn btn-success btn-block">
                                    <i class="fas fa-users mr-2"></i>User Management
                                </a>
                            </div>
                            <div class="col-md-3 mb-2">
                                <button class="btn btn-warning btn-block" onclick="clearFilters()">
                                    <i class="fas fa-times mr-2"></i>Clear Filters
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="collapse <?php echo (!empty($filter_user) || !empty($filter_type) || !empty($filter_action) || !empty($filter_date_from) || !empty($filter_date_to) || !empty($filter_ip)) ? 'show' : ''; ?>" id="filterCollapse">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-filter mr-2"></i>Filter Audit Log
                    </h5>
                </div>
                <div class="card-body">
                    <form method="GET" id="filterForm">
                        <div class="row">
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="user_id">User</label>
                                    <select class="form-control" id="user_id" name="user_id">
                                        <option value="">All Users</option>
                                        <?php foreach ($users as $user): ?>
                                            <option value="<?php echo $user['user_id']; ?>" <?php echo $filter_user == $user['user_id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($user['user_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label for="type">Activity Type</label>
                                    <select class="form-control" id="type" name="type">
                                        <option value="">All Types</option>
                                        <?php foreach ($log_types as $type): ?>
                                            <option value="<?php echo htmlspecialchars($type); ?>" <?php echo $filter_type == $type ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($type); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label for="action">Action</label>
                                    <select class="form-control" id="action" name="action">
                                        <option value="">All Actions</option>
                                        <?php foreach ($log_actions as $action): ?>
                                            <option value="<?php echo htmlspecialchars($action); ?>" <?php echo $filter_action == $action ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($action); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label for="date_from">Date From</label>
                                    <input type="date" class="form-control" id="date_from" name="date_from" value="<?php echo $filter_date_from; ?>">
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label for="date_to">Date To</label>
                                    <input type="date" class="form-control" id="date_to" name="date_to" value="<?php echo $filter_date_to; ?>">
                                </div>
                            </div>
                            <div class="col-md-1">
                                <div class="form-group">
                                    <label for="ip">IP Address</label>
                                    <input type="text" class="form-control" id="ip" name="ip" value="<?php echo $filter_ip; ?>" placeholder="IP...">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-12 text-right">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-search mr-2"></i>Apply Filters
                                </button>
                                <a href="audit_log.php" class="btn btn-secondary">
                                    <i class="fas fa-times mr-2"></i>Reset
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Recent Activity & Logs -->
        <div class="row">
            <div class="col-md-3">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-chart-bar mr-2"></i>Recent Activity (7 days)
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($recent_activity)): ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($recent_activity as $activity): ?>
                                    <div class="list-group-item px-0 py-2 d-flex justify-content-between align-items-center">
                                        <span class="text-truncate"><?php echo htmlspecialchars($activity['log_type']); ?></span>
                                        <span class="badge badge-primary badge-pill"><?php echo $activity['count']; ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-muted text-center mb-0">No recent activity</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- System Information -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-info-circle mr-2"></i>System Information
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="list-group list-group-flush">
                            <div class="list-group-item px-0 py-2 d-flex justify-content-between align-items-center">
                                <span>First Entry</span>
                                <small class="text-muted"><?php echo $stats['first_entry'] ? date('M j, Y', strtotime($stats['first_entry'])) : 'N/A'; ?></small>
                            </div>
                            <div class="list-group-item px-0 py-2 d-flex justify-content-between align-items-center">
                                <span>Last Entry</span>
                                <small class="text-muted"><?php echo $stats['last_entry'] ? timeAgo($stats['last_entry']) : 'N/A'; ?></small>
                            </div>
                            <div class="list-group-item px-0 py-2 d-flex justify-content-between align-items-center">
                                <span>Showing</span>
                                <small class="text-muted"><?php echo number_format($total_logs); ?> entries</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-9">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-list mr-2"></i>Audit Log Entries
                        </h5>
                        <div>
                            <span class="badge badge-light">Page <?php echo $current_page; ?> of <?php echo $total_pages; ?></span>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <?php if (!empty($logs)): ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Date & Time</th>
                                            <th>User</th>
                                            <th>Type</th>
                                            <th>Action</th>
                                            <th>Description</th>
                                            <th>IP Address</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($logs as $log): ?>
                                            <tr>
                                                <td>
                                                    <small class="text-muted"><?php echo date('M j, Y', strtotime($log['log_created_at'])); ?></small><br>
                                                    <small><?php echo date('g:i A', strtotime($log['log_created_at'])); ?></small>
                                                </td>
                                                <td>
                                                    <?php if ($log['user_name']): ?>
                                                        <strong><?php echo htmlspecialchars($log['user_name']); ?></strong>
                                                        <br>
                                                        <small class="text-muted"><?php echo htmlspecialchars($log['user_email']); ?></small>
                                                    <?php else: ?>
                                                        <span class="text-muted">System</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge badge-secondary"><?php echo htmlspecialchars($log['log_type']); ?></span>
                                                </td>
                                                <td>
                                                    <?php if ($log['log_action'] == 'Success'): ?>
                                                        <span class="badge badge-success"><?php echo htmlspecialchars($log['log_action']); ?></span>
                                                    <?php elseif ($log['log_action'] == 'Failed'): ?>
                                                        <span class="badge badge-danger"><?php echo htmlspecialchars($log['log_action']); ?></span>
                                                    <?php else: ?>
                                                        <span class="badge badge-info"><?php echo htmlspecialchars($log['log_action']); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="log-description" title="<?php echo htmlspecialchars($log['log_description']); ?>">
                                                        <?php echo htmlspecialchars(mb_strimwidth($log['log_description'], 0, 60, '...')); ?>
                                                    </span>
                                                    <?php if ($log['log_entity_id'] > 0): ?>
                                                        <br>
                                                        <small class="text-muted">Entity ID: <?php echo $log['log_entity_id']; ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <small class="text-muted font-monospace"><?php echo htmlspecialchars($log['log_ip']); ?></small>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-search fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">No audit log entries found</h5>
                                <p class="text-muted">Try adjusting your filters or check back later for new activity.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="row mt-3">
                    <div class="col-md-12">
                        <nav aria-label="Audit log pagination">
                            <ul class="pagination justify-content-center">
                                <?php if ($current_page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>">
                                            <i class="fas fa-angle-double-left"></i>
                                        </a>
                                    </li>
                                    <li class="page-item">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $current_page - 1])); ?>">
                                            <i class="fas fa-angle-left"></i>
                                        </a>
                                    </li>
                                <?php endif; ?>

                                <?php
                                $start_page = max(1, $current_page - 2);
                                $end_page = min($total_pages, $current_page + 2);
                                
                                for ($i = $start_page; $i <= $end_page; $i++):
                                ?>
                                    <li class="page-item <?php echo $i == $current_page ? 'active' : ''; ?>">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>

                                <?php if ($current_page < $total_pages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $current_page + 1])); ?>">
                                            <i class="fas fa-angle-right"></i>
                                        </a>
                                    </li>
                                    <li class="page-item">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>">
                                            <i class="fas fa-angle-double-right"></i>
                                        </a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize tooltips
    $('[title]').tooltip();
    
    // Auto-submit form when filters change
    $('#user_id, #type, #action').change(function() {
        $('#filterForm').submit();
    });
});

function clearFilters() {
    window.location.href = 'audit_log.php';
}

// Auto-refresh every 30 seconds for real-time monitoring
setTimeout(function() {
    window.location.reload();
}, 30000);
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
<?php
// audit_logs.php - Audit Logs Viewing Dashboard
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Default Column Sortby/Order Filter
$sort = "event_time";
$order = "DESC";
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Status Filter
if (isset($_GET['status']) && !empty($_GET['status']) && $_GET['status'] != 'all') {
    $status_query = "AND (a.status = '" . sanitizeInput($_GET['status']) . "')";
    $status_filter = nullable_htmlentities($_GET['status']);
} else {
    // Default - all statuses
    $status_query = "";
    $status_filter = 'all';
}

// Date Range Filter
$dtf = sanitizeInput($_GET['dtf'] ?? date('Y-m-01'));
$dtt = sanitizeInput($_GET['dtt'] ?? date('Y-m-d'));

// Module Filter
$module_filter = sanitizeInput($_GET['module'] ?? '');
$module_query = $module_filter ? "AND a.module = '$module_filter'" : '';

// Action Filter
$action_filter = sanitizeInput($_GET['action'] ?? '');
$action_query = $action_filter ? "AND a.action = '$action_query'" : '';

// User Filter
$user_filter = sanitizeInput($_GET['user'] ?? '');
$user_query = $user_filter ? "AND (a.user_name LIKE '%$user_filter%' OR a.user_id = '$user_filter')" : '';

// Patient Filter
$patient_filter = sanitizeInput($_GET['patient'] ?? '');
$patient_query = $patient_filter ? "AND (a.patient_id = '$patient_filter')" : '';

// Entity Type Filter
$entity_filter = sanitizeInput($_GET['entity'] ?? '');
$entity_query = $entity_filter ? "AND a.entity_type = '$entity_filter'" : '';

// Search Query
if (isset($_GET['q']) && !empty($_GET['q'])) {
    $q = sanitizeInput($_GET['q']);
    $search_query = "AND (a.user_name LIKE '%$q%' OR a.description LIKE '%$q%' OR a.ip_address LIKE '%$q%' OR a.table_name LIKE '%$q%' OR a.old_values LIKE '%$q%' OR a.new_values LIKE '%$q%')";
} else {
    $q = '';
    $search_query = '';
}

// Get unique modules for filter
$modules_sql = "SELECT DISTINCT module FROM audit_logs WHERE module IS NOT NULL AND module != '' ORDER BY module";
$modules_result = $mysqli->query($modules_sql);

// Get unique actions for filter
$actions_sql = "SELECT DISTINCT action FROM audit_logs WHERE action IS NOT NULL AND action != '' ORDER BY action";
$actions_result = $mysqli->query($actions_sql);

// Get unique entity types for filter
$entities_sql = "SELECT DISTINCT entity_type FROM audit_logs WHERE entity_type IS NOT NULL AND entity_type != '' ORDER BY entity_type";
$entities_result = $mysqli->query($entities_sql);

// Main query for audit logs
$sql = mysqli_query(
    $mysqli,
    "
    SELECT SQL_CALC_FOUND_ROWS a.*,
           p.first_name as patient_first_name,
           p.last_name as patient_last_name,
           p.patient_mrn as patient_mrn
    FROM audit_logs a
    LEFT JOIN patients p ON a.patient_id = p.patient_id
    WHERE DATE(a.event_time) BETWEEN '$dtf' AND '$dtt'
      $status_query
      $module_query
      $action_query
      $user_query
      $patient_query
      $entity_query
      $search_query
    ORDER BY $sort $order
    LIMIT $record_from, $record_to
");

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get statistics for audit logs
$stats_sql = "SELECT 
    COUNT(*) as total_logs,
    SUM(CASE WHEN status = 'SUCCESS' THEN 1 ELSE 0 END) as success_logs,
    SUM(CASE WHEN status = 'FAILED' THEN 1 ELSE 0 END) as failed_logs,
    COUNT(DISTINCT user_id) as unique_users,
    COUNT(DISTINCT module) as unique_modules,
    SUM(CASE WHEN DATE(event_time) = CURDATE() THEN 1 ELSE 0 END) as today_logs,
    SUM(CASE WHEN DATE(event_time) = DATE_SUB(CURDATE(), INTERVAL 1 DAY) THEN 1 ELSE 0 END) as yesterday_logs,
    AVG(LENGTH(description)) as avg_description_length
    FROM audit_logs 
    WHERE DATE(event_time) BETWEEN '$dtf' AND '$dtt'";
$stats_result = $mysqli->query($stats_sql);
$stats = $stats_result->fetch_assoc();

// Reset pointers for filter dropdowns
$modules_result->data_seek(0);
$actions_result->data_seek(0);
$entities_result->data_seek(0);
?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2 mb-0"><i class="fas fa-fw fa-history mr-2"></i>Audit Logs</h3>
        <div class="card-tools">
            <?php if (SimplePermission::any("audit_export")) { ?>
            <button class="btn btn-success" onclick="exportAuditLogs()">
                <i class="fas fa-download mr-2"></i>Export
            </button>
            <?php } ?>
        </div>
    </div>
    
    <!-- Statistics Row for Audit Logs -->
    <div class="card-header pb-2 pt-3 bg-light">
        <form autocomplete="off">
            <div class="row">
                <div class="col-md-5">
                    <div class="form-group">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search logs, user, description, IP..." autofocus>
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
                            <span class="btn btn-light border">
                                <i class="fas fa-history text-dark mr-1"></i>
                                Total: <strong><?php echo $stats['total_logs'] ?? 0; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-check-circle text-success mr-1"></i>
                                Success: <strong><?php echo $stats['success_logs'] ?? 0; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-times-circle text-danger mr-1"></i>
                                Failed: <strong><?php echo $stats['failed_logs'] ?? 0; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-users text-info mr-1"></i>
                                Users: <strong><?php echo $stats['unique_users'] ?? 0; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-calendar-day text-secondary mr-1"></i>
                                Today: <strong><?php echo $stats['today_logs'] ?? 0; ?></strong>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php if (isset($_GET['dtf']) || $status_filter != 'all' || $module_filter || $action_filter || $user_filter || $patient_filter || $entity_filter) { echo "show"; } ?>" id="advancedFilter">
                <div class="row">
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Date Range</label>
                            <select onchange="this.form.submit()" class="form-control select2" name="canned_date">
                                <option <?php if (($_GET['canned_date'] ?? '') == "custom") { echo "selected"; } ?> value="custom">Custom</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "today") { echo "selected"; } ?> value="today">Today</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "yesterday") { echo "selected"; } ?> value="yesterday">Yesterday</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "thisweek") { echo "selected"; } ?> value="thisweek">This Week</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "lastweek") { echo "selected"; } ?> value="lastweek">Last Week</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "thismonth") { echo "selected"; } ?> value="thismonth">This Month</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "lastmonth") { echo "selected"; } ?> value="lastmonth">Last Month</option>
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
                            <label>Status</label>
                            <select class="form-control select2" name="status" onchange="this.form.submit()">
                                <option value="all" <?php if ($status_filter == "all") { echo "selected"; } ?>>- All Statuses -</option>
                                <option value="SUCCESS" <?php if ($status_filter == "SUCCESS") { echo "selected"; } ?>>Success</option>
                                <option value="FAILED" <?php if ($status_filter == "FAILED") { echo "selected"; } ?>>Failed</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Module</label>
                            <select class="form-control select2" name="module" onchange="this.form.submit()">
                                <option value="">- All Modules -</option>
                                <?php while ($module_row = $modules_result->fetch_assoc()): ?>
                                    <option value="<?php echo $module_row['module']; ?>" <?php if ($module_filter == $module_row['module']) { echo "selected"; } ?>>
                                        <?php echo $module_row['module']; ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Action</label>
                            <select class="form-control select2" name="action" onchange="this.form.submit()">
                                <option value="">- All Actions -</option>
                                <?php while ($action_row = $actions_result->fetch_assoc()): ?>
                                    <option value="<?php echo $action_row['action']; ?>" <?php if ($action_filter == $action_row['action']) { echo "selected"; } ?>>
                                        <?php echo $action_row['action']; ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Entity Type</label>
                            <select class="form-control select2" name="entity" onchange="this.form.submit()">
                                <option value="">- All Entities -</option>
                                <?php while ($entity_row = $entities_result->fetch_assoc()): ?>
                                    <option value="<?php echo $entity_row['entity_type']; ?>" <?php if ($entity_filter == $entity_row['entity_type']) { echo "selected"; } ?>>
                                        <?php echo $entity_row['entity_type']; ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>User</label>
                            <input type="text" class="form-control" name="user" value="<?php echo nullable_htmlentities($user_filter); ?>" placeholder="User name or ID" onchange="this.form.submit()">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Patient ID</label>
                            <input type="number" class="form-control" name="patient" value="<?php echo nullable_htmlentities($patient_filter); ?>" placeholder="Patient ID" onchange="this.form.submit()">
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
    
    <div class="table-responsive-sm">
        <table class="table table-hover mb-0">
            <thead class="<?php if ($num_rows[0] == 0) { echo "d-none"; } ?> bg-light">
            <tr>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=event_time&order=<?php echo $disp; ?>">
                        Timestamp <?php if ($sort == 'event_time') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>User Details</th>
                <th>Action & Module</th>
                <th>Entity Details</th>
                <th>Description</th>
                <th>Status & IP</th>
                <th class="text-center">View</th>
            </tr>
            </thead>
            <tbody>
            <?php 
            if (mysqli_num_rows($sql) > 0) {
                while ($row = mysqli_fetch_array($sql)) {
                    $audit_id = intval($row['audit_id']);
                    $event_time = nullable_htmlentities($row['event_time']);
                    $user_id = intval($row['user_id']);
                    $user_name = nullable_htmlentities($row['user_name']);
                    $user_role = nullable_htmlentities($row['user_role']);
                    $action = nullable_htmlentities($row['action']);
                    $module = nullable_htmlentities($row['module']);
                    $table_name = nullable_htmlentities($row['table_name']);
                    $entity_type = nullable_htmlentities($row['entity_type']);
                    $record_id = intval($row['record_id']);
                    $patient_id = intval($row['patient_id']);
                    $visit_id = intval($row['visit_id']);
                    $old_values = $row['old_values'];
                    $new_values = $row['new_values'];
                    $description = nullable_htmlentities($row['description']);
                    $status = nullable_htmlentities($row['status']);
                    $ip_address = nullable_htmlentities($row['ip_address']);
                    $user_agent = nullable_htmlentities($row['user_agent']);
                    $created_at = nullable_htmlentities($row['created_at']);
                    
                    // Patient info if available
                    $patient_name = '';
                    $patient_mrn = '';
                    if ($row['patient_first_name'] && $row['patient_last_name']) {
                        $patient_name = $row['patient_first_name'] . ' ' . $row['patient_last_name'];
                        $patient_mrn = $row['patient_mrn'];
                    }

                    // Status badge styling
                    $status_color = '';
                    $status_text = '';
                    switch($status) {
                        case 'SUCCESS':
                            $status_color = 'success';
                            $status_text = 'SUCCESS';
                            break;
                        case 'FAILED':
                            $status_color = 'danger';
                            $status_text = 'FAILED';
                            break;
                        default:
                            $status_color = 'secondary';
                            $status_text = $status;
                    }

                    // Action badge styling
                    $action_color = '';
                    switch($action) {
                        case 'CREATE':
                        case 'INSERT':
                            $action_color = 'success';
                            break;
                        case 'UPDATE':
                        case 'EDIT':
                            $action_color = 'warning';
                            break;
                        case 'DELETE':
                        case 'REMOVE':
                            $action_color = 'danger';
                            break;
                        case 'VIEW':
                        case 'READ':
                            $action_color = 'info';
                            break;
                        case 'LOGIN':
                        case 'LOGOUT':
                            $action_color = 'primary';
                            break;
                        default:
                            $action_color = 'secondary';
                    }

                    // Format dates
                    $event_date = date('M j, Y', strtotime($event_time));
                    $event_time_formatted = date('g:i:s A', strtotime($event_time));
                    
                    // Truncate description for display
                    $description_display = strlen($description) > 100 ? substr($description, 0, 100) . '...' : $description;
                    
                    // Build entity info
                    $entity_info = $entity_type;
                    if ($record_id) {
                        $entity_info .= " #" . $record_id;
                    }
                    if ($table_name) {
                        $entity_info .= " (" . $table_name . ")";
                    }
                    
                    // Build patient info
                    $patient_info = '';
                    if ($patient_id) {
                        $patient_info = "Patient #" . $patient_id;
                        if ($patient_name) {
                            $patient_info .= ": " . $patient_name;
                            if ($patient_mrn) {
                                $patient_info .= " (MRN: " . $patient_mrn . ")";
                            }
                        }
                    }
                    
                    // Build visit info
                    $visit_info = $visit_id ? "Visit #" . $visit_id : '';
                    
                    // Row styling based on status
                    $row_class = $status == 'FAILED' ? 'table-danger' : '';
                    ?>
                    <tr class="<?php echo $row_class; ?>">
                        <td>
                            <div class="font-weight-bold"><?php echo $event_date; ?></div>
                            <small class="text-muted"><?php echo $event_time_formatted; ?></small>
                            <br>
                            <small class="text-muted">Log #<?php echo $audit_id; ?></small>
                        </td>
                        <td>
                            <?php if ($user_name): ?>
                                <div class="font-weight-bold"><?php echo $user_name; ?></div>
                                <?php if ($user_role): ?>
                                    <small class="badge badge-secondary"><?php echo $user_role; ?></small>
                                <?php endif; ?>
                                <?php if ($user_id): ?>
                                    <div class="text-muted">ID: <?php echo $user_id; ?></div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">System / Unknown</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="d-flex flex-column">
                                <span class="badge badge-<?php echo $action_color; ?> mb-1"><?php echo $action; ?></span>
                                <span class="badge badge-dark"><?php echo $module; ?></span>
                            </div>
                        </td>
                        <td>
                            <div class="font-weight-bold"><?php echo $entity_info; ?></div>
                            <?php if ($patient_info): ?>
                                <small class="text-info d-block"><?php echo $patient_info; ?></small>
                            <?php endif; ?>
                            <?php if ($visit_info): ?>
                                <small class="text-primary d-block"><?php echo $visit_info; ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div data-toggle="tooltip" title="<?php echo htmlspecialchars($description); ?>">
                                <?php echo $description_display; ?>
                            </div>
                        </td>
                        <td>
                            <div class="d-flex flex-column">
                                <span class="badge badge-<?php echo $status_color; ?> mb-1"><?php echo $status_text; ?></span>
                                <?php if ($ip_address): ?>
                                    <small class="text-muted" data-toggle="tooltip" title="IP Address">
                                        <i class="fas fa-network-wired"></i> <?php echo $ip_address; ?>
                                    </small>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="text-center">
                            <div class="dropdown dropleft">
                                <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                    <i class="fas fa-ellipsis-h"></i>
                                </button>
                                <div class="dropdown-menu">
                                    <?php if (SimplePermission::any("audit_view_details")): ?>
                                    <a class="dropdown-item" href="#" data-toggle="modal" data-target="#viewLogModal<?php echo $audit_id; ?>">
                                        <i class="fas fa-fw fa-eye mr-2"></i>View Details
                                    </a>
                                    <?php endif; ?>
                                    
                                    <?php if ($old_values || $new_values): ?>
                                    <a class="dropdown-item" href="#" data-toggle="modal" data-target="#viewChangesModal<?php echo $audit_id; ?>">
                                        <i class="fas fa-fw fa-exchange-alt mr-2"></i>View Changes
                                    </a>
                                    <?php endif; ?>
                                    
                                    <?php if ($user_agent): ?>
                                    <a class="dropdown-item" href="#" data-toggle="modal" data-target="#viewUserAgentModal<?php echo $audit_id; ?>">
                                        <i class="fas fa-fw fa-desktop mr-2"></i>Browser Info
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                    </tr>
                    
                    <!-- View Log Details Modal -->
                    <div class="modal fade" id="viewLogModal<?php echo $audit_id; ?>" tabindex="-1" role="dialog">
                        <div class="modal-dialog modal-lg" role="document">
                            <div class="modal-content">
                                <div class="modal-header bg-dark text-white">
                                    <h5 class="modal-title">Log Details #<?php echo $audit_id; ?></h5>
                                    <button type="button" class="close text-white" data-dismiss="modal">
                                        <span>&times;</span>
                                    </button>
                                </div>
                                <div class="modal-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <h6>Basic Information</h6>
                                            <table class="table table-sm">
                                                <tr>
                                                    <th width="40%">Timestamp:</th>
                                                    <td><?php echo $event_date . ' ' . $event_time_formatted; ?></td>
                                                </tr>
                                                <tr>
                                                    <th>Status:</th>
                                                    <td><span class="badge badge-<?php echo $status_color; ?>"><?php echo $status_text; ?></span></td>
                                                </tr>
                                                <tr>
                                                    <th>Module:</th>
                                                    <td><?php echo $module; ?></td>
                                                </tr>
                                                <tr>
                                                    <th>Action:</th>
                                                    <td><?php echo $action; ?></td>
                                                </tr>
                                            </table>
                                        </div>
                                        <div class="col-md-6">
                                            <h6>User Information</h6>
                                            <table class="table table-sm">
                                                <tr>
                                                    <th width="40%">User Name:</th>
                                                    <td><?php echo $user_name ?: 'System'; ?></td>
                                                </tr>
                                                <tr>
                                                    <th>User Role:</th>
                                                    <td><?php echo $user_role ?: 'N/A'; ?></td>
                                                </tr>
                                                <tr>
                                                    <th>User ID:</th>
                                                    <td><?php echo $user_id ?: 'N/A'; ?></td>
                                                </tr>
                                                <tr>
                                                    <th>IP Address:</th>
                                                    <td><?php echo $ip_address ?: 'N/A'; ?></td>
                                                </tr>
                                            </table>
                                        </div>
                                    </div>
                                    
                                    <div class="row mt-3">
                                        <div class="col-md-6">
                                            <h6>Entity Information</h6>
                                            <table class="table table-sm">
                                                <tr>
                                                    <th width="40%">Entity Type:</th>
                                                    <td><?php echo $entity_type; ?></td>
                                                </tr>
                                                <tr>
                                                    <th>Record ID:</th>
                                                    <td><?php echo $record_id ?: 'N/A'; ?></td>
                                                </tr>
                                                <tr>
                                                    <th>Table Name:</th>
                                                    <td><?php echo $table_name; ?></td>
                                                </tr>
                                                <?php if ($patient_id): ?>
                                                <tr>
                                                    <th>Patient ID:</th>
                                                    <td><?php echo $patient_id; ?></td>
                                                </tr>
                                                <?php endif; ?>
                                                <?php if ($visit_id): ?>
                                                <tr>
                                                    <th>Visit ID:</th>
                                                    <td><?php echo $visit_id; ?></td>
                                                </tr>
                                                <?php endif; ?>
                                            </table>
                                        </div>
                                        <div class="col-md-6">
                                            <h6>Additional Information</h6>
                                            <table class="table table-sm">
                                                <tr>
                                                    <th width="40%">Description:</th>
                                                    <td><?php echo $description ?: 'N/A'; ?></td>
                                                </tr>
                                                <tr>
                                                    <th>Created At:</th>
                                                    <td><?php echo date('M j, Y g:i:s A', strtotime($created_at)); ?></td>
                                                </tr>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- View Changes Modal -->
                    <?php if ($old_values || $new_values): ?>
                    <div class="modal fade" id="viewChangesModal<?php echo $audit_id; ?>" tabindex="-1" role="dialog">
                        <div class="modal-dialog modal-xl" role="document">
                            <div class="modal-content">
                                <div class="modal-header bg-warning">
                                    <h5 class="modal-title">Data Changes #<?php echo $audit_id; ?></h5>
                                    <button type="button" class="close" data-dismiss="modal">
                                        <span>&times;</span>
                                    </button>
                                </div>
                                <div class="modal-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <h6 class="text-danger">Old Values</h6>
                                            <pre class="bg-light p-3" style="max-height: 400px; overflow-y: auto;"><?php 
                                                if ($old_values) {
                                                    $old_data = json_decode($old_values, true);
                                                    if ($old_data) {
                                                        echo htmlspecialchars(json_encode($old_data, JSON_PRETTY_PRINT));
                                                    } else {
                                                        echo htmlspecialchars($old_values);
                                                    }
                                                } else {
                                                    echo 'No old values';
                                                }
                                            ?></pre>
                                        </div>
                                        <div class="col-md-6">
                                            <h6 class="text-success">New Values</h6>
                                            <pre class="bg-light p-3" style="max-height: 400px; overflow-y: auto;"><?php 
                                                if ($new_values) {
                                                    $new_data = json_decode($new_values, true);
                                                    if ($new_data) {
                                                        echo htmlspecialchars(json_encode($new_data, JSON_PRETTY_PRINT));
                                                    } else {
                                                        echo htmlspecialchars($new_values);
                                                    }
                                                } else {
                                                    echo 'No new values';
                                                }
                                            ?></pre>
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- View User Agent Modal -->
                    <?php if ($user_agent): ?>
                    <div class="modal fade" id="viewUserAgentModal<?php echo $audit_id; ?>" tabindex="-1" role="dialog">
                        <div class="modal-dialog" role="document">
                            <div class="modal-content">
                                <div class="modal-header bg-info text-white">
                                    <h5 class="modal-title">Browser Information #<?php echo $audit_id; ?></h5>
                                    <button type="button" class="close text-white" data-dismiss="modal">
                                        <span>&times;</span>
                                    </button>
                                </div>
                                <div class="modal-body">
                                    <h6>User Agent String:</h6>
                                    <pre class="bg-light p-3"><?php echo htmlspecialchars($user_agent); ?></pre>
                                    <hr>
                                    <h6>Parsed Information:</h6>
                                    <table class="table table-sm">
                                        <?php
                                        // Simple browser detection (you might want to use a library for this in production)
                                        $browser_info = 'Unknown';
                                        $os_info = 'Unknown';
                                        
                                        if (stripos($user_agent, 'Chrome') !== false) $browser_info = 'Google Chrome';
                                        elseif (stripos($user_agent, 'Firefox') !== false) $browser_info = 'Mozilla Firefox';
                                        elseif (stripos($user_agent, 'Safari') !== false) $browser_info = 'Apple Safari';
                                        elseif (stripos($user_agent, 'Edge') !== false) $browser_info = 'Microsoft Edge';
                                        elseif (stripos($user_agent, 'MSIE') !== false || stripos($user_agent, 'Trident') !== false) $browser_info = 'Internet Explorer';
                                        
                                        if (stripos($user_agent, 'Windows') !== false) $os_info = 'Windows';
                                        elseif (stripos($user_agent, 'Mac') !== false) $os_info = 'macOS';
                                        elseif (stripos($user_agent, 'Linux') !== false) $os_info = 'Linux';
                                        elseif (stripos($user_agent, 'Android') !== false) $os_info = 'Android';
                                        elseif (stripos($user_agent, 'iOS') !== false) $os_info = 'iOS';
                                        ?>
                                        <tr>
                                            <th width="40%">Browser:</th>
                                            <td><?php echo $browser_info; ?></td>
                                        </tr>
                                        <tr>
                                            <th>Operating System:</th>
                                            <td><?php echo $os_info; ?></td>
                                        </tr>
                                    </table>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php
                }
            } else {
                ?>
                <tr>
                    <td colspan="7" class="text-center py-4">
                        <i class="fas fa-history fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No audit logs found</h5>
                        <p class="text-muted">Try adjusting your filters or search criteria</p>
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
    
    <!-- Pagination Footer -->
    <?php 
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/filter_footer.php';
    ?>
    
</div> <!-- End Card -->

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

    // Initialize tooltips
    $('[data-toggle="tooltip"]').tooltip();

    // Keyboard shortcuts
    $(document).keydown(function(e) {
        // Ctrl + F for focus search
        if (e.ctrlKey && e.keyCode === 70) {
            e.preventDefault();
            $('input[name="q"]').focus();
        }
        // Ctrl + A for advanced filter toggle
        if (e.ctrlKey && e.keyCode === 65) {
            e.preventDefault();
            $('#advancedFilter').collapse('toggle');
        }
        // Ctrl + E for export
        if (e.ctrlKey && e.keyCode === 69) {
            e.preventDefault();
            exportAuditLogs();
        }
    });
});

function exportAuditLogs() {
    // Get current filter parameters
    const urlParams = new URLSearchParams(window.location.search);
    urlParams.set('export', 'csv');
    
    // Redirect to export endpoint
    window.location.href = 'post/audit_export.php?' + urlParams.toString();
}
</script>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
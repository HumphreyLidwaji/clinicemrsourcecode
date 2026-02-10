<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Handle actions
if (isset($_GET['action']) && isset($_GET['id'])) {
    $incident_id = intval($_GET['id']);
    
    if ($_GET['action'] == 'close_incident') {
        $sql = "UPDATE misconduct_incidents SET status = 'closed' WHERE incident_id = ?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("i", $incident_id);
        
        if ($stmt->execute()) {
            // Log the action
            $audit_sql = "INSERT INTO hr_audit_log (user_id, action, description, table_name, record_id) VALUES (?, 'misconduct_incident_closed', ?, 'misconduct_incidents', ?)";
            $audit_stmt = $mysqli->prepare($audit_sql);
            $description = "Closed misconduct incident ID: $incident_id";
            $audit_stmt->bind_param("isi", $_SESSION['user_id'], $description, $incident_id);
            $audit_stmt->execute();
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Misconduct incident closed successfully!";
        } else {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error closing incident: " . $stmt->error;
        }
        header("Location: misconduct_dashboard.php");
        exit;
    }
}

// Default Column Sortby/Order Filter
$sort = "mi.incident_date";
$order = "DESC";

// Get filter parameters
$category_filter = $_GET['category'] ?? '';
$severity_filter = $_GET['severity'] ?? 'all';
$status_filter = $_GET['status'] ?? 'all';
$employee_filter = $_GET['employee'] ?? '';
$investigator_filter = $_GET['investigator'] ?? '';
$search = $_GET['search'] ?? '';

// Date Range for Incidents
$dtf = sanitizeInput($_GET['dtf'] ?? date('Y-m-01'));
$dtt = sanitizeInput($_GET['dtt'] ?? date('Y-m-t'));

// Build query with filters
$sql = "SELECT SQL_CALC_FOUND_ROWS 
            mi.*, 
            mc.category_name,
            e.first_name, e.last_name, e.employee_number,
            er.first_name as reported_first, er.last_name as reported_last,
            inv.first_name as inv_first, inv.last_name as inv_last,
            (SELECT COUNT(*) FROM misconduct_investigations WHERE incident_id = mi.incident_id) as investigation_count,
            (SELECT COUNT(*) FROM show_cause_letters WHERE incident_id = mi.incident_id) as show_cause_count,
            (SELECT COUNT(*) FROM disciplinary_hearings WHERE incident_id = mi.incident_id) as hearing_count
        FROM misconduct_incidents mi 
        JOIN misconduct_categories mc ON mi.category_id = mc.category_id 
        JOIN employees e ON mi.employee_id = e.employee_id 
        JOIN employees er ON mi.reported_by = er.employee_id 
        LEFT JOIN misconduct_investigations minv ON mi.incident_id = minv.incident_id 
        LEFT JOIN employees inv ON minv.investigator_id = inv.employee_id 
        WHERE mi.incident_date BETWEEN '$dtf' AND '$dtt'";

$params = [];
$types = '';

if (!empty($category_filter)) {
    $sql .= " AND mi.category_id = ?";
    $params[] = $category_filter;
    $types .= 'i';
}

if (!empty($severity_filter) && $severity_filter != 'all') {
    $sql .= " AND mi.severity = ?";
    $params[] = $severity_filter;
    $types .= 's';
}

if (!empty($status_filter) && $status_filter != 'all') {
    $sql .= " AND mi.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

if (!empty($employee_filter)) {
    $sql .= " AND mi.employee_id = ?";
    $params[] = $employee_filter;
    $types .= 'i';
}

if (!empty($investigator_filter)) {
    $sql .= " AND minv.investigator_id = ?";
    $params[] = $investigator_filter;
    $types .= 'i';
}

if (!empty($search)) {
    $sql .= " AND (e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_number LIKE ? OR mi.description LIKE ? OR mc.category_name LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= 'sssss';
}

$sql .= " ORDER BY $sort $order LIMIT $record_from, $record_to";

$stmt = $mysqli->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$incidents_result = $stmt->get_result();

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get statistics for dashboard
$stats_sql = "
    SELECT 
        (SELECT COUNT(*) FROM misconduct_incidents WHERE status = 'open') as open_incidents,
        (SELECT COUNT(*) FROM misconduct_incidents WHERE status = 'under_investigation') as investigation_incidents,
        (SELECT COUNT(*) FROM misconduct_incidents WHERE status = 'closed') as closed_incidents,
        (SELECT COUNT(*) FROM misconduct_incidents WHERE severity = 'high' OR severity = 'gross') as serious_incidents,
        (SELECT COUNT(*) FROM misconduct_investigations WHERE end_date IS NULL) as ongoing_investigations,
        (SELECT COUNT(*) FROM disciplinary_hearings WHERE hearing_date >= CURDATE()) as upcoming_hearings,
        (SELECT COUNT(*) FROM show_cause_letters WHERE due_date >= CURDATE()) as pending_responses
";
$stats_result = $mysqli->query($stats_sql);
$stats = $stats_result->fetch_assoc();

// Get categories for filter
$categories_sql = "SELECT category_id, category_name FROM misconduct_categories WHERE is_active = 1 ORDER BY category_name";
$categories_result = $mysqli->query($categories_sql);

// Get employees for filter
$employees_sql = "SELECT employee_id, first_name, last_name, employee_number FROM employees WHERE employment_status = 'active' ORDER BY first_name, last_name";
$employees_result = $mysqli->query($employees_sql);

// Get recent actions for sidebar
$recent_actions_sql = "
    SELECT da.*, mi.incident_id, e.first_name, e.last_name, e.employee_number
    FROM disciplinary_actions da
    JOIN misconduct_incidents mi ON da.incident_id = mi.incident_id
    JOIN employees e ON mi.employee_id = e.employee_id
    ORDER BY da.created_at DESC
    LIMIT 5
";
$recent_actions = $mysqli->query($recent_actions_sql);
?>

<div class="card">
    <div class="card-header bg-danger py-2">
        <h3 class="card-title mt-2 mb-0"><i class="fas fa-fw fa-exclamation-triangle mr-2"></i>Misconduct Management Dashboard</h3>
        <div class="card-tools">
            <a href="hr_dashboard.php" class="btn btn-light">
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
                            <input type="search" class="form-control" name="search" value="<?php if (isset($search)) { echo stripslashes(nullable_htmlentities($search)); } ?>" placeholder="Search employees, descriptions, categories..." autofocus>
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
                            <a href="add_misconduct_incident.php" class="btn btn-success">
                                <i class="fas fa-plus-circle mr-2"></i>Report Incident
                            </a>
                            <a href="manage_misconduct_categories.php" class="btn btn-info">
                                <i class="fas fa-tags mr-2"></i>Manage Categories
                            </a>
                            <a href="misconduct_reports.php" class="btn btn-warning">
                                <i class="fas fa-chart-bar mr-2"></i>Reports
                            </a>
                        </div>
                        <div class="btn-group mr-2">
                            <span class="btn btn-light border">
                                <i class="fas fa-folder-open text-primary mr-1"></i>
                                Open: <strong><?php echo $stats['open_incidents']; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-search text-warning mr-1"></i>
                                Investigating: <strong><?php echo $stats['investigation_incidents']; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-check-circle text-success mr-1"></i>
                                Closed: <strong><?php echo $stats['closed_incidents']; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-exclamation text-danger mr-1"></i>
                                Serious: <strong><?php echo $stats['serious_incidents']; ?></strong>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php if (isset($_GET['dtf']) || $status_filter != 'all' || $category_filter || $severity_filter != 'all') { echo "show"; } ?>" id="advancedFilter">
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
                                <option value="open" <?php if ($status_filter == "open") { echo "selected"; } ?>>Open</option>
                                <option value="under_investigation" <?php if ($status_filter == "under_investigation") { echo "selected"; } ?>>Under Investigation</option>
                                <option value="closed" <?php if ($status_filter == "closed") { echo "selected"; } ?>>Closed</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Severity</label>
                            <select class="form-control select2" name="severity" onchange="this.form.submit()">
                                <option value="all" <?php if ($severity_filter == "all") { echo "selected"; } ?>>- All Severities -</option>
                                <option value="low" <?php if ($severity_filter == "low") { echo "selected"; } ?>>Low</option>
                                <option value="medium" <?php if ($severity_filter == "medium") { echo "selected"; } ?>>Medium</option>
                                <option value="high" <?php if ($severity_filter == "high") { echo "selected"; } ?>>High</option>
                                <option value="gross" <?php if ($severity_filter == "gross") { echo "selected"; } ?>>Gross</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Category</label>
                            <select class="form-control select2" name="category" onchange="this.form.submit()">
                                <option value="">- All Categories -</option>
                                <?php
                                while ($row = mysqli_fetch_array($categories_result)) {
                                    $category_id = intval($row['category_id']);
                                    $category_name = nullable_htmlentities($row['category_name']);
                                ?>
                                    <option value="<?php echo $category_id; ?>" <?php if ($category_id == $category_filter) { echo "selected"; } ?>>
                                        <?php echo $category_name; ?>
                                    </option>
                                <?php
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Employee</label>
                            <select class="form-control select2" name="employee" onchange="this.form.submit()">
                                <option value="">- All Employees -</option>
                                <?php
                                mysqli_data_seek($employees_result, 0);
                                while ($row = mysqli_fetch_array($employees_result)) {
                                    $employee_id = intval($row['employee_id']);
                                    $employee_name = nullable_htmlentities($row['first_name'] . ' ' . $row['last_name']);
                                    $employee_number = nullable_htmlentities($row['employee_number']);
                                ?>
                                    <option value="<?php echo $employee_id; ?>" <?php if ($employee_id == $employee_filter) { echo "selected"; } ?>>
                                        <?php echo $employee_name . " (" . $employee_number . ")"; ?>
                                    </option>
                                <?php
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Investigator</label>
                            <select class="form-control select2" name="investigator" onchange="this.form.submit()">
                                <option value="">- All Investigators -</option>
                                <?php
                                mysqli_data_seek($employees_result, 0);
                                while ($row = mysqli_fetch_array($employees_result)) {
                                    $employee_id = intval($row['employee_id']);
                                    $employee_name = nullable_htmlentities($row['first_name'] . ' ' . $row['last_name']);
                                ?>
                                    <option value="<?php echo $employee_id; ?>" <?php if ($employee_id == $investigator_filter) { echo "selected"; } ?>>
                                        <?php echo $employee_name; ?>
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
    
    <div class="row">
        <!-- Main Content -->
        <div class="col-md-9">
            <div class="table-responsive-sm">
                <table class="table table-hover mb-0">
                    <thead class="<?php if ($num_rows[0] == 0) { echo "d-none"; } ?> bg-light">
                    <tr>
                        <th>Employee</th>
                        <th>Incident Details</th>
                        <th>Category</th>
                        <th>Severity</th>
                        <th>Status</th>
                        <th>Progress</th>
                        <th class="text-center">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php
                    while ($row = mysqli_fetch_array($incidents_result)) {
                        $incident_id = intval($row['incident_id']);
                        $employee_name = nullable_htmlentities($row['first_name'] . ' ' . $row['last_name']);
                        $employee_number = nullable_htmlentities($row['employee_number']);
                        $reported_by = nullable_htmlentities($row['reported_first'] . ' ' . $row['reported_last']);
                        $category_name = nullable_htmlentities($row['category_name']);
                        $description = nullable_htmlentities($row['description']);
                        $incident_date = nullable_htmlentities($row['incident_date']);
                        $severity = nullable_htmlentities($row['severity']);
                        $status = nullable_htmlentities($row['status']);
                        $investigation_count = intval($row['investigation_count']);
                        $show_cause_count = intval($row['show_cause_count']);
                        $hearing_count = intval($row['hearing_count']);

                        // Severity badge styling
                        $severity_badge = "";
                        switch($severity) {
                            case 'low':
                                $severity_badge = "badge-info";
                                break;
                            case 'medium':
                                $severity_badge = "badge-warning";
                                break;
                            case 'high':
                                $severity_badge = "badge-danger";
                                break;
                            case 'gross':
                                $severity_badge = "badge-dark";
                                break;
                            default:
                                $severity_badge = "badge-light";
                        }

                        // Status badge styling
                        $status_badge = "";
                        switch($status) {
                            case 'open':
                                $status_badge = "badge-primary";
                                break;
                            case 'under_investigation':
                                $status_badge = "badge-warning";
                                break;
                            case 'closed':
                                $status_badge = "badge-success";
                                break;
                            default:
                                $status_badge = "badge-light";
                        }

                        // Progress indicators
                        $progress_steps = [
                            'investigation' => $investigation_count > 0,
                            'show_cause' => $show_cause_count > 0,
                            'hearing' => $hearing_count > 0
                        ];
                        $completed_steps = array_sum($progress_steps);
                        $total_steps = count($progress_steps);
                        $progress_percentage = $total_steps > 0 ? ($completed_steps / $total_steps) * 100 : 0;
                        ?>
                        <tr>
                            <td>
                                <div class="font-weight-bold"><?php echo $employee_name; ?></div>
                                <div class="small text-muted">ID: <?php echo $employee_number; ?></div>
                                <div class="small">
                                    <i class="fas fa-calendar mr-1"></i><?php echo date('M j, Y', strtotime($incident_date)); ?>
                                </div>
                            </td>
                            <td>
                                <div class="font-weight-bold text-truncate" style="max-width: 200px;" title="<?php echo $description; ?>">
                                    <?php echo strlen($description) > 50 ? substr($description, 0, 50) . '...' : $description; ?>
                                </div>
                                <div class="small text-muted">
                                    Reported by: <?php echo $reported_by; ?>
                                </div>
                            </td>
                            <td><?php echo $category_name; ?></td>
                            <td>
                                <span class="badge <?php echo $severity_badge; ?>"><?php echo ucfirst($severity); ?></span>
                            </td>
                            <td>
                                <span class="badge <?php echo $status_badge; ?>"><?php echo ucfirst(str_replace('_', ' ', $status)); ?></span>
                            </td>
                            <td>
                                <div class="progress" style="height: 8px;">
                                    <div class="progress-bar 
                                        <?php 
                                        if ($progress_percentage < 33) echo 'bg-danger';
                                        elseif ($progress_percentage < 66) echo 'bg-warning';
                                        else echo 'bg-success';
                                        ?>" 
                                         style="width: <?php echo $progress_percentage; ?>%">
                                    </div>
                                </div>
                                <div class="small text-muted mt-1">
                                    <?php echo $completed_steps; ?> of <?php echo $total_steps; ?> steps
                                </div>
                            </td>
                            <td>
                                <div class="dropdown dropleft text-center">
                                    <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                        <i class="fas fa-ellipsis-h"></i>
                                    </button>
                                    <div class="dropdown-menu">
                                        <a class="dropdown-item" href="view_misconduct_incident.php?id=<?php echo $incident_id; ?>">
                                            <i class="fas fa-fw fa-eye mr-2"></i>View Details
                                        </a>
                                        <a class="dropdown-item" href="edit_misconduct_incident.php?id=<?php echo $incident_id; ?>">
                                            <i class="fas fa-fw fa-edit mr-2"></i>Edit Incident
                                        </a>
                                        <div class="dropdown-divider"></div>
                                        <?php if ($investigation_count == 0): ?>
                                            <a class="dropdown-item" href="add_investigation.php?incident_id=<?php echo $incident_id; ?>">
                                                <i class="fas fa-fw fa-search mr-2"></i>Start Investigation
                                            </a>
                                        <?php else: ?>
                                            <a class="dropdown-item" href="view_investigation.php?incident_id=<?php echo $incident_id; ?>">
                                                <i class="fas fa-fw fa-search mr-2"></i>View Investigation
                                            </a>
                                        <?php endif; ?>
                                        
                                        <?php if ($show_cause_count == 0 && $investigation_count > 0): ?>
                                            <a class="dropdown-item" href="issue_show_cause.php?incident_id=<?php echo $incident_id; ?>">
                                                <i class="fas fa-fw fa-envelope mr-2"></i>Issue Show Cause
                                            </a>
                                        <?php elseif ($show_cause_count > 0): ?>
                                            <a class="dropdown-item" href="view_show_cause.php?incident_id=<?php echo $incident_id; ?>">
                                                <i class="fas fa-fw fa-envelope mr-2"></i>View Show Cause
                                            </a>
                                        <?php endif; ?>
                                        
                                        <?php if ($hearing_count == 0 && $show_cause_count > 0): ?>
                                            <a class="dropdown-item" href="schedule_hearing.php?incident_id=<?php echo $incident_id; ?>">
                                                <i class="fas fa-fw fa-gavel mr-2"></i>Schedule Hearing
                                            </a>
                                        <?php elseif ($hearing_count > 0): ?>
                                            <a class="dropdown-item" href="view_hearing.php?incident_id=<?php echo $incident_id; ?>">
                                                <i class="fas fa-fw fa-gavel mr-2"></i>View Hearing
                                            </a>
                                        <?php endif; ?>
                                        
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item" href="disciplinary_actions.php?incident_id=<?php echo $incident_id; ?>">
                                            <i class="fas fa-fw fa-balance-scale mr-2"></i>Disciplinary Actions
                                        </a>
                                        <?php if ($status == 'open' || $status == 'under_investigation'): ?>
                                            <div class="dropdown-divider"></div>
                                            <a class="dropdown-item text-danger confirm-link" href="misconduct_dashboard.php?action=close_incident&id=<?php echo $incident_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token'] ?>" data-confirm-message="Are you sure you want to close this misconduct incident?">
                                                <i class="fas fa-fw fa-times mr-2"></i>Close Incident
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
                            <td colspan="7" class="text-center py-4">
                                <i class="fas fa-check-circle fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">No misconduct incidents found</h5>
                                <p class="text-muted">No incidents match your current filters.</p>
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
        </div>

        <!-- Sidebar -->
        <div class="col-md-3">
            <!-- Quick Stats -->
            <div class="card mb-4">
                <div class="card-header bg-info text-white">
                    <h6 class="card-title mb-0"><i class="fas fa-chart-pie mr-2"></i>Misconduct Statistics</h6>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-6 mb-3">
                            <div class="border rounded p-2 bg-white">
                                <h5 class="text-primary mb-0"><?php echo $stats['open_incidents']; ?></h5>
                                <small class="text-muted">Open Incidents</small>
                            </div>
                        </div>
                        <div class="col-6 mb-3">
                            <div class="border rounded p-2 bg-white">
                                <h5 class="text-warning mb-0"><?php echo $stats['ongoing_investigations']; ?></h5>
                                <small class="text-muted">Investigations</small>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="border rounded p-2 bg-white">
                                <h5 class="text-success mb-0"><?php echo $stats['upcoming_hearings']; ?></h5>
                                <small class="text-muted">Upcoming Hearings</small>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="border rounded p-2 bg-white">
                                <h5 class="text-danger mb-0"><?php echo $stats['pending_responses']; ?></h5>
                                <small class="text-muted">Pending Responses</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Actions -->
            <div class="card">
                <div class="card-header bg-warning">
                    <h6 class="card-title mb-0"><i class="fas fa-clock mr-2"></i>Recent Disciplinary Actions</h6>
                </div>
                <div class="card-body p-0">
                    <?php if ($recent_actions->num_rows > 0): ?>
                        <div class="list-group list-group-flush">
                            <?php while ($action = $recent_actions->fetch_assoc()): ?>
                                <div class="list-group-item">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1"><?php echo htmlspecialchars($action['first_name'] . ' ' . $action['last_name']); ?></h6>
                                        <small class="text-muted"><?php echo date('M j', strtotime($action['created_at'])); ?></small>
                                    </div>
                                    <p class="mb-1 small text-capitalize">
                                        <?php echo str_replace('_', ' ', $action['action_type']); ?>
                                    </p>
                                    <small class="text-muted">
                                        Effective: <?php echo date('M j, Y', strtotime($action['effective_date'])); ?>
                                    </small>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-3">
                            <i class="fas fa-balance-scale text-muted mb-2"></i>
                            <p class="text-muted small mb-0">No recent actions</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Ends Card Body -->
    <?php 
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/filter_footer.php';
    ?>
</div>

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
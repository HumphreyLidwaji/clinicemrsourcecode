<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';



// Handle GET actions
if (isset($_GET['delete_ticket'])) {
    deleteTicket();
}

function deleteTicket() {
    global $mysqli, $session_user_id, $session_ip, $session_user_agent;
    
    $ticket_id = intval($_GET['delete_ticket']);
    $csrf_token = sanitizeInput($_GET['csrf_token'] ?? '');
    
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token.";
        header("Location: tickets.php");
        exit;
    }
    
    // Validate ticket exists
    $ticket_sql = "SELECT ticket_number FROM tickets WHERE ticket_id = ?";
    $ticket_stmt = $mysqli->prepare($ticket_sql);
    $ticket_stmt->bind_param("i", $ticket_id);
    $ticket_stmt->execute();
    $ticket_result = $ticket_stmt->get_result();
    
    if ($ticket_result->num_rows === 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Ticket not found.";
        header("Location: tickets.php");
        exit;
    }
    
    $ticket = $ticket_result->fetch_assoc();
    $ticket_stmt->close();
    
    $mysqli->begin_transaction();
    
    try {
        // Delete ticket comments first
        $delete_comments_sql = "DELETE FROM ticket_comments WHERE ticket_id = ?";
        $delete_comments_stmt = $mysqli->prepare($delete_comments_sql);
        $delete_comments_stmt->bind_param("i", $ticket_id);
        $delete_comments_stmt->execute();
        $delete_comments_stmt->close();
        
        // Delete ticket
        $delete_sql = "DELETE FROM tickets WHERE ticket_id = ?";
        $delete_stmt = $mysqli->prepare($delete_sql);
        $delete_stmt->bind_param("i", $ticket_id);
        
        if (!$delete_stmt->execute()) {
            throw new Exception("Failed to delete ticket: " . $delete_stmt->error);
        }
        $delete_stmt->close();
        
        // Log the action
        $log_sql = "INSERT INTO logs SET
                  log_type = 'Tickets',
                  log_action = 'Delete',
                  log_description = ?,
                  log_ip = ?,
                  log_user_agent = ?,
                  log_user_id = ?,
                  log_entity_id = ?,
                  log_created_at = NOW()";
        $log_stmt = $mysqli->prepare($log_sql);
        $log_description = "Deleted ticket: " . $ticket['ticket_number'];
        $log_stmt->bind_param("sssii", $log_description, $session_ip, $session_user_agent, $session_user_id, $ticket_id);
        $log_stmt->execute();
        $log_stmt->close();
        
        $mysqli->commit();
        
        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Ticket deleted successfully!";
        header("Location: tickets.php");
        exit;
        
    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = $e->getMessage();
        header("Location: tickets.php");
        exit;
    }
}

// Default Column Sortby/Order Filter
$sort = sanitizeInput($_GET['sort'] ?? "created_at");
$order = sanitizeInput($_GET['order'] ?? "DESC");

// Validate sort column
$allowed_sorts = ['ticket_number', 'ticket_title', 'ticket_priority', 'ticket_status', 'created_at', 'user_name', 'assigned_name'];
if (!in_array($sort, $allowed_sorts)) {
    $sort = 'created_at';
}

// Validate order
$order = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';

// Search Query
$q = sanitizeInput($_GET['q'] ?? '');
if (!empty($q)) {
    $search_query = "AND (
        t.ticket_number LIKE '%$q%' 
        OR t.ticket_title LIKE '%$q%'
        OR t.ticket_description LIKE '%$q%'
        OR u.user_name LIKE '%$q%'
        OR a.user_name LIKE '%$q%'
    )";
} else {
    $search_query = '';
}

// Status Filter
$status_filter = sanitizeInput($_GET['status'] ?? '');
if ($status_filter) {
    $status_query = "AND t.ticket_status = '$status_filter'";
} else {
    $status_query = '';
}

// Priority Filter
$priority_filter = sanitizeInput($_GET['priority'] ?? '');
if ($priority_filter) {
    $priority_query = "AND t.ticket_priority = '$priority_filter'";
} else {
    $priority_query = '';
}

// Category Filter
$category_filter = sanitizeInput($_GET['category'] ?? '');
if ($category_filter) {
    $category_query = "AND t.ticket_category = '$category_filter'";
} else {
    $category_query = '';
}

// Assigned To Filter
$assigned_filter = sanitizeInput($_GET['assigned'] ?? '');
if ($assigned_filter === 'me') {
    $assigned_query = "AND t.assigned_to = $session_user_id";
} elseif ($assigned_filter === 'unassigned') {
    $assigned_query = "AND (t.assigned_to = 0 OR t.assigned_to IS NULL)";
} elseif ($assigned_filter) {
    $assigned_query = "AND t.assigned_to = '$assigned_filter'";
} else {
    $assigned_query = '';
}

// My Tickets Filter
$my_tickets = isset($_GET['my_tickets']) ? true : false;
if ($my_tickets) {
    $my_tickets_query = "AND (t.user_id = $session_user_id OR t.assigned_to = $session_user_id)";
} else {
    $my_tickets_query = '';
}

// Get all tickets with user information
$tickets_sql = "
    SELECT SQL_CALC_FOUND_ROWS 
           t.*,
           u.user_name,
           u.user_email,
           a.user_name as assigned_name,
           a.user_email as assigned_email,
           COUNT(DISTINCT tc.comment_id) as comment_count,
           DATEDIFF(NOW(), t.created_at) as days_open
    FROM tickets t
    LEFT JOIN users u ON t.user_id = u.user_id
    LEFT JOIN users a ON t.assigned_to = a.user_id
    LEFT JOIN ticket_comments tc ON t.ticket_id = tc.ticket_id
    WHERE 1=1
      $search_query
      $status_query
      $priority_query
      $category_query
      $assigned_query
      $my_tickets_query
    GROUP BY t.ticket_id
    ORDER BY $sort $order
    LIMIT $record_from, $record_to";

$tickets_result = $mysqli->query($tickets_sql);
if (!$tickets_result) {
    die("Query failed: " . $mysqli->error);
}

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get ticket statistics - FIXED: Changed 'high_priority' alias to 'high_priority_count'
$stats_sql = "SELECT 
                COUNT(*) as total_tickets,
                SUM(CASE WHEN ticket_status = 'open' THEN 1 ELSE 0 END) as open_tickets,
                SUM(CASE WHEN ticket_status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_tickets,
                SUM(CASE WHEN ticket_status = 'closed' THEN 1 ELSE 0 END) as closed_tickets,
                SUM(CASE WHEN ticket_priority = 'high' THEN 1 ELSE 0 END) as high_priority_count,
                SUM(CASE WHEN ticket_priority = 'medium' THEN 1 ELSE 0 END) as medium_priority_count,
                SUM(CASE WHEN ticket_priority = 'low' THEN 1 ELSE 0 END) as low_priority_count
              FROM tickets";
$stats_result = $mysqli->query($stats_sql);
$stats = $stats_result->fetch_assoc();

// Get ticket categories
$categories_sql = $mysqli->query("SELECT DISTINCT ticket_category FROM tickets WHERE ticket_category IS NOT NULL AND ticket_category != '' ORDER BY ticket_category");
$categories = [];
while ($cat = $categories_sql->fetch_assoc()) {
    $categories[] = $cat['ticket_category'];
}

// Get IT support users (users with ticket permissions)
$support_users_sql = "SELECT user_id, user_name, user_email FROM users ";
$support_users_result = $mysqli->query($support_users_sql);
$support_users = [];
while ($user = $support_users_result->fetch_assoc()) {
    $support_users[] = $user;
}

// Get all users for ticket creation
$all_users_sql = "SELECT user_id, user_name, user_email FROM users ";
$all_users_result = $mysqli->query($all_users_sql);
$all_users = [];
while ($user = $all_users_result->fetch_assoc()) {
    $all_users[] = $user;
}
?>

<div class="card">

<div class="card-header bg-primary py-2">
    <div class="d-flex justify-content-between align-items-center">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-ticket-alt mr-2"></i>
            IT Support Tickets
        </h3>
        <div class="card-tools">
            <a href="ticket_new.php" class="btn btn-success mr-2">
                <i class="fas fa-plus mr-2"></i>New Ticket
            </a>
            <a href="ticket_reports.php" class="btn btn-info">
                <i class="fas fa-chart-bar mr-2"></i>Reports
            </a>
        </div>
    </div>
</div>

    <div class="card-header pb-2 pt-3">
        <form autocomplete="off">
            <input type="hidden" name="sort" value="<?php echo $sort; ?>">
            <input type="hidden" name="order" value="<?php echo $order; ?>">
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Search tickets, titles, descriptions, users..." autofocus>
                            <div class="input-group-append">
                                <button class="btn btn-secondary" type="button" data-toggle="collapse" data-target="#advancedFilter"><i class="fas fa-filter"></i></button>
                                <button class="btn btn-primary"><i class="fa fa-search"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="btn-toolbar form-group float-right">
                        <div class="btn-group">
                            <span class="btn btn-light border">
                                <i class="fas fa-ticket-alt text-primary mr-1"></i>
                                Total: <strong><?php echo $stats['total_tickets'] ?? 0; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-clock text-warning mr-1"></i>
                                Open: <strong><?php echo $stats['open_tickets'] ?? 0; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-cog text-info mr-1"></i>
                                In Progress: <strong><?php echo $stats['in_progress_tickets'] ?? 0; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-check-circle text-success mr-1"></i>
                                Closed: <strong><?php echo $stats['closed_tickets'] ?? 0; ?></strong>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php if ($status_filter || $priority_filter || $category_filter || $assigned_filter || !empty($q)) { echo "show"; } ?>" id="advancedFilter">
                <div class="row">
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Status</label>
                            <select class="form-control select2" name="status" onchange="this.form.submit()">
                                <option value="">- All Statuses -</option>
                                <option value="open" <?php if ($status_filter == 'open') { echo "selected"; } ?>>Open</option>
                                <option value="in_progress" <?php if ($status_filter == 'in_progress') { echo "selected"; } ?>>In Progress</option>
                                <option value="on_hold" <?php if ($status_filter == 'on_hold') { echo "selected"; } ?>>On Hold</option>
                                <option value="closed" <?php if ($status_filter == 'closed') { echo "selected"; } ?>>Closed</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Priority</label>
                            <select class="form-control select2" name="priority" onchange="this.form.submit()">
                                <option value="">- All Priorities -</option>
                                <option value="low" <?php if ($priority_filter == 'low') { echo "selected"; } ?>>Low</option>
                                <option value="medium" <?php if ($priority_filter == 'medium') { echo "selected"; } ?>>Medium</option>
                                <option value="high" <?php if ($priority_filter == 'high') { echo "selected"; } ?>>High</option>
                                <option value="urgent" <?php if ($priority_filter == 'urgent') { echo "selected"; } ?>>Urgent</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Category</label>
                            <select class="form-control select2" name="category" onchange="this.form.submit()">
                                <option value="">- All Categories -</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo htmlspecialchars($category); ?>" <?php if ($category_filter == $category) { echo "selected"; } ?>>
                                        <?php echo htmlspecialchars($category); ?>
                                    </option>
                                <?php endforeach; ?>
                                <?php if (empty($categories)): ?>
                                    <option value="">No categories found</option>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Assigned To</label>
                            <select class="form-control select2" name="assigned" onchange="this.form.submit()">
                                <option value="">- All Assignments -</option>
                                <option value="me" <?php if ($assigned_filter == 'me') { echo "selected"; } ?>>Assigned to Me</option>
                                <option value="unassigned" <?php if ($assigned_filter == 'unassigned') { echo "selected"; } ?>>Unassigned</option>
                                <?php foreach ($support_users as $user): ?>
                                    <option value="<?php echo $user['user_id']; ?>" <?php if ($assigned_filter == $user['user_id']) { echo "selected"; } ?>>
                                        <?php echo htmlspecialchars($user['user_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Sort By</label>
                            <select class="form-control select2" name="sort" onchange="this.form.submit()">
                                <option value="created_at" data-order="DESC" <?php if ($sort == "created_at" && $order == "DESC") { echo "selected"; } ?>>Newest First</option>
                                <option value="created_at" data-order="ASC" <?php if ($sort == "created_at" && $order == "ASC") { echo "selected"; } ?>>Oldest First</option>
                                <option value="ticket_priority" data-order="DESC" <?php if ($sort == "ticket_priority" && $order == "DESC") { echo "selected"; } ?>>Priority (High to Low)</option>
                                <option value="ticket_priority" data-order="ASC" <?php if ($sort == "ticket_priority" && $order == "ASC") { echo "selected"; } ?>>Priority (Low to High)</option>
                                <option value="ticket_status" data-order="ASC" <?php if ($sort == "ticket_status" && $order == "ASC") { echo "selected"; } ?>>Status (A-Z)</option>
                                <option value="ticket_title" data-order="ASC" <?php if ($sort == "ticket_title" && $order == "ASC") { echo "selected"; } ?>>Title (A-Z)</option>
                                <option value="ticket_title" data-order="DESC" <?php if ($sort == "ticket_title" && $order == "DESC") { echo "selected"; } ?>>Title (Z-A)</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Quick Filters</label>
                            <div class="btn-group btn-block">
                                <a href="?my_tickets=1" class="btn btn-<?php echo $my_tickets ? 'primary' : 'outline-primary'; ?>">
                                    <i class="fas fa-user mr-2"></i>My Tickets
                                </a>
                                <a href="?status=open&priority=high" class="btn btn-outline-danger">
                                    <i class="fas fa-exclamation-triangle mr-2"></i>High Priority
                                </a>
                                <a href="?assigned=unassigned" class="btn btn-outline-warning">
                                    <i class="fas fa-question-circle mr-2"></i>Unassigned
                                </a>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Quick Actions</label>
                            <div class="btn-group btn-block">
                                <a href="tickets.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times mr-2"></i>Clear Filters
                                </a>
                                <a href="ticket_new.php" class="btn btn-success">
                                    <i class="fas fa-plus mr-2"></i>New Ticket
                                </a>
                                <a href="tickets_export.php" class="btn btn-info">
                                    <i class="fas fa-download mr-2"></i>Export
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <div class="card-body">
        <?php if (isset($_SESSION['alert_message'])): ?>
            <div class="alert alert-<?php echo $_SESSION['alert_type']; ?> alert-dismissible">
                <button type="button" class="close" data-dismiss="alert">&times;</button>
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
            <div class="col-lg-3 col-6">
                <div class="small-box bg-warning">
                    <div class="inner">
                        <h3><?php echo $stats['open_tickets'] ?? 0; ?></h3>
                        <p>Open Tickets</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-clock"></i>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-3 col-6">
                <div class="small-box bg-info">
                    <div class="inner">
                        <h3><?php echo $stats['in_progress_tickets'] ?? 0; ?></h3>
                        <p>In Progress</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-cog"></i>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-3 col-6">
                <div class="small-box bg-success">
                    <div class="inner">
                        <h3><?php echo $stats['closed_tickets'] ?? 0; ?></h3>
                        <p>Closed</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-3 col-6">
                <div class="small-box bg-danger">
                    <div class="inner">
                        <h3><?php echo $stats['high_priority_count'] ?? 0; ?></h3>
                        <p>High Priority</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="table-responsive-sm">
            <table class="table table-hover mb-0">
                <thead class="<?php if ($num_rows[0] == 0) { echo "d-none"; } ?> bg-light">
                <tr>
                    <th style="width: 120px;">
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=ticket_number&order=<?php echo $disp; ?>">
                            Ticket # <?php if ($sort == 'ticket_number') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=ticket_title&order=<?php echo $disp; ?>">
                            Title <?php if ($sort == 'ticket_title') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th style="width: 100px;">Priority</th>
                    <th style="width: 120px;">Category</th>
                    <th style="width: 100px;">Status</th>
                    <th>Created By</th>
                    <th>Assigned To</th>
                    <th style="width: 140px;">
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=created_at&order=<?php echo $disp; ?>">
                            Created <?php if ($sort == 'created_at') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th style="width: 90px;" class="text-center">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($tickets_result->num_rows > 0): ?>
                    <?php while ($ticket = $tickets_result->fetch_assoc()): ?>
                        <?php
                        // Priority styling
                        $priority_class = 'secondary';
                        $priority_icon = 'circle';
                        switch ($ticket['ticket_priority']) {
                            case 'low':
                                $priority_class = 'info';
                                $priority_icon = 'arrow-down';
                                break;
                            case 'medium':
                                $priority_class = 'warning';
                                $priority_icon = 'minus';
                                break;
                            case 'high':
                                $priority_class = 'danger';
                                $priority_icon = 'arrow-up';
                                break;
                            case 'urgent':
                                $priority_class = 'danger';
                                $priority_icon = 'exclamation-triangle';
                                break;
                        }
                        
                        // Status styling
                        $status_class = 'secondary';
                        $status_icon = 'circle';
                        switch ($ticket['ticket_status']) {
                            case 'open':
                                $status_class = 'warning';
                                $status_icon = 'clock';
                                break;
                            case 'in_progress':
                                $status_class = 'info';
                                $status_icon = 'cog';
                                break;
                            case 'on_hold':
                                $status_class = 'secondary';
                                $status_icon = 'pause';
                                break;
                            case 'closed':
                                $status_class = 'success';
                                $status_icon = 'check-circle';
                                break;
                        }
                        
                        // Days open styling
                        $days_class = '';
                        if ($ticket['days_open'] > 7 && $ticket['ticket_status'] != 'closed') {
                            $days_class = 'text-danger font-weight-bold';
                        } elseif ($ticket['days_open'] > 3 && $ticket['ticket_status'] != 'closed') {
                            $days_class = 'text-warning';
                        }
                        ?>
                        <tr>
                            <td>
                                <a href="ticket_view.php?id=<?php echo $ticket['ticket_id']; ?>">
                                    <strong class="text-primary"><?php echo htmlspecialchars($ticket['ticket_number']); ?></strong>
                                </a>
                                <?php if ($ticket['comment_count'] > 0): ?>
                                    <br>
                                    <small class="text-muted">
                                        <i class="fas fa-comment"></i> <?php echo $ticket['comment_count']; ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="font-weight-bold"><?php echo htmlspecialchars($ticket['ticket_title']); ?></div>
                                <small class="text-muted">
                                    <?php echo strlen($ticket['ticket_description']) > 80 ? substr($ticket['ticket_description'], 0, 80) . '...' : $ticket['ticket_description']; ?>
                                </small>
                            </td>
                            <td>
                                <span class="badge badge-<?php echo $priority_class; ?>">
                                    <i class="fas fa-<?php echo $priority_icon; ?> mr-1"></i>
                                    <?php echo ucfirst($ticket['ticket_priority']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge badge-secondary"><?php echo htmlspecialchars($ticket['ticket_category']); ?></span>
                            </td>
                            <td>
                                <span class="badge badge-<?php echo $status_class; ?>">
                                    <i class="fas fa-<?php echo $status_icon; ?> mr-1"></i>
                                    <?php echo ucwords(str_replace('_', ' ', $ticket['ticket_status'])); ?>
                                </span>
                                <?php if ($ticket['days_open'] > 0 && $ticket['ticket_status'] != 'closed'): ?>
                                    <br>
                                    <small class="<?php echo $days_class; ?>">
                                        <?php echo $ticket['days_open']; ?> day<?php echo $ticket['days_open'] != 1 ? 's' : ''; ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div><?php echo htmlspecialchars($ticket['user_name']); ?></div>
                                <small class="text-muted"><?php echo htmlspecialchars($ticket['user_email']); ?></small>
                            </td>
                            <td>
                                <?php if ($ticket['assigned_name']): ?>
                                    <div><?php echo htmlspecialchars($ticket['assigned_name']); ?></div>
                                    <small class="text-muted"><?php echo htmlspecialchars($ticket['assigned_email']); ?></small>
                                <?php else: ?>
                                    <span class="text-muted"><em>Unassigned</em></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div><?php echo date('M j, Y', strtotime($ticket['created_at'])); ?></div>
                                <small class="text-muted"><?php echo date('H:i', strtotime($ticket['created_at'])); ?></small>
                            </td>
                            <td>
                                <div class="dropdown dropleft text-center">
                                    <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                        <i class="fas fa-ellipsis-h"></i>
                                    </button>
                                    <div class="dropdown-menu">
                                        <a class="dropdown-item" href="ticket_view.php?id=<?php echo $ticket['ticket_id']; ?>">
                                            <i class="fas fa-fw fa-eye mr-2"></i>View Details
                                        </a>
                                        <a class="dropdown-item" href="ticket_edit.php?id=<?php echo $ticket['ticket_id']; ?>">
                                            <i class="fas fa-fw fa-edit mr-2"></i>Edit Ticket
                                        </a>
                                        <a class="dropdown-item" href="ticket_assign.php?id=<?php echo $ticket['ticket_id']; ?>">
                                            <i class="fas fa-fw fa-user-plus mr-2"></i>Assign
                                        </a>
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item" href="ticket_comment.php?id=<?php echo $ticket['ticket_id']; ?>">
                                            <i class="fas fa-fw fa-comment mr-2"></i>Add Comment
                                        </a>
                                        <?php if ($ticket['ticket_status'] != 'closed'): ?>
                                            <a class="dropdown-item" href="ticket_close.php?id=<?php echo $ticket['ticket_id']; ?>">
                                                <i class="fas fa-fw fa-check-circle mr-2"></i>Close Ticket
                                            </a>
                                        <?php else: ?>
                                            <a class="dropdown-item" href="ticket_reopen.php?id=<?php echo $ticket['ticket_id']; ?>">
                                                <i class="fas fa-fw fa-redo mr-2"></i>Reopen
                                            </a>
                                        <?php endif; ?>
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item text-danger confirm-link" href="?delete_ticket=<?php echo $ticket['ticket_id']; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>">
                                            <i class="fas fa-fw fa-trash mr-2"></i>Delete
                                        </a>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="9" class="text-center py-4">
                            <i class="fas fa-ticket-alt fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">No Tickets Found</h5>
                            <p class="text-muted">Get started by creating your first support ticket.</p>
                            <a href="ticket_new.php" class="btn btn-primary">
                                <i class="fas fa-plus mr-2"></i>Create First Ticket
                            </a>
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

<script>
$(document).ready(function() {
    $('.select2').select2({
        theme: 'bootstrap4'
    });

    // Confirm before deleting
    $('.confirm-link').on('click', function(e) {
        if (!confirm('Are you sure you want to delete this ticket? This action cannot be undone.')) {
            e.preventDefault();
        }
    });

    // Handle sort selection
    $('select[name="sort"]').on('change', function() {
        var selected = $(this).find('option:selected');
        var sort = selected.val();
        var order = selected.data('order');
        
        // Update hidden fields
        $('input[name="sort"]').val(sort);
        $('input[name="order"]').val(order);
    });
});

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + N for new ticket
    if (e.ctrlKey && e.keyCode === 78) {
        e.preventDefault();
        window.location.href = 'ticket_new.php';
    }
    // Ctrl + F for focus search
    if (e.ctrlKey && e.keyCode === 70) {
        e.preventDefault();
        $('input[name="q"]').focus();
    }
    // Ctrl + R for refresh
    if (e.ctrlKey && e.keyCode === 82) {
        e.preventDefault();
        location.reload();
    }
});
</script>

<style>
.small-box {
    border-radius: 0.25rem;
    box-shadow: 0 0 1px rgba(0,0,0,.125), 0 1px 3px rgba(0,0,0,.2);
    display: block;
    margin-bottom: 20px;
    position: relative;
}

.small-box > .inner {
    padding: 10px;
}

.small-box .icon {
    position: absolute;
    top: -10px;
    right: 10px;
    z-index: 0;
    font-size: 70px;
    color: rgba(0,0,0,0.15);
    transition: all .3s linear;
}

.small-box:hover .icon {
    font-size: 75px;
}

.table th {
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.85em;
    letter-spacing: 0.5px;
}

.table tbody tr:hover {
    background-color: #f8f9fa;
    cursor: pointer;
}

.badge {
    font-size: 0.85em;
    padding: 5px 10px;
}

.select2-container--bootstrap4 .select2-selection--single {
    height: calc(2.25rem + 2px);
}

.select2-container--bootstrap4 .select2-selection--single .select2-selection__rendered {
    line-height: 2.25rem;
}
</style>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
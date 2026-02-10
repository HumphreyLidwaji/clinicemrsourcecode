<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Default Column Sortby/Order Filter
$sort = "r.requisition_date";
$order = "DESC";

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Filter parameters
$status_filter = $_GET['status'] ?? '';
$priority_filter = $_GET['priority'] ?? '';
$from_location_filter = $_GET['from_location'] ?? '';
$delivery_location_filter = $_GET['delivery_location'] ?? '';
$requester_filter = $_GET['requester'] ?? '';

// Date Range Filter
$dtf = sanitizeInput($_GET['dtf'] ?? '');
$dtt = sanitizeInput($_GET['dtt'] ?? '');

if (!empty($dtf) && !empty($dtt)) {
    $date_query = "AND DATE(r.requisition_date) BETWEEN '$dtf' AND '$dtt'";
} else {
    $date_query = '';
}

// Status Filter
if ($status_filter) {
    $status_query = "AND r.status = '" . sanitizeInput($status_filter) . "'";
} else {
    $status_query = '';
}

// Priority Filter
if ($priority_filter) {
    $priority_query = "AND r.priority = '" . sanitizeInput($priority_filter) . "'";
} else {
    $priority_query = '';
}

// From Location Filter
if ($from_location_filter) {
    $from_location_query = "AND r.from_location_id = " . intval($from_location_filter);
} else {
    $from_location_query = '';
}

// Delivery Location Filter
if ($delivery_location_filter) {
    $delivery_location_query = "AND r.delivery_location_id = " . intval($delivery_location_filter);
} else {
    $delivery_location_query = '';
}

// Requester Filter
if ($requester_filter) {
    $requester_query = "AND r.requested_by = " . intval($requester_filter);
} else {
    $requester_query = '';
}

// Search Query
$q = sanitizeInput($_GET['q'] ?? '');
if (!empty($q)) {
    $search_query = "AND (
        r.requisition_number LIKE '%$q%' 
        OR r.notes LIKE '%$q%'
        OR u.user_name LIKE '%$q%'
        OR u.user_name LIKE '%$q%'
        OR fl.location_name LIKE '%$q%'
        OR dl.location_name LIKE '%$q%'
    )";
} else {
    $search_query = '';
}

// Main query for requisitions
$sql = mysqli_query(
    $mysqli,
    "
    SELECT SQL_CALC_FOUND_ROWS 
        r.*,
        u.user_id,
        u.user_name,
        u.user_name as requester_full_name,
        fl.location_id as from_location_id,
        fl.location_name as from_location_name,
        fl.location_type as from_location_type,
        dl.location_id as delivery_location_id,
        dl.location_name as delivery_location_name,
        dl.location_type as delivery_location_type,
        a.user_name as approver_name,
        f.user_name as fulfiller_name,
        (SELECT COUNT(*) FROM inventory_requisition_items ri WHERE ri.requisition_id = r.requisition_id AND ri.is_active = 1) as item_count,
        (SELECT SUM(ri.quantity_requested) FROM inventory_requisition_items ri WHERE ri.requisition_id = r.requisition_id AND ri.is_active = 1) as total_requested,
        (SELECT SUM(ri.quantity_approved) FROM inventory_requisition_items ri WHERE ri.requisition_id = r.requisition_id AND ri.is_active = 1) as total_approved,
        (SELECT SUM(ri.quantity_issued) FROM inventory_requisition_items ri WHERE ri.requisition_id = r.requisition_id AND ri.is_active = 1) as total_issued
    FROM inventory_requisitions r
    LEFT JOIN users u ON r.requested_by = u.user_id
    LEFT JOIN users a ON r.approved_by = a.user_id
    LEFT JOIN users f ON r.fulfilled_by = f.user_id
    LEFT JOIN inventory_locations fl ON r.from_location_id = fl.location_id
    LEFT JOIN inventory_locations dl ON r.delivery_location_id = dl.location_id
    WHERE r.is_active = 1
      $status_query
      $priority_query
      $from_location_query
      $delivery_location_query
      $requester_query
      $date_query
      $search_query
    ORDER BY 
        CASE 
            WHEN r.priority = 'urgent' THEN 1
            WHEN r.priority = 'high' THEN 2
            WHEN r.priority = 'normal' THEN 3
            WHEN r.priority = 'low' THEN 4
            ELSE 5
        END,
        $sort $order
    LIMIT $record_from, $record_to
");

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get statistics
$stats_sql = mysqli_query($mysqli, "
    SELECT 
        COUNT(*) as total_requisitions,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_count,
        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_count,
        SUM(CASE WHEN status = 'fulfilled' THEN 1 ELSE 0 END) as fulfilled_count,
        SUM(CASE WHEN status = 'partial' THEN 1 ELSE 0 END) as partial_count,
        SUM(CASE WHEN priority = 'urgent' THEN 1 ELSE 0 END) as urgent_count
    FROM inventory_requisitions
    WHERE is_active = 1
      $status_query
      $priority_query
      $from_location_query
      $delivery_location_query
      $requester_query
      $date_query
");
$stats = mysqli_fetch_assoc($stats_sql);

// Get locations for filter
$locations_sql = mysqli_query($mysqli, "SELECT location_id, location_name, location_type FROM inventory_locations WHERE is_active = 1 ORDER BY location_name");

// Get requesters for filter
$requesters_sql = mysqli_query($mysqli, "SELECT user_id, user_name FROM users WHERE user_status = 1 ");
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0"><i class="fas fa-fw fa-clipboard-list mr-2"></i>Inventory Requisitions</h3>
        <div class="card-tools">
            <a href="inventory_requisition_create.php" class="btn btn-success">
                <i class="fas fa-plus mr-2"></i>New Requisition
            </a>
        </div>
    </div>

    <div class="card-header pb-2 pt-3">
        <form autocomplete="off">
            <div class="row">
                <div class="col-md-5">
                    <div class="form-group">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search requisitions, notes, locations..." autofocus>
                            <div class="input-group-append">
                                <button class="btn btn-secondary" type="button" data-toggle="collapse" data-target="#advancedFilter"><i class="fas fa-filter"></i></button>
                                <button class="btn btn-primary"><i class="fa fa-search"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-7">
                    <div class="btn-toolbar form-group float-right">
                        <div class="btn-group">
                            <span class="btn btn-light border">
                                <i class="fas fa-clipboard-list text-primary mr-1"></i>
                                Total: <strong><?php echo $stats['total_requisitions']; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-clock text-warning mr-1"></i>
                                Pending: <strong><?php echo $stats['pending_count']; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-check text-success mr-1"></i>
                                Approved: <strong><?php echo $stats['approved_count']; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-exclamation-triangle text-danger mr-1"></i>
                                Urgent: <strong><?php echo $stats['urgent_count']; ?></strong>
                            </span>
                            <a href="inventory_items.php" class="btn btn-info ml-2">
                                <i class="fas fa-fw fa-boxes mr-2"></i>View Items
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php if ($status_filter || $priority_filter || $from_location_filter || $delivery_location_filter || $requester_filter || isset($_GET['dtf'])) { echo "show"; } ?>" id="advancedFilter">
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
                                <option value="">- All Statuses -</option>
                                <option value="pending" <?php if ($status_filter == "pending") { echo "selected"; } ?>>Pending</option>
                                <option value="approved" <?php if ($status_filter == "approved") { echo "selected"; } ?>>Approved</option>
                                <option value="rejected" <?php if ($status_filter == "rejected") { echo "selected"; } ?>>Rejected</option>
                                <option value="fulfilled" <?php if ($status_filter == "fulfilled") { echo "selected"; } ?>>Fulfilled</option>
                                <option value="partial" <?php if ($status_filter == "partial") { echo "selected"; } ?>>Partial</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Priority</label>
                            <select class="form-control select2" name="priority" onchange="this.form.submit()">
                                <option value="">- All Priorities -</option>
                                <option value="urgent" <?php if ($priority_filter == "urgent") { echo "selected"; } ?>>Urgent</option>
                                <option value="high" <?php if ($priority_filter == "high") { echo "selected"; } ?>>High</option>
                                <option value="normal" <?php if ($priority_filter == "normal") { echo "selected"; } ?>>Normal</option>
                                <option value="low" <?php if ($priority_filter == "low") { echo "selected"; } ?>>Low</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Quick Actions</label>
                            <div class="btn-group btn-block">
                                <a href="inventory_requisitions.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times mr-2"></i>Clear Filters
                                </a>
                                <a href="inventory_requisition_create.php" class="btn btn-success">
                                    <i class="fas fa-plus mr-2"></i>New Requisition
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row mt-2">
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>From Location</label>
                            <select class="form-control select2" name="from_location" onchange="this.form.submit()">
                                <option value="">- All From Locations -</option>
                                <?php while($location = mysqli_fetch_assoc($locations_sql)): ?>
                                    <option value="<?php echo $location['location_id']; ?>" <?php if ($from_location_filter == $location['location_id']) { echo "selected"; } ?>>
                                        <?php echo htmlspecialchars($location['location_name']); ?> (<?php echo $location['location_type']; ?>)
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Delivery Location</label>
                            <select class="form-control select2" name="delivery_location" onchange="this.form.submit()">
                                <option value="">- All Delivery Locations -</option>
                                <?php 
                                // Reset pointer for locations query
                                mysqli_data_seek($locations_sql, 0);
                                while($location = mysqli_fetch_assoc($locations_sql)): ?>
                                    <option value="<?php echo $location['location_id']; ?>" <?php if ($delivery_location_filter == $location['location_id']) { echo "selected"; } ?>>
                                        <?php echo htmlspecialchars($location['location_name']); ?> (<?php echo $location['location_type']; ?>)
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Requester</label>
                            <select class="form-control select2" name="requester" onchange="this.form.submit()">
                                <option value="">- All Requesters -</option>
                                <?php while($requester = mysqli_fetch_assoc($requesters_sql)): ?>
                                    <option value="<?php echo $requester['user_id']; ?>" <?php if ($requester_filter == $requester['user_id']) { echo "selected"; } ?>>
                                        <?php echo htmlspecialchars($requester['user_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Action Buttons</label>
                            <div class="d-grid gap-2">
                                <button type="button" class="btn btn-outline-info" onclick="exportRequisitions()">
                                    <i class="fas fa-download mr-2"></i>Export CSV
                                </button>
                            </div>
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
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=r.requisition_number&order=<?php echo $disp; ?>">
                        Requisition # <?php if ($sort == 'r.requisition_number') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=r.requisition_date&order=<?php echo $disp; ?>">
                        Date <?php if ($sort == 'r.requisition_date') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=u.full_name&order=<?php echo $disp; ?>">
                        Requester <?php if ($sort == 'u.full_name' || $sort == 'u.user_name') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>Location Flow</th>
                <th class="text-center">
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=item_count&order=<?php echo $disp; ?>">
                        Items <?php if ($sort == 'item_count') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=r.priority&order=<?php echo $disp; ?>">
                        Priority <?php if ($sort == 'r.priority') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=r.status&order=<?php echo $disp; ?>">
                        Status <?php if ($sort == 'r.status') { echo $order_icon; } ?>
                    </a>
                </th>
                <th class="text-center">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php while ($row = mysqli_fetch_array($sql)) {
                $requisition_id = intval($row['requisition_id']);
                $requisition_number = nullable_htmlentities($row['requisition_number']);
                $requisition_date = nullable_htmlentities($row['requisition_date']);
                $requester_name = nullable_htmlentities($row['requester_full_name'] ?: $row['user_name']);
                $from_location_name = nullable_htmlentities($row['from_location_name']);
                $from_location_type = nullable_htmlentities($row['from_location_type']);
                $delivery_location_name = nullable_htmlentities($row['delivery_location_name']);
                $delivery_location_type = nullable_htmlentities($row['delivery_location_type']);
                $priority = nullable_htmlentities($row['priority']);
                $status = nullable_htmlentities($row['status']);
                $item_count = intval($row['item_count']);
                $total_requested = floatval($row['total_requested']);
                $total_approved = floatval($row['total_approved']);
                $total_issued = floatval($row['total_issued']);
                $notes = nullable_htmlentities($row['notes']);
                $approved_at = nullable_htmlentities($row['approved_at']);
                $fulfilled_at = nullable_htmlentities($row['fulfilled_at']);

                // Priority badge styling
                $priority_badge = "";
                switch($priority) {
                    case 'urgent':
                        $priority_badge = "badge-danger";
                        $priority_icon = "fas fa-exclamation-triangle";
                        break;
                    case 'high':
                        $priority_badge = "badge-warning";
                        $priority_icon = "fas fa-exclamation-circle";
                        break;
                    case 'normal':
                        $priority_badge = "badge-primary";
                        $priority_icon = "fas fa-flag";
                        break;
                    case 'low':
                        $priority_badge = "badge-secondary";
                        $priority_icon = "fas fa-flag";
                        break;
                    default:
                        $priority_badge = "badge-light";
                        $priority_icon = "fas fa-flag";
                }

                // Status badge styling
                $status_badge = "";
                $status_icon = "";
                switch($status) {
                    case 'pending':
                        $status_badge = "badge-warning";
                        $status_icon = "fas fa-clock";
                        break;
                    case 'approved':
                        $status_badge = "badge-success";
                        $status_icon = "fas fa-check";
                        break;
                    case 'rejected':
                        $status_badge = "badge-danger";
                        $status_icon = "fas fa-times";
                        break;
                    case 'fulfilled':
                        $status_badge = "badge-info";
                        $status_icon = "fas fa-check-double";
                        break;
                    case 'partial':
                        $status_badge = "badge-primary";
                        $status_icon = "fas fa-tasks";
                        break;
                    default:
                        $status_badge = "badge-light";
                        $status_icon = "fas fa-question";
                }
                ?>
                <tr>
                    <td>
                        <div class="font-weight-bold text-primary">
                            <i class="fas fa-file-alt text-muted mr-1"></i>
                            <?php echo $requisition_number; ?>
                        </div>
                        <?php if (!empty($notes)): ?>
                            <small class="text-muted" data-toggle="tooltip" title="<?php echo htmlspecialchars($notes); ?>">
                                <i class="fas fa-sticky-note mr-1"></i>
                                <?php echo strlen($notes) > 30 ? substr($notes, 0, 30) . '...' : $notes; ?>
                            </small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="font-weight-bold"><?php echo date('M j, Y', strtotime($requisition_date)); ?></div>
                        <?php if ($approved_at): ?>
                            <small class="text-success d-block">
                                <i class="fas fa-check mr-1"></i>Approved: <?php echo date('M j', strtotime($approved_at)); ?>
                            </small>
                        <?php endif; ?>
                        <?php if ($fulfilled_at): ?>
                            <small class="text-info d-block">
                                <i class="fas fa-truck mr-1"></i>Fulfilled: <?php echo date('M j', strtotime($fulfilled_at)); ?>
                            </small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="font-weight-bold"><?php echo $requester_name; ?></div>
                        <small class="text-muted">
                            <?php if ($row['department_id']): ?>
                                <i class="fas fa-building mr-1"></i>Dept: <?php echo $row['department_id']; ?>
                            <?php endif; ?>
                        </small>
                    </td>
                    <td>
                        <div class="small">
                            <div class="text-muted mb-1">
                                <i class="fas fa-arrow-right text-success"></i>
                                <span class="font-weight-bold">From:</span> 
                                <?php echo $from_location_name ? htmlspecialchars($from_location_name) . ' (' . htmlspecialchars($from_location_type) . ')' : '—'; ?>
                            </div>
                            <div>
                                <i class="fas fa-arrow-right text-primary"></i>
                                <span class="font-weight-bold">To:</span> 
                                <?php echo $delivery_location_name ? htmlspecialchars($delivery_location_name) . ' (' . htmlspecialchars($delivery_location_type) . ')' : '—'; ?>
                            </div>
                        </div>
                    </td>
                    <td class="text-center">
                        <span class="badge badge-primary badge-pill"><?php echo $item_count; ?></span>
                        <div class="small mt-1">
                            <div class="text-muted">
                                <small>Req: <?php echo number_format($total_requested, 3); ?></small>
                            </div>
                            <?php if ($total_approved > 0): ?>
                                <div class="text-success">
                                    <small>App: <?php echo number_format($total_approved, 3); ?></small>
                                </div>
                            <?php endif; ?>
                            <?php if ($total_issued > 0): ?>
                                <div class="text-info">
                                    <small>Iss: <?php echo number_format($total_issued, 3); ?></small>
                                </div>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <span class="badge <?php echo $priority_badge; ?>">
                            <i class="<?php echo $priority_icon; ?> mr-1"></i>
                            <?php echo ucfirst($priority); ?>
                        </span>
                    </td>
                    <td>
                        <span class="badge <?php echo $status_badge; ?>">
                            <i class="<?php echo $status_icon; ?> mr-1"></i>
                            <?php echo ucfirst($status); ?>
                        </span>
                        <?php if ($status == 'partial'): ?>
                            <br><small class="text-muted">Partially fulfilled</small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="btn-group" role="group">
                            <a href="inventory_requisition_view.php?id=<?php echo $requisition_id; ?>" 
                               class="btn btn-sm btn-info" title="View Details">
                                <i class="fas fa-eye"></i>
                            </a>
                     
                                <a href="inventory_requisition_edit.php?id=<?php echo $requisition_id; ?>" 
                                   class="btn btn-sm btn-warning" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                          
                           
                                <a href="inventory_requisition_action.php?action=approve&id=<?php echo $requisition_id; ?>" 
                                   class="btn btn-sm btn-success" title="Approve">
                                    <i class="fas fa-check"></i>
                                </a>
                           
                          
                                <a href="inventory_requisition_fulfill.php?id=<?php echo $requisition_id; ?>" 
                                   class="btn btn-sm btn-primary" title="Fulfill">
                                    <i class="fas fa-truck-loading"></i>
                                </a>
                         
                        </div>
                    </td>
                </tr>
            <?php } 
            
            if ($num_rows[0] == 0) {
                ?>
                <tr>
                    <td colspan="8" class="text-center py-4">
                        <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No Requisitions Found</h5>
                        <p class="text-muted">No requisitions match your current filters.</p>
                        <a href="inventory_requisition_create.php" class="btn btn-primary mt-2">
                            <i class="fas fa-plus mr-2"></i>Create First Requisition
                        </a>
                    </td>
                </tr>
                <?php
            }
            ?>
            </tbody>
        </table>
    </div>
    
    <!-- Ends Card Body -->
    <?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/filter_footer.php'; ?>
</div>

<script>
$(document).ready(function() {
    // Initialize tooltips
    $('[data-toggle="tooltip"]').tooltip();
    
    // Initialize Select2
    $('.select2').select2({
        theme: 'bootstrap4'
    });

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
    
    // Highlight urgent requisitions
    $('.badge-danger').closest('tr').addClass('bg-danger-light');
    
    // Auto-refresh page every 2 minutes for urgent items
    setInterval(function() {
        const urgentCount = <?php echo $stats['urgent_count']; ?>;
        if (urgentCount > 0) {
            // Blink urgent badge
            $('.badge-danger').fadeOut(500).fadeIn(500);
        }
    }, 120000); // 2 minutes
});

function exportRequisitions() {
    const params = new URLSearchParams(window.location.search);
    params.set('export', 'csv');
    window.location.href = 'inventory_requisitions.php?' + params.toString();
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + N for new requisition
    if (e.ctrlKey && e.keyCode === 78) {
        e.preventDefault();
        window.location.href = 'inventory_requisition_create.php';
    }
    // Ctrl + F for focus search
    if (e.ctrlKey && e.keyCode === 70) {
        e.preventDefault();
        $('input[name="q"]').focus();
    }
    // Ctrl + E for export
    if (e.ctrlKey && e.keyCode === 69) {
        e.preventDefault();
        exportRequisitions();
    }
});
</script>

<style>
.bg-danger-light {
    background-color: rgba(220, 53, 69, 0.1) !important;
}

.badge-pill {
    padding: 0.35em 0.65em;
    font-size: 0.85em;
}

.select2-container--bootstrap4 .select2-selection--single {
    height: calc(1.5em + 0.75rem + 2px) !important;
}

.btn-group .btn-sm {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
    line-height: 1.5;
}

.table-hover tbody tr:hover {
    background-color: rgba(0, 0, 0, 0.03);
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
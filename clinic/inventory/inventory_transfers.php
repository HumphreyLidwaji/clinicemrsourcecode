<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Default Column Sortby/Order Filter
$sort = "transfer_date";
$order = "DESC";

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Filter parameters
$status_filter = $_GET['status'] ?? '';
$item_filter = $_GET['item'] ?? '';
$user_filter = $_GET['user'] ?? '';
$from_location_filter = $_GET['from_location'] ?? '';
$to_location_filter = $_GET['to_location'] ?? '';

// Date Range Filter
$dtf = sanitizeInput($_GET['dtf'] ?? '');
$dtt = sanitizeInput($_GET['dtt'] ?? '');
$canned_date = $_GET['canned_date'] ?? '';

if (!empty($dtf) && !empty($dtt)) {
    $date_query = "AND DATE(t.transfer_date) BETWEEN '$dtf' AND '$dtt'";
} else if (!empty($canned_date)) {
    switch($canned_date) {
        case 'today':
            $date_query = "AND DATE(t.transfer_date) = CURDATE()";
            break;
        case 'yesterday':
            $date_query = "AND DATE(t.transfer_date) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
            break;
        case 'thisweek':
            $date_query = "AND YEARWEEK(t.transfer_date, 1) = YEARWEEK(CURDATE(), 1)";
            break;
        case 'lastweek':
            $date_query = "AND YEARWEEK(t.transfer_date, 1) = YEARWEEK(CURDATE(), 1) - 1";
            break;
        case 'thismonth':
            $date_query = "AND MONTH(t.transfer_date) = MONTH(CURDATE()) 
                           AND YEAR(t.transfer_date) = YEAR(CURDATE())";
            break;
        case 'lastmonth':
            $date_query = "AND MONTH(t.transfer_date) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) 
                           AND YEAR(t.transfer_date) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))";
            break;
        default:
            $date_query = '';
    }
} else {
    $date_query = '';
}

// Status Filter
if ($status_filter) {
    $status_query = "AND t.transfer_status = '" . sanitizeInput($status_filter) . "'";
} else {
    $status_query = '';
}

// Item Filter
if ($item_filter) {
    $item_query = "AND EXISTS (SELECT 1 FROM inventory_transfer_items ti WHERE ti.transfer_id = t.transfer_id AND ti.item_id = " . intval($item_filter) . ")";
} else {
    $item_query = '';
}

// User Filter
if ($user_filter) {
    $user_query = "AND t.requested_by = " . intval($user_filter);
} else {
    $user_query = '';
}

// From Location Filter
if ($from_location_filter) {
    $from_location_query = "AND t.from_location_id = " . intval($from_location_filter);
} else {
    $from_location_query = '';
}

// To Location Filter
if ($to_location_filter) {
    $to_location_query = "AND t.to_location_id = " . intval($to_location_filter);
} else {
    $to_location_query = '';
}

// Search Query
$q = sanitizeInput($_GET['q'] ?? '');
if (!empty($q)) {
    $search_query = "AND (
        t.transfer_number LIKE '%$q%' 
        OR t.notes LIKE '%$q%'
        OR u.user_name LIKE '%$q%'
        OR fl.location_name LIKE '%$q%'
        OR tl.location_name LIKE '%$q%'
        OR EXISTS (
            SELECT 1 FROM inventory_transfer_items ti 
            JOIN inventory_items i ON ti.item_id = i.item_id 
            WHERE ti.transfer_id = t.transfer_id 
            AND (i.item_name LIKE '%$q%' OR i.item_code LIKE '%$q%')
        )
    )";
} else {
    $search_query = '';
}

// Main query for inventory transfers - UPDATED FOR MULTIPLE ITEMS
$sql = mysqli_query(
    $mysqli,
    "
    SELECT SQL_CALC_FOUND_ROWS 
           t.transfer_id, t.transfer_number, t.from_location_id, t.to_location_id,
           t.requested_by, t.transfer_date, t.transfer_status, t.notes,
           u.user_name as requested_by_name,
           fl.location_name as from_location_name,
           fl.location_type as from_location_type,
           tl.location_name as to_location_name,
           tl.location_type as to_location_type,
           COUNT(DISTINCT ti.item_id) as item_count,
           SUM(ti.quantity) as total_quantity,
           GROUP_CONCAT(DISTINCT i.item_name ORDER BY i.item_name SEPARATOR ', ') as item_names,
           GROUP_CONCAT(DISTINCT i.item_code ORDER BY i.item_name SEPARATOR ', ') as item_codes
    FROM inventory_transfers t 
    LEFT JOIN users u ON t.requested_by = u.user_id
    LEFT JOIN inventory_locations fl ON t.from_location_id = fl.location_id
    LEFT JOIN inventory_locations tl ON t.to_location_id = tl.location_id
    LEFT JOIN inventory_transfer_items ti ON t.transfer_id = ti.transfer_id
    LEFT JOIN inventory_items i ON ti.item_id = i.item_id
    WHERE 1=1
      $status_query
      $item_query
      $user_query
      $from_location_query
      $to_location_query
      $date_query
      $search_query
    GROUP BY t.transfer_id, t.transfer_number, t.from_location_id, t.to_location_id,
             t.requested_by, t.transfer_date, t.transfer_status, t.notes,
             u.user_name, fl.location_name, fl.location_type, 
             tl.location_name, tl.location_type
    ORDER BY $sort $order
    LIMIT $record_from, $record_to
");

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get statistics
$total_transfers = $num_rows[0];
$pending_count = 0;
$in_transit_count = 0;
$completed_count = 0;
$cancelled_count = 0;
$today_transfers = 0;
$week_transfers = 0;

// Reset pointer and calculate
mysqli_data_seek($sql, 0);
while ($transfer = mysqli_fetch_assoc($sql)) {
    switch($transfer['transfer_status']) {
        case 'pending':
            $pending_count++;
            break;
        case 'in_transit':
            $in_transit_count++;
            break;
        case 'completed':
            $completed_count++;
            break;
        case 'cancelled':
            $cancelled_count++;
            break;
    }
    
    // Count today's transfers
    if (date('Y-m-d', strtotime($transfer['transfer_date'])) == date('Y-m-d')) {
        $today_transfers++;
    }
    
    // Count this week's transfers
    $transfer_date = strtotime($transfer['transfer_date']);
    $week_start = strtotime('monday this week');
    $week_end = strtotime('sunday this week');
    if ($transfer_date >= $week_start && $transfer_date <= $week_end) {
        $week_transfers++;
    }
}
mysqli_data_seek($sql, $record_from);

// Get unique items for filter (from transfer_items, not inventory_items directly)
$items_sql = mysqli_query($mysqli, "
    SELECT DISTINCT i.item_id, i.item_name, i.item_code 
    FROM inventory_items i
    JOIN inventory_transfer_items ti ON i.item_id = ti.item_id
    WHERE i.item_status != 'Discontinued' 
    ORDER BY i.item_name
");

// Get unique users for filter
$users_sql = mysqli_query($mysqli, "
    SELECT DISTINCT u.user_id, u.user_name 
    FROM users u
    JOIN inventory_transfers t ON u.user_id = t.requested_by
    WHERE u.user_status = 1 
    ORDER BY u.user_name
");

// Get unique locations for filter
$locations_sql = mysqli_query($mysqli, "
    SELECT location_id, location_name, location_type 
    FROM inventory_locations 
    WHERE is_active = 1 
    ORDER BY location_type, location_name
");

// Get today's transfers summary
$today_summary_sql = "SELECT 
    COUNT(*) as count,
    SUM(CASE WHEN transfer_status = 'pending' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN transfer_status = 'in_transit' THEN 1 ELSE 0 END) as in_transit,
    SUM(CASE WHEN transfer_status = 'completed' THEN 1 ELSE 0 END) as completed
    FROM inventory_transfers 
    WHERE DATE(transfer_date) = CURDATE()";
$today_summary_result = mysqli_query($mysqli, $today_summary_sql);
$today_summary = mysqli_fetch_assoc($today_summary_result);
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0 text-white"><i class="fas fa-fw fa-truck-moving mr-2"></i>Inventory Transfers</h3>
        <div class="card-tools">
            <div class="btn-group">
                <a href="inventory_transfer.php" class="btn btn-light">
                    <i class="fas fa-plus mr-2"></i>New Transfer
                </a>
                <a href="inventory_transactions.php?type=transfer" class="btn btn-info ml-2">
                    <i class="fas fa-exchange-alt mr-2"></i>View Transfer Transactions
                </a>
                <button type="button" class="btn btn-light dropdown-toggle dropdown-toggle-split" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <span class="sr-only">Toggle Dropdown</span>
                </button>
                <div class="dropdown-menu dropdown-menu-right">
                    <a class="dropdown-item" href="inventory_bulk_transfer.php">
                        <i class="fas fa-layer-group mr-2"></i>Bulk Transfer
                    </a>
                    <a class="dropdown-item" href="inventory_transfer_schedule.php">
                        <i class="fas fa-calendar-alt mr-2"></i>Scheduled Transfers
                    </a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="inventory_transfer_reports.php">
                        <i class="fas fa-chart-bar mr-2"></i>Transfer Reports
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Quick Stats Bar -->
    <div class="card-header bg-light py-2">
        <div class="row text-center">
            <div class="col-md-2">
                <div class="small text-muted">Total Transfers</div>
                <div class="h5 font-weight-bold text-primary"><?php echo number_format($total_transfers); ?></div>
            </div>
            <div class="col-md-2">
                <div class="small text-muted">Pending</div>
                <div class="h5 font-weight-bold text-warning"><?php echo $pending_count; ?></div>
            </div>
            <div class="col-md-2">
                <div class="small text-muted">In Transit</div>
                <div class="h5 font-weight-bold text-info"><?php echo $in_transit_count; ?></div>
            </div>
            <div class="col-md-2">
                <div class="small text-muted">Completed</div>
                <div class="h5 font-weight-bold text-success"><?php echo $completed_count; ?></div>
            </div>
            <div class="col-md-2">
                <div class="small text-muted">Today</div>
                <div class="h5 font-weight-bold text-dark"><?php echo $today_transfers; ?></div>
            </div>
            <div class="col-md-2">
                <div class="small text-muted">This Week</div>
                <div class="h5 font-weight-bold text-dark"><?php echo $week_transfers; ?></div>
            </div>
        </div>
    </div>

    <div class="card-header pb-2 pt-3">
        <form autocomplete="off">
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search transfers, items, references, locations..." autofocus>
                            <div class="input-group-append">
                                <button class="btn btn-secondary" type="button" data-toggle="collapse" data-target="#advancedFilter">
                                    <i class="fas fa-filter"></i>
                                </button>
                                <button class="btn btn-primary">
                                    <i class="fa fa-search"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="btn-toolbar form-group float-right">
                        <div class="btn-group">
                            <span class="btn btn-light border" data-toggle="tooltip" title="Total Transfers">
                                <i class="fas fa-truck-moving text-primary mr-1"></i>
                                <strong><?php echo $total_transfers; ?></strong>
                            </span>
                            <span class="btn btn-light border" data-toggle="tooltip" title="Pending Transfers">
                                <i class="fas fa-clock text-warning mr-1"></i>
                                <strong><?php echo $pending_count; ?></strong>
                            </span>
                            <span class="btn btn-light border" data-toggle="tooltip" title="In Transit">
                                <i class="fas fa-shipping-fast text-info mr-1"></i>
                                <strong><?php echo $in_transit_count; ?></strong>
                            </span>
                            <span class="btn btn-light border" data-toggle="tooltip" title="Completed">
                                <i class="fas fa-check-circle text-success mr-1"></i>
                                <strong><?php echo $completed_count; ?></strong>
                            </span>
                            <a href="?<?php echo $url_query_strings_sort ?>&export=pdf" class="btn btn-light border ml-2">
                                <i class="fa fa-fw fa-file-pdf mr-2"></i>Export
                            </a>
                            <a href="inventory_bulk_transfer.php" class="btn btn-warning ml-2">
                                <i class="fas fa-layer-group mr-2"></i>Bulk Transfer
                            </a>
                            <a href="inventory_locations.php" class="btn btn-info ml-2">
                                <i class="fas fa-map-marker-alt mr-2"></i>Locations
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php 
                if (isset($_GET['dtf']) || $status_filter || $item_filter || $user_filter || $from_location_filter || $to_location_filter || $canned_date) { 
                    echo "show"; 
                } 
            ?>" id="advancedFilter">
                <div class="row">
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Date Range</label>
                            <select class="form-control select2" name="canned_date" onchange="this.form.submit()">
                                <option value="">- All Dates -</option>
                                <option value="today" <?php if ($canned_date == "today") { echo "selected"; } ?>>Today</option>
                                <option value="yesterday" <?php if ($canned_date == "yesterday") { echo "selected"; } ?>>Yesterday</option>
                                <option value="thisweek" <?php if ($canned_date == "thisweek") { echo "selected"; } ?>>This Week</option>
                                <option value="lastweek" <?php if ($canned_date == "lastweek") { echo "selected"; } ?>>Last Week</option>
                                <option value="thismonth" <?php if ($canned_date == "thismonth") { echo "selected"; } ?>>This Month</option>
                                <option value="lastmonth" <?php if ($canned_date == "lastmonth") { echo "selected"; } ?>>Last Month</option>
                                <option value="custom" <?php if (!empty($dtf)) { echo "selected"; } ?>>Custom Range</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Date from</label>
                            <input type="date" class="form-control" name="dtf" max="2999-12-31" value="<?php echo nullable_htmlentities($dtf); ?>" onchange="this.form.submit()">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Date to</label>
                            <input type="date" class="form-control" name="dtt" max="2999-12-31" value="<?php echo nullable_htmlentities($dtt); ?>" onchange="this.form.submit()">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Transfer Status</label>
                            <select class="form-control select2" name="status" onchange="this.form.submit()">
                                <option value="">- All Statuses -</option>
                                <option value="pending" <?php if ($status_filter == "pending") { echo "selected"; } ?>>Pending</option>
                                <option value="in_transit" <?php if ($status_filter == "in_transit") { echo "selected"; } ?>>In Transit</option>
                                <option value="completed" <?php if ($status_filter == "completed") { echo "selected"; } ?>>Completed</option>
                                <option value="cancelled" <?php if ($status_filter == "cancelled") { echo "selected"; } ?>>Cancelled</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Item</label>
                            <select class="form-control select2" name="item" onchange="this.form.submit()">
                                <option value="">- All Items -</option>
                                <?php
                                while($item = mysqli_fetch_assoc($items_sql)) {
                                    $item_id = intval($item['item_id']);
                                    $item_name = nullable_htmlentities($item['item_name'] . ' (' . $item['item_code'] . ')');
                                    $selected = $item_filter == $item_id ? 'selected' : '';
                                    echo "<option value='$item_id' $selected>$item_name</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="row mt-2">
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Requested By</label>
                            <select class="form-control select2" name="user" onchange="this.form.submit()">
                                <option value="">- All Users -</option>
                                <?php
                                while($user = mysqli_fetch_assoc($users_sql)) {
                                    $user_id = intval($user['user_id']);
                                    $user_name = nullable_htmlentities($user['user_name']);
                                    $selected = $user_filter == $user_id ? 'selected' : '';
                                    echo "<option value='$user_id' $selected>$user_name</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>From Location</label>
                            <select class="form-control select2" name="from_location" onchange="this.form.submit()">
                                <option value="">- All Locations -</option>
                                <?php
                                mysqli_data_seek($locations_sql, 0);
                                while($location = mysqli_fetch_assoc($locations_sql)) {
                                    $location_id = intval($location['location_id']);
                                    $location_name = nullable_htmlentities($location['location_type'] . ' - ' . $location['location_name']);
                                    $selected = $from_location_filter == $location_id ? 'selected' : '';
                                    echo "<option value='$location_id' $selected>$location_name</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>To Location</label>
                            <select class="form-control select2" name="to_location" onchange="this.form.submit()">
                                <option value="">- All Locations -</option>
                                <?php
                                mysqli_data_seek($locations_sql, 0);
                                while($location = mysqli_fetch_assoc($locations_sql)) {
                                    $location_id = intval($location['location_id']);
                                    $location_name = nullable_htmlentities($location['location_type'] . ' - ' . $location['location_name']);
                                    $selected = $to_location_filter == $location_id ? 'selected' : '';
                                    echo "<option value='$location_id' $selected>$location_name</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>&nbsp;</label>
                            <a href="inventory_transfers.php" class="btn btn-secondary btn-block">Clear Filters</a>
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
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=t.transfer_date&order=<?php echo $disp; ?>">
                        Date & Time <?php if ($sort == 't.transfer_date') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>Transfer Details</th>
                <th class="text-center">Items & Quantity</th>
                <th class="text-center">
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=t.transfer_status&order=<?php echo $disp; ?>">
                        Status <?php if ($sort == 't.transfer_status') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>Locations</th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=u.user_name&order=<?php echo $disp; ?>">
                        Requested By <?php if ($sort == 'u.user_name') { echo $order_icon; } ?>
                    </a>
                </th>
                <th class="text-center">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php
            while ($row = mysqli_fetch_array($sql)) {
                $transfer_id = intval($row['transfer_id']);
                $transfer_number = nullable_htmlentities($row['transfer_number']);
                $transfer_date = nullable_htmlentities($row['transfer_date']);
                $transfer_status = nullable_htmlentities($row['transfer_status']);
                $notes = nullable_htmlentities($row['notes']);
                $requested_by_name = nullable_htmlentities($row['requested_by_name']);
                $from_location_name = nullable_htmlentities($row['from_location_name']);
                $from_location_type = nullable_htmlentities($row['from_location_type']);
                $to_location_name = nullable_htmlentities($row['to_location_name']);
                $to_location_type = nullable_htmlentities($row['to_location_type']);
                $item_count = intval($row['item_count']);
                $total_quantity = intval($row['total_quantity']);
                $item_names = nullable_htmlentities($row['item_names']);
                $item_codes = nullable_htmlentities($row['item_codes']);

                // Determine status color and icon
                switch($transfer_status) {
                    case 'pending':
                        $status_color = 'warning';
                        $status_icon = 'clock';
                        $status_badge = 'badge-warning';
                        break;
                    case 'in_transit':
                        $status_color = 'info';
                        $status_icon = 'shipping-fast';
                        $status_badge = 'badge-info';
                        break;
                    case 'completed':
                        $status_color = 'success';
                        $status_icon = 'check-circle';
                        $status_badge = 'badge-success';
                        break;
                    case 'cancelled':
                        $status_color = 'danger';
                        $status_icon = 'times-circle';
                        $status_badge = 'badge-danger';
                        break;
                    default:
                        $status_color = 'secondary';
                        $status_icon = 'question-circle';
                        $status_badge = 'badge-secondary';
                }

                // Format status for display
                $display_status = ucwords(str_replace('_', ' ', $transfer_status));

                // Check if today's transfer
                $is_today = date('Y-m-d', strtotime($transfer_date)) == date('Y-m-d');
                
                // Get transaction info for this transfer
                $transactions_sql = "SELECT 
                    SUM(CASE WHEN transaction_type = 'transfer_out' THEN 1 ELSE 0 END) as out_count,
                    SUM(CASE WHEN transaction_type = 'transfer_in' THEN 1 ELSE 0 END) as in_count
                    FROM inventory_transactions 
                    WHERE transfer_id = $transfer_id";
                $transactions_result = mysqli_query($mysqli, $transactions_sql);
                $transactions = mysqli_fetch_assoc($transactions_result);
                $has_transactions = ($transactions['out_count'] > 0 || $transactions['in_count'] > 0);
                ?>
                <tr class="<?php echo $is_today ? 'table-info' : ''; ?>">
                    <td>
                        <?php if ($transfer_date): ?>
                            <div class="font-weight-bold"><?php echo date('M j, Y', strtotime($transfer_date)); ?></div>
                            <small class="text-muted"><?php echo date('H:i', strtotime($transfer_date)); ?></small>
                            <?php if($is_today): ?>
                                <div><small class="badge badge-success">Today</small></div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="text-muted">No Date</div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="font-weight-bold"><?php echo $transfer_number; ?></div>
                        <?php if ($notes): ?>
                            <small class="text-muted" data-toggle="tooltip" title="<?php echo htmlspecialchars($notes); ?>">
                                <i class="fas fa-sticky-note mr-1"></i><?php echo truncate($notes, 40); ?>
                            </small>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <div class="font-weight-bold text-primary">
                            <?php echo number_format($item_count); ?> item<?php echo $item_count != 1 ? 's' : ''; ?>
                        </div>
                        <small class="text-muted">
                            <?php echo number_format($total_quantity); ?> total units
                        </small>
                        <?php if ($item_names): ?>
                            <div class="mt-1">
                                <small class="text-dark">
                                    <i class="fas fa-box mr-1"></i>
                                    <?php echo truncate($item_names, 50); ?>
                                </small>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <span class="badge <?php echo $status_badge; ?> p-2">
                            <i class="fas fa-<?php echo $status_icon; ?> mr-1"></i>
                            <?php echo $display_status; ?>
                        </span>
                        <?php if ($has_transactions): ?>
                            <div class="mt-1">
                                <small class="text-success">
                                    <i class="fas fa-exchange-alt mr-1"></i>
                                    Has Transactions
                                </small>
                            </div>
                        <?php elseif ($transfer_status == 'completed'): ?>
                            <div class="mt-1">
                                <small class="text-warning">
                                    <i class="fas fa-exclamation-triangle mr-1"></i>
                                    No Transactions
                                </small>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="d-flex align-items-center">
                            <div class="flex-shrink-0">
                                <i class="fas fa-map-marker-alt text-danger"></i>
                            </div>
                            <div class="flex-grow-1 ml-2">
                                <small>
                                    <span class="font-weight-bold">From:</span> 
                                    <?php echo $from_location_type . ' - ' . $from_location_name; ?>
                                </small>
                            </div>
                        </div>
                        <div class="d-flex align-items-center mt-1">
                            <div class="flex-shrink-0">
                                <i class="fas fa-map-marker-alt text-success"></i>
                            </div>
                            <div class="flex-grow-1 ml-2">
                                <small>
                                    <span class="font-weight-bold">To:</span> 
                                    <?php echo $to_location_type . ' - ' . $to_location_name; ?>
                                </small>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div class="font-weight-bold"><?php echo $requested_by_name; ?></div>
                    </td>
                    <td>
                        <div class="dropdown dropleft text-center">
                            <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                <i class="fas fa-ellipsis-h"></i>
                            </button>
                            <div class="dropdown-menu">
                                <a class="dropdown-item" href="inventory_transfer_view.php?id=<?php echo $transfer_id; ?>">
                                    <i class="fas fa-fw fa-eye mr-2"></i>View Details
                                </a>
                                <a class="dropdown-item" href="inventory_transfer_items.php?transfer_id=<?php echo $transfer_id; ?>">
                                    <i class="fas fa-fw fa-boxes mr-2"></i>View Items
                                </a>
                                <?php if ($from_location_name): ?>
                                    <a class="dropdown-item" href="inventory_locations.php?location_id=<?php echo $row['from_location_id']; ?>">
                                        <i class="fas fa-fw fa-map-marker-alt mr-2"></i>From Location
                                    </a>
                                <?php endif; ?>
                                <?php if ($to_location_name): ?>
                                    <a class="dropdown-item" href="inventory_locations.php?location_id=<?php echo $row['to_location_id']; ?>">
                                        <i class="fas fa-fw fa-map-marker-alt mr-2"></i>To Location
                                    </a>
                                <?php endif; ?>
                                <?php if ($notes): ?>
                                    <a class="dropdown-item" href="#" onclick="showTransferNotes(<?php echo $transfer_id; ?>, '<?php echo addslashes($notes); ?>')">
                                        <i class="fas fa-fw fa-sticky-note mr-2"></i>View Notes
                                    </a>
                                <?php endif; ?>
                                <div class="dropdown-divider"></div>
                                <?php if ($transfer_status == 'pending'): ?>
                                    <a class="dropdown-item text-warning" href="inventory_transfer_process.php?id=<?php echo $transfer_id; ?>&action=start">
                                        <i class="fas fa-fw fa-play mr-2"></i>Mark In Transit
                                    </a>
                                <?php elseif ($transfer_status == 'in_transit'): ?>
                                    <a class="dropdown-item text-success" href="inventory_transfer_process.php?id=<?php echo $transfer_id; ?>&action=complete">
                                        <i class="fas fa-fw fa-check mr-2"></i>Mark Completed
                                    </a>
                                <?php endif; ?>
                                <?php if (in_array($transfer_status, ['pending', 'in_transit'])): ?>
                                    <a class="dropdown-item text-danger" href="inventory_transfer_process.php?id=<?php echo $transfer_id; ?>&action=cancel" 
                                       onclick="return confirmCancel(<?php echo $transfer_id; ?>, '<?php echo addslashes($transfer_number); ?>')">
                                        <i class="fas fa-fw fa-times mr-2"></i>Cancel Transfer
                                    </a>
                                <?php endif; ?>
                                <?php if ($transfer_status == 'completed' && !$has_transactions): ?>
                                    <a class="dropdown-item text-info" href="inventory_transfer_complete.php?id=<?php echo $transfer_id; ?>">
                                        <i class="fas fa-fw fa-exchange-alt mr-2"></i>Create Transactions
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
                    <td colspan="7" class="text-center py-5">
                        <i class="fas fa-truck-moving fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No Transfers Found</h5>
                        <p class="text-muted">No transfers match your current filters.</p>
                        <a href="inventory_transfer.php" class="btn btn-primary mt-2">
                            <i class="fas fa-plus mr-2"></i>Create First Transfer
                        </a>
                        <a href="inventory_transfers.php" class="btn btn-secondary mt-2 ml-2">
                            <i class="fas fa-times mr-2"></i>Clear Filters
                        </a>
                    </td>
                </tr>
                <?php
            }
            ?>
            </tbody>
        </table>
    </div>
    
    <?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/filter_footer.php'; ?>
</div>

<!-- Transfer Notes Modal -->
<div class="modal fade" id="transferNotesModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-sticky-note mr-2"></i>Transfer Notes</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <p id="transferNotesContent" class="mb-0"></p>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.select2').select2();
    $('[data-toggle="tooltip"]').tooltip();

    // Auto-submit when date range changes
    $('input[type="date"]').change(function() {
        if ($(this).val()) {
            $(this).closest('form').submit();
        }
    });

    // Auto-submit when canned date is selected
    $('select[name="canned_date"]').change(function() {
        if ($(this).val() !== 'custom') {
            $(this).closest('form').submit();
        }
    });
});

function showTransferNotes(transferId, notes) {
    $('#transferNotesContent').text(notes);
    $('#transferNotesModal').modal('show');
}

function confirmCancel(transferId, transferNumber) {
    const message = `Are you sure you want to cancel transfer ${transferNumber}?\n\n` +
                   `This will mark the transfer as cancelled and prevent any further actions.`;
    
    return confirm(message);
}

function processTransfer(transferId, action) {
    const actionMap = {
        'start': 'Mark as In Transit',
        'complete': 'Mark as Completed',
        'cancel': 'Cancel Transfer'
    };
    
    if (confirm(`Are you sure you want to ${actionMap[action].toLowerCase()} this transfer?`)) {
        window.location.href = 'inventory_transfer_process.php?id=' + transferId + '&action=' + action;
    }
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + N for new transfer
    if (e.ctrlKey && e.keyCode === 78) {
        e.preventDefault();
        window.location.href = 'inventory_transfer.php';
    }
    // Ctrl + F for focus search
    if (e.ctrlKey && e.keyCode === 70) {
        e.preventDefault();
        $('input[name="q"]').focus();
    }
    // Escape to clear filters
    if (e.keyCode === 27) {
        window.location.href = 'inventory_transfers.php';
    }
    // Ctrl + B for bulk transfer
    if (e.ctrlKey && e.keyCode === 66) {
        e.preventDefault();
        window.location.href = 'inventory_bulk_transfer.php';
    }
    // Ctrl + L for locations
    if (e.ctrlKey && e.keyCode === 76) {
        e.preventDefault();
        window.location.href = 'inventory_locations.php';
    }
});
</script>

<style>
.table-hover tbody tr:hover {
    background-color: rgba(0, 123, 255, 0.075);
}

.badge {
    font-size: 0.75em;
    min-width: 80px;
}

.dropdown-menu {
    min-width: 220px;
}

.select2-container .select2-selection--single {
    height: 38px;
}

.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 36px;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
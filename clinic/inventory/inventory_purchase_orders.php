<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Default Column Sortby/Order Filter
$sort = "po.po_date";
$order = "DESC";

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Filter parameters
$status_filter = $_GET['status'] ?? '';
$supplier_filter = $_GET['supplier'] ?? '';
$location_filter = $_GET['location'] ?? '';
$requested_by_filter = $_GET['requested_by'] ?? '';
$approved_by_filter = $_GET['approved_by'] ?? '';

// Date Range Filter
$dtf = sanitizeInput($_GET['dtf'] ?? '');
$dtt = sanitizeInput($_GET['dtt'] ?? '');
$canned_date = $_GET['canned_date'] ?? '';

if (!empty($dtf) && !empty($dtt)) {
    $date_query = "AND DATE(po.po_date) BETWEEN '$dtf' AND '$dtt'";
} else if (!empty($canned_date)) {
    switch($canned_date) {
        case 'today':
            $date_query = "AND DATE(po.po_date) = CURDATE()";
            break;
        case 'yesterday':
            $date_query = "AND DATE(po.po_date) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
            break;
        case 'thisweek':
            $date_query = "AND YEARWEEK(po.po_date, 1) = YEARWEEK(CURDATE(), 1)";
            break;
        case 'lastweek':
            $date_query = "AND YEARWEEK(po.po_date, 1) = YEARWEEK(CURDATE(), 1) - 1";
            break;
        case 'thismonth':
            $date_query = "AND MONTH(po.po_date) = MONTH(CURDATE()) 
                           AND YEAR(po.po_date) = YEAR(CURDATE())";
            break;
        case 'lastmonth':
            $date_query = "AND MONTH(po.po_date) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) 
                           AND YEAR(po.po_date) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))";
            break;
        default:
            $date_query = '';
    }
} else {
    $date_query = '';
}

// Status Filter
if ($status_filter) {
    $status_query = "AND po.status = '" . sanitizeInput($status_filter) . "'";
} else {
    $status_query = '';
}

// Supplier Filter
if ($supplier_filter) {
    $supplier_query = "AND po.supplier_id = " . intval($supplier_filter);
} else {
    $supplier_query = '';
}

// Location Filter
if ($location_filter) {
    $location_query = "AND po.delivery_location_id = " . intval($location_filter);
} else {
    $location_query = '';
}

// Requested By Filter
if ($requested_by_filter) {
    $requested_by_query = "AND po.requested_by = " . intval($requested_by_filter);
} else {
    $requested_by_query = '';
}

// Approved By Filter
if ($approved_by_filter) {
    $approved_by_query = "AND po.approved_by = " . intval($approved_by_filter);
} else {
    $approved_by_query = '';
}

// Search Query
$q = sanitizeInput($_GET['q'] ?? '');
if (!empty($q)) {
    $search_query = "AND (
        po.po_number LIKE '%$q%' 
        po.notes LIKE '%$q%'
        OR s.supplier_name LIKE '%$q%'
        OR l.location_name LIKE '%$q%'
        OR req.user_name LIKE '%$q%'
        OR req.user_name LIKE '%$q%'
        OR app.user_name LIKE '%$q%'
        OR app.user_name LIKE '%$q%'
    )";
} else {
    $search_query = '';
}

// Main query for purchase orders
$sql = mysqli_query(
    $mysqli,
    "
    SELECT SQL_CALC_FOUND_ROWS 
        po.*,
        s.supplier_id,
        s.supplier_name,
        s.supplier_contact,
        s.supplier_phone,
        s.supplier_email,
        l.location_id,
        l.location_name,
        l.location_type,
        req.user_id as requested_by_id,
        req.user_name as requested_by_name,
        req.user_name as requested_by_username,
        app.user_id as approved_by_id,
        app.user_name as approved_by_name,
        app.user_name as approved_by_username,
        (SELECT COUNT(*) FROM inventory_purchase_order_items poi WHERE poi.purchase_order_id = po.purchase_order_id AND poi.is_active = 1) as item_count,
        (SELECT SUM(poi.quantity_ordered) FROM inventory_purchase_order_items poi WHERE poi.purchase_order_id = po.purchase_order_id AND poi.is_active = 1) as total_ordered,
        (SELECT SUM(poi.quantity_received) FROM inventory_purchase_order_items poi WHERE poi.purchase_order_id = po.purchase_order_id AND poi.is_active = 1) as total_received,
        (SELECT COUNT(*) FROM inventory_grns g WHERE g.purchase_order_id = po.purchase_order_id AND g.is_active = 1) as grn_count
    FROM inventory_purchase_orders po
    LEFT JOIN suppliers s ON po.supplier_id = s.supplier_id
    LEFT JOIN inventory_locations l ON po.delivery_location_id = l.location_id
    LEFT JOIN users req ON po.requested_by = req.user_id
    LEFT JOIN users app ON po.approved_by = app.user_id
    WHERE po.is_active = 1
      $date_query
      $status_query
      $supplier_query
      $location_query
      $requested_by_query
      $approved_by_query
      $search_query
    ORDER BY 
        CASE 
            WHEN po.status = 'draft' THEN 1
            WHEN po.status = 'submitted' THEN 2
            WHEN po.status = 'approved' THEN 3
            WHEN po.status = 'partially_received' THEN 4
            WHEN po.status = 'received' THEN 5
            WHEN po.status = 'cancelled' THEN 6
            ELSE 7
        END,
        $sort $order
    LIMIT $record_from, $record_to
");

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get statistics
$total_pos = $num_rows[0];
$draft_count = 0;
$submitted_count = 0;
$approved_count = 0;
$received_count = 0;
$partially_received_count = 0;
$cancelled_count = 0;
$today_count = 0;
$week_count = 0;
$month_count = 0;
$total_value = 0;

// Reset pointer and calculate
mysqli_data_seek($sql, 0);
while ($po = mysqli_fetch_assoc($sql)) {
    switch($po['status']) {
        case 'draft':
            $draft_count++;
            break;
        case 'submitted':
            $submitted_count++;
            break;
        case 'approved':
            $approved_count++;
            break;
        case 'received':
            $received_count++;
            break;
        case 'partially_received':
            $partially_received_count++;
            break;
        case 'cancelled':
            $cancelled_count++;
            break;
    }
    
    $po_date = strtotime($po['po_date']);
    
    // Count today's POs
    if (date('Y-m-d', $po_date) == date('Y-m-d')) {
        $today_count++;
    }
    
    // Count this week's POs
    $week_start = strtotime('monday this week');
    $week_end = strtotime('sunday this week');
    if ($po_date >= $week_start && $po_date <= $week_end) {
        $week_count++;
    }
    
    // Count this month's POs
    if (date('Y-m', $po_date) == date('Y-m')) {
        $month_count++;
    }
    
    // Calculate total value
    $total_value += $po['total_estimated_amount'] ?? 0;
}
mysqli_data_seek($sql, $record_from);

// Get unique suppliers for filter
$suppliers_sql = mysqli_query($mysqli, "
    SELECT supplier_id, supplier_name 
    FROM suppliers 
    WHERE supplier_is_active = 1 
    ORDER BY supplier_name
");

// Get unique locations for filter
$locations_sql = mysqli_query($mysqli, "
    SELECT location_id, location_name, location_type 
    FROM inventory_locations 
    WHERE is_active = 1 
    ORDER BY location_type, location_name
");

// Get unique users for filter
$users_sql = mysqli_query($mysqli, "
    SELECT user_id, user_name
    FROM users 
  
");

// Get today's summary
$today_summary_sql = "SELECT 
    COUNT(*) as count,
    SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as drafts_today,
    SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_today
    FROM inventory_purchase_orders 
    WHERE DATE(po_date) = CURDATE() AND is_active = 1";
$today_summary_result = mysqli_query($mysqli, $today_summary_sql);
$today_summary = mysqli_fetch_assoc($today_summary_result);
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0 text-white"><i class="fas fa-fw fa-clipboard-check mr-2"></i>Purchase Orders</h3>
        <div class="card-tools">
            <div class="btn-group">
                <a href="inventory_purchase_order_create.php" class="btn btn-light">
                    <i class="fas fa-plus mr-2"></i>Create PO
                </a>
                <button type="button" class="btn btn-light dropdown-toggle dropdown-toggle-split" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <span class="sr-only">Toggle Dropdown</span>
                </button>
                <div class="dropdown-menu dropdown-menu-right">
                    <a class="dropdown-item" href="inventory_purchase_order_create.php">
                        <i class="fas fa-plus mr-2"></i>New Purchase Order
                    </a>
                    <a class="dropdown-item" href="inventory_purchase_order_import.php">
                        <i class="fas fa-file-import mr-2"></i>Import from CSV
                    </a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="inventory_grns.php">
                        <i class="fas fa-receipt mr-2"></i>View GRNs
                    </a>
                    <a class="dropdown-item" href="inventory_suppliers.php">
                        <i class="fas fa-truck mr-2"></i>View Suppliers
                    </a>
                    <a class="dropdown-item" href="reports_purchase_orders.php">
                        <i class="fas fa-chart-bar mr-2"></i>PO Reports
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Quick Stats Bar -->
    <div class="card-header bg-light py-2">
        <div class="row text-center">
            <div class="col-md-2">
                <div class="small text-muted">Total POs</div>
                <div class="h5 font-weight-bold text-primary"><?php echo number_format($total_pos); ?></div>
            </div>
            <div class="col-md-2">
                <div class="small text-muted">Draft</div>
                <div class="h5 font-weight-bold text-secondary"><?php echo $draft_count; ?></div>
            </div>
            <div class="col-md-2">
                <div class="small text-muted">Submitted</div>
                <div class="h5 font-weight-bold text-info"><?php echo $submitted_count; ?></div>
            </div>
            <div class="col-md-2">
                <div class="small text-muted">Approved</div>
                <div class="h5 font-weight-bold text-success"><?php echo $approved_count; ?></div>
            </div>
            <div class="col-md-2">
                <div class="small text-muted">Received</div>
                <div class="h5 font-weight-bold text-warning"><?php echo $received_count + $partially_received_count; ?></div>
            </div>
            <div class="col-md-2">
                <div class="small text-muted">Total Value</div>
                <div class="h5 font-weight-bold text-dark">$<?php echo number_format($total_value, 2); ?></div>
            </div>
        </div>
        <div class="row text-center mt-2">
            <div class="col-md-3">
                <div class="small text-muted">Today</div>
                <div class="h6 font-weight-bold text-info"><?php echo $today_count; ?></div>
            </div>
            <div class="col-md-3">
                <div class="small text-muted">This Week</div>
                <div class="h6 font-weight-bold text-primary"><?php echo $week_count; ?></div>
            </div>
            <div class="col-md-3">
                <div class="small text-muted">This Month</div>
                <div class="h6 font-weight-bold text-success"><?php echo $month_count; ?></div>
            </div>
            <div class="col-md-3">
                <div class="small text-muted">Cancelled</div>
                <div class="h6 font-weight-bold text-danger"><?php echo $cancelled_count; ?></div>
            </div>
        </div>
    </div>

    <div class="card-header pb-2 pt-3">
        <form autocomplete="off">
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search PO numbers, suppliers, notes..." autofocus>
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
                            <span class="btn btn-light border" data-toggle="tooltip" title="Total POs">
                                <i class="fas fa-clipboard-check text-primary mr-1"></i>
                                <strong><?php echo $total_pos; ?></strong>
                            </span>
                            <span class="btn btn-light border" data-toggle="tooltip" title="Draft POs">
                                <i class="fas fa-file-alt text-secondary mr-1"></i>
                                <strong><?php echo $draft_count; ?></strong>
                            </span>
                            <span class="btn btn-light border" data-toggle="tooltip" title="Approved POs">
                                <i class="fas fa-check text-success mr-1"></i>
                                <strong><?php echo $approved_count; ?></strong>
                            </span>
                            <span class="btn btn-light border" data-toggle="tooltip" title="Pending Approval">
                                <i class="fas fa-clock text-info mr-1"></i>
                                <strong><?php echo $submitted_count; ?></strong>
                            </span>
                            <a href="?<?php echo $url_query_strings_sort ?>&export=pdf" class="btn btn-light border ml-2">
                                <i class="fa fa-fw fa-file-pdf mr-2"></i>Export
                            </a>
                            <a href="inventory_grns.php" class="btn btn-success ml-2">
                                <i class="fas fa-receipt mr-2"></i>GRNs
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php 
                if (isset($_GET['dtf']) || $status_filter || $supplier_filter || $location_filter || $requested_by_filter || $approved_by_filter || $canned_date) { 
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
                            <label>Status</label>
                            <select class="form-control select2" name="status" onchange="this.form.submit()">
                                <option value="">- All Statuses -</option>
                                <option value="draft" <?php if ($status_filter == "draft") { echo "selected"; } ?>>Draft</option>
                                <option value="submitted" <?php if ($status_filter == "submitted") { echo "selected"; } ?>>Submitted</option>
                                <option value="approved" <?php if ($status_filter == "approved") { echo "selected"; } ?>>Approved</option>
                                <option value="partially_received" <?php if ($status_filter == "partially_received") { echo "selected"; } ?>>Partially Received</option>
                                <option value="received" <?php if ($status_filter == "received") { echo "selected"; } ?>>Received</option>
                                <option value="cancelled" <?php if ($status_filter == "cancelled") { echo "selected"; } ?>>Cancelled</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Supplier</label>
                            <select class="form-control select2" name="supplier" onchange="this.form.submit()">
                                <option value="">- All Suppliers -</option>
                                <?php
                                while($supplier = mysqli_fetch_assoc($suppliers_sql)) {
                                    $supplier_id = intval($supplier['supplier_id']);
                                    $supplier_name = nullable_htmlentities($supplier['supplier_name']);
                                    $selected = $supplier_filter == $supplier_id ? 'selected' : '';
                                    echo "<option value='$supplier_id' $selected>$supplier_name</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="row mt-2">
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Delivery Location</label>
                            <select class="form-control select2" name="location" onchange="this.form.submit()">
                                <option value="">- All Locations -</option>
                                <?php
                                while($location = mysqli_fetch_assoc($locations_sql)) {
                                    $location_id = intval($location['location_id']);
                                    $location_name = nullable_htmlentities($location['location_type'] . ' - ' . $location['location_name']);
                                    $selected = $location_filter == $location_id ? 'selected' : '';
                                    echo "<option value='$location_id' $selected>$location_name</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Requested By</label>
                            <select class="form-control select2" name="requested_by" onchange="this.form.submit()">
                                <option value="">- All Requesters -</option>
                                <?php
                                while($user = mysqli_fetch_assoc($users_sql)) {
                                    $user_id = intval($user['user_id']);
                                    $user_name = nullable_htmlentities($user['user_name']);
                                    $selected = $requested_by_filter == $user_id ? 'selected' : '';
                                    echo "<option value='$user_id' $selected>$user_name</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Approved By</label>
                            <select class="form-control select2" name="approved_by" onchange="this.form.submit()">
                                <option value="">- All Approvers -</option>
                                <?php
                                mysqli_data_seek($users_sql, 0); // Reset pointer
                                while($user = mysqli_fetch_assoc($users_sql)) {
                                    $user_id = intval($user['user_id']);
                                    $user_name = nullable_htmlentities($user['user_name']);
                                    $selected = $approved_by_filter == $user_id ? 'selected' : '';
                                    echo "<option value='$user_id' $selected>$user_name</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>&nbsp;</label>
                            <a href="inventory_purchase_orders.php" class="btn btn-secondary btn-block">Clear Filters</a>
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
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=po.po_number&order=<?php echo $disp; ?>">
                        PO Number <?php if ($sort == 'po.po_number') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=po.po_date&order=<?php echo $disp; ?>">
                        Date <?php if ($sort == 'po.po_date') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>Supplier</th>
                <th>Delivery Location</th>
                <th class="text-center">Items</th>
                <th class="text-center">Received</th>
                <th class="text-center">Status</th>
                <th class="text-center">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php
            while ($row = mysqli_fetch_array($sql)) {
                $purchase_order_id = intval($row['purchase_order_id']);
                $po_number = nullable_htmlentities($row['po_number']);
                $po_date = nullable_htmlentities($row['po_date']);
                $expected_delivery_date = nullable_htmlentities($row['expected_delivery_date']);
                $notes = nullable_htmlentities($row['notes']);
                $supplier_name = nullable_htmlentities($row['supplier_name']);
                $supplier_contact = nullable_htmlentities($row['supplier_contact']);
                $location_name = nullable_htmlentities($row['location_name']);
                $location_type = nullable_htmlentities($row['location_type']);
                $requested_by_name = nullable_htmlentities($row['requested_by_name']);
                $approved_by_name = nullable_htmlentities($row['approved_by_name']);
                $approved_at = nullable_htmlentities($row['approved_at']);
                $status = nullable_htmlentities($row['status']);
                $total_estimated_amount = floatval($row['total_estimated_amount']);
                $item_count = intval($row['item_count']);
                $total_ordered = floatval($row['total_ordered']);
                $total_received = floatval($row['total_received']);
                $grn_count = intval($row['grn_count']);

                // Calculate received percentage
                $received_percentage = $total_ordered > 0 ? ($total_received / $total_ordered) * 100 : 0;
                
                // Check if today's PO
                $is_today = date('Y-m-d', strtotime($po_date)) == date('Y-m-d');
                
                // Check if overdue
                $is_overdue = false;
                if ($expected_delivery_date && $status != 'received' && $status != 'cancelled') {
                    $today = new DateTime();
                    $delivery_date = new DateTime($expected_delivery_date);
                    $is_overdue = $delivery_date < $today;
                }
                
                // Determine status badge
                $status_badge = '';
                $status_icon = '';
                switch($status) {
                    case 'draft':
                        $status_badge = 'badge-secondary';
                        $status_icon = 'file-alt';
                        break;
                    case 'submitted':
                        $status_badge = 'badge-info';
                        $status_icon = 'paper-plane';
                        break;
                    case 'approved':
                        $status_badge = 'badge-success';
                        $status_icon = 'check';
                        break;
                    case 'partially_received':
                        $status_badge = 'badge-warning';
                        $status_icon = 'truck-loading';
                        break;
                    case 'received':
                        $status_badge = 'badge-primary';
                        $status_icon = 'check-double';
                        break;
                    case 'cancelled':
                        $status_badge = 'badge-danger';
                        $status_icon = 'times';
                        break;
                    default:
                        $status_badge = 'badge-light';
                        $status_icon = 'question';
                }
                ?>
                <tr class="<?php echo $is_today ? 'table-info' : ''; ?><?php echo $is_overdue ? ' table-danger' : ''; ?>">
                    <td>
                        <div class="font-weight-bold text-primary"><?php echo $po_number; ?></div>
                        <?php if ($total_estimated_amount > 0): ?>
                            <small class="text-success">$<?php echo number_format($total_estimated_amount, 2); ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="font-weight-bold"><?php echo date('M j, Y', strtotime($po_date)); ?></div>
                        <?php if ($expected_delivery_date): ?>
                            <small class="<?php echo $is_overdue ? 'text-danger' : 'text-muted'; ?>">
                                Expected: <?php echo date('M j', strtotime($expected_delivery_date)); ?>
                                <?php if ($is_overdue): ?>
                                    <i class="fas fa-exclamation-triangle ml-1"></i>
                                <?php endif; ?>
                            </small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="font-weight-bold"><?php echo $supplier_name; ?></div>
                        <?php if ($supplier_contact): ?>
                            <small class="text-muted">Contact: <?php echo $supplier_contact; ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="font-weight-bold"><?php echo $location_name; ?></div>
                        <small class="text-muted"><?php echo $location_type; ?></small>
                    </td>
                    <td class="text-center">
                        <span class="badge badge-primary badge-pill"><?php echo $item_count; ?></span>
                        <div class="small text-muted mt-1">
                            <?php echo number_format($total_ordered, 3); ?> ordered
                        </div>
                    </td>
                    <td class="text-center">
                        <?php if ($total_ordered > 0): ?>
                            <div class="progress" style="height: 20px;">
                                <div class="progress-bar <?php echo $received_percentage == 100 ? 'bg-success' : ($received_percentage > 0 ? 'bg-warning' : 'bg-secondary'); ?>" 
                                     role="progressbar" 
                                     style="width: <?php echo min($received_percentage, 100); ?>%;" 
                                     aria-valuenow="<?php echo $received_percentage; ?>" 
                                     aria-valuemin="0" 
                                     aria-valuemax="100">
                                    <?php echo number_format($received_percentage, 0); ?>%
                                </div>
                            </div>
                            <small class="text-muted">
                                <?php echo number_format($total_received, 3); ?> received
                                <?php if ($grn_count > 0): ?>
                                    <br><span class="text-info"><?php echo $grn_count; ?> GRN(s)</span>
                                <?php endif; ?>
                            </small>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <span class="badge <?php echo $status_badge; ?>">
                            <i class="fas fa-<?php echo $status_icon; ?> mr-1"></i>
                            <?php echo ucfirst(str_replace('_', ' ', $status)); ?>
                        </span>
                        <?php if ($approved_by_name): ?>
                            <div class="small text-muted mt-1">
                                Approved by: <?php echo $approved_by_name; ?><br>
                                <?php if ($approved_at): ?>
                                    <?php echo date('M j', strtotime($approved_at)); ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="btn-group" role="group">
                            <a href="inventory_purchase_order_view.php?id=<?php echo $purchase_order_id; ?>" 
                               class="btn btn-sm btn-info" title="View Details">
                                <i class="fas fa-eye"></i>
                            </a>
                            <a href="inventory_purchase_order_print.php?id=<?php echo $purchase_order_id; ?>" 
                               class="btn btn-sm btn-secondary" title="Print" target="_blank">
                                <i class="fas fa-print"></i>
                            </a>
                            <?php if ($status == 'draft'): ?>
                                <a href="inventory_purchase_order_edit.php?id=<?php echo $purchase_order_id; ?>" 
                                   class="btn btn-sm btn-warning" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                            <?php endif; ?>
                            <?php if (in_array($status, ['approved', 'partially_received']) && hasPermission('inventory_grn_create')): ?>
                                <a href="inventory_grn_create.php?po_id=<?php echo $purchase_order_id; ?>" 
                                   class="btn btn-sm btn-success" title="Create GRN">
                                    <i class="fas fa-receipt"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php
            } 

            if ($num_rows[0] == 0) {
                ?>
                <tr>
                    <td colspan="8" class="text-center py-5">
                        <i class="fas fa-clipboard-check fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No Purchase Orders Found</h5>
                        <p class="text-muted">No purchase orders match your current filters.</p>
                        <a href="inventory_purchase_order_create.php" class="btn btn-primary mt-2">
                            <i class="fas fa-plus mr-2"></i>Create First Purchase Order
                        </a>
                        <a href="inventory_purchase_orders.php" class="btn btn-secondary mt-2 ml-2">
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
    
    // Highlight overdue POs
    $('.table-danger').each(function() {
        $(this).find('.text-danger').addClass('font-weight-bold');
        $(this).find('i.fa-exclamation-triangle').addClass('fa-beat');
    });
    
    // Blink draft POs
    setInterval(function() {
        $('.badge-secondary').closest('tr').fadeOut(100).fadeIn(100);
    }, 5000);
});

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + N for new PO
    if (e.ctrlKey && e.keyCode === 78) {
        e.preventDefault();
        window.location.href = 'inventory_purchase_order_create.php';
    }
    // Ctrl + F for focus search
    if (e.ctrlKey && e.keyCode === 70) {
        e.preventDefault();
        $('input[name="q"]').focus();
    }
    // Ctrl + G for GRNs
    if (e.ctrlKey && e.keyCode === 71) {
        e.preventDefault();
        window.location.href = 'inventory_grns.php';
    }
    // Escape to clear filters
    if (e.keyCode === 27) {
        window.location.href = 'inventory_purchase_orders.php';
    }
    // Ctrl + S for suppliers
    if (e.ctrlKey && e.keyCode === 83) {
        e.preventDefault();
        window.location.href = 'inventory_suppliers.php';
    }
});
</script>

<style>
.table-hover tbody tr:hover {
    background-color: rgba(0, 123, 255, 0.075);
}

.badge-pill {
    padding: 0.35em 0.65em;
    font-size: 0.85em;
}

.btn-group .btn-sm {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
    line-height: 1.5;
}

.progress {
    margin-bottom: 5px;
}

.table-danger {
    background-color: rgba(220, 53, 69, 0.1);
}

.fa-beat {
    animation: fa-beat 1s infinite;
}

@keyframes fa-beat {
    0% { transform: scale(1); }
    50% { transform: scale(1.2); }
    100% { transform: scale(1); }
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
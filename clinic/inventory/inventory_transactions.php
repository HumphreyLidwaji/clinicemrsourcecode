<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Default Column Sortby/Order Filter
$sort = "it.created_at";
$order = "DESC";

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Filter parameters
$type_filter = $_GET['type'] ?? '';
$item_filter = $_GET['item'] ?? '';
$user_filter = $_GET['user'] ?? '';
$location_filter = $_GET['location'] ?? '';
$requisition_filter = $_GET['requisition'] ?? '';
$batch_filter = $_GET['batch'] ?? '';

// Date Range Filter
$dtf = sanitizeInput($_GET['dtf'] ?? '');
$dtt = sanitizeInput($_GET['dtt'] ?? '');
$canned_date = $_GET['canned_date'] ?? '';

if (!empty($dtf) && !empty($dtt)) {
    $date_query = "AND DATE(it.created_at) BETWEEN '$dtf' AND '$dtt'";
} else if (!empty($canned_date)) {
    switch($canned_date) {
        case 'today':
            $date_query = "AND DATE(it.created_at) = CURDATE()";
            break;
        case 'yesterday':
            $date_query = "AND DATE(it.created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
            break;
        case 'thisweek':
            $date_query = "AND YEARWEEK(it.created_at, 1) = YEARWEEK(CURDATE(), 1)";
            break;
        case 'lastweek':
            $date_query = "AND YEARWEEK(it.created_at, 1) = YEARWEEK(CURDATE(), 1) - 1";
            break;
        case 'thismonth':
            $date_query = "AND MONTH(it.created_at) = MONTH(CURDATE()) 
                           AND YEAR(it.created_at) = YEAR(CURDATE())";
            break;
        case 'lastmonth':
            $date_query = "AND MONTH(it.created_at) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) 
                           AND YEAR(it.created_at) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))";
            break;
        default:
            $date_query = '';
    }
} else {
    $date_query = '';
}

// Type Filter - UPDATED for new transaction types
if ($type_filter) {
    $type_query = "AND it.transaction_type = '" . sanitizeInput($type_filter) . "'";
} else {
    $type_query = '';
}

// Item Filter
if ($item_filter) {
    $item_query = "AND it.item_id = " . intval($item_filter);
} else {
    $item_query = '';
}

// User Filter
if ($user_filter) {
    $user_query = "AND it.created_by = " . intval($user_filter);
} else {
    $user_query = '';
}

// Location Filter - Enhanced to include from and to locations
if ($location_filter) {
    $location_query = "AND (it.from_location_id = " . intval($location_filter) . " 
                          OR it.to_location_id = " . intval($location_filter) . ")";
} else {
    $location_query = '';
}

// Requisition Filter
if ($requisition_filter) {
    $requisition_query = "AND it.reference_type = 'requisition' AND it.reference_id = " . intval($requisition_filter);
} else {
    $requisition_query = '';
}

// Batch Filter
if ($batch_filter) {
    $batch_query = "AND it.batch_id = " . intval($batch_filter);
} else {
    $batch_query = '';
}

// Search Query
$q = sanitizeInput($_GET['q'] ?? '');
if (!empty($q)) {
    $search_query = "AND (
        ii.item_name LIKE '%$q%' 
        OR ii.item_code LIKE '%$q%'
        OR ib.batch_number LIKE '%$q%'
        OR it.reason LIKE '%$q%'
        OR u.user_name LIKE '%$q%'
        OR u.user_name LIKE '%$q%'
        OR fl.location_name LIKE '%$q%'
        OR tl.location_name LIKE '%$q%'
        OR ir.requisition_number LIKE '%$q%'
        OR ipo.po_number LIKE '%$q%'
    )";
} else {
    $search_query = '';
}

// Main query for inventory transactions - UPDATED for new schema
$sql = mysqli_query(
    $mysqli,
    "
    SELECT SQL_CALC_FOUND_ROWS 
        it.*,
        ii.item_id,
        ii.item_name,
        ii.item_code,
        ii.unit_of_measure,
        ic.category_name,
        ib.batch_id,
        ib.batch_number,
        ib.expiry_date,
        fl.location_id as from_location_id,
        fl.location_name as from_location_name,
        fl.location_type as from_location_type,
        tl.location_id as to_location_id,
        tl.location_name as to_location_name,
        tl.location_type as to_location_type,
        u.user_id,
        u.user_name,
        u.user_name as performed_by_name,
        ir.requisition_id,
        ir.requisition_number,
        ipo.purchase_order_id,
        ipo.po_number,
        s.supplier_id,
        s.supplier_name,
        grn.grn_id,
        grn.grn_number
    FROM inventory_transactions it
    INNER JOIN inventory_items ii ON it.item_id = ii.item_id
    LEFT JOIN inventory_categories ic ON ii.category_id = ic.category_id
    LEFT JOIN inventory_batches ib ON it.batch_id = ib.batch_id
    LEFT JOIN inventory_locations fl ON it.from_location_id = fl.location_id
    LEFT JOIN inventory_locations tl ON it.to_location_id = tl.location_id
    LEFT JOIN users u ON it.created_by = u.user_id
    LEFT JOIN inventory_requisitions ir ON (it.reference_type = 'requisition' AND it.reference_id = ir.requisition_id)
    LEFT JOIN inventory_purchase_orders ipo ON (it.reference_type = 'purchase_order' AND it.reference_id = ipo.purchase_order_id)
    LEFT JOIN inventory_grns grn ON (it.reference_type = 'grn' AND it.reference_id = grn.grn_id)
    LEFT JOIN suppliers s ON ipo.supplier_id = s.supplier_id
    WHERE it.is_active = 1
      $type_query
      $item_query
      $user_query
      $location_query
      $requisition_query
      $batch_query
      $date_query
      $search_query
    ORDER BY $sort $order
    LIMIT $record_from, $record_to
");

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get statistics with enhanced location data
$total_transactions = $num_rows[0];
$grn_count = 0;
$issue_count = 0;
$transfer_count = 0;
$adjustment_count = 0;
$wastage_count = 0;
$return_count = 0;
$total_quantity_in = 0;
$total_quantity_out = 0;
$today_transactions = 0;
$week_transactions = 0;

// Reset pointer and calculate
mysqli_data_seek($sql, 0);
while ($transaction = mysqli_fetch_assoc($sql)) {
    $transaction_date = strtotime($transaction['created_at']);
    
    // Count by transaction type - UPDATED for new types
    switch($transaction['transaction_type']) {
        case 'GRN':
            $grn_count++;
            $total_quantity_in += abs($transaction['quantity']);
            break;
        case 'ISSUE':
            $issue_count++;
            $total_quantity_out += abs($transaction['quantity']);
            break;
        case 'TRANSFER_OUT':
        case 'TRANSFER_IN':
            $transfer_count++;
            if ($transaction['transaction_type'] == 'TRANSFER_IN') {
                $total_quantity_in += abs($transaction['quantity']);
            } else {
                $total_quantity_out += abs($transaction['quantity']);
            }
            break;
        case 'ADJUSTMENT':
            $adjustment_count++;
            if ($transaction['quantity'] > 0) {
                $total_quantity_in += abs($transaction['quantity']);
            } else {
                $total_quantity_out += abs($transaction['quantity']);
            }
            break;
        case 'WASTAGE':
            $wastage_count++;
            $total_quantity_out += abs($transaction['quantity']);
            break;
        case 'RETURN':
            $return_count++;
            $total_quantity_in += abs($transaction['quantity']);
            break;
    }
    
    // Count today's transactions
    if (date('Y-m-d', $transaction_date) == date('Y-m-d')) {
        $today_transactions++;
    }
    
    // Count this week's transactions
    $week_start = strtotime('monday this week');
    $week_end = strtotime('sunday this week');
    if ($transaction_date >= $week_start && $transaction_date <= $week_end) {
        $week_transactions++;
    }
}
mysqli_data_seek($sql, $record_from);

// Get unique items for filter
$items_sql = mysqli_query($mysqli, "
    SELECT item_id, item_name, item_code 
    FROM inventory_items 
    WHERE is_active = 1 AND status = 'active'
    ORDER BY item_name
");

// Get unique users for filter
$users_sql = mysqli_query($mysqli, "
    SELECT user_id, user_name
    FROM users 
");

// Get unique locations for filter
$locations_sql = mysqli_query($mysqli, "
    SELECT location_id, location_name, location_type 
    FROM inventory_locations 
    WHERE is_active = 1 
    ORDER BY location_type, location_name
");

// Get unique requisitions for filter
$requisitions_sql = mysqli_query($mysqli, "
    SELECT requisition_id, requisition_number 
    FROM inventory_requisitions 
    WHERE is_active = 1
    ORDER BY requisition_date DESC 
    LIMIT 100
");

// Get unique batches for filter
$batches_sql = mysqli_query($mysqli, "
    SELECT DISTINCT ib.batch_id, ib.batch_number, ii.item_name
    FROM inventory_batches ib
    INNER JOIN inventory_items ii ON ib.item_id = ii.item_id
    WHERE ib.is_active = 1
    ORDER BY ib.batch_number
    LIMIT 100
");

// Get today's summary - UPDATED for new types
$today_summary_sql = "SELECT 
    COUNT(*) as count,
    SUM(CASE WHEN transaction_type = 'GRN' OR transaction_type = 'RETURN' THEN quantity ELSE 0 END) as today_in,
    SUM(CASE WHEN transaction_type = 'ISSUE' OR transaction_type = 'WASTAGE' THEN ABS(quantity) ELSE 0 END) as today_out,
    SUM(CASE WHEN transaction_type LIKE 'TRANSFER%' THEN 1 ELSE 0 END) as today_transfers
    FROM inventory_transactions 
    WHERE DATE(created_at) = CURDATE() AND is_active = 1";
$today_summary_result = mysqli_query($mysqli, $today_summary_sql);
$today_summary = mysqli_fetch_assoc($today_summary_result);
?>

<div class="card">
    <div class="card-header bg-info py-2">
        <h3 class="card-title mt-2 mb-0 text-white"><i class="fas fa-fw fa-exchange-alt mr-2"></i>Inventory Transactions</h3>
        <div class="card-tools">
            <div class="btn-group">
                <a href="inventory_grn_create.php" class="btn btn-success">
                    <i class="fas fa-arrow-down mr-2"></i>Create GRN
                </a>
                <a href="inventory_issue_create.php" class="btn btn-danger">
                    <i class="fas fa-arrow-up mr-2"></i>Issue Items
                </a>
                <a href="inventory_transfer_create.php" class="btn btn-primary">
                    <i class="fas fa-truck-moving mr-2"></i>Transfer Items
                </a>
                <button type="button" class="btn btn-info dropdown-toggle dropdown-toggle-split" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <span class="sr-only">Toggle Dropdown</span>
                </button>
                <div class="dropdown-menu dropdown-menu-right">
                    <a class="dropdown-item" href="inventory_stock_adjust.php">
                        <i class="fas fa-adjust mr-2"></i>Stock Adjustment
                    </a>
                    <a class="dropdown-item" href="inventory_stock_wastage.php">
                        <i class="fas fa-trash mr-2"></i>Record Wastage
                    </a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="inventory_grns.php">
                        <i class="fas fa-receipt mr-2"></i>View GRNs
                    </a>
                    <a class="dropdown-item" href="inventory_transfers.php">
                        <i class="fas fa-list mr-2"></i>View Transfers
                    </a>
                    <a class="dropdown-item" href="inventory_requisitions.php">
                        <i class="fas fa-clipboard-list mr-2"></i>View Requisitions
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Quick Stats Bar -->
    <div class="card-header bg-light py-2">
        <div class="row text-center">
            <div class="col-md-2">
                <div class="small text-muted">Total Transactions</div>
                <div class="h5 font-weight-bold text-primary"><?php echo number_format($total_transactions); ?></div>
            </div>
            <div class="col-md-2">
                <div class="small text-muted">GRN</div>
                <div class="h5 font-weight-bold text-success">
                    <?php echo $grn_count; ?>
                    <small class="text-muted">(<?php echo number_format($total_quantity_in, 3); ?>)</small>
                </div>
            </div>
            <div class="col-md-2">
                <div class="small text-muted">Issues</div>
                <div class="h5 font-weight-bold text-danger">
                    <?php echo $issue_count; ?>
                    <small class="text-muted">(<?php echo number_format($total_quantity_out, 3); ?>)</small>
                </div>
            </div>
            <div class="col-md-2">
                <div class="small text-muted">Transfers</div>
                <div class="h5 font-weight-bold text-warning"><?php echo $transfer_count; ?></div>
            </div>
            <div class="col-md-2">
                <div class="small text-muted">Today</div>
                <div class="h5 font-weight-bold text-info"><?php echo $today_transactions; ?></div>
            </div>
            <div class="col-md-2">
                <div class="small text-muted">This Week</div>
                <div class="h5 font-weight-bold text-dark"><?php echo $week_transactions; ?></div>
            </div>
        </div>
        <div class="row text-center mt-2">
            <div class="col-md-3">
                <div class="small text-muted">Adjustments</div>
                <div class="h6 font-weight-bold text-secondary"><?php echo $adjustment_count; ?></div>
            </div>
            <div class="col-md-3">
                <div class="small text-muted">Wastage</div>
                <div class="h6 font-weight-bold text-dark"><?php echo $wastage_count; ?></div>
            </div>
            <div class="col-md-3">
                <div class="small text-muted">Returns</div>
                <div class="h6 font-weight-bold text-primary"><?php echo $return_count; ?></div>
            </div>
            <div class="col-md-3">
                <div class="small text-muted">Net Movement</div>
                <div class="h6 font-weight-bold <?php echo ($total_quantity_in - $total_quantity_out) >= 0 ? 'text-success' : 'text-danger'; ?>">
                    <?php echo number_format($total_quantity_in - $total_quantity_out, 3); ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card-header pb-2 pt-3">
        <form autocomplete="off">
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search items, batches, locations, references..." autofocus>
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
                            <span class="btn btn-light border" data-toggle="tooltip" title="Total Transactions">
                                <i class="fas fa-exchange-alt text-primary mr-1"></i>
                                <strong><?php echo $total_transactions; ?></strong>
                            </span>
                            <span class="btn btn-light border" data-toggle="tooltip" title="GRN Transactions">
                                <i class="fas fa-receipt text-success mr-1"></i>
                                <strong><?php echo $grn_count; ?></strong>
                            </span>
                            <span class="btn btn-light border" data-toggle="tooltip" title="Issue Transactions">
                                <i class="fas fa-share text-danger mr-1"></i>
                                <strong><?php echo $issue_count; ?></strong>
                            </span>
                            <span class="btn btn-light border" data-toggle="tooltip" title="Transfer Transactions">
                                <i class="fas fa-sync-alt text-warning mr-1"></i>
                                <strong><?php echo $transfer_count; ?></strong>
                            </span>
                            <a href="?<?php echo $url_query_strings_sort ?>&export=csv" class="btn btn-light border ml-2">
                                <i class="fa fa-fw fa-file-csv mr-2"></i>Export
                            </a>
                            <a href="inventory_dashboard.php" class="btn btn-warning ml-2">
                                <i class="fas fa-tachometer-alt mr-2"></i>Dashboard
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php 
                if (isset($_GET['dtf']) || $type_filter || $item_filter || $user_filter || $location_filter || $requisition_filter || $batch_filter || $canned_date) { 
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
                            <label>Transaction Type</label>
                            <select class="form-control select2" name="type" onchange="this.form.submit()">
                                <option value="">- All Types -</option>
                                <option value="GRN" <?php if ($type_filter == "GRN") { echo "selected"; } ?>>Goods Receipt Note (GRN)</option>
                                <option value="ISSUE" <?php if ($type_filter == "ISSUE") { echo "selected"; } ?>>Issue</option>
                                <option value="TRANSFER_OUT" <?php if ($type_filter == "TRANSFER_OUT") { echo "selected"; } ?>>Transfer Out</option>
                                <option value="TRANSFER_IN" <?php if ($type_filter == "TRANSFER_IN") { echo "selected"; } ?>>Transfer In</option>
                                <option value="ADJUSTMENT" <?php if ($type_filter == "ADJUSTMENT") { echo "selected"; } ?>>Adjustment</option>
                                <option value="WASTAGE" <?php if ($type_filter == "WASTAGE") { echo "selected"; } ?>>Wastage</option>
                                <option value="RETURN" <?php if ($type_filter == "RETURN") { echo "selected"; } ?>>Return</option>
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
                            <label>Performed By</label>
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
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Location</label>
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
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Requisition</label>
                            <select class="form-control select2" name="requisition" onchange="this.form.submit()">
                                <option value="">- All Requisitions -</option>
                                <?php
                                while($requisition = mysqli_fetch_assoc($requisitions_sql)) {
                                    $requisition_id = intval($requisition['requisition_id']);
                                    $requisition_number = nullable_htmlentities($requisition['requisition_number']);
                                    $selected = $requisition_filter == $requisition_id ? 'selected' : '';
                                    echo "<option value='$requisition_id' $selected>$requisition_number</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Batch</label>
                            <select class="form-control select2" name="batch" onchange="this.form.submit()">
                                <option value="">- All Batches -</option>
                                <?php
                                while($batch = mysqli_fetch_assoc($batches_sql)) {
                                    $batch_id = intval($batch['batch_id']);
                                    $batch_display = nullable_htmlentities($batch['batch_number'] . ' - ' . $batch['item_name']);
                                    $selected = $batch_filter == $batch_id ? 'selected' : '';
                                    echo "<option value='$batch_id' $selected>$batch_display</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>&nbsp;</label>
                            <a href="inventory_transactions.php" class="btn btn-secondary btn-block">Clear Filters</a>
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
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=it.created_at&order=<?php echo $disp; ?>">
                        Date & Time <?php if ($sort == 'it.created_at') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=ii.item_name&order=<?php echo $disp; ?>">
                        Item <?php if ($sort == 'ii.item_name') { echo $order_icon; } ?>
                    </a>
                </th>
                <th class="text-center">Transaction Type</th>
                <th class="text-center">
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=it.quantity&order=<?php echo $disp; ?>">
                        Quantity <?php if ($sort == 'it.quantity') { echo $order_icon; } ?>
                    </a>
                </th>
                <th class="text-center">Locations</th>
                <th>Reference</th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=u.user_name&order=<?php echo $disp; ?>">
                        Performed By <?php if ($sort == 'u.user_name') { echo $order_icon; } ?>
                    </a>
                </th>
                <th class="text-center">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php
            while ($row = mysqli_fetch_array($sql)) {
                $transaction_id = intval($row['transaction_id']);
                $item_id = intval($row['item_id']);
                $item_name = nullable_htmlentities($row['item_name']);
                $item_code = nullable_htmlentities($row['item_code']);
                $unit_of_measure = nullable_htmlentities($row['unit_of_measure']);
                $category_name = nullable_htmlentities($row['category_name']);
                $transaction_type = nullable_htmlentities($row['transaction_type']);
                $quantity = floatval($row['quantity']);
                $unit_cost = floatval($row['unit_cost']);
                $reason = nullable_htmlentities($row['reason']);
                $created_at = nullable_htmlentities($row['created_at']);
                $performed_by_name = nullable_htmlentities($row['performed_by_name']);
                $batch_number = nullable_htmlentities($row['batch_number']);
                $expiry_date = nullable_htmlentities($row['expiry_date']);
                $requisition_number = nullable_htmlentities($row['requisition_number']);
                $requisition_id = intval($row['requisition_id']);
                $po_number = nullable_htmlentities($row['po_number']);
                $purchase_order_id = intval($row['purchase_order_id']);
                $grn_number = nullable_htmlentities($row['grn_number']);
                $grn_id = intval($row['grn_id']);
                $supplier_name = nullable_htmlentities($row['supplier_name']);
                $from_location_name = nullable_htmlentities($row['from_location_name']);
                $from_location_type = nullable_htmlentities($row['from_location_type']);
                $to_location_name = nullable_htmlentities($row['to_location_name']);
                $to_location_type = nullable_htmlentities($row['to_location_type']);

                // Determine transaction type color, icon and text
                $type_color = 'secondary';
                $type_icon = 'exchange-alt';
                $display_type = $transaction_type;
                
                switch($transaction_type) {
                    case 'GRN':
                        $type_color = 'success';
                        $type_icon = 'receipt';
                        $display_type = 'GRN';
                        break;
                    case 'ISSUE':
                        $type_color = 'danger';
                        $type_icon = 'share';
                        $display_type = 'Issue';
                        break;
                    case 'TRANSFER_OUT':
                        $type_color = 'warning';
                        $type_icon = 'sign-out-alt';
                        $display_type = 'Transfer Out';
                        break;
                    case 'TRANSFER_IN':
                        $type_color = 'info';
                        $type_icon = 'sign-in-alt';
                        $display_type = 'Transfer In';
                        break;
                    case 'ADJUSTMENT':
                        $type_color = 'secondary';
                        $type_icon = 'adjust';
                        $display_type = 'Adjustment';
                        break;
                    case 'WASTAGE':
                        $type_color = 'dark';
                        $type_icon = 'trash';
                        $display_type = 'Wastage';
                        break;
                    case 'RETURN':
                        $type_color = 'primary';
                        $type_icon = 'undo';
                        $display_type = 'Return';
                        break;
                }

                // Determine quantity color
                $quantity_color = 'dark';
                if ($quantity > 0 && in_array($transaction_type, ['GRN', 'TRANSFER_IN', 'RETURN', 'ADJUSTMENT'])) {
                    $quantity_color = 'success';
                } elseif ($quantity < 0 || in_array($transaction_type, ['ISSUE', 'TRANSFER_OUT', 'WASTAGE'])) {
                    $quantity_color = 'danger';
                }

                // Check if today's transaction
                $is_today = date('Y-m-d', strtotime($created_at)) == date('Y-m-d');
                
                // Format reference
                $reference_display = '';
                if ($requisition_number) {
                    $reference_display = "Requisition: $requisition_number";
                } elseif ($po_number) {
                    $reference_display = "PO: $po_number";
                } elseif ($grn_number) {
                    $reference_display = "GRN: $grn_number";
                } elseif ($reason) {
                    $reference_display = $reason;
                }
                ?>
                <tr class="<?php echo $is_today ? 'table-info' : ''; ?>">
                    <td>
                        <div class="font-weight-bold"><?php echo date('M j, Y', strtotime($created_at)); ?></div>
                        <small class="text-muted"><?php echo date('H:i', strtotime($created_at)); ?></small>
                        <?php if($is_today): ?>
                            <div><small class="badge badge-success">Today</small></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="font-weight-bold"><?php echo $item_name; ?></div>
                        <small class="text-muted">
                            <?php echo $item_code; ?>
                            <?php if ($category_name): ?>
                                • <?php echo $category_name; ?>
                            <?php endif; ?>
                            <?php if ($batch_number): ?>
                                <br><i class="fas fa-box text-info mr-1"></i>Batch: <?php echo $batch_number; ?>
                                <?php if ($expiry_date): ?>
                                    <small class="text-muted">(Exp: <?php echo date('M Y', strtotime($expiry_date)); ?>)</small>
                                <?php endif; ?>
                            <?php endif; ?>
                        </small>
                    </td>
                    <td class="text-center">
                        <span class="badge badge-<?php echo $type_color; ?>">
                            <i class="fas fa-<?php echo $type_icon; ?> mr-1"></i>
                            <?php echo $display_type; ?>
                        </span>
                        <?php if ($supplier_name && $transaction_type == 'GRN'): ?>
                            <div class="mt-1">
                                <small class="text-info">
                                    <i class="fas fa-truck mr-1"></i>
                                    <?php echo $supplier_name; ?>
                                </small>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <div class="font-weight-bold text-<?php echo $quantity_color; ?>">
                            <?php echo $quantity > 0 ? '+' : ''; ?><?php echo number_format($quantity, 3); ?>
                        </div>
                        <small class="text-muted"><?php echo $unit_of_measure; ?></small>
                        <?php if ($unit_cost > 0): ?>
                            <div class="small text-muted">
                                $<?php echo number_format($unit_cost, 4); ?>/unit
                            </div>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?php if ($from_location_name || $to_location_name): ?>
                            <div class="small">
                                <?php if ($from_location_name): ?>
                                    <div class="text-danger mb-1">
                                        <i class="fas fa-arrow-left mr-1"></i>
                                        <?php echo $from_location_type . ': ' . $from_location_name; ?>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($to_location_name): ?>
                                    <div class="text-success">
                                        <i class="fas fa-arrow-right mr-1"></i>
                                        <?php echo $to_location_type . ': ' . $to_location_name; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($reference_display): ?>
                            <div class="font-weight-bold"><?php echo $reference_display; ?></div>
                        <?php endif; ?>
                        
                        <?php if ($reason && !$reference_display): ?>
                            <small class="text-muted" data-toggle="tooltip" title="<?php echo htmlspecialchars($reason); ?>">
                                <i class="fas fa-sticky-note mr-1"></i><?php echo truncate($reason, 40); ?>
                            </small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="font-weight-bold"><?php echo $performed_by_name; ?></div>
                    </td>
                    <td>
                        <div class="dropdown dropleft text-center">
                            <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                <i class="fas fa-ellipsis-h"></i>
                            </button>
                            <div class="dropdown-menu">
                                <a class="dropdown-item" href="inventory_item_view.php?id=<?php echo $item_id; ?>">
                                    <i class="fas fa-fw fa-cube mr-2"></i>View Item
                                </a>
                                <?php if ($batch_number): ?>
                                    <a class="dropdown-item" href="inventory_batch_view.php?id=<?php echo $row['batch_id']; ?>">
                                        <i class="fas fa-fw fa-box mr-2"></i>View Batch
                                    </a>
                                <?php endif; ?>
                                <?php if ($requisition_id): ?>
                                    <a class="dropdown-item" href="inventory_requisition_view.php?id=<?php echo $requisition_id; ?>">
                                        <i class="fas fa-fw fa-clipboard-list mr-2"></i>View Requisition
                                    </a>
                                <?php endif; ?>
                                <?php if ($purchase_order_id): ?>
                                    <a class="dropdown-item" href="inventory_purchase_order_view.php?id=<?php echo $purchase_order_id; ?>">
                                        <i class="fas fa-fw fa-shopping-cart mr-2"></i>View Purchase Order
                                    </a>
                                <?php endif; ?>
                                <?php if ($grn_id): ?>
                                    <a class="dropdown-item" href="inventory_grn_view.php?id=<?php echo $grn_id; ?>">
                                        <i class="fas fa-fw fa-receipt mr-2"></i>View GRN
                                    </a>
                                <?php endif; ?>
                                <?php if ($from_location_name): ?>
                                    <a class="dropdown-item" href="inventory_location_view.php?id=<?php echo $row['from_location_id']; ?>">
                                        <i class="fas fa-fw fa-map-marker-alt mr-2"></i>From Location
                                    </a>
                                <?php endif; ?>
                                <?php if ($to_location_name): ?>
                                    <a class="dropdown-item" href="inventory_location_view.php?id=<?php echo $row['to_location_id']; ?>">
                                        <i class="fas fa-fw fa-map-marker-alt mr-2"></i>To Location
                                    </a>
                                <?php endif; ?>
                                <?php if ($reason): ?>
                                    <a class="dropdown-item" href="#" onclick="showTransactionReason(<?php echo $transaction_id; ?>, '<?php echo addslashes($reason); ?>')">
                                        <i class="fas fa-fw fa-sticky-note mr-2"></i>View Reason
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
                    <td colspan="8" class="text-center py-5">
                        <i class="fas fa-exchange-alt fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No Transactions Found</h5>
                        <p class="text-muted">No transactions match your current filters.</p>
                        <a href="inventory_grn_create.php" class="btn btn-success mt-2">
                            <i class="fas fa-receipt mr-2"></i>Create GRN
                        </a>
                        <a href="inventory_transactions.php" class="btn btn-secondary mt-2 ml-2">
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

<!-- Transaction Reason Modal -->
<div class="modal fade" id="transactionReasonModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-sticky-note mr-2"></i>Transaction Reason</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <p id="transactionReasonContent" class="mb-0"></p>
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

function showTransactionReason(transactionId, reason) {
    $('#transactionReasonContent').text(reason);
    $('#transactionReasonModal').modal('show');
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + G for GRN
    if (e.ctrlKey && e.keyCode === 71) {
        e.preventDefault();
        window.location.href = 'inventory_grn_create.php';
    }
    // Ctrl + I for Issue
    if (e.ctrlKey && e.keyCode === 73) {
        e.preventDefault();
        window.location.href = 'inventory_issue_create.php';
    }
    // Ctrl + F for focus search
    if (e.ctrlKey && e.keyCode === 70) {
        e.preventDefault();
        $('input[name="q"]').focus();
    }
    // Escape to clear filters
    if (e.keyCode === 27) {
        window.location.href = 'inventory_transactions.php';
    }
    // Ctrl + D for dashboard
    if (e.ctrlKey && e.keyCode === 68) {
        e.preventDefault();
        window.location.href = 'inventory_dashboard.php';
    }
});
</script>

<style>
.table-hover tbody tr:hover {
    background-color: rgba(0, 123, 255, 0.075);
}

.badge {
    font-size: 0.75em;
}

.dropdown-menu {
    min-width: 200px;
}

.select2-container .select2-selection--single {
    height: 38px;
}

.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 36px;
}

.table-sm {
    font-size: 0.875rem;
}

.table-sm td, .table-sm th {
    padding: 0.5rem;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
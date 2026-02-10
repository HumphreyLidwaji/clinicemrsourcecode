<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Default Column Sortby/Order Filter
$sort = "ret.return_date";
$order = "DESC";

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Filter parameters
$supplier_filter = $_GET['supplier'] ?? '';
$order_filter = $_GET['order'] ?? '';
$return_type_filter = $_GET['return_type'] ?? '';
$date_range_filter = $_GET['date_range'] ?? '';

// Date Range Filter
$dtf = sanitizeInput($_GET['dtf'] ?? '');
$dtt = sanitizeInput($_GET['dtt'] ?? '');

if (!empty($dtf) && !empty($dtt)) {
    $date_query = "AND DATE(ret.return_date) BETWEEN '$dtf' AND '$dtt'";
} else {
    $date_query = '';
}

// Supplier Filter
if ($supplier_filter) {
    $supplier_query = "AND po.supplier_id = " . intval($supplier_filter);
} else {
    $supplier_query = '';
}

// Order Filter
if ($order_filter) {
    $order_query = "AND ret.order_id = " . intval($order_filter);
} else {
    $order_query = '';
}

// Return Type Filter
if ($return_type_filter) {
    $return_type_query = "AND ret.return_type = '" . sanitizeInput($return_type_filter) . "'";
} else {
    $return_type_query = '';
}

// Search Query
$q = sanitizeInput($_GET['q'] ?? '');
if (!empty($q)) {
    $search_query = "AND (
        ret.return_number LIKE '%$q%' 
        OR po.order_number LIKE '%$q%'
        OR s.supplier_name LIKE '%$q%'
        OR ret.return_reason LIKE '%$q%'
        OR ret.notes LIKE '%$q%'
    )";
} else {
    $search_query = '';
}

$sql = mysqli_query(
    $mysqli,
    "
    SELECT 
        ret.*, 
        po.order_number, 
        po.order_date,
        s.supplier_name, 
        s.supplier_contact, 
        s.supplier_phone,
        u.user_name AS created_by_name,

        -- Count how many items are associated with this return
        (SELECT COUNT(*) 
         FROM return_items ri 
         WHERE ri.return_id = ret.return_id) AS item_count,

        -- Sum the total quantity returned
        (SELECT SUM(ri.quantity_returned) 
         FROM return_items ri 
         WHERE ri.return_id = ret.return_id) AS total_quantity,

        -- Sum the total cost of all returned items
        (SELECT SUM(ri.total_cost)
         FROM return_items ri
         WHERE ri.return_id = ret.return_id) AS total_cost

    FROM purchase_order_returns ret 
    LEFT JOIN purchase_orders po ON ret.order_id = po.order_id
    LEFT JOIN suppliers s ON po.supplier_id = s.supplier_id
    LEFT JOIN users u ON ret.created_by = u.user_id
    WHERE 1=1
      $supplier_query
      $order_query
      $return_type_query
      $date_query
      $search_query
    ORDER BY $sort $order
    LIMIT $record_from, $record_to
    "
);


$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get statistics
$total_returns = $num_rows[0];
$today_count = 0;
$week_count = 0;
$month_count = 0;
$total_return_value = 0;
$total_items_returned = 0;

// Return type counts
$refund_count = 0;
$replacement_count = 0;
$credit_note_count = 0;
$exchange_count = 0;

// Reset pointer and calculate
mysqli_data_seek($sql, 0);
while ($return = mysqli_fetch_assoc($sql)) {
    $return_date = new DateTime($return['return_date']);
    $today = new DateTime();
    $week_ago = (new DateTime())->modify('-7 days');
    $month_ago = (new DateTime())->modify('-30 days');
    
    if ($return_date->format('Y-m-d') == $today->format('Y-m-d')) {
        $today_count++;
    }
    if ($return_date >= $week_ago) {
        $week_count++;
    }
    if ($return_date >= $month_ago) {
        $month_count++;
    }
    
    // Count by return type
    switch($return['return_type']) {
        case 'refund':
            $refund_count++;
            break;
        case 'replacement':
            $replacement_count++;
            break;
        case 'credit_note':
            $credit_note_count++;
            break;
        case 'exchange':
            $exchange_count++;
            break;
    }
    
    $total_return_value += floatval($return['total_amount'] ?? 0);
    $total_items_returned += intval($return['total_quantity'] ?? 0);
}
mysqli_data_seek($sql, $record_from); // Reset pointer back to current page

// Get unique suppliers for filter
$suppliers_sql = mysqli_query($mysqli, "
    SELECT DISTINCT s.supplier_id, s.supplier_name 
    FROM suppliers s
    JOIN purchase_orders po ON s.supplier_id = po.supplier_id
    JOIN purchase_order_returns ret ON po.order_id = ret.order_id
    WHERE s.supplier_is_active = 1 
    ORDER BY s.supplier_name
");

// Get unique purchase orders for filter
$orders_sql = mysqli_query($mysqli, "
    SELECT DISTINCT po.order_id, po.order_number 
    FROM purchase_orders po
    JOIN purchase_order_returns ret ON po.order_id = ret.order_id
    ORDER BY po.order_date DESC
");

// Get recent returns for quick access
$recent_returns_sql = mysqli_query($mysqli, "
    SELECT ret.return_id, ret.return_number, po.order_number, s.supplier_name, 
           ret.return_date, ret.return_type, ret.total_amount
    FROM purchase_order_returns ret
    LEFT JOIN purchase_orders po ON ret.order_id = po.order_id
    LEFT JOIN suppliers s ON po.supplier_id = s.supplier_id
    ORDER BY ret.created_at DESC
    LIMIT 5
");
?>

<div class="card">
    <div class="card-header bg-warning py-2">
        <h3 class="card-title mt-2 mb-0"><i class="fas fa-fw fa-undo mr-2"></i>Purchase Order Returns</h3>
        <div class="card-tools">
            <a href="purchase_order_returns_create.php" class="btn btn-light">
                <i class="fas fa-plus mr-2"></i>New Return
            </a>
        </div>
    </div>
    
    <!-- Statistics Row -->
    <div class="card-body border-bottom">
        <div class="row text-center">
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-warning"><i class="fas fa-undo"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Returns</span>
                        <span class="info-box-number"><?php echo $total_returns; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-primary"><i class="fas fa-calendar-day"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Today</span>
                        <span class="info-box-number"><?php echo $today_count; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-info"><i class="fas fa-calendar-week"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">This Week</span>
                        <span class="info-box-number"><?php echo $week_count; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-danger"><i class="fas fa-money-bill-wave"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Value</span>
                        <span class="info-box-number">$<?php echo number_format($total_return_value, 2); ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-secondary"><i class="fas fa-cubes"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Items Returned</span>
                        <span class="info-box-number"><?php echo number_format($total_items_returned); ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-success"><i class="fas fa-exchange-alt"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Active</span>
                        <span class="info-box-number"><?php echo $total_returns; ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Return Type Breakdown -->
        <div class="row mt-4 text-center">
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-info"><i class="fas fa-money-bill"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Refunds</span>
                        <span class="info-box-number"><?php echo $refund_count; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-primary"><i class="fas fa-sync"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Replacements</span>
                        <span class="info-box-number"><?php echo $replacement_count; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-success"><i class="fas fa-file-invoice-dollar"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Credit Notes</span>
                        <span class="info-box-number"><?php echo $credit_note_count; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-warning"><i class="fas fa-retweet"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Exchanges</span>
                        <span class="info-box-number"><?php echo $exchange_count; ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card-header pb-2 pt-3">
        <form autocomplete="off">
            <div class="row">
                <div class="col-md-5">
                    <div class="form-group">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search return numbers, order numbers, suppliers, reasons..." autofocus>
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
                            <a href="?<?php echo $url_query_strings_sort ?>&export=pdf" class="btn btn-default">
                                <i class="fa fa-fw fa-file-pdf mr-2"></i>Export Report
                            </a>
                            <a href="purchase_order_returns_export.php" class="btn btn-default ml-2">
                                <i class="fas fa-file-excel mr-2"></i>Export to Excel
                            </a>
                            <a href="purchase_order_returns_print_all.php" class="btn btn-default ml-2" target="_blank">
                                <i class="fas fa-print mr-2"></i>Print All
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <div 
                class="collapse 
                    <?php 
                    if (
                    isset($_GET['dtf'])
                    || $supplier_filter
                    || $order_filter
                    || $return_type_filter
                    || ($_GET['canned_date'] ?? '') !== "custom" ) 
                    { 
                        echo "show"; 
                    } 
                    ?>
                "
                id="advancedFilter"
            >
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
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Purchase Order</label>
                            <select class="form-control select2" name="order" onchange="this.form.submit()">
                                <option value="">- All Orders -</option>
                                <?php
                                while($order = mysqli_fetch_assoc($orders_sql)) {
                                    $order_id = intval($order['order_id']);
                                    $order_number = nullable_htmlentities($order['order_number']);
                                    $selected = $order_filter == $order_id ? 'selected' : '';
                                    echo "<option value='$order_id' $selected>$order_number</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Return Type</label>
                            <select class="form-control select2" name="return_type" onchange="this.form.submit()">
                                <option value="">- All Types -</option>
                                <option value="refund" <?php if ($return_type_filter == "refund") { echo "selected"; } ?>>Refund</option>
                                <option value="replacement" <?php if ($return_type_filter == "replacement") { echo "selected"; } ?>>Replacement</option>
                                <option value="credit_note" <?php if ($return_type_filter == "credit_note") { echo "selected"; } ?>>Credit Note</option>
                                <option value="exchange" <?php if ($return_type_filter == "exchange") { echo "selected"; } ?>>Exchange</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
    
    <div class="table-responsive-sm">
        <table class="table table-hover mb-0 text-nowrap">
            <thead class="<?php if ($num_rows[0] == 0) { echo "d-none"; } ?> bg-light">
            <tr>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=ret.return_number&order=<?php echo $disp; ?>">
                        Return Number <?php if ($sort == 'ret.return_number') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=po.order_number&order=<?php echo $disp; ?>">
                        PO Number <?php if ($sort == 'po.order_number') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=s.supplier_name&order=<?php echo $disp; ?>">
                        Supplier <?php if ($sort == 's.supplier_name') { echo $order_icon; } ?>
                    </a>
                </th>
                <th class="text-center">
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=item_count&order=<?php echo $disp; ?>">
                        Items <?php if ($sort == 'item_count') { echo $order_icon; } ?>
                    </a>
                </th>
                <th class="text-center">
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=total_quantity&order=<?php echo $disp; ?>">
                        Qty Returned <?php if ($sort == 'total_quantity') { echo $order_icon; } ?>
                    </a>
                </th>
                <th class="text-center">
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=ret.return_date&order=<?php echo $disp; ?>">
                        Return Date <?php if ($sort == 'ret.return_date') { echo $order_icon; } ?>
                    </a>
                </th>
                <th class="text-center">
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=ret.total_amount&order=<?php echo $disp; ?>">
                        Total Amount <?php if ($sort == 'ret.total_amount') { echo $order_icon; } ?>
                    </a>
                </th>
                <th class="text-center">Return Type</th>
                <th class="text-center">Reason</th>
                <th class="text-center">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php

            while ($row = mysqli_fetch_array($sql)) {
                $return_id = intval($row['return_id']);
                $return_number = nullable_htmlentities($row['return_number']);
                $order_number = nullable_htmlentities($row['order_number']);
                $supplier_name = nullable_htmlentities($row['supplier_name']);
                $supplier_contact = nullable_htmlentities($row['supplier_contact']);
                $return_date = nullable_htmlentities($row['return_date']);
                $return_type = nullable_htmlentities($row['return_type']);
                $return_reason = nullable_htmlentities($row['return_reason']);
                $total_amount = floatval($row['total_amount'] ?? 0);
                $item_count = intval($row['item_count']);
                $total_quantity = intval($row['total_quantity'] ?? 0);
                $created_by_name = nullable_htmlentities($row['created_by_name']);
                $notes = nullable_htmlentities($row['notes']);

                // Return type badge colors
                $return_type_colors = [
                    'refund' => 'danger',
                    'replacement' => 'primary',
                    'credit_note' => 'success',
                    'exchange' => 'warning'
                ];

                $return_type_icons = [
                    'refund' => 'money-bill',
                    'replacement' => 'sync',
                    'credit_note' => 'file-invoice-dollar',
                    'exchange' => 'retweet'
                ];

                $return_type_color = $return_type_colors[$return_type] ?? 'secondary';
                $return_type_icon = $return_type_icons[$return_type] ?? 'undo';

                // Reason badge colors
                $reason_colors = [
                    'damaged' => 'danger',
                    'defective' => 'danger',
                    'wrong_item' => 'warning',
                    'over_supplied' => 'info',
                    'quality_issue' => 'warning',
                    'expired' => 'dark',
                    'other' => 'secondary'
                ];

                $reason_color = $reason_colors[$return_reason] ?? 'secondary';

                ?>
                <tr>
                    <td>
                        <div class="font-weight-bold text-warning"><?php echo $return_number; ?></div>
                        <small class="text-muted">
                            Created by <?php echo $created_by_name; ?>
                            <?php if ($notes): ?>
                                <br><i class="fas fa-sticky-note text-muted mr-1"></i><?php echo truncate($notes, 30); ?>
                            <?php endif; ?>
                        </small>
                    </td>
                    <td>
                        <div class="font-weight-bold"><?php echo $order_number; ?></div>
                        <small class="text-muted">
                            Ordered: <?php echo date('M j, Y', strtotime($row['order_date'])); ?>
                        </small>
                    </td>
                    <td>
                        <div class="font-weight-bold"><?php echo $supplier_name; ?></div>
                        <?php if ($supplier_contact): ?>
                            <small class="text-muted">Contact: <?php echo $supplier_contact; ?></small>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <div class="font-weight-bold"><?php echo $item_count; ?></div>
                    </td>
                    <td class="text-center">
                        <div class="font-weight-bold text-danger"><?php echo number_format($total_quantity); ?></div>
                    </td>
                    <td class="text-center">
                        <div class="font-weight-bold">
                            <?php echo date('M j, Y', strtotime($return_date)); ?>
                        </div>
                    </td>
                    <td class="text-center">
                        <div class="font-weight-bold text-danger">$<?php echo number_format($total_amount, 2); ?></div>
                    </td>
                    <td class="text-center">
                        <span class="badge badge-<?php echo $return_type_color; ?> badge-pill">
                            <i class="fas fa-<?php echo $return_type_icon; ?> mr-1"></i>
                            <?php echo ucfirst(str_replace('_', ' ', $return_type)); ?>
                        </span>
                    </td>
                    <td class="text-center">
                        <span class="badge badge-<?php echo $reason_color; ?>">
                            <?php echo ucfirst(str_replace('_', ' ', $return_reason)); ?>
                        </span>
                    </td>
                    <td>
                        <div class="dropdown dropleft text-center">
                            <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                <i class="fas fa-ellipsis-h"></i>
                            </button>
                            <div class="dropdown-menu">
                                <a class="dropdown-item" href="purchase_order_return_view.php?id=<?php echo $return_id; ?>">
                                    <i class="fas fa-fw fa-eye mr-2"></i>View Details
                                </a>
                                <a class="dropdown-item" href="purchase_order_return_print.php?id=<?php echo $return_id; ?>" target="_blank">
                                    <i class="fas fa-fw fa-print mr-2"></i>Print Return
                                </a>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item text-info" href="purchase_order_view.php?id=<?php echo $row['order_id']; ?>">
                                    <i class="fas fa-fw fa-shopping-cart mr-2"></i>View Purchase Order
                                </a>
                                <a class="dropdown-item text-success" href="grn_dashboard.php?order=<?php echo $row['order_id']; ?>">
                                    <i class="fas fa-fw fa-clipboard-check mr-2"></i>View GRNs
                                </a>
                                <?php if ($session_user_role == 1 || $session_user_role == 3): ?>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item text-danger" href="#" onclick="deleteReturn(<?php echo $return_id; ?>)">
                                        <i class="fas fa-fw fa-trash mr-2"></i>Delete Return
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php
            } 

            if ($num_rows[0] == 0) {
                echo '<tr><td colspan="10" class="text-center py-4">';
                echo '<i class="fas fa-undo fa-3x text-muted mb-3"></i>';
                echo '<h5 class="text-muted">No purchase order returns found</h5>';
                echo '<p class="text-muted">Purchase order returns will appear here when you return items against received orders.</p>';
                echo '<a href="purchase_orders.php" class="btn btn-warning">';
                echo '<i class="fas fa-shopping-cart mr-2"></i>View Purchase Orders';
                echo '</a>';
                echo '</td></tr>';
            }
            ?>
            </tbody>
        </table>
    </div>
    
    <!-- Recent Returns Sidebar -->
    <div class="card-footer">
        <div class="row">
            <div class="col-md-8">
                <?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/filter_footer.php'; ?>
            </div>
            <div class="col-md-4">
                <div class="card card-warning">
                    <div class="card-header">
                        <h5 class="card-title mb-0"><i class="fas fa-history mr-2"></i>Recent Returns</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush">
                            <?php while($recent_return = mysqli_fetch_assoc($recent_returns_sql)): 
                                $type_color = $return_type_colors[$recent_return['return_type']] ?? 'secondary';
                            ?>
                                <a href="purchase_order_return_view.php?id=<?php echo $recent_return['return_id']; ?>" class="list-group-item list-group-item-action">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1"><?php echo $recent_return['return_number']; ?></h6>
                                        <small class="text-<?php echo $type_color; ?>">
                                            <?php echo ucfirst($recent_return['return_type']); ?>
                                        </small>
                                    </div>
                                    <p class="mb-1"><?php echo $recent_return['order_number']; ?> - <?php echo $recent_return['supplier_name']; ?></p>
                                    <small class="text-muted">
                                        <?php echo date('M j', strtotime($recent_return['return_date'])); ?> • 
                                        $<?php echo number_format($recent_return['total_amount'], 2); ?>
                                    </small>
                                </a>
                            <?php endwhile; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
</div> <!-- End Card -->

<script>
$(document).ready(function() {
    $('.select2').select2();
    $('[data-toggle="tooltip"]').tooltip();

    // Auto-submit date range when canned date is selected
    $('select[name="canned_date"]').change(function() {
        if ($(this).val() !== 'custom') {
            $(this).closest('form').submit();
        }
    });

    // Initialize DataTable for better sorting and searching
    $('table').DataTable({
        pageLength: 25,
        order: [[5, 'desc']], // Default sort by return date descending
        dom: '<"top"f>rt<"bottom"lip><"clear">',
        language: {
            search: "Search returns:",
            lengthMenu: "Show _MENU_ returns per page",
            info: "Showing _START_ to _END_ of _TOTAL_ returns",
            infoEmpty: "No returns to show",
            infoFiltered: "(filtered from _MAX_ total returns)"
        }
    });
});

function deleteReturn(returnId) {
    if (confirm('Are you sure you want to delete this return? This action will reverse inventory adjustments and cannot be undone.')) {
        window.location.href = 'purchase_order_return_delete.php?id=' + returnId;
    }
}

// Quick date filter buttons
function filterByDate(range) {
    const today = new Date();
    let dtf, dtt;
    
    switch(range) {
        case 'today':
            dtf = dtt = today.toISOString().split('T')[0];
            break;
        case 'week':
            dtf = new Date(today.getTime() - 7 * 24 * 60 * 60 * 1000).toISOString().split('T')[0];
            dtt = today.toISOString().split('T')[0];
            break;
        case 'month':
            dtf = new Date(today.getFullYear(), today.getMonth(), 1).toISOString().split('T')[0];
            dtt = today.toISOString().split('T')[0];
            break;
    }
    
    window.location.href = `purchase_order_returns_dashboard.php?dtf=${dtf}&dtt=${dtt}`;
}

// Quick return type filter
function filterByReturnType(type) {
    window.location.href = `purchase_order_returns_dashboard.php?return_type=${type}`;
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + N for new return
    if (e.ctrlKey && e.keyCode === 78) {
        e.preventDefault();
        window.location.href = 'purchase_order_returns_create.php';
    }
    // Escape to clear search
    if (e.keyCode === 27) {
        window.location.href = 'purchase_order_returns_dashboard.php';
    }
});
</script>

<style>
.info-box-detail {
    font-size: 0.8em;
    margin-top: 2px;
}

.table tbody tr:hover {
    background-color: #f8f9fa;
}

.badge-pill {
    padding: 6px 12px;
    font-size: 0.75em;
}

.list-group-item {
    border: none;
    border-bottom: 1px solid #e3e6f0;
}

.list-group-item:last-child {
    border-bottom: none;
}

.card-header.bg-warning {
    background: linear-gradient(45deg, #ffc107, #fd7e14) !important;
}
</style>

<?php 
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
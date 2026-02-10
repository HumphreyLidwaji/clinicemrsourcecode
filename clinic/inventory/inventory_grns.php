<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Default Column Sortby/Order Filter
$sort = "g.grn_date";
$order = "DESC";

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Filter parameters
$status_filter = $_GET['status'] ?? '';
$supplier_filter = $_GET['supplier'] ?? '';
$location_filter = $_GET['location'] ?? '';
$po_filter = $_GET['po'] ?? '';
$verified_filter = $_GET['verified'] ?? '';

// Date Range Filter
$dtf = sanitizeInput($_GET['dtf'] ?? '');
$dtt = sanitizeInput($_GET['dtt'] ?? '');
$canned_date = $_GET['canned_date'] ?? '';

if (!empty($dtf) && !empty($dtt)) {
    $date_query = "AND DATE(g.grn_date) BETWEEN '$dtf' AND '$dtt'";
} else if (!empty($canned_date)) {
    switch($canned_date) {
        case 'today':
            $date_query = "AND DATE(g.grn_date) = CURDATE()";
            break;
        case 'yesterday':
            $date_query = "AND DATE(g.grn_date) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
            break;
        case 'thisweek':
            $date_query = "AND YEARWEEK(g.grn_date, 1) = YEARWEEK(CURDATE(), 1)";
            break;
        case 'lastweek':
            $date_query = "AND YEARWEEK(g.grn_date, 1) = YEARWEEK(CURDATE(), 1) - 1";
            break;
        case 'thismonth':
            $date_query = "AND MONTH(g.grn_date) = MONTH(CURDATE()) 
                           AND YEAR(g.grn_date) = YEAR(CURDATE())";
            break;
        case 'lastmonth':
            $date_query = "AND MONTH(g.grn_date) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) 
                           AND YEAR(g.grn_date) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))";
            break;
        default:
            $date_query = '';
    }
} else {
    $date_query = '';
}

// Verified Filter
if ($verified_filter === 'yes') {
    $verified_query = "AND g.verified_by IS NOT NULL";
} elseif ($verified_filter === 'no') {
    $verified_query = "AND g.verified_by IS NULL";
} else {
    $verified_query = '';
}

// Supplier Filter
if ($supplier_filter) {
    $supplier_query = "AND g.supplier_id = " . intval($supplier_filter);
} else {
    $supplier_query = '';
}

// Location Filter
if ($location_filter) {
    $location_query = "AND g.received_location_id = " . intval($location_filter);
} else {
    $location_query = '';
}

// Purchase Order Filter
if ($po_filter) {
    $po_query = "AND g.purchase_order_id = " . intval($po_filter);
} else {
    $po_query = '';
}

// Search Query
$q = sanitizeInput($_GET['q'] ?? '');
if (!empty($q)) {
    $search_query = "AND (
        g.grn_number LIKE '%$q%' 
        OR g.invoice_number LIKE '%$q%'
        OR g.delivery_note_number LIKE '%$q%'
        OR g.notes LIKE '%$q%'
        OR s.supplier_name LIKE '%$q%'
        OR l.location_name LIKE '%$q%'
        OR po.po_number LIKE '%$q%'
        OR u.user_name LIKE '%$q%'
        OR v.user_name LIKE '%$q%'
    )";
} else {
    $search_query = '';
}

// Main query for GRNs
$sql = mysqli_query(
    $mysqli,
    "
    SELECT SQL_CALC_FOUND_ROWS 
        g.*,
        s.supplier_id,
        s.supplier_name,
        s.supplier_contact,
        s.supplier_phone,
        s.supplier_email,
        l.location_id,
        l.location_name,
        l.location_type,
        po.purchase_order_id,
        po.po_number,
        po.status as po_status,
        u.user_name as received_by_name,
        v.user_name as verified_by_name,
        (SELECT COUNT(*) FROM inventory_grn_items gi WHERE gi.grn_id = g.grn_id) as item_count,
        (SELECT SUM(gi.quantity_received * gi.unit_cost) FROM inventory_grn_items gi WHERE gi.grn_id = g.grn_id) as total_value
    FROM inventory_grns g
    LEFT JOIN suppliers s ON g.supplier_id = s.supplier_id
    LEFT JOIN inventory_locations l ON g.received_location_id = l.location_id
    LEFT JOIN inventory_purchase_orders po ON g.purchase_order_id = po.purchase_order_id
    LEFT JOIN users u ON g.received_by = u.user_id
    LEFT JOIN users v ON g.verified_by = v.user_id
    WHERE g.is_active = 1
      $date_query
      $verified_query
      $supplier_query
      $location_query
      $po_query
      $search_query
    ORDER BY $sort $order
    LIMIT $record_from, $record_to
");

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get statistics
$total_grns = $num_rows[0];
$verified_count = 0;
$today_count = 0;
$week_count = 0;
$month_count = 0;
$total_value = 0;

// Reset pointer and calculate
mysqli_data_seek($sql, 0);
while ($grn = mysqli_fetch_assoc($sql)) {
    if ($grn['verified_by']) {
        $verified_count++;
    }
    
    $grn_date = strtotime($grn['grn_date']);
    
    // Count today's GRNs
    if (date('Y-m-d', $grn_date) == date('Y-m-d')) {
        $today_count++;
    }
    
    // Count this week's GRNs
    $week_start = strtotime('monday this week');
    $week_end = strtotime('sunday this week');
    if ($grn_date >= $week_start && $grn_date <= $week_end) {
        $week_count++;
    }
    
    // Count this month's GRNs
    if (date('Y-m', $grn_date) == date('Y-m')) {
        $month_count++;
    }
    
    // Calculate total value
    $total_value += $grn['total_value'] ?? 0;
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

// Get unique purchase orders for filter
$pos_sql = mysqli_query($mysqli, "
    SELECT purchase_order_id, po_number 
    FROM inventory_purchase_orders 
    WHERE is_active = 1
    ORDER BY po_date DESC 
    LIMIT 100
");

// Get today's summary
$today_summary_sql = "SELECT 
    COUNT(*) as count,
    SUM(CASE WHEN verified_by IS NOT NULL THEN 1 ELSE 0 END) as verified_today
    FROM inventory_grns 
    WHERE DATE(grn_date) = CURDATE() AND is_active = 1";
$today_summary_result = mysqli_query($mysqli, $today_summary_sql);
$today_summary = mysqli_fetch_assoc($today_summary_result);
?>

<div class="card">
    <div class="card-header bg-success py-2">
        <h3 class="card-title mt-2 mb-0 text-white"><i class="fas fa-fw fa-receipt mr-2"></i>Goods Receipt Notes (GRNs)</h3>
        <div class="card-tools">
            <div class="btn-group">
                <a href="inventory_grn_create.php" class="btn btn-light">
                    <i class="fas fa-plus mr-2"></i>Create GRN
                </a>
                <button type="button" class="btn btn-light dropdown-toggle dropdown-toggle-split" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <span class="sr-only">Toggle Dropdown</span>
                </button>
                <div class="dropdown-menu dropdown-menu-right">
                    <a class="dropdown-item" href="inventory_grn_create.php">
                        <i class="fas fa-plus mr-2"></i>New GRN
                    </a>
                    <a class="dropdown-item" href="inventory_grn_create.php?from_po=1">
                        <i class="fas fa-shopping-cart mr-2"></i>GRN from Purchase Order
                    </a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="inventory_purchase_orders.php">
                        <i class="fas fa-clipboard-check mr-2"></i>View Purchase Orders
                    </a>
                    <a class="dropdown-item" href="inventory_transactions.php?type=GRN">
                        <i class="fas fa-exchange-alt mr-2"></i>View GRN Transactions
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Quick Stats Bar -->
    <div class="card-header bg-light py-2">
        <div class="row text-center">
            <div class="col-md-3">
                <div class="small text-muted">Total GRNs</div>
                <div class="h5 font-weight-bold text-primary"><?php echo number_format($total_grns); ?></div>
            </div>
            <div class="col-md-3">
                <div class="small text-muted">Verified</div>
                <div class="h5 font-weight-bold text-success"><?php echo $verified_count; ?></div>
            </div>
            <div class="col-md-3">
                <div class="small text-muted">Today</div>
                <div class="h5 font-weight-bold text-info"><?php echo $today_count; ?></div>
            </div>
            <div class="col-md-3">
                <div class="small text-muted">Total Value</div>
                <div class="h5 font-weight-bold text-warning">$<?php echo number_format($total_value, 2); ?></div>
            </div>
        </div>
        <div class="row text-center mt-2">
            <div class="col-md-4">
                <div class="small text-muted">This Week</div>
                <div class="h6 font-weight-bold text-primary"><?php echo $week_count; ?></div>
            </div>
            <div class="col-md-4">
                <div class="small text-muted">This Month</div>
                <div class="h6 font-weight-bold text-info"><?php echo $month_count; ?></div>
            </div>
            <div class="col-md-4">
                <div class="small text-muted">Pending Verification</div>
                <div class="h6 font-weight-bold text-danger"><?php echo $total_grns - $verified_count; ?></div>
            </div>
        </div>
    </div>

    <div class="card-header pb-2 pt-3">
        <form autocomplete="off">
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search GRN numbers, invoices, suppliers, notes..." autofocus>
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
                            <span class="btn btn-light border" data-toggle="tooltip" title="Total GRNs">
                                <i class="fas fa-receipt text-primary mr-1"></i>
                                <strong><?php echo $total_grns; ?></strong>
                            </span>
                            <span class="btn btn-light border" data-toggle="tooltip" title="Verified GRNs">
                                <i class="fas fa-check text-success mr-1"></i>
                                <strong><?php echo $verified_count; ?></strong>
                            </span>
                            <span class="btn btn-light border" data-toggle="tooltip" title="Today's GRNs">
                                <i class="fas fa-calendar-day text-info mr-1"></i>
                                <strong><?php echo $today_count; ?></strong>
                            </span>
                            <a href="?<?php echo $url_query_strings_sort ?>&export=pdf" class="btn btn-light border ml-2">
                                <i class="fa fa-fw fa-file-pdf mr-2"></i>Export
                            </a>
                            <a href="inventory_purchase_orders.php" class="btn btn-warning ml-2">
                                <i class="fas fa-clipboard-check mr-2"></i>Purchase Orders
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php 
                if (isset($_GET['dtf']) || $verified_filter || $supplier_filter || $location_filter || $po_filter || $canned_date) { 
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
                            <label>Verification Status</label>
                            <select class="form-control select2" name="verified" onchange="this.form.submit()">
                                <option value="">- All -</option>
                                <option value="yes" <?php if ($verified_filter == "yes") { echo "selected"; } ?>>Verified</option>
                                <option value="no" <?php if ($verified_filter == "no") { echo "selected"; } ?>>Not Verified</option>
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
                            <label>Received Location</label>
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
                            <label>Purchase Order</label>
                            <select class="form-control select2" name="po" onchange="this.form.submit()">
                                <option value="">- All POs -</option>
                                <?php
                                while($po = mysqli_fetch_assoc($pos_sql)) {
                                    $po_id = intval($po['purchase_order_id']);
                                    $po_number = nullable_htmlentities($po['po_number']);
                                    $selected = $po_filter == $po_id ? 'selected' : '';
                                    echo "<option value='$po_id' $selected>$po_number</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Quick Actions</label>
                            <div class="btn-group btn-block">
                                <a href="inventory_grn_create.php" class="btn btn-success">
                                    <i class="fas fa-plus mr-2"></i>New GRN
                                </a>
                                <a href="inventory_grns.php" class="btn btn-secondary">
                                    <i class="fas fa-times mr-2"></i>Clear
                                </a>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>&nbsp;</label>
                            <a href="inventory_grns.php" class="btn btn-secondary btn-block">Clear Filters</a>
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
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=g.grn_number&order=<?php echo $disp; ?>">
                        GRN Number <?php if ($sort == 'g.grn_number') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=g.grn_date&order=<?php echo $disp; ?>">
                        Date <?php if ($sort == 'g.grn_date') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>Supplier</th>
                <th>Location</th>
                <th class="text-center">Items</th>
                <th>Purchase Order</th>
                <th class="text-center">Verification</th>
                <th class="text-center">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php
            while ($row = mysqli_fetch_array($sql)) {
                $grn_id = intval($row['grn_id']);
                $grn_number = nullable_htmlentities($row['grn_number']);
                $grn_date = nullable_htmlentities($row['grn_date']);
                $invoice_number = nullable_htmlentities($row['invoice_number']);
                $delivery_note_number = nullable_htmlentities($row['delivery_note_number']);
                $notes = nullable_htmlentities($row['notes']);
                $supplier_name = nullable_htmlentities($row['supplier_name']);
                $supplier_contact = nullable_htmlentities($row['supplier_contact']);
                $location_name = nullable_htmlentities($row['location_name']);
                $location_type = nullable_htmlentities($row['location_type']);
                $po_number = nullable_htmlentities($row['po_number']);
                $po_status = nullable_htmlentities($row['po_status']);
                $received_by_name = nullable_htmlentities($row['received_by_name']);
                $verified_by_name = nullable_htmlentities($row['verified_by_name']);
                $verified_at = nullable_htmlentities($row['verified_at']);
                $item_count = intval($row['item_count']);
                $total_value = floatval($row['total_value']);

                // Check if today's GRN
                $is_today = date('Y-m-d', strtotime($grn_date)) == date('Y-m-d');
                
                // Determine PO status badge
                $po_status_badge = '';
                switch($po_status) {
                    case 'draft':
                        $po_status_badge = 'badge-secondary';
                        break;
                    case 'submitted':
                        $po_status_badge = 'badge-info';
                        break;
                    case 'approved':
                        $po_status_badge = 'badge-primary';
                        break;
                    case 'received':
                        $po_status_badge = 'badge-success';
                        break;
                    case 'partially_received':
                        $po_status_badge = 'badge-warning';
                        break;
                    case 'cancelled':
                        $po_status_badge = 'badge-danger';
                        break;
                    default:
                        $po_status_badge = 'badge-light';
                }
                ?>
                <tr class="<?php echo $is_today ? 'table-info' : ''; ?>">
                    <td>
                        <div class="font-weight-bold text-primary"><?php echo $grn_number; ?></div>
                        <?php if ($invoice_number): ?>
                            <small class="text-muted">Invoice: <?php echo $invoice_number; ?></small><br>
                        <?php endif; ?>
                        <?php if ($delivery_note_number): ?>
                            <small class="text-muted">DN: <?php echo $delivery_note_number; ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="font-weight-bold"><?php echo date('M j, Y', strtotime($grn_date)); ?></div>
                        <?php if ($received_by_name): ?>
                            <small class="text-muted">Received by: <?php echo $received_by_name; ?></small>
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
                        <?php if ($total_value > 0): ?>
                            <div class="small text-success mt-1">
                                $<?php echo number_format($total_value, 2); ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($po_number): ?>
                            <div class="font-weight-bold text-info"><?php echo $po_number; ?></div>
                            <span class="badge <?php echo $po_status_badge; ?>"><?php echo ucfirst($po_status); ?></span>
                        <?php else: ?>
                            <span class="text-muted">Manual GRN</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?php if ($verified_by_name): ?>
                            <span class="badge badge-success">
                                <i class="fas fa-check mr-1"></i>Verified
                            </span>
                            <div class="small text-muted mt-1">
                                By: <?php echo $verified_by_name; ?><br>
                                <?php if ($verified_at): ?>
                                    <?php echo date('M j, H:i', strtotime($verified_at)); ?>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <span class="badge badge-warning">
                                <i class="fas fa-clock mr-1"></i>Pending
                            </span>
                            <?php if (hasPermission('inventory_verify')): ?>
                                <div class="mt-1">
                                    <a href="inventory_grn_verify.php?id=<?php echo $grn_id; ?>" class="btn btn-xs btn-outline-success">
                                        <i class="fas fa-check mr-1"></i>Verify
                                    </a>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="btn-group" role="group">
                            <a href="inventory_grn_view.php?id=<?php echo $grn_id; ?>" 
                               class="btn btn-sm btn-info" title="View Details">
                                <i class="fas fa-eye"></i>
                            </a>
                            <a href="inventory_grn_print.php?id=<?php echo $grn_id; ?>" 
                               class="btn btn-sm btn-secondary" title="Print" target="_blank">
                                <i class="fas fa-print"></i>
                            </a>
                            <?php if (!$verified_by_name && hasPermission('inventory_edit')): ?>
                                <a href="inventory_grn_edit.php?id=<?php echo $grn_id; ?>" 
                                   class="btn btn-sm btn-warning" title="Edit">
                                    <i class="fas fa-edit"></i>
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
                        <i class="fas fa-receipt fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No GRNs Found</h5>
                        <p class="text-muted">No goods receipt notes match your current filters.</p>
                        <a href="inventory_grn_create.php" class="btn btn-success mt-2">
                            <i class="fas fa-plus mr-2"></i>Create First GRN
                        </a>
                        <a href="inventory_grns.php" class="btn btn-secondary mt-2 ml-2">
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
    
    // Highlight pending verification GRNs
    setInterval(function() {
        $('.badge-warning').closest('tr').fadeOut(100).fadeIn(100);
    }, 3000);
});

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + N for new GRN
    if (e.ctrlKey && e.keyCode === 78) {
        e.preventDefault();
        window.location.href = 'inventory_grn_create.php';
    }
    // Ctrl + F for focus search
    if (e.ctrlKey && e.keyCode === 70) {
        e.preventDefault();
        $('input[name="q"]').focus();
    }
    // Ctrl + P for purchase orders
    if (e.ctrlKey && e.keyCode === 80) {
        e.preventDefault();
        window.location.href = 'inventory_purchase_orders.php';
    }
    // Escape to clear filters
    if (e.keyCode === 27) {
        window.location.href = 'inventory_grns.php';
    }
});
</script>

<style>
.table-hover tbody tr:hover {
    background-color: rgba(40, 167, 69, 0.075);
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

.badge-warning {
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0% { opacity: 1; }
    50% { opacity: 0.7; }
    100% { opacity: 1; }
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
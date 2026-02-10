<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Default Column Sortby/Order Filter
$sort = "p.order_date";
$order = "DESC";

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Filter parameters
$status_filter = $_GET['status'] ?? '';
$supplier_filter = $_GET['supplier'] ?? '';

// Date Range Filter
$dtf = sanitizeInput($_GET['dtf'] ?? '');
$dtt = sanitizeInput($_GET['dtt'] ?? '');

if (!empty($dtf) && !empty($dtt)) {
    $date_query = "AND DATE(p.order_date) BETWEEN '$dtf' AND '$dtt'";
} else {
    $date_query = '';
}

// Status Filter
if ($status_filter) {
    $status_query = "AND p.order_status = '" . sanitizeInput($status_filter) . "'";
} else {
    $status_query = '';
}

// Supplier Filter
if ($supplier_filter) {
    $supplier_query = "AND p.supplier_id = " . intval($supplier_filter);
} else {
    $supplier_query = '';
}

// Search Query
$q = sanitizeInput($_GET['q'] ?? '');
if (!empty($q)) {
    $search_query = "AND (
        p.order_number LIKE '%$q%' 
        OR s.supplier_name LIKE '%$q%'
        OR p.notes LIKE '%$q%'
    )";
} else {
    $search_query = '';
}

// Main query for purchase orders
$sql = mysqli_query(
    $mysqli,
    "
    SELECT SQL_CALC_FOUND_ROWS p.*, 
           s.supplier_name, s.supplier_contact, s.supplier_phone,
           u.user_name as created_by_name,
           (SELECT COUNT(*) FROM purchase_order_items poi WHERE poi.order_id = p.order_id) as item_count,
           (SELECT SUM(quantity_ordered) FROM purchase_order_items poi WHERE poi.order_id = p.order_id) as total_quantity,
           (SELECT SUM(quantity_received) FROM purchase_order_items poi WHERE poi.order_id = p.order_id) as received_quantity
    FROM purchase_orders p 
    LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id
    LEFT JOIN users u ON p.created_by = u.user_id
    WHERE 1=1
      $status_query
      $supplier_query
      $date_query
      $search_query
    ORDER BY $sort $order
    LIMIT $record_from, $record_to
");

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get statistics
$total_orders = $num_rows[0];
$draft_count = 0;
$pending_count = 0;
$approved_count = 0;
$ordered_count = 0;
$received_count = 0;
$cancelled_count = 0;
$total_order_value = 0;

// Reset pointer and calculate
mysqli_data_seek($sql, 0);
while ($order = mysqli_fetch_assoc($sql)) {
    switch($order['order_status']) {
        case 'draft':
            $draft_count++;
            break;
        case 'pending':
            $pending_count++;
            break;
        case 'approved':
            $approved_count++;
            break;
        case 'ordered':
            $ordered_count++;
            break;
        case 'received':
            $received_count++;
            break;
        case 'cancelled':
            $cancelled_count++;
            break;
    }
    $total_order_value += floatval($order['total_amount']);
}
mysqli_data_seek($sql, $record_from);

// Get unique suppliers for filter
$suppliers_sql = mysqli_query($mysqli, "
    SELECT supplier_id, supplier_name 
    FROM suppliers 
    WHERE supplier_is_active = 1 
    ORDER BY supplier_name
");

// Get pending approvals (for managers/admins)
$pending_approval_count = 0;
if ($session_user_role == 1 || $session_user_role == 3) {
    $pending_sql = "SELECT COUNT(*) as count FROM purchase_orders WHERE order_status = 'pending'";
    $pending_result = mysqli_query($mysqli, $pending_sql);
    $pending_approval_count = mysqli_fetch_assoc($pending_result)['count'];
}
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0"><i class="fas fa-fw fa-shopping-cart mr-2"></i>Purchase Orders</h3>
        <div class="card-tools">
            <a href="purchase_order_create.php" class="btn btn-success">
                <i class="fas fa-plus mr-2"></i>New Order
            </a>
        </div>
    </div>



    <div class="card-header pb-2 pt-3">
        <form autocomplete="off">
            <div class="row">
                <div class="col-md-5">
                    <div class="form-group">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search order numbers, suppliers, notes..." autofocus>
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
                                <i class="fas fa-file-invoice text-primary mr-1"></i>
                                Total: <strong><?php echo $total_orders; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-clock text-warning mr-1"></i>
                                Pending: <strong><?php echo $pending_count; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-dollar-sign text-success mr-1"></i>
                                Value: <strong>$<?php echo number_format($total_order_value, 2); ?></strong>
                            </span>
                            <a href="suppliers.php" class="btn btn-info ml-2">
                                <i class="fas fa-fw fa-truck mr-2"></i>Suppliers
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php if ($status_filter || $supplier_filter || isset($_GET['dtf'])) { echo "show"; } ?>" id="advancedFilter">
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
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Status</label>
                            <select class="form-control select2" name="status" onchange="this.form.submit()">
                                <option value="">- All Statuses -</option>
                                <option value="draft" <?php if ($status_filter == "draft") { echo "selected"; } ?>>Draft</option>
                                <option value="pending" <?php if ($status_filter == "pending") { echo "selected"; } ?>>Pending Approval</option>
                                <option value="approved" <?php if ($status_filter == "approved") { echo "selected"; } ?>>Approved</option>
                                <option value="ordered" <?php if ($status_filter == "ordered") { echo "selected"; } ?>>Ordered</option>
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
                    <div class="col-md-12">
                        <div class="form-group">
                            <label>Quick Actions</label>
                            <div class="btn-group">
                                <a href="purchase_orders.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times mr-2"></i>Clear Filters
                                </a>
                                <a href="purchase_order_create.php" class="btn btn-success">
                                    <i class="fas fa-plus mr-2"></i>New Order
                                </a>
                                <a href="?export=pdf" class="btn btn-default">
                                    <i class="fas fa-file-pdf mr-2"></i>Export PDF
                                </a>
                                <a href="purchase_order_export.php" class="btn btn-default">
                                    <i class="fas fa-file-excel mr-2"></i>Export Excel
                                </a>
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
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=p.order_number&order=<?php echo $disp; ?>">
                        Order # <?php if ($sort == 'p.order_number') { echo $order_icon; } ?>
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
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=p.order_date&order=<?php echo $disp; ?>">
                        Order Date <?php if ($sort == 'p.order_date') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=p.expected_delivery_date&order=<?php echo $disp; ?>">
                        Delivery Date <?php if ($sort == 'p.expected_delivery_date') { echo $order_icon; } ?>
                    </a>
                </th>
                <th class="text-center">
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=p.total_amount&order=<?php echo $disp; ?>">
                        Total Amount <?php if ($sort == 'p.total_amount') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>Status</th>
                <th class="text-center">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php while ($row = mysqli_fetch_array($sql)) {
                $order_id = intval($row['order_id']);
                $order_number = nullable_htmlentities($row['order_number']);
                $supplier_name = nullable_htmlentities($row['supplier_name']);
                $supplier_contact = nullable_htmlentities($row['supplier_contact']);
                $order_date = nullable_htmlentities($row['order_date']);
                $expected_delivery_date = nullable_htmlentities($row['expected_delivery_date']);
                $total_amount = floatval($row['total_amount']);
                $order_status = nullable_htmlentities($row['order_status']);
                $item_count = intval($row['item_count']);
                $total_quantity = intval($row['total_quantity']);
                $received_quantity = intval($row['received_quantity']);
                $created_by_name = nullable_htmlentities($row['created_by_name']);
                $notes = nullable_htmlentities($row['notes']);

                // Determine status color and progress
                $status_color = 'secondary';
                $status_icon = 'edit';
                
                switch($order_status) {
                    case 'draft':
                        $status_color = 'secondary';
                        $status_icon = 'edit';
                        break;
                    case 'pending':
                        $status_color = 'warning';
                        $status_icon = 'clock';
                        break;
                    case 'approved':
                        $status_color = 'info';
                        $status_icon = 'check-circle';
                        break;
                    case 'ordered':
                        $status_color = 'primary';
                        $status_icon = 'shopping-cart';
                        break;
                    case 'received':
                        $status_color = 'success';
                        $status_icon = 'check-double';
                        break;
                    case 'cancelled':
                        $status_color = 'danger';
                        $status_icon = 'times-circle';
                        break;
                }

                // Calculate receipt progress
                $receipt_progress = 0;
                if ($total_quantity > 0) {
                    $receipt_progress = min(100, ($received_quantity / $total_quantity) * 100);
                }

                // Check if delivery is overdue
                $is_overdue = false;
                if ($expected_delivery_date && $order_status !== 'received' && $order_status !== 'cancelled') {
                    $delivery_date = new DateTime($expected_delivery_date);
                    $today = new DateTime();
                    if ($delivery_date < $today) {
                        $is_overdue = true;
                    }
                }
                ?>
                <tr class="<?php echo $is_overdue ? 'table-warning' : ''; ?>">
                    <td>
                        <div class="font-weight-bold text-primary"><?php echo $order_number; ?></div>
                        <?php if ($notes): ?>
                            <small class="text-muted"><?php echo strlen($notes) > 50 ? substr($notes, 0, 50) . '...' : $notes; ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="font-weight-bold"><?php echo $supplier_name; ?></div>
                        <?php if ($supplier_contact): ?>
                            <small class="text-muted"><?php echo $supplier_contact; ?></small>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <span class="badge badge-primary"><?php echo $item_count; ?></span>
                        <?php if ($received_quantity > 0): ?>
                            <div class="progress mt-1" style="height: 3px;">
                                <div class="progress-bar bg-success" style="width: <?php echo $receipt_progress; ?>%"></div>
                            </div>
                            <small class="text-muted"><?php echo $received_quantity; ?>/<?php echo $total_quantity; ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="font-weight-bold"><?php echo date('M j, Y', strtotime($order_date)); ?></div>
                    </td>
                    <td>
                        <?php if ($expected_delivery_date): ?>
                            <div class="font-weight-bold <?php echo $is_overdue ? 'text-danger' : 'text-success'; ?>">
                                <?php echo date('M j, Y', strtotime($expected_delivery_date)); ?>
                            </div>
                            <?php if ($is_overdue): ?>
                                <small class="text-danger">Overdue</small>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted">Not set</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <span class="font-weight-bold text-success">$<?php echo number_format($total_amount, 2); ?></span>
                    </td>
                    <td>
                        <span class="badge badge-<?php echo $status_color; ?>">
                            <i class="fas fa-<?php echo $status_icon; ?> mr-1"></i>
                            <?php echo ucfirst($order_status); ?>
                        </span>
                    </td>
                    <td>
                        <div class="dropdown dropleft text-center">
                            <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                <i class="fas fa-ellipsis-h"></i>
                            </button>
                            <div class="dropdown-menu">
                                <a class="dropdown-item" href="purchase_order_view.php?id=<?php echo $order_id; ?>">
                                    <i class="fas fa-fw fa-eye mr-2"></i>View Details
                                </a>
                                <?php if ($order_status === 'draft' || $order_status === 'pending'): ?>
                                    <a class="dropdown-item" href="purchase_order_edit.php?id=<?php echo $order_id; ?>">
                                        <i class="fas fa-fw fa-edit mr-2"></i>Edit Order
                                    </a>
                                <?php endif; ?>
                                
                                <?php if ($order_status === 'pending' && ($session_user_role == 1 || $session_user_role == 3)): ?>
                                    <a class="dropdown-item text-success" href="purchase_order_approve.php?id=<?php echo $order_id; ?>">
                                        <i class="fas fa-fw fa-check mr-2"></i>Approve Order
                                    </a>
                                <?php endif; ?>
                                
                                <?php if ($order_status === 'ordered' || $order_status === 'partially_received'): ?>
                                    <a class="dropdown-item text-info" href="goods_received_note.php?order_id=<?php echo $order_id; ?>">
                                        <i class="fas fa-fw fa-clipboard-check mr-2"></i>Goods Received
                                    </a>
                                <?php endif; ?>
                                
                                <div class="dropdown-divider"></div>
                                
                                <a class="dropdown-item" href="purchase_order_print.php?id=<?php echo $order_id; ?>" target="_blank">
                                    <i class="fas fa-fw fa-print mr-2"></i>Print Order
                                </a>
                                
                                <?php if ($order_status === 'draft' || $order_status === 'pending'): ?>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item text-danger confirm-link" href="purchase_order_cancel.php?id=<?php echo $order_id; ?>">
                                        <i class="fas fa-fw fa-times mr-2"></i>Cancel Order
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
                    <td colspan="8" class="text-center py-4">
                        <i class="fas fa-shopping-cart fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No Purchase Orders Found</h5>
                        <p class="text-muted">No orders match your current filters.</p>
                        <a href="purchase_order_create.php" class="btn btn-primary mt-2">
                            <i class="fas fa-plus mr-2"></i>Create First Order
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

    // Confirm action links
    $('.confirm-link').on('click', function(e) {
        if (!confirm('Are you sure you want to cancel this purchase order? This action cannot be undone.')) {
            e.preventDefault();
        }
    });
});

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + N for new order
    if (e.ctrlKey && e.keyCode === 78) {
        e.preventDefault();
        window.location.href = 'purchase_order_create.php';
    }
    // Ctrl + F for focus search
    if (e.ctrlKey && e.keyCode === 70) {
        e.preventDefault();
        $('input[name="q"]').focus();
    }
});
</script>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
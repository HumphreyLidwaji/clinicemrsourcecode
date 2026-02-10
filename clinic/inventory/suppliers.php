<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Default Column Sortby/Order Filter
$sort = "supplier_name";
$order = "ASC";
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Filter parameters
$status_filter = $_GET['status'] ?? '';
$date_added_filter = $_GET['date_added'] ?? '';

// Date Range Filter for supplier creation
$dtf = sanitizeInput($_GET['dtf'] ?? '');
$dtt = sanitizeInput($_GET['dtt'] ?? '');

if (!empty($dtf) && !empty($dtt)) {
    $date_query = "AND supplier_created_at BETWEEN '$dtf' AND '$dtt'";
} else if (!empty($date_added_filter)) {
    switch($date_added_filter) {
        case 'today':
            $date_query = "AND DATE(supplier_created_at) = CURDATE()";
            break;
        case 'week':
            $date_query = "AND supplier_created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
            break;
        case 'month':
            $date_query = "AND supplier_created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
            break;
        default:
            $date_query = '';
    }
} else {
    $date_query = '';
}

// Search Query
$q = sanitizeInput($_GET['q'] ?? '');
if (!empty($q)) {
    $search_query = "AND (
        supplier_name LIKE '%$q%' 
        OR supplier_contact LIKE '%$q%'
        OR supplier_email LIKE '%$q%'
        OR supplier_phone LIKE '%$q%'
        OR supplier_address LIKE '%$q%'
        OR supplier_city LIKE '%$q%'
    )";
} else {
    $search_query = '';
}

// Status Filter
if ($status_filter) {
    $status_query = "AND supplier_is_active = " . ($status_filter == 'active' ? 1 : 0);
} else {
    $status_query = '';
}

// Main query for suppliers
$sql = mysqli_query(
    $mysqli,
    "
    SELECT SQL_CALC_FOUND_ROWS * 
    FROM suppliers 
    WHERE 1=1
      $status_query
      $search_query
      $date_query
    ORDER BY $sort $order
    LIMIT $record_from, $record_to
");

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get statistics
$total_suppliers = $num_rows[0];
$active_suppliers = 0;
$new_this_week = 0;

// Reset pointer and calculate
mysqli_data_seek($sql, 0);
while ($supplier = mysqli_fetch_assoc($sql)) {
    if ($supplier['supplier_is_active']) {
        $active_suppliers++;
    }
    if (strtotime($supplier['supplier_created_at']) >= strtotime('-7 days')) {
        $new_this_week++;
    }
}
mysqli_data_seek($sql, $record_from);

// Get comprehensive supplier statistics
$supplier_stats_sql = mysqli_query($mysqli, "
    SELECT s.supplier_id, 
           COUNT(i.item_id) as items_count,
           COUNT(po.order_id) as pending_orders,
           COUNT(po_completed.order_id) as total_orders,
           SUM(po_completed.total_amount) as total_spent
    FROM suppliers s
    LEFT JOIN inventory_items i ON s.supplier_id = i.item_supplier_id 
    LEFT JOIN purchase_orders po ON s.supplier_id = po.supplier_id AND po.order_status = 'pending'
    LEFT JOIN purchase_orders po_completed ON s.supplier_id = po_completed.supplier_id AND po_completed.order_status = 'completed'
    WHERE 1=1
    GROUP BY s.supplier_id
");
$supplier_stats = [];
while ($row = mysqli_fetch_assoc($supplier_stats_sql)) {
    $supplier_stats[$row['supplier_id']] = [
        'items_count' => $row['items_count'],
        'pending_orders' => $row['pending_orders'],
        'total_orders' => $row['total_orders'],
        'total_spent' => $row['total_spent'] ?? 0
    ];
}
?>

<div class="card">
    <div class="card-header bg-info py-2">
        <h3 class="card-title mt-2 mb-0"><i class="fas fa-fw fa-truck mr-2"></i>Suppliers</h3>
        <div class="card-tools">
            <a href="supplier_add.php" class="btn btn-primary">
                <i class="fas fa-plus mr-2"></i>Add Supplier
            </a>
        </div>
    </div>


    <div class="card-header pb-2 pt-3">
        <form autocomplete="off">
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search suppliers, contacts, emails..." autofocus>
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
                            <span class="btn btn-light border">
                                <i class="fas fa-truck text-primary mr-1"></i>
                                Total: <strong><?php echo $total_suppliers; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-check-circle text-success mr-1"></i>
                                Active: <strong><?php echo $active_suppliers; ?></strong>
                            </span>
                            <a href="?<?php echo $url_query_strings_sort ?>&export=pdf" class="btn btn-light border ml-2">
                                <i class="fa fa-fw fa-file-pdf mr-2"></i>Export
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php if (isset($_GET['status']) || isset($_GET['date_added']) || isset($_GET['dtf'])) { echo "show"; } ?>" id="advancedFilter">
                <div class="row">
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Date Added</label>
                            <select class="form-control select2" name="date_added" onchange="this.form.submit()">
                                <option value="">- Any Time -</option>
                                <option value="today" <?php if ($date_added_filter == "today") { echo "selected"; } ?>>Today</option>
                                <option value="week" <?php if ($date_added_filter == "week") { echo "selected"; } ?>>Past Week</option>
                                <option value="month" <?php if ($date_added_filter == "month") { echo "selected"; } ?>>Past Month</option>
                                <option value="custom" <?php if (isset($_GET['dtf'])) { echo "selected"; } ?>>Custom Range</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Date From</label>
                            <input type="date" class="form-control" name="dtf" max="2999-12-31" value="<?php echo nullable_htmlentities($dtf); ?>" onchange="this.form.submit()">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Date To</label>
                            <input type="date" class="form-control" name="dtt" max="2999-12-31" value="<?php echo nullable_htmlentities($dtt); ?>" onchange="this.form.submit()">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Status</label>
                            <select class="form-control select2" name="status" onchange="this.form.submit()">
                                <option value="">- All Status -</option>
                                <option value="active" <?php if ($status_filter == "active") { echo "selected"; } ?>>Active</option>
                                <option value="inactive" <?php if ($status_filter == "inactive") { echo "selected"; } ?>>Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>&nbsp;</label>
                            <a href="suppliers.php" class="btn btn-secondary btn-block">Clear Filters</a>
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
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=supplier_name&order=<?php echo $disp; ?>">
                        Supplier Name <?php if ($sort == 'supplier_name') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>Contact Information</th>
                <th class="text-center">Items</th>
                <th class="text-center">Orders</th>
                <th class="text-center">Total Spent</th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=supplier_is_active&order=<?php echo $disp; ?>">
                        Status <?php if ($sort == 'supplier_is_active') { echo $order_icon; } ?>
                    </a>
                </th>
                <th class="text-center">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php
            while ($row = mysqli_fetch_array($sql)) {
                $supplier_id = intval($row['supplier_id']);
                $supplier_name = nullable_htmlentities($row['supplier_name']);
                $supplier_contact_name = nullable_htmlentities($row['supplier_contact']);
                $supplier_email = nullable_htmlentities($row['supplier_email']);
                $supplier_phone = nullable_htmlentities($row['supplier_phone']);
                $supplier_address = nullable_htmlentities($row['supplier_address']);
                $supplier_city = nullable_htmlentities($row['supplier_city']);
                $supplier_is_active = intval($row['supplier_is_active']);
                $supplier_created_at = $row['supplier_created_at'];
                
                $stats = $supplier_stats[$supplier_id] ?? ['items_count' => 0, 'pending_orders' => 0, 'total_orders' => 0, 'total_spent' => 0];
                $items_count = $stats['items_count'];
                $pending_orders = $stats['pending_orders'];
                $total_orders = $stats['total_orders'];
                $total_spent = $stats['total_spent'];
                
                $is_new = strtotime($supplier_created_at) >= strtotime('-7 days');
                ?>
                <tr class="<?php echo $is_new ? 'table-info' : ''; ?>">
                    <td>
                        <div class="font-weight-bold"><?php echo $supplier_name; ?></div>
                        <small class="text-muted"><?php echo truncate($supplier_address, 30); ?></small>
                        <?php if ($is_new): ?>
                            <span class="badge badge-success badge-sm">NEW</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="small">
                            <?php if ($supplier_contact_name): ?>
                                <div><i class="fas fa-user mr-1 text-muted"></i> <?php echo $supplier_contact_name; ?></div>
                            <?php endif; ?>
                            <?php if ($supplier_email): ?>
                                <div><i class="fas fa-envelope mr-1 text-muted"></i> 
                                    <a href="mailto:<?php echo $supplier_email; ?>"><?php echo $supplier_email; ?></a>
                                </div>
                            <?php endif; ?>
                            <?php if ($supplier_phone): ?>
                                <div><i class="fas fa-phone mr-1 text-muted"></i> 
                                    <a href="tel:<?php echo $supplier_phone; ?>"><?php echo $supplier_phone; ?></a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="text-center">
                        <span class="font-weight-bold <?php echo $items_count > 0 ? 'text-primary' : 'text-muted'; ?>">
                            <?php echo $items_count; ?>
                        </span>
                        <div class="small text-muted">items</div>
                    </td>
                    <td class="text-center">
                        <?php if ($pending_orders > 0): ?>
                            <span class="badge badge-warning badge-pill" title="Pending Orders"><?php echo $pending_orders; ?></span>
                        <?php endif; ?>
                        <div class="small text-muted"><?php echo $total_orders; ?> total</div>
                    </td>
                    <td class="text-center">
                        <span class="font-weight-bold text-success">
                            $<?php echo number_format($total_spent, 2); ?>
                        </span>
                    </td>
                    <td>
                        <span class="badge badge-<?php echo $supplier_is_active ? 'success' : 'danger'; ?>">
                            <i class="fas fa-<?php echo $supplier_is_active ? 'check' : 'ban'; ?> mr-1"></i>
                            <?php echo $supplier_is_active ? 'Active' : 'Inactive'; ?>
                        </span>
                    </td>
                    <td>
                        <div class="dropdown dropleft text-center">
                            <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                <i class="fas fa-ellipsis-h"></i>
                            </button>
                            <div class="dropdown-menu">
                                <a class="dropdown-item" href="supplier_edit.php?supplier_id=<?php echo $supplier_id; ?>">
                                    <i class="fas fa-fw fa-edit mr-2"></i>Edit Supplier
                                </a>
                                <a class="dropdown-item" href="supplier_view.php?supplier_id=<?php echo $supplier_id; ?>">
                                    <i class="fas fa-fw fa-eye mr-2"></i>View Details
                                </a>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item" href="inventory.php?supplier=<?php echo $supplier_id; ?>">
                                    <i class="fas fa-fw fa-boxes mr-2"></i>View Items (<?php echo $items_count; ?>)
                                </a>
                                <a class="dropdown-item" href="purchase_orders.php?supplier=<?php echo $supplier_id; ?>">
                                    <i class="fas fa-fw fa-shopping-cart mr-2"></i>View Orders
                                </a>
                                <div class="dropdown-divider"></div>
                                <?php if ($supplier_is_active): ?>
                                    <a class="dropdown-item text-warning confirm-link" href="post.php?deactivate_supplier=<?php echo $supplier_id; ?>">
                                        <i class="fas fa-fw fa-ban mr-2"></i>Deactivate
                                    </a>
                                <?php else: ?>
                                    <a class="dropdown-item text-success" href="post.php?activate_supplier=<?php echo $supplier_id; ?>">
                                        <i class="fas fa-fw fa-check mr-2"></i>Activate
                                    </a>
                                <?php endif; ?>
                                <?php if ($items_count == 0 && $pending_orders == 0): ?>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item text-danger confirm-link" href="post.php?delete_supplier=<?php echo $supplier_id; ?>">
                                        <i class="fas fa-fw fa-trash mr-2"></i>Delete
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
                        <i class="fas fa-truck fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No Suppliers Found</h5>
                        <p class="text-muted">No suppliers match your current filters.</p>
                        <a href="supplier_add.php" class="btn btn-primary mt-2">
                            <i class="fas fa-plus mr-2"></i>Add First Supplier
                        </a>
                        <a href="suppliers.php" class="btn btn-secondary mt-2 ml-2">
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

    // Confirm action links
    $('.confirm-link').click(function(e) {
        if (!confirm('Are you sure you want to perform this action? This cannot be undone.')) {
            e.preventDefault();
        }
    });

    // Auto-submit when date range changes
    $('input[type="date"]').change(function() {
        if ($(this).val()) {
            $(this).closest('form').submit();
        }
    });

    // Auto-submit when canned date is selected
    $('select[name="date_added"]').change(function() {
        if ($(this).val() !== 'custom') {
            $(this).closest('form').submit();
        }
    });
});

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + N for new supplier
    if (e.ctrlKey && e.keyCode === 78) {
        e.preventDefault();
        window.location.href = 'supplier_add.php';
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
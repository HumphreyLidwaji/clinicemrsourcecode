<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Default Column Sortby/Order Filter
$sort = "i.item_name";
$order = "ASC";

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Filter parameters
$category_filter = $_GET['category'] ?? '';
$supplier_filter = $_GET['supplier'] ?? '';
$stock_status_filter = $_GET['stock_status'] ?? '';
$expiry_filter = $_GET['expiry'] ?? '';

// Stock level calculations
$critical_threshold = 0.1;  // 10% of low stock alert
$warning_threshold = 0.3;   // 30% of low stock alert

// Category Filter
if ($category_filter) {
    $category_query = "AND i.item_category_id = " . intval($category_filter);
} else {
    $category_query = '';
}

// Supplier Filter
if ($supplier_filter) {
    $supplier_query = "AND i.item_supplier_id = " . intval($supplier_filter);
} else {
    $supplier_query = '';
}

// Stock Status Filter
if ($stock_status_filter) {
    switch($stock_status_filter) {
        case 'critical':
            $stock_status_query = "AND i.item_quantity > 0 AND i.item_quantity <= ROUND(i.item_low_stock_alert * $critical_threshold)";
            break;
        case 'warning':
            $stock_status_query = "AND i.item_quantity > ROUND(i.item_low_stock_alert * $critical_threshold) AND i.item_quantity <= ROUND(i.item_low_stock_alert * $warning_threshold)";
            break;
        case 'low_stock':
            $stock_status_query = "AND i.item_quantity > ROUND(i.item_low_stock_alert * $warning_threshold) AND i.item_quantity <= i.item_low_stock_alert";
            break;
        case 'adequate':
            $stock_status_query = "AND i.item_quantity > i.item_low_stock_alert";
            break;
        case 'out_of_stock':
            $stock_status_query = "AND i.item_quantity <= 0";
            break;
        default:
            $stock_status_query = '';
    }
} else {
    $stock_status_query = '';
}

// Expiry Filter
if ($expiry_filter) {
    if ($expiry_filter == 'expired') {
        $expiry_query = "AND i.item_expiry_date IS NOT NULL AND i.item_expiry_date < CURDATE()";
    } elseif ($expiry_filter == 'expiring_soon') {
        $expiry_query = "AND i.item_expiry_date IS NOT NULL AND i.item_expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
    } elseif ($expiry_filter == 'no_expiry') {
        $expiry_query = "AND i.item_expiry_date IS NULL";
    } else {
        $expiry_query = '';
    }
} else {
    $expiry_query = '';
}

// Search Query
$q = sanitizeInput($_GET['q'] ?? '');
if (!empty($q)) {
    $search_query = "AND (
        i.item_name LIKE '%$q%' 
        OR i.item_code LIKE '%$q%'
        OR i.item_description LIKE '%$q%'
        OR c.category_name LIKE '%$q%'
        OR s.supplier_name LIKE '%$q%'
        OR i.item_brand LIKE '%$q%'
    )";
} else {
    $search_query = '';
}

// Main query for all inventory items with stock calculations
$sql = mysqli_query(
    $mysqli,
    "
    SELECT SQL_CALC_FOUND_ROWS i.*, 
           c.category_name, c.category_type,
           s.supplier_name,
           u.user_name as added_by,
           ROUND((i.item_quantity / NULLIF(i.item_low_stock_alert, 0)) * 100, 1) as stock_percentage,
           (i.item_quantity * i.item_unit_cost) as stock_value,
           (SELECT COUNT(*) FROM inventory_transactions it 
            WHERE it.item_id = i.item_id 
            AND it.transaction_type = 'out' 
            AND DATE(it.transaction_date) = CURDATE()) as today_usage,
           (SELECT transaction_date FROM inventory_transactions it 
            WHERE it.item_id = i.item_id 
            ORDER BY it.transaction_date DESC LIMIT 1) as last_transaction,
           CASE 
               WHEN i.item_quantity <= 0 THEN 'out_of_stock'
               WHEN i.item_quantity <= ROUND(i.item_low_stock_alert * $critical_threshold) THEN 'critical'
               WHEN i.item_quantity <= ROUND(i.item_low_stock_alert * $warning_threshold) THEN 'warning'
               WHEN i.item_quantity <= i.item_low_stock_alert THEN 'low_stock'
               ELSE 'adequate'
           END as stock_level
    FROM inventory_items i 
    LEFT JOIN inventory_categories c ON i.item_category_id = c.category_id 
    LEFT JOIN suppliers s ON i.item_supplier_id = s.supplier_id
    LEFT JOIN users u ON i.item_added_by = u.user_id
    WHERE i.item_status != 'Discontinued'
      $stock_status_query
      $category_query
      $supplier_query
      $expiry_query
      $search_query
    ORDER BY $sort $order
    LIMIT $record_from, $record_to
");

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get overall stock statistics
$stats_sql = mysqli_query($mysqli,
    "SELECT 
        COUNT(*) as total_items,
        SUM(item_quantity) as total_quantity,
        SUM(item_quantity * item_unit_cost) as total_value,
        SUM(CASE WHEN item_quantity <= 0 THEN 1 ELSE 0 END) as out_of_stock_count,
        SUM(CASE WHEN item_quantity > 0 AND item_quantity <= ROUND(item_low_stock_alert * $critical_threshold) THEN 1 ELSE 0 END) as critical_count,
        SUM(CASE WHEN item_quantity > ROUND(item_low_stock_alert * $critical_threshold) AND item_quantity <= ROUND(item_low_stock_alert * $warning_threshold) THEN 1 ELSE 0 END) as warning_count,
        SUM(CASE WHEN item_quantity > ROUND(item_low_stock_alert * $warning_threshold) AND item_quantity <= item_low_stock_alert THEN 1 ELSE 0 END) as low_stock_count,
        SUM(CASE WHEN item_quantity > item_low_stock_alert THEN 1 ELSE 0 END) as adequate_count,
        COUNT(CASE WHEN item_expiry_date IS NOT NULL AND item_expiry_date < CURDATE() THEN 1 END) as expired_count,
        COUNT(CASE WHEN item_expiry_date IS NOT NULL AND item_expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 END) as expiring_soon_count
    FROM inventory_items 
    WHERE item_status != 'Discontinued'"
);
$stats = mysqli_fetch_assoc($stats_sql);

// Get categories for filter
$categories_sql = mysqli_query($mysqli, "
    SELECT category_id, category_name, category_type 
    FROM inventory_categories 
    WHERE category_is_active = 1 
    ORDER BY category_type, category_name"
);

// Get suppliers for filter
$suppliers_sql = mysqli_query($mysqli, "
    SELECT supplier_id, supplier_name 
    FROM suppliers 
    WHERE supplier_is_active = 1 
    ORDER BY supplier_name"
);

// Calculate alert priorities
$critical_alerts = $stats['critical_count'] + $stats['out_of_stock_count'];
$warning_alerts = $stats['warning_count'] + $stats['expired_count'];
$total_alerts = $critical_alerts + $warning_alerts;
?>

<div class="card">
    <div class="card-header bg-info py-2">
        <h3 class="card-title mt-2 mb-0"><i class="fas fa-fw fa-boxes mr-2"></i>Stock Levels Overview</h3>
        <div class="card-tools">
            <a href="inventory_add_item.php" class="btn btn-primary">
                <i class="fas fa-plus mr-2"></i>Add Item
            </a>
        </div>
    </div>

 
     
    </div>

    <div class="card-header pb-2 pt-3">
        <form autocomplete="off">
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search items, codes, descriptions..." autofocus>
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
                                <i class="fas fa-boxes text-primary mr-1"></i>
                                Total: <strong><?php echo $stats['total_items']; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-skull-crossbones text-danger mr-1"></i>
                                Critical: <strong><?php echo $critical_alerts; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-exclamation-triangle text-warning mr-1"></i>
                                Warnings: <strong><?php echo $warning_alerts; ?></strong>
                            </span>
                            <a href="?<?php echo $url_query_strings_sort ?>&export=pdf" class="btn btn-light border ml-2">
                                <i class="fa fa-fw fa-file-pdf mr-2"></i>Export
                            </a>
                            <a href="inventory_audit.php" class="btn btn-warning ml-2">
                                <i class="fas fa-clipboard-check mr-2"></i>Audit
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php 
                if ($category_filter || $supplier_filter || $stock_status_filter || $expiry_filter) { 
                    echo "show"; 
                } 
            ?>" id="advancedFilter">
                <div class="row">
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Category</label>
                            <select class="form-control select2" name="category" onchange="this.form.submit()">
                                <option value="">- All Categories -</option>
                                <?php
                                while($category = mysqli_fetch_assoc($categories_sql)) {
                                    $category_id = intval($category['category_id']);
                                    $category_name = nullable_htmlentities($category['category_name']);
                                    $category_type = nullable_htmlentities($category['category_type']);
                                    $display_name = "$category_type - $category_name";
                                    $selected = $category_filter == $category_id ? 'selected' : '';
                                    echo "<option value='$category_id' $selected>$display_name</option>";
                                }
                                ?>
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
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Stock Status</label>
                            <select class="form-control select2" name="stock_status" onchange="this.form.submit()">
                                <option value="">- All Status -</option>
                                <option value="critical" <?php echo $stock_status_filter == 'critical' ? 'selected' : ''; ?>>Critical Stock</option>
                                <option value="warning" <?php echo $stock_status_filter == 'warning' ? 'selected' : ''; ?>>Warning Level</option>
                                <option value="low_stock" <?php echo $stock_status_filter == 'low_stock' ? 'selected' : ''; ?>>Low Stock</option>
                                <option value="adequate" <?php echo $stock_status_filter == 'adequate' ? 'selected' : ''; ?>>Adequate Stock</option>
                                <option value="out_of_stock" <?php echo $stock_status_filter == 'out_of_stock' ? 'selected' : ''; ?>>Out of Stock</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Expiry Status</label>
                            <select class="form-control select2" name="expiry" onchange="this.form.submit()">
                                <option value="">- All Items -</option>
                                <option value="expiring_soon" <?php echo $expiry_filter == 'expiring_soon' ? 'selected' : ''; ?>>Expiring Soon (30 days)</option>
                                <option value="expired" <?php echo $expiry_filter == 'expired' ? 'selected' : ''; ?>>Expired Items</option>
                                <option value="no_expiry" <?php echo $expiry_filter == 'no_expiry' ? 'selected' : ''; ?>>No Expiry Date</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="row mt-2">
                    <div class="col-md-12">
                        <div class="form-group mb-0">
                            <label>Quick Stock Filters</label>
                            <div class="btn-group btn-group-toggle" data-toggle="buttons">
                                <a href="?stock_status=critical" class="btn btn-outline-danger btn-sm <?php echo $stock_status_filter == 'critical' ? 'active' : ''; ?>">
                                    <i class="fas fa-skull-crossbones mr-1"></i> Critical
                                </a>
                                <a href="?stock_status=warning" class="btn btn-outline-warning btn-sm <?php echo $stock_status_filter == 'warning' ? 'active' : ''; ?>">
                                    <i class="fas fa-exclamation-triangle mr-1"></i> Warning
                                </a>
                                <a href="?stock_status=low_stock" class="btn btn-outline-info btn-sm <?php echo $stock_status_filter == 'low_stock' ? 'active' : ''; ?>">
                                    <i class="fas fa-low-vision mr-1"></i> Low Stock
                                </a>
                                <a href="?stock_status=out_of_stock" class="btn btn-outline-dark btn-sm <?php echo $stock_status_filter == 'out_of_stock' ? 'active' : ''; ?>">
                                    <i class="fas fa-times-circle mr-1"></i> Out of Stock
                                </a>
                                <a href="?expiry=expiring_soon" class="btn btn-outline-secondary btn-sm <?php echo $expiry_filter == 'expiring_soon' ? 'active' : ''; ?>">
                                    <i class="fas fa-calendar-times mr-1"></i> Expiring Soon
                                </a>
                                <a href="inventory_stock.php" class="btn btn-outline-secondary btn-sm">
                                    <i class="fas fa-times mr-1"></i> Clear Filters
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
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=i.item_name&order=<?php echo $disp; ?>">
                        Item Name <?php if ($sort == 'i.item_name') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=i.item_code&order=<?php echo $disp; ?>">
                        Code <?php if ($sort == 'i.item_code') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=c.category_name&order=<?php echo $disp; ?>">
                        Category <?php if ($sort == 'c.category_name') { echo $order_icon; } ?>
                    </a>
                </th>
                <th class="text-center">
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=i.item_quantity&order=<?php echo $disp; ?>">
                        Current Stock <?php if ($sort == 'i.item_quantity') { echo $order_icon; } ?>
                    </a>
                </th>
                <th class="text-center">
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=stock_percentage&order=<?php echo $disp; ?>">
                        Stock Level <?php if ($sort == 'stock_percentage') { echo $order_icon; } ?>
                    </a>
                </th>
                <th class="text-center">
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=i.item_low_stock_alert&order=<?php echo $disp; ?>">
                        Alert Level <?php if ($sort == 'i.item_low_stock_alert') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=i.item_expiry_date&order=<?php echo $disp; ?>">
                        Expiry Date <?php if ($sort == 'i.item_expiry_date') { echo $order_icon; } ?>
                    </a>
                </th>
                <th class="text-center">Today's Usage</th>
                <th class="text-center">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php
            while ($row = mysqli_fetch_array($sql)) {
                $item_id = intval($row['item_id']);
                $item_name = nullable_htmlentities($row['item_name']);
                $item_code = nullable_htmlentities($row['item_code']);
                $item_description = nullable_htmlentities($row['item_description']);
                $category_name = nullable_htmlentities($row['category_name']);
                $category_type = nullable_htmlentities($row['category_type']);
                $item_quantity = intval($row['item_quantity']);
                $item_low_stock_alert = intval($row['item_low_stock_alert']);
                $item_unit_cost = floatval($row['item_unit_cost']);
                $item_expiry_date = nullable_htmlentities($row['item_expiry_date']);
                $today_usage = intval($row['today_usage']);
                $stock_percentage = floatval($row['stock_percentage']);
                $stock_level = $row['stock_level'];
                $supplier_name = nullable_htmlentities($row['supplier_name']);
                $stock_value = floatval($row['stock_value']);

                // Determine stock level styling
                $stock_level_class = '';
                $stock_level_icon = '';
                $progress_bar_class = '';
                
                switch($stock_level) {
                    case 'critical':
                        $stock_level_class = 'danger';
                        $stock_level_icon = 'fa-skull-crossbones';
                        $progress_bar_class = 'bg-danger';
                        $table_class = 'table-danger';
                        break;
                    case 'warning':
                        $stock_level_class = 'warning';
                        $stock_level_icon = 'fa-exclamation-triangle';
                        $progress_bar_class = 'bg-warning';
                        $table_class = 'table-warning';
                        break;
                    case 'low_stock':
                        $stock_level_class = 'info';
                        $stock_level_icon = 'fa-low-vision';
                        $progress_bar_class = 'bg-info';
                        $table_class = '';
                        break;
                    case 'adequate':
                        $stock_level_class = 'success';
                        $stock_level_icon = 'fa-check-circle';
                        $progress_bar_class = 'bg-success';
                        $table_class = 'table-success';
                        break;
                    case 'out_of_stock':
                        $stock_level_class = 'dark';
                        $stock_level_icon = 'fa-times-circle';
                        $progress_bar_class = 'bg-dark';
                        $table_class = 'table-dark';
                        break;
                    default:
                        $stock_level_class = 'secondary';
                        $stock_level_icon = 'fa-question-circle';
                        $progress_bar_class = 'bg-secondary';
                        $table_class = '';
                }

                // Check if expired or expiring soon
                $is_expired = false;
                $is_expiring_soon = false;
                if ($item_expiry_date) {
                    $expiry_date = strtotime($item_expiry_date);
                    $today = strtotime('today');
                    $thirty_days_later = strtotime('+30 days');
                    
                    if ($expiry_date < $today) {
                        $is_expired = true;
                        $table_class = 'table-danger';
                    } elseif ($expiry_date <= $thirty_days_later) {
                        $is_expiring_soon = true;
                        if ($table_class != 'table-danger' && $table_class != 'table-warning') {
                            $table_class = 'table-warning';
                        }
                    }
                }
                ?>
                <tr class="<?php echo $table_class; ?>">
                    <td>
                        <div class="font-weight-bold"><?php echo $item_name; ?></div>
                        <small class="text-muted"><?php echo truncate($item_description, 50); ?></small>
                        <?php if ($supplier_name): ?>
                            <div class="small"><span class="text-info">Supplier:</span> <?php echo $supplier_name; ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="text-muted font-weight-bold"><?php echo $item_code; ?></div>
                    </td>
                    <td>
                        <div class="font-weight-bold"><?php echo $category_name; ?></div>
                        <small class="text-muted"><?php echo $category_type; ?></small>
                    </td>
                    <td class="text-center">
                        <div class="d-flex align-items-center justify-content-center">
                            <i class="fas <?php echo $stock_level_icon; ?> text-<?php echo $stock_level_class; ?> mr-2"></i>
                            <div class="font-weight-bold text-<?php echo $stock_level_class; ?>">
                                <?php echo number_format($item_quantity); ?>
                            </div>
                        </div>
                        <?php if ($stock_value > 0): ?>
                            <small class="text-muted">$<?php echo number_format($stock_value, 2); ?></small>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?php if ($item_low_stock_alert > 0 && $item_quantity > 0): ?>
                            <div class="progress" style="height: 20px; width: 120px; margin: 0 auto;">
                                <div class="progress-bar <?php echo $progress_bar_class; ?>" 
                                     role="progressbar" 
                                     style="width: <?php echo min($stock_percentage, 100); ?>%"
                                     aria-valuenow="<?php echo $stock_percentage; ?>" 
                                     aria-valuemin="0" 
                                     aria-valuemax="100">
                                    <?php echo $stock_percentage; ?>%
                                </div>
                            </div>
                            <small class="text-muted">of alert level</small>
                        <?php elseif ($item_quantity <= 0): ?>
                            <span class="badge badge-dark">Out of Stock</span>
                        <?php else: ?>
                            <span class="badge badge-success">No Alert Set</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?php if($item_low_stock_alert > 0): ?>
                            <span class="font-weight-bold text-warning"><?php echo number_format($item_low_stock_alert); ?></span>
                        <?php else: ?>
                            <span class="text-muted">Not set</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if($item_expiry_date): ?>
                            <div class="font-weight-bold <?php echo $is_expired ? 'text-danger' : ($is_expiring_soon ? 'text-warning' : 'text-success'); ?>">
                                <?php echo date('M j, Y', strtotime($item_expiry_date)); ?>
                            </div>
                            <?php if($is_expired): ?>
                                <small class="text-danger"><i class="fas fa-exclamation-circle mr-1"></i>Expired</small>
                            <?php elseif($is_expiring_soon): ?>
                                <small class="text-warning"><i class="fas fa-clock mr-1"></i>Expiring soon</small>
                            <?php else: ?>
                                <small class="text-success">In date</small>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted">No expiry</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?php if($today_usage > 0): ?>
                            <span class="badge badge-info badge-pill"><?php echo $today_usage; ?></span>
                            <div class="small text-muted">Today</div>
                        <?php else: ?>
                            <span class="badge badge-secondary badge-pill">0</span>
                            <div class="small text-muted">Today</div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="dropdown dropleft text-center">
                            <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                <i class="fas fa-ellipsis-h"></i>
                            </button>
                            <div class="dropdown-menu">
                                <a class="dropdown-item" href="inventory_item_details.php?item_id=<?php echo $item_id; ?>">
                                    <i class="fas fa-fw fa-eye mr-2"></i>View Details
                                </a>
                                <a class="dropdown-item" href="inventory_adjust_stock.php?item_id=<?php echo $item_id; ?>">
                                    <i class="fas fa-fw fa-edit mr-2"></i>Adjust Stock
                                </a>
                                <?php if (in_array($stock_level, ['critical', 'warning', 'low_stock', 'out_of_stock'])): ?>
                                    <a class="dropdown-item text-warning" href="purchase_order_create.php?item_id=<?php echo $item_id; ?>">
                                        <i class="fas fa-fw fa-shopping-cart mr-2"></i>Create Order
                                    </a>
                                <?php endif; ?>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item" href="inventory_transaction.php?item_id=<?php echo $item_id; ?>">
                                    <i class="fas fa-fw fa-exchange-alt mr-2"></i>Record Transaction
                                </a>
                                <a class="dropdown-item text-info" href="inventory_edit_item.php?item_id=<?php echo $item_id; ?>">
                                    <i class="fas fa-fw fa-edit mr-2"></i>Edit Item
                                </a>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php
            } 
            
            if ($num_rows[0] == 0) {
                ?>
                <tr>
                    <td colspan="9" class="text-center py-5">
                        <i class="fas fa-boxes fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No Items Found</h5>
                        <p class="text-muted">No inventory items match your current filters.</p>
                        <a href="inventory_add_item.php" class="btn btn-success mt-2">
                            <i class="fas fa-plus mr-2"></i>Add First Item
                        </a>
                        <a href="inventory_stock.php" class="btn btn-secondary mt-2 ml-2">
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

    // Quick filter buttons
    $('.btn-group-toggle .btn').click(function(e) {
        e.preventDefault();
        window.location.href = $(this).attr('href');
    });

    // Auto-refresh every 5 minutes for real-time stock updates
    setInterval(function() {
        if ($('.modal:visible').length === 0) {
            window.location.reload();
        }
    }, 300000); // 5 minutes
});

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + N for new item
    if (e.ctrlKey && e.keyCode === 78) {
        e.preventDefault();
        window.location.href = 'inventory_add_item.php';
    }
    // Ctrl + F for focus search
    if (e.ctrlKey && e.keyCode === 70) {
        e.preventDefault();
        $('input[name="q"]').focus();
    }
    // Escape to clear filters
    if (e.keyCode === 27) {
        window.location.href = 'inventory_stock.php';
    }
});
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
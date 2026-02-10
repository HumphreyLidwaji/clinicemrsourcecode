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
$status_filter = $_GET['status'] ?? 'active';
$location_filter = $_GET['location'] ?? '';
$stock_filter = $_GET['stock'] ?? '';
$expiry_filter = $_GET['expiry'] ?? '';

// Search Query
$q = sanitizeInput($_GET['q'] ?? '');
if (!empty($q)) {
    $search_query = "AND (
        i.item_name LIKE '%$q%' 
        OR i.item_code LIKE '%$q%'
        OR ic.category_name LIKE '%$q%'
        OR s.supplier_name LIKE '%$q%'
        OR il.location_name LIKE '%$q%'
        OR ib.batch_number LIKE '%$q%'
    )";
} else {
    $search_query = '';
}

// Category Filter
if ($category_filter) {
    $category_query = "AND i.category_id = " . intval($category_filter);
} else {
    $category_query = '';
}

// Supplier Filter
if ($supplier_filter) {
    $supplier_query = "AND EXISTS (
        SELECT 1 FROM inventory_batches ib2 
        LEFT JOIN suppliers s2 ON ib2.supplier_id = s2.supplier_id 
        WHERE ib2.item_id = i.item_id AND ib2.supplier_id = " . intval($supplier_filter) . "
    )";
} else {
    $supplier_query = '';
}

// Location Filter
if ($location_filter) {
    $location_id = intval($location_filter);
    $location_query = "AND EXISTS (
        SELECT 1 FROM inventory_location_stock ils 
        WHERE ils.batch_id IN (SELECT batch_id FROM inventory_batches WHERE item_id = i.item_id) 
        AND ils.location_id = $location_id AND ils.quantity > 0
    )";
} else {
    $location_query = '';
}

// Status Filter
if ($status_filter && $status_filter != 'all') {
    $status_query = "AND i.status = '" . sanitizeInput($status_filter) . "'";
} else {
    $status_query = '';
}

// Stock Level Filter
if ($stock_filter) {
    switch($stock_filter) {
        case 'low':
            $stock_query = "AND (i.reorder_level > 0 AND total_quantity <= i.reorder_level AND total_quantity > 0)";
            break;
        case 'out':
            $stock_query = "AND total_quantity = 0";
            break;
        case 'healthy':
            $stock_query = "AND (total_quantity > i.reorder_level OR i.reorder_level = 0)";
            break;
        default:
            $stock_query = '';
    }
} else {
    $stock_query = '';
}

// Expiry Filter
if ($expiry_filter) {
    if ($expiry_filter == 'expired') {
        $expiry_query = "AND EXISTS (
            SELECT 1 FROM inventory_batches ib2 
            WHERE ib2.item_id = i.item_id 
            AND ib2.expiry_date IS NOT NULL 
            AND ib2.expiry_date < CURDATE()
            AND EXISTS (
                SELECT 1 FROM inventory_location_stock ils 
                WHERE ils.batch_id = ib2.batch_id 
                AND ils.quantity > 0
            )
        )";
    } elseif ($expiry_filter == 'expiring_soon') {
        $expiry_query = "AND EXISTS (
            SELECT 1 FROM inventory_batches ib2 
            WHERE ib2.item_id = i.item_id 
            AND ib2.expiry_date IS NOT NULL 
            AND ib2.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
            AND EXISTS (
                SELECT 1 FROM inventory_location_stock ils 
                WHERE ils.batch_id = ib2.batch_id 
                AND ils.quantity > 0
            )
        )";
    } else {
        $expiry_query = '';
    }
} else {
    $expiry_query = '';
}

// Main query for inventory items with enhanced location data - UPDATED FOR NEW SCHEMA
$sql = mysqli_query(
    $mysqli,
    "
    SELECT SQL_CALC_FOUND_ROWS 
        i.*,
        ic.category_name,
        ic.category_type,
        ic.description as category_description,
        i.unit_of_measure,
        i.reorder_level,
        i.status,
        u.user_name as added_by_name,
        COALESCE(SUM(ils.quantity), 0) as total_quantity,
        COUNT(DISTINCT ils.location_id) as location_count,
        COUNT(DISTINCT ib.batch_id) as batch_count,
        MIN(ib.expiry_date) as earliest_expiry_date,
        MAX(ib.expiry_date) as latest_expiry_date,
        (
            SELECT GROUP_CONCAT(DISTINCT s.supplier_name SEPARATOR ', ') 
            FROM inventory_batches ib2 
            LEFT JOIN suppliers s ON ib2.supplier_id = s.supplier_id 
            WHERE ib2.item_id = i.item_id
            AND ib2.supplier_id IS NOT NULL
        ) as suppliers_list,
        CASE 
            WHEN COALESCE(SUM(ils.quantity), 0) = 0 THEN 'Out of Stock'
            WHEN i.reorder_level > 0 AND COALESCE(SUM(ils.quantity), 0) <= i.reorder_level THEN 'Low Stock'
            ELSE 'In Stock'
        END as stock_status
    FROM inventory_items i
    LEFT JOIN inventory_categories ic ON i.category_id = ic.category_id
    LEFT JOIN inventory_batches ib ON i.item_id = ib.item_id
    LEFT JOIN inventory_location_stock ils ON ib.batch_id = ils.batch_id
    LEFT JOIN users u ON i.created_by = u.user_id
    WHERE i.is_active = 1
      $status_query
      $category_query
      $location_query
      $search_query
      $supplier_query
    GROUP BY i.item_id, ic.category_id, u.user_id
    HAVING 1=1
      $stock_query
      $expiry_query
    ORDER BY $sort $order
    LIMIT $record_from, $record_to
");

if (!$sql) {
    die("Query failed: " . mysqli_error($mysqli));
}

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get enhanced statistics with location data
$stats_sql = mysqli_query($mysqli, "
    SELECT 
        COUNT(DISTINCT i.item_id) as total_items,
        SUM(CASE WHEN COALESCE(total_qty, 0) = 0 THEN 1 ELSE 0 END) as out_of_stock_count,
        SUM(CASE WHEN i.reorder_level > 0 AND COALESCE(total_qty, 0) > 0 AND COALESCE(total_qty, 0) <= i.reorder_level THEN 1 ELSE 0 END) as low_stock_count,
        SUM(CASE WHEN COALESCE(total_qty, 0) > i.reorder_level OR i.reorder_level = 0 THEN 1 ELSE 0 END) as healthy_stock_count,
        COALESCE(SUM(total_qty), 0) as total_quantity,
        COALESCE(SUM(total_value), 0) as total_inventory_value,
        SUM(CASE WHEN expired_batches > 0 THEN 1 ELSE 0 END) as expired_items_count,
        SUM(CASE WHEN expiring_soon_batches > 0 THEN 1 ELSE 0 END) as expiring_soon_items_count,
        (SELECT COUNT(DISTINCT location_id) FROM inventory_location_stock WHERE quantity > 0 AND is_active = 1) as active_locations_count,
        (SELECT COUNT(*) FROM inventory_locations WHERE is_active = 1) as total_locations_count
    FROM inventory_items i
    LEFT JOIN (
        SELECT 
            ib.item_id,
            SUM(ils.quantity) as total_qty,
            SUM(ils.quantity * ils.unit_cost) as total_value,
            SUM(CASE WHEN ib.expiry_date < CURDATE() AND EXISTS (
                SELECT 1 FROM inventory_location_stock ils2 
                WHERE ils2.batch_id = ib.batch_id AND ils2.quantity > 0
            ) THEN 1 ELSE 0 END) as expired_batches,
            SUM(CASE WHEN ib.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND EXISTS (
                SELECT 1 FROM inventory_location_stock ils2 
                WHERE ils2.batch_id = ib.batch_id AND ils2.quantity > 0
            ) THEN 1 ELSE 0 END) as expiring_soon_batches
        FROM inventory_batches ib
        LEFT JOIN inventory_location_stock ils ON ib.batch_id = ils.batch_id AND ils.is_active = 1
        WHERE ib.is_active = 1
        GROUP BY ib.item_id
    ) as stock_info ON i.item_id = stock_info.item_id
    WHERE i.is_active = 1
      $status_query
      $category_query
      $search_query
      $supplier_query
");

if ($stats_sql) {
    $stats = mysqli_fetch_assoc($stats_sql);
} else {
    $stats = [
        'total_items' => 0,
        'out_of_stock_count' => 0,
        'low_stock_count' => 0,
        'healthy_stock_count' => 0,
        'total_quantity' => 0,
        'total_inventory_value' => 0,
        'expired_items_count' => 0,
        'expiring_soon_items_count' => 0,
        'active_locations_count' => 0,
        'total_locations_count' => 0
    ];
}

// Get recent transactions for dashboard
$recent_transactions_sql = "
    SELECT t.transaction_type, t.quantity, t.created_at, 
           i.item_name, i.item_code, u.user_name,
           t.reason,
           fl.location_name as from_location,
           tl.location_name as to_location
    FROM inventory_transactions t
    JOIN inventory_items i ON t.item_id = i.item_id
    JOIN users u ON t.created_by = u.user_id
    LEFT JOIN inventory_locations fl ON t.from_location_id = fl.location_id
    LEFT JOIN inventory_locations tl ON t.to_location_id = tl.location_id
    WHERE t.is_active = 1
    ORDER BY t.created_at DESC 
    LIMIT 10
";
$recent_transactions = mysqli_query($mysqli, $recent_transactions_sql);

// Get categories for filter
$categories_sql = mysqli_query($mysqli, "SELECT category_id, category_name, category_type FROM inventory_categories WHERE is_active = 1 ORDER BY category_name");

// Get suppliers for filter
$suppliers_sql = mysqli_query($mysqli, "SELECT supplier_id, supplier_name FROM suppliers ORDER BY supplier_name");

// Get locations for filter
$locations_sql = mysqli_query($mysqli, "SELECT location_id, location_name, location_type FROM inventory_locations WHERE is_active = 1 ORDER BY location_name");

// Get pending requisitions count
$pending_reqs_sql = "SELECT COUNT(*) as count FROM inventory_requisitions WHERE status = 'pending' AND is_active = 1";
$pending_reqs_result = mysqli_query($mysqli, $pending_reqs_sql);
$pending_requisitions = mysqli_fetch_assoc($pending_reqs_result)['count'];

// Get pending purchase orders count
$pending_po_sql = "SELECT COUNT(*) as count FROM inventory_purchase_orders WHERE status IN ('draft', 'submitted', 'approved') AND is_active = 1";
$pending_po_result = mysqli_query($mysqli, $pending_po_sql);
$pending_purchase_orders = mysqli_fetch_assoc($pending_po_result)['count'];

// Get today's transactions summary
$today_transactions_sql = "
    SELECT 
        COUNT(*) as count,
        SUM(CASE WHEN transaction_type IN ('GRN') THEN 1 ELSE 0 END) as in_count,
        SUM(CASE WHEN transaction_type IN ('ISSUE', 'WASTAGE', 'RETURN') THEN 1 ELSE 0 END) as out_count,
        SUM(CASE WHEN transaction_type IN ('TRANSFER_OUT', 'TRANSFER_IN') THEN 1 ELSE 0 END) as transfer_count
    FROM inventory_transactions 
    WHERE DATE(created_at) = CURDATE()
    AND is_active = 1
";
$today_transactions_result = mysqli_query($mysqli, $today_transactions_sql);
$today_transactions = mysqli_fetch_assoc($today_transactions_result);
?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2 mb-0 text-white"><i class="fas fa-fw fa-boxes mr-2"></i>Inventory Dashboard</h3>
        <div class="card-tools">
            <div class="btn-group">
                <?php if (SimplePermission::any(['inventory_dashboard', '*'])): ?>
                <a href="inventory_item_create.php" class="btn btn-success">
                    <i class="fas fa-plus mr-2"></i>New Item
                </a>
                <?php endif; ?>

                <?php if (SimplePermission::any(['inventory_transactions', 'transaction_view', '*'])): ?>
                <a href="inventory_transactions.php" class="btn btn-primary ml-2">
                    <i class="fas fa-exchange-alt mr-2"></i>Transactions
                </a>
                <?php endif; ?>

                <?php if (SimplePermission::any(['inventory_grn', 'grn_create', '*'])): ?>
                <a href="inventory_grns.php" class="btn btn-warning ml-2">
                    <i class="fas fa-receipt mr-2"></i>GRNs
                </a>
                <?php endif; ?>

                <?php if (SimplePermission::any(['inventory_requisition', 'requisition_create', '*'])): ?>
                <a href="inventory_requisitions.php" class="btn btn-info ml-2">
                    <i class="fas fa-clipboard-list mr-2"></i>Requisitions
                    <?php if ($pending_requisitions > 0): ?>
                        <span class="badge badge-danger"><?php echo $pending_requisitions; ?></span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>

                <?php if (SimplePermission::any(['inventory_po', 'po_create', '*'])): ?>
                <a href="inventory_purchase_orders.php" class="btn btn-purple ml-2">
                    <i class="fas fa-file-purchase mr-2"></i>Purchase Orders
                    <?php if ($pending_purchase_orders > 0): ?>
                        <span class="badge badge-danger"><?php echo $pending_purchase_orders; ?></span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>

                <div class="btn-group ml-2">
                    <button type="button" class="btn btn-secondary dropdown-toggle" data-toggle="dropdown">
                        <i class="fas fa-tasks mr-2"></i>Quick Actions
                    </button>
                    <div class="dropdown-menu">
                        <a class="dropdown-item" href="inventory_locations.php">
                            <i class="fas fa-map-marker-alt mr-2"></i>Manage Locations
                            <span class="badge badge-light float-right"><?php echo $stats['total_locations_count']; ?></span>
                        </a>
                        <a href="suppliers.php" class="dropdown-item">
                            <i class="fas fa-truck mr-2"></i>Suppliers
                        </a>
                        <a href="inventory_categories.php" class="dropdown-item">
                            <i class="fas fa-tags mr-2"></i>Categories
                        </a>
                        <a class="dropdown-item" href="inventory_batches.php">
                            <i class="fas fa-layer-group mr-2"></i>Batches
                        </a>
                        <?php if (SimplePermission::any(['inventory_audit', 'audit_manage', '*'])): ?>
                        <a href="inventory_audit.php" class="dropdown-item">
                            <i class="fas fa-clipboard-check mr-2"></i>Inventory Audit
                        </a>
                        <?php endif; ?>
                        <div class="dropdown-divider"></div>
                        <?php if (SimplePermission::any(['inventory_reports', 'report_view', '*'])): ?>
                        <a class="dropdown-item" href="inventory_reports.php">
                            <i class="fas fa-chart-bar mr-2"></i>View Reports
                        </a>
                        <?php endif; ?>
                        <a class="dropdown-item" href="inventory_export.php">
                            <i class="fas fa-file-export mr-2"></i>Export Data
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Enhanced Stats Dashboard -->
    <div class="card-body bg-light border-bottom">
        <div class="row text-center">
            <!-- Total Inventory Value -->
            <div class="col-md-2 mb-3">
                <div class="card bg-primary text-white">
                    <div class="card-body py-3">
                        <i class="fas fa-dollar-sign fa-2x mb-2"></i>
                        <h5 class="card-title">Total Value</h5>
                        <h3 class="font-weight-bold">$<?php echo number_format($stats['total_inventory_value'] ?? '0', 2); ?></h3>
                        <small class="opacity-8"><?php echo $stats['total_items']; ?> items</small>
                    </div>
                </div>
            </div>
            
            <!-- Stock Status -->
            <div class="col-md-2 mb-3">
                <div class="card bg-success text-white">
                    <div class="card-body py-3">
                        <i class="fas fa-check-circle fa-2x mb-2"></i>
                        <h5 class="card-title">Healthy Stock</h5>
                        <h3 class="font-weight-bold"><?php echo $stats['healthy_stock_count']; ?></h3>
                        <small class="opacity-8">Good condition</small>
                    </div>
                </div>
            </div>
            
            <!-- Low Stock Alert -->
            <div class="col-md-2 mb-3">
                <div class="card bg-warning text-dark">
                    <div class="card-body py-3">
                        <i class="fas fa-exclamation-triangle fa-2x mb-2"></i>
                        <h5 class="card-title">Low Stock</h5>
                        <h3 class="font-weight-bold"><?php echo $stats['low_stock_count']; ?></h3>
                        <small class="opacity-8">Needs attention</small>
                    </div>
                </div>
            </div>
            
            <!-- Out of Stock -->
            <div class="col-md-2 mb-3">
                <div class="card bg-danger text-white">
                    <div class="card-body py-3">
                        <i class="fas fa-times-circle fa-2x mb-2"></i>
                        <h5 class="card-title">Out of Stock</h5>
                        <h3 class="font-weight-bold"><?php echo $stats['out_of_stock_count']; ?></h3>
                        <small class="opacity-8">Restock needed</small>
                    </div>
                </div>
            </div>
            
            <!-- Location Coverage -->
            <div class="col-md-2 mb-3">
                <div class="card bg-info text-white">
                    <div class="card-body py-3">
                        <i class="fas fa-map-marker-alt fa-2x mb-2"></i>
                        <h5 class="card-title">Active Locations</h5>
                        <h3 class="font-weight-bold"><?php echo $stats['active_locations_count']; ?></h3>
                        <small class="opacity-8">of <?php echo $stats['total_locations_count']; ?> total</small>
                    </div>
                </div>
            </div>
            
            <!-- Today's Activity -->
            <div class="col-md-2 mb-3">
                <div class="card bg-dark text-white">
                    <div class="card-body py-3">
                        <i class="fas fa-exchange-alt fa-2x mb-2"></i>
                        <h5 class="card-title">Today's Activity</h5>
                        <h3 class="font-weight-bold"><?php echo $today_transactions['count']; ?></h3>
                        <small class="opacity-8">transactions</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Alert Row -->
    <?php if ($stats['low_stock_count'] > 0 || $stats['out_of_stock_count'] > 0 || $stats['expired_items_count'] > 0 || $stats['expiring_soon_items_count'] > 0): ?>
    <div class="row mt-3">
        <div class="col-12">
            <div class="alert-container">
                <?php if ($stats['low_stock_count'] > 0): ?>
                <div class="alert alert-warning alert-dismissible fade show mb-2" role="alert">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    <strong><?php echo $stats['low_stock_count']; ?> item(s)</strong> are running low on stock.
                    <a href="?stock=low" class="alert-link ml-2">View Low Stock Items</a>
                    <button type="button" class="close" data-dismiss="alert">
                        <span>&times;</span>
                    </button>
                </div>
                <?php endif; ?>
                
                <?php if ($stats['out_of_stock_count'] > 0): ?>
                <div class="alert alert-danger alert-dismissible fade show mb-2" role="alert">
                    <i class="fas fa-times-circle mr-2"></i>
                    <strong><?php echo $stats['out_of_stock_count']; ?> item(s)</strong> are out of stock.
                    <a href="?stock=out" class="alert-link ml-2">View Out of Stock Items</a>
                    <button type="button" class="close" data-dismiss="alert">
                        <span>&times;</span>
                    </button>
                </div>
                <?php endif; ?>
                
                <?php if ($stats['expired_items_count'] > 0): ?>
                <div class="alert alert-dark alert-dismissible fade show mb-2" role="alert">
                    <i class="fas fa-calendar-times mr-2"></i>
                    <strong><?php echo $stats['expired_items_count']; ?> item(s)</strong> have expired stock.
                    <a href="?expiry=expired" class="alert-link ml-2">View Expired Items</a>
                    <button type="button" class="close" data-dismiss="alert">
                        <span>&times;</span>
                    </button>
                </div>
                <?php endif; ?>

                <?php if ($stats['expiring_soon_items_count'] > 0): ?>
                <div class="alert alert-info alert-dismissible fade show mb-2" role="alert">
                    <i class="fas fa-clock mr-2"></i>
                    <strong><?php echo $stats['expiring_soon_items_count']; ?> item(s)</strong> have stock expiring soon.
                    <a href="?expiry=expiring_soon" class="alert-link ml-2">View Expiring Items</a>
                    <button type="button" class="close" data-dismiss="alert">
                        <span>&times;</span>
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="card-header pb-2 pt-3">
        <form autocomplete="off">
            <div class="row">
                <div class="col-md-5">
                    <div class="form-group">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search items, codes, batches, locations..." autofocus>
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
                            <span class="btn btn-light border" data-toggle="tooltip" title="Total Items">
                                <i class="fas fa-boxes text-dark mr-1"></i>
                                <strong><?php echo $stats['total_items']; ?></strong>
                            </span>
                            <span class="btn btn-light border" data-toggle="tooltip" title="Healthy Stock">
                                <i class="fas fa-check-circle text-success mr-1"></i>
                                <strong><?php echo $stats['healthy_stock_count']; ?></strong>
                            </span>
                            <span class="btn btn-light border" data-toggle="tooltip" title="Low Stock">
                                <i class="fas fa-exclamation-triangle text-warning mr-1"></i>
                                <strong><?php echo $stats['low_stock_count']; ?></strong>
                            </span>
                            <span class="btn btn-light border" data-toggle="tooltip" title="Out of Stock">
                                <i class="fas fa-times-circle text-danger mr-1"></i>
                                <strong><?php echo $stats['out_of_stock_count']; ?></strong>
                            </span>
                            <a href="inventory_requisitions.php" class="btn btn-info ml-2">
                                <i class="fas fa-fw fa-clipboard-list mr-2"></i>Requisitions
                                <?php if ($pending_requisitions > 0): ?>
                                    <span class="badge badge-danger"><?php echo $pending_requisitions; ?></span>
                                <?php endif; ?>
                            </a>
                            <a href="inventory_purchase_orders.php" class="btn btn-purple ml-2">
                                <i class="fas fa-fw fa-file-purchase mr-2"></i>POs
                                <?php if ($pending_purchase_orders > 0): ?>
                                    <span class="badge badge-danger"><?php echo $pending_purchase_orders; ?></span>
                                <?php endif; ?>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php if ($category_filter || $supplier_filter || $location_filter || $status_filter || $stock_filter || $expiry_filter) { echo "show"; } ?>" id="advancedFilter">
                <div class="row">
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Category</label>
                            <select class="form-control select2" name="category" onchange="this.form.submit()">
                                <option value="">- All Categories -</option>
                                <?php while($category = mysqli_fetch_assoc($categories_sql)): ?>
                                    <option value="<?php echo $category['category_id']; ?>" <?php if ($category_filter == $category['category_id']) { echo "selected"; } ?>>
                                        <?php echo htmlspecialchars($category['category_name']); ?> (<?php echo $category['category_type']; ?>)
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Supplier</label>
                            <select class="form-control select2" name="supplier" onchange="this.form.submit()">
                                <option value="">- All Suppliers -</option>
                                <?php while($supplier = mysqli_fetch_assoc($suppliers_sql)): ?>
                                    <option value="<?php echo $supplier['supplier_id']; ?>" <?php if ($supplier_filter == $supplier['supplier_id']) { echo "selected"; } ?>>
                                        <?php echo htmlspecialchars($supplier['supplier_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Location</label>
                            <select class="form-control select2" name="location" onchange="this.form.submit()">
                                <option value="">- All Locations -</option>
                                <?php while($location = mysqli_fetch_assoc($locations_sql)): ?>
                                    <option value="<?php echo $location['location_id']; ?>" <?php if ($location_filter == $location['location_id']) { echo "selected"; } ?>>
                                        <?php echo htmlspecialchars($location['location_name']); ?> (<?php echo $location['location_type']; ?>)
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Quick Actions</label>
                            <div class="btn-group btn-block">
                                <a href="inventory_items.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times mr-2"></i>Clear Filters
                                </a>
                                <a href="inventory_item_create.php" class="btn btn-success">
                                    <i class="fas fa-plus mr-2"></i>New Item
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row mt-2">
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Item Status</label>
                            <select class="form-control select2" name="status" onchange="this.form.submit()">
                                <option value="all">- All Statuses -</option>
                                <option value="active" <?php if ($status_filter == "active") { echo "selected"; } ?>>Active</option>
                                <option value="inactive" <?php if ($status_filter == "inactive") { echo "selected"; } ?>>Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Stock Level</label>
                            <select class="form-control select2" name="stock" onchange="this.form.submit()">
                                <option value="">- All Levels -</option>
                                <option value="healthy" <?php if ($stock_filter == "healthy") { echo "selected"; } ?>>Healthy Stock</option>
                                <option value="low" <?php if ($stock_filter == "low") { echo "selected"; } ?>>Low Stock</option>
                                <option value="out" <?php if ($stock_filter == "out") { echo "selected"; } ?>>Out of Stock</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Expiry Status</label>
                            <select class="form-control select2" name="expiry" onchange="this.form.submit()">
                                <option value="">- All Items -</option>
                                <option value="expiring_soon" <?php if ($expiry_filter == "expiring_soon") { echo "selected"; } ?>>Expiring Soon</option>
                                <option value="expired" <?php if ($expiry_filter == "expired") { echo "selected"; } ?>>Expired</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Quick Filters</label>
                            <div class="btn-group btn-group-toggle" data-toggle="buttons">
                                <a href="?stock=low" class="btn btn-outline-warning btn-sm <?php echo $stock_filter == 'low' ? 'active' : ''; ?>">
                                    <i class="fas fa-exclamation-triangle mr-1"></i> Low Stock
                                </a>
                                <a href="?stock=out" class="btn btn-outline-danger btn-sm <?php echo $stock_filter == 'out' ? 'active' : ''; ?>">
                                    <i class="fas fa-times-circle mr-1"></i> Out of Stock
                                </a>
                                <a href="?expiry=expiring_soon" class="btn btn-outline-info btn-sm <?php echo $expiry_filter == 'expiring_soon' ? 'active' : ''; ?>">
                                    <i class="fas fa-clock mr-1"></i> Expiring Soon
                                </a>
                                <a href="?expiry=expired" class="btn btn-outline-dark btn-sm <?php echo $expiry_filter == 'expired' ? 'active' : ''; ?>">
                                    <i class="fas fa-calendar-times mr-1"></i> Expired
                                </a>
                                <a href="?status=inactive" class="btn btn-outline-secondary btn-sm <?php echo $status_filter == 'inactive' ? 'active' : ''; ?>">
                                    <i class="fas fa-ban mr-1"></i> Inactive Items
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
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=ic.category_name&order=<?php echo $disp; ?>">
                        Category <?php if ($sort == 'ic.category_name') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=i.unit_of_measure&order=<?php echo $disp; ?>">
                        UOM <?php if ($sort == 'i.unit_of_measure') { echo $order_icon; } ?>
                    </a>
                </th>
                <th class="text-center">
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=total_quantity&order=<?php echo $disp; ?>">
                        Stock <?php if ($sort == 'total_quantity') { echo $order_icon; } ?>
                    </a>
                </th>
                <th class="text-center">
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=location_count&order=<?php echo $disp; ?>">
                        Locations <?php if ($sort == 'location_count') { echo $order_icon; } ?>
                    </a>
                </th>
                <th class="text-center">
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=batch_count&order=<?php echo $disp; ?>">
                        Batches <?php if ($sort == 'batch_count') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=i.status&order=<?php echo $disp; ?>">
                        Status <?php if ($sort == 'i.status') { echo $order_icon; } ?>
                    </a>
                </th>
                <th class="text-center">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php 
            if ($num_rows[0] == 0) {
                ?>
                <tr>
                    <td colspan="9" class="text-center py-5">
                        <i class="fas fa-boxes fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No inventory items found</h5>
                        <p class="text-muted">
                            <?php 
                            if ($q || $category_filter || $supplier_filter || $location_filter || $status_filter || $stock_filter || $expiry_filter) {
                                echo "Try adjusting your search or filter criteria.";
                            } else {
                                echo "Get started by adding your first inventory item.";
                            }
                            ?>
                        </p>
                        <a href="inventory_item_create.php" class="btn btn-primary">
                            <i class="fas fa-plus mr-2"></i>Add First Item
                        </a>
                        <?php if ($q || $category_filter || $supplier_filter || $location_filter || $status_filter || $stock_filter || $expiry_filter): ?>
                            <a href="inventory_items.php" class="btn btn-secondary ml-2">
                                <i class="fas fa-times mr-2"></i>Clear Filters
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php
            } else {
                while ($row = mysqli_fetch_array($sql)) {
                    $item_id = intval($row['item_id']);
                    $item_name = nullable_htmlentities($row['item_name']);
                    $item_code = nullable_htmlentities($row['item_code']);
                    $category_name = nullable_htmlentities($row['category_name']);
                    $category_type = nullable_htmlentities($row['category_type']);
                    $category_description = nullable_htmlentities($row['category_description']);
                    $unit_of_measure = nullable_htmlentities($row['unit_of_measure']);
                    $reorder_level = floatval($row['reorder_level']);
                    $status = nullable_htmlentities($row['status']);
                    $total_quantity = floatval($row['total_quantity']);
                    $location_count = intval($row['location_count']);
                    $batch_count = intval($row['batch_count']);
                    $earliest_expiry_date = nullable_htmlentities($row['earliest_expiry_date']);
                    $latest_expiry_date = nullable_htmlentities($row['latest_expiry_date']);
                    $suppliers_list = nullable_htmlentities($row['suppliers_list']);
                    $stock_status = nullable_htmlentities($row['stock_status']);
                    $is_drug = intval($row['is_drug']);
                    $requires_batch = intval($row['requires_batch']);
                    $added_by_name = nullable_htmlentities($row['added_by_name']);

                    // Stock level badge styling
                    $stock_badge = "";
                    $stock_icon = "";
                    if ($total_quantity == 0) {
                        $stock_badge = "badge-danger";
                        $stock_icon = "fa-times-circle";
                    } elseif ($reorder_level > 0 && $total_quantity <= $reorder_level) {
                        $stock_badge = "badge-warning";
                        $stock_icon = "fa-exclamation-triangle";
                    } else {
                        $stock_badge = "badge-success";
                        $stock_icon = "fa-check-circle";
                    }

                    // Status badge styling
                    $status_badge = "";
                    switch($status) {
                        case 'active':
                            $status_badge = "badge-success";
                            break;
                        case 'inactive':
                            $status_badge = "badge-secondary";
                            break;
                        default:
                            $status_badge = "badge-light";
                    }

                    // Check expiry status
                    $is_expired = false;
                    $is_expiring_soon = false;
                    if ($earliest_expiry_date) {
                        $expiry_date = strtotime($earliest_expiry_date);
                        $today = strtotime('today');
                        $thirty_days_later = strtotime('+30 days');
                        
                        if ($expiry_date < $today) {
                            $is_expired = true;
                        } elseif ($expiry_date <= $thirty_days_later) {
                            $is_expiring_soon = true;
                        }
                    }

                    // Drug indicator
                    $drug_badge = "";
                    if ($is_drug) {
                        $drug_badge = '<span class="badge badge-danger mr-1"><i class="fas fa-pills"></i> Drug</span>';
                    }

                    // Batch requirement indicator
                    $batch_badge = "";
                    if ($requires_batch) {
                        $batch_badge = '<span class="badge badge-info mr-1"><i class="fas fa-layer-group"></i> Batch</span>';
                    }
                    ?>
                    <tr class="<?php echo $is_expired ? 'table-danger' : ($is_expiring_soon ? 'table-warning' : ''); ?>">
                        <td>
                            <div class="font-weight-bold"><?php echo $item_name; ?></div>
                            <?php echo $drug_badge; ?>
                            <?php echo $batch_badge; ?>
                            <?php if ($suppliers_list): ?>
                                <small class="text-muted d-block">
                                    <i class="fas fa-truck mr-1"></i><?php echo $suppliers_list; ?>
                                </small>
                            <?php endif; ?>
                            <?php if ($added_by_name): ?>
                                <small class="text-muted d-block">
                                    <i class="fas fa-user mr-1"></i>Added by: <?php echo $added_by_name; ?>
                                </small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="font-weight-bold text-primary"><?php echo $item_code; ?></div>
                            <?php if ($reorder_level > 0): ?>
                                <small class="text-muted d-block">Reorder at: <?php echo $reorder_level; ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($category_name): ?>
                                <span class="badge badge-info">
                                    <?php echo $category_name; ?>
                                </span>
                                <?php if ($category_type): ?>
                                    <small class="d-block text-muted"><?php echo $category_type; ?></small>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="badge badge-light">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-light"><?php echo $unit_of_measure; ?></span>
                        </td>
                        <td class="text-center">
                            <span class="badge <?php echo $stock_badge; ?> badge-pill">
                                <i class="fas <?php echo $stock_icon; ?> mr-1"></i>
                                <?php echo number_format($total_quantity, 3); ?>
                            </span>
                            <?php if ($stock_status == 'Low Stock' && $reorder_level > 0): ?>
                                <small class="d-block text-warning">
                                    <i class="fas fa-exclamation-circle mr-1"></i>Below reorder level
                                </small>
                            <?php endif; ?>
                            <?php if ($earliest_expiry_date && $latest_expiry_date): ?>
                                <small class="d-block text-muted">
                                    <i class="fas fa-clock mr-1"></i>
                                    <?php echo date('M j, Y', strtotime($earliest_expiry_date)); ?>
                                    <?php if ($earliest_expiry_date != $latest_expiry_date): ?>
                                        - <?php echo date('M j, Y', strtotime($latest_expiry_date)); ?>
                                    <?php endif; ?>
                                </small>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($location_count > 0): ?>
                                <span class="badge badge-primary badge-pill">
                                    <i class="fas fa-map-marker-alt mr-1"></i>
                                    <?php echo $location_count; ?>
                                </span>
                            <?php else: ?>
                                <span class="badge badge-secondary">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($batch_count > 0): ?>
                                <span class="badge badge-dark badge-pill">
                                    <i class="fas fa-layer-group mr-1"></i>
                                    <?php echo $batch_count; ?>
                                </span>
                            <?php else: ?>
                                <span class="badge badge-secondary">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?php echo $status_badge; ?>"><?php echo ucfirst($status); ?></span>
                            <?php if ($is_expired): ?>
                                <small class="d-block text-danger">
                                    <i class="fas fa-calendar-times mr-1"></i>Has expired stock
                                </small>
                            <?php elseif ($is_expiring_soon): ?>
                                <small class="d-block text-warning">
                                    <i class="fas fa-clock mr-1"></i>Stock expiring soon
                                </small>
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
                                    <a class="dropdown-item" href="inventory_edit_item.php?item_id=<?php echo $item_id; ?>">
                                        <i class="fas fa-fw fa-edit mr-2"></i>Edit Item
                                    </a>
                                    <a class="dropdown-item" href="inventory_batches.php?item_id=<?php echo $item_id; ?>">
                                        <i class="fas fa-fw fa-layer-group mr-2"></i>View Batches
                                    </a>
                                    <?php if ($status == 'active'): ?>
                                        <a class="dropdown-item text-info" href="inventory_requisition_create.php?item_id=<?php echo $item_id; ?>">
                                            <i class="fas fa-fw fa-clipboard-list mr-2"></i>Create Requisition
                                        </a>
                                        <a class="dropdown-item text-success" href="inventory_purchase_order_create.php?item_id=<?php echo $item_id; ?>">
                                            <i class="fas fa-fw fa-file-purchase mr-2"></i>Create Purchase Order
                                        </a>
                                        <a class="dropdown-item text-warning" href="inventory_transaction_record.php?item_id=<?php echo $item_id; ?>">
                                            <i class="fas fa-fw fa-exchange-alt mr-2"></i>Record Transaction
                                        </a>
                                    <?php endif; ?>
                                    <div class="dropdown-divider"></div>
                                    <?php if ($status == 'inactive'): ?>
                                        <a class="dropdown-item text-success" href="post.php?activate_item=<?php echo $item_id; ?>">
                                            <i class="fas fa-fw fa-redo mr-2"></i>Activate
                                        </a>
                                    <?php else: ?>
                                        <a class="dropdown-item text-danger" href="post.php?deactivate_item=<?php echo $item_id; ?>">
                                            <i class="fas fa-fw fa-ban mr-2"></i>Deactivate
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php
                }
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
    $('[data-toggle="tooltip"]').tooltip();

    // Auto-submit when filters change
    $('select[name="category"], select[name="supplier"], select[name="location"], select[name="status"], select[name="stock"], select[name="expiry"]').change(function() {
        $(this).closest('form').submit();
    });

    // Quick filter buttons
    $('.btn-group-toggle .btn').click(function(e) {
        e.preventDefault();
        window.location.href = $(this).attr('href');
    });
});

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + N for new item
    if (e.ctrlKey && e.keyCode === 78) {
        e.preventDefault();
        window.location.href = 'inventory_item_create.php';
    }
    // Ctrl + R for requisitions
    if (e.ctrlKey && e.keyCode === 82) {
        e.preventDefault();
        window.location.href = 'inventory_requisitions.php';
    }
    // Ctrl + T for transactions
    if (e.ctrlKey && e.keyCode === 84) {
        e.preventDefault();
        window.location.href = 'inventory_transactions.php';
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
    // Ctrl + G for GRNs
    if (e.ctrlKey && e.keyCode === 71) {
        e.preventDefault();
        window.location.href = 'inventory_grns.php';
    }
});
</script>

<style>
.card .card-body {
    padding: 1rem;
}

.badge-pill {
    padding: 0.5em 0.8em;
}

.btn-group-toggle .btn.active {
    background-color: #007bff;
    border-color: #007bff;
    color: white;
}

.alert-container .alert {
    margin-bottom: 0.5rem;
}

.btn-purple {
    background-color: #6f42c1;
    border-color: #6f42c1;
    color: white;
}

.btn-purple:hover {
    background-color: #5a32a3;
    border-color: #5a32a3;
    color: white;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
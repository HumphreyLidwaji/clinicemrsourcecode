<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Date range parameters
$start_date = sanitizeInput($_GET['start_date'] ?? date('Y-m-01'));
$end_date = sanitizeInput($_GET['end_date'] ?? date('Y-m-t'));
$report_type = sanitizeInput($_GET['report_type'] ?? 'stock_summary');
$category_filter = $_GET['category'] ?? '';
$supplier_filter = $_GET['supplier'] ?? '';
$location_filter = $_GET['location'] ?? '';

// Validate dates
if (!strtotime($start_date)) $start_date = date('Y-m-01');
if (!strtotime($end_date)) $end_date = date('Y-m-t');

// Get overall statistics - UPDATED FOR NEW SCHEMA
$stats_sql = mysqli_query($mysqli,
    "SELECT 
        COUNT(DISTINCT i.item_id) as total_items,
        SUM(ils.quantity) as total_quantity,
        SUM(ils.quantity * ils.unit_cost) as total_value,
        SUM(CASE WHEN ils.quantity <= 0 THEN 1 ELSE 0 END) as out_of_stock_count,
        SUM(CASE WHEN ils.quantity > 0 AND ils.quantity <= i.reorder_level THEN 1 ELSE 0 END) as low_stock_count,
        COUNT(DISTINCT CASE WHEN ib.expiry_date < CURDATE() AND ils.quantity > 0 THEN ib.batch_id END) as expired_count,
        COUNT(DISTINCT CASE WHEN ib.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND ils.quantity > 0 THEN ib.batch_id END) as expiring_soon_count
    FROM inventory_items i
    LEFT JOIN inventory_batches ib ON i.item_id = ib.item_id AND ib.is_active = 1
    LEFT JOIN inventory_location_stock ils ON ib.batch_id = ils.batch_id AND ils.is_active = 1
    WHERE i.is_active = 1 AND i.status = 'active'"
);
$stats = mysqli_fetch_assoc($stats_sql);

// Get category-wise statistics - UPDATED
$category_stats_sql = mysqli_query($mysqli,
    "SELECT 
        c.category_id,
        c.category_name,
        c.category_type,
        COUNT(DISTINCT i.item_id) as item_count,
        SUM(ils.quantity) as total_quantity,
        SUM(ils.quantity * ils.unit_cost) as total_value,
        SUM(CASE WHEN ils.quantity <= 0 THEN 1 ELSE 0 END) as out_of_stock,
        SUM(CASE WHEN ils.quantity > 0 AND ils.quantity <= i.reorder_level THEN 1 ELSE 0 END) as low_stock
    FROM inventory_categories c
    LEFT JOIN inventory_items i ON c.category_id = i.category_id AND i.is_active = 1 AND i.status = 'active'
    LEFT JOIN inventory_batches ib ON i.item_id = ib.item_id AND ib.is_active = 1
    LEFT JOIN inventory_location_stock ils ON ib.batch_id = ils.batch_id AND ils.is_active = 1
    WHERE c.is_active = 1
    GROUP BY c.category_id, c.category_name, c.category_type
    ORDER BY c.category_type, c.category_name"
);

// Get monthly usage trends - UPDATED
$monthly_trends_sql = mysqli_query($mysqli,
    "SELECT 
        DATE_FORMAT(it.created_at, '%Y-%m') as month,
        COUNT(DISTINCT it.item_id) as items_used,
        SUM(CASE WHEN it.transaction_type = 'ISSUE' OR it.transaction_type = 'WASTAGE' THEN it.quantity ELSE 0 END) as total_usage,
        SUM(CASE WHEN it.transaction_type = 'GRN' THEN it.quantity ELSE 0 END) as total_restocked
    FROM inventory_transactions it
    WHERE it.created_at BETWEEN DATE_SUB(CURDATE(), INTERVAL 6 MONTH) AND CURDATE()
        AND it.is_active = 1
    GROUP BY DATE_FORMAT(it.created_at, '%Y-%m')
    ORDER BY month DESC
    LIMIT 6"
);

// Get top used items - UPDATED
$top_used_sql = mysqli_query($mysqli,
    "SELECT 
        i.item_id,
        i.item_name,
        i.item_code,
        c.category_name,
        SUM(CASE WHEN it.transaction_type = 'ISSUE' OR it.transaction_type = 'WASTAGE' THEN it.quantity ELSE 0 END) as total_used,
        COUNT(DISTINCT DATE(it.created_at)) as usage_days
    FROM inventory_items i
    LEFT JOIN inventory_transactions it ON i.item_id = it.item_id 
        AND (it.transaction_type = 'ISSUE' OR it.transaction_type = 'WASTAGE')
        AND it.created_at BETWEEN '$start_date 00:00:00' AND '$end_date 23:59:59'
        AND it.is_active = 1
    LEFT JOIN inventory_categories c ON i.category_id = c.category_id
    WHERE i.is_active = 1 AND i.status = 'active'
    GROUP BY i.item_id, i.item_name, i.item_code, c.category_name
    HAVING total_used > 0
    ORDER BY total_used DESC
    LIMIT 10"
);

// Get low stock items - UPDATED
$low_stock_sql = mysqli_query($mysqli,
    "SELECT 
        i.item_id,
        i.item_name,
        i.item_code,
        c.category_name,
        i.reorder_level,
        SUM(ils.quantity) AS total_quantity,
        AVG(ils.unit_cost) AS avg_unit_cost,
        (SUM(ils.quantity) * AVG(ils.unit_cost)) AS stock_value
    FROM inventory_items i
    LEFT JOIN inventory_batches ib 
        ON i.item_id = ib.item_id AND ib.is_active = 1
    LEFT JOIN inventory_location_stock ils 
        ON ib.batch_id = ils.batch_id AND ils.is_active = 1
    LEFT JOIN inventory_categories c 
        ON i.category_id = c.category_id
    WHERE i.is_active = 1 
      AND i.status = 'active'
    GROUP BY 
        i.item_id, 
        i.item_name, 
        i.item_code, 
        c.category_name, 
        i.reorder_level
    HAVING 
        SUM(ils.quantity) > 0
        AND SUM(ils.quantity) <= i.reorder_level
    ORDER BY 
        (SUM(ils.quantity) / i.reorder_level) ASC
    LIMIT 15"
);


// Get expired/expiring items - UPDATED
$expiry_sql = mysqli_query($mysqli,
    "SELECT 
        i.item_id,
        i.item_name,
        i.item_code,
        c.category_name,
        ib.batch_number,
        ib.expiry_date,
        SUM(ils.quantity) as batch_quantity,
        DATEDIFF(ib.expiry_date, CURDATE()) as days_until_expiry
    FROM inventory_batches ib
    LEFT JOIN inventory_items i ON ib.item_id = i.item_id AND i.is_active = 1 AND i.status = 'active'
    LEFT JOIN inventory_location_stock ils ON ib.batch_id = ils.batch_id AND ils.is_active = 1
    LEFT JOIN inventory_categories c ON i.category_id = c.category_id
    WHERE ib.expiry_date IS NOT NULL 
        AND ib.is_active = 1
    GROUP BY i.item_id, i.item_name, i.item_code, c.category_name, ib.batch_id, ib.batch_number, ib.expiry_date
    HAVING (days_until_expiry <= 30 OR days_until_expiry < 0) 
        AND batch_quantity > 0
    ORDER BY ib.expiry_date ASC
    LIMIT 15"
);

// Get supplier performance - UPDATED
$supplier_stats_sql = mysqli_query($mysqli,
    "SELECT 
        s.supplier_id,
        s.supplier_name,
        COUNT(DISTINCT i.item_id) as items_supplied,
        SUM(ils.quantity * ils.unit_cost) as total_stock_value,
        AVG(ils.unit_cost) as avg_item_cost
    FROM suppliers s
    LEFT JOIN inventory_batches ib ON s.supplier_id = ib.supplier_id AND ib.is_active = 1
    LEFT JOIN inventory_items i ON ib.item_id = i.item_id AND i.is_active = 1 AND i.status = 'active'
    LEFT JOIN inventory_location_stock ils ON ib.batch_id = ils.batch_id AND ils.is_active = 1
    WHERE s.supplier_is_active = 1
    GROUP BY s.supplier_id, s.supplier_name
    HAVING items_supplied > 0
    ORDER BY total_stock_value DESC
    LIMIT 10"
);

// Get location stock summary - NEW
$location_stats_sql = mysqli_query($mysqli,
    "SELECT 
        il.location_id,
        il.location_name,
        il.location_type,
        COUNT(DISTINCT i.item_id) as item_count,
        SUM(ils.quantity) as total_quantity,
        SUM(ils.quantity * ils.unit_cost) as total_value
    FROM inventory_locations il
    LEFT JOIN inventory_location_stock ils ON il.location_id = ils.location_id AND ils.is_active = 1
    LEFT JOIN inventory_batches ib ON ils.batch_id = ib.batch_id AND ib.is_active = 1
    LEFT JOIN inventory_items i ON ib.item_id = i.item_id AND i.is_active = 1 AND i.status = 'active'
    WHERE il.is_active = 1
    GROUP BY il.location_id, il.location_name, il.location_type
    ORDER BY il.location_type, il.location_name"
);

// Get categories for filter
$categories_sql = mysqli_query($mysqli, "
    SELECT category_id, category_name, category_type 
    FROM inventory_categories 
    WHERE is_active = 1 
    ORDER BY category_type, category_name"
);

// Get suppliers for filter
$suppliers_sql = mysqli_query($mysqli, "
    SELECT supplier_id, supplier_name 
    FROM suppliers 
    WHERE supplier_is_active = 1 
    ORDER BY supplier_name"
);

// Get locations for filter - NEW
$locations_sql = mysqli_query($mysqli, "
    SELECT location_id, location_name, location_type 
    FROM inventory_locations 
    WHERE is_active = 1 
    ORDER BY location_type, location_name"
);
?>

<div class="card">
    <div class="card-header bg-info py-2">
        <h3 class="card-title mt-2 mb-0"><i class="fas fa-fw fa-chart-bar mr-2"></i>Inventory Reports & Analytics</h3>
        <div class="card-tools">
            <div class="btn-group">
                <button type="button" class="btn btn-success dropdown-toggle" data-toggle="dropdown">
                    <i class="fas fa-download mr-2"></i>Export Reports
                </button>
                <div class="dropdown-menu">
                    <a class="dropdown-item" href="?<?php echo http_build_query($_GET); ?>&export=pdf">
                        <i class="fas fa-file-pdf mr-2"></i>PDF Report
                    </a>
                    <a class="dropdown-item" href="?<?php echo http_build_query($_GET); ?>&export=excel">
                        <i class="fas fa-file-excel mr-2"></i>Excel Export
                    </a>
                    <a class="dropdown-item" href="?<?php echo http_build_query($_GET); ?>&export=csv">
                        <i class="fas fa-file-csv mr-2"></i>CSV Export
                    </a>
                </div>
                <a href="inventory.php" class="btn btn-secondary ml-2">
                    <i class="fas fa-warehouse mr-2"></i>Back to Inventory
                </a>
            </div>
        </div>
    </div>

    <!-- Report Filters - Updated with location filter -->
    <div class="card-header pb-2 pt-3">
        <form autocomplete="off">
            <div class="row">
                <div class="col-md-5">
                    <div class="form-group">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php if (isset($_GET['q'])) { echo stripslashes(nullable_htmlentities($_GET['q'])); } ?>" placeholder="Search reports..." autofocus>
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
                            <a href="?<?php echo http_build_query($_GET); ?>&export=pdf" class="btn btn-default">
                                <i class="fa fa-fw fa-file-pdf mr-2"></i>Export Report
                            </a>
                            <a href="inventory.php" class="btn btn-warning ml-2">
                                <i class="fas fa-warehouse mr-2"></i>Back to Inventory
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse 
                    <?php 
                    if (isset($_GET['start_date']) || isset($_GET['end_date']) || $category_filter || $supplier_filter || $location_filter || $report_type != 'stock_summary') { 
                        echo "show"; 
                    } 
                    ?>"
                id="advancedFilter">
                <div class="row">
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Report Type</label>
                            <select class="form-control select2" name="report_type" onchange="this.form.submit()">
                                <option value="stock_summary" <?php echo $report_type == 'stock_summary' ? 'selected' : ''; ?>>Stock Summary</option>
                                <option value="usage_analytics" <?php echo $report_type == 'usage_analytics' ? 'selected' : ''; ?>>Usage Analytics</option>
                                <option value="stock_alerts" <?php echo $report_type == 'stock_alerts' ? 'selected' : ''; ?>>Stock Alerts</option>
                                <option value="expiry_management" <?php echo $report_type == 'expiry_management' ? 'selected' : ''; ?>>Expiry Management</option>
                                <option value="supplier_analysis" <?php echo $report_type == 'supplier_analysis' ? 'selected' : ''; ?>>Supplier Analysis</option>
                                <option value="location_summary" <?php echo $report_type == 'location_summary' ? 'selected' : ''; ?>>Location Summary</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Start Date</label>
                            <input type="date" class="form-control" name="start_date" value="<?php echo $start_date; ?>" onchange="this.form.submit()">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>End Date</label>
                            <input type="date" class="form-control" name="end_date" value="<?php echo $end_date; ?>" onchange="this.form.submit()">
                        </div>
                    </div>
                    <div class="col-md-2">
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
                            <label>Location</label>
                            <select class="form-control select2" name="location" onchange="this.form.submit()">
                                <option value="">- All Locations -</option>
                                <?php
                                while($location = mysqli_fetch_assoc($locations_sql)) {
                                    $location_id = intval($location['location_id']);
                                    $location_name = nullable_htmlentities($location['location_name']);
                                    $location_type = nullable_htmlentities($location['location_type']);
                                    $display_name = "$location_type - $location_name";
                                    $selected = $location_filter == $location_id ? 'selected' : '';
                                    echo "<option value='$location_id' $selected>$display_name</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="row mt-2">
                    <div class="col-md-12">
                        <div class="form-group">
                            <label>Quick Report Links</label>
                            <div class="btn-group btn-group-toggle" data-toggle="buttons">
                                <a href="?report_type=stock_summary" class="btn btn-outline-primary btn-sm <?php echo $report_type == 'stock_summary' ? 'active' : ''; ?>">
                                    <i class="fas fa-chart-pie mr-1"></i> Stock Summary
                                </a>
                                <a href="?report_type=usage_analytics&start_date=<?php echo date('Y-m-01'); ?>&end_date=<?php echo date('Y-m-t'); ?>" class="btn btn-outline-info btn-sm <?php echo $report_type == 'usage_analytics' ? 'active' : ''; ?>">
                                    <i class="fas fa-chart-line mr-1"></i> Usage Analytics
                                </a>
                                <a href="?report_type=stock_alerts" class="btn btn-outline-warning btn-sm <?php echo $report_type == 'stock_alerts' ? 'active' : ''; ?>">
                                    <i class="fas fa-exclamation-triangle mr-1"></i> Stock Alerts
                                </a>
                                <a href="?report_type=expiry_management" class="btn btn-outline-danger btn-sm <?php echo $report_type == 'expiry_management' ? 'active' : ''; ?>">
                                    <i class="fas fa-calendar-times mr-1"></i> Expiry Management
                                </a>
                                <a href="?report_type=supplier_analysis" class="btn btn-outline-success btn-sm <?php echo $report_type == 'supplier_analysis' ? 'active' : ''; ?>">
                                    <i class="fas fa-truck mr-1"></i> Supplier Analysis
                                </a>
                                <a href="?report_type=location_summary" class="btn btn-outline-secondary btn-sm <?php echo $report_type == 'location_summary' ? 'active' : ''; ?>">
                                    <i class="fas fa-map-marker-alt mr-1"></i> Location Summary
                                </a>
                                <a href="inventory_reports.php" class="btn btn-outline-dark btn-sm">
                                    <i class="fas fa-times mr-1"></i> Clear Filters
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- Overall Statistics -->
    <div class="card-body">
        <div class="row mb-4">
            <div class="col-12">
                <h5 class="text-info mb-3"><i class="fas fa-chart-line mr-2"></i>Inventory Overview</h5>
            </div>
            <div class="col-md-2 col-6 mb-3">
                <div class="info-box bg-light h-100">
                    <span class="info-box-icon bg-primary"><i class="fas fa-boxes"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Items</span>
                        <span class="info-box-number"><?php echo number_format($stats['total_items']); ?></span>
                        <small class="text-muted">Active inventory</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2 col-6 mb-3">
                <div class="info-box bg-light h-100">
                    <span class="info-box-icon bg-success"><i class="fas fa-cubes"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Quantity</span>
                        <span class="info-box-number"><?php echo number_format($stats['total_quantity'] ?? 0); ?></span>
                        <small class="text-muted">Units in stock</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2 col-6 mb-3">
                <div class="info-box bg-light h-100">
                    <span class="info-box-icon bg-warning"><i class="fas fa-exclamation-triangle"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Low Stock</span>
                        <span class="info-box-number"><?php echo number_format($stats['low_stock_count']); ?></span>
                        <small class="text-muted">Need reordering</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2 col-6 mb-3">
                <div class="info-box bg-light h-100">
                    <span class="info-box-icon bg-danger"><i class="fas fa-times-circle"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Out of Stock</span>
                        <span class="info-box-number"><?php echo number_format($stats['out_of_stock_count']); ?></span>
                        <small class="text-muted">Zero quantity</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2 col-6 mb-3">
                <div class="info-box bg-light h-100">
                    <span class="info-box-icon bg-dark"><i class="fas fa-calendar-times"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Expired</span>
                        <span class="info-box-number"><?php echo number_format($stats['expired_count']); ?></span>
                        <small class="text-muted">Batches expired</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2 col-6 mb-3">
                <div class="info-box bg-light h-100">
                    <span class="info-box-icon bg-info"><i class="fas fa-money-bill-wave"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Value</span>
                        <span class="info-box-number">$<?php echo number_format($stats['total_value'] ?? 0, 2); ?></span>
                        <small class="text-muted">Inventory worth</small>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($report_type == 'stock_summary'): ?>
        
        <!-- Stock Summary Report -->
        <div class="row">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0"><i class="fas fa-chart-pie mr-2"></i>Inventory by Category</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Category</th>
                                        <th class="text-center">Items</th>
                                        <th class="text-center">Total Quantity</th>
                                        <th class="text-center">Stock Value</th>
                                        <th class="text-center">Low Stock</th>
                                        <th class="text-center">Out of Stock</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    mysqli_data_seek($category_stats_sql, 0);
                                    $total_categories = 0;
                                    while ($category = mysqli_fetch_assoc($category_stats_sql)) {
                                        $total_categories++;
                                        $low_stock_percent = $category['item_count'] > 0 ? round(($category['low_stock'] / $category['item_count']) * 100, 1) : 0;
                                        $out_of_stock_percent = $category['item_count'] > 0 ? round(($category['out_of_stock'] / $category['item_count']) * 100, 1) : 0;
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="font-weight-bold"><?php echo nullable_htmlentities($category['category_name']); ?></div>
                                                <small class="text-muted"><?php echo nullable_htmlentities($category['category_type']); ?></small>
                                            </td>
                                            <td class="text-center">
                                                <span class="font-weight-bold text-primary"><?php echo number_format($category['item_count']); ?></span>
                                            </td>
                                            <td class="text-center">
                                                <span class="font-weight-bold text-success"><?php echo number_format($category['total_quantity'] ?? 0); ?></span>
                                            </td>
                                            <td class="text-center">
                                                <span class="font-weight-bold text-info">$<?php echo number_format($category['total_value'] ?? 0, 2); ?></span>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge badge-<?php echo $category['low_stock'] > 0 ? 'warning' : 'secondary'; ?>">
                                                    <?php echo number_format($category['low_stock']); ?>
                                                </span>
                                                <?php if ($category['low_stock'] > 0): ?>
                                                    <br><small class="text-muted"><?php echo $low_stock_percent; ?>%</small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge badge-<?php echo $category['out_of_stock'] > 0 ? 'danger' : 'secondary'; ?>">
                                                    <?php echo number_format($category['out_of_stock']); ?>
                                                </span>
                                                <?php if ($category['out_of_stock'] > 0): ?>
                                                    <br><small class="text-muted"><?php echo $out_of_stock_percent; ?>%</small>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php
                                    }
                                    
                                    if ($total_categories == 0) {
                                        echo '<tr><td colspan="6" class="text-center text-muted py-3">No categories found</td></tr>';
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <!-- Value Distribution -->
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0"><i class="fas fa-money-bill-wave mr-2"></i>Value Distribution</h5>
                    </div>
                    <div class="card-body">
                        <div class="text-center mb-3">
                            <div class="display-4 text-success">$<?php echo number_format($stats['total_value'] ?? 0, 2); ?></div>
                            <small class="text-muted">Total Inventory Value</small>
                        </div>
                        
                        <div class="list-group list-group-flush">
                            <?php
                            mysqli_data_seek($category_stats_sql, 0);
                            while ($category = mysqli_fetch_assoc($category_stats_sql)) {
                                if (($category['total_value'] ?? 0) > 0) {
                                    $percentage = ($stats['total_value'] ?? 0) > 0 ? round(($category['total_value'] / $stats['total_value']) * 100, 1) : 0;
                                    ?>
                                    <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                                        <div>
                                            <span class="font-weight-bold"><?php echo nullable_htmlentities($category['category_name']); ?></span>
                                            <br><small class="text-muted"><?php echo number_format($category['item_count']); ?> items</small>
                                        </div>
                                        <div class="text-right">
                                            <span class="font-weight-bold text-success">$<?php echo number_format($category['total_value'], 2); ?></span>
                                            <br><small class="text-muted"><?php echo $percentage; ?>%</small>
                                        </div>
                                    </div>
                                    <?php
                                }
                            }
                            ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <?php elseif ($report_type == 'usage_analytics'): ?>
        
        <!-- Usage Analytics Report -->
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0"><i class="fas fa-chart-line mr-2"></i>Monthly Usage Trends</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Month</th>
                                        <th class="text-center">Items Used</th>
                                        <th class="text-center">Total Usage</th>
                                        <th class="text-center">Restocked</th>
                                        <th class="text-center">Net Change</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    if (mysqli_num_rows($monthly_trends_sql) > 0) {
                                        while ($trend = mysqli_fetch_assoc($monthly_trends_sql)) {
                                            $net_change = $trend['total_restocked'] - $trend['total_usage'];
                                            $net_class = $net_change > 0 ? 'text-success' : ($net_change < 0 ? 'text-danger' : 'text-muted');
                                            ?>
                                            <tr>
                                                <td class="font-weight-bold"><?php echo date('M Y', strtotime($trend['month'] . '-01')); ?></td>
                                                <td class="text-center"><?php echo number_format($trend['items_used']); ?></td>
                                                <td class="text-center text-danger"><?php echo number_format($trend['total_usage']); ?></td>
                                                <td class="text-center text-success"><?php echo number_format($trend['total_restocked']); ?></td>
                                                <td class="text-center font-weight-bold <?php echo $net_class; ?>">
                                                    <?php echo $net_change > 0 ? '+' : ''; ?><?php echo number_format($net_change); ?>
                                                </td>
                                            </tr>
                                            <?php
                                        }
                                    } else {
                                        echo '<tr><td colspan="5" class="text-center text-muted py-3">No usage data available</td></tr>';
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0"><i class="fas fa-fire mr-2"></i>Top Used Items (<?php echo date('M j', strtotime($start_date)); ?> - <?php echo date('M j', strtotime($end_date)); ?>)</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Item</th>
                                        <th class="text-center">Category</th>
                                        <th class="text-center">Total Used</th>
                                        <th class="text-center">Usage Days</th>
                                        <th class="text-center">Avg/Day</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    if (mysqli_num_rows($top_used_sql) > 0) {
                                        $rank = 1;
                                        while ($item = mysqli_fetch_assoc($top_used_sql)) {
                                            $avg_per_day = $item['usage_days'] > 0 ? round($item['total_used'] / $item['usage_days'], 1) : 0;
                                            ?>
                                            <tr>
                                                <td>
                                                    <div class="font-weight-bold">
                                                        <span class="badge badge-primary mr-2">#<?php echo $rank; ?></span>
                                                        <?php echo nullable_htmlentities($item['item_name']); ?>
                                                    </div>
                                                    <small class="text-muted"><?php echo nullable_htmlentities($item['item_code']); ?></small>
                                                </td>
                                                <td class="text-center">
                                                    <small class="text-muted"><?php echo nullable_htmlentities($item['category_name']); ?></small>
                                                </td>
                                                <td class="text-center">
                                                    <span class="font-weight-bold text-danger"><?php echo number_format($item['total_used']); ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge badge-info"><?php echo number_format($item['usage_days']); ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="font-weight-bold text-warning"><?php echo number_format($avg_per_day, 1); ?></span>
                                                </td>
                                            </tr>
                                            <?php
                                            $rank++;
                                        }
                                    } else {
                                        echo '<tr><td colspan="5" class="text-center text-muted py-3">No usage data for selected period</td></tr>';
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <?php elseif ($report_type == 'stock_alerts'): ?>
        
        <!-- Stock Alerts Report -->
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-light d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0"><i class="fas fa-exclamation-triangle mr-2 text-warning"></i>Low Stock Items</h5>
                        <span class="badge badge-warning"><?php echo mysqli_num_rows($low_stock_sql); ?> items need attention</span>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Item</th>
                                        <th class="text-center">Category</th>
                                        <th class="text-center">Current Stock</th>
                                        <th class="text-center">Reorder Level</th>
                                        <th class="text-center">Stock Status</th>
                                        <th class="text-center">Unit Cost</th>
                                        <th class="text-center">Stock Value</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    if (mysqli_num_rows($low_stock_sql) > 0) {
                                        while ($item = mysqli_fetch_assoc($low_stock_sql)) {
                                            $stock_percentage = $item['reorder_level'] > 0 ? round(($item['total_quantity'] / $item['reorder_level']) * 100, 1) : 0;
                                            $status_class = $stock_percentage <= 25 ? 'danger' : ($stock_percentage <= 50 ? 'warning' : 'info');
                                            ?>
                                            <tr>
                                                <td>
                                                    <div class="font-weight-bold"><?php echo nullable_htmlentities($item['item_name']); ?></div>
                                                    <small class="text-muted"><?php echo nullable_htmlentities($item['item_code']); ?></small>
                                                </td>
                                                <td class="text-center">
                                                    <small class="text-muted"><?php echo nullable_htmlentities($item['category_name']); ?></small>
                                                </td>
                                                <td class="text-center">
                                                    <span class="font-weight-bold text-<?php echo $status_class; ?>"><?php echo number_format($item['total_quantity']); ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="text-muted"><?php echo number_format($item['reorder_level']); ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <div class="progress" style="height: 20px;">
                                                        <div class="progress-bar bg-<?php echo $status_class; ?>" 
                                                             role="progressbar" 
                                                             style="width: <?php echo min($stock_percentage, 100); ?>%"
                                                             aria-valuenow="<?php echo $stock_percentage; ?>" 
                                                             aria-valuemin="0" 
                                                             aria-valuemax="100">
                                                            <?php echo $stock_percentage; ?>%
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="text-center">
                                                    <span class="text-success">$<?php echo number_format($item['avg_unit_cost'] ?? 0, 2); ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="font-weight-bold text-info">$<?php echo number_format($item['stock_value'] ?? 0, 2); ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <a href="purchase_order_create.php?item_id=<?php echo $item['item_id']; ?>" class="btn btn-warning btn-sm">
                                                        <i class="fas fa-shopping-cart mr-1"></i>Order
                                                    </a>
                                                </td>
                                            </tr>
                                            <?php
                                        }
                                    } else {
                                        echo '<tr><td colspan="8" class="text-center text-success py-4">
                                            <i class="fas fa-check-circle fa-2x mb-2"></i><br>
                                            No low stock items found!
                                        </td></tr>';
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <?php elseif ($report_type == 'expiry_management'): ?>
        
        <!-- Expiry Management Report -->
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-light d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0"><i class="fas fa-calendar-times mr-2 text-danger"></i>Expiry Management</h5>
                        <div>
                            <span class="badge badge-danger mr-2"><?php echo $stats['expired_count']; ?> Expired</span>
                            <span class="badge badge-warning"><?php echo $stats['expiring_soon_count']; ?> Expiring Soon</span>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Item</th>
                                        <th class="text-center">Batch</th>
                                        <th class="text-center">Category</th>
                                        <th class="text-center">Quantity</th>
                                        <th class="text-center">Expiry Date</th>
                                        <th class="text-center">Days Until Expiry</th>
                                        <th class="text-center">Status</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    if (mysqli_num_rows($expiry_sql) > 0) {
                                        while ($item = mysqli_fetch_assoc($expiry_sql)) {
                                            $days_until_expiry = $item['days_until_expiry'];
                                            if ($days_until_expiry < 0) {
                                                $status = 'Expired';
                                                $status_class = 'danger';
                                                $badge_class = 'danger';
                                            } elseif ($days_until_expiry <= 7) {
                                                $status = 'Critical';
                                                $status_class = 'danger';
                                                $badge_class = 'danger';
                                            } elseif ($days_until_expiry <= 30) {
                                                $status = 'Warning';
                                                $status_class = 'warning';
                                                $badge_class = 'warning';
                                            } else {
                                                $status = 'Good';
                                                $status_class = 'success';
                                                $badge_class = 'success';
                                            }
                                            ?>
                                            <tr class="<?php echo $days_until_expiry < 0 ? 'table-danger' : ($days_until_expiry <= 7 ? 'table-warning' : ''); ?>">
                                                <td>
                                                    <div class="font-weight-bold"><?php echo nullable_htmlentities($item['item_name']); ?></div>
                                                    <small class="text-muted"><?php echo nullable_htmlentities($item['item_code']); ?></small>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge badge-light"><?php echo nullable_htmlentities($item['batch_number']); ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <small class="text-muted"><?php echo nullable_htmlentities($item['category_name']); ?></small>
                                                </td>
                                                <td class="text-center">
                                                    <span class="font-weight-bold"><?php echo number_format($item['batch_quantity']); ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="font-weight-bold"><?php echo date('M j, Y', strtotime($item['expiry_date'])); ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge badge-<?php echo $badge_class; ?>">
                                                        <?php echo $days_until_expiry < 0 ? abs($days_until_expiry) . ' days ago' : $days_until_expiry . ' days'; ?>
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="font-weight-bold text-<?php echo $status_class; ?>"><?php echo $status; ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($days_until_expiry < 0): ?>
                                                        <a href="inventory_adjust_stock.php?batch_id=<?php echo $item['batch_id']; ?>" class="btn btn-danger btn-sm">
                                                            <i class="fas fa-trash mr-1"></i>Dispose
                                                        </a>
                                                    <?php else: ?>
                                                        <a href="inventory_batch_details.php?batch_id=<?php echo $item['batch_id']; ?>" class="btn btn-info btn-sm">
                                                            <i class="fas fa-eye mr-1"></i>View
                                                        </a>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php
                                        }
                                    } else {
                                        echo '<tr><td colspan="8" class="text-center text-success py-4">
                                            <i class="fas fa-check-circle fa-2x mb-2"></i><br>
                                            No expired or expiring batches found!
                                        </td></tr>';
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <?php elseif ($report_type == 'supplier_analysis'): ?>
        
        <!-- Supplier Analysis Report -->
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0"><i class="fas fa-truck mr-2"></i>Supplier Performance Analysis</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Supplier</th>
                                        <th class="text-center">Items Supplied</th>
                                        <th class="text-center">Total Stock Value</th>
                                        <th class="text-center">Average Item Cost</th>
                                        <th class="text-center">Value Percentage</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    if (mysqli_num_rows($supplier_stats_sql) > 0) {
                                        while ($supplier = mysqli_fetch_assoc($supplier_stats_sql)) {
                                            $value_percentage = ($stats['total_value'] ?? 0) > 0 ? round(($supplier['total_stock_value'] / $stats['total_value']) * 100, 1) : 0;
                                            ?>
                                            <tr>
                                                <td>
                                                    <div class="font-weight-bold"><?php echo nullable_htmlentities($supplier['supplier_name']); ?></div>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge badge-primary badge-pill"><?php echo number_format($supplier['items_supplied']); ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="font-weight-bold text-success">$<?php echo number_format($supplier['total_stock_value'], 2); ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="text-info">$<?php echo number_format($supplier['avg_item_cost'], 2); ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <div class="progress" style="height: 20px;">
                                                        <div class="progress-bar bg-success" 
                                                             role="progressbar" 
                                                             style="width: <?php echo $value_percentage; ?>%"
                                                             aria-valuenow="<?php echo $value_percentage; ?>" 
                                                             aria-valuemin="0" 
                                                             aria-valuemax="100">
                                                            <?php echo $value_percentage; ?>%
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="text-center">
                                                    <a href="supplier_edit.php?supplier_id=<?php echo $supplier['supplier_id']; ?>" class="btn btn-info btn-sm">
                                                        <i class="fas fa-eye mr-1"></i>View
                                                    </a>
                                                    <a href="inventory.php?supplier=<?php echo $supplier['supplier_id']; ?>" class="btn btn-primary btn-sm">
                                                        <i class="fas fa-boxes mr-1"></i>Items
                                                    </a>
                                                </td>
                                            </tr>
                                            <?php
                                        }
                                    } else {
                                        echo '<tr><td colspan="6" class="text-center text-muted py-3">No supplier data available</td></tr>';
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <?php elseif ($report_type == 'location_summary'): ?>
        
        <!-- Location Summary Report - NEW -->
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0"><i class="fas fa-map-marker-alt mr-2"></i>Location Stock Summary</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Location</th>
                                        <th class="text-center">Type</th>
                                        <th class="text-center">Items</th>
                                        <th class="text-center">Total Quantity</th>
                                        <th class="text-center">Stock Value</th>
                                        <th class="text-center">Avg Value/Item</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    if (mysqli_num_rows($location_stats_sql) > 0) {
                                        while ($location = mysqli_fetch_assoc($location_stats_sql)) {
                                            $avg_value = $location['item_count'] > 0 ? round(($location['total_value'] ?? 0) / $location['item_count'], 2) : 0;
                                            ?>
                                            <tr>
                                                <td>
                                                    <div class="font-weight-bold"><?php echo nullable_htmlentities($location['location_name']); ?></div>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge badge-info"><?php echo nullable_htmlentities($location['location_type']); ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="font-weight-bold text-primary"><?php echo number_format($location['item_count']); ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="font-weight-bold text-success"><?php echo number_format($location['total_quantity'] ?? 0); ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="font-weight-bold text-info">$<?php echo number_format($location['total_value'] ?? 0, 2); ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="text-muted">$<?php echo number_format($avg_value, 2); ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <a href="inventory_location_view.php?location_id=<?php echo $location['location_id']; ?>" class="btn btn-info btn-sm">
                                                        <i class="fas fa-eye mr-1"></i>View
                                                    </a>
                                                    <a href="inventory_transfer_create.php?from_location=<?php echo $location['location_id']; ?>" class="btn btn-primary btn-sm">
                                                        <i class="fas fa-exchange-alt mr-1"></i>Transfer
                                                    </a>
                                                </td>
                                            </tr>
                                            <?php
                                        }
                                    } else {
                                        echo '<tr><td colspan="7" class="text-center text-muted py-3">No location data available</td></tr>';
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <?php endif; ?>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.select2').select2();
    
    // Auto-print if print parameter is set
    <?php if (isset($_GET['print'])): ?>
        window.print();
    <?php endif; ?>
    
    // Quick filter buttons
    $('.btn-group-toggle .btn').click(function(e) {
        e.preventDefault();
        window.location.href = $(this).attr('href');
    });
});
</script>

<style>
@media print {
    .card-header, .btn, .form-group, .dropdown, .card-tools {
        display: none !important;
    }
    .card {
        border: none !important;
        box-shadow: none !important;
    }
    .info-box {
        break-inside: avoid;
    }
}
.progress {
    min-width: 80px;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
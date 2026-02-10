<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Get location ID from URL
$location_id = intval($_GET['location_id'] ?? 0);

if (!$location_id) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid location ID.";
    header("Location: inventory_locations.php");
    exit;
}

// Get location details
$location_sql = "SELECT * FROM inventory_locations WHERE location_id = ? AND is_active = 1";
$location_stmt = $mysqli->prepare($location_sql);
$location_stmt->bind_param("i", $location_id);
$location_stmt->execute();
$location_result = $location_stmt->get_result();

if ($location_result->num_rows === 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Location not found or inactive.";
    header("Location: inventory_locations.php");
    exit;
}

$location = $location_result->fetch_assoc();
$location_stmt->close();

// Default Column Sortby/Order Filter
$sort = sanitizeInput($_GET['sort'] ?? "i.item_name");
$order = sanitizeInput($_GET['order'] ?? "ASC");

// Validate sort column
$allowed_sorts = ['i.item_name', 'i.item_code', 'ils.quantity', 'ils.unit_cost', 'total_value', 'ic.category_name'];
if (!in_array($sort, $allowed_sorts)) {
    $sort = 'i.item_name';
}

// Validate order
$order = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';

// Search Query
$q = sanitizeInput($_GET['q'] ?? '');
if (!empty($q)) {
    $search_query = "AND (
        i.item_name LIKE '%$q%' 
        OR i.item_code LIKE '%$q%'
        OR ib.batch_number LIKE '%$q%'
        OR ic.category_name LIKE '%$q%'
    )";
} else {
    $search_query = '';
}

// Category Filter
$category_filter = sanitizeInput($_GET['category'] ?? '');
if ($category_filter) {
    $category_query = "AND i.category_id = '$category_filter'";
} else {
    $category_query = '';
}

// Status Filter
$status_filter = sanitizeInput($_GET['status'] ?? '');
if ($status_filter) {
    $status_query = "AND i.status = '$status_filter'";
} else {
    $status_query = '';
}

// Get items in this location with batch details
$items_sql = "
    SELECT SQL_CALC_FOUND_ROWS 
           i.*,
           ic.category_name,
           ic.category_type,
           ib.batch_id,
           ib.batch_number,
           ib.expiry_date,
           ib.manufacturer,
           ib.supplier_id,
           s.supplier_name,
           ils.quantity,
           ils.unit_cost,
           ils.selling_price,
           ils.last_movement_at,
           (ils.quantity * ils.unit_cost) as total_value,
           (SELECT COALESCE(SUM(quantity), 0) 
            FROM inventory_location_stock ils2 
            WHERE ils2.batch_id = ib.batch_id) as total_quantity_all_locations,
           DATEDIFF(ib.expiry_date, CURDATE()) as days_until_expiry
    FROM inventory_location_stock ils
    LEFT JOIN inventory_batches ib ON ils.batch_id = ib.batch_id
    LEFT JOIN inventory_items i ON ib.item_id = i.item_id
    LEFT JOIN inventory_categories ic ON i.category_id = ic.category_id
    LEFT JOIN suppliers s ON ib.supplier_id = s.supplier_id
    WHERE ils.location_id = ? 
      AND ils.quantity > 0
      AND ils.is_active = 1
      AND ib.is_active = 1
      AND i.is_active = 1
      $search_query
      $category_query
      $status_query
    ORDER BY $sort $order
    LIMIT $record_from, $record_to";

$items_stmt = $mysqli->prepare($items_sql);
$items_stmt->bind_param("i", $location_id);
$items_stmt->execute();
$items_result = $items_stmt->get_result();

if (!$items_result) {
    die("Query failed: " . $mysqli->error);
}

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get item statistics for this location
$stats_sql = "
    SELECT 
        COUNT(DISTINCT i.item_id) as total_items,
        COUNT(DISTINCT ib.batch_id) as total_batches,
        COALESCE(SUM(ils.quantity), 0) as total_quantity,
        COALESCE(SUM(ils.quantity * ils.unit_cost), 0) as total_value,
        COUNT(CASE WHEN ib.expiry_date < CURDATE() THEN 1 END) as expired_batches,
        COUNT(CASE WHEN ib.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 END) as expiring_soon_batches
    FROM inventory_location_stock ils
    LEFT JOIN inventory_batches ib ON ils.batch_id = ib.batch_id
    LEFT JOIN inventory_items i ON ib.item_id = i.item_id
    WHERE ils.location_id = ? 
      AND ils.quantity > 0
      AND ils.is_active = 1
      AND ib.is_active = 1";

$stats_stmt = $mysqli->prepare($stats_sql);
$stats_stmt->bind_param("i", $location_id);
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();
$stats = $stats_result->fetch_assoc();
$stats_stmt->close();

// Get categories for filter
$categories_sql = $mysqli->query("
    SELECT DISTINCT ic.category_id, ic.category_name 
    FROM inventory_location_stock ils
    LEFT JOIN inventory_batches ib ON ils.batch_id = ib.batch_id
    LEFT JOIN inventory_items i ON ib.item_id = i.item_id
    LEFT JOIN inventory_categories ic ON i.category_id = ic.category_id
    WHERE ils.location_id = $location_id
    AND ils.quantity > 0
    ORDER BY ic.category_name");

// Get suppliers for this location
$suppliers_sql = $mysqli->query("
    SELECT DISTINCT s.supplier_id, s.supplier_name 
    FROM inventory_location_stock ils
    LEFT JOIN inventory_batches ib ON ils.batch_id = ib.batch_id
    LEFT JOIN suppliers s ON ib.supplier_id = s.supplier_id
    WHERE ils.location_id = $location_id
    AND ils.quantity > 0
    ORDER BY s.supplier_name");
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h3 class="card-title mt-2 mb-0 text-white">
                    <i class="fas fa-fw fa-boxes mr-2"></i>
                    Items in Location: <?php echo htmlspecialchars($location['location_name']); ?>
                </h3>
                <small class="text-white">
                    <i class="fas fa-map-marker-alt mr-1"></i>
                    <?php echo htmlspecialchars($location['location_type']); ?> - 
                    <?php echo htmlspecialchars($location['location_description'] ?? 'No description'); ?>
                </small>
            </div>
            <div class="card-tools">
                <a href="inventory_locations.php" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Locations
                </a>
                <a href="inventory_transfer_create.php?from_location_id=<?php echo $location_id; ?>" class="btn btn-success ml-2">
                    <i class="fas fa-truck-loading mr-2"></i>Create Transfer
                </a>
            </div>
        </div>
    </div>

    <div class="card-header pb-2 pt-3">
        <form autocomplete="off">
            <input type="hidden" name="location_id" value="<?php echo $location_id; ?>">
            <input type="hidden" name="sort" value="<?php echo $sort; ?>">
            <input type="hidden" name="order" value="<?php echo $order; ?>">
            <div class="row">
                <div class="col-md-5">
                    <div class="form-group">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Search items, batches, codes..." autofocus>
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
                                <i class="fas fa-box text-primary mr-1"></i>
                                Items: <strong><?php echo $stats['total_items'] ?? 0; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-layer-group text-info mr-1"></i>
                                Batches: <strong><?php echo $stats['total_batches'] ?? 0; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-weight text-success mr-1"></i>
                                Quantity: <strong><?php echo number_format($stats['total_quantity'] ?? 0, 3); ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-dollar-sign text-warning mr-1"></i>
                                Value: <strong>$<?php echo number_format($stats['total_value'] ?? 0, 2); ?></strong>
                            </span>
                            <?php if ($stats['expiring_soon_batches'] > 0): ?>
                            <span class="btn btn-light border text-danger">
                                <i class="fas fa-clock mr-1"></i>
                                Expiring: <strong><?php echo $stats['expiring_soon_batches']; ?></strong>
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php if ($category_filter || $status_filter || !empty($q)) { echo "show"; } ?>" id="advancedFilter">
                <div class="row">
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Category</label>
                            <select class="form-control select2" name="category" onchange="this.form.submit()">
                                <option value="">- All Categories -</option>
                                <?php if ($categories_sql): ?>
                                    <?php while($category = $categories_sql->fetch_assoc()): ?>
                                        <option value="<?php echo $category['category_id']; ?>" <?php if ($category_filter == $category['category_id']) { echo "selected"; } ?>>
                                            <?php echo htmlspecialchars($category['category_name']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Status</label>
                            <select class="form-control select2" name="status" onchange="this.form.submit()">
                                <option value="">- All Statuses -</option>
                                <option value="active" <?php if ($status_filter == 'active') { echo "selected"; } ?>>Active</option>
                                <option value="inactive" <?php if ($status_filter == 'inactive') { echo "selected"; } ?>>Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Sort By</label>
                            <select class="form-control select2" name="sort" onchange="this.form.submit()">
                                <option value="i.item_name" data-order="ASC" <?php if ($sort == "i.item_name" && $order == "ASC") { echo "selected"; } ?>>Name (A-Z)</option>
                                <option value="i.item_name" data-order="DESC" <?php if ($sort == "i.item_name" && $order == "DESC") { echo "selected"; } ?>>Name (Z-A)</option>
                                <option value="ils.quantity" data-order="ASC" <?php if ($sort == "ils.quantity" && $order == "ASC") { echo "selected"; } ?>>Quantity (Low to High)</option>
                                <option value="ils.quantity" data-order="DESC" <?php if ($sort == "ils.quantity" && $order == "DESC") { echo "selected"; } ?>>Quantity (High to Low)</option>
                                <option value="ils.unit_cost" data-order="ASC" <?php if ($sort == "ils.unit_cost" && $order == "ASC") { echo "selected"; } ?>>Unit Cost (Low to High)</option>
                                <option value="ils.unit_cost" data-order="DESC" <?php if ($sort == "ils.unit_cost" && $order == "DESC") { echo "selected"; } ?>>Unit Cost (High to Low)</option>
                                <option value="ib.expiry_date" data-order="ASC" <?php if ($sort == "ib.expiry_date" && $order == "ASC") { echo "selected"; } ?>>Expiry (Near to Far)</option>
                                <option value="ib.expiry_date" data-order="DESC" <?php if ($sort == "ib.expiry_date" && $order == "DESC") { echo "selected"; } ?>>Expiry (Far to Near)</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Quick Actions</label>
                            <div class="btn-group btn-block">
                                <a href="inventory_locations_items_view.php?location_id=<?php echo $location_id; ?>" class="btn btn-outline-secondary">
                                    <i class="fas fa-times mr-2"></i>Clear Filters
                                </a>
                                <a href="inventory_items.php" class="btn btn-info">
                                    <i class="fas fa-boxes mr-2"></i>All Items
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <div class="card-body">
        <?php if (isset($_SESSION['alert_message'])): ?>
            <div class="alert alert-<?php echo $_SESSION['alert_type']; ?> alert-dismissible">
                <button type="button" class="close" data-dismiss="alert">&times;</button>
                <i class="icon fas fa-<?php echo $_SESSION['alert_type'] == 'success' ? 'check' : 'exclamation-triangle'; ?>"></i>
                <?php echo $_SESSION['alert_message']; ?>
            </div>
            <?php 
            unset($_SESSION['alert_type']);
            unset($_SESSION['alert_message']);
            ?>
        <?php endif; ?>

        <!-- Location Information Card -->
        <div class="card card-info mb-4">
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3">
                        <div class="text-center">
                            <i class="fas fa-map-marker-alt fa-3x text-info mb-2"></i>
                            <h5><?php echo htmlspecialchars($location['location_name']); ?></h5>
                            <span class="badge badge-info"><?php echo htmlspecialchars($location['location_type']); ?></span>
                            <?php if ($stats['expiring_soon_batches'] > 0): ?>
                                <br><span class="badge badge-danger mt-2"><?php echo $stats['expiring_soon_batches']; ?> batches expiring soon</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-9">
                        <div class="row">
                            <div class="col-md-3">
                                <div class="small-box bg-primary">
                                    <div class="inner">
                                        <h3><?php echo $stats['total_items'] ?? 0; ?></h3>
                                        <p>Unique Items</p>
                                    </div>
                                    <div class="icon">
                                        <i class="fas fa-box"></i>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="small-box bg-success">
                                    <div class="inner">
                                        <h3><?php echo $stats['total_batches'] ?? 0; ?></h3>
                                        <p>Total Batches</p>
                                    </div>
                                    <div class="icon">
                                        <i class="fas fa-layer-group"></i>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="small-box bg-warning">
                                    <div class="inner">
                                        <h3><?php echo number_format($stats['total_quantity'] ?? 0, 2); ?></h3>
                                        <p>Total Quantity</p>
                                    </div>
                                    <div class="icon">
                                        <i class="fas fa-weight"></i>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="small-box bg-danger">
                                    <div class="inner">
                                        <h3>$<?php echo number_format($stats['total_value'] ?? 0, 2); ?></h3>
                                        <p>Total Value</p>
                                    </div>
                                    <div class="icon">
                                        <i class="fas fa-dollar-sign"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php if ($location['location_description']): ?>
                            <div class="mt-2">
                                <strong>Description:</strong> <?php echo htmlspecialchars($location['location_description']); ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($suppliers_sql->num_rows > 0): ?>
                            <div class="mt-2">
                                <strong>Suppliers:</strong> 
                                <?php 
                                $suppliers = [];
                                while ($supplier = $suppliers_sql->fetch_assoc()) {
                                    $suppliers[] = htmlspecialchars($supplier['supplier_name']);
                                }
                                echo implode(', ', $suppliers);
                                ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="table-responsive-sm">
            <table class="table table-hover mb-0">
                <thead class="<?php if ($num_rows[0] == 0) { echo "d-none"; } ?> bg-light">
                <tr>
                    <th>Item</th>
                    <th>Batch</th>
                    <th>Category</th>
                    <th class="text-center">Quantity</th>
                    <th class="text-center">Unit Cost</th>
                    <th class="text-center">Total Value</th>
                    <th>Expiry</th>
                    <th>Supplier</th>
                    <th class="text-center">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($items_result->num_rows > 0): ?>
                    <?php while ($item = $items_result->fetch_assoc()): ?>
                        <?php
                        // Determine expiry status
                        $expiry_status = 'success';
                        $expiry_icon = 'check';
                        $expiry_text = 'Good';
                        $expiry_days = $item['days_until_expiry'];
                        
                        if ($expiry_days < 0) {
                            $expiry_status = 'danger';
                            $expiry_icon = 'calendar-times';
                            $expiry_text = 'Expired';
                        } elseif ($expiry_days <= 30) {
                            $expiry_status = 'warning';
                            $expiry_icon = 'clock';
                            $expiry_text = 'Expiring Soon';
                        }
                        
                        // Stock status based on reorder level
                        $stock_class = 'success';
                        $stock_text = 'Good';
                        if ($item['quantity'] <= 0) {
                            $stock_class = 'danger';
                            $stock_text = 'Out';
                        } elseif ($item['quantity'] <= 10) { // Assuming reorder level of 10
                            $stock_class = 'warning';
                            $stock_text = 'Low';
                        }
                        ?>
                        <tr class="<?php echo $expiry_status == 'danger' ? 'table-danger' : ($expiry_status == 'warning' ? 'table-warning' : ''); ?>">
                            <td>
                                <div class="font-weight-bold"><?php echo htmlspecialchars($item['item_name']); ?></div>
                                <small class="text-muted"><?php echo htmlspecialchars($item['item_code']); ?></small>
                                <br>
                                <span class="badge badge-light"><?php echo htmlspecialchars($item['unit_of_measure']); ?></span>
                                <?php if ($item['is_drug']): ?>
                                    <span class="badge badge-danger ml-1"><i class="fas fa-pills"></i> Drug</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="font-weight-bold"><?php echo htmlspecialchars($item['batch_number']); ?></div>
                                <?php if ($item['manufacturer']): ?>
                                    <small class="text-muted"><?php echo htmlspecialchars($item['manufacturer']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($item['category_name'])): ?>
                                    <span class="badge badge-secondary"><?php echo htmlspecialchars($item['category_name']); ?></span>
                                    <?php if ($item['category_type']): ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($item['category_type']); ?></small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">Uncategorized</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <div class="font-weight-bold <?php echo 'text-' . $stock_class; ?>">
                                    <?php echo number_format($item['quantity'], 3); ?>
                                </div>
                                <?php if ($item['total_quantity_all_locations'] > 0): ?>
                                    <small class="text-muted">
                                        Total: <?php echo number_format($item['total_quantity_all_locations'], 3); ?>
                                    </small>
                                <?php endif; ?>
                                <br>
                                <span class="badge badge-<?php echo $stock_class; ?> badge-sm"><?php echo $stock_text; ?></span>
                            </td>
                            <td class="text-center">
                                <span class="font-weight-bold">$<?php echo number_format($item['unit_cost'], 4); ?></span>
                                <?php if ($item['selling_price']): ?>
                                    <br><small class="text-success">Sell: $<?php echo number_format($item['selling_price'], 2); ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <span class="font-weight-bold text-success">$<?php echo number_format($item['total_value'], 2); ?></span>
                            </td>
                            <td>
                                <?php if ($item['expiry_date']): ?>
                                    <div><?php echo date('M j, Y', strtotime($item['expiry_date'])); ?></div>
                                    <span class="badge badge-<?php echo $expiry_status; ?>" data-toggle="tooltip" title="<?php echo $expiry_text; ?>">
                                        <i class="fas fa-<?php echo $expiry_icon; ?> mr-1"></i>
                                        <?php 
                                        if ($expiry_days < 0) {
                                            echo 'Expired ' . abs($expiry_days) . ' days ago';
                                        } elseif ($expiry_days == 0) {
                                            echo 'Expires today';
                                        } else {
                                            echo $expiry_days . ' days left';
                                        }
                                        ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">No expiry</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($item['supplier_name'])): ?>
                                    <small><?php echo htmlspecialchars($item['supplier_name']); ?></small>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="dropdown dropleft text-center">
                                    <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                        <i class="fas fa-ellipsis-h"></i>
                                    </button>
                                    <div class="dropdown-menu">
                                        <a class="dropdown-item" href="inventory_item_details.php?item_id=<?php echo $item['item_id']; ?>">
                                            <i class="fas fa-fw fa-eye mr-2"></i>View Item
                                        </a>
                                        <a class="dropdown-item" href="inventory_batch_details.php?batch_id=<?php echo $item['batch_id']; ?>">
                                            <i class="fas fa-fw fa-layer-group mr-2"></i>View Batch
                                        </a>
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item text-primary" href="inventory_transaction.php?item_id=<?php echo $item['item_id']; ?>&batch_id=<?php echo $item['batch_id']; ?>&location_id=<?php echo $location_id; ?>">
                                            <i class="fas fa-fw fa-exchange-alt mr-2"></i>Record Transaction
                                        </a>
                                        <a class="dropdown-item text-info" href="inventory_transfer_create.php?item_id=<?php echo $item['item_id']; ?>&batch_id=<?php echo $item['batch_id']; ?>&from_location_id=<?php echo $location_id; ?>">
                                            <i class="fas fa-fw fa-truck mr-2"></i>Transfer Stock
                                        </a>
                                        <a class="dropdown-item text-warning" href="inventory_adjustment.php?item_id=<?php echo $item['item_id']; ?>&batch_id=<?php echo $item['batch_id']; ?>&location_id=<?php echo $location_id; ?>">
                                            <i class="fas fa-fw fa-adjust mr-2"></i>Adjust Stock
                                        </a>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="9" class="text-center py-4">
                            <i class="fas fa-box fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">No Items Found in This Location</h5>
                            <p class="text-muted">This location currently has no inventory items.</p>
                            <a href="inventory_items.php" class="btn btn-primary">
                                <i class="fas fa-plus mr-2"></i>Add Items to Inventory
                            </a>
                            <a href="inventory_locations.php" class="btn btn-outline-secondary ml-2">
                                <i class="fas fa-arrow-left mr-2"></i>Back to Locations
                            </a>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Ends Card Body -->
        <?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/filter_footer.php'; ?>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.select2').select2();
    $('[data-toggle="tooltip"]').tooltip();
    
    // Auto-focus on search input
    $('input[name="q"]').focus();
    
    // Handle sort selection
    $('select[name="sort"]').on('change', function() {
        var selected = $(this).find('option:selected');
        var sort = selected.val();
        var order = selected.data('order');
        
        // Update hidden fields
        $('input[name="sort"]').val(sort);
        $('input[name="order"]').val(order);
    });
});

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + F for focus search
    if (e.ctrlKey && e.keyCode === 70) {
        e.preventDefault();
        $('input[name="q"]').focus();
    }
    // Escape to go back
    if (e.keyCode === 27) {
        window.location.href = 'inventory_locations.php';
    }
    // Ctrl + T for create transfer
    if (e.ctrlKey && e.keyCode === 84) {
        e.preventDefault();
        window.location.href = 'inventory_transfer_create.php?from_location_id=<?php echo $location_id; ?>';
    }
});
</script>

<style>
.small-box {
    border-radius: 0.25rem;
    box-shadow: 0 0 1px rgba(0,0,0,.125), 0 1px 3px rgba(0,0,0,.2);
    display: block;
    margin-bottom: 20px;
    position: relative;
    min-height: 85px;
}

.small-box > .inner {
    padding: 10px;
}

.small-box .inner h3 {
    font-size: 1.8rem;
    font-weight: bold;
    margin: 0 0 10px 0;
    padding: 0;
    white-space: nowrap;
}

.small-box .inner p {
    font-size: 14px;
    color: rgba(255, 255, 255, 0.8);
    margin: 0;
}

.small-box .icon {
    position: absolute;
    top: 10px;
    right: 10px;
    z-index: 0;
    font-size: 60px;
    color: rgba(0,0,0,0.15);
    transition: all .3s linear;
}

.table th {
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.85em;
    letter-spacing: 0.5px;
}

.badge-pill {
    padding-right: 0.6em;
    padding-left: 0.6em;
}

.badge-sm {
    font-size: 0.7em;
    padding: 0.2em 0.5em;
}
</style>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Get item ID from URL
$item_id = intval($_GET['item_id'] ?? 0);

if ($item_id <= 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid item ID.";
    header("Location: inventory_items.php");
    exit;
}

// Get item details
$item = $mysqli->query("
    SELECT i.*, 
           ic.category_name, ic.category_type, ic.description as category_description,
           u.user_name as created_by_name,
           u2.user_name as updated_by_name
    FROM inventory_items i
    LEFT JOIN inventory_categories ic ON i.category_id = ic.category_id
    LEFT JOIN users u ON i.created_by = u.user_id
    LEFT JOIN users u2 ON i.updated_by = u2.user_id
    WHERE i.item_id = $item_id
")->fetch_assoc();

if (!$item) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Item not found or has been deleted.";
    header("Location: inventory_items.php");
    exit;
}

// Get total stock quantity from all batches and locations
$total_stock_sql = "
    SELECT 
        COALESCE(SUM(ils.quantity), 0) as total_quantity,
        COALESCE(SUM(ils.quantity * ils.unit_cost), 0) as total_value,
        COUNT(DISTINCT ib.batch_id) as batch_count,
        COUNT(DISTINCT ils.location_id) as location_count
    FROM inventory_items i
    LEFT JOIN inventory_batches ib ON i.item_id = ib.item_id AND ib.is_active = 1
    LEFT JOIN inventory_location_stock ils ON ib.batch_id = ils.batch_id AND ils.is_active = 1
    WHERE i.item_id = $item_id
";
$total_stock_result = $mysqli->query($total_stock_sql);
$total_stock = $total_stock_result->fetch_assoc();

// Get recent batches
$batches_sql = "
    SELECT ib.*, 
           s.supplier_name,
           DATEDIFF(ib.expiry_date, CURDATE()) as days_until_expiry
    FROM inventory_batches ib
    LEFT JOIN suppliers s ON ib.supplier_id = s.supplier_id
    WHERE ib.item_id = $item_id AND ib.is_active = 1
    ORDER BY ib.expiry_date ASC, ib.batch_id DESC
    LIMIT 10
";
$batches_result = $mysqli->query($batches_sql);

// Get stock by location
$locations_sql = "
    SELECT 
        il.location_id,
        il.location_name,
        il.location_type,
        COALESCE(SUM(ils.quantity), 0) as total_quantity,
        COUNT(DISTINCT ib.batch_id) as batch_count
    FROM inventory_locations il
    LEFT JOIN inventory_location_stock ils ON il.location_id = ils.location_id AND ils.is_active = 1
    LEFT JOIN inventory_batches ib ON ils.batch_id = ib.batch_id AND ib.item_id = $item_id
    WHERE il.is_active = 1
    GROUP BY il.location_id, il.location_name, il.location_type
    HAVING total_quantity > 0
    ORDER BY total_quantity DESC
";
$locations_result = $mysqli->query($locations_sql);

// Get recent transactions
$transactions_sql = "
    SELECT 
        t.transaction_id,
        t.transaction_type,
        t.quantity,
        t.unit_cost,
        t.created_at,
        t.reason,
        u.user_name as created_by_name,
        fl.location_name as from_location_name,
        tl.location_name as to_location_name
    FROM inventory_transactions t
    LEFT JOIN users u ON t.created_by = u.user_id
    LEFT JOIN inventory_locations fl ON t.from_location_id = fl.location_id
    LEFT JOIN inventory_locations tl ON t.to_location_id = tl.location_id
    WHERE t.item_id = $item_id AND t.is_active = 1
    ORDER BY t.created_at DESC
    LIMIT 10
";
$transactions_result = $mysqli->query($transactions_sql);

// Get activity log for this item
$activities_sql = "
    SELECT l.*, u.user_name as performed_by_name
    FROM logs l
    LEFT JOIN users u ON l.log_user_id = u.user_id
    WHERE l.log_entity_id = $item_id AND l.log_type = 'Inventory'
    ORDER BY l.log_created_at DESC
    LIMIT 20
";
$activities_result = $mysqli->query($activities_sql);

// Determine stock status based on reorder level
$stock_status = 'In Stock';
$status_color = 'success';
if ($total_stock['total_quantity'] == 0) {
    $stock_status = 'Out of Stock';
    $status_color = 'danger';
} elseif ($item['reorder_level'] > 0 && $total_stock['total_quantity'] <= $item['reorder_level']) {
    $stock_status = 'Low Stock';
    $status_color = 'warning';
}

// Check if item has expired batches
$expired_batches_sql = "
    SELECT COUNT(*) as expired_count
    FROM inventory_batches ib
    LEFT JOIN inventory_location_stock ils ON ib.batch_id = ils.batch_id
    WHERE ib.item_id = $item_id 
    AND ib.expiry_date < CURDATE() 
    AND ils.quantity > 0
    AND ib.is_active = 1
";
$expired_result = $mysqli->query($expired_batches_sql);
$expired_batches = $expired_result->fetch_assoc()['expired_count'];

// Check for batches expiring soon (within 30 days)
$expiring_soon_sql = "
    SELECT COUNT(*) as expiring_soon_count
    FROM inventory_batches ib
    LEFT JOIN inventory_location_stock ils ON ib.batch_id = ils.batch_id
    WHERE ib.item_id = $item_id 
    AND ib.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    AND ils.quantity > 0
    AND ib.is_active = 1
";
$expiring_soon_result = $mysqli->query($expiring_soon_sql);
$expiring_soon_batches = $expiring_soon_result->fetch_assoc()['expiring_soon_count'];
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-cube mr-2"></i>Item Details: <?php echo htmlspecialchars($item['item_name']); ?>
        </h3>
        <div class="card-tools">
            <a href="inventory_items.php" class="btn btn-light">
                <i class="fas fa-arrow-left mr-2"></i>Back to Inventory
            </a>
        </div>
    </div>
    
    <!-- Statistics Row -->
    <div class="card-body border-bottom">
        <div class="row text-center">
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-primary"><i class="fas fa-boxes"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Stock</span>
                        <span class="info-box-number"><?php echo number_format($total_stock['total_quantity'], 3); ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-info"><i class="fas fa-dollar-sign"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Value</span>
                        <span class="info-box-number">$<?php echo number_format($total_stock['total_value'], 2); ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-success"><i class="fas fa-layer-group"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Active Batches</span>
                        <span class="info-box-number"><?php echo $total_stock['batch_count']; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-warning"><i class="fas fa-map-marker-alt"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Stock Locations</span>
                        <span class="info-box-number"><?php echo $total_stock['location_count']; ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card-body">
        <!-- Item Header Actions -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="btn-toolbar justify-content-between">
                    <div class="btn-group">
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Status:</strong> 
                            <span class="badge badge-<?php echo $item['status'] == 'active' ? 'success' : 'secondary'; ?> ml-2">
                                <?php echo ucfirst($item['status']); ?>
                            </span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Stock:</strong> 
                            <span class="badge badge-<?php echo $status_color; ?> ml-2"><?php echo $stock_status; ?></span>
                        </span>
                        <?php if ($expired_batches > 0): ?>
                            <span class="btn btn-outline-secondary disabled">
                                <strong>Expired:</strong> 
                                <span class="badge badge-danger ml-2"><?php echo $expired_batches; ?> batches</span>
                            </span>
                        <?php endif; ?>
                        <?php if ($expiring_soon_batches > 0): ?>
                            <span class="btn btn-outline-secondary disabled">
                                <strong>Expiring Soon:</strong> 
                                <span class="badge badge-warning ml-2"><?php echo $expiring_soon_batches; ?> batches</span>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="btn-group">
                        <a href="inventory_edit_item.php?item_id=<?php echo $item_id; ?>" class="btn btn-success">
                            <i class="fas fa-edit mr-2"></i>Edit Item
                        </a>
                        <a href="inventory_batch_create.php?item_id=<?php echo $item_id; ?>" class="btn btn-primary">
                            <i class="fas fa-plus-circle mr-2"></i>Add Batch
                        </a>
                        <div class="dropdown">
                            <button class="btn btn-secondary dropdown-toggle" type="button" data-toggle="dropdown">
                                <i class="fas fa-cog mr-2"></i>Actions
                            </button>
                            <div class="dropdown-menu">
                                <?php if ($item['status'] == 'active'): ?>
                                    <a class="dropdown-item text-warning" href="#" onclick="deactivateItem(<?php echo $item_id; ?>)">
                                        <i class="fas fa-pause mr-2"></i>Deactivate Item
                                    </a>
                                <?php else: ?>
                                    <a class="dropdown-item text-success" href="#" onclick="activateItem(<?php echo $item_id; ?>)">
                                        <i class="fas fa-play mr-2"></i>Activate Item
                                    </a>
                                <?php endif; ?>
                                <a class="dropdown-item" href="inventory_transaction.php?item_id=<?php echo $item_id; ?>">
                                    <i class="fas fa-exchange-alt mr-2"></i>Record Transaction
                                </a>
                                <a class="dropdown-item" href="inventory_requisition_create.php?item_id=<?php echo $item_id; ?>">
                                    <i class="fas fa-clipboard-list mr-2"></i>Create Requisition
                                </a>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item text-danger confirm-link" href="post/inventory.php?delete_item=<?php echo $item_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>">
                                    <i class="fas fa-trash mr-2"></i>Delete Item
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Item Information -->
            <div class="col-md-6">
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-info-circle mr-2"></i>Item Information</h4>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <th width="40%" class="text-muted">Item Code:</th>
                                    <td><strong class="text-primary"><?php echo htmlspecialchars($item['item_code']); ?></strong></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Item Name:</th>
                                    <td><strong><?php echo htmlspecialchars($item['item_name']); ?></strong></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Category:</th>
                                    <td>
                                        <span class="badge badge-info">
                                            <?php echo htmlspecialchars($item['category_name'] ?? 'No category'); ?>
                                        </span>
                                        <?php if ($item['category_type']): ?>
                                            <small class="text-muted d-block"><?php echo htmlspecialchars($item['category_type']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Unit of Measure:</th>
                                    <td><span class="badge badge-light"><?php echo htmlspecialchars($item['unit_of_measure']); ?></span></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Reorder Level:</th>
                                    <td>
                                        <span class="badge badge-<?php echo $stock_status == 'Low Stock' ? 'warning' : 'secondary'; ?>">
                                            <?php echo number_format($item['reorder_level'], 3); ?>
                                        </span>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Item Properties -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-tags mr-2"></i>Item Properties</h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-check mb-3">
                                    <input type="checkbox" class="form-check-input" disabled <?php echo $item['is_drug'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label">
                                        <i class="fas fa-pills mr-2 <?php echo $item['is_drug'] ? 'text-danger' : 'text-muted'; ?>"></i>
                                        Drug/Pharmaceutical Item
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check mb-3">
                                    <input type="checkbox" class="form-check-input" disabled <?php echo $item['requires_batch'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label">
                                        <i class="fas fa-layer-group mr-2 <?php echo $item['requires_batch'] ? 'text-info' : 'text-muted'; ?>"></i>
                                        Batch Tracking Required
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <?php if ($item['notes']): ?>
                            <div class="mt-3">
                                <label class="text-muted small">Notes:</label>
                                <div class="border rounded p-3 bg-light">
                                    <?php echo nl2br(htmlspecialchars($item['notes'])); ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Item Metadata & Stock Status -->
            <div class="col-md-6">
                <!-- Item Metadata -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-database mr-2"></i>Item Metadata</h4>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <th width="40%" class="text-muted">Created By:</th>
                                    <td><?php echo htmlspecialchars($item['created_by_name'] ?? 'NA'); ?></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Created Date:</th>
                                    <td><?php echo date('M j, Y H:i', strtotime($item['created_at'])); ?></td>
                                </tr>
                                <?php if ($item['updated_by']): ?>
                                <tr>
                                    <th class="text-muted">Last Updated By:</th>
                                    <td><?php echo htmlspecialchars($item['updated_by_name']); ?></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Last Updated:</th>
                                    <td><?php echo date('M j, Y H:i', strtotime($item['updated_at'])); ?></td>
                                </tr>
                                <?php endif; ?>
                                <tr>
                                    <th class="text-muted">Stock Locations:</th>
                                    <td><?php echo $total_stock['location_count']; ?> location(s)</td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Active Batches:</th>
                                    <td><?php echo $total_stock['batch_count']; ?> batch(es)</td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Stock Status Card -->
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-chart-bar mr-2"></i>Stock Status</h4>
                    </div>
                    <div class="card-body">
                        <div class="text-center">
                            <div class="mb-3">
                                <div class="h4 text-<?php echo $status_color; ?>">
                                    <i class="fas fa-<?php echo $status_color == 'success' ? 'check-circle' : ($status_color == 'warning' ? 'exclamation-triangle' : 'times-circle'); ?> mr-2"></i>
                                    <?php echo $stock_status; ?>
                                </div>
                                <small class="text-muted">
                                    <?php echo $stock_status == 'In Stock' ? 'This item has sufficient stock' : 
                                           ($stock_status == 'Low Stock' ? 'Stock is below reorder level' : 
                                           'No stock available'); ?>
                                </small>
                            </div>
                            
                            <?php if ($total_stock['total_quantity'] > 0): ?>
                                <div class="progress mb-2" style="height: 20px;">
                                    <?php 
                                    $current_ratio = $total_stock['total_quantity'];
                                    $max_ratio = max($total_stock['total_quantity'], $item['reorder_level'] * 3);
                                    $percentage = ($current_ratio / $max_ratio) * 100;
                                    $progress_class = 'bg-success';
                                    if ($stock_status == 'Low Stock') {
                                        $progress_class = 'bg-warning';
                                    } elseif ($stock_status == 'Out of Stock') {
                                        $progress_class = 'bg-danger';
                                    }
                                    ?>
                                    <div class="progress-bar <?php echo $progress_class; ?>" style="width: <?php echo min($percentage, 100); ?>%">
                                        <?php echo number_format($total_stock['total_quantity'], 3); ?> Units
                                    </div>
                                </div>
                                <small class="text-muted">
                                    Reorder Level: <?php echo number_format($item['reorder_level'], 3); ?> units
                                </small>
                            <?php else: ?>
                                <div class="alert alert-info mb-0">
                                    <i class="fas fa-info-circle mr-2"></i>
                                    This item has no stock in inventory.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Stock by Location -->
        <div class="card mb-4">
            <div class="card-header bg-light py-2">
                <h4 class="card-title mb-0"><i class="fas fa-map-marker-alt mr-2"></i>Stock by Location</h4>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th>Location</th>
                                <th>Type</th>
                                <th class="text-center">Quantity</th>
                                <th class="text-center">Batches</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($locations_result->num_rows > 0): ?>
                                <?php while($location = $locations_result->fetch_assoc()): ?>
                                    <?php
                                    $location_status = 'success';
                                    if ($location['total_quantity'] <= 0) {
                                        $location_status = 'danger';
                                    } elseif ($item['reorder_level'] > 0 && $location['total_quantity'] <= $item['reorder_level'] / 2) {
                                        $location_status = 'warning';
                                    }
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="font-weight-bold"><?php echo htmlspecialchars($location['location_name']); ?></div>
                                            <small class="text-muted">ID: <?php echo $location['location_id']; ?></small>
                                        </td>
                                        <td>
                                            <span class="badge badge-light"><?php echo htmlspecialchars($location['location_type']); ?></span>
                                        </td>
                                        <td class="text-center">
                                            <span class="font-weight-bold text-<?php echo $location_status; ?>">
                                                <?php echo number_format($location['total_quantity'], 3); ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge badge-secondary"><?php echo $location['batch_count']; ?></span>
                                        </td>
                                        <td class="text-center">
                                            <a href="inventory_transaction.php?item_id=<?php echo $item_id; ?>&location_id=<?php echo $location['location_id']; ?>" class="btn btn-sm btn-info" title="Record Transaction">
                                                <i class="fas fa-exchange-alt"></i>
                                            </a>
                                            <a href="inventory_transfer_create.php?item_id=<?php echo $item_id; ?>&from_location_id=<?php echo $location['location_id']; ?>" class="btn btn-sm btn-warning ml-1" title="Transfer Stock">
                                                <i class="fas fa-truck"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center py-4">
                                        <i class="fas fa-map-marker-alt fa-2x text-muted mb-3"></i>
                                        <h5 class="text-muted">No Stock Locations Found</h5>
                                        <p class="text-muted">This item is not stocked in any location yet.</p>
                                        <a href="inventory_batch_create.php?item_id=<?php echo $item_id; ?>" class="btn btn-sm btn-primary">
                                            <i class="fas fa-plus mr-2"></i>Add Batch & Stock
                                        </a>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php if ($locations_result->num_rows > 0): ?>
            <div class="card-footer">
                <a href="inventory_transfer_create.php?item_id=<?php echo $item_id; ?>" class="btn btn-outline-primary btn-sm">
                    <i class="fas fa-truck mr-2"></i>Transfer Stock
                </a>
                <a href="inventory_transaction.php?item_id=<?php echo $item_id; ?>" class="btn btn-outline-success btn-sm ml-2">
                    <i class="fas fa-exchange-alt mr-2"></i>New Transaction
                </a>
            </div>
            <?php endif; ?>
        </div>

        <!-- Recent Batches -->
        <?php if ($item['requires_batch'] && $batches_result->num_rows > 0): ?>
        <div class="card mb-4">
            <div class="card-header bg-light py-2">
                <h4 class="card-title mb-0"><i class="fas fa-layer-group mr-2"></i>Recent Batches</h4>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th>Batch Number</th>
                                <th>Expiry Date</th>
                                <th>Supplier</th>
                                <th>Received Date</th>
                                <th class="text-center">Stock</th>
                                <th class="text-center">Status</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($batch = $batches_result->fetch_assoc()): ?>
                                <?php
                                $batch_status = 'success';
                                $batch_icon = 'check-circle';
                                if ($batch['days_until_expiry'] < 0) {
                                    $batch_status = 'danger';
                                    $batch_icon = 'calendar-times';
                                } elseif ($batch['days_until_expiry'] <= 30) {
                                    $batch_status = 'warning';
                                    $batch_icon = 'clock';
                                }
                                
                                // Get batch stock quantity
                                $batch_stock_sql = "SELECT COALESCE(SUM(quantity), 0) as total_quantity FROM inventory_location_stock WHERE batch_id = " . $batch['batch_id'] . " AND is_active = 1";
                                $batch_stock_result = $mysqli->query($batch_stock_sql);
                                $batch_stock = $batch_stock_result->fetch_assoc();
                                ?>
                                <tr>
                                    <td>
                                        <div class="font-weight-bold"><?php echo htmlspecialchars($batch['batch_number']); ?></div>
                                        <?php if ($batch['manufacturer']): ?>
                                            <small class="text-muted"><?php echo htmlspecialchars($batch['manufacturer']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div><?php echo date('M j, Y', strtotime($batch['expiry_date'])); ?></div>
                                        <small class="text-<?php echo $batch_status; ?>">
                                            <?php 
                                            if ($batch['days_until_expiry'] < 0) {
                                                echo 'Expired ' . abs($batch['days_until_expiry']) . ' days ago';
                                            } elseif ($batch['days_until_expiry'] == 0) {
                                                echo 'Expires today';
                                            } else {
                                                echo $batch['days_until_expiry'] . ' days left';
                                            }
                                            ?>
                                        </small>
                                    </td>
                                    <td><?php echo htmlspecialchars($batch['supplier_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo date('M j, Y', strtotime($batch['received_date'])); ?></td>
                                    <td class="text-center">
                                        <span class="badge badge-info badge-pill"><?php echo $batch_stock['total_quantity']; ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge badge-<?php echo $batch_status; ?>">
                                            <i class="fas fa-<?php echo $batch_icon; ?> mr-1"></i>
                                            <?php 
                                            echo $batch['days_until_expiry'] < 0 ? 'Expired' : 
                                                 ($batch['days_until_expiry'] <= 30 ? 'Expiring Soon' : 'Active');
                                            ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <a href="inventory_batch_view.php?id=<?php echo $batch['batch_id']; ?>" class="btn btn-sm btn-info" title="View Batch">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer">
                <a href="inventory_batches.php?item_id=<?php echo $item_id; ?>" class="btn btn-outline-primary btn-sm">
                    <i class="fas fa-list mr-2"></i>View All Batches
                </a>
                <a href="inventory_batch_create.php?item_id=<?php echo $item_id; ?>" class="btn btn-outline-success btn-sm ml-2">
                    <i class="fas fa-plus mr-2"></i>Add New Batch
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- Recent Transactions -->
        <div class="card mb-4">
            <div class="card-header bg-light py-2">
                <h4 class="card-title mb-0"><i class="fas fa-history mr-2"></i>Recent Transactions</h4>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th>Date & Time</th>
                                <th>Type</th>
                                <th class="text-center">Quantity</th>
                                <th class="text-center">Unit Cost</th>
                                <th>From/To</th>
                                <th>Performed By</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($transactions_result->num_rows > 0): ?>
                                <?php while($transaction = $transactions_result->fetch_assoc()): ?>
                                    <?php
                                    $type_class = 'secondary';
                                    $type_icon = 'exchange-alt';
                                    $quantity_sign = '';
                                    if (in_array($transaction['transaction_type'], ['GRN', 'TRANSFER_IN'])) {
                                        $type_class = 'success';
                                        $type_icon = 'arrow-down';
                                        $quantity_sign = '+';
                                    } elseif (in_array($transaction['transaction_type'], ['ISSUE', 'WASTAGE', 'TRANSFER_OUT'])) {
                                        $type_class = 'danger';
                                        $type_icon = 'arrow-up';
                                        $quantity_sign = '-';
                                    } elseif ($transaction['transaction_type'] == 'ADJUSTMENT') {
                                        $type_class = 'warning';
                                        $type_icon = 'adjust';
                                    } elseif ($transaction['transaction_type'] == 'RETURN') {
                                        $type_class = 'info';
                                        $type_icon = 'undo';
                                    }
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="font-weight-bold"><?php echo date('M j, Y', strtotime($transaction['created_at'])); ?></div>
                                            <small class="text-muted"><?php echo date('H:i', strtotime($transaction['created_at'])); ?></small>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?php echo $type_class; ?>">
                                                <i class="fas fa-<?php echo $type_icon; ?> mr-1"></i>
                                                <?php echo $transaction['transaction_type']; ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <span class="font-weight-bold text-<?php echo $type_class; ?>">
                                                <?php echo $quantity_sign . number_format($transaction['quantity'], 3); ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <small>$<?php echo number_format($transaction['unit_cost'], 4); ?></small>
                                        </td>
                                        <td>
                                            <?php if ($transaction['from_location_name'] && $transaction['to_location_name']): ?>
                                                <small><?php echo $transaction['from_location_name']; ?> → <?php echo $transaction['to_location_name']; ?></small>
                                            <?php elseif ($transaction['from_location_name']): ?>
                                                <small>From: <?php echo $transaction['from_location_name']; ?></small>
                                            <?php elseif ($transaction['to_location_name']): ?>
                                                <small>To: <?php echo $transaction['to_location_name']; ?></small>
                                            <?php else: ?>
                                                <small class="text-muted">-</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <small><?php echo htmlspecialchars($transaction['created_by_name']); ?></small>
                                        </td>
                                        <td class="text-center">
                                            <a href="inventory_transaction_view.php?id=<?php echo $transaction['transaction_id']; ?>" class="btn btn-sm btn-info" title="View Transaction">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4">
                                        <i class="fas fa-exchange-alt fa-2x text-muted mb-3"></i>
                                        <h5 class="text-muted">No Transactions Found</h5>
                                        <p class="text-muted">No transactions recorded for this item yet.</p>
                                        <a href="inventory_transaction.php?item_id=<?php echo $item_id; ?>" class="btn btn-sm btn-primary">
                                            <i class="fas fa-plus mr-2"></i>Record Transaction
                                        </a>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php if ($transactions_result->num_rows > 0): ?>
            <div class="card-footer">
                <a href="inventory_transactions.php?item_id=<?php echo $item_id; ?>" class="btn btn-outline-primary btn-sm">
                    <i class="fas fa-list mr-2"></i>View All Transactions
                </a>
            </div>
            <?php endif; ?>
        </div>

        <!-- Activity Log -->
        <div class="card">
            <div class="card-header bg-light py-2">
                <h4 class="card-title mb-0"><i class="fas fa-stream mr-2"></i>Activity Log</h4>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th>Date & Time</th>
                                <th>Activity Type</th>
                                <th>Description</th>
                                <th>Performed By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($activities_result->num_rows > 0): ?>
                                <?php while($activity = $activities_result->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <div class="font-weight-bold"><?php echo date('M j, Y', strtotime($activity['log_created_at'])); ?></div>
                                            <small class="text-muted"><?php echo date('H:i', strtotime($activity['log_created_at'])); ?></small>
                                        </td>
                                        <td>
                                            <span class="badge badge-info"><?php echo ucfirst(str_replace('_', ' ', $activity['log_action'])); ?></span>
                                        </td>
                                        <td><?php echo htmlspecialchars($activity['log_description']); ?></td>
                                        <td><?php echo htmlspecialchars($activity['performed_by_name']); ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="text-center py-4">
                                        <i class="fas fa-stream fa-2x text-muted mb-3"></i>
                                        <h5 class="text-muted">No Activity Found</h5>
                                        <p class="text-muted">No activities recorded for this item yet.</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Confirm before deleting item
    $('.confirm-link').click(function(e) {
        if (!confirm('Are you sure you want to delete this item? This will also delete all related batches, stock records, and transactions.')) {
            e.preventDefault();
        }
    });

    // Tooltip initialization
    $('[title]').tooltip();
});

function deactivateItem(item_id) {
    if (confirm('Are you sure you want to deactivate this item? It will no longer be available for use in new transactions.')) {
        $.post('post/inventory.php', {
            action: 'deactivate_item',
            item_id: item_id,
            csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'
        }, function(response) {
            if (response.success) {
                location.reload();
            } else {
                alert('Error: ' + response.message);
            }
        }).fail(function() {
            alert('An error occurred. Please try again.');
        });
    }
}

function activateItem(item_id) {
    if (confirm('Are you sure you want to activate this item?')) {
        $.post('post/inventory.php', {
            action: 'activate_item',
            item_id: item_id,
            csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'
        }, function(response) {
            if (response.success) {
                location.reload();
            } else {
                alert('Error: ' + response.message);
            }
        }).fail(function() {
            alert('An error occurred. Please try again.');
        });
    }
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Escape to go back
    if (e.keyCode === 27) {
        window.location.href = 'inventory_items.php';
    }
    // Ctrl + E to edit
    if (e.ctrlKey && e.keyCode === 69) {
        e.preventDefault();
        window.location.href = 'inventory_edit_item.php?item_id=<?php echo $item_id; ?>';
    }
    // Ctrl + B to add batch
    if (e.ctrlKey && e.keyCode === 66) {
        e.preventDefault();
        window.location.href = 'inventory_batch_create.php?item_id=<?php echo $item_id; ?>';
    }
    // Ctrl + T for new transaction
    if (e.ctrlKey && e.keyCode === 84) {
        e.preventDefault();
        window.location.href = 'inventory_transaction.php?item_id=<?php echo $item_id; ?>';
    }
});
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
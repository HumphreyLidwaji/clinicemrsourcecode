<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/price_functions.php';

$price_list_id = intval($_GET['id'] ?? 0);
$action = $_GET['action'] ?? '';

// Get price list details
$sql = "SELECT pl.*, ic.company_name
        FROM price_lists pl
        LEFT JOIN insurance_companies ic ON pl.insurance_provider_id = ic.insurance_company_id
        WHERE pl.price_list_id = ?";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param('i', $price_list_id);
$stmt->execute();
$result = $stmt->get_result();
$price_list = $result->fetch_assoc();
$stmt->close();

if (!$price_list) {
    $_SESSION['alert_message'] = "Price list not found";
    header("Location: price_management.php");
    exit;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action_type = $_POST['action_type'] ?? '';
    
    if ($action_type == 'add_items') {
        $billable_item_ids = $_POST['billable_item_ids'] ?? [];
        $price = floatval($_POST['price'] ?? 0);
        $effective_from = $_POST['effective_from'] ?? date('Y-m-d');
        $effective_to = !empty($_POST['effective_to']) ? $_POST['effective_to'] : null;
        $added_count = 0;
        $created_by = intval($_SESSION['user_id']);
        
        foreach ($billable_item_ids as $billable_item_id) {
            $billable_item_id = intval($billable_item_id);
            
            // Get billable item details
            $item_sql = "SELECT unit_price FROM billable_items WHERE billable_item_id = ? AND is_active = 1";
            $item_stmt = $mysqli->prepare($item_sql);
            $item_stmt->bind_param('i', $billable_item_id);
            $item_stmt->execute();
            $item_result = $item_stmt->get_result();
            $item = $item_result->fetch_assoc();
            $item_stmt->close();
            
            if ($item) {
                // Use provided price or default to item's unit price
                $item_price = $price > 0 ? $price : $item['unit_price'];
                
                // Check if item already has an active price in this price list
                $check_sql = "SELECT price_list_item_id FROM price_list_items 
                             WHERE price_list_id = ? 
                             AND billable_item_id = ? 
                             AND (effective_to IS NULL OR effective_to >= CURDATE())
                             AND effective_from <= CURDATE()";
                $check_stmt = $mysqli->prepare($check_sql);
                $check_stmt->bind_param('ii', $price_list_id, $billable_item_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                $check = $check_result->fetch_assoc();
                $check_stmt->close();
                
                if (!$check) {
                    // Insert item into price list
                    $insert_sql = "INSERT INTO price_list_items 
                                   (price_list_id, billable_item_id, price, effective_from, effective_to, created_by, created_at)
                                   VALUES (?, ?, ?, ?, ?, ?, NOW())";
                    $insert_stmt = $mysqli->prepare($insert_sql);
                    $insert_stmt->bind_param('iidssi', $price_list_id, $billable_item_id, $item_price, $effective_from, $effective_to, $created_by);
                    
                    if ($insert_stmt->execute()) {
                        $added_count++;
                        
                        // Log to history
                        $history_sql = "INSERT INTO price_history 
                                       (price_list_item_id, billable_item_id, price_list_id, 
                                        old_price, new_price, changed_by, reason)
                                       VALUES (?, ?, ?, 0, ?, ?, 'Item added to price list')";
                        $history_stmt = $mysqli->prepare($history_sql);
                        $price_list_item_id = $mysqli->insert_id;
                        $history_stmt->bind_param('iiidi', $price_list_item_id, $billable_item_id, $price_list_id, $item_price, $created_by);
                        $history_stmt->execute();
                        $history_stmt->close();
                    }
                    $insert_stmt->close();
                }
            }
        }
        
        $_SESSION['alert_message'] = "Successfully added $added_count items to price list";
        header("Location: price_add_items.php?id=$price_list_id");
        exit;
    }
    
    if ($action_type == 'remove_item') {
        $price_list_item_id = intval($_POST['price_list_item_id']);
        
        // Get price before removal for history
        $get_sql = "SELECT pli.*, bi.item_name 
                   FROM price_list_items pli
                   JOIN billable_items bi ON pli.billable_item_id = bi.billable_item_id
                   WHERE pli.price_list_item_id = ?";
        $get_stmt = $mysqli->prepare($get_sql);
        $get_stmt->bind_param('i', $price_list_item_id);
        $get_stmt->execute();
        $get_result = $get_stmt->get_result();
        $item = $get_result->fetch_assoc();
        $get_stmt->close();
        
        if ($item) {
            // Remove item from price list
            $delete_sql = "DELETE FROM price_list_items WHERE price_list_item_id = ?";
            $delete_stmt = $mysqli->prepare($delete_sql);
            $delete_stmt->bind_param('i', $price_list_item_id);
            
            if ($delete_stmt->execute()) {
                // Log to history
                $history_sql = "INSERT INTO price_history 
                               (billable_item_id, price_list_id, 
                                old_price, new_price, changed_by, reason)
                               VALUES (?, ?, ?, 0, ?, 'Item removed from price list')";
                $history_stmt = $mysqli->prepare($history_sql);
                $history_stmt->bind_param('iidi', $item['billable_item_id'], $price_list_id, $item['price'], $_SESSION['user_id']);
                $history_stmt->execute();
                $history_stmt->close();
                
                $_SESSION['alert_message'] = "Item removed from price list";
            }
            $delete_stmt->close();
        }
        
        header("Location: price_add_items.php?id=$price_list_id");
        exit;
    }
}

// Get items already added to this price list
$added_items_sql = "SELECT pli.*, bi.item_name, bi.item_code, bi.item_type,
                           bi.unit_price as default_price,
                           bc.category_name
                    FROM price_list_items pli
                    JOIN billable_items bi ON pli.billable_item_id = bi.billable_item_id
                    LEFT JOIN billable_categories bc ON bi.category_id = bc.category_id
                    WHERE pli.price_list_id = ?
                    ORDER BY bi.item_name";
$added_items_stmt = $mysqli->prepare($added_items_sql);
$added_items_stmt->bind_param('i', $price_list_id);
$added_items_stmt->execute();
$added_items_result = $added_items_stmt->get_result();
$added_items = [];
while($item = $added_items_result->fetch_assoc()) {
    $added_items[] = $item;
}
$added_items_stmt->close();

// Get search parameters
$search = sanitizeInput($_GET['search'] ?? '');
$category_filter = intval($_GET['category'] ?? 0);
$type_filter = $_GET['type'] ?? '';
$status_filter = $_GET['status'] ?? '';

// Build query for billable items not in this price list
$where_conditions = ["bi.is_active = 1"];
$params = [];
$param_types = '';

// Exclude items already in this price list
$where_conditions[] = "bi.billable_item_id NOT IN (
    SELECT billable_item_id 
    FROM price_list_items 
    WHERE price_list_id = ? 
    AND (effective_to IS NULL OR effective_to >= CURDATE())
    AND effective_from <= CURDATE()
)";
$params[] = $price_list_id;
$param_types .= 'i';

if (!empty($search)) {
    $where_conditions[] = "(bi.item_name LIKE ? OR bi.item_code LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $param_types .= 'ss';
}

if ($category_filter > 0) {
    $where_conditions[] = "bi.category_id = ?";
    $params[] = $category_filter;
    $param_types .= 'i';
}

if ($type_filter) {
    $where_conditions[] = "bi.item_type = ?";
    $params[] = $type_filter;
    $param_types .= 's';
}

if ($status_filter == 'taxable') {
    $where_conditions[] = "bi.is_taxable = 1";
} elseif ($status_filter == 'nontaxable') {
    $where_conditions[] = "bi.is_taxable = 0";
}

$base_sql = "SELECT SQL_CALC_FOUND_ROWS 
                    bi.billable_item_id, 
                    bi.item_name, 
                    bi.item_code, 
                    bi.item_description,
                    bi.item_type,
                    bi.unit_price,
                    bi.cost_price,
                    bi.is_taxable,
                    bi.tax_rate,
                    bc.category_name
             FROM billable_items bi
             LEFT JOIN billable_categories bc ON bi.category_id = bc.category_id
             WHERE " . implode(" AND ", $where_conditions);
    
$order_sql = " ORDER BY bi.item_type, bi.item_name ASC LIMIT 100";

$sql = $base_sql . $order_sql;
$stmt = $mysqli->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param($param_types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

// Get categories for dropdown
$categories_sql = "SELECT category_id, category_name FROM billable_categories WHERE is_active = 1 ORDER BY category_name";
$categories_result = $mysqli->query($categories_sql);
?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-plus mr-2"></i>Add Billable Items to Price List
        </h3>
        <div class="card-tools">
            <a href="price_list_view.php?id=<?php echo $price_list_id; ?>" class="btn btn-secondary">
                <i class="fas fa-arrow-left mr-2"></i>Back to Price List
            </a>
        </div>
    </div>
    
    <div class="card-body">
        <!-- Price List Header -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="alert alert-info">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="alert-heading mb-1">
                                <i class="fas fa-tags mr-2"></i>
                                <?php echo htmlspecialchars($price_list['price_list_name']); ?>
                                <small class="text-muted">(<?php echo $price_list['price_list_code']; ?>)</small>
                            </h5>
                            <p class="mb-0">
                                <span class="badge badge-<?php 
                                    switch($price_list['price_list_type']) {
                                        case 'cash': echo 'success'; break;
                                        case 'insurance': echo 'info'; break;
                                        case 'corporate': echo 'primary'; break;
                                        case 'government': echo 'warning'; break;
                                        case 'staff': echo 'secondary'; break;
                                        default: echo 'light';
                                    }
                                ?>">
                                    <?php echo ucfirst($price_list['price_list_type']); ?>
                                </span>
                                <?php if($price_list['company_name']): ?>
                                    <span class="badge badge-light ml-2"><?php echo htmlspecialchars($price_list['company_name']); ?></span>
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="text-right">
                            <div class="h4 mb-0">
                                <?php echo count($added_items); ?> items
                            </div>
                            <small class="text-muted">Already in list</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Already Added Section -->
        <div class="card mb-4">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <h6 class="card-title mb-0">
                    <i class="fas fa-check-circle text-success mr-2"></i>
                    Already Added to Price List
                    <span class="badge badge-light ml-2"><?php echo count($added_items); ?> items</span>
                </h6>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleAddedSection()">
                    <i class="fas fa-chevron-up" id="addedToggleIcon"></i>
                </button>
            </div>
            <div class="card-body p-0" id="addedSection">
                <?php if (count($added_items) == 0): ?>
                    <div class="text-center py-4">
                        <i class="fas fa-inbox fa-2x text-muted mb-3"></i>
                        <h6 class="text-muted">No items added yet</h6>
                        <p class="text-muted small">Add items from the list below</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Code</th>
                                    <th>Type</th>
                                    <th>Category</th>
                                    <th class="text-right">Price</th>
                                    <th class="text-center">Effective Dates</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($added_items as $item): ?>
                                <tr>
                                    <td>
                                        <div class="font-weight-bold"><?php echo htmlspecialchars($item['item_name']); ?></div>
                                        <?php if($item['item_description']): ?>
                                            <small class="text-muted"><?php echo htmlspecialchars(substr($item['item_description'], 0, 50)); ?><?php if(strlen($item['item_description']) > 50): ?>...<?php endif; ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge badge-light"><?php echo htmlspecialchars($item['item_code']); ?></span>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?php 
                                            switch($item['item_type']) {
                                                case 'service': echo 'primary'; break;
                                                case 'bed': echo 'success'; break;
                                                case 'inventory': echo 'info'; break;
                                                case 'lab': echo 'warning'; break;
                                                case 'imaging': echo 'danger'; break;
                                                case 'procedure': echo 'secondary'; break;
                                                default: echo 'light';
                                            }
                                        ?>">
                                            <?php echo ucfirst($item['item_type']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if($item['category_name']): ?>
                                            <span class="badge badge-secondary"><?php echo htmlspecialchars($item['category_name']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-right">
                                        <strong class="text-primary">$<?php echo number_format($item['price'], 2); ?></strong>
                                        <?php if($item['default_price'] != $item['price']): ?>
                                            <div class="small text-muted">
                                                Default: $<?php echo number_format($item['default_price'], 2); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <div class="small">
                                            From: <?php echo date('M j, Y', strtotime($item['effective_from'])); ?>
                                        </div>
                                        <?php if($item['effective_to']): ?>
                                            <div class="small text-muted">
                                                To: <?php echo date('M j, Y', strtotime($item['effective_to'])); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm">
                                            <a href="price_list_view.php?id=<?php echo $price_list_id; ?>" 
                                               class="btn btn-outline-primary" title="View in Price List">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action_type" value="remove_item">
                                                <input type="hidden" name="price_list_item_id" value="<?php echo $item['price_list_item_id']; ?>">
                                                <button type="submit" class="btn btn-outline-danger" 
                                                        onclick="return confirm('Remove this item from the price list?')"
                                                        title="Remove from Price List">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Search and Filter Form -->
        <div class="card mb-4">
            <div class="card-header bg-light">
                <h6 class="card-title mb-0">
                    <i class="fas fa-search mr-2"></i>Search & Filter Available Billable Items
                </h6>
            </div>
            <div class="card-body">
                <form method="GET" class="form-inline">
                    <input type="hidden" name="id" value="<?php echo $price_list_id; ?>">
                    
                    <div class="form-group mr-3 mb-2">
                        <label class="mr-2">Search:</label>
                        <input type="text" class="form-control" name="search" 
                               value="<?php echo htmlspecialchars($search); ?>" 
                               placeholder="Enter name or code...">
                    </div>
                    
                    <div class="form-group mr-3 mb-2">
                        <label class="mr-2">Category:</label>
                        <select class="form-control" name="category">
                            <option value="">All Categories</option>
                            <?php 
                            $categories_result->data_seek(0); // Reset pointer
                            while($category = $categories_result->fetch_assoc()): ?>
                                <option value="<?php echo $category['category_id']; ?>" 
                                    <?php echo $category_filter == $category['category_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($category['category_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    
                    <div class="form-group mr-3 mb-2">
                        <label class="mr-2">Type:</label>
                        <select class="form-control" name="type">
                            <option value="">All Types</option>
                            <option value="service" <?php echo $type_filter == 'service' ? 'selected' : ''; ?>>Service</option>
                            <option value="bed" <?php echo $type_filter == 'bed' ? 'selected' : ''; ?>>Bed</option>
                            <option value="inventory" <?php echo $type_filter == 'inventory' ? 'selected' : ''; ?>>Inventory</option>
                            <option value="lab" <?php echo $type_filter == 'lab' ? 'selected' : ''; ?>>Lab Test</option>
                            <option value="imaging" <?php echo $type_filter == 'imaging' ? 'selected' : ''; ?>>Imaging</option>
                            <option value="procedure" <?php echo $type_filter == 'procedure' ? 'selected' : ''; ?>>Procedure</option>
                        </select>
                    </div>
                    
                    <div class="form-group mr-3 mb-2">
                        <label class="mr-2">Tax Status:</label>
                        <select class="form-control" name="status">
                            <option value="">All</option>
                            <option value="taxable" <?php echo $status_filter == 'taxable' ? 'selected' : ''; ?>>Taxable</option>
                            <option value="nontaxable" <?php echo $status_filter == 'nontaxable' ? 'selected' : ''; ?>>Non-taxable</option>
                        </select>
                    </div>
                    
                    <div class="form-group mb-2">
                        <button type="submit" class="btn btn-primary mr-2">
                            <i class="fas fa-search mr-2"></i>Search
                        </button>
                        <a href="price_add_items.php?id=<?php echo $price_list_id; ?>" 
                           class="btn btn-secondary">
                            <i class="fas fa-times mr-2"></i>Clear
                        </a>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Add Items Form -->
        <form method="POST" action="price_add_items.php">
            <input type="hidden" name="action_type" value="add_items">
            <input type="hidden" name="price_list_id" value="<?php echo $price_list_id; ?>">
            
            <div class="card mb-3">
                <div class="card-header bg-light">
                    <h6 class="card-title mb-0">
                        <i class="fas fa-list mr-2"></i>Add Items Configuration
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Price to Use</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text">$</span>
                                    </div>
                                    <input type="number" class="form-control" name="price" id="priceInput" step="0.01" min="0">
                                </div>
                                <small class="form-text text-muted">Leave empty to use default item price</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Effective From</label>
                                <input type="date" class="form-control" name="effective_from" value="<?php echo date('Y-m-d'); ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Effective To (Optional)</label>
                                <input type="date" class="form-control" name="effective_to">
                                <small class="form-text text-muted">Leave empty for indefinite validity</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                    <h6 class="card-title mb-0">
                        <i class="fas fa-list mr-2"></i>
                        Available Billable Items to Add
                        <span class="badge badge-light ml-2"><?php echo $result->num_rows; ?> found</span>
                    </h6>
                    <div>
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAll()">
                            <i class="fas fa-check-square mr-1"></i>Select All
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary ml-1" onclick="deselectAll()">
                            <i class="fas fa-times-circle mr-1"></i>Deselect None
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-info ml-1" onclick="toggleSelection()">
                            <i class="fas fa-exchange-alt mr-1"></i>Toggle
                        </button>
                    </div>
                </div>
                
                <div class="card-body p-0">
                    <?php if ($result->num_rows == 0): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-box-open fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">No billable items found</h5>
                            <p class="text-muted">
                                <?php if ($search || $category_filter || $type_filter || $status_filter): ?>
                                    Try adjusting your search criteria or
                                    <a href="price_add_items.php?id=<?php echo $price_list_id; ?>" 
                                       class="text-primary">clear filters</a>
                                <?php else: ?>
                                    All billable items have been added to this price list.
                                <?php endif; ?>
                            </p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th width="50">
                                            <input type="checkbox" id="selectAllCheckbox" onclick="toggleSelectAll()">
                                        </th>
                                        <th>Item Details</th>
                                        <th>Type & Category</th>
                                        <th class="text-right">Default Price</th>
                                        <th class="text-right">Cost Price</th>
                                        <th class="text-center">Tax</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while($row = $result->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" name="billable_item_ids[]" 
                                                   value="<?php echo $row['billable_item_id']; ?>" 
                                                   class="item-checkbox"
                                                   data-price="<?php echo $row['unit_price']; ?>"
                                                   data-name="<?php echo htmlspecialchars($row['item_name']); ?>">
                                        </td>
                                        <td>
                                            <div class="font-weight-bold">
                                                <?php echo htmlspecialchars($row['item_name']); ?>
                                            </div>
                                            <small class="text-muted">
                                                Code: <?php echo htmlspecialchars($row['item_code']); ?>
                                                <?php if($row['item_description']): ?>
                                                    <br><?php echo htmlspecialchars(substr($row['item_description'], 0, 100)); ?>
                                                    <?php if(strlen($row['item_description']) > 100): ?>...<?php endif; ?>
                                                <?php endif; ?>
                                            </small>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?php 
                                                switch($row['item_type']) {
                                                    case 'service': echo 'primary'; break;
                                                    case 'bed': echo 'success'; break;
                                                    case 'inventory': echo 'info'; break;
                                                    case 'lab': echo 'warning'; break;
                                                    case 'imaging': echo 'danger'; break;
                                                    case 'procedure': echo 'secondary'; break;
                                                    default: echo 'light';
                                                }
                                            ?>">
                                                <?php echo ucfirst($row['item_type']); ?>
                                            </span>
                                            <?php if($row['category_name']): ?>
                                                <div class="mt-1">
                                                    <small class="text-muted">
                                                        <i class="fas fa-folder mr-1"></i><?php echo htmlspecialchars($row['category_name']); ?>
                                                    </small>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-right">
                                            <strong class="text-primary">$<?php echo number_format($row['unit_price'], 2); ?></strong>
                                        </td>
                                        <td class="text-right">
                                            <?php if($row['cost_price'] > 0): ?>
                                                <span class="text-muted">$<?php echo number_format($row['cost_price'], 2); ?></span>
                                                <div class="small">
                                                    Margin: $<?php echo number_format($row['unit_price'] - $row['cost_price'], 2); ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if($row['is_taxable'] && $row['tax_rate'] > 0): ?>
                                                <span class="badge badge-danger"><?php echo $row['tax_rate']; ?>%</span>
                                            <?php else: ?>
                                                <span class="badge badge-success">Non-taxable</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                
                <?php if ($result->num_rows > 0): ?>
                <div class="card-footer">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <span id="selectedCount" class="text-muted">0 items selected</span>
                            <?php if($result->num_rows > 0): ?>
                                <span class="ml-3 text-muted" id="selectedTotalPrice"></span>
                            <?php endif; ?>
                        </div>
                        <div>
                            <a href="price_list_view.php?id=<?php echo $price_list_id; ?>" class="btn btn-secondary mr-2">
                                <i class="fas fa-times mr-2"></i>Cancel
                            </a>
                            <button type="submit" class="btn btn-primary" id="addButton" disabled>
                                <i class="fas fa-plus mr-2"></i>
                                Add Selected Items
                            </button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </form>
        
        <!-- Information Section -->
        <div class="card mt-4">
            <div class="card-header bg-light">
                <h6 class="card-title mb-0">
                    <i class="fas fa-info-circle mr-2"></i>Information
                </h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <h6><i class="fas fa-check-circle text-success mr-2"></i>Already Added</h6>
                        <p class="small mb-0">Shows all billable items already in this price list. You can view details or remove them.</p>
                    </div>
                    <div class="col-md-4">
                        <h6><i class="fas fa-list-check text-primary mr-2"></i>Available to Add</h6>
                        <p class="small mb-0">Shows billable items not yet in this price list. Select multiple and add them at once.</p>
                    </div>
                    <div class="col-md-4">
                        <h6><i class="fas fa-cog text-info mr-2"></i>Configuration</h6>
                        <p class="small mb-0">Set a custom price for all selected items or use their default prices. Configure effective dates.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Initialize when page loads
document.addEventListener('DOMContentLoaded', function() {
    updateSelectedCount();
    
    // Update price input with average of selected items
    document.querySelectorAll('.item-checkbox').forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            updateSelectedCount();
            updatePriceInput();
        });
    });
});

// Toggle the added section visibility
let addedSectionVisible = true;
function toggleAddedSection() {
    const addedSection = document.getElementById('addedSection');
    const toggleIcon = document.getElementById('addedToggleIcon');
    
    if (addedSectionVisible) {
        addedSection.style.display = 'none';
        toggleIcon.className = 'fas fa-chevron-down';
        addedSectionVisible = false;
    } else {
        addedSection.style.display = 'block';
        toggleIcon.className = 'fas fa-chevron-up';
        addedSectionVisible = true;
    }
}

// Checkbox selection functions
function toggleSelectAll() {
    const masterCheckbox = document.getElementById('selectAllCheckbox');
    const checkboxes = document.querySelectorAll('.item-checkbox');
    
    checkboxes.forEach(checkbox => {
        checkbox.checked = masterCheckbox.checked;
    });
    
    updateSelectedCount();
    updatePriceInput();
}

function selectAll() {
    const checkboxes = document.querySelectorAll('.item-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = true;
    });
    document.getElementById('selectAllCheckbox').checked = true;
    updateSelectedCount();
    updatePriceInput();
}

function deselectAll() {
    const checkboxes = document.querySelectorAll('.item-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = false;
    });
    document.getElementById('selectAllCheckbox').checked = false;
    updateSelectedCount();
    updatePriceInput();
}

function toggleSelection() {
    const checkboxes = document.querySelectorAll('.item-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = !checkbox.checked;
    });
    updateSelectedCount();
    updatePriceInput();
}

function updateSelectedCount() {
    const checkboxes = document.querySelectorAll('.item-checkbox');
    let selectedCount = 0;
    let totalPrice = 0;
    
    checkboxes.forEach(checkbox => {
        if (checkbox.checked) {
            selectedCount++;
            totalPrice += parseFloat(checkbox.dataset.price) || 0;
        }
    });
    
    const countElement = document.getElementById('selectedCount');
    const totalPriceElement = document.getElementById('selectedTotalPrice');
    const addButton = document.getElementById('addButton');
    
    if (countElement) {
        countElement.textContent = selectedCount + ' ' + 
            (selectedCount === 1 ? 'item' : 'items') + ' selected';
    }
    
    if (totalPriceElement && selectedCount > 0) {
        const avgPrice = totalPrice / selectedCount;
        totalPriceElement.textContent = 'Avg price: $' + avgPrice.toFixed(2);
    } else if (totalPriceElement) {
        totalPriceElement.textContent = '';
    }
    
    if (addButton) {
        addButton.disabled = selectedCount === 0;
        if (selectedCount > 0) {
            addButton.innerHTML = `<i class="fas fa-plus mr-2"></i>Add ${selectedCount} Selected ${selectedCount === 1 ? 'Item' : 'Items'}`;
        } else {
            addButton.innerHTML = `<i class="fas fa-plus mr-2"></i>Add Selected Items`;
        }
    }
    
    // Update master checkbox state
    const masterCheckbox = document.getElementById('selectAllCheckbox');
    if (masterCheckbox) {
        masterCheckbox.checked = selectedCount === checkboxes.length && checkboxes.length > 0;
        masterCheckbox.indeterminate = selectedCount > 0 && selectedCount < checkboxes.length;
    }
}

function updatePriceInput() {
    const checkboxes = document.querySelectorAll('.item-checkbox:checked');
    if (checkboxes.length > 0) {
        let totalPrice = 0;
        checkboxes.forEach(checkbox => {
            totalPrice += parseFloat(checkbox.dataset.price) || 0;
        });
        const avgPrice = totalPrice / checkboxes.length;
        document.getElementById('priceInput').placeholder = 'Avg: $' + avgPrice.toFixed(2);
    } else {
        document.getElementById('priceInput').placeholder = '';
    }
}

// Update count when checkboxes change
document.querySelectorAll('.item-checkbox').forEach(checkbox => {
    checkbox.addEventListener('change', function() {
        updateSelectedCount();
        updatePriceInput();
    });
});

// Confirm before submitting bulk add
document.querySelector('form').addEventListener('submit', function(e) {
    const checkboxes = document.querySelectorAll('.item-checkbox:checked');
    if (checkboxes.length === 0) {
        e.preventDefault();
        alert('Please select at least one item to add.');
        return;
    }
    
    const priceInput = document.getElementById('priceInput').value;
    const effectiveFrom = document.querySelector('input[name="effective_from"]').value;
    const effectiveTo = document.querySelector('input[name="effective_to"]').value;
    
    let confirmMessage = `Add ${checkboxes.length} item(s) to the price list?\n\n`;
    
    if (priceInput) {
        confirmMessage += `Price: $${parseFloat(priceInput).toFixed(2)} (applied to all items)\n`;
    } else {
        confirmMessage += 'Price: Using default item prices\n';
    }
    
    confirmMessage += `Effective From: ${effectiveFrom}\n`;
    if (effectiveTo) {
        confirmMessage += `Effective To: ${effectiveTo}\n`;
    } else {
        confirmMessage += 'Effective To: Indefinite\n';
    }
    
    if (!confirm(confirmMessage)) {
        e.preventDefault();
    }
});
</script>

<style>
.card {
    border: 1px solid #e3e6f0;
    border-radius: 0.35rem;
    box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
}

.card-header {
    border-bottom: 1px solid #e3e6f0;
    padding: 0.75rem 1.25rem;
}

.card-header.bg-dark {
    background-color: #4e73df !important;
}

.table th {
    border-top: none;
    font-weight: 600;
    background-color: #f8f9fc;
}

.table-hover tbody tr:hover {
    background-color: rgba(0, 123, 255, 0.05);
}

.badge {
    font-weight: 500;
    padding: 0.35em 0.65em;
}

.btn {
    font-weight: 500;
}

.form-control:focus {
    border-color: #4e73df;
    box-shadow: 0 0 0 0.2rem rgba(78, 115, 223, 0.25);
}

.alert {
    border: none;
    border-left: 4px solid #4e73df;
}

.alert-info {
    background-color: #f0f7ff;
    border-color: #4e73df;
}

input[type="checkbox"] {
    transform: scale(1.2);
}

input[type="checkbox"]:checked {
    background-color: #4e73df;
    border-color: #4e73df;
}

.text-primary {
    color: #4e73df !important;
}

.btn-primary {
    background-color: #4e73df;
    border-color: #4e73df;
}

.btn-primary:hover {
    background-color: #2e59d9;
    border-color: #2653d4;
}

.btn-success {
    background-color: #1cc88a;
    border-color: #1cc88a;
}

.btn-success:hover {
    background-color: #17a673;
    border-color: #169b6b;
}

.btn-secondary {
    background-color: #858796;
    border-color: #858796;
}

.btn-secondary:hover {
    background-color: #717384;
    border-color: #6b6d7d;
}

.btn-info {
    background-color: #36b9cc;
    border-color: #36b9cc;
}

.btn-info:hover {
    background-color: #2c9faf;
    border-color: #2a96a5;
}

.badge-success {
    background-color: #1cc88a;
}

.badge-info {
    background-color: #36b9cc;
}

.badge-danger {
    background-color: #e74a3b;
}

.badge-warning {
    background-color: #f6c23e;
    color: #212529;
}

.badge-light {
    background-color: #f8f9fc;
    color: #3a3b45;
}

.table-sm td, .table-sm th {
    padding: 0.5rem;
}
</style>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
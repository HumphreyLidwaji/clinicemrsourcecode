<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/price_functions.php';

// Get price list ID from GET or POST
$price_list_id = intval($_GET['id'] ?? $_POST['price_list_id'] ?? $_POST['id'] ?? 0);
$action = $_GET['action'] ?? '';

// Debug: Log the price list ID
error_log("DEBUG: Price List ID from request: " . $price_list_id);
error_log("DEBUG: GET: " . print_r($_GET, true));
error_log("DEBUG: POST: " . print_r($_POST, true));

// Get price list details
if ($price_list_id > 0) {
    $sql = "SELECT pl.*, ic.company_name, ic.company_code
            FROM price_lists pl
            LEFT JOIN insurance_companies ic ON pl.insurance_provider_id = ic.insurance_company_id
            WHERE pl.price_list_id = ?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('i', $price_list_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $price_list = $result->fetch_assoc();
    $stmt->close();
} else {
    $price_list = null;
}

if (!$price_list) {
    $_SESSION['alert_message'] = "Price list not found (ID: $price_list_id)";
    error_log("ERROR: Price list not found for ID: $price_list_id");
    header("Location: price_management.php");
    exit;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action_type = $_POST['action_type'] ?? '';
    
    // Make sure we have the price_list_id
    if (isset($_POST['price_list_id'])) {
        $price_list_id = intval($_POST['price_list_id']);
    } elseif (isset($_POST['id'])) {
        $price_list_id = intval($_POST['id']);
    }
    
    error_log("DEBUG: POST Action: $action_type, Price List ID: $price_list_id");
    
    if ($action_type == 'add_items') {
        $item_ids = $_POST['item_ids'] ?? [];
        $added_count = 0;
        $error_count = 0;
        
        error_log("DEBUG: Adding items: " . print_r($item_ids, true));
        
        // First, verify the price list exists
        $verify_sql = "SELECT price_list_id FROM price_lists WHERE price_list_id = ?";
        $verify_stmt = $mysqli->prepare($verify_sql);
        $verify_stmt->bind_param('i', $price_list_id);
        $verify_stmt->execute();
        $verify_result = $verify_stmt->get_result();
        $verify = $verify_result->fetch_assoc();
        $verify_stmt->close();
        
        if (!$verify) {
            $_SESSION['alert_message'] = "Error: Price list not found (ID: $price_list_id)";
            header("Location: price_management.php");
            exit;
        }
        
        foreach ($item_ids as $item_id) {
            $item_id = intval($item_id);
            
            // Skip if item_id is 0
            if ($item_id <= 0) {
                error_log("DEBUG: Skipping invalid item ID: $item_id");
                continue;
            }
            
            // Get item details from billable_items
            $item_sql = "SELECT unit_price FROM billable_items WHERE billable_item_id = ?";
            $item_stmt = $mysqli->prepare($item_sql);
            $item_stmt->bind_param('i', $item_id);
            $item_stmt->execute();
            $item_result = $item_stmt->get_result();
            $item = $item_result->fetch_assoc();
            $item_stmt->close();
            
            if (!$item) {
                error_log("ERROR: Item not found in billable_items: $item_id");
                $error_count++;
                continue;
            }
            
            // Check if item already exists in price list
            $check_sql = "SELECT COUNT(*) as count FROM price_list_items 
                         WHERE price_list_id = ? AND billable_item_id = ?";
            $check_stmt = $mysqli->prepare($check_sql);
            $check_stmt->bind_param('ii', $price_list_id, $item_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            $check = $check_result->fetch_assoc();
            $check_stmt->close();
            
            if ($check['count'] > 0) {
                error_log("INFO: Item $item_id already exists in price list $price_list_id");
                continue;
            }
            
            // Insert item into price list - include all required fields
            $insert_sql = "INSERT INTO price_list_items 
                           (price_list_id, billable_item_id, price, covered_percentage, 
                            effective_from, is_taxable, discount_allowed, created_by, created_at)
                           VALUES (?, ?, ?, 100, CURDATE(), 0, 0, ?, NOW())";
            $insert_stmt = $mysqli->prepare($insert_sql);
            $user_id = $_SESSION['user_id'] ?? 0;
            $insert_stmt->bind_param('iidii', $price_list_id, $item_id, $item['unit_price'], $user_id);
            
            if ($insert_stmt->execute()) {
                $added_count++;
                
                // Get the last inserted ID
                $last_insert_id = $insert_stmt->insert_id;
                
                // Log to history
                $history_sql = "INSERT INTO price_history 
                               (price_list_id, price_list_item_id, billable_item_id,
                                old_price, new_price, changed_by, reason)
                               VALUES (?, ?, ?, 0, ?, ?, 'Item added to price list')";
                $history_stmt = $mysqli->prepare($history_sql);
                $history_stmt->bind_param('iiidi', $price_list_id, $last_insert_id, $item_id, $item['unit_price'], $user_id);
                if (!$history_stmt->execute()) {
                    error_log("ERROR: Failed to log history for item $item_id: " . $history_stmt->error);
                }
                $history_stmt->close();
                
                error_log("SUCCESS: Added item $item_id to price list $price_list_id (Price: {$item['unit_price']})");
            } else {
                error_log("ERROR: Failed to insert item $item_id: " . $insert_stmt->error);
                $error_count++;
            }
            $insert_stmt->close();
        }
        
        if ($added_count > 0) {
            $_SESSION['alert_message'] = "Successfully added $added_count items to price list";
            if ($error_count > 0) {
                $_SESSION['alert_message'] .= " (failed to add $error_count items)";
            }
        } else {
            $_SESSION['alert_message'] = "No items were added. They might already be in the price list.";
            if ($error_count > 0) {
                $_SESSION['alert_message'] = "Failed to add items. Please check the logs.";
            }
        }
        
        header("Location: price_add_items.php?id=$price_list_id");
        exit;
    }
    
    if ($action_type == 'remove_item') {
        $item_id = intval($_POST['item_id']);
        $price_list_item_id = intval($_POST['price_list_item_id']);
        
        // Get item price before removal for history
        $get_sql = "SELECT price FROM price_list_items WHERE price_list_item_id = ?";
        $get_stmt = $mysqli->prepare($get_sql);
        $get_stmt->bind_param('i', $price_list_item_id);
        $get_stmt->execute();
        $get_result = $get_stmt->get_result();
        $get_row = $get_result->fetch_assoc();
        $item_price = $get_row ? $get_row['price'] : 0;
        $get_stmt->close();
        
        // Remove item from price list
        $delete_sql = "DELETE FROM price_list_items WHERE price_list_item_id = ?";
        $delete_stmt = $mysqli->prepare($delete_sql);
        $delete_stmt->bind_param('i', $price_list_item_id);
        
        if ($delete_stmt->execute()) {
            // Log to history
            $history_sql = "INSERT INTO price_history 
                           (price_list_id, price_list_item_id, billable_item_id,
                            old_price, new_price, changed_by, reason)
                           VALUES (?, ?, ?, ?, 0, ?, 'Item removed from price list')";
            $history_stmt = $mysqli->prepare($history_sql);
            $user_id = $_SESSION['user_id'] ?? 0;
            $history_stmt->bind_param('iiidi', $price_list_id, $price_list_item_id, $item_id, $item_price, $user_id);
            $history_stmt->execute();
            $history_stmt->close();
            
            $_SESSION['alert_message'] = "Item removed from price list";
        }
        $delete_stmt->close();
        
        header("Location: price_add_items.php?id=$price_list_id");
        exit;
    }
}

// Get items already added to this price list
$added_items_sql = "SELECT pli.*, bi.item_name, bi.item_code, bi.item_description,
                           bi.item_unit_measure, bi.item_status, bi.item_type,
                           bc.category_name as item_category
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
$category_id = intval($_GET['category'] ?? 0);
$status_filter = $_GET['status'] ?? '';
$type_filter = $_GET['type'] ?? '';

// Build query - exclude items already in this price list
$where_conditions = ["bi.is_active = 1"]; // Only show active billable items
$params = [];
$param_types = '';

// Exclude items already in this price list
$where_conditions[] = "bi.billable_item_id NOT IN (
    SELECT billable_item_id FROM price_list_items WHERE price_list_id = ?
)";
$params[] = $price_list_id;
$param_types .= 'i';

if (!empty($search)) {
    $where_conditions[] = "(bi.item_name LIKE ? OR bi.item_code LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $param_types .= 'ss';
}

if ($category_id > 0) {
    $where_conditions[] = "bi.category_id = ?";
    $params[] = $category_id;
    $param_types .= 'i';
}

if (!empty($type_filter)) {
    $where_conditions[] = "bi.item_type = ?";
    $params[] = $type_filter;
    $param_types .= 's';
}

if ($status_filter == 'active') {
    $where_conditions[] = "bi.item_status = 'active'";
} elseif ($status_filter == 'inactive') {
    $where_conditions[] = "bi.item_status = 'inactive'";
}

$base_sql = "SELECT SQL_CALC_FOUND_ROWS 
                    bi.billable_item_id, 
                    bi.item_name, 
                    bi.item_code, 
                    bi.item_description,
                    bi.unit_price,
                    bi.cost_price, 
                    bi.item_unit_measure,
                    bi.item_type,
                    bi.item_status,
                    bi.source_table,
                    bi.source_id,
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
$categories_sql = "SELECT category_id, category_name 
                  FROM billable_categories 
                  WHERE is_active = 1 
                  ORDER BY category_name";
$categories_result = $mysqli->query($categories_sql);
?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-plus mr-2"></i>
            Add Items to Price List
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
                            </h5>
                            <p class="mb-0">
                                <span class="badge badge-<?php echo $price_list['price_list_type'] == 'cash' ? 'success' : 'info'; ?>">
                                    <?php echo strtoupper($price_list['price_list_type']); ?>
                                </span>
                                <?php if($price_list['company_name']): ?>
                                    <span class="badge badge-light ml-2"><?php echo htmlspecialchars($price_list['company_name']); ?></span>
                                <?php endif; ?>
                                <?php if($price_list['is_default']): ?>
                                    <span class="badge badge-warning ml-2">DEFAULT</span>
                                <?php endif; ?>
                                <?php if($price_list['is_active']): ?>
                                    <span class="badge badge-success ml-2">ACTIVE</span>
                                <?php else: ?>
                                    <span class="badge badge-danger ml-2">INACTIVE</span>
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
                    <span class="badge badge-light ml-2">
                        <?php echo count($added_items); ?> items
                    </span>
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
                                    <th class="text-center">Coverage</th>
                                    <th class="text-center">Status</th>
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
                                            echo getItemTypeBadgeColor($item['item_type']);
                                        ?>">
                                            <?php echo ucfirst($item['item_type']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if($item['item_category']): ?>
                                            <span class="badge badge-secondary"><?php echo htmlspecialchars($item['item_category']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-right">
                                        <strong class="text-primary">$<?php echo number_format($item['price'], 4); ?></strong>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge badge-<?php 
                                            echo $item['covered_percentage'] == 100 ? 'success' : 
                                                ($item['covered_percentage'] >= 80 ? 'info' : 
                                                ($item['covered_percentage'] >= 50 ? 'warning' : 'danger')); 
                                        ?>">
                                            <?php echo number_format($item['covered_percentage'], 0); ?>%
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge badge-<?php echo $item['item_status'] == 'active' ? 'success' : 'danger'; ?>">
                                            <?php echo ucfirst($item['item_status']); ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm">
                                            <a href="price_list_view.php?id=<?php echo $price_list_id; ?>" 
                                               class="btn btn-outline-primary" title="View in Price List">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action_type" value="remove_item">
                                                <input type="hidden" name="item_id" value="<?php echo $item['billable_item_id']; ?>">
                                                <input type="hidden" name="price_list_item_id" value="<?php echo $item['price_list_item_id']; ?>">
                                                <input type="hidden" name="price_list_id" value="<?php echo $price_list_id; ?>">
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
                    <i class="fas fa-search mr-2"></i>Search & Filter Available Items
                </h6>
            </div>
            <div class="card-body">
                <form method="GET" class="form-inline">
                    <input type="hidden" name="id" value="<?php echo $price_list_id; ?>">
                    
                    <div class="form-group mr-3 mb-2">
                        <label class="mr-2">Search:</label>
                        <input type="text" class="form-control" name="search" 
                               value="<?php echo htmlspecialchars($search); ?>" 
                               placeholder="Enter item name or code...">
                    </div>
                    
                    <div class="form-group mr-3 mb-2">
                        <label class="mr-2">Category:</label>
                        <select class="form-control" name="category">
                            <option value="">All Categories</option>
                            <?php 
                            $categories_result->data_seek(0); // Reset pointer
                            while($category = $categories_result->fetch_assoc()): ?>
                                <option value="<?php echo $category['category_id']; ?>" 
                                    <?php echo $category_id == $category['category_id'] ? 'selected' : ''; ?>>
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
                            <option value="lab" <?php echo $type_filter == 'lab' ? 'selected' : ''; ?>>Lab</option>
                            <option value="imaging" <?php echo $type_filter == 'imaging' ? 'selected' : ''; ?>>Imaging</option>
                            <option value="procedure" <?php echo $type_filter == 'procedure' ? 'selected' : ''; ?>>Procedure</option>
                        </select>
                    </div>
                    
                    <div class="form-group mr-3 mb-2">
                        <label class="mr-2">Status:</label>
                        <select class="form-control" name="status">
                            <option value="">All Status</option>
                            <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $status_filter == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
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
            <input type="hidden" name="id" value="<?php echo $price_list_id; ?>">
            
            <div class="card">
                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                    <h6 class="card-title mb-0">
                        <i class="fas fa-list mr-2"></i>
                        Available Items to Add
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
                            <h5 class="text-muted">No items found</h5>
                            <p class="text-muted">
                                <?php if ($search || $category_id || $status_filter || $type_filter): ?>
                                    Try adjusting your search criteria or
                                    <a href="price_add_items.php?id=<?php echo $price_list_id; ?>" 
                                       class="text-primary">clear filters</a>
                                <?php else: ?>
                                    All items have been added to this price list.
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
                                        <th>Type</th>
                                        <th>Category</th>
                                        <th class="text-right">Unit Price</th>
                                        <th class="text-center">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while($row = $result->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" name="item_ids[]" 
                                                   value="<?php echo $row['billable_item_id']; ?>" 
                                                   class="item-checkbox"
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
                                                <?php if($row['source_table'] && $row['source_id']): ?>
                                                    <br><small>Source: <?php echo $row['source_table']; ?> #<?php echo $row['source_id']; ?></small>
                                                <?php endif; ?>
                                            </small>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?php echo getItemTypeBadgeColor($row['item_type']); ?>">
                                                <?php echo ucfirst($row['item_type']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if($row['category_name']): ?>
                                                <span class="badge badge-light">
                                                    <?php echo htmlspecialchars($row['category_name']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-right">
                                            <strong class="text-primary">$<?php echo number_format($row['unit_price'], 4); ?></strong>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge badge-<?php echo $row['item_status'] == 'active' ? 'success' : 'danger'; ?>">
                                                <?php echo ucfirst($row['item_status']); ?>
                                            </span>
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
                            <div id="selectedItemsList" class="small text-muted mt-1" style="display: none;">
                                <strong>Selected items:</strong> <span id="selectedItemsNames"></span>
                            </div>
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
                    <div class="col-md-6">
                        <h6><i class="fas fa-check-circle text-success mr-2"></i>Already Added</h6>
                        <p class="small mb-0">Shows all items already in this price list. You can view details or remove them.</p>
                    </div>
                    <div class="col-md-6">
                        <h6><i class="fas fa-list-check text-primary mr-2"></i>Available to Add</h6>
                        <p class="small mb-0">Shows items not yet in this price list. Select multiple and add them at once.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
// Helper function to get badge color for item type
function getItemTypeBadgeColor($type) {
    switch($type) {
        case 'service': return 'primary';
        case 'bed': return 'info';
        case 'inventory': return 'success';
        case 'lab': return 'warning';
        case 'imaging': return 'purple';
        case 'procedure': return 'danger';
        default: return 'secondary';
    }
}
?>

<script>
// Initialize when page loads
document.addEventListener('DOMContentLoaded', function() {
    updateSelectedCount();
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
}

function selectAll() {
    const checkboxes = document.querySelectorAll('.item-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = true;
    });
    document.getElementById('selectAllCheckbox').checked = true;
    updateSelectedCount();
}

function deselectAll() {
    const checkboxes = document.querySelectorAll('.item-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = false;
    });
    document.getElementById('selectAllCheckbox').checked = false;
    updateSelectedCount();
}

function toggleSelection() {
    const checkboxes = document.querySelectorAll('.item-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = !checkbox.checked;
    });
    updateSelectedCount();
}

function updateSelectedCount() {
    const checkboxes = document.querySelectorAll('.item-checkbox');
    let selectedCount = 0;
    let selectedNames = [];
    
    checkboxes.forEach(checkbox => {
        if (checkbox.checked) {
            selectedCount++;
            selectedNames.push(checkbox.getAttribute('data-name'));
        }
    });
    
    const countElement = document.getElementById('selectedCount');
    const addButton = document.getElementById('addButton');
    const selectedItemsList = document.getElementById('selectedItemsList');
    const selectedItemsNames = document.getElementById('selectedItemsNames');
    
    if (countElement) {
        countElement.textContent = selectedCount + ' ' + 
            (selectedCount === 1 ? 'item' : 'items') + ' selected';
    }
    
    if (addButton) {
        addButton.disabled = selectedCount === 0;
        if (selectedCount > 0) {
            addButton.innerHTML = `<i class="fas fa-plus mr-2"></i>Add ${selectedCount} Selected ${selectedCount === 1 ? 'Item' : 'Items'}`;
        } else {
            addButton.innerHTML = `<i class="fas fa-plus mr-2"></i>Add Selected Items`;
        }
    }
    
    // Show selected items list
    if (selectedItemsList && selectedItemsNames) {
        if (selectedCount > 0) {
            selectedItemsList.style.display = 'block';
            // Show first 3 items, then "and X more"
            if (selectedNames.length <= 3) {
                selectedItemsNames.textContent = selectedNames.join(', ');
            } else {
                selectedItemsNames.textContent = selectedNames.slice(0, 3).join(', ') + ', and ' + (selectedNames.length - 3) + ' more';
            }
        } else {
            selectedItemsList.style.display = 'none';
            selectedItemsNames.textContent = '';
        }
    }
    
    // Update master checkbox state
    const masterCheckbox = document.getElementById('selectAllCheckbox');
    if (masterCheckbox) {
        masterCheckbox.checked = selectedCount === checkboxes.length && checkboxes.length > 0;
        masterCheckbox.indeterminate = selectedCount > 0 && selectedCount < checkboxes.length;
    }
}

// Update count when checkboxes change
document.addEventListener('DOMContentLoaded', function() {
    const checkboxes = document.querySelectorAll('.item-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.addEventListener('change', updateSelectedCount);
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
    
    let selectedNames = [];
    checkboxes.forEach(checkbox => {
        selectedNames.push(checkbox.getAttribute('data-name'));
    });
    
    const confirmMessage = `Add ${checkboxes.length} item(s) to the price list?\n\n` +
                          'Selected items: ' + selectedNames.join(', ') + '\n\n' +
                          'Items will be added with their default prices and 100% coverage.';
    
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

.badge-primary {
    background-color: #4e73df;
}

.badge-purple {
    background-color: #6f42c1;
    color: white;
}

.table-sm td, .table-sm th {
    padding: 0.5rem;
}
</style>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
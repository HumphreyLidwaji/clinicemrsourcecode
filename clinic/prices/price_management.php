<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Default Column Sortby/Order Filter
$sort = "pl.created_at";
$order = "DESC";

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Include price functions (will need to be updated to use new tables)
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/price_functions.php';

// Action parameter
$action = $_GET['action'] ?? '';
$price_list_id = intval($_GET['id'] ?? 0);

// Handle price management actions
if ($action == 'quick_price_check' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $entity_type = $_POST['entity_type']; // Now corresponds to billable_items.item_type
    $entity_id = intval($_POST['entity_id']); // Now corresponds to billable_items.source_id
    $quantity = intval($_POST['quantity'] ?? 1);
    $payer_type = $_POST['payer_type'];
    $price_list_id = intval($_POST['price_list_id'] ?? 0);
    
    $price_result = calculatePrice($mysqli, $entity_type, $entity_id, $quantity, $payer_type, $price_list_id);
    
    echo json_encode($price_result);
    exit;
}

if ($action == 'clone_price_list' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $source_id = intval($_POST['source_price_list_id']);
    $new_name = $_POST['new_list_name'];
    $cloned_by = intval($_SESSION['user_id']);
    
    $new_list_id = clonePriceList($mysqli, $source_id, $new_name, $cloned_by);
    
    if ($new_list_id) {
        echo json_encode([
            'success' => true,
            'new_list_id' => $new_list_id,
            'message' => 'Price list cloned successfully'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to clone price list'
        ]);
    }
    exit;
}

if ($action == 'toggle_default' && $price_list_id) {
    // Toggle default status
    $sql = "SELECT price_list_type FROM price_lists WHERE price_list_id = ?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('i', $price_list_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $price_list = $result->fetch_assoc();
    
    // Remove default from others of same type
    $sql = "UPDATE price_lists SET is_default = 0 WHERE price_list_type = ? AND price_list_id != ?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('si', $price_list['price_list_type'], $price_list_id);
    $stmt->execute();
    
    // Set this as default
    $sql = "UPDATE price_lists SET is_default = 1 WHERE price_list_id = ?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('i', $price_list_id);
    $stmt->execute();
    
    $_SESSION['alert_message'] = "Default price list updated";
    header("Location: price_management.php");
    exit;
}

if ($action == 'toggle_status' && $price_list_id) {
    // Toggle active status
    $sql = "UPDATE price_lists SET is_active = NOT is_active WHERE price_list_id = ?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('i', $price_list_id);
    $stmt->execute();
    
    $_SESSION['alert_message'] = "Price list status updated";
    header("Location: price_management.php");
    exit;
}

if ($action == 'delete' && $price_list_id) {
    // Check if price list has items
    $check_sql = "SELECT COUNT(*) as item_count FROM price_list_items WHERE price_list_id = ?";
    $stmt = $mysqli->prepare($check_sql);
    $stmt->bind_param('i', $price_list_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    
    if ($row['item_count'] == 0) {
        // Delete price list if no items
        $sql = "DELETE FROM price_lists WHERE price_list_id = ?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param('i', $price_list_id);
        $stmt->execute();
        $_SESSION['alert_message'] = "Price list deleted";
    } else {
        // Soft delete if has items
        $sql = "UPDATE price_lists SET is_active = 0 WHERE price_list_id = ?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param('i', $price_list_id);
        $stmt->execute();
        $_SESSION['alert_message'] = "Price list deactivated (contains items)";
    }
    
    header("Location: price_management.php");
    exit;
}

// Filter parameters
$status_filter = $_GET['status'] ?? '';
$type_filter = $_GET['type'] ?? '';
$company_filter = $_GET['company'] ?? '';
$q = sanitizeInput($_GET['q'] ?? '');

// Build search query
$where_conditions = ["1=1"];
$params = [];
$param_types = '';

if ($status_filter == 'active') {
    $where_conditions[] = "pl.is_active = 1";
} elseif ($status_filter == 'inactive') {
    $where_conditions[] = "pl.is_active = 0";
}

if ($type_filter) {
    $where_conditions[] = "pl.price_list_type = ?";
    $params[] = $type_filter;
    $param_types .= 's';
}

if ($company_filter) {
    $where_conditions[] = "pl.insurance_provider_id = ?";
    $params[] = $company_filter;
    $param_types .= 'i';
}

if (!empty($q)) {
    $where_conditions[] = "(pl.price_list_name LIKE ? OR pl.price_list_code LIKE ? OR ic.company_name LIKE ?)";
    $params[] = "%$q%";
    $params[] = "%$q%";
    $params[] = "%$q%";
    $param_types .= 'sss';
}

$where_clause = implode(" AND ", $where_conditions);

// Get Price Management Statistics - UPDATED FOR NEW TABLES
$price_stats_sql = "SELECT 
    COUNT(DISTINCT pl.price_list_id) as total_price_lists,
    SUM(CASE WHEN pl.price_list_type = 'cash' THEN 1 ELSE 0 END) as cash_price_lists,
    SUM(CASE WHEN pl.price_list_type = 'insurance' THEN 1 ELSE 0 END) as insurance_price_lists,
    SUM(CASE WHEN pl.is_active = 1 THEN 1 ELSE 0 END) as active_price_lists,
    SUM(CASE WHEN pl.is_default = 1 THEN 1 ELSE 0 END) as default_price_lists,
    (SELECT COUNT(DISTINCT billable_item_id) FROM price_list_items WHERE effective_to IS NULL OR effective_to >= CURDATE()) as active_prices,
    (SELECT COUNT(*) FROM billable_items WHERE is_active = 1) as total_billable_items,
    (SELECT COUNT(*) FROM price_history WHERE DATE(changed_at) = CURDATE()) as today_changes
    FROM price_lists pl";

$price_stats_result = $mysqli->query($price_stats_sql);
$price_stats = $price_stats_result->fetch_assoc();

// Get recent price changes - UPDATED FOR NEW TABLES
$recent_price_changes_sql = "
    SELECT ph.*,
           bi.item_name,
           pl.price_list_name,
           u.user_name as changed_by_name
    FROM price_history ph
    LEFT JOIN billable_items bi ON ph.billable_item_id = bi.billable_item_id
    LEFT JOIN price_lists pl ON ph.price_list_id = pl.price_list_id
    LEFT JOIN users u ON ph.changed_by = u.user_id
    ORDER BY ph.changed_at DESC
    LIMIT 5
";
$recent_price_changes_result = $mysqli->query($recent_price_changes_sql);

// Get price lists with statistics - UPDATED FOR NEW TABLES
$sql = "SELECT SQL_CALC_FOUND_ROWS 
               pl.*, 
               ic.company_name,
               COUNT(DISTINCT pli.price_list_item_id) as item_count,
               creator.user_name as created_by_name,
               updater.user_name as updated_by_name
        FROM price_lists pl
        LEFT JOIN insurance_companies ic ON pl.insurance_provider_id = ic.insurance_company_id
        LEFT JOIN price_list_items pli ON pl.price_list_id = pli.price_list_id 
            AND (pli.effective_to IS NULL OR pli.effective_to >= CURDATE())
        LEFT JOIN users creator ON pl.created_by = creator.user_id
        LEFT JOIN users updater ON pl.updated_by = updater.user_id
        WHERE $where_clause
        GROUP BY pl.price_list_id
        ORDER BY $sort $order
        LIMIT $record_from, $record_to";

$stmt = $mysqli->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($param_types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get default price lists for quick access
$default_price_lists_sql = "
    SELECT pl.*, ic.company_name
    FROM price_lists pl
    LEFT JOIN insurance_companies ic ON pl.insurance_provider_id = ic.insurance_company_id
    WHERE pl.is_default = 1 AND pl.is_active = 1
    ORDER BY pl.price_list_type
";
$default_price_lists_result = $mysqli->query($default_price_lists_sql);
$default_price_lists = [];
while($row = $default_price_lists_result->fetch_assoc()) {
    $default_price_lists[$row['price_list_type']] = $row;
}

// Get recent price list clones
$recent_clones_sql = "
    SELECT pc.*, 
           source_pl.price_list_name as source_list_name,
           target_pl.price_list_name as target_list_name,
           u.user_name as cloned_by_name
    FROM price_list_clones pc
    JOIN price_lists source_pl ON pc.source_price_list_id = source_pl.price_list_id
    JOIN price_lists target_pl ON pc.target_price_list_id = target_pl.price_list_id
    LEFT JOIN users u ON pc.cloned_by = u.user_id
    ORDER BY pc.cloned_at DESC
    LIMIT 5
";
$recent_clones_result = $mysqli->query($recent_clones_sql);

// Get billable item categories for dropdowns
$billable_categories_sql = "SELECT category_id, category_name FROM billable_categories WHERE is_active = 1 ORDER BY category_name";
$billable_categories_result = $mysqli->query($billable_categories_sql);
?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-tags mr-2"></i>Price Management
        </h3>
        <div class="card-tools">
            <div class="btn-group">
                <a href="price_list_new.php" class="btn btn-success">
                    <i class="fas fa-plus mr-2"></i>New Price List
                </a>
                <a href="price_modifiers.php" class="btn btn-primary ml-2">
                    <i class="fas fa-percentage mr-2"></i>Price Modifiers
                </a>
                <a href="bulk_price_update.php" class="btn btn-info ml-2">
                    <i class="fas fa-sync-alt mr-2"></i>Bulk Update
                </a>
                <div class="btn-group ml-2">
                    <button type="button" class="btn btn-secondary dropdown-toggle" data-toggle="dropdown">
                        <i class="fas fa-tasks mr-2"></i>Quick Actions
                    </button>
                    <div class="dropdown-menu dropdown-menu-right">
                        <h6 class="dropdown-header">Price Management</h6>
                        <a class="dropdown-item" href="price_modifiers.php">
                            <i class="fas fa-percentage mr-2"></i>Price Modifiers
                        </a>
                        <a class="dropdown-item" href="price_history.php">
                            <i class="fas fa-history mr-2"></i>Price History
                        </a>
                        <a class="dropdown-item" href="bulk_price_update.php">
                            <i class="fas fa-sync-alt mr-2"></i>Bulk Price Update
                        </a>
                        <a class="dropdown-item" href="price_import.php">
                            <i class="fas fa-file-import mr-2"></i>Import Prices
                        </a>
                        <a class="dropdown-item" href="price_export.php">
                            <i class="fas fa-file-export mr-2"></i>Export Prices
                        </a>
                        
                        <div class="dropdown-divider"></div>
                        
                        <h6 class="dropdown-header">Tools</h6>
                        <a class="dropdown-item" href="#" data-toggle="modal" data-target="#quickPriceCheck">
                            <i class="fas fa-search-dollar mr-2"></i>Quick Price Check
                        </a>
                        <a class="dropdown-item" href="#" data-toggle="modal" data-target="#clonePriceListModal">
                            <i class="fas fa-copy mr-2"></i>Clone Price List
                        </a>
                        <a class="dropdown-item" href="price_reports.php">
                            <i class="fas fa-chart-bar mr-2"></i>Price Reports
                        </a>
                        
                        <div class="dropdown-divider"></div>
                        
                        <h6 class="dropdown-header">Default Lists</h6>
                        <?php foreach($default_price_lists as $type => $list): ?>
                        <a class="dropdown-item" href="price_list_view.php?id=<?php echo $list['price_list_id']; ?>">
                            <i class="fas fa-star text-warning mr-2"></i><?php echo ucfirst($type); ?>: <?php echo htmlspecialchars($list['price_list_name']); ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Quick Price Check Modal -->
    <div class="modal fade" id="quickPriceCheck" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-search-dollar mr-2"></i>Quick Price Check
                    </h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Billable Item</label>
                                <select id="quickCheckEntity" class="form-control select2" style="width: 100%;">
                                    <option value="">Select billable item</option>
                                    <?php
                                    // Get billable items
                                    $items_sql = "SELECT bi.billable_item_id, bi.item_name, bi.item_code, bi.item_type 
                                                 FROM billable_items bi 
                                                 WHERE bi.is_active = 1 
                                                 ORDER BY bi.item_type, bi.item_name 
                                                 LIMIT 100";
                                    $items_result = $mysqli->query($items_sql);
                                    $current_type = '';
                                    while($item = $items_result->fetch_assoc()):
                                        if ($current_type != $item['item_type']) {
                                            $current_type = $item['item_type'];
                                            echo '<optgroup label="' . ucfirst($current_type) . ' Items">';
                                        }
                                    ?>
                                        <option value="<?php echo $item['item_type'] . ':' . $item['billable_item_id']; ?>">
                                            <?php echo htmlspecialchars($item['item_name'] . " (" . $item['item_code'] . ")"); ?>
                                        </option>
                                    <?php 
                                        if ($current_type != $item['item_type']) {
                                            echo '</optgroup>';
                                        }
                                    endwhile; 
                                    ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Quantity</label>
                                <input type="number" id="quickCheckQuantity" class="form-control" value="1" min="1">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Payer Type</label>
                                <select id="quickCheckPayerType" class="form-control">
                                    <option value="cash">Cash</option>
                                    <option value="insurance">Insurance</option>
                                    <option value="corporate">Corporate</option>
                                    <option value="government">Government</option>
                                    <option value="staff">Staff</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Price List</label>
                                <select id="quickCheckPriceList" class="form-control select2">
                                    <option value="">Default Price List</option>
                                    <?php
                                    $price_lists_sql = "SELECT price_list_id, price_list_name, price_list_type FROM price_lists WHERE is_active = 1";
                                    $price_lists_result = $mysqli->query($price_lists_sql);
                                    while($pl = $price_lists_result->fetch_assoc()): ?>
                                        <option value="<?php echo $pl['price_list_id']; ?>">
                                            <?php echo htmlspecialchars($pl['price_list_name'] . " (" . ucfirst($pl['price_list_type']) . ")"); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>&nbsp;</label>
                                <button class="btn btn-primary btn-block" onclick="quickPriceCheck()">
                                    <i class="fas fa-calculator mr-2"></i>Calculate Price
                                </button>
                            </div>
                        </div>
                    </div>
                    <div id="quickPriceResult" class="mt-3" style="display: none;">
                        <div class="card">
                            <div class="card-body">
                                <h5 id="priceResultTitle" class="card-title"></h5>
                                <div id="priceResultDetails"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Clone Price List Modal -->
    <div class="modal fade" id="clonePriceListModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Clone Price List</h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <form id="cloneForm" action="price_management.php" method="POST">
                    <input type="hidden" name="action" value="clone_price_list">
                    <div class="modal-body">
                        <div class="form-group">
                            <label>Source Price List</label>
                            <select name="source_price_list_id" class="form-control" required>
                                <option value="">Select a price list to clone</option>
                                <?php
                                $price_lists_sql = "SELECT pl.*, ic.company_name 
                                                  FROM price_lists pl 
                                                  LEFT JOIN insurance_companies ic ON pl.insurance_provider_id = ic.insurance_company_id 
                                                  WHERE pl.is_active = 1 
                                                  ORDER BY pl.price_list_type, pl.price_list_name";
                                $price_lists_result = $mysqli->query($price_lists_sql);
                                while($pl = $price_lists_result->fetch_assoc()): ?>
                                    <option value="<?php echo $pl['price_list_id']; ?>">
                                        <?php echo htmlspecialchars($pl['price_list_name'] . " - " . ucfirst($pl['price_list_type']) . ($pl['company_name'] ? " (" . $pl['company_name'] . ")" : "")); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>New Price List Name</label>
                            <input type="text" name="new_list_name" class="form-control" required placeholder="Enter new price list name">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Clone Price List</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Alert Row -->
    <?php if (isset($_SESSION['alert_message'])): ?>
    <div class="row mt-3 mx-2">
        <div class="col-12">
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle mr-2"></i>
                <?php echo $_SESSION['alert_message']; ?>
                <button type="button" class="close" data-dismiss="alert" onclick="<?php unset($_SESSION['alert_message']); ?>">
                    <span>&times;</span>
                </button>
            </div>
        </div>
    </div>
    <?php unset($_SESSION['alert_message']); endif; ?>
    
    <?php if ($price_stats['active_price_lists'] == 0): ?>
    <div class="row mt-3 mx-2">
        <div class="col-12">
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle mr-2"></i>
                <strong>No active price lists found.</strong>
                <a href="price_list_new.php" class="alert-link ml-2">Create your first price list</a>
                <button type="button" class="close" data-dismiss="alert">
                    <span>&times;</span>
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if (isset($default_price_lists['cash']) && isset($default_price_lists['insurance'])): ?>
    <div class="row mt-3 mx-2">
        <div class="col-12">
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle mr-2"></i>
                <strong>Default price lists configured:</strong>
                Cash: <?php echo htmlspecialchars($default_price_lists['cash']['price_list_name']); ?>,
                Insurance: <?php echo htmlspecialchars($default_price_lists['insurance']['price_list_name']); ?>
                <button type="button" class="close" data-dismiss="alert">
                    <span>&times;</span>
                </button>
            </div>
        </div>
    </div>
    <?php elseif (!isset($default_price_lists['cash']) || !isset($default_price_lists['insurance'])): ?>
    <div class="row mt-3 mx-2">
        <div class="col-12">
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle mr-2"></i>
                <strong>Default price lists not configured.</strong>
                Please set default price lists for both cash and insurance types.
                <button type="button" class="close" data-dismiss="alert">
                    <span>&times;</span>
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Statistics Dashboard -->
    <div class="row mt-3 mx-2">
        <div class="col-md-3 mb-3">
            <div class="card bg-primary text-white">
                <div class="card-body py-2">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="font-weight-bold mb-0"><?php echo $price_stats['total_price_lists'] ?? 0; ?></h4>
                            <small>Price Lists</small>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-tags fa-2x"></i>
                        </div>
                    </div>
                </div>
                <div class="card-footer p-1">
                    <small>
                        <i class="fas fa-check-circle text-success mr-1"></i> <?php echo $price_stats['active_price_lists'] ?? 0; ?> Active
                    </small>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 mb-3">
            <div class="card bg-success text-white">
                <div class="card-body py-2">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="font-weight-bold mb-0"><?php echo $price_stats['cash_price_lists'] ?? 0; ?></h4>
                            <small>Cash Price Lists</small>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-money-bill-wave fa-2x"></i>
                        </div>
                    </div>
                </div>
                <div class="card-footer p-1">
                    <small>
                        <i class="fas fa-hand-holding-medical mr-1"></i> <?php echo $price_stats['insurance_price_lists'] ?? 0; ?> Insurance
                    </small>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 mb-3">
            <div class="card bg-info text-white">
                <div class="card-body py-2">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="font-weight-bold mb-0"><?php echo $price_stats['active_prices'] ?? 0; ?></h4>
                            <small>Active Prices</small>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-file-invoice-dollar fa-2x"></i>
                        </div>
                    </div>
                </div>
                <div class="card-footer p-1">
                    <small>
                        <?php echo $price_stats['total_billable_items'] ?? 0; ?> Billable Items
                    </small>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 mb-3">
            <div class="card bg-warning text-white">
                <div class="card-body py-2">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="font-weight-bold mb-0"><?php echo $price_stats['today_changes'] ?? 0; ?></h4>
                            <small>Today's Changes</small>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-sync-alt fa-2x"></i>
                        </div>
                    </div>
                </div>
                <div class="card-footer p-1">
                    <small>
                        <i class="fas fa-star text-warning mr-1"></i> <?php echo $price_stats['default_price_lists'] ?? 0; ?> Default Lists
                    </small>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Quick Access Cards -->
    <div class="row mx-2 mb-3">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header bg-light">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-bolt mr-2"></i>Quick Access
                    </h5>
                </div>
                <div class="card-body p-2">
                    <div class="row">
                        <div class="col-md-2 mb-2">
                            <a href="price_list_new.php" class="btn btn-outline-success btn-block">
                                <i class="fas fa-plus fa-2x mb-2"></i><br>
                                New Price List
                            </a>
                        </div>
                        <div class="col-md-2 mb-2">
                            <a href="billable_items.php" class="btn btn-outline-primary btn-block">
                                <i class="fas fa-boxes fa-2x mb-2"></i><br>
                                Billable Items
                            </a>
                        </div>
                        <div class="col-md-2 mb-2">
                            <a href="bulk_price_update.php" class="btn btn-outline-info btn-block">
                                <i class="fas fa-sync-alt fa-2x mb-2"></i><br>
                                Bulk Update
                            </a>
                        </div>
                        <div class="col-md-2 mb-2">
                            <a href="#" class="btn btn-outline-warning btn-block" data-toggle="modal" data-target="#quickPriceCheck">
                                <i class="fas fa-search-dollar fa-2x mb-2"></i><br>
                                Price Check
                            </a>
                        </div>
                        <div class="col-md-2 mb-2">
                            <a href="price_history.php" class="btn btn-outline-dark btn-block">
                                <i class="fas fa-history fa-2x mb-2"></i><br>
                                History
                            </a>
                        </div>
                        <div class="col-md-2 mb-2">
                            <a href="#" class="btn btn-outline-secondary btn-block" data-toggle="modal" data-target="#clonePriceListModal">
                                <i class="fas fa-copy fa-2x mb-2"></i><br>
                                Clone List
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card-header pb-2 pt-3">
        <form autocomplete="off" method="GET">
            <div class="row">
                <div class="col-md-5">
                    <div class="form-group">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search price lists..." autofocus>
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
                            <button type="button" class="btn btn-light border" data-toggle="tooltip" title="Quick Actions">
                                <i class="fas fa-bolt text-warning"></i>
                            </button>
                            <a href="price_list_new.php" class="btn btn-success">
                                <i class="fas fa-plus mr-2"></i>New Price List
                            </a>
                            <a href="billable_items.php" class="btn btn-primary ml-2">
                                <i class="fas fa-boxes mr-2"></i>Billable Items
                            </a>
                            <a href="#" class="btn btn-info ml-2" data-toggle="modal" data-target="#clonePriceListModal">
                                <i class="fas fa-copy mr-2"></i>Clone
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php if ($status_filter || $type_filter || $company_filter) { echo "show"; } ?>" id="advancedFilter">
                <div class="row">
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Status</label>
                            <select class="form-control select2" name="status">
                                <option value="">- All Status -</option>
                                <option value="active" <?php if ($status_filter == "active") { echo "selected"; } ?>>Active</option>
                                <option value="inactive" <?php if ($status_filter == "inactive") { echo "selected"; } ?>>Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Type</label>
                            <select class="form-control select2" name="type">
                                <option value="">- All Types -</option>
                                <option value="cash" <?php if ($type_filter == "cash") { echo "selected"; } ?>>Cash</option>
                                <option value="insurance" <?php if ($type_filter == "insurance") { echo "selected"; } ?>>Insurance</option>
                                <option value="corporate" <?php if ($type_filter == "corporate") { echo "selected"; } ?>>Corporate</option>
                                <option value="government" <?php if ($type_filter == "government") { echo "selected"; } ?>>Government</option>
                                <option value="staff" <?php if ($type_filter == "staff") { echo "selected"; } ?>>Staff</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Insurance Provider</label>
                            <select class="form-control select2" name="company">
                                <option value="">- All Providers -</option>
                                <?php
                                $companies_sql = "SELECT insurance_company_id, company_name FROM insurance_companies WHERE is_active = 1 ORDER BY company_name";
                                $companies_result = $mysqli->query($companies_sql);
                                while($company = $companies_result->fetch_assoc()): ?>
                                    <option value="<?php echo $company['insurance_company_id']; ?>" <?php if ($company_filter == $company['insurance_company_id']) { echo "selected"; } ?>>
                                        <?php echo htmlspecialchars($company['company_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>&nbsp;</label>
                            <div class="btn-group btn-block">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-filter mr-2"></i>Apply Filters
                                </button>
                                <a href="price_management.php" class="btn btn-secondary">
                                    <i class="fas fa-times mr-2"></i>Clear
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
    
    <div class="row mx-2">
        <!-- Main Price Lists Table -->
        <div class="col-md-8">
            <div class="card">
                <div class="card-header bg-light">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-tags mr-2"></i>Price Lists
                        <span class="badge badge-light float-right"><?php echo $num_rows[0]; ?> lists</span>
                    </h5>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="<?php if ($num_rows[0] == 0) { echo "d-none"; } ?>">
                        <tr>
                            <th>
                                <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=pl.price_list_name&order=<?php echo $disp; ?>">
                                    Name <?php if ($sort == 'pl.price_list_name') { echo $order_icon; } ?>
                                </a>
                            </th>
                            <th>
                                <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=pl.price_list_type&order=<?php echo $disp; ?>">
                                    Type <?php if ($sort == 'pl.price_list_type') { echo $order_icon; } ?>
                                </a>
                            </th>
                            <th>Code</th>
                            <th>Insurance Provider</th>
                            <th class="text-center">Items</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php 
                        if ($num_rows[0] == 0) {
                            ?>
                            <tr>
                                <td colspan="7" class="text-center py-5">
                                    <i class="fas fa-tags fa-3x text-muted mb-3"></i>
                                    <h5 class="text-muted">No price lists found</h5>
                                    <p class="text-muted">
                                        <?php 
                                        if ($q || $status_filter || $type_filter || $company_filter) {
                                            echo "Try adjusting your search or filter criteria.";
                                        } else {
                                            echo "Get started by creating your first price list.";
                                        }
                                        ?>
                                    </p>
                                    <a href="price_list_new.php" class="btn btn-success">
                                        <i class="fas fa-plus mr-2"></i>Create First Price List
                                    </a>
                                    <?php if ($q || $status_filter || $type_filter || $company_filter): ?>
                                        <a href="price_management.php" class="btn btn-secondary ml-2">
                                            <i class="fas fa-times mr-2"></i>Clear Filters
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php
                        } else {
                            while ($row = $result->fetch_assoc()) {
                                $price_list_id = intval($row['price_list_id']);
                                $list_name = nullable_htmlentities($row['price_list_name']);
                                $list_code = nullable_htmlentities($row['price_list_code']);
                                $price_list_type = $row['price_list_type'];
                                $company_name = nullable_htmlentities($row['company_name']);
                                $item_count = intval($row['item_count'] ?? 0);
                                $is_active = boolval($row['is_active'] ?? 0);
                                $is_default = boolval($row['is_default'] ?? 0);
                                $created_by = nullable_htmlentities($row['created_by_name']);
                                $created_at = nullable_htmlentities($row['created_at']);
                                ?>
                                <tr>
                                    <td>
                                        <div class="font-weight-bold">
                                            <a href="price_list_view.php?id=<?php echo $price_list_id; ?>" class="text-dark">
                                                <?php echo $list_name; ?>
                                            </a>
                                            <?php if($is_default): ?>
                                                <span class="badge badge-warning ml-2">Default</span>
                                            <?php endif; ?>
                                        </div>
                                        <small class="text-muted">
                                            Created: <?php echo date('M j, Y', strtotime($created_at)); ?>
                                            <?php if($created_by): ?>
                                                by <?php echo $created_by; ?>
                                            <?php endif; ?>
                                        </small>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?php 
                                            switch($price_list_type) {
                                                case 'cash': echo 'success'; break;
                                                case 'insurance': echo 'info'; break;
                                                case 'corporate': echo 'primary'; break;
                                                case 'government': echo 'warning'; break;
                                                case 'staff': echo 'secondary'; break;
                                                default: echo 'light';
                                            }
                                        ?>">
                                            <?php echo ucfirst($price_list_type); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <code><?php echo $list_code; ?></code>
                                    </td>
                                    <td>
                                        <?php if($company_name): ?>
                                            <div><?php echo $company_name; ?></div>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <div class="font-weight-bold">
                                            <?php echo $item_count; ?>
                                        </div>
                                        <small class="text-muted">
                                            items
                                        </small>
                                        <?php if ($item_count > 0): ?>
                                        <div class="mt-1">
                                            <a href="price_list_items.php?price_list_id=<?php echo $price_list_id; ?>" class="btn btn-xs btn-outline-primary">
                                                <i class="fas fa-list mr-1"></i>View Items
                                            </a>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge badge-<?php echo $is_active ? 'success' : 'secondary'; ?> badge-pill">
                                            <?php echo $is_active ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="dropdown dropleft text-center">
                                            <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                                <i class="fas fa-ellipsis-h"></i>
                                            </button>
                                            <div class="dropdown-menu">
                                                <a class="dropdown-item" href="price_list_view.php?id=<?php echo $price_list_id; ?>">
                                                    <i class="fas fa-fw fa-eye mr-2"></i>View Details
                                                </a>
                                                <a class="dropdown-item" href="price_list_edit.php?id=<?php echo $price_list_id; ?>">
                                                    <i class="fas fa-fw fa-edit mr-2"></i>Edit Price List
                                                </a>
                                                <?php if ($item_count > 0): ?>
                                                <a class="dropdown-item" href="price_list_items.php?price_list_id=<?php echo $price_list_id; ?>">
                                                    <i class="fas fa-fw fa-list mr-2"></i>View Items in List
                                                </a>
                                                <?php endif; ?>
                                                <a class="dropdown-item" href="bulk_price_update.php?price_list_id=<?php echo $price_list_id; ?>">
                                                    <i class="fas fa-fw fa-sync-alt mr-2"></i>Bulk Update Prices
                                                </a>
                                                <a class="dropdown-item" href="#" data-toggle="modal" data-target="#clonePriceListModal" onclick="$('#cloneForm select[name=\"source_price_list_id\"]').val('<?php echo $price_list_id; ?>');">
                                                    <i class="fas fa-fw fa-copy mr-2"></i>Clone This List
                                                </a>
                                                <div class="dropdown-divider"></div>
                                                <?php if(!$is_default): ?>
                                                    <a class="dropdown-item text-warning" href="price_management.php?action=toggle_default&id=<?php echo $price_list_id; ?>" 
                                                       onclick="return confirm('Set this as default price list for <?php echo ucfirst($price_list_type); ?>?')">
                                                        <i class="fas fa-fw fa-star mr-2"></i>Set as Default
                                                    </a>
                                                <?php endif; ?>
                                                <?php if($is_active): ?>
                                                    <a class="dropdown-item text-danger" href="price_management.php?action=toggle_status&id=<?php echo $price_list_id; ?>">
                                                        <i class="fas fa-fw fa-pause mr-2"></i>Deactivate
                                                    </a>
                                                <?php else: ?>
                                                    <a class="dropdown-item text-success" href="price_management.php?action=toggle_status&id=<?php echo $price_list_id; ?>">
                                                        <i class="fas fa-fw fa-play mr-2"></i>Activate
                                                    </a>
                                                <?php endif; ?>
                                                <?php if($item_count == 0): ?>
                                                    <a class="dropdown-item text-danger" href="price_management.php?action=delete&id=<?php echo $price_list_id; ?>" 
                                                       onclick="return confirm('Are you sure you want to delete this price list?')">
                                                        <i class="fas fa-fw fa-trash mr-2"></i>Delete
                                                    </a>
                                                <?php else: ?>
                                                    <span class="dropdown-item text-muted disabled" style="cursor: not-allowed;">
                                                        <i class="fas fa-fw fa-trash mr-2"></i>Delete (has items)
                                                    </span>
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
            </div>
        </div>
        
        <!-- Sidebar with Recent Activity -->
        <div class="col-md-4">
            <!-- Recent Price Changes -->
            <?php if ($recent_price_changes_result->num_rows > 0): ?>
            <div class="card mb-3">
                <div class="card-header bg-light">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-history mr-2"></i>Recent Price Changes
                        <a href="price_history.php" class="float-right btn btn-xs btn-outline-dark">
                            View All
                        </a>
                    </h5>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        <?php while($change = $recent_price_changes_result->fetch_assoc()): ?>
                        <div class="list-group-item py-2">
                            <div class="d-flex w-100 justify-content-between">
                                <h6 class="mb-1"><?php echo htmlspecialchars($change['item_name'] ?? 'Unknown'); ?></h6>
                                <small class="text-muted"><?php echo date('H:i', strtotime($change['changed_at'])); ?></small>
                            </div>
                            <p class="mb-1">
                                <span class="badge badge-light"><?php echo htmlspecialchars($change['price_list_name']); ?></span>
                            </p>
                            <small class="text-muted">
                                <?php echo htmlspecialchars($change['changed_by_name'] ?? 'System'); ?> updated from 
                                <strong><?php echo number_format($change['old_price'], 2); ?></strong> to 
                                <strong class="text-<?php echo $change['new_price'] > $change['old_price'] ? 'danger' : 'success'; ?>">
                                    <?php echo number_format($change['new_price'], 2); ?>
                                </strong>
                            </small>
                        </div>
                        <?php endwhile; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Recent Clones -->
            <?php if ($recent_clones_result->num_rows > 0): ?>
            <div class="card mb-3">
                <div class="card-header bg-light">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-copy mr-2"></i>Recent Clones
                    </h5>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        <?php while($clone = $recent_clones_result->fetch_assoc()): ?>
                        <div class="list-group-item py-2">
                            <div class="d-flex w-100 justify-content-between">
                                <h6 class="mb-1"><?php echo htmlspecialchars($clone['target_list_name']); ?></h6>
                                <small class="text-muted"><?php echo date('M j', strtotime($clone['cloned_at'])); ?></small>
                            </div>
                            <p class="mb-1">
                                <small class="text-muted">
                                    Cloned from: <?php echo htmlspecialchars($clone['source_list_name']); ?>
                                </small>
                            </p>
                            <small class="text-muted">
                                By: <?php echo htmlspecialchars($clone['cloned_by_name'] ?? 'System'); ?>
                            </small>
                        </div>
                        <?php endwhile; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Default Price Lists -->
            <div class="card">
                <div class="card-header bg-light">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-star mr-2"></i>Default Price Lists
                    </h5>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        <?php foreach(['cash', 'insurance', 'corporate', 'government', 'staff'] as $type): ?>
                        <div class="list-group-item py-2">
                            <div class="d-flex w-100 justify-content-between">
                                <h6 class="mb-1"><?php echo ucfirst($type); ?> Price List</h6>
                                <?php if(isset($default_price_lists[$type])): ?>
                                    <span class="badge badge-success">Set</span>
                                <?php else: ?>
                                    <span class="badge badge-warning">Not Set</span>
                                <?php endif; ?>
                            </div>
                            <?php if(isset($default_price_lists[$type])): ?>
                                <p class="mb-1">
                                    <a href="price_list_view.php?id=<?php echo $default_price_lists[$type]['price_list_id']; ?>" class="text-dark">
                                        <?php echo htmlspecialchars($default_price_lists[$type]['price_list_name']); ?>
                                    </a>
                                </p>
                                <?php if($default_price_lists[$type]['company_name']): ?>
                                    <small class="text-muted">
                                        <?php echo htmlspecialchars($default_price_lists[$type]['company_name']); ?>
                                    </small>
                                <?php endif; ?>
                            <?php else: ?>
                                <p class="mb-1 text-danger">
                                    <i class="fas fa-exclamation-circle mr-1"></i>No default set for <?php echo ucfirst($type); ?>
                                </p>
                                <a href="price_list_new.php?price_list_type=<?php echo $type; ?>" class="btn btn-xs btn-outline-success">
                                    <i class="fas fa-plus mr-1"></i>Create <?php echo ucfirst($type); ?> List
                                </a>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            
            <!-- Category Navigation -->
            <div class="card mt-3">
                <div class="card-header bg-light">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-folder mr-2"></i>Browse by Category
                    </h5>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        <div class="list-group-item py-2">
                            <h6 class="mb-1">Billable Item Categories</h6>
                            <?php while($category = $billable_categories_result->fetch_assoc()): ?>
                            <a href="price_list_items.php?category_id=<?php echo $category['category_id']; ?>" class="badge badge-light mr-1 mb-1">
                                <?php echo htmlspecialchars($category['category_name']); ?>
                            </a>
                            <?php endwhile; ?>
                            <div class="mt-2">
                                <a href="billable_items.php" class="btn btn-xs btn-outline-primary">
                                    <i class="fas fa-boxes mr-1"></i>View All Billable Items
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/filter_footer.php'; ?>
</div>

<script>
$(document).ready(function() {
    $('.select2').select2({
        theme: 'bootstrap4',
        width: '100%'
    });
    $('[data-toggle="tooltip"]').tooltip();

    // Auto-submit when filters change
    $('select[name="status"], select[name="type"], select[name="company"]').change(function() {
        $(this).closest('form').submit();
    });

    // Clone form submission
    $('#cloneForm').submit(function(e) {
        e.preventDefault();
        
        $.ajax({
            url: 'price_management.php',
            method: 'POST',
            data: $(this).serialize(),
            success: function(response) {
                const result = JSON.parse(response);
                if (result.success) {
                    alert('Price list cloned successfully! New list ID: ' + result.new_list_id);
                    $('#clonePriceListModal').modal('hide');
                    location.reload();
                } else {
                    alert('Error: ' + result.message);
                }
            },
            error: function() {
                alert('Error cloning price list');
            }
        });
    });
});

function quickPriceCheck() {
    const entity = $('#quickCheckEntity').val();
    const quantity = $('#quickCheckQuantity').val();
    const payerType = $('#quickCheckPayerType').val();
    const priceListId = $('#quickCheckPriceList').val();
    
    if (!entity) {
        alert('Please select a billable item');
        return;
    }
    
    const [entityType, entityId] = entity.split(':');
    
    $.ajax({
        url: 'price_management.php',
        method: 'POST',
        data: {
            action: 'quick_price_check',
            entity_type: entityType,
            entity_id: entityId,
            quantity: quantity,
            payer_type: payerType,
            price_list_id: priceListId || ''
        },
        success: function(response) {
            const data = JSON.parse(response);
            
            if (data.error) {
                alert(data.error);
                return;
            }
            
            $('#priceResultTitle').text(data.name);
            
            let html = `
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Base Price:</label>
                            <div class="h5">${parseFloat(data.base_price).toFixed(2)}</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Final Price:</label>
                            <div class="h5 text-success">${parseFloat(data.final_price).toFixed(2)}</div>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Quantity:</label>
                            <div class="h6">${data.quantity}</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Total:</label>
                            <div class="h4 text-primary">${parseFloat(data.total).toFixed(2)}</div>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-12">
                        <div class="form-group">
                            <label>Price Calculation:</label>
                            <span class="badge badge-info">${data.strategy}</span>
                            ${data.price_list_id ? '<span class="badge badge-light ml-2">Price List: ' + data.price_list_id + '</span>' : ''}
                        </div>
                    </div>
                </div>
            `;
            
            if (data.coverage) {
                html += `
                    <div class="row mt-3">
                        <div class="col-md-12">
                            <div class="alert alert-info">
                                <h6>Insurance Coverage Details:</h6>
                                <div>Insurance Pays: <strong>${parseFloat(data.coverage.insurance_pays).toFixed(2)}</strong></div>
                                <div>Patient Pays: <strong>${parseFloat(data.coverage.patient_pays).toFixed(2)}</strong></div>
                                <div>Coverage Rate: <strong>${data.coverage.coverage_rate}</strong></div>
                            </div>
                        </div>
                    </div>
                `;
            }
            
            $('#priceResultDetails').html(html);
            $('#quickPriceResult').show();
        },
        error: function() {
            alert('Error calculating price');
        }
    });
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + N for new price list
    if (e.ctrlKey && e.keyCode === 78) {
        e.preventDefault();
        window.location.href = 'price_list_new.php';
    }
    // Ctrl + F for focus search
    if (e.ctrlKey && e.keyCode === 70) {
        e.preventDefault();
        $('input[name="q"]').focus();
    }
    // Ctrl + Q for quick price check
    if (e.ctrlKey && e.keyCode === 81) {
        e.preventDefault();
        $('#quickPriceCheck').modal('show');
    }
    // Ctrl + C for clone
    if (e.ctrlKey && e.keyCode === 67) {
        e.preventDefault();
        $('#clonePriceListModal').modal('show');
    }
});

// Auto-refresh price check on changes
$('#quickCheckEntity, #quickCheckQuantity, #quickCheckPayerType, #quickCheckPriceList').change(function() {
    if ($('#quickCheckEntity').val()) {
        quickPriceCheck();
    }
});
</script>

<style>
.card .card-body {
    padding: 1rem;
}

.list-group-item {
    border: none;
    padding: 0.5rem 1rem;
}

.list-group-item:hover {
    background-color: #f8f9fa;
}

.badge-pill {
    padding: 0.3em 0.6em;
    font-size: 0.85em;
}

.alert-container .alert {
    margin-bottom: 0.5rem;
    border-radius: 0.25rem;
}

.quick-access .btn {
    padding: 1rem 0.5rem;
    text-align: center;
}

.quick-access .btn i {
    display: block;
    margin-bottom: 0.5rem;
}

.table td, .table th {
    vertical-align: middle;
}

.select2-container--bootstrap4 .select2-selection {
    height: calc(2.25rem + 2px);
}

.btn-xs {
    padding: 0.1rem 0.4rem;
    font-size: 0.75rem;
}

.text-warning {
    color: #ffc107 !important;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
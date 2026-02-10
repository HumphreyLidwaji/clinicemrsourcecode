<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Get price list ID
$price_list_id = intval($_GET['id'] ?? 0);
$category_id = intval($_GET['category_id'] ?? 0);
$entity_type = $_GET['type'] ?? ''; // ITEM or SERVICE
$q = sanitizeInput($_GET['q'] ?? '');

// Get price list details
$price_list_sql = "SELECT pl.*, ic.company_name, ic.company_code,
                   creator.user_name as created_by_name,
                   updater.user_name as updated_by_name
                   FROM price_lists pl
                   LEFT JOIN insurance_companies ic ON pl.insurance_company_id = ic.insurance_company_id
                   LEFT JOIN users creator ON pl.created_by = creator.user_id
                   LEFT JOIN users updater ON pl.updated_by = updater.user_id
                   WHERE pl.price_list_id = ?";
$stmt = $mysqli->prepare($price_list_sql);
$stmt->bind_param('i', $price_list_id);
$stmt->execute();
$price_list_result = $stmt->get_result();
$price_list = $price_list_result->fetch_assoc();

if (!$price_list) {
    $_SESSION['alert_message'] = "Price list not found";
    header("Location: price_management.php");
    exit;
}

$list_name = nullable_htmlentities($price_list['list_name']);
$payer_type = $price_list['payer_type'];
$company_name = nullable_htmlentities($price_list['company_name']);

// Initialize arrays
$item_prices = [];
$service_prices = [];

// Get item prices with pagination
$item_limit = 50; // Limit items per page
$item_page = isset($_GET['item_page']) ? intval($_GET['item_page']) : 1;
$item_offset = ($item_page - 1) * $item_limit;

if ($price_list_id) {
    // Build where conditions for items
    $item_where_conditions = ["ip.price_list_id = ?", "ip.is_active = 1"];
    $item_params = [$price_list_id];
    $item_param_types = 'i';
    
    if ($category_id && $entity_type == 'ITEM') {
        $item_where_conditions[] = "ii.item_category_id = ?";
        $item_params[] = $category_id;
        $item_param_types .= 'i';
    }
    
    if (!empty($q) && ($entity_type == '' || $entity_type == 'ITEM')) {
        $item_where_conditions[] = "(ii.item_name LIKE ? OR ii.item_code LIKE ? OR ii.item_description LIKE ?)";
        $item_params[] = "%$q%";
        $item_params[] = "%$q%";
        $item_params[] = "%$q%";
        $item_param_types .= 'sss';
    }
    
    $item_where_clause = implode(" AND ", $item_where_conditions);
    
    $item_prices_sql = "SELECT 
                       ip.*,
                       ii.item_id,
                       ii.item_name,
                       ii.item_code,
                       ii.item_description,
                       ii.item_unit_price as base_price,
                       ii.item_quantity as stock_quantity,
                       ii.item_status,
                       ic.category_name as item_category_name,
                       creator.user_name as created_by_name,
                       updater.user_name as updated_by_name,
                       ph.new_price as last_price,
                       ph.changed_at as last_price_change,
                       phu.user_name as last_changed_by
                       FROM item_prices ip
                       JOIN inventory_items ii ON ip.item_id = ii.item_id
                       LEFT JOIN inventory_categories ic ON ii.item_category_id = ic.category_id
                       LEFT JOIN users creator ON ip.created_by = creator.user_id
                       LEFT JOIN users updater ON ip.updated_by = updater.user_id
                       LEFT JOIN (
                           SELECT ph1.*, u.user_name
                           FROM price_history ph1
                           LEFT JOIN users u ON ph1.changed_by = u.user_id
                           WHERE ph1.entity_type = 'ITEM' 
                           AND ph1.price_list_id = ?
                           ORDER BY ph1.changed_at DESC
                       ) ph ON ip.item_id = ph.entity_id
                       LEFT JOIN users phu ON ph.changed_by = phu.user_id
                       WHERE $item_where_clause
                       ORDER BY ii.item_name ASC
                       LIMIT ?, ?";
    
    // Add price_list_id for subquery and pagination params
    $item_params[] = $price_list_id;
    $item_params[] = $item_offset;
    $item_params[] = $item_limit;
    $item_param_types .= 'iii';
    
    $stmt = $mysqli->prepare($item_prices_sql);
    if (!empty($item_params)) {
        $stmt->bind_param($item_param_types, ...$item_params);
    }
    $stmt->execute();
    $item_prices_result = $stmt->get_result();
    
    // Use fetch_assoc in a loop instead of fetch_all
    while ($row = $item_prices_result->fetch_assoc()) {
        $item_prices[] = $row;
    }
    
    // Get total item count for pagination
    $item_count_sql = "SELECT COUNT(*) as total 
                      FROM item_prices ip
                      JOIN inventory_items ii ON ip.item_id = ii.item_id
                      WHERE ip.price_list_id = ? AND ip.is_active = 1";
    
    $item_count_params = [$price_list_id];
    $item_count_types = 'i';
    
    if ($category_id && $entity_type == 'ITEM') {
        $item_count_sql .= " AND ii.item_category_id = ?";
        $item_count_params[] = $category_id;
        $item_count_types .= 'i';
    }
    
    if (!empty($q) && ($entity_type == '' || $entity_type == 'ITEM')) {
        $item_count_sql .= " AND (ii.item_name LIKE ? OR ii.item_code LIKE ? OR ii.item_description LIKE ?)";
        $item_count_params[] = "%$q%";
        $item_count_params[] = "%$q%";
        $item_count_params[] = "%$q%";
        $item_count_types .= 'sss';
    }
    
    $stmt = $mysqli->prepare($item_count_sql);
    if (!empty($item_count_params)) {
        $stmt->bind_param($item_count_types, ...$item_count_params);
    }
    $stmt->execute();
    $item_count_result = $stmt->get_result();
    $item_count_row = $item_count_result->fetch_assoc();
    $total_items = $item_count_row['total'] ?? 0;
    $item_total_pages = ceil($total_items / $item_limit);
}

// Get service prices with pagination
$service_limit = 50; // Limit services per page
$service_page = isset($_GET['service_page']) ? intval($_GET['service_page']) : 1;
$service_offset = ($service_page - 1) * $service_limit;

if ($price_list_id) {
    // Build where conditions for services
    $service_where_conditions = ["msp.price_list_id = ?", "msp.is_active = 1"];
    $service_params = [$price_list_id];
    $service_param_types = 'i';
    
    if ($category_id && $entity_type == 'SERVICE') {
        $service_where_conditions[] = "ms.service_category_id = ?";
        $service_params[] = $category_id;
        $service_param_types .= 'i';
    }
    
    if (!empty($q) && ($entity_type == '' || $entity_type == 'SERVICE')) {
        $service_where_conditions[] = "(ms.service_name LIKE ? OR ms.service_code LIKE ? OR ms.service_description LIKE ?)";
        $service_params[] = "%$q%";
        $service_params[] = "%$q%";
        $service_params[] = "%$q%";
        $service_param_types .= 'sss';
    }
    
    $service_where_clause = implode(" AND ", $service_where_conditions);
    
    $service_prices_sql = "SELECT 
                          msp.*,
                          ms.medical_service_id,
                          ms.service_name,
                          ms.service_code,
                          ms.service_description,
                          ms.fee_amount as base_price,
                          ms.is_active as service_active,
                          msc.category_name as service_category_name,
                          creator.user_name as created_by_name,
                          updater.user_name as updated_by_name,
                          ph.new_price as last_price,
                          ph.changed_at as last_price_change,
                          phu.user_name as last_changed_by
                          FROM medical_service_prices msp
                          JOIN medical_services ms ON msp.medical_service_id = ms.medical_service_id
                          LEFT JOIN medical_service_categories msc ON ms.service_category_id = msc.category_id
                          LEFT JOIN users creator ON msp.created_by = creator.user_id
                          LEFT JOIN users updater ON msp.updated_by = updater.user_id
                          LEFT JOIN (
                              SELECT ph1.*, u.user_name
                              FROM price_history ph1
                              LEFT JOIN users u ON ph1.changed_by = u.user_id
                              WHERE ph1.entity_type = 'SERVICE' 
                              AND ph1.price_list_id = ?
                              ORDER BY ph1.changed_at DESC
                          ) ph ON ms.medical_service_id = ph.entity_id
                          LEFT JOIN users phu ON ph.changed_by = phu.user_id
                          WHERE $service_where_clause
                          ORDER BY ms.service_name ASC
                          LIMIT ?, ?";
    
    // Add price_list_id for subquery and pagination params
    $service_params[] = $price_list_id;
    $service_params[] = $service_offset;
    $service_params[] = $service_limit;
    $service_param_types .= 'iii';
    
    $stmt = $mysqli->prepare($service_prices_sql);
    if (!empty($service_params)) {
        $stmt->bind_param($service_param_types, ...$service_params);
    }
    $stmt->execute();
    $service_prices_result = $stmt->get_result();
    
    // Use fetch_assoc in a loop instead of fetch_all
    while ($row = $service_prices_result->fetch_assoc()) {
        $service_prices[] = $row;
    }
    
    // Get total service count for pagination
    $service_count_sql = "SELECT COUNT(*) as total 
                         FROM medical_service_prices msp
                         JOIN medical_services ms ON msp.medical_service_id = ms.medical_service_id
                         WHERE msp.price_list_id = ? AND msp.is_active = 1";
    
    $service_count_params = [$price_list_id];
    $service_count_types = 'i';
    
    if ($category_id && $entity_type == 'SERVICE') {
        $service_count_sql .= " AND ms.service_category_id = ?";
        $service_count_params[] = $category_id;
        $service_count_types .= 'i';
    }
    
    if (!empty($q) && ($entity_type == '' || $entity_type == 'SERVICE')) {
        $service_count_sql .= " AND (ms.service_name LIKE ? OR ms.service_code LIKE ? OR ms.service_description LIKE ?)";
        $service_count_params[] = "%$q%";
        $service_count_params[] = "%$q%";
        $service_count_params[] = "%$q%";
        $service_count_types .= 'sss';
    }
    
    $stmt = $mysqli->prepare($service_count_sql);
    if (!empty($service_count_params)) {
        $stmt->bind_param($service_count_types, ...$service_count_params);
    }
    $stmt->execute();
    $service_count_result = $stmt->get_result();
    $service_count_row = $service_count_result->fetch_assoc();
    $total_services = $service_count_row['total'] ?? 0;
    $service_total_pages = ceil($total_services / $service_limit);
}

// Get categories for dropdown
$item_categories_sql = "SELECT category_id, category_name FROM inventory_categories WHERE category_is_active = 1 ORDER BY category_name";
$item_categories_result = $mysqli->query($item_categories_sql);

$service_categories_sql = "SELECT category_id, category_name FROM medical_service_categories WHERE is_active = 1 ORDER BY category_name";
$service_categories_result = $mysqli->query($service_categories_sql);

// Get price statistics (simplified to avoid memory issues)
$price_stats_sql = "SELECT 
                   COUNT(DISTINCT ip.item_price_id) as total_items,
                   COUNT(DISTINCT msp.service_price_id) as total_services
                   FROM price_lists pl
                   LEFT JOIN item_prices ip ON pl.price_list_id = ip.price_list_id AND ip.is_active = 1
                   LEFT JOIN medical_service_prices msp ON pl.price_list_id = msp.price_list_id AND msp.is_active = 1
                   WHERE pl.price_list_id = ?";

$stmt = $mysqli->prepare($price_stats_sql);
$stmt->bind_param('i', $price_list_id);
$stmt->execute();
$price_stats_result = $stmt->get_result();
$price_stats = $price_stats_result->fetch_assoc();
?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h3 class="card-title mt-2 mb-0 text-white">
                    <i class="fas fa-list mr-2"></i>Price List Items
                </h3>
                <small class="text-light">
                    <?php echo htmlspecialchars($list_name); ?> 
                    <span class="badge badge-<?php echo $payer_type == 'CASH' ? 'success' : 'info'; ?> ml-2">
                        <?php echo $payer_type; ?>
                    </span>
                    <?php if($company_name): ?>
                        <span class="ml-2">• <?php echo htmlspecialchars($company_name); ?></span>
                    <?php endif; ?>
                </small>
            </div>
            <div class="card-tools">
                <a href="price_management.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Lists
                </a>
                <a href="bulk_price_update.php?price_list_id=<?php echo $price_list_id; ?>" class="btn btn-info ml-2">
                    <i class="fas fa-sync-alt mr-2"></i>Bulk Update
                </a>
                <button type="button" class="btn btn-primary ml-2" data-toggle="modal" data-target="#addItemsModal">
                    <i class="fas fa-plus mr-2"></i>Add Items
                </button>
            </div>
        </div>
    </div>
    
    <!-- Add Items Modal -->
    <div class="modal fade" id="addItemsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-plus mr-2"></i>Add Items to Price List
                    </h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <form action="price_list_add_items.php" method="POST">
                    <input type="hidden" name="price_list_id" value="<?php echo $price_list_id; ?>">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Item Type</label>
                                    <select name="entity_type" class="form-control" id="entityTypeSelect" required>
                                        <option value="">Select Type</option>
                                        <option value="ITEM">Inventory Item</option>
                                        <option value="SERVICE">Medical Service</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Pricing Strategy</label>
                                    <select name="pricing_strategy" class="form-control">
                                        <option value="MARKUP_PERCENTAGE">Markup Percentage</option>
                                        <option value="FIXED_PRICE">Fixed Price</option>
                                        <option value="DISCOUNT_PERCENTAGE">Discount Percentage</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div id="itemSection" style="display: none;">
                            <div class="form-group">
                                <label>Select Items</label>
                                <select name="item_ids[]" class="form-control select2" multiple style="width: 100%;">
                                    <option value="">Search items...</option>
                                    <?php
                                    $items_sql = "SELECT item_id, item_name, item_code, item_unit_price 
                                                FROM inventory_items 
                                                WHERE item_status != 'Discontinued' 
                                                AND item_id NOT IN (
                                                    SELECT item_id FROM item_prices 
                                                    WHERE price_list_id = ? AND is_active = 1
                                                )
                                                ORDER BY item_name";
                                    $stmt = $mysqli->prepare($items_sql);
                                    $stmt->bind_param('i', $price_list_id);
                                    $stmt->execute();
                                    $items_result = $stmt->get_result();
                                    while($item = $items_result->fetch_assoc()): ?>
                                        <option value="<?php echo $item['item_id']; ?>">
                                            <?php echo htmlspecialchars($item['item_name'] . " (" . $item['item_code'] . ") - " . number_format($item['item_unit_price'], 2)); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Markup/Discount Value</label>
                                <div class="input-group">
                                    <input type="number" name="markup_value" class="form-control" step="0.01" min="0" value="20">
                                    <div class="input-group-append">
                                        <span class="input-group-text">%</span>
                                    </div>
                                </div>
                                <small class="text-muted">Percentage to add to/deduct from base price</small>
                            </div>
                        </div>
                        
                        <div id="serviceSection" style="display: none;">
                            <div class="form-group">
                                <label>Select Services</label>
                                <select name="service_ids[]" class="form-control select2" multiple style="width: 100%;">
                                    <option value="">Search services...</option>
                                    <?php
                                    $services_sql = "SELECT medical_service_id, service_name, service_code, fee_amount 
                                                   FROM medical_services 
                                                   WHERE is_active = 1
                                                   AND medical_service_id NOT IN (
                                                       SELECT medical_service_id FROM medical_service_prices 
                                                       WHERE price_list_id = ? AND is_active = 1
                                                   )
                                                   ORDER BY service_name";
                                    $stmt = $mysqli->prepare($services_sql);
                                    $stmt->bind_param('i', $price_list_id);
                                    $stmt->execute();
                                    $services_result = $stmt->get_result();
                                    while($service = $services_result->fetch_assoc()): ?>
                                        <option value="<?php echo $service['medical_service_id']; ?>">
                                            <?php echo htmlspecialchars($service['service_name'] . " (" . $service['service_code'] . ") - " . number_format($service['fee_amount'], 2)); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Markup/Discount Value</label>
                                <div class="input-group">
                                    <input type="number" name="markup_value_service" class="form-control" step="0.01" min="0" value="15">
                                    <div class="input-group-append">
                                        <span class="input-group-text">%</span>
                                    </div>
                                </div>
                                <small class="text-muted">Percentage to add to/deduct from base price</small>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>Notes</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="Optional notes about these price additions"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Selected Items</button>
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
    
    <!-- Statistics Row -->
    <div class="row mt-3 mx-2">
        <div class="col-md-3 mb-3">
            <div class="card bg-primary text-white">
                <div class="card-body py-2">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="font-weight-bold mb-0"><?php echo ($price_stats['total_items'] ?? 0) + ($price_stats['total_services'] ?? 0); ?></h4>
                            <small>Total Items/Services</small>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-boxes fa-2x"></i>
                        </div>
                    </div>
                </div>
                <div class="card-footer p-1">
                    <small>
                        <i class="fas fa-cube mr-1"></i> <?php echo $price_stats['total_items'] ?? 0; ?> Items
                        <i class="fas fa-stethoscope ml-2 mr-1"></i> <?php echo $price_stats['total_services'] ?? 0; ?> Services
                    </small>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 mb-3">
            <div class="card bg-info text-white">
                <div class="card-body py-2">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h5 class="font-weight-bold mb-0">Showing</h5>
                            <small><?php echo count($item_prices); ?> Items</small>
                            <small class="d-block"><?php echo count($service_prices); ?> Services</small>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-eye fa-2x"></i>
                        </div>
                    </div>
                </div>
                <div class="card-footer p-1">
                    <small>
                        <?php if(isset($total_items)): ?>
                        <i class="fas fa-cube mr-1"></i> <?php echo $total_items; ?> total items
                        <?php endif; ?>
                    </small>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 mb-3">
            <div class="card bg-success text-white">
                <div class="card-body py-2">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h5 class="font-weight-bold mb-0">Quick Actions</h5>
                            <div class="btn-group-vertical btn-block">
                                <button type="button" class="btn btn-sm btn-light mb-1" data-toggle="modal" data-target="#addItemsModal">
                                    <i class="fas fa-plus mr-1"></i>Add Items
                                </button>
                                <a href="bulk_price_update.php?price_list_id=<?php echo $price_list_id; ?>" class="btn btn-sm btn-light mb-1">
                                    <i class="fas fa-sync-alt mr-1"></i>Bulk Update
                                </a>
                            </div>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-bolt fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 mb-3">
            <div class="card bg-warning text-white">
                <div class="card-body py-2">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h5 class="font-weight-bold mb-0">Export Options</h5>
                            <div class="btn-group-vertical btn-block">
                                <a href="price_export.php?price_list_id=<?php echo $price_list_id; ?>&format=csv" class="btn btn-sm btn-light mb-1">
                                    <i class="fas fa-file-csv mr-1"></i>Export as CSV
                                </a>
                                <a href="price_export.php?price_list_id=<?php echo $price_list_id; ?>" class="btn btn-sm btn-light">
                                    <i class="fas fa-file-export mr-1"></i>Export All
                                </a>
                            </div>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-download fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Search and Filter Section -->
    <div class="card-header pb-2 pt-3">
        <form autocomplete="off" method="GET">
            <input type="hidden" name="id" value="<?php echo $price_list_id; ?>">
            <input type="hidden" name="item_page" value="1">
            <input type="hidden" name="service_page" value="1">
            
            <div class="row">
                <div class="col-md-5">
                    <div class="form-group">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search items/services..." autofocus>
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
                            <a href="price_management.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left mr-2"></i>Back to Lists
                            </a>
                            <button type="button" class="btn btn-primary ml-2" data-toggle="modal" data-target="#addItemsModal">
                                <i class="fas fa-plus mr-2"></i>Add Items
                            </button>
                            <a href="bulk_price_update.php?price_list_id=<?php echo $price_list_id; ?>" class="btn btn-info ml-2">
                                <i class="fas fa-sync-alt mr-2"></i>Bulk Update
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="collapse <?php if ($category_id) { echo "show"; } ?>" id="advancedFilter">
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Item Category</label>
                            <select class="form-control select2" name="category_id" onchange="this.form.submit()">
                                <option value="">- All Item Categories -</option>
                                <?php 
                                $item_categories_result->data_seek(0);
                                while($category = $item_categories_result->fetch_assoc()): ?>
                                    <option value="<?php echo $category['category_id']; ?>" 
                                        <?php if ($category_id == $category['category_id'] && $entity_type == 'ITEM') echo "selected"; ?>>
                                        <?php echo htmlspecialchars($category['category_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Service Category</label>
                            <select class="form-control select2" name="category_id" onchange="this.form.submit()">
                                <option value="">- All Service Categories -</option>
                                <?php 
                                $service_categories_result->data_seek(0);
                                while($category = $service_categories_result->fetch_assoc()): ?>
                                    <option value="<?php echo $category['category_id']; ?>"
                                        <?php if ($category_id == $category['category_id'] && $entity_type == 'SERVICE') echo "selected"; ?>>
                                        <?php echo htmlspecialchars($category['category_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Entity Type</label>
                            <select class="form-control" name="type" onchange="this.form.submit()">
                                <option value="">- All Types -</option>
                                <option value="ITEM" <?php if ($entity_type == 'ITEM') echo "selected"; ?>>Items Only</option>
                                <option value="SERVICE" <?php if ($entity_type == 'SERVICE') echo "selected"; ?>>Services Only</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-12">
                        <div class="btn-group btn-block">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter mr-2"></i>Apply Filter
                            </button>
                            <a href="price_list_items.php?id=<?php echo $price_list_id; ?>" class="btn btn-secondary">
                                <i class="fas fa-times mr-2"></i>Clear
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
    
    <div class="row mx-2">
        <!-- Items Tab -->
        <div class="col-md-12">
            <div class="card">
                <div class="card-header bg-light">
                    <ul class="nav nav-tabs card-header-tabs">
                        <li class="nav-item">
                            <a class="nav-link active" data-toggle="tab" href="#itemsTab">
                                <i class="fas fa-cube mr-2"></i>Items
                                <span class="badge badge-primary ml-2"><?php echo count($item_prices); ?></span>
                                <?php if(isset($total_items) && $total_items > count($item_prices)): ?>
                                    <small class="text-muted ml-1">(of <?php echo $total_items; ?>)</small>
                                <?php endif; ?>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" data-toggle="tab" href="#servicesTab">
                                <i class="fas fa-stethoscope mr-2"></i>Services
                                <span class="badge badge-success ml-2"><?php echo count($service_prices); ?></span>
                                <?php if(isset($total_services) && $total_services > count($service_prices)): ?>
                                    <small class="text-muted ml-1">(of <?php echo $total_services; ?>)</small>
                                <?php endif; ?>
                            </a>
                        </li>
                    </ul>
                </div>
                <div class="card-body">
                    <div class="tab-content">
                        <!-- Items Tab Content -->
                        <div class="tab-pane fade show active" id="itemsTab">
                            <?php if (empty($item_prices)): ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-cube fa-3x text-muted mb-3"></i>
                                    <h5 class="text-muted">No items in this price list</h5>
                                    <p class="text-muted">
                                        Add items to this price list to see them here.
                                    </p>
                                    <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#addItemsModal">
                                        <i class="fas fa-plus mr-2"></i>Add Items
                                    </button>
                                </div>
                            <?php else: ?>
                                <!-- Items Pagination -->
                                <?php if(isset($item_total_pages) && $item_total_pages > 1): ?>
                                <div class="row mb-3">
                                    <div class="col-md-12">
                                        <nav>
                                            <ul class="pagination pagination-sm justify-content-center">
                                                <li class="page-item <?php echo $item_page == 1 ? 'disabled' : ''; ?>">
                                                    <a class="page-link" href="?id=<?php echo $price_list_id; ?>&item_page=1&service_page=<?php echo $service_page; ?>&q=<?php echo urlencode($q); ?>&category_id=<?php echo $category_id; ?>&type=<?php echo $entity_type; ?>">First</a>
                                                </li>
                                                <li class="page-item <?php echo $item_page == 1 ? 'disabled' : ''; ?>">
                                                    <a class="page-link" href="?id=<?php echo $price_list_id; ?>&item_page=<?php echo $item_page - 1; ?>&service_page=<?php echo $service_page; ?>&q=<?php echo urlencode($q); ?>&category_id=<?php echo $category_id; ?>&type=<?php echo $entity_type; ?>">Previous</a>
                                                </li>
                                                
                                                <?php 
                                                $start_page = max(1, $item_page - 2);
                                                $end_page = min($item_total_pages, $item_page + 2);
                                                for($p = $start_page; $p <= $end_page; $p++): ?>
                                                <li class="page-item <?php echo $p == $item_page ? 'active' : ''; ?>">
                                                    <a class="page-link" href="?id=<?php echo $price_list_id; ?>&item_page=<?php echo $p; ?>&service_page=<?php echo $service_page; ?>&q=<?php echo urlencode($q); ?>&category_id=<?php echo $category_id; ?>&type=<?php echo $entity_type; ?>"><?php echo $p; ?></a>
                                                </li>
                                                <?php endfor; ?>
                                                
                                                <li class="page-item <?php echo $item_page == $item_total_pages ? 'disabled' : ''; ?>">
                                                    <a class="page-link" href="?id=<?php echo $price_list_id; ?>&item_page=<?php echo $item_page + 1; ?>&service_page=<?php echo $service_page; ?>&q=<?php echo urlencode($q); ?>&category_id=<?php echo $category_id; ?>&type=<?php echo $entity_type; ?>">Next</a>
                                                </li>
                                                <li class="page-item <?php echo $item_page == $item_total_pages ? 'disabled' : ''; ?>">
                                                    <a class="page-link" href="?id=<?php echo $price_list_id; ?>&item_page=<?php echo $item_total_pages; ?>&service_page=<?php echo $service_page; ?>&q=<?php echo urlencode($q); ?>&category_id=<?php echo $category_id; ?>&type=<?php echo $entity_type; ?>">Last</a>
                                                </li>
                                            </ul>
                                        </nav>
                                        <p class="text-center text-muted small">
                                            Page <?php echo $item_page; ?> of <?php echo $item_total_pages; ?> 
                                            (Showing <?php echo count($item_prices); ?> of <?php echo $total_items; ?> items)
                                        </p>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Item Name</th>
                                                <th>Code</th>
                                                <th>Category</th>
                                                <th>Base Price</th>
                                                <th>List Price</th>
                                                <th>Markup/Discount</th>
                                                <th>Stock</th>
                                                <th>Last Updated</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach($item_prices as $item): 
                                                $price_difference = $item['price'] - $item['base_price'];
                                                $percentage_change = $item['base_price'] > 0 ? ($price_difference / $item['base_price']) * 100 : 0;
                                            ?>
                                            <tr>
                                                <td>
                                                    <div class="font-weight-bold">
                                                        <?php echo htmlspecialchars($item['item_name']); ?>
                                                    </div>
                                                    <small class="text-muted">
                                                        <?php echo htmlspecialchars(substr($item['item_description'] ?? '', 0, 50)); ?>...
                                                    </small>
                                                </td>
                                                <td>
                                                    <span class="badge badge-light"><?php echo htmlspecialchars($item['item_code']); ?></span>
                                                </td>
                                                <td>
                                                    <?php if($item['item_category_name']): ?>
                                                        <span class="badge badge-info"><?php echo htmlspecialchars($item['item_category_name']); ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="font-weight-bold">
                                                        <?php echo number_format($item['base_price'], 2); ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="font-weight-bold text-success">
                                                        <?php echo number_format($item['price'], 2); ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge badge-<?php echo $percentage_change >= 0 ? 'success' : 'danger'; ?>">
                                                        <?php echo $percentage_change >= 0 ? '+' : ''; ?><?php echo number_format($percentage_change, 1); ?>%
                                                    </span>
                                                    <div class="small text-muted">
                                                        <?php echo $price_difference >= 0 ? '+' : ''; ?><?php echo number_format($price_difference, 2); ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge badge-<?php 
                                                        if($item['stock_quantity'] <= 0) echo 'danger';
                                                        elseif($item['stock_quantity'] <= 10) echo 'warning';
                                                        else echo 'success';
                                                    ?>">
                                                        <?php echo $item['stock_quantity']; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if($item['last_price_change']): ?>
                                                        <div class="small">
                                                            <?php echo date('M j, Y', strtotime($item['last_price_change'])); ?>
                                                        </div>
                                                        <div class="small text-muted">
                                                            <?php echo htmlspecialchars($item['last_changed_by'] ?? 'System'); ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="text-muted">Never</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group">
                                                        <button type="button" class="btn btn-sm btn-outline-primary" data-toggle="modal" data-target="#editPriceModal<?php echo $item['item_price_id']; ?>">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <a href="price_management.php?action=remove_item&id=<?php echo $item['item_price_id']; ?>&price_list_id=<?php echo $price_list_id; ?>" 
                                                           class="btn btn-sm btn-outline-danger" 
                                                           onclick="return confirm('Remove this item from price list?')">
                                                            <i class="fas fa-trash"></i>
                                                        </a>
                                                    </div>
                                                    
                                                    <!-- Edit Price Modal -->
                                                    <div class="modal fade" id="editPriceModal<?php echo $item['item_price_id']; ?>" tabindex="-1">
                                                        <div class="modal-dialog">
                                                            <div class="modal-content">
                                                                <div class="modal-header">
                                                                    <h5 class="modal-title">Edit Price: <?php echo htmlspecialchars($item['item_name']); ?></h5>
                                                                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                                                                </div>
                                                                <form action="price_update.php" method="POST">
                                                                    <input type="hidden" name="action" value="update_item_price">
                                                                    <input type="hidden" name="item_price_id" value="<?php echo $item['item_price_id']; ?>">
                                                                    <input type="hidden" name="price_list_id" value="<?php echo $price_list_id; ?>">
                                                                    <div class="modal-body">
                                                                        <div class="form-group">
                                                                            <label>Current Base Price</label>
                                                                            <input type="text" class="form-control" value="<?php echo number_format($item['base_price'], 2); ?>" readonly>
                                                                        </div>
                                                                        <div class="form-group">
                                                                            <label>New Price for this List</label>
                                                                            <input type="number" name="new_price" class="form-control" step="0.01" min="0" value="<?php echo number_format($item['price'], 2); ?>" required>
                                                                        </div>
                                                                        <div class="form-group">
                                                                            <label>Price Adjustment Type</label>
                                                                            <select name="adjustment_type" class="form-control">
                                                                                <option value="FIXED">Fixed Price</option>
                                                                                <option value="PERCENTAGE_INCREASE">Percentage Increase</option>
                                                                                <option value="PERCENTAGE_DECREASE">Percentage Decrease</option>
                                                                            </select>
                                                                        </div>
                                                                        <div class="form-group">
                                                                            <label>Reason for Change</label>
                                                                            <textarea name="reason" class="form-control" rows="2" placeholder="Optional reason for price change"></textarea>
                                                                        </div>
                                                                    </div>
                                                                    <div class="modal-footer">
                                                                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                                                                        <button type="submit" class="btn btn-primary">Update Price</button>
                                                                    </div>
                                                                </form>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- Bottom Pagination -->
                                <?php if(isset($item_total_pages) && $item_total_pages > 1): ?>
                                <div class="row mt-3">
                                    <div class="col-md-12">
                                        <nav>
                                            <ul class="pagination pagination-sm justify-content-center">
                                                <li class="page-item <?php echo $item_page == 1 ? 'disabled' : ''; ?>">
                                                    <a class="page-link" href="?id=<?php echo $price_list_id; ?>&item_page=1&service_page=<?php echo $service_page; ?>&q=<?php echo urlencode($q); ?>&category_id=<?php echo $category_id; ?>&type=<?php echo $entity_type; ?>">First</a>
                                                </li>
                                                <li class="page-item <?php echo $item_page == 1 ? 'disabled' : ''; ?>">
                                                    <a class="page-link" href="?id=<?php echo $price_list_id; ?>&item_page=<?php echo $item_page - 1; ?>&service_page=<?php echo $service_page; ?>&q=<?php echo urlencode($q); ?>&category_id=<?php echo $category_id; ?>&type=<?php echo $entity_type; ?>">Previous</a>
                                                </li>
                                                
                                                <?php 
                                                $start_page = max(1, $item_page - 2);
                                                $end_page = min($item_total_pages, $item_page + 2);
                                                for($p = $start_page; $p <= $end_page; $p++): ?>
                                                <li class="page-item <?php echo $p == $item_page ? 'active' : ''; ?>">
                                                    <a class="page-link" href="?id=<?php echo $price_list_id; ?>&item_page=<?php echo $p; ?>&service_page=<?php echo $service_page; ?>&q=<?php echo urlencode($q); ?>&category_id=<?php echo $category_id; ?>&type=<?php echo $entity_type; ?>"><?php echo $p; ?></a>
                                                </li>
                                                <?php endfor; ?>
                                                
                                                <li class="page-item <?php echo $item_page == $item_total_pages ? 'disabled' : ''; ?>">
                                                    <a class="page-link" href="?id=<?php echo $price_list_id; ?>&item_page=<?php echo $item_page + 1; ?>&service_page=<?php echo $service_page; ?>&q=<?php echo urlencode($q); ?>&category_id=<?php echo $category_id; ?>&type=<?php echo $entity_type; ?>">Next</a>
                                                </li>
                                                <li class="page_item <?php echo $item_page == $item_total_pages ? 'disabled' : ''; ?>">
                                                    <a class="page-link" href="?id=<?php echo $price_list_id; ?>&item_page=<?php echo $item_total_pages; ?>&service_page=<?php echo $service_page; ?>&q=<?php echo urlencode($q); ?>&category_id=<?php echo $category_id; ?>&type=<?php echo $entity_type; ?>">Last</a>
                                                </li>
                                            </ul>
                                        </nav>
                                    </div>
                                </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Services Tab Content -->
                        <div class="tab-pane fade" id="servicesTab">
                            <?php if (empty($service_prices)): ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-stethoscope fa-3x text-muted mb-3"></i>
                                    <h5 class="text-muted">No services in this price list</h5>
                                    <p class="text-muted">
                                        Add services to this price list to see them here.
                                    </p>
                                    <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#addItemsModal">
                                        <i class="fas fa-plus mr-2"></i>Add Services
                                    </button>
                                </div>
                            <?php else: ?>
                                <!-- Services Pagination -->
                                <?php if(isset($service_total_pages) && $service_total_pages > 1): ?>
                                <div class="row mb-3">
                                    <div class="col-md-12">
                                        <nav>
                                            <ul class="pagination pagination-sm justify-content-center">
                                                <li class="page-item <?php echo $service_page == 1 ? 'disabled' : ''; ?>">
                                                    <a class="page-link" href="?id=<?php echo $price_list_id; ?>&item_page=<?php echo $item_page; ?>&service_page=1&q=<?php echo urlencode($q); ?>&category_id=<?php echo $category_id; ?>&type=<?php echo $entity_type; ?>">First</a>
                                                </li>
                                                <li class="page-item <?php echo $service_page == 1 ? 'disabled' : ''; ?>">
                                                    <a class="page-link" href="?id=<?php echo $price_list_id; ?>&item_page=<?php echo $item_page; ?>&service_page=<?php echo $service_page - 1; ?>&q=<?php echo urlencode($q); ?>&category_id=<?php echo $category_id; ?>&type=<?php echo $entity_type; ?>">Previous</a>
                                                </li>
                                                
                                                <?php 
                                                $start_page = max(1, $service_page - 2);
                                                $end_page = min($service_total_pages, $service_page + 2);
                                                for($p = $start_page; $p <= $end_page; $p++): ?>
                                                <li class="page-item <?php echo $p == $service_page ? 'active' : ''; ?>">
                                                    <a class="page-link" href="?id=<?php echo $price_list_id; ?>&item_page=<?php echo $item_page; ?>&service_page=<?php echo $p; ?>&q=<?php echo urlencode($q); ?>&category_id=<?php echo $category_id; ?>&type=<?php echo $entity_type; ?>"><?php echo $p; ?></a>
                                                </li>
                                                <?php endfor; ?>
                                                
                                                <li class="page-item <?php echo $service_page == $service_total_pages ? 'disabled' : ''; ?>">
                                                    <a class="page-link" href="?id=<?php echo $price_list_id; ?>&item_page=<?php echo $item_page; ?>&service_page=<?php echo $service_page + 1; ?>&q=<?php echo urlencode($q); ?>&category_id=<?php echo $category_id; ?>&type=<?php echo $entity_type; ?>">Next</a>
                                                </li>
                                                <li class="page-item <?php echo $service_page == $service_total_pages ? 'disabled' : ''; ?>">
                                                    <a class="page-link" href="?id=<?php echo $price_list_id; ?>&item_page=<?php echo $item_page; ?>&service_page=<?php echo $service_total_pages; ?>&q=<?php echo urlencode($q); ?>&category_id=<?php echo $category_id; ?>&type=<?php echo $entity_type; ?>">Last</a>
                                                </li>
                                            </ul>
                                        </nav>
                                        <p class="text-center text-muted small">
                                            Page <?php echo $service_page; ?> of <?php echo $service_total_pages; ?> 
                                            (Showing <?php echo count($service_prices); ?> of <?php echo $total_services; ?> services)
                                        </p>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Service Name</th>
                                                <th>Code</th>
                                                <th>Category</th>
                                                <th>Base Fee</th>
                                                <th>List Price</th>
                                                <th>Markup/Discount</th>
                                                <th>Status</th>
                                                <th>Last Updated</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach($service_prices as $service): 
                                                $price_difference = $service['price'] - $service['base_price'];
                                                $percentage_change = $service['base_price'] > 0 ? ($price_difference / $service['base_price']) * 100 : 0;
                                            ?>
                                            <tr>
                                                <td>
                                                    <div class="font-weight-bold">
                                                        <?php echo htmlspecialchars($service['service_name']); ?>
                                                    </div>
                                                    <small class="text-muted">
                                                        <?php echo htmlspecialchars(substr($service['service_description'] ?? '', 0, 50)); ?>...
                                                    </small>
                                                </td>
                                                <td>
                                                    <span class="badge badge-light"><?php echo htmlspecialchars($service['service_code']); ?></span>
                                                </td>
                                                <td>
                                                    <?php if($service['service_category_name']): ?>
                                                        <span class="badge badge-info"><?php echo htmlspecialchars($service['service_category_name']); ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="font-weight-bold">
                                                        <?php echo number_format($service['base_price'], 2); ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="font-weight-bold text-success">
                                                        <?php echo number_format($service['price'], 2); ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge badge-<?php echo $percentage_change >= 0 ? 'success' : 'danger'; ?>">
                                                        <?php echo $percentage_change >= 0 ? '+' : ''; ?><?php echo number_format($percentage_change, 1); ?>%
                                                    </span>
                                                    <div class="small text-muted">
                                                        <?php echo $price_difference >= 0 ? '+' : ''; ?><?php echo number_format($price_difference, 2); ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge badge-<?php echo $service['service_active'] ? 'success' : 'secondary'; ?>">
                                                        <?php echo $service['service_active'] ? 'Active' : 'Inactive'; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if($service['last_price_change']): ?>
                                                        <div class="small">
                                                            <?php echo date('M j, Y', strtotime($service['last_price_change'])); ?>
                                                        </div>
                                                        <div class="small text-muted">
                                                            <?php echo htmlspecialchars($service['last_changed_by'] ?? 'System'); ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="text-muted">Never</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group">
                                                        <button type="button" class="btn btn-sm btn-outline-primary" data-toggle="modal" data-target="#editServicePriceModal<?php echo $service['service_price_id']; ?>">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <a href="price_management.php?action=remove_service&id=<?php echo $service['service_price_id']; ?>&price_list_id=<?php echo $price_list_id; ?>" 
                                                           class="btn btn-sm btn-outline-danger" 
                                                           onclick="return confirm('Remove this service from price list?')">
                                                            <i class="fas fa-trash"></i>
                                                        </a>
                                                    </div>
                                                    
                                                    <!-- Edit Service Price Modal -->
                                                    <div class="modal fade" id="editServicePriceModal<?php echo $service['service_price_id']; ?>" tabindex="-1">
                                                        <div class="modal-dialog">
                                                            <div class="modal-content">
                                                                <div class="modal-header">
                                                                    <h5 class="modal-title">Edit Price: <?php echo htmlspecialchars($service['service_name']); ?></h5>
                                                                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                                                                </div>
                                                                <form action="price_update.php" method="POST">
                                                                    <input type="hidden" name="action" value="update_service_price">
                                                                    <input type="hidden" name="service_price_id" value="<?php echo $service['service_price_id']; ?>">
                                                                    <input type="hidden" name="price_list_id" value="<?php echo $price_list_id; ?>">
                                                                    <div class="modal-body">
                                                                        <div class="form-group">
                                                                            <label>Current Base Fee</label>
                                                                            <input type="text" class="form-control" value="<?php echo number_format($service['base_price'], 2); ?>" readonly>
                                                                        </div>
                                                                        <div class="form-group">
                                                                            <label>New Price for this List</label>
                                                                            <input type="number" name="new_price" class="form-control" step="0.01" min="0" value="<?php echo number_format($service['price'], 2); ?>" required>
                                                                        </div>
                                                                        <div class="form-group">
                                                                            <label>Price Adjustment Type</label>
                                                                            <select name="adjustment_type" class="form-control">
                                                                                <option value="FIXED">Fixed Price</option>
                                                                                <option value="PERCENTAGE_INCREASE">Percentage Increase</option>
                                                                                <option value="PERCENTAGE_DECREASE">Percentage Decrease</option>
                                                                            </select>
                                                                        </div>
                                                                        <div class="form-group">
                                                                            <label>Reason for Change</label>
                                                                            <textarea name="reason" class="form-control" rows="2" placeholder="Optional reason for price change"></textarea>
                                                                        </div>
                                                                    </div>
                                                                    <div class="modal-footer">
                                                                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                                                                        <button type="submit" class="btn btn-primary">Update Price</button>
                                                                    </div>
                                                                </form>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- Bottom Pagination -->
                                <?php if(isset($service_total_pages) && $service_total_pages > 1): ?>
                                <div class="row mt-3">
                                    <div class="col-md-12">
                                        <nav>
                                            <ul class="pagination pagination-sm justify-content-center">
                                                <li class="page-item <?php echo $service_page == 1 ? 'disabled' : ''; ?>">
                                                    <a class="page-link" href="?id=<?php echo $price_list_id; ?>&item_page=<?php echo $item_page; ?>&service_page=1&q=<?php echo urlencode($q); ?>&category_id=<?php echo $category_id; ?>&type=<?php echo $entity_type; ?>">First</a>
                                                </li>
                                                <li class="page-item <?php echo $service_page == 1 ? 'disabled' : ''; ?>">
                                                    <a class="page-link" href="?id=<?php echo $price_list_id; ?>&item_page=<?php echo $item_page; ?>&service_page=<?php echo $service_page - 1; ?>&q=<?php echo urlencode($q); ?>&category_id=<?php echo $category_id; ?>&type=<?php echo $entity_type; ?>">Previous</a>
                                                </li>
                                                
                                                <?php 
                                                $start_page = max(1, $service_page - 2);
                                                $end_page = min($service_total_pages, $service_page + 2);
                                                for($p = $start_page; $p <= $end_page; $p++): ?>
                                                <li class="page-item <?php echo $p == $service_page ? 'active' : ''; ?>">
                                                    <a class="page-link" href="?id=<?php echo $price_list_id; ?>&item_page=<?php echo $item_page; ?>&service_page=<?php echo $p; ?>&q=<?php echo urlencode($q); ?>&category_id=<?php echo $category_id; ?>&type=<?php echo $entity_type; ?>"><?php echo $p; ?></a>
                                                </li>
                                                <?php endfor; ?>
                                                
                                                <li class="page-item <?php echo $service_page == $service_total_pages ? 'disabled' : ''; ?>">
                                                    <a class="page-link" href="?id=<?php echo $price_list_id; ?>&item_page=<?php echo $item_page; ?>&service_page=<?php echo $service_page + 1; ?>&q=<?php echo urlencode($q); ?>&category_id=<?php echo $category_id; ?>&type=<?php echo $entity_type; ?>">Next</a>
                                                </li>
                                                <li class="page-item <?php echo $service_page == $service_total_pages ? 'disabled' : ''; ?>">
                                                    <a class="page-link" href="?id=<?php echo $price_list_id; ?>&item_page=<?php echo $item_page; ?>&service_page=<?php echo $service_total_pages; ?>&q=<?php echo urlencode($q); ?>&category_id=<?php echo $category_id; ?>&type=<?php echo $entity_type; ?>">Last</a>
                                                </li>
                                            </ul>
                                        </nav>
                                    </div>
                                </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Export Options -->
    <div class="row mx-2 mt-3">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header bg-light">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-file-export mr-2"></i>Export Options
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3">
                            <a href="price_export.php?price_list_id=<?php echo $price_list_id; ?>&format=csv" class="btn btn-outline-success btn-block">
                                <i class="fas fa-file-csv mr-2"></i>Export as CSV
                            </a>
                        </div>
                        <div class="col-md-3">
                            <a href="price_export.php?price_list_id=<?php echo $price_list_id; ?>&format=excel" class="btn btn-outline-primary btn-block">
                                <i class="fas fa-file-excel mr-2"></i>Export as Excel
                            </a>
                        </div>
                        <div class="col-md-3">
                            <a href="price_export.php?price_list_id=<?php echo $price_list_id; ?>&format=pdf" class="btn btn-outline-danger btn-block">
                                <i class="fas fa-file-pdf mr-2"></i>Export as PDF
                            </a>
                        </div>
                        <div class="col-md-3">
                            <a href="price_print.php?price_list_id=<?php echo $price_list_id; ?>" class="btn btn-outline-dark btn-block" target="_blank">
                                <i class="fas fa-print mr-2"></i>Print Price List
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.select2').select2({
        theme: 'bootstrap4',
        width: '100%'
    });
    
    // Show/hide item/service sections based on selection
    $('#entityTypeSelect').change(function() {
        if ($(this).val() === 'ITEM') {
            $('#itemSection').show();
            $('#serviceSection').hide();
        } else if ($(this).val() === 'SERVICE') {
            $('#itemSection').hide();
            $('#serviceSection').show();
        } else {
            $('#itemSection').hide();
            $('#serviceSection').hide();
        }
    });
    
    // Initialize select2 for multi-select
    $('select[name="item_ids[]"], select[name="service_ids[]"]').select2({
        theme: 'bootstrap4',
        width: '100%',
        placeholder: 'Select items/services',
        allowClear: true
    });
    
    // Handle tab switching
    $('a[data-toggle="tab"]').on('shown.bs.tab', function (e) {
        // Store the active tab in localStorage
        localStorage.setItem('activePriceListTab', e.target.hash);
    });
    
    // Retrieve active tab from localStorage
    var activeTab = localStorage.getItem('activePriceListTab');
    if (activeTab) {
        $('.nav-tabs a[href="' + activeTab + '"]').tab('show');
    }
});

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + A to open add items modal
    if (e.ctrlKey && e.keyCode === 65) {
        e.preventDefault();
        $('#addItemsModal').modal('show');
    }
    // Ctrl + B to go back
    if (e.ctrlKey && e.keyCode === 66) {
        e.preventDefault();
        window.location.href = 'price_management.php';
    }
    // Ctrl + E to export
    if (e.ctrlKey && e.keyCode === 69) {
        e.preventDefault();
        window.location.href = 'price_export.php?price_list_id=<?php echo $price_list_id; ?>';
    }
});
</script>

<style>
.card .card-body {
    padding: 1rem;
}

.table td, .table th {
    vertical-align: middle;
}

.nav-tabs .nav-link {
    border: none;
    border-bottom: 3px solid transparent;
}

.nav-tabs .nav-link.active {
    border-bottom: 3px solid #007bff;
    background-color: transparent;
}

.badge {
    font-size: 0.8em;
    padding: 0.3em 0.6em;
}

.modal .select2-container {
    width: 100% !important;
}

.btn-group .btn-sm {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
}

.tab-content {
    padding: 1rem 0;
}

.pagination {
    margin-bottom: 0.5rem;
}

.page-item.active .page-link {
    background-color: #007bff;
    border-color: #007bff;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
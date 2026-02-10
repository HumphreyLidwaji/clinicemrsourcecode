<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/price_functions.php';

// Handle actions
$action = $_GET['action'] ?? '';
$billable_item_id = intval($_GET['id'] ?? 0);

// Delete billable item
if ($action == 'delete' && $billable_item_id) {
    $sql = "UPDATE billable_items SET is_active = 0 WHERE billable_item_id = ?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('i', $billable_item_id);
    
    if ($stmt->execute()) {
        $_SESSION['alert_message'] = "Billable item deactivated";
    } else {
        $_SESSION['alert_message'] = "Error deactivating billable item";
    }
    
    header("Location: billable_items.php");
    exit;
}

// Restore billable item
if ($action == 'restore' && $billable_item_id) {
    $sql = "UPDATE billable_items SET is_active = 1 WHERE billable_item_id = ?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('i', $billable_item_id);
    
    if ($stmt->execute()) {
        $_SESSION['alert_message'] = "Billable item restored";
    } else {
        $_SESSION['alert_message'] = "Error restoring billable item";
    }
    
    header("Location: billable_items.php");
    exit;
}

// Filter parameters
$status_filter = $_GET['status'] ?? 'active';
$type_filter = $_GET['type'] ?? '';
$category_filter = $_GET['category'] ?? '';
$q = sanitizeInput($_GET['q'] ?? '');

// Build search query
$where_conditions = ["1=1"];
$params = [];
$param_types = '';

if ($status_filter == 'active') {
    $where_conditions[] = "bi.is_active = 1";
} elseif ($status_filter == 'inactive') {
    $where_conditions[] = "bi.is_active = 0";
}

if ($type_filter) {
    $where_conditions[] = "bi.item_type = ?";
    $params[] = $type_filter;
    $param_types .= 's';
}

if ($category_filter) {
    $where_conditions[] = "bi.category_id = ?";
    $params[] = $category_filter;
    $param_types .= 'i';
}

if (!empty($q)) {
    $where_conditions[] = "(bi.item_name LIKE ? OR bi.item_code LIKE ? OR bi.item_description LIKE ?)";
    $params[] = "%$q%";
    $params[] = "%$q%";
    $params[] = "%$q%";
    $param_types .= 'sss';
}

$where_clause = implode(" AND ", $where_conditions);

// Get billable items
$sql = "SELECT SQL_CALC_FOUND_ROWS 
               bi.*,
               bc.category_name,
               creator.user_name as created_by_name,
               updater.user_name as updated_by_name,
               COUNT(DISTINCT pli.price_list_item_id) as price_list_count
        FROM billable_items bi
        LEFT JOIN billable_categories bc ON bi.category_id = bc.category_id
        LEFT JOIN price_list_items pli ON bi.billable_item_id = pli.billable_item_id 
            AND (pli.effective_to IS NULL OR pli.effective_to >= CURDATE())
        LEFT JOIN users creator ON bi.created_by = creator.user_id
        LEFT JOIN users updater ON bi.updated_by = updater.user_id
        WHERE $where_clause
        GROUP BY bi.billable_item_id
        ORDER BY bi.item_name ASC
        LIMIT $record_from, $record_to";

$stmt = $mysqli->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($param_types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get categories for filter
$categories_sql = "SELECT category_id, category_name FROM billable_categories WHERE is_active = 1 ORDER BY category_name";
$categories_result = $mysqli->query($categories_sql);

// Get statistics
$stats_sql = "SELECT 
    COUNT(*) as total_items,
    SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_items,
    SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_items,
    COUNT(DISTINCT item_type) as distinct_types,
    COUNT(DISTINCT category_id) as distinct_categories
    FROM billable_items";
$stats_result = $mysqli->query($stats_sql);
$stats = $stats_result->fetch_assoc();
?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-boxes mr-2"></i>Billable Items Catalog
        </h3>
        <div class="card-tools">
            <a href="price_management.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left mr-2"></i>Back to Price Management
            </a>
            <a href="billable_item_new.php" class="btn btn-success ml-2">
                <i class="fas fa-plus mr-2"></i>New Billable Item
            </a>
        </div>
    </div>
    
    <!-- Statistics Row -->
    <div class="row mt-3 mx-2">
        <div class="col-md-2 mb-3">
            <div class="card bg-primary text-white">
                <div class="card-body py-2">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="font-weight-bold mb-0"><?php echo $stats['total_items'] ?? 0; ?></h4>
                            <small>Total Items</small>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-box fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-2 mb-3">
            <div class="card bg-success text-white">
                <div class="card-body py-2">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="font-weight-bold mb-0"><?php echo $stats['active_items'] ?? 0; ?></h4>
                            <small>Active Items</small>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-check-circle fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-2 mb-3">
            <div class="card bg-info text-white">
                <div class="card-body py-2">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="font-weight-bold mb-0"><?php echo $stats['distinct_types'] ?? 0; ?></h4>
                            <small>Item Types</small>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-tags fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-2 mb-3">
            <div class="card bg-warning text-white">
                <div class="card-body py-2">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="font-weight-bold mb-0"><?php echo $stats['distinct_categories'] ?? 0; ?></h4>
                            <small>Categories</small>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-folder fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-4 mb-3">
            <div class="card bg-secondary text-white">
                <div class="card-body py-2">
                    <h6 class="font-weight-bold mb-1">Quick Actions</h6>
                    <div class="btn-group btn-block">
                        <a href="billable_item_new.php" class="btn btn-outline-light btn-sm">
                            <i class="fas fa-plus mr-1"></i>New Item
                        </a>
                        <a href="billable_item_import.php" class="btn btn-outline-light btn-sm ml-1">
                            <i class="fas fa-file-import mr-1"></i>Import
                        </a>
                        <a href="billable_item_export.php" class="btn btn-outline-light btn-sm ml-1">
                            <i class="fas fa-file-export mr-1"></i>Export
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card-header pb-2 pt-3">
        <form autocomplete="off" method="GET">
            <div class="row">
                <div class="col-md-4">
                    <div class="form-group">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search billable items..." autofocus>
                            <div class="input-group-append">
                                <button class="btn btn-secondary" type="button" data-toggle="collapse" data-target="#advancedFilter"><i class="fas fa-filter"></i></button>
                                <button class="btn btn-primary"><i class="fa fa-search"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-8">
                    <div class="btn-toolbar form-group float-right">
                        <div class="btn-group">
                            <button type="button" class="btn btn-light border" data-toggle="tooltip" title="Quick Actions">
                                <i class="fas fa-bolt text-warning"></i>
                            </button>
                            <a href="billable_item_new.php" class="btn btn-success">
                                <i class="fas fa-plus mr-2"></i>New Item
                            </a>
                            <a href="price_management.php" class="btn btn-primary ml-2">
                                <i class="fas fa-tags mr-2"></i>Price Lists
                            </a>
                            <div class="btn-group ml-2">
                                <button type="button" class="btn btn-info dropdown-toggle" data-toggle="dropdown">
                                    <i class="fas fa-download mr-2"></i>Export
                                </button>
                                <div class="dropdown-menu dropdown-menu-right">
                                    <a class="dropdown-item" href="billable_item_export.php?format=csv">
                                        <i class="fas fa-file-csv mr-2"></i>Export to CSV
                                    </a>
                                    <a class="dropdown-item" href="billable_item_export.php?format=excel">
                                        <i class="fas fa-file-excel mr-2"></i>Export to Excel
                                    </a>
                                    <a class="dropdown-item" href="billable_item_export.php?format=pdf">
                                        <i class="fas fa-file-pdf mr-2"></i>Export to PDF
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php if ($status_filter != 'active' || $type_filter || $category_filter) { echo "show"; } ?>" id="advancedFilter">
                <div class="row">
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Status</label>
                            <select class="form-control select2" name="status">
                                <option value="active" <?php if ($status_filter == "active") { echo "selected"; } ?>>Active Only</option>
                                <option value="inactive" <?php if ($status_filter == "inactive") { echo "selected"; } ?>>Inactive Only</option>
                                <option value="">All Status</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Item Type</label>
                            <select class="form-control select2" name="type">
                                <option value="">- All Types -</option>
                                <option value="service" <?php if ($type_filter == "service") { echo "selected"; } ?>>Service</option>
                                <option value="bed" <?php if ($type_filter == "bed") { echo "selected"; } ?>>Bed</option>
                                <option value="inventory" <?php if ($type_filter == "inventory") { echo "selected"; } ?>>Inventory</option>
                                <option value="lab" <?php if ($type_filter == "lab") { echo "selected"; } ?>>Lab Test</option>
                                <option value="imaging" <?php if ($type_filter == "imaging") { echo "selected"; } ?>>Imaging</option>
                                <option value="procedure" <?php if ($type_filter == "procedure") { echo "selected"; } ?>>Procedure</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Category</label>
                            <select class="form-control select2" name="category">
                                <option value="">- All Categories -</option>
                                <?php while($category = $categories_result->fetch_assoc()): ?>
                                    <option value="<?php echo $category['category_id']; ?>" <?php if ($category_filter == $category['category_id']) { echo "selected"; } ?>>
                                        <?php echo htmlspecialchars($category['category_name']); ?>
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
                                <a href="billable_items.php" class="btn btn-secondary">
                                    <i class="fas fa-times mr-2"></i>Clear
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
    
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="<?php if ($num_rows[0] == 0) { echo "d-none"; } ?>">
            <tr>
                <th>Item Details</th>
                <th>Type & Category</th>
                <th>Pricing</th>
                <th>Tax & Accounting</th>
                <th class="text-center">Price Lists</th>
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
                        <i class="fas fa-boxes fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No billable items found</h5>
                        <p class="text-muted">
                            <?php 
                            if ($q || $status_filter != 'active' || $type_filter || $category_filter) {
                                echo "Try adjusting your search or filter criteria.";
                            } else {
                                echo "Get started by creating your first billable item.";
                            }
                            ?>
                        </p>
                        <a href="billable_item_new.php" class="btn btn-success">
                            <i class="fas fa-plus mr-2"></i>Create First Billable Item
                        </a>
                        <?php if ($q || $status_filter != 'active' || $type_filter || $category_filter): ?>
                            <a href="billable_items.php" class="btn btn-secondary ml-2">
                                <i class="fas fa-times mr-2"></i>Clear Filters
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php
            } else {
                while ($row = $result->fetch_assoc()) {
                    $billable_item_id = intval($row['billable_item_id']);
                    $item_name = nullable_htmlentities($row['item_name']);
                    $item_code = nullable_htmlentities($row['item_code']);
                    $item_description = nullable_htmlentities($row['item_description']);
                    $item_type = $row['item_type'];
                    $category_name = nullable_htmlentities($row['category_name']);
                    $unit_price = floatval($row['unit_price']);
                    $cost_price = floatval($row['cost_price']);
                    $tax_rate = floatval($row['tax_rate']);
                    $is_taxable = boolval($row['is_taxable']);
                    $is_active = boolval($row['is_active']);
                    $price_list_count = intval($row['price_list_count']);
                    $created_by = nullable_htmlentities($row['created_by_name']);
                    $created_at = nullable_htmlentities($row['created_at']);
                    ?>
                    <tr>
                        <td>
                            <div class="font-weight-bold">
                                <a href="billable_item_view.php?id=<?php echo $billable_item_id; ?>" class="text-dark">
                                    <?php echo $item_name; ?>
                                </a>
                            </div>
                            <div class="small text-muted">
                                <code><?php echo $item_code; ?></code>
                                <?php if($item_description): ?>
                                    <div class="mt-1"><?php echo substr($item_description, 0, 100); ?><?php if(strlen($item_description) > 100): ?>...<?php endif; ?></div>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <span class="badge badge-<?php 
                                switch($item_type) {
                                    case 'service': echo 'primary'; break;
                                    case 'bed': echo 'success'; break;
                                    case 'inventory': echo 'info'; break;
                                    case 'lab': echo 'warning'; break;
                                    case 'imaging': echo 'danger'; break;
                                    case 'procedure': echo 'secondary'; break;
                                    default: echo 'light';
                                }
                            ?>">
                                <?php echo ucfirst($item_type); ?>
                            </span>
                            <?php if($category_name): ?>
                                <div class="mt-1">
                                    <small class="text-muted">
                                        <i class="fas fa-folder mr-1"></i><?php echo $category_name; ?>
                                    </small>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="font-weight-bold text-primary">
                                $<?php echo number_format($unit_price, 2); ?>
                            </div>
                            <?php if($cost_price > 0): ?>
                                <div class="small text-muted">
                                    Cost: $<?php echo number_format($cost_price, 2); ?>
                                    <?php if($unit_price > $cost_price): ?>
                                        <span class="text-success">
                                            (Margin: $<?php echo number_format($unit_price - $cost_price, 2); ?>)
                                        </span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if($is_taxable && $tax_rate > 0): ?>
                                <div class="small">
                                    <span class="badge badge-danger">Taxable</span>
                                    <span class="text-muted"><?php echo $tax_rate; ?>%</span>
                                </div>
                                <div class="small text-muted">
                                    Tax: $<?php echo number_format(($unit_price * $tax_rate) / 100, 2); ?>
                                </div>
                            <?php else: ?>
                                <span class="badge badge-success">Non-taxable</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <div class="h4 mb-0"><?php echo $price_list_count; ?></div>
                            <small class="text-muted">price lists</small>
                            <?php if($price_list_count > 0): ?>
                                <div class="mt-1">
                                    <a href="price_list_items.php?billable_item_id=<?php echo $billable_item_id; ?>" class="btn btn-xs btn-outline-primary">
                                        <i class="fas fa-list mr-1"></i>View Prices
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
                                    <a class="dropdown-item" href="billable_item_view.php?id=<?php echo $billable_item_id; ?>">
                                        <i class="fas fa-fw fa-eye mr-2"></i>View Details
                                    </a>
                                    <a class="dropdown-item" href="billable_item_edit.php?id=<?php echo $billable_item_id; ?>">
                                        <i class="fas fa-fw fa-edit mr-2"></i>Edit Item
                                    </a>
                                    <a class="dropdown-item" href="price_list_items.php?billable_item_id=<?php echo $billable_item_id; ?>">
                                        <i class="fas fa-fw fa-list mr-2"></i>View in Price Lists
                                    </a>
                                    <a class="dropdown-item" href="price_history.php?entity_type=billable_item&entity_id=<?php echo $billable_item_id; ?>">
                                        <i class="fas fa-fw fa-history mr-2"></i>Price History
                                    </a>
                                    <div class="dropdown-divider"></div>
                                    <?php if($is_active): ?>
                                        <a class="dropdown-item text-danger" href="billable_items.php?action=delete&id=<?php echo $billable_item_id; ?>" 
                                           onclick="return confirm('Deactivate this billable item?')">
                                            <i class="fas fa-fw fa-pause mr-2"></i>Deactivate
                                        </a>
                                    <?php else: ?>
                                        <a class="dropdown-item text-success" href="billable_items.php?action=restore&id=<?php echo $billable_item_id; ?>">
                                            <i class="fas fa-fw fa-play mr-2"></i>Activate
                                        </a>
                                    <?php endif; ?>
                                    <?php if($price_list_count == 0): ?>
                                        <a class="dropdown-item text-danger" href="billable_items.php?action=delete&id=<?php echo $billable_item_id; ?>" 
                                           onclick="return confirm('Are you sure you want to permanently delete this billable item?')">
                                            <i class="fas fa-fw fa-trash mr-2"></i>Delete Permanently
                                        </a>
                                    <?php else: ?>
                                        <span class="dropdown-item text-muted disabled" style="cursor: not-allowed;">
                                            <i class="fas fa-fw fa-trash mr-2"></i>Delete (in price lists)
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
    
    <?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/filter_footer.php'; ?>
</div>

<script>
$(document).ready(function() {
    $('.select2').select2({
        theme: 'bootstrap4',
        width: '100%'
    });
    
    // Auto-submit when filters change
    $('select[name="status"], select[name="type"], select[name="category"]').change(function() {
        $(this).closest('form').submit();
    });
});
</script>

<style>
.table td, .table th {
    vertical-align: middle;
}

.badge-pill {
    padding: 0.3em 0.6em;
    font-size: 0.85em;
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
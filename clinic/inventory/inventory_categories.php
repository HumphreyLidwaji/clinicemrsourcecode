<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Default Column Sortby/Order Filter
$sort = "category_name";
$order = "ASC";

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Filter parameters
$type_filter = $_GET['type'] ?? '';
$status_filter = $_GET['status'] ?? '';

// Search Query
$q = sanitizeInput($_GET['q'] ?? '');
if (!empty($q)) {
    $search_query = "AND (
        category_name LIKE '%$q%' 
        OR category_type LIKE '%$q%'
        OR description LIKE '%$q%'
    )";
} else {
    $search_query = '';
}

// Status Filter
if ($status_filter) {
    $status_query = "AND is_active = " . ($status_filter == 'active' ? 1 : 0);
} else {
    $status_query = '';
}

// Type Filter
if ($type_filter) {
    $type_query = "AND category_type = '" . sanitizeInput($type_filter) . "'";
} else {
    $type_query = '';
}

// Main query for categories
$sql = mysqli_query(
    $mysqli,
    "
    SELECT SQL_CALC_FOUND_ROWS * 
    FROM inventory_categories 
    WHERE 1=1
      $status_query
      $type_query
      $search_query
    ORDER BY $sort $order
    LIMIT $record_from, $record_to
");

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get statistics
$total_categories = $num_rows[0];
$active_categories = 0;
$medical_count = 0;
$pharmacy_count = 0;
$equipment_count = 0;
$lab_count = 0;
$general_count = 0;

// Reset pointer and calculate
mysqli_data_seek($sql, 0);
while ($category = mysqli_fetch_assoc($sql)) {
    if ($category['is_active']) {
        $active_categories++;
    }
    
    switch($category['category_type']) {
        case 'Medical Supplies':
            $medical_count++;
            break;
        case 'Pharmacy':
            $pharmacy_count++;
            break;
        case 'Equipment':
            $equipment_count++;
            break;
        case 'Laboratory':
            $lab_count++;
            break;
        case 'General':
            $general_count++;
            break;
    }
}
mysqli_data_seek($sql, $record_from);

// Get items count per category for statistics
$items_count_sql = mysqli_query($mysqli, "
    SELECT c.category_id, COUNT(i.item_id) as items_count
    FROM inventory_categories c
    LEFT JOIN inventory_items i ON c.category_id = i.category_id
    WHERE c.is_active = 1 AND i.is_active = 1
    GROUP BY c.category_id
");
$category_items_count = [];
while ($row = mysqli_fetch_assoc($items_count_sql)) {
    $category_items_count[$row['category_id']] = $row['items_count'];
}
?>

<div class="card">
    <div class="card-header bg-info py-2">
        <h3 class="card-title mt-2 mb-0"><i class="fas fa-fw fa-tags mr-2"></i>Inventory Categories</h3>
        <div class="card-tools">
            <a href="inventory_category_add.php" class="btn btn-primary">
                <i class="fas fa-plus mr-2"></i>Add Category
            </a>
        </div>
    </div>
    
    <!-- Statistics Row -->
    <div class="card-body border-bottom">
        <div class="row text-center">
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-primary"><i class="fas fa-tags"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Categories</span>
                        <span class="info-box-number"><?php echo $total_categories; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-success"><i class="fas fa-check-circle"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Active</span>
                        <span class="info-box-number"><?php echo $active_categories; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-warning"><i class="fas fa-pills"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Pharmacy</span>
                        <span class="info-box-number"><?php echo $pharmacy_count; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-secondary"><i class="fas fa-stethoscope"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Medical</span>
                        <span class="info-box-number"><?php echo $medical_count; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-info"><i class="fas fa-flask"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Laboratory</span>
                        <span class="info-box-number"><?php echo $lab_count; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-dark"><i class="fas fa-toolbox"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Equipment</span>
                        <span class="info-box-number"><?php echo $equipment_count; ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card-header pb-2 pt-3">
        <form autocomplete="off">
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search categories..." autofocus>
                            <div class="input-group-append">
                                <button class="btn btn-secondary" type="button" data-toggle="collapse" data-target="#advancedFilter"><i class="fas fa-filter"></i></button>
                                <button class="btn btn-primary"><i class="fa fa-search"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="btn-toolbar form-group float-right">
                        <div class="btn-group">
                            <a href="?<?php echo $url_query_strings_sort ?>&export=pdf" class="btn btn-default">
                                <i class="fa fa-fw fa-file-pdf mr-2"></i>Export
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php if (isset($_GET['type']) || isset($_GET['status'])) { echo "show"; } ?>" id="advancedFilter">
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Category Type</label>
                            <select class="form-control select2" name="type" onchange="this.form.submit()">
                                <option value="">- All Types -</option>
                                <option value="Medical Supplies" <?php if ($type_filter == "Medical Supplies") { echo "selected"; } ?>>Medical Supplies</option>
                                <option value="Pharmacy" <?php if ($type_filter == "Pharmacy") { echo "selected"; } ?>>Pharmacy</option>
                                <option value="Equipment" <?php if ($type_filter == "Equipment") { echo "selected"; } ?>>Equipment</option>
                                <option value="Laboratory" <?php if ($type_filter == "Laboratory") { echo "selected"; } ?>>Laboratory</option>
                                <option value="General" <?php if ($type_filter == "General") { echo "selected"; } ?>>General</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Status</label>
                            <select class="form-control select2" name="status" onchange="this.form.submit()">
                                <option value="">- All Status -</option>
                                <option value="active" <?php if ($status_filter == "active") { echo "selected"; } ?>>Active</option>
                                <option value="inactive" <?php if ($status_filter == "inactive") { echo "selected"; } ?>>Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
    
    <div class="table-responsive-sm">
        <table class="table table-hover mb-0 text-nowrap">
            <thead class="<?php if ($num_rows[0] == 0) { echo "d-none"; } ?> bg-light">
            <tr>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=category_name&order=<?php echo $disp; ?>">
                        Category Name <?php if ($sort == 'category_name') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=category_type&order=<?php echo $disp; ?>">
                        Type <?php if ($sort == 'category_type') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>Items Count</th>
                <th>Description</th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=is_active&order=<?php echo $disp; ?>">
                        Status <?php if ($sort == 'is_active') { echo $order_icon; } ?>
                    </a>
                </th>
                <th class="text-center">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php
            while ($row = mysqli_fetch_array($sql)) {
                $category_id = intval($row['category_id']);
                $category_name = nullable_htmlentities($row['category_name']);
                $category_type = nullable_htmlentities($row['category_type']);
                $description = nullable_htmlentities($row['description']);
                $is_active = intval($row['is_active']);
                $created_by = intval($row['created_by']);
                $created_at = nullable_htmlentities($row['created_at']);
                $updated_by = intval($row['updated_by']);
                $updated_at = nullable_htmlentities($row['updated_at']);
                $items_count = $category_items_count[$category_id] ?? 0;
                ?>
                <tr>
                    <td>
                        <div class="font-weight-bold"><?php echo $category_name; ?></div>
                        <small class="text-muted">ID: <?php echo $category_id; ?></small>
                    </td>
                    <td>
                        <span class="badge badge-<?php 
                            echo $category_type == 'Pharmacy' ? 'success' : 
                                ($category_type == 'Medical Supplies' ? 'warning' : 
                                ($category_type == 'Equipment' ? 'dark' : 
                                ($category_type == 'Laboratory' ? 'info' : 'secondary'))); 
                        ?>">
                            <?php echo $category_type; ?>
                        </span>
                    </td>
                    <td>
                        <span class="font-weight-bold <?php echo $items_count > 0 ? 'text-primary' : 'text-muted'; ?>">
                            <?php echo $items_count; ?> items
                        </span>
                        <?php if ($items_count > 0): ?>
                            <a href="inventory_items.php?category=<?php echo $category_id; ?>" class="btn btn-xs btn-outline-primary ml-2">
                                <i class="fas fa-eye"></i>
                            </a>
                        <?php endif; ?>
                    </td>
                    <td>
                        <small class="text-muted"><?php echo truncate($description, 60); ?></small>
                    </td>
                    <td>
                        <span class="badge badge-<?php echo $is_active ? 'success' : 'danger'; ?>">
                            <?php echo $is_active ? 'Active' : 'Inactive'; ?>
                        </span>
                    </td>
                    <td>
                        <div class="dropdown dropleft text-center">
                            <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                <i class="fas fa-ellipsis-h"></i>
                            </button>
                            <div class="dropdown-menu">
                                <a class="dropdown-item" href="inventory_category_edit.php?category_id=<?php echo $category_id; ?>">
                                    <i class="fas fa-fw fa-edit mr-2"></i>Edit
                                </a>
                                <a class="dropdown-item" href="inventory_items.php?category=<?php echo $category_id; ?>">
                                    <i class="fas fa-fw fa-boxes mr-2"></i>View Items
                                </a>
                                <?php if ($is_active): ?>
                                    <a class="dropdown-item text-warning" href="post.php?deactivate_category=<?php echo $category_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>">
                                        <i class="fas fa-fw fa-ban mr-2"></i>Deactivate
                                    </a>
                                <?php else: ?>
                                    <a class="dropdown-item text-success" href="post.php?activate_category=<?php echo $category_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>">
                                        <i class="fas fa-fw fa-check mr-2"></i>Activate
                                    </a>
                                <?php endif; ?>
                                <?php if ($items_count == 0): ?>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item text-danger confirm-delete" href="post.php?delete_category=<?php echo $category_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>">
                                        <i class="fas fa-fw fa-trash mr-2"></i>Delete
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php
            } 
            
            if ($num_rows[0] == 0) {
                ?>
                <tr>
                    <td colspan="6" class="text-center py-5">
                        <i class="fas fa-tags fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No categories found</h5>
                        <p class="text-muted">Get started by creating your first inventory category.</p>
                        <a href="inventory_category_add.php" class="btn btn-primary">
                            <i class="fas fa-plus mr-2"></i>Add Category
                        </a>
                    </td>
                </tr>
                <?php
            }
            ?>
            </tbody>
        </table>
    </div>
    
    <?php 
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/filter_footer.php';
    ?>
</div>

<script>
$(document).ready(function() {
    $('.select2').select2();
    
    // Confirm delete
    $('.confirm-delete').click(function(e) {
        if (!confirm('Are you sure you want to delete this category? This action cannot be undone.')) {
            e.preventDefault();
        }
    });
});
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
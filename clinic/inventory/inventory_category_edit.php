<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
// Get category ID from URL
$category_id = intval($_GET['category_id']);

if (!$category_id) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Category ID is required.";
    header("Location: inventory_categories.php");
    exit;
}

// Fetch category data
$sql = mysqli_query($mysqli, 
    "SELECT * FROM inventory_categories WHERE category_id = $category_id"
);

if (mysqli_num_rows($sql) == 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Category not found.";
    header("Location: inventory_categories.php");
    exit;
}

$category = mysqli_fetch_assoc($sql);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $category_name = sanitizeInput($_POST['category_name']);
    $category_type = sanitizeInput($_POST['category_type']);
    $category_description = sanitizeInput($_POST['category_description']);
    $category_is_active = intval($_POST['category_is_active']);

    // Validate required fields
    if (empty($category_name)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Category name is required.";
    } else {
        // Check for duplicate category name (excluding current category)
        $check_sql = mysqli_query($mysqli, 
            "SELECT category_id FROM inventory_categories 
             WHERE category_name = '$category_name' 
             AND category_type = '$category_type'
             AND category_id != $category_id
             LIMIT 1"
        );

        if (mysqli_num_rows($check_sql) > 0) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "A category with this name and type already exists.";
        } else {
            // Update category
            $update_sql = mysqli_query($mysqli,
                "UPDATE inventory_categories SET
                    category_name = '$category_name',
                    category_type = '$category_type',
                    category_description = '$category_description',
                    category_is_active = $category_is_active,
                    category_updated_at = NOW()
                WHERE category_id = $category_id"
            );

            if ($update_sql) {
                $_SESSION['alert_type'] = "success";
                $_SESSION['alert_message'] = "Category updated successfully.";
                
                // Log the activity
                mysqli_query($mysqli,
                    "INSERT INTO logs SET
                    log_type = 'Inventory Category',
                    log_action = 'Modify',
                    log_description = 'Category $category_name ($category_type) was updated',
                    log_ip = '$session_ip',
                    log_user_agent = '$session_user_agent',
                    log_user_id = $session_user_id"
                );

                header("Location: inventory_categories.php");
                exit;
            } else {
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = "Error updating category: " . mysqli_error($mysqli);
            }
        }
    }
}

// Get category statistics for the sidebar
$stats_sql = mysqli_query($mysqli,
    "SELECT 
        COUNT(i.item_id) as items_count,
        SUM(i.item_quantity) as total_quantity,
        SUM(i.item_quantity * i.item_unit_cost) as total_value,
        SUM(CASE WHEN i.item_quantity <= 0 THEN 1 ELSE 0 END) as out_of_stock,
        SUM(CASE WHEN i.item_quantity > 0 AND i.item_quantity <= i.item_low_stock_alert THEN 1 ELSE 0 END) as low_stock
    FROM inventory_categories c
    LEFT JOIN inventory_items i ON c.category_id = i.item_category_id AND i.item_status != 'Discontinued'
    WHERE c.category_id = $category_id
    GROUP BY c.category_id"
);

$stats = mysqli_fetch_assoc($stats_sql);
$items_count = $stats['items_count'] ?? 0;
$total_quantity = $stats['total_quantity'] ?? 0;
$total_value = $stats['total_value'] ?? 0;
$out_of_stock = $stats['out_of_stock'] ?? 0;
$low_stock = $stats['low_stock'] ?? 0;
?>

<div class="card">
    <div class="card-header bg-info py-2">
        <h3 class="card-title mt-2 mb-0"><i class="fas fa-fw fa-edit mr-2"></i>Edit Category</h3>
        <div class="card-tools">
            <a href="inventory_categories.php" class="btn btn-secondary">
                <i class="fas fa-fw fa-arrow-left mr-2"></i>Back to Categories
            </a>
        </div>
    </div>

    <div class="card-body">
        <div class="row">
            <div class="col-md-8">
                <form action="inventory_category_edit.php?category_id=<?php echo $category_id; ?>" method="POST" autocomplete="off">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Category Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="category_name" 
                                       value="<?php echo nullable_htmlentities($category['category_name']); ?>" 
                                       required autofocus
                                       placeholder="Enter category name">
                                <small class="form-text text-muted">Unique name for this category</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Category Type <span class="text-danger">*</span></label>
                                <select class="form-control" name="category_type" required>
                                    <option value="">- Select Type -</option>
                                    <option value="Medical Supplies" <?php echo $category['category_type'] == 'Medical Supplies' ? 'selected' : ''; ?>>Medical Supplies</option>
                                    <option value="Pharmacy" <?php echo $category['category_type'] == 'Pharmacy' ? 'selected' : ''; ?>>Pharmacy</option>
                                    <option value="Equipment" <?php echo $category['category_type'] == 'Equipment' ? 'selected' : ''; ?>>Equipment</option>
                                    <option value="Laboratory" <?php echo $category['category_type'] == 'Laboratory' ? 'selected' : ''; ?>>Laboratory</option>
                                    <option value="Office Supplies" <?php echo $category['category_type'] == 'Office Supplies' ? 'selected' : ''; ?>>Office Supplies</option>
                                    <option value="Other" <?php echo $category['category_type'] == 'Other' ? 'selected' : ''; ?>>Other</option>
                                </select>
                                <small class="form-text text-muted">Type helps organize inventory by major groups</small>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Description</label>
                        <textarea class="form-control" name="category_description" rows="4" 
                                  placeholder="Describe this category and what types of items belong here..."><?php echo nullable_htmlentities($category['category_description']); ?></textarea>
                        <small class="form-text text-muted">Optional description to help identify this category's purpose</small>
                    </div>

                    <div class="form-group">
                        <label>Status <span class="text-danger">*</span></label>
                        <select class="form-control" name="category_is_active" required>
                            <option value="1" <?php echo $category['category_is_active'] ? 'selected' : ''; ?>>Active</option>
                            <option value="0" <?php echo !$category['category_is_active'] ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                        <small class="form-text text-muted">Active categories can be used for new inventory items</small>
                    </div>

                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-fw fa-save mr-2"></i>Update Category
                        </button>
                        <a href="inventory_categories.php" class="btn btn-secondary">
                            <i class="fas fa-fw fa-times mr-2"></i>Cancel
                        </a>
                        <?php if ($items_count == 0): ?>
                            <a href="post.php?delete_category=<?php echo $category_id; ?>" class="btn btn-danger float-right" onclick="return confirm('Are you sure you want to delete this category? This action cannot be undone.');">
                                <i class="fas fa-fw fa-trash mr-2"></i>Delete Category
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <div class="col-md-4">
                <!-- Category Statistics -->
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0"><i class="fas fa-chart-bar mr-2"></i>Category Statistics</h5>
                    </div>
                    <div class="card-body">
                        <div class="text-center mb-4">
                            <div class="category-avatar bg-primary rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 80px; height: 80px;">
                                <span class="h4 text-white font-weight-bold">
                                    <?php echo strtoupper(substr($category['category_name'], 0, 2)); ?>
                                </span>
                            </div>
                            <h5 class="mt-3 mb-1"><?php echo nullable_htmlentities($category['category_name']); ?></h5>
                            <span class="badge badge-<?php echo $category['category_is_active'] ? 'success' : 'danger'; ?>">
                                <?php echo $category['category_is_active'] ? 'Active' : 'Inactive'; ?>
                            </span>
                            <div class="text-muted small mt-1"><?php echo nullable_htmlentities($category['category_type']); ?></div>
                        </div>

                        <div class="list-group list-group-flush">
                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <i class="fas fa-boxes text-primary mr-2"></i>
                                    <span>Total Items</span>
                                </div>
                                <span class="badge badge-primary badge-pill"><?php echo number_format($items_count); ?></span>
                            </div>
                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <i class="fas fa-cubes text-success mr-2"></i>
                                    <span>Total Quantity</span>
                                </div>
                                <span class="badge badge-success badge-pill"><?php echo number_format($total_quantity); ?></span>
                            </div>
                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <i class="fas fa-money-bill-wave text-info mr-2"></i>
                                    <span>Total Value</span>
                                </div>
                                <span class="text-info font-weight-bold">$<?php echo number_format($total_value, 2); ?></span>
                            </div>
                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <i class="fas fa-exclamation-triangle text-warning mr-2"></i>
                                    <span>Low Stock Items</span>
                                </div>
                                <span class="badge badge-warning badge-pill"><?php echo number_format($low_stock); ?></span>
                            </div>
                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <i class="fas fa-times-circle text-danger mr-2"></i>
                                    <span>Out of Stock</span>
                                </div>
                                <span class="badge badge-danger badge-pill"><?php echo number_format($out_of_stock); ?></span>
                            </div>
                        </div>

                        <div class="mt-4">
                            <h6 class="text-muted mb-2">Quick Actions</h6>
                            <div class="d-grid gap-2">
                                <a href="inventory.php?category=<?php echo $category_id; ?>" class="btn btn-outline-primary btn-sm">
                                    <i class="fas fa-boxes mr-2"></i>View Items
                                </a>
                                <a href="inventory_add_item.php?category_id=<?php echo $category_id; ?>" class="btn btn-outline-success btn-sm">
                                    <i class="fas fa-plus mr-2"></i>Add Item to Category
                                </a>
                                <a href="inventory_reports.php?category=<?php echo $category_id; ?>&report_type=stock_summary" class="btn btn-outline-info btn-sm">
                                    <i class="fas fa-chart-bar mr-2"></i>View Reports
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Category Information -->
                <div class="card mt-3">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0"><i class="fas fa-info-circle mr-2"></i>Category Information</h5>
                    </div>
                    <div class="card-body">
                        <div class="small">
                            <div class="mb-2">
                                <strong>Created:</strong><br>
                                <?php echo date('M j, Y g:i A', strtotime($category['category_created_at'])); ?>
                            </div>
                            <?php if ($category['category_updated_at'] && $category['category_updated_at'] != $category['category_created_at']): ?>
                                <div class="mb-2">
                                    <strong>Last Updated:</strong><br>
                                    <?php echo date('M j, Y g:i A', strtotime($category['category_updated_at'])); ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($category['category_description']): ?>
                                <div class="mb-2">
                                    <strong>Description:</strong><br>
                                    <?php echo nullable_htmlentities($category['category_description']); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Recent Items in this Category -->
                <div class="card mt-3">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0"><i class="fas fa-boxes mr-2"></i>Recent Items</h5>
                    </div>
                    <div class="card-body">
                        <?php
                        $recent_items_sql = mysqli_query($mysqli,
                            "SELECT i.item_name, i.item_code, i.item_quantity, i.item_low_stock_alert
                             FROM inventory_items i
                             WHERE i.item_category_id = $category_id 
                             AND i.item_status != 'Discontinued'
                             ORDER BY i.item_added_date DESC 
                             LIMIT 5"
                        );
                        
                        if (mysqli_num_rows($recent_items_sql) > 0) {
                            echo '<div class="list-group list-group-flush small">';
                            while ($item = mysqli_fetch_assoc($recent_items_sql)) {
                                $stock_class = $item['item_quantity'] <= 0 ? 'danger' : ($item['item_quantity'] <= $item['item_low_stock_alert'] ? 'warning' : 'success');
                                echo '
                                <div class="list-group-item px-0">
                                    <div class="font-weight-bold">' . nullable_htmlentities($item['item_name']) . '</div>
                                    <div class="text-muted">' . nullable_htmlentities($item['item_code']) . '</div>
                                    <span class="badge badge-' . $stock_class . ' badge-sm">' . number_format($item['item_quantity']) . ' in stock</span>
                                </div>';
                            }
                            echo '</div>';
                        } else {
                            echo '<p class="text-muted small mb-0">No items in this category.</p>';
                        }
                        ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Form validation
    $('form').submit(function(e) {
        const categoryName = $('input[name="category_name"]').val().trim();
        const categoryType = $('select[name="category_type"]').val();
        
        if (!categoryName) {
            e.preventDefault();
            alert('Category name is required.');
            $('input[name="category_name"]').focus();
            return false;
        }
        
        if (!categoryType) {
            e.preventDefault();
            alert('Category type is required.');
            $('select[name="category_type"]').focus();
            return false;
        }
    });

    // Auto-focus on category name field
    $('input[name="category_name"]').focus();
});
</script>

<style>
.category-avatar {
    font-size: 1.5rem;
    border: 3px solid #e9ecef;
}
.list-group-item {
    border: none;
    padding: 0.75rem 0;
}
.badge-sm {
    font-size: 0.7em;
}
</style>

  <?php 
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
    ?>
    
<?php
// laundry_categories.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

$page_title = "Laundry Categories";

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action == 'add_category') {
        $category_name = sanitizeInput($_POST['category_name']);
        $description = sanitizeInput($_POST['description']);
        $min_quantity = intval($_POST['min_quantity']);
        $reorder_point = intval($_POST['reorder_point']);
        
        if (empty($category_name)) {
            $error = "Category name is required";
        } else {
            $sql = "INSERT INTO laundry_categories 
                   (category_name, description, min_quantity, reorder_point) 
                   VALUES (?, ?, ?, ?)";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param("ssii", $category_name, $description, $min_quantity, $reorder_point);
            
            if ($stmt->execute()) {
                $category_id = $stmt->insert_id;
                logActivity($_SESSION['user_id'], "Added laundry category: $category_name", "laundry");
                $_SESSION['alert_message'] = "Category added successfully";
                $_SESSION['alert_type'] = "success";
                header("Location: laundry_categories.php");
                exit();
            } else {
                $error = "Error adding category: " . $mysqli->error;
            }
        }
    }
    elseif ($action == 'update_category') {
        $category_id = intval($_POST['category_id']);
        $category_name = sanitizeInput($_POST['category_name']);
        $description = sanitizeInput($_POST['description']);
        $min_quantity = intval($_POST['min_quantity']);
        $reorder_point = intval($_POST['reorder_point']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        $sql = "UPDATE laundry_categories 
               SET category_name = ?, description = ?, min_quantity = ?, 
                   reorder_point = ?, is_active = ?, updated_at = NOW()
               WHERE category_id = ?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("ssiiii", $category_name, $description, $min_quantity, $reorder_point, $is_active, $category_id);
        
        if ($stmt->execute()) {
            logActivity($_SESSION['user_id'], "Updated laundry category: $category_name", "laundry");
            $_SESSION['alert_message'] = "Category updated successfully";
            $_SESSION['alert_type'] = "success";
            header("Location: laundry_categories.php");
            exit();
        } else {
            $error = "Error updating category: " . $mysqli->error;
        }
    }
    elseif ($action == 'delete_category') {
        $category_id = intval($_POST['category_id']);
        
        // Check if category has items
        $check_sql = "SELECT COUNT(*) as item_count FROM laundry_items WHERE category_id = ?";
        $check_stmt = $mysqli->prepare($check_sql);
        $check_stmt->bind_param("i", $category_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $check_data = $check_result->fetch_assoc();
        
        if ($check_data['item_count'] > 0) {
            $error = "Cannot delete category: It has " . $check_data['item_count'] . " items assigned";
        } else {
            $sql = "DELETE FROM laundry_categories WHERE category_id = ?";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param("i", $category_id);
            
            if ($stmt->execute()) {
                logActivity($_SESSION['user_id'], "Deleted laundry category ID: $category_id", "laundry");
                $_SESSION['alert_message'] = "Category deleted successfully";
                $_SESSION['alert_type'] = "success";
                header("Location: laundry_categories.php");
                exit();
            } else {
                $error = "Error deleting category: " . $mysqli->error;
            }
        }
    }
}

// Get all categories with item counts
$categories_sql = "
    SELECT lc.*, 
           COUNT(li.laundry_id) as total_items,
           SUM(CASE WHEN li.status = 'clean' AND li.current_location = 'storage' THEN 1 ELSE 0 END) as available_clean,
           SUM(CASE WHEN li.status = 'clean' THEN 1 ELSE 0 END) as total_clean,
           SUM(CASE WHEN li.status = 'dirty' THEN 1 ELSE 0 END) as total_dirty,
           SUM(CASE WHEN li.status = 'damaged' THEN 1 ELSE 0 END) as total_damaged
    FROM laundry_categories lc
    LEFT JOIN laundry_items li ON lc.category_id = li.category_id
    GROUP BY lc.category_id, lc.category_name, lc.description, lc.min_quantity, lc.reorder_point,  lc.created_at, lc.updated_at
    ORDER BY lc.category_name
";
$categories_result = $mysqli->query($categories_sql);
?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-tags mr-2"></i>Laundry Categories
        </h3>
        <div class="card-tools">
            <button class="btn btn-success" data-toggle="modal" data-target="#addCategoryModal">
                <i class="fas fa-fw fa-plus mr-2"></i>Add Category
            </button>
            <a href="laundry_management.php" class="btn btn-secondary ml-2">
                <i class="fas fa-fw fa-arrow-left mr-2"></i>Back to Laundry
            </a>
        </div>
    </div>
    
    <div class="card-body">
        <?php if (isset($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle mr-2"></i><?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['alert_message'])): ?>
            <div class="alert alert-<?php echo $_SESSION['alert_type']; ?>">
                <i class="fas fa-check-circle mr-2"></i><?php echo $_SESSION['alert_message']; ?>
            </div>
            <?php unset($_SESSION['alert_message'], $_SESSION['alert_type']); ?>
        <?php endif; ?>
        
        <div class="table-responsive">
            <table class="table table-hover">
                <thead class="bg-light">
                    <tr>
                        <th>Category Name</th>
                        <th>Description</th>
                        <th class="text-center">Min Qty</th>
                        <th class="text-center">Reorder Point</th>
                        <th class="text-center">Items</th>
                        <th class="text-center">Available</th>
                        <th class="text-center">Dirty</th>
                        <th class="text-center">Damaged</th>
                      
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($categories_result->num_rows == 0): ?>
                        <tr>
                            <td colspan="10" class="text-center py-5">
                                <i class="fas fa-tags fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">No categories found</h5>
                                <p class="text-muted">Add your first category to get started</p>
                                <button class="btn btn-primary" data-toggle="modal" data-target="#addCategoryModal">
                                    <i class="fas fa-plus mr-2"></i>Add Category
                                </button>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php while($category = $categories_result->fetch_assoc()): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($category['category_name']); ?></strong>
                            </td>
                            <td>
                                <?php echo htmlspecialchars($category['description'] ?? '—'); ?>
                            </td>
                            <td class="text-center">
                                <span class="badge badge-info"><?php echo $category['min_quantity']; ?></span>
                            </td>
                            <td class="text-center">
                                <span class="badge badge-warning"><?php echo $category['reorder_point']; ?></span>
                            </td>
                            <td class="text-center">
                                <span class="badge badge-dark"><?php echo $category['total_items']; ?></span>
                            </td>
                            <td class="text-center">
                                <span class="badge <?php echo $category['available_clean'] < $category['reorder_point'] ? 'badge-danger' : 'badge-success'; ?>">
                                    <?php echo $category['available_clean']; ?>
                                </span>
                                <?php if ($category['available_clean'] < $category['reorder_point']): ?>
                                    <small class="text-danger d-block">
                                        <i class="fas fa-exclamation-circle"></i> Low stock
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($category['total_dirty'] > 0): ?>
                                    <span class="badge badge-warning"><?php echo $category['total_dirty']; ?></span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($category['total_damaged'] > 0): ?>
                                    <span class="badge badge-danger"><?php echo $category['total_damaged']; ?></span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                        
                            <td class="text-center">
                                <div class="btn-group">
                                    <button class="btn btn-sm btn-info" 
                                            data-toggle="modal" 
                                            data-target="#editCategoryModal"
                                            data-category-id="<?php echo $category['category_id']; ?>"
                                            data-category-name="<?php echo htmlspecialchars($category['category_name']); ?>"
                                            data-description="<?php echo htmlspecialchars($category['description'] ?? ''); ?>"
                                            data-min-quantity="<?php echo $category['min_quantity']; ?>"
                                            data-reorder-point="<?php echo $category['reorder_point']; ?>"
                                            
                                            onclick="loadEditForm(this)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    
                                    <button class="btn btn-sm btn-danger" 
                                            data-toggle="modal" 
                                            data-target="#deleteCategoryModal"
                                            data-category-id="<?php echo $category['category_id']; ?>"
                                            data-category-name="<?php echo htmlspecialchars($category['category_name']); ?>"
                                            data-item-count="<?php echo $category['total_items']; ?>"
                                            onclick="loadDeleteForm(this)">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Category Modal -->
<div class="modal fade" id="addCategoryModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form action="laundry_categories.php" method="POST">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-plus mr-2"></i>Add New Category
                    </h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span class="text-white">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_category">
                    
                    <div class="form-group">
                        <label>Category Name *</label>
                        <input type="text" class="form-control" name="category_name" required 
                               placeholder="e.g., Bed Sheets, Patient Gowns">
                    </div>
                    
                    <div class="form-group">
                        <label>Description</label>
                        <textarea class="form-control" name="description" rows="3" 
                                  placeholder="Description of this category..."></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Minimum Quantity</label>
                                <input type="number" class="form-control" name="min_quantity" 
                                       value="0" min="0" max="1000">
                                <small class="form-text text-muted">Minimum items that should be available</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Reorder Point</label>
                                <input type="number" class="form-control" name="reorder_point" 
                                       value="5" min="0" max="1000">
                                <small class="form-text text-muted">When to reorder (should be less than min quantity)</small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Category Modal -->
<div class="modal fade" id="editCategoryModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form action="laundry_categories.php" method="POST" id="editCategoryForm">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-edit mr-2"></i>Edit Category
                    </h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span class="text-white">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_category">
                    <input type="hidden" name="category_id" id="editCategoryId">
                    
                    <div class="form-group">
                        <label>Category Name *</label>
                        <input type="text" class="form-control" name="category_name" 
                               id="editCategoryName" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Description</label>
                        <textarea class="form-control" name="description" 
                                  id="editDescription" rows="3"></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Minimum Quantity</label>
                                <input type="number" class="form-control" name="min_quantity" 
                                       id="editMinQuantity" min="0" max="1000">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Reorder Point</label>
                                <input type="number" class="form-control" name="reorder_point" 
                                       id="editReorderPoint" min="0" max="1000">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" name="is_active" 
                               id="editIsActive" value="1">
                        <label class="form-check-label" for="editIsActive">
                            Category is active
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Category Modal -->
<div class="modal fade" id="deleteCategoryModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form action="laundry_categories.php" method="POST">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-trash mr-2"></i>Delete Category
                    </h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span class="text-white">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="delete_category">
                    <input type="hidden" name="category_id" id="deleteCategoryId">
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        <strong>Warning:</strong> This action cannot be undone!
                    </div>
                    
                    <p>Are you sure you want to delete the category <strong id="deleteCategoryName"></strong>?</p>
                    
                    <div class="alert alert-info" id="deleteWarning" style="display: none;">
                        <i class="fas fa-info-circle mr-2"></i>
                        This category has <span id="deleteItemCount">0</span> items assigned. 
                        You must reassign or delete these items before deleting the category.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger" id="deleteSubmitBtn">Delete Category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function loadEditForm(button) {
    var categoryId = $(button).data('category-id');
    var categoryName = $(button).data('category-name');
    var description = $(button).data('description');
    var minQuantity = $(button).data('min-quantity');
    var reorderPoint = $(button).data('reorder-point');
    var isActive = $(button).data('is-active');
    
    $('#editCategoryId').val(categoryId);
    $('#editCategoryName').val(categoryName);
    $('#editDescription').val(description);
    $('#editMinQuantity').val(minQuantity);
    $('#editReorderPoint').val(reorderPoint);
    $('#editIsActive').prop('checked', isActive == 1);
}

function loadDeleteForm(button) {
    var categoryId = $(button).data('category-id');
    var categoryName = $(button).data('category-name');
    var itemCount = $(button).data('item-count');
    
    $('#deleteCategoryId').val(categoryId);
    $('#deleteCategoryName').text(categoryName);
    $('#deleteItemCount').text(itemCount);
    
    if (itemCount > 0) {
        $('#deleteWarning').show();
        $('#deleteSubmitBtn').prop('disabled', true);
    } else {
        $('#deleteWarning').hide();
        $('#deleteSubmitBtn').prop('disabled', false);
    }
}
</script>

<style>
.modal-header .close {
    opacity: 1;
}

.badge {
    font-size: 12px;
    padding: 5px 10px;
}

.table tbody tr:hover {
    background-color: #f8f9fa;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Handle form submissions via POST
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_category'])) {
        $category_name = sanitizeInput($_POST['category_name']);
        $category_description = sanitizeInput($_POST['category_description']);
        
        $stmt = $mysqli->prepare("INSERT INTO medical_service_categories SET category_name=?, category_description=?");
        $stmt->bind_param("ss", $category_name, $category_description);
        
        if ($stmt->execute()) {
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Category added successfully!";
        } else {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error adding category: " . $mysqli->error;
        }
        $stmt->close();
    }
    
    elseif (isset($_POST['update_category'])) {
        $category_id = intval($_POST['category_id']);
        $category_name = sanitizeInput($_POST['category_name']);
        $category_description = sanitizeInput($_POST['category_description']);
        
        $stmt = $mysqli->prepare("UPDATE medical_service_categories SET category_name=?, category_description=? WHERE category_id=?");
        $stmt->bind_param("ssi", $category_name, $category_description, $category_id);
        
        if ($stmt->execute()) {
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Category updated successfully!";
        } else {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error updating category: " . $mysqli->error;
        }
        $stmt->close();
    }
    
    // Redirect to prevent form resubmission
    header("Location: medical_service_categories.php");
    exit;
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ajax_request'])) {
    header('Content-Type: application/json');
    
    if (isset($_POST['get_category_details'])) {
        $category_id = intval($_POST['category_id']);
        $category = $mysqli->query("SELECT * FROM medical_service_categories WHERE category_id = $category_id")->fetch_assoc();
        echo json_encode($category);
        exit;
    }
    
    if (isset($_POST['delete_category'])) {
        $category_id = intval($_POST['category_id']);
        
        // Check if category has active services
        $check_sql = "SELECT COUNT(*) as service_count FROM medical_services WHERE service_category_id = ? AND is_active = 1";
        $check_stmt = $mysqli->prepare($check_sql);
        $check_stmt->bind_param("i", $category_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $service_count = $check_result->fetch_assoc()['service_count'];
        $check_stmt->close();
        
        if ($service_count > 0) {
            echo json_encode(['success' => false, 'message' => 'Cannot delete category. There are ' . $service_count . ' active services using this category.']);
            exit;
        }
        
        // Since there's no is_active column, we'll delete the category
        // But first, set any services using this category to NULL
        $update_services_sql = "UPDATE medical_services SET service_category_id = NULL WHERE service_category_id = ?";
        $update_services_stmt = $mysqli->prepare($update_services_sql);
        $update_services_stmt->bind_param("i", $category_id);
        $update_services_stmt->execute();
        $update_services_stmt->close();
        
        $stmt = $mysqli->prepare("DELETE FROM medical_service_categories WHERE category_id = ?");
        $stmt->bind_param("i", $category_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Category deleted successfully!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error deleting category: ' . $mysqli->error]);
        }
        $stmt->close();
        exit;
    }
}

// Default Column Sortby/Order Filter
$sort = "msc.category_name";
$order = "ASC";

// Search Query
$q = sanitizeInput($_GET['q'] ?? '');
if (!empty($q)) {
    $search_query = "AND (
        msc.category_name LIKE '%$q%' 
        OR msc.category_description LIKE '%$q%'
    )";
} else {
    $search_query = '';
}

// Get all categories with service counts
$categories_sql = "
    SELECT SQL_CALC_FOUND_ROWS msc.*, 
           COUNT(ms.medical_service_id) as service_count,
           SUM(CASE WHEN ms.is_active = 1 THEN 1 ELSE 0 END) as active_services
    FROM medical_service_categories msc 
    LEFT JOIN medical_services ms ON msc.category_id = ms.service_category_id
    WHERE 1=1
    $search_query
    GROUP BY msc.category_id
    ORDER BY $sort $order
    LIMIT $record_from, $record_to
";

$categories_result = $mysqli->query($categories_sql);
$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get statistics
$total_categories = $mysqli->query("SELECT COUNT(*) FROM medical_service_categories")->fetch_row()[0];
$total_services = $mysqli->query("SELECT COUNT(*) FROM medical_services WHERE is_active = 1")->fetch_row()[0];
$uncategorized_services = $mysqli->query("SELECT COUNT(*) FROM medical_services WHERE service_category_id IS NULL AND is_active = 1")->fetch_row()[0];
?>

<div class="card">
    <div class="card-header bg-info py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-folder mr-2"></i>Medical Service Categories
            </h3>
            <div class="card-tools">
                <button type="button" class="btn btn-success" data-toggle="modal" data-target="#addCategoryModal">
                    <i class="fas fa-plus mr-2"></i>New Category
                </button>
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
                                <button class="btn btn-primary"><i class="fa fa-search"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="btn-toolbar form-group float-right">
                        <div class="btn-group">
                            <span class="btn btn-light border">
                                <i class="fas fa-folder text-info mr-1"></i>
                                Categories: <strong><?php echo $total_categories; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-stethoscope text-success mr-1"></i>
                                Services: <strong><?php echo $total_services; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-question text-warning mr-1"></i>
                                Uncategorized: <strong><?php echo $uncategorized_services; ?></strong>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
    
    <div class="card-body">
        <?php if (isset($_SESSION['alert_message'])): ?>
            <div class="alert alert-<?php echo $_SESSION['alert_type']; ?> alert-dismissible">
                <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
                <i class="icon fas fa-<?php echo $_SESSION['alert_type'] == 'success' ? 'check' : 'exclamation-triangle'; ?>"></i>
                <?php echo $_SESSION['alert_message']; ?>
            </div>
            <?php 
            unset($_SESSION['alert_type']);
            unset($_SESSION['alert_message']);
            ?>
        <?php endif; ?>

        <!-- Alert Container for AJAX Messages -->
        <div id="ajaxAlertContainer"></div>
    
        <div class="table-responsive-sm">
            <table class="table table-hover mb-0">
                <thead class="<?php if ($num_rows[0] == 0) { echo "d-none"; } ?> bg-light">
                <tr>
                    <th>
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=msc.category_name&order=<?php echo $disp; ?>">
                            Category Name <?php if ($sort == 'msc.category_name') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>Description</th>
                    <th>
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=service_count&order=<?php echo $disp; ?>">
                            Services <?php if ($sort == 'service_count') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>Active Services</th>
                    <th class="text-center">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php while($category = $categories_result->fetch_assoc()): 
                    $category_id = intval($category['category_id']);
                    $category_name = nullable_htmlentities($category['category_name']);
                    $category_description = nullable_htmlentities($category['category_description']);
                    $service_count = intval($category['service_count']);
                    $active_services = intval($category['active_services']);
                    ?>
                    <tr>
                        <td>
                            <div class="font-weight-bold text-info"><?php echo $category_name; ?></div>
                        </td>
                        <td>
                            <?php if ($category_description): ?>
                                <small class="text-muted"><?php echo strlen($category_description) > 100 ? substr($category_description, 0, 100) . '...' : $category_description; ?></small>
                            <?php else: ?>
                                <span class="text-muted">No description</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-primary badge-pill"><?php echo $service_count; ?></span>
                        </td>
                        <td>
                            <span class="badge badge-<?php echo $active_services > 0 ? 'success' : 'secondary'; ?> badge-pill">
                                <?php echo $active_services; ?>
                            </span>
                        </td>
                        <td>
                            <div class="dropdown dropleft text-center">
                                <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                    <i class="fas fa-ellipsis-h"></i>
                                </button>
                                <div class="dropdown-menu">
                                    <a class="dropdown-item" href="#" onclick="editCategory(<?php echo $category_id; ?>)">
                                        <i class="fas fa-fw fa-edit mr-2"></i>Edit Category
                                    </a>
                                    <a class="dropdown-item" href="medical_services.php?category=<?php echo $category_id; ?>">
                                        <i class="fas fa-fw fa-list mr-2"></i>View Services
                                    </a>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item text-danger" href="#" onclick="deleteCategory(<?php echo $category_id; ?>, '<?php echo addslashes($category_name); ?>')">
                                        <i class="fas fa-fw fa-trash mr-2"></i>Delete Category
                                    </a>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; ?>

                <?php if ($num_rows[0] === 0): ?>
                    <tr>
                        <td colspan="5" class="text-center py-4">
                            <i class="fas fa-folder fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">No Categories Found</h5>
                            <p class="text-muted">
                                <?php echo $search_query ? 
                                    'Try adjusting your search criteria.' : 
                                    'Get started by creating your first category.'; 
                                ?>
                            </p>
                            <button type="button" class="btn btn-info mt-2" data-toggle="modal" data-target="#addCategoryModal">
                                <i class="fas fa-plus mr-2"></i>Create First Category
                            </button>
                            <?php if ($search_query): ?>
                                <a href="medical_service_categories.php" class="btn btn-outline-secondary mt-2 ml-2">
                                    <i class="fas fa-times mr-2"></i>Clear Filters
                                </a>
                            <?php endif; ?>
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

<!-- Add Category Modal -->
<div class="modal fade" id="addCategoryModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">
                    <i class="fas fa-plus-circle mr-2"></i>Add New Category
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST" id="addCategoryForm">
                <div class="modal-body">
                    <div class="form-group">
                        <label for="category_name">Category Name *</label>
                        <input type="text" class="form-control" id="category_name" name="category_name" required maxlength="100">
                        <small class="form-text text-muted">Enter a descriptive name for the category</small>
                    </div>
                    <div class="form-group">
                        <label for="category_description">Description</label>
                        <textarea class="form-control" id="category_description" name="category_description" rows="3" maxlength="500" placeholder="Optional description of this category..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_category" class="btn btn-success">
                        <i class="fas fa-save mr-2"></i>Save Category
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Category Modal -->
<div class="modal fade" id="editCategoryModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-warning text-white">
                <h5 class="modal-title">
                    <i class="fas fa-edit mr-2"></i>Edit Category
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST" id="editCategoryForm">
                <input type="hidden" id="edit_category_id" name="category_id">
                <div class="modal-body">
                    <div class="form-group">
                        <label for="edit_category_name">Category Name *</label>
                        <input type="text" class="form-control" id="edit_category_name" name="category_name" required maxlength="100">
                        <small class="form-text text-muted">Enter a descriptive name for the category</small>
                    </div>
                    <div class="form-group">
                        <label for="edit_category_description">Description</label>
                        <textarea class="form-control" id="edit_category_description" name="category_description" rows="3" maxlength="500" placeholder="Optional description of this category..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_category" class="btn btn-warning">
                        <i class="fas fa-save mr-2"></i>Update Category
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Auto-close alerts after 5 seconds
    setTimeout(() => {
        $('.alert').alert('close');
    }, 5000);

    // Reset add form when modal is closed
    $('#addCategoryModal').on('hidden.bs.modal', function () {
        $('#addCategoryForm')[0].reset();
    });

    // Form validation for add category
    $('#addCategoryForm').on('submit', function(e) {
        const categoryName = $('#category_name').val().trim();
        
        if (!categoryName) {
            e.preventDefault();
            alert('Please enter a category name.');
            return false;
        }
    });

    // Form validation for edit category
    $('#editCategoryForm').on('submit', function(e) {
        const categoryName = $('#edit_category_name').val().trim();
        
        if (!categoryName) {
            e.preventDefault();
            alert('Please enter a category name.');
            return false;
        }
    });
});

function editCategory(categoryId) {
    $.ajax({
        url: 'medical_service_categories.php',
        type: 'POST',
        data: {
            ajax_request: 1,
            get_category_details: 1,
            category_id: categoryId
        },
        success: function(response) {
            const category = JSON.parse(response);
            $('#edit_category_id').val(category.category_id);
            $('#edit_category_name').val(category.category_name);
            $('#edit_category_description').val(category.category_description || '');
            $('#editCategoryModal').modal('show');
        },
        error: function() {
            showAlert('Error loading category details. Please try again.', 'error');
        }
    });
}

function deleteCategory(categoryId, categoryName) {
    if (confirm(`Are you sure you want to delete the category "${categoryName}"? This action cannot be undone and will fail if there are active services using this category. Services using this category will be set to uncategorized.`)) {
        $.ajax({
            url: 'medical_service_categories.php',
            type: 'POST',
            data: {
                ajax_request: 1,
                delete_category: 1,
                category_id: categoryId
            },
            success: function(response) {
                const result = JSON.parse(response);
                if (result.success) {
                    showAlert(result.message, 'success');
                    // Reload page after successful deletion
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    showAlert(result.message, 'error');
                }
            },
            error: function() {
                showAlert('Error deleting category. Please try again.', 'error');
            }
        });
    }
}

function showAlert(message, type) {
    const alertClass = type === 'success' ? 'alert-success' : 'alert-danger';
    const iconClass = type === 'success' ? 'fa-check' : 'fa-exclamation-triangle';
    
    const alertHtml = `
        <div class="alert ${alertClass} alert-dismissible">
            <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
            <i class="icon fas ${iconClass}"></i>
            ${message}
        </div>
    `;
    
    $('#ajaxAlertContainer').html(alertHtml);
    
    // Auto-dismiss success alerts after 5 seconds
    if (type === 'success') {
        setTimeout(() => {
            $('.alert').alert('close');
        }, 5000);
    }
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + N for new category
    if (e.ctrlKey && e.keyCode === 78) {
        e.preventDefault();
        $('#addCategoryModal').modal('show');
    }
    // Ctrl + F for focus search
    if (e.ctrlKey && e.keyCode === 70) {
        e.preventDefault();
        $('input[name="q"]').focus();
    }
});
</script>

<style>
.card {
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    margin-bottom: 1rem;
}
.badge-pill {
    font-size: 0.8rem;
    padding: 0.4em 0.8em;
}
</style>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
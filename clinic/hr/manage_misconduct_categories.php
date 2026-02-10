<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';



// Handle actions
if (isset($_GET['action']) && isset($_GET['id'])) {
    $category_id = intval($_GET['id']);
    
    if ($_GET['action'] == 'toggle_status') {
        // Get current status
        $check_sql = "SELECT is_active FROM misconduct_categories WHERE category_id = ?";
        $check_stmt = $mysqli->prepare($check_sql);
        $check_stmt->bind_param("i", $category_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $category = $check_result->fetch_assoc();
        
        $new_status = $category['is_active'] ? 0 : 1;
        
        $sql = "UPDATE misconduct_categories SET is_active = ? WHERE category_id = ?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("ii", $new_status, $category_id);
        
        if ($stmt->execute()) {
            $status_text = $new_status ? 'activated' : 'deactivated';
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Category $status_text successfully!";
        } else {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error updating category: " . $stmt->error;
        }
        header("Location: manage_misconduct_categories.php");
        exit;
    }
    
    if ($_GET['action'] == 'delete' && isset($_GET['confirm'])) {
        // Check if category is in use
        $check_sql = "SELECT COUNT(*) as usage_count FROM misconduct_incidents WHERE category_id = ?";
        $check_stmt = $mysqli->prepare($check_sql);
        $check_stmt->bind_param("i", $category_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $usage = $check_result->fetch_assoc();
        
        if ($usage['usage_count'] > 0) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Cannot delete category: It is being used by " . $usage['usage_count'] . " incident(s).";
        } else {
            $sql = "DELETE FROM misconduct_categories WHERE category_id = ?";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param("i", $category_id);
            
            if ($stmt->execute()) {
                // Log the action
                $audit_sql = "INSERT INTO hr_audit_log (user_id, action, description, table_name, record_id) VALUES (?, 'misconduct_category_deleted', ?, 'misconduct_categories', ?)";
                $audit_stmt = $mysqli->prepare($audit_sql);
                $description = "Deleted misconduct category ID: $category_id";
                $audit_stmt->bind_param("isi", $_SESSION['user_id'], $description, $category_id);
                $audit_stmt->execute();
                
                $_SESSION['alert_type'] = "success";
                $_SESSION['alert_message'] = "Category deleted successfully!";
            } else {
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = "Error deleting category: " . $stmt->error;
            }
        }
        header("Location: manage_misconduct_categories.php");
        exit;
    }
}

// Handle form submission for adding/editing categories
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $category_name = sanitizeInput($_POST['category_name']);
    $description = sanitizeInput($_POST['description']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    if (empty($category_name)) {
        $_SESSION['alert_type'] = 'error';
        $_SESSION['alert_message'] = 'Category name is required.';
    } else {
        if (isset($_POST['category_id']) && !empty($_POST['category_id'])) {
            // Update existing category
            $category_id = intval($_POST['category_id']);
            $sql = "UPDATE misconduct_categories SET category_name = ?, description = ?, is_active = ? WHERE category_id = ?";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param("ssii", $category_name, $description, $is_active, $category_id);
            $action = 'updated';
        } else {
            // Insert new category
            $sql = "INSERT INTO misconduct_categories (category_name, description, is_active) VALUES (?, ?, ?)";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param("ssi", $category_name, $description, $is_active);
            $action = 'created';
        }
        
        if ($stmt->execute()) {
            $record_id = isset($category_id) ? $category_id : $mysqli->insert_id;
            
            // Log the action
            $audit_sql = "INSERT INTO hr_audit_log (user_id, action, description, table_name, record_id) VALUES (?, 'misconduct_category_$action', ?, 'misconduct_categories', ?)";
            $audit_stmt = $mysqli->prepare($audit_sql);
            $audit_description = "$action misconduct category: $category_name";
            $audit_stmt->bind_param("isi", $_SESSION['user_id'], $audit_description, $record_id);
            $audit_stmt->execute();
            
            $_SESSION['alert_type'] = 'success';
            $_SESSION['alert_message'] = "Category $action successfully!";
        } else {
            $_SESSION['alert_type'] = 'error';
            $_SESSION['alert_message'] = "Error saving category: " . $stmt->error;
        }
        
        header("Location: manage_misconduct_categories.php");
        exit;
    }
}

// Get categories
$sql = "SELECT mc.*, 
               (SELECT COUNT(*) FROM misconduct_incidents mi WHERE mi.category_id = mc.category_id) as incident_count
        FROM misconduct_categories mc 
        ORDER BY mc.is_active DESC, mc.category_name";
$categories_result = $mysqli->query($sql);

// Get category for editing
$edit_category = null;
if (isset($_GET['edit_id'])) {
    $edit_id = intval($_GET['edit_id']);
    $edit_sql = "SELECT * FROM misconduct_categories WHERE category_id = ?";
    $edit_stmt = $mysqli->prepare($edit_sql);
    $edit_stmt->bind_param("i", $edit_id);
    $edit_stmt->execute();
    $edit_result = $edit_stmt->get_result();
    $edit_category = $edit_result->fetch_assoc();
}
?>

<div class="card">
    <div class="card-header py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0"><i class="fas fa-fw fa-tags mr-2"></i>Manage Misconduct Categories</h3>
            <a href="misconduct_dashboard.php" class="btn btn-light btn-sm">
                <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
            </a>
        </div>
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
        
        <div class="row">
            <!-- Add/Edit Form -->
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header bg-<?php echo $edit_category ? 'warning' : 'primary'; ?> text-white py-2">
                        <h4 class="card-title mb-0">
                            <i class="fas fa-<?php echo $edit_category ? 'edit' : 'plus'; ?> mr-2"></i>
                            <?php echo $edit_category ? 'Edit Category' : 'Add New Category'; ?>
                        </h4>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <?php if ($edit_category): ?>
                                <input type="hidden" name="category_id" value="<?php echo $edit_category['category_id']; ?>">
                            <?php endif; ?>
                            
                            <div class="form-group">
                                <label for="category_name">Category Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="category_name" name="category_name" 
                                       value="<?php echo $edit_category ? htmlspecialchars($edit_category['category_name']) : ''; ?>" 
                                       required>
                            </div>
                            
                            <div class="form-group">
                                <label for="description">Description</label>
                                <textarea class="form-control" id="description" name="description" rows="3" 
                                          placeholder="Brief description of this misconduct category..."><?php echo $edit_category ? htmlspecialchars($edit_category['description']) : ''; ?></textarea>
                            </div>
                            
                            <div class="form-group">
                                <div class="custom-control custom-switch">
                                    <input type="checkbox" class="custom-control-input" id="is_active" name="is_active" 
                                           <?php echo (!$edit_category || $edit_category['is_active']) ? 'checked' : ''; ?>>
                                    <label class="custom-control-label" for="is_active">Active Category</label>
                                </div>
                                <small class="form-text text-muted">Inactive categories won't be available for new incidents</small>
                            </div>
                            
                            <div class="form-group">
                                <button type="submit" class="btn btn-<?php echo $edit_category ? 'warning' : 'primary'; ?>">
                                    <i class="fas fa-<?php echo $edit_category ? 'save' : 'plus'; ?> mr-2"></i>
                                    <?php echo $edit_category ? 'Update Category' : 'Add Category'; ?>
                                </button>
                                
                                <?php if ($edit_category): ?>
                                    <a href="manage_misconduct_categories.php" class="btn btn-secondary">
                                        <i class="fas fa-times mr-2"></i>Cancel
                                    </a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Categories List -->
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-info text-white py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-list mr-2"></i>Existing Categories</h4>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Category Name</th>
                                        <th>Description</th>
                                        <th>Incidents</th>
                                        <th>Status</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($categories_result->num_rows > 0): ?>
                                        <?php while ($category = $categories_result->fetch_assoc()): ?>
                                            <tr>
                                                <td>
                                                    <div class="font-weight-bold"><?php echo htmlspecialchars($category['category_name']); ?></div>
                                                    <small class="text-muted">ID: <?php echo $category['category_id']; ?></small>
                                                </td>
                                                <td>
                                                    <?php echo $category['description'] ? htmlspecialchars($category['description']) : '<span class="text-muted">No description</span>'; ?>
                                                </td>
                                                <td>
                                                    <span class="badge badge-secondary"><?php echo $category['incident_count']; ?> incidents</span>
                                                </td>
                                                <td>
                                                    <span class="badge badge-<?php echo $category['is_active'] ? 'success' : 'danger'; ?>">
                                                        <?php echo $category['is_active'] ? 'Active' : 'Inactive'; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <a href="manage_misconduct_categories.php?edit_id=<?php echo $category['category_id']; ?>" 
                                                           class="btn btn-primary" title="Edit">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <a href="manage_misconduct_categories.php?action=toggle_status&id=<?php echo $category['category_id']; ?>" 
                                                           class="btn btn-<?php echo $category['is_active'] ? 'warning' : 'success'; ?>" 
                                                           title="<?php echo $category['is_active'] ? 'Deactivate' : 'Activate'; ?>">
                                                            <i class="fas fa-<?php echo $category['is_active'] ? 'pause' : 'play'; ?>"></i>
                                                        </a>
                                                        <?php if ($category['incident_count'] == 0): ?>
                                                            <a href="manage_misconduct_categories.php?action=delete&id=<?php echo $category['category_id']; ?>&confirm=1" 
                                                               class="btn btn-danger confirm-link" 
                                                               title="Delete" 
                                                               data-confirm-message="Are you sure you want to delete this category?">
                                                                <i class="fas fa-trash"></i>
                                                            </a>
                                                        <?php else: ?>
                                                            <button class="btn btn-danger" disabled title="Cannot delete - category in use">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" class="text-center py-4">
                                                <i class="fas fa-tags fa-3x text-muted mb-3"></i>
                                                <h5 class="text-muted">No categories found</h5>
                                                <p class="text-muted">Add your first misconduct category using the form on the left.</p>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Statistics -->
                <div class="card mt-4">
                    <div class="card-header bg-success text-white py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-chart-bar mr-2"></i>Category Statistics</h4>
                    </div>
                    <div class="card-body">
                        <?php
                        // Get category statistics
                        $stats_sql = "
                            SELECT 
                                COUNT(*) as total_categories,
                                SUM(is_active) as active_categories,
                                SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_categories,
                                (SELECT COUNT(*) FROM misconduct_incidents) as total_incidents
                            FROM misconduct_categories
                        ";
                        $stats_result = $mysqli->query($stats_sql);
                        $stats = $stats_result->fetch_assoc();
                        
                        // Get top categories by incident count
                        $top_categories_sql = "
                            SELECT mc.category_name, COUNT(mi.incident_id) as incident_count
                            FROM misconduct_categories mc
                            LEFT JOIN misconduct_incidents mi ON mc.category_id = mi.category_id
                            GROUP BY mc.category_id, mc.category_name
                            ORDER BY incident_count DESC
                            LIMIT 5
                        ";
                        $top_categories_result = $mysqli->query($top_categories_sql);
                        ?>
                        
                        <div class="row text-center">
                            <div class="col-4 mb-3">
                                <div class="border rounded p-3 bg-light">
                                    <h3 class="text-primary mb-0"><?php echo $stats['total_categories']; ?></h3>
                                    <small class="text-muted">Total Categories</small>
                                </div>
                            </div>
                            <div class="col-4 mb-3">
                                <div class="border rounded p-3 bg-light">
                                    <h3 class="text-success mb-0"><?php echo $stats['active_categories']; ?></h3>
                                    <small class="text-muted">Active</small>
                                </div>
                            </div>
                            <div class="col-4 mb-3">
                                <div class="border rounded p-3 bg-light">
                                    <h3 class="text-warning mb-0"><?php echo $stats['inactive_categories']; ?></h3>
                                    <small class="text-muted">Inactive</small>
                                </div>
                            </div>
                        </div>
                        
                        <?php if ($top_categories_result->num_rows > 0): ?>
                            <h6 class="mt-4 mb-3">Top Categories by Incident Count:</h6>
                            <div class="list-group">
                                <?php while ($top_cat = $top_categories_result->fetch_assoc()): ?>
                                    <div class="list-group-item d-flex justify-content-between align-items-center">
                                        <?php echo htmlspecialchars($top_cat['category_name']); ?>
                                        <span class="badge badge-primary badge-pill"><?php echo $top_cat['incident_count']; ?></span>
                                    </div>
                                <?php endwhile; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Confirm links
    $('.confirm-link').click(function(e) {
        e.preventDefault();
        var message = $(this).data('confirm-message') || 'Are you sure?';
        var href = $(this).attr('href');
        
        if (confirm(message)) {
            window.location.href = href;
        }
    });
});
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
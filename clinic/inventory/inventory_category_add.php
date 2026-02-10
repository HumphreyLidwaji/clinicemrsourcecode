<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token'] ?? '');
    $category_name = sanitizeInput($_POST['category_name']);
    $category_type = sanitizeInput($_POST['category_type']);
    $description = sanitizeInput($_POST['description']);
    $is_active = intval($_POST['is_active']);

    // Validate CSRF token
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        header("Location: inventory_category_add.php");
        exit;
    }

    // Validate required fields
    if (empty($category_name)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Category name is required.";
    } else if (empty($category_type)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Category type is required.";
    } else {
        // Check for duplicate category name
        $check_sql = mysqli_query($mysqli, 
            "SELECT category_id FROM inventory_categories 
             WHERE category_name = '$category_name' 
             AND category_type = '$category_type'
             LIMIT 1"
        );

        if (mysqli_num_rows($check_sql) > 0) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "A category with this name and type already exists.";
        } else {
            // Insert new category
            $insert_sql = mysqli_query($mysqli,
                "INSERT INTO inventory_categories SET
                    category_name = '$category_name',
                    category_type = '$category_type',
                    description = '$description',
                    is_active = $is_active,
                    created_by = $session_user_id,
                    updated_by = $session_user_id"
            );

            if ($insert_sql) {
                $new_category_id = mysqli_insert_id($mysqli);
                
                // Log the activity
                mysqli_query($mysqli,
                    "INSERT INTO logs SET
                    log_type = 'Inventory',
                    log_action = 'Category Create',
                    log_description = 'Created inventory category: $category_name ($category_type)',
                    log_ip = '$session_ip',
                    log_user_agent = '$session_user_agent',
                    log_user_id = $session_user_id,
                    log_entity_id = $new_category_id"
                );

                $_SESSION['alert_type'] = "success";
                $_SESSION['alert_message'] = "Category <strong>$category_name</strong> added successfully!";
                
                // Redirect based on button clicked
                if (isset($_POST['add_another'])) {
                    // Stay on the same page to add another category
                    $_SESSION['form_data'] = null;
                    header("Location: inventory_category_add.php");
                    exit;
                } else {
                    // Redirect to categories list
                    header("Location: inventory_categories.php");
                    exit;
                }
            } else {
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = "Error adding category: " . mysqli_error($mysqli);
            }
        }
    }
    
    // If there was an error, preserve form data
    $_SESSION['form_data'] = [
        'category_name' => $category_name,
        'category_type' => $category_type,
        'description' => $description,
        'is_active' => $is_active
    ];
}

// Get form data from session if exists
$form_data = $_SESSION['form_data'] ?? null;
if (isset($_SESSION['form_data'])) {
    unset($_SESSION['form_data']);
}
?>

<div class="card">
    <div class="card-header bg-info py-2">
        <h3 class="card-title mt-2 mb-0"><i class="fas fa-fw fa-plus-circle mr-2"></i>Add New Category</h3>
        <div class="card-tools">
            <a href="inventory_categories.php" class="btn btn-secondary">
                <i class="fas fa-fw fa-arrow-left mr-2"></i>Back to Categories
            </a>
        </div>
    </div>

    <?php if (isset($_SESSION['alert_message'])): ?>
    <div class="card-body border-bottom py-2">
        <div class="alert alert-<?php echo $_SESSION['alert_type']; ?> alert-dismissible mb-0">
            <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
            <i class="icon fas fa-<?php echo $_SESSION['alert_type'] == 'success' ? 'check' : 'exclamation-triangle'; ?>"></i>
            <?php echo $_SESSION['alert_message']; ?>
        </div>
        <?php 
        unset($_SESSION['alert_type']);
        unset($_SESSION['alert_message']);
        ?>
    </div>
    <?php endif; ?>

    <div class="card-body">
        <div class="row">
            <div class="col-md-8">
                <form action="inventory_category_add.php" method="POST" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Category Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="category_name" 
                                       value="<?php echo isset($form_data) ? nullable_htmlentities($form_data['category_name']) : ''; ?>" 
                                       required autofocus
                                       placeholder="Enter category name" maxlength="100">
                                <small class="form-text text-muted">Unique name for this category (max 100 characters)</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Category Type <span class="text-danger">*</span></label>
                                <select class="form-control select2" name="category_type" required>
                                    <option value="">- Select Type -</option>
                                    <option value="Medical Supplies" <?php echo (isset($form_data) && $form_data['category_type'] == 'Medical Supplies') ? 'selected' : ''; ?>>Medical Supplies</option>
                                    <option value="Pharmacy" <?php echo (isset($form_data) && $form_data['category_type'] == 'Pharmacy') ? 'selected' : ''; ?>>Pharmacy</option>
                                    <option value="Equipment" <?php echo (isset($form_data) && $form_data['category_type'] == 'Equipment') ? 'selected' : ''; ?>>Equipment</option>
                                    <option value="Laboratory" <?php echo (isset($form_data) && $form_data['category_type'] == 'Laboratory') ? 'selected' : ''; ?>>Laboratory</option>
                                    <option value="General" <?php echo (isset($form_data) && $form_data['category_type'] == 'General') ? 'selected' : ''; ?>>General</option>
                                </select>
                                <small class="form-text text-muted">Type helps organize inventory by major groups</small>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Description</label>
                        <textarea class="form-control" name="description" rows="4" 
                                  placeholder="Describe this category and what types of items belong here..." maxlength="500"><?php echo isset($form_data) ? nullable_htmlentities($form_data['description']) : ''; ?></textarea>
                        <small class="form-text text-muted">Optional description to help identify this category's purpose (max 500 characters)</small>
                    </div>

                    <div class="form-group">
                        <label>Status <span class="text-danger">*</span></label>
                        <select class="form-control" name="is_active" required>
                            <option value="1" <?php echo (isset($form_data) && $form_data['is_active'] == 1) ? 'selected' : 'selected'; ?>>Active</option>
                            <option value="0" <?php echo (isset($form_data) && $form_data['is_active'] == 0) ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                        <small class="form-text text-muted">Active categories can be used for new inventory items</small>
                    </div>

                    <div class="form-group">
                        <button type="submit" name="save" class="btn btn-primary">
                            <i class="fas fa-fw fa-save mr-2"></i>Save Category
                        </button>
                        <button type="submit" name="add_another" class="btn btn-success">
                            <i class="fas fa-fw fa-plus-circle mr-2"></i>Save & Add Another
                        </button>
                        <a href="inventory_categories.php" class="btn btn-secondary">
                            <i class="fas fa-fw fa-times mr-2"></i>Cancel
                        </a>
                    </div>
                </form>
            </div>

            <div class="col-md-4">
                <!-- Help Card -->
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0"><i class="fas fa-question-circle mr-2"></i>Adding a New Category</h5>
                    </div>
                    <div class="card-body">
                        <h6 class="text-info">Required Information</h6>
                        <ul class="small pl-3 mb-3">
                            <li><strong>Category Name</strong> - Unique identifier</li>
                            <li><strong>Category Type</strong> - Main classification group</li>
                            <li><strong>Status</strong> - Active or Inactive</li>
                        </ul>

                        <h6 class="text-info">Category Types</h6>
                        <ul class="small pl-3 mb-3">
                            <li><strong>Medical Supplies</strong> - Bandages, gloves, syringes, disposables</li>
                            <li><strong>Pharmacy</strong> - Medications, drugs, prescriptions</li>
                            <li><strong>Equipment</strong> - Medical devices, instruments, machinery</li>
                            <li><strong>Laboratory</strong> - Test kits, lab supplies, reagents</li>
                            <li><strong>General</strong> - Office supplies, cleaning materials, others</li>
                        </ul>

                        <h6 class="text-info">Best Practices</h6>
                        <ul class="small pl-3">
                            <li>Use clear, descriptive names</li>
                            <li>Choose the most appropriate type</li>
                            <li>Add descriptions for complex categories</li>
                            <li>Keep categories organized by type</li>
                        </ul>

                        <div class="alert alert-warning small mt-3">
                            <i class="fas fa-exclamation-triangle mr-2"></i>
                            <strong>Note:</strong> Only active categories can be selected when adding new inventory items.
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="card mt-3">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0"><i class="fas fa-bolt mr-2"></i>Quick Actions</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="inventory_categories.php" class="btn btn-outline-primary btn-sm">
                                <i class="fas fa-list mr-2"></i>View All Categories
                            </a>
                            <a href="inventory_item_create.php" class="btn btn-outline-success btn-sm">
                                <i class="fas fa-boxes mr-2"></i>Add Inventory Item
                            </a>
                            <a href="inventory_items.php" class="btn btn-outline-info btn-sm">
                                <i class="fas fa-warehouse mr-2"></i>View Inventory
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Recent Categories -->
                <div class="card mt-3">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0"><i class="fas fa-history mr-2"></i>Recent Categories</h5>
                    </div>
                    <div class="card-body">
                        <?php
                        $recent_categories_sql = mysqli_query($mysqli,
                            "SELECT category_id, category_name, category_type, is_active 
                             FROM inventory_categories 
                             ORDER BY created_at DESC 
                             LIMIT 5"
                        );
                        
                        if (mysqli_num_rows($recent_categories_sql) > 0) {
                            echo '<div class="list-group list-group-flush small">';
                            while ($recent = mysqli_fetch_assoc($recent_categories_sql)) {
                                $status_class = $recent['is_active'] ? 'success' : 'danger';
                                $status_text = $recent['is_active'] ? 'Active' : 'Inactive';
                                $type_class = $recent['category_type'] == 'Pharmacy' ? 'success' : 
                                            ($recent['category_type'] == 'Medical Supplies' ? 'warning' : 
                                            ($recent['category_type'] == 'Equipment' ? 'dark' : 
                                            ($recent['category_type'] == 'Laboratory' ? 'info' : 'secondary')));
                                echo '
                                <div class="list-group-item px-0">
                                    <div class="font-weight-bold">' . nullable_htmlentities($recent['category_name']) . '</div>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="badge badge-' . $type_class . ' badge-sm">' . nullable_htmlentities($recent['category_type']) . '</span>
                                        <span class="badge badge-' . $status_class . ' badge-sm">' . $status_text . '</span>
                                    </div>
                                    <a href="inventory_category_edit.php?category_id=' . $recent['category_id'] . '" class="btn btn-xs btn-outline-secondary mt-1">
                                        <i class="fas fa-edit fa-xs"></i> Edit
                                    </a>
                                </div>';
                            }
                            echo '</div>';
                        } else {
                            echo '<p class="text-muted small mb-0">No categories added yet.</p>';
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
    // Initialize Select2
    $('.select2').select2();

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
.list-group-item {
    border: none;
    padding: 0.5rem 0;
}
.badge-sm {
    font-size: 0.7em;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
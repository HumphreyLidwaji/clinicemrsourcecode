<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/audit_functions.php'; // Added for logging

// Get category ID from URL
$category_id = intval($_GET['category_id'] ?? 0);

// AUDIT LOG: Access attempt for editing lab category
audit_log($mysqli, [
    'user_id'     => $_SESSION['user_id'] ?? null,
    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
    'action'      => 'ACCESS',
    'module'      => 'Lab Categories',
    'table_name'  => 'lab_test_categories',
    'entity_type' => 'lab_category',
    'record_id'   => $category_id,
    'patient_id'  => null,
    'visit_id'    => null,
    'description' => "Attempting to access lab_category_edit.php for category ID: " . $category_id,
    'status'      => 'ATTEMPT',
    'old_values'  => null,
    'new_values'  => null
]);

if ($category_id <= 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid category ID.";
    
    // AUDIT LOG: Invalid category ID
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'ACCESS',
        'module'      => 'Lab Categories',
        'table_name'  => 'lab_test_categories',
        'entity_type' => 'lab_category',
        'record_id'   => $category_id,
        'patient_id'  => null,
        'visit_id'    => null,
        'description' => "Invalid category ID: " . $category_id,
        'status'      => 'FAILED',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    header("Location: lab_category.php");
    exit;
}

// Get category details
$category_sql = "SELECT * FROM lab_test_categories WHERE category_id = ?";
$category_stmt = $mysqli->prepare($category_sql);
$category_stmt->bind_param("i", $category_id);
$category_stmt->execute();
$category_result = $category_stmt->get_result();

if ($category_result->num_rows === 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Category not found.";
    
    // AUDIT LOG: Category not found
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'ACCESS',
        'module'      => 'Lab Categories',
        'table_name'  => 'lab_test_categories',
        'entity_type' => 'lab_category',
        'record_id'   => $category_id,
        'patient_id'  => null,
        'visit_id'    => null,
        'description' => "Category ID " . $category_id . " not found",
        'status'      => 'FAILED',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    header("Location: lab_category.php");
    exit;
}

$category = $category_result->fetch_assoc();
$category_stmt->close();

// AUDIT LOG: Successful access to edit category page
audit_log($mysqli, [
    'user_id'     => $_SESSION['user_id'] ?? null,
    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
    'action'      => 'VIEW',
    'module'      => 'Lab Categories',
    'table_name'  => 'lab_test_categories',
    'entity_type' => 'lab_category',
    'record_id'   => $category_id,
    'patient_id'  => null,
    'visit_id'    => null,
    'description' => "Accessed lab_category_edit.php for category: " . $category['category_name'],
    'status'      => 'SUCCESS',
    'old_values'  => null,
    'new_values'  => null
]);

// Get test count for this category
$test_count_sql = "SELECT COUNT(*) as test_count FROM lab_tests WHERE category_id = ? AND is_active = 1";
$test_count_stmt = $mysqli->prepare($test_count_sql);
$test_count_stmt->bind_param("i", $category_id);
$test_count_stmt->execute();
$test_count_result = $test_count_stmt->get_result();
$test_count = $test_count_result->fetch_assoc()['test_count'];
$test_count_stmt->close();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    $category_name = sanitizeInput($_POST['category_name']);
    $category_description = sanitizeInput($_POST['category_description']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    // Prepare category data for audit log
    $category_data = [
        'category_name' => $category_name,
        'category_description' => $category_description,
        'is_active' => $is_active,
        'updated_by' => $session_user_id ?? null
    ];
    
    // Store old values for audit log
    $old_category_data = [
        'category_name' => $category['category_name'],
        'category_description' => $category['category_description'],
        'is_active' => $category['is_active']
    ];

    // AUDIT LOG: Attempt to update lab category
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'CATEGORY_UPDATE',
        'module'      => 'Lab Categories',
        'table_name'  => 'lab_test_categories',
        'entity_type' => 'lab_category',
        'record_id'   => $category_id,
        'patient_id'  => null,
        'visit_id'    => null,
        'description' => "Attempting to update lab test category: " . $old_category_data['category_name'] . " (ID: " . $category_id . ")",
        'status'      => 'ATTEMPT',
        'old_values'  => json_encode($old_category_data),
        'new_values'  => json_encode($category_data)
    ]);

    // Validate CSRF token
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        
        // AUDIT LOG: Invalid CSRF token
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'VALIDATION_FAILED',
            'module'      => 'Lab Categories',
            'table_name'  => 'lab_test_categories',
            'entity_type' => 'lab_category',
            'record_id'   => $category_id,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Invalid CSRF token when attempting to update lab test category: " . $old_category_data['category_name'],
            'status'      => 'FAILED',
            'old_values'  => json_encode($old_category_data),
            'new_values'  => json_encode($category_data)
        ]);
        
        header("Location: lab_category_edit.php?category_id=" . $category_id);
        exit;
    }

    // Validate required fields
    if (empty($category_name)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please fill in all required fields.";
        
        // AUDIT LOG: Validation failed - missing required fields
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'VALIDATION_FAILED',
            'module'      => 'Lab Categories',
            'table_name'  => 'lab_test_categories',
            'entity_type' => 'lab_category',
            'record_id'   => $category_id,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Validation failed: Missing required fields when updating lab test category: " . $old_category_data['category_name'],
            'status'      => 'FAILED',
            'old_values'  => json_encode($old_category_data),
            'new_values'  => json_encode($category_data)
        ]);
        
        header("Location: lab_category_edit.php?category_id=" . $category_id);
        exit;
    }

    // Check if category name already exists (excluding current category)
    $check_sql = "SELECT category_id FROM lab_test_categories WHERE category_name = ? AND category_id != ? AND is_active = 1";
    $check_stmt = $mysqli->prepare($check_sql);
    $check_stmt->bind_param("si", $category_name, $category_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($check_result->num_rows > 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Category name already exists. Please use a unique name.";
        
        // AUDIT LOG: Validation failed - duplicate category name
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'VALIDATION_FAILED',
            'module'      => 'Lab Categories',
            'table_name'  => 'lab_test_categories',
            'entity_type' => 'lab_category',
            'record_id'   => $category_id,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Validation failed: Duplicate category name '" . $category_name . "' when updating lab test category: " . $old_category_data['category_name'],
            'status'      => 'FAILED',
            'old_values'  => json_encode($old_category_data),
            'new_values'  => json_encode($category_data)
        ]);
        
        header("Location: lab_category_edit.php?category_id=" . $category_id);
        exit;
    }
    $check_stmt->close();

    // Update category
    $update_sql = "UPDATE lab_test_categories SET 
                  category_name = ?, 
                  category_description = ?, 
                  is_active = ?,
                  updated_by = ?,
                  updated_at = NOW()
                  WHERE category_id = ?";
    
    $update_stmt = $mysqli->prepare($update_sql);
    $update_stmt->bind_param("ssiii", $category_name, $category_description, $is_active, $session_user_id, $category_id);

    if ($update_stmt->execute()) {
        // AUDIT LOG: Successful category update
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'CATEGORY_UPDATE',
            'module'      => 'Lab Categories',
            'table_name'  => 'lab_test_categories',
            'entity_type' => 'lab_category',
            'record_id'   => $category_id,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Lab test category updated successfully: " . $old_category_data['category_name'] . " to " . $category_name,
            'status'      => 'SUCCESS',
            'old_values'  => json_encode($old_category_data),
            'new_values'  => json_encode(array_merge($category_data, [
                'category_id' => $category_id,
                'updated_at' => date('Y-m-d H:i:s')
            ]))
        ]);

        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Category updated successfully!";
        header("Location: lab_category.php");
        exit;

    } else {
        // AUDIT LOG: Failed category update
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'CATEGORY_UPDATE',
            'module'      => 'Lab Categories',
            'table_name'  => 'lab_test_categories',
            'entity_type' => 'lab_category',
            'record_id'   => $category_id,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Failed to update lab test category: " . $old_category_data['category_name'] . ". Error: " . $mysqli->error,
            'status'      => 'FAILED',
            'old_values'  => json_encode($old_category_data),
            'new_values'  => json_encode($category_data)
        ]);

        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error updating category: " . $mysqli->error;
        header("Location: lab_category_edit.php?category_id=" . $category_id);
        exit;
    }
}

// Get recent tests in this category
$recent_tests_sql = "SELECT test_code, test_name, price, turnaround_time 
                     FROM lab_tests 
                     WHERE category_id = ? AND is_active = 1 
                     ORDER BY created_at DESC 
                     LIMIT 5";
$recent_tests_stmt = $mysqli->prepare($recent_tests_sql);
$recent_tests_stmt->bind_param("i", $category_id);
$recent_tests_stmt->execute();
$recent_tests_result = $recent_tests_stmt->get_result();
?>
<div class="card">
    <div class="card-header bg-warning py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-edit mr-2"></i>Edit Category
            </h3>
            <div class="card-tools">
                <a href="lab_category.php" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Categories
                </a>
            </div>
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

        <form method="POST" id="categoryForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <div class="row">
                <div class="col-md-8">
                    <!-- Category Information -->
                    <div class="card card-primary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-info-circle mr-2"></i>Category Information</h3>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label for="category_name">Category Name *</label>
                                <input type="text" class="form-control" id="category_name" name="category_name" 
                                       value="<?php echo htmlspecialchars($category['category_name']); ?>" 
                                       placeholder="e.g., Hematology, Chemistry, Microbiology" required maxlength="100">
                                <small class="form-text text-muted">Unique name for the test category</small>
                            </div>

                            <div class="form-group">
                                <label for="category_description">Description</label>
                                <textarea class="form-control" id="category_description" name="category_description" rows="4" 
                                          placeholder="Describe the types of tests included in this category, common specimens, or special requirements..."
                                          maxlength="500"><?php echo htmlspecialchars($category['category_description'] ?? ''); ?></textarea>
                            </div>

                            <div class="form-group">
                                <div class="custom-control custom-switch">
                                    <input type="checkbox" class="custom-control-input" id="is_active" name="is_active" value="1" 
                                        <?php echo $category['is_active'] ? 'checked' : ''; ?>>
                                    <label class="custom-control-label" for="is_active">Active Category</label>
                                </div>
                                <small class="form-text text-muted">Inactive categories won't be available for new tests</small>
                            </div>
                        </div>
                    </div>

                    <!-- Category Preview -->
                    <div class="card card-info">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-eye mr-2"></i>Category Preview</h3>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-3">
                                <i class="fas fa-folder fa-3x text-info mb-2"></i>
                                <h4 id="preview_name" class="text-primary"><?php echo htmlspecialchars($category['category_name']); ?></h4>
                                <p id="preview_description" class="text-muted">
                                    <?php echo $category['category_description'] ? htmlspecialchars($category['category_description']) : 'Category description will appear here'; ?>
                                </p>
                                <div class="badge badge-<?php echo $category['is_active'] ? 'success' : 'secondary'; ?> badge-lg p-2">
                                    <i class="fas fa-<?php echo $category['is_active'] ? 'check-circle' : 'ban'; ?> mr-1"></i>
                                    <?php echo $category['is_active'] ? 'Active Category' : 'Inactive Category'; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <!-- Quick Actions -->
                    <div class="card card-success">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-bolt mr-2"></i>Quick Actions</h3>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-success btn-lg">
                                    <i class="fas fa-save mr-2"></i>Update Category
                                </button>
                                <button type="reset" class="btn btn-outline-secondary" onclick="resetForm()">
                                    <i class="fas fa-undo mr-2"></i>Reset Changes
                                </button>
                                <a href="lab_category.php" class="btn btn-outline-danger">
                                    <i class="fas fa-times mr-2"></i>Cancel
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Category Information -->
                    <div class="card card-info">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-chart-bar mr-2"></i>Category Statistics</h3>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-3">
                                <i class="fas fa-folder fa-3x text-info mb-2"></i>
                                <h5><?php echo htmlspecialchars($category['category_name']); ?></h5>
                            </div>
                            <hr>
                            <div class="small">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Total Tests:</span>
                                    <span class="font-weight-bold text-primary"><?php echo $test_count; ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Status:</span>
                                    <span class="font-weight-bold">
                                        <span class="badge badge-<?php echo $category['is_active'] ? 'success' : 'secondary'; ?>">
                                            <?php echo $category['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Created:</span>
                                    <span class="font-weight-bold">
                                        <?php echo $category['created_at'] ? date('M j, Y', strtotime($category['created_at'])) : 'Unknown'; ?>
                                    </span>
                                </div>
                                <?php if ($category['updated_at']): ?>
                                <div class="d-flex justify-content-between">
                                    <span>Last Updated:</span>
                                    <span class="font-weight-bold">
                                        <?php echo date('M j, Y', strtotime($category['updated_at'])); ?>
                                    </span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Tests -->
                    <div class="card card-secondary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-vial mr-2"></i>Recent Tests</h3>
                        </div>
                        <div class="card-body">
                            <?php if ($recent_tests_result->num_rows > 0): ?>
                                <div class="list-group list-group-flush">
                                    <?php while ($test = $recent_tests_result->fetch_assoc()): ?>
                                        <div class="list-group-item px-0 py-2">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1 text-primary"><?php echo htmlspecialchars($test['test_code']); ?></h6>
                                                <small class="text-success">$<?php echo number_format($test['price'], 2); ?></small>
                                            </div>
                                            <p class="mb-1 small"><?php echo htmlspecialchars($test['test_name']); ?></p>
                                            <small class="text-muted"><?php echo $test['turnaround_time']; ?> hours</small>
                                        </div>
                                    <?php endwhile; ?>
                                </div>
                                <?php if ($test_count > 5): ?>
                                    <div class="text-center mt-2">
                                        <a href="lab_tests.php?category=<?php echo $category_id; ?>" class="btn btn-sm btn-outline-primary">
                                            View All <?php echo $test_count; ?> Tests
                                        </a>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <p class="text-muted mb-0 text-center">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    No tests in this category
                                </p>
                                <div class="text-center mt-2">
                                    <a href="lab_test_add.php?category_id=<?php echo $category_id; ?>" class="btn btn-sm btn-success">
                                        <i class="fas fa-plus mr-1"></i>Add Test
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Danger Zone -->
                    <div class="card card-danger">
                        <div class="card-header bg-danger text-white">
                            <h3 class="card-title"><i class="fas fa-exclamation-triangle mr-2"></i>Danger Zone</h3>
                        </div>
                        <div class="card-body">
                            <p class="small text-muted mb-3">
                                Once you delete a category, there is no going back. Please be certain.
                            </p>
                            <button type="button" class="btn btn-outline-danger btn-sm btn-block" onclick="deleteCategory()">
                                <i class="fas fa-trash mr-2"></i>Delete This Category
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
$(document).ready(function() {
    // Update preview based on form changes
    function updatePreview() {
        const categoryName = $('#category_name').val() || 'Category Name';
        const categoryDescription = $('#category_description').val() || 'Category description will appear here';
        const isActive = $('#is_active').is(':checked');
        
        // Update preview elements
        $('#preview_name').text(categoryName);
        $('#preview_description').text(categoryDescription);
        
        // Update status badge
        const statusBadge = $('#preview_name').siblings('.badge');
        if (isActive) {
            statusBadge.removeClass('badge-secondary').addClass('badge-success').html('<i class="fas fa-check-circle mr-1"></i> Active Category');
        } else {
            statusBadge.removeClass('badge-success').addClass('badge-secondary').html('<i class="fas fa-ban mr-1"></i> Inactive Category');
        }
    }
    
    // Event listeners for real-time preview
    $('#category_name, #category_description, #is_active').on('input change', updatePreview);
    
    // Form validation
    $('#categoryForm').on('submit', function(e) {
        const categoryName = $('#category_name').val().trim();
        
        if (!categoryName) {
            e.preventDefault();
            alert('Please enter a category name.');
            return false;
        }
        
        // Show loading state
        $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Updating...').prop('disabled', true);
    });
});

function resetForm() {
    if (confirm('Are you sure you want to reset all changes?')) {
        // Reload the page to reset to original values
        window.location.reload();
    }
}

function deleteCategory() {
    if (confirm('Are you sure you want to delete this category? This action cannot be undone.')) {
        window.location.href = 'lab_category.php?delete_category=<?php echo $category_id; ?>';
    }
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + S to save
    if (e.ctrlKey && e.keyCode === 83) {
        e.preventDefault();
        $('#categoryForm').submit();
    }
    // Escape to cancel
    if (e.keyCode === 27) {
        window.location.href = 'lab_category.php';
    }
});
</script>

<style>
.card {
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    margin-bottom: 1rem;
}
.form-control:focus {
    border-color: #007bff;
    box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
}
.list-group-item {
    border: none;
    padding: 0.75rem 0;
}
.badge-lg {
    font-size: 0.9rem;
    padding: 0.5rem 1rem;
}
</style>

<?php
require_once "../includes/footer.php";
?>
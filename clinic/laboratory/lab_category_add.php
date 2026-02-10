<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/audit_functions.php'; // Added for logging

// AUDIT LOG: Access attempt for adding lab category
audit_log($mysqli, [
    'user_id'     => $_SESSION['user_id'] ?? null,
    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
    'action'      => 'ACCESS',
    'module'      => 'Lab Categories',
    'table_name'  => 'lab_test_categories',
    'entity_type' => 'lab_category',
    'record_id'   => null,
    'patient_id'  => null,
    'visit_id'    => null,
    'description' => "Attempting to access lab_category_add.php to create new lab test category",
    'status'      => 'ATTEMPT',
    'old_values'  => null,
    'new_values'  => null
]);

// AUDIT LOG: Successful access to add category page
audit_log($mysqli, [
    'user_id'     => $_SESSION['user_id'] ?? null,
    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
    'action'      => 'VIEW',
    'module'      => 'Lab Categories',
    'table_name'  => 'lab_test_categories',
    'entity_type' => 'lab_category',
    'record_id'   => null,
    'patient_id'  => null,
    'visit_id'    => null,
    'description' => "Accessed lab_category_add.php to create new lab test category",
    'status'      => 'SUCCESS',
    'old_values'  => null,
    'new_values'  => null
]);

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
        'created_by' => $session_user_id ?? null
    ];

    // AUDIT LOG: Attempt to create lab category
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'CATEGORY_CREATE',
        'module'      => 'Lab Categories',
        'table_name'  => 'lab_test_categories',
        'entity_type' => 'lab_category',
        'record_id'   => null,
        'patient_id'  => null,
        'visit_id'    => null,
        'description' => "Attempting to create new lab test category: " . $category_name,
        'status'      => 'ATTEMPT',
        'old_values'  => null,
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
            'record_id'   => null,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Invalid CSRF token when attempting to create lab test category: " . $category_name,
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => null
        ]);
        
        header("Location: lab_category_add.php");
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
            'record_id'   => null,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Validation failed: Missing required fields when creating lab test category",
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => json_encode($category_data)
        ]);
        
        header("Location: lab_category_add.php");
        exit;
    }

    // Check if category name already exists
    $check_sql = "SELECT category_id FROM lab_test_categories WHERE category_name = ? AND is_active = 1";
    $check_stmt = $mysqli->prepare($check_sql);
    $check_stmt->bind_param("s", $category_name);
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
            'record_id'   => null,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Validation failed: Duplicate category name '" . $category_name . "' when creating lab test category",
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => json_encode($category_data)
        ]);
        
        header("Location: lab_category_add.php");
        exit;
    }
    $check_stmt->close();

    // Insert new category
    $insert_sql = "INSERT INTO lab_test_categories SET 
                  category_name = ?, 
                  category_description = ?, 
                  is_active = ?,
                  created_by = ?,
                  created_at = NOW()";
    
    $insert_stmt = $mysqli->prepare($insert_sql);
    $insert_stmt->bind_param("ssii", $category_name, $category_description, $is_active, $session_user_id);

    if ($insert_stmt->execute()) {
        $category_id = $insert_stmt->insert_id;
        
        // AUDIT LOG: Successful category creation
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'CATEGORY_CREATE',
            'module'      => 'Lab Categories',
            'table_name'  => 'lab_test_categories',
            'entity_type' => 'lab_category',
            'record_id'   => $category_id,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Lab test category created successfully: " . $category_name,
            'status'      => 'SUCCESS',
            'old_values'  => null,
            'new_values'  => json_encode(array_merge($category_data, [
                'category_id' => $category_id,
                'created_at' => date('Y-m-d H:i:s')
            ]))
        ]);

        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Category created successfully!";
        header("Location: lab_tests.php");
        exit;

    } else {
        // AUDIT LOG: Failed category creation
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'CATEGORY_CREATE',
            'module'      => 'Lab Categories',
            'table_name'  => 'lab_test_categories',
            'entity_type' => 'lab_category',
            'record_id'   => null,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Failed to create lab test category. Error: " . $mysqli->error,
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => json_encode($category_data)
        ]);

        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error creating category: " . $mysqli->error;
        header("Location: lab_category_add.php");
        exit;
    }
}

// Get recent categories for reference
$recent_categories_sql = "SELECT category_name, category_description, 
                                 COUNT(lt.test_id) as test_count 
                          FROM lab_test_categories ltc 
                          LEFT JOIN lab_tests lt ON ltc.category_id = lt.category_id AND lt.is_active = 1
                          WHERE ltc.is_active = 1 
                          GROUP BY ltc.category_id 
                          ORDER BY ltc.created_at DESC 
                          LIMIT 5";
$recent_categories_result = $mysqli->query($recent_categories_sql);
?>
<div class="card">
    <div class="card-header bg-success py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-folder-plus mr-2"></i>Add New Test Category
            </h3>
            <div class="card-tools">
                <a href="lab_tests.php" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Tests
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
                                       placeholder="e.g., Hematology, Chemistry, Microbiology" required maxlength="100">
                                <small class="form-text text-muted">Unique name for the test category</small>
                            </div>

                            <div class="form-group">
                                <label for="category_description">Description</label>
                                <textarea class="form-control" id="category_description" name="category_description" rows="4" 
                                          placeholder="Describe the types of tests included in this category, common specimens, or special requirements..."
                                          maxlength="500"></textarea>
                            </div>

                            <div class="form-group">
                                <div class="custom-control custom-switch">
                                    <input type="checkbox" class="custom-control-input" id="is_active" name="is_active" value="1" checked>
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
                                <h4 id="preview_name" class="text-primary">New Category</h4>
                                <p id="preview_description" class="text-muted">Category description will appear here</p>
                                <div class="badge badge-success badge-lg p-2">
                                    <i class="fas fa-check-circle mr-1"></i>
                                    Active Category
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
                                    <i class="fas fa-save mr-2"></i>Create Category
                                </button>
                                <button type="reset" class="btn btn-outline-secondary" onclick="resetForm()">
                                    <i class="fas fa-undo mr-2"></i>Reset Form
                                </button>
                                <a href="lab_tests.php" class="btn btn-outline-danger">
                                    <i class="fas fa-times mr-2"></i>Cancel
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Common Category Templates -->
                    <div class="card card-info">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-magic mr-2"></i>Quick Templates</h3>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="loadTemplate('hematology')">
                                    <i class="fas fa-tint mr-2"></i>Hematology
                                </button>
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="loadTemplate('chemistry')">
                                    <i class="fas fa-flask mr-2"></i>Clinical Chemistry
                                </button>
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="loadTemplate('microbiology')">
                                    <i class="fas fa-microscope mr-2"></i>Microbiology
                                </button>
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="loadTemplate('immunology')">
                                    <i class="fas fa-shield-alt mr-2"></i>Immunology
                                </button>
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="loadTemplate('urinalysis')">
                                    <i class="fas fa-vial mr-2"></i>Urinalysis
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Categories -->
                    <div class="card card-secondary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-history mr-2"></i>Recent Categories</h3>
                        </div>
                        <div class="card-body">
                            <?php if ($recent_categories_result->num_rows > 0): ?>
                                <div class="list-group list-group-flush">
                                    <?php while ($category = $recent_categories_result->fetch_assoc()): ?>
                                        <div class="list-group-item px-0 py-2">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1 text-primary"><?php echo htmlspecialchars($category['category_name']); ?></h6>
                                                <span class="badge badge-info"><?php echo $category['test_count']; ?> tests</span>
                                            </div>
                                            <?php if ($category['category_description']): ?>
                                                <p class="mb-1 small text-muted"><?php echo htmlspecialchars($category['category_description']); ?></p>
                                            <?php endif; ?>
                                        </div>
                                    <?php endwhile; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted mb-0 text-center">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    No categories yet
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Validation Rules -->
                    <div class="card card-warning">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-check-circle mr-2"></i>Validation Rules</h3>
                        </div>
                        <div class="card-body">
                            <ul class="list-unstyled small">
                                <li class="mb-1">
                                    <i class="fas fa-check text-success mr-1"></i>
                                    Category name must be unique
                                </li>
                                <li class="mb-1">
                                    <i class="fas fa-check text-success mr-1"></i>
                                    Category name is required
                                </li>
                                <li class="mb-1">
                                    <i class="fas fa-check text-success mr-1"></i>
                                    Maximum 100 characters for name
                                </li>
                                <li>
                                    <i class="fas fa-check text-success mr-1"></i>
                                    Maximum 500 characters for description
                                </li>
                            </ul>
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
        const categoryName = $('#category_name').val() || 'New Category';
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
    
    // Initial preview update
    updatePreview();
    
    // Form validation
    $('#categoryForm').on('submit', function(e) {
        const categoryName = $('#category_name').val().trim();
        
        if (!categoryName) {
            e.preventDefault();
            alert('Please enter a category name.');
            return false;
        }
        
        // Show loading state
        $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Creating...').prop('disabled', true);
    });
});

// Template functions
function loadTemplate(templateType) {
    const templates = {
        'hematology': {
            category_name: 'Hematology',
            category_description: 'Tests related to blood cells, coagulation, and blood disorders including complete blood count, coagulation studies, and blood morphology.'
        },
        'chemistry': {
            category_name: 'Clinical Chemistry',
            category_description: 'Biochemical analysis of bodily fluids including liver function tests, renal function tests, electrolyte panels, and metabolic profiles.'
        },
        'microbiology': {
            category_name: 'Microbiology',
            category_description: 'Tests for identification of microorganisms including cultures, sensitivity testing, and molecular diagnostics for infectious diseases.'
        },
        'immunology': {
            category_name: 'Immunology',
            category_description: 'Tests related to immune system function including autoimmune diseases, allergies, immunodeficiencies, and serological testing.'
        },
        'urinalysis': {
            category_name: 'Urinalysis',
            category_description: 'Physical, chemical, and microscopic examination of urine for renal function, metabolic disorders, and urinary tract infections.'
        }
    };
    
    const template = templates[templateType];
    if (template) {
        $('#category_name').val(template.category_name);
        $('#category_description').val(template.category_description);
        
        // Trigger preview update
        $('input, textarea').trigger('input');
        
        // Show success message
        alert('Template loaded successfully! Please review and adjust as needed.');
    }
}

function resetForm() {
    if (confirm('Are you sure you want to reset all fields?')) {
        $('#categoryForm')[0].reset();
        $('input, textarea').trigger('input');
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
        window.location.href = 'lab_tests.php';
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

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
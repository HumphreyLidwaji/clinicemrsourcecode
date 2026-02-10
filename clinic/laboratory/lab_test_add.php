<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/audit_functions.php'; // Added for logging

// AUDIT LOG: Access attempt for adding lab test
audit_log($mysqli, [
    'user_id'     => $_SESSION['user_id'] ?? null,
    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
    'action'      => 'ACCESS',
    'module'      => 'Lab Tests',
    'table_name'  => 'lab_tests',
    'entity_type' => 'lab_test',
    'record_id'   => null,
    'patient_id'  => null,
    'visit_id'    => null,
    'description' => "Attempting to access lab_test_add.php to create new lab test",
    'status'      => 'ATTEMPT',
    'old_values'  => null,
    'new_values'  => null
]);

// AUDIT LOG: Successful access to add test page
audit_log($mysqli, [
    'user_id'     => $_SESSION['user_id'] ?? null,
    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
    'action'      => 'VIEW',
    'module'      => 'Lab Tests',
    'table_name'  => 'lab_tests',
    'entity_type' => 'lab_test',
    'record_id'   => null,
    'patient_id'  => null,
    'visit_id'    => null,
    'description' => "Accessed lab_test_add.php to create new lab test",
    'status'      => 'SUCCESS',
    'old_values'  => null,
    'new_values'  => null
]);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    $test_code = sanitizeInput($_POST['test_code']);
    $test_name = sanitizeInput($_POST['test_name']);
    $test_description = sanitizeInput($_POST['test_description']);
    $category_id = intval($_POST['category_id']);
    $price = floatval($_POST['price']);
    $turnaround_time = intval($_POST['turnaround_time']);
    $specimen_type = sanitizeInput($_POST['specimen_type']);
    $reference_range = sanitizeInput($_POST['reference_range']);
    $instructions = sanitizeInput($_POST['instructions']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    // Prepare test data for audit log
    $test_data = [
        'test_code' => $test_code,
        'test_name' => $test_name,
        'test_description' => $test_description,
        'category_id' => $category_id,
        'price' => $price,
        'turnaround_time' => $turnaround_time,
        'specimen_type' => $specimen_type,
        'reference_range' => $reference_range,
        'instructions' => $instructions,
        'is_active' => $is_active,
        'created_by' => $session_user_id ?? null
    ];

    // AUDIT LOG: Attempt to create lab test
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'TEST_CREATE',
        'module'      => 'Lab Tests',
        'table_name'  => 'lab_tests',
        'entity_type' => 'lab_test',
        'record_id'   => null,
        'patient_id'  => null,
        'visit_id'    => null,
        'description' => "Attempting to create new lab test: " . $test_name . " (" . $test_code . ")",
        'status'      => 'ATTEMPT',
        'old_values'  => null,
        'new_values'  => json_encode($test_data)
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
            'module'      => 'Lab Tests',
            'table_name'  => 'lab_tests',
            'entity_type' => 'lab_test',
            'record_id'   => null,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Invalid CSRF token when attempting to create lab test: " . $test_name,
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => null
        ]);
        
        header("Location: lab_test_add.php");
        exit;
    }

    // Validate required fields
    if (empty($test_code) || empty($test_name) || empty($category_id) || $price < 0 || $turnaround_time <= 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please fill in all required fields with valid values.";
        
        // AUDIT LOG: Validation failed - missing required fields
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'VALIDATION_FAILED',
            'module'      => 'Lab Tests',
            'table_name'  => 'lab_tests',
            'entity_type' => 'lab_test',
            'record_id'   => null,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Validation failed: Missing required fields or invalid values when creating lab test: " . $test_name,
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => json_encode($test_data)
        ]);
        
        header("Location: lab_test_add.php");
        exit;
    }

    // Check if test code already exists
    $check_sql = "SELECT test_id FROM lab_tests WHERE test_code = ? AND is_active = 1";
    $check_stmt = $mysqli->prepare($check_sql);
    $check_stmt->bind_param("s", $test_code);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($check_result->num_rows > 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Test code already exists. Please use a unique test code.";
        
        // AUDIT LOG: Validation failed - duplicate test code
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'VALIDATION_FAILED',
            'module'      => 'Lab Tests',
            'table_name'  => 'lab_tests',
            'entity_type' => 'lab_test',
            'record_id'   => null,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Validation failed: Duplicate test code '" . $test_code . "' when creating lab test: " . $test_name,
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => json_encode($test_data)
        ]);
        
        header("Location: lab_test_add.php");
        exit;
    }
    $check_stmt->close();

    // Start transaction
    $mysqli->begin_transaction();

    try {
        // Insert new test
        $insert_sql = "INSERT INTO lab_tests SET 
                      test_code = ?, 
                      test_name = ?, 
                      test_description = ?, 
                      category_id = ?, 
                      price = ?, 
                      turnaround_time = ?, 
                      specimen_type = ?, 
                      reference_range = ?, 
                      instructions = ?, 
                      is_active = ?,
                      created_by = ?,
                      created_at = NOW()";
        
        $insert_stmt = $mysqli->prepare($insert_sql);
        $insert_stmt->bind_param(
            "sssidisssii", 
            $test_code, $test_name, $test_description, $category_id, $price, 
            $turnaround_time, $specimen_type, $reference_range, $instructions, 
            $is_active, $session_user_id
        );

        if (!$insert_stmt->execute()) {
            throw new Exception("Error creating test: " . $mysqli->error);
        }
        
        $new_test_id = $insert_stmt->insert_id;
        $insert_stmt->close();

        // Get category name for billable item
        $category_sql = "SELECT category_name FROM lab_test_categories WHERE category_id = ?";
        $category_stmt = $mysqli->prepare($category_sql);
        $category_stmt->bind_param("i", $category_id);
        $category_stmt->execute();
        $category_result = $category_stmt->get_result();
        $category_row = $category_result->fetch_assoc();
        $category_name = $category_row ? $category_row['category_name'] : 'Lab Tests';
        $category_stmt->close();

        // Get or create billable category
        $billable_category_sql = "SELECT category_id FROM billable_categories WHERE category_name = ? AND parent_category_id IS NULL";
        $billable_category_stmt = $mysqli->prepare($billable_category_sql);
        $billable_category_stmt->bind_param("s", $category_name);
        $billable_category_stmt->execute();
        $billable_category_result = $billable_category_stmt->get_result();
        
        $billable_category_id = null;
        if ($billable_category_result->num_rows > 0) {
            $billable_category_row = $billable_category_result->fetch_assoc();
            $billable_category_id = $billable_category_row['category_id'];
        } else {
            // Create new billable category
            $create_category_sql = "INSERT INTO billable_categories SET 
                                   category_name = ?, 
                                   category_description = ?,
                                   is_active = 1,
                                   created_at = NOW()";
            $create_category_stmt = $mysqli->prepare($create_category_sql);
            $create_category_stmt->bind_param("ss", $category_name, $test_description);
            if (!$create_category_stmt->execute()) {
                throw new Exception("Error creating billable category: " . $mysqli->error);
            }
            $billable_category_id = $create_category_stmt->insert_id;
            $create_category_stmt->close();
        }
        $billable_category_stmt->close();

        // Create billable item for the lab test
        $billable_item_sql = "
            INSERT INTO billable_items SET 
                item_type = 'lab',
                source_table = 'lab_tests',
                source_id = ?,
                item_code = ?,
                item_name = ?,
                item_description = ?,
                unit_price = ?,
                cost_price = ?,
                tax_rate = 0.00,
                is_taxable = 0,
                category_id = ?,
                is_active = ?,
                created_by = ?,
                created_at = NOW()
        ";
        
        // Set cost price (70% of selling price as default)
        $cost_price = round($price * 0.7, 2);
        
        $billable_stmt = $mysqli->prepare($billable_item_sql);
        $billable_stmt->bind_param(
            "isssddiii",
            $new_test_id,          // i
            $test_code,            // s
            $test_name,            // s
            $test_description,     // s
            $price,                // d
            $cost_price,           // d
            $billable_category_id, // i
            $is_active,            // i
            $session_user_id       // i
        );

        if (!$billable_stmt->execute()) {
            throw new Exception("Error creating billable item: " . $mysqli->error);
        }
        $billable_item_id = $billable_stmt->insert_id;
        $billable_stmt->close();

        // Prepare billable item data for audit log
        $billable_data = [
            'item_type' => 'lab',
            'source_table' => 'lab_tests',
            'source_id' => $new_test_id,
            'item_code' => $test_code,
            'item_name' => $test_name,
            'item_description' => $test_description,
            'unit_price' => $price,
            'cost_price' => $cost_price,
            'tax_rate' => 0.00,
            'is_taxable' => 0,
            'category_id' => $billable_category_id,
            'is_active' => $is_active,
            'created_by' => $session_user_id,
            'billable_item_id' => $billable_item_id
        ];

        // Log the activity
        $activity_sql = "INSERT INTO lab_activities SET 
                        test_id = ?, 
                        activity_type = 'test_created', 
                        activity_description = ?, 
                        performed_by = ?, 
                        activity_date = NOW()";
        
        $activity_desc = "New test created: " . $test_name . " (" . $test_code . ") and added to billable items (ID: $billable_item_id)";
        $activity_stmt = $mysqli->prepare($activity_sql);
        $activity_stmt->bind_param("isi", $new_test_id, $activity_desc, $session_user_id);
        
        if (!$activity_stmt->execute()) {
            throw new Exception("Error logging activity: " . $mysqli->error);
        }
        $activity_stmt->close();

        // Commit transaction
        $mysqli->commit();

        // AUDIT LOG: Successful test creation
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'TEST_CREATE',
            'module'      => 'Lab Tests',
            'table_name'  => 'lab_tests',
            'entity_type' => 'lab_test',
            'record_id'   => $new_test_id,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Lab test created successfully: " . $test_name . " (" . $test_code . ")",
            'status'      => 'SUCCESS',
            'old_values'  => null,
            'new_values'  => json_encode(array_merge($test_data, [
                'test_id' => $new_test_id,
                'created_at' => date('Y-m-d H:i:s')
            ]))
        ]);

        // AUDIT LOG: Successful billable item creation
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'BILLABLE_ITEM_CREATE',
            'module'      => 'Billing',
            'table_name'  => 'billable_items',
            'entity_type' => 'billable_item',
            'record_id'   => $billable_item_id,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Billable item created for lab test: " . $test_name . " (" . $test_code . ")",
            'status'      => 'SUCCESS',
            'old_values'  => null,
            'new_values'  => json_encode($billable_data)
        ]);

        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Test created successfully and added to billable items!";
        header("Location: lab_test_details.php?test_id=" . $new_test_id);
        exit;

    } catch (Exception $e) {
        // Rollback transaction on error
        $mysqli->rollback();
        
        // AUDIT LOG: Failed test creation
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'TEST_CREATE',
            'module'      => 'Lab Tests',
            'table_name'  => 'lab_tests',
            'entity_type' => 'lab_test',
            'record_id'   => null,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Failed to create lab test: " . $test_name . ". Error: " . $e->getMessage(),
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => json_encode($test_data)
        ]);

        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = $e->getMessage();
        header("Location: lab_test_add.php");
        exit;
    }
}

// Get categories for dropdown
$categories_sql = "SELECT category_id, category_name FROM lab_test_categories WHERE is_active = 1 ORDER BY category_name";
$categories_result = $mysqli->query($categories_sql);

// Get recent tests for reference
$recent_tests_sql = "SELECT test_code, test_name, category_name, price, turnaround_time 
                     FROM lab_tests lt 
                     LEFT JOIN lab_test_categories ltc ON lt.category_id = ltc.category_id 
                     WHERE lt.is_active = 1 
                     ORDER BY lt.created_at DESC 
                     LIMIT 5";
$recent_tests_result = $mysqli->query($recent_tests_sql);

// Get billable categories for reference
$billable_categories_sql = "SELECT category_id, category_name FROM billable_categories WHERE is_active = 1 ORDER BY category_name";
$billable_categories_result = $mysqli->query($billable_categories_sql);
?>
<div class="card">
    <div class="card-header bg-success py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-plus-circle mr-2"></i>Add New Lab Test
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

        <form method="POST" id="testForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <div class="row">
                <div class="col-md-8">
                    <!-- Test Basic Information -->
                    <div class="card card-primary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-info-circle mr-2"></i>Test Information</h3>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="test_code">Test Code *</label>
                                        <input type="text" class="form-control" id="test_code" name="test_code" 
                                               placeholder="e.g., CBC, LFT, UA" required maxlength="20">
                                        <small class="form-text text-muted">Unique identifier for the test (will also be used as billable item code)</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="test_name">Test Name *</label>
                                        <input type="text" class="form-control" id="test_name" name="test_name" 
                                               placeholder="e.g., Complete Blood Count, Liver Function Test" required>
                                        <small class="form-text text-muted">Full descriptive name of the test (will be used as billable item name)</small>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="test_description">Description</label>
                                <textarea class="form-control" id="test_description" name="test_description" rows="3" 
                                          placeholder="Brief description of what this test measures and its purpose..."
                                          maxlength="500"></textarea>
                                <small class="form-text text-muted">This description will also be used for the billable item</small>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="category_id">Category *</label>
                                        <select class="form-control" id="category_id" name="category_id" required>
                                            <option value="">- Select Category -</option>
                                            <?php while ($category = $categories_result->fetch_assoc()): ?>
                                                <option value="<?php echo $category['category_id']; ?>">
                                                    <?php echo htmlspecialchars($category['category_name']); ?>
                                                </option>
                                            <?php endwhile; ?>
                                        </select>
                                        <small class="form-text text-muted">Category will be mapped to billable categories</small>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="price">Price ($) *</label>
                                        <input type="number" class="form-control" id="price" name="price" 
                                               step="0.01" min="0" value="0.00" required>
                                        <small class="form-text text-muted">This will be the unit price in billable items</small>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="turnaround_time">Turnaround (hours) *</label>
                                        <input type="number" class="form-control" id="turnaround_time" name="turnaround_time" 
                                               min="1" value="24" required>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Test Specifications -->
                    <div class="card card-info">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-flask mr-2"></i>Test Specifications</h3>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="specimen_type">Specimen Type *</label>
                                        <select class="form-control" id="specimen_type" name="specimen_type" required>
                                            <option value="">- Select Specimen Type -</option>
                                            <option value="Blood">Blood</option>
                                            <option value="Urine">Urine</option>
                                            <option value="Stool">Stool</option>
                                            <option value="Saliva">Saliva</option>
                                            <option value="Tissue">Tissue</option>
                                            <option value="Swab">Swab</option                                            <option value="Sputum">Sputum</option>
                                            <option value="CSF">Cerebrospinal Fluid</option>
                                            <option value="Other">Other</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="reference_range">Reference Range</label>
                                        <input type="text" class="form-control" id="reference_range" name="reference_range" 
                                               placeholder="e.g., 0-100 mg/dL, Negative, 4.0-11.0 x10^9/L">
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="instructions">Patient Instructions</label>
                                <textarea class="form-control" id="instructions" name="instructions" rows="4" 
                                          placeholder="Patient preparation instructions, fasting requirements, special considerations..."
                                          maxlength="1000"></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Billable Item Preview -->
                    <div class="card card-secondary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-dollar-sign mr-2"></i>Billable Item Preview</h3>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle mr-2"></i>
                                This test will automatically be added to the billable items catalog for billing purposes.
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Billable Item Type</label>
                                        <input type="text" class="form-control bg-light" value="Laboratory Test" readonly>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Billable Item Code</label>
                                        <input type="text" class="form-control bg-light" id="billable_item_code" value="" readonly>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Cost Price (Estimated)</label>
                                        <div class="input-group">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text">$</span>
                                            </div>
                                            <input type="text" class="form-control bg-light" id="cost_price_preview" value="0.00" readonly>
                                        </div>
                                        <small class="form-text text-muted">Auto-calculated as 70% of selling price</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Tax Settings</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control bg-light" value="Non-taxable (0%)" readonly>
                                            <div class="input-group-append">
                                                <span class="input-group-text"><i class="fas fa-percentage"></i></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label>Mapping Information</label>
                                <div class="alert alert-light">
                                    <small>
                                        <strong>Source:</strong> lab_tests table<br>
                                        <strong>Category:</strong> <span id="billable_category_preview">-</span><br>
                                        <strong>Status:</strong> <span id="billable_status_preview">Active</span>
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Test Preview -->
                    <div class="card card-warning">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-eye mr-2"></i>Test Preview</h3>
                        </div>
                        <div class="card-body">
                            <div class="row text-center">
                                <div class="col-md-4">
                                    <div class="border rounded p-3 bg-light">
                                        <div class="h5 text-muted">Test Code</div>
                                        <div class="h3 font-weight-bold text-primary" id="preview_code">
                                            -
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="border rounded p-3 bg-light">
                                        <div class="h5 text-muted">Price</div>
                                        <div class="h3 font-weight-bold text-success" id="preview_price">
                                            $0.00
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="border rounded p-3 bg-light">
                                        <div class="h5 text-muted">Turnaround</div>
                                        <div class="h3 font-weight-bold text-info" id="preview_turnaround">
                                            24 hrs
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mt-3">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong>Test Name:</strong> 
                                        <span id="preview_name" class="ml-2">-</span>
                                    </div>
                                    <div>
                                        <strong>Specimen:</strong> 
                                        <span id="preview_specimen" class="ml-2 badge badge-secondary">-</span>
                                    </div>
                                </div>
                                <div class="mt-2">
                                    <strong>Description:</strong> 
                                    <span id="preview_description" class="ml-2 text-muted">-</span>
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
                                    <i class="fas fa-save mr-2"></i>Create Test
                                </button>
                                <button type="reset" class="btn btn-outline-secondary" onclick="resetForm()">
                                    <i class="fas fa-undo mr-2"></i>Reset Form
                                </button>
                                <a href="lab_tests.php" class="btn btn-outline-danger">
                                    <i class="fas fa-times mr-2"></i>Cancel
                                </a>
                            </div>
                            
                            <div class="form-group mt-3">
                                <div class="custom-control custom-switch">
                                    <input type="checkbox" class="custom-control-input" id="is_active" name="is_active" value="1" checked>
                                    <label class="custom-control-label" for="is_active">Active Test</label>
                                </div>
                                <small class="form-text text-muted">Inactive tests won't be available for ordering or billing</small>
                            </div>
                        </div>
                    </div>

                    <!-- Billable Categories Reference -->
                    <div class="card card-primary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-tags mr-2"></i>Available Billable Categories</h3>
                        </div>
                        <div class="card-body">
                            <?php if ($billable_categories_result->num_rows > 0): ?>
                                <div class="list-group list-group-flush">
                                    <?php while ($category = $billable_categories_result->fetch_assoc()): ?>
                                        <div class="list-group-item px-0 py-2">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1 text-primary"><?php echo htmlspecialchars($category['category_name']); ?></h6>
                                                <small class="text-muted">ID: <?php echo $category['category_id']; ?></small>
                                            </div>
                                            <small class="text-muted">Will be automatically mapped from lab test category</small>
                                        </div>
                                    <?php endwhile; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted mb-0 text-center">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    No billable categories found. New ones will be created automatically.
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Common Test Templates -->
                    <div class="card card-info">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-magic mr-2"></i>Quick Templates</h3>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="loadTemplate('cbc')">
                                    <i class="fas fa-tint mr-2"></i>Complete Blood Count
                                </button>
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="loadTemplate('lft')">
                                    <i class="fas fa-liver mr-2"></i>Liver Function Test
                                </button>
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="loadTemplate('ua')">
                                    <i class="fas fa-flask mr-2"></i>Urinalysis
                                </button>
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="loadTemplate('lipid')">
                                    <i class="fas fa-heart mr-2"></i>Lipid Profile
                                </button>
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="loadTemplate('thyroid')">
                                    <i class="fas fa-butterfly mr-2"></i>Thyroid Panel
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Tests -->
                    <div class="card card-secondary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-history mr-2"></i>Recently Added Tests</h3>
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
                                            <div class="d-flex justify-content-between">
                                                <small class="text-muted"><?php echo htmlspecialchars($test['category_name']); ?></small>
                                                <small class="text-muted"><?php echo $test['turnaround_time']; ?>h</small>
                                            </div>
                                        </div>
                                    <?php endwhile; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted mb-0 text-center">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    No recent tests
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
                                    Test code must be unique
                                </li>
                                <li class="mb-1">
                                    <i class="fas fa-check text-success mr-1"></i>
                                    All required fields must be filled
                                </li>
                                <li class="mb-1">
                                    <i class="fas fa-check text-success mr-1"></i>
                                    Price cannot be negative
                                </li>
                                <li class="mb-1">
                                    <i class="fas fa-check text-success mr-1"></i>
                                    Turnaround time must be positive
                                </li>
                                <li>
                                    <i class="fas fa-check text-success mr-1"></i>
                                    Specimen type must be selected
                                </li>
                                <li class="mt-2">
                                    <i class="fas fa-dollar-sign text-info mr-1"></i>
                                    Test will be added to billable items
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
        const testCode = $('#test_code').val() || '-';
        const testName = $('#test_name').val() || '-';
        const testDescription = $('#test_description').val() || '-';
        const price = $('#price').val() || '0.00';
        const turnaround = $('#turnaround_time').val() || '24';
        const specimen = $('#specimen_type').val() || '-';
        const isActive = $('#is_active').is(':checked');
        const categoryId = $('#category_id').val();
        const categoryText = $('#category_id option:selected').text();
        
        // Calculate cost price (70% of selling price)
        const costPrice = parseFloat(price) * 0.7;
        
        // Update preview elements
        $('#preview_code').text(testCode);
        $('#preview_name').text(testName);
        $('#preview_description').text(testDescription);
        $('#preview_price').text('$' + parseFloat(price).toFixed(2));
        $('#preview_turnaround').text(turnaround + ' hrs');
        $('#preview_specimen').text(specimen);
        
        // Update billable item preview
        $('#billable_item_code').val(testCode);
        $('#cost_price_preview').val(costPrice.toFixed(2));
        $('#billable_category_preview').text(categoryText || '-');
        $('#billable_status_preview').text(isActive ? 'Active' : 'Inactive');
        $('#billable_status_preview').removeClass('text-success text-danger').addClass(isActive ? 'text-success' : 'text-danger');
    }
    
    // Event listeners for real-time preview
    $('#test_code, #test_name, #test_description, #price, #turnaround_time, #specimen_type, #category_id, #is_active').on('input change', updatePreview);
    
    // Initial preview update
    updatePreview();
    
    // Form validation
    $('#testForm').on('submit', function(e) {
        const testCode = $('#test_code').val().trim();
        const testName = $('#test_name').val().trim();
        const category = $('#category_id').val();
        const price = parseFloat($('#price').val());
        const turnaround = parseInt($('#turnaround_time').val());
        const specimen = $('#specimen_type').val();
        
        let isValid = true;
        let errorMessage = '';
        
        // Validate required fields
        if (!testCode) {
            isValid = false;
            errorMessage = 'Test code is required';
        } else if (!testName) {
            isValid = false;
            errorMessage = 'Test name is required';
        } else if (!category) {
            isValid = false;
            errorMessage = 'Category is required';
        } else if (price < 0) {
            isValid = false;
            errorMessage = 'Price cannot be negative';
        } else if (turnaround <= 0) {
            isValid = false;
            errorMessage = 'Turnaround time must be positive';
        } else if (!specimen) {
            isValid = false;
            errorMessage = 'Specimen type is required';
        }
        
        if (!isValid) {
            e.preventDefault();
            alert('Please fix the following error: ' + errorMessage);
            return false;
        }
        
        // Show loading state
        $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Creating...').prop('disabled', true);
    });
});

// Template functions
function loadTemplate(templateType) {
    const templates = {
        'cbc': {
            test_code: 'CBC',
            test_name: 'Complete Blood Count',
            test_description: 'Measures the cells that circulate in the blood including red blood cells, white blood cells, and platelets',
            category_id: '1', // Assuming Hematology is category 1
            price: '45.00',
            turnaround_time: '4',
            specimen_type: 'Blood',
            reference_range: 'See individual parameters',
            instructions: 'No special preparation required'
        },
        'lft': {
            test_code: 'LFT',
            test_name: 'Liver Function Test',
            test_description: 'Group of blood tests that detect inflammation and damage to the liver',
            category_id: '2', // Assuming Chemistry is category 2
            price: '65.00',
            turnaround_time: '24',
            specimen_type: 'Blood',
            reference_range: 'See individual enzymes',
            instructions: 'Fasting for 8-12 hours recommended'
        },
        'ua': {
            test_code: 'UA',
            test_name: 'Urinalysis',
            test_description: 'Physical, chemical, and microscopic examination of urine',
            category_id: '3', // Assuming Urine Tests is category 3
            price: '25.00',
            turnaround_time: '2',
            specimen_type: 'Urine',
            reference_range: 'Negative for abnormal findings',
            instructions: 'First morning urine sample preferred'
        },
        'lipid': {
            test_code: 'LIPID',
            test_name: 'Lipid Profile',
            test_description: 'Measures cholesterol and triglyceride levels in the blood',
            category_id: '2',
            price: '55.00',
            turnaround_time: '24',
            specimen_type: 'Blood',
            reference_range: 'Total Cholesterol: <200 mg/dL',
            instructions: 'Fasting for 9-12 hours required'
        },
        'thyroid': {
            test_code: 'THYROID',
            test_name: 'Thyroid Function Panel',
            test_description: 'Measures thyroid hormone levels to assess thyroid function',
            category_id: '2',
            price: '85.00',
            turnaround_time: '48',
            specimen_type: 'Blood',
            reference_range: 'TSH: 0.4-4.0 mIU/L',
            instructions: 'No special preparation required'
        }
    };
    
    const template = templates[templateType];
    if (template) {
        $('#test_code').val(template.test_code);
        $('#test_name').val(template.test_name);
        $('#test_description').val(template.test_description);
        $('#category_id').val(template.category_id);
        $('#price').val(template.price);
        $('#turnaround_time').val(template.turnaround_time);
        $('#specimen_type').val(template.specimen_type);
        $('#reference_range').val(template.reference_range);
        $('#instructions').val(template.instructions);
        
        // Trigger preview update
        $('input, select, textarea').trigger('change');
        
        // Show success message
        alert('Template loaded successfully! Please review and adjust as needed.');
    }
}

function resetForm() {
    if (confirm('Are you sure you want to reset all fields?')) {
        $('#testForm')[0].reset();
        $('input, select, textarea').trigger('change');
    }
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + S to save
    if (e.ctrlKey && e.keyCode === 83) {
        e.preventDefault();
        $('#testForm').submit();
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
.bg-light {
    background-color: #f8f9fa !important;
}
</style>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
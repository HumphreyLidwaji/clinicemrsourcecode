<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/audit_functions.php'; // Added for logging

// Get test ID from URL
$test_id = intval($_GET['test_id'] ?? 0);

// AUDIT LOG: Access attempt for editing lab test
audit_log($mysqli, [
    'user_id'     => $_SESSION['user_id'] ?? null,
    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
    'action'      => 'ACCESS',
    'module'      => 'Lab Tests',
    'table_name'  => 'lab_tests',
    'entity_type' => 'lab_test',
    'record_id'   => $test_id,
    'patient_id'  => null,
    'visit_id'    => null,
    'description' => "Attempting to access lab_test_edit.php for test ID: " . $test_id,
    'status'      => 'ATTEMPT',
    'old_values'  => null,
    'new_values'  => null
]);

if ($test_id <= 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid test ID.";
    
    // AUDIT LOG: Invalid test ID
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'ACCESS',
        'module'      => 'Lab Tests',
        'table_name'  => 'lab_tests',
        'entity_type' => 'lab_test',
        'record_id'   => $test_id,
        'patient_id'  => null,
        'visit_id'    => null,
        'description' => "Invalid test ID: " . $test_id,
        'status'      => 'FAILED',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    header("Location: lab_tests.php");
    exit;
}

// Get test details
$test_sql = "SELECT lt.*, ltc.category_name 
             FROM lab_tests lt 
             LEFT JOIN lab_test_categories ltc ON lt.category_id = ltc.category_id 
             WHERE lt.test_id = ? AND lt.is_active = 1";
$test_stmt = $mysqli->prepare($test_sql);
$test_stmt->bind_param("i", $test_id);
$test_stmt->execute();
$test_result = $test_stmt->get_result();

if ($test_result->num_rows === 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Test not found or has been deleted.";
    
    // AUDIT LOG: Test not found
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'ACCESS',
        'module'      => 'Lab Tests',
        'table_name'  => 'lab_tests',
        'entity_type' => 'lab_test',
        'record_id'   => $test_id,
        'patient_id'  => null,
        'visit_id'    => null,
        'description' => "Test ID " . $test_id . " not found or inactive",
        'status'      => 'FAILED',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    header("Location: lab_tests.php");
    exit;
}

$test = $test_result->fetch_assoc();
$test_stmt->close();

// AUDIT LOG: Successful access to edit test page
audit_log($mysqli, [
    'user_id'     => $_SESSION['user_id'] ?? null,
    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
    'action'      => 'VIEW',
    'module'      => 'Lab Tests',
    'table_name'  => 'lab_tests',
    'entity_type' => 'lab_test',
    'record_id'   => $test_id,
    'patient_id'  => null,
    'visit_id'    => null,
    'description' => "Accessed lab_test_edit.php for test: " . $test['test_name'] . " (" . $test['test_code'] . ")",
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
        'updated_by' => $session_user_id ?? null
    ];
    
    // Store old values for audit log
    $old_test_data = [
        'test_code' => $test['test_code'],
        'test_name' => $test['test_name'],
        'test_description' => $test['test_description'],
        'category_id' => $test['category_id'],
        'price' => $test['price'],
        'turnaround_time' => $test['turnaround_time'],
        'specimen_type' => $test['specimen_type'],
        'reference_range' => $test['reference_range'],
        'instructions' => $test['instructions'],
        'is_active' => $test['is_active']
    ];

    // AUDIT LOG: Attempt to update lab test
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'TEST_UPDATE',
        'module'      => 'Lab Tests',
        'table_name'  => 'lab_tests',
        'entity_type' => 'lab_test',
        'record_id'   => $test_id,
        'patient_id'  => null,
        'visit_id'    => null,
        'description' => "Attempting to update lab test: " . $old_test_data['test_name'] . " (" . $old_test_data['test_code'] . ")",
        'status'      => 'ATTEMPT',
        'old_values'  => json_encode($old_test_data),
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
            'record_id'   => $test_id,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Invalid CSRF token when attempting to update lab test: " . $old_test_data['test_name'],
            'status'      => 'FAILED',
            'old_values'  => json_encode($old_test_data),
            'new_values'  => json_encode($test_data)
        ]);
        
        header("Location: lab_test_edit.php?test_id=" . $test_id);
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
            'record_id'   => $test_id,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Validation failed: Missing required fields or invalid values when updating lab test: " . $old_test_data['test_name'],
            'status'      => 'FAILED',
            'old_values'  => json_encode($old_test_data),
            'new_values'  => json_encode($test_data)
        ]);
        
        header("Location: lab_test_edit.php?test_id=" . $test_id);
        exit;
    }

    // Check if test code already exists (excluding current test)
    $check_sql = "SELECT test_id FROM lab_tests WHERE test_code = ? AND test_id != ? AND is_active = 1";
    $check_stmt = $mysqli->prepare($check_sql);
    $check_stmt->bind_param("si", $test_code, $test_id);
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
            'record_id'   => $test_id,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Validation failed: Duplicate test code '" . $test_code . "' when updating lab test: " . $old_test_data['test_name'],
            'status'      => 'FAILED',
            'old_values'  => json_encode($old_test_data),
            'new_values'  => json_encode($test_data)
        ]);
        
        header("Location: lab_test_edit.php?test_id=" . $test_id);
        exit;
    }
    $check_stmt->close();

    // Update test
    $update_sql = "UPDATE lab_tests SET 
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
                  updated_by = ?,
                  updated_at = NOW()
                  WHERE test_id = ?";
    
    $update_stmt = $mysqli->prepare($update_sql);
    $update_stmt->bind_param(
        "sssidisssiii", 
        $test_code, $test_name, $test_description, $category_id, $price, 
        $turnaround_time, $specimen_type, $reference_range, $instructions, 
        $is_active, $session_user_id, $test_id
    );

    if ($update_stmt->execute()) {
        // Check for changes to log in description
        $changes = [];
        if ($old_test_data['test_code'] !== $test_code) {
            $changes[] = "Test code: " . $old_test_data['test_code'] . " to " . $test_code;
        }
        if ($old_test_data['test_name'] !== $test_name) {
            $changes[] = "Test name: " . $old_test_data['test_name'] . " to " . $test_name;
        }
        if ($old_test_data['price'] != $price) {
            $changes[] = "Price: " . $old_test_data['price'] . " to " . $price;
        }
        if ($old_test_data['is_active'] != $is_active) {
            $status_change = $is_active ? "activated" : "deactivated";
            $changes[] = "Status: " . $status_change;
        }
        
        $change_description = !empty($changes) ? implode(", ", $changes) : "No significant changes detected";
        
        // AUDIT LOG: Successful test update
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'TEST_UPDATE',
            'module'      => 'Lab Tests',
            'table_name'  => 'lab_tests',
            'entity_type' => 'lab_test',
            'record_id'   => $test_id,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Lab test updated: " . $old_test_data['test_name'] . " (" . $old_test_data['test_code'] . "). Changes: " . $change_description,
            'status'      => 'SUCCESS',
            'old_values'  => json_encode($old_test_data),
            'new_values'  => json_encode(array_merge($test_data, [
                'test_id' => $test_id,
                'updated_at' => date('Y-m-d H:i:s')
            ]))
        ]);

        // Log the activity
        $activity_sql = "INSERT INTO lab_activities SET 
                        test_id = ?, 
                        activity_type = 'test_updated', 
                        activity_description = ?, 
                        performed_by = ?, 
                        activity_date = NOW()";
        
        $activity_desc = "Test updated: " . $test_name . " (" . $test_code . ")";
        $activity_stmt = $mysqli->prepare($activity_sql);
        $activity_stmt->bind_param("isi", $test_id, $activity_desc, $session_user_id);
        $activity_stmt->execute();
        $activity_stmt->close();

        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Test updated successfully!";
        header("Location: lab_test_details.php?test_id=" . $test_id);
        exit;

    } else {
        // AUDIT LOG: Failed test update
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'TEST_UPDATE',
            'module'      => 'Lab Tests',
            'table_name'  => 'lab_tests',
            'entity_type' => 'lab_test',
            'record_id'   => $test_id,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Failed to update lab test: " . $old_test_data['test_name'] . ". Error: " . $mysqli->error,
            'status'      => 'FAILED',
            'old_values'  => json_encode($old_test_data),
            'new_values'  => json_encode($test_data)
        ]);

        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error updating test: " . $mysqli->error;
        header("Location: lab_test_edit.php?test_id=" . $test_id);
        exit;
    }
}

// Get categories for dropdown
$categories_sql = "SELECT category_id, category_name FROM lab_test_categories WHERE is_active = 1 ORDER BY category_name";
$categories_result = $mysqli->query($categories_sql);
?>
<div class="card">
    <div class="card-header bg-warning py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-edit mr-2"></i>Edit Lab Test
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
                                               value="<?php echo htmlspecialchars($test['test_code']); ?>" required maxlength="20">
                                        <small class="form-text text-muted">Unique identifier for the test</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="test_name">Test Name *</label>
                                        <input type="text" class="form-control" id="test_name" name="test_name" 
                                               value="<?php echo htmlspecialchars($test['test_name']); ?>" required>
                                        <small class="form-text text-muted">Full descriptive name of the test</small>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="test_description">Description</label>
                                <textarea class="form-control" id="test_description" name="test_description" rows="3" 
                                          placeholder="Brief description of what this test measures and its purpose..."
                                          maxlength="500"><?php echo htmlspecialchars($test['test_description'] ?? ''); ?></textarea>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="category_id">Category *</label>
                                        <select class="form-control" id="category_id" name="category_id" required>
                                            <option value="">- Select Category -</option>
                                            <?php while ($category = $categories_result->fetch_assoc()): ?>
                                                <option value="<?php echo $category['category_id']; ?>" 
                                                    <?php echo $test['category_id'] == $category['category_id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($category['category_name']); ?>
                                                </option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="price">Price ($) *</label>
                                        <input type="number" class="form-control" id="price" name="price" 
                                               value="<?php echo htmlspecialchars($test['price']); ?>" step="0.01" min="0" required>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="turnaround_time">Turnaround (hours) *</label>
                                        <input type="number" class="form-control" id="turnaround_time" name="turnaround_time" 
                                               value="<?php echo htmlspecialchars($test['turnaround_time']); ?>" min="1" required>
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
                                            <option value="Blood" <?php echo $test['specimen_type'] == 'Blood' ? 'selected' : ''; ?>>Blood</option>
                                            <option value="Urine" <?php echo $test['specimen_type'] == 'Urine' ? 'selected' : ''; ?>>Urine</option>
                                            <option value="Stool" <?php echo $test['specimen_type'] == 'Stool' ? 'selected' : ''; ?>>Stool</option>
                                            <option value="Saliva" <?php echo $test['specimen_type'] == 'Saliva' ? 'selected' : ''; ?>>Saliva</option>
                                            <option value="Tissue" <?php echo $test['specimen_type'] == 'Tissue' ? 'selected' : ''; ?>>Tissue</option>
                                            <option value="Swab" <?php echo $test['specimen_type'] == 'Swab' ? 'selected' : ''; ?>>Swab</option>
                                            <option value="Sputum" <?php echo $test['specimen_type'] == 'Sputum' ? 'selected' : ''; ?>>Sputum</option>
                                            <option value="CSF" <?php echo $test['specimen_type'] == 'CSF' ? 'selected' : ''; ?>>Cerebrospinal Fluid</option>
                                            <option value="Other" <?php echo $test['specimen_type'] == 'Other' ? 'selected' : ''; ?>>Other</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="reference_range">Reference Range</label>
                                        <input type="text" class="form-control" id="reference_range" name="reference_range" 
                                               value="<?php echo htmlspecialchars($test['reference_range'] ?? ''); ?>"
                                               placeholder="e.g., 0-100 mg/dL, Negative, 4.0-11.0 x10^9/L">
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="instructions">Patient Instructions</label>
                                <textarea class="form-control" id="instructions" name="instructions" rows="4" 
                                          placeholder="Patient preparation instructions, fasting requirements, special considerations..."
                                          maxlength="1000"><?php echo htmlspecialchars($test['instructions'] ?? ''); ?></textarea>
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
                                    <i class="fas fa-save mr-2"></i>Update Test
                                </button>
                                <button type="reset" class="btn btn-outline-secondary" onclick="resetForm()">
                                    <i class="fas fa-undo mr-2"></i>Reset Changes
                                </button>
                                <a href="lab_tests.php" class="btn btn-outline-danger">
                                    <i class="fas fa-times mr-2"></i>Cancel
                                </a>
                            </div>
                            
                            <div class="form-group mt-3">
                                <div class="custom-control custom-switch">
                                    <input type="checkbox" class="custom-control-input" id="is_active" name="is_active" value="1" 
                                        <?php echo $test['is_active'] ? 'checked' : ''; ?>>
                                    <label class="custom-control-label" for="is_active">Active Test</label>
                                </div>
                                <small class="form-text text-muted">Inactive tests won't be available for ordering</small>
                            </div>
                        </div>
                    </div>

                    <!-- Test Information -->
                    <div class="card card-info">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-cube mr-2"></i>Test Information</h3>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-3">
                                <i class="fas fa-vial fa-3x text-info mb-2"></i>
                                <h5><?php echo htmlspecialchars($test['test_name']); ?></h5>
                                <div class="text-muted"><?php echo htmlspecialchars($test['test_code']); ?></div>
                            </div>
                            <hr>
                            <div class="small">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Current Category:</span>
                                    <span class="font-weight-bold"><?php echo htmlspecialchars($test['category_name']); ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Current Price:</span>
                                    <span class="font-weight-bold text-success">$<?php echo number_format($test['price'], 2); ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Turnaround Time:</span>
                                    <span class="font-weight-bold"><?php echo $test['turnaround_time']; ?> hours</span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Specimen Type:</span>
                                    <span class="font-weight-bold"><?php echo htmlspecialchars($test['specimen_type']); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Activity Log -->
                    <div class="card card-secondary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-history mr-2"></i>Recent Activity</h3>
                        </div>
                        <div class="card-body">
                            <?php
                            $activity_sql = "SELECT la.activity_type, la.activity_description, la.activity_date, u.user_name 
                                             FROM lab_activities la 
                                             LEFT JOIN users u ON la.performed_by = u.user_id 
                                             WHERE la.test_id = ? 
                                             ORDER BY la.activity_date DESC 
                                             LIMIT 5";
                            $activity_stmt = $mysqli->prepare($activity_sql);
                            $activity_stmt->bind_param("i", $test_id);
                            $activity_stmt->execute();
                            $activity_result = $activity_stmt->get_result();

                            if ($activity_result->num_rows > 0): ?>
                                <div class="list-group list-group-flush">
                                    <?php while ($activity = $activity_result->fetch_assoc()): ?>
                                        <div class="list-group-item px-0 py-2">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1 text-primary"><?php echo htmlspecialchars($activity['activity_type']); ?></h6>
                                                <small><?php echo timeAgo($activity['activity_date']); ?></small>
                                            </div>
                                            <p class="mb-1 small"><?php echo htmlspecialchars($activity['activity_description']); ?></p>
                                            <small class="text-muted">By: <?php echo htmlspecialchars($activity['user_name']); ?></small>
                                        </div>
                                    <?php endwhile; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted mb-0 text-center">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    No recent activity
                                </p>
                            <?php endif; ?>
                            <?php $activity_stmt->close(); ?>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
$(document).ready(function() {
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
        $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Updating...').prop('disabled', true);
    });
});

function resetForm() {
    if (confirm('Are you sure you want to reset all changes?')) {
        // Reload the page to reset to original values
        window.location.reload();
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
</style>

<?php 
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
    ?>
    
<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/audit_functions.php'; // Added for logging

// Get drug ID from URL
$drug_id = intval($_GET['drug_id'] ?? 0);

if (!$drug_id) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "No drug specified.";
    
    // AUDIT LOG: No drug ID specified
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'DRUG_UPDATE',
        'module'      => 'Drugs',
        'table_name'  => 'drugs',
        'entity_type' => 'drug',
        'record_id'   => null,
        'patient_id'  => null,
        'visit_id'    => null,
        'description' => "Attempted to access drug edit page without specifying drug ID",
        'status'      => 'FAILED',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    header("Location: drugs_manage.php");
    exit;
}

// Fetch drug data
$drug_sql = "SELECT d.*, u.user_name as created_by_name, u2.user_name as updated_by_name
             FROM drugs d
             LEFT JOIN users u ON d.drug_created_by = u.user_id
             LEFT JOIN users u2 ON d.drug_updated_by = u2.user_id
             WHERE d.drug_id = ?";
$drug_stmt = $mysqli->prepare($drug_sql);
$drug_stmt->bind_param("i", $drug_id);
$drug_stmt->execute();
$drug_result = $drug_stmt->get_result();
$drug = $drug_result->fetch_assoc();

if (!$drug) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Drug not found.";
    
    // AUDIT LOG: Drug not found
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'DRUG_UPDATE',
        'module'      => 'Drugs',
        'table_name'  => 'drugs',
        'entity_type' => 'drug',
        'record_id'   => $drug_id,
        'patient_id'  => null,
        'visit_id'    => null,
        'description' => "Attempted to access drug edit page but drug ID " . $drug_id . " not found",
        'status'      => 'FAILED',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    header("Location: drugs_manage.php");
    exit;
}

// AUDIT LOG: Successful access to drug edit page
audit_log($mysqli, [
    'user_id'     => $_SESSION['user_id'] ?? null,
    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
    'action'      => 'VIEW',
    'module'      => 'Drugs',
    'table_name'  => 'drugs',
    'entity_type' => 'drug',
    'record_id'   => $drug_id,
    'patient_id'  => null,
    'visit_id'    => null,
    'description' => "Accessed drug edit page for: " . $drug['drug_name'] . " (ID: " . $drug_id . ")",
    'status'      => 'SUCCESS',
    'old_values'  => null,
    'new_values'  => [
        'drug_id' => $drug_id,
        'drug_name' => $drug['drug_name']
    ]
]);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    
    if ($csrf_token != $_SESSION['csrf_token']) {
        $_SESSION['alert_message'] = "CSRF token validation failed.";
        
        // AUDIT LOG: CSRF failure
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'DRUG_UPDATE',
            'module'      => 'Drugs',
            'table_name'  => 'drugs',
            'entity_type' => 'drug',
            'record_id'   => $drug_id,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "CSRF token validation failed while attempting to update drug ID: " . $drug_id,
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => null
        ]);
        
        header("Location: drugs_edit.php?drug_id=" . $drug_id);
        exit;
    }

    // Get form data
    $drug_name = sanitizeInput($_POST['drug_name']);
    $drug_generic_name = sanitizeInput($_POST['drug_generic_name']);
    $drug_form = sanitizeInput($_POST['drug_form']);
    $drug_strength = sanitizeInput($_POST['drug_strength']);
    $drug_manufacturer = sanitizeInput($_POST['drug_manufacturer']);
    $drug_category = sanitizeInput($_POST['drug_category']);
    $drug_description = sanitizeInput($_POST['drug_description']);
    $drug_is_active = isset($_POST['drug_is_active']) ? 1 : 0;

    // Prepare update data for audit log
    $update_data = [
        'drug_name' => $drug_name,
        'drug_generic_name' => $drug_generic_name,
        'drug_form' => $drug_form,
        'drug_strength' => $drug_strength,
        'drug_manufacturer' => $drug_manufacturer,
        'drug_category' => $drug_category,
        'drug_description' => $drug_description,
        'drug_is_active' => $drug_is_active
    ];

    // Prepare old values for audit log
    $old_values = [
        'drug_name' => $drug['drug_name'],
        'drug_generic_name' => $drug['drug_generic_name'],
        'drug_form' => $drug['drug_form'],
        'drug_strength' => $drug['drug_strength'],
        'drug_manufacturer' => $drug['drug_manufacturer'],
        'drug_category' => $drug['drug_category'],
        'drug_description' => $drug['drug_description'],
        'drug_is_active' => $drug['drug_is_active']
    ];

    // Validate required fields
    if (empty($drug_name)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Drug name is required.";
        
        // AUDIT LOG: Validation failure
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'DRUG_UPDATE',
            'module'      => 'Drugs',
            'table_name'  => 'drugs',
            'entity_type' => 'drug',
            'record_id'   => $drug_id,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Failed to update drug: Drug name is required for drug ID: " . $drug_id,
            'status'      => 'FAILED',
            'old_values'  => $old_values,
            'new_values'  => $update_data
        ]);
        
        header("Location: drugs_edit.php?drug_id=" . $drug_id);
        exit;
    }

    // Check if drug already exists (excluding current drug)
    $check_sql = "SELECT drug_id FROM drugs WHERE drug_name = ? AND drug_generic_name = ? AND drug_form = ? AND drug_id != ?";
    $check_stmt = $mysqli->prepare($check_sql);
    $check_stmt->bind_param("sssi", $drug_name, $drug_generic_name, $drug_form, $drug_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        $_SESSION['alert_type'] = "warning";
        $_SESSION['alert_message'] = "A drug with similar details already exists.";
        
        // AUDIT LOG: Duplicate drug
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'DRUG_UPDATE',
            'module'      => 'Drugs',
            'table_name'  => 'drugs',
            'entity_type' => 'drug',
            'record_id'   => $drug_id,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Failed to update drug: Duplicate drug found for drug ID: " . $drug_id,
            'status'      => 'FAILED',
            'old_values'  => $old_values,
            'new_values'  => $update_data
        ]);
        
        header("Location: drugs_edit.php?drug_id=" . $drug_id);
        exit;
    }

    // AUDIT LOG: Drug update attempt
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'DRUG_UPDATE',
        'module'      => 'Drugs',
        'table_name'  => 'drugs',
        'entity_type' => 'drug',
        'record_id'   => $drug_id,
        'patient_id'  => null,
        'visit_id'    => null,
        'description' => "Attempting to update drug: " . $drug['drug_name'] . " (ID: " . $drug_id . ")",
        'status'      => 'ATTEMPT',
        'old_values'  => $old_values,
        'new_values'  => $update_data
    ]);

    // Update drug
    $update_sql = "UPDATE drugs SET 
        drug_name = ?, drug_generic_name = ?, drug_form = ?, drug_strength = ?,
        drug_manufacturer = ?, drug_category = ?, drug_description = ?, drug_is_active = ?,
        drug_updated_at = NOW(), drug_updated_by = ?
        WHERE drug_id = ?";
    
    $update_stmt = $mysqli->prepare($update_sql);
    $update_stmt->bind_param(
        "sssssssiii",
        $drug_name,
        $drug_generic_name,
        $drug_form,
        $drug_strength,
        $drug_manufacturer,
        $drug_category,
        $drug_description,
        $drug_is_active,
        $session_user_id,
        $drug_id
    );

    if ($update_stmt->execute()) {
        // AUDIT LOG: Successful drug update
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'DRUG_UPDATE',
            'module'      => 'Drugs',
            'table_name'  => 'drugs',
            'entity_type' => 'drug',
            'record_id'   => $drug_id,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Drug updated successfully: " . $drug_name . " (ID: " . $drug_id . ")",
            'status'      => 'SUCCESS',
            'old_values'  => $old_values,
            'new_values'  => array_merge($update_data, [
                'drug_updated_by' => $session_user_id,
                'drug_updated_at' => date('Y-m-d H:i:s')
            ])
        ]);
        
        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Drug updated successfully!";
        header("Location: drugs_edit.php?drug_id=" . $drug_id);
        exit;
    } else {
        // AUDIT LOG: Failed drug update
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'DRUG_UPDATE',
            'module'      => 'Drugs',
            'table_name'  => 'drugs',
            'entity_type' => 'drug',
            'record_id'   => $drug_id,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Failed to update drug ID: " . $drug_id . ". Error: " . $mysqli->error,
            'status'      => 'FAILED',
            'old_values'  => $old_values,
            'new_values'  => $update_data
        ]);
        
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error updating drug: " . $mysqli->error;
        header("Location: drugs_edit.php?drug_id=" . $drug_id);
        exit;
    }
}

// Get drug usage statistics
$stats_sql = "SELECT 
    (SELECT COUNT(*) FROM inventory_items WHERE drug_id = ?) as inventory_count,
    (SELECT COUNT(*) FROM prescription_items WHERE pi_drug_id = ?) as prescription_count";
$stats_stmt = $mysqli->prepare($stats_sql);
$stats_stmt->bind_param("ii", $drug_id, $drug_id);
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();
$stats = $stats_result->fetch_assoc();

// Get common values for dropdowns
$common_forms = ['Tablet', 'Capsule', 'Syrup', 'Injection', 'Ointment', 'Cream', 'Drops', 'Inhaler', 'Spray', 'Suppository', 'Gel', 'Lotion', 'Patch', 'Powder', 'Solution', 'Suspension'];
$common_categories = ['Analgesic', 'Antibiotic', 'Antiviral', 'Antifungal', 'Antihypertensive', 'Antidiabetic', 'Antidepressant', 'Anticoagulant', 'Bronchodilator', 'Diuretic', 'Statin', 'PPI', 'Steroid', 'Vaccine', 'Vitamin', 'Mineral'];
$common_manufacturers = ['Pfizer', 'GSK', 'Novartis', 'Roche', 'Merck', 'Johnson & Johnson', 'Sanofi', 'AstraZeneca', 'Gilead', 'AbbVie', 'Bayer', 'Eli Lilly', 'Amgen', 'Bristol-Myers Squibb', 'Teva'];

// Get drug statistics for header
$total_drugs_sql = "SELECT COUNT(*) as count FROM drugs WHERE drug_is_active = 1";
$total_drugs_result = $mysqli->query($total_drugs_sql);
$total_drugs = $total_drugs_result->fetch_assoc()['count'];

$today_drugs_sql = "SELECT COUNT(*) as count FROM drugs WHERE DATE(drug_updated_at) = CURDATE()";
$today_drugs_result = $mysqli->query($today_drugs_sql);
$today_drugs = $today_drugs_result->fetch_assoc()['count'];

$inactive_drugs_sql = "SELECT COUNT(*) as count FROM drugs WHERE drug_is_active = 0";
$inactive_drugs_result = $mysqli->query($inactive_drugs_sql);
$inactive_drugs = $inactive_drugs_result->fetch_assoc()['count'];
?>
<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-edit mr-2"></i>Edit Drug: <?php echo htmlspecialchars($drug['drug_name']); ?>
        </h3>
        <div class="card-tools">
            <div class="btn-group">
                <a href="drugs_manage.php" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Drugs
                </a>
                <a href="prescription_add.php" class="btn btn-light ml-2">
                    <i class="fas fa-prescription mr-2"></i>New Prescription
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

        <!-- Registration Stats Header -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="btn-toolbar justify-content-between">
                    <div class="btn-group">
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Status:</strong> 
                            <span class="badge badge-<?php echo $drug['drug_is_active'] ? 'success' : 'danger'; ?> ml-2">
                                <?php echo $drug['drug_is_active'] ? 'Active' : 'Inactive'; ?>
                            </span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Drug ID:</strong> 
                            <span class="badge badge-primary ml-2">#<?php echo $drug_id; ?></span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Last Updated:</strong> 
                            <span class="badge badge-info ml-2"><?php echo date('M j, Y', strtotime($drug['drug_updated_at'])); ?></span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Inventory Items:</strong> 
                            <span class="badge badge-warning ml-2"><?php echo $stats['inventory_count'] ?? 0; ?></span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Prescriptions:</strong> 
                            <span class="badge badge-success ml-2"><?php echo $stats['prescription_count'] ?? 0; ?></span>
                        </span>
                    </div>
                    <div class="btn-group">
                        <a href="drugs_view.php?drug_id=<?php echo $drug_id; ?>" class="btn btn-info">
                            <i class="fas fa-eye mr-2"></i>View Drug
                        </a>
                        <a href="drugs_manage.php" class="btn btn-secondary">
                            <i class="fas fa-times mr-2"></i>Cancel
                        </a>
                        <button type="submit" form="drugForm" class="btn btn-primary">
                            <i class="fas fa-save mr-2"></i>Update Drug
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <form method="post" id="drugForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <div class="row">
                <!-- Left Column: Drug Information -->
                <div class="col-md-8">
                    <!-- Basic Information Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-info-circle mr-2"></i>Basic Information</h4>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="required">Drug Name</label>
                                        <div class="input-group">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text"><i class="fas fa-pills"></i></span>
                                            </div>
                                            <input type="text" class="form-control" name="drug_name" 
                                                   value="<?php echo htmlspecialchars($drug['drug_name']); ?>"
                                                   placeholder="Enter brand/proprietary name" required autofocus>
                                        </div>
                                        <small class="form-text text-muted">The commercial/brand name of the drug</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Generic Name</label>
                                        <div class="input-group">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text"><i class="fas fa-capsules"></i></span>
                                            </div>
                                            <input type="text" class="form-control" name="drug_generic_name" 
                                                   value="<?php echo htmlspecialchars($drug['drug_generic_name']); ?>"
                                                   placeholder="Enter generic/international name">
                                        </div>
                                        <small class="form-text text-muted">International non-proprietary name (INN)</small>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Dosage Form</label>
                                        <select class="form-control select2" name="drug_form" data-placeholder="Select dosage form">
                                            <option value=""></option>
                                            <?php foreach ($common_forms as $form): ?>
                                                <option value="<?php echo $form; ?>" <?php echo $drug['drug_form'] == $form ? 'selected' : ''; ?>>
                                                    <?php echo $form; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="form-text text-muted">e.g., Tablet, Capsule, Injection</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Strength</label>
                                        <div class="input-group">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text"><i class="fas fa-weight-hanging"></i></span>
                                            </div>
                                            <input type="text" class="form-control" name="drug_strength" 
                                                   value="<?php echo htmlspecialchars($drug['drug_strength']); ?>"
                                                   placeholder="e.g., 500mg, 250mg/5ml">
                                        </div>
                                        <small class="form-text text-muted">Drug concentration or strength</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Manufacturer & Category Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-building mr-2"></i>Manufacturer & Category</h4>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Manufacturer</label>
                                        <select class="form-control select2" name="drug_manufacturer" 
                                                data-placeholder="Select or type manufacturer" data-tags="true">
                                            <option value=""></option>
                                            <?php foreach ($common_manufacturers as $manufacturer): ?>
                                                <option value="<?php echo $manufacturer; ?>" <?php echo $drug['drug_manufacturer'] == $manufacturer ? 'selected' : ''; ?>>
                                                    <?php echo $manufacturer; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="form-text text-muted">You can type to add a new manufacturer</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Drug Category</label>
                                        <select class="form-control select2" name="drug_category" 
                                                data-placeholder="Select or type category" data-tags="true">
                                            <option value=""></option>
                                            <?php foreach ($common_categories as $category): ?>
                                                <option value="<?php echo $category; ?>" <?php echo $drug['drug_category'] == $category ? 'selected' : ''; ?>>
                                                    <?php echo $category; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="form-text text-muted">Therapeutic category or class</small>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label>Description / Clinical Notes</label>
                                <textarea class="form-control" name="drug_description" rows="4" 
                                          placeholder="Enter any clinical notes, indications, or special instructions"><?php echo htmlspecialchars($drug['drug_description']); ?></textarea>
                                <small class="form-text text-muted">Optional clinical information</small>
                            </div>
                        </div>
                    </div>

                    <!-- Metadata Information Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-database mr-2"></i>Metadata</h4>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Created By</label>
                                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($drug['created_by_name'] ?? 'NA'); ?>" readonly>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Created Date</label>
                                        <input type="text" class="form-control" value="<?php echo date('M j, Y H:i', strtotime($drug['drug_created_at'])); ?>" readonly>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Last Updated By</label>
                                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($drug['updated_by_name'] ?? 'NA'); ?>" readonly>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Last Updated Date</label>
                                        <input type="text" class="form-control" value="<?php echo date('M j, Y H:i', strtotime($drug['drug_updated_at'])); ?>" readonly>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Actions & Preview -->
                <div class="col-md-4">
                    <!-- Edit Actions Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-bolt mr-2"></i>Drug Actions</h4>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <?php if (SimplePermission::any("drug_edit")) { ?>
                                <button type="submit" class="btn btn-primary btn-lg" id="submitBtn">
                                    <i class="fas fa-save mr-2"></i>Update Drug
                                </button>
                                <?php } ?>
                                <button type="reset" class="btn btn-outline-secondary" id="resetBtn">
                                    <i class="fas fa-redo mr-2"></i>Reset Form
                                </button>
                                <a href="drugs_manage.php" class="btn btn-outline-danger">
                                    <i class="fas fa-times mr-2"></i>Cancel
                                </a>
                            </div>
                            <hr>
                            <div class="small">
                                <p class="mb-2"><strong>Keyboard Shortcuts:</strong></p>
                                <div class="row">
                                    <div class="col-6">
                                        <span class="badge badge-light">Ctrl + N</span> Drug Name
                                    </div>
                                    <div class="col-6">
                                        <span class="badge badge-light">Ctrl + F</span> Form
                                    </div>
                                </div>
                                <div class="row mt-1">
                                    <div class="col-6">
                                        <span class="badge badge-light">Ctrl + S</span> Save
                                    </div>
                                    <div class="col-6">
                                        <span class="badge badge-light">Ctrl + R</span> Reset
                                    </div>
                                </div>
                                <div class="row mt-1">
                                    <div class="col-6">
                                        <span class="badge badge-light">Ctrl + V</span> View
                                    </div>
                                    <div class="col-6">
                                        <span class="badge badge-light">Esc</span> Cancel
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Drug Preview Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-eye mr-2"></i>Drug Preview</h4>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-3">
                                <div class="preview-icon mb-2">
                                    <i class="fas fa-pills fa-2x text-info"></i>
                                </div>
                                <h5 id="preview_drug_name"><?php echo htmlspecialchars($drug['drug_name']); ?></h5>
                                <div id="preview_generic_name" class="text-muted small"><?php echo htmlspecialchars($drug['drug_generic_name'] ?: 'Generic Name'); ?></div>
                            </div>
                            <hr>
                            <div class="small">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Form:</span>
                                    <span id="preview_form" class="font-weight-bold text-primary"><?php echo htmlspecialchars($drug['drug_form'] ?: '-'); ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Strength:</span>
                                    <span id="preview_strength" class="font-weight-bold text-primary"><?php echo htmlspecialchars($drug['drug_strength'] ?: '-'); ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Manufacturer:</span>
                                    <span id="preview_manufacturer" class="font-weight-bold text-primary"><?php echo htmlspecialchars($drug['drug_manufacturer'] ?: '-'); ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Category:</span>
                                    <span id="preview_category" class="font-weight-bold text-primary"><?php echo htmlspecialchars($drug['drug_category'] ?: '-'); ?></span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Status:</span>
                                    <span id="preview_status" class="font-weight-bold <?php echo $drug['drug_is_active'] ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo $drug['drug_is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Add Options Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-bolt mr-2"></i>Quick Options</h4>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-12">
                                    <p class="small mb-2"><strong>Common Forms:</strong></p>
                                    <div class="btn-group btn-group-sm d-flex mb-3">
                                        <button type="button" class="btn btn-outline-primary flex-fill form-quick-btn" data-form="Tablet">
                                            Tablet
                                        </button>
                                        <button type="button" class="btn btn-outline-primary flex-fill form-quick-btn" data-form="Capsule">
                                            Capsule
                                        </button>
                                        <button type="button" class="btn btn-outline-primary flex-fill form-quick-btn" data-form="Syrup">
                                            Syrup
                                        </button>
                                    </div>
                                    <div class="btn-group btn-group-sm d-flex mb-3">
                                        <button type="button" class="btn btn-outline-primary flex-fill form-quick-btn" data-form="Injection">
                                            Injection
                                        </button>
                                        <button type="button" class="btn btn-outline-primary flex-fill form-quick-btn" data-form="Ointment">
                                            Ointment
                                        </button>
                                        <button type="button" class="btn btn-outline-primary flex-fill form-quick-btn" data-form="Cream">
                                            Cream
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-12">
                                    <p class="small mb-2"><strong>Common Strengths:</strong></p>
                                    <div class="btn-group btn-group-sm d-flex flex-wrap mb-3">
                                        <button type="button" class="btn btn-outline-info m-1 strength-quick-btn" data-strength="250mg">
                                            250mg
                                        </button>
                                        <button type="button" class="btn btn-outline-info m-1 strength-quick-btn" data-strength="500mg">
                                            500mg
                                        </button>
                                        <button type="button" class="btn btn-outline-info m-1 strength-quick-btn" data-strength="100mg">
                                            100mg
                                        </button>
                                        <button type="button" class="btn btn-outline-info m-1 strength-quick-btn" data-strength="200mg">
                                            200mg
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-12">
                                    <p class="small mb-2"><strong>Common Categories:</strong></p>
                                    <div class="btn-group btn-group-sm d-flex mb-3">
                                        <button type="button" class="btn btn-outline-success flex-fill category-quick-btn" data-category="Analgesic">
                                            Analgesic
                                        </button>
                                        <button type="button" class="btn btn-outline-success flex-fill category-quick-btn" data-category="Antibiotic">
                                            Antibiotic
                                        </button>
                                    </div>
                                    <div class="btn-group btn-group-sm d-flex mb-3">
                                        <button type="button" class="btn btn-outline-success flex-fill category-quick-btn" data-category="Antihypertensive">
                                            Antihypertensive
                                        </button>
                                        <button type="button" class="btn btn-outline-success flex-fill category-quick-btn" data-category="Antidiabetic">
                                            Antidiabetic
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Today's Statistics Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-chart-bar mr-2"></i>Today's Statistics</h4>
                        </div>
                        <div class="card-body">
                            <div class="row text-center">
                                <div class="col-6">
                                    <div class="stat-box bg-primary-light p-2 rounded mb-2">
                                        <i class="fas fa-pills fa-lg text-primary mb-1"></i>
                                        <h5 class="mb-0"><?php echo $today_drugs; ?></h5>
                                        <small class="text-muted">Updated Today</small>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="stat-box bg-success-light p-2 rounded mb-2">
                                        <i class="fas fa-check-circle fa-lg text-success mb-1"></i>
                                        <h5 class="mb-0"><?php echo $total_drugs; ?></h5>
                                        <small class="text-muted">Total Active</small>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="stat-box bg-danger-light p-2 rounded">
                                        <i class="fas fa-times-circle fa-lg text-danger mb-1"></i>
                                        <h5 class="mb-0"><?php echo $inactive_drugs; ?></h5>
                                        <small class="text-muted">Inactive</small>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="stat-box bg-warning-light p-2 rounded">
                                        <i class="fas fa-boxes fa-lg text-warning mb-1"></i>
                                        <h5 class="mb-0"><?php echo $stats['inventory_count'] ?? 0; ?></h5>
                                        <small class="text-muted">Inventory Items</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Form Tips Card -->
                    <div class="card">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-lightbulb mr-2"></i>Quick Tips</h4>
                        </div>
                        <div class="card-body">
                            <ul class="list-unstyled small mb-0">
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-success mr-2"></i>
                                    <span class="text-danger">*</span> denotes required fields
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-success mr-2"></i>
                                    Use quick buttons for common options
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-success mr-2"></i>
                                    Inactive drugs won't appear in prescriptions
                                </li>
                                <li>
                                    <i class="fas fa-check-circle text-success mr-2"></i>
                                    Drug name must be unique per form
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Drug Status Card -->
            <div class="row mt-4">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-toggle-on mr-2"></i>Drug Status</h4>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="form-group mb-0">
                                        <div class="custom-control custom-switch custom-switch-lg">
                                            <input type="checkbox" class="custom-control-input" id="drug_is_active" name="drug_is_active" value="1" <?php echo $drug['drug_is_active'] ? 'checked' : ''; ?>>
                                            <label class="custom-control-label" for="drug_is_active">
                                                <span class="h5">Active Drug</span>
                                            </label>
                                        </div>
                                        <small class="form-text text-muted">Active drugs appear in prescription options. Inactive drugs are archived.</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Form Actions Footer -->
            <div class="row mt-4">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-body py-2">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <small class="text-muted">
                                        <i class="fas fa-info-circle mr-1"></i>
                                        Last updated: <?php echo date('M j, Y H:i', strtotime($drug['drug_updated_at'])); ?>
                                        by <?php echo htmlspecialchars($drug['updated_by_name'] ?? 'System'); ?>
                                    </small>
                                </div>
                                <div>
                                    <a href="drugs_view.php?drug_id=<?php echo $drug_id; ?>" class="btn btn-info btn-sm">
                                        <i class="fas fa-eye mr-1"></i>View Drug
                                    </a>
                                    <a href="drugs_manage.php" class="btn btn-secondary btn-sm">
                                        <i class="fas fa-times mr-1"></i>Cancel
                                    </a>
                                    <button type="reset" class="btn btn-outline-secondary btn-sm">
                                        <i class="fas fa-redo mr-1"></i>Reset
                                    </button>
                                    <?php if (SimplePermission::any("drug_edit")) { ?>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save mr-1"></i>Update Drug
                                    </button>
                                    <?php } ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize Select2
    $('.select2').select2({
        width: '100%',
        placeholder: "Select...",
        theme: 'bootstrap',
        minimumResultsForSearch: 10,
        tags: true
    });

    // Quick form buttons
    $('.form-quick-btn').click(function() {
        var form = $(this).data('form');
        $('select[name="drug_form"]').val(form).trigger('change');
        $('#preview_form').text(form);
    });

    // Quick category buttons
    $('.category-quick-btn').click(function() {
        var category = $(this).data('category');
        $('select[name="drug_category"]').val(category).trigger('change');
        $('#preview_category').text(category);
    });

    // Quick strength buttons
    $('.strength-quick-btn').click(function() {
        var strength = $(this).data('strength');
        $('input[name="drug_strength"]').val(strength);
        $('#preview_strength').text(strength);
    });

    // Update preview when drug name changes
    $('input[name="drug_name"]').on('input', function() {
        var drugName = $(this).val();
        $('#preview_drug_name').text(drugName || 'Drug Name');
    });

    // Update preview when generic name changes
    $('input[name="drug_generic_name"]').on('input', function() {
        var genericName = $(this).val();
        $('#preview_generic_name').text(genericName || 'Generic Name');
    });

    // Update preview when form changes
    $('select[name="drug_form"]').change(function() {
        var form = $(this).val();
        $('#preview_form').text(form || '-');
    });

    // Update preview when strength changes
    $('input[name="drug_strength"]').on('input', function() {
        var strength = $(this).val();
        $('#preview_strength').text(strength || '-');
    });

    // Update preview when manufacturer changes
    $('select[name="drug_manufacturer"]').change(function() {
        var manufacturer = $(this).val();
        $('#preview_manufacturer').text(manufacturer || '-');
    });

    // Update preview when category changes
    $('select[name="drug_category"]').change(function() {
        var category = $(this).val();
        $('#preview_category').text(category || '-');
    });

    // Update preview when status changes
    $('#drug_is_active').change(function() {
        var isActive = $(this).is(':checked');
        var statusText = isActive ? 'Active' : 'Inactive';
        var statusClass = isActive ? 'text-success' : 'text-danger';
        $('#preview_status').text(statusText).removeClass().addClass('font-weight-bold ' + statusClass);
    });

    // Form validation
    $('#drugForm').on('submit', function(e) {
        var isValid = true;
        
        // Clear previous validation errors
        $('.is-invalid').removeClass('is-invalid');
        $('.select2-selection').removeClass('is-invalid');
        
        // Validate drug name
        if (!$('input[name="drug_name"]').val()) {
            isValid = false;
            $('input[name="drug_name"]').addClass('is-invalid');
        }

        if (!isValid) {
            e.preventDefault();
            
            // Show error message
            if (!$('#formErrorAlert').length) {
                $('#drugForm').prepend(
                    '<div class="alert alert-danger alert-dismissible" id="formErrorAlert">' +
                    '<button type="button" class="close" data-dismiss="alert">&times;</button>' +
                    '<i class="fas fa-exclamation-triangle mr-2"></i>' +
                    'Please fill in all required fields marked with *' +
                    '</div>'
                );
            }
            
            // Scroll to first error
            $('html, body').animate({
                scrollTop: $('.is-invalid').first().offset().top - 100
            }, 500);
            
            return false;
        }

        // Show loading state
        $('#submitBtn').html('<i class="fas fa-spinner fa-spin mr-2"></i>Updating...').prop('disabled', true);
        $('#resetBtn').prop('disabled', true);
    });

    // Reset form to original values
    $('#resetBtn').click(function() {
        // Reset form fields to original values
        $('input[name="drug_name"]').val('<?php echo addslashes($drug['drug_name']); ?>');
        $('input[name="drug_generic_name"]').val('<?php echo addslashes($drug['drug_generic_name']); ?>');
        $('input[name="drug_strength"]').val('<?php echo addslashes($drug['drug_strength']); ?>');
        $('textarea[name="drug_description"]').val('<?php echo addslashes($drug['drug_description']); ?>');
        
        // Reset Select2 fields
        $('select[name="drug_form"]').val('<?php echo $drug['drug_form']; ?>').trigger('change');
        $('select[name="drug_manufacturer"]').val('<?php echo $drug['drug_manufacturer']; ?>').trigger('change');
        $('select[name="drug_category"]').val('<?php echo $drug['drug_category']; ?>').trigger('change');
        
        // Reset status
        $('#drug_is_active').prop('checked', <?php echo $drug['drug_is_active'] ? 'true' : 'false'; ?>);
        
        // Reset preview
        updatePreview();
        
        // Clear validation errors
        $('.is-invalid').removeClass('is-invalid');
        $('.select2-selection').removeClass('is-invalid');
        $('#formErrorAlert').remove();
    });

    // Keyboard shortcuts
    $(document).keydown(function(e) {
        // Ctrl + N to focus on drug name
        if (e.ctrlKey && e.keyCode === 78) {
            e.preventDefault();
            $('input[name="drug_name"]').focus();
        }
        // Ctrl + F to focus on form field
        if (e.ctrlKey && e.keyCode === 70) {
            e.preventDefault();
            $('select[name="drug_form"]').select2('open');
        }
        // Ctrl + S to submit form
        if (e.ctrlKey && e.keyCode === 83) {
            e.preventDefault();
            $('#drugForm').submit();
        }
        // Ctrl + R to reset form
        if (e.ctrlKey && e.keyCode === 82) {
            e.preventDefault();
            $('#resetBtn').click();
        }
        // Ctrl + V to view drug
        if (e.ctrlKey && e.keyCode === 86) {
            e.preventDefault();
            window.location.href = 'drugs_view.php?drug_id=<?php echo $drug_id; ?>';
        }
        // Escape to cancel
        if (e.keyCode === 27) {
            window.location.href = 'drugs_manage.php';
        }
    });

    // Auto-close alerts after 5 seconds
    setTimeout(function() {
        $('.alert').fadeOut('slow');
    }, 5000);

    // Function to update preview
    function updatePreview() {
        $('#preview_drug_name').text($('input[name="drug_name"]').val() || 'Drug Name');
        $('#preview_generic_name').text($('input[name="drug_generic_name"]').val() || 'Generic Name');
        $('#preview_form').text($('select[name="drug_form"]').val() || '-');
        $('#preview_strength').text($('input[name="drug_strength"]').val() || '-');
        $('#preview_manufacturer').text($('select[name="drug_manufacturer"]').val() || '-');
        $('#preview_category').text($('select[name="drug_category"]').val() || '-');
        
        var isActive = $('#drug_is_active').is(':checked');
        var statusText = isActive ? 'Active' : 'Inactive';
        var statusClass = isActive ? 'text-success' : 'text-danger';
        $('#preview_status').text(statusText).removeClass().addClass('font-weight-bold ' + statusClass);
    }

    // Update preview on form changes
    $('input, select, textarea').on('input change', updatePreview);

    // Auto-focus on drug name field
    $('input[name="drug_name"]').focus();
});
</script>

<style>
.required:after {
    content: " *";
    color: #dc3545;
}
.select2-container .select2-selection.is-invalid {
    border-color: #dc3545;
}
.preview-icon {
    width: 60px;
    height: 60px;
    background-color: #e3f2fd;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto;
}
.stat-box {
    transition: all 0.3s ease;
}
.stat-box:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}
.bg-primary-light {
    background-color: rgba(13, 110, 253, 0.1);
}
.bg-success-light {
    background-color: rgba(25, 135, 84, 0.1);
}
.bg-danger-light {
    background-color: rgba(220, 53, 69, 0.1);
}
.bg-warning-light {
    background-color: rgba(255, 193, 7, 0.1);
}
.card-title {
    font-size: 1.1rem;
    font-weight: 600;
}
.custom-switch.custom-switch-lg {
    padding-bottom: 1rem;
    padding-left: 2.25rem;
}
.custom-switch.custom-switch-lg .custom-control-label {
    padding-left: 1.75rem;
    padding-top: 0.5rem;
    font-size: 1.25rem;
}
.custom-switch.custom-switch-lg .custom-control-label::before {
    width: 3rem;
    height: 1.5rem;
    border-radius: 1.5rem;
    left: -2.25rem;
}
.custom-switch.custom-switch-lg .custom-control-label::after {
    width: 1.25rem;
    height: 1.25rem;
    border-radius: 1.25rem;
    left: calc(-2.25rem + 0.25rem);
}
.custom-switch.custom-switch-lg .custom-control-input:checked ~ .custom-control-label::after {
    transform: translateX(1.5rem);
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
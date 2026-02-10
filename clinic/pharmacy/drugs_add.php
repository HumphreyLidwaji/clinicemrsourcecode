<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/audit_functions.php'; // Added for logging

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    
    if ($csrf_token != $_SESSION['csrf_token']) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "CSRF token validation failed.";
        
        // AUDIT LOG: CSRF failure
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'DRUG_CREATE',
            'module'      => 'Drugs',
            'table_name'  => 'drugs',
            'entity_type' => 'drug',
            'record_id'   => null,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "CSRF token validation failed while attempting to add new drug",
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => null
        ]);
        
        header("Location: drugs_add.php");
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
    
    // Additional fields for inventory/billable items
    $unit_price = !empty($_POST['unit_price']) ? floatval($_POST['unit_price']) : 0.00;
    $cost_price = !empty($_POST['cost_price']) ? floatval($_POST['cost_price']) : ($unit_price * 0.7); // Default 30% markup
    $initial_quantity = !empty($_POST['initial_quantity']) ? intval($_POST['initial_quantity']) : 0;
    $item_code = !empty($_POST['item_code']) ? sanitizeInput($_POST['item_code']) : generateDrugCode($drug_name, $drug_strength, $drug_form);
    $item_unit_measure = !empty($_POST['item_unit_measure']) ? sanitizeInput($_POST['item_unit_measure']) : getDefaultUnit($drug_form);
    $tax_rate = !empty($_POST['tax_rate']) ? floatval($_POST['tax_rate']) : 0.00;
    $is_taxable = isset($_POST['is_taxable']) ? 1 : 0;
    $location_id = !empty($_POST['location_id']) ? intval($_POST['location_id']) : null;

    // Prepare drug data for audit log
    $drug_data = [
        'drug_name' => $drug_name,
        'drug_generic_name' => $drug_generic_name,
        'drug_form' => $drug_form,
        'drug_strength' => $drug_strength,
        'drug_manufacturer' => $drug_manufacturer,
        'drug_category' => $drug_category,
        'drug_description' => $drug_description,
        'drug_is_active' => $drug_is_active,
        'unit_price' => $unit_price,
        'cost_price' => $cost_price,
        'initial_quantity' => $initial_quantity,
        'item_code' => $item_code,
        'item_unit_measure' => $item_unit_measure,
        'tax_rate' => $tax_rate,
        'is_taxable' => $is_taxable,
        'location_id' => $location_id,
        'add_to_inventory' => isset($_POST['add_to_inventory']) ? $_POST['add_to_inventory'] : '0',
        'add_to_billable' => isset($_POST['add_to_billable']) ? $_POST['add_to_billable'] : '0'
    ];

    // Validate required fields
    if (empty($drug_name)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Drug name is required.";
        
        // AUDIT LOG: Validation failure
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'DRUG_CREATE',
            'module'      => 'Drugs',
            'table_name'  => 'drugs',
            'entity_type' => 'drug',
            'record_id'   => null,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Failed to add drug: Drug name is required. Drug data: " . json_encode($drug_data),
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => $drug_data
        ]);
        
        header("Location: drugs_add.php");
        exit;
    }

    // Start transaction
    $mysqli->begin_transaction();
    
    try {
        // Check if drug already exists
        $check_sql = "SELECT drug_id FROM drugs WHERE drug_name = ? AND drug_generic_name = ? AND drug_form = ?";
        $check_stmt = $mysqli->prepare($check_sql);
        $check_stmt->bind_param("sss", $drug_name, $drug_generic_name, $drug_form);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            throw new Exception("A drug with similar details already exists.");
        }

        // AUDIT LOG: Drug creation attempt
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'DRUG_CREATE',
            'module'      => 'Drugs',
            'table_name'  => 'drugs',
            'entity_type' => 'drug',
            'record_id'   => null,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Attempting to add new drug: " . $drug_name,
            'status'      => 'ATTEMPT',
            'old_values'  => null,
            'new_values'  => $drug_data
        ]);

        // Insert new drug
        $insert_sql = "INSERT INTO drugs (
            drug_name, drug_generic_name, drug_form, drug_strength, 
            drug_manufacturer, drug_category, drug_description, drug_is_active,
            drug_created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $insert_stmt = $mysqli->prepare($insert_sql);
        $insert_stmt->bind_param(
            "sssssssii",
            $drug_name,
            $drug_generic_name,
            $drug_form,
            $drug_strength,
            $drug_manufacturer,
            $drug_category,
            $drug_description,
            $drug_is_active,
            $session_user_id
        );

        if (!$insert_stmt->execute()) {
            throw new Exception("Failed to add drug: " . $mysqli->error);
        }
        
        $new_drug_id = $insert_stmt->insert_id;
        
        // Create inventory item if enabled
        $inventory_item_id = null;
        if (isset($_POST['add_to_inventory']) && $_POST['add_to_inventory'] == '1') {
            $inventory_item_id = createInventoryItem($mysqli, $new_drug_id, $drug_name, $drug_generic_name, 
                $drug_form, $drug_strength, $drug_manufacturer, $item_code, $unit_price, $cost_price, 
                $initial_quantity, $item_unit_measure, $location_id, $session_user_id);
            
            // AUDIT LOG: Inventory item creation
            audit_log($mysqli, [
                'user_id'     => $_SESSION['user_id'] ?? null,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'INVENTORY_ITEM_CREATE',
                'module'      => 'Inventory',
                'table_name'  => 'inventory_items',
                'entity_type' => 'inventory_item',
                'record_id'   => $inventory_item_id,
                'patient_id'  => null,
                'visit_id'    => null,
                'description' => "Created inventory item from drug: " . $drug_name,
                'status'      => 'SUCCESS',
                'old_values'  => null,
                'new_values'  => [
                    'drug_id' => $new_drug_id,
                    'item_code' => $item_code,
                    'unit_price' => $unit_price,
                    'cost_price' => $cost_price,
                    'quantity' => $initial_quantity,
                    'item_unit_measure' => $item_unit_measure,
                    'location_id' => $location_id
                ]
            ]);
        }
        
        // Create billable item if enabled
        $billable_item_id = null;
        if (isset($_POST['add_to_billable']) && $_POST['add_to_billable'] == '1') {
            $billable_item_id = createBillableItem($mysqli, $new_drug_id, $drug_name, $drug_generic_name, 
                $drug_form, $drug_strength, $item_code, $unit_price, $cost_price, $tax_rate, 
                $is_taxable, $item_unit_measure, $session_user_id);
            
            // AUDIT LOG: Billable item creation
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
                'description' => "Created billable item from drug: " . $drug_name,
                'status'      => 'SUCCESS',
                'old_values'  => null,
                'new_values'  => [
                    'drug_id' => $new_drug_id,
                    'item_code' => $item_code,
                    'unit_price' => $unit_price,
                    'cost_price' => $cost_price,
                    'tax_rate' => $tax_rate,
                    'is_taxable' => $is_taxable,
                    'item_unit_measure' => $item_unit_measure
                ]
            ]);
        }
        
        // AUDIT LOG: Successful drug creation
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'DRUG_CREATE',
            'module'      => 'Drugs',
            'table_name'  => 'drugs',
            'entity_type' => 'drug',
            'record_id'   => $new_drug_id,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Drug added successfully: " . $drug_name . 
                ($inventory_item_id ? " (Added to inventory)" : "") . 
                ($billable_item_id ? " (Added to billable items)" : ""),
            'status'      => 'SUCCESS',
            'old_values'  => null,
            'new_values'  => array_merge($drug_data, [
                'drug_id' => $new_drug_id,
                'drug_created_by' => $session_user_id,
                'drug_created_at' => date('Y-m-d H:i:s'),
                'inventory_item_id' => $inventory_item_id,
                'billable_item_id' => $billable_item_id
            ])
        ]);
        
        // Commit transaction
        $mysqli->commit();
        
        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Drug added successfully!" . 
            ($inventory_item_id ? " Added to inventory." : "") . 
            ($billable_item_id ? " Added to billable items." : "");
        
        // Redirect to edit page or manage page based on user choice
        if (isset($_POST['add_another'])) {
            header("Location: drugs_add.php");
        } else {
            header("Location: drugs_manage.php");
        }
        exit;
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $mysqli->rollback();
        
        // AUDIT LOG: Drug creation failed
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'DRUG_CREATE',
            'module'      => 'Drugs',
            'table_name'  => 'drugs',
            'entity_type' => 'drug',
            'record_id'   => null,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Failed to add drug: " . $drug_name . ". Error: " . $e->getMessage(),
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => $drug_data
        ]);
        
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error: " . $e->getMessage();
        header("Location: drugs_add.php");
        exit;
    }
}

// Helper function to generate drug code
function generateDrugCode($name, $strength, $form) {
    $prefix = substr(strtoupper(preg_replace('/[^A-Z]/', '', $name)), 0, 3);
    $strength_code = preg_replace('/[^0-9]/', '', $strength);
    $form_code = substr(strtoupper($form), 0, 2);
    $random = rand(100, 999);
    return $prefix . $strength_code . $form_code . $random;
}

// Helper function to get default unit based on form
function getDefaultUnit($form) {
    $form = strtolower($form);
    if (strpos($form, 'tablet') !== false || strpos($form, 'capsule') !== false) {
        return 'pcs';
    } elseif (strpos($form, 'syrup') !== false || strpos($form, 'suspension') !== false) {
        return 'bottle';
    } elseif (strpos($form, 'injection') !== false) {
        return 'ampoule';
    } elseif (strpos($form, 'ointment') !== false || strpos($form, 'cream') !== false) {
        return 'tube';
    } else {
        return 'pcs';
    }
}

// Helper function to create inventory item
function createInventoryItem($mysqli, $drug_id, $drug_name, $generic_name, $form, $strength, 
    $manufacturer, $item_code, $unit_price, $cost_price, $quantity, $unit_measure, $location_id, $user_id) {
    
    // Check if inventory item already exists for this drug
    $check_sql = "SELECT item_id FROM inventory_items WHERE drug_id = ?";
    $check_stmt = $mysqli->prepare($check_sql);
    $check_stmt->bind_param("i", $drug_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        throw new Exception("Inventory item already exists for this drug.");
    }
    
    // Check if item code already exists
    $check_code_sql = "SELECT item_id FROM inventory_items WHERE item_code = ?";
    $check_code_stmt = $mysqli->prepare($check_code_sql);
    $check_code_stmt->bind_param("s", $item_code);
    $check_code_stmt->execute();
    $check_code_result = $check_code_stmt->get_result();
    
    if ($check_code_result->num_rows > 0) {
        // Generate new code if duplicate
        $item_code = generateDrugCode($drug_name, $strength, $form);
    }
    
    // Create item name
    $item_name = $drug_name;
    if ($generic_name) {
        $item_name .= " ($generic_name)";
    }
    if ($strength) {
        $item_name .= " $strength";
    }
    if ($form) {
        $item_name .= " $form";
    }
    
    // Determine status based on quantity
    $item_status = 'In Stock';
    if ($quantity <= 0) {
        $item_status = 'Out of Stock';
    } elseif ($quantity < 10) {
        $item_status = 'Low Stock';
    }
    
    // Insert inventory item
    $insert_sql = "INSERT INTO inventory_items (
        item_name, item_brand, item_code, item_description, 
        item_quantity, item_unit_cost, item_unit_price, item_unit_measure,
        item_form, item_manufacturer, drug_id, item_status,
        item_added_by, item_added_date, item_updated_by, item_updated_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, NOW())";
    
    $insert_stmt = $mysqli->prepare($insert_sql);
    $item_description = "Drug: $drug_name" . ($generic_name ? " | Generic: $generic_name" : "") . 
                       " | Form: $form | Strength: $strength" . ($manufacturer ? " | Manufacturer: $manufacturer" : "");
    
    $insert_stmt->bind_param(
        "ssssiddsssissi",
        $item_name,
        $manufacturer,
        $item_code,
        $item_description,
        $quantity,
        $cost_price,
        $unit_price,
        $unit_measure,
        $form,
        $manufacturer,
        $drug_id,
        $item_status,
        $user_id,
        $user_id
    );
    
    if (!$insert_stmt->execute()) {
        throw new Exception("Failed to create inventory item: " . $mysqli->error);
    }
    
    $inventory_item_id = $insert_stmt->insert_id;
    
    // Add to inventory location if location specified
    if ($location_id && $quantity > 0) {
        $location_sql = "INSERT INTO inventory_location_items (
            item_id, location_id, quantity, unit_cost, selling_price,
            created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, NOW(), NOW())";
        
        $location_stmt = $mysqli->prepare($location_sql);
        $location_stmt->bind_param(
            "iiidd",
            $inventory_item_id,
            $location_id,
            $quantity,
            $cost_price,
            $unit_price
        );
        
        if (!$location_stmt->execute()) {
            throw new Exception("Failed to add item to location: " . $mysqli->error);
        }
    }
    
    return $inventory_item_id;
}

// Helper function to create billable item
function createBillableItem($mysqli, $drug_id, $drug_name, $generic_name, $form, $strength, 
    $item_code, $unit_price, $cost_price, $tax_rate, $is_taxable, $unit_measure, $user_id) {
    
    // Check if billable item already exists for this drug
    $check_sql = "SELECT billable_item_id FROM billable_items WHERE source_id = ? AND source_table = 'drugs'";
    $check_stmt = $mysqli->prepare($check_sql);
    $check_stmt->bind_param("i", $drug_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        throw new Exception("Billable item already exists for this drug.");
    }
    
    // Check if item code already exists in billable items
    $check_code_sql = "SELECT billable_item_id FROM billable_items WHERE item_code = ?";
    $check_code_stmt = $mysqli->prepare($check_code_sql);
    $check_code_stmt->bind_param("s", $item_code);
    $check_code_stmt->execute();
    $check_code_result = $check_code_stmt->get_result();
    
    if ($check_code_result->num_rows > 0) {
        // Generate new code if duplicate
        $item_code = generateDrugCode($drug_name, $strength, $form) . '-BILL';
    }
    
    // Create item name for billable items
    $item_name = $drug_name;
    if ($strength) {
        $item_name .= " $strength";
    }
    if ($form) {
        $item_name .= " $form";
    }
    
    $item_description = "Pharmaceutical Drug: $drug_name";
    if ($generic_name) {
        $item_description .= " ($generic_name)";
    }
    
    // Insert billable item
    $insert_sql = "INSERT INTO billable_items (
        item_type, source_table, source_id, item_code, item_name, item_description,
        unit_price, cost_price, tax_rate, is_taxable, item_unit_measure, item_status,
        created_by, updated_by
    ) VALUES ('inventory', 'drugs', ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?)";
    
    $insert_stmt = $mysqli->prepare($insert_sql);
    $insert_stmt->bind_param(
        "isssdddisii",
        $drug_id,
        $item_code,
        $item_name,
        $item_description,
        $unit_price,
        $cost_price,
        $tax_rate,
        $is_taxable,
        $unit_measure,
        $user_id,
        $user_id
    );

    if (!$insert_stmt->execute()) {
        throw new Exception("Failed to create billable item: " . $mysqli->error);
    }
    
    return $insert_stmt->insert_id;
}

// Get common values for dropdowns
$common_forms = ['Tablet', 'Capsule', 'Syrup', 'Injection', 'Ointment', 'Cream', 'Drops', 'Inhaler', 'Spray', 'Suppository', 'Gel', 'Lotion', 'Patch', 'Powder', 'Solution', 'Suspension'];
$common_categories = ['Analgesic', 'Antibiotic', 'Antiviral', 'Antifungal', 'Antihypertensive', 'Antidiabetic', 'Antidepressant', 'Anticoagulant', 'Bronchodilator', 'Diuretic', 'Statin', 'PPI', 'Steroid', 'Vaccine', 'Vitamin', 'Mineral'];
$common_manufacturers = ['Pfizer', 'GSK', 'Novartis', 'Roche', 'Merck', 'Johnson & Johnson', 'Sanofi', 'AstraZeneca', 'Gilead', 'AbbVie', 'Bayer', 'Eli Lilly', 'Amgen', 'Bristol-Myers Squibb', 'Teva'];
$common_units = ['pcs', 'tablet', 'capsule', 'bottle', 'ampoule', 'tube', 'pack', 'box', 'vial', 'syringe', 'ml', 'mg', 'g', 'kg', 'L'];

// Get locations for inventory
$locations = [];
$locations_sql = "SELECT location_id, location_name FROM inventory_locations";
$locations_result = $mysqli->query($locations_sql);
if ($locations_result) {
    $locations = $locations_result->fetch_all(MYSQLI_ASSOC);
}

// Get drug statistics for header
$total_drugs_sql = "SELECT COUNT(*) as count FROM drugs WHERE drug_is_active = 1";
$total_drugs_result = $mysqli->query($total_drugs_sql);
$total_drugs = $total_drugs_result->fetch_assoc()['count'];

$today_drugs_sql = "SELECT COUNT(*) as count FROM drugs WHERE DATE(drug_created_at) = CURDATE()";
$today_drugs_result = $mysqli->query($today_drugs_sql);
$today_drugs = $today_drugs_result->fetch_assoc()['count'];

$inactive_drugs_sql = "SELECT COUNT(*) as count FROM drugs WHERE drug_is_active = 0";
$inactive_drugs_result = $mysqli->query($inactive_drugs_sql);
$inactive_drugs = $inactive_drugs_result->fetch_assoc()['count'];
?>
<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-pills mr-2"></i>Add New Drug
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
                            <span class="badge badge-info ml-2">New Drug</span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Today:</strong> 
                            <span class="badge badge-success ml-2"><?php echo date('M j, Y'); ?></span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Total Active Drugs:</strong> 
                            <span class="badge badge-primary ml-2"><?php echo $total_drugs; ?></span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Drugs Today:</strong> 
                            <span class="badge badge-warning ml-2"><?php echo $today_drugs; ?></span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Inactive Drugs:</strong> 
                            <span class="badge badge-danger ml-2"><?php echo $inactive_drugs; ?></span>
                        </span>
                    </div>
                    <div class="btn-group">
                        <a href="drugs_manage.php" class="btn btn-secondary">
                            <i class="fas fa-times mr-2"></i>Cancel
                        </a>
                        <button type="submit" form="drugForm" class="btn btn-primary">
                            <i class="fas fa-save mr-2"></i>Add Drug
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
                                                   placeholder="Enter generic/international name">
                                        </div>
                                        <small class="form-text text-muted">International non-proprietary name (INN)</small>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Dosage Form</label>
                                        <select class="form-control select2" name="drug_form" data-placeholder="Select dosage form">
                                            <option value=""></option>
                                            <?php foreach ($common_forms as $form): ?>
                                                <option value="<?php echo $form; ?>"><?php echo $form; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="form-text text-muted">e.g., Tablet, Capsule, Injection</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Strength</label>
                                        <div class="input-group">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text"><i class="fas fa-weight-hanging"></i></span>
                                            </div>
                                            <input type="text" class="form-control" name="drug_strength" 
                                                   placeholder="e.g., 500mg, 250mg/5ml">
                                        </div>
                                        <small class="form-text text-muted">Drug concentration or strength</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Item Code</label>
                                        <div class="input-group">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text"><i class="fas fa-barcode"></i></span>
                                            </div>
                                            <input type="text" class="form-control" name="item_code" 
                                                   placeholder="Auto-generated">
                                        </div>
                                        <small class="form-text text-muted">Unique identifier for inventory</small>
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
                                                <option value="<?php echo $manufacturer; ?>"><?php echo $manufacturer; ?></option>
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
                                                <option value="<?php echo $category; ?>"><?php echo $category; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="form-text text-muted">Therapeutic category or class</small>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label>Description / Clinical Notes</label>
                                <textarea class="form-control" name="drug_description" rows="4" 
                                          placeholder="Enter any clinical notes, indications, or special instructions"></textarea>
                                <small class="form-text text-muted">Optional clinical information</small>
                            </div>
                        </div>
                    </div>

                    <!-- Inventory & Pricing Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-boxes mr-2"></i>Inventory & Pricing</h4>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Unit Price (Selling)</label>
                                        <div class="input-group">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text"><i class="fas fa-dollar-sign"></i></span>
                                            </div>
                                            <input type="number" class="form-control" name="unit_price" 
                                                   min="0" step="0.01" placeholder="0.00" value="0.00">
                                        </div>
                                        <small class="form-text text-muted">Selling price per unit</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Cost Price</label>
                                        <div class="input-group">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text"><i class="fas fa-money-bill-wave"></i></span>
                                            </div>
                                            <input type="number" class="form-control" name="cost_price" 
                                                   min="0" step="0.01" placeholder="0.00" value="0.00">
                                        </div>
                                        <small class="form-text text-muted">Purchase cost per unit</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Unit of Measure</label>
                                        <select class="form-control select2" name="item_unit_measure" data-placeholder="Select unit">
                                            <option value=""></option>
                                            <?php foreach ($common_units as $unit): ?>
                                                <option value="<?php echo $unit; ?>"><?php echo $unit; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="form-text text-muted">e.g., pcs, tablet, bottle</small>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Initial Quantity</label>
                                        <div class="input-group">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text"><i class="fas fa-cubes"></i></span>
                                            </div>
                                            <input type="number" class="form-control" name="initial_quantity" 
                                                   min="0" value="0" placeholder="0">
                                        </div>
                                        <small class="form-text text-muted">Initial stock quantity</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Tax Rate (%)</label>
                                        <div class="input-group">
                                            <div class="input-group-prepend">
                                                <span class="input-group-text"><i class="fas fa-percentage"></i></span>
                                            </div>
                                            <input type="number" class="form-control" name="tax_rate" 
                                                   min="0" max="100" step="0.01" value="0.00" placeholder="0.00">
                                        </div>
                                        <small class="form-text text-muted">Tax percentage for billing</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Location</label>
                                        <select class="form-control select2" name="location_id" data-placeholder="Select storage location">
                                            <option value=""></option>
                                            <?php foreach ($locations as $location): ?>
                                                <option value="<?php echo $location['location_id']; ?>">
                                                    <?php echo htmlspecialchars($location['location_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="form-text text-muted">Storage location for inventory</small>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-check">
                                        <input type="checkbox" class="form-check-input" id="is_taxable" name="is_taxable" value="1" checked>
                                        <label class="form-check-label" for="is_taxable">Taxable Item</label>
                                        <small class="form-text text-muted d-block">Check if this item is subject to tax</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-check">
                                        <input type="checkbox" class="form-check-input" id="add_to_inventory" name="add_to_inventory" value="1" checked>
                                        <label class="form-check-label" for="add_to_inventory">Add to Inventory</label>
                                        <small class="form-text text-muted d-block">Create inventory item for stock management</small>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row mt-2">
                                <div class="col-md-6">
                                    <div class="form-check">
                                        <input type="checkbox" class="form-check-input" id="add_to_billable" name="add_to_billable" value="1" checked>
                                        <label class="form-check-label" for="add_to_billable">Add to Billable Items</label>
                                        <small class="form-text text-muted d-block">Make available for billing and prescriptions</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Actions & Preview -->
                <div class="col-md-4">
                    <!-- Registration Actions Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-bolt mr-2"></i>Drug Actions</h4>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <?php if (SimplePermission::any("drug_create")) { ?>
                                <button type="submit" class="btn btn-primary btn-lg" id="submitBtn">
                                    <i class="fas fa-pills mr-2"></i>Add Drug
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
                                <h5 id="preview_drug_name">Drug Name</h5>
                                <div id="preview_generic_name" class="text-muted small">Generic Name</div>
                                <div id="preview_item_code" class="text-muted small">Code: -</div>
                            </div>
                            <hr>
                            <div class="small">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Form:</span>
                                    <span id="preview_form" class="font-weight-bold text-primary">-</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Strength:</span>
                                    <span id="preview_strength" class="font-weight-bold text-primary">-</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Manufacturer:</span>
                                    <span id="preview_manufacturer" class="font-weight-bold text-primary">-</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Category:</span>
                                    <span id="preview_category" class="font-weight-bold text-primary">-</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Price:</span>
                                    <span id="preview_price" class="font-weight-bold text-success">$0.00</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Qty:</span>
                                    <span id="preview_quantity" class="font-weight-bold text-warning">0</span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Status:</span>
                                    <span id="preview_status" class="font-weight-bold text-success">Active</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- System Integration Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-cogs mr-2"></i>System Integration</h4>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-info small mb-3">
                                <i class="fas fa-info-circle mr-2"></i>
                                When adding a drug, it can be automatically integrated into:
                            </div>
                            <div class="integration-status mb-3">
                                <div class="d-flex align-items-center mb-2">
                                    <span class="badge badge-success mr-2"><i class="fas fa-check"></i></span>
                                    <span class="flex-grow-1">Drugs Database</span>
                                    <span class="badge badge-light">Required</span>
                                </div>
                                <div class="d-flex align-items-center mb-2">
                                    <span id="inventory_status" class="badge badge-success mr-2"><i class="fas fa-check"></i></span>
                                    <span class="flex-grow-1">Inventory System</span>
                                    <span id="inventory_badge" class="badge badge-success">Enabled</span>
                                </div>
                                <div class="d-flex align-items-center">
                                    <span id="billing_status" class="badge badge-success mr-2"><i class="fas fa-check"></i></span>
                                    <span class="flex-grow-1">Billable Items</span>
                                    <span id="billing_badge" class="badge badge-success">Enabled</span>
                                </div>
                            </div>
                            <hr>
                            <div class="small">
                                <p class="mb-1"><strong>Integration Benefits:</strong></p>
                                <ul class="pl-3 mb-0">
                                    <li>Automatic stock tracking</li>
                                    <li>Seamless billing integration</li>
                                    <li>Prescription auto-linking</li>
                                    <li>Real-time inventory updates</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Add Options Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-bolt mr-2"></i>Quick Add Options</h4>
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
                                    <p class="small mb-2"><strong>Common Prices:</strong></p>
                                    <div class="btn-group btn-group-sm d-flex mb-3">
                                        <button type="button" class="btn btn-outline-success flex-fill price-quick-btn" data-price="5.00">
                                            $5.00
                                        </button>
                                        <button type="button" class="btn btn-outline-success flex-fill price-quick-btn" data-price="10.00">
                                            $10.00
                                        </button>
                                    </div>
                                    <div class="btn-group btn-group-sm d-flex mb-3">
                                        <button type="button" class="btn btn-outline-success flex-fill price-quick-btn" data-price="15.00">
                                            $15.00
                                        </button>
                                        <button type="button" class="btn btn-outline-success flex-fill price-quick-btn" data-price="25.00">
                                            $25.00
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-12">
                                    <p class="small mb-2"><strong>Common Categories:</strong></p>
                                    <div class="btn-group btn-group-sm d-flex mb-3">
                                        <button type="button" class="btn btn-outline-warning flex-fill category-quick-btn" data-category="Analgesic">
                                            Analgesic
                                        </button>
                                        <button type="button" class="btn btn-outline-warning flex-fill category-quick-btn" data-category="Antibiotic">
                                            Antibiotic
                                        </button>
                                    </div>
                                    <div class="btn-group btn-group-sm d-flex mb-3">
                                        <button type="button" class="btn btn-outline-warning flex-fill category-quick-btn" data-category="Antihypertensive">
                                            Antihypertensive
                                        </button>
                                        <button type="button" class="btn btn-outline-warning flex-fill category-quick-btn" data-category="Antidiabetic">
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
                                        <small class="text-muted">Drugs Today</small>
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
                                        <i class="fas fa-clock fa-lg text-warning mb-1"></i>
                                        <h5 class="mb-0"><?php echo date('H:i'); ?></h5>
                                        <small class="text-muted">Current Time</small>
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
                                    Item code auto-generates if left empty
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-success mr-2"></i>
                                    Check integration options for automatic setup
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-success mr-2"></i>
                                    Drug name must be unique per form
                                </li>
                                <li>
                                    <i class="fas fa-check-circle text-success mr-2"></i>
                                    Inventory integration enables stock tracking
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
                                <div class="col-md-4">
                                    <div class="form-group mb-0">
                                        <div class="custom-control custom-switch custom-switch-lg">
                                            <input type="checkbox" class="custom-control-input" id="drug_is_active" name="drug_is_active" value="1" checked>
                                            <label class="custom-control-label" for="drug_is_active">
                                                <span class="h5">Active Drug</span>
                                            </label>
                                        </div>
                                        <small class="form-text text-muted">Active drugs appear in prescription options.</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group mb-0">
                                        <div class="custom-control custom-switch custom-switch-lg">
                                            <input type="checkbox" class="custom-control-input" id="auto_integrate" checked disabled>
                                            <label class="custom-control-label" for="auto_integrate">
                                                <span class="h5">Auto Integrate</span>
                                            </label>
                                        </div>
                                        <small class="form-text text-muted">Automatic system integration.</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group mb-0">
                                        <div class="custom-control custom-switch custom-switch-lg">
                                            <input type="checkbox" class="custom-control-input" id="auto_code" checked disabled>
                                            <label class="custom-control-label" for="auto_code">
                                                <span class="h5">Auto Code</span>
                                            </label>
                                        </div>
                                        <small class="form-text text-muted">Auto-generate item codes.</small>
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
                                        Drug information is stored securely with automatic integration
                                    </small>
                                </div>
                                <div>
                                    <button type="submit" name="add_another" class="btn btn-outline-primary btn-sm">
                                        <i class="fas fa-plus mr-1"></i>Add & New
                                    </button>
                                    <a href="drugs_manage.php" class="btn btn-secondary btn-sm">
                                        <i class="fas fa-times mr-1"></i>Cancel
                                    </a>
                                    <button type="reset" class="btn btn-outline-secondary btn-sm">
                                        <i class="fas fa-redo mr-1"></i>Reset
                                    </button>
                                    <?php if (SimplePermission::any("drug_create")) { ?>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save mr-1"></i>Add Drug
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
        updateItemCode();
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
        updateItemCode();
    });

    // Quick price buttons
    $('.price-quick-btn').click(function() {
        var price = $(this).data('price');
        $('input[name="unit_price"]').val(price);
        
        // Auto-set cost price as 70% of selling price
        var costPrice = (parseFloat(price) * 0.7).toFixed(2);
        $('input[name="cost_price"]').val(costPrice);
        
        $('#preview_price').text('$' + price);
    });

    // Update preview when drug name changes
    $('input[name="drug_name"]').on('input', function() {
        var drugName = $(this).val();
        $('#preview_drug_name').text(drugName || 'Drug Name');
        updateItemCode();
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
        
        // Auto-set unit of measure based on form
        var formLower = form.toLowerCase();
        var unitMeasure = 'pcs';
        if (formLower.includes('tablet') || formLower.includes('capsule')) {
            unitMeasure = 'tablet';
        } else if (formLower.includes('syrup') || formLower.includes('suspension')) {
            unitMeasure = 'bottle';
        } else if (formLower.includes('injection')) {
            unitMeasure = 'ampoule';
        } else if (formLower.includes('ointment') || formLower.includes('cream')) {
            unitMeasure = 'tube';
        }
        $('select[name="item_unit_measure"]').val(unitMeasure).trigger('change');
        
        updateItemCode();
    });

    // Update preview when strength changes
    $('input[name="drug_strength"]').on('input', function() {
        var strength = $(this).val();
        $('#preview_strength').text(strength || '-');
        updateItemCode();
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

    // Update preview when price changes
    $('input[name="unit_price"]').on('input', function() {
        var price = parseFloat($(this).val()) || 0;
        $('#preview_price').text('$' + price.toFixed(2));
    });

    // Update preview when quantity changes
    $('input[name="initial_quantity"]').on('input', function() {
        var quantity = $(this).val();
        $('#preview_quantity').text(quantity || '0');
    });

    // Update preview when status changes
    $('#drug_is_active').change(function() {
        var isActive = $(this).is(':checked');
        var statusText = isActive ? 'Active' : 'Inactive';
        var statusClass = isActive ? 'text-success' : 'text-danger';
        $('#preview_status').text(statusText).removeClass().addClass('font-weight-bold ' + statusClass);
    });

    // Update integration status based on checkboxes
    $('#add_to_inventory, #add_to_billable').change(function() {
        updateIntegrationStatus();
    });

    // Auto-generate item code
    function updateItemCode() {
        var drugName = $('input[name="drug_name"]').val();
        var strength = $('input[name="drug_strength"]').val();
        var form = $('select[name="drug_form"]').val();
        
        if (drugName && strength && form) {
            // Generate a simple code
            var prefix = drugName.substring(0, 3).toUpperCase().replace(/[^A-Z]/g, '');
            var strengthNum = strength.replace(/[^0-9]/g, '');
            var formCode = form.substring(0, 2).toUpperCase();
            var random = Math.floor(Math.random() * 900) + 100;
            
            var generatedCode = prefix + strengthNum + formCode + random;
            $('input[name="item_code"]').val(generatedCode);
            $('#preview_item_code').text('Code: ' + generatedCode);
        }
    }

    // Update integration status display
    function updateIntegrationStatus() {
        var inventoryEnabled = $('#add_to_inventory').is(':checked');
        var billingEnabled = $('#add_to_billable').is(':checked');
        
        // Update inventory status
        if (inventoryEnabled) {
            $('#inventory_status').removeClass().addClass('badge badge-success mr-2').html('<i class="fas fa-check"></i>');
            $('#inventory_badge').removeClass().addClass('badge badge-success').text('Enabled');
        } else {
            $('#inventory_status').removeClass().addClass('badge badge-secondary mr-2').html('<i class="fas fa-times"></i>');
            $('#inventory_badge').removeClass().addClass('badge badge-secondary').text('Disabled');
        }
        
        // Update billing status
        if (billingEnabled) {
            $('#billing_status').removeClass().addClass('badge badge-success mr-2').html('<i class="fas fa-check"></i>');
            $('#billing_badge').removeClass().addClass('badge badge-success').text('Enabled');
        } else {
            $('#billing_status').removeClass().addClass('badge badge-secondary mr-2').html('<i class="fas fa-times"></i>');
            $('#billing_badge').removeClass().addClass('badge badge-secondary').text('Disabled');
        }
    }

    // Auto-suggest generic name based on brand name
    $('input[name="drug_name"]').on('blur', function() {
        var brandName = $(this).val().trim().toLowerCase();
        var genericField = $('input[name="drug_generic_name"]');
        
        if (brandName && !genericField.val()) {
            // Common brand-to-generic mappings
            var mappings = {
                'panadol': 'Paracetamol',
                'ventolin': 'Salbutamol',
                'augmentin': 'Amoxicillin + Clavulanic Acid',
                'lipitor': 'Atorvastatin',
                'plavix': 'Clopidogrel',
                'amoxil': 'Amoxicillin',
                'zithromax': 'Azithromycin',
                'cipro': 'Ciprofloxacin',
                'lasix': 'Furosemide',
                'xanax': 'Alprazolam'
            };
            
            for (var brand in mappings) {
                if (brandName.includes(brand)) {
                    genericField.val(mappings[brand]);
                    $('#preview_generic_name').text(mappings[brand]);
                    break;
                }
            }
        }
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
        $('#submitBtn').html('<i class="fas fa-spinner fa-spin mr-2"></i>Adding...').prop('disabled', true);
        $('#resetBtn').prop('disabled', true);
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
            $('#drugForm')[0].reset();
            // Reset Select2
            $('.select2').val('').trigger('change');
            // Reset checkboxes
            $('#add_to_inventory').prop('checked', true);
            $('#add_to_billable').prop('checked', true);
            $('#is_taxable').prop('checked', true);
            // Reset preview
            $('#preview_drug_name').text('Drug Name');
            $('#preview_generic_name').text('Generic Name');
            $('#preview_item_code').text('Code: -');
            $('#preview_form').text('-');
            $('#preview_strength').text('-');
            $('#preview_manufacturer').text('-');
            $('#preview_category').text('-');
            $('#preview_price').text('$0.00');
            $('#preview_quantity').text('0');
            $('#preview_status').text('Active').removeClass().addClass('font-weight-bold text-success');
            // Clear validation errors
            $('.is-invalid').removeClass('is-invalid');
            $('.select2-selection').removeClass('is-invalid');
            // Update integration status
            updateIntegrationStatus();
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

    // Auto-focus on drug name field
    $('input[name="drug_name"]').focus();

    // Initialize integration status
    updateIntegrationStatus();
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
.integration-status {
    background-color: #f8f9fa;
    border-radius: 5px;
    padding: 15px;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
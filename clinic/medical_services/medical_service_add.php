<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Get available services for linking
$lab_tests = $mysqli->query("SELECT test_id, test_code, test_name, price FROM lab_tests WHERE is_active = 1 ORDER BY test_name");
$radiology_imaging = $mysqli->query("SELECT imaging_id, imaging_code, imaging_name, fee_amount FROM radiology_imagings WHERE is_active = 1 ORDER BY imaging_name");
$service_categories = $mysqli->query("SELECT category_id, category_name FROM medical_service_categories");

// Get beds for linking
$beds = $mysqli->query("SELECT b.bed_id, b.bed_number, b.bed_type, b.bed_rate, 
                                w.ward_name, w.ward_type
                         FROM beds b
                         LEFT JOIN wards w ON b.bed_ward_id = w.ward_id
                         WHERE b.bed_status = 'available' 
                         AND b.bed_archived_at IS NULL
                         ORDER BY w.ward_name, b.bed_number");

// Get accounts for bookkeeping linking - optimize memory by storing in array
$accounts_result = $mysqli->query("SELECT account_id, account_number, account_name, account_type 
                                   FROM accounts 
                                   WHERE is_active = 1
                                   ORDER BY account_number");
$accounts = [];
while ($account = $accounts_result->fetch_assoc()) {
    $accounts[] = $account;
}
$accounts_result->free();

// Get inventory items for linking (if needed for medical supplies/consumables)
$inventory_items_result = $mysqli->query("SELECT item_id, item_code, item_name, item_unit_price 
                                  FROM inventory_items 
                                  WHERE item_status IN ('In Stock', 'Low Stock') 
                                  ORDER BY item_name");
$inventory_items = [];
while ($item = $inventory_items_result->fetch_assoc()) {
    $inventory_items[] = $item;
}
$inventory_items_result->free();

// Get billable categories for reference
$billable_categories_sql = "SELECT category_id, category_name FROM billable_categories WHERE is_active = 1 ORDER BY category_name";
$billable_categories_result = $mysqli->query($billable_categories_sql);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    $service_code = sanitizeInput($_POST['service_code']);
    $service_name = sanitizeInput($_POST['service_name']);
    $service_description = sanitizeInput($_POST['service_description']);
    $service_category_id = intval($_POST['service_category_id']);
    $service_type = sanitizeInput($_POST['service_type']);
    $fee_amount = floatval($_POST['fee_amount']);
    $duration_minutes = intval($_POST['duration_minutes']);
    $tax_rate = floatval($_POST['tax_rate']);
    $requires_doctor = isset($_POST['requires_doctor']) ? 1 : 0;
    $insurance_billable = isset($_POST['insurance_billable']) ? 1 : 0;
    $medical_code = sanitizeInput($_POST['medical_code']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    // Account linking fields - ensure they're integers
    $revenue_account_id = intval($_POST['revenue_account_id'] ?? 0);
    $cogs_account_id = intval($_POST['cogs_account_id'] ?? 0);
    $inventory_account_id = intval($_POST['inventory_account_id'] ?? 0);
    $tax_account_id = intval($_POST['tax_account_id'] ?? 0);

    // Get linked components
    $linked_lab_tests = isset($_POST['linked_lab_tests']) ? array_map('intval', $_POST['linked_lab_tests']) : [];
    $linked_radiology = isset($_POST['linked_radiology']) ? array_map('intval', $_POST['linked_radiology']) : [];
    $linked_beds = isset($_POST['linked_beds']) ? array_map('intval', $_POST['linked_beds']) : [];
    $linked_inventory_items = isset($_POST['linked_inventory_items']) ? array_map('intval', $_POST['linked_inventory_items']) : [];
    $linked_inventory_quantities = isset($_POST['linked_inventory_quantities']) ? $_POST['linked_inventory_quantities'] : [];

    // Validate CSRF token
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        header("Location: medical_service_add.php");
        exit;
    }

    // Validate required fields
    if (empty($service_code) || empty($service_name) || empty($service_type) || $fee_amount < 0 || $duration_minutes <= 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please fill in all required fields with valid values.";
        header("Location: medical_service_add.php");
        exit;
    }

    // Validate account linking for bookkeeping
    if ($revenue_account_id <= 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please select a revenue account for bookkeeping.";
        header("Location: medical_service_add.php");
        exit;
    }

    // Validate that selected accounts exist in the database
    if ($revenue_account_id > 0) {
        $account_check = $mysqli->query("SELECT account_id FROM accounts WHERE account_id = $revenue_account_id AND is_active = 1");
        if ($account_check->num_rows == 0) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Selected revenue account does not exist or is not active.";
            header("Location: medical_service_add.php");
            exit;
        }
    }

    // Check if service code already exists
    $check_sql = "SELECT medical_service_id FROM medical_services WHERE service_code = ? AND is_active = 1";
    $check_stmt = $mysqli->prepare($check_sql);
    $check_stmt->bind_param("s", $service_code);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($check_result->num_rows > 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Service code already exists. Please use a unique service code.";
        header("Location: medical_service_add.php");
        exit;
    }
    $check_stmt->close();

    // Start transaction for service creation and account linking
    $mysqli->begin_transaction();

    try {
        // Insert new service
        $insert_sql = "INSERT INTO medical_services SET 
                      service_code = ?, 
                      service_name = ?, 
                      service_description = ?, 
                      service_category_id = ?, 
                      service_type = ?, 
                      fee_amount = ?, 
                      duration_minutes = ?, 
                      tax_rate = ?, 
                      requires_doctor = ?, 
                      insurance_billable = ?, 
                      medical_code = ?, 
                      is_active = ?,
                      created_by = ?,
                      created_date = NOW()";
        
        $insert_stmt = $mysqli->prepare($insert_sql);
        
        // Check if service_category_id is 0, set to NULL
        $service_category_id = $service_category_id > 0 ? $service_category_id : NULL;
        
        $insert_stmt->bind_param(
            "sssisdiisiisi",
            $service_code, 
            $service_name, 
            $service_description, 
            $service_category_id, 
            $service_type, 
            $fee_amount, 
            $duration_minutes, 
            $tax_rate, 
            $requires_doctor, 
            $insurance_billable, 
            $medical_code, 
            $is_active, 
            $session_user_id
        );

        if (!$insert_stmt->execute()) {
            throw new Exception("Failed to create service: " . $insert_stmt->error);
        }
        
        $new_service_id = $insert_stmt->insert_id;
        $insert_stmt->close();

        // Get or create billable category for medical service
        $billable_category_name = "Medical Services - " . $service_type;
        $billable_category_sql = "SELECT category_id FROM billable_categories WHERE category_name = ?";
        $billable_category_stmt = $mysqli->prepare($billable_category_sql);
        $billable_category_stmt->bind_param("s", $billable_category_name);
        $billable_category_stmt->execute();
        $billable_category_result = $billable_category_stmt->get_result();
        
        $billable_category_id = null;
        if ($billable_category_result->num_rows > 0) {
            $billable_category_row = $billable_category_result->fetch_assoc();
            $billable_category_id = $billable_category_row['category_id'];
        } else {
            // Create new billable category for this service type
            $create_category_sql = "INSERT INTO billable_categories SET 
                                   category_name = ?, 
                                   category_description = ?,
                                   is_active = 1,
                                   created_at = NOW()";
            $create_category_stmt = $mysqli->prepare($create_category_sql);
            $category_description = "Medical services of type: " . $service_type . " - " . $service_description;
            $create_category_stmt->bind_param("ss", $billable_category_name, $category_description);
            if (!$create_category_stmt->execute()) {
                throw new Exception("Error creating billable category: " . $create_category_stmt->error);
            }
            $billable_category_id = $create_category_stmt->insert_id;
            $create_category_stmt->close();
        }
        $billable_category_stmt->close();

        // Create billable item for the medical service
        $billable_item_sql = "INSERT INTO billable_items SET 
                             item_type = 'service',
                             source_table = 'medical_services',
                             source_id = ?,
                             item_code = ?,
                             item_name = ?,
                             item_description = ?,
                             unit_price = ?,
                             cost_price = ?,
                             tax_rate = ?,
                             is_taxable = ?,
                             category_id = ?,
                             revenue_account_id = ?,
                             cogs_account_id = ?,
                             inventory_account_id = ?,
                             is_active = ?,
                             created_by = ?,
                             created_at = NOW()";
        
        // Set cost price (60% of selling price as default for medical services)
        $cost_price = $fee_amount * 0.6;
        $is_taxable = ($tax_rate > 0) ? 1 : 0;
        
        // Set account IDs to NULL if 0
        $revenue_account_id = $revenue_account_id > 0 ? $revenue_account_id : NULL;
        $cogs_account_id = $cogs_account_id > 0 ? $cogs_account_id : NULL;
        $inventory_account_id = $inventory_account_id > 0 ? $inventory_account_id : NULL;
        
        $billable_stmt = $mysqli->prepare($billable_item_sql);
        $billable_stmt->bind_param(
            "isssddiiiiiiii",
            $new_service_id,
            $service_code,
            $service_name,
            $service_description,
            $fee_amount,
            $cost_price,
            $tax_rate,
            $is_taxable,
            $billable_category_id,
            $revenue_account_id,
            $cogs_account_id,
            $inventory_account_id,
            $is_active,
            $session_user_id
        );

        if (!$billable_stmt->execute()) {
            throw new Exception("Error creating billable item: " . $billable_stmt->error);
        }
        $billable_item_id = $billable_stmt->insert_id;
        $billable_stmt->close();

        // Link accounts for bookkeeping - FIXED: Check if service_accounts table exists
        // First, let's check if the table exists
        $table_check = $mysqli->query("SHOW TABLES LIKE 'service_accounts'");
        if ($table_check->num_rows > 0) {
            $account_link_sql = "INSERT INTO service_accounts (medical_service_id, account_type, account_id, created_at, created_by) 
                                VALUES (?, ?, ?, NOW(), ?)";
            $account_link_stmt = $mysqli->prepare($account_link_sql);
            
            // Link revenue account
            if ($revenue_account_id > 0) {
                $account_type = 'revenue';
                $account_link_stmt->bind_param("isii", $new_service_id, $account_type, $revenue_account_id, $session_user_id);
                if (!$account_link_stmt->execute()) {
                    throw new Exception("Failed to link revenue account: " . $account_link_stmt->error);
                }
            }
            
            // Link COGS account
            if ($cogs_account_id > 0) {
                $account_type = 'cogs';
                $account_link_stmt->bind_param("isii", $new_service_id, $account_type, $cogs_account_id, $session_user_id);
                if (!$account_link_stmt->execute()) {
                    throw new Exception("Failed to link COGS account: " . $account_link_stmt->error);
                }
            }
            
            // Link inventory account
            if ($inventory_account_id > 0) {
                $account_type = 'inventory';
                $account_link_stmt->bind_param("isii", $new_service_id, $account_type, $inventory_account_id, $session_user_id);
                if (!$account_link_stmt->execute()) {
                    throw new Exception("Failed to link inventory account: " . $account_link_stmt->error);
                }
            }
            
            // Link tax account
            if ($tax_account_id > 0) {
                $account_type = 'tax';
                $account_link_stmt->bind_param("isii", $new_service_id, $account_type, $tax_account_id, $session_user_id);
                if (!$account_link_stmt->execute()) {
                    throw new Exception("Failed to link tax account: " . $account_link_stmt->error);
                }
            }
            
            $account_link_stmt->close();
        }
        
        // Link lab tests if any selected
        if (!empty($linked_lab_tests)) {
            // First check if service_components table exists
            $table_check = $mysqli->query("SHOW TABLES LIKE 'service_components'");
            if ($table_check->num_rows > 0) {
                $link_lab_sql = "INSERT INTO service_components (medical_service_id, component_type, component_reference_id, created_at) VALUES (?, 'LabTest', ?, NOW())";
                $link_lab_stmt = $mysqli->prepare($link_lab_sql);
                
                foreach ($linked_lab_tests as $test_id) {
                    // Validate lab test exists
                    $test_check = $mysqli->query("SELECT test_id FROM lab_tests WHERE test_id = $test_id");
                    if ($test_check->num_rows > 0) {
                        $link_lab_stmt->bind_param("ii", $new_service_id, $test_id);
                        if (!$link_lab_stmt->execute()) {
                            throw new Exception("Failed to link lab test: " . $link_lab_stmt->error);
                        }
                    }
                }
                $link_lab_stmt->close();
            }
        }
        
        // Link radiology imaging if any selected
        if (!empty($linked_radiology)) {
            $table_check = $mysqli->query("SHOW TABLES LIKE 'service_components'");
            if ($table_check->num_rows > 0) {
                $link_rad_sql = "INSERT INTO service_components (medical_service_id, component_type, component_reference_id, created_at) VALUES (?, 'Radiology', ?, NOW())";
                $link_rad_stmt = $mysqli->prepare($link_rad_sql);
                
                foreach ($linked_radiology as $imaging_id) {
                    // Validate radiology exists
                    $img_check = $mysqli->query("SELECT imaging_id FROM radiology_imagings WHERE imaging_id = $imaging_id");
                    if ($img_check->num_rows > 0) {
                        $link_rad_stmt->bind_param("ii", $new_service_id, $imaging_id);
                        if (!$link_rad_stmt->execute()) {
                            throw new Exception("Failed to link radiology imaging: " . $link_rad_stmt->error);
                        }
                    }
                }
                $link_rad_stmt->close();
            }
        }
        
        // Link beds if any selected
        if (!empty($linked_beds)) {
            $table_check = $mysqli->query("SHOW TABLES LIKE 'service_components'");
            if ($table_check->num_rows > 0) {
                $link_bed_sql = "INSERT INTO service_components (medical_service_id, component_type, component_reference_id, created_at) VALUES (?, 'Bed', ?, NOW())";
                $link_bed_stmt = $mysqli->prepare($link_bed_sql);
                
                foreach ($linked_beds as $bed_id) {
                    // Validate bed exists
                    $bed_check = $mysqli->query("SELECT bed_id FROM beds WHERE bed_id = $bed_id");
                    if ($bed_check->num_rows > 0) {
                        $link_bed_stmt->bind_param("ii", $new_service_id, $bed_id);
                        if (!$link_bed_stmt->execute()) {
                            throw new Exception("Failed to link bed: " . $link_bed_stmt->error);
                        }
                    }
                }
                $link_bed_stmt->close();
            }
        }
        
        // Link inventory items if any selected
        if (!empty($linked_inventory_items)) {
            $table_check = $mysqli->query("SHOW TABLES LIKE 'service_inventory_items'");
            if ($table_check->num_rows > 0) {
                $link_inv_sql = "INSERT INTO service_inventory_items (medical_service_id, item_id, quantity_required, created_at) VALUES (?, ?, ?, NOW())";
                $link_inv_stmt = $mysqli->prepare($link_inv_sql);
                
                foreach ($linked_inventory_items as $index => $item_id) {
                    if ($item_id > 0) {
                        $quantity = isset($linked_inventory_quantities[$index]) ? intval($linked_inventory_quantities[$index]) : 1;
                        // Validate inventory item exists
                        $item_check = $mysqli->query("SELECT item_id FROM inventory_items WHERE item_id = $item_id");
                        if ($item_check->num_rows > 0) {
                            $link_inv_stmt->bind_param("iii", $new_service_id, $item_id, $quantity);
                            if (!$link_inv_stmt->execute()) {
                                throw new Exception("Failed to link inventory item: " . $link_inv_stmt->error);
                            }
                        }
                    }
                }
                $link_inv_stmt->close();
            }
        }
        
        // Log the activity
        $activity_sql = "INSERT INTO activities (activity_description, activity_user_id, activity_timestamp) VALUES (?, ?, NOW())";
        $linked_components = [];
        if (!empty($linked_lab_tests)) $linked_components[] = count($linked_lab_tests) . " lab tests";
        if (!empty($linked_radiology)) $linked_components[] = count($linked_radiology) . " radiology imaging";
        if (!empty($linked_beds)) $linked_components[] = count($linked_beds) . " beds";
        if (!empty($linked_inventory_items)) $linked_components[] = count($linked_inventory_items) . " inventory items";
        
        $activity_desc = "Created new medical service: " . $service_name . " (" . $service_code . ") - Type: " . $service_type . " and added to billable items (ID: $billable_item_id)";
        if (!empty($linked_components)) {
            $activity_desc .= " - Linked: " . implode(", ", $linked_components);
        }
        
        $activity_stmt = $mysqli->prepare($activity_sql);
        $activity_stmt->bind_param("si", $activity_desc, $session_user_id);
        $activity_stmt->execute();
        $activity_stmt->close();

        $mysqli->commit();

        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Medical service created successfully and added to billable items!" . (!empty($linked_components) ? " Linked components: " . implode(", ", $linked_components) : "");
        header("Location: medical_service_details.php?medical_service_id=" . $new_service_id);
        exit;

    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error creating medical service: " . $e->getMessage();
        header("Location: medical_service_add.php");
        exit;
    }
}

// Get recent services for reference - optimize with LIMIT
$recent_services_sql = "SELECT service_code, service_name, service_type, category_name, fee_amount, duration_minutes 
                       FROM medical_services ms
                       LEFT JOIN medical_service_categories msc ON ms.service_category_id = msc.category_id
                       WHERE ms.is_active = 1 
                       ORDER BY ms.created_date DESC 
                       LIMIT 5";
$recent_services_result = $mysqli->query($recent_services_sql);
?>

<div class="card">
    <div class="card-header bg-success py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-plus-circle mr-2"></i>Add New Medical Service
            </h3>
            <div class="card-tools">
                <a href="medical_services.php" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Services
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

        <form method="POST" id="serviceForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <div class="row">
                <div class="col-md-8">
                    <!-- Service Basic Information -->
                    <div class="card card-primary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-info-circle mr-2"></i>Service Information</h3>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="service_code">Service Code *</label>
                                        <input type="text" class="form-control" id="service_code" name="service_code" 
                                               placeholder="e.g., CONSULT_GEN, PROCED_MINOR, PACKAGE_BASIC" required maxlength="50">
                                        <small class="form-text text-muted">Unique identifier for the service (will also be used as billable item code)</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="service_type">Service Type *</label>
                                        <select class="form-control" id="service_type" name="service_type" required>
                                            <option value="">- Select Service Type -</option>
                                            <option value="Consultation">Consultation</option>
                                            <option value="Procedure">Procedure</option>
                                            <option value="LabTest">Lab Test</option>
                                            <option value="Imaging">Imaging</option>
                                            <option value="Vaccination">Vaccination</option>
                                            <option value="Package">Service Package</option>
                                            <option value="Bed">Bed/Hospitalization</option>
                                            <option value="Other">Other</option>
                                        </select>
                                        <small class="form-text text-muted">Service type will determine the billable category</small>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="service_name">Service Name *</label>
                                <input type="text" class="form-control" id="service_name" name="service_name" 
                                       placeholder="e.g., General Consultation, Minor Procedure, Basic Health Package" required>
                                <small class="form-text text-muted">This will be used as the billable item name</small>
                            </div>

                            <div class="form-group">
                                <label for="service_description">Description</label>
                                <textarea class="form-control" id="service_description" name="service_description" rows="3" 
                                          placeholder="Brief description of what this service includes and its purpose..."
                                          maxlength="500"></textarea>
                                <small class="form-text text-muted">This description will also be used for the billable item</small>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="service_category_id">Category</label>
                                        <select class="form-control" id="service_category_id" name="service_category_id">
                                            <option value="">- Select Category -</option>
                                            <?php 
                                            // Reset pointer and fetch
                                            $service_categories->data_seek(0);
                                            while ($category = $service_categories->fetch_assoc()): ?>
                                                <option value="<?php echo $category['category_id']; ?>">
                                                    <?php echo htmlspecialchars($category['category_name']); ?>
                                                </option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="fee_amount">Fee ($) *</label>
                                        <input type="number" class="form-control" id="fee_amount" name="fee_amount" 
                                               step="0.01" min="0" value="0.00" required>
                                        <small class="form-text text-muted">This will be the unit price in billable items</small>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="duration_minutes">Duration (minutes) *</label>
                                        <input type="number" class="form-control" id="duration_minutes" name="duration_minutes" 
                                               min="1" value="30" required>
                                    </div>
                                </div>
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
                                This medical service will automatically be added to the billable items catalog for billing purposes.
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Billable Item Type</label>
                                        <input type="text" class="form-control bg-light" value="Medical Service" readonly>
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
                                        <small class="form-text text-muted">Auto-calculated as 60% of selling price</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Tax Settings</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control bg-light" id="tax_settings_preview" value="Non-taxable (0%)" readonly>
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
                                        <strong>Source:</strong> medical_services table<br>
                                        <strong>Category:</strong> <span id="billable_category_preview">Medical Services - </span><br>
                                        <strong>Account Links:</strong> <span id="billable_accounts_preview">None</span><br>
                                        <strong>Status:</strong> <span id="billable_status_preview">Active</span>
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Bookkeeping Accounts -->
                    <div class="card card-secondary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-book mr-2"></i>Bookkeeping Accounts</h3>
                            <small class="text-muted">Link accounts for automated bookkeeping (will also be linked to billable item)</small>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="revenue_account_id">Revenue Account *</label>
                                        <select class="form-control select2" id="revenue_account_id" name="revenue_account_id" required>
                                            <option value="">- Select Revenue Account -</option>
                                            <?php foreach ($accounts as $account): ?>
                                                <option value="<?php echo $account['account_id']; ?>">
                                                    <?php echo htmlspecialchars($account['account_number'] . ' - ' . $account['account_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="form-text text-muted">Where service revenue will be recorded (required for billable item)</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="cogs_account_id">COGS Account</label>
                                        <select class="form-control select2" id="cogs_account_id" name="cogs_account_id">
                                            <option value="">- Select COGS Account -</option>
                                            <?php foreach ($accounts as $account): ?>
                                                <option value="<?php echo $account['account_id']; ?>">
                                                    <?php echo htmlspecialchars($account['account_number'] . ' - ' . $account['account_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="form-text text-muted">Cost of goods sold account (optional for billable item)</small>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="inventory_account_id">Inventory Account</label>
                                        <select class="form-control select2" id="inventory_account_id" name="inventory_account_id">
                                            <option value="">- Select Inventory Account -</option>
                                            <?php foreach ($accounts as $account): ?>
                                                <option value="<?php echo $account['account_id']; ?>">
                                                    <?php echo htmlspecialchars($account['account_number'] . ' - ' . $account['account_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="form-text text-muted">Inventory valuation account (optional for billable item)</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="tax_account_id">Tax Account</label>
                                        <select class="form-control select2" id="tax_account_id" name="tax_account_id">
                                            <option value="">- Select Tax Account -</option>
                                            <?php foreach ($accounts as $account): ?>
                                                <option value="<?php echo $account['account_id']; ?>">
                                                    <?php echo htmlspecialchars($account['account_number'] . ' - ' . $account['account_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="form-text text-muted">Tax liability account (optional)</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Service Components Linking -->
                    <div class="card card-info">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-link mr-2"></i>Link Service Components</h3>
                            <small class="text-muted">Link this service with existing lab tests, imaging, beds, or other services</small>
                        </div>
                        <div class="card-body">
                            <!-- Lab Tests Linking -->
                            <div class="form-group">
                                <label>Link Lab Tests</label>
                                <select class="form-control select2" id="linked_lab_tests" name="linked_lab_tests[]" multiple data-placeholder="Select lab tests to link...">
                                    <?php 
                                    // Reset pointer and fetch
                                    $lab_tests->data_seek(0);
                                    while ($test = $lab_tests->fetch_assoc()): ?>
                                        <option value="<?php echo $test['test_id']; ?>" data-price="<?php echo $test['price']; ?>">
                                            <?php echo htmlspecialchars($test['test_code'] . ' - ' . $test['test_name'] . ' ($' . number_format($test['price'], 2) . ')'); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                                <small class="form-text text-muted">Select multiple lab tests to include in this service package</small>
                            </div>

                            <!-- Radiology Imaging Linking -->
                            <div class="form-group">
                                <label>Link Radiology Imaging</label>
                                <select class="form-control select2" id="linked_radiology" name="linked_radiology[]" multiple data-placeholder="Select radiology imaging to link...">
                                    <?php 
                                    // Reset pointer and fetch
                                    $radiology_imaging->data_seek(0);
                                    while ($imaging = $radiology_imaging->fetch_assoc()): ?>
                                        <option value="<?php echo $imaging['imaging_id']; ?>" data-price="<?php echo $imaging['fee_amount']; ?>">
                                            <?php echo htmlspecialchars($imaging['imaging_code'] . ' - ' . $imaging['imaging_name'] . ' ($' . number_format($imaging['fee_amount'], 2) . ')'); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                                <small class="form-text text-muted">Select multiple radiology imaging studies to include</small>
                            </div>

                            <!-- Beds Linking -->
                            <div class="form-group">
                                <label>Link Hospital Beds</label>
                                <select class="form-control select2" id="linked_beds" name="linked_beds[]" multiple data-placeholder="Select beds to link...">
                                    <?php 
                                    // Reset pointer and fetch
                                    $beds->data_seek(0);
                                    while ($bed = $beds->fetch_assoc()): ?>
                                        <option value="<?php echo $bed['bed_id']; ?>" data-price="<?php echo $bed['bed_rate']; ?>">
                                            <?php echo htmlspecialchars($bed['ward_name'] . ' - Bed ' . $bed['bed_number'] . ' (' . $bed['bed_type'] . ') - $' . number_format($bed['bed_rate'], 2) . '/day'); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                                <small class="form-text text-muted">Select hospital beds to include for hospitalization services</small>
                            </div>

                            <!-- Inventory Items Linking -->
                            <div class="form-group">
                                <label>Link Inventory Items (Medical Supplies/Consumables)</label>
                                <div id="inventoryItemsContainer">
                                    <div class="inventory-item-row mb-2">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <select class="form-control select2 inventory-item" name="linked_inventory_items[]" data-placeholder="Select inventory item...">
                                                    <option value=""></option>
                                                    <?php foreach ($inventory_items as $item): ?>
                                                        <option value="<?php echo $item['item_id']; ?>" data-price="<?php echo $item['item_unit_price']; ?>">
                                                            <?php echo htmlspecialchars($item['item_code'] . ' - ' . $item['item_name'] . ' ($' . number_format($item['item_unit_price'], 2) . ')'); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <input type="number" class="form-control inventory-quantity" name="linked_inventory_quantities[]" placeholder="Qty" min="1" value="1">
                                            </div>
                                            <div class="col-md-2">
                                                <button type="button" class="btn btn-danger btn-sm remove-inventory-item" style="display: none;">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <button type="button" id="addInventoryItem" class="btn btn-sm btn-outline-primary mt-2">
                                    <i class="fas fa-plus mr-1"></i>Add Another Item
                                </button>
                                <small class="form-text text-muted">Add medical supplies or consumables required for this service</small>
                            </div>

                            <!-- Linked Components Summary -->
                            <div class="border rounded p-3 bg-light mt-3" id="componentsSummary" style="display: none;">
                                <h6 class="mb-3">Linked Components Summary</h6>
                                <div id="labTestsSummary" class="mb-2"></div>
                                <div id="radiologySummary" class="mb-2"></div>
                                <div id="bedsSummary" class="mb-2"></div>
                                <div id="inventorySummary" class="mb-2"></div>
                                <div class="font-weight-bold mt-2" id="componentsTotal">
                                    Total Components Cost: $<span id="totalCost">0.00</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Additional Information -->
                    <div class="card card-warning">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-cogs mr-2"></i>Additional Information</h3>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="medical_code">Medical Code</label>
                                        <input type="text" class="form-control" id="medical_code" name="medical_code" 
                                               placeholder="e.g., CPT, ICD-10 codes" maxlength="50">
                                        <small class="form-text text-muted">Standard medical billing codes</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="tax_rate">Tax Rate (%)</label>
                                        <input type="number" class="form-control" id="tax_rate" name="tax_rate" 
                                               step="0.01" min="0" max="100" value="0.00">
                                        <small class="form-text text-muted">Tax rate for billable item (0% = non-taxable)</small>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-check mt-2">
                                        <input type="checkbox" class="form-check-input" id="requires_doctor" name="requires_doctor" value="1" checked>
                                        <label class="form-check-label" for="requires_doctor">Requires Doctor</label>
                                        <small class="form-text text-muted">Service must be performed by a doctor</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-check mt-2">
                                        <input type="checkbox" class="form-check-input" id="insurance_billable" name="insurance_billable" value="1" checked>
                                        <label class="form-check-label" for="insurance_billable">Insurance Billable</label>
                                        <small class="form-text text-muted">Service can be billed to insurance</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Service Preview -->
                    <div class="card card-secondary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-eye mr-2"></i>Service Preview</h3>
                        </div>
                        <div class="card-body">
                            <div class="row text-center">
                                <div class="col-md-3">
                                    <div class="border rounded p-3 bg-light">
                                        <div class="h5 text-muted">Service Code</div>
                                        <div class="h3 font-weight-bold text-primary" id="preview_code">
                                            -
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="border rounded p-3 bg-light">
                                        <div class="h5 text-muted">Fee</div>
                                        <div class="h3 font-weight-bold text-success" id="preview_fee">
                                            $0.00
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="border rounded p-3 bg-light">
                                        <div class="h5 text-muted">Duration</div>
                                        <div class="h3 font-weight-bold text-info" id="preview_duration">
                                            30 min
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="border rounded p-3 bg-light">
                                        <div class="h5 text-muted">Tax Rate</div>
                                        <div class="h3 font-weight-bold text-warning" id="preview_tax">
                                            0%
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mt-3">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong>Service Name:</strong> 
                                        <span id="preview_name" class="ml-2">-</span>
                                    </div>
                                    <div>
                                        <strong>Type:</strong> 
                                        <span id="preview_type" class="ml-2 badge badge-secondary">-</span>
                                    </div>
                                </div>
                                <div class="mt-2">
                                    <strong>Description:</strong> 
                                    <span id="preview_description" class="ml-2 text-muted">-</span>
                                </div>
                                <div class="mt-2" id="preview_accounts" style="display: none;">
                                    <strong>Linked Accounts:</strong> 
                                    <span id="preview_accounts_list" class="ml-2 text-muted">-</span>
                                </div>
                                <div class="mt-2" id="preview_components" style="display: none;">
                                    <strong>Linked Components:</strong> 
                                    <span id="preview_components_list" class="ml-2 text-muted">-</span>
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
                                    <i class="fas fa-save mr-2"></i>Create Service
                                </button>
                                <button type="reset" class="btn btn-outline-secondary" onclick="resetForm()">
                                    <i class="fas fa-undo mr-2"></i>Reset Form
                                </button>
                                <a href="medical_services.php" class="btn btn-outline-danger">
                                    <i class="fas fa-times mr-2"></i>Cancel
                                </a>
                            </div>
                            
                            <div class="form-group mt-3">
                                <div class="custom-control custom-switch">
                                    <input type="checkbox" class="custom-control-input" id="is_active" name="is_active" value="1" checked>
                                    <label class="custom-control-label" for="is_active">Active Service</label>
                                </div>
                                <small class="form-text text-muted">Inactive services won't be available for booking or billing</small>
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
                                            <small class="text-muted">New categories will be created based on service type</small>
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

                    <!-- Service Templates -->
                    <div class="card card-info">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-magic mr-2"></i>Quick Templates</h3>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="loadTemplate('consultation')">
                                    <i class="fas fa-user-md mr-2"></i>General Consultation
                                </button>
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="loadTemplate('health_package')">
                                    <i class="fas fa-heartbeat mr-2"></i>Basic Health Package
                                </button>
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="loadTemplate('pre_employment')">
                                    <i class="fas fa-briefcase mr-2"></i>Pre-Employment Check
                                </button>
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="loadTemplate('executive')">
                                    <i class="fas fa-user-tie mr-2"></i>Executive Health Screen
                                </button>
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="loadTemplate('hospitalization')">
                                    <i class="fas fa-procedures mr-2"></i>Hospitalization Package
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Services -->
                    <div class="card card-secondary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-history mr-2"></i>Recently Added Services</h3>
                        </div>
                        <div class="card-body">
                            <?php if ($recent_services_result->num_rows > 0): ?>
                                <div class="list-group list-group-flush">
                                    <?php while ($service = $recent_services_result->fetch_assoc()): ?>
                                        <div class="list-group-item px-0 py-2">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1 text-primary"><?php echo htmlspecialchars($service['service_code']); ?></h6>
                                                <small class="text-success">$<?php echo number_format($service['fee_amount'], 2); ?></small>
                                            </div>
                                            <p class="mb-1 small"><?php echo htmlspecialchars($service['service_name']); ?></p>
                                            <div class="d-flex justify-content-between">
                                                <small class="text-muted"><?php echo htmlspecialchars($service['category_name'] ?: 'Uncategorized'); ?></small>
                                                <small class="text-muted"><?php echo $service['duration_minutes']; ?>m</small>
                                            </div>
                                        </div>
                                    <?php endwhile; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted mb-0 text-center">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    No recent services
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
                                    Service code must be unique
                                </li>
                                <li class="mb-1">
                                    <i class="fas fa-check text-success mr-1"></i>
                                    Revenue account is required
                                </li>
                                <li class="mb-1">
                                    <i class="fas fa-check text-success mr-1"></i>
                                    All required fields must be filled
                                </li>
                                <li class="mb-1">
                                    <i class="fas fa-check text-success mr-1"></i>
                                    Fee cannot be negative
                                </li>
                                <li class="mb-1">
                                    <i class="fas fa-check text-success mr-1"></i>
                                    Duration must be positive
                                </li>
                                <li class="mt-2">
                                    <i class="fas fa-dollar-sign text-info mr-1"></i>
                                    Service will be added to billable items
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
    // Initialize Select2
    $('.select2').select2({
        theme: 'bootstrap4'
    });
    
    // Inventory items dynamic addition
    $('#addInventoryItem').click(function() {
        var newRow = $('.inventory-item-row:first').clone();
        newRow.find('select').val('').trigger('change');
        newRow.find('input').val('1');
        newRow.find('.remove-inventory-item').show();
        newRow.appendTo('#inventoryItemsContainer');
        
        // Reinitialize Select2 on new row
        newRow.find('.select2').select2({
            theme: 'bootstrap4'
        });
        
        // Bind change event to new item
        newRow.find('.inventory-item').on('change', updateComponentsPreview);
        newRow.find('.inventory-quantity').on('input', updateComponentsPreview);
    });
    
    // Remove inventory item row
    $(document).on('click', '.remove-inventory-item', function() {
        if ($('.inventory-item-row').length > 1) {
            $(this).closest('.inventory-item-row').remove();
            updateComponentsPreview();
            updateBillablePreview();
        }
    });
    
    // Update preview based on form changes
    function updatePreview() {
        const serviceCode = $('#service_code').val() || '-';
        const serviceName = $('#service_name').val() || '-';
        const serviceDescription = $('#service_description').val() || '-';
        const serviceType = $('#service_type').val() || '-';
        const fee = $('#fee_amount').val() || '0.00';
        const duration = $('#duration_minutes').val() || '30';
        const taxRate = $('#tax_rate').val() || '0.00';
        const isActive = $('#is_active').is(':checked');
        
        // Calculate cost price (60% of selling price for medical services)
        const costPrice = parseFloat(fee) * 0.6;
        
        // Update preview elements
        $('#preview_code').text(serviceCode);
        $('#preview_name').text(serviceName);
        $('#preview_description').text(serviceDescription);
        $('#preview_fee').text('$' + parseFloat(fee).toFixed(2));
        $('#preview_duration').text(duration + ' min');
        $('#preview_tax').text(taxRate + '%');
        
        // Update billable item preview
        $('#billable_item_code').val(serviceCode);
        $('#cost_price_preview').val(costPrice.toFixed(2));
        
        // Update tax settings preview
        const taxSettings = taxRate > 0 ? 'Taxable (' + taxRate + '%)' : 'Non-taxable (0%)';
        $('#tax_settings_preview').val(taxSettings);
        
        $('#billable_category_preview').text('Medical Services - ' + (serviceType || '-'));
        $('#billable_status_preview').text(isActive ? 'Active' : 'Inactive');
        $('#billable_status_preview').removeClass('text-success text-danger').addClass(isActive ? 'text-success' : 'text-danger');
        
        // Update service type badge
        const typeBadge = $('#preview_type');
        typeBadge.text(serviceType);
        typeBadge.removeClass('badge-primary badge-warning badge-info badge-success badge-secondary badge-danger');
        
        switch(serviceType) {
            case 'Consultation': typeBadge.addClass('badge-primary'); break;
            case 'Procedure': typeBadge.addClass('badge-warning'); break;
            case 'LabTest': typeBadge.addClass('badge-info'); break;
            case 'Imaging': typeBadge.addClass('badge-success'); break;
            case 'Vaccination': typeBadge.addClass('badge-success'); break;
            case 'Package': typeBadge.addClass('badge-info'); break;
            case 'Bed': typeBadge.addClass('badge-danger'); break;
            default: typeBadge.addClass('badge-secondary'); break;
        }
        
        // Update account preview
        updateAccountsPreview();
        
        // Update linked components preview
        updateComponentsPreview();
        
        // Update billable accounts preview
        updateBillableAccountsPreview();
    }
    
    // Update account preview
    function updateAccountsPreview() {
        const revenueAccount = $('#revenue_account_id option:selected').text();
        const cogsAccount = $('#cogs_account_id option:selected').text();
        const inventoryAccount = $('#inventory_account_id option:selected').text();
        const taxAccount = $('#tax_account_id option:selected').text();
        
        let accountsList = [];
        
        if (revenueAccount && revenueAccount !== '- Select Revenue Account -') {
            accountsList.push('Revenue: ' + revenueAccount.split(' - ')[1] || revenueAccount);
        }
        if (cogsAccount && cogsAccount !== '- Select COGS Account -') {
            accountsList.push('COGS: ' + cogsAccount.split(' - ')[1] || cogsAccount);
        }
        if (inventoryAccount && inventoryAccount !== '- Select Inventory Account -') {
            accountsList.push('Inventory: ' + inventoryAccount.split(' - ')[1] || inventoryAccount);
        }
        if (taxAccount && taxAccount !== '- Select Tax Account -') {
            accountsList.push('Tax: ' + taxAccount.split(' - ')[1] || taxAccount);
        }
        
        if (accountsList.length > 0) {
            $('#preview_accounts').show();
            $('#preview_accounts_list').text(accountsList.slice(0, 2).join(', ') + (accountsList.length > 2 ? '...' : ''));
        } else {
            $('#preview_accounts').hide();
        }
    }
    
    // Update billable accounts preview
    function updateBillableAccountsPreview() {
        const revenueAccount = $('#revenue_account_id option:selected').text();
        const cogsAccount = $('#cogs_account_id option:selected').text();
        const inventoryAccount = $('#inventory_account_id option:selected').text();
        
        let accountsPreview = [];
        
        if (revenueAccount && revenueAccount !== '- Select Revenue Account -') {
            accountsPreview.push('Rev: ' + revenueAccount.split(' - ')[0] || '');
        }
        if (cogsAccount && cogsAccount !== '- Select COGS Account -') {
            accountsPreview.push('COGS: ' + cogsAccount.split(' - ')[0] || '');
        }
        if (inventoryAccount && inventoryAccount !== '- Select Inventory Account -') {
            accountsPreview.push('Inv: ' + inventoryAccount.split(' - ')[0] || '');
        }
        
        if (accountsPreview.length > 0) {
            $('#billable_accounts_preview').text(accountsPreview.join(', '));
        } else {
            $('#billable_accounts_preview').text('None');
        }
    }
    
    // Update linked components summary and preview
    function updateComponentsPreview() {
        const labTests = $('#linked_lab_tests').select2('data');
        const radiology = $('#linked_radiology').select2('data');
        const beds = $('#linked_beds').select2('data');
        const inventoryItems = $('.inventory-item');
        
        let totalCost = 0;
        let componentsList = [];
        
        // Process lab tests
        if (labTests.length > 0) {
            const labTestNames = labTests.map(test => test.text.split(' - ')[1] || test.text);
            componentsList = componentsList.concat(labTestNames);
            labTests.forEach(test => {
                const price = parseFloat($(test.element).data('price') || 0);
                totalCost += price;
            });
        }
        
        // Process radiology
        if (radiology.length > 0) {
            const radiologyNames = radiology.map(img => img.text.split(' - ')[1] || img.text);
            componentsList = componentsList.concat(radiologyNames);
            radiology.forEach(img => {
                const price = parseFloat($(img.element).data('price') || 0);
                totalCost += price;
            });
        }
        
        // Process beds
        if (beds.length > 0) {
            const bedNames = beds.map(bed => bed.text.split(' - ')[1] || bed.text);
            componentsList = componentsList.concat(bedNames);
            beds.forEach(bed => {
                const price = parseFloat($(bed.element).data('price') || 0);
                totalCost += price;
            });
        }
        
        // Process inventory items
        let inventoryCount = 0;
        inventoryItems.each(function(index) {
            const selectedOption = $(this).find('option:selected');
            if (selectedOption.val()) {
                const itemName = selectedOption.text().split(' - ')[1] || selectedOption.text();
                const quantity = $(this).closest('.inventory-item-row').find('.inventory-quantity').val() || 1;
                const price = parseFloat(selectedOption.data('price') || 0);
                
                componentsList.push(itemName + ' (x' + quantity + ')');
                totalCost += (price * quantity);
                inventoryCount++;
            }
        });
        
        // Update components summary
        if (labTests.length > 0 || radiology.length > 0 || beds.length > 0 || inventoryCount > 0) {
            $('#componentsSummary').show();
            $('#labTestsSummary').html(labTests.length > 0 ? `<strong>Lab Tests:</strong> ${labTests.length} selected` : '');
            $('#radiologySummary').html(radiology.length > 0 ? `<strong>Radiology:</strong> ${radiology.length} selected` : '');
            $('#bedsSummary').html(beds.length > 0 ? `<strong>Beds:</strong> ${beds.length} selected` : '');
            $('#inventorySummary').html(inventoryCount > 0 ? `<strong>Inventory Items:</strong> ${inventoryCount} selected` : '');
            $('#totalCost').text(totalCost.toFixed(2));
            
            // Update preview
            $('#preview_components').show();
            $('#preview_components_list').text(componentsList.slice(0, 3).join(', ') + (componentsList.length > 3 ? '...' : ''));
        } else {
            $('#componentsSummary').hide();
            $('#preview_components').hide();
        }
    }
    
    // Event listeners for real-time preview
    $('#service_code, #service_name, #service_description, #service_type, #fee_amount, #duration_minutes, #tax_rate, #is_active').on('input change', updatePreview);
    
    // Event listeners for account selection
    $('#revenue_account_id, #cogs_account_id, #inventory_account_id, #tax_account_id').on('change', function() {
        updateAccountsPreview();
        updateBillableAccountsPreview();
    });
    
    // Event listeners for components selection
    $('#linked_lab_tests, #linked_radiology, #linked_beds').on('change', updateComponentsPreview);
    
    // Auto-generate service code suggestion
    $('#service_name, #service_type').on('blur', function() {
        if (!$('#service_code').val()) {
            generateServiceCode();
        }
    });
    
    function generateServiceCode() {
        const serviceType = $('#service_type').val();
        const serviceName = $('#service_name').val();
        
        if (serviceType && serviceName) {
            let code = '';
            
            // Create service type prefix
            switch(serviceType) {
                case 'Consultation': code = 'CONSULT'; break;
                case 'Procedure': code = 'PROCED'; break;
                case 'LabTest': code = 'LAB'; break;
                case 'Imaging': code = 'IMAGING'; break;
                case 'Vaccination': code = 'VACC'; break;
                case 'Package': code = 'PACKAGE'; break;
                case 'Bed': code = 'BED'; break;
                default: code = 'SERVICE'; break;
            }
            
            // Add key words from service name
            const words = serviceName.toUpperCase().split(' ');
            const keyWords = words.filter(word => 
                word.length > 2 && 
                !['GENERAL', 'BASIC', 'STANDARD', 'COMPREHENSIVE', 'COMPLETE', 'FULL', 'HOSPITALIZATION'].includes(word)
            );
            
            if (keyWords.length > 0) {
                code += '_' + keyWords.slice(0, 2).join('_');
            }
            
            $('#service_code').val(code.replace(/[^A-Z0-9_]/g, ''));
            updatePreview();
        }
    }
    
    // Set default duration based on service type
    $('#service_type').on('change', function() {
        const serviceType = $(this).val();
        let defaultDuration = 30;
        let defaultFee = 0.00;
        
        switch(serviceType) {
            case 'Consultation': defaultDuration = 30; defaultFee = 100.00; break;
            case 'Procedure': defaultDuration = 60; defaultFee = 250.00; break;
            case 'LabTest': defaultDuration = 15; defaultFee = 50.00; break;
            case 'Imaging': defaultDuration = 45; defaultFee = 200.00; break;
            case 'Vaccination': defaultDuration = 15; defaultFee = 75.00; break;
            case 'Package': defaultDuration = 120; defaultFee = 500.00; break;
            case 'Bed': defaultDuration = 1440; defaultFee = 500.00; break; // 24 hours
        }
        
        $('#duration_minutes').val(defaultDuration);
        $('#fee_amount').val(defaultFee);
        updatePreview();
    });
    
    // Initial preview update
    updatePreview();
    
    // Form validation
    $('#serviceForm').on('submit', function(e) {
        const serviceCode = $('#service_code').val().trim();
        const serviceName = $('#service_name').val().trim();
        const serviceType = $('#service_type').val();
        const fee = parseFloat($('#fee_amount').val());
        const duration = parseInt($('#duration_minutes').val());
        const revenueAccount = $('#revenue_account_id').val();
        
        let isValid = true;
        let errorMessage = '';
        
        // Validate required fields
        if (!serviceCode) {
            isValid = false;
            errorMessage = 'Service code is required';
        } else if (!serviceName) {
            isValid = false;
            errorMessage = 'Service name is required';
        } else if (!serviceType) {
            isValid = false;
            errorMessage = 'Service type is required';
        } else if (fee < 0) {
            isValid = false;
            errorMessage = 'Fee cannot be negative';
        } else if (duration <= 0) {
            isValid = false;
            errorMessage = 'Duration must be positive';
        } else if (!revenueAccount) {
            isValid = false;
            errorMessage = 'Revenue account is required for bookkeeping';
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
        'consultation': {
            service_code: 'CONSULT_GEN',
            service_name: 'General Consultation',
            service_description: 'Comprehensive medical consultation with a healthcare provider',
            service_type: 'Consultation',
            service_category_id: '',
            fee_amount: '100.00',
            duration_minutes: '30',
            tax_rate: '0.00',
            requires_doctor: true,
            insurance_billable: true,
            medical_code: '99213'
        },
        'health_package': {
            service_code: 'PACKAGE_BASIC',
            service_name: 'Basic Health Screening Package',
            service_description: 'Comprehensive health screening including basic tests and consultation',
            service_type: 'Package',
            service_category_id: '',
            fee_amount: '250.00',
            duration_minutes: '120',
            tax_rate: '0.00',
            requires_doctor: true,
            insurance_billable: true,
            medical_code: ''
        },
        'pre_employment': {
            service_code: 'PACKAGE_PRE_EMP',
            service_name: 'Pre-Employment Medical Check',
            service_description: 'Standard pre-employment medical examination and tests',
            service_type: 'Package',
            service_category_id: '',
            fee_amount: '150.00',
            duration_minutes: '60',
            tax_rate: '0.00',
            requires_doctor: true,
            insurance_billable: false,
            medical_code: ''
        },
        'executive': {
            service_code: 'PACKAGE_EXEC',
            service_name: 'Executive Health Screening',
            service_description: 'Comprehensive executive health screening with advanced diagnostics',
            service_type: 'Package',
            service_category_id: '',
            fee_amount: '500.00',
            duration_minutes: '180',
            tax_rate: '0.00',
            requires_doctor: true,
            insurance_billable: true,
            medical_code: ''
        },
        'hospitalization': {
            service_code: 'BED_PRIVATE',
            service_name: 'Private Room Hospitalization',
            service_description: 'Private room hospitalization with basic nursing care',
            service_type: 'Bed',
            service_category_id: '',
            fee_amount: '500.00',
            duration_minutes: '1440', // 24 hours
            tax_rate: '0.00',
            requires_doctor: true,
            insurance_billable: true,
            medical_code: ''
        }
    };
    
    const template = templates[templateType];
    if (template) {
        $('#service_code').val(template.service_code);
        $('#service_name').val(template.service_name);
        $('#service_description').val(template.service_description);
        $('#service_type').val(template.service_type);
        $('#service_category_id').val(template.service_category_id);
        $('#fee_amount').val(template.fee_amount);
        $('#duration_minutes').val(template.duration_minutes);
        $('#tax_rate').val(template.tax_rate);
        $('#medical_code').val(template.medical_code);
        $('#requires_doctor').prop('checked', template.requires_doctor);
        $('#insurance_billable').prop('checked', template.insurance_billable);
        
        // Trigger preview update
        $('input, select, textarea').trigger('change');
        
        // Show success message
        alert('Template loaded successfully! Please review and adjust as needed.');
    }
}

function resetForm() {
    if (confirm('Are you sure you want to reset all fields?')) {
        $('#serviceForm')[0].reset();
        $('.select2').val(null).trigger('change');
        $('.inventory-item-row:gt(0)').remove();
        $('.inventory-item-row:first .remove-inventory-item').hide();
        $('input, select, textarea').trigger('change');
    }
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + S to save
    if (e.ctrlKey && e.keyCode === 83) {
        e.preventDefault();
        $('#serviceForm').submit();
    }
    // Escape to cancel
    if (e.keyCode === 27) {
        window.location.href = 'medical_services.php';
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
.select2-container--bootstrap4 .select2-selection--multiple .select2-selection__choice {
    background-color: #007bff;
    border-color: #007bff;
    color: white;
}
.inventory-item-row {
    border-bottom: 1px dashed #dee2e6;
    padding-bottom: 10px;
}
.inventory-item-row:last-child {
    border-bottom: none;
}
.badge-bed {
    background-color: #dc3545;
}
.bg-light {
    background-color: #f8f9fa !important;
}
</style>

<?php
// Free result sets to free memory
if (isset($lab_tests)) $lab_tests->free();
if (isset($radiology_imaging)) $radiology_imaging->free();
if (isset($service_categories)) $service_categories->free();
if (isset($beds)) $beds->free();
if (isset($recent_services_result)) $recent_services_result->free();
if (isset($billable_categories_result)) $billable_categories_result->free();

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
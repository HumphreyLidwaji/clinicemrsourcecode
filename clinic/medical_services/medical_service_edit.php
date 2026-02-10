<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Helper function for activity badge colors
function getActivityBadgeColor($action) {
    switch ($action) {
        case 'create': return 'success';
        case 'edit': return 'warning';
        case 'view': return 'info';
        case 'delete': return 'danger';
        case 'activate': return 'success';
        case 'deactivate': return 'danger';
        default: return 'secondary';
    }
}

// Helper function for service type badge colors
function getServiceTypeBadgeColor($service_type) {
    switch ($service_type) {
        case 'Consultation': return 'primary';
        case 'Procedure': return 'warning';
        case 'LabTest': return 'info';
        case 'Imaging': return 'success';
        case 'Vaccination': return 'success';
        case 'Package': return 'info';
        case 'Bed': return 'danger';
        case 'Other': return 'secondary';
        default: return 'secondary';
    }
}

// Helper function for account type badge colors
function getAccountTypeBadgeColor($account_type) {
    switch ($account_type) {
        case 'revenue': return 'success';
        case 'cogs': return 'warning';
        case 'inventory': return 'info';
        case 'tax': return 'danger';
        default: return 'secondary';
    }
}

// Get service ID from URL
$medical_service_id = intval($_GET['medical_service_id'] ?? 0);

if ($medical_service_id <= 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid medical service ID.";
    header("Location: medical_services.php");
    exit;
}

// Fetch service details for editing
$service_sql = "SELECT * FROM medical_services WHERE medical_service_id = ?";
$service_stmt = $mysqli->prepare($service_sql);
$service_stmt->bind_param("i", $medical_service_id);
$service_stmt->execute();
$service_result = $service_stmt->get_result();

if ($service_result->num_rows === 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Medical service not found.";
    header("Location: medical_services.php");
    exit;
}

$service = $service_result->fetch_assoc();

// Get available data for linking - Using arrays to match add page logic
$lab_tests_result = $mysqli->query("SELECT test_id, test_code, test_name, price FROM lab_tests WHERE is_active = 1 ORDER BY test_name");
$lab_tests = [];
while ($test = $lab_tests_result->fetch_assoc()) {
    $lab_tests[] = $test;
}
$lab_tests_result->free();

$radiology_imaging_result = $mysqli->query("SELECT imaging_id, imaging_code, imaging_name, fee_amount FROM radiology_imagings WHERE is_active = 1 ORDER BY imaging_name");
$radiology_imaging = [];
while ($imaging = $radiology_imaging_result->fetch_assoc()) {
    $radiology_imaging[] = $imaging;
}
$radiology_imaging_result->free();

$service_categories_result = $mysqli->query("SELECT category_id, category_name FROM medical_service_categories");
$service_categories = [];
while ($category = $service_categories_result->fetch_assoc()) {
    $service_categories[] = $category;
}
$service_categories_result->free();

$inventory_items_result = $mysqli->query("SELECT item_id, item_code, item_name, item_unit_price 
                                   FROM inventory_items 
                                   WHERE item_status IN ('In Stock', 'Low Stock') 
                                   ORDER BY item_name");
$inventory_items = [];
while ($item = $inventory_items_result->fetch_assoc()) {
    $inventory_items[] = $item;
}
$inventory_items_result->free();

$beds_result = $mysqli->query("SELECT b.bed_id, b.bed_number, b.bed_type, b.bed_rate, 
                                w.ward_name, w.ward_type
                         FROM beds b
                         LEFT JOIN wards w ON b.bed_ward_id = w.ward_id
                         WHERE b.bed_status = 'available' 
                         AND b.bed_archived_at IS NULL
                         ORDER BY w.ward_name, b.bed_number");
$beds = [];
while ($bed = $beds_result->fetch_assoc()) {
    $beds[] = $bed;
}
$beds_result->free();

// Get accounts for bookkeeping linking - Using array to match add page
$accounts_result = $mysqli->query("SELECT account_id, account_number, account_name, account_type 
                           FROM accounts 
                           WHERE is_active = 1
                           ORDER BY account_number");
$accounts = [];
while ($account = $accounts_result->fetch_assoc()) {
    $accounts[] = $account;
}
$accounts_result->free();

// Get currently linked accounts
$current_accounts = [];
$table_check = $mysqli->query("SHOW TABLES LIKE 'service_accounts'");
if ($table_check->num_rows > 0) {
    $linked_accounts_sql = "SELECT * FROM service_accounts WHERE medical_service_id = ?";
    $linked_accounts_stmt = $mysqli->prepare($linked_accounts_sql);
    $linked_accounts_stmt->bind_param("i", $medical_service_id);
    $linked_accounts_stmt->execute();
    $linked_accounts_result = $linked_accounts_stmt->get_result();
    
    while ($account = $linked_accounts_result->fetch_assoc()) {
        $current_accounts[$account['account_type']] = $account['account_id'];
    }
    $linked_accounts_stmt->close();
}

// Get currently linked components
$current_lab_tests = [];
$current_radiology = [];
$current_beds = [];

$table_check = $mysqli->query("SHOW TABLES LIKE 'service_components'");
if ($table_check->num_rows > 0) {
    $linked_components_sql = "SELECT * FROM service_components WHERE medical_service_id = ?";
    $linked_components_stmt = $mysqli->prepare($linked_components_sql);
    $linked_components_stmt->bind_param("i", $medical_service_id);
    $linked_components_stmt->execute();
    $linked_components_result = $linked_components_stmt->get_result();
    
    while ($component = $linked_components_result->fetch_assoc()) {
        if ($component['component_type'] == 'LabTest') {
            $current_lab_tests[] = $component['component_reference_id'];
        } elseif ($component['component_type'] == 'Radiology') {
            $current_radiology[] = $component['component_reference_id'];
        } elseif ($component['component_type'] == 'Bed') {
            $current_beds[] = $component['component_reference_id'];
        }
    }
    $linked_components_stmt->close();
}

// Get currently linked inventory items
$current_inventory = [];
$table_check = $mysqli->query("SHOW TABLES LIKE 'service_inventory_items'");
if ($table_check->num_rows > 0) {
    $linked_inventory_sql = "SELECT * FROM service_inventory_items WHERE medical_service_id = ?";
    $linked_inventory_stmt = $mysqli->prepare($linked_inventory_sql);
    $linked_inventory_stmt->bind_param("i", $medical_service_id);
    $linked_inventory_stmt->execute();
    $linked_inventory_result = $linked_inventory_stmt->get_result();
    
    while ($item = $linked_inventory_result->fetch_assoc()) {
        $current_inventory[] = [
            'item_id' => $item['item_id'],
            'quantity' => $item['quantity_required']
        ];
    }
    $linked_inventory_stmt->close();
}

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
    
    // Account linking fields
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
        header("Location: medical_service_edit.php?medical_service_id=" . $medical_service_id);
        exit;
    }

    // Validate required fields
    if (empty($service_code) || empty($service_name) || empty($service_type) || $fee_amount < 0 || $duration_minutes <= 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please fill in all required fields with valid values.";
        header("Location: medical_service_edit.php?medical_service_id=" . $medical_service_id);
        exit;
    }

    // Validate account linking for bookkeeping
    if ($revenue_account_id <= 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please select a revenue account for bookkeeping.";
        header("Location: medical_service_edit.php?medical_service_id=" . $medical_service_id);
        exit;
    }

    // Validate that selected accounts exist in the database
    if ($revenue_account_id > 0) {
        $account_check = $mysqli->query("SELECT account_id FROM accounts WHERE account_id = $revenue_account_id AND is_active = 1");
        if ($account_check->num_rows == 0) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Selected revenue account does not exist or is not active.";
            header("Location: medical_service_edit.php?medical_service_id=" . $medical_service_id);
            exit;
        }
    }

    // Check if service code already exists (excluding current record)
    $check_sql = "SELECT medical_service_id FROM medical_services WHERE service_code = ? AND medical_service_id != ? AND is_active = 1";
    $check_stmt = $mysqli->prepare($check_sql);
    $check_stmt->bind_param("si", $service_code, $medical_service_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($check_result->num_rows > 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Service code already exists. Please use a unique service code.";
        header("Location: medical_service_edit.php?medical_service_id=" . $medical_service_id);
        exit;
    }
    $check_stmt->close();

    // Start transaction
    $mysqli->begin_transaction();

    try {
        // Track changes for logging
        $changes = [];
        if ($service['service_code'] != $service_code) $changes[] = "Service code: {$service['service_code']} → {$service_code}";
        if ($service['service_name'] != $service_name) $changes[] = "Service name: {$service['service_name']} → {$service_name}";
        if ($service['service_type'] != $service_type) $changes[] = "Service type: {$service['service_type']} → {$service_type}";
        if ($service['fee_amount'] != $fee_amount) $changes[] = "Fee: $" . number_format($service['fee_amount'], 2) . " → $" . number_format($fee_amount, 2);
        if ($service['is_active'] != $is_active) $changes[] = "Status: " . ($service['is_active'] ? 'Active' : 'Inactive') . " → " . ($is_active ? 'Active' : 'Inactive');

        // Update service
        $update_sql = "UPDATE medical_services SET 
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
                      updated_by = ?,
                      updated_date = NOW()
                      WHERE medical_service_id = ?";
        
        $update_stmt = $mysqli->prepare($update_sql);
        
        // Check if service_category_id is 0, set to NULL
        $service_category_id = $service_category_id > 0 ? $service_category_id : NULL;
        
        $update_stmt->bind_param(
            "sssisdiisiiisi",
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
            $session_user_id, 
            $medical_service_id
        );

        if (!$update_stmt->execute()) {
            throw new Exception("Failed to update service: " . $update_stmt->error);
        }
        $update_stmt->close();

        // Update linked accounts - Check if table exists first
        $table_check = $mysqli->query("SHOW TABLES LIKE 'service_accounts'");
        if ($table_check->num_rows > 0) {
            // First, remove all existing account links
            $delete_accounts_sql = "DELETE FROM service_accounts WHERE medical_service_id = ?";
            $delete_accounts_stmt = $mysqli->prepare($delete_accounts_sql);
            $delete_accounts_stmt->bind_param("i", $medical_service_id);
            if (!$delete_accounts_stmt->execute()) {
                throw new Exception("Failed to remove account links: " . $delete_accounts_stmt->error);
            }
            $delete_accounts_stmt->close();
            
            // Add new account links
            $account_link_sql = "INSERT INTO service_accounts (medical_service_id, account_type, account_id, created_at, created_by) 
                                VALUES (?, ?, ?, NOW(), ?)";
            $account_link_stmt = $mysqli->prepare($account_link_sql);
            
            // Link revenue account
            if ($revenue_account_id > 0) {
                $account_type = 'revenue';
                $account_link_stmt->bind_param("isii", $medical_service_id, $account_type, $revenue_account_id, $session_user_id);
                if (!$account_link_stmt->execute()) {
                    throw new Exception("Failed to link revenue account: " . $account_link_stmt->error);
                }
            }
            
            // Link COGS account
            if ($cogs_account_id > 0) {
                $account_type = 'cogs';
                $account_link_stmt->bind_param("isii", $medical_service_id, $account_type, $cogs_account_id, $session_user_id);
                if (!$account_link_stmt->execute()) {
                    throw new Exception("Failed to link COGS account: " . $account_link_stmt->error);
                }
            }
            
            // Link inventory account
            if ($inventory_account_id > 0) {
                $account_type = 'inventory';
                $account_link_stmt->bind_param("isii", $medical_service_id, $account_type, $inventory_account_id, $session_user_id);
                if (!$account_link_stmt->execute()) {
                    throw new Exception("Failed to link inventory account: " . $account_link_stmt->error);
                }
            }
            
            // Link tax account
            if ($tax_account_id > 0) {
                $account_type = 'tax';
                $account_link_stmt->bind_param("isii", $medical_service_id, $account_type, $tax_account_id, $session_user_id);
                if (!$account_link_stmt->execute()) {
                    throw new Exception("Failed to link tax account: " . $account_link_stmt->error);
                }
            }
            
            $account_link_stmt->close();
        }

        // Update linked components - Check if table exists first
        $table_check = $mysqli->query("SHOW TABLES LIKE 'service_components'");
        if ($table_check->num_rows > 0) {
            // Remove all existing component links
            $delete_links_sql = "DELETE FROM service_components WHERE medical_service_id = ?";
            $delete_links_stmt = $mysqli->prepare($delete_links_sql);
            $delete_links_stmt->bind_param("i", $medical_service_id);
            if (!$delete_links_stmt->execute()) {
                throw new Exception("Failed to remove component links: " . $delete_links_stmt->error);
            }
            $delete_links_stmt->close();
            
            // Add new lab test links
            if (!empty($linked_lab_tests)) {
                $link_lab_sql = "INSERT INTO service_components (medical_service_id, component_type, component_reference_id, created_at) VALUES (?, 'LabTest', ?, NOW())";
                $link_lab_stmt = $mysqli->prepare($link_lab_sql);
                
                foreach ($linked_lab_tests as $test_id) {
                    // Validate lab test exists
                    $test_check = $mysqli->query("SELECT test_id FROM lab_tests WHERE test_id = $test_id");
                    if ($test_check->num_rows > 0) {
                        $link_lab_stmt->bind_param("ii", $medical_service_id, $test_id);
                        if (!$link_lab_stmt->execute()) {
                            throw new Exception("Failed to link lab test: " . $link_lab_stmt->error);
                        }
                    }
                }
                $link_lab_stmt->close();
            }
            
            // Add new radiology links
            if (!empty($linked_radiology)) {
                $link_rad_sql = "INSERT INTO service_components (medical_service_id, component_type, component_reference_id, created_at) VALUES (?, 'Radiology', ?, NOW())";
                $link_rad_stmt = $mysqli->prepare($link_rad_sql);
                
                foreach ($linked_radiology as $imaging_id) {
                    // Validate radiology exists
                    $img_check = $mysqli->query("SELECT imaging_id FROM radiology_imagings WHERE imaging_id = $imaging_id");
                    if ($img_check->num_rows > 0) {
                        $link_rad_stmt->bind_param("ii", $medical_service_id, $imaging_id);
                        if (!$link_rad_stmt->execute()) {
                            throw new Exception("Failed to link radiology imaging: " . $link_rad_stmt->error);
                        }
                    }
                }
                $link_rad_stmt->close();
            }
            
            // Add new bed links
            if (!empty($linked_beds)) {
                $link_bed_sql = "INSERT INTO service_components (medical_service_id, component_type, component_reference_id, created_at) VALUES (?, 'Bed', ?, NOW())";
                $link_bed_stmt = $mysqli->prepare($link_bed_sql);
                
                foreach ($linked_beds as $bed_id) {
                    // Validate bed exists
                    $bed_check = $mysqli->query("SELECT bed_id FROM beds WHERE bed_id = $bed_id");
                    if ($bed_check->num_rows > 0) {
                        $link_bed_stmt->bind_param("ii", $medical_service_id, $bed_id);
                        if (!$link_bed_stmt->execute()) {
                            throw new Exception("Failed to link bed: " . $link_bed_stmt->error);
                        }
                    }
                }
                $link_bed_stmt->close();
            }
        }

        // Update linked inventory items - Check if table exists first
        $table_check = $mysqli->query("SHOW TABLES LIKE 'service_inventory_items'");
        if ($table_check->num_rows > 0) {
            // Remove all existing inventory links
            $delete_inventory_sql = "DELETE FROM service_inventory_items WHERE medical_service_id = ?";
            $delete_inventory_stmt = $mysqli->prepare($delete_inventory_sql);
            $delete_inventory_stmt->bind_param("i", $medical_service_id);
            if (!$delete_inventory_stmt->execute()) {
                throw new Exception("Failed to remove inventory links: " . $delete_inventory_stmt->error);
            }
            $delete_inventory_stmt->close();
            
            // Add new inventory links
            if (!empty($linked_inventory_items)) {
                $link_inv_sql = "INSERT INTO service_inventory_items (medical_service_id, item_id, quantity_required, created_at) VALUES (?, ?, ?, NOW())";
                $link_inv_stmt = $mysqli->prepare($link_inv_sql);
                
                foreach ($linked_inventory_items as $index => $item_id) {
                    if ($item_id > 0) {
                        $quantity = isset($linked_inventory_quantities[$index]) ? intval($linked_inventory_quantities[$index]) : 1;
                        // Validate inventory item exists
                        $item_check = $mysqli->query("SELECT item_id FROM inventory_items WHERE item_id = $item_id");
                        if ($item_check->num_rows > 0) {
                            $link_inv_stmt->bind_param("iii", $medical_service_id, $item_id, $quantity);
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
        
        $activity_desc = "Updated medical service: " . $service_name . " (" . $service_code . ") - Type: " . $service_type;
        if (!empty($changes)) {
            $activity_desc .= ". Changes: " . implode(", ", $changes);
        }
        if (!empty($linked_components)) {
            $activity_desc .= " - Linked: " . implode(", ", $linked_components);
        }
        
        $activity_stmt = $mysqli->prepare($activity_sql);
        $activity_stmt->bind_param("si", $activity_desc, $session_user_id);
        if (!$activity_stmt->execute()) {
            throw new Exception("Failed to log activity: " . $activity_stmt->error);
        }
        $activity_stmt->close();

        $mysqli->commit();

        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Medical service updated successfully!" . (!empty($linked_components) ? " Linked components: " . implode(", ", $linked_components) : "");
        header("Location: medical_service_details.php?medical_service_id=" . $medical_service_id);
        exit;

    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error updating medical service: " . $e->getMessage();
        header("Location: medical_service_edit.php?medical_service_id=" . $medical_service_id);
        exit;
    }
}

// Get recent activity logs - Using activities table to match add page
$activity_sql = "SELECT * FROM activities 
                 WHERE activity_description LIKE ? 
                 ORDER BY activity_timestamp DESC 
                 LIMIT 10";
$activity_stmt = $mysqli->prepare($activity_sql);
$search_term = "%" . $service['service_code'] . "%";
$activity_stmt->bind_param("s", $search_term);
$activity_stmt->execute();
$activity_result = $activity_stmt->get_result();

// Get recent services for reference
$recent_services_sql = "SELECT service_code, service_name, service_type, category_name, fee_amount, duration_minutes 
                       FROM medical_services ms
                       LEFT JOIN medical_service_categories msc ON ms.service_category_id = msc.category_id
                       WHERE ms.is_active = 1 
                       AND ms.medical_service_id != ?
                       ORDER BY ms.updated_date DESC 
                       LIMIT 5";
$recent_services_stmt = $mysqli->prepare($recent_services_sql);
$recent_services_stmt->bind_param("i", $medical_service_id);
$recent_services_stmt->execute();
$recent_services_result = $recent_services_stmt->get_result();
?>

<div class="card">
    <div class="card-header bg-warning py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-edit mr-2"></i>Edit Medical Service
            </h3>
            <div class="card-tools">
                <a href="medical_service_details.php?medical_service_id=<?php echo $medical_service_id; ?>" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Details
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
                                               value="<?php echo htmlspecialchars($service['service_code']); ?>" 
                                               placeholder="e.g., CONSULT_GEN, PROCED_MINOR, PACKAGE_BASIC" required maxlength="50">
                                        <small class="form-text text-muted">Unique identifier for the service</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="service_type">Service Type *</label>
                                        <select class="form-control" id="service_type" name="service_type" required>
                                            <option value="">- Select Service Type -</option>
                                            <option value="Consultation" <?php echo $service['service_type'] == 'Consultation' ? 'selected' : ''; ?>>Consultation</option>
                                            <option value="Procedure" <?php echo $service['service_type'] == 'Procedure' ? 'selected' : ''; ?>>Procedure</option>
                                            <option value="LabTest" <?php echo $service['service_type'] == 'LabTest' ? 'selected' : ''; ?>>Lab Test</option>
                                            <option value="Imaging" <?php echo $service['service_type'] == 'Imaging' ? 'selected' : ''; ?>>Imaging</option>
                                            <option value="Vaccination" <?php echo $service['service_type'] == 'Vaccination' ? 'selected' : ''; ?>>Vaccination</option>
                                            <option value="Package" <?php echo $service['service_type'] == 'Package' ? 'selected' : ''; ?>>Service Package</option>
                                            <option value="Bed" <?php echo $service['service_type'] == 'Bed' ? 'selected' : ''; ?>>Bed/Hospitalization</option>
                                            <option value="Other" <?php echo $service['service_type'] == 'Other' ? 'selected' : ''; ?>>Other</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="service_name">Service Name *</label>
                                <input type="text" class="form-control" id="service_name" name="service_name" 
                                       value="<?php echo htmlspecialchars($service['service_name']); ?>" 
                                       placeholder="e.g., General Consultation, Minor Procedure, Basic Health Package" required>
                            </div>

                            <div class="form-group">
                                <label for="service_description">Description</label>
                                <textarea class="form-control" id="service_description" name="service_description" rows="3" 
                                          placeholder="Brief description of what this service includes and its purpose..."
                                          maxlength="500"><?php echo htmlspecialchars($service['service_description'] ?? ''); ?></textarea>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="service_category_id">Category</label>
                                        <select class="form-control" id="service_category_id" name="service_category_id">
                                            <option value="">- Select Category -</option>
                                            <?php foreach ($service_categories as $category): ?>
                                                <option value="<?php echo $category['category_id']; ?>" <?php echo $service['service_category_id'] == $category['category_id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($category['category_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="fee_amount">Fee ($) *</label>
                                        <input type="number" class="form-control" id="fee_amount" name="fee_amount" 
                                               step="0.01" min="0" value="<?php echo number_format($service['fee_amount'], 2); ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="duration_minutes">Duration (minutes) *</label>
                                        <input type="number" class="form-control" id="duration_minutes" name="duration_minutes" 
                                               min="1" value="<?php echo intval($service['duration_minutes']); ?>" required>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Bookkeeping Accounts -->
                    <div class="card card-secondary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-book mr-2"></i>Bookkeeping Accounts</h3>
                            <small class="text-muted">Link accounts for automated bookkeeping</small>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="revenue_account_id">Revenue Account *</label>
                                        <select class="form-control select2" id="revenue_account_id" name="revenue_account_id" required>
                                            <option value="">- Select Revenue Account -</option>
                                            <?php foreach ($accounts as $account): ?>
                                                <option value="<?php echo $account['account_id']; ?>" <?php echo isset($current_accounts['revenue']) && $current_accounts['revenue'] == $account['account_id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($account['account_number'] . ' - ' . $account['account_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="form-text text-muted">Where service revenue will be recorded</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="cogs_account_id">COGS Account</label>
                                        <select class="form-control select2" id="cogs_account_id" name="cogs_account_id">
                                            <option value="">- Select COGS Account -</option>
                                            <?php foreach ($accounts as $account): ?>
                                                <option value="<?php echo $account['account_id']; ?>" <?php echo isset($current_accounts['cogs']) && $current_accounts['cogs'] == $account['account_id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($account['account_number'] . ' - ' . $account['account_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="form-text text-muted">Cost of goods sold account (optional)</small>
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
                                                <option value="<?php echo $account['account_id']; ?>" <?php echo isset($current_accounts['inventory']) && $current_accounts['inventory'] == $account['account_id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($account['account_number'] . ' - ' . $account['account_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="form-text text-muted">Inventory valuation account (optional)</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="tax_account_id">Tax Account</label>
                                        <select class="form-control select2" id="tax_account_id" name="tax_account_id">
                                            <option value="">- Select Tax Account -</option>
                                            <?php foreach ($accounts as $account): ?>
                                                <option value="<?php echo $account['account_id']; ?>" <?php echo isset($current_accounts['tax']) && $current_accounts['tax'] == $account['account_id'] ? 'selected' : ''; ?>>
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
                                    <?php foreach ($lab_tests as $test): ?>
                                        <option value="<?php echo $test['test_id']; ?>" data-price="<?php echo $test['price']; ?>" <?php echo in_array($test['test_id'], $current_lab_tests) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($test['test_code'] . ' - ' . $test['test_name'] . ' ($' . number_format($test['price'], 2) . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="form-text text-muted">Select multiple lab tests to include in this service package</small>
                            </div>

                            <!-- Radiology Imaging Linking -->
                            <div class="form-group">
                                <label>Link Radiology Imaging</label>
                                <select class="form-control select2" id="linked_radiology" name="linked_radiology[]" multiple data-placeholder="Select radiology imaging to link...">
                                    <?php foreach ($radiology_imaging as $imaging): ?>
                                        <option value="<?php echo $imaging['imaging_id']; ?>" data-price="<?php echo $imaging['fee_amount']; ?>" <?php echo in_array($imaging['imaging_id'], $current_radiology) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($imaging['imaging_code'] . ' - ' . $imaging['imaging_name'] . ' ($' . number_format($imaging['fee_amount'], 2) . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="form-text text-muted">Select multiple radiology imaging studies to include</small>
                            </div>

                            <!-- Beds Linking -->
                            <div class="form-group">
                                <label>Link Hospital Beds</label>
                                <select class="form-control select2" id="linked_beds" name="linked_beds[]" multiple data-placeholder="Select beds to link...">
                                    <?php foreach ($beds as $bed): ?>
                                        <option value="<?php echo $bed['bed_id']; ?>" data-price="<?php echo $bed['bed_rate']; ?>" <?php echo in_array($bed['bed_id'], $current_beds) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($bed['ward_name'] . ' - Bed ' . $bed['bed_number'] . ' (' . $bed['bed_type'] . ') - $' . number_format($bed['bed_rate'], 2) . '/day'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="form-text text-muted">Select hospital beds to include for hospitalization services</small>
                            </div>

                            <!-- Inventory Items Linking -->
                            <div class="form-group">
                                <label>Link Inventory Items (Medical Supplies/Consumables)</label>
                                <div id="inventoryItemsContainer">
                                    <?php if (!empty($current_inventory)): ?>
                                        <?php foreach ($current_inventory as $index => $item): ?>
                                            <div class="inventory-item-row mb-2">
                                                <div class="row">
                                                    <div class="col-md-6">
                                                        <select class="form-control select2 inventory-item" name="linked_inventory_items[]" data-placeholder="Select inventory item...">
                                                            <option value=""></option>
                                                            <?php foreach ($inventory_items as $inv_item): ?>
                                                                <option value="<?php echo $inv_item['item_id']; ?>" data-price="<?php echo $inv_item['item_unit_price']; ?>" <?php echo $item['item_id'] == $inv_item['item_id'] ? 'selected' : ''; ?>>
                                                                    <?php echo htmlspecialchars($inv_item['item_code'] . ' - ' . $inv_item['item_name'] . ' ($' . number_format($inv_item['item_unit_price'], 2) . ')'); ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <input type="number" class="form-control inventory-quantity" name="linked_inventory_quantities[]" placeholder="Qty" min="1" value="<?php echo $item['quantity']; ?>">
                                                    </div>
                                                    <div class="col-md-2">
                                                        <button type="button" class="btn btn-danger btn-sm remove-inventory-item">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
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
                                    <?php endif; ?>
                                </div>
                                <button type="button" id="addInventoryItem" class="btn btn-sm btn-outline-primary mt-2">
                                    <i class="fas fa-plus mr-1"></i>Add Another Item
                                </button>
                                <small class="form-text text-muted">Add medical supplies or consumables required for this service</small>
                            </div>

                            <!-- Linked Components Summary -->
                            <div class="border rounded p-3 bg-light mt-3" id="componentsSummary" style="<?php echo (!empty($current_lab_tests) || !empty($current_radiology) || !empty($current_beds) || !empty($current_inventory)) ? '' : 'display: none;'; ?>">
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
                                               value="<?php echo htmlspecialchars($service['medical_code']); ?>" 
                                               placeholder="e.g., CPT, ICD-10 codes" maxlength="50">
                                        <small class="form-text text-muted">Standard medical billing codes</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="tax_rate">Tax Rate (%)</label>
                                        <input type="number" class="form-control" id="tax_rate" name="tax_rate" 
                                               step="0.01" min="0" max="100" value="<?php echo number_format($service['tax_rate'], 2); ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-check mt-2">
                                        <input type="checkbox" class="form-check-input" id="requires_doctor" name="requires_doctor" value="1" <?php echo $service['requires_doctor'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="requires_doctor">Requires Doctor</label>
                                        <small class="form-text text-muted">Service must be performed by a doctor</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-check mt-2">
                                        <input type="checkbox" class="form-check-input" id="insurance_billable" name="insurance_billable" value="1" <?php echo $service['insurance_billable'] ? 'checked' : ''; ?>>
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
                                            <?php echo htmlspecialchars($service['service_code']); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="border rounded p-3 bg-light">
                                        <div class="h5 text-muted">Fee</div>
                                        <div class="h3 font-weight-bold text-success" id="preview_fee">
                                            $<?php echo number_format($service['fee_amount'], 2); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="border rounded p-3 bg-light">
                                        <div class="h5 text-muted">Duration</div>
                                        <div class="h3 font-weight-bold text-info" id="preview_duration">
                                            <?php echo intval($service['duration_minutes']); ?> min
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="border rounded p-3 bg-light">
                                        <div class="h5 text-muted">Tax Rate</div>
                                        <div class="h3 font-weight-bold text-warning" id="preview_tax">
                                            <?php echo number_format($service['tax_rate'], 2); ?>%
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mt-3">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong>Service Name:</strong> 
                                        <span id="preview_name" class="ml-2"><?php echo htmlspecialchars($service['service_name']); ?></span>
                                    </div>
                                    <div>
                                        <strong>Type:</strong> 
                                        <span id="preview_type" class="ml-2 badge badge-<?php echo getServiceTypeBadgeColor($service['service_type']); ?>"><?php echo htmlspecialchars($service['service_type']); ?></span>
                                    </div>
                                </div>
                                <div class="mt-2">
                                    <strong>Description:</strong> 
                                    <span id="preview_description" class="ml-2 text-muted"><?php echo htmlspecialchars($service['service_description'] ?: '-'); ?></span>
                                </div>
                                <div class="mt-2" id="preview_accounts" style="display: none;">
                                    <strong>Linked Accounts:</strong> 
                                    <span id="preview_accounts_list" class="ml-2 text-muted">-</span>
                                </div>
                                <div class="mt-2" id="preview_components" style="<?php echo (!empty($current_lab_tests) || !empty($current_radiology) || !empty($current_beds) || !empty($current_inventory)) ? '' : 'display: none;'; ?>">
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
                                    <i class="fas fa-save mr-2"></i>Update Service
                                </button>
                                <button type="reset" class="btn btn-outline-secondary" onclick="resetForm()">
                                    <i class="fas fa-undo mr-2"></i>Reset Changes
                                </button>
                                <a href="medical_service_details.php?medical_service_id=<?php echo $medical_service_id; ?>" class="btn btn-outline-danger">
                                    <i class="fas fa-times mr-2"></i>Cancel
                                </a>
                            </div>
                            
                            <div class="form-group mt-3">
                                <div class="custom-control custom-switch">
                                    <input type="checkbox" class="custom-control-input" id="is_active" name="is_active" value="1" <?php echo $service['is_active'] ? 'checked' : ''; ?>>
                                    <label class="custom-control-label" for="is_active">Active Service</label>
                                </div>
                                <small class="form-text text-muted">Inactive services won't be available for booking</small>
                            </div>
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

                    <!-- Recent Activity -->
                    <div class="card card-secondary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-history mr-2"></i>Recent Activity</h3>
                        </div>
                        <div class="card-body">
                            <?php if ($activity_result->num_rows > 0): ?>
                                <div class="list-group list-group-flush">
                                    <?php while ($activity = $activity_result->fetch_assoc()): ?>
                                        <div class="list-group-item px-0 py-2">
                                            <div class="d-flex w-100 justify-content-between">
                                                <small class="text-primary"><?php echo htmlspecialchars($activity['user_name'] ?? 'System'); ?></small>
                                                <small class="text-muted"><?php echo date('M j, g:i A', strtotime($activity['activity_timestamp'])); ?></small>
                                            </div>
                                            <p class="mb-1 small">
                                                <?php echo htmlspecialchars($activity['activity_description']); ?>
                                            </p>
                                        </div>
                                    <?php endwhile; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted mb-0 text-center">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    No recent activity
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Recent Services -->
                    <div class="card card-secondary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-list mr-2"></i>Recent Services</h3>
                        </div>
                        <div class="card-body">
                            <?php if ($recent_services_result->num_rows > 0): ?>
                                <div class="list-group list-group-flush">
                                    <?php while ($recent_service = $recent_services_result->fetch_assoc()): ?>
                                        <div class="list-group-item px-0 py-2">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1 text-primary"><?php echo htmlspecialchars($recent_service['service_code']); ?></h6>
                                                <small class="text-success">$<?php echo number_format($recent_service['fee_amount'], 2); ?></small>
                                            </div>
                                            <p class="mb-1 small"><?php echo htmlspecialchars($recent_service['service_name']); ?></p>
                                            <div class="d-flex justify-content-between">
                                                <small class="text-muted"><?php echo htmlspecialchars($recent_service['category_name'] ?: 'Uncategorized'); ?></small>
                                                <small class="text-muted"><?php echo $recent_service['duration_minutes']; ?>m</small>
                                            </div>
                                        </div>
                                    <?php endwhile; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted mb-0 text-center">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    No other recent services
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
                                <li>
                                    <i class="fas fa-check text-success mr-1"></i>
                                    Duration must be positive
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
        
        // Update preview elements
        $('#preview_code').text(serviceCode);
        $('#preview_name').text(serviceName);
        $('#preview_description').text(serviceDescription);
        $('#preview_fee').text('$' + parseFloat(fee).toFixed(2));
        $('#preview_duration').text(duration + ' min');
        $('#preview_tax').text(taxRate + '%');
        
        // Update service type badge
        const typeBadge = $('#preview_type');
        typeBadge.text(serviceType);
        typeBadge.removeClass('badge-primary badge-warning badge-info badge-success badge-danger badge-secondary');
        
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
    $('#service_code, #service_name, #service_description, #service_type, #fee_amount, #duration_minutes, #tax_rate').on('input change', updatePreview);
    
    // Event listeners for account selection
    $('#revenue_account_id, #cogs_account_id, #inventory_account_id, #tax_account_id').on('change', updateAccountsPreview);
    
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
    updateAccountsPreview();
    updateComponentsPreview();
    
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
            alert('Please fix the following error: ' . errorMessage);
            return false;
        }
        
        // Show loading state
        $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Updating...').prop('disabled', true);
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
    if (confirm('Are you sure you want to reset all changes?')) {
        location.reload();
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
        window.location.href = 'medical_service_details.php?medical_service_id=<?php echo $medical_service_id; ?>';
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
</style>

<?php
// Free remaining result sets
if (isset($activity_stmt)) $activity_stmt->close();
if (isset($recent_services_stmt)) $recent_services_stmt->close();
if (isset($service_stmt)) $service_stmt->close();

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
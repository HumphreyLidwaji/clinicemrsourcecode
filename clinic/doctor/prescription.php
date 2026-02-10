<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/audit_functions.php';

// AUDIT LOG: Initial page access
audit_log($mysqli, [
    'user_id'     => $_SESSION['user_id'] ?? null,
    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
    'action'      => 'PAGE_ACCESS',
    'module'      => 'Prescription',
    'table_name'  => 'N/A',
    'entity_type' => 'page',
    'record_id'   => null,
    'patient_id'  => null,
    'visit_id'    => null,
    'description' => "Accessed prescription.php",
    'status'      => 'ATTEMPT',
    'old_values'  => null,
    'new_values'  => null
]);

// Get visit_id from URL
$visit_id = intval($_GET['visit_id'] ?? 0);
$active_tab = $_GET['tab'] ?? 'prescription';

if ($visit_id <= 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid visit ID";
    
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'ACCESS_DENIED',
        'module'      => 'Prescription',
        'table_name'  => 'visits',
        'entity_type' => 'visit',
        'record_id'   => null,
        'patient_id'  => null,
        'visit_id'    => null,
        'description' => "Attempted to access prescription.php with invalid visit ID",
        'status'      => 'FAILED',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    header("Location: /clinic/dashboard.php");
    exit;
}

// Initialize variables
$patient_info = null;
$visit_info = null;
$visit_type = '';

// Get visit and patient information
$visit_sql = "SELECT v.*, 
                     p.patient_id, p.first_name, p.last_name, 
                     p.patient_mrn, p.sex as patient_gender, p.date_of_birth as patient_dob,
                     p.phone_primary as patient_phone, p.blood_group,
                     p.county, p.sub_county, p.ward, p.village, p.postal_address,
                     d.department_name,
                     doc.user_name as doctor_name,
                     v.attending_provider_id
              FROM visits v 
              JOIN patients p ON v.patient_id = p.patient_id
              LEFT JOIN departments d ON v.department_id = d.department_id
              LEFT JOIN users doc ON v.attending_provider_id = doc.user_id
              WHERE v.visit_id = ?";
$visit_stmt = $mysqli->prepare($visit_sql);
$visit_stmt->bind_param("i", $visit_id);
$visit_stmt->execute();
$visit_result = $visit_stmt->get_result();

if ($visit_result->num_rows === 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Visit not found";
    
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'ACCESS_DENIED',
        'module'      => 'Prescription',
        'table_name'  => 'visits',
        'entity_type' => 'visit',
        'record_id'   => $visit_id,
        'patient_id'  => null,
        'visit_id'    => $visit_id,
        'description' => "Visit not found for prescription",
        'status'      => 'FAILED',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    header("Location: /clinic/dashboard.php");
    exit;
}

$visit_info = $visit_result->fetch_assoc();
$patient_info = [
    'patient_id' => $visit_info['patient_id'],
    'first_name' => $visit_info['first_name'],
    'last_name' => $visit_info['last_name'],
    'patient_mrn' => $visit_info['patient_mrn'],
    'patient_gender' => $visit_info['patient_gender'],
    'patient_dob' => $visit_info['patient_dob'],
    'patient_phone' => $visit_info['patient_phone'],
    'blood_group' => $visit_info['blood_group'],
    'county' => $visit_info['county'],
    'sub_county' => $visit_info['sub_county'],
    'ward' => $visit_info['ward'],
    'village' => $visit_info['village'],
    'postal_address' => $visit_info['postal_address'],
];

$visit_type = $visit_info['visit_type'];
$visit_status = $visit_info['visit_status'];
$visit_number = $visit_info['visit_number'];

// Check for IPD admission
$is_ipd = false;
$ipd_info = null;
if ($visit_type == 'IPD') {
    $ipd_sql = "SELECT * FROM ipd_admissions WHERE visit_id = ?";
    $ipd_stmt = $mysqli->prepare($ipd_sql);
    $ipd_stmt->bind_param("i", $visit_id);
    $ipd_stmt->execute();
    $ipd_result = $ipd_stmt->get_result();
    if ($ipd_result->num_rows > 0) {
        $is_ipd = true;
        $ipd_info = $ipd_result->fetch_assoc();
    }
}

// ==================== STOCK CHECK FUNCTIONS ====================

/**
 * Get default pharmacy location ID
 */
function getDefaultPharmacyLocation($mysqli) {
    $location_sql = "SELECT location_id FROM inventory_locations 
                     WHERE location_type = 'Pharmacy' AND is_active = 1 
                     ORDER BY location_id LIMIT 1";
    $location_result = $mysqli->query($location_sql);
    if ($location_result->num_rows > 0) {
        $location = $location_result->fetch_assoc();
        return $location['location_id'];
    }
    return 1; // Default to 1 if none found
}

/**
 * Check if drug has sufficient stock
 * @param mysqli $mysqli Database connection
 * @param int $item_id Inventory item ID
 * @param int $required_quantity Quantity needed
 * @param int $location_id Pharmacy location ID (default: main pharmacy)
 * @return array [has_stock: bool, available_quantity: int, location_id: int|null]
 */
function checkDrugStock($mysqli, $item_id, $required_quantity, $location_id = null) {
    // If no location specified, get default pharmacy location
    if (!$location_id) {
        $location_id = getDefaultPharmacyLocation($mysqli);
    }
    
    // Check total stock across batches at the location
    // FIXED: Simplified query to avoid complex joins that might cause issues
    $stock_sql = "SELECT COALESCE(SUM(ils.quantity), 0) as total_stock 
                  FROM inventory_location_stock ils
                  WHERE ils.item_id = ? 
                  AND ils.location_id = ?
                  AND ils.is_active = 1
                  AND ils.quantity > 0";
    
    $stmt = $mysqli->prepare($stock_sql);
    $stmt->bind_param("ii", $item_id, $location_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    
    $total_stock = $row['total_stock'] ?? 0;
    
    return [
        'has_stock' => ($total_stock >= $required_quantity),
        'available_quantity' => floatval($total_stock),
        'location_id' => $location_id,
        'required_quantity' => $required_quantity
    ];
}

/**
 * Get drug stock details with batch information
 * @param mysqli $mysqli Database connection
 * @param int $item_id Inventory item ID
 * @param int $location_id Pharmacy location ID
 * @return array Array of batch stock details
 */
function getDrugStockDetails($mysqli, $item_id, $location_id) {
    $sql = "SELECT ils.*, ib.batch_number, ib.expiry_date, ib.manufacturer, 
                   ils.unit_cost, ils.selling_price
            FROM inventory_location_stock ils
            LEFT JOIN inventory_batches ib ON ils.batch_id = ib.batch_id
            WHERE ils.item_id = ? 
            AND ils.location_id = ?
            AND ils.is_active = 1
            AND ils.quantity > 0
            AND (ib.expiry_date IS NULL OR ib.expiry_date > CURDATE())
            ORDER BY ib.expiry_date ASC, ib.batch_number ASC";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("ii", $item_id, $location_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $stock_details = [];
    while ($row = $result->fetch_assoc()) {
        $stock_details[] = $row;
    }
    
    return $stock_details;
}

/**
 * Get available drugs with stock information
 * @param mysqli $mysqli Database connection
 * @return array Array of drugs with stock info
 */
function getAvailableDrugsWithStock($mysqli) {
    $location_id = getDefaultPharmacyLocation($mysqli);
    
    $sql = "SELECT 
                ii.item_id,
                ii.item_name,
                ii.item_code,
                ii.unit_of_measure,
                COALESCE(SUM(ils.quantity), 0) as total_stock
            FROM inventory_items ii
            LEFT JOIN inventory_location_stock ils ON ii.item_id = ils.item_id 
                AND ils.location_id = ?
                AND ils.is_active = 1
                AND ils.quantity > 0
            WHERE ii.is_active = 1 
                AND ii.is_drug = 1
            GROUP BY ii.item_id, ii.item_name, ii.item_code, ii.unit_of_measure
            ORDER BY ii.item_name";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("i", $location_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $drugs = [];
    while ($row = $result->fetch_assoc()) {
        $drugs[] = $row;
    }
    
    return $drugs;
}

/**
 * Reserve stock for dispensing (for FIFO/FEFO)
 * @param mysqli $mysqli Database connection
 * @param int $item_id Inventory item ID
 * @param int $location_id Pharmacy location ID
 * @param int $required_quantity Quantity to reserve
 * @return array Array of reserved batches with quantities
 */
function reserveStockForDispensing($mysqli, $item_id, $location_id, $required_quantity) {
    $stock_details = getDrugStockDetails($mysqli, $item_id, $location_id);
    
    $reserved_batches = [];
    $remaining_quantity = $required_quantity;
    
    foreach ($stock_details as $batch) {
        if ($remaining_quantity <= 0) break;
        
        $batch_available = floatval($batch['quantity']);
        $stock_id = $batch['stock_id'];
        $batch_id = $batch['batch_id'];
        
        if ($batch_available > 0) {
            $allocate_quantity = min($remaining_quantity, $batch_available);
            
            $reserved_batches[] = [
                'stock_id' => $stock_id,
                'batch_id' => $batch_id,
                'batch_number' => $batch['batch_number'],
                'expiry_date' => $batch['expiry_date'],
                'quantity' => $allocate_quantity,
                'unit_cost' => $batch['unit_cost'],
                'selling_price' => $batch['selling_price']
            ];
            
            $remaining_quantity -= $allocate_quantity;
        }
    }
    
    // If we couldn't allocate all required quantity, return empty
    if ($remaining_quantity > 0) {
        return [];
    }
    
    return $reserved_batches;
}

// ==================== PRESCRIPTION FUNCTIONS ====================

/**
 * Create OPD prescription with stock check
 */
function createOPDPrescription($mysqli, $visit_id, $patient_id, $item_id, $dosage, $frequency, 
                               $duration, $duration_unit, $quantity, $instructions, $priority, 
                               $notes, $prescription_type, $ordered_by, $location_id = null) {
    
    // Check stock availability
    $stock_check = checkDrugStock($mysqli, $item_id, $quantity, $location_id);
    
    if (!$stock_check['has_stock']) {
        $error_msg = "Insufficient stock for this medication. ";
        $error_msg .= "Available: " . $stock_check['available_quantity'] . ", ";
        $error_msg .= "Required: " . $quantity;
        throw new Exception($error_msg);
    }
    
    $location_id = $stock_check['location_id'];
    
    audit_log($mysqli, [
        'user_id'     => $ordered_by,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'CREATE_PRESCRIPTION',
        'module'      => 'Prescription',
        'table_name'  => 'prescriptions',
        'entity_type' => 'prescription',
        'record_id'   => null,
        'patient_id'  => $patient_id,
        'visit_id'    => $visit_id,
        'description' => "Creating new prescription with stock check",
        'status'      => 'ATTEMPT',
        'old_values'  => null,
        'new_values'  => [
            'item_id' => $item_id,
            'quantity' => $quantity,
            'has_stock' => $stock_check['has_stock'],
            'available_quantity' => $stock_check['available_quantity']
        ]
    ]);
    
    // Start transaction
    $mysqli->begin_transaction();
    
    try {
        // 1. Create prescription
        $prescription_sql = "INSERT INTO prescriptions 
                            (prescription_patient_id, prescription_visit_id, prescription_doctor_id,
                             prescription_date, prescription_type, prescription_status, 
                             prescription_priority, prescription_notes, created_by)
                            VALUES (?, ?, ?, NOW(), ?, 'pending', ?, ?, ?)";
        
        $stmt = $mysqli->prepare($prescription_sql);
        $stmt->bind_param("iiisssi", 
            $patient_id, $visit_id, $ordered_by, 
            $prescription_type, $priority, $notes, $ordered_by
        );
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to create prescription: " . $stmt->error);
        }
        
        $prescription_id = $mysqli->insert_id;
        
        // 2. Get drug price from inventory
        $price_sql = "SELECT ils.selling_price 
                     FROM inventory_location_stock ils
                     WHERE ils.item_id = ? AND ils.location_id = ?
                     AND ils.is_active = 1
                     AND ils.quantity > 0
                     LIMIT 1";
        $price_stmt = $mysqli->prepare($price_sql);
        $price_stmt->bind_param("ii", $item_id, $location_id);
        $price_stmt->execute();
        $price_result = $price_stmt->get_result();
        $price_row = $price_result->fetch_assoc();
        
        $unit_price = $price_row['selling_price'] ?? 0;
        $total_price = $unit_price * $quantity;
        
        // 3. Create prescription item with quantity
        $item_sql = "INSERT INTO prescription_items 
                    (pi_prescription_id, pi_inventory_item_id, pi_dosage, pi_frequency,
                     pi_duration, pi_duration_unit, pi_instructions, pi_quantity,
                     pi_unit_price, pi_total_price, pi_location_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $item_stmt = $mysqli->prepare($item_sql);
        $item_stmt->bind_param("iississsddi", 
            $prescription_id, $item_id, $dosage, $frequency, 
            $duration, $duration_unit, $instructions, $quantity,
            $unit_price, $total_price, $location_id
        );
        
        if (!$item_stmt->execute()) {
            throw new Exception("Failed to add prescription item: " . $item_stmt->error);
        }
        
        // 4. Reserve stock for dispensing
        $reserved_batches = reserveStockForDispensing($mysqli, $item_id, $location_id, $quantity);
        
        if (empty($reserved_batches)) {
            throw new Exception("Could not reserve stock for dispensing");
        }
        
        // 5. Record reserved batches (optional - for tracking)
        foreach ($reserved_batches as $batch) {
            $batch_sql = "INSERT INTO prescription_batch_allocations 
                         (prescription_id, stock_id, batch_id, quantity_allocated, location_id)
                         VALUES (?, ?, ?, ?, ?)";
            
            $batch_stmt = $mysqli->prepare($batch_sql);
            $batch_stmt->bind_param("iiiii", $prescription_id, $batch['stock_id'], $batch['batch_id'], $batch['quantity'], $location_id);
            $batch_stmt->execute();
        }
        
        // Commit transaction
        $mysqli->commit();
        
        audit_log($mysqli, [
            'user_id'     => $ordered_by,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'CREATE_PRESCRIPTION',
            'module'      => 'Prescription',
            'table_name'  => 'prescriptions',
            'entity_type' => 'prescription',
            'record_id'   => $prescription_id,
            'patient_id'  => $patient_id,
            'visit_id'    => $visit_id,
            'description' => "Prescription created successfully with stock reservation",
            'status'      => 'SUCCESS',
            'old_values'  => null,
            'new_values'  => [
                'prescription_id' => $prescription_id,
                'item_id' => $item_id,
                'quantity' => $quantity,
                'unit_price' => $unit_price,
                'total_price' => $total_price,
                'reserved_batches' => count($reserved_batches)
            ]
        ]);
        
        return $prescription_id;
        
    } catch (Exception $e) {
        $mysqli->rollback();
        
        audit_log($mysqli, [
            'user_id'     => $ordered_by,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'CREATE_PRESCRIPTION_FAIL',
            'module'      => 'Prescription',
            'table_name'  => 'prescriptions',
            'entity_type' => 'prescription',
            'record_id'   => null,
            'patient_id'  => $patient_id,
            'visit_id'    => $visit_id,
            'description' => "Failed to create prescription: " . $e->getMessage(),
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => null
        ]);
        
        throw $e;
    }
}

// ==================== IPD MEDICATION ORDER FUNCTIONS ====================

/**
 * Create IPD medication order with stock check
 */
function createIPDMedicationOrder($mysqli, $visit_id, $patient_id, $item_id, $dose, $frequency, $route, 
                                  $duration_days, $instructions, $ordered_by, $quantity = 1) {
    
    // For IPD orders, we still check stock but don't reserve immediately
    // Stock is deducted when medication is administered
    $stock_check = checkDrugStock($mysqli, $item_id, $quantity);
    
    error_log("DEBUG - IPD Order Stock Check: Item ID = " . $item_id . ", Quantity = " . $quantity . ", Available = " . $stock_check['available_quantity']);
    
    if (!$stock_check['has_stock']) {
        $error_msg = "Insufficient stock for this medication. ";
        $error_msg .= "Available: " . $stock_check['available_quantity'] . ", ";
        $error_msg .= "Required for initial dose: " . $quantity;
        throw new Exception($error_msg);
    }
    
    audit_log($mysqli, [
        'user_id'     => $ordered_by,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'CREATE_IPD_ORDER',
        'module'      => 'Prescription',
        'table_name'  => 'ipd_medication_orders',
        'entity_type' => 'medication_order',
        'record_id'   => null,
        'patient_id'  => $patient_id,
        'visit_id'    => $visit_id,
        'description' => "Creating new IPD medication order with stock check",
        'status'      => 'ATTEMPT',
        'old_values'  => null,
        'new_values'  => [
            'item_id' => $item_id,
            'quantity' => $quantity,
            'has_stock' => $stock_check['has_stock'],
            'available_quantity' => $stock_check['available_quantity']
        ]
    ]);
    
    $sql = "INSERT INTO ipd_medication_orders 
            (visit_id, patient_id, item_id, dose, frequency, route, 
             duration_days, instructions, start_datetime, ordered_by, initial_quantity)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("iiisssisii", 
        $visit_id, $patient_id, $item_id, $dose, $frequency, $route, 
        $duration_days, $instructions, $ordered_by, $quantity
    );
    
    if ($stmt->execute()) {
        $order_id = $mysqli->insert_id;
        
        audit_log($mysqli, [
            'user_id'     => $ordered_by,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'CREATE_IPD_ORDER',
            'module'      => 'Prescription',
            'table_name'  => 'ipd_medication_orders',
            'entity_type' => 'medication_order',
            'record_id'   => $order_id,
            'patient_id'  => $patient_id,
            'visit_id'    => $visit_id,
            'description' => "IPD medication order created successfully",
            'status'      => 'SUCCESS',
            'old_values'  => null,
            'new_values'  => [
                'visit_id' => $visit_id,
                'patient_id' => $patient_id,
                'item_id' => $item_id,
                'dose' => $dose,
                'frequency' => $frequency,
                'route' => $route,
                'status' => 'active',
                'initial_quantity' => $quantity
            ]
        ]);
        
        return $order_id;
    } else {
        $error = "Failed to create IPD medication order: " . $mysqli->error;
        
        audit_log($mysqli, [
            'user_id'     => $ordered_by,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'CREATE_IPD_ORDER_FAIL',
            'module'      => 'Prescription',
            'table_name'  => 'ipd_medication_orders',
            'entity_type' => 'medication_order',
            'record_id'   => null,
            'patient_id'  => $patient_id,
            'visit_id'    => $visit_id,
            'description' => "Failed to create IPD medication order",
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => null
        ]);
        
        throw new Exception($error);
    }
}

/**
 * Change IPD medication order
 */
function changeIPDMedicationOrder($mysqli, $old_order_id, $new_item_id, $new_dose, $new_frequency, 
                                  $new_route, $new_duration_days, $new_instructions, $changed_by, $change_reason) {
    
    // Get the old order details
    $old_order_sql = "SELECT * FROM ipd_medication_orders WHERE order_id = ?";
    $old_order_stmt = $mysqli->prepare($old_order_sql);
    $old_order_stmt->bind_param("i", $old_order_id);
    $old_order_stmt->execute();
    $old_order_result = $old_order_stmt->get_result();
    $old_order = $old_order_result->fetch_assoc();
    
    if (!$old_order) {
        throw new Exception("Original order not found");
    }
    
    // Check stock for new medication
    $stock_check = checkDrugStock($mysqli, $new_item_id, 1);
    if (!$stock_check['has_stock']) {
        throw new Exception("Insufficient stock for new medication");
    }
    
    audit_log($mysqli, [
        'user_id'     => $changed_by,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'CHANGE_IPD_ORDER',
        'module'      => 'Prescription',
        'table_name'  => 'ipd_medication_orders',
        'entity_type' => 'medication_order',
        'record_id'   => $old_order_id,
        'patient_id'  => $old_order['patient_id'],
        'visit_id'    => $old_order['visit_id'],
        'description' => "Changing IPD medication order",
        'status'      => 'ATTEMPT',
        'old_values'  => $old_order,
        'new_values'  => [
            'new_item_id' => $new_item_id,
            'new_dose' => $new_dose,
            'new_frequency' => $new_frequency,
            'new_route' => $new_route
        ]
    ]);
    
    // Start transaction
    $mysqli->begin_transaction();
    
    try {
        // 1. Stop the old order
        $stop_sql = "UPDATE ipd_medication_orders 
                    SET status = 'changed', 
                        end_datetime = NOW(),
                        stopped_by = ?,
                        stop_reason = ?
                    WHERE order_id = ?";
        
        $stop_stmt = $mysqli->prepare($stop_sql);
        $stop_reason = "Changed to new medication: " . $change_reason;
        $stop_stmt->bind_param("isi", $changed_by, $stop_reason, $old_order_id);
        $stop_stmt->execute();
        
        // 2. Create new order
        $new_order_sql = "INSERT INTO ipd_medication_orders 
                         (visit_id, patient_id, item_id, dose, frequency, route, 
                          duration_days, instructions, start_datetime, ordered_by, 
                          initial_quantity, changed_from_order_id)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?)";
        
        $new_order_stmt = $mysqli->prepare($new_order_sql);
        $new_order_stmt->bind_param("iiisssisiii", 
            $old_order['visit_id'], $old_order['patient_id'], $new_item_id, $new_dose, 
            $new_frequency, $new_route, $new_duration_days, $new_instructions, 
            $changed_by, $old_order['initial_quantity'] ?? 1, $old_order_id
        );
        $new_order_stmt->execute();
        
        $new_order_id = $mysqli->insert_id;
        
        // 3. Record the change
        $change_log_sql = "INSERT INTO ipd_order_changes 
                          (old_order_id, new_order_id, change_reason, changed_by, change_datetime)
                          VALUES (?, ?, ?, ?, NOW())";
        
        $change_log_stmt = $mysqli->prepare($change_log_sql);
        $change_log_stmt->bind_param("iisi", $old_order_id, $new_order_id, $change_reason, $changed_by);
        $change_log_stmt->execute();
        
        // Commit transaction
        $mysqli->commit();
        
        audit_log($mysqli, [
            'user_id'     => $changed_by,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'CHANGE_IPD_ORDER',
            'module'      => 'Prescription',
            'table_name'  => 'ipd_medication_orders',
            'entity_type' => 'medication_order',
            'record_id'   => $new_order_id,
            'patient_id'  => $old_order['patient_id'],
            'visit_id'    => $old_order['visit_id'],
            'description' => "IPD medication order changed successfully",
            'status'      => 'SUCCESS',
            'old_values'  => $old_order,
            'new_values'  => [
                'order_id' => $new_order_id,
                'item_id' => $new_item_id,
                'dose' => $new_dose,
                'frequency' => $new_frequency,
                'route' => $new_route
            ]
        ]);
        
        return ['new_order_id' => $new_order_id, 'old_order_id' => $old_order_id];
        
    } catch (Exception $e) {
        $mysqli->rollback();
        
        audit_log($mysqli, [
            'user_id'     => $changed_by,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'CHANGE_IPD_ORDER_FAIL',
            'module'      => 'Prescription',
            'table_name'  => 'ipd_medication_orders',
            'entity_type' => 'medication_order',
            'record_id'   => $old_order_id,
            'patient_id'  => $old_order['patient_id'],
            'visit_id'    => $old_order['visit_id'],
            'description' => "Failed to change IPD medication order",
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => null
        ]);
        
        throw $e;
    }
}

/**
 * Stop IPD medication order
 */
function stopIPDMedicationOrder($mysqli, $order_id, $stopped_by, $stop_reason) {
    
    // Get order details
    $order_sql = "SELECT * FROM ipd_medication_orders WHERE order_id = ?";
    $order_stmt = $mysqli->prepare($order_sql);
    $order_stmt->bind_param("i", $order_id);
    $order_stmt->execute();
    $order_result = $order_stmt->get_result();
    $order = $order_result->fetch_assoc();
    
    if (!$order) {
        throw new Exception("Order not found");
    }
    
    audit_log($mysqli, [
        'user_id'     => $stopped_by,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'STOP_IPD_ORDER',
        'module'      => 'Prescription',
        'table_name'  => 'ipd_medication_orders',
        'entity_type' => 'medication_order',
        'record_id'   => $order_id,
        'patient_id'  => $order['patient_id'],
        'visit_id'    => $order['visit_id'],
        'description' => "Stopping IPD medication order",
        'status'      => 'ATTEMPT',
        'old_values'  => $order,
        'new_values'  => null
    ]);
    
    $sql = "UPDATE ipd_medication_orders 
            SET status = 'stopped', 
                end_datetime = NOW(),
                stopped_by = ?,
                stop_reason = ?
            WHERE order_id = ? AND status = 'active'";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("isi", $stopped_by, $stop_reason, $order_id);
    
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        
        audit_log($mysqli, [
            'user_id'     => $stopped_by,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'STOP_IPD_ORDER',
            'module'      => 'Prescription',
            'table_name'  => 'ipd_medication_orders',
            'entity_type' => 'medication_order',
            'record_id'   => $order_id,
            'patient_id'  => $order['patient_id'],
            'visit_id'    => $order['visit_id'],
            'description' => "IPD medication order stopped successfully",
            'status'      => 'SUCCESS',
            'old_values'  => $order,
            'new_values'  => [
                'status' => 'stopped',
                'end_datetime' => date('Y-m-d H:i:s'),
                'stop_reason' => $stop_reason
            ]
        ]);
        
        return true;
    } else {
        
        audit_log($mysqli, [
            'user_id'     => $stopped_by,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'STOP_IPD_ORDER_FAIL',
            'module'      => 'Prescription',
            'table_name'  => 'ipd_medication_orders',
            'entity_type' => 'medication_order',
            'record_id'   => $order_id,
            'patient_id'  => $order['patient_id'],
            'visit_id'    => $order['visit_id'],
            'description' => "Failed to stop IPD medication order",
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => null
        ]);
        
        return false;
    }
}

// ==================== FORM SUBMISSION HANDLING ====================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Handle OPD Prescription Creation
    if (isset($_POST['create_prescription']) && $visit_type != 'IPD') {
        $item_id = intval($_POST['item_id']);
        $dosage = trim($_POST['dosage']);
        $frequency = trim($_POST['frequency']);
        $duration = intval($_POST['duration']);
        $duration_unit = trim($_POST['duration_unit']);
        $quantity = intval($_POST['quantity'] ?? 1);
        $instructions = trim($_POST['instructions'] ?? '');
        $priority = trim($_POST['priority'] ?? 'routine');
        $notes = trim($_POST['notes'] ?? '');
        $prescription_type = 'OPD';
        
        try {
            // Validate quantity
            if ($quantity < 1) {
                throw new Exception("Quantity must be at least 1");
            }
            
            $prescription_id = createOPDPrescription(
                $mysqli,
                $visit_id,
                $patient_info['patient_id'],
                $item_id,
                $dosage,
                $frequency,
                $duration,
                $duration_unit,
                $quantity,
                $instructions,
                $priority,
                $notes,
                $prescription_type,
                $_SESSION['user_id']
            );
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Prescription created successfully (ID: $prescription_id)";
            $active_tab = 'prescription';
            
        } catch (Exception $e) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error: " . $e->getMessage();
        }
    }
    
    // Handle IPD Medication Order Creation
    if (isset($_POST['create_ipd_order']) && $visit_type == 'IPD') {
        $item_id = intval($_POST['item_id']);
        $dose = trim($_POST['dose']);
        $frequency = trim($_POST['frequency']);
        $route = trim($_POST['route']);
        $duration_days = !empty($_POST['duration_days']) ? intval($_POST['duration_days']) : null;
        $instructions = trim($_POST['instructions'] ?? '');
        $quantity = intval($_POST['quantity'] ?? 1);
        
        error_log("DEBUG - Form Submission: Item ID = $item_id, Quantity = $quantity");
        
        try {
            // Validate quantity
            if ($quantity < 1) {
                throw new Exception("Quantity must be at least 1");
            }
            
            $order_id = createIPDMedicationOrder(
                $mysqli,
                $visit_id,
                $patient_info['patient_id'],
                $item_id,
                $dose,
                $frequency,
                $route,
                $duration_days,
                $instructions,
                $_SESSION['user_id'],
                $quantity
            );
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "IPD medication order created successfully (ID: $order_id)";
            $active_tab = 'ipd';
            
        } catch (Exception $e) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error: " . $e->getMessage();
        }
    }
    
    // Handle IPD Medication Order Change
    if (isset($_POST['change_ipd_order']) && $visit_type == 'IPD') {
        $old_order_id = intval($_POST['old_order_id']);
        $new_item_id = intval($_POST['new_item_id']);
        $new_dose = trim($_POST['new_dose']);
        $new_frequency = trim($_POST['new_frequency']);
        $new_route = trim($_POST['new_route']);
        $new_duration_days = !empty($_POST['new_duration_days']) ? intval($_POST['new_duration_days']) : null;
        $new_instructions = trim($_POST['new_instructions'] ?? '');
        $change_reason = trim($_POST['change_reason']);
        $quantity = intval($_POST['quantity'] ?? 1);
        
        try {
            // Check stock for new medication
            $stock_check = checkDrugStock($mysqli, $new_item_id, $quantity);
            if (!$stock_check['has_stock']) {
                throw new Exception("Insufficient stock for new medication");
            }
            
            $result = changeIPDMedicationOrder(
                $mysqli,
                $old_order_id,
                $new_item_id,
                $new_dose,
                $new_frequency,
                $new_route,
                $new_duration_days,
                $new_instructions,
                $_SESSION['user_id'],
                $change_reason
            );
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Medication changed successfully. New Order ID: " . $result['new_order_id'];
            $active_tab = 'ipd';
            
        } catch (Exception $e) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error changing medication: " . $e->getMessage();
        }
    }
    
    // Handle IPD Medication Order Stop
    if (isset($_POST['stop_ipd_order']) && $visit_type == 'IPD') {
        $order_id = intval($_POST['order_id']);
        $stop_reason = trim($_POST['stop_reason']);
        
        if (stopIPDMedicationOrder($mysqli, $order_id, $_SESSION['user_id'], $stop_reason)) {
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Medication order stopped successfully";
        } else {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Failed to stop medication order";
        }
        
        $active_tab = 'ipd';
    }
}

// ==================== DATA FETCHING ====================

// Get default pharmacy location
$default_location_id = getDefaultPharmacyLocation($mysqli);

// Get available drugs with stock information
$available_drugs = getAvailableDrugsWithStock($mysqli);

// DEBUG: Test the checkDrugStock function with a specific item
error_log("DEBUG - Default Location ID: " . $default_location_id);
if (!empty($available_drugs)) {
    $test_item = $available_drugs[0];
    $test_stock = checkDrugStock($mysqli, $test_item['item_id'], 1);
    error_log("DEBUG - Test Item: " . $test_item['item_name'] . " - Available: " . $test_stock['available_quantity']);
}

// Get OPD prescriptions
$prescriptions = [];
if ($visit_type != 'IPD') {
    $prescriptions_sql = "SELECT p.*, 
                         ii.item_name, ii.item_code, ii.unit_of_measure,
                         pi.pi_dosage, pi.pi_frequency, pi.pi_duration, 
                         pi.pi_instructions, pi.pi_duration_unit,
                         pi.pi_quantity, pi.pi_dispensed_quantity, 
                         pi.pi_unit_price, pi.pi_total_price,
                         doc.user_name as doctor_name,
                         loc.location_name
                         FROM prescriptions p
                         JOIN prescription_items pi ON p.prescription_id = pi.pi_prescription_id
                         JOIN inventory_items ii ON pi.pi_inventory_item_id = ii.item_id
                         JOIN users doc ON p.prescription_doctor_id = doc.user_id
                         LEFT JOIN inventory_locations loc ON pi.pi_location_id = loc.location_id
                         WHERE p.prescription_visit_id = ? 
                         AND p.prescription_patient_id = ?
                         AND p.prescription_archived_at IS NULL
                         ORDER BY p.prescription_date DESC";
    $prescriptions_stmt = $mysqli->prepare($prescriptions_sql);
    $prescriptions_stmt->bind_param("ii", $visit_id, $patient_info['patient_id']);
    $prescriptions_stmt->execute();
    $prescriptions_result = $prescriptions_stmt->get_result();
    $prescriptions = $prescriptions_result->fetch_all(MYSQLI_ASSOC);
}

// Get IPD medication orders
$ipd_orders = [];
$ipd_order_history = [];
if ($visit_type == 'IPD') {
    // Active orders
    $ipd_orders_sql = "SELECT imo.*, 
                      ii.item_name, ii.item_code, ii.unit_of_measure,
                      u.user_name as ordered_by_name,
                      u2.user_name as stopped_by_name
               FROM ipd_medication_orders imo
               JOIN inventory_items ii ON imo.item_id = ii.item_id
               JOIN users u ON imo.ordered_by = u.user_id
               LEFT JOIN users u2 ON imo.stopped_by = u2.user_id
               WHERE imo.visit_id = ? AND imo.patient_id = ?
               ORDER BY imo.status = 'active' DESC, imo.start_datetime DESC";
    
    $ipd_orders_stmt = $mysqli->prepare($ipd_orders_sql);
    $ipd_orders_stmt->bind_param("ii", $visit_id, $patient_info['patient_id']);
    $ipd_orders_stmt->execute();
    $ipd_orders_result = $ipd_orders_stmt->get_result();
    
    while ($order = $ipd_orders_result->fetch_assoc()) {
        if ($order['status'] == 'active') {
            $ipd_orders[] = $order;
        } else {
            $ipd_order_history[] = $order;
        }
    }
}

// DEBUG: Let's check what items we have in inventory using the same logic as the form
$debug_items_sql = "SELECT 
    ii.item_id,
    ii.item_name,
    ii.item_code,
    ii.unit_of_measure,
    COALESCE(SUM(ils.quantity), 0) as total_stock
FROM inventory_items ii
LEFT JOIN inventory_location_stock ils ON ii.item_id = ils.item_id
    AND ils.location_id = ?
    AND ils.is_active = 1
    AND ils.quantity > 0
WHERE ii.is_active = 1 
    AND ii.is_drug = 1
GROUP BY ii.item_id
ORDER BY ii.item_name";

$debug_stmt = $mysqli->prepare($debug_items_sql);
$debug_stmt->bind_param("i", $default_location_id);
$debug_stmt->execute();
$debug_items_result = $debug_stmt->get_result();
$inventory_debug = [];
while ($item = $debug_items_result->fetch_assoc()) {
    $inventory_debug[] = $item;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prescription Management - <?php echo htmlspecialchars($patient_info['first_name'] . ' ' . $patient_info['last_name']); ?></title>
    <link rel="stylesheet" href="/plugins/select2/css/select2.min.css">
    <link rel="stylesheet" href="/plugins/select2-bootstrap4-theme/select2-bootstrap4.min.css">
    <link rel="stylesheet" href="/plugins/sweetalert2/sweetalert2.min.css">
    <style>
        .prescription-timeline {
            position: relative;
            padding-left: 30px;
        }
        .prescription-timeline::before {
            content: '';
            position: absolute;
            left: 15px;
            top: 0;
            bottom: 0;
            width: 2px;
            background-color: #007bff;
        }
        .timeline-item {
            position: relative;
            margin-bottom: 20px;
        }
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -25px;
            top: 5px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background-color: #007bff;
            border: 2px solid white;
        }
        .order-changed {
            background-color: #fff3cd !important;
            border-left: 4px solid #ffc107 !important;
        }
        .order-stopped {
            background-color: #f8d7da !important;
            border-left: 4px solid #dc3545 !important;
        }
        .stock-info {
            font-size: 12px;
            padding: 2px 6px;
            border-radius: 3px;
        }
        .stock-available {
            background-color: #d4edda;
            color: #155724;
        }
        .stock-low {
            background-color: #fff3cd;
            color: #856404;
        }
        .stock-out {
            background-color: #f8d7da;
            color: #721c24;
        }
        .debug-panel {
            background-color: #f8f9fa;
            border-left: 4px solid #17a2b8;
            padding: 10px;
            margin-bottom: 15px;
            font-size: 12px;
        }
        .select2-container--bootstrap4 .select2-results__option[aria-selected=true] {
            background-color: #e9ecef;
        }
        .drug-option {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .drug-name {
            flex: 1;
        }
        .drug-stock {
            font-size: 0.9em;
            padding: 2px 6px;
            border-radius: 3px;
            min-width: 80px;
            text-align: right;
        }
        .stock-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 5px;
        }
        .stock-good {
            background-color: #28a745;
        }
        .stock-warning {
            background-color: #ffc107;
        }
        .stock-danger {
            background-color: #dc3545;
        }
        .stock-good-text {
            color: #28a745;
            background-color: rgba(40, 167, 69, 0.1);
        }
        .stock-warning-text {
            color: #ffc107;
            background-color: rgba(255, 193, 7, 0.1);
        }
        .stock-danger-text {
            color: #dc3545;
            background-color: rgba(220, 53, 69, 0.1);
        }
        .select2-results__option {
            padding: 8px 12px !important;
        }
        .form-control.is-invalid {
            border-color: #dc3545;
        }
        .invalid-feedback {
            display: block;
        }
        .debug-test {
            background-color: #e9f7fe;
            border: 1px solid #b3e0ff;
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 4px;
        }
    </style>
</head>
<body>
<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-prescription mr-2"></i>
            <?php echo $visit_type == 'IPD' ? 'IPD Medication Orders' : 'Prescription Management'; ?>
            : <?php echo htmlspecialchars($patient_info['patient_mrn']); ?>
        </h3>
        <div class="card-tools">
            <div class="btn-group">
                <button type="button" class="btn btn-light" onclick="window.history.back()">
                    <i class="fas fa-arrow-left mr-2"></i>Back
                </button>
                <button type="button" class="btn btn-success" onclick="window.print()">
                    <i class="fas fa-print mr-2"></i>Print
                </button>
                <?php if ($visit_type == 'IPD'): ?>
                    <a href="/clinic/doctor/administer_meds.php?visit_id=<?php echo $visit_id; ?>" class="btn btn-warning">
                        <i class="fas fa-syringe mr-2"></i>Administer Meds
                    </a>
                <?php endif; ?>
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

     
        <!-- Patient and Visit Info -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card bg-light">
                    <div class="card-body py-2">
                        <div class="row">
                            <div class="col-md-8">
                                <div class="row">
                                    <div class="col-md-6">
                                        <table class="table table-sm table-borderless mb-0">
                                            <tr>
                                                <th width="40%" class="text-muted">Patient:</th>
                                                <td><strong><?php echo htmlspecialchars($patient_info['first_name'] . ' ' . $patient_info['last_name']); ?></strong></td>
                                            </tr>
                                            <tr>
                                                <th class="text-muted">MRN:</th>
                                                <td><span class="badge badge-info"><?php echo htmlspecialchars($patient_info['patient_mrn']); ?></span></td>
                                            </tr>
                                            <tr>
                                                <th class="text-muted">Age/Sex:</th>
                                                <td>
                                                    <span class="badge badge-secondary">
                                                        <?php 
                                                        $age = '';
                                                        if (!empty($patient_info['patient_dob'])) {
                                                            $birthDate = new DateTime($patient_info['patient_dob']);
                                                            $today = new DateTime();
                                                            $age = $today->diff($birthDate)->y . ' years';
                                                        }
                                                        echo $age ?: 'N/A'; 
                                                        ?> / <?php echo htmlspecialchars($patient_info['patient_gender']); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        </table>
                                    </div>
                                    <div class="col-md-6">
                                        <table class="table table-sm table-borderless mb-0">
                                            <tr>
                                                <th class="text-muted">Visit Type:</th>
                                                <td>
                                                    <span class="badge badge-<?php 
                                                        echo $visit_type == 'OPD' ? 'primary' : 
                                                             ($visit_type == 'IPD' ? 'success' : 
                                                             ($visit_type == 'EMERGENCY' ? 'danger' : 'secondary')); 
                                                    ?>">
                                                        <?php echo htmlspecialchars($visit_type); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th class="text-muted">Visit #:</th>
                                                <td><?php echo htmlspecialchars($visit_number); ?></td>
                                            </tr>
                                            <?php if ($visit_type == 'IPD' && $ipd_info): ?>
                                            <tr>
                                                <th class="text-muted">IPD Admission:</th>
                                                <td>
                                                    <span class="badge badge-warning">
                                                        <?php echo htmlspecialchars($ipd_info['admission_number'] ?? 'N/A'); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <?php endif; ?>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 text-right">
                                <div class="mt-2">
                                    <?php if ($visit_type == 'IPD'): ?>
                                        <span class="h5">
                                            <i class="fas fa-clipboard-list text-primary mr-1"></i>
                                            <span class="badge badge-light"><?php echo count($ipd_orders); ?> Active Orders</span>
                                        </span>
                                        <br>
                                        <span class="h5">
                                            <i class="fas fa-history text-secondary mr-1"></i>
                                            <span class="badge badge-light"><?php echo count($ipd_order_history); ?> History</span>
                                        </span>
                                    <?php else: ?>
                                        <span class="h5">
                                            <i class="fas fa-prescription text-primary mr-1"></i>
                                            <span class="badge badge-light"><?php echo count($prescriptions); ?> Rx</span>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content Tabs -->
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <ul class="nav nav-pills card-header-pills">
                            <?php if ($visit_type == 'IPD'): ?>
                                <li class="nav-item">
                                    <a class="nav-link <?php echo $active_tab == 'ipd' ? 'active' : ''; ?>" 
                                       href="#ipd-orders" data-toggle="tab">
                                        <i class="fas fa-procedures mr-1"></i> IPD Medication Orders
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link <?php echo $active_tab == 'new-order' ? 'active' : ''; ?>" 
                                       href="#new-ipd-order" data-toggle="tab">
                                        <i class="fas fa-plus-circle mr-1"></i> New Order
                                    </a>
                                </li>
                            <?php else: ?>
                                <li class="nav-item">
                                    <a class="nav-link <?php echo $active_tab == 'prescription' ? 'active' : ''; ?>" 
                                       href="#prescription" data-toggle="tab">
                                        <i class="fas fa-prescription mr-1"></i> Prescriptions
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link <?php echo $active_tab == 'new-prescription' ? 'active' : ''; ?>" 
                                       href="#new-prescription" data-toggle="tab">
                                        <i class="fas fa-plus mr-1"></i> New Prescription
                                    </a>
                                </li>
                            <?php endif; ?>
                            <li class="nav-item">
                                <a class="nav-link" href="#history" data-toggle="tab">
                                    <i class="fas fa-history mr-1"></i> History
                                </a>
                            </li>
                        </ul>
                    </div>
                    <div class="card-body">
                        <div class="tab-content">
                            
                            <?php if ($visit_type == 'IPD'): ?>
                                
                                <!-- IPD Medication Orders Tab -->
                                <div class="tab-pane fade <?php echo $active_tab == 'ipd' ? 'show active' : ''; ?>" id="ipd-orders">
                                    <?php if (!empty($ipd_orders)): ?>
                                        <div class="table-responsive">
                                            <table class="table table-hover">
                                                <thead>
                                                    <tr>
                                                        <th>Medication</th>
                                                        <th>Dose</th>
                                                        <th>Frequency</th>
                                                        <th>Route</th>
                                                        <th>Quantity</th>
                                                        <th>Started</th>
                                                        <th>Duration</th>
                                                        <th>Status</th>
                                                        <th>Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($ipd_orders as $order): 
                                                        // Check current stock for this medication
                                                        $current_stock = checkDrugStock($mysqli, $order['item_id'], 1);
                                                        $stock_class = '';
                                                        if ($current_stock['available_quantity'] == 0) {
                                                            $stock_class = 'stock-out';
                                                        } elseif ($current_stock['available_quantity'] < 10) {
                                                            $stock_class = 'stock-low';
                                                        } else {
                                                            $stock_class = 'stock-available';
                                                        }
                                                    ?>
                                                    <tr class="<?php echo $order['changed_from_order_id'] ? 'order-changed' : ''; ?>">
                                                        <td>
                                                            <strong><?php echo htmlspecialchars($order['item_name']); ?></strong>
                                                            <br>
                                                            <small class="text-muted"><?php echo htmlspecialchars($order['item_code']); ?></small>
                                                            <div class="mt-1">
                                                                <span class="stock-info <?php echo $stock_class; ?>">
                                                                    <i class="fas fa-box mr-1"></i>
                                                                    Stock: <?php echo $current_stock['available_quantity']; ?> <?php echo htmlspecialchars($order['unit_of_measure']); ?>
                                                                </span>
                                                            </div>
                                                            <?php if ($order['changed_from_order_id']): ?>
                                                                <br>
                                                                <small class="text-warning">
                                                                    <i class="fas fa-exchange-alt mr-1"></i>
                                                                    Changed from previous medication
                                                                </small>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?php echo htmlspecialchars($order['dose']); ?></td>
                                                        <td><?php echo htmlspecialchars($order['frequency']); ?></td>
                                                        <td><?php echo htmlspecialchars($order['route']); ?></td>
                                                        <td>
                                                            <span class="badge badge-primary">
                                                                <?php echo htmlspecialchars($order['initial_quantity'] ?? 1); ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <?php echo date('M j, H:i', strtotime($order['start_datetime'])); ?>
                                                            <br>
                                                            <small class="text-muted">
                                                                by <?php echo htmlspecialchars($order['ordered_by_name']); ?>
                                                            </small>
                                                        </td>
                                                        <td>
                                                            <?php echo $order['duration_days'] ? $order['duration_days'] . ' days' : 'Continuous'; ?>
                                                        </td>
                                                        <td>
                                                            <?php 
                                                            $badge_class = $order['status'] == 'active' ? 'success' : 'secondary';
                                                            echo '<span class="badge badge-' . $badge_class . '">' . strtoupper($order['status']) . '</span>';
                                                            ?>
                                                        </td>
                                                        <td>
                                                            <div class="btn-group btn-group-sm">
                                                                <button type="button" class="btn btn-warning" 
                                                                        onclick="showChangeOrderModal(<?php echo htmlspecialchars(json_encode($order)); ?>)"
                                                                        title="Change Medication">
                                                                    <i class="fas fa-exchange-alt"></i>
                                                                </button>
                                                                <button type="button" class="btn btn-danger" 
                                                                        onclick="showStopOrderModal(<?php echo $order['order_id']; ?>, '<?php echo htmlspecialchars($order['item_name']); ?>')"
                                                                        title="Stop Medication">
                                                                    <i class="fas fa-stop"></i>
                                                                </button>
                                                                <button type="button" class="btn btn-info" 
                                                                        onclick="viewOrderDetails(<?php echo $order['order_id']; ?>)"
                                                                        title="View Details">
                                                                    <i class="fas fa-eye"></i>
                                                                </button>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php else: ?>
                                        <div class="text-center py-5">
                                            <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                                            <h5 class="text-muted">No Active Medication Orders</h5>
                                            <p class="text-muted">Create new medication orders using the "New Order" tab.</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- New IPD Order Tab -->
                                <div class="tab-pane fade <?php echo $active_tab == 'new-order' ? 'show active' : ''; ?>" id="new-ipd-order">
                                    <form method="POST" id="ipdOrderForm">
                                        <div class="row">
                                            <div class="col-md-8">
                                                <div class="form-group">
                                                    <label for="item_id">Medication *</label>
                                                    <select class="form-control select2" id="item_id" name="item_id" required
                                                            onchange="updateStockInfo(this.value, 'ipd')">
                                                        <option value="">-- Select Medication --</option>
                                                        <?php foreach ($available_drugs as $item): 
                                                            $stock_class = '';
                                                            $stock_indicator = '';
                                                            $stock_text_class = '';
                                                            if ($item['total_stock'] == 0) {
                                                                $stock_class = 'stock-danger';
                                                                $stock_indicator = '<span class="stock-indicator stock-danger"></span>';
                                                                $stock_text_class = 'stock-danger-text';
                                                            } elseif ($item['total_stock'] < 10) {
                                                                $stock_class = 'stock-warning';
                                                                $stock_indicator = '<span class="stock-indicator stock-warning"></span>';
                                                                $stock_text_class = 'stock-warning-text';
                                                            } else {
                                                                $stock_class = 'stock-good';
                                                                $stock_indicator = '<span class="stock-indicator stock-good"></span>';
                                                                $stock_text_class = 'stock-good-text';
                                                            }
                                                        ?>
                                                            <option value="<?php echo $item['item_id']; ?>" 
                                                                    data-stock="<?php echo $item['total_stock']; ?>"
                                                                    data-unit="<?php echo htmlspecialchars($item['unit_of_measure']); ?>">
                                                                <div class="drug-option">
                                                                    <span class="drug-name">
                                                                        <?php echo $stock_indicator; ?>
                                                                        <?php echo htmlspecialchars($item['item_name']); ?> 
                                                                        (<?php echo htmlspecialchars($item['item_code']); ?>)
                                                                    </span>
                                                                    <span class="drug-stock <?php echo $stock_text_class; ?>">
                                                                        <?php echo floatval($item['total_stock']); ?> <?php echo htmlspecialchars($item['unit_of_measure']); ?>
                                                                    </span>
                                                                </div>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <small id="stockInfo" class="form-text text-muted">Select a medication to see stock availability</small>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-group">
                                                    <label for="route">Route *</label>
                                                    <select class="form-control" id="route" name="route" required>
                                                        <option value="">-- Select Route --</option>
                                                        <option value="PO">PO (Oral)</option>
                                                        <option value="IV">IV (Intravenous)</option>
                                                        <option value="IM">IM (Intramuscular)</option>
                                                        <option value="SC">SC (Subcutaneous)</option>
                                                        <option value="TOP">TOP (Topical)</option>
                                                        <option value="INH">INH (Inhalation)</option>
                                                        <option value="PR">PR (Rectal)</option>
                                                        <option value="PV">PV (Vaginal)</option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="row">
                                            <div class="col-md-3">
                                                <div class="form-group">
                                                    <label for="dose">Dose *</label>
                                                    <input type="text" class="form-control" id="dose" name="dose" 
                                                           placeholder="e.g., 1 tab, 5mg, 10ml" required>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="form-group">
                                                    <label for="frequency">Frequency *</label>
                                                    <select class="form-control" id="frequency" name="frequency" required>
                                                        <option value="">-- Select Frequency --</option>
                                                        <option value="Once daily">Once daily</option>
                                                        <option value="Twice daily">Twice daily</option>
                                                        <option value="Three times daily">Three times daily</option>
                                                        <option value="Four times daily">Four times daily</option>
                                                        <option value="Every 4 hours">Every 4 hours</option>
                                                        <option value="Every 6 hours">Every 6 hours</option>
                                                        <option value="Every 8 hours">Every 8 hours</option>
                                                        <option value="Every 12 hours">Every 12 hours</option>
                                                        <option value="At bedtime">At bedtime</option>
                                                        <option value="As needed">As needed</option>
                                                        <option value="STAT">STAT (Immediately)</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="form-group">
                                                    <label for="quantity">Quantity *</label>
                                                    <input type="number" class="form-control" id="quantity" name="quantity" 
                                                           min="1" max="1000" value="1" required
                                                           onchange="validateQuantity('ipd')">
                                                    <small class="form-text text-muted">Number of units per dose</small>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="form-group">
                                                    <label for="duration_days">Duration (days)</label>
                                                    <input type="number" class="form-control" id="duration_days" name="duration_days" 
                                                           min="1" max="365" placeholder="Leave empty for continuous">
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="instructions">Instructions</label>
                                            <textarea class="form-control" id="instructions" name="instructions" 
                                                      rows="3" placeholder="Special instructions for administration"></textarea>
                                        </div>
                                        
                                        <div class="row">
                                            <div class="col-md-12">
                                                <button type="submit" name="create_ipd_order" class="btn btn-success">
                                                    <i class="fas fa-save mr-2"></i>Create Medication Order
                                                </button>
                                                <button type="reset" class="btn btn-secondary" onclick="resetStockInfo('ipd')">
                                                    <i class="fas fa-times mr-2"></i>Clear Form
                                                </button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                                
                            <?php else: ?>
                                
                                <!-- OPD Prescriptions Tab -->
                                <div class="tab-pane fade <?php echo $active_tab == 'prescription' ? 'show active' : ''; ?>" id="prescription">
                                    <?php if (!empty($prescriptions)): ?>
                                        <div class="table-responsive">
                                            <table class="table table-hover">
                                                <thead>
                                                    <tr>
                                                        <th>Medication</th>
                                                        <th>Dosage</th>
                                                        <th>Frequency</th>
                                                        <th>Duration</th>
                                                        <th>Quantity</th>
                                                        <th>Price</th>
                                                        <th>Date</th>
                                                        <th>Status</th>
                                                        <th>Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($prescriptions as $rx): 
                                                        // Check current stock for this medication
                                                        $current_stock = checkDrugStock($mysqli, $rx['pi_inventory_item_id'], 1);
                                                    ?>
                                                    <tr>
                                                        <td>
                                                            <strong><?php echo htmlspecialchars($rx['item_name']); ?></strong>
                                                            <br>
                                                            <small class="text-muted"><?php echo htmlspecialchars($rx['item_code']); ?></small>
                                                            <div class="mt-1">
                                                                <?php if ($current_stock['available_quantity'] == 0): ?>
                                                                    <span class="badge badge-danger">
                                                                        <i class="fas fa-exclamation-triangle mr-1"></i>
                                                                        Stock: <?php echo $current_stock['available_quantity']; ?>
                                                                    </span>
                                                                <?php elseif ($current_stock['available_quantity'] < 10): ?>
                                                                    <span class="badge badge-warning">
                                                                        <i class="fas fa-exclamation-circle mr-1"></i>
                                                                        Stock: <?php echo $current_stock['available_quantity']; ?>
                                                                    </span>
                                                                <?php else: ?>
                                                                    <span class="badge badge-success">
                                                                        <i class="fas fa-check-circle mr-1"></i>
                                                                        Stock: <?php echo $current_stock['available_quantity']; ?>
                                                                    </span>
                                                                <?php endif; ?>
                                                                <?php if ($rx['location_name']): ?>
                                                                    <span class="badge badge-info"><?php echo htmlspecialchars($rx['location_name']); ?></span>
                                                                <?php endif; ?>
                                                            </div>
                                                        </td>
                                                        <td><?php echo htmlspecialchars($rx['pi_dosage']); ?></td>
                                                        <td><?php echo htmlspecialchars($rx['pi_frequency']); ?></td>
                                                        <td>
                                                            <?php echo htmlspecialchars($rx['pi_duration']); ?> 
                                                            <?php echo htmlspecialchars($rx['pi_duration_unit']); ?>
                                                        </td>
                                                        <td>
                                                            <span class="badge badge-primary">
                                                                <?php echo htmlspecialchars($rx['pi_quantity']); ?>
                                                                <?php if ($rx['pi_dispensed_quantity'] > 0): ?>
                                                                    <br>
                                                                    <small class="text-muted">
                                                                        Dispensed: <?php echo $rx['pi_dispensed_quantity']; ?>
                                                                    </small>
                                                                <?php endif; ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <?php if ($rx['pi_total_price'] > 0): ?>
                                                                <?php echo numfmt_format_currency($currency_format, $rx['pi_total_price'], $session_company_currency); ?>
                                                            <?php else: ?>
                                                                <span class="text-muted">N/A</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?php echo date('M j, H:i', strtotime($rx['prescription_date'])); ?></td>
                                                        <td>
                                                            <?php 
                                                            $badge_class = '';
                                                            switch($rx['prescription_status']) {
                                                                case 'active': $badge_class = 'success'; break;
                                                                case 'pending': $badge_class = 'warning'; break;
                                                                case 'partial': $badge_class = 'info'; break;
                                                                case 'completed': $badge_class = 'secondary'; break;
                                                                case 'cancelled': $badge_class = 'danger'; break;
                                                                case 'dispensed': $badge_class = 'primary'; break;
                                                                default: $badge_class = 'light';
                                                            }
                                                            
                                                            $badge = '<span class="badge badge-' . $badge_class . '">' . strtoupper($rx['prescription_status']) . '</span>';
                                                            
                                                            if ($rx['prescription_priority'] && $rx['prescription_priority'] !== 'routine') {
                                                                $priority_class = $rx['prescription_priority'] === 'urgent' ? 'warning' : 'danger';
                                                                $badge .= ' <span class="badge badge-' . $priority_class . '">' . strtoupper($rx['prescription_priority']) . '</span>';
                                                            }
                                                            echo $badge;
                                                            ?>
                                                        </td>
                                                        <td>
                                                            <button type="button" class="btn btn-sm btn-info" 
                                                                    onclick="viewPrescriptionDetails(<?php echo $rx['prescription_id']; ?>)">
                                                                <i class="fas fa-eye"></i>
                                                            </button>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php else: ?>
                                        <div class="text-center py-5">
                                            <i class="fas fa-prescription fa-3x text-muted mb-3"></i>
                                            <h5 class="text-muted">No Prescriptions Yet</h5>
                                            <p class="text-muted">Create new prescriptions using the "New Prescription" tab.</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- New OPD Prescription Tab -->
                                <div class="tab-pane fade <?php echo $active_tab == 'new-prescription' ? 'show active' : ''; ?>" id="new-prescription">
                                    <form method="POST" id="prescriptionForm">
                                        <div class="row">
                                            <div class="col-md-8">
                                                <div class="form-group">
                                                    <label for="item_id_opd">Medication *</label>
                                                    <select class="form-control select2" id="item_id_opd" name="item_id" required
                                                            onchange="updateStockInfo(this.value, 'opd')">
                                                        <option value="">-- Select Medication --</option>
                                                        <?php foreach ($available_drugs as $item): 
                                                            $stock_class = '';
                                                            $stock_indicator = '';
                                                            $stock_text_class = '';
                                                            if ($item['total_stock'] == 0) {
                                                                $stock_class = 'stock-danger';
                                                                $stock_indicator = '<span class="stock-indicator stock-danger"></span>';
                                                                $stock_text_class = 'stock-danger-text';
                                                            } elseif ($item['total_stock'] < 10) {
                                                                $stock_class = 'stock-warning';
                                                                $stock_indicator = '<span class="stock-indicator stock-warning"></span>';
                                                                $stock_text_class = 'stock-warning-text';
                                                            } else {
                                                                $stock_class = 'stock-good';
                                                                $stock_indicator = '<span class="stock-indicator stock-good"></span>';
                                                                $stock_text_class = 'stock-good-text';
                                                            }
                                                        ?>
                                                            <option value="<?php echo $item['item_id']; ?>" 
                                                                    data-stock="<?php echo $item['total_stock']; ?>"
                                                                    data-unit="<?php echo htmlspecialchars($item['unit_of_measure']); ?>">
                                                                <div class="drug-option">
                                                                    <span class="drug-name">
                                                                        <?php echo $stock_indicator; ?>
                                                                        <?php echo htmlspecialchars($item['item_name']); ?> 
                                                                        (<?php echo htmlspecialchars($item['item_code']); ?>)
                                                                    </span>
                                                                    <span class="drug-stock <?php echo $stock_text_class; ?>">
                                                                        <?php echo floatval($item['total_stock']); ?> <?php echo htmlspecialchars($item['unit_of_measure']); ?>
                                                                    </span>
                                                                </div>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <small id="stockInfoOpd" class="form-text text-muted">Select a medication to see stock availability</small>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-group">
                                                    <label for="priority">Priority</label>
                                                    <select class="form-control" id="priority" name="priority">
                                                        <option value="routine">Routine</option>
                                                        <option value="urgent">Urgent</option>
                                                        <option value="emergency">Emergency</option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="row">
                                            <div class="col-md-3">
                                                <div class="form-group">
                                                    <label for="dosage">Dosage *</label>
                                                    <input type="text" class="form-control" id="dosage" name="dosage" 
                                                           placeholder="e.g., 1 tab, 5ml" required>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="form-group">
                                                    <label for="frequency">Frequency *</label>
                                                    <select class="form-control" id="frequency" name="frequency" required>
                                                        <option value="">-- Select --</option>
                                                        <option value="Once daily">Once daily</option>
                                                        <option value="Twice daily">Twice daily</option>
                                                        <option value="Three times daily">Three times daily</option>
                                                        <option value="Four times daily">Four times daily</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="form-group">
                                                    <label for="duration">Duration *</label>
                                                    <input type="number" class="form-control" id="duration" name="duration" 
                                                           min="1" max="365" placeholder="e.g., 7" required>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="form-group">
                                                    <label for="duration_unit">Unit</label>
                                                    <select class="form-control" id="duration_unit" name="duration_unit" required>
                                                        <option value="days">Days</option>
                                                        <option value="weeks">Weeks</option>
                                                        <option value="months">Months</option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="quantity">Quantity *</label>
                                                    <input type="number" class="form-control" id="quantity" name="quantity" 
                                                           min="1" max="1000" value="1" required
                                                           onchange="validateQuantity('opd')">
                                                    <small class="form-text text-muted">Total number of units to dispense</small>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="instructions">Instructions</label>
                                                    <textarea class="form-control" id="instructions" name="instructions" 
                                                              rows="1" placeholder="Special instructions"></textarea>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="notes">Notes (Optional)</label>
                                            <textarea class="form-control" id="notes" name="notes" 
                                                      rows="2" placeholder="Additional notes..."></textarea>
                                        </div>
                                        
                                        <div class="row">
                                            <div class="col-md-12">
                                                <button type="submit" name="create_prescription" class="btn btn-success">
                                                    <i class="fas fa-save mr-2"></i>Create Prescription
                                                </button>
                                                <button type="reset" class="btn btn-secondary" onclick="resetStockInfo('opd')">
                                                    <i class="fas fa-times mr-2"></i>Clear Form
                                                </button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                                
                            <?php endif; ?>
                            
                            <!-- History Tab -->
                            <div class="tab-pane fade" id="history">
                                <?php if ($visit_type == 'IPD'): ?>
                                    <!-- IPD Order History -->
                                    <?php if (!empty($ipd_order_history)): ?>
                                        <div class="prescription-timeline">
                                            <?php foreach ($ipd_order_history as $order): ?>
                                            <div class="timeline-item">
                                                <div class="card <?php echo $order['status'] == 'stopped' ? 'order-stopped' : ''; ?>">
                                                    <div class="card-body">
                                                        <div class="row">
                                                            <div class="col-md-8">
                                                                <h6 class="mb-1"><?php echo htmlspecialchars($order['item_name']); ?></h6>
                                                                <p class="mb-1">
                                                                    <span class="badge badge-light"><?php echo htmlspecialchars($order['dose']); ?></span>
                                                                    <span class="badge badge-light"><?php echo htmlspecialchars($order['frequency']); ?></span>
                                                                    <span class="badge badge-light"><?php echo htmlspecialchars($order['route']); ?></span>
                                                                    <span class="badge badge-primary">Qty: <?php echo $order['initial_quantity'] ?? 1; ?></span>
                                                                </p>
                                                                <?php if ($order['stop_reason']): ?>
                                                                    <p class="mb-1 text-danger">
                                                                        <i class="fas fa-exclamation-circle mr-1"></i>
                                                                        Reason: <?php echo htmlspecialchars($order['stop_reason']); ?>
                                                                    </p>
                                                                <?php endif; ?>
                                                            </div>
                                                            <div class="col-md-4 text-right">
                                                                <?php 
                                                                $badge_class = $order['status'] == 'stopped' ? 'danger' : 'secondary';
                                                                echo '<span class="badge badge-' . $badge_class . '">' . strtoupper($order['status']) . '</span>';
                                                                ?>
                                                                <div class="mt-2">
                                                                    <small class="text-muted">
                                                                        <?php echo date('M j, Y H:i', strtotime($order['start_datetime'])); ?>
                                                                        <?php if ($order['end_datetime']): ?>
                                                                            <br>to <?php echo date('M j, Y H:i', strtotime($order['end_datetime'])); ?>
                                                                        <?php endif; ?>
                                                                    </small>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="text-center py-5">
                                            <i class="fas fa-history fa-3x text-muted mb-3"></i>
                                            <h5 class="text-muted">No History Available</h5>
                                            <p class="text-muted">Medication order history will appear here.</p>
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <!-- OPD Prescription History -->
                                    <div class="text-center py-5">
                                        <i class="fas fa-history fa-3x text-muted mb-3"></i>
                                        <h5 class="text-muted">Prescription History</h5>
                                        <p class="text-muted">All prescription history is shown in the main tab.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modals -->
<?php if ($visit_type == 'IPD'): ?>
    <!-- Change Order Modal -->
    <div class="modal fade" id="changeOrderModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header bg-warning text-white">
                    <h5 class="modal-title">Change Medication Order</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <form method="POST" id="changeOrderForm">
                    <div class="modal-body">
                        <input type="hidden" id="old_order_id" name="old_order_id">
                        
                        <div class="alert alert-info">
                            <h6>Changing: <span id="current_medication"></span></h6>
                            <p id="current_details" class="mb-0"></p>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-8">
                                <div class="form-group">
                                    <label for="new_item_id">New Medication *</label>
                                    <select class="form-control select2" id="new_item_id" name="new_item_id" required
                                            onchange="updateStockInfo(this.value, 'change')">
                                        <option value="">-- Select New Medication --</option>
                                        <?php foreach ($available_drugs as $item): 
                                            $stock_class = '';
                                            $stock_indicator = '';
                                            $stock_text_class = '';
                                            if ($item['total_stock'] == 0) {
                                                $stock_class = 'stock-danger';
                                                $stock_indicator = '<span class="stock-indicator stock-danger"></span>';
                                                $stock_text_class = 'stock-danger-text';
                                            } elseif ($item['total_stock'] < 10) {
                                                $stock_class = 'stock-warning';
                                                $stock_indicator = '<span class="stock-indicator stock-warning"></span>';
                                                $stock_text_class = 'stock-warning-text';
                                            } else {
                                                $stock_class = 'stock-good';
                                                $stock_indicator = '<span class="stock-indicator stock-good"></span>';
                                                $stock_text_class = 'stock-good-text';
                                            }
                                        ?>
                                            <option value="<?php echo $item['item_id']; ?>" 
                                                    data-stock="<?php echo $item['total_stock']; ?>"
                                                    data-unit="<?php echo htmlspecialchars($item['unit_of_measure']); ?>">
                                                <div class="drug-option">
                                                    <span class="drug-name">
                                                        <?php echo $stock_indicator; ?>
                                                        <?php echo htmlspecialchars($item['item_name']); ?> 
                                                        (<?php echo htmlspecialchars($item['item_code']); ?>)
                                                    </span>
                                                    <span class="drug-stock <?php echo $stock_text_class; ?>">
                                                        <?php echo floatval($item['total_stock']); ?> <?php echo htmlspecialchars($item['unit_of_measure']); ?>
                                                    </span>
                                                </div>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small id="stockInfoChange" class="form-text text-muted">Select a medication to see stock availability</small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="new_route">Route *</label>
                                    <select class="form-control" id="new_route" name="new_route" required>
                                        <option value="">-- Select Route --</option>
                                        <option value="PO">PO (Oral)</option>
                                        <option value="IV">IV (Intravenous)</option>
                                        <option value="IM">IM (Intramuscular)</option>
                                        <option value="SC">SC (Subcutaneous)</option>
                                        <option value="TOP">TOP (Topical)</option>
                                        <option value="INH">INH (Inhalation)</option>
                                        <option value="PR">PR (Rectal)</option>
                                        <option value="PV">PV (Vaginal)</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="new_dose">New Dose *</label>
                                    <input type="text" class="form-control" id="new_dose" name="new_dose" required>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="new_frequency">New Frequency *</label>
                                    <select class="form-control" id="new_frequency" name="new_frequency" required>
                                        <option value="">-- Select --</option>
                                        <option value="Once daily">Once daily</option>
                                        <option value="Twice daily">Twice daily</option>
                                        <option value="Three times daily">Three times daily</option>
                                        <option value="Four times daily">Four times daily</option>
                                        <option value="Every 4 hours">Every 4 hours</option>
                                        <option value="Every 6 hours">Every 6 hours</option>
                                        <option value="Every 8 hours">Every 8 hours</option>
                                        <option value="Every 12 hours">Every 12 hours</option>
                                        <option value="At bedtime">At bedtime</option>
                                        <option value="As needed">As needed</option>
                                        <option value="STAT">STAT (Immediately)</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="quantity">Quantity *</label>
                                    <input type="number" class="form-control" id="quantity" name="quantity" 
                                           min="1" max="1000" value="1" required
                                           onchange="validateQuantity('change')">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="new_duration_days">Duration (days)</label>
                                    <input type="number" class="form-control" id="new_duration_days" name="new_duration_days">
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="new_instructions">New Instructions</label>
                            <textarea class="form-control" id="new_instructions" name="new_instructions" rows="2"></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label for="change_reason">Reason for Change *</label>
                            <textarea class="form-control" id="change_reason" name="change_reason" rows="3" 
                                      placeholder="Please provide the reason for changing this medication..." required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" name="change_ipd_order" class="btn btn-warning">
                            <i class="fas fa-exchange-alt mr-2"></i>Change Medication
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Stop Order Modal -->
    <div class="modal fade" id="stopOrderModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">Stop Medication Order</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <form method="POST" id="stopOrderForm">
                    <div class="modal-body">
                        <input type="hidden" id="stop_order_id" name="order_id">
                        
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle mr-2"></i>
                            <strong>Warning:</strong> You are about to stop this medication order.
                        </div>
                        
                        <div class="form-group">
                            <label for="stop_reason">Reason for Stopping *</label>
                            <select class="form-control" id="stop_reason" name="stop_reason" required>
                                <option value="">-- Select Reason --</option>
                                <option value="Medication completed">Medication completed</option>
                                <option value="Side effects">Side effects</option>
                                <option value="No clinical response">No clinical response</option>
                                <option value="Patient request">Patient request</option>
                                <option value="Contraindication developed">Contraindication developed</option>
                                <option value="Drug interaction">Drug interaction</option>
                                <option value="Allergic reaction">Allergic reaction</option>
                                <option value="Other">Other (specify in notes)</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="stop_notes">Additional Notes</label>
                            <textarea class="form-control" id="stop_notes" name="stop_notes" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" name="stop_ipd_order" class="btn btn-danger">
                            <i class="fas fa-stop mr-2"></i>Stop Medication
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- JavaScript -->
<script src="/plugins/select2/js/select2.full.min.js"></script>
<script src="/plugins/jquery-validation/jquery.validate.min.js"></script>
<script src="/plugins/sweetalert2/sweetalert2.min.js"></script>
<script>
$(document).ready(function() {
    // Initialize Select2 with custom template
    function formatDrugOption(drug) {
        if (!drug.id) {
            return drug.text;
        }
        
        // Get the stock and unit from data attributes
        var $option = $(drug.element);
        var stock = $option.data('stock');
        var unit = $option.data('unit');
        var text = $option.text();
        
        // Determine stock class
        var stockClass = '';
        if (stock == 0) {
            stockClass = 'stock-danger-text';
        } else if (stock < 10) {
            stockClass = 'stock-warning-text';
        } else {
            stockClass = 'stock-good-text';
        }
        
        var $drug = $(
            '<div class="drug-option">' +
                '<span class="drug-name">' + text + '</span>' +
                '<span class="drug-stock ' + stockClass + '">' + stock + ' ' + unit + '</span>' +
            '</div>'
        );
        return $drug;
    }
    
    $('.select2').select2({
        theme: 'bootstrap4',
        placeholder: 'Select a medication',
        allowClear: true,
        templateResult: formatDrugOption,
        templateSelection: function(drug) {
            if (!drug.id) {
                return drug.text;
            }
            // For the selected item, show just the name
            return $(drug.element).text().split(' - ')[0];
        },
        escapeMarkup: function(markup) {
            return markup;
        }
    });
    
    // Tab handling
    const urlParams = new URLSearchParams(window.location.search);
    const tabParam = urlParams.get('tab');
    if (tabParam) {
        $(`a[href="#${tabParam}"]`).tab('show');
    }
    
    // Form validation
    $.validator.setDefaults({
        errorClass: 'is-invalid',
        validClass: 'is-valid',
        errorElement: 'div',
        errorPlacement: function(error, element) {
            error.addClass('invalid-feedback');
            element.after(error);
        },
        highlight: function(element, errorClass, validClass) {
            $(element).addClass(errorClass).removeClass(validClass);
        },
        unhighlight: function(element, errorClass, validClass) {
            $(element).removeClass(errorClass).addClass(validClass);
        }
    });

    $('#prescriptionForm').validate({
        rules: {
            item_id: { required: true },
            dosage: { required: true, minlength: 1 },
            frequency: { required: true },
            duration: { required: true, min: 1 },
            quantity: { 
                required: true, 
                min: 1,
                max: function() {
                    var selectedOption = $('#item_id_opd option:selected');
                    var stock = selectedOption.data('stock');
                    return stock !== undefined ? stock : 1000;
                }
            }
        },
        messages: {
            item_id: "Please select a medication",
            dosage: "Please enter dosage",
            frequency: "Please select frequency",
            duration: "Please enter duration",
            quantity: {
                required: "Please enter quantity",
                min: "Quantity must be at least 1",
                max: "Quantity exceeds available stock"
            }
        },
        submitHandler: function(form) {
            // Final stock check before submission
            var selectedOption = $('#item_id_opd option:selected');
            var stock = selectedOption.data('stock');
            var quantity = parseInt($('#quantity').val());
            
            if (stock !== undefined && quantity > stock) {
                Swal.fire({
                    icon: 'error',
                    title: 'Insufficient Stock',
                    text: 'Quantity (' + quantity + ') exceeds available stock (' + stock + ')',
                    confirmButtonText: 'OK'
                });
                return false;
            }
            
            // Show loading
            Swal.fire({
                title: 'Creating Prescription...',
                text: 'Please wait',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            return true;
        }
    });

    $('#ipdOrderForm').validate({
        rules: {
            item_id: { required: true },
            dose: { required: true, minlength: 1 },
            frequency: { required: true },
            route: { required: true },
            quantity: { 
                required: true, 
                min: 1,
                max: function() {
                    var selectedOption = $('#item_id option:selected');
                    var stock = selectedOption.data('stock');
                    return stock !== undefined ? stock : 1000;
                }
            }
        },
        messages: {
            item_id: "Please select a medication",
            dose: "Please enter dose",
            frequency: "Please select frequency",
            route: "Please select route",
            quantity: {
                required: "Please enter quantity",
                min: "Quantity must be at least 1",
                max: "Quantity exceeds available stock"
            }
        },
        submitHandler: function(form) {
            // Final stock check before submission
            var selectedOption = $('#item_id option:selected');
            var stock = selectedOption.data('stock');
            var quantity = parseInt($('#quantity').val());
            
            if (stock !== undefined && quantity > stock) {
                Swal.fire({
                    icon: 'error',
                    title: 'Insufficient Stock',
                    text: 'Quantity (' + quantity + ') exceeds available stock (' + stock + ')',
                    confirmButtonText: 'OK'
                });
                return false;
            }
            
            // Show loading
            Swal.fire({
                title: 'Creating Medication Order...',
                text: 'Please wait',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            return true;
        }
    });
    
    // Auto-close alerts
    setTimeout(function() {
        $('.alert').fadeOut('slow');
    }, 5000);
});

// Stock information functions
function updateStockInfo(itemId, formType = 'ipd') {
    var selectedOption = null;
    var quantityField = null;
    var stockInfoId = '';
    
    switch(formType) {
        case 'opd':
            selectedOption = $('#item_id_opd option:selected');
            quantityField = $('#quantity');
            stockInfoId = 'stockInfoOpd';
            break;
        case 'change':
            selectedOption = $('#new_item_id option:selected');
            quantityField = $('#quantity');
            stockInfoId = 'stockInfoChange';
            break;
        default: // ipd
            selectedOption = $('#item_id option:selected');
            quantityField = $('#quantity');
            stockInfoId = 'stockInfo';
            break;
    }
    
    if (!selectedOption || selectedOption.val() === '') {
        resetStockInfo(formType);
        return;
    }
    
    var stock = selectedOption.data('stock');
    var unit = selectedOption.data('unit');
    var $stockInfo = $('#' + stockInfoId);
    
    var stockText = 'Available stock: ' + stock;
    if (unit) {
        stockText += ' ' + unit;
    }
    
    $stockInfo.text(stockText);
    
    if (stock == 0) {
        $stockInfo.removeClass('text-muted text-warning text-success').addClass('text-danger');
        $stockInfo.html('<i class="fas fa-exclamation-triangle"></i> ' + stockText + ' - <strong>Out of stock!</strong>');
        // Disable submit button
        $('button[type="submit"]').prop('disabled', true).addClass('disabled');
    } else if (stock < 10) {
        $stockInfo.removeClass('text-muted text-danger text-success').addClass('text-warning');
        $stockInfo.html('<i class="fas fa-exclamation-circle"></i> ' + stockText + ' - Low stock');
        $('button[type="submit"]').prop('disabled', false).removeClass('disabled');
    } else {
        $stockInfo.removeClass('text-danger text-warning text-muted').addClass('text-success');
        $stockInfo.html('<i class="fas fa-check-circle"></i> ' + stockText + ' - In stock');
        $('button[type="submit"]').prop('disabled', false).removeClass('disabled');
    }
    
    // Update max attribute on quantity field
    if (quantityField.length) {
        quantityField.attr('max', stock);
        // Validate current quantity
        validateQuantity(formType);
    }
}

function validateQuantity(formType = 'ipd') {
    var quantityField = null;
    var selectedOption = null;
    
    switch(formType) {
        case 'opd':
            quantityField = $('#quantity');
            selectedOption = $('#item_id_opd option:selected');
            break;
        case 'change':
            quantityField = $('#quantity');
            selectedOption = $('#new_item_id option:selected');
            break;
        default: // ipd
            quantityField = $('#quantity');
            selectedOption = $('#item_id option:selected');
            break;
    }
    
    if (!quantityField.length || !selectedOption || selectedOption.val() === '') {
        return true;
    }
    
    var quantity = parseInt(quantityField.val());
    var stock = selectedOption.data('stock');
    
    if (stock !== undefined && quantity > stock) {
        quantityField.addClass('is-invalid');
        quantityField.next('.invalid-feedback').remove();
        quantityField.after('<div class="invalid-feedback">Quantity (' + quantity + ') exceeds available stock (' + stock + ')</div>');
        return false;
    } else {
        quantityField.removeClass('is-invalid');
        quantityField.next('.invalid-feedback').remove();
        return true;
    }
}

function resetStockInfo(formType = 'ipd') {
    var stockInfoId = '';
    switch(formType) {
        case 'opd':
            stockInfoId = 'stockInfoOpd';
            break;
        case 'change':
            stockInfoId = 'stockInfoChange';
            break;
        default: // ipd
            stockInfoId = 'stockInfo';
            break;
    }
    
    $('#' + stockInfoId).text('Select a medication to see stock availability').removeClass('text-danger text-warning text-success').addClass('text-muted');
    $('button[type="submit"]').prop('disabled', false).removeClass('disabled');
}

<?php if ($visit_type == 'IPD'): ?>
// Show Change Order Modal
function showChangeOrderModal(order) {
    $('#old_order_id').val(order.order_id);
    $('#current_medication').text(order.item_name);
    $('#current_details').html(`
        <strong>Current:</strong> ${order.dose} ${order.frequency} ${order.route}<br>
        <strong>Quantity:</strong> ${order.initial_quantity || 1}<br>
        <strong>Started:</strong> ${new Date(order.start_datetime).toLocaleString()}
    `);
    
    // Set current values as defaults
    $('#new_dose').val(order.dose);
    $('#new_frequency').val(order.frequency);
    $('#new_route').val(order.route);
    $('#quantity').val(order.initial_quantity || 1);
    $('#new_duration_days').val(order.duration_days);
    $('#new_instructions').val(order.instructions);
    
    // Initialize Select2 in modal
    $('#new_item_id').select2({
        theme: 'bootstrap4',
        placeholder: 'Select new medication',
        dropdownParent: $('#changeOrderModal')
    });
    
    $('#changeOrderModal').modal('show');
}

// Show Stop Order Modal
function showStopOrderModal(orderId, medicationName) {
    $('#stop_order_id').val(orderId);
    $('#stopOrderModal').modal('show');
}

// View Order Details
function viewOrderDetails(orderId) {
    window.location.href = `/clinic/doctor/medication_order_details.php?order_id=${orderId}`;
}
<?php else: ?>
// View Prescription Details
function viewPrescriptionDetails(prescriptionId) {
    window.location.href = `/clinic/doctor/prescription_details.php?prescription_id=${prescriptionId}`;
}
<?php endif; ?>

// Print functionality
function printPrescription() {
    window.print();
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + P for print
    if (e.ctrlKey && e.keyCode === 80) {
        e.preventDefault();
        printPrescription();
    }
    // Escape to close modals
    if (e.keyCode === 27) {
        $('.modal').modal('hide');
    }
});

// Initialize stock info for current selection
$(document).ready(function() {
    // Check if there's a selected item in OPD form
    var selectedOpd = $('#item_id_opd option:selected');
    if (selectedOpd.length && selectedOpd.val() !== '') {
        updateStockInfo(selectedOpd.val(), 'opd');
    }
    
    // Check if there's a selected item in IPD form
    var selectedIpd = $('#item_id option:selected');
    if (selectedIpd.length && selectedIpd.val() !== '') {
        updateStockInfo(selectedIpd.val(), 'ipd');
    }
});
</script>
</body>
</html>
<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
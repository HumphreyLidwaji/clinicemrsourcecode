<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/audit_functions.php'; // Added for logging

// AUDIT LOG: Initial page access
audit_log($mysqli, [
    'user_id'     => $_SESSION['user_id'] ?? null,
    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
    'action'      => 'PAGE_ACCESS',
    'module'      => 'Imaging Orders',
    'table_name'  => 'N/A',
    'entity_type' => 'page',
    'record_id'   => null,
    'patient_id'  => null,
    'visit_id'    => null,
    'description' => "Accessed imaging_orders.php",
    'status'      => 'ATTEMPT',
    'old_values'  => null,
    'new_values'  => null
]);

// Get visit_id from URL
$visit_id = intval($_GET['visit_id'] ?? 0);

if ($visit_id <= 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid visit ID";
    
    // AUDIT LOG: Invalid visit ID
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'ACCESS_DENIED',
        'module'      => 'Imaging Orders',
        'table_name'  => 'visits',
        'entity_type' => 'visit',
        'record_id'   => null,
        'patient_id'  => null,
        'visit_id'    => null,
        'description' => "Attempted to access imaging_orders.php with invalid visit ID: " . ($_GET['visit_id'] ?? 'empty'),
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

// Get visit and patient information from the visits table
$visit_sql = "SELECT v.*, p.* 
             FROM visits v 
             JOIN patients p ON v.patient_id = p.patient_id
             WHERE v.visit_id = ?";
$visit_stmt = $mysqli->prepare($visit_sql);
$visit_stmt->bind_param("i", $visit_id);
$visit_stmt->execute();
$visit_result = $visit_stmt->get_result();

if ($visit_result->num_rows > 0) {
    $visit_info = $visit_result->fetch_assoc();
    $patient_info = $visit_info;
    $visit_type = $visit_info['visit_type'];
    
    // AUDIT LOG: Retrieved visit information
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'RETRIEVE',
        'module'      => 'Imaging Orders',
        'table_name'  => 'visits',
        'entity_type' => 'visit',
        'record_id'   => $visit_id,
        'patient_id'  => $visit_info['patient_id'],
        'visit_id'    => $visit_id,
        'description' => "Retrieved visit information for imaging orders. Visit Type: " . $visit_type,
        'status'      => 'SUCCESS',
        'old_values'  => null,
        'new_values'  => null
    ]);
} else {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Visit not found";
    
    // AUDIT LOG: Visit not found
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'ACCESS_DENIED',
        'module'      => 'Imaging Orders',
        'table_name'  => 'visits',
        'entity_type' => 'visit',
        'record_id'   => $visit_id,
        'patient_id'  => null,
        'visit_id'    => $visit_id,
        'description' => "Visit not found for imaging orders. Visit ID: " . $visit_id,
        'status'      => 'FAILED',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    header("Location: /clinic/dashboard.php");
    exit;
}

// If it's an IPD visit, get additional admission information
if ($visit_type == 'IPD') {
    $admission_sql = "SELECT * FROM ipd_admissions WHERE visit_id = ?";
    $admission_stmt = $mysqli->prepare($admission_sql);
    $admission_stmt->bind_param("i", $visit_id);
    $admission_stmt->execute();
    $admission_result = $admission_stmt->get_result();
    
    if ($admission_result->num_rows > 0) {
        $admission_info = $admission_result->fetch_assoc();
        // Merge admission info with visit info
        $visit_info = array_merge($visit_info, $admission_info);
        
        // AUDIT LOG: Retrieved IPD admission information
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'RETRIEVE',
            'module'      => 'Imaging Orders',
            'table_name'  => 'ipd_admissions',
            'entity_type' => 'admission',
            'record_id'   => $admission_info['ipd_admission_id'] ?? null,
            'patient_id'  => $visit_info['patient_id'],
            'visit_id'    => $visit_id,
            'description' => "Retrieved IPD admission information for imaging orders",
            'status'      => 'SUCCESS',
            'old_values'  => null,
            'new_values'  => null
        ]);
    }
}

// Function to generate imaging order number
function generateImagingOrderNumber($mysqli) {
    $prefix = "IMG";
    $year = date('Y');
    $month = date('m');
    
    // Get last order number for this month
    $sql = "SELECT order_number FROM radiology_orders 
            WHERE order_number LIKE '{$prefix}-{$year}{$month}%' 
            ORDER BY radiology_order_id DESC LIMIT 1";
    $result = $mysqli->query($sql);
    
    if ($result->num_rows > 0) {
        $last_order = $result->fetch_assoc();
        $last_number = intval(substr($last_order['order_number'], -4));
        $new_number = str_pad($last_number + 1, 4, '0', STR_PAD_LEFT);
    } else {
        $new_number = '0001';
    }
    
    return "{$prefix}-{$year}{$month}{$new_number}";
}

// Function to check for existing pending bill
function getOrCreatePendingBill($mysqli, $visit_id, $patient_id, $user_id, $price_list_id = 1) {
    // AUDIT LOG: Starting pending bill check/creation
    audit_log($mysqli, [
        'user_id'     => $user_id,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'PENDING_BILL_CHECK_START',
        'module'      => 'Imaging Orders',
        'table_name'  => 'pending_bills',
        'entity_type' => 'pending_bill',
        'record_id'   => null,
        'patient_id'  => $patient_id,
        'visit_id'    => $visit_id,
        'description' => "Checking for existing pending bill or creating new one",
        'status'      => 'ATTEMPT',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    // First, check if there's an existing pending bill for this visit
    $check_sql = "SELECT pending_bill_id, bill_number FROM pending_bills 
                 WHERE visit_id = ? 
                 AND patient_id = ?
                 AND bill_status IN ('draft', 'pending')
                 AND is_finalized = 0
                 ORDER BY created_at DESC 
                 LIMIT 1";
    
    $check_stmt = $mysqli->prepare($check_sql);
    $check_stmt->bind_param("ii", $visit_id, $patient_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        // Use existing pending bill
        $existing_bill = $check_result->fetch_assoc();
        
        // AUDIT LOG: Found existing pending bill
        audit_log($mysqli, [
            'user_id'     => $user_id,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'PENDING_BILL_FOUND',
            'module'      => 'Imaging Orders',
            'table_name'  => 'pending_bills',
            'entity_type' => 'pending_bill',
            'record_id'   => $existing_bill['pending_bill_id'],
            'patient_id'  => $patient_id,
            'visit_id'    => $visit_id,
            'description' => "Found existing pending bill for imaging order. Bill #: " . $existing_bill['bill_number'],
            'status'      => 'SUCCESS',
            'old_values'  => null,
            'new_values'  => null
        ]);
        
        return [
            'pending_bill_id' => $existing_bill['pending_bill_id'],
            'bill_number' => $existing_bill['bill_number'],
            'is_new' => false
        ];
    } else {
        // Create new pending bill
        $bill_number = "BIL-" . date('Ymd-His') . "-" . rand(100, 999);
        
        $create_sql = "INSERT INTO pending_bills 
                      (bill_number, visit_id, patient_id, price_list_id,
                       subtotal_amount, discount_amount, tax_amount, total_amount,
                       bill_status, created_by, bill_date, pending_bill_number)
                      VALUES (?, ?, ?, ?, 0, 0, 0, 0, 'draft', ?, NOW(), ?)";
        
        $create_stmt = $mysqli->prepare($create_sql);
        $create_stmt->bind_param(
            "siiiis",
            $bill_number,
            $visit_id,
            $patient_id,
            $price_list_id,
            $user_id,
            $bill_number
        );
        
        if ($create_stmt->execute()) {
            $pending_bill_id = $mysqli->insert_id;
            
            // AUDIT LOG: Created new pending bill
            audit_log($mysqli, [
                'user_id'     => $user_id,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'PENDING_BILL_CREATE',
                'module'      => 'Imaging Orders',
                'table_name'  => 'pending_bills',
                'entity_type' => 'pending_bill',
                'record_id'   => $pending_bill_id,
                'patient_id'  => $patient_id,
                'visit_id'    => $visit_id,
                'description' => "Created new pending bill for imaging order. Bill #: " . $bill_number,
                'status'      => 'SUCCESS',
                'old_values'  => null,
                'new_values'  => [
                    'bill_number' => $bill_number,
                    'visit_id' => $visit_id,
                    'patient_id' => $patient_id,
                    'price_list_id' => $price_list_id,
                    'bill_status' => 'draft'
                ]
            ]);
            
            return [
                'pending_bill_id' => $pending_bill_id,
                'bill_number' => $bill_number,
                'is_new' => true
            ];
        } else {
            $error = "Failed to create pending bill: " . $mysqli->error;
            
            // AUDIT LOG: Failed to create pending bill
            audit_log($mysqli, [
                'user_id'     => $user_id,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'PENDING_BILL_CREATE',
                'module'      => 'Imaging Orders',
                'table_name'  => 'pending_bills',
                'entity_type' => 'pending_bill',
                'record_id'   => null,
                'patient_id'  => $patient_id,
                'visit_id'    => $visit_id,
                'description' => "Failed to create pending bill for imaging order. Error: " . $error,
                'status'      => 'FAILED',
                'old_values'  => null,
                'new_values'  => null
            ]);
            
            throw new Exception($error);
        }
    }
}

// Function to add item to pending bill
function addItemToPendingBill($mysqli, $pending_bill_id, $billable_item_id, $item_quantity = 1, 
                              $unit_price = 0, $source_type = null, $source_id = null, $user_id = null) {
    
    // AUDIT LOG: Starting to add item to pending bill
    audit_log($mysqli, [
        'user_id'     => $user_id,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'ADD_BILL_ITEM_START',
        'module'      => 'Imaging Orders',
        'table_name'  => 'pending_bill_items',
        'entity_type' => 'bill_item',
        'record_id'   => null,
        'patient_id'  => null,
        'visit_id'    => null,
        'description' => "Starting to add item to pending bill. Bill ID: " . $pending_bill_id . ", Item ID: " . $billable_item_id,
        'status'      => 'ATTEMPT',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    // Get billable item details
    $item_sql = "SELECT * FROM billable_items WHERE billable_item_id = ?";
    $item_stmt = $mysqli->prepare($item_sql);
    $item_stmt->bind_param("i", $billable_item_id);
    $item_stmt->execute();
    $item_result = $item_stmt->get_result();
    
    if ($item_result->num_rows === 0) {
        $error = "Billable item not found: ID " . $billable_item_id;
        
        // AUDIT LOG: Billable item not found
        audit_log($mysqli, [
            'user_id'     => $user_id,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'ADD_BILL_ITEM_FAIL',
            'module'      => 'Imaging Orders',
            'table_name'  => 'billable_items',
            'entity_type' => 'bill_item',
            'record_id'   => null,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Billable item not found for pending bill. Item ID: " . $billable_item_id,
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => null
        ]);
        
        throw new Exception($error);
    }
    
    $billable_item = $item_result->fetch_assoc();
    $item_name = $billable_item['item_name'] ?? 'Unknown Item';
    
    // Calculate amounts
    $subtotal = $unit_price * $item_quantity;
    $tax_amount = 0;
    
    if ($billable_item['is_taxable']) {
        $tax_rate = floatval($billable_item['tax_rate'] ?? 0);
        $tax_amount = $subtotal * ($tax_rate / 100);
    }
    
    $total_amount = $subtotal + $tax_amount;
    
    // Check if item already exists in this bill
    $check_item_sql = "SELECT pending_bill_item_id FROM pending_bill_items 
                      WHERE pending_bill_id = ? AND billable_item_id = ? 
                      AND is_cancelled = 0";
    $check_item_stmt = $mysqli->prepare($check_item_sql);
    $check_item_stmt->bind_param("ii", $pending_bill_id, $billable_item_id);
    $check_item_stmt->execute();
    $check_item_result = $check_item_stmt->get_result();
    
    if ($check_item_result->num_rows > 0) {
        // Update existing item quantity
        $existing_item = $check_item_result->fetch_assoc();
        
        // Get old values first
        $old_values_sql = "SELECT item_quantity, subtotal, tax_amount, total_amount 
                          FROM pending_bill_items 
                          WHERE pending_bill_item_id = ?";
        $old_values_stmt = $mysqli->prepare($old_values_sql);
        $old_values_stmt->bind_param("i", $existing_item['pending_bill_item_id']);
        $old_values_stmt->execute();
        $old_values_result = $old_values_stmt->get_result();
        $old_values = $old_values_result->fetch_assoc();
        
        $update_sql = "UPDATE pending_bill_items 
                      SET item_quantity = item_quantity + ?,
                          subtotal = subtotal + ?,
                          tax_amount = tax_amount + ?,
                          total_amount = total_amount + ?,
                          updated_at = NOW()
                      WHERE pending_bill_item_id = ?";
        
        $update_stmt = $mysqli->prepare($update_sql);
        $update_stmt->bind_param(
            "ddddi",
            $item_quantity,
            $subtotal,
            $tax_amount,
            $total_amount,
            $existing_item['pending_bill_item_id']
        );
        
        if (!$update_stmt->execute()) {
            $error = "Failed to update pending bill item: " . $mysqli->error;
            
            // AUDIT LOG: Failed to update bill item
            audit_log($mysqli, [
                'user_id'     => $user_id,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'UPDATE_BILL_ITEM_FAIL',
                'module'      => 'Imaging Orders',
                'table_name'  => 'pending_bill_items',
                'entity_type' => 'bill_item',
                'record_id'   => $existing_item['pending_bill_item_id'],
                'patient_id'  => null,
                'visit_id'    => null,
                'description' => "Failed to update existing pending bill item. Error: " . $error,
                'status'      => 'FAILED',
                'old_values'  => null,
                'new_values'  => null
            ]);
            
            throw new Exception($error);
        }
        
        // AUDIT LOG: Updated existing bill item
        audit_log($mysqli, [
            'user_id'     => $user_id,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'UPDATE_BILL_ITEM',
            'module'      => 'Imaging Orders',
            'table_name'  => 'pending_bill_items',
            'entity_type' => 'bill_item',
            'record_id'   => $existing_item['pending_bill_item_id'],
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Updated existing pending bill item quantity. Item: " . $item_name . ", Added Quantity: " . $item_quantity,
            'status'      => 'SUCCESS',
            'old_values'  => [
                'item_quantity' => $old_values['item_quantity'] ?? 0,
                'subtotal' => $old_values['subtotal'] ?? 0,
                'total_amount' => $old_values['total_amount'] ?? 0
            ],
            'new_values'  => [
                'item_quantity' => ($old_values['item_quantity'] ?? 0) + $item_quantity,
                'subtotal' => ($old_values['subtotal'] ?? 0) + $subtotal,
                'total_amount' => ($old_values['total_amount'] ?? 0) + $total_amount
            ]
        ]);
        
        return $existing_item['pending_bill_item_id'];
    } else {
        $discount_percentage = $billable_item['discount_percentage'] ?? 0;
        $discount_amount     = $billable_item['discount_amount'] ?? 0;

        // Create new bill item
        $insert_sql = "INSERT INTO pending_bill_items 
                      (pending_bill_id, billable_item_id, price_list_item_id,
                       item_quantity, unit_price, discount_percentage, discount_amount,
                       tax_percentage, subtotal, tax_amount, total_amount,
                       source_type, source_id, created_by)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $insert_stmt = $mysqli->prepare($insert_sql);
        $tax_percentage = $billable_item['is_taxable'] ? ($billable_item['tax_rate'] ?? 0) : 0;
        
        $insert_stmt->bind_param(
            "iiidddddddsssi",
            $pending_bill_id,
            $billable_item_id,
            $billable_item_id, // price_list_item_id
            $item_quantity,
            $unit_price,
            $discount_percentage,
            $discount_amount,
            $tax_percentage,
            $subtotal,
            $tax_amount,
            $total_amount,
            $source_type,
            $source_id,
            $user_id
        );
        
        if (!$insert_stmt->execute()) {
            $error = "Failed to create pending bill item: " . $mysqli->error;
            
            // AUDIT LOG: Failed to create bill item
            audit_log($mysqli, [
                'user_id'     => $user_id,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'CREATE_BILL_ITEM_FAIL',
                'module'      => 'Imaging Orders',
                'table_name'  => 'pending_bill_items',
                'entity_type' => 'bill_item',
                'record_id'   => null,
                'patient_id'  => null,
                'visit_id'    => null,
                'description' => "Failed to create pending bill item. Error: " . $error,
                'status'      => 'FAILED',
                'old_values'  => null,
                'new_values'  => null
            ]);
            
            throw new Exception($error);
        }
        
        $new_item_id = $mysqli->insert_id;
        
        // AUDIT LOG: Created new bill item
        audit_log($mysqli, [
            'user_id'     => $user_id,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'CREATE_BILL_ITEM',
            'module'      => 'Imaging Orders',
            'table_name'  => 'pending_bill_items',
            'entity_type' => 'bill_item',
            'record_id'   => $new_item_id,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Created new pending bill item. Item: " . $item_name . ", Quantity: " . $item_quantity . ", Unit Price: " . $unit_price,
            'status'      => 'SUCCESS',
            'old_values'  => null,
            'new_values'  => [
                'billable_item_id' => $billable_item_id,
                'item_name' => $item_name,
                'item_quantity' => $item_quantity,
                'unit_price' => $unit_price,
                'subtotal' => $subtotal,
                'tax_amount' => $tax_amount,
                'total_amount' => $total_amount,
                'source_type' => $source_type,
                'source_id' => $source_id
            ]
        ]);
        
        return $new_item_id;
    }
}

// Function to update pending bill totals
function updatePendingBillTotals($mysqli, $pending_bill_id) {
    // Get old totals first
    $old_totals_sql = "SELECT subtotal_amount, discount_amount, tax_amount, total_amount 
                      FROM pending_bills 
                      WHERE pending_bill_id = ?";
    $old_totals_stmt = $mysqli->prepare($old_totals_sql);
    $old_totals_stmt->bind_param("i", $pending_bill_id);
    $old_totals_stmt->execute();
    $old_totals_result = $old_totals_stmt->get_result();
    $old_totals = $old_totals_result->fetch_assoc();
    
    // Calculate new totals from all items
    $totals_sql = "SELECT 
                   COALESCE(SUM(subtotal), 0) as new_subtotal,
                   COALESCE(SUM(discount_amount), 0) as new_discount,
                   COALESCE(SUM(tax_amount), 0) as new_tax,
                   COALESCE(SUM(total_amount), 0) as new_total
                   FROM pending_bill_items 
                   WHERE pending_bill_id = ? 
                   AND is_cancelled = 0";
    
    $totals_stmt = $mysqli->prepare($totals_sql);
    $totals_stmt->bind_param("i", $pending_bill_id);
    $totals_stmt->execute();
    $totals_result = $totals_stmt->get_result();
    
    if ($totals_result->num_rows > 0) {
        $totals = $totals_result->fetch_assoc();
        
        $update_sql = "UPDATE pending_bills 
                      SET subtotal_amount = ?,
                          discount_amount = ?,
                          tax_amount = ?,
                          total_amount = ?,
                          updated_at = NOW()
                      WHERE pending_bill_id = ?";
        
        $update_stmt = $mysqli->prepare($update_sql);
        $update_stmt->bind_param(
            "ddddi",
            $totals['new_subtotal'],
            $totals['new_discount'],
            $totals['new_tax'],
            $totals['new_total'],
            $pending_bill_id
        );
        
        if (!$update_stmt->execute()) {
            throw new Exception("Failed to update pending bill totals: " . $mysqli->error);
        }
        
        // AUDIT LOG: Updated bill totals
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'UPDATE_BILL_TOTALS',
            'module'      => 'Imaging Orders',
            'table_name'  => 'pending_bills',
            'entity_type' => 'pending_bill',
            'record_id'   => $pending_bill_id,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Updated pending bill totals",
            'status'      => 'SUCCESS',
            'old_values'  => [
                'subtotal_amount' => $old_totals['subtotal_amount'] ?? 0,
                'discount_amount' => $old_totals['discount_amount'] ?? 0,
                'tax_amount' => $old_totals['tax_amount'] ?? 0,
                'total_amount' => $old_totals['total_amount'] ?? 0
            ],
            'new_values'  => [
                'subtotal_amount' => $totals['new_subtotal'],
                'discount_amount' => $totals['new_discount'],
                'tax_amount' => $totals['new_tax'],
                'total_amount' => $totals['new_total']
            ]
        ]);
    }
    
    return true;
}

// Handle form submission for creating imaging order
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // AUDIT LOG: Form submission received
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'FORM_SUBMIT',
        'module'      => 'Imaging Orders',
        'table_name'  => 'N/A',
        'entity_type' => 'form',
        'record_id'   => null,
        'patient_id'  => $patient_info['patient_id'],
        'visit_id'    => $visit_id,
        'description' => "Form submission received on imaging orders page. Action: " . (isset($_POST['create_imaging_order']) ? 'create_imaging_order' : (isset($_POST['cancel_imaging_order']) ? 'cancel_imaging_order' : 'search_studies')),
        'status'      => 'ATTEMPT',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    // Create new imaging order
    if (isset($_POST['create_imaging_order'])) {
        $order_number = generateImagingOrderNumber($mysqli);
        $order_priority = !empty($_POST['order_priority']) ? trim($_POST['order_priority']) : 'routine';
        $clinical_notes = !empty($_POST['clinical_notes']) ? trim($_POST['clinical_notes']) : null;
        $body_part = !empty($_POST['body_part']) ? trim($_POST['body_part']) : null;
        $order_type = !empty($_POST['order_type']) ? trim($_POST['order_type']) : null;
        $instructions = !empty($_POST['instructions']) ? trim($_POST['instructions']) : null;
        $selected_studies = isset($_POST['selected_studies']) ? $_POST['selected_studies'] : [];
        $contrast_required = isset($_POST['contrast_required']) ? 1 : 0;
        $contrast_type = !empty($_POST['contrast_type']) ? trim($_POST['contrast_type']) : null;
        
        // AUDIT LOG: Starting imaging order creation
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'],
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'CREATE_IMAGING_ORDER_START',
            'module'      => 'Imaging Orders',
            'table_name'  => 'radiology_orders',
            'entity_type' => 'imaging_order',
            'record_id'   => null,
            'patient_id'  => $patient_info['patient_id'],
            'visit_id'    => $visit_id,
            'description' => "Starting creation of imaging order #" . $order_number . " with " . count($selected_studies) . " studies selected",
            'status'      => 'ATTEMPT',
            'old_values'  => null,
            'new_values'  => null
        ]);
        
        // Start transaction
        $mysqli->begin_transaction();
        
        try {
            // Create imaging order
            $order_sql = "INSERT INTO radiology_orders 
                         (order_number, visit_id, patient_id, order_date, order_priority, 
                          clinical_notes, body_part, order_type, instructions, contrast_required,
                          contrast_type, ordered_by, created_by, order_status)
                         VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')";
            
            $order_stmt = $mysqli->prepare($order_sql);
            $order_stmt->bind_param(
                "siisssssisii",
                $order_number,
                $visit_id,
                $patient_info['patient_id'],
                $order_priority,
                $clinical_notes,
                $body_part,
                $order_type,
                $instructions,
                $contrast_required,
                $contrast_type,
                $_SESSION['user_id'],
                $_SESSION['user_id']
            );
            
            if (!$order_stmt->execute()) {
                $error = "Failed to create imaging order: " . $mysqli->error;
                throw new Exception($error);
            }
            
            $radiology_order_id = $mysqli->insert_id;
            
            // AUDIT LOG: Imaging order created
            audit_log($mysqli, [
                'user_id'     => $_SESSION['user_id'],
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'CREATE_IMAGING_ORDER',
                'module'      => 'Imaging Orders',
                'table_name'  => 'radiology_orders',
                'entity_type' => 'imaging_order',
                'record_id'   => $radiology_order_id,
                'patient_id'  => $patient_info['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Created imaging order #" . $order_number . ". Priority: " . $order_priority,
                'status'      => 'SUCCESS',
                'old_values'  => null,
                'new_values'  => [
                    'order_number' => $order_number,
                    'visit_id' => $visit_id,
                    'patient_id' => $patient_info['patient_id'],
                    'order_priority' => $order_priority,
                    'clinical_notes' => $clinical_notes,
                    'body_part' => $body_part,
                    'order_type' => $order_type,
                    'contrast_required' => $contrast_required,
                    'contrast_type' => $contrast_type,
                    'order_status' => 'Pending'
                ]
            ]);
            
            // Check for existing pending bill or create new one
            $bill_info = getOrCreatePendingBill(
                $mysqli,
                $visit_id,
                $patient_info['patient_id'],
                $_SESSION['user_id']
            );
            
            $pending_bill_id = $bill_info['pending_bill_id'];
            
            // Add selected studies
            if (!empty($selected_studies)) {
                foreach ($selected_studies as $imaging_id) {
                    $imaging_id = intval($imaging_id);
                    
                    // Get imaging details and billable item
                    $imaging_sql = "SELECT ri.*, bi.billable_item_id, bi.unit_price 
                                   FROM radiology_imagings ri
                                   LEFT JOIN billable_items bi ON bi.source_table = 'radiology_imagings' 
                                       AND bi.source_id = ri.imaging_id
                                   WHERE ri.imaging_id = ?";
                    $imaging_stmt = $mysqli->prepare($imaging_sql);
                    $imaging_stmt->bind_param("i", $imaging_id);
                    $imaging_stmt->execute();
                    $imaging_result = $imaging_stmt->get_result();
                    
                    if ($imaging_result->num_rows > 0) {
                        $imaging = $imaging_result->fetch_assoc();
                        $imaging_name = $imaging['imaging_name'] ?? 'Unknown Imaging';
                        
                        // Add study to order
                        $study_sql = "INSERT INTO radiology_order_studies 
                                     (radiology_order_id, imaging_id, status, created_at)
                                     VALUES (?, ?, 'pending', NOW())";
                        $study_stmt = $mysqli->prepare($study_sql);
                        $study_stmt->bind_param("ii", $radiology_order_id, $imaging_id);
                        
                        if (!$study_stmt->execute()) {
                            throw new Exception("Failed to add study to order: " . $mysqli->error);
                        }
                        
                        $study_id = $mysqli->insert_id;
                        
                        // AUDIT LOG: Added study to imaging order
                        audit_log($mysqli, [
                            'user_id'     => $_SESSION['user_id'],
                            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                            'action'      => 'ADD_STUDY_TO_ORDER',
                            'module'      => 'Imaging Orders',
                            'table_name'  => 'radiology_order_studies',
                            'entity_type' => 'study',
                            'record_id'   => $study_id,
                            'patient_id'  => $patient_info['patient_id'],
                            'visit_id'    => $visit_id,
                            'description' => "Added study to imaging order. Study: " . $imaging_name . ", Order #: " . $order_number,
                            'status'      => 'SUCCESS',
                            'old_values'  => null,
                            'new_values'  => [
                                'radiology_order_id' => $radiology_order_id,
                                'imaging_id' => $imaging_id,
                                'imaging_name' => $imaging_name,
                                'status' => 'pending'
                            ]
                        ]);
                        
                        // If billable item exists and has price, add to pending bill
                        if (!empty($imaging['billable_item_id']) && ($imaging['unit_price'] > 0 || $imaging['fee_amount'] > 0)) {
                            $unit_price = floatval($imaging['unit_price'] ?? $imaging['fee_amount'] ?? 0);
                            
                            // Add to pending bill items
                            addItemToPendingBill(
                                $mysqli,
                                $pending_bill_id,
                                $imaging['billable_item_id'],
                                1, // quantity
                                $unit_price,
                                'imaging_order', // source_type
                                $radiology_order_id, // source_id
                                $_SESSION['user_id']
                            );
                        }
                        
                        // If contrast is required and has separate charge, add contrast item
                        if ($contrast_required && $contrast_type) {
                            // Look for contrast billable item
                            $contrast_sql = "SELECT billable_item_id, unit_price 
                                            FROM billable_items 
                                            WHERE item_type = 'service' 
                                            AND item_name LIKE '%contrast%' 
                                            AND item_status = 'active'
                                            LIMIT 1";
                            $contrast_result = $mysqli->query($contrast_sql);
                            
                            if ($contrast_result->num_rows > 0) {
                                $contrast_item = $contrast_result->fetch_assoc();
                                $contrast_item_name = $contrast_item['item_name'] ?? 'Contrast Media';
                                
                                addItemToPendingBill(
                                    $mysqli,
                                    $pending_bill_id,
                                    $contrast_item['billable_item_id'],
                                    1,
                                    $contrast_item['unit_price'],
                                    'imaging_order_contrast',
                                    $radiology_order_id,
                                    $_SESSION['user_id']
                                );
                                
                                // AUDIT LOG: Added contrast to imaging order
                                audit_log($mysqli, [
                                    'user_id'     => $_SESSION['user_id'],
                                    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                                    'action'      => 'ADD_CONTRAST_TO_ORDER',
                                    'module'      => 'Imaging Orders',
                                    'table_name'  => 'pending_bill_items',
                                    'entity_type' => 'contrast_item',
                                    'record_id'   => null,
                                    'patient_id'  => $patient_info['patient_id'],
                                    'visit_id'    => $visit_id,
                                    'description' => "Added contrast media to imaging order. Type: " . $contrast_type . ", Order #: " . $order_number,
                                    'status'      => 'SUCCESS',
                                    'old_values'  => null,
                                    'new_values'  => [
                                        'contrast_type' => $contrast_type,
                                        'item_name' => $contrast_item_name,
                                        'unit_price' => $contrast_item['unit_price']
                                    ]
                                ]);
                            }
                        }
                    }
                }
                
                // Update pending bill totals
                updatePendingBillTotals($mysqli, $pending_bill_id);
            }
            
            // Update imaging order with bill info
            $update_order_sql = "UPDATE radiology_orders 
                                SET invoice_id = ?, 
                                    is_billed = CASE WHEN ? > 0 THEN 1 ELSE 0 END
                                WHERE radiology_order_id = ?";
            
            $update_order_stmt = $mysqli->prepare($update_order_sql);
            $update_order_stmt->bind_param("iii", $pending_bill_id, $pending_bill_id, $radiology_order_id);
            $update_order_stmt->execute();
            
            // Commit transaction
            $mysqli->commit();
            
            $message = "Imaging order #$order_number created successfully";
            if ($bill_info['is_new']) {
                $message .= " with new pending bill #" . $bill_info['bill_number'];
            } else {
                $message .= " and added to existing pending bill";
            }
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = $message;
            
            // AUDIT LOG: Imaging order creation completed successfully
            audit_log($mysqli, [
                'user_id'     => $_SESSION['user_id'],
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'CREATE_IMAGING_ORDER_COMPLETE',
                'module'      => 'Imaging Orders',
                'table_name'  => 'radiology_orders',
                'entity_type' => 'imaging_order',
                'record_id'   => $radiology_order_id,
                'patient_id'  => $patient_info['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Imaging order creation completed successfully. Order #: " . $order_number . ", Studies: " . count($selected_studies),
                'status'      => 'SUCCESS',
                'old_values'  => null,
                'new_values'  => null
            ]);
            
            header("Location: imaging_orders.php?visit_id=" . $visit_id);
            exit;
            
        } catch (Exception $e) {
            $mysqli->rollback();
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error: " . $e->getMessage();
            
            // AUDIT LOG: Imaging order creation failed
            audit_log($mysqli, [
                'user_id'     => $_SESSION['user_id'],
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'CREATE_IMAGING_ORDER_FAIL',
                'module'      => 'Imaging Orders',
                'table_name'  => 'radiology_orders',
                'entity_type' => 'imaging_order',
                'record_id'   => null,
                'patient_id'  => $patient_info['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Failed to create imaging order. Error: " . $e->getMessage(),
                'status'      => 'FAILED',
                'old_values'  => null,
                'new_values'  => null
            ]);
        }
    }
    
    // Handle cancel imaging order
    if (isset($_POST['cancel_imaging_order'])) {
        $radiology_order_id = intval($_POST['radiology_order_id']);
        
        // AUDIT LOG: Starting imaging order cancellation
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'],
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'CANCEL_IMAGING_ORDER_START',
            'module'      => 'Imaging Orders',
            'table_name'  => 'radiology_orders',
            'entity_type' => 'imaging_order',
            'record_id'   => $radiology_order_id,
            'patient_id'  => $patient_info['patient_id'],
            'visit_id'    => $visit_id,
            'description' => "Starting cancellation of imaging order ID: " . $radiology_order_id,
            'status'      => 'ATTEMPT',
            'old_values'  => null,
            'new_values'  => null
        ]);
        
        // Start transaction
        $mysqli->begin_transaction();
        
        try {
            // Get pending bill ID from imaging order
            $get_bill_sql = "SELECT invoice_id, order_number, order_status FROM radiology_orders WHERE radiology_order_id = ?";
            $get_bill_stmt = $mysqli->prepare($get_bill_sql);
            $get_bill_stmt->bind_param("i", $radiology_order_id);
            $get_bill_stmt->execute();
            $get_bill_result = $get_bill_stmt->get_result();
            
            $pending_bill_id = null;
            $order_number = null;
            $old_status = null;
            
            if ($get_bill_result->num_rows > 0) {
                $order_data = $get_bill_result->fetch_assoc();
                $pending_bill_id = $order_data['invoice_id'];
                $order_number = $order_data['order_number'];
                $old_status = $order_data['order_status'];
            }
            
            // Cancel imaging order
            $cancel_sql = "UPDATE radiology_orders 
                          SET order_status = 'Cancelled',
                              updated_at = NOW()
                          WHERE radiology_order_id = ?";
            $cancel_stmt = $mysqli->prepare($cancel_sql);
            $cancel_stmt->bind_param("i", $radiology_order_id);
            
            if (!$cancel_stmt->execute()) {
                throw new Exception("Failed to cancel imaging order: " . $mysqli->error);
            }
            
            // AUDIT LOG: Imaging order cancelled
            audit_log($mysqli, [
                'user_id'     => $_SESSION['user_id'],
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'CANCEL_IMAGING_ORDER',
                'module'      => 'Imaging Orders',
                'table_name'  => 'radiology_orders',
                'entity_type' => 'imaging_order',
                'record_id'   => $radiology_order_id,
                'patient_id'  => $patient_info['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Cancelled imaging order. Order #: " . $order_number,
                'status'      => 'SUCCESS',
                'old_values'  => [
                    'order_status' => $old_status
                ],
                'new_values'  => [
                    'order_status' => 'Cancelled'
                ]
            ]);
            
            // If there's an associated pending bill, cancel the bill items too
            if ($pending_bill_id) {
                // Cancel pending bill items associated with this imaging order
                $cancel_items_sql = "UPDATE pending_bill_items 
                                    SET is_cancelled = 1,
                                        cancelled_at = NOW(),
                                        cancelled_by = ?,
                                        cancellation_reason = 'Imaging order cancelled'
                                    WHERE pending_bill_id = ? 
                                    AND (source_type = 'imaging_order' OR source_type = 'imaging_order_contrast')
                                    AND source_id = ?";
                
                $cancel_items_stmt = $mysqli->prepare($cancel_items_sql);
                $cancel_items_stmt->bind_param("iii", $_SESSION['user_id'], $pending_bill_id, $radiology_order_id);
                $cancel_items_stmt->execute();
                
                // Get count of cancelled items
                $affected_items = $cancel_items_stmt->affected_rows;
                
                // AUDIT LOG: Cancelled bill items
                audit_log($mysqli, [
                    'user_id'     => $_SESSION['user_id'],
                    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                    'action'      => 'CANCEL_BILL_ITEMS',
                    'module'      => 'Imaging Orders',
                    'table_name'  => 'pending_bill_items',
                    'entity_type' => 'bill_items',
                    'record_id'   => null,
                    'patient_id'  => $patient_info['patient_id'],
                    'visit_id'    => $visit_id,
                    'description' => "Cancelled " . $affected_items . " bill items associated with imaging order #" . $order_number,
                    'status'      => 'SUCCESS',
                    'old_values'  => null,
                    'new_values'  => null
                ]);
                
                // Update pending bill totals
                updatePendingBillTotals($mysqli, $pending_bill_id);
            }
            
            // Commit transaction
            $mysqli->commit();
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Imaging order cancelled successfully";
            
            // AUDIT LOG: Imaging order cancellation completed
            audit_log($mysqli, [
                'user_id'     => $_SESSION['user_id'],
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'CANCEL_IMAGING_ORDER_COMPLETE',
                'module'      => 'Imaging Orders',
                'table_name'  => 'radiology_orders',
                'entity_type' => 'imaging_order',
                'record_id'   => $radiology_order_id,
                'patient_id'  => $patient_info['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Imaging order cancellation completed successfully. Order #: " . $order_number,
                'status'      => 'SUCCESS',
                'old_values'  => null,
                'new_values'  => null
            ]);
            
            header("Location: imaging_orders.php?visit_id=" . $visit_id);
            exit;
            
        } catch (Exception $e) {
            $mysqli->rollback();
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error cancelling imaging order: " . $e->getMessage();
            
            // AUDIT LOG: Imaging order cancellation failed
            audit_log($mysqli, [
                'user_id'     => $_SESSION['user_id'],
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'CANCEL_IMAGING_ORDER_FAIL',
                'module'      => 'Imaging Orders',
                'table_name'  => 'radiology_orders',
                'entity_type' => 'imaging_order',
                'record_id'   => $radiology_order_id,
                'patient_id'  => $patient_info['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Failed to cancel imaging order. Error: " . $e->getMessage(),
                'status'      => 'FAILED',
                'old_values'  => null,
                'new_values'  => null
            ]);
        }
    }
    
    // Handle search for imaging studies
    if (isset($_POST['search_studies'])) {
        $search_term = !empty($_POST['search_term']) ? trim($_POST['search_term']) : '';
        $search_modality = !empty($_POST['search_modality']) ? trim($_POST['search_modality']) : '';
        
        // AUDIT LOG: Search for imaging studies
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'],
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'SEARCH_IMAGING_STUDIES',
            'module'      => 'Imaging Orders',
            'table_name'  => 'radiology_imagings',
            'entity_type' => 'search',
            'record_id'   => null,
            'patient_id'  => $patient_info['patient_id'],
            'visit_id'    => $visit_id,
            'description' => "Searched for imaging studies. Term: '" . $search_term . "', Modality: '" . $search_modality . "'",
            'status'      => 'SUCCESS',
            'old_values'  => null,
            'new_values'  => null
        ]);
    }
}

// Get existing imaging orders for this visit
$imaging_orders_sql = "SELECT ro.*, 
                       GROUP_CONCAT(DISTINCT ri.imaging_name SEPARATOR ', ') as study_names,
                       COUNT(DISTINCT ros.radiology_order_study_id) as study_count,
                       u.user_name as doctor_name,
                       rdoc.user_name as radiologist_name,
                       pb.bill_number,
                       pb.total_amount as bill_total,
                       pb.bill_status as bill_status
                       FROM radiology_orders ro
                       LEFT JOIN radiology_order_studies ros ON ro.radiology_order_id = ros.radiology_order_id
                       LEFT JOIN radiology_imagings ri ON ros.imaging_id = ri.imaging_id
                       LEFT JOIN users u ON ro.ordered_by = u.user_id
                       LEFT JOIN users rdoc ON ro.radiologist_id = rdoc.user_id
                       LEFT JOIN pending_bills pb ON ro.invoice_id = pb.pending_bill_id
                       WHERE ro.visit_id = ? 
                       AND ro.patient_id = ?
                       GROUP BY ro.radiology_order_id
                       ORDER BY ro.order_date DESC";
$imaging_orders_stmt = $mysqli->prepare($imaging_orders_sql);
$imaging_orders_stmt->bind_param("ii", $visit_id, $patient_info['patient_id']);
$imaging_orders_stmt->execute();
$imaging_orders_result = $imaging_orders_stmt->get_result();
$imaging_orders = $imaging_orders_result->fetch_all(MYSQLI_ASSOC);

// AUDIT LOG: Retrieved existing imaging orders
audit_log($mysqli, [
    'user_id'     => $_SESSION['user_id'] ?? null,
    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
    'action'      => 'RETRIEVE_IMAGING_ORDERS',
    'module'      => 'Imaging Orders',
    'table_name'  => 'radiology_orders',
    'entity_type' => 'orders_list',
    'record_id'   => null,
    'patient_id'  => $patient_info['patient_id'],
    'visit_id'    => $visit_id,
    'description' => "Retrieved " . count($imaging_orders) . " existing imaging orders for patient",
    'status'      => 'SUCCESS',
    'old_values'  => null,
    'new_values'  => null
]);

// Get order details with studies
$imaging_orders_with_studies = [];
foreach ($imaging_orders as $order) {
    $order_id = $order['radiology_order_id'];
    
    $studies_sql = "SELECT ri.*, ros.*, 
                   bi.billable_item_id, bi.unit_price as actual_price,
                   u.user_name as performed_by_name,
                   rdoc.user_name as radiologist_name,
                   pbi.subtotal as billed_amount
                   FROM radiology_order_studies ros
                   JOIN radiology_imagings ri ON ros.imaging_id = ri.imaging_id
                   LEFT JOIN billable_items bi ON bi.source_table = 'radiology_imagings' 
                       AND bi.source_id = ri.imaging_id
                   LEFT JOIN pending_bill_items pbi ON (pbi.source_type = 'imaging_order' 
                       OR pbi.source_type = 'imaging_order_contrast')
                       AND pbi.source_id = ros.radiology_order_id 
                       AND pbi.billable_item_id = bi.billable_item_id
                       AND pbi.is_cancelled = 0
                   LEFT JOIN users u ON ros.performed_by = u.user_id
                   LEFT JOIN users rdoc ON ros.performed_by = rdoc.user_id
                   WHERE ros.radiology_order_id = ?
                   ORDER BY ri.imaging_name";
    $studies_stmt = $mysqli->prepare($studies_sql);
    $studies_stmt->bind_param("i", $order_id);
    $studies_stmt->execute();
    $studies_result = $studies_stmt->get_result();
    $studies = $studies_result->fetch_all(MYSQLI_ASSOC);
    
    $imaging_orders_with_studies[$order_id] = [
        'order_info' => $order,
        'studies' => $studies
    ];
}

// Search imaging studies if requested
$studies = [];
if (isset($_POST['search_studies']) && !empty($search_term)) {
    $study_search_sql = "SELECT ri.*, bi.billable_item_id, bi.unit_price as actual_price,
                        bi.item_name as billable_item_name
                        FROM radiology_imagings ri
                        LEFT JOIN billable_items bi ON bi.source_table = 'radiology_imagings' 
                            AND bi.source_id = ri.imaging_id
                        WHERE ri.is_active = 1 
                        AND bi.billable_item_id IS NOT NULL";
    
    $params = [];
    $types = "";
    
    if (!empty($search_term)) {
        $study_search_sql .= " AND (ri.imaging_name LIKE ? OR ri.imaging_code LIKE ? OR bi.item_name LIKE ?)";
        $search_param = "%" . $search_term . "%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= "sss";
    }
    
    if (!empty($search_modality)) {
        $study_search_sql .= " AND ri.modality = ?";
        $params[] = $search_modality;
        $types .= "s";
    }
    
    $study_search_sql .= " ORDER BY ri.modality, ri.imaging_name LIMIT 50";
    
    $study_search_stmt = $mysqli->prepare($study_search_sql);
    
    if (!empty($params)) {
        $study_search_stmt->bind_param($types, ...$params);
    }
    
    $study_search_stmt->execute();
    $studies_result = $study_search_stmt->get_result();
    $studies = $studies_result->fetch_all(MYSQLI_ASSOC);
    
    // AUDIT LOG: Search results for imaging studies
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'SEARCH_RESULTS',
        'module'      => 'Imaging Orders',
        'table_name'  => 'radiology_imagings',
        'entity_type' => 'search_results',
        'record_id'   => null,
        'patient_id'  => $patient_info['patient_id'],
        'visit_id'    => $visit_id,
        'description' => "Search for imaging studies returned " . count($studies) . " results",
        'status'      => 'SUCCESS',
        'old_values'  => null,
        'new_values'  => null
    ]);
}

// Get modalities for filter
$modalities = [
    'X-Ray', 'CT', 'MRI', 'Ultrasound', 'Mammography', 'Fluoroscopy', 'Nuclear', 'Other'
];

// Get patient full name (using correct field names from your database)
$full_name = trim($patient_info['first_name'] . 
                 (!empty($patient_info['middle_name']) ? ' ' . $patient_info['middle_name'] : '') . 
                 ' ' . $patient_info['last_name']);

// Calculate age
$age = '';
if (!empty($patient_info['date_of_birth'])) {
    $birthDate = new DateTime($patient_info['date_of_birth']);
    $today = new DateTime();
    $age = $today->diff($birthDate)->y . ' years';
}

// Get visit number (using correct field from visits table)
$visit_number = $visit_info['visit_number'];

// For IPD visits, also show admission number
if ($visit_type == 'IPD' && isset($visit_info['admission_number'])) {
    $visit_number .= ' / ' . $visit_info['admission_number'];
}

// Function to get imaging order status badge
function getImagingOrderStatusBadge($status) {
    $badge_class = '';
    
    switch($status) {
        case 'Pending':
            $badge_class = 'warning';
            break;
        case 'Scheduled':
            $badge_class = 'info';
            break;
        case 'In Progress':
            $badge_class = 'primary';
            break;
        case 'Completed':
            $badge_class = 'success';
            break;
        case 'Cancelled':
            $badge_class = 'danger';
            break;
        default:
            $badge_class = 'light';
    }
    
    return '<span class="badge badge-' . $badge_class . '">' . htmlspecialchars($status) . '</span>';
}

// Function to get bill status badge
function getBillStatusBadge($status) {
    $badge_class = '';
    
    switch($status) {
        case 'draft':
            $badge_class = 'secondary';
            break;
        case 'pending':
            $badge_class = 'warning';
            break;
        case 'approved':
            $badge_class = 'success';
            break;
        case 'cancelled':
            $badge_class = 'danger';
            break;
        default:
            $badge_class = 'light';
    }
    
    return '<span class="badge badge-' . $badge_class . '">' . htmlspecialchars($status) . '</span>';
}

// Function to get modality badge
function getModalityBadge($modality) {
    $badge_class = '';
    
    switch($modality) {
        case 'X-Ray':
            $badge_class = 'primary';
            break;
        case 'CT':
            $badge_class = 'info';
            break;
        case 'MRI':
            $badge_class = 'success';
            break;
        case 'Ultrasound':
            $badge_class = 'warning';
            break;
        case 'Mammography':
            $badge_class = 'danger';
            break;
        case 'Fluoroscopy':
            $badge_class = 'secondary';
            break;
        case 'Nuclear':
            $badge_class = 'dark';
            break;
        default:
            $badge_class = 'light';
    }
    
    return '<span class="badge badge-' . $badge_class . '">' . htmlspecialchars($modality) . '</span>';
}

// Function to get priority badge
function getPriorityBadge($priority) {
    $badge_class = '';
    
    switch($priority) {
        case 'routine':
            $badge_class = 'secondary';
            break;
        case 'urgent':
            $badge_class = 'warning';
            break;
        case 'stat':
            $badge_class = 'danger';
            break;
        default:
            $badge_class = 'light';
    }
    
    return '<span class="badge badge-' . $badge_class . '">' . strtoupper($priority) . '</span>';
}

?>
<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0">
            <i class="fas fa-fw fa-x-ray mr-2"></i>Imaging Orders: <?php echo htmlspecialchars($patient_info['patient_mrn']); ?>
            <span class="badge badge-light ml-2"><?php echo count($imaging_orders); ?> Orders</span>
        </h3>
        <div class="card-tools">
            <div class="btn-group">
                <button type="button" class="btn btn-light" onclick="window.history.back()">
                    <i class="fas fa-arrow-left mr-2"></i>Back
                </button>
                <button type="button" class="btn btn-success" onclick="window.print()">
                    <i class="fas fa-print mr-2"></i>Print
                </button>
                <a href="/clinic/radiology/index.php?visit_id=<?php echo $visit_id; ?>" class="btn btn-info" target="_blank">
                    <i class="fas fa-file-medical-alt mr-2"></i>Radiology Dashboard
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
                                                <td><strong><?php echo htmlspecialchars($full_name); ?></strong></td>
                                            </tr>
                                            <tr>
                                                <th class="text-muted">MRN:</th>
                                                <td><span class="badge badge-info"><?php echo htmlspecialchars($patient_info['patient_mrn']); ?></span></td>
                                            </tr>
                                            <tr>
                                                <th class="text-muted">Age:</th>
                                                <td><span class="badge badge-secondary"><?php echo $age ?: 'N/A'; ?></span></td>
                                            </tr>
                                            <tr>
                                                <th class="text-muted">Sex:</th>
                                                <td>
                                                    <?php 
                                                    $sex_map = ['M' => 'Male', 'F' => 'Female', 'I' => 'Intersex'];
                                                    $sex_text = isset($sex_map[$patient_info['sex']]) ? $sex_map[$patient_info['sex']] : $patient_info['sex'];
                                                    echo htmlspecialchars($sex_text);
                                                    ?>
                                                </td>
                                            </tr>
                                        </table>
                                    </div>
                                    <div class="col-md-6">
                                        <table class="table table-sm table-borderless mb-0">
                                            <tr>
                                                <th width="40%" class="text-muted">Visit Type:</th>
                                                <td>
                                                    <span class="badge badge-<?php 
                                                        echo $visit_type == 'OPD' ? 'primary' : 
                                                             ($visit_type == 'IPD' ? 'success' : 'danger'); 
                                                    ?>">
                                                        <?php echo htmlspecialchars($visit_type); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th class="text-muted">Visit #:</th>
                                                <td><?php echo htmlspecialchars($visit_number); ?></td>
                                            </tr>
                                            <tr>
                                                <th class="text-muted">Blood Group:</th>
                                                <td>
                                                    <?php 
                                                    $blood_group = !empty($patient_info['blood_group']) ? 
                                                                htmlspecialchars($patient_info['blood_group']) : 
                                                                '<span class="text-muted">Not recorded</span>';
                                                    echo $blood_group;
                                                    ?>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th class="text-muted">Visit Status:</th>
                                                <td>
                                                    <?php 
                                                    $visit_status = $visit_info['visit_status'] ?? 'ACTIVE';
                                                    $status_badge_class = $visit_status == 'ACTIVE' ? 'success' : 
                                                                        ($visit_status == 'CLOSED' ? 'secondary' : 'danger');
                                                    ?>
                                                    <span class="badge badge-<?php echo $status_badge_class; ?>">
                                                        <?php echo htmlspecialchars($visit_status); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 text-right">
                                <div class="mt-2">
                                    <?php 
                                    $total_studies = 0;
                                    $total_orders = count($imaging_orders);
                                    $pending_orders = 0;
                                    $total_billed = 0;
                                    
                                    foreach ($imaging_orders as $order) {
                                        if ($order['order_status'] == 'Pending') {
                                            $pending_orders++;
                                        }
                                        $total_studies += intval($order['study_count']);
                                        $total_billed += floatval($order['bill_total'] ?? 0);
                                    }
                                    ?>
                                    <span class="h5">
                                        <i class="fas fa-x-ray text-primary mr-1"></i>
                                        <span class="badge badge-light"><?php echo $total_orders; ?> Orders</span>
                                    </span>
                                    <br>
                                    <span class="h5">
                                        <i class="fas fa-file-image text-success mr-1"></i>
                                        <span class="badge badge-light"><?php echo $total_studies; ?> Studies</span>
                                    </span>
                                    <br>
                                    <span class="h5">
                                        <i class="fas fa-money-bill text-warning mr-1"></i>
                                        <span class="badge badge-light">$<?php echo number_format($total_billed, 2); ?></span>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Study Search and Order Form -->
            <div class="col-md-5">
                <div class="card">
                    <div class="card-header bg-success py-2">
                        <h4 class="card-title mb-0 text-white">
                            <i class="fas fa-search mr-2"></i>Search and Order Imaging Studies
                        </h4>
                    </div>
                    <div class="card-body">
                        <!-- Order Type Selection -->
                        <div class="form-group">
                            <label for="order_type">Imaging Type</label>
                            <select class="form-control" id="order_type" name="order_type">
                                <option value="">-- Select Type --</option>
                                <option value="X-Ray">X-Ray</option>
                                <option value="CT Scan">CT Scan</option>
                                <option value="MRI">MRI</option>
                                <option value="Ultrasound">Ultrasound</option>
                                <option value="Mammography">Mammography</option>
                                <option value="Fluoroscopy">Fluoroscopy</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>

                        <!-- Study Search Form -->
                        <form method="POST" id="studySearchForm">
                            <div class="row">
                                <div class="col-md-8">
                                    <div class="form-group">
                                        <label for="search_term">Search Studies</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control" id="search_term" name="search_term" 
                                                   placeholder="Search by study name or code..."
                                                   value="<?php echo isset($search_term) ? htmlspecialchars($search_term) : ''; ?>">
                                            <div class="input-group-append">
                                                <button type="submit" name="search_studies" class="btn btn-primary">
                                                    <i class="fas fa-search"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="search_modality">Modality</label>
                                        <select class="form-control" id="search_modality" name="search_modality">
                                            <option value="">All Modalities</option>
                                            <?php foreach ($modalities as $modality): ?>
                                                <option value="<?php echo htmlspecialchars($modality); ?>"
                                                    <?php echo (isset($search_modality) && $search_modality == $modality) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($modality); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </form>

                        <!-- Study Search Results -->
                        <?php if (isset($_POST['search_studies']) && !empty($studies)): ?>
                            <div class="mt-3">
                                <h6 class="text-muted">Search Results (<?php echo count($studies); ?> found):</h6>
                                <div style="max-height: 300px; overflow-y: auto;">
                                    <form method="POST" id="studySelectionForm">
                                        <table class="table table-sm table-hover">
                                            <thead class="bg-light">
                                                <tr>
                                                    <th width="5%"></th>
                                                    <th>Study Name</th>
                                                    <th width="20%">Modality</th>
                                                    <th width="15%" class="text-right">Price</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($studies as $study): ?>
                                                    <tr>
                                                        <td>
                                                            <input type="checkbox" name="selected_studies[]" 
                                                                   value="<?php echo $study['imaging_id']; ?>"
                                                                   class="study-checkbox"
                                                                   data-price="<?php echo $study['actual_price'] ?? $study['fee_amount'] ?? 0; ?>"
                                                                   data-name="<?php echo htmlspecialchars($study['imaging_name']); ?>"
                                                                   data-modality="<?php echo htmlspecialchars($study['modality']); ?>">
                                                        </td>
                                                        <td>
                                                            <div class="font-weight-bold small">
                                                                <?php echo htmlspecialchars($study['imaging_name']); ?>
                                                            </div>
                                                            <div class="text-muted smaller">
                                                                <small><?php echo htmlspecialchars($study['imaging_code']); ?></small>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <?php echo getModalityBadge($study['modality']); ?>
                                                        </td>
                                                        <td class="text-right">
                                                            <span class="badge badge-light">
                                                                $<?php echo number_format($study['actual_price'] ?? $study['fee_amount'] ?? 0, 2); ?>
                                                            </span>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                        
                                        <!-- Selected Studies Summary -->
                                        <div id="selectedStudiesSummary" class="alert alert-info" style="display: none;">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <strong id="selectedCount">0</strong> study(s) selected
                                                    <br>
                                                    <small>Total: $<span id="selectedTotal">0.00</span></small>
                                                </div>
                                                <button type="button" class="btn btn-sm btn-outline-secondary" 
                                                        onclick="clearSelectedStudies()">
                                                    Clear All
                                                </button>
                                            </div>
                                        </div>
                                        
                                        <!-- Order Details Form -->
                                        <div id="orderDetailsForm" style="display: none;">
                                            <hr>
                                            <div class="form-group">
                                                <label for="order_priority">Priority</label>
                                                <select class="form-control" id="order_priority" name="order_priority">
                                                    <option value="routine">Routine</option>
                                                    <option value="urgent">Urgent</option>
                                                    <option value="stat">STAT (Immediate)</option>
                                                </select>
                                            </div>
                                            
                                            <div class="form-group">
                                                <label for="body_part">Body Part/Area</label>
                                                <input type="text" class="form-control" id="body_part" name="body_part" 
                                                       placeholder="e.g., Chest, Abdomen, Head, etc.">
                                            </div>
                                            
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <div class="form-check">
                                                            <input type="checkbox" class="form-check-input" 
                                                                   id="contrast_required" name="contrast_required">
                                                            <label class="form-check-label" for="contrast_required">Contrast Required</label>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label for="contrast_type">Contrast Type</label>
                                                        <select class="form-control" id="contrast_type" name="contrast_type">
                                                            <option value="">-- Select --</option>
                                                            <option value="Oral">Oral</option>
                                                            <option value="IV">IV</option>
                                                            <option value="Both">Both</option>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="form-group">
                                                <label for="clinical_notes">Clinical Notes</label>
                                                <textarea class="form-control" id="clinical_notes" name="clinical_notes" 
                                                          rows="3" placeholder="Clinical indication, suspected diagnosis, etc."></textarea>
                                            </div>
                                            
                                            <div class="form-group">
                                                <label for="instructions">Special Instructions</label>
                                                <textarea class="form-control" id="instructions" name="instructions" 
                                                          rows="2" placeholder="Special positioning, breath holding, etc."></textarea>
                                            </div>
                                            
                                            <button type="submit" name="create_imaging_order" class="btn btn-success btn-block">
                                                <i class="fas fa-plus mr-2"></i>
                                                Create Imaging Order
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        <?php elseif (isset($_POST['search_studies']) && empty($studies)): ?>
                            <div class="alert alert-warning mt-3">
                                <i class="fas fa-exclamation-triangle mr-2"></i>
                                No imaging studies found matching your search criteria.
                            </div>
                        <?php endif; ?>

                        <!-- Instructions -->
                        <?php if (!isset($_POST['search_studies'])): ?>
                            <div class="text-center py-4">
                                <i class="fas fa-search fa-3x text-muted mb-3"></i>
                                <h6 class="text-muted">Search for imaging studies to begin ordering</h6>
                                <p class="text-muted small">Search by study name, code, or browse by modality</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Common Imaging Studies -->
                <div class="card mt-4">
                    <div class="card-header bg-info py-2">
                        <h6 class="card-title mb-0 text-white">
                            <i class="fas fa-th-list mr-2"></i>Common Imaging Studies
                        </h6>
                    </div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush">
                            <?php
                            // Define common imaging studies
                            $common_studies = [
                                'CXR' => ['Chest X-Ray (PA & Lat)', 'X-Ray', 'CHEST'],
                                'AXR' => ['Abdomen X-Ray', 'X-Ray', 'ABDOMEN'],
                                'CTABD' => ['CT Abdomen & Pelvis', 'CT', 'ABDOMEN'],
                                'CTHEAD' => ['CT Head', 'CT', 'HEAD'],
                                'MRISPINE' => ['MRI Spine', 'MRI', 'SPINE'],
                                'USABD' => ['Ultrasound Abdomen', 'Ultrasound', 'ABDOMEN'],
                                'MAMMO' => ['Mammography', 'Mammography', 'BREAST'],
                                'ECHO' => ['Echocardiogram', 'Ultrasound', 'HEART'],
                            ];
                            
                            foreach ($common_studies as $code => $study):
                            ?>
                            <a href="#" class="list-group-item list-group-item-action" 
                               onclick="loadCommonStudy('<?php echo $code; ?>')">
                                <div class="d-flex w-100 justify-content-between">
                                    <h6 class="mb-1"><?php echo $study[0]; ?></h6>
                                    <small><?php echo getModalityBadge($study[1]); ?></small>
                                </div>
                                <p class="mb-1 small text-muted">
                                    <i class="fas fa-crosshairs mr-1"></i><?php echo $study[2]; ?>
                                </p>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Existing Imaging Orders -->
            <div class="col-md-7">
                <div class="card">
                    <div class="card-header bg-warning py-2">
                        <h4 class="card-title mb-0">
                            <i class="fas fa-list-alt mr-2"></i>Imaging Orders List
                            <span class="badge badge-light float-right"><?php echo count($imaging_orders); ?></span>
                        </h4>
                    </div>
                    <div class="card-body p-0">
                        <?php if (!empty($imaging_orders_with_studies)): ?>
                            <div class="table-responsive">
                                <?php foreach ($imaging_orders_with_studies as $order_id => $order_data): 
                                    $order = $order_data['order_info'];
                                    $studies = $order_data['studies'];
                                    $is_pending = $order['order_status'] == 'Pending';
                                ?>
                                <div class="card card-outline card-<?php echo $is_pending ? 'warning' : 'success'; ?> mb-3 mx-3 mt-3">
                                    <div class="card-header py-2">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <strong>Order #<?php echo htmlspecialchars($order['order_number']); ?></strong>
                                                <?php echo getImagingOrderStatusBadge($order['order_status']); ?>
                                                <?php echo getPriorityBadge($order['order_priority']); ?>
                                                <small class="text-muted ml-2">
                                                    <?php echo date('M j, H:i', strtotime($order['order_date'])); ?>
                                                </small>
                                            </div>
                                            <div>
                                                <span class="badge badge-light">
                                                    <?php echo count($studies); ?> study<?php echo count($studies) > 1 ? 'ies' : ''; ?>
                                                </span>
                                                <?php if ($order['bill_total'] > 0): ?>
                                                    <span class="badge badge-success ml-1">
                                                        $<?php echo number_format($order['bill_total'], 2); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php if ($order['bill_number']): ?>
                                        <div class="mt-1">
                                            <small class="text-muted">
                                                <i class="fas fa-receipt mr-1"></i>
                                                Bill: <?php echo htmlspecialchars($order['bill_number']); ?>
                                                <?php echo getBillStatusBadge($order['bill_status']); ?>
                                            </small>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="card-body p-0">
                                        <table class="table table-hover mb-0">
                                            <thead class="bg-light">
                                                <tr>
                                                    <th width="30%">Study Name</th>
                                                    <th width="20%">Modality</th>
                                                    <th width="20%">Status</th>
                                                    <th width="15%">Billed</th>
                                                    <th width="15%" class="text-center">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($studies as $study): ?>
                                                    <tr>
                                                        <td>
                                                            <div class="font-weight-bold small">
                                                                <?php echo htmlspecialchars($study['imaging_name']); ?>
                                                            </div>
                                                            <small class="text-muted">
                                                                <?php echo htmlspecialchars($study['imaging_code']); ?>
                                                            </small>
                                                        </td>
                                                        <td>
                                                            <?php echo getModalityBadge($study['modality']); ?>
                                                        </td>
                                                        <td>
                                                            <?php 
                                                            $study_status = $study['status'];
                                                            $status_class = $study_status == 'pending' ? 'warning' : 
                                                                           ($study_status == 'completed' ? 'success' : 'info');
                                                            ?>
                                                            <span class="badge badge-<?php echo $status_class; ?>">
                                                                <?php echo ucfirst($study_status); ?>
                                                            </span>
                                                            <?php if ($study['scheduled_date']): ?>
                                                                <br>
                                                                <small class="text-muted">
                                                                    <?php echo date('M j, H:i', strtotime($study['scheduled_date'])); ?>
                                                                </small>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php if ($study['billed_amount']): ?>
                                                                <span class="badge badge-light">
                                                                    $<?php echo number_format($study['billed_amount'], 2); ?>
                                                                </span>
                                                            <?php else: ?>
                                                                <span class="text-muted">Not billed</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="text-center">
                                                            <div class="btn-group btn-group-sm">
                                                                <button type="button" class="btn btn-info" 
                                                                        onclick="viewStudyDetails(<?php echo htmlspecialchars(json_encode($study)); ?>, <?php echo htmlspecialchars(json_encode($order)); ?>)"
                                                                        title="View Details">
                                                                    <i class="fas fa-eye"></i>
                                                                </button>
                                                                <?php if (!empty($study['findings']) && $study['status'] == 'completed'): ?>
                                                                    <button type="button" class="btn btn-primary" 
                                                                            onclick="viewReport(<?php echo htmlspecialchars(json_encode($study)); ?>)"
                                                                            title="View Report">
                                                                        <i class="fas fa-file-medical"></i>
                                                                    </button>
                                                                <?php endif; ?>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="card-footer py-2">
                                        <div class="d-flex justify-content-between">
                                            <div>
                                                <small class="text-muted">
                                                    <i class="fas fa-user-md mr-1"></i>
                                                    <?php echo htmlspecialchars($order['doctor_name']); ?>
                                                </small>
                                                <?php if ($order['radiologist_name']): ?>
                                                    <small class="text-muted ml-3">
                                                        <i class="fas fa-stethoscope mr-1"></i>
                                                        <?php echo htmlspecialchars($order['radiologist_name']); ?>
                                                    </small>
                                                <?php endif; ?>
                                                <?php if ($order['body_part']): ?>
                                                    <small class="text-muted ml-3">
                                                        <i class="fas fa-crosshairs mr-1"></i>
                                                        <?php echo htmlspecialchars($order['body_part']); ?>
                                                    </small>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <?php if ($is_pending): ?>
                                                    <form method="POST" style="display: inline;" 
                                                          onsubmit="return confirm('Cancel this entire imaging order?');">
                                                        <input type="hidden" name="radiology_order_id" value="<?php echo $order_id; ?>">
                                                        <button type="submit" name="cancel_imaging_order" class="btn btn-sm btn-danger" 
                                                                title="Cancel Order">
                                                            <i class="fas fa-times mr-1"></i>Cancel Order
                                                        </button>
                                                    </form>
                                                    <button type="button" class="btn btn-sm btn-primary ml-2" 
                                                            onclick="printImagingOrder(<?php echo $order_id; ?>)"
                                                            title="Print Order">
                                                        <i class="fas fa-print mr-1"></i>Print
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button" class="btn btn-sm btn-success" 
                                                            onclick="viewFullReport(<?php echo $order_id; ?>)"
                                                            title="View Full Report">
                                                        <i class="fas fa-file-pdf mr-1"></i>Report
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-x-ray fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">No Imaging Orders Yet</h5>
                                <p class="text-muted">Search and create imaging orders using the form on the left.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Study Details Modal -->
<div class="modal fade" id="studyDetailsModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Study Details</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body" id="studyDetailsContent">
                <!-- Content will be loaded dynamically -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="printStudyDetails()">
                    <i class="fas fa-print mr-2"></i>Print
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Report Modal -->
<div class="modal fade" id="reportModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-xl" role="document">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">Imaging Report</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body" id="reportContent">
                <!-- Content will be loaded dynamically -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="printReport()">
                    <i class="fas fa-print mr-2"></i>Print Report
                </button>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Auto-close alerts after 5 seconds
    setTimeout(function() {
        $('.alert').fadeOut('slow');
    }, 5000);

    // Tooltip initialization
    $('[title]').tooltip();

    // Study checkbox handling
    $('.study-checkbox').change(function() {
        updateSelectedStudiesSummary();
    });

    // Contrast required checkbox handling
    $('#contrast_required').change(function() {
        if ($(this).is(':checked')) {
            $('#contrast_type').prop('disabled', false);
        } else {
            $('#contrast_type').prop('disabled', true).val('');
        }
    });

    // Form validation
    $('#studySelectionForm').validate({
        rules: {
            'selected_studies[]': {
                required: true
            }
        }
    });

    // Auto-focus search field
    $('#search_term').focus();
});

function updateSelectedStudiesSummary() {
    var selectedStudies = $('.study-checkbox:checked');
    var count = selectedStudies.length;
    var total = 0;
    
    selectedStudies.each(function() {
        total += parseFloat($(this).data('price')) || 0;
    });
    
    if (count > 0) {
        $('#selectedCount').text(count);
        $('#selectedTotal').text(total.toFixed(2));
        $('#selectedStudiesSummary').show();
        $('#orderDetailsForm').show();
        
        // Auto-set order type based on selected studies
        if (count == 1) {
            var modality = selectedStudies.first().data('modality');
            if (modality) {
                $('#order_type').val(modality == 'X-Ray' ? 'X-Ray' : 
                                   modality == 'CT' ? 'CT Scan' : 
                                   modality == 'MRI' ? 'MRI' : 
                                   modality == 'Ultrasound' ? 'Ultrasound' : 
                                   modality == 'Mammography' ? 'Mammography' : 
                                   modality == 'Fluoroscopy' ? 'Fluoroscopy' : 'Other');
            }
        }
    } else {
        $('#selectedStudiesSummary').hide();
        $('#orderDetailsForm').hide();
    }
}

function clearSelectedStudies() {
    $('.study-checkbox').prop('checked', false);
    updateSelectedStudiesSummary();
}

function loadCommonStudy(studyCode) {
    // This would typically make an AJAX call to load studies for the panel
    alert('Loading ' + studyCode + ' study... This would load pre-defined imaging studies.');
    // For now, just clear and refocus search
    $('#search_term').val(studyCode).focus();
    $('#studySearchForm').submit();
}

function viewStudyDetails(study, order) {
    const modalContent = document.getElementById('studyDetailsContent');
    
    let html = `
        <div class="row">
            <div class="col-md-12">
                <div class="study-header text-center mb-4">
                    <h4>Study Details</h4>
                    <h5>Order #${order.order_number}</h5>
                    <hr>
                </div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-6">
                <div class="card mb-3">
                    <div class="card-header bg-light py-2">
                        <h6 class="mb-0"><i class="fas fa-file-image mr-2"></i>Study Information</h6>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm table-borderless">
                            <tr>
                                <th width="40%">Study Name:</th>
                                <td><strong>${study.imaging_name}</strong></td>
                            </tr>
                            <tr>
                                <th>Study Code:</th>
                                <td>${study.imaging_code}</td>
                            </tr>
                            <tr>
                                <th>Modality:</th>
                                <td>${getModalityBadgeHTML(study.modality)}</td>
                            </tr>
                            <tr>
                                <th>Duration:</th>
                                <td>${study.duration_minutes || 30} minutes</td>
                            </tr>
                            ${study.radiation_dose ? `
                            <tr>
                                <th>Radiation Dose:</th>
                                <td>${study.radiation_dose}</td>
                            </tr>
                            ` : ''}
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card mb-3">
                    <div class="card-header bg-light py-2">
                        <h6 class="mb-0"><i class="fas fa-clipboard-check mr-2"></i>Study Status</h6>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm table-borderless">
                            <tr>
                                <th width="40%">Status:</th>
                                <td>
                                    <span class="badge badge-${getStatusClass(study.status)}">
                                        ${study.status.toUpperCase()}
                                    </span>
                                </td>
                            </tr>
                            ${study.scheduled_date ? `
                            <tr>
                                <th>Scheduled:</th>
                                <td>${new Date(study.scheduled_date).toLocaleString()}</td>
                            </tr>
                            ` : ''}
                            ${study.performed_date ? `
                            <tr>
                                <th>Performed:</th>
                                <td>${new Date(study.performed_date).toLocaleString()}</td>
                            </tr>
                            ` : ''}
                            ${study.performed_by_name ? `
                            <tr>
                                <th>Performed By:</th>
                                <td>${study.performed_by_name}</td>
                            </tr>
                            ` : ''}
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        ${study.imaging_description ? `
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h6 class="mb-0"><i class="fas fa-info-circle mr-2"></i>Study Description</h6>
                    </div>
                    <div class="card-body">
                        <p class="mb-0">${study.imaging_description.replace(/\n/g, '<br>')}</p>
                    </div>
                </div>
            </div>
        </div>
        ` : ''}
        
        ${study.preparation_instructions ? `
        <div class="row mt-3">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h6 class="mb-0"><i class="fas fa-clipboard-list mr-2"></i>Preparation Instructions</h6>
                    </div>
                    <div class="card-body">
                        <pre class="mb-0" style="white-space: pre-wrap;">${study.preparation_instructions}</pre>
                    </div>
                </div>
            </div>
        </div>
        ` : ''}
        
        ${study.report_template ? `
        <div class="row mt-3">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h6 class="mb-0"><i class="fas fa-file-medical-alt mr-2"></i>Report Template</h6>
                    </div>
                    <div class="card-body">
                        <pre class="mb-0" style="white-space: pre-wrap;">${study.report_template}</pre>
                    </div>
                </div>
            </div>
        </div>
        ` : ''}
    `;
    
    modalContent.innerHTML = html;
    $('#studyDetailsModal').modal('show');
    
    function getStatusClass(status) {
        switch(status) {
            case 'pending': return 'warning';
            case 'scheduled': return 'info';
            case 'in_progress': return 'primary';
            case 'completed': return 'success';
            case 'cancelled': return 'danger';
            default: return 'light';
        }
    }
    
    function getModalityBadgeHTML(modality) {
        const badgeClass = getModalityClass(modality);
        return `<span class="badge badge-${badgeClass}">${modality}</span>`;
    }
    
    function getModalityClass(modality) {
        switch(modality) {
            case 'X-Ray': return 'primary';
            case 'CT': return 'info';
            case 'MRI': return 'success';
            case 'Ultrasound': return 'warning';
            case 'Mammography': return 'danger';
            case 'Fluoroscopy': return 'secondary';
            case 'Nuclear': return 'dark';
            default: return 'light';
        }
    }
}

function viewReport(study) {
    const modalContent = document.getElementById('reportContent');
    
    let html = `
        <div class="row">
            <div class="col-md-12">
                <div class="report-header text-center mb-4">
                    <h3>IMAGING REPORT</h3>
                    <h5>${study.imaging_name}</h5>
                    <hr>
                </div>
            </div>
        </div>
        
        <div class="row mb-4">
            <div class="col-md-6">
                <table class="table table-sm table-borderless">
                    <tr>
                        <th width="40%">Patient Name:</th>
                        <td><strong>${'<?php echo htmlspecialchars($full_name); ?>'}</strong></td>
                    </tr>
                    <tr>
                        <th>MRN:</th>
                        <td>${'<?php echo htmlspecialchars($patient_info["patient_mrn"]); ?>'}</td>
                    </tr>
                    <tr>
                        <th>Age:</th>
                        <td>${'<?php echo $age ?: "N/A"; ?>'}</td>
                    </tr>
                </table>
            </div>
            <div class="col-md-6">
                <table class="table table-sm table-borderless">
                    <tr>
                        <th width="40%">Study Date:</th>
                        <td>${study.performed_date ? new Date(study.performed_date).toLocaleDateString() : 'N/A'}</td>
                    </tr>
                    <tr>
                        <th>Report Date:</th>
                        <td>${study.result_date ? new Date(study.result_date).toLocaleDateString() : new Date().toLocaleDateString()}</td>
                    </tr>
                    <tr>
                        <th>Accession #:</th>
                        <td>${study.imaging_code}</td>
                    </tr>
                </table>
            </div>
        </div>
        
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h6 class="mb-0">CLINICAL INFORMATION</h6>
                    </div>
                    <div class="card-body">
                        <p class="mb-0">${study.study_notes || 'No clinical information provided.'}</p>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h6 class="mb-0">TECHNIQUE</h6>
                    </div>
                    <div class="card-body">
                        <p class="mb-0">
                            <strong>Modality:</strong> ${study.modality}<br>
                            <strong>Study:</strong> ${study.imaging_name}<br>
                            ${study.contrast_required ? `<strong>Contrast:</strong> ${study.contrast_type || 'Yes'}<br>` : ''}
                            <strong>Performed by:</strong> ${study.performed_by_name || 'N/A'}
                        </p>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h6 class="mb-0">FINDINGS</h6>
                    </div>
                    <div class="card-body">
                        <pre class="mb-0" style="white-space: pre-wrap; font-size: 14px;">${study.findings || 'No findings recorded.'}</pre>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h6 class="mb-0">IMPRESSION</h6>
                    </div>
                    <div class="card-body">
                        <pre class="mb-0" style="white-space: pre-wrap; font-size: 14px; font-weight: bold;">${study.impression || 'No impression provided.'}</pre>
                    </div>
                </div>
            </div>
        </div>
        
        ${study.recommendations ? `
        <div class="row mt-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h6 class="mb-0">RECOMMENDATIONS</h6>
                    </div>
                    <div class="card-body">
                        <pre class="mb-0" style="white-space: pre-wrap; font-size: 14px;">${study.recommendations}</pre>
                    </div>
                </div>
            </div>
        </div>
        ` : ''}
        
        <div class="row mt-4">
            <div class="col-md-12">
                <hr>
                <div class="text-right">
                    <p class="mb-0">
                        <strong>Radiologist:</strong> ${study.radiologist_name || study.performed_by_name || 'Not specified'}<br>
                        <small class="text-muted">${study.result_date ? 'Report finalized on: ' + new Date(study.result_date).toLocaleString() : ''}</small>
                    </p>
                </div>
            </div>
        </div>
    `;
    
    modalContent.innerHTML = html;
    $('#reportModal').modal('show');
}

function printImagingOrder(orderId) {
    const printWindow = window.open(`/clinic/radiology/print_order.php?order_id=${orderId}`, '_blank');
    printWindow.focus();
}

function viewFullReport(orderId) {
    const printWindow = window.open(`/clinic/doctor/radiology_report.php?report_id=${orderId}`, '_blank');
    printWindow.focus();
}

function printStudyDetails() {
    const modalContent = document.getElementById('studyDetailsContent');
    const printWindow = window.open('', '_blank');
    
    printWindow.document.write(`
        <html>
        <head>
            <title>Study Details - ${'<?php echo htmlspecialchars($full_name); ?>'}</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                .study-header { text-align: center; margin-bottom: 30px; }
                .card { margin-bottom: 15px; border: 1px solid #dee2e6; }
                @media print {
                    .no-print { display: none !important; }
                    body { margin: 0; padding: 20px; }
                }
            </style>
        </head>
        <body>
            ${modalContent.innerHTML}
            <div class="no-print text-center mt-4">
                <button onclick="window.print()" class="btn btn-primary">Print</button>
                <button onclick="window.close()" class="btn btn-secondary">Close</button>
            </div>
            <script>
                window.onload = function() {
                    window.print();
                };
            <\/script>
        </body>
        </html>
    `);
    
    printWindow.document.close();
}

function printReport() {
    const modalContent = document.getElementById('reportContent');
    const printWindow = window.open('', '_blank');
    
    printWindow.document.write(`
        <html>
        <head>
            <title>Imaging Report - ${'<?php echo htmlspecialchars($full_name); ?>'}</title>
            <style>
                body { font-family: 'Times New Roman', Times, serif; margin: 20px; }
                .report-header { text-align: center; margin-bottom: 30px; }
                .card { margin-bottom: 15px; border: 1px solid #000; }
                @media print {
                    .no-print { display: none !important; }
                    body { margin: 0; padding: 20px; }
                    .card { border: none; margin-bottom: 20px; }
                    pre { font-family: 'Times New Roman', Times, serif; }
                }
            </style>
        </head>
        <body>
            ${modalContent.innerHTML}
            <div class="no-print text-center mt-4">
                <button onclick="window.print()" class="btn btn-primary">Print Report</button>
                <button onclick="window.close()" class="btn btn-secondary">Close</button>
            </div>
            <script>
                window.onload = function() {
                    window.print();
                };
            <\/script>
        </body>
        </html>
    `);
    
    printWindow.document.close();
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + F for search focus
    if (e.ctrlKey && e.keyCode === 70) {
        e.preventDefault();
        $('#search_term').focus().select();
    }
    // Ctrl + P for print
    if (e.ctrlKey && e.keyCode === 80) {
        e.preventDefault();
        window.print();
    }
    // Escape to clear form
    if (e.keyCode === 27) {
        clearSelectedStudies();
    }
});


</script>

<style>
/* Custom styles for imaging orders page */
.card-outline-warning {
    border-color: #ffc107 !important;
}

.card-outline-success {
    border-color: #28a745 !important;
}

.study-checkbox {
    transform: scale(1.2);
}

.list-group-item:hover {
    background-color: #f8f9fa;
    cursor: pointer;
}

.smaller {
    font-size: 0.85em;
}

/* Print styles for reports */
@media print {
    .card-header, .card-tools, .btn, form, .modal {
        display: none !important;
    }
    .card {
        border: none !important;
    }
    .card-body {
        padding: 0 !important;
    }
    .report-header h3 {
        font-size: 24px;
        font-weight: bold;
    }
}
</style>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
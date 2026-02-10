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
    'module'      => 'Lab Orders',
    'table_name'  => 'N/A',
    'entity_type' => 'page',
    'record_id'   => null,
    'patient_id'  => null,
    'visit_id'    => null,
    'description' => "Accessed lab_orders.php",
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
        'module'      => 'Lab Orders',
        'table_name'  => 'visits',
        'entity_type' => 'visit',
        'record_id'   => null,
        'patient_id'  => null,
        'visit_id'    => null,
        'description' => "Attempted to access lab_orders.php with invalid visit ID: " . ($_GET['visit_id'] ?? 'empty'),
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
$search_term = '';
$search_category = '';

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
        'module'      => 'Lab Orders',
        'table_name'  => 'visits',
        'entity_type' => 'visit',
        'record_id'   => $visit_id,
        'patient_id'  => $visit_info['patient_id'],
        'visit_id'    => $visit_id,
        'description' => "Retrieved visit information for lab orders. Visit Type: " . $visit_type,
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
        'module'      => 'Lab Orders',
        'table_name'  => 'visits',
        'entity_type' => 'visit',
        'record_id'   => $visit_id,
        'patient_id'  => null,
        'visit_id'    => $visit_id,
        'description' => "Visit not found for lab orders. Visit ID: " . $visit_id,
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
            'module'      => 'Lab Orders',
            'table_name'  => 'ipd_admissions',
            'entity_type' => 'admission',
            'record_id'   => $admission_info['ipd_admission_id'] ?? null,
            'patient_id'  => $visit_info['patient_id'],
            'visit_id'    => $visit_id,
            'description' => "Retrieved IPD admission information for lab orders",
            'status'      => 'SUCCESS',
            'old_values'  => null,
            'new_values'  => null
        ]);
    }
}

// Function to generate lab order number
function generateLabOrderNumber($mysqli) {
    $prefix = "LAB";
    $year = date('Y');
    $month = date('m');

    // Pattern: LAB-YYYYMM%
    $like_pattern = $prefix . '-' . $year . $month . '%';

    $sql = "SELECT order_number FROM lab_orders 
            WHERE order_number LIKE ? 
            ORDER BY lab_order_id DESC LIMIT 1";

    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("s", $like_pattern);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $last_order = $result->fetch_assoc();
        $order_num = $last_order['order_number'];
        $last_part = substr($order_num, -4);
        $last_number = intval($last_part);
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
        'module'      => 'Lab Orders',
        'table_name'  => 'pending_bills',
        'entity_type' => 'pending_bill',
        'record_id'   => null,
        'patient_id'  => $patient_id,
        'visit_id'    => $visit_id,
        'description' => "Checking for existing pending bill or creating new one for lab order",
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
            'module'      => 'Lab Orders',
            'table_name'  => 'pending_bills',
            'entity_type' => 'pending_bill',
            'record_id'   => $existing_bill['pending_bill_id'],
            'patient_id'  => $patient_id,
            'visit_id'    => $visit_id,
            'description' => "Found existing pending bill for lab order. Bill #: " . $existing_bill['bill_number'],
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
                'module'      => 'Lab Orders',
                'table_name'  => 'pending_bills',
                'entity_type' => 'pending_bill',
                'record_id'   => $pending_bill_id,
                'patient_id'  => $patient_id,
                'visit_id'    => $visit_id,
                'description' => "Created new pending bill for lab order. Bill #: " . $bill_number,
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
                'module'      => 'Lab Orders',
                'table_name'  => 'pending_bills',
                'entity_type' => 'pending_bill',
                'record_id'   => null,
                'patient_id'  => $patient_id,
                'visit_id'    => $visit_id,
                'description' => "Failed to create pending bill for lab order. Error: " . $error,
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
        'module'      => 'Lab Orders',
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
            'module'      => 'Lab Orders',
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
    
    if (!empty($billable_item['is_taxable']) && $billable_item['is_taxable']) {
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
                'module'      => 'Lab Orders',
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
            'module'      => 'Lab Orders',
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
        // Create new bill item
        $insert_sql = "INSERT INTO pending_bill_items 
                      (pending_bill_id, billable_item_id, price_list_item_id,
                       item_quantity, unit_price, discount_percentage, discount_amount,
                       tax_percentage, subtotal, tax_amount, total_amount,
                       source_type, source_id, created_by)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $insert_stmt = $mysqli->prepare($insert_sql);
        $tax_percentage = (!empty($billable_item['is_taxable']) && $billable_item['is_taxable']) ? 
                         ($billable_item['tax_rate'] ?? 0) : 0;
        
        $discount_percentage = $billable_item['discount_percentage'] ?? 0;
        $discount_amount = $billable_item['discount_amount'] ?? 0;
        
        $insert_stmt->bind_param(
            "iiidddddddsssi",
            $pending_bill_id,
            $billable_item_id,
            $billable_item_id, // Using billable_item_id as price_list_item_id for simplicity
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
                'module'      => 'Lab Orders',
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
            'module'      => 'Lab Orders',
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
            'module'      => 'Lab Orders',
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

// Function to check for existing active lab order
function getActiveLabOrder($mysqli, $visit_id, $patient_id) {
    $sql = "SELECT lo.* 
            FROM lab_orders lo
            WHERE lo.visit_id = ? 
            AND lo.lab_order_patient_id = ?
            AND lo.lab_order_status IN ('Pending', 'Sample Collected', 'In Progress')
            AND lo.lab_order_archived_at IS NULL
            ORDER BY lo.order_date DESC 
            LIMIT 1";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("ii", $visit_id, $patient_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $order = $result->fetch_assoc();
        
        // AUDIT LOG: Found active lab order
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'FIND_ACTIVE_LAB_ORDER',
            'module'      => 'Lab Orders',
            'table_name'  => 'lab_orders',
            'entity_type' => 'lab_order',
            'record_id'   => $order['lab_order_id'],
            'patient_id'  => $patient_id,
            'visit_id'    => $visit_id,
            'description' => "Found active lab order. Order #: " . $order['order_number'],
            'status'      => 'SUCCESS',
            'old_values'  => null,
            'new_values'  => null
        ]);
        
        return $order;
    }
    
    return null;
}

// Function to check if test already exists in order
function testExistsInOrder($mysqli, $lab_order_id, $test_id) {
    $sql = "SELECT lab_order_test_id FROM lab_order_tests 
            WHERE lab_order_id = ? AND test_id = ?";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("ii", $lab_order_id, $test_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->num_rows > 0;
}

// Handle form submission for creating lab order
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // AUDIT LOG: Form submission received
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'FORM_SUBMIT',
        'module'      => 'Lab Orders',
        'table_name'  => 'N/A',
        'entity_type' => 'form',
        'record_id'   => null,
        'patient_id'  => $patient_info['patient_id'],
        'visit_id'    => $visit_id,
        'description' => "Form submission received on lab orders page. Action: " . 
                        (isset($_POST['create_lab_order']) ? 'create_lab_order' : 
                         (isset($_POST['cancel_lab_order']) ? 'cancel_lab_order' : 'search_tests')),
        'status'      => 'ATTEMPT',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    // Create new lab order or add to existing
    if (isset($_POST['create_lab_order'])) {
        $order_priority = !empty($_POST['order_priority']) ? trim($_POST['order_priority']) : 'routine';
        $clinical_notes = !empty($_POST['clinical_notes']) ? trim($_POST['clinical_notes']) : '';
        $specimen_type = !empty($_POST['specimen_type']) ? trim($_POST['specimen_type']) : '';
        $instructions = !empty($_POST['instructions']) ? trim($_POST['instructions']) : '';
        $lab_order_type = !empty($_POST['lab_order_type']) ? trim($_POST['lab_order_type']) : 'Routine';
        
        $selected_tests = isset($_POST['selected_tests']) ? $_POST['selected_tests'] : [];
        
        // AUDIT LOG: Starting lab order creation
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'],
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'CREATE_LAB_ORDER_START',
            'module'      => 'Lab Orders',
            'table_name'  => 'lab_orders',
            'entity_type' => 'lab_order',
            'record_id'   => null,
            'patient_id'  => $patient_info['patient_id'],
            'visit_id'    => $visit_id,
            'description' => "Starting creation of lab order with " . count($selected_tests) . " tests selected. Priority: " . $order_priority,
            'status'      => 'ATTEMPT',
            'old_values'  => null,
            'new_values'  => null
        ]);
        
        // Start transaction
        $mysqli->begin_transaction();
        
        try {
            $user_id = $_SESSION['user_id'];
            $patient_id = $patient_info['patient_id'];
            
            // Check if there's an existing active lab order
            $existing_order = getActiveLabOrder($mysqli, $visit_id, $patient_id);
            
            if ($existing_order) {
                // Use existing order
                $lab_order_id = $existing_order['lab_order_id'];
                $order_number = $existing_order['order_number'];
                $is_new_order = false;
                
                // AUDIT LOG: Using existing lab order
                audit_log($mysqli, [
                    'user_id'     => $user_id,
                    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                    'action'      => 'USE_EXISTING_LAB_ORDER',
                    'module'      => 'Lab Orders',
                    'table_name'  => 'lab_orders',
                    'entity_type' => 'lab_order',
                    'record_id'   => $lab_order_id,
                    'patient_id'  => $patient_id,
                    'visit_id'    => $visit_id,
                    'description' => "Using existing lab order #" . $order_number . " to add tests",
                    'status'      => 'SUCCESS',
                    'old_values'  => null,
                    'new_values'  => null
                ]);
                
                // Update order details if provided
                if (!empty($clinical_notes)) {
                    $old_notes = $existing_order['clinical_notes'] ?? '';
                    $new_notes = $old_notes . (!empty($old_notes) ? "\n" : "") . $clinical_notes;
                    
                    $update_notes_sql = "UPDATE lab_orders SET clinical_notes = ? 
                                         WHERE lab_order_id = ?";
                    $update_notes_stmt = $mysqli->prepare($update_notes_sql);
                    $update_notes_stmt->bind_param("si", $new_notes, $lab_order_id);
                    $update_notes_stmt->execute();
                    
                    // AUDIT LOG: Updated clinical notes
                    audit_log($mysqli, [
                        'user_id'     => $user_id,
                        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                        'action'      => 'UPDATE_CLINICAL_NOTES',
                        'module'      => 'Lab Orders',
                        'table_name'  => 'lab_orders',
                        'entity_type' => 'lab_order',
                        'record_id'   => $lab_order_id,
                        'patient_id'  => $patient_id,
                        'visit_id'    => $visit_id,
                        'description' => "Updated clinical notes for lab order #" . $order_number,
                        'status'      => 'SUCCESS',
                        'old_values'  => [
                            'clinical_notes' => $old_notes
                        ],
                        'new_values'  => [
                            'clinical_notes' => $new_notes
                        ]
                    ]);
                }
                
                if (!empty($specimen_type) && empty($existing_order['specimen_type'])) {
                    $update_specimen_sql = "UPDATE lab_orders SET specimen_type = ? WHERE lab_order_id = ?";
                    $update_specimen_stmt = $mysqli->prepare($update_specimen_sql);
                    $update_specimen_stmt->bind_param("si", $specimen_type, $lab_order_id);
                    $update_specimen_stmt->execute();
                    
                    // AUDIT LOG: Updated specimen type
                    audit_log($mysqli, [
                        'user_id'     => $user_id,
                        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                        'action'      => 'UPDATE_SPECIMEN_TYPE',
                        'module'      => 'Lab Orders',
                        'table_name'  => 'lab_orders',
                        'entity_type' => 'lab_order',
                        'record_id'   => $lab_order_id,
                        'patient_id'  => $patient_id,
                        'visit_id'    => $visit_id,
                        'description' => "Updated specimen type for lab order #" . $order_number . ": " . $specimen_type,
                        'status'      => 'SUCCESS',
                        'old_values'  => [
                            'specimen_type' => $existing_order['specimen_type'] ?? null
                        ],
                        'new_values'  => [
                            'specimen_type' => $specimen_type
                        ]
                    ]);
                }
                
                if (!empty($instructions)) {
                    $old_instructions = $existing_order['instructions'] ?? '';
                    $new_instructions = $old_instructions . (!empty($old_instructions) ? "\n" : "") . $instructions;
                    
                    $update_instructions_sql = "UPDATE lab_orders SET instructions = ? 
                                                WHERE lab_order_id = ?";
                    $update_instructions_stmt = $mysqli->prepare($update_instructions_sql);
                    $update_instructions_stmt->bind_param("si", $new_instructions, $lab_order_id);
                    $update_instructions_stmt->execute();
                    
                    // AUDIT LOG: Updated instructions
                    audit_log($mysqli, [
                        'user_id'     => $user_id,
                        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                        'action'      => 'UPDATE_INSTRUCTIONS',
                        'module'      => 'Lab Orders',
                        'table_name'  => 'lab_orders',
                        'entity_type' => 'lab_order',
                        'record_id'   => $lab_order_id,
                        'patient_id'  => $patient_id,
                        'visit_id'    => $visit_id,
                        'description' => "Updated instructions for lab order #" . $order_number,
                        'status'      => 'SUCCESS',
                        'old_values'  => [
                            'instructions' => $old_instructions
                        ],
                        'new_values'  => [
                            'instructions' => $new_instructions
                        ]
                    ]);
                }
                
                // Update order priority if different
                if ($order_priority !== $existing_order['order_priority']) {
                    $update_priority_sql = "UPDATE lab_orders SET order_priority = ? WHERE lab_order_id = ?";
                    $update_priority_stmt = $mysqli->prepare($update_priority_sql);
                    $update_priority_stmt->bind_param("si", $order_priority, $lab_order_id);
                    $update_priority_stmt->execute();
                    
                    // AUDIT LOG: Updated order priority
                    audit_log($mysqli, [
                        'user_id'     => $user_id,
                        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                        'action'      => 'UPDATE_ORDER_PRIORITY',
                        'module'      => 'Lab Orders',
                        'table_name'  => 'lab_orders',
                        'entity_type' => 'lab_order',
                        'record_id'   => $lab_order_id,
                        'patient_id'  => $patient_id,
                        'visit_id'    => $visit_id,
                        'description' => "Updated order priority for lab order #" . $order_number . ": " . $order_priority,
                        'status'      => 'SUCCESS',
                        'old_values'  => [
                            'order_priority' => $existing_order['order_priority']
                        ],
                        'new_values'  => [
                            'order_priority' => $order_priority
                        ]
                    ]);
                }
                
                // Get existing pending bill ID or create new one
                $bill_sql = "SELECT invoice_id FROM lab_orders WHERE lab_order_id = ?";
                $bill_stmt = $mysqli->prepare($bill_sql);
                $bill_stmt->bind_param("i", $lab_order_id);
                $bill_stmt->execute();
                $bill_result = $bill_stmt->get_result();
                
                if ($bill_result->num_rows > 0) {
                    $order_data = $bill_result->fetch_assoc();
                    $pending_bill_id = $order_data['invoice_id'];
                    
                    if (empty($pending_bill_id)) {
                        $bill_info = getOrCreatePendingBill($mysqli, $visit_id, $patient_id, $user_id);
                        $pending_bill_id = $bill_info['pending_bill_id'];
                        
                        // Update order with bill info
                        $update_bill_sql = "UPDATE lab_orders SET invoice_id = ? WHERE lab_order_id = ?";
                        $update_bill_stmt = $mysqli->prepare($update_bill_sql);
                        $update_bill_stmt->bind_param("ii", $pending_bill_id, $lab_order_id);
                        $update_bill_stmt->execute();
                        
                        // AUDIT LOG: Updated order with bill info
                        audit_log($mysqli, [
                            'user_id'     => $user_id,
                            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                            'action'      => 'UPDATE_ORDER_WITH_BILL',
                            'module'      => 'Lab Orders',
                            'table_name'  => 'lab_orders',
                            'entity_type' => 'lab_order',
                            'record_id'   => $lab_order_id,
                            'patient_id'  => $patient_id,
                            'visit_id'    => $visit_id,
                            'description' => "Updated lab order #" . $order_number . " with pending bill ID: " . $pending_bill_id,
                            'status'      => 'SUCCESS',
                            'old_values'  => [
                                'invoice_id' => null
                            ],
                            'new_values'  => [
                                'invoice_id' => $pending_bill_id
                            ]
                        ]);
                    }
                } else {
                    $bill_info = getOrCreatePendingBill($mysqli, $visit_id, $patient_id, $user_id);
                    $pending_bill_id = $bill_info['pending_bill_id'];
                }
            } else {
                // Create new lab order
                $order_number = generateLabOrderNumber($mysqli);
                
                $order_sql = "INSERT INTO lab_orders 
                             (order_number, visit_id, lab_order_patient_id, order_date, order_priority, 
                              clinical_notes, specimen_type, instructions, lab_order_type,
                              ordered_by, created_by, ordering_doctor_id, lab_order_status)
                             VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')";
                
                $order_stmt = $mysqli->prepare($order_sql);
                $order_stmt->bind_param(
                    "siisssssiii",
                    $order_number,
                    $visit_id,
                    $patient_id,
                    $order_priority,
                    $clinical_notes,
                    $specimen_type,
                    $instructions,
                    $lab_order_type,
                    $user_id,
                    $user_id,
                    $user_id
                );

                if (!$order_stmt->execute()) {
                    throw new Exception("Failed to create lab order: " . $mysqli->error);
                }
                
                $lab_order_id = $mysqli->insert_id;
                $is_new_order = true;
                
                // AUDIT LOG: Created new lab order
                audit_log($mysqli, [
                    'user_id'     => $user_id,
                    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                    'action'      => 'CREATE_LAB_ORDER',
                    'module'      => 'Lab Orders',
                    'table_name'  => 'lab_orders',
                    'entity_type' => 'lab_order',
                    'record_id'   => $lab_order_id,
                    'patient_id'  => $patient_id,
                    'visit_id'    => $visit_id,
                    'description' => "Created new lab order #" . $order_number . ". Priority: " . $order_priority . ", Type: " . $lab_order_type,
                    'status'      => 'SUCCESS',
                    'old_values'  => null,
                    'new_values'  => [
                        'order_number' => $order_number,
                        'visit_id' => $visit_id,
                        'patient_id' => $patient_id,
                        'order_priority' => $order_priority,
                        'clinical_notes' => $clinical_notes,
                        'specimen_type' => $specimen_type,
                        'lab_order_type' => $lab_order_type,
                        'lab_order_status' => 'Pending'
                    ]
                ]);
                
                // Create pending bill
                $bill_info = getOrCreatePendingBill($mysqli, $visit_id, $patient_id, $user_id);
                $pending_bill_id = $bill_info['pending_bill_id'];
                
                // Update lab order with bill info
                $update_order_sql = "UPDATE lab_orders 
                                    SET invoice_id = ?
                                    WHERE lab_order_id = ?";
                
                $update_order_stmt = $mysqli->prepare($update_order_sql);
                $update_order_stmt->bind_param("ii", $pending_bill_id, $lab_order_id);
                $update_order_stmt->execute();
                
                // AUDIT LOG: Updated new order with bill info
                audit_log($mysqli, [
                    'user_id'     => $user_id,
                    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                    'action'      => 'UPDATE_NEW_ORDER_WITH_BILL',
                    'module'      => 'Lab Orders',
                    'table_name'  => 'lab_orders',
                    'entity_type' => 'lab_order',
                    'record_id'   => $lab_order_id,
                    'patient_id'  => $patient_id,
                    'visit_id'    => $visit_id,
                    'description' => "Updated new lab order #" . $order_number . " with pending bill ID: " . $pending_bill_id,
                    'status'      => 'SUCCESS',
                    'old_values'  => [
                        'invoice_id' => null
                    ],
                    'new_values'  => [
                        'invoice_id' => $pending_bill_id
                    ]
                ]);
            }
            
            // Add selected tests
            $added_tests = 0;
            $skipped_duplicate_tests = 0;
            
            if (!empty($selected_tests)) {
                foreach ($selected_tests as $test_id) {
                    $test_id = intval($test_id);
                    
                    // Check if test already exists in order
                    if (testExistsInOrder($mysqli, $lab_order_id, $test_id)) {
                        $skipped_duplicate_tests++;
                        continue; // Skip if test already exists
                    }
                    
                    // Get test details and billable item
                    $test_sql = "SELECT t.*, bi.billable_item_id, bi.unit_price 
                                FROM lab_tests t
                                LEFT JOIN billable_items bi ON bi.source_table = 'lab_tests' 
                                    AND bi.source_id = t.test_id
                                WHERE t.test_id = ?";
                    $test_stmt = $mysqli->prepare($test_sql);
                    $test_stmt->bind_param("i", $test_id);
                    $test_stmt->execute();
                    $test_result = $test_stmt->get_result();
                    
                    if ($test_result->num_rows > 0) {
                        $test = $test_result->fetch_assoc();
                        $test_name = $test['test_name'] ?? 'Unknown Test';
                        
                        // Add test to order
                        $test_order_sql = "INSERT INTO lab_order_tests 
                                          (lab_order_id, test_id, status, created_at)
                                          VALUES (?, ?, 'pending', NOW())";
                        $test_order_stmt = $mysqli->prepare($test_order_sql);
                        $test_order_stmt->bind_param("ii", $lab_order_id, $test_id);
                        
                        if (!$test_order_stmt->execute()) {
                            throw new Exception("Failed to add test to order: " . $mysqli->error);
                        }
                        
                        $test_order_id = $mysqli->insert_id;
                        $added_tests++;
                        
                        // AUDIT LOG: Added test to lab order
                        audit_log($mysqli, [
                            'user_id'     => $user_id,
                            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                            'action'      => 'ADD_TEST_TO_ORDER',
                            'module'      => 'Lab Orders',
                            'table_name'  => 'lab_order_tests',
                            'entity_type' => 'lab_test',
                            'record_id'   => $test_order_id,
                            'patient_id'  => $patient_id,
                            'visit_id'    => $visit_id,
                            'description' => "Added test to lab order. Test: " . $test_name . ", Order #: " . ($order_number ?? 'N/A'),
                            'status'      => 'SUCCESS',
                            'old_values'  => null,
                            'new_values'  => [
                                'lab_order_id' => $lab_order_id,
                                'test_id' => $test_id,
                                'test_name' => $test_name,
                                'status' => 'pending'
                            ]
                        ]);
                        
                        // If billable item exists and has price, add to pending bill
                        if (!empty($test['billable_item_id']) && 
                            (($test['unit_price'] ?? 0) > 0 || ($test['price'] ?? 0) > 0)) {
                            $unit_price = floatval($test['unit_price'] ?? $test['price'] ?? 0);
                            
                            // Add to pending bill items
                            addItemToPendingBill(
                                $mysqli,
                                $pending_bill_id,
                                $test['billable_item_id'],
                                1, // quantity
                                $unit_price,
                                'lab_order', // source_type
                                $lab_order_id, // source_id
                                $user_id
                            );
                        }
                    }
                }
                
                // Update pending bill totals
                updatePendingBillTotals($mysqli, $pending_bill_id);
                
                // Update lab order billed status
                $update_billed_sql = "UPDATE lab_orders 
                                     SET is_billed = CASE WHEN ? > 0 THEN 1 ELSE 0 END,
                                         lab_order_updated_at = NOW()
                                     WHERE lab_order_id = ?";
                
                $update_billed_stmt = $mysqli->prepare($update_billed_sql);
                $update_billed_stmt->bind_param("ii", $pending_bill_id, $lab_order_id);
                $update_billed_stmt->execute();
            }
            
            // Commit transaction
            $mysqli->commit();
            
            if ($is_new_order) {
                $message = "Lab order #$order_number created successfully with $added_tests test(s)";
                if ($bill_info['is_new']) {
                    $message .= " and new pending bill #" . $bill_info['bill_number'];
                } else {
                    $message .= " and added to existing pending bill";
                }
            } else {
                $message = "$added_tests test(s) added to existing lab order #$order_number";
                if ($skipped_duplicate_tests > 0) {
                    $message .= " ($skipped_duplicate_tests duplicate test(s) skipped)";
                }
            }
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = $message;
            
            // AUDIT LOG: Lab order creation completed successfully
            audit_log($mysqli, [
                'user_id'     => $user_id,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'CREATE_LAB_ORDER_COMPLETE',
                'module'      => 'Lab Orders',
                'table_name'  => 'lab_orders',
                'entity_type' => 'lab_order',
                'record_id'   => $lab_order_id,
                'patient_id'  => $patient_id,
                'visit_id'    => $visit_id,
                'description' => "Lab order creation completed successfully. Order #: " . ($order_number ?? 'N/A') . ", Tests added: " . $added_tests,
                'status'      => 'SUCCESS',
                'old_values'  => null,
                'new_values'  => null
            ]);
            
            header("Location: lab_orders.php?visit_id=" . $visit_id);
            exit;
            
        } catch (Exception $e) {
            $mysqli->rollback();
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error: " . $e->getMessage();
            
            // AUDIT LOG: Lab order creation failed
            audit_log($mysqli, [
                'user_id'     => $_SESSION['user_id'],
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'CREATE_LAB_ORDER_FAIL',
                'module'      => 'Lab Orders',
                'table_name'  => 'lab_orders',
                'entity_type' => 'lab_order',
                'record_id'   => null,
                'patient_id'  => $patient_info['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Failed to create lab order. Error: " . $e->getMessage(),
                'status'      => 'FAILED',
                'old_values'  => null,
                'new_values'  => null
            ]);
        }
    }
    
    // Handle cancel lab order
    if (isset($_POST['cancel_lab_order'])) {
        $lab_order_id = intval($_POST['lab_order_id']);
        
        // AUDIT LOG: Starting lab order cancellation
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'],
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'CANCEL_LAB_ORDER_START',
            'module'      => 'Lab Orders',
            'table_name'  => 'lab_orders',
            'entity_type' => 'lab_order',
            'record_id'   => $lab_order_id,
            'patient_id'  => $patient_info['patient_id'],
            'visit_id'    => $visit_id,
            'description' => "Starting cancellation of lab order ID: " . $lab_order_id,
            'status'      => 'ATTEMPT',
            'old_values'  => null,
            'new_values'  => null
        ]);
        
        // Start transaction
        $mysqli->begin_transaction();
        
        try {
            // Get pending bill ID from lab order
            $get_bill_sql = "SELECT invoice_id, order_number, lab_order_status FROM lab_orders WHERE lab_order_id = ?";
            $get_bill_stmt = $mysqli->prepare($get_bill_sql);
            $get_bill_stmt->bind_param("i", $lab_order_id);
            $get_bill_stmt->execute();
            $get_bill_result = $get_bill_stmt->get_result();
            
            $pending_bill_id = null;
            $order_number = null;
            $old_status = null;
            
            if ($get_bill_result->num_rows > 0) {
                $order_data = $get_bill_result->fetch_assoc();
                $pending_bill_id = $order_data['invoice_id'];
                $order_number = $order_data['order_number'];
                $old_status = $order_data['lab_order_status'];
            }
            
            // Cancel lab order
            $cancel_sql = "UPDATE lab_orders 
                          SET lab_order_status = 'Cancelled',
                              lab_order_archived_at = NOW(),
                              lab_order_updated_at = NOW()
                          WHERE lab_order_id = ?";
            $cancel_stmt = $mysqli->prepare($cancel_sql);
            $cancel_stmt->bind_param("i", $lab_order_id);
            
            if (!$cancel_stmt->execute()) {
                throw new Exception("Failed to cancel lab order: " . $mysqli->error);
            }
            
            // AUDIT LOG: Lab order cancelled
            audit_log($mysqli, [
                'user_id'     => $_SESSION['user_id'],
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'CANCEL_LAB_ORDER',
                'module'      => 'Lab Orders',
                'table_name'  => 'lab_orders',
                'entity_type' => 'lab_order',
                'record_id'   => $lab_order_id,
                'patient_id'  => $patient_info['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Cancelled lab order. Order #: " . $order_number,
                'status'      => 'SUCCESS',
                'old_values'  => [
                    'lab_order_status' => $old_status,
                    'lab_order_archived_at' => null
                ],
                'new_values'  => [
                    'lab_order_status' => 'Cancelled',
                    'lab_order_archived_at' => date('Y-m-d H:i:s')
                ]
            ]);
            
            // If there's an associated pending bill, cancel the bill items too
            if ($pending_bill_id) {
                // Cancel pending bill items associated with this lab order
                $cancel_items_sql = "UPDATE pending_bill_items 
                                    SET is_cancelled = 1,
                                        cancelled_at = NOW(),
                                        cancelled_by = ?,
                                        cancellation_reason = 'Lab order cancelled'
                                    WHERE pending_bill_id = ? 
                                    AND source_type = 'lab_order' 
                                    AND source_id = ?";
                
                $cancel_items_stmt = $mysqli->prepare($cancel_items_sql);
                $cancelled_by = $_SESSION['user_id'];
                $cancel_items_stmt->bind_param("iii", $cancelled_by, $pending_bill_id, $lab_order_id);
                $cancel_items_stmt->execute();
                
                // Get count of cancelled items
                $affected_items = $cancel_items_stmt->affected_rows;
                
                // AUDIT LOG: Cancelled bill items
                audit_log($mysqli, [
                    'user_id'     => $_SESSION['user_id'],
                    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                    'action'      => 'CANCEL_BILL_ITEMS',
                    'module'      => 'Lab Orders',
                    'table_name'  => 'pending_bill_items',
                    'entity_type' => 'bill_items',
                    'record_id'   => null,
                    'patient_id'  => $patient_info['patient_id'],
                    'visit_id'    => $visit_id,
                    'description' => "Cancelled " . $affected_items . " bill items associated with lab order #" . $order_number,
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
            $_SESSION['alert_message'] = "Lab order cancelled successfully";
            
            // AUDIT LOG: Lab order cancellation completed
            audit_log($mysqli, [
                'user_id'     => $_SESSION['user_id'],
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'CANCEL_LAB_ORDER_COMPLETE',
                'module'      => 'Lab Orders',
                'table_name'  => 'lab_orders',
                'entity_type' => 'lab_order',
                'record_id'   => $lab_order_id,
                'patient_id'  => $patient_info['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Lab order cancellation completed successfully. Order #: " . $order_number,
                'status'      => 'SUCCESS',
                'old_values'  => null,
                'new_values'  => null
            ]);
            
            header("Location: lab_orders.php?visit_id=" . $visit_id);
            exit;
            
        } catch (Exception $e) {
            $mysqli->rollback();
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error cancelling lab order: " . $e->getMessage();
            
            // AUDIT LOG: Lab order cancellation failed
            audit_log($mysqli, [
                'user_id'     => $_SESSION['user_id'],
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'CANCEL_LAB_ORDER_FAIL',
                'module'      => 'Lab Orders',
                'table_name'  => 'lab_orders',
                'entity_type' => 'lab_order',
                'record_id'   => $lab_order_id,
                'patient_id'  => $patient_info['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Failed to cancel lab order. Error: " . $e->getMessage(),
                'status'      => 'FAILED',
                'old_values'  => null,
                'new_values'  => null
            ]);
        }
    }
    
    // Handle search for tests
    if (isset($_POST['search_tests'])) {
        $search_term = !empty($_POST['search_term']) ? trim($_POST['search_term']) : '';
        $search_category = !empty($_POST['search_category']) ? trim($_POST['search_category']) : '';
        
        // AUDIT LOG: Search for lab tests
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'],
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'SEARCH_LAB_TESTS',
            'module'      => 'Lab Orders',
            'table_name'  => 'lab_tests',
            'entity_type' => 'search',
            'record_id'   => null,
            'patient_id'  => $patient_info['patient_id'],
            'visit_id'    => $visit_id,
            'description' => "Searched for lab tests. Term: '" . $search_term . "', Category ID: '" . $search_category . "'",
            'status'      => 'SUCCESS',
            'old_values'  => null,
            'new_values'  => null
        ]);
    }
}

// Get existing lab orders for this visit
$lab_orders_sql = "SELECT lo.*, 
                   GROUP_CONCAT(DISTINCT lt.test_name SEPARATOR ', ') as test_names,
                   COUNT(DISTINCT lot.lab_order_test_id) as test_count,
                   u.user_name as doctor_name,
                   pb.bill_number,
                   pb.total_amount as bill_total,
                   pb.bill_status as bill_status
                   FROM lab_orders lo
                   LEFT JOIN lab_order_tests lot ON lo.lab_order_id = lot.lab_order_id
                   LEFT JOIN lab_tests lt ON lot.test_id = lt.test_id
                   LEFT JOIN users u ON lo.ordered_by = u.user_id
                   LEFT JOIN pending_bills pb ON lo.invoice_id = pb.pending_bill_id
                   WHERE lo.visit_id = ? 
                   AND lo.lab_order_patient_id = ?
                   AND lo.lab_order_archived_at IS NULL
                   GROUP BY lo.lab_order_id
                   ORDER BY lo.order_date DESC";
$lab_orders_stmt = $mysqli->prepare($lab_orders_sql);
$lab_orders_stmt->bind_param("ii", $visit_id, $patient_info['patient_id']);
$lab_orders_stmt->execute();
$lab_orders_result = $lab_orders_stmt->get_result();
$lab_orders = $lab_orders_result->fetch_all(MYSQLI_ASSOC);

// AUDIT LOG: Retrieved existing lab orders
audit_log($mysqli, [
    'user_id'     => $_SESSION['user_id'] ?? null,
    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
    'action'      => 'RETRIEVE_LAB_ORDERS',
    'module'      => 'Lab Orders',
    'table_name'  => 'lab_orders',
    'entity_type' => 'orders_list',
    'record_id'   => null,
    'patient_id'  => $patient_info['patient_id'],
    'visit_id'    => $visit_id,
    'description' => "Retrieved " . count($lab_orders) . " existing lab orders for patient",
    'status'      => 'SUCCESS',
    'old_values'  => null,
    'new_values'  => null
]);

// Get order details with tests
$lab_orders_with_tests = [];
foreach ($lab_orders as $order) {
    $order_id = $order['lab_order_id'];
    
    $tests_sql = "SELECT lt.*, lot.*, 
                 bi.billable_item_id, bi.unit_price as actual_price,
                 u.user_name as performed_by_name,
                 pbi.subtotal as billed_amount
                 FROM lab_order_tests lot
                 JOIN lab_tests lt ON lot.test_id = lt.test_id
                 LEFT JOIN billable_items bi ON bi.source_table = 'lab_tests' 
                     AND bi.source_id = lt.test_id
                 LEFT JOIN pending_bill_items pbi ON pbi.source_type = 'lab_order' 
                     AND pbi.source_id = lot.lab_order_id 
                     AND pbi.billable_item_id = bi.billable_item_id
                     AND pbi.is_cancelled = 0
                 LEFT JOIN users u ON lot.performed_by = u.user_id
                 WHERE lot.lab_order_id = ?
                 ORDER BY lt.test_name";
    $tests_stmt = $mysqli->prepare($tests_sql);
    $tests_stmt->bind_param("i", $order_id);
    $tests_stmt->execute();
    $tests_result = $tests_stmt->get_result();
    $tests = $tests_result->fetch_all(MYSQLI_ASSOC);
    
    $lab_orders_with_tests[$order_id] = [
        'order_info' => $order,
        'tests' => $tests
    ];
}

// Search tests if requested
$tests = [];
if (isset($_POST['search_tests']) && !empty($search_term)) {
    $test_search_sql = "SELECT lt.*, lc.category_name,
                       bi.billable_item_id, bi.unit_price as actual_price,
                       bi.item_name as billable_item_name
                       FROM lab_tests lt
                       LEFT JOIN lab_test_categories lc ON lt.category_id = lc.category_id
                       LEFT JOIN billable_items bi ON bi.source_table = 'lab_tests' 
                           AND bi.source_id = lt.test_id
                       WHERE lt.is_active = 1 
                       AND bi.billable_item_id IS NOT NULL";
    
    $params = [];
    $types = "";
    
    if (!empty($search_term)) {
        $test_search_sql .= " AND (lt.test_name LIKE ? OR lt.test_code LIKE ? OR bi.item_name LIKE ?)";
        $search_param = "%" . $search_term . "%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= "sss";
    }
    
    if (!empty($search_category)) {
        $test_search_sql .= " AND lt.category_id = ?";
        $params[] = $search_category;
        $types .= "i";
    }
    
    $test_search_sql .= " ORDER BY lt.category_id, lt.test_name LIMIT 50";
    
    $test_search_stmt = $mysqli->prepare($test_search_sql);
    
    if (!empty($params)) {
        $test_search_stmt->bind_param($types, ...$params);
    }
    
    $test_search_stmt->execute();
    $tests_result = $test_search_stmt->get_result();
    $tests = $tests_result->fetch_all(MYSQLI_ASSOC);
    
    // AUDIT LOG: Search results for lab tests
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'SEARCH_RESULTS',
        'module'      => 'Lab Orders',
        'table_name'  => 'lab_tests',
        'entity_type' => 'search_results',
        'record_id'   => null,
        'patient_id'  => $patient_info['patient_id'],
        'visit_id'    => $visit_id,
        'description' => "Search for lab tests returned " . count($tests) . " results",
        'status'      => 'SUCCESS',
        'old_values'  => null,
        'new_values'  => null
    ]);
}

// Get test categories for filter
$categories_sql = "SELECT * FROM lab_test_categories 
                  WHERE is_active = 1 
                  ORDER BY category_name";
$categories_result = $mysqli->query($categories_sql);
$categories = $categories_result->fetch_all(MYSQLI_ASSOC);

// Get patient full name (corrected field names)
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

// Get visit number (using correct field names)
$visit_number = $visit_info['visit_number'];

// For IPD visits, also show admission number
if ($visit_type == 'IPD' && isset($visit_info['admission_number'])) {
    $visit_number .= ' / ' . $visit_info['admission_number'];
}

// Check if there's an active lab order
$active_order = getActiveLabOrder($mysqli, $visit_id, $patient_info['patient_id']);
$has_active_order = !empty($active_order);

// Function to get order status badge
function getLabOrderStatusBadge($status) {
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
        case 'Sample Collected':
            $badge_class = 'secondary';
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
            <i class="fas fa-fw fa-flask mr-2"></i>Laboratory Orders: <?php echo htmlspecialchars($patient_info['patient_mrn']); ?>
            <span class="badge badge-light ml-2"><?php echo count($lab_orders); ?> Orders</span>
            <?php if ($has_active_order): ?>
                <span class="badge badge-success ml-2">Active Order: #<?php echo $active_order['order_number']; ?></span>
            <?php endif; ?>
        </h3>
        <div class="card-tools">
            <div class="btn-group">
                <button type="button" class="btn btn-light" onclick="window.history.back()">
                    <i class="fas fa-arrow-left mr-2"></i>Back
                </button>
                <button type="button" class="btn btn-success" onclick="window.print()">
                    <i class="fas fa-print mr-2"></i>Print
                </button>
                <a href="/clinic/lab/index.php?visit_id=<?php echo $visit_id; ?>" class="btn btn-info" target="_blank">
                    <i class="fas fa-microscope mr-2"></i>Lab Dashboard
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
                                    $total_tests = 0;
                                    $total_orders = count($lab_orders);
                                    $pending_orders = 0;
                                    
                                    foreach ($lab_orders as $order) {
                                        if ($order['lab_order_status'] == 'Pending') {
                                            $pending_orders++;
                                        }
                                        $total_tests += intval($order['test_count']);
                                    }
                                    ?>
                                    <span class="h5">
                                        <i class="fas fa-flask text-primary mr-1"></i>
                                        <span class="badge badge-light"><?php echo $total_orders; ?> Orders</span>
                                    </span>
                                    <br>
                                    <span class="h5">
                                        <i class="fas fa-vial text-success mr-1"></i>
                                        <span class="badge badge-light"><?php echo $total_tests; ?> Tests</span>
                                    </span>
                                    <br>
                                    <span class="h5">
                                        <i class="fas fa-clock text-warning mr-1"></i>
                                        <span class="badge badge-light"><?php echo $pending_orders; ?> Pending</span>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Test Search and Order Form -->
            <div class="col-md-5">
                <div class="card">
                    <div class="card-header bg-success py-2">
                        <h4 class="card-title mb-0 text-white">
                            <i class="fas fa-search mr-2"></i>Search and Order Tests
                            <?php if ($has_active_order): ?>
                                <small class="float-right">Adding to Order #<?php echo $active_order['order_number']; ?></small>
                            <?php endif; ?>
                        </h4>
                    </div>
                    <div class="card-body">
                        <!-- Active Order Warning -->
                        <?php if ($has_active_order): ?>
                            <div class="alert alert-info alert-dismissible fade show">
                                <i class="fas fa-info-circle mr-2"></i>
                                <strong>Active lab order found!</strong> New tests will be added to existing order #<?php echo $active_order['order_number']; ?>.
                                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                    <span aria-hidden="true">&times;</span>
                                </button>
                            </div>
                        <?php endif; ?>

                        <!-- Order Type Selection -->
                        <div class="form-group">
                            <label for="lab_order_type">Order Type</label>
                            <select class="form-control" id="lab_order_type" name="lab_order_type">
                                <option value="Routine" selected>Routine Lab Order</option>
                                <option value="Emergency">Emergency Lab Order</option>
                                <option value="Follow-up">Follow-up Lab Order</option>
                            </select>
                        </div>

                        <!-- Test Search Form -->
                        <form method="POST" id="testSearchForm">
                            <div class="row">
                                <div class="col-md-8">
                                    <div class="form-group">
                                        <label for="search_term">Search Tests</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control" id="search_term" name="search_term" 
                                                   placeholder="Search by test name or code..."
                                                   value="<?php echo isset($search_term) ? htmlspecialchars($search_term) : ''; ?>">
                                            <div class="input-group-append">
                                                <button type="submit" name="search_tests" class="btn btn-primary">
                                                    <i class="fas fa-search"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="search_category">Category</label>
                                        <select class="form-control" id="search_category" name="search_category">
                                            <option value="">All Categories</option>
                                            <?php foreach ($categories as $cat): ?>
                                                <option value="<?php echo htmlspecialchars($cat['category_id']); ?>"
                                                    <?php echo (isset($search_category) && $search_category == $cat['category_id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($cat['category_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </form>

                        <!-- Test Search Results -->
                        <?php if (isset($_POST['search_tests']) && !empty($tests)): ?>
                            <div class="mt-3">
                                <h6 class="text-muted">Search Results (<?php echo count($tests); ?> found):</h6>
                                <div style="max-height: 300px; overflow-y: auto;">
                                    <form method="POST" id="testSelectionForm">
                                        <table class="table table-sm table-hover">
                                            <thead class="bg-light">
                                                <tr>
                                                    <th width="5%"></th>
                                                    <th>Test Name</th>
                                                    <th width="25%">Category</th>
                                                    <th width="15%" class="text-right">Price</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php 
                                                foreach ($tests as $test): 
                                                    // Check if test already exists in active order
                                                    $already_in_order = false;
                                                    if ($has_active_order) {
                                                        $already_in_order = testExistsInOrder($mysqli, $active_order['lab_order_id'], $test['test_id']);
                                                    }
                                                ?>
                                                    <tr class="<?php echo $already_in_order ? 'table-secondary' : ''; ?>">
                                                        <td>
                                                            <?php if ($already_in_order): ?>
                                                                <span class="text-muted" title="Already in order">
                                                                    <i class="fas fa-check-circle text-success"></i>
                                                                </span>
                                                            <?php else: ?>
                                                                <input type="checkbox" name="selected_tests[]" 
                                                                       value="<?php echo $test['test_id']; ?>"
                                                                       class="test-checkbox"
                                                                       data-price="<?php echo $test['actual_price'] ?? $test['price'] ?? 0; ?>"
                                                                       data-name="<?php echo htmlspecialchars($test['test_name']); ?>">
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <div class="font-weight-bold small">
                                                                <?php echo htmlspecialchars($test['test_name']); ?>
                                                                <?php if ($already_in_order): ?>
                                                                    <span class="badge badge-success ml-1">Already Ordered</span>
                                                                <?php endif; ?>
                                                            </div>
                                                            <div class="text-muted smaller">
                                                                <small><?php echo htmlspecialchars($test['test_code']); ?></small>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <small><?php echo htmlspecialchars($test['category_name'] ?? 'N/A'); ?></small>
                                                        </td>
                                                        <td class="text-right">
                                                            <span class="badge badge-light">
                                                                $<?php echo number_format($test['actual_price'] ?? $test['price'] ?? 0, 2); ?>
                                                            </span>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                        
                                        <!-- Selected Tests Summary -->
                                        <div id="selectedTestsSummary" class="alert alert-info" style="display: none;">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <strong id="selectedCount">0</strong> test(s) selected
                                                    <br>
                                                    <small>Total: $<span id="selectedTotal">0.00</span></small>
                                                </div>
                                                <button type="button" class="btn btn-sm btn-outline-secondary" 
                                                        onclick="clearSelectedTests()">
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
                                                    <option value="routine" <?php echo ($has_active_order && $active_order['order_priority'] == 'routine') ? 'selected' : ''; ?>>Routine</option>
                                                    <option value="urgent" <?php echo ($has_active_order && $active_order['order_priority'] == 'urgent') ? 'selected' : ''; ?>>Urgent</option>
                                                    <option value="stat" <?php echo ($has_active_order && $active_order['order_priority'] == 'stat') ? 'selected' : ''; ?>>STAT (Immediate)</option>
                                                </select>
                                            </div>
                                            
                                            <div class="form-group">
                                                <label for="specimen_type">Specimen Type</label>
                                                <select class="form-control" id="specimen_type" name="specimen_type">
                                                    <option value="">-- Select Specimen --</option>
                                                    <option value="Blood" <?php echo ($has_active_order && $active_order['specimen_type'] == 'Blood') ? 'selected' : ''; ?>>Blood</option>
                                                    <option value="Urine" <?php echo ($has_active_order && $active_order['specimen_type'] == 'Urine') ? 'selected' : ''; ?>>Urine</option>
                                                    <option value="Stool" <?php echo ($has_active_order && $active_order['specimen_type'] == 'Stool') ? 'selected' : ''; ?>>Stool</option>
                                                    <option value="Sputum" <?php echo ($has_active_order && $active_order['specimen_type'] == 'Sputum') ? 'selected' : ''; ?>>Sputum</option>
                                                    <option value="CSF" <?php echo ($has_active_order && $active_order['specimen_type'] == 'CSF') ? 'selected' : ''; ?>>CSF</option>
                                                    <option value="Other" <?php echo ($has_active_order && $active_order['specimen_type'] == 'Other') ? 'selected' : ''; ?>>Other</option>
                                                </select>
                                            </div>
                                            
                                            <div class="form-group">
                                                <label for="clinical_notes">Clinical Notes</label>
                                                <textarea class="form-control" id="clinical_notes" name="clinical_notes" 
                                                          rows="3" placeholder="Clinical indication, suspected diagnosis, etc."><?php echo $has_active_order ? htmlspecialchars($active_order['clinical_notes'] ?? '') : ''; ?></textarea>
                                            </div>
                                            
                                            <div class="form-group">
                                                <label for="instructions">Special Instructions</label>
                                                <textarea class="form-control" id="instructions" name="instructions" 
                                                          rows="2" placeholder="Special handling instructions, fasting requirements, etc."><?php echo $has_active_order ? htmlspecialchars($active_order['instructions'] ?? '') : ''; ?></textarea>
                                            </div>
                                            
                                            <button type="submit" name="create_lab_order" class="btn btn-success btn-block">
                                                <i class="fas fa-plus mr-2"></i>
                                                <?php echo $has_active_order ? 'Add to Existing Order' : 'Create Lab Order'; ?>
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        <?php elseif (isset($_POST['search_tests']) && empty($tests)): ?>
                            <div class="alert alert-warning mt-3">
                                <i class="fas fa-exclamation-triangle mr-2"></i>
                                No tests found matching your search criteria.
                            </div>
                        <?php endif; ?>

                        <!-- Instructions -->
                        <?php if (!isset($_POST['search_tests'])): ?>
                            <div class="text-center py-4">
                                <i class="fas fa-search fa-3x text-muted mb-3"></i>
                                <h6 class="text-muted">Search for tests to begin ordering</h6>
                                <p class="text-muted small">Search by test name, code, or browse by category</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Common Test Panels -->
                <div class="card mt-4">
                    <div class="card-header bg-info py-2">
                        <h6 class="card-title mb-0 text-white">
                            <i class="fas fa-th-list mr-2"></i>Common Test Panels
                        </h6>
                    </div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush">
                            <?php
                            // Define common test panels
                            $common_panels = [
                                'CBC' => ['Complete Blood Count', 'Blood', 'HEMATOLOGY'],
                                'BMP' => ['Basic Metabolic Panel', 'Blood', 'CHEMISTRY'],
                                'CMP' => ['Comprehensive Metabolic Panel', 'Blood', 'CHEMISTRY'],
                                'LFT' => ['Liver Function Tests', 'Blood', 'CHEMISTRY'],
                                'RFT' => ['Renal Function Tests', 'Blood', 'CHEMISTRY'],
                                'LIPID' => ['Lipid Profile', 'Blood', 'CHEMISTRY'],
                                'URINALYSIS' => ['Urinalysis', 'Urine', 'URINE'],
                                'CULTURE' => ['Culture & Sensitivity', 'Various', 'MICROBIOLOGY'],
                            ];
                            
                            foreach ($common_panels as $code => $panel):
                            ?>
                            <a href="#" class="list-group-item list-group-item-action" 
                               onclick="loadCommonPanel('<?php echo $code; ?>')">
                                <div class="d-flex w-100 justify-content-between">
                                    <h6 class="mb-1"><?php echo $panel[0]; ?></h6>
                                    <small class="text-muted"><?php echo $panel[2]; ?></small>
                                </div>
                                <p class="mb-1 small text-muted">
                                    <i class="fas fa-vial mr-1"></i><?php echo $panel[1]; ?>
                                </p>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Existing Lab Orders -->
            <div class="col-md-7">
                <div class="card">
                    <div class="card-header bg-warning py-2">
                        <h4 class="card-title mb-0">
                            <i class="fas fa-list-alt mr-2"></i>Lab Orders List
                            <span class="badge badge-light float-right"><?php echo count($lab_orders); ?></span>
                        </h4>
                    </div>
                    <div class="card-body p-0">
                        <?php if (!empty($lab_orders_with_tests)): ?>
                            <div class="table-responsive">
                                <?php foreach ($lab_orders_with_tests as $order_id => $order_data): 
                                    $order = $order_data['order_info'];
                                    $tests = $order_data['tests'];
                                    $is_active = in_array($order['lab_order_status'], ['Pending', 'Sample Collected', 'In Progress']);
                                    $is_current_active = ($has_active_order && $order_id == $active_order['lab_order_id']);
                                ?>
                                <div class="card card-outline card-<?php echo $is_active ? ($is_current_active ? 'success' : 'warning') : 'secondary'; ?> mb-3 mx-3 mt-3 <?php echo $is_current_active ? 'border-success border-2' : ''; ?>">
                                    <div class="card-header py-2">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <strong>Order #<?php echo htmlspecialchars($order['order_number']); ?></strong>
                                                <?php echo getLabOrderStatusBadge($order['lab_order_status']); ?>
                                                <?php echo getPriorityBadge($order['order_priority']); ?>
                                                <?php if ($is_current_active): ?>
                                                    <span class="badge badge-success ml-1">Current Active Order</span>
                                                <?php endif; ?>
                                                <small class="text-muted ml-2">
                                                    <?php echo date('M j, H:i', strtotime($order['order_date'])); ?>
                                                </small>
                                            </div>
                                            <div>
                                                <span class="badge badge-light">
                                                    <?php echo count($tests); ?> test<?php echo count($tests) > 1 ? 's' : ''; ?>
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
                                                    <th width="30%">Test Name</th>
                                                    <th width="20%">Status</th>
                                                    <th width="20%">Result</th>
                                                    <th width="15%">Billed</th>
                                                    <th width="15%" class="text-center">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($tests as $test): ?>
                                                    <tr>
                                                        <td>
                                                            <div class="font-weight-bold small">
                                                                <?php echo htmlspecialchars($test['test_name']); ?>
                                                            </div>
                                                            <small class="text-muted">
                                                                <?php echo htmlspecialchars($test['test_code']); ?>
                                                            </small>
                                                        </td>
                                                        <td>
                                                            <?php 
                                                            $test_status = $test['status'];
                                                            $status_class = $test_status == 'pending' ? 'warning' : 
                                                                           ($test_status == 'completed' ? 'success' : 'info');
                                                            ?>
                                                            <span class="badge badge-<?php echo $status_class; ?>">
                                                                <?php echo ucfirst($test_status); ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <?php if (!empty($test['result_value'])): ?>
                                                                <span class="font-weight-bold">
                                                                    <?php echo htmlspecialchars($test['result_value']); ?>
                                                                </span>
                                                                <?php if ($test['result_unit']): ?>
                                                                    <small class="text-muted"><?php echo htmlspecialchars($test['result_unit']); ?></small>
                                                                <?php endif; ?>
                                                                <?php if ($test['abnormal_flag']): ?>
                                                                    <span class="badge badge-danger ml-1"><?php echo strtoupper($test['abnormal_flag']); ?></span>
                                                                <?php endif; ?>
                                                            <?php else: ?>
                                                                <span class="text-muted">Pending</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php if ($test['billed_amount']): ?>
                                                                <span class="badge badge-light">
                                                                    $<?php echo number_format($test['billed_amount'], 2); ?>
                                                                </span>
                                                            <?php else: ?>
                                                                <span class="text-muted">Not billed</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="text-center">
                                                            <div class="btn-group btn-group-sm">
                                                                <button type="button" class="btn btn-info" 
                                                                        onclick="viewTestDetails(<?php echo htmlspecialchars(json_encode($test)); ?>, <?php echo htmlspecialchars(json_encode($order)); ?>)"
                                                                        title="View Details">
                                                                    <i class="fas fa-eye"></i>
                                                                </button>
                                                                <?php if ($is_active): ?>
                                                                    <button type="button" class="btn btn-warning" 
                                                                            onclick="editTestOrder(<?php echo htmlspecialchars(json_encode($test)); ?>, <?php echo htmlspecialchars(json_encode($order)); ?>)"
                                                                            title="Edit Test">
                                                                        <i class="fas fa-edit"></i>
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
                                                <?php if ($order['specimen_type']): ?>
                                                    <small class="text-muted ml-3">
                                                        <i class="fas fa-vial mr-1"></i>
                                                        <?php echo htmlspecialchars($order['specimen_type']); ?>
                                                    </small>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <?php if ($is_active): ?>
                                                    <?php if ($is_current_active): ?>
                                                        <span class="badge badge-success mr-2">Currently Adding Tests</span>
                                                    <?php endif; ?>
                                                    <form method="POST" style="display: inline;" 
                                                          onsubmit="return confirm('Cancel this entire lab order?');">
                                                        <input type="hidden" name="lab_order_id" value="<?php echo $order_id; ?>">
                                                        <button type="submit" name="cancel_lab_order" class="btn btn-sm btn-danger" 
                                                                title="Cancel Order">
                                                            <i class="fas fa-times mr-1"></i>Cancel Order
                                                        </button>
                                                    </form>
                                                    <button type="button" class="btn btn-sm btn-primary ml-2" 
                                                            onclick="printLabOrder(<?php echo $order_id; ?>)"
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
                                <i class="fas fa-flask fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">No Lab Orders Yet</h5>
                                <p class="text-muted">Search and create lab orders using the form on the left.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Test Details Modal -->
<div class="modal fade" id="testDetailsModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Test Details</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body" id="testDetailsContent">
                <!-- Content will be loaded dynamically -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="printTestDetails()">
                    <i class="fas fa-print mr-2"></i>Print
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

    // Test checkbox handling
    $('.test-checkbox').change(function() {
        updateSelectedTestsSummary();
    });

    // Form validation
    $('#testSelectionForm').validate({
        rules: {
            'selected_tests[]': {
                required: true
            },
            specimen_type: {
                required: true
            }
        }
    });

    // Auto-focus search field
    $('#search_term').focus();
});

function updateSelectedTestsSummary() {
    var selectedTests = $('.test-checkbox:checked');
    var count = selectedTests.length;
    var total = 0;
    
    selectedTests.each(function() {
        total += parseFloat($(this).data('price')) || 0;
    });
    
    if (count > 0) {
        $('#selectedCount').text(count);
        $('#selectedTotal').text(total.toFixed(2));
        $('#selectedTestsSummary').show();
        $('#orderDetailsForm').show();
    } else {
        $('#selectedTestsSummary').hide();
        $('#orderDetailsForm').hide();
    }
}

function clearSelectedTests() {
    $('.test-checkbox').prop('checked', false);
    updateSelectedTestsSummary();
}

function loadCommonPanel(panelCode) {
    // This would typically make an AJAX call to load tests for the panel
    alert('Loading ' + panelCode + ' panel... This would load pre-defined test sets.');
    // For now, just clear and refocus search
    $('#search_term').val(panelCode).focus();
    $('#testSearchForm').submit();
}

function viewTestDetails(test, order) {
    const modalContent = document.getElementById('testDetailsContent');
    
    let html = `
        <div class="row">
            <div class="col-md-12">
                <div class="test-header text-center mb-4">
                    <h4>Test Details</h4>
                    <h5>Order #${order.order_number}</h5>
                    <hr>
                </div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-6">
                <div class="card mb-3">
                    <div class="card-header bg-light py-2">
                        <h6 class="mb-0"><i class="fas fa-vial mr-2"></i>Test Information</h6>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm table-borderless">
                            <tr>
                                <th width="40%">Test Name:</th>
                                <td><strong>${test.test_name}</strong></td>
                            </tr>
                            <tr>
                                <th>Test Code:</th>
                                <td>${test.test_code}</td>
                            </tr>
                            <tr>
                                <th>Method:</th>
                                <td>${test.method || 'N/A'}</td>
                            </tr>
                            <tr>
                                <th>Specimen:</th>
                                <td>${test.specimen_type || 'N/A'}</td>
                            </tr>
                            <tr>
                                <th>Container:</th>
                                <td>${test.container_type || 'N/A'}</td>
                            </tr>
                            <tr>
                                <th>Volume:</th>
                                <td>${test.required_volume || 'N/A'}</td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card mb-3">
                    <div class="card-header bg-light py-2">
                        <h6 class="mb-0"><i class="fas fa-clipboard-check mr-2"></i>Test Results</h6>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm table-borderless">
                            <tr>
                                <th width="40%">Status:</th>
                                <td>
                                    <span class="badge badge-${getStatusClass(test.status)}">
                                        ${test.status.toUpperCase()}
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <th>Result:</th>
                                <td>
                                    ${test.result_value ? 
                                        `<span class="font-weight-bold">${test.result_value}</span> ${test.result_unit ? test.result_unit : ''}` : 
                                        '<span class="text-muted">Pending</span>'}
                                </td>
                            </tr>
                            ${test.abnormal_flag ? `
                            <tr>
                                <th>Flag:</th>
                                <td><span class="badge badge-danger">${test.abnormal_flag.toUpperCase()}</span></td>
                            </tr>
                            ` : ''}
                            ${test.result_date ? `
                            <tr>
                                <th>Result Date:</th>
                                <td>${new Date(test.result_date).toLocaleString()}</td>
                            </tr>
                            ` : ''}
                            ${test.performed_by_name ? `
                            <tr>
                                <th>Performed By:</th>
                                <td>${test.performed_by_name}</td>
                            </tr>
                            ` : ''}
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        ${test.reference_range ? `
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h6 class="mb-0"><i class="fas fa-chart-line mr-2"></i>Reference Range</h6>
                    </div>
                    <div class="card-body">
                        <pre class="mb-0" style="white-space: pre-wrap;">${test.reference_range}</pre>
                    </div>
                </div>
            </div>
        </div>
        ` : ''}
        
        ${test.instructions ? `
        <div class="row mt-3">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h6 class="mb-0"><i class="fas fa-info-circle mr-2"></i>Instructions</h6>
                    </div>
                    <div class="card-body">
                        <pre class="mb-0" style="white-space: pre-wrap;">${test.instructions}</pre>
                    </div>
                </div>
            </div>
        </div>
        ` : ''}
    `;
    
    modalContent.innerHTML = html;
    $('#testDetailsModal').modal('show');
    
    function getStatusClass(status) {
        switch(status) {
            case 'pending': return 'warning';
            case 'completed': return 'success';
            case 'in_progress': return 'info';
            default: return 'light';
        }
    }
}

function editTestOrder(test, order) {
    // Implementation for editing test order
    alert('Edit test order functionality would go here');
}

function printLabOrder(orderId) {
    const printWindow = window.open(`/clinic/lab/print_order.php?order_id=${orderId}`, '_blank');
    printWindow.focus();
}

function viewFullReport(orderId) {
    const printWindow = window.open(`/clinic/doctor/lab_report.php?order_id=${orderId}`, '_blank');
    printWindow.focus();
}

function printTestDetails() {
    const modalContent = document.getElementById('testDetailsContent');
    const printWindow = window.open('', '_blank');
    
    printWindow.document.write(`
        <html>
        <head>
            <title>Test Details - ${$('#full_name').text()}</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                .test-header { text-align: center; margin-bottom: 30px; }
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
        clearSelectedTests();
    }
});

// Auto-refresh orders every 30 seconds
//setInterval(function() {
  //  if (!$('#testDetailsModal').is(':visible')) {
 //       location.reload();
 //   }
//}, 30000);
</script>

<style>
/* Custom styles for lab orders page */
.card-outline-warning {
    border-color: #ffc107 !important;
}

.card-outline-success {
    border-color: #28a745 !important;
}

.card-outline-secondary {
    border-color: #6c757d !important;
}

.test-checkbox {
    transform: scale(1.2);
}

.list-group-item:hover {
    background-color: #f8f9fa;
    cursor: pointer;
}

.smaller {
    font-size: 0.85em;
}

.border-success.border-2 {
    border-width: 2px !important;
}

/* Print styles */
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
}
</style>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
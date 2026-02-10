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
    'module'      => 'Doctor Orders',
    'table_name'  => 'N/A',
    'entity_type' => 'page',
    'record_id'   => null,
    'patient_id'  => null,
    'visit_id'    => null,
    'description' => "Accessed doctor_orders.php",
    'status'      => 'ATTEMPT',
    'old_values'  => null,
    'new_values'  => null
]);

// Get visit_id from URL
$visit_id = isset($_GET['visit_id']) ? intval($_GET['visit_id']) : 0;

if (!$visit_id) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "No visit specified!";
    
    // AUDIT LOG: No visit specified
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'ACCESS_DENIED',
        'module'      => 'Doctor Orders',
        'table_name'  => 'visits',
        'entity_type' => 'visit',
        'record_id'   => null,
        'patient_id'  => null,
        'visit_id'    => null,
        'description' => "Attempted to access doctor_orders.php without visit ID",
        'status'      => 'FAILED',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    header("Location: doctor_dashboard.php");
    exit;
}

// Fetch visit details using the correct table structure
$visit_sql = "SELECT v.*, 
                     p.patient_id, 
                     p.first_name as patient_first_name, 
                     p.last_name as patient_last_name,
                     p.middle_name as patient_middle_name,
                     p.patient_mrn,
                     p.date_of_birth,
                     p.sex as patient_gender,
                     p.blood_group,
                     u.user_id, 
                     u.user_name as doctor_name,
                     d.department_name,
                     ia.admission_number,
                     w.ward_name,
                     b.bed_number
              FROM visits v
              JOIN patients p ON v.patient_id = p.patient_id
              LEFT JOIN users u ON v.attending_provider_id = u.user_id
              LEFT JOIN departments d ON v.department_id = d.department_id
              LEFT JOIN ipd_admissions ia ON v.visit_id = ia.visit_id
              LEFT JOIN wards w ON ia.ward_id = w.ward_id
              LEFT JOIN beds b ON ia.bed_id = b.bed_id
              WHERE v.visit_id = ?";
              
$visit_stmt = $mysqli->prepare($visit_sql);
$visit_stmt->bind_param("i", $visit_id);
$visit_stmt->execute();
$visit_result = $visit_stmt->get_result();
$visit = $visit_result->fetch_assoc();

if (!$visit) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Visit not found!";
    
    // AUDIT LOG: Visit not found
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'ACCESS_DENIED',
        'module'      => 'Doctor Orders',
        'table_name'  => 'visits',
        'entity_type' => 'visit',
        'record_id'   => $visit_id,
        'patient_id'  => null,
        'visit_id'    => $visit_id,
        'description' => "Attempted to access doctor orders for visit ID " . $visit_id . " but visit not found",
        'status'      => 'FAILED',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    header("Location: doctor_dashboard.php");
    exit;
}

$patient_id = $visit['patient_id'];

// AUDIT LOG: Successful access to doctor orders page
audit_log($mysqli, [
    'user_id'     => $_SESSION['user_id'] ?? null,
    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
    'action'      => 'VIEW',
    'module'      => 'Doctor Orders',
    'table_name'  => 'visits',
    'entity_type' => 'visit',
    'record_id'   => $visit_id,
    'patient_id'  => $patient_id,
    'visit_id'    => $visit_id,
    'description' => "Accessed doctor orders page for visit ID " . $visit_id . " (Patient MRN: " . $visit['patient_mrn'] . ")",
    'status'      => 'SUCCESS',
    'old_values'  => null,
    'new_values'  => null
]);

// Get active pending bill for this visit
$bill_sql = "SELECT pb.*, 
                    pl.price_list_name,
                    u.user_name as created_by_name
             FROM pending_bills pb
             LEFT JOIN price_lists pl ON pb.price_list_id = pl.price_list_id
             LEFT JOIN users u ON pb.created_by = u.user_id
             WHERE pb.visit_id = ? 
             AND pb.bill_status != 'cancelled'
             AND pb.invoice_id IS NULL
             ORDER BY pb.created_at DESC
             LIMIT 1";
             
$bill_stmt = $mysqli->prepare($bill_sql);
$bill_stmt->bind_param("i", $visit_id);
$bill_stmt->execute();
$bill_result = $bill_stmt->get_result();
$pending_bill = $bill_result->fetch_assoc();

// If no pending bill exists, create one automatically
if (!$pending_bill) {
    $pending_bill = createPendingBill($mysqli, $session_user_id, $visit_id, $patient_id);
}

$pending_bill_id = $pending_bill['pending_bill_id'];

// AUDIT LOG: Retrieved or created pending bill
audit_log($mysqli, [
    'user_id'     => $_SESSION['user_id'] ?? null,
    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
    'action'      => 'RETRIEVE',
    'module'      => 'Doctor Orders',
    'table_name'  => 'pending_bills',
    'entity_type' => 'pending_bill',
    'record_id'   => $pending_bill_id,
    'patient_id'  => $patient_id,
    'visit_id'    => $visit_id,
    'description' => "Retrieved pending bill for orders. Bill ID: " . $pending_bill_id,
    'status'      => 'SUCCESS',
    'old_values'  => null,
    'new_values'  => null
]);

// Fetch bill items
$items_sql = "SELECT pbi.*, 
                     bi.item_code, bi.item_name, bi.item_description, bi.item_type,
                     pli.price_list_item_id,
                     pli.price as list_price
              FROM pending_bill_items pbi
              JOIN billable_items bi ON pbi.billable_item_id = bi.billable_item_id
              LEFT JOIN price_list_items pli ON pbi.price_list_item_id = pli.price_list_item_id
              WHERE pbi.pending_bill_id = ?
              AND pbi.is_cancelled = 0
              ORDER BY pbi.created_at ASC";
              
$items_stmt = $mysqli->prepare($items_sql);
$items_stmt->bind_param("i", $pending_bill_id);
$items_stmt->execute();
$items_result = $items_stmt->get_result();
$bill_items = [];

while ($row = $items_result->fetch_assoc()) {
    $bill_items[] = $row;
}

// AUDIT LOG: Retrieved bill items
audit_log($mysqli, [
    'user_id'     => $_SESSION['user_id'] ?? null,
    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
    'action'      => 'RETRIEVE',
    'module'      => 'Doctor Orders',
    'table_name'  => 'pending_bill_items',
    'entity_type' => 'order_items',
    'record_id'   => null,
    'patient_id'  => $patient_id,
    'visit_id'    => $visit_id,
    'description' => "Retrieved " . count($bill_items) . " order items for pending bill " . $pending_bill_id,
    'status'      => 'SUCCESS',
    'old_values'  => null,
    'new_values'  => null
]);

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // AUDIT LOG: Form submission received
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'FORM_SUBMIT',
        'module'      => 'Doctor Orders',
        'table_name'  => 'N/A',
        'entity_type' => 'form',
        'record_id'   => null,
        'patient_id'  => $patient_id,
        'visit_id'    => $visit_id,
        'description' => "Form submission received on doctor orders page. Action: " . ($_POST['action'] ?? 'unknown'),
        'status'      => 'ATTEMPT',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_item':
                handleAddItem($mysqli, $session_user_id, $pending_bill_id, $visit_id);
                break;
                
            case 'remove_item':
                handleRemoveItem($mysqli, $session_user_id);
                break;
                
            case 'update_item':
                handleUpdateItem($mysqli, $session_user_id);
                break;
                
            case 'search_items':
                $search_term = $_POST['search_term'] ?? '';
                $item_type = $_POST['item_type'] ?? 'all';
                
                // AUDIT LOG: Item search
                audit_log($mysqli, [
                    'user_id'     => $_SESSION['user_id'] ?? null,
                    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                    'action'      => 'SEARCH',
                    'module'      => 'Doctor Orders',
                    'table_name'  => 'billable_items',
                    'entity_type' => 'search',
                    'record_id'   => null,
                    'patient_id'  => $patient_id,
                    'visit_id'    => $visit_id,
                    'description' => "Searched for items in doctor orders. Term: " . $search_term . ", Type: " . $item_type,
                    'status'      => 'SUCCESS',
                    'old_values'  => null,
                    'new_values'  => null
                ]);
                
                header("Location: doctor_orders.php?visit_id=" . $visit_id . "&search=" . urlencode($search_term) . "&item_type=" . $item_type);
                exit;
                break;
                
            case 'prescribe_medication':
                handlePrescribeMedication($mysqli, $session_user_id, $visit_id, $patient_id);
                break;
                
            default:
                // AUDIT LOG: Unknown action
                audit_log($mysqli, [
                    'user_id'     => $_SESSION['user_id'] ?? null,
                    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                    'action'      => 'UNKNOWN_ACTION',
                    'module'      => 'Doctor Orders',
                    'table_name'  => 'N/A',
                    'entity_type' => 'form',
                    'record_id'   => null,
                    'patient_id'  => $patient_id,
                    'visit_id'    => $visit_id,
                    'description' => "Unknown form action received: " . $_POST['action'],
                    'status'      => 'WARNING',
                    'old_values'  => null,
                    'new_values'  => null
                ]);
        }
    }
}

// Search for billable items
$search_results = [];
$search_term = $_GET['search'] ?? '';
$search_item_type = $_GET['item_type'] ?? 'all';

if ($search_term) {
    // AUDIT LOG: Search performed via GET
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'SEARCH_VIA_GET',
        'module'      => 'Doctor Orders',
        'table_name'  => 'billable_items',
        'entity_type' => 'search',
        'record_id'   => null,
        'patient_id'  => $patient_id,
        'visit_id'    => $visit_id,
        'description' => "Search performed via URL parameters. Term: " . $search_term . ", Type: " . $search_item_type,
        'status'      => 'SUCCESS',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    $search_results = searchBillableItems($mysqli, $search_term, $search_item_type);
}

// Fetch doctor's orders history
$orders_history = getDoctorOrdersHistory($mysqli, $visit_id);

// Calculate patient age
function calculateAge($dob) {
    if (!$dob) return 'N/A';
    $birthDate = new DateTime($dob);
    $today = new DateTime();
    $age = $today->diff($birthDate);
    return $age->y;
}

// Functions (updated with comprehensive logging)
function createPendingBill($mysqli, $user_id, $visit_id, $patient_id) {
    // AUDIT LOG: Starting pending bill creation
    audit_log($mysqli, [
        'user_id'     => $user_id,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'CREATE_PENDING_BILL_START',
        'module'      => 'Doctor Orders',
        'table_name'  => 'pending_bills',
        'entity_type' => 'pending_bill',
        'record_id'   => null,
        'patient_id'  => $patient_id,
        'visit_id'    => $visit_id,
        'description' => "Starting creation of pending bill for doctor orders",
        'status'      => 'ATTEMPT',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    mysqli_begin_transaction($mysqli);
    
    try {
        // Get default price list
        $price_list_sql = "SELECT price_list_id FROM price_lists WHERE is_default = 1 LIMIT 1";
        $price_list_result = $mysqli->query($price_list_sql);
        $price_list = $price_list_result->fetch_assoc();
        $price_list_id = $price_list['price_list_id'] ?? null;
        
        // Generate bill number
        $bill_number = generateBillNumber($mysqli);
        
        $sql = "INSERT INTO pending_bills 
                (bill_number, visit_id, patient_id, price_list_id,
                 bill_status, is_finalized, created_by)
                VALUES (?, ?, ?, ?, 'draft', 0, ?)";
        
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("siiii", $bill_number, $visit_id, $patient_id, $price_list_id, $user_id);
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to create pending bill: " . $mysqli->error);
        }
        
        $pending_bill_id = $mysqli->insert_id;
        
        // Get the created bill
        $get_sql = "SELECT pb.*, 
                           pl.price_list_name,
                           u.user_name as created_by_name
                    FROM pending_bills pb
                    LEFT JOIN price_lists pl ON pb.price_list_id = pl.price_list_id
                    LEFT JOIN users u ON pb.created_by = u.user_id
                    WHERE pb.pending_bill_id = ?";
        
        $get_stmt = $mysqli->prepare($get_sql);
        $get_stmt->bind_param("i", $pending_bill_id);
        $get_stmt->execute();
        $result = $get_stmt->get_result();
        $pending_bill = $result->fetch_assoc();
        
        // AUDIT LOG: Pending bill created successfully
        audit_log($mysqli, [
            'user_id'     => $user_id,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'CREATE',
            'module'      => 'Doctor Orders',
            'table_name'  => 'pending_bills',
            'entity_type' => 'pending_bill',
            'record_id'   => $pending_bill_id,
            'patient_id'  => $patient_id,
            'visit_id'    => $visit_id,
            'description' => "Created pending bill for doctor orders. Bill #: " . $bill_number,
            'status'      => 'SUCCESS',
            'old_values'  => null,
            'new_values'  => [
                'bill_number' => $bill_number,
                'visit_id' => $visit_id,
                'patient_id' => $patient_id,
                'price_list_id' => $price_list_id,
                'bill_status' => 'draft',
                'is_finalized' => 0
            ]
        ]);
        
        mysqli_commit($mysqli);
        
        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "New billing session started!";
        
        return $pending_bill;
        
    } catch (Exception $e) {
        mysqli_rollback($mysqli);
        
        // AUDIT LOG: Failed pending bill creation
        audit_log($mysqli, [
            'user_id'     => $user_id,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'CREATE',
            'module'      => 'Doctor Orders',
            'table_name'  => 'pending_bills',
            'entity_type' => 'pending_bill',
            'record_id'   => null,
            'patient_id'  => $patient_id,
            'visit_id'    => $visit_id,
            'description' => "Failed to create pending bill for doctor orders. Error: " . $e->getMessage(),
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => null
        ]);
        
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error: " . $e->getMessage();
        return null;
    }
}

function generateBillNumber($mysqli) {
    $year = date('Y');
    $month = date('m');
    
    $count_sql = "SELECT COUNT(*) as count FROM pending_bills 
                  WHERE YEAR(created_at) = ? AND MONTH(created_at) = ?";
    $count_stmt = $mysqli->prepare($count_sql);
    $count_stmt->bind_param("ii", $year, $month);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $count_row = $count_result->fetch_assoc();
    
    $sequence = $count_row['count'] + 1;
    return 'PB-' . $year . $month . '-' . str_pad($sequence, 4, '0', STR_PAD_LEFT);
}

function handleAddItem($mysqli, $user_id, $pending_bill_id, $visit_id) {
    // AUDIT LOG: Starting item addition
    audit_log($mysqli, [
        'user_id'     => $user_id,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'ADD_ITEM_START',
        'module'      => 'Doctor Orders',
        'table_name'  => 'pending_bill_items',
        'entity_type' => 'order_item',
        'record_id'   => null,
        'patient_id'  => null,
        'visit_id'    => $visit_id,
        'description' => "Starting to add order item to pending bill " . $pending_bill_id,
        'status'      => 'ATTEMPT',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        
        // AUDIT LOG: CSRF validation failed
        audit_log($mysqli, [
            'user_id'     => $user_id,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'ADD_ITEM_CSRF_FAIL',
            'module'      => 'Doctor Orders',
            'table_name'  => 'pending_bill_items',
            'entity_type' => 'order_item',
            'record_id'   => null,
            'patient_id'  => null,
            'visit_id'    => $visit_id,
            'description' => "CSRF token validation failed for adding order item",
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => null
        ]);
        
        return false;
    }
    
    $billable_item_id = intval($_POST['billable_item_id'] ?? 0);
    $quantity = floatval($_POST['quantity'] ?? 1);
    $price_list_item_id = intval($_POST['price_list_item_id'] ?? 0);
    $unit_price = floatval($_POST['unit_price'] ?? 0);
    $instructions = $_POST['instructions'] ?? '';
    
    if (!$billable_item_id || $quantity <= 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid item or quantity";
        
        // AUDIT LOG: Invalid input data
        audit_log($mysqli, [
            'user_id'     => $user_id,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'ADD_ITEM_INVALID_DATA',
            'module'      => 'Doctor Orders',
            'table_name'  => 'pending_bill_items',
            'entity_type' => 'order_item',
            'record_id'   => null,
            'patient_id'  => null,
            'visit_id'    => $visit_id,
            'description' => "Invalid input data for adding order item. Item ID: " . $billable_item_id . ", Quantity: " . $quantity,
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => null
        ]);
        
        return false;
    }
    
    // Start transaction
    mysqli_begin_transaction($mysqli);
    
    try {
        // Check if bill is finalized
        $check_sql = "SELECT is_finalized, patient_id FROM pending_bills WHERE pending_bill_id = ?";
        $check_stmt = $mysqli->prepare($check_sql);
        $check_stmt->bind_param("i", $pending_bill_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $check_row = $check_result->fetch_assoc();
        
        if ($check_row['is_finalized']) {
            throw new Exception("Cannot add items to finalized bill");
        }
        
        $patient_id = $check_row['patient_id'] ?? null;
        
        // Get billable item details
        $item_sql = "SELECT bi.* FROM billable_items bi WHERE bi.billable_item_id = ?";
        $item_stmt = $mysqli->prepare($item_sql);
        $item_stmt->bind_param("i", $billable_item_id);
        $item_stmt->execute();
        $item_result = $item_stmt->get_result();
        $billable_item = $item_result->fetch_assoc();
        
        if (!$billable_item) {
            throw new Exception("Billable item not found");
        }
        
        // Use custom price if provided, otherwise get from price list or item
        if ($unit_price > 0) {
            $actual_price = $unit_price;
        } elseif ($price_list_item_id) {
            $price_sql = "SELECT unit_price FROM price_list_items WHERE price_list_item_id = ?";
            $price_stmt = $mysqli->prepare($price_sql);
            $price_stmt->bind_param("i", $price_list_item_id);
            $price_stmt->execute();
            $price_result = $price_stmt->get_result();
            $price_data = $price_result->fetch_assoc();
            $actual_price = $price_data['unit_price'] ?? $billable_item['unit_price'];
        } else {
            $actual_price = $billable_item['unit_price'];
        }
        
        // For doctor orders, no discount by default
        $discount_percentage = 0;
        
        // Calculate amounts
        $subtotal = $quantity * $actual_price;
        $discount_amount = 0;
        $taxable_amount = $subtotal - $discount_amount;
        $tax_amount = $billable_item['is_taxable'] ? ($taxable_amount * ($billable_item['tax_rate'] / 100)) : 0;
        $total_amount = $taxable_amount + $tax_amount;
        
        // Check if item already exists in bill (not cancelled)
        $check_item_sql = "SELECT pending_bill_item_id 
                          FROM pending_bill_items 
                          WHERE pending_bill_id = ? 
                          AND billable_item_id = ?
                          AND is_cancelled = 0";
        $check_item_stmt = $mysqli->prepare($check_item_sql);
        $check_item_stmt->bind_param("ii", $pending_bill_id, $billable_item_id);
        $check_item_stmt->execute();
        $check_item_result = $check_item_stmt->get_result();
        
        if ($check_item_result->num_rows > 0) {
            // Update quantity of existing item
            $row = $check_item_result->fetch_assoc();
            
            $update_sql = "UPDATE pending_bill_items 
                          SET item_quantity = item_quantity + ?,
                              notes = CONCAT(notes, '\n', ?),
                              updated_at = NOW()
                          WHERE pending_bill_item_id = ?";
            
            $update_stmt = $mysqli->prepare($update_sql);
            $update_stmt->bind_param("dsi", $quantity, $instructions, $row['pending_bill_item_id']);
            
            if (!$update_stmt->execute()) {
                throw new Exception("Failed to update item: " . $mysqli->error);
            }
            
            $item_id = $row['pending_bill_item_id'];
            $action_type = 'UPDATE';
            $description = "Updated quantity for existing order item";
            
        } else {
            // Insert new item with source_type = 'doctor_order'
            $insert_sql = "INSERT INTO pending_bill_items 
                          (pending_bill_id, billable_item_id, price_list_item_id,
                           item_quantity, unit_price, discount_percentage, discount_amount,
                           tax_percentage, subtotal, tax_amount, total_amount,
                           source_type, source_id, notes, created_by)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'doctor_order', ?, ?, ?)";
            
            $insert_stmt = $mysqli->prepare($insert_sql);
            $insert_stmt->bind_param("iiiidddddddssi", 
                $pending_bill_id, $billable_item_id, $price_list_item_id,
                $quantity, $actual_price, $discount_percentage, $discount_amount,
                $billable_item['tax_rate'], $subtotal, $tax_amount, $total_amount,
                $visit_id, $instructions, $user_id
            );
            
            if (!$insert_stmt->execute()) {
                throw new Exception("Failed to add item: " . $mysqli->error);
            }
            
            $item_id = $mysqli->insert_id;
            $action_type = 'CREATE';
            $description = "Added new order item";
        }
        
        // Update bill totals
        updateBillTotal($mysqli, $pending_bill_id);
        
        // AUDIT LOG: Item added/updated successfully
        audit_log($mysqli, [
            'user_id'     => $user_id,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'ORDER_ITEM_' . $action_type,
            'module'      => 'Doctor Orders',
            'table_name'  => 'pending_bill_items',
            'entity_type' => 'order_item',
            'record_id'   => $item_id,
            'patient_id'  => $patient_id,
            'visit_id'    => $visit_id,
            'description' => $description . ". Item: " . $billable_item['item_name'] . ", Quantity: " . $quantity,
            'status'      => 'SUCCESS',
            'old_values'  => null,
            'new_values'  => [
                'billable_item_id' => $billable_item_id,
                'item_name' => $billable_item['item_name'] ?? 'Unknown',
                'item_type' => $billable_item['item_type'] ?? 'Unknown',
                'quantity' => $quantity,
                'unit_price' => $actual_price,
                'subtotal' => $subtotal,
                'tax_amount' => $tax_amount,
                'total_amount' => $total_amount,
                'source_type' => 'doctor_order',
                'source_id' => $visit_id
            ]
        ]);
        
        mysqli_commit($mysqli);
        
        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Order added successfully!";
        return true;
        
    } catch (Exception $e) {
        mysqli_rollback($mysqli);
        
        // AUDIT LOG: Failed item addition
        audit_log($mysqli, [
            'user_id'     => $user_id,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'ORDER_ITEM_CREATE',
            'module'      => 'Doctor Orders',
            'table_name'  => 'pending_bill_items',
            'entity_type' => 'order_item',
            'record_id'   => null,
            'patient_id'  => $patient_id ?? null,
            'visit_id'    => $visit_id,
            'description' => "Failed to add order item. Error: " . $e->getMessage(),
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => null
        ]);
        
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error: " . $e->getMessage();
        return false;
    }
}

function handleRemoveItem($mysqli, $user_id) {
    // AUDIT LOG: Starting item removal
    audit_log($mysqli, [
        'user_id'     => $user_id,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'REMOVE_ITEM_START',
        'module'      => 'Doctor Orders',
        'table_name'  => 'pending_bill_items',
        'entity_type' => 'order_item',
        'record_id'   => null,
        'patient_id'  => null,
        'visit_id'    => null,
        'description' => "Starting to remove order item",
        'status'      => 'ATTEMPT',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        
        // AUDIT LOG: CSRF validation failed
        audit_log($mysqli, [
            'user_id'     => $user_id,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'REMOVE_ITEM_CSRF_FAIL',
            'module'      => 'Doctor Orders',
            'table_name'  => 'pending_bill_items',
            'entity_type' => 'order_item',
            'record_id'   => null,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "CSRF token validation failed for removing order item",
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => null
        ]);
        
        return false;
    }
    
    $pending_bill_item_id = intval($_POST['pending_bill_item_id'] ?? 0);
    
    if (!$pending_bill_item_id) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Item ID required";
        
        // AUDIT LOG: Missing item ID
        audit_log($mysqli, [
            'user_id'     => $user_id,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'REMOVE_ITEM_MISSING_ID',
            'module'      => 'Doctor Orders',
            'table_name'  => 'pending_bill_items',
            'entity_type' => 'order_item',
            'record_id'   => null,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Missing item ID for removal",
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => null
        ]);
        
        return false;
    }
    
    // Start transaction
    mysqli_begin_transaction($mysqli);
    
    try {
        // Get pending_bill_id before updating
        $get_sql = "SELECT pbi.*, pb.patient_id, pb.visit_id, pb.is_finalized, bi.item_name
                   FROM pending_bill_items pbi
                   JOIN pending_bills pb ON pbi.pending_bill_id = pb.pending_bill_id
                   JOIN billable_items bi ON pbi.billable_item_id = bi.billable_item_id
                   WHERE pending_bill_item_id = ?";
        $get_stmt = $mysqli->prepare($get_sql);
        $get_stmt->bind_param("i", $pending_bill_item_id);
        $get_stmt->execute();
        $get_result = $get_stmt->get_result();
        $item_data = $get_result->fetch_assoc();
        
        if (!$item_data) {
            throw new Exception("Item not found");
        }
        
        if ($item_data['is_finalized']) {
            throw new Exception("Cannot remove items from finalized bill");
        }
        
        $pending_bill_id = $item_data['pending_bill_id'];
        $patient_id = $item_data['patient_id'];
        $visit_id = $item_data['visit_id'];
        $item_name = $item_data['item_name'];
        $old_quantity = $item_data['item_quantity'];
        $old_total = $item_data['total_amount'];
        
        // Mark item as cancelled
        $update_sql = "UPDATE pending_bill_items 
                      SET is_cancelled = 1, 
                          cancelled_at = NOW(), 
                          cancelled_by = ?,
                          updated_at = NOW()
                      WHERE pending_bill_item_id = ?";
        
        $update_stmt = $mysqli->prepare($update_sql);
        $update_stmt->bind_param("ii", $user_id, $pending_bill_item_id);
        
        if ($update_stmt->execute()) {
            // Update bill total
            updateBillTotal($mysqli, $pending_bill_id);
            
            // AUDIT LOG: Item removed successfully
            audit_log($mysqli, [
                'user_id'     => $user_id,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'ORDER_ITEM_REMOVE',
                'module'      => 'Doctor Orders',
                'table_name'  => 'pending_bill_items',
                'entity_type' => 'order_item',
                'record_id'   => $pending_bill_item_id,
                'patient_id'  => $patient_id,
                'visit_id'    => $visit_id,
                'description' => "Removed order item: " . $item_name,
                'status'      => 'SUCCESS',
                'old_values'  => [
                    'item_name' => $item_name,
                    'quantity' => $old_quantity,
                    'unit_price' => $item_data['unit_price'] ?? 0,
                    'total_amount' => $old_total,
                    'is_cancelled' => 0
                ],
                'new_values'  => [
                    'is_cancelled' => 1,
                    'cancelled_at' => date('Y-m-d H:i:s'),
                    'cancelled_by' => $user_id
                ]
            ]);
            
            mysqli_commit($mysqli);
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Order removed successfully!";
            return true;
        } else {
            throw new Exception("Failed to remove item");
        }
        
    } catch (Exception $e) {
        mysqli_rollback($mysqli);
        
        // AUDIT LOG: Failed item removal
        audit_log($mysqli, [
            'user_id'     => $user_id,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'ORDER_ITEM_REMOVE',
            'module'      => 'Doctor Orders',
            'table_name'  => 'pending_bill_items',
            'entity_type' => 'order_item',
            'record_id'   => $pending_bill_item_id,
            'patient_id'  => $patient_id ?? null,
            'visit_id'    => $visit_id ?? null,
            'description' => "Failed to remove order item. Error: " . $e->getMessage(),
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => null
        ]);
        
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error: " . $e->getMessage();
        return false;
    }
}

function handleUpdateItem($mysqli, $user_id) {
    // AUDIT LOG: Starting item update
    audit_log($mysqli, [
        'user_id'     => $user_id,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'UPDATE_ITEM_START',
        'module'      => 'Doctor Orders',
        'table_name'  => 'pending_bill_items',
        'entity_type' => 'order_item',
        'record_id'   => null,
        'patient_id'  => null,
        'visit_id'    => null,
        'description' => "Starting to update order item",
        'status'      => 'ATTEMPT',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        
        // AUDIT LOG: CSRF validation failed
        audit_log($mysqli, [
            'user_id'     => $user_id,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'UPDATE_ITEM_CSRF_FAIL',
            'module'      => 'Doctor Orders',
            'table_name'  => 'pending_bill_items',
            'entity_type' => 'order_item',
            'record_id'   => null,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "CSRF token validation failed for updating order item",
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => null
        ]);
        
        return false;
    }
    
    $pending_bill_item_id = intval($_POST['pending_bill_item_id'] ?? 0);
    $quantity = floatval($_POST['quantity'] ?? 1);
    $instructions = $_POST['instructions'] ?? '';
    
    if (!$pending_bill_item_id || $quantity <= 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid item data";
        
        // AUDIT LOG: Invalid input data
        audit_log($mysqli, [
            'user_id'     => $user_id,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'UPDATE_ITEM_INVALID_DATA',
            'module'      => 'Doctor Orders',
            'table_name'  => 'pending_bill_items',
            'entity_type' => 'order_item',
            'record_id'   => null,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Invalid input data for updating order item. Item ID: " . $pending_bill_item_id . ", Quantity: " . $quantity,
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => null
        ]);
        
        return false;
    }
    
    // Start transaction
    mysqli_begin_transaction($mysqli);
    
    try {
        // Get item details and check if bill is finalized
        $get_sql = "SELECT pbi.*, pb.patient_id, pb.visit_id, pb.is_finalized, bi.is_taxable, bi.tax_rate, bi.unit_price, bi.item_name
                   FROM pending_bill_items pbi
                   JOIN pending_bills pb ON pbi.pending_bill_id = pb.pending_bill_id
                   JOIN billable_items bi ON pbi.billable_item_id = bi.billable_item_id
                   WHERE pending_bill_item_id = ?";
        $get_stmt = $mysqli->prepare($get_sql);
        $get_stmt->bind_param("i", $pending_bill_item_id);
        $get_stmt->execute();
        $get_result = $get_stmt->get_result();
        $item_data = $get_result->fetch_assoc();
        
        if (!$item_data) {
            throw new Exception("Item not found");
        }
        
        if ($item_data['is_finalized']) {
            throw new Exception("Cannot update items in finalized bill");
        }
        
        $pending_bill_id = $item_data['pending_bill_id'];
        $patient_id = $item_data['patient_id'];
        $visit_id = $item_data['visit_id'];
        $item_name = $item_data['item_name'];
        $old_quantity = $item_data['item_quantity'];
        $old_total = $item_data['total_amount'];
        $old_notes = $item_data['notes'] ?? '';
        
        // Recalculate amounts with same unit price
        $unit_price = $item_data['unit_price'];
        $subtotal = $quantity * $unit_price;
        $discount_amount = 0;
        $taxable_amount = $subtotal - $discount_amount;
        $tax_amount = $item_data['is_taxable'] ? ($taxable_amount * ($item_data['tax_rate'] / 100)) : 0;
        $total_amount = $taxable_amount + $tax_amount;
        
        // Update item
        $update_sql = "UPDATE pending_bill_items 
                      SET item_quantity = ?,
                          notes = ?,
                          subtotal = ?,
                          tax_amount = ?,
                          total_amount = ?,
                          updated_at = NOW()
                      WHERE pending_bill_item_id = ?";
        
        $update_stmt = $mysqli->prepare($update_sql);
        $update_stmt->bind_param("dsdddi", $quantity, $instructions, $subtotal, 
                                $tax_amount, $total_amount, $pending_bill_item_id);
        
        if ($update_stmt->execute()) {
            // Update bill total
            updateBillTotal($mysqli, $pending_bill_id);
            
            // AUDIT LOG: Item updated successfully
            audit_log($mysqli, [
                'user_id'     => $user_id,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'ORDER_ITEM_UPDATE',
                'module'      => 'Doctor Orders',
                'table_name'  => 'pending_bill_items',
                'entity_type' => 'order_item',
                'record_id'   => $pending_bill_item_id,
                'patient_id'  => $patient_id,
                'visit_id'    => $visit_id,
                'description' => "Updated order item: " . $item_name . ". Quantity: " . $old_quantity . " -> " . $quantity,
                'status'      => 'SUCCESS',
                'old_values'  => [
                    'quantity' => $old_quantity,
                    'notes' => $old_notes,
                    'subtotal' => $item_data['subtotal'] ?? 0,
                    'tax_amount' => $item_data['tax_amount'] ?? 0,
                    'total_amount' => $old_total
                ],
                'new_values'  => [
                    'quantity' => $quantity,
                    'notes' => $instructions,
                    'subtotal' => $subtotal,
                    'tax_amount' => $tax_amount,
                    'total_amount' => $total_amount
                ]
            ]);
            
            mysqli_commit($mysqli);
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Order updated successfully!";
            return true;
        } else {
            throw new Exception("Failed to update item");
        }
        
    } catch (Exception $e) {
        mysqli_rollback($mysqli);
        
        // AUDIT LOG: Failed item update
        audit_log($mysqli, [
            'user_id'     => $user_id,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'ORDER_ITEM_UPDATE',
            'module'      => 'Doctor Orders',
            'table_name'  => 'pending_bill_items',
            'entity_type' => 'order_item',
            'record_id'   => $pending_bill_item_id,
            'patient_id'  => $patient_id ?? null,
            'visit_id'    => $visit_id ?? null,
            'description' => "Failed to update order item. Error: " . $e->getMessage(),
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => null
        ]);
        
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error: " . $e->getMessage();
        return false;
    }
}

function handlePrescribeMedication($mysqli, $user_id, $visit_id, $patient_id) {
    // AUDIT LOG: Starting medication prescription
    audit_log($mysqli, [
        'user_id'     => $user_id,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'PRESCRIBE_MEDICATION_START',
        'module'      => 'Doctor Orders',
        'table_name'  => 'prescriptions',
        'entity_type' => 'prescription',
        'record_id'   => null,
        'patient_id'  => $patient_id,
        'visit_id'    => $visit_id,
        'description' => "Starting to prescribe medication",
        'status'      => 'ATTEMPT',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        
        // AUDIT LOG: CSRF validation failed
        audit_log($mysqli, [
            'user_id'     => $user_id,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'PRESCRIBE_MEDICATION_CSRF_FAIL',
            'module'      => 'Doctor Orders',
            'table_name'  => 'prescriptions',
            'entity_type' => 'prescription',
            'record_id'   => null,
            'patient_id'  => $patient_id,
            'visit_id'    => $visit_id,
            'description' => "CSRF token validation failed for prescribing medication",
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => null
        ]);
        
        return false;
    }
    
    $medication_id = intval($_POST['medication_id'] ?? 0);
    $dosage = $_POST['dosage'] ?? '';
    $frequency = $_POST['frequency'] ?? '';
    $duration = $_POST['duration'] ?? '';
    $instructions = $_POST['instructions'] ?? '';
    $route = $_POST['route'] ?? 'oral';
    
    if (!$medication_id || !$dosage) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Medication and dosage are required";
        
        // AUDIT LOG: Missing required fields
        audit_log($mysqli, [
            'user_id'     => $user_id,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'PRESCRIBE_MEDICATION_MISSING_DATA',
            'module'      => 'Doctor Orders',
            'table_name'  => 'prescriptions',
            'entity_type' => 'prescription',
            'record_id'   => null,
            'patient_id'  => $patient_id,
            'visit_id'    => $visit_id,
            'description' => "Missing required fields for prescribing medication. Medication ID: " . $medication_id . ", Dosage: " . $dosage,
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => null
        ]);
        
        return false;
    }
    
    // AUDIT LOG: Attempt to prescribe medication
    audit_log($mysqli, [
        'user_id'     => $user_id,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'PRESCRIBE_MEDICATION_ATTEMPT',
        'module'      => 'Doctor Orders',
        'table_name'  => 'prescriptions',
        'entity_type' => 'prescription',
        'record_id'   => null,
        'patient_id'  => $patient_id,
        'visit_id'    => $visit_id,
        'description' => "Attempting to prescribe medication. Medication ID: " . $medication_id,
        'status'      => 'ATTEMPT',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    // Start transaction
    mysqli_begin_transaction($mysqli);
    
    try {
        // Check if prescriptions table exists, if not create a simpler version
        $check_table = $mysqli->query("SHOW TABLES LIKE 'prescriptions'");
        if ($check_table->num_rows == 0) {
            // AUDIT LOG: Creating prescriptions table
            audit_log($mysqli, [
                'user_id'     => $user_id,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'SYSTEM',
                'module'      => 'Doctor Orders',
                'table_name'  => 'prescriptions',
                'entity_type' => 'system',
                'record_id'   => null,
                'patient_id'  => $patient_id,
                'visit_id'    => $visit_id,
                'description' => "Prescriptions table not found, attempting to create it",
                'status'      => 'WARNING',
                'old_values'  => null,
                'new_values'  => null
            ]);
            
            // Create simple prescriptions table
            $create_table = "CREATE TABLE IF NOT EXISTS prescriptions (
                prescription_id INT AUTO_INCREMENT PRIMARY KEY,
                visit_id BIGINT(20),
                patient_id BIGINT(20),
                medication_name VARCHAR(255),
                dosage VARCHAR(100),
                frequency VARCHAR(100),
                duration VARCHAR(100),
                route VARCHAR(50),
                instructions TEXT,
                prescribed_by BIGINT(20),
                status ENUM('active', 'completed', 'cancelled') DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )";
            $mysqli->query($create_table);
        }
        
        // Get medication name
        $med_sql = "SELECT item_name FROM billable_items WHERE billable_item_id = ?";
        $med_stmt = $mysqli->prepare($med_sql);
        $med_stmt->bind_param("i", $medication_id);
        $med_stmt->execute();
        $med_result = $med_stmt->get_result();
        $medication = $med_result->fetch_assoc();
        $medication_name = $medication['item_name'] ?? 'Medication';
        
        // Insert into prescriptions table
        $sql = "INSERT INTO prescriptions 
                (visit_id, patient_id, medication_name, dosage, frequency, duration, route, instructions, prescribed_by, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')";
        
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("iissssssi", $visit_id, $patient_id, $medication_name, $dosage, $frequency, $duration, $route, $instructions, $user_id);
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to prescribe medication: " . $mysqli->error);
        }
        
        $prescription_id = $mysqli->insert_id;
        
        // AUDIT LOG: Successful medication prescription
        audit_log($mysqli, [
            'user_id'     => $user_id,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'PRESCRIBE_MEDICATION',
            'module'      => 'Doctor Orders',
            'table_name'  => 'prescriptions',
            'entity_type' => 'prescription',
            'record_id'   => $prescription_id,
            'patient_id'  => $patient_id,
            'visit_id'    => $visit_id,
            'description' => "Prescribed medication: " . $medication_name,
            'status'      => 'SUCCESS',
            'old_values'  => null,
            'new_values'  => [
                'medication_name' => $medication_name,
                'dosage' => $dosage,
                'frequency' => $frequency,
                'duration' => $duration,
                'route' => $route,
                'instructions' => $instructions,
                'prescribed_by' => $user_id,
                'status' => 'active'
            ]
        ]);
        
        mysqli_commit($mysqli);
        
        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Medication prescribed successfully!";
        return true;
        
    } catch (Exception $e) {
        mysqli_rollback($mysqli);
        
        // AUDIT LOG: Failed medication prescription
        audit_log($mysqli, [
            'user_id'     => $user_id,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'PRESCRIBE_MEDICATION',
            'module'      => 'Doctor Orders',
            'table_name'  => 'prescriptions',
            'entity_type' => 'prescription',
            'record_id'   => null,
            'patient_id'  => $patient_id,
            'visit_id'    => $visit_id,
            'description' => "Failed to prescribe medication. Error: " . $e->getMessage(),
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => null
        ]);
        
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error: " . $e->getMessage();
        return false;
    }
}

function searchBillableItems($mysqli, $search_term, $item_type = 'all') {
    $items = [];
    
    $sql = "SELECT bi.* 
            FROM billable_items bi
            WHERE bi.is_active = 1
            AND (bi.item_name LIKE ? OR bi.item_code LIKE ? OR bi.item_description LIKE ?)";
    
    if ($item_type != 'all') {
        $sql .= " AND bi.item_type = ?";
    }
    
    $sql .= " ORDER BY bi.item_name
              LIMIT 20";
    
    $stmt = $mysqli->prepare($sql);
    $search_like = "%" . $search_term . "%";
    
    if ($item_type != 'all') {
        $stmt->bind_param("ssss", $search_like, $search_like, $search_like, $item_type);
    } else {
        $stmt->bind_param("sss", $search_like, $search_like, $search_like);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
    }
    
    // AUDIT LOG: Search results count
    if ($search_term) {
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'SEARCH_RESULTS',
            'module'      => 'Doctor Orders',
            'table_name'  => 'billable_items',
            'entity_type' => 'search',
            'record_id'   => null,
            'patient_id'  => null,
            'visit_id'    => null,
            'description' => "Search for '" . $search_term . "' returned " . count($items) . " results",
            'status'      => 'SUCCESS',
            'old_values'  => null,
            'new_values'  => null
        ]);
    }
    
    return $items;
}

function updateBillTotal($mysqli, $pending_bill_id) {
    // Get totals from items
    $total_sql = "SELECT 
                    SUM(subtotal) as subtotal_amount,
                    SUM(discount_amount) as discount_amount,
                    SUM(tax_amount) as tax_amount,
                    SUM(total_amount) as total_amount
                  FROM pending_bill_items 
                  WHERE pending_bill_id = ? AND is_cancelled = 0";
    
    $total_stmt = $mysqli->prepare($total_sql);
    $total_stmt->bind_param("i", $pending_bill_id);
    $total_stmt->execute();
    $total_result = $total_stmt->get_result();
    $total_row = $total_result->fetch_assoc();
    
    $subtotal_amount = $total_row['subtotal_amount'] ?? 0;
    $item_discount_amount = $total_row['discount_amount'] ?? 0;
    $tax_amount = $total_row['tax_amount'] ?? 0;
    
    // Get bill discount
    $bill_sql = "SELECT discount_amount FROM pending_bills WHERE pending_bill_id = ?";
    $bill_stmt = $mysqli->prepare($bill_sql);
    $bill_stmt->bind_param("i", $pending_bill_id);
    $bill_stmt->execute();
    $bill_result = $bill_stmt->get_result();
    $bill_row = $bill_result->fetch_assoc();
    
    $bill_discount_amount = $bill_row['discount_amount'] ?? 0;
    
    // Calculate total
    $total_amount = $subtotal_amount + $tax_amount - $bill_discount_amount;
    if ($total_amount < 0) $total_amount = 0;
    
    // Update bill totals
    $update_sql = "UPDATE pending_bills 
                  SET subtotal_amount = ?,
                      discount_amount = ?,
                      tax_amount = ?,
                      total_amount = ?,
                      updated_at = NOW()
                  WHERE pending_bill_id = ?";
    
    $update_stmt = $mysqli->prepare($update_sql);
    $update_stmt->bind_param("ddddi", $subtotal_amount, $bill_discount_amount, 
                            $tax_amount, $total_amount, $pending_bill_id);
    $update_stmt->execute();
}

function getDoctorOrdersHistory($mysqli, $visit_id) {
    $history = [];
    
    $sql = "SELECT pbi.*, bi.item_name, bi.item_type, 
                   DATE_FORMAT(pbi.created_at, '%Y-%m-%d %H:%i') as order_date,
                   u.user_name as ordered_by
            FROM pending_bill_items pbi
            JOIN billable_items bi ON pbi.billable_item_id = bi.billable_item_id
            JOIN users u ON pbi.created_by = u.user_id
            WHERE pbi.source_type = 'doctor_order'
            AND pbi.source_id = ?
            AND pbi.is_cancelled = 0
            ORDER BY pbi.created_at DESC
            LIMIT 50";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("i", $visit_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $history[] = $row;
    }
    
    return $history;
}
?>
<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0">
            <i class="fas fa-fw fa-hospital-user mr-2"></i>Doctor Round: <?php echo htmlspecialchars($patient_info['patient_mrn']); ?>
        </h3>
        <div class="card-tools">
            <div class="btn-group">
                <button type="button" class="btn btn-light" onclick="window.history.back()">
                    <i class="fas fa-arrow-left mr-2"></i>Back
                </button>
                <button type="button" class="btn btn-success" onclick="window.print()">
                    <i class="fas fa-print mr-2"></i>Print Round
                </button>
                <a href="/visits/patient_overview.php?visit_id=<?php echo $visit_id; ?>" class="btn btn-info">
                    <i class="fas fa-eye mr-2"></i>Patient Overview
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

        <!-- Patient and Admission Info -->
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
                                                <th class="text-muted">Age/Sex:</th>
                                                <td><span class="badge badge-secondary"><?php echo $age ?: 'N/A'; ?> / <?php echo $patient_info['sex']; ?></span></td>
                                            </tr>
                                        </table>
                                    </div>
                                    <div class="col-md-6">
                                        <table class="table table-sm table-borderless mb-0">
                                            <tr>
                                                <th width="40%" class="text-muted">Admission #:</th>
                                                <td><span class="badge badge-warning"><?php echo htmlspecialchars($ipd_info['admission_number']); ?></span></td>
                                            </tr>
                                            <tr>
                                                <th class="text-muted">Location:</th>
                                                <td><?php echo htmlspecialchars($ward_info['ward_name']); ?> (Bed: <?php echo htmlspecialchars($bed_info['bed_number']); ?>)</td>
                                            </tr>
                                            <tr>
                                                <th class="text-muted">Day #:</th>
                                                <td><span class="badge badge-success">Day <?php echo $days_admitted + 1; ?></span></td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 text-right">
                                <div class="mt-2">
                                    <span class="h5">
                                        <i class="fas fa-user-md text-primary mr-1"></i>
                                        <span class="badge badge-light">Dr. <?php echo htmlspecialchars($visit_info['attending_doctor'] ?? 'N/A'); ?></span>
                                    </span>
                                    <br>
                                    <span class="h6">
                                        <i class="fas fa-user-nurse text-success mr-1"></i>
                                        <span class="badge badge-light">Nurse: <?php echo htmlspecialchars($visit_info['nurse_incharge'] ?? 'N/A'); ?></span>
                                    </span>
                                    <br>
                                    <span class="h6">
                                        <i class="fas fa-clipboard-list text-warning mr-1"></i>
                                        <span class="badge badge-light"><?php echo count($doctor_rounds); ?> Rounds</span>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Doctor Round Form -->
            <div class="col-md-7">
                <div class="card">
                    <div class="card-header bg-success py-2">
                        <h4 class="card-title mb-0 text-white">
                            <i class="fas fa-stethoscope mr-2"></i>Doctor Round Form
                            <?php if ($today_round): ?>
                                <span class="badge badge-light float-right">Update Today's Round</span>
                            <?php else: ?>
                                <span class="badge badge-light float-right">New Round</span>
                            <?php endif; ?>
                        </h4>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="doctorRoundForm">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="round_type">Round Type</label>
                                        <select class="form-control" id="round_type" name="round_type" required>
                                            <option value="MORNING" <?php echo ($today_round['round_type'] ?? '') == 'MORNING' ? 'selected' : ''; ?>>Morning Round</option>
                                            <option value="EVENING" <?php echo ($today_round['round_type'] ?? '') == 'EVENING' ? 'selected' : ''; ?>>Evening Round</option>
                                            <option value="SPECIAL" <?php echo ($today_round['round_type'] ?? '') == 'SPECIAL' ? 'selected' : ''; ?>>Special Round</option>
                                            <option value="OTHER" <?php echo ($today_round['round_type'] ?? '') == 'OTHER' ? 'selected' : ''; ?>>Other</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="round_date">Date</label>
                                        <input type="date" class="form-control" id="round_date" name="round_date" 
                                               value="<?php echo $today; ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="round_time">Time</label>
                                        <input type="time" class="form-control" id="round_time" name="round_time" 
                                               value="<?php echo date('H:i'); ?>" required>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Vital Signs -->
                            <div class="card mb-3">
                                <div class="card-header bg-info py-2">
                                    <h6 class="card-title mb-0 text-white">
                                        <i class="fas fa-heartbeat mr-2"></i>Vital Signs
                                    </h6>
                                </div>
                                <div class="card-body p-2">
                                    <div class="row">
                                        <div class="col-md-2">
                                            <div class="form-group mb-1">
                                                <label class="small">Temp (°C)</label>
                                                <input type="number" step="0.1" class="form-control form-control-sm" 
                                                       name="temperature" placeholder="36.5"
                                                       value="<?php echo $latest_vitals['temperature'] ?? ''; ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="form-group mb-1">
                                                <label class="small">Pulse</label>
                                                <input type="number" class="form-control form-control-sm" 
                                                       name="pulse_rate" placeholder="72"
                                                       value="<?php echo $latest_vitals['pulse_rate'] ?? ''; ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="form-group mb-1">
                                                <label class="small">Resp</label>
                                                <input type="number" class="form-control form-control-sm" 
                                                       name="respiratory_rate" placeholder="16"
                                                       value="<?php echo $latest_vitals['respiratory_rate'] ?? ''; ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-group mb-1">
                                                <label class="small">BP (Sys/Dia)</label>
                                                <div class="input-group input-group-sm">
                                                    <input type="number" class="form-control" 
                                                           name="blood_pressure_systolic" placeholder="120"
                                                           value="<?php echo $latest_vitals['blood_pressure_systolic'] ?? ''; ?>">
                                                    <div class="input-group-prepend input-group-append">
                                                        <span class="input-group-text">/</span>
                                                    </div>
                                                    <input type="number" class="form-control" 
                                                           name="blood_pressure_diastolic" placeholder="80"
                                                           value="<?php echo $latest_vitals['blood_pressure_diastolic'] ?? ''; ?>">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-group mb-1">
                                                <label class="small">SpO₂ (%)</label>
                                                <input type="number" step="0.1" class="form-control form-control-sm" 
                                                       name="oxygen_saturation" placeholder="98"
                                                       value="<?php echo $latest_vitals['oxygen_saturation'] ?? ''; ?>">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- SOAP Format -->
                            <div class="accordion" id="soapAccordion">
                                <!-- Subjective -->
                                <div class="card mb-2">
                                    <div class="card-header p-0" id="headingSubjective">
                                        <h5 class="mb-0">
                                            <button class="btn btn-link btn-block text-left p-3" type="button" 
                                                    data-toggle="collapse" data-target="#collapseSubjective" 
                                                    aria-expanded="true" aria-controls="collapseSubjective">
                                                <i class="fas fa-comment-medical mr-2"></i><strong>S</strong>ubjective
                                                <small class="text-muted ml-3">(Patient's complaints, symptoms)</small>
                                            </button>
                                        </h5>
                                    </div>
                                    <div id="collapseSubjective" class="collapse show" aria-labelledby="headingSubjective" data-parent="#soapAccordion">
                                        <div class="card-body p-3">
                                            <textarea class="form-control" id="subjective_note" name="subjective_note" 
                                                      rows="3" placeholder="Patient reports..."><?php echo $today_round['subjective_note'] ?? ''; ?></textarea>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Objective & Examination -->
                                <div class="card mb-2">
                                    <div class="card-header p-0" id="headingObjective">
                                        <h5 class="mb-0">
                                            <button class="btn btn-link btn-block text-left p-3 collapsed" type="button" 
                                                    data-toggle="collapse" data-target="#collapseObjective" 
                                                    aria-expanded="false" aria-controls="collapseObjective">
                                                <i class="fas fa-stethoscope mr-2"></i><strong>O</strong>bjective & Examination
                                                <small class="text-muted ml-3">(Findings, observations)</small>
                                            </button>
                                        </h5>
                                    </div>
                                    <div id="collapseObjective" class="collapse" aria-labelledby="headingObjective" data-parent="#soapAccordion">
                                        <div class="card-body p-3">
                                            <div class="form-group">
                                                <label>Objective Notes:</label>
                                                <textarea class="form-control" id="objective_note" name="objective_note" 
                                                          rows="3" placeholder="Observed findings..."><?php echo $today_round['objective_note'] ?? ''; ?></textarea>
                                            </div>
                                            <div class="form-group">
                                                <label>Examination Findings:</label>
                                                <textarea class="form-control" id="examination_findings" name="examination_findings" 
                                                          rows="3" placeholder="Physical examination findings..."><?php echo $today_round['examination_findings'] ?? ''; ?></textarea>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Assessment -->
                                <div class="card mb-2">
                                    <div class="card-header p-0" id="headingAssessment">
                                        <h5 class="mb-0">
                                            <button class="btn btn-link btn-block text-left p-3 collapsed" type="button" 
                                                    data-toggle="collapse" data-target="#collapseAssessment" 
                                                    aria-expanded="false" aria-controls="collapseAssessment">
                                                <i class="fas fa-diagnoses mr-2"></i><strong>A</strong>ssessment
                                                <small class="text-muted ml-3">(Diagnosis, progress)</small>
                                            </button>
                                        </h5>
                                    </div>
                                    <div id="collapseAssessment" class="collapse" aria-labelledby="headingAssessment" data-parent="#soapAccordion">
                                        <div class="card-body p-3">
                                            <textarea class="form-control" id="assessment_note" name="assessment_note" 
                                                      rows="3" placeholder="Assessment indicates..."><?php echo $today_round['assessment_note'] ?? ''; ?></textarea>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Plan -->
                                <div class="card mb-2">
                                    <div class="card-header p-0" id="headingPlan">
                                        <h5 class="mb-0">
                                            <button class="btn btn-link btn-block text-left p-3 collapsed" type="button" 
                                                    data-toggle="collapse" data-target="#collapsePlan" 
                                                    aria-expanded="false" aria-controls="collapsePlan">
                                                <i class="fas fa-tasks mr-2"></i><strong>P</strong>lan
                                                <small class="text-muted ml-3">(Treatment plan, orders)</small>
                                            </button>
                                        </h5>
                                    </div>
                                    <div id="collapsePlan" class="collapse" aria-labelledby="headingPlan" data-parent="#soapAccordion">
                                        <div class="card-body p-3">
                                            <div class="form-group">
                                                <label>Plan Notes:</label>
                                                <textarea class="form-control" id="plan_note" name="plan_note" 
                                                          rows="3" placeholder="Plan includes..."><?php echo $today_round['plan_note'] ?? ''; ?></textarea>
                                            </div>
                                            <div class="form-group">
                                                <label>Investigations to Order:</label>
                                                <textarea class="form-control" id="investigations_ordered" name="investigations_ordered" 
                                                          rows="2" placeholder="Lab tests, imaging..."><?php echo $today_round['investigations_ordered'] ?? ''; ?></textarea>
                                            </div>
                                            <div class="form-group">
                                                <label>Medications Prescribed:</label>
                                                <textarea class="form-control" id="medications_prescribed" name="medications_prescribed" 
                                                          rows="2" placeholder="New/changed medications..."><?php echo $today_round['medications_prescribed'] ?? ''; ?></textarea>
                                            </div>
                                            <div class="form-group">
                                                <label>Recommendations:</label>
                                                <textarea class="form-control" id="recommendations" name="recommendations" 
                                                          rows="2" placeholder="Special instructions..."><?php echo $today_round['recommendations'] ?? ''; ?></textarea>
                                            </div>
                                            <div class="form-group">
                                                <label>Next Round Date:</label>
                                                <input type="date" class="form-control" id="next_round_date" name="next_round_date" 
                                                       value="<?php echo $today_round['next_round_date'] ?? ''; ?>">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Action Buttons -->
                            <div class="form-group mt-3">
                                <div class="btn-group btn-block" role="group">
                                    <button type="submit" name="save_round" class="btn btn-success btn-lg flex-fill">
                                        <i class="fas fa-save mr-2"></i>Save Doctor Round
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Pending Tasks -->
                <div class="card mt-4">
                    <div class="card-header bg-warning py-2">
                        <h6 class="card-title mb-0 text-white">
                            <i class="fas fa-tasks mr-2"></i>Pending Tasks
                        </h6>
                    </div>
                    <div class="card-body p-2">
                        <div class="row text-center">
                            <div class="col-4">
                                <div class="text-muted">Pending Labs</div>
                                <div class="h4 text-danger"><?php echo $investigations['pending_labs'] ?? 0; ?></div>
                            </div>
                            <div class="col-4">
                                <div class="text-muted">Pending Imaging</div>
                                <div class="h4 text-warning"><?php echo $investigations['pending_radiology'] ?? 0; ?></div>
                            </div>
                            <div class="col-4">
                                <div class="text-muted">Active Rx</div>
                                <div class="h4 text-info"><?php echo $investigations['active_prescriptions'] ?? 0; ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Rounds History -->
            <div class="col-md-5">
                <div class="card">
                    <div class="card-header bg-info py-2">
                        <h4 class="card-title mb-0 text-white">
                            <i class="fas fa-history mr-2"></i>Doctor Rounds History
                            <span class="badge badge-light float-right"><?php echo count($doctor_rounds); ?> rounds</span>
                        </h4>
                    </div>
                    <div class="card-body p-0" style="max-height: 600px; overflow-y: auto;">
                        <?php if (!empty($doctor_rounds)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Date/Time</th>
                                            <th>Type</th>
                                            <th>Doctor</th>
                                            <th class="text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $current_date = null;
                                        foreach ($doctor_rounds as $round): 
                                            $round_datetime = new DateTime($round['round_datetime']);
                                            $is_today = ($round_datetime->format('Y-m-d') == $today);
                                            $row_class = $is_today ? 'table-info' : '';
                                            
                                            if ($current_date != $round_datetime->format('Y-m-d')) {
                                                $current_date = $round_datetime->format('Y-m-d');
                                                $date_display = $round_datetime->format('M j, Y');
                                                if ($is_today) {
                                                    $date_display = '<strong>Today</strong>';
                                                }
                                        ?>
                                            <tr class="bg-light">
                                                <td colspan="4" class="font-weight-bold">
                                                    <i class="fas fa-calendar-day mr-2"></i><?php echo $date_display; ?>
                                                </td>
                                            </tr>
                                        <?php } ?>
                                            <tr class="<?php echo $row_class; ?>">
                                                <td>
                                                    <div>
                                                        <?php echo getRoundTypeBadge($round['round_type']); ?>
                                                    </div>
                                                    <small class="text-muted">
                                                        <?php echo $round_datetime->format('H:i'); ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($round['round_type']); ?>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($round['doctor_name']); ?>
                                                    <?php if ($round['verified_by_name']): ?>
                                                        <br>
                                                        <small class="text-muted">
                                                            Verified: <?php echo htmlspecialchars($round['verified_by_name']); ?>
                                                        </small>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <button type="button" class="btn btn-sm btn-info" 
                                                            onclick="viewRoundDetails(<?php echo htmlspecialchars(json_encode($round)); ?>)"
                                                            title="View Details">
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
                                <i class="fas fa-hospital-user fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">No Doctor Rounds</h5>
                                <p class="text-muted">No doctor rounds have been recorded for this admission.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Latest Vitals -->
                <div class="card mt-4">
                    <div class="card-header bg-primary py-2">
                        <h6 class="card-title mb-0 text-white">
                            <i class="fas fa-heartbeat mr-2"></i>Latest Vital Signs
                        </h6>
                    </div>
                    <div class="card-body p-2">
                        <?php if ($latest_vitals): ?>
                            <div class="text-center">
                                <div class="row">
                                    <div class="col-3">
                                        <div class="mb-2">
                                            <div class="text-muted small">Temp</div>
                                            <div class="h5"><?php echo $latest_vitals['temperature'] ?? '--'; ?>°C</div>
                                        </div>
                                    </div>
                                    <div class="col-3">
                                        <div class="mb-2">
                                            <div class="text-muted small">Pulse</div>
                                            <div class="h5"><?php echo $latest_vitals['pulse_rate'] ?? '--'; ?></div>
                                        </div>
                                    </div>
                                    <div class="col-3">
                                        <div class="mb-2">
                                            <div class="text-muted small">Resp</div>
                                            <div class="h5"><?php echo $latest_vitals['respiratory_rate'] ?? '--'; ?></div>
                                        </div>
                                    </div>
                                    <div class="col-3">
                                        <div class="mb-2">
                                            <div class="text-muted small">BP</div>
                                            <div class="h5">
                                                <?php 
                                                if ($latest_vitals['blood_pressure_systolic'] && $latest_vitals['blood_pressure_diastolic']) {
                                                    echo $latest_vitals['blood_pressure_systolic'] . '/' . $latest_vitals['blood_pressure_diastolic'];
                                                } else {
                                                    echo '--';
                                                }
                                                ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-6">
                                        <div class="mb-2">
                                            <div class="text-muted small">SpO₂</div>
                                            <div class="h5"><?php echo $latest_vitals['oxygen_saturation'] ?? '--'; ?>%</div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="mb-2">
                                            <div class="text-muted small">Pain</div>
                                            <div class="h5"><?php echo $latest_vitals['pain_score'] ?? '--'; ?>/10</div>
                                        </div>
                                    </div>
                                </div>
                                <small class="text-muted">
                                    Last recorded: <?php echo date('H:i', strtotime($latest_vitals['recorded_at'])); ?>
                                </small>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-3">
                                <i class="fas fa-heartbeat fa-2x text-muted mb-2"></i>
                                <p class="text-muted">No vital signs recorded</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Round Details Modal -->
<div class="modal fade" id="roundDetailsModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Doctor Round Details</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body" id="roundDetailsContent">
                <!-- Content will be loaded dynamically -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="window.print()">
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

    // Initialize date pickers
    $('#round_date').flatpickr({
        dateFormat: 'Y-m-d',
        maxDate: 'today'
    });
    
    $('#next_round_date').flatpickr({
        dateFormat: 'Y-m-d',
        minDate: 'today'
    });

    // Auto-expand textareas
    $('textarea').on('input', function() {
        this.style.height = 'auto';
        this.style.height = (this.scrollHeight) + 'px';
    }).trigger('input');

    // Initialize accordion
    $('#soapAccordion .collapse').on('shown.bs.collapse', function() {
        $(this).prev().find('button').addClass('active');
    }).on('hidden.bs.collapse', function() {
        $(this).prev().find('button').removeClass('active');
    });
});

function viewRoundDetails(round) {
    const modalContent = document.getElementById('roundDetailsContent');
    const roundDate = new Date(round.round_datetime);
    
    // Parse vital signs if available
    let vitalSigns = '';
    if (round.vital_signs) {
        try {
            const vitals = JSON.parse(round.vital_signs);
            vitalSigns = `
                <div class="row mb-3">
                    <div class="col-md-12">
                        <h6 class="text-primary"><i class="fas fa-heartbeat mr-2"></i>Vital Signs</h6>
                        <div class="p-2 bg-light rounded">
                            <div class="row">
                                <div class="col-3">
                                    <small class="text-muted">Temp:</small>
                                    <div>${vitals.temperature || '--'}°C</div>
                                </div>
                                <div class="col-3">
                                    <small class="text-muted">Pulse:</small>
                                    <div>${vitals.pulse_rate || '--'}</div>
                                </div>
                                <div class="col-3">
                                    <small class="text-muted">Resp:</small>
                                    <div>${vitals.respiratory_rate || '--'}</div>
                                </div>
                                <div class="col-3">
                                    <small class="text-muted">BP:</small>
                                    <div>${vitals.blood_pressure_systolic || '--'}/${vitals.blood_pressure_diastolic || '--'}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>`;
        } catch (e) {
            console.error('Error parsing vital signs:', e);
        }
    }
    
    let html = `
        <div class="card">
            <div class="card-header bg-light py-2">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0">
                            ${getRoundTypeBadge(round.round_type)} 
                            <span class="ml-2">${roundDate.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })}</span>
                        </h6>
                        <small class="text-muted">
                            ${roundDate.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})} • 
                            Doctor: ${round.doctor_name || 'N/A'}
                        </small>
                    </div>
                </div>
            </div>
            <div class="card-body">
                ${vitalSigns}
                
                <div class="soap-notes">
    `;
    
    if (round.subjective_note) {
        html += `<div class="mb-4">
                    <h6 class="text-primary"><i class="fas fa-comment-medical mr-2"></i>Subjective (S)</h6>
                    <div class="p-3 bg-light rounded">${round.subjective_note.replace(/\n/g, '<br>')}</div>
                </div>`;
    }
    
    if (round.objective_note) {
        html += `<div class="mb-4">
                    <h6 class="text-success"><i class="fas fa-stethoscope mr-2"></i>Objective (O)</h6>
                    <div class="p-3 bg-light rounded">${round.objective_note.replace(/\n/g, '<br>')}</div>
                </div>`;
    }
    
    if (round.examination_findings) {
        html += `<div class="mb-4">
                    <h6 class="text-success"><i class="fas fa-search mr-2"></i>Examination Findings</h6>
                    <div class="p-3 bg-light rounded">${round.examination_findings.replace(/\n/g, '<br>')}</div>
                </div>`;
    }
    
    if (round.assessment_note) {
        html += `<div class="mb-4">
                    <h6 class="text-warning"><i class="fas fa-diagnoses mr-2"></i>Assessment (A)</h6>
                    <div class="p-3 bg-light rounded">${round.assessment_note.replace(/\n/g, '<br>')}</div>
                </div>`;
    }
    
    if (round.plan_note) {
        html += `<div class="mb-4">
                    <h6 class="text-info"><i class="fas fa-tasks mr-2"></i>Plan (P)</h6>
                    <div class="p-3 bg-light rounded">${round.plan_note.replace(/\n/g, '<br>')}</div>
                </div>`;
    }
    
    if (round.investigations_ordered) {
        html += `<div class="mb-4">
                    <h6 class="text-info"><i class="fas fa-flask mr-2"></i>Investigations Ordered</h6>
                    <div class="p-3 bg-light rounded">${round.investigations_ordered.replace(/\n/g, '<br>')}</div>
                </div>`;
    }
    
    if (round.medications_prescribed) {
        html += `<div class="mb-4">
                    <h6 class="text-info"><i class="fas fa-pills mr-2"></i>Medications Prescribed</h6>
                    <div class="p-3 bg-light rounded">${round.medications_prescribed.replace(/\n/g, '<br>')}</div>
                </div>`;
    }
    
    if (round.recommendations) {
        html += `<div class="mb-4">
                    <h6 class="text-secondary"><i class="fas fa-comments mr-2"></i>Recommendations</h6>
                    <div class="p-3 bg-light rounded">${round.recommendations.replace(/\n/g, '<br>')}</div>
                </div>`;
    }
    
    html += `   </div>
            </div>
            <div class="card-footer">
                <small class="text-muted">
                    Recorded: ${new Date(round.created_at).toLocaleString()}
                    ${round.verified_by_name ? ` • Verified by: ${round.verified_by_name}` : ''}
                </small>
            </div>
        </div>`;
    
    modalContent.innerHTML = html;
    $('#roundDetailsModal').modal('show');
}

// Print styles
<style>
@media print {
    .card-header, .card-tools, .btn, form, .modal,
    .card-footer, .toast-container {
        display: none !important;
    }
    .card {
        border: none !important;
        box-shadow: none !important;
    }
    .card-body {
        padding: 0 !important;
    }
    .soap-notes .p-3 {
        padding: 0.5rem !important;
    }
    .table {
        font-size: 11px !important;
    }
}
</style>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/audit_functions.php'; // Added for logging

// Get visit_id from URL
$visit_id = isset($_GET['visit_id']) ? intval($_GET['visit_id']) : 0;

if (!$visit_id) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "No visit specified!";
    
    // AUDIT LOG: No visit specified
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'ACCESS',
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
        'action'      => 'ACCESS',
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

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
        }
    }
}

// Search for billable items
$search_results = [];
$search_term = $_GET['search'] ?? '';
$search_item_type = $_GET['item_type'] ?? 'all';

if ($search_term) {
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

// Functions (updated with logging)
function createPendingBill($mysqli, $user_id, $visit_id, $patient_id) {
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
        
        // AUDIT LOG: Pending bill created
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
                'bill_status' => 'draft'
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
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
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
        
        // AUDIT LOG: Item added/updated
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
                'quantity' => $quantity,
                'unit_price' => $actual_price,
                'total_amount' => $total_amount
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
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        return false;
    }
    
    $pending_bill_item_id = intval($_POST['pending_bill_item_id'] ?? 0);
    
    if (!$pending_bill_item_id) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Item ID required";
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
            
            // AUDIT LOG: Item removed
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
                    'quantity' => $item_data['item_quantity'] ?? 0,
                    'total_amount' => $item_data['total_amount'] ?? 0
                ],
                'new_values'  => null
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
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        return false;
    }
    
    $pending_bill_item_id = intval($_POST['pending_bill_item_id'] ?? 0);
    $quantity = floatval($_POST['quantity'] ?? 1);
    $instructions = $_POST['instructions'] ?? '';
    
    if (!$pending_bill_item_id || $quantity <= 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid item data";
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
            
            // AUDIT LOG: Item updated
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
                    'total_amount' => $old_total
                ],
                'new_values'  => [
                    'quantity' => $quantity,
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
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
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
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h3 class="card-title mt-2 mb-0 text-white">
                    <i class="fas fa-fw fa-stethoscope mr-2"></i>
                    Doctor Orders & Prescriptions
                </h3>
                <small class="text-white">
                    Visit #<?php echo htmlspecialchars($visit['visit_number']); ?> | 
                    Patient: <?php echo htmlspecialchars($visit['patient_first_name'] . ' ' . $visit['patient_last_name']); ?> |
                    MRN: <?php echo htmlspecialchars($visit['patient_mrn']); ?>
                </small>
            </div>
            <div class="btn-group">
                <a href="doctor_dashboard.php" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
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

        <!-- Visit Information Banner -->
        <div class="alert alert-info mb-4">
            <div class="row">
                <div class="col-md-3">
                    <strong>Patient:</strong> <?php echo htmlspecialchars($visit['patient_first_name'] . ' ' . $visit['patient_last_name']); ?><br>
                    <small>Age: <?php echo calculateAge($visit['date_of_birth']); ?>y | Gender: <?php echo htmlspecialchars($visit['patient_gender']); ?></small>
                </div>
                <div class="col-md-3">
                    <strong>Visit Type:</strong> <?php echo htmlspecialchars($visit['visit_type']); ?><br>
                    <small>Department: <?php echo htmlspecialchars($visit['department_name'] ?? 'N/A'); ?></small>
                </div>
                <div class="col-md-3">
                    <strong>Doctor:</strong> <?php echo htmlspecialchars($visit['doctor_name']); ?><br>
                    <small>Date: <?php echo date('F j, Y', strtotime($visit['visit_datetime'])); ?></small>
                </div>
                <div class="col-md-3">
                    <?php if ($visit['visit_type'] == 'IPD' && $visit['ward_name']): ?>
                        <strong>Ward/Bed:</strong> <?php echo htmlspecialchars($visit['ward_name']); ?>
                        <?php if ($visit['bed_number']): ?>
                            / <?php echo htmlspecialchars($visit['bed_number']); ?>
                        <?php endif; ?>
                    <?php else: ?>
                        <strong>Status:</strong> <?php echo htmlspecialchars($visit['visit_status']); ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Left Column: Add Orders -->
            <div class="col-md-8">
                <!-- Quick Order Forms -->
                <div class="card mb-4">
                    <div class="card-header bg-info py-2">
                        <h5 class="card-title mb-0 text-white"><i class="fas fa-plus-circle mr-2"></i>Add Orders & Prescriptions</h5>
                    </div>
                    <div class="card-body">
                        <!-- Search Form -->
                        <form method="post" class="mb-4">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="action" value="search_items">
                            
                            <div class="row">
                                <div class="col-md-8">
                                    <div class="form-group">
                                        <label>Search Billable Items</label>
                                        <div class="input-group">
                                            <input type="text" name="search_term" class="form-control" 
                                                   placeholder="Search services, tests, procedures, medications..." 
                                                   value="<?php echo htmlspecialchars($search_term); ?>">
                                            <div class="input-group-append">
                                                <button type="submit" class="btn btn-info">
                                                    <i class="fas fa-search"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Item Type</label>
                                        <select name="item_type" class="form-control">
                                            <option value="all" <?php echo $search_item_type == 'all' ? 'selected' : ''; ?>>All Types</option>
                                            <option value="service" <?php echo $search_item_type == 'service' ? 'selected' : ''; ?>>Services</option>
                                            <option value="lab" <?php echo $search_item_type == 'lab' ? 'selected' : ''; ?>>Lab Tests</option>
                                            <option value="imaging" <?php echo $search_item_type == 'imaging' ? 'selected' : ''; ?>>Imaging</option>
                                            <option value="procedure" <?php echo $search_item_type == 'procedure' ? 'selected' : ''; ?>>Procedures</option>
                                            <option value="medication" <?php echo $search_item_type == 'medication' ? 'selected' : ''; ?>>Medications</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </form>

                        <!-- Search Results -->
                        <?php if (!empty($search_results)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover table-sm">
                                    <thead>
                                        <tr>
                                            <th>Item</th>
                                            <th>Type</th>
                                            <th>Price</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($search_results as $item): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($item['item_name']); ?></strong>
                                                    <br><small class="text-muted"><code><?php echo htmlspecialchars($item['item_code']); ?></code></small>
                                                    <?php if ($item['item_description']): ?>
                                                        <br><small class="text-muted"><?php echo htmlspecialchars(substr($item['item_description'], 0, 50)); ?>...</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge badge-info">
                                                        <?php echo ucfirst($item['item_type']); ?>
                                                    </span>
                                                </td>
                                                <td class="font-weight-bold text-success">
                                                    KSH <?php echo number_format($item['unit_price'] ?? 0, 2); ?>
                                                </td>
                                                <td>
                                                    <?php if ($item['item_type'] == 'medication'): ?>
                                                        <button type="button" class="btn btn-warning btn-sm" 
                                                                data-toggle="modal" data-target="#prescribeModal"
                                                                data-item-id="<?php echo $item['billable_item_id']; ?>"
                                                                data-item-name="<?php echo htmlspecialchars($item['item_name']); ?>">
                                                            <i class="fas fa-prescription mr-1"></i> Prescribe
                                                        </button>
                                                    <?php else: ?>
                                                        <button type="button" class="btn btn-primary btn-sm" 
                                                                data-toggle="modal" data-target="#addItemModal"
                                                                data-item-id="<?php echo $item['billable_item_id']; ?>"
                                                                data-item-name="<?php echo htmlspecialchars($item['item_name']); ?>"
                                                                data-item-code="<?php echo htmlspecialchars($item['item_code']); ?>"
                                                                data-item-price="<?php echo $item['unit_price']; ?>">
                                                            <i class="fas fa-plus mr-1"></i> Order
                                                        </button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php elseif ($search_term): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle mr-2"></i>
                                No items found for "<?php echo htmlspecialchars($search_term); ?>"
                            </div>
                        <?php else: ?>
                            <div class="alert alert-secondary">
                                <i class="fas fa-search mr-2"></i>
                                Enter search terms above to find items to order or prescribe
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Current Orders List -->
                <div class="card">
                    <div class="card-header bg-warning py-2">
                        <h5 class="card-title mb-0"><i class="fas fa-list mr-2"></i>Current Orders (<?php echo count($bill_items); ?>)</h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if (!empty($bill_items)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover table-sm mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Item</th>
                                            <th>Qty</th>
                                            <th>Price</th>
                                            <th>Total</th>
                                            <th>Notes</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($bill_items as $item): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($item['item_name']); ?></strong>
                                                    <br><small class="text-muted"><code><?php echo htmlspecialchars($item['item_code']); ?></code></small>
                                                </td>
                                                <td><?php echo $item['item_quantity']; ?></td>
                                                <td class="text-success">KSH <?php echo number_format($item['unit_price'], 2); ?></td>
                                                <td class="font-weight-bold text-success">KSH <?php echo number_format($item['total_amount'], 2); ?></td>
                                                <td>
                                                    <small class="text-muted"><?php echo htmlspecialchars($item['notes']); ?></small>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <button type="button" class="btn btn-primary btn-sm" 
                                                                data-toggle="modal" data-target="#editItemModal"
                                                                data-item-id="<?php echo $item['pending_bill_item_id']; ?>"
                                                                data-item-name="<?php echo htmlspecialchars($item['item_name']); ?>"
                                                                data-quantity="<?php echo $item['item_quantity']; ?>"
                                                                data-instructions="<?php echo htmlspecialchars($item['notes']); ?>">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <form method="post" class="d-inline">
                                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                            <input type="hidden" name="action" value="remove_item">
                                                            <input type="hidden" name="pending_bill_item_id" value="<?php echo $item['pending_bill_item_id']; ?>">
                                                            <button type="submit" class="btn btn-danger btn-sm ml-1" onclick="return confirm('Remove this order?')">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="fas fa-shopping-cart fa-2x text-muted mb-3"></i>
                                <p class="text-muted mb-0">No orders added yet</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Right Column: Bill Summary & Actions -->
            <div class="col-md-4">
                <!-- Bill Summary Card -->
                <div class="card mb-4">
                    <div class="card-header bg-success py-2">
                        <h5 class="card-title mb-0 text-white"><i class="fas fa-calculator mr-2"></i>Bill Summary</h5>
                    </div>
                    <div class="card-body">
                        <div class="text-center mb-3">
                            <h3 class="text-success">KSH <?php echo number_format($pending_bill['total_amount'] ?? 0, 2); ?></h3>
                            <small class="text-muted">Total Estimated Cost</small>
                        </div>
                        
                        <table class="table table-sm">
                            <tr>
                                <td>Subtotal:</td>
                                <td class="text-right font-weight-bold">
                                    KSH <?php echo number_format($pending_bill['subtotal_amount'] ?? 0, 2); ?>
                                </td>
                            </tr>
                            <tr>
                                <td>Tax:</td>
                                <td class="text-right">
                                    KSH <?php echo number_format($pending_bill['tax_amount'] ?? 0, 2); ?>
                                </td>
                            </tr>
                            <tr class="table-primary">
                                <td><strong>Total:</strong></td>
                                <td class="text-right font-weight-bold text-success">
                                    KSH <?php echo number_format($pending_bill['total_amount'] ?? 0, 2); ?>
                                </td>
                            </tr>
                        </table>
                        
                        <hr>
                        
                        <div class="small">
                            <p class="mb-1"><strong>Billing Information:</strong></p>
                            <div class="d-flex justify-content-between">
                                <span>Bill Number:</span>
                                <span class="font-weight-bold"><?php echo htmlspecialchars($pending_bill['bill_number']); ?></span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span>Price List:</span>
                                <span><?php echo htmlspecialchars($pending_bill['price_list_name'] ?? 'Default'); ?></span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span>Status:</span>
                                <span class="badge <?php echo $pending_bill['is_finalized'] ? 'badge-success' : 'badge-warning'; ?>">
                                    <?php echo $pending_bill['is_finalized'] ? 'FINALIZED' : 'ACTIVE'; ?>
                                </span>
                            </div>
                            <?php if ($pending_bill['is_finalized']): ?>
                                <div class="alert alert-warning small mt-2 mb-0 p-2">
                                    <i class="fas fa-info-circle"></i> This bill has been finalized. New orders cannot be added.
                                </div>
                            <?php else: ?>
                                <div class="alert alert-success small mt-2 mb-0 p-2">
                                    <i class="fas fa-info-circle"></i> Add orders above. Bill will be finalized by billing department.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="card">
                    <div class="card-header bg-secondary py-2">
                        <h5 class="card-title mb-0 text-white"><i class="fas fa-bolt mr-2"></i>Quick Actions</h5>
                    </div>
                    <div class="card-body">
                        <div class="btn-group-vertical w-100">
                            <a href="doctor_consultation.php?visit_id=<?php echo $visit_id; ?>" class="btn btn-outline-primary mb-2 text-left">
                                <i class="fas fa-stethoscope mr-2"></i>Back to Consultation
                            </a>
                            <a href="prescription.php?visit_id=<?php echo $visit_id; ?>" class="btn btn-outline-info mb-2 text-left">
                                <i class="fas fa-prescription mr-2"></i>View Prescriptions
                            </a>
                            <a href="lab_orders.php?visit_id=<?php echo $visit_id; ?>" class="btn btn-outline-success mb-2 text-left">
                                <i class="fas fa-flask mr-2"></i>Lab Orders
                            </a>
                            <a href="imaging_orders.php?visit_id=<?php echo $visit_id; ?>" class="btn btn-outline-warning mb-2 text-left">
                                <i class="fas fa-x-ray mr-2"></i>Imaging Orders
                            </a>
                            <a href="/billing/view_bill.php?pending_bill_id=<?php echo $pending_bill_id; ?>" class="btn btn-outline-danger text-left">
                                <i class="fas fa-file-invoice-dollar mr-2"></i>View Complete Bill
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Item Modal -->
<div class="modal fade" id="addItemModal" tabindex="-1" role="dialog" aria-labelledby="addItemModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary">
                <h5 class="modal-title text-white" id="addItemModalLabel">
                    <i class="fas fa-plus-circle mr-2"></i>Add Order
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true" class="text-white">&times;</span>
                </button>
            </div>
            <form method="post" id="addItemForm">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="add_item">
                <input type="hidden" name="pending_bill_id" value="<?php echo $pending_bill_id; ?>">
                <input type="hidden" name="billable_item_id" id="add_billable_item_id">
                <input type="hidden" name="price_list_item_id" id="add_price_list_item_id" value="">
                <input type="hidden" name="unit_price" id="add_unit_price">
                
                <div class="modal-body">
                    <div class="form-group">
                        <label>Item</label>
                        <input type="text" class="form-control" id="add_item_name" readonly>
                        <small class="form-text text-muted" id="add_item_code"></small>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Quantity</label>
                                <input type="number" name="quantity" class="form-control" value="1" min="0.01" step="0.01" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Unit Price (KSH)</label>
                                <input type="text" class="form-control" id="display_unit_price" readonly>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Instructions/Notes</label>
                        <textarea name="instructions" class="form-control" rows="3" placeholder="Add any special instructions..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">
                        <i class="fas fa-times mr-2"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-plus mr-2"></i>Add Order
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Item Modal -->
<div class="modal fade" id="editItemModal" tabindex="-1" role="dialog" aria-labelledby="editItemModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title" id="editItemModalLabel">
                    <i class="fas fa-edit mr-2"></i>Edit Order
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="post" id="editItemForm">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="update_item">
                <input type="hidden" name="pending_bill_item_id" id="edit_pending_bill_item_id">
                
                <div class="modal-body">
                    <div class="form-group">
                        <label>Item</label>
                        <input type="text" class="form-control" id="edit_item_name" readonly>
                    </div>
                    
                    <div class="form-group">
                        <label>Quantity</label>
                        <input type="number" name="quantity" class="form-control" id="edit_quantity" 
                               min="0.01" step="0.01" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Instructions/Notes</label>
                        <textarea name="instructions" class="form-control" rows="3" id="edit_instructions"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">
                        <i class="fas fa-times mr-2"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-save mr-2"></i>Update Order
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Prescribe Medication Modal -->
<div class="modal fade" id="prescribeModal" tabindex="-1" role="dialog" aria-labelledby="prescribeModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title" id="prescribeModalLabel">
                    <i class="fas fa-prescription mr-2"></i>Prescribe Medication
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="post" id="prescribeForm">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="prescribe_medication">
                <input type="hidden" name="medication_id" id="prescribe_medication_id">
                <input type="hidden" name="visit_id" value="<?php echo $visit_id; ?>">
                <input type="hidden" name="patient_id" value="<?php echo $patient_id; ?>">
                
                <div class="modal-body">
                    <div class="form-group">
                        <label>Medication</label>
                        <input type="text" class="form-control" id="prescribe_medication_name" readonly>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Dosage</label>
                                <input type="text" name="dosage" class="form-control" placeholder="e.g., 500mg" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Frequency</label>
                                <input type="text" name="frequency" class="form-control" placeholder="e.g., BD, TDS, QID">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Duration</label>
                                <input type="text" name="duration" class="form-control" placeholder="e.g., 5 days, 1 week">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Route</label>
                                <select name="route" class="form-control">
                                    <option value="oral">Oral</option>
                                    <option value="iv">IV</option>
                                    <option value="im">IM</option>
                                    <option value="sc">SC</option>
                                    <option value="topical">Topical</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Instructions</label>
                        <textarea name="instructions" class="form-control" rows="3" placeholder="Special instructions..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">
                        <i class="fas fa-times mr-2"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-prescription mr-2"></i>Prescribe
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Add Item Modal
    $('#addItemModal').on('show.bs.modal', function(event) {
        const button = $(event.relatedTarget);
        const itemId = button.data('item-id');
        const itemName = button.data('item-name');
        const itemCode = button.data('item-code');
        const itemPrice = button.data('item-price');
        
        const modal = $(this);
        modal.find('#add_billable_item_id').val(itemId);
        modal.find('#add_item_name').val(itemName);
        modal.find('#add_item_code').text('Code: ' + itemCode);
        modal.find('#add_unit_price').val(itemPrice);
        modal.find('#display_unit_price').val('KSH ' + parseFloat(itemPrice).toFixed(2));
    });
    
    // Edit Item Modal
    $('#editItemModal').on('show.bs.modal', function(event) {
        const button = $(event.relatedTarget);
        const itemId = button.data('item-id');
        const itemName = button.data('item-name');
        const quantity = button.data('quantity');
        const instructions = button.data('instructions');
        
        const modal = $(this);
        modal.find('#edit_pending_bill_item_id').val(itemId);
        modal.find('#edit_item_name').val(itemName);
        modal.find('#edit_quantity').val(quantity);
        modal.find('#edit_instructions').val(instructions);
    });
    
    // Prescribe Modal
    $('#prescribeModal').on('show.bs.modal', function(event) {
        const button = $(event.relatedTarget);
        const itemId = button.data('item-id');
        const itemName = button.data('item-name');
        
        const modal = $(this);
        modal.find('#prescribe_medication_id').val(itemId);
        modal.find('#prescribe_medication_name').val(itemName);
    });
    
    // Form validation
    $('#addItemForm, #editItemForm').on('submit', function(e) {
        const quantity = $(this).find('input[name="quantity"]').val();
        
        if (quantity <= 0) {
            alert('Quantity must be greater than 0');
            e.preventDefault();
            return false;
        }
    });
});
</script>

<style>
.card {
    border: none;
    box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
}
.card-header {
    border-bottom: 1px solid #e3e6f0;
}
.badge {
    font-size: 0.75em;
}
.table th {
    border-top: none;
    font-weight: 600;
    color: #6e707e;
    font-size: 0.85rem;
}
.btn-group-sm > .btn {
    padding: 0.25rem 0.5rem;
    font-size: 0.75rem;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
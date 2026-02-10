<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/audit_functions.php';

// Initialize variables
$visit_id = null;
$visit_details = null;
$existing_invoice = null;
$pending_orders = [];
$pending_bills = [];
$active_pending_bill = null;
$pending_bill_items = [];
$billable_items = [];

// Default Column Sortby/Order Filter
$sort = "pb.bill_number";
$order = "DESC";

// Filter parameters
$status_filter = $_GET['status'] ?? '';
$payment_mode_filter = $_GET['payment_mode'] ?? '';
$visit_type_filter = $_GET['visit_type'] ?? '';

// Date Range Filter
$dtf = sanitizeInput($_GET['dtf'] ?? '');
$dtt = sanitizeInput($_GET['dtt'] ?? '');

// Search Query
$q = sanitizeInput($_GET['q'] ?? '');
if (!empty($q)) {
    $search_query = "AND (
        pb.bill_number LIKE '%$q%' 
        OR pb.pending_bill_number LIKE '%$q%'
        OR p.first_name LIKE '%$q%'
        OR p.last_name LIKE '%$q%'
        OR p.patient_mrn LIKE '%$q%'
        OR v.visit_number LIKE '%$q%'
        OR pl.price_list_name LIKE '%$q%'
    )";
} else {
    $search_query = '';
}

// Build WHERE clause for filters
$where_conditions = ["pb.bill_status != 'cancelled'"];
$params = [];
$types = '';

if (!empty($status_filter)) {
    $where_conditions[] = "pb.bill_status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

if (!empty($payment_mode_filter)) {
    if ($payment_mode_filter == 'insurance') {
        $where_conditions[] = "v.insurance_company_id IS NOT NULL";
    } elseif ($payment_mode_filter == 'cash') {
        $where_conditions[] = "v.insurance_company_id IS NULL";
    }
}

if (!empty($visit_type_filter)) {
    $where_conditions[] = "v.visit_type = ?";
    $params[] = $visit_type_filter;
    $types .= 's';
}

// Date range filter
if (!empty($dtf) && !empty($dtt)) {
    $where_conditions[] = "DATE(pb.created_at) BETWEEN ? AND ?";
    $params[] = $dtf;
    $params[] = $dtt;
    $types .= 'ss';
}

$where_clause = implode(' AND ', $where_conditions);

// Get all bills with pagination
$record_from = $_GET['record_from'] ?? 0;
$record_to = $_GET['record_to'] ?? 25;

$bills_sql = "
    SELECT SQL_CALC_FOUND_ROWS 
        pb.*,
        p.first_name,
        p.last_name,
        p.patient_mrn,
        v.visit_number,
        v.visit_type,
        v.visit_datetime,
        v.visit_status,
        pl.price_list_name,
        pl.price_list_type,
        u.user_name as created_by_name,
        i.invoice_number,
        i.invoice_status,
        (SELECT COUNT(*) FROM pending_bill_items WHERE pending_bill_id = pb.pending_bill_id AND is_cancelled = 0) as item_count,
        ic.company_name as insurance_company
    FROM pending_bills pb
    JOIN patients p ON pb.patient_id = p.patient_id
    JOIN visits v ON pb.visit_id = v.visit_id
    LEFT JOIN price_lists pl ON pb.price_list_id = pl.price_list_id
    LEFT JOIN users u ON pb.created_by = u.user_id
    LEFT JOIN invoices i ON pb.invoice_id = i.invoice_id
    LEFT JOIN visit_insurance vi ON v.visit_id = vi.visit_id
    LEFT JOIN insurance_companies ic ON vi.insurance_company_id = ic.insurance_company_id
    WHERE $where_clause
    $search_query
    ORDER BY $sort $order
    LIMIT $record_from, $record_to
";

$bills_stmt = $mysqli->prepare($bills_sql);
if (!empty($params)) {
    $bills_stmt->bind_param($types, ...$params);
}
$bills_stmt->execute();
$pending_bills = $bills_stmt->get_result();

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get unique statuses for filter
$statuses = $mysqli->query("SELECT DISTINCT bill_status FROM pending_bills WHERE bill_status IS NOT NULL ORDER BY bill_status");

// Get unique visit types for filter
$visit_types = $mysqli->query("SELECT DISTINCT visit_type FROM visits WHERE visit_type IS NOT NULL ORDER BY visit_type");

// Get statistics
$total_bills = $mysqli->query("SELECT COUNT(*) FROM pending_bills WHERE bill_status != 'cancelled'")->fetch_row()[0];
$draft_bills = $mysqli->query("SELECT COUNT(*) FROM pending_bills WHERE bill_status = 'draft'")->fetch_row()[0];
$pending_bills_count = $mysqli->query("SELECT COUNT(*) FROM pending_bills WHERE bill_status = 'pending'")->fetch_row()[0];
$total_value = $mysqli->query("SELECT SUM(total_amount) as total FROM pending_bills WHERE bill_status != 'cancelled'")->fetch_assoc()['total'] ?? 0;
$avg_bill_value = $mysqli->query("SELECT AVG(total_amount) as avg_value FROM pending_bills WHERE bill_status != 'cancelled'")->fetch_assoc()['avg_value'] ?? 0;
$insurance_bills = $mysqli->query("SELECT COUNT(DISTINCT pb.pending_bill_id) 
                                  FROM pending_bills pb
                                  JOIN visits v ON pb.visit_id = v.visit_id
                                  JOIN visit_insurance vi ON v.visit_id = vi.visit_id
                                  WHERE pb.bill_status != 'cancelled'")->fetch_row()[0];

// Handle different POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token'] ?? '');
    
    // Validate CSRF token
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        header("Location: billing.php");
        exit;
    }

    // Check which form was submitted
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'select_visit':
                // Handle visit selection
                $visit_id = intval($_POST['selected_visit_id'] ?? 0);
                if ($visit_id > 0) {
                    header("Location: billing.php?visit_id=" . $visit_id);
                    exit;
                }
                break;
                
            case 'create_pending_bill':
                // Handle pending bill creation
                handleCreatePendingBill($mysqli, $session_user_id);
                break;
                
            case 'update_pending_bill':
                // Handle pending bill update
                handleUpdatePendingBill($mysqli, $session_user_id);
                break;
                
            case 'delete_pending_bill':
                // Handle pending bill deletion
                handleDeletePendingBill($mysqli, $session_user_id);
                break;
                
            case 'add_item_to_bill':
                // Handle add item to pending bill
                handleAddItemToBill($mysqli, $session_user_id);
                break;
                
            case 'remove_bill_item':
                // Handle remove item from pending bill
                handleRemoveBillItem($mysqli, $session_user_id);
                break;
                
            case 'set_active_bill':
                // Handle setting active pending bill - redirect to billing_edit.php
                $pending_bill_id = intval($_POST['pending_bill_id'] ?? 0);
                if ($pending_bill_id > 0) {
                    header("Location: billing_edit.php?visit_id=" . $visit_id . "&pending_bill_id=" . $pending_bill_id);
                    exit;
                }
                break;
                
            case 'search_billable_items':
                // Handle billable item search
                $search_term = $_POST['search_term'] ?? '';
                $item_type = $_POST['item_type'] ?? 'all';
                header("Location: billing.php?visit_id=" . $visit_id . "&search=" . urlencode($search_term) . "&item_type=" . $item_type);
                exit;
                break;
                
            case 'create_invoice_from_bill':
                // Handle creating invoice from pending bill
                handleCreateInvoiceFromBill($mysqli, $session_user_id);
                break;
        }
    }
}

// If visit_id is provided in URL, use it
if (isset($_GET['visit_id'])) {
    $visit_id = intval($_GET['visit_id']);
}

// If pending_bill_id is provided in URL, use it
$pending_bill_id = isset($_GET['pending_bill_id']) ? intval($_GET['pending_bill_id']) : 0;

// If we have a visit_id, fetch detailed information
if ($visit_id) {
    // Fetch detailed visit information
    $visit_details_sql = "SELECT v.*, 
                    p.patient_id, p.first_name, p.last_name, p.middle_name, p.patient_mrn,
                    p.date_of_birth, p.sex, p.phone_primary, p.email,
                    d.department_name,
                    doc.user_name as doctor_name,
                    ia.admission_number, ia.ward_id, ia.bed_id, ia.admission_status,
                    w.ward_name,
                    b.bed_number,
                    ic.company_name,
                    vi.member_number, vi.coverage_percentage,
                    isc.scheme_name
                  FROM visits v
                  LEFT JOIN patients p ON v.patient_id = p.patient_id
                  LEFT JOIN departments d ON v.department_id = d.department_id
                  LEFT JOIN users doc ON v.attending_provider_id = doc.user_id
                  LEFT JOIN ipd_admissions ia ON v.visit_id = ia.visit_id
                  LEFT JOIN wards w ON ia.ward_id = w.ward_id
                  LEFT JOIN beds b ON ia.bed_id = b.bed_id
                  LEFT JOIN visit_insurance vi ON v.visit_id = vi.visit_id
                  LEFT JOIN insurance_companies ic ON vi.insurance_company_id = ic.insurance_company_id
                  LEFT JOIN insurance_schemes isc ON vi.insurance_scheme_id = isc.scheme_id
                  WHERE v.visit_id = ? AND v.visit_status != 'CANCELLED'";
                  
    $visit_details_stmt = $mysqli->prepare($visit_details_sql);
    $visit_details_stmt->bind_param("i", $visit_id);
    $visit_details_stmt->execute();
    $visit_details_result = $visit_details_stmt->get_result();
    
    $visit_details = $visit_details_result->fetch_assoc();
    
    if (!$visit_details) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Visit not found!";
        $visit_id = null;
    } else {
        $patient_id = $visit_details['patient_id'];
        
        // Determine payment mode based on insurance
        if (!empty($visit_details['company_name'])) {
            $visit_details['payment_mode'] = 'INSURANCE';
        } else {
            $visit_details['payment_mode'] = 'CASH';
        }
        
        // Get active pending bill details if selected
        if ($pending_bill_id > 0) {
            $active_pending_bill = getPendingBillDetails($mysqli, $pending_bill_id, $visit_id);
            if ($active_pending_bill) {
                $pending_bill_items = getPendingBillItems($mysqli, $pending_bill_id);
            }
        }
    }
}

// Function to generate bill number
function generatePendingBillNumber($mysqli, $prefix = 'BILL') {
    $year = date('Y');
    $full_prefix = $prefix . '-' . $year . '-';
    
    // Get the last bill number for current year
    $sql = "SELECT MAX(bill_number) AS last_number
            FROM pending_bills
            WHERE bill_number LIKE ?
            AND YEAR(created_at) = ?";
    
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $mysqli->error);
    }
    
    $like_prefix = $full_prefix . '%';
    $stmt->bind_param('si', $like_prefix, $year);
    $stmt->execute();
    
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    
    $last_number = 0;
    if (!empty($row['last_number'])) {
        // Extract the number after the last dash
        $parts = explode('-', $row['last_number']);
        $last_number = intval(end($parts));
    }
    
    $next_number = $last_number + 1;
    
    return $full_prefix . str_pad($next_number, 4, '0', STR_PAD_LEFT);
}

// Function to generate invoice number
function generateInvoiceNumber($mysqli) {
    $year = date('Y');
    $month = date('m');
    $prefix = 'INV-' . $year . '-' . $month . '-';
    
    // Get the last invoice number for current year-month
    $sql = "SELECT MAX(invoice_number) AS last_number
            FROM invoices
            WHERE invoice_number LIKE ?
            AND YEAR(created_at) = ?
            AND MONTH(created_at) = ?";
    
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $mysqli->error);
    }
    
    $like_prefix = $prefix . '%';
    $stmt->bind_param('sii', $like_prefix, $year, $month);
    $stmt->execute();
    
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    
    $last_number = 0;
    if (!empty($row['last_number'])) {
        // Extract the number after the last dash
        $parts = explode('-', $row['last_number']);
        $last_number = intval(end($parts));
    }
    
    $next_number = $last_number + 1;
    
    return $prefix . str_pad($next_number, 4, '0', STR_PAD_LEFT);
}

// Pending Bills Functions
function handleCreatePendingBill($mysqli, $user_id) {
    global $visit_details;
    
    $visit_id = intval($_POST['visit_id'] ?? 0);
    $patient_id = intval($_POST['patient_id'] ?? 0);
    
    if (!$visit_id || !$patient_id) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Visit or patient information missing";
        return false;
    }
    
    // Start transaction
    $mysqli->begin_transaction();
    
    try {
        // Generate bill number
        $bill_number = generatePendingBillNumber($mysqli);
        $pending_bill_number = generatePendingBillNumber($mysqli, 'PBL');
        
        // Check if visit has insurance
        $visit_sql = "SELECT vi.insurance_company_id 
                     FROM visits v 
                     LEFT JOIN visit_insurance vi ON v.visit_id = vi.visit_id 
                     WHERE v.visit_id = ?";
        $visit_stmt = $mysqli->prepare($visit_sql);
        $visit_stmt->bind_param("i", $visit_id);
        $visit_stmt->execute();
        $visit_result = $visit_stmt->get_result();
        $visit_data = $visit_result->fetch_assoc();
        
        // Determine price list based on insurance
        if ($visit_data['insurance_company_id']) {
            $price_list_type = 'insurance';
            $price_list_name = 'Insurance - ' . $visit_data['insurance_company_id'];
        } else {
            $price_list_type = 'self_pay';
            $price_list_name = 'Self Pay - Standard';
        }
        
        // Get default price list ID
        $price_list_sql = "SELECT price_list_id FROM price_lists WHERE price_list_type = ? AND is_active = 1 LIMIT 1";
        $price_list_stmt = $mysqli->prepare($price_list_sql);
        $price_list_stmt->bind_param("s", $price_list_type);
        $price_list_stmt->execute();
        $price_list_result = $price_list_stmt->get_result();
        $price_list_data = $price_list_result->fetch_assoc();
        
        $price_list_id = $price_list_data['price_list_id'] ?? 1;
        
        // Create draft bill
        $bill_sql = "INSERT INTO pending_bills (
            bill_number,
            pending_bill_number,
            visit_id,
            patient_id,
            price_list_id,
            subtotal_amount,
            discount_amount,
            tax_amount,
            total_amount,
            bill_status,
            is_finalized,
            notes,
            created_by,
            bill_date
        ) VALUES (?, ?, ?, ?, ?, 0, 0, 0, 0, 'draft', 0, ?, ?, NOW())";
        
        $bill_stmt = $mysqli->prepare($bill_sql);
        if (!$bill_stmt) {
            throw new Exception("Prepare failed for bill: " . $mysqli->error);
        }
        
        $notes = sanitizeInput($_POST['notes'] ?? "Draft bill created for visit " . ($visit_details['visit_number'] ?? ''));
        $bill_stmt->bind_param(
            "ssiiisi",
            $bill_number,
            $pending_bill_number,
            $visit_id,
            $patient_id,
            $price_list_id,
            $notes,
            $user_id
        );
        
        if (!$bill_stmt->execute()) {
            throw new Exception("Error creating bill: " . $bill_stmt->error);
        }
        
        $pending_bill_id = $bill_stmt->insert_id;
        
        // Generate invoice number
        $invoice_number = generateInvoiceNumber($mysqli);
        
        // Create empty invoice
        $patient_name = ($visit_details['first_name'] ?? '') . ' ' . ($visit_details['last_name'] ?? '');
        $invoice_sql = "INSERT INTO invoices (
            invoice_number,
            pending_bill_id,
            visit_id,
            patient_id,
            patient_name,
            patient_mrn,
            price_list_id,
            subtotal_amount,
            discount_amount,
            tax_amount,
            total_amount,
            amount_paid,
            amount_due,
            invoice_status,
            invoice_date,
            notes,
            created_by,
            finalized_at,
            finalized_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 0, 0, 0, 0, 'issued', CURDATE(), ?, ?, NOW(), ?)";
        
        $invoice_stmt = $mysqli->prepare($invoice_sql);
        if (!$invoice_stmt) {
            throw new Exception("Prepare failed for invoice: " . $mysqli->error);
        }
        
        $invoice_notes = "Invoice created for visit " . ($visit_details['visit_number'] ?? '');
        $invoice_stmt->bind_param(
            "siiisssiii",
            $invoice_number,
            $pending_bill_id,
            $visit_id,
            $patient_id,
            $patient_name,
            ($visit_details['patient_mrn'] ?? ''),
            $price_list_id,
            $invoice_notes,
            $user_id,
            $user_id
        );
        
        if (!$invoice_stmt->execute()) {
            throw new Exception("Error creating invoice: " . $invoice_stmt->error);
        }
        
        $invoice_id = $invoice_stmt->insert_id;
        
        // Update pending bill with invoice_id
        $update_bill_sql = "UPDATE pending_bills SET invoice_id = ? WHERE pending_bill_id = ?";
        $update_bill_stmt = $mysqli->prepare($update_bill_sql);
        $update_bill_stmt->bind_param("ii", $invoice_id, $pending_bill_id);
        
        if (!$update_bill_stmt->execute()) {
            throw new Exception("Error updating bill with invoice ID: " . $update_bill_stmt->error);
        }
        
        // Commit transaction
        $mysqli->commit();
        
        // AUDIT LOG: Log bill creation
        audit_log($mysqli, [
            'user_id'     => $user_id,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'CREATE',
            'module'      => 'Billing',
            'table_name'  => 'pending_bills',
            'entity_type' => 'pending_bill',
            'record_id'   => $pending_bill_id,
            'patient_id'  => $patient_id,
            'visit_id'    => $visit_id,
            'description' => "Created draft bill: " . $bill_number . " for visit: " . ($visit_details['visit_number'] ?? ''),
            'status'      => 'SUCCESS',
            'old_values'  => null,
            'new_values'  => [
                'bill_number' => $bill_number,
                'pending_bill_number' => $pending_bill_number,
                'visit_id' => $visit_id,
                'patient_id' => $patient_id,
                'price_list_id' => $price_list_id,
                'bill_status' => 'draft'
            ]
        ]);
        
        // AUDIT LOG: Log invoice creation
        audit_log($mysqli, [
            'user_id'     => $user_id,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'CREATE',
            'module'      => 'Billing',
            'table_name'  => 'invoices',
            'entity_type' => 'invoice',
            'record_id'   => $invoice_id,
            'patient_id'  => $patient_id,
            'visit_id'    => $visit_id,
            'description' => "Created invoice: " . $invoice_number . " for bill: " . $bill_number,
            'status'      => 'SUCCESS',
            'old_values'  => null,
            'new_values'  => [
                'invoice_number' => $invoice_number,
                'pending_bill_id' => $pending_bill_id,
                'visit_id' => $visit_id,
                'patient_id' => $patient_id,
                'invoice_status' => 'issued'
            ]
        ]);
        
        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Draft bill and invoice created successfully!<br>Bill #: " . $bill_number . "<br>Invoice #: " . $invoice_number;
        
        // Redirect to the new pending bill in billing_edit.php
        header("Location: billing_edit.php?visit_id=" . $visit_id . "&pending_bill_id=" . $pending_bill_id);
        exit;
        
    } catch (Exception $e) {
        $mysqli->rollback();
        
        // AUDIT LOG: Log failed bill creation
        audit_log($mysqli, [
            'user_id'     => $user_id,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'CREATE',
            'module'      => 'Billing',
            'table_name'  => 'pending_bills',
            'entity_type' => 'pending_bill',
            'record_id'   => 0,
            'patient_id'  => $patient_id ?? 0,
            'visit_id'    => $visit_id,
            'description' => "Failed to create draft bill for visit: " . ($visit_details['visit_number'] ?? ''),
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => null,
            'error'       => $e->getMessage()
        ]);
        
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error: " . $e->getMessage();
        return false;
    }
}

function handleUpdatePendingBill($mysqli, $user_id) {
    $pending_bill_id = intval($_POST['pending_bill_id'] ?? 0);
    $bill_status = sanitizeInput($_POST['bill_status'] ?? '');
    $notes = sanitizeInput($_POST['notes'] ?? '');
    $discount_amount = floatval($_POST['discount_amount'] ?? 0);
    
    if (!$pending_bill_id) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Pending bill ID required";
        return false;
    }
    
    // Start transaction
    $mysqli->begin_transaction();
    
    try {
        // Get current bill totals and details
        $total_sql = "SELECT 
                        SUM(subtotal) as subtotal_amount,
                        SUM(discount_amount) as item_discount_amount,
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
        $total_tax_amount = $total_row['tax_amount'] ?? 0;
        $total_amount = $total_row['total_amount'] ?? 0;
        
        // Apply discount
        $total_amount = $subtotal_amount - $discount_amount;
        if ($total_amount < 0) $total_amount = 0;
        
        // Add tax back to discounted amount
        $total_amount += $total_tax_amount;
        
        // Get old values for audit log
        $old_bill_sql = "SELECT bill_status, discount_amount, notes FROM pending_bills WHERE pending_bill_id = ?";
        $old_bill_stmt = $mysqli->prepare($old_bill_sql);
        $old_bill_stmt->bind_param("i", $pending_bill_id);
        $old_bill_stmt->execute();
        $old_bill_result = $old_bill_stmt->get_result();
        $old_bill = $old_bill_result->fetch_assoc();
        
        $sql = "UPDATE pending_bills 
                SET bill_status = ?, 
                    notes = ?, 
                    discount_amount = ?,
                    subtotal_amount = ?,
                    tax_amount = ?,
                    total_amount = ?,
                    updated_at = NOW()
                WHERE pending_bill_id = ?";
        
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("ssddddi", $bill_status, $notes, $discount_amount, 
                         $subtotal_amount, $total_tax_amount, $total_amount, $pending_bill_id);
        
        if ($stmt->execute()) {
            // Update invoice if exists
            $invoice_sql = "SELECT invoice_id FROM pending_bills WHERE pending_bill_id = ? AND invoice_id IS NOT NULL";
            $invoice_stmt = $mysqli->prepare($invoice_sql);
            $invoice_stmt->bind_param("i", $pending_bill_id);
            $invoice_stmt->execute();
            $invoice_result = $invoice_stmt->get_result();
            $invoice_data = $invoice_result->fetch_assoc();
            
            if ($invoice_data) {
                $update_invoice_sql = "UPDATE invoices 
                                      SET subtotal_amount = ?,
                                          discount_amount = ?,
                                          tax_amount = ?,
                                          total_amount = ?,
                                          amount_due = ?,
                                          updated_at = NOW()
                                      WHERE invoice_id = ?";
                
                $update_invoice_stmt = $mysqli->prepare($update_invoice_sql);
                $update_invoice_stmt->bind_param(
                    "dddddi",
                    $subtotal_amount,
                    $discount_amount,
                    $total_tax_amount,
                    $total_amount,
                    $total_amount,
                    $invoice_data['invoice_id']
                );
                
                if (!$update_invoice_stmt->execute()) {
                    throw new Exception("Error updating invoice totals: " . $update_invoice_stmt->error);
                }
            }
            
            // AUDIT LOG: Log bill update
            audit_log($mysqli, [
                'user_id'     => $user_id,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'UPDATE',
                'module'      => 'Billing',
                'table_name'  => 'pending_bills',
                'entity_type' => 'pending_bill',
                'record_id'   => $pending_bill_id,
                'patient_id'  => 0, // Will be fetched if needed
                'visit_id'    => 0, // Will be fetched if needed
                'description' => "Updated pending bill #" . $pending_bill_id,
                'status'      => 'SUCCESS',
                'old_values'  => [
                    'bill_status' => $old_bill['bill_status'] ?? '',
                    'discount_amount' => $old_bill['discount_amount'] ?? 0,
                    'notes' => $old_bill['notes'] ?? ''
                ],
                'new_values'  => [
                    'bill_status' => $bill_status,
                    'discount_amount' => $discount_amount,
                    'notes' => $notes,
                    'subtotal_amount' => $subtotal_amount,
                    'tax_amount' => $total_tax_amount,
                    'total_amount' => $total_amount
                ]
            ]);
            
            $mysqli->commit();
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Pending bill updated successfully!";
            return true;
        } else {
            throw new Exception("Failed to update pending bill: " . $mysqli->error);
        }
        
    } catch (Exception $e) {
        $mysqli->rollback();
        
        // AUDIT LOG: Log failed update
        audit_log($mysqli, [
            'user_id'     => $user_id,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'UPDATE',
            'module'      => 'Billing',
            'table_name'  => 'pending_bills',
            'entity_type' => 'pending_bill',
            'record_id'   => $pending_bill_id,
            'patient_id'  => 0,
            'visit_id'    => 0,
            'description' => "Failed to update pending bill #" . $pending_bill_id,
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => null,
            'error'       => $e->getMessage()
        ]);
        
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Update Error: " . $e->getMessage();
        return false;
    }
}

function handleDeletePendingBill($mysqli, $user_id) {
    $pending_bill_id = intval($_POST['pending_bill_id'] ?? 0);
    
    if (!$pending_bill_id) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Pending bill ID required";
        return false;
    }
    
    // Start transaction
    $mysqli->begin_transaction();
    
    try {
        // Get bill details for audit log
        $bill_sql = "SELECT pb.*, CONCAT(p.first_name, ' ', p.last_name) as patient_name 
                     FROM pending_bills pb
                     JOIN patients p ON pb.patient_id = p.patient_id
                     WHERE pb.pending_bill_id = ?";
        $bill_stmt = $mysqli->prepare($bill_sql);
        $bill_stmt->bind_param("i", $pending_bill_id);
        $bill_stmt->execute();
        $bill_result = $bill_stmt->get_result();
        $bill_data = $bill_result->fetch_assoc();
        
        if (!$bill_data) {
            throw new Exception("Bill not found");
        }
        
        $visit_id = $bill_data['visit_id'] ?? 0;
        $patient_id = $bill_data['patient_id'] ?? 0;
        $bill_number = $bill_data['bill_number'] ?? '';
        
        // First mark all items as cancelled
        $cancel_items_sql = "UPDATE pending_bill_items 
                            SET is_cancelled = 1, 
                                cancelled_at = NOW(), 
                                cancelled_by = ? 
                            WHERE pending_bill_id = ?";
        $cancel_items_stmt = $mysqli->prepare($cancel_items_sql);
        $cancel_items_stmt->bind_param("ii", $user_id, $pending_bill_id);
        $cancel_items_stmt->execute();
        
        // Then cancel the bill
        $update_sql = "UPDATE pending_bills 
                      SET bill_status = 'cancelled', 
                          is_finalized = 0,
                          updated_at = NOW()
                      WHERE pending_bill_id = ?";
        $update_stmt = $mysqli->prepare($update_sql);
        $update_stmt->bind_param("i", $pending_bill_id);
        
        if ($update_stmt->execute()) {
            // AUDIT LOG: Log bill cancellation
            audit_log($mysqli, [
                'user_id'     => $user_id,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'DELETE',
                'module'      => 'Billing',
                'table_name'  => 'pending_bills',
                'entity_type' => 'pending_bill',
                'record_id'   => $pending_bill_id,
                'patient_id'  => $patient_id,
                'visit_id'    => $visit_id,
                'description' => "Cancelled pending bill: " . $bill_number,
                'status'      => 'SUCCESS',
                'old_values'  => [
                    'bill_status' => $bill_data['bill_status'] ?? '',
                    'bill_number' => $bill_number,
                    'patient_id' => $patient_id,
                    'visit_id' => $visit_id
                ],
                'new_values'  => [
                    'bill_status' => 'cancelled',
                    'is_finalized' => 0
                ]
            ]);
            
            $mysqli->commit();
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Pending bill cancelled successfully!";
            
            // Redirect
            header("Location: billing.php");
            exit;
        } else {
            throw new Exception("Failed to cancel pending bill");
        }
        
    } catch (Exception $e) {
        $mysqli->rollback();
        
        // AUDIT LOG: Log failed cancellation
        audit_log($mysqli, [
            'user_id'     => $user_id,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'DELETE',
            'module'      => 'Billing',
            'table_name'  => 'pending_bills',
            'entity_type' => 'pending_bill',
            'record_id'   => $pending_bill_id,
            'patient_id'  => 0,
            'visit_id'    => 0,
            'description' => "Failed to cancel pending bill #" . $pending_bill_id,
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => null,
            'error'       => $e->getMessage()
        ]);
        
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Delete Error: " . $e->getMessage();
        return false;
    }
}

function handleAddItemToBill($mysqli, $user_id) {
    global $visit_id, $visit_details;
    
    $pending_bill_id = intval($_POST['pending_bill_id'] ?? 0);
    $billable_item_id = intval($_POST['billable_item_id'] ?? 0);
    $quantity = floatval($_POST['quantity'] ?? 1);
    $price_list_item_id = intval($_POST['price_list_item_id'] ?? 0);
    
    if (!$pending_bill_id || !$billable_item_id) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid parameters";
        return false;
    }
    
    // Start transaction
    $mysqli->begin_transaction();
    
    try {
        // Get pending bill details
        $bill_sql = "SELECT pb.*, pl.price_list_type 
                     FROM pending_bills pb
                     LEFT JOIN price_lists pl ON pb.price_list_id = pl.price_list_id
                     WHERE pb.pending_bill_id = ?";
        $bill_stmt = $mysqli->prepare($bill_sql);
        $bill_stmt->bind_param("i", $pending_bill_id);
        $bill_stmt->execute();
        $bill_result = $bill_stmt->get_result();
        $pending_bill = $bill_result->fetch_assoc();
        
        if (!$pending_bill) {
            throw new Exception("Pending bill not found");
        }
        
        // Get billable item details
        $item_sql = "SELECT bi.* 
                     FROM billable_items bi
                     WHERE bi.billable_item_id = ?";
        $item_stmt = $mysqli->prepare($item_sql);
        $item_stmt->bind_param("i", $billable_item_id);
        $item_stmt->execute();
        $item_result = $item_stmt->get_result();
        $billable_item = $item_result->fetch_assoc();
        
        if (!$billable_item) {
            throw new Exception("Billable item not found");
        }
        
        // Get price list item price
        if ($price_list_item_id) {
            $price_sql = "SELECT unit_price FROM price_list_items WHERE price_list_item_id = ?";
            $price_stmt = $mysqli->prepare($price_sql);
            $price_stmt->bind_param("i", $price_list_item_id);
            $price_stmt->execute();
            $price_result = $price_stmt->get_result();
            $price_data = $price_result->fetch_assoc();
            $unit_price = $price_data['unit_price'] ?? $billable_item['unit_price'];
        } else {
            $unit_price = $billable_item['unit_price'];
        }
        
        // Calculate amounts
        $subtotal = $quantity * $unit_price;
        $discount_amount = 0; // Can be modified later
        $tax_amount = $billable_item['is_taxable'] ? ($subtotal * ($billable_item['tax_rate'] / 100)) : 0;
        $total_amount = $subtotal - $discount_amount + $tax_amount;
        
        // Check if item already exists in bill (not cancelled)
        $check_sql = "SELECT pending_bill_item_id 
                      FROM pending_bill_items 
                      WHERE pending_bill_id = ? 
                      AND billable_item_id = ?
                      AND price_list_item_id = ?
                      AND is_cancelled = 0";
        $check_stmt = $mysqli->prepare($check_sql);
        $check_stmt->bind_param("iii", $pending_bill_id, $billable_item_id, $price_list_item_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            // Update quantity of existing item
            $row = $check_result->fetch_assoc();
            
            $update_sql = "UPDATE pending_bill_items 
                          SET item_quantity = item_quantity + ?,
                              subtotal = subtotal + ?,
                              discount_amount = discount_amount + ?,
                              tax_amount = tax_amount + ?,
                              total_amount = total_amount + ?,
                              updated_at = NOW()
                          WHERE pending_bill_item_id = ?";
            
            $update_stmt = $mysqli->prepare($update_sql);
            $update_stmt->bind_param("dddddi", $quantity, $subtotal, $discount_amount, 
                                    $tax_amount, $total_amount, $row['pending_bill_item_id']);
            
            if (!$update_stmt->execute()) {
                throw new Exception("Failed to update item: " . $mysqli->error);
            }
            
        } else {
            // Insert new item
            $insert_sql = "INSERT INTO pending_bill_items 
                          (pending_bill_id, billable_item_id, price_list_item_id,
                           item_quantity, unit_price, discount_percentage, discount_amount,
                           tax_percentage, subtotal, tax_amount, total_amount,
                           source_type, source_id, created_by, created_at)
                          VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, 'manual', NULL, ?, NOW())";
            
            $insert_stmt = $mysqli->prepare($insert_sql);
            $insert_stmt->bind_param("iiidddddddii", 
                $pending_bill_id, $billable_item_id, $price_list_item_id,
                $quantity, $unit_price, $discount_amount,
                $billable_item['tax_rate'], $subtotal, $tax_amount, $total_amount,
                $user_id
            );
            
            if (!$insert_stmt->execute()) {
                throw new Exception("Failed to add item: " . $mysqli->error);
            }
            
            $pending_bill_item_id = $insert_stmt->insert_id;
            
            // AUDIT LOG: Log item addition
            audit_log($mysqli, [
                'user_id'     => $user_id,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'ADD',
                'module'      => 'Billing',
                'table_name'  => 'pending_bill_items',
                'entity_type' => 'bill_item',
                'record_id'   => $pending_bill_item_id,
                'patient_id'  => $pending_bill['patient_id'] ?? 0,
                'visit_id'    => $pending_bill['visit_id'] ?? 0,
                'description' => "Added item to bill: " . $billable_item['item_name'] . " (Qty: " . $quantity . ")",
                'status'      => 'SUCCESS',
                'old_values'  => null,
                'new_values'  => [
                    'billable_item_id' => $billable_item_id,
                    'item_name' => $billable_item['item_name'],
                    'item_quantity' => $quantity,
                    'unit_price' => $unit_price,
                    'subtotal' => $subtotal,
                    'tax_amount' => $tax_amount,
                    'total_amount' => $total_amount
                ]
            ]);
        }
        
        // Update pending bill totals
        updatePendingBillTotal($mysqli, $pending_bill_id);
        
        // Update invoice totals if invoice exists
        if ($pending_bill['invoice_id']) {
            updateInvoiceTotal($mysqli, $pending_bill['invoice_id']);
        }
        
        $mysqli->commit();
        
        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Item added to bill successfully!";
        return true;
        
    } catch (Exception $e) {
        $mysqli->rollback();
        
        // AUDIT LOG: Log failed item addition
        audit_log($mysqli, [
            'user_id'     => $user_id,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'ADD',
            'module'      => 'Billing',
            'table_name'  => 'pending_bill_items',
            'entity_type' => 'bill_item',
            'record_id'   => 0,
            'patient_id'  => 0,
            'visit_id'    => $visit_id,
            'description' => "Failed to add item to bill #" . $pending_bill_id,
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => null,
            'error'       => $e->getMessage()
        ]);
        
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error: " . $e->getMessage();
        return false;
    }
}

function handleRemoveBillItem($mysqli, $user_id) {
    $pending_bill_item_id = intval($_POST['pending_bill_item_id'] ?? 0);
    
    if (!$pending_bill_item_id) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Item ID required";
        return false;
    }
    
    // Start transaction
    $mysqli->begin_transaction();
    
    try {
        // Get item details for audit log
        $item_sql = "SELECT pbi.*, bi.item_name, pb.pending_bill_id, pb.patient_id, pb.visit_id
                     FROM pending_bill_items pbi
                     JOIN billable_items bi ON pbi.billable_item_id = bi.billable_item_id
                     JOIN pending_bills pb ON pbi.pending_bill_id = pb.pending_bill_id
                     WHERE pbi.pending_bill_item_id = ?";
        $item_stmt = $mysqli->prepare($item_sql);
        $item_stmt->bind_param("i", $pending_bill_item_id);
        $item_stmt->execute();
        $item_result = $item_stmt->get_result();
        $item_data = $item_result->fetch_assoc();
        
        if (!$item_data) {
            throw new Exception("Item not found");
        }
        
        $pending_bill_id = $item_data['pending_bill_id'] ?? 0;
        $patient_id = $item_data['patient_id'] ?? 0;
        $visit_id = $item_data['visit_id'] ?? 0;
        $item_name = $item_data['item_name'] ?? '';
        
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
            updatePendingBillTotal($mysqli, $pending_bill_id);
            
            // Update invoice total if invoice exists
            $bill_sql = "SELECT invoice_id FROM pending_bills WHERE pending_bill_id = ?";
            $bill_stmt = $mysqli->prepare($bill_sql);
            $bill_stmt->bind_param("i", $pending_bill_id);
            $bill_stmt->execute();
            $bill_result = $bill_stmt->get_result();
            $bill_data = $bill_result->fetch_assoc();
            
            if ($bill_data && $bill_data['invoice_id']) {
                updateInvoiceTotal($mysqli, $bill_data['invoice_id']);
            }
            
            // AUDIT LOG: Log item removal
            audit_log($mysqli, [
                'user_id'     => $user_id,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'REMOVE',
                'module'      => 'Billing',
                'table_name'  => 'pending_bill_items',
                'entity_type' => 'bill_item',
                'record_id'   => $pending_bill_item_id,
                'patient_id'  => $patient_id,
                'visit_id'    => $visit_id,
                'description' => "Removed item from bill: " . $item_name,
                'status'      => 'SUCCESS',
                'old_values'  => [
                    'item_name' => $item_name,
                    'item_quantity' => $item_data['item_quantity'] ?? 0,
                    'unit_price' => $item_data['unit_price'] ?? 0,
                    'subtotal' => $item_data['subtotal'] ?? 0,
                    'tax_amount' => $item_data['tax_amount'] ?? 0,
                    'total_amount' => $item_data['total_amount'] ?? 0
                ],
                'new_values'  => [
                    'is_cancelled' => 1,
                    'cancelled_by' => $user_id
                ]
            ]);
            
            $mysqli->commit();
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Item removed from bill successfully!";
            return true;
        } else {
            throw new Exception("Failed to remove item");
        }
        
    } catch (Exception $e) {
        $mysqli->rollback();
        
        // AUDIT LOG: Log failed item removal
        audit_log($mysqli, [
            'user_id'     => $user_id,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'REMOVE',
            'module'      => 'Billing',
            'table_name'  => 'pending_bill_items',
            'entity_type' => 'bill_item',
            'record_id'   => $pending_bill_item_id,
            'patient_id'  => 0,
            'visit_id'    => 0,
            'description' => "Failed to remove item #" . $pending_bill_item_id . " from bill",
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => null,
            'error'       => $e->getMessage()
        ]);
        
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error: " . $e->getMessage();
        return false;
    }
}

function handleCreateInvoiceFromBill($mysqli, $user_id) {
    $pending_bill_id = intval($_POST['pending_bill_id'] ?? 0);
    
    if (!$pending_bill_id) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Pending bill ID required";
        return false;
    }
    
    // Start transaction
    $mysqli->begin_transaction();
    
    try {
        // Get pending bill details
        $bill_sql = "SELECT pb.*, CONCAT(p.first_name, ' ', p.last_name) as patient_name, p.patient_mrn, pl.price_list_name, pl.price_list_type
                     FROM pending_bills pb
                     JOIN patients p ON pb.patient_id = p.patient_id
                     JOIN price_lists pl ON pb.price_list_id = pl.price_list_id
                     WHERE pb.pending_bill_id = ?";
        $bill_stmt = $mysqli->prepare($bill_sql);
        $bill_stmt->bind_param("i", $pending_bill_id);
        $bill_stmt->execute();
        $bill_result = $bill_stmt->get_result();
        $pending_bill = $bill_result->fetch_assoc();
        
        if (!$pending_bill) {
            throw new Exception("Pending bill not found");
        }
        
        // Check if bill is already invoiced
        if ($pending_bill['invoice_id']) {
            throw new Exception("This bill has already been invoiced");
        }
        
        // Check if bill is finalized
        if (!$pending_bill['is_finalized']) {
            throw new Exception("Bill must be finalized before creating invoice");
        }
        
        // Generate invoice number
        $invoice_number = generateInvoiceNumber($mysqli);
        
        // Create invoice
        $invoice_sql = "INSERT INTO invoices 
                       (invoice_number, pending_bill_id, visit_id, patient_id, patient_name, patient_mrn,
                        price_list_id, price_list_name, price_list_type,
                        subtotal_amount, discount_amount, tax_amount, total_amount,
                        amount_due, invoice_status, invoice_date, due_date, payment_terms,
                        created_by, finalized_at, finalized_by)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'issued', CURDATE(), 
                               DATE_ADD(CURDATE(), INTERVAL 30 DAY), 'Net 30', ?, NOW(), ?)";
        
        $invoice_stmt = $mysqli->prepare($invoice_sql);
        $invoice_stmt->bind_param("siiisssiisdddddssii", 
            $invoice_number, $pending_bill_id, $pending_bill['visit_id'], $pending_bill['patient_id'],
            $pending_bill['patient_name'], $pending_bill['patient_mrn'], $pending_bill['price_list_id'],
            $pending_bill['price_list_name'], $pending_bill['price_list_type'],
            $pending_bill['subtotal_amount'], $pending_bill['discount_amount'], $pending_bill['tax_amount'],
            $pending_bill['total_amount'], $pending_bill['total_amount'], // amount_due initially equals total
            $user_id, $user_id
        );
        
        if (!$invoice_stmt->execute()) {
            throw new Exception("Failed to create invoice: " . $mysqli->error);
        }
        
        $invoice_id = $mysqli->insert_id;
        
        // Get bill items and create invoice items
        $items_sql = "SELECT pbi.*, bi.item_code, bi.item_name, bi.item_description, bi.item_type
                      FROM pending_bill_items pbi
                      JOIN billable_items bi ON pbi.billable_item_id = bi.billable_item_id
                      WHERE pbi.pending_bill_id = ? AND pbi.is_cancelled = 0";
        $items_stmt = $mysqli->prepare($items_sql);
        $items_stmt->bind_param("i", $pending_bill_id);
        $items_stmt->execute();
        $items_result = $items_stmt->get_result();
        
        while ($item = $items_result->fetch_assoc()) {
            $item_sql = "INSERT INTO invoice_items 
                        (invoice_id, billable_item_id, item_code, item_name, item_description,
                         price_list_item_id, unit_price, item_quantity, discount_percentage, discount_amount,
                         tax_percentage, subtotal, tax_amount, total_amount, source_type, source_id)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $item_stmt = $mysqli->prepare($item_sql);
            $item_stmt->bind_param("iisssiidddddddssi",
                $invoice_id, $item['billable_item_id'], $item['item_code'], $item['item_name'], 
                $item['item_description'], $item['price_list_item_id'], $item['unit_price'],
                $item['item_quantity'], $item['discount_percentage'], $item['discount_amount'],
                $item['tax_percentage'], $item['subtotal'], $item['tax_amount'], $item['total_amount'],
                $item['source_type'], $item['source_id']
            );
            
            if (!$item_stmt->execute()) {
                throw new Exception("Failed to create invoice item: " . $mysqli->error);
            }
        }
        
        // Update pending bill with invoice reference
        $update_bill_sql = "UPDATE pending_bills SET invoice_id = ?, updated_at = NOW() WHERE pending_bill_id = ?";
        $update_bill_stmt = $mysqli->prepare($update_bill_sql);
        $update_bill_stmt->bind_param("ii", $invoice_id, $pending_bill_id);
        $update_bill_stmt->execute();
        
        // AUDIT LOG: Log invoice creation
        audit_log($mysqli, [
            'user_id'     => $user_id,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'CREATE',
            'module'      => 'Billing',
            'table_name'  => 'invoices',
            'entity_type' => 'invoice',
            'record_id'   => $invoice_id,
            'patient_id'  => $pending_bill['patient_id'],
            'visit_id'    => $pending_bill['visit_id'],
            'description' => "Created invoice: " . $invoice_number . " from pending bill: " . $pending_bill['bill_number'],
            'status'      => 'SUCCESS',
            'old_values'  => null,
            'new_values'  => [
                'invoice_number' => $invoice_number,
                'pending_bill_id' => $pending_bill_id,
                'visit_id' => $pending_bill['visit_id'],
                'patient_id' => $pending_bill['patient_id'],
                'subtotal_amount' => $pending_bill['subtotal_amount'],
                'discount_amount' => $pending_bill['discount_amount'],
                'tax_amount' => $pending_bill['tax_amount'],
                'total_amount' => $pending_bill['total_amount'],
                'invoice_status' => 'issued'
            ]
        ]);
        
        $mysqli->commit();
        
        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Invoice created successfully! Invoice #: " . $invoice_number;
        
        // Redirect to invoice
        header("Location: billing_invoice_view.php?invoice_id=" . $invoice_id);
        exit;
        
    } catch (Exception $e) {
        $mysqli->rollback();
        
        // AUDIT LOG: Log failed invoice creation
        audit_log($mysqli, [
            'user_id'     => $user_id,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'CREATE',
            'module'      => 'Billing',
            'table_name'  => 'invoices',
            'entity_type' => 'invoice',
            'record_id'   => 0,
            'patient_id'  => 0,
            'visit_id'    => 0,
            'description' => "Failed to create invoice from pending bill #" . $pending_bill_id,
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => null,
            'error'       => $e->getMessage()
        ]);
        
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error: " . $e->getMessage();
        return false;
    }
}

// Helper functions
function getPendingBillDetails($mysqli, $pending_bill_id, $visit_id) {
    $sql = "SELECT pb.*, 
                   p.first_name, p.last_name,
                   pl.price_list_name,
                   pl.price_list_type,
                   u.user_name as created_by_name,
                   i.invoice_number,
                   i.invoice_status
            FROM pending_bills pb
            JOIN patients p ON pb.patient_id = p.patient_id
            LEFT JOIN price_lists pl ON pb.price_list_id = pl.price_list_id
            LEFT JOIN users u ON pb.created_by = u.user_id
            LEFT JOIN invoices i ON pb.invoice_id = i.invoice_id
            WHERE pb.pending_bill_id = ? 
            AND pb.visit_id = ?
            AND pb.bill_status != 'cancelled'";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("ii", $pending_bill_id, $visit_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->fetch_assoc();
}

function getPendingBillItems($mysqli, $pending_bill_id) {
    $items = [];
    
    $sql = "SELECT pbi.*, 
                   bi.item_code,
                   bi.item_name,
                   bi.item_description,
                   bi.item_type,
                   pli.price_list_item_id,
                   pli.unit_price as list_price
            FROM pending_bill_items pbi
            JOIN billable_items bi ON pbi.billable_item_id = bi.billable_item_id
            LEFT JOIN price_list_items pli ON pbi.price_list_item_id = pli.price_list_item_id
            WHERE pbi.pending_bill_id = ?
            AND pbi.is_cancelled = 0
            ORDER BY pbi.created_at ASC";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("i", $pending_bill_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
    }
    
    return $items;
}

function updatePendingBillTotal($mysqli, $pending_bill_id) {
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
    $total_amount = $total_row['total_amount'] ?? 0;
    
    // Get bill discount
    $bill_sql = "SELECT discount_amount FROM pending_bills WHERE pending_bill_id = ?";
    $bill_stmt = $mysqli->prepare($bill_sql);
    $bill_stmt->bind_param("i", $pending_bill_id);
    $bill_stmt->execute();
    $bill_result = $bill_stmt->get_result();
    $bill_row = $bill_result->fetch_assoc();
    
    $bill_discount_amount = $bill_row['discount_amount'] ?? 0;
    
    // Apply bill discount to total (after tax)
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

function updateInvoiceTotal($mysqli, $invoice_id) {
    // Get bill totals from associated pending bill
    $bill_sql = "SELECT subtotal_amount, discount_amount, tax_amount, total_amount 
                 FROM pending_bills 
                 WHERE invoice_id = ?";
    $bill_stmt = $mysqli->prepare($bill_sql);
    $bill_stmt->bind_param("i", $invoice_id);
    $bill_stmt->execute();
    $bill_result = $bill_stmt->get_result();
    $bill_row = $bill_result->fetch_assoc();
    
    if ($bill_row) {
        $update_sql = "UPDATE invoices 
                      SET subtotal_amount = ?,
                          discount_amount = ?,
                          tax_amount = ?,
                          total_amount = ?,
                          amount_due = ?,
                          updated_at = NOW()
                      WHERE invoice_id = ?";
        
        $update_stmt = $mysqli->prepare($update_sql);
        $update_stmt->bind_param("dddddi", 
            $bill_row['subtotal_amount'],
            $bill_row['discount_amount'],
            $bill_row['tax_amount'],
            $bill_row['total_amount'],
            $bill_row['total_amount'],
            $invoice_id
        );
        $update_stmt->execute();
    }
}

function searchBillableItems($mysqli, $search_term, $item_type = 'all') {
    $items = [];
    
    $sql = "SELECT bi.*, 
                   GROUP_CONCAT(DISTINCT pl.price_list_name SEPARATOR ', ') as price_lists,
                   GROUP_CONCAT(DISTINCT pli.price_list_item_id SEPARATOR ',') as price_list_item_ids,
                   GROUP_CONCAT(DISTINCT pli.unit_price SEPARATOR ',') as price_list_prices
            FROM billable_items bi
            LEFT JOIN price_list_items pli ON bi.billable_item_id = pli.billable_item_id
            LEFT JOIN price_lists pl ON pli.price_list_id = pl.price_list_id
            WHERE bi.is_active = 1
            AND (bi.item_name LIKE ? OR bi.item_code LIKE ? OR bi.item_description LIKE ?)";
    
    if ($item_type != 'all') {
        $sql .= " AND bi.item_type = ?";
    }
    
    $sql .= " GROUP BY bi.billable_item_id
              ORDER BY bi.item_name
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
        // Parse price list data
        if ($row['price_list_item_ids']) {
            $price_list_ids = explode(',', $row['price_list_item_ids']);
            $price_list_prices = explode(',', $row['price_list_prices']);
            $row['price_options'] = [];
            for ($i = 0; $i < count($price_list_ids); $i++) {
                if ($price_list_ids[$i]) {
                    $row['price_options'][] = [
                        'id' => $price_list_ids[$i],
                        'price' => $price_list_prices[$i]
                    ];
                }
            }
        }
        $items[] = $row;
    }
    
    return $items;
}

// Search for billable items
$search_results = [];
$search_term = $_GET['search'] ?? '';
$search_item_type = $_GET['item_type'] ?? 'all';

if ($search_term && $visit_id) {
    $search_results = searchBillableItems($mysqli, $search_term, $search_item_type);
}

// Reset pointer for main query
if ($pending_bills) {
    $pending_bills->data_seek(0);
}
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-file-invoice-dollar mr-2"></i>
            Billing Management
        </h3>
        <div class="card-tools">
            <div class="btn-group">
                <a href="invoices.php" class="btn btn-light">
                    <i class="fas fa-list mr-2"></i>All Invoices
                </a>
                <a href="billing_create.php" class="btn btn-success ml-2">
                    <i class="fas fa-plus mr-2"></i>Create New Bill
                </a>
            </div>
        </div>
    </div>

    <div class="card-header pb-2 pt-3">
        <form autocomplete="off">
            <div class="row">
                <div class="col-md-5">
                    <div class="form-group">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search bills, patients, visit numbers..." autofocus>
                            <div class="input-group-append">
                                <button class="btn btn-secondary" type="button" data-toggle="collapse" data-target="#advancedFilter"><i class="fas fa-filter"></i></button>
                                <button class="btn btn-primary"><i class="fa fa-search"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-7">
                    <div class="btn-toolbar form-group float-right">
                        <div class="btn-group">
                            <span class="btn btn-light border">
                                <i class="fas fa-file-invoice text-info mr-1"></i>
                                Total Bills: <strong><?php echo $total_bills; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-clock text-warning mr-1"></i>
                                Draft: <strong><?php echo $draft_bills; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-dollar-sign text-success mr-1"></i>
                                Value: <strong>KSH <?php echo number_format($total_value, 2); ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-shield-alt text-primary mr-1"></i>
                                Insurance: <strong><?php echo $insurance_bills; ?></strong>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php if ($status_filter || $payment_mode_filter || isset($_GET['dtf'])) { echo "show"; } ?>" id="advancedFilter">
                <div class="row">
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Status</label>
                            <select class="form-control select2" name="status" onchange="this.form.submit()">
                                <option value="">- All Statuses -</option>
                                <?php while($status = $statuses->fetch_assoc()): ?>
                                    <option value="<?php echo htmlspecialchars($status['bill_status']); ?>" <?php if ($status_filter == $status['bill_status']) { echo "selected"; } ?>>
                                        <?php echo ucfirst(htmlspecialchars($status['bill_status'])); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Payment Mode</label>
                            <select class="form-control select2" name="payment_mode" onchange="this.form.submit()">
                                <option value="">- All Modes -</option>
                                <option value="cash" <?php if ($payment_mode_filter == 'cash') { echo "selected"; } ?>>Cash</option>
                                <option value="insurance" <?php if ($payment_mode_filter == 'insurance') { echo "selected"; } ?>>Insurance</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Visit Type</label>
                            <select class="form-control select2" name="visit_type" onchange="this.form.submit()">
                                <option value="">- All Types -</option>
                                <?php while($visit_type = $visit_types->fetch_assoc()): ?>
                                    <option value="<?php echo htmlspecialchars($visit_type['visit_type']); ?>" <?php if ($visit_type_filter == $visit_type['visit_type']) { echo "selected"; } ?>>
                                        <?php echo htmlspecialchars($visit_type['visit_type']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Date Range</label>
                            <select onchange="this.form.submit()" class="form-control select2" name="canned_date">
                                <option <?php if (($_GET['canned_date'] ?? '') == "custom") { echo "selected"; } ?> value="custom">Custom</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "today") { echo "selected"; } ?> value="today">Today</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "yesterday") { echo "selected"; } ?> value="yesterday">Yesterday</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "thisweek") { echo "selected"; } ?> value="thisweek">This Week</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "lastweek") { echo "selected"; } ?> value="lastweek">Last Week</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "thismonth") { echo "selected"; } ?> value="thismonth">This Month</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "lastmonth") { echo "selected"; } ?> value="lastmonth">Last Month</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Date from</label>
                            <input onchange="this.form.submit()" type="date" class="form-control" name="dtf" max="2999-12-31" value="<?php echo nullable_htmlentities($dtf); ?>">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Date to</label>
                            <input onchange="this.form.submit()" type="date" class="form-control" name="dtt" max="2999-12-31" value="<?php echo nullable_htmlentities($dtt); ?>">
                        </div>
                    </div>
                </div>
            </div>
        </form>
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

        <!-- Alert Container for AJAX Messages -->
        <div id="ajaxAlertContainer"></div>
    
        <div class="table-responsive-sm">
            <table class="table table-hover mb-0">
                <thead class="<?php if ($num_rows[0] == 0) { echo "d-none"; } ?> bg-light">
                <tr>
                    <th>
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=pb.bill_number&order=<?php echo $disp; ?>">
                            Bill Number <?php if ($sort == 'pb.bill_number') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>Patient Details</th>
                    <th>
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=v.visit_type&order=<?php echo $disp; ?>">
                            Visit Details <?php if ($sort == 'v.visit_type') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=pb.bill_status&order=<?php echo $disp; ?>">
                            Status <?php if ($sort == 'pb.bill_status') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=pb.total_amount&order=<?php echo $disp; ?>">
                            Amount <?php if ($sort == 'pb.total_amount') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=pb.created_at&order=<?php echo $disp; ?>">
                            Created <?php if ($sort == 'pb.created_at') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th class="text-center">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($pending_bills && $pending_bills->num_rows > 0): ?>
                    <?php while($bill = $pending_bills->fetch_assoc()): 
                        $bill_id = intval($bill['pending_bill_id']);
                        $bill_number = nullable_htmlentities($bill['bill_number']);
                        $patient_name = nullable_htmlentities($bill['first_name'] . ' ' . $bill['last_name']);
                        $patient_mrn = nullable_htmlentities($bill['patient_mrn']);
                        $visit_number = nullable_htmlentities($bill['visit_number']);
                        $visit_type = nullable_htmlentities($bill['visit_type']);
                        $visit_date = nullable_htmlentities(date('M j, Y', strtotime($bill['visit_datetime'])));
                        $bill_status = nullable_htmlentities($bill['bill_status']);
                        $is_finalized = intval($bill['is_finalized']);
                        $total_amount = floatval($bill['total_amount']);
                        $created_date = nullable_htmlentities(date('M j, Y', strtotime($bill['created_at'])));
                        $item_count = intval($bill['item_count']);
                        $insurance_company = nullable_htmlentities($bill['insurance_company'] ?? '');
                        $price_list_name = nullable_htmlentities($bill['price_list_name'] ?? '');
                        $invoice_number = nullable_htmlentities($bill['invoice_number'] ?? '');
                        ?>
                        <tr>
                            <td>
                                <div class="font-weight-bold text-info"><?php echo $bill_number; ?></div>
                                <?php if ($invoice_number): ?>
                                    <small class="text-success">Invoice: <?php echo $invoice_number; ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="font-weight-bold"><?php echo $patient_name; ?></div>
                                <small class="text-muted">MRN: <?php echo $patient_mrn; ?></small>
                                <?php if ($insurance_company): ?>
                                    <br><small class="text-primary"><i class="fas fa-shield-alt"></i> <?php echo $insurance_company; ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="font-weight-bold"><?php echo $visit_number; ?></div>
                                <small class="text-muted"><?php echo $visit_type; ?> - <?php echo $visit_date; ?></small>
                                <br><small class="text-muted">Items: <?php echo $item_count; ?></small>
                            </td>
                            <td>
                                <?php 
                                $status_badges = [
                                    'draft' => 'badge-secondary',
                                    'pending' => 'badge-warning',
                                    'approved' => 'badge-success',
                                    'cancelled' => 'badge-danger'
                                ];
                                $badge_class = $status_badges[$bill_status] ?? 'badge-secondary';
                                ?>
                                <span class="badge <?php echo $badge_class; ?>">
                                    <?php echo ucfirst($bill_status); ?>
                                </span>
                                <?php if ($is_finalized): ?>
                                    <span class="badge badge-success ml-1">Finalized</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="font-weight-bold text-success">KSH <?php echo number_format($total_amount, 2); ?></div>
                                <?php if ($price_list_name): ?>
                                    <small class="text-muted"><?php echo $price_list_name; ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <small><?php echo $created_date; ?></small>
                                <?php if ($bill['created_by_name']): ?>
                                    <br><small class="text-muted">by <?php echo $bill['created_by_name']; ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="dropdown dropleft text-center">
                                    <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                        <i class="fas fa-ellipsis-h"></i>
                                    </button>
                                    <div class="dropdown-menu">
                                        <a class="dropdown-item" href="billing_view.php?pending_bill_id=<?php echo $bill_id; ?>">
                                            <i class="fas fa-fw fa-eye mr-2"></i>View Details
                                        </a>
                                        <a class="dropdown-item" href="billing_edit.php?pending_bill_id=<?php echo $bill_id; ?>">
                                            <i class="fas fa-fw fa-edit mr-2"></i>Edit Bill
                                        </a>
                                        <?php if ($bill_status == 'draft'): ?>
                                            <div class="dropdown-divider"></div>
                                            <a class="dropdown-item text-danger" href="#" onclick="deleteBill(<?php echo $bill_id; ?>)">
                                                <i class="fas fa-fw fa-trash mr-2"></i>Delete Bill
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" class="text-center py-4">
                            <i class="fas fa-file-invoice-dollar fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">No Bills Found</h5>
                            <p class="text-muted">
                                <?php echo ($status_filter || $payment_mode_filter || $search_query) ? 
                                    'Try adjusting your filters or search criteria.' : 
                                    'Get started by creating your first bill.'; 
                                ?>
                            </p>
                            <a href="billing_create.php" class="btn btn-success mt-2">
                                <i class="fas fa-plus mr-2"></i>Create First Bill
                            </a>
                            <?php if ($status_filter || $payment_mode_filter || $search_query): ?>
                                <a href="billing.php" class="btn btn-outline-secondary mt-2 ml-2">
                                    <i class="fas fa-times mr-2"></i>Clear Filters
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Ends Card Body -->
        <?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/filter_footer.php'; ?>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.select2').select2();

    // Auto-submit when date range changes
    $('input[type="date"]').change(function() {
        if ($(this).val()) {
            $(this).closest('form').submit();
        }
    });

    // Auto-submit date range when canned date is selected
    $('select[name="canned_date"]').change(function() {
        if ($(this).val() !== 'custom') {
            $(this).closest('form').submit();
        }
    });

    // Auto-close alerts after 5 seconds
    setTimeout(() => {
        $('.alert').alert('close');
    }, 5000);
});

function deleteBill(billId) {
    if (confirm('Are you sure you want to delete this bill? This action will cancel the bill and all its items.')) {
        const form = document.createElement('form');
        form.method = 'post';
        form.action = '';
        
        const csrfToken = document.createElement('input');
        csrfToken.type = 'hidden';
        csrfToken.name = 'csrf_token';
        csrfToken.value = '<?php echo $_SESSION['csrf_token']; ?>';
        form.appendChild(csrfToken);
        
        const action = document.createElement('input');
        action.type = 'hidden';
        action.name = 'action';
        action.value = 'delete_pending_bill';
        form.appendChild(action);
        
        const billIdInput = document.createElement('input');
        billIdInput.type = 'hidden';
        billIdInput.name = 'pending_bill_id';
        billIdInput.value = billId;
        form.appendChild(billIdInput);
        
        document.body.appendChild(form);
        form.submit();
    }
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + N for new bill
    if (e.ctrlKey && e.keyCode === 78) {
        e.preventDefault();
        window.location.href = 'billing_create.php';
    }
    // Ctrl + F for focus search
    if (e.ctrlKey && e.keyCode === 70) {
        e.preventDefault();
        $('input[name="q"]').focus();
    }
});
</script>

<style>
.required:after {
    content: " *";
    color: #dc3545;
}
.card {
    border: none;
    box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
}
.card-header {
    border-bottom: 1px solid #e3e6f0;
}
.border-left-primary {
    border-left: 0.25rem solid #4e73df !important;
}
.border-left-success {
    border-left: 0.25rem solid #1cc88a !important;
}
.border-left-info {
    border-left: 0.25rem solid #36b9cc !important;
}
.border-left-warning {
    border-left: 0.25rem solid #f6c23e !important;
}
.table th {
    border-top: none;
    font-weight: 600;
    color: #6e707e;
    font-size: 0.85rem;
    text-transform: uppercase;
}
.badge-pill {
    padding: 0.5em 0.8em;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
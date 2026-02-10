<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/audit_functions.php';

// Check if pending_bill_id is provided
$pending_bill_id = isset($_GET['pending_bill_id']) ? intval($_GET['pending_bill_id']) : 0;

if (!$pending_bill_id) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "No pending bill specified!";
    header("Location: billing.php");
    exit;
}

// Fetch pending bill details
$bill_sql = "SELECT pb.*, 
                    v.visit_id, v.visit_number, v.visit_type, 
                    p.patient_id, p.first_name as patient_first_name, p.last_name as patient_last_name, p.patient_mrn,
                    pl.price_list_name, pl.price_list_type,
                    u.user_name as created_by_name,
                    fu.user_name as finalized_by_name
             FROM pending_bills pb
             JOIN visits v ON pb.visit_id = v.visit_id
             JOIN patients p ON pb.patient_id = p.patient_id
             LEFT JOIN price_lists pl ON pb.price_list_id = pl.price_list_id
             LEFT JOIN users u ON pb.created_by = u.user_id
             LEFT JOIN users fu ON pb.finalized_by = fu.user_id
             WHERE pb.pending_bill_id = ? 
             AND pb.bill_status != 'cancelled'";
             
$bill_stmt = $mysqli->prepare($bill_sql);
$bill_stmt->bind_param("i", $pending_bill_id);
$bill_stmt->execute();
$bill_result = $bill_stmt->get_result();
$pending_bill = $bill_result->fetch_assoc();

if (!$pending_bill) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Pending bill not found or already invoiced!";
    header("Location: billing.php");
    exit;
}

$visit_id = $pending_bill['visit_id'];
$patient_id = $pending_bill['patient_id'];

// Fetch bill items with invoice status
$items_sql = "SELECT pbi.*, 
                     bi.item_code, bi.item_name, bi.item_description, bi.item_type, bi.is_taxable, bi.tax_rate,
                     pli.price_list_item_id,
                     pli.price as list_price,
                     pl.price_list_name,
                     pl.price_list_type,
                     CASE 
                         WHEN pbi.invoice_item_id IS NOT NULL THEN 'finalized'
                         WHEN pbi.price_list_item_id IS NOT NULL THEN 'price_list_assigned'
                         ELSE 'pending'
                     END as item_status,
                     pbi.invoice_item_id
              FROM pending_bill_items pbi
              JOIN billable_items bi ON pbi.billable_item_id = bi.billable_item_id
              LEFT JOIN price_list_items pli ON pbi.price_list_item_id = pli.price_list_item_id
              LEFT JOIN price_lists pl ON pli.price_list_id = pl.price_list_id
              WHERE pbi.pending_bill_id = ?
              AND pbi.is_cancelled = 0
              ORDER BY 
                CASE 
                    WHEN pbi.invoice_item_id IS NOT NULL THEN 1
                    WHEN pbi.price_list_item_id IS NOT NULL THEN 2
                    ELSE 3
                END,
                pbi.created_at ASC";
              
$items_stmt = $mysqli->prepare($items_sql);
$items_stmt->bind_param("i", $pending_bill_id);
$items_stmt->execute();
$items_result = $items_stmt->get_result();
$bill_items = [];

while ($row = $items_result->fetch_assoc()) {
    $bill_items[] = $row;
}

// Get today's billing statistics
$today_bills_sql = "SELECT 
    COUNT(*) as total_bills,
    SUM(CASE WHEN bill_status = 'draft' THEN 1 ELSE 0 END) as draft_bills,
    SUM(CASE WHEN bill_status = 'pending' THEN 1 ELSE 0 END) as pending_bills,
    SUM(CASE WHEN bill_status = 'approved' THEN 1 ELSE 0 END) as approved_bills
    FROM pending_bills 
    WHERE DATE(created_at) = CURDATE()";
$today_bills_result = $mysqli->query($today_bills_sql);
$today_bills_stats = $today_bills_result->fetch_assoc();

// Get today's invoice statistics
$today_invoices_sql = "SELECT 
    COUNT(*) as total_invoices,
    SUM(CASE WHEN invoice_status = 'issued' THEN 1 ELSE 0 END) as issued_invoices,
    SUM(CASE WHEN invoice_status = 'partially_paid' THEN 1 ELSE 0 END) as partially_paid_invoices,
    SUM(CASE WHEN invoice_status = 'paid' THEN 1 ELSE 0 END) as paid_invoices
    FROM invoices 
    WHERE DATE(created_at) = CURDATE()";
$today_invoices_result = $mysqli->query($today_invoices_sql);
$today_invoices_stats = $today_invoices_result->fetch_assoc();

// Get available price lists
$price_lists_sql = "SELECT pl.* FROM price_lists pl WHERE pl.is_active = 1 ORDER BY pl.price_list_name";
$price_lists_result = $mysqli->query($price_lists_sql);
$price_lists = [];
while ($row = $price_lists_result->fetch_assoc()) {
    $price_lists[] = $row;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $csrf_token = sanitizeInput($_POST['csrf_token'] ?? '');
        
        // Validate CSRF token
        if (!validateCsrfToken($csrf_token)) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
            header("Location: billing_edit.php?pending_bill_id=" . $pending_bill_id);
            exit;
        }
        
        switch ($_POST['action']) {
            case 'update_bill':
                handleUpdateBill($mysqli, $session_user_id, $pending_bill_id);
                break;
                
            case 'finalize_bill':
                handleFinalizeBill($mysqli, $session_user_id, $pending_bill_id);
                break;
                
            case 'add_item':
                handleAddItem($mysqli, $session_user_id, $pending_bill_id);
                break;
                
            case 'remove_item':
                handleRemoveItem($mysqli, $session_user_id);
                break;
                
            case 'update_item':
                handleUpdateItem($mysqli, $session_user_id);
                break;
                
            case 'update_item_price_list':
                handleUpdateItemPriceList($mysqli, $session_user_id);
                break;
                
            case 'search_items':
                $search_term = $_POST['search_term'] ?? '';
                $item_type = $_POST['item_type'] ?? 'all';
                header("Location: billing_edit.php?pending_bill_id=" . $pending_bill_id . "&search=" . urlencode($search_term) . "&item_type=" . $item_type);
                exit;
                break;
                
            case 'create_invoice':
                handleCreateInvoice($mysqli, $session_user_id, $pending_bill_id, $visit_id);
                break;
                
            case 'finalize_item_to_invoice':
                handleFinalizeItemToInvoice($mysqli, $session_user_id);
                break;
                
            case 'assign_price_list_to_item':
                handleAssignPriceListToItem($mysqli, $session_user_id);
                break;
        }
    }
}

// Search for billable items
$search_results = [];
$search_term = $_GET['search'] ?? '';
$search_item_type = $_GET['item_type'] ?? 'all';

if ($search_term && !$pending_bill['is_finalized']) {
    $search_results = searchBillableItems($mysqli, $search_term, $search_item_type);
}

// Functions
function handleUpdateBill($mysqli, $user_id, $pending_bill_id) {
    // Get bill details for audit log
    $old_bill_sql = "SELECT pb.*, CONCAT(p.first_name, ' ', p.last_name) as patient_name 
                     FROM pending_bills pb
                     JOIN patients p ON pb.patient_id = p.patient_id
                     WHERE pb.pending_bill_id = ?";
    $old_bill_stmt = $mysqli->prepare($old_bill_sql);
    $old_bill_stmt->bind_param("i", $pending_bill_id);
    $old_bill_stmt->execute();
    $old_bill_result = $old_bill_stmt->get_result();
    $old_bill_data = $old_bill_result->fetch_assoc();
    
    $bill_status = sanitizeInput($_POST['bill_status'] ?? '');
    $notes = sanitizeInput($_POST['notes'] ?? '');
    $discount_amount = floatval($_POST['discount_amount'] ?? 0);
    $price_list_id = intval($_POST['price_list_id'] ?? 0);
    
    // Start transaction
    $mysqli->begin_transaction();
    
    try {
        // Get current bill totals from items
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
        
        // Calculate total with discount
        $total_amount = $subtotal_amount - $discount_amount;
        if ($total_amount < 0) $total_amount = 0;
        $total_amount += $total_tax_amount;
        
        $sql = "UPDATE pending_bills 
                SET bill_status = ?, 
                    notes = ?, 
                    discount_amount = ?,
                    price_list_id = ?,
                    subtotal_amount = ?,
                    tax_amount = ?,
                    total_amount = ?,
                    updated_at = NOW()
                WHERE pending_bill_id = ?";
        
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("ssdidddi", $bill_status, $notes, $discount_amount, $price_list_id,
                         $subtotal_amount, $total_tax_amount, $total_amount, $pending_bill_id);
        
        if ($stmt->execute()) {
            // Update invoice totals if invoice exists
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
                'patient_id'  => $old_bill_data['patient_id'] ?? 0,
                'visit_id'    => $old_bill_data['visit_id'] ?? 0,
                'description' => "Updated pending bill #" . $old_bill_data['bill_number'],
                'status'      => 'SUCCESS',
                'old_values'  => [
                    'bill_status' => $old_bill_data['bill_status'] ?? '',
                    'discount_amount' => $old_bill_data['discount_amount'] ?? 0,
                    'price_list_id' => $old_bill_data['price_list_id'] ?? 0,
                    'notes' => $old_bill_data['notes'] ?? '',
                    'subtotal_amount' => $old_bill_data['subtotal_amount'] ?? 0,
                    'tax_amount' => $old_bill_data['tax_amount'] ?? 0,
                    'total_amount' => $old_bill_data['total_amount'] ?? 0
                ],
                'new_values'  => [
                    'bill_status' => $bill_status,
                    'discount_amount' => $discount_amount,
                    'price_list_id' => $price_list_id,
                    'notes' => $notes,
                    'subtotal_amount' => $subtotal_amount,
                    'tax_amount' => $total_tax_amount,
                    'total_amount' => $total_amount
                ]
            ]);
            
            $mysqli->commit();
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Bill updated successfully!";
            return true;
        } else {
            throw new Exception("Failed to update bill: " . $mysqli->error);
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
            'patient_id'  => $old_bill_data['patient_id'] ?? 0,
            'visit_id'    => $old_bill_data['visit_id'] ?? 0,
            'description' => "Failed to update pending bill #" . ($old_bill_data['bill_number'] ?? $pending_bill_id),
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

function handleFinalizeBill($mysqli, $user_id, $pending_bill_id) {
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
    
    // Start transaction
    $mysqli->begin_transaction();
    
    try {
        // Check if bill has items
        $check_sql = "SELECT COUNT(*) as item_count FROM pending_bill_items WHERE pending_bill_id = ? AND is_cancelled = 0";
        $check_stmt = $mysqli->prepare($check_sql);
        $check_stmt->bind_param("i", $pending_bill_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $check_row = $check_result->fetch_assoc();
        
        if ($check_row['item_count'] == 0) {
            throw new Exception("Cannot finalize empty bill. Add items first.");
        }
        
        // Finalize the bill
        $update_sql = "UPDATE pending_bills 
                      SET bill_status = 'approved',
                          is_finalized = 1,
                          finalized_at = NOW(),
                          finalized_by = ?,
                          updated_at = NOW()
                      WHERE pending_bill_id = ?";
        
        $update_stmt = $mysqli->prepare($update_sql);
        $update_stmt->bind_param("ii", $user_id, $pending_bill_id);
        
        if (!$update_stmt->execute()) {
            throw new Exception("Failed to finalize bill: " . $mysqli->error);
        }
        
        // AUDIT LOG: Log bill finalization
        audit_log($mysqli, [
            'user_id'     => $user_id,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'UPDATE',
            'module'      => 'Billing',
            'table_name'  => 'pending_bills',
            'entity_type' => 'pending_bill',
            'record_id'   => $pending_bill_id,
            'patient_id'  => $bill_data['patient_id'] ?? 0,
            'visit_id'    => $bill_data['visit_id'] ?? 0,
            'description' => "Finalized pending bill #" . $bill_data['bill_number'],
            'status'      => 'SUCCESS',
            'old_values'  => [
                'bill_status' => $bill_data['bill_status'] ?? '',
                'is_finalized' => $bill_data['is_finalized'] ?? 0
            ],
            'new_values'  => [
                'bill_status' => 'approved',
                'is_finalized' => 1,
                'finalized_by' => $user_id,
                'finalized_at' => date('Y-m-d H:i:s')
            ]
        ]);
        
        $mysqli->commit();
        
        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Bill finalized successfully! You can now create an invoice.";
        return true;
        
    } catch (Exception $e) {
        $mysqli->rollback();
        
        // AUDIT LOG: Log failed finalization
        audit_log($mysqli, [
            'user_id'     => $user_id,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'UPDATE',
            'module'      => 'Billing',
            'table_name'  => 'pending_bills',
            'entity_type' => 'pending_bill',
            'record_id'   => $pending_bill_id,
            'patient_id'  => $bill_data['patient_id'] ?? 0,
            'visit_id'    => $bill_data['visit_id'] ?? 0,
            'description' => "Failed to finalize pending bill #" . ($bill_data['bill_number'] ?? $pending_bill_id),
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

function handleFinalizeItemToInvoice($mysqli, $user_id) {
    $pending_bill_item_id = intval($_POST['pending_bill_item_id'] ?? 0);
    $invoice_id = intval($_POST['invoice_id'] ?? 0);
    
    if (!$pending_bill_item_id) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Item ID required";
        return false;
    }
    
    // Start transaction
    $mysqli->begin_transaction();
    
    try {
        // Get item details for audit log
        $get_sql = "SELECT pbi.*, bi.item_name, pb.pending_bill_id, pb.bill_number, pb.patient_id, pb.visit_id
                   FROM pending_bill_items pbi
                   JOIN billable_items bi ON pbi.billable_item_id = bi.billable_item_id
                   JOIN pending_bills pb ON pbi.pending_bill_id = pb.pending_bill_id
                   WHERE pbi.pending_bill_item_id = ?";
        $get_stmt = $mysqli->prepare($get_sql);
        $get_stmt->bind_param("i", $pending_bill_item_id);
        $get_stmt->execute();
        $get_result = $get_stmt->get_result();
        $item_data = $get_result->fetch_assoc();
        
        if (!$item_data) {
            throw new Exception("Item not found");
        }
        
        $pending_bill_id = $item_data['pending_bill_id'];
        $item_name = $item_data['item_name'] ?? '';
        
        // Check if item is already finalized
        if ($item_data['invoice_item_id']) {
            throw new Exception("Item is already finalized to an invoice");
        }
        
        // Check if price list is assigned
        if (!$item_data['price_list_item_id']) {
            throw new Exception("Cannot finalize item without a price list assigned");
        }
        
        // Get or create invoice
        if ($invoice_id > 0) {
            // Check if invoice exists and belongs to this pending bill
            $invoice_check_sql = "SELECT invoice_id FROM invoices WHERE invoice_id = ? AND pending_bill_id = ?";
            $invoice_check_stmt = $mysqli->prepare($invoice_check_sql);
            $invoice_check_stmt->bind_param("ii", $invoice_id, $pending_bill_id);
            $invoice_check_stmt->execute();
            $invoice_check_result = $invoice_check_stmt->get_result();
            $invoice_data = $invoice_check_result->fetch_assoc();
            
            if (!$invoice_data) {
                throw new Exception("Invalid invoice ID or invoice doesn't belong to this bill");
            }
        } else {
            // Create new invoice for the pending bill
            $invoice_number = generateInvoiceNumber($mysqli);
            
            // Get pending bill details
            $bill_sql = "SELECT pb.*, 
                                CONCAT(p.first_name, ' ', p.last_name) as patient_name, p.patient_mrn,
                                pl.price_list_name, pl.price_list_type
                         FROM pending_bills pb
                         JOIN patients p ON pb.patient_id = p.patient_id
                         LEFT JOIN price_lists pl ON pb.price_list_id = pl.price_list_id
                         WHERE pb.pending_bill_id = ?";
            $bill_stmt = $mysqli->prepare($bill_sql);
            $bill_stmt->bind_param("i", $pending_bill_id);
            $bill_stmt->execute();
            $bill_result = $bill_stmt->get_result();
            $pending_bill = $bill_result->fetch_assoc();
            
            // Create invoice
            $invoice_sql = "INSERT INTO invoices 
                           (invoice_number, pending_bill_id, visit_id, patient_id, patient_name, patient_mrn,
                            price_list_id, price_list_name, price_list_type,
                            subtotal_amount, discount_amount, tax_amount, total_amount,
                            amount_due, invoice_status, invoice_date, due_date, payment_terms,
                            created_by, finalized_at, finalized_by)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, 0, 0, 'issued', CURDATE(), 
                                   DATE_ADD(CURDATE(), INTERVAL 30 DAY), 'Net 30', ?, NOW(), ?)";
            
            $invoice_stmt = $mysqli->prepare($invoice_sql);
            $invoice_stmt->bind_param("siiisssisii", 
                $invoice_number, $pending_bill_id, $pending_bill['visit_id'], $pending_bill['patient_id'],
                $pending_bill['patient_name'], $pending_bill['patient_mrn'], $pending_bill['price_list_id'],
                $pending_bill['price_list_name'], $pending_bill['price_list_type'],
                $user_id, $user_id
            );
            
            if (!$invoice_stmt->execute()) {
                throw new Exception("Failed to create invoice: " . $mysqli->error);
            }
            
            $invoice_id = $mysqli->insert_id;
            
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
                    'invoice_status' => 'issued'
                ]
            ]);
        }
        
        // Create invoice item from pending bill item
        $item_sql = "INSERT INTO invoice_items 
                    (invoice_id, billable_item_id, item_code, item_name, item_description,
                     price_list_item_id, unit_price, item_quantity, discount_percentage, discount_amount,
                     tax_percentage, subtotal, tax_amount, total_amount, source_type, source_id)
                    SELECT 
                        ?,
                        pbi.billable_item_id,
                        bi.item_code,
                        bi.item_name,
                        bi.item_description,
                        pbi.price_list_item_id,
                        pbi.unit_price,
                        pbi.item_quantity,
                        pbi.discount_percentage,
                        pbi.discount_amount,
                        pbi.tax_percentage,
                        pbi.subtotal,
                        pbi.tax_amount,
                        pbi.total_amount,
                        'pending_bill',
                        pbi.pending_bill_item_id
                    FROM pending_bill_items pbi
                    JOIN billable_items bi ON pbi.billable_item_id = bi.billable_item_id
                    WHERE pbi.pending_bill_item_id = ?";
        
        $item_stmt = $mysqli->prepare($item_sql);
        $item_stmt->bind_param("ii", $invoice_id, $pending_bill_item_id);
        
        if (!$item_stmt->execute()) {
            throw new Exception("Failed to create invoice item: " . $item_stmt->error);
        }
        
        $invoice_item_id = $mysqli->insert_id;
        
        // Update pending bill item with invoice item reference
        $update_item_sql = "UPDATE pending_bill_items 
                           SET invoice_item_id = ?,
                               updated_at = NOW()
                           WHERE pending_bill_item_id = ?";
        
        $update_item_stmt = $mysqli->prepare($update_item_sql);
        $update_item_stmt->bind_param("ii", $invoice_item_id, $pending_bill_item_id);
        $update_item_stmt->execute();
        
        // Update invoice totals
        updateInvoiceTotal($mysqli, $invoice_id);
        
        // AUDIT LOG: Log item finalization
        audit_log($mysqli, [
            'user_id'     => $user_id,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'UPDATE',
            'module'      => 'Billing',
            'table_name'  => 'pending_bill_items',
            'entity_type' => 'bill_item',
            'record_id'   => $pending_bill_item_id,
            'patient_id'  => $item_data['patient_id'] ?? 0,
            'visit_id'    => $item_data['visit_id'] ?? 0,
            'description' => "Finalized item to invoice #" . $invoice_id . ": " . $item_name,
            'status'      => 'SUCCESS',
            'old_values'  => [
                'invoice_item_id' => null
            ],
            'new_values'  => [
                'invoice_item_id' => $invoice_item_id,
                'finalized_to_invoice_id' => $invoice_id
            ]
        ]);
        
        $mysqli->commit();
        
        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Item finalized to invoice successfully!";
        return true;
        
    } catch (Exception $e) {
        $mysqli->rollback();
        
        // AUDIT LOG: Log failed item finalization
        audit_log($mysqli, [
            'user_id'     => $user_id,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'UPDATE',
            'module'      => 'Billing',
            'table_name'  => 'pending_bill_items',
            'entity_type' => 'bill_item',
            'record_id'   => $pending_bill_item_id,
            'patient_id'  => 0,
            'visit_id'    => 0,
            'description' => "Failed to finalize item #" . $pending_bill_item_id . " to invoice",
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

function handleAssignPriceListToItem($mysqli, $user_id) {
    $pending_bill_item_id = intval($_POST['pending_bill_item_id'] ?? 0);
    $price_list_id = intval($_POST['price_list_id'] ?? 0);
    
    if (!$pending_bill_item_id || !$price_list_id) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Item ID and Price List ID required";
        return false;
    }
    
    // Start transaction
    $mysqli->begin_transaction();
    
    try {
        // Get item details for audit log
       $get_sql = "SELECT 
                pbi.*, 
                bi.item_name, bi.is_taxable, bi.tax_rate, 
                pb.pending_bill_id, pb.bill_number, pb.patient_id, pb.visit_id,
                pb.is_finalized
            FROM pending_bill_items pbi
            JOIN pending_bills pb ON pbi.pending_bill_id = pb.pending_bill_id
            JOIN billable_items bi ON pbi.billable_item_id = bi.billable_item_id
            WHERE pbi.pending_bill_item_id = ?";

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
        $item_name = $item_data['item_name'] ?? '';
        
        // Get price list item for this billable item and price list
        $price_list_item_sql = "SELECT pli.* 
                               FROM price_list_items pli
                               WHERE pli.billable_item_id = ? 
                               AND pli.price_list_id = ?
                               AND pli.is_active = 1
                               LIMIT 1";
        $price_list_item_stmt = $mysqli->prepare($price_list_item_sql);
        $price_list_item_stmt->bind_param("ii", $item_data['billable_item_id'], $price_list_id);
        $price_list_item_stmt->execute();
        $price_list_item_result = $price_list_item_stmt->get_result();
        $price_list_item = $price_list_item_result->fetch_assoc();
        
        if (!$price_list_item) {
            throw new Exception("Price list item not found for this billable item");
        }
        
        $new_price = $price_list_item['price'];
        $price_list_item_id = $price_list_item['price_list_item_id'];
        
        // Get price list name
        $price_list_name_sql = "SELECT price_list_name FROM price_lists WHERE price_list_id = ?";
        $price_list_name_stmt = $mysqli->prepare($price_list_name_sql);
        $price_list_name_stmt->bind_param("i", $price_list_id);
        $price_list_name_stmt->execute();
        $price_list_name_result = $price_list_name_stmt->get_result();
        $price_list_name_data = $price_list_name_result->fetch_assoc();
        $new_price_list_name = $price_list_name_data['price_list_name'] ?? '';
        
        // Calculate new amounts based on new price
        $quantity = $item_data['item_quantity'] ?? 1;
        $discount_percentage = $item_data['discount_percentage'] ?? 0;
        
        $subtotal = $quantity * $new_price;
        $discount_amount = $subtotal * ($discount_percentage / 100);
        $taxable_amount = $subtotal - $discount_amount;
        $tax_amount = $item_data['is_taxable'] ? ($taxable_amount * ($item_data['tax_rate'] / 100)) : 0;
        $total_amount = $taxable_amount + $tax_amount;
        
        // Update item with new price list
        $update_sql = "UPDATE pending_bill_items 
                      SET price_list_item_id = ?,
                          unit_price = ?,
                          discount_amount = ?,
                          subtotal = ?,
                          tax_amount = ?,
                          total_amount = ?,
                          updated_at = NOW()
                      WHERE pending_bill_item_id = ?";
        
        $update_stmt = $mysqli->prepare($update_sql);
        $update_stmt->bind_param("idddddi", $price_list_item_id, $new_price, $discount_amount, 
                                $subtotal, $tax_amount, $total_amount, $pending_bill_item_id);
        
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
            
            // AUDIT LOG: Log price list assignment
            audit_log($mysqli, [
                'user_id'     => $user_id,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'UPDATE',
                'module'      => 'Billing',
                'table_name'  => 'pending_bill_items',
                'entity_type' => 'bill_item',
                'record_id'   => $pending_bill_item_id,
                'patient_id'  => $item_data['patient_id'] ?? 0,
                'visit_id'    => $item_data['visit_id'] ?? 0,
                'description' => "Assigned price list to item in bill #" . $item_data['bill_number'] . ": " . $item_name . 
                                 (empty($new_price_list_name) ? '' : " (Price List: " . $new_price_list_name . ")"),
                'status'      => 'SUCCESS',
                'old_values'  => [
                    'price_list_item_id' => $item_data['price_list_item_id'] ?? 0,
                    'unit_price' => $item_data['unit_price'] ?? 0,
                    'subtotal' => $item_data['subtotal'] ?? 0,
                    'tax_amount' => $item_data['tax_amount'] ?? 0,
                    'total_amount' => $item_data['total_amount'] ?? 0
                ],
                'new_values'  => [
                    'price_list_item_id' => $price_list_item_id,
                    'unit_price' => $new_price,
                    'subtotal' => $subtotal,
                    'tax_amount' => $tax_amount,
                    'total_amount' => $total_amount
                ]
            ]);
            
            $mysqli->commit();
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Price list assigned successfully!";
            return true;
        } else {
            throw new Exception("Failed to assign price list");
        }
        
    } catch (Exception $e) {
        $mysqli->rollback();
        
        // AUDIT LOG: Log failed price list assignment
        audit_log($mysqli, [
            'user_id'     => $user_id,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'UPDATE',
            'module'      => 'Billing',
            'table_name'  => 'pending_bill_items',
            'entity_type' => 'bill_item',
            'record_id'   => $pending_bill_item_id,
            'patient_id'  => 0,
            'visit_id'    => 0,
            'description' => "Failed to assign price list to item #" . $pending_bill_item_id,
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

function handleAddItem($mysqli, $user_id, $pending_bill_id) {
    $billable_item_id = intval($_POST['billable_item_id'] ?? 0);
    $quantity = floatval($_POST['quantity'] ?? 1);
    $price_list_item_id = intval($_POST['price_list_item_id'] ?? 0);
    $unit_price = floatval($_POST['unit_price'] ?? 0);
    $discount_percentage = floatval($_POST['discount_percentage'] ?? 0);
    $price_list_id = intval($_POST['price_list_id'] ?? 0);
    
    if (!$billable_item_id || $quantity <= 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid item or quantity";
        return false;
    }
    
    // Start transaction
    $mysqli->begin_transaction();
    
    try {
        // Get pending bill details
        $bill_sql = "SELECT pb.*, CONCAT(p.first_name, ' ', p.last_name) as patient_name 
                     FROM pending_bills pb
                     JOIN patients p ON pb.patient_id = p.patient_id
                     WHERE pb.pending_bill_id = ?";
        $bill_stmt = $mysqli->prepare($bill_sql);
        $bill_stmt->bind_param("i", $pending_bill_id);
        $bill_stmt->execute();
        $bill_result = $bill_stmt->get_result();
        $pending_bill = $bill_result->fetch_assoc();
        
        if (!$pending_bill) {
            throw new Exception("Pending bill not found");
        }
        
        if ($pending_bill['is_finalized']) {
            throw new Exception("Cannot add items to finalized bill");
        }
        
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
            $price_sql = "SELECT pli.price, pl.price_list_name 
                         FROM price_list_items pli
                         JOIN price_lists pl ON pli.price_list_id = pl.price_list_id
                         WHERE pli.price_list_item_id = ?";
            $price_stmt = $mysqli->prepare($price_sql);
            $price_stmt->bind_param("i", $price_list_item_id);
            $price_stmt->execute();
            $price_result = $price_stmt->get_result();
            $price_data = $price_result->fetch_assoc();
            $actual_price = $price_data['price'] ?? $billable_item['unit_price'];
            $price_list_name = $price_data['price_list_name'] ?? '';
        } elseif ($price_list_id) {
            // Get price from price list
            $price_sql = "SELECT pli.price, pl.price_list_name 
                         FROM price_list_items pli
                         JOIN price_lists pl ON pli.price_list_id = pl.price_list_id
                         WHERE pli.billable_item_id = ?
                         AND pli.price_list_id = ?
                         AND pli.is_active = 1
                         LIMIT 1";
            $price_stmt = $mysqli->prepare($price_sql);
            $price_stmt->bind_param("ii", $billable_item_id, $price_list_id);
            $price_stmt->execute();
            $price_result = $price_stmt->get_result();
            $price_data = $price_result->fetch_assoc();
            
            if ($price_data) {
                $actual_price = $price_data['price'];
                $price_list_name = $price_data['price_list_name'] ?? '';
                $price_list_item_id = $price_data['price_list_item_id'] ?? 0;
            } else {
                $actual_price = $billable_item['unit_price'];
                $price_list_name = '';
            }
        } else {
            $actual_price = $billable_item['unit_price'];
            $price_list_name = '';
        }
        
        // Calculate amounts
        $subtotal = $quantity * $actual_price;
        $discount_amount = $subtotal * ($discount_percentage / 100);
        $taxable_amount = $subtotal - $discount_amount;
        $tax_amount = $billable_item['is_taxable'] ? ($taxable_amount * ($billable_item['tax_rate'] / 100)) : 0;
        $total_amount = $taxable_amount + $tax_amount;
        
        // Check if item already exists in bill (not cancelled)
        $check_item_sql = "SELECT pending_bill_item_id 
                          FROM pending_bill_items 
                          WHERE pending_bill_id = ? 
                          AND billable_item_id = ?
                          AND price_list_item_id = ?
                          AND is_cancelled = 0";
        $check_item_stmt = $mysqli->prepare($check_item_sql);
        $check_item_stmt->bind_param("iii", $pending_bill_id, $billable_item_id, $price_list_item_id);
        $check_item_stmt->execute();
        $check_item_result = $check_item_stmt->get_result();
        
        if ($check_item_result->num_rows > 0) {
            // Update quantity of existing item
            $row = $check_item_result->fetch_assoc();
            
            $update_sql = "UPDATE pending_bill_items 
                          SET item_quantity = item_quantity + ?,
                              unit_price = ?,
                              discount_percentage = ?,
                              discount_amount = discount_amount + ?,
                              subtotal = subtotal + ?,
                              tax_amount = tax_amount + ?,
                              total_amount = total_amount + ?,
                              updated_at = NOW()
                          WHERE pending_bill_item_id = ?";
            
            $update_stmt = $mysqli->prepare($update_sql);
            $update_stmt->bind_param("dddddddi", $quantity, $actual_price, $discount_percentage, 
                                    $discount_amount, $subtotal, $tax_amount, $total_amount, $row['pending_bill_item_id']);
            
            if (!$update_stmt->execute()) {
                throw new Exception("Failed to update item: " . $mysqli->error);
            }
            
        } else {
            // Insert new item
            $insert_sql = "INSERT INTO pending_bill_items 
                          (pending_bill_id, billable_item_id, price_list_item_id,
                           item_quantity, unit_price, discount_percentage, discount_amount,
                           tax_percentage, subtotal, tax_amount, total_amount,
                           source_type, source_id, notes, created_by, created_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'manual', NULL, '', ?, NOW())";
            
            $insert_stmt = $mysqli->prepare($insert_sql);
            $insert_stmt->bind_param("iiiidddddddi", 
                $pending_bill_id, $billable_item_id, $price_list_item_id,
                $quantity, $actual_price, $discount_percentage, $discount_amount,
                $billable_item['tax_rate'], $subtotal, $tax_amount, $total_amount,
                $user_id
            );
            
            if (!$insert_stmt->execute()) {
                throw new Exception("Failed to add item: " . $insert_stmt->error);
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
                'description' => "Added item to bill: " . $billable_item['item_name'] . 
                                 (empty($price_list_name) ? '' : " (Price List: " . $price_list_name . ")"),
                'status'      => 'SUCCESS',
                'old_values'  => null,
                'new_values'  => [
                    'billable_item_id' => $billable_item_id,
                    'item_name' => $billable_item['item_name'],
                    'price_list_item_id' => $price_list_item_id,
                    'item_quantity' => $quantity,
                    'unit_price' => $actual_price,
                    'discount_percentage' => $discount_percentage,
                    'subtotal' => $subtotal,
                    'tax_amount' => $tax_amount,
                    'total_amount' => $total_amount
                ]
            ]);
        }
        
        // Update bill totals
        updatePendingBillTotal($mysqli, $pending_bill_id);
        
        // Update invoice totals if invoice exists
        if ($pending_bill['invoice_id']) {
            updateInvoiceTotal($mysqli, $pending_bill['invoice_id']);
        }
        
        $mysqli->commit();
        
        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Item added successfully!";
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
            'visit_id'    => 0,
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

function handleRemoveItem($mysqli, $user_id) {
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
        $get_sql = "SELECT pbi.*, bi.item_name, pb.pending_bill_id, pb.patient_id, pb.visit_id, pb.bill_number
                   FROM pending_bill_items pbi
                   JOIN billable_items bi ON pbi.billable_item_id = bi.billable_item_id
                   JOIN pending_bills pb ON pbi.pending_bill_id = pb.pending_bill_id
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
                'patient_id'  => $item_data['patient_id'] ?? 0,
                'visit_id'    => $item_data['visit_id'] ?? 0,
                'description' => "Removed item from bill #" . $item_data['bill_number'] . ": " . $item_name,
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
                    'cancelled_by' => $user_id,
                    'cancelled_at' => date('Y-m-d H:i:s')
                ]
            ]);
            
            $mysqli->commit();
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Item removed successfully!";
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

function handleUpdateItem($mysqli, $user_id) {
    $pending_bill_item_id = intval($_POST['pending_bill_item_id'] ?? 0);
    $quantity = floatval($_POST['quantity'] ?? 1);
    $unit_price = floatval($_POST['unit_price'] ?? 0);
    $discount_percentage = floatval($_POST['discount_percentage'] ?? 0);
    
    if (!$pending_bill_item_id || $quantity <= 0 || $unit_price <= 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid item data";
        return false;
    }
    
    // Start transaction
    $mysqli->begin_transaction();
    
    try {
        // Get item details for audit log
        $get_sql = "SELECT pbi.*, bi.item_name, bi.is_taxable, bi.tax_rate, pb.pending_bill_id, pb.bill_number, pb.patient_id, pb.visit_id
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
        $item_name = $item_data['item_name'] ?? '';
        
        // Calculate new amounts
        $subtotal = $quantity * $unit_price;
        $discount_amount = $subtotal * ($discount_percentage / 100);
        $taxable_amount = $subtotal - $discount_amount;
        $tax_amount = $item_data['is_taxable'] ? ($taxable_amount * ($item_data['tax_rate'] / 100)) : 0;
        $total_amount = $taxable_amount + $tax_amount;
        
        // Update item
        $update_sql = "UPDATE pending_bill_items 
                      SET item_quantity = ?,
                          unit_price = ?,
                          discount_percentage = ?,
                          discount_amount = ?,
                          subtotal = ?,
                          tax_amount = ?,
                          total_amount = ?,
                          updated_at = NOW()
                      WHERE pending_bill_item_id = ?";
        
        $update_stmt = $mysqli->prepare($update_sql);
        $update_stmt->bind_param("dddddddi", $quantity, $unit_price, $discount_percentage, 
                                $discount_amount, $subtotal, $tax_amount, $total_amount, $pending_bill_item_id);
        
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
            
            // AUDIT LOG: Log item update
            audit_log($mysqli, [
                'user_id'     => $user_id,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'UPDATE',
                'module'      => 'Billing',
                'table_name'  => 'pending_bill_items',
                'entity_type' => 'bill_item',
                'record_id'   => $pending_bill_item_id,
                'patient_id'  => $item_data['patient_id'] ?? 0,
                'visit_id'    => $item_data['visit_id'] ?? 0,
                'description' => "Updated item in bill #" . $item_data['bill_number'] . ": " . $item_name,
                'status'      => 'SUCCESS',
                'old_values'  => [
                    'item_quantity' => $item_data['item_quantity'] ?? 0,
                    'unit_price' => $item_data['unit_price'] ?? 0,
                    'discount_percentage' => $item_data['discount_percentage'] ?? 0,
                    'discount_amount' => $item_data['discount_amount'] ?? 0,
                    'subtotal' => $item_data['subtotal'] ?? 0,
                    'tax_amount' => $item_data['tax_amount'] ?? 0,
                    'total_amount' => $item_data['total_amount'] ?? 0
                ],
                'new_values'  => [
                    'item_quantity' => $quantity,
                    'unit_price' => $unit_price,
                    'discount_percentage' => $discount_percentage,
                    'discount_amount' => $discount_amount,
                    'subtotal' => $subtotal,
                    'tax_amount' => $tax_amount,
                    'total_amount' => $total_amount
                ]
            ]);
            
            $mysqli->commit();
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Item updated successfully!";
            return true;
        } else {
            throw new Exception("Failed to update item");
        }
        
    } catch (Exception $e) {
        $mysqli->rollback();
        
        // AUDIT LOG: Log failed item update
        audit_log($mysqli, [
            'user_id'     => $user_id,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'UPDATE',
            'module'      => 'Billing',
            'table_name'  => 'pending_bill_items',
            'entity_type' => 'bill_item',
            'record_id'   => $pending_bill_item_id,
            'patient_id'  => 0,
            'visit_id'    => 0,
            'description' => "Failed to update item #" . $pending_bill_item_id,
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

function handleUpdateItemPriceList($mysqli, $user_id) {
    $pending_bill_item_id = intval($_POST['pending_bill_item_id'] ?? 0);
    $price_list_item_id = intval($_POST['price_list_item_id'] ?? 0);
    
    if (!$pending_bill_item_id) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Item ID required";
        return false;
    }
    
    // Start transaction
    $mysqli->begin_transaction();
    
    try {
        // Get item details for audit log
        $get_sql = "SELECT pbi.*, bi.item_name, bi.is_taxable, bi.tax_rate, pb.pending_bill_id, pb.bill_number, pb.patient_id, pb.visit_id,
                           pli.price as new_price, pl.price_list_name as new_price_list_name
                   FROM pending_bill_items pbi
                   JOIN pending_bills pb ON pbi.pending_bill_id = pb.pending_bill_id
                   JOIN billable_items bi ON pbi.billable_item_id = bi.billable_item_id
                   LEFT JOIN price_list_items pli ON pli.price_list_item_id = ?
                   LEFT JOIN price_lists pl ON pli.price_list_id = pl.price_list_id
                   WHERE pbi.pending_bill_item_id = ?";
        $get_stmt = $mysqli->prepare($get_sql);
        $get_stmt->bind_param("ii", $price_list_item_id, $pending_bill_item_id);
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
        $item_name = $item_data['item_name'] ?? '';
        $new_price = $item_data['new_price'] ?? $item_data['unit_price'];
        $new_price_list_name = $item_data['new_price_list_name'] ?? '';
        
        // Calculate new amounts based on new price
        $quantity = $item_data['item_quantity'] ?? 1;
        $discount_percentage = $item_data['discount_percentage'] ?? 0;
        
        $subtotal = $quantity * $new_price;
        $discount_amount = $subtotal * ($discount_percentage / 100);
        $taxable_amount = $subtotal - $discount_amount;
        $tax_amount = $item_data['is_taxable'] ? ($taxable_amount * ($item_data['tax_rate'] / 100)) : 0;
        $total_amount = $taxable_amount + $tax_amount;
        
        // Update item with new price list
        $update_sql = "UPDATE pending_bill_items 
                      SET price_list_item_id = ?,
                          unit_price = ?,
                          discount_amount = ?,
                          subtotal = ?,
                          tax_amount = ?,
                          total_amount = ?,
                          updated_at = NOW()
                      WHERE pending_bill_item_id = ?";
        
        $update_stmt = $mysqli->prepare($update_sql);
        $update_stmt->bind_param("idddddi", $price_list_item_id, $new_price, $discount_amount, 
                                $subtotal, $tax_amount, $total_amount, $pending_bill_item_id);
        
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
            
            // AUDIT LOG: Log price list update
            audit_log($mysqli, [
                'user_id'     => $user_id,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'UPDATE',
                'module'      => 'Billing',
                'table_name'  => 'pending_bill_items',
                'entity_type' => 'bill_item',
                'record_id'   => $pending_bill_item_id,
                'patient_id'  => $item_data['patient_id'] ?? 0,
                'visit_id'    => $item_data['visit_id'] ?? 0,
                'description' => "Updated price list for item in bill #" . $item_data['bill_number'] . ": " . $item_name . 
                                 (empty($new_price_list_name) ? '' : " (New Price List: " . $new_price_list_name . ")"),
                'status'      => 'SUCCESS',
                'old_values'  => [
                    'price_list_item_id' => $item_data['price_list_item_id'] ?? 0,
                    'unit_price' => $item_data['unit_price'] ?? 0,
                    'subtotal' => $item_data['subtotal'] ?? 0,
                    'tax_amount' => $item_data['tax_amount'] ?? 0,
                    'total_amount' => $item_data['total_amount'] ?? 0
                ],
                'new_values'  => [
                    'price_list_item_id' => $price_list_item_id,
                    'unit_price' => $new_price,
                    'subtotal' => $subtotal,
                    'tax_amount' => $tax_amount,
                    'total_amount' => $total_amount
                ]
            ]);
            
            $mysqli->commit();
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Price list updated successfully!";
            return true;
        } else {
            throw new Exception("Failed to update price list");
        }
        
    } catch (Exception $e) {
        $mysqli->rollback();
        
        // AUDIT LOG: Log failed price list update
        audit_log($mysqli, [
            'user_id'     => $user_id,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'UPDATE',
            'module'      => 'Billing',
            'table_name'  => 'pending_bill_items',
            'entity_type' => 'bill_item',
            'record_id'   => $pending_bill_item_id,
            'patient_id'  => 0,
            'visit_id'    => 0,
            'description' => "Failed to update price list for item #" . $pending_bill_item_id,
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

function handleCreateInvoice($mysqli, $user_id, $pending_bill_id, $visit_id) {
    // Start transaction
    $mysqli->begin_transaction();
    
    try {
        // Get pending bill details
        $bill_sql = "SELECT pb.*, 
                            CONCAT(p.first_name, ' ', p.last_name) as patient_name, p.patient_mrn,
                            pl.price_list_name, pl.price_list_type
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
        
        // Check if bill is finalized
        if (!$pending_bill['is_finalized']) {
            throw new Exception("Bill must be finalized before creating invoice");
        }
        
        // Check if invoice already exists
        if ($pending_bill['invoice_id']) {
            throw new Exception("Invoice already exists for this bill");
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
                throw new Exception("Failed to create invoice item: " . $item_stmt->error);
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
        
        // Redirect to invoice view
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
            'visit_id'    => $visit_id,
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

function searchBillableItems($mysqli, $search_term, $item_type = 'all') {
    $items = [];
    
    $sql = "SELECT bi.*, 
                   GROUP_CONCAT(DISTINCT pl.price_list_name SEPARATOR ', ') as price_lists,
                   GROUP_CONCAT(DISTINCT pli.price_list_item_id SEPARATOR ',') as price_list_item_ids,
                   GROUP_CONCAT(DISTINCT pli.price SEPARATOR ',') as price_list_prices
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

// Get price list items for each bill item
foreach ($bill_items as &$item) {
    if ($item['billable_item_id']) {
        $price_options_sql = "SELECT pli.price_list_item_id, pli.price, pl.price_list_name, pl.price_list_id
                             FROM price_list_items pli
                             JOIN price_lists pl ON pli.price_list_id = pl.price_list_id
                             WHERE pli.billable_item_id = ?
                             AND pl.is_active = 1
                             AND pli.is_active = 1
                             ORDER BY pl.price_list_name";
        $price_options_stmt = $mysqli->prepare($price_options_sql);
        $price_options_stmt->bind_param("i", $item['billable_item_id']);
        $price_options_stmt->execute();
        $price_options_result = $price_options_stmt->get_result();
        $item['price_options'] = [];
        while ($price_option = $price_options_result->fetch_assoc()) {
            $item['price_options'][] = $price_option;
        }
    }
}

// Calculate bill totals
$bill_totals = [
    'subtotal' => 0,
    'tax' => 0,
    'total' => 0
];

foreach ($bill_items as $item) {
    $bill_totals['subtotal'] += $item['subtotal'];
    $bill_totals['tax'] += $item['tax_amount'];
    $bill_totals['total'] += $item['total_amount'];
}

// Check if invoice exists for this bill
$invoice_exists = false;
$existing_invoice_id = 0;
if ($pending_bill['invoice_id']) {
    $invoice_exists = true;
    $existing_invoice_id = $pending_bill['invoice_id'];
}
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-edit mr-2"></i>
            Edit Pending Bill: <?php echo htmlspecialchars($pending_bill['bill_number']); ?>
        </h3>
        <div class="card-tools">
            <div class="btn-group">
                <a href="billing.php?visit_id=<?php echo $visit_id; ?>" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Billing
                </a>
                <?php if ($pending_bill['is_finalized'] && $invoice_exists): ?>
                    <a href="billing_invoice_view.php?invoice_id=<?php echo $existing_invoice_id; ?>" class="btn btn-success ml-2">
                        <i class="fas fa-file-invoice-dollar mr-2"></i>View Invoice
                    </a>
                <?php elseif ($pending_bill['is_finalized']): ?>
                    <button type="button" class="btn btn-warning ml-2" data-toggle="modal" data-target="#createInvoiceModal">
                        <i class="fas fa-file-invoice-dollar mr-2"></i>Create Invoice
                    </button>
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

        <!-- Header Actions -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="btn-toolbar justify-content-between">
                    <div class="btn-group">
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Status:</strong> 
                            <span class="badge <?php echo $pending_bill['is_finalized'] ? 'badge-success' : 'badge-warning'; ?> ml-2">
                                <?php echo $pending_bill['is_finalized'] ? 'FINALIZED' : 'DRAFT'; ?>
                            </span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Visit #:</strong> 
                            <span class="badge badge-dark ml-2"><?php echo htmlspecialchars($pending_bill['visit_number']); ?></span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Today:</strong> 
                            <span class="badge badge-success ml-2"><?php echo date('M j, Y'); ?></span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Today's Bills:</strong> 
                            <span class="badge badge-primary ml-2"><?php echo $today_bills_stats['total_bills'] ?? 0; ?></span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Today's Invoices:</strong> 
                            <span class="badge badge-warning ml-2"><?php echo $today_invoices_stats['total_invoices'] ?? 0; ?></span>
                        </span>
                    </div>
                    <div class="btn-group">
                        <?php if (!$pending_bill['is_finalized']): ?>
                            <button type="button" class="btn btn-success" data-toggle="modal" data-target="#finalizeBillModal">
                                <i class="fas fa-check mr-2"></i>Finalize Bill
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Main Content -->
            <div class="col-md-8">
                <!-- Patient Information Card -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-user-injured mr-2"></i>Patient Information</h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="table-responsive">
                                    <table class="table table-sm table-borderless">
                                        <tr>
                                            <th width="40%" class="text-muted">Patient Name:</th>
                                            <td><strong><?php echo htmlspecialchars($pending_bill['patient_first_name'] . ' ' . $pending_bill['patient_last_name']); ?></strong></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Medical Record No:</th>
                                            <td><strong class="text-primary"><?php echo htmlspecialchars($pending_bill['patient_mrn']); ?></strong></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="table-responsive">
                                    <table class="table table-sm table-borderless">
                                        <tr>
                                            <th class="text-muted">Visit #:</th>
                                            <td><?php echo htmlspecialchars($pending_bill['visit_number']); ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Visit Type:</th>
                                            <td><?php echo htmlspecialchars($pending_bill['visit_type']); ?></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Bill Information Card -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-info-circle mr-2"></i>Bill Information</h4>
                    </div>
                    <div class="card-body">
                        <form method="post" id="updateBillForm">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="action" value="update_bill">
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Bill Number</label>
                                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($pending_bill['bill_number']); ?>" readonly>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Status</label>
                                        <select name="bill_status" class="form-control" <?php echo $pending_bill['is_finalized'] ? 'disabled' : ''; ?>>
                                            <option value="draft" <?php echo $pending_bill['bill_status'] == 'draft' ? 'selected' : ''; ?>>Draft</option>
                                            <option value="pending" <?php echo $pending_bill['bill_status'] == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                            <option value="approved" <?php echo $pending_bill['bill_status'] == 'approved' ? 'selected' : ''; ?>>Approved</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Price List</label>
                                        <select name="price_list_id" class="form-control" <?php echo $pending_bill['is_finalized'] ? 'disabled' : ''; ?>>
                                            <option value="">Select Price List</option>
                                            <?php foreach ($price_lists as $price_list): ?>
                                                <option value="<?php echo $price_list['price_list_id']; ?>"
                                                    <?php echo $price_list['price_list_id'] == $pending_bill['price_list_id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($price_list['price_list_name'] . ' (' . $price_list['price_list_type'] . ')'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if ($pending_bill['price_list_name']): ?>
                                            <small class="text-muted">Current: <?php echo htmlspecialchars($pending_bill['price_list_name']); ?></small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Discount Amount (KSH)</label>
                                        <input type="number" name="discount_amount" class="form-control" 
                                               step="0.01" min="0" 
                                               value="<?php echo number_format($pending_bill['discount_amount'] ?? 0, 2); ?>"
                                               <?php echo $pending_bill['is_finalized'] ? 'disabled' : ''; ?>>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label>Notes</label>
                                <textarea name="notes" class="form-control" rows="2" <?php echo $pending_bill['is_finalized'] ? 'disabled' : ''; ?>><?php echo htmlspecialchars($pending_bill['notes'] ?? ''); ?></textarea>
                            </div>
                            
                            <?php if (!$pending_bill['is_finalized']): ?>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save mr-2"></i>Update Bill
                                </button>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>

                <!-- Add Items Card (Only if not finalized) -->
                <?php if (!$pending_bill['is_finalized']): ?>
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-plus-circle mr-2"></i>Add Items to Bill</h4>
                    </div>
                    <div class="card-body">
                        <!-- Search Form -->
                        <form method="post" class="mb-4">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="action" value="search_items">
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Search Items</label>
                                        <div class="input-group">
                                            <input type="text" name="search_term" class="form-control" 
                                                   placeholder="Search by name, code, or description..." 
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
                                            <option value="bed" <?php echo $search_item_type == 'bed' ? 'selected' : ''; ?>>Beds</option>
                                            <option value="inventory" <?php echo $search_item_type == 'inventory' ? 'selected' : ''; ?>>Inventory</option>
                                            <option value="lab" <?php echo $search_item_type == 'lab' ? 'selected' : ''; ?>>Lab Tests</option>
                                            <option value="imaging" <?php echo $search_item_type == 'imaging' ? 'selected' : ''; ?>>Imaging</option>
                                            <option value="procedure" <?php echo $search_item_type == 'procedure' ? 'selected' : ''; ?>>Procedures</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="form-group">
                                        <label>&nbsp;</label>
                                        <a href="billing_edit.php?pending_bill_id=<?php echo $pending_bill_id; ?>" class="btn btn-secondary btn-block">
                                            <i class="fas fa-times"></i> Clear
                                        </a>
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
                                            <th>Item Code</th>
                                            <th>Item Name</th>
                                            <th>Type</th>
                                            <th>Base Price</th>
                                            <th>Price Lists</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($search_results as $item): ?>
                                            <tr>
                                                <td><code><?php echo htmlspecialchars($item['item_code']); ?></code></td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($item['item_name']); ?></strong>
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
                                                    <?php if (!empty($item['price_lists'])): ?>
                                                        <small class="text-muted"><?php echo htmlspecialchars($item['price_lists']); ?></small>
                                                    <?php else: ?>
                                                        <span class="text-muted">No price lists</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <button type="button" class="btn btn-primary btn-sm" 
                                                            data-toggle="modal" data-target="#addItemModal"
                                                            data-item-id="<?php echo $item['billable_item_id']; ?>"
                                                            data-item-name="<?php echo htmlspecialchars($item['item_name']); ?>"
                                                            data-item-code="<?php echo htmlspecialchars($item['item_code']); ?>"
                                                            data-item-price="<?php echo $item['unit_price']; ?>"
                                                            data-price-options='<?php echo isset($item['price_options']) ? json_encode($item['price_options']) : '[]'; ?>'>
                                                        <i class="fas fa-plus mr-1"></i> Add
                                                    </button>
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
                                Enter search terms above to find billable items to add
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Bill Items List Card -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2 d-flex justify-content-between align-items-center">
                        <h4 class="card-title mb-0"><i class="fas fa-list mr-2"></i>Bill Items</h4>
                        <span class="badge badge-primary">
                            <?php echo count($bill_items); ?> item(s)
                        </span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($bill_items)): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle mr-2"></i>
                                No items have been added to this bill yet.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th width="5%">#</th>
                                            <th width="25%">Item</th>
                                            <th width="10%">Code</th>
                                            <th width="10%" class="text-right">Qty</th>
                                            <th width="12%" class="text-right">Unit Price</th>
                                            <th width="10%" class="text-right">Tax</th>
                                            <th width="13%" class="text-right">Total</th>
                                            <th width="15%" class="text-center">Status</th>
                                            <?php if (!$pending_bill['is_finalized']): ?>
                                                <th width="10%" class="text-center">Actions</th>
                                            <?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $counter = 1; ?>
                                        <?php foreach ($bill_items as $item): ?>
                                            <tr class="<?php echo $item['invoice_item_id'] ? 'table-success' : ($item['price_list_item_id'] ? 'table-info' : ''); ?>">
                                                <td><?php echo $counter++; ?></td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($item['item_name']); ?></strong><br>
                                                    <small class="text-muted"><?php echo htmlspecialchars($item['item_description'] ?: ''); ?></small>
                                                    <?php if ($item['price_list_name']): ?>
                                                        <br><small class="text-info"><i class="fas fa-tag mr-1"></i><?php echo htmlspecialchars($item['price_list_name']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td><code><?php echo htmlspecialchars($item['item_code']); ?></code></td>
                                                <td class="text-right"><?php echo number_format($item['item_quantity'], 3); ?></td>
                                                <td class="text-right"><?php echo number_format($item['unit_price'], 2); ?></td>
                                                <td class="text-right">
                                                    <?php echo number_format($item['tax_amount'], 2); ?><br>
                                                    <small class="text-muted">(<?php echo number_format($item['tax_rate'], 2); ?>%)</small>
                                                </td>
                                                <td class="text-right">
                                                    <strong><?php echo number_format($item['total_amount'], 2); ?></strong>
                                                    <?php if ($item['discount_amount'] > 0): ?>
                                                        <br><small class="text-danger">-<?php echo number_format($item['discount_amount'], 2); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($item['invoice_item_id']): ?>
                                                        <span class="badge badge-success">Finalized</span>
                                                        <br><small class="text-muted">To Invoice</small>
                                                    <?php elseif ($item['price_list_item_id']): ?>
                                                        <span class="badge badge-info">Price List Assigned</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-warning">Pending</span>
                                                    <?php endif; ?>
                                                </td>
                                                <?php if (!$pending_bill['is_finalized']): ?>
                                                    <td class="text-center">
                                                        <div class="btn-group btn-group-sm">
                                                            <?php if (!$item['invoice_item_id']): ?>
                                                                <button type="button" class="btn btn-primary btn-sm" 
                                                                        data-toggle="modal" data-target="#editItemModal"
                                                                        data-item-id="<?php echo $item['pending_bill_item_id']; ?>"
                                                                        data-item-name="<?php echo htmlspecialchars($item['item_name']); ?>"
                                                                        data-quantity="<?php echo $item['item_quantity']; ?>"
                                                                        data-unit-price="<?php echo $item['unit_price']; ?>"
                                                                        data-discount="<?php echo $item['discount_percentage']; ?>">
                                                                    <i class="fas fa-edit"></i>
                                                                </button>
                                                                <?php if (!empty($item['price_options'])): ?>
                                                                    <button type="button" class="btn btn-info btn-sm ml-1" 
                                                                            data-toggle="modal" data-target="#updatePriceListModal"
                                                                            data-item-id="<?php echo $item['pending_bill_item_id']; ?>"
                                                                            data-item-name="<?php echo htmlspecialchars($item['item_name']); ?>"
                                                                            data-current-price-list-id="<?php echo $item['price_list_item_id']; ?>"
                                                                            data-price-options='<?php echo json_encode($item['price_options']); ?>'>
                                                                        <i class="fas fa-tags"></i>
                                                                    </button>
                                                                <?php else: ?>
                                                                    <button type="button" class="btn btn-secondary btn-sm ml-1" 
                                                                            data-toggle="modal" data-target="#assignPriceListModal"
                                                                            data-item-id="<?php echo $item['pending_bill_item_id']; ?>"
                                                                            data-item-name="<?php echo htmlspecialchars($item['item_name']); ?>">
                                                                        <i class="fas fa-tag"></i>
                                                                    </button>
                                                                <?php endif; ?>
                                                                <button type="button" class="btn btn-success btn-sm ml-1" 
                                                                        data-toggle="modal" data-target="#finalizeItemModal"
                                                                        data-item-id="<?php echo $item['pending_bill_item_id']; ?>"
                                                                        data-item-name="<?php echo htmlspecialchars($item['item_name']); ?>"
                                                                        data-price-list-assigned="<?php echo $item['price_list_item_id'] ? '1' : '0'; ?>">
                                                                    <i class="fas fa-check"></i>
                                                                </button>
                                                            <?php endif; ?>
                                                            <form method="post" class="d-inline">
                                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                                <input type="hidden" name="action" value="remove_item">
                                                                <input type="hidden" name="pending_bill_item_id" value="<?php echo $item['pending_bill_item_id']; ?>">
                                                                <button type="submit" class="btn btn-danger btn-sm ml-1" onclick="return confirm('Remove this item from bill?')">
                                                                    <i class="fas fa-trash"></i>
                                                                </button>
                                                            </form>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="bg-light">
                                        <tr>
                                            <td colspan="<?php echo $pending_bill['is_finalized'] ? 7 : 8; ?>" class="text-right"><strong>Subtotal:</strong></td>
                                            <td class="text-right">
                                                <strong>KSH <?php echo number_format($bill_totals['subtotal'], 2); ?></strong>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td colspan="<?php echo $pending_bill['is_finalized'] ? 7 : 8; ?>" class="text-right"><strong>Tax:</strong></td>
                                            <td class="text-right">
                                                <strong>KSH <?php echo number_format($bill_totals['tax'], 2); ?></strong>
                                            </td>
                                        </tr>
                                        <?php if ($pending_bill['discount_amount'] > 0): ?>
                                        <tr>
                                            <td colspan="<?php echo $pending_bill['is_finalized'] ? 7 : 8; ?>" class="text-right text-danger"><strong>Discount:</strong></td>
                                            <td class="text-right text-danger">
                                                <strong>-KSH <?php echo number_format($pending_bill['discount_amount'], 2); ?></strong>
                                            </td>
                                        </tr>
                                        <?php endif; ?>
                                        <tr class="table-active">
                                            <td colspan="<?php echo $pending_bill['is_finalized'] ? 7 : 8; ?>" class="text-right"><strong>Total Amount:</strong></td>
                                            <td class="text-right">
                                                <h5 class="mb-0 text-success">KSH <?php echo number_format($pending_bill['total_amount'], 2); ?></h5>
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Sidebar Information -->
            <div class="col-md-4">
                <!-- Today's Billing Statistics Card -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-chart-bar mr-2"></i>Today's Billing Statistics</h4>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-6">
                                <div class="stat-box bg-primary-light p-2 rounded mb-2">
                                    <i class="fas fa-file-invoice fa-lg text-primary mb-1"></i>
                                    <h5 class="mb-0"><?php echo $today_bills_stats['total_bills'] ?? 0; ?></h5>
                                    <small class="text-muted">Total Bills</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="stat-box bg-info-light p-2 rounded mb-2">
                                    <i class="fas fa-edit fa-lg text-info mb-1"></i>
                                    <h5 class="mb-0"><?php echo $today_bills_stats['draft_bills'] ?? 0; ?></h5>
                                    <small class="text-muted">Draft</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="stat-box bg-warning-light p-2 rounded">
                                    <i class="fas fa-clock fa-lg text-warning mb-1"></i>
                                    <h5 class="mb-0"><?php echo $today_bills_stats['pending_bills'] ?? 0; ?></h5>
                                    <small class="text-muted">Pending</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="stat-box bg-success-light p-2 rounded">
                                    <i class="fas fa-check-circle fa-lg text-success mb-1"></i>
                                    <h5 class="mb-0"><?php echo $today_bills_stats['approved_bills'] ?? 0; ?></h5>
                                    <small class="text-muted">Approved</small>
                                </div>
                            </div>
                        </div>
                        <hr>
                        <div class="row text-center">
                            <div class="col-6">
                                <div class="stat-box bg-secondary-light p-2 rounded">
                                    <i class="fas fa-receipt fa-lg text-secondary mb-1"></i>
                                    <h5 class="mb-0"><?php echo $today_invoices_stats['total_invoices'] ?? 0; ?></h5>
                                    <small class="text-muted">Invoices</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="stat-box bg-success-light p-2 rounded">
                                    <i class="fas fa-check-circle fa-lg text-success mb-1"></i>
                                    <h5 class="mb-0"><?php echo $today_invoices_stats['paid_invoices'] ?? 0; ?></h5>
                                    <small class="text-muted">Paid</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Bill Summary Card -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-receipt mr-2"></i>Bill Summary</h4>
                    </div>
                    <div class="card-body">
                        <div class="text-center mb-3">
                            <div class="h4 text-primary"><?php echo htmlspecialchars($pending_bill['bill_number']); ?></div>
                            <small class="text-muted"><?php echo $pending_bill['is_finalized'] ? 'Finalized Bill' : 'Draft Bill'; ?></small>
                        </div>
                        <hr>
                        <div class="small">
                            <div class="d-flex justify-content-between mb-2">
                                <span>Subtotal:</span>
                                <span class="font-weight-bold">KSH <?php echo number_format($bill_totals['subtotal'], 2); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Tax:</span>
                                <span class="font-weight-bold">KSH <?php echo number_format($bill_totals['tax'], 2); ?></span>
                            </div>
                            <?php if ($pending_bill['discount_amount'] > 0): ?>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-danger">Discount:</span>
                                <span class="font-weight-bold text-danger">-KSH <?php echo number_format($pending_bill['discount_amount'], 2); ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="d-flex justify-content-between mb-3">
                                <span>Total Amount:</span>
                                <span class="h5 text-success">KSH <?php echo number_format($pending_bill['total_amount'], 2); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Status:</span>
                                <span class="badge badge-<?php 
                                    switch($pending_bill['bill_status']) {
                                        case 'draft': echo 'info'; break;
                                        case 'pending': echo 'warning'; break;
                                        case 'approved': echo 'success'; break;
                                        case 'cancelled': echo 'danger'; break;
                                        default: echo 'secondary';
                                    }
                                ?>">
                                    <?php echo htmlspecialchars(ucfirst($pending_bill['bill_status'])); ?>
                                </span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Items Count:</span>
                                <span class="font-weight-bold"><?php echo count($bill_items); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Created By:</span>
                                <span><?php echo htmlspecialchars($pending_bill['created_by_name'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span>Created Date:</span>
                                <span><?php echo date('M j, Y', strtotime($pending_bill['created_at'])); ?></span>
                            </div>
                            <?php if ($pending_bill['is_finalized']): ?>
                                <div class="d-flex justify-content-between">
                                    <span>Finalized By:</span>
                                    <span><?php echo htmlspecialchars($pending_bill['finalized_by_name'] ?? 'N/A'); ?></span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Finalized On:</span>
                                    <span><?php echo date('M j, Y H:i', strtotime($pending_bill['finalized_at'])); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Important Notes Card -->
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-lightbulb mr-2"></i>Important Notes</h4>
                    </div>
                    <div class="card-body">
                        <ul class="list-unstyled small mb-0">
                            <?php if (!$pending_bill['is_finalized']): ?>
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-success mr-2"></i>
                                    You can add, remove, and edit items
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-tag text-info mr-2"></i>
                                    Assign price lists to items for proper pricing
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-check text-success mr-2"></i>
                                    Finalize individual items to invoice as needed
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-exclamation-circle text-warning mr-2"></i>
                                    Finalize bill to prevent further changes
                                </li>
                            <?php else: ?>
                                <li class="mb-2">
                                    <i class="fas fa-lock text-success mr-2"></i>
                                    Bill is finalized and locked
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-file-invoice-dollar text-info mr-2"></i>
                                    You can now create an invoice
                                </li>
                            <?php endif; ?>
                            <li class="mb-2">
                                <i class="fas fa-check-circle text-success mr-2"></i>
                                All changes are logged for audit
                            </li>
                            <li class="mb-2">
                                <i class="fas fa-check-circle text-success mr-2"></i>
                                Price lists can be applied to items
                            </li>
                            <li>
                                <i class="fas fa-check-circle text-success mr-2"></i>
                                Discounts can be applied at bill level
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Item Modal -->
<?php if (!$pending_bill['is_finalized']): ?>
<div class="modal fade" id="addItemModal" tabindex="-1" role="dialog" aria-labelledby="addItemModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary">
                <h5 class="modal-title text-white" id="addItemModalLabel">
                    <i class="fas fa-plus-circle mr-2"></i>Add Item to Bill
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
                                <input type="number" name="unit_price" class="form-control" id="add_unit_price" 
                                       min="0" step="0.01" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Price List (Optional)</label>
                        <select class="form-control" id="add_price_list_select">
                            <option value="">-- Select Price List --</option>
                            <!-- Price options will be populated by JavaScript -->
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Discount Percentage</label>
                        <div class="input-group">
                            <input type="number" name="discount_percentage" class="form-control" value="0" min="0" max="100" step="0.01">
                            <div class="input-group-append">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">
                        <i class="fas fa-times mr-2"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-plus mr-2"></i>Add Item
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
                <h5 class="modal-title text-white" id="editItemModalLabel">
                    <i class="fas fa-edit mr-2"></i>Edit Item
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true" class="text-white">&times;</span>
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
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Quantity</label>
                                <input type="number" name="quantity" class="form-control" id="edit_quantity" 
                                       min="0.01" step="0.01" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Unit Price (KSH)</label>
                                <input type="number" name="unit_price" class="form-control" id="edit_unit_price" 
                                       min="0" step="0.01" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Discount Percentage</label>
                        <div class="input-group">
                            <input type="number" name="discount_percentage" class="form-control" id="edit_discount_percentage" 
                                   min="0" max="100" step="0.01">
                            <div class="input-group-append">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">
                        <i class="fas fa-times mr-2"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-save mr-2"></i>Update Item
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Update Price List Modal -->
<div class="modal fade" id="updatePriceListModal" tabindex="-1" role="dialog" aria-labelledby="updatePriceListModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-info">
                <h5 class="modal-title text-white" id="updatePriceListModalLabel">
                    <i class="fas fa-tags mr-2"></i>Update Price List for Item
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true" class="text-white">&times;</span>
                </button>
            </div>
            <form method="post" id="updatePriceListForm">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="update_item_price_list">
                <input type="hidden" name="pending_bill_item_id" id="update_price_list_item_id">
                
                <div class="modal-body">
                    <div class="form-group">
                        <label>Item</label>
                        <input type="text" class="form-control" id="update_price_list_item_name" readonly>
                    </div>
                    
                    <div class="form-group">
                        <label>Select Price List</label>
                        <select name="price_list_item_id" class="form-control" id="update_price_list_select" required>
                            <option value="">-- Select Price List --</option>
                            <!-- Price options will be populated by JavaScript -->
                        </select>
                        <small class="form-text text-muted">Selecting a price list will update the item's unit price.</small>
                    </div>
                    
                    <div class="alert alert-info mt-3">
                        <i class="fas fa-info-circle mr-2"></i>
                        <strong>Note:</strong> Changing the price list will update the item's unit price and recalculate totals.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">
                        <i class="fas fa-times mr-2"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-info">
                        <i class="fas fa-save mr-2"></i>Update Price List
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Assign Price List Modal -->
<div class="modal fade" id="assignPriceListModal" tabindex="-1" role="dialog" aria-labelledby="assignPriceListModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-info">
                <h5 class="modal-title text-white" id="assignPriceListModalLabel">
                    <i class="fas fa-tag mr-2"></i>Assign Price List to Item
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true" class="text-white">&times;</span>
                </button>
            </div>
            <form method="post" id="assignPriceListForm">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="assign_price_list_to_item">
                <input type="hidden" name="pending_bill_item_id" id="assign_price_list_item_id">
                
                <div class="modal-body">
                    <div class="form-group">
                        <label>Item</label>
                        <input type="text" class="form-control" id="assign_price_list_item_name" readonly>
                    </div>
                    
                    <div class="form-group">
                        <label>Select Price List</label>
                        <select name="price_list_id" class="form-control" id="assign_price_list_select" required>
                            <option value="">-- Select Price List --</option>
                            <?php foreach ($price_lists as $price_list): ?>
                                <option value="<?php echo $price_list['price_list_id']; ?>">
                                    <?php echo htmlspecialchars($price_list['price_list_name'] . ' (' . $price_list['price_list_type'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="form-text text-muted">Select a price list to assign to this item.</small>
                    </div>
                    
                    <div class="alert alert-info mt-3">
                        <i class="fas fa-info-circle mr-2"></i>
                        <strong>Note:</strong> The item's unit price will be updated based on the selected price list.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">
                        <i class="fas fa-times mr-2"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-info">
                        <i class="fas fa-save mr-2"></i>Assign Price List
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Finalize Item Modal -->
<div class="modal fade" id="finalizeItemModal" tabindex="-1" role="dialog" aria-labelledby="finalizeItemModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-success">
                <h5 class="modal-title text-white" id="finalizeItemModalLabel">
                    <i class="fas fa-check mr-2"></i>Finalize Item to Invoice
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true" class="text-white">&times;</span>
                </button>
            </div>
            <form method="post" id="finalizeItemForm">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="finalize_item_to_invoice">
                <input type="hidden" name="pending_bill_item_id" id="finalize_item_id">
                <input type="hidden" name="invoice_id" id="finalize_invoice_id" value="<?php echo $existing_invoice_id; ?>">
                
                <div class="modal-body">
                    <div class="form-group">
                        <label>Item</label>
                        <input type="text" class="form-control" id="finalize_item_name" readonly>
                    </div>
                    
                    <div class="form-group">
                        <label>Invoice</label>
                        <select name="invoice_id" class="form-control" id="finalize_invoice_select" required>
                            <?php if ($existing_invoice_id): ?>
                                <option value="<?php echo $existing_invoice_id; ?>">Use Existing Invoice</option>
                                <option value="0">Create New Invoice</option>
                            <?php else: ?>
                                <option value="0">Create New Invoice</option>
                            <?php endif; ?>
                        </select>
                        <small class="form-text text-muted">
                            <?php if ($existing_invoice_id): ?>
                                Use existing invoice or create a new one for this item.
                            <?php else: ?>
                                A new invoice will be created for this item.
                            <?php endif; ?>
                        </small>
                    </div>
                    
                    <div class="alert alert-warning" id="price_list_warning" style="display: none;">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        <strong>Warning:</strong> This item does not have a price list assigned. Please assign a price list before finalizing.
                    </div>
                    
                    <div class="alert alert-info mt-3">
                        <i class="fas fa-info-circle mr-2"></i>
                        <strong>Note:</strong> Finalizing an item moves it to an invoice and locks it from further edits.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">
                        <i class="fas fa-times mr-2"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-success" id="finalize_item_button">
                        <i class="fas fa-check mr-2"></i>Finalize Item
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Finalize Bill Modal -->
<div class="modal fade" id="finalizeBillModal" tabindex="-1" role="dialog" aria-labelledby="finalizeBillModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-success">
                <h5 class="modal-title text-white" id="finalizeBillModalLabel">
                    <i class="fas fa-check mr-2"></i>Finalize Bill
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true" class="text-white">&times;</span>
                </button>
            </div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="finalize_bill">
                
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        <strong>Warning:</strong> Once finalized, you cannot add, remove, or edit items in this bill.
                    </div>
                    
                    <p>Are you sure you want to finalize this bill?</p>
                    
                    <div class="alert alert-info">
                        <p class="mb-1"><strong>Bill Summary:</strong></p>
                        <div class="d-flex justify-content-between">
                            <span>Items:</span>
                            <span><?php echo count($bill_items); ?> items</span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span>Subtotal:</span>
                            <span>KSH <?php echo number_format($bill_totals['subtotal'], 2); ?></span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span>Total:</span>
                            <span class="font-weight-bold">KSH <?php echo number_format($pending_bill['total_amount'], 2); ?></span>
                        </div>
                    </div>
                    
                    <p>After finalizing, you can create an invoice from this bill.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">
                        <i class="fas fa-times mr-2"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check mr-2"></i>Finalize Bill
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Create Invoice Modal -->
<?php if ($pending_bill['is_finalized']): ?>
<div class="modal fade" id="createInvoiceModal" tabindex="-1" role="dialog" aria-labelledby="createInvoiceModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title text-white" id="createInvoiceModalLabel">
                    <i class="fas fa-file-invoice-dollar mr-2"></i>Create Invoice
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true" class="text-white">&times;</span>
                </button>
            </div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="create_invoice">
                
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle mr-2"></i>
                        Create an invoice from this finalized bill.
                    </div>
                    
                    <div class="form-group">
                        <label>Bill Information</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($pending_bill['bill_number']); ?>" readonly>
                        <small class="form-text text-muted">
                            Patient: <?php echo htmlspecialchars($pending_bill['patient_first_name'] . ' ' . $pending_bill['patient_last_name']); ?>
                        </small>
                    </div>
                    
                    <div class="alert alert-light">
                        <p class="mb-1"><strong>Invoice Summary:</strong></p>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="d-flex justify-content-between">
                                    <span>Items:</span>
                                    <span><?php echo count($bill_items); ?></span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Subtotal:</span>
                                    <span>KSH <?php echo number_format($pending_bill['subtotal_amount'] ?? 0, 2); ?></span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="d-flex justify-content-between">
                                    <span>Discount:</span>
                                    <span class="text-danger">-KSH <?php echo number_format($pending_bill['discount_amount'] ?? 0, 2); ?></span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Total:</span>
                                    <span class="font-weight-bold text-success">KSH <?php echo number_format($pending_bill['total_amount'] ?? 0, 2); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        Invoice will be created with status "issued" and due date 30 days from today.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">
                        <i class="fas fa-times mr-2"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-file-invoice-dollar mr-2"></i>Create Invoice
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
$(document).ready(function() {
    // Auto-close alerts after 5 seconds
    setTimeout(function() {
        $('.alert').fadeOut('slow');
    }, 5000);

    // Add Item Modal
    $('#addItemModal').on('show.bs.modal', function(event) {
        const button = $(event.relatedTarget);
        const itemId = button.data('item-id');
        const itemName = button.data('item-name');
        const itemCode = button.data('item-code');
        const itemPrice = button.data('item-price');
        const priceOptions = button.data('price-options') || [];
        
        const modal = $(this);
        modal.find('#add_billable_item_id').val(itemId);
        modal.find('#add_item_name').val(itemName);
        modal.find('#add_item_code').text('Code: ' + itemCode);
        modal.find('#add_unit_price').val(itemPrice);
        
        // Populate price list dropdown
        const priceListSelect = modal.find('#add_price_list_select');
        priceListSelect.html('<option value="">-- Select Price List --</option>');
        
        priceOptions.forEach(option => {
            priceListSelect.append(new Option(
                option.price_list_name + ' (KSH ' + parseFloat(option.unit_price).toFixed(2) + ')',
                option.price_list_item_id
            ));
        });
        
        // Update price when price list is selected
        priceListSelect.off('change').on('change', function() {
            const selectedOption = priceOptions.find(opt => opt.price_list_item_id == $(this).val());
            if (selectedOption) {
                modal.find('#add_unit_price').val(selectedOption.unit_price);
                modal.find('#add_price_list_item_id').val(selectedOption.price_list_item_id);
            } else {
                modal.find('#add_price_list_item_id').val('');
            }
        });
    });
    
    // Edit Item Modal
    $('#editItemModal').on('show.bs.modal', function(event) {
        const button = $(event.relatedTarget);
        const itemId = button.data('item-id');
        const itemName = button.data('item-name');
        const quantity = button.data('quantity');
        const unitPrice = button.data('unit-price');
        const discount = button.data('discount');
        
        const modal = $(this);
        modal.find('#edit_pending_bill_item_id').val(itemId);
        modal.find('#edit_item_name').val(itemName);
        modal.find('#edit_quantity').val(quantity);
        modal.find('#edit_unit_price').val(unitPrice);
        modal.find('#edit_discount_percentage').val(discount);
    });
    
    // Update Price List Modal
    $('#updatePriceListModal').on('show.bs.modal', function(event) {
        const button = $(event.relatedTarget);
        const itemId = button.data('item-id');
        const itemName = button.data('item-name');
        const currentPriceListId = button.data('current-price-list-id');
        const priceOptions = button.data('price-options') || [];
        
        const modal = $(this);
        modal.find('#update_price_list_item_id').val(itemId);
        modal.find('#update_price_list_item_name').val(itemName);
        
        // Populate price list dropdown
        const priceListSelect = modal.find('#update_price_list_select');
        priceListSelect.html('<option value="">-- Select Price List --</option>');
        
        priceOptions.forEach(option => {
            const optionElement = new Option(
                option.price_list_name + ' (KSH ' + parseFloat(option.unit_price).toFixed(2) + ')',
                option.price_list_item_id
            );
            if (option.price_list_item_id == currentPriceListId) {
                optionElement.selected = true;
            }
            priceListSelect.append(optionElement);
        });
    });
    
    // Assign Price List Modal
    $('#assignPriceListModal').on('show.bs.modal', function(event) {
        const button = $(event.relatedTarget);
        const itemId = button.data('item-id');
        const itemName = button.data('item-name');
        
        const modal = $(this);
        modal.find('#assign_price_list_item_id').val(itemId);
        modal.find('#assign_price_list_item_name').val(itemName);
    });
    
    // Finalize Item Modal
    $('#finalizeItemModal').on('show.bs.modal', function(event) {
        const button = $(event.relatedTarget);
        const itemId = button.data('item-id');
        const itemName = button.data('item-name');
        const priceListAssigned = button.data('price-list-assigned');
        
        const modal = $(this);
        modal.find('#finalize_item_id').val(itemId);
        modal.find('#finalize_item_name').val(itemName);
        
        // Show warning if price list not assigned
        const warningDiv = modal.find('#price_list_warning');
        const finalizeButton = modal.find('#finalize_item_button');
        
        if (priceListAssigned === '0') {
            warningDiv.show();
            finalizeButton.prop('disabled', true);
        } else {
            warningDiv.hide();
            finalizeButton.prop('disabled', false);
        }
        
        // Update invoice select based on existing invoice
        const existingInvoiceId = <?php echo $existing_invoice_id ?: '0'; ?>;
        const invoiceSelect = modal.find('#finalize_invoice_select');
        
        if (existingInvoiceId) {
            invoiceSelect.val(existingInvoiceId);
        }
    });
    
    // Form validation
    $('#addItemForm, #editItemForm').on('submit', function(e) {
        const quantity = $(this).find('input[name="quantity"]').val();
        const unitPrice = $(this).find('input[name="unit_price"]').val();
        
        if (quantity <= 0 || unitPrice <= 0) {
            alert('Quantity and unit price must be greater than 0');
            e.preventDefault();
            return false;
        }
    });
    
    // Update price list form validation
    $('#updatePriceListForm').on('submit', function(e) {
        const priceListId = $(this).find('select[name="price_list_item_id"]').val();
        
        if (!priceListId) {
            alert('Please select a price list');
            e.preventDefault();
            return false;
        }
    });
    
    // Assign price list form validation
    $('#assignPriceListForm').on('submit', function(e) {
        const priceListId = $(this).find('select[name="price_list_id"]').val();
        
        if (!priceListId) {
            alert('Please select a price list');
            e.preventDefault();
            return false;
        }
    });
    
    // Finalize item form validation
    $('#finalizeItemForm').on('submit', function(e) {
        const priceListAssigned = $(this).data('price-list-assigned');
        
        if (priceListAssigned === '0') {
            alert('Cannot finalize item without a price list assigned');
            e.preventDefault();
            return false;
        }
    });
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
.badge {
    font-size: 0.75em;
}
.table th {
    border-top: none;
    font-weight: 600;
    color: #6e707e;
    font-size: 0.85rem;
}
.table-success {
    background-color: rgba(25, 135, 84, 0.1);
}
.table-info {
    background-color: rgba(13, 202, 240, 0.1);
}
.bg-primary-light {
    background-color: rgba(13, 110, 253, 0.1);
}
.bg-info-light {
    background-color: rgba(23, 162, 184, 0.1);
}
.bg-warning-light {
    background-color: rgba(255, 193, 7, 0.1);
}
.bg-success-light {
    background-color: rgba(25, 135, 84, 0.1);
}
.bg-secondary-light {
    background-color: rgba(108, 117, 125, 0.1);
}
.card-title {
    font-size: 1.1rem;
    font-weight: 600;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
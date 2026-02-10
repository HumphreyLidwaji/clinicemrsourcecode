<?php
// invoice_visit.php - Create Invoice for Visit
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/audit_functions.php';

// Get visit_id from URL
$visit_id = intval($_GET['visit_id'] ?? 0);

if ($visit_id <= 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid visit ID";
    header("Location: visits.php");
    exit;
}

// Get visit data with patient information
$visit_sql = "SELECT v.*, 
                     p.first_name, p.last_name, p.patient_mrn,
                     p.date_of_birth, p.sex, p.phone_primary,
                     d.department_name,
                     u.user_name as doctor_name
              FROM visits v 
              JOIN patients p ON v.patient_id = p.patient_id
              LEFT JOIN departments d ON v.department_id = d.department_id
              LEFT JOIN users u ON v.attending_provider_id = u.user_id
              WHERE v.visit_id = ? AND p.archived_at IS NULL";
$visit_stmt = $mysqli->prepare($visit_sql);
$visit_stmt->bind_param("i", $visit_id);
$visit_stmt->execute();
$visit_result = $visit_stmt->get_result();
$visit = $visit_result->fetch_assoc();

if (!$visit) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Visit not found";
    header("Location: visits.php");
    exit;
}

// Check if visit can be invoiced (should be ACTIVE or CLOSED)
if ($visit['visit_status'] == 'CANCELLED') {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Cancelled visits cannot be invoiced";
    header("Location: visit_details.php?visit_id=" . $visit_id);
    exit;
}

// Function to generate bill number
function generateBillNumber($mysqli, $prefix = 'BILL') {
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

// Function to check if visit already has pending bill
function getVisitPendingBill($mysqli, $visit_id) {
    $sql = "SELECT pb.*, i.invoice_number, i.invoice_status
            FROM pending_bills pb
            LEFT JOIN invoices i ON pb.invoice_id = i.invoice_id
            WHERE pb.visit_id = ? 
            AND pb.bill_status IN ('draft', 'pending')
            ORDER BY pb.created_at DESC 
            LIMIT 1";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("i", $visit_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->fetch_assoc();
}

// Get price lists for dropdown
$price_lists = [];
$price_list_sql = "SELECT price_list_id, price_list_name, price_list_type, currency 
                   FROM price_lists 
                   WHERE is_active = 1 
                   ORDER BY price_list_name";
$price_list_result = $mysqli->query($price_list_sql);
while ($row = $price_list_result->fetch_assoc()) {
    $price_lists[] = $row;
}

// Get default price list (first active or create default if none)
$default_price_list_id = 0;
if (!empty($price_lists)) {
    $default_price_list_id = $price_lists[0]['price_list_id'];
}

// Get billable items for selection
$billable_items = [];
$billable_sql = "SELECT bi.*, bc.category_name 
                 FROM billable_items bi
                 LEFT JOIN billable_categories bc ON bi.category_id = bc.category_id
                 WHERE bi.is_active = 1 
                 ORDER BY bc.category_name, bi.item_name";
$billable_result = $mysqli->query($billable_sql);
while ($row = $billable_result->fetch_assoc()) {
    $billable_items[] = $row;
}

// Group billable items by category
$items_by_category = [];
foreach ($billable_items as $item) {
    $category = $item['category_name'] ?: 'Uncategorized';
    if (!isset($items_by_category[$category])) {
        $items_by_category[$category] = [];
    }
    $items_by_category[$category][] = $item;
}

// Check if visit already has pending bill
$existing_bill = getVisitPendingBill($mysqli, $visit_id);
$pending_bill_id = $existing_bill ? $existing_bill['pending_bill_id'] : 0;
$invoice_id = $existing_bill ? $existing_bill['invoice_id'] : 0;
$invoice_number = $existing_bill ? $existing_bill['invoice_number'] : null;
$invoice_status = $existing_bill ? $existing_bill['invoice_status'] : null;
$bill_created = false;

// Get today's billing stats
$today_bills_sql = "SELECT 
    COUNT(*) as total_bills,
    SUM(CASE WHEN bill_status = 'draft' THEN 1 ELSE 0 END) as draft_bills,
    SUM(CASE WHEN bill_status = 'pending' THEN 1 ELSE 0 END) as pending_bills,
    SUM(CASE WHEN bill_status = 'approved' THEN 1 ELSE 0 END) as approved_bills
    FROM pending_bills 
    WHERE DATE(created_at) = CURDATE()";
$today_bills_result = $mysqli->query($today_bills_sql);
$today_bills_stats = $today_bills_result->fetch_assoc();

// Get today's invoice stats
$today_invoices_sql = "SELECT 
    COUNT(*) as total_invoices,
    SUM(CASE WHEN invoice_status = 'issued' THEN 1 ELSE 0 END) as issued_invoices,
    SUM(CASE WHEN invoice_status = 'partially_paid' THEN 1 ELSE 0 END) as partially_paid_invoices,
    SUM(CASE WHEN invoice_status = 'paid' THEN 1 ELSE 0 END) as paid_invoices
    FROM invoices 
    WHERE DATE(created_at) = CURDATE()";
$today_invoices_result = $mysqli->query($today_invoices_sql);
$today_invoices_stats = $today_invoices_result->fetch_assoc();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    
    // Validate CSRF token
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        header("Location: invoice_visit.php?visit_id=" . $visit_id);
        exit;
    }

    // Determine action
    $action = sanitizeInput($_POST['action'] ?? '');
    
    if ($action === 'create_bill') {
        // Create new draft bill and invoice
        
        // Start transaction
        $mysqli->begin_transaction();
        
        try {
            // Generate bill number
            $bill_number = generateBillNumber($mysqli);
            $pending_bill_number = generateBillNumber($mysqli, 'PBL');
            
            // Generate invoice number
            $invoice_number = generateInvoiceNumber($mysqli);
            
            // Get price list
            $price_list_id = intval($_POST['price_list_id'] ?? $default_price_list_id);
            
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
            
            $notes = sanitizeInput($_POST['notes'] ?? "Draft bill created for visit " . $visit['visit_number']);
            $bill_stmt->bind_param(
                "ssiiisi",
                $bill_number,
                $pending_bill_number,
                $visit_id,
                $visit['patient_id'],
                $price_list_id,
                $notes,
                $session_user_id
            );
            
            if (!$bill_stmt->execute()) {
                throw new Exception("Error creating bill: " . $bill_stmt->error);
            }
            
            $pending_bill_id = $bill_stmt->insert_id;
            
            // Create empty invoice
            $patient_name = $visit['first_name'] . ' ' . $visit['last_name'];
            $invoice_sql = "INSERT INTO invoices (
                invoice_number,
                pending_bill_id,
                visit_id,
                patient_id,
                patient_name,
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
            ) VALUES (?, ?, ?, ?, ?, ?, 0, 0, 0, 0, 0, 0, 'issued', CURDATE(), ?, ?, NOW(), ?)";
            
            $invoice_stmt = $mysqli->prepare($invoice_sql);
            if (!$invoice_stmt) {
                throw new Exception("Prepare failed for invoice: " . $mysqli->error);
            }
            
            $invoice_notes = "Invoice created for visit " . $visit['visit_number'];
            $invoice_stmt->bind_param(
                "siiisisii",
                $invoice_number,
                $pending_bill_id,
                $visit_id,
                $visit['patient_id'],
                $patient_name,
                $price_list_id,
                $invoice_notes,
                $session_user_id,
                $session_user_id
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
                'user_id'     => $session_user_id,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'CREATE',
                'module'      => 'Billing',
                'table_name'  => 'pending_bills',
                'entity_type' => 'pending_bill',
                'record_id'   => $pending_bill_id,
                'patient_id'  => $visit['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Created draft bill: " . $bill_number . " for visit: " . $visit['visit_number'],
                'status'      => 'SUCCESS',
                'old_values'  => null,
                'new_values'  => [
                    'bill_number' => $bill_number,
                    'pending_bill_number' => $pending_bill_number,
                    'visit_id' => $visit_id,
                    'patient_id' => $visit['patient_id'],
                    'price_list_id' => $price_list_id,
                    'bill_status' => 'draft'
                ]
            ]);
            
            // AUDIT LOG: Log invoice creation
            audit_log($mysqli, [
                'user_id'     => $session_user_id,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'CREATE',
                'module'      => 'Billing',
                'table_name'  => 'invoices',
                'entity_type' => 'invoice',
                'record_id'   => $invoice_id,
                'patient_id'  => $visit['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Created invoice: " . $invoice_number . " for bill: " . $bill_number,
                'status'      => 'SUCCESS',
                'old_values'  => null,
                'new_values'  => [
                    'invoice_number' => $invoice_number,
                    'pending_bill_id' => $pending_bill_id,
                    'visit_id' => $visit_id,
                    'patient_id' => $visit['patient_id'],
                    'invoice_status' => 'issued'
                ]
            ]);
            
            $bill_created = true;
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Draft bill and invoice created successfully!<br>Bill #: " . $bill_number . "<br>Invoice #: " . $invoice_number;
            
        } catch (Exception $e) {
            $mysqli->rollback();
            
            // AUDIT LOG: Log failed bill creation
            audit_log($mysqli, [
                'user_id'     => $session_user_id,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'CREATE',
                'module'      => 'Billing',
                'table_name'  => 'pending_bills',
                'entity_type' => 'pending_bill',
                'record_id'   => 0,
                'patient_id'  => $visit['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Failed to create draft bill for visit: " . $visit['visit_number'],
                'status'      => 'FAILED',
                'old_values'  => null,
                'new_values'  => null,
                'error'       => $e->getMessage()
            ]);
            
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error creating bill: " . $e->getMessage();
        }
        
    } elseif ($action === 'add_item' && $pending_bill_id > 0) {
        // Add item to pending bill
        
        $billable_item_id = intval($_POST['billable_item_id'] ?? 0);
        $item_quantity = floatval($_POST['item_quantity'] ?? 1);
        
        if ($billable_item_id <= 0) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Please select a billable item";
            header("Location: invoice_visit.php?visit_id=" . $visit_id);
            exit;
        }
        
        if ($item_quantity <= 0) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Quantity must be greater than 0";
            header("Location: invoice_visit.php?visit_id=" . $visit_id);
            exit;
        }
        
        // Get billable item details
        $item_sql = "SELECT * FROM billable_items WHERE billable_item_id = ?";
        $item_stmt = $mysqli->prepare($item_sql);
        $item_stmt->bind_param("i", $billable_item_id);
        $item_stmt->execute();
        $item_result = $item_stmt->get_result();
        $billable_item = $item_result->fetch_assoc();
        
        if (!$billable_item) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Billable item not found";
            header("Location: invoice_visit.php?visit_id=" . $visit_id);
            exit;
        }
        
        // Start transaction
        $mysqli->begin_transaction();
        
        try {
            // Calculate amounts
            $unit_price = $billable_item['unit_price'];
            $subtotal = $unit_price * $item_quantity;
            $tax_amount = $subtotal * ($billable_item['tax_rate'] / 100);
            $total_amount = $subtotal + $tax_amount;
            
            // Add item to pending_bill_items
            $item_insert_sql = "INSERT INTO pending_bill_items (
                pending_bill_id,
                billable_item_id,
                item_quantity,
                unit_price,
                discount_percentage,
                discount_amount,
                tax_percentage,
                subtotal,
                tax_amount,
                total_amount,
                source_type,
                source_id,
                created_by
            ) VALUES (?, ?, ?, ?, 0, 0, ?, ?, ?, ?, 'visit', ?, ?)";
            
            $item_insert_stmt = $mysqli->prepare($item_insert_sql);
            $item_insert_stmt->bind_param(
                "iiddddddii",
                $pending_bill_id,
                $billable_item_id,
                $item_quantity,
                $unit_price,
                $billable_item['tax_rate'],
                $subtotal,
                $tax_amount,
                $total_amount,
                $visit_id,
                $session_user_id
            );
            
            if (!$item_insert_stmt->execute()) {
                throw new Exception("Error adding item to bill: " . $item_insert_stmt->error);
            }
            
            // Update bill totals
            $update_totals_sql = "UPDATE pending_bills 
                                 SET subtotal_amount = subtotal_amount + ?,
                                     tax_amount = tax_amount + ?,
                                     total_amount = total_amount + ?,
                                     updated_at = NOW()
                                 WHERE pending_bill_id = ?";
            
            $update_totals_stmt = $mysqli->prepare($update_totals_sql);
            $update_totals_stmt->bind_param(
                "dddi",
                $subtotal,
                $tax_amount,
                $total_amount,
                $pending_bill_id
            );
            
            if (!$update_totals_stmt->execute()) {
                throw new Exception("Error updating bill totals: " . $update_totals_stmt->error);
            }
            
            // Update invoice totals
            if ($invoice_id > 0) {
                $update_invoice_sql = "UPDATE invoices 
                                      SET subtotal_amount = subtotal_amount + ?,
                                          tax_amount = tax_amount + ?,
                                          total_amount = total_amount + ?,
                                          amount_due = amount_due + ?,
                                          updated_at = NOW()
                                      WHERE invoice_id = ?";
                
                $update_invoice_stmt = $mysqli->prepare($update_invoice_sql);
                $update_invoice_stmt->bind_param(
                    "ddddi",
                    $subtotal,
                    $tax_amount,
                    $total_amount,
                    $total_amount,
                    $invoice_id
                );
                
                if (!$update_invoice_stmt->execute()) {
                    throw new Exception("Error updating invoice totals: " . $update_invoice_stmt->error);
                }
            }
            
            // Commit transaction
            $mysqli->commit();
            
            // AUDIT LOG: Log item addition
            audit_log($mysqli, [
                'user_id'     => $session_user_id,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'ADD',
                'module'      => 'Billing',
                'table_name'  => 'pending_bill_items',
                'entity_type' => 'bill_item',
                'record_id'   => $pending_bill_id,
                'patient_id'  => $visit['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Added item to bill: " . $billable_item['item_name'] . " (Qty: " . $item_quantity . ")",
                'status'      => 'SUCCESS',
                'old_values'  => null,
                'new_values'  => [
                    'billable_item_id' => $billable_item_id,
                    'item_name' => $billable_item['item_name'],
                    'item_quantity' => $item_quantity,
                    'unit_price' => $unit_price,
                    'subtotal' => $subtotal
                ]
            ]);
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Item added to bill successfully!<br>" . 
                                       $billable_item['item_name'] . " x " . $item_quantity . " = " . 
                                       number_format($total_amount, 2);
            
        } catch (Exception $e) {
            $mysqli->rollback();
            
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error adding item: " . $e->getMessage();
        }
    }
    
    // Reload the page to show updated information
    header("Location: invoice_visit.php?visit_id=" . $visit_id);
    exit;
}

// Get bill details if bill exists (with invoice info)
if ($pending_bill_id > 0) {
    // Get bill details with invoice info
    $bill_details_sql = "SELECT pb.*, i.invoice_number, i.invoice_status
                        FROM pending_bills pb
                        LEFT JOIN invoices i ON pb.invoice_id = i.invoice_id
                        WHERE pb.pending_bill_id = ?";
    $bill_details_stmt = $mysqli->prepare($bill_details_sql);
    $bill_details_stmt->bind_param("i", $pending_bill_id);
    $bill_details_stmt->execute();
    $bill_details_result = $bill_details_stmt->get_result();
    $existing_bill = $bill_details_result->fetch_assoc();
    
    // Update invoice info from the query result
    if ($existing_bill) {
        $invoice_number = $existing_bill['invoice_number'];
        $invoice_status = $existing_bill['invoice_status'];
    }
}

// Get bill items if bill exists
$bill_items = [];
$bill_totals = [
    'subtotal' => 0,
    'tax' => 0,
    'total' => 0
];

if ($pending_bill_id > 0) {
    // Get bill items
    $items_sql = "SELECT pbi.*, bi.item_name, bi.item_code, bi.item_description
                  FROM pending_bill_items pbi
                  JOIN billable_items bi ON pbi.billable_item_id = bi.billable_item_id
                  WHERE pbi.pending_bill_id = ? AND pbi.is_cancelled = 0
                  ORDER BY pbi.created_at";
    $items_stmt = $mysqli->prepare($items_sql);
    $items_stmt->bind_param("i", $pending_bill_id);
    $items_stmt->execute();
    $items_result = $items_stmt->get_result();
    
    while ($row = $items_result->fetch_assoc()) {
        $bill_items[] = $row;
        $bill_totals['subtotal'] += $row['subtotal'];
        $bill_totals['tax'] += $row['tax_amount'];
        $bill_totals['total'] += $row['total_amount'];
    }
}

// Calculate patient age
$age = '';
if (!empty($visit['date_of_birth'])) {
    $birthDate = new DateTime($visit['date_of_birth']);
    $today = new DateTime();
    $age = $today->diff($birthDate)->y . ' years';
}

$patient_name = $visit['first_name'] . ' ' . $visit['last_name'];
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-file-invoice-dollar mr-2"></i>Create Invoice for Visit: <?php echo htmlspecialchars($visit['visit_number']); ?>
        </h3>
        <div class="card-tools">
            <div class="btn-group">
                <a href="visit_details.php?visit_id=<?php echo $visit_id; ?>" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Visit Details
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

        <!-- Invoice Header Actions -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="btn-toolbar justify-content-between">
                    <div class="btn-group">
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Status:</strong> 
                            <?php if ($existing_bill): ?>
                                <span class="badge badge-info ml-2"><?php echo htmlspecialchars(ucfirst($existing_bill['bill_status'])); ?></span>
                            <?php else: ?>
                                <span class="badge badge-secondary ml-2">No Draft Bill</span>
                            <?php endif; ?>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Visit #:</strong> 
                            <span class="badge badge-dark ml-2"><?php echo htmlspecialchars($visit['visit_number']); ?></span>
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
                                            <td><strong><?php echo htmlspecialchars($patient_name); ?></strong></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Medical Record No:</th>
                                            <td><strong class="text-primary"><?php echo htmlspecialchars($visit['patient_mrn']); ?></strong></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Date of Birth:</th>
                                            <td><?php echo !empty($visit['date_of_birth']) ? date('M j, Y', strtotime($visit['date_of_birth'])) : 'Not specified'; ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Age:</th>
                                            <td><?php echo $age ?: 'N/A'; ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Gender:</th>
                                            <td><?php echo htmlspecialchars($visit['sex'] ?: 'Not specified'); ?></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="table-responsive">
                                    <table class="table table-sm table-borderless">
                                        <tr>
                                            <th class="text-muted">Primary Phone:</th>
                                            <td><?php echo htmlspecialchars($visit['phone_primary'] ?: 'Not specified'); ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Visit Type:</th>
                                            <td><?php echo htmlspecialchars($visit['visit_type']); ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Department:</th>
                                            <td><?php echo htmlspecialchars($visit['department_name'] ?: 'Not specified'); ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Attending Provider:</th>
                                            <td><?php echo htmlspecialchars($visit['doctor_name'] ?: 'Not assigned'); ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Visit Date:</th>
                                            <td><?php echo date('M j, Y g:i A', strtotime($visit['visit_datetime'])); ?></td>
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
                        <?php if (!$existing_bill): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle mr-2"></i>
                                No draft bill exists for this visit. Create a draft bill to start adding billable items.
                            </div>
                            
                            <form method="post" class="mb-4">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <input type="hidden" name="action" value="create_bill">
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="required">Price List</label>
                                            <select class="form-control select2" name="price_list_id" id="price_list_id" required>
                                                <option value="">- Select Price List -</option>
                                                <?php foreach ($price_lists as $price_list): ?>
                                                    <option value="<?php echo $price_list['price_list_id']; ?>" 
                                                        <?php echo $price_list['price_list_id'] == $default_price_list_id ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($price_list['price_list_name']); ?> 
                                                        (<?php echo htmlspecialchars($price_list['price_list_type']); ?>)
                                                        <?php if (!empty($price_list['currency'])): ?>
                                                            - <?php echo htmlspecialchars($price_list['currency']); ?>
                                                        <?php endif; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label>Notes</label>
                                            <textarea class="form-control" name="notes" rows="2" 
                                                      placeholder="Optional notes about this bill"></textarea>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="text-center">
                                    <button type="submit" class="btn btn-primary btn-lg">
                                        <i class="fas fa-plus-circle mr-2"></i>Create Draft Bill & Invoice
                                    </button>
                                </div>
                            </form>
                        <?php else: ?>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="table-responsive">
                                        <table class="table table-sm table-borderless">
                                            <tr>
                                                <th width="40%" class="text-muted">Bill Number:</th>
                                                <td><strong class="text-primary"><?php echo htmlspecialchars($existing_bill['bill_number']); ?></strong></td>
                                            </tr>
                                            <tr>
                                                <th class="text-muted">Bill Status:</th>
                                                <td>
                                                    <span class="badge badge-<?php 
                                                        switch($existing_bill['bill_status']) {
                                                            case 'draft': echo 'info'; break;
                                                            case 'pending': echo 'warning'; break;
                                                            case 'approved': echo 'success'; break;
                                                            case 'cancelled': echo 'danger'; break;
                                                            default: echo 'secondary';
                                                        }
                                                    ?>">
                                                        <?php echo htmlspecialchars(ucfirst($existing_bill['bill_status'])); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th class="text-muted">Price List:</th>
                                                <td>
                                                    <?php 
                                                    $price_list_name = '';
                                                    foreach ($price_lists as $pl) {
                                                        if ($pl['price_list_id'] == $existing_bill['price_list_id']) {
                                                            $price_list_name = $pl['price_list_name'];
                                                            break;
                                                        }
                                                    }
                                                    echo htmlspecialchars($price_list_name ?: 'N/A');
                                                    ?>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th class="text-muted">Created Date:</th>
                                                <td><?php echo date('M j, Y g:i A', strtotime($existing_bill['created_at'])); ?></td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="table-responsive">
                                        <table class="table table-sm table-borderless">
                                            <tr>
                                                <th class="text-muted">Invoice Number:</th>
                                                <td>
                                                    <?php if ($invoice_number): ?>
                                                        <strong class="text-success"><?php echo htmlspecialchars($invoice_number); ?></strong>
                                                    <?php else: ?>
                                                        <span class="text-muted">Not generated</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th class="text-muted">Invoice Status:</th>
                                                <td>
                                                    <?php if ($invoice_status): ?>
                                                        <span class="badge badge-<?php 
                                                            switch($invoice_status) {
                                                                case 'issued': echo 'info'; break;
                                                                case 'partially_paid': echo 'warning'; break;
                                                                case 'paid': echo 'success'; break;
                                                                case 'cancelled': echo 'danger'; break;
                                                                default: echo 'secondary';
                                                            }
                                                        ?>">
                                                            <?php echo htmlspecialchars(str_replace('_', ' ', ucfirst($invoice_status))); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-muted">N/A</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php if ($existing_bill['is_finalized']): ?>
                                            <tr>
                                                <th class="text-muted">Finalized Date:</th>
                                                <td><?php echo date('M j, Y g:i A', strtotime($existing_bill['finalized_at'])); ?></td>
                                            </tr>
                                            <?php endif; ?>
                                            <tr>
                                                <th class="text-muted">Last Updated:</th>
                                                <td><?php echo date('M j, Y g:i A', strtotime($existing_bill['updated_at'])); ?></td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Add Billable Items Card -->
                <?php if ($existing_bill && $existing_bill['bill_status'] == 'draft'): ?>
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-plus-circle mr-2"></i>Add Billable Items</h4>
                    </div>
                    <div class="card-body">
                        <form method="post" id="addItemForm">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="action" value="add_item">
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="required">Select Item</label>
                                        <select class="form-control select2" name="billable_item_id" id="billable_item_id" required>
                                            <option value="">- Select Billable Item -</option>
                                            <?php foreach ($items_by_category as $category => $items): ?>
                                                <optgroup label="<?php echo htmlspecialchars($category); ?>">
                                                    <?php foreach ($items as $item): ?>
                                                        <option value="<?php echo $item['billable_item_id']; ?>"
                                                                data-price="<?php echo $item['unit_price']; ?>"
                                                                data-tax="<?php echo $item['tax_rate']; ?>">
                                                            <?php echo htmlspecialchars($item['item_name']); ?>
                                                            - <?php echo number_format($item['unit_price'], 2); ?>
                                                            <?php if ($item['tax_rate'] > 0): ?>
                                                                (Tax: <?php echo $item['tax_rate']; ?>%)
                                                            <?php endif; ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </optgroup>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label class="required">Quantity</label>
                                        <input type="number" class="form-control" name="item_quantity" id="item_quantity" 
                                               value="1" min="0.001" step="0.001" required>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label>Unit Price</label>
                                        <input type="text" class="form-control" id="unit_price_display" readonly>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-12">
                                    <div id="item_preview" class="alert alert-secondary" style="display: none;">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <strong id="preview_item_name"></strong><br>
                                                <small id="preview_item_details"></small>
                                            </div>
                                            <div class="text-right">
                                                <span id="preview_total" class="h5"></span><br>
                                                <small id="preview_tax"></small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="text-center">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="fas fa-plus mr-2"></i>Add Item to Bill
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Bill Items List Card -->
                <?php if ($existing_bill): ?>
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
                                No items have been added to this bill yet. Use the form above to add billable items.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th width="5%">#</th>
                                            <th width="30%">Item</th>
                                            <th width="10%">Code</th>
                                            <th width="10%" class="text-right">Qty</th>
                                            <th width="15%" class="text-right">Unit Price</th>
                                            <th width="15%" class="text-right">Tax</th>
                                            <th width="15%" class="text-right">Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $counter = 1; ?>
                                        <?php foreach ($bill_items as $item): ?>
                                            <tr>
                                                <td><?php echo $counter++; ?></td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($item['item_name']); ?></strong><br>
                                                    <small class="text-muted"><?php echo htmlspecialchars($item['item_description'] ?: ''); ?></small>
                                                </td>
                                                <td><?php echo htmlspecialchars($item['item_code']); ?></td>
                                                <td class="text-right"><?php echo number_format($item['item_quantity'], 3); ?></td>
                                                <td class="text-right"><?php echo number_format($item['unit_price'], 2); ?></td>
                                                <td class="text-right">
                                                    <?php echo number_format($item['tax_amount'], 2); ?><br>
                                                    <small class="text-muted">(<?php echo number_format($item['tax_percentage'], 2); ?>%)</small>
                                                </td>
                                                <td class="text-right">
                                                    <strong><?php echo number_format($item['total_amount'], 2); ?></strong>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="bg-light">
                                        <tr>
                                            <td colspan="4" class="text-right"><strong>Subtotal:</strong></td>
                                            <td colspan="2" class="text-right">
                                                <strong><?php echo number_format($bill_totals['subtotal'], 2); ?></strong>
                                            </td>
                                            <td></td>
                                        </tr>
                                        <tr>
                                            <td colspan="4" class="text-right"><strong>Tax:</strong></td>
                                            <td colspan="2" class="text-right">
                                                <strong><?php echo number_format($bill_totals['tax'], 2); ?></strong>
                                            </td>
                                            <td></td>
                                        </tr>
                                        <tr class="table-active">
                                            <td colspan="4" class="text-right"><strong>Total Amount:</strong></td>
                                            <td colspan="2" class="text-right">
                                                <h5 class="mb-0 text-success"><?php echo number_format($bill_totals['total'], 2); ?></h5>
                                            </td>
                                            <td></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

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
                <?php if ($existing_bill): ?>
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-receipt mr-2"></i>Bill Summary</h4>
                    </div>
                    <div class="card-body">
                        <div class="text-center mb-3">
                            <div class="h4 text-primary"><?php echo htmlspecialchars($existing_bill['bill_number']); ?></div>
                            <small class="text-muted">Draft Bill</small>
                        </div>
                        <hr>
                        <div class="small">
                            <div class="d-flex justify-content-between mb-2">
                                <span>Subtotal:</span>
                                <span class="font-weight-bold"><?php echo number_format($bill_totals['subtotal'], 2); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Tax:</span>
                                <span class="font-weight-bold"><?php echo number_format($bill_totals['tax'], 2); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-3">
                                <span>Total Amount:</span>
                                <span class="h5 text-success"><?php echo number_format($bill_totals['total'], 2); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Status:</span>
                                <span class="badge badge-<?php 
                                    switch($existing_bill['bill_status']) {
                                        case 'draft': echo 'info'; break;
                                        case 'pending': echo 'warning'; break;
                                        case 'approved': echo 'success'; break;
                                        case 'cancelled': echo 'danger'; break;
                                        default: echo 'secondary';
                                    }
                                ?>">
                                    <?php echo htmlspecialchars(ucfirst($existing_bill['bill_status'])); ?>
                                </span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Items Count:</span>
                                <span class="font-weight-bold"><?php echo count($bill_items); ?></span>
                            </div>
                            <?php if ($invoice_number): ?>
                            <div class="d-flex justify-content-between">
                                <span>Invoice:</span>
                                <span class="font-weight-bold text-success"><?php echo htmlspecialchars($invoice_number); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Important Notes Card -->
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-lightbulb mr-2"></i>Important Notes</h4>
                    </div>
                    <div class="card-body">
                        <ul class="list-unstyled small mb-0">
                            <li class="mb-2">
                                <i class="fas fa-check-circle text-success mr-2"></i>
                                Create draft bill first before adding items
                            </li>
                            <li class="mb-2">
                                <i class="fas fa-check-circle text-success mr-2"></i>
                                Select price list based on patient category
                            </li>
                            <li class="mb-2">
                                <i class="fas fa-exclamation-circle text-danger mr-2"></i>
                                Bill cannot be edited after finalization
                            </li>
                            <li class="mb-2">
                                <i class="fas fa-check-circle text-success mr-2"></i>
                                Items are added from billable items catalog
                            </li>
                            <li class="mb-2">
                                <i class="fas fa-check-circle text-success mr-2"></i>
                                Invoice is automatically generated with bill
                            </li>
                            <li>
                                <i class="fas fa-check-circle text-success mr-2"></i>
                                Finalize bill to create final invoice
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize Select2
    $('.select2').select2({
        width: '100%',
        placeholder: "Select...",
        theme: 'bootstrap',
        minimumResultsForSearch: 10
    });

    // Auto-close alerts after 5 seconds
    setTimeout(function() {
        $('.alert').fadeOut('slow');
    }, 5000);

    // Item selection preview
    $('#billable_item_id').change(function() {
        var selectedOption = $(this).find('option:selected');
        var price = parseFloat(selectedOption.data('price') || 0);
        var taxRate = parseFloat(selectedOption.data('tax') || 0);
        var quantity = parseFloat($('#item_quantity').val()) || 1;
        
        if (price > 0) {
            var subtotal = price * quantity;
            var taxAmount = subtotal * (taxRate / 100);
            var total = subtotal + taxAmount;
            
            $('#unit_price_display').val(price.toFixed(2));
            $('#preview_item_name').text(selectedOption.text());
            $('#preview_item_details').text('Unit Price: ' + price.toFixed(2) + ' × ' + quantity + ' units');
            $('#preview_total').text('Total: ' + total.toFixed(2));
            $('#preview_tax').text('Tax (' + taxRate + '%): ' + taxAmount.toFixed(2));
            $('#item_preview').show();
        } else {
            $('#item_preview').hide();
            $('#unit_price_display').val('');
        }
    });

    // Update preview when quantity changes
    $('#item_quantity').on('input', function() {
        $('#billable_item_id').trigger('change');
    });

    // Form validation for add item form
    $('#addItemForm').on('submit', function(e) {
        var isValid = true;
        
        // Clear previous validation errors
        $('.is-invalid').removeClass('is-invalid');
        $('.select2-selection').removeClass('is-invalid');
        
        // Validate required fields
        if (!$('#billable_item_id').val()) {
            isValid = false;
            $('#billable_item_id').addClass('is-invalid');
            $('#billable_item_id').next('.select2-container').find('.select2-selection').addClass('is-invalid');
        }
        
        var quantity = parseFloat($('#item_quantity').val());
        if (!quantity || quantity <= 0) {
            isValid = false;
            $('#item_quantity').addClass('is-invalid');
        }

        if (!isValid) {
            e.preventDefault();
            
            // Show error message
            if (!$('#formErrorAlert').length) {
                $('#addItemForm').prepend(
                    '<div class="alert alert-danger alert-dismissible" id="formErrorAlert">' +
                    '<button type="button" class="close" data-dismiss="alert">&times;</button>' +
                    '<i class="fas fa-exclamation-triangle mr-2"></i>' +
                    'Please fill in all required fields correctly' +
                    '</div>'
                );
            }
            
            return false;
        }
    });

    // Keyboard shortcuts
    $(document).keydown(function(e) {
        // Ctrl + P to focus on price list (when no bill exists)
        if (e.ctrlKey && e.keyCode === 80) {
            e.preventDefault();
            if (!$('#price_list_id').is(':disabled')) {
                $('#price_list_id').select2('open');
            }
        }
        // Ctrl + I to focus on item search (when bill exists)
        if (e.ctrlKey && e.keyCode === 73) {
            e.preventDefault();
            if ($('#billable_item_id').length) {
                $('#billable_item_id').select2('open');
            }
        }
        // Ctrl + S to submit form (whichever is visible)
        if (e.ctrlKey && e.keyCode === 83) {
            e.preventDefault();
            if ($('#addItemForm').is(':visible')) {
                $('#addItemForm').submit();
            } else {
                // Find and submit the first visible form
                $('form').filter(':visible').first().submit();
            }
        }
        // Escape to go back
        if (e.keyCode === 27) {
            window.location.href = 'visit_details.php?visit_id=<?php echo $visit_id; ?>';
        }
    });
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
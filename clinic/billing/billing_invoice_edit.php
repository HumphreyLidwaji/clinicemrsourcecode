<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Check if invoice ID is provided
if (!isset($_GET['invoice_id']) || empty($_GET['invoice_id'])) {
    $_SESSION['alert_message'] = "Invoice ID is required";
    header("Location: invoices.php");
    exit;
}

$invoice_id = intval($_GET['invoice_id']);

// Fetch invoice details
$invoice_sql = "SELECT i.*, p.patient_id, p.patient_last_name, p.patient_first_name, 
                       CONCAT(p.patient_first_name, ' ', p.patient_last_name) as patient_name,
                       v.visit_id, v.visit_type, v.consultation_fee,
                       u.user_name as created_by_name
                FROM invoices i 
                LEFT JOIN patients p ON i.patient_id = p.patient_id
                LEFT JOIN visits v ON i.visit_id = v.visit_id
                LEFT JOIN users u ON i.created_by = u.user_id
                WHERE i.invoice_id = ?
                LIMIT 1";
                
$invoice_stmt = $mysqli->prepare($invoice_sql);
$invoice_stmt->bind_param("i", $invoice_id);
$invoice_stmt->execute();
$invoice_result = $invoice_stmt->get_result();

if ($invoice_result->num_rows === 0) {
    $_SESSION['alert_message'] = "Invoice not found";
    header("Location: invoices.php");
    exit;
}

$invoice = $invoice_result->fetch_assoc();
$invoice_stmt->close();

// Initialize variables from database
$invoice_number = $invoice['invoice_number'];
$invoice_prefix = $invoice['invoice_prefix'] ?? "INV-";
$invoice_status = $invoice['invoice_status'];
$invoice_date = $invoice['invoice_date'];
$invoice_due_date = $invoice['amount_due'];
$invoice_note = $invoice['invoice_note'] ?? '';
$invoice_currency_code = $invoice['invoice_currency_code'] ?? 'USD';
$invoice_category_id = $invoice['invoice_category_id'] ?? 1;
$patient_id = $invoice['patient_id'];
$patient_name = $invoice['patient_name'];
$visit_id = $invoice['visit_id'] ?? '';

// Fetch invoice items
$items_sql = "SELECT * FROM invoice_items 
              WHERE item_invoice_id = ? 
              ORDER BY item_order ASC";
$items_stmt = $mysqli->prepare($items_sql);
$items_stmt->bind_param("i", $invoice_id);
$items_stmt->execute();
$items_result = $items_stmt->get_result();
$invoice_items = [];

while ($item = $items_result->fetch_assoc()) {
    $invoice_items[] = $item;
}
$items_stmt->close();

// Fetch billed orders linked to this invoice
$billed_orders_sql = "
    SELECT 
        'lab' as order_type,
        lo.lab_order_id as order_id,
        lo.order_number,
        p.patient_id,
        CONCAT(p.patient_first_name, ' ', p.patient_last_name) as patient_name,
        lo.order_date,
        lo.lab_order_status as status,
        'Laboratory Tests' as description,
        (SELECT COUNT(*) FROM lab_order_tests WHERE lab_order_id = lo.lab_order_id) as item_count,
        lo.invoice_id
    FROM lab_orders lo
    JOIN patients p ON lo.lab_order_patient_id = p.patient_id
    WHERE lo.invoice_id = ?
    
    UNION ALL
    
    SELECT 
        'radiology' as order_type,
        ro.radiology_order_id as order_id,
        ro.order_number,
        p.patient_id,
        CONCAT(p.patient_first_name, ' ', p.patient_last_name) as patient_name,
        ro.order_date,
        ro.order_status as status,
        'Radiology Studies' as description,
        (SELECT COUNT(*) FROM radiology_order_studies WHERE radiology_order_id = ro.radiology_order_id) as item_count,
        ro.invoice_id
    FROM radiology_orders ro
    JOIN patients p ON ro.patient_id = p.patient_id
    WHERE ro.invoice_id = ?
    
    UNION ALL
    
    SELECT 
        'service' as order_type,
        so.service_order_id as order_id,
        so.order_number,
        p.patient_id,
        CONCAT(p.patient_first_name, ' ', p.patient_last_name) as patient_name,
        so.order_date,
        so.order_status as status,
        'Medical Service' as description,
        1 as item_count,
        so.invoice_id
    FROM service_orders so
    JOIN patients p ON so.patient_id = p.patient_id
    WHERE so.invoice_id = ?
    
    UNION ALL
    
    SELECT 
        'prescription' as order_type,
        pr.prescription_id as order_id,
        CONCAT('RX-', pr.prescription_id) as order_number,
        p.patient_id,
        CONCAT(p.patient_first_name, ' ', p.patient_last_name) as patient_name,
        pr.prescription_date as order_date,
        pr.prescription_status as status,
        'Medications' as description,
        (SELECT COUNT(*) FROM prescription_items WHERE pi_prescription_id = pr.prescription_id) as item_count,
        pr.invoice_id
    FROM prescriptions pr
    JOIN patients p ON pr.prescription_patient_id = p.patient_id
    WHERE pr.invoice_id = ?
    
    ORDER BY order_date DESC
";

$billed_orders_stmt = $mysqli->prepare($billed_orders_sql);
$billed_orders_stmt->bind_param("iiii", $invoice_id, $invoice_id, $invoice_id, $invoice_id);
$billed_orders_stmt->execute();
$billed_orders_result = $billed_orders_stmt->get_result();
$billed_orders = [];

while ($order = $billed_orders_result->fetch_assoc()) {
    $billed_orders[] = $order;
}
$billed_orders_stmt->close();

// Handle form submission for updating invoice
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // Sanitize inputs
    $invoice_number = sanitizeInput($_POST['invoice_number']);
    $visit_id = intval($_POST['visit_id'] ?? 0);
    $patient_id = intval($_POST['patient_id']);
    $invoice_date = sanitizeInput($_POST['invoice_date']);
    $invoice_due_date = sanitizeInput($_POST['invoice_due_date']);
    $invoice_note = sanitizeInput($_POST['invoice_note']);
    $invoice_status = sanitizeInput($_POST['invoice_status']);
    $invoice_currency_code = sanitizeInput($_POST['invoice_currency_code']);
    $invoice_category_id = intval($_POST['invoice_category_id']);
    
    // Get line items
    $item_names = $_POST['item_name'] ?? [];
    $item_descriptions = $_POST['item_description'] ?? [];
    $item_quantities = $_POST['item_quantity'] ?? [];
    $item_prices = $_POST['item_price'] ?? [];
    $tax_ids = $_POST['tax_id'] ?? [];
    $item_product_ids = $_POST['item_product_id'] ?? [];
    $item_ids = $_POST['item_id'] ?? []; // For existing items
    
    // Validate required fields
    $errors = [];
    
    if (empty($invoice_number)) {
        $errors[] = "Invoice number is required";
    }
    
    if (empty($patient_id)) {
        $errors[] = "Patient selection is required";
    }
    
    if (empty($invoice_category_id)) {
        $errors[] = "Invoice category is required";
    }
    
    if (empty($errors)) {
        // Start transaction
        $mysqli->begin_transaction();
        
        try {
            // Calculate totals
            $subtotal = 0;
            $total_tax = 0;
            $line_items = [];
            
            for ($i = 0; $i < count($item_names); $i++) {
                if (!empty($item_names[$i]) && !empty($item_quantities[$i]) && !empty($item_prices[$i])) {
                    $item_name = $item_names[$i];
                    $item_description = $item_descriptions[$i] ?? '';
                    $quantity = floatval($item_quantities[$i]);
                    $price = floatval($item_prices[$i]);
                    $tax_id = intval($tax_ids[$i]);
                    $item_product_id = intval($item_product_ids[$i] ?? 0);
                    $item_id = intval($item_ids[$i] ?? 0);
                    $line_subtotal = $quantity * $price;
                    
                    // Calculate tax if tax_id is provided
                    $item_tax = 0;
                    if ($tax_id > 0) {
                        $tax_sql = "SELECT tax_percent FROM taxes WHERE tax_id = ?";
                        $tax_stmt = $mysqli->prepare($tax_sql);
                        $tax_stmt->bind_param("i", $tax_id);
                        $tax_stmt->execute();
                        $tax_result = $tax_stmt->get_result();
                        if ($tax_row = $tax_result->fetch_assoc()) {
                            $tax_percent = floatval($tax_row['tax_percent']);
                            $item_tax = $line_subtotal * ($tax_percent / 100);
                        }
                        $tax_stmt->close();
                    }
                    
                    $item_total = $line_subtotal + $item_tax;
                    
                    $subtotal += $line_subtotal;
                    $total_tax += $item_tax;
                    
                    $line_items[] = [
                        'id' => $item_id,
                        'name' => $item_name,
                        'description' => $item_description,
                        'quantity' => $quantity,
                        'price' => $price,
                        'tax_id' => $tax_id,
                        'tax' => $item_tax,
                        'total' => $item_total,
                        'order' => $i + 1,
                        'product_id' => $item_product_id
                    ];
                }
            }
            
            // Calculate final totals
            $total_amount = $subtotal + $total_tax;
            
            // Update invoice
            $invoice_sql = "UPDATE invoices SET
                invoice_number = ?,
                invoice_status = ?,
                invoice_date = ?,
                invoice_due = ?,
                invoice_amount = ?,
                invoice_currency_code = ?,
                invoice_note = ?,
                invoice_category_id = ?,
                invoice_client_id = ?,
                visit_id = ?
                WHERE invoice_id = ?";
            
            $invoice_stmt = $mysqli->prepare($invoice_sql);
            $invoice_stmt->bind_param(
                "sssdssiiiii",
                $invoice_number,
                $invoice_status,
                $invoice_date,
                $invoice_due_date,
                $total_amount,
                $invoice_currency_code,
                $invoice_note,
                $invoice_category_id,
                $patient_id,
                $visit_id,
                $invoice_id
            );
            
            if (!$invoice_stmt->execute()) {
                throw new Exception("Failed to update invoice: " . $invoice_stmt->error);
            }
            
            $invoice_stmt->close();
            
            // Get existing item IDs to identify which ones to delete
            $existing_items_sql = "SELECT item_id FROM invoice_items WHERE item_invoice_id = ?";
            $existing_items_stmt = $mysqli->prepare($existing_items_sql);
            $existing_items_stmt->bind_param("i", $invoice_id);
            $existing_items_stmt->execute();
            $existing_items_result = $existing_items_stmt->get_result();
            $existing_item_ids = [];
            
            while ($row = $existing_items_result->fetch_assoc()) {
                $existing_item_ids[] = $row['item_id'];
            }
            $existing_items_stmt->close();
            
            // Process line items
            $updated_item_ids = [];
            
            foreach ($line_items as $item) {
                if ($item['id'] > 0) {
                    // Update existing item
                    $update_sql = "UPDATE invoice_items SET
                        item_name = ?,
                        item_description = ?,
                        item_quantity = ?,
                        item_price = ?,
                        item_tax = ?,
                        item_total = ?,
                        item_order = ?,
                        item_tax_id = ?,
                        item_product_id = ?
                        WHERE item_id = ? AND item_invoice_id = ?";
                    
                    $update_stmt = $mysqli->prepare($update_sql);
                    $update_stmt->bind_param(
                        "ssddddiiiii",
                        $item['name'],
                        $item['description'],
                        $item['quantity'],
                        $item['price'],
                        $item['tax'],
                        $item['total'],
                        $item['order'],
                        $item['tax_id'],
                        $item['product_id'],
                        $item['id'],
                        $invoice_id
                    );
                    
                    if (!$update_stmt->execute()) {
                        throw new Exception("Failed to update line item: " . $update_stmt->error);
                    }
                    $update_stmt->close();
                    
                    $updated_item_ids[] = $item['id'];
                } else {
                    // Insert new item
                    $insert_sql = "INSERT INTO invoice_items (
                        item_invoice_id, item_name, item_description, item_quantity, 
                        item_price, item_tax, item_total, item_order, item_tax_id, item_product_id
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    
                    $insert_stmt = $mysqli->prepare($insert_sql);
                    $insert_stmt->bind_param(
                        "issddddiii",
                        $invoice_id,
                        $item['name'],
                        $item['description'],
                        $item['quantity'],
                        $item['price'],
                        $item['tax'],
                        $item['total'],
                        $item['order'],
                        $item['tax_id'],
                        $item['product_id']
                    );
                    
                    if (!$insert_stmt->execute()) {
                        throw new Exception("Failed to add line item: " . $insert_stmt->error);
                    }
                    $insert_stmt->close();
                }
            }
            
            // Delete items that were removed
            $items_to_delete = array_diff($existing_item_ids, $updated_item_ids);
            if (!empty($items_to_delete)) {
                $delete_sql = "DELETE FROM invoice_items WHERE item_id IN (" . 
                    implode(',', array_fill(0, count($items_to_delete), '?')) . ")";
                $delete_stmt = $mysqli->prepare($delete_sql);
                $types = str_repeat('i', count($items_to_delete));
                $delete_stmt->bind_param($types, ...$items_to_delete);
                $delete_stmt->execute();
                $delete_stmt->close();
            }
            
            // Add history record
            $history_sql = "INSERT INTO history SET 
                history_date = NOW(),
                history_status = 'Updated',
                history_description = 'Invoice updated',
                history_invoice_id = ?";
            
            $history_stmt = $mysqli->prepare($history_sql);
            $history_stmt->bind_param("i", $invoice_id);
            $history_stmt->execute();
            $history_stmt->close();
            
            // Commit transaction
            $mysqli->commit();
            
            // Redirect to invoice view
            $_SESSION['alert_message'] = "Invoice updated successfully";
            header("Location: invoice.php?invoice_id=" . $invoice_id);
            exit;
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $mysqli->rollback();
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}

// Fetch data for dropdowns
$visits_sql = "SELECT v.visit_id, v.visit_date, p.patient_id, CONCAT(p.patient_first_name, ' ', p.patient_last_name) as patient_name,  
                      d.user_name as doctor_name, v.visit_type, v.consultation_fee
               FROM visits v 
               LEFT JOIN patients p ON v.visit_patient_id = p.patient_id
               LEFT JOIN users d ON v.visit_doctor_id = d.user_id
               WHERE v.is_active = 1
               ORDER BY v.visit_date DESC";
$visits_result = mysqli_query($mysqli, $visits_sql);

$patients_sql = "SELECT patient_id, CONCAT(patient_first_name, ' ', patient_last_name) as patient_name FROM patients ORDER BY patient_last_name";
$patients_result = mysqli_query($mysqli, $patients_sql);

$categories_sql = mysqli_query($mysqli, "SELECT * FROM invoice_categories ORDER BY category_name");
$taxes_sql = mysqli_query($mysqli, "SELECT * FROM taxes WHERE tax_archived_at IS NULL ORDER BY tax_name ASC");

$inventory_items_sql = "SELECT item_id, item_name, item_description, item_unit_price, item_quantity 
                        FROM inventory_items 
                        WHERE item_status IN ('In Stock', 'Low Stock') 
                        ORDER BY item_name";
$inventory_items_result = mysqli_query($mysqli, $inventory_items_sql);

?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2">
            <i class="fa fa-fw fa-edit mr-2"></i>Edit Invoice 
            <small class="text-light">#<?php echo $invoice_prefix . $invoice_number; ?></small>
        </h3>
        <div class="card-tools">
            <a href="invoice.php?invoice_id=<?php echo $invoice_id; ?>" class="btn btn-secondary">
                <i class="fas fa-arrow-left mr-2"></i>Back to Invoice
            </a>
        </div>
    </div>
    
    <div class="card-body">
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <strong>Error!</strong>
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo $error; ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        
        <!-- Billed Orders Section -->
        <?php if (!empty($billed_orders)): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="card border-primary">
                    <div class="card-header bg-primary text-white py-2">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-receipt mr-2"></i>Billed Orders
                            <span class="badge badge-light ml-2"><?php echo count($billed_orders); ?> orders</span>
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Order #</th>
                                        <th>Type</th>
                                        <th>Patient</th>
                                        <th>Order Date</th>
                                        <th>Items</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($billed_orders as $order): ?>
                                        <tr>
                                            <td class="font-weight-bold"><?php echo $order['order_number']; ?></td>
                                            <td>
                                                <?php 
                                                $type_badge = "";
                                                $type_icon = "";
                                                switch($order['order_type']) {
                                                    case 'lab':
                                                        $type_badge = "badge-primary";
                                                        $type_icon = "fa-flask";
                                                        break;
                                                    case 'radiology':
                                                        $type_badge = "badge-info";
                                                        $type_icon = "fa-x-ray";
                                                        break;
                                                    case 'service':
                                                        $type_badge = "badge-success";
                                                        $type_icon = "fa-stethoscope";
                                                        break;
                                                    case 'prescription':
                                                        $type_badge = "badge-warning";
                                                        $type_icon = "fa-pills";
                                                        break;
                                                }
                                                ?>
                                                <span class="badge <?php echo $type_badge; ?>">
                                                    <i class="fas <?php echo $type_icon; ?> mr-1"></i>
                                                    <?php echo ucfirst($order['order_type']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo $order['patient_name']; ?></td>
                                            <td><?php echo date('M j, Y', strtotime($order['order_date'])); ?></td>
                                            <td>
                                                <span class="badge badge-light"><?php echo $order['item_count']; ?> items</span>
                                            </td>
                                            <td>
                                                <span class="badge badge-secondary"><?php echo ucfirst($order['status']); ?></span>
                                            </td>
                                            <td>
                                                <?php 
                                                $order_url = "";
                                                switch($order['order_type']) {
                                                    case 'lab':
                                                        $order_url = "lab_order.php?lab_order_id=" . $order['order_id'];
                                                        break;
                                                    case 'radiology':
                                                        $order_url = "radiology_order.php?radiology_order_id=" . $order['order_id'];
                                                        break;
                                                    case 'service':
                                                        $order_url = "service_order.php?service_order_id=" . $order['order_id'];
                                                        break;
                                                    case 'prescription':
                                                        $order_url = "prescription.php?prescription_id=" . $order['order_id'];
                                                        break;
                                                }
                                                if ($order_url): 
                                                ?>
                                                    <a href="<?php echo $order_url; ?>" class="btn btn-sm btn-outline-primary" target="_blank">
                                                        <i class="fas fa-external-link-alt"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <form method="POST" id="invoiceForm">
            <div class="row">
                <div class="col-md-3">
                    <div class="form-group">
                        <label>Invoice Number <strong class="text-danger">*</strong></label>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text"><?php echo $invoice_prefix; ?></span>
                            </div>
                            <input type="text" class="form-control" name="invoice_number" value="<?php echo $invoice_number; ?>" required>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label>Status <strong class="text-danger">*</strong></label>
                        <select class="form-control select2" name="invoice_status" required>
                            <option value="draft" <?php echo $invoice_status == 'draft' ? 'selected' : ''; ?>>Draft</option>
                            <option value="sent" <?php echo $invoice_status == 'sent' ? 'selected' : ''; ?>>Sent</option>
                            <option value="paid" <?php echo $invoice_status == 'paid' ? 'selected' : ''; ?>>Paid</option>
                            <option value="cancelled" <?php echo $invoice_status == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label>Currency <strong class="text-danger">*</strong></label>
                        <select class="form-control select2" name="invoice_currency_code" required>
                            <option value="USD" <?php echo $invoice_currency_code == 'USD' ? 'selected' : ''; ?>>USD - US Dollar</option>
                            <option value="EUR" <?php echo $invoice_currency_code == 'EUR' ? 'selected' : ''; ?>>EUR - Euro</option>
                            <option value="GBP" <?php echo $invoice_currency_code == 'GBP' ? 'selected' : ''; ?>>GBP - British Pound</option>
                            <option value="KSH" <?php echo $invoice_currency_code == 'KSH' ? 'selected' : ''; ?>>KSH - Kenyan Shilling</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label>Category <strong class="text-danger">*</strong></label>
                        <select class="form-control select2" name="invoice_category_id" required>
                            <?php while ($category = mysqli_fetch_assoc($categories_sql)): ?>
                                <option value="<?php echo $category['category_id']; ?>" <?php echo $invoice_category_id == $category['category_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($category['category_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label>Visit</label>
                        <select class="form-control select2" name="visit_id" id="visit_id" onchange="updatePatientFromVisit()">
                            <option value="">Select Visit</option>
                            <?php while ($visit = mysqli_fetch_assoc($visits_result)): ?>
                                <option value="<?php echo $visit['visit_id']; ?>" 
                                        data-patient-id="<?php echo $visit['patient_id']; ?>"
                                        data-patient-name="<?php echo htmlspecialchars($visit['patient_name']); ?>"
                                        data-consultation-fee="<?php echo $visit['consultation_fee']; ?>"
                                        <?php echo $visit_id == $visit['visit_id'] ? 'selected' : ''; ?>>
                                    <?php echo date('M j, Y', strtotime($visit['visit_date'])); ?> - 
                                    <?php echo htmlspecialchars($visit['patient_name']); ?> - 
                                    <?php echo htmlspecialchars($visit['doctor_name']); ?> -
                                    <?php echo $visit['visit_type']; ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label>Patient <strong class="text-danger">*</strong></label>
                        <select class="form-control select2" name="patient_id" id="patient_id" required>
                            <option value="">Select Patient</option>
                            <?php 
                            mysqli_data_seek($patients_result, 0);
                            while ($patient = mysqli_fetch_assoc($patients_result)): 
                            ?>
                                <option value="<?php echo $patient['patient_id']; ?>" <?php echo $patient_id == $patient['patient_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($patient['patient_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-3">
                    <div class="form-group">
                        <label>Invoice Date <strong class="text-danger">*</strong></label>
                        <input type="date" class="form-control" name="invoice_date" value="<?php echo $invoice_date; ?>" required>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label>Due Date <strong class="text-danger">*</strong></label>
                        <input type="date" class="form-control" name="invoice_due_date" value="<?php echo $invoice_due_date; ?>" required>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label>Consultation Fee</label>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text">$</span>
                            </div>
                            <input type="number" class="form-control" id="consultation_fee" value="<?php echo $invoice['consultation_fee'] ?? '0.00'; ?>" readonly>
                            <div class="input-group-append">
                                <button type="button" class="btn btn-outline-secondary" onclick="addConsultationFee()">Add to Invoice</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Invoice Items Section -->
            <div class="row">
                <div class="col-12">
                    <h5 class="mb-3 border-bottom pb-2">
                        Invoice Items 
                        <span class="badge badge-primary"><?php echo count($invoice_items); ?> items</span>
                    </h5>
                    
                    <div class="table-responsive">
                        <table class="table table-bordered" id="lineItemsTable">
                            <thead class="bg-light">
                                <tr>
                                    <th width="25%">Item</th>
                                    <th width="25%">Description</th>
                                    <th width="10%">Qty</th>
                                    <th width="15%">Unit Price</th>
                                    <th width="15%">Tax</th>
                                    <th width="10%">Stock</th>
                                    <th width="5%"></th>
                                </tr>
                            </thead>
                            <tbody id="lineItemsBody">
                                <?php foreach ($invoice_items as $index => $item): ?>
                                <tr class="line-item">
                                    <td>
                                        <select class="form-control item-select" name="item_name[]" onchange="updateItemDetails(this)" required>
                                            <option value="">Select Item</option>
                                            <option value="custom" <?php echo empty($item['item_product_id']) ? 'selected' : ''; ?>>-- Custom Item --</option>
                                            <?php 
                                            mysqli_data_seek($inventory_items_result, 0);
                                            while ($inv_item = mysqli_fetch_assoc($inventory_items_result)): 
                                            ?>
                                                <option value="<?php echo htmlspecialchars($inv_item['item_name']); ?>"
                                                        data-product-id="<?php echo $inv_item['item_id']; ?>"
                                                        data-description="<?php echo htmlspecialchars($inv_item['item_description'] ?? ''); ?>"
                                                        data-price="<?php echo $inv_item['item_unit_price']; ?>"
                                                        data-stock="<?php echo $inv_item['item_quantity']; ?>"
                                                        <?php echo $item['item_product_id'] == $inv_item['item_id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($inv_item['item_name']); ?> - $<?php echo number_format($inv_item['item_unit_price'], 2); ?>
                                                </option>
                                            <?php endwhile; ?>
                                        </select>
                                        <input type="hidden" class="item-product-id" name="item_product_id[]" value="<?php echo $item['item_product_id']; ?>">
                                        <input type="hidden" name="item_id[]" value="<?php echo $item['item_id']; ?>">
                                    </td>
                                    <td>
                                        <textarea class="form-control item-description" name="item_description[]" rows="2" <?php echo !empty($item['item_product_id']) ? 'readonly' : ''; ?>><?php echo htmlspecialchars($item['item_description']); ?></textarea>
                                    </td>
                                    <td>
                                        <input type="number" class="form-control quantity" name="item_quantity[]" value="<?php echo $item['item_quantity']; ?>" min="0.01" step="0.01" onchange="calculateLineTotal(this)" onkeyup="calculateLineTotal(this)" required>
                                    </td>
                                    <td>
                                        <input type="number" class="form-control price" name="item_price[]" value="<?php echo $item['item_price']; ?>" min="0" step="0.01" onchange="calculateLineTotal(this)" onkeyup="calculateLineTotal(this)" required <?php echo !empty($item['item_product_id']) ? 'readonly' : ''; ?>>
                                    </td>
                                    <td>
                                        <select class="form-control tax-select" name="tax_id[]" onchange="calculateLineTotal(this)">
                                            <option value="0">No Tax</option>
                                            <?php 
                                            mysqli_data_seek($taxes_sql, 0);
                                            while ($tax = mysqli_fetch_assoc($taxes_sql)): 
                                            ?>
                                                <option value="<?php echo $tax['tax_id']; ?>" data-percent="<?php echo $tax['tax_percent']; ?>" <?php echo $item['item_tax_id'] == $tax['tax_id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($tax['tax_name']); ?> (<?php echo $tax['tax_percent']; ?>%)
                                                </option>
                                            <?php endwhile; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <span class="stock-info badge badge-light">-</span>
                                    </td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-sm btn-danger" onclick="removeLineItem(this)" <?php echo count($invoice_items) === 1 ? 'disabled' : ''; ?>>
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="7" class="text-right">
                                        <button type="button" class="btn btn-sm btn-success" onclick="addLineItem()">
                                            <i class="fas fa-plus mr-1"></i>Add Item
                                        </button>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Totals Section -->
            <div class="row justify-content-end">
                <div class="col-md-4">
                    <table class="table table-bordered">
                        <tr>
                            <td><strong>Subtotal:</strong></td>
                            <td class="text-right" id="subtotal">$0.00</td>
                        </tr>
                        <tr>
                            <td><strong>Tax:</strong></td>
                            <td class="text-right" id="taxAmount">$0.00</td>
                        </tr>
                        <tr class="table-active">
                            <td><strong>Total:</strong></td>
                            <td class="text-right" id="totalAmount">$0.00</td>
                        </tr>
                    </table>
                </div>
            </div>
            
            <div class="row">
                <div class="col-12">
                    <div class="form-group">
                        <label>Notes</label>
                        <textarea class="form-control" name="invoice_note" rows="3" placeholder="Additional notes or terms..."><?php echo htmlspecialchars($invoice_note); ?></textarea>
                    </div>
                </div>
            </div>
            
            <div class="row mt-4">
                <div class="col-12 text-right">
                    <a href="invoice.php?invoice_id=<?php echo $invoice_id; ?>" class="btn btn-secondary mr-2">
                        <i class="fas fa-times mr-1"></i>Cancel
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save mr-1"></i>Update Invoice
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- JavaScript functions remain the same as in your original code -->
<script>
// ... (all your existing JavaScript functions remain unchanged)
// updatePatientFromVisit, updateItemDetails, updateStockInfo, addConsultationFee, 
// addLineItem, removeLineItem, updateRemoveButtons, calculateLineTotal, calculateTotals
</script>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Initialize variables with proper date formatting
$invoice_date = date('Y-m-d');
$invoice_due_date = date('Y-m-d', strtotime('+30 days'));
$invoice_status = 'draft';
$invoice_type = 'patient_self_pay'; // Default to patient self-pay
$visit_id = '';
$patient_id = '';
$insurance_provider = '';
$policy_number = '';
$invoice_note = '';
$invoice_currency_code = 'USD';
$invoice_category_id = 1;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // Sanitize inputs
    $invoice_number = sanitizeInput($_POST['invoice_number']);
    $visit_id = intval($_POST['visit_id']);
    $patient_id = intval($_POST['patient_id']);
    $invoice_date = sanitizeInput($_POST['invoice_date']);
    $invoice_due_date = sanitizeInput($_POST['invoice_due_date']);
    $invoice_note = sanitizeInput($_POST['invoice_note']);
    $invoice_status = sanitizeInput($_POST['invoice_status']);
    $invoice_type = sanitizeInput($_POST['invoice_type']);
    $insurance_provider = sanitizeInput($_POST['insurance_provider'] ?? '');
    $policy_number = sanitizeInput($_POST['policy_number'] ?? '');
    $invoice_currency_code = sanitizeInput($_POST['invoice_currency_code']);
    $invoice_category_id = intval($_POST['invoice_category_id']);
    
    // Validate dates
    if (!DateTime::createFromFormat('Y-m-d', $invoice_date)) {
        $errors[] = "Invalid invoice date format";
    }
    
    if (!DateTime::createFromFormat('Y-m-d', $invoice_due_date)) {
        $errors[] = "Invalid due date format";
    }
    
    // Validate insurance fields for insurance invoices
    if ($invoice_type === 'insurance' && empty($insurance_provider)) {
        $errors[] = "Insurance provider is required for insurance invoices";
    }
    
    // Get line items
    $item_names = $_POST['item_name'] ?? [];
    $item_descriptions = $_POST['item_description'] ?? [];
    $item_quantities = $_POST['item_quantity'] ?? [];
    $item_prices = $_POST['item_price'] ?? [];
    $tax_ids = $_POST['tax_id'] ?? [];
    $item_product_ids = $_POST['item_product_id'] ?? [];
    
    // Validate required fields
    $errors = $errors ?? [];
    
    if (empty($invoice_number)) {
        $errors[] = "Invoice number is required";
    }
    
    if (empty($visit_id)) {
        $errors[] = "Visit selection is required";
    }
    
    if (empty($patient_id)) {
        $errors[] = "Patient selection is required";
    }
    
    if (empty($invoice_category_id)) {
        $errors[] = "Invoice category is required";
    }
    
    if (empty($invoice_date)) {
        $errors[] = "Invoice date is required";
    }
    
    if (empty($invoice_due_date)) {
        $errors[] = "Due date is required";
    }
    
    if (empty($item_names) || count(array_filter($item_names)) == 0) {
        $errors[] = "At least one line item is required";
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
            
            // Generate URL key for guest access
            $invoice_url_key = bin2hex(random_bytes(16));
            
            // Set due date based on invoice type
            if ($invoice_type === 'insurance') {
                $invoice_due_date = date('Y-m-d', strtotime('+45 days')); // Insurance usually 45 days
            } else {
                $invoice_due_date = date('Y-m-d', strtotime('+15 days')); // Patient usually 15 days
            }
            
            // Insert invoice with new fields
            $invoice_sql = "INSERT INTO invoices (
                invoice_prefix, invoice_number, invoice_status, invoice_type, invoice_date, invoice_due,
                invoice_amount, insurance_approved_amount, patient_responsibility, invoice_currency_code, 
                invoice_note, invoice_url_key, invoice_category_id, invoice_client_id, visit_id,
                insurance_provider, policy_number, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $insurance_approved_amount = $invoice_type === 'insurance' ? 0 : $total_amount;
            $patient_responsibility = $invoice_type === 'insurance' ? 0 : $total_amount;
            
            $invoice_stmt = $mysqli->prepare($invoice_sql);
            $invoice_stmt->bind_param(
                "sissssdddsssiisssi",
                $invoice_prefix,
                $invoice_number,
                $invoice_status,
                $invoice_type,
                $invoice_date,
                $invoice_due_date,
                $total_amount,
                $insurance_approved_amount,
                $patient_responsibility,
                $invoice_currency_code,
                $invoice_note,
                $invoice_url_key,
                $invoice_category_id,
                $patient_id,
                $visit_id,
                $insurance_provider,
                $policy_number,
                $user_id
            );
            
            if (!$invoice_stmt->execute()) {
                throw new Exception("Failed to create invoice: " . $invoice_stmt->error);
            }
            
            $invoice_id = $mysqli->insert_id;
            $invoice_stmt->close();
            
            // Insert line items
            $item_sql = "INSERT INTO invoice_items (
                item_invoice_id, item_name, item_description, item_quantity, 
                item_price, item_tax, item_total, item_order, item_tax_id, item_product_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $item_stmt = $mysqli->prepare($item_sql);
            
            foreach ($line_items as $item) {
                $item_stmt->bind_param(
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
                
                if (!$item_stmt->execute()) {
                    throw new Exception("Failed to add line item: " . $item_stmt->error);
                }
                
                // Update inventory stock if it's an inventory item
                if ($item['product_id'] > 0) {
                    $update_stock_sql = "UPDATE inventory_items SET item_quantity = item_quantity - ? WHERE item_id = ?";
                    $update_stmt = $mysqli->prepare($update_stock_sql);
                    $update_stmt->bind_param("di", $item['quantity'], $item['product_id']);
                    $update_stmt->execute();
                    $update_stmt->close();
                }
            }
            
            $item_stmt->close();
            
            // Update visit with invoice_id
            $update_visit_sql = "UPDATE visits SET visit_invoice_id = ? WHERE visit_id = ?";
            $update_visit_stmt = $mysqli->prepare($update_visit_sql);
            $update_visit_stmt->bind_param("ii", $invoice_id, $visit_id);
            
            if (!$update_visit_stmt->execute()) {
                throw new Exception("Failed to update visit with invoice ID: " . $update_visit_stmt->error);
            }
            $update_visit_stmt->close();
            
            // Add history record
            $history_sql = "INSERT INTO history SET 
                history_date = NOW(),
                history_status = 'Created',
                history_description = 'Invoice created - Type: " . $invoice_type . "',
                history_invoice_id = ?";
            
            $history_stmt = $mysqli->prepare($history_sql);
            $history_stmt->bind_param("i", $invoice_id);
            $history_stmt->execute();
            $history_stmt->close();
            
            // Commit transaction
            $mysqli->commit();
            
            // Redirect to invoice view
            $_SESSION['alert_message'] = ucfirst(str_replace('_', ' ', $invoice_type)) . " invoice created successfully";
            header("Location: invoice.php?invoice_id=" . $invoice_id);
            exit;
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $mysqli->rollback();
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}

// Generate next invoice number with prefix based on type
$invoice_type_prefix = [
    'insurance' => 'INS-',
    'patient_self_pay' => 'PAT-',
    'patient_co_pay' => 'COP-'
];

$next_invoice_sql = "SELECT MAX(invoice_number) as last_number FROM invoices";
$next_invoice_result = mysqli_query($mysqli, $next_invoice_sql);
$next_invoice_row = mysqli_fetch_assoc($next_invoice_result);
$last_number = intval($next_invoice_row['last_number'] ?? 0);
$invoice_number = $last_number + 1;
$invoice_prefix = $invoice_type_prefix[$invoice_type] ?? "INV-";

// Fetch active visits for dropdown
$visits_sql = "SELECT v.visit_id, v.visit_date, p.patient_id, p.patient_first_name, p.patient_last_name, 
                      p.insurance_provider, p.policy_number,
                      d.user_name as doctor_name, v.visit_type, v.consultation_fee
               FROM visits v 
               LEFT JOIN patients p ON v.visit_patient_id = p.patient_id
               LEFT JOIN users d ON v.visit_doctor_id = d.user_id
               WHERE v.is_active = 1 AND v.visit_invoice_id IS NULL
               ORDER BY v.visit_date DESC";
$visits_result = mysqli_query($mysqli, $visits_sql);

// Fetch active patients for dropdown
$patients_sql = "SELECT patient_id, patient_first_name, patient_last_name, insurance_provider, policy_number 
                 FROM patients WHERE patient_status  = 'Active' ORDER BY patient_first_name";
$patients_result = mysqli_query($mysqli, $patients_sql);

// Fetch insurance providers
$insurance_providers_sql = "SELECT provider_name FROM insurance_providers WHERE is_active = 1 ORDER BY provider_name";
$insurance_providers_result = mysqli_query($mysqli, $insurance_providers_sql);

// Fetch invoice categories
$categories_sql = mysqli_query($mysqli, "SELECT * FROM invoice_categories ORDER BY category_name");
if (!$categories_sql || mysqli_num_rows($categories_sql) == 0) {
    $default_category_id = 1;
} else {
    $first_category = mysqli_fetch_assoc($categories_sql);
    $default_category_id = $first_category['category_id'];
    mysqli_data_seek($categories_sql, 0);
}

// Fetch taxes for dropdown
$taxes_sql = mysqli_query($mysqli, "SELECT * FROM taxes WHERE tax_archived_at IS NULL ORDER BY tax_name ASC");

// Fetch inventory items for dropdown
$inventory_items_sql = "SELECT item_id, item_name, item_description, item_unit_price, item_quantity 
                        FROM inventory_items 
                        WHERE item_status IN ('In Stock', 'Low Stock') 
                        ORDER BY item_name";
$inventory_items_result = mysqli_query($mysqli, $inventory_items_sql);

?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2"><i class="fa fa-fw fa-file-invoice mr-2"></i>Create New Invoice</h3>
        <div class="card-tools">
            <a href="invoices.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left mr-2"></i>Back to Invoices
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
        
        <form method="POST" id="invoiceForm">
            <div class="row">
                <div class="col-md-2">
                    <div class="form-group">
                        <label>Invoice Type <strong class="text-danger">*</strong></label>
                        <select class="form-control select2" name="invoice_type" id="invoice_type" required onchange="updateInvoiceType()">
                            <option value="patient_self_pay" <?php echo $invoice_type == 'patient_self_pay' ? 'selected' : ''; ?>>Patient Self-Pay</option>
                            <option value="insurance" <?php echo $invoice_type == 'insurance' ? 'selected' : ''; ?>>Insurance</option>
                            <option value="patient_co_pay" <?php echo $invoice_type == 'patient_co_pay' ? 'selected' : ''; ?>>Patient Co-Pay</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-group">
                        <label>Invoice Number <strong class="text-danger">*</strong></label>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text" id="invoice_prefix_display"><?php echo $invoice_prefix; ?></span>
                            </div>
                            <input type="text" class="form-control" name="invoice_number" value="<?php echo $invoice_number; ?>" required readonly>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-group">
                        <label>Status <strong class="text-danger">*</strong></label>
                        <select class="form-control select2" name="invoice_status" required>
                            <option value="draft" <?php echo $invoice_status == 'draft' ? 'selected' : ''; ?>>Draft</option>
                            <option value="sent" <?php echo $invoice_status == 'sent' ? 'selected' : ''; ?>>Sent</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label>Currency <strong class="text-danger">*</strong></label>
                        <select class="form-control select2" name="invoice_currency_code" required>
                            <option value="USD" selected>USD - US Dollar</option>
                            <option value="EUR">EUR - Euro</option>
                            <option value="GBP">GBP - British Pound</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label>Category <strong class="text-danger">*</strong></label>
                        <select class="form-control select2" name="invoice_category_id" required>
                            <?php if (mysqli_num_rows($categories_sql) > 0): ?>
                                <?php while ($category = mysqli_fetch_assoc($categories_sql)): ?>
                                    <option value="<?php echo $category['category_id']; ?>" <?php echo $invoice_category_id == $category['category_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($category['category_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <option value="1">General</option>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>
            </div>
            
            <!-- Insurance Information Section (shown only for insurance type) -->
            <div class="row" id="insurance_info_section" style="display: <?php echo $invoice_type == 'insurance' ? 'flex' : 'none'; ?>;">
                <div class="col-md-6">
                    <div class="form-group">
                        <label>Insurance Provider <strong class="text-danger">*</strong></label>
                        <select class="form-control select2" name="insurance_provider" id="insurance_provider">
                            <option value="">Select Insurance Provider</option>
                            <?php while ($provider = mysqli_fetch_assoc($insurance_providers_result)): ?>
                                <option value="<?php echo htmlspecialchars($provider['provider_name']); ?>" 
                                    <?php echo $insurance_provider == $provider['provider_name'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($provider['provider_name']); ?>
                                </option>
                            <?php endwhile; ?>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label>Policy Number</label>
                        <input type="text" class="form-control" name="policy_number" id="policy_number" 
                               value="<?php echo htmlspecialchars($policy_number); ?>" 
                               placeholder="Enter policy number">
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label>Visit <strong class="text-danger">*</strong></label>
                        <select class="form-control select2" name="visit_id" id="visit_id" required onchange="updatePatientFromVisit()">
                            <option value="">Select Visit</option>
                            <?php while ($visit = mysqli_fetch_assoc($visits_result)): ?>
                                <option value="<?php echo $visit['visit_id']; ?>" 
                                        data-patient-id="<?php echo $visit['patient_id']; ?>"
                                        data-patient-name="<?php echo htmlspecialchars($visit['patient_first_name'] . ' ' . $visit['patient_last_name']); ?>"
                                        data-insurance-provider="<?php echo htmlspecialchars($visit['insurance_provider'] ?? ''); ?>"
                                        data-policy-number="<?php echo htmlspecialchars($visit['policy_number'] ?? ''); ?>"
                                        data-consultation-fee="<?php echo $visit['consultation_fee']; ?>">
                                    <?php echo date('M j, Y', strtotime($visit['visit_date'])); ?> - 
                                    <?php echo htmlspecialchars($visit['patient_first_name'] . ' ' . $visit['patient_last_name']); ?> - 
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
                        <select class="form-control select2" name="patient_id" id="patient_id" required onchange="updatePatientInsurance()">
                            <option value="">Select Patient</option>
                            <?php 
                            mysqli_data_seek($patients_result, 0);
                            while ($patient = mysqli_fetch_assoc($patients_result)): 
                            ?>
                                <option value="<?php echo $patient['patient_id']; ?>" 
                                        data-insurance-provider="<?php echo htmlspecialchars($patient['insurance_provider'] ?? ''); ?>"
                                        data-policy-number="<?php echo htmlspecialchars($patient['policy_number'] ?? ''); ?>"
                                        <?php echo $patient_id == $patient['patient_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($patient['patient_first_name'] . ' ' . $patient['patient_last_name']); ?>
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
                        <input type="date" class="form-control" name="invoice_due_date" id="invoice_due_date" value="<?php echo $invoice_due_date; ?>" required>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label>Consultation Fee</label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="consultation_fee" value="0.00" min="0" step="0.01" readonly>
                            <div class="input-group-append">
                                <button type="button" class="btn btn-outline-primary" onclick="addConsultationFee()">
                                    <i class="fas fa-plus mr-1"></i>Add to Invoice
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Line Items Section (same as before, but with updated JavaScript) -->
            <div class="row">
                <div class="col-12">
                    <h5 class="mb-3 border-bottom pb-2">Line Items</h5>
                    
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
                                <tr class="line-item">
                                    <td>
                                        <select class="form-control item-select" name="item_name[]" onchange="updateItemDetails(this)" required>
                                            <option value="">Select Item</option>
                                            <option value="custom">-- Custom Item --</option>
                                            <?php while ($item = mysqli_fetch_assoc($inventory_items_result)): ?>
                                                <option value="<?php echo htmlspecialchars($item['item_name']); ?>"
                                                        data-product-id="<?php echo $item['item_id']; ?>"
                                                        data-description="<?php echo htmlspecialchars($item['item_description'] ?? ''); ?>"
                                                        data-price="<?php echo $item['item_unit_price']; ?>"
                                                        data-stock="<?php echo $item['item_quantity']; ?>">
                                                    <?php echo htmlspecialchars($item['item_name']); ?> - $<?php echo number_format($item['item_unit_price'], 2); ?>
                                                </option>
                                            <?php endwhile; ?>
                                        </select>
                                        <input type="hidden" class="item-product-id" name="item_product_id[]" value="0">
                                    </td>
                                    <td>
                                        <textarea class="form-control item-description" name="item_description[]" rows="2" placeholder="Item description"></textarea>
                                    </td>
                                    <td>
                                        <input type="number" class="form-control quantity" name="item_quantity[]" value="1" min="0.01" step="0.01" onchange="calculateLineTotal(this)" onkeyup="calculateLineTotal(this)" required>
                                    </td>
                                    <td>
                                        <input type="number" class="form-control price" name="item_price[]" value="0.00" min="0" step="0.01" onchange="calculateLineTotal(this)" onkeyup="calculateLineTotal(this)" required>
                                    </td>
                                    <td>
                                        <select class="form-control tax-select" name="tax_id[]" onchange="calculateLineTotal(this)">
                                            <option value="0">No Tax</option>
                                            <?php 
                                            mysqli_data_seek($taxes_sql, 0);
                                            while ($tax = mysqli_fetch_assoc($taxes_sql)): 
                                            ?>
                                                <option value="<?php echo $tax['tax_id']; ?>" data-percent="<?php echo $tax['tax_percent']; ?>">
                                                    <?php echo htmlspecialchars($tax['tax_name']); ?> (<?php echo $tax['tax_percent']; ?>%)
                                                </option>
                                            <?php endwhile; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <span class="stock-info badge badge-light">-</span>
                                    </td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-sm btn-danger" onclick="removeLineItem(this)" disabled>
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </td>
                                </tr>
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
                        <textarea class="form-control" name="invoice_note" rows="3" placeholder="Additional notes or terms..."><?php echo $invoice_note; ?></textarea>
                    </div>
                </div>
            </div>
            
            <div class="row mt-4">
                <div class="col-12 text-right">
                    <a href="invoices.php" class="btn btn-secondary mr-2">
                        <i class="fas fa-times mr-1"></i>Cancel
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save mr-1"></i>Create Invoice
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
// Invoice type prefixes
const invoiceTypePrefixes = {
    'insurance': 'INS-',
    'patient_self_pay': 'PAT-', 
    'patient_co_pay': 'COP-'
};

function updateInvoiceType() {
    const invoiceType = document.getElementById('invoice_type').value;
    const insuranceSection = document.getElementById('insurance_info_section');
    const prefixDisplay = document.getElementById('invoice_prefix_display');
    const dueDateInput = document.getElementById('invoice_due_date');
    
    // Update prefix
    prefixDisplay.textContent = invoiceTypePrefixes[invoiceType] || 'INV-';
    
    // Show/hide insurance section
    if (invoiceType === 'insurance') {
        insuranceSection.style.display = 'flex';
        // Set due date to 45 days for insurance
        const dueDate = new Date();
        dueDate.setDate(dueDate.getDate() + 45);
        dueDateInput.value = dueDate.toISOString().split('T')[0];
    } else {
        insuranceSection.style.display = 'none';
        // Set due date to 15 days for patient invoices
        const dueDate = new Date();
        dueDate.setDate(dueDate.getDate() + 15);
        dueDateInput.value = dueDate.toISOString().split('T')[0];
    }
}

function updatePatientFromVisit() {
    const visitSelect = document.getElementById('visit_id');
    const patientSelect = document.getElementById('patient_id');
    const consultationFeeInput = document.getElementById('consultation_fee');
    const insuranceProviderInput = document.getElementById('insurance_provider');
    const policyNumberInput = document.getElementById('policy_number');
    
    if (visitSelect.value) {
        const selectedOption = visitSelect.options[visitSelect.selectedIndex];
        const patientId = selectedOption.getAttribute('data-patient-id');
        const patientName = selectedOption.getAttribute('data-patient-name');
        const insuranceProvider = selectedOption.getAttribute('data-insurance-provider');
        const policyNumber = selectedOption.getAttribute('data-policy-number');
        const consultationFee = selectedOption.getAttribute('data-consultation-fee') || '1500';
        
        // Update patient selection
        patientSelect.value = patientId;
        
        // Update insurance information
        if (insuranceProvider) {
            insuranceProviderInput.value = insuranceProvider;
            // Trigger Select2 update
            $(insuranceProviderInput).val(insuranceProvider).trigger('change');
        }
        if (policyNumber) {
            policyNumberInput.value = policyNumber;
        }
        
        // Update consultation fee
        consultationFeeInput.value = parseFloat(consultationFee).toFixed(2);
        
        // Trigger Select2 update for patient
        $(patientSelect).trigger('change');
    }
}

function updatePatientInsurance() {
    const patientSelect = document.getElementById('patient_id');
    const insuranceProviderInput = document.getElementById('insurance_provider');
    const policyNumberInput = document.getElementById('policy_number');
    
    if (patientSelect.value) {
        const selectedOption = patientSelect.options[patientSelect.selectedIndex];
        const insuranceProvider = selectedOption.getAttribute('data-insurance-provider');
        const policyNumber = selectedOption.getAttribute('data-policy-number');
        
        // Update insurance information
        if (insuranceProvider) {
            insuranceProviderInput.value = insuranceProvider;
            $(insuranceProviderInput).val(insuranceProvider).trigger('change');
        }
        if (policyNumber) {
            policyNumberInput.value = policyNumber;
        }
    }
}

function updateItemDetails(select) {
    const row = select.closest('tr');
    const selectedOption = select.options[select.selectedIndex];
    const productIdInput = row.querySelector('.item-product-id');
    const descriptionInput = row.querySelector('.item-description');
    const priceInput = row.querySelector('.price');
    const stockInfo = row.querySelector('.stock-info');
    
    if (select.value === 'custom') {
        // Custom item - clear all fields
        productIdInput.value = '0';
        descriptionInput.value = '';
        priceInput.value = '0.00';
        descriptionInput.readOnly = false;
        priceInput.readOnly = false;
        updateStockInfo(stockInfo, null);
    } else if (select.value) {
        // Inventory item - populate fields
        const productId = selectedOption.getAttribute('data-product-id');
        const description = selectedOption.getAttribute('data-description');
        const price = selectedOption.getAttribute('data-price');
        const stock = selectedOption.getAttribute('data-stock');
        
        productIdInput.value = productId;
        descriptionInput.value = description;
        priceInput.value = parseFloat(price).toFixed(2);
        descriptionInput.readOnly = true;
        priceInput.readOnly = true;
        updateStockInfo(stockInfo, stock);
    } else {
        // No selection - clear fields
        productIdInput.value = '0';
        descriptionInput.value = '';
        priceInput.value = '0.00';
        descriptionInput.readOnly = false;
        priceInput.readOnly = false;
        updateStockInfo(stockInfo, null);
    }
    
    calculateLineTotal(select);
}

function updateStockInfo(stockElement, stock) {
    if (stock === null || stock === '') {
        stockElement.className = 'stock-info badge badge-light';
        stockElement.textContent = '-';
    } else if (stock > 10) {
        stockElement.className = 'stock-info badge badge-success';
        stockElement.textContent = stock + ' in stock';
    } else if (stock > 0) {
        stockElement.className = 'stock-info badge badge-warning';
        stockElement.textContent = stock + ' in stock';
    } else {
        stockElement.className = 'stock-info badge badge-danger';
        stockElement.textContent = 'Out of stock';
    }
}

function addLineItem() {
    const tbody = document.getElementById('lineItemsBody');
    const newRow = document.createElement('tr');
    newRow.className = 'line-item';
    
    newRow.innerHTML = `
        <td>
            <select class="form-control item-select" name="item_name[]" onchange="updateItemDetails(this)" required>
                <option value="">Select Item</option>
                <option value="custom">-- Custom Item --</option>
                <?php 
                mysqli_data_seek($inventory_items_result, 0);
                while ($item = mysqli_fetch_assoc($inventory_items_result)): 
                ?>
                    <option value="<?php echo htmlspecialchars($item['item_name']); ?>"
                            data-product-id="<?php echo $item['item_id']; ?>"
                            data-description="<?php echo htmlspecialchars($item['item_description'] ?? ''); ?>"
                            data-price="<?php echo $item['item_unit_price']; ?>"
                            data-stock="<?php echo $item['item_quantity']; ?>">
                        <?php echo htmlspecialchars($item['item_name']); ?> - $<?php echo number_format($item['item_unit_price'], 2); ?>
                    </option>
                <?php endwhile; ?>
            </select>
            <input type="hidden" class="item-product-id" name="item_product_id[]" value="0">
        </td>
        <td>
            <textarea class="form-control item-description" name="item_description[]" rows="2" placeholder="Item description"></textarea>
        </td>
        <td>
            <input type="number" class="form-control quantity" name="item_quantity[]" value="1" min="0.01" step="0.01" onchange="calculateLineTotal(this)" onkeyup="calculateLineTotal(this)" required>
        </td>
        <td>
            <input type="number" class="form-control price" name="item_price[]" value="0.00" min="0" step="0.01" onchange="calculateLineTotal(this)" onkeyup="calculateLineTotal(this)" required>
        </td>
        <td>
            <select class="form-control tax-select" name="tax_id[]" onchange="calculateLineTotal(this)">
                <option value="0">No Tax</option>
                <?php 
                mysqli_data_seek($taxes_sql, 0);
                while ($tax = mysqli_fetch_assoc($taxes_sql)): 
                ?>
                    <option value="<?php echo $tax['tax_id']; ?>" data-percent="<?php echo $tax['tax_percent']; ?>">
                        <?php echo htmlspecialchars($tax['tax_name']); ?> (<?php echo $tax['tax_percent']; ?>%)
                    </option>
                <?php endwhile; ?>
            </select>
        </td>
        <td>
            <span class="stock-info badge badge-light">-</span>
        </td>
        <td class="text-center">
            <button type="button" class="btn btn-sm btn-danger" onclick="removeLineItem(this)">
                <i class="fas fa-times"></i>
            </button>
        </td>
    `;
    
    tbody.appendChild(newRow);
    
    // Initialize Select2 for the new item select
    $(newRow.querySelector('.item-select')).select2();
    $(newRow.querySelector('.tax-select')).select2();
    
    updateRemoveButtons();
}

function removeLineItem(button) {
    const row = button.closest('tr');
    row.remove();
    calculateTotals();
    updateRemoveButtons();
}

function updateRemoveButtons() {
    const rows = document.querySelectorAll('#lineItemsBody tr');
    const removeButtons = document.querySelectorAll('#lineItemsBody .btn-danger');
    
    if (rows.length === 1) {
        removeButtons[0].disabled = true;
    } else {
        removeButtons.forEach(btn => btn.disabled = false);
    }
}

function calculateLineTotal(input) {
    calculateTotals();
}

function calculateTotals() {
    let subtotal = 0;
    let totalTax = 0;
    
    document.querySelectorAll('.line-item').forEach(row => {
        const quantity = parseFloat(row.querySelector('.quantity').value) || 0;
        const price = parseFloat(row.querySelector('.price').value) || 0;
        const taxSelect = row.querySelector('.tax-select');
        const taxPercent = taxSelect && taxSelect.selectedOptions[0] ? parseFloat(taxSelect.selectedOptions[0].getAttribute('data-percent') || 0) : 0;
        
        const lineSubtotal = quantity * price;
        const lineTax = lineSubtotal * (taxPercent / 100);
        
        subtotal += lineSubtotal;
        totalTax += lineTax;
    });
    
    const totalAmount = subtotal + totalTax;
    
    document.getElementById('subtotal').textContent = '$' + subtotal.toFixed(2);
    document.getElementById('taxAmount').textContent = '$' + totalTax.toFixed(2);
    document.getElementById('totalAmount').textContent = '$' + totalAmount.toFixed(2);
}

function addConsultationFee() {
    const consultationFee = parseFloat(document.getElementById('consultation_fee').value) || 0;
    if (consultationFee > 0) {
        // Add consultation fee as a line item
        const tbody = document.getElementById('lineItemsBody');
        const newRow = document.createElement('tr');
        newRow.className = 'line-item';
        
        newRow.innerHTML = `
            <td>
                <select class="form-control item-select" name="item_name[]" onchange="updateItemDetails(this)" required>
                    <option value="Consultation Fee" selected>Consultation Fee</option>
                </select>
                <input type="hidden" class="item-product-id" name="item_product_id[]" value="0">
            </td>
            <td>
                <textarea class="form-control item-description" name="item_description[]" rows="2" readonly>Professional consultation fee</textarea>
            </td>
            <td>
                <input type="number" class="form-control quantity" name="item_quantity[]" value="1" min="1" readonly>
            </td>
            <td>
                <input type="number" class="form-control price" name="item_price[]" value="${consultationFee.toFixed(2)}" readonly>
            </td>
            <td>
                <select class="form-control tax-select" name="tax_id[]" onchange="calculateLineTotal(this)" disabled>
                    <option value="0" selected>No Tax</option>
                </select>
            </td>
            <td>
                <span class="stock-info badge badge-light">-</span>
            </td>
            <td class="text-center">
                <button type="button" class="btn btn-sm btn-danger" onclick="removeLineItem(this)">
                    <i class="fas fa-times"></i>
                </button>
            </td>
        `;
        
        tbody.appendChild(newRow);
        calculateTotals();
        updateRemoveButtons();
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    calculateTotals();
    updateRemoveButtons();
    
    // Initialize Select2 for all selects
    $('.select2').select2();
    $('.item-select').select2();
    $('.tax-select').select2();
    
    // Set initial due date based on invoice type
    updateInvoiceType();
});


// Form validation
document.getElementById('invoiceForm').addEventListener('submit', function(e) {
    const lineItems = document.querySelectorAll('.line-item');
    let hasValidItems = false;
    
    lineItems.forEach(row => {
        const itemSelect = row.querySelector('.item-select');
        const quantity = row.querySelector('.quantity').value;
        const price = row.querySelector('.price').value;
        
        if (itemSelect.value && quantity > 0 && price >= 0) {
            hasValidItems = true;
        }
    });
    
    if (!hasValidItems) {
        e.preventDefault();
        alert('Please add at least one valid line item with name, quantity and price.');
        return false;
    }
    
    const visitId = document.getElementById('visit_id').value;
    const patientId = document.getElementById('patient_id').value;
    
    if (!visitId || !patientId) {
        e.preventDefault();
        alert('Please select both a visit and patient.');
        return false;
    }
});
</script>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Get surgery_id from URL
$surgery_id = isset($_GET['surgery_id']) ? intval($_GET['surgery_id']) : 0;
if (!$surgery_id) {
    die("Surgery ID is required");
}

// Get surgery details
$surgery_sql = "SELECT s.*, 
                       p.patient_first_name, p.patient_last_name, p.patient_mrn, 
                       p.patient_gender, p.patient_dob, p.patient_phone, p.patient_email,
                       u.user_name as surgeon_name,
                       ms.service_name as procedure_name, ms.fee_amount as procedure_fee,
                       ms.service_code, ms.duration_minutes,
                       t.theatre_name, t.theatre_number,
                       so.order_number as service_order_number
                       
                       
                FROM surgeries s
                JOIN patients p ON s.patient_id = p.patient_id
                LEFT JOIN users u ON s.primary_surgeon_id = u.user_id
                LEFT JOIN medical_services ms ON s.medical_service_id = ms.medical_service_id
                LEFT JOIN theatres t ON s.theatre_id = t.theatre_id
                LEFT JOIN service_orders so ON s.service_order_id = so.service_order_id
       
                WHERE s.surgery_id = $surgery_id";
$surgery_result = $mysqli->query($surgery_sql);
if ($surgery_result->num_rows == 0) {
    die("Surgery not found");
}
$surgery = $surgery_result->fetch_assoc();

// Get existing bill if any
$bill_sql = "SELECT * FROM surgery_bills WHERE surgery_id = $surgery_id";
$bill_result = $mysqli->query($bill_sql);
$existing_bill = $bill_result->fetch_assoc();

// Get bill items


// Get inventory usage for this surgery

// Get available medical services
$medical_services_sql = "SELECT * FROM medical_services 
                         WHERE is_active = 1 
                         AND service_type IN ('Procedure', 'Consultation', 'Other')
                         ORDER BY service_name";
$medical_services_result = $mysqli->query($medical_services_sql);

// Get available inventory items

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action == 'add_item') {
        $item_type = sanitizeInput($_POST['item_type']);
        $item_id = intval($_POST['item_id']);
        $quantity = intval($_POST['quantity']);
        $unit_price = floatval($_POST['unit_price']);
        $description = sanitizeInput($_POST['description'] ?? '');
        
        // Get or create bill
        if (!$existing_bill) {
            $create_bill_sql = "INSERT INTO surgery_bills (surgery_id, created_by, created_at) 
                               VALUES ($surgery_id, {$_SESSION['user_id']}, NOW())";
            if ($mysqli->query($create_bill_sql)) {
                $bill_id = $mysqli->insert_id;
                $existing_bill = ['bill_id' => $bill_id];
            }
        } else {
            $bill_id = $existing_bill['bill_id'];
        }
        
        // Add item to bill
        if ($item_type == 'service') {
            $insert_sql = "INSERT INTO surgery_bill_items 
                          (bill_id, item_type, medical_service_id, quantity, unit_price, 
                           description, created_by, created_at)
                          VALUES ($bill_id, 'service', $item_id, $quantity, $unit_price,
                          '$description', {$_SESSION['user_id']}, NOW())";
        } elseif ($item_type == 'inventory') {
            $insert_sql = "INSERT INTO surgery_bill_items 
                          (bill_id, item_type, inventory_item_id, quantity, unit_price, 
                           description, created_by, created_at)
                          VALUES ($bill_id, 'inventory', $item_id, $quantity, $unit_price,
                          '$description', {$_SESSION['user_id']}, NOW())";
        } else {
            $insert_sql = "INSERT INTO surgery_bill_items 
                          (bill_id, item_type, item_description, quantity, unit_price, 
                           created_by, created_at)
                          VALUES ($bill_id, 'custom', '$description', $quantity, $unit_price,
                          {$_SESSION['user_id']}, NOW())";
        }
        
        if ($mysqli->query($insert_sql)) {
            // If inventory item, update inventory usage billing status
            if ($item_type == 'inventory') {
                $update_usage_sql = "UPDATE surgery_inventory_usage 
                                     SET billed = 1 
                                     WHERE surgery_id = $surgery_id 
                                     AND item_id = $item_id 
                                     AND billed = 0";
                $mysqli->query($update_usage_sql);
            }
            
            $_SESSION['alert_message'] = "Item added to bill successfully";
            $_SESSION['alert_type'] = "success";
            header("Location: surgery_billing.php?surgery_id=$surgery_id");
            exit;
        } else {
            $error = "Error adding item: " . $mysqli->error;
        }
    } 
    elseif ($action == 'remove_item') {
        $item_id = intval($_POST['item_id']);
        
        // Get item details before deleting
        $item_sql = "SELECT * FROM surgery_bill_items WHERE item_id = $item_id";
        $item_result = $mysqli->query($item_sql);
        if ($item_result->num_rows > 0) {
            $item = $item_result->fetch_assoc();
            
            // If it was an inventory item, mark as not billed
            if ($item['inventory_item_id']) {
                $update_usage_sql = "UPDATE surgery_inventory_usage 
                                     SET billed = 0 
                                     WHERE surgery_id = $surgery_id 
                                     AND item_id = {$item['inventory_item_id']}";
                $mysqli->query($update_usage_sql);
            }
            
            // Delete the item
            $delete_sql = "DELETE FROM surgery_bill_items WHERE item_id = $item_id";
            if ($mysqli->query($delete_sql)) {
                $_SESSION['alert_message'] = "Item removed from bill successfully";
                $_SESSION['alert_type'] = "success";
            }
        }
        header("Location: surgery_billing.php?surgery_id=$surgery_id");
        exit;
    }
    elseif ($action == 'update_quantities') {
        if ($existing_bill) {
            foreach ($_POST['quantities'] as $item_id => $quantity) {
                $item_id = intval($item_id);
                $quantity = intval($quantity);
                
                if ($quantity > 0) {
                    $update_sql = "UPDATE surgery_bill_items 
                                   SET quantity = $quantity,
                                       updated_at = NOW(),
                                       updated_by = {$_SESSION['user_id']}
                                   WHERE item_id = $item_id";
                    $mysqli->query($update_sql);
                }
            }
            $_SESSION['alert_message'] = "Quantities updated successfully";
            $_SESSION['alert_type'] = "success";
        }
        header("Location: surgery_billing.php?surgery_id=$surgery_id");
        exit;
    }
    elseif ($action == 'finalize_bill') {
        if ($existing_bill) {
            $discount_percent = floatval($_POST['discount_percent']);
            $discount_amount = floatval($_POST['discount_amount']);
            $tax_rate = floatval($_POST['tax_rate']);
            $notes = sanitizeInput($_POST['notes']);
            $payment_terms = sanitizeInput($_POST['payment_terms']);
            $bill_date = $_POST['bill_date'];
            $due_date = $_POST['due_date'];
            
            // Calculate totals
            $items_sql = "SELECT SUM(quantity * unit_price) as subtotal 
                         FROM surgery_bill_items 
                         WHERE bill_id = {$existing_bill['bill_id']}";
            $items_result = $mysqli->query($items_sql);
            $subtotal = $items_result->fetch_assoc()['subtotal'] ?? 0;
            
            // Apply discount
            if ($discount_percent > 0) {
                $discount_amount = $subtotal * ($discount_percent / 100);
            }
            
            $total_after_discount = $subtotal - $discount_amount;
            $tax_amount = $total_after_discount * ($tax_rate / 100);
            $grand_total = $total_after_discount + $tax_amount;
            
            // Update bill
            $update_sql = "UPDATE surgery_bills SET
                          subtotal = $subtotal,
                          discount_percent = $discount_percent,
                          discount_amount = $discount_amount,
                          tax_rate = $tax_rate,
                          tax_amount = $tax_amount,
                          grand_total = $grand_total,
                          bill_date = '$bill_date',
                          due_date = '$due_date',
                          notes = '$notes',
                          payment_terms = '$payment_terms',
                          status = 'finalized',
                          finalized_by = {$_SESSION['user_id']},
                          finalized_at = NOW(),
                          updated_at = NOW()
                          WHERE bill_id = {$existing_bill['bill_id']}";
            
            if ($mysqli->query($update_sql)) {
                // Update surgery status
                $update_surgery_sql = "UPDATE surgeries SET billed = 1 WHERE surgery_id = $surgery_id";
                $mysqli->query($update_surgery_sql);
                
                $_SESSION['alert_message'] = "Bill finalized successfully";
                $_SESSION['alert_type'] = "success";
                header("Location: surgery_billing.php?surgery_id=$surgery_id");
                exit;
            } else {
                $error = "Error finalizing bill: " . $mysqli->error;
            }
        }
    }
    elseif ($action == 'generate_invoice') {
        if ($existing_bill && $existing_bill['status'] == 'finalized') {
            // Generate invoice PDF (you'll implement this function)
            // generateInvoicePDF($existing_bill['bill_id']);
            
            // Mark as invoiced
            $update_sql = "UPDATE surgery_bills SET 
                          invoiced = 1,
                          invoice_date = NOW(),
                          invoice_number = CONCAT('INV-', LPAD({$existing_bill['bill_id']}, 6, '0'))
                          WHERE bill_id = {$existing_bill['bill_id']}";
            if ($mysqli->query($update_sql)) {
                $_SESSION['alert_message'] = "Invoice generated successfully";
                $_SESSION['alert_type'] = "success";
                header("Location: surgery_billing.php?surgery_id=$surgery_id");
                exit;
            }
        }
    }
}

// Calculate totals
$subtotal = 0;
$total_quantity = 0;
if ($existing_bill && $bill_items_result->num_rows > 0) {
    mysqli_data_seek($bill_items_result, 0);
    while ($item = $bill_items_result->fetch_assoc()) {
        $subtotal += $item['quantity'] * $item['unit_price'];
        $total_quantity += $item['quantity'];
    }
    mysqli_data_seek($bill_items_result, 0);
}


?>

<div class="card">
    <div class="card-header bg-success text-white py-2">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h3 class="card-title mt-2 mb-0">
                    <i class="fas fa-file-invoice-dollar mr-2"></i>
                    Surgery Billing
                </h3>
                <small class="text-light">
                    Surgery #<?php echo $surgery['surgery_number']; ?> - 
                    <?php echo $surgery['patient_first_name'] . ' ' . $surgery['patient_last_name']; ?>
                </small>
            </div>
            <div>
                <?php if($existing_bill && $existing_bill['status'] == 'finalized'): ?>
                    <span class="badge badge-light mr-2">
                        <i class="fas fa-check-circle"></i> Finalized
                    </span>
                <?php endif; ?>
                <a href="surgery_view.php?id=<?php echo $surgery_id; ?>" class="btn btn-light btn-sm">
                    <i class="fas fa-arrow-left mr-1"></i> Back to Surgery
                </a>
            </div>
        </div>
    </div>
    
    <!-- Statistics Cards -->
    <div class="card-body py-3 border-bottom">
        <div class="row">
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-success"><i class="fas fa-procedures"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Completed Surgeries</span>
                        <span class="info-box-number"><?php echo $stats['total_surgeries']; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-info"><i class="fas fa-file-invoice"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Billed Surgeries</span>
                        <span class="info-box-number"><?php echo $stats['billed_surgeries']; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-primary"><i class="fas fa-check-double"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Finalized Bills</span>
                        <span class="info-box-number"><?php echo $stats['finalized_bills']; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-warning"><i class="fas fa-money-bill-wave"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Revenue</span>
                        <span class="info-box-number">₹<?php echo number_format($stats['total_revenue'] ?? 0, 2); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Patient & Surgery Info -->
    <div class="card-body py-3 border-bottom">
        <div class="row">
            <div class="col-md-4">
                <h6><i class="fas fa-user-injured mr-2"></i>Patient Information</h6>
                <p class="mb-1"><strong>Name:</strong> <?php echo $surgery['patient_first_name'] . ' ' . $surgery['patient_last_name']; ?></p>
                <p class="mb-1"><strong>MRN:</strong> <?php echo $surgery['patient_mrn']; ?></p>
                <p class="mb-1"><strong>Phone:</strong> <?php echo $surgery['patient_phone']; ?></p>
                <p class="mb-1"><strong>Email:</strong> <?php echo $surgery['patient_email']; ?></p>
            </div>
            <div class="col-md-4">
                <h6><i class="fas fa-procedures mr-2"></i>Surgery Details</h6>
                <p class="mb-1"><strong>Procedure:</strong> <?php echo $surgery['procedure_name']; ?></p>
                <p class="mb-1"><strong>Surgeon:</strong> <?php echo $surgery['surgeon_name']; ?></p>
                <p class="mb-1"><strong>Theatre:</strong> <?php echo $surgery['theatre_number']; ?> - <?php echo $surgery['theatre_name']; ?></p>
                <p class="mb-1"><strong>Date:</strong> <?php echo date('M j, Y', strtotime($surgery['scheduled_date'])); ?></p>
            </div>
            <div class="col-md-4">
                <h6><i class="fas fa-shield-alt mr-2"></i>Insurance Information</h6>
                <?php if($surgery['insurance_name']): ?>
                    <p class="mb-1"><strong>Insurance:</strong> <?php echo $surgery['insurance_name']; ?></p>
                    <p class="mb-1"><strong>Policy #:</strong> <?php echo $surgery['insurance_policy_number']; ?></p>
                <?php else: ?>
                    <p class="mb-1 text-muted">No insurance information</p>
                <?php endif; ?>
                <p class="mb-1"><strong>Service Order:</strong> 
                    <?php echo $surgery['service_order_number'] ?: 'No service order'; ?>
                    <?php if($surgery['order_status']): ?>
                        <span class="badge badge-info"><?php echo $surgery['order_status']; ?></span>
                    <?php endif; ?>
                </p>
            </div>
        </div>
    </div>
    
    <!-- Bill Summary -->
    <div class="card-body py-3">
        <div class="row">
            <div class="col-md-8">
                <h5><i class="fas fa-shopping-cart mr-2"></i>Bill Items</h5>
            </div>
            <div class="col-md-4 text-right">
                <div class="bg-light p-3 rounded">
                    <div class="d-flex justify-content-between mb-2">
                        <span>Subtotal:</span>
                        <span class="font-weight-bold">₹<?php echo number_format($subtotal, 2); ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Items:</span>
                        <span><?php echo $total_quantity; ?> items</span>
                    </div>
                    <?php if($existing_bill && $existing_bill['status'] == 'finalized'): ?>
                        <div class="d-flex justify-content-between mb-2">
                            <span>Discount:</span>
                            <span class="text-danger">-₹<?php echo number_format($existing_bill['discount_amount'], 2); ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span>Tax (<?php echo $existing_bill['tax_rate']; ?>%):</span>
                            <span>₹<?php echo number_format($existing_bill['tax_amount'], 2); ?></span>
                        </div>
                        <hr>
                        <div class="d-flex justify-content-between">
                            <span class="font-weight-bold">Total:</span>
                            <span class="font-weight-bold text-success">₹<?php echo number_format($existing_bill['grand_total'], 2); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bill Items Table -->
    <div class="card-body">
        <?php if(isset($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle mr-2"></i><?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <?php if($existing_bill): ?>
        <form method="POST" action="" id="updateQuantitiesForm">
            <input type="hidden" name="action" value="update_quantities">
            <div class="table-responsive">
                <table class="table table-hover mb-4">
                    <thead class="bg-light">
                        <tr>
                            <th width="5%">#</th>
                            <th width="20%">Item Description</th>
                            <th width="10%">Code</th>
                            <th width="10%">Type</th>
                            <th width="10%">Quantity</th>
                            <th width="15%">Unit Price (₹)</th>
                            <th width="15%">Total (₹)</th>
                            <th width="15%">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($bill_items_result->num_rows > 0): ?>
                            <?php $counter = 1; while($item = $bill_items_result->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo $counter++; ?></td>
                                    <td>
                                        <strong><?php echo $item['item_name_display'] ?: $item['item_description']; ?></strong>
                                        <?php if($item['description']): ?>
                                            <small class="d-block text-muted"><?php echo $item['description']; ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $item['item_code_display']; ?></td>
                                    <td>
                                        <span class="badge badge-info">
                                            <?php echo ucfirst($item['item_type']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if($existing_bill['status'] != 'finalized'): ?>
                                            <input type="number" name="quantities[<?php echo $item['item_id']; ?>]" 
                                                   class="form-control form-control-sm" 
                                                   value="<?php echo $item['quantity']; ?>" min="1">
                                        <?php else: ?>
                                            <?php echo $item['quantity']; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>₹<?php echo number_format($item['unit_price'], 2); ?></td>
                                    <td class="font-weight-bold">
                                        ₹<?php echo number_format($item['quantity'] * $item['unit_price'], 2); ?>
                                    </td>
                                    <td>
                                        <?php if($existing_bill['status'] != 'finalized'): ?>
                                            <button type="button" class="btn btn-sm btn-danger" 
                                                    onclick="removeItem(<?php echo $item['item_id']; ?>)">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        <?php else: ?>
                                            <span class="text-muted">Finalized</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="text-center py-4">
                                    <i class="fas fa-cart-plus fa-2x text-muted mb-3"></i>
                                    <h5>No items in bill</h5>
                                    <p class="text-muted">Add items from inventory or medical services</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if($existing_bill['status'] != 'finalized' && $bill_items_result->num_rows > 0): ?>
            <div class="row">
                <div class="col-md-12 text-right">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-sync-alt mr-1"></i> Update Quantities
                    </button>
                </div>
            </div>
            <?php endif; ?>
        </form>
        <?php endif; ?>
    </div>
    
    <!-- Add Items Section -->
    <?php if(!$existing_bill || $existing_bill['status'] != 'finalized'): ?>
    <div class="card-body border-top">
        <h5><i class="fas fa-plus-circle mr-2"></i>Add Items to Bill</h5>
        
        <!-- Tabs for different item types -->
        <ul class="nav nav-tabs mb-4" id="itemTabs" role="tablist">
            <li class="nav-item">
                <a class="nav-link active" id="inventory-tab" data-toggle="tab" href="#inventory" role="tab">
                    <i class="fas fa-boxes mr-1"></i> Inventory Items
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="services-tab" data-toggle="tab" href="#services" role="tab">
                    <i class="fas fa-stethoscope mr-1"></i> Medical Services
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="custom-tab" data-toggle="tab" href="#custom" role="tab">
                    <i class="fas fa-edit mr-1"></i> Custom Items
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="usage-tab" data-toggle="tab" href="#usage" role="tab">
                    <i class="fas fa-history mr-1"></i> Inventory Usage
                </a>
            </li>
        </ul>
        
        <div class="tab-content" id="itemTabsContent">
            <!-- Inventory Items Tab -->
            <div class="tab-pane fade show active" id="inventory" role="tabpanel">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="add_item">
                    <input type="hidden" name="item_type" value="inventory">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Select Inventory Item</label>
                                <select class="form-control select2" name="item_id" id="inventoryItemSelect" required>
                                    <option value="">Select Item</option>
                                    <?php while($item = $inventory_items_result->fetch_assoc()): ?>
                                        <option value="<?php echo $item['item_id']; ?>" 
                                                data-price="<?php echo $item['item_unit_price']; ?>"
                                                data-stock="<?php echo $item['item_quantity']; ?>">
                                            <?php echo $item['item_name']; ?> 
                                            (<?php echo $item['item_code']; ?>)
                                            - Stock: <?php echo $item['item_quantity']; ?> 
                                            - ₹<?php echo number_format($item['item_unit_price'], 2); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-group">
                                <label>Quantity</label>
                                <input type="number" class="form-control" name="quantity" value="1" min="1" required>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-group">
                                <label>Unit Price (₹)</label>
                                <input type="number" class="form-control" name="unit_price" step="0.01" required 
                                       id="inventoryPriceInput">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-group">
                                <label>&nbsp;</label>
                                <button type="submit" class="btn btn-success btn-block">
                                    <i class="fas fa-plus mr-1"></i> Add
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label>Description / Notes (Optional)</label>
                                <input type="text" class="form-control" name="description" 
                                       placeholder="Additional notes about this item...">
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            
            <!-- Medical Services Tab -->
            <div class="tab-pane fade" id="services" role="tabpanel">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="add_item">
                    <input type="hidden" name="item_type" value="service">
                    
                    <div class="row">
                        <div class="col-md-8">
                            <div class="form-group">
                                <label>Select Medical Service</label>
                                <select class="form-control select2" name="item_id" id="serviceItemSelect" required>
                                    <option value="">Select Service</option>
                                    <?php while($service = $medical_services_result->fetch_assoc()): ?>
                                        <option value="<?php echo $service['medical_service_id']; ?>" 
                                                data-price="<?php echo $service['fee_amount']; ?>">
                                            <?php echo $service['service_name']; ?> 
                                            (<?php echo $service['service_code']; ?>)
                                            - ₹<?php echo number_format($service['fee_amount'], 2); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-group">
                                <label>Quantity</label>
                                <input type="number" class="form-control" name="quantity" value="1" min="1" required>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-group">
                                <label>&nbsp;</label>
                                <button type="submit" class="btn btn-success btn-block">
                                    <i class="fas fa-plus mr-1"></i> Add
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label>Description / Notes (Optional)</label>
                                <input type="text" class="form-control" name="description" 
                                       placeholder="Additional notes about this service...">
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            
            <!-- Custom Items Tab -->
            <div class="tab-pane fade" id="custom" role="tabpanel">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="add_item">
                    <input type="hidden" name="item_type" value="custom">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Item Description</label>
                                <input type="text" class="form-control" name="description" 
                                       placeholder="e.g., Additional surgeon fee, Special equipment, etc." required>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-group">
                                <label>Quantity</label>
                                <input type="number" class="form-control" name="quantity" value="1" min="1" required>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-group">
                                <label>Unit Price (₹)</label>
                                <input type="number" class="form-control" name="unit_price" step="0.01" required>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-group">
                                <label>&nbsp;</label>
                                <button type="submit" class="btn btn-success btn-block">
                                    <i class="fas fa-plus mr-1"></i> Add
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            
            <!-- Inventory Usage Tab -->
            <div class="tab-pane fade" id="usage" role="tabpanel">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Quantity Used</th>
                                <th>Default Price</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($inventory_usage_result->num_rows > 0): ?>
                                <?php while($usage = $inventory_usage_result->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo $usage['item_name']; ?></strong><br>
                                            <small class="text-muted"><?php echo $usage['item_code']; ?></small>
                                        </td>
                                        <td><?php echo $usage['quantity_used']; ?></td>
                                        <td>₹<?php echo number_format($usage['default_price'], 2); ?></td>
                                        <td>
                                            <span class="badge badge-warning">Not Billed</span>
                                        </td>
                                        <td>
                                            <form method="POST" action="" class="d-inline">
                                                <input type="hidden" name="action" value="add_item">
                                                <input type="hidden" name="item_type" value="inventory">
                                                <input type="hidden" name="item_id" value="<?php echo $usage['item_id']; ?>">
                                                <input type="hidden" name="quantity" value="<?php echo $usage['quantity_used']; ?>">
                                                <input type="hidden" name="unit_price" value="<?php echo $usage['default_price']; ?>">
                                                <button type="submit" class="btn btn-sm btn-success">
                                                    <i class="fas fa-plus mr-1"></i> Add to Bill
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center">
                                        <i class="fas fa-box-open fa-2x text-muted mb-2"></i><br>
                                        No unbilled inventory usage found
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Finalize Bill Section -->
    <?php if($existing_bill && $existing_bill['status'] != 'finalized' && $bill_items_result->num_rows > 0): ?>
    <div class="card-body border-top bg-light">
        <h5><i class="fas fa-file-signature mr-2"></i>Finalize Bill</h5>
        <form method="POST" action="" id="finalizeBillForm">
            <input type="hidden" name="action" value="finalize_bill">
            
            <div class="row">
                <div class="col-md-3">
                    <div class="form-group">
                        <label>Bill Date</label>
                        <input type="date" class="form-control" name="bill_date" 
                               value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label>Due Date</label>
                        <input type="date" class="form-control" name="due_date" 
                               value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>" required>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-group">
                        <label>Discount (%)</label>
                        <input type="number" class="form-control" name="discount_percent" 
                               value="0" min="0" max="100" step="0.01">
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-group">
                        <label>Tax Rate (%)</label>
                        <input type="number" class="form-control" name="tax_rate" 
                               value="18" min="0" max="100" step="0.01">
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-group">
                        <label>Payment Terms</label>
                        <select class="form-control" name="payment_terms">
                            <option value="Net 30">Net 30</option>
                            <option value="Net 15">Net 15</option>
                            <option value="Due on Receipt">Due on Receipt</option>
                            <option value="COD">COD</option>
                        </select>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-12">
                    <div class="form-group">
                        <label>Notes</label>
                        <textarea class="form-control" name="notes" rows="2" 
                                  placeholder="Any special notes or instructions..."></textarea>
                    </div>
                </div>
            </div>
            
            <!-- Preview Section -->
            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="bg-white p-3 rounded border">
                        <h6 class="mb-3">Bill Preview</h6>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Subtotal:</span>
                                    <span>₹<?php echo number_format($subtotal, 2); ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Discount (<span id="discountPreview">0</span>%):</span>
                                    <span class="text-danger" id="discountAmountPreview">₹0.00</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Tax (<span id="taxPreview">18</span>%):</span>
                                    <span id="taxAmountPreview">₹0.00</span>
                                </div>
                                <hr>
                                <div class="d-flex justify-content-between">
                                    <strong>Total:</strong>
                                    <strong class="text-success" id="totalPreview">₹<?php echo number_format($subtotal, 2); ?></strong>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="text-muted">
                                    <small>
                                        <i class="fas fa-info-circle mr-1"></i>
                                        This preview updates automatically as you change discount and tax rates.
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-12 text-right">
                    <button type="submit" class="btn btn-success btn-lg">
                        <i class="fas fa-file-signature mr-1"></i> Finalize Bill
                    </button>
                </div>
            </div>
        </form>
    </div>
    <?php endif; ?>
    
    <!-- Invoice Actions -->
    <?php if($existing_bill && $existing_bill['status'] == 'finalized'): ?>
    <div class="card-footer bg-light">
        <div class="row">
            <div class="col-md-6">
                <div class="bill-info">
                    <h6>Bill Information</h6>
                    <p class="mb-1"><strong>Bill Date:</strong> <?php echo date('M j, Y', strtotime($existing_bill['bill_date'])); ?></p>
                    <p class="mb-1"><strong>Due Date:</strong> <?php echo date('M j, Y', strtotime($existing_bill['due_date'])); ?></p>
                    <p class="mb-1"><strong>Payment Terms:</strong> <?php echo $existing_bill['payment_terms']; ?></p>
                    <?php if($existing_bill['invoice_number']): ?>
                        <p class="mb-1"><strong>Invoice #:</strong> <?php echo $existing_bill['invoice_number']; ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-md-6 text-right">
                <div class="btn-group">
                    <?php if(!$existing_bill['invoiced']): ?>
                        <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#generateInvoiceModal">
                            <i class="fas fa-file-pdf mr-1"></i> Generate Invoice
                        </button>
                    <?php else: ?>
                        <a href="invoices/<?php echo $existing_bill['invoice_number']; ?>.pdf" 
                           target="_blank" class="btn btn-success">
                            <i class="fas fa-file-pdf mr-1"></i> View Invoice
                        </a>
                    <?php endif; ?>
                    <a href="surgery_billing_print.php?bill_id=<?php echo $existing_bill['bill_id']; ?>" 
                       target="_blank" class="btn btn-secondary">
                        <i class="fas fa-print mr-1"></i> Print Bill
                    </a>
                    <button type="button" class="btn btn-info" data-toggle="modal" data-target="#paymentModal">
                        <i class="fas fa-credit-card mr-1"></i> Record Payment
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Generate Invoice Modal -->
<div class="modal fade" id="generateInvoiceModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="generate_invoice">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Generate Invoice</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p>This will generate a PDF invoice for the finalized bill.</p>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle mr-2"></i>
                        Invoice will be generated with number: <strong>INV-<?php echo str_pad($existing_bill['bill_id'], 6, '0', STR_PAD_LEFT); ?></strong>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Generate Invoice</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">Record Payment</h5>
                <button type="button" class="close text-white" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <p>Payment recording functionality would be implemented here.</p>
                <p>This would typically include:</p>
                <ul>
                    <li>Payment amount</li>
                    <li>Payment method (Cash, Card, Insurance, etc.)</li>
                    <li>Payment date</li>
                    <li>Reference number</li>
                    <li>Partial payment tracking</li>
                </ul>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                <button type="button" class="btn btn-info">Record Payment</button>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.select2').select2();
    
    // Auto-fill price when selecting inventory item
    $('#inventoryItemSelect').change(function() {
        var selectedOption = $(this).find(':selected');
        var price = selectedOption.data('price');
        var stock = selectedOption.data('stock');
        
        if (price) {
            $('#inventoryPriceInput').val(price);
        }
        
        // Show stock alert if quantity exceeds available stock
        var quantityInput = $('input[name="quantity"]');
        if (stock && parseInt(quantityInput.val()) > stock) {
            alert('Warning: Quantity exceeds available stock (' + stock + ')');
        }
    });
    
    // Auto-fill price when selecting service
    $('#serviceItemSelect').change(function() {
        var selectedOption = $(this).find(':selected');
        var price = selectedOption.data('price');
        
        if (price) {
            $(this).closest('form').find('input[name="unit_price"]').val(price);
        }
    });
    
    // Update bill preview in real-time
    function updateBillPreview() {
        var subtotal = <?php echo $subtotal; ?>;
        var discountPercent = parseFloat($('input[name="discount_percent"]').val()) || 0;
        var discountAmount = subtotal * (discountPercent / 100);
        var taxRate = parseFloat($('input[name="tax_rate"]').val()) || 0;
        var totalAfterDiscount = subtotal - discountAmount;
        var taxAmount = totalAfterDiscount * (taxRate / 100);
        var grandTotal = totalAfterDiscount + taxAmount;
        
        $('#discountPreview').text(discountPercent.toFixed(2));
        $('#discountAmountPreview').text('₹' + discountAmount.toFixed(2));
        $('#taxPreview').text(taxRate.toFixed(2));
        $('#taxAmountPreview').text('₹' + taxAmount.toFixed(2));
        $('#totalPreview').text('₹' + grandTotal.toFixed(2));
    }
    
    // Listen for changes on discount and tax inputs
    $('input[name="discount_percent"], input[name="tax_rate"]').on('input', updateBillPreview);
    
    // Initial preview update
    updateBillPreview();
    
    // Form validation for finalizing bill
    $('#finalizeBillForm').submit(function(e) {
        var dueDate = new Date($('input[name="due_date"]').val());
        var billDate = new Date($('input[name="bill_date"]').val());
        
        if (dueDate < billDate) {
            e.preventDefault();
            alert('Due date cannot be before bill date.');
            return false;
        }
        
        if (!confirm('Are you sure you want to finalize this bill? This action cannot be undone.')) {
            e.preventDefault();
            return false;
        }
    });
    
    // Remove item confirmation
    window.removeItem = function(itemId) {
        if (confirm('Are you sure you want to remove this item from the bill?')) {
            var form = document.createElement('form');
            form.method = 'POST';
            form.action = '';
            
            var actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'remove_item';
            form.appendChild(actionInput);
            
            var itemInput = document.createElement('input');
            itemInput.type = 'hidden';
            itemInput.name = 'item_id';
            itemInput.value = itemId;
            form.appendChild(itemInput);
            
            document.body.appendChild(form);
            form.submit();
        }
    };
    
    // Update quantities form submission
    $('#updateQuantitiesForm').submit(function(e) {
        var hasZeroQuantity = false;
        $('input[name^="quantities"]').each(function() {
            if (parseInt($(this).val()) < 1) {
                hasZeroQuantity = true;
                $(this).addClass('is-invalid');
            } else {
                $(this).removeClass('is-invalid');
            }
        });
        
        if (hasZeroQuantity) {
            e.preventDefault();
            alert('Quantity cannot be less than 1 for any item.');
            return false;
        }
        
        if (!confirm('Update all quantities?')) {
            e.preventDefault();
            return false;
        }
    });
    
    // Keyboard shortcuts
    $(document).keydown(function(e) {
        // Ctrl + S to save/update
        if (e.ctrlKey && e.keyCode === 83) {
            e.preventDefault();
            if ($('#updateQuantitiesForm').length) {
                $('#updateQuantitiesForm').submit();
            }
        }
        // Ctrl + F to finalize
        if (e.ctrlKey && e.keyCode === 70) {
            e.preventDefault();
            if ($('#finalizeBillForm').length) {
                $('#finalizeBillForm').submit();
            }
        }
    });
});
</script>

<style>
.info-box {
    transition: transform 0.2s ease-in-out;
    border: 1px solid #e3e6f0;
    min-height: 70px;
    margin-bottom: 0;
}
.info-box:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}
.info-box-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 50px;
    font-size: 1.5rem;
}
.info-box-content {
    padding: 5px 10px;
}
.nav-tabs .nav-link.active {
    background-color: #28a745;
    color: white;
    border-color: #28a745;
}
.bill-preview {
    background-color: #f8f9fa;
    border-radius: 5px;
    padding: 20px;
    border: 1px solid #dee2e6;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
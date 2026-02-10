<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Initialize variables
$suppliers = [];
$items = [];
$pre_selected_item_id = intval($_GET['item_id'] ?? 0);

// Get active suppliers
$suppliers_sql = "SELECT supplier_id, supplier_name, supplier_contact, supplier_phone, supplier_email 
                  FROM suppliers 
                  WHERE supplier_is_active = 1 
                  ORDER BY supplier_name";
$suppliers_result = $mysqli->query($suppliers_sql);
while ($supplier = $suppliers_result->fetch_assoc()) {
    $suppliers[] = $supplier;
}

// Get active inventory items
$items_sql = "SELECT item_id, item_name, item_code, item_unit_cost, item_unit_measure, 
                     item_quantity, item_low_stock_alert, item_status
              FROM inventory_items 
              WHERE item_status != 'Discontinued' 
              ORDER BY item_name";
$items_result = $mysqli->query($items_sql);
while ($item = $items_result->fetch_assoc()) {
    $items[] = $item;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    $supplier_id = intval($_POST['supplier_id']);
    $order_date = sanitizeInput($_POST['order_date']);
    $expected_delivery_date = sanitizeInput($_POST['expected_delivery_date']);
    $notes = sanitizeInput($_POST['notes']);
    
    // Get order items from POST data
    $order_items = [];
    if (isset($_POST['item_id']) && is_array($_POST['item_id'])) {
        foreach ($_POST['item_id'] as $index => $item_id) {
            if (!empty($item_id) && !empty($_POST['quantity_ordered'][$index])) {
                $order_items[] = [
                    'item_id' => intval($item_id),
                    'item_name' => sanitizeInput($_POST['item_name'][$index]),
                    'item_description' => sanitizeInput($_POST['item_description'][$index] ?? ''),
                    'quantity_ordered' => intval($_POST['quantity_ordered'][$index]),
                    'unit_cost' => floatval($_POST['unit_cost'][$index]),
                    'total_cost' => floatval($_POST['total_cost'][$index])
                ];
            }
        }
    }

    // Validate CSRF token
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        header("Location: purchase_order_create.php");
        exit;
    }

    // Validate required fields
    if (empty($supplier_id) || empty($order_date) || count($order_items) === 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please fill in all required fields and add at least one item.";
        header("Location: purchase_order_create.php");
        exit;
    }

    // Validate order items
    $total_amount = 0;
    foreach ($order_items as $item) {
        if ($item['quantity_ordered'] <= 0 || $item['unit_cost'] < 0) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Please check all item quantities and costs are valid.";
            header("Location: purchase_order_create.php");
            exit;
        }
        $total_amount += $item['total_cost'];
    }

    // Generate order number
    $order_number = "PO-" . date('Ymd-His');

    // Start transaction
    $mysqli->begin_transaction();

    try {
        // Insert purchase order
        $order_sql = "INSERT INTO purchase_orders (
            order_number, supplier_id, order_date, expected_delivery_date,
            total_amount, notes, created_by, order_status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 'draft')";
        
        $order_stmt = $mysqli->prepare($order_sql);
        $order_stmt->bind_param(
            "sissdsi",
            $order_number, $supplier_id, $order_date, $expected_delivery_date,
            $total_amount, $notes, $session_user_id
        );
        $order_stmt->execute();
        $order_id = $order_stmt->insert_id;
        $order_stmt->close();

        // Insert order items
        $item_sql = "INSERT INTO purchase_order_items (
            order_id, item_id, item_name, item_description, quantity_ordered,
            unit_cost, total_cost
        ) VALUES (?, ?, ?, ?, ?, ?, ?)";
        
        $item_stmt = $mysqli->prepare($item_sql);
        
        foreach ($order_items as $order_item) {
            $item_stmt->bind_param(
                "iissidd",
                $order_id, $order_item['item_id'], $order_item['item_name'], 
                $order_item['item_description'], $order_item['quantity_ordered'],
                $order_item['unit_cost'], $order_item['total_cost']
            );
            $item_stmt->execute();
        }
        $item_stmt->close();

        $mysqli->commit();

        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Purchase order <strong>$order_number</strong> created successfully!";
        header("Location: purchase_order_view.php?id=" . $order_id);
        exit;

    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error creating purchase order: " . $e->getMessage();
        header("Location: purchase_order_create.php");
        exit;
    }
}
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-shopping-cart mr-2"></i>Create Purchase Order
            </h3>
            <div class="card-tools">
                <a href="purchase_orders.php" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Purchase Orders
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

        <form method="POST" id="purchaseOrderForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <div class="row">
                <div class="col-md-8">
                    <!-- Order Information -->
                    <div class="card card-primary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-info-circle mr-2"></i>Order Information</h3>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="supplier_id">Supplier *</label>
                                        <select class="form-control select2" id="supplier_id" name="supplier_id" required>
                                            <option value="">- Select Supplier -</option>
                                            <?php foreach ($suppliers as $supplier): ?>
                                                <option value="<?php echo $supplier['supplier_id']; ?>">
                                                    <?php echo htmlspecialchars($supplier['supplier_name']); ?>
                                                    <?php if ($supplier['supplier_contact']): ?>
                                                        (Contact: <?php echo htmlspecialchars($supplier['supplier_contact']); ?>)
                                                    <?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="order_date">Order Date *</label>
                                        <input type="date" class="form-control" id="order_date" name="order_date" 
                                               value="<?php echo date('Y-m-d'); ?>" required>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="expected_delivery_date">Expected Delivery Date</label>
                                        <input type="date" class="form-control" id="expected_delivery_date" name="expected_delivery_date"
                                               min="<?php echo date('Y-m-d'); ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="notes">Order Notes</label>
                                <textarea class="form-control" id="notes" name="notes" rows="3" 
                                          placeholder="Special instructions, delivery requirements, etc..." 
                                          maxlength="500"></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Order Items -->
                    <div class="card card-warning">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h3 class="card-title"><i class="fas fa-list-ol mr-2"></i>Order Items</h3>
                            <button type="button" class="btn btn-sm btn-success" onclick="addOrderItem()">
                                <i class="fas fa-plus mr-1"></i>Add Item
                            </button>
                        </div>
                        <div class="card-body">
                            <div id="order_items_container">
                                <!-- Order items will be added here dynamically -->
                                <div class="text-center text-muted py-4" id="no_items_message">
                                    <i class="fas fa-cubes fa-2x mb-2"></i>
                                    <p>No items added yet. Click "Add Item" to start.</p>
                                </div>
                            </div>
                            
                            <!-- Order Summary -->
                            <div class="row mt-4">
                                <div class="col-md-6 offset-md-6">
                                    <table class="table table-sm table-borderless">
                                        <tr>
                                            <td class="text-right"><strong>Subtotal:</strong></td>
                                            <td class="text-right" width="120">$<span id="order_subtotal">0.00</span></td>
                                        </tr>
                                        <tr>
                                            <td class="text-right"><strong>Tax (0%):</strong></td>
                                            <td class="text-right">$<span id="order_tax">0.00</span></td>
                                        </tr>
                                        <tr class="border-top">
                                            <td class="text-right"><strong>Total Amount:</strong></td>
                                            <td class="text-right"><strong>$<span id="order_total">0.00</span></strong></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <!-- Quick Actions -->
                    <div class="card card-success">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-bolt mr-2"></i>Quick Actions</h3>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-success btn-lg">
                                    <i class="fas fa-save mr-2"></i>Create Order
                                </button>
                                <button type="button" class="btn btn-outline-info" onclick="saveDraft()">
                                    <i class="fas fa-file-alt mr-2"></i>Save as Draft
                                </button>
                                <button type="reset" class="btn btn-outline-secondary" onclick="resetForm()">
                                    <i class="fas fa-undo mr-2"></i>Reset Form
                                </button>
                                <a href="purchase_orders.php" class="btn btn-outline-danger">
                                    <i class="fas fa-times mr-2"></i>Cancel
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Supplier Information -->
                    <div class="card card-info">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-truck mr-2"></i>Supplier Information</h3>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-3">
                                <i class="fas fa-building fa-3x text-info mb-2"></i>
                                <h5 id="supplier_name">Select Supplier</h5>
                            </div>
                            <hr>
                            <div class="small" id="supplier_details">
                                <div class="text-muted text-center">Select a supplier to view details</div>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Add Items -->
                    <div class="card card-secondary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-bolt mr-2"></i>Quick Add Items</h3>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="addLowStockItems()">
                                    <i class="fas fa-exclamation-triangle mr-2"></i>Add Low Stock Items
                                </button>
                                <button type="button" class="btn btn-outline-warning btn-sm" onclick="addOutOfStockItems()">
                                    <i class="fas fa-times-circle mr-2"></i>Add Out of Stock Items
                                </button>
                                <button type="button" class="btn btn-outline-info btn-sm" onclick="addCommonItems()">
                                    <i class="fas fa-star mr-2"></i>Add Common Items
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Order Preview -->
                    <div class="card card-warning">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-eye mr-2"></i>Order Preview</h3>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-3">
                                <i class="fas fa-shopping-cart fa-3x text-warning mb-2"></i>
                                <h5 id="preview_order_number">New Order</h5>
                                <div class="text-muted" id="preview_item_count">0 items</div>
                            </div>
                            <hr>
                            <div class="small">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Supplier:</span>
                                    <span class="font-weight-bold" id="preview_supplier">-</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Order Date:</span>
                                    <span class="font-weight-bold" id="preview_order_date">-</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Delivery Date:</span>
                                    <span class="font-weight-bold" id="preview_delivery_date">-</span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Total Amount:</span>
                                    <span class="font-weight-bold text-success">$<span id="preview_total">0.00</span></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Item Selection Modal -->
<div class="modal fade" id="itemSelectionModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-cubes mr-2"></i>Select Inventory Item</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table class="table table-sm table-hover" id="itemsTable">
                        <thead class="bg-light">
                            <tr>
                                <th>Item Name</th>
                                <th>Code</th>
                                <th class="text-center">Current Stock</th>
                                <th class="text-center">Low Stock Alert</th>
                                <th class="text-center">Unit Cost</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($item['item_name']); ?></strong>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($item['item_unit_measure']); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($item['item_code']); ?></td>
                                    <td class="text-center">
                                        <span class="badge badge-<?php 
                                            echo $item['item_status'] == 'In Stock' ? 'success' : 
                                                 ($item['item_status'] == 'Low Stock' ? 'warning' : 'danger'); 
                                        ?>">
                                            <?php echo $item['item_quantity']; ?>
                                        </span>
                                    </td>
                                    <td class="text-center"><?php echo $item['item_low_stock_alert']; ?></td>
                                    <td class="text-center">$<?php echo number_format($item['item_unit_cost'], 2); ?></td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-sm btn-success select-item-btn" 
                                                data-item-id="<?php echo $item['item_id']; ?>"
                                                data-item-name="<?php echo htmlspecialchars($item['item_name']); ?>"
                                                data-unit-cost="<?php echo $item['item_unit_cost']; ?>"
                                                data-unit-measure="<?php echo htmlspecialchars($item['item_unit_measure']); ?>">
                                            <i class="fas fa-plus mr-1"></i>Add
                                        </button>
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

<script>
// Global variables
let itemCounter = 0;
const items = <?php echo json_encode($items); ?>;

// Make functions globally accessible
window.selectItem = function(itemId, itemName, unitCost, unitMeasure) {
    try {
        itemCounter++;
        
        const itemRow = `
            <div class="order-item-row border rounded p-3 mb-3" id="item_row_${itemCounter}">
                <div class="row">
                    <div class="col-md-5">
                        <div class="form-group">
                            <label>Item *</label>
                            <input type="hidden" name="item_id[]" value="${itemId}">
                            <input type="text" class="form-control" name="item_name[]" value="${escapeHtml(itemName)}" readonly>
                            <input type="hidden" name="item_description[]" value="${escapeHtml(itemName)} - ${escapeHtml(unitMeasure)}">
                            <small class="form-text text-muted">${escapeHtml(unitMeasure)}</small>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Quantity *</label>
                            <input type="number" class="form-control quantity" name="quantity_ordered[]" 
                                   min="1" value="1" required onchange="calculateItemTotal(${itemCounter})">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Unit Cost ($) *</label>
                            <input type="number" class="form-control unit-cost" name="unit_cost[]" 
                                   min="0" step="0.01" value="${unitCost}" required onchange="calculateItemTotal(${itemCounter})">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Total Cost ($)</label>
                            <input type="number" class="form-control total-cost" name="total_cost[]" 
                                   value="${unitCost}" readonly>
                        </div>
                    </div>
                    <div class="col-md-1">
                        <div class="form-group">
                            <label>&nbsp;</label>
                            <button type="button" class="btn btn-danger btn-block" onclick="removeOrderItem(${itemCounter})">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        $('#no_items_message').hide();
        $('#order_items_container').append(itemRow);
        $('#itemSelectionModal').modal('hide');
        
        calculateItemTotal(itemCounter);
        updatePreview();
    } catch (error) {
        console.error('Error adding item:', error);
        alert('Error adding item. Please try again.');
    }
}

window.removeOrderItem = function(itemId) {
    $('#item_row_' + itemId).remove();
    
    // Show no items message if no items left
    if ($('.order-item-row').length === 0) {
        $('#no_items_message').show();
    }
    
    updateOrderSummary();
    updatePreview();
}

window.calculateItemTotal = function(itemId) {
    const quantity = parseFloat($('#item_row_' + itemId + ' .quantity').val()) || 0;
    const unitCost = parseFloat($('#item_row_' + itemId + ' .unit-cost').val()) || 0;
    const totalCost = quantity * unitCost;
    
    $('#item_row_' + itemId + ' .total-cost').val(totalCost.toFixed(2));
    updateOrderSummary();
}

// Helper function to escape HTML
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

$(document).ready(function() {
    // Initialize Select2
    $('.select2').select2();

    // Initialize DataTable for items modal
    $('#itemsTable').DataTable({
        pageLength: 10,
        order: [[0, 'asc']],
        language: {
            search: "Search items:"
        },
        drawCallback: function() {
            // Re-attach event listeners after DataTable redraws
            attachModalEventListeners();
        }
    });

    // Attach event listeners to modal buttons
    function attachModalEventListeners() {
        $('.select-item-btn').off('click').on('click', function() {
            const itemId = $(this).data('item-id');
            const itemName = $(this).data('item-name');
            const unitCost = $(this).data('unit-cost');
            const unitMeasure = $(this).data('unit-measure');
            
            selectItem(itemId, itemName, unitCost, unitMeasure);
        });
    }

    // Initial attachment of event listeners
    attachModalEventListeners();

    // Update supplier details when selected
    $('#supplier_id').on('change', function() {
        const supplierId = $(this).val();
        const selectedOption = $(this).find('option:selected');
        
        if (supplierId) {
            $('#supplier_name').text(selectedOption.text().split(' (')[0]);
            $('#preview_supplier').text(selectedOption.text().split(' (')[0]);
            
            // In a real application, you'd fetch supplier details via AJAX
            $('#supplier_details').html(`
                <div class="text-center">
                    <i class="fas fa-info-circle text-muted"></i>
                    <p class="mb-0">Supplier details would be loaded here</p>
                </div>
            `);
        } else {
            $('#supplier_name').text('Select Supplier');
            $('#preview_supplier').text('-');
            $('#supplier_details').html('<div class="text-muted text-center">Select a supplier to view details</div>');
        }
    });

    // Update order date preview
    $('#order_date').on('change', function() {
        $('#preview_order_date').text($(this).val());
    });

    $('#expected_delivery_date').on('change', function() {
        $('#preview_delivery_date').text($(this).val() || 'Not set');
    });

    // Auto-set delivery date to 7 days from order date
    $('#order_date').on('change', function() {
        if ($(this).val() && !$('#expected_delivery_date').val()) {
            const orderDate = new Date($(this).val());
            orderDate.setDate(orderDate.getDate() + 7);
            const deliveryDate = orderDate.toISOString().split('T')[0];
            $('#expected_delivery_date').val(deliveryDate);
            $('#preview_delivery_date').text(deliveryDate);
        }
    });

    // Initialize order date
    $('#order_date').trigger('change');

    // If pre-selected item exists, add it automatically
    <?php if ($pre_selected_item_id > 0): ?>
        const preSelectedItem = items.find(item => item.item_id == <?php echo $pre_selected_item_id; ?>);
        if (preSelectedItem) {
            selectItem(
                preSelectedItem.item_id,
                preSelectedItem.item_name,
                preSelectedItem.item_unit_cost,
                preSelectedItem.item_unit_measure
            );
        }
    <?php endif; ?>
});

// Add new order item row
function addOrderItem() {
    $('#itemSelectionModal').modal('show');
}

// Update order summary
function updateOrderSummary() {
    let subtotal = 0;
    
    $('.total-cost').each(function() {
        subtotal += parseFloat($(this).val()) || 0;
    });
    
    const tax = 0; // You can add tax calculation logic here
    const total = subtotal + tax;
    
    $('#order_subtotal').text(subtotal.toFixed(2));
    $('#order_tax').text(tax.toFixed(2));
    $('#order_total').text(total.toFixed(2));
    $('#preview_total').text(total.toFixed(2));
}

// Update preview
function updatePreview() {
    const itemCount = $('.order-item-row').length;
    $('#preview_item_count').text(itemCount + ' item' + (itemCount !== 1 ? 's' : ''));
}

// Quick add functions
function addLowStockItems() {
    const lowStockItems = <?php echo json_encode(array_filter($items, function($item) { 
        return $item['item_status'] === 'Low Stock'; 
    })); ?>;
    
    lowStockItems.forEach(item => {
        // Check if item already exists in order
        if (!$(`input[name="item_id[]"][value="${item.item_id}"]`).length) {
            selectItem(
                item.item_id,
                item.item_name,
                item.item_unit_cost,
                item.item_unit_measure
            );
        }
    });
}

function addOutOfStockItems() {
    const outOfStockItems = <?php echo json_encode(array_filter($items, function($item) { 
        return $item['item_status'] === 'Out of Stock'; 
    })); ?>;
    
    outOfStockItems.forEach(item => {
        if (!$(`input[name="item_id[]"][value="${item.item_id}"]`).length) {
            selectItem(
                item.item_id,
                item.item_name,
                item.item_unit_cost,
                item.item_unit_measure
            );
        }
    });
}

function addCommonItems() {
    // Add first 5 items as "common items" - in real app, you might have a usage-based algorithm
    const commonItems = <?php echo json_encode(array_slice($items, 0, 5)); ?>;
    
    commonItems.forEach(item => {
        if (!$(`input[name="item_id[]"][value="${item.item_id}"]`).length) {
            selectItem(
                item.item_id,
                item.item_name,
                item.item_unit_cost,
                item.item_unit_measure
            );
        }
    });
}

// Form actions
function saveDraft() {
    // In a real application, you might have a separate draft status
    alert('Draft functionality would be implemented here');
    $('#purchaseOrderForm').submit();
}

function resetForm() {
    if (confirm('Are you sure you want to reset the entire form? All items will be removed.')) {
        $('#purchaseOrderForm').trigger('reset');
        $('#order_items_container').empty();
        $('#no_items_message').show();
        updateOrderSummary();
        updatePreview();
        $('.select2').trigger('change');
    }
}

// Form validation
$('#purchaseOrderForm').on('submit', function(e) {
    const supplierId = $('#supplier_id').val();
    const orderDate = $('#order_date').val();
    const itemCount = $('.order-item-row').length;
    
    let isValid = true;
    
    // Validate required fields
    if (!supplierId || !orderDate || itemCount === 0) {
        isValid = false;
    }
    
    // Validate all items have valid quantities and costs
    $('.quantity').each(function() {
        if ($(this).val() <= 0) {
            isValid = false;
            $(this).addClass('is-invalid');
        } else {
            $(this).removeClass('is-invalid');
        }
    });
    
    $('.unit-cost').each(function() {
        if ($(this).val() < 0) {
            isValid = false;
            $(this).addClass('is-invalid');
        } else {
            $(this).removeClass('is-invalid');
        }
    });
    
    if (!isValid) {
        e.preventDefault();
        alert('Please fill in all required fields and ensure all items have valid quantities and costs.');
        return false;
    }
    
    // Show loading state
    $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Creating...').prop('disabled', true);
});

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + S to save
    if (e.ctrlKey && e.keyCode === 83) {
        e.preventDefault();
        $('#purchaseOrderForm').submit();
    }
    // Escape to cancel
    if (e.keyCode === 27) {
        window.location.href = 'purchase_orders.php';
    }
    // Ctrl + I to add item
    if (e.ctrlKey && e.keyCode === 73) {
        e.preventDefault();
        addOrderItem();
    }
});
</script>

<style>
.order-item-row {
    background-color: #f8f9fa;
    border-left: 4px solid #007bff !important;
}

.order-item-row:hover {
    background-color: #e9ecef;
}

#itemsTable_wrapper {
    margin: 0;
}

.select-item-btn {
    cursor: pointer;
}
</style>

<?php 
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
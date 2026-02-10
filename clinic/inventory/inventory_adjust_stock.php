<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Get item ID from URL
$item_id = intval($_GET['item_id'] ?? 0);

if ($item_id <= 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid item ID.";
    header("Location: inventory_dashboard.php");
    exit;
}

// Get item details
$item_sql = "SELECT i.*, 
                    c.category_name, c.category_type,
                    s.supplier_name,
                    u.user_name as added_by_name
             FROM inventory_items i
             LEFT JOIN inventory_categories c ON i.item_category_id = c.category_id
             LEFT JOIN suppliers s ON i.item_supplier_id = s.supplier_id
             LEFT JOIN users u ON i.item_added_by = u.user_id
             WHERE i.item_id = ?";
$item_stmt = $mysqli->prepare($item_sql);
$item_stmt->bind_param("i", $item_id);
$item_stmt->execute();
$item_result = $item_stmt->get_result();

if ($item_result->num_rows === 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Item not found.";
    header("Location: inventory_dashboard.php");
    exit;
}

$item = $item_result->fetch_assoc();
$item_stmt->close();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    $adjustment_type = sanitizeInput($_POST['adjustment_type']);
    $quantity_change = intval($_POST['quantity_change']);
    $adjustment_reason = sanitizeInput($_POST['adjustment_reason']);
    $notes = sanitizeInput($_POST['notes']);

    // Validate CSRF token
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        header("Location: inventory_adjust_stock.php?item_id=" . $item_id);
        exit;
    }

    // Validate required fields
    if (empty($adjustment_type) || empty($adjustment_reason) || $quantity_change == 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please fill in all required fields with valid values.";
        header("Location: inventory_adjust_stock.php?item_id=" . $item_id);
        exit;
    }

    // Calculate new quantity
    $previous_quantity = $item['item_quantity'];
    $new_quantity = $previous_quantity;
    
    if ($adjustment_type === 'increase') {
        $new_quantity = $previous_quantity + $quantity_change;
    } elseif ($adjustment_type === 'decrease') {
        $new_quantity = $previous_quantity - $quantity_change;
        if ($new_quantity < 0) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Cannot decrease stock below 0. Current stock: " . $previous_quantity;
            header("Location: inventory_adjust_stock.php?item_id=" . $item_id);
            exit;
        }
    } elseif ($adjustment_type === 'set') {
        $new_quantity = $quantity_change;
        $quantity_change = $new_quantity - $previous_quantity;
    }

    // Determine new status
    $new_status = 'In Stock';
    if ($new_quantity <= 0) {
        $new_status = 'Out of Stock';
    } elseif ($new_quantity <= $item['item_low_stock_alert']) {
        $new_status = 'Low Stock';
    }

    // Start transaction
    $mysqli->begin_transaction();

    try {
        // Update inventory item
        $update_sql = "UPDATE inventory_items SET 
                      item_quantity = ?, 
                      item_status = ?,
                      last_restocked_date = CASE WHEN ? = 'increase' THEN NOW() ELSE last_restocked_date END
                      WHERE item_id = ?";
        $update_stmt = $mysqli->prepare($update_sql);
        $update_stmt->bind_param("issi", $new_quantity, $new_status, $adjustment_type, $item_id);
        $update_stmt->execute();

        // Record transaction
        $transaction_sql = "INSERT INTO inventory_transactions (
            item_id, transaction_type, quantity_change, previous_quantity, new_quantity,
            transaction_reference, performed_by, transaction_notes
        ) VALUES (?, 'adjustment', ?, ?, ?, 'STOCK_ADJUST', ?, ?)";
        
        $transaction_notes = "Stock adjustment: " . $adjustment_reason;
        if (!empty($notes)) {
            $transaction_notes .= " - " . $notes;
        }
        
        $trans_stmt = $mysqli->prepare($transaction_sql);
        $trans_stmt->bind_param(
            "iiiiss",
            $item_id, $quantity_change, $previous_quantity, $new_quantity, $session_user_id, $transaction_notes
        );
        $trans_stmt->execute();
        $trans_stmt->close();

        // Record stock adjustment
        $adjustment_sql = "INSERT INTO stock_adjustments (
            item_id, adjustment_type, previous_quantity, new_quantity, quantity_difference,
            adjustment_reason, adjusted_by, notes
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        $adjustment_type_db = $adjustment_type === 'increase' ? 'correction' : 
                             ($adjustment_type === 'decrease' ? 'correction' : 'correction');
        
        $adjust_stmt = $mysqli->prepare($adjustment_sql);
        $adjust_stmt->bind_param(
            "isiiissi",
            $item_id, $adjustment_type_db, $previous_quantity, $new_quantity, $quantity_change,
            $adjustment_reason, $session_user_id, $notes
        );
        $adjust_stmt->execute();
        $adjust_stmt->close();

        $mysqli->commit();

        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Stock adjusted successfully! Quantity updated from $previous_quantity to $new_quantity.";
        header("Location: inventory_item_details.php?item_id=" . $item_id);
        exit;

    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error adjusting stock: " . $e->getMessage();
        header("Location: inventory_adjust_stock.php?item_id=" . $item_id);
        exit;
    }
}
?>

<div class="card">
    <div class="card-header bg-warning py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-adjust mr-2"></i>Adjust Stock
            </h3>
            <div class="card-tools">
                <a href="inventory_stock.php" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Stocks
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

        <form method="POST" id="adjustmentForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <div class="row">
                <div class="col-md-8">
                    <!-- Stock Adjustment Information -->
                    <div class="card card-primary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-exchange-alt mr-2"></i>Stock Adjustment</h3>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="adjustment_type">Adjustment Type *</label>
                                        <select class="form-control" id="adjustment_type" name="adjustment_type" required>
                                            <option value="">- Select Adjustment Type -</option>
                                            <option value="increase">Increase Stock</option>
                                            <option value="decrease">Decrease Stock</option>
                                            <option value="set">Set Exact Quantity</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="quantity_change">Quantity *</label>
                                        <input type="number" class="form-control" id="quantity_change" name="quantity_change" 
                                               min="1" value="1" required>
                                        <small class="form-text text-muted" id="quantity_help">
                                            Enter the quantity to adjust
                                        </small>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="adjustment_reason">Reason for Adjustment *</label>
                                <select class="form-control" id="adjustment_reason" name="adjustment_reason" required>
                                    <option value="">- Select Reason -</option>
                                    <option value="physical_count">Physical Count Discrepancy</option>
                                    <option value="damaged">Damaged Goods</option>
                                    <option value="expired">Expired Items</option>
                                    <option value="theft">Theft/Loss</option>
                                    <option value="donation">Donation</option>
                                    <option value="sample">Sample/Testing</option>
                                    <option value="return">Customer Return</option>
                                    <option value="vendor_return">Vendor Return</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="notes">Additional Notes</label>
                                <textarea class="form-control" id="notes" name="notes" rows="3" 
                                          placeholder="Additional details about this adjustment..." 
                                          maxlength="500"></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Preview Changes -->
                    <div class="card card-info">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-calculator mr-2"></i>Adjustment Preview</h3>
                        </div>
                        <div class="card-body">
                            <div class="row text-center">
                                <div class="col-md-4">
                                    <div class="border rounded p-3 bg-light">
                                        <div class="h5 text-muted">Current Stock</div>
                                        <div class="h2 font-weight-bold text-primary" id="preview_current">
                                            <?php echo $item['item_quantity']; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="border rounded p-3 bg-light">
                                        <div class="h5 text-muted">Adjustment</div>
                                        <div class="h2 font-weight-bold text-warning" id="preview_change">
                                            +0
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="border rounded p-3 bg-light">
                                        <div class="h5 text-muted">New Stock</div>
                                        <div class="h2 font-weight-bold text-success" id="preview_new">
                                            <?php echo $item['item_quantity']; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mt-3 text-center">
                                <div id="preview_status" class="badge badge-lg p-2">
                                    Current Status: <span class="badge badge-<?php 
                                        echo $item['item_status'] == 'In Stock' ? 'success' : 
                                             ($item['item_status'] == 'Low Stock' ? 'warning' : 
                                             ($item['item_status'] == 'Out of Stock' ? 'danger' : 'secondary')); 
                                    ?>"><?php echo $item['item_status']; ?></span>
                                </div>
                                <div id="preview_new_status" class="badge badge-lg p-2 ml-2 d-none">
                                    New Status: <span class="badge" id="preview_status_badge">-</span>
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
                                    <i class="fas fa-check mr-2"></i>Apply Adjustment
                                </button>
                                <button type="reset" class="btn btn-outline-secondary" onclick="resetForm()">
                                    <i class="fas fa-undo mr-2"></i>Reset Form
                                </button>
                                <a href="inventory_item_details.php?item_id=<?php echo $item_id; ?>" class="btn btn-outline-danger">
                                    <i class="fas fa-times mr-2"></i>Cancel
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Item Information -->
                    <div class="card card-info">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-cube mr-2"></i>Item Information</h3>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-3">
                                <i class="fas fa-cube fa-3x text-info mb-2"></i>
                                <h5><?php echo htmlspecialchars($item['item_name']); ?></h5>
                                <div class="text-muted"><?php echo htmlspecialchars($item['item_code']); ?></div>
                            </div>
                            <hr>
                            <div class="small">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Current Stock:</span>
                                    <span class="font-weight-bold"><?php echo $item['item_quantity']; ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Low Stock Alert:</span>
                                    <span class="font-weight-bold"><?php echo $item['item_low_stock_alert']; ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Unit Measure:</span>
                                    <span class="font-weight-bold"><?php echo htmlspecialchars(ucfirst($item['item_unit_measure'])); ?></span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Category:</span>
                                    <span class="font-weight-bold">
                                        <?php echo $item['category_name'] ? htmlspecialchars($item['category_name']) : 'Not assigned'; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Common Adjustments -->
                    <div class="card card-secondary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-bolt mr-2"></i>Quick Adjustments</h3>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="setQuickAdjustment('increase', 10)">
                                    <i class="fas fa-plus mr-2"></i>Add 10 Units
                                </button>
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="setQuickAdjustment('increase', 25)">
                                    <i class="fas fa-plus mr-2"></i>Add 25 Units
                                </button>
                                <button type="button" class="btn btn-outline-warning btn-sm" onclick="setQuickAdjustment('decrease', 5)">
                                    <i class="fas fa-minus mr-2"></i>Remove 5 Units
                                </button>
                                <button type="button" class="btn btn-outline-warning btn-sm" onclick="setQuickAdjustment('decrease', 10)">
                                    <i class="fas fa-minus mr-2"></i>Remove 10 Units
                                </button>
                                <button type="button" class="btn btn-outline-info btn-sm" onclick="setExactQuantity(0)">
                                    <i class="fas fa-ban mr-2"></i>Set to Zero
                                </button>
                                <button type="button" class="btn btn-outline-info btn-sm" onclick="setExactQuantity(<?php echo $item['item_low_stock_alert']; ?>)">
                                    <i class="fas fa-exclamation mr-2"></i>Set to Low Stock Level
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Adjustment History -->
                    <div class="card card-warning">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-history mr-2"></i>Recent Adjustments</h3>
                        </div>
                        <div class="card-body">
                            <?php
                            $recent_sql = "SELECT sa.adjustment_type, sa.previous_quantity, sa.new_quantity, 
                                                  sa.quantity_difference, sa.adjustment_date, sa.adjustment_reason,
                                                  u.user_name as adjusted_by
                                           FROM stock_adjustments sa
                                           LEFT JOIN users u ON sa.adjusted_by = u.user_id
                                           WHERE sa.item_id = ?
                                           ORDER BY sa.adjustment_date DESC 
                                           LIMIT 5";
                            $recent_stmt = $mysqli->prepare($recent_sql);
                            $recent_stmt->bind_param("i", $item_id);
                            $recent_stmt->execute();
                            $recent_result = $recent_stmt->get_result();

                            if ($recent_result->num_rows > 0): ?>
                                <div class="list-group list-group-flush">
                                    <?php while ($adjustment = $recent_result->fetch_assoc()): ?>
                                        <div class="list-group-item px-0 py-2">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1">
                                                    <?php 
                                                    $diff_class = $adjustment['quantity_difference'] > 0 ? 'text-success' : 'text-danger';
                                                    $diff_sign = $adjustment['quantity_difference'] > 0 ? '+' : '';
                                                    ?>
                                                    <span class="<?php echo $diff_class; ?>">
                                                        <?php echo $diff_sign . $adjustment['quantity_difference']; ?>
                                                    </span>
                                                </h6>
                                                <small><?php echo timeAgo($adjustment['adjustment_date']); ?></small>
                                            </div>
                                            <p class="mb-1 small text-muted">
                                                <?php echo htmlspecialchars($adjustment['adjustment_reason']); ?>
                                            </p>
                                            <small class="text-muted">
                                                From <?php echo $adjustment['previous_quantity']; ?> to <?php echo $adjustment['new_quantity']; ?>
                                            </small>
                                        </div>
                                    <?php endwhile; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted mb-0 text-center">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    No previous adjustments
                                </p>
                            <?php endif; ?>
                            <?php $recent_stmt->close(); ?>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
$(document).ready(function() {
    const currentStock = <?php echo $item['item_quantity']; ?>;
    const lowStockAlert = <?php echo $item['item_low_stock_alert']; ?>;
    
    // Update preview based on form changes
    function updatePreview() {
        const adjustmentType = $('#adjustment_type').val();
        const quantityChange = parseInt($('#quantity_change').val()) || 0;
        
        let newQuantity = currentStock;
        let displayChange = quantityChange;
        
        if (adjustmentType === 'increase') {
            newQuantity = currentStock + quantityChange;
            displayChange = '+' + quantityChange;
        } else if (adjustmentType === 'decrease') {
            newQuantity = currentStock - quantityChange;
            displayChange = '-' + quantityChange;
        } else if (adjustmentType === 'set') {
            newQuantity = quantityChange;
            displayChange = (quantityChange - currentStock) > 0 ? 
                           '+' + (quantityChange - currentStock) : 
                           (quantityChange - currentStock).toString();
        }
        
        // Update preview numbers
        $('#preview_change').text(displayChange);
        $('#preview_new').text(newQuantity);
        
        // Update status
        let newStatus = 'In Stock';
        let statusClass = 'success';
        
        if (newQuantity <= 0) {
            newStatus = 'Out of Stock';
            statusClass = 'danger';
        } else if (newQuantity <= lowStockAlert) {
            newStatus = 'Low Stock';
            statusClass = 'warning';
        }
        
        $('#preview_status_badge').text(newStatus).removeClass('badge-success badge-warning badge-danger').addClass('badge-' + statusClass);
        $('#preview_new_status').removeClass('d-none');
        
        // Update quantity help text
        let helpText = 'Enter the quantity to adjust';
        if (adjustmentType === 'set') {
            helpText = 'Enter the exact quantity to set';
        }
        $('#quantity_help').text(helpText);
    }
    
    // Event listeners
    $('#adjustment_type, #quantity_change').on('change input', updatePreview);
    
    // Initial preview update
    updatePreview();
    
    // Form validation
    $('#adjustmentForm').on('submit', function(e) {
        const adjustmentType = $('#adjustment_type').val();
        const quantityChange = parseInt($('#quantity_change'].val()) || 0;
        const reason = $('#adjustment_reason').val();
        
        let isValid = true;
        
        // Validate required fields
        if (!adjustmentType || !reason || quantityChange <= 0) {
            isValid = false;
        }
        
        // Validate decrease won't go below zero
        if (adjustmentType === 'decrease' && (currentStock - quantityChange) < 0) {
            isValid = false;
            alert('Cannot decrease stock below 0. Current stock: ' + currentStock);
        }
        
        if (!isValid) {
            e.preventDefault();
            alert('Please fill in all required fields with valid values.');
            return false;
        }
        
        // Show loading state
        $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Applying...').prop('disabled', true);
    });
});

// Quick adjustment functions
function setQuickAdjustment(type, quantity) {
    $('#adjustment_type').val(type).trigger('change');
    $('#quantity_change').val(quantity).trigger('input');
    $('#adjustment_reason').val('physical_count');
}

function setExactQuantity(quantity) {
    $('#adjustment_type').val('set').trigger('change');
    $('#quantity_change').val(quantity).trigger('input');
    $('#adjustment_reason').val('physical_count');
}

function resetForm() {
    if (confirm('Are you sure you want to reset all changes?')) {
        $('#adjustment_type').val('').trigger('change');
        $('#quantity_change').val(1).trigger('input');
        $('#adjustment_reason').val('');
        $('#notes').val('');
    }
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + S to save
    if (e.ctrlKey && e.keyCode === 83) {
        e.preventDefault();
        $('#adjustmentForm').submit();
    }
    // Escape to cancel
    if (e.keyCode === 27) {
        window.location.href = 'inventory_item_details.php?item_id=<?php echo $item_id; ?>';
    }
});
</script>

  <?php 
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
    ?>
    
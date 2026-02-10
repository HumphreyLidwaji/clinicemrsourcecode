<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

$batch_id = intval($_GET['id'] ?? 0);

if ($batch_id <= 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid batch ID";
    header("Location: inventory_items.php");
    exit;
}

// Get batch details
$batch_sql = "SELECT 
                ib.*,
                i.item_id,
                i.item_name,
                i.item_code,
                i.unit_of_measure,
                i.is_drug,
                i.requires_batch,
                c.category_name,
                s.supplier_name
            FROM inventory_batches ib
            INNER JOIN inventory_items i ON ib.item_id = i.item_id
            LEFT JOIN inventory_categories c ON i.category_id = c.category_id
            LEFT JOIN suppliers s ON ib.supplier_id = s.supplier_id
            WHERE ib.batch_id = ? AND ib.is_active = 1 AND i.is_active = 1";
            
$batch_stmt = $mysqli->prepare($batch_sql);
$batch_stmt->bind_param("i", $batch_id);
$batch_stmt->execute();
$batch_result = $batch_stmt->get_result();
$batch = $batch_result->fetch_assoc();
$batch_stmt->close();

if (!$batch) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Batch not found or has been deleted";
    header("Location: inventory_items.php");
    exit;
}

// Get active suppliers
$suppliers_sql = "SELECT supplier_id, supplier_name, supplier_contact, supplier_phone, supplier_email 
                  FROM suppliers 
                  WHERE supplier_is_active = 1 
                  ORDER BY supplier_name";
$suppliers_result = $mysqli->query($suppliers_sql);
$suppliers = [];
while ($supplier = $suppliers_result->fetch_assoc()) {
    $suppliers[] = $supplier;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    $batch_number = sanitizeInput($_POST['batch_number']);
    $expiry_date = sanitizeInput($_POST['expiry_date']);
    $manufacturer = sanitizeInput($_POST['manufacturer']);
    $supplier_id = intval($_POST['supplier_id']);
    $received_date = sanitizeInput($_POST['received_date']);
    $notes = sanitizeInput($_POST['notes']);
    
    // Validate CSRF token
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token";
        header("Location: inventory_batch_edit.php?id=" . $batch_id);
        exit;
    }
    
    // Validate required fields
    if (empty($batch_number) || empty($expiry_date) || empty($received_date)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please fill in all required fields";
        header("Location: inventory_batch_edit.php?id=" . $batch_id);
        exit;
    }
    
    // Check if batch number already exists (excluding current batch)
    $check_sql = "SELECT batch_id FROM inventory_batches WHERE batch_number = ? AND item_id = ? AND batch_id != ? AND is_active = 1";
    $check_stmt = $mysqli->prepare($check_sql);
    $check_stmt->bind_param("sii", $batch_number, $batch['item_id'], $batch_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Batch number already exists for this item";
        header("Location: inventory_batch_edit.php?id=" . $batch_id);
        exit;
    }
    $check_stmt->close();
    
    // Validate dates
    $expiry_timestamp = strtotime($expiry_date);
    $received_timestamp = strtotime($received_date);
    
    if ($expiry_timestamp < $received_timestamp) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Expiry date cannot be before received date";
        header("Location: inventory_batch_edit.php?id=" . $batch_id);
        exit;
    }
    
    if ($expiry_timestamp < time()) {
        // Warn but allow expired batches
        $warning = true;
    }
    
    // Update batch
    $update_sql = "UPDATE inventory_batches 
                   SET batch_number = ?, 
                       expiry_date = ?, 
                       manufacturer = ?, 
                       supplier_id = ?, 
                       received_date = ?, 
                       notes = ?, 
                       updated_by = ?, 
                       updated_at = NOW()
                   WHERE batch_id = ?";
    
    $update_stmt = $mysqli->prepare($update_sql);
    $update_stmt->bind_param(
        "sssissii",
        $batch_number,
        $expiry_date,
        $manufacturer,
        $supplier_id,
        $received_date,
        $notes,
        $session_user_id,
        $batch_id
    );
    
    if ($update_stmt->execute()) {
        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Batch updated successfully";
        
        // Log the update
        $log_sql = "INSERT INTO inventory_transactions 
                    (transaction_type, item_id, batch_id, quantity, unit_cost, reason, created_by)
                    VALUES ('ADJUSTMENT', ?, ?, 0, 0, 'Batch details updated', ?)";
        $log_stmt = $mysqli->prepare($log_sql);
        $log_stmt->bind_param("iii", $batch['item_id'], $batch_id, $session_user_id);
        $log_stmt->execute();
        $log_stmt->close();
        
        header("Location: inventory_batch_view.php?id=" . $batch_id);
        exit;
    } else {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error updating batch: " . $mysqli->error;
        header("Location: inventory_batch_edit.php?id=" . $batch_id);
        exit;
    }
    
    $update_stmt->close();
}
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h3 class="card-title mt-2 mb-0">
                    <i class="fas fa-fw fa-edit mr-2"></i>Edit Batch: <?php echo htmlspecialchars($batch['batch_number']); ?>
                </h3>
                <small class="text-white-50">Item: <?php echo htmlspecialchars($batch['item_name']); ?></small>
            </div>
            <div class="card-tools">
                <a href="inventory_batch_view.php?id=<?php echo $batch_id; ?>" class="btn btn-light mr-2">
                    <i class="fas fa-eye mr-2"></i>View Batch
                </a>
                <a href="inventory_batches.php?item_id=<?php echo $batch['item_id']; ?>" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Batches
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

        <!-- Item Summary -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="info-box bg-gradient-info">
                    <span class="info-box-icon"><i class="fas fa-cube"></i></span>
                    <div class="info-box-content">
                        <div class="row">
                            <div class="col-md-4">
                                <span class="info-box-text">Item Name</span>
                                <span class="info-box-number"><?php echo htmlspecialchars($batch['item_name']); ?></span>
                            </div>
                            <div class="col-md-4">
                                <span class="info-box-text">Item Code</span>
                                <span class="info-box-number"><?php echo htmlspecialchars($batch['item_code']); ?></span>
                            </div>
                            <div class="col-md-4">
                                <span class="info-box-text">Category</span>
                                <span class="info-box-number"><?php echo htmlspecialchars($batch['category_name']); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <form method="POST" id="editBatchForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <div class="row">
                <!-- Left Column: Batch Details -->
                <div class="col-md-6">
                    <div class="card card-primary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-info-circle mr-2"></i>Batch Details</h3>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label for="batch_number">Batch Number *</label>
                                <input type="text" class="form-control" id="batch_number" name="batch_number" 
                                       value="<?php echo htmlspecialchars($batch['batch_number']); ?>" required
                                       pattern="[A-Za-z0-9\-_\.]+" title="Alphanumeric characters, dashes, underscores, and dots only">
                                <small class="form-text text-muted">Unique identifier for this batch</small>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="expiry_date">Expiry Date *</label>
                                        <input type="date" class="form-control" id="expiry_date" name="expiry_date" 
                                               value="<?php echo htmlspecialchars($batch['expiry_date']); ?>" required>
                                        <small class="form-text text-muted">When this batch expires</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="received_date">Received Date *</label>
                                        <input type="date" class="form-control" id="received_date" name="received_date" 
                                               value="<?php echo htmlspecialchars($batch['received_date']); ?>" required>
                                        <small class="form-text text-muted">When this batch was received</small>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="manufacturer">Manufacturer</label>
                                <input type="text" class="form-control" id="manufacturer" name="manufacturer" 
                                       value="<?php echo htmlspecialchars($batch['manufacturer'] ?? ''); ?>"
                                       maxlength="100">
                                <small class="form-text text-muted">Original manufacturer of the product</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="supplier_id">Supplier</label>
                                <select class="form-control select2" id="supplier_id" name="supplier_id">
                                    <option value="">- Select Supplier -</option>
                                    <?php foreach ($suppliers as $supplier): ?>
                                        <option value="<?php echo $supplier['supplier_id']; ?>" 
                                            <?php echo ($batch['supplier_id'] == $supplier['supplier_id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($supplier['supplier_name']); ?>
                                            <?php if ($supplier['supplier_contact']): ?>
                                                (Contact: <?php echo htmlspecialchars($supplier['supplier_contact']); ?>)
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="form-text text-muted">Supplier who provided this batch</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="notes">Notes</label>
                                <textarea class="form-control" id="notes" name="notes" rows="4" 
                                          placeholder="Additional information about this batch..."
                                          maxlength="500"><?php echo htmlspecialchars($batch['notes'] ?? ''); ?></textarea>
                                <small class="form-text text-muted">Optional notes about this batch</small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Audit Information -->
                    <div class="card card-secondary mt-3">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-history mr-2"></i>Audit Information</h3>
                        </div>
                        <div class="card-body">
                            <dl class="row">
                                <dt class="col-sm-4">Created:</dt>
                                <dd class="col-sm-8"><?php echo date('F j, Y, g:i a', strtotime($batch['created_at'])); ?></dd>
                                
                                <dt class="col-sm-4">Last Updated:</dt>
                                <dd class="col-sm-8"><?php echo date('F j, Y, g:i a', strtotime($batch['updated_at'])); ?></dd>
                                
                                <dt class="col-sm-4">Created By:</dt>
                                <dd class="col-sm-8"><?php echo htmlspecialchars($batch['created_by'] ? 'User ID: ' . $batch['created_by'] : 'System'); ?></dd>
                                
                                <dt class="col-sm-4">Updated By:</dt>
                                <dd class="col-sm-8"><?php echo htmlspecialchars($batch['updated_by'] ? 'User ID: ' . $batch['updated_by'] : 'Not updated yet'); ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
                
                <!-- Right Column: Validation & Actions -->
                <div class="col-md-6">
                    <!-- Validation Warnings -->
                    <div class="card card-warning">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-exclamation-triangle mr-2"></i>Validation Warnings</h3>
                        </div>
                        <div class="card-body">
                            <div id="validationMessages">
                                <div class="text-center text-muted">
                                    <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                                    <p>Fill in the form to see validation messages</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Quick Actions -->
                    <div class="card card-success mt-3">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-bolt mr-2"></i>Quick Actions</h3>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-success btn-lg">
                                    <i class="fas fa-save mr-2"></i>Update Batch
                                </button>
                                <button type="button" class="btn btn-outline-info" onclick="saveAndView()">
                                    <i class="fas fa-save mr-2"></i>Update & View
                                </button>
                                <button type="reset" class="btn btn-outline-secondary" onclick="resetForm()">
                                    <i class="fas fa-undo mr-2"></i>Reset Form
                                </button>
                                <a href="inventory_batch_view.php?id=<?php echo $batch_id; ?>" class="btn btn-outline-danger">
                                    <i class="fas fa-times mr-2"></i>Cancel
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Batch Preview -->
                    <div class="card card-info mt-3">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-eye mr-2"></i>Batch Preview</h3>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-3">
                                <i class="fas fa-box fa-3x text-info mb-2"></i>
                                <h5 id="preview_batch_number"><?php echo htmlspecialchars($batch['batch_number']); ?></h5>
                                <div class="text-muted" id="preview_item_name"><?php echo htmlspecialchars($batch['item_name']); ?></div>
                            </div>
                            <hr>
                            <div class="small">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Expiry Date:</span>
                                    <span class="font-weight-bold" id="preview_expiry_date"><?php echo date('M d, Y', strtotime($batch['expiry_date'])); ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Received Date:</span>
                                    <span class="font-weight-bold" id="preview_received_date"><?php echo date('M d, Y', strtotime($batch['received_date'])); ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Manufacturer:</span>
                                    <span class="font-weight-bold" id="preview_manufacturer"><?php echo htmlspecialchars($batch['manufacturer'] ?? 'Not specified'); ?></span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Supplier:</span>
                                    <span class="font-weight-bold" id="preview_supplier"><?php echo htmlspecialchars($batch['supplier_name'] ?? 'Not specified'); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Expiry Status -->
                    <div class="card card-danger mt-3">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-calendar-times mr-2"></i>Expiry Status</h3>
                        </div>
                        <div class="card-body">
                            <?php
                            $expiry_date = strtotime($batch['expiry_date']);
                            $today = time();
                            $days_remaining = floor(($expiry_date - $today) / (60 * 60 * 24));
                            
                            if ($days_remaining < 0): ?>
                                <div class="text-center">
                                    <i class="fas fa-exclamation-circle fa-2x text-danger mb-2"></i>
                                    <h5 class="text-danger">EXPIRED</h5>
                                    <p class="mb-1">Batch expired <?php echo abs($days_remaining); ?> days ago</p>
                                    <small class="text-muted">Consider removing from inventory</small>
                                </div>
                            <?php elseif ($days_remaining <= 30): ?>
                                <div class="text-center">
                                    <i class="fas fa-exclamation-triangle fa-2x text-warning mb-2"></i>
                                    <h5 class="text-warning">NEAR EXPIRY</h5>
                                    <p class="mb-1"><?php echo $days_remaining; ?> days remaining</p>
                                    <small class="text-muted">Monitor closely or use first</small>
                                </div>
                            <?php else: ?>
                                <div class="text-center">
                                    <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                                    <h5 class="text-success">GOOD</h5>
                                    <p class="mb-1"><?php echo $days_remaining; ?> days remaining</p>
                                    <small class="text-muted">Batch is within shelf life</small>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize Select2
    $('.select2').select2();
    
    // Set minimum dates
    const today = new Date().toISOString().split('T')[0];
    $('#expiry_date').attr('min', today);
    $('#received_date').attr('max', today);
    
    // Update preview on form changes
    $('#batch_number').on('input', function() {
        $('#preview_batch_number').text($(this).val() || 'Not set');
    });
    
    $('#expiry_date').on('change', function() {
        if ($(this).val()) {
            const date = new Date($(this).val());
            $('#preview_expiry_date').text(date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }));
            validateDates();
        }
    });
    
    $('#received_date').on('change', function() {
        if ($(this).val()) {
            const date = new Date($(this).val());
            $('#preview_received_date').text(date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }));
            validateDates();
        }
    });
    
    $('#manufacturer').on('input', function() {
        $('#preview_manufacturer').text($(this).val() || 'Not specified');
    });
    
    $('#supplier_id').on('change', function() {
        const selectedText = $(this).find('option:selected').text();
        const supplierName = selectedText.split(' (')[0];
        $('#preview_supplier').text(supplierName || 'Not specified');
    });
    
    $('#notes').on('input', function() {
        // Character counter
        const maxLength = 500;
        const currentLength = $(this).val().length;
        if (currentLength > maxLength * 0.8) {
            $(this).next('.form-text').addClass('text-warning');
        } else {
            $(this).next('.form-text').removeClass('text-warning');
        }
    });
    
    // Initial preview update
    $('#batch_number').trigger('input');
    $('#expiry_date').trigger('change');
    $('#received_date').trigger('change');
    $('#manufacturer').trigger('input');
    $('#supplier_id').trigger('change');
});

function validateDates() {
    const expiryDate = $('#expiry_date').val();
    const receivedDate = $('#received_date').val();
    const validationDiv = $('#validationMessages');
    
    if (!expiryDate || !receivedDate) {
        validationDiv.html(`
            <div class="text-center text-muted">
                <i class="fas fa-info-circle fa-2x text-info mb-2"></i>
                <p>Complete date fields to see validation</p>
            </div>
        `);
        return;
    }
    
    const expiry = new Date(expiryDate);
    const received = new Date(receivedDate);
    const today = new Date();
    const messages = [];
    
    // Check if expiry is before received
    if (expiry < received) {
        messages.push({
            type: 'error',
            icon: 'exclamation-triangle',
            message: 'Expiry date cannot be before received date'
        });
    }
    
    // Check if batch is expired
    if (expiry < today) {
        messages.push({
            type: 'danger',
            icon: 'calendar-times',
            message: 'Batch is expired. Consider removing from inventory.'
        });
    }
    
    // Check if batch is near expiry (within 30 days)
    const daysToExpiry = Math.floor((expiry - today) / (1000 * 60 * 60 * 24));
    if (daysToExpiry >= 0 && daysToExpiry <= 30) {
        messages.push({
            type: 'warning',
            icon: 'exclamation-triangle',
            message: `Batch expires in ${daysToExpiry} days. Monitor closely.`
        });
    }
    
    // Check if received date is in the future
    if (received > today) {
        messages.push({
            type: 'warning',
            icon: 'calendar-plus',
            message: 'Received date is in the future'
        });
    }
    
    // Display messages
    if (messages.length === 0) {
        validationDiv.html(`
            <div class="text-center text-success">
                <i class="fas fa-check-circle fa-2x mb-2"></i>
                <p class="mb-1">All dates are valid</p>
                <small class="text-muted">Batch is within acceptable ranges</small>
            </div>
        `);
    } else {
        let html = '';
        messages.forEach(msg => {
            html += `
                <div class="alert alert-${msg.type} alert-dismissible py-2 mb-2">
                    <i class="icon fas fa-${msg.icon} mr-2"></i>
                    ${msg.message}
                </div>
            `;
        });
        validationDiv.html(html);
    }
}

function saveAndView() {
    // Submit form and redirect to view page
    const form = $('#editBatchForm');
    form.append('<input type="hidden" name="redirect_to_view" value="1">');
    form.submit();
}

function resetForm() {
    if (confirm('Are you sure you want to reset the form? All changes will be lost.')) {
        $('#editBatchForm')[0].reset();
        $('.select2').trigger('change');
        
        // Reset preview to original values
        $('#preview_batch_number').text('<?php echo htmlspecialchars($batch['batch_number']); ?>');
        $('#preview_expiry_date').text('<?php echo date('M d, Y', strtotime($batch['expiry_date'])); ?>');
        $('#preview_received_date').text('<?php echo date('M d, Y', strtotime($batch['received_date'])); ?>');
        $('#preview_manufacturer').text('<?php echo htmlspecialchars($batch['manufacturer'] ?? 'Not specified'); ?>');
        $('#preview_supplier').text('<?php echo htmlspecialchars($batch['supplier_name'] ?? 'Not specified'); ?>');
        
        // Reset validation
        $('#validationMessages').html(`
            <div class="text-center text-muted">
                <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                <p>Fill in the form to see validation messages</p>
            </div>
        `);
    }
}

// Form validation
$('#editBatchForm').on('submit', function(e) {
    let isValid = true;
    
    // Clear previous error states
    $('.form-control').removeClass('is-invalid');
    $('.invalid-feedback').remove();
    
    // Validate batch number
    const batchNumber = $('#batch_number').val();
    if (!batchNumber || !/^[A-Za-z0-9\-_\.]+$/.test(batchNumber)) {
        $('#batch_number').addClass('is-invalid');
        $('#batch_number').after('<div class="invalid-feedback">Please enter a valid batch number (alphanumeric, dashes, underscores, dots only)</div>');
        isValid = false;
    }
    
    // Validate expiry date
    const expiryDate = $('#expiry_date').val();
    if (!expiryDate) {
        $('#expiry_date').addClass('is-invalid');
        $('#expiry_date').after('<div class="invalid-feedback">Expiry date is required</div>');
        isValid = false;
    }
    
    // Validate received date
    const receivedDate = $('#received_date').val();
    if (!receivedDate) {
        $('#received_date').addClass('is-invalid');
        $('#received_date').after('<div class="invalid-feedback">Received date is required</div>');
        isValid = false;
    }
    
    // Validate date logic
    if (expiryDate && receivedDate) {
        const expiry = new Date(expiryDate);
        const received = new Date(receivedDate);
        
        if (expiry < received) {
            $('#expiry_date').addClass('is-invalid');
            $('#expiry_date').after('<div class="invalid-feedback">Expiry date cannot be before received date</div>');
            $('#received_date').addClass('is-invalid');
            isValid = false;
        }
    }
    
    if (!isValid) {
        e.preventDefault();
        // Scroll to first error
        $('html, body').animate({
            scrollTop: $('.is-invalid').first().offset().top - 100
        }, 500);
        return false;
    }
    
    // Show loading state
    $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Updating...').prop('disabled', true);
});

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + S to save
    if (e.ctrlKey && e.keyCode === 83) {
        e.preventDefault();
        $('#editBatchForm').submit();
    }
    // Escape to cancel
    if (e.keyCode === 27) {
        window.location.href = 'inventory_batch_view.php?id=<?php echo $batch_id; ?>';
    }
});
</script>

<style>
.info-box {
    box-shadow: 0 0 1px rgba(0,0,0,.125), 0 1px 3px rgba(0,0,0,.2);
    border-radius: .25rem;
    background: #fff;
    display: flex;
    margin-bottom: 1rem;
    min-height: 80px;
    padding: .5rem;
    position: relative;
}

.info-box .info-box-icon {
    border-radius: .25rem;
    display: flex;
    font-size: 1.875rem;
    justify-content: center;
    text-align: center;
    width: 70px;
    align-items: center;
}

.info-box .info-box-content {
    display: flex;
    flex-direction: column;
    justify-content: center;
    line-height: 1.8;
    flex: 1;
    padding: 0 10px;
}

.info-box .info-box-text {
    display: block;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    text-transform: uppercase;
    font-weight: 700;
    font-size: .875rem;
}

.info-box .info-box-number {
    font-weight: 700;
    font-size: 1.5rem;
}

.alert-dismissible {
    margin-bottom: 0.5rem !important;
}
</style>

<?php 
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
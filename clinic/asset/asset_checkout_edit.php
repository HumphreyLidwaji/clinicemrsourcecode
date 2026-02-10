<?php
// asset_checkout_edit.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Check if ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid checkout ID";
    header("Location: asset_checkout.php");
    exit;
}

$checkout_id = intval($_GET['id']);

// Get checkout details for editing
$checkout_sql = "
    SELECT c.*, a.asset_id, a.asset_tag, a.asset_name, a.serial_number,
           CONCAT(e.first_name, ' ', e.last_name) as employee_name
    FROM asset_checkout_logs c
    JOIN assets a ON c.asset_id = a.asset_id
    LEFT JOIN employees e ON c.assigned_to_id = e.employee_id
    WHERE c.checkout_id = ? AND c.checkin_date IS NULL
";

$checkout_stmt = $mysqli->prepare($checkout_sql);
$checkout_stmt->bind_param("i", $checkout_id);
$checkout_stmt->execute();
$checkout_result = $checkout_stmt->get_result();

if ($checkout_result->num_rows === 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Checkout record not found or already checked in";
    header("Location: asset_checkout.php");
    exit;
}

$checkout = $checkout_result->fetch_assoc();

// Get employees for assignment
$employees_sql = "SELECT employee_id, CONCAT(first_name, ' ', last_name) as employee_name FROM employees  
                  ORDER BY first_name, last_name";
$employees_result = $mysqli->query($employees_sql);
$employees = [];
while ($employee = $employees_result->fetch_assoc()) {
    $employees[] = $employee;
}

// Get locations
$locations_sql = "SELECT location_id, location_name, location_type FROM asset_locations 
                 WHERE is_active = 1 ORDER BY location_type, location_name";
$locations_result = $mysqli->query($locations_sql);
$locations = [];
while ($location = $locations_result->fetch_assoc()) {
    $locations[] = $location;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    
    if ($csrf_token != $_SESSION['csrf_token']) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "CSRF token validation failed";
        header("Location: asset_checkout_edit.php?id=$checkout_id");
        exit;
    }
    
    $checkout_type = sanitizeInput($_POST['checkout_type']);
    $assigned_to_id = intval($_POST['assigned_to_id']);
    $checkout_date = sanitizeInput($_POST['checkout_date']);
    $expected_return_date = !empty($_POST['expected_return_date']) ? sanitizeInput($_POST['expected_return_date']) : null;
    $checkout_notes = sanitizeInput($_POST['checkout_notes']);
    $destination_location_id = !empty($_POST['destination_location_id']) ? intval($_POST['destination_location_id']) : null;
    
    // Validate required fields
    if (empty($assigned_to_id) || empty($checkout_date)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please fill in all required fields";
        header("Location: asset_checkout_edit.php?id=$checkout_id");
        exit;
    }
    
    // Validate date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkout_date)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid checkout date format. Use YYYY-MM-DD";
        header("Location: asset_checkout_edit.php?id=$checkout_id");
        exit;
    }
    
    if ($expected_return_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expected_return_date)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid return date format. Use YYYY-MM-DD";
        header("Location: asset_checkout_edit.php?id=$checkout_id");
        exit;
    }
    
    // Validate dates
    $checkout_timestamp = strtotime($checkout_date);
    if ($checkout_timestamp === false) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid checkout date";
        header("Location: asset_checkout_edit.php?id=$checkout_id");
        exit;
    }
    
    if ($expected_return_date) {
        $return_timestamp = strtotime($expected_return_date);
        if ($return_timestamp === false) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Invalid return date";
            header("Location: asset_checkout_edit.php?id=$checkout_id");
            exit;
        }
        
        if ($return_timestamp < $checkout_timestamp) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Return date cannot be before checkout date";
            header("Location: asset_checkout_edit.php?id=$checkout_id");
            exit;
        }
    }
    
    // Update checkout record
    $update_sql = "
        UPDATE asset_checkout_logs 
        SET checkout_type = ?, 
            assigned_to_id = ?, 
            checkout_date = ?, 
            expected_return_date = ?, 
            checkout_notes = ?, 
            destination_location_id = ?,
            updated_at = NOW()
        WHERE checkout_id = ?
    ";
    
    $update_stmt = $mysqli->prepare($update_sql);
    $update_stmt->bind_param(
        "sissisii",
        $checkout_type,
        $assigned_to_id,
        $checkout_date,
        $expected_return_date,
        $checkout_notes,
        $destination_location_id,
        $checkout_id
    );
    
    if ($update_stmt->execute()) {
        // Log activity
        $asset_info = $mysqli->query("SELECT asset_tag, asset_name FROM assets WHERE asset_id = {$checkout['asset_id']}")->fetch_assoc();
        $emp_info = $mysqli->query("SELECT CONCAT(first_name, ' ', last_name) as employee_name FROM employees WHERE employee_id = $assigned_to_id")->fetch_assoc();
        
        $log_description = "Checkout updated: {$asset_info['asset_tag']} - {$asset_info['asset_name']} to {$emp_info['employee_name']}";
        mysqli_query($mysqli, "INSERT INTO logs SET log_type = 'Asset', log_action = 'Update', log_description = '$log_description', log_ip = '$session_ip', log_user_agent = '$session_user_agent', log_user_id = $session_user_id");
        
        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Checkout updated successfully!";
        header("Location: asset_checkout_view.php?id=$checkout_id");
        exit;
    } else {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error updating checkout: " . $mysqli->error;
        header("Location: asset_checkout_edit.php?id=$checkout_id");
        exit;
    }
}
?>

<div class="card">
    <div class="card-header bg-warning py-2">
        <h3 class="card-title mt-2 mb-0 text-white"><i class="fas fa-fw fa-edit mr-2"></i>Edit Checkout</h3>
        <div class="card-tools">
            <a href="asset_checkout_view.php?id=<?php echo $checkout_id; ?>" class="btn btn-light">
                <i class="fas fa-arrow-left mr-2"></i>Back to View
            </a>
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

        <!-- Asset Information -->
        <div class="card card-info mb-4">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-cube mr-2"></i>Asset Information</h3>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <dl class="row">
                            <dt class="col-sm-5">Asset Tag:</dt>
                            <dd class="col-sm-7"><?php echo htmlspecialchars($checkout['asset_tag']); ?></dd>
                        </dl>
                    </div>
                    <div class="col-md-4">
                        <dl class="row">
                            <dt class="col-sm-5">Asset Name:</dt>
                            <dd class="col-sm-7"><?php echo htmlspecialchars($checkout['asset_name']); ?></dd>
                        </dl>
                    </div>
                    <div class="col-md-4">
                        <dl class="row">
                            <dt class="col-sm-5">Serial Number:</dt>
                            <dd class="col-sm-7"><?php echo htmlspecialchars($checkout['serial_number'] ?: 'N/A'); ?></dd>
                        </dl>
                    </div>
                </div>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    <strong>Note:</strong> You cannot change the asset once it has been checked out. To assign a different asset, please check in this asset and create a new checkout.
                </div>
            </div>
        </div>

        <form method="POST" id="editForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <div class="row">
                <div class="col-md-8">
                    <!-- Assignment Details -->
                    <div class="card card-success">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-user-check mr-2"></i>Assignment Details</h3>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="checkout_type">Checkout Type *</label>
                                        <select class="form-control" id="checkout_type" name="checkout_type" required>
                                            <option value="temporary" <?php echo $checkout['checkout_type'] == 'temporary' ? 'selected' : ''; ?>>Temporary (Short-term)</option>
                                            <option value="long_term" <?php echo $checkout['checkout_type'] == 'long_term' ? 'selected' : ''; ?>>Long-term Assignment</option>
                                            <option value="project" <?php echo $checkout['checkout_type'] == 'project' ? 'selected' : ''; ?>>Project-based</option>
                                            <option value="training" <?php echo $checkout['checkout_type'] == 'training' ? 'selected' : ''; ?>>Training/Development</option>
                                            <option value="replacement" <?php echo $checkout['checkout_type'] == 'replacement' ? 'selected' : ''; ?>>Replacement Equipment</option>
                                        </select>
                                        <small class="form-text text-muted">Purpose of this checkout</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="assigned_to_id">Assign to Employee *</label>
                                        <select class="form-control select2" id="assigned_to_id" name="assigned_to_id" required>
                                            <option value="">- Select Employee -</option>
                                            <?php foreach ($employees as $employee): ?>
                                                <option value="<?php echo $employee['employee_id']; ?>" <?php echo $employee['employee_id'] == $checkout['assigned_to_id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($employee['employee_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="form-text text-muted">Who will receive this asset?</small>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="destination_location_id">Destination Location</label>
                                        <select class="form-control select2" id="destination_location_id" name="destination_location_id">
                                            <option value="">- Select Location -</option>
                                            <?php foreach ($locations as $location): ?>
                                                <option value="<?php echo $location['location_id']; ?>" <?php echo $location['location_id'] == $checkout['destination_location_id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($location['location_type'] . ' - ' . $location['location_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="form-text text-muted">Where the asset will be used (optional)</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Checkout Details -->
                    <div class="card card-info">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-calendar-alt mr-2"></i>Checkout Details</h3>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="checkout_date">Checkout Date *</label>
                                        <input type="date" class="form-control" id="checkout_date" name="checkout_date" 
                                               value="<?php echo htmlspecialchars($checkout['checkout_date']); ?>" required>
                                        <small class="form-text text-muted">When the asset was checked out</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="expected_return_date">Expected Return Date</label>
                                        <input type="date" class="form-control" id="expected_return_date" 
                                               name="expected_return_date" value="<?php echo htmlspecialchars($checkout['expected_return_date'] ?: ''); ?>">
                                        <small class="form-text text-muted">When the asset is expected to be returned (optional)</small>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="checkout_notes">Checkout Notes</label>
                                <textarea class="form-control" id="checkout_notes" name="checkout_notes" rows="3" 
                                          placeholder="Purpose of checkout, special instructions, conditions, etc..." maxlength="500"><?php echo htmlspecialchars($checkout['checkout_notes'] ?: ''); ?></textarea>
                                <small class="form-text text-muted">Provide details about this checkout (optional)</small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <!-- Quick Actions -->
                    <div class="card card-primary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-bolt mr-2"></i>Quick Actions</h3>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-warning btn-lg">
                                    <i class="fas fa-save mr-2"></i>Update Checkout
                                </button>
                                <a href="asset_checkout_view.php?id=<?php echo $checkout_id; ?>" class="btn btn-outline-secondary">
                                    <i class="fas fa-times mr-2"></i>Cancel
                                </a>
                                <a href="asset_checkin.php?id=<?php echo $checkout_id; ?>" class="btn btn-success">
                                    <i class="fas fa-sign-in-alt mr-2"></i>Check In Instead
                                </a>
                            </div>
                            
                            <hr>
                            
                            <div class="alert alert-info">
                                <h6><i class="fas fa-info-circle mr-2"></i>Editing Information</h6>
                                <small>
                                    <div class="mb-2">
                                        <strong>Original Assignment:</strong><br>
                                        <?php echo htmlspecialchars($checkout['employee_name']); ?>
                                    </div>
                                    <div class="mb-2">
                                        <strong>Original Checkout Date:</strong><br>
                                        <?php echo date('M d, Y', strtotime($checkout['checkout_date'])); ?>
                                    </div>
                                    <div>
                                        <strong>Checkout ID:</strong><br>
                                        #<?php echo $checkout_id; ?>
                                    </div>
                                </small>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Tips -->
                    <div class="card card-warning">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-lightbulb mr-2"></i>Editing Tips</h3>
                        </div>
                        <div class="card-body">
                            <div class="callout callout-info small mb-3">
                                <i class="fas fa-info-circle mr-2"></i>
                                <strong>Asset Locked:</strong> The assigned asset cannot be changed. Check in and create a new checkout for different assets.
                            </div>
                            <div class="callout callout-warning small mb-3">
                                <i class="fas fa-exclamation-triangle mr-2"></i>
                                <strong>Date Validation:</strong> Return date cannot be before checkout date.
                            </div>
                            <div class="callout callout-success small">
                                <i class="fas fa-check-circle mr-2"></i>
                                <strong>Audit Trail:</strong> All changes are logged for accountability.
                            </div>
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

    // Form validation
    $('#editForm').on('submit', function(e) {
        var requiredFields = ['checkout_type', 'assigned_to_id', 'checkout_date'];
        var isValid = true;
        var errorMessages = [];
        
        requiredFields.forEach(function(field) {
            var value = $('#' + field).val();
            var fieldName = $('label[for="' + field + '"]').text().replace('*', '').trim();
            
            if (!value) {
                isValid = false;
                errorMessages.push(fieldName + ' is required');
                $('#' + field).addClass('is-invalid');
            } else {
                $('#' + field).removeClass('is-invalid');
            }
        });

        // Validate dates
        var checkoutDate = new Date($('#checkout_date').val());
        var returnDate = $('#expected_return_date').val() ? new Date($('#expected_return_date').val()) : null;
        
        if (returnDate && returnDate < checkoutDate) {
            isValid = false;
            errorMessages.push('Return date cannot be before checkout date');
            $('#expected_return_date').addClass('is-invalid');
        }

        if (!isValid) {
            e.preventDefault();
            var errorMessage = 'Please fix the following errors:\n• ' + errorMessages.join('\n• ');
            alert(errorMessage);
            return false;
        }

        // Show loading state
        $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Saving...').prop('disabled', true);
    });

    // Keyboard shortcuts
    $(document).keydown(function(e) {
        // Ctrl + S to save
        if (e.ctrlKey && e.keyCode === 83) {
            e.preventDefault();
            $('#editForm').submit();
        }
        // Escape to cancel
        if (e.keyCode === 27) {
            window.location.href = 'asset_checkout_view.php?id=<?php echo $checkout_id; ?>';
        }
    });
});
</script>

<style>
.callout {
    border-left: 3px solid #eee;
    margin-bottom: 10px;
    padding: 10px 15px;
    border-radius: 0.25rem;
}

.callout-info {
    border-left-color: #17a2b8;
    background-color: #f8f9fa;
}

.callout-warning {
    border-left-color: #ffc107;
    background-color: #fffbf0;
}

.callout-success {
    border-left-color: #28a745;
    background-color: #f0fff4;
}

.is-invalid {
    border-color: #dc3545;
    box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25);
}

.btn:disabled {
    cursor: not-allowed;
    opacity: 0.6;
}

dl.row dt {
    font-weight: 600;
    color: #6c757d;
}

dl.row dd {
    color: #212529;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
<?php
// laundry_edit.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

$page_title = "Edit Laundry Item";

// Get item ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: laundry_management.php");
    exit();
}

$laundry_id = intval($_GET['id']);

// Get current item details
$sql = "SELECT li.*, a.asset_name, a.asset_tag, a.asset_description, a.serial_number,
               lc.category_name, 
               ac.category_name as asset_category,
               al.location_name as asset_location
        FROM laundry_items li 
        LEFT JOIN assets a ON li.asset_id = a.asset_id 
        LEFT JOIN laundry_categories lc ON li.category_id = lc.category_id
        LEFT JOIN asset_categories ac ON a.category_id = ac.category_id
        LEFT JOIN asset_locations al ON a.location_id = al.location_id
        WHERE li.laundry_id = ?";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $laundry_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    $_SESSION['alert_message'] = "Laundry item not found";
    $_SESSION['alert_type'] = "danger";
    header("Location: laundry_management.php");
    exit();
}

$item = $result->fetch_assoc();

// Get categories
$categories_sql = "SELECT * FROM laundry_categories ORDER BY category_name";
$categories_result = $mysqli->query($categories_sql);
$categories = [];
while ($category = $categories_result->fetch_assoc()) {
    $categories[] = $category;
}

// Get item history
$history_sql = "
    SELECT lt.*, u.user_name
    FROM laundry_transactions lt
    LEFT JOIN users u ON lt.performed_by = u.user_id
    WHERE lt.laundry_id = ?
    ORDER BY lt.transaction_date DESC
    LIMIT 5
";
$history_stmt = $mysqli->prepare($history_sql);
$history_stmt->bind_param("i", $laundry_id);
$history_stmt->execute();
$history_result = $history_stmt->get_result();
$item_history = [];
while ($history = $history_result->fetch_assoc()) {
    $item_history[] = $history;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    
    if ($csrf_token != $_SESSION['csrf_token']) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "CSRF token validation failed";
        header("Location: laundry_edit.php?id=" . $laundry_id);
        exit;
    }
    
    $category_id = intval($_POST['category_id']);
    $quantity = intval($_POST['quantity']);
    $current_location = sanitizeInput($_POST['current_location']);
    $status = sanitizeInput($_POST['status']);
    $condition = sanitizeInput($_POST['condition']);
    $notes = sanitizeInput($_POST['notes']);
    $is_critical = isset($_POST['is_critical']) ? 1 : 0;
    $next_wash_date = !empty($_POST['next_wash_date']) ? $_POST['next_wash_date'] : NULL;
    
    $user_id = intval($_SESSION['user_id']);
    
    // Validate
    $errors = [];
    
    if ($quantity < 1) {
        $errors[] = "Quantity must be at least 1";
    }
    if ($quantity > 1000) {
        $errors[] = "Quantity cannot exceed 1000";
    }
    if (empty($category_id)) {
        $errors[] = "Category is required";
    }
    
    if (empty($errors)) {
        // Update laundry item
        $sql = "UPDATE laundry_items 
               SET category_id = ?, quantity = ?, current_location = ?, 
                   status = ?, item_condition = ?, notes = ?, is_critical = ?, 
                   next_wash_date = ?, updated_at = NOW(), updated_by = ?
               WHERE laundry_id = ?";
        
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("iissssissii", 
            $category_id, $quantity, $current_location, $status, $condition, 
            $notes, $is_critical, $next_wash_date, $user_id, $laundry_id
        );
        
        if ($stmt->execute()) {
            // Create transaction record for significant changes
            if ($item['status'] != $status || $item['current_location'] != $current_location) {
                $transaction_sql = "INSERT INTO laundry_transactions 
                                   (laundry_id, transaction_type, from_location, to_location, 
                                    performed_by, notes) 
                                   VALUES (?, 'update', ?, ?, ?, 'Manual update via edit form')";
                $transaction_stmt = $mysqli->prepare($transaction_sql);
                $transaction_stmt->bind_param("issi", 
                    $laundry_id, 
                    $item['current_location'], 
                    $current_location, 
                    $user_id
                );
                $transaction_stmt->execute();
            }
            
            // Log activity
            $log_description = "Updated laundry item: {$item['asset_name']} (ID: $laundry_id)";
            mysqli_query($mysqli, "INSERT INTO logs SET log_type = 'Laundry', log_action = 'Update', log_description = '$log_description', log_ip = '$session_ip', log_user_agent = '$session_user_agent', log_user_id = $session_user_id");
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Laundry item updated successfully";
            
            header("Location: laundry_view.php?id=" . $laundry_id);
            exit();
        } else {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error updating laundry item: " . $mysqli->error;
            header("Location: laundry_edit.php?id=" . $laundry_id);
            exit;
        }
    } else {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = implode("<br>", $errors);
        header("Location: laundry_edit.php?id=" . $laundry_id);
        exit;
    }
}
?>

<div class="card">
    <div class="card-header bg-warning py-2">
        <h3 class="card-title mt-2 mb-0 text-dark">
            <i class="fas fa-fw fa-edit mr-2"></i>Edit Laundry Item
        </h3>
        <div class="card-tools">
            <a href="laundry_view.php?id=<?php echo $laundry_id; ?>" class="btn btn-info">
                <i class="fas fa-eye mr-2"></i>View Details
            </a>
            <a href="laundry_management.php" class="btn btn-light ml-2">
                <i class="fas fa-arrow-left mr-2"></i>Back to Laundry
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
        
        <div class="alert alert-info mb-4">
            <i class="fas fa-info-circle mr-2"></i>
            Editing: <strong><?php echo htmlspecialchars($item['asset_name']); ?></strong> 
            (<?php echo htmlspecialchars($item['asset_tag']); ?>)
        </div>
        
        <form method="POST" id="editForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <div class="row">
                <div class="col-md-8">
                    <!-- Item Information -->
                    <div class="card card-primary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-info-circle mr-2"></i>Item Information</h3>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="alert alert-secondary">
                                        <div class="row">
                                            <div class="col-md-3">
                                                <p class="mb-1"><strong>Asset:</strong></p>
                                                <h5><?php echo htmlspecialchars($item['asset_name']); ?></h5>
                                                <small class="text-muted"><?php echo htmlspecialchars($item['asset_tag']); ?></small>
                                            </div>
                                            <div class="col-md-3">
                                                <p class="mb-1"><strong>Asset Category:</strong></p>
                                                <p><?php echo htmlspecialchars($item['asset_category']); ?></p>
                                            </div>
                                            <div class="col-md-3">
                                                <p class="mb-1"><strong>Asset Location:</strong></p>
                                                <p><?php echo htmlspecialchars($item['asset_location']); ?></p>
                                            </div>
                                            <div class="col-md-3">
                                                <p class="mb-1"><strong>Serial Number:</strong></p>
                                                <p><?php echo htmlspecialchars($item['serial_number']); ?></p>
                                            </div>
                                        </div>
                                        <?php if ($item['asset_description']): ?>
                                            <hr class="my-2">
                                            <p class="mb-0"><strong>Description:</strong> <?php echo htmlspecialchars($item['asset_description']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Laundry Details -->
                    <div class="card card-warning">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-tshirt mr-2"></i>Laundry Details</h3>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="category_id">Category *</label>
                                        <select class="form-control select2" id="category_id" name="category_id" required>
                                            <option value="">- Select Category -</option>
                                            <?php foreach ($categories as $category): ?>
                                                <option value="<?php echo $category['category_id']; ?>" 
                                                        <?php echo $item['category_id'] == $category['category_id'] ? 'selected' : ''; ?>
                                                        data-color="<?php echo $category['category_color']; ?>">
                                                    <?php echo htmlspecialchars($category['category_name']); ?>
                                                    <?php if ($category['category_color']): ?>
                                                        <span class="badge" style="background-color: <?php echo $category['category_color']; ?>">&nbsp;</span>
                                                    <?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="form-text text-muted">Laundry item category</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="quantity">Quantity *</label>
                                        <input type="number" class="form-control" id="quantity" name="quantity" 
                                               value="<?php echo $item['quantity']; ?>" min="1" max="1000" required>
                                        <small class="form-text text-muted">Number of identical items</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="current_location">Current Location *</label>
                                        <select class="form-control" id="current_location" name="current_location" required>
                                            <option value="storage" <?php echo $item['current_location'] == 'storage' ? 'selected' : ''; ?>>Storage</option>
                                            <option value="clinic" <?php echo $item['current_location'] == 'clinic' ? 'selected' : ''; ?>>Clinic</option>
                                            <option value="laundry" <?php echo $item['current_location'] == 'laundry' ? 'selected' : ''; ?>>Laundry Room</option>
                                            <option value="in_transit" <?php echo $item['current_location'] == 'in_transit' ? 'selected' : ''; ?>>In Transit</option>
                                            <option value="ward" <?php echo $item['current_location'] == 'ward' ? 'selected' : ''; ?>>Ward</option>
                                            <option value="or" <?php echo $item['current_location'] == 'or' ? 'selected' : ''; ?>>Operating Room</option>
                                            <option value="er" <?php echo $item['current_location'] == 'er' ? 'selected' : ''; ?>>Emergency Room</option>
                                        </select>
                                        <small class="form-text text-muted">Where the item is currently located</small>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="status">Status *</label>
                                        <select class="form-control" id="status" name="status" required>
                                            <option value="clean" <?php echo $item['status'] == 'clean' ? 'selected' : ''; ?>>Clean</option>
                                            <option value="dirty" <?php echo $item['status'] == 'dirty' ? 'selected' : ''; ?>>Dirty</option>
                                            <option value="in_wash" <?php echo $item['status'] == 'in_wash' ? 'selected' : ''; ?>>In Wash</option>
                                            <option value="damaged" <?php echo $item['status'] == 'damaged' ? 'selected' : ''; ?>>Damaged</option>
                                            <option value="lost" <?php echo $item['status'] == 'lost' ? 'selected' : ''; ?>>Lost</option>
                                            <option value="retired" <?php echo $item['status'] == 'retired' ? 'selected' : ''; ?>>Retired</option>
                                        </select>
                                        <small class="form-text text-muted">Current status of the item</small>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="condition">Condition *</label>
                                        <select class="form-control" id="condition" name="condition" required>
                                            <option value="excellent" <?php echo $item['item_condition'] == 'excellent' ? 'selected' : ''; ?>>Excellent</option>
                                            <option value="good" <?php echo $item['item_condition'] == 'good' ? 'selected' : ''; ?>>Good</option>
                                            <option value="fair" <?php echo $item['item_condition'] == 'fair' ? 'selected' : ''; ?>>Fair</option>
                                            <option value="poor" <?php echo $item['item_condition'] == 'poor' ? 'selected' : ''; ?>>Poor</option>
                                            <option value="critical" <?php echo $item['item_condition'] == 'critical' ? 'selected' : ''; ?>>Critical</option>
                                        </select>
                                        <small class="form-text text-muted">Physical condition of the item</small>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="next_wash_date">Next Wash Date</label>
                                        <input type="date" class="form-control" id="next_wash_date" 
                                               name="next_wash_date" value="<?php echo $item['next_wash_date']; ?>">
                                        <small class="form-text text-muted">When this item should be washed next</small>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label>Wash History</label>
                                        <input type="text" class="form-control" 
                                               value="<?php echo $item['wash_count']; ?> washes" disabled>
                                        <small class="form-text text-muted">
                                            Last: <?php echo $item['last_washed_date'] ? date('M j, Y', strtotime($item['last_washed_date'])) : 'Never'; ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Additional Information -->
                    <div class="card card-info">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-clipboard mr-2"></i>Additional Information</h3>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label for="notes">Notes</label>
                                <textarea class="form-control" id="notes" name="notes" rows="4"><?php echo htmlspecialchars($item['notes']); ?></textarea>
                                <small class="form-text text-muted">Additional information about this laundry item</small>
                            </div>

                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" name="is_critical" id="is_critical" value="1"
                                       <?php echo $item['is_critical'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="is_critical">
                                    <strong>Mark as Critical Item</strong>
                                    <small class="form-text text-muted d-block">Critical items require special attention and priority handling</small>
                                </label>
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
                                    <i class="fas fa-save mr-2"></i>Update Item
                                </button>
                                <button type="reset" class="btn btn-outline-secondary" onclick="resetForm()">
                                    <i class="fas fa-undo mr-2"></i>Reset Changes
                                </button>
                                <a href="laundry_view.php?id=<?php echo $laundry_id; ?>" class="btn btn-outline-info">
                                    <i class="fas fa-eye mr-2"></i>View Details
                                </a>
                                <a href="laundry_management.php" class="btn btn-outline-danger">
                                    <i class="fas fa-times mr-2"></i>Cancel
                                </a>
                            </div>
                            <hr>
                            <div class="text-center small text-muted">
                                <i class="fas fa-info-circle mr-1"></i>
                                Use <kbd>Ctrl+S</kbd> to save, <kbd>Esc</kbd> to cancel
                            </div>
                        </div>
                    </div>

                    <!-- Item Status -->
                    <div class="card card-info">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-chart-pie mr-2"></i>Item Status</h3>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-3">
                                <i class="fas fa-tshirt fa-3x mb-2" style="color: <?php 
                                    switch($item['status']) {
                                        case 'clean': echo '#28a745'; break;
                                        case 'dirty': echo '#ffc107'; break;
                                        case 'damaged': echo '#dc3545'; break;
                                        case 'lost': echo '#6c757d'; break;
                                        default: echo '#6c757d';
                                    }
                                ?>;"></i>
                                <h5><?php echo htmlspecialchars($item['asset_name']); ?></h5>
                                <div class="text-muted small"><?php echo htmlspecialchars($item['asset_tag']); ?></div>
                            </div>
                            <hr>
                            <div class="small">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Quantity:</span>
                                    <span class="font-weight-bold"><?php echo $item['quantity']; ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Category:</span>
                                    <span class="font-weight-bold"><?php echo htmlspecialchars($item['category_name']); ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Status:</span>
                                    <span class="badge badge-<?php 
                                        switch($item['status']) {
                                            case 'clean': echo 'success'; break;
                                            case 'dirty': echo 'warning'; break;
                                            case 'damaged': echo 'danger'; break;
                                            case 'lost': echo 'secondary'; break;
                                            default: echo 'secondary';
                                        }
                                    ?>">
                                        <?php echo ucfirst($item['status']); ?>
                                    </span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Condition:</span>
                                    <span class="badge badge-<?php 
                                        switch($item['item_condition']) {
                                            case 'excellent': echo 'success'; break;
                                            case 'good': echo 'info'; break;
                                            case 'fair': echo 'warning'; break;
                                            case 'poor': echo 'warning'; break;
                                            case 'critical': echo 'danger'; break;
                                            default: echo 'secondary';
                                        }
                                    ?>">
                                        <?php echo ucfirst($item['item_condition']); ?>
                                    </span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Created:</span>
                                    <span class="font-weight-bold"><?php echo date('M j, Y', strtotime($item['created_at'])); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Item History -->
                    <div class="card card-secondary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-history mr-2"></i>Recent History</h3>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($item_history)): ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($item_history as $history): ?>
                                        <div class="list-group-item px-0 py-2">
                                            <div class="d-flex w-100 justify-content-between align-items-center">
                                                <div>
                                                    <h6 class="mb-1"><?php echo ucfirst($history['transaction_type']); ?></h6>
                                                    <small class="text-muted">
                                                        <?php echo date('M j, g:i A', strtotime($history['transaction_date'])); ?>
                                                    </small>
                                                </div>
                                                <span class="badge badge-light">
                                                    <?php echo $history['user_name'] ?? 'System'; ?>
                                                </span>
                                            </div>
                                            <?php if ($history['notes']): ?>
                                                <small class="text-muted"><?php echo htmlspecialchars(substr($history['notes'], 0, 30)); ?>...</small>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted text-center mb-0">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    No history available
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Quick Tips -->
                    <div class="card card-primary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-lightbulb mr-2"></i>Quick Tips</h3>
                        </div>
                        <div class="card-body">
                            <div class="callout callout-info small mb-3">
                                <i class="fas fa-info-circle mr-2"></i>
                                <strong>Status Updates:</strong> Update status when items move between clean/dirty states.
                            </div>
                            <div class="callout callout-warning small mb-3">
                                <i class="fas fa-exclamation-triangle mr-2"></i>
                                <strong>Condition Tracking:</strong> Update condition as items wear out over time.
                            </div>
                            <div class="callout callout-success small">
                                <i class="fas fa-check-circle mr-2"></i>
                                <strong>Next Wash Date:</strong> Set realistic wash schedules based on usage.
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
    
    // Initialize date picker
    $('#next_wash_date').datepicker({
        format: 'yyyy-mm-dd',
        autoclose: true,
        todayHighlight: true
    });
    
    // Enhanced form validation
    $('#editForm').on('submit', function(e) {
        var requiredFields = ['category_id', 'quantity', 'current_location', 'status', 'condition'];
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
        
        // Validate quantity
        var quantity = parseInt($('#quantity').val()) || 0;
        if (quantity < 1) {
            isValid = false;
            errorMessages.push('Quantity must be at least 1');
            $('#quantity').addClass('is-invalid');
        } else if (quantity > 1000) {
            isValid = false;
            errorMessages.push('Quantity cannot exceed 1000');
            $('#quantity').addClass('is-invalid');
        } else {
            $('#quantity').removeClass('is-invalid');
        }
        
        if (!isValid) {
            e.preventDefault();
            var errorMessage = 'Please fix the following errors:\n• ' + errorMessages.join('\n• ');
            alert(errorMessage);
            return false;
        }
        
        // Show loading state
        $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Updating...').prop('disabled', true);
    });
});

function resetForm() {
    if (confirm('Are you sure you want to reset all changes? All entered data will be lost.')) {
        // Reload the page to get original values
        window.location.reload();
    }
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + S to save
    if (e.ctrlKey && e.keyCode === 83) {
        e.preventDefault();
        $('#editForm').submit();
    }
    // Escape to cancel
    if (e.keyCode === 27) {
        window.location.href = 'laundry_management.php';
    }
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

.callout-primary {
    border-left-color: #007bff;
    background-color: #f0f8ff;
}

.is-invalid {
    border-color: #dc3545;
    box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25);
}

.btn:disabled {
    cursor: not-allowed;
    opacity: 0.6;
}

.form-check-input {
    transform: scale(1.2);
    margin-right: 8px;
}

.select2-container .select2-selection--single {
    height: 38px;
}

.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 36px;
}

.alert-secondary {
    background-color: #f8f9fa;
    border-color: #dee2e6;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
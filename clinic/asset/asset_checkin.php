<?php
// asset_checkin.php
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

// Get checkout details
$checkout_sql = "
    SELECT c.*, a.asset_id, a.asset_tag, a.asset_name, a.serial_number,
           CONCAT(e.first_name, ' ', e.last_name) as employee_name,
           l.location_name as destination_location,
           u.user_name as checkout_by_name
    FROM asset_checkout_logs c
    JOIN assets a ON c.asset_id = a.asset_id
    LEFT JOIN employees e ON c.assigned_to_id = e.employee_id
    LEFT JOIN asset_locations l ON c.destination_location_id = l.location_id
    LEFT JOIN users u ON c.checkout_by = u.user_id
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

// Handle checkin form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    
    if ($csrf_token != $_SESSION['csrf_token']) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "CSRF token validation failed";
        header("Location: asset_checkin.php?id=$checkout_id");
        exit;
    }
    
    $checkin_date = sanitizeInput($_POST['checkin_date']);
    $checkin_condition = sanitizeInput($_POST['checkin_condition']);
    $checkin_notes = sanitizeInput($_POST['checkin_notes']);
    $return_location_id = !empty($_POST['return_location_id']) ? intval($_POST['return_location_id']) : null;
    $checkin_by = $session_user_id;
    
    // Validate required fields
    if (empty($checkin_date)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please fill in all required fields";
        header("Location: asset_checkin.php?id=$checkout_id");
        exit;
    }
    
    // Validate date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkin_date)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid checkin date format. Use YYYY-MM-DD";
        header("Location: asset_checkin.php?id=$checkout_id");
        exit;
    }
    
    // Check if checkin date is before checkout date
    $checkout_date = strtotime($checkout['checkout_date']);
    $checkin_timestamp = strtotime($checkin_date);
    
    if ($checkin_timestamp < $checkout_date) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Checkin date cannot be before checkout date";
        header("Location: asset_checkin.php?id=$checkout_id");
        exit;
    }
    
    // Start transaction
    $mysqli->begin_transaction();
    
    try {
        // Update checkout record
        $update_checkout_sql = "
            UPDATE asset_checkout_logs 
            SET checkin_date = ?, 
                checkin_condition = ?, 
                checkin_notes = ?, 
                return_location_id = ?, 
                checkin_by = ?, 
                checkin_at = NOW(),
                updated_at = NOW()
            WHERE checkout_id = ?
        ";
        
        $update_stmt = $mysqli->prepare($update_checkout_sql);
        $update_stmt->bind_param(
            "sssiii",
            $checkin_date,
            $checkin_condition,
            $checkin_notes,
            $return_location_id,
            $checkin_by,
            $checkout_id
        );
        
        // Update asset status and location
        $update_asset_sql = "
            UPDATE assets 
            SET status = 'active', 
                location_id = ?,
                updated_by = ?, 
                updated_at = NOW() 
            WHERE asset_id = ?
        ";
        
        $update_asset_stmt = $mysqli->prepare($update_asset_sql);
        $update_asset_stmt->bind_param(
            "iii",
            $return_location_id,
            $session_user_id,
            $checkout['asset_id']
        );
        
        if ($update_stmt->execute() && $update_asset_stmt->execute()) {
            $mysqli->commit();
            
            // Log activity
            $log_description = "Asset checked in: {$checkout['asset_tag']} - {$checkout['asset_name']} from {$checkout['employee_name']}";
            mysqli_query($mysqli, "INSERT INTO logs SET log_type = 'Asset', log_action = 'Checkin', log_description = '$log_description', log_ip = '$session_ip', log_user_agent = '$session_user_agent', log_user_id = $session_user_id");
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Asset checked in successfully!";
            header("Location: asset_checkout.php");
            exit;
        } else {
            throw new Exception("Database error: " . $mysqli->error);
        }
    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error checking in asset: " . $e->getMessage();
        header("Location: asset_checkin.php?id=$checkout_id");
        exit;
    }
}

// Get locations for return
$locations_sql = "SELECT location_id, location_name, location_type FROM asset_locations 
                 WHERE is_active = 1 ORDER BY location_type, location_name";
$locations_result = $mysqli->query($locations_sql);
$locations = [];
while ($location = $locations_result->fetch_assoc()) {
    $locations[] = $location;
}
?>

<div class="card">
    <div class="card-header bg-success py-2">
        <h3 class="card-title mt-2 mb-0 text-white"><i class="fas fa-fw fa-sign-in-alt mr-2"></i>Checkin Asset</h3>
        <div class="card-tools">
            <a href="asset_checkout.php" class="btn btn-light">
                <i class="fas fa-arrow-left mr-2"></i>Back to Checkouts
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

        <!-- Asset Information Card -->
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
                            
                            <dt class="col-sm-5">Asset Name:</dt>
                            <dd class="col-sm-7"><?php echo htmlspecialchars($checkout['asset_name']); ?></dd>
                            
                            <dt class="col-sm-5">Serial Number:</dt>
                            <dd class="col-sm-7"><?php echo htmlspecialchars($checkout['serial_number'] ?: 'N/A'); ?></dd>
                        </dl>
                    </div>
                    <div class="col-md-4">
                        <dl class="row">
                            <dt class="col-sm-5">Checked Out To:</dt>
                            <dd class="col-sm-7"><?php echo htmlspecialchars($checkout['employee_name']); ?></dd>
                            
                            <dt class="col-sm-5">Checkout Date:</dt>
                            <dd class="col-sm-7"><?php echo date('M d, Y', strtotime($checkout['checkout_date'])); ?></dd>
                            
                            <dt class="col-sm-5">Expected Return:</dt>
                            <dd class="col-sm-7"><?php echo $checkout['expected_return_date'] ? date('M d, Y', strtotime($checkout['expected_return_date'])) : 'Not set'; ?></dd>
                        </dl>
                    </div>
                    <div class="col-md-4">
                        <dl class="row">
                            <dt class="col-sm-5">Checkout Type:</dt>
                            <dd class="col-sm-7"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $checkout['checkout_type']))); ?></dd>
                            
                            <dt class="col-sm-5">Destination:</dt>
                            <dd class="col-sm-7"><?php echo htmlspecialchars($checkout['destination_location'] ?: 'Not specified'); ?></dd>
                            
                            <dt class="col-sm-5">Checked Out By:</dt>
                            <dd class="col-sm-7"><?php echo htmlspecialchars($checkout['checkout_by_name']); ?></dd>
                        </dl>
                    </div>
                </div>
            </div>
        </div>

        <!-- Checkin Form -->
        <form method="POST" id="checkinForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <div class="row">
                <div class="col-md-8">
                    <div class="card card-success">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-clipboard-check mr-2"></i>Checkin Details</h3>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="checkin_date">Checkin Date *</label>
                                        <input type="date" class="form-control" id="checkin_date" name="checkin_date" 
                                               value="<?php echo date('Y-m-d'); ?>" required>
                                        <small class="form-text text-muted">Date when the asset is returned</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="checkin_condition">Asset Condition *</label>
                                        <select class="form-control" id="checkin_condition" name="checkin_condition" required>
                                            <option value="excellent">Excellent - Like new</option>
                                            <option value="good" selected>Good - Normal wear</option>
                                            <option value="fair">Fair - Minor issues</option>
                                            <option value="poor">Poor - Needs repair</option>
                                            <option value="damaged">Damaged - Requires attention</option>
                                        </select>
                                        <small class="form-text text-muted">Condition of asset when returned</small>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="return_location_id">Return Location</label>
                                        <select class="form-control select2" id="return_location_id" name="return_location_id">
                                            <option value="">- Select Location -</option>
                                            <?php foreach ($locations as $location): ?>
                                                <option value="<?php echo $location['location_id']; ?>">
                                                    <?php echo htmlspecialchars($location['location_type'] . ' - ' . $location['location_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="form-text text-muted">Where the asset will be stored</small>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="checkin_notes">Checkin Notes</label>
                                <textarea class="form-control" id="checkin_notes" name="checkin_notes" rows="3" 
                                          placeholder="Notes about the asset condition, any issues, maintenance needed, etc..." maxlength="500"></textarea>
                                <small class="form-text text-muted">Provide details about the asset's condition</small>
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
                                <button type="submit" class="btn btn-success btn-lg">
                                    <i class="fas fa-sign-in-alt mr-2"></i>Checkin Asset
                                </button>
                                <a href="asset_checkout.php" class="btn btn-outline-danger">
                                    <i class="fas fa-times mr-2"></i>Cancel
                                </a>
                            </div>
                            
                            <hr>
                            
                            <div class="alert alert-info">
                                <h6><i class="fas fa-info-circle mr-2"></i>Checkin Information</h6>
                                <small>
                                    <div class="mb-2">
                                        <strong>Asset:</strong><br>
                                        <?php echo htmlspecialchars($checkout['asset_tag'] . ' - ' . $checkout['asset_name']); ?>
                                    </div>
                                    <div class="mb-2">
                                        <strong>Assigned to:</strong><br>
                                        <?php echo htmlspecialchars($checkout['employee_name']); ?>
                                    </div>
                                    <div>
                                        <strong>Days checked out:</strong><br>
                                        <?php 
                                        $days_out = floor((time() - strtotime($checkout['checkout_date'])) / (60 * 60 * 24));
                                        echo $days_out . ' day' . ($days_out != 1 ? 's' : '');
                                        ?>
                                    </div>
                                </small>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Tips -->
                    <div class="card card-warning">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-lightbulb mr-2"></i>Checkin Tips</h3>
                        </div>
                        <div class="card-body">
                            <div class="callout callout-info small mb-3">
                                <i class="fas fa-info-circle mr-2"></i>
                                <strong>Inspect Thoroughly:</strong> Always inspect the asset for damage or issues before checking it in.
                            </div>
                            <div class="callout callout-warning small mb-3">
                                <i class="fas fa-exclamation-triangle mr-2"></i>
                                <strong>Document Issues:</strong> Note any damage or maintenance needs in the checkin notes.
                            </div>
                            <div class="callout callout-success small">
                                <i class="fas fa-check-circle mr-2"></i>
                                <strong>Update Location:</strong> Ensure the asset is returned to the correct storage location.
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
    $('#checkinForm').on('submit', function(e) {
        var requiredFields = ['checkin_date', 'checkin_condition'];
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

        // Validate date
        var checkinDate = new Date($('#checkin_date').val());
        var checkoutDate = new Date('<?php echo $checkout['checkout_date']; ?>');
        
        if (checkinDate < checkoutDate) {
            isValid = false;
            errorMessages.push('Checkin date cannot be before checkout date');
            $('#checkin_date').addClass('is-invalid');
        }

        if (!isValid) {
            e.preventDefault();
            var errorMessage = 'Please fix the following errors:\n• ' + errorMessages.join('\n• ');
            alert(errorMessage);
            return false;
        }

        // Show loading state
        $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Processing...').prop('disabled', true);
    });

    // Keyboard shortcuts
    $(document).keydown(function(e) {
        // Ctrl + S to save
        if (e.ctrlKey && e.keyCode === 83) {
            e.preventDefault();
            $('#checkinForm').submit();
        }
        // Escape to cancel
        if (e.keyCode === 27) {
            window.location.href = 'asset_checkout.php';
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
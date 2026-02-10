<?php
// asset_checkout_new.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Initialize variables
$available_assets = [];
$employees = [];
$locations = [];

// Get available assets (not checked out and active)
$assets_sql = "
    SELECT a.asset_id, a.asset_tag, a.asset_name, a.serial_number, 
           ac.category_name, al.location_name, al.location_id,
           a.purchase_price, a.current_value
    FROM assets a
    LEFT JOIN asset_categories ac ON a.category_id = ac.category_id
    LEFT JOIN asset_locations al ON a.location_id = al.location_id
    WHERE a.status = 'active' 
    AND a.asset_id NOT IN (
        SELECT asset_id FROM asset_checkout_logs
        WHERE checkin_date IS NULL
    )
    ORDER BY a.asset_tag
";
$assets_result = $mysqli->query($assets_sql);
while ($asset = $assets_result->fetch_assoc()) {
    $available_assets[] = $asset;
}

// Get employees for assignment 
$employees_sql = "SELECT employee_id, CONCAT(first_name, ' ', last_name) as employee_name FROM employees  
                  ORDER BY first_name, last_name";
$employees_result = $mysqli->query($employees_sql);
while ($employee = $employees_result->fetch_assoc()) {
    $employees[] = $employee;
}

// Get locations for destination
$locations_sql = "SELECT location_id, location_name, location_type FROM asset_locations 
                 WHERE is_active = 1 ORDER BY location_type, location_name";
$locations_result = $mysqli->query($locations_sql);
while ($location = $locations_result->fetch_assoc()) {
    $locations[] = $location;
}

// Get current user's information
$user_sql = "SELECT user_name, user_email FROM users WHERE user_id = ?";
$user_stmt = $mysqli->prepare($user_sql);
$user_stmt->bind_param("i", $session_user_id);
$user_stmt->execute();
$user_result = $user_stmt->get_result();
$current_user = $user_result->fetch_assoc();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    
    if ($csrf_token != $_SESSION['csrf_token']) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "CSRF token validation failed";
        header("Location: asset_checkout_new.php");
        exit;
    }
    
    $asset_id = intval($_POST['asset_id']);
    $checkout_type = sanitizeInput($_POST['checkout_type']);
    $assigned_to_id = intval($_POST['assigned_to_id']);
    $checkout_date = sanitizeInput($_POST['checkout_date']);
    $expected_return_date = !empty($_POST['expected_return_date']) ? sanitizeInput($_POST['expected_return_date']) : null;
    $checkout_notes = sanitizeInput($_POST['checkout_notes']);
    $destination_location_id = !empty($_POST['destination_location_id']) ? intval($_POST['destination_location_id']) : null;
    $checkout_by = $session_user_id;
    
    // Validate required fields
    if (empty($asset_id) || empty($assigned_to_id) || empty($checkout_date)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please fill in all required fields";
        header("Location: asset_checkout_new.php");
        exit;
    }
    
    // Validate date format - FIXED: Added proper validation
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkout_date)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid checkout date format. Use YYYY-MM-DD";
        header("Location: asset_checkout_new.php");
        exit;
    }
    
    if ($expected_return_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expected_return_date)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid return date format. Use YYYY-MM-DD";
        header("Location: asset_checkout_new.php");
        exit;
    }
    
    // Validate dates are valid
    $checkout_timestamp = strtotime($checkout_date);
    if ($checkout_timestamp === false) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid checkout date";
        header("Location: asset_checkout_new.php");
        exit;
    }
    
    if ($expected_return_date) {
        $return_timestamp = strtotime($expected_return_date);
        if ($return_timestamp === false) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Invalid return date";
            header("Location: asset_checkout_new.php");
            exit;
        }
        
        // Check if return date is before checkout date
        if ($return_timestamp < $checkout_timestamp) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Return date cannot be before checkout date";
            header("Location: asset_checkout_new.php");
            exit;
        }
    }
    
    // Validate asset is available
    $check_asset_sql = "SELECT status FROM assets WHERE asset_id = ?";
    $check_stmt = $mysqli->prepare($check_asset_sql);
    $check_stmt->bind_param("i", $asset_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $asset = $check_result->fetch_assoc();
    
    if (!$asset || $asset['status'] != 'active') {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Selected asset is not available for checkout";
        header("Location: asset_checkout_new.php");
        exit;
    }
    
    // Check if already checked out
    $check_checkout_sql = "SELECT checkout_id FROM asset_checkout_logs WHERE asset_id = ? AND checkin_date IS NULL";
    $check_checkout_stmt = $mysqli->prepare($check_checkout_sql);
    $check_checkout_stmt->bind_param("i", $asset_id);
    $check_checkout_stmt->execute();
    $check_checkout_result = $check_checkout_stmt->get_result();
    
    if ($check_checkout_result->num_rows > 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Asset is already checked out to someone else";
        header("Location: asset_checkout_new.php");
        exit;
    }
    
    // Insert checkout record - FIXED: Using correct bind_param type specifier
    $insert_sql = "
        INSERT INTO asset_checkout_logs 
        (asset_id, checkout_type, assigned_to_type, assigned_to_id, checkout_date, 
         expected_return_date, checkout_notes, destination_location_id, checkout_by, checkout_at)
        VALUES (?, ?, 'employee', ?, ?, ?, ?, ?, ?, NOW())
    ";
    
    $insert_stmt = $mysqli->prepare($insert_sql);
    
    // FIXED: Correct bind_param type specifier - 8 parameters
    // i = asset_id (integer)
    // s = checkout_type (string)
    // i = assigned_to_id (integer) - Note: 'employee' is hardcoded in SQL, not a parameter
    // s = checkout_date (string)
    // s = expected_return_date (string or null)
    // s = checkout_notes (string)
    // i = destination_location_id (integer or null)
    // i = checkout_by (integer)
    $insert_stmt->bind_param(
        "isissiii",  // FIXED: Changed from "ississsi" to "isissiii"
        $asset_id,           // i
        $checkout_type,      // s
        $assigned_to_id,     // i
        $checkout_date,      // s
        $expected_return_date, // s (can be null)
        $checkout_notes,     // s
        $destination_location_id, // i (can be null)
        $checkout_by         // i
    );
    
    // Update asset status
    $update_asset_sql = "UPDATE assets SET status = 'checked_out', updated_by = ?, updated_at = NOW() WHERE asset_id = ?";
    $update_stmt = $mysqli->prepare($update_asset_sql);
    $update_stmt->bind_param("ii", $session_user_id, $asset_id);
    
    // Start transaction
    $mysqli->begin_transaction();
    
    try {
        if ($insert_stmt->execute() && $update_stmt->execute()) {
            $mysqli->commit();
            
            // Get details for logging
            $asset_info = $mysqli->query("SELECT asset_tag, asset_name FROM assets WHERE asset_id = $asset_id")->fetch_assoc();
            
            // Get employee info
            $emp_info = $mysqli->query("SELECT CONCAT(first_name, ' ', last_name) as employee_name FROM employees WHERE employee_id = $assigned_to_id")->fetch_assoc();
            $assigned_to_info = $emp_info['employee_name'];
            
            // Log activity
            $log_description = "Asset checked out: {$asset_info['asset_tag']} - {$asset_info['asset_name']} to $assigned_to_info";
            mysqli_query($mysqli, "INSERT INTO logs SET log_type = 'Asset', log_action = 'Checkout', log_description = '$log_description', log_ip = '$session_ip', log_user_agent = '$session_user_agent', log_user_id = $session_user_id");
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Asset checked out successfully!";
            header("Location: asset_checkout.php");
            exit;
        } else {
            $error_msg = "Database error: ";
            if ($insert_stmt->error) {
                $error_msg .= "Insert error: " . $insert_stmt->error;
            }
            if ($update_stmt->error) {
                $error_msg .= " Update error: " . $update_stmt->error;
            }
            throw new Exception($error_msg);
        }
    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error checking out asset: " . $e->getMessage();
        header("Location: asset_checkout_new.php");
        exit;
    }
}

// Get statistics for sidebar 
$checkout_stats_sql = "
    SELECT 
        COUNT(DISTINCT asset_id) as total_checked_out,
        COUNT(DISTINCT CASE WHEN assigned_to_type = 'employee' THEN assigned_to_id END) as employees_with_assets,
        SUM(CASE WHEN DATE(checkout_date) = CURDATE() THEN 1 ELSE 0 END) as checked_out_today
    FROM asset_checkout_logs
    WHERE checkin_date IS NULL
";
$checkout_stats_result = $mysqli->query($checkout_stats_sql);
$checkout_stats = $checkout_stats_result->fetch_assoc();

// Get recently checked out assets
$recent_checkouts_sql = "
    SELECT c.*, a.asset_tag, a.asset_name, 
           CONCAT(e.first_name, ' ', e.last_name) as employee_name
    FROM asset_checkout_logs c
    JOIN assets a ON c.asset_id = a.asset_id
    LEFT JOIN employees e ON c.assigned_to_id = e.employee_id
    WHERE c.checkin_date IS NULL
    ORDER BY c.checkout_at DESC
    LIMIT 5
";
$recent_checkouts_result = $mysqli->query($recent_checkouts_sql);
$recent_checkouts = [];
while ($checkout = $recent_checkouts_result->fetch_assoc()) {
    $recent_checkouts[] = $checkout;
}
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0 text-white"><i class="fas fa-fw fa-sign-out-alt mr-2"></i>Checkout Asset</h3>
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

        <form method="POST" id="checkoutForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <div class="row">
                <div class="col-md-8">
                    <!-- Asset Selection -->
                    <div class="card card-primary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-cube mr-2"></i>Select Asset</h3>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label for="asset_id">Asset to Checkout *</label>
                                <select class="form-control select2" id="asset_id" name="asset_id" required>
                                    <option value="">- Select Asset -</option>
                                    <?php foreach ($available_assets as $asset): ?>
                                        <option value="<?php echo $asset['asset_id']; ?>" 
                                                data-category="<?php echo htmlspecialchars($asset['category_name']); ?>"
                                                data-location="<?php echo htmlspecialchars($asset['location_name']); ?>"
                                                data-value="<?php echo number_format($asset['current_value'], 2); ?>">
                                            <?php echo htmlspecialchars($asset['asset_tag'] . ' - ' . $asset['asset_name']); ?>
                                            <?php if ($asset['serial_number']): ?>
                                                (SN: <?php echo htmlspecialchars($asset['serial_number']); ?>)
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="form-text text-muted">Only available (active and not checked out) assets are shown</small>
                            </div>
                            
                            <div class="row mt-3" id="assetDetails" style="display: none;">
                                <div class="col-md-4">
                                    <div class="info-box bg-light">
                                        <span class="info-box-icon"><i class="fas fa-tag"></i></span>
                                        <div class="info-box-content">
                                            <span class="info-box-text">Category</span>
                                            <span id="assetCategory" class="info-box-number">-</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="info-box bg-light">
                                        <span class="info-box-icon"><i class="fas fa-map-marker-alt"></i></span>
                                        <div class="info-box-content">
                                            <span class="info-box-text">Current Location</span>
                                            <span id="assetLocation" class="info-box-number">-</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="info-box bg-light">
                                        <span class="info-box-icon"><i class="fas fa-dollar-sign"></i></span>
                                        <div class="info-box-content">
                                            <span class="info-box-text">Current Value</span>
                                            <span id="assetValue" class="info-box-number">$0.00</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

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
                                            <option value="temporary">Temporary (Short-term)</option>
                                            <option value="long_term">Long-term Assignment</option>
                                            <option value="project">Project-based</option>
                                            <option value="training">Training/Development</option>
                                            <option value="replacement">Replacement Equipment</option>
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
                                                <option value="<?php echo $employee['employee_id']; ?>">
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
                                                <option value="<?php echo $location['location_id']; ?>">
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
                                               value="<?php echo date('Y-m-d'); ?>" required>
                                        <small class="form-text text-muted">When the asset is being checked out</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="expected_return_date">Expected Return Date</label>
                                        <input type="date" class="form-control" id="expected_return_date" 
                                               name="expected_return_date">
                                        <small class="form-text text-muted">When the asset is expected to be returned (optional)</small>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="checkout_notes">Checkout Notes</label>
                                <textarea class="form-control" id="checkout_notes" name="checkout_notes" rows="3" 
                                          placeholder="Purpose of checkout, special instructions, conditions, etc..." maxlength="500"></textarea>
                                <small class="form-text text-muted">Provide details about this checkout (optional)</small>
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
                                    <i class="fas fa-sign-out-alt mr-2"></i>Checkout Asset
                                </button>
                                <button type="reset" class="btn btn-outline-secondary" onclick="resetForm()">
                                    <i class="fas fa-undo mr-2"></i>Reset Form
                                </button>
                                <a href="asset_checkout.php" class="btn btn-outline-danger">
                                    <i class="fas fa-times mr-2"></i>Cancel
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Checkout Preview -->
                    <div class="card card-info">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-eye mr-2"></i>Checkout Preview</h3>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-3">
                                <i class="fas fa-cube fa-3x text-info mb-2"></i>
                                <h5 id="preview_asset">Select an Asset</h5>
                                <div id="preview_assigned_to" class="text-muted">Not assigned</div>
                            </div>
                            <hr>
                            <div class="small">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Checkout Type:</span>
                                    <span id="preview_type" class="font-weight-bold">-</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Checkout Date:</span>
                                    <span id="preview_date" class="font-weight-bold"><?php echo date('M d, Y'); ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Return Date:</span>
                                    <span id="preview_return" class="font-weight-bold">Not set</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Destination:</span>
                                    <span id="preview_destination" class="font-weight-bold">-</span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Initiated by:</span>
                                    <span class="font-weight-bold"><?php echo htmlspecialchars($current_user['user_name']); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Checkout Statistics -->
                    <div class="card card-warning">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-chart-bar mr-2"></i>Checkout Statistics</h3>
                        </div>
                        <div class="card-body">
                            <div class="list-group list-group-flush">
                                <div class="list-group-item px-0 py-2">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1">Total Checked Out</h6>
                                        <span class="badge badge-primary badge-pill"><?php echo $checkout_stats['total_checked_out']; ?></span>
                                    </div>
                                    <small class="text-muted">Currently checked out assets</small>
                                </div>
                                <div class="list-group-item px-0 py-2">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1">With Employees</h6>
                                        <span class="badge badge-success badge-pill"><?php echo $checkout_stats['employees_with_assets']; ?></span>
                                    </div>
                                    <small class="text-muted">Employees with assets</small>
                                </div>
                                <div class="list-group-item px-0 py-2">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1">Today's Checkouts</h6>
                                        <span class="badge badge-warning badge-pill"><?php echo $checkout_stats['checked_out_today']; ?></span>
                                    </div>
                                    <small class="text-muted">Assets checked out today</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Checkouts -->
                    <div class="card card-secondary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-history mr-2"></i>Recent Checkouts</h3>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($recent_checkouts)): ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($recent_checkouts as $checkout): ?>
                                        <div class="list-group-item px-0 py-2">
                                            <div class="d-flex w-100 justify-content-between align-items-center">
                                                <div>
                                                    <h6 class="mb-1"><?php echo htmlspecialchars($checkout['asset_tag']); ?></h6>
                                                    <small class="text-muted">To: <?php echo htmlspecialchars($checkout['employee_name']); ?></small>
                                                </div>
                                                <small class="text-muted">
                                                    <?php echo date('M d', strtotime($checkout['checkout_date'])); ?>
                                                </small>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted text-center mb-0">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    No recent checkouts
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
                                <strong>Asset Tracking:</strong> Always set an expected return date to help track overdue assets.
                            </div>
                            <div class="callout callout-warning small mb-3">
                                <i class="fas fa-exclamation-triangle mr-2"></i>
                                <strong>Documentation:</strong> Add detailed notes about the purpose and conditions of checkout.
                            </div>
                            <div class="callout callout-success small">
                                <i class="fas fa-check-circle mr-2"></i>
                                <strong>Asset Condition:</strong> Ensure the asset is in good condition before checking it out.
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

    // Show asset details when asset is selected
    $('#asset_id').on('change', function() {
        var selectedOption = $(this).find('option:selected');
        var assetDetails = $('#assetDetails');
        
        if ($(this).val()) {
            $('#preview_asset').text(selectedOption.text().split(' - ')[1] || selectedOption.text());
            
            $('#assetCategory').text(selectedOption.data('category') || '-');
            $('#assetLocation').text(selectedOption.data('location') || '-');
            $('#assetValue').text('$' + (selectedOption.data('value') || '0.00'));
            
            assetDetails.show();
        } else {
            assetDetails.hide();
            $('#preview_asset').text('Select an Asset');
            $('#preview_assigned_to').text('Not assigned');
        }
    });

    // Update preview when assignee is selected
    $('#assigned_to_id').on('change', function() {
        var selectedText = $(this).find('option:selected').text();
        $('#preview_assigned_to').text(selectedText || 'Not assigned');
    });

    // Update preview for checkout type
    $('#checkout_type').on('change', function() {
        var selectedText = $(this).find('option:selected').text();
        $('#preview_type').text(selectedText);
    });

    // Update preview for checkout date
    $('#checkout_date').on('change', function() {
        var date = new Date($(this).val());
        if (!isNaN(date.getTime())) {
            $('#preview_date').text(date.toLocaleDateString('en-US', { 
                month: 'short', 
                day: 'numeric', 
                year: 'numeric' 
            }));
        }
    });

    // Update preview for expected return date
    $('#expected_return_date').on('change', function() {
        var date = new Date($(this).val());
        if (!isNaN(date.getTime())) {
            $('#preview_return').text(date.toLocaleDateString('en-US', { 
                month: 'short', 
                day: 'numeric', 
                year: 'numeric' 
            }));
        } else {
            $('#preview_return').text('Not set');
        }
    });

    // Update preview for destination location
    $('#destination_location_id').on('change', function() {
        var selectedText = $(this).find('option:selected').text();
        $('#preview_destination').text(selectedText.split(' - ')[1] || '-');
    });

    // Set default expected return date (30 days from now)
    var defaultReturnDate = new Date();
    defaultReturnDate.setDate(defaultReturnDate.getDate() + 30);
    $('#expected_return_date').val(defaultReturnDate.toISOString().split('T')[0]);
    $('#expected_return_date').trigger('change');

    // Enhanced form validation
    $('#checkoutForm').on('submit', function(e) {
        var requiredFields = ['asset_id', 'checkout_type', 'assigned_to_id', 'checkout_date'];
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
        var today = new Date();
        
        if (checkoutDate < new Date(today.getFullYear(), today.getMonth(), today.getDate())) {
            isValid = false;
            errorMessages.push('Checkout date cannot be in the past');
            $('#checkout_date').addClass('is-invalid');
        }
        
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
        $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Processing...').prop('disabled', true);
    });

    // Initialize preview
    $('#checkout_type').trigger('change');
});

function resetForm() {
    if (confirm('Are you sure you want to reset all changes? All entered data will be lost.')) {
        $('#checkoutForm')[0].reset();
        $('.select2').val('').trigger('change');
        $('#expected_return_date').val('');
        $('#checkout_type').trigger('change');
        $('#asset_id').trigger('change');
    }
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + S to save
    if (e.ctrlKey && e.keyCode === 83) {
        e.preventDefault();
        $('#checkoutForm').submit();
    }
    // Escape to cancel
    if (e.keyCode === 27) {
        window.location.href = 'asset_checkout.php';
    }
    // Ctrl + R to reset
    if (e.ctrlKey && e.keyCode === 82) {
        e.preventDefault();
        resetForm();
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

.select2-container .select2-selection--single {
    height: 38px;
}

.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 36px;
}

.is-invalid {
    border-color: #dc3545;
    box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25);
}

.btn:disabled {
    cursor: not-allowed;
    opacity: 0.6;
}

.info-box {
    margin-bottom: 10px;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
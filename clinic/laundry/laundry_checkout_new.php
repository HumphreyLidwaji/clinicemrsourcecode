<?php
// laundry_checkout_new.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

$page_title = "Checkout Laundry Item";

// Get laundry ID
if (!isset($_GET['laundry_id']) || !is_numeric($_GET['laundry_id'])) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid laundry item ID";
    header("Location: laundry_management.php");
    exit();
}

$laundry_id = intval($_GET['laundry_id']);

// Get laundry item details
$sql = "
    SELECT li.*, 
           a.asset_name, 
           a.asset_tag, 
           a.asset_description,
           a.serial_number,
           lc.category_name,
          
           ac.category_name as asset_category,
           al.location_name as asset_location,
           li.quantity as current_quantity,
           DATEDIFF(CURDATE(), li.last_washed_date) as days_since_last_wash
    FROM laundry_items li
    LEFT JOIN assets a ON li.asset_id = a.asset_id
    LEFT JOIN laundry_categories lc ON li.category_id = lc.category_id
    LEFT JOIN asset_categories ac ON a.category_id = ac.category_id
    LEFT JOIN asset_locations al ON a.location_id = al.location_id
    WHERE li.laundry_id = ?
";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $laundry_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Laundry item not found";
    header("Location: laundry_management.php");
    exit();
}

$item = $result->fetch_assoc();

// Get available clients/patients
$patients_sql = "
    SELECT patient_id, 
           patient_first_name,
           patient_last_name,
           patient_mrn,
           patient_dob,
           patient_gender
    FROM patients 
    ORDER BY patient_last_name, patient_first_name
";
$patients_result = $mysqli->query($patients_sql);
$patients = [];
while ($patient = $patients_result->fetch_assoc()) {
    $patients[] = $patient;
}

// Get department statistics
$dept_stats_sql = "
    SELECT 
        department,
        COUNT(*) as transaction_count,
        MAX(transaction_date) as last_transaction
    FROM laundry_transactions
    WHERE laundry_id = ?
    GROUP BY department
    ORDER BY transaction_count DESC
    LIMIT 5
";
$dept_stats_stmt = $mysqli->prepare($dept_stats_sql);
$dept_stats_stmt->bind_param("i", $laundry_id);
$dept_stats_stmt->execute();
$dept_stats_result = $dept_stats_stmt->get_result();
$dept_stats = [];
while ($stat = $dept_stats_result->fetch_assoc()) {
    $dept_stats[] = $stat;
}

// Get recent transactions for this item
$recent_transactions_sql = "
    SELECT lt.*, 
           u.user_name,
           c.client_name,
           p.patient_first_name,
           p.patient_last_name
    FROM laundry_transactions lt
    LEFT JOIN users u ON lt.performed_by = u.user_id
    LEFT JOIN clients c ON lt.performed_for = c.client_id
    LEFT JOIN patients p ON lt.performed_for = p.patient_id
    WHERE lt.laundry_id = ?
    ORDER BY lt.transaction_date DESC
    LIMIT 5
";
$recent_transactions_stmt = $mysqli->prepare($recent_transactions_sql);
$recent_transactions_stmt->bind_param("i", $laundry_id);
$recent_transactions_stmt->execute();
$recent_transactions_result = $recent_transactions_stmt->get_result();
$recent_transactions = [];
while ($transaction = $recent_transactions_result->fetch_assoc()) {
    $recent_transactions[] = $transaction;
}

// Determine default action
$default_action = 'checkout';
$default_to_location = 'clinic';
$button_text = 'Checkout Item';

if ($item['status'] == 'dirty' && $item['current_location'] == 'storage') {
    $default_action = 'checkout_laundry';
    $default_to_location = 'laundry';
    $button_text = 'Send to Laundry';
} elseif ($item['status'] == 'clean' && $item['current_location'] == 'laundry') {
    $default_action = 'checkin_laundry';
    $default_to_location = 'storage';
    $button_text = 'Receive from Laundry';
} elseif ($item['current_location'] == 'clinic' && $item['status'] == 'clean') {
    $default_action = 'checkin';
    $default_to_location = 'storage';
    $button_text = 'Return to Storage';
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    
    if ($csrf_token != $_SESSION['csrf_token']) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "CSRF token validation failed";
        header("Location: laundry_checkout_new.php?laundry_id=" . $laundry_id);
        exit;
    }
    
    $action = sanitizeInput($_POST['action']);
    $to_location = sanitizeInput($_POST['to_location']);
    $quantity = intval($_POST['quantity']);
    $performed_for = !empty($_POST['performed_for']) ? intval($_POST['performed_for']) : NULL;
    $performed_for_type = sanitizeInput($_POST['performed_for_type']);
    $department = sanitizeInput($_POST['department']);
    $expected_return_date = !empty($_POST['expected_return_date']) ? $_POST['expected_return_date'] : NULL;
    $notes = sanitizeInput($_POST['notes']);
    
    $user_id = intval($_SESSION['user_id']);
    
    // Validate
    $errors = [];
    
    if (empty($action)) {
        $errors[] = "Action is required";
    }
    if (empty($to_location)) {
        $errors[] = "Destination location is required";
    }
    if ($quantity < 1) {
        $errors[] = "Quantity must be at least 1";
    }
    if ($quantity > $item['current_quantity']) {
        $errors[] = "Quantity cannot exceed available stock (" . $item['current_quantity'] . ")";
    }
    if (empty($department)) {
        $errors[] = "Department is required";
    }
    
    if (empty($errors)) {
        $from_location = $item['current_location'];
        
        // Start transaction
        $mysqli->begin_transaction();
        
        try {
            // Update laundry item quantity if needed
            if ($action == 'checkout' || $action == 'checkout_laundry') {
                $update_sql = "UPDATE laundry_items 
                              SET current_location = ?, 
                                  quantity = quantity - ?,
                                  updated_at = NOW(),
                                  updated_by = ?
                              WHERE laundry_id = ?";
                
                $update_stmt = $mysqli->prepare($update_sql);
                $update_stmt->bind_param("siii", $to_location, $quantity, $user_id, $laundry_id);
                $update_stmt->execute();
            } else {
                $update_sql = "UPDATE laundry_items 
                              SET current_location = ?, 
                                  updated_at = NOW(),
                                  updated_by = ?
                              WHERE laundry_id = ?";
                
                $update_stmt = $mysqli->prepare($update_sql);
                $update_stmt->bind_param("sii", $to_location, $user_id, $laundry_id);
                $update_stmt->execute();
            }
            
            // Create transaction record
            $transaction_sql = "INSERT INTO laundry_transactions 
                               (laundry_id, transaction_type, quantity, from_location, to_location, 
                                performed_by, performed_for, performed_for_type, department, 
                                expected_return_date, notes) 
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $transaction_stmt = $mysqli->prepare($transaction_sql);
            $transaction_stmt->bind_param("isisssiisss", 
                $laundry_id, 
                $action, 
                $quantity, 
                $from_location, 
                $to_location, 
                $user_id, 
                $performed_for, 
                $performed_for_type,
                $department, 
                $expected_return_date, 
                $notes
            );
            $transaction_stmt->execute();
            
            // Log activity
            $log_description = "Laundry item checkout: {$item['asset_name']} (Qty: $quantity) to $to_location";
            mysqli_query($mysqli, "INSERT INTO logs SET log_type = 'Laundry', log_action = 'Checkout', log_description = '$log_description', log_ip = '$session_ip', log_user_agent = '$session_user_agent', log_user_id = $session_user_id");
            
            $mysqli->commit();
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Item checked out successfully";
            
            header("Location: laundry_view.php?id=" . $laundry_id);
            exit();
            
        } catch (Exception $e) {
            $mysqli->rollback();
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error during checkout: " . $e->getMessage();
            header("Location: laundry_checkout_new.php?laundry_id=" . $laundry_id);
            exit;
        }
    } else {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = implode("<br>", $errors);
        header("Location: laundry_checkout_new.php?laundry_id=" . $laundry_id);
        exit;
    }
}
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-exchange-alt mr-2"></i>Checkout Laundry Item
        </h3>
        <div class="card-tools">
            <a href="laundry_view.php?id=<?php echo $laundry_id; ?>" class="btn btn-light">
                <i class="fas fa-eye mr-2"></i>View Item
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
                    <!-- Item Information -->
                    <div class="card card-primary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-cube mr-2"></i>Item Information</h3>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-8">
                                    <h4><?php echo htmlspecialchars($item['asset_name']); ?></h4>
                                    <p class="text-muted mb-2">
                                        <i class="fas fa-tag mr-1"></i>
                                        <?php echo htmlspecialchars($item['asset_tag']); ?>
                                        <?php if ($item['serial_number']): ?>
                                            | <i class="fas fa-barcode mr-1"></i>
                                            <?php echo htmlspecialchars($item['serial_number']); ?>
                                        <?php endif; ?>
                                    </p>
                                    <p class="mb-0">
                                        <span class="badge" style="background-color: <?php echo $item['category_color'] ?: '#6c757d'; ?>;">
                                            <?php echo htmlspecialchars($item['category_name']); ?>
                                        </span>
                                        <span class="badge badge-light ml-2">
                                            <i class="fas fa-cube mr-1"></i>
                                            <?php echo htmlspecialchars($item['asset_category']); ?>
                                        </span>
                                    </p>
                                </div>
                                <div class="col-md-4 text-right">
                                    <div class="h1 text-primary"><?php echo $item['current_quantity']; ?></div>
                                    <small class="text-muted">Available in Stock</small>
                                </div>
                            </div>
                            
                            <div class="row mt-3">
                                <div class="col-md-4">
                                    <div class="info-box bg-light">
                                        <span class="info-box-icon"><i class="fas fa-map-marker-alt"></i></span>
                                        <div class="info-box-content">
                                            <span class="info-box-text">Current Location</span>
                                            <span class="info-box-number">
                                                <?php echo ucfirst(str_replace('_', ' ', $item['current_location'])); ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="info-box bg-light">
                                        <span class="info-box-icon"><i class="fas fa-heartbeat"></i></span>
                                        <div class="info-box-content">
                                            <span class="info-box-text">Status</span>
                                            <span class="info-box-number">
                                                <span class="badge badge-<?php 
                                                    switch($item['status']) {
                                                        case 'clean': echo 'success'; break;
                                                        case 'dirty': echo 'warning'; break;
                                                        default: echo 'secondary';
                                                    }
                                                ?>">
                                                    <?php echo ucfirst($item['status']); ?>
                                                </span>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="info-box bg-light">
                                        <span class="info-box-icon"><i class="fas fa-tint"></i></span>
                                        <div class="info-box-content">
                                            <span class="info-box-text">Last Washed</span>
                                            <span class="info-box-number">
                                                <?php if ($item['last_washed_date']): ?>
                                                    <?php echo $item['days_since_last_wash']; ?> days ago
                                                <?php else: ?>
                                                    Never
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Checkout Details -->
                    <div class="card card-warning">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-clipboard-check mr-2"></i>Checkout Details</h3>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="action">Action Type *</label>
                                        <select class="form-control" id="action" name="action" required onchange="updateCheckoutForm()">
                                            <option value="checkout" <?php echo $default_action == 'checkout' ? 'selected' : ''; ?>>Checkout to Department</option>
                                            <option value="checkout_laundry" <?php echo $default_action == 'checkout_laundry' ? 'selected' : ''; ?>>Send to Laundry</option>
                                            <option value="checkin" <?php echo $default_action == 'checkin' ? 'selected' : ''; ?>>Return to Storage</option>
                                            <option value="checkin_laundry" <?php echo $default_action == 'checkin_laundry' ? 'selected' : ''; ?>>Receive from Laundry</option>
                                            <option value="transfer">Transfer to Another Location</option>
                                        </select>
                                        <small class="form-text text-muted">Type of checkout action</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="to_location">Destination *</label>
                                        <select class="form-control" id="to_location" name="to_location" required>
                                            <option value="">- Select Destination -</option>
                                            <option value="clinic" <?php echo $default_to_location == 'clinic' ? 'selected' : ''; ?>>Clinic/Department</option>
                                            <option value="laundry" <?php echo $default_to_location == 'laundry' ? 'selected' : ''; ?>>Laundry Room</option>
                                            <option value="storage" <?php echo $default_to_location == 'storage' ? 'selected' : ''; ?>>Storage</option>
                                            <option value="in_transit">In Transit</option>
                                            <option value="ward">Ward</option>
                                            <option value="or">Operating Room</option>
                                            <option value="er">Emergency Room</option>
                                        </select>
                                        <small class="form-text text-muted">Where the item is going</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="quantity">Quantity *</label>
                                        <input type="number" class="form-control" id="quantity" name="quantity" 
                                               value="1" min="1" max="<?php echo $item['current_quantity']; ?>" required>
                                        <small class="form-text text-muted">
                                            Max: <?php echo $item['current_quantity']; ?> items available
                                        </small>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="department">Department *</label>
                                        <select class="form-control select2" id="department" name="department" required>
                                            <option value="">- Select Department -</option>
                                            <option value="emergency">Emergency Room</option>
                                            <option value="operating_room">Operating Room</option>
                                            <option value="icu">Intensive Care Unit (ICU)</option>
                                            <option value="ward">General Ward</option>
                                            <option value="maternity">Maternity Ward</option>
                                            <option value="pediatric">Pediatric Ward</option>
                                            <option value="surgical">Surgical Ward</option>
                                            <option value="outpatient">Outpatient Clinic</option>
                                            <option value="lab">Laboratory</option>
                                            <option value="radiology">Radiology</option>
                                            <option value="pharmacy">Pharmacy</option>
                                            <option value="administration">Administration</option>
                                            <option value="maintenance">Maintenance</option>
                                        </select>
                                        <small class="form-text text-muted">Destination department</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="expected_return_date">Expected Return Date</label>
                                        <input type="date" class="form-control" id="expected_return_date" 
                                               name="expected_return_date" min="<?php echo date('Y-m-d'); ?>">
                                        <small class="form-text text-muted">When should this item be returned?</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Assignment Information -->
                    <div class="card card-info">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-user-check mr-2"></i>Assignment Information</h3>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="performed_for_type">Assign To</label>
                                        <select class="form-control" id="performed_for_type" name="performed_for_type" onchange="updateAssignmentOptions()">
                                            <option value="">- Select Type -</option>
                                            <option value="patient">Patient</option>
                                            <option value="staff">Staff Member</option>
                                            <option value="room">Room/Bed</option>
                                            <option value="other">Other</option>
                                        </select>
                                        <small class="form-text text-muted">Who is this item assigned to?</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="performed_for">Specific Assignment</label>
                                        <select class="form-control select2" id="performed_for" name="performed_for" style="width: 100%;">
                                            <option value="">- Select Assignment -</option>
                                            <!-- Options populated by JavaScript -->
                                        </select>
                                        <small class="form-text text-muted">Specific patient, staff, or location</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Notes -->
                    <div class="card card-success">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-clipboard mr-2"></i>Notes & Instructions</h3>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label for="notes">Special Instructions / Notes</label>
                                <textarea class="form-control" id="notes" name="notes" rows="3" 
                                          placeholder="Any special instructions, care requirements, or notes for this checkout..."></textarea>
                                <small class="form-text text-muted">Additional information about this checkout</small>
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
                                <button type="submit" class="btn btn-success btn-lg" id="submitBtn">
                                    <i class="fas fa-check mr-2"></i>Complete Checkout
                                </button>
                                <button type="button" class="btn btn-outline-secondary" onclick="quickAssign('ward')">
                                    <i class="fas fa-procedures mr-2"></i>Ward Use
                                </button>
                                <button type="button" class="btn btn-outline-info" onclick="quickAssign('laundry')">
                                    <i class="fas fa-tint mr-2"></i>Send to Laundry
                                </button>
                                <button type="button" class="btn btn-outline-warning" onclick="quickReturn()">
                                    <i class="fas fa-undo mr-2"></i>Return to Storage
                                </button>
                                <a href="laundry_view.php?id=<?php echo $laundry_id; ?>" class="btn btn-outline-danger">
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

                    <!-- Checkout Preview -->
                    <div class="card card-info">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-eye mr-2"></i>Checkout Preview</h3>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-3">
                                <i class="fas fa-exchange-alt fa-3x text-info mb-2"></i>
                                <h5 id="preview_item"><?php echo htmlspecialchars($item['asset_name']); ?></h5>
                                <div id="preview_quantity" class="text-muted">1 item</div>
                            </div>
                            <hr>
                            <div class="small">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>From:</span>
                                    <span id="preview_from" class="font-weight-bold">
                                        <?php echo ucfirst(str_replace('_', ' ', $item['current_location'])); ?>
                                    </span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>To:</span>
                                    <span id="preview_to" class="font-weight-bold">Clinic</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Action:</span>
                                    <span id="preview_action" class="font-weight-bold">Checkout</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Department:</span>
                                    <span id="preview_dept" class="font-weight-bold">-</span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Status:</span>
                                    <span class="badge badge-warning">Pending</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Department History -->
                    <div class="card card-warning">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-history mr-2"></i>Department History</h3>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($dept_stats)): ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($dept_stats as $stat): ?>
                                        <div class="list-group-item px-0 py-2">
                                            <div class="d-flex w-100 justify-content-between align-items-center">
                                                <div>
                                                    <h6 class="mb-1"><?php echo ucfirst(str_replace('_', ' ', $stat['department'])); ?></h6>
                                                    <small class="text-muted">
                                                        <?php echo $stat['transaction_count']; ?> transaction<?php echo $stat['transaction_count'] != 1 ? 's' : ''; ?>
                                                    </small>
                                                </div>
                                                <span class="badge badge-light">
                                                    <?php echo date('M j', strtotime($stat['last_transaction'])); ?>
                                                </span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted text-center mb-0">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    No department history
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Recent Transactions -->
                    <div class="card card-secondary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-stream mr-2"></i>Recent Activity</h3>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($recent_transactions)): ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($recent_transactions as $trans): ?>
                                        <div class="list-group-item px-0 py-2">
                                            <div class="d-flex w-100 justify-content-between align-items-center">
                                                <div>
                                                    <h6 class="mb-1"><?php echo ucfirst($trans['transaction_type']); ?></h6>
                                                    <small class="text-muted">
                                                        <?php echo date('M j', strtotime($trans['transaction_date'])); ?>
                                                    </small>
                                                </div>
                                                <span class="badge badge-light">
                                                    <?php echo $trans['quantity']; ?> item<?php echo $trans['quantity'] != 1 ? 's' : ''; ?>
                                                </span>
                                            </div>
                                            <?php if ($trans['patient_first_name']): ?>
                                                <small class="text-muted">
                                                    <i class="fas fa-user mr-1"></i>
                                                    <?php echo htmlspecialchars($trans['patient_first_name'] . ' ' . $trans['patient_last_name']); ?>
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted text-center mb-0">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    No recent transactions
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
                                <strong>Clean Items Only:</strong> Only clean items should be checked out to departments.
                            </div>
                            <div class="callout callout-warning small mb-3">
                                <i class="fas fa-exclamation-triangle mr-2"></i>
                                <strong>Quantity Check:</strong> Ensure sufficient quantity is available before checkout.
                            </div>
                            <div class="callout callout-success small">
                                <i class="fas fa-check-circle mr-2"></i>
                                <strong>Return Dates:</strong> Set expected return dates for tracking purposes.
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
    
    // Update preview when action changes
    $('#action').on('change', function() {
        $('#preview_action').text($(this).find('option:selected').text());
        updateCheckoutForm();
    });
    
    // Update preview when destination changes
    $('#to_location').on('change', function() {
        $('#preview_to').text($(this).find('option:selected').text());
    });
    
    // Update preview when quantity changes
    $('#quantity').on('input', function() {
        var quantity = parseInt($(this).val()) || 1;
        var text = quantity + ' item' + (quantity !== 1 ? 's' : '');
        $('#preview_quantity').text(text);
    });
    
    // Update preview when department changes
    $('#department').on('change', function() {
        $('#preview_dept').text($(this).find('option:selected').text() || '-');
    });
    
    // Set default expected return date (3 days from now)
    var threeDays = new Date();
    threeDays.setDate(threeDays.getDate() + 3);
    $('#expected_return_date').val(threeDays.toISOString().split('T')[0]);
    
    // Enhanced form validation
    $('#checkoutForm').on('submit', function(e) {
        var requiredFields = ['action', 'to_location', 'quantity', 'department'];
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
        var maxQuantity = <?php echo $item['current_quantity']; ?>;
        
        if (quantity < 1) {
            isValid = false;
            errorMessages.push('Quantity must be at least 1');
            $('#quantity').addClass('is-invalid');
        } else if (quantity > maxQuantity) {
            isValid = false;
            errorMessages.push('Quantity cannot exceed available stock (' + maxQuantity + ')');
            $('#quantity').addClass('is-invalid');
        } else {
            $('#quantity').removeClass('is-invalid');
        }
        
        // Validate action-specific rules
        var action = $('#action').val();
        var currentStatus = '<?php echo $item['status']; ?>';
        var currentLocation = '<?php echo $item['current_location']; ?>';
        
        if (action === 'checkout' && currentStatus !== 'clean') {
            isValid = false;
            errorMessages.push('Only clean items can be checked out to departments');
            $('#action').addClass('is-invalid');
        }
        
        if (action === 'checkin_laundry' && currentLocation !== 'laundry') {
            isValid = false;
            errorMessages.push('Item must be in laundry to be received from laundry');
            $('#action').addClass('is-invalid');
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
    updateCheckoutForm();
});

function updateCheckoutForm() {
    var action = $('#action').val();
    var toLocation = $('#to_location');
    
    // Update destination options based on action
    switch(action) {
        case 'checkout':
            toLocation.val('clinic');
            $('#preview_to').text('Clinic');
            $('#preview_action').text('Checkout to Department');
            break;
        case 'checkout_laundry':
            toLocation.val('laundry');
            $('#preview_to').text('Laundry Room');
            $('#preview_action').text('Send to Laundry');
            break;
        case 'checkin':
            toLocation.val('storage');
            $('#preview_to').text('Storage');
            $('#preview_action').text('Return to Storage');
            break;
        case 'checkin_laundry':
            toLocation.val('storage');
            $('#preview_to').text('Storage');
            $('#preview_action').text('Receive from Laundry');
            break;
        case 'transfer':
            $('#preview_action').text('Transfer');
            break;
    }
    
    // Trigger change event to update preview
    toLocation.trigger('change');
}

function updateAssignmentOptions() {
    var type = $('#performed_for_type').val();
    var select = $('#performed_for');
    
    select.empty();
    
    if (type === 'patient') {
        select.append(new Option('- Select Patient -', ''));
        <?php foreach ($patients as $patient): ?>
            select.append(new Option(
                '<?php echo htmlspecialchars($patient["patient_last_name"] . ", " . $patient["patient_first_name"] . " (MRN: " . $patient["patient_mrn"] . ")"); ?>',
                '<?php echo $patient["patient_id"]; ?>'
            ));
        <?php endforeach; ?>
    } else if (type === 'staff') {
        select.append(new Option('- Select Staff -', ''));
        // In a real implementation, this would be populated from a staff table
        select.append(new Option('Dr. Smith (Attending Physician)', 'staff_1'));
        select.append(new Option('Nurse Johnson (RN)', 'staff_2'));
        select.append(new Option('Dr. Williams (Surgeon)', 'staff_3'));
    } else if (type === 'room') {
        select.append(new Option('- Select Room/Bed -', ''));
        select.append(new Option('Room 101 - Bed A', 'room_101a'));
        select.append(new Option('Room 101 - Bed B', 'room_101b'));
        select.append(new Option('Room 102 - Bed A', 'room_102a'));
        select.append(new Option('ICU Room 1', 'icu_1'));
        select.append(new Option('OR Room 3', 'or_3'));
    } else {
        select.append(new Option('- Select Assignment -', ''));
    }
    
    // Reinitialize Select2
    select.select2();
}

function quickAssign(destination) {
    if (destination === 'ward') {
        $('#action').val('checkout');
        $('#to_location').val('ward');
        $('#department').val('ward');
        $('#expected_return_date').val('');
    } else if (destination === 'laundry') {
        $('#action').val('checkout_laundry');
        $('#to_location').val('laundry');
        $('#department').val('laundry');
        $('#expected_return_date').val('');
    }
    
    updateCheckoutForm();
    $('#department').trigger('change');
}

function quickReturn() {
    $('#action').val('checkin');
    $('#to_location').val('storage');
    $('#department').val('storage');
    $('#expected_return_date').val('');
    
    updateCheckoutForm();
    $('#department').trigger('change');
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
        window.location.href = 'laundry_management.php';
    }
    // Ctrl + 1 for ward checkout
    if (e.ctrlKey && e.keyCode === 49) {
        e.preventDefault();
        quickAssign('ward');
    }
    // Ctrl + 2 for laundry
    if (e.ctrlKey && e.keyCode === 50) {
        e.preventDefault();
        quickAssign('laundry');
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

.info-box {
    margin-bottom: 10px;
}

.d-grid {
    display: grid;
    gap: 10px;
}

.select2-container .select2-selection--single {
    height: 38px;
}

.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 36px;
}

.list-group-flush .list-group-item {
    padding: 0.5rem 0;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
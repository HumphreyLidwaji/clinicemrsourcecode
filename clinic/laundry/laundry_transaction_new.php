<?php
// laundry_transaction_new.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

$page_title = "Add Transaction";

// Get laundry ID
if (!isset($_GET['laundry_id']) || !is_numeric($_GET['laundry_id'])) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid laundry item ID";
    header("Location: laundry_management.php");
    exit();
}

$laundry_id = intval($_GET['laundry_id']);

// Get laundry item details with enhanced information
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
           li.wash_count,
           li.last_washed_date,
           DATEDIFF(CURDATE(), li.last_washed_date) as days_since_last_wash,
           (SELECT COUNT(*) FROM laundry_transactions WHERE laundry_id = li.laundry_id) as transaction_count,
           (SELECT COUNT(*) FROM laundry_transactions WHERE laundry_id = li.laundry_id AND transaction_type = 'damage') as damage_count,
           (SELECT COUNT(*) FROM laundry_transactions WHERE laundry_id = li.laundry_id AND transaction_type = 'lost') as lost_count
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

// Get available patients
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

// Get transaction statistics
$transaction_stats_sql = "
    SELECT 
        transaction_type,
        COUNT(*) as type_count,
        MAX(transaction_date) as last_transaction
    FROM laundry_transactions
    WHERE laundry_id = ?
    GROUP BY transaction_type
    ORDER BY type_count DESC
";
$transaction_stats_stmt = $mysqli->prepare($transaction_stats_sql);
$transaction_stats_stmt->bind_param("i", $laundry_id);
$transaction_stats_stmt->execute();
$transaction_stats_result = $transaction_stats_stmt->get_result();
$transaction_stats = [];
while ($stat = $transaction_stats_result->fetch_assoc()) {
    $transaction_stats[] = $stat;
}

// Get recent transaction history
$recent_history_sql = "
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
$recent_history_stmt = $mysqli->prepare($recent_history_sql);
$recent_history_stmt->bind_param("i", $laundry_id);
$recent_history_stmt->execute();
$recent_history_result = $recent_history_stmt->get_result();
$recent_history = [];
while ($transaction = $recent_history_result->fetch_assoc()) {
    $recent_history[] = $transaction;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    
    if ($csrf_token != $_SESSION['csrf_token']) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "CSRF token validation failed";
        header("Location: laundry_transaction_new.php?laundry_id=" . $laundry_id);
        exit;
    }
    
    $transaction_type = sanitizeInput($_POST['transaction_type']);
    $from_location = sanitizeInput($_POST['from_location']);
    $to_location = sanitizeInput($_POST['to_location']);
    $performed_for = !empty($_POST['performed_for']) ? intval($_POST['performed_for']) : NULL;
    $performed_for_type = sanitizeInput($_POST['performed_for_type']);
    $department = sanitizeInput($_POST['department']);
    $quantity = intval($_POST['quantity']);
    $notes = sanitizeInput($_POST['notes']);
    $condition_before = sanitizeInput($_POST['condition_before']);
    $condition_after = sanitizeInput($_POST['condition_after']);
    
    $user_id = intval($_SESSION['user_id']);
    
    // Validate
    $errors = [];
    
    if (empty($transaction_type)) {
        $errors[] = "Transaction type is required";
    }
    if ($quantity < 1) {
        $errors[] = "Quantity must be at least 1";
    }
    if ($quantity > $item['current_quantity']) {
        $errors[] = "Quantity cannot exceed available stock (" . $item['current_quantity'] . ")";
    }
    if (empty($notes)) {
        $errors[] = "Notes are required";
    }
    
    if (empty($errors)) {
        // Start transaction
        $mysqli->begin_transaction();
        
        try {
            // Update item location if provided and different
            if (!empty($from_location) && !empty($to_location) && $from_location != $to_location) {
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
                                condition_before, condition_after, notes) 
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $transaction_stmt = $mysqli->prepare($transaction_sql);
            $transaction_stmt->bind_param("isisssiissss", 
                $laundry_id, 
                $transaction_type, 
                $quantity, 
                $from_location, 
                $to_location, 
                $user_id, 
                $performed_for, 
                $performed_for_type,
                $department, 
                $condition_before, 
                $condition_after, 
                $notes
            );
            $transaction_stmt->execute();
            
            // Update item status based on transaction type
            $status_updates = [];
            
            switch($transaction_type) {
                case 'damage':
                    $status_updates['status'] = 'damaged';
                    break;
                case 'lost':
                    $status_updates['status'] = 'lost';
                    break;
                case 'found':
                    $status_updates['status'] = 'clean';
                    break;
                case 'wash':
                    $status_updates['status'] = 'clean';
                    $status_updates['wash_count'] = 'wash_count + 1';
                    $status_updates['last_washed_date'] = 'CURDATE()';
                    break;
                case 'repair':
                    $status_updates['status'] = 'clean';
                    break;
            }
            
            // Update item condition if changed
            if (!empty($condition_after) && $condition_after != $item['item_condition']) {
                $status_updates['item_condition'] = "'$condition_after'";
            }
            
            // Apply status updates if any
            if (!empty($status_updates)) {
                $update_fields = [];
                foreach ($status_updates as $field => $value) {
                    $update_fields[] = "$field = $value";
                }
                
                $status_sql = "UPDATE laundry_items SET " . implode(', ', $update_fields) . " WHERE laundry_id = ?";
                $status_stmt = $mysqli->prepare($status_sql);
                $status_stmt->bind_param("i", $laundry_id);
                $status_stmt->execute();
            }
            
            // Log activity
            $log_description = "Transaction added: {$item['asset_name']} - " . ucfirst($transaction_type);
            mysqli_query($mysqli, "INSERT INTO logs SET log_type = 'Laundry', log_action = 'Transaction', log_description = '$log_description', log_ip = '$session_ip', log_user_agent = '$session_user_agent', log_user_id = $session_user_id");
            
            $mysqli->commit();
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Transaction added successfully";
            
            header("Location: laundry_view.php?id=" . $laundry_id);
            exit();
            
        } catch (Exception $e) {
            $mysqli->rollback();
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error adding transaction: " . $e->getMessage();
            header("Location: laundry_transaction_new.php?laundry_id=" . $laundry_id);
            exit;
        }
    } else {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = implode("<br>", $errors);
        header("Location: laundry_transaction_new.php?laundry_id=" . $laundry_id);
        exit;
    }
}
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-exchange-alt mr-2"></i>Add Transaction
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
        
        <form method="POST" id="transactionForm" autocomplete="off">
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
                                    <p class="mb-3">
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
                                <div class="col-md-3">
                                    <div class="info-box bg-light">
                                        <span class="info-box-icon"><i class="fas fa-heartbeat"></i></span>
                                        <div class="info-box-content">
                                            <span class="info-box-text">Status</span>
                                            <span class="info-box-number">
                                                <span class="badge badge-<?php 
                                                    switch($item['status']) {
                                                        case 'clean': echo 'success'; break;
                                                        case 'dirty': echo 'warning'; break;
                                                        case 'damaged': echo 'danger'; break;
                                                        case 'lost': echo 'dark'; break;
                                                        default: echo 'secondary';
                                                    }
                                                ?>">
                                                    <?php echo ucfirst($item['status']); ?>
                                                </span>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="info-box bg-light">
                                        <span class="info-box-icon"><i class="fas fa-map-marker-alt"></i></span>
                                        <div class="info-box-content">
                                            <span class="info-box-text">Location</span>
                                            <span class="info-box-number">
                                                <?php echo ucfirst(str_replace('_', ' ', $item['current_location'])); ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="info-box bg-light">
                                        <span class="info-box-icon"><i class="fas fa-tint"></i></span>
                                        <div class="info-box-content">
                                            <span class="info-box-text">Wash Count</span>
                                            <span class="info-box-number"><?php echo $item['wash_count']; ?></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="info-box bg-light">
                                        <span class="info-box-icon"><i class="fas fa-history"></i></span>
                                        <div class="info-box-content">
                                            <span class="info-box-text">Transactions</span>
                                            <span class="info-box-number"><?php echo $item['transaction_count']; ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Transaction Details -->
                    <div class="card card-warning">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-clipboard-check mr-2"></i>Transaction Details</h3>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="transaction_type">Transaction Type *</label>
                                        <select class="form-control" id="transaction_type" name="transaction_type" required onchange="updateTransactionForm()">
                                            <option value="">- Select Type -</option>
                                            <option value="checkout">Checkout</option>
                                            <option value="checkin">Checkin</option>
                                            <option value="wash">Wash</option>
                                            <option value="damage">Damage Report</option>
                                            <option value="lost">Lost Report</option>
                                            <option value="found">Found Report</option>
                                            <option value="repair">Repair</option>
                                            <option value="maintenance">Maintenance</option>
                                            <option value="inspection">Inspection</option>
                                            <option value="retirement">Retirement</option>
                                            <option value="disposal">Disposal</option>
                                            <option value="adjustment">Inventory Adjustment</option>
                                            <option value="other">Other</option>
                                        </select>
                                        <small class="form-text text-muted">Type of transaction</small>
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
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="department">Department</label>
                                        <select class="form-control select2" id="department" name="department">
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
                                            <option value="laundry">Laundry</option>
                                            <option value="storage">Storage</option>
                                            <option value="maintenance">Maintenance</option>
                                            <option value="administration">Administration</option>
                                        </select>
                                        <small class="form-text text-muted">Related department</small>
                                    </div>
                                </div>
                            </div>

                            <div class="row mt-3">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="from_location">From Location</label>
                                        <select class="form-control" id="from_location" name="from_location">
                                            <option value="">- Select From Location -</option>
                                            <option value="storage" <?php echo $item['current_location'] == 'storage' ? 'selected' : ''; ?>>Storage</option>
                                            <option value="clinic" <?php echo $item['current_location'] == 'clinic' ? 'selected' : ''; ?>>Clinic</option>
                                            <option value="laundry" <?php echo $item['current_location'] == 'laundry' ? 'selected' : ''; ?>>Laundry</option>
                                            <option value="in_transit" <?php echo $item['current_location'] == 'in_transit' ? 'selected' : ''; ?>>In Transit</option>
                                            <option value="ward">Ward</option>
                                            <option value="or">Operating Room</option>
                                            <option value="er">Emergency Room</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="to_location">To Location</label>
                                        <select class="form-control" id="to_location" name="to_location">
                                            <option value="">- Select To Location -</option>
                                            <option value="storage">Storage</option>
                                            <option value="clinic">Clinic</option>
                                            <option value="laundry">Laundry</option>
                                            <option value="in_transit">In Transit</option>
                                            <option value="ward">Ward</option>
                                            <option value="or">Operating Room</option>
                                            <option value="er">Emergency Room</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="row mt-3">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="condition_before">Condition Before</label>
                                        <select class="form-control" id="condition_before" name="condition_before">
                                            <option value="">- Select Condition -</option>
                                            <option value="excellent" <?php echo $item['item_condition'] == 'excellent' ? 'selected' : ''; ?>>Excellent</option>
                                            <option value="good" <?php echo $item['item_condition'] == 'good' ? 'selected' : ''; ?>>Good</option>
                                            <option value="fair" <?php echo $item['item_condition'] == 'fair' ? 'selected' : ''; ?>>Fair</option>
                                            <option value="poor" <?php echo $item['item_condition'] == 'poor' ? 'selected' : ''; ?>>Poor</option>
                                            <option value="critical" <?php echo $item['item_condition'] == 'critical' ? 'selected' : ''; ?>>Critical</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="condition_after">Condition After</label>
                                        <select class="form-control" id="condition_after" name="condition_after">
                                            <option value="">- Select Condition -</option>
                                            <option value="excellent">Excellent</option>
                                            <option value="good">Good</option>
                                            <option value="fair">Fair</option>
                                            <option value="poor">Poor</option>
                                            <option value="critical">Critical</option>
                                        </select>
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
                                        <label for="performed_for_type">Assigned To</label>
                                        <select class="form-control" id="performed_for_type" name="performed_for_type" onchange="updateAssignmentOptions()">
                                            <option value="">- Select Type -</option>
                                            <option value="patient">Patient</option>
                                            <option value="staff">Staff Member</option>
                                            <option value="room">Room/Bed</option>
                                            <option value="equipment">Medical Equipment</option>
                                            <option value="other">Other</option>
                                        </select>
                                        <small class="form-text text-muted">Who/what is this related to?</small>
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

                    <!-- Notes & Details -->
                    <div class="card card-success">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-clipboard mr-2"></i>Notes & Details</h3>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label for="notes">Transaction Details *</label>
                                <textarea class="form-control" id="notes" name="notes" rows="4" required
                                          placeholder="Describe the transaction in detail. Include reasons, observations, actions taken, and any follow-up required..."></textarea>
                                <small class="form-text text-muted">Detailed description of the transaction</small>
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
                                    <i class="fas fa-save mr-2"></i>Add Transaction
                                </button>
                                <button type="button" class="btn btn-outline-danger" onclick="quickTransaction('damage')">
                                    <i class="fas fa-times-circle mr-2"></i>Report Damage
                                </button>
                                <button type="button" class="btn btn-outline-dark" onclick="quickTransaction('lost')">
                                    <i class="fas fa-question-circle mr-2"></i>Report Lost
                                </button>
                                <button type="button" class="btn btn-outline-success" onclick="quickTransaction('found')">
                                    <i class="fas fa-check-circle mr-2"></i>Report Found
                                </button>
                                <button type="button" class="btn btn-outline-info" onclick="quickTransaction('wash')">
                                    <i class="fas fa-tint mr-2"></i>Record Wash
                                </button>
                                <button type="button" class="btn btn-outline-warning" onclick="quickTransaction('repair')">
                                    <i class="fas fa-tools mr-2"></i>Record Repair
                                </button>
                                <a href="laundry_view.php?id=<?php echo $laundry_id; ?>" class="btn btn-outline-secondary">
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

                    <!-- Transaction Preview -->
                    <div class="card card-info">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-eye mr-2"></i>Transaction Preview</h3>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-3">
                                <i class="fas fa-exchange-alt fa-3x text-info mb-2"></i>
                                <h5 id="preview_type">Select Type</h5>
                                <div id="preview_item" class="text-muted small"><?php echo htmlspecialchars($item['asset_name']); ?></div>
                            </div>
                            <hr>
                            <div class="small">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Quantity:</span>
                                    <span id="preview_quantity" class="font-weight-bold">1</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>From:</span>
                                    <span id="preview_from" class="font-weight-bold">
                                        <?php echo ucfirst(str_replace('_', ' ', $item['current_location'])); ?>
                                    </span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>To:</span>
                                    <span id="preview_to" class="font-weight-bold">-</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Condition:</span>
                                    <span id="preview_condition" class="font-weight-bold">
                                        <?php echo ucfirst($item['item_condition']); ?> →
                                    </span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Status:</span>
                                    <span class="badge badge-warning">Draft</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Transaction Statistics -->
                    <div class="card card-warning">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-chart-bar mr-2"></i>Transaction Statistics</h3>
                        </div>
                        <div class="card-body">
                            <div class="list-group list-group-flush">
                                <div class="list-group-item px-0 py-2">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1">Total Transactions</h6>
                                        <span class="badge badge-primary badge-pill"><?php echo $item['transaction_count']; ?></span>
                                    </div>
                                    <small class="text-muted">All transaction types</small>
                                </div>
                                <div class="list-group-item px-0 py-2">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1">Damage Reports</h6>
                                        <span class="badge badge-danger badge-pill"><?php echo $item['damage_count']; ?></span>
                                    </div>
                                    <small class="text-muted">Damage incidents</small>
                                </div>
                                <div class="list-group-item px-0 py-2">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1">Lost Items</h6>
                                        <span class="badge badge-dark badge-pill"><?php echo $item['lost_count']; ?></span>
                                    </div>
                                    <small class="text-muted">Lost incidents</small>
                                </div>
                                <div class="list-group-item px-0 py-2">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1">Wash Count</h6>
                                        <span class="badge badge-info badge-pill"><?php echo $item['wash_count']; ?></span>
                                    </div>
                                    <small class="text-muted">Total washes</small>
                                </div>
                                <?php if (!empty($transaction_stats)): ?>
                                    <?php foreach ($transaction_stats as $stat): ?>
                                        <div class="list-group-item px-0 py-2">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1"><?php echo ucfirst($stat['transaction_type']); ?></h6>
                                                <span class="badge badge-light badge-pill"><?php echo $stat['type_count']; ?></span>
                                            </div>
                                            <small class="text-muted">
                                                Last: <?php echo date('M j', strtotime($stat['last_transaction'])); ?>
                                            </small>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Recent History -->
                    <div class="card card-secondary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-history mr-2"></i>Recent History</h3>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($recent_history)): ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($recent_history as $trans): ?>
                                        <div class="list-group-item px-0 py-2">
                                            <div class="d-flex w-100 justify-content-between align-items-center">
                                                <div>
                                                    <h6 class="mb-1"><?php echo ucfirst($trans['transaction_type']); ?></h6>
                                                    <small class="text-muted">
                                                        <?php echo date('M j, g:i A', strtotime($trans['transaction_date'])); ?>
                                                    </small>
                                                </div>
                                                <span class="badge badge-light">
                                                    <?php echo $trans['quantity']; ?>
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
                                <strong>Detailed Notes:</strong> Include specific details, reasons, and follow-up actions.
                            </div>
                            <div class="callout callout-warning small mb-3">
                                <i class="fas fa-exclamation-triangle mr-2"></i>
                                <strong>Condition Tracking:</strong> Update condition before and after significant events.
                            </div>
                            <div class="callout callout-success small">
                                <i class="fas fa-check-circle mr-2"></i>
                                <strong>Location Updates:</strong> Always update locations for accurate tracking.
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
    
    // Update preview when transaction type changes
    $('#transaction_type').on('change', function() {
        var selectedText = $(this).find('option:selected').text();
        $('#preview_type').text(selectedText || 'Select Type');
        updateTransactionForm();
    });
    
    // Update preview when quantity changes
    $('#quantity').on('input', function() {
        var quantity = parseInt($(this).val()) || 1;
        $('#preview_quantity').text(quantity);
    });
    
    // Update preview when from location changes
    $('#from_location').on('change', function() {
        $('#preview_from').text($(this).find('option:selected').text());
    });
    
    // Update preview when to location changes
    $('#to_location').on('change', function() {
        $('#preview_to').text($(this).find('option:selected').text());
    });
    
    // Update preview when condition changes
    $('#condition_before, #condition_after').on('change', function() {
        var before = $('#condition_before').val() || '-';
        var after = $('#condition_after').val() || '-';
        $('#preview_condition').text(before + ' → ' + after);
    });
    
    // Enhanced form validation
    $('#transactionForm').on('submit', function(e) {
        var requiredFields = ['transaction_type', 'quantity', 'notes'];
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
        
        // Validate notes length
        var notes = $('#notes').val().trim();
        if (notes.length < 10) {
            isValid = false;
            errorMessages.push('Please provide more detailed notes (minimum 10 characters)');
            $('#notes').addClass('is-invalid');
        } else {
            $('#notes').removeClass('is-invalid');
        }
        
        if (!isValid) {
            e.preventDefault();
            var errorMessage = 'Please fix the following errors:\n• ' + errorMessages.join('\n• ');
            alert(errorMessage);
            return false;
        }
        
        // Show loading state
        $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Adding...').prop('disabled', true);
    });
    
    // Initialize preview
    updateTransactionForm();
});

// Transaction templates
const transactionTemplates = {
    'damage': {
        type: 'damage',
        department: 'maintenance',
        condition_before: '<?php echo $item["item_condition"]; ?>',
        condition_after: 'critical',
        notes: 'Item reported as damaged during routine inspection. Visual inspection reveals significant wear/tear. Requires immediate attention and possible replacement. Item removed from service until repaired or replaced.'
    },
    'lost': {
        type: 'lost',
        department: '',
        condition_before: '<?php echo $item["item_condition"]; ?>',
        condition_after: '',
        notes: 'Item reported as missing during routine inventory check. Last known location: <?php echo ucfirst($item["current_location"]); ?>. Search initiated. All departments notified to report if found.'
    },
    'found': {
        type: 'found',
        department: '',
        condition_before: '',
        condition_after: '<?php echo $item["item_condition"]; ?>',
        notes: 'Previously lost item found during routine cleaning. Item appears to be in good condition. Cleaned and returned to service.'
    },
    'wash': {
        type: 'wash',
        department: 'laundry',
        from_location: 'laundry',
        to_location: 'storage',
        condition_before: '<?php echo $item["item_condition"]; ?>',
        condition_after: '<?php echo $item["item_condition"]; ?>',
        notes: 'Item washed according to standard laundry procedures. Temperature: warm. Detergent: regular. Cycle: normal. Item cleaned, sanitized, and prepared for reuse.'
    },
    'repair': {
        type: 'repair',
        department: 'maintenance',
        condition_before: 'critical',
        condition_after: 'good',
        notes: 'Item repaired by maintenance staff. All issues addressed. Item tested and returned to service. Estimated remaining lifespan: 6-12 months.'
    }
};

function updateTransactionForm() {
    var transactionType = $('#transaction_type').val();
    
    // Set default values based on transaction type
    switch(transactionType) {
        case 'checkout':
            $('#from_location').val('storage');
            $('#to_location').val('clinic');
            $('#department').val('ward');
            $('#condition_before').val('<?php echo $item["item_condition"]; ?>');
            break;
        case 'checkin':
            $('#from_location').val('clinic');
            $('#to_location').val('storage');
            $('#department').val('ward');
            $('#condition_before').val('<?php echo $item["item_condition"]; ?>');
            break;
        case 'wash':
            $('#from_location').val('laundry');
            $('#to_location').val('storage');
            $('#department').val('laundry');
            break;
        case 'damage':
            $('#department').val('maintenance');
            $('#condition_before').val('<?php echo $item["item_condition"]; ?>');
            $('#condition_after').val('critical');
            break;
        case 'repair':
            $('#department').val('maintenance');
            $('#condition_before').val('critical');
            $('#condition_after').val('good');
            break;
    }
    
    // Trigger change events to update preview
    $('#from_location').trigger('change');
    $('#to_location').trigger('change');
    $('#condition_before').trigger('change');
    $('#condition_after').trigger('change');
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
        select.append(new Option('Dr. Smith (Attending Physician)', 'staff_1'));
        select.append(new Option('Nurse Johnson (RN)', 'staff_2'));
        select.append(new Option('Dr. Williams (Surgeon)', 'staff_3'));
        select.append(new Option('Nurse Davis (LPN)', 'staff_4'));
    } else if (type === 'room') {
        select.append(new Option('- Select Room/Bed -', ''));
        select.append(new Option('Room 101 - Bed A', 'room_101a'));
        select.append(new Option('Room 101 - Bed B', 'room_101b'));
        select.append(new Option('Room 102 - Bed A', 'room_102a'));
        select.append(new Option('ICU Room 1', 'icu_1'));
        select.append(new Option('OR Room 3', 'or_3'));
    } else if (type === 'equipment') {
        select.append(new Option('- Select Equipment -', ''));
        select.append(new Option('Ventilator #V-101', 'equip_1'));
        select.append(new Option('IV Pump #IV-205', 'equip_2'));
        select.append(new Option('Patient Monitor #PM-103', 'equip_3'));
    } else {
        select.append(new Option('- Select Assignment -', ''));
    }
    
    // Reinitialize Select2
    select.select2();
}

function quickTransaction(templateName) {
    var template = transactionTemplates[templateName];
    if (!template) return;
    
    $('#transaction_type').val(template.type);
    
    if (template.department) {
        $('#department').val(template.department);
    }
    
    if (template.from_location) {
        $('#from_location').val(template.from_location);
    }
    
    if (template.to_location) {
        $('#to_location').val(template.to_location);
    }
    
    if (template.condition_before) {
        $('#condition_before').val(template.condition_before);
    }
    
    if (template.condition_after) {
        $('#condition_after').val(template.condition_after);
    }
    
    if (template.notes) {
        $('#notes').val(template.notes);
    }
    
    updateTransactionForm();
    
    // Trigger change events
    $('#transaction_type').trigger('change');
    $('#condition_before').trigger('change');
    $('#condition_after').trigger('change');
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + S to save
    if (e.ctrlKey && e.keyCode === 83) {
        e.preventDefault();
        $('#transactionForm').submit();
    }
    // Escape to cancel
    if (e.keyCode === 27) {
        window.location.href = 'laundry_view.php?id=<?php echo $laundry_id; ?>';
    }
    // Ctrl + D for damage
    if (e.ctrlKey && e.keyCode === 68) {
        e.preventDefault();
        quickTransaction('damage');
    }
    // Ctrl + W for wash
    if (e.ctrlKey && e.keyCode === 87) {
        e.preventDefault();
        quickTransaction('wash');
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

.btn-lg, .btn-group-lg > .btn {
    padding: 0.75rem 1.5rem;
    font-size: 1.1rem;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
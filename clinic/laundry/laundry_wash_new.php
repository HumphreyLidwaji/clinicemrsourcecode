<?php
// laundry_wash_new.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

$page_title = "Start Wash Cycle";

// Initialize variables
$dirty_items = [];
$wash_stats = [];

// Get dirty laundry items
$dirty_items_sql = "
    SELECT li.*, a.asset_name, a.asset_tag, a.serial_number, 
           lc.category_name, 
           li.item_condition, li.wash_count, li.last_washed_date,
           li.next_wash_date, li.is_critical
    FROM laundry_items li
    LEFT JOIN assets a ON li.asset_id = a.asset_id
    LEFT JOIN laundry_categories lc ON li.category_id = lc.category_id
    WHERE li.status = 'dirty'
    ORDER BY li.next_wash_date ASC, li.last_washed_date ASC, a.asset_name
";
$dirty_items_result = $mysqli->query($dirty_items_sql);
while ($item = $dirty_items_result->fetch_assoc()) {
    $dirty_items[] = $item;
}

// Get wash statistics
$wash_stats_sql = "
    SELECT 
        COUNT(*) as total_cycles,
        SUM(items_washed) as total_items,
        SUM(CASE WHEN DATE(wash_date) = CURDATE() THEN 1 ELSE 0 END) as today_cycles,
        SUM(CASE WHEN DATE(wash_date) = CURDATE() THEN items_washed ELSE 0 END) as today_items,
        AVG(total_weight) as avg_weight,
        SUM(total_weight) as total_weight,
        SUM(CASE WHEN bleach_used = 1 THEN 1 ELSE 0 END) as bleach_cycles,
        SUM(CASE WHEN fabric_softener_used = 1 THEN 1 ELSE 0 END) as softener_cycles
    FROM laundry_wash_cycles
";
$wash_stats_result = $mysqli->query($wash_stats_sql);
$wash_stats = $wash_stats_result->fetch_assoc();

// Get recent wash cycles
$recent_cycles_sql = "
    SELECT wc.*, u.user_name, 
           COUNT(wci.laundry_id) as item_count,
           SUM(CASE WHEN a.asset_name IS NOT NULL THEN 1 ELSE 0 END) as asset_count
    FROM laundry_wash_cycles wc
    LEFT JOIN users u ON wc.completed_by = u.user_id
    LEFT JOIN wash_cycle_items wci ON wc.wash_id = wci.wash_id
    LEFT JOIN laundry_items li ON wci.laundry_id = li.laundry_id
    LEFT JOIN assets a ON li.asset_id = a.asset_id
    GROUP BY wc.wash_id
    ORDER BY wc.wash_date DESC, wc.wash_time DESC
    LIMIT 5
";
$recent_cycles_result = $mysqli->query($recent_cycles_sql);
$recent_cycles = [];
while ($cycle = $recent_cycles_result->fetch_assoc()) {
    $recent_cycles[] = $cycle;
}

// Get user information
$current_user_sql = "SELECT user_name, user_email FROM users WHERE user_id = ?";
$current_user_stmt = $mysqli->prepare($current_user_sql);
$current_user_stmt->bind_param("i", $session_user_id);
$current_user_stmt->execute();
$current_user_result = $current_user_stmt->get_result();
$current_user = $current_user_result->fetch_assoc();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    
    if ($csrf_token != $_SESSION['csrf_token']) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "CSRF token validation failed";
        header("Location: laundry_wash_new.php");
        exit;
    }
    
    $wash_date = sanitizeInput($_POST['wash_date']);
    $wash_time = sanitizeInput($_POST['wash_time']);
    $temperature = sanitizeInput($_POST['temperature']);
    $detergent_type = sanitizeInput($_POST['detergent_type']);
    $detergent_amount = sanitizeInput($_POST['detergent_amount']);
    $bleach_used = isset($_POST['bleach_used']) ? 1 : 0;
    $fabric_softener_used = isset($_POST['fabric_softener_used']) ? 1 : 0;
    $total_weight = floatval($_POST['total_weight']);
    $cycle_duration = intval($_POST['cycle_duration']);
    $water_level = sanitizeInput($_POST['water_level']);
    $spin_speed = sanitizeInput($_POST['spin_speed']);
    $notes = sanitizeInput($_POST['notes']);
    $laundry_ids = $_POST['laundry_ids'] ?? [];
    $items_washed = count($laundry_ids);
    
    $user_id = intval($_SESSION['user_id']);
    
    // Validate required fields
    $errors = [];
    
    if (empty($wash_date)) {
        $errors[] = "Wash date is required";
    }
    if (empty($wash_time)) {
        $errors[] = "Wash time is required";
    }
    if ($items_washed == 0) {
        $errors[] = "Please select at least one item to wash";
    }
    if (empty($temperature)) {
        $errors[] = "Temperature setting is required";
    }
    if (empty($detergent_type)) {
        $errors[] = "Detergent type is required";
    }
    
    // Validate weight
    if ($total_weight <= 0) {
        $errors[] = "Total weight must be greater than 0";
    }
    if ($total_weight > 50) {
        $errors[] = "Total weight cannot exceed 50kg";
    }
    
    // Validate dates
    $washDateTime = new DateTime($wash_date . ' ' . $wash_time);
    $now = new DateTime();
    
    if ($washDateTime > $now) {
        $errors[] = "Wash date/time cannot be in the future";
    }
    
    if (empty($errors)) {
        // Start transaction
        $mysqli->begin_transaction();
        
        try {
            // Insert wash cycle
            $sql = "INSERT INTO laundry_wash_cycles 
                   (wash_date, wash_time, completed_by, temperature, detergent_type, 
                    detergent_amount, bleach_used, fabric_softener_used, items_washed, 
                    total_weight, cycle_duration, water_level, spin_speed, notes) 
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param("ssissssiidiss", 
                $wash_date, 
                $wash_time, 
                $user_id, 
                $temperature, 
                $detergent_type,
                $detergent_amount,
                $bleach_used, 
                $fabric_softener_used, 
                $items_washed, 
                $total_weight,
                $cycle_duration,
                $water_level,
                $spin_speed,
                $notes
            );
            
            if (!$stmt->execute()) {
                throw new Exception("Error creating wash cycle: " . $mysqli->error);
            }
            
            $wash_id = $stmt->insert_id;
            
            // Process each laundry item
            $total_estimated_weight = 0;
            foreach ($laundry_ids as $laundry_id) {
                $laundry_id = intval($laundry_id);
                
                // Get item details
                foreach ($dirty_items as $item) {
                    if ($item['laundry_id'] == $laundry_id) {
                        $condition_before = $item['item_condition'];
                        $new_wash_count = $item['wash_count'] + 1;
                        
                        // Add to wash cycle items
                        $wash_item_sql = "INSERT INTO wash_cycle_items 
                                         (wash_id, laundry_id, quantity, condition_before, condition_after) 
                                         VALUES (?, ?, 1, ?, ?)";
                        $wash_item_stmt = $mysqli->prepare($wash_item_sql);
                        
                        // Determine condition after washing
                        $condition_after = $condition_before;
                        if ($condition_before == 'dirty') {
                            $condition_after = 'clean';
                        }
                        
                        $wash_item_stmt->bind_param("iiss", $wash_id, $laundry_id, $condition_before, $condition_after);
                        $wash_item_stmt->execute();
                        
                        // Update laundry item
                        $update_sql = "UPDATE laundry_items 
                                      SET status = 'clean', 
                                          item_condition = ?, 
                                          current_location = 'storage',
                                          last_washed_date = ?,
                                          wash_count = ?,
                                          next_wash_date = DATE_ADD(?, INTERVAL 7 DAY),
                                          updated_at = NOW(),
                                          updated_by = ?
                                      WHERE laundry_id = ?";
                        
                        $update_stmt = $mysqli->prepare($update_sql);
                        $update_stmt->bind_param("sssii", $condition_after, $wash_date, $new_wash_count, $wash_date, $user_id, $laundry_id);
                        $update_stmt->execute();
                        
                        // Create transaction record
                        $transaction_sql = "INSERT INTO laundry_transactions 
                                           (laundry_id, transaction_type, from_location, to_location, 
                                            performed_by, wash_id, notes) 
                                           VALUES (?, 'wash', 'laundry', 'storage', ?, ?, 'Wash cycle completed')";
                        $transaction_stmt = $mysqli->prepare($transaction_sql);
                        $transaction_stmt->bind_param("iii", $laundry_id, $user_id, $wash_id);
                        $transaction_stmt->execute();
                        
                        break;
                    }
                }
            }
            
            $mysqli->commit();
            
            // Log activity
            $log_description = "Wash cycle started: $items_washed items washed";
            mysqli_query($mysqli, "INSERT INTO logs SET log_type = 'Laundry', log_action = 'Wash', log_description = '$log_description', log_ip = '$session_ip', log_user_agent = '$session_user_agent', log_user_id = $session_user_id");
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Wash cycle completed successfully! $items_washed items washed.";
            
            header("Location: laundry_wash_view.php?id=" . $wash_id);
            exit();
            
        } catch (Exception $e) {
            $mysqli->rollback();
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error creating wash cycle: " . $e->getMessage();
            header("Location: laundry_wash_new.php");
            exit;
        }
    } else {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = implode("<br>", $errors);
        header("Location: laundry_wash_new.php");
        exit;
    }
}
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-tint mr-2"></i>Start New Wash Cycle
        </h3>
        <div class="card-tools">
            <a href="laundry_management.php" class="btn btn-light">
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
        
        <?php if (empty($dirty_items)): ?>
            <div class="text-center py-5">
                <i class="fas fa-check-circle fa-4x text-success mb-3"></i>
                <h4>No Dirty Items to Wash!</h4>
                <p class="text-muted">All laundry items are clean and ready for use.</p>
                <a href="laundry_management.php" class="btn btn-primary mt-3">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Laundry Management
                </a>
            </div>
        <?php else: ?>
        
        <form method="POST" id="washForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <div class="row">
                <div class="col-md-8">
                    <!-- Items Selection -->
                    <div class="card card-primary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-tshirt mr-2"></i>Select Items to Wash</h3>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <div class="btn-group btn-group-toggle" data-toggle="buttons">
                                    <label class="btn btn-outline-primary active" onclick="selectAllItems(true)">
                                        <input type="radio" name="options" autocomplete="off" checked> Select All
                                    </label>
                                    <label class="btn btn-outline-primary" onclick="selectAllItems(false)">
                                        <input type="radio" name="options" autocomplete="off"> Clear All
                                    </label>
                                    <label class="btn btn-outline-primary" onclick="selectCriticalItems()">
                                        <input type="radio" name="options" autocomplete="off"> Critical Only
                                    </label>
                                    <label class="btn btn-outline-primary" onclick="selectOverdueItems()">
                                        <input type="radio" name="options" autocomplete="off"> Overdue Only
                                    </label>
                                </div>
                                <small class="form-text text-muted">Select items for washing. <span id="selectedCount" class="font-weight-bold">0 items selected</span></small>
                            </div>
                            
                            <div class="table-responsive">
                                <table class="table table-hover table-sm">
                                    <thead>
                                        <tr>
                                            <th width="5%">
                                                <input type="checkbox" id="selectAllCheckbox" onchange="toggleAllItems()">
                                            </th>
                                            <th>Asset</th>
                                            <th>Category</th>
                                            <th>Condition</th>
                                            <th>Last Washed</th>
                                            <th>Wash Count</th>
                                            <th>Next Wash</th>
                                            <th>Critical</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($dirty_items as $item): ?>
                                        <tr class="<?php echo $item['is_critical'] ? 'table-warning' : ''; ?>" 
                                            data-critical="<?php echo $item['is_critical']; ?>"
                                            data-overdue="<?php echo ($item['next_wash_date'] && strtotime($item['next_wash_date']) < time()) ? '1' : '0'; ?>">
                                            <td>
                                                <input type="checkbox" class="item-checkbox" 
                                                       name="laundry_ids[]" 
                                                       value="<?php echo $item['laundry_id']; ?>"
                                                       onchange="updateSelectedCount()">
                                            </td>
                                            <td>
                                                <div><strong><?php echo htmlspecialchars($item['asset_tag']); ?></strong></div>
                                                <small class="text-muted"><?php echo htmlspecialchars($item['asset_name']); ?></small>
                                            </td>
                                            <td>
                                                <span class="badge" style="background-color: <?php echo $item['category_color'] ?: '#6c757d'; ?>">
                                                    <?php echo htmlspecialchars($item['category_name']); ?>
                                                </span>
                                            </td>
                                            <td>
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
                                            </td>
                                            <td>
                                                <?php if ($item['last_washed_date']): ?>
                                                    <?php echo date('M j, Y', strtotime($item['last_washed_date'])); ?>
                                                <?php else: ?>
                                                    <span class="text-muted">Never</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge badge-light"><?php echo $item['wash_count']; ?></span>
                                            </td>
                                            <td>
                                                <?php if ($item['next_wash_date']): ?>
                                                    <?php 
                                                    $nextDate = strtotime($item['next_wash_date']);
                                                    $today = time();
                                                    $daysDiff = floor(($nextDate - $today) / (60 * 60 * 24));
                                                    
                                                    if ($daysDiff < 0) {
                                                        echo '<span class="badge badge-danger">Overdue</span>';
                                                    } elseif ($daysDiff <= 3) {
                                                        echo '<span class="badge badge-warning">' . date('M j', $nextDate) . '</span>';
                                                    } else {
                                                        echo '<span class="badge badge-success">' . date('M j', $nextDate) . '</span>';
                                                    }
                                                    ?>
                                                <?php else: ?>
                                                    <span class="text-muted">Not set</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <?php if ($item['is_critical']): ?>
                                                    <i class="fas fa-exclamation-triangle text-warning" title="Critical Item"></i>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Wash Settings -->
                    <div class="card card-info">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-sliders-h mr-2"></i>Wash Settings</h3>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="wash_date">Wash Date *</label>
                                        <input type="date" class="form-control" id="wash_date" 
                                               name="wash_date" value="<?php echo date('Y-m-d'); ?>" required>
                                        <small class="form-text text-muted">Date of washing</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="wash_time">Wash Time *</label>
                                        <input type="time" class="form-control" id="wash_time" 
                                               name="wash_time" value="<?php echo date('H:i'); ?>" required>
                                        <small class="form-text text-muted">Time washing started</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="temperature">Temperature *</label>
                                        <select class="form-control" id="temperature" name="temperature" required>
                                            <option value="hot">Hot (60°C+)</option>
                                            <option value="warm" selected>Warm (40°C)</option>
                                            <option value="cold">Cold (30°C)</option>
                                            <option value="delicate">Delicate (20°C)</option>
                                        </select>
                                        <small class="form-text text-muted">Water temperature</small>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="cycle_duration">Cycle Duration (min)</label>
                                        <input type="number" class="form-control" id="cycle_duration" 
                                               name="cycle_duration" value="60" min="15" max="180" step="5">
                                        <small class="form-text text-muted">Wash cycle time</small>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="water_level">Water Level</label>
                                        <select class="form-control" id="water_level" name="water_level">
                                            <option value="low">Low</option>
                                            <option value="medium" selected>Medium</option>
                                            <option value="high">High</option>
                                            <option value="auto">Auto</option>
                                        </select>
                                        <small class="form-text text-muted">Water level setting</small>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="spin_speed">Spin Speed (RPM)</label>
                                        <select class="form-control" id="spin_speed" name="spin_speed">
                                            <option value="400">400 (Low)</option>
                                            <option value="800" selected>800 (Medium)</option>
                                            <option value="1200">1200 (High)</option>
                                            <option value="1400">1400 (Max)</option>
                                        </select>
                                        <small class="form-text text-muted">Spin speed setting</small>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="total_weight">Total Weight (kg) *</label>
                                        <input type="number" class="form-control" id="total_weight" 
                                               name="total_weight" min="0.1" max="50" step="0.1" 
                                               placeholder="e.g., 7.5" required>
                                        <small class="form-text text-muted">Total weight of items</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Detergent & Supplies -->
                    <div class="card card-warning">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-pump-soap mr-2"></i>Detergent & Supplies</h3>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="detergent_type">Detergent Type *</label>
                                        <select class="form-control" id="detergent_type" name="detergent_type" required>
                                            <option value="">- Select Type -</option>
                                            <option value="regular">Regular</option>
                                            <option value="hypoallergenic">Hypoallergenic</option>
                                            <option value="bleach">Bleach Detergent</option>
                                            <option value="eco">Eco-Friendly</option>
                                            <option value="medical">Medical Grade</option>
                                            <option value="other">Other</option>
                                        </select>
                                        <small class="form-text text-muted">Type of detergent used</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="detergent_amount">Detergent Amount</label>
                                        <input type="text" class="form-control" id="detergent_amount" 
                                               name="detergent_amount" placeholder="e.g., 1 cup, 100ml">
                                        <small class="form-text text-muted">Amount of detergent used</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label class="d-block">&nbsp;</label>
                                        <div class="form-check">
                                            <input type="checkbox" class="form-check-input" name="bleach_used" id="bleach_used" value="1">
                                            <label class="form-check-label" for="bleach_used">Bleach Used</label>
                                        </div>
                                        <div class="form-check">
                                            <input type="checkbox" class="form-check-input" name="fabric_softener_used" id="fabric_softener_used" value="1">
                                            <label class="form-check-label" for="fabric_softener_used">Fabric Softener Used</label>
                                        </div>
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
                                          placeholder="Any special washing instructions, observations, or notes..."></textarea>
                                <small class="form-text text-muted">Additional information about this wash cycle</small>
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
                                    <i class="fas fa-play mr-2"></i>Start Wash Cycle
                                </button>
                                <button type="reset" class="btn btn-outline-secondary" onclick="resetForm()">
                                    <i class="fas fa-undo mr-2"></i>Reset Form
                                </button>
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

                    <!-- Wash Preview -->
                    <div class="card card-info">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-eye mr-2"></i>Wash Preview</h3>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-3">
                                <i class="fas fa-tint fa-3x text-info mb-2"></i>
                                <h5 id="preview_items">0 Items</h5>
                                <div id="preview_weight" class="text-muted">0.0 kg</div>
                            </div>
                            <hr>
                            <div class="small">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Wash Date:</span>
                                    <span id="preview_date" class="font-weight-bold"><?php echo date('M d, Y'); ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Temperature:</span>
                                    <span id="preview_temp" class="font-weight-bold">Warm</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Detergent:</span>
                                    <span id="preview_detergent" class="font-weight-bold">-</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Duration:</span>
                                    <span id="preview_duration" class="font-weight-bold">60 min</span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Status:</span>
                                    <span class="badge badge-primary">Ready</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Wash Statistics -->
                    <div class="card card-warning">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-chart-bar mr-2"></i>Wash Statistics</h3>
                        </div>
                        <div class="card-body">
                            <div class="list-group list-group-flush">
                                <div class="list-group-item px-0 py-2">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1">Total Cycles</h6>
                                        <span class="badge badge-primary badge-pill"><?php echo $wash_stats['total_cycles']; ?></span>
                                    </div>
                                    <small class="text-muted">All wash cycles</small>
                                </div>
                                <div class="list-group-item px-0 py-2">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1">Total Items</h6>
                                        <span class="badge badge-info badge-pill"><?php echo $wash_stats['total_items']; ?></span>
                                    </div>
                                    <small class="text-muted">Items washed total</small>
                                </div>
                                <div class="list-group-item px-0 py-2">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1">Today's Washes</h6>
                                        <span class="badge badge-success badge-pill"><?php echo $wash_stats['today_cycles']; ?></span>
                                    </div>
                                    <small class="text-muted">Cycles today</small>
                                </div>
                                <div class="list-group-item px-0 py-2">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1">Avg Weight</h6>
                                        <span class="badge badge-dark badge-pill"><?php echo number_format($wash_stats['avg_weight'], 1); ?>kg</span>
                                    </div>
                                    <small class="text-muted">Average per cycle</small>
                                </div>
                                <div class="list-group-item px-0 py-2">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1">Bleach Used</h6>
                                        <span class="badge badge-warning badge-pill"><?php echo $wash_stats['bleach_cycles']; ?></span>
                                    </div>
                                    <small class="text-muted">Cycles with bleach</small>
                                </div>
                                <div class="list-group-item px-0 py-2">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1">Softener Used</h6>
                                        <span class="badge badge-info badge-pill"><?php echo $wash_stats['softener_cycles']; ?></span>
                                    </div>
                                    <small class="text-muted">Cycles with softener</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Wash Cycles -->
                    <div class="card card-secondary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-history mr-2"></i>Recent Cycles</h3>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($recent_cycles)): ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($recent_cycles as $cycle): ?>
                                        <div class="list-group-item px-0 py-2">
                                            <div class="d-flex w-100 justify-content-between align-items-center">
                                                <div>
                                                    <h6 class="mb-1"><?php echo date('M j', strtotime($cycle['wash_date'])); ?></h6>
                                                    <small class="text-muted">
                                                        <?php echo $cycle['item_count']; ?> items, 
                                                        <?php echo ucfirst($cycle['temperature']); ?>
                                                    </small>
                                                </div>
                                                <span class="badge badge-light">
                                                    <?php echo date('H:i', strtotime($cycle['wash_time'])); ?>
                                                </span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted text-center mb-0">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    No recent wash cycles
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
                                <strong>Sort by Need:</strong> Prioritize items with overdue or upcoming wash dates.
                            </div>
                            <div class="callout callout-warning small mb-3">
                                <i class="fas fa-exclamation-triangle mr-2"></i>
                                <strong>Weight Matters:</strong> Don't overload the machine. Check manufacturer's limits.
                            </div>
                            <div class="callout callout-success small">
                                <i class="fas fa-check-circle mr-2"></i>
                                <strong>Proper Detergent:</strong> Use the right detergent type for different fabrics.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
        
        <?php endif; ?>
    </div>
</div>

<script>
$(document).ready(function() {
    updateSelectedCount();
    calculateEstimatedWeight();
    
    // Update preview when wash date changes
    $('#wash_date').on('change', function() {
        var date = new Date($(this).val());
        if (!isNaN(date.getTime())) {
            $('#preview_date').text(date.toLocaleDateString('en-US', { 
                month: 'short', 
                day: 'numeric', 
                year: 'numeric' 
            }));
        }
    });
    
    // Update preview when temperature changes
    $('#temperature').on('change', function() {
        $('#preview_temp').text($(this).find('option:selected').text());
    });
    
    // Update preview when detergent changes
    $('#detergent_type').on('change', function() {
        var selectedText = $(this).find('option:selected').text();
        $('#preview_detergent').text(selectedText || '-');
    });
    
    // Update preview when duration changes
    $('#cycle_duration').on('input', function() {
        $('#preview_duration').text($(this).val() + ' min');
    });
    
    // Update preview when weight changes
    $('#total_weight').on('input', function() {
        var weight = parseFloat($(this).val()) || 0;
        $('#preview_weight').text(weight.toFixed(1) + ' kg');
    });
    
    // Auto-calculate weight based on selection
    $('.item-checkbox').on('change', function() {
        calculateEstimatedWeight();
    });
    
    // Enhanced form validation
    $('#washForm').on('submit', function(e) {
        var requiredFields = ['wash_date', 'wash_time', 'temperature', 'detergent_type', 'total_weight'];
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
        
        // Check if any items selected
        var selectedCount = $('.item-checkbox:checked').length;
        if (selectedCount === 0) {
            isValid = false;
            errorMessages.push('Please select at least one item to wash');
        }
        
        // Validate weight
        var weight = parseFloat($('#total_weight').val()) || 0;
        if (weight <= 0) {
            isValid = false;
            errorMessages.push('Weight must be greater than 0');
            $('#total_weight').addClass('is-invalid');
        } else if (weight > 50) {
            isValid = false;
            errorMessages.push('Weight cannot exceed 50kg');
            $('#total_weight').addClass('is-invalid');
        } else {
            $('#total_weight').removeClass('is-invalid');
        }
        
        // Validate date/time
        var washDate = new Date($('#wash_date').val() + 'T' + $('#wash_time').val());
        var now = new Date();
        
        if (washDate > now) {
            isValid = false;
            errorMessages.push('Wash date/time cannot be in the future');
            $('#wash_date').addClass('is-invalid');
            $('#wash_time').addClass('is-invalid');
        } else {
            $('#wash_date').removeClass('is-invalid');
            $('#wash_time').removeClass('is-invalid');
        }
        
        if (!isValid) {
            e.preventDefault();
            var errorMessage = 'Please fix the following errors:\n• ' + errorMessages.join('\n• ');
            alert(errorMessage);
            return false;
        }
        
        // Show loading state
        $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Starting...').prop('disabled', true);
    });
    
    // Initialize preview
    $('#temperature').trigger('change');
    $('#detergent_type').trigger('change');
    $('#cycle_duration').trigger('input');
    $('#total_weight').trigger('input');
});

function toggleAllItems() {
    var isChecked = $('#selectAllCheckbox').prop('checked');
    $('.item-checkbox').prop('checked', isChecked);
    updateSelectedCount();
    calculateEstimatedWeight();
}

function selectAllItems(select) {
    $('.item-checkbox').prop('checked', select);
    $('#selectAllCheckbox').prop('checked', select);
    updateSelectedCount();
    calculateEstimatedWeight();
}

function selectCriticalItems() {
    $('.item-checkbox').prop('checked', false);
    $('tr[data-critical="1"] .item-checkbox').prop('checked', true);
    $('#selectAllCheckbox').prop('checked', false);
    updateSelectedCount();
    calculateEstimatedWeight();
}

function selectOverdueItems() {
    $('.item-checkbox').prop('checked', false);
    $('tr[data-overdue="1"] .item-checkbox').prop('checked', true);
    $('#selectAllCheckbox').prop('checked', false);
    updateSelectedCount();
    calculateEstimatedWeight();
}

function updateSelectedCount() {
    var selectedCount = $('.item-checkbox:checked').length;
    var selectedText = selectedCount + ' item' + (selectedCount !== 1 ? 's' : '');
    $('#selectedCount').text(selectedText);
    $('#preview_items').text(selectedText);
    
    if (selectedCount > 0) {
        $('#submitBtn').prop('disabled', false);
    } else {
        $('#submitBtn').prop('disabled', true);
    }
}

function calculateEstimatedWeight() {
    var selectedCount = $('.item-checkbox:checked').length;
    // Estimate 0.5kg per item on average
    var estimatedWeight = selectedCount * 0.5;
    
    // Update weight field if empty or still at default
    var currentWeight = parseFloat($('#total_weight').val()) || 0;
    if (currentWeight === 0 || currentWeight === 0.5) {
        $('#total_weight').val(estimatedWeight.toFixed(1));
        $('#total_weight').trigger('input');
    }
}

function resetForm() {
    if (confirm('Are you sure you want to reset all changes? All entered data will be lost.')) {
        $('#washForm')[0].reset();
        $('.item-checkbox').prop('checked', false);
        $('#selectAllCheckbox').prop('checked', false);
        $('#wash_date').val('<?php echo date("Y-m-d"); ?>');
        $('#wash_time').val('<?php echo date("H:i"); ?>');
        $('#temperature').val('warm');
        $('#detergent_type').val('');
        $('#total_weight').val('0.5');
        updateSelectedCount();
        calculateEstimatedWeight();
        $('#temperature').trigger('change');
        $('#detergent_type').trigger('change');
        $('#total_weight').trigger('input');
    }
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + S to save
    if (e.ctrlKey && e.keyCode === 83) {
        e.preventDefault();
        $('#washForm').submit();
    }
    // Escape to cancel
    if (e.keyCode === 27) {
        window.location.href = 'laundry_management.php';
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

.is-invalid {
    border-color: #dc3545;
    box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25);
}

.btn:disabled {
    cursor: not-allowed;
    opacity: 0.6;
}

.table tbody tr {
    cursor: pointer;
}

.table tbody tr:hover {
    background-color: #f8f9fa;
}

.btn-group-toggle .btn {
    font-size: 0.875rem;
    padding: 0.25rem 0.5rem;
}

.badge {
    font-size: 0.75rem;
    font-weight: normal;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
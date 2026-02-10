<?php
// laundry_checkout.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

$page_title = "Checkout/Checkin Laundry Items";

// Check if form is submitted
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    $action = sanitizeInput($_POST['action']);
    $laundry_ids = $_POST['laundry_ids'] ?? [];
    $performed_for = !empty($_POST['performed_for']) ? intval($_POST['performed_for']) : NULL;
    $department = sanitizeInput($_POST['department']);
    $notes = sanitizeInput($_POST['notes']);
    
    $user_id = intval($_SESSION['user_id']);
    
    if (empty($laundry_ids)) {
        $error = "Please select at least one item";
    } else {
        
        $mysqli->begin_transaction();
        
        try {
            foreach ($laundry_ids as $laundry_id) {
                $laundry_id = intval($laundry_id);
                
                // Get current item details
                $item_sql = "SELECT current_location, status FROM laundry_items WHERE laundry_id = ?";
                $item_stmt = $mysqli->prepare($item_sql);
                $item_stmt->bind_param("i", $laundry_id);
                $item_stmt->execute();
                $item_result = $item_stmt->get_result();
                $item = $item_result->fetch_assoc();
                
                if ($item) {
                    $current_location = $item['current_location'];
                    $from_location = $current_location;
                    
                    // Determine new location based on action
                    if ($action == 'checkout' && $current_location == 'storage') {
                        $to_location = 'clinic';
                    } elseif ($action == 'checkin' && $current_location == 'clinic') {
                        $to_location = 'storage';
                    } elseif ($action == 'checkout_laundry' && $current_location == 'storage') {
                        $to_location = 'laundry';
                    } elseif ($action == 'checkin_laundry' && $current_location == 'laundry') {
                        $to_location = 'storage';
                    } else {
                        throw new Exception("Invalid action for current location");
                    }
                    
                    // Update laundry item
                    $update_sql = "UPDATE laundry_items 
                                  SET current_location = ?, 
                                      updated_at = NOW(),
                                      updated_by = ?
                                  WHERE laundry_id = ?";
                    $update_stmt = $mysqli->prepare($update_sql);
                    $update_stmt->bind_param("sii", $to_location, $user_id, $laundry_id);
                    $update_stmt->execute();
                    
                    // Create transaction record
                    $transaction_sql = "INSERT INTO laundry_transactions 
                                       (laundry_id, transaction_type, from_location, to_location, 
                                        performed_by, performed_for, department, notes) 
                                       VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                    $transaction_stmt = $mysqli->prepare($transaction_sql);
                    $transaction_stmt->bind_param("isssiiss", 
                        $laundry_id, 
                        $action, 
                        $from_location, 
                        $to_location, 
                        $user_id, 
                        $performed_for, 
                        $department, 
                        $notes
                    );
                    $transaction_stmt->execute();
                }
            }
            
            $mysqli->commit();
            
            // Log activity
            logActivity($user_id, "Processed checkout/checkin for " . count($laundry_ids) . " items", "laundry");
            
            $_SESSION['alert_message'] = count($laundry_ids) . " items processed successfully";
            $_SESSION['alert_type'] = "success";
            
            header("Location: laundry_management.php");
            exit();
            
        } catch (Exception $e) {
            $mysqli->rollback();
            $error = $e->getMessage();
        }
    }
}

// Get available items based on current tab
$current_tab = $_GET['tab'] ?? 'checkout';
$status_filter = 'clean'; // Only clean items can be checked out/in

if ($current_tab == 'checkout') {
    $location_filter = 'storage';
    $action = 'checkout';
} elseif ($current_tab == 'checkin') {
    $location_filter = 'clinic';
    $action = 'checkin';
} elseif ($current_tab == 'to_laundry') {
    $location_filter = 'storage';
    $action = 'checkout_laundry';
} else {
    $location_filter = 'laundry';
    $action = 'checkin_laundry';
}

$available_items_sql = "
    SELECT li.*, a.asset_name, a.asset_tag, lc.category_name
    FROM laundry_items li
    LEFT JOIN assets a ON li.asset_id = a.asset_id
    LEFT JOIN laundry_categories lc ON li.category_id = lc.category_id
    WHERE li.status = '$status_filter' 
    AND li.current_location = '$location_filter'
    ORDER BY a.asset_name
";
$available_items_result = $mysqli->query($available_items_sql);

// Get clients for patient assignment
$patient_sql = "SELECT patient_id, patient_first_name,patient_last_name FROM patients ";
$patients_result = $mysqli->query($patient_sql);
?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-exchange-alt mr-2"></i>Checkout/Checkin Laundry Items
        </h3>
        <div class="card-tools">
            <a href="laundry_management.php" class="btn btn-secondary">
                <i class="fas fa-fw fa-arrow-left mr-2"></i>Back to Laundry
            </a>
        </div>
    </div>
    
    <div class="card-body">
        <?php if (isset($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle mr-2"></i><?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <!-- Tab Navigation -->
        <ul class="nav nav-tabs mb-4" id="checkoutTab" role="tablist">
            <li class="nav-item">
                <a class="nav-link <?php echo $current_tab == 'checkout' ? 'active' : ''; ?>" 
                   href="?tab=checkout">
                    <i class="fas fa-sign-out-alt mr-2"></i>Checkout to Clinic
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $current_tab == 'checkin' ? 'active' : ''; ?>" 
                   href="?tab=checkin">
                    <i class="fas fa-sign-in-alt mr-2"></i>Checkin to Storage
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $current_tab == 'to_laundry' ? 'active' : ''; ?>" 
                   href="?tab=to_laundry">
                    <i class="fas fa-tint mr-2"></i>Send to Laundry
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $current_tab == 'from_laundry' ? 'active' : ''; ?>" 
                   href="?tab=from_laundry">
                    <i class="fas fa-box mr-2"></i>Receive from Laundry
                </a>
            </li>
        </ul>
        
        <div class="tab-content">
            <div class="tab-pane fade show active">
                <form action="laundry_checkout.php" method="POST" autocomplete="off">
                    <input type="hidden" name="action" value="<?php echo $action; ?>">
                    
                    <div class="row mb-4">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Department *</label>
                                <select class="form-control" name="department" required>
                                    <option value="">- Select Department -</option>
                                    <option value="emergency">Emergency Room</option>
                                    <option value="operating_room">Operating Room</option>
                                    <option value="icu">ICU</option>
                                    <option value="ward">General Ward</option>
                                    <option value="outpatient">Outpatient Clinic</option>
                                    <option value="lab">Laboratory</option>
                                    <option value="radiology">Radiology</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>For Patient (Optional)</label>
                                <select class="form-control select2" name="performed_for">
                                    <option value="">- Select Patient -</option>
                                    <?php while($patient = $patients_result->fetch_assoc()): ?>
                                        <option value="<?php echo $client['patient_id']; ?>">
                                            <?php echo htmlspecialchars($patient['patient_first_name'] . '' . $patient['patient_last_name'] ); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Notes</label>
                                <textarea class="form-control" name="notes" rows="1" 
                                          placeholder="Reason or special instructions..."></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card mb-4">
                        <div class="card-header bg-light">
                            <h5 class="mb-0">
                                <i class="fas fa-tshirt mr-2"></i>Available Items
                                <small class="text-muted float-right" id="selectedCount">0 items selected</small>
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if ($available_items_result->num_rows == 0): ?>
                                <div class="alert alert-warning text-center">
                                    <i class="fas fa-info-circle mr-2"></i>
                                    No items available for this action.
                                </div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th width="5%">
                                                <input type="checkbox" id="selectAllItems" onchange="toggleAllItems()">
                                            </th>
                                            <th>Asset Tag</th>
                                            <th>Item Name</th>
                                            <th>Category</th>
                                            <th>Condition</th>
                                            <th>Current Location</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while($item = $available_items_result->fetch_assoc()): ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox" class="item-checkbox" 
                                                       name="laundry_ids[]" 
                                                       value="<?php echo $item['laundry_id']; ?>"
                                                       onchange="updateSelectedCount()">
                                            </td>
                                            <td><?php echo htmlspecialchars($item['asset_tag']); ?></td>
                                            <td><?php echo htmlspecialchars($item['asset_name']); ?></td>
                                            <td><?php echo htmlspecialchars($item['category_name']); ?></td>
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
                                                <span class="badge badge-<?php 
                                                    switch($item['current_location']) {
                                                        case 'clinic': echo 'primary'; break;
                                                        case 'laundry': echo 'info'; break;
                                                        case 'storage': echo 'success'; break;
                                                        case 'in_transit': echo 'warning'; break;
                                                        default: echo 'secondary';
                                                    }
                                                ?>">
                                                    <?php echo ucfirst($item['current_location']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <div class="form-group text-center">
                        <button type="submit" class="btn btn-primary btn-lg" id="submitBtn" 
                                <?php echo $available_items_result->num_rows == 0 ? 'disabled' : ''; ?>>
                            <i class="fas fa-check mr-2"></i>
                            <?php 
                            switch($action) {
                                case 'checkout': echo 'Checkout to Clinic'; break;
                                case 'checkin': echo 'Checkin to Storage'; break;
                                case 'checkout_laundry': echo 'Send to Laundry'; break;
                                case 'checkin_laundry': echo 'Receive from Laundry'; break;
                            }
                            ?>
                        </button>
                        <a href="laundry_management.php" class="btn btn-secondary btn-lg ml-2">
                            <i class="fas fa-times mr-2"></i>Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.select2').select2();
    updateSelectedCount();
});

function toggleAllItems() {
    var isChecked = $('#selectAllItems').prop('checked');
    $('.item-checkbox').prop('checked', isChecked);
    updateSelectedCount();
}

function updateSelectedCount() {
    var selectedCount = $('.item-checkbox:checked').length;
    $('#selectedCount').text(selectedCount + ' items selected');
    
    if (selectedCount > 0) {
        $('#submitBtn').prop('disabled', false);
    } else {
        $('#submitBtn').prop('disabled', true);
    }
}
</script>

<style>
.nav-tabs .nav-link.active {
    font-weight: bold;
    border-bottom: 3px solid #007bff;
}

.card-body .table tbody tr:hover {
    background-color: #f8f9fa;
    cursor: pointer;
}

#submitBtn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
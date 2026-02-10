<?php
// laundry_new.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

$page_title = "Add Laundry Item";

// Initialize variables
$assets = [];
$categories = [];
$laundry_stats = [];

// Get assets that aren't in laundry yet
$assets_sql = "SELECT a.*, ac.category_name, al.location_name
               FROM assets a 
               LEFT JOIN laundry_items li ON a.asset_id = li.asset_id 
               LEFT JOIN asset_categories ac ON a.category_id = ac.category_id
               LEFT JOIN asset_locations al ON a.location_id = al.location_id
               WHERE li.laundry_id IS NULL
               AND (a.category_id IN (SELECT category_id FROM asset_categories WHERE category_name LIKE '%linen%' OR category_name LIKE '%uniform%')
                    OR a.asset_name LIKE '%sheet%' 
                    OR a.asset_name LIKE '%towel%' 
                    OR a.asset_name LIKE '%gown%' 
                    OR a.asset_name LIKE '%blanket%'
                    OR a.asset_description LIKE '%linen%'
                    OR a.asset_description LIKE '%uniform%')
               ORDER BY a.asset_name";
$assets_result = $mysqli->query($assets_sql);
while ($asset = $assets_result->fetch_assoc()) {
    $assets[] = $asset;
}

// Get laundry categories
$categories_sql = "SELECT * FROM laundry_categories ORDER BY category_name";
$categories_result = $mysqli->query($categories_sql);
while ($category = $categories_result->fetch_assoc()) {
    $categories[] = $category;
}

// Get laundry statistics
$stats_sql = "
    SELECT 
        COUNT(*) as total_items,
        SUM(quantity) as total_quantity,
        SUM(CASE WHEN status = 'clean' THEN quantity ELSE 0 END) as clean_items,
        SUM(CASE WHEN status = 'dirty' THEN quantity ELSE 0 END) as dirty_items,
        SUM(CASE WHEN status = 'damaged' THEN quantity ELSE 0 END) as damaged_items,
        SUM(CASE WHEN is_critical = 1 THEN 1 ELSE 0 END) as critical_items,
        SUM(wash_count) as total_washes,
        AVG(wash_count) as avg_washes
    FROM laundry_items
";
$stats_result = $mysqli->query($stats_sql);
$laundry_stats = $stats_result->fetch_assoc();

// Get recent laundry items
$recent_items_sql = "
    SELECT li.*, a.asset_name, a.asset_tag, lc.category_name
    FROM laundry_items li
    LEFT JOIN assets a ON li.asset_id = a.asset_id
    LEFT JOIN laundry_categories lc ON li.category_id = lc.category_id
    ORDER BY li.created_at DESC
    LIMIT 5
";
$recent_items_result = $mysqli->query($recent_items_sql);
$recent_items = [];
while ($item = $recent_items_result->fetch_assoc()) {
    $recent_items[] = $item;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    
    if ($csrf_token != $_SESSION['csrf_token']) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "CSRF token validation failed";
        header("Location: laundry_new.php");
        exit;
    }
    
    $asset_id = intval($_POST['asset_id']);
    $category_id = intval($_POST['category_id']);
    $quantity = intval($_POST['quantity']);
    $current_location = sanitizeInput($_POST['current_location']);
    $status = sanitizeInput($_POST['status']);
    $item_condition = sanitizeInput($_POST['condition']);
    $notes = sanitizeInput($_POST['notes']);
    $is_critical = isset($_POST['is_critical']) ? 1 : 0;
    $next_wash_date = !empty($_POST['next_wash_date']) ? $_POST['next_wash_date'] : NULL;
    
    $user_id = intval($_SESSION['user_id']);
    
    // Validate required fields
    $errors = [];
    
    if (empty($asset_id)) {
        $errors[] = "Asset selection is required";
    }
    if (empty($category_id)) {
        $errors[] = "Category selection is required";
    }
    if ($quantity < 1) {
        $errors[] = "Quantity must be at least 1";
    }
    if ($quantity > 1000) {
        $errors[] = "Quantity cannot exceed 1000";
    }
    
    if (empty($errors)) {
        // Check if asset already exists in laundry
        $check_sql = "SELECT * FROM laundry_items WHERE asset_id = ?";
        $check_stmt = $mysqli->prepare($check_sql);
        $check_stmt->bind_param("i", $asset_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "This asset is already in the laundry system";
            header("Location: laundry_new.php");
            exit;
        } else {
            // Insert new laundry item
            $sql = "INSERT INTO laundry_items 
                   (asset_id, category_id, quantity, current_location, status, 
                    `item_condition`, notes, is_critical, next_wash_date, created_by) 
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param("iiissssisi", 
                $asset_id, 
                $category_id, 
                $quantity, 
                $current_location, 
                $status, 
                $item_condition,
                $notes, 
                $is_critical, 
                $next_wash_date, 
                $user_id
            );
            
            if ($stmt->execute()) {
                $laundry_id = $stmt->insert_id;
                
                // Create initial transaction
                $transaction_sql = "INSERT INTO laundry_transactions 
                                   (laundry_id, transaction_type, from_location, to_location, 
                                    performed_by, notes) 
                                   VALUES (?, 'checkin', 'new', ?, ?, 'Initial inventory entry')";
                $transaction_stmt = $mysqli->prepare($transaction_sql);
                $transaction_stmt->bind_param("isi", $laundry_id, $current_location, $user_id);
                $transaction_stmt->execute();
                
                // Log activity
                $asset_name = getAssetName($asset_id);
                $log_description = "Laundry item added: $asset_name (Qty: $quantity)";
                mysqli_query($mysqli, "INSERT INTO logs SET log_type = 'Laundry', log_action = 'Add', log_description = '$log_description', log_ip = '$session_ip', log_user_agent = '$session_user_agent', log_user_id = $session_user_id");
                
                $_SESSION['alert_type'] = "success";
                $_SESSION['alert_message'] = "Laundry item added successfully";
                
                header("Location: laundry_view.php?id=" . $laundry_id);
                exit();
            } else {
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = "Error adding laundry item: " . $mysqli->error;
                header("Location: laundry_new.php");
                exit;
            }
        }
    } else {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = implode("<br>", $errors);
        header("Location: laundry_new.php");
        exit;
    }
}

// Helper function to get asset name
function getAssetName($asset_id) {
    global $mysqli;
    $sql = "SELECT asset_name FROM assets WHERE asset_id = ?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("i", $asset_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return $row['asset_name'] ?? 'Unknown Asset';
}
?>

<div class="card">
    <div class="card-header bg-success py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-plus mr-2"></i>Add New Laundry Item
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
        
        <form method="POST" id="laundryForm" autocomplete="off">
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
                                <label for="asset_id">Asset *</label>
                                <select class="form-control select2" id="asset_id" name="asset_id" required onchange="loadAssetDetails()">
                                    <option value="">- Select Asset -</option>
                                    <?php foreach ($assets as $asset): ?>
                                        <option value="<?php echo $asset['asset_id']; ?>" 
                                                data-tag="<?php echo htmlspecialchars($asset['asset_tag'] ?? 'N/A'); ?>"
                                                data-name="<?php echo htmlspecialchars($asset['asset_name'] ?? 'N/A'); ?>"
                                                data-description="<?php echo htmlspecialchars($asset['asset_description'] ?? 'N/A'); ?>"
                                                data-serial="<?php echo htmlspecialchars($asset['serial_number'] ?? 'N/A'); ?>"
                                                data-category="<?php echo htmlspecialchars($asset['category_name'] ?? 'N/A'); ?>"
                                                data-location="<?php echo htmlspecialchars($asset['location_name'] ?? 'N/A'); ?>">
                                            <?php echo htmlspecialchars($asset['asset_tag'] . " - " . $asset['asset_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="form-text text-muted">Select an asset that hasn't been added to laundry yet</small>
                            </div>
                            
                            <div class="row mt-3" id="assetDetails" style="display: none;">
                                <div class="col-md-4">
                                    <div class="info-box bg-light">
                                        <span class="info-box-icon"><i class="fas fa-tag"></i></span>
                                        <div class="info-box-content">
                                            <span class="info-box-text">Asset Tag</span>
                                            <span id="assetTag" class="info-box-number">-</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="info-box bg-light">
                                        <span class="info-box-icon"><i class="fas fa-tags"></i></span>
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
                                            <span class="info-box-text">Location</span>
                                            <span id="assetLocation" class="info-box-number">-</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Item Details -->
                    <div class="card card-warning">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-info-circle mr-2"></i>Item Details</h3>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="category_id">Category *</label>
                                        <select class="form-control select2" id="category_id" name="category_id" required>
                                            <option value="">- Select Category -</option>
                                            <?php foreach ($categories as $category): ?>
                                                <option value="<?php echo $category['category_id']; ?>">
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
                                               value="1" min="1" max="1000" required>
                                        <small class="form-text text-muted">Number of identical items</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="current_location">Initial Location *</label>
                                        <select class="form-control" id="current_location" name="current_location" required>
                                            <option value="storage" selected>Storage</option>
                                            <option value="clinic">Clinic</option>
                                            <option value="laundry">Laundry Room</option>
                                            <option value="in_transit">In Transit</option>
                                            <option value="ward">Ward</option>
                                            <option value="or">Operating Room</option>
                                            <option value="er">Emergency Room</option>
                                        </select>
                                        <small class="form-text text-muted">Where the item is currently located</small>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="status">Initial Status *</label>
                                        <select class="form-control" id="status" name="status" required>
                                            <option value="clean" selected>Clean</option>
                                            <option value="dirty">Dirty</option>
                                            <option value="in_wash">In Wash</option>
                                            <option value="damaged">Damaged</option>
                                            <option value="lost">Lost</option>
                                            <option value="retired">Retired</option>
                                        </select>
                                        <small class="form-text text-muted">Current status of the item</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="condition">Condition *</label>
                                        <select class="form-control" id="condition" name="condition" required>
                                            <option value="excellent">Excellent (Like New)</option>
                                            <option value="good" selected>Good (Normal Use)</option>
                                            <option value="fair">Fair (Minor Wear)</option>
                                            <option value="poor">Poor (Heavy Wear)</option>
                                            <option value="critical">Critical (Needs Replacement)</option>
                                        </select>
                                        <small class="form-text text-muted">Physical condition of the item</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label for="next_wash_date">Next Wash Date</label>
                                        <input type="date" class="form-control" id="next_wash_date" 
                                               name="next_wash_date" min="<?php echo date('Y-m-d'); ?>">
                                        <small class="form-text text-muted">When this item should be washed next</small>
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
                                <textarea class="form-control" id="notes" name="notes" rows="4" 
                                          placeholder="Any special instructions, care information, or notes about this item..."></textarea>
                                <small class="form-text text-muted">Additional information about this laundry item</small>
                            </div>

                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" name="is_critical" id="is_critical" value="1">
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
                                <button type="submit" class="btn btn-success btn-lg" id="submitBtn" <?php echo (empty($assets) || empty($categories)) ? 'disabled' : ''; ?>>
                                    <i class="fas fa-save mr-2"></i>Add Laundry Item
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

                    <!-- Item Preview -->
                    <div class="card card-info">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-eye mr-2"></i>Item Preview</h3>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-3">
                                <i class="fas fa-tshirt fa-3x text-info mb-2"></i>
                                <h5 id="preview_name">Select an Asset</h5>
                                <div id="preview_tag" class="text-muted small">-</div>
                            </div>
                            <hr>
                            <div class="small">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Quantity:</span>
                                    <span id="preview_quantity" class="font-weight-bold">1</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Category:</span>
                                    <span id="preview_category" class="font-weight-bold">-</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Status:</span>
                                    <span id="preview_status" class="font-weight-bold">Clean</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Condition:</span>
                                    <span id="preview_condition" class="font-weight-bold">Good</span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Location:</span>
                                    <span id="preview_location" class="font-weight-bold">Storage</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Laundry Statistics -->
                    <div class="card card-warning">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-chart-bar mr-2"></i>Laundry Statistics</h3>
                        </div>
                        <div class="card-body">
                            <div class="list-group list-group-flush">
                                <div class="list-group-item px-0 py-2">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1">Total Items</h6>
                                        <span class="badge badge-primary badge-pill"><?php echo $laundry_stats['total_items']; ?></span>
                                    </div>
                                    <small class="text-muted">All laundry items</small>
                                </div>
                                <div class="list-group-item px-0 py-2">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1">Total Quantity</h6>
                                        <span class="badge badge-info badge-pill"><?php echo $laundry_stats['total_quantity']; ?></span>
                                    </div>
                                    <small class="text-muted">Sum of all quantities</small>
                                </div>
                                <div class="list-group-item px-0 py-2">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1">Clean Items</h6>
                                        <span class="badge badge-success badge-pill"><?php echo $laundry_stats['clean_items']; ?></span>
                                    </div>
                                    <small class="text-muted">Ready for use</small>
                                </div>
                                <div class="list-group-item px-0 py-2">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1">Dirty Items</h6>
                                        <span class="badge badge-warning badge-pill"><?php echo $laundry_stats['dirty_items']; ?></span>
                                    </div>
                                    <small class="text-muted">Need washing</small>
                                </div>
                                <div class="list-group-item px-0 py-2">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1">Damaged Items</h6>
                                        <span class="badge badge-danger badge-pill"><?php echo $laundry_stats['damaged_items']; ?></span>
                                    </div>
                                    <small class="text-muted">Need repair/replacement</small>
                                </div>
                                <div class="list-group-item px-0 py-2">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1">Critical Items</h6>
                                        <span class="badge badge-dark badge-pill"><?php echo $laundry_stats['critical_items']; ?></span>
                                    </div>
                                    <small class="text-muted">Special attention needed</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Items -->
                    <div class="card card-secondary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-history mr-2"></i>Recent Items</h3>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($recent_items)): ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($recent_items as $item): ?>
                                        <div class="list-group-item px-0 py-2">
                                            <div class="d-flex w-100 justify-content-between align-items-center">
                                                <div>
                                                    <h6 class="mb-1"><?php echo htmlspecialchars(substr($item['asset_name'], 0, 20)); ?>...</h6>
                                                    <small class="text-muted">
                                                        <?php echo htmlspecialchars($item['category_name']); ?> - 
                                                        Qty: <?php echo $item['quantity']; ?>
                                                    </small>
                                                </div>
                                                <span class="badge badge-<?php 
                                                    switch($item['status']) {
                                                        case 'clean': echo 'success'; break;
                                                        case 'dirty': echo 'warning'; break;
                                                        case 'damaged': echo 'danger'; break;
                                                        default: echo 'secondary';
                                                    }
                                                ?>">
                                                    <?php echo ucfirst($item['status']); ?>
                                                </span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted text-center mb-0">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    No laundry items yet
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
                                <strong>Asset Selection:</strong> Only assets not already in laundry system are shown.
                            </div>
                            <div class="callout callout-warning small mb-3">
                                <i class="fas fa-exclamation-triangle mr-2"></i>
                                <strong>Critical Items:</strong> Mark items as critical if they require special handling or are in short supply.
                            </div>
                            <div class="callout callout-success small">
                                <i class="fas fa-check-circle mr-2"></i>
                                <strong>Next Wash Date:</strong> Set for preventive maintenance scheduling.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
        
        <?php if (empty($assets)): ?>
            <div class="alert alert-warning mt-3">
                <i class="fas fa-exclamation-triangle mr-2"></i>
                <strong>No available assets found.</strong> All assets are already in the laundry system or you need to add linen/uniform assets first.
                <a href="asset_new.php" class="alert-link ml-2">Add New Asset</a>
            </div>
        <?php endif; ?>
        
        <?php if (empty($categories)): ?>
            <div class="alert alert-warning mt-3">
                <i class="fas fa-exclamation-triangle mr-2"></i>
                <strong>No categories found.</strong> You need to create laundry categories first.
                <a href="laundry_categories.php" class="alert-link ml-2">Create Categories</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize Select2
    $('.select2').select2();
    
    // Update preview when quantity changes
    $('#quantity').on('input', function() {
        var quantity = parseInt($(this).val()) || 1;
        $('#preview_quantity').text(quantity);
    });
    
    // Update preview when status changes
    $('#status').on('change', function() {
        $('#preview_status').text($(this).find('option:selected').text());
    });
    
    // Update preview when condition changes
    $('#condition').on('change', function() {
        $('#preview_condition').text($(this).find('option:selected').text());
    });
    
    // Update preview when location changes
    $('#current_location').on('change', function() {
        $('#preview_location').text($(this).find('option:selected').text());
    });
    
    // Update preview when category changes
    $('#category_id').on('change', function() {
        var selectedText = $(this).find('option:selected').text();
        $('#preview_category').text(selectedText || '-');
    });
    
    // Set default next wash date (7 days from now)
    var nextWeek = new Date();
    nextWeek.setDate(nextWeek.getDate() + 7);
    $('#next_wash_date').val(nextWeek.toISOString().split('T')[0]);
    
    // Enhanced form validation
    $('#laundryForm').on('submit', function(e) {
        var requiredFields = ['asset_id', 'category_id', 'quantity', 'current_location', 'status', 'condition'];
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
        $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Adding...').prop('disabled', true);
    });
    
    // Initialize preview
    $('#status').trigger('change');
    $('#condition').trigger('change');
    $('#current_location').trigger('change');
});

function loadAssetDetails() {
    var selectedOption = $('#asset_id option:selected');
    var assetDetails = $('#assetDetails');
    
    if (selectedOption.val()) {
        $('#preview_name').text(selectedOption.data('name') || 'Unknown Asset');
        $('#preview_tag').text(selectedOption.data('tag') || 'No Tag');
        
        $('#assetTag').text(selectedOption.data('tag') || '-');
        $('#assetCategory').text(selectedOption.data('category') || '-');
        $('#assetLocation').text(selectedOption.data('location') || '-');
        
        assetDetails.show();
    } else {
        assetDetails.hide();
        $('#preview_name').text('Select an Asset');
        $('#preview_tag').text('-');
    }
}

function resetForm() {
    if (confirm('Are you sure you want to reset all changes? All entered data will be lost.')) {
        $('#laundryForm')[0].reset();
        $('.select2').val('').trigger('change');
        $('#quantity').val('1');
        $('#status').val('clean');
        $('#condition').val('good');
        $('#current_location').val('storage');
        
        // Set default next wash date
        var nextWeek = new Date();
        nextWeek.setDate(nextWeek.getDate() + 7);
        $('#next_wash_date').val(nextWeek.toISOString().split('T')[0]);
        
        // Trigger change events
        $('#status').trigger('change');
        $('#condition').trigger('change');
        $('#current_location').trigger('change');
        $('#asset_id').trigger('change');
    }
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + S to save
    if (e.ctrlKey && e.keyCode === 83) {
        e.preventDefault();
        $('#laundryForm').submit();
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
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
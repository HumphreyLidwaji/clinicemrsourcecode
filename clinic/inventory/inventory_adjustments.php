<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Default Column Sortby/Order Filter
$sort = "transaction_date";
$order = "DESC";

// Initialize variables
$items = [];
$locations = [];

// Get all active items for dropdown
$items_sql = "SELECT item_id, item_name, item_code, item_quantity 
              FROM inventory_items 
              WHERE item_status != 'Discontinued' 
              ORDER BY item_name";
$items_result = $mysqli->query($items_sql);
while ($item = $items_result->fetch_assoc()) {
    $items[] = $item;
}

// Get all active locations
$locations_sql = "SELECT location_id, location_name, location_type 
                  FROM inventory_locations 
                  WHERE is_active = 1 
                  ORDER BY location_type, location_name";
$locations_result = $mysqli->query($locations_sql);
while ($location = $locations_result->fetch_assoc()) {
    $locations[] = $location;
}

// Handle form submission for new adjustment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_adjustment'])) {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    $item_id = intval($_POST['item_id']);
    $location_id = intval($_POST['location_id']);
    $adjustment_type = sanitizeInput($_POST['adjustment_type']);
    $quantity = intval($_POST['quantity']);
    $reason = sanitizeInput($_POST['reason']);
    $notes = sanitizeInput($_POST['notes']);

    // Validate CSRF token
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        header("Location: inventory_adjustments.php");
        exit;
    }

    // Validate required fields
    if ($item_id <= 0 || $location_id <= 0 || $quantity <= 0 || empty($reason)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please fill in all required fields with valid values.";
        header("Location: inventory_adjustments.php");
        exit;
    }

    // Start transaction
    $mysqli->begin_transaction();

    try {
        // Get current item and location data
        $current_data_sql = "SELECT i.item_name, i.item_code, i.item_quantity as total_quantity,
                                    l.location_name,
                                    COALESCE(ili.quantity, 0) as location_quantity
                             FROM inventory_items i
                             JOIN inventory_locations l ON l.location_id = ?
                             LEFT JOIN inventory_location_items ili ON (ili.item_id = i.item_id AND ili.location_id = ?)
                             WHERE i.item_id = ?";
        $current_stmt = $mysqli->prepare($current_data_sql);
        $current_stmt->bind_param("iii", $location_id, $location_id, $item_id);
        $current_stmt->execute();
        $current_result = $current_stmt->get_result();
        
        if ($current_result->num_rows === 0) {
            throw new Exception("Item or location not found.");
        }
        
        $current_data = $current_result->fetch_assoc();
        $current_stmt->close();

        $current_location_quantity = $current_data['location_quantity'];
        $current_total_quantity = $current_data['total_quantity'];
        $item_name = $current_data['item_name'];
        $item_code = $current_data['item_code'];
        $location_name = $current_data['location_name'];

        // Calculate new quantities
        if ($adjustment_type === 'add') {
            $new_location_quantity = $current_location_quantity + $quantity;
            $new_total_quantity = $current_total_quantity + $quantity;
            $transaction_type = 'Adjustment In';
            $quantity_change = $quantity;
        } else {
            $new_location_quantity = $current_location_quantity - $quantity;
            $new_total_quantity = $current_total_quantity - $quantity;
            $transaction_type = 'Adjustment Out';
            $quantity_change = -$quantity;

            // Check if sufficient stock exists
            if ($new_location_quantity < 0) {
                throw new Exception("Insufficient stock in location. Available: " . $current_location_quantity);
            }
            if ($new_total_quantity < 0) {
                throw new Exception("Insufficient total stock. Available: " . $current_total_quantity);
            }
        }

        // Update or insert location item quantity
        if ($current_location_quantity > 0) {
            // Update existing location item
            $update_location_sql = "UPDATE inventory_location_items 
                                   SET quantity = ?, updated_at = NOW() 
                                   WHERE item_id = ? AND location_id = ?";
            $update_stmt = $mysqli->prepare($update_location_sql);
            $update_stmt->bind_param("iii", $new_location_quantity, $item_id, $location_id);
        } else {
            // Insert new location item
            $update_location_sql = "INSERT INTO inventory_location_items 
                                   (item_id, location_id, quantity, low_stock_alert) 
                                   VALUES (?, ?, ?, 0)";
            $update_stmt = $mysqli->prepare($update_location_sql);
            $update_stmt->bind_param("iii", $item_id, $location_id, $new_location_quantity);
        }

        if (!$update_stmt->execute()) {
            throw new Exception("Failed to update location stock: " . $update_stmt->error);
        }
        $update_stmt->close();

        // Update main item total quantity and status
        $new_status = calculateStockStatus($new_total_quantity, $item_id);
        
        $update_item_sql = "UPDATE inventory_items 
                           SET item_quantity = ?, item_status = ?, item_updated_by = ?, item_updated_date = NOW() 
                           WHERE item_id = ?";
        $update_item_stmt = $mysqli->prepare($update_item_sql);
        $update_item_stmt->bind_param("isii", $new_total_quantity, $new_status, $session_user_id, $item_id);
        
        if (!$update_item_stmt->execute()) {
            throw new Exception("Failed to update item total quantity: " . $update_item_stmt->error);
        }
        $update_item_stmt->close();

        // Record adjustment transaction using the correct field names
        $transaction_reference = "ADJ-" . strtoupper($adjustment_type) . "-" . date('Ymd-His');
        $transaction_notes = "Stock adjustment: " . $quantity . " units " . 
                           ($adjustment_type === 'add' ? 'added to' : 'removed from') . 
                           " location '" . $location_name . "' - Reason: " . $reason;
        if (!empty($notes)) {
            $transaction_notes .= " - " . $notes;
        }

        $transaction_sql = "INSERT INTO inventory_transactions (
            item_id, location_id, transaction_type, quantity_change, previous_quantity, new_quantity,
            transaction_reference, transaction_notes, performed_by, adjustment_date
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $trans_stmt = $mysqli->prepare($transaction_sql);
        $trans_stmt->bind_param(
            "iisiiissi",
            $item_id,
            $location_id,
            $transaction_type,
            $quantity_change,
            $current_location_quantity,
            $new_location_quantity,
            $transaction_reference,
            $transaction_notes,
            $session_user_id
        );
        
        if (!$trans_stmt->execute()) {
            throw new Exception("Failed to record adjustment transaction: " . $trans_stmt->error);
        }
        $trans_stmt->close();

        // Log the action
        $log_sql = "INSERT INTO logs SET
                  log_type = 'Inventory',
                  log_action = 'Adjust',
                  log_description = ?,
                  log_ip = ?,
                  log_user_agent = ?,
                  log_user_id = ?,
                  log_entity_id = ?,
                  log_created_at = NOW()";
        $log_stmt = $mysqli->prepare($log_sql);
        $log_description = "Stock adjustment: " . $quantity . " units " . 
                         ($adjustment_type === 'add' ? 'added to' : 'removed from') . 
                         " " . $item_name . " at " . $location_name . " - " . $reason;
        $log_stmt->bind_param("sssii", $log_description, $session_ip, $session_user_agent, $session_user_id, $item_id);
        $log_stmt->execute();
        $log_stmt->close();

        $mysqli->commit();

        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Stock adjustment completed successfully!";
        
        header("Location: inventory_adjustments.php");
        exit;

    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error processing adjustment: " . $e->getMessage();
        header("Location: inventory_adjustments.php");
        exit;
    }
}

// Search and filter parameters
$q = sanitizeInput($_GET['q'] ?? '');
$item_filter = intval($_GET['item'] ?? 0);
$location_filter = intval($_GET['location'] ?? 0);
$type_filter = sanitizeInput($_GET['type'] ?? '');
$date_from = sanitizeInput($_GET['date_from'] ?? '');
$date_to = sanitizeInput($_GET['date_to'] ?? '');

// Build search query
$search_query = '';
if (!empty($q)) {
    $search_query = "AND (
        i.item_name LIKE '%$q%' 
        OR i.item_code LIKE '%$q%'
        OR t.transaction_reference LIKE '%$q%'
        OR t.transaction_notes LIKE '%$q%'
        OR u.user_name LIKE '%$q%'
    )";
}

if ($item_filter > 0) {
    $search_query .= " AND t.item_id = $item_filter";
}

if ($location_filter > 0) {
    $search_query .= " AND t.location_id = $location_filter";
}

if ($type_filter) {
    $search_query .= " AND t.transaction_type = '$type_filter'";
}

if (!empty($date_from)) {
    $search_query .= " AND DATE(t.transaction_date) >= '$date_from'";
}

if (!empty($date_to)) {
    $search_query .= " AND DATE(t.transaction_date) <= '$date_to'";
}

// Get adjustment history from inventory_transactions table
$adjustments_sql = "
    SELECT SQL_CALC_FOUND_ROWS 
        t.transaction_id,
        t.item_id,
        t.location_id,
        t.transaction_type,
        t.quantity_change,
        t.previous_quantity,
        t.new_quantity,
        t.transaction_date,
        t.transaction_reference,
        t.transaction_notes,
        t.performed_by,
        t.adjustment_date,
        i.item_name,
        i.item_code,
        l.location_name,
        l.location_type,
        u.user_name as performed_by_name
    FROM inventory_transactions t
    JOIN inventory_items i ON t.item_id = i.item_id
    LEFT JOIN inventory_locations l ON t.location_id = l.location_id
    LEFT JOIN users u ON t.performed_by = u.user_id
    WHERE t.transaction_type IN ('adjustment_in', 'adjustment_out')
      $search_query
    ORDER BY t.$sort $order
    LIMIT $record_from, $record_to
";

$adjustments_result = $mysqli->query($adjustments_sql);
if (!$adjustments_result) {
    die("Query failed: " . $mysqli->error);
}

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get adjustment statistics
$stats_sql = "
    SELECT 
        COUNT(*) as total_adjustments,
        SUM(CASE WHEN transaction_type = 'adjustment_in' THEN 1 ELSE 0 END) as add_count,
        SUM(CASE WHEN transaction_type = 'adjustment_out' THEN 1 ELSE 0 END) as remove_count,
        SUM(CASE WHEN transaction_type = 'adjustment_in' THEN quantity_change ELSE 0 END) as total_added,
        SUM(CASE WHEN transaction_type = 'adjustment_out' THEN ABS(quantity_change) ELSE 0 END) as total_removed
    FROM inventory_transactions 
    WHERE transaction_type IN ('adjustment_in', 'adjustment_out')
      $search_query
";

$stats_result = $mysqli->query($stats_sql);
$stats = $stats_result->fetch_assoc();

function calculateStockStatus($quantity, $item_id) {
    global $mysqli;
    
    $sql = "SELECT item_low_stock_alert FROM inventory_items WHERE item_id = ?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("i", $item_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $item = $result->fetch_assoc();
    $stmt->close();
    
    $low_stock_alert = $item['item_low_stock_alert'] ?? 10;
    
    if ($quantity <= 0) {
        return 'Out of Stock';
    } elseif ($quantity <= $low_stock_alert) {
        return 'Low Stock';
    } else {
        return 'In Stock';
    }
}
?>

<div class="card">
    <div class="card-header bg-warning py-2">
        <h3 class="card-title mt-2 mb-0"><i class="fas fa-fw fa-adjust mr-2"></i>Stock Adjustments</h3>
        <div class="card-tools">
            <button type="button" class="btn btn-success" data-toggle="modal" data-target="#addAdjustmentModal">
                <i class="fas fa-plus mr-2"></i>New Adjustment
            </button>
        </div>
    </div>

    <!-- Stats Overview -->
    <div class="card-body bg-light border-bottom">
        <div class="row text-center">
            <div class="col-md-3 mb-3">
                <div class="card bg-primary text-white">
                    <div class="card-body py-3">
                        <i class="fas fa-exchange-alt fa-2x mb-2"></i>
                        <h5 class="card-title">Total Adjustments</h5>
                        <h3 class="font-weight-bold"><?php echo $stats['total_adjustments']; ?></h3>
                        <small class="opacity-8">All time</small>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 mb-3">
                <div class="card bg-success text-white">
                    <div class="card-body py-3">
                        <i class="fas fa-arrow-down fa-2x mb-2"></i>
                        <h5 class="card-title">Stock Added</h5>
                        <h3 class="font-weight-bold"><?php echo $stats['add_count']; ?></h3>
                        <small class="opacity-8"><?php echo $stats['total_added']; ?> units</small>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 mb-3">
                <div class="card bg-danger text-white">
                    <div class="card-body py-3">
                        <i class="fas fa-arrow-up fa-2x mb-2"></i>
                        <h5 class="card-title">Stock Removed</h5>
                        <h3 class="font-weight-bold"><?php echo $stats['remove_count']; ?></h3>
                        <small class="opacity-8"><?php echo $stats['total_removed']; ?> units</small>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 mb-3">
                <div class="card bg-info text-white">
                    <div class="card-body py-3">
                        <i class="fas fa-balance-scale fa-2x mb-2"></i>
                        <h5 class="card-title">Net Change</h5>
                        <h3 class="font-weight-bold"><?php echo $stats['total_added'] - $stats['total_removed']; ?></h3>
                        <small class="opacity-8">units</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card-header pb-2 pt-3">
        <form autocomplete="off">
            <div class="row">
                <div class="col-md-5">
                    <div class="form-group">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Search items, references, notes..." autofocus>
                            <div class="input-group-append">
                                <button class="btn btn-secondary" type="button" data-toggle="collapse" data-target="#advancedFilter"><i class="fas fa-filter"></i></button>
                                <button class="btn btn-primary"><i class="fa fa-search"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-7">
                    <div class="btn-toolbar form-group float-right">
                        <div class="btn-group">
                            <span class="btn btn-light border">
                                <i class="fas fa-adjust text-warning mr-1"></i>
                                Adjustments: <strong><?php echo $stats['total_adjustments']; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-arrow-down text-success mr-1"></i>
                                Added: <strong><?php echo $stats['add_count']; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-arrow-up text-danger mr-1"></i>
                                Removed: <strong><?php echo $stats['remove_count']; ?></strong>
                            </span>
                            <a href="inventory_items.php" class="btn btn-info ml-2">
                                <i class="fas fa-fw fa-boxes mr-2"></i>View Items
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php if ($item_filter || $location_filter || $type_filter || $date_from || $date_to) { echo "show"; } ?>" id="advancedFilter">
                <div class="row">
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Item</label>
                            <select class="form-control select2" name="item">
                                <option value="">- All Items -</option>
                                <?php foreach ($items as $item): ?>
                                    <option value="<?php echo $item['item_id']; ?>" <?php if ($item_filter == $item['item_id']) echo "selected"; ?>>
                                        <?php echo htmlspecialchars($item['item_name']); ?> (<?php echo htmlspecialchars($item['item_code']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Location</label>
                            <select class="form-control select2" name="location">
                                <option value="">- All Locations -</option>
                                <?php foreach ($locations as $location): ?>
                                    <option value="<?php echo $location['location_id']; ?>" <?php if ($location_filter == $location['location_id']) echo "selected"; ?>>
                                        <?php echo htmlspecialchars($location['location_name']); ?> (<?php echo htmlspecialchars($location['location_type']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Adjustment Type</label>
                            <select class="form-control select2" name="type">
                                <option value="">- All Types -</option>
                                <option value="adjustment_in" <?php if ($type_filter == 'adjustment_in') echo "selected"; ?>>Stock Added</option>
                                <option value="adjustment_out" <?php if ($type_filter == 'adjustment_out') echo "selected"; ?>>Stock Removed</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Date From</label>
                            <input type="date" class="form-control" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Date To</label>
                            <input type="date" class="form-control" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-12">
                        <div class="form-group">
                            <label>Quick Actions</label>
                            <div class="btn-group">
                                <a href="inventory_adjustments.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times mr-2"></i>Clear Filters
                                </a>
                                <button type="button" class="btn btn-success" data-toggle="modal" data-target="#addAdjustmentModal">
                                    <i class="fas fa-plus mr-2"></i>New Adjustment
                                </button>
                                <a href="inventory_transactions.php?type=adjustment" class="btn btn-info">
                                    <i class="fas fa-list mr-2"></i>View All Transactions
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
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

        <div class="table-responsive-sm">
            <table class="table table-hover mb-0">
                <thead class="<?php if ($num_rows[0] == 0) { echo "d-none"; } ?> bg-light">
                <tr>
                    <th>
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=transaction_date&order=<?php echo $disp; ?>">
                            Date & Time <?php if ($sort == 'transaction_date') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>Item</th>
                    <th>Location</th>
                    <th class="text-center">Type</th>
                    <th class="text-center">Quantity Change</th>
                    <th class="text-center">Previous Qty</th>
                    <th class="text-center">New Qty</th>
                    <th>Reason</th>
                    <th>Performed By</th>
                    <th class="text-center">Reference</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($num_rows[0] > 0): ?>
                    <?php while ($adjustment = $adjustments_result->fetch_assoc()): ?>
                        <?php
                        $is_addition = $adjustment['transaction_type'] === 'adjustment_in';
                        $type_color = $is_addition ? 'success' : 'danger';
                        $type_icon = $is_addition ? 'arrow-down' : 'arrow-up';
                        $type_text = $is_addition ? 'Stock Added' : 'Stock Removed';
                        ?>
                        <tr>
                            <td>
                                <small><?php echo date('M j, Y', strtotime($adjustment['transaction_date'])); ?></small>
                                <br><small class="text-muted"><?php echo date('H:i', strtotime($adjustment['transaction_date'])); ?></small>
                            </td>
                            <td>
                                <div class="font-weight-bold"><?php echo htmlspecialchars($adjustment['item_name']); ?></div>
                                <small class="text-muted"><?php echo htmlspecialchars($adjustment['item_code']); ?></small>
                            </td>
                            <td>
                                <?php if ($adjustment['location_name']): ?>
                                    <span class="font-weight-bold"><?php echo htmlspecialchars($adjustment['location_name']); ?></span>
                                    <br><small class="text-muted"><?php echo htmlspecialchars($adjustment['location_type']); ?></small>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <span class="badge badge-<?php echo $type_color; ?>">
                                    <i class="fas fa-<?php echo $type_icon; ?> mr-1"></i>
                                    <?php echo $type_text; ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <span class="font-weight-bold text-<?php echo $type_color; ?>">
                                    <?php echo $is_addition ? '+' : '-'; ?><?php echo abs($adjustment['quantity_change']); ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <span class="text-muted"><?php echo $adjustment['previous_quantity']; ?></span>
                            </td>
                            <td class="text-center">
                                <span class="font-weight-bold"><?php echo $adjustment['new_quantity']; ?></span>
                            </td>
                            <td>
                                <small><?php echo htmlspecialchars($adjustment['transaction_notes']); ?></small>
                            </td>
                            <td>
                                <small><?php echo htmlspecialchars($adjustment['performed_by_name']); ?></small>
                            </td>
                            <td class="text-center">
                                <small class="text-muted"><?php echo htmlspecialchars($adjustment['transaction_reference']); ?></small>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="10" class="text-center py-5">
                            <i class="fas fa-adjust fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">No stock adjustments found</h5>
                            <p class="text-muted">
                                <?php if ($q || $item_filter || $location_filter || $type_filter || $date_from || $date_to): ?>
                                    Try adjusting your search or filter criteria.
                                <?php else: ?>
                                    Get started by making your first stock adjustment.
                                <?php endif; ?>
                            </p>
                            <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#addAdjustmentModal">
                                <i class="fas fa-plus mr-2"></i>Make First Adjustment
                            </button>
                            <?php if ($q || $item_filter || $location_filter || $type_filter || $date_from || $date_to): ?>
                                <a href="inventory_adjustments.php" class="btn btn-secondary ml-2">
                                    <i class="fas fa-times mr-2"></i>Clear Filters
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/filter_footer.php'; ?>
    </div>
</div>

<!-- Add Adjustment Modal -->
<div class="modal fade" id="addAdjustmentModal">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">New Stock Adjustment</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form method="POST" id="adjustmentForm">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="item_id">Item *</label>
                                <select class="form-control select2" id="item_id" name="item_id" required>
                                    <option value="">- Select Item -</option>
                                    <?php foreach ($items as $item): ?>
                                        <option value="<?php echo $item['item_id']; ?>" data-quantity="<?php echo $item['item_quantity']; ?>">
                                            <?php echo htmlspecialchars($item['item_name']); ?> (<?php echo htmlspecialchars($item['item_code']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="form-text text-muted">Select the item to adjust</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="location_id">Location *</label>
                                <select class="form-control select2" id="location_id" name="location_id" required>
                                    <option value="">- Select Location -</option>
                                    <?php foreach ($locations as $location): ?>
                                        <option value="<?php echo $location['location_id']; ?>">
                                            <?php echo htmlspecialchars($location['location_name']); ?> (<?php echo htmlspecialchars($location['location_type']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="form-text text-muted">Select the location where stock is adjusted</small>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="adjustment_type">Adjustment Type *</label>
                                <select class="form-control" id="adjustment_type" name="adjustment_type" required>
                                    <option value="add">Add Stock</option>
                                    <option value="remove">Remove Stock</option>
                                </select>
                                <small class="form-text text-muted">Choose whether to add or remove stock</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="quantity">Quantity *</label>
                                <input type="number" class="form-control" id="quantity" name="quantity" 
                                       min="1" value="1" required>
                                <small class="form-text text-muted">Number of units to adjust</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Current Stock</label>
                                <input type="text" class="form-control" id="current_stock" readonly value="Select item and location">
                                <small class="form-text text-muted">Available stock in selected location</small>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="reason">Reason for Adjustment *</label>
                        <select class="form-control" id="reason" name="reason" required>
                            <option value="">- Select Reason -</option>
                            <option value="Found stock">Found stock</option>
                            <option value="Counting error">Counting error</option>
                            <option value="Damaged goods">Damaged goods</option>
                            <option value="Theft/Loss">Theft/Loss</option>
                            <option value="Expired items">Expired items</option>
                            <option value="Quality control">Quality control</option>
                            <option value="Return from customer">Return from customer</option>
                            <option value="Other">Other</option>
                        </select>
                        <small class="form-text text-muted">Explain why this adjustment is necessary</small>
                    </div>

                    <div class="form-group">
                        <label for="notes">Additional Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="3" 
                                  placeholder="Any additional details about this adjustment..." 
                                  maxlength="500"></textarea>
                        <small class="form-text text-muted">Optional notes for audit trail</small>
                    </div>

                    <!-- Adjustment Preview -->
                    <div class="card card-info mt-3">
                        <div class="card-header">
                            <h6 class="card-title mb-0"><i class="fas fa-eye mr-2"></i>Adjustment Preview</h6>
                        </div>
                        <div class="card-body">
                            <div class="row text-center">
                                <div class="col-4">
                                    <div class="font-weight-bold text-muted">Current Stock</div>
                                    <div id="preview_current" class="h5">-</div>
                                </div>
                                <div class="col-4">
                                    <div class="font-weight-bold text-muted">Adjustment</div>
                                    <div id="preview_change" class="h5">-</div>
                                </div>
                                <div class="col-4">
                                    <div class="font-weight-bold text-muted">New Stock</div>
                                    <div id="preview_new" class="h5">-</div>
                                </div>
                            </div>
                            <div class="text-center mt-2">
                                <span id="preview_status" class="badge badge-warning">Pending</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_adjustment" class="btn btn-primary">Process Adjustment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.select2').select2();

    // Update current stock when item or location changes
    function updateCurrentStock() {
        var itemId = $('#item_id').val();
        var locationId = $('#location_id').val();
        
        if (itemId && locationId) {
            // Make AJAX call to get current stock
            $('#current_stock').val('Checking...');
            
            $.ajax({
                url: 'ajax/get_location_stock.php',
                type: 'POST',
                data: {
                    item_id: itemId,
                    location_id: locationId,
                    csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'
                },
                success: function(response) {
                    var data = JSON.parse(response);
                    if (data.success) {
                        $('#current_stock').val(data.current_stock);
                    } else {
                        $('#current_stock').val('Error: ' + data.message);
                    }
                    updatePreview();
                },
                error: function() {
                    $('#current_stock').val('Error loading data');
                    updatePreview();
                }
            });
        } else {
            $('#current_stock').val('Select item and location');
            updatePreview();
        }
    }

    // Update adjustment preview
    function updatePreview() {
        var adjustmentType = $('#adjustment_type').val();
        var quantity = parseInt($('#quantity').val()) || 0;
        var currentStock = parseInt($('#current_stock').val()) || 0;
        
        if (isNaN(currentStock)) {
            $('#preview_current').text('-').removeClass('text-success text-danger');
            $('#preview_change').text('-').removeClass('text-success text-danger');
            $('#preview_new').text('-').removeClass('text-success text-danger');
            $('#preview_status').text('Select item and location').removeClass('badge-success badge-danger').addClass('badge-warning');
            return;
        }
        
        var changeText, newStock, changeClass, statusText, statusClass;
        
        if (adjustmentType === 'add') {
            changeText = '+' + quantity;
            newStock = currentStock + quantity;
            changeClass = 'text-success';
            statusText = 'Stock will be added';
            statusClass = 'badge-success';
        } else {
            changeText = '-' + quantity;
            newStock = currentStock - quantity;
            changeClass = 'text-danger';
            
            if (newStock < 0) {
                statusText = 'Insufficient stock';
                statusClass = 'badge-danger';
            } else {
                statusText = 'Stock will be removed';
                statusClass = 'badge-warning';
            }
        }
        
        $('#preview_current').text(currentStock).removeClass('text-success text-danger');
        $('#preview_change').text(changeText).removeClass('text-success text-danger').addClass(changeClass);
        $('#preview_new').text(newStock).removeClass('text-success text-danger');
        $('#preview_status').text(statusText).removeClass('badge-success badge-danger badge-warning').addClass(statusClass);
    }

    // Event listeners
    $('#item_id, #location_id').on('change', updateCurrentStock);
    $('#adjustment_type, #quantity').on('change input', updatePreview);

    // Form validation
    $('#adjustmentForm').on('submit', function(e) {
        var adjustmentType = $('#adjustment_type').val();
        var quantity = parseInt($('#quantity'].val()) || 0;
        var currentStock = parseInt($('#current_stock'].val()) || 0;
        var reason = $('#reason').val();
        
        if (quantity <= 0) {
            e.preventDefault();
            alert('Quantity must be greater than zero.');
            return false;
        }
        
        if (!reason) {
            e.preventDefault();
            alert('Please select a reason for the adjustment.');
            return false;
        }
        
        if (adjustmentType === 'remove' && quantity > currentStock) {
            e.preventDefault();
            alert('Cannot remove more items than available in stock. Available: ' + currentStock);
            return false;
        }
        
        // Show loading state
        $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Processing...').prop('disabled', true);
    });

    // Initialize preview
    updatePreview();
});

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + N for new adjustment
    if (e.ctrlKey && e.keyCode === 78) {
        e.preventDefault();
        $('#addAdjustmentModal').modal('show');
    }
    // Ctrl + F for focus search
    if (e.ctrlKey && e.keyCode === 70) {
        e.preventDefault();
        $('input[name="q"]').focus();
    }
});
</script>

<style>
.select2-container .select2-selection--single {
    height: 38px;
}

.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 36px;
}

.card .card-body {
    padding: 1rem;
}

.badge-pill {
    padding: 0.5em 0.8em;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
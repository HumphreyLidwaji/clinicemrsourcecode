<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Handle form submissions with proper security
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token'] ?? '');
    
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        header("Location: inventory_locations.php");
        exit;
    }

    if (isset($_POST['add_location'])) {
        addLocation();
    } elseif (isset($_POST['edit_location'])) {
        editLocation();
    }
}

// Handle GET actions with security checks
if (isset($_GET['delete_location'])) {
    deleteLocation();
} elseif (isset($_GET['toggle_status'])) {
    toggleLocationStatus();
}

function addLocation() {
    global $mysqli, $session_user_id, $session_ip, $session_user_agent;
    
    $location_name = sanitizeInput($_POST['location_name']);
    $location_description = sanitizeInput($_POST['location_description']);
    $location_type = sanitizeInput($_POST['location_type']);
    
    // Validate required fields
    if (empty($location_name) || empty($location_type)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Location name and type are required.";
        return;
    }
    
    $mysqli->begin_transaction();
    
    try {
        // Check if location already exists using prepared statement
        $check_sql = "SELECT location_id FROM inventory_locations WHERE location_name = ?";
        $check_stmt = $mysqli->prepare($check_sql);
        $check_stmt->bind_param("s", $location_name);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            throw new Exception("Location '$location_name' already exists!");
        }
        $check_stmt->close();
        
        // Insert new location
        $insert_sql = "INSERT INTO inventory_locations SET 
                      location_name = ?, 
                      location_description = ?, 
                      location_type = ?,
                      created_by = ?,
                      created_at = NOW()";
        
        $insert_stmt = $mysqli->prepare($insert_sql);
        $insert_stmt->bind_param("sssi", $location_name, $location_description, $location_type, $session_user_id);
        
        if (!$insert_stmt->execute()) {
            throw new Exception("Failed to add location: " . $insert_stmt->error);
        }
        
        $new_location_id = $insert_stmt->insert_id;
        $insert_stmt->close();
        
        // Log the action
        $log_sql = "INSERT INTO logs SET
                  log_type = 'Inventory',
                  log_action = 'Location Create',
                  log_description = ?,
                  log_ip = ?,
                  log_user_agent = ?,
                  log_user_id = ?,
                  log_entity_id = ?,
                  log_created_at = NOW()";
        $log_stmt = $mysqli->prepare($log_sql);
        $log_description = "Created new inventory location: " . $location_name . " (Type: " . $location_type . ")";
        $log_stmt->bind_param("sssii", $log_description, $session_ip, $session_user_agent, $session_user_id, $new_location_id);
        $log_stmt->execute();
        $log_stmt->close();
        
        $mysqli->commit();
        
        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Location '$location_name' added successfully!";
        
    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = $e->getMessage();
    }
}

function editLocation() {
    global $mysqli, $session_user_id, $session_ip, $session_user_agent;
    
    $location_id = intval($_POST['location_id']);
    $location_name = sanitizeInput($_POST['location_name']);
    $location_description = sanitizeInput($_POST['location_description']);
    $location_type = sanitizeInput($_POST['location_type']);
    
    // Validate required fields
    if (empty($location_name) || empty($location_type)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Location name and type are required.";
        return;
    }
    
    $mysqli->begin_transaction();
    
    try {
        // Check if name conflicts with other locations
        $check_sql = "SELECT location_id FROM inventory_locations WHERE location_name = ? AND location_id != ?";
        $check_stmt = $mysqli->prepare($check_sql);
        $check_stmt->bind_param("si", $location_name, $location_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            throw new Exception("Location '$location_name' already exists!");
        }
        $check_stmt->close();
        
        // Update location
        $update_sql = "UPDATE inventory_locations SET 
                      location_name = ?, 
                      location_description = ?, 
                      location_type = ?,
                      updated_by = ?,
                      updated_at = NOW()
                      WHERE location_id = ?";
        
        $update_stmt = $mysqli->prepare($update_sql);
        $update_stmt->bind_param("sssii", $location_name, $location_description, $location_type, $session_user_id, $location_id);
        
        if (!$update_stmt->execute()) {
            throw new Exception("Failed to update location: " . $update_stmt->error);
        }
        $update_stmt->close();
        
        // Log the action
        $log_sql = "INSERT INTO logs SET
                  log_type = 'Inventory',
                  log_action = 'Location Update',
                  log_description = ?,
                  log_ip = ?,
                  log_user_agent = ?,
                  log_user_id = ?,
                  log_entity_id = ?,
                  log_created_at = NOW()";
        $log_stmt = $mysqli->prepare($log_sql);
        $log_description = "Updated inventory location: " . $location_name . " (Type: " . $location_type . ")";
        $log_stmt->bind_param("sssii", $log_description, $session_ip, $session_user_agent, $session_user_id, $location_id);
        $log_stmt->execute();
        $log_stmt->close();
        
        $mysqli->commit();
        
        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Location updated successfully!";
        
    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = $e->getMessage();
    }
}

function deleteLocation() {
    global $mysqli, $session_user_id, $session_ip, $session_user_agent;
    
    $location_id = intval($_GET['delete_location']);
    
    // Validate location exists
    $location_sql = "SELECT location_name FROM inventory_locations WHERE location_id = ?";
    $location_stmt = $mysqli->prepare($location_sql);
    $location_stmt->bind_param("i", $location_id);
    $location_stmt->execute();
    $location_result = $location_stmt->get_result();
    
    if ($location_result->num_rows === 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Location not found.";
        return;
    }
    
    $location = $location_result->fetch_assoc();
    $location_stmt->close();
    
    $mysqli->begin_transaction();
    
    try {
        // Check if location has stock in inventory_location_stock
        $stock_check = "SELECT stock_id FROM inventory_location_stock WHERE location_id = ? LIMIT 1";
        $stock_stmt = $mysqli->prepare($stock_check);
        $stock_stmt->bind_param("i", $location_id);
        $stock_stmt->execute();
        $stock_result = $stock_stmt->get_result();
        
        if ($stock_result->num_rows > 0) {
            throw new Exception("Cannot delete location - there is stock assigned to this location. Please transfer or remove stock first.");
        }
        $stock_stmt->close();
        
        // Check if location is referenced in transactions
        $transaction_check = "SELECT transaction_id FROM inventory_transactions WHERE from_location_id = ? OR to_location_id = ? LIMIT 1";
        $transaction_stmt = $mysqli->prepare($transaction_check);
        $transaction_stmt->bind_param("ii", $location_id, $location_id);
        $transaction_stmt->execute();
        $transaction_result = $transaction_stmt->get_result();
        
        if ($transaction_result->num_rows > 0) {
            throw new Exception("Cannot delete location - it is referenced in transactions. Please archive instead.");
        }
        $transaction_stmt->close();
        
        // Check if location is used in requisitions
        $requisition_check = "SELECT requisition_id FROM inventory_requisitions WHERE from_location_id = ? OR delivery_location_id = ? LIMIT 1";
        $requisition_stmt = $mysqli->prepare($requisition_check);
        $requisition_stmt->bind_param("ii", $location_id, $location_id);
        $requisition_stmt->execute();
        $requisition_result = $requisition_stmt->get_result();
        
        if ($requisition_result->num_rows > 0) {
            throw new Exception("Cannot delete location - it is used in requisitions. Please archive instead.");
        }
        $requisition_stmt->close();
        
        // Delete location
        $delete_sql = "DELETE FROM inventory_locations WHERE location_id = ?";
        $delete_stmt = $mysqli->prepare($delete_sql);
        $delete_stmt->bind_param("i", $location_id);
        
        if (!$delete_stmt->execute()) {
            throw new Exception("Failed to delete location: " . $delete_stmt->error);
        }
        $delete_stmt->close();
        
        // Log the action
        $log_sql = "INSERT INTO logs SET
                  log_type = 'Inventory',
                  log_action = 'Location Delete',
                  log_description = ?,
                  log_ip = ?,
                  log_user_agent = ?,
                  log_user_id = ?,
                  log_entity_id = ?,
                  log_created_at = NOW()";
        $log_stmt = $mysqli->prepare($log_sql);
        $log_description = "Deleted inventory location: " . $location['location_name'];
        $log_stmt->bind_param("sssii", $log_description, $session_ip, $session_user_agent, $session_user_id, $location_id);
        $log_stmt->execute();
        $log_stmt->close();
        
        $mysqli->commit();
        
        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Location deleted successfully!";
        
    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = $e->getMessage();
    }
}

function toggleLocationStatus() {
    global $mysqli, $session_user_id, $session_ip, $session_user_agent;
    
    $location_id = intval($_GET['toggle_status']);
    
    $mysqli->begin_transaction();
    
    try {
        // Get current status
        $status_sql = "SELECT location_name, is_active FROM inventory_locations WHERE location_id = ?";
        $status_stmt = $mysqli->prepare($status_sql);
        $status_stmt->bind_param("i", $location_id);
        $status_stmt->execute();
        $status_result = $status_stmt->get_result();
        
        if ($status_result->num_rows === 0) {
            throw new Exception("Location not found.");
        }
        
        $location = $status_result->fetch_assoc();
        $status_stmt->close();
        
        $new_status = $location['is_active'] ? 0 : 1;
        
        // Update status
        $toggle_sql = "UPDATE inventory_locations SET is_active = ?, updated_by = ?, updated_at = NOW() WHERE location_id = ?";
        $toggle_stmt = $mysqli->prepare($toggle_sql);
        $toggle_stmt->bind_param("iii", $new_status, $session_user_id, $location_id);
        
        if (!$toggle_stmt->execute()) {
            throw new Exception("Failed to update location status: " . $toggle_stmt->error);
        }
        $toggle_stmt->close();
        
        // Log the action
        $log_sql = "INSERT INTO logs SET
                  log_type = 'Inventory',
                  log_action = ?,
                  log_description = ?,
                  log_ip = ?,
                  log_user_agent = ?,
                  log_user_id = ?,
                  log_entity_id = ?,
                  log_created_at = NOW()";
        $log_stmt = $mysqli->prepare($log_sql);
        $action = $new_status ? 'Location Activate' : 'Location Deactivate';
        $log_description = ($new_status ? 'Activated' : 'Deactivated') . " inventory location: " . $location['location_name'];
        $log_stmt->bind_param("sssii", $action, $log_description, $session_ip, $session_user_agent, $session_user_id, $location_id);
        $log_stmt->execute();
        $log_stmt->close();
        
        $mysqli->commit();
        
        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Location status updated successfully!";
        
    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = $e->getMessage();
    }
}

// Default Column Sortby/Order Filter
$sort = sanitizeInput($_GET['sort'] ?? "location_name");
$order = sanitizeInput($_GET['order'] ?? "ASC");

// Validate sort column
$allowed_sorts = ['location_name', 'item_count', 'total_value', 'location_type'];
if (!in_array($sort, $allowed_sorts)) {
    $sort = 'location_name';
}

// Validate order
$order = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';

// Search Query
$q = sanitizeInput($_GET['q'] ?? '');
if (!empty($q)) {
    $search_query = "AND (
        location_name LIKE '%$q%' 
        OR location_description LIKE '%$q%'
        OR location_type LIKE '%$q%'
    )";
} else {
    $search_query = '';
}

// Type Filter
$type_filter = sanitizeInput($_GET['type'] ?? '');
if ($type_filter) {
    $type_query = "AND location_type = '$type_filter'";
} else {
    $type_query = '';
}

// Get all locations with statistics
$locations_sql = "
    SELECT SQL_CALC_FOUND_ROWS l.*,
           COUNT(DISTINCT ils.stock_id) as item_count,
           COALESCE(SUM(ils.quantity), 0) as total_quantity,
           COALESCE(SUM(ils.quantity * ils.unit_cost), 0) as total_value
    FROM inventory_locations l
    LEFT JOIN inventory_location_stock ils ON l.location_id = ils.location_id
    WHERE 1=1
      $search_query
      $type_query
      AND l.is_active = 1
      AND ils.is_active = 1
    GROUP BY l.location_id
    ORDER BY $sort $order
    LIMIT $record_from, $record_to";

$locations_result = $mysqli->query($locations_sql);
if (!$locations_result) {
    die("Query failed: " . $mysqli->error);
}

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get location statistics
$total_locations_result = $mysqli->query("SELECT COUNT(*) FROM inventory_locations WHERE is_active = 1");
$total_locations = $total_locations_result->fetch_row()[0];

$active_locations_result = $mysqli->query("SELECT COUNT(*) FROM inventory_locations WHERE is_active = 1");
$active_locations = $active_locations_result->fetch_row()[0];

$total_items_result = $mysqli->query("SELECT COUNT(DISTINCT ils.batch_id) FROM inventory_location_stock ils WHERE ils.is_active = 1");
$total_items = $total_items_result->fetch_row()[0];

$total_value_result = $mysqli->query("SELECT COALESCE(SUM(ils.quantity * ils.unit_cost), 0) FROM inventory_location_stock ils WHERE ils.is_active = 1");
$total_value = $total_value_result->fetch_row()[0] ?? 0;

// Get unique location types for filter
$types_sql = $mysqli->query("SELECT DISTINCT location_type FROM inventory_locations WHERE is_active = 1 ORDER BY location_type");
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0 text-white"><i class="fas fa-fw fa-map-marker-alt mr-2"></i>Inventory Locations Management</h3>
        <div class="card-tools">
            <button type="button" class="btn btn-success" data-toggle="modal" data-target="#addLocationModal">
                <i class="fas fa-plus mr-2"></i>New Location
            </button>
        </div>
    </div>

    <?php if (isset($_SESSION['alert_message'])): ?>
    <div class="card-body border-bottom py-2">
        <div class="alert alert-<?php echo $_SESSION['alert_type']; ?> alert-dismissible mb-0">
            <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
            <i class="icon fas fa-<?php echo $_SESSION['alert_type'] == 'success' ? 'check' : 'exclamation-triangle'; ?>"></i>
            <?php echo $_SESSION['alert_message']; ?>
        </div>
        <?php 
        unset($_SESSION['alert_type']);
        unset($_SESSION['alert_message']);
        ?>
    </div>
    <?php endif; ?>

    <div class="card-header pb-2 pt-3">
        <form autocomplete="off">
            <input type="hidden" name="sort" value="<?php echo $sort; ?>">
            <input type="hidden" name="order" value="<?php echo $order; ?>">
            <div class="row">
                <div class="col-md-5">
                    <div class="form-group">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Search locations, descriptions..." autofocus>
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
                                <i class="fas fa-map-marker-alt text-primary mr-1"></i>
                                Locations: <strong><?php echo $total_locations; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-boxes text-info mr-1"></i>
                                Batches: <strong><?php echo $total_items; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-dollar-sign text-success mr-1"></i>
                                Value: <strong>$<?php echo number_format($total_value, 2); ?></strong>
                            </span>
                            <a href="inventory_items.php" class="btn btn-info ml-2">
                                <i class="fas fa-fw fa-boxes mr-2"></i>View Items
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php if ($type_filter || !empty($q)) { echo "show"; } ?>" id="advancedFilter">
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Location Type</label>
                            <select class="form-control select2" name="type" onchange="this.form.submit()">
                                <option value="">- All Types -</option>
                                <?php while($type = $types_sql->fetch_assoc()): ?>
                                    <option value="<?php echo htmlspecialchars($type['location_type']); ?>" <?php if ($type_filter == $type['location_type']) { echo "selected"; } ?>>
                                        <?php echo htmlspecialchars($type['location_type']); ?>
                                    </option>
                                <?php endwhile; ?>
                                <?php if ($types_sql->num_rows === 0): ?>
                                    <option value="">No types found</option>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Sort By</label>
                            <select class="form-control select2" name="sort" onchange="this.form.submit()">
                                <option value="location_name" data-order="ASC" <?php if ($sort == "location_name" && $order == "ASC") { echo "selected"; } ?>>Name (A-Z)</option>
                                <option value="location_name" data-order="DESC" <?php if ($sort == "location_name" && $order == "DESC") { echo "selected"; } ?>>Name (Z-A)</option>
                                <option value="item_count" data-order="ASC" <?php if ($sort == "item_count" && $order == "ASC") { echo "selected"; } ?>>Batch Count (Low to High)</option>
                                <option value="item_count" data-order="DESC" <?php if ($sort == "item_count" && $order == "DESC") { echo "selected"; } ?>>Batch Count (High to Low)</option>
                                <option value="total_value" data-order="ASC" <?php if ($sort == "total_value" && $order == "ASC") { echo "selected"; } ?>>Value (Low to High)</option>
                                <option value="total_value" data-order="DESC" <?php if ($sort == "total_value" && $order == "DESC") { echo "selected"; } ?>>Value (High to Low)</option>
                                <option value="location_type" data-order="ASC" <?php if ($sort == "location_type" && $order == "ASC") { echo "selected"; } ?>>Type (A-Z)</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Quick Actions</label>
                            <div class="btn-group btn-block">
                                <a href="inventory_locations.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times mr-2"></i>Clear Filters
                                </a>
                                <button type="button" class="btn btn-success" data-toggle="modal" data-target="#addLocationModal">
                                    <i class="fas fa-plus mr-2"></i>New Location
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <div class="card-body">
        <div class="table-responsive-sm">
            <table class="table table-hover mb-0">
                <thead class="<?php if ($num_rows[0] == 0) { echo "d-none"; } ?> bg-light">
                <tr>
                    <th>
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=location_name&order=<?php echo $disp; ?>">
                            Location Name <?php if ($sort == 'location_name') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>Type</th>
                    <th>Description</th>
                    <th class="text-center">
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=item_count&order=<?php echo $disp; ?>">
                            Batches <?php if ($sort == 'item_count') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th class="text-center">
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=total_value&order=<?php echo $disp; ?>">
                            Total Value <?php if ($sort == 'total_value') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>Status</th>
                    <th class="text-center">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($locations_result->num_rows > 0): ?>
                    <?php while ($location = $locations_result->fetch_assoc()): ?>
                        <tr>
                            <td>
                                <div class="font-weight-bold text-primary">
                                    <i class="fas fa-map-marker-alt mr-2"></i><?php echo htmlspecialchars($location['location_name']); ?>
                                </div>
                                <small class="text-muted">ID: <?php echo $location['location_id']; ?></small>
                            </td>
                            <td>
                                <span class="badge badge-info"><?php echo htmlspecialchars($location['location_type']); ?></span>
                            </td>
                            <td>
                                <?php if (!empty($location['location_description'])): ?>
                                    <small class="text-muted"><?php echo strlen($location['location_description']) > 80 ? substr($location['location_description'], 0, 80) . '...' : $location['location_description']; ?></small>
                                <?php else: ?>
                                    <span class="text-muted"><em>No description</em></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <span class="badge badge-primary badge-pill"><?php echo $location['item_count']; ?></span>
                                <?php if ($location['total_quantity'] > 0): ?>
                                    <br><small class="text-muted"><?php echo number_format($location['total_quantity'], 3); ?> units</small>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <span class="font-weight-bold text-success">$<?php echo number_format($location['total_value'], 2); ?></span>
                            </td>
                            <td>
                                <span class="badge badge-<?php echo $location['is_active'] ? 'success' : 'secondary'; ?>">
                                    <?php echo $location['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </td>
                            <td>
                                <div class="dropdown dropleft text-center">
                                    <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                        <i class="fas fa-ellipsis-h"></i>
                                    </button>
                                    <div class="dropdown-menu">
                                        <button type="button" class="dropdown-item" data-toggle="modal" data-target="#editLocationModal<?php echo $location['location_id']; ?>">
                                            <i class="fas fa-fw fa-edit mr-2"></i>Edit Location
                                        </button>
                                        <a class="dropdown-item" href="inventory_locations_items_view.php?location_id=<?php echo $location['location_id']; ?>">
                                            <i class="fas fa-fw fa-boxes mr-2"></i>View Location Items
                                        </a>
                                        <a class="dropdown-item" href="inventory_location_batches_view.php?location_id=<?php echo $location['location_id']; ?>">
                                            <i class="fas fa-fw fa-layer-group mr-2"></i>View Batches
                                        </a>
                                        <div class="dropdown-divider"></div>
                                        <?php if ($location['is_active']): ?>
                                            <a class="dropdown-item text-warning" href="?toggle_status=<?php echo $location['location_id']; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>">
                                                <i class="fas fa-fw fa-ban mr-2"></i>Deactivate
                                            </a>
                                        <?php else: ?>
                                            <a class="dropdown-item text-success" href="?toggle_status=<?php echo $location['location_id']; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>">
                                                <i class="fas fa-fw fa-check mr-2"></i>Activate
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($location['item_count'] == 0 && !$location['is_active']): ?>
                                            <a class="dropdown-item text-danger confirm-link" href="?delete_location=<?php echo $location['location_id']; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>">
                                                <i class="fas fa-fw fa-trash mr-2"></i>Delete
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                        </tr>

                        <!-- Edit Location Modal -->
                        <div class="modal fade" id="editLocationModal<?php echo $location['location_id']; ?>">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Edit Location</h5>
                                        <button type="button" class="close" data-dismiss="modal">&times;</button>
                                    </div>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="location_id" value="<?php echo $location['location_id']; ?>">
                                        
                                        <div class="modal-body">
                                            <div class="form-group">
                                                <label>Location Name <strong class="text-danger">*</strong></label>
                                                <input type="text" class="form-control" name="location_name" value="<?php echo htmlspecialchars($location['location_name']); ?>" required>
                                            </div>
                                            
                                            <div class="form-group">
                                                <label>Location Type <strong class="text-danger">*</strong></label>
                                                <select class="form-control" name="location_type" required>
                                                    <option value="Store" <?php echo $location['location_type'] == 'Store' ? 'selected' : ''; ?>>Store</option>
                                                    <option value="Pharmacy" <?php echo $location['location_type'] == 'Pharmacy' ? 'selected' : ''; ?>>Pharmacy</option>
                                                    <option value="Ward" <?php echo $location['location_type'] == 'Ward' ? 'selected' : ''; ?>>Ward</option>
                                                    <option value="Theatre" <?php echo $location['location_type'] == 'Theatre' ? 'selected' : ''; ?>>Theatre</option>
                                                    <option value="Lab" <?php echo $location['location_type'] == 'Lab' ? 'selected' : ''; ?>>Lab</option>
                                                    <option value="Other" <?php echo $location['location_type'] == 'Other' ? 'selected' : ''; ?>>Other</option>
                                                </select>
                                            </div>
                                            
                                            <div class="form-group">
                                                <label>Description</label>
                                                <textarea class="form-control" name="location_description" rows="3" placeholder="Location description..."><?php echo htmlspecialchars($location['location_description']); ?></textarea>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                                            <button type="submit" name="edit_location" class="btn btn-primary">Update Location</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" class="text-center py-4">
                            <i class="fas fa-map-marker-alt fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">No Active Locations Found</h5>
                            <p class="text-muted">Get started by adding your first inventory location.</p>
                            <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#addLocationModal">
                                <i class="fas fa-plus mr-2"></i>Add First Location
                            </button>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Ends Card Body -->
        <?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/filter_footer.php'; ?>
    </div>
</div>

<!-- Add Location Modal -->
<div class="modal fade" id="addLocationModal">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Location</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Location Name <strong class="text-danger">*</strong></label>
                        <input type="text" class="form-control" name="location_name" placeholder="Enter location name" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Location Type <strong class="text-danger">*</strong></label>
                        <select class="form-control" name="location_type" required>
                            <option value="">- Select Type -</option>
                            <option value="Store">Store</option>
                            <option value="Pharmacy">Pharmacy</option>
                            <option value="Ward">Ward</option>
                            <option value="Theatre">Theatre</option>
                            <option value="Lab">Lab</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Description</label>
                        <textarea class="form-control" name="location_description" rows="3" placeholder="Location description..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_location" class="btn btn-primary">Add Location</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.select2').select2();

    // Auto-focus on first input in modals
    $('.modal').on('shown.bs.modal', function() {
        $(this).find('input[type="text"]').first().focus();
    });

    // Confirm before deleting
    $('.confirm-link').on('click', function(e) {
        if (!confirm('Are you sure you want to delete this location? This action cannot be undone.')) {
            e.preventDefault();
        }
    });

    // Handle sort selection
    $('select[name="sort"]').on('change', function() {
        var selected = $(this).find('option:selected');
        var sort = selected.val();
        var order = selected.data('order');
        
        // Update hidden fields
        $('input[name="sort"]').val(sort);
        $('input[name="order"]').val(order);
    });
});

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + N for new location
    if (e.ctrlKey && e.keyCode === 78) {
        e.preventDefault();
        $('#addLocationModal').modal('show');
    }
    // Ctrl + F for focus search
    if (e.ctrlKey && e.keyCode === 70) {
        e.preventDefault();
        $('input[name="q"]').focus();
    }
});
</script>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
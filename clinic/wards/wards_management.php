<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Get billable categories for reference
$billable_categories_sql = "SELECT category_id, category_name FROM billable_categories WHERE is_active = 1 ORDER BY category_name";
$billable_categories_result = $mysqli->query($billable_categories_sql);

// Handle form submissions via POST
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_ward'])) {
        $ward_name = sanitizeInput($_POST['ward_name']);
        $ward_type = sanitizeInput($_POST['ward_type']);
        $ward_description = sanitizeInput($_POST['ward_description']);
        
        $stmt = $mysqli->prepare("INSERT INTO wards SET ward_name=?, ward_type=?, ward_description=?, ward_is_active=1");
        $stmt->bind_param("sss", $ward_name, $ward_type, $ward_description);
        
        if ($stmt->execute()) {
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Ward added successfully!";
        } else {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error adding ward: " . $mysqli->error;
        }
        $stmt->close();
    }
    
    elseif (isset($_POST['update_ward'])) {
        $ward_id = intval($_POST['ward_id']);
        $ward_name = sanitizeInput($_POST['ward_name']);
        $ward_type = sanitizeInput($_POST['ward_type']);
        $ward_description = sanitizeInput($_POST['ward_description']);
        
        $stmt = $mysqli->prepare("UPDATE wards SET ward_name=?, ward_type=?, ward_description=?, ward_updated_at=NOW() WHERE ward_id=?");
        $stmt->bind_param("sssi", $ward_name, $ward_type, $ward_description, $ward_id);
        
        if ($stmt->execute()) {
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Ward updated successfully!";
        } else {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error updating ward: " . $mysqli->error;
        }
        $stmt->close();
    }
    
    elseif (isset($_POST['add_bed'])) {
        $bed_ward_id = intval($_POST['bed_ward_id']);
        $bed_number = sanitizeInput($_POST['bed_number']);
        $bed_type = sanitizeInput($_POST['bed_type']);
        $bed_rate = floatval($_POST['bed_rate']);
        $bed_notes = sanitizeInput($_POST['bed_notes']);
        
        // Start transaction
        $mysqli->begin_transaction();

        try {
            // Insert new bed
            $stmt = $mysqli->prepare("INSERT INTO beds SET bed_ward_id=?, bed_number=?, bed_type=?, bed_rate=?, bed_notes=?");
            $stmt->bind_param("issds", $bed_ward_id, $bed_number, $bed_type, $bed_rate, $bed_notes);
            
            if (!$stmt->execute()) {
                throw new Exception("Error adding bed: " . $mysqli->error);
            }
            
            $new_bed_id = $stmt->insert_id;
            $stmt->close();
            
            // Get ward details for billable item
            $ward_sql = "SELECT ward_name, ward_type FROM wards WHERE ward_id = ?";
            $ward_stmt = $mysqli->prepare($ward_sql);
            $ward_stmt->bind_param("i", $bed_ward_id);
            $ward_stmt->execute();
            $ward_result = $ward_stmt->get_result();
            $ward = $ward_result->fetch_assoc();
            $ward_stmt->close();
            
            // Get or create billable category for beds
            $billable_category_name = "Hospital Beds - " . $ward['ward_type'];
            $billable_category_sql = "SELECT category_id FROM billable_categories WHERE category_name = ?";
            $billable_category_stmt = $mysqli->prepare($billable_category_sql);
            $billable_category_stmt->bind_param("s", $billable_category_name);
            $billable_category_stmt->execute();
            $billable_category_result = $billable_category_stmt->get_result();
            
            $billable_category_id = null;
            if ($billable_category_result->num_rows > 0) {
                $billable_category_row = $billable_category_result->fetch_assoc();
                $billable_category_id = $billable_category_row['category_id'];
            } else {
                // Create new billable category for this bed type
                $create_category_sql = "INSERT INTO billable_categories SET 
                                       category_name = ?, 
                                       category_description = ?,
                                       is_active = 1,
                                       created_at = NOW()";
                $create_category_stmt = $mysqli->prepare($create_category_sql);
                $category_description = "Hospital beds of type: " . $ward['ward_type'] . " in ward: " . $ward['ward_name'];
                $create_category_stmt->bind_param("ss", $billable_category_name, $category_description);
                if (!$create_category_stmt->execute()) {
                    throw new Exception("Error creating billable category: " . $create_category_stmt->error);
                }
                $billable_category_id = $create_category_stmt->insert_id;
                $create_category_stmt->close();
            }
            $billable_category_stmt->close();
            
            // Generate bed code
            $bed_code = "BED_" . strtoupper(str_replace(' ', '_', $ward['ward_name'])) . "_" . $bed_number;
            
            // Create billable item for the bed
            $billable_item_sql = "INSERT INTO billable_items SET 
                                 item_type = 'bed',
                                 source_table = 'beds',
                                 source_id = ?,
                                 item_code = ?,
                                 item_name = ?,
                                 item_description = ?,
                                 unit_price = ?,
                                 cost_price = ?,
                                 tax_rate = 0.00,
                                 is_taxable = 0,
                                 category_id = ?,
                                 is_active = 1,
                                 created_by = ?,
                                 created_at = NOW()";
            
            // Set cost price (30% of daily rate as default for beds - covers maintenance/cleaning)
            $cost_price = $bed_rate * 0.3;
            $item_name = $ward['ward_name'] . " - Bed " . $bed_number . " (" . $bed_type . ")";
            $item_description = $bed_type . " bed in " . $ward['ward_name'] . " ward. Daily rate: $" . number_format($bed_rate, 2);
            if ($bed_notes) {
                $item_description .= " - Notes: " . $bed_notes;
            }
            
            $billable_stmt = $mysqli->prepare($billable_item_sql);
            $billable_stmt->bind_param(
                "isssddii",
                $new_bed_id,
                $bed_code,
                $item_name,
                $item_description,
                $bed_rate,
                $cost_price,
                $billable_category_id,
                $session_user_id
            );

            if (!$billable_stmt->execute()) {
                throw new Exception("Error creating billable item: " . $billable_stmt->error);
            }
            $billable_item_id = $billable_stmt->insert_id;
            $billable_stmt->close();

            // Commit transaction
            $mysqli->commit();

            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Bed added successfully and added to billable items (ID: $billable_item_id)!";

        } catch (Exception $e) {
            // Rollback transaction on error
            $mysqli->rollback();
            
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = $e->getMessage();
        }
    }
    
    elseif (isset($_POST['update_bed'])) {
        $bed_id = intval($_POST['bed_id']);
        $bed_number = sanitizeInput($_POST['bed_number']);
        $bed_type = sanitizeInput($_POST['bed_type']);
        $bed_rate = floatval($_POST['bed_rate']);
        $bed_status = sanitizeInput($_POST['bed_status']);
        $bed_notes = sanitizeInput($_POST['bed_notes']);
        
        // Start transaction
        $mysqli->begin_transaction();

        try {
            // Update bed
            $stmt = $mysqli->prepare("UPDATE beds SET bed_number=?, bed_type=?, bed_rate=?, bed_status=?, bed_notes=?, bed_updated_at=NOW() WHERE bed_id=?");
            $stmt->bind_param("ssdssi", $bed_number, $bed_type, $bed_rate, $bed_status, $bed_notes, $bed_id);
            
            if (!$stmt->execute()) {
                throw new Exception("Error updating bed: " . $stmt->error);
            }
            $stmt->close();
            
            // Get bed and ward details for billable item update
            $bed_details_sql = "SELECT b.*, w.ward_name, w.ward_type 
                                FROM beds b 
                                JOIN wards w ON b.bed_ward_id = w.ward_id 
                                WHERE b.bed_id = ?";
            $bed_details_stmt = $mysqli->prepare($bed_details_sql);
            $bed_details_stmt->bind_param("i", $bed_id);
            $bed_details_stmt->execute();
            $bed_details_result = $bed_details_stmt->get_result();
            $bed_details = $bed_details_result->fetch_assoc();
            $bed_details_stmt->close();
            
            // Generate bed code
            $bed_code = "BED_" . strtoupper(str_replace(' ', '_', $bed_details['ward_name'])) . "_" . $bed_number;
            
            // Update billable item
            $billable_item_sql = "UPDATE billable_items SET 
                                 item_code = ?,
                                 item_name = ?,
                                 item_description = ?,
                                 unit_price = ?,
                                 cost_price = ?,
                                 updated_at = NOW()
                                 WHERE source_table = 'beds' AND source_id = ?";
            
            $cost_price = $bed_rate * 0.3;
            $item_name = $bed_details['ward_name'] . " - Bed " . $bed_number . " (" . $bed_type . ")";
            $item_description = $bed_type . " bed in " . $bed_details['ward_name'] . " ward. Daily rate: $" . number_format($bed_rate, 2);
            if ($bed_notes) {
                $item_description .= " - Notes: " . $bed_notes;
            }
            
            $billable_stmt = $mysqli->prepare($billable_item_sql);
            $billable_stmt->bind_param(
                "sssddi",
                $bed_code,
                $item_name,
                $item_description,
                $bed_rate,
                $cost_price,
                $bed_id
            );

            if (!$billable_stmt->execute()) {
                // If billable item doesn't exist, create it
                // Get or create billable category
                $billable_category_name = "Hospital Beds - " . $bed_details['ward_type'];
                $billable_category_sql = "SELECT category_id FROM billable_categories WHERE category_name = ?";
                $billable_category_stmt = $mysqli->prepare($billable_category_sql);
                $billable_category_stmt->bind_param("s", $billable_category_name);
                $billable_category_stmt->execute();
                $billable_category_result = $billable_category_stmt->get_result();
                
                $billable_category_id = null;
                if ($billable_category_result->num_rows > 0) {
                    $billable_category_row = $billable_category_result->fetch_assoc();
                    $billable_category_id = $billable_category_row['category_id'];
                } else {
                    // Create new billable category
                    $create_category_sql = "INSERT INTO billable_categories SET 
                                           category_name = ?, 
                                           category_description = ?,
                                           is_active = 1,
                                           created_at = NOW()";
                    $create_category_stmt = $mysqli->prepare($create_category_sql);
                    $category_description = "Hospital beds of type: " . $bed_details['ward_type'] . " in ward: " . $bed_details['ward_name'];
                    $create_category_stmt->bind_param("ss", $billable_category_name, $category_description);
                    if (!$create_category_stmt->execute()) {
                        throw new Exception("Error creating billable category: " . $create_category_stmt->error);
                    }
                    $billable_category_id = $create_category_stmt->insert_id;
                    $create_category_stmt->close();
                }
                $billable_category_stmt->close();
                
                // Create new billable item
                $create_billable_sql = "INSERT INTO billable_items SET 
                                       item_type = 'bed',
                                       source_table = 'beds',
                                       source_id = ?,
                                       item_code = ?,
                                       item_name = ?,
                                       item_description = ?,
                                       unit_price = ?,
                                       cost_price = ?,
                                       tax_rate = 0.00,
                                       is_taxable = 0,
                                       category_id = ?,
                                       is_active = 1,
                                       created_by = ?,
                                       created_at = NOW()";
                
                $create_billable_stmt = $mysqli->prepare($create_billable_sql);
                $create_billable_stmt->bind_param(
                    "isssddii",
                    $bed_id,
                    $bed_code,
                    $item_name,
                    $item_description,
                    $bed_rate,
                    $cost_price,
                    $billable_category_id,
                    $session_user_id
                );
                
                if (!$create_billable_stmt->execute()) {
                    throw new Exception("Error creating billable item: " . $create_billable_stmt->error);
                }
                $create_billable_stmt->close();
            }
            $billable_stmt->close();

            // Commit transaction
            $mysqli->commit();

            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Bed updated successfully and billable item synchronized!";

        } catch (Exception $e) {
            // Rollback transaction on error
            $mysqli->rollback();
            
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = $e->getMessage();
        }
    }
    
    // Redirect to prevent form resubmission
    header("Location: wards_management.php");
    exit;
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ajax_request'])) {
    header('Content-Type: application/json');
    
    if (isset($_POST['get_ward_details'])) {
        $ward_id = intval($_POST['ward_id']);
        $ward = $mysqli->query("SELECT * FROM wards WHERE ward_id = $ward_id")->fetch_assoc();
        echo json_encode($ward);
        exit;
    }
    
    if (isset($_POST['get_bed_details'])) {
        $bed_id = intval($_POST['bed_id']);
        $bed = $mysqli->query("SELECT * FROM beds WHERE bed_id = $bed_id")->fetch_assoc();
        echo json_encode($bed);
        exit;
    }
    
    if (isset($_POST['delete_ward'])) {
        $ward_id = intval($_POST['ward_id']);
        $stmt = $mysqli->prepare("UPDATE wards SET ward_is_active = 0, ward_archived_at = NOW() WHERE ward_id = ?");
        $stmt->bind_param("i", $ward_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Ward archived successfully!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error archiving ward: ' . $mysqli->error]);
        }
        $stmt->close();
        exit;
    }
    
    if (isset($_POST['delete_bed'])) {
        $bed_id = intval($_POST['bed_id']);
        
        // Start transaction for bed deletion
        $mysqli->begin_transaction();

        try {
            // Archive the bed
            $stmt = $mysqli->prepare("UPDATE beds SET bed_archived_at = NOW() WHERE bed_id = ?");
            $stmt->bind_param("i", $bed_id);
            
            if (!$stmt->execute()) {
                throw new Exception("Error archiving bed: " . $stmt->error);
            }
            $stmt->close();
            
            // Also deactivate the billable item
            $billable_stmt = $mysqli->prepare("UPDATE billable_items SET is_active = 0, updated_at = NOW() WHERE source_table = 'beds' AND source_id = ?");
            $billable_stmt->bind_param("i", $bed_id);
            
            if (!$billable_stmt->execute()) {
                // If no billable item exists, that's okay - just continue
            }
            $billable_stmt->close();

            // Commit transaction
            $mysqli->commit();

            echo json_encode(['success' => true, 'message' => 'Bed archived successfully and billable item deactivated!']);

        } catch (Exception $e) {
            // Rollback transaction on error
            $mysqli->rollback();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if (isset($_POST['update_bed_status'])) {
        $bed_id = intval($_POST['bed_id']);
        $bed_status = sanitizeInput($_POST['bed_status']);
        
        $stmt = $mysqli->prepare("UPDATE beds SET bed_status=?, bed_updated_at=NOW() WHERE bed_id=?");
        $stmt->bind_param("si", $bed_status, $bed_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Bed status updated successfully!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error updating bed status: ' . $mysqli->error]);
        }
        $stmt->close();
        exit;
    }
}

// Default Column Sortby/Order Filter
$sort = "ward_name";
$order = "ASC";

// Filter parameters
$type_filter = $_GET['type'] ?? '';
$active_filter = $_GET['active'] ?? '';

// Search Query
$q = sanitizeInput($_GET['q'] ?? '');
if (!empty($q)) {
    $search_query = "AND (
        w.ward_name LIKE '%$q%' 
        OR w.ward_description LIKE '%$q%'
        OR w.ward_type LIKE '%$q%'
    )";
} else {
    $search_query = '';
}

// Build WHERE clause for filters
$where_conditions = ["1=1"];
$params = [];
$types = '';

if (!empty($type_filter)) {
    $where_conditions[] = "w.ward_type = ?";
    $params[] = $type_filter;
    $types .= 's';
}

if ($active_filter === 'inactive') {
    $where_conditions[] = "w.ward_is_active = 0";
} else {
    $where_conditions[] = "w.ward_is_active = 1";
}

$where_clause = implode(' AND ', $where_conditions);

// Get all wards
$wards_sql = "
    SELECT SQL_CALC_FOUND_ROWS w.*,
           (SELECT COUNT(*) FROM beds b WHERE b.bed_ward_id = w.ward_id AND b.bed_archived_at IS NULL) as total_beds,
           (SELECT COUNT(*) FROM beds b WHERE b.bed_ward_id = w.ward_id AND b.bed_status = 'occupied' AND b.bed_archived_at IS NULL) as occupied_beds,
           (SELECT COUNT(*) FROM beds b WHERE b.bed_ward_id = w.ward_id AND b.bed_status = 'available' AND b.bed_archived_at IS NULL) as available_beds
    FROM wards w 
    WHERE $where_clause
    $search_query
    ORDER BY $sort $order
    LIMIT $record_from, $record_to
";

$wards_stmt = $mysqli->prepare($wards_sql);
if (!empty($params)) {
    $wards_stmt->bind_param($types, ...$params);
}
$wards_stmt->execute();
$wards = $wards_stmt->get_result();

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get unique ward types for filter
$ward_types = $mysqli->query("SELECT DISTINCT ward_type FROM wards WHERE ward_type IS NOT NULL ORDER BY ward_type");

// Get statistics
$total_wards = $mysqli->query("SELECT COUNT(*) FROM wards WHERE ward_is_active = 1")->fetch_row()[0];
$inactive_wards = $mysqli->query("SELECT COUNT(*) FROM wards WHERE ward_is_active = 0")->fetch_row()[0];
$total_beds = $mysqli->query("SELECT COUNT(*) FROM beds WHERE bed_archived_at IS NULL")->fetch_row()[0];
$occupied_beds = $mysqli->query("SELECT COUNT(*) FROM beds WHERE bed_status = 'occupied' AND bed_archived_at IS NULL")->fetch_row()[0];
$available_beds = $mysqli->query("SELECT COUNT(*) FROM beds WHERE bed_status = 'available' AND bed_archived_at IS NULL")->fetch_row()[0];
$maintenance_beds = $mysqli->query("SELECT COUNT(*) FROM beds WHERE bed_status = 'maintenance' AND bed_archived_at IS NULL")->fetch_row()[0];

// Reset pointer for main query
$wards->data_seek(0);
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-procedures mr-2"></i>Ward & Bed Management
            </h3>
            <div class="card-tools">
                <button type="button" class="btn btn-light mr-2" data-toggle="modal" data-target="#addWardModal">
                    <i class="fas fa-plus mr-2"></i>New Ward
                </button>
                <button type="button" class="btn btn-light" data-toggle="modal" data-target="#addBedModal">
                    <i class="fas fa-bed mr-2"></i>New Bed
                </button>
            </div>
        </div>
    </div>

    <div class="card-header pb-2 pt-3">
        <form autocomplete="off">
            <div class="row">
                <div class="col-md-5">
                    <div class="form-group">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search wards, descriptions..." autofocus>
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
                                <i class="fas fa-hospital text-primary mr-1"></i>
                                Wards: <strong><?php echo $total_wards; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-bed text-success mr-1"></i>
                                Beds: <strong><?php echo $total_beds; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-user-injured text-warning mr-1"></i>
                                Occupied: <strong><?php echo $occupied_beds; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-tools text-danger mr-1"></i>
                                Maintenance: <strong><?php echo $maintenance_beds; ?></strong>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php if ($type_filter || isset($_GET['dtf'])) { echo "show"; } ?>" id="advancedFilter">
                <div class="row">
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Status</label>
                            <select class="form-control select2" name="active" onchange="this.form.submit()">
                                <option value="active" <?php if ($active_filter !== 'inactive') echo "selected"; ?>>Active Wards</option>
                                <option value="inactive" <?php if ($active_filter === 'inactive') echo "selected"; ?>>Inactive Wards</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Ward Type</label>
                            <select class="form-control select2" name="type" onchange="this.form.submit()">
                                <option value="">- All Types -</option>
                                <?php while($type = $ward_types->fetch_assoc()): ?>
                                    <option value="<?php echo htmlspecialchars($type['ward_type']); ?>" <?php if ($type_filter == $type['ward_type']) { echo "selected"; } ?>>
                                        <?php echo htmlspecialchars($type['ward_type']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
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

        <!-- Billable Items Info Alert -->
        <div class="alert alert-info">
            <i class="fas fa-info-circle mr-2"></i>
            Beds are automatically added to the billable items catalog for billing purposes. Daily rates from beds become billable items for hospitalization services.
        </div>

        <!-- Alert Container for AJAX Messages -->
        <div id="ajaxAlertContainer"></div>
    
        <div class="table-responsive-sm">
            <table class="table table-hover mb-0">
                <thead class="<?php if ($num_rows[0] == 0) { echo "d-none"; } ?> bg-light">
                <tr>
                    <th>
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=ward_name&order=<?php echo $disp; ?>">
                            Ward Name <?php if ($sort == 'ward_name') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=ward_type&order=<?php echo $disp; ?>">
                            Type <?php if ($sort == 'ward_type') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>Bed Status</th>
                    <th>Description</th>
                    <th class="text-center">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php while($ward = $wards->fetch_assoc()): 
                    $ward_id = intval($ward['ward_id']);
                    $ward_name = nullable_htmlentities($ward['ward_name']);
                    $ward_type = nullable_htmlentities($ward['ward_type']);
                    $ward_description = nullable_htmlentities($ward['ward_description']);
                    $total_beds = intval($ward['total_beds']);
                    $occupied_beds = intval($ward['occupied_beds']);
                    $available_beds = intval($ward['available_beds']);
                    $is_active = intval($ward['ward_is_active']);
                    ?>
                    <tr class="<?php echo $is_active ? '' : 'text-muted bg-light'; ?>">
                        <td class="font-weight-bold">
                            <a href="ward_details.php?ward_id=<?php echo $ward_id; ?>" class="text-dark">
                                <?php echo $ward_name; ?>
                            </a>
                        </td>
                        <td>
                            <span class="badge badge-<?php echo getWardTypeBadgeColor($ward_type); ?>"><?php echo $ward_type; ?></span>
                        </td>
                        <td>
                            <div class="d-flex align-items-center">
                                <div class="progress flex-grow-1 mr-2" style="height: 20px;">
                                    <?php if ($total_beds > 0): ?>
                                        <div class="progress-bar bg-success" style="width: <?php echo ($available_beds / $total_beds) * 100; ?>%" title="Available: <?php echo $available_beds; ?>"></div>
                                        <div class="progress-bar bg-warning" style="width: <?php echo ($occupied_beds / $total_beds) * 100; ?>%" title="Occupied: <?php echo $occupied_beds; ?>"></div>
                                    <?php endif; ?>
                                </div>
                                <small class="text-nowrap">
                                    <?php echo $available_beds; ?>/<?php echo $total_beds; ?> available
                                </small>
                            </div>
                        </td>
                        <td>
                            <?php if ($ward_description): ?>
                                <small class="text-muted"><?php echo strlen($ward_description) > 50 ? substr($ward_description, 0, 50) . '...' : $ward_description; ?></small>
                            <?php else: ?>
                                <small class="text-muted">No description</small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="dropdown dropleft text-center">
                                <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                    <i class="fas fa-ellipsis-h"></i>
                                </button>
                                <div class="dropdown-menu">
                                    <a class="dropdown-item" href="ward_details.php?ward_id=<?php echo $ward_id; ?>">
                                        <i class="fas fa-fw fa-eye mr-2"></i>View Ward & Beds
                                    </a>
                                    <a class="dropdown-item" href="#" onclick="editWard(<?php echo $ward_id; ?>)">
                                        <i class="fas fa-fw fa-edit mr-2"></i>Edit Ward
                                    </a>
                                    <?php if ($is_active): ?>
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item text-danger" href="#" onclick="deleteWard(<?php echo $ward_id; ?>)">
                                            <i class="fas fa-fw fa-archive mr-2"></i>Archive Ward
                                        </a>
                                    <?php else: ?>
                                        <a class="dropdown-item text-success" href="#" onclick="restoreWard(<?php echo $ward_id; ?>)">
                                            <i class="fas fa-fw fa-undo mr-2"></i>Restore Ward
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; ?>

                <?php if ($num_rows[0] === 0): ?>
                    <tr>
                        <td colspan="5" class="text-center py-4">
                            <i class="fas fa-hospital fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">No Wards Found</h5>
                            <p class="text-muted">
                                <?php echo ($type_filter || $search_query) ? 
                                    'Try adjusting your filters or search criteria.' : 
                                    'Get started by creating your first ward.'; 
                                ?>
                            </p>
                            <button type="button" class="btn btn-primary mt-2" data-toggle="modal" data-target="#addWardModal">
                                <i class="fas fa-plus mr-2"></i>Create First Ward
                            </button>
                            <?php if ($type_filter || $search_query): ?>
                                <a href="wards_management.php" class="btn btn-outline-secondary mt-2 ml-2">
                                    <i class="fas fa-times mr-2"></i>Clear Filters
                                </a>
                            <?php endif; ?>
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

<!-- Add Ward Modal -->
<div class="modal fade" id="addWardModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-hospital mr-2"></i>Add New Ward</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Ward Name *</label>
                        <input type="text" class="form-control" name="ward_name" required>
                    </div>
                    <div class="form-group">
                        <label>Ward Type *</label>
                        <select class="form-control" name="ward_type" required>
                            <option value="">Select Type</option>
                            <option value="General">General</option>
                            <option value="ICU">ICU</option>
                            <option value="CCU">CCU</option>
                            <option value="Pediatric">Pediatric</option>
                            <option value="Maternity">Maternity</option>
                            <option value="Surgical">Surgical</option>
                            <option value="Psychiatric">Psychiatric</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <textarea class="form-control" name="ward_description" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_ward" class="btn btn-primary">Add Ward</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Bed Modal -->
<div class="modal fade" id="addBedModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-bed mr-2"></i>Add New Bed</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="alert alert-info mb-3">
                        <i class="fas fa-info-circle mr-2"></i>
                        This bed will be automatically added to the billable items catalog for hospitalization billing.
                    </div>
                    
                    <div class="form-group">
                        <label>Ward *</label>
                        <select class="form-control" name="bed_ward_id" required>
                            <option value="">Select Ward</option>
                            <?php 
                            $active_wards = $mysqli->query("SELECT * FROM wards WHERE ward_is_active = 1 ORDER BY ward_name");
                            while($ward = $active_wards->fetch_assoc()): ?>
                                <option value="<?php echo $ward['ward_id']; ?>"><?php echo htmlspecialchars($ward['ward_name']); ?> (<?php echo $ward['ward_type']; ?>)</option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Bed Number *</label>
                        <input type="text" class="form-control" name="bed_number" required>
                        <small class="form-text text-muted">Billable item code will be generated as: BED_WARDNAME_BEDNUMBER</small>
                    </div>
                    <div class="form-group">
                        <label>Bed Type</label>
                        <select class="form-control" name="bed_type">
                            <option value="Regular">Regular</option>
                            <option value="ICU">ICU</option>
                            <option value="Private">Private</option>
                            <option value="Semi-Private">Semi-Private</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Daily Rate ($) *</label>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text">$</span>
                            </div>
                            <input type="number" class="form-control" name="bed_rate" step="0.01" min="0" value="0.00" required>
                        </div>
                        <small class="form-text text-muted">This will be the unit price in billable items</small>
                    </div>
                    <div class="form-group">
                        <label>Notes</label>
                        <textarea class="form-control" name="bed_notes" rows="2"></textarea>
                        <small class="form-text text-muted">Additional notes will be included in billable item description</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_bed" class="btn btn-success">Add Bed</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Ward Modal -->
<div class="modal fade" id="editWardModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-warning text-white">
                <h5 class="modal-title"><i class="fas fa-edit mr-2"></i>Edit Ward</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST">
                <input type="hidden" name="ward_id" id="edit_ward_id">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Ward Name *</label>
                        <input type="text" class="form-control" name="ward_name" id="edit_ward_name" required>
                    </div>
                    <div class="form-group">
                        <label>Ward Type *</label>
                        <select class="form-control" name="ward_type" id="edit_ward_type" required>
                            <option value="">Select Type</option>
                            <option value="General">General</option>
                            <option value="ICU">ICU</option>
                            <option value="CCU">CCU</option>
                            <option value="Pediatric">Pediatric</option>
                            <option value="Maternity">Maternity</option>
                            <option value="Surgical">Surgical</option>
                            <option value="Psychiatric">Psychiatric</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <textarea class="form-control" name="ward_description" id="edit_ward_description" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_ward" class="btn btn-warning">Update Ward</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
// Helper function for ward type badge colors
function getWardTypeBadgeColor($ward_type) {
    switch ($ward_type) {
        case 'ICU': return 'danger';
        case 'CCU': return 'danger';
        case 'General': return 'primary';
        case 'Pediatric': return 'info';
        case 'Maternity': return 'success';
        case 'Surgical': return 'warning';
        case 'Psychiatric': return 'secondary';
        default: return 'secondary';
    }
}
?>

<script>
$(document).ready(function() {
    $('.select2').select2();

    // Auto-close alerts after 5 seconds
    setTimeout(() => {
        $('.alert').alert('close');
    }, 5000);
});

function editWard(wardId) {
    $.ajax({
        url: 'wards_management.php',
        type: 'POST',
        data: {
            ajax_request: 1,
            get_ward_details: 1,
            ward_id: wardId
        },
        success: function(response) {
            const ward = JSON.parse(response);
            $('#edit_ward_id').val(ward.ward_id);
            $('#edit_ward_name').val(ward.ward_name);
            $('#edit_ward_type').val(ward.ward_type);
            $('#edit_ward_description').val(ward.ward_description);
            $('#editWardModal').modal('show');
        },
        error: function() {
            showAlert('Error loading ward details. Please try again.', 'error');
        }
    });
}

function deleteWard(wardId) {
    if (confirm('Are you sure you want to archive this ward? This will make it inactive but preserve its data.')) {
        $.ajax({
            url: 'wards_management.php',
            type: 'POST',
            data: {
                ajax_request: 1,
                delete_ward: 1,
                ward_id: wardId
            },
            success: function(response) {
                const result = JSON.parse(response);
                if (result.success) {
                    showAlert(result.message, 'success');
                    // Reload page after successful deletion
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    showAlert(result.message, 'error');
                }
            },
            error: function() {
                showAlert('Error archiving ward. Please try again.', 'error');
            }
        });
    }
}

function restoreWard(wardId) {
    if (confirm('Are you sure you want to restore this ward?')) {
        $.ajax({
            url: 'wards_management.php',
            type: 'POST',
            data: {
                ajax_request: 1,
                restore_ward: 1,
                ward_id: wardId
            },
            success: function(response) {
                const result = JSON.parse(response);
                if (result.success) {
                    showAlert(result.message, 'success');
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    showAlert(result.message, 'error');
                }
            },
            error: function() {
                showAlert('Error restoring ward. Please try again.', 'error');
            }
        });
    }
}

function showAlert(message, type) {
    const alertClass = type === 'success' ? 'alert-success' : 'alert-danger';
    const iconClass = type === 'success' ? 'fa-check' : 'fa-exclamation-triangle';
    
    const alertHtml = `
        <div class="alert ${alertClass} alert-dismissible">
            <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
            <i class="icon fas ${iconClass}"></i>
            ${message}
        </div>
    `;
    
    $('#ajaxAlertContainer').html(alertHtml);
    
    // Auto-dismiss success alerts after 5 seconds
    if (type === 'success') {
        setTimeout(() => {
            $('.alert').alert('close');
        }, 5000);
    }
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + W for new ward
    if (e.ctrlKey && e.keyCode === 87) {
        e.preventDefault();
        $('#addWardModal').modal('show');
    }
    // Ctrl + B for new bed
    if (e.ctrlKey && e.keyCode === 66) {
        e.preventDefault();
        $('#addBedModal').modal('show');
    }
    // Ctrl + F for focus search
    if (e.ctrlKey && e.keyCode === 70) {
        e.preventDefault();
        $('input[name="q"]').focus();
    }
});
</script>

<style>
.card {
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    margin-bottom: 1rem;
}
.progress {
    min-width: 100px;
}
</style>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
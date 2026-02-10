<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Check if user has permission to access this page
// You might want to add authentication/authorization here

$ward_id = intval($_GET['ward_id'] ?? 0);

if (!$ward_id) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Ward ID is required!";
    header("Location: ../wards_management.php");
    exit;
}

// Handle POST requests for bed operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add new bed
    if (isset($_POST['add_bed'])) {
        $bed_number = trim($_POST['bed_number']);
        $bed_type = $_POST['bed_type'];
        $bed_rate = floatval($_POST['bed_rate']);
        $bed_notes = trim($_POST['bed_notes'] ?? '');
        $bed_ward_id = intval($_POST['bed_ward_id']);
        
        // Check if bed number already exists in this ward
        $check_sql = "SELECT bed_id FROM beds WHERE bed_ward_id = ? AND bed_number = ?";
        $check_stmt = $mysqli->prepare($check_sql);
        $check_stmt->bind_param("is", $bed_ward_id, $bed_number);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "A bed with this number already exists in this ward!";
        } else {
            // Start transaction
            $mysqli->begin_transaction();
            
            try {
                // Insert bed
                $sql = "INSERT INTO beds (bed_number, bed_type, bed_rate, bed_notes, bed_ward_id, bed_status, bed_created_at) 
                        VALUES (?, ?, ?, ?, ?, 'available', NOW())";
                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param("ssdsi", $bed_number, $bed_type, $bed_rate, $bed_notes, $bed_ward_id);
                
                if (!$stmt->execute()) {
                    throw new Exception("Error adding bed: " . $mysqli->error);
                }
                
                $new_bed_id = $mysqli->insert_id;
                $stmt->close();
                
                // Check if bed type category exists in billable_categories
                $category_sql = "SELECT category_id FROM billable_categories WHERE category_name = ?";
                $category_stmt = $mysqli->prepare($category_sql);
                $category_name = $bed_type . " Beds";
                $category_stmt->bind_param("s", $category_name);
                $category_stmt->execute();
                $category_result = $category_stmt->get_result();
                
                $category_id = null;
                if ($category_result->num_rows > 0) {
                    $category_row = $category_result->fetch_assoc();
                    $category_id = $category_row['category_id'];
                } else {
                    // Create new category if not exists
                    $insert_category_sql = "INSERT INTO billable_categories (category_name, category_description, is_active, created_at) 
                                            VALUES (?, ?, 1, NOW())";
                    $insert_category_stmt = $mysqli->prepare($insert_category_sql);
                    $category_description = $bed_type . " beds for patient accommodation";
                    $insert_category_stmt->bind_param("ss", $category_name, $category_description);
                    
                    if (!$insert_category_stmt->execute()) {
                        throw new Exception("Error creating category: " . $mysqli->error);
                    }
                    
                    $category_id = $mysqli->insert_id;
                    $insert_category_stmt->close();
                }
                $category_stmt->close();
                
                // Create billable item for the bed
                $billable_sql = "INSERT INTO billable_items 
                                (item_type, source_table, source_id, item_code, item_name, item_description, 
                                 unit_price, cost_price, tax_rate, is_taxable, category_id, is_active, created_at) 
                                VALUES ('bed', 'beds', ?, ?, ?, ?, ?, ?, 0.00, 1, ?, 1, NOW())";
                $billable_stmt = $mysqli->prepare($billable_sql);
                
                $item_code = "BED-" . str_pad($new_bed_id, 5, '0', STR_PAD_LEFT);
                $item_name = "Bed " . $bed_number . " - " . $bed_type;
                $item_description = $bed_type . " bed in " . htmlspecialchars($ward['ward_name']) . ". " . $bed_notes;
                $unit_price = $bed_rate;
                $cost_price = $bed_rate * 0.3; // Assuming 30% cost price
                
                $billable_stmt->bind_param("isssddi", 
                    $new_bed_id, 
                    $item_code, 
                    $item_name, 
                    $item_description, 
                    $unit_price, 
                    $cost_price,
                    $category_id
                );
                
                if (!$billable_stmt->execute()) {
                    throw new Exception("Error creating billable item: " . $mysqli->error);
                }
                
                $billable_stmt->close();
                
                // Commit transaction
                $mysqli->commit();
                
                $_SESSION['alert_type'] = "success";
                $_SESSION['alert_message'] = "Bed added successfully and added to billable items!";
                
            } catch (Exception $e) {
                // Rollback transaction on error
                $mysqli->rollback();
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = $e->getMessage();
            }
        }
        $check_stmt->close();
        header("Location: ward_beds.php?ward_id=$ward_id");
        exit;
    }
    
    // Update bed
    if (isset($_POST['update_bed'])) {
        $bed_id = intval($_POST['bed_id']);
        $bed_number = trim($_POST['bed_number']);
        $bed_type = $_POST['bed_type'];
        $bed_rate = floatval($_POST['bed_rate']);
        $bed_status = $_POST['bed_status'];
        $bed_notes = trim($_POST['bed_notes'] ?? '');
        
        // Check if bed exists in billable_items before allowing status change to available
        if ($bed_status === 'available') {
            $check_billable_sql = "SELECT billable_item_id FROM billable_items 
                                   WHERE item_type = 'bed' 
                                   AND source_id = ? 
                                   AND is_active = 1";
            $check_billable_stmt = $mysqli->prepare($check_billable_sql);
            $check_billable_stmt->bind_param("i", $bed_id);
            $check_billable_stmt->execute();
            $billable_result = $check_billable_stmt->get_result();
            
            if ($billable_result->num_rows > 0) {
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = "Cannot set bed to 'Available' because it is currently in use in billable items!";
                header("Location: ward_beds.php?ward_id=$ward_id");
                exit;
            }
            $check_billable_stmt->close();
        }
        
        // Get current ward_id for this bed
        $get_ward_sql = "SELECT bed_ward_id FROM beds WHERE bed_id = ?";
        $get_ward_stmt = $mysqli->prepare($get_ward_sql);
        $get_ward_stmt->bind_param("i", $bed_id);
        $get_ward_stmt->execute();
        $ward_result = $get_ward_stmt->get_result();
        $ward_data = $ward_result->fetch_assoc();
        $current_ward_id = $ward_data['bed_ward_id'] ?? 0;
        $get_ward_stmt->close();
        
        // Check if bed number already exists in this ward (excluding current bed)
        $check_sql = "SELECT bed_id FROM beds WHERE bed_ward_id = ? AND bed_number = ? AND bed_id != ?";
        $check_stmt = $mysqli->prepare($check_sql);
        $check_stmt->bind_param("isi", $current_ward_id, $bed_number, $bed_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "A bed with this number already exists in this ward!";
        } else {
            // Start transaction for updating bed and billable item
            $mysqli->begin_transaction();
            
            try {
                // Update bed
                $sql = "UPDATE beds SET bed_number = ?, bed_type = ?, bed_rate = ?, bed_status = ?, 
                        bed_notes = ?, bed_updated_at = NOW() WHERE bed_id = ?";
                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param("ssdssi", $bed_number, $bed_type, $bed_rate, $bed_status, $bed_notes, $bed_id);
                
                if (!$stmt->execute()) {
                    throw new Exception("Error updating bed: " . $mysqli->error);
                }
                $stmt->close();
                
                // Update corresponding billable item if exists
                $update_billable_sql = "UPDATE billable_items 
                                       SET item_name = ?, 
                                           item_description = ?,
                                           unit_price = ?,
                                           cost_price = ?,
                                           updated_at = NOW()
                                       WHERE item_type = 'bed' 
                                       AND source_id = ? 
                                       AND is_active = 1";
                $update_billable_stmt = $mysqli->prepare($update_billable_sql);
                
                $item_name = "Bed " . $bed_number . " - " . $bed_type;
                $item_description = $bed_type . " bed in " . htmlspecialchars($ward['ward_name']) . ". " . $bed_notes;
                $cost_price = $bed_rate * 0.3;
                
                $update_billable_stmt->bind_param("ssddi", 
                    $item_name, 
                    $item_description, 
                    $bed_rate, 
                    $cost_price,
                    $bed_id
                );
                
                $update_billable_stmt->execute();
                $update_billable_stmt->close();
                
                // Commit transaction
                $mysqli->commit();
                
                $_SESSION['alert_type'] = "success";
                $_SESSION['alert_message'] = "Bed updated successfully!";
                
            } catch (Exception $e) {
                // Rollback transaction on error
                $mysqli->rollback();
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = $e->getMessage();
            }
        }
        $check_stmt->close();
        header("Location: ward_beds.php?ward_id=$ward_id");
        exit;
    }
    
    // Set bed status (available/maintenance)
    if (isset($_POST['set_bed_status'])) {
        $bed_id = intval($_POST['bed_id']);
        $new_status = $_POST['bed_status'];
        
        // Check if bed exists in billable_items before allowing status change to available
        if ($new_status === 'available') {
            $check_billable_sql = "SELECT billable_item_id FROM billable_items 
                                   WHERE item_type = 'bed' 
                                   AND source_id = ? 
                                   AND is_active = 1";
            $check_billable_stmt = $mysqli->prepare($check_billable_sql);
            $check_billable_stmt->bind_param("i", $bed_id);
            $check_billable_stmt->execute();
            $billable_result = $check_billable_stmt->get_result();
            
            if ($billable_result->num_rows > 0) {
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = "Cannot set bed to 'Available' because it is currently in use in billable items!";
                header("Location: ward_beds.php?ward_id=$ward_id");
                exit;
            }
            $check_billable_stmt->close();
        }
        
        $sql = "UPDATE beds SET bed_status = ?, bed_updated_at = NOW() WHERE bed_id = ?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("si", $new_status, $bed_id);
        
        if ($stmt->execute()) {
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Bed status updated to '" . ucfirst($new_status) . "' successfully!";
        } else {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error updating bed status: " . $mysqli->error;
        }
        $stmt->close();
        header("Location: ward_beds.php?ward_id=$ward_id");
        exit;
    }
    
    // Delete bed
    if (isset($_POST['delete_bed'])) {
        $bed_id = intval($_POST['bed_id']);
        
        // Check if bed exists in billable_items before deletion
        $check_billable_sql = "SELECT billable_item_id FROM billable_items 
                               WHERE item_type = 'bed' 
                               AND source_id = ?";
        $check_billable_stmt = $mysqli->prepare($check_billable_sql);
        $check_billable_stmt->bind_param("i", $bed_id);
        $check_billable_stmt->execute();
        $billable_result = $check_billable_stmt->get_result();
        
        if ($billable_result->num_rows > 0) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Cannot delete bed because it is referenced in billable items!";
            header("Location: ward_beds.php?ward_id=$ward_id");
            exit;
        }
        
        // Check if bed is currently occupied
        $check_occupied_sql = "SELECT bed_id FROM beds WHERE bed_id = ? AND bed_status = 'occupied'";
        $check_occupied_stmt = $mysqli->prepare($check_occupied_sql);
        $check_occupied_stmt->bind_param("i", $bed_id);
        $check_occupied_stmt->execute();
        $occupied_result = $check_occupied_stmt->get_result();
        
        if ($occupied_result->num_rows > 0) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Cannot delete bed because it is currently occupied!";
            header("Location: ward_beds.php?ward_id=$ward_id");
            exit;
        }
        
        $sql = "DELETE FROM beds WHERE bed_id = ?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("i", $bed_id);
        
        if ($stmt->execute()) {
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Bed deleted successfully!";
        } else {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error deleting bed: " . $mysqli->error;
        }
        $stmt->close();
        header("Location: ward_beds.php?ward_id=$ward_id");
        exit;
    }
    
    // Add missing beds to billable items
    if (isset($_POST['add_missing_to_billable'])) {
        $ward_id_post = intval($_POST['ward_id']);
        
        // Get beds not in billable_items
        $missing_beds_sql = "SELECT b.*, w.ward_name 
                            FROM beds b 
                            JOIN wards w ON b.bed_ward_id = w.ward_id 
                            WHERE b.bed_ward_id = ? 
                            AND b.bed_id NOT IN (
                                SELECT source_id FROM billable_items 
                                WHERE item_type = 'bed' 
                                AND source_table = 'beds'
                            )";
        $missing_beds_stmt = $mysqli->prepare($missing_beds_sql);
        $missing_beds_stmt->bind_param("i", $ward_id_post);
        $missing_beds_stmt->execute();
        $missing_beds_result = $missing_beds_stmt->get_result();
        
        $added_count = 0;
        $errors = [];
        
        while ($bed = $missing_beds_result->fetch_assoc()) {
            try {
                // Check if bed type category exists
                $category_sql = "SELECT category_id FROM billable_categories WHERE category_name = ?";
                $category_stmt = $mysqli->prepare($category_sql);
                $category_name = $bed['bed_type'] . " Beds";
                $category_stmt->bind_param("s", $category_name);
                $category_stmt->execute();
                $category_result = $category_stmt->get_result();
                
                $category_id = null;
                if ($category_result->num_rows > 0) {
                    $category_row = $category_result->fetch_assoc();
                    $category_id = $category_row['category_id'];
                } else {
                    // Create new category
                    $insert_category_sql = "INSERT INTO billable_categories (category_name, category_description, is_active, created_at) 
                                            VALUES (?, ?, 1, NOW())";
                    $insert_category_stmt = $mysqli->prepare($insert_category_sql);
                    $category_description = $bed['bed_type'] . " beds for patient accommodation";
                    $insert_category_stmt->bind_param("ss", $category_name, $category_description);
                    
                    if (!$insert_category_stmt->execute()) {
                        throw new Exception("Error creating category for bed " . $bed['bed_number']);
                    }
                    
                    $category_id = $mysqli->insert_id;
                    $insert_category_stmt->close();
                }
                $category_stmt->close();
                
                // Create billable item
                $billable_sql = "INSERT INTO billable_items 
                                (item_type, source_table, source_id, item_code, item_name, item_description, 
                                 unit_price, cost_price, tax_rate, is_taxable, category_id, is_active, created_at) 
                                VALUES ('bed', 'beds', ?, ?, ?, ?, ?, ?, 0.00, 1, ?, 1, NOW())";
                $billable_stmt = $mysqli->prepare($billable_sql);
                
                $item_code = "BED-" . str_pad($bed['bed_id'], 5, '0', STR_PAD_LEFT);
                $item_name = "Bed " . $bed['bed_number'] . " - " . $bed['bed_type'];
                $item_description = $bed['bed_type'] . " bed in " . $bed['ward_name'] . ". " . ($bed['bed_notes'] ?? '');
                $unit_price = $bed['bed_rate'];
                $cost_price = $bed['bed_rate'] * 0.3;
                
                $billable_stmt->bind_param("isssddi", 
                    $bed['bed_id'], 
                    $item_code, 
                    $item_name, 
                    $item_description, 
                    $unit_price, 
                    $cost_price,
                    $category_id
                );
                
                if ($billable_stmt->execute()) {
                    $added_count++;
                } else {
                    $errors[] = "Bed " . $bed['bed_number'] . ": " . $mysqli->error;
                }
                $billable_stmt->close();
                
            } catch (Exception $e) {
                $errors[] = "Bed " . $bed['bed_number'] . ": " . $e->getMessage();
            }
        }
        $missing_beds_stmt->close();
        
        if ($added_count > 0) {
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Successfully added " . $added_count . " beds to billable items.";
            if (!empty($errors)) {
                $_SESSION['alert_message'] .= " Some errors: " . implode(", ", $errors);
            }
        } elseif (!empty($errors)) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Errors: " . implode(", ", $errors);
        } else {
            $_SESSION['alert_type'] = "info";
            $_SESSION['alert_message'] = "All beds are already in billable items.";
        }
        
        header("Location: ward_beds.php?ward_id=$ward_id");
        exit;
    }
}

// Check if we need to show edit modal
$show_edit_modal = false;
$edit_bed_data = null;
if (isset($_GET['edit_bed_id'])) {
    $edit_bed_id = intval($_GET['edit_bed_id']);
    $sql = "SELECT * FROM beds WHERE bed_id = ?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("i", $edit_bed_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $edit_bed_data = $result->fetch_assoc();
    $stmt->close();
    
    if ($edit_bed_data) {
        $show_edit_modal = true;
    }
}

// Get ward details
$ward_sql = "SELECT * FROM wards WHERE ward_id = ?";
$ward_stmt = $mysqli->prepare($ward_sql);

if (!$ward_stmt) {
    error_log("Ward prepare failed: " . $mysqli->error);
    die("Database error: " . $mysqli->error);
}

$ward_stmt->bind_param("i", $ward_id);
$ward_stmt->execute();
$ward_result = $ward_stmt->get_result();
$ward = $ward_result->fetch_assoc();
$ward_stmt->close();

if (!$ward) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Ward not found! ID: " . $ward_id;
    header("Location: ../wards_management.php");
    exit;
}

// Get beds for this ward with billable items info
$beds_sql = "
    SELECT b.*, 
           p.patient_first_name, 
           p.patient_last_name,
           p.patient_id,
           (SELECT COUNT(*) FROM billable_items 
            WHERE item_type = 'bed' 
            AND source_id = b.bed_id
            AND source_table = 'beds'
            AND is_active = 1) as in_billable_items,
           bi.item_code as billable_code,
           bi.item_name as billable_name
    FROM beds b 
    LEFT JOIN patients p ON b.bed_assigned_to = p.patient_id 
    LEFT JOIN billable_items bi ON bi.source_id = b.bed_id 
        AND bi.item_type = 'bed' 
        AND bi.source_table = 'beds'
        AND bi.is_active = 1
    WHERE b.bed_ward_id = ? 
    ORDER BY b.bed_number
";

$beds_stmt = $mysqli->prepare($beds_sql);
if (!$beds_stmt) {
    error_log("Beds prepare failed: " . $mysqli->error);
    die("Database error: " . $mysqli->error);
}

$beds_stmt->bind_param("i", $ward_id);
$beds_stmt->execute();
$beds = $beds_stmt->get_result();
$beds_count = $beds->num_rows;

// Get count of beds not in billable items
$missing_beds_sql = "SELECT COUNT(*) as count 
                     FROM beds b 
                     WHERE b.bed_ward_id = ? 
                     AND b.bed_id NOT IN (
                         SELECT source_id FROM billable_items 
                         WHERE item_type = 'bed' 
                         AND source_table = 'beds'
                     )";
$missing_beds_stmt = $mysqli->prepare($missing_beds_sql);
$missing_beds_stmt->bind_param("i", $ward_id);
$missing_beds_stmt->execute();
$missing_beds_result = $missing_beds_stmt->get_result();
$missing_beds_row = $missing_beds_result->fetch_assoc();
$missing_beds_count = $missing_beds_row['count'] ?? 0;
$missing_beds_stmt->close();

// Get bed statistics
function getBedCount($mysqli, $ward_id, $status = null) {
    $sql = "SELECT COUNT(*) as count FROM beds WHERE bed_ward_id = ?";
    if ($status) {
        $sql .= " AND bed_status = ?";
    }
    
    $stmt = $mysqli->prepare($sql);
    if ($status) {
        $stmt->bind_param("is", $ward_id, $status);
    } else {
        $stmt->bind_param("i", $ward_id);
    }
    
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    return $result['count'] ?? 0;
}

$total_beds = getBedCount($mysqli, $ward_id);
$occupied_beds = getBedCount($mysqli, $ward_id, 'occupied');
$available_beds = getBedCount($mysqli, $ward_id, 'available');
$maintenance_beds = getBedCount($mysqli, $ward_id, 'maintenance');
$reserved_beds = getBedCount($mysqli, $ward_id, 'reserved');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($ward['ward_name'] ?? 'Ward'); ?> - Bed Management</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <style>
        .bed-card {
            transition: transform 0.2s;
            height: 100%;
            border: 2px solid;
        }
        .bed-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .card-header {
            border-bottom: none;
        }
        .dropdown-item {
            cursor: pointer;
        }
        .modal-backdrop {
            opacity: 0.5;
        }
        .progress-bar {
            font-size: 12px;
            line-height: 25px;
        }
        .billable-info {
            font-size: 0.8rem;
            margin-top: 5px;
        }
    </style>
</head>
<body>
    <div class="container-fluid mt-3">
        <div class="card">
            <div class="card-header bg-primary py-2">
                <div class="d-flex justify-content-between align-items-center">
                    <h3 class="card-title mt-2 mb-0 text-white">
                        <i class="fas fa-fw fa-hospital mr-2"></i>
                        <?php echo htmlspecialchars($ward['ward_name'] ?? 'Unknown Ward'); ?> - Bed Management
                    </h3>
                    <div class="card-tools">
                        <a href="../wards_management.php" class="btn btn-light">
                            <i class="fas fa-arrow-left mr-2"></i>Back to Wards
                        </a>
                    </div>
                </div>
            </div>

            <div class="card-body">
                <!-- Display alerts -->
                <?php if (isset($_SESSION['alert_message'])): ?>
                <div class="alert alert-<?php echo $_SESSION['alert_type']; ?> alert-dismissible fade show" role="alert">
                    <?php echo $_SESSION['alert_message']; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php 
                unset($_SESSION['alert_type']);
                unset($_SESSION['alert_message']);
                endif; ?>

                <!-- Billable Items Action -->
                <?php if ($missing_beds_count > 0): ?>
                <div class="alert alert-warning alert-dismissible fade show mb-4" role="alert">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <i class="fas fa-exclamation-triangle mr-2"></i>
                            <strong><?php echo $missing_beds_count; ?> bed(s)</strong> are not in billable items.
                        </div>
                        <form method="POST" class="mb-0" onsubmit="return confirm('Add all <?php echo $missing_beds_count; ?> missing beds to billable items?');">
                            <input type="hidden" name="ward_id" value="<?php echo $ward_id; ?>">
                            <button type="submit" name="add_missing_to_billable" class="btn btn-warning btn-sm">
                                <i class="fas fa-plus-circle mr-1"></i>Add All to Billable Items
                            </button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Ward Information -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-light">
                                <h5 class="card-title mb-0">Ward Information</h5>
                            </div>
                            <div class="card-body">
                                <table class="table table-sm">
                                    <tr>
                                        <th width="30%">Ward Name:</th>
                                        <td><?php echo htmlspecialchars($ward['ward_name'] ?? 'N/A'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Ward Type:</th>
                                        <td>
                                            <?php if (isset($ward['ward_type'])): ?>
                                                <span class="badge bg-<?php echo getWardTypeBadgeColor($ward['ward_type']); ?>">
                                                    <?php echo htmlspecialchars($ward['ward_type']); ?>
                                                </span>
                                            <?php else: ?>
                                                <em class="text-muted">Not specified</em>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Description:</th>
                                        <td>
                                            <?php if (!empty($ward['ward_description'])): ?>
                                                <?php echo htmlspecialchars($ward['ward_description']); ?>
                                            <?php else: ?>
                                                <em class="text-muted">No description provided</em>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Status:</th>
                                        <td>
                                            <?php if ($ward['ward_is_active']): ?>
                                                <span class="badge bg-success">Active</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Billable Items:</th>
                                        <td>
                                            <span class="badge bg-<?php echo ($missing_beds_count == 0) ? 'success' : 'warning'; ?>">
                                                <?php echo ($total_beds - $missing_beds_count) . '/' . $total_beds; ?> beds in billable items
                                            </span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Created:</th>
                                        <td><?php echo !empty($ward['ward_created_at']) ? date('M j, Y g:i A', strtotime($ward['ward_created_at'])) : 'N/A'; ?></td>
                                    </tr>
                                    <?php if (!empty($ward['ward_updated_at'])): ?>
                                    <tr>
                                        <th>Last Updated:</th>
                                        <td><?php echo date('M j, Y g:i A', strtotime($ward['ward_updated_at'])); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-light">
                                <h5 class="card-title mb-0">Bed Statistics</h5>
                            </div>
                            <div class="card-body">
                                <div class="row text-center">
                                    <div class="col-3">
                                        <div class="border rounded p-2">
                                            <div class="h4 mb-0 text-primary"><?php echo $total_beds; ?></div>
                                            <small class="text-muted">Total Beds</small>
                                        </div>
                                    </div>
                                    <div class="col-3">
                                        <div class="border rounded p-2">
                                            <div class="h4 mb-0 text-success"><?php echo $available_beds; ?></div>
                                            <small class="text-muted">Available</small>
                                        </div>
                                    </div>
                                    <div class="col-3">
                                        <div class="border rounded p-2">
                                            <div class="h4 mb-0 text-warning"><?php echo $occupied_beds; ?></div>
                                            <small class="text-muted">Occupied</small>
                                        </div>
                                    </div>
                                    <div class="col-3">
                                        <div class="border rounded p-2">
                                            <div class="h4 mb-0 text-danger"><?php echo $maintenance_beds; ?></div>
                                            <small class="text-muted">Maintenance</small>
                                        </div>
                                    </div>
                                </div>
                                <?php if ($total_beds > 0): ?>
                                <div class="mt-3">
                                    <div class="progress" style="height: 25px;">
                                        <div class="progress-bar bg-success" style="width: <?php echo ($available_beds / $total_beds) * 100; ?>%">
                                            <?php echo round(($available_beds / $total_beds) * 100); ?>%
                                        </div>
                                        <div class="progress-bar bg-warning" style="width: <?php echo ($occupied_beds / $total_beds) * 100; ?>%">
                                            <?php echo round(($occupied_beds / $total_beds) * 100); ?>%
                                        </div>
                                        <div class="progress-bar bg-danger" style="width: <?php echo ($maintenance_beds / $total_beds) * 100; ?>%">
                                            <?php echo round(($maintenance_beds / $total_beds) * 100); ?>%
                                        </div>
                                        <?php if ($reserved_beds > 0): ?>
                                        <div class="progress-bar bg-info" style="width: <?php echo ($reserved_beds / $total_beds) * 100; ?>%">
                                            <?php echo round(($reserved_beds / $total_beds) * 100); ?>%
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="mt-2 text-center">
                                        <small class="text-muted">
                                            Availability: <?php echo round(($available_beds / $total_beds) * 100); ?>% |
                                            Occupancy: <?php echo round(($occupied_beds / $total_beds) * 100); ?>% |
                                            Billable: <?php echo round((($total_beds - $missing_beds_count) / $total_beds) * 100); ?>%
                                        </small>
                                    </div>
                                </div>
                                <?php else: ?>
                                <div class="text-center mt-3">
                                    <p class="text-muted">No beds configured for this ward</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Beds Grid -->
                <div class="card">
                    <div class="card-header bg-light d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">
                            Beds in <?php echo htmlspecialchars($ward['ward_name']); ?>
                            <span class="badge bg-primary ml-2"><?php echo $total_beds; ?> beds</span>
                            <?php if ($missing_beds_count > 0): ?>
                            <span class="badge bg-warning ml-2"><?php echo $missing_beds_count; ?> not in billable</span>
                            <?php endif; ?>
                        </h5>
                        <div>
                            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addBedModal">
                                <i class="fas fa-bed mr-1"></i>Add Bed
                            </button>
                            <?php if ($missing_beds_count > 0): ?>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Add all <?php echo $missing_beds_count; ?> missing beds to billable items?');">
                                <input type="hidden" name="ward_id" value="<?php echo $ward_id; ?>">
                                <button type="submit" name="add_missing_to_billable" class="btn btn-warning btn-sm ms-1">
                                    <i class="fas fa-plus-circle mr-1"></i>Add Missing to Billable
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if ($beds_count > 0): ?>
                        <div class="row">
                            <?php while($bed = $beds->fetch_assoc()): 
                                $bed_status = $bed['bed_status'] ?? 'available';
                                $status_class = getBedStatusClass($bed_status);
                                $patient_name = 'Unoccupied';
                                $in_billable_items = $bed['in_billable_items'] > 0;
                                $billable_code = $bed['billable_code'] ?? null;
                                $billable_name = $bed['billable_name'] ?? null;
                                
                                if (!empty($bed['patient_first_name']) && !empty($bed['patient_last_name'])) {
                                    $patient_name = htmlspecialchars($bed['patient_first_name'] . ' ' . $bed['patient_last_name']);
                                } elseif (!empty($bed['bed_assigned_to'])) {
                                    $patient_name = 'Patient #' . $bed['bed_assigned_to'];
                                }
                            ?>
                            <div class="col-xl-3 col-lg-4 col-md-6 mb-3">
                                <div class="card bed-card border-<?php echo $status_class; ?>">
                                    <div class="card-header py-2 bg-<?php echo $status_class; ?> text-white d-flex justify-content-between align-items-center">
                                        <strong>Bed <?php echo htmlspecialchars($bed['bed_number'] ?? 'N/A'); ?></strong>
                                        <?php if ($in_billable_items): ?>
                                            <span class="badge bg-light text-dark" title="This bed is in billable items">
                                                <i class="fas fa-dollar-sign"></i>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-danger" title="Not in billable items">
                                                <i class="fas fa-exclamation-circle"></i>
                                            </span>
                                        <?php endif; ?>
                                        <div class="dropdown">
                                            <button class="btn btn-sm p-0 text-white" type="button" data-bs-toggle="dropdown">
                                                <i class="fas fa-ellipsis-v"></i>
                                            </button>
                                            <ul class="dropdown-menu">
                                                <li><a class="dropdown-item" href="ward_beds.php?ward_id=<?php echo $ward_id; ?>&edit_bed_id=<?php echo $bed['bed_id']; ?>">
                                                    <i class="fas fa-edit mr-2"></i>Edit
                                                </a></li>
                                                <li>
                                                    <form method="POST" class="d-inline" onsubmit="return confirm('Set this bed to Available?');">
                                                        <input type="hidden" name="bed_id" value="<?php echo $bed['bed_id']; ?>">
                                                        <input type="hidden" name="bed_status" value="available">
                                                        <button type="submit" name="set_bed_status" class="dropdown-item">
                                                            <i class="fas fa-check mr-2"></i>Set Available
                                                        </button>
                                                    </form>
                                                </li>
                                                <li>
                                                    <form method="POST" class="d-inline" onsubmit="return confirm('Set this bed to Maintenance?');">
                                                        <input type="hidden" name="bed_id" value="<?php echo $bed['bed_id']; ?>">
                                                        <input type="hidden" name="bed_status" value="maintenance">
                                                        <button type="submit" name="set_bed_status" class="dropdown-item">
                                                            <i class="fas fa-tools mr-2"></i>Set Maintenance
                                                        </button>
                                                    </form>
                                                </li>
                                                <li><hr class="dropdown-divider"></li>
                                                <li>
                                                    <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this bed? This action cannot be undone.');">
                                                        <input type="hidden" name="bed_id" value="<?php echo $bed['bed_id']; ?>">
                                                        <button type="submit" name="delete_bed" class="dropdown-item text-danger">
                                                            <i class="fas fa-trash mr-2"></i>Delete
                                                        </button>
                                                    </form>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                    <div class="card-body p-3">
                                        <div class="text-center mb-2">
                                            <i class="fas fa-bed fa-2x text-<?php echo $status_class; ?>"></i>
                                        </div>
                                        <div class="text-center">
                                            <span class="badge bg-<?php echo $status_class; ?> mb-2">
                                                <?php echo ucfirst($bed_status); ?>
                                            </span>
                                            <?php if ($in_billable_items): ?>
                                                <span class="badge bg-info mb-2" title="This bed is in billable items">
                                                    <i class="fas fa-dollar-sign mr-1"></i>In Billable
                                                </span>
                                                <?php if ($billable_code): ?>
                                                <div class="billable-info text-success">
                                                    <small><i class="fas fa-hashtag"></i> <?php echo $billable_code; ?></small>
                                                </div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="badge bg-danger mb-2" title="Not in billable items">
                                                    <i class="fas fa-exclamation-circle mr-1"></i>Not Billable
                                                </span>
                                            <?php endif; ?>
                                            <p class="mb-1"><strong>Type:</strong> <?php echo htmlspecialchars($bed['bed_type'] ?? 'Regular'); ?></p>
                                            <p class="mb-1"><strong>Rate:</strong> $<?php echo number_format($bed['bed_rate'] ?? 0, 2); ?>/day</p>
                                            <p class="mb-1"><strong>Patient:</strong> <?php echo $patient_name; ?></p>
                                            <?php if (!empty($bed['bed_notes'])): ?>
                                                <p class="mb-0">
                                                    <small class="text-muted" title="<?php echo htmlspecialchars($bed['bed_notes']); ?>">
                                                        <?php 
                                                        $notes = htmlspecialchars($bed['bed_notes']);
                                                        echo strlen($notes) > 50 ? substr($notes, 0, 50) . '...' : $notes;
                                                        ?>
                                                    </small>
                                                </p>
                                            <?php endif; ?>
                                            <?php if (!empty($bed['bed_last_cleaned_at'])): ?>
                                                <p class="mb-0">
                                                    <small class="text-info">
                                                        Cleaned: <?php echo date('M j, Y', strtotime($bed['bed_last_cleaned_at'])); ?>
                                                    </small>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endwhile; ?>
                        </div>
                        <?php else: ?>
                        <div class="col-12 text-center py-4">
                            <i class="fas fa-bed fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">No Beds Found</h5>
                            <p class="text-muted">This ward doesn't have any beds yet.</p>
                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addBedModal">
                                <i class="fas fa-plus mr-2"></i>Add First Bed
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Bed Modal for this ward -->
    <div class="modal fade" id="addBedModal" tabindex="-1" role="dialog" aria-labelledby="addBedModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="addBedModalLabel"><i class="fas fa-bed mr-2"></i>Add New Bed to <?php echo htmlspecialchars($ward['ward_name']); ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="bed_ward_id" value="<?php echo $ward_id; ?>">
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle mr-2"></i>
                            This bed will be automatically added to billable items with appropriate category.
                        </div>
                        <div class="form-group mb-3">
                            <label class="form-label">Bed Number *</label>
                            <input type="text" class="form-control" name="bed_number" required>
                        </div>
                        <div class="form-group mb-3">
                            <label class="form-label">Bed Type</label>
                            <select class="form-control" name="bed_type">
                                <option value="Regular">Regular</option>
                                <option value="ICU">ICU</option>
                                <option value="Private">Private</option>
                                <option value="Semi-Private">Semi-Private</option>
                            </select>
                        </div>
                        <div class="form-group mb-3">
                            <label class="form-label">Daily Rate ($)</label>
                            <input type="number" class="form-control" name="bed_rate" step="0.01" min="0" value="0.00">
                        </div>
                        <div class="form-group mb-3">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="bed_notes" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_bed" class="btn btn-success">Add Bed</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php if ($show_edit_modal && $edit_bed_data): ?>
    <!-- Edit Bed Modal (shown via PHP) -->
    <div class="modal fade show" id="editBedModal" tabindex="-1" role="dialog" aria-labelledby="editBedModalLabel" style="display: block; padding-right: 17px;" aria-modal="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header bg-warning text-white">
                    <h5 class="modal-title" id="editBedModalLabel"><i class="fas fa-edit mr-2"></i>Edit Bed <?php echo htmlspecialchars($edit_bed_data['bed_number']); ?></h5>
                    <a href="ward_beds.php?ward_id=<?php echo $ward_id; ?>" class="btn-close btn-close-white"></a>
                </div>
                <form method="POST">
                    <input type="hidden" name="bed_id" value="<?php echo $edit_bed_data['bed_id']; ?>">
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle mr-2"></i>
                            Updating this bed will also update the corresponding billable item (if exists).
                        </div>
                        <div class="form-group mb-3">
                            <label class="form-label">Bed Number *</label>
                            <input type="text" class="form-control" name="bed_number" value="<?php echo htmlspecialchars($edit_bed_data['bed_number']); ?>" required>
                        </div>
                        <div class="form-group mb-3">
                            <label class="form-label">Bed Type</label>
                            <select class="form-control" name="bed_type">
                                <option value="Regular" <?php echo $edit_bed_data['bed_type'] == 'Regular' ? 'selected' : ''; ?>>Regular</option>
                                <option value="ICU" <?php echo $edit_bed_data['bed_type'] == 'ICU' ? 'selected' : ''; ?>>ICU</option>
                                <option value="Private" <?php echo $edit_bed_data['bed_type'] == 'Private' ? 'selected' : ''; ?>>Private</option>
                                <option value="Semi-Private" <?php echo $edit_bed_data['bed_type'] == 'Semi-Private' ? 'selected' : ''; ?>>Semi-Private</option>
                            </select>
                        </div>
                        <div class="form-group mb-3">
                            <label class="form-label">Daily Rate ($)</label>
                            <input type="number" class="form-control" name="bed_rate" step="0.01" min="0" value="<?php echo htmlspecialchars($edit_bed_data['bed_rate']); ?>">
                        </div>
                        <div class="form-group mb-3">
                            <label class="form-label">Bed Status</label>
                            <select class="form-control" name="bed_status">
                                <option value="available" <?php echo $edit_bed_data['bed_status'] == 'available' ? 'selected' : ''; ?>>Available</option>
                                <option value="occupied" <?php echo $edit_bed_data['bed_status'] == 'occupied' ? 'selected' : ''; ?>>Occupied</option>
                                <option value="maintenance" <?php echo $edit_bed_data['bed_status'] == 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                                <option value="reserved" <?php echo $edit_bed_data['bed_status'] == 'reserved' ? 'selected' : ''; ?>>Reserved</option>
                            </select>
                        </div>
                        <div class="form-group mb-3">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="bed_notes" rows="2"><?php echo htmlspecialchars($edit_bed_data['bed_notes'] ?? ''); ?></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <a href="ward_beds.php?ward_id=<?php echo $ward_id; ?>" class="btn btn-secondary">Cancel</a>
                        <button type="submit" name="update_bed" class="btn btn-warning">Update Bed</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="modal-backdrop fade show"></div>
    <?php endif; ?>

    <?php
    // Helper function for bed status colors
    function getBedStatusClass($status) {
        switch ($status) {
            case 'available': return 'success';
            case 'occupied': return 'warning';
            case 'maintenance': return 'danger';
            case 'reserved': return 'info';
            default: return 'secondary';
        }
    }

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
    // Auto show add/edit modals if needed
    <?php if ($show_edit_modal && $edit_bed_data): ?>
    $(document).ready(function() {
        $('#editBedModal').modal('show');
    });
    <?php endif; ?>

    // Initialize Bootstrap dropdowns
    $(document).ready(function() {
        var dropdownElementList = [].slice.call(document.querySelectorAll('.dropdown-toggle'))
        var dropdownList = dropdownElementList.map(function (dropdownToggleEl) {
            return new bootstrap.Dropdown(dropdownToggleEl)
        });
    });
    </script>
</body>
</html>
<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $price_list_id = intval($_POST['price_list_id']);
    $entity_type = sanitizeInput($_POST['entity_type']);
    $pricing_strategy = sanitizeInput($_POST['pricing_strategy'] ?? 'MARKUP_PERCENTAGE');
    $markup_value = floatval($_POST['markup_value'] ?? $_POST['markup_value_service'] ?? 0);
    $bulk_price = floatval($_POST['bulk_price'] ?? 0);
    $notes = sanitizeInput($_POST['notes'] ?? '');
    $created_by = intval($_SESSION['user_id']);
    
    // Get selected item/service IDs
    $item_ids = isset($_POST['item_ids']) ? array_map('intval', $_POST['item_ids']) : [];
    $service_ids = isset($_POST['service_ids']) ? array_map('intval', $_POST['service_ids']) : [];
    
    // Validate
    $errors = [];
    
    if ($price_list_id <= 0) {
        $_SESSION['alert_message'] = "Invalid price list ID";
        header("Location: price_management.php");
        exit;
    }
    
    if (!in_array($entity_type, ['ITEM', 'SERVICE'])) {
        $_SESSION['alert_message'] = "Invalid entity type";
        header("Location: price_list_items.php?id=$price_list_id");
        exit;
    }
    
    if (($entity_type == 'ITEM' && empty($item_ids)) || ($entity_type == 'SERVICE' && empty($service_ids))) {
        $_SESSION['alert_message'] = "Please select at least one item/service";
        header("Location: price_list_items.php?id=$price_list_id");
        exit;
    }
    
    // Get price list details for validation
    $price_list_sql = "SELECT * FROM price_lists WHERE price_list_id = ? AND is_active = 1";
    $stmt = $mysqli->prepare($price_list_sql);
    $stmt->bind_param('i', $price_list_id);
    $stmt->execute();
    $price_list_result = $stmt->get_result();
    $price_list = $price_list_result->fetch_assoc();
    
    if (!$price_list) {
        $_SESSION['alert_message'] = "Price list not found or inactive";
        header("Location: price_management.php");
        exit;
    }
    
    // Process the request
    $mysqli->begin_transaction();
    
    try {
        $added_count = 0;
        $skipped_count = 0;
        
        if ($entity_type == 'ITEM' && !empty($item_ids)) {
            // Process items
            foreach ($item_ids as $item_id) {
                // Check if item already exists in price list
                $check_sql = "SELECT item_price_id FROM item_prices WHERE item_id = ? AND price_list_id = ? AND is_active = 1";
                $check_stmt = $mysqli->prepare($check_sql);
                $check_stmt->bind_param('ii', $item_id, $price_list_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows > 0) {
                    $skipped_count++;
                    continue; // Skip if already exists
                }
                
                // Get item base price
                $item_sql = "SELECT item_unit_price FROM inventory_items WHERE item_id = ?";
                $item_stmt = $mysqli->prepare($item_sql);
                $item_stmt->bind_param('i', $item_id);
                $item_stmt->execute();
                $item_result = $item_stmt->get_result();
                $item = $item_result->fetch_assoc();
                
                if (!$item) {
                    $skipped_count++;
                    continue; // Skip if item not found
                }
                
                $base_price = floatval($item['item_unit_price']);
                $calculated_price = calculatePrice($base_price, $pricing_strategy, $markup_value, $bulk_price);
                
                // Insert item price
                $insert_sql = "INSERT INTO item_prices (item_id, price_list_id, price, is_active, created_by, created_at, notes)
                              VALUES (?, ?, ?, 1, ?, NOW(), ?)";
                $insert_stmt = $mysqli->prepare($insert_sql);
                $insert_stmt->bind_param('iidiiss', $item_id, $price_list_id, $calculated_price, $created_by, $notes);
                
                if ($insert_stmt->execute()) {
                    $added_count++;
                    
                    // Record in price history
                    $history_sql = "INSERT INTO price_history (entity_type, entity_id, price_list_id, old_price, new_price, changed_by, changed_at, notes)
                                   VALUES ('ITEM', ?, ?, ?, ?, ?, NOW(), ?)";
                    $history_stmt = $mysqli->prepare($history_sql);
                    $history_stmt->bind_param('iidiis', $item_id, $price_list_id, $base_price, $calculated_price, $created_by, $notes);
                    $history_stmt->execute();
                } else {
                    $skipped_count++;
                }
            }
        } elseif ($entity_type == 'SERVICE' && !empty($service_ids)) {
            // Process services
            foreach ($service_ids as $service_id) {
                // Check if service already exists in price list
                $check_sql = "SELECT service_price_id FROM medical_service_prices WHERE medical_service_id = ? AND price_list_id = ? AND is_active = 1";
                $check_stmt = $mysqli->prepare($check_sql);
                $check_stmt->bind_param('ii', $service_id, $price_list_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows > 0) {
                    $skipped_count++;
                    continue; // Skip if already exists
                }
                
                // Get service base price
                $service_sql = "SELECT fee_amount FROM medical_services WHERE medical_service_id = ? AND is_active = 1";
                $service_stmt = $mysqli->prepare($service_sql);
                $service_stmt->bind_param('i', $service_id);
                $service_stmt->execute();
                $service_result = $service_stmt->get_result();
                $service = $service_result->fetch_assoc();
                
                if (!$service) {
                    $skipped_count++;
                    continue; // Skip if service not found or inactive
                }
                
                $base_price = floatval($service['fee_amount']);
                $calculated_price = calculatePrice($base_price, $pricing_strategy, $markup_value, $bulk_price);
                
                // Insert service price
                $insert_sql = "INSERT INTO medical_service_prices (medical_service_id, price_list_id, price, is_active, created_by, created_at, notes)
                              VALUES (?, ?, ?, 1, ?, NOW(), ?)";
                $insert_stmt = $mysqli->prepare($insert_sql);
                $insert_stmt->bind_param('iidisss', $service_id, $price_list_id, $calculated_price, $created_by, $notes);
                
                if ($insert_stmt->execute()) {
                    $added_count++;
                    
                    // Record in price history
                    $history_sql = "INSERT INTO price_history (entity_type, entity_id, price_list_id, old_price, new_price, changed_by, changed_at, notes)
                                   VALUES ('SERVICE', ?, ?, ?, ?, ?, NOW(), ?)";
                    $history_stmt = $mysqli->prepare($history_sql);
                    $history_stmt->bind_param('iidiis', $service_id, $price_list_id, $base_price, $calculated_price, $created_by, $notes);
                    $history_stmt->execute();
                } else {
                    $skipped_count++;
                }
            }
        }
        
        $mysqli->commit();
        
        // Prepare success message
        $message = "Successfully added $added_count " . strtolower($entity_type) . "(s) to price list.";
        if ($skipped_count > 0) {
            $message .= " $skipped_count " . strtolower($entity_type) . "(s) were skipped (already exist or invalid).";
        }
        
        $_SESSION['alert_message'] = $message;
        
    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_message'] = "Error adding items: " . $e->getMessage();
    }
    
    // Redirect back to price list items page
    header("Location: price_list_items.php?id=$price_list_id");
    exit;
    
} else {
    // Not a POST request, redirect back
    header("Location: price_management.php");
    exit;
}

// Function to calculate price based on strategy
function calculatePrice($base_price, $strategy, $percentage, $fixed_price) {
    switch ($strategy) {
        case 'MARKUP_PERCENTAGE':
            return round($base_price * (1 + ($percentage / 100)), 2);
        case 'DISCOUNT_PERCENTAGE':
            $calculated = $base_price * (1 - ($percentage / 100));
            return round(max($calculated, 0), 2); // Ensure price doesn't go negative
        case 'FIXED_PRICE':
            return round($fixed_price, 2);
        default:
            return round($base_price, 2);
    }
}
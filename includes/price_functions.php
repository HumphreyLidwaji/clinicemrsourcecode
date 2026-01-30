<?php
// includes/price_functions.php

/**
 * Calculate price for a billable item
 */
function calculatePrice($mysqli, $item_type, $billable_item_id, $quantity, $payer_type, $price_list_id = 0) {
    // Get default price list if not specified
    if (!$price_list_id) {
        $price_list_id = getDefaultPriceListId($mysqli, $payer_type);
    }
    
    // Get billable item details
    $sql = "SELECT bi.*, bi.unit_price as default_price
            FROM billable_items bi
            WHERE bi.billable_item_id = ? AND bi.is_active = 1";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('i', $billable_item_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $item_name = $row['item_name'];
        $item_code = $row['item_code'];
        $default_price = $row['default_price'];
    } else {
        return [
            'error' => 'Billable item not found',
            'entity_type' => $item_type,
            'entity_id' => $billable_item_id
        ];
    }
    
    // Get price from price list
    $sql = "SELECT pli.* 
            FROM price_list_items pli
            WHERE pli.billable_item_id = ? 
            AND pli.price_list_id = ?
            AND (pli.effective_to IS NULL OR pli.effective_to >= CURDATE())
            AND pli.effective_from <= CURDATE()
            ORDER BY pli.effective_from DESC
            LIMIT 1";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('ii', $billable_item_id, $price_list_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $base_price = $row['price'];
        $price_list_item_id = $row['price_list_item_id'];
    } else {
        // Fallback to default price
        $base_price = $default_price;
        $price_list_item_id = null;
    }
    
    // Apply pricing strategy
    $price_details = applyPricingStrategy(
        $base_price, 
        $quantity, 
        $payer_type, 
        100, // Default coverage - can be modified if you have coverage in price_list_items
        $billable_item_id,
        $item_type
    );
    
    return [
        'entity_type' => $item_type,
        'entity_id' => $billable_item_id,
        'name' => $item_name,
        'code' => $item_code,
        'base_price' => $base_price,
        'final_price' => $price_details['final_price'],
        'quantity' => $quantity,
        'total' => $price_details['final_price'] * $quantity,
        'price_list_id' => $price_list_id,
        'price_list_item_id' => $price_list_item_id,
        'strategy' => $payer_type,
        'coverage' => $price_details['coverage'] ?? null
    ];
}

/**
 * Apply pricing strategy based on payer type
 */
function applyPricingStrategy($base_price, $quantity, $payer_type, $covered_percentage, $entity_id, $entity_type) {
    $result = [
        'final_price' => $base_price * $quantity,
        'quantity' => $quantity
    ];
    
    if (strtolower($payer_type) == 'cash') {
        // Cash pricing - apply any cash discounts
        $result['final_price'] = calculateCashPrice($base_price * $quantity, $entity_id, $entity_type);
    } else {
        // Insurance pricing - apply coverage
        $coverage_details = calculateInsuranceCoverage($base_price * $quantity, $covered_percentage);
        $result['final_price'] = $coverage_details['insurance_pays'];
        $result['coverage'] = $coverage_details;
    }
    
    return $result;
}

/**
 * Calculate cash price with discounts
 */
function calculateCashPrice($total_price, $entity_id, $entity_type) {
    // For now, return total price
    // You can add discount logic here later
    return $total_price;
}

/**
 * Calculate insurance coverage
 */
function calculateInsuranceCoverage($price, $covered_percentage) {
    $covered_amount = $price * ($covered_percentage / 100);
    $patient_pays = $price - $covered_amount;
    
    return [
        'insurance_pays' => $covered_amount,
        'patient_pays' => $patient_pays,
        'coverage_rate' => $covered_percentage . '%',
        'total_price' => $price
    ];
}

/**
 * Get default price list ID for a payer type
 */
function getDefaultPriceListId($mysqli, $payer_type) {
    $sql = "SELECT price_list_id 
            FROM price_lists 
            WHERE price_list_type = ? 
            AND is_default = 1 
            AND is_active = 1 
            LIMIT 1";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('s', $payer_type);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        return $row['price_list_id'];
    }
    
    return 0;
}

/**
 * Clone a price list
 */
function clonePriceList($mysqli, $source_id, $new_name, $cloned_by) {
    // Start transaction
    $mysqli->begin_transaction();
    
    try {
        // Get source price list details
        $sql = "SELECT * FROM price_lists WHERE price_list_id = ?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param('i', $source_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $source_list = $result->fetch_assoc();
        
        if (!$source_list) {
            throw new Exception("Source price list not found");
        }
        
        // Create new price list
        $sql = "INSERT INTO price_lists SET 
                price_list_name = ?,
                price_list_code = ?,
                price_list_type = ?,
                insurance_provider_id = ?,
                revenue_account_id = ?,
                accounts_receivable_account_id = ?,
                is_default = 0,
                is_active = 1,
                valid_from = ?,
                valid_to = ?,
                currency = ?,
                notes = ?,
                created_by = ?,
                created_at = NOW()";
        
        // Generate new price list code
        $new_code = $source_list['price_list_code'] . '_CLONE_' . date('YmdHis');
        
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param(
            'sssiiissssi',
            $new_name,
            $new_code,
            $source_list['price_list_type'],
            $source_list['insurance_provider_id'],
            $source_list['revenue_account_id'],
            $source_list['accounts_receivable_account_id'],
            $source_list['valid_from'],
            $source_list['valid_to'],
            $source_list['currency'],
            $source_list['notes'],
            $cloned_by
        );
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to create price list: " . $stmt->error);
        }
        
        $new_list_id = $mysqli->insert_id;
        
        // Clone price list items
        $sql = "INSERT INTO price_list_items (price_list_id, billable_item_id, price, effective_from, effective_to, created_by, created_at)
                SELECT ?, billable_item_id, price, effective_from, effective_to, ?, NOW()
                FROM price_list_items 
                WHERE price_list_id = ? 
                AND (effective_to IS NULL OR effective_to >= CURDATE())";
        
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param('iii', $new_list_id, $cloned_by, $source_id);
        if (!$stmt->execute()) {
            throw new Exception("Failed to clone price list items: " . $stmt->error);
        }
        
        // Record clone operation
        $sql = "INSERT INTO price_list_clones SET 
                source_price_list_id = ?,
                target_price_list_id = ?,
                cloned_by = ?,
                cloned_at = NOW()";
        
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param('iii', $source_id, $new_list_id, $cloned_by);
        if (!$stmt->execute()) {
            throw new Exception("Failed to record clone operation: " . $stmt->error);
        }
        
        $mysqli->commit();
        return $new_list_id;
        
    } catch (Exception $e) {
        $mysqli->rollback();
        error_log("Failed to clone price list: " . $e->getMessage());
        return false;
    }
}

/**
 * Update billable item price
 */
function updateBillableItemPrice($mysqli, $billable_item_id, $price_list_id, $new_price, $effective_from, $effective_to, $changed_by, $reason = '') {
    // Get current active price
    $sql = "SELECT price_list_item_id, price 
            FROM price_list_items 
            WHERE billable_item_id = ? 
            AND price_list_id = ?
            AND (effective_to IS NULL OR effective_to >= CURDATE())
            AND effective_from <= CURDATE()
            ORDER BY effective_from DESC
            LIMIT 1";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('ii', $billable_item_id, $price_list_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $current = $result->fetch_assoc();
    
    // End current price if exists
    if ($current) {
        $sql = "UPDATE price_list_items 
                SET effective_to = DATE_SUB(?, INTERVAL 1 DAY)
                WHERE price_list_item_id = ?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param('si', $effective_from, $current['price_list_item_id']);
        $stmt->execute();
        
        // Log price change
        logPriceChange($mysqli, $current['price_list_item_id'], $billable_item_id, $price_list_id, 
                      $current['price'], $new_price, $changed_by, $reason);
    }
    
    // Insert new price
    $sql = "INSERT INTO price_list_items (price_list_id, billable_item_id, price, effective_from, effective_to, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('iidssi', $price_list_id, $billable_item_id, $new_price, $effective_from, $effective_to, $changed_by);
    $stmt->execute();
    
    return true;
}

/**
 * Log price change to history
 */
function logPriceChange($mysqli, $price_list_item_id, $billable_item_id, $price_list_id, 
                       $old_price, $new_price, $changed_by, $reason) {
    
    $sql = "INSERT INTO price_history SET
            price_list_item_id = ?,
            billable_item_id = ?,
            price_list_id = ?,
            old_price = ?,
            new_price = ?,
            changed_by = ?,
            reason = ?,
            changed_at = NOW()";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param(
        'iiiddis',
        $price_list_item_id,
        $billable_item_id,
        $price_list_id,
        $old_price,
        $new_price,
        $changed_by,
        $reason
    );
    
    return $stmt->execute();
}

/**
 * Get price modifiers for a billable item
 */
function getPriceModifiers($mysqli, $billable_item_id) {
    $sql = "SELECT pm.*
            FROM price_modifiers pm
            JOIN price_modifier_items pmi ON pm.modifier_id = pmi.modifier_id
            WHERE pm.is_active = 1 
            AND (pmi.billable_item_id = ? OR pmi.billable_item_id IS NULL)
            AND (pm.valid_from IS NULL OR pm.valid_from <= CURDATE())
            AND (pm.valid_to IS NULL OR pm.valid_to >= CURDATE())
            ORDER BY pm.modifier_id";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('i', $billable_item_id);
    $stmt->execute();
    
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * Get price list details
 */
function getPriceListDetails($mysqli, $price_list_id) {
    $sql = "SELECT pl.*, ic.company_name,
                   creator.user_name as created_by_name,
                   updater.user_name as updated_by_name
            FROM price_lists pl
            LEFT JOIN insurance_companies ic ON pl.insurance_provider_id = ic.insurance_company_id
            LEFT JOIN users creator ON pl.created_by = creator.user_id
            LEFT JOIN users updater ON pl.updated_by = updater.user_id
            WHERE pl.price_list_id = ?";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('i', $price_list_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->fetch_assoc();
}

/**
 * Get all items in a price list
 */
function getPriceListItems($mysqli, $price_list_id, $active_only = true) {
    $sql = "SELECT pli.*, bi.item_name, bi.item_code, bi.unit_price as default_price,
                   bi.item_type, bi.category_id, bi.is_taxable, bi.tax_rate,
                   bc.category_name
            FROM price_list_items pli
            JOIN billable_items bi ON pli.billable_item_id = bi.billable_item_id
            LEFT JOIN billable_categories bc ON bi.category_id = bc.category_id
            WHERE pli.price_list_id = ?";
    
    if ($active_only) {
        $sql .= " AND (pli.effective_to IS NULL OR pli.effective_to >= CURDATE()) 
                  AND pli.effective_from <= CURDATE()";
    }
    
    $sql .= " ORDER BY bi.item_name";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('i', $price_list_id);
    $stmt->execute();
    
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * Get billable items not in a price list
 */
function getBillableItemsNotInPriceList($mysqli, $price_list_id) {
    $sql = "SELECT bi.*, bc.category_name
            FROM billable_items bi
            LEFT JOIN billable_categories bc ON bi.category_id = bc.category_id
            WHERE bi.is_active = 1 
            AND bi.billable_item_id NOT IN (
                SELECT billable_item_id 
                FROM price_list_items 
                WHERE price_list_id = ? 
                AND (effective_to IS NULL OR effective_to >= CURDATE())
            )
            ORDER BY bi.item_type, bi.item_name";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('i', $price_list_id);
    $stmt->execute();
    
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * Get price history for a billable item
 */
function getPriceHistory($mysqli, $billable_item_id, $limit = 50) {
    $sql = "SELECT ph.*, pl.price_list_name, u.user_name as changed_by_name,
                   bi.item_name
            FROM price_history ph
            JOIN price_lists pl ON ph.price_list_id = pl.price_list_id
            JOIN billable_items bi ON ph.billable_item_id = bi.billable_item_id
            LEFT JOIN users u ON ph.changed_by = u.user_id
            WHERE ph.billable_item_id = ?
            ORDER BY ph.changed_at DESC
            LIMIT ?";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('ii', $billable_item_id, $limit);
    $stmt->execute();
    
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * Check if price list exists and is active
 */
function validatePriceList($mysqli, $price_list_id) {
    $sql = "SELECT price_list_id FROM price_lists WHERE price_list_id = ? AND is_active = 1";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('i', $price_list_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->num_rows > 0;
}

/**
 * Get all price lists for dropdown
 */
function getAllPriceLists($mysqli, $active_only = true) {
    $sql = "SELECT pl.price_list_id, pl.price_list_name, pl.price_list_code, pl.price_list_type, ic.company_name
            FROM price_lists pl
            LEFT JOIN insurance_companies ic ON pl.insurance_provider_id = ic.insurance_company_id";
    
    if ($active_only) {
        $sql .= " WHERE pl.is_active = 1";
    }
    
    $sql .= " ORDER BY pl.price_list_type, pl.price_list_name";
    
    $result = $mysqli->query($sql);
    return $result->fetch_all(MYSQLI_ASSOC);
}

/**
 * Get price lists by type
 */
function getPriceListsByType($mysqli, $price_list_type, $active_only = true) {
    $sql = "SELECT pl.price_list_id, pl.price_list_name, pl.price_list_code, ic.company_name
            FROM price_lists pl
            LEFT JOIN insurance_companies ic ON pl.insurance_provider_id = ic.insurance_company_id
            WHERE pl.price_list_type = ?";
    
    if ($active_only) {
        $sql .= " AND pl.is_active = 1";
    }
    
    $sql .= " ORDER BY pl.price_list_name";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('s', $price_list_type);
    $stmt->execute();
    
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * Bulk update prices from CSV/array
 */
function bulkUpdatePrices($mysqli, $price_list_id, $updates, $changed_by) {
    $mysqli->begin_transaction();
    $success_count = 0;
    $error_count = 0;
    $errors = [];
    
    try {
        foreach ($updates as $index => $update) {
            if (!isset($update['billable_item_id']) || !isset($update['price'])) {
                $errors[] = "Row $index: Missing required fields";
                $error_count++;
                continue;
            }
            
            $billable_item_id = intval($update['billable_item_id']);
            $new_price = floatval($update['price']);
            $effective_from = $update['effective_from'] ?? date('Y-m-d');
            $effective_to = $update['effective_to'] ?? null;
            $reason = $update['reason'] ?? 'Bulk update';
            
            $success = updateBillableItemPrice($mysqli, $billable_item_id, $price_list_id, $new_price, 
                                              $effective_from, $effective_to, $changed_by, $reason);
            
            if ($success) {
                $success_count++;
            } else {
                $error_count++;
                $errors[] = "Row $index: Failed to update price";
            }
        }
        
        $mysqli->commit();
        
        return [
            'success' => true,
            'success_count' => $success_count,
            'error_count' => $error_count,
            'errors' => $errors
        ];
        
    } catch (Exception $e) {
        $mysqli->rollback();
        
        return [
            'success' => false,
            'message' => 'Bulk update failed: ' . $e->getMessage(),
            'success_count' => $success_count,
            'error_count' => $error_count,
            'errors' => $errors
        ];
    }
}

/**
 * Get price statistics for dashboard
 */
function getPriceStatistics($mysqli) {
    $stats = [];
    
    // Total price lists
    $sql = "SELECT COUNT(*) as total FROM price_lists";
    $result = $mysqli->query($sql);
    $stats['total_price_lists'] = $result->fetch_assoc()['total'];
    
    // Active price lists
    $sql = "SELECT COUNT(*) as active FROM price_lists WHERE is_active = 1";
    $result = $mysqli->query($sql);
    $stats['active_price_lists'] = $result->fetch_assoc()['active'];
    
    // Cash price lists
    $sql = "SELECT COUNT(*) as cash FROM price_lists WHERE price_list_type = 'cash' AND is_active = 1";
    $result = $mysqli->query($sql);
    $stats['cash_price_lists'] = $result->fetch_assoc()['cash'];
    
    // Insurance price lists
    $sql = "SELECT COUNT(*) as insurance FROM price_lists WHERE price_list_type = 'insurance' AND is_active = 1";
    $result = $mysqli->query($sql);
    $stats['insurance_price_lists'] = $result->fetch_assoc()['insurance'];
    
    // Active prices (current effective prices)
    $sql = "SELECT COUNT(DISTINCT billable_item_id) as active_prices 
            FROM price_list_items 
            WHERE (effective_to IS NULL OR effective_to >= CURDATE()) 
            AND effective_from <= CURDATE()";
    $result = $mysqli->query($sql);
    $stats['active_prices'] = $result->fetch_assoc()['active_prices'];
    
    // Total billable items
    $sql = "SELECT COUNT(*) as total_billable_items FROM billable_items WHERE is_active = 1";
    $result = $mysqli->query($sql);
    $stats['total_billable_items'] = $result->fetch_assoc()['total_billable_items'];
    
    // Today's price changes
    $sql = "SELECT COUNT(*) as today FROM price_history WHERE DATE(changed_at) = CURDATE()";
    $result = $mysqli->query($sql);
    $stats['today_changes'] = $result->fetch_assoc()['today'];
    
    // Default price lists
    $sql = "SELECT COUNT(*) as defaults FROM price_lists WHERE is_default = 1 AND is_active = 1";
    $result = $mysqli->query($sql);
    $stats['default_price_lists'] = $result->fetch_assoc()['defaults'];
    
    return $stats;
}

/**
 * Search for billable items by name/code
 */
function searchBillableItems($mysqli, $search_term, $limit = 20) {
    $sql = "SELECT bi.*, bc.category_name
            FROM billable_items bi
            LEFT JOIN billable_categories bc ON bi.category_id = bc.category_id
            WHERE (bi.item_name LIKE ? OR bi.item_code LIKE ?)
            AND bi.is_active = 1
            ORDER BY bi.item_name
            LIMIT ?";
    
    $stmt = $mysqli->prepare($sql);
    $search_param = "%$search_term%";
    $stmt->bind_param('ssi', $search_param, $search_param, $limit);
    $stmt->execute();
    
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * Export price list to CSV
 */
function exportPriceListToCSV($mysqli, $price_list_id) {
    $price_list = getPriceListDetails($mysqli, $price_list_id);
    $items = getPriceListItems($mysqli, $price_list_id);
    
    $csv_data = [];
    
    // Add header
    $csv_data[] = [
        'Price List: ' . $price_list['price_list_name'],
        'Code: ' . $price_list['price_list_code'],
        'Type: ' . $price_list['price_list_type'],
        'Company: ' . ($price_list['company_name'] ?? 'N/A'),
        'Export Date: ' . date('Y-m-d H:i:s')
    ];
    $csv_data[] = []; // Empty row
    
    // Add items header
    $csv_data[] = ['BILLABLE ITEMS', '', '', '', '', '', ''];
    $csv_data[] = ['ID', 'Type', 'Name', 'Code', 'Price', 'Effective From', 'Effective To'];
    
    // Add items
    foreach ($items as $item) {
        $csv_data[] = [
            $item['billable_item_id'],
            $item['item_type'],
            $item['item_name'],
            $item['item_code'],
            $item['price'],
            $item['effective_from'],
            $item['effective_to'] ?? ''
        ];
    }
    
    return $csv_data;
}

/**
 * Import price list from CSV
 */
function importPriceListFromCSV($mysqli, $price_list_id, $csv_data, $changed_by) {
    $mysqli->begin_transaction();
    $success_count = 0;
    $error_count = 0;
    $errors = [];
    
    try {
        // Skip header rows (first 5 rows are headers)
        for ($i = 5; $i < count($csv_data); $i++) {
            $row = $csv_data[$i];
            
            // Skip empty rows or header rows
            if (empty($row[0]) || $row[0] == 'ID' || $row[0] == 'BILLABLE ITEMS') {
                continue;
            }
            
            $billable_item_id = intval($row[0]);
            $price = floatval($row[4] ?? 0);
            $effective_from = $row[5] ?? date('Y-m-d');
            $effective_to = !empty($row[6]) ? $row[6] : null;
            
            $success = updateBillableItemPrice($mysqli, $billable_item_id, $price_list_id, $price, 
                                              $effective_from, $effective_to, $changed_by, 'CSV Import');
            
            if ($success) {
                $success_count++;
            } else {
                $error_count++;
                $errors[] = "Row $i: Failed to import";
            }
        }
        
        $mysqli->commit();
        
        return [
            'success' => true,
            'success_count' => $success_count,
            'error_count' => $error_count,
            'errors' => $errors
        ];
        
    } catch (Exception $e) {
        $mysqli->rollback();
        
        return [
            'success' => false,
            'message' => 'Import failed: ' . $e->getMessage(),
            'success_count' => $success_count,
            'error_count' => $error_count,
            'errors' => $errors
        ];
    }
}

/**
 * Compare two price lists
 */
function comparePriceLists($mysqli, $list1_id, $list2_id) {
    $comparison = [
        'list1' => getPriceListDetails($mysqli, $list1_id),
        'list2' => getPriceListDetails($mysqli, $list2_id),
        'items' => []
    ];
    
    // Get current items from both lists
    $items1 = getPriceListItems($mysqli, $list1_id);
    $items2 = getPriceListItems($mysqli, $list2_id);
    
    // Create a combined list of all items
    $all_items = [];
    foreach ($items1 as $item) {
        $all_items[$item['billable_item_id']] = [
            'billable_item_id' => $item['billable_item_id'],
            'item_name' => $item['item_name'],
            'item_code' => $item['item_code'],
            'item_type' => $item['item_type'],
            'list1_price' => $item['price'],
            'list1_effective_from' => $item['effective_from'],
            'list1_effective_to' => $item['effective_to'],
            'list2_price' => null,
            'list2_effective_from' => null,
            'list2_effective_to' => null,
            'price_diff' => null
        ];
    }
    
    foreach ($items2 as $item) {
        if (isset($all_items[$item['billable_item_id']])) {
            $all_items[$item['billable_item_id']]['list2_price'] = $item['price'];
            $all_items[$item['billable_item_id']]['list2_effective_from'] = $item['effective_from'];
            $all_items[$item['billable_item_id']]['list2_effective_to'] = $item['effective_to'];
            $all_items[$item['billable_item_id']]['price_diff'] = $item['price'] - $all_items[$item['billable_item_id']]['list1_price'];
        } else {
            $all_items[$item['billable_item_id']] = [
                'billable_item_id' => $item['billable_item_id'],
                'item_name' => $item['item_name'],
                'item_code' => $item['item_code'],
                'item_type' => $item['item_type'],
                'list1_price' => null,
                'list1_effective_from' => null,
                'list1_effective_to' => null,
                'list2_price' => $item['price'],
                'list2_effective_from' => $item['effective_from'],
                'list2_effective_to' => $item['effective_to'],
                'price_diff' => null
            ];
        }
    }
    
    $comparison['items'] = array_values($all_items);
    
    return $comparison;
}

/**
 * Get billable item details
 */
function getBillableItemDetails($mysqli, $billable_item_id) {
    $sql = "SELECT bi.*, bc.category_name
            FROM billable_items bi
            LEFT JOIN billable_categories bc ON bi.category_id = bc.category_id
            WHERE bi.billable_item_id = ?";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('i', $billable_item_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->fetch_assoc();
}

/**
 * Add billable item to price list
 */
function addBillableItemToPriceList($mysqli, $price_list_id, $billable_item_id, $price, 
                                    $effective_from, $effective_to, $created_by) {
    
    // Check if already exists in price list
    $sql = "SELECT price_list_item_id 
            FROM price_list_items 
            WHERE price_list_id = ? 
            AND billable_item_id = ? 
            AND (effective_to IS NULL OR effective_to >= CURDATE())";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('ii', $price_list_id, $billable_item_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        return ['success' => false, 'message' => 'Item already exists in price list'];
    }
    
    // Insert new price list item
    $sql = "INSERT INTO price_list_items (price_list_id, billable_item_id, price, effective_from, effective_to, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('iidssi', $price_list_id, $billable_item_id, $price, $effective_from, $effective_to, $created_by);
    
    if ($stmt->execute()) {
        return ['success' => true, 'price_list_item_id' => $mysqli->insert_id];
    } else {
        return ['success' => false, 'message' => $stmt->error];
    }
}

/**
 * Remove billable item from price list
 */
function removeBillableItemFromPriceList($mysqli, $price_list_item_id) {
    $sql = "DELETE FROM price_list_items WHERE price_list_item_id = ?";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('i', $price_list_item_id);
    
    return $stmt->execute();
}

/**
 * Get price for specific date
 */
function getPriceForDate($mysqli, $billable_item_id, $price_list_id, $date) {
    $sql = "SELECT price 
            FROM price_list_items 
            WHERE billable_item_id = ? 
            AND price_list_id = ?
            AND effective_from <= ?
            AND (effective_to IS NULL OR effective_to >= ?)
            ORDER BY effective_from DESC
            LIMIT 1";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('iiss', $billable_item_id, $price_list_id, $date, $date);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        return $row['price'];
    }
    
    return null;
}

/**
 * Get price trends for a billable item
 */
function getPriceTrends($mysqli, $billable_item_id, $price_list_id, $months = 12) {
    $sql = "SELECT 
                YEAR(effective_from) as year,
                MONTH(effective_from) as month,
                AVG(price) as avg_price,
                MIN(price) as min_price,
                MAX(price) as max_price
            FROM price_list_items 
            WHERE billable_item_id = ? 
            AND price_list_id = ?
            AND effective_from >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
            GROUP BY YEAR(effective_from), MONTH(effective_from)
            ORDER BY year DESC, month DESC";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('iii', $billable_item_id, $price_list_id, $months);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>
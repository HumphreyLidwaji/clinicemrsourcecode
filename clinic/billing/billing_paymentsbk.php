<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Get current page name for redirects
$current_page = basename($_SERVER['PHP_SELF']);

// ========================
// ACCOUNTING CONFIGURATION
// ========================

// Default account mappings (fallback if service_accounts table doesn't have mapping)
$revenue_accounts = [
    'pharmacy' => 19,      // Product Sales (4010)
    'laboratory' => 20,    // Service Revenue (4020)
    'imaging' => 20,       // Service Revenue (4020)
    'radiology' => 20,     // Service Revenue (4020)
    'services' => 20,      // Service Revenue (4020)
    'procedure' => 20,     // Service Revenue (4020)
    'admission' => 20,     // Service Revenue (4020)
    'opd' => 20,           // Service Revenue (4020)
    'theatre' => 20,       // Service Revenue (4020)
    'surgery' => 20,       // Service Revenue (4020)
    'consultation' => 20,  // Service Revenue (4020)
    'ward' => 20,          // Service Revenue (4020)
    'nursing' => 20,       // Service Revenue (4020)
    'physiotherapy' => 20, // Service Revenue (4020)
    'dental' => 20,        // Service Revenue (4020)
];

// Map payment methods to debit accounts
$payment_accounts = [
    'cash' => 1,           // Cash (1010)
    'mpesa_stk' => 1,      // Cash (1010)
    'card' => 1,           // Cash (1010)
    'insurance' => 3,      // Accounts Receivable (1100)
    'check' => 1,          // Cash (1010)
    'bank_transfer' => 1,  // Cash (1010)
    'credit' => 3,         // Accounts Receivable (1100)
    'nhif' => 3,           // Accounts Receivable (1100)
];

// ========================
// ACCOUNTING FUNCTIONS
// ========================

function generatePaymentNumber($mysqli) {
    $prefix = "PMT";
    $year = date('Y');
    
    $last_sql = "SELECT payment_number FROM payments 
                 WHERE payment_number LIKE '$prefix-$year-%' 
                 ORDER BY payment_id DESC LIMIT 1";
    $last_result = mysqli_query($mysqli, $last_sql);
    
    if (mysqli_num_rows($last_result) > 0) {
        $last_number = mysqli_fetch_assoc($last_result)['payment_number'];
        $last_seq = intval(substr($last_number, -4));
        $new_seq = str_pad($last_seq + 1, 4, '0', STR_PAD_LEFT);
    } else {
        $new_seq = '0001';
    }
    
    return $prefix . '-' . $year . '-' . $new_seq;
}

function getRevenueAccountForService($mysqli, $item_id) {
    global $revenue_accounts;
    
    // NEW: First try to get account from service_accounts table
    $sql = "SELECT sa.account_id 
            FROM invoice_items ii
            LEFT JOIN service_accounts sa ON ii.service_id = sa.medical_service_id
            WHERE ii.item_id = ? 
            AND sa.account_type = 'revenue'
            LIMIT 1";
    
    $stmt = mysqli_prepare($mysqli, $sql);
    mysqli_stmt_bind_param($stmt, 'i', $item_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $account = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    
    if ($account && !empty($account['account_id'])) {
        return intval($account['account_id']);
    }
    
    // Fallback: Try to get service category from item
    $item_sql = "SELECT ii.*, s.service_category 
                 FROM invoice_items ii
                 LEFT JOIN medical_services s ON ii.service_id = s.medical_service_id
                 WHERE ii.item_id = ?";
    $item_stmt = mysqli_prepare($mysqli, $item_sql);
    mysqli_stmt_bind_param($item_stmt, 'i', $item_id);
    mysqli_stmt_execute($item_stmt);
    $item_result = mysqli_stmt_get_result($item_stmt);
    $item = mysqli_fetch_assoc($item_result);
    mysqli_stmt_close($item_stmt);
    
    if ($item && !empty($item['service_category'])) {
        $category = strtolower($item['service_category']);
        foreach ($revenue_accounts as $key => $account_id) {
            if (strpos($category, $key) !== false) {
                return $account_id;
            }
        }
    }
    
    // Default to Service Revenue if no specific category found
    return 20; // Service Revenue (4020)
}

function getCOGSAccountForService($mysqli, $service_id) {
    // Get COGS account for inventory tracking
    $sql = "SELECT account_id 
            FROM service_accounts 
            WHERE medical_service_id = ? 
            AND account_type = 'cogs'
            LIMIT 1";
    
    $stmt = mysqli_prepare($mysqli, $sql);
    mysqli_stmt_bind_param($stmt, 'i', $service_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $account = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    
    if ($account && !empty($account['account_id'])) {
        return intval($account['account_id']);
    }
    
    // Default COGS account (5110 - Cost of Goods Sold)
    return 23;
}

function getInventoryAccountForItem($mysqli, $item_id) {
    // Get inventory account for inventory items
    $sql = "SELECT a.account_id 
            FROM inventory_items ii
            LEFT JOIN accounts a ON ii.category_id = a.category_id
            WHERE ii.item_id = ? 
            AND a.account_type = 'inventory'
            LIMIT 1";
    
    $stmt = mysqli_prepare($mysqli, $sql);
    mysqli_stmt_bind_param($stmt, 'i', $item_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $account = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    
    if ($account && !empty($account['account_id'])) {
        return intval($account['account_id']);
    }
    
    // Default inventory account (1200 - Inventory)
    return 4;
}

function getDebitAccountForPaymentMethod($payment_method) {
    global $payment_accounts;
    return $payment_accounts[$payment_method] ?? 1; // Default to Cash
}

function updateInventoryForService($mysqli, $service_id, $quantity, $action = 'reduce', $session_user_id) {
    // Update inventory when service is billed
    $sql = "SELECT si.item_id, si.quantity_required, ii.item_name, ii.current_quantity
            FROM service_inventory_items si
            JOIN inventory_items ii ON si.item_id = ii.item_id
            WHERE si.medical_service_id = ?";
    
    $stmt = mysqli_prepare($mysqli, $sql);
    mysqli_stmt_bind_param($stmt, 'i', $service_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $inventory_items = [];
    
    while ($item = mysqli_fetch_assoc($result)) {
        $inventory_items[] = $item;
    }
    mysqli_stmt_close($stmt);
    
    $inventory_updated = 0;
    
    foreach ($inventory_items as $item) {
        $required_qty = $item['quantity_required'] * $quantity;
        
        if ($action === 'reduce') {
            // Check if enough inventory exists
            if ($item['current_quantity'] < $required_qty) {
                throw new Exception("Insufficient inventory for " . $item['item_name'] . 
                                  ". Available: " . $item['current_quantity'] . 
                                  ", Required: " . $required_qty);
            }
            
            $new_qty = $item['current_quantity'] - $required_qty;
        } else {
            $new_qty = $item['current_quantity'] + $required_qty;
        }
        
        // Update inventory quantity
        $update_sql = "UPDATE inventory_items SET 
                      current_quantity = ?,
                      last_updated = NOW()
                      WHERE item_id = ?";
        $update_stmt = mysqli_prepare($mysqli, $update_sql);
        mysqli_stmt_bind_param($update_stmt, 'ii', $new_qty, $item['item_id']);
        mysqli_stmt_execute($update_stmt);
        mysqli_stmt_close($update_stmt);
        
        // Record inventory transaction
        $transaction_sql = "INSERT INTO inventory_transactions SET 
                           item_id = ?,
                           transaction_type = ?,
                           quantity = ?,
                           remaining_quantity = ?,
                           related_service_id = ?,
                           created_by = ?,
                           created_at = NOW()";
        $trans_stmt = mysqli_prepare($mysqli, $transaction_sql);
        $trans_type = $action === 'reduce' ? 'usage' : 'return';
        mysqli_stmt_bind_param($trans_stmt, 'isiidi', 
            $item['item_id'], $trans_type, $required_qty, $new_qty, 
            $service_id, $session_user_id
        );
        mysqli_stmt_execute($trans_stmt);
        mysqli_stmt_close($trans_stmt);
        
        $inventory_updated++;
    }
    
    return $inventory_updated;
}

function getOrCreateJournalHeader($mysqli, $header_name, $entry_date, $created_by) {
    // Check if there's a journal header for this month
    $month_start = date('Y-m-01', strtotime($entry_date));
    $month_end = date('Y-m-t', strtotime($entry_date));
    
    $header_sql = "SELECT journal_header_id FROM journal_headers 
                   WHERE header_name LIKE ? 
                   AND entry_date BETWEEN ? AND ?
                   AND status = 'draft'
                   LIMIT 1";
    $header_stmt = mysqli_prepare($mysqli, $header_sql);
    $search_name = "Medical Revenue%";
    mysqli_stmt_bind_param($header_stmt, 'sss', $search_name, $month_start, $month_end);
    mysqli_stmt_execute($header_stmt);
    $header_result = mysqli_stmt_get_result($header_stmt);
    $existing_header = mysqli_fetch_assoc($header_result);
    mysqli_stmt_close($header_stmt);
    
    if ($existing_header) {
        return $existing_header['journal_header_id'];
    }
    
    // Create new journal header
    $new_header_name = "Medical Revenue - " . date('F Y', strtotime($entry_date));
    $reference_number = "MED-JRNL-" . date('Ym');
    
    $create_sql = "INSERT INTO journal_headers SET 
                   header_name = ?,
                   reference_number = ?,
                   entry_date = ?,
                   description = 'Medical revenue entries for ' . ?,
                   status = 'draft',
                   module = 'medical_revenue',
                   created_by = ?,
                   created_at = NOW()";
    
    $create_stmt = mysqli_prepare($mysqli, $create_sql);
    $month_name = date('F Y', strtotime($entry_date));
    mysqli_stmt_bind_param($create_stmt, 'ssssi', 
        $new_header_name, $reference_number, $entry_date, $month_name, $created_by
    );
    
    if (!mysqli_stmt_execute($create_stmt)) {
        throw new Exception("Failed to create journal header: " . mysqli_stmt_error($create_stmt));
    }
    
    $header_id = mysqli_insert_id($mysqli);
    mysqli_stmt_close($create_stmt);
    
    return $header_id;
}

function createCompleteMedicalJournalEntry($mysqli, $payment_id, $invoice_id, $payment_amount, 
                                         $payment_method, $session_user_id, $invoice_number, 
                                         $patient_name, $invoice_items) {
    
    // Start transaction for atomic operations
    mysqli_begin_transaction($mysqli);
    
    try {
        // Get debit account for payment method
        $debit_account_id = getDebitAccountForPaymentMethod($payment_method);
        
        // Get or create journal header
        $journal_header_id = getOrCreateJournalHeader($mysqli, "Medical Revenue", date('Y-m-d'), $session_user_id);
        
        // Create main journal entry
        $entry_number = "MED-" . date('Ym') . "-" . str_pad($payment_id, 5, '0', STR_PAD_LEFT);
        $entry_description = "Medical revenue from Invoice #" . $invoice_number . " - " . $patient_name;
        
        $entry_sql = "INSERT INTO journal_entries SET 
                      journal_header_id = ?,
                      entry_number = ?,
                      entry_date = CURDATE(),
                      entry_description = ?,
                      reference_number = ?,
                      entry_type = 'payment',
                      source_document = 'payment_' . ?,
                      amount = ?,
                      created_by = ?,
                      created_at = NOW()";
        
        $entry_stmt = mysqli_prepare($mysqli, $entry_sql);
        $ref_number = "INV-" . $invoice_number;
        mysqli_stmt_bind_param($entry_stmt, 'issssdi', 
            $journal_header_id, $entry_number, $entry_description, 
            $ref_number, $payment_id, $payment_amount, $session_user_id
        );
        
        if (!mysqli_stmt_execute($entry_stmt)) {
            throw new Exception("Failed to create journal entry: " . mysqli_stmt_error($entry_stmt));
        }
        
        $entry_id = mysqli_insert_id($mysqli);
        mysqli_stmt_close($entry_stmt);
        
        // Create detailed journal entry lines
        $lines = [];
        $total_debits = 0;
        $total_credits = 0;
        
        // Line 1: Debit to Cash/Accounts Receivable (total payment)
        $lines[] = [
            'entry_id' => $entry_id,
            'account_id' => $debit_account_id,
            'entry_type' => 'debit',
            'amount' => $payment_amount,
            'description' => "Payment received for Invoice #" . $invoice_number,
            'reference' => "PAY-" . $payment_id,
            'created_by' => $session_user_id
        ];
        $total_debits += $payment_amount;
        
        // Process each service item
        $inventory_services = [];
        foreach ($invoice_items as $item) {
            $service_id = $item['service_id'];
            $item_total = $item['item_total'];
            $item_quantity = $item['item_quantity'];
            
            // Get revenue account for this service
            $revenue_account_id = getRevenueAccountForService($mysqli, $item['item_id']);
            
            // Line: Credit to Revenue account
            $lines[] = [
                'entry_id' => $entry_id,
                'account_id' => $revenue_account_id,
                'entry_type' => 'credit',
                'amount' => $item_total,
                'description' => $item['item_name'] . " (Qty: $item_quantity) - Invoice #" . $invoice_number,
                'reference' => "INV-" . $invoice_number,
                'created_by' => $session_user_id
            ];
            $total_credits += $item_total;
            
            // Check if service consumes inventory
            $inventory_check_sql = "SELECT COUNT(*) as has_inventory 
                                   FROM service_inventory_items 
                                   WHERE medical_service_id = ?";
            $check_stmt = mysqli_prepare($mysqli, $inventory_check_sql);
            mysqli_stmt_bind_param($check_stmt, 'i', $service_id);
            mysqli_stmt_execute($check_stmt);
            $check_result = mysqli_stmt_get_result($check_stmt);
            $has_inventory = mysqli_fetch_assoc($check_result)['has_inventory'];
            mysqli_stmt_close($check_stmt);
            
            if ($has_inventory > 0) {
                $inventory_services[] = [
                    'service_id' => $service_id,
                    'service_name' => $item['item_name'],
                    'quantity' => $item_quantity
                ];
            }
        }
        
        // Process COGS and inventory for services that use inventory
        foreach ($inventory_services as $inv_service) {
            $service_id = $inv_service['service_id'];
            $item_quantity = $inv_service['quantity'];
            
            // Get COGS account for this service
            $cogs_account_id = getCOGSAccountForService($mysqli, $service_id);
            
            // Get average cost of inventory items used
            $cost_sql = "SELECT SUM(ii.average_cost * si.quantity_required) as total_cost
                        FROM service_inventory_items si
                        JOIN inventory_items ii ON si.item_id = ii.item_id
                        WHERE si.medical_service_id = ?";
            $cost_stmt = mysqli_prepare($mysqli, $cost_sql);
            mysqli_stmt_bind_param($cost_stmt, 'i', $service_id);
            mysqli_stmt_execute($cost_stmt);
            $cost_result = mysqli_stmt_get_result($cost_stmt);
            $cost_data = mysqli_fetch_assoc($cost_result);
            mysqli_stmt_close($cost_stmt);
            
            $cogs_amount = ($cost_data['total_cost'] ?? 0) * $item_quantity;
            
            if ($cogs_amount > 0) {
                // Line: Debit to COGS account
                $lines[] = [
                    'entry_id' => $entry_id,
                    'account_id' => $cogs_account_id,
                    'entry_type' => 'debit',
                    'amount' => $cogs_amount,
                    'description' => "COGS for " . $inv_service['service_name'] . " (Qty: $item_quantity)",
                    'reference' => "INV-" . $invoice_number,
                    'created_by' => $session_user_id
                ];
                $total_debits += $cogs_amount;
                
                // Line: Credit to Inventory account
                $inventory_account_id = getInventoryAccountForItem($mysqli, $service_id);
                $lines[] = [
                    'entry_id' => $entry_id,
                    'account_id' => $inventory_account_id,
                    'entry_type' => 'credit',
                    'amount' => $cogs_amount,
                    'description' => "Inventory used for " . $inv_service['service_name'],
                    'reference' => "INV-" . $invoice_number,
                    'created_by' => $session_user_id
                ];
                $total_credits += $cogs_amount;
            }
        }
        
        // Verify accounting equation: Debits = Credits
        if (abs($total_debits - $total_credits) > 0.01) {
            throw new Exception("Accounting equation imbalance: Debits (KSH " . number_format($total_debits, 2) . 
                              ") ≠ Credits (KSH " . number_format($total_credits, 2) . ")");
        }
        
        // Insert all journal entry lines
        foreach ($lines as $line) {
            $line_sql = "INSERT INTO journal_entry_lines SET 
                         entry_id = ?,
                         account_id = ?,
                         entry_type = ?,
                         amount = ?,
                         description = ?,
                         reference = ?,
                         created_by = ?,
                         line_created_at = NOW(),
                         created_at = NOW()";
            
            $line_stmt = mysqli_prepare($mysqli, $line_sql);
            mysqli_stmt_bind_param($line_stmt, 'iisdssi',
                $line['entry_id'], $line['account_id'], $line['entry_type'],
                $line['amount'], $line['description'], $line['reference'], $line['created_by']
            );
            
            if (!mysqli_stmt_execute($line_stmt)) {
                throw new Exception("Failed to create journal entry line: " . mysqli_stmt_error($line_stmt));
            }
            
            mysqli_stmt_close($line_stmt);
        }
        
        // Update account balances
        updateAccountBalancesForPayment($mysqli, $debit_account_id, $invoice_items, $payment_amount);
        
        // Update inventory quantities for services that use inventory
        foreach ($inventory_services as $inv_service) {
            try {
                updateInventoryForService($mysqli, $inv_service['service_id'], $inv_service['quantity'], 'reduce', $session_user_id);
            } catch (Exception $e) {
                // Log inventory error but continue with accounting
                error_log("Inventory update error for service {$inv_service['service_id']}: " . $e->getMessage());
            }
        }
        
        // Commit transaction
        mysqli_commit($mysqli);
        
        return $entry_id;
        
    } catch (Exception $e) {
        mysqli_rollback($mysqli);
        throw $e;
    }
}

function updateAccountBalancesForPayment($mysqli, $debit_account_id, $invoice_items, $total_amount) {
    // Update debit account (Cash or Accounts Receivable)
    $debit_sql = "UPDATE accounts SET 
                current_balance = current_balance + ?,
                updated_at = NOW()
                WHERE account_id = ?";
    $debit_stmt = mysqli_prepare($mysqli, $debit_sql);
    mysqli_stmt_bind_param($debit_stmt, 'di', $total_amount, $debit_account_id);
    mysqli_stmt_execute($debit_stmt);
    mysqli_stmt_close($debit_stmt);
    
    // Group items by revenue account
    $revenue_totals = [];
    $cogs_totals = [];
    $inventory_totals = [];
    
    foreach ($invoice_items as $item) {
        $revenue_account_id = getRevenueAccountForService($mysqli, $item['item_id']);
        
        if (!isset($revenue_totals[$revenue_account_id])) {
            $revenue_totals[$revenue_account_id] = 0;
        }
        
        $revenue_totals[$revenue_account_id] += $item['item_total'];
        
        // Check for COGS
        $service_id = $item['service_id'];
        
        // Check if service consumes inventory
        $inventory_check_sql = "SELECT COUNT(*) as has_inventory 
                               FROM service_inventory_items 
                               WHERE medical_service_id = ?";
        $check_stmt = mysqli_prepare($mysqli, $inventory_check_sql);
        mysqli_stmt_bind_param($check_stmt, 'i', $service_id);
        mysqli_stmt_execute($check_stmt);
        $check_result = mysqli_stmt_get_result($check_stmt);
        $has_inventory = mysqli_fetch_assoc($check_result)['has_inventory'];
        mysqli_stmt_close($check_stmt);
        
        if ($has_inventory > 0) {
            $cogs_account_id = getCOGSAccountForService($mysqli, $service_id);
            
            // Calculate COGS amount
            $cost_sql = "SELECT SUM(ii.average_cost * si.quantity_required) as total_cost
                        FROM service_inventory_items si
                        JOIN inventory_items ii ON si.item_id = ii.item_id
                        WHERE si.medical_service_id = ?";
            $cost_stmt = mysqli_prepare($mysqli, $cost_sql);
            mysqli_stmt_bind_param($cost_stmt, 'i', $service_id);
            mysqli_stmt_execute($cost_stmt);
            $cost_result = mysqli_stmt_get_result($cost_stmt);
            $cost_data = mysqli_fetch_assoc($cost_result);
            mysqli_stmt_close($cost_stmt);
            
            $cogs_amount = ($cost_data['total_cost'] ?? 0) * $item['item_quantity'];
            
            if ($cogs_amount > 0) {
                if (!isset($cogs_totals[$cogs_account_id])) {
                    $cogs_totals[$cogs_account_id] = 0;
                }
                $cogs_totals[$cogs_account_id] += $cogs_amount;
                
                // Update inventory account
                $inventory_account_id = getInventoryAccountForItem($mysqli, $service_id);
                if (!isset($inventory_totals[$inventory_account_id])) {
                    $inventory_totals[$inventory_account_id] = 0;
                }
                $inventory_totals[$inventory_account_id] += $cogs_amount;
            }
        }
    }
    
    // Update revenue accounts
    foreach ($revenue_totals as $account_id => $amount) {
        if ($amount > 0) {
            $credit_sql = "UPDATE accounts SET 
                          current_balance = current_balance + ?,
                          updated_at = NOW()
                          WHERE account_id = ?";
            $credit_stmt = mysqli_prepare($mysqli, $credit_sql);
            mysqli_stmt_bind_param($credit_stmt, 'di', $amount, $account_id);
            mysqli_stmt_execute($credit_stmt);
            mysqli_stmt_close($credit_stmt);
        }
    }
    
    // Update COGS accounts
    foreach ($cogs_totals as $account_id => $amount) {
        if ($amount > 0) {
            $cogs_sql = "UPDATE accounts SET 
                        current_balance = current_balance + ?,
                        updated_at = NOW()
                        WHERE account_id = ?";
            $cogs_stmt = mysqli_prepare($mysqli, $cogs_sql);
            mysqli_stmt_bind_param($cogs_stmt, 'di', $amount, $account_id);
            mysqli_stmt_execute($cogs_stmt);
            mysqli_stmt_close($cogs_stmt);
        }
    }
    
    // Update inventory accounts (reduce inventory)
    foreach ($inventory_totals as $account_id => $amount) {
        if ($amount > 0) {
            $inventory_sql = "UPDATE accounts SET 
                             current_balance = current_balance - ?,
                             updated_at = NOW()
                             WHERE account_id = ?";
            $inventory_stmt = mysqli_prepare($mysqli, $inventory_sql);
            mysqli_stmt_bind_param($inventory_stmt, 'di', $amount, $account_id);
            mysqli_stmt_execute($inventory_stmt);
            mysqli_stmt_close($inventory_stmt);
        }
    }
}

// ========================
// INVOICE CLOSING CONTROL
// ========================

function canCloseInvoice($mysqli, $invoice_id) {
    // 1. Get visit information + count invoice items
    $sql = "SELECT i.visit_id,
                   (SELECT COUNT(*) FROM invoice_items WHERE item_invoice_id = ?) AS item_count
            FROM invoices i
            WHERE i.invoice_id = ?";
    
    $stmt = mysqli_prepare($mysqli, $sql);
    mysqli_stmt_bind_param($stmt, 'ii', $invoice_id, $invoice_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $data = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    if (!$data) {
        return false; // invoice not found
    }

    $visit_id = $data['visit_id'];

    // CASE 1 — Invoice is NOT linked to a visit
    if (!$visit_id) {
        return ($data['item_count'] > 0);  
    }

    // CASE 2 — Invoice IS linked to a visit → Check if visit is discharged
    $visit_sql = "SELECT visit_status, visit_discharge_date
                  FROM visits
                  WHERE visit_id = ?";
    $vstmt = mysqli_prepare($mysqli, $visit_sql);
    mysqli_stmt_bind_param($vstmt, 'i', $visit_id);
    mysqli_stmt_execute($vstmt);
    $visit_res = mysqli_stmt_get_result($vstmt);
    $visit = mysqli_fetch_assoc($visit_res);
    mysqli_stmt_close($vstmt);

    if (!$visit) {
        return false;
    }

    $visit_discharged = (
        $visit['visit_status'] === 'discharged' &&
        !empty($visit['visit_discharge_date'])
    );

    if (!$visit_discharged) {
        return false; // cannot close invoice if visit isn't discharged
    }

    // CASE 3 — Check for unbilled LAB orders
    $lab_sql = "SELECT COUNT(*) AS cnt
                FROM lab_orders
                WHERE visit_id = ?
                  AND (is_billed = 0 OR invoice_id IS NULL)";
    $labstmt = mysqli_prepare($mysqli, $lab_sql);
    mysqli_stmt_bind_param($labstmt, 'i', $visit_id);
    mysqli_stmt_execute($labstmt);
    $lab_res = mysqli_stmt_get_result($labstmt);
    $lab = mysqli_fetch_assoc($lab_res)['cnt'];
    mysqli_stmt_close($labstmt);

    // CASE 4 — Check for unbilled PRESCRIPTIONS
    $rx_sql = "SELECT COUNT(*) AS cnt
               FROM prescriptions
               WHERE prescription_visit_id = ?
                 AND (is_billed = 0 OR invoice_id IS NULL)";
    $rxstmt = mysqli_prepare($mysqli, $rx_sql);
    mysqli_stmt_bind_param($rxstmt, 'i', $visit_id);
    mysqli_stmt_execute($rxstmt);
    $rx_res = mysqli_stmt_get_result($rxstmt);
    $rx = mysqli_fetch_assoc($rx_res)['cnt'];
    mysqli_stmt_close($rxstmt);

    // CASE 5 — Check for unbilled RADIOLOGY ORDERS
    $rad_sql = "SELECT COUNT(*) AS cnt
                FROM radiology_orders
                WHERE visit_id = ?
                  AND (is_billed = 0 OR invoice_id IS NULL)";
    $radstmt = mysqli_prepare($mysqli, $rad_sql);
    mysqli_stmt_bind_param($radstmt, 'i', $visit_id);
    mysqli_stmt_execute($radstmt);
    $rad_res = mysqli_stmt_get_result($radstmt);
    $rad = mysqli_fetch_assoc($rad_res)['cnt'];
    mysqli_stmt_close($radstmt);

    // If ANY unbilled items exist → cannot close invoice
    $any_unbilled = ($lab > 0 || $rx > 0 || $rad > 0);

    return !$any_unbilled;
}

function closeInvoiceManually($mysqli, $invoice_id, $user_id) {
    // Start transaction
    mysqli_begin_transaction($mysqli);
    
    try {
        // Check if invoice can be closed
        if (!canCloseInvoice($mysqli, $invoice_id)) {
            throw new Exception("Cannot close invoice. Visit not fully discharged or billing not complete.");
        }
        
        // Get invoice details
        $invoice_sql = "SELECT * FROM invoices WHERE invoice_id = ?";
        $invoice_stmt = mysqli_prepare($mysqli, $invoice_sql);
        mysqli_stmt_bind_param($invoice_stmt, 'i', $invoice_id);
        mysqli_stmt_execute($invoice_stmt);
        $invoice_result = mysqli_stmt_get_result($invoice_stmt);
        $invoice = mysqli_fetch_assoc($invoice_result);
        mysqli_stmt_close($invoice_stmt);
        
        if (!$invoice) {
            throw new Exception("Invoice not found");
        }
        
        // Check if invoice is fully paid
        $is_fully_paid = ($invoice['paid_amount'] >= $invoice['invoice_amount']);
        
        if (!$is_fully_paid) {
            throw new Exception("Invoice must be fully paid before closing");
        }
        
        // Update invoice status to closed
        $update_sql = "UPDATE invoices SET 
                      invoice_status = 'closed',
                      closed_by = ?,
                      closed_at = NOW(),
                      invoice_updated_at = NOW()
                      WHERE invoice_id = ?";
        $update_stmt = mysqli_prepare($mysqli, $update_sql);
        mysqli_stmt_bind_param($update_stmt, 'ii', $user_id, $invoice_id);
        
        if (!mysqli_stmt_execute($update_stmt)) {
            throw new Exception("Failed to close invoice: " . mysqli_stmt_error($update_stmt));
        }
        mysqli_stmt_close($update_stmt);
        
        // Commit transaction
        mysqli_commit($mysqli);
        
        return true;
        
    } catch (Exception $e) {
        mysqli_rollback($mysqli);
        throw $e;
    }
}

// ========================
// PAYMENT PROCESSING
// ========================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (isset($_POST['process_payment'])) {
        // Validate CSRF
        if (!isset($_SESSION['csrf_token']) || $csrf_token !== $_SESSION['csrf_token']) {
            flash_alert("Invalid CSRF token", 'error');
            header("Location: $current_page?invoice_id=" . ($_POST['invoice_id'] ?? 0));
            exit;
        }

        // Get form data
        $invoice_id = intval($_POST['invoice_id'] ?? 0);
        $payment_method = sanitizeInput($_POST['payment_method'] ?? '');
        $payment_amount = floatval($_POST['payment_amount'] ?? 0);
        $payment_date = sanitizeInput($_POST['payment_date'] ?? date('Y-m-d H:i:s'));
        $payment_notes = sanitizeInput($_POST['payment_notes'] ?? '');
        $register_id = intval($_POST['register_id'] ?? 0);
        $allocation_method = sanitizeInput($_POST['allocation_method'] ?? 'full');
        
        // Payment method specific data
        $insurance_company_id = intval($_POST['insurance_company_id'] ?? 0);
        $insurance_claim_number = sanitizeInput($_POST['insurance_claim_number'] ?? '');
        $insurance_approval_code = sanitizeInput($_POST['insurance_approval_code'] ?? '');
        
        $mpesa_phone = sanitizeInput($_POST['mpesa_phone'] ?? '');
        $mpesa_transaction_id = sanitizeInput($_POST['mpesa_transaction_id'] ?? '');
        
        $card_last_four = sanitizeInput($_POST['card_last_four'] ?? '');
        $card_type = sanitizeInput($_POST['card_type'] ?? '');
        $card_authorization_code = sanitizeInput($_POST['card_authorization_code'] ?? '');

        // Validate required fields
        if (empty($invoice_id) || empty($payment_method) || $payment_amount <= 0) {
            flash_alert("Please fill in all required payment details", 'error');
            header("Location: $current_page?invoice_id=$invoice_id");
            exit;
        }

        // Start transaction
        mysqli_begin_transaction($mysqli);

        try {
            // Get invoice details
            $invoice_sql = "SELECT i.*, p.patient_id, CONCAT(p.patient_first_name, ' ', p.patient_last_name) as patient_name,
                                   i.invoice_amount - IFNULL(i.paid_amount, 0) as remaining_balance
                            FROM invoices i
                            LEFT JOIN patients p ON i.invoice_client_id = p.patient_id
                            WHERE i.invoice_id = ?";
            $invoice_stmt = mysqli_prepare($mysqli, $invoice_sql);
            mysqli_stmt_bind_param($invoice_stmt, 'i', $invoice_id);
            mysqli_stmt_execute($invoice_stmt);
            $invoice_result = mysqli_stmt_get_result($invoice_stmt);
            $invoice = mysqli_fetch_assoc($invoice_result);
            mysqli_stmt_close($invoice_stmt);

            if (!$invoice) {
                throw new Exception("Invoice not found");
            }

            // Get invoice items for accounting
            $items_sql = "SELECT * FROM invoice_items WHERE item_invoice_id = ?";
            $items_stmt = mysqli_prepare($mysqli, $items_sql);
            mysqli_stmt_bind_param($items_stmt, 'i', $invoice_id);
            mysqli_stmt_execute($items_stmt);
            $items_result = mysqli_stmt_get_result($items_stmt);
            $invoice_items = [];
            while ($item = mysqli_fetch_assoc($items_result)) {
                $invoice_items[] = $item;
            }
            mysqli_stmt_close($items_stmt);

            if (empty($invoice_items)) {
                throw new Exception("Invoice has no items");
            }

            // Validate payment amount
            if ($payment_amount > $invoice['remaining_balance']) {
                throw new Exception("Payment amount exceeds remaining balance of KSH " . number_format($invoice['remaining_balance'], 2));
            }

            // Generate payment number
            $payment_number = generatePaymentNumber($mysqli);

            // Insert payment record
            $payment_sql = "INSERT INTO payments SET 
                           payment_number = ?,
                           payment_invoice_id = ?,
                           payment_amount = ?,
                           payment_method = ?,
                           payment_date = ?,
                           payment_notes = ?,
                           payment_received_by = ?,
                           register_id = ?,
                           allocation_method = ?,
                           payment_status = 'completed',
                           accounting_status = 'pending',
                           created_at = NOW(),
                           payment_created_by = ?";
            
            $payment_stmt = mysqli_prepare($mysqli, $payment_sql);
            mysqli_stmt_bind_param($payment_stmt, 'siddssiisi', 
                $payment_number, $invoice_id, $payment_amount, $payment_method, 
                $payment_date, $payment_notes, $session_user_id, $register_id, 
                $allocation_method, $session_user_id
            );
            
            if (!mysqli_stmt_execute($payment_stmt)) {
                throw new Exception("Failed to create payment record: " . mysqli_stmt_error($payment_stmt));
            }
            
            $payment_id = mysqli_insert_id($mysqli);
            mysqli_stmt_close($payment_stmt);

            // Insert payment method details
            if ($payment_method === 'insurance' && $insurance_company_id) {
                $insurance_company_sql = "SELECT company_name FROM insurance_companies WHERE insurance_company_id = ?";
                $insurance_company_stmt = mysqli_prepare($mysqli, $insurance_company_sql);
                mysqli_stmt_bind_param($insurance_company_stmt, 'i', $insurance_company_id);
                mysqli_stmt_execute($insurance_company_stmt);
                $insurance_company_result = mysqli_stmt_get_result($insurance_company_stmt);
                $insurance_company = mysqli_fetch_assoc($insurance_company_result);
                $insurance_provider_name = $insurance_company['company_name'] ?? '';
                mysqli_stmt_close($insurance_company_stmt);
                
                $insurance_sql = "INSERT INTO payment_insurance_details SET 
                                 payment_id = ?,
                                 insurance_provider = ?,
                                 claim_number = ?,
                                 approval_code = ?";
                $insurance_stmt = mysqli_prepare($mysqli, $insurance_sql);
                mysqli_stmt_bind_param($insurance_stmt, 'isss', 
                    $payment_id, $insurance_provider_name, $insurance_claim_number, $insurance_approval_code
                );
                mysqli_stmt_execute($insurance_stmt);
                mysqli_stmt_close($insurance_stmt);
            } elseif ($payment_method === 'mpesa_stk') {
                $mpesa_sql = "INSERT INTO payment_mpesa_details SET 
                             payment_id = ?,
                             phone_number = ?,
                             reference_number = ?";
                $mpesa_stmt = mysqli_prepare($mysqli, $mpesa_sql);
                mysqli_stmt_bind_param($mpesa_stmt, 'iss', 
                    $payment_id, $mpesa_phone, $mpesa_transaction_id
                );
                mysqli_stmt_execute($mpesa_stmt);
                mysqli_stmt_close($mpesa_stmt);
            } elseif ($payment_method === 'card') {
                $card_sql = "INSERT INTO payment_card_details SET 
                            payment_id = ?,
                            card_type = ?,
                            card_last_four = ?,
                            auth_code = ?";
                $card_stmt = mysqli_prepare($mysqli, $card_sql);
                mysqli_stmt_bind_param($card_stmt, 'isss', 
                    $payment_id, $card_type, $card_last_four, $card_authorization_code
                );
                mysqli_stmt_execute($card_stmt);
                mysqli_stmt_close($card_stmt);
            }

            // Update invoice items payment status
            updateInvoiceItemsPayment($mysqli, $invoice_id, $payment_amount, $allocation_method, $_POST['item_payments'] ?? []);

            // ========================
            // CREATE COMPLETE ACCOUNTING ENTRY WITH INVENTORY TRACKING
            // ========================
            $journal_entry_id = null;
            
            try {
                $journal_entry_id = createCompleteMedicalJournalEntry(
                    $mysqli, 
                    $payment_id, 
                    $invoice_id, 
                    $payment_amount, 
                    $payment_method, 
                    $session_user_id,
                    $invoice['invoice_number'],
                    $invoice['patient_name'],
                    $invoice_items
                );
                
                // Link payment to journal entry
                $update_payment_sql = "UPDATE payments SET 
                                      journal_entry_id = ?,
                                      accounting_status = 'posted'
                                      WHERE payment_id = ?";
                $update_payment_stmt = mysqli_prepare($mysqli, $update_payment_sql);
                mysqli_stmt_bind_param($update_payment_stmt, 'ii', $journal_entry_id, $payment_id);
                mysqli_stmt_execute($update_payment_stmt);
                mysqli_stmt_close($update_payment_stmt);
                
                error_log("Accounting: Created complete journal entry #$journal_entry_id for payment #$payment_id");
                
            } catch (Exception $e) {
                error_log("Accounting ERROR for payment $payment_id: " . $e->getMessage());
                // Set accounting status to failed but continue with payment
                $failed_sql = "UPDATE payments SET accounting_status = 'failed', accounting_error = ? WHERE payment_id = ?";
                $failed_stmt = mysqli_prepare($mysqli, $failed_sql);
                $error_msg = substr($e->getMessage(), 0, 255);
                mysqli_stmt_bind_param($failed_stmt, 'si', $error_msg, $payment_id);
                mysqli_stmt_execute($failed_stmt);
                mysqli_stmt_close($failed_stmt);
                
                // Re-throw only if it's a critical error
                if (strpos($e->getMessage(), 'Insufficient inventory') !== false) {
                    throw $e; // Critical: Stop payment if inventory insufficient
                }
            }

            // Update invoice
            $current_paid_amount = $invoice['paid_amount'] ?? 0;
            $new_paid_amount = $current_paid_amount + $payment_amount;
            $invoice_amount = $invoice['invoice_amount'];
            
            // Only update status to partially_paid or unpaid, never to 'paid' automatically
            $new_status = ($new_paid_amount > 0) ? 'partially_paid' : 'unpaid';

            $invoice_update_sql = "UPDATE invoices SET 
                                  paid_amount = ?,
                                  invoice_status = ?,
                                  invoice_updated_at = NOW()
                                  WHERE invoice_id = ?";
            $invoice_update_stmt = mysqli_prepare($mysqli, $invoice_update_sql);
            mysqli_stmt_bind_param($invoice_update_stmt, 'dsi', $new_paid_amount, $new_status, $invoice_id);
            
            if (!mysqli_stmt_execute($invoice_update_stmt)) {
                throw new Exception("Failed to update invoice: " . mysqli_stmt_error($invoice_update_stmt));
            }
            
            mysqli_stmt_close($invoice_update_stmt);

            // Commit transaction
            mysqli_commit($mysqli);

            // Success
            $alert_message = "✅ Payment processed successfully! Receipt #: " . $payment_number;
            if ($journal_entry_id) {
                $alert_message .= " (Accounting entry #$journal_entry_id posted successfully)";
            } else {
                $alert_message .= " (Accounting entry pending - check logs)";
            }
            
            // Add note about invoice status
            if ($new_paid_amount >= $invoice_amount) {
                $alert_message .= "\n\n📋 Invoice is now fully paid. Please close the invoice manually after billing discharges and visit completion.";
            }
            
            flash_alert($alert_message, 'success');
            header("Location: payment_receipt.php?payment_id=" . $payment_id);
            exit;

        } catch (Exception $e) {
            mysqli_rollback($mysqli);
            flash_alert("❌ Payment Error: " . $e->getMessage(), 'error');
            header("Location: $current_page?invoice_id=$invoice_id");
            exit;
        }
    }
    
    // Handle manual invoice closing
    if (isset($_POST['close_invoice'])) {
        // Validate CSRF
        if (!isset($_SESSION['csrf_token']) || $csrf_token !== $_SESSION['csrf_token']) {
            flash_alert("Invalid CSRF token", 'error');
            header("Location: $current_page?invoice_id=" . ($_POST['invoice_id'] ?? 0));
            exit;
        }
        
        $invoice_id = intval($_POST['invoice_id'] ?? 0);
        
        // Get invoice details for messaging
        $invoice_sql = "SELECT * FROM invoices WHERE invoice_id = ?";
        $invoice_stmt = mysqli_prepare($mysqli, $invoice_sql);
        mysqli_stmt_bind_param($invoice_stmt, 'i', $invoice_id);
        mysqli_stmt_execute($invoice_stmt);
        $invoice_result = mysqli_stmt_get_result($invoice_stmt);
        $invoice = mysqli_fetch_assoc($invoice_result);
        mysqli_stmt_close($invoice_stmt);
        
        try {
            closeInvoiceManually($mysqli, $invoice_id, $session_user_id);
            flash_alert("✅ Invoice #" . $invoice['invoice_number'] . " has been successfully closed!", 'success');
            header("Location: $current_page?invoice_id=$invoice_id");
            exit;
        } catch (Exception $e) {
            flash_alert("❌ Failed to close invoice: " . $e->getMessage(), 'error');
            header("Location: $current_page?invoice_id=$invoice_id");
            exit;
        }
    }
}

function updateInvoiceItemsPayment($mysqli, $invoice_id, $payment_amount, $allocation_method, $item_payments) {
    // Reset all items first
    $reset_sql = "UPDATE invoice_items SET 
                 item_amount_paid = 0,
                 item_payment_status = 'unpaid'
                 WHERE item_invoice_id = ?";
    $reset_stmt = mysqli_prepare($mysqli, $reset_sql);
    mysqli_stmt_bind_param($reset_stmt, 'i', $invoice_id);
    mysqli_stmt_execute($reset_stmt);
    mysqli_stmt_close($reset_stmt);
    
    if ($allocation_method === 'specific_items' && !empty($item_payments)) {
        // Apply specific amounts
        foreach ($item_payments as $item_id => $amount) {
            $item_id = intval($item_id);
            $amount = floatval($amount);
            
            if ($amount > 0) {
                $item_sql = "SELECT item_total FROM invoice_items WHERE item_id = ?";
                $item_stmt = mysqli_prepare($mysqli, $item_sql);
                mysqli_stmt_bind_param($item_stmt, 'i', $item_id);
                mysqli_stmt_execute($item_stmt);
                $item_result = mysqli_stmt_get_result($item_stmt);
                $item = mysqli_fetch_assoc($item_result);
                mysqli_stmt_close($item_stmt);
                
                if ($item) {
                    $item_status = ($amount >= $item['item_total']) ? 'paid' : 'partial';
                    
                    $update_sql = "UPDATE invoice_items SET 
                                  item_amount_paid = ?,
                                  item_payment_status = ?
                                  WHERE item_id = ?";
                    $update_stmt = mysqli_prepare($mysqli, $update_sql);
                    mysqli_stmt_bind_param($update_stmt, 'dsi', $amount, $item_status, $item_id);
                    mysqli_stmt_execute($update_stmt);
                    mysqli_stmt_close($update_stmt);
                }
            }
        }
    } else {
        // Apply proportionally
        $items_sql = "SELECT item_id, item_total FROM invoice_items WHERE item_invoice_id = ?";
        $items_stmt = mysqli_prepare($mysqli, $items_sql);
        mysqli_stmt_bind_param($items_stmt, 'i', $invoice_id);
        mysqli_stmt_execute($items_stmt);
        $items_result = mysqli_stmt_get_result($items_stmt);
        $items = [];
        $total_amount = 0;
        
        while ($item = mysqli_fetch_assoc($items_result)) {
            $items[] = $item;
            $total_amount += $item['item_total'];
        }
        mysqli_stmt_close($items_stmt);
        
        foreach ($items as $item) {
            $proportion = $item['item_total'] / $total_amount;
            $item_payment = $payment_amount * $proportion;
            
            $item_status = ($item_payment >= $item['item_total']) ? 'paid' : 'partial';
            
            $update_sql = "UPDATE invoice_items SET 
                          item_amount_paid = ?,
                          item_payment_status = ?
                          WHERE item_id = ?";
            $update_stmt = mysqli_prepare($mysqli, $update_sql);
            mysqli_stmt_bind_param($update_stmt, 'dsi', $item_payment, $item_status, $item['item_id']);
            mysqli_stmt_execute($update_stmt);
            mysqli_stmt_close($update_stmt);
        }
    }
}

// ========================
// DISPLAY LOGIC
// ========================

$invoice_id = intval($_GET['invoice_id'] ?? 0);

if (!$invoice_id) {
    flash_alert("No invoice specified", 'error');
    header("Location: billing_dashboard.php");
    exit;
}

// Get invoice details
$invoice_sql = "SELECT i.*, p.patient_id, CONCAT(p.patient_first_name, ' ', p.patient_last_name) as patient_name,
                       p.patient_phone, p.patient_email, p.patient_mrn, p.patient_dob,
                       i.invoice_amount - IFNULL(i.paid_amount, 0) as remaining_balance,
                       IFNULL(i.paid_amount, 0) as paid_amount,
                       (SELECT COUNT(*) FROM payments WHERE payment_invoice_id = i.invoice_id AND payment_status = 'completed') as payment_count,
                       v.visit_status, v.visit_discharge_date
                FROM invoices i
                LEFT JOIN patients p ON i.invoice_client_id = p.patient_id
                LEFT JOIN visits v ON i.visit_id = v.visit_id
                WHERE i.invoice_id = ?";
$invoice_stmt = mysqli_prepare($mysqli, $invoice_sql);
mysqli_stmt_bind_param($invoice_stmt, 'i', $invoice_id);
mysqli_stmt_execute($invoice_stmt);
$invoice_result = mysqli_stmt_get_result($invoice_stmt);
$invoice = mysqli_fetch_assoc($invoice_result);
mysqli_stmt_close($invoice_stmt);

if (!$invoice) {
    flash_alert("Invoice not found", 'error');
    header("Location: billing_dashboard.php");
    exit;
}

// Set invoice status if not set
if (empty($invoice['invoice_status'])) {
    if ($invoice['paid_amount'] >= $invoice['invoice_amount']) {
        $invoice['invoice_status'] = 'partially_paid'; // Changed from 'paid' to 'partially_paid'
    } elseif ($invoice['paid_amount'] > 0) {
        $invoice['invoice_status'] = 'partially_paid';
    } else {
        $invoice['invoice_status'] = 'unpaid';
    }
}

// Get invoice items, linking to medical_services table
$items_sql = "SELECT ii.*, ms.service_name, ms.service_category_id,
                     ii.item_total - ii.item_amount_paid as item_due,
                     CASE 
                         WHEN ii.item_amount_paid >= ii.item_total THEN 'paid'
                         WHEN ii.item_amount_paid > 0 THEN 'partial' 
                         ELSE 'unpaid'
                     END as calculated_status
              FROM invoice_items ii
              LEFT JOIN medical_services ms ON ii.service_id = ms.medical_service_id
              WHERE ii.item_invoice_id = ? 
              ORDER BY ii.item_id";

$items_stmt = mysqli_prepare($mysqli, $items_sql);
mysqli_stmt_bind_param($items_stmt, 'i', $invoice_id);
mysqli_stmt_execute($items_stmt);
$items_result = mysqli_stmt_get_result($items_stmt);
$invoice_items = [];

while ($item = mysqli_fetch_assoc($items_result)) {
    if (empty($item['item_payment_status'])) {
        $item['item_payment_status'] = $item['calculated_status'];
    }
    $invoice_items[] = $item;
}

mysqli_stmt_close($items_stmt);

// Get payment history
$payments_sql = "SELECT p.*, u.user_name as received_by 
                 FROM payments p
                 LEFT JOIN users u ON p.payment_received_by = u.user_id
                 WHERE p.payment_invoice_id = ? 
                 ORDER BY p.payment_date DESC";
$payments_stmt = mysqli_prepare($mysqli, $payments_sql);
mysqli_stmt_bind_param($payments_stmt, 'i', $invoice_id);
mysqli_stmt_execute($payments_stmt);
$payments_result = mysqli_stmt_get_result($payments_stmt);
$payments = [];
while ($payment = mysqli_fetch_assoc($payments_result)) {
    $payments[] = $payment;
}
mysqli_stmt_close($payments_stmt);

// Get insurance companies
$insurance_sql = "SELECT * FROM insurance_companies WHERE is_active = 1 ORDER BY company_name";
$insurance_result = mysqli_query($mysqli, $insurance_sql);

// Get cash registers
$registers_sql = "SELECT * FROM cash_register WHERE status = 'open' ORDER BY register_name";
$registers_result = mysqli_query($mysqli, $registers_sql);

// Check if invoice can be closed
$can_close_invoice = false;
$fully_paid = false;

if ($invoice) {
    $fully_paid = ($invoice['paid_amount'] >= $invoice['invoice_amount']);
    $can_close_invoice = canCloseInvoice($mysqli, $invoice_id) && $fully_paid;
}

// Check accounting status for invoice
$accounting_status_sql = "SELECT 
                          COUNT(*) as total_payments,
                          SUM(CASE WHEN accounting_status = 'posted' THEN 1 ELSE 0 END) as posted_payments,
                          SUM(CASE WHEN accounting_status = 'failed' THEN 1 ELSE 0 END) as failed_payments
                          FROM payments 
                          WHERE payment_invoice_id = ? AND payment_status = 'completed'";
$acc_stmt = mysqli_prepare($mysqli, $accounting_status_sql);
mysqli_stmt_bind_param($acc_stmt, 'i', $invoice_id);
mysqli_stmt_execute($acc_stmt);
$acc_result = mysqli_stmt_get_result($acc_stmt);
$accounting_status = mysqli_fetch_assoc($acc_result);
mysqli_stmt_close($acc_stmt);
?>

<div class="card">
    <div class="card-header bg-success py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-credit-card mr-2"></i>Process Payment
                <?php if($accounting_status['total_payments'] > 0): ?>
                    <span class="badge badge-light ml-2">
                        Accounting: 
                        <?php if($accounting_status['failed_payments'] > 0): ?>
                            <span class="text-danger"><?php echo $accounting_status['failed_payments']; ?> failed</span>
                        <?php else: ?>
                            <span class="text-success">All posted</span>
                        <?php endif; ?>
                    </span>
                <?php endif; ?>
            </h3>
            <div class="card-tools">
                <a href="billing_invoices.php" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Invoices
                </a>
            </div>
        </div>
    </div>

    <div class="card-body">
        <?php if (isset($_SESSION['alert_message'])): ?>
            <div class="alert alert-<?php echo $_SESSION['alert_type']; ?> alert-dismissible">
                <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
                <i class="icon fas fa-<?php echo $_SESSION['alert_type'] == 'success' ? 'check' : 
                                      ($_SESSION['alert_type'] == 'warning' ? 'exclamation-triangle' : 'exclamation-triangle'); ?>"></i>
                <?php echo $_SESSION['alert_message']; ?>
            </div>
            <?php 
            unset($_SESSION['alert_type']);
            unset($_SESSION['alert_message']);
            ?>
        <?php endif; ?>

        <?php if($invoice): ?>
        <!-- Process Payment for Specific Invoice -->
        <div class="row">
            <div class="col-md-8">
                <!-- Invoice Information -->
                <div class="card card-primary">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-file-invoice mr-2"></i>Invoice Information</h3>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold">Patient Name</label>
                                    <div class="form-control-plaintext">
                                        <?php echo htmlspecialchars($invoice['patient_name']); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold">Invoice Number</label>
                                    <div class="form-control-plaintext">
                                        <span class="badge badge-primary"><?php echo htmlspecialchars($invoice['invoice_number']); ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="font-weight-bold">Total Amount</label>
                                    <div class="form-control-plaintext font-weight-bold text-success">
                                        KSH <?php echo number_format($invoice['invoice_amount'], 2); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="font-weight-bold">Paid Amount</label>
                                    <div class="form-control-plaintext font-weight-bold text-info">
                                        KSH <?php echo number_format($invoice['paid_amount'], 2); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="font-weight-bold">Remaining Balance</label>
                                    <div class="form-control-plaintext font-weight-bold text-danger">
                                        KSH <?php echo number_format($invoice['remaining_balance'], 2); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold">Invoice Status</label>
                                    <div class="form-control-plaintext">
                                        <span class="badge badge-<?php 
                                            echo $invoice['invoice_status'] === 'closed' ? 'secondary' : 
                                                 ($invoice['invoice_status'] === 'partially_paid' ? 'warning' : 'danger'); 
                                        ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $invoice['invoice_status'])); ?>
                                            <?php if($fully_paid && $invoice['invoice_status'] !== 'closed'): ?>
                                                <i class="fas fa-check ml-1 text-success"></i>
                                            <?php endif; ?>
                                        </span>
                                        <?php if($fully_paid && $invoice['invoice_status'] !== 'closed'): ?>
                                            <small class="text-success ml-2"><i class="fas fa-info-circle"></i> Fully paid but not closed</small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold">Visit Status</label>
                                    <div class="form-control-plaintext">
                                        <?php if($invoice['visit_status']): ?>
                                            <span class="badge badge-<?php 
                                                echo $invoice['visit_status'] === 'discharged' ? 'success' : 
                                                     ($invoice['visit_status'] === 'admitted' ? 'primary' : 'warning'); 
                                            ?>">
                                                <?php echo ucfirst($invoice['visit_status']); ?>
                                                <?php if($invoice['visit_discharge_date']): ?>
                                                    <br><small>Discharged: <?php echo date('M j, Y', strtotime($invoice['visit_discharge_date'])); ?></small>
                                                <?php endif; ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge badge-secondary">No Visit Linked</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold">Patient Phone</label>
                                    <div class="form-control-plaintext">
                                        <?php echo htmlspecialchars($invoice['patient_phone']); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold">Payment Count</label>
                                    <div class="form-control-plaintext">
                                        <span class="badge badge-info"><?php echo $invoice['payment_count']; ?> payments</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Payment Form -->
                <div class="card card-warning mt-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-money-bill-wave mr-2"></i>Payment Details</h3>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="paymentForm" action="<?php echo $_SERVER['PHP_SELF']; ?>?invoice_id=<?php echo $invoice_id; ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="invoice_id" value="<?php echo $invoice_id; ?>">
                            
                            <!-- Allocation Method -->
                            <div class="form-group">
                                <label class="font-weight-bold">Payment Allocation Method</label>
                                <select class="form-control" name="allocation_method" id="allocation_method" required onchange="toggleAllocationMethod()">
                                    <option value="full">Full Payment (Auto-distribute)</option>
                                    <option value="partial">Partial Payment (Auto-distribute to oldest items)</option>
                                    <option value="specific_items" selected>Specific Items (Choose which items to pay)</option>
                                </select>
                            </div>

                            <!-- Invoice Items Allocation -->
                            <div class="allocation-section" id="item_allocation_section">
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle mr-2"></i>
                                    <strong>Invoice Items</strong> - Select which items to pay
                                    <?php 
                                    // Check for inventory items
                                    $inventory_items_count = 0;
                                    foreach ($invoice_items as $item) {
                                        if (!empty($item['service_id'])) {
                                            $inv_check_sql = "SELECT COUNT(*) as cnt FROM service_inventory_items WHERE medical_service_id = ?";
                                            $inv_stmt = mysqli_prepare($mysqli, $inv_check_sql);
                                            mysqli_stmt_bind_param($inv_stmt, 'i', $item['service_id']);
                                            mysqli_stmt_execute($inv_stmt);
                                            $inv_result = mysqli_stmt_get_result($inv_stmt);
                                            $inv_count = mysqli_fetch_assoc($inv_result)['cnt'];
                                            mysqli_stmt_close($inv_stmt);
                                            
                                            if ($inv_count > 0) {
                                                $inventory_items_count++;
                                            }
                                        }
                                    }
                                    if ($inventory_items_count > 0): ?>
                                        <span class="badge badge-light ml-2">
                                            <i class="fas fa-boxes mr-1"></i><?php echo $inventory_items_count; ?> item(s) consume inventory
                                        </span>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="select-all-section">
                                    <div class="form-check">
                                        <input type="checkbox" class="form-check-input" id="select_all_items" onclick="toggleSelectAllItems()">
                                        <label class="form-check-label font-weight-bold" for="select_all_items">
                                            Select All Items for Full Payment
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="table-responsive">
                                    <table class="table table-bordered">
                                        <thead class="bg-light">
                                            <tr>
                                                <th width="50" class="text-center">
                                                    <input type="checkbox" id="select_all_items_header" onclick="toggleSelectAllItems()">
                                                </th>
                                                <th>Item Description</th>
                                                <th class="text-center">Quantity</th>
                                                <th class="text-right">Unit Price</th>
                                                <th class="text-right">Total</th>
                                                <th class="text-right">Paid</th>
                                                <th class="text-right">Due</th>
                                                <th class="text-right">Payment Amount</th>
                                                <th class="text-center">Status</th>
                                                <th class="text-center">Inventory</th>
                                            </tr>
                                        </thead>
                                        <tbody id="item_allocation_tbody">
                                            <?php
                                            $total_due = 0;
                                            $can_pay = false;
                                            foreach ($invoice_items as $item):
                                                $item_due = $item['item_total'] - $item['item_amount_paid'];
                                                $total_due += $item_due;
                                                $is_paid = $item_due <= 0;
                                                if (!$is_paid) $can_pay = true;
                                                
                                                // Check if service uses inventory
                                                $has_inventory = false;
                                                if (!empty($item['service_id'])) {
                                                    $inv_sql = "SELECT COUNT(*) as cnt FROM service_inventory_items WHERE medical_service_id = ?";
                                                    $inv_stmt = mysqli_prepare($mysqli, $inv_sql);
                                                    mysqli_stmt_bind_param($inv_stmt, 'i', $item['service_id']);
                                                    mysqli_stmt_execute($inv_stmt);
                                                    $inv_result = mysqli_stmt_get_result($inv_stmt);
                                                    $inv_data = mysqli_fetch_assoc($inv_result);
                                                    $has_inventory = $inv_data['cnt'] > 0;
                                                    mysqli_stmt_close($inv_stmt);
                                                }
                                            ?>
                                            <tr class="item-row <?php echo $is_paid ? 'table-success' : ''; ?>">
                                                <td class="text-center">
                                                    <?php if (!$is_paid): ?>
                                                    <input type="checkbox" class="form-check-input item-checkbox" 
                                                           id="item_check_<?php echo $item['item_id']; ?>"
                                                           data-item-id="<?php echo $item['item_id']; ?>"
                                                           data-due-amount="<?php echo $item_due; ?>"
                                                           onchange="updateItemPayment(this)">
                                                    <?php else: ?>
                                                    <i class="fas fa-check text-success"></i>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($item['item_name']); ?></strong>
                                                    <?php if (!empty($item['item_description'])): ?>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($item['item_description']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center"><?php echo $item['item_quantity']; ?></td>
                                                <td class="text-right font-weight-bold">KSH <?php echo number_format($item['item_price'], 2); ?></td>
                                                <td class="text-right font-weight-bold text-primary">KSH <?php echo number_format($item['item_total'], 2); ?></td>
                                                <td class="text-right text-success font-weight-bold">KSH <?php echo number_format($item['item_amount_paid'], 2); ?></td>
                                                <td class="text-right text-danger font-weight-bold">KSH <?php echo number_format($item_due, 2); ?></td>
                                                <td class="text-right">
                                                    <?php if (!$is_paid): ?>
                                                    <input type="number" class="form-control form-control-sm item-payment-input" 
                                                           name="item_payments[<?php echo $item['item_id']; ?>]"
                                                           id="item_payment_<?php echo $item['item_id']; ?>"
                                                           data-item-id="<?php echo $item['item_id']; ?>"
                                                           data-max-amount="<?php echo $item_due; ?>"
                                                           placeholder="0.00" step="0.01" min="0" 
                                                           max="<?php echo $item_due; ?>"
                                                           onchange="validateItemPayment(this)"
                                                           onkeyup="validateItemPayment(this)"
                                                           style="width: 120px; display: inline-block;">
                                                    <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge badge-<?php 
                                                        echo $item['item_payment_status'] === 'paid' ? 'success' : 
                                                             ($item['item_payment_status'] === 'partial' ? 'warning' : 'danger'); 
                                                    ?>">
                                                        <?php echo ucfirst($item['item_payment_status']); ?>
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($has_inventory): ?>
                                                        <span class="badge badge-info" data-toggle="tooltip" title="Consumes inventory items">
                                                            <i class="fas fa-boxes"></i>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge badge-light" data-toggle="tooltip" title="No inventory consumption">
                                                            <i class="fas fa-box"></i>
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot class="bg-light">
                                            <tr>
                                                <td colspan="6" class="text-right font-weight-bold">Total Due:</td>
                                                <td class="text-right font-weight-bold text-danger">KSH <?php echo number_format($total_due, 2); ?></td>
                                                <td class="text-right">
                                                    <div id="total_item_allocation" class="font-weight-bold text-success">KSH 0.00</div>
                                                </td>
                                                <td></td>
                                                <td></td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>

                            <!-- Payment Method Selection -->
                            <div class="row mt-4">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="font-weight-bold">Payment Method <span class="text-danger">*</span></label>
                                        <select class="form-control" name="payment_method" id="payment_method" required onchange="togglePaymentFields()">
                                            <option value="">Select Payment Method</option>
                                            <option value="cash">Cash</option>
                                            <option value="mpesa_stk">M-Pesa</option>
                                            <option value="card">Card</option>
                                            <option value="insurance">Insurance</option>
                                            <option value="check">Check</option>
                                            <option value="bank_transfer">Bank Transfer</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="font-weight-bold">Payment Amount (KSH) <span class="text-danger">*</span></label>
                                        <input type="number" class="form-control" name="payment_amount" 
                                               id="payment_amount" step="0.01" min="0.01" 
                                               max="<?php echo $invoice['remaining_balance']; ?>"
                                               value="<?php echo $invoice['remaining_balance']; ?>"
                                               required onchange="validatePaymentAmount()">
                                        <small class="form-text text-muted">
                                            Maximum: KSH <?php echo number_format($invoice['remaining_balance'], 2); ?>
                                        </small>
                                    </div>
                                </div>
                            </div>

                            <!-- Payment Date & Register -->
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="font-weight-bold">Payment Date <span class="text-danger">*</span></label>
                                        <input type="datetime-local" class="form-control" name="payment_date" 
                                               value="<?php echo date('Y-m-d\TH:i'); ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="font-weight-bold">Cash Register</label>
                                        <select class="form-control" name="register_id" id="register_id">
                                            <option value="">Select Register (Optional)</option>
                                            <?php 
                                            mysqli_data_seek($registers_result, 0);
                                            while ($register = mysqli_fetch_assoc($registers_result)): ?>
                                                <option value="<?php echo $register['register_id']; ?>">
                                                    <?php echo htmlspecialchars($register['register_name']); ?> 
                                                    (Balance: KSH <?php echo number_format($register['cash_balance'], 2); ?>)
                                                </option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <!-- Payment Method Specific Fields -->
                            <div id="payment_fields_container">
                                <!-- M-Pesa Fields -->
                                <div class="payment-fields" id="mpesa_fields" style="display: none;">
                                    <h6 class="font-weight-bold text-primary mb-3">
                                        <i class="fas fa-mobile-alt mr-2"></i>M-Pesa Details
                                    </h6>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label class="font-weight-bold">Phone Number</label>
                                                <input type="tel" class="form-control" name="mpesa_phone" 
                                                       placeholder="e.g., 254712345678" pattern="[0-9]{12}">
                                                <small class="form-text text-muted">Format: 254712345678</small>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label class="font-weight-bold">Transaction ID</label>
                                                <input type="text" class="form-control" name="mpesa_transaction_id" 
                                                       placeholder="Enter M-Pesa transaction ID">
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Card Fields -->
                                <div class="payment-fields" id="card_fields" style="display: none;">
                                    <h6 class="font-weight-bold text-primary mb-3">
                                        <i class="fas fa-credit-card mr-2"></i>Card Details
                                    </h6>
                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="form-group">
                                                <label class="font-weight-bold">Card Type</label>
                                                <select class="form-control" name="card_type">
                                                    <option value="">Select Card Type</option>
                                                    <option value="visa">Visa</option>
                                                    <option value="mastercard">MasterCard</option>
                                                    <option value="amex">American Express</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-group">
                                                <label class="font-weight-bold">Last 4 Digits</label>
                                                <input type="text" class="form-control" name="card_last_four" 
                                                       placeholder="1234" maxlength="4" pattern="[0-9]{4}">
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-group">
                                                <label class="font-weight-bold">Auth Code</label>
                                                <input type="text" class="form-control" name="card_authorization_code" 
                                                       placeholder="Enter auth code">
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Insurance Fields -->
                                <div class="payment-fields" id="insurance_fields" style="display: none;">
                                    <h6 class="font-weight-bold text-primary mb-3">
                                        <i class="fas fa-hospital mr-2"></i>Insurance Details
                                    </h6>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label class="font-weight-bold">Insurance Company</label>
                                                <select class="form-control" name="insurance_company_id" id="insurance_company_id">
                                                    <option value="">Select Insurance Company</option>
                                                    <?php 
                                                    mysqli_data_seek($insurance_result, 0);
                                                    while ($insurance = mysqli_fetch_assoc($insurance_result)): ?>
                                                        <option value="<?php echo $insurance['insurance_company_id']; ?>">
                                                            <?php echo htmlspecialchars($insurance['company_name']); ?>
                                                        </option>
                                                    <?php endwhile; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label class="font-weight-bold">Claim Number</label>
                                                <input type="text" class="form-control" name="insurance_claim_number" 
                                                       placeholder="Enter claim number">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Payment Notes -->
                            <div class="form-group">
                                <label class="font-weight-bold">Payment Notes</label>
                                <textarea class="form-control" name="payment_notes" rows="2" 
                                          placeholder="Additional payment notes..."></textarea>
                            </div>

                            <div class="alert alert-warning mt-3">
                                <i class="fas fa-exclamation-triangle mr-2"></i>
                                <strong>Important Payment Policy:</strong>
                                <ul class="mb-0 mt-2">
                                    <li>Invoices remain open even when fully paid</li>
                                    <li>Manual closure required after billing discharges</li>
                                    <li>Visit must be discharged before invoice closure</li>
                                    <li>All billing items must be marked as discharged</li>
                                    <li>Automatic accounting entries created for all payments</li>
                                </ul>
                            </div>
                            
                            <div class="row mt-4">
                                <div class="col-md-12 text-center">
                                    <?php if($can_pay): ?>
                                        <button type="submit" name="process_payment" class="btn btn-success btn-lg" id="processPaymentButton">
                                            <i class="fas fa-check mr-2"></i>Process Payment & Post to Accounting
                                        </button>
                                        <a href="billing_invoice_edit.php?invoice_id=<?php echo $invoice_id; ?>" class="btn btn-warning btn-lg ml-2">
                                            <i class="fas fa-edit mr-2"></i>Edit Invoice
                                        </a>
                                        <a href="billing_invoices.php" class="btn btn-secondary btn-lg ml-2">Cancel</a>
                                    <?php else: ?>
                                        <div class="alert alert-success">
                                            <i class="fas fa-check-circle mr-2"></i>
                                            This invoice is already fully paid. No payment required.
                                        </div>
                                        <a href="payment_receipt.php?invoice_id=<?php echo $invoice_id; ?>" class="btn btn-info btn-lg mr-2">
                                            <i class="fas fa-receipt mr-2"></i>View Receipts
                                        </a>
                                        <a href="billing_invoices.php" class="btn btn-secondary">Back to Invoices</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Close Invoice Section -->
                <?php if($invoice['invoice_status'] != 'closed' && $fully_paid): ?>
                <div class="row mt-4">
                    <div class="col-md-12">
                        <div class="card card-danger">
                            <div class="card-header">
                                <h3 class="card-title"><i class="fas fa-lock mr-2"></i>Close Invoice</h3>
                            </div>
                            <div class="card-body">
                                <div class="alert alert-warning">
                                    <i class="fas fa-exclamation-triangle mr-2"></i>
                                    <strong>Important:</strong> Closing an invoice will finalize all billing and mark the visit as complete.
                                    This action cannot be undone.
                                </div>
                                
                                <?php if($can_close_invoice): ?>
                                    <form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>?invoice_id=<?php echo $invoice_id; ?>" 
                                          onsubmit="return confirmCloseInvoice()">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="invoice_id" value="<?php echo $invoice_id; ?>">
                                        
                                        <div class="form-group">
                                            <label class="font-weight-bold">Close Invoice</label>
                                            <div class="alert alert-success">
                                                <i class="fas fa-check-circle mr-2"></i>
                                                <strong>Ready to Close:</strong> 
                                                <ul class="mt-2 mb-0">
                                                    <li>✓ Invoice is fully paid</li>
                                                    <li>✓ Visit is discharged</li>
                                                    <li>✓ All billing items are discharged</li>
                                                    <li>✓ No pending adjustments</li>
                                                    <li>✓ Accounting entries posted</li>
                                                </ul>
                                            </div>
                                        </div>
                                        
                                        <button type="submit" name="close_invoice" class="btn btn-danger btn-lg">
                                            <i class="fas fa-lock mr-2"></i>Close Invoice & Finalize Visit
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle mr-2"></i>
                                        <strong>Invoice cannot be closed yet. Requirements:</strong>
                                        <ul class="mt-2 mb-0">
                                            <li><strong>Invoice Status:</strong> 
                                                <span class="badge badge-<?php echo $fully_paid ? 'success' : 'danger'; ?>">
                                                    <?php echo $fully_paid ? '✓ Fully Paid' : '✗ Not Fully Paid'; ?>
                                                </span>
                                            </li>
                                            <li><strong>Visit Status:</strong> 
                                                <span class="badge badge-<?php echo ($invoice['visit_status'] == 'discharged' && $invoice['visit_discharge_date']) ? 'success' : 'danger'; ?>">
                                                    <?php echo ($invoice['visit_status'] == 'discharged' && $invoice['visit_discharge_date']) ? '✓ Discharged' : '✗ Not Discharged'; ?>
                                                </span>
                                            </li>
                                            <li><strong>Billing Discharge:</strong> All billing items must be marked as discharged</li>
                                            <li><strong>Final Review:</strong> Verify all charges and payments are correct</li>
                                        </ul>
                                        <div class="mt-3">
                                            <a href="patient_visit.php?patient_id=<?php echo $invoice['patient_id']; ?>" class="btn btn-sm btn-outline-primary mr-2">
                                                <i class="fas fa-hospital mr-1"></i>Check Visit Status
                                            </a>
                                            <a href="billing_discharge.php?invoice_id=<?php echo $invoice_id; ?>" class="btn btn-sm btn-outline-info">
                                                <i class="fas fa-file-invoice-dollar mr-1"></i>Review Billing Items
                                            </a>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="col-md-4">
                <!-- Quick Actions -->
                <div class="card card-success">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-bolt mr-2"></i>Quick Actions</h3>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="payment_receipt.php?invoice_id=<?php echo $invoice_id; ?>" class="btn btn-info">
                                <i class="fas fa-receipt mr-2"></i>View Receipts
                            </a>
                            <a href="billing_invoice_edit.php?invoice_id=<?php echo $invoice_id; ?>" class="btn btn-warning">
                                <i class="fas fa-edit mr-2"></i>Edit Invoice
                            </a>
                            <a href="payment_history.php?invoice_id=<?php echo $invoice_id; ?>" class="btn btn-outline-info">
                                <i class="fas fa-history mr-2"></i>Payment History
                            </a>
                            <a href="journal_entries.php?source=invoice_<?php echo $invoice_id; ?>" class="btn btn-outline-dark">
                                <i class="fas fa-book mr-2"></i>View Accounting Entries
                            </a>
                            <a href="billing_invoices.php" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left mr-2"></i>Back to Invoices
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Payment Information -->
                <div class="card card-info mt-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-info-circle mr-2"></i>Payment Info</h3>
                    </div>
                    <div class="card-body">
                        <div class="small">
                            <div class="d-flex justify-content-between mb-2">
                                <span>Processed By:</span>
                                <span class="font-weight-bold"><?php echo $_SESSION['user_name']; ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Payment Date:</span>
                                <span class="font-weight-bold"><?php echo date('M j, Y H:i'); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Invoice ID:</span>
                                <span class="font-weight-bold">#<?php echo $invoice_id; ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Invoice Status:</span>
                                <span class="badge badge-<?php 
                                    echo $invoice['invoice_status'] === 'closed' ? 'secondary' : 
                                         ($invoice['invoice_status'] === 'partially_paid' ? 'warning' : 'danger'); 
                                ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $invoice['invoice_status'])); ?>
                                    <?php if($fully_paid && $invoice['invoice_status'] !== 'closed'): ?>
                                        <i class="fas fa-check ml-1 text-success"></i>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span>Available Balance:</span>
                                <span class="font-weight-bold text-success">
                                    KSH <?php echo number_format($invoice['remaining_balance'], 2); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Accounting Status -->
                <div class="card card-dark mt-3">
                    <div class="card-header bg-dark">
                        <h3 class="card-title"><i class="fas fa-book mr-2"></i>Accounting Status</h3>
                    </div>
                    <div class="card-body">
                        <div class="small">
                            <div class="d-flex justify-content-between mb-2">
                                <span>Total Payments:</span>
                                <span class="font-weight-bold"><?php echo $accounting_status['total_payments']; ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Accounting Posted:</span>
                                <span class="font-weight-bold text-success"><?php echo $accounting_status['posted_payments']; ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Accounting Failed:</span>
                                <span class="font-weight-bold <?php echo $accounting_status['failed_payments'] > 0 ? 'text-danger' : 'text-muted'; ?>">
                                    <?php echo $accounting_status['failed_payments']; ?>
                                </span>
                            </div>
                            <?php if($accounting_status['failed_payments'] > 0): ?>
                            <div class="alert alert-danger mt-2 p-2">
                                <i class="fas fa-exclamation-circle mr-1"></i>
                                <small>Some payments have accounting errors. Check journal entries.</small>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Recent Payments -->
                <div class="card card-warning mt-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-history mr-2"></i>Recent Payments</h3>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($payments)): ?>
                            <div class="list-group list-group-flush">
                                <?php 
                                $counter = 0;
                                foreach ($payments as $payment): 
                                    if ($counter++ >= 3) break;
                                    $accounting_badge = $payment['accounting_status'] == 'posted' ? 'badge-success' : 
                                                       ($payment['accounting_status'] == 'failed' ? 'badge-danger' : 'badge-warning');
                                ?>
                                <div class="list-group-item px-0 py-2 border-0">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1 text-primary"><?php echo htmlspecialchars($payment['payment_number']); ?></h6>
                                        <small class="text-success font-weight-bold">
                                            KSH <?php echo number_format($payment['payment_amount'], 2); ?>
                                        </small>
                                    </div>
                                    <p class="mb-1 small">
                                        <span class="badge badge-secondary"><?php echo ucfirst($payment['payment_method']); ?></span>
                                        <span class="badge badge-success">Completed</span>
                                        <span class="badge <?php echo $accounting_badge; ?>"><?php echo $payment['accounting_status']; ?></span>
                                    </p>
                                    <small class="text-muted">
                                        <?php echo date('M j, Y', strtotime($payment['payment_date'])); ?>
                                    </small>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php if (count($payments) > 3): ?>
                            <div class="text-center mt-2">
                                <a href="payment_history.php?invoice_id=<?php echo $invoice_id; ?>" class="btn btn-sm btn-outline-warning">
                                    <i class="fas fa-list mr-1"></i>View All <?php echo count($payments); ?> Payments
                                </a>
                            </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="text-center py-3">
                                <i class="fas fa-receipt fa-2x text-muted mb-2"></i>
                                <h6>No Payments Yet</h6>
                                <p class="text-muted small">This invoice has no recorded payments.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Invoice Closure Status -->
                <?php if($invoice['invoice_status'] != 'closed'): ?>
                <div class="card card-dark mt-3">
                    <div class="card-header bg-dark">
                        <h3 class="card-title"><i class="fas fa-lock-open mr-2"></i>Invoice Open Status</h3>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-light">
                            <i class="fas fa-info-circle mr-2 text-dark"></i>
                            <strong class="text-dark">Why is this invoice still open?</strong>
                            <ul class="mt-2 mb-0 text-dark">
                                <li>Allows for billing adjustments</li>
                                <li>Enables additional charges</li>
                                <li>Pending visit discharge</li>
                                <li>Final review required</li>
                                <li>Manual closure prevents errors</li>
                                <li>Accounting audit trail maintained</li>
                            </ul>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <?php else: ?>
        <!-- Select Invoice to Process Payment -->
        <div class="row">
            <div class="col-md-12">
                <div class="card card-primary">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-file-invoice mr-2"></i>Select Invoice to Pay</h3>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Date</th>
                                        <th>Invoice #</th>
                                        <th>Patient</th>
                                        <th class="text-right">Amount</th>
                                        <th class="text-right">Paid</th>
                                        <th class="text-right">Balance</th>
                                        <th>Status</th>
                                        <th class="text-center">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $invoices = mysqli_query($mysqli, "
                                        SELECT i.*, 
                                               CONCAT(p.patient_first_name, ' ', p.patient_last_name) as patient_name,
                                               i.invoice_amount - IFNULL(i.paid_amount, 0) as remaining_balance
                                        FROM invoices i
                                        LEFT JOIN patients p ON i.invoice_client_id = p.patient_id
                                        WHERE i.invoice_status IN ('unpaid', 'partially_paid')
                                        AND i.invoice_amount > IFNULL(i.paid_amount, 0)
                                        ORDER BY i.invoice_date ASC
                                        LIMIT 20
                                    ");
                                    
                                    while($inv = mysqli_fetch_assoc($invoices)): 
                                        $status_class = $inv['invoice_status'] === 'partially_paid' ? 'warning' : 'danger';
                                    ?>
                                    <tr>
                                        <td><?php echo date('M j, Y', strtotime($inv['invoice_date'])); ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($inv['invoice_number']); ?></strong>
                                        </td>
                                        <td><?php echo htmlspecialchars($inv['patient_name']); ?></td>
                                        <td class="text-right font-weight-bold">KSH <?php echo number_format($inv['invoice_amount'], 2); ?></td>
                                        <td class="text-right text-success">KSH <?php echo number_format($inv['paid_amount'], 2); ?></td>
                                        <td class="text-right text-danger font-weight-bold">KSH <?php echo number_format($inv['remaining_balance'], 2); ?></td>
                                        <td>
                                            <span class="badge badge-<?php echo $status_class; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $inv['invoice_status'])); ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <a href="<?php echo $current_page; ?>?invoice_id=<?php echo $inv['invoice_id']; ?>" 
                                               class="btn btn-sm btn-success">
                                                <i class="fas fa-credit-card mr-1"></i>Pay Now
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
$(document).ready(function() {
    console.log('Payment page loaded');
    
    // Initialize tooltips
    $('[data-toggle="tooltip"]').tooltip();
    
    // Add confirmation for payment processing
    $('#paymentForm').on('submit', function(e) {
        console.log('Payment form submission triggered');
        
        const paymentAmount = parseFloat($('#payment_amount').val()) || 0;
        const paymentMethod = $('#payment_method').val();
        const invoicePaid = <?php echo $fully_paid ? 'true' : 'false'; ?>;
        const currentPaid = <?php echo $invoice['paid_amount']; ?>;
        const invoiceTotal = <?php echo $invoice['invoice_amount']; ?>;
        const hasInventoryItems = <?php echo $inventory_items_count > 0 ? 'true' : 'false'; ?>;
        
        if (!paymentMethod) {
            alert('Please select a payment method');
            e.preventDefault();
            return false;
        }
        
        if (paymentAmount <= 0) {
            alert('Please enter a valid payment amount');
            e.preventDefault();
            return false;
        }
        
        let message = `Are you sure you want to process this payment?\n\n`;
        message += `Amount: KSH ${paymentAmount.toFixed(2)}\n`;
        message += `Method: ${paymentMethod}\n\n`;
        message += `This action will:\n`;
        message += `• Update payment records\n`;
        message += `• Generate payment receipt\n`;
        message += `• Record payment history\n`;
        
        if (hasInventoryItems) {
            message += `• Update inventory quantities\n`;
            message += `• Record COGS in accounting\n`;
        }
        
        message += `• Create accounting journal entries\n`;
        
        if ((currentPaid + paymentAmount) >= invoiceTotal) {
            message += `\n⚠️  IMPORTANT: Invoice will be fully paid but NOT automatically closed.\n`;
            message += `   Manual closure required after billing discharges.\n`;
        }
        
        message += `\nThis action cannot be undone.`;
        
        if (!confirm(message)) {
            e.preventDefault();
            return false;
        }
        
        // Show loading state
        $('#processPaymentButton').html('<i class="fas fa-spinner fa-spin mr-2"></i>Processing...').prop('disabled', true);
        console.log('Payment processing started');
    });

    // Initialize item allocation
    initializeAllocationMethod();
    togglePaymentFields();
});

// JavaScript functions for payment allocation
let totalAllocated = 0;
let itemLastValues = {};

function initializeAllocationMethod() {
    // Set specific items as default
    $('#allocation_method').val('specific_items');
    toggleAllocationMethod();
}

function toggleAllocationMethod() {
    const method = $('#allocation_method').val();
    const paymentAmountInput = $('#payment_amount');
    
    if (method === 'specific_items') {
        // Set payment amount to total due
        const totalDue = <?php echo $invoice['remaining_balance']; ?>;
        paymentAmountInput.val(totalDue);
    } else {
        // Clear all allocations
        $('.item-checkbox').prop('checked', false);
        $('.item-payment-input').val('');
        totalAllocated = 0;
        itemLastValues = {};
        updateTotalAllocation();
    }
}

function toggleSelectAllItems() {
    const isChecked = $('#select_all_items').prop('checked');
    $('#select_all_items_header').prop('checked', isChecked);
    
    totalAllocated = 0;
    itemLastValues = {};
    
    $('.item-checkbox').each(function() {
        const itemId = $(this).data('item-id');
        const dueAmount = parseFloat($(this).data('due-amount'));
        const paymentInput = $(`#item_payment_${itemId}`);
        
        if (isChecked) {
            $(this).prop('checked', true);
            paymentInput.val(dueAmount.toFixed(2));
            totalAllocated += dueAmount;
            itemLastValues[itemId] = dueAmount;
        } else {
            $(this).prop('checked', false);
            paymentInput.val('');
            totalAllocated -= (itemLastValues[itemId] || 0);
            delete itemLastValues[itemId];
        }
    });
    
    updateTotalAllocation();
    updatePaymentAmount();
}

function updateItemPayment(checkbox) {
    const itemId = $(checkbox).data('item-id');
    const dueAmount = parseFloat($(checkbox).data('due-amount'));
    const paymentInput = $(`#item_payment_${itemId}`);
    
    if (checkbox.checked) {
        paymentInput.val(dueAmount.toFixed(2));
        totalAllocated += dueAmount;
        itemLastValues[itemId] = dueAmount;
    } else {
        paymentInput.val('');
        totalAllocated -= (itemLastValues[itemId] || 0);
        delete itemLastValues[itemId];
        $('#select_all_items').prop('checked', false);
        $('#select_all_items_header').prop('checked', false);
    }
    
    updateTotalAllocation();
    updatePaymentAmount();
}

function validateItemPayment(input) {
    const itemId = $(input).data('item-id');
    const maxAmount = parseFloat($(input).data('max-amount'));
    let amount = parseFloat($(input).val()) || 0;
    
    if (amount > maxAmount) {
        alert(`Payment amount cannot exceed due amount of KSH ${maxAmount.toFixed(2)}`);
        amount = maxAmount;
        $(input).val(amount.toFixed(2));
    }
    
    const oldAmount = itemLastValues[itemId] || 0;
    totalAllocated = totalAllocated - oldAmount + amount;
    itemLastValues[itemId] = amount;
    
    // Update checkbox state
    const checkbox = $(`#item_check_${itemId}`);
    if (amount > 0) {
        checkbox.prop('checked', true);
    } else {
        checkbox.prop('checked', false);
        $('#select_all_items').prop('checked', false);
        $('#select_all_items_header').prop('checked', false);
    }
    
    updateTotalAllocation();
    updatePaymentAmount();
}

function validatePaymentAmount() {
    const paymentAmount = parseFloat($('#payment_amount').val()) || 0;
    const maxAmount = <?php echo $invoice['remaining_balance']; ?>;
    
    if (paymentAmount > maxAmount) {
        alert(`Payment amount cannot exceed remaining balance of KSH ${maxAmount.toFixed(2)}`);
        $('#payment_amount').val(maxAmount.toFixed(2));
    }
}

function updateTotalAllocation() {
    $('#total_item_allocation').text(`KSH ${totalAllocated.toFixed(2)}`);
}

function updatePaymentAmount() {
    const allocationMethod = $('#allocation_method').val();
    if (allocationMethod === 'specific_items') {
        $('#payment_amount').val(totalAllocated.toFixed(2));
    }
}

function togglePaymentFields() {
    const paymentMethod = $('#payment_method').val();
    
    // Hide all payment fields
    $('.payment-fields').hide();
    
    // Show relevant fields
    if (paymentMethod === 'mpesa_stk') {
        $('#mpesa_fields').show();
    } else if (paymentMethod === 'card') {
        $('#card_fields').show();
    } else if (paymentMethod === 'insurance') {
        $('#insurance_fields').show();
    }
}

function confirmCloseInvoice() {
    const message = `⚠️  FINAL CONFIRMATION REQUIRED ⚠️\n\n` +
                   `Are you absolutely sure you want to close this invoice?\n\n` +
                   `This will:\n` +
                   `• Permanently close the invoice\n` +
                   `• Finalize all billing\n` +
                   `• Mark visit as complete\n` +
                   `• Prevent any further modifications\n` +
                   `• Generate final reports\n\n` +
                   `This action CANNOT be undone!`;
    
    return confirm(message);
}

// Make functions globally available
window.toggleSelectAllItems = toggleSelectAllItems;
window.updateItemPayment = updateItemPayment;
window.validateItemPayment = validateItemPayment;
window.togglePaymentFields = togglePaymentFields;
window.confirmCloseInvoice = confirmCloseInvoice;
</script>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
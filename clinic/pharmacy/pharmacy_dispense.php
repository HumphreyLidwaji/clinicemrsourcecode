<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/audit_functions.php';

$prescription_id = $_GET['prescription_id'] ?? 0;

// AUDIT LOG: Access attempt for dispensing
audit_log($mysqli, [
    'user_id'     => $_SESSION['user_id'] ?? null,
    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
    'action'      => 'ACCESS',
    'module'      => 'Pharmacy',
    'table_name'  => 'prescriptions',
    'entity_type' => 'prescription',
    'record_id'   => $prescription_id,
    'patient_id'  => null,
    'visit_id'    => null,
    'description' => "Attempting to access dispensing page for prescription ID: " . $prescription_id,
    'status'      => 'ATTEMPT',
    'old_values'  => null,
    'new_values'  => null
]);

if ($prescription_id) {
    // Get prescription details with visit information
    $prescription = mysqli_fetch_assoc(mysqli_query($mysqli, "
        SELECT p.*, 
               pat.first_name, pat.last_name, pat.patient_mrn, pat.patient_id,
               u.user_name as doctor_name,
               v.visit_id, v.visit_status, v.visit_datetime,
               inv.invoice_id, inv.invoice_status
        FROM prescriptions p
        LEFT JOIN patients pat ON p.prescription_patient_id = pat.patient_id
        LEFT JOIN users u ON p.prescription_doctor_id = u.user_id
        LEFT JOIN visits v ON p.prescription_visit_id = v.visit_id
        LEFT JOIN invoices inv ON p.prescription_invoice_id = inv.invoice_id
        WHERE p.prescription_id = $prescription_id
    "));
    
    if ($prescription) {
        // AUDIT LOG: Successful access to dispensing page
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'VIEW',
            'module'      => 'Pharmacy',
            'table_name'  => 'prescriptions',
            'entity_type' => 'prescription',
            'record_id'   => $prescription_id,
            'patient_id'  => $prescription['patient_id'],
            'visit_id'    => $prescription['visit_id'] ?? null,
            'description' => "Accessed dispensing page for prescription #" . $prescription_id . 
                            " (Patient: " . $prescription['first_name'] . " " . $prescription['last_name'] . ")",
            'status'      => 'SUCCESS',
            'old_values'  => null,
            'new_values'  => null
        ]);
    }
}

// Handle form submission
if (isset($_POST['dispense_prescription'])) {
    $prescription_id = intval($_POST['prescription_id']);
    $dispensed_by = intval($_SESSION['user_id']);
    
    // AUDIT LOG: Attempt to dispense prescription
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'DISPENSE',
        'module'      => 'Pharmacy',
        'table_name'  => 'prescriptions',
        'entity_type' => 'prescription',
        'record_id'   => $prescription_id,
        'patient_id'  => null,
        'visit_id'    => null,
        'description' => "Attempting to dispense prescription ID: " . $prescription_id,
        'status'      => 'ATTEMPT',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    // Start transaction
    mysqli_begin_transaction($mysqli);
    
    try {
        // Get prescription details with visit info
        $prescription = mysqli_fetch_assoc(mysqli_query($mysqli, "
            SELECT p.*, pat.first_name, pat.last_name, pat.patient_id,
                   v.visit_id, v.visit_status
            FROM prescriptions p
            LEFT JOIN patients pat ON p.prescription_patient_id = pat.patient_id
            LEFT JOIN visits v ON p.prescription_visit_id = v.visit_id
            WHERE p.prescription_id = $prescription_id
        "));
        
        if (!$prescription) {
            throw new Exception("Prescription not found");
        }
        
        $patient_id = $prescription['patient_id'];
        $visit_id = $prescription['visit_id'];
        $patient_name = $prescription['first_name'] . ' ' . $prescription['last_name'];

        // AUDIT LOG: Prescription details retrieved
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'GET_DETAILS',
            'module'      => 'Pharmacy',
            'table_name'  => 'prescriptions',
            'entity_type' => 'prescription',
            'record_id'   => $prescription_id,
            'patient_id'  => $patient_id,
            'visit_id'    => $visit_id,
            'description' => "Retrieved prescription details for patient: " . $patient_name,
            'status'      => 'SUCCESS',
            'old_values'  => null,
            'new_values'  => json_encode([
                'prescription_id' => $prescription_id,
                'patient_id' => $patient_id,
                'visit_id' => $visit_id,
                'patient_name' => $patient_name
            ])
        ]);

        // Check for existing draft invoice for this visit
        $existing_invoice = null;
        if ($visit_id) {
            $invoice_query = mysqli_query($mysqli, "
                SELECT invoice_id, invoice_status 
                FROM invoices 
                WHERE visit_id = $visit_id 
                AND invoice_status = 'draft'
                LIMIT 1
            ");
            $existing_invoice = mysqli_fetch_assoc($invoice_query);
            
            if ($existing_invoice) {
                // AUDIT LOG: Found existing draft invoice
                audit_log($mysqli, [
                    'user_id'     => $_SESSION['user_id'] ?? null,
                    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                    'action'      => 'CHECK_INVOICE',
                    'module'      => 'Pharmacy',
                    'table_name'  => 'invoices',
                    'entity_type' => 'invoice',
                    'record_id'   => $existing_invoice['invoice_id'],
                    'patient_id'  => $patient_id,
                    'visit_id'    => $visit_id,
                    'description' => "Found existing draft invoice #" . $existing_invoice['invoice_id'] . 
                                    " for visit #" . $visit_id,
                    'status'      => 'SUCCESS',
                    'old_values'  => null,
                    'new_values'  => null
                ]);
            }
        }

        // Check for existing pending bill with draft/pending status
        $pending_bill = null;
        if ($visit_id) {
            $pending_bill_query = mysqli_query($mysqli, "
                SELECT pending_bill_id, bill_status, total_amount
                FROM pending_bills 
                WHERE visit_id = $visit_id 
                AND bill_status IN ('draft', 'pending')
                AND is_finalized = 0
                LIMIT 1
            ");
            $pending_bill = mysqli_fetch_assoc($pending_bill_query);
            
            if ($pending_bill) {
                // AUDIT LOG: Found pending bill
                audit_log($mysqli, [
                    'user_id'     => $_SESSION['user_id'] ?? null,
                    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                    'action'      => 'CHECK_PENDING_BILL',
                    'module'      => 'Pharmacy',
                    'table_name'  => 'pending_bills',
                    'entity_type' => 'pending_bill',
                    'record_id'   => $pending_bill['pending_bill_id'],
                    'patient_id'  => $patient_id,
                    'visit_id'    => $visit_id,
                    'description' => "Found pending bill #" . $pending_bill['pending_bill_id'] . 
                                    " with status: " . $pending_bill['bill_status'],
                    'status'      => 'SUCCESS',
                    'old_values'  => null,
                    'new_values'  => null
                ]);
            }
        }

        // Process each prescription item - UPDATED TO USE NEW TABLES
        $items = mysqli_query($mysqli, "
            SELECT pi.*, 
                   ii.item_id, ii.item_name, ii.item_code, ii.unit_of_measure,
                   ii.is_drug, ii.requires_batch,
                   ib.batch_id, ib.batch_number, ib.expiry_date, ib.manufacturer,
                   ils.location_id, ils.quantity as available_stock, 
                   ils.unit_cost, ils.selling_price as unit_price,
                   il.location_name, il.location_type
            FROM prescription_items pi
            LEFT JOIN inventory_items ii ON pi.pi_item_id = ii.item_id
            LEFT JOIN inventory_batches ib ON pi.pi_batch_id = ib.batch_id
            LEFT JOIN inventory_location_stock ils ON pi.pi_batch_id = ils.batch_id AND pi.pi_location_id = ils.location_id
            LEFT JOIN inventory_locations il ON ils.location_id = il.location_id
            WHERE pi.pi_prescription_id = $prescription_id
            AND ii.item_id IS NOT NULL
        ");

        $invoice_subtotal = 0;
        $invoice_items = [];
        $dispensed_items_log = [];
        
        while($item = mysqli_fetch_assoc($items)) {
            $quantity = $item['pi_quantity'];
            $item_id = $item['item_id'];
            $item_name = $item['item_name'] ?? 'Unspecified Item';
            $item_code = $item['item_code'] ?? '';
            $unit_price = $item['unit_price'] ?? 0;
            $item_total = $quantity * $unit_price;
            $invoice_subtotal += $item_total;
            
            // AUDIT LOG: Processing item
            audit_log($mysqli, [
                'user_id'     => $_SESSION['user_id'] ?? null,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'PROCESS_ITEM',
                'module'      => 'Pharmacy',
                'table_name'  => 'prescription_items',
                'entity_type' => 'prescription_item',
                'record_id'   => $item['pi_id'],
                'patient_id'  => $patient_id,
                'visit_id'    => $visit_id,
                'description' => "Processing prescription item: " . $item_name . 
                                " (Quantity: " . $quantity . ", Unit Price: " . $unit_price . ")",
                'status'      => 'PROCESSING',
                'old_values'  => null,
                'new_values'  => null
            ]);
            
            if (!$item_id) {
                throw new Exception("No inventory item linked for item: {$item_name}");
            }
            
            // Get current inventory details including batch items
            $inventory_item = mysqli_fetch_assoc(mysqli_query($mysqli, "
                SELECT ii.item_id, ii.item_name, ii.item_code, ii.status,
                       ii.is_drug, ii.requires_batch, ii.reorder_level
                FROM inventory_items ii
                WHERE ii.item_id = $item_id
            "));
            
            if (!$inventory_item) {
                throw new Exception("Inventory item not found: {$item_name}");
            }
            
            // Check total stock availability including batch items
            $total_stock_info = getAvailableStockWithBatches($mysqli, $item_id);
            $total_available_stock = $total_stock_info['total_stock'];
            
            // CHECK STOCK BEFORE ADDING TO PENDING BILLS
            if ($total_available_stock < $quantity) {
                throw new Exception("Insufficient total stock for {$item_name}. Available: $total_available_stock, Required: $quantity");
            }
            
            // Check if inventory item exists in billable_items
            $billable_item = mysqli_fetch_assoc(mysqli_query($mysqli, "
                SELECT billable_item_id, item_name, unit_price, is_active
                FROM billable_items 
                WHERE source_table = 'inventory_items' 
                AND source_id = $item_id
                AND is_active = 1
                LIMIT 1
            "));
            
            if (!$billable_item) {
                // AUDIT LOG: Inventory item not in billable items
                audit_log($mysqli, [
                    'user_id'     => $_SESSION['user_id'] ?? null,
                    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                    'action'      => 'BILLABLE_ITEM_NOT_FOUND',
                    'module'      => 'Pharmacy',
                    'table_name'  => 'billable_items',
                    'entity_type' => 'billable_item',
                    'record_id'   => null,
                    'patient_id'  => $patient_id,
                    'visit_id'    => $visit_id,
                    'description' => "Inventory item #" . $item_id . " (" . $item_name . ") not found in billable items",
                    'status'      => 'WARNING',
                    'old_values'  => null,
                    'new_values'  => null
                ]);
                
                // Create billable item if not exists
                $item_code = 'INV-' . $item_id;
                mysqli_query($mysqli, "
                    INSERT INTO billable_items 
                    (item_type, source_table, source_id, item_code, item_name, 
                     item_description, unit_price, cost_price, is_active, created_by)
                    VALUES (
                        'inventory',
                        'inventory_items',
                        $item_id,
                        '$item_code',
                        '" . mysqli_real_escape_string($mysqli, $item_name) . "',
                        '" . mysqli_real_escape_string($mysqli, "Inventory Item: " . $item_name . " | Code: " . $item_code) . "',
                        $unit_price,
                        " . ($item['unit_cost'] ?? 0) . ",
                        1,
                        $dispensed_by
                    )
                ");
                $billable_item_id = mysqli_insert_id($mysqli);
                
                // AUDIT LOG: Billable item created
                audit_log($mysqli, [
                    'user_id'     => $_SESSION['user_id'] ?? null,
                    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                    'action'      => 'CREATE_BILLABLE_ITEM',
                    'module'      => 'Pharmacy',
                    'table_name'  => 'billable_items',
                    'entity_type' => 'billable_item',
                    'record_id'   => $billable_item_id,
                    'patient_id'  => $patient_id,
                    'visit_id'    => $visit_id,
                    'description' => "Created billable item for inventory item: " . $item_name . " (Price: " . $unit_price . ")",
                    'status'      => 'SUCCESS',
                    'old_values'  => null,
                    'new_values'  => json_encode([
                        'item_type' => 'inventory',
                        'source_table' => 'inventory_items',
                        'source_id' => $item_id,
                        'item_name' => $item_name,
                        'unit_price' => $unit_price
                    ])
                ]);
            } else {
                $billable_item_id = $billable_item['billable_item_id'];
            }
            
            // Create or get pending bill
            if (!$pending_bill) {
                // Generate pending bill number
                $pending_bill_number = 'PB-' . date('Ymd') . '-' . str_pad($visit_id, 6, '0', STR_PAD_LEFT);
                
                // Create new pending bill
                mysqli_query($mysqli, "
                    INSERT INTO pending_bills 
                    (bill_number, pending_bill_number, visit_id, patient_id, 
                     subtotal_amount, total_amount, bill_status, created_by, bill_date)
                    VALUES (
                        '$pending_bill_number',
                        '$pending_bill_number',
                        $visit_id,
                        $patient_id,
                        $item_total,
                        $item_total,
                        'draft',
                        $dispensed_by,
                        NOW()
                    )
                ");
                $pending_bill_id = mysqli_insert_id($mysqli);
                
                // AUDIT LOG: Pending bill created
                audit_log($mysqli, [
                    'user_id'     => $_SESSION['user_id'] ?? null,
                    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                    'action'      => 'CREATE_PENDING_BILL',
                    'module'      => 'Pharmacy',
                    'table_name'  => 'pending_bills',
                    'entity_type' => 'pending_bill',
                    'record_id'   => $pending_bill_id,
                    'patient_id'  => $patient_id,
                    'visit_id'    => $visit_id,
                    'description' => "Created pending bill #" . $pending_bill_id . " for visit #" . $visit_id,
                    'status'      => 'SUCCESS',
                    'old_values'  => null,
                    'new_values'  => json_encode([
                        'bill_number' => $pending_bill_number,
                        'total_amount' => $item_total,
                        'bill_status' => 'draft'
                    ])
                ]);
            } else {
                $pending_bill_id = $pending_bill['pending_bill_id'];
                
                // Update pending bill totals
                $new_total = $pending_bill['total_amount'] + $item_total;
                mysqli_query($mysqli, "
                    UPDATE pending_bills 
                    SET subtotal_amount = subtotal_amount + $item_total,
                        total_amount = $new_total,
                        updated_at = NOW()
                    WHERE pending_bill_id = $pending_bill_id
                ");
                
                // AUDIT LOG: Pending bill updated
                audit_log($mysqli, [
                    'user_id'     => $_SESSION['user_id'] ?? null,
                    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                    'action'      => 'UPDATE_PENDING_BILL',
                    'module'      => 'Pharmacy',
                    'table_name'  => 'pending_bills',
                    'entity_type' => 'pending_bill',
                    'record_id'   => $pending_bill_id,
                    'patient_id'  => $patient_id,
                    'visit_id'    => $visit_id,
                    'description' => "Updated pending bill #" . $pending_bill_id . " total: " . $new_total,
                    'status'      => 'SUCCESS',
                    'old_values'  => json_encode(['total_amount' => $pending_bill['total_amount']]),
                    'new_values'  => json_encode(['total_amount' => $new_total])
                ]);
            }
            
            // Add item to pending bill items
            $subtotal = $item_total;
            $tax_amount = 0; // Calculate tax if needed
            $total_amount = $subtotal + $tax_amount;
            
            mysqli_query($mysqli, "
                INSERT INTO pending_bill_items 
                (pending_bill_id, billable_item_id, item_quantity, unit_price,
                 subtotal, tax_amount, total_amount, source_type, source_id,
                 notes, created_by)
                VALUES (
                    $pending_bill_id,
                    $billable_item_id,
                    $quantity,
                    $unit_price,
                    $subtotal,
                    $tax_amount,
                    $total_amount,
                    'prescription',
                    {$item['pi_id']},
                    'Prescription item: " . mysqli_real_escape_string($mysqli, $item_name) . "',
                    $dispensed_by
                )
            ");
            $pending_bill_item_id = mysqli_insert_id($mysqli);
            
            // AUDIT LOG: Pending bill item added
            audit_log($mysqli, [
                'user_id'     => $_SESSION['user_id'] ?? null,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'ADD_PENDING_BILL_ITEM',
                'module'      => 'Pharmacy',
                'table_name'  => 'pending_bill_items',
                'entity_type' => 'pending_bill_item',
                'record_id'   => $pending_bill_item_id,
                'patient_id'  => $patient_id,
                'visit_id'    => $visit_id,
                'description' => "Added item to pending bill: " . $item_name . 
                                " (Qty: " . $quantity . ", Total: " . $total_amount . ")",
                'status'      => 'SUCCESS',
                'old_values'  => null,
                'new_values'  => json_encode([
                    'item_name' => $item_name,
                    'quantity' => $quantity,
                    'unit_price' => $unit_price,
                    'total_amount' => $total_amount
                ])
            ]);
            
            // NOW DISPENSE THE ITEMS FROM INVENTORY
            $dispensed_batches = [];
            $remaining_quantity = $quantity;
            
            // Get batch items to determine where to dispense from
            $batch_query = mysqli_query($mysqli, "
                SELECT ils.stock_id, ils.quantity, ils.unit_cost, ils.selling_price,
                       ils.last_movement_at,
                       ib.batch_id, ib.batch_number, ib.expiry_date, ib.manufacturer,
                       il.location_id, il.location_name, il.location_type
                FROM inventory_location_stock ils
                LEFT JOIN inventory_batches ib ON ils.batch_id = ib.batch_id
                LEFT JOIN inventory_locations il ON ils.location_id = il.location_id
                WHERE ib.item_id = $item_id
                AND ils.quantity > 0
                ORDER BY ib.expiry_date ASC, ils.quantity DESC
            ");
            
            // Dispense from batches first (FIFO by expiry date)
            while ($batch_item = mysqli_fetch_assoc($batch_query) && $remaining_quantity > 0) {
                $batch_quantity = $batch_item['quantity'];
                $batch_id = $batch_item['batch_id'];
                $location_id = $batch_item['location_id'];
                $location_name = $batch_item['location_name'];
                $batch_number = $batch_item['batch_number'];
                $expiry_date = $batch_item['expiry_date'];
                
                $dispense_from_batch = min($batch_quantity, $remaining_quantity);
                
                // Update batch location stock quantity
                $new_batch_quantity = $batch_quantity - $dispense_from_batch;
                mysqli_query($mysqli, "
                    UPDATE inventory_location_stock 
                    SET quantity = $new_batch_quantity,
                        last_movement_at = NOW(),
                        updated_at = NOW()
                    WHERE stock_id = {$batch_item['stock_id']}
                ");
                
                // AUDIT LOG: Batch stock update
                audit_log($mysqli, [
                    'user_id'     => $_SESSION['user_id'] ?? null,
                    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                    'action'      => 'UPDATE_BATCH_STOCK',
                    'module'      => 'Pharmacy',
                    'table_name'  => 'inventory_location_stock',
                    'entity_type' => 'inventory_batch_stock',
                    'record_id'   => $batch_item['stock_id'],
                    'patient_id'  => $patient_id,
                    'visit_id'    => $visit_id,
                    'description' => "Updated batch stock for " . $item_name . 
                                    " at " . $location_name . 
                                    " (Batch: " . $batch_number . 
                                    ", Old: " . $batch_quantity . ", New: " . $new_batch_quantity . ")",
                    'status'      => 'SUCCESS',
                    'old_values'  => json_encode(['quantity' => $batch_quantity]),
                    'new_values'  => json_encode(['quantity' => $new_batch_quantity])
                ]);
                
                $dispensed_batches[] = [
                    'batch_id' => $batch_id,
                    'batch_number' => $batch_number,
                    'expiry_date' => $expiry_date,
                    'location_id' => $location_id,
                    'location_name' => $location_name,
                    'quantity' => $dispense_from_batch
                ];
                
                $remaining_quantity -= $dispense_from_batch;
            }

            // If still need to dispense more, throw error (should not happen as we checked stock)
            if ($remaining_quantity > 0) {
                throw new Exception("Insufficient stock after batch processing for {$item_name}");
            }
            
            // Update inventory item status if needed
            if ($inventory_item['requires_batch'] == 1) {
                // Check if we need to update item status based on remaining stock
                $remaining_stock = getAvailableStockWithBatches($mysqli, $item_id);
                
                if ($remaining_stock['total_stock'] <= 0) {
                    $new_status = 'inactive';
                } elseif ($inventory_item['reorder_level'] > 0 && $remaining_stock['total_stock'] <= $inventory_item['reorder_level']) {
                    $new_status = 'active'; // Could change to 'low' if you have that status
                } else {
                    $new_status = 'active';
                }
                
                // Update item status if changed
                if ($inventory_item['status'] != $new_status) {
                    mysqli_query($mysqli, "
                        UPDATE inventory_items 
                        SET status = '$new_status',
                            updated_at = NOW()
                        WHERE item_id = $item_id
                    ");
                    
                    // AUDIT LOG: Inventory status update
                    audit_log($mysqli, [
                        'user_id'     => $_SESSION['user_id'] ?? null,
                        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                        'action'      => 'UPDATE_INVENTORY_STATUS',
                        'module'      => 'Pharmacy',
                        'table_name'  => 'inventory_items',
                        'entity_type' => 'inventory_item',
                        'record_id'   => $item_id,
                        'patient_id'  => $patient_id,
                        'visit_id'    => $visit_id,
                        'description' => "Updated inventory status for " . $item_name . 
                                        " (Old: " . $inventory_item['status'] . ", New: " . $new_status . ")",
                        'status'      => 'SUCCESS',
                        'old_values'  => json_encode(['status' => $inventory_item['status']]),
                        'new_values'  => json_encode(['status' => $new_status])
                    ]);
                }
            }
            
            // Update prescription item with dispensed quantity, pricing, and batches
            $dispensed_batches_json = json_encode($dispensed_batches);
            mysqli_query($mysqli, "
                UPDATE prescription_items SET 
                pi_dispensed_quantity = $quantity,
                pi_unit_price = $unit_price,
                pi_total_price = $item_total,
                pi_dispensed_batches = '$dispensed_batches_json',
                pi_dispensed_at = NOW()
                WHERE pi_id = {$item['pi_id']}
            ");
            
            // AUDIT LOG: Prescription item update
            audit_log($mysqli, [
                'user_id'     => $_SESSION['user_id'] ?? null,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'UPDATE_PRESCRIPTION_ITEM',
                'module'      => 'Pharmacy',
                'table_name'  => 'prescription_items',
                'entity_type' => 'prescription_item',
                'record_id'   => $item['pi_id'],
                'patient_id'  => $patient_id,
                'visit_id'    => $visit_id,
                'description' => "Updated prescription item for " . $item_name . 
                                " (Quantity: " . $quantity . ", Total: " . $item_total . ")",
                'status'      => 'SUCCESS',
                'old_values'  => json_encode([
                    'pi_dispensed_quantity' => $item['pi_dispensed_quantity'],
                    'pi_unit_price' => $item['pi_unit_price'],
                    'pi_total_price' => $item['pi_total_price']
                ]),
                'new_values'  => json_encode([
                    'pi_dispensed_quantity' => $quantity,
                    'pi_unit_price' => $unit_price,
                    'pi_total_price' => $item_total,
                    'pi_dispensed_at' => date('Y-m-d H:i:s')
                ])
            ]);
            
            $dispensed_items_log[] = [
                'item_name' => $item_name,
                'quantity' => $quantity,
                'unit_price' => $unit_price,
                'total' => $item_total,
                'pending_bill_item_id' => $pending_bill_item_id
            ];
        }
        
        // Calculate invoice totals
        $tax_amount = 0;
        $invoice_total = $invoice_subtotal + $tax_amount;
        
        // Update prescription with pending bill reference
        mysqli_query($mysqli, "
            UPDATE prescriptions SET 
            prescription_status = 'dispensed',
            prescription_dispensed_at = NOW(),
            prescription_dispensed_by = $dispensed_by
            WHERE prescription_id = $prescription_id
        ");
        
        // AUDIT LOG: Prescription status update
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'UPDATE_PRESCRIPTION_STATUS',
            'module'      => 'Pharmacy',
            'table_name'  => 'prescriptions',
            'entity_type' => 'prescription',
            'record_id'   => $prescription_id,
            'patient_id'  => $patient_id,
            'visit_id'    => $visit_id,
            'description' => "Updated prescription status to 'dispensed'",
            'status'      => 'SUCCESS',
            'old_values'  => json_encode([
                'prescription_status' => $prescription['prescription_status'],
                'prescription_dispensed_at' => $prescription['prescription_dispensed_at']
            ]),
            'new_values'  => json_encode([
                'prescription_status' => 'dispensed',
                'prescription_dispensed_at' => date('Y-m-d H:i:s'),
                'prescription_dispensed_by' => $dispensed_by
            ])
        ]);

        // Commit transaction
        mysqli_commit($mysqli);
        
        // Determine pending bill status
        $pending_bill_status = $pending_bill ? $pending_bill['bill_status'] : 'draft';
        
        // AUDIT LOG: Dispense completed successfully
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'DISPENSE_COMPLETE',
            'module'      => 'Pharmacy',
            'table_name'  => 'prescriptions',
            'entity_type' => 'prescription',
            'record_id'   => $prescription_id,
            'patient_id'  => $patient_id,
            'visit_id'    => $visit_id,
            'description' => "Prescription #" . $prescription_id . " dispensed successfully for patient: " . $patient_name . 
                            ". Pending bill #" . ($pending_bill_id ?? 'NEW') . " updated/created with status: " . $pending_bill_status . 
                            ". Items dispensed: " . count($dispensed_items_log) . 
                            ". Total amount: " . $invoice_total,
            'status'      => 'COMPLETED',
            'old_values'  => null,
            'new_values'  => json_encode([
                'pending_bill_id' => $pending_bill_id ?? 'new',
                'pending_bill_status' => $pending_bill_status,
                'invoice_total' => $invoice_total,
                'items_count' => count($dispensed_items_log),
                'patient_name' => $patient_name,
                'dispensed_by' => $_SESSION['user_name']
            ])
        ]);

        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Prescription dispensed successfully! " . 
            ($pending_bill ? "Added to existing pending bill #$pending_bill_id" : "Pending bill created") . 
            ". Inventory updated from batch locations and billing records created.";
        
        error_log("Dispense successful");
        header("Location: pharmacy_prescriptions.php");
        exit;
        
    } catch (Exception $e) {
        mysqli_rollback($mysqli);
        
        // Check if error suggests transfer
        $error_message = $e->getMessage();
        $inventory_item_id = $item_id ?? 0;
        
        // AUDIT LOG: Dispense failed
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'DISPENSE',
            'module'      => 'Pharmacy',
            'table_name'  => 'prescriptions',
            'entity_type' => 'prescription',
            'record_id'   => $prescription_id,
            'patient_id'  => $patient_id ?? null,
            'visit_id'    => $visit_id ?? null,
            'description' => "Failed to dispense prescription #" . $prescription_id . 
                            ". Error: " . $error_message,
            'status'      => 'FAILED',
            'old_values'  => null,
            'new_values'  => null
        ]);
        
        if (strpos($error_message, 'transfer') !== false || strpos($error_message, 'Stock distribution') !== false) {
            $_SESSION['alert_type'] = "warning";
            $_SESSION['alert_message'] = $error_message . 
                " <a href='inventory_transfer.php?item_id=$inventory_item_id' class='btn btn-sm btn-warning ml-2'>" .
                "<i class='fas fa-exchange-alt mr-1'></i>Transfer Stock</a>" .
                " <a href='pharmacy_dispense.php?prescription_id=$prescription_id' class='btn btn-sm btn-info ml-1'>" .
                "<i class='fas fa-redo mr-1'></i>Try Again After Transfer</a>";
        } else {
            $_SESSION['alert_type'] = "danger";
            $_SESSION['alert_message'] = "Error dispensing prescription: " . $error_message;
        }
        
        error_log("Dispense error: " . $e->getMessage());
        header("Location: pharmacy_dispense.php?prescription_id=$prescription_id");
        exit;
    }
}

function getAvailableStockWithBatches($mysqli, $inventory_item_id) {

    $result = [
        'total_stock' => 0,
        'batch_items' => [],
        'needs_transfer' => false,
        'transfer_suggestion' => '',
        'locations_summary' => ''
    ];

    if (empty($inventory_item_id)) {
        return $result; // fail safely
    }

    $sql = "
        SELECT ils.stock_id, ils.quantity, ils.unit_cost, ils.selling_price,
               ils.last_movement_at,
               ib.batch_id, ib.batch_number, ib.expiry_date, ib.manufacturer,
               ib.requires_batch,
               il.location_id, il.location_name, il.location_type
        FROM inventory_location_stock ils
        LEFT JOIN inventory_batches ib ON ils.batch_id = ib.batch_id
        LEFT JOIN inventory_locations il ON ils.location_id = il.location_id
        WHERE ib.item_id = ?
          AND ils.quantity > 0
        ORDER BY ib.expiry_date ASC, ils.quantity DESC
    ";

    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("i", $inventory_item_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $locations = [];

    while ($row = $res->fetch_assoc()) {
        $result['batch_items'][] = $row;
        $result['total_stock'] += $row['quantity'];
        $locations[] = $row['location_name'] . " ({$row['quantity']})";
    }

    $result['locations_summary'] = implode(', ', array_unique($locations));

    return $result;
}
?>

<div class="card">
    <div class="card-header bg-success py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-pills mr-2"></i>Dispense Items
            </h3>
            <div class="card-tools">
                <a href="pharmacy_prescriptions.php" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Prescriptions
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

        <?php if($prescription_id && $prescription): ?>
        <!-- Dispense Specific Prescription -->
        <div class="row">
            <div class="col-md-8">
                <!-- Patient Information -->
                <div class="card card-primary">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-user mr-2"></i>Patient Information</h3>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold">Patient Name</label>
                                    <div class="form-control-plaintext">
                                        <?php echo htmlspecialchars($prescription['first_name'] . ' ' . $prescription['last_name']); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold">MRN</label>
                                    <div class="form-control-plaintext">
                                        <?php echo htmlspecialchars($prescription['patient_mrn']); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold">Prescribing Doctor</label>
                                    <div class="form-control-plaintext">
                                        <?php echo htmlspecialchars($prescription['doctor_name']); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold">Prescription Date</label>
                                    <div class="form-control-plaintext">
                                        <?php echo date('M j, Y', strtotime($prescription['prescription_date'])); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php if($prescription['visit_id']): ?>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold">Related Visit</label>
                                    <div class="form-control-plaintext">
                                        <span class="badge badge-info">Visit #<?php echo $prescription['visit_id']; ?></span>
                                        <?php if($prescription['visit_status'] == 'ACTIVE'): ?>
                                            <span class="badge badge-success ml-2">Active</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold">Pending Bill Status</label>
                                    <div class="form-control-plaintext">
                                        <?php 
                                        // Check for pending bills
                                        if ($prescription['visit_id']) {
                                            $pending_bill = mysqli_fetch_assoc(mysqli_query($mysqli, "
                                                SELECT pending_bill_id, bill_status, total_amount
                                                FROM pending_bills 
                                                WHERE visit_id = {$prescription['visit_id']} 
                                                AND bill_status IN ('draft', 'pending')
                                                AND is_finalized = 0
                                                LIMIT 1
                                            "));
                                            if ($pending_bill) {
                                                echo '<span class="badge badge-info">Pending Bill #' . $pending_bill['pending_bill_id'] . ' (' . $pending_bill['bill_status'] . ')</span>';
                                                echo '<br><small class="text-muted">Total: ' . numfmt_format_currency($currency_format, $pending_bill['total_amount'], $session_company_currency) . '</small>';
                                            } else {
                                                echo '<span class="badge badge-secondary">No active pending bill</span>';
                                            }
                                        } else {
                                            echo '<span class="text-muted">No visit linked</span>';
                                        }
                                        ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if($prescription['prescription_notes']): ?>
                        <div class="form-group">
                            <label class="font-weight-bold">Prescription Notes</label>
                            <div class="form-control-plaintext bg-light rounded p-2">
                                <?php echo htmlspecialchars($prescription['prescription_notes']); ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Prescription Items -->
                <div class="card card-warning mt-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-list-ol mr-2"></i>Prescription Items</h3>
                    </div>
                    <div class="card-body">
                        <form method="post" id="dispenseForm" action="<?php echo $_SERVER['PHP_SELF']; ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="prescription_id" value="<?php echo $prescription_id; ?>">
                            
                            <div class="table-responsive">
                                <table class="table table-bordered">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Item</th>
                                            <th class="text-center">Dosage</th>
                                            <th class="text-center">Frequency</th>
                                            <th class="text-center">Duration</th>
                                            <th class="text-center">Prescribed Qty</th>
                                            <th class="text-center">Available Stock</th>
                                            <th class="text-center">Stock Locations & Batches</th>
                                            <th class="text-right">Unit Price</th>
                                            <th class="text-right">Total</th>
                                            <th class="text-center">Billable Status</th>
                                            <th class="text-center">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        // Get prescription items - UPDATED QUERY
                                        $items = mysqli_query($mysqli, "
                                            SELECT pi.*, 
                                                   ii.item_id, ii.item_name, ii.item_code, ii.unit_of_measure,
                                                   ii.is_drug, ii.requires_batch, ii.status as inventory_status,
                                                   ib.batch_id, ib.batch_number, ib.expiry_date,
                                                   ils.quantity as available_stock, ils.selling_price as unit_price,
                                                   il.location_name, il.location_type
                                            FROM prescription_items pi
                                            LEFT JOIN inventory_items ii ON pi.pi_inventory_item_id = ii.item_id
                                            LEFT JOIN inventory_batches ib ON pi.pi_batch_id = ib.batch_id
                                            LEFT JOIN inventory_location_stock ils ON pi.pi_batch_id = ils.batch_id AND pi.pi_location_id = ils.location_id
                                            LEFT JOIN inventory_locations il ON ils.location_id = il.location_id
                                            WHERE pi.pi_prescription_id = $prescription_id
                                        ");
                                        
                                        $can_dispense = true;
                                        $grand_total = 0;
                                        $transfer_needed_items = [];
                                        $billable_items_missing = [];
                                        
                                        while($item = mysqli_fetch_assoc($items)): 
                                            $unit_price = $item['unit_price'] ?? 0;
                                            $item_total = $item['pi_quantity'] * $unit_price;
                                            $grand_total += $item_total;
                                            
                                            // Get available stock including batch items
                                            $stock_info = getAvailableStockWithBatches($mysqli, $item['item_id']);
                                            $total_available_stock = $stock_info['total_stock'];
                                            
                                            // Check if billable item exists
                                            $billable_item_exists = false;
                                            if ($item['item_id']) {
                                                $billable_check = mysqli_fetch_assoc(mysqli_query($mysqli, "
                                                    SELECT COUNT(*) as count
                                                    FROM billable_items 
                                                    WHERE source_table = 'inventory_items' 
                                                    AND source_id = {$item['item_id']}
                                                    AND is_active = 1
                                                "));
                                                $billable_item_exists = ($billable_check['count'] > 0);
                                            }
                                            
                                            // Determine stock status
                                            if ($item['item_id'] && $total_available_stock >= $item['pi_quantity']) {
                                                $stock_status = 'success';
                                                $status_text = 'In Stock';
                                            } elseif ($item['item_id'] && $total_available_stock > 0) {
                                                $stock_status = 'warning';
                                                $status_text = 'Low Stock';
                                                $can_dispense = false;
                                                $transfer_needed_items[] = $item;
                                            } else {
                                                $stock_status = 'danger';
                                                $status_text = 'Out of Stock';
                                                $can_dispense = false;
                                            }
                                            
                                            // Check billable item status
                                            if (!$billable_item_exists && $item['item_id']) {
                                                $billable_items_missing[] = $item;
                                            }
                                        ?>
                                        <tr class="<?php echo $stock_status == 'danger' ? 'table-danger' : ($stock_status == 'warning' ? 'table-warning' : ''); ?>">
                                            <td>
                                                <strong><?php echo htmlspecialchars($item['item_name'] ?? 'Unspecified Item'); ?></strong>
                                                <?php if($item['item_code']): ?>
                                                    <br><small class="text-muted">Code: <?php echo $item['item_code']; ?></small>
                                                <?php endif; ?>
                                                <?php if($item['is_drug']): ?>
                                                    <br><small class="text-success">Drug Item</small>
                                                <?php endif; ?>
                                                <?php if($item['requires_batch']): ?>
                                                    <br><small class="text-info">Batch Required</small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center"><?php echo $item['pi_dosage'] ?: '-'; ?></td>
                                            <td class="text-center"><?php echo $item['pi_frequency'] ?: '-'; ?></td>
                                            <td class="text-center"><?php echo $item['pi_duration'] ?: '-'; ?></td>
                                            <td class="text-center font-weight-bold"><?php echo $item['pi_quantity']; ?></td>
                                            <td class="text-center">
                                                <span class="font-weight-bold text-<?php echo $stock_status; ?>">
                                                    <?php echo $total_available_stock; ?>
                                                </span>
                                                <?php if($item['unit_of_measure']): ?>
                                                    <br><small class="text-muted"><?php echo $item['unit_of_measure']; ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <?php if($item['item_id']): ?>
                                                    <div class="small text-info">
                                                        <!-- Batch and Location Details -->
                                                        <?php foreach($stock_info['batch_items'] as $batch_item): ?>
                                                            <div class="mb-1 border-bottom pb-1">
                                                                <strong><?php echo $batch_item['location_type']; ?>:</strong> 
                                                                <?php echo $batch_item['location_name']; ?>
                                                                <br>
                                                                <small class="text-secondary">
                                                                    Batch: <?php echo $batch_item['batch_number']; ?> 
                                                                    (Exp: <?php echo date('M Y', strtotime($batch_item['expiry_date'])); ?>)
                                                                </small>
                                                                <span class="badge badge-light float-right"><?php echo $batch_item['quantity']; ?></span>
                                                            </div>
                                                        <?php endforeach; ?>
                                                        
                                                        <!-- Transfer Suggestion -->
                                                        <?php if($stock_info['needs_transfer']): ?>
                                                            <div class="mt-2 p-1 bg-warning text-dark rounded small">
                                                                <i class="fas fa-exchange-alt mr-1"></i>
                                                                <?php echo $stock_info['transfer_suggestion']; ?>
                                                                <a href="inventory_transfer.php?item_id=<?php echo $item['item_id']; ?>" 
                                                                   class="btn btn-xs btn-outline-dark ml-1">
                                                                    Transfer Now
                                                                </a>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-danger">
                                                        <i class="fas fa-exclamation-triangle"></i><br>
                                                        No inventory linked
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-right font-weight-bold">
                                                <?php echo numfmt_format_currency($currency_format, $unit_price, $session_company_currency); ?>
                                            </td>
                                            <td class="text-right font-weight-bold text-success">
                                                <?php echo numfmt_format_currency($currency_format, $item_total, $session_company_currency); ?>
                                            </td>
                                            <td class="text-center">
                                                <?php if($billable_item_exists): ?>
                                                    <span class="badge badge-success">Billable</span>
                                                <?php elseif($item['item_id']): ?>
                                                    <span class="badge badge-warning">Will Create</span>
                                                <?php else: ?>
                                                    <span class="badge badge-secondary">N/A</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge badge-<?php echo $stock_status; ?>">
                                                    <?php echo $status_text; ?>
                                                </span>
                                                <?php if(!$item['item_id']): ?>
                                                    <br><small class="text-danger">Edit to link inventory</small>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                    <tfoot class="bg-light">
                                        <tr>
                                            <td colspan="9" class="text-right font-weight-bold">Pending Bill Total:</td>
                                            <td colspan="2" class="text-right font-weight-bold text-success">
                                                <?php echo numfmt_format_currency($currency_format, $grand_total, $session_company_currency); ?>
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                            
                            <!-- Billable Items Alert -->
                            <?php if(count($billable_items_missing) > 0): ?>
                            <div class="alert alert-info mt-3">
                                <i class="fas fa-receipt mr-2"></i>
                                <strong>Billable Items Notice</strong>
                                <p class="mb-2">The following items will be automatically added to billable items during dispensing:</p>
                                <ul class="mb-0">
                                    <?php foreach($billable_items_missing as $billable_item): ?>
                                        <li>
                                            <strong><?php echo $billable_item['item_name']; ?></strong>: 
                                            Price: <?php echo numfmt_format_currency($currency_format, $billable_item['unit_price'] ?? 0, $session_company_currency); ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Transfer Suggestions Alert -->
                            <?php if(count($transfer_needed_items) > 0): ?>
                            <div class="alert alert-warning mt-3">
                                <i class="fas fa-exchange-alt mr-2"></i>
                                <strong>Stock Transfer Recommended</strong>
                                <p class="mb-2">The following items have stock available but may need transfers for optimal dispensing:</p>
                                <ul class="mb-0">
                                    <?php foreach($transfer_needed_items as $transfer_item): ?>
                                        <li>
                                            <strong><?php echo $transfer_item['item_name']; ?></strong>: 
                                            <a href="inventory_transfer.php?item_id=<?php echo $transfer_item['item_id']; ?>" 
                                               class="btn btn-xs btn-warning ml-2">
                                                <i class="fas fa-exchange-alt mr-1"></i>Transfer Stock
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <?php endif; ?>
                            
                            <div class="alert alert-info mt-3">
                                <i class="fas fa-info-circle mr-2"></i>
                                <strong>What happens when you dispense:</strong>
                                <ul class="mb-0 mt-2">
                                    <li>Check stock availability across batches before billing</li>
                                    <li>Verify/Create billable items in system</li>
                                    <li>Create/Update pending bill (draft/pending status)</li>
                                    <li>Add items to pending bill items table</li>
                                    <li>Deduct stock from batch locations (FIFO by expiry)</li>
                                    <li>Update inventory batch status and quantities</li>
                                    <li>Record prescription as 'dispensed'</li>
                                    <li>Create comprehensive audit logs for all actions</li>
                                </ul>
                            </div>
                            
                            <div class="row mt-4">
                                <div class="col-md-12 text-center">
                                    <?php if($can_dispense): ?>
                                        <button type="submit" name="dispense_prescription" class="btn btn-success btn-lg" id="dispenseButton">
                                            <i class="fas fa-check mr-2"></i>Dispense & Add to Pending Bill
                                        </button>
                                        <a href="pharmacy_prescription_edit.php?id=<?php echo $prescription_id; ?>" class="btn btn-warning btn-lg ml-2">
                                            <i class="fas fa-edit mr-2"></i>Edit Prescription
                                        </a>
                                        <a href="pharmacy_prescriptions.php" class="btn btn-secondary btn-lg ml-2">Cancel</a>
                                    <?php else: ?>
                                        <div class="alert alert-warning">
                                            <i class="fas fa-exclamation-triangle mr-2"></i>
                                            Cannot dispense prescription due to insufficient stock or unlinked inventory items.
                                            <?php if(count($transfer_needed_items) > 0): ?>
                                                <div class="mt-2">
                                                    <strong>Quick Actions:</strong>
                                                    <?php foreach($transfer_needed_items as $transfer_item): ?>
                                                        <a href="inventory_transfer.php?item_id=<?php echo $transfer_item['item_id']; ?>" 
                                                           class="btn btn-sm btn-warning ml-2">
                                                            <i class="fas fa-exchange-alt mr-1"></i>Transfer <?php echo $transfer_item['item_name']; ?>
                                                        </a>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        
                                    <?php endif; ?>
                                </div>
                            </div>
                        </form>
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
                            <?php if($can_dispense): ?>
                                <button type="submit" form="dispenseForm" name="dispense_prescription" class="btn btn-success btn-lg">
                                    <i class="fas fa-check mr-2"></i>Dispense Now
                                </button>
                            <?php endif; ?>
                            
                     
                            
                            <a href="pharmacy_prescription_edit.php?id=<?php echo $prescription_id; ?>" class="btn btn-warning">
                                <i class="fas fa-edit mr-2"></i>Edit Prescription
                            </a>
                            <a href="patient_view.php?patient_id=<?php echo $prescription['patient_id']; ?>" class="btn btn-outline-primary">
                                <i class="fas fa-user mr-2"></i>View Patient
                            </a>
                           
                           
                       
                            <a href="pharmacy_prescriptions.php" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left mr-2"></i>Back to List
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Dispensing Information -->
                <div class="card card-info mt-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-info-circle mr-2"></i>Dispensing Info</h3>
                    </div>
                    <div class="card-body">
                        <div class="small">
                            <div class="d-flex justify-content-between mb-2">
                                <span>Dispensed By:</span>
                                <span class="font-weight-bold"><?php echo $_SESSION['user_name']; ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Dispense Date:</span>
                                <span class="font-weight-bold"><?php echo date('M j, Y H:i'); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Prescription ID:</span>
                                <span class="font-weight-bold">#<?php echo $prescription_id; ?></span>
                            </div>
                            <?php if($prescription['visit_id']): ?>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Related Visit:</span>
                                <span class="font-weight-bold">#<?php echo $prescription['visit_id']; ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="d-flex justify-content-between">
                                <span>Total Amount:</span>
                                <span class="font-weight-bold text-success">
                                    <?php echo numfmt_format_currency($currency_format, $grand_total, $session_company_currency); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- System Updates -->
                <div class="card card-primary mt-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-database mr-2"></i>System Updates</h3>
                    </div>
                    <div class="card-body">
                        <div class="small">
                            <div class="alert alert-success mb-2">
                                <i class="fas fa-check-circle mr-2"></i>
                                <strong>Stock Check:</strong> Verified across batches before billing
                            </div>
                            <div class="alert alert-info mb-2">
                                <i class="fas fa-receipt mr-2"></i>
                                <strong>Pending Bill:</strong> 
                                <?php 
                                if ($prescription['visit_id']) {
                                    $pending_bill = mysqli_fetch_assoc(mysqli_query($mysqli, "
                                        SELECT pending_bill_id, bill_status
                                        FROM pending_bills 
                                        WHERE visit_id = {$prescription['visit_id']} 
                                        AND bill_status IN ('draft', 'pending')
                                        AND is_finalized = 0
                                        LIMIT 1
                                    "));
                                    if ($pending_bill) {
                                        echo "Add to existing bill #" . $pending_bill['pending_bill_id'];
                                    } else {
                                        echo "Create new pending bill";
                                    }
                                } else {
                                    echo "Create new pending bill";
                                }
                                ?>
                            </div>
                            <div class="alert alert-warning mb-2">
                                <i class="fas fa-cubes mr-2"></i>
                                <strong>Billable Items:</strong> Auto-create if missing
                            </div>
                            <div class="alert alert-secondary mb-2">
                                <i class="fas fa-boxes mr-2"></i>
                                <strong>Batch Inventory:</strong> Deduct from batch locations (FIFO)
                            </div>
                            <div class="alert alert-dark">
                                <i class="fas fa-clipboard-check mr-2"></i>
                                <strong>Audit Log:</strong> Comprehensive logging
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Transfer Recommendations -->
                <?php if(count($transfer_needed_items) > 0): ?>
                <div class="card card-warning mt-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-exchange-alt mr-2"></i>Transfer Recommendations</h3>
                    </div>
                    <div class="card-body">
                        <div class="small">
                            <?php foreach($transfer_needed_items as $transfer_item): 
                                $stock_info = getAvailableStockWithBatches($mysqli, $transfer_item['item_id']);
                            ?>
                            <div class="alert alert-warning mb-2">
                                <strong><?php echo $transfer_item['item_name']; ?></strong>
                                <div class="mt-1">
                                    <small>
                                        Available: <span class="badge badge-light"><?php echo $stock_info['total_stock']; ?></span><br>
                                        Locations: <?php echo $stock_info['locations_summary']; ?>
                                    </small>
                                </div>
                                <a href="inventory_transfer.php?item_id=<?php echo $transfer_item['item_id']; ?>" 
                                   class="btn btn-sm btn-warning btn-block mt-1">
                                    <i class="fas fa-exchange-alt mr-1"></i>Transfer Stock
                                </a>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Billable Items Notice -->
                <?php if(count($billable_items_missing) > 0): ?>
                <div class="card card-info mt-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-receipt mr-2"></i>Billable Items</h3>
                    </div>
                    <div class="card-body">
                        <div class="small">
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle mr-2"></i>
                                <strong>Auto-Creation:</strong>
                                <p class="mb-1 mt-1">The following will be added to billable items:</p>
                                <ul class="mb-0 pl-3">
                                    <?php foreach($billable_items_missing as $billable_item): ?>
                                        <li><?php echo $billable_item['item_name']; ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <?php else: ?>
        <!-- Select Prescription to Dispense -->
        <div class="row">
            <div class="col-md-12">
                <div class="card card-primary">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-prescription mr-2"></i>Select Prescription to Dispense</h3>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Date</th>
                                        <th>Patient</th>
                                        <th>MRN</th>
                                        <th>Doctor</th>
                                        <th class="text-center">Items</th>
                                        <th>Visit</th>
                                        <th>Status</th>
                                        <th class="text-center">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $prescriptions = mysqli_query($mysqli, "
                                        SELECT p.*, 
                                               pat.first_name, pat.last_name, pat.patient_mrn, 
                                               u.user_name as doctor_name,
                                               v.visit_id,
                                               COUNT(pi.pi_id) as item_count
                                        FROM prescriptions p
                                        LEFT JOIN patients pat ON p.prescription_patient_id = pat.patient_id
                                        LEFT JOIN users u ON p.prescription_doctor_id = u.user_id
                                        LEFT JOIN visits v ON p.prescription_visit_id = v.visit_id
                                        LEFT JOIN prescription_items pi ON p.prescription_id = pi.pi_prescription_id
                                        WHERE p.prescription_status = 'pending'
                                        GROUP BY p.prescription_id
                                        ORDER BY p.prescription_date ASC
                                        LIMIT 20
                                    ");
                                    
                                    while($rx = mysqli_fetch_assoc($prescriptions)): 
                                    ?>
                                    <tr>
                                        <td><?php echo date('M j, Y', strtotime($rx['prescription_date'])); ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($rx['first_name'] . ' ' . $rx['last_name']); ?></strong>
                                        </td>
                                        <td><?php echo htmlspecialchars($rx['patient_mrn']); ?></td>
                                        <td><?php echo htmlspecialchars($rx['doctor_name']); ?></td>
                                        <td class="text-center">
                                            <span class="badge badge-primary"><?php echo $rx['item_count']; ?> items</span>
                                        </td>
                                        <td class="text-center">
                                            <?php if($rx['visit_id']): ?>
                                                <span class="badge badge-info">Visit #<?php echo $rx['visit_id']; ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="badge badge-warning">Pending</span></td>
                                        <td class="text-center">
                                            <a href="pharmacy_dispense.php?prescription_id=<?php echo $rx['prescription_id']; ?>" 
                                               class="btn btn-sm btn-success">
                                                <i class="fas fa-pills mr-1"></i>Dispense
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
    console.log('Page loaded, form ready');
    
    // Add confirmation for dispensing
    $('#dispenseForm').on('submit', function(e) {
        console.log('Form submission triggered');
        
        if (!confirm('Are you sure you want to dispense this prescription?\n\nThis will:\n• Check stock availability across batches before billing\n• Create/update billable items if missing\n• Add to pending bill (draft/pending status)\n• Deduct stock from batch locations (FIFO by expiry)\n• Update inventory batch status and quantities\n• Record prescription as dispensed\n\nThis action cannot be undone.')) {
            e.preventDefault();
            return false;
        }
        
        // Show loading state
        $('#dispenseButton').html('<i class="fas fa-spinner fa-spin mr-2"></i>Processing...').prop('disabled', true);
        console.log('Form submitted, showing loading state');
    });

    // Debug form submission
    $('#dispenseForm').on('click', function() {
        console.log('Form clicked');
    });

    // Keyboard shortcuts
    $(document).keydown(function(e) {
        // Ctrl + Enter to dispense
        if (e.ctrlKey && e.keyCode === 13) {
            e.preventDefault();
            console.log('Ctrl+Enter pressed, submitting form');
            $('#dispenseForm').submit();
        }
        // Escape to go back
        if (e.keyCode === 27) {
            window.location.href = 'pharmacy_prescriptions.php';
        }
    });

    // Check if form elements exist
    console.log('Form exists:', $('#dispenseForm').length > 0);
    console.log('Submit button exists:', $('button[name="dispense_prescription"]').length > 0);
});
</script>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
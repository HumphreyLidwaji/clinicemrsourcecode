<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/audit_functions.php';

// Verify CSRF token for all POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_token();
}

$prescription_id = intval($_GET['id'] ?? 0);

// AUDIT LOG: Access attempt for viewing prescription
audit_log($mysqli, [
    'user_id'     => $_SESSION['user_id'] ?? null,
    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
    'action'      => 'VIEW',
    'module'      => 'Pharmacy',
    'table_name'  => 'prescriptions',
    'entity_type' => 'prescription',
    'record_id'   => $prescription_id,
    'patient_id'  => null,
    'visit_id'    => null,
    'description' => "Attempting to view prescription ID: " . $prescription_id,
    'status'      => 'ATTEMPT',
    'old_values'  => null,
    'new_values'  => null
]);

if (!$prescription_id) {
    set_alert('error', 'Prescription ID not specified');
    
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'VIEW',
        'module'      => 'Pharmacy',
        'table_name'  => 'prescriptions',
        'entity_type' => 'prescription',
        'record_id'   => null,
        'patient_id'  => null,
        'visit_id'    => null,
        'description' => "Invalid prescription ID: " . $prescription_id,
        'status'      => 'FAILED',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    header("Location: pharmacy_prescriptions.php");
    exit;
}

// Get prescription details
$stmt = mysqli_prepare($mysqli, "
    SELECT p.*, 
           pat.patient_id, pat.first_name, pat.last_name, pat.patient_mrn, 
           pat.date_of_birth, pat.sex, pat.phone_primary, pat.email, 
           pat.blood_group, 
           doc.user_id as doctor_id, doc.user_name as doctor_name, 
           disp.user_name as dispensed_by_name,
           v.visit_id, v.visit_datetime, v.visit_number,
           inv.invoice_id, inv.invoice_number, inv.invoice_status,
           vi.visit_insurance_id, vi.member_number, vi.coverage_percentage, vi.authorization_code, vi.is_primary,
           ic.insurance_company_id, ic.company_name, ic.phone, ic.email,
           isch.scheme_name, isch.scheme_type,
           loc.location_name as dispensed_location,
           ipd.ipd_admission_id, ipd.admission_number, ipd.admission_datetime, ipd.discharge_datetime
    FROM prescriptions p
    LEFT JOIN patients pat ON p.prescription_patient_id = pat.patient_id
    LEFT JOIN users doc ON p.prescription_doctor_id = doc.user_id
    LEFT JOIN users disp ON p.prescription_dispensed_by = disp.user_id
    LEFT JOIN visits v ON p.prescription_visit_id = v.visit_id
    LEFT JOIN invoices inv ON p.prescription_invoice_id = inv.invoice_id
    LEFT JOIN visit_insurance vi ON v.visit_id = vi.visit_id AND vi.is_primary = 1
    LEFT JOIN insurance_companies ic ON vi.insurance_company_id = ic.insurance_company_id
    LEFT JOIN insurance_schemes isch ON vi.insurance_scheme_id = isch.scheme_id
    LEFT JOIN inventory_locations loc ON p.dispensed_location_id = loc.location_id
    LEFT JOIN ipd_admissions ipd ON v.visit_id = ipd.visit_id
    WHERE p.prescription_id = ?
");

mysqli_stmt_bind_param($stmt, 'i', $prescription_id);
mysqli_stmt_execute($stmt);
$prescription = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$prescription) {
    set_alert('error', 'Prescription not found');
    
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'VIEW',
        'module'      => 'Pharmacy',
        'table_name'  => 'prescriptions',
        'entity_type' => 'prescription',
        'record_id'   => $prescription_id,
        'patient_id'  => null,
        'visit_id'    => null,
        'description' => "Prescription ID " . $prescription_id . " not found",
        'status'      => 'FAILED',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    header("Location: pharmacy_prescriptions.php");
    exit;
}

// AUDIT LOG: Successful access to prescription
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
    'description' => "Accessed prescription #" . $prescription_id . 
                    " for patient: " . $prescription['first_name'] . " " . $prescription['last_name'] . 
                    " (MRN: " . $prescription['patient_mrn'] . ")",
    'status'      => 'SUCCESS',
    'old_values'  => null,
    'new_values'  => null
]);

// Function to get enhanced stock information including batch items
function getEnhancedStockInfo($mysqli, $item_id, $required_quantity) {
    $result = [
        'total_quantity' => 0,
        'available_quantity' => 0,
        'batch_items' => [],
        'stock_status' => 'no_inventory',
        'locations_summary' => ''
    ];
    
    if (!$item_id) {
        return $result;
    }
    
    // Get inventory item details
    $item_stmt = mysqli_prepare($mysqli, "
        SELECT item_name, item_code, unit_of_measure, status, is_drug
        FROM inventory_items
        WHERE item_id = ?
    ");
    mysqli_stmt_bind_param($item_stmt, 'i', $item_id);
    mysqli_stmt_execute($item_stmt);
    $item_info = mysqli_fetch_assoc(mysqli_stmt_get_result($item_stmt));
    mysqli_stmt_close($item_stmt);
    
    if (!$item_info) {
        $result['stock_status'] = 'no_inventory';
        return $result;
    }
    
    // Get batch and location stock information
    $batch_stmt = mysqli_prepare($mysqli, "
        SELECT ils.quantity, ils.unit_cost, ils.selling_price,
               ils.last_movement_at,
               ib.batch_number, ib.expiry_date, ib.manufacturer,
               il.location_name, il.location_type
        FROM inventory_location_stock ils
        LEFT JOIN inventory_batches ib ON ils.batch_id = ib.batch_id
        LEFT JOIN inventory_locations il ON ils.location_id = il.location_id
        WHERE ib.item_id = ? AND ils.quantity > 0
        ORDER BY ib.expiry_date ASC, ils.quantity DESC
    ");
    mysqli_stmt_bind_param($batch_stmt, 'i', $item_id);
    mysqli_stmt_execute($batch_stmt);
    $batch_result = mysqli_stmt_get_result($batch_stmt);
    
    $batch_items = [];
    while ($batch_item = mysqli_fetch_assoc($batch_result)) {
        $batch_items[] = [
            'location_name' => $batch_item['location_name'],
            'location_type' => $batch_item['location_type'],
            'batch_number' => $batch_item['batch_number'],
            'expiry_date' => $batch_item['expiry_date'],
            'manufacturer' => $batch_item['manufacturer'],
            'quantity' => $batch_item['quantity'],
            'unit_cost' => $batch_item['unit_cost'],
            'selling_price' => $batch_item['selling_price'],
            'last_movement_at' => $batch_item['last_movement_at']
        ];
        $result['total_quantity'] += $batch_item['quantity'];
    }
    mysqli_stmt_close($batch_stmt);
    
    $result['batch_items'] = $batch_items;
    $result['available_quantity'] = $result['total_quantity'];
    
    // Determine stock status
    if ($item_info['status'] == 'inactive') {
        $result['stock_status'] = 'inactive';
    } elseif ($item_info['is_drug'] == 0) {
        $result['stock_status'] = 'not_drug';
    } elseif ($result['total_quantity'] >= $required_quantity) {
        $result['stock_status'] = 'sufficient';
    } elseif ($result['total_quantity'] > 0) {
        $result['stock_status'] = 'low';
    } else {
        $result['stock_status'] = 'out';
    }
    
    // Create locations summary
    $locations = [];
    foreach ($result['batch_items'] as $batch) {
        $locations[] = $batch['location_name'] . " (" . $batch['quantity'] . ")";
    }
    $result['locations_summary'] = implode(', ', array_unique($locations));
    
    return $result;
}

// Get prescription items with enhanced inventory integration - UPDATED
$items_stmt = mysqli_prepare($mysqli, "
    SELECT pi.*, 
           ii.item_id, ii.item_name, ii.item_code, ii.unit_of_measure,
           ii.is_drug, ii.requires_batch,
           ic.category_name, ic.category_type,
           ib.batch_id, ib.batch_number, ib.expiry_date, ib.manufacturer,
           ils.unit_cost, ils.selling_price
    FROM prescription_items pi
    LEFT JOIN inventory_items ii ON pi.pi_inventory_item_id = ii.item_id
    LEFT JOIN inventory_categories ic ON ii.category_id = ic.category_id
    LEFT JOIN inventory_batches ib ON pi.pi_batch_id = ib.batch_id
    LEFT JOIN inventory_location_stock ils ON pi.pi_batch_id = ils.batch_id AND pi.pi_location_id = ils.location_id
    WHERE pi.pi_prescription_id = ?
    ORDER BY pi.pi_id
");

mysqli_stmt_bind_param($items_stmt, 'i', $prescription_id);
mysqli_stmt_execute($items_stmt);
$items_result = mysqli_stmt_get_result($items_stmt);
$items = [];
while($item = mysqli_fetch_assoc($items_result)) {
    // Get enhanced stock information for each item
    $stock_info = getEnhancedStockInfo($mysqli, $item['item_id'], $item['pi_quantity']);
    
    // Merge stock information with item data
    $items[] = array_merge($item, $stock_info);
}
mysqli_stmt_close($items_stmt);

// Calculate totals and stock status
$totals = [
    'item_count' => 0,
    'total_quantity' => 0,
    'total_amount' => 0,
    'total_cost' => 0,
    'dispensed_count' => 0,
    'all_in_stock' => true,
    'in_stock_count' => 0,
    'low_stock_count' => 0,
    'out_of_stock_count' => 0
];

foreach ($items as $item) {
    $totals['item_count']++;
    $totals['total_quantity'] += $item['pi_quantity'];
    
    // Use selling price from inventory location stock if available
    $item_price = $item['selling_price'] ?? $item['pi_unit_price'] ?? 0;
    $item_total = $item['pi_quantity'] * $item_price;
    $totals['total_amount'] += $item_total;
    
    // Calculate cost from inventory if available
    $item_cost = $item['pi_quantity'] * ($item['unit_cost'] ?? 0);
    $totals['total_cost'] += $item_cost;
    
    if ($item['pi_dispensed_quantity'] > 0) {
        $totals['dispensed_count']++;
    }
    
    // Track stock status counts
    switch ($item['stock_status']) {
        case 'sufficient':
            $totals['in_stock_count']++;
            break;
        case 'low':
            $totals['low_stock_count']++;
            $totals['all_in_stock'] = false;
            break;
        case 'out':
        case 'no_inventory':
        case 'inactive':
        case 'not_drug':
            $totals['out_of_stock_count']++;
            $totals['all_in_stock'] = false;
            break;
    }
}

// Handle actions
if (isset($_POST['cancel_prescription'])) {
    if (has_permission('pharmacy', 'cancel_prescriptions')) {
        
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'CANCEL',
            'module'      => 'Pharmacy',
            'table_name'  => 'prescriptions',
            'entity_type' => 'prescription',
            'record_id'   => $prescription_id,
            'patient_id'  => $prescription['patient_id'],
            'visit_id'    => $prescription['visit_id'] ?? null,
            'description' => "Attempting to cancel prescription #" . $prescription_id . 
                            " for patient: " . $prescription['first_name'] . " " . $prescription['last_name'],
            'status'      => 'ATTEMPT',
            'old_values'  => json_encode(['prescription_status' => $prescription['prescription_status']]),
            'new_values'  => json_encode(['prescription_status' => 'cancelled'])
        ]);
        
        $stmt = mysqli_prepare($mysqli, "
            UPDATE prescriptions SET
            prescription_status = 'cancelled',
            prescription_updated_at = NOW()
            WHERE prescription_id = ?
        ");
        mysqli_stmt_bind_param($stmt, 'i', $prescription_id);
        
        if (mysqli_stmt_execute($stmt)) {
            audit_log($mysqli, [
                'user_id'     => $_SESSION['user_id'] ?? null,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'CANCEL',
                'module'      => 'Pharmacy',
                'table_name'  => 'prescriptions',
                'entity_type' => 'prescription',
                'record_id'   => $prescription_id,
                'patient_id'  => $prescription['patient_id'],
                'visit_id'    => $prescription['visit_id'] ?? null,
                'description' => "Prescription #" . $prescription_id . " cancelled successfully",
                'status'      => 'SUCCESS',
                'old_values'  => json_encode(['prescription_status' => $prescription['prescription_status']]),
                'new_values'  => json_encode(['prescription_status' => 'cancelled'])
            ]);
            
            set_alert('success', 'Prescription cancelled successfully');
            header("Location: pharmacy_prescription_view.php?id=$prescription_id");
            exit;
        } else {
            audit_log($mysqli, [
                'user_id'     => $_SESSION['user_id'] ?? null,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'CANCEL',
                'module'      => 'Pharmacy',
                'table_name'  => 'prescriptions',
                'entity_type' => 'prescription',
                'record_id'   => $prescription_id,
                'patient_id'  => $prescription['patient_id'],
                'visit_id'    => $prescription['visit_id'] ?? null,
                'description' => "Failed to cancel prescription #" . $prescription_id . 
                                ". Error: " . $mysqli->error,
                'status'      => 'FAILED',
                'old_values'  => json_encode(['prescription_status' => $prescription['prescription_status']]),
                'new_values'  => json_encode(['prescription_status' => 'cancelled'])
            ]);
            
            set_alert('error', 'Failed to cancel prescription: ' . $mysqli->error);
        }
        
        mysqli_stmt_close($stmt);
    } else {
        set_alert('error', 'You do not have permission to cancel prescriptions');
        
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'CANCEL',
            'module'      => 'Pharmacy',
            'table_name'  => 'prescriptions',
            'entity_type' => 'prescription',
            'record_id'   => $prescription_id,
            'patient_id'  => $prescription['patient_id'],
            'visit_id'    => $prescription['visit_id'] ?? null,
            'description' => "Permission denied to cancel prescription #" . $prescription_id,
            'status'      => 'DENIED',
            'old_values'  => json_encode(['prescription_status' => $prescription['prescription_status']]),
            'new_values'  => null
        ]);
    }
}

if (isset($_POST['restore_prescription'])) {
    if (has_permission('pharmacy', 'manage_prescriptions')) {
        
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'RESTORE',
            'module'      => 'Pharmacy',
            'table_name'  => 'prescriptions',
            'entity_type' => 'prescription',
            'record_id'   => $prescription_id,
            'patient_id'  => $prescription['patient_id'],
            'visit_id'    => $prescription['visit_id'] ?? null,
            'description' => "Attempting to restore cancelled prescription #" . $prescription_id,
            'status'      => 'ATTEMPT',
            'old_values'  => json_encode(['prescription_status' => $prescription['prescription_status']]),
            'new_values'  => json_encode(['prescription_status' => 'pending'])
        ]);
        
        $stmt = mysqli_prepare($mysqli, "
            UPDATE prescriptions SET
            prescription_status = 'pending',
            prescription_updated_at = NOW()
            WHERE prescription_id = ?
        ");
        mysqli_stmt_bind_param($stmt, 'i', $prescription_id);
        
        if (mysqli_stmt_execute($stmt)) {
            audit_log($mysqli, [
                'user_id'     => $_SESSION['user_id'] ?? null,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'RESTORE',
                'module'      => 'Pharmacy',
                'table_name'  => 'prescriptions',
                'entity_type' => 'prescription',
                'record_id'   => $prescription_id,
                'patient_id'  => $prescription['patient_id'],
                'visit_id'    => $prescription['visit_id'] ?? null,
                'description' => "Prescription #" . $prescription_id . " restored to pending status",
                'status'      => 'SUCCESS',
                'old_values'  => json_encode(['prescription_status' => $prescription['prescription_status']]),
                'new_values'  => json_encode(['prescription_status' => 'pending'])
            ]);
            
            set_alert('success', 'Prescription restored successfully');
            header("Location: pharmacy_prescription_view.php?id=$prescription_id");
            exit;
        }
        
        mysqli_stmt_close($stmt);
    }
}

if (isset($_POST['update_notes'])) {
    if (has_permission('pharmacy', 'edit_prescriptions')) {
        $notes = sanitizeInput($_POST['prescription_notes']);
        $instructions = sanitizeInput($_POST['prescription_instructions']);
        
        $old_notes = $prescription['prescription_notes'] ?? '';
        $old_instructions = $prescription['prescription_instructions'] ?? '';
        
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'UPDATE_NOTES',
            'module'      => 'Pharmacy',
            'table_name'  => 'prescriptions',
            'entity_type' => 'prescription',
            'record_id'   => $prescription_id,
            'patient_id'  => $prescription['patient_id'],
            'visit_id'    => $prescription['visit_id'] ?? null,
            'description' => "Attempting to update notes for prescription #" . $prescription_id,
            'status'      => 'ATTEMPT',
            'old_values'  => json_encode([
                'notes' => $old_notes,
                'instructions' => $old_instructions
            ]),
            'new_values'  => json_encode([
                'notes' => $notes,
                'instructions' => $instructions
            ])
        ]);
        
        $stmt = mysqli_prepare($mysqli, "
            UPDATE prescriptions SET
            prescription_notes = ?,
            prescription_instructions = ?,
            prescription_updated_at = NOW()
            WHERE prescription_id = ?
        ");
        mysqli_stmt_bind_param($stmt, 'ssi', $notes, $instructions, $prescription_id);
        
        if (mysqli_stmt_execute($stmt)) {
            audit_log($mysqli, [
                'user_id'     => $_SESSION['user_id'] ?? null,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'UPDATE_NOTES',
                'module'      => 'Pharmacy',
                'table_name'  => 'prescriptions',
                'entity_type' => 'prescription',
                'record_id'   => $prescription_id,
                'patient_id'  => $prescription['patient_id'],
                'visit_id'    => $prescription['visit_id'] ?? null,
                'description' => "Updated notes for prescription #" . $prescription_id,
                'status'      => 'SUCCESS',
                'old_values'  => json_encode([
                    'notes' => $old_notes,
                    'instructions' => $old_instructions
                ]),
                'new_values'  => json_encode([
                    'notes' => $notes,
                    'instructions' => $instructions
                ])
            ]);
            
            set_alert('success', 'Prescription details updated successfully');
            header("Location: pharmacy_prescription_view.php?id=" . $prescription_id);
            exit;
        } else {
            audit_log($mysqli, [
                'user_id'     => $_SESSION['user_id'] ?? null,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'UPDATE_NOTES',
                'module'      => 'Pharmacy',
                'table_name'  => 'prescriptions',
                'entity_type' => 'prescription',
                'record_id'   => $prescription_id,
                'patient_id'  => $prescription['patient_id'],
                'visit_id'    => $prescription['visit_id'] ?? null,
                'description' => "Failed to update notes for prescription #" . $prescription_id . 
                                ". Error: " . $mysqli->error,
                'status'      => 'FAILED',
                'old_values'  => json_encode([
                    'notes' => $old_notes,
                    'instructions' => $old_instructions
                ]),
                'new_values'  => json_encode([
                    'notes' => $notes,
                    'instructions' => $instructions
                ])
            ]);
            
            set_alert('error', 'Failed to update prescription details');
        }
        
        mysqli_stmt_close($stmt);
    }
}

// Calculate patient age
$patient_age = '';
if ($prescription['date_of_birth']) {
    $age = date_diff(date_create($prescription['date_of_birth']), date_create('today'))->y;
    $patient_age = "$age years";
}

// Get prescription statistics for header
$today_prescriptions_sql = "SELECT COUNT(*) as count FROM prescriptions WHERE DATE(prescription_date) = CURDATE()";
$today_prescriptions_result = $mysqli->query($today_prescriptions_sql);
$today_prescriptions = $today_prescriptions_result->fetch_assoc()['count'];

$pending_prescriptions_sql = "SELECT COUNT(*) as count FROM prescriptions WHERE prescription_status = 'pending'";
$pending_prescriptions_result = $mysqli->query($pending_prescriptions_sql);
$pending_prescriptions = $pending_prescriptions_result->fetch_assoc()['count'];

$dispensed_prescriptions_sql = "SELECT COUNT(*) as count FROM prescriptions WHERE prescription_status = 'dispensed' AND DATE(prescription_dispensed_at) = CURDATE()";
$dispensed_prescriptions_result = $mysqli->query($dispensed_prescriptions_sql);
$dispensed_prescriptions = $dispensed_prescriptions_result->fetch_assoc()['count'];

$total_prescriptions_sql = "SELECT COUNT(*) as count FROM prescriptions WHERE prescription_status != 'cancelled'";
$total_prescriptions_result = $mysqli->query($total_prescriptions_sql);
$total_prescriptions = $total_prescriptions_result->fetch_assoc()['count'];
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-file-prescription mr-2"></i>
            Prescription #<?php echo $prescription_id; ?> 
            <span class="badge badge-<?php 
                echo $prescription['prescription_status'] == 'pending' ? 'warning' : 
                     ($prescription['prescription_status'] == 'dispensed' ? 'success' : 
                     ($prescription['prescription_status'] == 'cancelled' ? 'danger' : 
                     ($prescription['prescription_status'] == 'partial' ? 'info' : 
                     ($prescription['prescription_status'] == 'active' ? 'primary' : 'secondary')))); 
            ?> ml-2">
                <?php echo strtoupper($prescription['prescription_status']); ?>
            </span>
        </h3>
        <div class="card-tools">
            <div class="btn-group">
                <a href="prescription_pdf.php?prescription_id=<?php echo $prescription_id; ?>" 
                   class="btn btn-light mr-2" target="_blank">
                    <i class="fas fa-print mr-2"></i>Print
                </a>
                <a href="pharmacy_prescriptions.php" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to List
                </a>
            </div>
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

        <!-- Prescription Stats Header -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="btn-toolbar justify-content-between">
                    <div class="btn-group">
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Status:</strong> 
                            <span class="badge badge-<?php 
                                echo $prescription['prescription_status'] == 'pending' ? 'warning' : 
                                     ($prescription['prescription_status'] == 'dispensed' ? 'success' : 
                                     ($prescription['prescription_status'] == 'cancelled' ? 'danger' : 
                                     ($prescription['prescription_status'] == 'partial' ? 'info' : 
                                     ($prescription['prescription_status'] == 'active' ? 'primary' : 'secondary')))); 
                            ?> ml-2">
                                <?php echo ucfirst($prescription['prescription_status']); ?>
                            </span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Prescription ID:</strong> 
                            <span class="badge badge-primary ml-2">#<?php echo $prescription_id; ?></span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Date:</strong> 
                            <span class="badge badge-info ml-2"><?php echo date('M j, Y', strtotime($prescription['prescription_date'])); ?></span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Items:</strong> 
                            <span class="badge badge-warning ml-2"><?php echo $totals['item_count']; ?></span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Total:</strong> 
                            <span class="badge badge-success ml-2"><?php echo numfmt_format_currency($currency_format, $totals['total_amount'], $session_company_currency); ?></span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Stock:</strong> 
                            <span class="badge badge-<?php echo $totals['all_in_stock'] ? 'success' : 'danger'; ?> ml-2">
                                <?php echo $totals['in_stock_count']; ?> In / <?php echo $totals['low_stock_count']; ?> Low / <?php echo $totals['out_of_stock_count']; ?> Out
                            </span>
                        </span>
                    </div>
                    <div class="btn-group">
                        <a href="patient_overview.php?patient_id=<?php echo $prescription['patient_id']; ?>" 
                           class="btn btn-info">
                            <i class="fas fa-user mr-2"></i>View Patient
                        </a>
                        <?php if ($prescription['visit_id']): ?>
                        <a href="visit.php?visit_id=<?php echo $prescription['visit_id']; ?>" 
                           class="btn btn-secondary ml-2">
                            <i class="fas fa-stethoscope mr-2"></i>View Visit
                        </a>
                        <?php endif; ?>
                        <?php if ($prescription['ipd_admission_id']): ?>
                        <a href="ipd_patient_view.php?admission_id=<?php echo $prescription['ipd_admission_id']; ?>" 
                           class="btn btn-warning ml-2">
                            <i class="fas fa-procedures mr-2"></i>View IPD Admission
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Left Column: Patient & Items -->
            <div class="col-md-8">
                <!-- Patient Information Card -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-user mr-2"></i>Patient Information</h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="text-muted">Patient Name</label>
                                    <div class="h5 text-primary">
                                        <?php echo htmlspecialchars($prescription['first_name'] . ' ' . $prescription['last_name']); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="text-muted">MRN</label>
                                    <div class="h5 text-info">
                                        <?php echo htmlspecialchars($prescription['patient_mrn'] ?? 'NA'); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="text-muted">Date of Birth</label>
                                    <div>
                                        <?php echo $prescription['date_of_birth'] ? date('M j, Y', strtotime($prescription['date_of_birth'])) . " ($patient_age)" : 'Not specified'; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="text-muted">Gender</label>
                                    <div>
                                        <?php echo htmlspecialchars($prescription['sex'] ?: 'Not specified'); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="text-muted">Blood Group</label>
                                    <div>
                                        <?php echo htmlspecialchars($prescription['blood_group'] ?: 'Not specified'); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="text-muted">Contact</label>
                                    <div>
                                        <?php if ($prescription['phone_primary']): ?>
                                            <div><i class="fas fa-phone mr-2 text-muted"></i><?php echo htmlspecialchars($prescription['phone_primary']); ?></div>
                                        <?php endif; ?>
                                        <?php if ($prescription['email']): ?>
                                            <div><i class="fas fa-envelope mr-2 text-muted"></i><?php echo htmlspecialchars($prescription['email']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Visit Information -->
                        <?php if ($prescription['visit_id']): ?>
                        <hr>
                        <div class="row">
                            <div class="col-md-12">
                                <label class="text-muted">Visit Information</label>
                                <div class="d-flex flex-wrap justify-content-between align-items-center">
                                    <div>
                                        <span class="font-weight-bold text-primary">Visit #<?php echo ($prescription['visit_number']); ?></span>
                                        <span class="badge badge-<?php echo $prescription['visit_type'] == 'IPD' ? 'warning' : 'info'; ?> ml-2">
                                            <?php echo ($prescription['visit_type']) ?? 'N/A'; ?>
                                        </span>
                                        <?php if ($prescription['ipd_admission_id']): ?>
                                            <span class="badge badge-warning ml-2">
                                                IPD Admission #<?php echo htmlspecialchars($prescription['admission_number']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-right">
                                        <div class="small text-muted">
                                            <?php echo date('M j, Y g:i A', strtotime($prescription['visit_datetime'])); ?>
                                        </div>
                                        <?php if ($prescription['admission_datetime']): ?>
                                            <div class="small text-warning">
                                                Admitted: <?php echo date('M j, Y g:i A', strtotime($prescription['admission_datetime'])); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Visit Insurance Information -->
                        <?php if ($prescription['company_name']): ?>
                        <hr>
                        <div class="row">
                            <div class="col-md-12">
                                <label class="text-muted">Visit Insurance Information</label>
                                <div class="d-flex flex-wrap justify-content-between align-items-center">
                                    <div>
                                        <span class="font-weight-bold text-primary"><?php echo htmlspecialchars($prescription['company_name']); ?></span>
                                        <?php if ($prescription['scheme_name']): ?>
                                            <span class="badge badge-info ml-2"><?php echo htmlspecialchars($prescription['scheme_name']); ?></span>
                                            <?php if ($prescription['scheme_type']): ?>
                                                <small class="text-muted ml-2">(<?php echo htmlspecialchars($prescription['scheme_type']); ?>)</small>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-right">
                                        <?php if ($prescription['member_number']): ?>
                                            <div class="small text-muted">Member #: <?php echo htmlspecialchars($prescription['member_number']); ?></div>
                                        <?php endif; ?>
                                        <?php if ($prescription['coverage_percentage'] < 100): ?>
                                            <div class="small text-warning">Coverage: <?php echo $prescription['coverage_percentage']; ?>%</div>
                                        <?php endif; ?>
                                        <?php if ($prescription['authorization_code']): ?>
                                            <div class="small text-success">Auth Code: <?php echo htmlspecialchars($prescription['authorization_code']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php if ($prescription['phone'] || $prescription['email']): ?>
                                <div class="mt-2 small">
                                    <?php if ($prescription['phone']): ?>
                                        <span class="text-muted mr-3"><i class="fas fa-phone mr-1"></i><?php echo htmlspecialchars($prescription['phone']); ?></span>
                                    <?php endif; ?>
                                    <?php if ($prescription['email']): ?>
                                        <span class="text-muted"><i class="fas fa-envelope mr-1"></i><?php echo htmlspecialchars($prescription['email']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Prescription Items Card -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2 d-flex justify-content-between align-items-center">
                        <h4 class="card-title mb-0"><i class="fas fa-pills mr-2"></i>Prescribed Items</h4>
                        <div>
                            <span class="badge badge-primary"><?php echo $totals['item_count']; ?> items</span>
                            <span class="badge badge-success ml-2"><?php echo numfmt_format_currency($currency_format, $totals['total_amount'], $session_company_currency); ?></span>
                            <?php if ($totals['dispensed_count'] > 0): ?>
                                <span class="badge badge-info ml-2"><?php echo $totals['dispensed_count']; ?> dispensed</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Item Details</th>
                                        <th class="text-center">Dosage</th>
                                        <th class="text-center">Quantity</th>
                                        <th class="text-center">Stock Status</th>
                                        <th class="text-right">Price</th>
                                        <th class="text-right">Total</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $grand_total = 0;
                                    $grand_cost = 0;
                                    
                                    foreach($items as $item): 
                                        // Calculate item totals
                                        $item_price = $item['selling_price'] ?? $item['pi_unit_price'] ?? 0;
                                        $item_total = $item['pi_quantity'] * $item_price;
                                        $item_cost = $item['pi_quantity'] * ($item['unit_cost'] ?? 0);
                                        $grand_total += $item_total;
                                        $grand_cost += $item_cost;
                                        
                                        // Determine stock status
                                        switch($item['stock_status']) {
                                            case 'sufficient':
                                                $stock_status = 'success';
                                                $status_text = 'In Stock';
                                                $table_class = '';
                                                $stock_icon = 'fa-check';
                                                break;
                                            case 'low':
                                                $stock_status = 'warning';
                                                $status_text = 'Low Stock';
                                                $table_class = 'table-warning';
                                                $stock_icon = 'fa-exclamation-triangle';
                                                break;
                                            case 'inactive':
                                                $stock_status = 'dark';
                                                $status_text = 'Inactive';
                                                $table_class = 'table-dark';
                                                $stock_icon = 'fa-ban';
                                                break;
                                            case 'not_drug':
                                                $stock_status = 'secondary';
                                                $status_text = 'Not Drug';
                                                $table_class = 'table-secondary';
                                                $stock_icon = 'fa-exclamation-circle';
                                                break;
                                            case 'no_inventory':
                                                $stock_status = 'secondary';
                                                $status_text = 'No Inventory';
                                                $table_class = 'table-secondary';
                                                $stock_icon = 'fa-question';
                                                break;
                                            default:
                                                $stock_status = 'danger';
                                                $status_text = 'Out of Stock';
                                                $table_class = 'table-danger';
                                                $stock_icon = 'fa-times';
                                        }
                                    ?>
                                    <tr class="<?php echo $table_class; ?>">
                                        <td>
                                            <div class="font-weight-bold"><?php echo htmlspecialchars($item['item_name'] ?? 'Unspecified Item'); ?></div>
                                            <?php if($item['item_code']): ?>
                                                <small class="text-muted">Code: <?php echo htmlspecialchars($item['item_code']); ?></small>
                                            <?php endif; ?>
                                            <?php if($item['category_name']): ?>
                                                <br><small class="text-muted">Category: <?php echo htmlspecialchars($item['category_name']); ?></small>
                                            <?php endif; ?>
                                            <?php if($item['batch_number'] || $item['expiry_date']): ?>
                                                <br>
                                                <?php if($item['batch_number']): ?>
                                                    <small class="text-secondary">Batch: <?php echo htmlspecialchars($item['batch_number']); ?></small>
                                                <?php endif; ?>
                                                <?php if($item['expiry_date']): ?>
                                                    <small class="text-<?php echo strtotime($item['expiry_date']) < strtotime('+30 days') ? 'danger' : 'muted'; ?> ml-2">
                                                        Exp: <?php echo date('M Y', strtotime($item['expiry_date'])); ?>
                                                    </small>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if($item['pi_dosage']): ?>
                                                <div class="font-weight-bold"><?php echo htmlspecialchars($item['pi_dosage']); ?></div>
                                            <?php endif; ?>
                                            <?php if($item['pi_frequency']): ?>
                                                <small class="text-muted"><?php echo htmlspecialchars($item['pi_frequency']); ?></small><br>
                                            <?php endif; ?>
                                            <?php if($item['pi_duration']): ?>
                                                <small class="text-muted"><?php echo htmlspecialchars($item['pi_duration']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <div class="font-weight-bold <?php echo $item['pi_dispensed_quantity'] > 0 ? 'text-success' : ''; ?>">
                                                <?php if ($item['pi_dispensed_quantity'] > 0): ?>
                                                    <?php echo $item['pi_dispensed_quantity']; ?>/<?php echo $item['pi_quantity']; ?>
                                                <?php else: ?>
                                                    <?php echo $item['pi_quantity']; ?>
                                                <?php endif; ?>
                                            </div>
                                            <?php if($item['unit_of_measure']): ?>
                                                <small class="text-muted"><?php echo $item['unit_of_measure']; ?></small>
                                            <?php endif; ?>
                                            <?php if ($item['pi_dispensed_quantity'] > 0 && $item['pi_dispensed_quantity'] < $item['pi_quantity']): ?>
                                                <br><small class="text-warning">Pending: <?php echo $item['pi_quantity'] - $item['pi_dispensed_quantity']; ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge badge-<?php echo $stock_status; ?>">
                                                <i class="fas <?php echo $stock_icon; ?> mr-1"></i>
                                                <?php echo $status_text; ?>
                                            </span>
                                            <div class="small text-muted mt-1">
                                                Available: <?php echo $item['available_quantity']; ?>
                                                <?php if(!empty($item['batch_items']) && count($item['batch_items']) <= 3): ?>
                                                    <br>
                                                    <?php foreach(array_slice($item['batch_items'], 0, 3) as $batch): ?>
                                                        <span class="badge badge-light small" title="<?php echo $batch['batch_number']; ?> - Exp: <?php echo date('M Y', strtotime($batch['expiry_date'])); ?>">
                                                            <?php echo $batch['location_name']; ?>: <?php echo $batch['quantity']; ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                <?php elseif(!empty($item['batch_items'])): ?>
                                                    <br><small class="text-info">In <?php echo count($item['batch_items']); ?> batches</small>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="text-right">
                                            <div class="font-weight-bold">
                                                <?php echo numfmt_format_currency($currency_format, $item_price, $session_company_currency); ?>
                                            </div>
                                            <?php if(isset($item['unit_cost']) && $item['unit_cost'] > 0): ?>
                                                <small class="text-muted">
                                                    Cost: <?php echo numfmt_format_currency($currency_format, $item['unit_cost'], $session_company_currency); ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-right font-weight-bold text-success">
                                            <?php echo numfmt_format_currency($currency_format, $item_total, $session_company_currency); ?>
                                        </td>
                                        <td class="text-center">
                                            <div class="btn-group btn-group-sm">
                                                <?php if ($prescription['prescription_status'] == 'pending'): ?>
                                                    <a href="pharmacy_prescription_item_edit.php?pi_id=<?php echo $item['pi_id']; ?>" 
                                                       class="btn btn-outline-primary" title="Edit Item">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <?php if ($item['item_id']): ?>
                                                    <a href="inventory_item_view.php?id=<?php echo $item['item_id']; ?>" 
                                                       class="btn btn-outline-info" title="View Inventory">
                                                        <i class="fas fa-box"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    
                                    <?php if(empty($items)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-4">
                                            <i class="fas fa-pills fa-2x text-muted mb-3"></i>
                                            <h5 class="text-muted">No Items Prescribed</h5>
                                            <p class="text-muted">This prescription doesn't contain any items.</p>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                                <?php if(!empty($items)): ?>
                                <tfoot class="bg-light">
                                    <tr>
                                        <td colspan="4" class="text-right font-weight-bold">Totals:</td>
                                        <td class="text-right">
                                            <?php if($grand_cost > 0): ?>
                                                <small class="text-muted">Cost: <?php echo numfmt_format_currency($currency_format, $grand_cost, $session_company_currency); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-right font-weight-bold text-success">
                                            <?php echo numfmt_format_currency($currency_format, $grand_total, $session_company_currency); ?>
                                        </td>
                                        <td></td>
                                    </tr>
                                    <?php if($grand_cost > 0): ?>
                                    <tr>
                                        <td colspan="4" class="text-right font-weight-bold">Profit Margin:</td>
                                        <td colspan="3" class="text-right font-weight-bold text-<?php echo ($grand_total - $grand_cost) > 0 ? 'success' : 'danger'; ?>">
                                            <?php echo numfmt_format_currency($currency_format, $grand_total - $grand_cost, $session_company_currency); ?>
                                            (<?php echo $grand_total > 0 ? round((($grand_total - $grand_cost) / $grand_total) * 100, 1) : 0; ?>%)
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tfoot>
                                <?php endif; ?>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Prescription Details Card -->
                <div class="card">
                    <div class="card-header bg-light py-2 d-flex justify-content-between align-items-center">
                        <h4 class="card-title mb-0"><i class="fas fa-sticky-note mr-2"></i>Prescription Details</h4>
                        <?php if (SimplePermission::any('pharmacy', 'edit_prescriptions')): ?>
                            <button type="button" class="btn btn-sm btn-outline-primary" data-toggle="modal" data-target="#editNotesModal">
                                <i class="fas fa-edit mr-1"></i>Edit Details
                            </button>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <!-- Doctor's Notes -->
                        <?php if ($prescription['prescription_notes']): ?>
                        <div class="mb-4">
                            <label class="font-weight-bold text-primary">Doctor's Notes:</label>
                            <div class="bg-light rounded p-3 border">
                                <?php echo nl2br(htmlspecialchars($prescription['prescription_notes'])); ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Patient Instructions -->
                        <?php if ($prescription['prescription_instructions']): ?>
                        <div class="mb-4">
                            <label class="font-weight-bold text-info">Patient Instructions:</label>
                            <div class="bg-info-light rounded p-3 border">
                                <?php echo nl2br(htmlspecialchars($prescription['prescription_instructions'])); ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Prescription Timeline -->
                        <div class="mt-4">
                            <label class="font-weight-bold">Prescription Timeline</label>
                            <div class="timeline mt-3">
                                <div class="timeline-item">
                                    <div class="timeline-marker bg-primary"></div>
                                    <div class="timeline-content">
                                        <small class="text-muted">Prescribed</small>
                                        <div class="font-weight-bold"><?php echo date('M j, Y g:i A', strtotime($prescription['prescription_date'])); ?></div>
                                        <small class="text-muted">by Dr. <?php echo htmlspecialchars($prescription['doctor_name']); ?></small>
                                    </div>
                                </div>
                                <?php if ($prescription['prescription_dispensed_at']): ?>
                                <div class="timeline-item">
                                    <div class="timeline-marker bg-success"></div>
                                    <div class="timeline-content">
                                        <small class="text-muted">Dispensed</small>
                                        <div class="font-weight-bold"><?php echo date('M j, Y g:i A', strtotime($prescription['prescription_dispensed_at'])); ?></div>
                                        <small class="text-muted">by <?php echo htmlspecialchars($prescription['dispensed_by_name']); ?></small>
                                        <?php if ($prescription['dispensed_location']): ?>
                                            <br><small class="text-muted">at <?php echo htmlspecialchars($prescription['dispensed_location']); ?></small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <?php if ($prescription['prescription_expiry_date']): ?>
                                <div class="timeline-item">
                                    <div class="timeline-marker bg-<?php echo strtotime($prescription['prescription_expiry_date']) < time() ? 'danger' : 'warning'; ?>"></div>
                                    <div class="timeline-content">
                                        <small class="text-muted">Expires</small>
                                        <div class="font-weight-bold text-<?php echo strtotime($prescription['prescription_expiry_date']) < time() ? 'danger' : 'success'; ?>">
                                            <?php echo date('M j, Y', strtotime($prescription['prescription_expiry_date'])); ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <div class="timeline-item">
                                    <div class="timeline-marker bg-secondary"></div>
                                    <div class="timeline-content">
                                        <small class="text-muted">Last Updated</small>
                                        <div class="font-weight-bold"><?php echo date('M j, Y g:i A', strtotime($prescription['prescription_updated_at'])); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Actions & Information -->
            <div class="col-md-4">
                <!-- Prescription Actions Card -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-bolt mr-2"></i>Prescription Actions</h4>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <?php if ($prescription['prescription_status'] == 'pending'): ?>
                                <?php if (SimplePermission::any('pharmacy', 'dispense_prescriptions') && $totals['all_in_stock']): ?>
                                    <a href="pharmacy_dispense.php?prescription_id=<?php echo $prescription_id; ?>" 
                                       class="btn btn-success btn-lg">
                                        <i class="fas fa-pills mr-2"></i>Dispense Prescription
                                    </a>
                                <?php elseif (SimplePermission::any('pharmacy', 'dispense_prescriptions')): ?>
                                    <a href="pharmacy_dispense.php?prescription_id=<?php echo $prescription_id; ?>" 
                                       class="btn btn-warning btn-lg">
                                        <i class="fas fa-exclamation-triangle mr-2"></i>Partial Dispense
                                    </a>
                                <?php else: ?>
                                    <button type="button" class="btn btn-secondary btn-lg" disabled>
                                        <i class="fas fa-lock mr-2"></i>No Dispense Permission
                                    </button>
                                <?php endif; ?>
                                
                                <?php if (SimplePermission::any('pharmacy', 'cancel_prescriptions')): ?>
                                    <form method="post" class="d-grid">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <button type="submit" name="cancel_prescription" class="btn btn-danger" id="cancelBtn">
                                            <i class="fas fa-times mr-2"></i>Cancel Prescription
                                        </button>
                                    </form>
                                <?php endif; ?>
                            <?php elseif ($prescription['prescription_status'] == 'cancelled' && SimplePermission::any('pharmacy', 'manage_prescriptions')): ?>
                                <form method="post" class="d-grid">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <button type="submit" name="restore_prescription" class="btn btn-info" id="restoreBtn">
                                        <i class="fas fa-redo mr-2"></i>Restore Prescription
                                    </button>
                                </form>
                            <?php endif; ?>
                            
                            <?php if (SimplePermission::any('pharmacy', 'edit_prescriptions') && $prescription['prescription_status'] == 'pending'): ?>
                                <a href="pharmacy_prescription_edit.php?id=<?php echo $prescription_id; ?>" 
                                   class="btn btn-outline-warning">
                                    <i class="fas fa-edit mr-2"></i>Edit Prescription
                                </a>
                            <?php endif; ?>
                            
                            <a href="prescription_pdf.php?prescription_id=<?php echo $prescription_id; ?>" 
                               class="btn btn-outline-primary" target="_blank">
                                <i class="fas fa-print mr-2"></i>Print Prescription
                            </a>
                            
                            <a href="pharmacy_prescriptions.php" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left mr-2"></i>Back to List
                            </a>
                        </div>
                        
                        <hr>
                        <div class="small">
                            <p class="mb-2"><strong>Keyboard Shortcuts:</strong></p>
                            <div class="row">
                                <div class="col-6">
                                    <span class="badge badge-light">Ctrl + P</span> Print
                                </div>
                                <div class="col-6">
                                    <span class="badge badge-light">Ctrl + D</span> Dispense
                                </div>
                            </div>
                            <div class="row mt-1">
                                <div class="col-6">
                                    <span class="badge badge-light">Ctrl + E</span> Edit
                                </div>
                                <div class="col-6">
                                    <span class="badge badge-light">Esc</span> Back
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Prescription Information Card -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-info-circle mr-2"></i>Prescription Information</h4>
                    </div>
                    <div class="card-body">
                        <div class="small">
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted">Status:</span>
                                <span class="badge badge-<?php 
                                    echo $prescription['prescription_status'] == 'pending' ? 'warning' : 
                                         ($prescription['prescription_status'] == 'dispensed' ? 'success' : 
                                         ($prescription['prescription_status'] == 'cancelled' ? 'danger' : 
                                         ($prescription['prescription_status'] == 'partial' ? 'info' : 
                                         ($prescription['prescription_status'] == 'active' ? 'primary' : 'secondary')))); 
                                ?>">
                                    <?php echo ucfirst($prescription['prescription_status']); ?>
                                </span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted">Priority:</span>
                                <span class="font-weight-bold text-<?php 
                                    echo $prescription['prescription_priority'] == 'urgent' ? 'warning' : 
                                         ($prescription['prescription_priority'] == 'emergency' ? 'danger' : 'info'); 
                                ?>">
                                    <?php echo ucfirst($prescription['prescription_priority']); ?>
                                </span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted">Valid Until:</span>
                                <span class="font-weight-bold text-<?php echo strtotime($prescription['prescription_expiry_date']) < time() ? 'danger' : 'success'; ?>">
                                    <?php echo $prescription['prescription_expiry_date'] ? date('M j, Y', strtotime($prescription['prescription_expiry_date'])) : 'No expiry'; ?>
                                </span>
                            </div>
                            <?php if ($prescription['prescription_refills'] > 0): ?>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted">Refills Remaining:</span>
                                <span class="font-weight-bold text-info"><?php echo $prescription['prescription_refills']; ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted">Created:</span>
                                <span><?php echo date('M j, Y H:i', strtotime($prescription['prescription_created_at'])); ?></span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="text-muted">Last Updated:</span>
                                <span><?php echo date('M j, Y H:i', strtotime($prescription['prescription_updated_at'])); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Doctor Information Card -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-user-md mr-2"></i>Prescribing Doctor</h4>
                    </div>
                    <div class="card-body text-center">
                        <div class="doctor-avatar mb-3">
                            <!-- Avatar placeholder -->
                        </div>
                        <h5 class="mb-1">Dr. <?php echo htmlspecialchars($prescription['doctor_name']); ?></h5>
                        <small class="text-muted">Prescribing Physician</small>
                        <?php if ($prescription['visit_id']): ?>
                            <div class="mt-3">
                                <a href="visit.php?visit_id=<?php echo $prescription['visit_id']; ?>" 
                                   class="btn btn-outline-primary btn-sm">
                                    <i class="fas fa-stethoscope mr-1"></i>View Visit
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Stock Status Card -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-chart-bar mr-2"></i>Stock Status</h4>
                    </div>
                    <div class="card-body">
                        <div class="text-center mb-3">
                            <div class="h4 <?php echo $totals['all_in_stock'] ? 'text-success' : 'text-warning'; ?>">
                                <?php echo $totals['in_stock_count']; ?>/<?php echo $totals['item_count']; ?> In Stock
                            </div>
                            <small class="text-muted"><?php echo $totals['low_stock_count']; ?> low, <?php echo $totals['out_of_stock_count']; ?> out of stock</small>
                        </div>
                        
                        <?php if ($totals['item_count'] > 0): ?>
                        <div class="progress mb-2" style="height: 20px;">
                            <div class="progress-bar bg-success" style="width: <?php echo ($totals['in_stock_count'] / $totals['item_count']) * 100; ?>%">
                                In Stock
                            </div>
                            <div class="progress-bar bg-warning" style="width: <?php echo ($totals['low_stock_count'] / $totals['item_count']) * 100; ?>%">
                                Low Stock
                            </div>
                            <div class="progress-bar bg-danger" style="width: <?php echo ($totals['out_of_stock_count'] / $totals['item_count']) * 100; ?>%">
                                Out of Stock
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="small">
                            <div class="d-flex align-items-center mb-2">
                                <span class="badge badge-success mr-2">&nbsp;&nbsp;</span>
                                <span>In Stock: Sufficient quantity</span>
                            </div>
                            <div class="d-flex align-items-center mb-2">
                                <span class="badge badge-warning mr-2">&nbsp;&nbsp;</span>
                                <span>Low Stock: Partial quantity</span>
                            </div>
                            <div class="d-flex align-items-center">
                                <span class="badge badge-danger mr-2">&nbsp;&nbsp;</span>
                                <span>Out of Stock: Not available</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Today's Statistics Card -->
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-chart-pie mr-2"></i>Today's Statistics</h4>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-6">
                                <div class="stat-box bg-primary-light p-2 rounded mb-2">
                                    <i class="fas fa-file-prescription fa-lg text-primary mb-1"></i>
                                    <h5 class="mb-0"><?php echo $today_prescriptions; ?></h5>
                                    <small class="text-muted">Today</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="stat-box bg-warning-light p-2 rounded mb-2">
                                    <i class="fas fa-clock fa-lg text-warning mb-1"></i>
                                    <h5 class="mb-0"><?php echo $pending_prescriptions; ?></h5>
                                    <small class="text-muted">Pending</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="stat-box bg-success-light p-2 rounded">
                                    <i class="fas fa-check-circle fa-lg text-success mb-1"></i>
                                    <h5 class="mb-0"><?php echo $dispensed_prescriptions; ?></h5>
                                    <small class="text-muted">Dispensed</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="stat-box bg-info-light p-2 rounded">
                                    <i class="fas fa-chart-line fa-lg text-info mb-1"></i>
                                    <h5 class="mb-0"><?php echo $total_prescriptions; ?></h5>
                                    <small class="text-muted">Total Active</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Edit Notes Modal -->
<?php if (SimplePermission::any('pharmacy', 'edit_prescriptions')): ?>
<div class="modal fade" id="editNotesModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Prescription Details</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <div class="form-group">
                        <label for="prescription_notes">Doctor's Notes</label>
                        <textarea class="form-control" id="prescription_notes" name="prescription_notes" rows="4" 
                                  placeholder="Enter any clinical notes or observations..."><?php echo htmlspecialchars($prescription['prescription_notes'] ?? ''); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label for="prescription_instructions">Patient Instructions</label>
                        <textarea class="form-control" id="prescription_instructions" name="prescription_instructions" rows="4" 
                                  placeholder="Enter instructions for the patient..."><?php echo htmlspecialchars($prescription['prescription_instructions'] ?? ''); ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_notes" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
$(document).ready(function() {
    // Confirm action buttons
    $('#cancelBtn').click(function(e) {
        if (!confirm('Are you sure you want to cancel this prescription? This action cannot be undone.')) {
            e.preventDefault();
        }
    });
    
    $('#restoreBtn').click(function(e) {
        if (!confirm('Restore this prescription to pending status?')) {
            e.preventDefault();
        }
    });

    // Keyboard shortcuts
    $(document).keydown(function(e) {
        // Ctrl + P to print
        if (e.ctrlKey && e.keyCode === 80) {
            e.preventDefault();
            window.open('prescription_pdf.php?prescription_id=<?php echo $prescription_id; ?>', '_blank');
        }
        // Ctrl + D to dispense (if permitted and pending)
        if (e.ctrlKey && e.keyCode === 68 && <?php echo $prescription['prescription_status'] == 'pending' && SimplePermission::any('pharmacy', 'dispense_prescriptions') ? 'true' : 'false'; ?>) {
            e.preventDefault();
            window.location.href = 'pharmacy_dispense.php?prescription_id=<?php echo $prescription_id; ?>';
        }
        // Ctrl + E to edit (if permitted and pending)
        if (e.ctrlKey && e.keyCode === 69 && <?php echo $prescription['prescription_status'] == 'pending' && SimplePermission::any('pharmacy', 'edit_prescriptions') ? 'true' : 'false'; ?>) {
            e.preventDefault();
            window.location.href = 'pharmacy_prescription_edit.php?id=<?php echo $prescription_id; ?>';
        }
        // Escape to go back
        if (e.keyCode === 27) {
            window.location.href = 'pharmacy_prescriptions.php';
        }
    });

    // Auto-focus on search in modal
    $('#editNotesModal').on('shown.bs.modal', function () {
        $('#prescription_notes').focus();
    });
    
    // Auto-close alerts after 5 seconds
    setTimeout(function() {
        $('.alert').fadeOut('slow');
    }, 5000);
});
</script>

<style>
.avatar-circle {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto;
    font-size: 24px;
    font-weight: bold;
}
.avatar-circle .initials {
    font-size: 28px;
}
.stat-box {
    transition: all 0.3s ease;
}
.stat-box:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}
.bg-primary-light {
    background-color: rgba(13, 110, 253, 0.1);
}
.bg-success-light {
    background-color: rgba(25, 135, 84, 0.1);
}
.bg-danger-light {
    background-color: rgba(220, 53, 69, 0.1);
}
.bg-warning-light {
    background-color: rgba(255, 193, 7, 0.1);
}
.bg-info-light {
    background-color: rgba(23, 162, 184, 0.1);
}
.timeline {
    position: relative;
    padding-left: 30px;
}
.timeline-item {
    position: relative;
    margin-bottom: 20px;
}
.timeline-marker {
    position: absolute;
    left: -30px;
    top: 5px;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    border: 3px solid white;
}
.timeline-content {
    margin-left: 0;
}
.timeline-content small {
    font-size: 0.85em;
}
.card-title {
    font-size: 1.1rem;
    font-weight: 600;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
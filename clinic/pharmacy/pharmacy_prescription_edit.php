<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/audit_functions.php'; // Added for logging

$prescription_id = intval($_GET['id'] ?? 0);

// AUDIT LOG: Access attempt for editing prescription
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
    'description' => "Attempting to access prescription edit page for ID: " . $prescription_id,
    'status'      => 'ATTEMPT',
    'old_values'  => null,
    'new_values'  => null
]);

if (!$prescription_id) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Prescription ID not specified";
    
    // AUDIT LOG: Invalid prescription ID
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'ACCESS',
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

// Get prescription details with CORRECTED table references
$prescription = mysqli_fetch_assoc(mysqli_query($mysqli, "
    SELECT p.*, 
           pat.patient_id, pat.first_name, pat.last_name, pat.patient_mrn, pat.date_of_birth, 
           pat.sex, pat.phone_primary, pat.email, 
           doc.user_id as doctor_id, doc.user_name as doctor_name, 
           disp.user_name as dispensed_by_name,
           v.visit_id, v.visit_datetime, v.visit_number
    FROM prescriptions p
    LEFT JOIN patients pat ON p.prescription_patient_id = pat.patient_id
    LEFT JOIN users doc ON p.prescription_doctor_id = doc.user_id
    LEFT JOIN users disp ON p.prescription_dispensed_by = disp.user_id
    LEFT JOIN visits v ON p.prescription_visit_id = v.visit_id
    WHERE p.prescription_id = $prescription_id
"));

if (!$prescription) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Prescription not found";
    
    // AUDIT LOG: Prescription not found
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
        'description' => "Prescription ID " . $prescription_id . " not found",
        'status'      => 'FAILED',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    header("Location: pharmacy_prescriptions.php");
    exit;
}

// AUDIT LOG: Successful access to edit page
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
    'description' => "Accessed prescription edit page for prescription #" . $prescription_id . 
                    " (Patient: " . $prescription['first_name'] . " " . $prescription['last_name'] . ")",
    'status'      => 'SUCCESS',
    'old_values'  => null,
    'new_values'  => null
]);

// Check if prescription can be edited (only pending or active prescriptions can be edited)
if (!in_array($prescription['prescription_status'], ['pending', 'active'])) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Cannot edit prescription with status: " . $prescription['prescription_status'];
    
    // AUDIT LOG: Cannot edit due to status
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'EDIT',
        'module'      => 'Pharmacy',
        'table_name'  => 'prescriptions',
        'entity_type' => 'prescription',
        'record_id'   => $prescription_id,
        'patient_id'  => $prescription['patient_id'],
        'visit_id'    => $prescription['visit_id'] ?? null,
        'description' => "Cannot edit prescription #" . $prescription_id . " - status is " . $prescription['prescription_status'],
        'status'      => 'FAILED',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    header("Location: pharmacy_prescription_view.php?id=" . $prescription_id);
    exit;
}

// Get prescription items with drug and location-based inventory information
$items_result = mysqli_query($mysqli, "
    SELECT pi.*, 
           d.drug_id, d.drug_name, d.drug_generic_name, d.drug_form, d.drug_strength,
           ili.location_item_id as inventory_location_item_id, 
           ili.quantity as available_stock, 
           ili.batch_number, ili.expiry_date,
           ili.unit_cost, ili.selling_price as item_unit_price,
           i.item_id, i.item_name, i.item_brand, i.item_code, i.item_form as inventory_form, 
           i.item_unit_measure, i.item_low_stock_alert,
           loc.location_name, loc.location_type
    FROM prescription_items pi
    LEFT JOIN drugs d ON pi.pi_drug_id = d.drug_id
    LEFT JOIN inventory_location_items ili ON pi.pi_inventory_item_id = ili.location_item_id
    LEFT JOIN inventory_items i ON ili.item_id = i.item_id
    LEFT JOIN inventory_locations loc ON ili.location_id = loc.location_id
    WHERE pi.pi_prescription_id = $prescription_id
    ORDER BY pi.pi_id
");

$prescription_items = [];
while($item = mysqli_fetch_assoc($items_result)) {
    $prescription_items[] = $item;
}

// Get available drugs with location-based inventory for dropdown
$drugs_result = mysqli_query($mysqli, "
    SELECT DISTINCT
        d.drug_id, 
        d.drug_name, 
        d.drug_generic_name, 
        d.drug_form, 
        d.drug_strength,
        d.drug_manufacturer,
        ili.location_item_id as inventory_location_item_id,
        i.item_name as inventory_name,
        ili.selling_price as item_unit_price,
        ili.quantity as stock_quantity,
        CASE 
            WHEN ili.quantity <= 0 THEN 'Out of Stock'
            WHEN ili.quantity <= i.item_low_stock_alert THEN 'Low Stock'
            ELSE 'In Stock'
        END as item_status,
        ili.batch_number,
        ili.expiry_date,
        i.item_unit_measure,
        loc.location_name,
        loc.location_id,
        loc.location_type,
        -- Get the best available inventory item (prefer in-stock items from main pharmacy)
        CASE 
            WHEN ili.quantity > 0 AND loc.location_type = 'pharmacy' THEN 1
            WHEN ili.quantity > 0 AND loc.location_type = 'dispensary' THEN 2
            WHEN ili.quantity > 0 THEN 3
            ELSE 4
        END as inventory_priority
    FROM drugs d
    LEFT JOIN inventory_items i ON d.drug_id = i.drug_id AND i.item_status != 'Discontinued'
    LEFT JOIN inventory_location_items ili ON i.item_id = ili.item_id 
        AND ili.quantity > 0 
        AND (ili.expiry_date IS NULL OR ili.expiry_date > CURDATE())
    LEFT JOIN inventory_locations loc ON ili.location_id = loc.location_id
    WHERE d.drug_is_active = 1 
    AND d.drug_archived_at IS NULL 
    ORDER BY 
        d.drug_name,
        inventory_priority,
        ili.expiry_date ASC
");

// Group drugs by drug_id to show available location inventory options
$drugs_grouped = [];
while($drug = mysqli_fetch_assoc($drugs_result)) {
    $drug_id = $drug['drug_id'];
    
    if (!isset($drugs_grouped[$drug_id])) {
        $drugs_grouped[$drug_id] = [
            'drug_id' => $drug['drug_id'],
            'drug_name' => $drug['drug_name'],
            'drug_generic_name' => $drug['drug_generic_name'],
            'drug_form' => $drug['drug_form'],
            'drug_strength' => $drug['drug_strength'],
            'drug_manufacturer' => $drug['drug_manufacturer'],
            'inventory_options' => []
        ];
    }
    
    // Add location inventory option if available
    if ($drug['inventory_location_item_id']) {
        $drugs_grouped[$drug_id]['inventory_options'][] = [
            'inventory_location_item_id' => $drug['inventory_location_item_id'],
            'item_name' => $drug['inventory_name'],
            'item_unit_price' => $drug['item_unit_price'],
            'stock_quantity' => $drug['stock_quantity'],
            'item_status' => $drug['item_status'],
            'batch_number' => $drug['batch_number'],
            'expiry_date' => $drug['expiry_date'],
            'unit_measure' => $drug['item_unit_measure'],
            'location_name' => $drug['location_name'],
            'location_id' => $drug['location_id'],
            'location_type' => $drug['location_type']
        ];
    }
}

// Common dosage instructions
$common_instructions = [
    "Take once daily",
    "Take twice daily",
    "Take three times daily",
    "Take four times daily",
    "Take as needed for pain",
    "Take with food",
    "Take on empty stomach",
    "Take at bedtime",
    "Apply topically twice daily",
    "Use inhaler as directed",
    "Chew tablet before swallowing",
    "Dissolve in water before taking"
];

// Common frequencies
$common_frequencies = [
    "Once daily",
    "Twice daily", 
    "Three times daily",
    "Four times daily",
    "Every 4 hours",
    "Every 6 hours",
    "Every 8 hours",
    "Every 12 hours",
    "As needed",
    "At bedtime"
];

// Priority options
$priority_options = [
    'routine' => 'Routine',
    'urgent' => 'Urgent',
    'emergency' => 'Emergency'
];

// Status options (for display only - field will be disabled)
$status_options = [
    'pending' => 'Pending',
    'active' => 'Active', 
    'dispensed' => 'Dispensed',
    'partial' => 'Partially Dispensed',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled'
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $expiry_date = sanitizeInput($_POST['expiry_date']);
    $priority = sanitizeInput($_POST['priority']);
    $refills = intval($_POST['refills']);
    $notes = sanitizeInput($_POST['notes']);
    
    // Store old prescription data for audit log
    $old_prescription_data = [
        'prescription_expiry_date' => $prescription['prescription_expiry_date'],
        'prescription_priority' => $prescription['prescription_priority'],
        'prescription_refills' => $prescription['prescription_refills'],
        'prescription_notes' => $prescription['prescription_notes']
    ];
    
    // Store old items data for audit log
    $old_items_data = $prescription_items;
    
    // AUDIT LOG: Attempt to update prescription
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'UPDATE',
        'module'      => 'Pharmacy',
        'table_name'  => 'prescriptions',
        'entity_type' => 'prescription',
        'record_id'   => $prescription_id,
        'patient_id'  => $prescription['patient_id'],
        'visit_id'    => $prescription['visit_id'] ?? null,
        'description' => "Attempting to update prescription #" . $prescription_id . 
                        " for patient: " . $prescription['first_name'] . " " . $prescription['last_name'],
        'status'      => 'ATTEMPT',
        'old_values'  => json_encode([
            'prescription_data' => $old_prescription_data,
            'items_count' => count($old_items_data)
        ]),
        'new_values'  => null
    ]);
    
    // Start transaction
    mysqli_begin_transaction($mysqli);
    
    try {
        // Update prescription
        $update_sql = mysqli_query($mysqli, "
            UPDATE prescriptions SET
                prescription_expiry_date = '$expiry_date',
                prescription_priority = '$priority',
                prescription_refills = $refills,
                prescription_notes = '$notes',
                prescription_updated_at = NOW()
            WHERE prescription_id = $prescription_id
        ");
        
        if (!$update_sql) {
            throw new Exception('Error updating prescription: ' . mysqli_error($mysqli));
        }
        
        // Get old items before deletion for audit logging
        $old_items_sql = "SELECT * FROM prescription_items WHERE pi_prescription_id = $prescription_id";
        $old_items_result = mysqli_query($mysqli, $old_items_sql);
        $deleted_items = [];
        while($old_item = mysqli_fetch_assoc($old_items_result)) {
            $deleted_items[] = $old_item;
        }
        
        // Delete existing prescription items
        $delete_sql = mysqli_query($mysqli, "DELETE FROM prescription_items WHERE pi_prescription_id = $prescription_id");
        if (!$delete_sql) {
            throw new Exception('Error clearing existing medications: ' . mysqli_error($mysqli));
        }
        
        // AUDIT LOG: Old items deleted
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'DELETE_ITEMS',
            'module'      => 'Pharmacy',
            'table_name'  => 'prescription_items',
            'entity_type' => 'prescription_item',
            'record_id'   => $prescription_id,
            'patient_id'  => $prescription['patient_id'],
            'visit_id'    => $prescription['visit_id'] ?? null,
            'description' => "Deleted " . count($deleted_items) . " old prescription items for prescription #" . $prescription_id,
            'status'      => 'SUCCESS',
            'old_values'  => json_encode($deleted_items),
            'new_values'  => null
        ]);
        
        // Insert updated prescription items
        $valid_items = 0;
        $total_prescription_cost = 0;
        $new_items = [];
        
        if (isset($_POST['drug_id'])) {
            foreach ($_POST['drug_id'] as $index => $drug_id) {
                $drug_id = intval($drug_id);
                $quantity = isset($_POST['quantity'][$index]) ? intval($_POST['quantity'][$index]) : 1;
                $dosage = isset($_POST['dosage'][$index]) ? sanitizeInput($_POST['dosage'][$index]) : '';
                $frequency = isset($_POST['frequency'][$index]) ? sanitizeInput($_POST['frequency'][$index]) : '';
                $duration = isset($_POST['duration'][$index]) ? sanitizeInput($_POST['duration'][$index]) : '';
                $instructions = isset($_POST['instructions'][$index]) ? sanitizeInput($_POST['instructions'][$index]) : '';
                $inventory_location_item_id = isset($_POST['location_item_id'][$index]) ? intval($_POST['location_item_id'][$index]) : 0;
                $unit_price = isset($_POST['unit_price'][$index]) ? floatval($_POST['unit_price'][$index]) : 0;
                
                if ($drug_id > 0 && $quantity > 0 && $inventory_location_item_id > 0) {
                    $total_price = $unit_price * $quantity;
                    $total_prescription_cost += $total_price;
                    
                    $insert_item_sql = mysqli_query(
                        $mysqli,
                        "INSERT INTO prescription_items SET
                            pi_prescription_id = $prescription_id,
                            pi_drug_id = $drug_id,
                            pi_quantity = $quantity,
                            pi_dosage = '$dosage',
                            pi_frequency = '$frequency',
                            pi_duration = '$duration',
                            pi_instructions = '$instructions',
                            pi_unit_price = $unit_price,
                            pi_total_price = $total_price,
                            pi_inventory_item_id = $inventory_location_item_id"
                    );
                    
                    if (!$insert_item_sql) {
                        throw new Exception('Error adding prescription item: ' . mysqli_error($mysqli));
                    }
                    
                    $new_item_id = mysqli_insert_id($mysqli);
                    
                    // AUDIT LOG: New item added
                    audit_log($mysqli, [
                        'user_id'     => $_SESSION['user_id'] ?? null,
                        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                        'action'      => 'ADD_ITEM',
                        'module'      => 'Pharmacy',
                        'table_name'  => 'prescription_items',
                        'entity_type' => 'prescription_item',
                        'record_id'   => $new_item_id,
                        'patient_id'  => $prescription['patient_id'],
                        'visit_id'    => $prescription['visit_id'] ?? null,
                        'description' => "Added prescription item for drug ID: " . $drug_id . 
                                        " (Quantity: " . $quantity . ", Price: " . $unit_price . ")",
                        'status'      => 'SUCCESS',
                        'old_values'  => null,
                        'new_values'  => json_encode([
                            'drug_id' => $drug_id,
                            'quantity' => $quantity,
                            'dosage' => $dosage,
                            'frequency' => $frequency,
                            'duration' => $duration,
                            'unit_price' => $unit_price,
                            'total_price' => $total_price,
                            'inventory_item_id' => $inventory_location_item_id
                        ])
                    ]);
                    
                    $new_items[] = [
                        'item_id' => $new_item_id,
                        'drug_id' => $drug_id,
                        'quantity' => $quantity,
                        'unit_price' => $unit_price
                    ];
                    
                    $valid_items++;
                }
            }
        }
        
        if ($valid_items === 0) {
            throw new Exception('No valid medication items were added');
        }
        
        // Update prescription with total cost
        mysqli_query($mysqli, 
            "UPDATE prescriptions SET prescription_total_cost = $total_prescription_cost 
             WHERE prescription_id = $prescription_id"
        );
        
        // Commit transaction
        mysqli_commit($mysqli);
        
        // AUDIT LOG: Prescription update completed
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'UPDATE',
            'module'      => 'Pharmacy',
            'table_name'  => 'prescriptions',
            'entity_type' => 'prescription',
            'record_id'   => $prescription_id,
            'patient_id'  => $prescription['patient_id'],
            'visit_id'    => $prescription['visit_id'] ?? null,
            'description' => "Prescription #" . $prescription_id . " updated successfully. " . 
                            "Added " . $valid_items . " medication(s). " .
                            "Total cost: " . $total_prescription_cost,
            'status'      => 'SUCCESS',
            'old_values'  => json_encode([
                'prescription_data' => $old_prescription_data,
                'old_items_count' => count($old_items_data)
            ]),
            'new_values'  => json_encode([
                'prescription_data' => [
                    'prescription_expiry_date' => $expiry_date,
                    'prescription_priority' => $priority,
                    'prescription_refills' => $refills,
                    'prescription_notes' => substr($notes, 0, 100)
                ],
                'new_items_count' => $valid_items,
                'total_cost' => $total_prescription_cost
            ])
        ]);

        // Log the activity in activity_logs (existing log)
        mysqli_query(
            $mysqli,
            "INSERT INTO activity_logs SET
                activity_description = 'Updated prescription #" . $prescription_id . " with " . $valid_items . " medication(s)',
                activity_created_by = $session_user_id,
                activity_date = NOW()"
        );
        
        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Prescription updated successfully with " . $valid_items . " medication(s)!";
        
        header("Location: pharmacy_prescription_view.php?id=$prescription_id");
        exit;
        
    } catch (Exception $e) {
        // Rollback transaction on error
        mysqli_rollback($mysqli);
        
        // AUDIT LOG: Prescription update failed
        audit_log($mysqli, [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
            'action'      => 'UPDATE',
            'module'      => 'Pharmacy',
            'table_name'  => 'prescriptions',
            'entity_type' => 'prescription',
            'record_id'   => $prescription_id,
            'patient_id'  => $prescription['patient_id'],
            'visit_id'    => $prescription['visit_id'] ?? null,
            'description' => "Failed to update prescription #" . $prescription_id . 
                            ". Error: " . $e->getMessage(),
            'status'      => 'FAILED',
            'old_values'  => json_encode([
                'prescription_data' => $old_prescription_data,
                'old_items_count' => count($old_items_data)
            ]),
            'new_values'  => null
        ]);
        
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = $e->getMessage();
    }
}

// Calculate patient age
$patient_age = '';
if ($prescription['date_of_birth']) {
    $age = date_diff(date_create($prescription['date_of_birth']), date_create('today'))->y;
    $patient_age = "$age years";
}

// Calculate totals
$total_cost = 0;
$total_quantity = 0;
foreach ($prescription_items as $item) {
    $total_cost += floatval($item['pi_total_price']);
    $total_quantity += intval($item['pi_quantity']);
}
?>

<div class="card">
    <div class="card-header bg-warning py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-edit mr-2"></i>
                Edit Prescription: #<?php echo $prescription_id; ?>
            </h3>
            <div class="card-tools">
                <a href="pharmacy_prescription_view.php?id=<?php echo $prescription_id; ?>" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Prescription
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

        <!-- Prescription Information Header -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="btn-toolbar justify-content-between">
                    <div class="btn-group">
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Prescription:</strong> 
                            <span class="badge badge-info ml-2">#<?php echo $prescription_id; ?></span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Patient:</strong> 
                            <span class="badge badge-success ml-2">
                                <?php echo htmlspecialchars($prescription['first_name'] . ' ' . $prescription['last_name']); ?>
                            </span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>MRN:</strong> 
                            <span class="badge badge-primary ml-2"><?php echo htmlspecialchars($prescription['patient_mrn']); ?></span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Status:</strong> 
                            <?php
                            $status_badge = '';
                            switch($prescription['prescription_status']) {
                                case 'pending': $status_badge = 'warning'; break;
                                case 'active': $status_badge = 'info'; break;
                                case 'dispensed': $status_badge = 'success'; break;
                                case 'partial': $status_badge = 'info'; break;
                                case 'completed': $status_badge = 'secondary'; break;
                                case 'cancelled': $status_badge = 'danger'; break;
                                default: $status_badge = 'secondary';
                            }
                            ?>
                            <span class="badge badge-<?php echo $status_badge; ?> ml-2">
                                <?php echo ucfirst(str_replace('_', ' ', $prescription['prescription_status'])); ?>
                            </span>
                        </span>
                    </div>
                    <div class="btn-group">
                        <a href="pharmacy_prescription_view.php?id=<?php echo $prescription_id; ?>" class="btn btn-secondary">
                            <i class="fas fa-times mr-2"></i>Cancel
                        </a>
                        <button type="submit" form="editPrescriptionForm" class="btn btn-primary">
                            <i class="fas fa-save mr-2"></i>Save Changes
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <form method="POST" action="" id="editPrescriptionForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <div class="row">
                <!-- Left Column: Prescription Details -->
                <div class="col-md-8">
                    <!-- Prescription Information Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-info-circle mr-2"></i>Prescription Information</h4>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="font-weight-bold">Patient</label>
                                        <div class="form-control bg-light">
                                            <?php echo htmlspecialchars($prescription['first_name'] . ' ' . $prescription['last_name']); ?>
                                            (<?php echo htmlspecialchars($prescription['patient_mrn'] ?? 'NA'); ?>)
                                        </div>
                                        <input type="hidden" name="patient_id" value="<?php echo $prescription['prescription_patient_id']; ?>">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="font-weight-bold">Prescribing Doctor</label>
                                        <div class="form-control bg-light">
                                            <?php echo htmlspecialchars($prescription['doctor_name']); ?>
                                        </div>
                                        <input type="hidden" name="doctor_id" value="<?php echo $prescription['doctor_id']; ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <?php if ($prescription['visit_datetime']): ?>
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="form-group">
                                        <label class="font-weight-bold">Visit Information</label>
                                        <div class="form-control bg-light">
                                            Visit #<?php echo htmlspecialchars($prescription['visit_number'] ?? ''); ?> - 
                                            <?php echo date('M j, Y g:i A', strtotime($prescription['visit_datetime'])); ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Prescription Date</label>
                                        <div class="form-control bg-light">
                                            <?php echo date('M j, Y g:i A', strtotime($prescription['prescription_date'])); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Expiry Date</label>
                                        <input type="date" class="form-control" name="expiry_date" 
                                               value="<?php echo $prescription['prescription_expiry_date']; ?>">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Refills Allowed</label>
                                        <input type="number" class="form-control" name="refills" 
                                               value="<?php echo $prescription['prescription_refills']; ?>" min="0" max="12">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="required">Priority</label>
                                        <select class="form-control select2" name="priority" required>
                                            <?php foreach($priority_options as $value => $label): ?>
                                                <option value="<?php echo $value; ?>" 
                                                    <?php echo $prescription['prescription_priority'] == $value ? 'selected' : ''; ?>>
                                                    <?php echo $label; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Status</label>
                                        <select class="form-control bg-light" name="status" disabled>
                                            <?php foreach($status_options as $value => $label): ?>
                                                <option value="<?php echo $value; ?>" 
                                                    <?php echo $prescription['prescription_status'] == $value ? 'selected' : ''; ?>>
                                                    <?php echo $label; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="form-text text-muted">Status cannot be changed from edit page</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Prescription Medications Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2 d-flex justify-content-between align-items-center">
                            <h4 class="card-title mb-0"><i class="fas fa-pills mr-2"></i>Prescription Medications</h4>
                            <div class="btn-group">
                                <button type="button" class="btn btn-sm btn-success" onclick="addMedicationRow()">
                                    <i class="fas fa-plus mr-1"></i>Add Medication
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div id="medications-container">
                                <!-- Medication rows will be added here dynamically -->
                                <div class="text-center py-4 text-muted" id="no_medications_message">
                                    <i class="fas fa-pills fa-2x mb-2"></i>
                                    <p>No medications added yet. Click "Add Medication" to start.</p>
                                </div>
                            </div>
                            
                            <!-- Instructions Section -->
                            <div class="row mt-3">
                                <div class="col-md-12">
                                    <div class="form-group">
                                        <label>Instructions</label>
                                        <select class="form-control" id="instructions_select" onchange="handleInstructionsChange(this)">
                                            <option value="">- Select Instructions -</option>
                                            <?php foreach($common_instructions as $instruction): ?>
                                                <option value="<?php echo $instruction; ?>"><?php echo $instruction; ?></option>
                                            <?php endforeach; ?>
                                            <option value="custom">Custom Instructions...</option>
                                        </select>
                                        <textarea class="form-control mt-2" id="custom_instructions" name="instructions[]" 
                                                  rows="2" placeholder="Custom instructions..." style="display: none;"></textarea>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Medications Summary -->
                            <div class="row mt-3">
                                <div class="col-md-6">
                                    <div class="small text-muted">
                                        <i class="fas fa-info-circle mr-1"></i>
                                        <span id="medications_count"><?php echo count($prescription_items); ?></span> medications in prescription
                                    </div>
                                </div>
                                <div class="col-md-6 text-right">
                                    <div class="small text-muted">
                                        Total Quantity: <strong id="total_quantity"><?php echo $total_quantity; ?></strong> | 
                                        Total Cost: <strong id="total_cost"><?php echo numfmt_format_currency($currency_format, $total_cost, $session_company_currency); ?></strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Doctor's Notes Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-sticky-note mr-2"></i>Doctor's Notes</h4>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <textarea class="form-control" name="notes" rows="4" 
                                          placeholder="Additional notes, instructions, or special considerations..."><?php echo htmlspecialchars($prescription['prescription_notes']); ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Patient Info & Actions -->
                <div class="col-md-4">
                    <!-- Quick Actions Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-bolt mr-2"></i>Quick Actions</h4>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <button type="submit" form="editPrescriptionForm" class="btn btn-primary btn-lg">
                                    <i class="fas fa-save mr-2"></i>Update Prescription
                                </button>
                                <a href="pharmacy_prescription_view.php?id=<?php echo $prescription_id; ?>" 
                                   class="btn btn-outline-secondary">
                                    <i class="fas fa-times mr-2"></i>Cancel
                                </a>
                                <a href="pharmacy_prescriptions.php" class="btn btn-outline-primary">
                                    <i class="fas fa-arrow-left mr-2"></i>Back to List
                                </a>
                                <?php if ($prescription['prescription_status'] == 'pending'): ?>
                                <button type="submit" class="btn btn-success" onclick="return confirm('Save and mark as active?')" name="save_and_activate">
                                    <i class="fas fa-check-circle mr-2"></i>Save & Activate
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Patient Information Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-user mr-2"></i>Patient Information</h4>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-3">
                                <i class="fas fa-user-injured fa-3x text-info mb-2"></i>
                                <h5><?php echo htmlspecialchars($prescription['first_name'] . ' ' . $prescription['last_name']); ?></h5>
                            </div>
                            <hr>
                            <div class="small">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>MRN:</span>
                                    <span class="font-weight-bold"><?php echo htmlspecialchars($prescription['patient_mrn'] ?? 'NA'); ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Age:</span>
                                    <span class="font-weight-bold"><?php echo $patient_age; ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Gender:</span>
                                    <span class="font-weight-bold"><?php echo htmlspecialchars($prescription['sex'] ?: 'Not specified'); ?></span>
                                </div>
                                <?php if ($prescription['phone_primary']): ?>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Phone:</span>
                                    <span class="font-weight-bold"><?php echo htmlspecialchars($prescription['phone_primary']); ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if ($prescription['email']): ?>
                                <div class="d-flex justify-content-between">
                                    <span>Email:</span>
                                    <span class="font-weight-bold"><?php echo htmlspecialchars($prescription['email']); ?></span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Prescription Summary Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-eye mr-2"></i>Prescription Summary</h4>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-3">
                                <i class="fas fa-prescription fa-3x text-warning mb-2"></i>
                                <h5>Prescription #<?php echo $prescription_id; ?></h5>
                                <div class="text-muted" id="preview_medication_count"><?php echo count($prescription_items); ?> medications</div>
                            </div>
                            <hr>
                            <div class="small">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Priority:</span>
                                    <span class="badge badge-<?php 
                                        echo $prescription['prescription_priority'] == 'routine' ? 'secondary' : 
                                             ($prescription['prescription_priority'] == 'urgent' ? 'warning' : 'danger'); 
                                    ?>" id="preview_priority">
                                        <?php echo ucfirst($prescription['prescription_priority']); ?>
                                    </span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Status:</span>
                                    <span class="badge badge-<?php 
                                        echo $prescription['prescription_status'] == 'pending' ? 'warning' : 
                                             ($prescription['prescription_status'] == 'dispensed' ? 'success' : 
                                             ($prescription['prescription_status'] == 'partial' ? 'info' : 'danger')); 
                                    ?>" id="preview_status">
                                        <?php echo ucfirst($prescription['prescription_status']); ?>
                                    </span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Refills:</span>
                                    <span class="font-weight-bold" id="preview_refills"><?php echo $prescription['prescription_refills']; ?></span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Expiry:</span>
                                    <span class="font-weight-bold" id="preview_expiry"><?php echo $prescription['prescription_expiry_date'] ? date('M j, Y', strtotime($prescription['prescription_expiry_date'])) : 'Not set'; ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <a href="pharmacy_prescription_view.php?id=<?php echo $prescription_id; ?>" class="btn btn-secondary">
                                        <i class="fas fa-times mr-2"></i>Discard Changes
                                    </a>
                                    <a href="pharmacy_prescription_view.php?id=<?php echo $prescription_id; ?>" class="btn btn-outline-secondary">
                                        <i class="fas fa-eye mr-2"></i>Preview
                                    </a>
                                </div>
                                <div>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save mr-2"></i>Save Changes
                                    </button>
                                    <?php if ($prescription['prescription_status'] == 'pending'): ?>
                                    <button type="submit" class="btn btn-success" onclick="return confirm('Save and activate this prescription?')" name="save_and_activate">
                                        <i class="fas fa-check-circle mr-2"></i>Save & Activate
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<style>
.required:after {
    content: " *";
    color: #dc3545;
}
.form-group {
    margin-bottom: 1rem;
}
.medication-row {
    border: 1px solid #dee2e6;
    border-radius: 0.25rem;
    padding: 1rem;
    margin-bottom: 1rem;
    background-color: #f8f9fa;
}
.location-info {
    font-size: 0.875rem;
}
.select2-container .select2-selection--single {
    height: 38px !important;
}
.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 36px !important;
}
.unit-price-display, .total-price {
    background-color: #e9ecef !important;
}
.badge {
    font-size: 0.7em;
}
</style>

<script>
// Store drugs data
const drugsData = <?php echo json_encode($drugs_grouped); ?>;

let rowCount = <?php echo count($prescription_items); ?>;

// Make functions globally accessible
window.addMedicationRow = function(drugId = 0, inventoryLocationItemId = 0, quantity = 1, dosage = '', frequency = '', duration = '', instructions = '') {
    try {
        rowCount++;
        const container = document.getElementById('medications-container');
        const noMedicationsMessage = document.getElementById('no_medications_message');
        
        // Hide no medications message
        if (noMedicationsMessage) {
            noMedicationsMessage.style.display = 'none';
        }
        
        // Create options for drug dropdown
        let drugOptions = '<option value="">- Select Drug -</option>';
        Object.keys(drugsData).forEach(drugIdKey => {
            const drug = drugsData[drugIdKey];
            const selected = drug.drug_id == drugId ? 'selected' : '';
            const displayText = `${drug.drug_name} ${drug.drug_generic_name ? '(' + drug.drug_generic_name + ')' : ''}`;
            drugOptions += `<option value="${drug.drug_id}" ${selected}>${displayText}</option>`;
        });

        // Create location inventory options for the selected drug
        let inventoryOptions = '<option value="">- Select Location & Batch -</option>';
        if (drugId > 0 && drugsData[drugId]) {
            drugsData[drugId].inventory_options.forEach(inventory => {
                const selected = inventory.inventory_location_item_id == inventoryLocationItemId ? 'selected' : '';
                const stockStatus = getStockStatus(inventory.item_status, inventory.stock_quantity);
                const stockBadge = getStockBadge(stockStatus);
                inventoryOptions += `
                    <option value="${inventory.inventory_location_item_id}" ${selected}
                            data-price="${inventory.item_unit_price}"
                            data-stock="${inventory.stock_quantity}"
                            data-status="${inventory.item_status}"
                            data-batch="${inventory.batch_number || 'N/A'}"
                            data-expiry="${inventory.expiry_date || 'N/A'}"
                            data-location="${inventory.location_name || 'N/A'}"
                            data-location-id="${inventory.location_id}"
                            data-unit="${inventory.unit_measure}">
                        ${stockBadge} ${inventory.location_name} - ${inventory.stock_quantity} units 
                        ${inventory.batch_number ? '- Batch: ' + inventory.batch_number : ''}
                        ${inventory.expiry_date ? '- Exp: ' + new Date(inventory.expiry_date).toLocaleDateString() : ''}
                    </option>
                `;
            });
        }

        // Create frequency options
        let frequencyOptions = '<option value="">- Select -</option>';
        <?php foreach($common_frequencies as $freq): ?>
            frequencyOptions += `<option value="<?php echo $freq; ?>" ${frequency === '<?php echo $freq; ?>' ? 'selected' : ''}><?php echo $freq; ?></option>`;
        <?php endforeach; ?>
        frequencyOptions += '<option value="custom">Custom...</option>';
        
        const row = document.createElement('div');
        row.id = `row-${rowCount}`;
        row.className = 'medication-row border-bottom pb-3 mb-3';
        row.innerHTML = `
            <input type="hidden" name="location_item_id[]" class="location-item-id" value="${inventoryLocationItemId}">
            <input type="hidden" name="unit_price[]" class="unit-price-input" value="0">
            
            <div class="row">
                <div class="col-md-5">
                    <div class="form-group">
                        <label>Drug *</label>
                        <select class="form-control select2-drug" name="drug_id[]" required onchange="updateDrugInfo(this)">
                            ${drugOptions}
                        </select>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label>Quantity *</label>
                        <input type="number" class="form-control quantity-input" name="quantity[]" 
                               value="${quantity}" min="1" required onchange="updateSummary()">
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-group">
                        <label>Unit Price</label>
                        <input type="number" class="form-control unit-price-display" readonly>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-group">
                        <label>Total</label>
                        <input type="text" class="form-control total-price" readonly>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label>Location & Batch *</label>
                        <select class="form-control select-inventory" name="inventory_select[]" onchange="selectLocationInventory(this)" required>
                            ${inventoryOptions}
                        </select>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label>&nbsp;</label>
                        <button type="button" class="btn btn-danger btn-block" onclick="removeMedicationRow(${rowCount})">
                            <i class="fas fa-trash mr-1"></i>Remove
                        </button>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-3">
                    <div class="form-group">
                        <label>Dosage</label>
                        <input type="text" class="form-control" name="dosage[]" 
                               value="${dosage}" placeholder="e.g., 500mg">
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label>Frequency</label>
                        <select class="form-control frequency-select" name="frequency[]" onchange="handleFrequencyChange(this)">
                            ${frequencyOptions}
                        </select>
                        <input type="text" class="form-control custom-frequency mt-1" name="custom_frequency[]" 
                               placeholder="Custom frequency..." style="display: none;">
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label>Duration</label>
                        <input type="text" class="form-control" name="duration[]" 
                               value="${duration}" placeholder="e.g., 7 days">
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label>Instructions</label>
                        <textarea class="form-control" name="instructions[]" rows="1" 
                                  placeholder="Special instructions">${instructions}</textarea>
                    </div>
                </div>
            </div>
            
            <div class="location-info bg-light p-2 rounded mt-2" style="display: none;">
                <small class="text-muted">
                    <i class="fas fa-map-marker-alt mr-1"></i>
                    <span class="location-info-text"></span>
                    <span class="stock-info"></span>
                    <span class="batch-info"></span>
                    <span class="expiry-info"></span>
                </small>
            </div>
        `;
        
        container.appendChild(row);
        
        // Initialize Select2 for the new dropdowns
        $(row).find('.select2-drug').select2({
            placeholder: "- Select Drug -",
            allowClear: true
        });
        
        // Update drug info for pre-selected drugs
        if (drugId > 0) {
            updateDrugInfo(row.querySelector('.select2-drug'));
            if (inventoryLocationItemId > 0) {
                const inventorySelect = row.querySelector('.select-inventory');
                inventorySelect.value = inventoryLocationItemId;
                selectLocationInventory(inventorySelect);
            }
        }
        
        updateSummary();
        updatePreview();
    } catch (error) {
        console.error('Error adding medication row:', error);
        alert('Error adding medication. Please try again.');
    }
}

window.removeMedicationRow = function(rowId) {
    const row = document.getElementById(`row-${rowId}`);
    if (row) {
        row.remove();
        updateSummary();
        updatePreview();
    }
    
    // Show no medications message if no medications left
    if (document.querySelectorAll('.medication-row').length === 0) {
        const noMedicationsMessage = document.getElementById('no_medications_message');
        if (noMedicationsMessage) {
            noMedicationsMessage.style.display = 'block';
        }
    }
}

window.updateDrugInfo = function(selectElement) {
    const drugId = selectElement.value;
    const row = selectElement.closest('.medication-row');
    const inventorySelect = row.querySelector('.select-inventory');
    
    // Clear existing inventory options
    inventorySelect.innerHTML = '<option value="">- Select Location & Batch -</option>';
    
    if (drugId && drugsData[drugId]) {
        // Populate location inventory options for selected drug
        drugsData[drugId].inventory_options.forEach(inventory => {
            const stockStatus = getStockStatus(inventory.item_status, inventory.stock_quantity);
            const stockBadge = getStockBadge(stockStatus);
            const option = document.createElement('option');
            option.value = inventory.inventory_location_item_id;
            option.innerHTML = `${stockBadge} ${inventory.location_name} - ${inventory.stock_quantity} units `;
            if (inventory.batch_number) option.innerHTML += `- Batch: ${inventory.batch_number} `;
            if (inventory.expiry_date) option.innerHTML += `- Exp: ${new Date(inventory.expiry_date).toLocaleDateString()}`;
            
            // Store additional data as attributes
            option.setAttribute('data-price', inventory.item_unit_price);
            option.setAttribute('data-stock', inventory.stock_quantity);
            option.setAttribute('data-status', inventory.item_status);
            option.setAttribute('data-batch', inventory.batch_number || 'N/A');
            option.setAttribute('data-expiry', inventory.expiry_date || 'N/A');
            option.setAttribute('data-location', inventory.location_name || 'N/A');
            option.setAttribute('data-location-id', inventory.location_id);
            option.setAttribute('data-unit', inventory.unit_measure);
            
            inventorySelect.appendChild(option);
        });
    }
    
    // Reset inventory-related fields
    resetLocationInventoryFields(row);
}

window.selectLocationInventory = function(selectElement) {
    const row = selectElement.closest('.medication-row');
    const selectedOption = selectElement.options[selectElement.selectedIndex];
    const locationInfo = row.querySelector('.location-info');
    const unitPriceDisplay = row.querySelector('.unit-price-display');
    const unitPriceInput = row.querySelector('.unit-price-input');
    const locationItemId = row.querySelector('.location-item-id');
    const quantityInput = row.querySelector('.quantity-input');
    
    if (selectedOption.value) {
        const price = parseFloat(selectedOption.getAttribute('data-price')) || 0;
        const stock = parseInt(selectedOption.getAttribute('data-stock')) || 0;
        const status = selectedOption.getAttribute('data-status');
        const batch = selectedOption.getAttribute('data-batch');
        const expiry = selectedOption.getAttribute('data-expiry');
        const location = selectedOption.getAttribute('data-location');
        const locationId = selectedOption.getAttribute('data-location-id');
        const unit = selectedOption.getAttribute('data-unit');
        
        // Update hidden fields
        locationItemId.value = selectedOption.value;
        unitPriceInput.value = price;
        
        // Update display fields
        unitPriceDisplay.value = price.toFixed(2);
        
        // Show location inventory information
        locationInfo.style.display = 'block';
        locationInfo.querySelector('.location-info-text').innerHTML = 
            `<strong>Location:</strong> ${location}`;
        locationInfo.querySelector('.stock-info').innerHTML = 
            ` | <strong>Stock:</strong> ${stock} ${unit} (${status})`;
        locationInfo.querySelector('.batch-info').innerHTML = 
            batch !== 'N/A' ? ` | <strong>Batch:</strong> ${batch}` : '';
        locationInfo.querySelector('.expiry-info').innerHTML = 
            expiry !== 'N/A' ? ` | <strong>Expiry:</strong> ${new Date(expiry).toLocaleDateString()}` : '';
        
        // Update quantity validation
        quantityInput.setAttribute('max', stock);
        if (parseInt(quantityInput.value) > stock) {
            quantityInput.value = stock;
            showAlert(`Quantity reduced to available stock in ${location}: ${stock}`, 'warning');
        }
        
        // Update total price
        updateRowTotal(row);
    } else {
        resetLocationInventoryFields(row);
    }
}

function resetLocationInventoryFields(row) {
    const unitPriceDisplay = row.querySelector('.unit-price-display');
    const unitPriceInput = row.querySelector('.unit-price-input');
    const totalPrice = row.querySelector('.total-price');
    const locationInfo = row.querySelector('.location-info');
    const locationItemId = row.querySelector('.location-item-id');
    
    unitPriceDisplay.value = '';
    unitPriceInput.value = '0';
    totalPrice.value = '<?php echo $currency_symbol; ?>0.00';
    locationInfo.style.display = 'none';
    locationItemId.value = '';
}

window.updateRowTotal = function(row) {
    const quantityInput = row.querySelector('.quantity-input');
    const unitPriceInput = row.querySelector('.unit-price-input');
    const totalPrice = row.querySelector('.total-price');
    
    const quantity = parseInt(quantityInput.value) || 0;
    const unitPrice = parseFloat(unitPriceInput.value) || 0;
    const total = quantity * unitPrice;
    
    totalPrice.value = '<?php echo $currency_symbol; ?>' + total.toFixed(2);
    updateSummary();
}

window.updateSummary = function() {
    let totalQuantity = 0;
    let totalCost = 0;
    let medicationCount = 0;
    
    document.querySelectorAll('.medication-row').forEach(row => {
        const quantity = parseInt(row.querySelector('.quantity-input').value) || 0;
        const unitPrice = parseFloat(row.querySelector('.unit-price-input').value) || 0;
        
        totalQuantity += quantity;
        totalCost += quantity * unitPrice;
        medicationCount++;
    });
    
    // Update summary display
    document.getElementById('medications_count').textContent = medicationCount;
    document.getElementById('total_quantity').textContent = totalQuantity;
    document.getElementById('total_cost').textContent = '<?php echo $currency_symbol; ?>' + totalCost.toFixed(2);
    
    // Update preview
    updatePreview();
}

window.updatePreview = function() {
    const medicationCount = document.querySelectorAll('.medication-row').length;
    document.getElementById('preview_medication_count').textContent = `${medicationCount} medications`;
    
    // Update priority preview
    const prioritySelect = document.querySelector('select[name="priority"]');
    const priorityPreview = document.getElementById('preview_priority');
    if (prioritySelect && priorityPreview) {
        const selectedOption = prioritySelect.options[prioritySelect.selectedIndex];
        priorityPreview.textContent = selectedOption.text;
        priorityPreview.className = `badge badge-${
            prioritySelect.value === 'routine' ? 'secondary' : 
            prioritySelect.value === 'urgent' ? 'warning' : 'danger'
        }`;
    }
    
    // Update refills preview
    const refillsInput = document.querySelector('input[name="refills"]');
    const refillsPreview = document.getElementById('preview_refills');
    if (refillsInput && refillsPreview) {
        refillsPreview.textContent = refillsInput.value;
    }
    
    // Update expiry preview
    const expiryInput = document.querySelector('input[name="expiry_date"]');
    const expiryPreview = document.getElementById('preview_expiry');
    if (expiryInput && expiryPreview) {
        expiryPreview.textContent = expiryInput.value ? 
            new Date(expiryInput.value).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : 
            'Not set';
    }
}

window.handleFrequencyChange = function(selectElement) {
    const row = selectElement.closest('.medication-row');
    const customFrequency = row.querySelector('.custom-frequency');
    
    if (selectElement.value === 'custom') {
        customFrequency.style.display = 'block';
        customFrequency.required = true;
    } else {
        customFrequency.style.display = 'none';
        customFrequency.required = false;
        customFrequency.value = '';
    }
}

window.handleInstructionsChange = function(selectElement) {
    const customInstructions = document.getElementById('custom_instructions');
    
    if (selectElement.value === 'custom') {
        customInstructions.style.display = 'block';
        customInstructions.required = true;
    } else {
        customInstructions.style.display = 'none';
        customInstructions.required = false;
        customInstructions.value = selectElement.value || '';
    }
}

function getStockStatus(status, quantity) {
    if (status === 'Out of Stock' || quantity <= 0) return 'out_of_stock';
    if (status === 'Low Stock' || quantity < 10) return 'low_stock';
    return 'in_stock';
}

function getStockBadge(stockStatus) {
    switch(stockStatus) {
        case 'in_stock':
            return '<span class="badge badge-success">✓</span>';
        case 'low_stock':
            return '<span class="badge badge-warning">!</span>';
        case 'out_of_stock':
            return '<span class="badge badge-danger">✗</span>';
        default:
            return '<span class="badge badge-secondary">?</span>';
    }
}

function showAlert(message, type = 'info') {
    // Create alert element
    const alert = document.createElement('div');
    alert.className = `alert alert-${type} alert-dismissible fade show`;
    alert.innerHTML = `
        <button type="button" class="close" data-dismiss="alert">&times;</button>
        ${message}
    `;
    
    // Add to page
    const container = document.querySelector('.card-body');
    container.insertBefore(alert, container.firstChild);
    
    // Auto remove after 5 seconds
    setTimeout(() => {
        if (alert.parentElement) {
            alert.remove();
        }
    }, 5000);
}

// Initialize existing prescription items
document.addEventListener('DOMContentLoaded', function() {
    // Add existing prescription items
    <?php foreach($prescription_items as $index => $item): ?>
        addMedicationRow(
            <?php echo $item['drug_id']; ?>,
            <?php echo $item['inventory_location_item_id']; ?>,
            <?php echo $item['pi_quantity']; ?>,
            '<?php echo addslashes($item['pi_dosage']); ?>',
            '<?php echo addslashes($item['pi_frequency']); ?>',
            '<?php echo addslashes($item['pi_duration']); ?>',
            '<?php echo addslashes($item['pi_instructions']); ?>'
        );
    <?php endforeach; ?>
    
    // Initialize Select2
    $('.select2').select2({
        placeholder: "- Select -",
        allowClear: true
    });
    
    // Update preview on form changes
    document.querySelectorAll('input, select').forEach(element => {
        element.addEventListener('change', updatePreview);
    });
    
    // Add real-time validation for quantity inputs
    document.addEventListener('input', function(e) {
        if (e.target.classList.contains('quantity-input')) {
            updateRowTotal(e.target.closest('.medication-row'));
        }
    });
    
    console.log('Prescription editor initialized with', <?php echo count($prescription_items); ?>, 'existing medications');
});

// Form validation
document.getElementById('editPrescriptionForm').addEventListener('submit', function(e) {
    const medicationRows = document.querySelectorAll('.medication-row');
    let hasValidMedications = false;
    
    // Check if we have at least one valid medication
    medicationRows.forEach(row => {
        const drugId = row.querySelector('select[name="drug_id[]"]').value;
        const locationItemId = row.querySelector('.location-item-id').value;
        const quantity = row.querySelector('input[name="quantity[]"]').value;
        
        if (drugId && locationItemId && quantity > 0) {
            hasValidMedications = true;
        }
    });
    
    if (!hasValidMedications) {
        e.preventDefault();
        showAlert('Please add at least one medication with valid location inventory selection.', 'error');
        return;
    }
    
    // Validate location inventory stock
    let stockIssues = [];
    medicationRows.forEach((row, index) => {
        const drugSelect = row.querySelector('select[name="drug_id[]"]');
        const inventorySelect = row.querySelector('.select-inventory');
        const quantityInput = row.querySelector('input[name="quantity[]"]');
        const selectedOption = inventorySelect.options[inventorySelect.selectedIndex];
        
        if (selectedOption && selectedOption.value) {
            const availableStock = parseInt(selectedOption.getAttribute('data-stock')) || 0;
            const requestedQuantity = parseInt(quantityInput.value) || 0;
            const drugName = drugSelect.options[drugSelect.selectedIndex]?.text || `Medication ${index + 1}`;
            const locationName = selectedOption.getAttribute('data-location');
            
            if (requestedQuantity > availableStock) {
                stockIssues.push(`${drugName} in ${locationName}: Requested ${requestedQuantity}, Available ${availableStock}`);
            }
        }
    });
    
    if (stockIssues.length > 0) {
        e.preventDefault();
        const message = 'Insufficient stock for:<br>' + stockIssues.join('<br>');
        showAlert(message, 'error');
    }
});

$(document).ready(function() {
    // Auto-close alerts after 5 seconds
    setTimeout(function() {
        $('.alert').fadeOut('slow');
    }, 5000);

    // Form validation
    $('#editPrescriptionForm').submit(function(e) {
        var isValid = true;
        var requiredFields = $(this).find('[required]');
        
        requiredFields.each(function() {
            if ($(this).val() === '' || $(this).val() === null) {
                $(this).addClass('is-invalid');
                isValid = false;
            } else {
                $(this).removeClass('is-invalid');
            }
        });
        
        if (!isValid) {
            e.preventDefault();
            // Scroll to first invalid field
            $('html, body').animate({
                scrollTop: $('.is-invalid').first().offset().top - 100
            }, 500);
            
            // Show error message
            if (!$('.alert-danger').length) {
                $('#editPrescriptionForm').prepend(
                    '<div class="alert alert-danger alert-dismissible">' +
                    '<button type="button" class="close" data-dismiss="alert">&times;</button>' +
                    '<i class="fas fa-exclamation-triangle mr-2"></i>' +
                    'Please fill in all required fields marked with *' +
                    '</div>'
                );
            }
        }
    });

    // Remove invalid class when field is filled
    $('[required]').on('input change', function() {
        if ($(this).val() !== '') {
            $(this).removeClass('is-invalid');
        }
    });

    // Keyboard shortcuts
    $(document).keydown(function(e) {
        // Ctrl + S to save
        if (e.ctrlKey && e.keyCode === 83) {
            e.preventDefault();
            $('#editPrescriptionForm').submit();
        }
        // Escape to cancel
        if (e.keyCode === 27) {
            if (confirm('Are you sure you want to discard changes?')) {
                window.location.href = 'pharmacy_prescription_view.php?id=<?php echo $prescription_id; ?>';
            }
        }
    });
});
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
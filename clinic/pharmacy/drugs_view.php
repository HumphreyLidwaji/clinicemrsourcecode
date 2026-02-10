<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/audit_functions.php'; // Added for logging

// Get drug ID from URL
$drug_id = intval($_GET['drug_id'] ?? 0);

if (!$drug_id) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "No drug specified.";
    
    // AUDIT LOG: No drug ID specified
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'DRUG_VIEW',
        'module'      => 'Drugs',
        'table_name'  => 'drugs',
        'entity_type' => 'drug',
        'record_id'   => null,
        'patient_id'  => null,
        'visit_id'    => null,
        'description' => "Attempted to access drug view page without specifying drug ID",
        'status'      => 'FAILED',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    header("Location: drugs_manage.php");
    exit;
}

// Fetch drug data with detailed information
$drug_sql = "
    SELECT 
        d.*, 
        u.user_name AS created_by_name, 
        u2.user_name AS updated_by_name
    FROM drugs d
    LEFT JOIN users u ON d.drug_created_by = u.user_id
    LEFT JOIN users u2 ON d.drug_updated_by = u2.user_id
    WHERE d.drug_id = ?
";
$drug_stmt = $mysqli->prepare($drug_sql);
$drug_stmt->bind_param("i", $drug_id);
$drug_stmt->execute();
$drug = $drug_stmt->get_result()->fetch_assoc();

if (!$drug) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Drug not found.";
    
    // AUDIT LOG: Drug not found
    audit_log($mysqli, [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
        'action'      => 'DRUG_VIEW',
        'module'      => 'Drugs',
        'table_name'  => 'drugs',
        'entity_type' => 'drug',
        'record_id'   => $drug_id,
        'patient_id'  => null,
        'visit_id'    => null,
        'description' => "Attempted to access drug view page but drug ID " . $drug_id . " not found",
        'status'      => 'FAILED',
        'old_values'  => null,
        'new_values'  => null
    ]);
    
    header("Location: drugs_manage.php");
    exit;
}

// AUDIT LOG: Successful access to drug view page
audit_log($mysqli, [
    'user_id'     => $_SESSION['user_id'] ?? null,
    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
    'action'      => 'VIEW',
    'module'      => 'Drugs',
    'table_name'  => 'drugs',
    'entity_type' => 'drug',
    'record_id'   => $drug_id,
    'patient_id'  => null,
    'visit_id'    => null,
    'description' => "Accessed drug view page for: " . $drug['drug_name'] . " (ID: " . $drug_id . ")",
    'status'      => 'SUCCESS',
    'old_values'  => null,
    'new_values'  => [
        'drug_id' => $drug_id,
        'drug_name' => $drug['drug_name'],
        'drug_is_active' => $drug['drug_is_active']
    ]
]);

/*
|--------------------------------------------------------------------------
| Drug Inventory Statistics (OPTIMIZED)
|--------------------------------------------------------------------------
*/
$stats_sql = "
    SELECT
        COUNT(*) AS inventory_count,
        COALESCE(SUM(item_quantity), 0) AS total_stock,
        SUM(item_status = 'Low Stock') AS low_stock_count,
        SUM(item_status = 'Out of Stock') AS out_of_stock_count,
        SUM(item_status = 'In Stock') AS in_stock_count,
        MAX(item_updated_at) AS last_inventory_update
    FROM inventory_items
    WHERE drug_id = ? AND item_status != 'Discontinued'
";
$stats_stmt = $mysqli->prepare($stats_sql);
$stats_stmt->bind_param("i", $drug_id);
$stats_stmt->execute();
$stats = $stats_stmt->get_result()->fetch_assoc();

/*
|--------------------------------------------------------------------------
| Prescription Usage Count (SEPARATE & LIGHTWEIGHT)
|--------------------------------------------------------------------------
*/
$prescription_sql = "
    SELECT COUNT(*) AS prescription_count
    FROM prescription_items
    WHERE pi_drug_id = ?
";
$pres_stmt = $mysqli->prepare($prescription_sql);
$pres_stmt->bind_param("i", $drug_id);
$pres_stmt->execute();
$prescription = $pres_stmt->get_result()->fetch_assoc();

$stats['prescription_count'] = $prescription['prescription_count'] ?? 0;

// AUDIT LOG: Drug statistics viewed
audit_log($mysqli, [
    'user_id'     => $_SESSION['user_id'] ?? null,
    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
    'action'      => 'DRUG_STATS_VIEW',
    'module'      => 'Drugs',
    'table_name'  => 'inventory_items',
    'entity_type' => 'drug_stats',
    'record_id'   => $drug_id,
    'patient_id'  => null,
    'visit_id'    => null,
    'description' => "Viewed drug statistics for: " . $drug['drug_name'] . " (ID: " . $drug_id . ")",
    'status'      => 'SUCCESS',
    'old_values'  => null,
    'new_values'  => array_merge([
        'drug_id' => $drug_id,
        'drug_name' => $drug['drug_name']
    ], $stats)
]);

/*
|--------------------------------------------------------------------------
| Recent Inventory Items
|--------------------------------------------------------------------------
*/
$inventory_sql = "
    SELECT 
        i.*, 
        l.location_name, 
        s.supplier_name, 
        c.category_name
    FROM inventory_items i
    LEFT JOIN inventory_locations l ON i.location_id = l.location_id
    LEFT JOIN suppliers s ON i.item_supplier_id = s.supplier_id
    LEFT JOIN inventory_categories c ON i.item_category_id = c.category_id
    WHERE i.drug_id = ?
    ORDER BY 
        FIELD(i.item_status, 'Out of Stock', 'Low Stock', 'In Stock'),
        i.item_quantity DESC
    LIMIT 10
";
$inventory_stmt = $mysqli->prepare($inventory_sql);
$inventory_stmt->bind_param("i", $drug_id);
$inventory_stmt->execute();
$inventory_result = $inventory_stmt->get_result();

// Get inventory count for audit log
$inventory_items = $inventory_result->fetch_all(MYSQLI_ASSOC);
$inventory_count = count($inventory_items);

// AUDIT LOG: Inventory items viewed
audit_log($mysqli, [
    'user_id'     => $_SESSION['user_id'] ?? null,
    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
    'action'      => 'INVENTORY_VIEW',
    'module'      => 'Inventory',
    'table_name'  => 'inventory_items',
    'entity_type' => 'inventory_items',
    'record_id'   => $drug_id,
    'patient_id'  => null,
    'visit_id'    => null,
    'description' => "Viewed " . $inventory_count . " inventory items for drug: " . $drug['drug_name'] . " (ID: " . $drug_id . ")",
    'status'      => 'SUCCESS',
    'old_values'  => null,
    'new_values'  => [
        'drug_id' => $drug_id,
        'drug_name' => $drug['drug_name'],
        'inventory_item_count' => $inventory_count,
        'inventory_items' => $inventory_items
    ]
]);

// Reset result pointer for template usage
$inventory_result->data_seek(0);

/*
|--------------------------------------------------------------------------
| Recent Prescriptions
|--------------------------------------------------------------------------
*/
$prescriptions_sql = "
    SELECT 
        pi.*, 
        p.patient_first_name, 
        p.patient_last_name, 
        p.patient_mrn,
        v.visit_date, 
        u.user_name AS prescribed_by,
        pr.prescription_id, 
        pr.prescription_status
    FROM prescription_items pi
    JOIN prescriptions pr ON pi.pi_prescription_id = pr.prescription_id
    JOIN visits v ON pr.prescription_visit_id = v.visit_id
    JOIN patients p ON v.visit_patient_id = p.patient_id
    JOIN users u ON pr.prescription_doctor_id = u.user_id
    WHERE pi.pi_drug_id = ?
    ORDER BY pr.prescription_date DESC
    LIMIT 10
";
$prescriptions_stmt = $mysqli->prepare($prescriptions_sql);
$prescriptions_stmt->bind_param("i", $drug_id);
$prescriptions_stmt->execute();
$prescriptions_result = $prescriptions_stmt->get_result();

// Get prescription count for audit log
$prescription_items = $prescriptions_result->fetch_all(MYSQLI_ASSOC);
$prescription_item_count = count($prescription_items);

// AUDIT LOG: Prescription history viewed
audit_log($mysqli, [
    'user_id'     => $_SESSION['user_id'] ?? null,
    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
    'action'      => 'PRESCRIPTION_HISTORY_VIEW',
    'module'      => 'Prescriptions',
    'table_name'  => 'prescription_items',
    'entity_type' => 'prescription_items',
    'record_id'   => $drug_id,
    'patient_id'  => null,
    'visit_id'    => null,
    'description' => "Viewed " . $prescription_item_count . " prescription records for drug: " . $drug['drug_name'] . " (ID: " . $drug_id . ")",
    'status'      => 'SUCCESS',
    'old_values'  => null,
    'new_values'  => [
        'drug_id' => $drug_id,
        'drug_name' => $drug['drug_name'],
        'prescription_item_count' => $prescription_item_count,
        'prescription_items' => $prescription_items
    ]
]);

// Reset result pointer for template usage
$prescriptions_result->data_seek(0);

// Additional audit log for comprehensive drug data view
audit_log($mysqli, [
    'user_id'     => $_SESSION['user_id'] ?? null,
    'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
    'action'      => 'DRUG_DETAILS_VIEW',
    'module'      => 'Drugs',
    'table_name'  => 'drugs',
    'entity_type' => 'drug',
    'record_id'   => $drug_id,
    'patient_id'  => null,
    'visit_id'    => null,
    'description' => "Comprehensive drug details viewed for: " . $drug['drug_name'] . " (ID: " . $drug_id . ") including inventory and prescription history",
    'status'      => 'SUCCESS',
    'old_values'  => null,
    'new_values'  => [
        'drug_id' => $drug_id,
        'drug_name' => $drug['drug_name'],
        'drug_generic_name' => $drug['drug_generic_name'],
        'drug_form' => $drug['drug_form'],
        'drug_strength' => $drug['drug_strength'],
        'drug_is_active' => $drug['drug_is_active'],
        'inventory_count' => $stats['inventory_count'],
        'total_stock' => $stats['total_stock'],
        'prescription_count' => $stats['prescription_count']
    ]
]);

?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0">
            <i class="fas fa-fw fa-capsules mr-2"></i>Drug Details: <?php echo htmlspecialchars($drug['drug_name']); ?>
        </h3>
        <div class="card-tools">
            <a href="drugs_manage.php" class="btn btn-light">
                <i class="fas fa-arrow-left mr-2"></i>Back to Drugs
            </a>
        </div>
    </div>
    
    <!-- Statistics Row -->
    <div class="card-body border-bottom">
        <div class="row text-center">
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-primary"><i class="fas fa-boxes"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Inventory Items</span>
                        <span class="info-box-number"><?php echo $stats['inventory_count'] ?? 0; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-success"><i class="fas fa-prescription"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Prescriptions</span>
                        <span class="info-box-number"><?php echo $stats['prescription_count'] ?? 0; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-info"><i class="fas fa-cubes"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Stock</span>
                        <span class="info-box-number"><?php echo $stats['total_stock'] ?? 0; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-<?php echo $drug['drug_is_active'] ? 'success' : 'danger'; ?>">
                        <i class="fas fa-<?php echo $drug['drug_is_active'] ? 'check-circle' : 'pause-circle'; ?>"></i>
                    </span>
                    <div class="info-box-content">
                        <span class="info-box-text">Status</span>
                        <span class="info-box-number"><?php echo $drug['drug_is_active'] ? 'Active' : 'Inactive'; ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card-body">
        <!-- Drug Header Actions -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="btn-toolbar justify-content-between">
                    <div class="btn-group">
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Status:</strong> 
                            <span class="badge badge-<?php echo $drug['drug_is_active'] ? 'success' : 'danger'; ?> ml-2">
                                <?php echo $drug['drug_is_active'] ? 'Active' : 'Inactive'; ?>
                            </span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Category:</strong> 
                            <span class="badge badge-info ml-2"><?php echo htmlspecialchars($drug['drug_category'] ?: 'Uncategorized'); ?></span>
                        </span>
                    </div>
                    <div class="btn-group">
                        <a href="drugs_edit.php?drug_id=<?php echo $drug_id; ?>" class="btn btn-success">
                            <i class="fas fa-edit mr-2"></i>Edit Drug
                        </a>
                        <a href="drug_print.php?drug_id=<?php echo $drug_id; ?>" class="btn btn-primary" target="_blank">
                            <i class="fas fa-print mr-2"></i>Print
                        </a>
                        <div class="dropdown">
                            <button class="btn btn-secondary dropdown-toggle" type="button" data-toggle="dropdown">
                                <i class="fas fa-cog mr-2"></i>Actions
                            </button>
                            <div class="dropdown-menu">
                                <a href="inventory_add_item.php?drug_id=<?php echo $drug_id; ?>" class="dropdown-item text-success">
                                    <i class="fas fa-plus mr-2"></i>Add to Inventory
                                </a>
                                <a href="pharmacy_inventory.php?drug=<?php echo $drug_id; ?>" class="dropdown-item text-info">
                                    <i class="fas fa-boxes mr-2"></i>View All Inventory
                                </a>
                                <div class="dropdown-divider"></div>
                                <?php if ($drug['drug_is_active']): ?>
                                    <a class="dropdown-item text-warning confirm-link" href="post/drug.php?deactivate_drug=<?php echo $drug_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>">
                                        <i class="fas fa-pause mr-2"></i>Deactivate Drug
                                    </a>
                                <?php else: ?>
                                    <a class="dropdown-item text-success confirm-link" href="post/drug.php?activate_drug=<?php echo $drug_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>">
                                        <i class="fas fa-play mr-2"></i>Activate Drug
                                    </a>
                                <?php endif; ?>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item text-danger confirm-link" href="post/drug.php?delete_drug=<?php echo $drug_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>">
                                    <i class="fas fa-trash mr-2"></i>Delete Drug
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Drug Information -->
            <div class="col-md-6">
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-info-circle mr-2"></i>Drug Information</h4>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <th width="40%" class="text-muted">Drug ID:</th>
                                    <td><strong class="text-primary">#<?php echo $drug_id; ?></strong></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Drug Name:</th>
                                    <td><strong><?php echo htmlspecialchars($drug['drug_name']); ?></strong></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Generic Name:</th>
                                    <td>
                                        <?php if ($drug['drug_generic_name']): ?>
                                            <strong class="text-info"><?php echo htmlspecialchars($drug['drug_generic_name']); ?></strong>
                                        <?php else: ?>
                                            <span class="text-muted">Not specified</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Dosage Form:</th>
                                    <td>
                                        <?php if ($drug['drug_form']): ?>
                                            <span class="badge badge-primary"><?php echo htmlspecialchars($drug['drug_form']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">Not specified</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Strength:</th>
                                    <td>
                                        <?php if ($drug['drug_strength']): ?>
                                            <span class="badge badge-info"><?php echo htmlspecialchars($drug['drug_strength']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">Not specified</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Manufacturer:</th>
                                    <td>
                                        <?php if ($drug['drug_manufacturer']): ?>
                                            <strong><?php echo htmlspecialchars($drug['drug_manufacturer']); ?></strong>
                                        <?php else: ?>
                                            <span class="text-muted">Not specified</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Category:</th>
                                    <td>
                                        <?php if ($drug['drug_category']): ?>
                                            <span class="badge badge-success"><?php echo htmlspecialchars($drug['drug_category']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">Uncategorized</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php if ($drug['drug_description']): ?>
                                <tr>
                                    <th class="text-muted">Description:</th>
                                    <td><?php echo nl2br(htmlspecialchars($drug['drug_description'])); ?></td>
                                </tr>
                                <?php endif; ?>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Drug Metadata -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-database mr-2"></i>Drug Metadata</h4>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <th width="40%" class="text-muted">Created By:</th>
                                    <td><?php echo htmlspecialchars($drug['created_by_name'] ?? 'NA'); ?></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Created Date:</th>
                                    <td><?php echo date('M j, Y H:i', strtotime($drug['drug_created_at'])); ?></td>
                                </tr>
                                <?php if ($drug['drug_updated_by']): ?>
                                <tr>
                                    <th class="text-muted">Last Updated By:</th>
                                    <td><?php echo htmlspecialchars($drug['updated_by_name']); ?></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Last Updated:</th>
                                    <td><?php echo date('M j, Y H:i', strtotime($drug['drug_updated_at'])); ?></td>
                                </tr>
                                <?php endif; ?>
                                <?php if ($stats['last_inventory_update']): ?>
                                <tr>
                                    <th class="text-muted">Last Inventory Update:</th>
                                    <td><?php echo date('M j, Y H:i', strtotime($stats['last_inventory_update'])); ?></td>
                                </tr>
                                <?php endif; ?>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Stock Status & Quick Stats -->
            <div class="col-md-6">
                <!-- Stock Status -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-chart-bar mr-2"></i>Stock Status Summary</h4>
                    </div>
                    <div class="card-body">
                        <?php if ($stats['inventory_count'] > 0): ?>
                            <div class="text-center mb-4">
                                <div class="h4 text-success mb-1"><?php echo $stats['total_stock'] ?? 0; ?> units</div>
                                <small class="text-muted">Total units available across all locations</small>
                            </div>
                            
                            <div class="row text-center mb-3">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <div class="h5 text-success mb-1"><?php echo $stats['in_stock_count'] ?? 0; ?></div>
                                        <small class="text-muted">In Stock Items</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <div class="h5 text-warning mb-1"><?php echo $stats['low_stock_count'] ?? 0; ?></div>
                                        <small class="text-muted">Low Stock Items</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <div class="h5 text-danger mb-1"><?php echo $stats['out_of_stock_count'] ?? 0; ?></div>
                                        <small class="text-muted">Out of Stock</small>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="progress mb-2" style="height: 20px;">
                                <div class="progress-bar bg-success" style="width: <?php echo (($stats['in_stock_count'] ?? 0) / $stats['inventory_count']) * 100; ?>%">
                                    In Stock
                                </div>
                                <div class="progress-bar bg-warning" style="width: <?php echo (($stats['low_stock_count'] ?? 0) / $stats['inventory_count']) * 100; ?>%">
                                    Low Stock
                                </div>
                                <div class="progress-bar bg-danger" style="width: <?php echo (($stats['out_of_stock_count'] ?? 0) / $stats['inventory_count']) * 100; ?>%">
                                    Out of Stock
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info mb-0">
                                <i class="fas fa-info-circle mr-2"></i>
                                This drug has not been added to inventory yet.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Drug Status -->
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-chart-pie mr-2"></i>Drug Status</h4>
                    </div>
                    <div class="card-body">
                        <div class="text-center">
                            <div class="mb-3">
                                <div class="h4 <?php echo $drug['drug_is_active'] ? 'text-success' : 'text-danger'; ?>">
                                    <i class="fas fa-<?php echo $drug['drug_is_active'] ? 'check-circle' : 'pause-circle'; ?> mr-2"></i>
                                    <?php echo $drug['drug_is_active'] ? 'Active' : 'Inactive'; ?>
                                </div>
                                <small class="text-muted">
                                    <?php echo $drug['drug_is_active'] ? 'This drug is available for prescription' : 'This drug is not available for prescription'; ?>
                                </small>
                            </div>
                            
                            <div class="row mt-3">
                                <div class="col-md-6">
                                    <div class="card bg-light">
                                        <div class="card-body text-center">
                                            <div class="h5 mb-1 text-primary"><?php echo $stats['inventory_count'] ?? 0; ?></div>
                                            <small class="text-muted">Inventory Items</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="card bg-light">
                                        <div class="card-body text-center">
                                            <div class="h5 mb-1 text-success"><?php echo $stats['prescription_count'] ?? 0; ?></div>
                                            <small class="text-muted">Total Prescriptions</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Inventory Items -->
        <div class="card mb-4">
            <div class="card-header bg-light py-2">
                <h4 class="card-title mb-0"><i class="fas fa-boxes mr-2"></i>Recent Inventory Items</h4>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th>Item Name</th>
                                <th>Location</th>
                                <th class="text-center">Stock</th>
                                <th>Status</th>
                                <th class="text-right">Cost Price</th>
                                <th class="text-right">Selling Price</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($inventory_result->num_rows > 0): ?>
                                <?php while ($item = $inventory_result->fetch_assoc()): 
                                    $stock_class = $item['item_status'] == 'In Stock' ? 'text-success' : 
                                                ($item['item_status'] == 'Low Stock' ? 'text-warning' : 'text-danger');
                                    $status_badge = $item['item_status'] == 'In Stock' ? 'badge-success' : 
                                                 ($item['item_status'] == 'Low Stock' ? 'badge-warning' : 'badge-danger');
                                ?>
                                    <tr>
                                        <td>
                                            <div class="font-weight-bold"><?php echo htmlspecialchars($item['item_name']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($item['item_code']); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($item['location_name'] ?? 'N/A'); ?></td>
                                        <td class="text-center">
                                            <span class="font-weight-bold <?php echo $stock_class; ?>">
                                                <?php echo number_format($item['item_quantity']); ?>
                                            </span>
                                            <br><small class="text-muted"><?php echo $item['item_unit_measure']; ?></small>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $status_badge; ?>"><?php echo $item['item_status']; ?></span>
                                        </td>
                                        <td class="text-right">
                                            <div class="font-weight-bold">$<?php echo number_format($item['item_unit_cost'], 2); ?></div>
                                        </td>
                                        <td class="text-right">
                                            <div class="font-weight-bold">$<?php echo number_format($item['item_unit_price'], 2); ?></div>
                                        </td>
                                        <td class="text-center">
                                            <a href="inventory_item_details.php?item_id=<?php echo $item['item_id']; ?>" class="btn btn-sm btn-info" title="View Item">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4">
                                        <i class="fas fa-box-open fa-2x text-muted mb-3"></i>
                                        <h5 class="text-muted">No Inventory Items</h5>
                                        <p class="text-muted">This drug has not been added to inventory yet.</p>
                                        <a href="inventory_add_item.php?drug_id=<?php echo $drug_id; ?>" class="btn btn-success btn-sm">
                                            <i class="fas fa-plus mr-2"></i>Add to Inventory
                                        </a>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php if ($inventory_result->num_rows > 0): ?>
            <div class="card-footer">
                <a href="pharmacy_inventory.php?drug=<?php echo $drug_id; ?>" class="btn btn-outline-primary btn-sm">
                    <i class="fas fa-list mr-2"></i>View All Inventory Items
                </a>
            </div>
            <?php endif; ?>
        </div>

        <!-- Recent Prescriptions -->
        <div class="card">
            <div class="card-header bg-light py-2">
                <h4 class="card-title mb-0"><i class="fas fa-prescription mr-2"></i>Recent Prescriptions</h4>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th>Patient</th>
                                <th>MRN</th>
                                <th>Visit Date</th>
                                <th class="text-center">Dosage</th>
                                <th class="text-center">Duration</th>
                                <th>Prescribed By</th>
                                <th>Status</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($prescriptions_result->num_rows > 0): ?>
                                <?php while ($prescription = $prescriptions_result->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <div class="font-weight-bold"><?php echo htmlspecialchars($prescription['patient_first_name'] . ' ' . $prescription['patient_last_name']); ?></div>
                                        </td>
                                        <td><?php echo htmlspecialchars($prescription['patient_mrn']); ?></td>
                                        <td>
                                            <div class="font-weight-bold"><?php echo date('M j, Y', strtotime($prescription['visit_date'])); ?></div>
                                            <small class="text-muted"><?php echo date('H:i', strtotime($prescription['visit_date'])); ?></small>
                                        </td>
                                        <td class="text-center">
                                            <span class="font-weight-bold"><?php echo htmlspecialchars($prescription['pi_dosage']); ?></span>
                                            <?php if ($prescription['pi_frequency']): ?>
                                                <br><small class="text-muted"><?php echo $prescription['pi_frequency']; ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($prescription['pi_duration']): ?>
                                                <span class="font-weight-bold"><?php echo $prescription['pi_duration'] ?? 'N/A'; ?></span>
                                                <?php if ($prescription['pi_duration_unit'] ?? 'N/A'): ?>
                                                    <br><small class="text-muted"><?php echo $prescription['pi_duration_unit']; ?></small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($prescription['prescribed_by']); ?></td>
                                        <td>
                                            <span class="badge badge-<?php 
                                                echo $prescription['prescription_status'] == 'active' ? 'success' : 
                                                     ($prescription['prescription_status'] == 'completed' ? 'info' : 'secondary'); 
                                            ?>">
                                                <?php echo ucfirst($prescription['prescription_status']); ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <a href="pharmacy_prescription_view.php?id=<?php echo $prescription['prescription_id']; ?>" class="btn btn-sm btn-info" title="View Prescription">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4">
                                        <i class="fas fa-prescription fa-2x text-muted mb-3"></i>
                                        <h5 class="text-muted">No Prescriptions Found</h5>
                                        <p class="text-muted">This drug has not been prescribed yet.</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php if ($prescriptions_result->num_rows > 0): ?>
            <div class="card-footer">
                <a href="prescriptions_report.php?drug_id=<?php echo $drug_id; ?>" class="btn btn-outline-primary btn-sm">
                    <i class="fas fa-list mr-2"></i>View All Prescriptions
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Confirm before actions
    $('.confirm-link').click(function(e) {
        if (!confirm('Are you sure you want to perform this action?')) {
            e.preventDefault();
        }
    });

    // Tooltip initialization
    $('[title]').tooltip();
});

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Escape to go back
    if (e.keyCode === 27) {
        window.location.href = 'drugs_manage.php';
    }
    // Ctrl + E to edit
    if (e.ctrlKey && e.keyCode === 69) {
        e.preventDefault();
        window.location.href = 'drugs_edit.php?drug_id=<?php echo $drug_id; ?>';
    }
    // Ctrl + I to add inventory
    if (e.ctrlKey && e.keyCode === 73) {
        e.preventDefault();
        window.location.href = 'inventory_add_item.php?drug_id=<?php echo $drug_id; ?>';
    }
    // Ctrl + P to print
    if (e.ctrlKey && e.keyCode === 80) {
        e.preventDefault();
        window.open('drug_print.php?drug_id=<?php echo $drug_id; ?>', '_blank');
    }
});
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
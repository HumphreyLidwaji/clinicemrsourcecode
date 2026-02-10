<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Get service ID from URL
$medical_service_id = intval($_GET['medical_service_id'] ?? 0);

if ($medical_service_id <= 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid medical service ID.";
    header("Location: medical_services.php");
    exit;
}

// Fetch service details
$service_sql = "SELECT ms.*, msc.category_name, u1.user_name as created_by_name, u2.user_name as updated_by_name
                FROM medical_services ms
                LEFT JOIN medical_service_categories msc ON ms.service_category_id = msc.category_id
                LEFT JOIN users u1 ON ms.created_by = u1.user_id
                LEFT JOIN users u2 ON ms.updated_by = u2.user_id
                WHERE ms.medical_service_id = ?";
$service_stmt = $mysqli->prepare($service_sql);
$service_stmt->bind_param("i", $medical_service_id);
$service_stmt->execute();
$service_result = $service_stmt->get_result();

if ($service_result->num_rows === 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Medical service not found.";
    header("Location: medical_services.php");
    exit;
}

$service = $service_result->fetch_assoc();

// Get linked accounts for bookkeeping
$accounts_sql = "SELECT sa.*, a.account_number, a.account_name, a.account_type as account_type_name
                 FROM service_accounts sa
                 JOIN accounts a ON sa.account_id = a.account_id
                 WHERE sa.medical_service_id = ?
                 ORDER BY sa.account_type";
$accounts_stmt = $mysqli->prepare($accounts_sql);
$accounts_stmt->bind_param("i", $medical_service_id);
$accounts_stmt->execute();
$accounts_result = $accounts_stmt->get_result();

$linked_accounts = [];
while ($account = $accounts_result->fetch_assoc()) {
    $linked_accounts[] = $account;
}

// Get linked components (lab tests, radiology, beds)
$components_sql = "SELECT sc.*, 
                   CASE 
                     WHEN sc.component_type = 'LabTest' THEN lt.test_name
                     WHEN sc.component_type = 'Radiology' THEN ri.imaging_name
                     WHEN sc.component_type = 'Bed' THEN CONCAT(w.ward_name, ' - Bed ', b.bed_number)
                   END as component_name,
                   CASE 
                     WHEN sc.component_type = 'LabTest' THEN lt.test_code
                     WHEN sc.component_type = 'Radiology' THEN ri.imaging_code
                     WHEN sc.component_type = 'Bed' THEN CONCAT('BED-', b.bed_number)
                   END as component_code,
                   CASE 
                     WHEN sc.component_type = 'LabTest' THEN lt.price
                     WHEN sc.component_type = 'Radiology' THEN ri.fee_amount
                     WHEN sc.component_type = 'Bed' THEN b.bed_rate
                   END as component_price,
                   CASE 
                     WHEN sc.component_type = 'Bed' THEN b.bed_type
                     ELSE NULL
                   END as bed_type,
                   CASE 
                     WHEN sc.component_type = 'Bed' THEN w.ward_name
                     ELSE NULL
                   END as ward_name
                   FROM service_components sc
                   LEFT JOIN lab_tests lt ON sc.component_type = 'LabTest' AND sc.component_reference_id = lt.test_id
                   LEFT JOIN radiology_imagings ri ON sc.component_type = 'Radiology' AND sc.component_reference_id = ri.imaging_id
                   LEFT JOIN beds b ON sc.component_type = 'Bed' AND sc.component_reference_id = b.bed_id
                   LEFT JOIN wards w ON b.bed_ward_id = w.ward_id
                   WHERE sc.medical_service_id = ?
                   ORDER BY sc.component_type";
$components_stmt = $mysqli->prepare($components_sql);
$components_stmt->bind_param("i", $medical_service_id);
$components_stmt->execute();
$components_result = $components_stmt->get_result();

// Calculate total components cost
$total_components_cost = 0;
$linked_components = [];
while ($component = $components_result->fetch_assoc()) {
    $total_components_cost += floatval($component['component_price']);
    $linked_components[] = $component;
}

// Get linked inventory items
$inventory_sql = "SELECT si.*, ii.item_code, ii.item_name, ii.item_unit_price
                  FROM service_inventory_items si
                  JOIN inventory_items ii ON si.item_id = ii.item_id
                  WHERE si.medical_service_id = ?
                  ORDER BY ii.item_name";
$inventory_stmt = $mysqli->prepare($inventory_sql);
$inventory_stmt->bind_param("i", $medical_service_id);
$inventory_stmt->execute();
$inventory_result = $inventory_stmt->get_result();

$linked_inventory = [];
$total_inventory_cost = 0;
while ($item = $inventory_result->fetch_assoc()) {
    $item_cost = $item['item_unit_price'] * $item['quantity_required'];
    $total_inventory_cost += $item_cost;
    $linked_inventory[] = $item;
}

// Calculate total cost including inventory
$total_linked_cost = $total_components_cost + $total_inventory_cost;

// Get activity logs
$activity_sql = "SELECT al.*, u.user_name 
                 FROM medical_service_activity_logs al
                 LEFT JOIN users u ON al.user_id = u.user_id
                 WHERE al.description LIKE ? 
                 ORDER BY al.created_at DESC 
                 LIMIT 10";

$activity_search = "%" . $service['service_code'] . "%";
$activity_stmt = $mysqli->prepare($activity_sql);
$activity_stmt->bind_param("s", $activity_search);
$activity_stmt->execute();
$activity_result = $activity_stmt->get_result();

?>

<div class="card">
    <div class="card-header bg-success py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-stethoscope mr-2"></i>Medical Service Details
            </h3>
            <div class="card-tools">
                <div class="btn-group">
                    <a href="medical_service_edit.php?medical_service_id=<?php echo $medical_service_id; ?>" class="btn btn-warning">
                        <i class="fas fa-edit mr-2"></i>Edit
                    </a>
                    <a href="medical_services.php" class="btn btn-light">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Services
                    </a>
                </div>
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

        <div class="row">
            <div class="col-md-8">
                <!-- Service Overview -->
                <div class="card card-primary">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-info-circle mr-2"></i>Service Overview</h3>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <table class="table table-bordered">
                                    <tr>
                                        <th class="bg-light" style="width: 40%">Service Code</th>
                                        <td>
                                            <span class="font-weight-bold text-primary"><?php echo htmlspecialchars($service['service_code']); ?></span>
                                            <?php if (!$service['is_active']): ?>
                                                <span class="badge badge-danger ml-2">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light">Service Name</th>
                                        <td class="font-weight-bold"><?php echo htmlspecialchars($service['service_name']); ?></td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light">Service Type</th>
                                        <td>
                                            <span class="badge badge-<?php echo getServiceTypeBadgeColor($service['service_type']); ?>">
                                                <?php echo htmlspecialchars($service['service_type']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light">Category</th>
                                        <td><?php echo htmlspecialchars($service['category_name'] ?: 'Uncategorized'); ?></td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <table class="table table-bordered">
                                    <tr>
                                        <th class="bg-light" style="width: 40%">Fee Amount</th>
                                        <td class="font-weight-bold text-success">$<?php echo number_format($service['fee_amount'], 2); ?></td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light">Duration</th>
                                        <td>
                                            <span class="badge badge-info"><?php echo intval($service['duration_minutes']); ?> minutes</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light">Tax Rate</th>
                                        <td><?php echo number_format($service['tax_rate'], 2); ?>%</td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light">Medical Code</th>
                                        <td><?php echo htmlspecialchars($service['medical_code'] ?: 'Not specified'); ?></td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <?php if ($service['service_description']): ?>
                        <div class="form-group">
                            <label class="font-weight-bold">Description</label>
                            <div class="border rounded p-3 bg-light">
                                <?php echo nl2br(htmlspecialchars($service['service_description'])); ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Service Flags -->
                        <div class="row mt-3">
                            <div class="col-md-6">
                                <div class="d-flex align-items-center mb-2">
                                    <span class="font-weight-bold mr-3">Service Flags:</span>
                                    <?php if ($service['requires_doctor']): ?>
                                        <span class="badge badge-primary mr-2">Requires Doctor</span>
                                    <?php endif; ?>
                                    <?php if ($service['insurance_billable']): ?>
                                        <span class="badge badge-success">Insurance Billable</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary">Self-pay</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Bookkeeping Accounts -->
                <?php if (!empty($linked_accounts)): ?>
                <div class="card card-secondary">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-book mr-2"></i>Bookkeeping Accounts</h3>
                        <small class="text-muted">Linked accounts for automated bookkeeping</small>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Account Type</th>
                                        <th>Account Number</th>
                                        <th>Account Name</th>
                                        <th>Account Type</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($linked_accounts as $account): ?>
                                        <tr>
                                            <td>
                                                <span class="badge badge-<?php echo getAccountTypeBadgeColor($account['account_type']); ?>">
                                                    <?php echo htmlspecialchars(ucfirst($account['account_type'])); ?>
                                                </span>
                                            </td>
                                            <td class="font-weight-bold"><?php echo htmlspecialchars($account['account_number']); ?></td>
                                            <td><?php echo htmlspecialchars($account['account_name']); ?></td>
                                            <td><?php echo htmlspecialchars($account['account_type_name']); ?></td>
                                            <td class="text-center">
                                                <a href="/admin/accounting/account_details.php?account_id=<?php echo $account['account_id']; ?>" 
                                                   class="btn btn-sm btn-outline-info" target="_blank" title="View Account">
                                                    <i class="fas fa-external-link-alt"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Linked Components -->
                <?php if (!empty($linked_components) || !empty($linked_inventory)): ?>
                <div class="card card-info">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-link mr-2"></i>Linked Service Components</h3>
                    </div>
                    <div class="card-body">
                        <!-- Lab Tests, Radiology & Beds -->
                        <?php if (!empty($linked_components)): ?>
                        <div class="mb-4">
                            <h5 class="mb-3">Medical Components</h5>
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Component Type</th>
                                            <th>Component Code</th>
                                            <th>Component Name</th>
                                            <th>Details</th>
                                            <th class="text-right">Price</th>
                                            <th class="text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($linked_components as $component): ?>
                                            <tr>
                                                <td>
                                                    <span class="badge badge-<?php echo getComponentTypeBadgeColor($component['component_type']); ?>">
                                                        <?php echo htmlspecialchars($component['component_type']); ?>
                                                    </span>
                                                </td>
                                                <td class="font-weight-bold"><?php echo htmlspecialchars($component['component_code']); ?></td>
                                                <td><?php echo htmlspecialchars($component['component_name']); ?></td>
                                                <td>
                                                    <?php if ($component['component_type'] == 'Bed'): ?>
                                                        <small class="text-muted">
                                                            <?php echo htmlspecialchars($component['ward_name'] . ' - ' . $component['bed_type']); ?>
                                                        </small>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-right text-success">$<?php echo number_format($component['component_price'], 2); ?></td>
                                                <td class="text-center">
                                                    <?php if ($component['component_type'] == 'LabTest'): ?>
                                                        <a href="../lab/lab_test_details.php?test_id=<?php echo $component['component_reference_id']; ?>" 
                                                           class="btn btn-sm btn-outline-info" target="_blank" title="View Lab Test">
                                                            <i class="fas fa-external-link-alt"></i>
                                                        </a>
                                                    <?php elseif ($component['component_type'] == 'Radiology'): ?>
                                                        <a href="../radiology/radiology_imaging_details.php?imaging_id=<?php echo $component['component_reference_id']; ?>" 
                                                           class="btn btn-sm btn-outline-info" target="_blank" title="View Radiology">
                                                            <i class="fas fa-external-link-alt"></i>
                                                        </a>
                                                    <?php elseif ($component['component_type'] == 'Bed'): ?>
                                                        <a href="../wards/bed_details.php?bed_id=<?php echo $component['component_reference_id']; ?>" 
                                                           class="btn btn-sm btn-outline-info" target="_blank" title="View Bed">
                                                            <i class="fas fa-external-link-alt"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Inventory Items -->
                        <?php if (!empty($linked_inventory)): ?>
                        <div>
                            <h5 class="mb-3">Inventory Items (Consumables/Supplies)</h5>
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Item Code</th>
                                            <th>Item Name</th>
                                            <th>Quantity Required</th>
                                            <th>Unit Price</th>
                                            <th class="text-right">Total Cost</th>
                                            <th class="text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($linked_inventory as $item): ?>
                                            <tr>
                                                <td class="font-weight-bold"><?php echo htmlspecialchars($item['item_code']); ?></td>
                                                <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                                                <td class="text-center"><?php echo intval($item['quantity_required']); ?></td>
                                                <td class="text-right">$<?php echo number_format($item['item_unit_price'], 2); ?></td>
                                                <td class="text-right text-success">
                                                    $<?php echo number_format($item['item_unit_price'] * $item['quantity_required'], 2); ?>
                                                </td>
                                                <td class="text-center">
                                                    <a href="../inventory/inventory_item_details.php?item_id=<?php echo $item['item_id']; ?>" 
                                                       class="btn btn-sm btn-outline-info" target="_blank" title="View Item">
                                                        <i class="fas fa-external-link-alt"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Cost Summary -->
                        <div class="border-top mt-4 pt-3">
                            <div class="row">
                                <div class="col-md-6 offset-md-6">
                                    <table class="table table-sm">
                                        <tbody>
                                            <?php if (!empty($linked_components)): ?>
                                            <tr>
                                                <td class="font-weight-bold">Medical Components Cost:</td>
                                                <td class="text-right text-success">$<?php echo number_format($total_components_cost, 2); ?></td>
                                            </tr>
                                            <?php endif; ?>
                                            <?php if (!empty($linked_inventory)): ?>
                                            <tr>
                                                <td class="font-weight-bold">Inventory Items Cost:</td>
                                                <td class="text-right text-success">$<?php echo number_format($total_inventory_cost, 2); ?></td>
                                            </tr>
                                            <?php endif; ?>
                                            <?php if (!empty($linked_components) || !empty($linked_inventory)): ?>
                                            <tr class="bg-light">
                                                <td class="font-weight-bold">Total Linked Components Cost:</td>
                                                <td class="text-right font-weight-bold text-primary">$<?php echo number_format($total_linked_cost, 2); ?></td>
                                            </tr>
                                            <?php endif; ?>
                                            <tr>
                                                <td class="font-weight-bold">Service Fee:</td>
                                                <td class="text-right font-weight-bold text-success">$<?php echo number_format($service['fee_amount'], 2); ?></td>
                                            </tr>
                                            <tr>
                                                <td class="font-weight-bold">Service Markup:</td>
                                                <td class="text-right font-weight-bold text-<?php echo ($service['fee_amount'] - $total_linked_cost) >= 0 ? 'success' : 'danger'; ?>">
                                                    $<?php echo number_format($service['fee_amount'] - $total_linked_cost, 2); ?>
                                                </td>
                                            </tr>
                                            <?php if ($service['tax_rate'] > 0): ?>
                                            <tr>
                                                <td class="font-weight-bold">Tax (<?php echo $service['tax_rate']; ?>%):</td>
                                                <td class="text-right font-weight-bold text-warning">
                                                    $<?php echo number_format($service['fee_amount'] * $service['tax_rate'] / 100, 2); ?>
                                                </td>
                                            </tr>
                                            <tr class="bg-light">
                                                <td class="font-weight-bold">Total with Tax:</td>
                                                <td class="text-right font-weight-bold text-primary">
                                                    $<?php echo number_format($service['fee_amount'] + ($service['fee_amount'] * $service['tax_rate'] / 100), 2); ?>
                                                </td>
                                            </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
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
                            <a href="medical_service_edit.php?medical_service_id=<?php echo $medical_service_id; ?>" class="btn btn-warning">
                                <i class="fas fa-edit mr-2"></i>Edit Service
                            </a>
                            <a href="medical_services.php" class="btn btn-outline-primary">
                                <i class="fas fa-list mr-2"></i>View All Services
                            </a>
                            <a href="medical_service_add.php" class="btn btn-outline-success">
                                <i class="fas fa-plus mr-2"></i>Add New Service
                            </a>
                            <?php if ($service['is_active']): ?>
                                <button type="button" class="btn btn-outline-danger" onclick="confirmDeactivate()">
                                    <i class="fas fa-times mr-2"></i>Deactivate
                                </button>
                            <?php else: ?>
                                <button type="button" class="btn btn-outline-success" onclick="confirmActivate()">
                                    <i class="fas fa-check mr-2"></i>Activate
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Status Information -->
                <div class="card card-info">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-chart-bar mr-2"></i>Status Information</h3>
                    </div>
                    <div class="card-body">
                        <div class="list-group list-group-flush">
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                                Status
                                <span class="badge badge-<?php echo $service['is_active'] ? 'success' : 'danger'; ?>">
                                    <?php echo $service['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </div>
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                                Created By
                                <span class="text-muted"><?php echo htmlspecialchars($service['created_by_name'] ?: 'System'); ?></span>
                            </div>
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                                Created Date
                                <span class="text-muted"><?php echo date('M j, Y g:i A', strtotime($service['created_date'])); ?></span>
                            </div>
                            <?php if ($service['updated_date']): ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                                Last Updated
                                <span class="text-muted"><?php echo date('M j, Y g:i A', strtotime($service['updated_date'])); ?></span>
                            </div>
                            <?php if ($service['updated_by_name']): ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                                Updated By
                                <span class="text-muted"><?php echo htmlspecialchars($service['updated_by_name']); ?></span>
                            </div>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Service Statistics -->
                <div class="card card-warning">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-chart-pie mr-2"></i>Service Statistics</h3>
                    </div>
                    <div class="card-body">
                        <div class="list-group list-group-flush">
                            <?php if (!empty($linked_accounts)): ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                                Linked Accounts
                                <span class="badge badge-secondary badge-pill"><?php echo count($linked_accounts); ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                                Medical Components
                                <span class="badge badge-info badge-pill"><?php echo count($linked_components); ?></span>
                            </div>
                            <?php if (!empty($linked_inventory)): ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                                Inventory Items
                                <span class="badge badge-warning badge-pill"><?php echo count($linked_inventory); ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                                Total Components Cost
                                <span class="text-success">$<?php echo number_format($total_linked_cost, 2); ?></span>
                            </div>
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                                Service Markup
                                <span class="text-<?php echo ($service['fee_amount'] - $total_linked_cost) >= 0 ? 'success' : 'danger'; ?>">
                                    $<?php echo number_format($service['fee_amount'] - $total_linked_cost, 2); ?>
                                </span>
                            </div>
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                                Markup Percentage
                                <span class="text-<?php echo ($service['fee_amount'] - $total_linked_cost) >= 0 ? 'success' : 'danger'; ?>">
                                    <?php 
                                    if ($total_linked_cost > 0) {
                                        $markup_percentage = (($service['fee_amount'] - $total_linked_cost) / $total_linked_cost) * 100;
                                        echo number_format($markup_percentage, 1) . '%';
                                    } else {
                                        echo 'N/A';
                                    }
                                    ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div class="card card-secondary">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-history mr-2"></i>Recent Activity</h3>
                    </div>
                    <div class="card-body">
                        <?php if ($activity_result->num_rows > 0): ?>
                            <div class="list-group list-group-flush">
                                <?php while ($activity = $activity_result->fetch_assoc()): ?>
                                    <div class="list-group-item px-0 py-2">
                                        <div class="d-flex w-100 justify-content-between">
                                            <small class="text-primary"><?php echo htmlspecialchars($activity['user_name'] ?: 'System'); ?></small>
                                            <small class="text-muted"><?php echo date('M j, g:i A', strtotime($activity['created_at'])); ?></small>
                                        </div>
                                        <p class="mb-1 small"><?php echo htmlspecialchars($activity['description']); ?></p>
                                    </div>
                                <?php endwhile; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-muted mb-0 text-center">
                                <i class="fas fa-info-circle mr-1"></i>
                                No recent activity
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
// Helper function for service type badge colors
function getServiceTypeBadgeColor($service_type) {
    switch ($service_type) {
        case 'Consultation': return 'primary';
        case 'Procedure': return 'warning';
        case 'LabTest': return 'info';
        case 'Imaging': return 'success';
        case 'Vaccination': return 'success';
        case 'Package': return 'info';
        case 'Bed': return 'danger';
        case 'Other': return 'secondary';
        default: return 'secondary';
    }
}

// Helper function for account type badge colors
function getAccountTypeBadgeColor($account_type) {
    switch ($account_type) {
        case 'revenue': return 'success';
        case 'cogs': return 'warning';
        case 'inventory': return 'info';
        case 'tax': return 'danger';
        default: return 'secondary';
    }
}

// Helper function for component type badge colors
function getComponentTypeBadgeColor($component_type) {
    switch ($component_type) {
        case 'LabTest': return 'info';
        case 'Radiology': return 'success';
        case 'Bed': return 'danger';
        default: return 'secondary';
    }
}
?>

<script>
function confirmDeactivate() {
    if (confirm('Are you sure you want to deactivate this service? It will no longer be available for booking.')) {
        window.location.href = 'medical_service_deactivate.php?medical_service_id=<?php echo $medical_service_id; ?>';
    }
}

function confirmActivate() {
    if (confirm('Are you sure you want to activate this service? It will be available for booking.')) {
        window.location.href = 'medical_service_activate.php?medical_service_id=<?php echo $medical_service_id; ?>';
    }
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + E to edit
    if (e.ctrlKey && e.keyCode === 69) {
        e.preventDefault();
        window.location.href = 'medical_service_edit.php?medical_service_id=<?php echo $medical_service_id; ?>';
    }
    // Ctrl + L to go back to list
    if (e.ctrlKey && e.keyCode === 76) {
        e.preventDefault();
        window.location.href = 'medical_services.php';
    }
});
</script>

<style>
.card {
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    margin-bottom: 1rem;
}
.table th {
    font-weight: 600;
}
.table-sm td {
    padding: 0.25rem 0.5rem;
}
</style>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
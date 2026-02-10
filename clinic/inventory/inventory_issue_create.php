<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Initialize variables
$items = [];
$locations = [];
$batches = [];
$patients = [];
$departments = [];
$pre_selected_item_id = intval($_GET['item_id'] ?? 0);

// Generate issue number
$issue_number = "ISS-" . date('Ymd') . "-" . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);

// Get active inventory locations
$locations_sql = "SELECT location_id, location_name, location_type
                  FROM inventory_locations 
                  WHERE is_active = 1 
                  ORDER BY location_type, location_name";
$locations_result = $mysqli->query($locations_sql);
while ($location = $locations_result->fetch_assoc()) {
    $locations[] = $location;
}

// Get active inventory items with category info
$items_sql = "SELECT 
                i.item_id, 
                i.item_name, 
                i.item_code,
                i.unit_of_measure,
                i.requires_batch,
                i.is_drug,
                c.category_name
              FROM inventory_items i
              LEFT JOIN inventory_categories c ON i.category_id = c.category_id
              WHERE i.is_active = 1 AND i.status = 'active'
              ORDER BY i.item_name";
$items_result = $mysqli->query($items_sql);
while ($item = $items_result->fetch_assoc()) {
    $items[] = $item;
}

// Get batches with stock information for issuing
$batches_sql = "SELECT 
                  b.batch_id,
                  b.batch_number,
                  b.expiry_date,
                  b.item_id,
                  b.manufacturer,
                  i.item_name,
                  i.item_code,
                  i.unit_of_measure,
                  i.is_drug,
                  COALESCE(SUM(ils.quantity), 0) as total_stock,
                  GROUP_CONCAT(CONCAT(l.location_name, ' (', l.location_type, '): ', ils.quantity) SEPARATOR '; ') as locations_info
                FROM inventory_batches b
                INNER JOIN inventory_items i ON b.item_id = i.item_id
                LEFT JOIN inventory_location_stock ils ON b.batch_id = ils.batch_id AND ils.is_active = 1
                LEFT JOIN inventory_locations l ON ils.location_id = l.location_id
                WHERE b.is_active = 1 
                AND b.expiry_date >= CURDATE()
                GROUP BY b.batch_id, b.batch_number, b.expiry_date, b.item_id, b.manufacturer, i.item_name, i.item_code, i.unit_of_measure, i.is_drug
                HAVING total_stock > 0
                ORDER BY b.expiry_date ASC, b.batch_number";
$batches_result = $mysqli->query($batches_sql);
$all_batches = [];
while ($batch = $batches_result->fetch_assoc()) {
    $all_batches[] = $batch;
}

// Get active patients
$patients_sql = "SELECT patient_id, first_name, last_name,patient_mrn
                 FROM patients 
                 WHERE archived_at IS NULL 
                 AND patient_status = 'Active'
                 ORDER BY last_name";
$patients_result = $mysqli->query($patients_sql);
while ($patient = $patients_result->fetch_assoc()) {
    $patients[] = $patient;
}

// Get departments
$departments_sql = "SELECT department_id, department_name 
                    FROM departments 
                    WHERE department_archived_at IS NULL 
                    ORDER BY department_name";
$departments_result = $mysqli->query($departments_sql);
while ($department = $departments_result->fetch_assoc()) {
    $departments[] = $department;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    $issue_date = sanitizeInput($_POST['issue_date']);
    $from_location_id = intval($_POST['from_location_id']);
    $patient_id = intval($_POST['patient_id'] ?? 0);
    $department_id = intval($_POST['department_id'] ?? 0);
    $issued_to_type = sanitizeInput($_POST['issued_to_type'] ?? 'patient');
    $issued_to_name = sanitizeInput($_POST['issued_to_name'] ?? '');
    $issued_to_id = sanitizeInput($_POST['issued_to_id'] ?? '');
    $issue_type = sanitizeInput($_POST['issue_type'] ?? 'consumption');
    $notes = sanitizeInput($_POST['notes']);
    
    // Get issue items from POST data
    $issue_items = [];
    if (isset($_POST['batch_id']) && is_array($_POST['batch_id'])) {
        foreach ($_POST['batch_id'] as $index => $batch_id) {
            if (!empty($batch_id) && !empty($_POST['quantity'][$index])) {
                $issue_items[] = [
                    'batch_id' => intval($batch_id),
                    'item_name' => sanitizeInput($_POST['item_name'][$index]),
                    'batch_number' => sanitizeInput($_POST['batch_number'][$index]),
                    'quantity' => floatval($_POST['quantity'][$index]),
                    'available_stock' => floatval($_POST['available_stock'][$index]),
                    'notes' => sanitizeInput($_POST['item_notes'][$index] ?? ''),
                    'selling_price' => floatval($_POST['selling_price'][$index] ?? 0)
                ];
            }
        }
    }

    // Validate CSRF token
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        header("Location: inventory_issue_create.php");
        exit;
    }

    // Validate required fields
    if (empty($issue_date) || empty($from_location_id) || count($issue_items) === 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please fill in all required fields and add at least one item.";
        header("Location: inventory_issue_create.php");
        exit;
    }

    // Validate issued to information
    if ($issued_to_type === 'patient' && empty($patient_id)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please select a patient.";
        header("Location: inventory_issue_create.php");
        exit;
    } elseif ($issued_to_type === 'department' && empty($department_id)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please select a department.";
        header("Location: inventory_issue_create.php");
        exit;
    } elseif ($issued_to_type === 'other' && empty($issued_to_name)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please enter the name of who the items are issued to.";
        header("Location: inventory_issue_create.php");
        exit;
    }

    // Validate issue items
    $total_amount = 0;
    foreach ($issue_items as $item) {
        if ($item['quantity'] <= 0) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Please check all item quantities are valid.";
            header("Location: inventory_issue_create.php");
            exit;
        }
        
        // Check if quantity exceeds available stock
        if ($item['quantity'] > $item['available_stock']) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Quantity exceeds available stock for batch " . $item['batch_number'];
            header("Location: inventory_issue_create.php");
            exit;
        }
        
        // Calculate total amount
        $total_amount += $item['quantity'] * $item['selling_price'];
    }

    // Start transaction
    $mysqli->begin_transaction();

    try {
        // Prepare issued_to information
        $issued_to_info = '';
        if ($issued_to_type === 'patient') {
            $issued_to_info = "Patient: " . $issued_to_name;
        } elseif ($issued_to_type === 'department') {
            $issued_to_info = "Department: " . $issued_to_name;
        } else {
            $issued_to_info = "Other: " . $issued_to_name;
        }

        // Create inventory transactions for each item
        foreach ($issue_items as $item) {
            // Get item details for transaction
            $item_sql = "SELECT item_id FROM inventory_batches WHERE batch_id = ?";
            $item_stmt = $mysqli->prepare($item_sql);
            $item_stmt->bind_param("i", $item['batch_id']);
            $item_stmt->execute();
            $item_result = $item_stmt->get_result();
            $batch_data = $item_result->fetch_assoc();
            $item_stmt->close();
            
            if (!$batch_data) {
                throw new Exception("Batch not found: " . $item['batch_id']);
            }
            
            // Create transaction
            $transaction_sql = "INSERT INTO inventory_transactions (
                transaction_type, item_id, batch_id, from_location_id,
                quantity, unit_cost, created_by, reference_type, reason
            ) VALUES ('ISSUE', ?, ?, ?, ?, ?, ?, 'ISSUE', ?)";
            
            // Get unit cost from inventory_location_stock
            $cost_sql = "SELECT unit_cost FROM inventory_location_stock 
                        WHERE batch_id = ? AND location_id = ? AND is_active = 1
                        LIMIT 1";
            $cost_stmt = $mysqli->prepare($cost_sql);
            $cost_stmt->bind_param("ii", $item['batch_id'], $from_location_id);
            $cost_stmt->execute();
            $cost_result = $cost_stmt->get_result();
            $cost_data = $cost_result->fetch_assoc();
            $cost_stmt->close();
            
            $unit_cost = $cost_data['unit_cost'] ?? 0;
            $reason = "Issued to: " . $issued_to_info . ". " . $notes;
            
            $transaction_stmt = $mysqli->prepare($transaction_sql);
            $transaction_stmt->bind_param(
                "iiiddis",
                $batch_data['item_id'],
                $item['batch_id'],
                $from_location_id,
                $item['quantity'],
                $unit_cost,
                $session_user_id,
                $reason
            );
            $transaction_stmt->execute();
            $transaction_stmt->close();
            
            // Update inventory_location_stock
            $update_sql = "UPDATE inventory_location_stock 
                          SET quantity = quantity - ?,
                          last_movement_at = NOW(),
                          updated_by = ?,
                          updated_at = NOW()
                          WHERE batch_id = ? 
                          AND location_id = ?
                          AND is_active = 1";
            $update_stmt = $mysqli->prepare($update_sql);
            $update_stmt->bind_param("diii", $item['quantity'], $session_user_id, $item['batch_id'], $from_location_id);
            $update_stmt->execute();
            
            if ($update_stmt->affected_rows === 0) {
                throw new Exception("Failed to update stock for batch " . $item['batch_number']);
            }
            $update_stmt->close();
        }

        // Log the issue
        $log_sql = "INSERT INTO logs SET
                  log_type = 'Inventory',
                  log_action = 'Issue Create',
                  log_description = ?,
                  log_ip = ?,
                  log_user_agent = ?,
                  log_user_id = ?,
                  log_created_at = NOW()";
        $log_stmt = $mysqli->prepare($log_sql);
        $log_description = "Issued " . count($issue_items) . " items from location #" . $from_location_id . " to " . $issued_to_info;
        $log_stmt->bind_param("sssi", $log_description, $session_ip, $session_user_agent, $session_user_id);
        $log_stmt->execute();
        $log_stmt->close();

        $mysqli->commit();

        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Issue <strong>$issue_number</strong> created successfully! Total amount: $" . number_format($total_amount, 2);
        header("Location: inventory_transactions.php?type=ISSUE");
        exit;

    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error creating issue: " . $e->getMessage();
        header("Location: inventory_issue_create.php");
        exit;
    }
}
?>

<div class="card">
    <div class="card-header bg-danger py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-sign-out-alt mr-2"></i>Create Inventory Issue
            </h3>
            <div class="card-tools">
                <a href="inventory_transactions.php?type=ISSUE" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Issues
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

        <form method="POST" id="issueForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <div class="row">
                <div class="col-md-8">
                    <!-- Issue Information -->
                    <div class="card card-danger">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-info-circle mr-2"></i>Issue Information</h3>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="issue_number">Issue Number</label>
                                        <input type="text" class="form-control" id="issue_number" 
                                               value="<?php echo htmlspecialchars($issue_number); ?>" readonly>
                                        <small class="form-text text-muted">Auto-generated issue number</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="issue_date">Issue Date *</label>
                                        <input type="date" class="form-control" id="issue_date" name="issue_date" 
                                               value="<?php echo date('Y-m-d'); ?>" required>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="from_location_id">From Location *</label>
                                        <select class="form-control select2" id="from_location_id" name="from_location_id" required>
                                            <option value="">- Select Source Location -</option>
                                            <?php foreach ($locations as $location): ?>
                                                <option value="<?php echo $location['location_id']; ?>">
                                                    <?php echo htmlspecialchars($location['location_name']); ?>
                                                    (<?php echo $location['location_type']; ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="issue_type">Issue Type *</label>
                                        <select class="form-control" id="issue_type" name="issue_type" required>
                                            <option value="consumption" selected>Consumption</option>
                                            <option value="dispense">Dispense to Patient</option>
                                            <option value="department_use">Department Use</option>
                                            <option value="emergency">Emergency Issue</option>
                                            <option value="wastage">Wastage</option>
                                            <option value="other">Other</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <!-- Issued To Section -->
                            <div class="row mb-3">
                                <div class="col-md-12">
                                    <div class="form-group">
                                        <label>Issued To *</label>
                                        <div class="row">
                                            <div class="col-md-4">
                                                <select class="form-control" id="issued_to_type" name="issued_to_type" required>
                                                    <option value="patient" selected>Patient</option>
                                                    <option value="department">Department</option>
                                                    <option value="other">Other</option>
                                                </select>
                                            </div>
                                            <div class="col-md-8" id="issued_to_container">
                                                <!-- Patient selection (default) -->
                                                <select class="form-control select2" id="patient_id" name="patient_id" required>
                                                    <option value="">- Select Patient -</option>
                                                    <?php foreach ($patients as $patient): ?>
                                                        <option value="<?php echo $patient['patient_id']; ?>">
                                                            <?php echo htmlspecialchars($patient['first_name'] . '' . $patient['last_name']); ?>
                                                            (<?php echo htmlspecialchars($patient['patient_mrn']); ?>)
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="notes">Issue Notes</label>
                                <textarea class="form-control" id="notes" name="notes" rows="3" 
                                          placeholder="Purpose of issue, diagnosis, prescription details, etc..." 
                                          maxlength="500"></textarea>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Issued By</label>
                                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($session_name); ?>" readonly>
                                        <small class="form-text text-muted">Your name will be recorded as the issuer</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Issue Items -->
                    <div class="card card-warning">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h3 class="card-title"><i class="fas fa-list-ol mr-2"></i>Issue Items</h3>
                            <button type="button" class="btn btn-sm btn-success" onclick="showBatchModal()">
                                <i class="fas fa-plus mr-1"></i>Add Item
                            </button>
                        </div>
                        <div class="card-body">
                            <div id="issue_items_container">
                                <!-- Issue items will be added here dynamically -->
                                <div class="text-center text-muted py-4" id="no_items_message">
                                    <i class="fas fa-pills fa-2x mb-2"></i>
                                    <p>No items added yet. Click "Add Item" to start.</p>
                                </div>
                            </div>
                            
                            <!-- Issue Summary -->
                            <div class="row mt-4">
                                <div class="col-md-6 offset-md-6">
                                    <table class="table table-sm table-borderless">
                                        <tr>
                                            <td class="text-right"><strong>Total Items:</strong></td>
                                            <td class="text-right" width="150"><strong><span id="item_count">0</span> items</strong></td>
                                        </tr>
                                        <tr>
                                            <td class="text-right"><strong>Total Quantity:</strong></td>
                                            <td class="text-right"><strong><span id="total_quantity">0.000</span></strong></td>
                                        </tr>
                                        <tr class="border-top">
                                            <td class="text-right"><strong>Total Amount:</strong></td>
                                            <td class="text-right"><strong>$<span id="total_amount">0.00</span></strong></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
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
                                <button type="submit" class="btn btn-success btn-lg" id="submitBtn">
                                    <i class="fas fa-save mr-2"></i>Create Issue
                                </button>
                                <button type="reset" class="btn btn-outline-secondary" onclick="resetForm()">
                                    <i class="fas fa-undo mr-2"></i>Reset Form
                                </button>
                                <a href="inventory_transactions.php?type=ISSUE" class="btn btn-outline-danger">
                                    <i class="fas fa-times mr-2"></i>Cancel
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Source Location Information -->
                    <div class="card card-info">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-sign-out-alt mr-2"></i>Source Location</h3>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-3">
                                <i class="fas fa-warehouse fa-3x text-info mb-2"></i>
                                <h5 id="from_location_name">Select Location</h5>
                            </div>
                            <hr>
                            <div class="small" id="from_location_details">
                                <div class="text-muted text-center">Select a source location</div>
                            </div>
                        </div>
                    </div>

                    <!-- Issued To Information -->
                    <div class="card card-primary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-user mr-2"></i>Issued To</h3>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-3">
                                <i class="fas fa-user-injured fa-3x text-primary mb-2"></i>
                                <h5 id="issued_to_display">Select Patient</h5>
                            </div>
                            <hr>
                            <div class="small" id="issued_to_details">
                                <div class="text-muted text-center">Select who the items are issued to</div>
                            </div>
                        </div>
                    </div>

                    <!-- Available Batches -->
                    <div class="card card-warning">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-pills mr-2"></i>Available Stock</h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-tool" onclick="refreshBatches()" title="Refresh">
                                    <i class="fas fa-sync-alt"></i>
                                </button>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div class="list-group list-group-flush" id="batchesList" style="max-height: 300px; overflow-y: auto;">
                                <?php foreach ($all_batches as $batch): ?>
                                    <div class="list-group-item list-group-item-action batch-item" 
                                         data-batch-id="<?php echo $batch['batch_id']; ?>"
                                         data-item-name="<?php echo htmlspecialchars($batch['item_name']); ?>"
                                         data-batch-number="<?php echo htmlspecialchars($batch['batch_number']); ?>"
                                         data-expiry-date="<?php echo $batch['expiry_date']; ?>"
                                         data-manufacturer="<?php echo htmlspecialchars($batch['manufacturer'] ?? ''); ?>"
                                         data-available-stock="<?php echo $batch['total_stock']; ?>"
                                         data-unit-measure="<?php echo htmlspecialchars($batch['unit_of_measure']); ?>"
                                         data-is-drug="<?php echo $batch['is_drug']; ?>"
                                         style="cursor: pointer;">
                                        <div class="d-flex w-100 justify-content-between">
                                            <h6 class="mb-1">
                                                <?php echo htmlspecialchars($batch['item_name']); ?>
                                                <?php if ($batch['is_drug']): ?>
                                                    <span class="badge badge-danger ml-1">Drug</span>
                                                <?php endif; ?>
                                            </h6>
                                            <small class="text-muted"><?php echo number_format($batch['total_stock'], 3); ?></small>
                                        </div>
                                        <p class="mb-1 small">
                                            <strong>Batch:</strong> <?php echo htmlspecialchars($batch['batch_number']); ?>
                                            <?php if ($batch['manufacturer']): ?>
                                                • <strong>Mfr:</strong> <?php echo htmlspecialchars($batch['manufacturer']); ?>
                                            <?php endif; ?>
                                        </p>
                                        <small class="text-muted">
                                            <i class="fas fa-calendar-alt mr-1"></i>
                                            Expires: <?php echo date('M j, Y', strtotime($batch['expiry_date'])); ?>
                                            • <?php echo htmlspecialchars($batch['unit_of_measure']); ?>
                                        </small>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (empty($all_batches)): ?>
                                    <div class="list-group-item text-center text-muted">
                                        <i class="fas fa-box-open fa-2x mb-2"></i>
                                        <p>No batches with available stock found</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Issue Preview -->
                    <div class="card card-danger">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-eye mr-2"></i>Issue Preview</h3>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-3">
                                <i class="fas fa-sign-out-alt fa-3x text-danger mb-2"></i>
                                <h5 id="preview_issue_number"><?php echo htmlspecialchars($issue_number); ?></h5>
                                <div class="text-muted" id="preview_item_count">0 items</div>
                            </div>
                            <hr>
                            <div class="small">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>From:</span>
                                    <span class="font-weight-bold" id="preview_from_location">-</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Issued To:</span>
                                    <span class="font-weight-bold" id="preview_issued_to">-</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Issue Date:</span>
                                    <span class="font-weight-bold" id="preview_issue_date">-</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Issue Type:</span>
                                    <span class="font-weight-bold" id="preview_issue_type">Consumption</span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Total Amount:</span>
                                    <span class="font-weight-bold text-success">$<span id="preview_total_amount">0.00</span></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Tips -->
                    <div class="card card-light">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-lightbulb mr-2"></i>Quick Tips</h3>
                        </div>
                        <div class="card-body">
                            <div class="small">
                                <div class="mb-2">
                                    <strong>Drug Items:</strong> Marked with <span class="badge badge-danger">Drug</span> badge
                                </div>
                                <div class="mb-2">
                                    <strong>Quantity:</strong> Use decimal format for partial units.
                                </div>
                                <div class="mb-2">
                                    <strong>Selling Price:</strong> Enter selling price for billing purposes.
                                </div>
                                <div>
                                    <strong>Expiry:</strong> Items expiring soon are highlighted in yellow.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Batch Selection Modal -->
<div class="modal fade" id="batchSelectionModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-pills mr-2"></i>Select Batch for Issue</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="row mb-3">
                    <div class="col-md-12">
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text"><i class="fas fa-search"></i></span>
                            </div>
                            <input type="text" class="form-control" id="batchSearch" placeholder="Search batches by item name, batch number, or manufacturer...">
                        </div>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover" id="batchesTable">
                        <thead class="bg-light">
                            <tr>
                                <th>Item</th>
                                <th>Batch Number</th>
                                <th>Manufacturer</th>
                                <th class="text-center">Expiry Date</th>
                                <th class="text-center">Available Stock</th>
                                <th class="text-center">Unit</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($all_batches as $batch): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($batch['item_name']); ?></strong>
                                        <?php if ($batch['is_drug']): ?>
                                            <span class="badge badge-danger ml-1">Drug</span>
                                        <?php endif; ?>
                                        <br>
                                        <small class="text-muted"><?php echo htmlspecialchars($batch['item_code']); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($batch['batch_number']); ?></td>
                                    <td><?php echo $batch['manufacturer'] ? htmlspecialchars($batch['manufacturer']) : '-'; ?></td>
                                    <td class="text-center <?php echo strtotime($batch['expiry_date']) < strtotime('+30 days') ? 'text-warning' : ''; ?>">
                                        <?php echo date('M j, Y', strtotime($batch['expiry_date'])); ?>
                                        <?php if (strtotime($batch['expiry_date']) < strtotime('+30 days')): ?>
                                            <br><small class="text-danger">Expiring soon</small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge <?php echo $batch['total_stock'] > 0 ? 'badge-success' : 'badge-secondary'; ?>">
                                            <?php echo number_format($batch['total_stock'], 3); ?>
                                        </span>
                                    </td>
                                    <td class="text-center"><?php echo htmlspecialchars($batch['unit_of_measure']); ?></td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-sm btn-success select-batch-btn" 
                                                data-batch-id="<?php echo $batch['batch_id']; ?>"
                                                data-item-name="<?php echo htmlspecialchars($batch['item_name']); ?>"
                                                data-batch-number="<?php echo htmlspecialchars($batch['batch_number']); ?>"
                                                data-expiry-date="<?php echo $batch['expiry_date']; ?>"
                                                data-manufacturer="<?php echo htmlspecialchars($batch['manufacturer'] ?? ''); ?>"
                                                data-available-stock="<?php echo $batch['total_stock']; ?>"
                                                data-unit-measure="<?php echo htmlspecialchars($batch['unit_of_measure']); ?>"
                                                data-is-drug="<?php echo $batch['is_drug']; ?>"
                                                <?php echo $batch['total_stock'] <= 0 ? 'disabled' : ''; ?>>
                                            <i class="fas fa-plus mr-1"></i>Add
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
// Global variables
let itemCounter = 0;
const batches = <?php echo json_encode($all_batches); ?>;
const locations = <?php echo json_encode($locations); ?>;
const patients = <?php echo json_encode($patients); ?>;
const departments = <?php echo json_encode($departments); ?>;

// Helper function to escape HTML
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Function to add batch to issue
function addBatchToIssue(batchId, itemName, batchNumber, expiryDate, manufacturer, availableStock, unitMeasure, isDrug) {
    try {
        itemCounter++;
        
        const manufacturerText = manufacturer ? `<small class="form-text text-muted">Manufacturer: ${escapeHtml(manufacturer)}</small>` : '';
        const expiryText = `<small class="form-text text-muted">Expires: ${escapeHtml(expiryDate)}</small>`;
        const drugBadge = isDrug == 1 ? '<span class="badge badge-danger ml-1">Drug</span>' : '';
        
        const itemRow = `
            <div class="issue-item-row border rounded p-3 mb-3" id="item_row_${itemCounter}">
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Item & Batch *</label>
                            <input type="hidden" name="batch_id[]" value="${escapeHtml(batchId)}">
                            <input type="hidden" name="item_name[]" value="${escapeHtml(itemName)}">
                            <input type="hidden" name="batch_number[]" value="${escapeHtml(batchNumber)}">
                            <input type="hidden" name="available_stock[]" value="${escapeHtml(availableStock)}">
                            <input type="text" class="form-control" value="${escapeHtml(itemName)} ${drugBadge}" readonly>
                            ${manufacturerText}
                            ${expiryText}
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Available</label>
                            <input type="text" class="form-control" value="${parseFloat(availableStock).toFixed(3)} ${escapeHtml(unitMeasure)}" readonly>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Quantity *</label>
                            <input type="number" class="form-control quantity" name="quantity[]" 
                                   min="0.001" step="0.001" max="${availableStock}" 
                                   value="${Math.min(1, availableStock).toFixed(3)}" required
                                   onchange="calculateTotals()">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Selling Price ($)</label>
                            <input type="number" class="form-control selling-price" name="selling_price[]" 
                                   min="0" step="0.01" value="0.00" required
                                   onchange="calculateTotals()">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Notes</label>
                            <input type="text" class="form-control" name="item_notes[]" 
                                   placeholder="Item notes..." maxlength="255">
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-10">
                        <div class="form-group mb-0">
                            <input type="text" class="form-control" placeholder="Optional: Dosage instructions, frequency, etc." 
                                   name="item_instructions[]" maxlength="255">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <button type="button" class="btn btn-danger btn-block" onclick="removeIssueItem(${itemCounter})">
                            <i class="fas fa-trash"></i> Remove
                        </button>
                    </div>
                </div>
            </div>
        `;
        
        $('#no_items_message').hide();
        $('#issue_items_container').append(itemRow);
        $('#batchSelectionModal').modal('hide');
        
        calculateTotals();
        updatePreview();
        
        // Show success message
        showToast('success', `Added ${itemName} (${batchNumber}) to issue`);
    } catch (error) {
        console.error('Error adding batch:', error);
        showToast('error', 'Error adding batch. Please try again.');
    }
}

// Function to remove issue item
function removeIssueItem(itemId) {
    if (confirm('Are you sure you want to remove this item?')) {
        $('#item_row_' + itemId).remove();
        
        // Show no items message if no items left
        if ($('.issue-item-row').length === 0) {
            $('#no_items_message').show();
        }
        
        calculateTotals();
        updatePreview();
        
        showToast('info', 'Item removed from issue');
    }
}

// Show batch selection modal
function showBatchModal() {
    $('#batchSelectionModal').modal('show');
}

// Calculate totals
function calculateTotals() {
    let totalQuantity = 0;
    let totalAmount = 0;
    let itemCount = 0;
    
    $('.quantity').each(function(index) {
        const quantity = parseFloat($(this).val()) || 0;
        const sellingPrice = parseFloat($('.selling-price').eq(index).val()) || 0;
        
        totalQuantity += quantity;
        totalAmount += quantity * sellingPrice;
        itemCount++;
    });
    
    $('#item_count').text(itemCount);
    $('#total_quantity').text(totalQuantity.toFixed(3));
    $('#total_amount').text(totalAmount.toFixed(2));
    
    return { itemCount, totalQuantity, totalAmount };
}

// Update preview
function updatePreview() {
    const totals = calculateTotals();
    $('#preview_item_count').text(totals.itemCount + ' item' + (totals.itemCount !== 1 ? 's' : ''));
    $('#preview_total_amount').text(totals.totalAmount.toFixed(2));
}

// Refresh batches list
function refreshBatches() {
    location.reload();
}

// Reset form
function resetForm() {
    if (confirm('Are you sure you want to reset the entire form? All items will be removed.')) {
        $('#issueForm')[0].reset();
        $('#issue_items_container').empty();
        $('#no_items_message').show();
        calculateTotals();
        updatePreview();
        $('.select2').val('').trigger('change');
        $('#issue_date').val('<?php echo date('Y-m-d'); ?>').trigger('change');
        $('#issue_type').val('consumption').trigger('change');
        $('#issued_to_type').val('patient').trigger('change');
        updateIssuedToDisplay();
        
        showToast('info', 'Form has been reset');
    }
}

// Update issued to display based on selection
function updateIssuedToDisplay() {
    const issuedToType = $('#issued_to_type').val();
    let containerHtml = '';
    let displayText = '';
    
    if (issuedToType === 'patient') {
        containerHtml = `
            <select class="form-control select2" id="patient_id" name="patient_id" required>
                <option value="">- Select Patient -</option>
                ${patients.map(patient => `
                    <option value="${patient.patient_id}">
                        ${escapeHtml(patient.first_name)} (${escapeHtml(patient.patient_mrn)})
                    </option>
                `).join('')}
            </select>
        `;
        displayText = 'Select Patient';
    } else if (issuedToType === 'department') {
        containerHtml = `
            <select class="form-control select2" id="department_id" name="department_id" required>
                <option value="">- Select Department -</option>
                ${departments.map(dept => `
                    <option value="${dept.department_id}">
                        ${escapeHtml(dept.department_name)}
                    </option>
                `).join('')}
            </select>
        `;
        displayText = 'Select Department';
    } else {
        containerHtml = `
            <div class="input-group">
                <input type="text" class="form-control" id="issued_to_name" name="issued_to_name" 
                       placeholder="Enter name or identifier..." required>
                <div class="input-group-append">
                    <input type="text" class="form-control" id="issued_to_id" name="issued_to_id" 
                           placeholder="ID/Reference" style="max-width: 100px;">
                </div>
            </div>
        `;
        displayText = 'Enter Name';
    }
    
    $('#issued_to_container').html(containerHtml);
    $('#issued_to_display').text(displayText);
    
    if (issuedToType !== 'other') {
        $('.select2').select2();
    }
    
    // Update preview
    $('#preview_issued_to').text(displayText);
}

// Show toast notification
function showToast(type, message) {
    // Create toast container if not exists
    if ($('.toast-container').length === 0) {
        $('body').append('<div class="toast-container" style="position: fixed; top: 20px; right: 20px; z-index: 1050;"></div>');
    }
    
    const toast = $(`
        <div class="toast" role="alert" aria-live="assertive" aria-atomic="true" data-delay="5000">
            <div class="toast-header bg-${type} text-white">
                <i class="fas fa-${getToastIcon(type)} mr-2"></i>
                <strong class="mr-auto">${type.charAt(0).toUpperCase() + type.slice(1)}</strong>
                <button type="button" class="ml-2 mb-1 close text-white" data-dismiss="toast">
                    <span>&times;</span>
                </button>
            </div>
            <div class="toast-body">
                ${message}
            </div>
        </div>
    `);
    
    $('.toast-container').append(toast);
    toast.toast('show');
    
    // Remove toast after it's hidden
    toast.on('hidden.bs.toast', function () {
        $(this).remove();
    });
}

function getToastIcon(type) {
    switch(type) {
        case 'success': return 'check-circle';
        case 'error': 
        case 'danger': return 'exclamation-triangle';
        case 'warning': return 'exclamation-circle';
        case 'info': return 'info-circle';
        default: return 'info-circle';
    }
}

$(document).ready(function() {
    // Initialize Select2
    $('.select2').select2();

    // Initialize DataTable for batches modal
    $('#batchesTable').DataTable({
        pageLength: 10,
        order: [[3, 'asc']], // Sort by expiry date
        language: {
            search: "Search batches:"
        },
        drawCallback: function() {
            // Re-attach event listeners after DataTable redraws
            attachModalEventListeners();
        }
    });

    // Search functionality for batches
    $('#batchSearch').on('keyup', function() {
        const searchTerm = $(this).val().toLowerCase();
        $('#batchesTable tbody tr').each(function() {
            const rowText = $(this).text().toLowerCase();
            $(this).toggle(rowText.indexOf(searchTerm) > -1);
        });
    });

    // Attach event listeners to modal buttons
    function attachModalEventListeners() {
        $('.select-batch-btn').off('click').on('click', function() {
            const batchId = $(this).data('batch-id');
            const itemName = $(this).data('item-name');
            const batchNumber = $(this).data('batch-number');
            const expiryDate = $(this).data('expiry-date');
            const manufacturer = $(this).data('manufacturer');
            const availableStock = $(this).data('available-stock');
            const unitMeasure = $(this).data('unit-measure');
            const isDrug = $(this).data('is-drug');
            
            addBatchToIssue(batchId, itemName, batchNumber, expiryDate, manufacturer, availableStock, unitMeasure, isDrug);
        });
    }

    // Attach click events to batch list items
    $('.batch-item').off('click').on('click', function() {
        const batchId = $(this).data('batch-id');
        const itemName = $(this).data('item-name');
        const batchNumber = $(this).data('batch-number');
        const expiryDate = $(this).data('expiry-date');
        const manufacturer = $(this).data('manufacturer');
        const availableStock = $(this).data('available-stock');
        const unitMeasure = $(this).data('unit-measure');
        const isDrug = $(this).data('is-drug');
        
        addBatchToIssue(batchId, itemName, batchNumber, expiryDate, manufacturer, availableStock, unitMeasure, isDrug);
    });

    // Initial attachment of event listeners
    attachModalEventListeners();

    // Update from location details when selected
    $('#from_location_id').on('change', function() {
        const locationId = $(this).val();
        const selectedOption = $(this).find('option:selected');
        
        if (locationId) {
            const locationText = selectedOption.text().split(' (');
            $('#from_location_name').text(locationText[0]);
            $('#preview_from_location').text(locationText[0]);
        } else {
            $('#from_location_name').text('Select Location');
            $('#preview_from_location').text('-');
        }
    });

    // Update issued to type display
    $('#issued_to_type').on('change', function() {
        updateIssuedToDisplay();
    });

    // Update issue date preview
    $('#issue_date').on('change', function() {
        $('#preview_issue_date').text($(this).val());
    });

    // Update issue type preview
    $('#issue_type').on('change', function() {
        $('#preview_issue_type').text($(this).val().charAt(0).toUpperCase() + $(this).val().slice(1));
    });

    // Update patient/department/other display when selected
    $(document).on('change', '#patient_id, #department_id, #issued_to_name', function() {
        const issuedToType = $('#issued_to_type').val();
        let displayText = '';
        
        if (issuedToType === 'patient') {
            const selectedPatient = $('#patient_id option:selected').text();
            displayText = selectedPatient || 'Select Patient';
        } else if (issuedToType === 'department') {
            const selectedDept = $('#department_id option:selected').text();
            displayText = selectedDept || 'Select Department';
        } else {
            const otherName = $('#issued_to_name').val();
            const otherId = $('#issued_to_id').val();
            displayText = otherName || 'Enter Name';
            if (otherId) {
                displayText += ` (${otherId})`;
            }
        }
        
        $('#issued_to_display').text(displayText);
        $('#preview_issued_to').text(displayText);
    });

    // Auto-set issue date to today
    $('#issue_date').trigger('change');
    $('#issue_type').trigger('change');
    updateIssuedToDisplay();

    // Form validation
    $('#issueForm').on('submit', function(e) {
        const issueDate = $('#issue_date').val();
        const fromLocationId = $('#from_location_id').val();
        const itemCount = $('.issue-item-row').length;
        
        let isValid = true;
        
        // Validate required fields
        if (!issueDate || !fromLocationId || itemCount === 0) {
            isValid = false;
        }
        
        // Validate issued to information
        const issuedToType = $('#issued_to_type').val();
        if (issuedToType === 'patient' && !$('#patient_id').val()) {
            isValid = false;
            $('#patient_id').addClass('is-invalid');
        } else if (issuedToType === 'department' && !$('#department_id').val()) {
            isValid = false;
            $('#department_id').addClass('is-invalid');
        } else if (issuedToType === 'other' && !$('#issued_to_name').val()) {
            isValid = false;
            $('#issued_to_name').addClass('is-invalid');
        }
        
        // Validate all items have valid quantities and prices
        $('.quantity').each(function() {
            const quantity = parseFloat($(this).val()) || 0;
            const maxQuantity = parseFloat($(this).attr('max')) || 0;
            
            if (quantity <= 0) {
                isValid = false;
                $(this).addClass('is-invalid');
            } else if (quantity > maxQuantity) {
                isValid = false;
                $(this).addClass('is-invalid');
                $(this).next('.invalid-feedback').remove();
                $('<div class="invalid-feedback">Quantity exceeds available stock.</div>').insertAfter($(this));
            } else {
                $(this).removeClass('is-invalid');
                $(this).next('.invalid-feedback').remove();
            }
        });
        
        $('.selling-price').each(function() {
            const price = parseFloat($(this).val()) || 0;
            
            if (price < 0) {
                isValid = false;
                $(this).addClass('is-invalid');
            } else {
                $(this).removeClass('is-invalid');
            }
        });
        
        if (!isValid) {
            e.preventDefault();
            showToast('error', 'Please fill in all required fields and ensure all items have valid quantities and prices.');
            return false;
        }
        
        // Show loading state
        const submitBtn = $('#submitBtn');
        submitBtn.html('<i class="fas fa-spinner fa-spin mr-2"></i>Creating...').prop('disabled', true);
    });

    // Keyboard shortcuts
    $(document).keydown(function(e) {
        // Ctrl + S to save
        if (e.ctrlKey && e.keyCode === 83) {
            e.preventDefault();
            if ($('#issueForm')[0].checkValidity()) {
                $('#issueForm').submit();
            } else {
                showToast('error', 'Please fill in all required fields before submitting.');
            }
        }
        // Escape to cancel
        if (e.keyCode === 27) {
            window.location.href = 'inventory_transactions.php?type=ISSUE';
        }
        // Ctrl + R to reset
        if (e.ctrlKey && e.keyCode === 82) {
            e.preventDefault();
            resetForm();
        }
        // Ctrl + A to add item
        if (e.ctrlKey && e.keyCode === 65) {
            e.preventDefault();
            showBatchModal();
        }
    });
});
</script>

<style>
.issue-item-row {
    background-color: #f8f9fa;
    border-left: 4px solid #dc3545 !important;
}

.issue-item-row:hover {
    background-color: #e9ecef;
}

#batchesTable_wrapper {
    margin: 0;
}

.select-batch-btn {
    cursor: pointer;
}

.badge-lg {
    font-size: 1rem;
    padding: 0.5rem 1rem;
}

.card-header.bg-danger {
    background: linear-gradient(45deg, #c82333, #dc3545) !important;
}

.form-control:read-only {
    background-color: #f8f9fa;
}

.batch-item:hover {
    background-color: #f8f9fa;
}

.list-group-item-action {
    transition: background-color 0.2s;
}

.modal-lg {
    max-width: 90%;
}

.dataTables_wrapper {
    margin-top: 0 !important;
}

.toast {
    min-width: 300px;
    max-width: 350px;
}

.btn:disabled {
    cursor: not-allowed;
    opacity: 0.6;
}

.table-sm td {
    padding: 0.3rem;
}

.table-sm th {
    padding: 0.5rem 0.3rem;
}

.card-header {
    padding: 0.5rem 1rem;
}

.card-title {
    font-size: 1.1rem;
    margin-bottom: 0;
}

.btn-sm {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
}

.badge-danger {
    background-color: #dc3545;
}
</style>

<?php 
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
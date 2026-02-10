<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Check permission
//enforceUserPermission('inventory_requisition_edit');

$requisition_id = intval($_GET['requisition_id'] ?? 0);

if ($requisition_id == 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Requisition ID is required.";
    header("Location: inventory_requisitions.php");
    exit;
}

// Get requisition details
$requisition_sql = "SELECT 
    r.*,
    u.user_name as requester_name,
    l.location_name,
    l.location_type,
    (SELECT COUNT(*) FROM inventory_requisition_items ri WHERE ri.requisition_id = r.requisition_id) as item_count
FROM inventory_requisitions r
LEFT JOIN users u ON r.requested_by = u.user_id
LEFT JOIN inventory_locations l ON r.location_id = l.location_id
WHERE r.requisition_id = ?";

$requisition_stmt = $mysqli->prepare($requisition_sql);
$requisition_stmt->bind_param("i", $requisition_id);
$requisition_stmt->execute();
$requisition_result = $requisition_stmt->get_result();

if ($requisition_result->num_rows == 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Requisition not found.";
    header("Location: inventory_requisitions.php");
    exit;
}

$requisition = $requisition_result->fetch_assoc();
$requisition_stmt->close();

// Check if requisition can be edited (only pending requisitions)
if ($requisition['status'] != 'pending') {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Only pending requisitions can be edited.";
    header("Location: inventory_requisition_details.php?requisition_id=" . $requisition_id);
    exit;
}

// Get requisition items
$items_sql = "SELECT 
    ri.*,
    i.item_name,
    i.item_code,
    i.item_quantity as current_stock,
    i.item_unit_measure,
    c.category_name
FROM inventory_requisition_items ri
JOIN inventory_items i ON ri.item_id = i.item_id
LEFT JOIN inventory_categories c ON i.item_category_id = c.category_id
WHERE ri.requisition_id = ?
ORDER BY i.item_name";

$items_stmt = $mysqli->prepare($items_sql);
$items_stmt->bind_param("i", $requisition_id);
$items_stmt->execute();
$items_result = $items_stmt->get_result();

// Get available items for adding new items
$available_items_sql = "SELECT i.item_id, i.item_name, i.item_code, i.item_quantity, 
                               i.item_unit_measure, c.category_name
                        FROM inventory_items i
                        LEFT JOIN inventory_categories c ON i.item_category_id = c.category_id
                        WHERE i.item_archived_at IS NULL AND i.item_status != 'Out of Stock'
                        ORDER BY i.item_name";
$available_items_result = $mysqli->query($available_items_sql);

// Get locations
$locations_sql = "SELECT location_id, location_name, location_type 
                  FROM inventory_locations 
                  
                  ORDER BY location_name";
$locations_result = $mysqli->query($locations_sql);

// Get departments
$departments_sql = "SELECT department_id, department_name 
                    FROM departments 
                    WHERE department_archived_at IS NULL 
                    ORDER BY department_name";
$departments_result = $mysqli->query($departments_sql);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token.";
        header("Location: inventory_requisition_edit.php?requisition_id=" . $requisition_id);
        exit;
    }

    // Get form data
    $requisition_date = sanitizeInput($_POST['requisition_date']);
    $department_id = intval($_POST['department_id'] ?? 0);
    $location_id = intval($_POST['location_id'] ?? 0);
    $priority = sanitizeInput($_POST['priority']);
    $notes = sanitizeInput($_POST['notes']);
    $items = $_POST['items'] ?? [];
    $remove_items = $_POST['remove_items'] ?? [];

    // Validate required fields
    if (empty($requisition_date) || empty($location_id)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please fill in all required fields.";
        header("Location: inventory_requisition_edit.php?requisition_id=" . $requisition_id);
        exit;
    }

    // Validate items
    $valid_items = [];
    foreach ($items as $item_key => $item) {
        // Skip items marked for removal
        if (in_array($item_key, $remove_items)) {
            continue;
        }
        
        $item_id = intval($item['item_id'] ?? 0);
        $quantity = intval($item['quantity'] ?? 0);
        $item_notes = sanitizeInput($item['notes'] ?? '');

        if ($item_id > 0 && $quantity > 0) {
            $valid_items[] = [
                'item_key' => $item_key,
                'item_id' => $item_id,
                'quantity' => $quantity,
                'notes' => $item_notes
            ];
        }
    }

    if (count($valid_items) === 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please keep at least one valid item with quantity greater than zero.";
        header("Location: inventory_requisition_edit.php?requisition_id=" . $requisition_id);
        exit;
    }

    // Start transaction
    $mysqli->begin_transaction();

    try {
        // Update requisition
        $requisition_sql = "UPDATE inventory_requisitions SET
            requisition_date = ?,
            department_id = ?,
            location_id = ?,
            priority = ?,
            notes = ?
            WHERE requisition_id = ?";
        
        $requisition_stmt = $mysqli->prepare($requisition_sql);
        $requisition_stmt->bind_param(
            "siissi",
            $requisition_date,
            $department_id,
            $location_id,
            $priority,
            $notes,
            $requisition_id
        );
        $requisition_stmt->execute();
        $requisition_stmt->close();

        // Remove items marked for deletion
        if (!empty($remove_items)) {
            foreach ($remove_items as $item_key) {
                // Check if this is an existing item (has requisition_item_id)
                if (strpos($item_key, 'existing_') === 0) {
                    $requisition_item_id = intval(str_replace('existing_', '', $item_key));
                    if ($requisition_item_id > 0) {
                        $delete_sql = "DELETE FROM inventory_requisition_items WHERE requisition_item_id = ?";
                        $delete_stmt = $mysqli->prepare($delete_sql);
                        $delete_stmt->bind_param("i", $requisition_item_id);
                        $delete_stmt->execute();
                        $delete_stmt->close();
                    }
                }
            }
        }

        // Update or insert items
        foreach ($valid_items as $item) {
            if (strpos($item['item_key'], 'existing_') === 0) {
                // Update existing item
                $requisition_item_id = intval(str_replace('existing_', '', $item['item_key']));
                $update_sql = "UPDATE inventory_requisition_items SET
                    quantity_requested = ?,
                    notes = ?
                    WHERE requisition_item_id = ?";
                
                $update_stmt = $mysqli->prepare($update_sql);
                $update_stmt->bind_param("isi", $item['quantity'], $item['notes'], $requisition_item_id);
                $update_stmt->execute();
                $update_stmt->close();
            } else {
                // Insert new item
                $insert_sql = "INSERT INTO inventory_requisition_items (
                    requisition_id, item_id, quantity_requested, notes
                ) VALUES (?, ?, ?, ?)";
                
                $insert_stmt = $mysqli->prepare($insert_sql);
                $insert_stmt->bind_param(
                    "iiis",
                    $requisition_id,
                    $item['item_id'],
                    $item['quantity'],
                    $item['notes']
                );
                $insert_stmt->execute();
                $insert_stmt->close();
            }
        }

        $mysqli->commit();

        // Log the action
        $log_sql = "INSERT INTO logs SET
            log_type = 'Requisition',
            log_action = 'Update',
            log_description = ?,
            log_ip = ?,
            log_user_agent = ?,
            log_user_id = ?,
            log_entity_id = ?,
            log_created_at = NOW()";
        $log_stmt = $mysqli->prepare($log_sql);
        $log_description = "Updated requisition #" . $requisition['requisition_number'];
        $log_stmt->bind_param("sssii", $log_description, $session_ip, $session_user_agent, $session_user_id, $requisition_id);
        $log_stmt->execute();
        $log_stmt->close();

        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Requisition updated successfully!";
        header("Location: inventory_requisition_details.php?requisition_id=" . $requisition_id);
        exit;

    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error updating requisition: " . $e->getMessage();
        header("Location: inventory_requisition_edit.php?requisition_id=" . $requisition_id);
        exit;
    }
}
?>

<div class="card">
    <div class="card-header bg-warning py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-edit mr-2"></i>Edit Requisition: <?php echo htmlspecialchars($requisition['requisition_number']); ?>
            </h3>
            <div class="card-tools">
                <a href="inventory_requisition_details.php?requisition_id=<?php echo $requisition_id; ?>" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Requisition
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

        <form method="POST" id="requisitionForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <div class="row">
                <div class="col-md-8">
                    <!-- Requisition Details -->
                    <div class="card card-primary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-info-circle mr-2"></i>Requisition Details</h3>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="requisition_number">Requisition Number</label>
                                        <input type="text" class="form-control" id="requisition_number" 
                                               value="<?php echo htmlspecialchars($requisition['requisition_number']); ?>" readonly>
                                        <small class="form-text text-muted">Requisition number cannot be changed</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="requisition_date">Requisition Date *</label>
                                        <input type="date" class="form-control" id="requisition_date" 
                                               name="requisition_date" value="<?php echo $requisition['requisition_date']; ?>" required>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="location_id">Delivery Location *</label>
                                        <select class="form-control" id="location_id" name="location_id" required>
                                            <option value="">- Select Location -</option>
                                            <?php while ($location = $locations_result->fetch_assoc()): ?>
                                                <option value="<?php echo $location['location_id']; ?>" 
                                                    <?php echo $location['location_id'] == $requisition['location_id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($location['location_name']); ?> 
                                                    (<?php echo htmlspecialchars($location['location_type']); ?>)
                                                </option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="department_id">Department</label>
                                        <select class="form-control" id="department_id" name="department_id">
                                            <option value="">- Select Department -</option>
                                            <?php while ($department = $departments_result->fetch_assoc()): ?>
                                                <option value="<?php echo $department['department_id']; ?>" 
                                                    <?php echo $department['department_id'] == $requisition['department_id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($department['department_name']); ?>
                                                </option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="priority">Priority *</label>
                                        <select class="form-control" id="priority" name="priority" required>
                                            <option value="low" <?php echo $requisition['priority'] == 'low' ? 'selected' : ''; ?>>Low</option>
                                            <option value="normal" <?php echo $requisition['priority'] == 'normal' ? 'selected' : ''; ?>>Normal</option>
                                            <option value="high" <?php echo $requisition['priority'] == 'high' ? 'selected' : ''; ?>>High</option>
                                            <option value="urgent" <?php echo $requisition['priority'] == 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Requested By</label>
                                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($requisition['requester_name']); ?>" readonly>
                                        <small class="form-text text-muted">Requester cannot be changed</small>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="notes">Requisition Notes</label>
                                <textarea class="form-control" id="notes" name="notes" rows="3" 
                                          placeholder="Additional information about this requisition..."><?php echo htmlspecialchars($requisition['notes']); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Items Section -->
                    <div class="card card-success">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h3 class="card-title"><i class="fas fa-cubes mr-2"></i>Requested Items</h3>
                            <button type="button" class="btn btn-sm btn-primary" onclick="addNewItemRow()">
                                <i class="fas fa-plus mr-1"></i>Add New Item
                            </button>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-sm" id="itemsTable">
                                    <thead class="bg-light">
                                        <tr>
                                            <th width="35%">Item</th>
                                            <th width="15%">Current Stock</th>
                                            <th width="15%">Quantity</th>
                                            <th width="25%">Notes</th>
                                            <th width="10%"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="itemsTableBody">
                                        <?php 
                                        $item_counter = 0;
                                        while ($item = $items_result->fetch_assoc()): 
                                            $item_key = 'existing_' . $item['requisition_item_id'];
                                        ?>
                                            <tr id="row_<?php echo $item_key; ?>">
                                                <td>
                                                    <select class="form-control form-control-sm item-select" name="items[<?php echo $item_key; ?>][item_id]" required onchange="updateItemInfo(this)">
                                                        <option value="">- Select Item -</option>
                                                        <?php 
                                                        $available_items_result->data_seek(0);
                                                        while ($available_item = $available_items_result->fetch_assoc()): 
                                                        ?>
                                                            <option value="<?php echo $available_item['item_id']; ?>" 
                                                                data-stock="<?php echo $available_item['item_quantity']; ?>" 
                                                                data-unit="<?php echo htmlspecialchars($available_item['item_unit_measure']); ?>"
                                                                <?php echo $available_item['item_id'] == $item['item_id'] ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($available_item['item_name']); ?> (<?php echo htmlspecialchars($available_item['item_code']); ?>)
                                                            </option>
                                                        <?php endwhile; ?>
                                                    </select>
                                                </td>
                                                <td>
                                                    <span class="current-stock"><?php echo $item['current_stock']; ?></span>
                                                    <small class="text-muted unit-measure"><?php echo htmlspecialchars($item['item_unit_measure']); ?></small>
                                                </td>
                                                <td>
                                                    <input type="number" class="form-control form-control-sm" name="items[<?php echo $item_key; ?>][quantity]" 
                                                           min="1" value="<?php echo $item['quantity_requested']; ?>" required onchange="validateQuantity(this)">
                                                </td>
                                                <td>
                                                    <input type="text" class="form-control form-control-sm" name="items[<?php echo $item_key; ?>][notes]" 
                                                           value="<?php echo htmlspecialchars($item['notes']); ?>" placeholder="Item notes...">
                                                </td>
                                                <td class="text-center">
                                                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeItemRow('<?php echo $item_key; ?>')">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php 
                                            $item_counter++;
                                        endwhile; 
                                        ?>
                                    </tbody>
                                    <tfoot>
                                        <tr id="noItemsMessage" style="<?php echo $item_counter > 0 ? 'display: none;' : ''; ?>">
                                            <td colspan="5" class="text-center text-muted py-3">
                                                <i class="fas fa-info-circle mr-2"></i>
                                                No items added yet. Click "Add New Item" to start.
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <!-- Quick Actions -->
                    <div class="card card-info">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-bolt mr-2"></i>Quick Actions</h3>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-success btn-lg">
                                    <i class="fas fa-save mr-2"></i>Update Requisition
                                </button>
                                <button type="reset" class="btn btn-outline-secondary" onclick="resetForm()">
                                    <i class="fas fa-undo mr-2"></i>Reset Changes
                                </button>
                                <a href="inventory_requisition_details.php?requisition_id=<?php echo $requisition_id; ?>" class="btn btn-outline-danger">
                                    <i class="fas fa-times mr-2"></i>Cancel
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Available Items -->
                    <div class="card card-warning">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-cube mr-2"></i>Available Items</h3>
                        </div>
                        <div class="card-body p-0">
                            <div class="list-group list-group-flush" style="max-height: 300px; overflow-y: auto;">
                                <?php 
                                $available_items_result->data_seek(0);
                                while ($item = $available_items_result->fetch_assoc()): 
                                ?>
                                    <div class="list-group-item list-group-item-action" 
                                         onclick="addSpecificItem(<?php echo $item['item_id']; ?>, '<?php echo addslashes($item['item_name']); ?>', <?php echo $item['item_quantity']; ?>, '<?php echo addslashes($item['item_unit_measure']); ?>')"
                                         style="cursor: pointer;">
                                        <div class="d-flex w-100 justify-content-between">
                                            <h6 class="mb-1"><?php echo htmlspecialchars($item['item_name']); ?></h6>
                                            <small class="text-muted"><?php echo $item['item_quantity']; ?> in stock</small>
                                        </div>
                                        <p class="mb-1 small text-muted">
                                            <?php echo htmlspecialchars($item['item_code']); ?>
                                            <?php if ($item['category_name']): ?>
                                                • <?php echo htmlspecialchars($item['category_name']); ?>
                                            <?php endif; ?>
                                        </p>
                                        <small class="text-muted">Unit: <?php echo htmlspecialchars($item['item_unit_measure']); ?></small>
                                    </div>
                                <?php endwhile; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Requisition Status -->
                    <div class="card card-secondary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-info-circle mr-2"></i>Requisition Info</h3>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-3">
                                <i class="fas fa-clipboard-list fa-2x text-secondary mb-2"></i>
                                <h6><?php echo htmlspecialchars($requisition['requisition_number']); ?></h6>
                                <div class="text-muted"><?php echo $requisition['item_count']; ?> items</div>
                            </div>
                            <hr>
                            <div class="small">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Status:</span>
                                    <span class="badge badge-warning">Pending</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Requester:</span>
                                    <span><?php echo htmlspecialchars($requisition['requester_name']); ?></span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Created:</span>
                                    <span><?php echo date('M j, Y', strtotime($requisition['requisition_date'])); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Editing Guidelines -->
                    <div class="card card-primary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-lightbulb mr-2"></i>Editing Guidelines</h3>
                        </div>
                        <div class="card-body">
                            <div class="small">
                                <div class="mb-2">
                                    <i class="fas fa-check text-success mr-2"></i>
                                    You can modify item quantities and notes
                                </div>
                                <div class="mb-2">
                                    <i class="fas fa-check text-success mr-2"></i>
                                    You can add new items to the requisition
                                </div>
                                <div class="mb-2">
                                    <i class="fas fa-check text-success mr-2"></i>
                                    You can remove items from the requisition
                                </div>
                                <div class="mb-2">
                                    <i class="fas fa-info text-info mr-2"></i>
                                    Changes will reset approval status
                                </div>
                                <div class="mb-2">
                                    <i class="fas fa-exclamation text-warning mr-2"></i>
                                    Once approved, requisition cannot be edited
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
// Store available items for quick access
const availableItems = [
    <?php 
    $available_items_result->data_seek(0);
    $first = true;
    while ($item = $available_items_result->fetch_assoc()): 
        if (!$first) echo ',';
        $first = false;
    ?>
    {
        id: <?php echo $item['item_id']; ?>,
        name: '<?php echo addslashes($item['item_name']); ?>',
        code: '<?php echo addslashes($item['item_code']); ?>',
        stock: <?php echo $item['item_quantity']; ?>,
        unit: '<?php echo addslashes($item['item_unit_measure']); ?>',
        category: '<?php echo addslashes($item['category_name'] ?? ''); ?>'
    }
    <?php endwhile; ?>
];

let newItemCounter = 0;
const removedItems = new Set();

function addNewItemRow(itemId = '', itemName = '', currentStock = 0, unitMeasure = '') {
    const tbody = document.getElementById('itemsTableBody');
    const noItemsMessage = document.getElementById('noItemsMessage');
    
    // Hide no items message
    noItemsMessage.style.display = 'none';
    
    const rowId = 'new_' + newItemCounter++;
    
    const row = document.createElement('tr');
    row.id = 'row_' + rowId;
    row.innerHTML = `
        <td>
            <select class="form-control form-control-sm item-select" name="items[${rowId}][item_id]" required onchange="updateItemInfo(this)">
                <option value="">- Select Item -</option>
                ${availableItems.map(item => `
                    <option value="${item.id}" data-stock="${item.stock}" data-unit="${item.unit}" ${itemId == item.id ? 'selected' : ''}>
                        ${item.name} (${item.code})
                    </option>
                `).join('')}
            </select>
        </td>
        <td>
            <span class="current-stock">${currentStock}</span>
            <small class="text-muted unit-measure">${unitMeasure}</small>
        </td>
        <td>
            <input type="number" class="form-control form-control-sm" name="items[${rowId}][quantity]" 
                   min="1" value="1" required onchange="validateQuantity(this)">
        </td>
        <td>
            <input type="text" class="form-control form-control-sm" name="items[${rowId}][notes]" 
                   placeholder="Item notes...">
        </td>
        <td class="text-center">
            <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeItemRow('${rowId}')">
                <i class="fas fa-times"></i>
            </button>
        </td>
    `;
    
    tbody.appendChild(row);
    
    // If specific item was provided, select it
    if (itemId) {
        const select = row.querySelector('.item-select');
        select.value = itemId;
        updateItemInfo(select);
    }
}

function addSpecificItem(itemId, itemName, currentStock, unitMeasure) {
    addNewItemRow(itemId, itemName, currentStock, unitMeasure);
}

function updateItemInfo(selectElement) {
    const row = selectElement.closest('tr');
    const selectedOption = selectElement.options[selectElement.selectedIndex];
    const currentStock = selectedOption.getAttribute('data-stock') || 0;
    const unitMeasure = selectedOption.getAttribute('data-unit') || '';
    
    row.querySelector('.current-stock').textContent = currentStock;
    row.querySelector('.unit-measure').textContent = unitMeasure;
    
    // Update quantity input max value
    const quantityInput = row.querySelector('input[type="number"]');
    quantityInput.setAttribute('data-max', currentStock);
}

function validateQuantity(inputElement) {
    const row = inputElement.closest('tr');
    const currentStock = parseInt(row.querySelector('.current-stock').textContent) || 0;
    const quantity = parseInt(inputElement.value) || 0;
    
    if (quantity > currentStock) {
        inputElement.classList.add('is-invalid');
        // You can add a tooltip or message here if needed
    } else {
        inputElement.classList.remove('is-invalid');
    }
}

function removeItemRow(rowId) {
    const row = document.getElementById('row_' + rowId);
    if (row) {
        // If it's an existing item, mark it for removal in the form
        if (rowId.startsWith('existing_')) {
            if (!removedItems.has(rowId)) {
                removedItems.add(rowId);
                // Create hidden input to track removed items
                const hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = 'remove_items[]';
                hiddenInput.value = rowId;
                document.getElementById('requisitionForm').appendChild(hiddenInput);
            }
        }
        row.remove();
    }
    
    // Show no items message if no rows left
    const tbody = document.getElementById('itemsTableBody');
    if (tbody.children.length === 0) {
        document.getElementById('noItemsMessage').style.display = '';
    }
}

function resetForm() {
    if (confirm('Are you sure you want to reset all changes? All unsaved changes will be lost.')) {
        document.getElementById('requisitionForm').reset();
        window.location.reload(); // Reload to get original state
    }
}

// Form validation
document.getElementById('requisitionForm').addEventListener('submit', function(e) {
    const itemRows = document.querySelectorAll('#itemsTableBody tr');
    
    if (itemRows.length === 0) {
        e.preventDefault();
        alert('Please keep at least one item in the requisition.');
        return false;
    }
    
    // Validate each item
    let hasErrors = false;
    itemRows.forEach(row => {
        const itemSelect = row.querySelector('.item-select');
        const quantityInput = row.querySelector('input[type="number"]');
        
        if (!itemSelect.value || !quantityInput.value || quantityInput.value <= 0) {
            hasErrors = true;
            quantityInput.classList.add('is-invalid');
        }
    });
    
    if (hasErrors) {
        e.preventDefault();
        alert('Please ensure all items have valid quantities.');
        return false;
    }
    
    // Show loading state
    const submitBtn = this.querySelector('button[type="submit"]');
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Updating...';
    submitBtn.disabled = true;
});

// Initialize item info for existing items on page load
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.item-select').forEach(select => {
        updateItemInfo(select);
    });
});

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // Ctrl + S to save
    if (e.ctrlKey && e.key === 's') {
        e.preventDefault();
        document.getElementById('requisitionForm').submit();
    }
    // Escape to cancel
    if (e.key === 'Escape') {
        window.location.href = 'inventory_requisition_details.php?requisition_id=<?php echo $requisition_id; ?>';
    }
});
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
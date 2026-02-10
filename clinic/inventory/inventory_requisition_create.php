<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Initialize variables
$items = [];
$locations = [];
$departments = [];
$pre_selected_item_id = intval($_GET['item_id'] ?? 0);

// Generate requisition number
$requisition_number = "REQ-" . date('Ymd') . "-" . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);

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
                i.reorder_level,
                i.status,
                i.requires_batch,
                c.category_name
              FROM inventory_items i
              LEFT JOIN inventory_categories c ON i.category_id = c.category_id
              WHERE i.is_active = 1 AND i.status = 'active'
              ORDER BY i.item_name";
$items_result = $mysqli->query($items_sql);
while ($item = $items_result->fetch_assoc()) {
    $items[] = $item;
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
    $requisition_date = sanitizeInput($_POST['requisition_date']);
    $department_id = intval($_POST['department_id'] ?? 0);
    $from_location_id = intval($_POST['from_location_id'] ?? 0);
    $delivery_location_id = intval($_POST['delivery_location_id'] ?? 0);
    $priority = sanitizeInput($_POST['priority']);
    $notes = sanitizeInput($_POST['notes']);
    
    // Get requisition items from POST data
    $requisition_items = [];
    if (isset($_POST['item_id']) && is_array($_POST['item_id'])) {
        foreach ($_POST['item_id'] as $index => $item_id) {
            if (!empty($item_id) && !empty($_POST['quantity_requested'][$index])) {
                $requisition_items[] = [
                    'item_id' => intval($item_id),
                    'item_name' => sanitizeInput($_POST['item_name'][$index]),
                    'quantity_requested' => floatval($_POST['quantity_requested'][$index]),
                    'notes' => sanitizeInput($_POST['item_notes'][$index] ?? '')
                ];
            }
        }
    }

    // Validate CSRF token
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        header("Location: inventory_requisition_create.php");
        exit;
    }

    // Validate required fields
    if (empty($requisition_date) || empty($from_location_id) || empty($delivery_location_id) || count($requisition_items) === 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please fill in all required fields and add at least one item.";
        header("Location: inventory_requisition_create.php");
        exit;
    }

    // Validate requisition items
    foreach ($requisition_items as $item) {
        if ($item['quantity_requested'] <= 0) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Please check all item quantities are valid.";
            header("Location: inventory_requisition_create.php");
            exit;
        }
    }

    // Start transaction
    $mysqli->begin_transaction();

    try {
        // Insert requisition
        $requisition_sql = "INSERT INTO inventory_requisitions (
            requisition_number, requisition_date, requested_by, department_id, 
            from_location_id, delivery_location_id, priority, notes, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')";
        
        $requisition_stmt = $mysqli->prepare($requisition_sql);
        $requisition_stmt->bind_param(
            "ssiiisss",
            $requisition_number,
            $requisition_date,
            $session_user_id,
            $department_id,
            $from_location_id,
            $delivery_location_id,
            $priority,
            $notes
        );

        $requisition_stmt->execute();
        $requisition_id = $requisition_stmt->insert_id;
        $requisition_stmt->close();

        // Insert requisition items
        $item_sql = "INSERT INTO inventory_requisition_items (
            requisition_id, item_id, quantity_requested, notes
        ) VALUES (?, ?, ?, ?)";
        
        $item_stmt = $mysqli->prepare($item_sql);
        
        foreach ($requisition_items as $item) {
            $item_stmt->bind_param(
                "iids",
                $requisition_id,
                $item['item_id'],
                $item['quantity_requested'],
                $item['notes']
            );
            $item_stmt->execute();
        }
        $item_stmt->close();

        $mysqli->commit();

        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Requisition <strong>$requisition_number</strong> created successfully!";
        header("Location: inventory_requisition_view.php?id=" . $requisition_id);
        exit;

    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error creating requisition: " . $e->getMessage();
        header("Location: inventory_requisition_create.php");
        exit;
    }
}
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-clipboard-list mr-2"></i>Create Requisition
            </h3>
            <div class="card-tools">
                <a href="inventory_requisitions.php" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Requisitions
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
                    <!-- Requisition Information -->
                    <div class="card card-primary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-info-circle mr-2"></i>Requisition Information</h3>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="requisition_number">Requisition Number</label>
                                        <input type="text" class="form-control" id="requisition_number" 
                                               value="<?php echo htmlspecialchars($requisition_number); ?>" readonly>
                                        <small class="form-text text-muted">Auto-generated requisition number</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="requisition_date">Requisition Date *</label>
                                        <input type="date" class="form-control" id="requisition_date" name="requisition_date" 
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
                                        <label for="delivery_location_id">Delivery Location *</label>
                                        <select class="form-control select2" id="delivery_location_id" name="delivery_location_id" required>
                                            <option value="">- Select Delivery Location -</option>
                                            <?php foreach ($locations as $location): ?>
                                                <option value="<?php echo $location['location_id']; ?>">
                                                    <?php echo htmlspecialchars($location['location_name']); ?>
                                                    (<?php echo $location['location_type']; ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="department_id">Department</label>
                                        <select class="form-control select2" id="department_id" name="department_id">
                                            <option value="">- Select Department -</option>
                                            <?php foreach ($departments as $department): ?>
                                                <option value="<?php echo $department['department_id']; ?>">
                                                    <?php echo htmlspecialchars($department['department_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="priority">Priority *</label>
                                        <select class="form-control" id="priority" name="priority" required>
                                            <option value="low">Low</option>
                                            <option value="normal" selected>Normal</option>
                                            <option value="high">High</option>
                                            <option value="urgent">Urgent</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="notes">Requisition Notes</label>
                                <textarea class="form-control" id="notes" name="notes" rows="3" 
                                          placeholder="Purpose of requisition, special requirements, etc..." 
                                          maxlength="500"></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Requisition Items -->
                    <div class="card card-warning">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h3 class="card-title"><i class="fas fa-list-ol mr-2"></i>Requisition Items</h3>
                            <button type="button" class="btn btn-sm btn-success" onclick="addRequisitionItem()">
                                <i class="fas fa-plus mr-1"></i>Add Item
                            </button>
                        </div>
                        <div class="card-body">
                            <div id="requisition_items_container">
                                <!-- Requisition items will be added here dynamically -->
                                <div class="text-center text-muted py-4" id="no_items_message">
                                    <i class="fas fa-cubes fa-2x mb-2"></i>
                                    <p>No items added yet. Click "Add Item" to start.</p>
                                </div>
                            </div>
                            
                            <!-- Requisition Summary -->
                            <div class="row mt-4">
                                <div class="col-md-6 offset-md-6">
                                    <table class="table table-sm table-borderless">
                                        <tr class="border-top">
                                            <td class="text-right"><strong>Total Items:</strong></td>
                                            <td class="text-right" width="150"><strong><span id="item_count">0</span> items</strong></td>
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
                                <button type="submit" class="btn btn-success btn-lg">
                                    <i class="fas fa-save mr-2"></i>Create Requisition
                                </button>
                                <button type="button" class="btn btn-outline-info" onclick="submitForApproval()">
                                    <i class="fas fa-paper-plane mr-2"></i>Submit for Approval
                                </button>
                                <button type="reset" class="btn btn-outline-secondary" onclick="resetForm()">
                                    <i class="fas fa-undo mr-2"></i>Reset Form
                                </button>
                                <a href="inventory_requisitions.php" class="btn btn-outline-danger">
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

                    <!-- Delivery Location Information -->
                    <div class="card card-primary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-sign-in-alt mr-2"></i>Delivery Location</h3>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-3">
                                <i class="fas fa-map-marker-alt fa-3x text-primary mb-2"></i>
                                <h5 id="delivery_location_name">Select Location</h5>
                                <div class="text-muted" id="delivery_location_type">-</div>
                            </div>
                        </div>
                    </div>

                    <!-- Priority Information -->
                    <div class="card card-danger">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-exclamation-triangle mr-2"></i>Priority</h3>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-3">
                                <i class="fas fa-flag fa-3x text-danger mb-2"></i>
                                <h5 id="priority_display">Normal</h5>
                            </div>
                            <hr>
                            <div class="small">
                                <div class="mb-2">
                                    <span class="badge badge-danger mr-1">Urgent</span>
                                    <span class="text-muted">Critical needs - immediate action</span>
                                </div>
                                <div class="mb-2">
                                    <span class="badge badge-warning mr-1">High</span>
                                    <span class="text-muted">Important needs - within 24 hours</span>
                                </div>
                                <div class="mb-2">
                                    <span class="badge badge-primary mr-1">Normal</span>
                                    <span class="text-muted">Standard needs - within 3 days</span>
                                </div>
                                <div>
                                    <span class="badge badge-secondary mr-1">Low</span>
                                    <span class="text-muted">Non-urgent needs - as available</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Requisition Preview -->
                    <div class="card card-warning">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-eye mr-2"></i>Requisition Preview</h3>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-3">
                                <i class="fas fa-clipboard-list fa-3x text-warning mb-2"></i>
                                <h5 id="preview_requisition_number"><?php echo htmlspecialchars($requisition_number); ?></h5>
                                <div class="text-muted" id="preview_item_count">0 items</div>
                            </div>
                            <hr>
                            <div class="small">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>From:</span>
                                    <span class="font-weight-bold" id="preview_from_location">-</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>To:</span>
                                    <span class="font-weight-bold" id="preview_delivery_location">-</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Requisition Date:</span>
                                    <span class="font-weight-bold" id="preview_requisition_date">-</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Priority:</span>
                                    <span class="font-weight-bold" id="preview_priority">Normal</span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Requester:</span>
                                    <span class="font-weight-bold text-success"><?php echo htmlspecialchars($session_name); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Item Selection Modal -->
<div class="modal fade" id="itemSelectionModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-cubes mr-2"></i>Select Inventory Item</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table class="table table-sm table-hover" id="itemsTable">
                        <thead class="bg-light">
                            <tr>
                                <th>Item Name</th>
                                <th>Code</th>
                                <th>Category</th>
                                <th class="text-center">Unit</th>
                                <th class="text-center">Batch Required</th>
                                <th class="text-center">Reorder Level</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($item['item_name']); ?></strong>
                                    </td>
                                    <td><?php echo htmlspecialchars($item['item_code']); ?></td>
                                    <td><?php echo htmlspecialchars($item['category_name']); ?></td>
                                    <td class="text-center"><?php echo htmlspecialchars($item['unit_of_measure']); ?></td>
                                    <td class="text-center">
                                        <?php if ($item['requires_batch']): ?>
                                            <span class="badge badge-info">Yes</span>
                                        <?php else: ?>
                                            <span class="badge badge-secondary">No</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center"><?php echo $item['reorder_level']; ?></td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-sm btn-success select-item-btn" 
                                                data-item-id="<?php echo $item['item_id']; ?>"
                                                data-item-name="<?php echo htmlspecialchars($item['item_name']); ?>"
                                                data-unit-measure="<?php echo htmlspecialchars($item['unit_of_measure']); ?>"
                                                data-category="<?php echo htmlspecialchars($item['category_name']); ?>"
                                                data-requires-batch="<?php echo $item['requires_batch']; ?>">
                                            <i class="fas fa-plus mr-1"></i>Add
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Global variables
let itemCounter = 0;
const items = <?php echo json_encode($items); ?>;
const locations = <?php echo json_encode($locations); ?>;

// Make functions globally accessible
window.selectItem = function(itemId, itemName, unitMeasure, category, requiresBatch) {
    try {
        itemCounter++;
        
        const requiresBatchBadge = requiresBatch == 1 ? '<span class="badge badge-info ml-1">Requires Batch</span>' : '';
        
        const itemRow = `
            <div class="requisition-item-row border rounded p-3 mb-3" id="item_row_${itemCounter}">
                <div class="row">
                    <div class="col-md-5">
                        <div class="form-group">
                            <label>Item *</label>
                            <input type="hidden" name="item_id[]" value="${escapeHtml(itemId)}">
                            <input type="text" class="form-control" name="item_name[]" value="${escapeHtml(itemName)}" readonly>
                            <small class="form-text text-muted">
                                ${escapeHtml(category)} - ${escapeHtml(unitMeasure)}
                                ${requiresBatchBadge}
                            </small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Quantity *</label>
                            <input type="number" class="form-control quantity" name="quantity_requested[]" 
                                   min="0.001" step="0.001" value="1" required>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Notes</label>
                            <input type="text" class="form-control" name="item_notes[]" 
                                   placeholder="Item notes..." maxlength="255">
                        </div>
                    </div>
                    <div class="col-md-1">
                        <div class="form-group">
                            <label>&nbsp;</label>
                            <button type="button" class="btn btn-danger btn-block" onclick="removeRequisitionItem(${itemCounter})">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        $('#no_items_message').hide();
        $('#requisition_items_container').append(itemRow);
        $('#itemSelectionModal').modal('hide');
        
        updateItemCount();
        updatePreview();
    } catch (error) {
        console.error('Error adding item:', error);
        alert('Error adding item. Please try again.');
    }
}

window.removeRequisitionItem = function(itemId) {
    $('#item_row_' + itemId).remove();
    
    // Show no items message if no items left
    if ($('.requisition-item-row').length === 0) {
        $('#no_items_message').show();
    }
    
    updateItemCount();
    updatePreview();
}

// Helper function to escape HTML
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

$(document).ready(function() {
    // Initialize Select2
    $('.select2').select2();

    // Initialize DataTable for items modal
    $('#itemsTable').DataTable({
        pageLength: 10,
        order: [[0, 'asc']],
        language: {
            search: "Search items:"
        },
        drawCallback: function() {
            // Re-attach event listeners after DataTable redraws
            attachModalEventListeners();
        }
    });

    // Attach event listeners to modal buttons
    function attachModalEventListeners() {
        $('.select-item-btn').off('click').on('click', function() {
            const itemId = $(this).data('item-id');
            const itemName = $(this).data('item-name');
            const unitMeasure = $(this).data('unit-measure');
            const category = $(this).data('category');
            const requiresBatch = $(this).data('requires-batch');
            
            selectItem(itemId, itemName, unitMeasure, category, requiresBatch);
        });
    }

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

    // Update delivery location details when selected
    $('#delivery_location_id').on('change', function() {
        const locationId = $(this).val();
        const selectedOption = $(this).find('option:selected');
        
        if (locationId) {
            const locationText = selectedOption.text().split(' (');
            $('#delivery_location_name').text(locationText[0]);
            $('#delivery_location_type').text(locationText[1] ? locationText[1].replace(')', '') : '-');
            $('#preview_delivery_location').text(locationText[0]);
        } else {
            $('#delivery_location_name').text('Select Location');
            $('#delivery_location_type').text('-');
            $('#preview_delivery_location').text('-');
        }
    });

    // Update requisition date preview
    $('#requisition_date').on('change', function() {
        $('#preview_requisition_date').text($(this).val());
    });

    // Update priority display
    $('#priority').on('change', function() {
        const priority = $(this).val();
        $('#priority_display').text(priority.charAt(0).toUpperCase() + priority.slice(1));
        $('#preview_priority').text(priority.charAt(0).toUpperCase() + priority.slice(1));
    });

    // Auto-set requisition date to today
    $('#requisition_date').trigger('change');
    $('#priority').trigger('change');

    // If pre-selected item exists, add it automatically
    <?php if ($pre_selected_item_id > 0): ?>
        const preSelectedItem = items.find(item => item.item_id == <?php echo $pre_selected_item_id; ?>);
        if (preSelectedItem) {
            selectItem(
                preSelectedItem.item_id,
                preSelectedItem.item_name,
                preSelectedItem.unit_of_measure,
                preSelectedItem.category_name,
                preSelectedItem.requires_batch
            );
        }
    <?php endif; ?>
});

// Add new requisition item row
function addRequisitionItem() {
    $('#itemSelectionModal').modal('show');
}

// Update item count
function updateItemCount() {
    const itemCount = $('.requisition-item-row').length;
    $('#item_count').text(itemCount);
    $('#preview_item_count').text(itemCount + ' item' + (itemCount !== 1 ? 's' : ''));
}

// Update preview
function updatePreview() {
    updateItemCount();
}

// Quick add functions
function addLowStockItems() {
    alert('Low stock functionality would query actual stock levels in the inventory');
}

function addCommonItems() {
    // Add first 5 items as "common items"
    const commonItems = <?php echo json_encode(array_slice($items, 0, 5)); ?>;
    
    commonItems.forEach(item => {
        if (!$(`input[name="item_id[]"][value="${item.item_id}"]`).length) {
            selectItem(
                item.item_id,
                item.item_name,
                item.unit_of_measure,
                item.category_name,
                item.requires_batch
            );
        }
    });
}

// Form actions
function submitForApproval() {
    // Create requisition with 'pending' status
    $('#requisitionForm').submit();
}

function resetForm() {
    if (confirm('Are you sure you want to reset the entire form? All items will be removed.')) {
        $('#requisitionForm').trigger('reset');
        $('#requisition_items_container').empty();
        $('#no_items_message').show();
        updateItemCount();
        updatePreview();
        $('.select2').trigger('change');
        $('#priority').trigger('change');
    }
}

// Form validation
$('#requisitionForm').on('submit', function(e) {
    const requisitionDate = $('#requisition_date').val();
    const fromLocationId = $('#from_location_id').val();
    const deliveryLocationId = $('#delivery_location_id').val();
    const itemCount = $('.requisition-item-row').length;
    
    let isValid = true;
    
    // Validate required fields
    if (!requisitionDate || !fromLocationId || !deliveryLocationId || itemCount === 0) {
        isValid = false;
    }
    
    // Validate all items have valid quantities
    $('.quantity').each(function() {
        if ($(this).val() <= 0) {
            isValid = false;
            $(this).addClass('is-invalid');
        } else {
            $(this).removeClass('is-invalid');
        }
    });
    
    if (!isValid) {
        e.preventDefault();
        alert('Please fill in all required fields and ensure all items have valid quantities.');
        return false;
    }
    
    // Show loading state
    $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Creating...').prop('disabled', true);
});

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + S to save
    if (e.ctrlKey && e.keyCode === 83) {
        e.preventDefault();
        $('#requisitionForm').submit();
    }
    // Escape to cancel
    if (e.keyCode === 27) {
        window.location.href = 'inventory_requisitions.php';
    }
    // Ctrl + I to add item
    if (e.ctrlKey && e.keyCode === 73) {
        e.preventDefault();
        addRequisitionItem();
    }
});
</script>

<style>
.requisition-item-row {
    background-color: #f8f9fa;
    border-left: 4px solid #28a745 !important;
}

.requisition-item-row:hover {
    background-color: #e9ecef;
}

#itemsTable_wrapper {
    margin: 0;
}

.select-item-btn {
    cursor: pointer;
}

.badge-lg {
    font-size: 1rem;
    padding: 0.5rem 1rem;
}

.card-header.bg-primary {
    background: linear-gradient(45deg, #0066cc, #007bff) !important;
}

.form-control:read-only {
    background-color: #f8f9fa;
}
</style>

<?php 
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
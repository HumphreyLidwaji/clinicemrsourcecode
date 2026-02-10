<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Initialize variables
$categories = [];
$items = [];

// Get active categories
$categories_sql = "SELECT category_id, category_name, category_type 
                   FROM inventory_categories 
                   WHERE category_is_active = 1 
                   ORDER BY category_type, category_name";
$categories_result = $mysqli->query($categories_sql);
while ($category = $categories_result->fetch_assoc()) {
    $categories[] = $category;
}

// Get active inventory items for selection
$items_sql = "SELECT item_id, item_name, item_code, item_quantity, item_low_stock_alert,
                     item_location, item_unit_measure, c.category_name
              FROM inventory_items i
              LEFT JOIN inventory_categories c ON i.item_category_id = c.category_id
              WHERE i.item_status != 'Discontinued' 
              ORDER BY c.category_name, i.item_name";
$items_result = $mysqli->query($items_sql);
while ($item = $items_result->fetch_assoc()) {
    $items[] = $item;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    $audit_type = sanitizeInput($_POST['audit_type']);
    $audit_scope = sanitizeInput($_POST['audit_scope']);
    $selected_categories = $_POST['selected_categories'] ?? [];
    $selected_items = $_POST['selected_items'] ?? [];
    $notes = sanitizeInput($_POST['notes']);
    $include_zero_stock = isset($_POST['include_zero_stock']) ? 1 : 0;
    $include_discontinued = isset($_POST['include_discontinued']) ? 1 : 0;

    // Validate CSRF token
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        header("Location: inventory_audit_start.php");
        exit;
    }

    // Validate required fields
    if (empty($audit_type) || empty($audit_scope)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please select audit type and scope.";
        header("Location: inventory_audit_start.php");
        exit;
    }

    // Validate scope-specific requirements
    if ($audit_scope === 'category' && empty($selected_categories)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please select at least one category for category-based audit.";
        header("Location: inventory_audit_start.php");
        exit;
    }

    if ($audit_scope === 'selected' && empty($selected_items)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please select at least one item for selected items audit.";
        header("Location: inventory_audit_start.php");
        exit;
    }

    // Build item selection criteria
    $item_criteria = [];
    
    if ($audit_scope === 'full') {
        // Full audit - all items
        $item_criteria_sql = "item_status != 'Discontinued'";
        if (!$include_discontinued) {
            $item_criteria_sql = "item_status IN ('In Stock', 'Low Stock', 'Out of Stock')";
        }
        if (!$include_zero_stock) {
            $item_criteria_sql .= " AND item_quantity > 0";
        }
    } elseif ($audit_scope === 'category') {
        // Category-based audit
        $category_ids = array_map('intval', $selected_categories);
        $category_list = implode(',', $category_ids);
        $item_criteria_sql = "item_category_id IN ($category_list)";
        if (!$include_discontinued) {
            $item_criteria_sql .= " AND item_status != 'Discontinued'";
        }
        if (!$include_zero_stock) {
            $item_criteria_sql .= " AND item_quantity > 0";
        }
    } elseif ($audit_scope === 'selected') {
        // Selected items audit
        $item_ids = array_map('intval', $selected_items);
        $item_list = implode(',', $item_ids);
        $item_criteria_sql = "item_id IN ($item_list)";
    }

    // Count total items for the audit
    $count_sql = "SELECT COUNT(*) as total_items FROM inventory_items WHERE $item_criteria_sql";
    $count_result = $mysqli->query($count_sql);
    $total_items = $count_result->fetch_assoc()['total_items'];

    if ($total_items === 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "No items found matching the selected criteria.";
        header("Location: inventory_audit_start.php");
        exit;
    }

    // Start transaction
    $mysqli->begin_transaction();

    try {
        // Create audit record
        $audit_sql = "INSERT INTO inventory_audits (
            audit_date, audit_type, auditor_id, status,
            total_items_count, items_audited, discrepancies_found, notes
        ) VALUES (CURDATE(), ?, ?, 'in_progress', ?, 0, 0, ?)";
        
        $audit_stmt = $mysqli->prepare($audit_sql);
        $audit_stmt->bind_param(
            "siis",
            $audit_type, $session_user_id, $total_items, $notes
        );
        $audit_stmt->execute();
        $audit_id = $audit_stmt->insert_id;
        $audit_stmt->close();

        // Get items for the audit and create audit items
        $items_sql = "SELECT item_id, item_quantity, item_name, item_code 
                      FROM inventory_items 
                      WHERE $item_criteria_sql 
                      ORDER BY item_name";
        $items_result = $mysqli->query($items_sql);
        
        $audit_items_sql = "INSERT INTO audit_items (
            audit_id, item_id, expected_quantity, counted_quantity, variance
        ) VALUES (?, ?, ?, 0, ?)";
        $audit_items_stmt = $mysqli->prepare($audit_items_sql);
        
        while ($item = $items_result->fetch_assoc()) {
            $variance = 0 - $item['item_quantity']; // Initially 0 counted, so variance is negative
            $audit_items_stmt->bind_param(
                "iiii",
                $audit_id, $item['item_id'], $item['item_quantity'], $variance
            );
            $audit_items_stmt->execute();
        }
        $audit_items_stmt->close();

        $mysqli->commit();

        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Inventory audit started successfully! <strong>$total_items</strong> items added to audit.";
        header("Location: inventory_audit_continue.php?id=" . $audit_id);
        exit;

    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error starting audit: " . $e->getMessage();
        header("Location: inventory_audit_start.php");
        exit;
    }
}
?>

<div class="card">
    <div class="card-header bg-success py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-play-circle mr-2"></i>Start New Inventory Audit
            </h3>
            <div class="card-tools">
                <a href="inventory_audit.php" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Audits
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

        <form method="POST" id="auditForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <div class="row">
                <div class="col-md-8">
                    <!-- Audit Configuration -->
                    <div class="card card-primary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-cog mr-2"></i>Audit Configuration</h3>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="audit_type">Audit Type *</label>
                                        <select class="form-control" id="audit_type" name="audit_type" required>
                                            <option value="">- Select Audit Type -</option>
                                            <option value="full">Full Inventory Audit</option>
                                            <option value="partial">Partial Audit</option>
                                            <option value="cycle">Cycle Count</option>
                                        </select>
                                        <small class="form-text text-muted">
                                            <i class="fas fa-info-circle mr-1"></i>
                                            <span id="audit_type_help">Choose the type of audit to perform</span>
                                        </small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="audit_scope">Audit Scope *</label>
                                        <select class="form-control" id="audit_scope" name="audit_scope" required>
                                            <option value="">- Select Scope -</option>
                                            <option value="full">Full Inventory</option>
                                            <option value="category">By Category</option>
                                            <option value="selected">Selected Items</option>
                                        </select>
                                        <small class="form-text text-muted">Define which items to include in the audit</small>
                                    </div>
                                </div>
                            </div>

                            <!-- Category Selection (shown when scope is 'category') -->
                            <div class="form-group" id="category_selection" style="display: none;">
                                <label>Select Categories *</label>
                                <div class="border rounded p-3 bg-light" style="max-height: 200px; overflow-y: auto;">
                                    <?php foreach ($categories as $category): ?>
                                        <div class="custom-control custom-checkbox">
                                            <input type="checkbox" class="custom-control-input category-checkbox" 
                                                   name="selected_categories[]" value="<?php echo $category['category_id']; ?>" 
                                                   id="category_<?php echo $category['category_id']; ?>">
                                            <label class="custom-control-label" for="category_<?php echo $category['category_id']; ?>">
                                                <strong><?php echo htmlspecialchars($category['category_name']); ?></strong>
                                                <span class="badge badge-light ml-2"><?php echo htmlspecialchars($category['category_type']); ?></span>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <small class="form-text text-muted">Select one or more categories to audit</small>
                            </div>

                            <!-- Item Selection (shown when scope is 'selected') -->
                            <div class="form-group" id="item_selection" style="display: none;">
                                <label>Select Items *</label>
                                <div class="border rounded p-3 bg-light" style="max-height: 300px; overflow-y: auto;">
                                    <div class="mb-2">
                                        <input type="text" class="form-control form-control-sm" id="item_search" 
                                               placeholder="Search items..." onkeyup="filterItems()">
                                    </div>
                                    <div id="item_list">
                                        <?php foreach ($items as $item): ?>
                                            <div class="custom-control custom-checkbox item-option" 
                                                 data-category="<?php echo htmlspecialchars($item['category_name'] ?? 'Uncategorized'); ?>">
                                                <input type="checkbox" class="custom-control-input item-checkbox" 
                                                       name="selected_items[]" value="<?php echo $item['item_id']; ?>" 
                                                       id="item_<?php echo $item['item_id']; ?>">
                                                <label class="custom-control-label" for="item_<?php echo $item['item_id']; ?>">
                                                    <strong><?php echo htmlspecialchars($item['item_name']); ?></strong>
                                                    <small class="text-muted d-block">
                                                        <?php echo htmlspecialchars($item['item_code']); ?> 
                                                        • Stock: <?php echo $item['item_quantity']; ?> 
                                                        <?php echo htmlspecialchars($item['item_unit_measure']); ?>
                                                        <?php if ($item['category_name']): ?>
                                                            • <?php echo htmlspecialchars($item['category_name']); ?>
                                                        <?php endif; ?>
                                                    </small>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <small class="form-text text-muted">Select specific items to include in the audit</small>
                            </div>

                            <div class="row mt-3">
                                <div class="col-md-6">
                                    <div class="custom-control custom-checkbox">
                                        <input type="checkbox" class="custom-control-input" id="include_zero_stock" name="include_zero_stock" checked>
                                        <label class="custom-control-label" for="include_zero_stock">
                                            Include Zero Stock Items
                                        </label>
                                        <small class="form-text text-muted">Include items with 0 quantity in the audit</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="custom-control custom-checkbox">
                                        <input type="checkbox" class="custom-control-input" id="include_discontinued" name="include_discontinued">
                                        <label class="custom-control-label" for="include_discontinued">
                                            Include Discontinued Items
                                        </label>
                                        <small class="form-text text-muted">Include discontinued items in the audit</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Audit Notes -->
                    <div class="card card-info">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-sticky-note mr-2"></i>Audit Notes</h3>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label for="notes">Audit Purpose & Notes</label>
                                <textarea class="form-control" id="notes" name="notes" rows="4" 
                                          placeholder="Purpose of this audit, special instructions, areas of focus, etc..." 
                                          maxlength="1000"></textarea>
                                <small class="form-text text-muted">Optional notes about this audit for reference</small>
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
                                    <i class="fas fa-play mr-2"></i>Start Audit
                                </button>
                                <button type="reset" class="btn btn-outline-secondary" onclick="resetForm()">
                                    <i class="fas fa-undo mr-2"></i>Reset Form
                                </button>
                                <a href="inventory_audit.php" class="btn btn-outline-danger">
                                    <i class="fas fa-times mr-2"></i>Cancel
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Audit Preview -->
                    <div class="card card-info">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-eye mr-2"></i>Audit Preview</h3>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-3">
                                <i class="fas fa-clipboard-check fa-3x text-info mb-2"></i>
                                <h5 id="preview_audit_type">New Audit</h5>
                                <div class="text-muted" id="preview_scope">Select configuration</div>
                            </div>
                            <hr>
                            <div class="small">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Estimated Items:</span>
                                    <span class="font-weight-bold" id="preview_item_count">-</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Audit Type:</span>
                                    <span class="font-weight-bold" id="preview_type">-</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Scope:</span>
                                    <span class="font-weight-bold" id="preview_scope_detail">-</span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Status:</span>
                                    <span class="font-weight-bold text-warning">Ready to Start</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Templates -->
                    <div class="card card-secondary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-magic mr-2"></i>Quick Templates</h3>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="setQuickTemplate('full_audit')">
                                    <i class="fas fa-clipboard-list mr-2"></i>Full Inventory Audit
                                </button>
                                <button type="button" class="btn btn-outline-warning btn-sm" onclick="setQuickTemplate('cycle_count')">
                                    <i class="fas fa-sync mr-2"></i>Cycle Count (High Value)
                                </button>
                                <button type="button" class="btn btn-outline-info btn-sm" onclick="setQuickTemplate('low_stock')">
                                    <i class="fas fa-exclamation-triangle mr-2"></i>Low Stock Focus
                                </button>
                                <button type="button" class="btn btn-outline-danger btn-sm" onclick="setQuickTemplate('zero_stock')">
                                    <i class="fas fa-ban mr-2"></i>Zero Stock Verification
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Audit Statistics -->
                    <div class="card card-warning">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-chart-bar mr-2"></i>Inventory Overview</h3>
                        </div>
                        <div class="card-body">
                            <?php
                            $stats_sql = "SELECT 
                                COUNT(*) as total_items,
                                SUM(CASE WHEN item_quantity <= 0 THEN 1 ELSE 0 END) as zero_stock,
                                SUM(CASE WHEN item_quantity <= item_low_stock_alert AND item_quantity > 0 THEN 1 ELSE 0 END) as low_stock,
                                COUNT(DISTINCT item_category_id) as categories
                                FROM inventory_items 
                                WHERE item_status != 'Discontinued'";
                            $stats_result = $mysqli->query($stats_sql);
                            $stats = $stats_result->fetch_assoc();
                            ?>
                            <div class="small">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Total Items:</span>
                                    <span class="font-weight-bold"><?php echo $stats['total_items']; ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Categories:</span>
                                    <span class="font-weight-bold"><?php echo $stats['categories']; ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Low Stock Items:</span>
                                    <span class="font-weight-bold text-warning"><?php echo $stats['low_stock']; ?></span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Zero Stock Items:</span>
                                    <span class="font-weight-bold text-danger"><?php echo $stats['zero_stock']; ?></span>
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
$(document).ready(function() {
    let totalItems = <?php echo $stats['total_items']; ?>;
    
    // Update scope selection visibility
    $('#audit_scope').on('change', function() {
        const scope = $(this).val();
        
        $('#category_selection, #item_selection').hide();
        $('.category-checkbox, .item-checkbox').prop('checked', false);
        
        if (scope === 'category') {
            $('#category_selection').show();
            updatePreview();
        } else if (scope === 'selected') {
            $('#item_selection').show();
            updatePreview();
        } else {
            updatePreview();
        }
    });

    // Update audit type help text
    $('#audit_type').on('change', function() {
        const type = $(this).val();
        let helpText = 'Choose the type of audit to perform';
        
        switch(type) {
            case 'full':
                helpText = 'Complete count of all inventory items. Recommended for quarterly/annual audits.';
                break;
            case 'partial':
                helpText = 'Count specific sections or categories. Good for regular spot checks.';
                break;
            case 'cycle':
                helpText = 'Regular counting of high-value or fast-moving items.';
                break;
        }
        
        $('#audit_type_help').text(helpText);
        updatePreview();
    });

    // Update preview when checkboxes change
    $('.category-checkbox, .item-checkbox').on('change', updatePreview);

    // Update include options
    $('#include_zero_stock, #include_discontinued').on('change', updatePreview);

    function updatePreview() {
        const auditType = $('#audit_type').val();
        const auditScope = $('#audit_scope').val();
        
        // Update basic info
        if (auditType) {
            $('#preview_audit_type').text(auditType.charAt(0).toUpperCase() + auditType.slice(1) + ' Audit');
            $('#preview_type').text(auditType.charAt(0).toUpperCase() + auditType.slice(1));
        }
        
        if (auditScope) {
            $('#preview_scope').text(auditScope.charAt(0).toUpperCase() + auditScope.slice(1) + ' Scope');
            $('#preview_scope_detail').text(auditScope.charAt(0).toUpperCase() + auditScope.slice(1));
        }

        // Estimate item count
        let estimatedCount = 0;
        
        if (auditScope === 'full') {
            estimatedCount = totalItems;
            if (!$('#include_zero_stock').is(':checked')) {
                estimatedCount -= <?php echo $stats['zero_stock']; ?>;
            }
            if (!$('#include_discontinued').is(':checked')) {
                // We'd need to query discontinued count, but for now we'll estimate
                estimatedCount = Math.max(estimatedCount - 10, 0); // Rough estimate
            }
        } else if (auditScope === 'category') {
            estimatedCount = $('.category-checkbox:checked').length * 15; // Rough estimate
        } else if (auditScope === 'selected') {
            estimatedCount = $('.item-checkbox:checked').length;
        }
        
        $('#preview_item_count').text(estimatedCount > 0 ? estimatedCount : '-');
    }

    // Initial preview update
    updatePreview();
});

// Item search and filter
function filterItems() {
    const searchTerm = $('#item_search').val().toLowerCase();
    
    $('.item-option').each(function() {
        const itemText = $(this).text().toLowerCase();
        const category = $(this).data('category').toLowerCase();
        
        if (itemText.includes(searchTerm) || category.includes(searchTerm)) {
            $(this).show();
        } else {
            $(this).hide();
        }
    });
}

// Quick templates
function setQuickTemplate(template) {
    switch(template) {
        case 'full_audit':
            $('#audit_type').val('full').trigger('change');
            $('#audit_scope').val('full').trigger('change');
            $('#include_zero_stock').prop('checked', true);
            $('#include_discontinued').prop('checked', false);
            $('#notes').val('Quarterly full inventory audit. Verify all stock levels and identify discrepancies.');
            break;
            
        case 'cycle_count':
            $('#audit_type').val('cycle').trigger('change');
            $('#audit_scope').val('category').trigger('change');
            // Select high-value categories (you might want to customize this)
            $('.category-checkbox').each(function() {
                const categoryName = $(this).closest('label').find('strong').text();
                if (categoryName.includes('Equipment') || categoryName.includes('Pharmacy')) {
                    $(this).prop('checked', true);
                }
            });
            $('#include_zero_stock').prop('checked', false);
            $('#include_discontinued').prop('checked', false);
            $('#notes').val('Cycle count of high-value items. Focus on accuracy of expensive inventory.');
            break;
            
        case 'low_stock':
            $('#audit_type').val('partial').trigger('change');
            $('#audit_scope').val('selected').trigger('change');
            // This would ideally filter for low stock items, but we'll select a few for demo
            $('.item-checkbox').slice(0, 10).prop('checked', true); // Select first 10 items
            $('#include_zero_stock').prop('checked', true);
            $('#notes').val('Focus audit on low stock and critical items. Verify reorder points.');
            break;
            
        case 'zero_stock':
            $('#audit_type').val('partial').trigger('change');
            $('#audit_scope').val('full').trigger('change');
            $('#include_zero_stock').prop('checked', true);
            $('#include_discontinued').prop('checked', false);
            $('#notes').val('Verification audit of zero stock items. Confirm items are truly out of stock.');
            break;
    }
    
    updatePreview();
}

function resetForm() {
    if (confirm('Are you sure you want to reset all settings?')) {
        $('#auditForm').trigger('reset');
        $('#category_selection, #item_selection').hide();
        $('.category-checkbox, .item-checkbox').prop('checked', false);
        updatePreview();
    }
}

// Form validation
$('#auditForm').on('submit', function(e) {
    const auditType = $('#audit_type').val();
    const auditScope = $('#audit_scope').val();
    const selectedCategories = $('.category-checkbox:checked').length;
    const selectedItems = $('.item-checkbox:checked').length;
    
    let isValid = true;
    let errorMessage = '';
    
    // Validate required fields
    if (!auditType || !auditScope) {
        isValid = false;
        errorMessage = 'Please select audit type and scope.';
    } else if (auditScope === 'category' && selectedCategories === 0) {
        isValid = false;
        errorMessage = 'Please select at least one category.';
    } else if (auditScope === 'selected' && selectedItems === 0) {
        isValid = false;
        errorMessage = 'Please select at least one item.';
    }
    
    if (!isValid) {
        e.preventDefault();
        alert(errorMessage);
        return false;
    }
    
    // Show loading state
    $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Starting...').prop('disabled', true);
});

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + S to save/start
    if (e.ctrlKey && e.keyCode === 83) {
        e.preventDefault();
        $('#auditForm').submit();
    }
    // Escape to cancel
    if (e.keyCode === 27) {
        window.location.href = 'inventory_audit.php';
    }
});
</script>

<style>
.category-checkbox, .item-checkbox {
    margin-bottom: 8px;
}

.item-option {
    padding: 4px 0;
    border-bottom: 1px solid #f8f9fa;
}

.item-option:last-child {
    border-bottom: none;
}

#item_list {
    max-height: 250px;
    overflow-y: auto;
}

.custom-control-label {
    cursor: pointer;
}
</style>

  <?php 
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
    ?>
    
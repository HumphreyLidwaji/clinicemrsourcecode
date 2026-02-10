<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/price_functions.php';

// Handle actions
$action = $_GET['action'] ?? '';
$modifier_id = intval($_GET['id'] ?? 0);

// Add new modifier
if ($action == 'add' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $modifier_name = sanitizeInput($_POST['modifier_name']);
    $modifier_type = sanitizeInput($_POST['modifier_type']);
    $modifier_value = floatval($_POST['modifier_value']);
    $applies_to = sanitizeInput($_POST['applies_to']);
    $target_id = ($_POST['target_id'] ?? '') !== '' ? intval($_POST['target_id']) : NULL;
    $valid_from = !empty($_POST['valid_from']) ? $_POST['valid_from'] : date('Y-m-d');
    $valid_to = !empty($_POST['valid_to']) ? $_POST['valid_to'] : null;
    $is_active = isset($_POST['is_active']) ? 1 : 1;
    $created_by = intval($_SESSION['user_id']);
    
    $sql = "INSERT INTO price_modifiers SET 
            modifier_name = ?,
            modifier_type = ?,
            modifier_value = ?,
            applies_to = ?,
            target_id = ?,
            valid_from = ?,
            valid_to = ?,
            is_active = ?,
            created_by = ?,
            created_at = NOW()";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param(
        'ssdsisssi',
        $modifier_name,
        $modifier_type,
        $modifier_value,
        $applies_to,
        $target_id,
        $valid_from,
        $valid_to,
        $is_active,
        $created_by
    );
    
    if ($stmt->execute()) {
        $modifier_id = $mysqli->insert_id;
        
        // Handle specific applications if needed
        // (This part can be expanded based on your specific needs)
        
        $_SESSION['alert_message'] = "Price modifier created successfully";
        header("Location: price_modifiers.php");
        exit;
    } else {
        $error_message = "Error creating price modifier: " . $stmt->error;
    }
}

// Delete modifier
if ($action == 'delete' && $modifier_id) {
    $sql = "DELETE FROM price_modifiers WHERE modifier_id = ?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('i', $modifier_id);
    
    if ($stmt->execute()) {
        $_SESSION['alert_message'] = "Price modifier deleted";
    } else {
        $_SESSION['alert_message'] = "Error deleting price modifier";
    }
    
    header("Location: price_modifiers.php");
    exit;
}

// Toggle status
if ($action == 'toggle_status' && $modifier_id) {
    $sql = "UPDATE price_modifiers SET is_active = NOT is_active WHERE modifier_id = ?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('i', $modifier_id);
    $stmt->execute();
    
    header("Location: price_modifiers.php");
    exit;
}

// Get all modifiers
$sql = "SELECT pm.*, u.user_name as created_by_name
        FROM price_modifiers pm
        LEFT JOIN users u ON pm.created_by = u.user_id
        ORDER BY pm.created_at DESC";

$modifiers_result = $mysqli->query($sql);
?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-percentage mr-2"></i>Price Modifiers
        </h3>
        <div class="card-tools">
            <a href="price_management.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left mr-2"></i>Back
            </a>
            <button class="btn btn-success ml-2" data-toggle="modal" data-target="#addModifierModal">
                <i class="fas fa-plus mr-2"></i>Add Modifier
            </button>
        </div>
    </div>
    
    <div class="card-body">
        <?php if (isset($_SESSION['alert_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle mr-2"></i><?php echo $_SESSION['alert_message']; ?>
            <button type="button" class="close" data-dismiss="alert">
                <span>&times;</span>
            </button>
        </div>
        <?php unset($_SESSION['alert_message']); endif; ?>
        
        <?php if (isset($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error_message); ?>
            <button type="button" class="close" data-dismiss="alert">
                <span>&times;</span>
            </button>
        </div>
        <?php endif; ?>
        
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Value</th>
                        <th>Applies To</th>
                        <th>Target</th>
                        <th>Validity</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($modifier = $modifiers_result->fetch_assoc()): ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($modifier['modifier_name']); ?></strong>
                        </td>
                        <td>
                            <span class="badge badge-info"><?php echo $modifier['modifier_type']; ?></span>
                        </td>
                        <td>
                            <?php if($modifier['modifier_type'] == 'percentage'): ?>
                                <?php echo $modifier['modifier_value']; ?>%
                            <?php else: ?>
                                <?php echo number_format($modifier['modifier_value'], 2); ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo ucfirst($modifier['applies_to']); ?>
                        </td>
                        <td>
                            <?php if($modifier['target_id']): ?>
                                <small class="text-muted">ID: <?php echo $modifier['target_id']; ?></small>
                            <?php else: ?>
                                <span class="badge badge-secondary">All</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <small>
                                <?php echo date('M j, Y', strtotime($modifier['valid_from'])); ?>
                                <?php if($modifier['valid_to']): ?>
                                    <br>to <?php echo date('M j, Y', strtotime($modifier['valid_to'])); ?>
                                <?php else: ?>
                                    <br>to ∞
                                <?php endif; ?>
                            </small>
                        </td>
                        <td>
                            <span class="badge badge-<?php echo $modifier['is_active'] ? 'success' : 'secondary'; ?>">
                                <?php echo $modifier['is_active'] ? 'Active' : 'Inactive'; ?>
                            </span>
                        </td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <button class="btn btn-outline-primary" data-toggle="modal" data-target="#viewModifierModal<?php echo $modifier['modifier_id']; ?>">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <a href="price_modifiers.php?action=toggle_status&id=<?php echo $modifier['modifier_id']; ?>" 
                                   class="btn btn-outline-<?php echo $modifier['is_active'] ? 'warning' : 'success'; ?>">
                                    <i class="fas fa-<?php echo $modifier['is_active'] ? 'pause' : 'play'; ?>"></i>
                                </a>
                                <a href="price_modifiers.php?action=delete&id=<?php echo $modifier['modifier_id']; ?>" 
                                   class="btn btn-outline-danger" onclick="return confirm('Delete this modifier?')">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                    
                    <!-- View Modal -->
                    <div class="modal fade" id="viewModifierModal<?php echo $modifier['modifier_id']; ?>">
                        <div class="modal-dialog modal-lg">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title"><?php echo htmlspecialchars($modifier['modifier_name']); ?></h5>
                                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                                </div>
                                <div class="modal-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <p><strong>Type:</strong> <?php echo $modifier['modifier_type']; ?></p>
                                            <p><strong>Value:</strong> 
                                                <?php if($modifier['modifier_type'] == 'percentage'): ?>
                                                    <?php echo $modifier['modifier_value']; ?>%
                                                <?php else: ?>
                                                    <?php echo number_format($modifier['modifier_value'], 2); ?>
                                                <?php endif; ?>
                                            </p>
                                            <p><strong>Applies To:</strong> <?php echo ucfirst($modifier['applies_to']); ?></p>
                                        </div>
                                        <div class="col-md-6">
                                            <p><strong>Target ID:</strong> <?php echo $modifier['target_id'] ?: 'All'; ?></p>
                                            <p><strong>Validity:</strong> 
                                                <?php echo date('M j, Y', strtotime($modifier['valid_from'])); ?>
                                                <?php if($modifier['valid_to']): ?>
                                                    to <?php echo date('M j, Y', strtotime($modifier['valid_to'])); ?>
                                                <?php else: ?>
                                                    to Indefinite
                                                <?php endif; ?>
                                            </p>
                                            <p><strong>Status:</strong> 
                                                <span class="badge badge-<?php echo $modifier['is_active'] ? 'success' : 'secondary'; ?>">
                                                    <?php echo $modifier['is_active'] ? 'Active' : 'Inactive'; ?>
                                                </span>
                                            </p>
                                            <p><strong>Created:</strong> 
                                                <?php echo date('M j, Y', strtotime($modifier['created_at'])); ?>
                                                by <?php echo htmlspecialchars($modifier['created_by_name']); ?>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Modifier Modal -->
<div class="modal fade" id="addModifierModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Price Modifier</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form method="POST" action="price_modifiers.php?action=add">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label>Modifier Name *</label>
                                <input type="text" class="form-control" name="modifier_name" required 
                                       placeholder="e.g., Staff Discount, Volume Discount, etc.">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Modifier Type *</label>
                                <select class="form-control" name="modifier_type" required>
                                    <option value="">Select Type</option>
                                    <option value="percentage">Percentage (%)</option>
                                    <option value="fixed">Fixed Amount</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Modifier Value *</label>
                                <input type="number" class="form-control" name="modifier_value" step="0.01" min="0" required>
                                <small class="form-text text-muted">Percentage or fixed amount</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Applies To *</label>
                                <select class="form-control" name="applies_to" required>
                                    <option value="">Select Target</option>
                                    <option value="all">All Billable Items</option>
                                    <option value="price_list">Specific Price List</option>
                                    <option value="category">Specific Category</option>
                                    <option value="item">Specific Billable Item</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Target ID (Optional)</label>
                                <input type="number" class="form-control" name="target_id" min="0">
                                <small class="form-text text-muted">Required if applies to specific item/list/category</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Valid From *</label>
                                <input type="date" class="form-control" name="valid_from" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Valid To (Optional)</label>
                                <input type="date" class="form-control" name="valid_to">
                                <small class="form-text text-muted">Leave empty for indefinite validity</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-12">
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input" id="isActiveNew" name="is_active" value="1" checked>
                                <label class="custom-control-label" for="isActiveNew">Active</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Create Modifier</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Show/hide target ID field based on selection
    $('select[name="applies_to"]').change(function() {
        var appliesTo = $(this).val();
        var targetIdField = $('input[name="target_id"]');
        
        if (appliesTo === 'all') {
            targetIdField.prop('required', false);
            targetIdField.closest('.form-group').hide();
        } else {
            targetIdField.prop('required', true);
            targetIdField.closest('.form-group').show();
        }
    });
    
    // Initialize the display
    $('select[name="applies_to"]').trigger('change');
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
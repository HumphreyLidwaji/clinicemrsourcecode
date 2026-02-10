<?php
// laundry_replenish.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

$page_title = "Replenish Stock";

// Get category ID if provided
$category_id = isset($_GET['category_id']) ? intval($_GET['category_id']) : 0;

// Check if form is submitted
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $items = $_POST['items'] ?? [];
    $user_id = intval($_SESSION['user_id']);
    
    if (empty($items)) {
        $error = "Please select items to replenish";
    } else {
        $mysqli->begin_transaction();
        
        try {
            foreach ($items as $item_data) {
                list($laundry_id, $asset_id, $quantity) = explode('_', $item_data);
                $laundry_id = intval($laundry_id);
                $asset_id = intval($asset_id);
                $quantity = intval($quantity);
                
                // Update quantity
                $update_sql = "UPDATE laundry_items SET quantity = quantity + ? WHERE laundry_id = ?";
                $update_stmt = $mysqli->prepare($update_sql);
                $update_stmt->bind_param("ii", $quantity, $laundry_id);
                $update_stmt->execute();
                
                // Create transaction
                $transaction_sql = "INSERT INTO laundry_transactions 
                                   (laundry_id, transaction_type, from_location, to_location, 
                                    performed_by, notes) 
                                   VALUES (?, 'checkin', 'new', 'storage', ?, 'Replenished stock: +$quantity items')";
                $transaction_stmt = $mysqli->prepare($transaction_sql);
                $transaction_stmt->bind_param("ii", $laundry_id, $user_id);
                $transaction_stmt->execute();
            }
            
            $mysqli->commit();
            
            logActivity($user_id, "Replenished stock for " . count($items) . " items", "laundry");
            
            $_SESSION['alert_message'] = "Stock replenished successfully!";
            $_SESSION['alert_type'] = "success";
            
            header("Location: laundry_management.php");
            exit();
            
        } catch (Exception $e) {
            $mysqli->rollback();
            $error = "Error replenishing stock: " . $e->getMessage();
        }
    }
}

// Get low stock items
$low_stock_sql = "
    SELECT li.*, a.asset_name, a.asset_tag, lc.category_name,
           lc.min_quantity, lc.reorder_point,
           (lc.min_quantity - li.quantity) as needed
    FROM laundry_items li
    LEFT JOIN assets a ON li.asset_id = a.asset_id
    LEFT JOIN laundry_categories lc ON li.category_id = lc.category_id
    WHERE li.quantity < lc.min_quantity
    AND lc.min_quantity > 0
    AND li.status = 'clean'
    " . ($category_id ? " AND li.category_id = $category_id" : "") . "
    ORDER BY needed DESC, a.asset_name
";
$low_stock_result = $mysqli->query($low_stock_sql);

// Get categories for filter
$categories_sql = "SELECT * FROM laundry_categories WHERE is_active = 1 ORDER BY category_name";
$categories_result = $mysqli->query($categories_sql);
?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-boxes mr-2"></i>Replenish Stock
        </h3>
        <div class="card-tools">
            <a href="laundry_management.php" class="btn btn-secondary">
                <i class="fas fa-fw fa-arrow-left mr-2"></i>Back to Laundry
            </a>
        </div>
    </div>
    
    <div class="card-body">
        <?php if (isset($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle mr-2"></i><?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <!-- Filter Form -->
        <div class="card mb-4">
            <div class="card-body bg-light">
                <form action="laundry_replenish.php" method="GET" autocomplete="off">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Filter by Category</label>
                                <select class="form-control select2" name="category_id" onchange="this.form.submit()">
                                    <option value="0">All Categories</option>
                                    <?php while($category = $categories_result->fetch_assoc()): ?>
                                        <option value="<?php echo $category['category_id']; ?>" 
                                                <?php echo $category_id == $category['category_id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($category['category_name']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>&nbsp;</label>
                                <div class="btn-group btn-block">
                                    <a href="laundry_replenish.php" class="btn btn-outline-secondary">
                                        <i class="fas fa-times mr-2"></i>Clear Filter
                                    </a>
                                    <button type="button" class="btn btn-outline-primary" onclick="selectAllItems()">
                                        <i class="fas fa-check-square mr-2"></i>Select All
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <?php if ($low_stock_result->num_rows == 0): ?>
            <div class="alert alert-success text-center">
                <i class="fas fa-check-circle fa-3x mb-3"></i>
                <h4>Great News!</h4>
                <p class="mb-0">All items are well-stocked. No replenishment needed at this time.</p>
            </div>
            <div class="text-center">
                <a href="laundry_management.php" class="btn btn-primary">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Laundry Management
                </a>
            </div>
        <?php else: ?>
        
        <form action="laundry_replenish.php" method="POST" autocomplete="off">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="bg-light">
                        <tr>
                            <th width="5%">
                                <input type="checkbox" id="selectAll" onchange="toggleAll()">
                            </th>
                            <th>Item</th>
                            <th>Category</th>
                            <th class="text-center">Current Qty</th>
                            <th class="text-center">Min Qty</th>
                            <th class="text-center">Needed</th>
                            <th class="text-center">Replenish Qty</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($item = $low_stock_result->fetch_assoc()): 
                            $needed = max(1, $item['needed']);
                        ?>
                        <tr>
                            <td>
                                <input type="checkbox" class="item-select" 
                                       name="items[]" 
                                       value="<?php echo $item['laundry_id']; ?>_<?php echo $item['asset_id']; ?>_<?php echo $needed; ?>"
                                       onchange="updateSelection()">
                            </td>
                            <td>
                                <strong><?php echo htmlspecialchars($item['asset_name']); ?></strong><br>
                                <small class="text-muted"><?php echo htmlspecialchars($item['asset_tag']); ?></small>
                            </td>
                            <td>
                                <span class="badge badge-info">
                                    <?php echo htmlspecialchars($item['category_name']); ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <span class="badge badge-warning"><?php echo $item['quantity']; ?></span>
                            </td>
                            <td class="text-center">
                                <span class="badge badge-light"><?php echo $item['min_quantity']; ?></span>
                            </td>
                            <td class="text-center">
                                <span class="badge badge-danger"><?php echo $needed; ?></span>
                            </td>
                            <td class="text-center">
                                <input type="number" class="form-control form-control-sm qty-input" 
                                       value="<?php echo $needed; ?>" min="1" max="100"
                                       style="width: 80px; display: inline-block;"
                                       onchange="updateQuantity(this, <?php echo $item['laundry_id']; ?>, <?php echo $item['asset_id']; ?>)">
                            </td>
                            <td>
                                <span class="badge badge-<?php 
                                    switch($item['status']) {
                                        case 'clean': echo 'success'; break;
                                        case 'dirty': echo 'warning'; break;
                                        default: echo 'secondary';
                                    }
                                ?>">
                                    <?php echo ucfirst($item['status']); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="card mt-4">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-8">
                            <div class="form-group">
                                <label>Replenishment Notes</label>
                                <textarea class="form-control" name="notes" rows="2" 
                                          placeholder="Any notes about this replenishment..."></textarea>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-right">
                                <h5>Summary</h5>
                                <p>
                                    <strong>Selected Items:</strong> 
                                    <span id="selectedCount">0</span>
                                </p>
                                <p>
                                    <strong>Total to Add:</strong> 
                                    <span id="totalQuantity">0</span> items
                                </p>
                                <button type="submit" class="btn btn-success btn-lg" id="submitBtn" disabled>
                                    <i class="fas fa-box-open mr-2"></i>Replenish Stock
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
        
        <?php endif; ?>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.select2').select2();
    updateSelection();
});

function toggleAll() {
    var isChecked = $('#selectAll').prop('checked');
    $('.item-select').prop('checked', isChecked);
    updateSelection();
}

function selectAllItems() {
    $('.item-select').prop('checked', true);
    $('#selectAll').prop('checked', true);
    updateSelection();
}

function updateQuantity(input, laundryId, assetId) {
    var quantity = parseInt(input.value);
    var checkbox = $(input).closest('tr').find('.item-select');
    var currentValue = checkbox.val().split('_');
    currentValue[2] = quantity; // Update quantity in the value
    checkbox.val(currentValue.join('_'));
    updateSelection();
}

function updateSelection() {
    var selectedCount = $('.item-select:checked').length;
    var totalQuantity = 0;
    
    $('.item-select:checked').each(function() {
        var parts = $(this).val().split('_');
        totalQuantity += parseInt(parts[2]);
    });
    
    $('#selectedCount').text(selectedCount);
    $('#totalQuantity').text(totalQuantity);
    
    if (selectedCount > 0) {
        $('#submitBtn').prop('disabled', false);
    } else {
        $('#submitBtn').prop('disabled', true);
    }
}
</script>

<style>
.qty-input {
    text-align: center;
}

.table tbody tr:hover {
    background-color: #f8f9fa;
}

#submitBtn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
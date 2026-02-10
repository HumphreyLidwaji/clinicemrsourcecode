<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/price_functions.php';

$price_list_id = intval($_GET['price_list_id'] ?? 0);
$action = $_GET['action'] ?? '';

// Handle bulk update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['bulk_action'])) {
    $bulk_action = $_POST['bulk_action'];
    $price_list_id = intval($_POST['price_list_id']);
    $changed_by = intval($_SESSION['user_id']);
    
    if ($bulk_action == 'percentage') {
        $percentage = floatval($_POST['percentage']);
        $increase = ($_POST['change_type'] == 'increase');
        
        // Get all items in price list
        $items = getPriceListItems($mysqli, $price_list_id);
        $services = getPriceListServices($mysqli, $price_list_id);
        
        $updates = [];
        
        // Update items
        foreach ($items as $item) {
            $old_price = $item['price'];
            if ($increase) {
                $new_price = $old_price * (1 + ($percentage / 100));
            } else {
                $new_price = $old_price * (1 - ($percentage / 100));
            }
            
            $updates[] = [
                'entity_type' => 'ITEM',
                'entity_id' => $item['item_id'],
                'price' => round($new_price, 2),
                'covered_percentage' => $item['covered_percentage'],
                'reason' => 'Bulk ' . ($increase ? 'increase' : 'decrease') . ' by ' . $percentage . '%'
            ];
        }
        
        // Update services
        foreach ($services as $service) {
            $old_price = $service['price'];
            if ($increase) {
                $new_price = $old_price * (1 + ($percentage / 100));
            } else {
                $new_price = $old_price * (1 - ($percentage / 100));
            }
            
            $updates[] = [
                'entity_type' => 'SERVICE',
                'entity_id' => $service['medical_service_id'],
                'price' => round($new_price, 2),
                'covered_percentage' => $service['covered_percentage'],
                'reason' => 'Bulk ' . ($increase ? 'increase' : 'decrease') . ' by ' . $percentage . '%'
            ];
        }
        
        $result = bulkUpdatePrices($mysqli, $price_list_id, $updates, $changed_by);
        
        if ($result['success']) {
            $_SESSION['alert_message'] = "Bulk update completed: " . $result['success_count'] . " updated, " . $result['error_count'] . " errors";
        } else {
            $_SESSION['alert_message'] = "Bulk update failed: " . $result['message'];
        }
        
        header("Location: bulk_price_update.php?price_list_id=$price_list_id");
        exit;
        
    } elseif ($bulk_action == 'fixed') {
        $fixed_amount = floatval($_POST['fixed_amount']);
        $increase = ($_POST['change_type'] == 'increase');
        
        // Get all items in price list
        $items = getPriceListItems($mysqli, $price_list_id);
        $services = getPriceListServices($mysqli, $price_list_id);
        
        $updates = [];
        
        // Update items
        foreach ($items as $item) {
            $old_price = $item['price'];
            if ($increase) {
                $new_price = $old_price + $fixed_amount;
            } else {
                $new_price = max(0, $old_price - $fixed_amount);
            }
            
            $updates[] = [
                'entity_type' => 'ITEM',
                'entity_id' => $item['item_id'],
                'price' => round($new_price, 2),
                'covered_percentage' => $item['covered_percentage'],
                'reason' => 'Bulk ' . ($increase ? 'increase' : 'decrease') . ' by ' . number_format($fixed_amount, 2)
            ];
        }
        
        // Update services
        foreach ($services as $service) {
            $old_price = $service['price'];
            if ($increase) {
                $new_price = $old_price + $fixed_amount;
            } else {
                $new_price = max(0, $old_price - $fixed_amount);
            }
            
            $updates[] = [
                'entity_type' => 'SERVICE',
                'entity_id' => $service['medical_service_id'],
                'price' => round($new_price, 2),
                'covered_percentage' => $service['covered_percentage'],
                'reason' => 'Bulk ' . ($increase ? 'increase' : 'decrease') . ' by ' . number_format($fixed_amount, 2)
            ];
        }
        
        $result = bulkUpdatePrices($mysqli, $price_list_id, $updates, $changed_by);
        
        if ($result['success']) {
            $_SESSION['alert_message'] = "Bulk update completed: " . $result['success_count'] . " updated, " . $result['error_count'] . " errors";
        } else {
            $_SESSION['alert_message'] = "Bulk update failed: " . $result['message'];
        }
        
        header("Location: bulk_price_update.php?price_list_id=$price_list_id");
        exit;
    }
}

// Get price lists for dropdown
$price_lists = getAllPriceLists($mysqli);

// If price list is selected, get its details
$price_list_details = null;
$items = [];
$services = [];
if ($price_list_id) {
    $price_list_details = getPriceListDetails($mysqli, $price_list_id);
    $items = getPriceListItems($mysqli, $price_list_id);
    $services = getPriceListServices($mysqli, $price_list_id);
}
?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-sync-alt mr-2"></i>Bulk Price Update
        </h3>
        <div class="card-tools">
            <a href="price_management.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left mr-2"></i>Back
            </a>
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
        
        <div class="row">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header bg-light">
                        <h6 class="card-title mb-0">Select Price List</h6>
                    </div>
                    <div class="card-body">
                        <form method="GET" id="selectPriceListForm">
                            <div class="form-group">
                                <label>Price List</label>
                                <select class="form-control" name="price_list_id" id="priceListSelect" required>
                                    <option value="">Select Price List</option>
                                    <?php foreach($price_lists as $pl): ?>
                                    <option value="<?php echo $pl['price_list_id']; ?>" 
                                        <?php echo ($price_list_id == $pl['price_list_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($pl['list_name'] . " (" . $pl['payer_type'] . ")"); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary btn-block">
                                <i class="fas fa-check mr-2"></i>Select Price List
                            </button>
                        </form>
                    </div>
                </div>
                
                <?php if ($price_list_details): ?>
                <div class="card mt-3">
                    <div class="card-header bg-light">
                        <h6 class="card-title mb-0">Price List Info</h6>
                    </div>
                    <div class="card-body">
                        <p><strong>Name:</strong> <?php echo htmlspecialchars($price_list_details['list_name']); ?></p>
                        <p><strong>Type:</strong> 
                            <span class="badge badge-<?php echo $price_list_details['payer_type'] == 'CASH' ? 'success' : 'info'; ?>">
                                <?php echo $price_list_details['payer_type']; ?>
                            </span>
                        </p>
                        <?php if($price_list_details['company_name']): ?>
                            <p><strong>Insurance Company:</strong> <?php echo htmlspecialchars($price_list_details['company_name']); ?></p>
                        <?php endif; ?>
                        <p><strong>Items:</strong> <?php echo count($items); ?></p>
                        <p><strong>Services:</strong> <?php echo count($services); ?></p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="col-md-8">
                <?php if ($price_list_id): ?>
                <div class="card">
                    <div class="card-header bg-light">
                        <h6 class="card-title mb-0">Bulk Update Actions</h6>
                    </div>
                    <div class="card-body">
                        <!-- Percentage Update -->
                        <div class="card mb-3">
                            <div class="card-header">
                                <h6 class="card-title mb-0">Percentage Update</h6>
                            </div>
                            <div class="card-body">
                                <form method="POST" onsubmit="return confirm('Update ALL prices in this list? This cannot be undone!')">
                                    <input type="hidden" name="bulk_action" value="percentage">
                                    <input type="hidden" name="price_list_id" value="<?php echo $price_list_id; ?>">
                                    
                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="form-group">
                                                <label>Change Type</label>
                                                <select class="form-control" name="change_type" required>
                                                    <option value="increase">Increase Prices</option>
                                                    <option value="decrease">Decrease Prices</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-group">
                                                <label>Percentage</label>
                                                <div class="input-group">
                                                    <input type="number" class="form-control" name="percentage" step="0.01" min="0.01" max="1000" required>
                                                    <div class="input-group-append">
                                                        <span class="input-group-text">%</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-group">
                                                <label>&nbsp;</label>
                                                <button type="submit" class="btn btn-warning btn-block">
                                                    <i class="fas fa-percentage mr-2"></i>Apply Percentage
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    <small class="text-muted">This will update <?php echo count($items) + count($services); ?> items/services</small>
                                </form>
                            </div>
                        </div>
                        
                        <!-- Fixed Amount Update -->
                        <div class="card mb-3">
                            <div class="card-header">
                                <h6 class="card-title mb-0">Fixed Amount Update</h6>
                            </div>
                            <div class="card-body">
                                <form method="POST" onsubmit="return confirm('Update ALL prices in this list? This cannot be undone!')">
                                    <input type="hidden" name="bulk_action" value="fixed">
                                    <input type="hidden" name="price_list_id" value="<?php echo $price_list_id; ?>">
                                    
                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="form-group">
                                                <label>Change Type</label>
                                                <select class="form-control" name="change_type" required>
                                                    <option value="increase">Increase Prices</option>
                                                    <option value="decrease">Decrease Prices</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-group">
                                                <label>Fixed Amount</label>
                                                <div class="input-group">
                                                    <div class="input-group-prepend">
                                                        <span class="input-group-text">$</span>
                                                    </div>
                                                    <input type="number" class="form-control" name="fixed_amount" step="0.01" min="0.01" required>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-group">
                                                <label>&nbsp;</label>
                                                <button type="submit" class="btn btn-info btn-block">
                                                    <i class="fas fa-dollar-sign mr-2"></i>Apply Fixed Amount
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    <small class="text-muted">This will update <?php echo count($items) + count($services); ?> items/services</small>
                                </form>
                            </div>
                        </div>
                        
                        <!-- CSV Import -->
                        <div class="card">
                            <div class="card-header">
                                <h6 class="card-title mb-0">CSV Import</h6>
                            </div>
                            <div class="card-body">
                                <form method="POST" action="price_import.php" enctype="multipart/form-data">
                                    <input type="hidden" name="price_list_id" value="<?php echo $price_list_id; ?>">
                                    
                                    <div class="row">
                                        <div class="col-md-8">
                                            <div class="form-group">
                                                <label>Upload CSV File</label>
                                                <div class="custom-file">
                                                    <input type="file" class="custom-file-input" id="csvFile" name="csv_file" accept=".csv" required>
                                                    <label class="custom-file-label" for="csvFile">Choose CSV file</label>
                                                </div>
                                                <small class="form-text text-muted">
                                                    CSV format: Entity Type, Entity ID, New Price, Coverage %
                                                </small>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-group">
                                                <label>&nbsp;</label>
                                                <button type="submit" class="btn btn-success btn-block">
                                                    <i class="fas fa-file-import mr-2"></i>Import CSV
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-12">
                                            <a href="price_import.php?price_list_id=<?php echo $price_list_id; ?>&template=1" 
                                               class="btn btn-outline-secondary btn-sm">
                                                <i class="fas fa-download mr-2"></i>Download Template
                                            </a>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Preview of items to be updated -->
                <div class="card mt-3">
                    <div class="card-header bg-light">
                        <h6 class="card-title mb-0">Current Prices (First 10 items)</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Type</th>
                                        <th>Name</th>
                                        <th>Current Price</th>
                                        <th>Coverage</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $count = 0;
                                    foreach($items as $item): 
                                        if ($count++ >= 10) break;
                                    ?>
                                    <tr>
                                        <td><span class="badge badge-primary">ITEM</span></td>
                                        <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                                        <td><?php echo number_format($item['price'], 2); ?></td>
                                        <td><?php echo $item['covered_percentage']; ?>%</td>
                                    </tr>
                                    <?php endforeach; ?>
                                    
                                    <?php 
                                    $count = 0;
                                    foreach($services as $service): 
                                        if ($count++ >= 10) break;
                                    ?>
                                    <tr>
                                        <td><span class="badge badge-success">SERVICE</span></td>
                                        <td><?php echo htmlspecialchars($service['service_name']); ?></td>
                                        <td><?php echo number_format($service['price'], 2); ?></td>
                                        <td><?php echo $service['covered_percentage']; ?>%</td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if (count($items) + count($services) > 10): ?>
                            <p class="text-muted">Showing 10 of <?php echo count($items) + count($services); ?> items/services</p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php else: ?>
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="fas fa-list-alt fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">Select a Price List</h5>
                        <p class="text-muted">Please select a price list from the dropdown to begin bulk updates.</p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // File input label
    $('#csvFile').on('change', function() {
        var fileName = $(this).val().split('\\').pop();
        $(this).next('.custom-file-label').html(fileName);
    });
    
    // Auto-submit price list selection
    $('#priceListSelect').change(function() {
        if ($(this).val()) {
            $('#selectPriceListForm').submit();
        }
    });
    
    // Confirm before bulk updates
    $('form[onsubmit]').submit(function(e) {
        if (!confirm('This will update ALL prices in the selected price list. This action cannot be undone. Continue?')) {
            e.preventDefault();
            return false;
        }
    });
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
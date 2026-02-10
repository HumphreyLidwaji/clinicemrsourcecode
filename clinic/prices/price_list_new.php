<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/price_functions.php';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $price_list_name = sanitizeInput($_POST['price_list_name']);
    $price_list_code = sanitizeInput($_POST['price_list_code']);
    $price_list_type = sanitizeInput($_POST['price_list_type']);
    $insurance_provider_id = ($_POST['insurance_provider_id'] ?? '') !== '' ? intval($_POST['insurance_provider_id']) : NULL;
    $revenue_account_id = ($_POST['revenue_account_id'] ?? '') !== '' ? intval($_POST['revenue_account_id']) : NULL;
    $accounts_receivable_account_id = ($_POST['accounts_receivable_account_id'] ?? '') !== '' ? intval($_POST['accounts_receivable_account_id']) : NULL;
    $is_default = isset($_POST['is_default']) ? 1 : 0;
    $is_active = isset($_POST['is_active']) ? 1 : 1;
    $valid_from = !empty($_POST['valid_from']) ? $_POST['valid_from'] : date('Y-m-d');
    $valid_to = !empty($_POST['valid_to']) ? $_POST['valid_to'] : NULL;
    $currency = sanitizeInput($_POST['currency'] ?? 'USD');
    $notes = sanitizeInput($_POST['notes'] ?? '');
    $created_by = intval($_SESSION['user_id']);
    
    // Debug log
    error_log("Creating price list - price_list_type: $price_list_type, insurance_provider_id: " . ($insurance_provider_id ?? 'NULL'));
    
    // For cash price lists, insurance_provider_id must be NULL
    if ($price_list_type == 'cash') {
        $insurance_provider_id = NULL;
    }
    
    // Validate insurance provider exists if provided (for insurance lists)
    if ($price_list_type == 'insurance' && $insurance_provider_id) {
        $check_company_sql = "SELECT insurance_company_id FROM insurance_companies WHERE insurance_company_id = ?";
        $check_stmt = $mysqli->prepare($check_company_sql);
        $check_stmt->bind_param('i', $insurance_provider_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows == 0) {
            $error_message = "Error: Selected insurance provider does not exist.";
        }
    }
    
    // For insurance lists, provider is required
    if ($price_list_type == 'insurance' && empty($insurance_provider_id)) {
        $error_message = "Error: Insurance provider is required for insurance price lists.";
    }
    
    // Validate dates
    if ($valid_to && $valid_from > $valid_to) {
        $error_message = "Error: Valid from date cannot be after valid to date.";
    }
    
    // If no errors, proceed with insertion
    if (!isset($error_message)) {
        // Start transaction
        $mysqli->begin_transaction();
        
        try {
            // Insert new price list
            $sql = "INSERT INTO price_lists SET 
                    price_list_name = ?,
                    price_list_code = ?,
                    price_list_type = ?,
                    insurance_provider_id = ?,
                    revenue_account_id = ?,
                    accounts_receivable_account_id = ?,
                    is_default = ?,
                    is_active = ?,
                    valid_from = ?,
                    valid_to = ?,
                    currency = ?,
                    notes = ?,
                    created_by = ?,
                    created_at = NOW()";
            
            $stmt = $mysqli->prepare($sql);
            
            // Bind parameters
    $stmt->bind_param(
    'sssiiiiissssi',
    $price_list_name,
    $price_list_code,
    $price_list_type,
    $insurance_provider_id,
    $revenue_account_id,
    $accounts_receivable_account_id,
    $is_default,
    $is_active,
    $valid_from,
    $valid_to,
    $currency,
    $notes,
    $created_by
);

            
            if (!$stmt->execute()) {
                throw new Exception("Failed to create price list: " . $stmt->error);
            }
            
            $new_price_list_id = $mysqli->insert_id;
            
            // If this is set as default, update other price lists of same type
            if ($is_default) {
                $sql = "UPDATE price_lists 
                        SET is_default = 0 
                        WHERE price_list_id != ? 
                        AND price_list_type = ? 
                        AND is_default = 1";
                
                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param('is', $new_price_list_id, $price_list_type);
                $stmt->execute();
            }
            
            // Clone from existing price list if requested
            if (isset($_POST['clone_from']) && $_POST['clone_from'] == 1 && !empty($_POST['clone_source_id'])) {
                $clone_source_id = intval($_POST['clone_source_id']);
                
                // Clone price list items
                $clone_items_sql = "INSERT INTO price_list_items (price_list_id, billable_item_id, price, effective_from, effective_to, created_by, created_at)
                                    SELECT ?, billable_item_id, price, ?, ?, ?, NOW()
                                    FROM price_list_items 
                                    WHERE price_list_id = ? 
                                    AND (effective_to IS NULL OR effective_to >= CURDATE())";
                $stmt = $mysqli->prepare($clone_items_sql);
                $stmt->bind_param('isssi', $new_price_list_id, $valid_from, $valid_to, $created_by, $clone_source_id);
                $stmt->execute();
                
                // Record the clone
                $clone_record_sql = "INSERT INTO price_list_clones (source_price_list_id, target_price_list_id, cloned_by, cloned_at)
                                     VALUES (?, ?, ?, NOW())";
                $stmt = $mysqli->prepare($clone_record_sql);
                $stmt->bind_param('iii', $clone_source_id, $new_price_list_id, $created_by);
                $stmt->execute();
                
                $_SESSION['alert_message'] = "Price list created and cloned successfully!";
                
            } else {
                // Add selected billable items to the price list
                if (!empty($_POST['billable_item_ids'])) {
                    $billable_item_ids = array_map('intval', $_POST['billable_item_ids']);
                    $pricing_strategy = sanitizeInput($_POST['pricing_strategy'] ?? 'default');
                    $markup_value = floatval($_POST['markup_value'] ?? 0);
                    $fixed_price = floatval($_POST['fixed_price'] ?? 0);
                    
                    foreach ($billable_item_ids as $billable_item_id) {
                        // Get billable item base price
                        $item_sql = "SELECT unit_price FROM billable_items WHERE billable_item_id = ?";
                        $item_stmt = $mysqli->prepare($item_sql);
                        $item_stmt->bind_param('i', $billable_item_id);
                        $item_stmt->execute();
                        $item_result = $item_stmt->get_result();
                        $item = $item_result->fetch_assoc();
                        
                        if ($item) {
                            $base_price = floatval($item['unit_price']);
                            $calculated_price = calculatePriceBasedOnStrategy($base_price, $pricing_strategy, $markup_value, $fixed_price);
                            
                            // Insert price list item
                            $insert_item_sql = "INSERT INTO price_list_items (price_list_id, billable_item_id, price, effective_from, effective_to, created_by, created_at)
                                                VALUES (?, ?, ?, ?, ?, ?, NOW())";
                            $insert_stmt = $mysqli->prepare($insert_item_sql);
                            $insert_stmt->bind_param('iidssi', $new_price_list_id, $billable_item_id, $calculated_price, $valid_from, $valid_to, $created_by);
                            $insert_stmt->execute();
                        }
                    }
                }
                
                $_SESSION['alert_message'] = "Price list created successfully" . 
                                            (isset($billable_item_ids) ? " with " . count($billable_item_ids) . " billable items!" : "!");
            }
            
            $mysqli->commit();
            
            header("Location: price_list_view.php?id=$new_price_list_id");
            exit;
            
        } catch (Exception $e) {
            $mysqli->rollback();
            $error_message = "Error creating price list: " . $e->getMessage();
            error_log("Price list creation error: " . $e->getMessage());
        }
    }
}

// Function to calculate price based on strategy
function calculatePriceBasedOnStrategy($base_price, $strategy, $percentage, $fixed_price) {
    switch ($strategy) {
        case 'markup_percentage':
            return $base_price * (1 + ($percentage / 100));
        case 'discount_percentage':
            return $base_price * (1 - ($percentage / 100));
        case 'fixed_price':
            return $fixed_price;
        default:
            return $base_price;
    }
}

// Get insurance companies for dropdown
$companies_sql = "SELECT insurance_company_id, company_name, company_code 
                  FROM insurance_companies 
                  WHERE is_active = 1 
                  ORDER BY company_name";
$companies_result = $mysqli->query($companies_sql);

// Get billable items for selection
$available_items_sql = "SELECT bi.billable_item_id, bi.item_name, bi.item_code, bi.unit_price, bi.item_type
                        FROM billable_items bi 
                        WHERE bi.is_active = 1 
                        ORDER BY bi.item_type, bi.item_name 
                        LIMIT 200";
$available_items_result = $mysqli->query($available_items_sql);

// Get existing price lists for cloning
$price_lists_sql = "SELECT pl.*, ic.company_name 
                   FROM price_lists pl 
                   LEFT JOIN insurance_companies ic ON pl.insurance_provider_id = ic.insurance_company_id 
                   WHERE pl.is_active = 1 
                   ORDER BY pl.price_list_type, pl.price_list_name";
$price_lists_result = $mysqli->query($price_lists_sql);
?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-plus mr-2"></i>Create New Price List
        </h3>
        <div class="card-tools">
            <a href="price_management.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left mr-2"></i>Back to Price Management
            </a>
        </div>
    </div>
    
    <div class="card-body">
        <?php if (isset($error_message)): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error_message); ?>
        </div>
        <?php endif; ?>
        
        <form method="POST" id="priceListForm">
            <!-- Basic Information Section -->
            <div class="card mb-4">
                <div class="card-header bg-light">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-info-circle mr-2"></i>Basic Information
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Price List Name *</label>
                                <input type="text" class="form-control" name="price_list_name" required 
                                       placeholder="e.g., Standard Cash Prices, NHIF Outpatient, etc.">
                                <small class="form-text text-muted">A descriptive name for this price list</small>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Price List Code *</label>
                                <input type="text" class="form-control" name="price_list_code" required 
                                       placeholder="e.g., CASH-STD, NHIF-OPD">
                                <small class="form-text text-muted">Unique code for this price list</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Price List Type *</label>
                                <select class="form-control" name="price_list_type" id="priceListType" required>
                                    <option value="">Select Price List Type</option>
                                    <option value="cash">Cash</option>
                                    <option value="insurance">Insurance</option>
                                    <option value="corporate">Corporate</option>
                                    <option value="government">Government</option>
                                    <option value="staff">Staff</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="col-md-6" id="insuranceProviderField" style="display: none;">
                            <div class="form-group">
                             <label>Insurance Provider <span class="text-danger">*</span></label>
                                <select class="form-control" name="insurance_provider_id" id="insuranceProvider" required>
                                    <option value="">Select Insurance Provider</option>

                                    <?php
                                    // Fetch active insurance companies
                                    $companies_sql = "
                                        SELECT insurance_company_id, company_name, company_code
                                        FROM insurance_companies
                                        WHERE is_active = 1
                                        ORDER BY company_name
                                    ";

                                    $companies_result = $mysqli->query($companies_sql);

                                    while ($company = $companies_result->fetch_assoc()):
                                    ?>
                                        <option value="<?php echo (int)$company['insurance_company_id']; ?>">
                                            <?php
                                            echo htmlspecialchars(
                                                $company['company_name'] .
                                                (!empty($company['company_code']) ? " (" . $company['company_code'] . ")" : "")
                                            );
                                            ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>

                                <small class="form-text text-muted">Required for insurance price lists</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Valid From *</label>
                                <input type="date" class="form-control" name="valid_from" required 
                                       value="<?php echo date('Y-m-d'); ?>">
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
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Currency</label>
                                <select class="form-control" name="currency">
                                    <option value="USD">USD - US Dollar</option>
                                    <option value="KES">KES - Kenyan Shilling</option>
                                    <option value="EUR">EUR - Euro</option>
                                    <option value="GBP">GBP - British Pound</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Revenue Account (Optional)</label>
                                <select class="form-control" name="revenue_account_id">
                                    <option value="">Select Revenue Account</option>
                               <?php
                                    // Get revenue accounts from accounts table
                                    $accounts_sql = "
                                        SELECT account_id, account_name, account_number 
                                        FROM accounts 
                                        WHERE account_type = 'revenue'
                                        AND is_active = 1
                                        ORDER BY account_number
                                    ";

                                    $accounts_result = $mysqli->query($accounts_sql);

                                    while ($account = $accounts_result->fetch_assoc()):
                                    ?>
                                        <option value="<?php echo (int)$account['account_id']; ?>">
                                            <?php
                                            echo htmlspecialchars(
                                                $account['account_name'] . " (" . $account['account_number'] . ")"
                                            );
                                            ?>
                                        </option>
                                    <?php endwhile; ?>

                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input" id="isDefault" name="is_default" value="1">
                                    <label class="custom-control-label" for="isDefault">Set as Default Price List</label>
                                </div>
                                <small class="form-text text-muted">This will be used as the default price list for this type</small>
                            </div>
                        </div>
                        
                        <div class="col-md-4">
                            <div class="form-group">
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input" id="isActive" name="is_active" value="1" checked>
                                    <label class="custom-control-label" for="isActive">Active</label>
                                </div>
                                <small class="form-text text-muted">Inactive price lists won't be available for use</small>
                            </div>
                        </div>
                        
                        <div class="col-md-4">
                            <div class="form-group">
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input" id="cloneFrom" name="clone_from" value="1">
                                    <label class="custom-control-label" for="cloneFrom">Clone from existing price list</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row" id="cloneFields" style="display: none;">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label>Select Price List to Clone</label>
                                <select class="form-control" name="clone_source_id" id="cloneSource">
                                    <option value="">Select price list to clone</option>
                                    <?php while($pl = $price_lists_result->fetch_assoc()): ?>
                                        <option value="<?php echo $pl['price_list_id']; ?>" data-type="<?php echo $pl['price_list_type']; ?>">
                                            <?php echo htmlspecialchars($pl['price_list_name'] . " - " . ucfirst($pl['price_list_type']) . ($pl['company_name'] ? " (" . $pl['company_name'] . ")" : "")); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                                <small class="form-text text-muted">This will copy all price list items from the selected list</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label>Notes/Description</label>
                                <textarea class="form-control" name="notes" rows="3" placeholder="Any additional notes about this price list..."></textarea>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Add Billable Items Section -->
            <div class="card mb-4" id="addItemsSection">
                <div class="card-header bg-light">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-cart-plus mr-2"></i>Add Billable Items (Optional)
                    </h5>
                    <small class="text-muted">You can add billable items now or later</small>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Pricing Strategy</label>
                                <select class="form-control" name="pricing_strategy" id="pricingStrategy" required>
                                    <option value="default">Default Price</option>
                                    <option value="markup_percentage">Markup Percentage</option>
                                    <option value="discount_percentage">Discount Percentage</option>
                                    <option value="fixed_price">Fixed Price</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="col-md-4" id="percentageField" style="display: none;">
                            <div class="form-group">
                                <label id="percentageLabel">Markup Percentage</label>
                                <div class="input-group">
                                    <input type="number" class="form-control" name="markup_value" id="markupValue" value="20" step="0.01" min="0" max="1000">
                                    <div class="input-group-append">
                                        <span class="input-group-text">%</span>
                                    </div>
                                </div>
                                <small class="form-text text-muted" id="percentageHelp">Percentage to add to base price</small>
                            </div>
                        </div>
                        
                        <div class="col-md-4" id="fixedPriceField" style="display: none;">
                            <div class="form-group">
                                <label>Fixed Price</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text">$</span>
                                    </div>
                                    <input type="number" class="form-control" name="fixed_price" id="fixedPrice" value="0" step="0.01" min="0">
                                </div>
                                <small class="form-text text-muted">Fixed price for all selected items</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-12">
                            <div class="card">
                                <div class="card-header">
                                    <h6 class="card-title mb-0">
                                        <i class="fas fa-boxes mr-2"></i>Select Billable Items
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="form-group">
                                        <label>Select Billable Items</label>
                                        <select name="billable_item_ids[]" class="form-control select2" multiple style="width: 100%;" id="billableItemSelect">
                                            <option value="">Search billable items...</option>
                                            <?php while($item = $available_items_result->fetch_assoc()): ?>
                                                <option value="<?php echo $item['billable_item_id']; ?>" data-price="<?php echo $item['unit_price']; ?>" data-type="<?php echo $item['item_type']; ?>">
                                                    <?php echo htmlspecialchars($item['item_name'] . " (" . $item['item_code'] . ") - $" . number_format($item['unit_price'], 2) . " [" . ucfirst($item['item_type']) . "]"); ?>
                                                </option>
                                            <?php endwhile; ?>
                                        </select>
                                        <small class="form-text text-muted">Select multiple billable items to add to this price list</small>
                                    </div>
                                    <div id="selectedItemsPreview" class="mt-2" style="display: none;">
                                        <h6>Selected Items Preview:</h6>
                                        <div id="selectedItemsList" class="small text-muted"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-md-12">
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle mr-2"></i>
                                <strong>Note:</strong> You can skip adding billable items now and add them later from the price list view page.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Summary Section -->
            <div class="card mb-4">
                <div class="card-header bg-light">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-clipboard-check mr-2"></i>Summary
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label>Selected Billable Items Count</label>
                                <div class="h4" id="itemsCount">0</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Action Buttons -->
            <div class="row mt-4">
                <div class="col-md-12">
                    <button type="submit" class="btn btn-success btn-lg">
                        <i class="fas fa-save mr-2"></i>Create Price List
                    </button>
                    <a href="price_management.php" class="btn btn-secondary ml-2">
                        <i class="fas fa-times mr-2"></i>Cancel
                    </a>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize select2
    $('.select2').select2({
        theme: 'bootstrap4',
        width: '100%',
        placeholder: 'Search...'
    });
    
    // Show/hide insurance provider field based on price list type
    $('#priceListType').change(function() {
        if ($(this).val() === 'insurance') {
            $('#insuranceProviderField').slideDown();
            $('#insuranceProvider').prop('required', true);
        } else {
            $('#insuranceProviderField').slideUp();
            $('#insuranceProvider').prop('required', false);
            $('#insuranceProvider').val('');
        }
    });
    
    // Show/hide clone fields
    $('#cloneFrom').change(function() {
        if ($(this).is(':checked')) {
            $('#cloneFields').slideDown();
            $('#addItemsSection').slideUp();
        } else {
            $('#cloneFields').slideUp();
            $('#addItemsSection').slideDown();
        }
    });
    
    // Show/hide pricing fields based on strategy
    $('#pricingStrategy').change(function() {
        var strategy = $(this).val();
        
        if (strategy === 'fixed_price') {
            $('#percentageField').slideUp();
            $('#fixedPriceField').slideDown();
        } else if (strategy === 'markup_percentage' || strategy === 'discount_percentage') {
            $('#percentageField').slideDown();
            $('#fixedPriceField').slideUp();
            
            if (strategy === 'markup_percentage') {
                $('#percentageLabel').text('Markup Percentage');
                $('#percentageHelp').text('Percentage to add to base price');
            } else {
                $('#percentageLabel').text('Discount Percentage');
                $('#percentageHelp').text('Percentage to deduct from base price');
            }
        } else {
            $('#percentageField').slideUp();
            $('#fixedPriceField').slideUp();
        }
        
        updateSelectedItemsPreview();
    });
    
    // Update selected items preview
    function updateSelectedItemsPreview() {
        var strategy = $('#pricingStrategy').val();
        var markup = parseFloat($('#markupValue').val()) || 0;
        var fixedPrice = parseFloat($('#fixedPrice').val()) || 0;
        
        // Update items preview
        var items = $('#billableItemSelect').select2('data');
        if (items.length > 0) {
            var itemsHtml = '<ul>';
            $.each(items, function(index, item) {
                var basePrice = parseFloat(item.element.dataset.price);
                var itemType = item.element.dataset.type;
                var calculatedPrice = calculatePrice(basePrice, strategy, markup, fixedPrice);
                itemsHtml += '<li>' + item.text.split(' - $')[0] + 
                           ': $' + basePrice.toFixed(2) + ' → $' + calculatedPrice.toFixed(2) + 
                           ' <span class="badge badge-secondary">' + itemType + '</span></li>';
            });
            itemsHtml += '</ul>';
            $('#selectedItemsList').html(itemsHtml);
            $('#selectedItemsPreview').show();
        } else {
            $('#selectedItemsPreview').hide();
        }
        
        // Update count
        $('#itemsCount').text(items.length);
    }
    
    // Calculate price based on strategy
    function calculatePrice(basePrice, strategy, percentage, fixedPrice) {
        switch (strategy) {
            case 'markup_percentage':
                return basePrice * (1 + (percentage / 100));
            case 'discount_percentage':
                return basePrice * (1 - (percentage / 100));
            case 'fixed_price':
                return fixedPrice;
            default:
                return basePrice;
        }
    }
    
    // Update preview when selection changes
    $('#billableItemSelect').on('change', updateSelectedItemsPreview);
    $('#markupValue, #fixedPrice').on('input', updateSelectedItemsPreview);
    
    // Filter clone source by selected price list type
    $('#priceListType, #cloneSource').change(function() {
        var priceListType = $('#priceListType').val();
        var cloneSource = $('#cloneSource');
        
        if (priceListType && $('#cloneFrom').is(':checked')) {
            cloneSource.find('option').each(function() {
                var optionType = $(this).data('type');
                if (!optionType || optionType === priceListType) {
                    $(this).show();
                } else {
                    $(this).hide();
                }
            });
            
            // Reset to first option if current selection is hidden
            if (cloneSource.find('option:selected').is(':hidden')) {
                cloneSource.val('');
            }
        }
    });
    
    // Form validation
    $('#priceListForm').submit(function(e) {
        var priceListType = $('#priceListType').val();
        var insuranceProvider = $('#insuranceProvider').val();
        var cloneFrom = $('#cloneFrom').is(':checked');
        var cloneSource = $('#cloneSource').val();
        var pricingStrategy = $('#pricingStrategy').val();
        var markupValue = parseFloat($('#markupValue').val());
        var fixedPrice = parseFloat($('#fixedPrice').val());
        
        if (!priceListType) {
            alert('Please select a price list type');
            e.preventDefault();
            return false;
        }
        
        if (priceListType === 'insurance' && !insuranceProvider) {
            alert('Please select an insurance provider for insurance price lists');
            e.preventDefault();
            return false;
        }
        
        if (cloneFrom && !cloneSource) {
            alert('Please select a price list to clone from');
            e.preventDefault();
            return false;
        }
        
        // Validate pricing strategy
        if (!cloneFrom) {
            if (pricingStrategy === 'markup_percentage' || pricingStrategy === 'discount_percentage') {
                if (isNaN(markupValue) || markupValue <= 0) {
                    alert('Please enter a valid percentage value greater than 0');
                    e.preventDefault();
                    return false;
                }
            }
            
            if (pricingStrategy === 'fixed_price') {
                if (isNaN(fixedPrice) || fixedPrice <= 0) {
                    alert('Please enter a valid fixed price greater than 0');
                    e.preventDefault();
                    return false;
                }
            }
        }
        
        // Show confirmation for large number of items
        var itemsCount = $('#itemsCount').text();
        if (itemsCount > 50 && !cloneFrom) {
            if (!confirm('You are about to add ' + itemsCount + ' billable items. This may take a moment. Continue?')) {
                e.preventDefault();
                return false;
            }
        }
    });
    
    // Initialize preview
    updateSelectedItemsPreview();
});
</script>

<style>
.custom-control-input:checked ~ .custom-control-label::before {
    background-color: #28a745;
    border-color: #28a745;
}

.select2-container--bootstrap4 .select2-selection {
    height: auto;
    min-height: calc(2.25rem + 2px);
}

.card .card-header {
    background-color: #f8f9fa;
    border-bottom: 1px solid #dee2e6;
}

#selectedItemsList ul {
    padding-left: 20px;
    margin-bottom: 0;
}

#selectedItemsList li {
    margin-bottom: 5px;
}

.alert {
    margin-bottom: 1rem;
}

.btn-lg {
    padding: 0.75rem 1.5rem;
    font-size: 1.1rem;
}
</style>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
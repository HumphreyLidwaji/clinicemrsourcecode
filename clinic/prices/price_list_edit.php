<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/price_functions.php';

// Get price list ID
$price_list_id = intval($_GET['id'] ?? 0);

// Fetch price list details
$price_list_sql = "SELECT pl.*, ic.company_name, ic.company_code, ic.insurance_company_id,
                   creator.user_name as created_by_name,
                   updater.user_name as updated_by_name
                   FROM price_lists pl
                   LEFT JOIN insurance_companies ic ON pl.insurance_company_id = ic.insurance_company_id
                   LEFT JOIN users creator ON pl.created_by = creator.user_id
                   LEFT JOIN users updater ON pl.updated_by = updater.user_id
                   WHERE pl.price_list_id = ?";
$stmt = $mysqli->prepare($price_list_sql);
$stmt->bind_param('i', $price_list_id);
$stmt->execute();
$price_list_result = $stmt->get_result();
$price_list = $price_list_result->fetch_assoc();

if (!$price_list) {
    $_SESSION['alert_message'] = "Price list not found";
    header("Location: price_management.php");
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $list_name = sanitizeInput($_POST['list_name']);
    $payer_type = sanitizeInput($_POST['payer_type']);
    $insurance_company_id = ($_POST['insurance_company_id'] ?? '') !== '' ? intval($_POST['insurance_company_id']) : NULL;
    $insurance_scheme_id = ($_POST['insurance_scheme_id'] ?? '') !== '' ? intval($_POST['insurance_scheme_id']) : NULL;
    $is_default = isset($_POST['is_default']) ? 1 : 0;
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $notes = sanitizeInput($_POST['notes'] ?? '');
    $updated_by = intval($_SESSION['user_id']);
    
    // Debug log
    error_log("Updating price list $price_list_id - payer_type: $payer_type, insurance_company_id: " . ($insurance_company_id ?? 'NULL'));
    
    // For cash price lists, insurance_company_id must be NULL
    if ($payer_type == 'CASH') {
        $insurance_company_id = NULL;
        $insurance_scheme_id = NULL;
    }
    
    // Validate insurance company exists if provided (for insurance lists)
    if ($payer_type == 'INSURANCE' && $insurance_company_id) {
        $check_company_sql = "SELECT insurance_company_id FROM insurance_companies WHERE insurance_company_id = ?";
        $check_stmt = $mysqli->prepare($check_company_sql);
        $check_stmt->bind_param('i', $insurance_company_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows == 0) {
            $error_message = "Error: Selected insurance company does not exist.";
        }
    }
    
    // Validate insurance scheme exists if provided (for insurance lists)
    if ($payer_type == 'INSURANCE' && $insurance_scheme_id) {
        $check_scheme_sql = "SELECT scheme_id FROM insurance_schemes WHERE scheme_id = ?";
        $check_stmt = $mysqli->prepare($check_scheme_sql);
        $check_stmt->bind_param('i', $insurance_scheme_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows == 0) {
            $error_message = "Error: Selected insurance scheme does not exist.";
        }
    }
    
    // For insurance lists, company is required
    if ($payer_type == 'INSURANCE' && empty($insurance_company_id)) {
        $error_message = "Error: Insurance company is required for insurance price lists.";
    }
    
    // If no errors, proceed with update
    if (!isset($error_message)) {
        // Start transaction
        $mysqli->begin_transaction();
        
        try {
            // Update price list
            $sql = "UPDATE price_lists SET 
                    list_name = ?,
                    payer_type = ?,
                    insurance_company_id = ?,
                    insurance_scheme_id = ?,
                    is_default = ?,
                    is_active = ?,
                    notes = ?,
                    updated_by = ?,
                    updated_at = NOW()
                    WHERE price_list_id = ?";
            
            $stmt = $mysqli->prepare($sql);
            
            // Bind parameters
            $stmt->bind_param(
                'ssiiiisii',
                $list_name,
                $payer_type,
                $insurance_company_id,
                $insurance_scheme_id,
                $is_default,
                $is_active,
                $notes,
                $updated_by,
                $price_list_id
            );
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to update price list: " . $stmt->error);
            }
            
            // If this is set as default, update other price lists of same type
            if ($is_default) {
                $sql = "UPDATE price_lists 
                        SET is_default = 0 
                        WHERE price_list_id != ? 
                        AND payer_type = ? 
                        AND is_default = 1";
                
                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param('is', $price_list_id, $payer_type);
                $stmt->execute();
            }
            
            $mysqli->commit();
            
            $_SESSION['alert_message'] = "Price list updated successfully!";
            header("Location: price_list_view.php?id=$price_list_id");
            exit;
            
        } catch (Exception $e) {
            $mysqli->rollback();
            $error_message = "Error updating price list: " . $e->getMessage();
            error_log("Price list update error: " . $e->getMessage());
        }
    }
}

// Get insurance companies for dropdown
$companies_sql = "SELECT insurance_company_id, company_name, company_code 
                  FROM insurance_companies 
                  WHERE is_active = 1 
                  ORDER BY company_name";
$companies_result = $mysqli->query($companies_sql);

// Get all insurance schemes grouped by company
$schemes_sql = "SELECT scheme_id, scheme_name, insurance_company_id 
                FROM insurance_schemes 
                WHERE is_active = 1 
                ORDER BY scheme_name";
$schemes_result = $mysqli->query($schemes_sql);
$schemes_by_company = [];
while($scheme = $schemes_result->fetch_assoc()) {
    $schemes_by_company[$scheme['insurance_company_id']][] = $scheme;
}

// Get current scheme if exists
$current_scheme = null;
if ($price_list['insurance_scheme_id']) {
    $scheme_sql = "SELECT scheme_id, scheme_name, insurance_company_id 
                   FROM insurance_schemes 
                   WHERE scheme_id = ?";
    $stmt = $mysqli->prepare($scheme_sql);
    $stmt->bind_param('i', $price_list['insurance_scheme_id']);
    $stmt->execute();
    $scheme_result = $stmt->get_result();
    $current_scheme = $scheme_result->fetch_assoc();
}

// Get statistics for this price list
$stats_sql = "SELECT 
              COUNT(DISTINCT ip.item_price_id) as item_count,
              COUNT(DISTINCT msp.service_price_id) as service_count,
              COUNT(DISTINCT ph.history_id) as price_changes,
              MAX(ph.changed_at) as last_price_change
              FROM price_lists pl
              LEFT JOIN item_prices ip ON pl.price_list_id = ip.price_list_id AND ip.is_active = 1
              LEFT JOIN medical_service_prices msp ON pl.price_list_id = msp.price_list_id AND msp.is_active = 1
              LEFT JOIN price_history ph ON pl.price_list_id = ph.price_list_id
              WHERE pl.price_list_id = ?";
$stmt = $mysqli->prepare($stats_sql);
$stmt->bind_param('i', $price_list_id);
$stmt->execute();
$stats_result = $stmt->get_result();
$stats = $stats_result->fetch_assoc();
?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h3 class="card-title mt-2 mb-0 text-white">
                    <i class="fas fa-edit mr-2"></i>Edit Price List
                </h3>
                <small class="text-light">
                    <?php echo htmlspecialchars($price_list['list_name']); ?>
                </small>
            </div>
            <div class="card-tools">
                <a href="price_list_view.php?id=<?php echo $price_list_id; ?>" class="btn btn-secondary">
                    <i class="fas fa-eye mr-2"></i>View
                </a>
                <a href="price_management.php" class="btn btn-secondary ml-2">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Lists
                </a>
            </div>
        </div>
    </div>
    
    <div class="card-body">
        <?php if (isset($error_message)): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error_message); ?>
        </div>
        <?php endif; ?>
        
        <!-- Statistics Row -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card bg-primary text-white">
                    <div class="card-body py-2 text-center">
                        <h4 class="font-weight-bold mb-0"><?php echo $stats['item_count'] ?? 0; ?></h4>
                        <small>Items</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-success text-white">
                    <div class="card-body py-2 text-center">
                        <h4 class="font-weight-bold mb-0"><?php echo $stats['service_count'] ?? 0; ?></h4>
                        <small>Services</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-info text-white">
                    <div class="card-body py-2 text-center">
                        <h4 class="font-weight-bold mb-0"><?php echo $stats['price_changes'] ?? 0; ?></h4>
                        <small>Price Changes</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-warning text-white">
                    <div class="card-body py-2 text-center">
                        <h6 class="font-weight-bold mb-0">Created</h6>
                        <small><?php echo date('M j, Y', strtotime($price_list['created_at'])); ?></small>
                        <?php if($price_list['created_by_name']): ?>
                            <div class="small">by <?php echo htmlspecialchars($price_list['created_by_name']); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
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
                                <input type="text" class="form-control" name="list_name" required 
                                       value="<?php echo htmlspecialchars($price_list['list_name']); ?>"
                                       placeholder="e.g., Standard Cash Prices, NHIF Outpatient, etc.">
                                <small class="form-text text-muted">A descriptive name for this price list</small>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Payer Type *</label>
                                <select class="form-control" name="payer_type" id="payerType" required>
                                    <option value="">Select Payer Type</option>
                                    <option value="CASH" <?php echo $price_list['payer_type'] == 'CASH' ? 'selected' : ''; ?>>Cash</option>
                                    <option value="INSURANCE" <?php echo $price_list['payer_type'] == 'INSURANCE' ? 'selected' : ''; ?>>Insurance</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row" id="insuranceFields" style="<?php echo $price_list['payer_type'] == 'INSURANCE' ? '' : 'display: none;'; ?>">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Insurance Company <?php echo $price_list['payer_type'] == 'INSURANCE' ? '*' : ''; ?></label>
                                <select class="form-control" name="insurance_company_id" id="insuranceCompany" <?php echo $price_list['payer_type'] == 'INSURANCE' ? 'required' : ''; ?>>
                                    <option value="">Select Insurance Company</option>
                                    <?php 
                                    $companies_result->data_seek(0); // Reset pointer
                                    while($company = $companies_result->fetch_assoc()): ?>
                                    <option value="<?php echo $company['insurance_company_id']; ?>"
                                        <?php echo ($price_list['insurance_company_id'] == $company['insurance_company_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($company['company_name'] . " (" . $company['company_code'] . ")"); ?>
                                    </option>
                                    <?php endwhile; ?>
                                </select>
                                <small class="form-text text-muted">Required for insurance price lists</small>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Insurance Scheme (Optional)</label>
                                <select class="form-control" name="insurance_scheme_id" id="insuranceScheme">
                                    <option value="">Select Scheme (Optional)</option>
                                    <?php 
                                    // Store schemes data in a JavaScript variable
                                    $schemes_json = json_encode($schemes_by_company);
                                    
                                    // If there's a current scheme, add it to the options
                                    if ($current_scheme): ?>
                                    <option value="<?php echo $current_scheme['scheme_id']; ?>" selected>
                                        <?php echo htmlspecialchars($current_scheme['scheme_name']); ?>
                                    </option>
                                    <?php endif; ?>
                                </select>
                                <small class="form-text text-muted">Optional - specific scheme for this price list</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input" id="isDefault" name="is_default" value="1"
                                        <?php echo $price_list['is_default'] ? 'checked' : ''; ?>>
                                    <label class="custom-control-label" for="isDefault">Set as Default Price List</label>
                                </div>
                                <small class="form-text text-muted">This will be used as the default price list for this payer type</small>
                            </div>
                        </div>
                        
                        <div class="col-md-4">
                            <div class="form-group">
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input" id="isActive" name="is_active" value="1"
                                        <?php echo $price_list['is_active'] ? 'checked' : ''; ?>>
                                    <label class="custom-control-label" for="isActive">Active</label>
                                </div>
                                <small class="form-text text-muted">Inactive price lists won't be available for use</small>
                            </div>
                        </div>
                        
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Status</label>
                                <div class="form-control-plaintext">
                                    <span class="badge badge-<?php echo $price_list['is_active'] ? 'success' : 'secondary'; ?>">
                                        <?php echo $price_list['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                    <?php if($price_list['is_default']): ?>
                                        <span class="badge badge-warning ml-2">Default</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label>Notes/Description</label>
                                <textarea class="form-control" name="notes" rows="3" 
                                          placeholder="Any additional notes about this price list..."><?php echo htmlspecialchars($price_list['notes'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Quick Actions Card -->
            <div class="card mb-4">
                <div class="card-header bg-light">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-bolt mr-2"></i>Quick Actions
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3 mb-2">
                            <a href="price_list_items.php?id=<?php echo $price_list_id; ?>" class="btn btn-outline-primary btn-block">
                                <i class="fas fa-list mr-2"></i>View Items
                            </a>
                        </div>
                        <div class="col-md-3 mb-2">
                            <a href="bulk_price_update.php?price_list_id=<?php echo $price_list_id; ?>" class="btn btn-outline-info btn-block">
                                <i class="fas fa-sync-alt mr-2"></i>Bulk Update
                            </a>
                        </div>
                        <div class="col-md-3 mb-2">
                            <button type="button" class="btn btn-outline-success btn-block" data-toggle="modal" data-target="#cloneModal">
                                <i class="fas fa-copy mr-2"></i>Clone List
                            </button>
                        </div>
                        <div class="col-md-3 mb-2">
                            <a href="price_history.php?price_list_id=<?php echo $price_list_id; ?>" class="btn btn-outline-dark btn-block">
                                <i class="fas fa-history mr-2"></i>View History
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Items Summary Card -->
            <div class="card mb-4">
                <div class="card-header bg-light">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-chart-bar mr-2"></i>Price Statistics
                    </h5>
                </div>
                <div class="card-body">
                    <?php
                    // Get price statistics
                    $price_stats_sql = "SELECT 
                                       AVG(ip.price) as avg_item_price,
                                       AVG(msp.price) as avg_service_price,
                                       MIN(ip.price) as min_item_price,
                                       MAX(ip.price) as max_item_price,
                                       MIN(msp.price) as min_service_price,
                                       MAX(msp.price) as max_service_price,
                                       SUM(ip.price) as total_items_value,
                                       SUM(msp.price) as total_services_value
                                       FROM price_lists pl
                                       LEFT JOIN item_prices ip ON pl.price_list_id = ip.price_list_id AND ip.is_active = 1
                                       LEFT JOIN medical_service_prices msp ON pl.price_list_id = msp.price_list_id AND msp.is_active = 1
                                       WHERE pl.price_list_id = ?";
                    $stmt = $mysqli->prepare($price_stats_sql);
                    $stmt->bind_param('i', $price_list_id);
                    $stmt->execute();
                    $price_stats_result = $stmt->get_result();
                    $price_stats = $price_stats_result->fetch_assoc();
                    ?>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Items Pricing</h6>
                            <table class="table table-sm">
                                <tr>
                                    <td>Average Price:</td>
                                    <td class="text-right font-weight-bold">$<?php echo number_format($price_stats['avg_item_price'] ?? 0, 2); ?></td>
                                </tr>
                                <tr>
                                    <td>Range:</td>
                                    <td class="text-right">
                                        $<?php echo number_format($price_stats['min_item_price'] ?? 0, 2); ?> - 
                                        $<?php echo number_format($price_stats['max_item_price'] ?? 0, 2); ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td>Total Value:</td>
                                    <td class="text-right font-weight-bold text-primary">
                                        $<?php echo number_format($price_stats['total_items_value'] ?? 0, 2); ?>
                                    </td>
                                </tr>
                            </table>
                        </div>
                        
                        <div class="col-md-6">
                            <h6>Services Pricing</h6>
                            <table class="table table-sm">
                                <tr>
                                    <td>Average Price:</td>
                                    <td class="text-right font-weight-bold">$<?php echo number_format($price_stats['avg_service_price'] ?? 0, 2); ?></td>
                                </tr>
                                <tr>
                                    <td>Range:</td>
                                    <td class="text-right">
                                        $<?php echo number_format($price_stats['min_service_price'] ?? 0, 2); ?> - 
                                        $<?php echo number_format($price_stats['max_service_price'] ?? 0, 2); ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td>Total Value:</td>
                                    <td class="text-right font-weight-bold text-primary">
                                        $<?php echo number_format($price_stats['total_services_value'] ?? 0, 2); ?>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    
                    <?php if($stats['last_price_change']): ?>
                    <div class="row mt-3">
                        <div class="col-md-12">
                            <div class="alert alert-info">
                                <i class="fas fa-history mr-2"></i>
                                <strong>Last Price Change:</strong> <?php echo date('F j, Y, g:i a', strtotime($stats['last_price_change'])); ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Action Buttons -->
            <div class="row mt-4">
                <div class="col-md-12">
                    <button type="submit" class="btn btn-success btn-lg">
                        <i class="fas fa-save mr-2"></i>Save Changes
                    </button>
                    <a href="price_list_view.php?id=<?php echo $price_list_id; ?>" class="btn btn-secondary ml-2">
                        <i class="fas fa-times mr-2"></i>Cancel
                    </a>
                    
                    <?php if(($stats['item_count'] + $stats['service_count']) == 0): ?>
                    <button type="button" class="btn btn-danger ml-2 float-right" data-toggle="modal" data-target="#deleteModal">
                        <i class="fas fa-trash mr-2"></i>Delete Price List
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Clone Modal -->
<div class="modal fade" id="cloneModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-copy mr-2"></i>Clone Price List
                </h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form action="price_management.php" method="POST" id="cloneForm">
                <input type="hidden" name="action" value="clone_price_list">
                <input type="hidden" name="source_price_list_id" value="<?php echo $price_list_id; ?>">
                <div class="modal-body">
                    <div class="form-group">
                        <label>New Price List Name</label>
                        <input type="text" name="new_list_name" class="form-control" required 
                               value="<?php echo htmlspecialchars($price_list['list_name'] . ' - Copy'); ?>"
                               placeholder="Enter new price list name">
                    </div>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle mr-2"></i>
                        This will copy all items and services from this price list to a new one.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Clone Price List</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-danger">
                    <i class="fas fa-exclamation-triangle mr-2"></i>Confirm Deletion
                </h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete this price list?</p>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-circle mr-2"></i>
                    <strong>Warning:</strong> This action cannot be undone. The price list will be permanently removed.
                </div>
                <p><strong>Price List:</strong> <?php echo htmlspecialchars($price_list['list_name']); ?></p>
                <p><strong>Type:</strong> <?php echo $price_list['payer_type']; ?></p>
                <?php if($price_list['company_name']): ?>
                    <p><strong>Insurance Company:</strong> <?php echo htmlspecialchars($price_list['company_name']); ?></p>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <a href="price_management.php?action=delete&id=<?php echo $price_list_id; ?>" 
                   class="btn btn-danger" onclick="return confirm('Are you absolutely sure you want to delete this price list?')">
                    <i class="fas fa-trash mr-2"></i>Delete Permanently
                </a>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Store schemes data from PHP
    var schemesByCompany = <?php echo $schemes_json; ?>;
    
    // Show/hide insurance fields based on payer type
    $('#payerType').change(function() {
        if ($(this).val() === 'INSURANCE') {
            $('#insuranceFields').slideDown();
            $('#insuranceCompany').prop('required', true);
            updateSchemesDropdown();
        } else {
            $('#insuranceFields').slideUp();
            $('#insuranceCompany').prop('required', false);
            $('#insuranceCompany').val('');
            $('#insuranceScheme').empty().append('<option value="">Select Scheme (Optional)</option>');
        }
    });
    
    // Update schemes dropdown when company changes
    $('#insuranceCompany').change(function() {
        updateSchemesDropdown();
    });
    
    // Function to update schemes dropdown
    function updateSchemesDropdown() {
        var companyId = $('#insuranceCompany').val();
        var schemeSelect = $('#insuranceScheme');
        
        // Clear existing options except the first one
        schemeSelect.empty();
        schemeSelect.append('<option value="">Select Scheme (Optional)</option>');
        
        if (companyId && schemesByCompany[companyId]) {
            // Add schemes for selected company
            $.each(schemesByCompany[companyId], function(index, scheme) {
                schemeSelect.append('<option value="' + scheme.scheme_id + '">' + 
                                    scheme.scheme_name + '</option>');
            });
        }
        
        // Select current scheme if exists
        var currentSchemeId = '<?php echo $price_list['insurance_scheme_id'] ?? 0; ?>';
        if (currentSchemeId && currentSchemeId > 0) {
            schemeSelect.val(currentSchemeId);
        }
    }
    
    // Initialize schemes dropdown if insurance is selected
    <?php if($price_list['payer_type'] == 'INSURANCE' && $price_list['insurance_company_id']): ?>
    updateSchemesDropdown();
    <?php endif; ?>
    
    // Clone form submission
    $('#cloneForm').submit(function(e) {
        e.preventDefault();
        
        $.ajax({
            url: 'price_management.php',
            method: 'POST',
            data: $(this).serialize(),
            success: function(response) {
                const result = JSON.parse(response);
                if (result.success) {
                    alert('Price list cloned successfully! New list ID: ' + result.new_list_id);
                    $('#cloneModal').modal('hide');
                    window.location.href = 'price_list_view.php?id=' + result.new_list_id;
                } else {
                    alert('Error: ' + result.message);
                }
            },
            error: function() {
                alert('Error cloning price list');
            }
        });
    });
    
    // Form validation
    $('#priceListForm').submit(function(e) {
        var payerType = $('#payerType').val();
        var insuranceCompany = $('#insuranceCompany').val();
        
        if (!payerType) {
            alert('Please select a payer type');
            e.preventDefault();
            return false;
        }
        
        if (payerType === 'INSURANCE' && !insuranceCompany) {
            alert('Please select an insurance company for insurance price lists');
            e.preventDefault();
            return false;
        }
        
        // Additional validation for insurance lists
        if (payerType === 'INSURANCE') {
            var companyId = $('#insuranceCompany').val();
            if (!companyId || companyId === '') {
                alert('Insurance company is required for insurance price lists');
                e.preventDefault();
                return false;
            }
        }
        
        // Optional: Validate scheme belongs to selected company
        if (payerType === 'INSURANCE' && insuranceCompany) {
            var schemeId = $('#insuranceScheme').val();
            if (schemeId) {
                var isValidScheme = false;
                if (schemesByCompany[insuranceCompany]) {
                    $.each(schemesByCompany[insuranceCompany], function(index, scheme) {
                        if (scheme.scheme_id == schemeId) {
                            isValidScheme = true;
                            return false; // break loop
                        }
                    });
                }
                
                if (!isValidScheme) {
                    alert('Selected scheme does not belong to the selected insurance company');
                    e.preventDefault();
                    return false;
                }
            }
        }
        
        // Warn about changing payer type if there are items
        var itemCount = <?php echo $stats['item_count'] ?? 0; ?>;
        var serviceCount = <?php echo $stats['service_count'] ?? 0; ?>;
        var originalPayerType = '<?php echo $price_list['payer_type']; ?>';
        
        if ((itemCount > 0 || serviceCount > 0) && payerType !== originalPayerType) {
            if (!confirm('Warning: You are changing the payer type from "' + originalPayerType + '" to "' + payerType + '".\n\n' +
                        'This price list has ' + (itemCount + serviceCount) + ' items/services. Changing payer type may affect pricing.\n\n' +
                        'Are you sure you want to continue?')) {
                e.preventDefault();
                return false;
            }
        }
        
        // Warn about deactivating default list
        if (!$(this).find('#isActive').is(':checked') && <?php echo $price_list['is_default'] ? 'true' : 'false'; ?>) {
            if (!confirm('Warning: You are deactivating the default price list for ' + payerType + '.\n\n' +
                        'This may affect pricing calculations. Are you sure you want to continue?')) {
                e.preventDefault();
                return false;
            }
        }
    });
    
    // Keyboard shortcuts
    $(document).keydown(function(e) {
        // Ctrl + S to save
        if (e.ctrlKey && e.keyCode === 83) {
            e.preventDefault();
            $('#priceListForm').submit();
        }
        // Ctrl + B to go back
        if (e.ctrlKey && e.keyCode === 66) {
            e.preventDefault();
            window.location.href = 'price_list_view.php?id=<?php echo $price_list_id; ?>';
        }
        // Ctrl + D to delete (if empty)
        if (e.ctrlKey && e.keyCode === 68 && <?php echo ($stats['item_count'] + $stats['service_count']) == 0 ? 'true' : 'false'; ?>) {
            e.preventDefault();
            $('#deleteModal').modal('show');
        }
        // Ctrl + C to clone
        if (e.ctrlKey && e.keyCode === 67) {
            e.preventDefault();
            $('#cloneModal').modal('show');
        }
    });
});
</script>

<style>
.custom-control-input:checked ~ .custom-control-label::before {
    background-color: #28a745;
    border-color: #28a745;
}

#insuranceFields .form-group label::after {
    content: " *";
    color: #dc3545;
    display: none;
}

#insuranceFields .form-group label.required::after {
    display: inline;
}

.card .card-header {
    background-color: #f8f9fa;
    border-bottom: 1px solid #dee2e6;
}

.table-sm td {
    padding: 0.5rem;
    border-top: 1px solid #dee2e6;
}

.btn-lg {
    padding: 0.75rem 1.5rem;
    font-size: 1.1rem;
}

.modal .alert {
    margin-bottom: 0;
}

.badge {
    font-size: 0.9em;
    padding: 0.4em 0.8em;
}

.form-control-plaintext {
    min-height: 38px;
    padding-top: 7px;
}
</style>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
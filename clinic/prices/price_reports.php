<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/price_functions.php';

// Get report parameters
$report_type = $_GET['report'] ?? 'summary';
$price_list_id = intval($_GET['price_list_id'] ?? 0);
$start_date = $_GET['start_date'] ?? date('Y-m-01'); // First day of current month
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$compare_with = intval($_GET['compare_with'] ?? 0);

// Get price lists for dropdown
$price_lists = getAllPriceLists($mysqli);

// Get selected price list details
$selected_list = null;
if ($price_list_id) {
    $selected_list = getPriceListDetails($mysqli, $price_list_id);
}

// Generate reports based on type
switch ($report_type) {
    case 'summary':
        $report_title = "Price List Summary";
        break;
    case 'changes':
        $report_title = "Price Change Analysis";
        break;
    case 'comparison':
        $report_title = "Price List Comparison";
        break;
    case 'coverage':
        $report_title = "Insurance Coverage Analysis";
        break;
    default:
        $report_title = "Price Reports";
}
?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-chart-bar mr-2"></i>Price Reports & Analysis
        </h3>
        <div class="card-tools">
            <a href="price_management.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left mr-2"></i>Back
            </a>
            <button class="btn btn-success ml-2" onclick="printReport()">
                <i class="fas fa-print mr-2"></i>Print Report
            </button>
        </div>
    </div>
    
    <div class="card-body">
        <!-- Report Selection -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-light">
                        <h6 class="card-title mb-0">Select Report</h6>
                    </div>
                    <div class="card-body">
                        <form method="GET" id="reportForm">
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label>Report Type</label>
                                        <select class="form-control" name="report" id="reportType">
                                            <option value="summary" <?php echo $report_type == 'summary' ? 'selected' : ''; ?>>Price List Summary</option>
                                            <option value="changes" <?php echo $report_type == 'changes' ? 'selected' : ''; ?>>Price Change Analysis</option>
                                            <option value="comparison" <?php echo $report_type == 'comparison' ? 'selected' : ''; ?>>Price List Comparison</option>
                                            <option value="coverage" <?php echo $report_type == 'coverage' ? 'selected' : ''; ?>>Coverage Analysis</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label>Price List</label>
                                        <select class="form-control" name="price_list_id" id="priceListSelect">
                                            <option value="">Select Price List</option>
                                            <?php foreach($price_lists as $pl): ?>
                                            <option value="<?php echo $pl['price_list_id']; ?>" 
                                                <?php echo ($price_list_id == $pl['price_list_id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($pl['list_name'] . " (" . $pl['payer_type'] . ")"); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="col-md-2" id="compareField" style="<?php echo $report_type == 'comparison' ? '' : 'display: none;'; ?>">
                                    <div class="form-group">
                                        <label>Compare With</label>
                                        <select class="form-control" name="compare_with">
                                            <option value="">Select to Compare</option>
                                            <?php foreach($price_lists as $pl): ?>
                                            <?php if ($pl['price_list_id'] != $price_list_id): ?>
                                            <option value="<?php echo $pl['price_list_id']; ?>" 
                                                <?php echo ($compare_with == $pl['price_list_id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($pl['list_name']); ?>
                                            </option>
                                            <?php endif; ?>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="col-md-2">
                                    <div class="form-group">
                                        <label>Start Date</label>
                                        <input type="date" class="form-control" name="start_date" value="<?php echo $start_date; ?>">
                                    </div>
                                </div>
                                
                                <div class="col-md-2">
                                    <div class="form-group">
                                        <label>End Date</label>
                                        <input type="date" class="form-control" name="end_date" value="<?php echo $end_date; ?>">
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-12">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-chart-bar mr-2"></i>Generate Report
                                    </button>
                                    <button type="button" class="btn btn-secondary" onclick="exportReport()">
                                        <i class="fas fa-download mr-2"></i>Export to Excel
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Report Content -->
        <div id="reportContent">
            <?php if ($report_type == 'summary' && $price_list_id): ?>
                <!-- Price List Summary Report -->
                <?php
                $items = getPriceListItems($mysqli, $price_list_id);
                $services = getPriceListServices($mysqli, $price_list_id);
                
                // Calculate statistics
                $total_items = count($items);
                $total_services = count($services);
                $avg_item_price = 0;
                $avg_service_price = 0;
                $min_item_price = PHP_FLOAT_MAX;
                $max_item_price = 0;
                $min_service_price = PHP_FLOAT_MAX;
                $max_service_price = 0;
                
                foreach ($items as $item) {
                    $price = $item['price'];
                    $avg_item_price += $price;
                    $min_item_price = min($min_item_price, $price);
                    $max_item_price = max($max_item_price, $price);
                }
                
                foreach ($services as $service) {
                    $price = $service['price'];
                    $avg_service_price += $price;
                    $min_service_price = min($min_service_price, $price);
                    $max_service_price = max($max_service_price, $price);
                }
                
                $avg_item_price = $total_items > 0 ? $avg_item_price / $total_items : 0;
                $avg_service_price = $total_services > 0 ? $avg_service_price / $total_services : 0;
                ?>
                
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-chart-pie mr-2"></i>Price List Summary: <?php echo htmlspecialchars($selected_list['list_name']); ?>
                        </h5>
                    </div>
                    <div class="card-body">
                        <!-- Statistics Cards -->
                        <div class="row mb-4">
                            <div class="col-md-3">
                                <div class="card bg-light">
                                    <div class="card-body text-center">
                                        <h2 class="text-primary"><?php echo $total_items + $total_services; ?></h2>
                                        <small class="text-muted">Total Items & Services</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card bg-light">
                                    <div class="card-body text-center">
                                        <h2 class="text-success"><?php echo $total_items; ?></h2>
                                        <small class="text-muted">Items</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card bg-light">
                                    <div class="card-body text-center">
                                        <h2 class="text-info"><?php echo $total_services; ?></h2>
                                        <small class="text-muted">Services</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card bg-light">
                                    <div class="card-body text-center">
                                        <h2 class="text-warning"><?php echo $selected_list['payer_type']; ?></h2>
                                        <small class="text-muted">Payer Type</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Price Statistics -->
                        <div class="row">
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header">
                                        <h6 class="card-title mb-0">Item Price Statistics</h6>
                                    </div>
                                    <div class="card-body">
                                        <table class="table table-sm">
                                            <tr>
                                                <td>Average Price:</td>
                                                <td class="text-right font-weight-bold"><?php echo number_format($avg_item_price, 2); ?></td>
                                            </tr>
                                            <tr>
                                                <td>Minimum Price:</td>
                                                <td class="text-right"><?php echo number_format($min_item_price, 2); ?></td>
                                            </tr>
                                            <tr>
                                                <td>Maximum Price:</td>
                                                <td class="text-right"><?php echo number_format($max_item_price, 2); ?></td>
                                            </tr>
                                            <tr>
                                                <td>Price Range:</td>
                                                <td class="text-right"><?php echo number_format($max_item_price - $min_item_price, 2); ?></td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header">
                                        <h6 class="card-title mb-0">Service Price Statistics</h6>
                                    </div>
                                    <div class="card-body">
                                        <table class="table table-sm">
                                            <tr>
                                                <td>Average Price:</td>
                                                <td class="text-right font-weight-bold"><?php echo number_format($avg_service_price, 2); ?></td>
                                            </tr>
                                            <tr>
                                                <td>Minimum Price:</td>
                                                <td class="text-right"><?php echo number_format($min_service_price, 2); ?></td>
                                            </tr>
                                            <tr>
                                                <td>Maximum Price:</td>
                                                <td class="text-right"><?php echo number_format($max_service_price, 2); ?></td>
                                            </tr>
                                            <tr>
                                                <td>Price Range:</td>
                                                <td class="text-right"><?php echo number_format($max_service_price - $min_service_price, 2); ?></td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Price Distribution -->
                        <div class="row mt-4">
                            <div class="col-md-12">
                                <div class="card">
                                    <div class="card-header">
                                        <h6 class="card-title mb-0">Price Distribution</h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="table-responsive">
                                            <table class="table table-sm">
                                                <thead>
                                                    <tr>
                                                        <th>Price Range</th>
                                                        <th>Items</th>
                                                        <th>Services</th>
                                                        <th>Total</th>
                                                        <th width="60%">Distribution</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php
                                                    // Define price ranges
                                                    $ranges = [
                                                        ['min' => 0, 'max' => 100, 'label' => '0 - 100'],
                                                        ['min' => 100, 'max' => 500, 'label' => '100 - 500'],
                                                        ['min' => 500, 'max' => 1000, 'label' => '500 - 1,000'],
                                                        ['min' => 1000, 'max' => 5000, 'label' => '1,000 - 5,000'],
                                                        ['min' => 5000, 'max' => PHP_FLOAT_MAX, 'label' => '5,000+']
                                                    ];
                                                    
                                                    foreach ($ranges as $range) {
                                                        $item_count = 0;
                                                        $service_count = 0;
                                                        
                                                        foreach ($items as $item) {
                                                            if ($item['price'] >= $range['min'] && $item['price'] < $range['max']) {
                                                                $item_count++;
                                                            }
                                                        }
                                                        
                                                        foreach ($services as $service) {
                                                            if ($service['price'] >= $range['min'] && $service['price'] < $range['max']) {
                                                                $service_count++;
                                                            }
                                                        }
                                                        
                                                        $total_count = $item_count + $service_count;
                                                        $total_all = $total_items + $total_services;
                                                        $percentage = $total_all > 0 ? ($total_count / $total_all) * 100 : 0;
                                                        ?>
                                                        <tr>
                                                            <td><?php echo $range['label']; ?></td>
                                                            <td><?php echo $item_count; ?></td>
                                                            <td><?php echo $service_count; ?></td>
                                                            <td><?php echo $total_count; ?></td>
                                                            <td>
                                                                <div class="progress" style="height: 20px;">
                                                                    <div class="progress-bar bg-success" style="width: <?php echo $percentage; ?>%">
                                                                        <?php echo number_format($percentage, 1); ?>%
                                                                    </div>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                        <?php
                                                    }
                                                    ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
            <?php elseif ($report_type == 'changes' && $price_list_id): ?>
                <!-- Price Change Analysis -->
                <?php
                // Get price changes for this list
                $changes_sql = "SELECT ph.*, 
                                       CASE 
                                           WHEN ph.entity_type = 'ITEM' THEN ii.item_name
                                           WHEN ph.entity_type = 'SERVICE' THEN ms.service_name
                                       END as entity_name,
                                       u.user_name as changed_by_name
                                FROM price_history ph
                                LEFT JOIN inventory_items ii ON ph.entity_type = 'ITEM' AND ph.entity_id = ii.item_id
                                LEFT JOIN medical_services ms ON ph.entity_type = 'SERVICE' AND ph.entity_id = ms.medical_service_id
                                LEFT JOIN users u ON ph.changed_by = u.user_id
                                WHERE ph.price_list_id = ? 
                                AND DATE(ph.changed_at) BETWEEN ? AND ?
                                ORDER BY ph.changed_at DESC";
                
                $changes_stmt = $mysqli->prepare($changes_sql);
                $changes_stmt->bind_param('iss', $price_list_id, $start_date, $end_date);
                $changes_stmt->execute();
                $changes_result = $changes_stmt->get_result();
                ?>
                
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-exchange-alt mr-2"></i>Price Change Analysis: <?php echo htmlspecialchars($selected_list['list_name']); ?>
                        </h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted">Period: <?php echo date('M j, Y', strtotime($start_date)); ?> to <?php echo date('M j, Y', strtotime($end_date)); ?></p>
                        
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Entity</th>
                                        <th>Old Price</th>
                                        <th>New Price</th>
                                        <th>Change</th>
                                        <th>% Change</th>
                                        <th>Changed By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $total_increases = 0;
                                    $total_decreases = 0;
                                    $increase_count = 0;
                                    $decrease_count = 0;
                                    
                                    while($change = $changes_result->fetch_assoc()): 
                                        $price_change = $change['new_price'] - $change['old_price'];
                                        $percent_change = $change['old_price'] > 0 ? ($price_change / $change['old_price']) * 100 : 0;
                                        
                                        if ($price_change > 0) {
                                            $total_increases += $price_change;
                                            $increase_count++;
                                        } elseif ($price_change < 0) {
                                            $total_decreases += abs($price_change);
                                            $decrease_count++;
                                        }
                                    ?>
                                    <tr>
                                        <td><?php echo date('M j, Y', strtotime($change['changed_at'])); ?></td>
                                        <td>
                                            <span class="badge badge-<?php echo $change['entity_type'] == 'ITEM' ? 'primary' : 'success'; ?>">
                                                <?php echo $change['entity_type']; ?>
                                            </span>
                                            <?php echo htmlspecialchars($change['entity_name']); ?>
                                        </td>
                                        <td><?php echo number_format($change['old_price'], 2); ?></td>
                                        <td>
                                            <strong class="text-primary"><?php echo number_format($change['new_price'], 2); ?></strong>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?php echo $price_change >= 0 ? 'danger' : 'success'; ?>">
                                                <?php echo ($price_change >= 0 ? '+' : '') . number_format($price_change, 2); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="text-<?php echo $percent_change >= 0 ? 'danger' : 'success'; ?>">
                                                <?php echo ($percent_change >= 0 ? '+' : '') . number_format($percent_change, 1); ?>%
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($change['changed_by_name']); ?></td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                                <tfoot>
                                    <tr class="table-active">
                                        <td colspan="4" class="text-right"><strong>Summary:</strong></td>
                                        <td>
                                            <span class="badge badge-danger">+<?php echo number_format($total_increases, 2); ?></span>
                                            <br><small><?php echo $increase_count; ?> increases</small>
                                        </td>
                                        <td>
                                            <span class="badge badge-success">-<?php echo number_format($total_decreases, 2); ?></span>
                                            <br><small><?php echo $decrease_count; ?> decreases</small>
                                        </td>
                                        <td>
                                            <span class="badge badge-info"><?php echo number_format($total_increases - $total_decreases, 2); ?></span>
                                            <br><small>Net change</small>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
                
            <?php elseif ($report_type == 'comparison' && $price_list_id && $compare_with): ?>
                <!-- Price List Comparison -->
                <?php
                $comparison = comparePriceLists($mysqli, $price_list_id, $compare_with);
                $list1 = $comparison['list1'];
                $list2 = $comparison['list2'];
                ?>
                
                <div class="card">
                    <div class="card-header bg-warning text-white">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-balance-scale mr-2"></i>Price List Comparison
                        </h5>
                    </div>
                    <div class="card-body">
                        <!-- Comparison Header -->
                        <div class="row mb-4">
                            <div class="col-md-5">
                                <div class="card bg-light">
                                    <div class="card-body text-center">
                                        <h5><?php echo htmlspecialchars($list1['list_name']); ?></h5>
                                        <small class="text-muted"><?php echo $list1['payer_type']; ?></small>
                                        <div class="mt-2">
                                            <span class="badge badge-primary"><?php echo count($comparison['items']) + count($comparison['services']); ?> items</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-2 text-center align-self-center">
                                <i class="fas fa-exchange-alt fa-2x text-muted"></i>
                            </div>
                            <div class="col-md-5">
                                <div class="card bg-light">
                                    <div class="card-body text-center">
                                        <h5><?php echo htmlspecialchars($list2['list_name']); ?></h5>
                                        <small class="text-muted"><?php echo $list2['payer_type']; ?></small>
                                        <div class="mt-2">
                                            <span class="badge badge-primary"><?php echo count($comparison['items']) + count($comparison['services']); ?> items</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Items Comparison -->
                        <div class="row">
                            <div class="col-md-12">
                                <div class="card">
                                    <div class="card-header">
                                        <h6 class="card-title mb-0">Items Comparison</h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="table-responsive">
                                            <table class="table table-sm table-hover">
                                                <thead>
                                                    <tr>
                                                        <th>Item Name</th>
                                                        <th class="text-center"><?php echo htmlspecialchars($list1['list_name']); ?></th>
                                                        <th class="text-center"><?php echo htmlspecialchars($list2['list_name']); ?></th>
                                                        <th class="text-center">Difference</th>
                                                        <th class="text-center">% Difference</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach($comparison['items'] as $item): ?>
                                                    <?php if ($item['list1_price'] && $item['list2_price']): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                                                        <td class="text-center"><?php echo number_format($item['list1_price'], 2); ?></td>
                                                        <td class="text-center"><?php echo number_format($item['list2_price'], 2); ?></td>
                                                        <td class="text-center">
                                                            <?php if ($item['price_diff'] != 0): ?>
                                                            <span class="badge badge-<?php echo $item['price_diff'] > 0 ? 'danger' : 'success'; ?>">
                                                                <?php echo ($item['price_diff'] > 0 ? '+' : '') . number_format($item['price_diff'], 2); ?>
                                                            </span>
                                                            <?php else: ?>
                                                            <span class="badge badge-secondary">0.00</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="text-center">
                                                            <?php if ($item['price_diff'] != 0 && $item['list1_price'] > 0): ?>
                                                            <?php $percent_diff = ($item['price_diff'] / $item['list1_price']) * 100; ?>
                                                            <span class="text-<?php echo $percent_diff > 0 ? 'danger' : 'success'; ?>">
                                                                <?php echo ($percent_diff > 0 ? '+' : '') . number_format($percent_diff, 1); ?>%
                                                            </span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                    <?php endif; ?>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Services Comparison -->
                        <div class="row mt-4">
                            <div class="col-md-12">
                                <div class="card">
                                    <div class="card-header">
                                        <h6 class="card-title mb-0">Services Comparison</h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="table-responsive">
                                            <table class="table table-sm table-hover">
                                                <thead>
                                                    <tr>
                                                        <th>Service Name</th>
                                                        <th class="text-center"><?php echo htmlspecialchars($list1['list_name']); ?></th>
                                                        <th class="text-center"><?php echo htmlspecialchars($list2['list_name']); ?></th>
                                                        <th class="text-center">Difference</th>
                                                        <th class="text-center">% Difference</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach($comparison['services'] as $service): ?>
                                                    <?php if ($service['list1_price'] && $service['list2_price']): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($service['service_name']); ?></td>
                                                        <td class="text-center"><?php echo number_format($service['list1_price'], 2); ?></td>
                                                        <td class="text-center"><?php echo number_format($service['list2_price'], 2); ?></td>
                                                        <td class="text-center">
                                                            <?php if ($service['price_diff'] != 0): ?>
                                                            <span class="badge badge-<?php echo $service['price_diff'] > 0 ? 'danger' : 'success'; ?>">
                                                                <?php echo ($service['price_diff'] > 0 ? '+' : '') . number_format($service['price_diff'], 2); ?>
                                                            </span>
                                                            <?php else: ?>
                                                            <span class="badge badge-secondary">0.00</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="text-center">
                                                            <?php if ($service['price_diff'] != 0 && $service['list1_price'] > 0): ?>
                                                            <?php $percent_diff = ($service['price_diff'] / $service['list1_price']) * 100; ?>
                                                            <span class="text-<?php echo $percent_diff > 0 ? 'danger' : 'success'; ?>">
                                                                <?php echo ($percent_diff > 0 ? '+' : '') . number_format($percent_diff, 1); ?>%
                                                            </span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                    <?php endif; ?>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
            <?php elseif ($report_type == 'coverage' && $selected_list && $selected_list['payer_type'] == 'INSURANCE'): ?>
                <!-- Insurance Coverage Analysis -->
                <?php
                $services = getPriceListServices($mysqli, $price_list_id);
                
                // Calculate coverage statistics
                $total_services = count($services);
                $full_coverage = 0;
                $partial_coverage = 0;
                $no_coverage = 0;
                $coverage_sum = 0;
                
                foreach ($services as $service) {
                    if ($service['covered_percentage'] == 100) {
                        $full_coverage++;
                    } elseif ($service['covered_percentage'] > 0) {
                        $partial_coverage++;
                    } else {
                        $no_coverage++;
                    }
                    $coverage_sum += $service['covered_percentage'];
                }
                
                $avg_coverage = $total_services > 0 ? $coverage_sum / $total_services : 0;
                ?>
                
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-shield-alt mr-2"></i>Insurance Coverage Analysis: <?php echo htmlspecialchars($selected_list['list_name']); ?>
                        </h5>
                    </div>
                    <div class="card-body">
                        <!-- Coverage Statistics -->
                        <div class="row mb-4">
                            <div class="col-md-3">
                                <div class="card bg-light">
                                    <div class="card-body text-center">
                                        <h2 class="text-success"><?php echo $total_services; ?></h2>
                                        <small class="text-muted">Total Services</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card bg-light">
                                    <div class="card-body text-center">
                                        <h2 class="text-primary"><?php echo $full_coverage; ?></h2>
                                        <small class="text-muted">100% Coverage</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card bg-light">
                                    <div class="card-body text-center">
                                        <h2 class="text-warning"><?php echo $partial_coverage; ?></h2>
                                        <small class="text-muted">Partial Coverage</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card bg-light">
                                    <div class="card-body text-center">
                                        <h2 class="text-danger"><?php echo $no_coverage; ?></h2>
                                        <small class="text-muted">No Coverage</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Coverage Distribution -->
                        <div class="row">
                            <div class="col-md-8">
                                <div class="card">
                                    <div class="card-header">
                                        <h6 class="card-title mb-0">Coverage Distribution</h6>
                                    </div>
                                    <div class="card-body">
                                        <canvas id="coverageChart" height="200"></canvas>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card">
                                    <div class="card-header">
                                        <h6 class="card-title mb-0">Coverage Summary</h6>
                                    </div>
                                    <div class="card-body">
                                        <table class="table table-sm">
                                            <tr>
                                                <td>Average Coverage:</td>
                                                <td class="text-right font-weight-bold"><?php echo number_format($avg_coverage, 1); ?>%</td>
                                            </tr>
                                            <tr>
                                                <td>100% Coverage:</td>
                                                <td class="text-right"><?php echo $full_coverage; ?> services</td>
                                            </tr>
                                            <tr>
                                                <td>Partial Coverage:</td>
                                                <td class="text-right"><?php echo $partial_coverage; ?> services</td>
                                            </tr>
                                            <tr>
                                                <td>No Coverage:</td>
                                                <td class="text-right"><?php echo $no_coverage; ?> services</td>
                                            </tr>
                                            <tr class="table-active">
                                                <td>Total Coverage Value:</td>
                                                <td class="text-right"><?php echo $coverage_sum; ?>%</td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Coverage Details -->
                        <div class="row mt-4">
                            <div class="col-md-12">
                                <div class="card">
                                    <div class="card-header">
                                        <h6 class="card-title mb-0">Service Coverage Details</h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="table-responsive">
                                            <table class="table table-sm table-hover">
                                                <thead>
                                                    <tr>
                                                        <th>Service Name</th>
                                                        <th>Price</th>
                                                        <th>Coverage %</th>
                                                        <th>Insurance Pays</th>
                                                        <th>Patient Pays</th>
                                                        <th>Coverage Status</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach($services as $service): ?>
                                                    <?php
                                                    $coverage = calculateInsuranceCoverage($service['price'], $service['covered_percentage']);
                                                    $status_class = '';
                                                    $status_text = '';
                                                    
                                                    if ($service['covered_percentage'] == 100) {
                                                        $status_class = 'success';
                                                        $status_text = 'Full Coverage';
                                                    } elseif ($service['covered_percentage'] >= 50) {
                                                        $status_class = 'info';
                                                        $status_text = 'Good Coverage';
                                                    } elseif ($service['covered_percentage'] > 0) {
                                                        $status_class = 'warning';
                                                        $status_text = 'Partial Coverage';
                                                    } else {
                                                        $status_class = 'danger';
                                                        $status_text = 'No Coverage';
                                                    }
                                                    ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($service['service_name']); ?></td>
                                                        <td><?php echo number_format($service['price'], 2); ?></td>
                                                        <td>
                                                            <span class="badge badge-<?php echo $status_class; ?>">
                                                                <?php echo $service['covered_percentage']; ?>%
                                                            </span>
                                                        </td>
                                                        <td><?php echo number_format($coverage['insurance_pays'], 2); ?></td>
                                                        <td><?php echo number_format($coverage['patient_pays'], 2); ?></td>
                                                        <td><?php echo $status_text; ?></td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <script>
                // Coverage Chart
                document.addEventListener('DOMContentLoaded', function() {
                    var ctx = document.getElementById('coverageChart').getContext('2d');
                    var coverageChart = new Chart(ctx, {
                        type: 'doughnut',
                        data: {
                            labels: ['100% Coverage', 'Partial Coverage', 'No Coverage'],
                            datasets: [{
                                data: [<?php echo $full_coverage; ?>, <?php echo $partial_coverage; ?>, <?php echo $no_coverage; ?>],
                                backgroundColor: [
                                    '#28a745',
                                    '#ffc107',
                                    '#dc3545'
                                ]
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            legend: {
                                position: 'bottom'
                            }
                        }
                    });
                });
                </script>
                
            <?php else: ?>
                <!-- Default View - Instructions -->
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="fas fa-chart-line fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">Price Reports & Analysis</h5>
                        <p class="text-muted">Select a report type and price list to generate detailed analysis and reports.</p>
                        
                        <div class="row mt-4">
                            <div class="col-md-3">
                                <div class="card">
                                    <div class="card-body">
                                        <i class="fas fa-chart-pie fa-2x text-primary mb-3"></i>
                                        <h6>Summary Report</h6>
                                        <small class="text-muted">Overview of price list statistics and distribution</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card">
                                    <div class="card-body">
                                        <i class="fas fa-exchange-alt fa-2x text-info mb-3"></i>
                                        <h6>Change Analysis</h6>
                                        <small class="text-muted">Track price changes over time</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card">
                                    <div class="card-body">
                                        <i class="fas fa-balance-scale fa-2x text-warning mb-3"></i>
                                        <h6>Comparison Report</h6>
                                        <small class="text-muted">Compare prices between two price lists</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card">
                                    <div class="card-body">
                                        <i class="fas fa-shield-alt fa-2x text-success mb-3"></i>
                                        <h6>Coverage Analysis</h6>
                                        <small class="text-muted">Insurance coverage statistics (Insurance lists only)</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Show/hide compare field based on report type
    $('#reportType').change(function() {
        if ($(this).val() === 'comparison') {
            $('#compareField').slideDown();
        } else {
            $('#compareField').slideUp();
        }
    });
    
    // Auto-submit when price list changes for some reports
    $('#priceListSelect').change(function() {
        if ($(this).val() && $('#reportType').val() !== 'comparison') {
            $('#reportForm').submit();
        }
    });
});

function printReport() {
    window.print();
}

function exportReport() {
    // Get current parameters
    var params = new URLSearchParams(window.location.search);
    
    // Redirect to export page
    window.location.href = 'price_export.php?' + params.toString() + '&type=report';
}
</script>

<style>
@media print {
    .card-header, .card-tools, #reportForm, .btn {
        display: none !important;
    }
    
    .card {
        border: none !important;
    }
    
    .card-body {
        padding: 0 !important;
    }
    
    table {
        font-size: 12px;
    }
}
</style>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
<?php
// asset_reports.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Report type filter
$report_type = $_GET['report'] ?? 'overview';
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$category_filter = $_GET['category'] ?? '';
$location_filter = $_GET['location'] ?? '';
$supplier_filter = $_GET['supplier'] ?? '';

// Validate dates
if (!strtotime($date_from)) $date_from = date('Y-m-01');
if (!strtotime($date_to)) $date_to = date('Y-m-d');

// Get categories for filter
$categories_sql = "SELECT * FROM asset_categories WHERE is_active = 1 ORDER BY category_name";
$categories_result = $mysqli->query($categories_sql);

// Get locations for filter
$locations_sql = "SELECT * FROM asset_locations WHERE is_active = 1 ORDER BY location_name";
$locations_result = $mysqli->query($locations_sql);

// Get suppliers for filter
$suppliers_sql = "SELECT supplier_id, supplier_name FROM suppliers WHERE supplier_is_active = 1 ORDER BY supplier_name";
$suppliers_result = $mysqli->query($suppliers_sql);

// Generate reports based on type
switch ($report_type) {
    case 'depreciation':
        $report_title = "Asset Depreciation Report";
        $report_description = "Shows depreciation calculations for assets";
        break;
        
    case 'maintenance':
        $report_title = "Maintenance Report";
        $report_description = "Shows maintenance history and costs";
        break;
        
    case 'checkout':
        $report_title = "Checkout Activity Report";
        $report_description = "Shows asset checkout and return activity";
        break;
        
    case 'disposals':
        $report_title = "Asset Disposals Report";
        $report_description = "Shows disposed assets and reasons";
        break;
        
    case 'value':
        $report_title = "Asset Value Report";
        $report_description = "Shows asset values and depreciation";
        break;
        
    default:
        $report_type = 'overview';
        $report_title = "Asset Overview Report";
        $report_description = "Overview of all assets and their status";
        break;
}
?>

<div class="content-wrapper">
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="m-0 text-dark">
                        <i class="fas fa-chart-bar mr-2"></i>Asset Reports
                        <small class="text-muted"><?php echo $report_title; ?></small>
                    </h1>
                </div>
                <div class="col-sm-6">
                    <div class="float-right">
                        <a href="asset_management.php" class="btn btn-secondary">
                            <i class="fas fa-cubes mr-2"></i>View Assets
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <section class="content">
        <div class="container-fluid">
            <!-- Alert Messages -->
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

            <!-- Report Type Selector -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-chart-pie mr-2"></i>Report Type</h3>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-12">
                            <div class="btn-group btn-group-toggle" data-toggle="buttons">
                                <a href="?report=overview" class="btn btn-outline-primary <?php echo $report_type == 'overview' ? 'active' : ''; ?>">
                                    <i class="fas fa-chart-bar mr-2"></i>Overview
                                </a>
                                <a href="?report=value" class="btn btn-outline-primary <?php echo $report_type == 'value' ? 'active' : ''; ?>">
                                    <i class="fas fa-dollar-sign mr-2"></i>Value
                                </a>
                                <a href="?report=depreciation" class="btn btn-outline-primary <?php echo $report_type == 'depreciation' ? 'active' : ''; ?>">
                                    <i class="fas fa-chart-line mr-2"></i>Depreciation
                                </a>
                                <a href="?report=maintenance" class="btn btn-outline-primary <?php echo $report_type == 'maintenance' ? 'active' : ''; ?>">
                                    <i class="fas fa-tools mr-2"></i>Maintenance
                                </a>
                                <a href="?report=checkout" class="btn btn-outline-primary <?php echo $report_type == 'checkout' ? 'active' : ''; ?>">
                                    <i class="fas fa-exchange-alt mr-2"></i>Checkout
                                </a>
                                <a href="?report=disposals" class="btn btn-outline-primary <?php echo $report_type == 'disposals' ? 'active' : ''; ?>">
                                    <i class="fas fa-trash mr-2"></i>Disposals
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Report Filters -->
            <div class="card mt-4">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-filter mr-2"></i>Report Filters</h3>
                </div>
                <div class="card-body">
                    <form method="GET" autocomplete="off">
                        <input type="hidden" name="report" value="<?php echo $report_type; ?>">
                        
                        <div class="row">
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Date From</label>
                                    <input type="date" class="form-control" name="date_from" value="<?php echo $date_from; ?>">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Date To</label>
                                    <input type="date" class="form-control" name="date_to" value="<?php echo $date_to; ?>">
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label>Category</label>
                                    <select class="form-control select2" name="category">
                                        <option value="">All Categories</option>
                                        <?php while($category = $categories_result->fetch_assoc()): ?>
                                            <option value="<?php echo $category['category_id']; ?>" <?php if ($category_filter == $category['category_id']) echo "selected"; ?>>
                                                <?php echo htmlspecialchars($category['category_name']); ?>
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label>Location</label>
                                    <select class="form-control select2" name="location">
                                        <option value="">All Locations</option>
                                        <?php while($location = $locations_result->fetch_assoc()): ?>
                                            <option value="<?php echo $location['location_id']; ?>" <?php if ($location_filter == $location['location_id']) echo "selected"; ?>>
                                                <?php echo htmlspecialchars($location['location_name']); ?>
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label>Supplier</label>
                                    <select class="form-control select2" name="supplier">
                                        <option value="">All Suppliers</option>
                                        <?php while($supplier = $suppliers_result->fetch_assoc()): ?>
                                            <option value="<?php echo $supplier['supplier_id']; ?>" <?php if ($supplier_filter == $supplier['supplier_id']) echo "selected"; ?>>
                                                <?php echo htmlspecialchars($supplier['supplier_name']); ?>
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-12 text-right">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-filter mr-2"></i>Apply Filters
                                </button>
                                <a href="asset_reports.php" class="btn btn-secondary">
                                    <i class="fas fa-undo mr-2"></i>Reset
                                </a>
                                <button type="button" class="btn btn-success" onclick="exportReport()">
                                    <i class="fas fa-file-export mr-2"></i>Export Report
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Report Content -->
            <div class="card mt-4">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-chart-bar mr-2"></i><?php echo $report_title; ?></h3>
                    <div class="card-tools">
                        <span class="badge badge-info"><?php echo date('M d, Y', strtotime($date_from)); ?> to <?php echo date('M d, Y', strtotime($date_to)); ?></span>
                    </div>
                </div>
                <div class="card-body">
                    <?php if ($report_type == 'overview'): ?>
                        <!-- Overview Report -->
                        <?php
                        // Get overview statistics
                        $overview_sql = "SELECT 
                            COUNT(*) as total_assets,
                            SUM(purchase_price) as total_purchase_value,
                            SUM(current_value) as total_current_value,
                            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_assets,
                            SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive_assets,
                            SUM(CASE WHEN status = 'under_maintenance' THEN 1 ELSE 0 END) as maintenance_assets,
                            SUM(CASE WHEN status = 'disposed' THEN 1 ELSE 0 END) as disposed_assets,
                            SUM(CASE WHEN is_critical = 1 THEN 1 ELSE 0 END) as critical_assets
                            FROM assets
                            WHERE 1=1";
                        
                        if ($category_filter) $overview_sql .= " AND category_id = " . intval($category_filter);
                        if ($location_filter) $overview_sql .= " AND location_id = " . intval($location_filter);
                        if ($supplier_filter) $overview_sql .= " AND supplier_id = " . intval($supplier_filter);
                        
                        $overview_result = $mysqli->query($overview_sql);
                        $overview = $overview_result->fetch_assoc();
                        
                        // Get category breakdown
                        $category_sql = "SELECT 
                            ac.category_name,
                            COUNT(a.asset_id) as asset_count,
                            SUM(a.purchase_price) as total_purchase,
                            SUM(a.current_value) as total_current
                            FROM asset_categories ac
                            LEFT JOIN assets a ON ac.category_id = a.category_id
                            WHERE ac.is_active = 1";
                        
                        if ($category_filter) $category_sql .= " AND a.category_id = " . intval($category_filter);
                        if ($location_filter) $category_sql .= " AND a.location_id = " . intval($location_filter);
                        if ($supplier_filter) $category_sql .= " AND a.supplier_id = " . intval($supplier_filter);
                        
                        $category_sql .= " GROUP BY ac.category_id ORDER BY asset_count DESC";
                        $category_result = $mysqli->query($category_sql);
                        ?>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <h5>Key Metrics</h5>
                                <div class="table-responsive">
                                    <table class="table table-bordered">
                                        <tr>
                                            <th width="70%">Metric</th>
                                            <th width="30%" class="text-right">Value</th>
                                        </tr>
                                        <tr>
                                            <td>Total Assets</td>
                                            <td class="text-right"><?php echo $overview['total_assets']; ?></td>
                                        </tr>
                                        <tr>
                                            <td>Active Assets</td>
                                            <td class="text-right"><?php echo $overview['active_assets']; ?></td>
                                        </tr>
                                        <tr>
                                            <td>Critical Assets</td>
                                            <td class="text-right"><?php echo $overview['critical_assets']; ?></td>
                                        </tr>
                                        <tr>
                                            <td>Under Maintenance</td>
                                            <td class="text-right"><?php echo $overview['maintenance_assets']; ?></td>
                                        </tr>
                                        <tr>
                                            <td>Total Purchase Value</td>
                                            <td class="text-right text-success">$<?php echo number_format($overview['total_purchase_value'] ?? 0, 2); ?></td>
                                        </tr>
                                        <tr>
                                            <td>Total Current Value</td>
                                            <td class="text-right text-primary">$<?php echo number_format($overview['total_current_value'] ?? 0, 2); ?></td>
                                        </tr>
                                        <tr>
                                            <td>Total Depreciation</td>
                                            <td class="text-right text-danger">$<?php echo number_format($overview['total_purchase_value'] - $overview['total_current_value'], 2); ?></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <h5>Asset Status Distribution</h5>
                                <canvas id="statusChart" height="200"></canvas>
                            </div>
                        </div>
                        
                        <div class="row mt-4">
                            <div class="col-md-12">
                                <h5>Category Breakdown</h5>
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>Category</th>
                                                <th class="text-right">Asset Count</th>
                                                <th class="text-right">Purchase Value</th>
                                                <th class="text-right">Current Value</th>
                                                <th class="text-right">Depreciation</th>
                                                <th class="text-right">% of Total</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php while($cat = $category_result->fetch_assoc()): 
                                                $depreciation = $cat['total_purchase'] - $cat['total_current'];
                                                $percentage = $overview['total_assets'] > 0 ? ($cat['asset_count'] / $overview['total_assets'] * 100) : 0;
                                            ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($cat['category_name']); ?></td>
                                                <td class="text-right"><?php echo $cat['asset_count']; ?></td>
                                                <td class="text-right">$<?php echo number_format($cat['total_purchase'] ?? 0, 2); ?></td>
                                                <td class="text-right">$<?php echo number_format($cat['total_current'] ?? 0, 2); ?></td>
                                                <td class="text-right text-danger">$<?php echo number_format($depreciation, 2); ?></td>
                                                <td class="text-right"><?php echo number_format($percentage, 1); ?>%</td>
                                            </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        
                    <?php elseif ($report_type == 'depreciation'): ?>
                        <!-- Depreciation Report -->
                        <?php
                        $depreciation_sql = "SELECT 
                            a.asset_tag,
                            a.asset_name,
                            a.purchase_date,
                            a.purchase_price,
                            a.current_value,
                            (a.purchase_price - a.current_value) as total_depreciation,
                            ((a.purchase_price - a.current_value) / a.purchase_price * 100) as depreciation_percentage,
                            ac.category_name,
                            s.supplier_name
                            FROM assets a
                            LEFT JOIN asset_categories ac ON a.category_id = ac.category_id
                            LEFT JOIN suppliers s ON a.supplier_id = s.supplier_id
                            WHERE a.purchase_price > 0 AND a.purchase_date IS NOT NULL";
                        
                        if ($category_filter) $depreciation_sql .= " AND a.category_id = " . intval($category_filter);
                        if ($location_filter) $depreciation_sql .= " AND a.location_id = " . intval($location_filter);
                        if ($supplier_filter) $depreciation_sql .= " AND a.supplier_id = " . intval($supplier_filter);
                        
                        $depreciation_sql .= " ORDER BY total_depreciation DESC";
                        $depreciation_result = $mysqli->query($depreciation_sql);
                        ?>
                        
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Asset</th>
                                        <th>Category</th>
                                        <th>Supplier</th>
                                        <th class="text-right">Purchase Date</th>
                                        <th class="text-right">Purchase Price</th>
                                        <th class="text-right">Current Value</th>
                                        <th class="text-right">Total Depreciation</th>
                                        <th class="text-right">Depreciation %</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while($asset = $depreciation_result->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <strong class="text-primary"><?php echo htmlspecialchars($asset['asset_tag']); ?></strong>
                                            <br>
                                            <small class="text-muted"><?php echo htmlspecialchars($asset['asset_name']); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($asset['category_name']); ?></td>
                                        <td><?php echo htmlspecialchars($asset['supplier_name']); ?></td>
                                        <td class="text-right"><?php echo $asset['purchase_date'] ? date('M d, Y', strtotime($asset['purchase_date'])) : 'N/A'; ?></td>
                                        <td class="text-right">$<?php echo number_format($asset['purchase_price'], 2); ?></td>
                                        <td class="text-right">$<?php echo number_format($asset['current_value'], 2); ?></td>
                                        <td class="text-right text-danger">$<?php echo number_format($asset['total_depreciation'], 2); ?></td>
                                        <td class="text-right">
                                            <?php if ($asset['purchase_price'] > 0): ?>
                                                <span class="badge badge-<?php echo $asset['depreciation_percentage'] > 50 ? 'danger' : ($asset['depreciation_percentage'] > 25 ? 'warning' : 'info'); ?>">
                                                    <?php echo number_format($asset['depreciation_percentage'], 1); ?>%
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                        
                    <?php elseif ($report_type == 'maintenance'): ?>
                        <!-- Maintenance Report -->
                        <?php
                        $maintenance_sql = "SELECT 
                            am.maintenance_id,
                            am.maintenance_type,
                            am.maintenance_date,
                            am.cost,
                            am.status,
                            am.performed_by,
                            a.asset_tag,
                            a.asset_name,
                            s.supplier_name
                            FROM asset_maintenance am
                            LEFT JOIN assets a ON am.asset_id = a.asset_id
                            LEFT JOIN suppliers s ON am.supplier_id = s.supplier_id
                            WHERE am.maintenance_date BETWEEN '$date_from' AND '$date_to'";
                        
                        if ($category_filter) $maintenance_sql .= " AND a.category_id = " . intval($category_filter);
                        if ($location_filter) $maintenance_sql .= " AND a.location_id = " . intval($location_filter);
                        if ($supplier_filter) $maintenance_sql .= " AND am.supplier_id = " . intval($supplier_filter);
                        
                        $maintenance_sql .= " ORDER BY am.maintenance_date DESC";
                        $maintenance_result = $mysqli->query($maintenance_sql);
                        ?>
                        
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Asset</th>
                                        <th>Type</th>
                                        <th>Performed By</th>
                                        <th>Supplier</th>
                                        <th class="text-right">Cost</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while($maintenance = $maintenance_result->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo date('M d, Y', strtotime($maintenance['maintenance_date'])); ?></td>
                                        <td>
                                            <strong class="text-primary"><?php echo htmlspecialchars($maintenance['asset_tag']); ?></strong>
                                            <br>
                                            <small class="text-muted"><?php echo htmlspecialchars($maintenance['asset_name']); ?></small>
                                        </td>
                                        <td>
                                            <span class="badge badge-primary">
                                                <?php echo ucfirst($maintenance['maintenance_type']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($maintenance['performed_by']); ?></td>
                                        <td><?php echo htmlspecialchars($maintenance['supplier_name']); ?></td>
                                        <td class="text-right">$<?php echo number_format($maintenance['cost'], 2); ?></td>
                                        <td>
                                            <span class="badge badge-<?php echo $maintenance['status'] == 'completed' ? 'success' : 'warning'; ?>">
                                                <?php echo ucfirst($maintenance['status']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                        
                    <?php elseif ($report_type == 'checkout'): ?>
                        <!-- Checkout Report -->
                        <?php
                        $checkout_sql = "SELECT 
                            cl.checkout_date,
                            cl.checkin_date,
                            cl.expected_return_date,
                            a.asset_tag,
                            a.asset_name,
                            checked_out_by.user_name as checked_out_by,
                            assigned_to.user_name as assigned_to,
                            DATEDIFF(IFNULL(cl.checkin_date, CURDATE()), cl.checkout_date) as days_out
                            FROM asset_checkout_logs cl
                            LEFT JOIN assets a ON cl.asset_id = a.asset_id
                            LEFT JOIN users checked_out_by ON cl.checked_out_by = checked_out_by.user_id
                            LEFT JOIN users assigned_to ON cl.assigned_to = assigned_to.user_id
                            WHERE cl.checkout_date BETWEEN '$date_from' AND '$date_to'";
                        
                        if ($category_filter) $checkout_sql .= " AND a.category_id = " . intval($category_filter);
                        if ($location_filter) $checkout_sql .= " AND a.location_id = " . intval($location_filter);
                        
                        $checkout_sql .= " ORDER BY cl.checkout_date DESC";
                        $checkout_result = $mysqli->query($checkout_sql);
                        ?>
                        
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Checkout Date</th>
                                        <th>Asset</th>
                                        <th>Checked Out By</th>
                                        <th>Assigned To</th>
                                        <th>Expected Return</th>
                                        <th>Checkin Date</th>
                                        <th class="text-right">Days Out</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while($checkout = $checkout_result->fetch_assoc()): 
                                        $status = $checkout['checkin_date'] ? 'Returned' : ($checkout['expected_return_date'] < date('Y-m-d') ? 'Overdue' : 'Checked Out');
                                        $status_badge = $status == 'Returned' ? 'success' : ($status == 'Overdue' ? 'danger' : 'warning');
                                    ?>
                                    <tr>
                                        <td><?php echo date('M d, Y', strtotime($checkout['checkout_date'])); ?></td>
                                        <td>
                                            <strong class="text-primary"><?php echo htmlspecialchars($checkout['asset_tag']); ?></strong>
                                            <br>
                                            <small class="text-muted"><?php echo htmlspecialchars($checkout['asset_name']); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($checkout['checked_out_by']); ?></td>
                                        <td><?php echo htmlspecialchars($checkout['assigned_to']); ?></td>
                                        <td><?php echo $checkout['expected_return_date'] ? date('M d, Y', strtotime($checkout['expected_return_date'])) : 'N/A'; ?></td>
                                        <td><?php echo $checkout['checkin_date'] ? date('M d, Y', strtotime($checkout['checkin_date'])) : 'Not Returned'; ?></td>
                                        <td class="text-right"><?php echo $checkout['days_out']; ?> days</td>
                                        <td>
                                            <span class="badge badge-<?php echo $status_badge; ?>">
                                                <?php echo $status; ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                        
                    <?php elseif ($report_type == 'disposals'): ?>
                        <!-- Disposals Report -->
                        <?php
                        $disposals_sql = "SELECT 
                            ad.disposal_date,
                            ad.disposal_reason,
                            ad.disposal_method,
                            ad.sold_amount,
                            a.asset_tag,
                            a.asset_name,
                            a.purchase_price,
                            approved.user_name as approved_by,
                            disposed.user_name as disposed_by
                            FROM asset_disposals ad
                            LEFT JOIN assets a ON ad.asset_id = a.asset_id
                            LEFT JOIN users approved ON ad.approved_by = approved.user_id
                            LEFT JOIN users disposed ON ad.disposed_by = disposed.user_id
                            WHERE ad.disposal_date BETWEEN '$date_from' AND '$date_to'";
                        
                        if ($category_filter) $disposals_sql .= " AND a.category_id = " . intval($category_filter);
                        if ($location_filter) $disposals_sql .= " AND a.location_id = " . intval($location_filter);
                        
                        $disposals_sql .= " ORDER BY ad.disposal_date DESC";
                        $disposals_result = $mysqli->query($disposals_sql);
                        ?>
                        
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Disposal Date</th>
                                        <th>Asset</th>
                                        <th>Reason</th>
                                        <th>Method</th>
                                        <th class="text-right">Purchase Price</th>
                                        <th class="text-right">Sold Amount</th>
                                        <th class="text-right">Loss/Gain</th>
                                        <th>Approved By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while($disposal = $disposals_result->fetch_assoc()): 
                                        $loss_gain = $disposal['sold_amount'] - $disposal['purchase_price'];
                                    ?>
                                    <tr>
                                        <td><?php echo date('M d, Y', strtotime($disposal['disposal_date'])); ?></td>
                                        <td>
                                            <strong class="text-primary"><?php echo htmlspecialchars($disposal['asset_tag']); ?></strong>
                                            <br>
                                            <small class="text-muted"><?php echo htmlspecialchars($disposal['asset_name']); ?></small>
                                        </td>
                                        <td><?php echo ucfirst(str_replace('_', ' ', $disposal['disposal_reason'])); ?></td>
                                        <td><?php echo htmlspecialchars($disposal['disposal_method']); ?></td>
                                        <td class="text-right">$<?php echo number_format($disposal['purchase_price'], 2); ?></td>
                                        <td class="text-right">$<?php echo number_format($disposal['sold_amount'], 2); ?></td>
                                        <td class="text-right <?php echo $loss_gain >= 0 ? 'text-success' : 'text-danger'; ?>">
                                            $<?php echo number_format($loss_gain, 2); ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($disposal['approved_by']); ?></td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                        
                    <?php elseif ($report_type == 'value'): ?>
                        <!-- Value Report -->
                        <?php
                        $value_sql = "SELECT 
                            a.asset_tag,
                            a.asset_name,
                            ac.category_name,
                            al.location_name,
                            a.purchase_date,
                            a.purchase_price,
                            a.current_value,
                            (a.purchase_price - a.current_value) as depreciation,
                            ((a.purchase_price - a.current_value) / a.purchase_price * 100) as depreciation_percentage
                            FROM assets a
                            LEFT JOIN asset_categories ac ON a.category_id = ac.category_id
                            LEFT JOIN asset_locations al ON a.location_id = al.location_id
                            WHERE a.purchase_price > 0";
                        
                        if ($category_filter) $value_sql .= " AND a.category_id = " . intval($category_filter);
                        if ($location_filter) $value_sql .= " AND a.location_id = " . intval($location_filter);
                        if ($supplier_filter) $value_sql .= " AND a.supplier_id = " . intval($supplier_filter);
                        
                        $value_sql .= " ORDER BY a.purchase_price DESC";
                        $value_result = $mysqli->query($value_sql);
                        ?>
                        
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Asset</th>
                                        <th>Category</th>
                                        <th>Location</th>
                                        <th class="text-right">Purchase Date</th>
                                        <th class="text-right">Purchase Price</th>
                                        <th class="text-right">Current Value</th>
                                        <th class="text-right">Depreciation</th>
                                        <th class="text-right">Depreciation %</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while($asset = $value_result->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <strong class="text-primary"><?php echo htmlspecialchars($asset['asset_tag']); ?></strong>
                                            <br>
                                            <small class="text-muted"><?php echo htmlspecialchars($asset['asset_name']); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($asset['category_name']); ?></td>
                                        <td><?php echo htmlspecialchars($asset['location_name']); ?></td>
                                        <td class="text-right"><?php echo $asset['purchase_date'] ? date('M d, Y', strtotime($asset['purchase_date'])) : 'N/A'; ?></td>
                                        <td class="text-right">$<?php echo number_format($asset['purchase_price'], 2); ?></td>
                                        <td class="text-right">$<?php echo number_format($asset['current_value'], 2); ?></td>
                                        <td class="text-right text-danger">$<?php echo number_format($asset['depreciation'], 2); ?></td>
                                        <td class="text-right">
                                            <?php if ($asset['purchase_price'] > 0): ?>
                                                <span class="badge badge-<?php echo $asset['depreciation_percentage'] > 50 ? 'danger' : ($asset['depreciation_percentage'] > 25 ? 'warning' : 'info'); ?>">
                                                    <?php echo number_format($asset['depreciation_percentage'], 1); ?>%
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="card-footer">
                    <div class="row">
                        <div class="col-md-6">
                            <small class="text-muted">
                                <i class="fas fa-info-circle mr-1"></i>
                                Report generated on <?php echo date('F j, Y'); ?> at <?php echo date('h:i A'); ?>
                            </small>
                        </div>
                        <div class="col-md-6 text-right">
                            <button type="button" class="btn btn-sm btn-success" onclick="window.print()">
                                <i class="fas fa-print mr-1"></i>Print Report
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<script>
$(document).ready(function() {
    $('.select2').select2();
    
    // Initialize chart for overview report
    <?php if ($report_type == 'overview'): ?>
        var ctx = document.getElementById('statusChart').getContext('2d');
        var statusChart = new Chart(ctx, {
            type: 'pie',
            data: {
                labels: ['Active', 'Inactive', 'Maintenance', 'Disposed'],
                datasets: [{
                    data: [
                        <?php echo $overview['active_assets']; ?>,
                        <?php echo $overview['inactive_assets']; ?>,
                        <?php echo $overview['maintenance_assets']; ?>,
                        <?php echo $overview['disposed_assets']; ?>
                    ],
                    backgroundColor: [
                        '#28a745',
                        '#6c757d',
                        '#ffc107',
                        '#dc3545'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
    <?php endif; ?>
});

function exportReport() {
    // Collect filter data
    var filters = {
        report: '<?php echo $report_type; ?>',
        date_from: '<?php echo $date_from; ?>',
        date_to: '<?php echo $date_to; ?>',
        category: '<?php echo $category_filter; ?>',
        location: '<?php echo $location_filter; ?>',
        supplier: '<?php echo $supplier_filter; ?>'
    };
    
    // Open export page
    var exportUrl = 'asset_export_report.php?' + $.param(filters);
    window.open(exportUrl, '_blank');
}
</script>

<style>
.info-box {
    transition: transform 0.2s ease-in-out;
    border: 1px solid #e3e6f0;
}
.info-box:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}
.btn-group-toggle .btn {
    border-radius: 0.25rem;
    margin-right: 5px;
}
.btn-group-toggle .btn.active {
    background-color: #007bff;
    color: white;
    border-color: #007bff;
}
@media print {
    .btn, .card-header .card-tools, form {
        display: none !important;
    }
    .card {
        border: none !important;
    }
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
<?php
// asset_view.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Get asset ID
$asset_id = intval($_GET['id'] ?? 0);
if ($asset_id <= 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid asset ID.";
    header("Location: asset_management.php");
    exit;
}

// Get asset details with enhanced information
$asset_sql = "
    SELECT a.*, 
           ac.category_name, ac.depreciation_rate, ac.useful_life_years,
           al.location_name, al.location_type, al.building, al.floor, al.room_number,
           u.user_name as assigned_user_name,
           creator.user_name as created_by_name,
           s.supplier_name, s.supplier_email, s.supplier_phone, s.supplier_address,
           updater.user_name as updated_by_name,
           TIMESTAMPDIFF(MONTH, a.purchase_date, CURDATE()) as months_owned,
           DATEDIFF(CURDATE(), a.purchase_date) as days_owned,
           DATEDIFF(a.next_maintenance_date, CURDATE()) as days_to_maintenance,
           DATEDIFF(a.warranty_expiry, CURDATE()) as days_to_warranty_expiry,
           (SELECT COUNT(*) FROM asset_maintenance am WHERE am.asset_id = a.asset_id) as total_maintenance,
           (SELECT COUNT(*) FROM asset_checkout_logs ac WHERE ac.asset_id = a.asset_id AND ac.checkin_date IS NULL) as currently_checked_out
    FROM assets a
    LEFT JOIN asset_categories ac ON a.category_id = ac.category_id
    LEFT JOIN asset_locations al ON a.location_id = al.location_id
    LEFT JOIN users u ON a.assigned_to = u.user_id
    LEFT JOIN users creator ON a.created_by = creator.user_id
    LEFT JOIN users updater ON a.updated_by = updater.user_id
    LEFT JOIN suppliers s ON a.supplier_id = s.supplier_id
    WHERE a.asset_id = ? ";

$asset_stmt = $mysqli->prepare($asset_sql);
$asset_stmt->bind_param("i", $asset_id);
$asset_stmt->execute();
$asset_result = $asset_stmt->get_result();
$asset = $asset_result->fetch_assoc();

if (!$asset) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Asset not found or has been archived.";
    header("Location: asset_management.php");
    exit;
}

// Get maintenance history
$maintenance_sql = "
    SELECT am.*, s.supplier_name, u.user_name as performed_by_name,
           DATEDIFF(CURDATE(), am.maintenance_date) as days_ago
    FROM asset_maintenance am
    LEFT JOIN suppliers s ON am.supplier_id = s.supplier_id
    LEFT JOIN users u ON am.performed_by = u.user_id
    WHERE am.asset_id = ?
    ORDER BY am.maintenance_date DESC
    LIMIT 5";
$maintenance_stmt = $mysqli->prepare($maintenance_sql);
$maintenance_stmt->bind_param("i", $asset_id);
$maintenance_stmt->execute();
$maintenance_result = $maintenance_stmt->get_result();

// Get depreciation history
$depreciation_sql = "
    SELECT ad.*, u.user_name as calculated_by_name,
           DATEDIFF(CURDATE(), ad.depreciation_date) as days_ago
    FROM asset_depreciation ad
    LEFT JOIN users u ON ad.calculated_by = u.user_id
    WHERE ad.asset_id = ?
    ORDER BY ad.depreciation_date DESC
    LIMIT 5";
$depreciation_stmt = $mysqli->prepare($depreciation_sql);
$depreciation_stmt->bind_param("i", $asset_id);
$depreciation_stmt->execute();
$depreciation_result = $depreciation_stmt->get_result();

// Get checkout history
$checkout_sql = "
    SELECT ac.*, 
           checkout.user_name as checked_out_by_name,
           CASE 
               WHEN ac.assigned_to_type = 'employee' THEN e.first_name
               ELSE 'System'
           END as assigned_to_name
    FROM asset_checkout_logs ac
    LEFT JOIN users checkout ON ac.checkout_by = checkout.user_id
    LEFT JOIN employees e ON ac.assigned_to_type = 'employee' AND ac.assigned_to_id = e.employee_id
    LEFT JOIN clients c ON ac.assigned_to_type = 'client' AND ac.assigned_to_id = c.client_id
    WHERE ac.asset_id = ?
    ORDER BY ac.checkout_date DESC
    LIMIT 5";
$checkout_stmt = $mysqli->prepare($checkout_sql);
$checkout_stmt->bind_param("i", $asset_id);
$checkout_stmt->execute();
$checkout_result = $checkout_stmt->get_result();

// Calculate depreciation
$annual_depreciation = 0;
$monthly_depreciation = 0;
$current_value = $asset['current_value'];
$purchase_price = $asset['purchase_price'];

if ($asset['depreciation_rate'] > 0 && $asset['purchase_price'] > 0) {
    $annual_depreciation = $asset['purchase_price'] * ($asset['depreciation_rate'] / 100);
    $monthly_depreciation = $annual_depreciation / 12;
}

// Status badge styling
$status_badge = '';
$status_icon = '';
switch($asset['status']) {
    case 'active': 
        $status_badge = 'success'; 
        $status_icon = 'fa-check-circle';
        break;
    case 'inactive': 
        $status_badge = 'secondary'; 
        $status_icon = 'fa-ban';
        break;
    case 'under_maintenance': 
        $status_badge = 'warning'; 
        $status_icon = 'fa-wrench';
        break;
    case 'disposed': 
        $status_badge = 'danger'; 
        $status_icon = 'fa-trash';
        break;
    case 'lost': 
        $status_badge = 'dark'; 
        $status_icon = 'fa-question-circle';
        break;
    default: 
        $status_badge = 'secondary';
        $status_icon = 'fa-question-circle';
}

// Condition badge styling
$condition_badge = '';
switch($asset['asset_condition']) {
    case 'excellent': $condition_badge = 'success'; break;
    case 'good': $condition_badge = 'info'; break;
    case 'fair': $condition_badge = 'warning'; break;
    case 'poor': $condition_badge = 'warning'; break;
    case 'critical': $condition_badge = 'danger'; break;
    default: $condition_badge = 'secondary';
}

// Get asset statistics for dashboard
$asset_stats_sql = "
    SELECT 
        COUNT(*) as total_assets,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_assets,
        SUM(CASE WHEN status = 'under_maintenance' THEN 1 ELSE 0 END) as maintenance_assets,
        SUM(CASE WHEN status = 'checked_out' THEN 1 ELSE 0 END) as checked_out_assets,
        COALESCE(SUM(purchase_price), 0) as total_purchase_value,
        COALESCE(SUM(current_value), 0) as total_current_value
    FROM assets 
";
$stats_result = $mysqli->query($asset_stats_sql);
$asset_stats = $stats_result->fetch_assoc();
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0 text-white">
                <i class="fas fa-fw fa-cube mr-2"></i>Asset Details: <?php echo htmlspecialchars($asset['asset_tag']); ?>
            </h3>
            <div class="btn-group">
                <a href="asset_management.php" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Assets
                </a>
                
                    <a href="asset_edit.php?id=<?php echo $asset_id; ?>" class="btn btn-warning ml-2">
                        <i class="fas fa-edit mr-2"></i>Edit Asset
                    </a>
               
                <div class="btn-group ml-2">
                    <button type="button" class="btn btn-info dropdown-toggle" data-toggle="dropdown">
                        <i class="fas fa-cog mr-2"></i>Actions
                    </button>
                    <div class="dropdown-menu dropdown-menu-right">
                     
                            <a class="dropdown-item" href="asset_maintenance_new.php?asset=<?php echo $asset_id; ?>">
                                <i class="fas fa-tools mr-2"></i>Schedule Maintenance
                            </a>
                       
                            <a class="dropdown-item" href="asset_checkout_new.php?asset=<?php echo $asset_id; ?>">
                                <i class="fas fa-exchange-alt mr-2"></i>Checkout/Checkin
                            </a>
                        
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item" href="asset_print.php?id=<?php echo $asset_id; ?>" target="_blank">
                            <i class="fas fa-print mr-2"></i>Print Details
                        </a>
                        <a class="dropdown-item" href="asset_qr.php?id=<?php echo $asset_id; ?>" target="_blank">
                            <i class="fas fa-qrcode mr-2"></i>Generate QR Code
                        </a>
                        <div class="dropdown-divider"></div>
                        
                            <a class="dropdown-item text-danger confirm-link" href="asset_dispose.php?id=<?php echo $asset_id; ?>">
                                <i class="fas fa-trash mr-2"></i>Mark as Disposed
                            </a>
                       
                    </div>
                </div>
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

        <!-- Asset Summary Cards -->
        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <div class="card bg-primary text-white">
                    <div class="card-body py-3">
                        <i class="fas fa-dollar-sign fa-2x mb-2"></i>
                        <h5 class="card-title">Current Value</h5>
                        <h3 class="font-weight-bold">$<?php echo number_format($asset['current_value'], 2); ?></h3>
                        <small class="opacity-8">
                            Purchase: $<?php echo number_format($asset['purchase_price'], 2); ?>
                        </small>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 mb-3">
                <div class="card bg-success text-white">
                    <div class="card-body py-3">
                        <i class="fas fa-heartbeat fa-2x mb-2"></i>
                        <h5 class="card-title">Condition</h5>
                        <h3 class="font-weight-bold"><?php echo ucfirst($asset['asset_condition']); ?></h3>
                        <small class="opacity-8">
                            <?php echo $asset['asset_condition'] == 'excellent' ? 'In perfect condition' : 'Needs attention'; ?>
                        </small>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 mb-3">
                <div class="card bg-info text-white">
                    <div class="card-body py-3">
                        <i class="fas fa-tools fa-2x mb-2"></i>
                        <h5 class="card-title">Maintenance</h5>
                        <h3 class="font-weight-bold"><?php echo $asset['total_maintenance']; ?></h3>
                        <small class="opacity-8">
                            Total maintenance records
                        </small>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 mb-3">
                <div class="card bg-<?php echo $status_badge; ?> text-white">
                    <div class="card-body py-3">
                        <i class="fas <?php echo $status_icon; ?> fa-2x mb-2"></i>
                        <h5 class="card-title">Status</h5>
                        <h3 class="font-weight-bold"><?php echo ucfirst(str_replace('_', ' ', $asset['status'])); ?></h3>
                        <small class="opacity-8">
                            <?php echo $asset['currently_checked_out'] ? 'Currently checked out' : 'Available'; ?>
                        </small>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Left Column -->
            <div class="col-lg-8">
                <!-- Asset Information -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h4 class="card-title"><i class="fas fa-info-circle mr-2"></i>Asset Information</h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <th width="40%">Asset Tag:</th>
                                        <td class="font-weight-bold text-primary"><?php echo htmlspecialchars($asset['asset_tag']); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Asset Name:</th>
                                        <td><?php echo htmlspecialchars($asset['asset_name']); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Category:</th>
                                        <td>
                                            <?php echo htmlspecialchars($asset['category_name']); ?>
                                            <?php if ($asset['depreciation_rate']): ?>
                                                <small class="text-muted d-block">
                                                    <?php echo $asset['depreciation_rate']; ?>% depreciation rate
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php if ($asset['asset_description']): ?>
                                    <tr>
                                        <th>Description:</th>
                                        <td><?php echo nl2br(htmlspecialchars($asset['asset_description'])); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                    <tr>
                                        <th>Status:</th>
                                        <td>
                                            <span class="badge badge-<?php echo $status_badge; ?> badge-pill">
                                                <i class="fas <?php echo $status_icon; ?> mr-1"></i>
                                                <?php echo ucfirst(str_replace('_', ' ', $asset['status'])); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Condition:</th>
                                        <td>
                                            <span class="badge badge-<?php echo $condition_badge; ?>">
                                                <?php echo ucfirst($asset['asset_condition']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <th width="40%">Serial Number:</th>
                                        <td><?php echo htmlspecialchars($asset['serial_number'] ?: 'N/A'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Model:</th>
                                        <td><?php echo htmlspecialchars($asset['model'] ?: 'N/A'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Manufacturer:</th>
                                        <td><?php echo htmlspecialchars($asset['manufacturer'] ?: 'N/A'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Location:</th>
                                        <td>
                                            <?php if ($asset['location_name']): ?>
                                                <?php echo htmlspecialchars($asset['location_name']); ?>
                                                <small class="text-muted d-block">
                                                    <?php if ($asset['building']): ?><?php echo htmlspecialchars($asset['building']); ?><?php endif; ?>
                                                    <?php if ($asset['floor']): ?>, Floor: <?php echo htmlspecialchars($asset['floor']); ?><?php endif; ?>
                                                    <?php if ($asset['room_number']): ?>, Room: <?php echo htmlspecialchars($asset['room_number']); ?><?php endif; ?>
                                                </small>
                                            <?php else: ?>
                                                <span class="text-muted">Not assigned</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Assigned To:</th>
                                        <td>
                                            <?php if ($asset['assigned_user_name']): ?>
                                                <?php echo htmlspecialchars($asset['assigned_user_name']); ?>
                                            <?php else: ?>
                                                <span class="text-muted">Unassigned</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Purchase & Financial Information -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h4 class="card-title"><i class="fas fa-shopping-cart mr-2"></i>Purchase & Financial Information</h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <th width="40%">Purchase Date:</th>
                                        <td>
                                            <?php if ($asset['purchase_date']): ?>
                                                <?php echo date('F j, Y', strtotime($asset['purchase_date'])); ?>
                                                <small class="text-muted d-block">
                                                    (<?php echo $asset['months_owned']; ?> months ago)
                                                </small>
                                            <?php else: ?>
                                                <span class="text-muted">Not set</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Purchase Price:</th>
                                        <td class="text-success font-weight-bold">
                                            <?php if ($asset['purchase_price'] > 0): ?>
                                                $<?php echo number_format($asset['purchase_price'], 2); ?>
                                            <?php else: ?>
                                                <span class="text-muted">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Current Value:</th>
                                        <td class="font-weight-bold text-primary">
                                            $<?php echo number_format($asset['current_value'], 2); ?>
                                            <?php if ($asset['purchase_price'] > 0 && $asset['current_value'] < $asset['purchase_price']): ?>
                                                <small class="text-danger d-block">
                                                    Depreciation: $<?php echo number_format($asset['purchase_price'] - $asset['current_value'], 2); ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Supplier:</th>
                                        <td>
                                            <?php if ($asset['supplier_name']): ?>
                                                <?php echo htmlspecialchars($asset['supplier_name']); ?>
                                                <?php if ($asset['supplier_contact'] ?? 'NA'): ?>
                                                    <small class="text-muted d-block">
                                                        <i class="fas fa-user mr-1"></i>
                                                        <?php echo htmlspecialchars($asset['supplier_contact'] ?? 'NA'); ?>
                                                    </small>
                                                <?php endif; ?>
                                                <?php if ($asset['supplier_phone']): ?>
                                                    <small class="text-muted d-block">
                                                        <i class="fas fa-phone mr-1"></i>
                                                        <?php echo htmlspecialchars($asset['supplier_phone']); ?>
                                                    </small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">Not set</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <th width="40%">Warranty Expiry:</th>
                                        <td>
                                            <?php if ($asset['warranty_expiry']): ?>
                                                <?php echo date('F j, Y', strtotime($asset['warranty_expiry'])); ?>
                                                <?php if ($asset['days_to_warranty_expiry'] > 0): ?>
                                                    <span class="badge badge-<?php echo $asset['days_to_warranty_expiry'] <= 30 ? 'warning' : 'success'; ?>">
                                                        <?php echo $asset['days_to_warranty_expiry']; ?> days left
                                                    </span>
                                                <?php elseif ($asset['days_to_warranty_expiry'] == 0): ?>
                                                    <span class="badge badge-warning">Expires today</span>
                                                <?php else: ?>
                                                    <span class="badge badge-danger">Expired</span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">No warranty</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php if ($asset['depreciation_rate'] > 0 && $asset['purchase_price'] > 0): ?>
                                    <tr>
                                        <th>Depreciation Rate:</th>
                                        <td class="text-danger">
                                            <?php echo $asset['depreciation_rate']; ?>% per year
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Annual Depreciation:</th>
                                        <td class="text-danger">
                                            $<?php echo number_format($annual_depreciation, 2); ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Monthly Depreciation:</th>
                                        <td class="text-danger">
                                            $<?php echo number_format($monthly_depreciation, 2); ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Useful Life:</th>
                                        <td><?php echo $asset['useful_life_years']; ?> years</td>
                                    </tr>
                                    <?php endif; ?>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Maintenance Information -->
                <div class="card mb-4">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <h4 class="card-title"><i class="fas fa-tools mr-2"></i>Maintenance Information</h4>
                            
                                <a href="asset_maintenance_new.php?asset=<?php echo $asset_id; ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-plus mr-1"></i>New Maintenance
                                </a>
                        
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <div class="info-box bg-light">
                                    <span class="info-box-icon"><i class="fas fa-calendar-check"></i></span>
                                    <div class="info-box-content">
                                        <span class="info-box-text">Last Maintenance</span>
                                        <span class="info-box-number">
                                            <?php if ($asset['last_maintenance_date']): ?>
                                                <?php echo date('M j, Y', strtotime($asset['last_maintenance_date'])); ?>
                                            <?php else: ?>
                                                <span class="text-muted">Never</span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-box bg-light">
                                    <span class="info-box-icon"><i class="fas fa-calendar-alt"></i></span>
                                    <div class="info-box-content">
                                        <span class="info-box-text">Next Maintenance</span>
                                        <span class="info-box-number">
                                            <?php if ($asset['next_maintenance_date']): ?>
                                                <?php echo date('M j, Y', strtotime($asset['next_maintenance_date'])); ?>
                                                <?php if ($asset['days_to_maintenance'] !== null): ?>
                                                    <span class="badge badge-<?php 
                                                        if ($asset['days_to_maintenance'] < 0) echo 'danger';
                                                        elseif ($asset['days_to_maintenance'] <= 7) echo 'warning';
                                                        elseif ($asset['days_to_maintenance'] <= 30) echo 'info';
                                                        else echo 'success';
                                                    ?> ml-2">
                                                        <?php 
                                                        if ($asset['days_to_maintenance'] < 0) echo 'Overdue';
                                                        elseif ($asset['days_to_maintenance'] == 0) echo 'Today';
                                                        else echo $asset['days_to_maintenance'] . ' days';
                                                        ?>
                                                    </span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">Not scheduled</span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <h5 class="mb-3">Recent Maintenance History</h5>
                        <?php if ($maintenance_result->num_rows > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Type</th>
                                            <th>Description</th>
                                            <th>Cost</th>
                                            <th>Status</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($maintenance = $maintenance_result->fetch_assoc()): ?>
                                            <tr>
                                                <td>
                                                    <?php echo date('M j, Y', strtotime($maintenance['maintenance_date'])); ?>
                                                    <?php if ($maintenance['days_ago'] == 0): ?>
                                                        <small class="d-block text-success">Today</small>
                                                    <?php elseif ($maintenance['days_ago'] <= 7): ?>
                                                        <small class="d-block text-info"><?php echo $maintenance['days_ago']; ?> days ago</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge badge-<?php 
                                                        switch($maintenance['maintenance_type']) {
                                                            case 'preventive': echo 'primary'; break;
                                                            case 'corrective': echo 'danger'; break;
                                                            case 'calibration': echo 'info'; break;
                                                            case 'inspection': echo 'warning'; break;
                                                            default: echo 'secondary';
                                                        }
                                                    ?>">
                                                        <?php echo ucfirst($maintenance['maintenance_type']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars(substr($maintenance['description'], 0, 30)); ?>
                                                    <?php if (strlen($maintenance['description']) > 30): ?>...<?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($maintenance['cost'] > 0): ?>
                                                        <span class="text-success">$<?php echo number_format($maintenance['cost'], 2); ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">$0.00</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge badge-<?php 
                                                        switch($maintenance['status']) {
                                                            case 'completed': echo 'success'; break;
                                                            case 'in_progress': echo 'primary'; break;
                                                            case 'scheduled': echo 'warning'; break;
                                                            case 'cancelled': echo 'secondary'; break;
                                                            default: echo 'light';
                                                        }
                                                    ?>">
                                                        <?php echo ucfirst($maintenance['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <a href="asset_maintenance_view.php?id=<?php echo $maintenance['maintenance_id']; ?>" class="btn btn-sm btn-outline-info">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="text-center mt-2">
                                <a href="asset_maintenance.php?asset=<?php echo $asset_id; ?>" class="btn btn-sm btn-outline-primary">
                                    View All Maintenance Records
                                </a>
                            </div>
                        <?php else: ?>
                            <p class="text-muted text-center mb-0">
                                <i class="fas fa-info-circle mr-1"></i>
                                No maintenance records found for this asset.
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Right Column -->
            <div class="col-lg-4">
             <!-- Checkout History -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h4 class="card-title"><i class="fas fa-history mr-2"></i>Recent Checkouts</h4>
                    </div>
                    <div class="card-body">
                        <?php if ($checkout_result->num_rows > 0): ?>
                            <div class="list-group list-group-flush">
                                <?php while ($checkout = $checkout_result->fetch_assoc()): ?>
                                    <div class="list-group-item px-0 py-2">
                                        <div class="d-flex w-100 justify-content-between align-items-center">
                                            <div>
                                                <h6 class="mb-1"><?php echo htmlspecialchars($checkout['assigned_to_name']); ?></h6>
                                                <small class="text-muted">
                                                    <?php echo date('M j, Y', strtotime($checkout['checkout_date'])); ?>
                                                </small>
                                            </div>
                                            <div>
                                                <?php if ($checkout['checkin_date']): ?>
                                                    <span class="badge badge-success badge-pill">
                                                        <i class="fas fa-check mr-1"></i>Returned
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge badge-warning badge-pill">
                                                        <i class="fas fa-clock mr-1"></i>Checked Out
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            </div>
                            <div class="text-center mt-2">
                                <a href="asset_checkout.php?asset=<?php echo $asset_id; ?>" class="btn btn-sm btn-outline-primary">
                                    View All Checkouts
                                </a>
                            </div>
                        <?php else: ?>
                            <p class="text-muted text-center mb-0">
                                <i class="fas fa-info-circle mr-1"></i>
                                No checkout history found.
                            </p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Depreciation History -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h4 class="card-title"><i class="fas fa-chart-line mr-2"></i>Depreciation History</h4>
                    </div>
                    <div class="card-body">
                        <?php if ($depreciation_result->num_rows > 0): ?>
                            <div class="list-group list-group-flush">
                                <?php while ($depreciation = $depreciation_result->fetch_assoc()): ?>
                                    <div class="list-group-item px-0 py-2">
                                        <div class="d-flex w-100 justify-content-between align-items-center">
                                            <div>
                                                <h6 class="mb-1"><?php echo date('M j, Y', strtotime($depreciation['depreciation_date'])); ?></h6>
                                                <small class="text-muted">
                                                    Calculated by <?php echo htmlspecialchars($depreciation['calculated_by_name']); ?>
                                                </small>
                                            </div>
                                            <div class="text-right">
                                                <span class="text-danger">-$<?php echo number_format($depreciation['depreciation_amount'], 2); ?></span>
                                                <div class="text-success">$<?php echo number_format($depreciation['current_value'], 2); ?></div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-muted text-center mb-0">
                                <i class="fas fa-chart-line mr-1"></i>
                                No depreciation history available.
                            </p>
                    
                                <div class="text-center mt-2">
                                    <button class="btn btn-sm btn-outline-primary" onclick="calculateDepreciation()">
                                        Calculate Depreciation
                                    </button>
                                </div>
                            
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Asset Statistics -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h4 class="card-title"><i class="fas fa-chart-bar mr-2"></i>Asset Statistics</h4>
                    </div>
                    <div class="card-body">
                        <div class="list-group list-group-flush">
                            <div class="list-group-item px-0 py-2">
                                <div class="d-flex w-100 justify-content-between">
                                    <span>Total Assets in System:</span>
                                    <span class="badge badge-primary badge-pill"><?php echo $asset_stats['total_assets']; ?></span>
                                </div>
                            </div>
                            <div class="list-group-item px-0 py-2">
                                <div class="d-flex w-100 justify-content-between">
                                    <span>Active Assets:</span>
                                    <span class="badge badge-success badge-pill"><?php echo $asset_stats['active_assets']; ?></span>
                                </div>
                            </div>
                            <div class="list-group-item px-0 py-2">
                                <div class="d-flex w-100 justify-content-between">
                                    <span>Under Maintenance:</span>
                                    <span class="badge badge-warning badge-pill"><?php echo $asset_stats['maintenance_assets']; ?></span>
                                </div>
                            </div>
                            <div class="list-group-item px-0 py-2">
                                <div class="d-flex w-100 justify-content-between">
                                    <span>Checked Out:</span>
                                    <span class="badge badge-info badge-pill"><?php echo $asset_stats['checked_out_assets']; ?></span>
                                </div>
                            </div>
                            <div class="list-group-item px-0 py-2">
                                <div class="d-flex w-100 justify-content-between">
                                    <span>Total System Value:</span>
                                    <span class="badge badge-dark badge-pill">$<?php echo number_format($asset_stats['total_current_value'], 0); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Asset Metadata -->
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title"><i class="fas fa-info-circle mr-2"></i>Record Information</h4>
                    </div>
                    <div class="card-body">
                        <div class="small">
                            <div class="d-flex justify-content-between mb-2">
                                <span>Created:</span>
                                <span><?php echo date('M j, Y H:i', strtotime($asset['created_at'])); ?></span>
                            </div>
                            <?php if ($asset['created_by_name']): ?>
                            <div class="d-flex justify-content-between mb-2">
                                <span>By:</span>
                                <span><?php echo htmlspecialchars($asset['created_by_name']); ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if ($asset['updated_at'] && $asset['updated_at'] != $asset['created_at']): ?>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Last Updated:</span>
                                <span><?php echo date('M j, Y H:i', strtotime($asset['updated_at'])); ?></span>
                            </div>
                            <?php if ($asset['updated_by_name']): ?>
                            <div class="d-flex justify-content-between">
                                <span>By:</span>
                                <span><?php echo htmlspecialchars($asset['updated_by_name']); ?></span>
                            </div>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card-footer">
        <div class="row">
            <div class="col-md-6">
                <small class="text-muted">
                    Asset ID: <?php echo str_pad($asset_id, 6, '0', STR_PAD_LEFT); ?>
                </small>
            </div>
            <div class="col-md-6 text-right">
                <small class="text-muted">
                    Last accessed: <?php echo date('F j, Y, g:i a'); ?>
                </small>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Confirm action links
    $('.confirm-link').click(function(e) {
        if (!confirm('Are you sure you want to mark this asset as disposed? This action cannot be undone.')) {
            e.preventDefault();
        }
    });

    // Initialize tooltips
    $('[data-toggle="tooltip"]').tooltip();
});

function calculateDepreciation() {
    if (confirm('Calculate depreciation for this asset? This will update the current value based on the depreciation rate.')) {
        $.ajax({
            url: 'asset_ajax_depreciation.php',
            method: 'POST',
            data: {
                asset_id: <?php echo $asset_id; ?>,
                csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'
            },
            beforeSend: function() {
                // Show loading indicator
                $('button[onclick="calculateDepreciation()"]').html('<i class="fas fa-spinner fa-spin mr-1"></i> Calculating...').prop('disabled', true);
            },
            success: function(response) {
                try {
                    const result = JSON.parse(response);
                    if (result.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + result.message);
                    }
                } catch (e) {
                    alert('Error parsing response.');
                }
            },
            error: function() {
                alert('Error calculating depreciation. Please try again.');
            },
            complete: function() {
                // Restore button
                $('button[onclick="calculateDepreciation()"]').html('Calculate Depreciation').prop('disabled', false);
            }
        });
    }
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + E to edit
    if (e.ctrlKey && e.keyCode === 69) {
        e.preventDefault();
        window.location.href = 'asset_edit.php?id=<?php echo $asset_id; ?>';
    }
    // Ctrl + M for maintenance
    if (e.ctrlKey && e.keyCode === 77) {
        e.preventDefault();
        window.location.href = 'asset_maintenance_new.php?asset=<?php echo $asset_id; ?>';
    }
    // Ctrl + C for checkout
    if (e.ctrlKey && e.keyCode === 67) {
        e.preventDefault();
        window.location.href = 'asset_checkout_new.php?asset=<?php echo $asset_id; ?>';
    }
    // Ctrl + P to print
    if (e.ctrlKey && e.keyCode === 80) {
        e.preventDefault();
        window.open('asset_print.php?id=<?php echo $asset_id; ?>', '_blank');
    }
    // Escape to go back
    if (e.keyCode === 27) {
        window.location.href = 'asset_management.php';
    }
});
</script>

<style>
.info-box {
    margin-bottom: 10px;
}

.info-box .info-box-icon {
    border-radius: 0.25rem;
}

.badge-pill {
    padding: 0.5em 0.8em;
}

.list-group-item {
    border: none;
    padding: 0.75rem 0;
}

.list-group-item:hover {
    background-color: #f8f9fa;
}

.btn-group .dropdown-menu {
    min-width: 200px;
}

.table th {
    font-weight: 600;
    color: #495057;
}

.card {
    margin-bottom: 20px;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
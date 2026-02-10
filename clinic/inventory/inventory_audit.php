<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Audit parameters
$start_date = sanitizeInput($_GET['start_date'] ?? date('Y-m-01'));
$end_date = sanitizeInput($_GET['end_date'] ?? date('Y-m-d'));
$audit_type = sanitizeInput($_GET['audit_type'] ?? 'transactions');
$item_filter = $_GET['item'] ?? '';
$location_filter = $_GET['location'] ?? '';
$user_filter = $_GET['user'] ?? '';
$transaction_type_filter = $_GET['transaction_type'] ?? '';
$batch_filter = $_GET['batch'] ?? '';

// Validate dates
if (!strtotime($start_date)) $start_date = date('Y-m-01');
if (!strtotime($end_date)) $end_date = date('Y-m-d');

// Get audit summary statistics
$summary_sql = mysqli_query($mysqli,
    "SELECT 
        COUNT(*) as total_transactions,
        COUNT(DISTINCT it.item_id) as unique_items,
        COUNT(DISTINCT it.created_by) as unique_users,
        COUNT(DISTINCT it.from_location_id) as unique_from_locations,
        COUNT(DISTINCT it.to_location_id) as unique_to_locations,
        SUM(CASE WHEN it.transaction_type IN ('GRN', 'TRANSFER_IN') THEN it.quantity ELSE 0 END) as total_incoming,
        SUM(CASE WHEN it.transaction_type IN ('ISSUE', 'TRANSFER_OUT', 'WASTAGE') THEN it.quantity ELSE 0 END) as total_outgoing,
        SUM(CASE WHEN it.transaction_type = 'ADJUSTMENT' THEN ABS(it.quantity) ELSE 0 END) as total_adjustments,
        SUM(it.quantity * it.unit_cost) as total_value_moved
    FROM inventory_transactions it
    WHERE it.created_at BETWEEN '$start_date 00:00:00' AND '$end_date 23:59:59'
        AND it.is_active = 1"
);
$summary = mysqli_fetch_assoc($summary_sql);

// Get transaction types summary
$types_summary_sql = mysqli_query($mysqli,
    "SELECT 
        it.transaction_type,
        COUNT(*) as count,
        SUM(it.quantity) as total_quantity,
        SUM(it.quantity * it.unit_cost) as total_value
    FROM inventory_transactions it
    WHERE it.created_at BETWEEN '$start_date 00:00:00' AND '$end_date 23:59:59'
        AND it.is_active = 1
    GROUP BY it.transaction_type
    ORDER BY count DESC"
);

// Get transactions - main audit log
$transactions_sql = mysqli_query($mysqli,
    "SELECT 
        it.transaction_id,
        it.transaction_type,
        it.created_at,
        it.quantity,
        it.unit_cost,
        (it.quantity * it.unit_cost) as total_value,
        it.reason,
        it.reference_type,
        it.reference_id,
        
        i.item_id,
        i.item_name,
        i.item_code,
        
        ib.batch_id,
        ib.batch_number,
        
        c.category_name,
        
        fl.location_name as from_location,
        tl.location_name as to_location,
        
        u.user_name as created_by_name
    FROM inventory_transactions it
    LEFT JOIN inventory_items i ON it.item_id = i.item_id
    LEFT JOIN inventory_batches ib ON it.batch_id = ib.batch_id
    LEFT JOIN inventory_categories c ON i.category_id = c.category_id
    LEFT JOIN inventory_locations fl ON it.from_location_id = fl.location_id
    LEFT JOIN inventory_locations tl ON it.to_location_id = tl.location_id
    LEFT JOIN users u ON it.created_by = u.user_id
    WHERE it.created_at BETWEEN '$start_date 00:00:00' AND '$end_date 23:59:59'
        AND it.is_active = 1
        " . ($item_filter ? " AND i.item_id = " . intval($item_filter) : "") . "
        " . ($location_filter ? " AND (it.from_location_id = " . intval($location_filter) . " OR it.to_location_id = " . intval($location_filter) . ")" : "") . "
        " . ($user_filter ? " AND it.created_by = " . intval($user_filter) : "") . "
        " . ($transaction_type_filter ? " AND it.transaction_type = '" . mysqli_real_escape_string($mysqli, $transaction_type_filter) . "'" : "") . "
        " . ($batch_filter ? " AND ib.batch_number LIKE '%" . mysqli_real_escape_string($mysqli, $batch_filter) . "%'" : "") . "
    ORDER BY it.created_at DESC
    LIMIT 200"
);

// Get stock adjustments audit
$adjustments_sql = mysqli_query($mysqli,
    "SELECT 
        it.transaction_id,
        it.created_at,
        it.quantity,
        it.unit_cost,
        it.reason,
        
        i.item_name,
        i.item_code,
        
        ib.batch_number,
        
        fl.location_name as location_name,
        
        u.user_name as adjusted_by
    FROM inventory_transactions it
    LEFT JOIN inventory_items i ON it.item_id = i.item_id
    LEFT JOIN inventory_batches ib ON it.batch_id = ib.batch_id
    LEFT JOIN inventory_locations fl ON it.from_location_id = fl.location_id
    LEFT JOIN users u ON it.created_by = u.user_id
    WHERE it.transaction_type = 'ADJUSTMENT'
        AND it.created_at BETWEEN '$start_date 00:00:00' AND '$end_date 23:59:59'
        AND it.is_active = 1
    ORDER BY it.created_at DESC
    LIMIT 100"
);

// Get stock discrepancy report
$discrepancy_sql = mysqli_query($mysqli,
    "SELECT 
        i.item_id,
        i.item_name,
        i.item_code,
        c.category_name,

        SUM(ici.counted_quantity) AS physical_count,
        SUM(ici.system_quantity) AS system_count,

        SUM(ici.counted_quantity) - SUM(ici.system_quantity) AS variance,
        ABS(SUM(ici.counted_quantity) - SUM(ici.system_quantity)) AS abs_variance,

        CASE 
            WHEN SUM(ici.system_quantity) = 0 THEN 0
            ELSE ROUND(
                ABS(SUM(ici.counted_quantity) - SUM(ici.system_quantity))
                / SUM(ici.system_quantity) * 100,
                2
            )
        END AS variance_percent,

        AVG(ici.unit_cost) AS avg_cost,
        SUM(ABS(ici.variance_quantity) * ici.unit_cost) AS value_variance

    FROM inventory_count_items ici
    INNER JOIN inventory_counts ic 
        ON ici.count_id = ic.count_id
    INNER JOIN inventory_items i 
        ON ici.item_id = i.item_id
    LEFT JOIN inventory_categories c 
        ON i.category_id = c.category_id

    WHERE ic.count_date BETWEEN '$start_date' AND '$end_date'
      AND ic.is_active = 1
      AND ici.is_active = 1
      AND ic.status IN ('completed', 'reviewed', 'approved')

    GROUP BY 
        i.item_id,
        i.item_name,
        i.item_code,
        c.category_name

    HAVING ABS(SUM(ici.counted_quantity) - SUM(ici.system_quantity)) > 0

    ORDER BY value_variance DESC
    LIMIT 50"
);

// Get user activity report
$user_activity_sql = mysqli_query($mysqli,
    "SELECT 
        u.user_id,
        u.user_name,
        COUNT(it.transaction_id) as transaction_count,
        SUM(CASE WHEN it.transaction_type IN ('GRN', 'TRANSFER_IN') THEN it.quantity ELSE 0 END) as total_incoming,
        SUM(CASE WHEN it.transaction_type IN ('ISSUE', 'TRANSFER_OUT', 'WASTAGE') THEN it.quantity ELSE 0 END) as total_outgoing,
        SUM(it.quantity * it.unit_cost) as total_value_handled,
        MIN(it.created_at) as first_activity,
        MAX(it.created_at) as last_activity
    FROM users u
    LEFT JOIN inventory_transactions it ON u.user_id = it.created_by
        AND it.created_at BETWEEN '$start_date 00:00:00' AND '$end_date 23:59:59'
        AND it.is_active = 1
    
        AND u.user_id IN (SELECT DISTINCT created_by FROM inventory_transactions)
    GROUP BY u.user_id, u.user_name
    ORDER BY transaction_count DESC
    LIMIT 20"
);

// Get batch audit trail
$batch_audit_sql = mysqli_query($mysqli,
    "SELECT 
        ib.batch_id,
        ib.batch_number,
        i.item_name,
        ib.expiry_date,
        ib.received_date,
        s.supplier_name,
        
        SUM(CASE WHEN it.transaction_type = 'GRN' THEN it.quantity ELSE 0 END) as total_received,
        SUM(CASE WHEN it.transaction_type IN ('ISSUE', 'WASTAGE', 'TRANSFER_OUT') THEN it.quantity ELSE 0 END) as total_consumed,
        SUM(CASE WHEN it.transaction_type = 'ADJUSTMENT' THEN it.quantity ELSE 0 END) as total_adjusted,
        
        COALESCE(ils.total_current, 0) as current_stock,
        
        COUNT(DISTINCT it.transaction_id) as transaction_count,
        MIN(it.created_at) as first_transaction,
        MAX(it.created_at) as last_transaction
    FROM inventory_batches ib
    LEFT JOIN inventory_items i ON ib.item_id = i.item_id
    LEFT JOIN suppliers s ON ib.supplier_id = s.supplier_id
    LEFT JOIN inventory_transactions it ON ib.batch_id = it.batch_id
        AND it.created_at BETWEEN '$start_date 00:00:00' AND '$end_date 23:59:59'
        AND it.is_active = 1
    LEFT JOIN (
        SELECT 
            batch_id,
            SUM(quantity) as total_current
        FROM inventory_location_stock
        WHERE is_active = 1
        GROUP BY batch_id
    ) ils ON ib.batch_id = ils.batch_id
    WHERE ib.is_active = 1
        AND (ib.received_date BETWEEN '$start_date' AND '$end_date'
            OR it.created_at BETWEEN '$start_date 00:00:00' AND '$end_date 23:59:59')
    GROUP BY ib.batch_id, ib.batch_number, i.item_name, ib.expiry_date, ib.received_date, s.supplier_name, ils.total_current
    ORDER BY ib.received_date DESC
    LIMIT 50"
);

// Get items for filter
$items_sql = mysqli_query($mysqli,
    "SELECT item_id, item_name, item_code 
    FROM inventory_items 
    WHERE is_active = 1 AND status = 'active'
    ORDER BY item_name"
);

// Get locations for filter
$locations_sql = mysqli_query($mysqli,
    "SELECT location_id, location_name, location_type 
    FROM inventory_locations 
    WHERE is_active = 1 
    ORDER BY location_type, location_name"
);

// Get users for filter
$users_sql = mysqli_query($mysqli,
    "SELECT user_id, user_name 
    FROM users 
  
    ORDER BY user_name"
);

// Get transaction types for filter
$transaction_types = ['GRN', 'ISSUE', 'TRANSFER_OUT', 'TRANSFER_IN', 'ADJUSTMENT', 'WASTAGE', 'RETURN'];
?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2 mb-0"><i class="fas fa-fw fa-clipboard-check mr-2"></i>Inventory Audit Trail</h3>
        <div class="card-tools">
            <div class="btn-group">
                <button type="button" class="btn btn-success dropdown-toggle" data-toggle="dropdown">
                    <i class="fas fa-download mr-2"></i>Export Audit
                </button>
                <div class="dropdown-menu">
                    <a class="dropdown-item" href="?<?php echo http_build_query($_GET); ?>&export=pdf">
                        <i class="fas fa-file-pdf mr-2"></i>PDF Report
                    </a>
                    <a class="dropdown-item" href="?<?php echo http_build_query($_GET); ?>&export=excel">
                        <i class="fas fa-file-excel mr-2"></i>Excel Export
                    </a>
                    <a class="dropdown-item" href="?<?php echo http_build_query($_GET); ?>&export=csv">
                        <i class="fas fa-file-csv mr-2"></i>CSV Export
                    </a>
                </div>
                <a href="inventory_reports.php" class="btn btn-info ml-2">
                    <i class="fas fa-chart-bar mr-2"></i>View Reports
                </a>
            </div>
        </div>
    </div>

    <!-- Audit Filters -->
    <div class="card-header pb-2 pt-3">
        <form autocomplete="off">
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php if (isset($_GET['q'])) { echo stripslashes(nullable_htmlentities($_GET['q'])); } ?>" placeholder="Search audit trail..." autofocus>
                            <div class="input-group-append">
                                <button class="btn btn-secondary" type="button" data-toggle="collapse" data-target="#advancedFilter"><i class="fas fa-filter"></i></button>
                                <button class="btn btn-primary"><i class="fa fa-search"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="btn-toolbar form-group float-right">
                        <div class="btn-group">
                            <a href="?<?php echo http_build_query($_GET); ?>&export=pdf" class="btn btn-default">
                                <i class="fa fa-fw fa-file-pdf mr-2"></i>Export Audit
                            </a>
                            <a href="inventory_counts.php" class="btn btn-warning ml-2">
                                <i class="fas fa-clipboard-list mr-2"></i>Stock Counts
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse 
                    <?php 
                    if (isset($_GET['start_date']) || isset($_GET['end_date']) || $item_filter || $location_filter || $user_filter || $transaction_type_filter || $batch_filter) { 
                        echo "show"; 
                    } 
                    ?>"
                id="advancedFilter">
                <div class="row">
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Audit Type</label>
                            <select class="form-control select2" name="audit_type" onchange="this.form.submit()">
                                <option value="transactions" <?php echo $audit_type == 'transactions' ? 'selected' : ''; ?>>Transactions</option>
                                <option value="adjustments" <?php echo $audit_type == 'adjustments' ? 'selected' : ''; ?>>Adjustments</option>
                                <option value="discrepancy" <?php echo $audit_type == 'discrepancy' ? 'selected' : ''; ?>>Discrepancy</option>
                                <option value="user_activity" <?php echo $audit_type == 'user_activity' ? 'selected' : ''; ?>>User Activity</option>
                                <option value="batch_trail" <?php echo $audit_type == 'batch_trail' ? 'selected' : ''; ?>>Batch Trail</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Start Date</label>
                            <input type="date" class="form-control" name="start_date" value="<?php echo $start_date; ?>" onchange="this.form.submit()">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>End Date</label>
                            <input type="date" class="form-control" name="end_date" value="<?php echo $end_date; ?>" onchange="this.form.submit()">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Item</label>
                            <select class="form-control select2" name="item" onchange="this.form.submit()">
                                <option value="">- All Items -</option>
                                <?php
                                while($item = mysqli_fetch_assoc($items_sql)) {
                                    $item_id = intval($item['item_id']);
                                    $item_name = nullable_htmlentities($item['item_name']);
                                    $item_code = nullable_htmlentities($item['item_code']);
                                    $display_name = "$item_name ($item_code)";
                                    $selected = $item_filter == $item_id ? 'selected' : '';
                                    echo "<option value='$item_id' $selected>$display_name</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Location</label>
                            <select class="form-control select2" name="location" onchange="this.form.submit()">
                                <option value="">- All Locations -</option>
                                <?php
                                while($location = mysqli_fetch_assoc($locations_sql)) {
                                    $location_id = intval($location['location_id']);
                                    $location_name = nullable_htmlentities($location['location_name']);
                                    $location_type = nullable_htmlentities($location['location_type']);
                                    $display_name = "$location_type - $location_name";
                                    $selected = $location_filter == $location_id ? 'selected' : '';
                                    echo "<option value='$location_id' $selected>$display_name</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>User</label>
                            <select class="form-control select2" name="user" onchange="this.form.submit()">
                                <option value="">- All Users -</option>
                                <?php
                                while($user = mysqli_fetch_assoc($users_sql)) {
                                    $user_id = intval($user['user_id']);
                                    $user_name = nullable_htmlentities($user['user_name']);
                                    $selected = $user_filter == $user_id ? 'selected' : '';
                                    echo "<option value='$user_id' $selected>$user_name</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Transaction Type</label>
                            <select class="form-control select2" name="transaction_type" onchange="this.form.submit()">
                                <option value="">- All Types -</option>
                                <?php
                                foreach ($transaction_types as $type) {
                                    $selected = $transaction_type_filter == $type ? 'selected' : '';
                                    echo "<option value='$type' $selected>$type</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Batch Number</label>
                            <input type="text" class="form-control" name="batch" value="<?php echo htmlspecialchars($batch_filter); ?>" placeholder="Enter batch number..." onchange="this.form.submit()">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Quick Audit Links</label>
                            <div class="btn-group btn-group-toggle" data-toggle="buttons">
                                <a href="?audit_type=transactions" class="btn btn-outline-primary btn-sm <?php echo $audit_type == 'transactions' ? 'active' : ''; ?>">
                                    <i class="fas fa-exchange-alt mr-1"></i> Transactions
                                </a>
                                <a href="?audit_type=adjustments&start_date=<?php echo date('Y-m-01'); ?>&end_date=<?php echo date('Y-m-d'); ?>" class="btn btn-outline-warning btn-sm <?php echo $audit_type == 'adjustments' ? 'active' : ''; ?>">
                                    <i class="fas fa-adjust mr-1"></i> Adjustments
                                </a>
                                <a href="?audit_type=discrepancy" class="btn btn-outline-danger btn-sm <?php echo $audit_type == 'discrepancy' ? 'active' : ''; ?>">
                                    <i class="fas fa-exclamation-triangle mr-1"></i> Discrepancy
                                </a>
                                <a href="?audit_type=user_activity&start_date=<?php echo date('Y-m-01'); ?>&end_date=<?php echo date('Y-m-d'); ?>" class="btn btn-outline-info btn-sm <?php echo $audit_type == 'user_activity' ? 'active' : ''; ?>">
                                    <i class="fas fa-users mr-1"></i> User Activity
                                </a>
                                <a href="?audit_type=batch_trail" class="btn btn-outline-success btn-sm <?php echo $audit_type == 'batch_trail' ? 'active' : ''; ?>">
                                    <i class="fas fa-box mr-1"></i> Batch Trail
                                </a>
                                <a href="inventory_audit.php" class="btn btn-outline-dark btn-sm">
                                    <i class="fas fa-times mr-1"></i> Clear
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- Audit Summary -->
    <div class="card-body">
        <div class="row mb-4">
            <div class="col-12">
                <h5 class="text-dark mb-3"><i class="fas fa-chart-line mr-2"></i>Audit Summary (<?php echo date('M j', strtotime($start_date)); ?> - <?php echo date('M j', strtotime($end_date)); ?>)</h5>
            </div>
            <div class="col-md-2 col-6 mb-3">
                <div class="info-box bg-light h-100">
                    <span class="info-box-icon bg-primary"><i class="fas fa-exchange-alt"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Transactions</span>
                        <span class="info-box-number"><?php echo number_format($summary['total_transactions']); ?></span>
                        <small class="text-muted">All inventory movements</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2 col-6 mb-3">
                <div class="info-box bg-light h-100">
                    <span class="info-box-icon bg-success"><i class="fas fa-arrow-down"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Incoming</span>
                        <span class="info-box-number"><?php echo number_format($summary['total_incoming'] ?? 0); ?></span>
                        <small class="text-muted">Items received</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2 col-6 mb-3">
                <div class="info-box bg-light h-100">
                    <span class="info-box-icon bg-danger"><i class="fas fa-arrow-up"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Outgoing</span>
                        <span class="info-box-number"><?php echo number_format($summary['total_outgoing'] ?? 0); ?></span>
                        <small class="text-muted">Items issued/used</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2 col-6 mb-3">
                <div class="info-box bg-light h-100">
                    <span class="info-box-icon bg-warning"><i class="fas fa-adjust"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Adjustments</span>
                        <span class="info-box-number"><?php echo number_format($summary['total_adjustments'] ?? 0); ?></span>
                        <small class="text-muted">Stock corrections</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2 col-6 mb-3">
                <div class="info-box bg-light h-100">
                    <span class="info-box-icon bg-info"><i class="fas fa-users"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Users Involved</span>
                        <span class="info-box-number"><?php echo number_format($summary['unique_users']); ?></span>
                        <small class="text-muted">Active users</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2 col-6 mb-3">
                <div class="info-box bg-light h-100">
                    <span class="info-box-icon bg-dark"><i class="fas fa-money-bill-wave"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Value Moved</span>
                        <span class="info-box-number">$<?php echo number_format($summary['total_value_moved'] ?? 0, 2); ?></span>
                        <small class="text-muted">Total transaction value</small>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($audit_type == 'transactions'): ?>
        
        <!-- Transaction Audit Log -->
        <div class="row">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0"><i class="fas fa-chart-pie mr-2"></i>Transaction Types Summary</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Type</th>
                                        <th class="text-center">Count</th>
                                        <th class="text-center">Quantity</th>
                                        <th class="text-center">Value</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    if (mysqli_num_rows($types_summary_sql) > 0) {
                                        while ($type = mysqli_fetch_assoc($types_summary_sql)) {
                                            $badge_class = '';
                                            switch($type['transaction_type']) {
                                                case 'GRN':
                                                case 'TRANSFER_IN':
                                                    $badge_class = 'success';
                                                    break;
                                                case 'ISSUE':
                                                case 'TRANSFER_OUT':
                                                case 'WASTAGE':
                                                    $badge_class = 'danger';
                                                    break;
                                                case 'ADJUSTMENT':
                                                    $badge_class = 'warning';
                                                    break;
                                                default:
                                                    $badge_class = 'info';
                                            }
                                            ?>
                                            <tr>
                                                <td>
                                                    <span class="badge badge-<?php echo $badge_class; ?>"><?php echo $type['transaction_type']; ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="font-weight-bold"><?php echo number_format($type['count']); ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="<?php echo $type['total_quantity'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                        <?php echo number_format($type['total_quantity']); ?>
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="text-info">$<?php echo number_format($type['total_value'] ?? 0, 2); ?></span>
                                                </td>
                                            </tr>
                                            <?php
                                        }
                                    } else {
                                        echo '<tr><td colspan="4" class="text-center text-muted py-3">No transaction data</td></tr>';
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-light d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0"><i class="fas fa-list-alt mr-2"></i>Transaction Audit Log</h5>
                        <span class="badge badge-primary">Showing <?php echo min(200, mysqli_num_rows($transactions_sql)); ?> records</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Date/Time</th>
                                        <th>Type</th>
                                        <th>Item</th>
                                        <th class="text-center">Batch</th>
                                        <th class="text-center">Qty</th>
                                        <th class="text-center">Cost</th>
                                        <th class="text-center">From/To</th>
                                        <th>User</th>
                                        <th>Reference</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    if (mysqli_num_rows($transactions_sql) > 0) {
                                        while ($transaction = mysqli_fetch_assoc($transactions_sql)) {
                                            $badge_class = '';
                                            $arrow = '';
                                            switch($transaction['transaction_type']) {
                                                case 'GRN':
                                                case 'TRANSFER_IN':
                                                    $badge_class = 'success';
                                                    $arrow = '<i class="fas fa-arrow-down text-success"></i>';
                                                    break;
                                                case 'ISSUE':
                                                case 'TRANSFER_OUT':
                                                case 'WASTAGE':
                                                    $badge_class = 'danger';
                                                    $arrow = '<i class="fas fa-arrow-up text-danger"></i>';
                                                    break;
                                                case 'ADJUSTMENT':
                                                    $badge_class = 'warning';
                                                    $arrow = '<i class="fas fa-adjust text-warning"></i>';
                                                    break;
                                                default:
                                                    $badge_class = 'info';
                                            }
                                            ?>
                                            <tr>
                                                <td>
                                                    <small><?php echo date('M j, H:i', strtotime($transaction['created_at'])); ?></small>
                                                </td>
                                                <td>
                                                    <span class="badge badge-<?php echo $badge_class; ?>">
                                                        <?php echo $transaction['transaction_type']; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="font-weight-bold"><?php echo nullable_htmlentities($transaction['item_name']); ?></div>
                                                    <small class="text-muted"><?php echo nullable_htmlentities($transaction['item_code']); ?></small>
                                                    <?php if ($transaction['category_name']): ?>
                                                        <br><small class="text-muted"><?php echo nullable_htmlentities($transaction['category_name']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($transaction['batch_number']): ?>
                                                        <span class="badge badge-light"><?php echo nullable_htmlentities($transaction['batch_number']); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <span class="font-weight-bold <?php echo $transaction['quantity'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                        <?php echo $arrow; ?> <?php echo number_format(abs($transaction['quantity']), 3); ?>
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <small>$<?php echo number_format($transaction['unit_cost'], 4); ?></small>
                                                    <br><small class="text-info">$<?php echo number_format($transaction['total_value'], 2); ?></small>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($transaction['from_location']): ?>
                                                        <small class="text-danger"><?php echo nullable_htmlentities($transaction['from_location']); ?></small>
                                                    <?php endif; ?>
                                                    <?php if ($transaction['to_location']): ?>
                                                        <br><small class="text-success"><?php echo nullable_htmlentities($transaction['to_location']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <small><?php echo nullable_htmlentities($transaction['created_by_name']); ?></small>
                                                </td>
                                                <td>
                                                    <?php if ($transaction['reference_type'] && $transaction['reference_id']): ?>
                                                        <small class="text-muted"><?php echo $transaction['reference_type']; ?> #<?php echo $transaction['reference_id']; ?></small>
                                                    <?php endif; ?>
                                                    <?php if ($transaction['reason']): ?>
                                                        <br><small class="text-muted" title="<?php echo htmlspecialchars($transaction['reason']); ?>">
                                                            <i class="fas fa-comment"></i> <?php echo substr($transaction['reason'], 0, 30); ?>...
                                                        </small>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php
                                        }
                                    } else {
                                        echo '<tr><td colspan="9" class="text-center text-muted py-4">No transactions found</td></tr>';
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <?php elseif ($audit_type == 'adjustments'): ?>
        
        <!-- Stock Adjustments Audit -->
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-light d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0"><i class="fas fa-adjust mr-2 text-warning"></i>Stock Adjustments Audit</h5>
                        <span class="badge badge-warning"><?php echo mysqli_num_rows($adjustments_sql); ?> adjustments</span>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Date/Time</th>
                                        <th>Item</th>
                                        <th class="text-center">Batch</th>
                                        <th class="text-center">Location</th>
                                        <th class="text-center">Adjustment Qty</th>
                                        <th class="text-center">Unit Cost</th>
                                        <th class="text-center">Total Value</th>
                                        <th>Adjusted By</th>
                                        <th>Reason</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    if (mysqli_num_rows($adjustments_sql) > 0) {
                                        while ($adjustment = mysqli_fetch_assoc($adjustments_sql)) {
                                            $value_change = $adjustment['quantity'] * $adjustment['unit_cost'];
                                            ?>
                                            <tr class="<?php echo $adjustment['quantity'] > 0 ? 'table-success' : 'table-danger'; ?>">
                                                <td>
                                                    <small><?php echo date('M j, H:i', strtotime($adjustment['created_at'])); ?></small>
                                                </td>
                                                <td>
                                                    <div class="font-weight-bold"><?php echo nullable_htmlentities($adjustment['item_name']); ?></div>
                                                    <small class="text-muted"><?php echo nullable_htmlentities($adjustment['item_code']); ?></small>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($adjustment['batch_number']): ?>
                                                        <span class="badge badge-light"><?php echo nullable_htmlentities($adjustment['batch_number']); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <small><?php echo nullable_htmlentities($adjustment['location_name']); ?></small>
                                                </td>
                                                <td class="text-center">
                                                    <span class="font-weight-bold <?php echo $adjustment['quantity'] > 0 ? 'text-success' : 'text-danger'; ?>">
                                                        <?php echo $adjustment['quantity'] > 0 ? '+' : ''; ?><?php echo number_format($adjustment['quantity'], 3); ?>
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <small>$<?php echo number_format($adjustment['unit_cost'], 4); ?></small>
                                                </td>
                                                <td class="text-center">
                                                    <span class="font-weight-bold <?php echo $value_change > 0 ? 'text-success' : 'text-danger'; ?>">
                                                        $<?php echo number_format($value_change, 2); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <small><?php echo nullable_htmlentities($adjustment['adjusted_by']); ?></small>
                                                </td>
                                                <td>
                                                    <small class="text-muted"><?php echo nullable_htmlentities($adjustment['reason']); ?></small>
                                                </td>
                                                <td class="text-center">
                                                    <a href="inventory_transaction_view.php?transaction_id=<?php echo $adjustment['transaction_id']; ?>" class="btn btn-info btn-sm">
                                                        <i class="fas fa-eye mr-1"></i>View
                                                    </a>
                                                </td>
                                            </tr>
                                            <?php
                                        }
                                    } else {
                                        echo '<tr><td colspan="10" class="text-center text-success py-4">
                                            <i class="fas fa-check-circle fa-2x mb-2"></i><br>
                                            No stock adjustments found
                                        </td></tr>';
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <?php elseif ($audit_type == 'discrepancy'): ?>
        
        <!-- Stock Discrepancy Report -->
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-light d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0"><i class="fas fa-exclamation-triangle mr-2 text-danger"></i>Stock Discrepancy Report</h5>
                        <span class="badge badge-danger"><?php echo mysqli_num_rows($discrepancy_sql); ?> discrepancies</span>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-warning">
                            <i class="fas fa-info-circle mr-2"></i>
                            This report shows differences between physical counts and system records. Positive variance means physical count is higher than system. Negative means system shows more than physically counted.
                        </div>
                        
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Item</th>
                                        <th class="text-center">Category</th>
                                        <th class="text-center">Physical Count</th>
                                        <th class="text-center">System Count</th>
                                        <th class="text-center">Variance</th>
                                        <th class="text-center">Variance %</th>
                                        <th class="text-center">Avg Cost</th>
                                        <th class="text-center">Value Variance</th>
                                        <th class="text-center">Status</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    if (mysqli_num_rows($discrepancy_sql) > 0) {
                                        while ($item = mysqli_fetch_assoc($discrepancy_sql)) {
                                            $variance = $item['variance'];
                                            $variance_percent = $item['variance_percent'];
                                            $value_variance = $item['value_variance'] ?? 0;
                                            
                                            if ($variance_percent > 10) {
                                                $status = 'High';
                                                $status_class = 'danger';
                                            } elseif ($variance_percent > 5) {
                                                $status = 'Medium';
                                                $status_class = 'warning';
                                            } else {
                                                $status = 'Low';
                                                $status_class = 'info';
                                            }
                                            ?>
                                            <tr class="<?php echo $value_variance > 100 ? 'table-warning' : ''; ?>">
                                                <td>
                                                    <div class="font-weight-bold"><?php echo nullable_htmlentities($item['item_name']); ?></div>
                                                    <small class="text-muted"><?php echo nullable_htmlentities($item['item_code']); ?></small>
                                                </td>
                                                <td class="text-center">
                                                    <small><?php echo nullable_htmlentities($item['category_name']); ?></small>
                                                </td>
                                                <td class="text-center">
                                                    <span class="font-weight-bold"><?php echo number_format($item['physical_count'], 3); ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="font-weight-bold"><?php echo number_format($item['system_count'], 3); ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="font-weight-bold <?php echo $variance > 0 ? 'text-success' : 'text-danger'; ?>">
                                                        <?php echo $variance > 0 ? '+' : ''; ?><?php echo number_format($variance, 3); ?>
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge badge-<?php echo $status_class; ?>">
                                                        <?php echo number_format($variance_percent, 2); ?>%
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <small>$<?php echo number_format($item['avg_cost'] ?? 0, 4); ?></small>
                                                </td>
                                                <td class="text-center">
                                                    <span class="font-weight-bold <?php echo $value_variance > 0 ? 'text-danger' : 'text-info'; ?>">
                                                        $<?php echo number_format($value_variance, 2); ?>
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge badge-<?php echo $status_class; ?>"><?php echo $status; ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <a href="inventory_item_details.php?item_id=<?php echo $item['item_id']; ?>" class="btn btn-info btn-sm">
                                                        <i class="fas fa-eye mr-1"></i>View
                                                    </a>
                                                    <a href="inventory_adjust_stock.php?item_id=<?php echo $item['item_id']; ?>" class="btn btn-warning btn-sm">
                                                        <i class="fas fa-adjust mr-1"></i>Adjust
                                                    </a>
                                                </td>
                                            </tr>
                                            <?php
                                        }
                                    } else {
                                        echo '<tr><td colspan="10" class="text-center text-success py-4">
                                            <i class="fas fa-check-circle fa-2x mb-2"></i><br>
                                            No stock discrepancies found
                                        </td></tr>';
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <?php elseif ($audit_type == 'user_activity'): ?>
        
        <!-- User Activity Report -->
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0"><i class="fas fa-users mr-2"></i>User Activity Report</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead class="bg-light">
                                    <tr>
                                        <th>User</th>
                                        <th class="text-center">Transactions</th>
                                        <th class="text-center">Incoming Qty</th>
                                        <th class="text-center">Outgoing Qty</th>
                                        <th class="text-center">Value Handled</th>
                                        <th class="text-center">Avg Value/Trans</th>
                                        <th class="text-center">First Activity</th>
                                        <th class="text-center">Last Activity</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    if (mysqli_num_rows($user_activity_sql) > 0) {
                                        while ($user = mysqli_fetch_assoc($user_activity_sql)) {
                                            $avg_value = $user['transaction_count'] > 0 ? round($user['total_value_handled'] / $user['transaction_count'], 2) : 0;
                                            ?>
                                            <tr>
                                                <td>
                                                    <div class="font-weight-bold"><?php echo nullable_htmlentities($user['user_name']); ?></div>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge badge-primary badge-pill"><?php echo number_format($user['transaction_count']); ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="font-weight-bold text-success"><?php echo number_format($user['total_incoming'] ?? 0, 3); ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="font-weight-bold text-danger"><?php echo number_format($user['total_outgoing'] ?? 0, 3); ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <span class="font-weight-bold text-info">$<?php echo number_format($user['total_value_handled'] ?? 0, 2); ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <small class="text-muted">$<?php echo number_format($avg_value, 2); ?></small>
                                                </td>
                                                <td class="text-center">
                                                    <small><?php echo $user['first_activity'] ? date('M j, H:i', strtotime($user['first_activity'])) : '-'; ?></small>
                                                </td>
                                                <td class="text-center">
                                                    <small><?php echo $user['last_activity'] ? date('M j, H:i', strtotime($user['last_activity'])) : '-'; ?></small>
                                                </td>
                                                <td class="text-center">
                                                    <a href="?audit_type=transactions&user=<?php echo $user['user_id']; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>" class="btn btn-info btn-sm">
                                                        <i class="fas fa-eye mr-1"></i>View Activity
                                                    </a>
                                                </td>
                                            </tr>
                                            <?php
                                        }
                                    } else {
                                        echo '<tr><td colspan="9" class="text-center text-muted py-3">No user activity found</td></tr>';
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <?php elseif ($audit_type == 'batch_trail'): ?>
        
        <!-- Batch Audit Trail -->
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0"><i class="fas fa-box mr-2"></i>Batch Audit Trail</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Batch Details</th>
                                        <th class="text-center">Item</th>
                                        <th class="text-center">Supplier</th>
                                        <th class="text-center">Dates</th>
                                        <th class="text-center">Transaction Summary</th>
                                        <th class="text-center">Current Stock</th>
                                        <th class="text-center">Activity Period</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    if (mysqli_num_rows($batch_audit_sql) > 0) {
                                        while ($batch = mysqli_fetch_assoc($batch_audit_sql)) {
                                            $days_to_expiry = $batch['expiry_date'] ? floor((strtotime($batch['expiry_date']) - time()) / (60 * 60 * 24)) : null;
                                            $expiry_class = $days_to_expiry <= 0 ? 'danger' : ($days_to_expiry <= 30 ? 'warning' : 'success');
                                            ?>
                                            <tr class="<?php echo $days_to_expiry <= 0 ? 'table-danger' : ($days_to_expiry <= 7 ? 'table-warning' : ''); ?>">
                                                <td>
                                                    <div class="font-weight-bold"><?php echo nullable_htmlentities($batch['batch_number']); ?></div>
                                                    <?php if ($batch['expiry_date']): ?>
                                                        <small class="text-<?php echo $expiry_class; ?>">
                                                            Expires: <?php echo date('M j, Y', strtotime($batch['expiry_date'])); ?>
                                                            (<?php echo $days_to_expiry > 0 ? $days_to_expiry . ' days' : 'Expired'; ?>)
                                                        </small>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <div class="font-weight-bold"><?php echo nullable_htmlentities($batch['item_name']); ?></div>
                                                </td>
                                                <td class="text-center">
                                                    <small><?php echo nullable_htmlentities($batch['supplier_name']); ?></small>
                                                    <br><small class="text-muted">Received: <?php echo date('M j, Y', strtotime($batch['received_date'])); ?></small>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($batch['first_transaction']): ?>
                                                        <small>First: <?php echo date('M j', strtotime($batch['first_transaction'])); ?></small>
                                                        <br><small>Last: <?php echo date('M j', strtotime($batch['last_transaction'])); ?></small>
                                                    <?php else: ?>
                                                        <small class="text-muted">No transactions</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <div class="row">
                                                        <div class="col-4">
                                                            <small class="text-success">Received:</small>
                                                            <br><strong><?php echo number_format($batch['total_received'] ?? 0, 3); ?></strong>
                                                        </div>
                                                        <div class="col-4">
                                                            <small class="text-danger">Consumed:</small>
                                                            <br><strong><?php echo number_format($batch['total_consumed'] ?? 0, 3); ?></strong>
                                                        </div>
                                                        <div class="col-4">
                                                            <small class="text-warning">Adjusted:</small>
                                                            <br><strong><?php echo number_format($batch['total_adjusted'] ?? 0, 3); ?></strong>
                                                        </div>
                                                    </div>
                                                    <small class="text-muted"><?php echo number_format($batch['transaction_count']); ?> transactions</small>
                                                </td>
                                                <td class="text-center">
                                                    <span class="font-weight-bold text-primary"><?php echo number_format($batch['current_stock'], 3); ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($batch['first_transaction'] && $batch['last_transaction']): ?>
                                                        <small>
                                                            <?php 
                                                            $first = strtotime($batch['first_transaction']);
                                                            $last = strtotime($batch['last_transaction']);
                                                            $days_active = ceil(($last - $first) / (60 * 60 * 24));
                                                            echo $days_active . ' days';
                                                            ?>
                                                        </small>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <a href="inventory_batch_details.php?batch_id=<?php echo $batch['batch_id']; ?>" class="btn btn-info btn-sm">
                                                        <i class="fas fa-eye mr-1"></i>View
                                                    </a>
                                                    <a href="?audit_type=transactions&batch=<?php echo urlencode($batch['batch_number']); ?>&start_date=<?php echo date('Y-m-01', strtotime($batch['received_date'])); ?>&end_date=<?php echo date('Y-m-d'); ?>" class="btn btn-primary btn-sm">
                                                        <i class="fas fa-history mr-1"></i>History
                                                    </a>
                                                </td>
                                            </tr>
                                            <?php
                                        }
                                    } else {
                                        echo '<tr><td colspan="8" class="text-center text-muted py-3">No batch data found</td></tr>';
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <?php endif; ?>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.select2').select2();
    
    // Auto-print if print parameter is set
    <?php if (isset($_GET['print'])): ?>
        window.print();
    <?php endif; ?>
    
    // Quick filter buttons
    $('.btn-group-toggle .btn').click(function(e) {
        e.preventDefault();
        window.location.href = $(this).attr('href');
    });
    
    // Tooltips for truncated text
    $('[title]').tooltip();
});
</script>

<style>
@media print {
    .card-header, .btn, .form-group, .dropdown, .card-tools {
        display: none !important;
    }
    .card {
        border: none !important;
        box-shadow: none !important;
    }
    .info-box {
        break-inside: avoid;
    }
}
.table td {
    vertical-align: middle;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
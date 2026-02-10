<?php
// laundry_view.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

$page_title = "Laundry Item Details";

// Get item ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid item ID";
    header("Location: laundry_management.php");
    exit();
}

$laundry_id = intval($_GET['id']);

// Get laundry item details with enhanced information
$sql = "
    SELECT li.*, 
           a.*,
           lc.category_name,
          
           lc.min_quantity,
           lc.reorder_point,
           creator.user_name as created_by_name,
           creator.user_email as created_by_email,
           updater.user_name as updated_by_name,
           updater.user_email as updated_by_email,
           DATEDIFF(CURDATE(), li.last_washed_date) as days_since_last_wash,
           DATEDIFF(li.next_wash_date, CURDATE()) as days_to_next_wash,
           ac.category_name as asset_category,
           al.location_name as asset_location,
           (SELECT COUNT(*) FROM laundry_transactions WHERE laundry_id = li.laundry_id) as transaction_count,
           (SELECT COUNT(*) FROM wash_cycle_items WHERE laundry_id = li.laundry_id) as wash_cycle_count
    FROM laundry_items li
    LEFT JOIN assets a ON li.asset_id = a.asset_id
    LEFT JOIN laundry_categories lc ON li.category_id = lc.category_id
    LEFT JOIN asset_categories ac ON a.category_id = ac.category_id
    LEFT JOIN asset_locations al ON a.location_id = al.location_id
    LEFT JOIN users creator ON li.created_by = creator.user_id
    LEFT JOIN users updater ON li.updated_by = updater.user_id
    WHERE li.laundry_id = ?
";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $laundry_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Laundry item not found";
    header("Location: laundry_management.php");
    exit();
}

$item = $result->fetch_assoc();

// Get laundry statistics for comparison
$stats_sql = "
    SELECT 
        COUNT(*) as total_items,
        AVG(quantity) as avg_quantity,
        AVG(wash_count) as avg_wash_count,
        SUM(CASE WHEN status = 'dirty' THEN 1 ELSE 0 END) as dirty_count,
        SUM(CASE WHEN status = 'clean' THEN 1 ELSE 0 END) as clean_count,
        SUM(CASE WHEN item_condition = 'critical' THEN 1 ELSE 0 END) as critical_condition_count
    FROM laundry_items
    WHERE category_id = ?
";
$stats_stmt = $mysqli->prepare($stats_sql);
$stats_stmt->bind_param("i", $item['category_id']);
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();
$category_stats = $stats_result->fetch_assoc();

// Get recent transactions for this item
$transactions_sql = "
    SELECT lt.*, 
           u.user_name as performed_by_name,
           u.user_email as performed_by_email,
           c.client_name,
           wc.wash_date,
           wc.wash_time
    FROM laundry_transactions lt
    LEFT JOIN users u ON lt.performed_by = u.user_id
    LEFT JOIN clients c ON lt.performed_for = c.client_id
    LEFT JOIN laundry_wash_cycles wc ON lt.wash_id = wc.wash_id
    WHERE lt.laundry_id = ?
    ORDER BY lt.transaction_date DESC
    LIMIT 10
";
$transactions_stmt = $mysqli->prepare($transactions_sql);
$transactions_stmt->bind_param("i", $laundry_id);
$transactions_stmt->execute();
$transactions_result = $transactions_stmt->get_result();

// Get detailed wash history
$wash_history_sql = "
    SELECT wc.*, 
           wci.condition_before,
           wci.condition_after,
           wci.notes as wash_item_notes,
           u.user_name as completed_by_name,
           u.user_email as completed_by_email,
           DATEDIFF(CURDATE(), wc.wash_date) as days_ago
    FROM wash_cycle_items wci
    LEFT JOIN laundry_wash_cycles wc ON wci.wash_id = wc.wash_id
    LEFT JOIN users u ON wc.completed_by = u.user_id
    WHERE wci.laundry_id = ?
    ORDER BY wc.wash_date DESC, wc.wash_time DESC
    LIMIT 5
";
$wash_history_stmt = $mysqli->prepare($wash_history_sql);
$wash_history_stmt->bind_param("i", $laundry_id);
$wash_history_stmt->execute();
$wash_history_result = $wash_history_stmt->get_result();

// Get next wash recommendation
$next_wash_sql = "
    SELECT 
        CASE 
            WHEN li.next_wash_date IS NOT NULL AND li.next_wash_date <= CURDATE() THEN 'overdue'
            WHEN li.next_wash_date IS NOT NULL AND DATEDIFF(li.next_wash_date, CURDATE()) <= 3 THEN 'soon'
            WHEN li.last_washed_date IS NOT NULL AND DATEDIFF(CURDATE(), li.last_washed_date) > 30 THEN 'recommended'
            ELSE 'scheduled'
        END as wash_recommendation,
        CASE 
            WHEN li.next_wash_date IS NOT NULL THEN li.next_wash_date
            ELSE DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        END as recommended_date
    FROM laundry_items li
    WHERE li.laundry_id = ?
";
$next_wash_stmt = $mysqli->prepare($next_wash_sql);
$next_wash_stmt->bind_param("i", $laundry_id);
$next_wash_stmt->execute();
$next_wash_result = $next_wash_stmt->get_result();
$wash_recommendation = $next_wash_result->fetch_assoc();
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-tshirt mr-2"></i>Laundry Item Details
        </h3>
        <div class="card-tools">
            <a href="laundry_edit.php?id=<?php echo $laundry_id; ?>" class="btn btn-warning">
                <i class="fas fa-edit mr-2"></i>Edit Item
            </a>
            <a href="laundry_management.php" class="btn btn-light ml-2">
                <i class="fas fa-arrow-left mr-2"></i>Back to Laundry
            </a>
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
        
        <div class="row">
            <div class="col-md-8">
                <!-- Item Overview -->
                <div class="card card-primary">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-info-circle mr-2"></i>Item Overview</h3>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-8">
                                <h4><?php echo htmlspecialchars($item['asset_name']); ?></h4>
                                <p class="text-muted mb-2">
                                    <i class="fas fa-tag mr-1"></i>
                                    <?php echo htmlspecialchars($item['asset_tag']); ?>
                                    <?php if ($item['serial_number']): ?>
                                        | <i class="fas fa-barcode mr-1"></i>
                                        <?php echo htmlspecialchars($item['serial_number']); ?>
                                    <?php endif; ?>
                                </p>
                                <p class="mb-3">
                                    <span class="badge">
                                        <?php echo htmlspecialchars($item['category_name']); ?>
                                    </span>
                                    <span class="badge badge-light ml-2">
                                        <i class="fas fa-cube mr-1"></i>
                                        <?php echo htmlspecialchars($item['asset_category']); ?>
                                    </span>
                                </p>
                            </div>
                            <div class="col-md-4 text-right">
                                <div class="h1 text-primary"><?php echo $item['quantity']; ?></div>
                                <small class="text-muted">Items in Stock</small>
                            </div>
                        </div>
                        
                        <?php if ($item['asset_description']): ?>
                            <div class="alert alert-secondary mt-3">
                                <strong>Description:</strong> <?php echo htmlspecialchars($item['asset_description']); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Status & Condition -->
                <div class="row">
                    <div class="col-md-6">
                        <div class="card card-warning">
                            <div class="card-header">
                                <h3 class="card-title"><i class="fas fa-heartbeat mr-2"></i>Current Status</h3>
                            </div>
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <div>
                                        <h6 class="mb-1">Item Status</h6>
                                        <span class="badge badge-<?php 
                                            switch($item['status']) {
                                                case 'clean': echo 'success'; break;
                                                case 'dirty': echo 'warning'; break;
                                                case 'in_wash': echo 'info'; break;
                                                case 'damaged': echo 'danger'; break;
                                                case 'lost': echo 'dark'; break;
                                                case 'retired': echo 'secondary'; break;
                                                default: echo 'secondary';
                                            }
                                        ?> badge-lg">
                                            <?php echo ucfirst($item['status']); ?>
                                        </span>
                                    </div>
                                    <i class="fas fa-3x text-<?php 
                                        switch($item['status']) {
                                            case 'clean': echo 'success'; break;
                                            case 'dirty': echo 'warning'; break;
                                            case 'in_wash': echo 'info'; break;
                                            case 'damaged': echo 'danger'; break;
                                            default: echo 'secondary';
                                        }
                                    ?>">
                                        <?php 
                                        switch($item['status']) {
                                            case 'clean': echo 'fa-check-circle'; break;
                                            case 'dirty': echo 'fa-exclamation-triangle'; break;
                                            case 'in_wash': echo 'fa-sync'; break;
                                            case 'damaged': echo 'fa-times-circle'; break;
                                            default: echo 'fa-question-circle';
                                        }
                                        ?>
                                    </i>
                                </div>
                                
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="mb-1">Current Location</h6>
                                        <span class="badge badge-<?php 
                                            switch($item['current_location']) {
                                                case 'clinic': echo 'primary'; break;
                                                case 'laundry': echo 'info'; break;
                                                case 'storage': echo 'success'; break;
                                                case 'in_transit': echo 'warning'; break;
                                                case 'ward': echo 'purple'; break;
                                                case 'or': echo 'danger'; break;
                                                case 'er': echo 'dark'; break;
                                                default: echo 'secondary';
                                            }
                                        ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $item['current_location'])); ?>
                                        </span>
                                    </div>
                                    <i class="fas fa-2x text-<?php 
                                        switch($item['current_location']) {
                                            case 'clinic': echo 'primary'; break;
                                            case 'laundry': echo 'info'; break;
                                            case 'storage': echo 'success'; break;
                                            case 'in_transit': echo 'warning'; break;
                                            default: echo 'secondary';
                                        }
                                    ?> fa-map-marker-alt"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card card-info">
                            <div class="card-header">
                                <h3 class="card-title"><i class="fas fa-stethoscope mr-2"></i>Condition & Health</h3>
                            </div>
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <div>
                                        <h6 class="mb-1">Item Condition</h6>
                                        <span class="badge badge-<?php 
                                            switch($item['item_condition']) {
                                                case 'excellent': echo 'success'; break;
                                                case 'good': echo 'info'; break;
                                                case 'fair': echo 'warning'; break;
                                                case 'poor': echo 'warning'; break;
                                                case 'critical': echo 'danger'; break;
                                                default: echo 'secondary';
                                            }
                                        ?> badge-lg">
                                            <?php echo ucfirst($item['item_condition']); ?>
                                        </span>
                                    </div>
                                    <i class="fas fa-3x text-<?php 
                                        switch($item['item_condition']) {
                                            case 'excellent': echo 'success'; break;
                                            case 'good': echo 'info'; break;
                                            case 'fair': echo 'warning'; break;
                                            case 'poor': echo 'warning'; break;
                                            case 'critical': echo 'danger'; break;
                                            default: echo 'secondary';
                                        }
                                    ?> fa-heartbeat"></i>
                                </div>
                                
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="mb-1">Wash Count</h6>
                                        <div class="h4 text-primary"><?php echo $item['wash_count']; ?></div>
                                        <small class="text-muted">Total washes</small>
                                    </div>
                                    <div class="text-right">
                                        <h6 class="mb-1">Last Wash</h6>
                                        <div class="h6">
                                            <?php if ($item['last_washed_date']): ?>
                                                <?php echo date('M j, Y', strtotime($item['last_washed_date'])); ?>
                                            <?php else: ?>
                                                <span class="text-muted">Never</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Wash Schedule & History -->
                <div class="card card-success">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-calendar-alt mr-2"></i>Wash Schedule</h3>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="d-block">Next Wash Date</label>
                                    <div class="d-flex align-items-center">
                                        <?php if ($item['next_wash_date']): ?>
                                            <div class="h4 mr-3 <?php echo $item['days_to_next_wash'] < 0 ? 'text-danger' : ($item['days_to_next_wash'] <= 3 ? 'text-warning' : 'text-success'); ?>">
                                                <?php echo date('M j, Y', strtotime($item['next_wash_date'])); ?>
                                            </div>
                                            <span class="badge badge-<?php 
                                                if ($item['days_to_next_wash'] < 0) {
                                                    echo 'danger';
                                                } elseif ($item['days_to_next_wash'] <= 3) {
                                                    echo 'warning';
                                                } else {
                                                    echo 'success';
                                                }
                                            ?>">
                                                <?php 
                                                if ($item['days_to_next_wash'] < 0) {
                                                    echo abs($item['days_to_next_wash']) . ' days overdue';
                                                } elseif ($item['days_to_next_wash'] == 0) {
                                                    echo 'Today';
                                                } else {
                                                    echo $item['days_to_next_wash'] . ' days';
                                                }
                                                ?>
                                            </span>
                                        <?php else: ?>
                                            <div class="h4 mr-3 text-muted">Not scheduled</div>
                                            <a href="laundry_edit.php?id=<?php echo $laundry_id; ?>" class="btn btn-sm btn-outline-primary">
                                                Schedule
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                    <small class="text-muted">
                                        Recommended: 
                                        <span class="font-weight-bold">
                                            <?php echo date('M j, Y', strtotime($wash_recommendation['recommended_date'])); ?>
                                        </span>
                                        (<?php echo $wash_recommendation['wash_recommendation']; ?>)
                                    </small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="d-block">Wash Frequency</label>
                                    <div class="h4">
                                        <?php if ($item['wash_count'] > 0 && $item['days_since_last_wash'] > 0): ?>
                                            Every <?php echo round($item['days_since_last_wash'] / $item['wash_count'], 1); ?> days
                                        <?php else: ?>
                                            <span class="text-muted">Not enough data</span>
                                        <?php endif; ?>
                                    </div>
                                    <small class="text-muted">
                                        Average based on <?php echo $item['wash_count']; ?> washes
                                    </small>
                                </div>
                            </div>
                        </div>
                        
                        <?php if ($wash_history_result->num_rows > 0): ?>
                            <hr>
                            <h6 class="mb-3"><i class="fas fa-history mr-2"></i>Recent Wash History</h6>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Time</th>
                                            <th>Temperature</th>
                                            <th>Condition</th>
                                            <th>Performed By</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while($wash = $wash_history_result->fetch_assoc()): ?>
                                        <tr>
                                            <td><?php echo date('M j, Y', strtotime($wash['wash_date'])); ?></td>
                                            <td><?php echo date('g:i A', strtotime($wash['wash_time'])); ?></td>
                                            <td>
                                                <span class="badge badge-light">
                                                    <?php echo ucfirst($wash['temperature']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge badge-light">
                                                    <?php echo ucfirst($wash['condition_before']); ?> → 
                                                    <?php echo ucfirst($wash['condition_after']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <small><?php echo htmlspecialchars($wash['completed_by_name']); ?></small>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="text-center">
                                <a href="laundry_wash_cycles.php" class="btn btn-sm btn-outline-info">
                                    View All Wash Cycles
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div class="card card-secondary">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-stream mr-2"></i>Recent Activity</h3>
                    </div>
                    <div class="card-body">
                        <?php if ($transactions_result->num_rows == 0): ?>
                            <p class="text-muted text-center">No recent activity</p>
                        <?php else: ?>
                            <div class="timeline">
                                <?php while($transaction = $transactions_result->fetch_assoc()): ?>
                                <div class="timeline-item">
                                    <div class="timeline-marker 
                                        <?php 
                                        switch($transaction['transaction_type']) {
                                            case 'checkout': echo 'bg-success'; break;
                                            case 'checkin': echo 'bg-primary'; break;
                                            case 'wash': echo 'bg-info'; break;
                                            case 'damage': echo 'bg-warning'; break;
                                            case 'lost': echo 'bg-danger'; break;
                                            default: echo 'bg-secondary';
                                        }
                                        ?>">
                                    </div>
                                    <div class="timeline-content">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <strong class="text-capitalize">
                                                    <?php echo str_replace('_', ' ', $transaction['transaction_type']); ?>
                                                </strong>
                                                <?php if ($transaction['client_name']): ?>
                                                    <span class="badge badge-light ml-2">
                                                        <?php echo htmlspecialchars($transaction['client_name']); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <small class="text-muted">
                                                <?php echo date('M j, g:i A', strtotime($transaction['transaction_date'])); ?>
                                            </small>
                                        </div>
                                        <div class="text-muted small">
                                            <?php if ($transaction['from_location'] && $transaction['to_location']): ?>
                                                <i class="fas fa-arrow-right mr-1"></i>
                                                <?php echo ucfirst($transaction['from_location']); ?> → 
                                                <?php echo ucfirst($transaction['to_location']); ?>
                                            <?php endif; ?>
                                            <?php if ($transaction['wash_date']): ?>
                                                <span class="ml-2">
                                                    <i class="fas fa-tint mr-1"></i>
                                                    Wash cycle: <?php echo date('M j', strtotime($transaction['wash_date'])); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($transaction['notes']): ?>
                                            <div class="alert alert-light p-2 mt-2 mb-0">
                                                <small><?php echo htmlspecialchars($transaction['notes']); ?></small>
                                            </div>
                                        <?php endif; ?>
                                        <small class="text-muted">
                                            <i class="fas fa-user mr-1"></i>
                                            <?php echo htmlspecialchars($transaction['performed_by_name']); ?>
                                        </small>
                                    </div>
                                </div>
                                <?php endwhile; ?>
                            </div>
                            <div class="text-center mt-3">
                                <a href="laundry_transactions.php?q=<?php echo urlencode($item['asset_name']); ?>" 
                                   class="btn btn-sm btn-outline-primary">
                                    View All Transactions (<?php echo $item['transaction_count']; ?>)
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Notes Section -->
                <?php if (!empty($item['notes'])): ?>
                <div class="card card-info">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-sticky-note mr-2"></i>Notes</h3>
                    </div>
                    <div class="card-body">
                        <p class="mb-0"><?php echo nl2br(htmlspecialchars($item['notes'])); ?></p>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="col-md-4">
                <!-- Quick Actions -->
                <div class="card card-success">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-bolt mr-2"></i>Quick Actions</h3>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <?php if ($item['status'] == 'dirty'): ?>
                                <a href="laundry_wash_new.php" class="btn btn-primary">
                                    <i class="fas fa-tint mr-2"></i>Wash This Item
                                </a>
                            <?php endif; ?>
                            
                            <?php if ($item['status'] == 'clean'): ?>
                                <button class="btn btn-warning" onclick="checkoutItem()">
                                    <i class="fas fa-sign-out-alt mr-2"></i>Check Out
                                </button>
                            <?php endif; ?>
                            
                            <a href="laundry_edit.php?id=<?php echo $laundry_id; ?>" class="btn btn-outline-warning">
                                <i class="fas fa-edit mr-2"></i>Edit Item
                            </a>
                            
                            <a href="laundry_management.php" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left mr-2"></i>Back to List
                            </a>
                            
                            <button class="btn btn-outline-info" onclick="window.print()">
                                <i class="fas fa-print mr-2"></i>Print Details
                            </button>
                            
                            <?php if ($item['is_critical']): ?>
                                <button class="btn btn-danger" onclick="alert('Critical item - requires attention!')">
                                    <i class="fas fa-exclamation-triangle mr-2"></i>Critical Item
                                </button>
                            <?php endif; ?>
                        </div>
                        <hr>
                        <div class="text-center small text-muted">
                            <i class="fas fa-info-circle mr-1"></i>
                            Last updated: <?php echo date('M j, Y g:i A', strtotime($item['updated_at'] ?: $item['created_at'])); ?>
                        </div>
                    </div>
                </div>

                <!-- Category Statistics -->
                <div class="card card-warning">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-chart-pie mr-2"></i>Category Statistics</h3>
                    </div>
                    <div class="card-body">
                        <div class="text-center mb-3">
                            <h5><?php echo htmlspecialchars($item['category_name']); ?></h5>
                            <small class="text-muted">Compared to other items in this category</small>
                        </div>
                        
                        <div class="list-group list-group-flush">
                            <div class="list-group-item px-0 py-2">
                                <div class="d-flex w-100 justify-content-between">
                                    <span>Items in Category:</span>
                                    <span class="badge badge-primary badge-pill"><?php echo $category_stats['total_items']; ?></span>
                                </div>
                            </div>
                            <div class="list-group-item px-0 py-2">
                                <div class="d-flex w-100 justify-content-between">
                                    <span>Your Quantity:</span>
                                    <span class="badge badge-<?php echo $item['quantity'] > $category_stats['avg_quantity'] ? 'success' : ($item['quantity'] < $category_stats['avg_quantity'] ? 'warning' : 'info'); ?> badge-pill">
                                        <?php echo $item['quantity']; ?>
                                        (Avg: <?php echo round($category_stats['avg_quantity'], 1); ?>)
                                    </span>
                                </div>
                            </div>
                            <div class="list-group-item px-0 py-2">
                                <div class="d-flex w-100 justify-content-between">
                                    <span>Your Wash Count:</span>
                                    <span class="badge badge-<?php echo $item['wash_count'] > $category_stats['avg_wash_count'] ? 'warning' : 'info'; ?> badge-pill">
                                        <?php echo $item['wash_count']; ?>
                                        (Avg: <?php echo round($category_stats['avg_wash_count'], 1); ?>)
                                    </span>
                                </div>
                            </div>
                            <div class="list-group-item px-0 py-2">
                                <div class="d-flex w-100 justify-content-between">
                                    <span>Clean in Category:</span>
                                    <span class="badge badge-success badge-pill"><?php echo $category_stats['clean_count']; ?></span>
                                </div>
                            </div>
                            <div class="list-group-item px-0 py-2">
                                <div class="d-flex w-100 justify-content-between">
                                    <span>Dirty in Category:</span>
                                    <span class="badge badge-warning badge-pill"><?php echo $category_stats['dirty_count']; ?></span>
                                </div>
                            </div>
                            <div class="list-group-item px-0 py-2">
                                <div class="d-flex w-100 justify-content-between">
                                    <span>Critical Condition:</span>
                                    <span class="badge badge-danger badge-pill"><?php echo $category_stats['critical_condition_count']; ?></span>
                                </div>
                            </div>
                        </div>
                        
                        <?php if ($item['min_quantity'] && $item['quantity'] < $item['min_quantity']): ?>
                            <div class="alert alert-danger mt-3 p-2">
                                <i class="fas fa-exclamation-triangle mr-2"></i>
                                <strong>Low Stock!</strong> Below minimum quantity (<?php echo $item['min_quantity']; ?>)
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($item['reorder_point'] && $item['quantity'] <= $item['reorder_point']): ?>
                            <div class="alert alert-warning mt-3 p-2">
                                <i class="fas fa-shopping-cart mr-2"></i>
                                <strong>Reorder Point!</strong> Consider restocking soon
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Item Metadata -->
                <div class="card card-info">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-database mr-2"></i>Item Metadata</h3>
                    </div>
                    <div class="card-body">
                        <div class="small">
                            <div class="d-flex justify-content-between mb-2">
                                <span>Created By:</span>
                                <span class="font-weight-bold"><?php echo htmlspecialchars($item['created_by_name']); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Created Date:</span>
                                <span><?php echo date('M j, Y g:i A', strtotime($item['created_at'])); ?></span>
                            </div>
                            
                            <?php if ($item['updated_by_name']): ?>
                                <hr class="my-2">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Updated By:</span>
                                    <span class="font-weight-bold"><?php echo htmlspecialchars($item['updated_by_name']); ?></span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Updated Date:</span>
                                    <span><?php echo date('M j, Y g:i A', strtotime($item['updated_at'])); ?></span>
                                </div>
                            <?php endif; ?>
                            
                            <hr class="my-2">
                            <div class="d-flex justify-content-between mb-2">
                                <span>Asset Category:</span>
                                <span><?php echo htmlspecialchars($item['asset_category']); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Asset Location:</span>
                                <span><?php echo htmlspecialchars($item['asset_location']); ?></span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span>Total Activities:</span>
                                <span class="badge badge-light"><?php echo $item['transaction_count'] + $item['wash_cycle_count']; ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Tips -->
                <div class="card card-primary">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-lightbulb mr-2"></i>Quick Tips</h3>
                    </div>
                    <div class="card-body">
                        <div class="callout callout-info small mb-3">
                            <i class="fas fa-info-circle mr-2"></i>
                            <strong>Wash Schedule:</strong> Items should be washed based on usage frequency and condition.
                        </div>
                        <div class="callout callout-warning small mb-3">
                            <i class="fas fa-exclamation-triangle mr-2"></i>
                            <strong>Condition Monitoring:</strong> Update condition regularly to track wear and tear.
                        </div>
                        <div class="callout callout-success small">
                            <i class="fas fa-check-circle mr-2"></i>
                            <strong>Inventory Management:</strong> Keep quantities above minimum levels for critical items.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize tooltips
    $('[data-toggle="tooltip"]').tooltip();
    
    // Check if item needs attention
    <?php if ($item['status'] == 'damaged' || $item['item_condition'] == 'critical'): ?>
        showAttentionAlert();
    <?php endif; ?>
});

function checkoutItem() {
    if (confirm('Check out <?php echo htmlspecialchars($item['asset_name']); ?> to a client or department?')) {
        // In a real implementation, this would open a checkout form
        // For now, redirect to a checkout page
        window.location.href = 'laundry_checkout.php?id=<?php echo $laundry_id; ?>';
    }
}

function showAttentionAlert() {
    const messages = [];
    
    <?php if ($item['status'] == 'damaged'): ?>
        messages.push('This item is marked as DAMAGED and may need repair or replacement.');
    <?php endif; ?>
    
    <?php if ($item['item_condition'] == 'critical'): ?>
        messages.push('This item is in CRITICAL condition and may need immediate attention.');
    <?php endif; ?>
    
    <?php if ($item['is_critical']): ?>
        messages.push('This is a CRITICAL ITEM that requires special handling.');
    <?php endif; ?>
    
    <?php if ($item['next_wash_date'] && $item['days_to_next_wash'] < 0): ?>
        messages.push('Next wash date is OVERDUE by <?php echo abs($item['days_to_next_wash']); ?> days.');
    <?php endif; ?>
    
    if (messages.length > 0) {
        alert('ATTENTION REQUIRED:\n\n' + messages.join('\n\n'));
    }
}

function printItemLabel() {
    // This would open a print dialog for item labels
    window.open('laundry_label.php?id=<?php echo $laundry_id; ?>', '_blank');
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + E to edit
    if (e.ctrlKey && e.keyCode === 69) {
        e.preventDefault();
        window.location.href = 'laundry_edit.php?id=<?php echo $laundry_id; ?>';
    }
    // Ctrl + P to print
    if (e.ctrlKey && e.keyCode === 80) {
        e.preventDefault();
        window.print();
    }
    // Escape to go back
    if (e.keyCode === 27) {
        window.location.href = 'laundry_management.php';
    }
});
</script>

<style>
.callout {
    border-left: 3px solid #eee;
    margin-bottom: 10px;
    padding: 10px 15px;
    border-radius: 0.25rem;
}

.callout-info {
    border-left-color: #17a2b8;
    background-color: #f8f9fa;
}

.callout-warning {
    border-left-color: #ffc107;
    background-color: #fffbf0;
}

.callout-success {
    border-left-color: #28a745;
    background-color: #f0fff4;
}

.callout-primary {
    border-left-color: #007bff;
    background-color: #f0f8ff;
}

.badge-lg {
    font-size: 14px;
    padding: 8px 15px;
}

.timeline {
    position: relative;
    padding-left: 20px;
}

.timeline:before {
    content: '';
    position: absolute;
    left: 9px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: #e9ecef;
}

.timeline-item {
    position: relative;
    margin-bottom: 15px;
}

.timeline-marker {
    position: absolute;
    left: -20px;
    top: 5px;
    width: 12px;
    height: 12px;
    border-radius: 50%;
}

.timeline-content {
    padding-left: 10px;
}

.d-grid {
    display: grid;
    gap: 10px;
}

.btn-lg, .btn-group-lg > .btn {
    padding: 0.75rem 1.5rem;
    font-size: 1.1rem;
}

.text-purple {
    color: #6f42c1 !important;
}

.badge-purple {
    background-color: #6f42c1;
    color: white;
}

.table-sm th, .table-sm td {
    padding: 0.5rem;
}

.list-group-flush .list-group-item {
    padding: 0.5rem 0;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
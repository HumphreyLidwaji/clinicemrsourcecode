<?php
// laundry_audit.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

$page_title = "Laundry Audit Trail";

// Filter parameters
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$action_type = $_GET['action'] ?? '';
$user_id_filter = $_GET['user_id'] ?? '';

// Build query
$where_conditions = ["DATE(created_at) BETWEEN ? AND ?"];
$params = [$start_date, $end_date];
$param_types = "ss";

if ($action_type) {
    $where_conditions[] = "action = ?";
    $params[] = $action_type;
    $param_types .= "s";
}

if ($user_id_filter) {
    $where_conditions[] = "user_id = ?";
    $params[] = $user_id_filter;
    $param_types .= "i";
}

$where_clause = implode(" AND ", $where_conditions);

// Get activity logs from system_logs table (assuming it exists)
// If not, we'll create a combined view of all laundry activities
$sql = "
    SELECT 
        'transaction' as source,
        lt.transaction_id as id,
        lt.transaction_date as timestamp,
        lt.performed_by as user_id,
        u.user_name,
        CONCAT('Laundry ', 
               CASE lt.transaction_type 
                   WHEN 'checkout' THEN 'Checkout'
                   WHEN 'checkin' THEN 'Checkin' 
                   WHEN 'wash' THEN 'Wash'
                   WHEN 'damage' THEN 'Damage Report'
                   WHEN 'lost' THEN 'Lost Report'
                   WHEN 'found' THEN 'Found Report'
                   ELSE lt.transaction_type 
               END,
               ' - ', a.asset_name) as description,
        lt.transaction_type as action,
        CONCAT('Item: ', a.asset_tag, ' (', a.asset_name, ')') as details
    FROM laundry_transactions lt
    LEFT JOIN laundry_items li ON lt.laundry_id = li.laundry_id
    LEFT JOIN assets a ON li.asset_id = a.asset_id
    LEFT JOIN users u ON lt.performed_by = u.user_id
    WHERE $where_clause
    
    UNION ALL
    
    SELECT 
        'wash_cycle' as source,
        wc.wash_id as id,
        wc.created_at as timestamp,
        wc.completed_by as user_id,
        u.user_name,
        CONCAT('Wash Cycle Completed - ', wc.items_washed, ' items') as description,
        'wash_cycle' as action,
        CONCAT('Temperature: ', wc.temperature, 
               ', Detergent: ', COALESCE(wc.detergent_type, 'N/A'),
               ', Date: ', DATE_FORMAT(wc.wash_date, '%M %d, %Y')) as details
    FROM laundry_wash_cycles wc
    LEFT JOIN users u ON wc.completed_by = u.user_id
    WHERE $where_clause
    
    UNION ALL
    
    SELECT 
        'item_update' as source,
        li.laundry_id as id,
        li.updated_at as timestamp,
        li.updated_by as user_id,
        u.user_name,
        CONCAT('Item Updated - ', a.asset_name) as description,
        'item_update' as action,
        CONCAT('Status: ', li.status, 
               ', Location: ', li.current_location,
               ', Condition: ', li.condition) as details
    FROM laundry_items li
    LEFT JOIN assets a ON li.asset_id = a.asset_id
    LEFT JOIN users u ON li.updated_by = u.user_id
    WHERE $where_clause AND li.updated_at IS NOT NULL
    
    ORDER BY timestamp DESC
    LIMIT 100
";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param($param_types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// Get users for filter
$users_sql = "SELECT user_id, user_name FROM users ";
$users_result = $mysqli->query($users_result);
?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-clipboard-check mr-2"></i>Laundry Audit Trail
        </h3>
        <div class="card-tools">
            <button class="btn btn-light" onclick="window.print()">
                <i class="fas fa-fw fa-print mr-2"></i>Print
            </button>
            <a href="laundry_management.php" class="btn btn-secondary ml-2">
                <i class="fas fa-fw fa-arrow-left mr-2"></i>Back to Laundry
            </a>
        </div>
    </div>
    
    <div class="card-body">
        <!-- Filter Form -->
        <div class="card mb-4">
            <div class="card-body bg-light">
                <form action="laundry_audit.php" method="GET" autocomplete="off">
                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Start Date</label>
                                <input type="date" class="form-control" name="start_date" 
                                       value="<?php echo $start_date; ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>End Date</label>
                                <input type="date" class="form-control" name="end_date" 
                                       value="<?php echo $end_date; ?>">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-group">
                                <label>Action Type</label>
                                <select class="form-control" name="action">
                                    <option value="">All Actions</option>
                                    <option value="checkout" <?php echo $action_type == 'checkout' ? 'selected' : ''; ?>>Checkout</option>
                                    <option value="checkin" <?php echo $action_type == 'checkin' ? 'selected' : ''; ?>>Checkin</option>
                                    <option value="wash" <?php echo $action_type == 'wash' ? 'selected' : ''; ?>>Wash</option>
                                    <option value="damage" <?php echo $action_type == 'damage' ? 'selected' : ''; ?>>Damage</option>
                                    <option value="wash_cycle" <?php echo $action_type == 'wash_cycle' ? 'selected' : ''; ?>>Wash Cycle</option>
                                    <option value="item_update" <?php echo $action_type == 'item_update' ? 'selected' : ''; ?>>Item Update</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-group">
                                <label>User</label>
                                <select class="form-control select2" name="user_id">
                                    <option value="">All Users</option>
                                    <?php while($user = $users_result->fetch_assoc()): ?>
                                        <option value="<?php echo $user['user_id']; ?>" 
                                                <?php echo $user_id_filter == $user['user_id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($user['user_name']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-group">
                                <label>&nbsp;</label>
                                <div class="btn-group btn-block">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-filter mr-2"></i>Filter
                                    </button>
                                    <a href="laundry_audit.php" class="btn btn-secondary">
                                        <i class="fas fa-times mr-2"></i>Clear
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Audit Trail -->
        <div class="timeline">
            <?php if ($result->num_rows == 0): ?>
                <div class="text-center py-5">
                    <i class="fas fa-history fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No audit records found</h5>
                    <p class="text-muted">Try adjusting your filter criteria</p>
                </div>
            <?php else: ?>
                <?php while($record = $result->fetch_assoc()): ?>
                <div class="timeline-item mb-4">
                    <div class="timeline-marker 
                        <?php 
                        switch($record['action']) {
                            case 'checkout': echo 'bg-success'; break;
                            case 'checkin': echo 'bg-primary'; break;
                            case 'wash': echo 'bg-info'; break;
                            case 'wash_cycle': echo 'bg-info'; break;
                            case 'damage': echo 'bg-warning'; break;
                            case 'item_update': echo 'bg-secondary'; break;
                            default: echo 'bg-dark';
                        }
                        ?>">
                        <?php 
                        switch($record['action']) {
                            case 'checkout': echo '<i class="fas fa-sign-out-alt"></i>'; break;
                            case 'checkin': echo '<i class="fas fa-sign-in-alt"></i>'; break;
                            case 'wash': echo '<i class="fas fa-tint"></i>'; break;
                            case 'wash_cycle': echo '<i class="fas fa-sync"></i>'; break;
                            case 'damage': echo '<i class="fas fa-exclamation-triangle"></i>'; break;
                            case 'item_update': echo '<i class="fas fa-edit"></i>'; break;
                            default: echo '<i class="fas fa-history"></i>';
                        }
                        ?>
                    </div>
                    <div class="timeline-content">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h6 class="card-title mb-1">
                                            <?php echo htmlspecialchars($record['description']); ?>
                                        </h6>
                                        <p class="card-text text-muted mb-1">
                                            <?php echo htmlspecialchars($record['details']); ?>
                                        </p>
                                        <small class="text-muted">
                                            <i class="fas fa-user mr-1"></i>
                                            <?php echo htmlspecialchars($record['user_name']); ?>
                                        </small>
                                    </div>
                                    <div class="text-right">
                                        <small class="text-muted">
                                            <?php echo date('M j, Y', strtotime($record['timestamp'])); ?><br>
                                            <?php echo date('g:i A', strtotime($record['timestamp'])); ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endwhile; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.select2').select2();
    
    // Initialize date pickers
    $('input[type="date"]').datepicker({
        format: 'yyyy-mm-dd',
        autoclose: true,
        todayHighlight: true
    });
});
</script>

<style>
.timeline {
    position: relative;
    padding-left: 40px;
}

.timeline:before {
    content: '';
    position: absolute;
    left: 19px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: #e9ecef;
}

.timeline-item {
    position: relative;
}

.timeline-marker {
    position: absolute;
    left: -40px;
    top: 20px;
    width: 38px;
    height: 38px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 14px;
    z-index: 1;
}

.timeline-content {
    padding-left: 10px;
}

@media print {
    .timeline:before {
        display: none;
    }
    
    .timeline-marker {
        position: static;
        display: inline-block;
        margin-right: 10px;
    }
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
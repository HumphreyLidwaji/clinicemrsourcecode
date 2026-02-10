<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/price_functions.php';

// Get filter parameters
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$entity_type = $_GET['entity_type'] ?? '';
$entity_id = intval($_GET['entity_id'] ?? 0);
$price_list_id = intval($_GET['price_list_id'] ?? 0);
$changed_by = intval($_GET['changed_by'] ?? 0);
$q = sanitizeInput($_GET['q'] ?? '');

// Build query
$where_conditions = ["1=1"];
$params = [];
$param_types = '';

if ($start_date) {
    $where_conditions[] = "DATE(ph.changed_at) >= ?";
    $params[] = $start_date;
    $param_types .= 's';
}

if ($end_date) {
    $where_conditions[] = "DATE(ph.changed_at) <= ?";
    $params[] = $end_date;
    $param_types .= 's';
}

if ($entity_type) {
    $where_conditions[] = "ph.entity_type = ?";
    $params[] = $entity_type;
    $param_types .= 's';
}

if ($entity_id) {
    $where_conditions[] = "ph.entity_id = ?";
    $params[] = $entity_id;
    $param_types .= 'i';
}

if ($price_list_id) {
    $where_conditions[] = "ph.price_list_id = ?";
    $params[] = $price_list_id;
    $param_types .= 'i';
}

if ($changed_by) {
    $where_conditions[] = "ph.changed_by = ?";
    $params[] = $changed_by;
    $param_types .= 'i';
}

if ($q) {
    $where_conditions[] = "(ii.item_name LIKE ? OR ms.service_name LIKE ? OR pl.list_name LIKE ?)";
    $params[] = "%$q%";
    $params[] = "%$q%";
    $params[] = "%$q%";
    $param_types .= 'sss';
}

$where_clause = implode(" AND ", $where_conditions);

// Get total count for pagination
$count_sql = "SELECT COUNT(*) as total 
              FROM price_history ph
              LEFT JOIN inventory_items ii ON ph.entity_type = 'ITEM' AND ph.entity_id = ii.item_id
              LEFT JOIN medical_services ms ON ph.entity_type = 'SERVICE' AND ph.entity_id = ms.medical_service_id
              LEFT JOIN price_lists pl ON ph.price_list_id = pl.price_list_id
              WHERE $where_clause";

$count_stmt = $mysqli->prepare($count_sql);
if (!empty($params)) {
    $count_stmt->bind_param($param_types, ...$params);
}
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_rows = $count_result->fetch_assoc()['total'];

// Get history data
$sql = "SELECT ph.*,
               CASE 
                   WHEN ph.entity_type = 'ITEM' THEN ii.item_name
                   WHEN ph.entity_type = 'SERVICE' THEN ms.service_name
               END as entity_name,
               pl.list_name,
               u.user_name as changed_by_name,
               pl.payer_type
        FROM price_history ph
        LEFT JOIN inventory_items ii ON ph.entity_type = 'ITEM' AND ph.entity_id = ii.item_id
        LEFT JOIN medical_services ms ON ph.entity_type = 'SERVICE' AND ph.entity_id = ms.medical_service_id
        LEFT JOIN price_lists pl ON ph.price_list_id = pl.price_list_id
        LEFT JOIN users u ON ph.changed_by = u.user_id
        WHERE $where_clause
        ORDER BY ph.changed_at DESC
        LIMIT $record_from, $record_to";

$stmt = $mysqli->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($param_types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// Get price lists for filter
$price_lists = getAllPriceLists($mysqli);

// Get users who have made changes
$users_sql = "SELECT DISTINCT u.user_id, u.user_name 
              FROM price_history ph
              JOIN users u ON ph.changed_by = u.user_id
              ORDER BY u.user_name";
$users_result = $mysqli->query($users_sql);
?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-history mr-2"></i>Price Change History
        </h3>
        <div class="card-tools">
            <a href="price_management.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left mr-2"></i>Back
            </a>
            <button class="btn btn-info ml-2" onclick="exportHistory()">
                <i class="fas fa-download mr-2"></i>Export
            </button>
        </div>
    </div>
    
    <div class="card-body">
        <!-- Filters -->
        <form method="GET" class="mb-4">
            <div class="row">
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
                <div class="col-md-2">
                    <div class="form-group">
                        <label>Entity Type</label>
                        <select class="form-control" name="entity_type">
                            <option value="">All Types</option>
                            <option value="ITEM" <?php echo $entity_type == 'ITEM' ? 'selected' : ''; ?>>Item</option>
                            <option value="SERVICE" <?php echo $entity_type == 'SERVICE' ? 'selected' : ''; ?>>Service</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-group">
                        <label>Price List</label>
                        <select class="form-control" name="price_list_id">
                            <option value="">All Lists</option>
                            <?php foreach($price_lists as $pl): ?>
                            <option value="<?php echo $pl['price_list_id']; ?>" 
                                <?php echo ($price_list_id == $pl['price_list_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($pl['list_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-group">
                        <label>Changed By</label>
                        <select class="form-control" name="changed_by">
                            <option value="">All Users</option>
                            <?php while($user = $users_result->fetch_assoc()): ?>
                            <option value="<?php echo $user['user_id']; ?>" 
                                <?php echo ($changed_by == $user['user_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($user['user_name']); ?>
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-group">
                        <label>Search</label>
                        <div class="input-group">
                            <input type="text" class="form-control" name="q" value="<?php echo $q; ?>" placeholder="Search...">
                            <div class="input-group-append">
                                <button class="btn btn-primary" type="submit">
                                    <i class="fas fa-search"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="btn-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter mr-2"></i>Apply Filters
                        </button>
                        <a href="price_history.php" class="btn btn-secondary">
                            <i class="fas fa-times mr-2"></i>Clear Filters
                        </a>
                        <?php if ($start_date || $end_date || $entity_type || $price_list_id || $changed_by || $q): ?>
                            <span class="btn btn-light border">
                                <?php echo $total_rows; ?> records found
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </form>
        
        <!-- Statistics -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body py-2">
                        <div class="row text-center">
                            <div class="col-md-3">
                                <div class="font-weight-bold text-primary"><?php echo $total_rows; ?></div>
                                <small class="text-muted">Total Changes</small>
                            </div>
                            <div class="col-md-3">
                                <div class="font-weight-bold text-success"><?php 
                                    // Get today's changes
                                    $today_sql = "SELECT COUNT(*) as today FROM price_history WHERE DATE(changed_at) = CURDATE()";
                                    $today_result = $mysqli->query($today_sql);
                                    echo $today_result->fetch_assoc()['today'];
                                ?></div>
                                <small class="text-muted">Today</small>
                            </div>
                            <div class="col-md-3">
                                <div class="font-weight-bold text-info"><?php 
                                    // Get this month's changes
                                    $month_sql = "SELECT COUNT(*) as month FROM price_history WHERE MONTH(changed_at) = MONTH(CURDATE()) AND YEAR(changed_at) = YEAR(CURDATE())";
                                    $month_result = $mysqli->query($month_sql);
                                    echo $month_result->fetch_assoc()['month'];
                                ?></div>
                                <small class="text-muted">This Month</small>
                            </div>
                            <div class="col-md-3">
                                <div class="font-weight-bold text-warning"><?php 
                                    // Get unique users
                                    $users_count_sql = "SELECT COUNT(DISTINCT changed_by) as users FROM price_history";
                                    $users_count_result = $mysqli->query($users_count_sql);
                                    echo $users_count_result->fetch_assoc()['users'];
                                ?></div>
                                <small class="text-muted">Users</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- History Table -->
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Date/Time</th>
                        <th>Entity</th>
                        <th>Price List</th>
                        <th>Old Price</th>
                        <th>New Price</th>
                        <th>Change</th>
                        <th>Coverage</th>
                        <th>Changed By</th>
                        <th>Reason</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result->num_rows == 0): ?>
                    <tr>
                        <td colspan="9" class="text-center py-4">
                            <i class="fas fa-history fa-2x text-muted mb-3"></i>
                            <h5 class="text-muted">No price changes found</h5>
                            <?php if ($start_date || $end_date || $entity_type || $price_list_id || $changed_by || $q): ?>
                                <p class="text-muted">Try adjusting your filters</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php while($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td>
                            <div class="small"><?php echo date('M j, Y', strtotime($row['changed_at'])); ?></div>
                            <div class="text-muted"><?php echo date('H:i:s', strtotime($row['changed_at'])); ?></div>
                        </td>
                        <td>
                            <span class="badge badge-<?php echo $row['entity_type'] == 'ITEM' ? 'primary' : 'success'; ?>">
                                <?php echo $row['entity_type']; ?>
                            </span>
                            <div class="font-weight-bold mt-1"><?php echo htmlspecialchars($row['entity_name'] ?? 'Unknown'); ?></div>
                            <small class="text-muted">ID: <?php echo $row['entity_id']; ?></small>
                        </td>
                        <td>
                            <div><?php echo htmlspecialchars($row['list_name']); ?></div>
                            <small class="text-muted"><?php echo $row['payer_type']; ?></small>
                        </td>
                        <td>
                            <?php echo number_format($row['old_price'], 2); ?>
                        </td>
                        <td>
                            <strong class="text-primary"><?php echo number_format($row['new_price'], 2); ?></strong>
                        </td>
                        <td>
                            <?php 
                            $change = $row['new_price'] - $row['old_price'];
                            $change_percent = $row['old_price'] > 0 ? ($change / $row['old_price']) * 100 : 0;
                            ?>
                            <span class="badge badge-<?php echo $change >= 0 ? 'danger' : 'success'; ?>">
                                <?php echo ($change >= 0 ? '+' : '') . number_format($change, 2); ?>
                                (<?php echo ($change_percent >= 0 ? '+' : '') . number_format($change_percent, 1); ?>%)
                            </span>
                        </td>
                        <td>
                            <?php if ($row['old_covered_percentage'] != $row['new_covered_percentage']): ?>
                                <div class="small">
                                    <?php echo $row['old_covered_percentage']; ?>% → 
                                    <strong class="text-info"><?php echo $row['new_covered_percentage']; ?>%</strong>
                                </div>
                                <small class="text-muted">
                                    Δ <?php echo $row['new_covered_percentage'] - $row['old_covered_percentage']; ?>%
                                </small>
                            <?php else: ?>
                                <?php echo $row['new_covered_percentage']; ?>%
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo htmlspecialchars($row['changed_by_name']); ?>
                        </td>
                        <td>
                            <small><?php echo htmlspecialchars($row['change_reason']); ?></small>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/filter_footer.php'; ?>
    </div>
</div>

<script>
function exportHistory() {
    // Get current filter parameters
    var params = new URLSearchParams(window.location.search);
    
    // Redirect to export page with current filters
    window.location.href = 'price_export.php?' + params.toString() + '&type=history';
}

// Auto-refresh every 30 seconds if on first page
$(document).ready(function() {
    <?php if ($record_from == 0 && !$start_date && !$end_date && !$entity_type && !$entity_id && !$price_list_id && !$changed_by && !$q): ?>
    setTimeout(function() {
        location.reload();
    }, 30000);
    <?php endif; ?>
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
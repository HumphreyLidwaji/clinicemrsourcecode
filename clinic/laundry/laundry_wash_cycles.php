<?php
// laundry_wash_cycles.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

$page_title = "Wash History";

// Filter parameters
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$temperature = $_GET['temperature'] ?? '';
$completed_by = $_GET['completed_by'] ?? '';

// Build query
$where_conditions = ["DATE(wc.wash_date) BETWEEN ? AND ?"];
$params = [$start_date, $end_date];
$param_types = "ss";

if ($temperature) {
    $where_conditions[] = "wc.temperature = ?";
    $params[] = $temperature;
    $param_types .= "s";
}

if ($completed_by) {
    $where_conditions[] = "wc.completed_by = ?";
    $params[] = $completed_by;
    $param_types .= "i";
}

$where_clause = implode(" AND ", $where_conditions);

// Get total count
$count_sql = "
    SELECT COUNT(*) as total 
    FROM laundry_wash_cycles wc
    WHERE $where_clause
";
$count_stmt = $mysqli->prepare($count_sql);
$count_stmt->bind_param($param_types, ...$params);
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_rows = $count_result->fetch_assoc()['total'];

// Get wash cycles with pagination
$limit = 20;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $limit;

$sql = "
    SELECT wc.*, u.user_name as completed_by_name,
           COUNT(wci.wash_item_id) as item_count
    FROM laundry_wash_cycles wc
    LEFT JOIN users u ON wc.completed_by = u.user_id
    LEFT JOIN wash_cycle_items wci ON wc.wash_id = wci.wash_id
    WHERE $where_clause
    GROUP BY wc.wash_id, wc.wash_date, wc.wash_time, wc.completed_by, 
             wc.temperature, wc.detergent_type, wc.bleach_used, 
             wc.fabric_softener_used, wc.items_washed, wc.total_weight, 
             wc.notes, wc.created_at, u.user_name
    ORDER BY wc.wash_date DESC, wc.wash_time DESC
    LIMIT ? OFFSET ?
";

$params[] = $limit;
$params[] = $offset;
$param_types .= "ii";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param($param_types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// Get users for filter
$users_sql = "SELECT user_id, user_name FROM users WHERE user_status = 1 ORDER BY user_name";
$users_result = $mysqli->query($users_sql);

// Get wash statistics
$stats_sql = "
    SELECT 
        COUNT(*) as total_cycles,
        SUM(items_washed) as total_items,
        AVG(items_washed) as avg_per_wash,
        MIN(wash_date) as first_wash,
        MAX(wash_date) as last_wash,
        SUM(CASE WHEN temperature = 'hot' THEN 1 ELSE 0 END) as hot_washes,
        SUM(CASE WHEN temperature = 'warm' THEN 1 ELSE 0 END) as warm_washes,
        SUM(CASE WHEN temperature = 'cold' THEN 1 ELSE 0 END) as cold_washes
    FROM laundry_wash_cycles
    WHERE DATE(wash_date) BETWEEN ? AND ?
";
$stats_stmt = $mysqli->prepare($stats_sql);
$stats_stmt->bind_param("ss", $start_date, $end_date);
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();
$stats = $stats_result->fetch_assoc();
?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-history mr-2"></i>Wash History
        </h3>
        <div class="card-tools">
            <a href="laundry_wash_new.php" class="btn btn-primary">
                <i class="fas fa-fw fa-plus mr-2"></i>New Wash Cycle
            </a>
            <a href="laundry_management.php" class="btn btn-secondary ml-2">
                <i class="fas fa-fw fa-arrow-left mr-2"></i>Back to Laundry
            </a>
        </div>
    </div>
    
    <div class="card-body">
        <!-- Filter Form -->
        <div class="card mb-4">
            <div class="card-body bg-light">
                <form action="laundry_wash_cycles.php" method="GET" autocomplete="off">
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
                                <label>Temperature</label>
                                <select class="form-control" name="temperature">
                                    <option value="">All</option>
                                    <option value="hot" <?php echo $temperature == 'hot' ? 'selected' : ''; ?>>Hot</option>
                                    <option value="warm" <?php echo $temperature == 'warm' ? 'selected' : ''; ?>>Warm</option>
                                    <option value="cold" <?php echo $temperature == 'cold' ? 'selected' : ''; ?>>Cold</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-group">
                                <label>Completed By</label>
                                <select class="form-control select2" name="completed_by">
                                    <option value="">All Users</option>
                                    <?php while($user = $users_result->fetch_assoc()): ?>
                                        <option value="<?php echo $user['user_id']; ?>" 
                                                <?php echo $completed_by == $user['user_id'] ? 'selected' : ''; ?>>
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
                                    <a href="laundry_wash_cycles.php" class="btn btn-secondary">
                                        <i class="fas fa-times mr-2"></i>Clear
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Statistics -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card border-left-primary">
                    <div class="card-body">
                        <div class="text-uppercase text-muted small">Total Cycles</div>
                        <div class="h4 font-weight-bold"><?php echo $stats['total_cycles']; ?></div>
                        <div class="text-muted small">
                            <?php echo date('M j', strtotime($stats['first_wash'])); ?> - 
                            <?php echo date('M j', strtotime($stats['last_wash'])); ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-left-success">
                    <div class="card-body">
                        <div class="text-uppercase text-muted small">Total Items</div>
                        <div class="h4 font-weight-bold"><?php echo $stats['total_items']; ?></div>
                        <div class="text-muted small">Average: <?php echo round($stats['avg_per_wash'], 1); ?> per wash</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-left-danger">
                    <div class="card-body">
                        <div class="text-uppercase text-muted small">Hot Washes</div>
                        <div class="h4 font-weight-bold"><?php echo $stats['hot_washes']; ?></div>
                        <div class="text-muted small">For heavily soiled items</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-left-info">
                    <div class="card-body">
                        <div class="text-uppercase text-muted small">Cold Washes</div>
                        <div class="h4 font-weight-bold"><?php echo $stats['cold_washes']; ?></div>
                        <div class="text-muted small">For delicate items</div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Wash Cycles Table -->
        <div class="table-responsive">
            <table class="table table-hover">
                <thead class="bg-light">
                    <tr>
                        <th>Date & Time</th>
                        <th>Completed By</th>
                        <th>Items</th>
                        <th>Temperature</th>
                        <th>Detergent</th>
                        <th>Weight</th>
                        <th>Additives</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result->num_rows == 0): ?>
                        <tr>
                            <td colspan="8" class="text-center py-5">
                                <i class="fas fa-tint fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">No wash cycles found</h5>
                                <p class="text-muted">Try adjusting your filter criteria</p>
                                <a href="laundry_wash_new.php" class="btn btn-primary">
                                    <i class="fas fa-plus mr-2"></i>Start First Wash Cycle
                                </a>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php while($wash = $result->fetch_assoc()): ?>
                        <tr>
                            <td>
                                <strong><?php echo date('M j, Y', strtotime($wash['wash_date'])); ?></strong><br>
                                <small class="text-muted"><?php echo date('g:i A', strtotime($wash['wash_time'])); ?></small>
                            </td>
                            <td>
                                <?php echo htmlspecialchars($wash['completed_by_name']); ?>
                            </td>
                            <td>
                                <span class="badge badge-dark"><?php echo $wash['item_count']; ?> items</span>
                            </td>
                            <td>
                                <span class="badge badge-<?php 
                                    switch($wash['temperature']) {
                                        case 'hot': echo 'danger'; break;
                                        case 'warm': echo 'warning'; break;
                                        case 'cold': echo 'info'; break;
                                        default: echo 'secondary';
                                    }
                                ?>">
                                    <?php echo ucfirst($wash['temperature']); ?>
                                </span>
                            </td>
                            <td>
                                <?php echo ucfirst($wash['detergent_type'] ?? '—'); ?>
                            </td>
                            <td>
                                <?php if ($wash['total_weight']): ?>
                                    <?php echo $wash['total_weight']; ?> kg
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($wash['bleach_used']): ?>
                                    <span class="badge badge-light mr-1">Bleach</span>
                                <?php endif; ?>
                                <?php if ($wash['fabric_softener_used']): ?>
                                    <span class="badge badge-light">Softener</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="laundry_wash_view.php?id=<?php echo $wash['wash_id']; ?>" 
                                   class="btn btn-sm btn-info">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <?php if ($wash['notes']): ?>
                                <button class="btn btn-sm btn-light" data-toggle="tooltip" 
                                        title="<?php echo htmlspecialchars($wash['notes']); ?>">
                                    <i class="fas fa-sticky-note"></i>
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($total_rows > $limit): ?>
        <nav aria-label="Page navigation">
            <ul class="pagination justify-content-center mt-4">
                <?php
                $total_pages = ceil($total_rows / $limit);
                $visible_pages = 5;
                $start_page = max(1, $page - floor($visible_pages / 2));
                $end_page = min($total_pages, $start_page + $visible_pages - 1);
                $start_page = max(1, $end_page - $visible_pages + 1);
                
                // Previous button
                if ($page > 1): ?>
                <li class="page-item">
                    <a class="page-link" href="?<?php 
                        echo http_build_query(array_merge($_GET, ['page' => $page - 1]));
                    ?>">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                </li>
                <?php endif;
                
                // Page numbers
                for ($i = $start_page; $i <= $end_page; $i++): ?>
                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                    <a class="page-link" href="?<?php 
                        echo http_build_query(array_merge($_GET, ['page' => $i]));
                    ?>">
                        <?php echo $i; ?>
                    </a>
                </li>
                <?php endfor;
                
                // Next button
                if ($page < $total_pages): ?>
                <li class="page-item">
                    <a class="page-link" href="?<?php 
                        echo http_build_query(array_merge($_GET, ['page' => $page + 1]));
                    ?>">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.select2').select2();
    $('[data-toggle="tooltip"]').tooltip();
    
    // Initialize date pickers
    $('input[type="date"]').datepicker({
        format: 'yyyy-mm-dd',
        autoclose: true,
        todayHighlight: true
    });
});
</script>

<style>
.border-left-primary { border-left: 4px solid #007bff !important; }
.border-left-success { border-left: 4px solid #28a745 !important; }
.border-left-danger { border-left: 4px solid #dc3545 !important; }
.border-left-info { border-left: 4px solid #17a2b8 !important; }

.table tbody tr:hover {
    background-color: #f8f9fa;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
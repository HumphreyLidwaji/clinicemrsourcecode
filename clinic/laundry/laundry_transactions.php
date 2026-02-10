<?php
// laundry_transactions.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

$page_title = "Laundry Transactions";

// Filter parameters
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$transaction_type = $_GET['type'] ?? '';
$department = $_GET['department'] ?? '';
$performed_by = $_GET['performed_by'] ?? '';
$q = sanitizeInput($_GET['q'] ?? '');

// Build filter query
$where_conditions = ["DATE(lt.transaction_date) BETWEEN ? AND ?"];
$params = [$start_date, $end_date];
$param_types = "ss";

if ($transaction_type) {
    $where_conditions[] = "lt.transaction_type = ?";
    $params[] = $transaction_type;
    $param_types .= "s";
}

if ($department) {
    $where_conditions[] = "lt.department = ?";
    $params[] = $department;
    $param_types .= "s";
}

if ($performed_by) {
    $where_conditions[] = "lt.performed_by = ?";
    $params[] = $performed_by;
    $param_types .= "i";
}

if ($q) {
    $where_conditions[] = "(a.asset_name LIKE ? OR a.asset_tag LIKE ? OR lt.notes LIKE ?)";
    $params[] = "%$q%";
    $params[] = "%$q%";
    $params[] = "%$q%";
    $param_types .= "sss";
}

$where_clause = implode(" AND ", $where_conditions);

// Get total count for pagination
$count_sql = "
    SELECT COUNT(*) as total 
    FROM laundry_transactions lt
    LEFT JOIN laundry_items li ON lt.laundry_id = li.laundry_id
    LEFT JOIN assets a ON li.asset_id = a.asset_id
    WHERE $where_clause
";

$count_stmt = $mysqli->prepare($count_sql);
$count_stmt->bind_param($param_types, ...$params);
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_rows = $count_result->fetch_assoc()['total'];

// Get transactions with pagination
$limit = 50;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $limit;

$sql = "
    SELECT lt.*, 
           a.asset_name,
           a.asset_tag,
           u.user_name as performed_by_name,
           c.client_name,
           li.current_location
    FROM laundry_transactions lt
    LEFT JOIN laundry_items li ON lt.laundry_id = li.laundry_id
    LEFT JOIN assets a ON li.asset_id = a.asset_id
    LEFT JOIN users u ON lt.performed_by = u.user_id
    LEFT JOIN clients c ON lt.performed_for = c.client_id
    WHERE $where_clause
    ORDER BY lt.transaction_date DESC
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

// Get unique departments
$departments_sql = "SELECT DISTINCT department FROM laundry_transactions WHERE department IS NOT NULL ORDER BY department";
$departments_result = $mysqli->query($departments_sql);

// Get transaction summary
$summary_sql = "
    SELECT 
        lt.transaction_type,
        COUNT(*) as count,
        GROUP_CONCAT(DISTINCT lt.department) as departments
    FROM laundry_transactions lt
    WHERE DATE(lt.transaction_date) BETWEEN ? AND ?
    GROUP BY lt.transaction_type
    ORDER BY count DESC
";

$summary_stmt = $mysqli->prepare($summary_sql);
$summary_stmt->bind_param("ss", $start_date, $end_date);
$summary_stmt->execute();
$summary_result = $summary_stmt->get_result();
?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-history mr-2"></i>Laundry Transactions
            <small class="text-light ml-2"><?php echo number_format($total_rows); ?> records</small>
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
                <form action="laundry_transactions.php" method="GET" autocomplete="off">
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
                                <label>Transaction Type</label>
                                <select class="form-control" name="type">
                                    <option value="">All Types</option>
                                    <option value="checkout" <?php echo $transaction_type == 'checkout' ? 'selected' : ''; ?>>Checkout</option>
                                    <option value="checkin" <?php echo $transaction_type == 'checkin' ? 'selected' : ''; ?>>Checkin</option>
                                    <option value="wash" <?php echo $transaction_type == 'wash' ? 'selected' : ''; ?>>Wash</option>
                                    <option value="damage" <?php echo $transaction_type == 'damage' ? 'selected' : ''; ?>>Damage</option>
                                    <option value="lost" <?php echo $transaction_type == 'lost' ? 'selected' : ''; ?>>Lost</option>
                                    <option value="found" <?php echo $transaction_type == 'found' ? 'selected' : ''; ?>>Found</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="col-md-2">
                            <div class="form-group">
                                <label>Department</label>
                                <select class="form-control" name="department">
                                    <option value="">All Departments</option>
                                    <?php while($dept = $departments_result->fetch_assoc()): ?>
                                        <option value="<?php echo htmlspecialchars($dept['department']); ?>" 
                                                <?php echo $department == $dept['department'] ? 'selected' : ''; ?>>
                                            <?php echo ucfirst(str_replace('_', ' ', $dept['department'])); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="col-md-2">
                            <div class="form-group">
                                <label>Performed By</label>
                                <select class="form-control select2" name="performed_by">
                                    <option value="">All Users</option>
                                    <?php while($user = $users_result->fetch_assoc()): ?>
                                        <option value="<?php echo $user['user_id']; ?>" 
                                                <?php echo $performed_by == $user['user_id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($user['user_name']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-9">
                            <div class="form-group">
                                <label>Search</label>
                                <input type="text" class="form-control" name="q" value="<?php echo htmlspecialchars($q); ?>" 
                                       placeholder="Search by asset name, tag, or notes...">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>&nbsp;</label>
                                <div class="btn-group btn-block">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-filter mr-2"></i>Apply Filters
                                    </button>
                                    <a href="laundry_transactions.php" class="btn btn-secondary">
                                        <i class="fas fa-times mr-2"></i>Clear
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Summary Cards -->
        <?php if ($summary_result->num_rows > 0): ?>
        <div class="row mb-4">
            <?php while($summary = $summary_result->fetch_assoc()): ?>
            <div class="col-md-2 mb-3">
                <div class="card 
                    <?php 
                    switch($summary['transaction_type']) {
                        case 'checkout': echo 'border-left-primary'; break;
                        case 'checkin': echo 'border-left-success'; break;
                        case 'wash': echo 'border-left-info'; break;
                        case 'damage': echo 'border-left-warning'; break;
                        case 'lost': echo 'border-left-danger'; break;
                        default: echo 'border-left-secondary';
                    }
                    ?>">
                    <div class="card-body">
                        <div class="text-uppercase text-muted small">
                            <?php echo ucfirst($summary['transaction_type']); ?>
                        </div>
                        <div class="h4 font-weight-bold mb-0"><?php echo $summary['count']; ?></div>
                        <?php if ($summary['departments']): ?>
                        <div class="text-muted small mt-1">
                            <?php 
                            $depts = explode(',', $summary['departments']);
                            echo count($depts) . ' dept(s)';
                            ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endwhile; ?>
        </div>
        <?php endif; ?>
        
        <!-- Transactions Table -->
        <div class="table-responsive">
            <table class="table table-hover">
                <thead class="bg-light">
                    <tr>
                        <th>Date & Time</th>
                        <th>Item</th>
                        <th>Transaction</th>
                        <th>From → To</th>
                        <th>Performed By</th>
                        <th>For Patient</th>
                        <th>Department</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result->num_rows == 0): ?>
                        <tr>
                            <td colspan="8" class="text-center py-5">
                                <i class="fas fa-history fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">No transactions found</h5>
                                <p class="text-muted">Try adjusting your search criteria</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php while($transaction = $result->fetch_assoc()): ?>
                        <tr>
                            <td width="15%">
                                <small class="text-muted">
                                    <?php echo date('M j, Y', strtotime($transaction['transaction_date'])); ?><br>
                                    <?php echo date('g:i A', strtotime($transaction['transaction_date'])); ?>
                                </small>
                            </td>
                            <td width="20%">
                                <strong><?php echo htmlspecialchars($transaction['asset_name']); ?></strong><br>
                                <small class="text-muted"><?php echo htmlspecialchars($transaction['asset_tag']); ?></small>
                            </td>
                            <td width="10%">
                                <span class="badge badge-<?php 
                                    switch($transaction['transaction_type']) {
                                        case 'checkout': echo 'success'; break;
                                        case 'checkin': echo 'primary'; break;
                                        case 'wash': echo 'info'; break;
                                        case 'damage': echo 'warning'; break;
                                        case 'lost': echo 'danger'; break;
                                        case 'found': echo 'success'; break;
                                        default: echo 'secondary';
                                    }
                                ?>">
                                    <?php echo ucfirst($transaction['transaction_type']); ?>
                                </span>
                            </td>
                            <td width="15%">
                                <?php if ($transaction['from_location'] && $transaction['to_location']): ?>
                                <small>
                                    <span class="badge badge-light"><?php echo ucfirst($transaction['from_location']); ?></span>
                                    <i class="fas fa-arrow-right text-muted mx-1"></i>
                                    <span class="badge badge-light"><?php echo ucfirst($transaction['to_location']); ?></span>
                                </small>
                                <?php else: ?>
                                <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td width="15%">
                                <small>
                                    <i class="fas fa-user text-muted mr-1"></i>
                                    <?php echo htmlspecialchars($transaction['performed_by_name']); ?>
                                </small>
                            </td>
                            <td width="10%">
                                <?php if ($transaction['client_name']): ?>
                                <small class="text-info">
                                    <i class="fas fa-user-injured mr-1"></i>
                                    <?php echo htmlspecialchars($transaction['client_name']); ?>
                                </small>
                                <?php else: ?>
                                <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td width="10%">
                                <?php if ($transaction['department']): ?>
                                <small class="text-muted">
                                    <?php echo ucfirst(str_replace('_', ' ', $transaction['department'])); ?>
                                </small>
                                <?php else: ?>
                                <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td width="15%">
                                <?php if ($transaction['notes']): ?>
                                <small class="text-muted">
                                    <?php echo htmlspecialchars(substr($transaction['notes'], 0, 50)); ?>
                                    <?php if (strlen($transaction['notes']) > 50): ?>...<?php endif; ?>
                                </small>
                                <?php else: ?>
                                <span class="text-muted">—</span>
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
            <ul class="pagination justify-content-center">
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
    
    // Initialize date pickers
    $('input[type="date"]').datepicker({
        format: 'yyyy-mm-dd',
        autoclose: true,
        todayHighlight: true
    });
    
    // Auto-submit on some filter changes
    $('select[name="type"], select[name="department"]').change(function() {
        $(this).closest('form').submit();
    });
});
</script>

<style>
@media print {
    .card-header, .btn, .form-group, .pagination, .card.mb-4 {
        display: none !important;
    }
    
    .card {
        border: none !important;
        box-shadow: none !important;
    }
    
    .table {
        font-size: 11px !important;
    }
}

.border-left-primary { border-left: 4px solid #007bff !important; }
.border-left-success { border-left: 4px solid #28a745 !important; }
.border-left-info { border-left: 4px solid #17a2b8 !important; }
.border-left-warning { border-left: 4px solid #ffc107 !important; }
.border-left-danger { border-left: 4px solid #dc3545 !important; }
.border-left-secondary { border-left: 4px solid #6c757d !important; }

.table tbody tr:hover {
    background-color: #f8f9fa;
}

.pagination .page-item.active .page-link {
    background-color: #007bff;
    border-color: #007bff;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
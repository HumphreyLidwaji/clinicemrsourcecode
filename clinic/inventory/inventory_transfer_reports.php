<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';



// Default date range (last 30 days)
$start_date = date('Y-m-01');
$end_date = date('Y-m-d');
$location_id = 0;
$status = 'all';

// Process filters
if ($_SERVER['REQUEST_METHOD'] == 'POST' || isset($_GET['filter'])) {
    $start_date = $_POST['start_date'] ?? $_GET['start_date'] ?? $start_date;
    $end_date = $_POST['end_date'] ?? $_GET['end_date'] ?? $end_date;
    $location_id = intval($_POST['location_id'] ?? $_GET['location_id'] ?? 0);
    $status = $_POST['status'] ?? $_GET['status'] ?? 'all';
}

// Build query for transfers
$where_conditions = [];
$params = [];
$types = "";

$where_conditions[] = "t.transfer_date BETWEEN ? AND ?";
$params[] = $start_date . ' 00:00:00';
$params[] = $end_date . ' 23:59:59';
$types .= "ss";

if ($location_id > 0) {
    $where_conditions[] = "(t.from_location_id = ? OR t.to_location_id = ?)";
    $params[] = $location_id;
    $params[] = $location_id;
    $types .= "ii";
}

if ($status != 'all') {
    $where_conditions[] = "t.transfer_status = ?";
    $params[] = $status;
    $types .= "s";
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Get transfers for the report
$transfers_sql = "SELECT t.*, 
                         fl.location_name as from_location_name,
                         fl.location_type as from_location_type,
                         tl.location_name as to_location_name,
                         tl.location_type as to_location_type,
                         u.user_name as requested_by_name,
                         COUNT(ti.item_id) as item_count,
                         SUM(ti.quantity) as total_quantity
                  FROM inventory_transfers t
                  LEFT JOIN inventory_locations fl ON t.from_location_id = fl.location_id
                  LEFT JOIN inventory_locations tl ON t.to_location_id = tl.location_id
                  LEFT JOIN users u ON t.requested_by = u.user_id
                  LEFT JOIN inventory_transfer_items ti ON t.transfer_id = ti.transfer_id
                  $where_clause
                  GROUP BY t.transfer_id
                  ORDER BY t.transfer_date DESC";
$transfers_stmt = $mysqli->prepare($transfers_sql);

if (!empty($params)) {
    $transfers_stmt->bind_param($types, ...$params);
}

$transfers_stmt->execute();
$transfers_result = $transfers_stmt->get_result();
$transfers = [];
$total_transfers = 0;
$total_items = 0;
$total_quantity = 0;

while ($transfer = $transfers_result->fetch_assoc()) {
    $transfers[] = $transfer;
    $total_transfers++;
    $total_items += $transfer['item_count'];
    $total_quantity += $transfer['total_quantity'];
}
$transfers_stmt->close();

// Get statistics by status
$stats_sql = "SELECT 
                SUM(CASE WHEN transfer_status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN transfer_status = 'in_transit' THEN 1 ELSE 0 END) as in_transit,
                SUM(CASE WHEN transfer_status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN transfer_status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                COUNT(*) as total
              FROM inventory_transfers
              WHERE transfer_date BETWEEN ? AND ?";

$stats_stmt = $mysqli->prepare($stats_sql);

$from = $start_date . ' 00:00:00';
$to   = $end_date . ' 23:59:59';

$stats_stmt->bind_param("ss", $from, $to);
$stats_stmt->execute();

$stats_result = $stats_stmt->get_result();
$stats = $stats_result->fetch_assoc();
$stats_stmt->close();


// Get top items transferred
$top_items_sql = "SELECT 
                    i.item_name, 
                    i.item_code, 
                    i.item_unit_measure,
                    SUM(ti.quantity) as total_transferred,
                    COUNT(DISTINCT ti.transfer_id) as transfer_count
                  FROM inventory_transfer_items ti
                  JOIN inventory_items i ON ti.item_id = i.item_id
                  JOIN inventory_transfers t ON ti.transfer_id = t.transfer_id
                  WHERE t.transfer_date BETWEEN ? AND ?
                  GROUP BY i.item_id
                  ORDER BY total_transferred DESC
                  LIMIT 10";

$top_items_stmt = $mysqli->prepare($top_items_sql);

$from = $start_date . ' 00:00:00';
$to   = $end_date . ' 23:59:59';

$top_items_stmt->bind_param("ss", $from, $to);
$top_items_stmt->execute();

$top_items_result = $top_items_stmt->get_result();

$top_items = [];
while ($item = $top_items_result->fetch_assoc()) {
    $top_items[] = $item;
}

$top_items_stmt->close();


// Get locations
$locations_sql = "SELECT location_id, location_name, location_type 
                 FROM inventory_locations 
               ";
$locations_result = $mysqli->query($locations_sql);
$locations = [];
while ($loc = $locations_result->fetch_assoc()) {
    $locations[] = $loc;
}
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0 text-white">
                <i class="fas fa-fw fa-chart-bar mr-2"></i>
                Transfer Reports
            </h3>
            <div class="card-tools">
                <button type="button" class="btn btn-light" onclick="printReport()">
                    <i class="fas fa-print mr-2"></i>Print Report
                </button>
                <button type="button" class="btn btn-light ml-2" onclick="exportToExcel()">
                    <i class="fas fa-file-excel mr-2"></i>Export Excel
                </button>
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

        <!-- Filters -->
        <div class="card card-info mb-4">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-filter mr-2"></i>Report Filters</h3>
                <div class="card-tools">
                    <button type="button" class="btn btn-tool" data-card-widget="collapse">
                        <i class="fas fa-minus"></i>
                    </button>
                </div>
            </div>
            <div class="card-body">
                <form method="GET" action="" id="filterForm">
                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="start_date">Start Date</label>
                                <input type="date" class="form-control" name="start_date" 
                                       id="start_date" value="<?php echo $start_date; ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="end_date">End Date</label>
                                <input type="date" class="form-control" name="end_date" 
                                       id="end_date" value="<?php echo $end_date; ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="location_id">Location</label>
                                <select class="form-control" name="location_id" id="location_id">
                                    <option value="0">All Locations</option>
                                    <?php foreach ($locations as $loc): ?>
                                    <option value="<?php echo $loc['location_id']; ?>" 
                                            <?php echo $location_id == $loc['location_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($loc['location_type'] . ' - ' . $loc['location_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="status">Status</label>
                                <select class="form-control" name="status" id="status">
                                    <option value="all" <?php echo $status == 'all' ? 'selected' : ''; ?>>All Statuses</option>
                                    <option value="pending" <?php echo $status == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="in_transit" <?php echo $status == 'in_transit' ? 'selected' : ''; ?>>In Transit</option>
                                    <option value="completed" <?php echo $status == 'completed' ? 'selected' : ''; ?>>Completed</option>
                                    <option value="cancelled" <?php echo $status == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary" name="filter">
                                <i class="fas fa-search mr-2"></i>Apply Filters
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="resetFilters()">
                                <i class="fas fa-redo mr-2"></i>Reset
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-lg-3 col-6">
                <div class="small-box bg-info">
                    <div class="inner">
                        <h3><?php echo $total_transfers; ?></h3>
                        <p>Total Transfers</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-truck-moving"></i>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-3 col-6">
                <div class="small-box bg-success">
                    <div class="inner">
                        <h3><?php echo $total_items; ?></h3>
                        <p>Total Items</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-boxes"></i>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-3 col-6">
                <div class="small-box bg-warning">
                    <div class="inner">
                        <h3><?php echo number_format($total_quantity); ?></h3>
                        <p>Total Quantity</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-weight"></i>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-3 col-6">
                <div class="small-box bg-danger">
                    <div class="inner">
                        <h3><?php echo $stats['completed'] ?? 0; ?></h3>
                        <p>Completed</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-8">
                <!-- Transfers Report -->
                <div class="card card-primary">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-list mr-2"></i>Transfers List</h3>
                        <div class="card-tools">
                            <span class="badge badge-light"><?php echo count($transfers); ?> transfers</span>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0" id="transfersTable">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Transfer #</th>
                                        <th>Date</th>
                                        <th>From → To</th>
                                        <th class="text-center">Items</th>
                                        <th class="text-center">Status</th>
                                        <th class="text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($transfers as $transfer): ?>
                                    <?php 
                                    // Status badge
                                    switch($transfer['transfer_status']) {
                                        case 'pending':
                                            $status_class = 'warning';
                                            break;
                                        case 'in_transit':
                                            $status_class = 'info';
                                            break;
                                        case 'completed':
                                            $status_class = 'success';
                                            break;
                                        case 'cancelled':
                                            $status_class = 'danger';
                                            break;
                                        default:
                                            $status_class = 'secondary';
                                    }
                                    ?>
                                    <tr>
                                        <td>
                                            <a href="inventory_transfer_view.php?id=<?php echo $transfer['transfer_id']; ?>">
                                                <strong><?php echo htmlspecialchars($transfer['transfer_number']); ?></strong>
                                            </a>
                                            <br>
                                            <small class="text-muted">
                                                <?php echo htmlspecialchars($transfer['requested_by_name']); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <?php if ($transfer['transfer_date']): ?>
                                                <?php echo date('M j, Y', strtotime($transfer['transfer_date'])); ?>
                                                <br>
                                                <small class="text-muted">
                                                    <?php echo date('H:i', strtotime($transfer['transfer_date'])); ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="small text-danger">
                                                <i class="fas fa-arrow-up mr-1"></i>
                                                <?php echo htmlspecialchars($transfer['from_location_name']); ?>
                                            </div>
                                            <div class="small text-success">
                                                <i class="fas fa-arrow-down mr-1"></i>
                                                <?php echo htmlspecialchars($transfer['to_location_name']); ?>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge badge-light">
                                                <?php echo $transfer['item_count']; ?> items
                                            </span>
                                            <br>
                                            <small class="text-muted">
                                                <?php echo number_format($transfer['total_quantity']); ?> units
                                            </small>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge badge-<?php echo $status_class; ?>">
                                                <?php echo ucwords(str_replace('_', ' ', $transfer['transfer_status'])); ?>
                                            </span>
                                        </td>
                                        <td class="text-right">
                                            <a href="inventory_transfer_view.php?id=<?php echo $transfer['transfer_id']; ?>" 
                                               class="btn btn-sm btn-info" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="inventory_transfer_print.php?id=<?php echo $transfer['transfer_id']; ?>" 
                                               class="btn btn-sm btn-secondary" title="Print" target="_blank">
                                                <i class="fas fa-print"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    
                                    <?php if (empty($transfers)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-3">
                                            <i class="fas fa-clipboard-list fa-2x text-muted mb-2"></i>
                                            <p class="text-muted mb-0">No transfers found for the selected filters.</p>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <!-- Status Breakdown -->
                <div class="card card-success">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-chart-pie mr-2"></i>Status Breakdown</h3>
                    </div>
                    <div class="card-body">
                        <canvas id="statusChart" height="200"></canvas>
                        <div class="mt-3">
                            <table class="table table-sm">
                                <tr>
                                    <td><span class="badge badge-warning">■</span> Pending</td>
                                    <td class="text-right"><?php echo $stats['pending'] ?? 0; ?></td>
                                </tr>
                                <tr>
                                    <td><span class="badge badge-info">■</span> In Transit</td>
                                    <td class="text-right"><?php echo $stats['in_transit'] ?? 0; ?></td>
                                </tr>
                                <tr>
                                    <td><span class="badge badge-success">■</span> Completed</td>
                                    <td class="text-right"><?php echo $stats['completed'] ?? 0; ?></td>
                                </tr>
                                <tr>
                                    <td><span class="badge badge-danger">■</span> Cancelled</td>
                                    <td class="text-right"><?php echo $stats['cancelled'] ?? 0; ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Top Items -->
                <div class="card card-info mt-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-star mr-2"></i>Top Transferred Items</h3>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Item</th>
                                        <th class="text-center">Transfers</th>
                                        <th class="text-right">Quantity</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($top_items as $item): ?>
                                    <tr>
                                        <td>
                                            <div class="font-weight-bold small"><?php echo htmlspecialchars($item['item_name']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($item['item_code']); ?></small>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge badge-light"><?php echo $item['transfer_count']; ?></span>
                                        </td>
                                        <td class="text-right">
                                            <strong><?php echo number_format($item['total_transferred']); ?></strong>
                                            <br>
                                            <small class="text-muted"><?php echo $item['item_unit_measure']; ?></small>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    
                                    <?php if (empty($top_items)): ?>
                                    <tr>
                                        <td colspan="3" class="text-center py-3">
                                            <small class="text-muted">No data available</small>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Quick Export -->
                <div class="card card-secondary mt-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-download mr-2"></i>Export Options</h3>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <button type="button" class="btn btn-success" onclick="exportToExcel()">
                                <i class="fas fa-file-excel mr-2"></i>Export to Excel
                            </button>
                            <button type="button" class="btn btn-info" onclick="exportToCSV()">
                                <i class="fas fa-file-csv mr-2"></i>Export to CSV
                            </button>
                            <button type="button" class="btn btn-dark" onclick="printReport()">
                                <i class="fas fa-print mr-2"></i>Print Report
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Initialize status chart
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('statusChart').getContext('2d');
    const statusChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Pending', 'In Transit', 'Completed', 'Cancelled'],
            datasets: [{
                data: [
                    <?php echo $stats['pending'] ?? 0; ?>,
                    <?php echo $stats['in_transit'] ?? 0; ?>,
                    <?php echo $stats['completed'] ?? 0; ?>,
                    <?php echo $stats['cancelled'] ?? 0; ?>
                ],
                backgroundColor: [
                    '#ffc107',
                    '#17a2b8',
                    '#28a745',
                    '#dc3545'
                ],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    display: false
                }
            }
        }
    });
});

function resetFilters() {
    document.getElementById('start_date').value = '';
    document.getElementById('end_date').value = '';
    document.getElementById('location_id').value = '0';
    document.getElementById('status').value = 'all';
    document.getElementById('filterForm').submit();
}

function printReport() {
    const originalContent = document.body.innerHTML;
    const reportContent = document.querySelector('.card').innerHTML;
    
    document.body.innerHTML = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>Transfer Report - <?php echo date('Y-m-d'); ?></title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                .print-header { text-align: center; margin-bottom: 30px; }
                .print-header h1 { color: #333; }
                .print-meta { color: #666; margin-bottom: 20px; }
                table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
                th { background-color: #f5f5f5; padding: 10px; text-align: left; border: 1px solid #ddd; }
                td { padding: 8px; border: 1px solid #ddd; }
                .badge { padding: 3px 8px; border-radius: 3px; font-size: 12px; }
                .badge-warning { background-color: #ffc107; color: #000; }
                .badge-info { background-color: #17a2b8; color: #fff; }
                .badge-success { background-color: #28a745; color: #fff; }
                .badge-danger { background-color: #dc3545; color: #fff; }
                .text-right { text-align: right; }
                .text-center { text-align: center; }
                .footer { margin-top: 30px; text-align: center; color: #666; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class="print-header">
                <h1>Transfer Report</h1>
                <div class="print-meta">
                    Period: <?php echo $start_date; ?> to <?php echo $end_date; ?> | 
                    Generated: <?php echo date('Y-m-d H:i'); ?>
                </div>
            </div>
            
            <div class="print-stats">
                <h3>Summary</h3>
                <p>Total Transfers: <?php echo $total_transfers; ?> | 
                   Total Items: <?php echo $total_items; ?> | 
                   Total Quantity: <?php echo number_format($total_quantity); ?></p>
            </div>
            
            <h3>Transfers List</h3>
            ${document.getElementById('transfersTable').outerHTML}
            
            <div class="footer">
                <p>Generated by Inventory System | Page 1 of 1</p>
            </div>
        </body>
        </html>
    `;
    
    window.print();
    document.body.innerHTML = originalContent;
    window.location.reload();
}

function exportToExcel() {
    // Simple CSV export for now
    exportToCSV();
}

function exportToCSV() {
    const rows = [];
    const headers = ['Transfer #', 'Date', 'From Location', 'To Location', 'Items', 'Quantity', 'Status', 'Requested By'];
    
    rows.push(headers.join(','));
    
    <?php foreach ($transfers as $transfer): ?>
    rows.push([
        '<?php echo addslashes($transfer['transfer_number']); ?>',
        '<?php echo date('Y-m-d H:i', strtotime($transfer['transfer_date'])); ?>',
        '<?php echo addslashes($transfer['from_location_name']); ?>',
        '<?php echo addslashes($transfer['to_location_name']); ?>',
        '<?php echo $transfer['item_count']; ?>',
        '<?php echo $transfer['total_quantity']; ?>',
        '<?php echo ucwords(str_replace('_', ' ', $transfer['transfer_status'])); ?>',
        '<?php echo addslashes($transfer['requested_by_name']); ?>'
    ].join(','));
    <?php endforeach; ?>
    
    const csvContent = "data:text/csv;charset=utf-8," + rows.join("\n");
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", "transfers_report_<?php echo date('Y-m-d'); ?>.csv");
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// Auto-submit form on filter change
document.getElementById('filterForm').addEventListener('change', function(e) {
    if (e.target.tagName === 'SELECT' || e.target.tagName === 'INPUT') {
        setTimeout(() => {
            if (document.getElementById('start_date').value && document.getElementById('end_date').value) {
                this.submit();
            }
        }, 500);
    }
});

// Date range validation
document.getElementById('end_date').addEventListener('change', function() {
    const startDate = new Date(document.getElementById('start_date').value);
    const endDate = new Date(this.value);
    
    if (startDate > endDate) {
        alert('End date cannot be earlier than start date!');
        this.value = document.getElementById('start_date').value;
    }
});
</script>

<style>
.small-box {
    border-radius: 0.25rem;
    box-shadow: 0 0 1px rgba(0,0,0,.125), 0 1px 3px rgba(0,0,0,.2);
    display: block;
    margin-bottom: 20px;
    position: relative;
}

.small-box > .inner {
    padding: 10px;
}

.small-box .icon {
    position: absolute;
    top: -10px;
    right: 10px;
    z-index: 0;
    font-size: 70px;
    color: rgba(0,0,0,0.15);
    transition: all .3s linear;
}

.small-box:hover .icon {
    font-size: 75px;
}

.table th {
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.85em;
    letter-spacing: 0.5px;
}

#transfersTable tbody tr:hover {
    background-color: #f8f9fa;
    cursor: pointer;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
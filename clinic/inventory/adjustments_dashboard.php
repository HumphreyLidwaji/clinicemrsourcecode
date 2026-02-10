<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Check if specific item ID is provided
$item_id = intval($_GET['item_id'] ?? 0);
$view_all = $item_id <= 0;

// Get item details if specific item is requested
if (!$view_all) {
    $item_sql = "SELECT i.*, 
                        c.category_name, c.category_type,
                        s.supplier_name,
                        u.user_name as added_by_name
                 FROM inventory_items i
                 LEFT JOIN inventory_categories c ON i.item_category_id = c.category_id
                 LEFT JOIN suppliers s ON i.item_supplier_id = s.supplier_id
                 LEFT JOIN users u ON i.item_added_by = u.user_id
                 WHERE i.item_id = ?";
    $item_stmt = $mysqli->prepare($item_sql);
    $item_stmt->bind_param("i", $item_id);
    $item_stmt->execute();
    $item_result = $item_stmt->get_result();

    if ($item_result->num_rows === 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Item not found.";
        header("Location: inventory_dashboard.php");
        exit;
    }

    $item = $item_result->fetch_assoc();
    $item_stmt->close();
}

// Build query based on whether we're viewing all transactions or specific item
if ($view_all) {
    $transactions_sql = "SELECT 
        t.transaction_id,
        t.item_id,
        t.transaction_type,
        t.quantity_change,
        t.previous_quantity,
        t.new_quantity,
        t.transaction_date,
        t.transaction_reference,
        t.transaction_notes,
        u.user_name as performed_by_name,
        i.item_name,
        i.item_code,
        i.item_unit_measure,
        sa.adjustment_reason,
        sa.notes as adjustment_notes
    FROM inventory_transactions t
    LEFT JOIN users u ON t.performed_by = u.user_id
    LEFT JOIN inventory_items i ON t.item_id = i.item_id
    LEFT JOIN stock_adjustments sa ON t.transaction_reference = 'STOCK_ADJUST' AND t.transaction_date = sa.adjustment_date
    ORDER BY t.transaction_date DESC, t.transaction_id DESC";
    
    $transactions_stmt = $mysqli->prepare($transactions_sql);
} else {
    $transactions_sql = "SELECT 
        t.transaction_id,
        t.item_id,
        t.transaction_type,
        t.quantity_change,
        t.previous_quantity,
        t.new_quantity,
        t.transaction_date,
        t.transaction_reference,
        t.transaction_notes,
        u.user_name as performed_by_name,
        i.item_name,
        i.item_code,
        i.item_unit_measure,
        sa.adjustment_reason,
        sa.notes as adjustment_notes
    FROM inventory_transactions t
    LEFT JOIN users u ON t.performed_by = u.user_id
    LEFT JOIN inventory_items i ON t.item_id = i.item_id
    LEFT JOIN stock_adjustments sa ON t.transaction_reference = 'STOCK_ADJUST' AND t.transaction_date = sa.adjustment_date
    WHERE t.item_id = ?
    ORDER BY t.transaction_date DESC, t.transaction_id DESC";
    
    $transactions_stmt = $mysqli->prepare($transactions_sql);
    $transactions_stmt->bind_param("i", $item_id);
}

$transactions_stmt->execute();
$transactions_result = $transactions_stmt->get_result();

// Get transaction statistics
if ($view_all) {
    $stats_sql = "SELECT 
        COUNT(*) as total_transactions,
        COUNT(CASE WHEN transaction_type = 'in' THEN 1 END) as incoming_transactions,
        COUNT(CASE WHEN transaction_type = 'out' THEN 1 END) as outgoing_transactions,
        COUNT(CASE WHEN transaction_type = 'adjustment' THEN 1 END) as adjustment_transactions,
        SUM(CASE WHEN transaction_type = 'in' THEN quantity_change ELSE 0 END) as total_incoming,
        SUM(CASE WHEN transaction_type = 'out' THEN ABS(quantity_change) ELSE 0 END) as total_outgoing,
        COUNT(DISTINCT item_id) as unique_items
    FROM inventory_transactions";
    
    $stats_stmt = $mysqli->prepare($stats_sql);
} else {
    $stats_sql = "SELECT 
        COUNT(*) as total_transactions,
        COUNT(CASE WHEN transaction_type = 'in' THEN 1 END) as incoming_transactions,
        COUNT(CASE WHEN transaction_type = 'out' THEN 1 END) as outgoing_transactions,
        COUNT(CASE WHEN transaction_type = 'adjustment' THEN 1 END) as adjustment_transactions,
        SUM(CASE WHEN transaction_type = 'in' THEN quantity_change ELSE 0 END) as total_incoming,
        SUM(CASE WHEN transaction_type = 'out' THEN ABS(quantity_change) ELSE 0 END) as total_outgoing
    FROM inventory_transactions 
    WHERE item_id = ?";
    
    $stats_stmt = $mysqli->prepare($stats_sql);
    $stats_stmt->bind_param("i", $item_id);
}

$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();
$stats = $stats_result->fetch_assoc();
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0 text-white">
                <i class="fas fa-fw fa-history mr-2"></i>
                <?php echo $view_all ? 'All Inventory Transactions' : 'Transaction History: ' . htmlspecialchars($item['item_name']); ?>
            </h3>
            <div class="card-tools">
                <?php if ($view_all): ?>
                    <a href="inventory_dashboard.php" class="btn btn-light">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
                    </a>
                <?php else: ?>
                    <a href="inventory_item_details.php?item_id=<?php echo $item_id; ?>" class="btn btn-light">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Item
                    </a>
                <?php endif; ?>
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

        <!-- Header Information -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card bg-light">
                    <div class="card-body">
                        <div class="row">
                            <?php if ($view_all): ?>
                                <div class="col-md-3">
                                    <strong>Viewing:</strong> 
                                    <span class="badge badge-primary">All Inventory Items</span>
                                </div>
                                <div class="col-md-3">
                                    <strong>Total Transactions:</strong> 
                                    <span class="badge badge-info"><?php echo $stats['total_transactions']; ?> records</span>
                                </div>
                                <div class="col-md-3">
                                    <strong>Unique Items:</strong> 
                                    <span class="badge badge-success"><?php echo $stats['unique_items']; ?> items</span>
                                </div>
                                <div class="col-md-3">
                                    <strong>Stock Movement:</strong> 
                                    <span class="badge badge-success">+<?php echo $stats['total_incoming'] ?? 0; ?></span>
                                    <span class="badge badge-danger">-<?php echo $stats['total_outgoing'] ?? 0; ?></span>
                                </div>
                            <?php else: ?>
                                <div class="col-md-3">
                                    <strong>Item:</strong> <?php echo htmlspecialchars($item['item_name']); ?>
                                    <br><small class="text-muted"><?php echo htmlspecialchars($item['item_code']); ?></small>
                                </div>
                                <div class="col-md-3">
                                    <strong>Current Stock:</strong> 
                                    <span class="badge badge-<?php 
                                        echo $item['item_status'] == 'In Stock' ? 'success' : 
                                             ($item['item_status'] == 'Low Stock' ? 'warning' : 
                                             ($item['item_status'] == 'Out of Stock' ? 'danger' : 'secondary')); 
                                    ?>"><?php echo $item['item_quantity']; ?> <?php echo htmlspecialchars($item['item_unit_measure']); ?></span>
                                </div>
                                <div class="col-md-3">
                                    <strong>Total Transactions:</strong> 
                                    <span class="badge badge-primary"><?php echo $stats['total_transactions']; ?> records</span>
                                </div>
                                <div class="col-md-3">
                                    <strong>Stock Movement:</strong> 
                                    <span class="badge badge-success">+<?php echo $stats['total_incoming'] ?? 0; ?></span>
                                    <span class="badge badge-danger">-<?php echo $stats['total_outgoing'] ?? 0; ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Transaction Statistics -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="info-box bg-success">
                    <span class="info-box-icon"><i class="fas fa-sign-in-alt"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Incoming</span>
                        <span class="info-box-number"><?php echo $stats['incoming_transactions']; ?></span>
                        <span class="progress-description">
                            +<?php echo $stats['total_incoming'] ?? 0; ?> units
                        </span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-danger">
                    <span class="info-box-icon"><i class="fas fa-sign-out-alt"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Outgoing</span>
                        <span class="info-box-number"><?php echo $stats['outgoing_transactions']; ?></span>
                        <span class="progress-description">
                            -<?php echo $stats['total_outgoing'] ?? 0; ?> units
                        </span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-warning">
                    <span class="info-box-icon"><i class="fas fa-adjust"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Adjustments</span>
                        <span class="info-box-number"><?php echo $stats['adjustment_transactions']; ?></span>
                        <span class="progress-description">
                            Manual corrections
                        </span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-info">
                    <span class="info-box-icon"><i class="fas fa-chart-line"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Net Change</span>
                        <span class="info-box-number">
                            <?php echo ($stats['total_incoming'] - $stats['total_outgoing']); ?>
                        </span>
                        <span class="progress-description">
                            Overall movement
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Transactions Table -->
        <div class="card">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">
                    <i class="fas fa-list mr-2"></i>
                    <?php echo $view_all ? 'All Inventory Transactions' : 'Item Transactions'; ?>
                    <span class="badge badge-secondary ml-2"><?php echo $stats['total_transactions']; ?> records</span>
                </h5>
                <?php if ($view_all): ?>
                    <a href="inventory_dashboard.php" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-cube mr-1"></i>View Inventory
                    </a>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <?php if ($transactions_result->num_rows > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-striped mb-0" id="transactionsTable">
                            <thead class="bg-light">
                                <tr>
                                    <?php if ($view_all): ?>
                                        <th>Item</th>
                                    <?php endif; ?>
                                    <th>Date & Time</th>
                                    <th>Type</th>
                                    <th>Reference</th>
                                    <th>Previous Qty</th>
                                    <th>Change</th>
                                    <th>New Qty</th>
                                    <th>Performed By</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($transaction = $transactions_result->fetch_assoc()): 
                                    $change_class = $transaction['quantity_change'] > 0 ? 'text-success' : 'text-danger';
                                    $change_sign = $transaction['quantity_change'] > 0 ? '+' : '';
                                    $type_badge = '';
                                    
                                    switch($transaction['transaction_type']) {
                                        case 'in':
                                            $type_badge = 'badge-success';
                                            $type_icon = 'fa-sign-in-alt';
                                            break;
                                        case 'out':
                                            $type_badge = 'badge-danger';
                                            $type_icon = 'fa-sign-out-alt';
                                            break;
                                        case 'adjustment':
                                            $type_badge = 'badge-warning';
                                            $type_icon = 'fa-adjust';
                                            break;
                                        default:
                                            $type_badge = 'badge-secondary';
                                            $type_icon = 'fa-exchange-alt';
                                    }
                                ?>
                                    <tr <?php if ($view_all): ?>style="cursor: pointer;" onclick="window.location.href='inventory_transactions.php?item_id=<?php echo $transaction['item_id']; ?>'"<?php endif; ?>>
                                        <?php if ($view_all): ?>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="mr-2">
                                                        <i class="fas fa-cube text-primary"></i>
                                                    </div>
                                                    <div>
                                                        <strong><?php echo htmlspecialchars($transaction['item_name']); ?></strong>
                                                        <br>
                                                        <small class="text-muted"><?php echo htmlspecialchars($transaction['item_code']); ?></small>
                                                    </div>
                                                </div>
                                            </td>
                                        <?php endif; ?>
                                        <td class="text-nowrap">
                                            <i class="fas fa-clock text-muted mr-1"></i>
                                            <?php echo date('M j, Y g:i A', strtotime($transaction['transaction_date'])); ?>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $type_badge; ?> badge-sm">
                                                <i class="fas <?php echo $type_icon; ?> mr-1"></i>
                                                <?php echo ucfirst($transaction['transaction_type']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($transaction['transaction_reference']): ?>
                                                <small class="text-muted">
                                                    <?php 
                                                    if ($transaction['transaction_reference'] === 'STOCK_ADJUST') {
                                                        echo 'Stock Adjustment';
                                                    } else {
                                                        echo htmlspecialchars($transaction['transaction_reference']);
                                                    }
                                                    ?>
                                                </small>
                                            <?php else: ?>
                                                <small class="text-muted">Manual</small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="font-weight-bold">
                                            <?php echo $transaction['previous_quantity']; ?>
                                        </td>
                                        <td class="font-weight-bold <?php echo $change_class; ?>">
                                            <?php echo $change_sign . $transaction['quantity_change']; ?>
                                        </td>
                                        <td class="font-weight-bold text-primary">
                                            <?php echo $transaction['new_quantity']; ?>
                                        </td>
                                        <td>
                                            <small class="text-muted"><?php echo htmlspecialchars($transaction['performed_by_name'] ?? 'System'); ?></small>
                                        </td>
                                        <td>
                                            <?php 
                                            $notes = '';
                                            if (!empty($transaction['adjustment_reason'])) {
                                                $notes = $transaction['adjustment_reason'];
                                                if (!empty($transaction['adjustment_notes'])) {
                                                    $notes .= ' - ' . $transaction['adjustment_notes'];
                                                }
                                            } elseif (!empty($transaction['transaction_notes'])) {
                                                $notes = $transaction['transaction_notes'];
                                            }
                                            
                                            if (!empty($notes)): ?>
                                                <small class="text-muted" title="<?php echo htmlspecialchars($notes); ?>">
                                                    <?php echo strlen($notes) > 50 ? substr($notes, 0, 50) . '...' : $notes; ?>
                                                </small>
                                            <?php else: ?>
                                                <small class="text-muted">-</small>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-5">
                        <i class="fas fa-history fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No Transactions Found</h5>
                        <p class="text-muted">
                            <?php echo $view_all ? 'There are no inventory transactions in the system yet.' : 'This item has no transaction history yet.'; ?>
                        </p>
                        <?php if (!$view_all): ?>
                            <a href="inventory_adjust_stock.php?item_id=<?php echo $item_id; ?>" class="btn btn-primary">
                                <i class="fas fa-adjust mr-2"></i>Make First Adjustment
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Export Options -->
        <?php if ($transactions_result->num_rows > 0): ?>
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h6 class="card-title mb-0">
                            <i class="fas fa-download mr-2"></i>Export Options
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="btn-group">
                            <button type="button" class="btn btn-outline-primary" onclick="exportToCSV()">
                                <i class="fas fa-file-csv mr-2"></i>Export to CSV
                            </button>
                            <button type="button" class="btn btn-outline-success" onclick="printTransactions()">
                                <i class="fas fa-print mr-2"></i>Print Report
                            </button>
                            <?php if ($view_all): ?>
                                <a href="inventory_transactions.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-sync mr-2"></i>Refresh
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<script>
function exportToCSV() {
    const table = document.getElementById('transactionsTable');
    const rows = table.querySelectorAll('tr');
    let csv = [];
    
    for (let i = 0; i < rows.length; i++) {
        let row = [], cols = rows[i].querySelectorAll('td, th');
        
        for (let j = 0; j < cols.length; j++) {
            let data = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, "").replace(/(\s\s)/gm, " ");
            row.push('"' + data + '"');
        }
        
        csv.push(row.join(","));
    }

    const csvString = csv.join("\n");
    const blob = new Blob([csvString], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.setAttribute('hidden', '');
    a.setAttribute('href', url);
    a.setAttribute('download', '<?php echo $view_all ? "all_transactions" : "transactions_" . ($item["item_code"] ?? "item"); ?>_<?php echo date('Y-m-d'); ?>.csv');
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
}

function printTransactions() {
    const printContent = document.querySelector('.card-body').innerHTML;
    const originalContent = document.body.innerHTML;
    
    document.body.innerHTML = `
        <!DOCTYPE html>
        <html>
        <head>
            <title><?php echo $view_all ? 'All Inventory Transactions' : 'Transaction History - ' . htmlspecialchars($item['item_name'] ?? 'Item'); ?></title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                .table { width: 100%; border-collapse: collapse; }
                .table th, .table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                .table th { background-color: #f8f9fa; }
                .text-success { color: #28a745; }
                .text-danger { color: #dc3545; }
                .badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; }
                .badge-success { background-color: #28a745; color: white; }
                .badge-danger { background-color: #dc3545; color: white; }
                .badge-warning { background-color: #ffc107; color: black; }
            </style>
        </head>
        <body>
            <h2><?php echo $view_all ? 'All Inventory Transactions' : 'Transaction History: ' . htmlspecialchars($item['item_name'] ?? 'Item'); ?></h2>
            <p><strong>Generated:</strong> <?php echo date('F j, Y g:i A'); ?></p>
            ${printContent}
        </body>
        </html>
    `;
    
    window.print();
    document.body.innerHTML = originalContent;
    window.location.reload();
}

// Initialize DataTables with enhanced features
$(document).ready(function() {
    if ($.fn.DataTable) {
        $('#transactionsTable').DataTable({
            pageLength: 25,
            order: [[<?php echo $view_all ? 1 : 0; ?>, 'desc']],
            dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>rt<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
            language: {
                search: "Search transactions:",
                lengthMenu: "Show _MENU_ transactions"
            },
            stateSave: true
        });
    }
    
    // Add hover effects
    $('#transactionsTable tbody tr').hover(
        function() {
            $(this).addClass('table-active');
        },
        function() {
            $(this).removeClass('table-active');
        }
    );
});
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Get transfer ID from URL
$transfer_id = intval($_GET['id'] ?? 0);

if (!$transfer_id) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid transfer ID.";
    header("Location: inventory_transfers.php");
    exit;
}

// Get transfer details
$transfer_sql = "SELECT t.*, 
                        u.user_name as requested_by_name,
                        fl.location_name as from_location_name,
                        fl.location_type as from_location_type,
                        tl.location_name as to_location_name,
                        tl.location_type as to_location_type
                 FROM inventory_transfers t
                 LEFT JOIN users u ON t.requested_by = u.user_id
                 LEFT JOIN inventory_locations fl ON t.from_location_id = fl.location_id
                 LEFT JOIN inventory_locations tl ON t.to_location_id = tl.location_id
                 WHERE t.transfer_id = ?";
$transfer_stmt = $mysqli->prepare($transfer_sql);
$transfer_stmt->bind_param("i", $transfer_id);
$transfer_stmt->execute();
$transfer_result = $transfer_stmt->get_result();

if ($transfer_result->num_rows === 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Transfer not found.";
    header("Location: inventory_transfers.php");
    exit;
}

$transfer = $transfer_result->fetch_assoc();
$transfer_stmt->close();

// Get transfer items
$items_sql = "SELECT ti.*, i.item_name, i.item_code, i.item_unit_measure,
                     i.item_quantity as current_stock, i.item_low_stock_alert
              FROM inventory_transfer_items ti
              JOIN inventory_items i ON ti.item_id = i.item_id
              WHERE ti.transfer_id = ?
              ORDER BY i.item_name";
$items_stmt = $mysqli->prepare($items_sql);
$items_stmt->bind_param("i", $transfer_id);
$items_stmt->execute();
$items_result = $items_stmt->get_result();
$transfer_items = [];
$total_items = 0;
$total_quantity = 0;

while ($item = $items_result->fetch_assoc()) {
    $transfer_items[] = $item;
    $total_items++;
    $total_quantity += $item['quantity'];
}
$items_stmt->close();

// Get transactions for this transfer
$transactions_sql = "SELECT t.*, i.item_name, i.item_code,
                            l1.location_name as from_location_name,
                            l2.location_name as to_location_name,
                            u.user_name as performed_by_name
                     FROM inventory_transactions t
                     LEFT JOIN inventory_items i ON t.item_id = i.item_id
                     LEFT JOIN inventory_locations l1 ON t.from_location_id = l1.location_id
                     LEFT JOIN inventory_locations l2 ON t.to_location_id = l2.location_id
                     LEFT JOIN users u ON t.performed_by = u.user_id
                     WHERE t.transfer_id = ?
                     ORDER BY t.transaction_date";
$transactions_stmt = $mysqli->prepare($transactions_sql);
$transactions_stmt->bind_param("i", $transfer_id);
$transactions_stmt->execute();
$transactions_result = $transactions_stmt->get_result();
$transactions = [];

while ($transaction = $transactions_result->fetch_assoc()) {
    $transactions[] = $transaction;
}
$transactions_stmt->close();

// Determine status color and icon
switch($transfer['transfer_status']) {
    case 'pending':
        $status_color = 'warning';
        $status_icon = 'clock';
        $status_badge = 'badge-warning';
        break;
    case 'in_transit':
        $status_color = 'info';
        $status_icon = 'shipping-fast';
        $status_badge = 'badge-info';
        break;
    case 'completed':
        $status_color = 'success';
        $status_icon = 'check-circle';
        $status_badge = 'badge-success';
        break;
    case 'cancelled':
        $status_color = 'danger';
        $status_icon = 'times-circle';
        $status_badge = 'badge-danger';
        break;
    default:
        $status_color = 'secondary';
        $status_icon = 'question-circle';
        $status_badge = 'badge-secondary';
}

// Format status for display
$display_status = ucwords(str_replace('_', ' ', $transfer['transfer_status']));
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0 text-white">
                <i class="fas fa-fw fa-truck-moving mr-2"></i>
                Transfer Details: <?php echo htmlspecialchars($transfer['transfer_number']); ?>
            </h3>
            <div class="card-tools">
                <a href="inventory_transfers.php" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Transfers
                </a>
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

        <div class="row">
            <div class="col-md-8">
                <!-- Transfer Information -->
                <div class="card card-primary">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-info-circle mr-2"></i>Transfer Information</h3>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <dl class="row mb-0">
                                    <dt class="col-sm-4">Transfer #</dt>
                                    <dd class="col-sm-8">
                                        <strong><?php echo htmlspecialchars($transfer['transfer_number']); ?></strong>
                                    </dd>
                                    
                                    <dt class="col-sm-4">Status</dt>
                                    <dd class="col-sm-8">
                                        <span class="badge <?php echo $status_badge; ?> p-2">
                                            <i class="fas fa-<?php echo $status_icon; ?> mr-1"></i>
                                            <?php echo $display_status; ?>
                                        </span>
                                    </dd>
                                    
                                    <dt class="col-sm-4">Date</dt>
                                    <dd class="col-sm-8">
                                        <?php if ($transfer['transfer_date']): ?>
                                            <?php echo date('M j, Y H:i', strtotime($transfer['transfer_date'])); ?>
                                        <?php else: ?>
                                            <span class="text-muted">Not set</span>
                                        <?php endif; ?>
                                    </dd>
                                    
                                    <dt class="col-sm-4">Requested By</dt>
                                    <dd class="col-sm-8">
                                        <?php echo htmlspecialchars($transfer['requested_by_name']); ?>
                                    </dd>
                                </dl>
                            </div>
                            <div class="col-md-6">
                                <dl class="row mb-0">
                                    <dt class="col-sm-4">From Location</dt>
                                    <dd class="col-sm-8">
                                        <i class="fas fa-map-marker-alt text-danger mr-1"></i>
                                        <?php echo htmlspecialchars($transfer['from_location_type'] . ' - ' . $transfer['from_location_name']); ?>
                                    </dd>
                                    
                                    <dt class="col-sm-4">To Location</dt>
                                    <dd class="col-sm-8">
                                        <i class="fas fa-map-marker-alt text-success mr-1"></i>
                                        <?php echo htmlspecialchars($transfer['to_location_type'] . ' - ' . $transfer['to_location_name']); ?>
                                    </dd>
                                    
                                    <dt class="col-sm-4">Total Items</dt>
                                    <dd class="col-sm-8">
                                        <span class="font-weight-bold"><?php echo $total_items; ?></span> items
                                    </dd>
                                    
                                    <dt class="col-sm-4">Total Quantity</dt>
                                    <dd class="col-sm-8">
                                        <span class="font-weight-bold"><?php echo number_format($total_quantity); ?></span> units
                                    </dd>
                                </dl>
                            </div>
                        </div>
                        
                        <?php if ($transfer['notes']): ?>
                            <div class="row mt-3">
                                <div class="col-12">
                                    <dt>Notes</dt>
                                    <dd class="border rounded p-3 bg-light">
                                        <?php echo nl2br(htmlspecialchars($transfer['notes'])); ?>
                                    </dd>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Transfer Items -->
                <div class="card card-success mt-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-boxes mr-2"></i>Transfer Items</h3>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Item</th>
                                        <th class="text-center">Quantity</th>
                                        <th class="text-center">Sent</th>
                                        <th class="text-center">Received</th>
                                        <th>Status</th>
                                        <th>Notes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($transfer_items as $item): ?>
                                    <tr>
                                        <td>
                                            <div class="font-weight-bold"><?php echo htmlspecialchars($item['item_name']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($item['item_code']); ?></small>
                                            <div class="small">
                                                <i class="fas fa-box text-muted mr-1"></i>
                                                Current Stock: <?php echo $item['current_stock']; ?>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <div class="font-weight-bold"><?php echo $item['quantity']; ?></div>
                                            <small class="text-muted"><?php echo $item['item_unit_measure']; ?></small>
                                        </td>
                                        <td class="text-center">
                                            <div class="font-weight-bold <?php echo $item['quantity_sent'] == $item['quantity'] ? 'text-success' : 'text-warning'; ?>">
                                                <?php echo $item['quantity_sent']; ?>
                                            </div>
                                            <small class="text-muted">sent</small>
                                        </td>
                                        <td class="text-center">
                                            <div class="font-weight-bold <?php echo $item['quantity_received'] == $item['quantity'] ? 'text-success' : 'text-warning'; ?>">
                                                <?php echo $item['quantity_received']; ?>
                                            </div>
                                            <small class="text-muted">received</small>
                                        </td>
                                        <td>
                                            <?php if ($item['quantity_received'] == $item['quantity']): ?>
                                                <span class="badge badge-success">Fully Received</span>
                                            <?php elseif ($item['quantity_sent'] == $item['quantity']): ?>
                                                <span class="badge badge-info">In Transit</span>
                                            <?php elseif ($item['quantity_sent'] > 0): ?>
                                                <span class="badge badge-warning">Partially Sent</span>
                                            <?php else: ?>
                                                <span class="badge badge-secondary">Pending</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($item['notes']): ?>
                                                <small class="text-muted" data-toggle="tooltip" title="<?php echo htmlspecialchars($item['notes']); ?>">
                                                    <i class="fas fa-sticky-note mr-1"></i>
                                                    <?php echo truncate($item['notes'], 30); ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    
                                    <?php if (empty($transfer_items)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-3">
                                            <i class="fas fa-box fa-2x text-muted mb-2"></i>
                                            <p class="text-muted mb-0">No items found for this transfer.</p>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Transactions -->
                <?php if (!empty($transactions)): ?>
                <div class="card card-info mt-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-exchange-alt mr-2"></i>Related Transactions</h3>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Date & Time</th>
                                        <th>Item</th>
                                        <th class="text-center">Type</th>
                                        <th class="text-center">Quantity</th>
                                        <th>Performed By</th>
                                        <th>Reference</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($transactions as $transaction): ?>
                                    <tr>
                                        <td>
                                            <?php if ($transaction['transaction_date']): ?>
                                                <div class="small"><?php echo date('M j, Y', strtotime($transaction['transaction_date'])); ?></div>
                                                <div class="text-muted"><?php echo date('H:i', strtotime($transaction['transaction_date'])); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="font-weight-bold"><?php echo htmlspecialchars($transaction['item_name']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($transaction['item_code']); ?></small>
                                        </td>
                                        <td class="text-center">
                                            <?php 
                                            $type_color = 'secondary';
                                            $type_icon = 'exchange-alt';
                                            
                                            switch($transaction['transaction_type']) {
                                                case 'transfer_out':
                                                    $type_color = 'danger';
                                                    $type_icon = 'arrow-up';
                                                    break;
                                                case 'transfer_in':
                                                    $type_color = 'success';
                                                    $type_icon = 'arrow-down';
                                                    break;
                                            }
                                            ?>
                                            <span class="badge badge-<?php echo $type_color; ?>">
                                                <i class="fas fa-<?php echo $type_icon; ?> mr-1"></i>
                                                <?php echo ucwords(str_replace('_', ' ', $transaction['transaction_type'])); ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <div class="font-weight-bold text-<?php echo $transaction['transaction_type'] == 'transfer_out' ? 'danger' : 'success'; ?>">
                                                <?php echo $transaction['transaction_type'] == 'transfer_out' ? '-' : '+'; ?><?php echo abs($transaction['quantity_change']); ?>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($transaction['performed_by_name']); ?></td>
                                        <td>
                                            <small class="text-muted"><?php echo htmlspecialchars($transaction['transaction_reference']); ?></small>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="col-md-4">
                <!-- Quick Actions -->
                <div class="card card-success">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-bolt mr-2"></i>Actions</h3>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="inventory_transfer_items.php?transfer_id=<?php echo $transfer_id; ?>" class="btn btn-info">
                                <i class="fas fa-boxes mr-2"></i>Manage Items
                            </a>
                            
                            <?php if ($transfer['transfer_status'] == 'pending'): ?>
                                <a href="inventory_transfer_process.php?id=<?php echo $transfer_id; ?>&action=start" class="btn btn-warning">
                                    <i class="fas fa-play mr-2"></i>Mark In Transit
                                </a>
                            <?php elseif ($transfer['transfer_status'] == 'in_transit'): ?>
                                <a href="inventory_transfer_process.php?id=<?php echo $transfer_id; ?>&action=complete" class="btn btn-success">
                                    <i class="fas fa-check mr-2"></i>Mark Completed
                                </a>
                                <a href="inventory_transfer_complete.php?id=<?php echo $transfer_id; ?>" class="btn btn-primary">
                                    <i class="fas fa-exchange-alt mr-2"></i>Create Transactions
                                </a>
                            <?php endif; ?>
                            
                            <?php if (in_array($transfer['transfer_status'], ['pending', 'in_transit'])): ?>
                                <a href="inventory_transfer_process.php?id=<?php echo $transfer_id; ?>&action=cancel" 
                                   class="btn btn-danger" onclick="return confirmCancel()">
                                    <i class="fas fa-times mr-2"></i>Cancel Transfer
                                </a>
                            <?php endif; ?>
                            
                            <a href="inventory_transfer_print.php?id=<?php echo $transfer_id; ?>" class="btn btn-secondary" target="_blank">
                                <i class="fas fa-print mr-2"></i>Print Transfer
                            </a>
                            
                            <a href="inventory_transfers.php" class="btn btn-outline-dark">
                                <i class="fas fa-arrow-left mr-2"></i>Back to Transfers
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Transfer Summary -->
                <div class="card card-info mt-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-calculator mr-2"></i>Transfer Summary</h3>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-secondary">
                            <div class="d-flex justify-content-between mb-2">
                                <span>Items Count:</span>
                                <span class="font-weight-bold"><?php echo $total_items; ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Total Quantity:</span>
                                <span class="font-weight-bold"><?php echo number_format($total_quantity); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Transactions:</span>
                                <span class="font-weight-bold"><?php echo count($transactions); ?></span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span>Created:</span>
                                <span class="font-weight-bold"><?php echo date('M j, Y', strtotime($transfer['transfer_date'])); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Location Details -->
                <div class="card card-warning mt-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-map-marker-alt mr-2"></i>Location Details</h3>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-danger">
                            <h6><i class="fas fa-arrow-up mr-2"></i>Source Location</h6>
                            <div class="small">
                                <strong><?php echo htmlspecialchars($transfer['from_location_type']); ?></strong><br>
                                <?php echo htmlspecialchars($transfer['from_location_name']); ?>
                            </div>
                        </div>
                        <div class="alert alert-success">
                            <h6><i class="fas fa-arrow-down mr-2"></i>Destination Location</h6>
                            <div class="small">
                                <strong><?php echo htmlspecialchars($transfer['to_location_type']); ?></strong><br>
                                <?php echo htmlspecialchars($transfer['to_location_name']); ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Stats -->
                <div class="card card-secondary mt-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-chart-bar mr-2"></i>Quick Stats</h3>
                    </div>
                    <div class="card-body">
                        <?php
                        // Calculate item status
                        $fully_received = 0;
                        $in_transit = 0;
                        $partially_sent = 0;
                        $pending = 0;
                        
                        foreach ($transfer_items as $item) {
                            if ($item['quantity_received'] == $item['quantity']) {
                                $fully_received++;
                            } elseif ($item['quantity_sent'] == $item['quantity']) {
                                $in_transit++;
                            } elseif ($item['quantity_sent'] > 0) {
                                $partially_sent++;
                            } else {
                                $pending++;
                            }
                        }
                        ?>
                        <div class="small">
                            <div class="d-flex justify-content-between mb-1">
                                <span>Fully Received:</span>
                                <span class="font-weight-bold text-success"><?php echo $fully_received; ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-1">
                                <span>In Transit:</span>
                                <span class="font-weight-bold text-info"><?php echo $in_transit; ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-1">
                                <span>Partially Sent:</span>
                                <span class="font-weight-bold text-warning"><?php echo $partially_sent; ?></span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span>Pending:</span>
                                <span class="font-weight-bold text-secondary"><?php echo $pending; ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function confirmCancel() {
    return confirm('Are you sure you want to cancel this transfer?\n\nThis action cannot be undone.');
}

function printTransfer() {
    window.open('inventory_transfer_print.php?id=<?php echo $transfer_id; ?>', '_blank');
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + P to print
    if (e.ctrlKey && e.keyCode === 80) {
        e.preventDefault();
        printTransfer();
    }
    // Escape to go back
    if (e.keyCode === 27) {
        window.location.href = 'inventory_transfers.php';
    }
});
</script>

<style>
.badge {
    font-size: 0.85em;
    padding: 5px 10px;
}

.card-header.bg-primary {
    background-color: #007bff !important;
}

.alert-danger {
    border-left: 4px solid #dc3545;
}

.alert-success {
    border-left: 4px solid #28a745;
}

.table th {
    border-top: none;
}

.dl-horizontal dt {
    text-align: left;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
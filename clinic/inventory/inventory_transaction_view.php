<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Get transaction ID from URL
$transaction_id = intval($_GET['id'] ?? 0);

if ($transaction_id <= 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid transaction ID.";
    header("Location: inventory_transactions.php");
    exit;
}

// Get transaction details with enhanced location data
$transaction_sql = "SELECT t.*, 
                           i.item_id, i.item_name, i.item_code, i.item_description,
                           i.item_unit_measure, i.item_unit_cost, i.item_unit_price,
                           i.item_low_stock_alert, i.location_id as current_location_id,
                           c.category_name, c.category_type,
                           s.supplier_name,
                           u.user_name as performed_by_name,
                           v.visit_id, p.patient_first_name, p.patient_last_name, p.patient_mrn,
                           fl.location_name as from_location_name, fl.location_type as from_location_type,
                           tl.location_name as to_location_name, tl.location_type as to_location_type,
                           cl.location_name as current_location_name, cl.location_type as current_location_type
                    FROM inventory_transactions t
                    JOIN inventory_items i ON t.item_id = i.item_id
                    LEFT JOIN inventory_categories c ON i.item_category_id = c.category_id
                    LEFT JOIN suppliers s ON i.item_supplier_id = s.supplier_id
                    LEFT JOIN users u ON t.performed_by = u.user_id
                    LEFT JOIN visits v ON t.related_visit_id = v.visit_id
                    LEFT JOIN patients p ON v.visit_patient_id = p.patient_id
                    LEFT JOIN inventory_locations fl ON t.from_location_id = fl.location_id
                    LEFT JOIN inventory_locations tl ON t.to_location_id = tl.location_id
                    LEFT JOIN inventory_locations cl ON i.location_id = cl.location_id
                    WHERE t.transaction_id = ?";
$transaction_stmt = $mysqli->prepare($transaction_sql);
$transaction_stmt->bind_param("i", $transaction_id);
$transaction_stmt->execute();
$transaction_result = $transaction_stmt->get_result();

if ($transaction_result->num_rows === 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Transaction not found.";
    header("Location: inventory_transactions.php");
    exit;
}

$transaction = $transaction_result->fetch_assoc();
$transaction_stmt->close();

// Check if this transaction has been reversed
$reversal_check_sql = "SELECT transaction_id, transaction_reference 
                      FROM inventory_transactions 
                      WHERE original_transaction_id = ?";
$reversal_stmt = $mysqli->prepare($reversal_check_sql);
$reversal_stmt->bind_param("i", $transaction_id);
$reversal_stmt->execute();
$reversal_result = $reversal_stmt->get_result();
$reversal_transaction = $reversal_result->fetch_assoc();
$has_reversal = $reversal_result->num_rows > 0;
$reversal_stmt->close();

// Get current location stock information
$location_stock_sql = "SELECT ili.quantity, l.location_name, l.location_type
                      FROM inventory_location_items ili
                      JOIN inventory_locations l ON ili.location_id = l.location_id
                      WHERE ili.item_id = ? AND ili.quantity > 0
                      ORDER BY ili.quantity DESC";
$location_stmt = $mysqli->prepare($location_stock_sql);
$location_stmt->bind_param("i", $transaction['item_id']);
$location_stmt->execute();
$location_result = $location_stmt->get_result();
$location_stock = [];
while ($location = $location_result->fetch_assoc()) {
    $location_stock[] = $location;
}
$location_stmt->close();

// Determine transaction type color and icon
$type_color = 'secondary';
$type_icon = 'exchange-alt';
$type_text = 'Transaction';
$change_sign = '';

switch($transaction['transaction_type']) {
    case 'in':
        $type_color = 'success';
        $type_icon = 'arrow-down';
        $type_text = 'Stock In';
        $change_sign = '+';
        break;
    case 'out':
        $type_color = 'danger';
        $type_icon = 'arrow-up';
        $type_text = 'Stock Out';
        $change_sign = '-';
        break;
    case 'adjustment':
        $type_color = 'warning';
        $type_icon = 'adjust';
        $type_text = 'Adjustment';
        $change_sign = $transaction['quantity_change'] > 0 ? '+' : '';
        break;
    case 'return':
        $type_color = 'info';
        $type_icon = 'undo';
        $type_text = 'Return';
        $change_sign = '+';
        break;
    case 'transfer':
        $type_color = 'primary';
        $type_icon = 'sync-alt';
        $type_text = 'Location Transfer';
        $change_sign = '↔';
        break;
}

// Calculate value change if applicable
$value_change = 0;
if ($transaction['item_unit_cost'] > 0) {
    $value_change = abs($transaction['quantity_change']) * $transaction['item_unit_cost'];
}

// Calculate stock status
$current_status = 'In Stock';
$status_class = 'success';
if ($transaction['new_quantity'] <= 0) {
    $current_status = 'Out of Stock';
    $status_class = 'danger';
} elseif ($transaction['new_quantity'] <= $transaction['item_low_stock_alert']) {
    $current_status = 'Low Stock';
    $status_class = 'warning';
}
?>

<div class="card">
    <div class="card-header bg-info py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0 text-white">
                <i class="fas fa-fw fa-eye mr-2"></i>Transaction Details
            </h3>
            <div class="card-tools">
                <a href="inventory_transactions.php" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Transactions
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

        <?php if ($has_reversal): ?>
            <div class="alert alert-warning">
                <i class="fas fa-undo mr-2"></i>
                <strong>This transaction has been reversed.</strong> 
                <a href="inventory_transaction_view.php?id=<?php echo $reversal_transaction['transaction_id']; ?>" class="alert-link">
                    View reversal transaction #<?php echo $reversal_transaction['transaction_id']; ?>
                </a>
            </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-md-8">
                <!-- Transaction Overview -->
                <div class="card card-primary">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h3 class="card-title"><i class="fas fa-info-circle mr-2"></i>Transaction Overview</h3>
                        <?php if ($has_reversal): ?>
                            <span class="badge badge-warning">
                                <i class="fas fa-undo mr-1"></i>Reversed
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <div class="row text-center mb-4">
                            <div class="col-md-4">
                                <div class="border rounded p-3 bg-light">
                                    <div class="h5 text-muted">Previous Stock</div>
                                    <div class="h2 font-weight-bold text-primary"><?php echo $transaction['previous_quantity']; ?></div>
                                    <div class="small text-muted">Before Transaction</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="border rounded p-3 bg-light">
                                    <div class="h5 text-muted">Transaction</div>
                                    <div class="h2 font-weight-bold text-<?php echo $type_color; ?>">
                                        <?php echo $change_sign . abs($transaction['quantity_change']); ?>
                                    </div>
                                    <div class="small text-muted"><?php echo $type_text; ?></div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="border rounded p-3 bg-light">
                                    <div class="h5 text-muted">New Stock</div>
                                    <div class="h2 font-weight-bold text-success"><?php echo $transaction['new_quantity']; ?></div>
                                    <div class="small text-muted">After Transaction</div>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td class="text-muted" width="40%">Transaction ID:</td>
                                        <td><strong>#<?php echo $transaction_id; ?></strong></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Transaction Type:</td>
                                        <td>
                                            <span class="badge badge-<?php echo $type_color; ?>">
                                                <i class="fas fa-<?php echo $type_icon; ?> mr-1"></i>
                                                <?php echo $type_text; ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Date & Time:</td>
                                        <td>
                                            <strong><?php echo date('M j, Y', strtotime($transaction['transaction_date'])); ?></strong>
                                            at <?php echo date('H:i:s', strtotime($transaction['transaction_date'])); ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Reference:</td>
                                        <td><strong><?php echo htmlspecialchars($transaction['transaction_reference']); ?></strong></td>
                                    </tr>
                                    <?php if ($transaction['from_location_id']): ?>
                                    <tr>
                                        <td class="text-muted">From Location:</td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($transaction['from_location_name']); ?></strong>
                                            <br><small class="text-muted"><?php echo htmlspecialchars($transaction['from_location_type']); ?></small>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td class="text-muted" width="40%">Performed By:</td>
                                        <td><strong><?php echo htmlspecialchars($transaction['performed_by_name']); ?></strong></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Quantity Change:</td>
                                        <td>
                                            <strong class="text-<?php echo $type_color; ?>">
                                                <?php echo $change_sign . abs($transaction['quantity_change']); ?>
                                            </strong>
                                            <span class="text-muted"><?php echo htmlspecialchars($transaction['item_unit_measure']); ?></span>
                                        </td>
                                    </tr>
                                    <?php if ($value_change > 0): ?>
                                        <tr>
                                            <td class="text-muted">Value Change:</td>
                                            <td><strong class="text-success">$<?php echo number_format($value_change, 2); ?></strong></td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php if ($transaction['to_location_id']): ?>
                                    <tr>
                                        <td class="text-muted">To Location:</td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($transaction['to_location_name']); ?></strong>
                                            <br><small class="text-muted"><?php echo htmlspecialchars($transaction['to_location_type']); ?></small>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                    <?php if ($transaction['related_visit_id']): ?>
                                        <tr>
                                            <td class="text-muted">Related Visit:</td>
                                            <td>
                                                <strong>Visit #<?php echo $transaction['visit_id']; ?></strong>
                                                <?php if ($transaction['patient_first_name'] || $transaction['patient_last_name']): ?>
                                                    <br><small class="text-muted">Patient: <?php echo htmlspecialchars($transaction['patient_first_name'] . ' ' . $transaction['patient_last_name']); ?> (MRN: <?php echo htmlspecialchars($transaction['patient_mrn']); ?>)</small>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </table>
                            </div>
                        </div>

                        <?php if ($transaction['transaction_notes']): ?>
                            <div class="mt-3">
                                <strong>Transaction Notes:</strong>
                                <div class="border rounded p-3 bg-light mt-2">
                                    <?php echo nl2br(htmlspecialchars($transaction['transaction_notes'])); ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Item Information -->
                <div class="card card-info">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-cube mr-2"></i>Item Information</h3>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td class="text-muted" width="40%">Item Name:</td>
                                        <td><strong><?php echo htmlspecialchars($transaction['item_name']); ?></strong></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Item Code:</td>
                                        <td><strong><?php echo htmlspecialchars($transaction['item_code']); ?></strong></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Category:</td>
                                        <td>
                                            <?php if ($transaction['category_name']): ?>
                                                <span class="badge badge-light"><?php echo htmlspecialchars($transaction['category_type']); ?></span>
                                                <?php echo htmlspecialchars($transaction['category_name']); ?>
                                            <?php else: ?>
                                                <span class="text-muted">Not assigned</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Current Location:</td>
                                        <td>
                                            <?php if ($transaction['current_location_name']): ?>
                                                <strong><?php echo htmlspecialchars($transaction['current_location_name']); ?></strong>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($transaction['current_location_type']); ?></small>
                                            <?php else: ?>
                                                <span class="text-muted">Not assigned</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td class="text-muted" width="40%">Current Stock:</td>
                                        <td>
                                            <strong><?php echo $transaction['new_quantity']; ?></strong>
                                            <span class="text-muted"><?php echo htmlspecialchars($transaction['item_unit_measure']); ?></span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Low Stock Alert:</td>
                                        <td><strong><?php echo $transaction['item_low_stock_alert']; ?></strong></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Unit Cost:</td>
                                        <td><strong>$<?php echo number_format($transaction['item_unit_cost'], 2); ?></strong></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Supplier:</td>
                                        <td>
                                            <?php if ($transaction['supplier_name']): ?>
                                                <?php echo htmlspecialchars($transaction['supplier_name']); ?>
                                            <?php else: ?>
                                                <span class="text-muted">Not assigned</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <?php if ($transaction['item_description']): ?>
                            <div class="mt-3">
                                <strong>Item Description:</strong>
                                <div class="border rounded p-3 bg-light mt-2">
                                    <?php echo nl2br(htmlspecialchars($transaction['item_description'])); ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Location Stock Distribution -->
                <?php if (!empty($location_stock)): ?>
                <div class="card card-warning">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-map-marker-alt mr-2"></i>Current Stock Distribution</h3>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-striped">
                                <thead>
                                    <tr>
                                        <th>Location</th>
                                        <th>Type</th>
                                        <th class="text-center">Current Stock</th>
                                        <th class="text-center">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($location_stock as $location): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($location['location_name']); ?></strong>
                                            </td>
                                            <td>
                                                <span class="badge badge-light"><?php echo htmlspecialchars($location['location_type']); ?></span>
                                            </td>
                                            <td class="text-center">
                                                <span class="font-weight-bold"><?php echo $location['quantity']; ?></span>
                                            </td>
                                            <td class="text-center">
                                                <?php
                                                $loc_status = 'In Stock';
                                                $loc_status_class = 'success';
                                                if ($location['quantity'] <= 0) {
                                                    $loc_status = 'Out of Stock';
                                                    $loc_status_class = 'danger';
                                                } elseif ($location['quantity'] <= $transaction['item_low_stock_alert']) {
                                                    $loc_status = 'Low Stock';
                                                    $loc_status_class = 'warning';
                                                }
                                                ?>
                                                <span class="badge badge-<?php echo $loc_status_class; ?>"><?php echo $loc_status; ?></span>
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
                        <h3 class="card-title"><i class="fas fa-bolt mr-2"></i>Quick Actions</h3>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="inventory_item_details.php?item_id=<?php echo $transaction['item_id']; ?>" class="btn btn-info">
                                <i class="fas fa-cube mr-2"></i>View Item Details
                            </a>
                            <a href="inventory_transactions.php?item=<?php echo $transaction['item_id']; ?>" class="btn btn-outline-primary">
                                <i class="fas fa-history mr-2"></i>View Item History
                            </a>
                            <button type="button" class="btn btn-outline-warning" onclick="printTransaction()">
                                <i class="fas fa-print mr-2"></i>Print Details
                            </button>
                            <?php if (!$has_reversal && $transaction['transaction_type'] !== 'adjustment'): ?>
                                <button type="button" class="btn btn-outline-danger" onclick="reverseTransaction(<?php echo $transaction_id; ?>)">
                                    <i class="fas fa-undo mr-2"></i>Reverse Transaction
                                </button>
                            <?php elseif ($has_reversal): ?>
                                <a href="inventory_transaction_view.php?id=<?php echo $reversal_transaction['transaction_id']; ?>" class="btn btn-outline-warning">
                                    <i class="fas fa-eye mr-2"></i>View Reversal
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Transaction Impact -->
                <div class="card card-warning">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-chart-line mr-2"></i>Transaction Impact</h3>
                    </div>
                    <div class="card-body">
                        <div class="text-center mb-3">
                            <i class="fas fa-<?php echo $type_icon; ?> fa-3x text-<?php echo $type_color; ?> mb-2"></i>
                            <h5><?php echo $type_text; ?> Impact</h5>
                        </div>
                        <div class="small">
                            <div class="d-flex justify-content-between mb-2">
                                <span>Stock Change:</span>
                                <span class="font-weight-bold text-<?php echo $type_color; ?>">
                                    <?php echo $change_sign . abs($transaction['quantity_change']); ?>
                                </span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Change Percentage:</span>
                                <span class="font-weight-bold">
                                    <?php
                                    $change_percentage = 0;
                                    if ($transaction['previous_quantity'] > 0) {
                                        $change_percentage = (abs($transaction['quantity_change']) / $transaction['previous_quantity']) * 100;
                                    }
                                    echo number_format($change_percentage, 1) . '%';
                                    ?>
                                </span>
                            </div>
                            <?php if ($value_change > 0): ?>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Value Impact:</span>
                                    <span class="font-weight-bold text-success">$<?php echo number_format($value_change, 2); ?></span>
                                </div>
                            <?php endif; ?>
                            <div class="d-flex justify-content-between">
                                <span>Current Status:</span>
                                <span class="font-weight-bold">
                                    <span class="badge badge-<?php echo $status_class; ?>"><?php echo $current_status; ?></span>
                                </span>
                            </div>
                        </div>
                        
                        <?php if ($transaction['transaction_type'] === 'transfer'): ?>
                            <hr>
                            <div class="text-center">
                                <i class="fas fa-sync-alt text-primary mb-2"></i>
                                <div class="small text-muted">Location Transfer</div>
                                <div class="font-weight-bold">
                                    <?php echo htmlspecialchars($transaction['from_location_name']); ?>
                                    <i class="fas fa-arrow-right mx-2 text-muted"></i>
                                    <?php echo htmlspecialchars($transaction['to_location_name']); ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Stock Status -->
                <div class="card card-<?php echo $status_class; ?>">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-chart-bar mr-2"></i>Stock Status</h3>
                    </div>
                    <div class="card-body">
                        <div class="text-center mb-3">
                            <div class="h2 font-weight-bold text-<?php echo $status_class; ?>">
                                <?php echo $transaction['new_quantity']; ?>
                            </div>
                            <div class="text-muted">Current Stock Level</div>
                        </div>
                        <div class="progress mb-2" style="height: 20px;">
                            <?php
                            $max_stock = max($transaction['new_quantity'], $transaction['previous_quantity'], $transaction['item_low_stock_alert'] * 2);
                            $progress_percentage = min(100, ($transaction['new_quantity'] / $max_stock) * 100);
                            $progress_class = $status_class === 'success' ? 'bg-success' : ($status_class === 'warning' ? 'bg-warning' : 'bg-danger');
                            ?>
                            <div class="progress-bar <?php echo $progress_class; ?>" 
                                 role="progressbar" 
                                 style="width: <?php echo $progress_percentage; ?>%"
                                 aria-valuenow="<?php echo $transaction['new_quantity']; ?>" 
                                 aria-valuemin="0" 
                                 aria-valuemax="<?php echo $max_stock; ?>">
                            </div>
                        </div>
                        <div class="small text-center">
                            Low Stock Alert: <strong><?php echo $transaction['item_low_stock_alert']; ?></strong>
                        </div>
                    </div>
                </div>

                <!-- Related Transactions -->
                <div class="card card-secondary">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-link mr-2"></i>Quick Links</h3>
                    </div>
                    <div class="card-body">
                        <div class="list-group list-group-flush">
                            <a href="inventory_transactions.php?type=<?php echo $transaction['transaction_type']; ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                                Similar Transactions
                                <span class="badge badge-primary badge-pill">
                                    <i class="fas fa-arrow-right"></i>
                                </span>
                            </a>
                            <a href="inventory_transactions.php?item=<?php echo $transaction['item_id']; ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                                Item Transaction History
                                <span class="badge badge-primary badge-pill">
                                    <i class="fas fa-arrow-right"></i>
                                </span>
                            </a>
                            <?php if ($transaction['related_visit_id']): ?>
                                <a href="visit_details.php?id=<?php echo $transaction['related_visit_id']; ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                                    Related Visit Details
                                    <span class="badge badge-primary badge-pill">
                                        <i class="fas fa-arrow-right"></i>
                                    </span>
                                </a>
                            <?php endif; ?>
                            <?php if ($transaction['from_location_id']): ?>
                                <a href="inventory_transactions.php?location=<?php echo $transaction['from_location_id']; ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                                    From Location History
                                    <span class="badge badge-primary badge-pill">
                                        <i class="fas fa-arrow-right"></i>
                                    </span>
                                </a>
                            <?php endif; ?>
                            <?php if ($transaction['to_location_id']): ?>
                                <a href="inventory_transactions.php?location=<?php echo $transaction['to_location_id']; ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                                    To Location History
                                    <span class="badge badge-primary badge-pill">
                                        <i class="fas fa-arrow-right"></i>
                                    </span>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Audit Trail -->
                <div class="card card-dark">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-shield-alt mr-2"></i>Audit Information</h3>
                    </div>
                    <div class="card-body">
                        <div class="small">
                            <div class="d-flex justify-content-between mb-2">
                                <span>Transaction ID:</span>
                                <span class="font-weight-bold">#<?php echo $transaction_id; ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Recorded By:</span>
                                <span class="font-weight-bold"><?php echo htmlspecialchars($transaction['performed_by_name']); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Timestamp:</span>
                                <span class="font-weight-bold"><?php echo date('Y-m-d H:i:s', strtotime($transaction['transaction_date'])); ?></span>
                            </div>
                            <?php if ($has_reversal): ?>
                                <div class="d-flex justify-content-between">
                                    <span>Reversal Status:</span>
                                    <span class="font-weight-bold text-warning">Reversed</span>
                                </div>
                            <?php else: ?>
                                <div class="d-flex justify-content-between">
                                    <span>Reversal Status:</span>
                                    <span class="font-weight-bold text-success">Active</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function printTransaction() {
    window.open('inventory_transaction_print.php?id=<?php echo $transaction_id; ?>', '_blank');
}

function reverseTransaction(transactionId) {
    if (confirm('Are you sure you want to reverse this transaction? This will create an opposite transaction to cancel out this one.')) {
        window.location.href = 'inventory_transaction_reverse.php?id=' + transactionId;
    }
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Escape to go back
    if (e.keyCode === 27) {
        window.location.href = 'inventory_transactions.php';
    }
    // Ctrl+P to print
    if (e.ctrlKey && e.keyCode === 80) {
        e.preventDefault();
        printTransaction();
    }
    // Ctrl+R to reverse (if available)
    if (e.ctrlKey && e.keyCode === 82 && <?php echo !$has_reversal && $transaction['transaction_type'] !== 'adjustment' ? 'true' : 'false'; ?>) {
        e.preventDefault();
        reverseTransaction(<?php echo $transaction_id; ?>);
    }
});
</script>

<style>
.progress {
    background-color: #e9ecef;
    border-radius: 0.25rem;
}

.progress-bar {
    transition: width 0.6s ease;
}

.list-group-item {
    border: none;
    padding: 0.75rem 0;
}

.list-group-item:hover {
    background-color: #f8f9fa;
}
</style>

<?php 
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

$batch_id = intval($_GET['id'] ?? 0);

if ($batch_id <= 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid batch ID";
    header("Location: inventory_items.php");
    exit;
}

// Get batch details with item information
$batch_sql = "SELECT 
                ib.batch_id,
                ib.batch_number,
                ib.expiry_date,
                ib.manufacturer,
                ib.supplier_id,
                ib.received_date,
                ib.notes,
                ib.created_at,
                ib.updated_at,
                
                i.item_id,
                i.item_name,
                i.item_code,
                i.unit_of_measure,
                i.is_drug,
                i.requires_batch,
                c.category_name,
                
                s.supplier_name,
                s.supplier_contact,
                s.supplier_phone,
                s.supplier_email,
                s.supplier_address,
                
                uc.user_name as created_by_name,
                uu.user_name as updated_by_name
            FROM inventory_batches ib
            INNER JOIN inventory_items i ON ib.item_id = i.item_id
            LEFT JOIN inventory_categories c ON i.category_id = c.category_id
            LEFT JOIN suppliers s ON ib.supplier_id = s.supplier_id
            LEFT JOIN users uc ON ib.created_by = uc.user_id
            LEFT JOIN users uu ON ib.updated_by = uu.user_id
            WHERE ib.batch_id = ? AND ib.is_active = 1 AND i.is_active = 1";
            
$batch_stmt = $mysqli->prepare($batch_sql);
$batch_stmt->bind_param("i", $batch_id);
$batch_stmt->execute();
$batch_result = $batch_stmt->get_result();
$batch = $batch_result->fetch_assoc();
$batch_stmt->close();

if (!$batch) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Batch not found or has been deleted";
    header("Location: inventory_items.php");
    exit;
}

// Get stock locations and quantities
$stock_sql = "SELECT 
                ils.stock_id,
                ils.quantity,
                ils.unit_cost,
                ils.selling_price,
                ils.last_movement_at,
                ils.created_at as stock_created_at,
                
                il.location_id,
                il.location_name,
                il.location_type,
                
                u.user_name as added_by_name
            FROM inventory_location_stock ils
            INNER JOIN inventory_locations il ON ils.location_id = il.location_id
            LEFT JOIN users u ON ils.created_by = u.user_id
            WHERE ils.batch_id = ? AND ils.is_active = 1
            ORDER BY il.location_name";
            
$stock_stmt = $mysqli->prepare($stock_sql);
$stock_stmt->bind_param("i", $batch_id);
$stock_stmt->execute();
$stock_result = $stock_stmt->get_result();
$stock_locations = [];
$total_quantity = 0;
$total_value = 0;
while ($stock = $stock_result->fetch_assoc()) {
    $stock['value'] = $stock['quantity'] * $stock['unit_cost'];
    $total_quantity += $stock['quantity'];
    $total_value += $stock['value'];
    $stock_locations[] = $stock;
}
$stock_stmt->close();

// Get recent transactions for this batch
$transactions_sql = "SELECT 
                        it.transaction_id,
                        it.transaction_type,
                        it.quantity,
                        it.unit_cost,
                        it.reference_type,
                        it.reference_id,
                        it.reason,
                        it.created_at,
                        
                        fl.location_name as from_location_name,
                        tl.location_name as to_location_name,
                        
                        u.user_name as performed_by_name
                    FROM inventory_transactions it
                    LEFT JOIN inventory_locations fl ON it.from_location_id = fl.location_id
                    LEFT JOIN inventory_locations tl ON it.to_location_id = tl.location_id
                    LEFT JOIN users u ON it.created_by = u.user_id
                    WHERE it.batch_id = ?
                    ORDER BY it.created_at DESC
                    LIMIT 50";
                    
$transactions_stmt = $mysqli->prepare($transactions_sql);
$transactions_stmt->bind_param("i", $batch_id);
$transactions_stmt->execute();
$transactions_result = $transactions_stmt->get_result();
$transactions = [];
while ($transaction = $transactions_result->fetch_assoc()) {
    $transactions[] = $transaction;
}
$transactions_stmt->close();

// Calculate expiry status
$expiry_date = strtotime($batch['expiry_date']);
$today = time();
$days_remaining = floor(($expiry_date - $today) / (60 * 60 * 24));

if ($days_remaining < 0) {
    $expiry_class = 'danger';
    $expiry_status = 'Expired';
    $expiry_text = 'Expired ' . abs($days_remaining) . ' days ago';
} elseif ($days_remaining <= 30) {
    $expiry_class = 'warning';
    $expiry_status = 'Near Expiry';
    $expiry_text = $days_remaining . ' days remaining';
} elseif ($days_remaining <= 90) {
    $expiry_class = 'info';
    $expiry_status = 'Good';
    $expiry_text = $days_remaining . ' days remaining';
} else {
    $expiry_class = 'success';
    $expiry_status = 'Good';
    $expiry_text = $days_remaining . ' days remaining';
}
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h3 class="card-title mt-2 mb-0">
                    <i class="fas fa-fw fa-box mr-2"></i>Batch Details: <?php echo htmlspecialchars($batch['batch_number']); ?>
                </h3>
                <small class="text-white-50">Item: <?php echo htmlspecialchars($batch['item_name']); ?></small>
            </div>
            <div class="card-tools">
                <a href="inventory_batches.php?item_id=<?php echo $batch['item_id']; ?>" class="btn btn-light mr-2">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Batches
                </a>
                <a href="inventory_batch_edit.php?id=<?php echo $batch_id; ?>" class="btn btn-warning">
                    <i class="fas fa-edit mr-2"></i>Edit Batch
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

        <!-- Batch Summary -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="info-box bg-gradient-<?php echo $expiry_class; ?>">
                    <span class="info-box-icon"><i class="fas fa-box"></i></span>
                    <div class="info-box-content">
                        <div class="row">
                            <div class="col-md-3">
                                <span class="info-box-text">Batch Number</span>
                                <span class="info-box-number"><?php echo htmlspecialchars($batch['batch_number']); ?></span>
                            </div>
                            <div class="col-md-3">
                                <span class="info-box-text">Expiry Status</span>
                                <span class="info-box-number">
                                    <?php echo $expiry_status; ?>
                                    <small class="d-block"><?php echo $expiry_text; ?></small>
                                </span>
                            </div>
                            <div class="col-md-3">
                                <span class="info-box-text">Total Stock</span>
                                <span class="info-box-number">
                                    <?php echo number_format($total_quantity, 3); ?> <?php echo htmlspecialchars($batch['unit_of_measure']); ?>
                                </span>
                            </div>
                            <div class="col-md-3">
                                <span class="info-box-text">Total Value</span>
                                <span class="info-box-number">$<?php echo number_format($total_value, 2); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Left Column: Batch Details -->
            <div class="col-md-6">
                <!-- Batch Information -->
                <div class="card card-primary">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-info-circle mr-2"></i>Batch Information</h3>
                    </div>
                    <div class="card-body">
                        <dl class="row">
                            <dt class="col-sm-4">Batch Number:</dt>
                            <dd class="col-sm-8"><?php echo htmlspecialchars($batch['batch_number']); ?></dd>
                            
                            <dt class="col-sm-4">Expiry Date:</dt>
                            <dd class="col-sm-8">
                                <span class="badge badge-<?php echo $expiry_class; ?>">
                                    <?php echo date('F j, Y', strtotime($batch['expiry_date'])); ?>
                                </span>
                            </dd>
                            
                            <dt class="col-sm-4">Received Date:</dt>
                            <dd class="col-sm-8"><?php echo date('F j, Y', strtotime($batch['received_date'])); ?></dd>
                            
                            <dt class="col-sm-4">Manufacturer:</dt>
                            <dd class="col-sm-8"><?php echo $batch['manufacturer'] ? htmlspecialchars($batch['manufacturer']) : '<span class="text-muted">Not specified</span>'; ?></dd>
                            
                            <dt class="col-sm-4">Supplier:</dt>
                            <dd class="col-sm-8">
                                <?php if ($batch['supplier_name']): ?>
                                    <strong><?php echo htmlspecialchars($batch['supplier_name']); ?></strong><br>
                                    <small class="text-muted">
                                        <?php if ($batch['supplier_contact']): ?>
                                            Contact: <?php echo htmlspecialchars($batch['supplier_contact']); ?><br>
                                        <?php endif; ?>
                                        <?php if ($batch['supplier_phone']): ?>
                                            Phone: <?php echo htmlspecialchars($batch['supplier_phone']); ?><br>
                                        <?php endif; ?>
                                        <?php if ($batch['supplier_email']): ?>
                                            Email: <?php echo htmlspecialchars($batch['supplier_email']); ?>
                                        <?php endif; ?>
                                    </small>
                                <?php else: ?>
                                    <span class="text-muted">No supplier specified</span>
                                <?php endif; ?>
                            </dd>
                            
                            <dt class="col-sm-4">Notes:</dt>
                            <dd class="col-sm-8"><?php echo $batch['notes'] ? nl2br(htmlspecialchars($batch['notes'])) : '<span class="text-muted">No notes</span>'; ?></dd>
                            
                            <dt class="col-sm-4">Created:</dt>
                            <dd class="col-sm-8">
                                <?php echo date('F j, Y, g:i a', strtotime($batch['created_at'])); ?><br>
                                <small class="text-muted">By: <?php echo htmlspecialchars($batch['created_by_name'] ?? 'System'); ?></small>
                            </dd>
                            
                            <dt class="col-sm-4">Last Updated:</dt>
                            <dd class="col-sm-8">
                                <?php echo date('F j, Y, g:i a', strtotime($batch['updated_at'])); ?><br>
                                <small class="text-muted">By: <?php echo htmlspecialchars($batch['updated_by_name'] ?? 'System'); ?></small>
                            </dd>
                        </dl>
                    </div>
                </div>

                <!-- Item Information -->
                <div class="card card-info mt-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-cube mr-2"></i>Item Information</h3>
                    </div>
                    <div class="card-body">
                        <dl class="row">
                            <dt class="col-sm-4">Item Name:</dt>
                            <dd class="col-sm-8">
                                <a href="inventory_item_view.php?id=<?php echo $batch['item_id']; ?>">
                                    <?php echo htmlspecialchars($batch['item_name']); ?>
                                </a>
                            </dd>
                            
                            <dt class="col-sm-4">Item Code:</dt>
                            <dd class="col-sm-8"><?php echo htmlspecialchars($batch['item_code']); ?></dd>
                            
                            <dt class="col-sm-4">Category:</dt>
                            <dd class="col-sm-8"><?php echo htmlspecialchars($batch['category_name']); ?></dd>
                            
                            <dt class="col-sm-4">Unit of Measure:</dt>
                            <dd class="col-sm-8"><?php echo htmlspecialchars($batch['unit_of_measure']); ?></dd>
                            
                            <dt class="col-sm-4">Item Type:</dt>
                            <dd class="col-sm-8">
                                <?php if ($batch['is_drug'] == 1): ?>
                                    <span class="badge badge-warning">Drug</span>
                                <?php endif; ?>
                                <?php if ($batch['requires_batch'] == 1): ?>
                                    <span class="badge badge-info">Batch Required</span>
                                <?php endif; ?>
                            </dd>
                        </dl>
                        <div class="text-center mt-3">
                            <a href="inventory_item_view.php?id=<?php echo $batch['item_id']; ?>" class="btn btn-outline-info btn-sm">
                                <i class="fas fa-external-link-alt mr-1"></i>View Full Item Details
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Stock and Transactions -->
            <div class="col-md-6">
                <!-- Stock Locations -->
                <div class="card card-success">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-map-marker-alt mr-2"></i>Stock Locations</h3>
                        <div class="card-tools">
                            <span class="badge badge-light"><?php echo count($stock_locations); ?> locations</span>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($stock_locations)): ?>
                            <div class="text-center py-4">
                                <i class="fas fa-warehouse fa-2x text-muted mb-3"></i>
                                <h5>No Stock Available</h5>
                                <p class="text-muted">This batch has not been added to any location yet.</p>
                                <a href="inventory_stock_add.php?batch_id=<?php echo $batch_id; ?>" class="btn btn-success">
                                    <i class="fas fa-plus mr-1"></i>Add Stock to Location
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                    <thead>
                                        <tr>
                                            <th>Location</th>
                                            <th class="text-center">Type</th>
                                            <th class="text-right">Quantity</th>
                                            <th class="text-right">Unit Cost</th>
                                            <th class="text-right">Value</th>
                                            <th class="text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($stock_locations as $stock): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($stock['location_name']); ?></strong>
                                                    <?php if ($stock['last_movement_at']): ?>
                                                        <br>
                                                        <small class="text-muted">Last movement: <?php echo date('M j, Y', strtotime($stock['last_movement_at'])); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge badge-secondary"><?php echo $stock['location_type']; ?></span>
                                                </td>
                                                <td class="text-right">
                                                    <?php echo number_format($stock['quantity'], 3); ?> <?php echo htmlspecialchars($batch['unit_of_measure']); ?>
                                                </td>
                                                <td class="text-right">$<?php echo number_format($stock['unit_cost'], 4); ?></td>
                                                <td class="text-right">$<?php echo number_format($stock['value'], 2); ?></td>
                                                <td class="text-center">
                                                    <div class="btn-group">
                                                        <a href="inventory_transfer_create.php?batch_id=<?php echo $batch_id; ?>&from_location=<?php echo $stock['location_id']; ?>" 
                                                           class="btn btn-xs btn-warning" title="Transfer Stock">
                                                            <i class="fas fa-exchange-alt"></i>
                                                        </a>
                                                        <a href="inventory_stock_adjust.php?stock_id=<?php echo $stock['stock_id']; ?>" 
                                                           class="btn btn-xs btn-info" title="Adjust Stock">
                                                            <i class="fas fa-sliders-h"></i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr class="bg-light">
                                            <td colspan="2" class="text-right"><strong>Totals:</strong></td>
                                            <td class="text-right"><strong><?php echo number_format($total_quantity, 3); ?></strong></td>
                                            <td class="text-right">-</td>
                                            <td class="text-right"><strong>$<?php echo number_format($total_value, 2); ?></strong></td>
                                            <td></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                            <div class="text-center mt-2">
                                <a href="inventory_stock_add.php?batch_id=<?php echo $batch_id; ?>" class="btn btn-success btn-sm">
                                    <i class="fas fa-plus mr-1"></i>Add More Stock
                                </a>
                                <a href="inventory_transfer_create.php?batch_id=<?php echo $batch_id; ?>" class="btn btn-warning btn-sm">
                                    <i class="fas fa-exchange-alt mr-1"></i>Transfer Stock
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Transactions -->
                <div class="card card-warning mt-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-history mr-2"></i>Recent Transactions</h3>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($transactions)): ?>
                            <div class="text-center py-4">
                                <i class="fas fa-history fa-2x text-muted mb-3"></i>
                                <h5>No Transactions Found</h5>
                                <p class="text-muted">No transactions recorded for this batch yet.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Date</th>
                                            <th>Type</th>
                                            <th>Quantity</th>
                                            <th>From/To</th>
                                            <th>Performed By</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($transactions as $transaction): 
                                            $type_classes = [
                                                'GRN' => 'success',
                                                'ISSUE' => 'primary',
                                                'TRANSFER_OUT' => 'warning',
                                                'TRANSFER_IN' => 'info',
                                                'ADJUSTMENT' => 'secondary',
                                                'WASTAGE' => 'danger',
                                                'RETURN' => 'dark'
                                            ];
                                            $type_class = $type_classes[$transaction['transaction_type']] ?? 'secondary';
                                        ?>
                                            <tr>
                                                <td><?php echo date('M j, H:i', strtotime($transaction['created_at'])); ?></td>
                                                <td>
                                                    <span class="badge badge-<?php echo $type_class; ?>">
                                                        <?php echo $transaction['transaction_type']; ?>
                                                    </span>
                                                </td>
                                                <td><?php echo number_format($transaction['quantity'], 3); ?></td>
                                                <td>
                                                    <?php if ($transaction['from_location_name']): ?>
                                                        <small><?php echo htmlspecialchars($transaction['from_location_name']); ?> →</small><br>
                                                    <?php endif; ?>
                                                    <?php if ($transaction['to_location_name']): ?>
                                                        <small>→ <?php echo htmlspecialchars($transaction['to_location_name']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <small><?php echo htmlspecialchars($transaction['performed_by_name'] ?? 'System'); ?></small>
                                                    <?php if ($transaction['reason']): ?>
                                                        <br><small class="text-muted" data-toggle="tooltip" title="<?php echo htmlspecialchars($transaction['reason']); ?>">
                                                            <i class="fas fa-comment"></i>
                                                        </small>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="card-footer text-center">
                                <a href="inventory_transactions.php?batch_id=<?php echo $batch_id; ?>" class="btn btn-outline-warning btn-sm">
                                    <i class="fas fa-list mr-1"></i>View All Transactions
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="row mt-4">
            <div class="col-md-12">
                <div class="card card-secondary">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-bolt mr-2"></i>Quick Actions</h3>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3">
                                <a href="inventory_stock_add.php?batch_id=<?php echo $batch_id; ?>" class="btn btn-success btn-block mb-2">
                                    <i class="fas fa-plus mr-2"></i>Add Stock
                                </a>
                            </div>
                            <div class="col-md-3">
                                <a href="inventory_transfer_create.php?batch_id=<?php echo $batch_id; ?>" class="btn btn-warning btn-block mb-2">
                                    <i class="fas fa-exchange-alt mr-2"></i>Transfer Stock
                                </a>
                            </div>
                            <div class="col-md-3">
                                <a href="inventory_stock_adjust.php?batch_id=<?php echo $batch_id; ?>" class="btn btn-info btn-block mb-2">
                                    <i class="fas fa-sliders-h mr-2"></i>Adjust Stock
                                </a>
                            </div>
                            <div class="col-md-3">
                                <a href="inventory_batch_edit.php?id=<?php echo $batch_id; ?>" class="btn btn-primary btn-block mb-2">
                                    <i class="fas fa-edit mr-2"></i>Edit Batch
                                </a>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-3">
                                <a href="inventory_grn_create.php?batch_id=<?php echo $batch_id; ?>" class="btn btn-outline-success btn-block mb-2">
                                    <i class="fas fa-receipt mr-2"></i>Create GRN
                                </a>
                            </div>
                            <div class="col-md-3">
                                <a href="inventory_batches.php?item_id=<?php echo $batch['item_id']; ?>" class="btn btn-outline-info btn-block mb-2">
                                    <i class="fas fa-arrow-left mr-2"></i>View All Batches
                                </a>
                            </div>
                            <div class="col-md-3">
                                <a href="inventory_item_view.php?id=<?php echo $batch['item_id']; ?>" class="btn btn-outline-primary btn-block mb-2">
                                    <i class="fas fa-cube mr-2"></i>View Item
                                </a>
                            </div>
                            <div class="col-md-3">
                                <button type="button" class="btn btn-outline-danger btn-block mb-2" onclick="printBatchDetails()">
                                    <i class="fas fa-print mr-2"></i>Print Details
                                </button>
                            </div>
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
    
    // Update expiry warning every minute
    setInterval(function() {
        const expiryBadge = $('.info-box .badge');
        if (expiryBadge.hasClass('badge-danger') || expiryBadge.hasClass('badge-warning')) {
            expiryBadge.fadeOut(300).fadeIn(300);
        }
    }, 60000);
});

function printBatchDetails() {
    const printContent = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>Batch Details: <?php echo htmlspecialchars($batch['batch_number']); ?></title>
            <style>
                body { font-family: Arial, sans-serif; }
                .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #333; padding-bottom: 10px; }
                .section { margin-bottom: 15px; }
                .section-title { font-weight: bold; background-color: #f0f0f0; padding: 5px; }
                .row { display: flex; margin-bottom: 5px; }
                .col-label { width: 150px; font-weight: bold; }
                .col-value { flex: 1; }
                table { width: 100%; border-collapse: collapse; margin: 10px 0; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                th { background-color: #f2f2f2; }
                .footer { margin-top: 30px; text-align: center; font-size: 12px; color: #666; }
            </style>
        </head>
        <body>
            <div class="header">
                <h2>Batch Details Report</h2>
                <h3>Batch: <?php echo htmlspecialchars($batch['batch_number']); ?></h3>
                <p>Generated on: ${new Date().toLocaleString()}</p>
            </div>
            
            <div class="section">
                <div class="section-title">Batch Information</div>
                <div class="row"><div class="col-label">Batch Number:</div><div class="col-value"><?php echo htmlspecialchars($batch['batch_number']); ?></div></div>
                <div class="row"><div class="col-label">Expiry Date:</div><div class="col-value"><?php echo date('F j, Y', strtotime($batch['expiry_date'])); ?> (<?php echo $expiry_text; ?>)</div></div>
                <div class="row"><div class="col-label">Received Date:</div><div class="col-value"><?php echo date('F j, Y', strtotime($batch['received_date'])); ?></div></div>
                <div class="row"><div class="col-label">Manufacturer:</div><div class="col-value"><?php echo htmlspecialchars($batch['manufacturer'] ?? 'Not specified'); ?></div></div>
                <div class="row"><div class="col-label">Supplier:</div><div class="col-value"><?php echo htmlspecialchars($batch['supplier_name'] ?? 'Not specified'); ?></div></div>
                <div class="row"><div class="col-label">Notes:</div><div class="col-value"><?php echo nl2br(htmlspecialchars($batch['notes'] ?? 'None')); ?></div></div>
            </div>
            
            <div class="section">
                <div class="section-title">Item Information</div>
                <div class="row"><div class="col-label">Item Name:</div><div class="col-value"><?php echo htmlspecialchars($batch['item_name']); ?></div></div>
                <div class="row"><div class="col-label">Item Code:</div><div class="col-value"><?php echo htmlspecialchars($batch['item_code']); ?></div></div>
                <div class="row"><div class="col-label">Category:</div><div class="col-value"><?php echo htmlspecialchars($batch['category_name']); ?></div></div>
                <div class="row"><div class="col-label">Unit of Measure:</div><div class="col-value"><?php echo htmlspecialchars($batch['unit_of_measure']); ?></div></div>
            </div>
            
            <div class="section">
                <div class="section-title">Stock Summary</div>
                <div class="row"><div class="col-label">Total Quantity:</div><div class="col-value"><?php echo number_format($total_quantity, 3); ?> <?php echo htmlspecialchars($batch['unit_of_measure']); ?></div></div>
                <div class="row"><div class="col-label">Total Value:</div><div class="col-value">$<?php echo number_format($total_value, 2); ?></div></div>
                <div class="row"><div class="col-label">Number of Locations:</div><div class="col-value"><?php echo count($stock_locations); ?></div></div>
            </div>
            
            <?php if (!empty($stock_locations)): ?>
            <div class="section">
                <div class="section-title">Stock Locations</div>
                <table>
                    <thead>
                        <tr>
                            <th>Location</th>
                            <th>Quantity</th>
                            <th>Unit Cost</th>
                            <th>Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stock_locations as $stock): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($stock['location_name']); ?> (<?php echo $stock['location_type']; ?>)</td>
                            <td><?php echo number_format($stock['quantity'], 3); ?></td>
                            <td>$<?php echo number_format($stock['unit_cost'], 4); ?></td>
                            <td>$<?php echo number_format($stock['value'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr style="font-weight: bold; background-color: #f0f0f0;">
                            <td>Total</td>
                            <td><?php echo number_format($total_quantity, 3); ?></td>
                            <td>-</td>
                            <td>$<?php echo number_format($total_value, 2); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
            <div class="footer">
                <p>Report generated by: <?php echo $_SESSION['user_name'] ?? 'System'; ?></p>
                <p>© <?php echo date('Y'); ?> Clinic Inventory System</p>
            </div>
        </body>
        </html>
    `;
    
    const printWindow = window.open('', '_blank');
    printWindow.document.write(printContent);
    printWindow.document.close();
    printWindow.focus();
    setTimeout(() => {
        printWindow.print();
        printWindow.close();
    }, 500);
}
</script>

<style>
.info-box {
    box-shadow: 0 0 1px rgba(0,0,0,.125), 0 1px 3px rgba(0,0,0,.2);
    border-radius: .25rem;
    background: #fff;
    display: flex;
    margin-bottom: 1rem;
    min-height: 80px;
    padding: .5rem;
    position: relative;
}

.info-box .info-box-icon {
    border-radius: .25rem;
    display: flex;
    font-size: 1.875rem;
    justify-content: center;
    text-align: center;
    width: 70px;
    align-items: center;
}

.info-box .info-box-content {
    display: flex;
    flex-direction: column;
    justify-content: center;
    line-height: 1.8;
    flex: 1;
    padding: 0 10px;
}

.info-box .info-box-text {
    display: block;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    text-transform: uppercase;
    font-weight: 700;
    font-size: .875rem;
}

.info-box .info-box-number {
    font-weight: 700;
    font-size: 1.5rem;
}

.badge-danger {
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0% { opacity: 1; }
    50% { opacity: 0.5; }
    100% { opacity: 1; }
}
</style>

<?php 
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
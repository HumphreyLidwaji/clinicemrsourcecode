<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

$item_id = intval($_GET['item_id'] ?? 0);

if ($item_id <= 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid item ID";
    header("Location: inventory_items.php");
    exit;
}

// Get item details
$item_sql = "SELECT 
                i.item_id, 
                i.item_name, 
                i.item_code,
                i.unit_of_measure,
                i.category_id,
                i.is_drug,
                i.requires_batch,
                c.category_name
            FROM inventory_items i
            LEFT JOIN inventory_categories c ON i.category_id = c.category_id
            WHERE i.item_id = ? AND i.is_active = 1";
            
$item_stmt = $mysqli->prepare($item_sql);
$item_stmt->bind_param("i", $item_id);
$item_stmt->execute();
$item_result = $item_stmt->get_result();
$item = $item_result->fetch_assoc();
$item_stmt->close();

if (!$item) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Item not found";
    header("Location: inventory_items.php");
    exit;
}

// Get item stock summary across all locations
$stock_summary_sql = "SELECT 
                        SUM(ils.quantity) as total_quantity,
                        COUNT(DISTINCT ils.location_id) as location_count,
                        MIN(ib.expiry_date) as nearest_expiry,
                        MAX(ib.expiry_date) as furthest_expiry
                    FROM inventory_location_stock ils
                    INNER JOIN inventory_batches ib ON ils.batch_id = ib.batch_id
                    WHERE ib.item_id = ? AND ils.is_active = 1 AND ib.is_active = 1";
                    
$stock_stmt = $mysqli->prepare($stock_summary_sql);
$stock_stmt->bind_param("i", $item_id);
$stock_stmt->execute();
$stock_result = $stock_stmt->get_result();
$stock_summary = $stock_result->fetch_assoc();
$stock_stmt->close();

// Get all batches for this item
$batches_sql = "SELECT 
                    ib.batch_id,
                    ib.batch_number,
                    ib.expiry_date,
                    ib.manufacturer,
                    ib.supplier_id,
                    ib.received_date,
                    ib.notes,
                    s.supplier_name,
                    s.supplier_contact,
                    s.supplier_phone,
                    s.supplier_email,
                    COALESCE(SUM(ils.quantity), 0) as total_quantity,
                    COUNT(DISTINCT ils.location_id) as location_count,
                    GROUP_CONCAT(DISTINCT il.location_name ORDER BY il.location_name SEPARATOR ', ') as locations
                FROM inventory_batches ib
                LEFT JOIN suppliers s ON ib.supplier_id = s.supplier_id
                LEFT JOIN inventory_location_stock ils ON ib.batch_id = ils.batch_id AND ils.is_active = 1
                LEFT JOIN inventory_locations il ON ils.location_id = il.location_id
                WHERE ib.item_id = ? AND ib.is_active = 1
                GROUP BY ib.batch_id, ib.batch_number, ib.expiry_date, ib.manufacturer, 
                         ib.supplier_id, ib.received_date, ib.notes,
                         s.supplier_name, s.supplier_contact, s.supplier_phone, s.supplier_email
                ORDER BY ib.expiry_date ASC, ib.received_date DESC";
                
$batches_stmt = $mysqli->prepare($batches_sql);
$batches_stmt->bind_param("i", $item_id);
$batches_stmt->execute();
$batches_result = $batches_stmt->get_result();
$batches = [];
while ($batch = $batches_result->fetch_assoc()) {
    $batches[] = $batch;
}
$batches_stmt->close();

// Handle batch deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_batch'])) {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    $batch_id = intval($_POST['batch_id']);
    
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token";
        header("Location: inventory_batches.php?item_id=" . $item_id);
        exit;
    }
    
    // Check if batch has any stock
    $check_sql = "SELECT SUM(quantity) as total_stock FROM inventory_location_stock WHERE batch_id = ? AND is_active = 1";
    $check_stmt = $mysqli->prepare($check_sql);
    $check_stmt->bind_param("i", $batch_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $check_data = $check_result->fetch_assoc();
    $check_stmt->close();
    
    if ($check_data['total_stock'] > 0) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Cannot delete batch that still has stock. Please transfer or adjust stock first.";
        header("Location: inventory_batches.php?item_id=" . $item_id);
        exit;
    }
    
    // Soft delete the batch
    $delete_sql = "UPDATE inventory_batches SET is_active = 0, updated_by = ?, updated_at = NOW() WHERE batch_id = ?";
    $delete_stmt = $mysqli->prepare($delete_sql);
    $delete_stmt->bind_param("ii", $session_user_id, $batch_id);
    
    if ($delete_stmt->execute()) {
        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Batch deleted successfully";
    } else {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error deleting batch: " . $mysqli->error;
    }
    
    $delete_stmt->close();
    header("Location: inventory_batches.php?item_id=" . $item_id);
    exit;
}
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h3 class="card-title mt-2 mb-0">
                    <i class="fas fa-fw fa-boxes mr-2"></i>Batches for: <?php echo htmlspecialchars($item['item_name']); ?>
                </h3>
                <small class="text-white-50">Item Code: <?php echo htmlspecialchars($item['item_code']); ?></small>
            </div>
            <div class="card-tools">
                <a href="inventory_items.php" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Items
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

        <!-- Item Summary -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="info-box bg-gradient-info">
                    <span class="info-box-icon"><i class="fas fa-cube"></i></span>
                    <div class="info-box-content">
                        <div class="row">
                            <div class="col-md-3">
                                <span class="info-box-text">Total Stock</span>
                                <span class="info-box-number">
                                    <?php echo number_format($stock_summary['total_quantity'] ?? 0, 3); ?> <?php echo htmlspecialchars($item['unit_of_measure']); ?>
                                </span>
                            </div>
                            <div class="col-md-3">
                                <span class="info-box-text">Active Batches</span>
                                <span class="info-box-number"><?php echo count($batches); ?></span>
                            </div>
                            <div class="col-md-3">
                                <span class="info-box-text">Nearest Expiry</span>
                                <span class="info-box-number">
                                    <?php echo $stock_summary['nearest_expiry'] ? date('M d, Y', strtotime($stock_summary['nearest_expiry'])) : 'N/A'; ?>
                                </span>
                            </div>
                            <div class="col-md-3">
                                <span class="info-box-text">Location Count</span>
                                <span class="info-box-number"><?php echo $stock_summary['location_count'] ?? 0; ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-boxes mr-2"></i>Batch Management</h3>
                        <div class="card-tools">
                            <?php if ($item['requires_batch'] == 1): ?>
                                <a href="inventory_batch_create.php?item_id=<?php echo $item_id; ?>" class="btn btn-sm btn-success">
                                    <i class="fas fa-plus mr-1"></i>Add New Batch
                                </a>
                            <?php else: ?>
                                <span class="badge badge-info">Non-batch item</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if ($item['requires_batch'] == 0): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle mr-2"></i>
                                This item does not require batch tracking. Stock is managed without batch numbers.
                            </div>
                        <?php endif; ?>
                        
                        <?php if (empty($batches) && $item['requires_batch'] == 1): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-box-open fa-3x text-muted mb-3"></i>
                                <h4>No Batches Found</h4>
                                <p class="text-muted">This item has no active batches.</p>
                                <a href="inventory_batch_create.php?item_id=<?php echo $item_id; ?>" class="btn btn-success">
                                    <i class="fas fa-plus mr-1"></i>Add First Batch
                                </a>
                            </div>
                        <?php elseif (!empty($batches)): ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover" id="batchesTable">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Batch Number</th>
                                            <th class="text-center">Expiry Date</th>
                                            <th>Manufacturer/Supplier</th>
                                            <th class="text-center">Received Date</th>
                                            <th class="text-center">Total Quantity</th>
                                            <th class="text-center">Locations</th>
                                            <th>Notes</th>
                                            <th class="text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($batches as $batch): 
                                            $expiry_class = '';
                                            $expiry_date = strtotime($batch['expiry_date']);
                                            $today = time();
                                            $days_remaining = floor(($expiry_date - $today) / (60 * 60 * 24));
                                            
                                            if ($days_remaining < 0) {
                                                $expiry_class = 'danger';
                                                $expiry_text = 'Expired ' . abs($days_remaining) . ' days ago';
                                            } elseif ($days_remaining <= 30) {
                                                $expiry_class = 'warning';
                                                $expiry_text = $days_remaining . ' days remaining';
                                            } elseif ($days_remaining <= 90) {
                                                $expiry_class = 'info';
                                                $expiry_text = $days_remaining . ' days remaining';
                                            } else {
                                                $expiry_class = 'success';
                                                $expiry_text = $days_remaining . ' days remaining';
                                            }
                                        ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($batch['batch_number']); ?></strong>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge badge-<?php echo $expiry_class; ?>" 
                                                          data-toggle="tooltip" title="<?php echo $expiry_text; ?>">
                                                        <?php echo date('M d, Y', $expiry_date); ?>
                                                    </span>
                                                    <?php if ($days_remaining <= 30): ?>
                                                        <br><small class="text-<?php echo $expiry_class; ?>"><?php echo $expiry_text; ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($batch['manufacturer']): ?>
                                                        <strong><?php echo htmlspecialchars($batch['manufacturer']); ?></strong><br>
                                                    <?php endif; ?>
                                                    <?php if ($batch['supplier_name']): ?>
                                                        <small class="text-muted">Supplier: <?php echo htmlspecialchars($batch['supplier_name']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <?php echo date('M d, Y', strtotime($batch['received_date'])); ?>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge badge-primary">
                                                        <?php echo number_format($batch['total_quantity'], 3); ?> <?php echo htmlspecialchars($item['unit_of_measure']); ?>
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($batch['locations']): ?>
                                                        <span data-toggle="tooltip" title="<?php echo htmlspecialchars($batch['locations']); ?>">
                                                            <?php echo $batch['location_count']; ?> location(s)
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge badge-warning">No stock</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($batch['notes']): ?>
                                                        <span data-toggle="tooltip" title="<?php echo htmlspecialchars($batch['notes']); ?>">
                                                            <i class="fas fa-sticky-note text-muted"></i>
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <div class="btn-group">
                                                        <a href="inventory_batch_view.php?id=<?php echo $batch['batch_id']; ?>" 
                                                           class="btn btn-sm btn-info" title="View Details">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        <a href="inventory_batch_edit.php?id=<?php echo $batch['batch_id']; ?>" 
                                                           class="btn btn-sm btn-warning" title="Edit">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <button type="button" class="btn btn-sm btn-danger delete-batch-btn" 
                                                                data-batch-id="<?php echo $batch['batch_id']; ?>"
                                                                data-batch-number="<?php echo htmlspecialchars($batch['batch_number']); ?>"
                                                                title="Delete">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="row mt-4">
            <div class="col-md-4">
                <div class="card card-success">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-plus-circle mr-2"></i>Stock Actions</h3>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <?php if ($item['requires_batch'] == 1): ?>
                                <a href="inventory_stock_add.php?item_id=<?php echo $item_id; ?>" class="btn btn-success">
                                    <i class="fas fa-plus mr-2"></i>Add Stock (New Batch)
                                </a>
                            <?php else: ?>
                                <a href="inventory_stock_adjust.php?item_id=<?php echo $item_id; ?>" class="btn btn-success">
                                    <i class="fas fa-plus mr-2"></i>Add Stock
                                </a>
                            <?php endif; ?>
                            <a href="inventory_transfer_create.php?item_id=<?php echo $item_id; ?>" class="btn btn-warning">
                                <i class="fas fa-exchange-alt mr-2"></i>Transfer Stock
                            </a>
                            <a href="inventory_stock_adjust.php?item_id=<?php echo $item_id; ?>&adjustment_type=wastage" class="btn btn-danger">
                                <i class="fas fa-trash mr-2"></i>Record Wastage
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card card-info">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-history mr-2"></i>Transaction History</h3>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="inventory_transactions.php?item_id=<?php echo $item_id; ?>" class="btn btn-info">
                                <i class="fas fa-list mr-2"></i>View All Transactions
                            </a>
                            <a href="inventory_transactions.php?item_id=<?php echo $item_id; ?>&type=GRN" class="btn btn-outline-info">
                                <i class="fas fa-receipt mr-2"></i>Goods Receipt Notes
                            </a>
                            <a href="inventory_transactions.php?item_id=<?php echo $item_id; ?>&type=ISSUE" class="btn btn-outline-info">
                                <i class="fas fa-share mr-2"></i>Issue History
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card card-primary">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-cogs mr-2"></i>Item Management</h3>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="inventory_item_edit.php?id=<?php echo $item_id; ?>" class="btn btn-primary">
                                <i class="fas fa-edit mr-2"></i>Edit Item Details
                            </a>
                            <a href="inventory_item_view.php?id=<?php echo $item_id; ?>" class="btn btn-outline-primary">
                                <i class="fas fa-info-circle mr-2"></i>View Item Details
                            </a>
                            <?php if ($item['is_drug'] == 1): ?>
                                <button type="button" class="btn btn-outline-warning">
                                    <i class="fas fa-prescription mr-2"></i>Drug Information
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-exclamation-triangle mr-2"></i>Confirm Deletion</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete batch <strong id="deleteBatchNumber"></strong>?</p>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-circle mr-2"></i>
                    <strong>Warning:</strong> This action cannot be undone. Please ensure the batch has no remaining stock before deleting.
                </div>
                <form id="deleteForm" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="delete_batch" value="1">
                    <input type="hidden" name="batch_id" id="deleteBatchId">
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="submit" form="deleteForm" class="btn btn-danger">Delete Batch</button>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize DataTable
    $('#batchesTable').DataTable({
        pageLength: 25,
        order: [[1, 'asc']], // Sort by expiry date by default
        language: {
            search: "Search batches:"
        },
        columnDefs: [
            { orderable: false, targets: [7] } // Disable sorting on actions column
        ]
    });
    
    // Initialize tooltips
    $('[data-toggle="tooltip"]').tooltip();
    
    // Handle delete button clicks
    $('.delete-batch-btn').click(function() {
        const batchId = $(this).data('batch-id');
        const batchNumber = $(this).data('batch-number');
        
        $('#deleteBatchId').val(batchId);
        $('#deleteBatchNumber').text(batchNumber);
        $('#deleteModal').modal('show');
    });
    
    // Auto-refresh page every 5 minutes to check for expiry alerts
    setInterval(function() {
        // Check if any batches are near expiry
        $('.badge-danger, .badge-warning').each(function() {
            // Visual alert for expired/near expiry items
            if ($(this).hasClass('badge-danger')) {
                $(this).fadeOut(500).fadeIn(500);
            }
        });
    }, 300000); // 5 minutes
});
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
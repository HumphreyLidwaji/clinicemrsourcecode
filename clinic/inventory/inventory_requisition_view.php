<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Get requisition ID from URL
$requisition_id = intval($_GET['id'] ?? 0);

if ($requisition_id <= 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid requisition ID.";
    header("Location: inventory_requisitions.php");
    exit;
}

// Initialize variables
$requisition = null;
$requisition_items = [];
$transactions = [];

// Fetch requisition details
$requisition_sql = "SELECT 
                    r.*,
                    fl.location_name as from_location_name,
                    fl.location_type as from_location_type,
                    dl.location_name as delivery_location_name,
                    dl.location_type as delivery_location_type,
                    d.department_name,
                    ur.user_name as requested_by_name,
                    ua.user_name as approved_by_name,
                    uf.user_name as fulfilled_by_name,
                    uc.user_name as created_by_name
                  FROM inventory_requisitions r
                  LEFT JOIN inventory_locations fl ON r.from_location_id = fl.location_id
                  LEFT JOIN inventory_locations dl ON r.delivery_location_id = dl.location_id
                  LEFT JOIN departments d ON r.department_id = d.department_id
                  LEFT JOIN users ur ON r.requested_by = ur.user_id
                  LEFT JOIN users ua ON r.approved_by = ua.user_id
                  LEFT JOIN users uf ON r.fulfilled_by = uf.user_id
                  LEFT JOIN users uc ON r.created_by = uc.user_id
                  WHERE r.requisition_id = ? AND r.is_active = 1";
              
$requisition_stmt = $mysqli->prepare($requisition_sql);
$requisition_stmt->bind_param("i", $requisition_id);
$requisition_stmt->execute();
$requisition_result = $requisition_stmt->get_result();

if ($requisition_result->num_rows === 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Requisition not found.";
    header("Location: inventory_requisitions.php");
    exit;
}

$requisition = $requisition_result->fetch_assoc();
$requisition_stmt->close();

// Fetch requisition items
$items_sql = "SELECT 
                ri.*,
                i.item_name,
                i.item_code,
                i.unit_of_measure,
                i.requires_batch,
                c.category_name,
                COALESCE(SUM(ils.quantity), 0) as available_stock
              FROM inventory_requisition_items ri
              INNER JOIN inventory_items i ON ri.item_id = i.item_id
              LEFT JOIN inventory_categories c ON i.category_id = c.category_id
              LEFT JOIN inventory_location_stock ils ON i.item_id = ils.item_id 
                AND ils.location_id = ? 
                AND ils.is_active = 1
              WHERE ri.requisition_id = ? 
              AND ri.is_active = 1
              GROUP BY ri.requisition_item_id, i.item_id, i.item_name, i.item_code, i.unit_of_measure, i.requires_batch, c.category_name
              ORDER BY ri.requisition_item_id";
              
$items_stmt = $mysqli->prepare($items_sql);
$items_stmt->bind_param("ii", $requisition['from_location_id'], $requisition_id);
$items_stmt->execute();
$items_result = $items_stmt->get_result();

while ($item = $items_result->fetch_assoc()) {
    $requisition_items[] = $item;
}
$items_stmt->close();

// Fetch related transactions
$transactions_sql = "SELECT 
                        t.*,
                        i.item_name,
                        i.item_code,
                        i.unit_of_measure,
                        b.batch_number,
                        fl.location_name as from_location_name,
                        tl.location_name as to_location_name,
                        u.user_name as user_name
                     FROM inventory_transactions t
                     LEFT JOIN inventory_items i ON t.item_id = i.item_id
                     LEFT JOIN inventory_batches b ON t.batch_id = b.batch_id
                     LEFT JOIN inventory_locations fl ON t.from_location_id = fl.location_id
                     LEFT JOIN inventory_locations tl ON t.to_location_id = tl.location_id
                     LEFT JOIN users u ON t.created_by = u.user_id
                     WHERE t.reference_type = 'REQUISITION' 
                     AND t.reference_id = ?
                     AND t.is_active = 1
                     ORDER BY t.created_at DESC";
                     
$transactions_stmt = $mysqli->prepare($transactions_sql);
$transactions_stmt->bind_param("i", $requisition_id);
$transactions_stmt->execute();
$transactions_result = $transactions_stmt->get_result();

while ($transaction = $transactions_result->fetch_assoc()) {
    $transactions[] = $transaction;
}
$transactions_stmt->close();

// Handle actions (approve, reject, fulfill, etc.)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    $action = sanitizeInput($_POST['action'] ?? '');
    
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token.";
        header("Location: inventory_requisition_view.php?id=" . $requisition_id);
        exit;
    }
    
    $mysqli->begin_transaction();
    
    try {
        switch ($action) {
            case 'approve':
                if ($requisition['status'] === 'pending') {
                    $update_sql = "UPDATE inventory_requisitions SET 
                                   status = 'approved',
                                   approved_by = ?,
                                   approved_at = NOW(),
                                   updated_by = ?,
                                   updated_at = NOW()
                                   WHERE requisition_id = ?";
                    $update_stmt = $mysqli->prepare($update_sql);
                    $update_stmt->bind_param("iii", $session_user_id, $session_user_id, $requisition_id);
                    $update_stmt->execute();
                    $update_stmt->close();
                    
                    // Log action
                    log_activity($mysqli, $session_user_id, 'Requisition Approved', 
                                "Approved requisition #" . $requisition['requisition_number']);
                    
                    $_SESSION['alert_type'] = "success";
                    $_SESSION['alert_message'] = "Requisition approved successfully!";
                }
                break;
                
            case 'reject':
                if ($requisition['status'] === 'pending') {
                    $reject_reason = sanitizeInput($_POST['reject_reason'] ?? '');
                    
                    $update_sql = "UPDATE inventory_requisitions SET 
                                   status = 'rejected',
                                   approved_by = ?,
                                   approved_at = NOW(),
                                   notes = CONCAT(notes, '\n\nRejection Reason: ', ?),
                                   updated_by = ?,
                                   updated_at = NOW()
                                   WHERE requisition_id = ?";
                    $update_stmt = $mysqli->prepare($update_sql);
                    $update_stmt->bind_param("issi", $session_user_id, $reject_reason, $session_user_id, $requisition_id);
                    $update_stmt->execute();
                    $update_stmt->close();
                    
                    // Log action
                    log_activity($mysqli, $session_user_id, 'Requisition Rejected', 
                                "Rejected requisition #" . $requisition['requisition_number']);
                    
                    $_SESSION['alert_type'] = "warning";
                    $_SESSION['alert_message'] = "Requisition rejected.";
                }
                break;
                
            case 'fulfill':
                if ($requisition['status'] === 'approved') {
                    // Check if all items have enough stock
                    $all_items_available = true;
                    $unavailable_items = [];
                    
                    foreach ($requisition_items as $item) {
                        if ($item['quantity_issued'] < $item['quantity_approved'] || 
                            $item['quantity_approved'] < $item['quantity_requested']) {
                            $all_items_available = false;
                            $unavailable_items[] = $item['item_name'];
                        }
                    }
                    
                    if (!$all_items_available) {
                        $_SESSION['alert_type'] = "error";
                        $_SESSION['alert_message'] = "Cannot fulfill requisition. Some items are not fully approved or issued.";
                        header("Location: inventory_requisition_view.php?id=" . $requisition_id);
                        exit;
                    }
                    
                    $update_sql = "UPDATE inventory_requisitions SET 
                                   status = 'fulfilled',
                                   fulfilled_by = ?,
                                   fulfilled_at = NOW(),
                                   updated_by = ?,
                                   updated_at = NOW()
                                   WHERE requisition_id = ?";
                    $update_stmt = $mysqli->prepare($update_sql);
                    $update_stmt->bind_param("iii", $session_user_id, $session_user_id, $requisition_id);
                    $update_stmt->execute();
                    $update_stmt->close();
                    
                    // Log action
                    log_activity($mysqli, $session_user_id, 'Requisition Fulfilled', 
                                "Fulfilled requisition #" . $requisition['requisition_number']);
                    
                    $_SESSION['alert_type'] = "success";
                    $_SESSION['alert_message'] = "Requisition marked as fulfilled!";
                }
                break;
                
            case 'update_items':
                if ($requisition['status'] === 'approved') {
                    $approved_quantities = $_POST['quantity_approved'] ?? [];
                    $issued_quantities = $_POST['quantity_issued'] ?? [];
                    
                    foreach ($requisition_items as $index => $item) {
                        $item_id = $item['requisition_item_id'];
                        $approved_qty = floatval($approved_quantities[$index] ?? 0);
                        $issued_qty = floatval($issued_quantities[$index] ?? 0);
                        
                        if ($approved_qty > $item['quantity_requested']) {
                            $approved_qty = $item['quantity_requested'];
                        }
                        
                        if ($issued_qty > $approved_qty) {
                            $issued_qty = $approved_qty;
                        }
                        
                        $update_sql = "UPDATE inventory_requisition_items SET 
                                       quantity_approved = ?,
                                       quantity_issued = ?,
                                       updated_by = ?,
                                       updated_at = NOW()
                                       WHERE requisition_item_id = ?";
                        $update_stmt = $mysqli->prepare($update_sql);
                        $update_stmt->bind_param("ddii", $approved_qty, $issued_qty, $session_user_id, $item_id);
                        $update_stmt->execute();
                        $update_stmt->close();
                    }
                    
                    // Update requisition status based on issued quantities
                    $check_sql = "SELECT 
                                  SUM(quantity_requested) as total_requested,
                                  SUM(quantity_approved) as total_approved,
                                  SUM(quantity_issued) as total_issued
                                  FROM inventory_requisition_items 
                                  WHERE requisition_id = ? AND is_active = 1";
                    $check_stmt = $mysqli->prepare($check_sql);
                    $check_stmt->bind_param("i", $requisition_id);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result();
                    $totals = $check_result->fetch_assoc();
                    $check_stmt->close();
                    
                    $new_status = 'approved';
                    if ($totals['total_issued'] > 0 && $totals['total_issued'] < $totals['total_approved']) {
                        $new_status = 'partial';
                    } elseif ($totals['total_issued'] >= $totals['total_approved']) {
                        $new_status = 'fulfilled';
                    }
                    
                    $status_sql = "UPDATE inventory_requisitions SET 
                                  status = ?,
                                  updated_by = ?,
                                  updated_at = NOW()
                                  WHERE requisition_id = ?";
                    $status_stmt = $mysqli->prepare($status_sql);
                    $status_stmt->bind_param("sii", $new_status, $session_user_id, $requisition_id);
                    $status_stmt->execute();
                    $status_stmt->close();
                    
                    // Log action
                    log_activity($mysqli, $session_user_id, 'Requisition Items Updated', 
                                "Updated item quantities for requisition #" . $requisition['requisition_number']);
                    
                    $_SESSION['alert_type'] = "success";
                    $_SESSION['alert_message'] = "Item quantities updated successfully!";
                }
                break;
                
            case 'cancel':
                if (in_array($requisition['status'], ['pending', 'approved'])) {
                    $update_sql = "UPDATE inventory_requisitions SET 
                                   status = 'cancelled',
                                   updated_by = ?,
                                   updated_at = NOW()
                                   WHERE requisition_id = ?";
                    $update_stmt = $mysqli->prepare($update_sql);
                    $update_stmt->bind_param("ii", $session_user_id, $requisition_id);
                    $update_stmt->execute();
                    $update_stmt->close();
                    
                    // Log action
                    log_activity($mysqli, $session_user_id, 'Requisition Cancelled', 
                                "Cancelled requisition #" . $requisition['requisition_number']);
                    
                    $_SESSION['alert_type'] = "warning";
                    $_SESSION['alert_message'] = "Requisition cancelled.";
                }
                break;
        }
        
        $mysqli->commit();
        
        // Refresh data
        header("Location: inventory_requisition_view.php?id=" . $requisition_id);
        exit;
        
    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error processing action: " . $e->getMessage();
        header("Location: inventory_requisition_view.php?id=" . $requisition_id);
        exit;
    }
}

// Function to log activity
function log_activity($mysqli, $user_id, $action, $description) {
    global $session_ip, $session_user_agent;
    
    $log_sql = "INSERT INTO logs SET
                log_type = 'Inventory',
                log_action = ?,
                log_description = ?,
                log_ip = ?,
                log_user_agent = ?,
                log_user_id = ?,
                log_created_at = NOW()";
    $log_stmt = $mysqli->prepare($log_sql);
    $log_stmt->bind_param("ssssi", $action, $description, $session_ip, $session_user_agent, $user_id);
    $log_stmt->execute();
    $log_stmt->close();
}
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-clipboard-list mr-2"></i>View Requisition: <?php echo htmlspecialchars($requisition['requisition_number']); ?>
            </h3>
            <div class="card-tools">
                <a href="inventory_requisition_edit.php?id=<?php echo $requisition_id; ?>" class="btn btn-light mr-2">
                    <i class="fas fa-edit mr-2"></i>Edit
                </a>
                <a href="inventory_requisitions.php" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Requisitions
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

        <!-- Requisition Status Badge -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <span class="badge badge-<?php 
                            echo match($requisition['status']) {
                                'pending' => 'warning',
                                'approved' => 'info',
                                'rejected' => 'danger',
                                'partial' => 'primary',
                                'fulfilled' => 'success',
                                'cancelled' => 'secondary',
                                default => 'secondary'
                            };
                        ?> badge-lg p-2">
                            <i class="fas fa-<?php 
                                echo match($requisition['status']) {
                                    'pending' => 'clock',
                                    'approved' => 'check-circle',
                                    'rejected' => 'ban',
                                    'partial' => 'truck-loading',
                                    'fulfilled' => 'check-double',
                                    'cancelled' => 'times-circle',
                                    default => 'file'
                                };
                            ?> mr-1"></i>
                            <?php echo ucfirst($requisition['status']); ?>
                        </span>
                        <small class="text-muted ml-2">
                            Created: <?php echo date('M j, Y', strtotime($requisition['created_at'])); ?> 
                            by <?php echo htmlspecialchars($requisition['created_by_name'] ?? ''); ?>
                        </small>
                    </div>
                    <div class="btn-group">
                        <?php if ($requisition['status'] === 'pending' ): ?>
                            <button type="button" class="btn btn-success btn-sm" data-toggle="modal" data-target="#approveModal">
                                <i class="fas fa-check mr-1"></i>Approve
                            </button>
                            <button type="button" class="btn btn-danger btn-sm" data-toggle="modal" data-target="#rejectModal">
                                <i class="fas fa-times mr-1"></i>Reject
                            </button>
                        <?php endif; ?>
                        
                        <?php if (in_array($requisition['status'], ['approved', 'partial'])): ?>
                            <button type="button" class="btn btn-info btn-sm" data-toggle="modal" data-target="#updateItemsModal">
                                <i class="fas fa-edit mr-1"></i>Update Items
                            </button>
                            <?php if ($requisition['status'] === 'approved'): ?>
                                <button type="button" class="btn btn-primary btn-sm" data-toggle="modal" data-target="#fulfillModal">
                                    <i class="fas fa-check-double mr-1"></i>Fulfill
                                </button>
                            <?php endif; ?>
                        <?php endif; ?>
                        
                        <?php if (in_array($requisition['status'], ['pending', 'approved'])): ?>
                            <button type="button" class="btn btn-outline-danger btn-sm" data-toggle="modal" data-target="#cancelModal">
                                <i class="fas fa-ban mr-1"></i>Cancel
                            </button>
                        <?php endif; ?>
                        
                        <a href="inventory_requisition_print.php?id=<?php echo $requisition_id; ?>" class="btn btn-outline-info btn-sm" target="_blank">
                            <i class="fas fa-print mr-1"></i>Print
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-8">
                <!-- Requisition Information -->
                <div class="card card-primary">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-info-circle mr-2"></i>Requisition Information</h3>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="text-muted">Requisition Number</label>
                                    <p class="font-weight-bold"><?php echo htmlspecialchars($requisition['requisition_number']); ?></p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="text-muted">Requisition Date</label>
                                    <p class="font-weight-bold"><?php echo date('F j, Y', strtotime($requisition['requisition_date'])); ?></p>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="text-muted">From Location</label>
                                    <p class="font-weight-bold">
                                        <?php echo htmlspecialchars($requisition['from_location_name']); ?>
                                        <small class="text-muted">(<?php echo $requisition['from_location_type']; ?>)</small>
                                    </p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="text-muted">Delivery Location</label>
                                    <p class="font-weight-bold">
                                        <?php echo htmlspecialchars($requisition['delivery_location_name']); ?>
                                        <small class="text-muted">(<?php echo $requisition['delivery_location_type']; ?>)</small>
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="text-muted">Department</label>
                                    <p class="font-weight-bold"><?php echo $requisition['department_name'] ? htmlspecialchars($requisition['department_name']) : '<span class="text-muted">Not specified</span>'; ?></p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="text-muted">Priority</label>
                                    <p class="font-weight-bold">
                                        <span class="badge badge-<?php 
                                            echo match($requisition['priority']) {
                                                'urgent' => 'danger',
                                                'high' => 'warning',
                                                'normal' => 'primary',
                                                'low' => 'secondary',
                                                default => 'secondary'
                                            };
                                        ?>">
                                            <?php echo ucfirst($requisition['priority']); ?>
                                        </span>
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="text-muted">Requested By</label>
                                    <p class="font-weight-bold"><?php echo htmlspecialchars($requisition['requested_by_name']); ?></p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <?php if ($requisition['approved_by']): ?>
                                <div class="form-group">
                                    <label class="text-muted">Approved By</label>
                                    <p class="font-weight-bold"><?php echo htmlspecialchars($requisition['approved_by_name']); ?></p>
                                    <small class="text-muted"><?php echo date('F j, Y g:i A', strtotime($requisition['approved_at'])); ?></small>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($requisition['fulfilled_by']): ?>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="text-muted">Fulfilled By</label>
                                    <p class="font-weight-bold"><?php echo htmlspecialchars($requisition['fulfilled_by_name']); ?></p>
                                    <small class="text-muted"><?php echo date('F j, Y g:i A', strtotime($requisition['fulfilled_at'])); ?></small>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ($requisition['notes']): ?>
                        <div class="row">
                            <div class="col-md-12">
                                <div class="form-group">
                                    <label class="text-muted">Notes</label>
                                    <div class="border rounded p-3 bg-light">
                                        <?php echo nl2br(htmlspecialchars($requisition['notes'])); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Requisition Items -->
                <div class="card card-warning">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-list-ol mr-2"></i>Requisition Items</h3>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Item</th>
                                        <th class="text-center">Unit</th>
                                        <th class="text-center">Available Stock</th>
                                        <th class="text-center">Requested</th>
                                        <th class="text-center">Approved</th>
                                        <th class="text-center">Issued</th>
                                        <th class="text-center">Balance</th>
                                        <th>Notes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $total_requested = 0;
                                    $total_approved = 0;
                                    $total_issued = 0;
                                    
                                    foreach ($requisition_items as $item): 
                                        $balance = $item['quantity_approved'] - $item['quantity_issued'];
                                        $total_requested += $item['quantity_requested'];
                                        $total_approved += $item['quantity_approved'];
                                        $total_issued += $item['quantity_issued'];
                                    ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($item['item_name']); ?></strong><br>
                                                <small class="text-muted">
                                                    <?php echo htmlspecialchars($item['item_code']); ?> • 
                                                    <?php echo htmlspecialchars($item['category_name']); ?>
                                                    <?php if ($item['requires_batch']): ?>
                                                        <span class="badge badge-info ml-1">Batch</span>
                                                    <?php endif; ?>
                                                </small>
                                            </td>
                                            <td class="text-center"><?php echo htmlspecialchars($item['unit_of_measure']); ?></td>
                                            <td class="text-center">
                                                <span class="badge <?php echo $item['available_stock'] >= $item['quantity_requested'] ? 'badge-success' : 'badge-warning'; ?>">
                                                    <?php echo number_format($item['available_stock'], 3); ?>
                                                </span>
                                            </td>
                                            <td class="text-center">
                                                <?php echo number_format($item['quantity_requested'], 3); ?>
                                            </td>
                                            <td class="text-center">
                                                <span class="<?php echo $item['quantity_approved'] < $item['quantity_requested'] ? 'text-warning' : 'text-success'; ?>">
                                                    <?php echo number_format($item['quantity_approved'], 3); ?>
                                                </span>
                                            </td>
                                            <td class="text-center">
                                                <span class="<?php echo $item['quantity_issued'] < $item['quantity_approved'] ? 'text-warning' : 'text-success'; ?>">
                                                    <?php echo number_format($item['quantity_issued'], 3); ?>
                                                </span>
                                            </td>
                                            <td class="text-center">
                                                <span class="<?php echo $balance > 0 ? 'text-danger' : 'text-success'; ?>">
                                                    <?php echo number_format($balance, 3); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <small class="text-muted"><?php echo htmlspecialchars($item['notes'] ?: '-'); ?></small>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="bg-light">
                                    <tr>
                                        <th colspan="3" class="text-right">Totals:</th>
                                        <th class="text-center"><?php echo number_format($total_requested, 3); ?></th>
                                        <th class="text-center"><?php echo number_format($total_approved, 3); ?></th>
                                        <th class="text-center"><?php echo number_format($total_issued, 3); ?></th>
                                        <th class="text-center"><?php echo number_format($total_approved - $total_issued, 3); ?></th>
                                        <th></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Transaction History -->
                <?php if (!empty($transactions)): ?>
                <div class="card card-info">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-history mr-2"></i>Transaction History</h3>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Date</th>
                                        <th>Type</th>
                                        <th>Item</th>
                                        <th>Batch</th>
                                        <th>From</th>
                                        <th>To</th>
                                        <th class="text-right">Quantity</th>
                                        <th>By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($transactions as $transaction): ?>
                                        <tr>
                                            <td><?php echo date('M j, Y g:i A', strtotime($transaction['created_at'])); ?></td>
                                            <td>
                                                <span class="badge badge-<?php 
                                                    echo match($transaction['transaction_type']) {
                                                        'ISSUE' => 'warning',
                                                        'TRANSFER_OUT' => 'info',
                                                        'TRANSFER_IN' => 'success',
                                                        default => 'secondary'
                                                    };
                                                ?>">
                                                    <?php echo $transaction['transaction_type']; ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($transaction['item_name']); ?></td>
                                            <td><?php echo $transaction['batch_number'] ? htmlspecialchars($transaction['batch_number']) : '-'; ?></td>
                                            <td><?php echo $transaction['from_location_name'] ? htmlspecialchars($transaction['from_location_name']) : '-'; ?></td>
                                            <td><?php echo $transaction['to_location_name'] ? htmlspecialchars($transaction['to_location_name']) : '-'; ?></td>
                                            <td class="text-right"><?php echo number_format($transaction['quantity'], 3); ?></td>
                                            <td><?php echo htmlspecialchars($transaction['user_name']); ?></td>
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
                            <a href="inventory_requisition_edit.php?id=<?php echo $requisition_id; ?>" class="btn btn-primary">
                                <i class="fas fa-edit mr-2"></i>Edit Requisition
                            </a>
                            <a href="inventory_requisition_print.php?id=<?php echo $requisition_id; ?>" class="btn btn-info" target="_blank">
                                <i class="fas fa-print mr-2"></i>Print Requisition
                            </a>
                            <a href="inventory_requisition_create.php" class="btn btn-success">
                                <i class="fas fa-plus mr-2"></i>Create New
                            </a>
                            <a href="inventory_requisitions.php" class="btn btn-outline-secondary">
                                <i class="fas fa-list mr-2"></i>View All
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Requisition Summary -->
                <div class="card card-primary">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-chart-pie mr-2"></i>Requisition Summary</h3>
                    </div>
                    <div class="card-body">
                        <div class="text-center mb-3">
                            <i class="fas fa-clipboard-list fa-3x text-primary mb-2"></i>
                            <h5><?php echo htmlspecialchars($requisition['requisition_number']); ?></h5>
                        </div>
                        <hr>
                        <div class="small">
                            <div class="d-flex justify-content-between mb-2">
                                <span>Status:</span>
                                <span class="font-weight-bold">
                                    <span class="badge badge-<?php 
                                        echo match($requisition['status']) {
                                            'pending' => 'warning',
                                            'approved' => 'info',
                                            'rejected' => 'danger',
                                            'partial' => 'primary',
                                            'fulfilled' => 'success',
                                            'cancelled' => 'secondary',
                                            default => 'secondary'
                                        };
                                    ?>">
                                        <?php echo ucfirst($requisition['status']); ?>
                                    </span>
                                </span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Priority:</span>
                                <span class="font-weight-bold">
                                    <span class="badge badge-<?php 
                                        echo match($requisition['priority']) {
                                            'urgent' => 'danger',
                                            'high' => 'warning',
                                            'normal' => 'primary',
                                            'low' => 'secondary',
                                            default => 'secondary'
                                        };
                                    ?>">
                                        <?php echo ucfirst($requisition['priority']); ?>
                                    </span>
                                </span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Items:</span>
                                <span class="font-weight-bold"><?php echo count($requisition_items); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Requested Total:</span>
                                <span class="font-weight-bold"><?php echo number_format($total_requested, 3); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Approved Total:</span>
                                <span class="font-weight-bold"><?php echo number_format($total_approved, 3); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Issued Total:</span>
                                <span class="font-weight-bold"><?php echo number_format($total_issued, 3); ?></span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span>Balance:</span>
                                <span class="font-weight-bold <?php echo ($total_approved - $total_issued) > 0 ? 'text-danger' : 'text-success'; ?>">
                                    <?php echo number_format($total_approved - $total_issued, 3); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Progress -->
                <div class="card card-warning">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-tasks mr-2"></i>Progress</h3>
                    </div>
                    <div class="card-body">
                        <div class="progress mb-3" style="height: 25px;">
                            <?php 
                            $percentage = $total_requested > 0 ? ($total_issued / $total_requested) * 100 : 0;
                            $progress_class = match(true) {
                                $percentage >= 100 => 'bg-success',
                                $percentage >= 70 => 'bg-info',
                                $percentage >= 40 => 'bg-warning',
                                default => 'bg-danger'
                            };
                            ?>
                            <div class="progress-bar <?php echo $progress_class; ?>" 
                                 role="progressbar" 
                                 style="width: <?php echo min($percentage, 100); ?>%" 
                                 aria-valuenow="<?php echo $percentage; ?>" 
                                 aria-valuemin="0" 
                                 aria-valuemax="100">
                                <?php echo number_format($percentage, 1); ?>%
                            </div>
                        </div>
                        <div class="small text-center">
                            <div class="row">
                                <div class="col-4">
                                    <div class="text-muted">Requested</div>
                                    <div class="font-weight-bold"><?php echo number_format($total_requested, 3); ?></div>
                                </div>
                                <div class="col-4">
                                    <div class="text-muted">Approved</div>
                                    <div class="font-weight-bold"><?php echo number_format($total_approved, 3); ?></div>
                                </div>
                                <div class="col-4">
                                    <div class="text-muted">Issued</div>
                                    <div class="font-weight-bold"><?php echo number_format($total_issued, 3); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Location Information -->
                <div class="card card-info">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-map-marker-alt mr-2"></i>Location Information</h3>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <h6 class="font-weight-bold text-info">
                                <i class="fas fa-arrow-up mr-1"></i>Source Location
                            </h6>
                            <p class="mb-1"><?php echo htmlspecialchars($requisition['from_location_name']); ?></p>
                            <small class="text-muted"><?php echo $requisition['from_location_type']; ?></small>
                        </div>
                        <div class="mb-0">
                            <h6 class="font-weight-bold text-success">
                                <i class="fas fa-truck mr-1"></i>Delivery Location
                            </h6>
                            <p class="mb-1"><?php echo htmlspecialchars($requisition['delivery_location_name']); ?></p>
                            <small class="text-muted"><?php echo $requisition['delivery_location_type']; ?></small>
                        </div>
                    </div>
                </div>

                <!-- Timeline -->
                <div class="card card-secondary">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-history mr-2"></i>Timeline</h3>
                    </div>
                    <div class="card-body">
                        <div class="timeline">
                            <div class="timeline-item mb-3">
                                <div class="timeline-marker bg-success"></div>
                                <div class="timeline-content">
                                    <small class="text-muted">Created</small>
                                    <p class="mb-1"><?php echo date('M j, Y g:i A', strtotime($requisition['created_at'])); ?></p>
                                    <small class="text-muted">by <?php echo htmlspecialchars($requisition['created_by_name']); ?></small>
                                </div>
                            </div>
                            
                            <?php if ($requisition['approved_at']): ?>
                            <div class="timeline-item mb-3">
                                <div class="timeline-marker bg-info"></div>
                                <div class="timeline-content">
                                    <small class="text-muted">Approved</small>
                                    <p class="mb-1"><?php echo date('M j, Y g:i A', strtotime($requisition['approved_at'])); ?></p>
                                    <small class="text-muted">by <?php echo htmlspecialchars($requisition['approved_by_name']); ?></small>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($requisition['fulfilled_at']): ?>
                            <div class="timeline-item mb-3">
                                <div class="timeline-marker bg-primary"></div>
                                <div class="timeline-content">
                                    <small class="text-muted">Fulfilled</small>
                                    <p class="mb-1"><?php echo date('M j, Y g:i A', strtotime($requisition['fulfilled_at'])); ?></p>
                                    <small class="text-muted">by <?php echo htmlspecialchars($requisition['fulfilled_by_name']); ?></small>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($requisition['updated_at'] && $requisition['updated_at'] != $requisition['created_at']): ?>
                            <div class="timeline-item">
                                <div class="timeline-marker bg-secondary"></div>
                                <div class="timeline-content">
                                    <small class="text-muted">Last Updated</small>
                                    <p class="mb-1"><?php echo date('M j, Y g:i A', strtotime($requisition['updated_at'])); ?></p>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Approve Modal -->
<div class="modal fade" id="approveModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="approve">
                
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-check mr-2"></i>Approve Requisition</h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to approve this requisition?</p>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle mr-2"></i>
                        Once approved, the requisition can be fulfilled.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Approve Requisition</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="reject">
                
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-times mr-2"></i>Reject Requisition</h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to reject this requisition?</p>
                    <div class="form-group">
                        <label for="reject_reason">Rejection Reason</label>
                        <textarea class="form-control" id="reject_reason" name="reject_reason" rows="3" 
                                  placeholder="Please provide a reason for rejecting this requisition..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Reject Requisition</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Update Items Modal -->
<div class="modal fade" id="updateItemsModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="update_items">
                
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="fas fa-edit mr-2"></i>Update Item Quantities</h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p>Update approved and issued quantities for each item:</p>
                    
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead class="bg-light">
                                <tr>
                                    <th>Item</th>
                                    <th class="text-center">Available</th>
                                    <th class="text-center">Requested</th>
                                    <th class="text-center">Approved</th>
                                    <th class="text-center">Issued</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($requisition_items as $index => $item): ?>
                                    <tr>
                                        <td>
                                            <small><?php echo htmlspecialchars($item['item_name']); ?></small><br>
                                            <small class="text-muted"><?php echo htmlspecialchars($item['unit_of_measure']); ?></small>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge <?php echo $item['available_stock'] >= $item['quantity_requested'] ? 'badge-success' : 'badge-warning'; ?>">
                                                <?php echo number_format($item['available_stock'], 3); ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <?php echo number_format($item['quantity_requested'], 3); ?>
                                        </td>
                                        <td class="text-center">
                                            <input type="number" class="form-control form-control-sm text-center" 
                                                   name="quantity_approved[]" 
                                                   value="<?php echo $item['quantity_approved']; ?>"
                                                   min="0" max="<?php echo $item['quantity_requested']; ?>"
                                                   step="0.001">
                                        </td>
                                        <td class="text-center">
                                            <input type="number" class="form-control form-control-sm text-center" 
                                                   name="quantity_issued[]" 
                                                   value="<?php echo $item['quantity_issued']; ?>"
                                                   min="0" max="<?php echo $item['quantity_approved']; ?>"
                                                   step="0.001">
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-info">Update Quantities</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Fulfill Modal -->
<div class="modal fade" id="fulfillModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="fulfill">
                
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-check-double mr-2"></i>Fulfill Requisition</h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to mark this requisition as fulfilled?</p>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        This action will change the status to "fulfilled". Make sure all items have been issued.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Mark as Fulfilled</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Cancel Modal -->
<div class="modal fade" id="cancelModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="cancel">
                
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-ban mr-2"></i>Cancel Requisition</h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to cancel this requisition?</p>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        This action cannot be undone. The requisition will be marked as cancelled.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Cancel Requisition</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize form validation for update items modal
    $('#updateItemsModal form').on('submit', function(e) {
        let isValid = true;
        
        $('input[name="quantity_approved[]"]').each(function() {
            const requested = parseFloat($(this).attr('max'));
            const approved = parseFloat($(this).val());
            
            if (approved > requested) {
                isValid = false;
                $(this).addClass('is-invalid');
            } else {
                $(this).removeClass('is-invalid');
            }
        });
        
        $('input[name="quantity_issued[]"]').each(function() {
            const approved = parseFloat($(this).attr('max'));
            const issued = parseFloat($(this).val());
            
            if (issued > approved) {
                isValid = false;
                $(this).addClass('is-invalid');
            } else {
                $(this).removeClass('is-invalid');
            }
        });
        
        if (!isValid) {
            e.preventDefault();
            alert('Issued quantity cannot exceed approved quantity, and approved quantity cannot exceed requested quantity.');
        }
    });
    
    // Auto-calculate maximums for quantity inputs
    $('input[name="quantity_approved[]"]').on('input', function() {
        const requested = parseFloat($(this).attr('max'));
        const approved = parseFloat($(this).val());
        
        if (approved > requested) {
            $(this).val(requested);
        }
        
        // Update corresponding issued input max
        const index = $('input[name="quantity_approved[]"]').index(this);
        $('input[name="quantity_issued[]"]').eq(index).attr('max', $(this).val());
    });
    
    $('input[name="quantity_issued[]"]').on('input', function() {
        const approved = parseFloat($(this).attr('max'));
        const issued = parseFloat($(this).val());
        
        if (issued > approved) {
            $(this).val(approved);
        }
    });
});
</script>

<style>
.timeline {
    position: relative;
    padding-left: 30px;
}

.timeline:before {
    content: '';
    position: absolute;
    left: 15px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: #dee2e6;
}

.timeline-item {
    position: relative;
    margin-bottom: 20px;
}

.timeline-marker {
    position: absolute;
    left: -30px;
    top: 5px;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    border: 3px solid #fff;
}

.timeline-content {
    padding-left: 10px;
}

.badge-lg {
    font-size: 1rem;
    padding: 0.5rem 1rem;
}

.progress {
    border-radius: 10px;
}

.progress-bar {
    border-radius: 10px;
}

.table td, .table th {
    vertical-align: middle;
}

.card-header {
    padding: 0.5rem 1rem;
}

.card-title {
    font-size: 1.1rem;
    margin-bottom: 0;
}

.modal-header {
    padding: 0.75rem 1rem;
}

.btn-group .btn {
    margin-right: 0.25rem;
}

.btn-group .btn:last-child {
    margin-right: 0;
}
</style>

<?php 
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
<?php
// asset_checkout_view.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Check if ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid checkout ID";
    header("Location: asset_checkout.php");
    exit;
}

$checkout_id = intval($_GET['id']);

// Get checkout details
$checkout_sql = "
    SELECT c.*, a.asset_id, a.asset_tag, a.asset_name, a.serial_number, a.current_value,
           ac.category_name,
           CONCAT(e.first_name, ' ', e.last_name) as employee_name,
            
           l.location_name as destination_location,
           rl.location_name as return_location,
           uc.user_name as checkout_by_name,
           ui.user_name as checkin_by_name
    FROM asset_checkout_logs c
    JOIN assets a ON c.asset_id = a.asset_id
    LEFT JOIN asset_categories ac ON a.category_id = ac.category_id
    LEFT JOIN employees e ON c.assigned_to_id = e.employee_id
    LEFT JOIN asset_locations l ON c.destination_location_id = l.location_id
    LEFT JOIN asset_locations rl ON c.return_location_id = rl.location_id
    LEFT JOIN users uc ON c.checkout_by = uc.user_id
    LEFT JOIN users ui ON c.checkin_by = ui.user_id
    WHERE c.checkout_id = ?
";

$checkout_stmt = $mysqli->prepare($checkout_sql);
$checkout_stmt->bind_param("i", $checkout_id);
$checkout_stmt->execute();
$checkout_result = $checkout_stmt->get_result();

if ($checkout_result->num_rows === 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Checkout record not found";
    header("Location: asset_checkout.php");
    exit;
}

$checkout = $checkout_result->fetch_assoc();

// Calculate days checked out
$days_checked_out = 0;
if ($checkout['checkout_date']) {
    $checkout_timestamp = strtotime($checkout['checkout_date']);
    $checkin_timestamp = $checkout['checkin_date'] ? strtotime($checkout['checkin_date']) : time();
    $days_checked_out = floor(($checkin_timestamp - $checkout_timestamp) / (60 * 60 * 24));
}

// Check if overdue
$is_overdue = false;
if ($checkout['expected_return_date'] && !$checkout['checkin_date']) {
    $expected_return = strtotime($checkout['expected_return_date']);
    $is_overdue = time() > $expected_return;
}
?>

<div class="card">
    <div class="card-header bg-info py-2">
        <h3 class="card-title mt-2 mb-0 text-white"><i class="fas fa-fw fa-eye mr-2"></i>View Checkout Details</h3>
        <div class="card-tools">
            <a href="asset_checkout.php" class="btn btn-light">
                <i class="fas fa-arrow-left mr-2"></i>Back to Checkouts
            </a>
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

        <!-- Status Badge -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <?php if ($checkout['checkin_date']): ?>
                            <span class="badge badge-success badge-lg p-2">
                                <i class="fas fa-check-circle mr-1"></i> Checked In
                            </span>
                            <span class="ml-3 text-muted">
                                <i class="fas fa-calendar-check mr-1"></i>
                                Checked in on <?php echo date('M d, Y', strtotime($checkout['checkin_date'])); ?>
                            </span>
                        <?php else: ?>
                            <span class="badge badge-primary badge-lg p-2">
                                <i class="fas fa-sign-out-alt mr-1"></i> Checked Out
                            </span>
                            <?php if ($is_overdue): ?>
                                <span class="badge badge-danger badge-lg p-2 ml-2">
                                    <i class="fas fa-exclamation-triangle mr-1"></i> Overdue
                                </span>
                            <?php endif; ?>
                            <span class="ml-3 text-muted">
                                <i class="fas fa-clock mr-1"></i>
                                Checked out for <?php echo $days_checked_out; ?> day<?php echo $days_checked_out != 1 ? 's' : ''; ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <?php if (!$checkout['checkin_date']): ?>
                            <a href="asset_checkin.php?id=<?php echo $checkout_id; ?>" class="btn btn-success btn-sm">
                                <i class="fas fa-sign-in-alt mr-1"></i> Check In
                            </a>
                        <?php endif; ?>
                        <a href="asset_checkout_edit.php?id=<?php echo $checkout_id; ?>" class="btn btn-warning btn-sm ml-2">
                            <i class="fas fa-edit mr-1"></i> Edit
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-8">
                <!-- Asset Information -->
                <div class="card card-primary mb-4">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-cube mr-2"></i>Asset Information</h3>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <dl class="row">
                                    <dt class="col-sm-4">Asset Tag:</dt>
                                    <dd class="col-sm-8"><?php echo htmlspecialchars($checkout['asset_tag']); ?></dd>
                                    
                                    <dt class="col-sm-4">Asset Name:</dt>
                                    <dd class="col-sm-8"><?php echo htmlspecialchars($checkout['asset_name']); ?></dd>
                                    
                                    <dt class="col-sm-4">Serial Number:</dt>
                                    <dd class="col-sm-8"><?php echo htmlspecialchars($checkout['serial_number'] ?: 'N/A'); ?></dd>
                                    
                                    <dt class="col-sm-4">Category:</dt>
                                    <dd class="col-sm-8"><?php echo htmlspecialchars($checkout['category_name']); ?></dd>
                                </dl>
                            </div>
                            <div class="col-md-6">
                                <dl class="row">
                                    <dt class="col-sm-4">Current Value:</dt>
                                    <dd class="col-sm-8">$<?php echo number_format($checkout['current_value'], 2); ?></dd>
                                    
                                    <dt class="col-sm-4">Asset ID:</dt>
                                    <dd class="col-sm-8"><?php echo $checkout['asset_id']; ?></dd>
                                    
                                    <dt class="col-sm-4">Status:</dt>
                                    <dd class="col-sm-8">
                                        <?php if ($checkout['checkin_date']): ?>
                                            <span class="badge badge-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge badge-primary">Checked Out</span>
                                        <?php endif; ?>
                                    </dd>
                                </dl>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Checkout Details -->
                <div class="card card-info mb-4">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-sign-out-alt mr-2"></i>Checkout Details</h3>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <dl class="row">
                                    <dt class="col-sm-4">Checkout Type:</dt>
                                    <dd class="col-sm-8"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $checkout['checkout_type']))); ?></dd>
                                    
                                    <dt class="col-sm-4">Checkout Date:</dt>
                                    <dd class="col-sm-8"><?php echo date('M d, Y', strtotime($checkout['checkout_date'])); ?></dd>
                                    
                                    <dt class="col-sm-4">Expected Return:</dt>
                                    <dd class="col-sm-8">
                                        <?php echo $checkout['expected_return_date'] ? date('M d, Y', strtotime($checkout['expected_return_date'])) : 'Not set'; ?>
                                        <?php if ($is_overdue): ?>
                                            <span class="badge badge-danger ml-2">Overdue</span>
                                        <?php endif; ?>
                                    </dd>
                                </dl>
                            </div>
                            <div class="col-md-6">
                                <dl class="row">
                                    <dt class="col-sm-4">Destination:</dt>
                                    <dd class="col-sm-8"><?php echo htmlspecialchars($checkout['destination_location'] ?: 'Not specified'); ?></dd>
                                    
                                    <dt class="col-sm-4">Checkout By:</dt>
                                    <dd class="col-sm-8"><?php echo htmlspecialchars($checkout['checkout_by_name']); ?></dd>
                                    
                                    <dt class="col-sm-4">Checkout At:</dt>
                                    <dd class="col-sm-8"><?php echo date('M d, Y H:i', strtotime($checkout['checkout_at'])); ?></dd>
                                </dl>
                            </div>
                        </div>
                        
                        <?php if ($checkout['checkout_notes']): ?>
                            <div class="row mt-3">
                                <div class="col-md-12">
                                    <dt>Checkout Notes:</dt>
                                    <dd class="border rounded p-3 bg-light"><?php echo nl2br(htmlspecialchars($checkout['checkout_notes'])); ?></dd>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Assignment Details -->
                <div class="card card-success">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-user-check mr-2"></i>Assignment Details</h3>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <dl class="row">
                                    <dt class="col-sm-4">Assigned To:</dt>
                                    <dd class="col-sm-8"><?php echo htmlspecialchars($checkout['employee_name']); ?></dd>

                                    <dt class="col-sm-4">Department:</dt>
                                    <dd class="col-sm-8"><?php echo htmlspecialchars($checkout['employee_department'] ?? 'N/A'); ?></dd>
                                </dl>
                            </div>
                            <div class="col-md-6">
                                <dl class="row">
                                    <dt class="col-sm-4">Assigned To Type:</dt>
                                    <dd class="col-sm-8"><?php echo htmlspecialchars(ucfirst($checkout['assigned_to_type'])); ?></dd>
                                    
                                    <dt class="col-sm-4">Assigned ID:</dt>
                                    <dd class="col-sm-8"><?php echo $checkout['assigned_to_id']; ?></dd>
                                </dl>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <!-- Checkin Information -->
                <?php if ($checkout['checkin_date']): ?>
                    <div class="card card-success mb-4">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-sign-in-alt mr-2"></i>Checkin Information</h3>
                        </div>
                        <div class="card-body">
                            <dl class="row">
                                <dt class="col-sm-5">Checkin Date:</dt>
                                <dd class="col-sm-7"><?php echo date('M d, Y', strtotime($checkout['checkin_date'])); ?></dd>
                                
                                <dt class="col-sm-5">Condition:</dt>
                                <dd class="col-sm-7">
                                    <?php 
                                    $condition_labels = [
                                        'excellent' => 'Excellent',
                                        'good' => 'Good',
                                        'fair' => 'Fair',
                                        'poor' => 'Poor',
                                        'damaged' => 'Damaged'
                                    ];
                                    echo htmlspecialchars($condition_labels[$checkout['checkin_condition']] ?? ucfirst($checkout['checkin_condition']));
                                    ?>
                                </dd>
                                
                                <dt class="col-sm-5">Return Location:</dt>
                                <dd class="col-sm-7"><?php echo htmlspecialchars($checkout['return_location'] ?: 'Not specified'); ?></dd>
                                
                                <dt class="col-sm-5">Checkin By:</dt>
                                <dd class="col-sm-7"><?php echo htmlspecialchars($checkout['checkin_by_name']); ?></dd>
                                
                                <dt class="col-sm-5">Checkin At:</dt>
                                <dd class="col-sm-7"><?php echo date('M d, Y H:i', strtotime($checkout['checkin_at'])); ?></dd>
                            </dl>
                            
                            <?php if ($checkout['checkin_notes']): ?>
                                <dt>Checkin Notes:</dt>
                                <dd class="border rounded p-2 bg-light small"><?php echo nl2br(htmlspecialchars($checkout['checkin_notes'])); ?></dd>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Statistics -->
                <div class="card card-warning mb-4">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-chart-bar mr-2"></i>Statistics</h3>
                    </div>
                    <div class="card-body">
                        <div class="list-group list-group-flush">
                            <div class="list-group-item px-0 py-2">
                                <div class="d-flex w-100 justify-content-between">
                                    <h6 class="mb-1">Days Checked Out</h6>
                                    <span class="badge badge-primary badge-pill"><?php echo $days_checked_out; ?></span>
                                </div>
                                <small class="text-muted">Total duration</small>
                            </div>
                            
                            <?php if ($checkout['expected_return_date']): ?>
                                <div class="list-group-item px-0 py-2">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1">Return Status</h6>
                                        <span class="badge badge-<?php echo $checkout['checkin_date'] ? 'success' : ($is_overdue ? 'danger' : 'warning'); ?> badge-pill">
                                            <?php 
                                            if ($checkout['checkin_date']) {
                                                echo 'Returned';
                                            } else {
                                                echo $is_overdue ? 'Overdue' : 'On Time';
                                            }
                                            ?>
                                        </span>
                                    </div>
                                    <small class="text-muted">
                                        <?php if (!$checkout['checkin_date']): ?>
                                            Due: <?php echo date('M d, Y', strtotime($checkout['expected_return_date'])); ?>
                                        <?php endif; ?>
                                    </small>
                                </div>
                            <?php endif; ?>
                            
                            <div class="list-group-item px-0 py-2">
                                <div class="d-flex w-100 justify-content-between">
                                    <h6 class="mb-1">Checkout ID</h6>
                                    <span class="badge badge-secondary badge-pill">#<?php echo $checkout_id; ?></span>
                                </div>
                                <small class="text-muted">Transaction reference</small>
                            </div>
                        </div>
                    </div>
                </div>

            
            </div>
        </div>
    </div>
</div>

<style>
.badge-lg {
    font-size: 1rem;
    padding: 0.5rem 1rem;
}

dl.row dt {
    font-weight: 600;
    color: #6c757d;
}

dl.row dd {
    color: #212529;
    word-break: break-word;
}

.card {
    margin-bottom: 1rem;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>
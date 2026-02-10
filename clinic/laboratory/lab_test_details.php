<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
   require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
    
$test_id = intval($_GET['test_id']);

// Get test details
$test = $mysqli->query("
    SELECT lt.*, ltc.category_name, u.user_name as created_by_name,
           u2.user_name as updated_by_name
    FROM lab_tests lt
    LEFT JOIN lab_test_categories ltc ON lt.category_id = ltc.category_id
    LEFT JOIN users u ON lt.created_by = u.user_id
    LEFT JOIN users u2 ON lt.updated_by = u2.user_id
    WHERE lt.test_id = $test_id AND lt.is_active = 1
")->fetch_assoc();

if (!$test) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Test not found or has been deleted.";
    header("Location: lab_tests.php");
    exit;
}

// Get test usage statistics
$usage_stats = $mysqli->query("
    SELECT 
        COUNT(*) as total_orders,
        COUNT(CASE WHEN lot.status = 'completed' THEN 1 END) as completed_orders,
        COUNT(CASE WHEN lot.status = 'pending' THEN 1 END) as pending_orders,
        AVG(lt.price) as avg_price,
        MAX(lo.lab_order_created_at) as last_ordered
    FROM lab_order_tests lot
    JOIN lab_orders lo ON lot.lab_order_id = lo.lab_order_id
    JOIN lab_tests lt ON lot.test_id = lt.test_id
    WHERE lot.test_id = $test_id
    AND lo.lab_order_archived_at IS NULL
")->fetch_assoc();

// Get recent orders for this test
$recent_orders = $mysqli->query("
    SELECT lo.lab_order_id, lo.order_number, lo.lab_order_status, 
           p.first_name as patient_first_name, p.last_name as patient_last_name, p.patient_mrn,
           lot.status as test_status, lot.result_value, lot.result_unit,
           lo.lab_order_created_at
    FROM lab_order_tests lot
    JOIN lab_orders lo ON lot.lab_order_id = lo.lab_order_id
    LEFT JOIN patients p ON lo.lab_order_patient_id = p.patient_id
    WHERE lot.test_id = $test_id
      AND lo.lab_order_archived_at IS NULL
    ORDER BY lo.lab_order_created_at DESC
    LIMIT 10
");

// Get activity log for this test
$activities = $mysqli->query("
    SELECT la.*, u.user_name as performed_by_name
    FROM lab_activities la
    LEFT JOIN users u ON la.performed_by = u.user_id
    WHERE la.test_id = $test_id
    ORDER BY la.activity_date DESC
    LIMIT 20
");
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0">
            <i class="fas fa-fw fa-vial mr-2"></i>Test Details: <?php echo htmlspecialchars($test['test_name']); ?>
        </h3>
        <div class="card-tools">
            <a href="lab_tests.php" class="btn btn-light">
                <i class="fas fa-arrow-left mr-2"></i>Back to Tests
            </a>
        </div>
    </div>
    
    <!-- Statistics Row -->
    <div class="card-body border-bottom">
        <div class="row text-center">
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-primary"><i class="fas fa-clipboard-list"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Orders</span>
                        <span class="info-box-number"><?php echo $usage_stats['total_orders'] ?? 0; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-success"><i class="fas fa-check-circle"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Completed</span>
                        <span class="info-box-number"><?php echo $usage_stats['completed_orders'] ?? 0; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-warning"><i class="fas fa-clock"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Pending</span>
                        <span class="info-box-number"><?php echo $usage_stats['pending_orders'] ?? 0; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-info"><i class="fas fa-dollar-sign"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Avg Price</span>
                        <span class="info-box-number">$<?php echo number_format($usage_stats['avg_price'] ?? 0, 2); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card-body">
        <!-- Test Header Actions -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="btn-toolbar justify-content-between">
                    <div class="btn-group">
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Status:</strong> 
                            <span class="badge badge-<?php echo $test['is_active'] ? 'success' : 'danger'; ?> ml-2">
                                <?php echo $test['is_active'] ? 'Active' : 'Inactive'; ?>
                            </span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Category:</strong> 
                            <span class="badge badge-info ml-2"><?php echo htmlspecialchars($test['category_name']); ?></span>
                        </span>
                    </div>
                    <div class="btn-group">
                        <a href="lab_test_edit.php?test_id=<?php echo $test_id; ?>" class="btn btn-success">
                            <i class="fas fa-edit mr-2"></i>Edit Test
                        </a>
                        <a href="lab_test_print.php?test_id=<?php echo $test_id; ?>" class="btn btn-primary" target="_blank">
                            <i class="fas fa-print mr-2"></i>Print
                        </a>
                        <div class="dropdown">
                            <button class="btn btn-secondary dropdown-toggle" type="button" data-toggle="dropdown">
                                <i class="fas fa-cog mr-2"></i>Actions
                            </button>
                            <div class="dropdown-menu">
                                <?php if ($test['is_active']): ?>
                                    <a class="dropdown-item text-warning" href="post/lab.php?deactivate_test=<?php echo $test_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>">
                                        <i class="fas fa-pause mr-2"></i>Deactivate Test
                                    </a>
                                <?php else: ?>
                                    <a class="dropdown-item text-success" href="post/lab.php?activate_test=<?php echo $test_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>">
                                        <i class="fas fa-play mr-2"></i>Activate Test
                                    </a>
                                <?php endif; ?>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item text-danger confirm-link" href="post/lab.php?delete_test=<?php echo $test_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>">
                                    <i class="fas fa-trash mr-2"></i>Delete Test
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Test Information -->
            <div class="col-md-6">
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-info-circle mr-2"></i>Test Information</h4>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <th width="40%" class="text-muted">Test Code:</th>
                                    <td><strong class="text-primary"><?php echo htmlspecialchars($test['test_code']); ?></strong></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Test Name:</th>
                                    <td><strong><?php echo htmlspecialchars($test['test_name']); ?></strong></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Category:</th>
                                    <td><?php echo htmlspecialchars($test['category_name']); ?></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Description:</th>
                                    <td><?php echo htmlspecialchars($test['test_description'] ?: 'No description provided'); ?></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Price:</th>
                                    <td><strong class="text-success">$<?php echo number_format($test['price'], 2); ?></strong></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Turnaround Time:</th>
                                    <td><span class="badge badge-secondary"><?php echo $test['turnaround_time']; ?> hours</span></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Test Specifications -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-flask mr-2"></i>Test Specifications</h4>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <th width="40%" class="text-muted">Specimen Type:</th>
                                    <td><strong><?php echo htmlspecialchars($test['specimen_type'] ?: 'Not specified'); ?></strong></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Reference Range:</th>
                                    <td><?php echo htmlspecialchars($test['reference_range'] ?: 'Not specified'); ?></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Instructions:</th>
                                    <td><?php echo htmlspecialchars($test['instructions'] ?: 'No special instructions'); ?></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Methodology:</th>
                                    <td><?php echo htmlspecialchars($test['method'] ?: 'Standard method'); ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Test Metadata & Quick Actions -->
            <div class="col-md-6">
                <!-- Test Metadata -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-database mr-2"></i>Test Metadata</h4>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <th width="40%" class="text-muted">Created By:</th>
                                    <td><?php echo htmlspecialchars($test['created_by_name'] ?? 'NA'); ?></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Created Date:</th>
                                    <td><?php echo date('M j, Y H:i', strtotime($test['created_at'])); ?></td>
                                </tr>
                                <?php if ($test['updated_by']): ?>
                                <tr>
                                    <th class="text-muted">Last Updated By:</th>
                                    <td><?php echo htmlspecialchars($test['updated_by_name']); ?></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Last Updated:</th>
                                    <td><?php echo date('M j, Y H:i', strtotime($test['updated_at'])); ?></td>
                                </tr>
                                <?php endif; ?>
                                <?php if ($usage_stats['last_ordered']): ?>
                                <tr>
                                    <th class="text-muted">Last Ordered:</th>
                                    <td><?php echo date('M j, Y H:i', strtotime($usage_stats['last_ordered'])); ?></td>
                                </tr>
                                <?php endif; ?>
                            </table>
                        </div>
                    </div>
                </div>

         
                <!-- Test Status -->
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-chart-bar mr-2"></i>Test Status</h4>
                    </div>
                    <div class="card-body">
                        <div class="text-center">
                            <div class="mb-3">
                                <div class="h4 <?php echo $test['is_active'] ? 'text-success' : 'text-danger'; ?>">
                                    <i class="fas fa-<?php echo $test['is_active'] ? 'check-circle' : 'pause-circle'; ?> mr-2"></i>
                                    <?php echo $test['is_active'] ? 'Active' : 'Inactive'; ?>
                                </div>
                                <small class="text-muted">
                                    <?php echo $test['is_active'] ? 'This test is available for ordering' : 'This test is not available for ordering'; ?>
                                </small>
                            </div>
                            
                            <?php if ($usage_stats['total_orders'] > 0): ?>
                            <div class="progress mb-2" style="height: 20px;">
                                <div class="progress-bar bg-success" style="width: <?php echo ($usage_stats['completed_orders'] / $usage_stats['total_orders']) * 100; ?>%">
                                    <?php echo $usage_stats['completed_orders']; ?> Completed
                                </div>
                                <div class="progress-bar bg-warning" style="width: <?php echo ($usage_stats['pending_orders'] / $usage_stats['total_orders']) * 100; ?>%">
                                    <?php echo $usage_stats['pending_orders']; ?> Pending
                                </div>
                            </div>
                            <small class="text-muted">
                                Completion Rate: <?php echo round(($usage_stats['completed_orders'] / $usage_stats['total_orders']) * 100, 1); ?>%
                            </small>
                            <?php else: ?>
                            <div class="alert alert-info mb-0">
                                <i class="fas fa-info-circle mr-2"></i>
                                This test has not been ordered yet.
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Orders -->
        <div class="card mb-4">
            <div class="card-header bg-light py-2">
                <h4 class="card-title mb-0"><i class="fas fa-history mr-2"></i>Recent Orders</h4>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th>Order #</th>
                                <th>Patient</th>
                                <th>Order Date</th>
                                <th>Test Status</th>
                                <th>Result</th>
                                <th>Order Status</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($recent_orders->num_rows > 0): ?>
                                <?php while($order = $recent_orders->fetch_assoc()): ?>
                                    <tr>
                                        <td class="font-weight-bold text-primary"><?php echo htmlspecialchars($order['order_number']); ?></td>
                                        <td>
                                            <div class="font-weight-bold"><?php echo htmlspecialchars($order['patient_first_name'] . ' ' . $order['patient_last_name']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($order['patient_mrn']); ?></small>
                                        </td>
                                        <td>
                                            <div class="font-weight-bold"><?php echo date('M j, Y', strtotime($order['lab_order_created_at'])); ?></div>
                                            <small class="text-muted"><?php echo date('H:i', strtotime($order['lab_order_created_at'])); ?></small>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?php 
                                                echo $order['test_status'] == 'completed' ? 'success' : 
                                                     ($order['test_status'] == 'in_progress' ? 'warning' : 
                                                     ($order['test_status'] == 'collected' ? 'info' : 'secondary')); 
                                            ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $order['test_status'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($order['result_value']): ?>
                                                <span class="font-weight-bold"><?php echo htmlspecialchars($order['result_value']); ?></span>
                                                <?php if ($order['result_unit']): ?>
                                                    <small class="text-muted"><?php echo htmlspecialchars($order['result_unit']); ?></small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?php 
                                                echo $order['lab_order_status'] == 'completed' ? 'success' : 
                                                     ($order['lab_order_status'] == 'in_progress' ? 'warning' : 
                                                     ($order['lab_order_status'] == 'collected' ? 'info' : 'secondary')); 
                                            ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $order['lab_order_status'])); ?>
                                            </span>
                                        </td>
                                        
                                    <td class="text-center">
  <a href="lab_order_details.php?id=<?php  echo ($order['lab_order_id']); ?>" class="btn btn-sm btn-info" title="View Order">
    <i class="fas fa-eye"></i>
  </a>
</td>

                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4">
                                        <i class="fas fa-clipboard-list fa-2x text-muted mb-3"></i>
                                        <h5 class="text-muted">No Orders Found</h5>
                                        <p class="text-muted">This test has not been ordered yet.</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php if ($recent_orders->num_rows > 0): ?>
            <div class="card-footer">
                <a href="lab_orders.php?test=<?php echo $test_id; ?>" class="btn btn-outline-primary btn-sm">
                    <i class="fas fa-list mr-2"></i>View All Orders
                </a>
            </div>
            <?php endif; ?>
        </div>

        <!-- Activity Log -->
        <div class="card">
            <div class="card-header bg-light py-2">
                <h4 class="card-title mb-0"><i class="fas fa-stream mr-2"></i>Activity Log</h4>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th>Date & Time</th>
                                <th>Activity Type</th>
                                <th>Description</th>
                                <th>Performed By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($activities->num_rows > 0): ?>
                                <?php while($activity = $activities->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <div class="font-weight-bold"><?php echo date('M j, Y', strtotime($activity['activity_date'])); ?></div>
                                            <small class="text-muted"><?php echo date('H:i', strtotime($activity['activity_date'])); ?></small>
                                        </td>
                                        <td>
                                            <span class="badge badge-info"><?php echo ucfirst(str_replace('_', ' ', $activity['activity_type'])); ?></span>
                                        </td>
                                        <td><?php echo htmlspecialchars($activity['activity_description']); ?></td>
                                        <td><?php echo htmlspecialchars($activity['performed_by_name']); ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="text-center py-4">
                                        <i class="fas fa-stream fa-2x text-muted mb-3"></i>
                                        <h5 class="text-muted">No Activity Found</h5>
                                        <p class="text-muted">No activities recorded for this test yet.</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Confirm before deleting test
    $('.confirm-link').click(function(e) {
        if (!confirm('Are you sure you want to delete this test? This action cannot be undone.')) {
            e.preventDefault();
        }
    });

    // Tooltip initialization
    $('[title]').tooltip();
});

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Escape to go back
    if (e.keyCode === 27) {
        window.location.href = 'lab_tests.php';
    }
    // Ctrl + E to edit
    if (e.ctrlKey && e.keyCode === 69) {
        e.preventDefault();
        window.location.href = 'lab_test_edit.php?test_id=<?php echo $test_id; ?>';
    }
    // Ctrl + P to print
    if (e.ctrlKey && e.keyCode === 80) {
        e.preventDefault();
        window.open('lab_test_print.php?test_id=<?php echo $test_id; ?>', '_blank');
    }
});
</script>

<?php 
 require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; 

?>
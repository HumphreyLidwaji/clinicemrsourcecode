<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
   require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
    
$order_id = intval($_GET['id']);
$order = $mysqli->query("
    SELECT lo.*, 
           p.first_name as patient_first_name, 
           p.last_name as patient_last_name, 
           p.patient_mrn, 
           p.sex as patient_gender, 
           p.date_of_birth as patient_dob,
           u.user_name as doctor_name, 
           u2.user_name as nurse_name
    FROM lab_orders lo 
    LEFT JOIN patients p ON lo.lab_order_patient_id = p.patient_id 
    LEFT JOIN users u ON lo.ordering_doctor_id = u.user_id
    LEFT JOIN users u2 ON lo.ordering_nurse_id = u2.user_id
    WHERE lo.lab_order_id = $order_id
")->fetch_assoc();

$order_tests = $mysqli->query("
    SELECT lot.*, lt.test_name, lt.test_code, lt.price, lt.reference_range
    FROM lab_order_tests lot
    JOIN lab_tests lt ON lot.test_id = lt.test_id
    WHERE lot.lab_order_id = $order_id
");

$samples = $mysqli->query("
    SELECT ls.*, lt.test_name, u.user_name as collected_by_name
    FROM lab_samples ls
    JOIN lab_tests lt ON ls.test_id = lt.test_id
    LEFT JOIN users u ON ls.collected_by = u.user_id
    WHERE ls.lab_order_id = $order_id
");


// Get statistics for this order
$total_tests = $order_tests->num_rows;
$completed_tests = 0;
$pending_tests = 0;
$collected_tests = 0;

$order_tests->data_seek(0);
while($test = $order_tests->fetch_assoc()) {
    switch($test['status']) {
        case 'completed':
            $completed_tests++;
            break;
        case 'pending':
            $pending_tests++;
            break;
        case 'collected':
            $collected_tests++;
            break;
    }
}
$order_tests->data_seek(0);
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0">
            <i class="fas fa-fw fa-clipboard-list mr-2"></i>
            Order Details: <?php echo $order['order_number']; ?>
        </h3>
        <div class="card-tools">
            <a href="lab_orders.php" class="btn btn-light">
                <i class="fas fa-arrow-left mr-2"></i>Back to Orders
            </a>
        </div>
    </div>
    


    <div class="card-body">
        <!-- Order Header Actions -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="btn-toolbar justify-content-between">
                    <div class="btn-group">
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Status:</strong> 
                            <span class="badge badge-<?php 
                                echo $order['lab_order_status'] == 'completed' ? 'success' : 
                                     ($order['lab_order_status'] == 'in_progress' ? 'warning' : 
                                     ($order['lab_order_status'] == 'collected' ? 'info' : 'secondary')); 
                            ?> ml-2">
                                <?php echo ucfirst(str_replace('_', ' ', $order['lab_order_status'])); ?>
                            </span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Priority:</strong> 
                            <span class="badge badge-<?php 
                                echo $order['order_priority'] == 'stat' ? 'danger' : 
                                     ($order['order_priority'] == 'urgent' ? 'warning' : 'success'); 
                            ?> ml-2">
                                <?php echo ucfirst($order['order_priority']); ?>
                            </span>
                        </span>
                    </div>
                    <div class="btn-group">
                        <?php if ($order['lab_order_status'] != 'completed'): ?>
                            <a href="post.php?update_order_status=<?php echo $order_id; ?>&status=completed" class="btn btn-success">
                                <i class="fas fa-check mr-2"></i>Mark Complete
                            </a>
                        <?php endif; ?>
                        <a href="lab_generate_report.php?order_id=<?php echo $order_id; ?>" class="btn btn-primary">
                            <i class="fas fa-file-pdf mr-2"></i>Generate Report
                        </a>
                        <a href="lab_order_print.php?id=<?php echo $order_id; ?>" class="btn btn-secondary" target="_blank">
                            <i class="fas fa-print mr-2"></i>Print
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Patient Information -->
            <div class="col-md-4">
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-user mr-2"></i>Patient Information</h4>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <th width="40%" class="text-muted">Patient Name:</th>
                                    <td><strong><?php echo htmlspecialchars($order['patient_first_name'] . ' ' . $order['patient_last_name']); ?></strong></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">MRN:</th>
                                    <td><?php echo htmlspecialchars($order['patient_mrn']); ?></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Gender:</th>
                                    <td>
                                        <?php 
                                        $gender_display = "";
                                        switch($order['patient_gender']) {
                                            case 'M':
                                                $gender_display = 'Male';
                                                break;
                                            case 'F':
                                                $gender_display = 'Female';
                                                break;
                                            case 'I':
                                                $gender_display = 'Intersex';
                                                break;
                                            default:
                                                $gender_display = $order['patient_gender'] ?? 'NA';
                                        }
                                        echo htmlspecialchars($gender_display);
                                        ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Date of Birth:</th>
                                    <td><?php echo !empty($order['patient_dob']) ? date('M j, Y', strtotime($order['patient_dob'])) : 'NA'; ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Order Information -->
            <div class="col-md-4">
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-info-circle mr-2"></i>Order Information</h4>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <th width="40%" class="text-muted">Order Number:</th>
                                    <td><strong><?php echo $order['order_number']; ?></strong></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Ordering Doctor:</th>
                                    <td><?php echo htmlspecialchars($order['doctor_name'] ?? 'NA'); ?></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Order Type:</th>
                                    <td>
                                        <span class="badge badge-info">
                                            <?php echo ucfirst($order['lab_order_type']); ?>
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Order Date:</th>
                                    <td><?php echo date('M j, Y H:i', strtotime($order['lab_order_created_at'])); ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Clinical Notes -->
            <div class="col-md-4">
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-sticky-note mr-2"></i>Clinical Notes</h4>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($order['clinical_notes'])): ?>
                            <p class="mb-0"><?php echo nl2br(htmlspecialchars($order['clinical_notes'])); ?></p>
                        <?php else: ?>
                            <p class="text-muted mb-0">No clinical notes provided.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Order Tests -->
        <div class="card">
            <div class="card-header bg-light py-2">
                <h4 class="card-title mb-0"><i class="fas fa-vial mr-2"></i>Ordered Tests</h4>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th>Test Code</th>
                                <th>Test Name</th>
                                <th>Status</th>
                                <th>Result</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($test = $order_tests->fetch_assoc()): 
                                $test_id = intval($test['lab_order_test_id']);
                                $test_code = nullable_htmlentities($test['test_code']);
                                $test_name = nullable_htmlentities($test['test_name']);
                                $status = nullable_htmlentities($test['status']);
                                $result_value = nullable_htmlentities($test['result_value']);
                                $result_unit = nullable_htmlentities($test['result_unit']);
                                $abnormal_flag = nullable_htmlentities($test['abnormal_flag']);
                                ?>
                                <tr>
                                    <td class="font-weight-bold text-primary"><?php echo $test_code; ?></td>
                                    <td>
                                        <div class="font-weight-bold"><?php echo $test_name; ?></div>
                                        <?php if ($test['reference_range']): ?>
                                            <small class="text-muted">Ref: <?php echo $test['reference_range']; ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?php 
                                            echo $status == 'completed' ? 'success' : 
                                                 ($status == 'in_progress' ? 'warning' : 
                                                 ($status == 'collected' ? 'info' : 'secondary')); 
                                        ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $status)); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($result_value): ?>
                                            <span class="<?php 
                                                echo $abnormal_flag == 'normal' ? 'text-success' : 
                                                     ($abnormal_flag == 'critical' ? 'text-danger' : 'text-warning'); 
                                            ?>">
                                                <strong><?php echo $result_value; ?></strong>
                                                <?php if ($result_unit): ?>
                                                    <?php echo $result_unit; ?>
                                                <?php endif; ?>
                                                <?php if ($abnormal_flag != 'normal'): ?>
                                                    <i class="fas fa-exclamation-triangle ml-1"></i>
                                                <?php endif; ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <div class="dropdown dropleft">
                                            <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                                <i class="fas fa-ellipsis-h"></i>
                                            </button>
                                            <div class="dropdown-menu">
                                                <?php if ($status == 'pending'): ?>
                                                <a class="dropdown-item" href="collect_sample.php?order_test_id=<?php echo $test_id; ?>">
                                                    <i class="fas fa-fw fa-syringe mr-2"></i>Collect Sample
                                                </a>
                                                <?php elseif ($status == 'collected' && !$result_value): ?>
                                                   <!-- For entering results -->
                                                <a class="dropdown-item" href="enter_result.php?order_test_id=<?php echo $test_id; ?>">
                                                    <i class="fas fa-fw fa-edit mr-2"></i>Enter Result
                                                </a>

                                       <?php elseif ($result_value && !$test['verified_by']): ?>
                                                    <!-- For verifying results -->
                                                <a class="dropdown-item" href="verify_result.php?order_test_id=<?php echo $test_id; ?>">
                                                    <i class="fas fa-fw fa-check mr-2"></i>Verify Result
                                                </a>
         
                                                <?php endif; ?>
                                                <a class="dropdown-item" href="lab_test_details.php?test_id=<?php echo $test['test_id']; ?>">
                                                    <i class="fas fa-fw fa-eye mr-2"></i>View Test Details
                                                </a>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Sample Information -->
        <?php if ($samples->num_rows > 0): ?>
            <div class="card mt-4">
                <div class="card-header bg-light py-2">
                    <h4 class="card-title mb-0"><i class="fas fa-syringe mr-2"></i>Sample Information</h4>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th>Sample #</th>
                                    <th>Test</th>
                                    <th>Specimen</th>
                                    <th>Collection Date</th>
                                    <th>Collected By</th>
                                    <th>Condition</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($sample = $samples->fetch_assoc()): ?>
                                    <tr>
                                        <td class="font-weight-bold"><?php echo $sample['sample_number']; ?></td>
                                        <td><?php echo htmlspecialchars($sample['test_name'] ?? 'Not Found'); ?></td>
                                        <td><?php echo htmlspecialchars($sample['specimen_type'] ?? 'Not Found'); ?></td>
                                        <td>
                                            <div class="font-weight-bold"><?php echo date('M j, Y', strtotime($sample['collection_date'])); ?></div>
                                            <small class="text-muted"><?php echo date('H:i', strtotime($sample['collection_date'])); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($sample['collected_by_name'] ?? 'None'); ?></td>
                                        <td>
                                            <span class="badge badge-<?php 
                                                echo $sample['sample_condition'] == 'good' ? 'success' : 'warning'; 
                                            ?>">
                                                <?php echo ucfirst($sample['sample_condition'] ?? 'No Condition'); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>


<script>
$(document).ready(function() {
    // Initialize tooltips if needed
    $('[data-toggle="tooltip"]').tooltip();
});

function collectSample(orderTestId) {
    $('#collectSampleModal').modal('show');
    // Load order test details
}

function enterResult(orderTestId) {
    $('#enterResultModal').modal('show');
    // Load test details
}

function verifyResult(orderTestId) {
    $('#verifyResultModal').modal('show');
    // Load result details
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Escape to go back
    if (e.keyCode === 27) {
        window.location.href = 'lab_orders.php';
    }
    // Ctrl + P to print
    if (e.ctrlKey && e.keyCode === 80) {
        e.preventDefault();
        window.open('lab_order_print.php?id=<?php echo $order_id; ?>', '_blank');
    }
});
</script>

<?php 
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
    ?>
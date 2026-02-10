<?php
   require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
    
// Get results pending verification
$pending_verification = $mysqli->query("
    SELECT lot.*, lt.test_name, lt.reference_range,
           lo.order_number, lo.order_priority,
           p.patient_first_name, p.patient_last_name,
           u1.user_name as performed_by_name,
           ls.sample_number
    FROM lab_order_tests lot
    JOIN lab_tests lt ON lot.test_id = lt.test_id
    JOIN lab_orders lo ON lot.order_id = lo.order_id
    LEFT JOIN patients p ON lo.patient_id = p.patient_id
    LEFT JOIN users u1 ON lot.performed_by = u1.user_id
    LEFT JOIN lab_samples ls ON lot.order_test_id = ls.order_test_id
    WHERE lot.result_value IS NOT NULL AND lot.verified_by IS NULL
    ORDER BY 
        CASE WHEN lot.abnormal_flag = 'critical' THEN 1
             WHEN lot.abnormal_flag IN ('high', 'low') THEN 2
             ELSE 3 END,
        lo.order_priority DESC,
        lot.updated_at ASC
");

// Get recently verified results
$recently_verified = $mysqli->query("
    SELECT lot.*, lt.test_name, lo.order_number,
           p.patient_first_name, p.patient_last_name,
           u1.user_name as performed_by_name,
           u2.user_name as verified_by_name
    FROM lab_order_tests lot
    JOIN lab_tests lt ON lot.test_id = lt.test_id
    JOIN lab_orders lo ON lot.order_id = lo.order_id
    LEFT JOIN patients p ON lo.patient_id = p.patient_id
    LEFT JOIN users u1 ON lot.performed_by = u1.user_id
    LEFT JOIN users u2 ON lot.verified_by = u2.user_id
    WHERE lot.verified_by IS NOT NULL
    AND lot.verified_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ORDER BY lot.verified_at DESC
    LIMIT 20
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Verify Results - <?php echo $session_company_name; ?></title>
    <?php require_once "includes/head.php"; ?>
    <style>
        .critical-result { 
            background: linear-gradient(45deg, #ffcccc 25%, #ffffff 25%, #ffffff 50%, #ffcccc 50%, #ffcccc 75%, #ffffff 75%);
            background-size: 20px 20px;
            animation: criticalAlert 1s infinite linear;
        }
        @keyframes criticalAlert {
            0% { background-position: 0 0; }
            100% { background-position: 20px 20px; }
        }
        .abnormal-result { background-color: #fff3cd !important; }
    </style>
</head>
<body class="hold-transition sidebar-mini">
    <div class="wrapper">
        <?php require_once "includes/navbar.php"; ?>
        <?php require_once "includes/sidebar.php"; ?>

        <div class="content-wrapper">
            <section class="content">
                <div class="container-fluid">
                    <div class="row mb-3">
                        <div class="col-12">
                            <h1><i class="fas fa-check-circle mr-2"></i>Verify Test Results</h1>
                        </div>
                    </div>

                    <!-- Results Pending Verification -->
                    <div class="card">
                        <div class="card-header bg-warning">
                            <h3 class="card-title">Results Pending Verification</h3>
                            <div class="card-tools">
                                <span class="badge badge-light"><?php echo $pending_verification->num_rows; ?> results</span>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <?php if ($pending_verification->num_rows > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover">
                                        <thead>
                                            <tr>
                                                <th>Priority</th>
                                                <th>Order #</th>
                                                <th>Patient</th>
                                                <th>Test</th>
                                                <th>Result</th>
                                                <th>Reference Range</th>
                                                <th>Flag</th>
                                                <th>Performed By</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php while($result = $pending_verification->fetch_assoc()): ?>
                                                <tr class="<?php 
                                                    echo $result['abnormal_flag'] == 'critical' ? 'critical-result' : 
                                                         ($result['abnormal_flag'] != 'normal' ? 'abnormal-result' : ''); 
                                                ?>">
                                                    <td>
                                                        <span class="badge badge-<?php 
                                                            echo $result['order_priority'] == 'stat' ? 'danger' : 
                                                                 ($result['order_priority'] == 'urgent' ? 'warning' : 'success'); 
                                                        ?>">
                                                            <?php echo strtoupper($result['order_priority']); ?>
                                                        </span>
                                                    </td>
                                                    <td><strong><?php echo $result['order_number']; ?></strong></td>
                                                    <td><?php echo htmlspecialchars($result['patient_first_name'] . ' ' . $result['patient_last_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($result['test_name']); ?></td>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($result['result_value']); ?></strong>
                                                        <?php if ($result['result_unit']): ?>
                                                            <?php echo $result['result_unit']; ?>
                                                        <?php endif; ?>
                                                        <?php if ($result['result_notes']): ?>
                                                            <br><small class="text-muted"><?php echo htmlspecialchars($result['result_notes']); ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="reference-range">
                                                        <?php if ($result['reference_range']): ?>
                                                            <?php echo htmlspecialchars($result['reference_range']); ?>
                                                        <?php else: ?>
                                                            <em class="text-muted">Not specified</em>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($result['abnormal_flag'] != 'normal'): ?>
                                                            <span class="badge badge-<?php 
                                                                echo $result['abnormal_flag'] == 'critical' ? 'danger' : 'warning'; 
                                                            ?>">
                                                                <?php echo ucfirst($result['abnormal_flag']); ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="badge badge-success">Normal</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($result['performed_by_name']); ?></td>
                                                    <td>
                                                        <div class="btn-group">
                                                            <button class="btn btn-sm btn-success" onclick="verifyResult(<?php echo $result['order_test_id']; ?>, 'approved')">
                                                                <i class="fas fa-check"></i> Verify
                                                            </button>
                                                            <button class="btn btn-sm btn-warning" onclick="verifyResult(<?php echo $result['order_test_id']; ?>, 'review')">
                                                                <i class="fas fa-redo"></i> Review
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="text-center p-4">
                                    <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                                    <h4 class="text-success">All Results Verified!</h4>
                                    <p class="text-muted">No results pending verification.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Recently Verified -->
                    <div class="card mt-4">
                        <div class="card-header bg-success text-white">
                            <h3 class="card-title">Recently Verified Results</h3>
                        </div>
                        <div class="card-body p-0">
                            <?php if ($recently_verified->num_rows > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>Order #</th>
                                                <th>Patient</th>
                                                <th>Test</th>
                                                <th>Result</th>
                                                <th>Verified By</th>
                                                <th>Time</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php while($result = $recently_verified->fetch_assoc()): ?>
                                                <tr>
                                                    <td><?php echo $result['order_number']; ?></td>
                                                    <td><?php echo htmlspecialchars($result['patient_first_name'] . ' ' . $result['patient_last_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($result['test_name']); ?></td>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($result['result_value']); ?></strong>
                                                        <?php if ($result['result_unit']): ?>
                                                            <?php echo $result['result_unit']; ?>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($result['verified_by_name']); ?></td>
                                                    <td><?php echo date('H:i', strtotime($result['verified_at'])); ?></td>
                                                </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="text-center p-4">
                                    <p class="text-muted">No results verified in the last 24 hours.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>

    <?php require_once "modals/lab/verify_result_modal.php"; ?>

    <script>
    function verifyResult(orderTestId, action) {
        if (action === 'approved') {
            if (confirm('Are you sure you want to verify this result?')) {
                // Direct verification
                $.post('post.php', {
                    verify_result: orderTestId,
                    action: 'approve',
                    csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'
                }, function(response) {
                    location.reload();
                });
            }
        } else {
            $('#verifyResultModal').modal('show');
            // Load result details for review
            $.get('ajax/get_order_test.php?id=' + orderTestId, function(data) {
                const result = JSON.parse(data);
                $('#verifyResultModal').find('#reviewOrderTestId').val(orderTestId);
                $('#verifyResultModal').find('#currentResult').val(result.result_value);
                // Populate other fields...
            });
        }
    }

    // Auto-fill current time for verification
    $(document).ready(function() {
        const now = new Date();
        $('#verificationTime').val(now.toTimeString().substring(0, 5));
    });
    </script>
<?php 
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
    ?>
    
</body>
</html>
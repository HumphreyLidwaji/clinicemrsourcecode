<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Get tests ready for result entry (collected but no results)
$tests_for_results = $mysqli->query("
    SELECT 
        lot.*, 
        lt.test_name, 
        lt.test_code, 
        lt.reference_range, 
        lt.result_unit, 
        lo.lab_order_id AS order_id, 
        p.patient_first_name,
         p.patient_last_name, 
        p.patient_dob, 
        p.patient_gender, 
        ls.sample_code, 
        ls.collection_date
    FROM lab_order_tests lot
    JOIN lab_tests lt ON lot.test_id = lt.test_id
    JOIN lab_orders lo ON lot.lab_order_id = lo.lab_order_id
    LEFT JOIN patients p ON lo.lab_order_patient_id = p.patient_id
    LEFT JOIN lab_samples ls ON lot.lab_order_test_id = ls.test_id
    WHERE lot.is_active = 1 AND lot.result_value IS NULL
    ORDER BY lo.lab_order_created_at DESC, ls.collection_date ASC
");


// Get recently entered results (last 24 hours)
$recent_results = $mysqli->query("
    SELECT lot.*, lt.test_name, lo.lab_order_id AS order_id,
           p.patient_last_name, p.patient_first_name
    FROM lab_order_tests lot
    JOIN lab_tests lt ON lot.test_id = lt.test_id
    JOIN lab_orders lo ON lot.lab_order_id = lo.lab_order_id
    LEFT JOIN patients p ON lo.lab_order_patient_id = p.patient_id
    WHERE lot.result_value IS NOT NULL
      AND lot.updated_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ORDER BY lot.updated_at DESC
    LIMIT 20
");

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Enter Results - <?php echo $session_company_name; ?></title>
  
    <style>
        .result-row { transition: all 0.3s; }
        .result-row:hover { background-color: #f8f9fa !important; }
        .reference-range { font-size: 0.85em; color: #6c757d; }
        .abnormal-high { background-color: #ffe6e6 !important; }
        .abnormal-low { background-color: #e6f3ff !important; }
        .critical { background-color: #ffcccc !important; font-weight: bold; }
    </style>
</head>
<body class="hold-transition sidebar-mini">
    <div class="wrapper">
   
        <div class="content-wrapper">
            <section class="content">
                <div class="container-fluid">
                    <div class="row mb-3">
                        <div class="col-12">
                            <h1><i class="fas fa-edit mr-2"></i>Enter Test Results</h1>
                        </div>
                    </div>

                    <!-- Tests Ready for Results -->
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <h3 class="card-title">Tests Ready for Results</h3>
                            <div class="card-tools">
                                <span class="badge badge-light"><?php echo $tests_for_results->num_rows; ?> tests</span>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <?php if ($tests_for_results->num_rows > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover">
                                        <thead>
                                            <tr>
                                                <th>Priority</th>
                                                <th>Order #</th>
                                                <th>Patient</th>
                                                <th>Test</th>
                                                <th>Sample #</th>
                                                <th>Reference Range</th>
                                                <th>Collection Time</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php while($test = $tests_for_results->fetch_assoc()): ?>
                                                <tr class="result-row priority-<?php echo $test['order_priority']; ?>">
                                                    <td>
                                                        <span class="badge badge-<?php 
                                                            echo $test['order_priority'] == 'stat' ? 'danger' : 
                                                                 ($test['order_priority'] == 'urgent' ? 'warning' : 'success'); 
                                                        ?>">
                                                            <?php echo strtoupper($test['order_priority']); ?>
                                                        </span>
                                                    </td>
                                                    <td><strong><?php echo $test['order_number']; ?></strong></td>
                                                    <td>
                                                        <?php echo htmlspecialchars($test['patient_first_name'] . ' ' . $test['patient_last_name']); ?>
                                                        <br>
                                                        <small class="text-muted">
                                                            <?php 
                                                            echo $test['patient_gender'] . ', ' . 
                                                                 date_diff(date_create($test['patient_date_of_birth']), date_create('today'))->y . 'y';
                                                            ?>
                                                        </small>
                                                    </td>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($test['test_name']); ?></strong>
                                                        <br><small class="text-muted"><?php echo $test['test_code']; ?></small>
                                                    </td>
                                                    <td><?php echo $test['sample_number']; ?></td>
                                                    <td class="reference-range">
                                                        <?php if ($test['reference_range']): ?>
                                                            <?php echo htmlspecialchars($test['reference_range']); ?>
                                                        <?php else: ?>
                                                            <em class="text-muted">Not specified</em>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($test['collection_date']): ?>
                                                            <?php echo date('H:i', strtotime($test['collection_date'])); ?>
                                                        <?php else: ?>
                                                            <em class="text-muted">Not recorded</em>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <button class="btn btn-sm btn-success" onclick="enterResult(<?php echo $test['order_test_id']; ?>)">
                                                            <i class="fas fa-edit mr-1"></i> Enter Result
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="text-center p-4">
                                    <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                                    <h4 class="text-success">All Results Entered!</h4>
                                    <p class="text-muted">No tests awaiting result entry.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Recent Results -->
                    <div class="card mt-4">
                        <div class="card-header bg-info">
                            <h3 class="card-title">Recently Entered Results (Last 24 Hours)</h3>
                        </div>
                        <div class="card-body p-0">
                            <?php if ($recent_results->num_rows > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>Order #</th>
                                                <th>Patient</th>
                                                <th>Test</th>
                                                <th>Result</th>
                                                <th>Flag</th>
                                                <th>Entered By</th>
                                                <th>Time</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php while($result = $recent_results->fetch_assoc()): ?>
                                                <tr class="<?php 
                                                    echo $result['abnormal_flag'] == 'critical' ? 'critical' : 
                                                         ($result['abnormal_flag'] == 'high' ? 'abnormal-high' : 
                                                         ($result['abnormal_flag'] == 'low' ? 'abnormal-low' : '')); 
                                                ?>">
                                                    <td><?php echo $result['order_number']; ?></td>
                                                    <td><?php echo htmlspecialchars($result['patient_first_name'] . ' ' . $result['patient_last_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($result['test_name']); ?></td>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($result['result_value']); ?></strong>
                                                        <?php if ($result['result_unit']): ?>
                                                            <?php echo $result['result_unit']; ?>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($result['abnormal_flag'] != 'normal'): ?>
                                                            <span class="badge badge-<?php 
                                                                echo $result['abnormal_flag'] == 'critical' ? 'danger' : 'warning'; 
                                                            ?>">
                                                                <?php echo ucfirst($result['abnormal_flag']); ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($result['entered_by']); ?></td>
                                                    <td><?php echo date('H:i', strtotime($result['updated_at'])); ?></td>
                                                </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="text-center p-4">
                                    <p class="text-muted">No results entered in the last 24 hours.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>

    <?php require_once "modals/lab/enter_result_modal.php"; ?>

    <script>
    function enterResult(orderTestId) {
        $('#enterResultModal').modal('show');
        
        // Load test details via AJAX
        $.get('ajax/get_order_test.php?id=' + orderTestId, function(data) {
            const test = JSON.parse(data);
            
            // Populate modal
            $('#enterResultModal').find('#patientInfo').text(
                test.patient_first_name + ' ' + test.patient_last_name
            );
            $('#enterResultModal').find('#testInfo').text(test.test_name);
            $('#enterResultModal').find('#referenceRange').text(test.reference_range || 'Not specified');
            $('#enterResultModal').find('#resultUnit').text(test.result_unit || '');
            $('#enterResultModal').find('#orderTestId').val(orderTestId);
            
            // Set up result validation based on reference range
            setupResultValidation(test.reference_range);
        });
    }

    function setupResultValidation(referenceRange) {
        // Parse reference range and set up validation
        // This is a simplified version - you'd want more sophisticated parsing
        if (referenceRange) {
            const range = referenceRange.match(/(\d+\.?\d*)\s*-\s*(\d+\.?\d*)/);
            if (range) {
                const min = parseFloat(range[1]);
                const max = parseFloat(range[2]);
                
                $('#resultValue').on('input', function() {
                    const value = parseFloat($(this).val());
                    if (!isNaN(value)) {
                        let flag = 'normal';
                        if (value < min) flag = 'low';
                        if (value > max) flag = 'high';
                        if (value < min * 0.5 || value > max * 2) flag = 'critical';
                        
                        $('#abnormalFlag').val(flag);
                        updateFlagDisplay(flag);
                    }
                });
            }
        }
    }

    function updateFlagDisplay(flag) {
        const flagDisplay = $('#flagDisplay');
        flagDisplay.removeClass('badge-success badge-warning badge-danger');
        
        switch(flag) {
            case 'normal':
                flagDisplay.addClass('badge-success').text('Normal');
                break;
            case 'low':
            case 'high':
                flagDisplay.addClass('badge-warning').text(flag.charAt(0).toUpperCase() + flag.slice(1));
                break;
            case 'critical':
                flagDisplay.addClass('badge-danger').text('Critical');
                break;
        }
    }

    // Auto-fill current time
    $(document).ready(function() {
        const now = new Date();
        $('#resultTime').val(now.toTimeString().substring(0, 5));
    });
    </script>

  <?php 
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
    ?>
    
</body>
</html>
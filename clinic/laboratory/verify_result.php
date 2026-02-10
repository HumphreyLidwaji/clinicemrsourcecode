<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
   require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
    

$order_test_id = intval($_GET['order_test_id']);

// Get order test details with result
$order_test = $mysqli->query("
    SELECT lot.*, lt.test_name, lt.test_code, lt.specimen_type, lt.reference_range, lt.method,
           lo.order_number, p.patient_name, p.patient_mrn, p.patient_gender, p.patient_dob,
           u.user_name as doctor_name
    FROM lab_order_tests lot
    JOIN lab_tests lt ON lot.test_id = lt.test_id
    JOIN lab_orders lo ON lot.lab_order_id = lo.lab_order_id
    LEFT JOIN patients p ON lo.lab_order_patient_id = p.patient_id
    LEFT JOIN users u ON lo.ordering_doctor_id = u.user_id
    WHERE lot.lab_order_test_id = $order_test_id
")->fetch_assoc();

if (!$order_test) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Order test not found.";
    header("Location: lab_orders.php");
    exit;
}

if (!$order_test['result_value']) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "No result found for this test. Please enter a result first.";
    header("Location: enter_result.php?order_test_id=" . $order_test_id);
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    $verification_status = sanitizeInput($_POST['verification_status']);
    $verification_date = sanitizeInput($_POST['verification_date']);
    $verification_notes = sanitizeInput($_POST['verification_notes']);
    $corrected_result_value = sanitizeInput($_POST['corrected_result_value']);
    $corrected_result_unit = sanitizeInput($_POST['corrected_result_unit']);
    $critical_notified_to = sanitizeInput($_POST['critical_notified_to']);
    $critical_notification_time = sanitizeInput($_POST['critical_notification_time']);
    $critical_response = sanitizeInput($_POST['critical_response']);
    $critical_acknowledged = isset($_POST['critical_acknowledged']) ? 1 : 0;
    $verification_confirmed = isset($_POST['verification_confirmed']) ? 1 : 0;

    // Validate CSRF token
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        header("Location: verify_result.php?order_test_id=" . $order_test_id);
        exit;
    }

    // Validate required fields
    if (empty($verification_status) || empty($verification_date) || empty($verification_notes) || !$verification_confirmed) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please fill in all required fields and confirm verification.";
        header("Location: verify_result.php?order_test_id=" . $order_test_id);
        exit;
    }

    // Validate verification date is not in the future
    $verification_datetime = new DateTime($verification_date);
    $now = new DateTime();
    if ($verification_datetime > $now) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Verification date cannot be in the future.";
        header("Location: verify_result.php?order_test_id=" . $order_test_id);
        exit;
    }

    // Validate modified results
    if ($verification_status === 'modified' && empty($corrected_result_value)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please enter the corrected result value.";
        header("Location: verify_result.php?order_test_id=" . $order_test_id);
        exit;
    }

    // Validate critical results
    if (($order_test['abnormal_flag'] === 'critical_low' || $order_test['abnormal_flag'] === 'critical_high') && 
        $verification_status === 'verified' && !$critical_acknowledged) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please confirm that critical result protocol was followed.";
        header("Location: verify_result.php?order_test_id=" . $order_test_id);
        exit;
    }

    // Start transaction
    $mysqli->begin_transaction();

    try {
        // Update order test with verification
        $update_sql = "UPDATE lab_order_tests SET 
                      verification_status = ?,
                      verification_date = ?,
                      verification_notes = ?,
                      verified_by = ?,
                      verified_at = NOW()";
        
        // Add corrected result if modified
        if ($verification_status === 'modified') {
            $update_sql .= ", result_value = ?, result_unit = ?, result_status = 'corrected'";
        }
        
        $update_sql .= " WHERE lab_order_test_id = ?";
        
        $update_stmt = $mysqli->prepare($update_sql);
        
        if ($verification_status === 'modified') {
            $update_stmt->bind_param(
                "sssissi",
                $verification_status,
                $verification_date,
                $verification_notes,
                $session_user_id,
                $corrected_result_value,
                $corrected_result_unit,
                $order_test_id
            );
        } else {
            $update_stmt->bind_param(
                "sssii",
                $verification_status,
                $verification_date,
                $verification_notes,
                $session_user_id,
                $order_test_id
            );
        }

        if (!$update_stmt->execute()) {
            throw new Exception("Error updating verification: " . $mysqli->error);
        }

        // Log critical result notification if applicable
        if (($order_test['abnormal_flag'] === 'critical_low' || $order_test['abnormal_flag'] === 'critical_high') && 
            $verification_status === 'verified') {
            
            $critical_sql = "INSERT INTO lab_critical_notifications SET 
                           test_id = ?,
                           notified_to = ?,
                           notification_time = ?,
                           response = ?,
                           acknowledged = ?,
                           created_by = ?,
                           created_at = NOW()";
            
            $critical_stmt = $mysqli->prepare($critical_sql);
            $critical_stmt->bind_param(
                "issiii",
                $order_test_id,
                $critical_notified_to,
                $critical_notification_time,
                $critical_response,
                $critical_acknowledged,
                $session_user_id
            );
            
            if (!$critical_stmt->execute()) {
                throw new Exception("Error logging critical notification: " . $mysqli->error);
            }
        }

        // Log the activity
        $activity_sql = "INSERT INTO lab_activities SET 
                        test_id = ?,
                        activity_type = 'result_verified',
                        activity_description = ?,
                        performed_by = ?,
                        activity_date = NOW()";
        
        $activity_desc = "Result verified for test: " . $order_test['test_name'] . " (" . $order_test['test_code'] . ") - Status: " . $verification_status;
        $activity_stmt = $mysqli->prepare($activity_sql);
        $activity_stmt->bind_param("isi", $order_test_id, $activity_desc, $session_user_id);
        $activity_stmt->execute();

        // Commit transaction
        $mysqli->commit();

        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Test result verified successfully!";
        header("Location: lab_order_details.php?id=" . $order_test['lab_order_id']);
        exit;

    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error verifying test result: " . $e->getMessage();
        header("Location: verify_result.php?order_test_id=" . $order_test_id);
        exit;
    }
}

// Set abnormal flag badge styling
function getAbnormalFlagBadge($flag) {
    switch($flag) {
        case 'normal':
            return 'badge-success';
        case 'low':
        case 'high':
            return 'badge-warning';
        case 'critical_low':
        case 'critical_high':
            return 'badge-danger';
        case 'abnormal':
            return 'badge-info';
        default:
            return 'badge-secondary';
    }
}

function getAbnormalFlagText($flag) {
    switch($flag) {
        case 'normal':
            return 'Normal';
        case 'low':
            return 'Low';
        case 'high':
            return 'High';
        case 'critical_low':
            return 'Critical Low';
        case 'critical_high':
            return 'Critical High';
        case 'abnormal':
            return 'Abnormal';
        default:
            return 'Unknown';
    }
}
?>

<div class="card">
    <div class="card-header bg-warning py-2">
        <h3 class="card-title mt-2 mb-0">
            <i class="fas fa-fw fa-check-double mr-2"></i>Verify Test Result
        </h3>
        <div class="card-tools">
            <a href="lab_order_details.php?id=<?php echo $order_test['lab_order_id']; ?>" class="btn btn-light">
                <i class="fas fa-arrow-left mr-2"></i>Back to Order
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

        <div class="row">
            <div class="col-md-8">
                <form method="POST" id="verifyResultForm" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    
                    <!-- Test Information -->
                    <div class="card card-primary mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-info-circle mr-2"></i>Test Information</h4>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="text-muted">Test Name</label>
                                        <p class="form-control-plaintext font-weight-bold"><?php echo htmlspecialchars($order_test['test_name']); ?></p>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="text-muted">Test Code</label>
                                        <p class="form-control-plaintext font-weight-bold"><?php echo htmlspecialchars($order_test['test_code']); ?></p>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="text-muted">Reference Range</label>
                                        <p class="form-control-plaintext"><?php echo htmlspecialchars($order_test['reference_range'] ?: 'Not specified'); ?></p>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="text-muted">Method</label>
                                        <p class="form-control-plaintext"><?php echo htmlspecialchars($order_test['method'] ?: 'Standard method'); ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Current Result -->
                    <div class="card card-info mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-flask mr-2"></i>Current Result</h4>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label class="text-muted">Result Value</label>
                                        <p class="form-control-plaintext font-weight-bold"><?php echo htmlspecialchars($order_test['result_value']); ?></p>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label class="text-muted">Unit</label>
                                        <p class="form-control-plaintext"><?php echo htmlspecialchars($order_test['result_unit'] ?: '-'); ?></p>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label class="text-muted">Abnormal Flag</label>
                                        <p class="form-control-plaintext">
                                            <span class="badge <?php echo getAbnormalFlagBadge($order_test['abnormal_flag']); ?>">
                                                <?php echo getAbnormalFlagText($order_test['abnormal_flag']); ?>
                                            </span>
                                        </p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="text-muted">Performed By</label>
                                        <p class="form-control-plaintext"><?php echo htmlspecialchars($order_test['performed_by'] ?: 'Not specified'); ?></p>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="text-muted">Result Date</label>
                                        <p class="form-control-plaintext"><?php echo $order_test['result_date'] ? date('M j, Y H:i', strtotime($order_test['result_date'])) : '-'; ?></p>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-12">
                                    <div class="form-group">
                                        <label class="text-muted">Result Notes</label>
                                        <p class="form-control-plaintext"><?php echo htmlspecialchars($order_test['result_notes'] ?: 'No notes'); ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Verification Details -->
                    <div class="card card-warning mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-check-double mr-2"></i>Verification Details</h4>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="verificationStatus">Verification Status <span class="text-danger">*</span></label>
                                        <select class="form-control" id="verificationStatus" name="verification_status" required>
                                            <option value="">- Select Status -</option>
                                            <option value="verified">Verified - Result Accepted</option>
                                            <option value="rejected">Rejected - Repeat Test</option>
                                            <option value="modified">Modified - Corrected Result</option>
                                            <option value="referred">Referred - Send for Second Opinion</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="verificationDate">Verification Date & Time <span class="text-danger">*</span></label>
                                        <input type="datetime-local" class="form-control" id="verificationDate" 
                                               name="verification_date" value="<?php echo date('Y-m-d\TH:i'); ?>" required>
                                    </div>
                                </div>
                            </div>

                            <!-- Modified Result Section -->
                            <div class="row" id="modifiedResultSection" style="display: none;">
                                <div class="col-md-12">
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle mr-2"></i>
                                        Please enter the corrected result below.
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="correctedResultValue">Corrected Result Value</label>
                                        <input type="text" class="form-control" id="correctedResultValue" 
                                               name="corrected_result_value" placeholder="Enter corrected result">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="correctedResultUnit">Corrected Unit</label>
                                        <input type="text" class="form-control" id="correctedResultUnit" 
                                               name="corrected_result_unit" placeholder="Unit for corrected result">
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-12">
                                    <div class="form-group">
                                        <label for="verificationNotes">Verification Notes <span class="text-danger">*</span></label>
                                        <textarea class="form-control" id="verificationNotes" name="verification_notes" 
                                                  rows="3" placeholder="Comments about verification decision..." required></textarea>
                                        <small class="form-text text-muted">
                                            Explain the reason for verification status. For rejected results, specify the issue.
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Critical Result Handling -->
                    <?php if ($order_test['abnormal_flag'] === 'critical_low' || $order_test['abnormal_flag'] === 'critical_high'): ?>
                    <div class="card card-danger mb-4">
                        <div class="card-header bg-danger text-white py-2">
                            <h4 class="card-title mb-0">
                                <i class="fas fa-exclamation-triangle mr-2"></i>Critical Result Protocol
                            </h4>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-warning">
                                <strong>Critical Value Detected!</strong> This result requires immediate attention and communication.
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="criticalNotifiedTo">Notified To</label>
                                        <input type="text" class="form-control" id="criticalNotifiedTo" 
                                               name="critical_notified_to" placeholder="Doctor/Nurse name">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="criticalNotificationTime">Notification Time</label>
                                        <input type="datetime-local" class="form-control" id="criticalNotificationTime" 
                                               name="critical_notification_time" value="<?php echo date('Y-m-d\TH:i'); ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="form-group">
                                        <label for="criticalResponse">Response/Action Taken</label>
                                        <textarea class="form-control" id="criticalResponse" name="critical_response" 
                                                  rows="2" placeholder="Action taken after critical result notification..."></textarea>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="criticalAcknowledged" name="critical_acknowledged" value="1">
                                <label class="form-check-label" for="criticalAcknowledged">
                                    Critical result communication protocol followed
                                </label>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Verification Confirmation -->
                    <div class="card card-success">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-user-check mr-2"></i>Verification Confirmation</h4>
                        </div>
                        <div class="card-body">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="verificationConfirmed" name="verification_confirmed" value="1" required>
                                <label class="form-check-label" for="verificationConfirmed">
                                    I confirm that I have reviewed this result and the associated quality control data, 
                                    and I take responsibility for this verification decision.
                                </label>
                            </div>
                            
                            <div class="mt-2">
                                <small class="text-muted">
                                    <strong>Verified by:</strong> <?php echo $session_user_name; ?> (<?php echo $session_user_type; ?>)
                                    <br><strong>Date:</strong> <?php echo date('F j, Y H:i'); ?>
                                </small>
                            </div>
                        </div>
                    </div>

                    <!-- Form Actions -->
                    <div class="row mt-4">
                        <div class="col-md-12">
                            <div class="btn-toolbar justify-content-between">
                                <a href="lab_order_details.php?id=<?php echo $order_test['lab_order_id']; ?>" class="btn btn-secondary">
                                    <i class="fas fa-times mr-2"></i>Cancel
                                </a>
                                <button type="submit" class="btn btn-warning" id="verifySubmitBtn">
                                    <i class="fas fa-check-double mr-2"></i>Submit Verification
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <div class="col-md-4">
                <!-- Quick Actions -->
                <div class="card card-success mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-bolt mr-2"></i>Quick Actions</h4>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <button type="button" class="btn btn-outline-primary" onclick="setCurrentDateTime()">
                                <i class="fas fa-clock mr-2"></i>Set Current Date/Time
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Verification Guidelines -->
                <div class="card card-info">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-book mr-2"></i>Verification Guidelines</h4>
                    </div>
                    <div class="card-body">
                        <ul class="list-unstyled small">
                            <li class="mb-2">
                                <i class="fas fa-check text-success mr-1"></i>
                                Review all quality control data
                            </li>
                            <li class="mb-2">
                                <i class="fas fa-check text-success mr-1"></i>
                                Verify result against reference ranges
                            </li>
                            <li class="mb-2">
                                <i class="fas fa-check text-success mr-1"></i>
                                Check for technical errors
                            </li>
                            <li class="mb-2">
                                <i class="fas fa-check text-success mr-1"></i>
                                Document verification decision
                            </li>
                            <li class="mb-2">
                                <i class="fas fa-check text-success mr-1"></i>
                                Follow critical value protocols
                            </li>
                            <li>
                                <i class="fas fa-check text-success mr-1"></i>
                                Ensure complete documentation
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Handle verification status changes
    $('#verificationStatus').change(function() {
        const status = $(this).val();
        
        // Show/hide modified result section
        if (status === 'modified') {
            $('#modifiedResultSection').show();
            $('#correctedResultValue').prop('required', true);
        } else {
            $('#modifiedResultSection').hide();
            $('#correctedResultValue').prop('required', false);
        }
        
        // Update button color based on status
        updateVerifyButtonColor(status);
    });

    // Update verify button color based on status
    function updateVerifyButtonColor(status) {
        const button = $('#verifySubmitBtn');
        button.removeClass('btn-warning btn-success btn-danger btn-info');
        
        switch(status) {
            case 'verified':
                button.addClass('btn-success');
                break;
            case 'rejected':
                button.addClass('btn-danger');
                break;
            case 'modified':
                button.addClass('btn-info');
                break;
            case 'referred':
                button.addClass('btn-primary');
                break;
            default:
                button.addClass('btn-warning');
        }
    }

    // Form validation
    $('#verifyResultForm').submit(function(e) {
        const verificationStatus = $('#verificationStatus').val();
        const verificationDate = $('#verificationDate').val();
        const verificationNotes = $('#verificationNotes').val();
        const verificationConfirmed = $('#verificationConfirmed').is(':checked');
        
        if (!verificationStatus || !verificationDate || !verificationNotes || !verificationConfirmed) {
            e.preventDefault();
            alert('Please fill in all required fields and confirm verification');
            return false;
        }
        
        // Validate verification date is not in the future
        const verificationDateTime = new Date(verificationDate);
        const now = new Date();
        if (verificationDateTime > now) {
            e.preventDefault();
            alert('Verification date cannot be in the future');
            return false;
        }
        
        // Validate modified results
        if (verificationStatus === 'modified') {
            const correctedValue = $('#correctedResultValue').val();
            if (!correctedValue) {
                e.preventDefault();
                alert('Please enter the corrected result value');
                return false;
            }
        }
        
        // Validate critical results
        const abnormalFlag = '<?php echo $order_test['abnormal_flag']; ?>';
        if ((abnormalFlag === 'critical_low' || abnormalFlag === 'critical_high') && 
            verificationStatus === 'verified') {
            const criticalAcknowledged = $('#criticalAcknowledged').is(':checked');
            if (!criticalAcknowledged) {
                e.preventDefault();
                alert('Please confirm that critical result protocol was followed');
                return false;
            }
        }
        
        // Show loading state
        $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Verifying...').prop('disabled', true);
    });
});

function setCurrentDateTime() {
    const now = new Date();
    const localDateTime = now.toISOString().slice(0, 16);
    $('#verificationDate').val(localDateTime);
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + S to save
    if (e.ctrlKey && e.keyCode === 83) {
        e.preventDefault();
        $('#verifyResultForm').submit();
    }
    // Escape to cancel
    if (e.keyCode === 27) {
        window.location.href = 'lab_order_details.php?id=<?php echo $order_test['lab_order_id']; ?>';
    }
    // Ctrl + T to set current time
    if (e.ctrlKey && e.keyCode === 84) {
        e.preventDefault();
        setCurrentDateTime();
    }
});
</script>

<?php 
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
    ?>
    
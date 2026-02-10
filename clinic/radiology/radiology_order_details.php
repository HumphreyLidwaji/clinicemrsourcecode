<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Get order ID from URL
$order_id = intval($_GET['id']);

// Fetch radiology order details
$order_sql = "SELECT ro.*, 
                     p.first_name, p.last_name, p.patient_mrn, p.sex, p.date_of_birth, p.phone_primary, p.email,
                     u.user_name as referring_doctor_name, u.user_email as referring_doctor_email,
                     ru.user_name as radiologist_name, ru.user_email as radiologist_email,
                     au.user_name as created_by_name,
                     d.department_name
              FROM radiology_orders ro
              LEFT JOIN patients p ON ro.patient_id = p.patient_id
              LEFT JOIN users u ON ro.referring_doctor_id = u.user_id
              LEFT JOIN users ru ON ro.radiologist_id = ru.user_id
              LEFT JOIN users au ON ro.created_by = au.user_id
              LEFT JOIN departments d ON ro.department_id = d.department_id
              WHERE ro.radiology_order_id = ?";
$order_stmt = $mysqli->prepare($order_sql);
$order_stmt->bind_param("i", $order_id);
$order_stmt->execute();
$order_result = $order_stmt->get_result();

if ($order_result->num_rows == 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Radiology order not found.";
    header("Location: radiology_orders.php");
    exit;
}

$order = $order_result->fetch_assoc();

// Fetch order studies
$studies_sql = "SELECT ros.*, ri.imaging_name, ri.imaging_code, ri.fee_amount,
                       u.user_name as performed_by_name
                FROM radiology_order_studies ros
                LEFT JOIN radiology_imagings ri ON ros.imaging_id = ri.imaging_id
                LEFT JOIN users u ON ros.performed_by = u.user_id
                WHERE ros.radiology_order_id = ?
                ORDER BY ros.created_at ASC";
$studies_stmt = $mysqli->prepare($studies_sql);
$studies_stmt->bind_param("i", $order_id);
$studies_stmt->execute();
$studies_result = $studies_stmt->get_result();

// Calculate patient age
$patient_age = "";
if (!empty($order['patient_dob'])) {
    $birthDate = new DateTime($order['patient_dob']);
    $today = new DateTime();
    $age = $today->diff($birthDate)->y;
    $patient_age = " ($age yrs)";
}

// Status badge styling
$status_badge = "";
switch($order['order_status']) {
    case 'Pending':
        $status_badge = "badge-warning";
        break;
    case 'Scheduled':
        $status_badge = "badge-info";
        break;
    case 'In Progress':
        $status_badge = "badge-primary";
        break;
    case 'Completed':
        $status_badge = "badge-success";
        break;
    case 'Cancelled':
        $status_badge = "badge-danger";
        break;
    default:
        $status_badge = "badge-light";
}

// Priority badge styling
$priority_badge = "";
switch($order['order_priority']) {
    case 'stat':
        $priority_badge = "badge-danger";
        break;
    case 'urgent':
        $priority_badge = "badge-warning";
        break;
    case 'routine':
        $priority_badge = "badge-success";
        break;
    default:
        $priority_badge = "badge-light";
}
?>

<div class="card">
<div class="card-header bg-info py-2">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-x-ray mr-2"></i>Radiology Order Details
            </h3>
            <small class="text-white-50">Order #: <?php echo htmlspecialchars($order['order_number']); ?></small>
        </div>
        <div class="btn-group">
            <a href="radiology_imaging_orders.php" class="btn btn-light">
                <i class="fas fa-arrow-left mr-2"></i>Back to Orders
            </a>
            
           <!-- EDIT BUTTON - Updated with correct parameter name -->
<a href="radiology_edit_order.php?order_id=<?php echo $order_id; ?>" class="btn btn-warning">
    <i class="fas fa-edit mr-2"></i>Edit Order & Studies
</a>
            
            <?php 
            // Check if all studies are completed
            $all_studies_completed = true;
            $has_studies = false;
            mysqli_data_seek($studies_result, 0);
            while ($study_check = $studies_result->fetch_assoc()) {
                $has_studies = true;
                if ($study_check['status'] != 'completed') {
                    $all_studies_completed = false;
                    break;
                }
            }
            mysqli_data_seek($studies_result, 0);
            
            // Check if report already exists for this order
            $existing_report_sql = "SELECT report_id FROM radiology_reports WHERE radiology_order_id = ? AND report_status != 'cancelled'";
            $existing_report_stmt = $mysqli->prepare($existing_report_sql);
            $existing_report_stmt->bind_param("i", $order_id);
            $existing_report_stmt->execute();
            $existing_report_result = $existing_report_stmt->get_result();
            $report_exists = $existing_report_result->num_rows > 0;
            $existing_report_stmt->close();
            
            if ($has_studies && $all_studies_completed && $order['order_status'] != 'Completed'): ?>
                <a href="post/radiology.php?complete_order=<?php echo $order_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>" 
                   class="btn btn-success confirm-link">
                    <i class="fas fa-check-circle mr-2"></i>Complete Order
                </a>
            <?php endif; ?>
            
            <?php if ($order['order_status'] == 'Completed'): ?>
                <?php if ($report_exists): 
                    $existing_report = $existing_report_result->fetch_assoc(); ?>
                      <?php if (SimplePermission::any([ 'radiology_view_report'])): ?>
                    <a href="radiology_view_report.php?report_id=<?php echo $existing_report['report_id']; ?>" class="btn btn-primary">
                        <i class="fas fa-file-medical mr-2"></i>View Report
                    </a>
                     <?php endif; ?>
                <?php else: ?>
                         <?php if (SimplePermission::any([ 'radiology_create_report'])): ?>
             <a href="radiology_create_report.php?order_id=<?php echo $order_id; ?>" class="btn btn-primary">
                        <i class="fas fa-file-medical-alt mr-2"></i>Create Report
                    </a>
            <?php endif; ?>
                  
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

    <div class="card-body">
        <!-- Alert Messages -->
        <?php if (isset($_SESSION['alert_message'])): ?>
            <div class="alert alert-<?php echo $_SESSION['alert_type']; ?> alert-dismissible">
                <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
                <i class="icon fas fa-<?php echo $_SESSION['alert_type'] == 'success' ? 'check' : 'exclamation-triangle'; ?>"></i>
                <?php echo $_SESSION['alert_message']; ?>
            </div>
            <?php unset($_SESSION['alert_message'], $_SESSION['alert_type']); ?>
        <?php endif; ?>

        <div class="row">
            <!-- Patient Information -->
            <div class="col-md-6">
                <div class="card card-primary">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-user-injured mr-2"></i>Patient Information</h3>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm">
                            <tr>
                                <th width="40%">Patient Name:</th>
                                <td>
                                    <strong><?php echo ($order['first_name'] . ' ' . $order['last_name']); ?></strong>
                                    <?php echo $patient_age; ?>
                                </td>
                            </tr>
                            <tr>
                                <th>MRN:</th>
                                <td><?php echo ($order['patient_mrn']); ?></td>
                            </tr>
                            <tr>
                                <th>Gender:</th>
                                <td><?php echo ($order['sex']); ?></td>
                            </tr>
                            <tr>
                                <th>Date of Birth:</th>
                                <td><?php echo !empty($order['date_of_birth']) ? date('M j, Y', strtotime($order['date_of_birth'])) : 'N/A'; ?></td>
                            </tr>
                            <tr>
                                <th>Phone:</th>
                                <td><?php echo !empty($order['phone_primary']) ? ($order['phone_primary']) : 'N/A'; ?></td>
                            </tr>
                            <tr>
                                <th>Email:</th>
                                <td><?php echo !empty($order['email']) ?($order['email']) : 'N/A'; ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Order Information -->
            <div class="col-md-6">
                <div class="card card-info">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-info-circle mr-2"></i>Order Information</h3>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm">
                            <tr>
                                <th width="40%">Status:</th>
                                <td><span class="badge <?php echo $status_badge; ?>"><?php echo $order['order_status']; ?></span></td>
                            </tr>
                            <tr>
                                <th>Priority:</th>
                                <td><span class="badge <?php echo $priority_badge; ?>"><?php echo ucfirst($order['order_priority']); ?></span></td>
                            </tr>
                            <tr>
                                <th>Order Type:</th>
                                <td><?php echo htmlspecialchars($order['order_type']); ?></td>
                            </tr>
                            <tr>
                                <th>Body Part:</th>
                                <td><?php echo !empty($order['body_part']) ? htmlspecialchars($order['body_part']) : 'N/A'; ?></td>
                            </tr>
                            <tr>
                                <th>Referring Doctor:</th>
                                <td><?php echo !empty($order['referring_doctor_name']) ? htmlspecialchars($order['referring_doctor_name']) : 'N/A'; ?></td>
                            </tr>
                            <tr>
                                <th>Radiologist:</th>
                                <td><?php echo !empty($order['radiologist_name']) ? htmlspecialchars($order['radiologist_name']) : 'Not assigned'; ?></td>
                            </tr>
                            <tr>
                                <th>Department:</th>
                                <td><?php echo !empty($order['department_name']) ? htmlspecialchars($order['department_name']) : 'N/A'; ?></td>
                            </tr>
                            <tr>
                                <th>Order Date:</th>
                                <td><?php echo date('M j, Y g:i A', strtotime($order['created_at'])); ?></td>
                            </tr>
                            <tr>
                                <th>Created By:</th>
                                <td><?php echo htmlspecialchars($order['created_by_name']); ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Clinical Information -->
        <div class="row mt-3">
            <div class="col-md-12">
                <div class="card card-warning">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-stethoscope mr-2"></i>Clinical Information</h3>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6>Clinical Notes:</h6>
                                <p><?php echo !empty($order['clinical_notes']) ? nl2br(htmlspecialchars($order['clinical_notes'])) : 'No clinical notes provided.'; ?></p>
                            </div>
                            <div class="col-md-6">
                                <h6>Instructions:</h6>
                                <p><?php echo !empty($order['instructions']) ? nl2br(htmlspecialchars($order['instructions'])) : 'No special instructions.'; ?></p>
                                <?php if ($order['contrast_required']): ?>
                                    <div class="alert alert-info mt-2">
                                        <i class="fas fa-info-circle mr-2"></i>
                                        <strong>Contrast Required:</strong> <?php echo htmlspecialchars($order['contrast_type'] ?? 'NA'); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($order['pre_procedure_instructions'] ?? 'NA')): ?>
                                    <h6>Pre-procedure Instructions:</h6>
                                    <p><?php echo nl2br(htmlspecialchars($order['pre_procedure_instructions'] )); ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Order Studies -->
        <div class="row mt-3">
            <div class="col-md-12">
                <div class="card card-success">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h3 class="card-title"><i class="fas fa-procedures mr-2"></i>Order Studies</h3>
                        <span class="badge badge-light"><?php echo $studies_result->num_rows; ?> studies</span>
                    </div>
                    <div class="card-body">
                        <?php if ($studies_result->num_rows > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>Study</th>
                                            <th>Code</th>
                                            <th>Status</th>
                                            <th>Scheduled Date</th>
                                            <th>Performed Date</th>
                                            <th>Performed By</th>
                                            <th>Fee</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($study = $studies_result->fetch_assoc()): 
                                            $study_status_badge = "";
                                            switch($study['status']) {
                                                case 'pending':
                                                    $study_status_badge = "badge-warning";
                                                    break;
                                                case 'scheduled':
                                                    $study_status_badge = "badge-info";
                                                    break;
                                                case 'in_progress':
                                                    $study_status_badge = "badge-primary";
                                                    break;
                                                case 'completed':
                                                    $study_status_badge = "badge-success";
                                                    break;
                                                case 'cancelled':
                                                    $study_status_badge = "badge-danger";
                                                    break;
                                                default:
                                                    $study_status_badge = "badge-light";
                                            }
                                        ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($study['imaging_name']); ?></strong>
                                                </td>
                                                <td><?php echo htmlspecialchars($study['imaging_code'] ?? ''); ?></td>
                                                <td>
                                                    <span class="badge <?php echo $study_status_badge; ?>">
                                                        <?php echo ucfirst(str_replace('_', ' ', $study['status'])); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($study['scheduled_date']): ?>
                                                        <?php echo date('M j, Y g:i A', strtotime($study['scheduled_date'])); ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">Not scheduled</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($study['performed_date']): ?>
                                                        <?php echo date('M j, Y g:i A', strtotime($study['performed_date'])); ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">Not performed</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($study['performed_by_name']): ?>
                                                        <?php echo htmlspecialchars($study['performed_by_name']); ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">Not assigned</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>Kshs<?php echo number_format($study['fee_amount'], 2); ?></td>
                                              <td>
    <div class="btn-group">
        <button type="button" class="btn btn-sm btn-info view-study-btn" 
                data-study-id="<?php echo $study['radiology_order_study_id']; ?>"
                data-study-name="<?php echo htmlspecialchars($study['imaging_name']); ?>"
                data-status="<?php echo $study['status']; ?>"
                data-scheduled-date="<?php echo $study['scheduled_date']; ?>"
                data-performed-date="<?php echo $study['performed_date']; ?>"
                data-performed-by="<?php echo htmlspecialchars($study['performed_by_name'] ?? ''); ?>"
                data-findings="<?php echo htmlspecialchars($study['findings'] ?? ''); ?>"
                data-impression="<?php echo htmlspecialchars($study['impression'] ?? ''); ?>"
                data-recommendations="<?php echo htmlspecialchars($study['recommendations'] ?? ''); ?>">
            <i class="fas fa-eye"></i>
        </button>
        
        <?php if ($study['status'] == 'pending' || $study['status'] == 'scheduled'): ?>
            <button type="button" class="btn btn-sm btn-warning update-study-btn" 
                    data-study-id="<?php echo $study['radiology_order_study_id']; ?>"
                    data-study-name="<?php echo htmlspecialchars($study['imaging_name']); ?>"
                    data-status="<?php echo $study['status']; ?>">
                <i class="fas fa-edit"></i>
            </button>
        <?php endif; ?>
        
        <?php if ($study['status'] == 'scheduled' || $study['status'] == 'in_progress'): ?>
            <?php if (SimplePermission::any("radiology_complete_study")) { ?>
            <a href="post/radiology.php?update_study_status=<?php echo $study['radiology_order_study_id']; ?>&status=completed&csrf_token=<?php echo $_SESSION['csrf_token']; ?>" 
               class="btn btn-sm btn-success confirm-link" 
               title="Mark as Completed">
                <i class="fas fa-check"></i>
            </a>
        <?php } ?>
        <?php endif; ?>
        
        <?php if ($study['status'] == 'completed'): ?>
            <span class="badge badge-success">Completed</span>
        <?php endif; ?>
    </div>
</td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info text-center">
                                <i class="fas fa-procedures fa-2x mb-3"></i>
                                <h5>No studies found for this order</h5>
                                <p class="mb-0">Use the "Edit Order & Studies" button to add studies to this order.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<!-- Complete Study Modal -->
<div class="modal fade" id="completeStudyModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Complete Study</h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <form method="post" action="post/radiology.php" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="complete_study" value="1">
                <input type="hidden" name="study_id" id="complete_study_id">
                
                <div class="modal-body">
                    <div class="form-group">
                        <label for="findings">Findings</label>
                        <textarea class="form-control" id="findings" name="findings" rows="4" 
                                  placeholder="Describe the radiological findings..."></textarea>
                    </div>

                    <div class="form-group">
                        <label for="impression">Impression</label>
                        <textarea class="form-control" id="impression" name="impression" rows="3" 
                                  placeholder="Provide clinical impression..."></textarea>
                    </div>

                    <div class="form-group">
                        <label for="recommendations">Recommendations</label>
                        <textarea class="form-control" id="recommendations" name="recommendations" rows="2" 
                                  placeholder="Any recommendations for follow-up..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check mr-2"></i>Complete Study
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<!-- View Study Details Modal -->
<div class="modal fade" id="viewStudyModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Study Details</h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div id="studyDetailsContent"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    console.log('Radiology order details page loaded');
    
    // View study details
    $('.view-study-btn').click(function() {
        console.log('View study button clicked');
        const studyName = $(this).data('study-name');
        const status = $(this).data('status');
        const scheduledDate = $(this).data('scheduled-date');
        const performedDate = $(this).data('performed-date');
        const performedBy = $(this).data('performed-by');
        const findings = $(this).data('findings');
        const impression = $(this).data('impression');
        const recommendations = $(this).data('recommendations');

        let content = `
            <h6>Study Information</h6>
            <p><strong>Study:</strong> ${studyName}</p>
            <p><strong>Status:</strong> <span class="badge badge-info">${status}</span></p>
            <p><strong>Scheduled Date:</strong> ${scheduledDate ? new Date(scheduledDate).toLocaleString() : 'Not scheduled'}</p>
            <p><strong>Performed Date:</strong> ${performedDate ? new Date(performedDate).toLocaleString() : 'Not performed'}</p>
            <p><strong>Performed By:</strong> ${performedBy || 'Not assigned'}</p>
        `;

        if (findings) {
            content += `<hr><h6>Findings</h6><p>${findings}</p>`;
        }
        if (impression) {
            content += `<hr><h6>Impression</h6><p>${impression}</p>`;
        }
        if (recommendations) {
            content += `<hr><h6>Recommendations</h6><p>${recommendations}</p>`;
        }

        $('#studyDetailsContent').html(content);
        $('#viewStudyModal').modal('show');
    });

    // Show imaging study details when selected
    $('#imaging_id').change(function() {
        console.log('Imaging ID changed');
        const selectedOption = $(this).find('option:selected');
        const description = selectedOption.data('description');
        const fee = selectedOption.data('fee');
        const turnaround = selectedOption.data('turnaround');
        
        console.log('Selected option:', selectedOption.val());
        console.log('Description:', description);
        console.log('Fee:', fee);
        console.log('Turnaround:', turnaround);
        
        if (selectedOption.val()) {
            $('#imagingDescription').text(description || 'No description available');
            $('#imagingFee').text(parseFloat(fee).toFixed(2));
            $('#imagingTurnaround').text(turnaround || 'Not specified');
            $('#imagingDetails').show();
        } else {
            $('#imagingDetails').hide();
        }
    });

    // Set minimum datetime for scheduling
    const now = new Date();
    now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
    $('#scheduled_date').attr('min', now.toISOString().slice(0, 16));

    // Auto-populate scheduled date with a reasonable default (next business hour)
    const nextHour = new Date(now);
    nextHour.setHours(nextHour.getHours() + 1);
    nextHour.setMinutes(0, 0, 0);
    $('#scheduled_date').val(nextHour.toISOString().slice(0, 16));

    // Debug: Check if imaging studies are loaded
    console.log('Imaging studies count:', $('#imaging_id option').length - 1);
    
    // Test the change event manually
    $('#imaging_id').trigger('change');
});

// Additional event listener for modal show to ensure JavaScript works
$(document).on('show.bs.modal', '#scheduleStudyModal', function () {
    console.log('Schedule study modal shown');
    // Re-trigger the change event when modal opens
    setTimeout(function() {
        $('#imaging_id').trigger('change');
    }, 500);
});
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>